package calc

import (
	"sort"
	"strings"
)

type OwnedBlueprint struct {
	MaterialEfficiency int
	TimeEfficiency     int
	OwnerNames         []string
}

func MergeOwnedBlueprints(entries []OwnedEntry) map[int]OwnedBlueprint {
	out := map[int]OwnedBlueprint{}
	for _, e := range entries {
		cur, ok := out[e.TypeID]
		if !ok {
			out[e.TypeID] = OwnedBlueprint{MaterialEfficiency: e.ME, TimeEfficiency: e.TE, OwnerNames: []string{e.Owner}}
			continue
		}
		found := false
		for _, n := range cur.OwnerNames {
			if n == e.Owner {
				found = true
				break
			}
		}
		if !found {
			cur.OwnerNames = append(cur.OwnerNames, e.Owner)
		}
		if e.ME > cur.MaterialEfficiency || (e.ME == cur.MaterialEfficiency && e.TE > cur.TimeEfficiency) {
			cur.MaterialEfficiency, cur.TimeEfficiency = e.ME, e.TE
		}
		out[e.TypeID] = cur
	}
	return out
}

type OwnedEntry struct {
	TypeID int
	ME     int
	TE     int
	Owner  string
}

func SortOwnedFirst[T any](items []T, owned func(T) bool, desc bool) {
	sort.SliceStable(items, func(i, j int) bool {
		oi, oj := owned(items[i]), owned(items[j])
		if oi != oj {
			if desc {
				return !oi && oj
			}
			return oi && !oj
		}
		return false
	})
}

func SortByGroupName[T any](items []T, name func(T) string, desc bool) {
	sort.SliceStable(items, func(i, j int) bool {
		a, b := name(items[i]), name(items[j])
		if desc {
			return strings.Compare(a, b) > 0
		}
		return strings.Compare(a, b) < 0
	})
}
