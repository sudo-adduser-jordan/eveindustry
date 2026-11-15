package http

import (
	"context"
	"errors"
	"net/http"
	"testing"

	"github.com/sudo-adduser-jordan/eveindustry/internal/esi"
)

type stubStatusUniverse struct {
	status *esi.ServerStatus
	err    error
}

func (s *stubStatusUniverse) GetServerStatus(ctx context.Context) (*esi.ServerStatus, error) {
	return s.status, s.err
}

func statusTestDeps(s *stubStatusUniverse) Deps {
	stub := testAuth()
	return Deps{Sessions: stub, Users: stub, EsiStatus: EsiStatusDeps{Universe: s}}
}

func TestEsiStatusOnline(t *testing.T) {
	d := statusTestDeps(&stubStatusUniverse{status: &esi.ServerStatus{Players: 23456, ServerVersion: "2824473"}})
	code, body := get(t, d, "/api/v1/esi/status")
	if code != http.StatusOK {
		t.Fatalf("code = %d %v", code, body)
	}
	data := body["data"].(map[string]any)
	if data["online"] != true || data["players"] != 23456.0 {
		t.Fatalf("data = %v, want online with players", data)
	}
}

func TestEsiStatusDown(t *testing.T) {
	d := statusTestDeps(&stubStatusUniverse{})
	code, body := get(t, d, "/api/v1/esi/status")
	if code != http.StatusOK {
		t.Fatalf("code = %d %v", code, body)
	}
	if body["data"].(map[string]any)["online"] != false {
		t.Fatalf("data = %v, want online false", body["data"])
	}
}

func TestEsiStatusError(t *testing.T) {
	d := statusTestDeps(&stubStatusUniverse{err: errors.New("boom")})
	code, body := get(t, d, "/api/v1/esi/status")
	if code != http.StatusBadGateway || body["code"] != "server_error" {
		t.Fatalf("code = %d %v, want 502 server_error", code, body)
	}
}

func TestEsiStatusNeedsUniverse(t *testing.T) {
	stub := testAuth()
	code, _ := get(t, Deps{Sessions: stub, Users: stub}, "/api/v1/esi/status")
	if code != http.StatusNotFound {
		t.Fatalf("code = %d, want 404 without a universe client", code)
	}
}
