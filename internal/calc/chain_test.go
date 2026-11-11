package calc

import (
	"context"
	"database/sql"
	"testing"

	"github.com/sudo-adduser-jordan/eveindustry/internal/industry"
)

func sqlNull(s string) sql.NullString { return sql.NullString{String: s, Valid: true} }

type chainTestStore struct {
	byBP   map[[2]int]*industry.BlueprintActivityRow
	groups map[int]int
}

func (s *chainTestStore) ActivityProducing(ctx context.Context, typeID, act int) (*industry.BlueprintActivityRow, error) {
	for _, a := range s.byBP {
		if a.ActivityID != act {
			continue
		}
		for _, p := range industry.DecodeJSONList(industry.StrVal(a.Products)) {
			if id, _ := asInt(p["type_id"]); id == typeID {
				return a, nil
			}
		}
	}
	return nil, nil
}

func (s *chainTestStore) ActivityByBlueprint(ctx context.Context, bp, act int) (*industry.BlueprintActivityRow, error) {
	return s.byBP[[2]int{bp, act}], nil
}

func (s *chainTestStore) GroupsOf(ctx context.Context, ids []int) (map[int]int, error) {
	out := map[int]int{}
	for _, id := range ids {
		if g, ok := s.groups[id]; ok {
			out[id] = g
		}
	}
	return out, nil
}

func chainFixture() *chainTestStore {
	mk := func(bp, act int, mats, prods string) *industry.BlueprintActivityRow {
		return &industry.BlueprintActivityRow{BlueprintTypeID: bp, ActivityID: act, Time: 10,
			Materials: sqlNull(mats), Products: sqlNull(prods)}
	}
	return &chainTestStore{
		byBP: map[[2]int]*industry.BlueprintActivityRow{
			{786, 1}: mk(786, 1,
				`[{"type_id":588,"name":"T1","quantity":1},{"type_id":34,"name":"Trit","quantity":1000}]`,
				`[{"type_id":587,"name":"Rifter","quantity":1}]`),
			{787, 1}: mk(787, 1,
				`[{"type_id":34,"name":"Trit","quantity":100}]`,
				`[{"type_id":588,"name":"T1","quantity":1}]`),
			{999, 11}: mk(999, 11,
				`[{"type_id":34,"name":"Trit","quantity":50}]`,
				`[{"type_id":587,"name":"Comp","quantity":5}]`),
		},
		groups: map[int]int{34: 1},
	}
}

func TestResolveFullMaterialChain(t *testing.T) {
	steps, err := ResolveFullMaterialChain(context.Background(), chainFixture(),
		[]ChainMaterial{{TypeID: 587, Name: "Rifter", Quantity: 3}}, 10, 0, map[int]bool{}, nil)
	if err != nil || len(steps) != 1 {
		t.Fatalf("got %+v %v", steps, err)
	}
	if len(steps[0].Children) != 1 || steps[0].Children[0].TypeID != 588 {
		t.Fatalf("children: %+v", steps[0].Children)
	}
	t1 := steps[0].Children[0]
	if len(t1.Materials) != 1 || t1.Materials[0].AdjustedQuantity != 270 || len(t1.Children) != 0 {
		t.Fatalf("t1: %+v", t1)
	}
	fuel := chainFixture()
	fuel.groups[588] = 1136
	steps, err = ResolveFullMaterialChain(context.Background(), fuel,
		[]ChainMaterial{{TypeID: 587, Name: "R", Quantity: 1}}, 10, 0, map[int]bool{}, nil)
	if err != nil || len(steps[0].Children) != 0 {
		t.Fatalf("fuel skip: %+v %v", steps, err)
	}
}

func TestReactionPlanSteps(t *testing.T) {
	steps, firstID, firstName, err := ReactionPlanSteps(context.Background(), chainFixture(),
		[]ReactionPlanEntry{{FormulaTypeID: 999, FormulaName: "F", Slots: 1, RunsPerSlot: 5}}, 0)
	if err != nil || len(steps) != 1 {
		t.Fatalf("got %+v %v", steps, err)
	}
	if *firstID != 587 || *firstName != "Comp" || steps[0].RunsNeeded != 5 {
		t.Fatalf("header: %v %v %+v", *firstID, *firstName, steps[0])
	}
	steps, _, _, err = ReactionPlanSteps(context.Background(), chainFixture(),
		[]ReactionPlanEntry{{FormulaTypeID: 4242, Slots: 1, RunsPerSlot: 5}}, 0)
	if err != nil || steps != nil {
		t.Fatalf("unknown: %+v %v", steps, err)
	}
}

func TestMergePrograms(t *testing.T) {
	got := MergePrograms([]MergeProgram{
		{ID: 1, Name: "A", ProgramType: "manufacturing", Steps: []any{1}, Settings: map[string]any{"x": 1}},
		{ID: 2, Name: "B", ProgramType: "manufacturing", Steps: []any{2, 3}, Settings: map[string]any{"y": 2}},
	}, []int{2, 1, 99})
	if len(got.Steps) != 3 {
		t.Fatalf("got %+v", got)
	}
	if secs, ok := got.Settings["sections"].([]any); !ok || len(secs) != 2 {
		t.Fatalf("sections: %+v", got.Settings)
	}
	if got.Settings["x"] != 1 || got.Settings["y"] != 2 {
		t.Fatalf("settings: %+v", got.Settings)
	}
	if got.ProgramType != "manufacturing" {
		t.Fatalf("type: %v", got)
	}
	mixed := MergePrograms([]MergeProgram{
		{ID: 1, Name: "A", ProgramType: "reaction"},
		{ID: 2, Name: "B", ProgramType: "manufacturing"},
	}, []int{1, 2})
	if mixed.ProgramType != "manufacturing" {
		t.Fatalf("mixed: %+v", mixed)
	}
}
