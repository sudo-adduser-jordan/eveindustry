package calc

import (
	"context"
	"database/sql"
	"testing"

	"github.com/sudo-adduser-jordan/eveindustry/internal/industry"
)

func sql2(s string) sql.NullString { return sql.NullString{String: s, Valid: true} }

type chainStub struct {
	byBP map[[2]int]*industry.BlueprintActivityRow
}

func (s *chainStub) SearchBlueprintTypes(ctx context.Context, like string, limit int) ([]industry.BlueprintTypeHit, error) {
	return nil, nil
}
func (s *chainStub) SearchReactionTypes(ctx context.Context, like string, limit int) ([]industry.BlueprintTypeHit, error) {
	return nil, nil
}
func (s *chainStub) SearchT2Candidates(ctx context.Context, like string) ([]industry.BlueprintTypeHit, error) {
	return nil, nil
}
func (s *chainStub) ActivityByBlueprint(ctx context.Context, bp, act int) (*industry.BlueprintActivityRow, error) {
	return s.byBP[[2]int{bp, act}], nil
}
func (s *chainStub) ActivityProducingType(ctx context.Context, typeID, act int) (*industry.BlueprintActivityRow, error) {
	for _, a := range s.byBP {
		if a.ActivityID != act {
			continue
		}
		for _, p := range industry.DecodeJSONList(strPtr2(a.Products.String)) {
			if id, _ := numInt(p["type_id"]); id == typeID {
				return a, nil
			}
		}
	}
	return nil, nil
}
func (s *chainStub) HasActivity(ctx context.Context, bp, act int) (bool, error) {
	_, ok := s.byBP[[2]int{bp, act}]
	return ok, nil
}
func (s *chainStub) TypePortions(ctx context.Context, ids []int) (map[int]int, error) {
	return map[int]int{}, nil
}
func (s *chainStub) TypeMaterials(ctx context.Context, ids []int) (map[int][]industry.ReprocessMaterial, error) {
	return map[int][]industry.ReprocessMaterial{}, nil
}
func (s *chainStub) TypeVolumes(ctx context.Context, ids []int) (map[int]float64, error) {
	return map[int]float64{}, nil
}
func (s *chainStub) TypeNames(ctx context.Context, ids []int) (map[int]string, error) {
	return map[int]string{}, nil
}
func (s *chainStub) ProductTypeIDsExcludingInvented(ctx context.Context) ([]int, error) {
	return nil, nil
}
func (s *chainStub) ProductGroupCounts(ctx context.Context, ids []int) (map[int]int, error) {
	return nil, nil
}
func (s *chainStub) GroupCategories(ctx context.Context, ids []int) (map[int]int, error) {
	return nil, nil
}
func (s *chainStub) CategoryNames(ctx context.Context, ids []int) (map[int]string, error) {
	return nil, nil
}
func (s *chainStub) AllReactionFormulas(ctx context.Context) ([]industry.ReactionFormula, error) {
	return nil, nil
}
func (s *chainStub) ActivityProducing(ctx context.Context, typeID, activityID int) (*industry.BlueprintActivityRow, error) {
	return s.ActivityProducingType(ctx, typeID, activityID)
}
func (s *chainStub) GroupsOf(ctx context.Context, typeIDs []int) (map[int]int, error) {
	return map[int]int{}, nil
}
func (s *chainStub) AllManufacturingActivities(ctx context.Context) ([]industry.MfgActivity, error) {
	return nil, nil
}
func (s *chainStub) InventedBlueprintIDs(ctx context.Context) (map[int]bool, error) {
	return nil, nil
}
func (s *chainStub) MarketGroupDescendants(ctx context.Context, parentID int) ([]int, error) {
	return nil, nil
}
func (s *chainStub) PublishedTypesInGroups(ctx context.Context, groupIDs []int) ([]int, error) {
	return nil, nil
}
func (s *chainStub) TypeGroupMeta(ctx context.Context, typeIDs []int) ([]industry.TypeGroupMeta, error) {
	return nil, nil
}
func (s *chainStub) DemoBlueprintPool(ctx context.Context) ([][2]int, error) {
	return nil, nil
}
func (s *chainStub) GroupNames(ctx context.Context, ids []int) (map[int]string, error) {
	return map[int]string{}, nil
}
func (s *chainStub) GroupsInCategory(ctx context.Context, categoryID int) ([]int, error) {
	return nil, nil
}
func (s *chainStub) PrecomputedCosts(ctx context.Context, productIDs []int, regionID, meLevel, limit, offset int) ([]industry.PrecomputedCost, int, error) {
	return nil, 0, nil
}
func (s *chainStub) TopPrecomputedCosts(ctx context.Context, regionID, meLevel, limit, offset int, search string, onlyProfitable bool) ([]industry.PrecomputedCost, int, error) {
	return nil, 0, nil
}

func strPtr2(s string) *string { return &s }

func TestResolveReactionChain(t *testing.T) {
	mk := func(bp, act int, mats, prods string) *industry.BlueprintActivityRow {
		return &industry.BlueprintActivityRow{BlueprintTypeID: bp, ActivityID: act,
			Materials: sql2(mats), Products: sql2(prods)}
	}
	st := &chainStub{byBP: map[[2]int]*industry.BlueprintActivityRow{
		{999, 11}: mk(999, 11,
			`[{"type_id":100,"name":"Intermediate","quantity":10},{"type_id":34,"name":"Goo","quantity":5}]`,
			`[{"type_id":587,"name":"Composite","quantity":5}]`),
		{998, 11}: mk(998, 11,
			`[{"type_id":35,"name":"Raw Goo","quantity":20}]`,
			`[{"type_id":100,"name":"Intermediate","quantity":2}]`),
	}}
	chain, err := ResolveReactionChain(context.Background(), st,
		[]PlanEntry{{FormulaTypeID: 999, Slots: 1, RunsPerSlot: 5}}, 0)
	if err != nil {
		t.Fatal(err)
	}
	if len(chain.CompositeOutputs) != 1 || chain.CompositeOutputs[0].TotalOutput != 25 {
		t.Fatalf("outputs: %+v", chain.CompositeOutputs)
	}
	if len(chain.RawMaterials) != 2 {
		t.Fatalf("raw: %+v", chain.RawMaterials)
	}
	byID := map[int]int{}
	for _, r := range chain.RawMaterials {
		byID[r.TypeID] = r.Quantity
	}
	if byID[34] != 25 || byID[35] != 500 {
		t.Fatalf("raw qtys: %+v", byID)
	}
	if chain.TotalSlots != 6 || chain.TotalIntermediateSlots != 5 {
		t.Fatalf("slots: %+v", chain)
	}
	chain, err = ResolveReactionChain(context.Background(), st,
		[]PlanEntry{{FormulaTypeID: 1, Slots: 1, RunsPerSlot: 5}}, 0)
	if err != nil || len(chain.CompositeOutputs) != 0 {
		t.Fatalf("skip: %+v %v", chain, err)
	}
}

func TestJobCostAndRigBonus(t *testing.T) {
	fee, tax, total := JobCost(1000, 5, 10)
	if fee != 50 || tax != 5 || total != 55 {
		t.Fatalf("got %v %v %v", fee, tax, total)
	}
	if ResolveRigBonus("t2", "nullsec") != 2.64 {
		t.Fatalf("rig: %v", ResolveRigBonus("t2", "nullsec"))
	}
	if ResolveRigBonus("none", "lowsec") != 0 {
		t.Fatal("none rig must be 0")
	}
}

func TestCalculateSellScore(t *testing.T) {
	s := CalculateSellScore(SellScoreInput{Volumes: []uint64{100, 200}, OrderCounts: []uint64{10, 20}}, 25)
	if s.Score != 9 || s.AvgDailyVolume != 150 || s.TotalDays != 2 || s.ActiveDays != 2 {
		t.Fatalf("got %+v", s)
	}
	empty := CalculateSellScore(SellScoreInput{}, 10)
	if empty.Score != 0 || empty.TotalDays != 0 {
		t.Fatalf("empty: %+v", empty)
	}
}
