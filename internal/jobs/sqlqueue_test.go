package jobs

import (
	"context"
	"encoding/json"
	"testing"
	"time"

	"github.com/jmoiron/sqlx"
	_ "modernc.org/sqlite"
)

const queueTestDDL = `
CREATE TABLE go_jobs (
  id INTEGER NOT NULL,
  queue TEXT NOT NULL,
  name TEXT NOT NULL,
  payload TEXT NOT NULL DEFAULT '',
  attempts INTEGER NOT NULL DEFAULT 0,
  max_tries INTEGER NOT NULL DEFAULT 1,
  enqueued_at TEXT NOT NULL,
  available_at TEXT NOT NULL DEFAULT (CURRENT_TIMESTAMP),
  PRIMARY KEY (id)
);
CREATE TABLE go_locks (
  name TEXT NOT NULL,
  expires_at TEXT NOT NULL,
  PRIMARY KEY (name)
);`

func openTestDB(t *testing.T) *sqlx.DB {
	t.Helper()
	db, err := sqlx.Connect("sqlite", ":memory:?_pragma=busy_timeout(5000)&_time_format=sqlite&_timezone=UTC&_texttotime=1")
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { _ = db.Close() })
	if _, err := db.Exec(queueTestDDL); err != nil {
		t.Fatal(err)
	}
	return db
}

func TestSQLQueueFIFO(t *testing.T) {
	db := openTestDB(t)
	ctx := context.Background()
	q := &SQLQueue{DB: db}

	if n, err := q.Len(ctx, QueueDefault); err != nil || n != 0 {
		t.Fatalf("Len empty: %v %d", err, n)
	}
	if err := q.Push(ctx, QueueDefault, Envelope{Name: "a", MaxTries: 2}); err != nil {
		t.Fatalf("Push: %v", err)
	}
	if err := q.Push(ctx, QueueUpdates, Envelope{Name: "u", MaxTries: 1}); err != nil {
		t.Fatalf("Push: %v", err)
	}
	if err := q.Push(ctx, QueueDefault, Envelope{Name: "b", Payload: MarshalPayload(map[string]int{"n": 1})}); err != nil {
		t.Fatalf("Push: %v", err)
	}
	name, e, ok, err := q.Pop(ctx, []string{QueueDefault, QueueUpdates}, time.Second)
	if err != nil || !ok || name != QueueDefault || e.Name != "a" || e.MaxTries != 2 {
		t.Fatalf("Pop first: %v %v %q %+v", err, ok, name, e)
	}
	name, e, ok, err = q.Pop(ctx, []string{QueueUpdates}, time.Second)
	if err != nil || !ok || name != QueueUpdates || e.Name != "u" {
		t.Fatalf("Pop updates: %v %v %q %+v", err, ok, name, e)
	}
	if n, err := q.Len(ctx, QueueDefault); err != nil || n != 1 {
		t.Fatalf("Len: %v %d", err, n)
	}
	// Empty pop waits out the deadline instead of erroring.
	start := time.Now()
	_, _, ok, err = q.Pop(ctx, []string{"missing"}, 300*time.Millisecond)
	if err != nil || ok || time.Since(start) < 300*time.Millisecond {
		t.Fatalf("Pop empty: %v %v", err, ok)
	}
}

func TestSQLQueueDispatchRoundTrip(t *testing.T) {
	db := openTestDB(t)
	ctx := context.Background()
	q := &SQLQueue{DB: db}
	r := NewRunner(Deps{Locks: &DBLocker{DB: db}})
	ran := 0
	r.Register("noop", JobDef{Queue: QueueDefault, MaxTries: 1, Timeout: time.Minute,
		Run: func(ctx context.Context, d Deps, _ json.RawMessage) error { ran++; return nil }})
	if err := r.Dispatch(ctx, q, "noop", nil); err != nil {
		t.Fatalf("Dispatch: %v", err)
	}
	_, e, ok, err := q.Pop(ctx, []string{QueueDefault}, time.Second)
	if err != nil || !ok {
		t.Fatalf("Pop: %v %v", err, ok)
	}
	if err := r.RunOne(ctx, e); err != nil || ran != 1 {
		t.Fatalf("RunOne: %v ran=%d", err, ran)
	}
}

func TestDBLocker(t *testing.T) {
	db := openTestDB(t)
	ctx := context.Background()
	l := &DBLocker{DB: db}

	if !l.Acquire(ctx, "k", time.Minute) {
		t.Fatal("first acquire must win")
	}
	if l.Acquire(ctx, "k", time.Minute) {
		t.Fatal("second acquire must lose")
	}
	l.Release(ctx, "k")
	if !l.Acquire(ctx, "k", time.Minute) {
		t.Fatal("re-acquire after release must win")
	}
	// Expiry lapses (second granularity, so sleep past the tick).
	if !l.Acquire(ctx, "short", 100*time.Millisecond) {
		t.Fatal("short acquire must win")
	}
	time.Sleep(1200 * time.Millisecond)
	if !l.Acquire(ctx, "short", time.Minute) {
		t.Fatal("expired lock must be acquirable")
	}
	var nilL *DBLocker
	if !nilL.Acquire(ctx, "k", time.Minute) {
		t.Fatal("nil locker must be permissive")
	}
	nilL.Release(ctx, "k")
}
