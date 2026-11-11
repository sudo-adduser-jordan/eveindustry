package jobs

import (
	"context"
	"fmt"
	"strings"
	"time"
)

type ScheduleEntry struct {
	Name     string
	Every    time.Duration
	At       string
	CronDays string
	CronDOM  string
	When     func(ctx context.Context) bool
	Run      func(ctx context.Context, r *Runner, q Queue) error
	Overlap  time.Duration
}

type Scheduler struct {
	Entries []ScheduleEntry
	Runner  *Runner
	Queue   Queue
	Locks   Locker
	Now     func() time.Time
	Log     func(msg string, args ...any)
}

func (e ScheduleEntry) Due(now time.Time, last time.Time) bool {
	if !last.IsZero() {
		window := e.Every
		if window <= 0 {
			window = 24 * time.Hour
		}
		if now.Sub(last) < window {
			return false
		}
	}
	if e.At == "" {
		return true
	}
	hh, mm := e.At[0:2], e.At[3:5]
	var h, m int
	fmt.Sscanf(hh, "%d", &h)
	fmt.Sscanf(mm, "%d", &m)
	if now.Hour() != h || now.Minute() != m {
		return false
	}
	if e.CronDays != "" && e.CronDays != "*" {
		allowed := false
		for _, d := range strings.Split(e.CronDays, ",") {
			if strings.EqualFold(strings.TrimSpace(d), now.Weekday().String()[:3]) ||
				strings.EqualFold(strings.TrimSpace(d), now.Weekday().String()) {
				allowed = true
			}
		}
		if !allowed {
			return false
		}
	}
	if e.CronDOM != "" && e.CronDOM != "*" {
		allowed := false
		for _, d := range strings.Split(e.CronDOM, ",") {
			var day int
			fmt.Sscanf(strings.TrimSpace(d), "%d", &day)
			if day == now.Day() {
				allowed = true
			}
		}
		if !allowed {
			return false
		}
	}
	return true
}

func (s *Scheduler) Tick(ctx context.Context, last map[string]time.Time) map[string]time.Time {
	now := time.Now()
	if s.Now != nil {
		now = s.Now()
	}
	next := map[string]time.Time{}
	for k, v := range last {
		next[k] = v
	}
	for _, e := range s.Entries {
		if !e.Due(now, last[e.Name]) {
			continue
		}
		if e.When != nil && !e.When(ctx) {
			continue
		}
		window := e.Overlap
		if window <= 0 {
			window = e.Every
		}
		if window <= 0 {
			window = time.Minute
		}
		if s.Locks != nil {
			if !s.Locks.Acquire(ctx, "jobs:schedule:"+e.Name, window) {
				continue
			}
		}
		if err := e.Run(ctx, s.Runner, s.Queue); err != nil && s.Log != nil {
			s.Log("schedule failed", "entry", e.Name, "err", err)
		}
		next[e.Name] = now
	}
	return next
}
