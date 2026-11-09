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

type stubMoonScans struct {
	systems map[int64]store.MoonScanSystem
	moons   map[int64][]store.MoonScanResult
	next    int64
}

func (s *stubMoonScans) MoonScanSystems(ctx context.Context, corpID int64) ([]store.MoonScanSystem, error) {
	var out []store.MoonScanSystem
	for _, r := range s.systems {
		if r.CorporationID.Valid && r.CorporationID.Int64 == corpID {
			out = append(out, r)
		}
	}
	return out, nil
}
func (s *stubMoonScans) MoonScanSystem(ctx context.Context, corpID int64, name string) (*store.MoonScanSystem, error) {
	for _, r := range s.systems {
		if r.CorporationID.Valid && r.CorporationID.Int64 == corpID && r.SystemName == name {
			r := r
			return &r, nil
		}
	}
	return nil, nil
}
func (s *stubMoonScans) UpsertMoonScanSystem(ctx context.Context, name string, corpID *int64, region int64, mode string) (*store.MoonScanSystem, error) {
	for _, r := range s.systems {
		sameCorp := (r.CorporationID.Valid && corpID != nil && r.CorporationID.Int64 == *corpID) ||
			(!r.CorporationID.Valid && corpID == nil)
		if r.SystemName == name && sameCorp {
			r.RegionID = region
			r.PricingMode = mode
			s.systems[r.ID] = r
			out := r
			return &out, nil
		}
	}
	s.next++
	r := store.MoonScanSystem{ID: s.next, SystemName: name, RegionID: region, PricingMode: mode, LastScannedAt: time.Now()}
	if corpID != nil {
		r.CorporationID = sql.NullInt64{Int64: *corpID, Valid: true}
	}
	if s.systems == nil {
		s.systems = map[int64]store.MoonScanSystem{}
	}
	s.systems[r.ID] = r
	return &r, nil
}
func (s *stubMoonScans) MoonScanMoons(ctx context.Context, systemID int64) ([]store.MoonScanResult, error) {
	return s.moons[systemID], nil
}
func (s *stubMoonScans) UpsertMoonScanResult(ctx context.Context, sysID int64, moon string, planet *string, grade, ores string, total int64) (int64, error) {
	s.next++
	r := store.MoonScanResult{ID: s.next, SystemID: sql.NullInt64{Int64: sysID, Valid: true},
		MoonName: moon, Grade: grade, OresJSON: ores, TotalValue: total, ScannedAt: time.Now()}
	if planet != nil {
		r.PlanetName = sql.NullString{String: *planet, Valid: true}
	}
	if s.moons == nil {
		s.moons = map[int64][]store.MoonScanResult{}
	}
	s.moons[sysID] = append(s.moons[sysID], r)
	return r.ID, nil
}

func moonScanTestDeps() Deps {
	stub := testAuth()
	corpID := int64(2001)
	stub.chars = []auth.Character{{
		CharacterID: 11, Name: "Main", IsMain: true, IsEsiActive: true,
		CorporationID: &corpID,
	}}
	return Deps{Sessions: stub, Users: stub,
		MoonScans: &stubMoonScans{},
		Market:    &stubMarket{},
		Industry:  &stubIndustry{},
		Reference: &stubReference{},
	}
}

const moonScanTSV = "Moon\tMoon Product\tQuantity\tTypeID\nAldali II - Moon 1\t\t\t\n\tZeolites\t0.5\t45490\n\tSylvite\t0.5\t45491\n"

func TestMoonScanStore(t *testing.T) {
	d := moonScanTestDeps()
	code, body := post(t, d, "/api/v1/moon-scans", `{"scan_data":`+quoteTSV(moonScanTSV)+`}`)
	if code != http.StatusCreated {
		t.Fatalf("store = %d %v", code, body)
	}
	data := body["data"].(map[string]any)
	if data["pricing_mode"] != "raw" {
		t.Fatalf("mode = %v", data)
	}
	moons := data["moons"].([]any)
	if len(moons) != 1 {
		t.Fatalf("moons = %v", data["moons"])
	}
	m := moons[0].(map[string]any)
	if m["grade"] != "D" || m["occupied"] != false {
		t.Fatalf("moon = %v", m)
	}
	if m["id"] == nil {
		t.Fatal("expected stored id")
	}
	ores := m["ores"].([]any)
	if len(ores) != 2 || ores[0].(map[string]any)["ore_units"] == 0 {
		t.Fatalf("ores = %v", ores)
	}
	if _, ok := body["meta"].(map[string]any)["freshness"]; !ok {
		t.Fatalf("freshness missing: %v", body["meta"])
	}
	// errors-only scan still 200 with errors
	code, body = post(t, d, "/api/v1/moon-scans", `{"scan_data":""}`)
	if code != http.StatusUnprocessableEntity {
		t.Fatalf("empty = %d %v", code, body)
	}
	if code, _ := post(t, d, "/api/v1/moon-scans", `{"scan_data":"x","pricing_mode":"nope"}`); code != http.StatusUnprocessableEntity {
		t.Fatalf("bad mode = %d", code)
	}
	if code, _ := post(t, d, "/api/v1/moon-scans", `{"scan_data":"x","region_id":1}`); code != http.StatusUnprocessableEntity {
		t.Fatalf("bad region = %d", code)
	}
}

func quoteTSV(s string) string {
	out := "\""
	for _, c := range s {
		switch c {
		case '"':
			out += "\\\""
		case '\n':
			out += "\\n"
		case '\t':
			out += "\\t"
		default:
			out += string(c)
		}
	}
	return out + "\""
}

func TestMoonScanSystems(t *testing.T) {
	d := moonScanTestDeps()
	if code, _ := post(t, d, "/api/v1/moon-scans", `{"scan_data":`+quoteTSV(moonScanTSV)+`}`); code != http.StatusCreated {
		t.Fatalf("store = %d", code)
	}
	code, body := get(t, d, "/api/v1/moon-scans/systems")
	if code != http.StatusOK {
		t.Fatalf("systems = %d %v", code, body)
	}
	items := body["data"].([]any)
	if len(items) != 1 || items[0].(map[string]any)["name"] != "Aldali" {
		t.Fatalf("items = %v", items)
	}
	code, body = get(t, d, "/api/v1/moon-scans/systems/Aldali")
	if code != http.StatusOK {
		t.Fatalf("system = %d %v", code, body)
	}
	meta := body["meta"].(map[string]any)
	sys := meta["system"].(map[string]any)
	if sys["name"] != "Aldali" || sys["pricing_mode"] != "raw" {
		t.Fatalf("sys meta = %v", sys)
	}
	if _, ok := meta["freshness"]; !ok {
		t.Fatalf("freshness missing: %v", meta)
	}
	// reprice to refined on the fly
	code, body = get(t, d, "/api/v1/moon-scans/systems/Aldali?pricing_mode=refined")
	if code != http.StatusOK {
		t.Fatalf("reprice = %d %v", code, body)
	}
	if body["meta"].(map[string]any)["system"].(map[string]any)["pricing_mode"] != "refined" {
		t.Fatalf("mode meta = %v", body["meta"])
	}
	if code, _ := get(t, d, "/api/v1/moon-scans/systems/Unknown"); code != http.StatusNotFound {
		t.Fatalf("unknown = %d", code)
	}
	if code, _ := get(t, d, "/api/v1/moon-scans/systems/Aldali?pricing_mode=nope"); code != http.StatusUnprocessableEntity {
		t.Fatalf("bad mode = %d", code)
	}
	// no corporation -> 422 on system, empty on systems
	d2 := moonScanTestDeps()
	d2.Sessions.(*stubAuth).chars = []auth.Character{{CharacterID: 11, Name: "X"}}
	d2.Users.(*stubAuth).chars = []auth.Character{{CharacterID: 11, Name: "X"}}
	if code, _ := get(t, d2, "/api/v1/moon-scans/systems/Aldali"); code != http.StatusUnprocessableEntity {
		t.Fatalf("no corp = %d", code)
	}
	if code, body := get(t, d2, "/api/v1/moon-scans/systems"); code != http.StatusOK || len(body["data"].([]any)) != 0 {
		t.Fatalf("no corp systems = %d %v", code, body)
	}
}
