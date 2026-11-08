package http

import (
	"context"
	"database/sql"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"testing"

	"github.com/sudo-adduser-jordan/eveindustry/internal/store"
)

// stubReference is an in-memory store.ReferenceStore for handler tests.
type stubReference struct {
	groups   []store.MarketGroup
	types    []store.EveType
	systems  []store.SolarSystem
	stations []store.Station
	keep     map[int]bool
}

func (s *stubReference) RootGroups(ctx context.Context) ([]store.MarketGroup, error) {
	var out []store.MarketGroup
	for _, g := range s.groups {
		if !g.ParentGroupID.Valid {
			out = append(out, g)
		}
	}
	return out, nil
}

func (s *stubReference) ChildCount(ctx context.Context, groupID int) (int, error) {
	n := 0
	for _, g := range s.groups {
		if g.ParentGroupID.Valid && int(g.ParentGroupID.Int64) == groupID {
			n++
		}
	}
	return n, nil
}

func (s *stubReference) MarketGroup(ctx context.Context, groupID int) (*store.MarketGroup, error) {
	for _, g := range s.groups {
		if g.MarketGroupID == groupID {
			g := g
			return &g, nil
		}
	}
	return nil, nil
}

func (s *stubReference) ChildGroups(ctx context.Context, groupID int) ([]store.MarketGroup, error) {
	var out []store.MarketGroup
	for _, g := range s.groups {
		if g.ParentGroupID.Valid && int(g.ParentGroupID.Int64) == groupID {
			out = append(out, g)
		}
	}
	return out, nil
}

func (s *stubReference) TypesInGroup(ctx context.Context, groupID int, excludeMeta []int) ([]store.EveType, error) {
	var out []store.EveType
	for _, t := range s.types {
		if t.MarketGroupID.Valid && int(t.MarketGroupID.Int64) == groupID {
			out = append(out, t)
		}
	}
	return out, nil
}

func (s *stubReference) AllGroups(ctx context.Context) ([]store.MarketGroup, error) {
	return s.groups, nil
}

func (s *stubReference) ManufacturableGroupIDs(ctx context.Context) (map[int]bool, error) {
	return s.keep, nil
}

func (s *stubReference) SearchTypes(ctx context.Context, query string, limit int, excludeMeta []int) ([]store.EveType, error) {
	var out []store.EveType
	for _, t := range s.types {
		if len(out) >= limit {
			break
		}
		out = append(out, t)
	}
	return out, nil
}

func (s *stubReference) TypeExists(ctx context.Context, typeID int) (bool, error) {
	for _, t := range s.types {
		if t.TypeID == typeID {
			return true, nil
		}
	}
	return false, nil
}

func (s *stubReference) FindType(ctx context.Context, typeID int) (*store.EveType, error) {
	for _, t := range s.types {
		if t.TypeID == typeID {
			t := t
			return &t, nil
		}
	}
	return nil, nil
}

func (s *stubReference) FindSystem(ctx context.Context, systemID int) (*store.SolarSystem, error) {
	for _, y := range s.systems {
		if y.SolarSystemID == systemID {
			y := y
			return &y, nil
		}
	}
	return nil, nil
}

func (s *stubReference) SearchSystems(ctx context.Context, query string, limit int) ([]store.SolarSystem, error) {
	return s.systems, nil
}

func (s *stubReference) SystemsByName(ctx context.Context, names []string) ([]store.SolarSystem, error) {
	return s.systems, nil
}

func (s *stubReference) FindStation(ctx context.Context, stationID uint64) (*store.Station, error) {
	for _, st := range s.stations {
		if st.StationID == stationID {
			st := st
			return &st, nil
		}
	}
	return nil, nil
}

func (s *stubReference) SearchStations(ctx context.Context, query string, systemID *int, limit int) ([]store.Station, error) {
	return s.stations, nil
}

func (s *stubReference) StationsInSystem(ctx context.Context, systemID int) ([]store.Station, error) {
	return s.stations, nil
}

func testDeps() (Deps, *stubReference, *stubMarket) {
	ref := &stubReference{
		groups: []store.MarketGroup{
			{MarketGroupID: 1, Name: "Ships", HasTypes: false},
			{MarketGroupID: 2, Name: "Frigates", ParentGroupID: sql.NullInt64{Int64: 1, Valid: true}, HasTypes: true},
		},
		types: []store.EveType{
			{TypeID: 34, Name: "Tritanium", Published: true, MarketGroupID: sql.NullInt64{Int64: 2, Valid: true}},
		},
		systems: []store.SolarSystem{
			{SolarSystemID: 30000142, SolarSystemName: "Jita", Security: sql.NullFloat64{Float64: 0.9, Valid: true}},
		},
		stations: []store.Station{
			{StationID: 60003760, StationName: "Jita IV - Moon 4", SolarSystemID: sql.NullInt64{Int64: 30000142, Valid: true}},
		},
		keep: map[int]bool{1: true, 2: true},
	}
	mkt := &stubMarket{}
	authStub := testAuth()
	return Deps{Reference: ref, Market: mkt, Sessions: authStub, Users: authStub}, ref, mkt
}

func get(t *testing.T, d Deps, path string) (int, map[string]any) {
	t.Helper()
	e := New(d)
	req := httptest.NewRequest(http.MethodGet, path, nil)
	rec := httptest.NewRecorder()
	e.ServeHTTP(rec, req)
	var body map[string]any
	if err := json.Unmarshal(rec.Body.Bytes(), &body); err != nil {
		t.Fatalf("GET %s: body is not JSON: %v", path, err)
	}
	return rec.Code, body
}

func TestTypeSearchShortQueryIs422(t *testing.T) {
	d, _, _ := testDeps()
	// PHP's SearchRequest requires q (min 2): short/missing q is a 422.
	code, body := get(t, d, "/api/v1/types?q=x")
	if code != http.StatusUnprocessableEntity || body["code"] != "validation_failed" {
		t.Fatalf("short q: %d %v", code, body)
	}
	code, _ = get(t, d, "/api/v1/types")
	if code != http.StatusUnprocessableEntity {
		t.Fatalf("missing q: %d", code)
	}
	code, body = get(t, d, "/api/v1/types?q=Trit")
	if code != http.StatusOK {
		t.Fatalf("valid q: %d %v", code, body)
	}
}

func TestTypeShowAnd404(t *testing.T) {
	d, _, _ := testDeps()
	if code, _ := get(t, d, "/api/v1/types/34"); code != http.StatusOK {
		t.Fatalf("show = %d", code)
	}
	code, body := get(t, d, "/api/v1/types/999")
	if code != http.StatusNotFound || body["code"] != "not_found" {
		t.Fatalf("missing type: %d %v", code, body)
	}
}

func TestGroupTreeAndContents(t *testing.T) {
	d, _, _ := testDeps()
	code, body := get(t, d, "/api/v1/market-groups/tree")
	if code != http.StatusOK {
		t.Fatalf("tree = %d", code)
	}
	if len(body["data"].([]any)) != 1 {
		t.Fatalf("tree root: %v", body["data"])
	}
	code, body = get(t, d, "/api/v1/market-groups/2")
	if code != http.StatusOK {
		t.Fatalf("contents = %d", code)
	}
	data := body["data"].(map[string]any)
	if len(data["items"].([]any)) != 1 || len(data["breadcrumbs"].([]any)) != 2 {
		t.Fatalf("contents: %v", data)
	}
	if code, _ := get(t, d, "/api/v1/market-groups/999"); code != http.StatusNotFound {
		t.Fatalf("missing group = %d", code)
	}
}

func TestSystemsAndStations(t *testing.T) {
	d, _, _ := testDeps()
	if code, _ := get(t, d, "/api/v1/systems/30000142"); code != http.StatusOK {
		t.Fatalf("system show failed")
	}
	if code, body := get(t, d, "/api/v1/systems/1/cost-indices"); code != http.StatusNotImplemented || body["code"] != "server_error" {
		t.Fatalf("cost-indices: %d %v", code, body)
	}
	if code, _ := get(t, d, "/api/v1/stations/60003760"); code != http.StatusOK {
		t.Fatalf("station show failed")
	}
}
