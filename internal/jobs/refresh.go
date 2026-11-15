package jobs

import (
	"context"
	"log/slog"
	"time"

	"github.com/sudo-adduser-jordan/eveindustry/internal/assets"
	"github.com/sudo-adduser-jordan/eveindustry/internal/auth"
	"github.com/sudo-adduser-jordan/eveindustry/internal/cache"
	"github.com/sudo-adduser-jordan/eveindustry/internal/esi"
	"github.com/sudo-adduser-jordan/eveindustry/internal/industry"
	"github.com/sudo-adduser-jordan/eveindustry/internal/pi"
	"github.com/sudo-adduser-jordan/eveindustry/internal/store"
)

type HandlerDeps struct {
	Log         *slog.Logger
	Cache       *cache.Cache
	DB          *store.SQL
	Industry    *industry.SQL
	ESIChars    *esi.CharacterClient
	ESIUniverse *esi.UniverseClient
	ESIMarket   *esi.MarketClient
}

func (d HandlerDeps) logger() *slog.Logger {
	if d.Log != nil {
		return d.Log
	}
	return slog.Default()
}

func assetCacheKey(uid int64) string    { return "assets.user." + itoa(uid) }
func assetCachedAtKey(uid int64) string { return "assets.user." + itoa(uid) + ".cached_at" }

func RefreshCharacterProfile(ctx context.Context, d HandlerDeps, characterID int64) error {
	chars, err := d.DB.EsiActiveCharacters(ctx, nil)
	if err != nil {
		return err
	}
	var ch *auth.Character
	for i := range chars {
		if chars[i].CharacterID == characterID {
			ch = &chars[i]
			break
		}
	}
	if ch == nil {
		return nil
	}
	var upd store.CharacterRefresh
	aff, err := d.ESIChars.GetAffiliation(ctx, ch.CharacterID)
	if err != nil {
		return err
	}
	if aff != nil {
		if corp, ok := intOfESI(aff["corporation_id"]); ok && corp != 0 {
			if ch.CorporationID == nil || *ch.CorporationID != corp {
				upd.CorporationID = &corp
				upd.Roles = []string{}
				upd.NullRoles = true
				upd.IsDirectorCEO = boolPtr(false)
				if info, err := d.ESIUniverse.GetCorporation(ctx, corp); err == nil && info != nil {
					if n, ok := info["name"].(string); ok && n != "" {
						upd.CorporationName = &n
					}
				}
			}
			if ally, ok := intOfESI(aff["alliance_id"]); ok && ally != 0 {
				if ch.AllianceID == nil || *ch.AllianceID != ally {
					upd.AllianceID = &ally
					if info, err := d.ESIUniverse.GetAlliance(ctx, ally); err == nil && info != nil {
						if n, ok := info["name"].(string); ok && n != "" {
							upd.AllianceName = &n
						}
					}
				}
			} else if ch.AllianceID != nil {
				zero := int64(0)
				upd.AllianceID = &zero
			}
		}
	}
	if hasScope(*ch, "esi-location.read_location.v1") {
		if pos, err := d.ESIChars.GetLocation(ctx, ch.CharacterID); err == nil && pos != nil {
			if sys, ok := intOfESI(pos["solar_system_id"]); ok && sys != 0 {
				if info, err := d.ESIUniverse.GetSystem(ctx, int(sys)); err == nil && info != nil {
					if n, ok := info["name"].(string); ok && n != "" {
						upd.CachedLocation = &n
					}
				}
			}
		}
	}
	if hasScope(*ch, "esi-skills.read_skillqueue.v1") {
		if queue, err := d.ESIChars.GetSkillqueue(ctx, ch.CharacterID); err == nil {
			for _, entry := range queue {
				if pos, _ := intOfESI(entry["queue_position"]); pos != 0 {
					continue
				}
				skillID, _ := intOfESI(entry["skill_id"])
				if skillID == 0 {
					upd.NullTraining = true
					break
				}
				name := "Skill"
				if info, err := d.ESIUniverse.GetTypeInfo(ctx, int(skillID)); err == nil && info != nil {
					if n, ok := info["name"].(string); ok && n != "" {
						name = n
					}
				}
				level, _ := intOfESI(entry["finished_level"])
				label := name + " " + itoa(level)
				upd.CachedTraining = &label
				break
			}
		}
	}
	if hasScope(*ch, "esi-skills.read_skills.v1") {
		_, _ = d.ESIChars.GetSkills(ctx, ch.CharacterID)
	}
	if hasScope(*ch, "esi-characters.read_corporation_roles.v1") {
		if roles, err := d.ESIChars.GetCharacterRoles(ctx, ch.CharacterID); err == nil && roles != nil {
			var list []string
			if r, ok := roles["roles"].([]any); ok {
				for _, item := range r {
					if s, ok := item.(string); ok {
						list = append(list, s)
					}
				}
			}
			dir := false
			for _, r := range list {
				if r == "Director" || r == "CEO" {
					dir = true
					break
				}
			}
			upd.Roles = list
			upd.IsDirectorCEO = &dir
		}
	}
	if err := d.DB.UpdateCharacterRefresh(ctx, ch.CharacterID, upd); err != nil {
		return err
	}
	d.logger().Info("refreshed character", "character_id", ch.CharacterID)
	return nil
}

func RefreshAssetData(ctx context.Context, d HandlerDeps, userID int64, force bool) error {
	chars, err := d.DB.EsiActiveCharacters(ctx, &userID)
	if err != nil {
		return err
	}
	if len(chars) == 0 {
		return nil
	}
	if !force && d.Cache != nil {
		var cachedAt int64
		if d.Cache.GetJSON(ctx, assetCachedAtKey(userID), &cachedAt) {
			if time.Since(time.Unix(cachedAt, 0)) < 20*time.Minute {
				return nil
			}
		}
	}
	ad := assets.Deps{
		Characters: d.ESIChars, Universe: d.ESIUniverse,
		Reference: assetRefAdapter{db: d.DB}, Industry: d.Industry,
	}
	all := assets.FetchUserAssets(ctx, ad, chars, true)
	if d.Cache != nil {
		d.Cache.SetJSON(ctx, assetCacheKey(userID), all, 0)
		d.Cache.SetJSON(ctx, assetCachedAtKey(userID), time.Now().Unix(), 0)
	}
	d.logger().Info("refreshed assets", "user_id", userID, "items", len(all))
	return nil
}

type assetRefAdapter struct{ db *store.SQL }

func (a assetRefAdapter) FindStation(ctx context.Context, id uint64) (string, *int, bool) {
	st, err := a.db.FindStation(ctx, id)
	if err != nil || st == nil {
		return "", nil, false
	}
	var sys *int
	if st.SolarSystemID.Valid {
		v := int(st.SolarSystemID.Int64)
		sys = &v
	}
	return st.StationName, sys, true
}

func (a assetRefAdapter) FindSystem(ctx context.Context, id int) (string, bool) {
	st, err := a.db.FindSystem(ctx, id)
	if err != nil || st == nil {
		return "", false
	}
	return st.SolarSystemName, true
}

func RefreshIndustryData(ctx context.Context, d HandlerDeps, userID int64) error {
	chars, err := d.DB.EsiActiveCharacters(ctx, &userID)
	if err != nil {
		return err
	}
	if len(chars) == 0 {
		return nil
	}
	avail := industry.AvailabilityData(ctx, industry.AvailDeps{
		Characters: d.ESIChars, Universe: d.ESIUniverse, Corp: corpAdapter{db: d.DB},
	}, chars, userID, time.Now().UTC())
	if d.Cache != nil {
		d.Cache.SetJSON(ctx, "dashboard.industry_stats."+itoa(userID), map[string]any{
			"characters": avail.Characters, "totals": totalsOf(avail.Totals),
			"ready_for_collection": avail.Ready,
		}, 1200*time.Second)
	}
	d.logger().Info("refreshed industry", "user_id", userID)
	return nil
}

func totalsOf(t map[string]map[string]int) map[string]any {
	out := map[string]any{}
	for k, v := range t {
		out[k] = map[string]any{"total": v["total"], "active": v["active"], "available": v["available"]}
	}
	return out
}

type corpAdapter struct{ db *store.SQL }

func (a corpAdapter) UserCorpCharacters(ctx context.Context, userID, corpID int64) ([]industry.CorpMember, error) {
	members, err := a.db.UserCorpCharacters(ctx, userID, corpID)
	if err != nil {
		return nil, err
	}
	out := make([]industry.CorpMember, 0, len(members))
	for _, m := range members {
		out = append(out, industry.CorpMember{
			CharacterID: m.CharacterID, IsEsiActive: m.IsEsiActive,
			Scopes: m.Scopes, IsDirectorCEO: m.IsDirectorCEO, Roles: m.Roles,
		})
	}
	return out, nil
}

func RefreshDashboardStats(ctx context.Context, d HandlerDeps, userID int64) error {
	if err := RefreshIndustryData(ctx, d, userID); err != nil {
		return err
	}
	chars, err := d.DB.EsiActiveCharacters(ctx, &userID)
	if err != nil {
		return err
	}
	colonies, err := pi.AnalyzeColonies(ctx, pi.ColonyDeps{
		Characters: d.ESIChars, Universe: d.ESIUniverse, Industry: d.Industry,
	}, chars, time.Now().UTC())
	if err != nil {
		return err
	}
	expired, full := 0, 0
	for _, c := range colonies {
		for _, av := range c.Analysis.Alerts {
			if a, ok := av.(map[string]any); ok && a["type"] == "extractor_expired" {
				expired++
			}
		}
		for _, pv := range c.Analysis.Pins {
			p, _ := pv.(map[string]any)
			if st, ok := p["storage"].(map[string]any); ok {
				if u, ok := st["usage_percent"].(float64); ok && u >= 90 {
					full++
				}
			}
		}
	}
	if d.Cache != nil {
		d.Cache.SetJSON(ctx, "dashboard.pi_stats."+itoa(userID), map[string]any{
			"total_planets": len(colonies), "expired_extractors": expired, "full_storage": full,
		}, 1200*time.Second)
		var flat []map[string]any
		for _, c := range colonies {
			flat = append(flat, map[string]any{
				"character_name": c.Char.Name, "planet_name": c.Name,
				"planet_type": c.Type, "summary": map[string]any{
					"extractor_count": c.Analysis.Summary["extractor_count"],
					"factory_count":   c.Analysis.Summary["factory_count"],
				},
			})
		}
		d.Cache.SetJSON(ctx, "planetary.user."+itoa(userID), flat, 1200*time.Second)
	}
	d.logger().Info("refreshed dashboard", "user_id", userID)
	return nil
}

func ActiveUserIDs(ctx context.Context, db *store.SQL) ([]int64, error) {
	return db.ActiveUserIDs(ctx)
}

func hasScope(ch auth.Character, scope string) bool {
	for _, s := range ch.Scopes {
		if s == scope {
			return true
		}
	}
	return false
}

func intOfESI(v any) (int64, bool) {
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

func boolPtr(b bool) *bool { return &b }

func itoa(i int64) string {
	if i == 0 {
		return "0"
	}
	neg := i < 0
	if neg {
		i = -i
	}
	var b [20]byte
	p := len(b)
	for i > 0 {
		p--
		b[p] = byte('0' + i%10)
		i /= 10
	}
	if neg {
		p--
		b[p] = '-'
	}
	return string(b[p:])
}
