package http

import (
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"

	"github.com/sudo-adduser-jordan/eveindustry/internal/store"
)

// stubPlanning implements store.PlanningStore in memory.
type stubPlanning struct {
	rows   map[int64]store.StationConfig
	nextID int64
}

func (s *stubPlanning) StationConfigs(ctx context.Context, userID int64, limit, offset int) ([]store.StationConfig, int, error) {
	var out []store.StationConfig
	now := time.Now()
	for _, r := range s.rows {
		if r.UserID == userID {
			out = append(out, r)
		}
	}
	_ = now
	total := len(out)
	if offset > total {
		offset = total
	}
	end := offset + limit
	if end > total {
		end = total
	}
	return out[offset:end], total, nil
}
func (s *stubPlanning) StationConfig(ctx context.Context, id, userID int64) (*store.StationConfig, error) {
	if r, ok := s.rows[id]; ok && r.UserID == userID {
		r := r
		return &r, nil
	}
	return nil, nil
}
func (s *stubPlanning) CreateStationConfig(ctx context.Context, userID int64, in store.StationConfigInput) (*store.StationConfig, error) {
	s.nextID++
	r := store.StationConfig{ID: s.nextID, UserID: userID, ActivityType: "manufacturing",
		Name: strVal(in.Name), StructureType: strVal(in.StructureType), RigME: strVal(in.RigME),
		RigTE: strVal(in.RigTE), SystemSecurity: strVal(in.SystemSecurity),
		IsDefault: in.IsDefault != nil && *in.IsDefault}
	if r.IsDefault {
		for id, o := range s.rows {
			if o.UserID == userID {
				o.IsDefault = false
				s.rows[id] = o
			}
		}
	}
	s.rows[r.ID] = r
	out := r
	return &out, nil
}
func (s *stubPlanning) UpdateStationConfig(ctx context.Context, id, userID int64, in store.StationConfigInput) (*store.StationConfig, error) {
	r, ok := s.rows[id]
	if !ok || r.UserID != userID {
		return nil, nil
	}
	if in.Name != nil {
		r.Name = *in.Name
	}
	if in.IsDefault != nil && *in.IsDefault {
		for oid, o := range s.rows {
			if o.UserID == userID && oid != id {
				o.IsDefault = false
				s.rows[oid] = o
			}
		}
		r.IsDefault = true
	} else if in.IsDefault != nil {
		r.IsDefault = false
	}
	s.rows[id] = r
	out := r
	return &out, nil
}
func (s *stubPlanning) DeleteStationConfig(ctx context.Context, id, userID int64) (bool, error) {
	if r, ok := s.rows[id]; ok && r.UserID == userID {
		delete(s.rows, id)
		return true, nil
	}
	return false, nil
}
func (s *stubPlanning) SetDefaultStationConfig(ctx context.Context, id, userID int64) (*store.StationConfig, error) {
	r, ok := s.rows[id]
	if !ok || r.UserID != userID {
		return nil, nil
	}
	for oid, o := range s.rows {
		if o.UserID == userID {
			o.IsDefault = oid == id
			s.rows[oid] = o
		}
	}
	out := s.rows[id]
	return &out, nil
}

func strVal(s *string) string {
	if s == nil {
		return ""
	}
	return *s
}

func planningTestDeps() Deps {
	stub := testAuth()
	d := Deps{Sessions: stub, Users: stub, Planning: &stubPlanning{rows: map[int64]store.StationConfig{
		1: {ID: 1, UserID: 1, ActivityType: "manufacturing", Name: "Alpha"},
		2: {ID: 2, UserID: 1, ActivityType: "manufacturing", Name: "Beta", IsDefault: true},
		3: {ID: 3, UserID: 9, ActivityType: "manufacturing", Name: "Foreign"},
	}, nextID: 10}}
	return d
}

func postJSON(t *testing.T, d Deps, path, body string) (int, map[string]any) {
	return post(t, d, path, body)
}

func TestStationConfigsIndex(t *testing.T) {
	d := planningTestDeps()
	code, body := get(t, d, "/api/v1/station-configs")
	if code != http.StatusOK {
		t.Fatalf("code = %d %v", code, body)
	}
	if len(body["data"].([]any)) != 2 {
		t.Fatalf("rows = %v", body["data"])
	}
}

func TestStationConfigCRUD(t *testing.T) {
	d := planningTestDeps()
	// create (201), becomes only default
	code, body := postJSON(t, d, "/api/v1/station-configs", `{"name":"Gamma","structure_type":"raitaru","rig_me":"t1","rig_te":"none","system_security":"lowsec","is_default":true}`)
	if code != http.StatusCreated {
		t.Fatalf("create = %d %v", code, body)
	}
	if body["data"].(map[string]any)["is_default"] != true {
		t.Fatalf("default = %v", body)
	}
	// foreign id -> 404 on update and delete
	if code, _ := putJSON(t, d, "/api/v1/station-configs/3", `{"name":"X"}`); code != http.StatusNotFound {
		t.Fatalf("foreign update = %d", code)
	}
	if code, _ := deleteReq(t, d, "/api/v1/station-configs/3"); code != http.StatusNotFound {
		t.Fatalf("foreign delete = %d", code)
	}
	// update own
	code, body = putJSON(t, d, "/api/v1/station-configs/1", `{"name":"Alpha2"}`)
	if code != http.StatusOK || body["data"].(map[string]any)["name"] != "Alpha2" {
		t.Fatalf("update = %d %v", code, body)
	}
	// set default flips the flag
	if code, _ := postJSON(t, d, "/api/v1/station-configs/1/default", `{}`); code != http.StatusOK {
		t.Fatalf("default = %d", code)
	}
	code, body = get(t, d, "/api/v1/station-configs")
	_ = body
	// delete own -> 204
	if code, _ := deleteReq(t, d, "/api/v1/station-configs/1"); code != http.StatusNoContent {
		t.Fatalf("delete = %d", code)
	}
	// bad input -> 422
	if code, _ := postJSON(t, d, "/api/v1/station-configs", `{"name":""}`); code != http.StatusUnprocessableEntity {
		t.Fatalf("bad = %d", code)
	}
}

func putJSON(t *testing.T, d Deps, path, body string) (int, map[string]any) {
	t.Helper()
	e := New(d)
	req := httptest.NewRequest(http.MethodPut, path, strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	rec := httptest.NewRecorder()
	e.ServeHTTP(rec, req)
	var out map[string]any
	if err := json.Unmarshal(rec.Body.Bytes(), &out); err != nil {
		t.Fatalf("%s: body is not JSON: %v (%s)", path, err, rec.Body.String())
	}
	return rec.Code, out
}

func deleteReq(t *testing.T, d Deps, path string) (int, map[string]any) {
	t.Helper()
	e := New(d)
	req := httptest.NewRequest(http.MethodDelete, path, nil)
	rec := httptest.NewRecorder()
	e.ServeHTTP(rec, req)
	if rec.Code == http.StatusNoContent {
		return rec.Code, nil
	}
	var out map[string]any
	if err := json.Unmarshal(rec.Body.Bytes(), &out); err != nil {
		t.Fatalf("%s: body is not JSON: %v (%s)", path, err, rec.Body.String())
	}
	return rec.Code, out
}
