package calc

import (
	"testing"

	"github.com/sudo-adduser-jordan/eveindustry/internal/industry"
)

func TestFilterManufacturingBlueprints(t *testing.T) {
	acts := []industry.MfgActivity{
		{BlueprintTypeID: 1, Products: []map[string]any{{"type_id": 10, "name": "A", "quantity": 1}}},
		{BlueprintTypeID: 2, Products: []map[string]any{{"type_id": 20, "name": "B", "quantity": 1}}},
		{BlueprintTypeID: 3, Products: []map[string]any{}},
	}
	names := map[int]string{1: "Bp A", 2: "Bp B"}
	// invented + excluded + allow-list
	got := FilterManufacturingBlueprints(acts, map[int]bool{1: true}, nil, nil, names)
	if len(got) != 1 || got[0].BlueprintTypeID != 2 {
		t.Fatalf("invented filter: %+v", got)
	}
	got = FilterManufacturingBlueprints(acts, nil, map[int]bool{10: true}, nil, names)
	if len(got) != 1 || got[0].BlueprintTypeID != 1 {
		t.Fatalf("allow-list: %+v", got)
	}
	got = FilterManufacturingBlueprints(acts, nil, nil, map[int]bool{10: true, 20: true}, names)
	if len(got) != 0 {
		t.Fatalf("excluded: %+v", got)
	}
}

func TestApplyPrecomputedAdjustments(t *testing.T) {
	adj := ApplyPrecomputedAdjustments(900, 1000, 1, 2, 0, 0, 0)
	if adj.TotalInputCost != 1800 || adj.TotalOutputValue != 2000 || adj.Profit != 120 {
		t.Fatalf("got %+v", adj)
	}
	if adj.ProfitMargin != 6.7 {
		t.Fatalf("margin: %+v", adj)
	}
	// structure ME scales input: 900*2*0.96 = 1728
	adj = ApplyPrecomputedAdjustments(900, 1000, 1, 2, 4, 0, 0)
	if adj.TotalInputCost != 1728 {
		t.Fatalf("me: %+v", adj)
	}
}
