package http

import (
	"context"
	"math"
	"net/http"
	"sort"
	"strings"
	"time"

	"github.com/labstack/echo/v4"

	"github.com/sudo-adduser-jordan/eveindustry/internal/auth"
)

// ProjectESI is the ESI surface corporation projects need.
type ProjectESI interface {
	GetCorporationProjects(ctx context.Context, characterID, corporationID int64) ([]map[string]any, error)
	GetCorporationProjectContributors(ctx context.Context, characterID, corporationID int64, projectID string) ([]map[string]any, error)
	ClearCorporationProjectsCache(corporationID int64)
	ClearCorporationProjectContributorsCache(corporationID int64, projectID string)
}

// registerProjects mounts the corporation projects endpoints.
func registerProjects(v1 *echo.Group, d Deps) {
	if d.Projects == nil || d.Users == nil {
		return
	}
	v1.GET("/corporation/projects", handleProjectsIndex(d))
	v1.GET("/corporation/projects/contributors", handleProjectContributors(d))
	v1.POST("/corporation/projects/refresh", handleProjectsRefresh(d))
}

// projectsChar mirrors getUserProjectsCharacters: the main character holding
// the projects scope in a corporation.
func projectsChar(chars []auth.Character) *auth.Character {
	for i := range chars {
		ch := &chars[i]
		if ch.IsMain && ch.CorporationID != nil && *ch.CorporationID != 0 &&
			hasScope(*ch, "esi-corporations.read_projects.v1") {
			return ch
		}
	}
	return nil
}

func projectsDenial(chars []auth.Character) *string {
	if projectsChar(chars) == nil {
		msg := "Your main character is not in a corporation or lacks the esi-corporations.read_projects.v1 scope. Re-authenticate to grant it."
		return &msg
	}
	return nil
}

// GET /corporation/projects — projects with progress, rewards, contributors.
func handleProjectsIndex(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		page, perPage, ok := listPage(c)
		if !ok {
			return errHandled
		}
		uid := c.Get(string(ctxUIDKey)).(int64)
		ctx := c.Request().Context()
		chars, err := d.Users.CharactersForUser(ctx, uid)
		if err != nil {
			return dbError(c, err)
		}
		if denial := projectsDenial(chars); denial != nil {
			return Failure(c, http.StatusForbidden, *denial, nil)
		}
		ch := projectsChar(chars)
		projects, err := d.Projects.GetCorporationProjects(ctx, ch.CharacterID, *ch.CorporationID)
		if err != nil {
			return dbError(c, err)
		}
		rows, summary := projectAnalytics(ctx, d, ch, projects)
		return Success(c, http.StatusOK, pagedSlice(rows, page, perPage), map[string]any{
			"summary": summary, "pagination": paginationOf(len(rows), page, perPage),
		})
	}
}

func projectAnalytics(ctx context.Context, d Deps, ch *auth.Character, projects []map[string]any) ([]any, map[string]any) {
	var completed, inProgress int
	var totalProg, totalDesired, rewardInit, rewardLeft, rewardEarned float64
	rows := make([]any, 0, len(projects))
	for _, p := range projects {
		current := numNested(p, "progress", "current")
		desired := numNested(p, "progress", "desired")
		pct := 0.0
		if desired > 0 {
			pct = numRound1(current / desired * 100)
			if pct > 100 {
				pct = 100
			}
		}
		complete := desired > 0 && current >= desired
		if complete {
			completed++
		} else if current > 0 {
			inProgress++
		}
		totalProg += current
		totalDesired += desired
		earned := 0.0
		var reward any
		if r, ok := p["reward"].(map[string]any); ok {
			initial := numVal(r["initial"])
			remaining := numVal(r["remaining"])
			earned = initial - remaining
			reward = map[string]any{"initial": initial, "remaining": remaining}
			rewardInit += initial
			rewardLeft += remaining
			rewardEarned += earned
		}
		id := projStr(p["id"])
		contribs := projectContributors(ctx, d, ch, id)
		rows = append(rows, map[string]any{
			"id": id, "name": projStr(p["name"]), "state": p["state"],
			"last_modified": p["last_modified"],
			"progress": map[string]any{"current": int(current), "desired": int(desired)},
			"completion_pct": pct, "is_complete": complete,
			"reward": reward, "reward_earned": earned,
			"contributors": contribs,
		})
	}
	sort.SliceStable(rows, func(i, j int) bool {
		return projStr(rows[i].(map[string]any)["last_modified"]) > projStr(rows[j].(map[string]any)["last_modified"])
	})
	overall := 0.0
	if totalDesired > 0 {
		overall = numRound1(totalProg / totalDesired * 100)
		if overall > 100 {
			overall = 100
		}
	}
	var corpName any
	if ch.CorporationName != nil {
		corpName = *ch.CorporationName
	}
	return rows, map[string]any{
		"corporation_id": *ch.CorporationID, "corporation_name": corpName,
		"total_projects": len(projects), "completed_projects": completed,
		"in_progress_projects": inProgress,
		"total_progress": int(totalProg), "total_desired": int(totalDesired),
		"overall_completion_pct": overall,
		"total_reward_initial": rewardInit, "total_reward_remaining": rewardLeft,
		"total_reward_earned": rewardEarned,
	}
}

func projectContributors(ctx context.Context, d Deps, ch *auth.Character, projectID string) []any {
	list, err := d.Projects.GetCorporationProjectContributors(ctx, ch.CharacterID, *ch.CorporationID, projectID)
	if err != nil {
		return []any{}
	}
	out := make([]any, 0, len(list))
	for _, c := range list {
		var cid any
		if n, ok := int64OfJob(c["character_id"]); ok {
			cid = int(n)
		}
		name, _ := c["character_name"].(string)
		if name == "" {
			name, _ = c["name"].(string)
		}
		var contributed any
		for _, k := range []string{"contributed", "amount", "quantity"} {
			if v, ok := c[k]; ok && v != nil {
				contributed = v
				break
			}
		}
		out = append(out, map[string]any{
			"character_id": cid, "character_name": nameOrNil(name), "contributed": contributed,
		})
	}
	return out
}

// GET /corporation/projects/contributors — ISK leaderboard over rewarded,
// closed projects.
func handleProjectContributors(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		qp := c.QueryParams()
		var from, to *time.Time
		if raw := firstParam(qp["date_from"]); raw != "" {
			t, err := time.Parse("2006-01-02", raw)
			if err != nil {
				verrs := validationErrors{}
				verrs.add("date_from", "The date from field must be a valid date.")
				return verrs.invalid(c)
			}
			sod := time.Date(t.Year(), t.Month(), t.Day(), 0, 0, 0, 0, time.UTC)
			from = &sod
		}
		if raw := firstParam(qp["date_to"]); raw != "" {
			t, err := time.Parse("2006-01-02", raw)
			if err != nil {
				verrs := validationErrors{}
				verrs.add("date_to", "The date to field must be a valid date.")
				return verrs.invalid(c)
			}
			if from != nil && raw < from.Format("2006-01-02") {
				verrs := validationErrors{}
				verrs.add("date_to", "The date to field must be a date after or equal to date from.")
				return verrs.invalid(c)
			}
			eod := time.Date(t.Year(), t.Month(), t.Day(), 23, 59, 59, 0, time.UTC)
			to = &eod
		}
		projectName := firstParam(qp["project_name"])
		if len([]rune(projectName)) > 255 {
			verrs := validationErrors{}
			verrs.add("project_name", "The project name is invalid.")
			return verrs.invalid(c)
		}
		page, perPage, ok := listPage(c)
		if !ok {
			return errHandled
		}
		uid := c.Get(string(ctxUIDKey)).(int64)
		ctx := c.Request().Context()
		chars, err := d.Users.CharactersForUser(ctx, uid)
		if err != nil {
			return dbError(c, err)
		}
		if denial := projectsDenial(chars); denial != nil {
			return Failure(c, http.StatusForbidden, *denial, nil)
		}
		ch := projectsChar(chars)
		projects, err := d.Projects.GetCorporationProjects(ctx, ch.CharacterID, *ch.CorporationID)
		if err != nil {
			return dbError(c, err)
		}
		leaders, summary := projectLeaderboard(ctx, d, ch, projects, from, to, projectName)
		return Success(c, http.StatusOK, pagedSlice(leaders, page, perPage), map[string]any{
			"summary": summary, "pagination": paginationOf(len(leaders), page, perPage),
		})
	}
}

func projectLeaderboard(ctx context.Context, d Deps, ch *auth.Character, projects []map[string]any, from, to *time.Time, name string) ([]any, map[string]any) {
	var corpName any
	if ch.CorporationName != nil {
		corpName = *ch.CorporationName
	}
	var fromStr, toStr any
	summary := func(leaders []any, total float64, rewarded int) map[string]any {
		return map[string]any{
			"corporation_name": corpName, "total_attributed_isk": total,
			"total_rewarded_projects": rewarded,
			"date_from": fromStr, "date_to": toStr, "project_name": nameOrNil(name),
		}
	}
	type entry struct {
		cid    *int
		cname  string
		count  int
		isk    float64
		projs  []any
	}
	byKey := map[string]*entry{}
	var total float64
	rewarded := 0
	for _, p := range projects {
		reward, ok := p["reward"].(map[string]any)
		if !ok {
			continue
		}
		if numVal(reward["initial"]) <= 0 {
			continue
		}
		if st, _ := p["state"].(string); st != "Closed" && st != "Completed" {
			continue
		}
		if name != "" && !strings.Contains(strings.ToLower(projStr(p["name"])), strings.ToLower(name)) {
			continue
		}
		if from != nil || to != nil {
			mod, ok := parseJobTime(projStr(p["last_modified"]))
			if !ok {
				continue
			}
			if from != nil && mod.Before(*from) {
				continue
			}
			if to != nil && mod.After(*to) {
				continue
			}
		}
		rewarded++
		desired := numNested(p, "progress", "desired")
		initial := numVal(reward["initial"])
		perUnit := 0.0
		if desired > 0 {
			perUnit = initial / desired
		}
		id := projStr(p["id"])
		list, err := d.Projects.GetCorporationProjectContributors(ctx, ch.CharacterID, *ch.CorporationID, id)
		if err != nil {
			continue
		}
		for _, contrib := range list {
			cid, hasID := int64OfJob(contrib["character_id"])
			cname, _ := contrib["name"].(string)
			if cname == "" {
				cname, _ = contrib["character_name"].(string)
			}
			qty := 0.0
			for _, k := range []string{"contributed", "amount"} {
				if v, ok := numFloatJob(contrib[k]); ok {
					qty = v
					break
				}
			}
			isk := numRound2(qty * perUnit)
			key := "anon_" + cname
			var cidP *int
			if hasID {
				k := int(cid)
				cidP = &k
				key = "id_" + itoaJob(k)
			}
			e := byKey[key]
			if e == nil {
				e = &entry{cid: cidP, cname: cname}
				byKey[key] = e
			}
			e.isk += isk
			e.count++
			e.projs = append(e.projs, map[string]any{
				"id": id, "name": projStr(p["name"]),
				"quantity": int(qty), "attributed_isk": isk,
			})
			total += isk
		}
	}
	if from != nil {
		fromStr = from.Format("2006-01-02")
	}
	if to != nil {
		toStr = to.Format("2006-01-02")
	}
	list := make([]*entry, 0, len(byKey))
	for _, e := range byKey {
		list = append(list, e)
	}
	sort.Slice(list, func(i, j int) bool { return list[i].isk > list[j].isk })
	out := make([]any, 0, len(list))
	for _, e := range list {
		pct := 0.0
		if total > 0 {
			pct = numRound2(e.isk / total * 100)
		}
		var cid any
		if e.cid != nil {
			cid = *e.cid
		}
		out = append(out, map[string]any{
			"character_id": cid, "character_name": nameOrNil(e.cname),
			"project_count": e.count, "attributed_isk": e.isk,
			"attributed_pct": pct, "projects": e.projs,
		})
	}
	return out, summary(out, total, rewarded)
}

// POST /corporation/projects/refresh — forget memoized projects, 204.
func handleProjectsRefresh(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		uid := c.Get(string(ctxUIDKey)).(int64)
		ctx := c.Request().Context()
		chars, err := d.Users.CharactersForUser(ctx, uid)
		if err != nil {
			return dbError(c, err)
		}
		if ch := projectsChar(chars); ch != nil && ch.CorporationID != nil {
			d.Projects.ClearCorporationProjectsCache(*ch.CorporationID)
		}
		return c.NoContent(http.StatusNoContent)
	}
}

func numVal(v any) float64 {
	n, _ := asNumProg(v)
	return n
}

func numNested(m map[string]any, keys ...string) float64 {
	var cur any = m
	for _, k := range keys {
		mm, ok := cur.(map[string]any)
		if !ok {
			return 0
		}
		cur = mm[k]
	}
	return numVal(cur)
}

func projStr(v any) string {
	s, _ := v.(string)
	return s
}

func nameOrNil(s string) any {
	if s == "" {
		return nil
	}
	return s
}

func numRound1(v float64) float64 { return math.Round(v*10) / 10 }

func numRound2(v float64) float64 { return math.Round(v*100) / 100 }
