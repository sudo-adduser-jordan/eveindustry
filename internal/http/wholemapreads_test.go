package http

import (
	"context"
	"database/sql"
	"net/http"
	"testing"
	"time"

	"github.com/sudo-adduser-jordan/eveindustry/internal/auth"
	"github.com/sudo-adduser-jordan/eveindustry/internal/store"
)

type stubWholemapLoc struct{}

func (s *stubWholemapLoc) GetLocation(ctx context.Context, id int64) (map[string]any, error) {
	return map[string]any{"solar_system_id": 30000142}, nil
}

type stubWholemapRef struct{ store.ReferenceStore }

func (s *stubWholemapRef) FindSystem(ctx context.Context, id int) (*store.SolarSystem, error) {
	if id == 30000142 {
		return &store.SolarSystem{SolarSystemID: id, SolarSystemName: "Jita",
			Security: sql.NullFloat64{Float64: 0.9, Valid: true},
			RegionID: sql.NullInt64{Int64: 10000002, Valid: true}}, nil
	}
	return nil, nil
}

func wholemapReadsTestDeps() Deps {
	stub := testAuth()
	stub.chars = []auth.Character{{
		CharacterID: 11, Name: "Main", IsMain: true, IsEsiActive: true,
		Scopes: []string{"esi-location.read_location.v1"},
	}}
	jumped := time.Date(2026, 11, 8, 10, 0, 0, 0, time.UTC)
	return Deps{Sessions: stub, Users: stub,
		Wholemap: &stubWholemap{
			sigs: map[int64]store.WholemapSignature{
				1: {ID: 1, UserID: 1, SystemID: 30000142, Type: "wormhole"},
				2: {ID: 2, UserID: 1, SystemID: 30000144, Type: "unknown"},
			},
			conns: map[int64]store.WormholeConnection{
				2: {ID: 2, UserID: 1, SignatureID: 1, FromSystemID: 30000142, Size: "large", Life: "stable", Mass: "stable"},
				8: {ID: 8, UserID: 9, SignatureID: 9, FromSystemID: 1, Size: "large", Life: "stable", Mass: "stable"},
			},
			notes: map[int64]store.WholemapNote{
				5: {ID: 5, UserID: 1, SystemID: 30000142, Status: "mapped"},
			},
			jumps: map[int64]store.WormholeJumpLog{
				1: {ID: 1, ConnectionID: 2, UserID: 1, JumpedAt: jumped},
			},
			nextID: 10,
		},
		WholemapReads: WholemapReadsDeps{
			Characters: &stubWholemapLoc{},
			Reference:  &stubWholemapRef{},
		},
	}
}

func TestWholemapMap(t *testing.T) {
	d := wholemapReadsTestDeps()
	code, body := get(t, d, "/api/v1/wholemap")
	if code != http.StatusOK {
		t.Fatalf("map = %d %v", code, body)
	}
	data := body["data"].(map[string]any)
	for _, k := range []string{"signatures", "connections", "notes", "systems"} {
		if _, ok := data[k]; !ok {
			t.Fatalf("missing %s: %v", k, data)
		}
	}
	if len(data["signatures"].([]any)) != 2 || len(data["notes"].([]any)) != 1 {
		t.Fatalf("map = %v", data)
	}
	// system filter narrows sigs + notes, not connections
	code, body = get(t, d, "/api/v1/wholemap?system_id=30000144")
	if code != http.StatusOK {
		t.Fatalf("filtered = %d %v", code, body)
	}
	fdata := body["data"].(map[string]any)
	if len(fdata["signatures"].([]any)) != 1 || len(fdata["notes"].([]any)) != 0 {
		t.Fatalf("filtered = %v", fdata)
	}
	if len(fdata["connections"].([]any)) != 1 {
		t.Fatalf("connections narrowed: %v", fdata)
	}
	if code, _ := get(t, d, "/api/v1/wholemap?system_id=x"); code != http.StatusUnprocessableEntity {
		t.Fatalf("bad system = %d", code)
	}
}

func TestJumpLogs(t *testing.T) {
	d := wholemapReadsTestDeps()
	code, body := get(t, d, "/api/v1/wholemap/connections/2/jump-logs")
	if code != http.StatusOK {
		t.Fatalf("jumps = %d %v", code, body)
	}
	if len(body["data"].([]any)) != 1 {
		t.Fatalf("jumps = %v", body["data"])
	}
	if code, _ := get(t, d, "/api/v1/wholemap/connections/8/jump-logs"); code != http.StatusForbidden {
		t.Fatalf("foreign = %d", code)
	}
	if code, _ := get(t, d, "/api/v1/wholemap/connections/99/jump-logs"); code != http.StatusNotFound {
		t.Fatalf("missing = %d", code)
	}
}

func TestWholemapLocation(t *testing.T) {
	d := wholemapReadsTestDeps()
	code, body := get(t, d, "/api/v1/wholemap/location")
	if code != http.StatusOK {
		t.Fatalf("location = %d %v", code, body)
	}
	data := body["data"].(map[string]any)
	if data["solar_system_id"] != 30000142.0 || data["solar_system_name"] != "Jita" {
		t.Fatalf("location = %v", data)
	}
	if data["security"] != 0.9 || data["region_id"] != 10000002.0 {
		t.Fatalf("sde = %v", data)
	}
	// foreign character -> 422
	if code, _ := get(t, d, "/api/v1/wholemap/location?character_id=99"); code != http.StatusUnprocessableEntity {
		t.Fatalf("foreign char = %d", code)
	}
	// no main -> 422
	d2 := wholemapReadsTestDeps()
	d2.Sessions.(*stubAuth).chars = []auth.Character{{CharacterID: 12, Name: "Alt"}}
	d2.Users.(*stubAuth).chars = []auth.Character{{CharacterID: 12, Name: "Alt"}}
	if code, _ := get(t, d2, "/api/v1/wholemap/location"); code != http.StatusUnprocessableEntity {
		t.Fatalf("no main = %d", code)
	}
	// missing scope -> 422
	d3 := wholemapReadsTestDeps()
	d3.Sessions.(*stubAuth).chars[0].Scopes = nil
	d3.Users.(*stubAuth).chars[0].Scopes = nil
	if code, _ := get(t, d3, "/api/v1/wholemap/location?character_id=11"); code != http.StatusUnprocessableEntity {
		t.Fatalf("no scope = %d", code)
	}
}
