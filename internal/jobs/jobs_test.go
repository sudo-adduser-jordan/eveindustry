package jobs

import (
	"context"
	"encoding/json"
	"errors"
	"testing"
	"time"
)

func TestMemoryQueue(t *testing.T) {
	ctx := context.Background()
	q := NewMemoryQueue()
	if err := q.Push(ctx, QueueDefault, Envelope{Name: "a"}); err != nil {
		t.Fatal(err)
	}
	if err := q.Push(ctx, QueueDefault, Envelope{Name: "b"}); err != nil {
		t.Fatal(err)
	}
	if n, _ := q.Len(ctx, QueueDefault); n != 2 {
		t.Fatalf("len = %d", n)
	}
	name, e, ok, err := q.Pop(ctx, []string{QueueDefault}, time.Second)
	if err != nil || !ok || e.Name != "a" || name != QueueDefault {
		t.Fatalf("pop = %v %v %v", name, e, err)
	}
}

func TestRunnerRetry(t *testing.T) {
	r := NewRunner(Deps{})
	calls := 0
	r.Register("flaky", JobDef{Queue: QueueDefault, MaxTries: 3, Timeout: time.Second,
		Run: func(ctx context.Context, d Deps, raw json.RawMessage) error {
			calls++
			if calls < 2 {
				return errors.New("boom")
			}
			return nil
		}})
	ctx := context.Background()
	e := Envelope{Name: "flaky", MaxTries: 3}
	if err := r.RunOne(ctx, e); err == nil {
		t.Fatal("expected failure")
	}
	next, retry := r.ShouldRetry(e)
	if !retry || next.Attempts != 1 {
		t.Fatalf("retry = %v %v", retry, next)
	}
	if err := r.RunOne(ctx, next); err != nil {
		t.Fatalf("second attempt = %v", err)
	}
	if _, retry := r.ShouldRetry(Envelope{Name: "flaky", Attempts: 2}); retry {
		t.Fatal("should drop at max tries")
	}
}

func TestRunnerUnknown(t *testing.T) {
	r := NewRunner(Deps{})
	if err := r.Dispatch(context.Background(), NewMemoryQueue(), "nope", nil); err == nil {
		t.Fatal("expected unknown job error")
	}
}

func TestSchedulerDue(t *testing.T) {
	now := time.Date(2026, 11, 8, 12, 0, 0, 0, time.UTC)
	interval := ScheduleEntry{Name: "x", Every: 30 * time.Minute}
	if !interval.Due(now, time.Time{}) {
		t.Fatal("first fire should be due")
	}
	if interval.Due(now, now.Add(-time.Minute)) {
		t.Fatal("too soon")
	}
	if !interval.Due(now, now.Add(-time.Hour)) {
		t.Fatal("overdue should fire")
	}
	daily := ScheduleEntry{Name: "y", Every: 24 * time.Hour, At: "04:00"}
	if daily.Due(now, now.Add(-time.Hour)) {
		t.Fatal("wrong time of day")
	}
	at := time.Date(2026, 11, 8, 4, 0, 0, 0, time.UTC)
	if !daily.Due(at, at.Add(-25*time.Hour)) {
		t.Fatal("daily should fire at 04:00")
	}
	weekly := ScheduleEntry{Name: "z", Every: 7 * 24 * time.Hour, At: "03:00", CronDays: "Sun"}
	sunday := time.Date(2026, 11, 8, 3, 0, 0, 0, time.UTC)
	if sunday.Weekday() != time.Sunday {
		t.Fatalf("test date is not Sunday: %v", sunday.Weekday())
	}
	if !weekly.Due(sunday, sunday.Add(-8*24*time.Hour)) {
		t.Fatal("weekly should fire Sunday 03:00")
	}
	monday := time.Date(2026, 11, 9, 3, 0, 0, 0, time.UTC)
	if weekly.Due(monday, monday.Add(-8*24*time.Hour)) {
		t.Fatal("weekly must not fire Monday")
	}
}
