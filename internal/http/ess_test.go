package http

import (
	"context"
	"database/sql"
	"net/http"
	"testing"
	"time"

	"github.com/sudo-adduser-jordan/eveindustry/internal/auth"
	"github.com/sudo-adduser-jordan/eveindustry/internal/store"
)

type stubEss struct {
	systems map[int64]store.EssSystem
	payouts map[int64][]store.EssPayout
	next    int64
}

func (s *stubEss) EssSystems(ctx context.Context, corpID int64, lim, off int) ([]store.EssSystem, int, error) {
	var out []store.EssSystem
	for _, r := range s.systems {
		if r.CorporationID == corpID {
			out = append(out, r)
		}
	}
	return out, len(out), nil
}
func (s *stubEss) EssSystem(ctx context.Context, id int64) (*store.EssSystem, error) {
	if r, ok := s.systems[id]; ok {
		r := r
		return &r, nil
	}
	return nil, nil
}
func (s *stubEss) UpsertEssSystem(ctx context.Context, e store.EssSystem) (*store.EssSystem, error) {
	for _, r := range s.systems {
		if r.CorporationID == e.CorporationID && r.SolarSystemID == e.SolarSystemID {
			e.ID = r.ID
			s.systems[r.ID] = e
			out := e
			return &out, nil
		}
	}
	s.next++
	e.ID = s.next
	s.systems[e.ID] = e
	out := e
	return &out, nil
}
func (s *stubEss) UpdateEssSystem(ctx context.Context, e store.EssSystem) (*store.EssSystem, error) {
	r := s.systems[e.ID]
	r.CycleMinutes = e.CycleMinutes
	r.CycleAnchorAt = e.CycleAnchorAt
	r.Notes = e.Notes
	s.systems[e.ID] = r
	return &r, nil
}
func (s *stubEss) DeleteEssSystem(ctx context.Context, id int64) error {
	delete(s.systems, id)
	return nil
}
func (s *stubEss) EssPayouts(ctx context.Context, sysID int64, lim int) ([]store.EssPayout, error) {
	out := s.payouts[sysID]
	if len(out) > lim {
		out = out[:lim]
	}
	if out == nil {
		out = []store.EssPayout{}
	}
	return out, nil
}
func (s *stubEss) CreateEssPayout(ctx context.Context, p store.EssPayout) (*store.EssPayout, error) {
	s.next++
	p.ID = s.next
	s.payouts[p.SystemID] = append([]store.EssPayout{p}, s.payouts[p.SystemID]...)
	out := p
	return &out, nil
}

type stubWindows struct {
	rows map[int64]store.StructureWindow
	next int64
}

func (s *stubWindows) StructureWindows(ctx context.Context, corpID int64, lim, off int) ([]store.StructureWindow, int, error) {
	var out []store.StructureWindow
	for _, r := range s.rows {
		if r.CorporationID == corpID {
			out = append(out, r)
		}
	}
	return out, len(out), nil
}
func (s *stubWindows) StructureWindow(ctx context.Context, id int64) (*store.StructureWindow, error) {
	if r, ok := s.rows[id]; ok {
		r := r
		return &r, nil
	}
	return nil, nil
}
func (s *stubWindows) CreateStructureWindow(ctx context.Context, w store.StructureWindow) (*store.StructureWindow, error) {
	s.next++
	w.ID = s.next
	s.rows[w.ID] = w
	out := w
	return &out, nil
}
func (s *stubWindows) UpdateStructureWindow(ctx context.Context, w store.StructureWindow) (*store.StructureWindow, error) {
	r := s.rows[w.ID]
	r.SolarSystemID = w.SolarSystemID
	r.SolarSystemName = w.SolarSystemName
	r.StructureType = w.StructureType
	r.StructureName = w.StructureName
	r.DayOfWeek = w.DayOfWeek
	r.StartTimeUTC = w.StartTimeUTC
	r.DurationMinutes = w.DurationMinutes
	r.ReinforceExitAt = w.ReinforceExitAt
	r.Notes = w.Notes
	s.rows[w.ID] = r
	return &r, nil
}
func (s *stubWindows) DeleteStructureWindow(ctx context.Context, id int64) error {
	delete(s.rows, id)
	return nil
}

func essTestDeps() Deps {
	stub := testAuth()
	corpID := int64(2001)
	stub.chars = []auth.Character{{
		CharacterID: 11, Name: "Main", IsMain: true, IsEsiActive: true, CorporationID: &corpID,
	}}
	anchor := time.Date(2026, 11, 8, 10, 0, 0, 0, time.UTC)
	return Deps{Sessions: stub, Users: stub,
		Ess: &stubEss{
			systems: map[int64]store.EssSystem{
				1: {ID: 1, CorporationID: 2001, SolarSystemID: 30000142, SolarSystemName: "Jita",
					CycleMinutes: 165, CycleAnchorAt: anchor},
				9: {ID: 9, CorporationID: 9999, SolarSystemID: 30000144, SolarSystemName: "Other",
					CycleMinutes: 165, CycleAnchorAt: anchor},
			},
			payouts: map[int64][]store.EssPayout{
				1: {{ID: 5, SystemID: 1, OccurredAt: anchor, AmountISK: sql.NullFloat64{Float64: 100, Valid: true}}},
			},
			next: 100,
		},
		Windows: &stubWindows{
			rows: map[int64]store.StructureWindow{
				1: {ID: 1, CorporationID: 2001, SolarSystemID: 30000142, SolarSystemName: "Jita",
					StructureType: "upwell", StructureName: "Fort", StartTimeUTC: "12:00", DurationMinutes: 60},
				9: {ID: 9, CorporationID: 9999, SolarSystemID: 30000144, SolarSystemName: "Other",
					StructureType: "upwell", StructureName: "X", StartTimeUTC: "12:00", DurationMinutes: 60},
			},
			next: 100,
		},
		Reference: &stubReference{},
	}
}

func TestEssIndex(t *testing.T) {
	d := essTestDeps()
	code, body := get(t, d, "/api/v1/corporation/ess")
	if code != http.StatusOK {
		t.Fatalf("index = %d %v", code, body)
	}
	items := body["data"].([]any)
	if len(items) != 1 {
		t.Fatalf("items = %v", items)
	}
	it := items[0].(map[string]any)
	if it["next_payout_at"] == nil || it["seconds_until_next_payout"] == nil {
		t.Fatalf("payout math missing: %v", it)
	}
	if len(it["recent_payouts"].([]any)) != 1 {
		t.Fatalf("payouts = %v", it["recent_payouts"])
	}
	// no corp -> 403
	d2 := essTestDeps()
	d2.Sessions.(*stubAuth).chars = []auth.Character{{CharacterID: 11, Name: "X"}}
	d2.Users.(*stubAuth).chars = []auth.Character{{CharacterID: 11, Name: "X"}}
	if code, _ := get(t, d2, "/api/v1/corporation/ess"); code != http.StatusForbidden {
		t.Fatalf("no corp = %d", code)
	}
}

func TestEssWrites(t *testing.T) {
	d := essTestDeps()
	code, body := post(t, d, "/api/v1/corporation/ess", `{"solar_system_id":30000144,"cycle_minutes":120}`)
	if code != http.StatusCreated {
		t.Fatalf("store = %d %v", code, body)
	}
	if body["data"].(map[string]any)["cycle_minutes"] != 120.0 {
		t.Fatalf("cycle = %v", body["data"])
	}
	// invalid cycle -> 422
	if code, _ := post(t, d, "/api/v1/corporation/ess", `{"solar_system_id":1,"cycle_minutes":0}`); code != http.StatusUnprocessableEntity {
		t.Fatalf("bad cycle accepted")
	}
	// update foreign -> 404
	if code, _ := putJSON(t, d, "/api/v1/corporation/ess/9", `{"cycle_minutes":60}`); code != http.StatusNotFound {
		t.Fatalf("foreign update = %d", code)
	}
	if code, body := putJSON(t, d, "/api/v1/corporation/ess/1", `{"cycle_minutes":60}`); code != http.StatusOK {
		t.Fatalf("update = %d %v", code, body)
	} else if body["data"].(map[string]any)["cycle_minutes"] != 60.0 {
		t.Fatalf("updated = %v", body["data"])
	}
	// payout
	code, body = post(t, d, "/api/v1/corporation/ess/1/payouts", `{"amount_isk":50}`)
	if code != http.StatusCreated {
		t.Fatalf("payout = %d %v", code, body)
	}
	if len(body["data"].(map[string]any)["recent_payouts"].([]any)) != 2 {
		t.Fatalf("payouts = %v", body["data"])
	}
	if code, _ := post(t, d, "/api/v1/corporation/ess/9/payouts", `{}`); code != http.StatusNotFound {
		t.Fatalf("foreign payout = %d", code)
	}
	// destroy
	if code, _ := deleteReq(t, d, "/api/v1/corporation/ess/9"); code != http.StatusNotFound {
		t.Fatalf("foreign delete = %d", code)
	}
	if code, _ := deleteReq(t, d, "/api/v1/corporation/ess/1"); code != http.StatusNoContent {
		t.Fatalf("delete = %d", code)
	}
}

func TestEssNextPayout(t *testing.T) {
	anchor := time.Date(2026, 11, 8, 10, 0, 0, 0, time.UTC)
	// at anchor -> anchor
	if got := essNextPayout(anchor, 165, anchor); !got.Equal(anchor) {
		t.Fatalf("at anchor = %v", got)
	}
	// 1s after anchor with 60m cycle -> anchor + 60m
	if got := essNextPayout(anchor, 60, anchor.Add(time.Second)); !got.Equal(anchor.Add(time.Hour)) {
		t.Fatalf("after anchor = %v", got)
	}
	// cycle clamped to >= 1
	if got := essNextPayout(anchor, 0, anchor.Add(time.Second)); !got.Equal(anchor.Add(time.Minute)) {
		t.Fatalf("clamp = %v", got)
	}
}

func TestWindowCRUD(t *testing.T) {
	d := essTestDeps()
	code, body := get(t, d, "/api/v1/corporation/structure-vulnerability")
	if code != http.StatusOK {
		t.Fatalf("index = %d %v", code, body)
	}
	if len(body["data"].([]any)) != 1 {
		t.Fatalf("items = %v", body["data"])
	}
	code, body = post(t, d, "/api/v1/corporation/structure-vulnerability", `{"solar_system_id":30000142,"structure_type":"skyhook","structure_name":"Hook","start_time_utc":"08:30","duration_minutes":30}`)
	if code != http.StatusCreated {
		t.Fatalf("store = %d %v", code, body)
	}
	if body["data"].(map[string]any)["start_time_utc"] != "08:30" {
		t.Fatalf("start = %v", body["data"])
	}
	if code, _ := post(t, d, "/api/v1/corporation/structure-vulnerability", `{"solar_system_id":1,"structure_type":"nope"}`); code != http.StatusUnprocessableEntity {
		t.Fatalf("bad type accepted")
	}
	if code, _ := putJSON(t, d, "/api/v1/corporation/structure-vulnerability/9", `{"solar_system_id":1,"structure_type":"upwell","structure_name":"X","start_time_utc":"01:00","duration_minutes":60}`); code != http.StatusNotFound {
		t.Fatalf("foreign update = %d", code)
	}
	if code, _ := putJSON(t, d, "/api/v1/corporation/structure-vulnerability/1", `{"solar_system_id":1,"structure_type":"upwell","structure_name":"Y","start_time_utc":"01:00","duration_minutes":60}`); code != http.StatusOK {
		t.Fatalf("update = %d", code)
	}
	if code, _ := deleteReq(t, d, "/api/v1/corporation/structure-vulnerability/9"); code != http.StatusNotFound {
		t.Fatalf("foreign delete = %d", code)
	}
	if code, _ := deleteReq(t, d, "/api/v1/corporation/structure-vulnerability/1"); code != http.StatusNoContent {
		t.Fatalf("delete = %d", code)
	}
	if code, _ := get(t, d, "/api/v1/corporation/structure-vulnerability"); code != http.StatusOK {
		t.Fatalf("index2 = %d", code)
	}
}
