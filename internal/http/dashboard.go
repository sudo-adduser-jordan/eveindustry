package http

import (
	"net/http"
	"sort"
	"time"

	"github.com/labstack/echo/v4"
)

// registerDashboard mounts GET /dashboard: industry + planetary stats with
// the user's characters and saved plans. Computed live (the jobs phase will
// warm the dashboard.* caches instead).
func registerDashboard(v1 *echo.Group, d Deps) {
	if d.Users == nil || d.Plans == nil {
		return
	}
	v1.GET("/dashboard", handleDashboardShow(d))
}

// GET /dashboard — mirrors DashboardController@show.
func handleDashboardShow(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		uid := c.Get(string(ctxUIDKey)).(int64)
		ctx := c.Request().Context()
		now := time.Now().UTC()
		industry := map[string]any{"in_progress": 0, "ready": 0, "available_slots": 0}
		if d.IndustryDeps.Characters != nil && d.Industry != nil && d.Market != nil {
			if avail, err := availabilityData(ctx, d, uid, now); err == nil {
				inProg, ready, slots := 0, 0, 0
				if totals, ok := avail["totals"].(map[string]any); ok {
					for _, k := range []string{"manufacturing", "research", "reactions"} {
						if t, ok := totals[k].(map[string]int); ok {
							inProg += t["active"]
							slots += t["available"]
						}
					}
				}
				if chars, ok := avail["characters"].([]any); ok {
					for _, cv := range chars {
						ch := cv.(map[string]any)
						for _, k := range []string{"manufacturing", "research", "reactions"} {
							g, _ := ch[k].(map[string]any)
							jobs, _ := g["jobs"].([]any)
							for _, jv := range jobs {
								if jv.(map[string]any)["ready_for_delivery"] == true {
									ready++
								}
							}
						}
					}
				}
				industry = map[string]any{"in_progress": inProg, "ready": ready, "available_slots": slots}
			}
		}
		planetary := map[string]any{"expired_extractors": 0, "full_storage": 0}
		if d.Planetary.Characters != nil && d.Planetary.Universe != nil {
			if chars, err := collectPlanetary(ctx, d, uid, now); err == nil {
				expired, full := 0, 0
				for _, pc := range chars {
					for _, pv := range pc.planets {
						pm := pv.(map[string]any)
						for _, av := range pm["alerts"].([]any) {
							if av.(map[string]any)["type"] == "extractor_expired" {
								expired++
							}
						}
						for _, pinv := range pm["pins"].([]any) {
							pin := pinv.(map[string]any)
							if st, ok := pin["storage"].(map[string]any); ok {
								if u, ok := st["usage_percent"].(float64); ok && u >= 90 {
									full++
								}
							}
						}
					}
				}
				planetary = map[string]any{"expired_extractors": expired, "full_storage": full}
			}
		}
		chars, err := d.Users.CharactersForUser(ctx, uid)
		if err != nil {
			return dbError(c, err)
		}
		charOut := make([]any, 0, len(chars))
		for _, ch := range chars {
			var corp any
			if ch.CorporationName != nil {
				corp = *ch.CorporationName
			}
			charOut = append(charOut, map[string]any{
				"character_id": ch.CharacterID, "name": ch.Name,
				"is_main": ch.IsMain, "corporation_name": corp,
			})
		}
		sortChars(charOut)
		reactionPlans, _, _ := d.Plans.ReactionPlans(ctx, uid, 1000, 0)
		inventionPlans, _, _ := d.Plans.InventionPlans(ctx, uid, 1000, 0)
		rp := make([]any, 0, len(reactionPlans))
		for _, p := range reactionPlans {
			rp = append(rp, map[string]any{"id": p.ID, "name": p.Name})
		}
		ip := make([]any, 0, len(inventionPlans))
		for _, p := range inventionPlans {
			ip = append(ip, map[string]any{"id": p.ID, "name": p.Name})
		}
		return Success(c, http.StatusOK, map[string]any{
			"industry": industry, "planetary": planetary,
			"characters": charOut, "reaction_plans": rp, "invention_plans": ip,
		}, nil)
	}
}

func sortChars(list []any) {
	sort.SliceStable(list, func(i, j int) bool {
		a := list[i].(map[string]any)
		b := list[j].(map[string]any)
		if a["is_main"] != b["is_main"] {
			return a["is_main"] == true
		}
		return a["name"].(string) < b["name"].(string)
	})
}
