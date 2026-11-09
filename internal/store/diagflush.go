package store

import (
	"context"
	"database/sql"
	"time"
)

// DiagCheckpoint mirrors the collector checkpoint row.
type DiagCheckpoint struct {
	Name            string         `db:"name"`
	StreamEpoch     sql.NullString `db:"stream_epoch"`
	LastStreamID    sql.NullString `db:"last_stream_id"`
	LastFlushedAt   sql.NullTime   `db:"last_flushed_at"`
	GapCount        int64          `db:"gap_count"`
	LastGapAt       sql.NullTime   `db:"last_gap_at"`
	LastGapReason   sql.NullString `db:"last_gap_reason"`
	DroppedEvents   int64          `db:"dropped_events"`
	ExpiredRows     int64          `db:"expired_rows"`
	EntriesConsumed int64          `db:"entries_consumed"`
}

// DiagFlushStore covers the diagnostics drain (flush) and retention (prune).
type DiagFlushStore interface {
	EnsureDiagCheckpoint(ctx context.Context) error
	DiagCheckpointForUpdate(ctx context.Context, tx *sql.Tx) (*DiagCheckpoint, error)
	UpdateDiagCheckpoint(ctx context.Context, checkpoint DiagCheckpoint) error
	BumpDiagExpired(ctx context.Context, total int64) error
	InsertDiagEvents(ctx context.Context, rows []DiagEventRow) error
	MergeEndpointMetric(ctx context.Context, m EndpointMetricDelta) error
	MergeBudgetMetric(ctx context.Context, m BudgetMetricDelta) error
	PruneDiagTable(ctx context.Context, table, column string, granularity *string, cutoff time.Time, batch int) (int64, error)
}

// DiagEventRow is one esi_diagnostic_events insert.
type DiagEventRow struct {
	EventID          string
	OccurredAt       time.Time
	Kind             string
	Method           *string
	Operation        string
	Compatibility    *string
	Dataset          *string
	JobClass         *string
	CorrelationID    *string
	SourceIdentity   string
	ApplicationID    string
	CharacterID      *int64
	CorporationID    *int64
	ResourceID       *int64
	Page             *int
	BatchSize        *int
	HTTPStatus       *int
	Reason           string
	DurationMs       *int
	ResponseReceived bool
	LimiterGroup     *string
	LegacyRemaining  *int
	RetryAfter       *int
	UpstreamReqID    *string
	BatchFP          *string
	Release          *string
}

// EndpointMetricDelta is one merged endpoint bucket increment.
type EndpointMetricDelta struct {
	DimensionHash string
	BucketAt      time.Time
	Operation     string
	EventKind     string
	HTTPStatus    int
	Reason        string
	Dataset       string
	SourceID      string
	LimiterGroup  string
	Requests      int64
	Successes     int64
	Errors        int64
	DurationSum   int64
	DurationCount int64
	DurationMax   int
	FirstSeen     time.Time
	LastSeen      time.Time
}

// BudgetMetricDelta is one merged budget bucket increment.
type BudgetMetricDelta struct {
	DimensionHash  string
	BucketAt       time.Time
	Granularity    string
	SourceID       string
	ApplicationID  string
	Mechanism      string
	LimiterGroup   string
	Observations   int64
	RemainingMin   *int64
	RemainingMax   *int64
	RemainingFirst *int64
	RemainingLast  *int64
	UsedSum        *int64
	ResetMin       *time.Time
	ResetMax       *time.Time
	CircuitOpened  int64
	CircuitClosed  int64
	PauseSeconds   int64
}

// EnsureDiagCheckpoint inserts the collector row when missing.
func (s *SQL) EnsureDiagCheckpoint(ctx context.Context) error {
	_, err := s.db.ExecContext(ctx, `INSERT OR IGNORE INTO esi_telemetry_checkpoints (name, updated_at) VALUES ('collector', CURRENT_TIMESTAMP)`)
	return err
}

// DiagCheckpointForUpdate reads the collector row. Inside a transaction the
// SQLite write lock serializes concurrent flushers (no FOR UPDATE).
func (s *SQL) DiagCheckpointForUpdate(ctx context.Context, tx *sql.Tx) (*DiagCheckpoint, error) {
	var out DiagCheckpoint
	var err error
	if tx != nil {
		err = tx.QueryRowContext(ctx, `SELECT name, stream_epoch, last_stream_id, last_flushed_at,
			gap_count, last_gap_at, last_gap_reason, dropped_events, expired_rows, entries_consumed
			FROM esi_telemetry_checkpoints WHERE name = 'collector'`).Scan(
			&out.Name, &out.StreamEpoch, &out.LastStreamID, &out.LastFlushedAt,
			&out.GapCount, &out.LastGapAt, &out.LastGapReason, &out.DroppedEvents,
			&out.ExpiredRows, &out.EntriesConsumed)
	} else {
		err = s.db.GetContext(ctx, &out, `SELECT name, stream_epoch, last_stream_id, last_flushed_at,
			gap_count, last_gap_at, last_gap_reason, dropped_events, expired_rows, entries_consumed
			FROM esi_telemetry_checkpoints WHERE name = 'collector'`)
	}
	if err == sql.ErrNoRows {
		return nil, nil
	}
	return &out, err
}

// UpdateDiagCheckpoint writes the post-batch checkpoint.
func (s *SQL) UpdateDiagCheckpoint(ctx context.Context, checkpoint DiagCheckpoint) error {
	_, err := s.db.ExecContext(ctx, `UPDATE esi_telemetry_checkpoints SET stream_epoch = ?,
		last_stream_id = ?, last_flushed_at = CURRENT_TIMESTAMP, gap_count = ?, last_gap_at = ?,
		last_gap_reason = ?, dropped_events = ?, expired_rows = ?, entries_consumed = ?, updated_at = CURRENT_TIMESTAMP
		WHERE name = 'collector'`,
		checkpoint.StreamEpoch, checkpoint.LastStreamID, checkpoint.GapCount, checkpoint.LastGapAt, checkpoint.LastGapReason,
		checkpoint.DroppedEvents, checkpoint.ExpiredRows, checkpoint.EntriesConsumed)
	return err
}

// BumpDiagExpired tracks deliberate expiry apart from capture loss.
func (s *SQL) BumpDiagExpired(ctx context.Context, total int64) error {
	if _, err := s.db.ExecContext(ctx, `INSERT OR IGNORE INTO esi_telemetry_checkpoints (name, updated_at) VALUES ('collector', CURRENT_TIMESTAMP)`); err != nil {
		return err
	}
	_, err := s.db.ExecContext(ctx, `UPDATE esi_telemetry_checkpoints SET expired_rows = expired_rows + ?, updated_at = CURRENT_TIMESTAMP WHERE name = 'collector'`, total)
	return err
}

// InsertDiagEvents batch-inserts validated detail rows (25 per chunk).
func (s *SQL) InsertDiagEvents(ctx context.Context, rows []DiagEventRow) error {
	for _, chunk := range chunkDiagEvents(rows, 25) {
		q := `INSERT OR IGNORE INTO esi_diagnostic_events (event_id, occurred_at, kind, method, operation_key,
			compatibility_date, dataset, job_class, correlation_id, source_identity, application_id,
			character_id, corporation_id, resource_id, page, batch_size, http_status, reason, duration_ms,
			response_received, limiter_group, legacy_remaining, retry_after_seconds, upstream_request_id,
			batch_fingerprint, release) VALUES `
		var args []any
		for i, r := range chunk {
			if i > 0 {
				q += ","
			}
			q += `(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`
			args = append(args, r.EventID, r.OccurredAt, r.Kind, r.Method, r.Operation,
				r.Compatibility, r.Dataset, r.JobClass, r.CorrelationID, r.SourceIdentity, r.ApplicationID,
				r.CharacterID, r.CorporationID, r.ResourceID, r.Page, r.BatchSize, r.HTTPStatus,
				r.Reason, r.DurationMs, r.ResponseReceived, r.LimiterGroup, r.LegacyRemaining,
				r.RetryAfter, r.UpstreamReqID, r.BatchFP, r.Release)
		}
		if _, err := s.db.ExecContext(ctx, q, args...); err != nil {
			return err
		}
	}
	return nil
}

func chunkDiagEvents(in []DiagEventRow, n int) [][]DiagEventRow {
	var out [][]DiagEventRow
	for len(in) > 0 {
		if len(in) < n {
			n = len(in)
		}
		out = append(out, in[:n])
		in = in[n:]
	}
	return out
}

// MergeEndpointMetric ports mergeEndpoint: one atomic upsert that inserts
// the bucket or adds onto it (dimension_hash + bucket_at is unique).
func (s *SQL) MergeEndpointMetric(ctx context.Context, m EndpointMetricDelta) error {
	_, err := s.db.ExecContext(ctx, `INSERT INTO esi_endpoint_metrics (dimension_hash, bucket_at,
		operation_key, event_kind, http_status, reason, dataset, source_identity, limiter_group,
		request_count, success_count, error_count, duration_sum_ms, duration_count, duration_max_ms,
		first_observed_at, last_observed_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
		ON CONFLICT(dimension_hash, bucket_at) DO UPDATE SET
		request_count = request_count + excluded.request_count,
		success_count = success_count + excluded.success_count,
		error_count = error_count + excluded.error_count,
		duration_sum_ms = duration_sum_ms + excluded.duration_sum_ms,
		duration_count = duration_count + excluded.duration_count,
		duration_max_ms = max(duration_max_ms, excluded.duration_max_ms),
		first_observed_at = min(first_observed_at, excluded.first_observed_at),
		last_observed_at = max(last_observed_at, excluded.last_observed_at)`,
		m.DimensionHash, m.BucketAt, m.Operation, m.EventKind, m.HTTPStatus, m.Reason, m.Dataset,
		m.SourceID, m.LimiterGroup, m.Requests, m.Successes, m.Errors, m.DurationSum, m.DurationCount,
		m.DurationMax, m.FirstSeen, m.LastSeen)
	return err
}

// MergeBudgetMetric ports mergeBudget: same atomic insert-or-add shape.
func (s *SQL) MergeBudgetMetric(ctx context.Context, m BudgetMetricDelta) error {
	_, err := s.db.ExecContext(ctx, `INSERT INTO esi_budget_metrics (dimension_hash, bucket_at, granularity,
		source_identity, application_id, mechanism, limiter_group, observations, remaining_min, remaining_max,
		remaining_first, remaining_last, used_sum, reset_at_min, reset_at_max,
		circuit_opened_count, circuit_closed_count, pause_seconds) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
		ON CONFLICT(dimension_hash, bucket_at) DO UPDATE SET
		observations = observations + excluded.observations,
		remaining_min = min(coalesce(remaining_min, excluded.remaining_min), coalesce(excluded.remaining_min, remaining_min)),
		remaining_max = max(coalesce(remaining_max, excluded.remaining_max), coalesce(excluded.remaining_max, remaining_max)),
		used_sum = coalesce(used_sum, 0) + coalesce(excluded.used_sum, 0),
		reset_at_min = min(coalesce(reset_at_min, excluded.reset_at_min), coalesce(excluded.reset_at_min, reset_at_min)),
		reset_at_max = max(coalesce(reset_at_max, excluded.reset_at_max), coalesce(excluded.reset_at_max, reset_at_max)),
		circuit_opened_count = circuit_opened_count + excluded.circuit_opened_count,
		circuit_closed_count = circuit_closed_count + excluded.circuit_closed_count,
		pause_seconds = pause_seconds + excluded.pause_seconds`,
		m.DimensionHash, m.BucketAt, m.Granularity, m.SourceID, m.ApplicationID, m.Mechanism,
		m.LimiterGroup, m.Observations, m.RemainingMin, m.RemainingMax, m.RemainingFirst, m.RemainingLast,
		m.UsedSum, m.ResetMin, m.ResetMax, m.CircuitOpened, m.CircuitClosed, m.PauseSeconds)
	return err
}

// PruneDiagTable deletes expired rows in id batches (500 default).
func (s *SQL) PruneDiagTable(ctx context.Context, table, column string, granularity *string, cutoff time.Time, batch int) (int64, error) {
	var total int64
	for {
		q := `SELECT id FROM ` + table + ` WHERE ` + column + ` < ?`
		args := []any{cutoff}
		if granularity != nil {
			q += ` AND granularity = ?`
			args = append(args, *granularity)
		}
		q += ` ORDER BY ` + column + ` LIMIT ?`
		args = append(args, batch)
		var ids []int64
		if err := s.db.SelectContext(ctx, &ids, q, args...); err != nil {
			return total, err
		}
		if len(ids) == 0 {
			break
		}
		iq, iargs, err := sqlxIn(`DELETE FROM `+table+` WHERE id IN (?)`, anySlice(ids))
		if err != nil {
			return total, err
		}
		res, err := s.db.ExecContext(ctx, s.db.Rebind(iq), iargs...)
		if err != nil {
			return total, err
		}
		n, _ := res.RowsAffected()
		total += n
		if len(ids) < batch {
			break
		}
	}
	return total, nil
}

func anySlice(in []int64) []any {
	out := make([]any, 0, len(in))
	for _, v := range in {
		out = append(out, v)
	}
	return out
}
