package calc

import (
	"testing"
)

func TestAggregateAssetMaterials(t *testing.T) {
	got := AggregateAssetMaterials([]map[string]any{
		{"type_id": 34, "quantity": 100},
		{"type_id": 34, "quantity": 7},
		{"type_id": 35, "quantity": 0},
		{"type_id": 0, "quantity": 5},
	})
	if len(got) != 1 || got[34] != 107 {
		t.Fatalf("got %v", got)
	}
}

func TestCalculateBuildableItems(t *testing.T) {
	bps := []ProductionBlueprint{
		{TypeID: 786, MaterialEfficiency: 10, Runs: -1},
		{TypeID: 787, MaterialEfficiency: 0, Runs: 3},
		{TypeID: 999},
	}
	mats := map[int]int{34: 950}
	acts := map[int]SDEActivity{
		786: {
			Materials: []BaseMaterial{{TypeID: 34, Name: "Trit", Quantity: 100}},
			Products:  []BaseMaterial{{TypeID: 587, Name: "Rifter", Quantity: 1}},
		},
		787: {
			Materials: []BaseMaterial{{TypeID: 34, Name: "Trit", Quantity: 100}},
			Products:  []BaseMaterial{{TypeID: 587, Name: "Rifter", Quantity: 1}},
		},
	}
	got := CalculateBuildableItems(bps, mats, acts)
	// 786: req ceil(90)=90 -> 950/90 = 10 runs (BPO, unlimited cap)
	// 787: req 100 -> 9 runs, capped to 3 BPC runs
	// grouped by 587_ME10: 10 runs; 587_ME0: 3 runs
	if len(got) != 2 {
		t.Fatalf("got %+v", got)
	}
	if got[0].BuildableRuns != 10 || got[0].TotalOutput != 10 {
		t.Fatalf("first: %+v", got[0])
	}
	if got[1].BuildableRuns != 3 || got[1].MaxRuns != 3 {
		t.Fatalf("second: %+v", got[1])
	}
	if got[0].Materials[0].RequiredPerRun != 90 || got[0].Materials[0].RunsPossible != 10 {
		t.Fatalf("material: %+v", got[0].Materials)
	}
}

func TestResolveMaterialNames(t *testing.T) {
	got := ResolveMaterialNames(map[int]int{34: 5, 99: 2}, map[int]string{34: "b"})
	if len(got) != 2 || got[0]["name"] != "b" || got[1]["name"] != "Type #99" {
		t.Fatalf("got %v", got)
	}
}
