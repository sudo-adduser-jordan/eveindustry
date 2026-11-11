package esi

import (
	"context"
	"fmt"
	"net/http"
	"net/http/httptest"
	"testing"
	"time"
)

type stubCreds struct {
	cred        Credential
	ok          bool
	saved       *TokenPair
	deactivated bool
}

func (s *stubCreds) Credentials(ctx context.Context, id int64) (Credential, bool, error) {
	return s.cred, s.ok, nil
}
func (s *stubCreds) SaveTokens(ctx context.Context, id int64, access, refresh string, exp time.Time) error {
	s.saved = &TokenPair{AccessToken: access, RefreshToken: refresh}
	return nil
}
func (s *stubCreds) Deactivate(ctx context.Context, id int64) error {
	s.deactivated = true
	return nil
}

func charTestStack(srv *httptest.Server, cred Credential) (*CharacterClient, *stubCreds) {
	st := NewMemStore()
	lim := NewLimiter(st, "test")
	circ := NewCircuit(st)
	cl := NewClient(st, lim, circ, "Test/1.0")
	cl.BaseURL = srv.URL
	cl.HTTP = srv.Client()
	creds := &stubCreds{cred: cred, ok: true}
	ref := NewRefresher(st, creds, srv.Client(), "cid", "secret")
	ref.base = srv.URL
	return NewCharacterClient(cl, ref, creds, NewMemo(), nil), creds
}

func TestCharacterSkills(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/characters/42/skills/" {
			w.WriteHeader(404)
			return
		}
		if r.Header.Get("Authorization") != "Bearer tok" {
			w.WriteHeader(401)
			return
		}
		w.Write([]byte(`{"skills":[{"skill_id":3380,"trained_skill_level":5},{"skill_id":3388,"trained_skill_level":4}]}`))
	}))
	defer srv.Close()

	cc, _ := charTestStack(srv, Credential{CharacterID: 42, AccessToken: "tok", RefreshToken: "r",
		TokenExpiresAt: time.Now().Add(time.Hour), IsEsiActive: true})
	levels, err := cc.GetSkills(context.Background(), 42)
	if err != nil || levels[3380] != 5 || levels[3388] != 4 {
		t.Fatalf("got %+v %v", levels, err)
	}
	srv.Close()
	if _, err := cc.GetSkills(context.Background(), 42); err != nil {
		t.Fatalf("cache: %v", err)
	}
}

func TestCharacterCredentialGating(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Write([]byte(`{}`))
	}))
	defer srv.Close()
	cc, _ := charTestStack(srv, Credential{CharacterID: 7, IsEsiActive: false})
	if levels, err := cc.GetSkills(context.Background(), 7); err != nil || levels != nil {
		t.Fatalf("inactive must map to zeros path: %+v %v", levels, err)
	}
}

func TestCharacterPaginatedAssets(t *testing.T) {
	var calls int
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		calls++
		w.Header().Set("X-Pages", "2")
		page := r.URL.Query().Get("page")
		w.Write([]byte(fmt.Sprintf(`[{"item_id":%s}]`, page)))
	}))
	defer srv.Close()
	cc, _ := charTestStack(srv, Credential{CharacterID: 9, AccessToken: "t", RefreshToken: "r",
		TokenExpiresAt: time.Now().Add(time.Hour), IsEsiActive: true})
	all, err := cc.GetAssets(context.Background(), 9)
	if err != nil || len(all) != 2 || calls != 2 {
		t.Fatalf("got %+v %v calls=%d", all, err, calls)
	}
	srv2 := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Write([]byte(`[]`))
	}))
	defer srv2.Close()
	cc2, _ := charTestStack(srv2, Credential{CharacterID: 9, AccessToken: "t", RefreshToken: "r",
		TokenExpiresAt: time.Now().Add(time.Hour), IsEsiActive: true})
	if _, err := cc2.GetAssets(context.Background(), 9); err != nil {
		t.Fatal(err)
	}
}
