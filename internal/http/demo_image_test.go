package http

import (
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/sudo-adduser-jordan/eveindustry/internal/auth"
)

func demoImageReq(t *testing.T, path string) (int, http.Header, string) {
	t.Helper()
	e := New(Deps{})
	req := httptest.NewRequest(http.MethodGet, path, nil)
	rec := httptest.NewRecorder()
	e.ServeHTTP(rec, req)
	return rec.Code, rec.Header(), rec.Body.String()
}

func TestDemoPortraitRedirectsToCorpLogo(t *testing.T) {
	code, hdr, _ := demoImageReq(t, "/demo/portrait/99014526777.svg?size=128")
	if code != http.StatusFound {
		t.Fatalf("portrait = %d, want %d", code, http.StatusFound)
	}
	if loc := hdr.Get("Location"); loc != auth.DemoPortraitURL {
		t.Fatalf("portrait Location = %q, want %q", loc, auth.DemoPortraitURL)
	}
	if hdr.Get("Cache-Control") == "" {
		t.Fatalf("portrait missing Cache-Control")
	}
}

func TestDemoLogoServesSVG(t *testing.T) {
	code, hdr, body := demoImageReq(t, "/demo/logo/99101050398.svg?size=64")
	if code != http.StatusOK {
		t.Fatalf("logo = %d, want 200", code)
	}
	if ct := hdr.Get("Content-Type"); ct != "image/svg+xml" {
		t.Fatalf("content-type = %q, want image/svg+xml", ct)
	}
	if !strings.HasPrefix(body, "<svg") || !strings.Contains(body, `width="64"`) {
		t.Fatalf("logo body is not a sized SVG: %.120s", body)
	}
}

func TestDemoImageRejectsNonDemoIDs(t *testing.T) {
	for _, path := range []string{
		"/demo/portrait/12345.svg",
		"/demo/logo/631636893.svg",
		"/demo/portrait/notanid.svg",
		"/demo/logo/99300000000.svg",
	} {
		code, _, _ := demoImageReq(t, path)
		if code != http.StatusNotFound {
			t.Fatalf("GET %s = %d, want 404", path, code)
		}
	}
}
