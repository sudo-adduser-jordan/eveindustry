package http

import (
	"net/http"
	"time"

	"github.com/labstack/echo/v4"

	"github.com/sudo-adduser-jordan/eveindustry/internal/store"
)

var diagReasons = map[string]bool{
	"ok": true, "not_modified": true, "missing_scope": true, "missing_role": true,
	"corporation_mismatch": true, "credential": true, "forbidden": true,
	"not_found": true, "invalid_id_batch": true, "throttled_420": true,
	"throttled_429": true, "upstream_failure": true, "client_error": true,
	"connection": true, "error_limited": true, "rate_limited": true,
	"circuit_open": true, "circuit_expired_inferred": true, "cache": true, "unknown": true,
}

func registerDiagnostics(v1 *echo.Group, d Deps) {
	if d.Diag == nil || d.Users == nil {
		return
	}
	g := v1.Group("/admin", requireAdminDeps(d))
	g.GET("/esi-diagnostics/summary", handleDiagSummary(d))
	g.GET("/esi-diagnostics/trends", handleDiagTrends(d))
	g.GET("/esi-diagnostics/endpoints", handleDiagEndpoints(d))
	g.GET("/esi-diagnostics/events", handleDiagEvents(d))
	g.GET("/esi-diagnostics/batches", handleDiagBatches(d))
	g.GET("/esi-diagnostics/health", handleDiagHealth(d))
}

type diagRange struct {
	from, to time.Time
	filters  store.DiagFilters
}

func parseDiagRange(c echo.Context) (diagRange, bool) {
	var r diagRange
	verrs := validationErrors{}
	qp := c.QueryParams()
	now := time.Now().UTC()
	r.to = now
	if raw := firstParam(qp["to"]); raw != "" {
		t, err := parseDiagTime(raw)
		if err != nil {
			verrs.add("to", "The to field must be a valid date.")
			verrs.invalid(c)
			return r, false
		}
		r.to = t
	}
	r.from = r.to.AddDate(0, 0, -1)
	if raw := firstParam(qp["from"]); raw != "" {
		t, err := parseDiagTime(raw)
		if err != nil {
			verrs.add("from", "The from field must be a valid date.")
			verrs.invalid(c)
			return r, false
		}
		r.from = t
	}
	if !r.from.Before(r.to) {
		verrs.add("from", "The start of the range must be before its end.")
		verrs.invalid(c)
		return r, false
	}
	if r.to.Sub(r.from) > 31*24*time.Hour {
		verrs.add("from", "The range may not exceed 31 days.")
		verrs.invalid(c)
		return r, false
	}
	if v := firstParam(qp["operation"]); v != "" {
		if len([]rune(v)) > 160 {
			verrs.add("operation", "The operation is invalid.")
		} else {
			r.filters.Operation = &v
		}
	}
	if v := firstParam(qp["dataset"]); v != "" {
		if len([]rune(v)) > 48 {
			verrs.add("dataset", "The dataset is invalid.")
		} else {
			r.filters.Dataset = &v
		}
	}
	if raw := firstParam(qp["status"]); raw != "" {
		n, err := parseIntProg(raw)
		if err != nil || n < 100 || n > 599 {
			verrs.add("status", "The status field must be an integer.")
		} else {
			r.filters.Status = &n
		}
	}
	if v := firstParam(qp["reason"]); v != "" {
		if !diagReasons[v] {
			verrs.add("reason", "The selected reason is invalid.")
		} else {
			r.filters.Reason = &v
		}
	}
	if v := firstParam(qp["group"]); v != "" {
		if len([]rune(v)) > 64 {
			verrs.add("group", "The group is invalid.")
		} else {
			r.filters.Group = &v
		}
	}
	if raw := firstParam(qp["character_id"]); raw != "" {
		n, err := parseIntProg(raw)
		if err != nil || n < 1 {
			verrs.add("character_id", "The character id field must be an integer.")
		} else {
			v := int64(n)
			r.filters.CharacterID = &v
		}
	}
	if raw := firstParam(qp["corporation_id"]); raw != "" {
		n, err := parseIntProg(raw)
		if err != nil || n < 1 {
			verrs.add("corporation_id", "The corporation id field must be an integer.")
		} else {
			v := int64(n)
			r.filters.CorporationID = &v
		}
	}
	if len(verrs) > 0 {
		verrs.invalid(c)
		return r, false
	}
	return r, true
}

func parseDiagTime(s string) (time.Time, error) {
	return parseEssTime(s)
}

func isoDiagTime(t time.Time) string { return t.UTC().Format(time.RFC3339) }

func diagSummaryShape(from, to time.Time, s *store.DiagSummary) map[string]any {
	rels := make([]any, 0, len(s.Releases))
	for _, r := range s.Releases {
		rels = append(rels, map[string]any{"release": r.Release, "first_seen": isoDiagTime(r.FirstSeen)})
	}
	return map[string]any{
		"from": isoDiagTime(from), "to": isoDiagTime(to),
		"upstream_requests": s.UpstreamRequests, "errors": s.Errors,
		"error_percent": s.ErrorPercent, "status_401": s.Status401,
		"status_403": s.Status403, "status_404": s.Status404,
		"throttled_420": s.Throttled420, "throttled_429": s.Throttled429,
		"other_errors": s.OtherErrors, "connection_failures": s.ConnectionFailures,
		"local_deferrals": s.LocalDeferrals, "cache_hits": s.CacheHits,
		"cache_misses": s.CacheMisses, "cache_revalidations": s.CacheRevalidations,
		"protection_episodes":    s.ProtectionEpisodes,
		"observed_pause_seconds": s.ObservedPauseSeconds,
		"min_legacy_remaining":   s.MinLegacyRemaining, "releases": rels,
	}
}

func handleDiagSummary(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		r, ok := parseDiagRange(c)
		if !ok {
			return errHandled
		}
		s, err := d.Diag.DiagSummary(c.Request().Context(), r.from, r.to, r.filters)
		if err != nil {
			return dbError(c, err)
		}
		return Success(c, http.StatusOK, diagSummaryShape(r.from, r.to, s), nil)
	}
}

func handleDiagTrends(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		r, ok := parseDiagRange(c)
		if !ok {
			return errHandled
		}
		t, err := d.Diag.DiagTrends(c.Request().Context(), r.from, r.to, r.filters)
		if err != nil {
			return dbError(c, err)
		}
		buckets := make([]any, 0, len(t.Buckets))
		for _, b := range t.Buckets {
			buckets = append(buckets, map[string]any{
				"at": isoDiagTime(b.At), "requests": b.Requests, "errors": b.Errors,
				"status_401": b.Status401, "status_403": b.Status403, "status_404": b.Status404,
				"throttled_420": b.Throttled420, "throttled_429": b.Throttled429,
				"local_deferrals": b.LocalDeferrals, "cache_hits": b.CacheHits,
			})
		}
		legacy := make([]any, 0, len(t.Legacy))
		for _, l := range t.Legacy {
			legacy = append(legacy, map[string]any{
				"at": isoDiagTime(l.At), "remaining_min": l.RemainingMin,
				"protection_episodes": l.ProtectionEpisodes,
			})
		}
		toks := make([]any, 0, len(t.TokenGroups))
		for _, g := range t.TokenGroups {
			pts := make([]any, 0, len(g.Points))
			for _, p := range g.Points {
				pts = append(pts, map[string]any{
					"at": isoDiagTime(p.At), "remaining_min": p.RemainingMin, "used": p.Used,
				})
			}
			toks = append(toks, map[string]any{"group": g.Group, "points": pts})
		}
		return Success(c, http.StatusOK, map[string]any{
			"granularity": t.Granularity, "buckets": buckets,
			"legacy_budget": legacy, "token_groups": toks,
		}, nil)
	}
}

func handleDiagEndpoints(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		r, ok := parseDiagRange(c)
		if !ok {
			return errHandled
		}
		e, err := d.Diag.DiagEndpoints(c.Request().Context(), r.from, r.to, r.filters, 25)
		if err != nil {
			return dbError(c, err)
		}
		ops := make([]any, 0, len(e.Operations))
		for _, o := range e.Operations {
			ops = append(ops, map[string]any{
				"operation": o.Operation, "requests": o.Requests, "errors": o.Errors,
				"errors_per_1000": o.ErrorsPer1000,
			})
		}
		reasons := make([]any, 0, len(e.Reasons))
		for _, x := range e.Reasons {
			reasons = append(reasons, map[string]any{
				"operation": x.Operation, "http_status": x.HTTPStatus, "reason": x.Reason,
				"dataset": x.Dataset, "errors": x.Errors,
				"share_of_errors":          x.ShareOfErrors,
				"errors_per_1000_requests": x.ErrorsPer1000Requests,
			})
		}
		return Success(c, http.StatusOK, map[string]any{
			"total_requests": e.TotalRequests, "total_errors": e.TotalErrors,
			"operations": ops, "reasons": reasons,
		}, nil)
	}
}

func handleDiagEvents(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		r, ok := parseDiagRange(c)
		if !ok {
			return errHandled
		}
		qp := c.QueryParams()
		var before *int64
		if raw := firstParam(qp["before_id"]); raw != "" {
			n, err := parseIntProg(raw)
			if err != nil || n < 1 {
				verrs := validationErrors{}
				verrs.add("before_id", "The before id field must be an integer.")
				return verrs.invalid(c)
			}
			v := int64(n)
			before = &v
		}
		perPage := 50
		if raw := firstParam(qp["per_page"]); raw != "" {
			n, err := parseIntProg(raw)
			if err != nil || n < 1 || n > 100 {
				verrs := validationErrors{}
				verrs.add("per_page", "The per page field must be an integer.")
				return verrs.invalid(c)
			}
			perPage = n
		}
		rows, more, err := d.Diag.DiagEvents(c.Request().Context(), r.from, r.to, r.filters, before, perPage)
		if err != nil {
			return dbError(c, err)
		}
		items := make([]any, 0, len(rows))
		for _, e := range rows {
			items = append(items, map[string]any{
				"id": e.ID, "occurred_at": isoDiagTime(e.OccurredAt), "kind": e.Kind,
				"operation": e.Operation, "http_status": e.HTTPStatus, "reason": e.Reason,
				"dataset": e.Dataset, "job_class": e.JobClass, "correlation_id": e.CorrelationID,
				"character_id": e.CharacterID, "corporation_id": e.CorporationID,
				"resource_id": e.ResourceID, "page": e.Page, "batch_size": e.BatchSize,
				"duration_ms": e.DurationMs, "limiter_group": e.LimiterGroup,
				"legacy_remaining": e.LegacyRemaining, "retry_after_seconds": e.RetryAfterSeconds,
				"upstream_request_id": e.UpstreamRequestID, "batch_fingerprint": e.BatchFingerprint,
				"release": e.Release,
			})
		}
		var next any
		if more && len(rows) > 0 {
			next = rows[len(rows)-1].ID
		}
		return Success(c, http.StatusOK, items, map[string]any{"next_cursor": next})
	}
}

func handleDiagBatches(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		r, ok := parseDiagRange(c)
		if !ok {
			return errHandled
		}
		rows, err := d.Diag.DiagBatches(c.Request().Context(), r.from, r.to, r.filters, 25)
		if err != nil {
			return dbError(c, err)
		}
		items := make([]any, 0, len(rows))
		for _, b := range rows {
			items = append(items, map[string]any{
				"fingerprint": b.Fingerprint, "operation": b.Operation, "dataset": b.Dataset,
				"reason": b.Reason, "occurrences": b.Occurrences, "characters": b.Characters,
				"batch_size": b.BatchSize, "first_seen": isoDiagTime(b.FirstSeen),
				"last_seen": isoDiagTime(b.LastSeen), "character_id": b.CharacterID,
			})
		}
		return Success(c, http.StatusOK, items, nil)
	}
}

func handleDiagHealth(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		cp, err := d.Diag.DiagCheckpoint(c.Request().Context())
		if err != nil {
			return dbError(c, err)
		}
		out := map[string]any{
			"enabled": false, "healthy": true, "problems": []any{},
			"last_flushed_at": nil, "seconds_since_flush": nil,
			"backlog": nil, "oldest_event_age_seconds": nil,
			"dropped_events": 0, "gap_count": 0,
			"last_gap_at": nil, "last_gap_reason": nil, "expired_rows": 0,
		}
		if cp != nil {
			out["dropped_events"] = cp.DroppedEvents
			out["gap_count"] = cp.GapCount
			out["expired_rows"] = cp.ExpiredRows
			if cp.LastFlushedAt.Valid {
				out["last_flushed_at"] = isoDiagTime(cp.LastFlushedAt.Time)
				out["seconds_since_flush"] = int(time.Since(cp.LastFlushedAt.Time).Seconds())
			}
			if cp.LastGapAt.Valid {
				out["last_gap_at"] = isoDiagTime(cp.LastGapAt.Time)
			}
			if cp.LastGapReason.Valid {
				out["last_gap_reason"] = cp.LastGapReason.String
			}
		}
		return Success(c, http.StatusOK, out, nil)
	}
}
