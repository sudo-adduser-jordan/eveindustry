package store

import (
	"context"
	"database/sql"
	"time"
)

// DiagFilters mirrors the ESI diagnostics report filters.
type DiagFilters struct {
	Operation     *string
	Dataset       *string
	Status        *int
	Reason        *string
	Group         *string
	CharacterID   *int64
	CorporationID *int64
}

// DiagStore covers the read-only ESI diagnostics reports.
type DiagStore interface {
	DiagSummary(ctx context.Context, from, to time.Time, f DiagFilters) (*DiagSummary, error)
	DiagTrends(ctx context.Context, from, to time.Time, f DiagFilters) (*DiagTrends, error)
	DiagEndpoints(ctx context.Context, from, to time.Time, f DiagFilters, limit int) (*DiagEndpoints, error)
	DiagEvents(ctx context.Context, from, to time.Time, f DiagFilters, beforeID *int64, perPage int) ([]DiagEvent, bool, error)
	DiagBatches(ctx context.Context, from, to time.Time, f DiagFilters, limit int) ([]DiagBatch, error)
	DiagCheckpoint(ctx context.Context) (*DiagCheckpoint, error)
}

// DiagSummary mirrors the report service's summary payload (minus from/to,
// rendered by the handler).
type DiagSummary struct {
	UpstreamRequests     int
	Errors               int
	ErrorPercent         *float64
	Status401            int
	Status403            int
	Status404            int
	Throttled420         int
	Throttled429         int
	OtherErrors          int
	ConnectionFailures   int
	LocalDeferrals       int
	CacheHits            int
	CacheMisses          int
	CacheRevalidations   int
	ProtectionEpisodes   int
	ObservedPauseSeconds int
	MinLegacyRemaining   *int
	Releases             []DiagRelease
}

// DiagRelease is one release first-seen pair.
type DiagRelease struct {
	Release   string
	FirstSeen time.Time
}

// DiagTrendBucket is one time bucket.
type DiagTrendBucket struct {
	At             time.Time
	Requests       int
	Errors         int
	Status401      int
	Status403      int
	Status404      int
	Throttled420   int
	Throttled429   int
	LocalDeferrals int
	CacheHits      int
}

// DiagLegacyPoint is one legacy-budget point.
type DiagLegacyPoint struct {
	At                 time.Time
	RemainingMin       *int
	ProtectionEpisodes int
}

// DiagTokenPoint is one token-group point.
type DiagTokenPoint struct {
	At           time.Time
	RemainingMin *int
	Used         *int
}

// DiagTokenGroup is one limiter group's series.
type DiagTokenGroup struct {
	Group  string
	Points []DiagTokenPoint
}

// DiagTrends mirrors the trends payload.
type DiagTrends struct {
	Granularity string
	Buckets     []DiagTrendBucket
	Legacy      []DiagLegacyPoint
	TokenGroups []DiagTokenGroup
}

// DiagOperation is one top operation row.
type DiagOperation struct {
	Operation     string
	Requests      int
	Errors        int
	ErrorsPer1000 *float64
}

// DiagReason is one top reason row.
type DiagReason struct {
	Operation             string
	HTTPStatus            *int
	Reason                string
	Dataset               *string
	Errors                int
	ShareOfErrors         *float64
	ErrorsPer1000Requests *float64
}

// DiagEndpoints mirrors the endpoints payload.
type DiagEndpoints struct {
	TotalRequests int
	TotalErrors   int
	Operations    []DiagOperation
	Reasons       []DiagReason
}

// DiagEvent is one failure-detail row.
type DiagEvent struct {
	ID                int64
	OccurredAt        time.Time
	Kind              string
	Operation         *string
	HTTPStatus        *int
	Reason            string
	Dataset           *string
	JobClass          *string
	CorrelationID     *string
	CharacterID       *int64
	CorporationID     *int64
	ResourceID        *int64
	Page              *int
	BatchSize         *int
	DurationMs        *int
	LimiterGroup      *string
	LegacyRemaining   *int
	RetryAfterSeconds *int
	UpstreamRequestID *string
	BatchFingerprint  *string
	Release           *string
}

// DiagBatch is one repeated-fingerprint row.
type DiagBatch struct {
	Fingerprint string
	Operation   string
	Dataset     string
	Reason      string
	Occurrences int
	Characters  int
	BatchSize   *int
	FirstSeen   time.Time
	LastSeen    time.Time
	CharacterID *int64
}

func fmtDiag(t time.Time) string { return t.UTC().Format("2006-01-02 15:04:05") }
func hourDiag(t time.Time) string {
	return time.Date(t.UTC().Year(), t.UTC().Month(), t.UTC().Day(), t.UTC().Hour(), 0, 0, 0, time.UTC).Format("2006-01-02 15:04:05")
}

func endpointWhere(f DiagFilters) (string, []any) {
	q := ""
	var args []any
	if f.Operation != nil {
		q += " AND operation_key = ?"
		args = append(args, *f.Operation)
	}
	if f.Dataset != nil {
		q += " AND dataset = ?"
		args = append(args, *f.Dataset)
	}
	if f.Status != nil {
		q += " AND http_status = ?"
		args = append(args, *f.Status)
	}
	if f.Reason != nil {
		q += " AND reason = ?"
		args = append(args, *f.Reason)
	}
	if f.Group != nil {
		q += " AND limiter_group = ?"
		args = append(args, *f.Group)
	}
	return q, args
}

// DiagSummary ports the report service's summary query.
func (s *SQL) DiagSummary(ctx context.Context, from, to time.Time, f DiagFilters) (*DiagSummary, error) {
	out := &DiagSummary{}
	fw, args := endpointWhere(f)
	base := `FROM esi_endpoint_metrics WHERE bucket_at >= ? AND bucket_at <= ?` + fw
	full := append([]any{hourDiag(from), fmtDiag(to)}, args...)
	up := ` AND event_kind IN ('http_attempt','connection_failure')`
	row := struct {
		Requests *int64 `db:"requests"`
		Errors   *int64 `db:"errors"`
	}{}
	if err := s.db.GetContext(ctx, &row, `SELECT COALESCE(SUM(request_count),0) AS requests, COALESCE(SUM(error_count),0) AS errors `+base+up, full...); err != nil {
		return nil, err
	}
	if row.Requests != nil {
		out.UpstreamRequests = int(*row.Requests)
	}
	if row.Errors != nil {
		out.Errors = int(*row.Errors)
	}
	if out.UpstreamRequests > 0 {
		p := float64(out.Errors) * 100 / float64(out.UpstreamRequests)
		p = float64(int(p*100+0.5)) / 100
		out.ErrorPercent = &p
	}
	type statusRow struct {
		Status int   `db:"http_status"`
		Total  int64 `db:"total"`
	}
	var byStatus []statusRow
	if err := s.db.SelectContext(ctx, &byStatus, `SELECT http_status, SUM(request_count) AS total `+base+up+` AND (http_status = 0 OR http_status >= 400) GROUP BY http_status`, full...); err != nil {
		return nil, err
	}
	errorStatuses := map[int]bool{401: true, 403: true, 404: true, 420: true, 429: true, 0: true}
	known := 0
	for _, r := range byStatus {
		switch r.Status {
		case 401:
			out.Status401 = int(r.Total)
		case 403:
			out.Status403 = int(r.Total)
		case 404:
			out.Status404 = int(r.Total)
		case 420:
			out.Throttled420 = int(r.Total)
		case 429:
			out.Throttled429 = int(r.Total)
		case 0:
			out.ConnectionFailures = int(r.Total)
		}
		if errorStatuses[r.Status] {
			known += int(r.Total)
		}
	}
	out.OtherErrors = out.Errors - known
	if out.OtherErrors < 0 {
		out.OtherErrors = 0
	}
	type kindRow struct {
		Kind  string `db:"event_kind"`
		Total int64  `db:"total"`
	}
	var kinds []kindRow
	if err := s.db.SelectContext(ctx, &kinds, `SELECT event_kind, SUM(request_count) AS total `+base+` AND event_kind IN ('local_deferral','cache_hit','cache_miss','cache_revalidation') GROUP BY event_kind`, full...); err != nil {
		return nil, err
	}
	for _, k := range kinds {
		switch k.Kind {
		case "local_deferral":
			out.LocalDeferrals = int(k.Total)
		case "cache_hit":
			out.CacheHits = int(k.Total)
		case "cache_miss":
			out.CacheMisses = int(k.Total)
		case "cache_revalidation":
			out.CacheRevalidations = int(k.Total)
		}
	}
	bud := struct {
		Opened *int64 `db:"opened"`
		Pause  *int64 `db:"pause"`
		MinRem *int64 `db:"minrem"`
	}{}
	if err := s.db.GetContext(ctx, &bud, `SELECT COALESCE(SUM(circuit_opened_count),0) AS opened, COALESCE(SUM(pause_seconds),0) AS pause, MIN(remaining_min) AS minrem
		FROM esi_budget_metrics WHERE granularity = 'hour' AND mechanism = 'legacy' AND bucket_at >= ? AND bucket_at <= ?`, hourDiag(from), fmtDiag(to)); err != nil {
		return nil, err
	}
	if bud.Opened != nil {
		out.ProtectionEpisodes = int(*bud.Opened)
	}
	if bud.Pause != nil {
		out.ObservedPauseSeconds = int(*bud.Pause)
	}
	if bud.MinRem != nil {
		v := int(*bud.MinRem)
		out.MinLegacyRemaining = &v
	}
	var releases []struct {
		Release   string    `db:"release"`
		FirstSeen time.Time `db:"first_seen"`
	}
	if err := s.db.SelectContext(ctx, &releases, `SELECT release, MIN(occurred_at) AS first_seen FROM esi_diagnostic_events
		WHERE release IS NOT NULL AND occurred_at >= ? AND occurred_at <= ? GROUP BY release ORDER BY first_seen`, fmtDiag(from), fmtDiag(to)); err != nil {
		return nil, err
	}
	for _, r := range releases {
		out.Releases = append(out.Releases, DiagRelease{Release: r.Release, FirstSeen: r.FirstSeen})
	}
	if out.Releases == nil {
		out.Releases = []DiagRelease{}
	}
	return out, nil
}

// DiagTrends ports the trends query (hour or minute granularity).
func (s *SQL) DiagTrends(ctx context.Context, from, to time.Time, f DiagFilters) (*DiagTrends, error) {
	out := &DiagTrends{Buckets: []DiagTrendBucket{}, Legacy: []DiagLegacyPoint{}, TokenGroups: []DiagTokenGroup{}}
	fw, args := endpointWhere(f)
	full := append([]any{hourDiag(from), fmtDiag(to)}, args...)
	up := ` AND event_kind IN ('http_attempt','connection_failure')`
	type bucketRow struct {
		At   time.Time `db:"bucket_at"`
		Req  int64     `db:"requests"`
		Err  int64     `db:"errors"`
		S401 int64     `db:"s401"`
		S403 int64     `db:"s403"`
		S404 int64     `db:"s404"`
		S420 int64     `db:"s420"`
		S429 int64     `db:"s429"`
	}
	var buckets []bucketRow
	if err := s.db.SelectContext(ctx, &buckets, `SELECT bucket_at,
		SUM(request_count) AS requests, SUM(error_count) AS errors,
		SUM(CASE WHEN http_status = 401 THEN request_count ELSE 0 END) AS s401,
		SUM(CASE WHEN http_status = 403 THEN request_count ELSE 0 END) AS s403,
		SUM(CASE WHEN http_status = 404 THEN request_count ELSE 0 END) AS s404,
		SUM(CASE WHEN http_status = 420 THEN request_count ELSE 0 END) AS s420,
		SUM(CASE WHEN http_status = 429 THEN request_count ELSE 0 END) AS s429
		FROM esi_endpoint_metrics WHERE bucket_at >= ? AND bucket_at <= ?`+fw+up+` GROUP BY bucket_at ORDER BY bucket_at`, full...); err != nil {
		return nil, err
	}
	type deferRow struct {
		At    time.Time `db:"bucket_at"`
		Total int64     `db:"total"`
	}
	var defs, hits []deferRow
	if err := s.db.SelectContext(ctx, &defs, `SELECT bucket_at, SUM(request_count) AS total FROM esi_endpoint_metrics
		WHERE bucket_at >= ? AND bucket_at <= ?`+fw+` AND event_kind = 'local_deferral' GROUP BY bucket_at`, full...); err != nil {
		return nil, err
	}
	if err := s.db.SelectContext(ctx, &hits, `SELECT bucket_at, SUM(request_count) AS total FROM esi_endpoint_metrics
		WHERE bucket_at >= ? AND bucket_at <= ?`+fw+` AND event_kind = 'cache_hit' GROUP BY bucket_at`, full...); err != nil {
		return nil, err
	}
	defMap := map[time.Time]int64{}
	for _, r := range defs {
		defMap[r.At] = r.Total
	}
	hitMap := map[time.Time]int64{}
	for _, r := range hits {
		hitMap[r.At] = r.Total
	}
	for _, b := range buckets {
		out.Buckets = append(out.Buckets, DiagTrendBucket{
			At: b.At, Requests: int(b.Req), Errors: int(b.Err),
			Status401: int(b.S401), Status403: int(b.S403), Status404: int(b.S404),
			Throttled420: int(b.S420), Throttled429: int(b.S429),
			LocalDeferrals: int(defMap[b.At]), CacheHits: int(hitMap[b.At]),
		})
	}
	gran := "hour"
	if to.Sub(from).Seconds() <= 6*3600 {
		gran = "minute"
	}
	out.Granularity = gran
	bfrom := hourDiag(from)
	if gran == "minute" {
		bfrom = fmtDiag(from)
	}
	budgetBase := `FROM esi_budget_metrics WHERE granularity = ? AND bucket_at >= ? AND bucket_at <= ?`
	bargs := []any{gran, bfrom, fmtDiag(to)}
	if f.Group != nil {
		budgetBase += ` AND limiter_group = ?`
		bargs = append(bargs, *f.Group)
	}
	type legRow struct {
		At     time.Time `db:"bucket_at"`
		MinRem *int64    `db:"remaining_min"`
		Opened *int64    `db:"opened"`
	}
	var legs []legRow
	if err := s.db.SelectContext(ctx, &legs, `SELECT bucket_at, MIN(remaining_min) AS remaining_min, SUM(circuit_opened_count) AS opened `+budgetBase+` AND mechanism = 'legacy' GROUP BY bucket_at ORDER BY bucket_at`, bargs...); err != nil {
		return nil, err
	}
	for _, l := range legs {
		p := DiagLegacyPoint{At: l.At}
		if l.MinRem != nil {
			v := int(*l.MinRem)
			p.RemainingMin = &v
		}
		if l.Opened != nil {
			p.ProtectionEpisodes = int(*l.Opened)
		}
		out.Legacy = append(out.Legacy, p)
	}
	type tokRow struct {
		At      time.Time `db:"bucket_at"`
		Group   string    `db:"limiter_group"`
		MinRem  *int64    `db:"remaining_min"`
		UsedSum *int64    `db:"used_sum"`
	}
	var toks []tokRow
	if err := s.db.SelectContext(ctx, &toks, `SELECT bucket_at, limiter_group, remaining_min, used_sum `+budgetBase+` AND mechanism = 'token' ORDER BY bucket_at`, bargs...); err != nil {
		return nil, err
	}
	gi := map[string]int{}
	for _, t := range toks {
		i, ok := gi[t.Group]
		if !ok {
			i = len(out.TokenGroups)
			gi[t.Group] = i
			out.TokenGroups = append(out.TokenGroups, DiagTokenGroup{Group: t.Group})
		}
		pt := DiagTokenPoint{At: t.At}
		if t.MinRem != nil {
			v := int(*t.MinRem)
			pt.RemainingMin = &v
		}
		if t.UsedSum != nil {
			v := int(*t.UsedSum)
			pt.Used = &v
		}
		out.TokenGroups[i].Points = append(out.TokenGroups[i].Points, pt)
	}
	return out, nil
}

// DiagEndpoints ports the endpoints + reasons queries.
func (s *SQL) DiagEndpoints(ctx context.Context, from, to time.Time, f DiagFilters, limit int) (*DiagEndpoints, error) {
	out := &DiagEndpoints{Operations: []DiagOperation{}, Reasons: []DiagReason{}}
	fw, args := endpointWhere(f)
	full := append([]any{hourDiag(from), fmtDiag(to)}, args...)
	up := ` AND event_kind IN ('http_attempt','connection_failure')`
	tot := struct {
		Req *int64 `db:"req"`
		Err *int64 `db:"err"`
	}{}
	if err := s.db.GetContext(ctx, &tot, `SELECT COALESCE(SUM(request_count),0) AS req, COALESCE(SUM(error_count),0) AS err
		FROM esi_endpoint_metrics WHERE bucket_at >= ? AND bucket_at <= ?`+fw+up, full...); err != nil {
		return nil, err
	}
	if tot.Req != nil {
		out.TotalRequests = int(*tot.Req)
	}
	if tot.Err != nil {
		out.TotalErrors = int(*tot.Err)
	}
	type opRow struct {
		Op  string `db:"operation_key"`
		Req int64  `db:"requests"`
		Err int64  `db:"errors"`
	}
	var ops []opRow
	if err := s.db.SelectContext(ctx, &ops, `SELECT operation_key, SUM(request_count) AS requests, SUM(error_count) AS errors
		FROM esi_endpoint_metrics WHERE bucket_at >= ? AND bucket_at <= ?`+fw+up+`
		GROUP BY operation_key ORDER BY errors DESC, requests DESC LIMIT ?`, append(full, limit)...); err != nil {
		return nil, err
	}
	for _, o := range ops {
		op := DiagOperation{Operation: o.Op, Requests: int(o.Req), Errors: int(o.Err)}
		if o.Req > 0 {
			v := float64(o.Err) * 1000 / float64(o.Req)
			v = float64(int(v*10+0.5)) / 10
			op.ErrorsPer1000 = &v
		}
		out.Operations = append(out.Operations, op)
	}
	type reasonRow struct {
		Op      string `db:"operation_key"`
		Status  int    `db:"http_status"`
		Reason  string `db:"reason"`
		Dataset string `db:"dataset"`
		Errs    int64  `db:"errors"`
	}
	var reasons []reasonRow
	if err := s.db.SelectContext(ctx, &reasons, `SELECT operation_key, http_status, reason, dataset, SUM(request_count) AS errors
		FROM esi_endpoint_metrics WHERE bucket_at >= ? AND bucket_at <= ?`+fw+up+` AND (http_status = 0 OR http_status >= 400)
		GROUP BY operation_key, http_status, reason, dataset ORDER BY errors DESC LIMIT ?`, append(full, limit)...); err != nil {
		return nil, err
	}
	for _, r := range reasons {
		dr := DiagReason{Operation: r.Op, Reason: r.Reason, Errors: int(r.Errs)}
		if r.Status != 0 || true {
			v := r.Status
			dr.HTTPStatus = &v
		}
		if r.Dataset != "" {
			ds := r.Dataset
			dr.Dataset = &ds
		}
		if out.TotalErrors > 0 {
			v := float64(r.Errs) * 100 / float64(out.TotalErrors)
			v = float64(int(v*10+0.5)) / 10
			dr.ShareOfErrors = &v
		}
		if out.TotalRequests > 0 {
			v := float64(r.Errs) * 1000 / float64(out.TotalRequests)
			v = float64(int(v*10+0.5)) / 10
			dr.ErrorsPer1000Requests = &v
		}
		out.Reasons = append(out.Reasons, dr)
	}
	return out, nil
}

// DiagEvents ports the failure-detail cursor page (newest first).
func (s *SQL) DiagEvents(ctx context.Context, from, to time.Time, f DiagFilters, beforeID *int64, perPage int) ([]DiagEvent, bool, error) {
	q := `SELECT id, occurred_at, kind, operation_key, http_status, reason, dataset, job_class,
		correlation_id, character_id, corporation_id, resource_id, page, batch_size, duration_ms,
		limiter_group, legacy_remaining, retry_after_seconds, upstream_request_id, batch_fingerprint, release
		FROM esi_diagnostic_events WHERE occurred_at >= ? AND occurred_at <= ?`
	args := []any{fmtDiag(from), fmtDiag(to)}
	if f.Operation != nil {
		q += " AND operation_key = ?"
		args = append(args, *f.Operation)
	}
	if f.Dataset != nil {
		q += " AND dataset = ?"
		args = append(args, *f.Dataset)
	}
	if f.Status != nil {
		q += " AND http_status = ?"
		args = append(args, *f.Status)
	}
	if f.Reason != nil {
		q += " AND reason = ?"
		args = append(args, *f.Reason)
	}
	if f.Group != nil {
		q += " AND limiter_group = ?"
		args = append(args, *f.Group)
	}
	if f.CharacterID != nil {
		q += " AND character_id = ?"
		args = append(args, *f.CharacterID)
	}
	if f.CorporationID != nil {
		q += " AND corporation_id = ?"
		args = append(args, *f.CorporationID)
	}
	if beforeID != nil {
		q += " AND id < ?"
		args = append(args, *beforeID)
	}
	q += " ORDER BY id DESC LIMIT ?"
	args = append(args, perPage+1)
	type row struct {
		ID            int64          `db:"id"`
		OccurredAt    time.Time      `db:"occurred_at"`
		Kind          string         `db:"kind"`
		Operation     string         `db:"operation_key"`
		HTTPStatus    sql.NullInt64  `db:"http_status"`
		Reason        string         `db:"reason"`
		Dataset       sql.NullString `db:"dataset"`
		JobClass      sql.NullString `db:"job_class"`
		CorrelationID sql.NullString `db:"correlation_id"`
		CharacterID   sql.NullInt64  `db:"character_id"`
		CorporationID sql.NullInt64  `db:"corporation_id"`
		ResourceID    sql.NullInt64  `db:"resource_id"`
		Page          sql.NullInt64  `db:"page"`
		BatchSize     sql.NullInt64  `db:"batch_size"`
		DurationMs    sql.NullInt64  `db:"duration_ms"`
		LimiterGroup  sql.NullString `db:"limiter_group"`
		LegacyRemain  sql.NullInt64  `db:"legacy_remaining"`
		RetryAfter    sql.NullInt64  `db:"retry_after_seconds"`
		UpstreamID    sql.NullString `db:"upstream_request_id"`
		BatchFP       sql.NullString `db:"batch_fingerprint"`
		Release       sql.NullString `db:"release"`
	}
	var rows []row
	if err := s.db.SelectContext(ctx, &rows, q, args...); err != nil {
		return nil, false, err
	}
	more := len(rows) > perPage
	if more {
		rows = rows[:perPage]
	}
	out := make([]DiagEvent, 0, len(rows))
	for _, r := range rows {
		e := DiagEvent{ID: r.ID, OccurredAt: r.OccurredAt, Kind: r.Kind, Reason: r.Reason}
		if r.Operation != "" {
			v := r.Operation
			e.Operation = &v
		}
		if r.HTTPStatus.Valid {
			v := int(r.HTTPStatus.Int64)
			e.HTTPStatus = &v
		}
		if r.Dataset.Valid && r.Dataset.String != "" {
			v := r.Dataset.String
			e.Dataset = &v
		} else if r.Dataset.Valid {
			v := ""
			e.Dataset = &v
		}
		if r.JobClass.Valid {
			v := r.JobClass.String
			e.JobClass = &v
		}
		if r.CorrelationID.Valid {
			v := r.CorrelationID.String
			e.CorrelationID = &v
		}
		if r.CharacterID.Valid {
			v := r.CharacterID.Int64
			e.CharacterID = &v
		}
		if r.CorporationID.Valid {
			v := r.CorporationID.Int64
			e.CorporationID = &v
		}
		if r.ResourceID.Valid {
			v := r.ResourceID.Int64
			e.ResourceID = &v
		}
		if r.Page.Valid {
			v := int(r.Page.Int64)
			e.Page = &v
		}
		if r.BatchSize.Valid {
			v := int(r.BatchSize.Int64)
			e.BatchSize = &v
		}
		if r.DurationMs.Valid {
			v := int(r.DurationMs.Int64)
			e.DurationMs = &v
		}
		if r.LimiterGroup.Valid {
			v := r.LimiterGroup.String
			e.LimiterGroup = &v
		}
		if r.LegacyRemain.Valid {
			v := int(r.LegacyRemain.Int64)
			e.LegacyRemaining = &v
		}
		if r.RetryAfter.Valid {
			v := int(r.RetryAfter.Int64)
			e.RetryAfterSeconds = &v
		}
		if r.UpstreamID.Valid {
			v := r.UpstreamID.String
			e.UpstreamRequestID = &v
		}
		if r.BatchFP.Valid {
			v := r.BatchFP.String
			e.BatchFingerprint = &v
		}
		if r.Release.Valid {
			v := r.Release.String
			e.Release = &v
		}
		out = append(out, e)
	}
	return out, more, nil
}

// DiagBatches ports the repeated-fingerprint query.
func (s *SQL) DiagBatches(ctx context.Context, from, to time.Time, f DiagFilters, limit int) ([]DiagBatch, error) {
	q := `SELECT batch_fingerprint, operation_key, dataset, reason, COUNT(*) AS occurrences,
		COUNT(DISTINCT character_id) AS characters, MIN(batch_size) AS batch_size,
		MIN(occurred_at) AS first_seen, MAX(occurred_at) AS last_seen, MIN(character_id) AS character_id
		FROM esi_diagnostic_events WHERE batch_fingerprint IS NOT NULL AND occurred_at >= ? AND occurred_at <= ?`
	args := []any{fmtDiag(from), fmtDiag(to)}
	if f.Operation != nil {
		q += " AND operation_key = ?"
		args = append(args, *f.Operation)
	}
	if f.Dataset != nil {
		q += " AND dataset = ?"
		args = append(args, *f.Dataset)
	}
	if f.Status != nil {
		q += " AND http_status = ?"
		args = append(args, *f.Status)
	}
	if f.Reason != nil {
		q += " AND reason = ?"
		args = append(args, *f.Reason)
	}
	if f.CharacterID != nil {
		q += " AND character_id = ?"
		args = append(args, *f.CharacterID)
	}
	if f.CorporationID != nil {
		q += " AND corporation_id = ?"
		args = append(args, *f.CorporationID)
	}
	q += ` GROUP BY batch_fingerprint, operation_key, dataset, reason HAVING COUNT(*) > 1 ORDER BY occurrences DESC LIMIT ?`
	args = append(args, limit)
	type row struct {
		FP        string        `db:"batch_fingerprint"`
		Op        string        `db:"operation_key"`
		Dataset   string        `db:"dataset"`
		Reason    string        `db:"reason"`
		Occ       int64         `db:"occurrences"`
		Chars     int64         `db:"characters"`
		BatchSize sql.NullInt64 `db:"batch_size"`
		First     time.Time     `db:"first_seen"`
		Last      time.Time     `db:"last_seen"`
		CharID    sql.NullInt64 `db:"character_id"`
	}
	var rows []row
	if err := s.db.SelectContext(ctx, &rows, q, args...); err != nil {
		return nil, err
	}
	out := make([]DiagBatch, 0, len(rows))
	for _, r := range rows {
		b := DiagBatch{Fingerprint: r.FP, Operation: r.Op, Dataset: r.Dataset, Reason: r.Reason,
			Occurrences: int(r.Occ), Characters: int(r.Chars), FirstSeen: r.First, LastSeen: r.Last}
		if r.BatchSize.Valid {
			v := int(r.BatchSize.Int64)
			b.BatchSize = &v
		}
		if r.CharID.Valid {
			v := r.CharID.Int64
			b.CharacterID = &v
		}
		out = append(out, b)
	}
	return out, nil
}

// DiagCheckpoint loads the flusher checkpoint (nil when never flushed).
func (s *SQL) DiagCheckpoint(ctx context.Context) (*DiagCheckpoint, error) {
	var r struct {
		LastFlushed sql.NullTime   `db:"last_flushed_at"`
		Dropped     sql.NullInt64  `db:"dropped_events"`
		Gaps        sql.NullInt64  `db:"gap_count"`
		GapAt       sql.NullTime   `db:"last_gap_at"`
		GapReason   sql.NullString `db:"last_gap_reason"`
		Expired     sql.NullInt64  `db:"expired_rows"`
	}
	err := s.db.GetContext(ctx, &r, `SELECT last_flushed_at, dropped_events, gap_count,
		last_gap_at, last_gap_reason, expired_rows FROM esi_telemetry_checkpoints WHERE name = 'esi:diagnostics'`)
	if err == sql.ErrNoRows {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	out := &DiagCheckpoint{LastFlushedAt: r.LastFlushed, LastGapAt: r.GapAt, LastGapReason: r.GapReason}
	if r.Dropped.Valid {
		out.DroppedEvents = r.Dropped.Int64
	}
	if r.Gaps.Valid {
		out.GapCount = r.Gaps.Int64
	}
	if r.Expired.Valid {
		out.ExpiredRows = r.Expired.Int64
	}
	return out, nil
}
