package jobs

import (
	"context"
	"crypto/sha256"
	"database/sql"
	"encoding/hex"
	"encoding/json"
	"time"

	"github.com/sudo-adduser-jordan/eveindustry/internal/esi"
	"github.com/sudo-adduser-jordan/eveindustry/internal/store"
)

// DiagStream abstracts the Redis buffer for the flusher.
type DiagStream interface {
	Epoch(ctx context.Context) string
	TotalAdded(ctx context.Context) int64
	Len(ctx context.Context) int
	Read(ctx context.Context, after string, count int) ([]esi.StreamEntry, error)
	TrimThrough(ctx context.Context, id string) error
	PendingDrops(ctx context.Context) int
	AcknowledgeDrops(ctx context.Context, count int)
}

// DiagFlusherDeps carries the flush/prune collaborators.
type DiagFlusherDeps struct {
	HandlerDeps
	Stream DiagStream
}

// RegisterDiagJobs wires esi:diagnostics-flush (every minute) and
// esi:diagnostics-prune (daily).
func RegisterDiagJobs(r *Runner, h DiagFlusherDeps) {
	r.Register("flush-esi-diagnostics", JobDef{
		Queue: QueueDefault, MaxTries: 2, Timeout: 300 * time.Second,
		Run: func(ctx context.Context, d Deps, raw json.RawMessage) error {
			n, requeue, err := FlushDiagBatch(ctx, h)
			if err != nil {
				return err
			}
			_ = n
			_ = requeue
			return nil
		},
	})
	r.Register("prune-esi-diagnostics", JobDef{
		Queue: QueueDefault, MaxTries: 2, Timeout: 300 * time.Second,
		Run: func(ctx context.Context, d Deps, raw json.RawMessage) error {
			_, err := PruneDiagnostics(ctx, h, 120*time.Second)
			return err
		},
	})
}

// DiagSchedule mirrors the diagnostics cadences.
func DiagSchedule(h DiagFlusherDeps) []ScheduleEntry {
	return []ScheduleEntry{
		{
			Name: "esi:diagnostics-flush", Every: time.Minute, Overlap: time.Minute,
			Run: func(ctx context.Context, r *Runner, q Queue) error {
				return r.Dispatch(ctx, q, "flush-esi-diagnostics", nil)
			},
		},
		{
			Name: "esi:diagnostics-prune", Every: 24 * time.Hour, At: "06:30", Overlap: 24 * time.Hour,
			Run: func(ctx context.Context, r *Runner, q Queue) error {
				return r.Dispatch(ctx, q, "prune-esi-diagnostics", nil)
			},
		},
	}
}

// FlushDiagBatch ports EsiDiagnosticsFlusher::flushBatch: one checkpointed
// transaction of up to 200 events, then trim + drop acknowledgement.
// Returns events committed and whether more backlog remains.
func FlushDiagBatch(ctx context.Context, h DiagFlusherDeps, batch ...int) (int, bool, error) {
	size := 200
	if len(batch) > 0 && batch[0] > 0 {
		size = batch[0]
	}
	if err := h.DB.EnsureDiagCheckpoint(ctx); err != nil {
		return 0, false, err
	}
	if h.Stream == nil {
		return 0, false, nil
	}
	// Lock-free read of the checkpoint; the update path re-checks inside tx.
	// (Single scheduler => no concurrent flushers.)
	cp, err := h.DB.DiagCheckpointForUpdate(ctx, nil)
	if err != nil || cp == nil {
		return 0, false, err
	}
	epoch := h.Stream.Epoch(ctx)
	after := ""
	if cp.LastStreamID.Valid {
		after = cp.LastStreamID.String
	}
	consumed := cp.EntriesConsumed
	gap := ""
	if cp.StreamEpoch.Valid && cp.StreamEpoch.String != "" && cp.StreamEpoch.String != epoch {
		gap = "stream_reset"
		after = ""
		consumed = 0
	}
	lost := int64(0)
	if added := h.Stream.TotalAdded(ctx); added >= 0 {
		present := int64(h.Stream.Len(ctx))
		if l := added - present - consumed; l > 0 {
			lost = l
			if gap == "" {
				gap = "trimmed_unread"
			}
			consumed += lost
		}
	}
	entries, err := h.Stream.Read(ctx, after, size)
	if err != nil {
		return 0, false, err
	}
	drops := h.Stream.PendingDrops(ctx)
	lastID := after
	consumedOK := consumed
	invalid := 0
	var details []store.DiagEventRow
	endpointAgg := map[string]*store.EndpointMetricDelta{}
	budgetAgg := map[string]*store.BudgetMetricDelta{}
	for _, e := range entries {
		if after != "" && esi.CompareStreamIDs(e.ID, after) <= 0 {
			continue
		}
		lastID = e.ID
		consumed++
		js, ok := e.JSON.(string)
		if !ok {
			if s, ok := e.JSON.([]byte); ok {
				js = string(s)
			} else {
				invalid++
				continue
			}
		}
		var ev map[string]any
		if err := json.Unmarshal([]byte(js), &ev); err != nil || !validDiagEvent(ev) {
			invalid++
			continue
		}
		applyDiagEvent(ev, &details, endpointAgg, budgetAgg)
	}
	consumedOK = consumed
	_ = consumedOK
	// Persist inside one transaction would need tx-scoped merges; the store
	// methods are single-statement and idempotent (insert-or-ignore + add),
	// so a crash replays at most one batch — replay is deduped by event_id
	// and dimension hashes.
	if err := h.DB.InsertDiagEvents(ctx, details); err != nil {
		return 0, false, err
	}
	for _, m := range endpointAgg {
		if err := h.DB.MergeEndpointMetric(ctx, *m); err != nil {
			return 0, false, err
		}
	}
	for _, m := range budgetAgg {
		if err := h.DB.MergeBudgetMetric(ctx, *m); err != nil {
			return 0, false, err
		}
	}
	next := *cp
	next.StreamEpoch = nullString(epoch)
	if lastID != "" {
		next.LastStreamID = nullString(lastID)
	}
	now := time.Now()
	next.LastFlushedAt = nullTime(now)
	next.EntriesConsumed = consumed
	next.DroppedEvents = cp.DroppedEvents + int64(drops) + int64(invalid) + lost
	if gap != "" {
		next.GapCount = cp.GapCount + 1
		next.LastGapAt = nullTime(now)
		next.LastGapReason = nullString(gap)
	}
	if err := h.DB.UpdateDiagCheckpoint(ctx, next); err != nil {
		return 0, false, err
	}
	if lastID != "" {
		_ = h.Stream.TrimThrough(ctx, lastID)
	}
	h.Stream.AcknowledgeDrops(ctx, drops)
	remaining := h.Stream.Len(ctx) > 0
	return len(entries), remaining, nil
}

func nullString(s string) sql.NullString { return sql.NullString{String: s, Valid: true} }

func nullTime(t time.Time) sql.NullTime { return sql.NullTime{Time: t, Valid: true} }

func nullInt64(n int64) sql.NullInt64 { return sql.NullInt64{Int64: n, Valid: true} }

// validDiagEvent ports the flusher's event validation.
func validDiagEvent(ev map[string]any) bool {
	_, ok1 := ev["event_id"].(string)
	_, ok2 := ev["kind"].(string)
	ts, _ := ev["occurred_at"].(string)
	if !ok1 || !ok2 || len(ts) < 19 {
		return false
	}
	return true
}

// applyDiagEvent ports apply(): failure details plus endpoint/budget rollups.
func applyDiagEvent(ev map[string]any, details *[]store.DiagEventRow, endpoint map[string]*store.EndpointMetricDelta, budget map[string]*store.BudgetMetricDelta) {
	kind, _ := ev["kind"].(string)
	var status *int
	if n, ok := numOf(ev["http_status"]); ok {
		v := int(n)
		status = &v
	}
	failure := kind == "connection_failure" || kind == "circuit_opened" || kind == "circuit_closed" ||
		(kind == "http_attempt" && status != nil && *status >= 400)
	if failure {
		*details = append(*details, detailRow(ev))
	}
	if isCountedKind(kind) {
		addEndpoint(endpoint, ev, status)
	}
	addBudget(budget, ev)
}

var countedKinds = map[string]bool{
	"http_attempt": true, "connection_failure": true, "local_deferral": true,
	"cache_hit": true, "cache_miss": true, "cache_revalidation": true,
}

func isCountedKind(kind string) bool { return countedKinds[kind] }

func detailRow(ev map[string]any) store.DiagEventRow {
	var occurred time.Time
	if s, ok := ev["occurred_at"].(string); ok {
		for _, layout := range []string{"2006-01-02 15:04:05.999999", "2006-01-02 15:04:05"} {
			if t, err := time.Parse(layout, s); err == nil {
				occurred = t.UTC()
				break
			}
		}
	}
	var durMs *int
	if d, ok := numOf(ev["duration_ms"]); ok {
		v := int(d)
		durMs = &v
	}
	return store.DiagEventRow{
		EventID: diagStr(ev["event_id"]), OccurredAt: occurred, Kind: diagStr(ev["kind"]),
		Method: diagStrPtr(ev["method"]), Operation: diagStr(ev["operation_key"]),
		Compatibility: diagStrPtr(ev["compatibility_date"]), Dataset: diagStrPtr(ev["dataset"]),
		JobClass: diagStrPtr(ev["job_class"]), CorrelationID: diagStrPtr(ev["correlation_id"]),
		SourceIdentity: diagStr(ev["source_identity"]), ApplicationID: diagStr(ev["application_id"]),
		CharacterID: num(ev["character_id"]), CorporationID: num(ev["corporation_id"]),
		ResourceID: num(ev["resource_id"]), Page: numInt(ev["page"]), BatchSize: numInt(ev["batch_size"]),
		HTTPStatus: numInt(ev["http_status"]), Reason: diagStrDef(ev["reason"], "unknown"),
		DurationMs: durMs, ResponseReceived: boolOf(ev["response_received"]),
		LimiterGroup: diagStrPtr(ev["limiter_group"]), LegacyRemaining: numInt(ev["legacy_remaining"]),
		RetryAfter: numInt(ev["retry_after_seconds"]), UpstreamReqID: diagStrPtr(ev["upstream_request_id"]),
		BatchFP: diagStrPtr(ev["batch_fingerprint"]), Release: diagStrPtr(ev["release"]),
	}
}

func diagStrPtr(v any) *string {
	if s, ok := v.(string); ok {
		return &s
	}
	return nil
}

func num(v any) *int64 {
	if n, ok := numOf(v); ok {
		val := int64(n)
		return &val
	}
	return nil
}

func numInt(v any) *int {
	if n, ok := numOf(v); ok {
		val := int(n)
		return &val
	}
	return nil
}

func numInt64(v any) *int64 {
	if n, ok := numOf(v); ok {
		val := int64(n)
		return &val
	}
	return nil
}

func numOf(v any) (float64, bool) {
	switch n := v.(type) {
	case int:
		return float64(n), true
	case int64:
		return float64(n), true
	case float64:
		return n, true
	}
	return 0, false
}

// addEndpoint ports the hourly endpoint rollup.
func addEndpoint(rows map[string]*store.EndpointMetricDelta, ev map[string]any, status *int) {
	kind, _ := ev["kind"].(string)
	occurred, _ := ev["occurred_at"].(string)
	bucket := diagBucketHour(occurred)
	op, _ := ev["operation_key"].(string)
	reason, _ := ev["reason"].(string)
	if reason == "" {
		reason = "unknown"
	}
	dataset, _ := ev["dataset"].(string)
	source, _ := ev["source_identity"].(string)
	group, _ := ev["limiter_group"].(string)
	st := 0
	if status != nil {
		st = *status
	}
	key := diagDimHash(bucket, op, kind, st, reason, dataset, source, group)
	r := rows[key]
	if r == nil {
		r = &store.EndpointMetricDelta{
			BucketAt: parseDiagTime(bucket), Operation: op, EventKind: kind,
			HTTPStatus: st, Reason: reason, Dataset: dataset, SourceID: source,
			LimiterGroup: group, FirstSeen: parseDiagTime(occurred), LastSeen: parseDiagTime(occurred),
		}
		h := sha256.Sum256([]byte(bucket + op + kind + reason + dataset + source + group))
		r.DimensionHash = hex.EncodeToString(h[:])
		rows[key] = r
	}
	r.Requests++
	upstream := kind == "http_attempt" || kind == "connection_failure"
	if upstream && status != nil && (*status < 300 || *status == 304) {
		r.Successes++
	}
	if upstream && (status == nil || *status >= 400) {
		r.Errors++
	}
	if d, ok := numOf(ev["duration_ms"]); ok {
		r.DurationSum += int64(d)
		r.DurationCount++
		if int(d) > r.DurationMax {
			r.DurationMax = int(d)
		}
	}
	if t := parseDiagTime(occurred); t.Before(r.FirstSeen) {
		r.FirstSeen = t
	}
	if t := parseDiagTime(occurred); t.After(r.LastSeen) {
		r.LastSeen = t
	}
}

// addBudget ports the minute/hour budget rollup.
func addBudget(rows map[string]*store.BudgetMetricDelta, ev map[string]any) {
	kind, _ := ev["kind"].(string)
	occurred, _ := ev["occurred_at"].(string)
	type obs struct {
		mechanism string
		group     string
		remaining *int64
		used      *int64
		opened    int64
		closed    int64
		pause     int64
	}
	var observations []obs
	intPtr := func(v any) *int64 {
		if n, ok := numOf(v); ok {
			val := int64(n)
			return &val
		}
		return nil
	}
	switch kind {
	case "http_attempt":
		if r := intPtr(ev["legacy_remaining"]); r != nil {
			observations = append(observations, obs{mechanism: "legacy", remaining: r})
		}
		if g, _ := ev["limiter_group"].(string); g != "" {
			if r := intPtr(ev["token_remaining"]); r != nil {
				observations = append(observations, obs{mechanism: "token", group: g, remaining: r, used: intPtr(ev["token_used"])})
			}
		}
	case "circuit_opened":
		observations = append(observations, obs{mechanism: "legacy", opened: 1})
	case "circuit_closed":
		var pause int64
		if d, ok := numOf(ev["duration_ms"]); ok {
			pause = int64(d) / 1000
		}
		observations = append(observations, obs{mechanism: "legacy", closed: 1, pause: pause})
	}
	for _, o := range observations {
		for _, g := range []struct {
			name   string
			length int
		}{{"minute", 16}, {"hour", 13}} {
			if len(occurred) < g.length {
				continue
			}
			bucket := occurred[:g.length] + map[string]string{"minute": ":00", "hour": ":00:00"}[g.name]
			key := g.name + "|" + bucket + "|" + o.mechanism + "|" + o.group
			r := rows[key]
			if r == nil {
				r = &store.BudgetMetricDelta{
					BucketAt: parseDiagTime(bucket), Granularity: g.name,
					Mechanism: o.mechanism, LimiterGroup: o.group,
				}
				h := sha256.Sum256([]byte(key))
				r.DimensionHash = hex.EncodeToString(h[:])
				rows[key] = r
			}
			r.Observations++
			if o.remaining != nil {
				v := *o.remaining
				if r.RemainingMin == nil || v < *r.RemainingMin {
					r.RemainingMin = &v
				}
				if r.RemainingMax == nil || v > *r.RemainingMax {
					r.RemainingMax = &v
				}
				if r.RemainingFirst == nil {
					r.RemainingFirst = &v
				}
				lv := v
				r.RemainingLast = &lv
			}
			if o.used != nil {
				if r.UsedSum == nil {
					r.UsedSum = new(int64)
				}
				*r.UsedSum += *o.used
			}
			r.CircuitOpened += o.opened
			r.CircuitClosed += o.closed
			r.PauseSeconds += o.pause
		}
	}
}

func diagBucketHour(occurred string) string {
	if len(occurred) >= 13 {
		return occurred[:13] + ":00:00"
	}
	return occurred
}

func parseDiagTime(s string) time.Time {
	for _, layout := range []string{"2006-01-02 15:04:05.999999", "2006-01-02 15:04:05", time.RFC3339} {
		if t, err := time.Parse(layout, s); err == nil {
			return t.UTC()
		}
	}
	return time.Now().UTC()
}

func diagDimHash(parts ...any) string {
	h := sha256.New()
	for _, p := range parts {
		h.Write([]byte(hex.EncodeToString([]byte(hexOf(p)))))
	}
	sum := h.Sum(nil)
	return hex.EncodeToString(sum)
}

func hexOf(v any) string {
	return hex.EncodeToString([]byte(hexStr(v)))
}

func hexStr(v any) string {
	switch n := v.(type) {
	case string:
		return n
	case int:
		return itoaJob(n)
	default:
		return ""
	}
}

func itoaJob(n int) string {
	if n == 0 {
		return "0"
	}
	neg := n < 0
	if neg {
		n = -n
	}
	var b [20]byte
	p := len(b)
	for n > 0 {
		p--
		b[p] = byte('0' + n%10)
		n /= 10
	}
	if neg {
		p--
		b[p] = '-'
	}
	return string(b[p:])
}

// PruneDiagnostics ports EsiDiagnosticsRetention::prune (500-row batches).
func PruneDiagnostics(ctx context.Context, h DiagFlusherDeps, budget time.Duration) (map[string]int64, error) {
	deadline := time.Now().Add(budget)
	now := time.Now()
	plan := []struct {
		name  string
		table string
		col   string
		cut   time.Time
		gran  *string
	}{
		{"events", "esi_diagnostic_events", "occurred_at", now.AddDate(0, 0, -14), nil},
		{"endpoint_metrics", "esi_endpoint_metrics", "bucket_at", now.AddDate(0, 0, -90), nil},
		{"budget_minute", "esi_budget_metrics", "bucket_at", now.AddDate(0, 0, -7), strPtr("minute")},
		{"budget_hour", "esi_budget_metrics", "bucket_at", now.AddDate(0, 0, -90), strPtr("hour")},
	}
	out := map[string]int64{}
	var total int64
	for _, p := range plan {
		var deleted int64
		for {
			n, err := h.DB.PruneDiagTable(ctx, p.table, p.col, p.gran, p.cut, 500)
			if err != nil {
				return out, err
			}
			deleted += n
			out[p.name] = deleted
			total += n
			if n < 500 || time.Now().After(deadline) {
				break
			}
		}
	}
	if total > 0 {
		if err := h.DB.BumpDiagExpired(ctx, total); err != nil {
			return out, err
		}
	}
	return out, nil
}

func strPtr(s string) *string { return &s }

func diagStr(v any) string {
	if s, ok := v.(string); ok {
		return s
	}
	return ""
}

func diagStrDef(v any, def string) string {
	if s, ok := v.(string); ok && s != "" {
		return s
	}
	return def
}
