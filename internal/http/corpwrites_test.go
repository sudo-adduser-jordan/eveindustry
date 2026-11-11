package http

import (
	"context"
	"database/sql"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"

	"github.com/sudo-adduser-jordan/eveindustry/internal/auth"
	"github.com/sudo-adduser-jordan/eveindustry/internal/industry"
	"github.com/sudo-adduser-jordan/eveindustry/internal/store"
)

type stubCorpPrograms struct {
	rows map[int64]store.CorpProgram
	next int64
	now  time.Time
}

func (s *stubCorpPrograms) CorpPrograms(ctx context.Context, corpID int64) ([]store.CorpProgram, error) {
	var out []store.CorpProgram
	for _, r := range s.rows {
		if r.CorporationID == corpID {
			out = append(out, r)
		}
	}
	return out, nil
}
func (s *stubCorpPrograms) CorpProgram(ctx context.Context, id, corpID int64) (*store.CorpProgram, error) {
	if r, ok := s.rows[id]; ok && r.CorporationID == corpID {
		r := r
		return &r, nil
	}
	return nil, nil
}
func (s *stubCorpPrograms) CorpProgramsByIDs(ctx context.Context, corpID int64, ids []int64) ([]store.CorpProgram, error) {
	var out []store.CorpProgram
	for _, id := range ids {
		if r, ok := s.rows[id]; ok && r.CorporationID == corpID {
			out = append(out, r)
		}
	}
	return out, nil
}
func (s *stubCorpPrograms) upsert(p store.CorpProgram) *store.CorpProgram {
	if p.ID == 0 {
		s.next++
		p.ID = s.next
		p.CreatedAt = s.now
	}
	p.UpdatedAt = s.now
	s.rows[p.ID] = p
	out := p
	return &out
}
func (s *stubCorpPrograms) CreateCorpProgram(ctx context.Context, p store.CorpProgram) (*store.CorpProgram, error) {
	return s.upsert(p), nil
}
func (s *stubCorpPrograms) RenameCorpProgram(ctx context.Context, id, corpID int64, name string) (*store.CorpProgram, error) {
	r, ok := s.rows[id]
	if !ok || r.CorporationID != corpID {
		return nil, nil
	}
	r.Name = name
	return s.upsert(r), nil
}
func (s *stubCorpPrograms) UpdateCorpProgramSettings(ctx context.Context, id, corpID int64, settingsJSON string) (*store.CorpProgram, error) {
	r, ok := s.rows[id]
	if !ok || r.CorporationID != corpID {
		return nil, nil
	}
	if settingsJSON == "" {
		r.SettingsJSON = sql.NullString{}
	} else {
		r.SettingsJSON = sql.NullString{String: settingsJSON, Valid: true}
	}
	return s.upsert(r), nil
}
func (s *stubCorpPrograms) DeleteCorpProgram(ctx context.Context, id, corpID int64) (bool, error) {
	if r, ok := s.rows[id]; ok && r.CorporationID == corpID {
		delete(s.rows, id)
		return true, nil
	}
	return false, nil
}

func corpWritesTestDeps() Deps {
	stub := testAuth()
	corpID := int64(2001)
	stub.chars = []auth.Character{{
		CharacterID: 11, Name: "Main", IsMain: true, IsEsiActive: true,
		CorporationID: &corpID,
	}}
	now := time.Date(2026, 11, 8, 12, 0, 0, 0, time.UTC)
	d := Deps{Sessions: stub, Users: stub,
		CorpPrograms: &stubCorpPrograms{
			rows: map[int64]store.CorpProgram{
				1: {ID: 1, CorporationID: 2001, Name: "A", ProgramType: "manufacturing", StepsJSON: `[]`, SettingsJSON: sqlStr("{\"sections\":[]}")},
				2: {ID: 2, CorporationID: 2001, Name: "B", ProgramType: "manufacturing", StepsJSON: `[]`, SettingsJSON: sqlStr("{}")},
				9: {ID: 9, CorporationID: 9999, Name: "Foreign", ProgramType: "manufacturing", StepsJSON: `[]`, SettingsJSON: sqlStr("{}")},
			},
			next: 100, now: now,
		},
		Corp: &stubCorp{
			configs: map[int64]*store.CorpConfig{2001: {CorporationID: 2001}},
			members: map[int64][]store.CorpCharacter{2001: {{CharacterID: 11, IsDirectorCEO: true}}},
			full:    map[int64]*store.CorpConfigFull{2001: {CorporationID: 2001, CorporationName: sqlNullStrCorp("ACME"), CharacterID: 11, EnabledByUserID: 1, IsActive: true}},
		},
		StockItems:      &stubStockReport{},
		RefreshThrottle: NewRefreshThrottle(),
	}
	mk := func(bp, act int, mats, prods string) *industry.BlueprintActivityRow {
		return &industry.BlueprintActivityRow{BlueprintTypeID: bp, ActivityID: act, Time: 100,
			Materials: sqlStr(mats), Products: sqlStr(prods)}
	}
	d.Industry = &stubIndustry{activities: map[[2]int]*industry.BlueprintActivityRow{
		{786, 1}: mk(786, 1,
			`[{"type_id":34,"name":"Tritanium","quantity":100}]`,
			`[{"type_id":587,"name":"Rifter","quantity":1}]`),
	}, names: map[int]string{34: "Tritanium"}}
	d.Trading = &stubMarket{}
	return d
}

func TestCorpProgramCRUD(t *testing.T) {
	d := corpWritesTestDeps()
	code, _ := post(t, d, "/api/v1/corporation/build-programs", `{"name":"Corp Rifter","steps":[{"type_id":587,"name":"Rifter","quantity_required":10,"materials":[{"type_id":34,"name":"Tritanium","quantity":100}]}]}`)
	if code != http.StatusCreated {
		t.Fatalf("create = %d", code)
	}
	if code, _ := putJSON(t, d, "/api/v1/corporation/build-programs/9", `{"name":"X"}`); code != http.StatusNotFound {
		t.Fatalf("foreign rename = %d", code)
	}
	if code, _ := putJSON(t, d, "/api/v1/corporation/build-programs/1", `{"name":"A2"}`); code != http.StatusOK {
		t.Fatalf("rename = %d", code)
	}
	if code, _ := post(t, d, "/api/v1/corporation/build-programs/merge", `{"name":"M","program_ids":[1,2]}`); code != http.StatusCreated {
		t.Fatalf("merge = %d", code)
	}
	if code, _ := post(t, d, "/api/v1/corporation/build-programs/merge", `{"name":"M","program_ids":[1]}`); code != http.StatusUnprocessableEntity {
		t.Fatalf("merge min = %d", code)
	}
	if code, _ := deleteReq(t, d, "/api/v1/corporation/build-programs/1"); code != http.StatusNoContent {
		t.Fatalf("delete = %d", code)
	}
}

func TestStockItemCRUD(t *testing.T) {
	base := programsTestDeps()
	ind := base.Industry.(*stubIndustry)
	ind.names = map[int]string{34: "Tritanium"}
	d := Deps{Sessions: base.Sessions, Users: base.Users,
		StockItems: &stubStockReport{items: []store.WatchedItem{}},
		Industry:   ind,
	}
	u := d.Sessions.(*stubAuth).user
	u.IsStockManager = true
	code, body := post(t, d, "/api/v1/stock/items", `{"type_id":34,"desired_quantity":500}`)
	if code != http.StatusCreated {
		t.Fatalf("store = %d %v", code, body)
	}
	if code, _ := post(t, d, "/api/v1/stock/items", `{"type_id":-1,"desired_quantity":1}`); code != http.StatusUnprocessableEntity {
		t.Fatalf("bad type = %d", code)
	}
	id := int(body["data"].(map[string]any)["id"].(float64))
	if code, _ := putJSON(t, d, "/api/v1/stock/items/9999", `{"desired_quantity":1}`); code != http.StatusNotFound {
		t.Fatalf("foreign update = %d", code)
	}
	path := "/api/v1/stock/items/" + itoa(id)
	if code, _ := putJSON(t, d, path, `{"desired_quantity":10}`); code != http.StatusOK {
		t.Fatalf("update = %d", code)
	}
	if code, _ := deleteReq(t, d, path); code != http.StatusNoContent {
		t.Fatalf("delete = %d", code)
	}
}

func TestStructuresCRUD(t *testing.T) {
	d := programsTestDeps()
	d.Planning = &stubPlanning{rows: map[int64]store.StationConfig{}, nextID: 100}
	code, body := post(t, d, "/api/v1/structures", `{"activity_type":"manufacturing","name":"Rait","structure_type":"raitaru","rig_me":"t1","rig_te":"none","system_security":"lowsec"}`)
	if code != http.StatusCreated {
		t.Fatalf("store = %d %v", code, body)
	}
	if code, _ := post(t, d, "/api/v1/structures", `{"activity_type":"nope","name":"x"}`); code != http.StatusUnprocessableEntity {
		t.Fatalf("bad activity = %d", code)
	}
	id := int(body["data"].(map[string]any)["id"].(float64))
	path := "/api/v1/structures/" + itoa(id)
	if code, _ := putJSON(t, d, path, `{"name":"Rait2"}`); code != http.StatusOK {
		t.Fatalf("update = %d", code)
	}
	if code, _ := post(t, d, path+"/default", `{}`); code != http.StatusOK {
		t.Fatalf("default = %d", code)
	}
	if code, _ := deleteReq(t, d, path); code != http.StatusNoContent {
		t.Fatalf("delete = %d", code)
	}
	if code, _ := deleteReq(t, d, "/api/v1/structures/9999"); code != http.StatusNotFound {
		t.Fatalf("foreign delete = %d", code)
	}
}

func TestIndustryRefresh(t *testing.T) {
	d := programsTestDeps()
	d.RefreshThrottle = NewRefreshThrottle()
	if code := postRaw(t, d, "/api/v1/industry/availability/clear", `{}`); code != http.StatusNoContent {
		t.Fatalf("clear = %d", code)
	}
	if code := postRaw(t, d, "/api/v1/industry/availability/clear", `{}`); code != http.StatusTooManyRequests {
		t.Fatalf("throttle = %d", code)
	}
	if code := postRaw(t, d, "/api/v1/industry/availability/refresh", `{}`); code != http.StatusNoContent {
		t.Fatalf("avail = %d", code)
	}
	if code := postRaw(t, d, "/api/v1/industry/can-produce/refresh", `{}`); code != http.StatusNoContent {
		t.Fatalf("canproduce = %d", code)
	}
}

func postRaw(t *testing.T, d Deps, path, body string) int {
	t.Helper()
	e := New(d)
	req := httptest.NewRequest(http.MethodPost, path, strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	rec := httptest.NewRecorder()
	e.ServeHTTP(rec, req)
	return rec.Code
}

func itoa(i int) string {
	if i == 0 {
		return "0"
	}
	neg := i < 0
	if neg {
		i = -i
	}
	var b [20]byte
	p := len(b)
	for i > 0 {
		p--
		b[p] = byte('0' + i%10)
		i /= 10
	}
	if neg {
		p--
		b[p] = '-'
	}
	return string(b[p:])
}

func corpPagesNoConfigDeps() Deps {
	stub := testAuth()
	corpID := int64(2001)
	stub.chars = []auth.Character{{
		CharacterID: 11, Name: "Main", IsMain: true, IsEsiActive: true,
		CorporationID: &corpID, CorporationName: strPtr("ACME"),
		IsDirectorCEO: true, Scopes: []string{"esi-assets.read_corporation_assets.v1"},
	}}
	return Deps{Sessions: stub, Users: stub,
		CorpPrograms: &stubCorpPrograms{rows: map[int64]store.CorpProgram{}, next: 1, now: time.Now()},
		Corp:         &stubCorp{},
		Industry:     &stubIndustry{},
	}
}

func TestCorpProgramPages(t *testing.T) {
	d := corpWritesTestDeps()
	code, body := get(t, d, "/api/v1/corporation/build-programs")
	if code != http.StatusOK {
		t.Fatalf("index = %d %v", code, body)
	}
	data := body["data"].(map[string]any)
	if data["enabled"] != true || data["can_manage"] != true {
		t.Fatalf("overview = %v", data)
	}
	if len(data["programs"].([]any)) != 2 {
		t.Fatalf("programs = %v", data["programs"])
	}
	code, body = get(t, d, "/api/v1/corporation/build-programs/1")
	if code != http.StatusOK {
		t.Fatalf("show = %d %v", code, body)
	}
	if code, _ := get(t, d, "/api/v1/corporation/build-programs/9"); code != http.StatusNotFound {
		t.Fatalf("foreign show = %d", code)
	}
	d2 := corpWritesTestDeps()
	stub2 := d2.Sessions.(*stubAuth)
	stub2.uid = 2
	stub2.user = &auth.User{ID: 2, Name: "Other"}
	if code := postRaw(t, d2, "/api/v1/corporation/build-programs/disable", `{}`); code != http.StatusForbidden {
		t.Fatalf("disable foreign = %d", code)
	}
	if code := postRaw(t, d, "/api/v1/corporation/build-programs/disable", `{}`); code != http.StatusOK {
		t.Fatalf("disable = %d", code)
	}
	if code, _ := get(t, d, "/api/v1/corporation/build-programs/1"); code != http.StatusNotFound {
		t.Fatalf("show disabled = %d", code)
	}
	d3 := corpPagesNoConfigDeps()
	stub3 := d3.Sessions.(*stubAuth)
	stub3.chars[0].Scopes = nil
	if code := postRaw(t, d3, "/api/v1/corporation/build-programs/enable", `{}`); code != http.StatusUnprocessableEntity {
		t.Fatalf("enable ineligible = %d", code)
	}
	d4 := corpPagesNoConfigDeps()
	if code := postRaw(t, d4, "/api/v1/corporation/build-programs/enable", `{"character_id":11}`); code != http.StatusOK {
		t.Fatalf("enable = %d", code)
	}
	if code := postRaw(t, d4, "/api/v1/corporation/build-programs/enable", `{"character_id":99}`); code != http.StatusUnprocessableEntity {
		t.Fatalf("enable wrong char = %d", code)
	}
}

func TestStructuresIndex(t *testing.T) {
	d := programsTestDeps()
	d.Planning = &stubPlanning{rows: map[int64]store.StationConfig{
		1: {ID: 1, UserID: 1, ActivityType: "manufacturing", Name: "Alpha", StructureType: "raitaru", RigME: "t1", RigTE: "none", SystemSecurity: "lowsec"},
		2: {ID: 2, UserID: 1, ActivityType: "reaction", Name: "Beta", StructureType: "athanor", RigME: "t1", RigTE: "none", SystemSecurity: "lowsec"},
		3: {ID: 3, UserID: 9, ActivityType: "manufacturing", Name: "Foreign"},
	}, nextID: 10}
	d.Reference = &stubReferenceSystems{}
	code, body := get(t, d, "/api/v1/structures")
	if code != http.StatusOK {
		t.Fatalf("index = %d %v", code, body)
	}
	if body["meta"].(map[string]any)["pagination"].(map[string]any)["total"] != 2.0 {
		t.Fatalf("total = %v", body)
	}
	first := body["data"].([]any)[0].(map[string]any)
	if first["effective_bonuses"] == nil {
		t.Fatalf("bonuses missing: %v", first)
	}
	code, body = get(t, d, "/api/v1/structures?activity_type=reaction")
	if code != http.StatusOK || body["meta"].(map[string]any)["pagination"].(map[string]any)["total"] != 1.0 {
		t.Fatalf("filtered = %d %v", code, body)
	}
	if code, _ := get(t, d, "/api/v1/structures?activity_type=nope"); code != http.StatusUnprocessableEntity {
		t.Fatalf("bad filter = %d", code)
	}
}

type stubReferenceSystems struct{ store.ReferenceStore }

func (s *stubReferenceSystems) FindSystem(ctx context.Context, id int) (*store.SolarSystem, error) {
	return nil, nil
}
