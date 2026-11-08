package http

import (
	"context"
	"database/sql"
	"net/http"
	"testing"

	"github.com/sudo-adduser-jordan/eveindustry/internal/industry"
)

// stubIndustry implements industry.IndustryStore for handler tests.
type stubIndustry struct {
	hits             []industry.BlueprintTypeHit
	t2cands          []industry.BlueprintTypeHit
	activities       map[[2]int]*industry.BlueprintActivityRow
	cats             []industry.CategoryCount
	mfgActs          []industry.MfgActivity
	precomputed      []industry.PrecomputedCost
	precomputedTotal int
}

func (s *stubIndustry) SearchBlueprintTypes(ctx context.Context, like string, limit int) ([]industry.BlueprintTypeHit, error) {
	return s.hits, nil
}
func (s *stubIndustry) SearchReactionTypes(ctx context.Context, like string, limit int) ([]industry.BlueprintTypeHit, error) {
	return s.hits, nil
}
func (s *stubIndustry) SearchT2Candidates(ctx context.Context, like string) ([]industry.BlueprintTypeHit, error) {
	return s.t2cands, nil
}
func (s *stubIndustry) ActivityByBlueprint(ctx context.Context, bp, act int) (*industry.BlueprintActivityRow, error) {
	return s.activities[[2]int{bp, act}], nil
}
func (s *stubIndustry) ActivityProducingType(ctx context.Context, typeID, act int) (*industry.BlueprintActivityRow, error) {
	for _, a := range s.activities {
		if a.ActivityID != act {
			continue
		}
		for _, p := range industry.DecodeJSONList(nullStrPtr(a.Products)) {
			if id, ok := p["type_id"].(float64); ok && int(id) == typeID {
				return a, nil
			}
		}
	}
	return nil, nil
}
func (s *stubIndustry) HasActivity(ctx context.Context, bp, act int) (bool, error) {
	_, ok := s.activities[[2]int{bp, act}]
	return ok, nil
}
func (s *stubIndustry) TypeNames(ctx context.Context, ids []int) (map[int]string, error) {
	return map[int]string{}, nil
}
func (s *stubIndustry) ProductTypeIDsExcludingInvented(ctx context.Context) ([]int, error) {
	return []int{587}, nil
}
func (s *stubIndustry) ProductGroupCounts(ctx context.Context, ids []int) (map[int]int, error) {
	return map[int]int{25: 2}, nil
}
func (s *stubIndustry) GroupCategories(ctx context.Context, ids []int) (map[int]int, error) {
	return map[int]int{25: 6}, nil
}
func (s *stubIndustry) CategoryNames(ctx context.Context, ids []int) (map[int]string, error) {
	return map[int]string{6: "Ships"}, nil
}
func (s *stubIndustry) AllReactionFormulas(ctx context.Context) ([]industry.ReactionFormula, error) {
	return []industry.ReactionFormula{{
		BlueprintTypeID: 999, FormulaName: "Test Formula",
		Materials: []map[string]any{{"type_id": 34, "name": "Tritanium", "quantity": 50}},
		Products:  []map[string]any{{"type_id": 587, "name": "Composite", "quantity": 5}},
		Time:      600,
	}}, nil
}
func (s *stubIndustry) AllManufacturingActivities(ctx context.Context) ([]industry.MfgActivity, error) {
	return s.mfgActs, nil
}
func (s *stubIndustry) InventedBlueprintIDs(ctx context.Context) (map[int]bool, error) {
	return map[int]bool{}, nil
}
func (s *stubIndustry) MarketGroupDescendants(ctx context.Context, parentID int) ([]int, error) {
	return nil, nil
}
func (s *stubIndustry) PublishedTypesInGroups(ctx context.Context, groupIDs []int) ([]int, error) {
	return []int{587}, nil
}
func (s *stubIndustry) TypeGroupMeta(ctx context.Context, typeIDs []int) ([]industry.TypeGroupMeta, error) {
	return nil, nil
}
func (s *stubIndustry) GroupsInCategory(ctx context.Context, categoryID int) ([]int, error) {
	return nil, nil
}
func (s *stubIndustry) PrecomputedCosts(ctx context.Context, productIDs []int, regionID, meLevel, limit, offset int) ([]industry.PrecomputedCost, int, error) {
	return s.precomputed, s.precomputedTotal, nil
}

func industryTestDeps() Deps {
	return Deps{Industry: &stubIndustry{
		hits:    []industry.BlueprintTypeHit{{TypeID: 786, Name: "Rifter Blueprint"}},
		t2cands: []industry.BlueprintTypeHit{{TypeID: 587, Name: "Rifter"}},
		activities: map[[2]int]*industry.BlueprintActivityRow{
			{786, industry.ActivityManufacturing}: {BlueprintTypeID: 786, ActivityID: 1, Time: 100,
				Materials: sql.NullString{String: `[{"type_id":34,"name":"Tritanium","quantity":100}]`, Valid: true},
				Products:  sql.NullString{String: `[{"type_id":587,"name":"Rifter","quantity":1}]`, Valid: true}},
			{787, industry.ActivityManufacturing}: {BlueprintTypeID: 787, ActivityID: 1, Time: 80,
				Materials: sql.NullString{String: `[{"type_id":34,"name":"Tritanium","quantity":50}]`, Valid: true},
				Products:  sql.NullString{String: `[{"type_id":588,"name":"T1 Frigate","quantity":1}]`, Valid: true}},
			{787, industry.ActivityInvention}: {BlueprintTypeID: 787, ActivityID: 8, Time: 3600,
				Materials: sql.NullString{String: `[{"type_id":20171,"name":"Datacore","quantity":2}]`, Valid: true},
				Products:  sql.NullString{String: `[{"type_id":786,"probability":0.3,"quantity":4}]`, Valid: true}},
		},
	}}
}

func TestBlueprintSearchAndShow(t *testing.T) {
	d := industryTestDeps()
	if code, _ := get(t, d, "/api/v1/blueprints"); code != http.StatusUnprocessableEntity {
		t.Fatalf("missing q = %d", code)
	}
	code, body := get(t, d, "/api/v1/blueprints?q=rifter")
	if code != http.StatusOK {
		t.Fatalf("search = %d %v", code, body)
	}
	code, body = get(t, d, "/api/v1/blueprints/786")
	if code != http.StatusOK || body["data"].(map[string]any)["time"].(float64) != 100 {
		t.Fatalf("show = %d %v", code, body)
	}
	if code, _ := get(t, d, "/api/v1/blueprints/1"); code != http.StatusNotFound {
		t.Fatalf("missing bp = %d", code)
	}
}

func TestInventionSearchShowAndCategories(t *testing.T) {
	d := industryTestDeps()
	code, body := get(t, d, "/api/v1/invention/items?q=rifter")
	if code != http.StatusOK || len(body["data"].([]any)) != 1 {
		t.Fatalf("invention search = %d %v", code, body)
	}
	code, body = get(t, d, "/api/v1/invention/items/587")
	if code != http.StatusOK {
		t.Fatalf("chain = %d %v", code, body)
	}
	if body["data"].(map[string]any)["t1_item_type_id"].(float64) != 588 {
		t.Fatalf("chain T1: %v", body["data"])
	}
	code, body = get(t, d, "/api/v1/manufacturing/categories")
	if code != http.StatusOK || len(body["data"].([]any)) != 1 {
		t.Fatalf("categories = %d %v", code, body)
	}
	code, body = get(t, d, "/api/v1/corporations?q=abc")
	if code != http.StatusOK || len(body["data"].([]any)) != 0 {
		t.Fatalf("corporations = %d %v", code, body)
	}
}
