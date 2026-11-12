package calc

import "testing"

func TestFallbackShipGroups(t *testing.T) {
	if ShipCategoryID != 6 {
		t.Fatalf("ShipCategoryID = %d, want 6", ShipCategoryID)
	}
	for typeID, groupID := range FallbackShipGroups {
		name, ok := ShipGroups[groupID]
		if !ok || name == "" {
			t.Fatalf("type %d maps to unknown ship group %d", typeID, groupID)
		}
	}
	for _, want := range []int{587, 24702, 24698, 638, 17480, 32880} {
		if _, ok := FallbackShipGroups[want]; !ok {
			t.Fatalf("missing fallback ship group for %d", want)
		}
	}
}
