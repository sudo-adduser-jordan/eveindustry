package http

import (
	"net/http"
	"sort"
	"time"

	"github.com/labstack/echo/v4"

	"github.com/sudo-adduser-jordan/eveindustry/internal/auth"
	"github.com/sudo-adduser-jordan/eveindustry/internal/industry"
)

func registerDashboard(v1 *echo.Group, d Deps) {
	if d.Users == nil || d.Plans == nil {
		return
	}
	v1.GET("/dashboard", handleDashboardShow(d))
}

func handleDashboardShow(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		uid := c.Get(string(ctxUIDKey)).(int64)
		ctx := c.Request().Context()
		now := time.Now().UTC()
		ind := map[string]any{"in_progress": 0, "ready_jobs": 0, "available_slots": 0}
		if d.Cache != nil {
			var payload map[string]any
			if d.Cache.GetJSON(ctx, "dashboard.industry_stats."+itoaProg(int(uid)), &payload) {
				inProg, ready, slots := 0, 0, 0
				if totals, ok := payload["totals"].(map[string]any); ok {
					for _, k := range []string{"manufacturing", "research", "reactions"} {
						if t, ok := totals[k].(map[string]any); ok {
							inProg += int(numAny(t["active"]))
							slots += int(numAny(t["available"]))
						}
					}
				}
				if list, ok := payload["characters"].([]any); ok {
					for _, cv := range list {
						ch, ok := cv.(map[string]any)
						if !ok {
							continue
						}
						for _, k := range []string{"manufacturing", "research", "reactions"} {
							g, _ := ch[k].(map[string]any)
							jobs, _ := g["jobs"].([]any)
							for _, jv := range jobs {
								if j, ok := jv.(map[string]any); ok && j["ready_for_delivery"] == true {
									ready++
								}
							}
						}
					}
				}
				ind = map[string]any{"in_progress": inProg, "ready_jobs": ready, "available_slots": slots}
			}
		} else if d.IndustryDeps.Characters != nil && d.Industry != nil && d.Market != nil {
			chars, err := d.Users.CharactersForUser(ctx, uid)
			if err == nil {
				avail := industry.AvailabilityData(ctx, availDeps(d), chars, uid, now)
				inProg, ready, slots := 0, 0, 0
				for _, cv := range avail.Characters {
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
				for _, k := range []string{"manufacturing", "research", "reactions"} {
					inProg += avail.Totals[k]["active"]
					slots += avail.Totals[k]["available"]
				}
				ind = map[string]any{"in_progress": inProg, "ready_jobs": ready, "available_slots": slots}
			}
		}
		planetary := map[string]any{"total_planets": 0, "expired_extractors": 0, "full_storage": 0}
		if d.Cache != nil {
			var piStats map[string]any
			if d.Cache.GetJSON(ctx, "dashboard.pi_stats."+itoaProg(int(uid)), &piStats) {
				planetary = map[string]any{
					"total_planets":      int(numAny(piStats["total_planets"])),
					"expired_extractors": int(numAny(piStats["expired_extractors"])),
					"full_storage":       int(numAny(piStats["full_storage"])),
				}
			}
		} else if d.Planetary.Characters != nil && d.Planetary.Universe != nil {
			if chars, err := collectPlanetary(ctx, d, uid, now); err == nil {
				expired, full, total := 0, 0, 0
				for _, pc := range chars {
					total += len(pc.planets)
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
				planetary = map[string]any{"total_planets": total, "expired_extractors": expired, "full_storage": full}
			}
		}
		chars, err := d.Users.CharactersForUser(ctx, uid)
		if err != nil {
			return dbError(c, err)
		}
		me, err := d.Users.UserByID(ctx, uid)
		if err != nil {
			return dbError(c, err)
		}
		var joined, lastLogin any
		if me != nil {
			joined = rfc3339OrNil(me.CreatedAt)
			lastLogin = rfc3339OrNil(me.LastLoginAt)
		}
		charOut := make([]any, 0, len(chars))
		for _, ch := range chars {
			charOut = append(charOut, map[string]any{
				"character_id": ch.CharacterID, "name": ch.Name,
				"portrait_url":     ch.PortraitURL(),
				"corporation_name": strOrEmpty(ch.CorporationName),
				"corporation_logo": corpLogoURL(ch.CorporationID),
				"alliance_name":    strOrEmpty(ch.AllianceName),
				"alliance_logo":    allianceLogoURL(ch.AllianceID),
				"location":         strOrEmpty(ch.CachedLocation),
				"training":         strOrEmpty(ch.CachedTraining),
				"is_main":          ch.IsMain, "is_esi_active": ch.IsEsiActive,
			})
		}
		sortChars(charOut)
		reactionPlans, _, _ := d.Plans.ReactionPlans(ctx, uid, 1000, 0)
		inventionPlans, _, _ := d.Plans.InventionPlans(ctx, uid, 1000, 0)
		rp := make([]any, 0, len(reactionPlans))
		for _, p := range reactionPlans {
			rp = append(rp, map[string]any{
				"id": p.ID, "name": p.Name,
				"t2_type_name": nil, "updated_at": rfc3339OrNil(timePtr(p.UpdatedAt)),
			})
		}
		ip := make([]any, 0, len(inventionPlans))
		for _, p := range inventionPlans {
			ip = append(ip, map[string]any{
				"id": p.ID, "name": p.Name,
				"t2_type_name": strOrNil(p.T2TypeName),
				"updated_at":   rfc3339OrNil(timePtr(p.UpdatedAt)),
			})
		}
		return Success(c, http.StatusOK, map[string]any{
			"industry": ind, "planetary": planetary,
			"account":    map[string]any{"joined_at": joined, "last_login_at": lastLogin},
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

func rfc3339OrNil(t *time.Time) any {
	if t == nil || t.IsZero() {
		return nil
	}
	return t.UTC().Format(time.RFC3339)
}

func strOrNil(s string) any {
	if s == "" {
		return nil
	}
	return s
}

func timePtr(t time.Time) *time.Time { return &t }

func corpLogoURL(id *int64) string {
	if id == nil || *id <= 0 {
		return ""
	}
	return corpLogo(*id)
}

func allianceLogoURL(id *int64) string {
	if id == nil || *id <= 0 {
		return ""
	}
	if auth.IsDemoID(*id) {
		return "/demo/logo/" + itoa64(*id) + ".svg?size=64"
	}
	return "https://images.evetech.net/alliances/" + itoa64(*id) + "/logo?size=64"
}
