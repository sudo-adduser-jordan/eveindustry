package http

import (
	"context"
	"net/http"
	"testing"

	"github.com/sudo-adduser-jordan/eveindustry/internal/auth"
	"github.com/sudo-adduser-jordan/eveindustry/internal/store"
)

// stubResearch implements store.ResearchStore.
type stubResearch struct {
	blueprints []store.ResearchBlueprint
	formulas   []store.ResearchFormula
	groups     []store.GroupName
}

func (s *stubResearch) T1Blueprints(ctx context.Context, search string, group int) ([]store.ResearchBlueprint, error) {
	return s.blueprints, nil
}
func (s *stubResearch) ReactionFormulas(ctx context.Context, search string, group int) ([]store.ResearchFormula, error) {
	return s.formulas, nil
}
func (s *stubResearch) BlueprintMarketGroups(ctx context.Context) ([]store.GroupName, error) {
	return s.groups, nil
}

type stubResearchChars struct {
	blueprints map[int64][]map[string]any
	jobs       map[int64][]map[string]any
}

func (s *stubResearchChars) GetBlueprints(ctx context.Context, id int64) ([]map[string]any, error) {
	return s.blueprints[id], nil
}
func (s *stubResearchChars) GetIndustryJobs(ctx context.Context, id int64, inc bool) ([]map[string]any, error) {
	return s.jobs[id], nil
}

func researchTestDeps() Deps {
	stub := testAuth()
	stub.chars = []auth.Character{
		{CharacterID: 11, Name: "Main", IsMain: true, IsEsiActive: true,
			Scopes: []string{"esi-characters.read_blueprints.v1", "esi-industry.read_character_jobs.v1"}},
		{CharacterID: 22, Name: "Alt", IsEsiActive: true},
	}
	mg := "Ships"
	gn := "Composite Group"
	d := Deps{Sessions: stub, Users: stub, Research: ResearchDeps{
		Characters: &stubResearchChars{
			blueprints: map[int64][]map[string]any{
				11: {{"type_id": 786, "material_efficiency": 10, "time_efficiency": 8}},
			},
			jobs: map[int64][]map[string]any{
				11: {{"blueprint_type_id": 999, "status": "active", "activity_id": 9}},
			},
		},
		Research: &stubResearch{
			blueprints: []store.ResearchBlueprint{
				{TypeID: 786, Name: "Rifter Blueprint", MarketGroupID: intPtr2(2), MarketGroupName: &mg},
				{TypeID: 787, Name: "Other Blueprint"},
			},
			formulas: []store.ResearchFormula{
				{TypeID: 999, Name: "Test Formula", GroupID: intPtr2(1888), GroupName: &gn},
				{TypeID: 1000, Name: "Other Formula"},
			},
			groups: []store.GroupName{{ID: 2, Name: "Ships"}},
		},
	}}
	return d
}

func intPtr2(i int) *int { return &i }

func TestBlueprintResearch(t *testing.T) {
	d := researchTestDeps()
	code, body := get(t, d, "/api/v1/industry/blueprint-research?sort=owned")
	if code != http.StatusOK {
		t.Fatalf("code = %d %v", code, body)
	}
	rows := body["data"].([]any)
	if len(rows) != 2 {
		t.Fatalf("rows = %v", rows)
	}
	// owned first
	if rows[0].(map[string]any)["type_id"].(float64) != 786 {
		t.Fatalf("order = %v", rows)
	}
	if rows[0].(map[string]any)["me"].(float64) != 10 {
		t.Fatalf("me = %v", rows[0])
	}
	meta := body["meta"].(map[string]any)
	if meta["has_blueprint_data"] != true || len(meta["characters_without_scope"].([]any)) != 1 {
		t.Fatalf("meta = %v", meta)
	}
}

func TestFormulaResearch(t *testing.T) {
	d := researchTestDeps()
	code, body := get(t, d, "/api/v1/industry/reaction-formula-research")
	if code != http.StatusOK {
		t.Fatalf("code = %d %v", code, body)
	}
	rows := body["data"].([]any)
	if len(rows) != 2 || rows[0].(map[string]any)["quantity"].(float64) != 1 {
		t.Fatalf("rows = %v", rows)
	}
	if rows[0].(map[string]any)["category"] != "Composite" || rows[1].(map[string]any)["category"] != "Other" {
		t.Fatalf("cats = %v", rows)
	}
}
