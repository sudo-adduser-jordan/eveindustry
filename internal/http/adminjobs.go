package http

import (
	"context"
	"math"
	"sort"
	"time"

	"github.com/sudo-adduser-jordan/eveindustry/internal/industry"

	"golang.org/x/crypto/bcrypt"
)

func checkPassword(hash, password string) bool {
	return bcrypt.CompareHashAndPassword([]byte(hash), []byte(password)) == nil
}

func hashPassword(password string) (string, error) {
	h, err := bcrypt.GenerateFromPassword([]byte(password), bcrypt.DefaultCost)
	if err != nil {
		return "", err
	}
	return string(h), nil
}

func adminJobsFor(ctx context.Context, d Deps, userID int64, regionID int) (map[string]any, error) {
	now := time.Now().UTC()
	chars, err := d.Users.CharactersForUser(ctx, userID)
	if err != nil {
		return nil, err
	}
	var rows []industry.JobRow
	seen := map[int64]bool{}
	for _, ch := range chars {
		if !industryEligible(ch) {
			continue
		}
		jobs, err := d.IndustryDeps.Characters.GetIndustryJobs(ctx, ch.CharacterID, false)
		if err != nil {
			continue
		}
		for _, cj := range corpJobsFor(ctx, d, userID, ch) {
			jobs = append(jobs, cj)
		}
		for _, j := range jobs {
			raw, ok := parseJob(j)
			if !ok || raw.Status != "active" {
				continue
			}
			if seen[raw.JobID] {
				continue
			}
			seen[raw.JobID] = true
			rows = append(rows, enrichJob(ctx, d, ch, raw, now))
		}
	}
	typeIDs := map[int]bool{}
	for _, r := range rows {
		if r.TypeID != nil {
			typeIDs[*r.TypeID] = true
		}
	}
	ids := make([]int, 0, len(typeIDs))
	for id := range typeIDs {
		ids = append(ids, id)
	}
	prices := map[int]float64{}
	if len(ids) > 0 {
		if resolved, err := resolvePrices(ctx, d.Market, ids, regionID); err == nil {
			for id, r := range resolved {
				prices[id] = r.Price
			}
		}
	}
	valueOf := func(typeID *int, qty float64) *float64 {
		if typeID == nil || prices[*typeID] <= 0 {
			return nil
		}
		v := prices[*typeID] * qty
		return &v
	}
	pricedActs := map[string]bool{"Manufacturing": true, "Reactions": true}
	groups := map[string][]any{}
	var order []string
	for _, r := range rows {
		row := map[string]any{
			"job_id": r.JobID, "activity_id": r.ActivityID, "activity_name": r.ActivityName,
			"character_name": r.CharacterName, "type_id": r.TypeID, "item_name": r.ItemName,
			"item_image_url": r.ItemImageURL, "runs": r.Runs,
			"probability_pct": r.ProbabilityPct, "expected_output": r.ExpectedOutput,
			"time_remaining": r.TimeRemaining, "seconds_remaining": r.SecondsRemaining,
			"end_date": r.EndDate, "progress_pct": r.ProgressPct,
			"ready_for_delivery": r.ReadyForDelivery, "location": r.Location,
			"estimated_value": nil,
		}
		if pricedActs[r.ActivityName] {
			var qty float64
			if r.TypeID != nil {
				qty = r.ExpectedOutput
			}
			row["estimated_value"] = valueOf(r.TypeID, qty)
		}
		if _, ok := groups[r.ActivityName]; !ok {
			order = append(order, r.ActivityName)
		}
		groups[r.ActivityName] = append(groups[r.ActivityName], row)
	}
	groupList := make([]any, 0, len(groups))
	for _, act := range order {
		groupList = append(groupList, map[string]any{"activity_name": act, "jobs": groups[act]})
	}
	summary := map[string]*summaryRow{}
	var grandTotal float64
	for _, r := range rows {
		if !pricedActs[r.ActivityName] {
			continue
		}
		s := summary[r.ItemName]
		if s == nil {
			s = &summaryRow{typeID: r.TypeID, name: r.ItemName, image: r.ItemImageURL}
			summary[r.ItemName] = s
		}
		s.output += r.ExpectedOutput
		s.runs += r.Runs
		s.jobs++
	}
	sumList := make([]any, 0, len(summary))
	for _, s := range summary {
		total := int(math.Floor(s.output))
		v := valueOf(s.typeID, float64(total))
		if v != nil {
			grandTotal += *v
		}
		sumList = append(sumList, map[string]any{
			"type_id": s.typeID, "item_name": s.name, "item_image_url": s.image,
			"total_expected_output": total, "total_runs": s.runs, "job_count": s.jobs,
			"estimated_value": v,
		})
	}
	sort.Slice(sumList, func(i, j int) bool {
		return sumList[i].(map[string]any)["item_name"].(string) < sumList[j].(map[string]any)["item_name"].(string)
	})
	var grand any
	if grandTotal > 0 {
		grand = grandTotal
	}
	return map[string]any{
		"region_id": regionID, "groups": groupList, "summary": sumList, "grand_total": grand,
	}, nil
}

type summaryRow struct {
	typeID *int
	name   string
	image  *string
	output float64
	runs   int
	jobs   int
}
