package industry

import (
	"math"
	"sort"
	"strings"
	"time"
)

// Industry-specific activity IDs not covered by the blueprint constants.
const (
	ActivityResearchTE = 3
	ActivityResearchME = 4
	ActivityReactions  = 9
)

// ActivityNames mirrors the buildJobsOverview() label map.
var ActivityNames = map[int]string{
	ActivityManufacturing: "Manufacturing",
	ActivityResearchTE:    "Time Efficiency Research",
	ActivityResearchME:    "Material Efficiency Research",
	ActivityCopying:       "Copying",
	ActivityInvention:     "Invention",
	ActivityReactions:     "Reactions",
}

// ValueActivities mirrors JobsOverviewService::VALUE_ACTIVITIES.
var ValueActivities = map[string]bool{"Manufacturing": true, "Reactions": true}

// RawJob is one ESI industry job row (character jobs) with the fields the
// overview needs.
type RawJob struct {
	JobID          int64
	ActivityID     int
	BlueprintTypeID *int64
	ProductTypeID  *int64
	Runs           int
	Status         string
	StartDate      time.Time
	EndDate        time.Time
	Probability    *float64
	StationID      *uint64
	FacilityID     *uint64
	SolarSystemID  *int
}

// JobRow is one assembled overview job, mirroring buildJobsOverview rows.
type JobRow struct {
	JobID            int64   `json:"job_id"`
	ActivityID       int     `json:"activity_id"`
	ActivityName     string  `json:"activity_name"`
	CharacterName    string  `json:"character_name"`
	TypeID           *int    `json:"type_id"`
	ItemName         string  `json:"item_name"`
	ItemImageURL     *string `json:"item_image_url"`
	Runs             int     `json:"runs"`
	ProbabilityPct   *float64 `json:"probability_pct"`
	ExpectedOutput   float64 `json:"expected_output"`
	TimeRemaining    string  `json:"time_remaining"`
	SecondsRemaining int64   `json:"seconds_remaining"`
	EndDate          string  `json:"end_date"`
	ProgressPct      float64 `json:"progress_pct"`
	ReadyForDelivery bool    `json:"ready_for_delivery"`
	Location         string  `json:"location"`
}

// AssembleJob ports the per-job half of buildJobsOverview(): progress, time
// remaining, expected output and the record shape. Names, images, portions
// and locations are resolved by the caller (ESI/store).
func AssembleJob(job RawJob, characterName, itemName string, imageURL *string, outputPerRun int, location string, probabilityPct *float64, now time.Time) JobRow {
	total := job.EndDate.Sub(job.StartDate).Seconds()
	elapsed := now.Sub(job.StartDate).Seconds()
	remaining := int64(math.Max(0, job.EndDate.Sub(now).Seconds()))
	progress := 100.0
	if total > 0 {
		progress = math.Min(100, math.Round(elapsed/total*100))
	}
	days := remaining / 86400
	hours := (remaining % 86400) / 3600
	minutes := (remaining % 3600) / 60
	parts := []string{}
	if days > 0 {
		parts = append(parts, itoa(int(days))+"d")
	}
	if hours > 0 || days > 0 {
		parts = append(parts, itoa(int(hours))+"h")
	}
	parts = append(parts, itoa(int(minutes))+"m")
	timeRemaining := strings.Join(parts, " ")

	var expected float64
	switch {
	case job.ActivityID == ActivityInvention && job.Probability != nil:
		expected = math.Round(float64(job.Runs)**job.Probability*100) / 100
	case job.ActivityID == ActivityManufacturing || job.ActivityID == ActivityReactions:
		expected = float64(job.Runs * outputPerRun)
	default:
		expected = float64(job.Runs)
	}

	var typeID *int
	var productID *int64 = job.ProductTypeID
	if productID == nil {
		productID = job.BlueprintTypeID
	}
	if productID != nil {
		v := int(*productID)
		typeID = &v
	}
	return JobRow{
		JobID: job.JobID, ActivityID: job.ActivityID,
		ActivityName: ActivityNames[job.ActivityID], CharacterName: characterName,
		TypeID: typeID, ItemName: itemName, ItemImageURL: imageURL,
		Runs: job.Runs, ProbabilityPct: probabilityPct, ExpectedOutput: expected,
		TimeRemaining: timeRemaining, SecondsRemaining: remaining,
		EndDate: job.EndDate.UTC().Format("2006-01-02T15:04:05Z"),
		ProgressPct: progress, ReadyForDelivery: remaining <= 60, Location: location,
	}
}

// GroupJobs ports the grouping tail: known activities only, each group by
// seconds remaining ascending.
func GroupJobs(rows []JobRow) []JobGroup {
	byName := map[string][]JobRow{}
	for _, r := range rows {
		if _, ok := ActivityNames[r.ActivityID]; !ok {
			continue
		}
		byName[r.ActivityName] = append(byName[r.ActivityName], r)
	}
	out := []JobGroup{}
	for name, jobs := range byName {
		sort.Slice(jobs, func(i, j int) bool { return jobs[i].SecondsRemaining < jobs[j].SecondsRemaining })
		out = append(out, JobGroup{ActivityName: name, Jobs: jobs})
	}
	sort.Slice(out, func(i, j int) bool { return out[i].ActivityName < out[j].ActivityName })
	return out
}

// JobGroup is one activity group.
type JobGroup struct {
	ActivityName string   `json:"activity_name"`
	Jobs         []JobRow `json:"jobs"`
}

// SummaryItem mirrors one buildProductionSummary() row.
type SummaryItem struct {
	TypeID             *int    `json:"type_id"`
	ItemName           string  `json:"item_name"`
	ItemImageURL       *string `json:"item_image_url"`
	TotalRuns          int     `json:"total_runs"`
	TotalExpectedOutput int    `json:"total_expected_output"`
	JobCount           int     `json:"job_count"`
	EstimatedValue     *float64 `json:"estimated_value"`
}

// BuildSummary ports buildProductionSummary(): manufacturing + reaction
// output totalled per item name, totals floored, sorted by name.
func BuildSummary(rows []JobRow) []SummaryItem {
	type agg struct {
		typeID   *int
		name     string
		image    *string
		output   float64
		runs     int
		count    int
	}
	byName := map[string]*agg{}
	var order []string
	for _, r := range rows {
		if !ValueActivities[r.ActivityName] {
			continue
		}
		a, ok := byName[r.ItemName]
		if !ok {
			a = &agg{typeID: r.TypeID, name: r.ItemName, image: r.ItemImageURL}
			byName[r.ItemName] = a
			order = append(order, r.ItemName)
		}
		a.output += r.ExpectedOutput
		a.runs += r.Runs
		a.count++
	}
	out := make([]SummaryItem, 0, len(byName))
	for _, name := range order {
		a := byName[name]
		out = append(out, SummaryItem{
			TypeID: a.typeID, ItemName: a.name, ItemImageURL: a.image,
			TotalRuns: a.runs, TotalExpectedOutput: int(math.Floor(a.output)), JobCount: a.count,
		})
	}
	sort.Slice(out, func(i, j int) bool { return out[i].ItemName < out[j].ItemName })
	return out
}
