package industry

import (
	"context"
	"database/sql"
	"testing"
)

func TestEscapeLike(t *testing.T) {
	if got := EscapeLike("100%_x\\y"); got != `100\%\_x\\y` {
		t.Fatalf("got %q", got)
	}
}

func TestDedupeMaterials(t *testing.T) {
	raw := []map[string]any{
		{"type_id": 34, "name": "Tritanium", "quantity": 100},
		{"typeid": 34, "name": "Tritanium", "quantity": 50},
		{"type_id": 0, "name": "junk", "quantity": 1},
		{"name": "no id", "quantity": 1},
		{"type_id": 35, "quantity": 5},
	}
	got := DedupeMaterials(raw)
	if len(got) != 2 || got[0].Quantity != 100 || got[1].TypeID != 35 {
		t.Fatalf("got %+v", got)
	}
	if *got[1].Name != "Unknown" {
		t.Fatalf("missing name defaults to Unknown: %+v", got[1])
	}
}

type stubStore struct {
	activities map[string]*BlueprintActivityRow
}

func (s *stubStore) SearchBlueprintTypes(ctx context.Context, like string, limit int) ([]BlueprintTypeHit, error) {
	return []BlueprintTypeHit{{TypeID: 786, Name: "Rifter Blueprint", GroupID: sql.NullInt64{Int64: 105, Valid: true}}}, nil
}
func (s *stubStore) SearchReactionTypes(ctx context.Context, like string, limit int) ([]BlueprintTypeHit, error) {
	return []BlueprintTypeHit{{TypeID: 999, Name: "Test Reaction Formula"}}, nil
}
func (s *stubStore) SearchT2Candidates(ctx context.Context, like string) ([]BlueprintTypeHit, error) {
	return []BlueprintTypeHit{{TypeID: 587, Name: "Rifter"}}, nil
}
func (s *stubStore) ActivityByBlueprint(ctx context.Context, bp, act int) (*BlueprintActivityRow, error) {
	return s.activities[key(bp, act)], nil
}
func (s *stubStore) ActivityProducingType(ctx context.Context, typeID, act int) (*BlueprintActivityRow, error) {
	for _, a := range s.activities {
		if a.ActivityID != act {
			continue
		}
		for _, p := range DecodeJSONList(nullString(a.Products)) {
			if intVal(p["type_id"], 0) == typeID {
				return a, nil
			}
		}
	}
	return nil, nil
}
func (s *stubStore) HasActivity(ctx context.Context, bp, act int) (bool, error) {
	_, ok := s.activities[key(bp, act)]
	return ok, nil
}
func (s *stubStore) TypeNames(ctx context.Context, ids []int) (map[int]string, error) {
	return map[int]string{}, nil
}
func (s *stubStore) TypePortions(ctx context.Context, ids []int) (map[int]int, error) {
	return map[int]int{}, nil
}
func (s *stubStore) TypeMaterials(ctx context.Context, ids []int) (map[int][]ReprocessMaterial, error) {
	return map[int][]ReprocessMaterial{}, nil
}
func (s *stubStore) TypeVolumes(ctx context.Context, ids []int) (map[int]float64, error) {
	return map[int]float64{}, nil
}
func (s *stubStore) ProductTypeIDsExcludingInvented(ctx context.Context) ([]int, error) {
	return []int{587}, nil
}
func (s *stubStore) ProductGroupCounts(ctx context.Context, ids []int) (map[int]int, error) {
	return map[int]int{25: 3}, nil
}
func (s *stubStore) GroupCategories(ctx context.Context, ids []int) (map[int]int, error) {
	return map[int]int{25: 6}, nil
}
func (s *stubStore) CategoryNames(ctx context.Context, ids []int) (map[int]string, error) {
	return map[int]string{6: "Ships"}, nil
}
func (s *stubStore) AllReactionFormulas(ctx context.Context) ([]ReactionFormula, error) {
	return nil, nil
}
func (s *stubStore) ActivityProducing(ctx context.Context, typeID, activityID int) (*BlueprintActivityRow, error) {
	return s.ActivityProducingType(ctx, typeID, activityID)
}
func (s *stubStore) GroupsOf(ctx context.Context, typeIDs []int) (map[int]int, error) {
	return map[int]int{}, nil
}
func (s *stubStore) AllManufacturingActivities(ctx context.Context) ([]MfgActivity, error) {
	return nil, nil
}
func (s *stubStore) InventedBlueprintIDs(ctx context.Context) (map[int]bool, error) {
	return nil, nil
}
func (s *stubStore) MarketGroupDescendants(ctx context.Context, parentID int) ([]int, error) {
	return nil, nil
}
func (s *stubStore) PublishedTypesInGroups(ctx context.Context, groupIDs []int) ([]int, error) {
	return nil, nil
}
func (s *stubStore) TypeGroupMeta(ctx context.Context, typeIDs []int) ([]TypeGroupMeta, error) {
	return nil, nil
}
func (s *stubStore) DemoBlueprintPool(ctx context.Context) ([][2]int, error) {
	return nil, nil
}
func (s *stubStore) GroupNames(ctx context.Context, ids []int) (map[int]string, error) {
	return map[int]string{}, nil
}
func (s *stubStore) GroupsInCategory(ctx context.Context, categoryID int) ([]int, error) {
	return nil, nil
}
func (s *stubStore) PrecomputedCosts(ctx context.Context, productIDs []int, regionID, meLevel, limit, offset int) ([]PrecomputedCost, int, error) {
	return nil, 0, nil
}
func (s *stubStore) TopPrecomputedCosts(ctx context.Context, regionID, meLevel, limit, offset int, search string, onlyProfitable bool) ([]PrecomputedCost, int, error) {
	return nil, 0, nil
}

func key(bp, act int) string { return string(rune(bp)) + string(rune(act)) }

func chainFixture() *stubStore {
	return &stubStore{activities: map[string]*BlueprintActivityRow{
		key(786, ActivityManufacturing): {BlueprintTypeID: 786, ActivityID: 1, Time: 100,
			Materials: sql.NullString{String: `[{"type_id":588,"name":"T1 Frigate","quantity":1},{"type_id":34,"name":"Tritanium","quantity":5000}]`, Valid: true},
			Products:  sql.NullString{String: `[{"type_id":587,"name":"Rifter","quantity":1}]`, Valid: true}},
		key(787, ActivityManufacturing): {BlueprintTypeID: 787, ActivityID: 1, Time: 80,
			Materials: sql.NullString{String: `[{"type_id":34,"name":"Tritanium","quantity":3000}]`, Valid: true},
			Products:  sql.NullString{String: `[{"type_id":588,"name":"T1 Frigate","quantity":1}]`, Valid: true}},
		key(787, ActivityInvention): {BlueprintTypeID: 787, ActivityID: 8, Time: 3600,
			Materials: sql.NullString{String: `[{"type_id":20171,"name":"Datacore - Electronic Engineering","quantity":2}]`, Valid: true},
			Products:  sql.NullString{String: `[{"type_id":786,"probability":0.3,"quantity":4}]`, Valid: true},
			Skills:    sql.NullString{String: `[{"type_id":23087},{"type_id":11443}]`, Valid: true}},
	}}
}

func TestResolveChain(t *testing.T) {
	st := chainFixture()
	chain, err := ResolveChain(context.Background(), st, 587)
	if err != nil {
		t.Fatal(err)
	}
	if chain == nil {
		t.Fatal("expected chain")
	}
	if chain.T1ItemTypeID != 588 || chain.T1BlueprintTypeID != 787 {
		t.Fatalf("T1 link: %+v", chain)
	}
	if chain.BaseProbability != 0.3 || chain.BaseRuns != 4 {
		t.Fatalf("probability/runs: %+v", chain)
	}
	if chain.Datacores[0].SkillID == nil || *chain.Datacores[0].SkillID != 11443 {
		t.Fatalf("datacore skill: %+v", chain.Datacores)
	}
	if chain.EncryptionSkillID == nil || *chain.EncryptionSkillID != 23087 {
		t.Fatalf("encryption skill: %+v", chain)
	}
	if chain.Science1SkillID == nil || *chain.Science1SkillID != 11443 {
		t.Fatalf("science skill: %+v", chain)
	}
	if _, err := ResolveChain(context.Background(), st, 12345); err != nil {
		t.Fatal(err)
	} else if chain2, _ := ResolveChain(context.Background(), st, 12345); chain2 != nil {
		t.Fatalf("unknown item should be nil chain")
	}
}

func TestHasTechChain(t *testing.T) {
	st := chainFixture()
	t2mfg, _ := st.ActivityProducingType(context.Background(), 587, ActivityManufacturing)
	ok, err := HasTechChain(context.Background(), st, t2mfg)
	if err != nil || !ok {
		t.Fatalf("expected tech chain: %v %v", ok, err)
	}
}

func TestProductCategories(t *testing.T) {
	cats, err := ProductCategories(context.Background(), chainFixture())
	if err != nil {
		t.Fatal(err)
	}
	if len(cats) != 1 || cats[0].Name != "Ships" || cats[0].ItemCount != 3 {
		t.Fatalf("got %+v", cats)
	}
}
