package calc

import (
	"sort"
	"strings"
)

// ProductionBlueprint is one ESI blueprint entry for can-produce math.
type ProductionBlueprint struct {
	TypeID            int
	MaterialEfficiency int
	TimeEfficiency    int
	Runs              int // -1 = BPO (unlimited)
}

// ProductionMaterial is one material line with availability math.
type ProductionMaterial struct {
	TypeID        int    `json:"type_id"`
	Name          string `json:"name"`
	RequiredPerRun int   `json:"required_per_run"`
	Available     int    `json:"available"`
	RunsPossible  int    `json:"runs_possible"`
}

// BuildableItem mirrors one calculateBuildableItems() row (pre-grouping).
type BuildableItem struct {
	BlueprintTypeID int                 `json:"blueprint_type_id"`
	ProductTypeID   int                 `json:"product_type_id"`
	ProductName     string              `json:"product_name"`
	ProductQtyPerRun int                `json:"product_qty_per_run"`
	ME              int                 `json:"me"`
	TE              int                 `json:"te"`
	MaxRuns         int                 `json:"max_runs"`
	BuildableRuns   int                 `json:"buildable_runs"`
	TotalOutput     int                 `json:"total_output"`
	Materials       []ProductionMaterial `json:"materials"`
}

// AggregateAssetMaterials ports aggregateMaterials(): type_id → quantity.
func AggregateAssetMaterials(assets []map[string]any) map[int]int {
	out := map[int]int{}
	for _, a := range assets {
		id := toInt(a["type_id"])
		qty := toInt(a["quantity"])
		if id != 0 && qty > 0 {
			out[id] += qty
		}
	}
	return out
}

// SDEActivity is the material/product data for one blueprint.
type SDEActivity struct {
	Materials []BaseMaterial
	Products  []BaseMaterial
}

// CalculateBuildableItems ports calculateBuildableItems(): per-blueprint
// max runs from hangar materials with the copy's ME, BPC-capped, grouped by
// product+ME and sorted by runs descending.
func CalculateBuildableItems(bps []ProductionBlueprint, materials map[int]int, activities map[int]SDEActivity) []BuildableItem {
	var items []BuildableItem
	for _, bp := range bps {
		if bp.TypeID == 0 {
			continue
		}
		act, ok := activities[bp.TypeID]
		if !ok || len(act.Materials) == 0 || len(act.Products) == 0 {
			continue
		}
		prod := act.Products[0]
		if prod.TypeID == 0 {
			continue
		}
		maxRuns := 1 << 30
		var details []ProductionMaterial
		for _, m := range act.Materials {
			if m.TypeID == 0 || m.Quantity == 0 {
				continue
			}
			// Per-run requirement at the copy's ME (runs=1 form of the ME
			// formula: max(1, ceil(round(base*(1-me/100), 2)))).
			adjusted := CalculateMaterials([]BaseMaterial{{TypeID: m.TypeID, Name: m.Name, Quantity: m.Quantity}}, bp.MaterialEfficiency, 1, 0)
			req := 1
			if len(adjusted) > 0 {
				req = adjusted[0].AdjustedQuantity
			}
			avail := materials[m.TypeID]
			possible := 0
			if req > 0 {
				possible = avail / req
			}
			if possible < maxRuns {
				maxRuns = possible
			}
			details = append(details, ProductionMaterial{
				TypeID: m.TypeID, Name: m.Name, RequiredPerRun: req,
				Available: avail, RunsPossible: possible,
			})
		}
		if maxRuns == 1<<30 {
			maxRuns = 0
		}
		if bp.Runs > 0 && maxRuns > bp.Runs {
			maxRuns = bp.Runs
		}
		if maxRuns <= 0 {
			continue
		}
		items = append(items, BuildableItem{
			BlueprintTypeID: bp.TypeID, ProductTypeID: prod.TypeID,
			ProductName: prod.Name, ProductQtyPerRun: max(prod.Quantity, 1),
			ME: bp.MaterialEfficiency, TE: bp.TimeEfficiency, MaxRuns: bp.Runs,
			BuildableRuns: maxRuns, TotalOutput: maxRuns * max(prod.Quantity, 1),
			Materials: details,
		})
	}

	// Group by product + ME, summing runs/output; any BPO makes max_runs -1.
	grouped := map[string]*BuildableItem{}
	var order []string
	for i := range items {
		it := items[i]
		k := itoa(it.ProductTypeID) + "_" + itoa(it.ME)
		if g, ok := grouped[k]; ok {
			g.BuildableRuns += it.BuildableRuns
			g.TotalOutput += it.TotalOutput
			if it.MaxRuns == -1 || g.MaxRuns == -1 {
				g.MaxRuns = -1
			} else {
				g.MaxRuns += it.MaxRuns
			}
		} else {
			cp := it
			grouped[k] = &cp
			order = append(order, k)
		}
	}
	out := make([]BuildableItem, 0, len(grouped))
	for _, k := range order {
		out = append(out, *grouped[k])
	}
	sort.Slice(out, func(i, j int) bool { return out[i].BuildableRuns > out[j].BuildableRuns })
	return out
}

// ResolveMaterialNames ports resolveAvailableMaterials(): names sorted
// case-insensitively, unknown types as "Type #id".
func ResolveMaterialNames(materials map[int]int, names map[int]string) []map[string]any {
	out := make([]map[string]any, 0, len(materials))
	for id, qty := range materials {
		name := names[id]
		if name == "" {
			name = "Type #" + itoa(id)
		}
		out = append(out, map[string]any{"type_id": id, "name": name, "quantity": qty})
	}
	sort.Slice(out, func(i, j int) bool {
		return strings.ToLower(out[i]["name"].(string)) < strings.ToLower(out[j]["name"].(string))
	})
	return out
}

func toInt(v any) int {
	switch n := v.(type) {
	case int:
		return n
	case int64:
		return int(n)
	case float64:
		return int(n)
	}
	return 0
}
