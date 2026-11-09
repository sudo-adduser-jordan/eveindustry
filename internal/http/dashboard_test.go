package http

import (
	"net/http"
	"testing"
	"time"

	"github.com/sudo-adduser-jordan/eveindustry/internal/auth"
	"github.com/sudo-adduser-jordan/eveindustry/internal/store"
)

func dashboardTestDeps() Deps {
	stub := testAuth()
	stub.chars = []auth.Character{{
		CharacterID: 11, Name: "Main", IsMain: true, IsEsiActive: true,
		Scopes: []string{"esi-industry.read_character_jobs.v1", "esi-skills.read_skills.v1"},
	}}
	now := time.Date(2026, 11, 8, 12, 0, 0, 0, time.UTC)
	d := Deps{Sessions: stub, Users: stub,
		IndustryDeps: IndustryDeps{
			Characters: &stubAvailChars{
				skills: map[int64]map[int]int{11: {3387: 1}},
				jobs: map[int64][]map[string]any{11: {{
					"job_id": 1, "activity_id": 1, "status": "active", "runs": 2,
					"start_date": "2026-11-08T10:00:00Z", "end_date": "2026-11-08T12:00:00Z",
					"blueprint_type_id": 786, "product_type_id": 587, "station_id": 60003760,
				}}},
			},
			Universe: stubUniverse{},
		},
		Market:   &stubMarket{},
		Industry: &stubIndustry{},
		Corp:     &stubCorp{},
		Plans: &stubPlans{
			reac: map[int64]store.ReactionPlan{1: {ID: 1, UserID: 1, Name: "R"}},
			inv:  map[int64]store.InventionPlan{2: {ID: 2, UserID: 1, Name: "I"}},
			now:  now,
		},
		Planetary: PlanetaryDeps{
			Characters: &stubPlanetaryChars{planets: map[int64][]map[string]any{}},
			Universe:   &stubPlanetaryUniverse{},
		},
	}
	return d
}

func TestDashboard(t *testing.T) {
	d := dashboardTestDeps()
	code, body := get(t, d, "/api/v1/dashboard")
	if code != http.StatusOK {
		t.Fatalf("dashboard = %d %v", code, body)
	}
	data := body["data"].(map[string]any)
	ind := data["industry"].(map[string]any)
	if ind["in_progress"] != 1.0 {
		t.Fatalf("industry = %v", ind)
	}
	pi := data["planetary"].(map[string]any)
	if pi["expired_extractors"] != 0.0 || pi["full_storage"] != 0.0 {
		t.Fatalf("planetary = %v", pi)
	}
	if len(data["characters"].([]any)) != 1 {
		t.Fatalf("characters = %v", data["characters"])
	}
	if len(data["reaction_plans"].([]any)) != 1 || len(data["invention_plans"].([]any)) != 1 {
		t.Fatalf("plans = %v %v", data["reaction_plans"], data["invention_plans"])
	}
}
