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
	joined := time.Date(2026, 10, 1, 9, 0, 0, 0, time.UTC)
	login := time.Date(2026, 11, 8, 11, 0, 0, 0, time.UTC)
	stub.user.CreatedAt, stub.user.LastLoginAt = &joined, &login
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
	if _, ok := ind["ready_jobs"]; !ok {
		t.Fatalf("industry missing ready_jobs (DashboardResource contract): %v", ind)
	}
	if _, bad := ind["ready"]; bad {
		t.Fatalf("industry still uses the legacy ready key: %v", ind)
	}
	pi := data["planetary"].(map[string]any)
	if pi["expired_extractors"] != 0.0 || pi["full_storage"] != 0.0 {
		t.Fatalf("planetary = %v", pi)
	}
	if _, ok := pi["total_planets"]; !ok {
		t.Fatalf("planetary missing total_planets (DashboardResource contract): %v", pi)
	}
	acct := data["account"].(map[string]any)
	if acct["joined_at"] != "2026-10-01T09:00:00Z" || acct["last_login_at"] != "2026-11-08T11:00:00Z" {
		t.Fatalf("account = %v", acct)
	}
	chars := data["characters"].([]any)
	if len(chars) != 1 {
		t.Fatalf("characters = %v", data["characters"])
	}
	ch := chars[0].(map[string]any)
	for _, k := range []string{"portrait_url", "corporation_name", "corporation_logo",
		"alliance_name", "alliance_logo", "location", "training", "is_main", "is_esi_active"} {
		if _, ok := ch[k]; !ok {
			t.Fatalf("character missing %s (DashboardCharacterResource contract): %v", k, ch)
		}
	}
	if ch["portrait_url"] != "https://images.evetech.net/characters/11/portrait?size=128" {
		t.Fatalf("portrait_url = %v", ch["portrait_url"])
	}
	rp := data["reaction_plans"].([]any)
	ipv := data["invention_plans"].([]any)
	if len(rp) != 1 || len(ipv) != 1 {
		t.Fatalf("plans = %v %v", data["reaction_plans"], data["invention_plans"])
	}
	for _, p := range append(rp, ipv...) {
		pm := p.(map[string]any)
		if _, ok := pm["t2_type_name"]; !ok {
			t.Fatalf("plan missing t2_type_name (DashboardPlanResource contract): %v", pm)
		}
		if _, ok := pm["updated_at"]; !ok {
			t.Fatalf("plan missing updated_at (DashboardPlanResource contract): %v", pm)
		}
	}
}
