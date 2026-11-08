package http

import (
	"context"
	"database/sql"
	"net/http"
	"testing"
	"time"

	"github.com/sudo-adduser-jordan/eveindustry/internal/store"
)

type stubDiag struct {
	summary *store.DiagSummary
	trends  *store.DiagTrends
	ends    *store.DiagEndpoints
	events  []store.DiagEvent
	batches []store.DiagBatch
	check   *store.DiagCheckpoint
}

func (s *stubDiag) DiagSummary(ctx context.Context, from, to time.Time, f store.DiagFilters) (*store.DiagSummary, error) {
	if s.summary != nil {
		return s.summary, nil
	}
	return &store.DiagSummary{Releases: []store.DiagRelease{}}, nil
}
func (s *stubDiag) DiagTrends(ctx context.Context, from, to time.Time, f store.DiagFilters) (*store.DiagTrends, error) {
	if s.trends != nil {
		return s.trends, nil
	}
	return &store.DiagTrends{Buckets: []store.DiagTrendBucket{}, Legacy: []store.DiagLegacyPoint{}, TokenGroups: []store.DiagTokenGroup{}}, nil
}
func (s *stubDiag) DiagEndpoints(ctx context.Context, from, to time.Time, f store.DiagFilters, lim int) (*store.DiagEndpoints, error) {
	if s.ends != nil {
		return s.ends, nil
	}
	return &store.DiagEndpoints{Operations: []store.DiagOperation{}, Reasons: []store.DiagReason{}}, nil
}
func (s *stubDiag) DiagEvents(ctx context.Context, from, to time.Time, f store.DiagFilters, before *int64, per int) ([]store.DiagEvent, bool, error) {
	if before != nil {
		var out []store.DiagEvent
		for _, e := range s.events {
			if e.ID < *before {
				out = append(out, e)
			}
		}
		if len(out) > per {
			return out[:per], true, nil
		}
		return out, false, nil
	}
	if len(s.events) > per {
		return s.events[:per], true, nil
	}
	return s.events, false, nil
}
func (s *stubDiag) DiagBatches(ctx context.Context, from, to time.Time, f store.DiagFilters, lim int) ([]store.DiagBatch, error) {
	return s.batches, nil
}
func (s *stubDiag) DiagCheckpoint(ctx context.Context) (*store.DiagCheckpoint, error) {
	return s.check, nil
}

func diagTestDeps() Deps {
	d := adminTestDeps()
	at := time.Date(2026, 11, 8, 10, 0, 0, 0, time.UTC)
	pct := 12.5
	d.Diag = &stubDiag{
		summary: &store.DiagSummary{UpstreamRequests: 100, Errors: 5, ErrorPercent: &pct,
			Status404: 5, Releases: []store.DiagRelease{{Release: "v1", FirstSeen: at}}},
		trends: &store.DiagTrends{Granularity: "hour",
			Buckets: []store.DiagTrendBucket{{At: at, Requests: 100, Errors: 5}}},
		ends: &store.DiagEndpoints{TotalRequests: 100, TotalErrors: 5,
			Operations: []store.DiagOperation{{Operation: "get /x", Requests: 100, Errors: 5}}},
		events: []store.DiagEvent{
			{ID: 3, OccurredAt: at, Kind: "http_attempt", Reason: "not_found"},
			{ID: 2, OccurredAt: at, Kind: "http_attempt", Reason: "ok"},
			{ID: 1, OccurredAt: at, Kind: "cache_hit", Reason: "cache"},
		},
		batches: []store.DiagBatch{{Fingerprint: "fp", Operation: "op", Occurrences: 3}},
	}
	return d
}

func TestDiagReports(t *testing.T) {
	d := diagTestDeps()
	code, body := get(t, d, "/api/v1/admin/esi-diagnostics/summary")
	if code != http.StatusOK {
		t.Fatalf("summary = %d %v", code, body)
	}
	data := body["data"].(map[string]any)
	if data["upstream_requests"] != 100.0 || data["error_percent"] != 12.5 || data["status_404"] != 5.0 {
		t.Fatalf("summary = %v", data)
	}
	if _, ok := data["from"]; !ok {
		t.Fatalf("from missing: %v", data)
	}
	code, body = get(t, d, "/api/v1/admin/esi-diagnostics/trends")
	if code != http.StatusOK || body["data"].(map[string]any)["granularity"] != "hour" {
		t.Fatalf("trends = %d %v", code, body)
	}
	code, body = get(t, d, "/api/v1/admin/esi-diagnostics/endpoints")
	if code != http.StatusOK || body["data"].(map[string]any)["total_requests"] != 100.0 {
		t.Fatalf("endpoints = %d %v", code, body)
	}
	code, body = get(t, d, "/api/v1/admin/esi-diagnostics/batches")
	if code != http.StatusOK || len(body["data"].([]any)) != 1 {
		t.Fatalf("batches = %d %v", code, body)
	}
	// events default page 50: all 3, no cursor
	code, body = get(t, d, "/api/v1/admin/esi-diagnostics/events")
	if code != http.StatusOK || len(body["data"].([]any)) != 3 {
		t.Fatalf("events = %d %v", code, body)
	}
	if body["meta"].(map[string]any)["next_cursor"] != nil {
		t.Fatalf("cursor = %v", body["meta"])
	}
	// per_page=2 -> cursor 2
	code, body = get(t, d, "/api/v1/admin/esi-diagnostics/events?per_page=2")
	if code != http.StatusOK || len(body["data"].([]any)) != 2 {
		t.Fatalf("paged = %d %v", code, body)
	}
	if body["meta"].(map[string]any)["next_cursor"] != 2.0 {
		t.Fatalf("cursor = %v", body["meta"])
	}
	// before_id=2 -> only id 1
	code, body = get(t, d, "/api/v1/admin/esi-diagnostics/events?before_id=2")
	if code != http.StatusOK || len(body["data"].([]any)) != 1 {
		t.Fatalf("before = %d %v", code, body)
	}
	// bad range
	if code, _ := get(t, d, "/api/v1/admin/esi-diagnostics/summary?from=2026-11-08&to=2026-11-01"); code != http.StatusUnprocessableEntity {
		t.Fatalf("reversed = %d", code)
	}
	if code, _ := get(t, d, "/api/v1/admin/esi-diagnostics/summary?from=2020-01-01&to=2026-11-08"); code != http.StatusUnprocessableEntity {
		t.Fatalf("too wide = %d", code)
	}
	if code, _ := get(t, d, "/api/v1/admin/esi-diagnostics/summary?reason=nope"); code != http.StatusUnprocessableEntity {
		t.Fatalf("bad reason = %d", code)
	}
	// non-admin -> 403
	d2 := diagTestDeps()
	d2.Sessions.(*stubAuth).user.IsAdmin = false
	if code, _ := get(t, d2, "/api/v1/admin/esi-diagnostics/summary"); code != http.StatusForbidden {
		t.Fatalf("gate = %d", code)
	}
}

func TestDiagHealth(t *testing.T) {
	d := diagTestDeps()
	code, body := get(t, d, "/api/v1/admin/esi-diagnostics/health")
	if code != http.StatusOK {
		t.Fatalf("health = %d %v", code, body)
	}
	data := body["data"].(map[string]any)
	if data["enabled"] != false || data["healthy"] != true {
		t.Fatalf("health = %v", data)
	}
	var _ = sql.NullString{}
}
