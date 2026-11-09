package jobs

import (
	"context"
	"encoding/json"
	"fmt"
	"log/slog"
	"time"
)

// Handler runs one job's payload. Returning an error retries the job until
// MaxTries, mirroring Horizon's tries/timeout contract ($timeout caps a
// single attempt; attempts beyond MaxTries are dropped with a log).
type Handler func(ctx context.Context, d Deps, payload json.RawMessage) error

// JobDef registers a job: its queue, retry budget and single-attempt timeout.
type JobDef struct {
	Queue    string
	MaxTries int
	Timeout  time.Duration
	Unique   time.Duration // dedupe window; 0 disables (ShouldBeUnique)
	Run      Handler
}

// Deps carries what job bodies need. Concrete wiring lives in cmd/worker.
type Deps struct {
	Log *slog.Logger
	// Locks is nil when the process runs without a database: uniqueness
	// windows become no-ops.
	Locks Locker
}

// Runner dispatches envelopes to registered handlers.
type Runner struct {
	Jobs map[string]JobDef
	Deps Deps
}

// NewRunner builds an empty runner.
func NewRunner(d Deps) *Runner { return &Runner{Jobs: map[string]JobDef{}, Deps: d} }

// Register adds a job definition.
func (r *Runner) Register(name string, def JobDef) { r.Jobs[name] = def }

// uniqueKey mirrors ShouldBeUnique: one active envelope per name+payload.
func uniqueKey(name string, payload json.RawMessage) string {
	return "jobs:unique:" + name + ":" + string(payload)
}

// AcquireUnique holds the uniqueness lock for window. False means a
// duplicate is already queued/running and this dispatch is skipped.
func AcquireUnique(ctx context.Context, l Locker, name string, payload json.RawMessage, window time.Duration) bool {
	if l == nil || window <= 0 {
		return true
	}
	return l.Acquire(ctx, uniqueKey(name, payload), window)
}

// Dispatch pushes an envelope, honoring the job's uniqueness window.
func (r *Runner) Dispatch(ctx context.Context, q Queue, name string, payload json.RawMessage) error {
	def, ok := r.Jobs[name]
	if !ok {
		return fmt.Errorf("unknown job %s", name)
	}
	if def.Unique > 0 && !AcquireUnique(ctx, r.Deps.Locks, name, payload, def.Unique) {
		return nil
	}
	return q.Push(ctx, def.Queue, Envelope{Name: name, Payload: payload, MaxTries: def.MaxTries})
}

// RunOne executes a single envelope with its timeout. A nil error releases
// the uniqueness lock; failures keep it until the window lapses (like
// ShouldBeUniqueUntilProcessing expiring with the job).
func (r *Runner) RunOne(ctx context.Context, e Envelope) error {
	def, ok := r.Jobs[e.Name]
	if !ok {
		return fmt.Errorf("unknown job %s", e.Name)
	}
	log := r.Deps.Log
	if log == nil {
		log = slog.Default()
	}
	attempt := e.Attempts + 1
	tctx, cancel := context.WithTimeout(ctx, def.Timeout)
	defer cancel()
	done := make(chan error, 1)
	go func() {
		defer func() {
			if rec := recover(); rec != nil {
				done <- fmt.Errorf("panic: %v", rec)
			}
		}()
		done <- def.Run(tctx, r.Deps, e.Payload)
	}()
	var err error
	select {
	case <-ctx.Done():
		err = ctx.Err()
	case <-tctx.Done():
		select {
		case err = <-done:
		default:
			err = fmt.Errorf("timeout after %s", def.Timeout)
		}
	case err = <-done:
	}
	if err != nil {
		log.Warn("job failed", "job", e.Name, "attempt", attempt, "max", e.MaxTries, "err", err)
		return err
	}
	if r.Deps.Locks != nil && def.Unique > 0 {
		r.Deps.Locks.Release(ctx, uniqueKey(e.Name, e.Payload))
	}
	return nil
}

// ShouldRetry reports whether a failed envelope goes back on its queue.
func (r *Runner) ShouldRetry(e Envelope) (Envelope, bool) {
	def, ok := r.Jobs[e.Name]
	if !ok {
		return e, false
	}
	if e.Attempts+1 >= maxInt(def.MaxTries, 1) {
		return e, false
	}
	e.Attempts++
	return e, true
}

func maxInt(a, b int) int {
	if a > b {
		return a
	}
	return b
}
