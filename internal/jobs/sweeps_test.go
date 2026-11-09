package jobs

import (
	"testing"
	"time"
)

func TestSweepRegistration(t *testing.T) {
	r := NewRunner(Deps{})
	RegisterSweepJobs(r, HandlerDeps{})
	for _, name := range []string{"fetch-region-orders", "fetch-region-order-pages", "complete-market-order-sweep"} {
		def, ok := r.Jobs[name]
		if !ok {
			t.Fatalf("missing %s", name)
		}
		if def.Queue != QueueMarket {
			t.Fatalf("%s queue = %s", name, def.Queue)
		}
	}
	entries := SweepSchedule(HandlerDeps{}, 10000002)
	if len(entries) != 2 {
		t.Fatalf("entries = %d", len(entries))
	}
	if entries[0].Every != 15*time.Minute || entries[1].Every != time.Hour {
		t.Fatalf("cadences = %v %v", entries[0].Every, entries[1].Every)
	}
}

func TestRefreshRegistration(t *testing.T) {
	r := NewRunner(Deps{})
	RegisterRefreshJobs(r, HandlerDeps{})
	for _, name := range []string{"refresh-character-profile", "refresh-asset-data", "refresh-industry-data", "refresh-dashboard-stats"} {
		if _, ok := r.Jobs[name]; !ok {
			t.Fatalf("missing %s", name)
		}
	}
	entries := RefreshSchedule(HandlerDeps{})
	if len(entries) != 4 {
		t.Fatalf("entries = %d", len(entries))
	}
}

func TestPrecomputeRegistration(t *testing.T) {
	r := NewRunner(Deps{})
	RegisterPrecomputeJobs(r, HandlerDeps{})
	if _, ok := r.Jobs["manufacturing-precompute-costs"]; !ok {
		t.Fatal("missing precompute job")
	}
	entries := PrecomputeSchedule(HandlerDeps{})
	if len(entries) != 1 || entries[0].Every.String() != (4*time.Hour).String() {
		t.Fatalf("schedule = %+v", entries)
	}
}
