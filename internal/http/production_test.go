package http

import (
	"context"
	"net/http"
	"testing"

	"github.com/sudo-adduser-jordan/eveindustry/internal/auth"
)

// stubProduction implements ProductionESI.
type stubProduction struct {
	blueprints     map[int64][]map[string]any
	assets         map[int64][]map[string]any
	roles          map[int64][]string
	corpBlueprints map[int64][]map[string]any
	corpAssets     map[int64][]map[string]any
}

func (s *stubProduction) GetBlueprints(ctx context.Context, id int64) ([]map[string]any, error) {
	return s.blueprints[id], nil
}
func (s *stubProduction) GetAssets(ctx context.Context, id int64) ([]map[string]any, error) {
	return s.assets[id], nil
}
func (s *stubProduction) GetCharacterRoles(ctx context.Context, id int64) (map[string]any, error) {
	var out []any
	for _, r := range s.roles[id] {
		out = append(out, r)
	}
	return map[string]any{"roles": out}, nil
}
func (s *stubProduction) GetCorporationBlueprints(ctx context.Context, charID, corpID int64) ([]map[string]any, error) {
	return s.corpBlueprints[corpID], nil
}
func (s *stubProduction) GetCorporationAssets(ctx context.Context, charID, corpID int64) ([]map[string]any, error) {
	return s.corpAssets[corpID], nil
}

func productionTestDeps() Deps {
	stub := testAuth()
	stub.chars = []auth.Character{
		{CharacterID: 11, Name: "Main", IsMain: true, IsEsiActive: true,
			CorporationID: int64Ptr(987), CorporationName: strPtr2("ACME"),
			Scopes: []string{"esi-characters.read_blueprints.v1"}},
		{CharacterID: 22, Name: "Director", IsEsiActive: true,
			CorporationID: int64Ptr(988), CorporationName: strPtr2("CORP"),
			Scopes: []string{
				"esi-corporations.read_blueprints.v1",
				"esi-assets.read_corporation_assets.v1",
				"esi-characters.read_corporation_roles.v1",
			}},
	}
	d := calcTestDeps()
	d.Users = stub
	d.Sessions = stub
	d.Production.Characters = &stubProduction{
		blueprints: map[int64][]map[string]any{
			11: {{ "type_id": 786, "material_efficiency": 10, "runs": -1}},
		},
		assets: map[int64][]map[string]any{
			11: {{ "type_id": 34, "quantity": 950}},
		},
		roles: map[int64][]string{22: {"Director"}},
		corpBlueprints: map[int64][]map[string]any{
			988: {{ "type_id": 786, "material_efficiency": 0, "runs": 2}},
		},
		corpAssets: map[int64][]map[string]any{
			988: {{ "type_id": 34, "quantity": 1000}},
		},
	}
	ind := d.Industry.(*stubIndustry)
	ind.activities[[2]int{786, 1}] = industryActivityRow(786, 1, 100,
		`[{"type_id":34,"name":"Tritanium","quantity":100}]`,
		`[{"type_id":587,"name":"Rifter","quantity":1}]`)
	return d
}

func strPtr2(s string) *string { return &s }

func TestCanProduce(t *testing.T) {
	d := productionTestDeps()
	code, body := get(t, d, "/api/v1/industry/can-produce")
	if code != http.StatusOK {
		t.Fatalf("code = %d %v", code, body)
	}
	rows := body["data"].([]any)
	if len(rows) != 3 {
		t.Fatalf("want 2 char + 1 corp sources, got %d", len(rows))
	}
	char := rows[0].(map[string]any)
	if char["type"] != "character" || char["material_count"].(float64) != 1 {
		t.Fatalf("char = %v", char)
	}
	buildable := char["buildable"].([]any)
	if len(buildable) != 1 || buildable[0].(map[string]any)["buildable_runs"].(float64) != 10 {
		t.Fatalf("buildable = %v", buildable)
	}
	corp := rows[1].(map[string]any)
	if corp["type"] != "corporation" || corp["corporation_logo"] == nil {
		t.Fatalf("corp = %v", corp)
	}
	if corp["buildable"].([]any)[0].(map[string]any)["buildable_runs"].(float64) != 2 {
		t.Fatalf("corp buildable = %v", corp)
	}
}
