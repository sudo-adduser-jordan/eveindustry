package http

import (
	"context"
	"net/http"
	"testing"
	"time"

	"github.com/sudo-adduser-jordan/eveindustry/internal/store"
)

// stubPlans implements store.PlanStore in memory.
type stubPlans struct {
	inv  map[int64]store.InventionPlan
	reac map[int64]store.ReactionPlan
	next int64
	now  time.Time
}

func (s *stubPlans) nid() int64 { s.next++; return s.next }

func (s *stubPlans) InventionPlans(ctx context.Context, uid int64, limit, offset int) ([]store.InventionPlan, int, error) {
	var out []store.InventionPlan
	for _, r := range s.inv {
		if r.UserID == uid {
			out = append(out, r)
		}
	}
	return out, len(out), nil
}
func (s *stubPlans) InventionPlan(ctx context.Context, id, uid int64) (*store.InventionPlan, error) {
	if r, ok := s.inv[id]; ok && r.UserID == uid {
		r := r
		return &r, nil
	}
	return nil, nil
}
func (s *stubPlans) CreateInventionPlan(ctx context.Context, p store.InventionPlan) (*store.InventionPlan, error) {
	p.ID = s.nid()
	p.CreatedAt, p.UpdatedAt = s.now, s.now
	s.inv[p.ID] = p
	r := p
	return &r, nil
}
func (s *stubPlans) UpdateInventionPlan(ctx context.Context, p store.InventionPlan) (*store.InventionPlan, error) {
	if _, ok := s.inv[p.ID]; !ok {
		return nil, nil
	}
	p.UpdatedAt = s.now
	s.inv[p.ID] = p
	r := p
	return &r, nil
}
func (s *stubPlans) DeleteInventionPlan(ctx context.Context, id, uid int64) (bool, error) {
	if r, ok := s.inv[id]; ok && r.UserID == uid {
		delete(s.inv, id)
		return true, nil
	}
	return false, nil
}
func (s *stubPlans) ReactionPlans(ctx context.Context, uid int64, limit, offset int) ([]store.ReactionPlan, int, error) {
	var out []store.ReactionPlan
	for _, r := range s.reac {
		if r.UserID == uid {
			out = append(out, r)
		}
	}
	return out, len(out), nil
}
func (s *stubPlans) ReactionPlan(ctx context.Context, id, uid int64) (*store.ReactionPlan, error) {
	if r, ok := s.reac[id]; ok && r.UserID == uid {
		r := r
		return &r, nil
	}
	return nil, nil
}
func (s *stubPlans) CreateReactionPlan(ctx context.Context, p store.ReactionPlan) (*store.ReactionPlan, error) {
	p.ID = s.nid()
	p.CreatedAt, p.UpdatedAt = s.now, s.now
	s.reac[p.ID] = p
	r := p
	return &r, nil
}
func (s *stubPlans) UpdateReactionPlan(ctx context.Context, p store.ReactionPlan) (*store.ReactionPlan, error) {
	if _, ok := s.reac[p.ID]; !ok {
		return nil, nil
	}
	p.UpdatedAt = s.now
	s.reac[p.ID] = p
	r := p
	return &r, nil
}
func (s *stubPlans) DeleteReactionPlan(ctx context.Context, id, uid int64) (bool, error) {
	if r, ok := s.reac[id]; ok && r.UserID == uid {
		delete(s.reac, id)
		return true, nil
	}
	return false, nil
}

func plansTestDeps() Deps {
	stub := testAuth()
	now := time.Date(2026, 11, 8, 12, 0, 0, 0, time.UTC)
	return Deps{Sessions: stub, Users: stub, Plans: &stubPlans{
		inv: map[int64]store.InventionPlan{
			1: {ID: 1, UserID: 1, Name: "Rifter", T2TypeID: 587, T2TypeName: "Rifter", DecryptorKey: "none"},
			9: {ID: 9, UserID: 9, Name: "Foreign", T2TypeID: 1, T2TypeName: "X"},
		},
		reac: map[int64]store.ReactionPlan{
			2: {ID: 2, UserID: 1, Name: "Comp", EntriesJSON: `[]`, DefaultRunsPerSlot: 240, StructureType: "athanor", RigTier: "none", SecuritySpace: "lowsec"},
		},
		next: 100, now: now,
	}}
}

func TestInventionPlansCRUD(t *testing.T) {
	d := plansTestDeps()
	if code, _ := get(t, d, "/api/v1/invention-plans"); code != http.StatusOK {
		t.Fatalf("index = %d", code)
	}
	// foreign id -> 404 (hidden, not 403)
	if code, _ := get(t, d, "/api/v1/invention-plans/9"); code != http.StatusNotFound {
		t.Fatalf("foreign = %d", code)
	}
	// create minimal (defaults kick in) -> 201
	code, body := post(t, d, "/api/v1/invention-plans", `{"name":"Min","t2_type_id":1,"t2_type_name":"X"}`)
	if code != http.StatusCreated {
		t.Fatalf("create = %d %v", code, body)
	}
	if body["data"].(map[string]any)["decryptor_key"] != "none" || body["data"].(map[string]any)["invention_runs"].(float64) != 1 {
		t.Fatalf("defaults = %v", body)
	}
	id := int(body["data"].(map[string]any)["id"].(float64))
	// show
	if code, _ := get(t, d, "/api/v1/invention-plans/101"); code != http.StatusOK {
		t.Fatalf("show = %d", code)
	}
	_ = id
	// update
	if code, body = putJSON(t, d, "/api/v1/invention-plans/1", `{"t1_me":10}`); code != http.StatusOK || body["data"].(map[string]any)["t1_me"].(float64) != 10 {
		t.Fatalf("update = %d %v", code, body)
	}
	// delete own -> 204, then 404
	if code, _ := deleteReq(t, d, "/api/v1/invention-plans/1"); code != http.StatusNoContent {
		t.Fatalf("delete = %d", code)
	}
	if code, _ := get(t, d, "/api/v1/invention-plans/1"); code != http.StatusNotFound {
		t.Fatalf("gone = %d", code)
	}
	// bad input -> 422
	if code, _ := post(t, d, "/api/v1/invention-plans", `{"name":"x"}`); code != http.StatusUnprocessableEntity {
		t.Fatalf("bad = %d", code)
	}
}

func TestReactionPlansCRUD(t *testing.T) {
	d := plansTestDeps()
	code, _ := get(t, d, "/api/v1/reaction-plans")
	if code != http.StatusOK {
		t.Fatalf("index = %d", code)
	}
	entries := `[{"formula_type_id":999,"formula_name":"F","slots":1,"runs_per_slot":5}]`
	code, body := post(t, d, "/api/v1/reaction-plans", `{"name":"R","entries":`+entries+`}`)
	if code != http.StatusCreated {
		t.Fatalf("create = %d %v", code, body)
	}
	data := body["data"].(map[string]any)
	// defaults: 240 runs, athanor/none/lowsec
	if data["default_runs_per_slot"].(float64) != 240 || data["structure_type"] != "athanor" {
		t.Fatalf("defaults = %v", data)
	}
	if data["entries"].([]any)[0].(map[string]any)["slots"].(float64) != 1 {
		t.Fatalf("entries = %v", data)
	}
	// show + update (partial, entries replaced)
	if code, _ := get(t, d, "/api/v1/reaction-plans/2"); code != http.StatusOK {
		t.Fatalf("show = %d", code)
	}
	code, body = putJSON(t, d, "/api/v1/reaction-plans/2", `{"name":"R2","entries":`+entries+`}`)
	if code != http.StatusOK || body["data"].(map[string]any)["name"] != "R2" {
		t.Fatalf("update = %d %v", code, body)
	}
	// empty entries rejected
	if code, _ := post(t, d, "/api/v1/reaction-plans", `{"name":"R","entries":[]}`); code != http.StatusUnprocessableEntity {
		t.Fatalf("empty = %d", code)
	}
	if code, _ := deleteReq(t, d, "/api/v1/reaction-plans/2"); code != http.StatusNoContent {
		t.Fatalf("delete = %d", code)
	}
}
