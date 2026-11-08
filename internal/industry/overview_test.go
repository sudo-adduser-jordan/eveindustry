package industry

import (
	"testing"
	"time"
)

func TestAssembleJob(t *testing.T) {
	now := time.Date(2026, 11, 8, 12, 0, 0, 0, time.UTC)
	raw := RawJob{
		JobID: 1, ActivityID: ActivityManufacturing, Runs: 10,
		Status: "active",
		StartDate: now.Add(-time.Hour), EndDate: now.Add(3 * time.Hour),
	}
	img := "http://img"
	got := AssembleJob(raw, "Main", "Rifter", &img, 1, "Jita", nil, now)
	if got.ProgressPct != 25 || got.SecondsRemaining != 10800 {
		t.Fatalf("got %+v", got)
	}
	if got.TimeRemaining != "3h 0m" || !got.ReadyForDelivery == false {
		t.Fatalf("time: %+v", got)
	}
	if got.ExpectedOutput != 10 || got.EndDate != "2026-11-08T15:00:00Z" {
		t.Fatalf("out: %+v", got)
	}
	// invention with probability
	prob := 0.3
	raw2 := RawJob{JobID: 2, ActivityID: ActivityInvention, Runs: 4, Status: "active",
		StartDate: now, EndDate: now.Add(time.Hour), Probability: &prob}
	got2 := AssembleJob(raw2, "M", "X", nil, 1, "L", nil, now)
	if got2.ExpectedOutput != 1.2 {
		t.Fatalf("invention: %+v", got2)
	}
}

func TestGroupAndSummary(t *testing.T) {
	rows := []JobRow{
		{JobID: 2, ActivityID: ActivityManufacturing, ActivityName: "Manufacturing", ItemName: "B", ExpectedOutput: 5, Runs: 1, SecondsRemaining: 9, TypeID: intPtr(2)},
		{JobID: 1, ActivityID: ActivityManufacturing, ActivityName: "Manufacturing", ItemName: "A", ExpectedOutput: 3, Runs: 2, SecondsRemaining: 5, TypeID: intPtr(1)},
		{JobID: 3, ActivityID: 99, ActivityName: "Unknown", ItemName: "?", ExpectedOutput: 1, Runs: 1},
	}
	groups := GroupJobs(rows)
	if len(groups) != 1 || groups[0].Jobs[0].JobID != 1 {
		t.Fatalf("groups: %+v", groups)
	}
	sum := BuildSummary(rows)
	if len(sum) != 2 || sum[0].ItemName != "A" || sum[0].TotalExpectedOutput != 3 {
		t.Fatalf("summary: %+v", sum)
	}
}
