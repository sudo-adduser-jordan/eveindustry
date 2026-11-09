package calc

import (
	"context"
	"database/sql"
	"sort"

	"github.com/sudo-adduser-jordan/eveindustry/internal/industry"
)

func nullStringOf(ns sql.NullString) *string {
	if !ns.Valid {
		return nil
	}
	return &ns.String
}

// Reaction rig ME tables mirror ReactionsCalculatorService::RIG_ME_BONUS
// and SECURITY_MULTIPLIER. Note the multipliers: reactions lowsec 1.0,
// nullsec 1.1 (unlike manufacturing rigs).
func ResolveRigBonus(rigTier, securitySpace string) float64 {
	base := 0.0
	switch rigTier {
	case "t1":
		base = 2.0
	case "t2":
		base = 2.4
	}
	mult := 1.0
	if securitySpace == "nullsec" {
		mult = 1.1
	}
	return base * mult
}

// JobCost ports IndustryProfitCalculator::jobCost(): job fee on the input
// cost, facility tax on the job fee.
func JobCost(inputCost, systemCostIndexPct, facilityTaxPct float64) (jobFee, facilityTax, total float64) {
	jobFee = inputCost * (systemCostIndexPct / 100)
	facilityTax = jobFee * (facilityTaxPct / 100)
	return jobFee, facilityTax, jobFee + facilityTax
}

// PlanEntry is one requested composite: formula, slots, runs per slot and an
// optional per-entry rig bonus overriding the global one.
type PlanEntry struct {
	FormulaTypeID int
	Slots         int
	RunsPerSlot   int
	RigBonus      *float64
}

// CompositeOutput mirrors one resolveChain() composite_outputs row.
type CompositeOutput struct {
	FormulaTypeID int    `json:"formula_type_id"`
	ProductTypeID *int   `json:"product_type_id"`
	ProductName   string `json:"product_name"`
	Slots         int    `json:"slots"`
	RunsPerSlot   int    `json:"runs_per_slot"`
	TotalRuns     int    `json:"total_runs"`
	TotalOutput   int    `json:"total_output"`
	PortionSize   int    `json:"portion_size"`
}

// PlanSlot mirrors composite/intermediate slot rows.
type PlanSlot struct {
	FormulaTypeID int    `json:"formula_type_id"`
	ProductTypeID *int   `json:"product_type_id,omitempty"`
	ProductName   string `json:"product_name"`
	Slots         int    `json:"slots"`
	RunsPerSlot   *int   `json:"runs_per_slot,omitempty"`
	TotalRuns     *int   `json:"total_runs,omitempty"`
}

// RawMaterial is one aggregated priced raw material.
type RawMaterial struct {
	TypeID      int     `json:"type_id"`
	Name        string  `json:"name"`
	Quantity    int     `json:"quantity"`
	PriceSource string  `json:"price_source"`
	UnitPrice   float64 `json:"unit_price"`
	TotalCost   float64 `json:"total_cost"`
	Volume      float64 `json:"volume"`
}

// PlanChain is the priced reaction plan before the summary block.
type PlanChain struct {
	CompositeOutputs       []CompositeOutput `json:"composite_outputs"`
	CompositeSlots         []PlanSlot        `json:"composite_slots"`
	IntermediateSlots      []PlanSlot        `json:"intermediate_slots"`
	RawMaterials           []RawMaterial     `json:"raw_materials"`
	TotalCompositeSlots    int               `json:"total_composite_slots"`
	TotalIntermediateSlots int               `json:"total_intermediate_slots"`
	TotalSlots             int               `json:"total_slots"`
	TotalInputCost         float64           `json:"total_input_cost"`
	TotalOutputValue       float64           `json:"total_output_value"`
}

// ResolveReactionChain ports ReactionPlannerService::resolveChain() over the
// industry store: composite formulas from SDE activity 11, intermediates via
// producer lookup, raw goo aggregated by type and sorted by name.
func ResolveReactionChain(ctx context.Context, st industry.IndustryStore, entries []PlanEntry, globalRigBonus float64) (PlanChain, error) {
	var out PlanChain
	out.CompositeOutputs = []CompositeOutput{}
	out.CompositeSlots = []PlanSlot{}
	out.IntermediateSlots = []PlanSlot{}
	rawAgg := map[int]*RawMaterial{}

	for _, e := range entries {
		rig := globalRigBonus
		if e.RigBonus != nil {
			rig = *e.RigBonus
		}
		row, err := st.ActivityByBlueprint(ctx, e.FormulaTypeID, industry.ActivityReaction)
		if err != nil {
			return out, err
		}
		if row == nil {
			continue
		}
		mats := industry.DecodeJSONList(nullStringOf(row.Materials))
		prods := industry.DecodeJSONList(nullStringOf(row.Products))
		if len(mats) == 0 {
			continue
		}
		portion, pname := 1, "Unknown"
		var pid *int
		if len(prods) > 0 {
			if q, ok := numInt(prods[0]["quantity"]); ok && q > 0 {
				portion = q
			}
			if n, ok := prods[0]["name"].(string); ok {
				pname = n
			}
			if id, ok := numInt(prods[0]["type_id"]); ok && id > 0 {
				pid = &id
			}
		}
		totalRuns := e.Slots * e.RunsPerSlot
		out.CompositeOutputs = append(out.CompositeOutputs, CompositeOutput{
			FormulaTypeID: e.FormulaTypeID, ProductTypeID: pid, ProductName: pname,
			Slots: e.Slots, RunsPerSlot: e.RunsPerSlot, TotalRuns: totalRuns,
			TotalOutput: totalRuns * portion, PortionSize: portion,
		})
		out.CompositeSlots = append(out.CompositeSlots, PlanSlot{
			FormulaTypeID: e.FormulaTypeID, ProductName: pname, Slots: e.Slots,
		})

		base := make([]BaseMaterial, 0, len(mats))
		for _, m := range mats {
			id, _ := numInt(m["type_id"])
			name, _ := m["name"].(string)
			qty, _ := numInt(m["quantity"])
			if name == "" {
				name = "Unknown"
			}
			base = append(base, BaseMaterial{TypeID: id, Name: name, Quantity: qty})
		}
		for _, m := range ReactionMaterials(base, totalRuns, rig) {
			inter, err := st.ActivityProducingType(ctx, m.TypeID, industry.ActivityReaction)
			if err != nil {
				return out, err
			}
			if inter == nil {
				addRaw(rawAgg, m.TypeID, m.Name, m.AdjustedQuantity)
				continue
			}
			if err := resolveIntermediate(ctx, st, &out, inter, m.TypeID, m.AdjustedQuantity, e.RunsPerSlot, rig, rawAgg); err != nil {
				return out, err
			}
		}
	}

	raw := make([]RawMaterial, 0, len(rawAgg))
	for _, r := range rawAgg {
		raw = append(raw, *r)
	}
	sort.Slice(raw, func(i, j int) bool { return raw[i].Name < raw[j].Name })
	out.RawMaterials = raw
	for _, s := range out.CompositeSlots {
		out.TotalCompositeSlots += s.Slots
	}
	for _, s := range out.IntermediateSlots {
		out.TotalIntermediateSlots += s.Slots
	}
	out.TotalSlots = out.TotalCompositeSlots + out.TotalIntermediateSlots
	return out, nil
}

func resolveIntermediate(ctx context.Context, st industry.IndustryStore, out *PlanChain, inter *industry.BlueprintActivityRow, productTypeID, requiredQty, runsPerSlot int, rig float64, rawAgg map[int]*RawMaterial) error {
	prods := industry.DecodeJSONList(nullStringOf(inter.Products))
	mats := industry.DecodeJSONList(nullStringOf(inter.Materials))
	portion := 1
	iname := "Unknown"
	for _, p := range prods {
		if id, _ := numInt(p["type_id"]); id == productTypeID {
			if q, ok := numInt(p["quantity"]); ok && q > 0 {
				portion = q
			}
			if n, ok := p["name"].(string); ok {
				iname = n
			}
			break
		}
	}
	totalRuns := (requiredQty + portion - 1) / portion
	slotsNeeded := totalRuns / runsPerSlot
	if totalRuns%runsPerSlot != 0 {
		slotsNeeded++
	}
	if slotsNeeded < 1 {
		slotsNeeded = 1
	}
	perSlot := totalRuns
	if slotsNeeded > 0 {
		perSlot = (totalRuns + slotsNeeded - 1) / slotsNeeded
	}
	pid := productTypeID
	found := false
	for i := range out.IntermediateSlots {
		if s := &out.IntermediateSlots[i]; s.ProductTypeID != nil && *s.ProductTypeID == productTypeID {
			s.Slots += slotsNeeded
			*s.TotalRuns += totalRuns
			found = true
			break
		}
	}
	if !found {
		rps, tr := perSlot, totalRuns
		out.IntermediateSlots = append(out.IntermediateSlots, PlanSlot{
			FormulaTypeID: inter.BlueprintTypeID, ProductTypeID: &pid,
			ProductName: iname, Slots: slotsNeeded, RunsPerSlot: &rps, TotalRuns: &tr,
		})
	}

	base := make([]BaseMaterial, 0, len(mats))
	for _, m := range mats {
		id, _ := numInt(m["type_id"])
		name, _ := m["name"].(string)
		qty, _ := numInt(m["quantity"])
		if name == "" {
			name = "Unknown"
		}
		base = append(base, BaseMaterial{TypeID: id, Name: name, Quantity: qty})
	}
	for _, m := range ReactionMaterials(base, totalRuns, rig) {
		addRaw(rawAgg, m.TypeID, m.Name, m.AdjustedQuantity)
	}
	return nil
}

func addRaw(agg map[int]*RawMaterial, typeID int, name string, qty int) {
	if r, ok := agg[typeID]; ok {
		r.Quantity += qty
		return
	}
	agg[typeID] = &RawMaterial{TypeID: typeID, Name: name, Quantity: qty}
}

func numInt(v any) (int, bool) {
	switch n := v.(type) {
	case int:
		return n, true
	case int64:
		return int(n), true
	case float64:
		return int(n), true
	}
	return 0, false
}
