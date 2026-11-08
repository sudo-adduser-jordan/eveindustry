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

// stubPrograms implements store.ProgramStore in memory.
type stubPrograms struct {
	rows map[int64]store.BuildProgram
	next int64
	now  time.Time
}

func (s *stubPrograms) BuildPrograms(ctx context.Context, uid int64) ([]store.BuildProgram, error) {
	var out []store.BuildProgram
	for _, r := range s.rows {
		if r.UserID == uid {
			out = append(out, r)
		}
	}
	return out, nil
}
func (s *stubPrograms) BuildProgram(ctx context.Context, id, uid int64) (*store.BuildProgram, error) {
	if r, ok := s.rows[id]; ok && r.UserID == uid {
		r := r
		return &r, nil
	}
	return nil, nil
}
func (s *stubPrograms) BuildProgramsByIDs(ctx context.Context, uid int64, ids []int64) ([]store.BuildProgram, error) {
	var out []store.BuildProgram
	for _, id := range ids {
		if r, ok := s.rows[id]; ok && r.UserID == uid {
			out = append(out, r)
		}
	}
	return out, nil
}
func (s *stubPrograms) upsert(p store.BuildProgram) *store.BuildProgram {
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
func (s *stubPrograms) CreateBuildProgram(ctx context.Context, p store.BuildProgram) (*store.BuildProgram, error) {
	return s.upsert(p), nil
}
func (s *stubPrograms) RenameBuildProgram(ctx context.Context, id, uid int64, name string) (*store.BuildProgram, error) {
	r, ok := s.rows[id]
	if !ok || r.UserID != uid {
		return nil, nil
	}
	r.Name = name
	return s.upsert(r), nil
}
func (s *stubPrograms) UpdateBuildProgramSettings(ctx context.Context, id, uid int64, settings string) (*store.BuildProgram, error) {
	r, ok := s.rows[id]
	if !ok || r.UserID != uid {
		return nil, nil
	}
	r.SettingsJSON = settings
	return s.upsert(r), nil
}
func (s *stubPrograms) DeleteBuildProgram(ctx context.Context, id, uid int64) (bool, error) {
	if r, ok := s.rows[id]; ok && r.UserID == uid {
		delete(s.rows, id)
		return true, nil
	}
	return false, nil
}

func programsTestDeps() Deps {
	stub := testAuth()
	stub.chars = []auth.Character{{CharacterID: 11, Name: "Main", IsMain: true, IsEsiActive: true}}
	now := time.Date(2026, 11, 8, 12, 0, 0, 0, time.UTC)
	d := Deps{Sessions: stub, Users: stub, Programs: &stubPrograms{
		rows: map[int64]store.BuildProgram{
			1: {ID: 1, UserID: 1, Name: "A", ProgramType: "manufacturing", StepsJSON: `[]`, SettingsJSON: `{}`},
			2: {ID: 2, UserID: 1, Name: "B", ProgramType: "manufacturing", StepsJSON: `[]`, SettingsJSON: `{}`},
			9: {ID: 9, UserID: 9, Name: "Foreign", ProgramType: "manufacturing", StepsJSON: `[]`, SettingsJSON: `{}`},
		},
		next: 100, now: now,
	}}
	mk := func(bp, act int, mats, prods string) *industry.BlueprintActivityRow {
		return &industry.BlueprintActivityRow{BlueprintTypeID: bp, ActivityID: act, Time: 100,
			Materials: sqlStr(mats), Products: sqlStr(prods)}
	}
	d.Industry = &stubIndustry{activities: map[[2]int]*industry.BlueprintActivityRow{
		{786, 1}: mk(786, 1,
			`[{"type_id":34,"name":"Tritanium","quantity":100}]`,
			`[{"type_id":587,"name":"Rifter","quantity":1}]`),
		{999, 11}: mk(999, 11,
			`[{"type_id":34,"name":"Tritanium","quantity":50}]`,
			`[{"type_id":587,"name":"Composite","quantity":5}]`),
	}}
	d.Trading = &stubMarket{}
	return d
}

func TestBuildProgramCRUD(t *testing.T) {
	d := programsTestDeps()
	if code, _ := get(t, d, "/api/v1/build-programs"); code != http.StatusOK {
		t.Fatalf("index = %d", code)
	}
	if code, _ := get(t, d, "/api/v1/build-programs/9"); code != http.StatusNotFound {
		t.Fatalf("foreign = %d", code)
	}
	// create with nested materials: Trit is raw, no sub-blueprint in fixture
	code, body := post(t, d, "/api/v1/build-programs", `{"name":"Rifter x10","t2_type_id":587,"steps":[{"type_id":587,"name":"Rifter","quantity_required":10,"materials":[{"type_id":34,"name":"Tritanium","quantity":100}]}]}`)
	if code != http.StatusCreated {
		t.Fatalf("create = %d %v", code, body)
	}
	steps := body["data"].(map[string]any)["steps"].([]any)
	if len(steps) != 1 || steps[0].(map[string]any)["blueprint_type_id"].(float64) != 786 {
		t.Fatalf("nested = %v", steps)
	}
	// invalid: missing name
	if code, _ := post(t, d, "/api/v1/build-programs", `{"steps":[]}`); code != http.StatusUnprocessableEntity {
		t.Fatalf("bad = %d", code)
	}
	// rename
	if code, body := putJSON(t, d, "/api/v1/build-programs/1", `{"name":"A2"}`); code != http.StatusOK || body["data"].(map[string]any)["name"] != "A2" {
		t.Fatalf("rename = %d %v", code, body)
	}
	// location filter
	if code, _ := patchReq(t, d, "/api/v1/build-programs/1/location-filter", `{"location_ids":[30000142]}`); code != http.StatusOK {
		t.Fatalf("filter = %d", code)
	}
	// delete
	if code, _ := deleteReq(t, d, "/api/v1/build-programs/1"); code != http.StatusNoContent {
		t.Fatalf("delete = %d", code)
	}
}

func patchReq(t *testing.T, d Deps, path, body string) (int, map[string]any) {
	t.Helper()
	e := New(d)
	req := httptest.NewRequest(http.MethodPatch, path, strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	rec := httptest.NewRecorder()
	e.ServeHTTP(rec, req)
	var out map[string]any
	if err := jsonUnmarshal([]byte(rec.Body.String()), &out); err != nil {
		t.Fatalf("PATCH %s: body is not JSON: %v (%s)", path, err, rec.Body.String())
	}
	return rec.Code, out
}

func TestBuildProgramFromReactionPlan(t *testing.T) {
	d := programsTestDeps()
	if code, _ := post(t, d, "/api/v1/build-programs/from-reaction-plan", `{"name":"X","entries":[{"formula_type_id":4242,"slots":1,"runs_per_slot":5}]}`); code != http.StatusUnprocessableEntity {
		t.Fatalf("unknown = %d", code)
	}
	if code, _ := post(t, d, "/api/v1/build-programs/from-reaction-plan", `{"name":"X"}`); code != http.StatusUnprocessableEntity {
		t.Fatalf("missing = %d", code)
	}
	code, body := post(t, d, "/api/v1/build-programs/from-reaction-plan", `{"name":"R","entries":[{"formula_type_id":999,"formula_name":"F","slots":1,"runs_per_slot":5}]}`)
	if code != http.StatusCreated {
		t.Fatalf("create = %d %v", code, body)
	}
	if body["data"].(map[string]any)["program_type"] != "reaction" {
		t.Fatalf("type = %v", body)
	}
}

func TestBuildProgramMerge(t *testing.T) {
	d := programsTestDeps()
	code, body := post(t, d, "/api/v1/build-programs/merge", `{"name":"M","program_ids":[1,2]}`)
	if code != http.StatusCreated {
		t.Fatalf("merge = %d %v", code, body)
	}
	if secs, ok := body["data"].(map[string]any)["settings"].(map[string]any)["sections"].([]any); !ok || len(secs) != 2 {
		t.Fatalf("sections = %v", body)
	}
	if code, _ := post(t, d, "/api/v1/build-programs/merge", `{"name":"M","program_ids":[1,9]}`); code != http.StatusUnprocessableEntity {
		t.Fatalf("merge-foreign = %d", code)
	}
}

func TestBuildProgramShow(t *testing.T) {
	d := programsTestDeps()
	code, body := get(t, d, "/api/v1/build-programs/1")
	if code != http.StatusOK {
		t.Fatalf("show = %d %v", code, body)
	}
	if _, ok := body["meta"].(map[string]any)["volumes"]; !ok {
		t.Fatalf("volumes meta = %v", body)
	}
}

func sqlStr(s string) sql.NullString { return sql.NullString{String: s, Valid: true} }
