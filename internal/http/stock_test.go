package http

import (
	"context"
	"net/http"
	"testing"

	"github.com/sudo-adduser-jordan/eveindustry/internal/auth"
)

// stubStock implements StockReader in memory.
type stubStock struct {
	assets     map[int64][]map[string]any
	assetErr   map[int64]bool
	blueprints map[int64][]map[string]any
	jobs       map[int64][]map[string]any
}

func (s *stubStock) GetAssets(ctx context.Context, id int64) ([]map[string]any, error) {
	if s.assetErr[id] {
		return nil, errTest
	}
	return s.assets[id], nil
}
func (s *stubStock) GetBlueprints(ctx context.Context, id int64) ([]map[string]any, error) {
	return s.blueprints[id], nil
}
func (s *stubStock) GetIndustryJobs(ctx context.Context, id int64, completed bool) ([]map[string]any, error) {
	return s.jobs[id], nil
}

type testErr struct{}

func (testErr) Error() string { return "esi down" }

var errTest = testErr{}

func stockTestDeps() Deps {
	stub := testAuth()
	stub.chars = []auth.Character{
		{CharacterID: 11, Name: "Main", IsMain: true, IsEsiActive: true,
			Scopes: []string{"esi-assets.read_assets.v1", "esi-characters.read_blueprints.v1", "esi-industry.read_character_jobs.v1"}},
		{CharacterID: 22, Name: "Alt", IsEsiActive: true,
			Scopes: []string{"esi-assets.read_assets.v1"}},
		{CharacterID: 33, Name: "NoScope", IsEsiActive: true},
	}
	return Deps{Sessions: stub, Users: stub, Stock: StockDeps{Characters: &stubStock{
		assets: map[int64][]map[string]any{
			11: {{"type_id": 34, "quantity": 100}, {"type_id": 35, "quantity": 5}},
			22: {{"type_id": 34, "quantity": 7}},
		},
		assetErr: map[int64]bool{22: false},
		blueprints: map[int64][]map[string]any{
			11: {{"type_id": 999, "quantity": 3}},
		},
		jobs: map[int64][]map[string]any{
			11: {
				{"blueprint_type_id": 999, "status": "active", "activity_id": 9},
				{"blueprint_type_id": 999, "status": "delivered", "activity_id": 9},
				{"blueprint_type_id": 888, "status": "active", "activity_id": 1},
			},
		},
	}}}
}

func TestAssetStock(t *testing.T) {
	d := stockTestDeps()
	for _, path := range []string{
		"/api/v1/calculators/manufacturing/stock",
		"/api/v1/calculators/reaction-planner/stock",
	} {
		code, body := post(t, d, path, `{"type_ids": [34, 35, 99, 34]}`)
		if code != http.StatusOK {
			t.Fatalf("%s: code = %d %v", path, code, body)
		}
		rows := body["data"].([]any)
		if len(rows) != 3 {
			t.Fatalf("%s: rows = %v", path, rows)
		}
		// 100 + 7 = 107
		if rows[0].(map[string]any)["quantity"].(float64) != 107 {
			t.Fatalf("row0 = %v", rows[0])
		}
		if rows[2].(map[string]any)["quantity"].(float64) != 0 {
			t.Fatalf("row2 = %v", rows[2])
		}
	}
	if code, _ := post(t, d, "/api/v1/calculators/manufacturing/stock", `{"type_ids": []}`); code != http.StatusUnprocessableEntity {
		t.Fatalf("empty = %d", code)
	}
}

func TestFormulaStock(t *testing.T) {
	d := stockTestDeps()
	code, body := post(t, d, "/api/v1/calculators/reaction-planner/formula-stock", `{"formula_type_ids": [999, 1000]}`)
	if code != http.StatusOK {
		t.Fatalf("code = %d %v", code, body)
	}
	data := body["data"].(map[string]any)
	if data["has_blueprint_scope"] != true {
		t.Fatalf("scope = %v", data)
	}
	formulas := data["formulas"].([]any)
	// blueprints 3 + 1 active reaction job = 4
	if len(formulas) != 2 || formulas[0].(map[string]any)["quantity"].(float64) != 4 {
		t.Fatalf("formulas = %v", formulas)
	}
}
