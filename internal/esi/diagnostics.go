package esi

import (
	"context"
	"time"
)

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

type DiagSink interface {
	Emit(ctx context.Context, e DiagEvent)
}

type DiagRecorder struct {
	Enabled bool
	Sink    DiagSink
	Dropped int64
}

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
