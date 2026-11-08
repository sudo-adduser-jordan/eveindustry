package jobs

import (
	"testing"

	"github.com/sudo-adduser-jordan/eveindustry/internal/esi"
	"github.com/sudo-adduser-jordan/eveindustry/internal/store"
)

func TestDiagRegistration(t *testing.T) {
	r := NewRunner(Deps{})
	RegisterDiagJobs(r, DiagFlusherDeps{})
	for _, name := range []string{"flush-esi-diagnostics", "prune-esi-diagnostics"} {
		if _, ok := r.Jobs[name]; !ok {
			t.Fatalf("missing %s", name)
		}
	}
	entries := DiagSchedule(DiagFlusherDeps{})
	if len(entries) != 2 {
		t.Fatalf("entries = %d", len(entries))
	}
	if entries[1].At != "06:30" {
		t.Fatalf("prune at = %s", entries[1].At)
	}
}

func TestValidDiagEvent(t *testing.T) {
	if !validDiagEvent(map[string]any{"event_id": "x", "kind": "http_attempt", "occurred_at": "2026-11-08 10:00:00"}) {
		t.Fatal("valid rejected")
	}
	if validDiagEvent(map[string]any{"kind": "http_attempt"}) {
		t.Fatal("missing id accepted")
	}
}

func TestEndpointAggMath(t *testing.T) {
	rows := map[string]*store.EndpointMetricDelta{}
	addEndpoint(rows, map[string]any{
		"kind": "http_attempt", "occurred_at": "2026-11-08 10:15:00",
		"operation_key": "get /x", "http_status": 200, "reason": "ok",
		"dataset": "d", "source_identity": "s", "limiter_group": "g",
		"duration_ms": 120,
	}, intPtr(200))
	addEndpoint(rows, map[string]any{
		"kind": "http_attempt", "occurred_at": "2026-11-08 10:45:00",
		"operation_key": "get /x", "http_status": 500, "reason": "upstream_failure",
		"dataset": "d", "source_identity": "s", "limiter_group": "g",
		"duration_ms": 80,
	}, intPtr(500))
	// status is part of the dimension hash: two buckets
	if len(rows) != 2 {
		t.Fatalf("rows = %d", len(rows))
	}
	var totReq, totErr, totDur int64
	var maxDur int
	for _, r := range rows {
		totReq += r.Requests
		totErr += r.Errors
		totDur += r.DurationSum
		if r.DurationMax > maxDur {
			maxDur = r.DurationMax
		}
		if r.BucketAt.Hour() != 10 {
			t.Fatalf("bucket = %v", r.BucketAt)
		}
	}
	if totReq != 2 || totErr != 1 || totDur != 200 || maxDur != 120 {
		t.Fatalf("totals req=%d err=%d dur=%d max=%d", totReq, totErr, totDur, maxDur)
	}
}

func intPtr(n int) *int { v := n; return &v }

func TestBudgetAggMath(t *testing.T) {
	rows := map[string]*store.BudgetMetricDelta{}
	addBudget(rows, map[string]any{
		"kind": "http_attempt", "occurred_at": "2026-11-08 10:15:30",
		"legacy_remaining": 90, "limiter_group": "g", "token_remaining": 100, "token_used": 5,
	})
	addBudget(rows, map[string]any{"kind": "circuit_opened", "occurred_at": "2026-11-08 10:16:00"})
	if len(rows) == 0 {
		t.Fatal("no rows")
	}
	foundLegacy, foundToken := false, false
	for _, r := range rows {
		if r.Mechanism == "legacy" && r.Granularity == "hour" {
			foundLegacy = true
			if r.RemainingMin == nil || *r.RemainingMin != 90 {
				t.Fatalf("legacy = %+v", r)
			}
		}
		if r.Mechanism == "token" {
			foundToken = true
		}
	}
	if !foundLegacy || !foundToken {
		t.Fatalf("rows = %+v", rows)
	}
	_ = esi.CompareStreamIDs
}
