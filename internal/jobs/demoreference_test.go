package jobs

import (
	"context"
	"errors"
	"testing"
)

// stubRefSource answers reference reads from canned ESI payloads.
type stubRefSource struct {
	types    map[int]map[string]any
	stations map[uint64]map[string]any
	systems  map[int]map[string]any
	failIDs  map[int]bool
}

func (s *stubRefSource) GetTypeInfo(ctx context.Context, typeID int) (map[string]any, error) {
	if s.failIDs[typeID] {
		return nil, errors.New("esi down")
	}
	return s.types[typeID], nil
}

func (s *stubRefSource) GetStation(ctx context.Context, stationID uint64) (map[string]any, error) {
	return s.stations[stationID], nil
}

func (s *stubRefSource) GetSystem(ctx context.Context, systemID int) (map[string]any, error) {
	return s.systems[systemID], nil
}

func TestDemoReferenceTypeIDs(t *testing.T) {
	ids := demoReferenceTypeIDs()
	if len(ids) == 0 {
		t.Fatal("no demo type ids")
	}
	for i := 1; i < len(ids); i++ {
		if ids[i-1] >= ids[i] {
			t.Fatalf("not sorted/deduped: %v", ids)
		}
	}
	for _, want := range []int{34, 35, 36, 37, 38, 39, 40, 17887, 17888, 17889, 16274, 587, 24702, 24698, 638, 17480, 32880,
		2048, 3839, 2281, 3178, 47831, 47775, 99101, 99102, 99103, 99104} {
		found := false
		for _, id := range ids {
			if id == want {
				found = true
				break
			}
		}
		if !found {
			t.Fatalf("missing demo type %d in %v", want, ids)
		}
	}
}

func TestCollectDemoReference(t *testing.T) {
	src := &stubRefSource{
		types: map[int]map[string]any{
			34: {"name": "Tritanium", "group_id": float64(18), "published": true},
			35: {"name": "Pyerite", "group_id": float64(18), "published": true},
		},
		stations: map[uint64]map[string]any{
			60003760: {"name": "Jita IV - Moon 4 - Caldari Navy Assembly Plant", "system_id": float64(30000142)},
		},
		systems: map[int]map[string]any{
			30000142: {"name": "Jita", "constellation_id": float64(20000020)},
		},
		failIDs: map[int]bool{35: true},
	}
	ref := collectDemoReference(context.Background(), src)
	// Tritanium resolves; Pyerite fails and has no fallback so it is skipped.
	// Other demo types resolve via static fallbacks when ESI is unreachable.
	byID := map[int]bool{}
	for _, row := range ref.Types {
		byID[row.TypeID] = true
		if row.TypeID == 35 {
			t.Fatal("failed type must be skipped")
		}
	}
	var tritFound, tritPublished bool
	var tritGID int64
	for _, row := range ref.Types {
		if row.TypeID == 34 {
			tritFound = true
			tritPublished = row.Published
			if row.GroupID != nil {
				tritGID = *row.GroupID
			}
		}
	}
	if !tritFound || tritGID != 18 || !tritPublished {
		t.Fatalf("tritanium row found=%v gid=%d published=%v", tritFound, tritGID, tritPublished)
	}
	// Demo ships fall back to static names when the stub has no ESI row.
	if !byID[587] {
		t.Fatalf("ship fallback missing in %+v", ref.Types)
	}
	// Mutated abyssal types are demo-only: they carry meta group 15.
	foundMut := false
	for _, row := range ref.Types {
		if row.TypeID == 99101 {
			foundMut = true
			if row.MetaGroupID == nil || *row.MetaGroupID != 15 {
				t.Fatalf("mutated meta = %+v, want 15", row)
			}
			if !row.Published {
				t.Fatal("mutated must be published")
			}
		}
	}
	if !foundMut {
		t.Fatalf("mutated 99101 missing in %+v", ref.Types)
	}
	// Ship groups/categories seed alongside types so the ships tab groups.
	foundGroup := false
	for _, g := range ref.Groups {
		if g.GroupID == 25 && g.Name == "Frigate" {
			foundGroup = true
		}
	}
	if !foundGroup {
		t.Fatalf("ship groups = %+v, want Frigate 25", ref.Groups)
	}
	foundCat := false
	for _, c := range ref.Categories {
		if c.CategoryID == 6 && c.Name == "Ship" {
			foundCat = true
		}
	}
	if !foundCat {
		t.Fatalf("ship categories = %+v, want Ship 6", ref.Categories)
	}
	if len(ref.Stations) != 1 || ref.Stations[0].StationID != 60003760 {
		t.Fatalf("stations = %+v", ref.Stations)
	}
	if ref.Stations[0].SolarSystemID == nil || *ref.Stations[0].SolarSystemID != 30000142 {
		t.Fatalf("station system = %+v", ref.Stations[0])
	}
	if len(ref.Systems) != 1 || ref.Systems[0].Name != "Jita" {
		t.Fatalf("systems = %+v", ref.Systems)
	}
}

func TestSeedDemoReferenceDataNoDeps(t *testing.T) {
	// Zero handler (no DB, no ESI) is a no-op, never a panic.
	seedDemoReferenceData(context.Background(), HandlerDeps{})
}
