package http

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"testing"
)

func TestUp(t *testing.T) {
	e := New(Deps{})
	req := httptest.NewRequest(http.MethodGet, "/up", nil)
	rec := httptest.NewRecorder()
	e.ServeHTTP(rec, req)
	if rec.Code != http.StatusOK {
		t.Fatalf("GET /up = %d, want 200", rec.Code)
	}
}

func TestRootIndex(t *testing.T) {
	e := New(Deps{})
	req := httptest.NewRequest(http.MethodGet, "/", nil)
	rec := httptest.NewRecorder()
	e.ServeHTTP(rec, req)
	if rec.Code != http.StatusOK {
		t.Fatalf("GET / = %d, want 200", rec.Code)
	}
	var body struct {
		Data map[string]string `json:"data"`
	}
	if err := json.Unmarshal(rec.Body.Bytes(), &body); err != nil {
		t.Fatal(err)
	}
	if body.Data["api"] != "/api/v1" || body.Data["health"] != "/api/v1/health" {
		t.Fatalf("GET / data = %v, want api + health pointers", body.Data)
	}
}

func TestHealthReportsFailedWithoutDeps(t *testing.T) {
	e := New(Deps{})
	req := httptest.NewRequest(http.MethodGet, "/api/v1/health", nil)
	rec := httptest.NewRecorder()
	e.ServeHTTP(rec, req)

	if rec.Code != http.StatusServiceUnavailable {
		t.Fatalf("GET /health = %d, want 503 with no DB/cache", rec.Code)
	}
	var body struct {
		Data struct {
			Status string            `json:"status"`
			Checks map[string]string `json:"checks"`
		} `json:"data"`
		Meta map[string]any `json:"meta"`
	}
	if err := json.Unmarshal(rec.Body.Bytes(), &body); err != nil {
		t.Fatalf("health body is not JSON: %v", err)
	}
	if body.Data.Status != "failed" {
		t.Fatalf("status = %q, want failed", body.Data.Status)
	}
	if body.Data.Checks["database"] != "failed" || body.Data.Checks["cache"] != "failed" {
		t.Fatalf("checks = %v, want both failed", body.Data.Checks)
	}
}

func TestMeIsUnauthenticatedStub(t *testing.T) {
	e := New(Deps{})
	req := httptest.NewRequest(http.MethodGet, "/api/v1/me", nil)
	rec := httptest.NewRecorder()
	e.ServeHTTP(rec, req)

	if rec.Code != http.StatusUnauthorized {
		t.Fatalf("GET /me = %d, want 401", rec.Code)
	}
	var body struct {
		Message string `json:"message"`
		Code    string `json:"code"`
	}
	if err := json.Unmarshal(rec.Body.Bytes(), &body); err != nil {
		t.Fatalf("me body is not JSON: %v", err)
	}
	if body.Code != "unauthenticated" {
		t.Fatalf("code = %q, want unauthenticated", body.Code)
	}
}

func TestUnknownV1RouteIsJSONNotFound(t *testing.T) {
	e := New(Deps{})
	req := httptest.NewRequest(http.MethodGet, "/api/v1/nope", nil)
	rec := httptest.NewRecorder()
	e.ServeHTTP(rec, req)
	if rec.Code != http.StatusUnauthorized {
		t.Fatalf("anon GET /api/v1/nope = %d, want 401", rec.Code)
	}

	stub := testAuth()
	d := Deps{Sessions: stub, Users: stub}
	code, body := get(t, d, "/api/v1/nope")
	if code != http.StatusNotFound {
		t.Fatalf("GET /api/v1/nope = %d, want 404", code)
	}
	if body["code"] != "not_found" {
		t.Fatalf("code = %v, want not_found", body)
	}
}
