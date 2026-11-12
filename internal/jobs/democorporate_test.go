package jobs

import (
	"context"
	"testing"
	"time"

	"github.com/sudo-adduser-jordan/eveindustry/internal/esi"
	"github.com/sudo-adduser-jordan/eveindustry/internal/store"
)

func demoCorpChar(uid, charID, corpID int64, name string, main bool) store.DemoCharacter {
	roles := []string{"Director", "Factory_Manager", "Accountant", "Junior_Accountant", "Project_Manager", "Station_Manager"}
	if !main {
		roles = nil
	}
	return store.DemoCharacter{
		CharacterID: charID, UserID: uid, Name: name,
		CorporationID: corpID, CorpName: "Demo Corp",
		IsMain: main, IsDirectorCEO: main, Roles: roles,
		CachedLoc: "Jita", CachedTrain: "Advanced Mass Reactions 4",
	}
}

func TestDemoCorporateSeedsAllTabs(t *testing.T) {
	ctx := context.Background()
	s, _ := demoTestDB(t)
	h := HandlerDeps{DB: s}
	uid, err := s.EnsureDemoUser(ctx, "demo@eve-industrialist.invalid")
	if err != nil {
		t.Fatal(err)
	}
	if err := s.WipeUserContent(ctx, uid); err != nil {
		t.Fatal(err)
	}
	mainChar := int64(99000000001)
	mainCorp := int64(99100000001)
	if err := s.CreateDemoCharacter(ctx, demoCorpChar(uid, mainChar, mainCorp, "Demo Main", true)); err != nil {
		t.Fatal(err)
	}
	if err := s.CreateDemoCharacter(ctx, demoCorpChar(uid, mainChar+1, mainCorp, "Demo Alt", false)); err != nil {
		t.Fatal(err)
	}
	seedDemoCorporateData(ctx, h, uid, mainChar, mainCorp, "Demo Corp")

	dcfg, err := s.DonationConfigForCorps(ctx, []int64{mainCorp})
	if err != nil || dcfg == nil || !dcfg.IsActive || dcfg.CharacterID != mainChar {
		t.Fatalf("donation config = %+v %v", dcfg, err)
	}
	ccfg, err := s.CorpConfigForCorps(ctx, []int64{mainCorp})
	if err != nil || ccfg == nil || !ccfg.IsActive || ccfg.CharacterID != mainChar {
		t.Fatalf("corp config = %+v %v", ccfg, err)
	}
	progs, err := s.CorpPrograms(ctx, mainCorp)
	if err != nil || len(progs) < 3 {
		t.Fatalf("corp programs = %d %v", len(progs), err)
	}
	windows, total, err := s.StructureWindows(ctx, mainCorp, 10, 0)
	if err != nil || total < 3 || len(windows) < 3 {
		t.Fatalf("windows = %d total=%d %v", len(windows), total, err)
	}
	seenUpwell, seenSkyhook := false, false
	for _, w := range windows {
		if w.StructureType == "upwell" {
			seenUpwell = true
		}
		if w.StructureType == "skyhook" {
			seenSkyhook = true
		}
	}
	if !seenUpwell || !seenSkyhook {
		t.Fatalf("window types upwell=%v skyhook=%v", seenUpwell, seenSkyhook)
	}

	now := time.Date(2026, 11, 8, 12, 0, 0, 0, time.UTC)
	r := &esi.DemoResponder{
		Chars: func(ctx context.Context) ([]esi.DemoCharacter, error) {
			return []esi.DemoCharacter{
				{ID: mainChar, Name: "Demo Main", CorporationID: mainCorp, CorpName: "Demo Corp"},
				{ID: mainChar + 1, Name: "Demo Alt", CorporationID: mainCorp, CorpName: "Demo Corp"},
			}, nil
		},
		Now: func() time.Time { return now },
	}

	chars, _ := r.Chars(ctx)
	var members []esi.DemoCharacter
	for _, m := range chars {
		if m.CorporationID == mainCorp {
			members = append(members, m)
		}
	}
	if len(r.DemoProjects(mainCorp, members)) < 3 {
		t.Fatal("demo projects missing")
	}
	if len(r.DemoProjectContributors(mainCorp, "demo-p1", members)) == 0 {
		t.Fatal("demo contributors missing")
	}
	st, payload, _ := r.Respond(ctx, "get", "/corporations/99100000001/wallets/1/journal/", nil, map[string]string{}, nil)
	if st != 200 {
		t.Fatalf("journal status = %d", st)
	}
	foundDonation := false
	for _, item := range payload.([]any) {
		if m, ok := item.(map[string]any); ok && m["ref_type"] == "player_donation" {
			foundDonation = true
		}
	}
	if !foundDonation {
		t.Fatal("no player_donation in demo journal")
	}
}
