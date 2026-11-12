package jobs

import (
	"context"
	"testing"
)

func TestDemoPlanningSeedsIndustryTabs(t *testing.T) {
	ctx := context.Background()
	s, ind := demoTestDB(t)
	h := HandlerDeps{DB: s, Industry: ind}
	if err := s.SeedDemoIndustry(ctx, buildDemoIndustrySeed()); err != nil {
		t.Fatal(err)
	}
	uid, err := s.EnsureDemoUser(ctx, "demo@eve-industrialist.invalid")
	if err != nil {
		t.Fatal(err)
	}
	if err := s.WipeUserContent(ctx, uid); err != nil {
		t.Fatal(err)
	}
	seedDemoPlanningData(ctx, h, uid)

	configs, err := s.AllStationConfigs(ctx, uid, nil)
	if err != nil {
		t.Fatal(err)
	}
	byActivity := map[string]bool{}
	for _, c := range configs {
		byActivity[c.ActivityType] = true
	}
	for _, want := range []string{"manufacturing", "invention", "reaction"} {
		if !byActivity[want] {
			t.Fatalf("missing station config for %s in %+v", want, configs)
		}
	}

	inv, _, err := s.InventionPlans(ctx, uid, 10, 0)
	if err != nil {
		t.Fatal(err)
	}
	if len(inv) < 2 {
		t.Fatalf("invention plans = %d, want >= 2", len(inv))
	}

	rp, _, err := s.ReactionPlans(ctx, uid, 10, 0)
	if err != nil {
		t.Fatal(err)
	}
	if len(rp) < 2 {
		t.Fatalf("reaction plans = %d, want >= 2", len(rp))
	}

	progs, err := s.BuildPrograms(ctx, uid)
	if err != nil {
		t.Fatal(err)
	}
	if len(progs) < 3 {
		t.Fatalf("build programs = %d, want >= 3", len(progs))
	}
	seenReaction := false
	for _, p := range progs {
		if p.ProgramType == "reaction" {
			seenReaction = true
		}
	}
	if !seenReaction {
		t.Fatal("no reaction build program seeded")
	}

	if ok, err := ind.HasActivity(ctx, 99011, 8); err != nil || !ok {
		t.Fatalf("missing invention activity on demo T1 blueprint 99011: %v %v", ok, err)
	}
	t2mfg, err := ind.ActivityByBlueprint(ctx, 99311, 1)
	if err != nil || t2mfg == nil {
		t.Fatalf("missing manufacturing activity for demo T2 blueprint 99311: %v", err)
	}
	prod, err := ind.ActivityProducingType(ctx, 587, 1)
	if err != nil || prod == nil {
		t.Fatalf("ActivityProducingType(587, mfg) failed on sqlite: %v %+v", err, prod)
	}
	invAct, err := ind.ActivityProducingType(ctx, 99311, 8)
	if err != nil || invAct == nil {
		t.Fatalf("ActivityProducingType(99311, inv) failed on sqlite: %v %+v", err, invAct)
	}

	pre, total, err := ind.PrecomputedCosts(ctx, []int{587}, 10000002, 0, 10, 0)
	if err != nil {
		t.Fatal(err)
	}
	if total == 0 || len(pre) == 0 {
		t.Fatal("no precomputed manufacturing costs for demo Rifter")
	}
	preT2, totalT2, err := ind.PrecomputedCosts(ctx, []int{99301, 99302}, 10000002, 0, 10, 0)
	if err != nil {
		t.Fatal(err)
	}
	if totalT2 < 2 || len(preT2) < 2 {
		t.Fatalf("no precomputed manufacturing costs for demo T2 items: %d %+v", totalT2, preT2)
	}
}
