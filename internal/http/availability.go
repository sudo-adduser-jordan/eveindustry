package http

import (
	"context"
	"math"
	"net/http"
	"sort"
	"time"

	"github.com/labstack/echo/v4"

	"github.com/sudo-adduser-jordan/eveindustry/internal/auth"
	"github.com/sudo-adduser-jordan/eveindustry/internal/industry"
	"github.com/sudo-adduser-jordan/eveindustry/internal/store"
)

func registerAvailability(v1 *echo.Group, d Deps) {
	if d.IndustryDeps.Characters == nil || d.IndustryDeps.Universe == nil ||
		d.Users == nil || d.Market == nil || d.Industry == nil || d.Corp == nil {
		return
	}
	v1.GET("/industry/availability", handleAvailability(d))
	v1.GET("/industry/reprocessing", handleReprocessing(d))
}

const (
	skillMassProduction      = 3387
	skillAdvMassProduction   = 24625
	skillLaboratoryOperation = 3406
	skillAdvLaboratory       = 24624
	skillMassReactions       = 45748
	skillAdvMassReactions    = 45749
	skillReprocessing        = 3385
	skillReprocessingEff     = 3389
	skillScrapmetalProc      = 12196

	activityManufacturing = 1
	activityResearchTE    = 3
	activityResearchME    = 4
	activityCopying       = 5
	activityInvention     = 8
	activityReactions     = 9
)

var researchActivities = []int{activityResearchTE, activityResearchME, activityCopying, activityInvention}

var heavyActivityNames = map[int]string{
	activityManufacturing: "Manufacturing",
	activityResearchTE:    "Time Efficiency Research",
	activityResearchME:    "Material Efficiency Research",
	activityCopying:       "Copying",
	activityInvention:     "Invention",
	activityReactions:     "Reactions",
}

func handleAvailability(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		uid := c.Get(string(ctxUIDKey)).(int64)
		ctx := c.Request().Context()
		now := time.Now().UTC()
		if d.Cache != nil {
			var payload map[string]any
			if d.Cache.GetJSON(ctx, "dashboard.industry_stats."+itoaProg(int(uid)), &payload) {
				if chars, ok := payload["characters"].([]any); ok {
					totals := map[string]any{}
					if t, ok := payload["totals"].(map[string]any); ok {
						totals = t
					}
					var ready []any
					if r, ok := payload["ready_for_collection"].([]any); ok {
						ready = r
					}
					return Success(c, http.StatusOK, map[string]any{
						"characters": chars, "totals": totals, "ready_for_collection": ready,
					}, map[string]any{
						"cached_until": now.Add(20 * time.Minute).Format(time.RFC3339),
					})
				}
			}
		}
		chars, err := d.Users.CharactersForUser(ctx, uid)
		if err != nil {
			return dbError(c, err)
		}
		data := industry.AvailabilityData(ctx, availDeps(d), chars, uid, now)
		return Success(c, http.StatusOK, map[string]any{
			"characters": data.Characters,
			"totals": map[string]any{
				"manufacturing": data.Totals["manufacturing"],
				"research":      data.Totals["research"],
				"reactions":     data.Totals["reactions"],
			},
			"ready_for_collection": data.Ready,
		}, map[string]any{
			"cached_until": now.Add(20 * time.Minute).Format(time.RFC3339),
		})
	}
}

func availDeps(d Deps) industry.AvailDeps {
	return industry.AvailDeps{
		Characters: d.IndustryDeps.Characters,
		Universe:   d.IndustryDeps.Universe,
		Corp:       availCorpAdapter{d.Corp},
	}
}

type availCorpAdapter struct{ c store.CorpStore }

func (a availCorpAdapter) UserCorpCharacters(ctx context.Context, userID, corpID int64) ([]industry.CorpMember, error) {
	if a.c == nil {
		return nil, nil
	}
	members, err := a.c.UserCorpCharacters(ctx, userID, corpID)
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

func corpNameOf(ch auth.Character) any {
	if ch.CorporationName != nil {
		return *ch.CorporationName
	}
	return nil
}

func handleReprocessing(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		uid := c.Get(string(ctxUIDKey)).(int64)
		ctx := c.Request().Context()
		chars, err := d.Users.CharactersForUser(ctx, uid)
		if err != nil {
			return dbError(c, err)
		}
		charRows := []any{}
		var totalProfit float64
		totalItems := 0
		for _, ch := range chars {
			row, n := reprocessingForCharacter(ctx, d, ch)
			if row == nil {
				continue
			}
			charRows = append(charRows, row)
			totalProfit += row["total_profit"].(float64)
			totalItems += n
		}
		data := map[string]any{
			"characters": charRows,
			"totals": map[string]any{
				"profit": totalProfit, "characters": len(charRows), "items": totalItems,
			},
		}
		return Success(c, http.StatusOK, data, map[string]any{
			"freshness": freshnessFor(c, d, 10000002),
		})
	}
}

func reprocessingForCharacter(ctx context.Context, d Deps, ch auth.Character) (map[string]any, int) {
	skills, err := d.IndustryDeps.Characters.GetSkills(ctx, ch.CharacterID)
	if err != nil {
		return nil, 0
	}
	if skills == nil {
		skills = map[int]int{}
	}
	yield := 0.50 * (1 + float64(skills[skillReprocessing])*0.03) *
		(1 + float64(skills[skillReprocessingEff])*0.02) *
		(1 + float64(skills[skillScrapmetalProc])*0.02)
	assets, err := d.IndustryDeps.Characters.GetAssets(ctx, ch.CharacterID)
	if err != nil {
		return nil, 0
	}
	groups := map[int64][]map[string]any{}
	typeIDs := map[int]bool{}
	for _, a := range assets {
		loc, _ := int64OfJob(a["location_id"])
		if loc == 0 {
			continue
		}
		tid, _ := int64OfJob(a["type_id"])
		if tid == 0 || isSingletonJob(a) {
			continue
		}
		groups[loc] = append(groups[loc], a)
		typeIDs[int(tid)] = true
	}
	if len(typeIDs) == 0 {
		return charReprocessRow(ch, yield, skills, []any{}), 0
	}
	ids := make([]int, 0, len(typeIDs))
	for id := range typeIDs {
		ids = append(ids, id)
	}
	mats, err := d.Industry.TypeMaterials(ctx, ids)
	if err != nil {
		return nil, 0
	}
	if len(mats) == 0 {
		return charReprocessRow(ch, yield, skills, []any{}), 0
	}
	matIDs := map[int]bool{}
	for _, list := range mats {
		for _, m := range list {
			matIDs[m.MaterialTypeID] = true
		}
	}
	priceIDs := append(append([]int{}, ids...), keysOf(matIDs)...)
	prices := priceMap(ctx, d, priceIDs)
	names, _ := d.Industry.TypeNames(ctx, priceIDs)
	portions, _ := d.Industry.TypePortions(ctx, ids)
	_ = names
	locations := []any{}
	nItems := 0
	for loc, items := range groups {
		profitable := profitableReprocessing(ctx, d, items, yield, mats, prices, names, portions)
		if len(profitable) == 0 {
			continue
		}
		var rv, mv float64
		for _, p := range profitable {
			pm := p.(map[string]any)
			rv += pm["reprocess_value"].(float64)
			mv += pm["market_value"].(float64)
		}
		locations = append(locations, map[string]any{
			"location_id": int(loc), "location_name": reprocessLocationName(ctx, d, ch, loc),
			"items":              profitable,
			"total_market_value": mv, "total_reprocess_value": rv, "total_profit": rv - mv,
		})
		nItems += len(profitable)
	}
	sort.Slice(locations, func(i, j int) bool {
		return locations[i].(map[string]any)["total_profit"].(float64) > locations[j].(map[string]any)["total_profit"].(float64)
	})
	var total float64
	for _, l := range locations {
		total += l.(map[string]any)["total_profit"].(float64)
	}
	row := charReprocessRow(ch, yield, skills, locations)
	row["total_profit"] = total
	return row, nItems
}

func charReprocessRow(ch auth.Character, yield float64, skills map[int]int, locations []any) map[string]any {
	return map[string]any{
		"character_id": ch.CharacterID, "character_name": ch.Name,
		"portrait_url": ch.PortraitURL(), "corporation_name": corpNameOf(ch),
		"yield_pct": math.Round(yield*100*10) / 10,
		"skills": map[string]any{
			"reprocessing":            skills[skillReprocessing],
			"reprocessing_efficiency": skills[skillReprocessingEff],
			"scrapmetal_processing":   skills[skillScrapmetalProc],
		},
		"locations": locations, "total_profit": 0.0,
	}
}

func profitableReprocessing(ctx context.Context, d Deps, items []map[string]any, yield float64, mats map[int][]industry.ReprocessMaterial, prices map[int]float64, names map[int]string, portions map[int]int) []any {
	qtys := map[int]int64{}
	for _, a := range items {
		tid, _ := int64OfJob(a["type_id"])
		if tid == 0 || isSingletonJob(a) {
			continue
		}
		q, _ := int64OfJob(a["quantity"])
		if q <= 0 {
			q = 1
		}
		qtys[int(tid)] += q
	}
	type itemOut struct {
		profit float64
		row    map[string]any
	}
	out := []itemOut{}
	for tid, qty := range qtys {
		list := mats[tid]
		if len(list) == 0 {
			continue
		}
		portion := portions[tid]
		if portion < 1 {
			portion = 1
		}
		batches := int(qty) / portion
		if batches < 1 {
			continue
		}
		unit := prices[tid]
		if unit <= 0 {
			continue
		}
		marketValue := unit * float64(qty)
		var reprocessValue float64
		matRows := []any{}
		for _, m := range list {
			matQty := int(math.Floor(float64(m.Quantity)*yield)) * batches
			matUnit := prices[m.MaterialTypeID]
			matValue := matUnit * float64(matQty)
			reprocessValue += matValue
			mname := names[m.MaterialTypeID]
			if mname == "" {
				mname = "Unknown"
			}
			matRows = append(matRows, map[string]any{
				"type_id": m.MaterialTypeID, "name": mname, "quantity": matQty,
				"unit_price": matUnit, "total_value": matValue,
			})
		}
		profit := reprocessValue - marketValue
		if profit <= 0 || reprocessValue <= 0 {
			continue
		}
		tname := names[tid]
		if tname == "" {
			if info, err := d.IndustryDeps.Universe.GetTypeInfo(ctx, tid); err == nil && info != nil {
				tname, _ = info["name"].(string)
			}
			if tname == "" {
				tname = "Unknown"
			}
		}
		out = append(out, itemOut{profit: profit, row: map[string]any{
			"type_id": tid, "name": tname, "quantity": int(qty),
			"market_value": marketValue, "reprocess_value": reprocessValue, "profit": profit,
			"profit_pct":        math.Round(profit/marketValue*100*10) / 10,
			"unit_market_price": unit, "materials": matRows,
			"icon_url": "https://images.evetech.net/types/" + itoaJob(tid) + "/icon?size=32",
		}})
	}
	sort.Slice(out, func(i, j int) bool { return out[i].profit > out[j].profit })
	rows := make([]any, 0, len(out))
	for _, o := range out {
		rows = append(rows, o.row)
	}
	return rows
}

func priceMap(ctx context.Context, d Deps, ids []int) map[int]float64 {
	out := map[int]float64{}
	rows, err := d.Market.MarketPriceRows(ctx, ids, 10000002)
	if err != nil {
		return out
	}
	for _, r := range rows {
		if r.SellMin.Valid && r.SellMin.Float64 > 0 {
			out[r.TypeID] = r.SellMin.Float64
		} else if r.Average.Valid {
			out[r.TypeID] = r.Average.Float64
		} else {
			out[r.TypeID] = 0
		}
	}
	return out
}

func reprocessLocationName(ctx context.Context, d Deps, ch auth.Character, loc int64) string {
	uv := d.IndustryDeps.Universe
	if loc >= 60000000 && loc < 64000000 {
		if st, err := uv.GetStation(ctx, uint64(loc)); err == nil && st != nil {
			if n, ok := st["name"].(string); ok && n != "" {
				return n
			}
		}
	} else if loc > 2000000000000 {
		if st, err := uv.GetStructure(ctx, uint64(loc), ch.CharacterID); err == nil && st != nil {
			if n, ok := st["name"].(string); ok && n != "" {
				return n
			}
		}
	} else if loc >= 30000000 && loc < 33000000 {
		if s, err := uv.GetSystem(ctx, int(loc)); err == nil && s != nil {
			if n, ok := s["name"].(string); ok && n != "" {
				return n
			}
		}
	}
	return "Location #" + itoaJob(int(loc))
}

func keysOf(m map[int]bool) []int {
	out := make([]int, 0, len(m))
	for k := range m {
		out = append(out, k)
	}
	return out
}

func strOfJob(v any) string {
	s, _ := v.(string)
	return s
}

func strOfJobDefault(v any, def string) string {
	if s, ok := v.(string); ok && s != "" {
		return s
	}
	return def
}

func int64OfJob(v any) (int64, bool) {
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

func numFloatJob(v any) (float64, bool) {
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

func firstNonZeroJob(j map[string]any, keys ...string) any {
	for _, k := range keys {
		if n, ok := int64OfJob(j[k]); ok && n != 0 {
			return n
		}
	}
	return 0
}

func mustJobID(j map[string]any) int64 {
	n, _ := int64OfJob(j["job_id"])
	return n
}

func isSingletonJob(a map[string]any) bool {
	b, _ := a["is_singleton"].(bool)
	return b
}

func parseJobTime(s string) (time.Time, bool) {
	for _, layout := range []string{time.RFC3339, "2006-01-02T15:04:05Z", time.RFC3339Nano} {
		if t, err := time.Parse(layout, s); err == nil {
			return t.UTC(), true
		}
	}
	return time.Time{}, false
}

func firstNonEmpty(vals ...string) string {
	for _, v := range vals {
		if v != "" {
			return v
		}
	}
	return ""
}

func trimFloat(v float64) string {
	s := ""
	neg := v < 0
	if neg {
		v = -v
	}
	iv := int(v)
	s = itoaJob(iv)
	frac := v - float64(iv)
	if frac > 0 {
		f := int(math.Round(frac * 100))
		if f > 0 {
			fs := itoaJob(f)
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

func itoaJob(i int) string {
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
