package calc

import (
	"context"

	"github.com/sudo-adduser-jordan/eveindustry/internal/industry"
)

// FuelBlockGroupID mirrors AssetService::FUEL_BLOCK_GROUP_ID: fuel blocks
// are raw materials, never resolved into sub-steps.
const FuelBlockGroupID = 1136

// ChainMaterial is one material line fed into the chain resolver.
// AdjustedQuantity wins over Quantity when both are present.
type ChainMaterial struct {
	TypeID           int
	Name             string
	AdjustedQuantity int
	Quantity         int
}

// ChainStep is one resolved step with its children.
type ChainStep struct {
	TypeID           int             `json:"type_id"`
	Name             string          `json:"name"`
	QuantityRequired int             `json:"quantity_required"`
	RunsNeeded       int             `json:"runs_needed"`
	PortionSize      int             `json:"portion_size"`
	ActivityType     string          `json:"activity_type"`
	BlueprintTypeID  int             `json:"blueprint_type_id"`
	Materials        []ChainMaterial `json:"materials"`
	Children         []ChainStep     `json:"children"`
}

// ChainResolver supplies SDE lookups for the chain walk.
type ChainResolver interface {
	ActivityProducing(ctx context.Context, typeID, activityID int) (*industry.BlueprintActivityRow, error)
	ActivityByBlueprint(ctx context.Context, blueprintID, activityID int) (*industry.BlueprintActivityRow, error)
	GroupsOf(ctx context.Context, typeIDs []int) (map[int]int, error)
}

func cint(m map[string]any, key string) int {
	v, _ := asInt(m[key])
	return v
}

// ResolveFullMaterialChain ports InventionPlannerService::resolveFullMaterialChain():
// materials become child steps via their manufacturing (else reaction)
// blueprint at ME 10 (reactions ME 0), recursing with cycle protection and
// skipping fuel blocks.
func ResolveFullMaterialChain(ctx context.Context, st ChainResolver, materials []ChainMaterial, meLevel int, structBonus float64, visited map[int]bool, reactionBonus *float64) ([]ChainStep, error) {
	effReaction := structBonus
	if reactionBonus != nil {
		effReaction = *reactionBonus
	}
	ids := make([]int, 0, len(materials))
	for _, m := range materials {
		if m.TypeID != 0 {
			ids = append(ids, m.TypeID)
		}
	}
	groups, err := st.GroupsOf(ctx, ids)
	if err != nil {
		return nil, err
	}
	out := []ChainStep{}
	for _, m := range materials {
		if m.TypeID == 0 || visited[m.TypeID] || groups[m.TypeID] == FuelBlockGroupID {
			continue
		}
		act, err := st.ActivityProducing(ctx, m.TypeID, industry.ActivityManufacturing)
		if err != nil {
			return nil, err
		}
		isReaction := false
		if act == nil {
			act, err = st.ActivityProducing(ctx, m.TypeID, industry.ActivityReaction)
			if err != nil {
				return nil, err
			}
			isReaction = act != nil
		}
		if act == nil {
			continue
		}
		portion := 1
		for _, p := range industry.DecodeJSONList(industry.StrVal(act.Products)) {
			if cint(p, "type_id") == m.TypeID {
				if q := cint(p, "quantity"); q > 0 {
					portion = q
				}
				break
			}
		}
		reqQty := m.AdjustedQuantity
		if reqQty == 0 {
			reqQty = m.Quantity
		}
		runsNeeded := reqQty
		if portion > 0 {
			runsNeeded = (reqQty + portion - 1) / portion
		}
		effME, effBonus := meLevel, structBonus
		if isReaction {
			effME, effBonus = 0, effReaction
		}
		subBase := make([]BaseMaterial, 0)
		for _, sm := range industry.DecodeJSONList(industry.StrVal(act.Materials)) {
			name, _ := sm["name"].(string)
			subBase = append(subBase, BaseMaterial{TypeID: cint(sm, "type_id"), Name: name, Quantity: cint(sm, "quantity")})
		}
		subMats := CalculateMaterials(subBase, effME, runsNeeded, effBonus)
		next := map[int]bool{}
		for k, v := range visited {
			next[k] = v
		}
		next[m.TypeID] = true
		subChain := make([]ChainMaterial, 0, len(subMats))
		for _, sm := range subMats {
			subChain = append(subChain, ChainMaterial{TypeID: sm.TypeID, Name: sm.Name, AdjustedQuantity: sm.AdjustedQuantity, Quantity: sm.BaseQuantity})
		}
		children, err := ResolveFullMaterialChain(ctx, st, subChain, meLevel, structBonus, next, reactionBonus)
		if err != nil {
			return nil, err
		}
		name := m.Name
		if name == "" {
			name = "Unknown"
		}
		activity := "manufacturing"
		if isReaction {
			activity = "reaction"
		}
		subOut := make([]ChainMaterial, 0, len(subMats))
		for _, sm := range subMats {
			subOut = append(subOut, ChainMaterial{TypeID: sm.TypeID, Name: sm.Name, AdjustedQuantity: sm.AdjustedQuantity, Quantity: sm.BaseQuantity})
		}
		out = append(out, ChainStep{
			TypeID: m.TypeID, Name: name, QuantityRequired: reqQty, RunsNeeded: runsNeeded,
			PortionSize: portion, ActivityType: activity, BlueprintTypeID: act.BlueprintTypeID,
			Materials: subOut, Children: children,
		})
	}
	return out, nil
}

// MergeSection is one merged-program section row.
type MergeSection struct {
	Name      string `json:"name"`
	StepCount int    `json:"step_count"`
}

// MergeResult mirrors mergeFields(): concatenated steps in id order,
// settings merged (later wins) plus sections, mixed types collapse to
// manufacturing.
type MergeResult struct {
	Steps       []any          `json:"steps"`
	Settings    map[string]any `json:"settings"`
	ProgramType string         `json:"program_type"`
}

// MergePrograms ports mergeFields() over generic program rows.
type MergeProgram struct {
	ID          int
	Name        string
	ProgramType string
	Steps       []any
	Settings    map[string]any
}

func MergePrograms(programs []MergeProgram, order []int) MergeResult {
	byID := map[int]MergeProgram{}
	for _, p := range programs {
		byID[p.ID] = p
	}
	programType := "manufacturing"
	if len(programs) > 0 {
		programType = programs[0].ProgramType
	}
	mixed := false
	var steps []any
	settings := map[string]any{}
	var sections []MergeSection
	for _, id := range order {
		p, ok := byID[id]
		if !ok {
			continue
		}
		sections = append(sections, MergeSection{Name: p.Name, StepCount: len(p.Steps)})
		steps = append(steps, p.Steps...)
		if p.ProgramType != programType {
			mixed = true
		}
		for k, v := range p.Settings {
			settings[k] = v
		}
	}
	if steps == nil {
		steps = []any{}
	}
	secOut := make([]any, 0, len(sections))
	for _, s := range sections {
		secOut = append(secOut, s)
	}
	settings["sections"] = secOut
	if mixed {
		programType = "manufacturing"
	}
	return MergeResult{Steps: steps, Settings: settings, ProgramType: programType}
}

// ReactionPlanStep is one top-level step built from a plan entry.
type ReactionPlanStep struct {
	Step      ChainStep
	ProductID int
	ProductName string
}

// ReactionPlanSteps ports reactionPlanSteps(): one top-level step per entry
// with intermediate reactions as children; nil steps when no entry matches
// a known formula. Also returns the first product as the program header.
func ReactionPlanSteps(ctx context.Context, st ChainResolver, entries []ReactionPlanEntry, rigBonus float64) ([]ChainStep, *int, *string, error) {
	var out []ChainStep
	var firstID *int
	var firstName *string
	for _, e := range entries {
		row, err := st.ActivityByBlueprint(ctx, e.FormulaTypeID, industry.ActivityReaction)
		if err != nil {
			return nil, nil, nil, err
		}
		if row == nil {
			continue
		}
		totalRuns := e.Slots * e.RunsPerSlot
		prods := industry.DecodeJSONList(industry.StrVal(row.Products))
		if len(prods) == 0 {
			continue
		}
		pid := cint(prods[0], "type_id")
		if pid == 0 {
			continue
		}
		pname, _ := prods[0]["name"].(string)
		if pname == "" {
			pname = e.FormulaName
		}
		if pname == "" {
			pname = "Unknown"
		}
		portion := cint(prods[0], "quantity")
		if portion <= 0 {
			portion = 1
		}
		if firstID == nil {
			firstID = &pid
			firstName = &pname
		}
		base := make([]BaseMaterial, 0)
		for _, m := range industry.DecodeJSONList(industry.StrVal(row.Materials)) {
			name, _ := m["name"].(string)
			base = append(base, BaseMaterial{TypeID: cint(m, "type_id"), Name: name, Quantity: cint(m, "quantity")})
		}
		adjusted := ReactionMaterials(base, totalRuns, rigBonus)
		subChain := make([]ChainMaterial, 0, len(adjusted))
		for _, m := range adjusted {
			subChain = append(subChain, ChainMaterial{TypeID: m.TypeID, Name: m.Name, AdjustedQuantity: m.AdjustedQuantity, Quantity: m.BaseQuantity})
		}
		children, err := ResolveFullMaterialChain(ctx, st, subChain, 10, 0, map[int]bool{}, &rigBonus)
		if err != nil {
			return nil, nil, nil, err
		}
		mats := make([]ChainMaterial, 0, len(adjusted))
		for _, m := range adjusted {
			mats = append(mats, ChainMaterial{TypeID: m.TypeID, Name: m.Name, AdjustedQuantity: m.AdjustedQuantity, Quantity: m.BaseQuantity})
		}
		out = append(out, ChainStep{
			TypeID: pid, Name: pname, QuantityRequired: totalRuns * portion, RunsNeeded: totalRuns,
			PortionSize: portion, ActivityType: "reaction", BlueprintTypeID: e.FormulaTypeID,
			Materials: mats, Children: children,
		})
	}
	if len(out) == 0 {
		return nil, nil, nil, nil
	}
	return out, firstID, firstName, nil
}

// ReactionPlanEntry is one planner entry for program creation.
type ReactionPlanEntry struct {
	FormulaTypeID int
	FormulaName   string
	Slots         int
	RunsPerSlot   int
}

