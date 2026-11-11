package esi

import (
	"context"
	"net/http"
	"net/http/httptest"
	"testing"
)

func testStack() (*MemStore, *Limiter, *Circuit, *Client) {
	st := NewMemStore()
	lim := NewLimiter(st, "test")
	circ := NewCircuit(st)
	cl := NewClient(st, lim, circ, "Test/1.0")
	return st, lim, circ, cl
}

func TestLimiterReserveReconcile(t *testing.T) {
	st, lim, _, _ := testStack()
	id, err := lim.Reserve("g", "u1", 5)
	if err != nil || id == "" {
		t.Fatalf("reserve: %v %q", err, id)
	}
	if lim.Remaining("g", "u1") != DefaultLimit-5 {
		t.Fatalf("remaining: %d", lim.Remaining("g", "u1"))
	}
	if err := lim.Reconcile("g", "u1", id, ResponseHeaders{Status: 200, Used: intPtr(2)}); err != nil {
		t.Fatal(err)
	}
	if lim.Remaining("g", "u1") != DefaultLimit-2 {
		t.Fatalf("after reconcile: %d", lim.Remaining("g", "u1"))
	}
	if err := lim.Reconcile("g", "u1", "", ResponseHeaders{Status: 429, RetryAfterSecs: intPtr(30)}); err != nil {
		t.Fatal(err)
	}
	if !lim.IsLimited("g", "u1") {
		t.Fatal("429 must limit")
	}
	_ = st
}

func TestLimiterAdvertisedConfig(t *testing.T) {
	_, lim, _, _ := testStack()
	if err := lim.Reconcile("g", "u", "", ResponseHeaders{Status: 200, Advertised: "100/15m"}); err != nil {
		t.Fatal(err)
	}
	for i := 0; i < 20; i++ {
		if _, err := lim.Reserve("g", "u", 5); err != nil {
			t.Fatal(err)
		}
	}
	if lim.Remaining("g", "u") != 0 {
		t.Fatalf("remaining: %d", lim.Remaining("g", "u"))
	}
	if lim.ResetsIn("g", "u", 5) <= 0 {
		t.Fatal("exhausted budget must report a wait")
	}
}

func TestCircuit(t *testing.T) {
	_, _, circ, _ := testStack()
	if circ.IsLimited() {
		t.Fatal("fresh circuit open")
	}
	remain := 5
	circ.TrackResponse(&remain, 60, false, 0)
	if !circ.IsLimited() {
		t.Fatal("low remaining must open circuit")
	}
	if circ.ResetsIn() <= 0 || circ.ResetsIn() > 60 {
		t.Fatalf("reset: %d", circ.ResetsIn())
	}
}

func TestClientClassification(t *testing.T) {
	_, _, _, cl := testStack()
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch r.URL.Path {
		case "/ok":
			w.Write([]byte(`{"a":1}`))
		case "/limited":
			w.Header().Set("X-Esi-Error-Limit-Remain", "0")
			w.Header().Set("X-Esi-Error-Limit-Reset", "45")
			w.WriteHeader(420)
		case "/throttled":
			w.Header().Set("Retry-After", "12")
			w.WriteHeader(429)
		case "/nope":
			w.WriteHeader(404)
		case "/boom":
			w.WriteHeader(500)
		case "/auth":
			w.Header().Set("Authorization", r.Header.Get("Authorization"))
			w.WriteHeader(200)
		}
	}))
	defer srv.Close()
	cl.BaseURL = srv.URL
	ctx := context.Background()

	r, err := cl.Do(ctx, "GET", "/ok", nil, nil, nil)
	if err != nil || r.Status != 200 {
		t.Fatalf("ok: %v %+v", err, r)
	}
	if _, err := cl.Do(ctx, "GET", "/nope", nil, nil, nil); reqErr(err) != OutcomeNotFound {
		t.Fatalf("404: %v", err)
	}
	if _, err := cl.Do(ctx, "GET", "/boom", nil, nil, nil); reqErr(err) != OutcomeTransient {
		t.Fatalf("500: %v", err)
	}
	if _, err := cl.Do(ctx, "GET", "/throttled", nil, nil, nil); reqErr(err) != OutcomeRateLimited {
		t.Fatalf("429: %v", err)
	}
	if _, err := cl.Do(ctx, "GET", "/limited", nil, nil, nil); reqErr(err) != OutcomeErrorLimited {
		t.Fatalf("420: %v", err)
	}
	if _, err := cl.Do(ctx, "GET", "/ok", nil, nil, nil); reqErr(err) != OutcomeErrorLimited {
		t.Fatalf("circuit must hold: %v", err)
	}
	r, err = cl.Do(ctx, "GET", "/auth", nil, nil, &Auth{CharacterID: 1, AccessToken: "tok"})
	if err != nil || r == nil {
		_, _, _, cl2 := testStack()
		cl2.BaseURL = srv.URL
		r, err = cl2.Do(ctx, "GET", "/auth", nil, nil, &Auth{CharacterID: 1, AccessToken: "tok"})
		if err != nil {
			t.Fatalf("auth: %v", err)
		}
		_ = r
	}
}

func TestTokenRefresh(t *testing.T) {
	var gotForm string
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		body := make([]byte, r.ContentLength)
		_, _ = r.Body.Read(body)
		gotForm = string(body)
		w.Write([]byte(`{"access_token":"new-access","refresh_token":"new-refresh","expires_in":1200}`))
	}))
	defer srv.Close()
	st := NewMemStore()
	ref := NewRefresher(st, nil, srv.Client(), "cid", "secret")
	ref.base = srv.URL
	pair, err := ref.RefreshToken(context.Background(), "old-refresh")
	if err != nil || pair.AccessToken != "new-access" || pair.ExpiresIn != 1200 {
		t.Fatalf("got %+v %v (%s)", pair, err, gotForm)
	}

	srv2 := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(400)
		w.Write([]byte(`{"error":"invalid_grant"}`))
	}))
	defer srv2.Close()
	ref2 := NewRefresher(st, nil, srv2.Client(), "cid", "secret")
	ref2.base = srv2.URL
	if _, err := ref2.RefreshToken(context.Background(), "bad"); reqErr(err) != OutcomeCredential {
		t.Fatalf("grant: %v", err)
	}
}

func TestMissingScope(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("WWW-Authenticate", `Bearer error="insufficient_scope"`)
		w.WriteHeader(401)
		w.Write([]byte(`{"error":"insufficient scope"}`))
	}))
	defer srv.Close()
	_, _, _, cl := testStack()
	cl.BaseURL = srv.URL
	_, err := cl.Do(context.Background(), "GET", "/x", nil, nil, &Auth{CharacterID: 1, AccessToken: "t"})
	if reqErr(err) != OutcomeMissingScope {
		t.Fatalf("scope: %v", err)
	}
}

func reqErr(err error) Outcome {
	if re, ok := err.(*RequestError); ok {
		return re.Outcome
	}
	return "no-error"
}

func intPtr(i int) *int { return &i }
