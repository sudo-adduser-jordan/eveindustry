package http

import (
	"context"
	"math"
	"net/http"
	"time"

	"github.com/labstack/echo/v4"

	"github.com/sudo-adduser-jordan/eveindustry/internal/auth"
	"github.com/sudo-adduser-jordan/eveindustry/internal/industry"
	"github.com/sudo-adduser-jordan/eveindustry/internal/market"
)

// IndustryJobReader is the ESI surface the jobs overview needs.
// *esi.CharacterClient satisfies it; tests stub it.
type IndustryJobReader interface {
	GetIndustryJobs(ctx context.Context, characterID int64, includeCompleted bool) ([]map[string]any, error)
	GetCorporationIndustryJobs(ctx context.Context, characterID, corporationID int64, includeCompleted bool) ([]map[string]any, error)
	GetSkills(ctx context.Context, characterID int64) (map[int]int, error)
	GetAssets(ctx context.Context, characterID int64) ([]map[string]any, error)
}

// UniverseReader is the ESI/store surface the jobs overview needs.
// *esi.UniverseClient satisfies it; tests stub it.
type UniverseReader interface {
	GetTypeInfo(ctx context.Context, typeID int) (map[string]any, error)
	GetBestTypeImageUrl(ctx context.Context, typeID int, isBlueprint bool) string
	GetStation(ctx context.Context, stationID uint64) (map[string]any, error)
	GetSystem(ctx context.Context, systemID int) (map[string]any, error)
	GetStructure(ctx context.Context, structureID uint64, characterID int64) (map[string]any, error)
}

// DogmaReader is the dogma subset UniverseReader gains for abyssal analysis.
type DogmaReader interface {
	GetDogmaAttribute(ctx context.Context, attributeID int) (map[string]any, error)
	DogmaTypeAttributes(ctx context.Context, typeID int) (map[int]float64, error)
}

// IndustryDeps carries the ESI clients the industry group needs.
type IndustryDeps struct {
	Characters IndustryJobReader
	Universe   UniverseReader
}

// registerIndustry mounts GET /dashboard (warm-cache subset omitted: see
// below) and GET /industry/jobs. Dashboard planetary alerts and slot
// caches come with the refresh jobs in the jobs phase; /dashboard itself
// lands with the planetary slice.
func registerIndustry(v1 *echo.Group, d Deps) {
	if d.IndustryDeps.Characters == nil || d.IndustryDeps.Universe == nil || d.Users == nil || d.Market == nil || d.Industry == nil {
		return
	}
	v1.GET("/industry/jobs", handleJobsOverview(d))
}

// industryScopes mirrors the RefreshEligibility 'industry' dataset: active
// characters holding the industry jobs or skills scope.
func industryEligible(ch auth.Character) bool {
	if !ch.IsEsiActive {
		return false
	}
	for _, s := range ch.Scopes {
		if s == "esi-industry.read_character_jobs.v1" || s == "esi-skills.read_skills.v1" {
			return true
		}
	}
	return false
}

// GET /industry/jobs — mirrors JobsOverviewController@show: active jobs
// across the user's industry-eligible characters, grouped by activity with
// priced manufacturing/reaction output. Computed live; the 20-minute
// overview cache warms with the refresh jobs in the jobs phase.
func handleJobsOverview(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		verrs := validationErrors{}
		regionID := parseRegionID(c, verrs)
		if len(verrs) > 0 {
			return verrs.invalid(c)
		}
		uid := c.Get(string(ctxUIDKey)).(int64)
		ctx := c.Request().Context()
		now := time.Now()

		chars, err := d.Users.CharactersForUser(ctx, uid)
		if err != nil {
			return dbError(c, err)
		}

		var rows []industry.JobRow
		seenJobs := map[int64]bool{}
		for _, ch := range chars {
			if !industryEligible(ch) {
				continue
			}
			jobs, err := d.IndustryDeps.Characters.GetIndustryJobs(ctx, ch.CharacterID, false)
			if err != nil {
				continue
			}
			for _, corpJob := range corpJobsFor(ctx, d, uid, ch) {
				jobs = append(jobs, corpJob)
			}
			for _, job := range jobs {
				raw, ok := parseJob(job)
				if !ok || raw.Status != "active" {
					continue
				}
				if seenJobs[raw.JobID] {
					continue
				}
				seenJobs[raw.JobID] = true
				rows = append(rows, enrichJob(ctx, d, ch, raw, now))
			}
		}

		priced := map[int]float64{}
		{
			ids := map[int]bool{}
			for _, r := range rows {
				if r.TypeID != nil {
					ids[*r.TypeID] = true
				}
			}
			typeIDs := make([]int, 0, len(ids))
			for id := range ids {
				typeIDs = append(typeIDs, id)
			}
			if resolved, err := resolvePrices(ctx, d.Market, typeIDs, regionID); err != nil {
				return dbError(c, err)
			} else {
				for id, r := range resolved {
					priced[id] = r.Price
				}
			}
		}

		value := func(typeID *int, qty float64) *float64 {
			if typeID == nil {
				return nil
			}
			if p, ok := priced[*typeID]; ok && p > 0 {
				v := p * qty
				return &v
			}
			return nil
		}
		groups := industry.GroupJobs(rows)
		groupOut := make([]any, 0, len(groups))
		for _, g := range groups {
			pricedJobs := make([]any, 0, len(g.Jobs))
			for _, j := range g.Jobs {
				m := jobShape(j)
				if industry.ValueActivities[j.ActivityName] {
					m["estimated_value"] = value(j.TypeID, j.ExpectedOutput)
				} else {
					m["estimated_value"] = nil
				}
				pricedJobs = append(pricedJobs, m)
			}
			groupOut = append(groupOut, map[string]any{"activity_name": g.ActivityName, "jobs": pricedJobs})
		}
		summary := industry.BuildSummary(rows)
		summaryOut := make([]any, 0, len(summary))
		total := 0.0
		for _, s := range summary {
			ev := value(s.TypeID, float64(s.TotalExpectedOutput))
			if ev != nil {
				total += *ev
			}
			summaryOut = append(summaryOut, map[string]any{
				"type_id": s.TypeID, "item_name": s.ItemName, "item_image_url": s.ItemImageURL,
				"total_runs": s.TotalRuns, "total_expected_output": s.TotalExpectedOutput,
				"job_count": s.JobCount, "estimated_value": ev,
			})
		}
		var estimated *float64
		if total > 0 {
			estimated = &total
		}
		totalJobs := 0
		for _, g := range groups {
			totalJobs += len(g.Jobs)
		}
		return Success(c, http.StatusOK, map[string]any{
			"groups": groupOut, "summary": summaryOut,
			"estimated_total": estimated, "total_jobs": totalJobs,
		}, map[string]any{
			"region":    map[string]any{"region_id": regionID, "region_name": market.RegionName(regionID)},
			"freshness": freshnessFor(c, d, regionID),
		})
	}
}

func jobShape(j industry.JobRow) map[string]any {
	return map[string]any{
		"job_id": j.JobID, "activity_id": j.ActivityID, "activity_name": j.ActivityName,
		"character_name": j.CharacterName, "type_id": j.TypeID, "item_name": j.ItemName,
		"item_image_url": j.ItemImageURL, "runs": j.Runs, "probability_pct": j.ProbabilityPct,
		"expected_output": j.ExpectedOutput, "time_remaining": j.TimeRemaining,
		"seconds_remaining": j.SecondsRemaining, "end_date": j.EndDate,
		"progress_pct": j.ProgressPct, "ready_for_delivery": j.ReadyForDelivery,
		"location": j.Location,
	}
}

func parseJob(m map[string]any) (industry.RawJob, bool) {
	jobID := int64(intVal(m["job_id"], 0))
	if jobID == 0 {
		return industry.RawJob{}, false
	}
	raw := industry.RawJob{JobID: jobID, ActivityID: intVal(m["activity_id"], 0), Runs: intVal(m["runs"], 0)}
	if raw.Runs == 0 {
		raw.Runs = 1
	}
	if s, ok := m["status"].(string); ok {
		raw.Status = s
	}
	if s, ok := m["start_date"].(string); ok {
		if t, err := time.Parse(time.RFC3339, s); err == nil {
			raw.StartDate = t
		}
	}
	if s, ok := m["end_date"].(string); ok {
		if t, err := time.Parse(time.RFC3339, s); err == nil {
			raw.EndDate = t
		}
	}
	if raw.StartDate.IsZero() || raw.EndDate.IsZero() {
		return industry.RawJob{}, false
	}
	if v, ok := numFloat(m["blueprint_type_id"]); ok {
		id := int64(v)
		raw.BlueprintTypeID = &id
	}
	if v, ok := numFloat(m["product_type_id"]); ok {
		id := int64(v)
		raw.ProductTypeID = &id
	}
	if v, ok := numFloat(m["probability"]); ok {
		raw.Probability = &v
	}
	if v, ok := numFloat(m["station_id"]); ok && v != 0 {
		id := uint64(v)
		raw.StationID = &id
	}
	if v, ok := numFloat(m["facility_id"]); ok && v != 0 {
		id := uint64(v)
		raw.FacilityID = &id
	}
	if v, ok := numFloat(m["solar_system_id"]); ok && v != 0 {
		id := int(v)
		raw.SolarSystemID = &id
	}
	return raw, true
}

func numFloat(v any) (float64, bool) {
	switch n := v.(type) {
	case float64:
		return n, true
	case int:
		return float64(n), true
	case int64:
		return float64(n), true
	}
	return 0, false
}

// enrichJob resolves names, images, portions, locations and progress for one
// job, mirroring buildJobsOverview()'s per-job block.
func enrichJob(ctx context.Context, d Deps, ch auth.Character, raw industry.RawJob, now time.Time) industry.JobRow {
	var productID *int64 = raw.ProductTypeID
	if productID == nil {
		productID = raw.BlueprintTypeID
	}
	itemName := "Unknown Item"
	var imageURL *string
	outputPerRun := 1
	if productID != nil {
		pid := int(*productID)
		if info, err := d.IndustryDeps.Universe.GetTypeInfo(ctx, pid); err == nil && info != nil {
			if n, ok := info["name"].(string); ok && n != "" {
				itemName = n
			}
			if portion, ok := numFloat(info["portion_size"]); ok && portion > 1 {
				outputPerRun = int(portion)
			}
		}
		if img := d.IndustryDeps.Universe.GetBestTypeImageUrl(ctx, pid, false); img != "" {
			imageURL = &img
		}
		if raw.BlueprintTypeID != nil {
			sdeActivity := raw.ActivityID
			if raw.ActivityID == industry.ActivityReactions {
				sdeActivity = 11
			}
			if row, err := d.Industry.ActivityByBlueprint(ctx, int(*raw.BlueprintTypeID), sdeActivity); err == nil && row != nil {
				prods := industry.DecodeJSONList(nullStrPtr(row.Products))
				matchedIdx := -1
				for i, p := range prods {
					if id, ok := numFloat(p["type_id"]); ok && int64(id) == *productID {
						matchedIdx = i
						break
					}
				}
				if matchedIdx < 0 && len(prods) > 0 {
					matchedIdx = 0
				}
				if matchedIdx >= 0 {
					if q, ok := numFloat(prods[matchedIdx]["quantity"]); ok && q > 0 {
						outputPerRun = int(q)
					}
				}
			}
		}
	}

	var probPct *float64
	if raw.ActivityID == industry.ActivityInvention && raw.Probability != nil {
		p := math.Round(*raw.Probability*100*100) / 100
		probPct = &p
	}

	return industry.AssembleJob(raw, ch.Name, itemName, imageURL, outputPerRun, resolveJobLocation(ctx, d, ch, raw), probPct, now)
}

func resolveJobLocation(ctx context.Context, d Deps, ch auth.Character, raw industry.RawJob) string {
	uv := d.IndustryDeps.Universe
	if raw.StationID != nil && *raw.StationID != 0 {
		if st, err := uv.GetStation(ctx, *raw.StationID); err == nil && st != nil {
			if n, ok := st["name"].(string); ok && n != "" {
				return n
			}
		}
		if st, err := uv.GetStructure(ctx, *raw.StationID, ch.CharacterID); err == nil && st != nil {
			if n, ok := st["name"].(string); ok && n != "" {
				return n
			}
		}
	}
	if raw.FacilityID != nil && *raw.FacilityID != 0 && (raw.StationID == nil || *raw.FacilityID != *raw.StationID) {
		if st, err := uv.GetStructure(ctx, *raw.FacilityID, ch.CharacterID); err == nil && st != nil {
			if n, ok := st["name"].(string); ok && n != "" {
				return n
			}
		}
	}
	if raw.SolarSystemID != nil && *raw.SolarSystemID != 0 {
		if sys, err := uv.GetSystem(ctx, *raw.SolarSystemID); err == nil && sys != nil {
			if n, ok := sys["name"].(string); ok && n != "" {
				return n
			}
		}
	}
	return "Unknown Location"
}

// corpJobsFor ports getCorporationJobsForCharacter(): corp jobs started by
// this character, via the character itself when it may collect (scope plus
// Director/CEO/Factory_Manager) or a same-corp sibling with the scope and
// Factory_Manager role.
func corpJobsFor(ctx context.Context, d Deps, uid int64, ch auth.Character) []map[string]any {
	corpID := ch.CorporationID
	if corpID == nil || *corpID == 0 || d.Corp == nil {
		return nil
	}
	collector := int64(0)
	if hasScope(ch, "esi-industry.read_corporation_jobs.v1") && ch.IsEsiActive {
		if ch.IsDirectorCEO || hasRole(ch.Roles, "Factory_Manager") {
			collector = ch.CharacterID
		}
	}
	if collector == 0 {
		members, err := d.Corp.UserCorpCharacters(ctx, uid, *corpID)
		if err != nil {
			return nil
		}
		for _, m := range members {
			if !m.IsEsiActive {
				continue
			}
			has := false
			for _, s := range m.Scopes {
				if s == "esi-industry.read_corporation_jobs.v1" {
					has = true
					break
				}
			}
			if !has {
				continue
			}
			if m.IsDirectorCEO || hasRole(m.Roles, "Factory_Manager") {
				collector = m.CharacterID
				break
			}
		}
	}
	if collector == 0 {
		return nil
	}
	jobs, err := d.IndustryDeps.Characters.GetCorporationIndustryJobs(ctx, collector, *corpID, false)
	if err != nil {
		return nil
	}
	out := []map[string]any{}
	for _, job := range jobs {
		if id, ok := numFloat(job["installer_id"]); ok && int64(id) == ch.CharacterID {
			out = append(out, job)
		}
	}
	return out
}

func mathRound2(v float64) float64 {
	return float64(int(v*100+0.5*(1-2*boolToInt(v < 0)))) / 100
}

func boolToInt(b bool) float64 {
	if b {
		return 1
	}
	return 0
}
