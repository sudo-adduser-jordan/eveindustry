package jobs

import (
	"context"
	"encoding/json"
	"testing"
)

func TestDemoMoonScansSeedToolsTab(t *testing.T) {
	ctx := context.Background()
	s, _ := demoTestDB(t)
	h := HandlerDeps{DB: s}
	uid, err := s.EnsureDemoUser(ctx, "demo@eve-industrialist.invalid")
	if err != nil {
		t.Fatal(err)
	}
	if err := s.WipeUserContent(ctx, uid); err != nil {
		t.Fatal(err)
	}
	mainChar := int64(99000000001)
	mainCorp := int64(99100000001)
	if err := s.CreateDemoCharacter(ctx, demoCorpChar(uid, mainChar, mainCorp, "Demo Main", true)); err != nil {
		t.Fatal(err)
	}
	seedDemoMoonScans(ctx, h, mainCorp, "Demo Corp")

	systems, err := s.MoonScanSystems(ctx, mainCorp)
	if err != nil || len(systems) != 2 {
		t.Fatalf("systems = %d %v", len(systems), err)
	}
	names := map[string]bool{}
	for _, sys := range systems {
		names[sys.SystemName] = true
	}
	if !names["Tama"] || !names["Amamake"] {
		t.Fatalf("systems = %v, want Tama + Amamake", names)
	}
	tamaSys, err := s.MoonScanSystem(ctx, mainCorp, "Tama")
	if err != nil || tamaSys == nil {
		t.Fatalf("tama system = %+v %v", tamaSys, err)
	}
	moons, err := s.MoonScanMoons(ctx, tamaSys.ID)
	if err != nil || len(moons) != 3 {
		t.Fatalf("tama moons = %d %v", len(moons), err)
	}
	occupied := 0
	for _, m := range moons {
		var ores []map[string]any
		if err := json.Unmarshal([]byte(m.OresJSON), &ores); err != nil || len(ores) == 0 {
			t.Fatalf("moon %s ores = %q %v", m.MoonName, m.OresJSON, err)
		}
		for _, o := range ores {
			for _, key := range []string{"name", "type_id", "quantity", "tier", "ore_units", "price_per_unit", "value_contribution"} {
				if _, ok := o[key]; !ok {
					t.Fatalf("moon %s ore missing %s in %v", m.MoonName, key, o)
				}
			}
		}
		if m.Occupied {
			occupied++
			if !m.OccupyingCorporationID.Valid || m.OccupyingCorporationID.Int64 != mainCorp {
				t.Fatalf("occupied moon corp = %+v, want %d", m.OccupyingCorporationID, mainCorp)
			}
		}
	}
	if occupied != 1 {
		t.Fatalf("occupied moons = %d, want 1", occupied)
	}
	seedDemoMoonScans(ctx, h, mainCorp, "Demo Corp")
	again, err := s.MoonScanSystems(ctx, mainCorp)
	if err != nil || len(again) != 2 {
		t.Fatalf("reseed must be a no-op, systems = %d %v", len(again), err)
	}
}
