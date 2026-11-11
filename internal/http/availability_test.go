package http

import (
	"context"
	"database/sql"
	"net/http"
	"testing"
	"time"

	"github.com/sudo-adduser-jordan/eveindustry/internal/auth"
	"github.com/sudo-adduser-jordan/eveindustry/internal/industry"
	"github.com/sudo-adduser-jordan/eveindustry/internal/store"
)

type stubAvailChars struct {
	skills map[int64]map[int]int
	jobs   map[int64][]map[string]any
	assets map[int64][]map[string]any
}

func (s *stubAvailChars) GetIndustryJobs(ctx context.Context, id int64, inc bool) ([]map[string]any, error) {
	return s.jobs[id], nil
}
func (s *stubAvailChars) GetCorporationIndustryJobs(ctx context.Context, charID, corpID int64, inc bool) ([]map[string]any, error) {
	return nil, nil
}
func (s *stubAvailChars) GetSkills(ctx context.Context, id int64) (map[int]int, error) {
	return s.skills[id], nil
}
func (s *stubAvailChars) GetAssets(ctx context.Context, id int64) ([]map[string]any, error) {
	return s.assets[id], nil
}

func availTestDeps() Deps {
	stub := testAuth()
	stub.chars = []auth.Character{{
		CharacterID: 11, Name: "Main", IsMain: true, IsEsiActive: true,
		CorporationID: int64Ptr(987), CorporationName: strPtr("ACME"),
		Scopes: []string{"esi-industry.read_character_jobs.v1", "esi-skills.read_skills.v1"},
	}}
	d := Deps{Sessions: stub, Users: stub,
		IndustryDeps: IndustryDeps{
			Characters: &stubAvailChars{
				skills: map[int64]map[int]int{11: {
					3387: 5, 24625: 4, 3406: 3, 24624: 0, 45748: 0, 45749: 0,
					3385: 5, 3389: 5, 12196: 0,
				}},
				jobs: map[int64][]map[string]any{11: {{
					"job_id": 101, "activity_id": 1, "status": "active", "runs": 10,
					"start_date": "2026-11-08T11:00:00Z", "end_date": "2026-11-08T13:00:00Z",
					"blueprint_type_id": 786, "product_type_id": 587, "station_id": 60003760,
				}}},
				assets: map[int64][]map[string]any{11: {
					{"item_id": 1, "type_id": 587, "quantity": 100.0, "location_id": 60003760, "location_type": "station"},
				}},
			},
			Universe: stubUniverse{},
		},
		Market: &stubMarket{
			prices: []store.MarketPriceRowFull{
				{TypeID: 587, SellMin: sql.NullFloat64{Float64: 1000, Valid: true}},
				{TypeID: 34, SellMin: sql.NullFloat64{Float64: 10, Valid: true}},
			},
		},
		Industry: &stubIndustry{
			names:    map[int]string{34: "Tritanium", 587: "Rifter"},
			mats:     map[int][]industry.ReprocessMaterial{587: {{TypeID: 587, MaterialTypeID: 34, Quantity: 200}}},
			portions: map[int]int{587: 1},
		},
		Corp: &stubCorp{},
	}
	return d
}

func TestAvailability(t *testing.T) {
	d := availTestDeps()
	code, body := get(t, d, "/api/v1/industry/availability")
	if code != http.StatusOK {
		t.Fatalf("availability = %d %v", code, body)
	}
	data := body["data"].(map[string]any)
	chars := data["characters"].([]any)
	if len(chars) != 1 {
		t.Fatalf("characters = %v", data["characters"])
	}
	ch := chars[0].(map[string]any)
	mfg := ch["manufacturing"].(map[string]any)
	if mfg["total"] != 10.0 || mfg["active"] != 1.0 || mfg["available"] != 9.0 {
		t.Fatalf("mfg = %v", mfg)
	}
	job := mfg["jobs"].([]any)[0].(map[string]any)
	if job["item_name"] != "Rifter" || job["activity_name"] != "Manufacturing" {
		t.Fatalf("job = %v", job)
	}
	if job["progress_pct"] == nil || job["time_remaining"] == nil || job["seconds_remaining"] == nil {
		t.Fatalf("job progress missing: %v", job)
	}
	totals := data["totals"].(map[string]any)
	if totals["manufacturing"].(map[string]any)["total"] != 10.0 {
		t.Fatalf("totals = %v", totals)
	}
	if _, ok := body["meta"].(map[string]any)["cached_until"]; !ok {
		t.Fatalf("cached_until missing: %v", body["meta"])
	}
}

func TestReprocessing(t *testing.T) {
	d := availTestDeps()
	code, body := get(t, d, "/api/v1/industry/reprocessing")
	if code != http.StatusOK {
		t.Fatalf("reprocessing = %d %v", code, body)
	}
	data := body["data"].(map[string]any)
	chars := data["characters"].([]any)
	if len(chars) != 1 {
		t.Fatalf("characters = %v", data["characters"])
	}
	ch := chars[0].(map[string]any)
	if ch["yield_pct"] != 63.2 {
		t.Fatalf("yield = %v", ch["yield_pct"])
	}
	locs := ch["locations"].([]any)
	if len(locs) != 1 {
		t.Fatalf("locations = %v", ch["locations"])
	}
	loc := locs[0].(map[string]any)
	items := loc["items"].([]any)
	if len(items) != 1 {
		t.Fatalf("items = %v", loc["items"])
	}
	it := items[0].(map[string]any)
	if it["profit"] != 26000.0 {
		t.Fatalf("item = %v", it)
	}
	if it["icon_url"] != "https://images.evetech.net/types/587/icon?size=32" {
		t.Fatalf("icon = %v", it["icon_url"])
	}
	totals := data["totals"].(map[string]any)
	if totals["profit"] != 26000.0 || totals["characters"] != 1.0 || totals["items"] != 1.0 {
		t.Fatalf("totals = %v", totals)
	}
	if _, ok := body["meta"].(map[string]any)["freshness"]; !ok {
		t.Fatalf("freshness missing: %v", body["meta"])
	}
	_ = time.Now
}
