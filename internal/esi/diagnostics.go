package esi

import (
	"context"
	"time"
)

// DiagEventKind mirrors the diagnostics event kinds.
type DiagEventKind string

const (
	DiagHTTPAttempt     DiagEventKind = "http_attempt"
	DiagConnectionFail  DiagEventKind = "connection_failure"
	DiagLocalDeferral   DiagEventKind = "local_deferral"
	DiagCacheHit        DiagEventKind = "cache_hit"
	DiagCacheMiss       DiagEventKind = "cache_miss"
	DiagCacheRevalidate DiagEventKind = "cache_revalidation"
	DiagCircuitOpen     DiagEventKind = "circuit_opened"
	DiagCircuitHalfOpen DiagEventKind = "circuit_half_open"
	DiagBudgetExhausted DiagEventKind = "budget_exhausted"
	DiagSkipped         DiagEventKind = "skipped"
)

// DiagEvent is one passive capture record. Nothing here may alter an ESI
// outcome: recording never returns errors to the caller.
type DiagEvent struct {
	Kind        DiagEventKind
	Method      string
	Endpoint    string
	Operation   string
	CharacterID int64
	HTTPStatus  int
	Reason      string
	DurationMs  *int
	Group       string
	Dataset     string
	OccurredAt  time.Time
}

// DiagSink receives events. The Redis-stream sink and the flush worker land
// with the jobs phase; until then the recorder drops (no-op when disabled).
type DiagSink interface {
	Emit(ctx context.Context, e DiagEvent)
}

// DiagRecorder is the passive capture facade. Enabled gates everything;
// Emit swallows sink failures via the dropped counter.
type DiagRecorder struct {
	Enabled bool
	Sink    DiagSink
	Dropped int64
}

// Record emits unless disabled or sinkless.
func (r *DiagRecorder) Record(ctx context.Context, e DiagEvent) {
	if r == nil || !r.Enabled || r.Sink == nil {
		return
	}
	if e.OccurredAt.IsZero() {
		e.OccurredAt = time.Now()
	}
	func() {
		defer func() {
			_ = recover()
		}()
		r.Sink.Emit(ctx, e)
	}()
}
