package http

import (
	"context"
	"net/http"
	"net/http/httptest"
	"testing"
	"time"

	"github.com/sudo-adduser-jordan/eveindustry/internal/auth"
)

// stubAuth implements auth.Sessions + store.AuthStore for handler tests: a
// fixed logged-in user (uid 0 = anonymous).
type stubAuth struct {
	uid     int64
	user    *auth.User
	chars   []auth.Character
	deleted int64
}

func testAuth() *stubAuth {
	return &stubAuth{
		uid:  1,
		user: &auth.User{ID: 1, Name: "Test User"},
	}
}

func (s *stubAuth) UserID(r *http.Request) (int64, bool) { return s.uid, s.uid > 0 }
func (s *stubAuth) Issue(w http.ResponseWriter, userID int64)      {}
func (s *stubAuth) Destroy(w http.ResponseWriter, r *http.Request) {}
func (s *stubAuth) UserByID(ctx context.Context, userID int64) (*auth.User, error) {
	if s.user != nil && userID == s.uid {
		return s.user, nil
	}
	return nil, nil
}
func (s *stubAuth) CharactersForUser(ctx context.Context, userID int64) ([]auth.Character, error) {
	return s.chars, nil
}
func (s *stubAuth) DeleteUser(ctx context.Context, userID int64) error {
	s.deleted = userID
	return nil
}

func TestMeAuthenticated(t *testing.T) {
	stub := testAuth()
	stub.chars = []auth.Character{
		{CharacterID: 2, Name: "Alt", IsMain: false},
		{CharacterID: 1, Name: "Main", IsMain: true, Scopes: []string{"a"}},
	}
	d := Deps{Sessions: stub, Users: stub, App: AppConfig{SourceURL: "https://example.test"}}
	code, body := get(t, d, "/api/v1/me")
	if code != http.StatusOK {
		t.Fatalf("code = %d %v", code, body)
	}
	data := body["data"].(map[string]any)
	if data["display_name"] != "Main" {
		t.Fatalf("display = %v", data)
	}
	if len(data["characters"].([]any)) != 2 {
		t.Fatalf("chars = %v", data)
	}
}

func TestMeUnauthenticatedVariants(t *testing.T) {
	// no session store at all
	if code, _ := get(t, Deps{}, "/api/v1/me"); code != http.StatusUnauthorized {
		t.Fatalf("nil store = %d", code)
	}
	// anonymous cookie
	stub := &stubAuth{}
	if code, _ := get(t, Deps{Sessions: stub, Users: stub}, "/api/v1/me"); code != http.StatusUnauthorized {
		t.Fatalf("anon = %d", code)
	}
	// unknown user id
	stub = &stubAuth{uid: 99}
	if code, _ := get(t, Deps{Sessions: stub, Users: stub}, "/api/v1/me"); code != http.StatusUnauthorized {
		t.Fatalf("unknown user = %d", code)
	}
	// data route also guarded
	if code, _ := get(t, Deps{}, "/api/v1/types?q=abc"); code != http.StatusUnauthorized {
		t.Fatalf("data route = %d", code)
	}
}

func TestLogoutAndAccountDelete(t *testing.T) {
	stub := testAuth()
	d := Deps{Sessions: stub, Users: stub}
	e := New(d)
	req := httptest.NewRequest(http.MethodPost, "/api/v1/logout", nil)
	rec := httptest.NewRecorder()
	e.ServeHTTP(rec, req)
	if rec.Code != http.StatusNoContent {
		t.Fatalf("logout = %d", rec.Code)
	}
	// demo user blocked from account deletion
	stub.user.IsDemo = true
	req = httptest.NewRequest(http.MethodDelete, "/api/v1/account", nil)
	rec = httptest.NewRecorder()
	e.ServeHTTP(rec, req)
	if rec.Code != http.StatusForbidden {
		t.Fatalf("demo delete = %d %s", rec.Code, rec.Body.String())
	}
	stub.user.IsDemo = false
	req = httptest.NewRequest(http.MethodDelete, "/api/v1/account", nil)
	rec = httptest.NewRecorder()
	e.ServeHTTP(rec, req)
	if rec.Code != http.StatusNoContent || stub.deleted != 1 {
		t.Fatalf("delete = %d deleted=%d", rec.Code, stub.deleted)
	}
}

func TestCookieSessions(t *testing.T) {
	s := auth.NewCookieSessions([]byte("test-key-0123456789abcdef"), time.Minute, false, "")
	w := httptest.NewRecorder()
	s.Issue(w, 42)
	cookies := w.Result().Cookies()
	if len(cookies) != 1 || cookies[0].Name != auth.SessionCookie {
		t.Fatalf("cookies = %v", cookies)
	}
	r := httptest.NewRequest(http.MethodGet, "/", nil)
	r.AddCookie(cookies[0])
	uid, ok := s.UserID(r)
	if !ok || uid != 42 {
		t.Fatalf("round trip: %v %v", uid, ok)
	}
	// tampered value rejected
	bad := *cookies[0]
	bad.Value += "x"
	r2 := httptest.NewRequest(http.MethodGet, "/", nil)
	r2.AddCookie(&bad)
	if _, ok := s.UserID(r2); ok {
		t.Fatal("tampered cookie accepted")
	}
	// expired rejected: issue from a store whose TTL is already negative
	expired := auth.NewCookieSessions([]byte("test-key-0123456789abcdef"), -time.Minute, false, "")
	ew := httptest.NewRecorder()
	expired.Issue(ew, 7)
	er := httptest.NewRequest(http.MethodGet, "/", nil)
	er.AddCookie(ew.Result().Cookies()[0])
	if _, ok := expired.UserID(er); ok {
		t.Fatal("expired cookie accepted")
	}
	// session key resolution
	if _, eph := auth.SessionKeyFromEnv("k", ""); eph {
		t.Fatal("explicit key must not be ephemeral")
	}
	appKey := "base64:" + "QUJDREVGR0hJSktMTU5PUFFSU1RVVldYWVowMTIzNDU2Nzg5MDEyMw=="
	if _, eph := auth.SessionKeyFromEnv("", appKey); eph {
		t.Fatal("valid APP_KEY must decode")
	}
	if _, eph := auth.SessionKeyFromEnv("", ""); !eph {
		t.Fatal("empty keys must be ephemeral")
	}
}

func TestDemoWriteBlocked(t *testing.T) {
	if auth.DemoWriteBlocked(false, "POST", "/api/v1/account") {
		t.Fatal("non-demo never blocked")
	}
	if auth.DemoWriteBlocked(true, "GET", "/api/v1/account") {
		t.Fatal("safe method never blocked")
	}
	if auth.DemoWriteBlocked(true, "POST", "/api/v1/calculators/manufacturing") {
		t.Fatal("calculators allowed")
	}
	// PHP's allow-list has 'api/v1/appraisals' (legacy path), so the v1
	// market appraisal endpoint stays blocked for demo users. Faithful port.
	if !auth.DemoWriteBlocked(true, "POST", "/api/v1/market/appraisals") {
		t.Fatal("market appraisals blocked like PHP")
	}
	if !auth.DemoWriteBlocked(true, "DELETE", "/api/v1/account") {
		t.Fatal("account delete blocked")
	}
	if !auth.DemoWriteBlocked(true, "POST", "/api/v1/build-programsXYZ") {
		t.Fatal("prefix-sibling must not match")
	}
}
