package http

import (
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/sudo-adduser-jordan/eveindustry/internal/auth"
	"github.com/sudo-adduser-jordan/eveindustry/internal/cache"
	"github.com/sudo-adduser-jordan/eveindustry/internal/store"
	"github.com/sudo-adduser-jordan/eveindustry/migrations"
)

// stubAuth implements auth.Sessions + store.AuthStore for handler tests: a
// fixed logged-in user (uid 0 = anonymous).
type stubAuth struct {
	uid     int64
	user    *auth.User
	chars   []auth.Character
	deleted int64
	issued  int64
}

func testAuth() *stubAuth {
	return &stubAuth{
		uid:  1,
		user: &auth.User{ID: 1, Name: "Test User"},
	}
}

func (s *stubAuth) UserID(r *http.Request) (int64, bool)           { return s.uid, s.uid > 0 }
func (s *stubAuth) Issue(w http.ResponseWriter, userID int64)      { s.issued = userID }
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

// stubDemo implements DemoUsers for handler tests.
type stubDemo struct {
	id      int64
	email   string
	touched int64
}

func (s *stubDemo) EnsureDemoUser(ctx context.Context, email string) (int64, error) {
	s.email = email
	return s.id, nil
}

func (s *stubDemo) TouchDemoUser(ctx context.Context, id int64) error {
	s.touched = id
	return nil
}

func getRaw(t *testing.T, d Deps, method, path string) *httptest.ResponseRecorder {
	t.Helper()
	e := New(d)
	req := httptest.NewRequest(method, path, nil)
	rec := httptest.NewRecorder()
	e.ServeHTTP(rec, req)
	return rec
}

func TestLoginPageAnonymous(t *testing.T) {
	// The SPA owns the login UI: anonymous visits bounce to /app/login.
	rec := getRaw(t, Deps{}, "GET", "/login")
	if rec.Code != http.StatusFound {
		t.Fatalf("GET /login = %d, want 302", rec.Code)
	}
	if loc := rec.Header().Get("Location"); loc != "/app/login" {
		t.Fatalf("location = %q, want /app/login", loc)
	}
}

func TestLoginRedirectsWhenAuthenticated(t *testing.T) {
	stub := testAuth()
	rec := getRaw(t, Deps{Sessions: stub, Users: stub}, "GET", "/login")
	if rec.Code != http.StatusFound {
		t.Fatalf("GET /login authed = %d, want 302", rec.Code)
	}
	if loc := rec.Header().Get("Location"); loc != "/app/dashboard" {
		t.Fatalf("location = %q, want /app/dashboard", loc)
	}
	// Unknown user id bounces to the SPA login instead of redirecting in.
	rec = getRaw(t, Deps{Sessions: &stubAuth{uid: 99}, Users: stub}, "GET", "/login")
	if rec.Code != http.StatusFound {
		t.Fatalf("GET /login unknown user = %d, want 302", rec.Code)
	}
	if loc := rec.Header().Get("Location"); loc != "/app/login" {
		t.Fatalf("location = %q, want /app/login", loc)
	}
}

func TestWelcomePage(t *testing.T) {
	rec := getRaw(t, Deps{}, "GET", "/welcome")
	if rec.Code != http.StatusFound {
		t.Fatalf("GET /welcome = %d, want 302", rec.Code)
	}
	if loc := rec.Header().Get("Location"); loc != "/app/login" {
		t.Fatalf("location = %q, want /app/login", loc)
	}
	stub := testAuth()
	rec = getRaw(t, Deps{Sessions: stub, Users: stub}, "GET", "/welcome")
	if rec.Code != http.StatusFound {
		t.Fatalf("GET /welcome authed = %d, want 302", rec.Code)
	}
}

func TestDemoLoginDisabledIs404(t *testing.T) {
	// Flag off: 404 like the PHP route.
	e := New(Deps{})
	req := httptest.NewRequest(http.MethodPost, "/demo/login", nil)
	rec := httptest.NewRecorder()
	e.ServeHTTP(rec, req)
	if rec.Code != http.StatusNotFound {
		t.Fatalf("POST /demo/login disabled = %d, want 404", rec.Code)
	}
	var body map[string]any
	if err := json.Unmarshal(rec.Body.Bytes(), &body); err != nil {
		t.Fatalf("body is not JSON: %v", err)
	}
	if body["code"] != "not_found" {
		t.Fatalf("code = %v, want not_found", body)
	}
	// Flag on but no stores: still 404, never 500.
	e = New(Deps{DemoEnabled: true})
	req = httptest.NewRequest(http.MethodPost, "/demo/login", nil)
	rec = httptest.NewRecorder()
	e.ServeHTTP(rec, req)
	if rec.Code != http.StatusNotFound {
		t.Fatalf("POST /demo/login without stores = %d, want 404", rec.Code)
	}
}

func TestDemoLoginEnabledIssuesSession(t *testing.T) {
	authStub := testAuth()
	demo := &stubDemo{id: 7}
	d := Deps{Sessions: authStub, Users: authStub, DemoUsers: demo, DemoEnabled: true}
	e := New(d)
	req := httptest.NewRequest(http.MethodPost, "/demo/login", nil)
	rec := httptest.NewRecorder()
	e.ServeHTTP(rec, req)
	if rec.Code != http.StatusFound {
		t.Fatalf("POST /demo/login = %d, want 302", rec.Code)
	}
	if loc := rec.Header().Get("Location"); loc != "/app/dashboard" {
		t.Fatalf("location = %q, want /app/dashboard", loc)
	}
	if demo.email != DemoEmail || demo.touched != 7 {
		t.Fatalf("demo store calls: email=%q touched=%d", demo.email, demo.touched)
	}
	if authStub.issued != 7 {
		t.Fatalf("issued session uid = %d, want 7", authStub.issued)
	}
}

// TestDemoLoginFlowEndToEnd runs the real demo sign-in path: POST
// /demo/login issues a session cookie against SQLite, and the SPA's /me
// call resolves the demo account.
func TestDemoLoginFlowEndToEnd(t *testing.T) {
	db, err := store.Connect(filepath.Join(t.TempDir(), "demo.sqlite"))
	if err != nil {
		t.Fatal(err)
	}
	defer func() { _ = db.DB.Close() }()
	for _, name := range []string{
		"0000_00_00_000000_baseline.up.sql",
		"2026_11_09_000001_notification_settings_unique.up.sql",
	} {
		raw, err := migrations.FS.ReadFile(name)
		if err != nil {
			t.Fatal(err)
		}
		for _, stmt := range strings.Split(string(raw), ";\n") {
			if strings.TrimSpace(stmt) == "" {
				continue
			}
			if _, err := db.DB.Exec(stmt); err != nil {
				t.Fatalf("%s: %v\n%s", name, err, stmt)
			}
		}
	}
	queries := store.NewSQL(db.DB)
	d := Deps{
		Sessions: auth.NewCookieSessions([]byte("0123456789abcdef0123456789abcdef"), 0, false, ""),
		Users:    queries, DemoUsers: queries, DemoEnabled: true,
		Cache: &cache.Cache{DB: db.DB},
	}
	e := New(d)

	req := httptest.NewRequest(http.MethodPost, "/demo/login", nil)
	rec := httptest.NewRecorder()
	e.ServeHTTP(rec, req)
	if rec.Code != http.StatusFound {
		t.Fatalf("POST /demo/login = %d, want 302: %s", rec.Code, rec.Body.String())
	}
	cookies := rec.Result().Cookies()
	if len(cookies) == 0 {
		t.Fatal("demo login issued no cookie")
	}
	req = httptest.NewRequest(http.MethodGet, "/api/v1/me", nil)
	req.AddCookie(cookies[0])
	rec = httptest.NewRecorder()
	e.ServeHTTP(rec, req)
	if rec.Code != http.StatusOK {
		t.Fatalf("GET /me = %d: %s", rec.Code, rec.Body.String())
	}
	var body struct {
		Data struct {
			Demo        bool   `json:"demo"`
			DisplayName string `json:"display_name"`
		} `json:"data"`
	}
	if err := json.Unmarshal(rec.Body.Bytes(), &body); err != nil {
		t.Fatal(err)
	}
	if !body.Data.Demo || body.Data.DisplayName != "Demo Account" {
		t.Fatalf("me = %+v, want the demo account", body.Data)
	}
	req = httptest.NewRequest(http.MethodGet, "/login", nil)
	req.AddCookie(cookies[0])
	rec = httptest.NewRecorder()
	e.ServeHTTP(rec, req)
	if rec.Code != http.StatusFound || rec.Header().Get("Location") != "/app/dashboard" {
		t.Fatalf("GET /login authed = %d %q", rec.Code, rec.Header().Get("Location"))
	}
}

func TestDemoLoginJSONReturnsRedirectBody(t *testing.T) {
	// The SPA login page posts with Accept: application/json and navigates
	// itself, so the handler answers 200 with the redirect target.
	authStub := testAuth()
	demo := &stubDemo{id: 7}
	d := Deps{Sessions: authStub, Users: authStub, DemoUsers: demo, DemoEnabled: true}
	e := New(d)
	req := httptest.NewRequest(http.MethodPost, "/demo/login", nil)
	req.Header.Set("Accept", "application/json")
	rec := httptest.NewRecorder()
	e.ServeHTTP(rec, req)
	if rec.Code != http.StatusOK {
		t.Fatalf("POST /demo/login json = %d, want 200: %s", rec.Code, rec.Body.String())
	}
	var body struct {
		Data struct {
			Redirect string `json:"redirect"`
		} `json:"data"`
	}
	if err := json.Unmarshal(rec.Body.Bytes(), &body); err != nil {
		t.Fatal(err)
	}
	if body.Data.Redirect != "/app/dashboard" {
		t.Fatalf("redirect = %q, want /app/dashboard", body.Data.Redirect)
	}
	if authStub.issued != 7 {
		t.Fatalf("issued session uid = %d, want 7", authStub.issued)
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

func (s *stubAuth) UpdateCharacterLocation(ctx context.Context, userID, characterID int64, location string) error {
	return nil
}

func (s *stubAuth) CharacterOwner(ctx context.Context, id int64) (int64, bool, int, error) {
	for _, ch := range s.chars {
		if ch.CharacterID == id {
			// test characters belong to user 1.
			return 1, ch.IsMain, len(s.chars), nil
		}
	}
	return 0, false, 0, nil
}
func (s *stubAuth) SetMainCharacter(ctx context.Context, uid, id int64) error {
	for i := range s.chars {
		s.chars[i].IsMain = s.chars[i].CharacterID == id
	}
	return nil
}
func (s *stubAuth) DeleteCharacter(ctx context.Context, uid, id int64) error {
	var kept []auth.Character
	for _, ch := range s.chars {
		if ch.CharacterID != id {
			kept = append(kept, ch)
		}
	}
	s.chars = kept
	return nil
}

func (s *stubAuth) UpdateCharacterRefresh(ctx context.Context, id int64, r store.CharacterRefresh) error {
	return nil
}
func (s *stubAuth) EsiActiveCharacters(ctx context.Context, uid *int64) ([]auth.Character, error) {
	return s.chars, nil
}
