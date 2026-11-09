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

type stubProgressChars struct {
	assets     map[int64][]map[string]any
	corpAssets map[int64][]map[string]any
}

func (s *stubProgressChars) GetAssets(ctx context.Context, id int64) ([]map[string]any, error) {
	return s.assets[id], nil
}
func (s *stubProgressChars) GetCorporationAssets(ctx context.Context, charID, corpID int64) ([]map[string]any, error) {
	return s.corpAssets[corpID], nil
}

type stubProgressUniverse struct{}

func (s *stubProgressUniverse) GetSystem(ctx context.Context, id int) (map[string]any, error) {
	return map[string]any{"name": "Jita"}, nil
}
func (s *stubProgressUniverse) GetStructure(ctx context.Context, id uint64, charID int64) (map[string]any, error) {
	return map[string]any{"name": "Structure", "solar_system_id": 30000142}, nil
}

type stubProgressRef struct{}

func (s *stubProgressRef) FindStation(ctx context.Context, id uint64) (*store.Station, error) {
	if id == 60003760 {
		return &store.Station{StationID: id, StationName: "Jita IV", SolarSystemID: sqlNullInt64(30000142)}, nil
	}
	return nil, nil
}
func (s *stubProgressRef) FindSystem(ctx context.Context, id int) (*store.SolarSystem, error) {
	if id == 30000142 {
		return &store.SolarSystem{SolarSystemID: id, SolarSystemName: "Jita"}, nil
	}
	return nil, nil
}

func progressStepsJSON() string {
	return `[{"type_id":587,"name":"Rifter","quantity_required":10,"blueprint_type_id":786,"activity_type":"manufacturing","materials":[{"type_id":34,"name":"Tritanium","quantity":100}],"children":[]}]`
}

func progressTestDeps() Deps {
	stub := testAuth()
	stub.chars = []auth.Character{{CharacterID: 11, Name: "Main", IsMain: true, IsEsiActive: true}}
	now := time.Date(2026, 11, 8, 12, 0, 0, 0, time.UTC)
	d := Deps{Sessions: stub, Users: stub,
		Programs: &stubPrograms{rows: map[int64]store.BuildProgram{
			1: {ID: 1, UserID: 1, Name: "A", ProgramType: "manufacturing", StepsJSON: progressStepsJSON(), SettingsJSON: `{}`},
			9: {ID: 9, UserID: 9, Name: "Foreign", ProgramType: "manufacturing", StepsJSON: `[]`, SettingsJSON: `{}`},
		}, next: 100, now: now},
		CorpPrograms: &stubCorpPrograms{rows: map[int64]store.CorpProgram{
			1: {ID: 1, CorporationID: 2001, Name: "CA", ProgramType: "manufacturing", StepsJSON: progressStepsJSON(), SettingsJSON: sqlStr("{}")},
		}, next: 100, now: now},
		Corp: &stubCorp{
			configs: map[int64]*store.CorpConfig{2001: {CorporationID: 2001}},
			members: map[int64][]store.CorpCharacter{2001: {{CharacterID: 11, IsDirectorCEO: true}}},
			full:    map[int64]*store.CorpConfigFull{2001: {CorporationID: 2001, CharacterID: 11, EnabledByUserID: 1, IsActive: true}},
		},
		Progress: ProgressDeps{
			Characters: &stubProgressChars{
				assets: map[int64][]map[string]any{11: {
					{"item_id": 1, "type_id": 34, "quantity": 50.0, "location_id": 60003760, "location_type": "station"},
					{"item_id": 2, "type_id": 587, "quantity": 3.0, "location_id": 60003760, "location_type": "station"},
				}},
				corpAssets: map[int64][]map[string]any{2001: {
					{"item_id": 7, "type_id": 34, "quantity": 500.0, "location_id": 60003760, "location_type": "station"},
				}},
			},
			Universe:  &stubProgressUniverse{},
			Reference: &stubProgressRef{},
		},
		Industry: &stubIndustry{
			names:  map[int]string{34: "Tritanium", 587: "Rifter", 786: "Rifter Blueprint"},
			groups: map[int]int{34: 18, 587: 25, 786: 1034},
		},
	}
	mk := func(bp, act int, mats, prods string) *industry.BlueprintActivityRow {
		return &industry.BlueprintActivityRow{BlueprintTypeID: bp, ActivityID: act, Time: 100,
			Materials: sqlStr(mats), Products: sqlStr(prods)}
	}
	d.Industry.(*stubIndustry).activities = map[[2]int]*industry.BlueprintActivityRow{
		{786, 1}: mk(786, 1, `[{"type_id":34,"name":"Tritanium","quantity":100}]`, `[{"type_id":587,"name":"Rifter","quantity":1}]`),
	}
	d.Trading = &stubMarket{}
	return d
}

func TestPersonalProgress(t *testing.T) {
	d := progressTestDeps()
	code, body := get(t, d, "/api/v1/build-programs/1/progress")
	if code != http.StatusOK {
		t.Fatalf("progress = %d %v", code, body)
	}
	data := body["data"].(map[string]any)
	for _, k := range []string{"character_names", "overall_progress", "steps", "required_blueprints", "base_materials", "fuel_blocks", "reactions_summary", "sections"} {
		if _, ok := data[k]; !ok {
			t.Fatalf("missing %s in %v", k, data)
		}
	}
	if data["character_names"].([]any)[0] != "Main" {
		t.Fatalf("names = %v", data["character_names"])
	}
	// 3 of 10 Rifters -> 30
	if data["overall_progress"] != 30.0 {
		t.Fatalf("overall = %v", data["overall_progress"])
	}
	step := data["steps"].([]any)[0].(map[string]any)
	mat := step["materials"].([]any)[0].(map[string]any)
	// remaining 7 -> ceil(100*0.7)=70 required, 50 on hand, not enough
	if mat["required"] != 70.0 || mat["has_enough"] != false {
		t.Fatalf("mat = %v", mat)
	}
	bp := data["required_blueprints"].([]any)[0].(map[string]any)
	if bp["name"] != "Rifter Blueprint" || bp["owned"] != false {
		t.Fatalf("bp = %v", bp)
	}
	base := data["base_materials"].([]any)[0].(map[string]any)
	if base["group_name"] != "Minerals" {
		t.Fatalf("base = %v", base)
	}
	if code, _ := get(t, d, "/api/v1/build-programs/9/progress"); code != http.StatusNotFound {
		t.Fatalf("foreign = %d", code)
	}
	// no characters -> 400
	d2 := progressTestDeps()
	d2.Sessions.(*stubAuth).chars = nil
	d2.Users.(*stubAuth).chars = nil
	if code, _ := get(t, d2, "/api/v1/build-programs/1/progress"); code != http.StatusBadRequest {
		t.Fatalf("no chars = %d", code)
	}
	// location filter excluding the station -> materials required unscaled? no:
	// filter active, station not in filter -> quantities 0 -> required full 100
	code, body = get(t, d, "/api/v1/build-programs/1/progress?location_ids[]=99999999")
	if code != http.StatusOK {
		t.Fatalf("filtered = %d %v", code, body)
	}
	fmat := body["data"].(map[string]any)["steps"].([]any)[0].(map[string]any)["materials"].([]any)[0].(map[string]any)
	if fmat["required"] != 100.0 || fmat["available"] != 0.0 {
		t.Fatalf("filtered mat = %v", fmat)
	}
	// bad filter -> 422
	if code, _ := get(t, d, "/api/v1/build-programs/1/progress?location_ids[]=x"); code != http.StatusUnprocessableEntity {
		t.Fatalf("bad filter = %d", code)
	}
}

func TestPersonalAssetLocations(t *testing.T) {
	d := progressTestDeps()
	code, body := get(t, d, "/api/v1/build-programs/1/asset-locations")
	if code != http.StatusOK {
		t.Fatalf("locations = %d %v", code, body)
	}
	items := body["data"].([]any)
	if len(items) != 1 {
		t.Fatalf("items = %v", items)
	}
	it := items[0].(map[string]any)
	if it["name"] != "Jita IV" || it["solar_system_name"] != "Jita" {
		t.Fatalf("item = %v", it)
	}
	pag := body["meta"].(map[string]any)["pagination"].(map[string]any)
	if pag["total"] != 1.0 || pag["page"] != 1.0 {
		t.Fatalf("pagination = %v", pag)
	}
	if code, _ := get(t, d, "/api/v1/build-programs/9/asset-locations"); code != http.StatusNotFound {
		t.Fatalf("foreign = %d", code)
	}
}

func TestCorpProgress(t *testing.T) {
	d := progressTestDeps()
	// need corp char on the user for the corp routes
	stub := d.Sessions.(*stubAuth)
	corpID := int64(2001)
	stub.chars = []auth.Character{{
		CharacterID: 11, Name: "Main", IsMain: true, IsEsiActive: true,
		CorporationID: &corpID, IsDirectorCEO: true,
		Scopes: []string{"esi-assets.read_corporation_assets.v1"},
	}}
	code, body := get(t, d, "/api/v1/corporation/build-programs/1/progress")
	if code != http.StatusOK {
		t.Fatalf("progress = %d %v", code, body)
	}
	data := body["data"].(map[string]any)
	if _, ok := data["character_names"]; ok {
		t.Fatalf("personal key leaked: %v", data)
	}
	if data["character_name"] != "Main" {
		t.Fatalf("character_name = %v", data)
	}
	// corp assets: 500 Tritanium at input, 0 Rifters at output -> overall 0
	if data["overall_progress"] != 0.0 {
		t.Fatalf("overall = %v", data["overall_progress"])
	}
	mat := data["steps"].([]any)[0].(map[string]any)["materials"].([]any)[0].(map[string]any)
	if mat["available"] != 500.0 || mat["required"] != 100.0 {
		t.Fatalf("mat = %v", mat)
	}
	code, body = get(t, d, "/api/v1/corporation/build-programs/1/asset-locations")
	if code != http.StatusOK {
		t.Fatalf("locations = %d %v", code, body)
	}
	if len(body["data"].([]any)) != 1 {
		t.Fatalf("items = %v", body["data"])
	}
}

func sqlNullInt64(v int64) sql.NullInt64 { return sql.NullInt64{Int64: v, Valid: true} }
