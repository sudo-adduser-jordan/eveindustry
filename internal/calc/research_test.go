package calc

import (
	"testing"
)

func TestMergeOwnedBlueprints(t *testing.T) {
	got := MergeOwnedBlueprints([]OwnedEntry{
		{TypeID: 1, ME: 8, TE: 10, Owner: "A"},
		{TypeID: 1, ME: 10, TE: 4, Owner: "B"},
		{TypeID: 1, ME: 9, TE: 20, Owner: "A"},
		{TypeID: 2, ME: 0, TE: 0, Owner: "A"},
	})
	if got[1].MaterialEfficiency != 10 || len(got[1].OwnerNames) != 2 {
		t.Fatalf("got %+v", got[1])
	}
	if got[2].MaterialEfficiency != 0 {
		t.Fatalf("got %+v", got[2])
	}
}

func TestSortOwnedFirst(t *testing.T) {
	items := []int{1, 2, 3}
	owned := map[int]bool{2: true, 3: true}
	SortOwnedFirst(items, func(i int) bool { return owned[i] }, false)
	if items[0] != 2 || items[2] != 1 {
		t.Fatalf("asc: %v", items)
	}
	items = []int{1, 2, 3}
	SortOwnedFirst(items, func(i int) bool { return owned[i] }, true)
	if items[0] != 1 || items[2] != 3 {
		t.Fatalf("desc: %v", items)
	}
}
