package notify

import (
	"database/sql"
	"testing"
	"time"

	"github.com/sudo-adduser-jordan/eveindustry/internal/industry"
	"github.com/sudo-adduser-jordan/eveindustry/internal/store"
)

func TestDetectIndustryCompleted(t *testing.T) {
	jobs := []industry.FinishedJob{
		{JobID: 1, CharacterID: 11, CharacterName: "A", ActivityName: "Manufacturing", ItemName: "Rifter"},
		{JobID: 2, CharacterID: 12, CharacterName: "B", ActivityName: "Invention", ItemName: "an item"},
	}
	msgs := DetectIndustryCompleted(jobs, map[int64]bool{11: true})
	if len(msgs) != 1 || msgs[0].DedupeKey != "industry:1:done" {
		t.Fatalf("msgs = %+v", msgs)
	}
}

func TestDetectPiExpiring(t *testing.T) {
	now := time.Date(2026, 11, 8, 12, 0, 0, 0, time.UTC)
	soon := now.Add(30 * time.Minute)
	past := now.Add(-time.Hour)
	planets := []PiPlanetWithChar{{
		CharacterID: 11, CharacterName: "A", PlanetID: 1, PlanetName: "Jita I",
		Pins: []PiPin{{Expiry: &soon}, {Expiry: &past}, {}},
	}}
	msgs := DetectPiExpiring(planets, map[int64]bool{}, time.Hour, now, now.Add(-time.Hour))
	// past is before since -> only the soon one counts
	if len(msgs) != 1 {
		t.Fatalf("msgs = %+v", msgs)
	}
	if msgs[0].DedupeKey == "" {
		t.Fatal("missing dedupe")
	}
	// outside horizon -> none
	msgs = DetectPiExpiring(planets, map[int64]bool{}, time.Minute, now, now.Add(-time.Hour))
	if len(msgs) != 0 {
		t.Fatalf("horizon = %+v", msgs)
	}
}

func TestDetectStructureWindows(t *testing.T) {
	now := time.Date(2026, 11, 8, 12, 0, 0, 0, time.UTC) // a Saturday
	_ = now
	monday := time.Date(2026, 11, 9, 12, 0, 0, 0, time.UTC) // Monday noon
	windows := []store.StructureWindow{{
		ID: 1, CorporationID: 7, SolarSystemName: "Jita", StructureType: "upwell",
		StructureName: "Fort", StartTimeUTC: "13:00",
	}}
	// opening Monday 13:00 within 2h horizon
	msgs := DetectStructureWindows(windows, false, 2*time.Hour, monday)
	if len(msgs) != 1 {
		t.Fatalf("opening = %+v", msgs)
	}
	// outside horizon
	msgs = DetectStructureWindows(windows, false, 30*time.Minute, monday)
	if len(msgs) != 0 {
		t.Fatalf("far = %+v", msgs)
	}
	// timer with no reinforce time -> none
	msgs = DetectStructureWindows(windows, true, 2*time.Hour, monday)
	if len(msgs) != 0 {
		t.Fatalf("timer = %+v", msgs)
	}
}

func TestIsQuiet(t *testing.T) {
	now := time.Date(2026, 11, 8, 23, 0, 0, 0, time.UTC)
	if IsQuiet(nil, now) {
		t.Fatal("nil")
	}
	q := func(start, end string) *store.QuietHours {
		return &store.QuietHours{
			Start: sql.NullString{String: start, Valid: true},
			End:   sql.NullString{String: end, Valid: true},
			Timezone: "UTC",
		}
	}
	if !IsQuiet(q("22:00", "07:00"), now) {
		t.Fatal("overnight")
	}
	if IsQuiet(q("08:00", "18:00"), now) {
		t.Fatal("daytime")
	}
}
