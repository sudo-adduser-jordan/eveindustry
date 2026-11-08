package industry

import (
	"context"
	"math"
	"sort"
	"time"

	"github.com/sudo-adduser-jordan/eveindustry/internal/auth"
)

// Skill IDs for slot counts (mirrors IndustryService).
const (
	SkillMassProduction      = 3387
	SkillAdvMassProduction   = 24625
	SkillLaboratoryOperation = 3406
	SkillAdvLaboratory       = 24624
	SkillMassReactions       = 45748
	SkillAdvMassReactions    = 45749
)

// ResearchActivities mirrors the PHP RESEARCH_ACTIVITIES list.
var ResearchActivities = []int{3, 4, 5, 8}

// DetailedActivityNames mirrors the detailed-job activity names.
var DetailedActivityNames = map[int]string{
	1: "Manufacturing",
	3: "Time Efficiency Research",
	4: "Material Efficiency Research",
	5: "Copying",
	8: "Invention",
	9: "Reactions",
}

// ReadyActivityNames mirrors the ready-for-collection activity names.
var ReadyActivityNames = map[int]string{
	1: "Manufacturing",
	3: "Research (TE)",
	4: "Research (ME)",
	5: "Copying",
	8: "Invention",
	9: "Reactions",
}

// AvailCharacters is the ESI surface availability computation needs.
type AvailCharacters interface {
	GetSkills(ctx context.Context, characterID int64) (map[int]int, error)
	GetIndustryJobs(ctx context.Context, characterID int64, includeCompleted bool) ([]map[string]any, error)
	GetCorporationIndustryJobs(ctx context.Context, characterID, corporationID int64, includeCompleted bool) ([]map[string]any, error)
}

// AvailUniverse resolves names, images and locations.
type AvailUniverse interface {
	GetTypeInfo(ctx context.Context, typeID int) (map[string]any, error)
	GetBestTypeImageUrl(ctx context.Context, typeID int, isBlueprint bool) string
	GetStation(ctx context.Context, stationID uint64) (map[string]any, error)
	GetSystem(ctx context.Context, systemID int) (map[string]any, error)
	GetStructure(ctx context.Context, structureID uint64, characterID int64) (map[string]any, error)
}

// AvailCorp reads corp roles for the corp-jobs collector.
type AvailCorp interface {
	UserCorpCharacters(ctx context.Context, userID, corporationID int64) ([]CorpMember, error)
}

// CorpMember mirrors the corp-standing fields availability needs.
type CorpMember struct {
	CharacterID   int64
	IsEsiActive   bool
	Scopes        []string
	IsDirectorCEO bool
	Roles         []string
}

// AvailDeps carries the availability computation collaborators.
type AvailDeps struct {
	Characters AvailCharacters
	Universe   AvailUniverse
	Corp       AvailCorp
}

// AvailData is the computed availability payload (characters, totals,
// ready_for_collection).
type AvailData struct {
	Characters []any
	Totals     map[string]map[string]int
	Ready      []any
}

// HasScope reports whether the character holds a scope.
func HasScope(ch auth.Character, scope string) bool {
	for _, s := range ch.Scopes {
		if s == scope {
			return true
		}
	}
	return false
}

func hasRole(roles []string, role string) bool {
	for _, r := range roles {
		if r == role {
			return true
		}
	}
	return false
}

// CorpJobsFor ports getCorporationJobsForCharacter(): corp jobs started by
// this character, via itself or a same-corp Factory_Manager sibling.
func CorpJobsFor(ctx context.Context, d AvailDeps, uid int64, ch auth.Character) []map[string]any {
	if ch.CorporationID == nil || *ch.CorporationID == 0 || d.Corp == nil {
		return nil
	}
	var collector int64
	if HasScope(ch, "esi-industry.read_corporation_jobs.v1") && ch.IsEsiActive {
		if ch.IsDirectorCEO || hasRole(ch.Roles, "Factory_Manager") {
			collector = ch.CharacterID
		}
	}
	if collector == 0 {
		members, err := d.Corp.UserCorpCharacters(ctx, uid, *ch.CorporationID)
		if err != nil {
			return nil
		}
		for _, m := range members {
			if !m.IsEsiActive {
				continue
			}
			hit := false
			for _, s := range m.Scopes {
				if s == "esi-industry.read_corporation_jobs.v1" {
					hit = true
					break
				}
			}
			if !hit {
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
	jobs, err := d.Characters.GetCorporationIndustryJobs(ctx, collector, *ch.CorporationID, false)
	if err != nil {
		return nil
	}
	return jobs
}

// AvailabilityData ports the slot computation shared by /availability and
// the dashboard industry stats. Per-character ESI failures skip that
// character.
func AvailabilityData(ctx context.Context, d AvailDeps, chars []auth.Character, uid int64, now time.Time) AvailData {
	out := AvailData{
		Totals: map[string]map[string]int{
			"manufacturing": {"total": 0, "active": 0, "available": 0},
			"research":      {"total": 0, "active": 0, "available": 0},
			"reactions":     {"total": 0, "active": 0, "available": 0},
		},
	}
	for _, ch := range chars {
		if !ch.IsEsiActive {
			continue
		}
		skills, err := d.Characters.GetSkills(ctx, ch.CharacterID)
		if err != nil {
			continue
		}
		if skills == nil {
			skills = map[int]int{}
		}
		jobs, err := d.Characters.GetIndustryJobs(ctx, ch.CharacterID, false)
		if err != nil {
			continue
		}
		for _, cj := range CorpJobsFor(ctx, d, uid, ch) {
			jobs = append(jobs, cj)
		}
		active := ActiveJobs(jobs)
		mfgTotal := 1 + skills[SkillMassProduction] + skills[SkillAdvMassProduction]
		resTotal := 1 + skills[SkillLaboratoryOperation] + skills[SkillAdvLaboratory]
		reaTotal := 1 + skills[SkillMassReactions] + skills[SkillAdvMassReactions]
		mfgJobs := DetailedJobs(ctx, d, ch, active, []int{ActivityManufacturing}, now)
		resJobs := DetailedJobs(ctx, d, ch, active, ResearchActivities, now)
		reaJobs := DetailedJobs(ctx, d, ch, active, []int{ActivityReactions}, now)
		mfgActive, resActive, reaActive := len(mfgJobs), len(resJobs), len(reaJobs)
		var corpName any
		if ch.CorporationName != nil {
			corpName = *ch.CorporationName
		}
		out.Characters = append(out.Characters, map[string]any{
			"character_id": ch.CharacterID, "character_name": ch.Name,
			"portrait_url": ch.PortraitURL(), "corporation_name": corpName,
			"manufacturing":   slotGroup(mfgTotal, mfgActive, mfgJobs),
			"research":        slotGroup(resTotal, resActive, resJobs),
			"reactions":       slotGroup(reaTotal, reaActive, reaJobs),
			"completing_soon": completingSoon(active, now),
			"skills": map[string]any{
				"mass_production": skills[SkillMassProduction], "adv_mass_production": skills[SkillAdvMassProduction],
				"laboratory_operation": skills[SkillLaboratoryOperation], "adv_laboratory_operation": skills[SkillAdvLaboratory],
				"mass_reactions": skills[SkillMassReactions], "adv_mass_reactions": skills[SkillAdvMassReactions],
			},
		})
		out.Totals["manufacturing"]["total"] += mfgTotal
		out.Totals["manufacturing"]["active"] += mfgActive
		out.Totals["manufacturing"]["available"] += max(0, mfgTotal-mfgActive)
		out.Totals["research"]["total"] += resTotal
		out.Totals["research"]["active"] += resActive
		out.Totals["research"]["available"] += max(0, resTotal-resActive)
		out.Totals["reactions"]["total"] += reaTotal
		out.Totals["reactions"]["active"] += reaActive
		out.Totals["reactions"]["available"] += max(0, reaTotal-reaActive)
		out.Ready = append(out.Ready, ReadyJobs(ctx, d, ch, active, now)...)
	}
	sort.Slice(out.Ready, func(i, j int) bool {
		return out.Ready[i].(map[string]any)["end_date"].(string) < out.Ready[j].(map[string]any)["end_date"].(string)
	})
	if out.Characters == nil {
		out.Characters = []any{}
	}
	if out.Ready == nil {
		out.Ready = []any{}
	}
	return out
}

func slotGroup(total, active int, jobs []any) map[string]any {
	if jobs == nil {
		jobs = []any{}
	}
	return map[string]any{
		"total": total, "active": active,
		"available": max(0, total-active), "jobs": jobs,
	}
}

// ActiveJobs filters active jobs, deduped by id.
func ActiveJobs(jobs []map[string]any) []map[string]any {
	out := []map[string]any{}
	seen := map[int64]bool{}
	for _, j := range jobs {
		if strOf(j["status"]) != "active" {
			continue
		}
		id, _ := int64Of(j["job_id"])
		if seen[id] {
			continue
		}
		seen[id] = true
		out = append(out, j)
	}
	return out
}

// DetailedJobs ports getDetailedJobsByActivity.
func DetailedJobs(ctx context.Context, d AvailDeps, ch auth.Character, jobs []map[string]any, activities []int, now time.Time) []any {
	out := []any{}
	for _, j := range jobs {
		act := intOf(j["activity_id"])
		if !inInts(act, activities) {
			continue
		}
		start, ok1 := parseTime(strOf(j["start_date"]))
		end, ok2 := parseTime(strOf(j["end_date"]))
		if !ok1 || !ok2 {
			continue
		}
		total := end.Sub(start).Seconds()
		elapsed := now.Sub(start).Seconds()
		remaining := int64(math.Max(0, end.Sub(now).Seconds()))
		progress := 100.0
		if total > 0 {
			progress = math.Min(100, math.Round(elapsed/total*100))
		}
		days := remaining / 86400
		hours := (remaining % 86400) / 3600
		minutes := (remaining % 3600) / 60
		var parts []string
		if days > 0 {
			parts = append(parts, itoa(int(days))+"d")
		}
		if hours > 0 || days > 0 {
			parts = append(parts, itoa(int(hours))+"h")
		}
		parts = append(parts, itoa(int(minutes))+"m")
		timeRemaining := joinStr(parts, " ")
		productID, _ := int64Of(firstNonZero(j, "product_type_id", "blueprint_type_id"))
		bpID, _ := int64Of(j["blueprint_type_id"])
		itemName := "Unknown Item"
		var imageURL any
		if productID != 0 {
			if info, err := d.Universe.GetTypeInfo(ctx, int(productID)); err == nil && info != nil {
				if n, ok := info["name"].(string); ok && n != "" {
					itemName = n
				}
			}
			if u := d.Universe.GetBestTypeImageUrl(ctx, int(productID), false); u != "" {
				imageURL = u
			}
		}
		var probPct any
		var probLabel any
		if p := inventionProbability(j); p != nil {
			probPct = *p
			probLabel = formatPct(*p) + "%"
		}
		runs, _ := int64Of(j["runs"])
		if runs == 0 {
			runs = 1
		}
		var licensed any
		if v, ok := j["licensed_runs"]; ok && v != nil {
			if n, ok := int64Of(v); ok {
				licensed = int(n)
			}
		}
		var bp, prod any
		if bpID != 0 {
			bp = int(bpID)
		}
		if productID != 0 {
			prod = int(productID)
		}
		out = append(out, map[string]any{
			"job_id": int(mustID(j)), "activity_id": act,
			"activity_name":     DetailedActivityNames[act],
			"blueprint_type_id": bp, "product_type_id": prod,
			"item_name": itemName, "item_image_url": imageURL,
			"runs": int(runs), "licensed_runs": licensed,
			"start_date": start.Format(time.RFC3339), "end_date": end.Format(time.RFC3339),
			"progress_pct": int(progress), "time_remaining": timeRemaining,
			"seconds_remaining": int(remaining), "ready_for_delivery": remaining <= 60,
			"status":          strOr(j["status"], "active"),
			"probability_pct": probPct, "probability_label": probLabel,
		})
	}
	sort.Slice(out, func(i, j int) bool {
		return out[i].(map[string]any)["seconds_remaining"].(int) < out[j].(map[string]any)["seconds_remaining"].(int)
	})
	return out
}

// ReadyJobs ports getReadyForCollectionJobsForUser's per-character slice.
func ReadyJobs(ctx context.Context, d AvailDeps, ch auth.Character, jobs []map[string]any, now time.Time) []any {
	out := []any{}
	cutoff := now.Add(60 * time.Second)
	for _, j := range jobs {
		end, ok := parseTime(strOf(j["end_date"]))
		if !ok || end.After(cutoff) {
			continue
		}
		productID, _ := int64Of(firstNonZero(j, "product_type_id", "blueprint_type_id"))
		itemName := "Unknown Item"
		var imageURL any
		if productID != 0 {
			if info, err := d.Universe.GetTypeInfo(ctx, int(productID)); err == nil && info != nil {
				if n, ok := info["name"].(string); ok && n != "" {
					itemName = n
				}
			}
			if u := d.Universe.GetBestTypeImageUrl(ctx, int(productID), false); u != "" {
				imageURL = u
			}
		}
		location := resolveLocation(ctx, d, ch, j)
		var probPct any
		var probLabel any
		if p := inventionProbability(j); p != nil {
			probPct = *p
			probLabel = formatPct(*p) + "% Chance"
		}
		runs, _ := int64Of(j["runs"])
		if runs == 0 {
			runs = 1
		}
		id, _ := int64Of(j["job_id"])
		act := intOf(j["activity_id"])
		out = append(out, map[string]any{
			"character_id": ch.CharacterID, "character_name": ch.Name,
			"job_id": int(id), "activity_id": act,
			"activity_name": firstNonEmpty(ReadyActivityNames[act], "Unknown"),
			"item_name":     itemName, "item_image_url": imageURL,
			"end_date": end.Format(time.RFC3339), "location": location,
			"runs": int(runs), "probability_pct": probPct, "probability_label": probLabel,
		})
	}
	return out
}

func resolveLocation(ctx context.Context, d AvailDeps, ch auth.Character, j map[string]any) string {
	if sid, ok := int64Of(j["station_id"]); ok && sid != 0 {
		if st, err := d.Universe.GetStation(ctx, uint64(sid)); err == nil && st != nil {
			if n, ok := st["name"].(string); ok && n != "" {
				return n
			}
		} else if sys, ok := int64Of(j["solar_system_id"]); ok && sys != 0 {
			if s, err := d.Universe.GetSystem(ctx, int(sys)); err == nil && s != nil {
				if n, ok := s["name"].(string); ok && n != "" {
					return n
				}
			}
		}
	} else if sys, ok := int64Of(j["solar_system_id"]); ok && sys != 0 {
		if s, err := d.Universe.GetSystem(ctx, int(sys)); err == nil && s != nil {
			if n, ok := s["name"].(string); ok && n != "" {
				return n
			}
		}
	}
	return "Unknown Location"
}

func inventionProbability(j map[string]any) *float64 {
	if intOf(j["activity_id"]) != ActivityInvention {
		return nil
	}
	p, ok := numFloat(j["probability"])
	if !ok {
		return nil
	}
	v := math.Round(p*100*100) / 100
	return &v
}

// completingSoon ports getJobsCompletingSoon.
func completingSoon(jobs []map[string]any, now time.Time) []any {
	out := []any{}
	limit := now.Add(time.Hour)
	for _, j := range jobs {
		end, ok := parseTime(strOf(j["end_date"]))
		if !ok || end.After(limit) || end.Before(now) {
			continue
		}
		id, _ := int64Of(j["job_id"])
		out = append(out, map[string]any{
			"job_id": int(id), "activity_id": intOf(j["activity_id"]),
			"end_date":          end.Format(time.RFC3339),
			"minutes_remaining": int(now.Sub(end).Minutes() * -1),
		})
	}
	if out == nil {
		out = []any{}
	}
	return out
}

func formatPct(v float64) string {
	if v == math.Trunc(v) {
		return itoa(int(v))
	}
	s := ""
	neg := v < 0
	if neg {
		v = -v
	}
	iv := int(v)
	s = itoa(iv)
	frac := v - float64(iv)
	if frac > 0 {
		f := int(math.Round(frac * 100))
		if f > 0 {
			fs := itoa(f)
			if len(fs) == 1 {
				fs = "0" + fs
			}
			for len(fs) > 0 && fs[len(fs)-1] == '0' {
				fs = fs[:len(fs)-1]
			}
			s += "." + fs
		}
	}
	if neg {
		s = "-" + s
	}
	return s
}

func inInts(a int, list []int) bool {
	for _, v := range list {
		if v == a {
			return true
		}
	}
	return false
}

func strOf(v any) string {
	s, _ := v.(string)
	return s
}

func strOr(v any, def string) string {
	if s, ok := v.(string); ok && s != "" {
		return s
	}
	return def
}

func intOf(v any) int {
	n, _ := int64Of(v)
	return int(n)
}

func int64Of(v any) (int64, bool) {
	switch n := v.(type) {
	case int:
		return int64(n), true
	case int64:
		return n, true
	case float64:
		return int64(n), true
	}
	return 0, false
}

func numFloat(v any) (float64, bool) {
	switch n := v.(type) {
	case int:
		return float64(n), true
	case int64:
		return float64(n), true
	case float64:
		return n, true
	}
	return 0, false
}

func firstNonZero(j map[string]any, keys ...string) any {
	for _, k := range keys {
		if n, ok := int64Of(j[k]); ok && n != 0 {
			return n
		}
	}
	return 0
}

func mustID(j map[string]any) int64 {
	n, _ := int64Of(j["job_id"])
	return n
}

func parseTime(s string) (time.Time, bool) {
	for _, layout := range []string{time.RFC3339, "2006-01-02T15:04:05Z", time.RFC3339Nano} {
		if t, err := time.Parse(layout, s); err == nil {
			return t.UTC(), true
		}
	}
	return time.Time{}, false
}

func joinStr(parts []string, sep string) string {
	out := ""
	for i, p := range parts {
		if i > 0 {
			out += sep
		}
		out += p
	}
	return out
}

func firstNonEmpty(vals ...string) string {
	for _, v := range vals {
		if v != "" {
			return v
		}
	}
	return ""
}
