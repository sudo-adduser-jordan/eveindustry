package http

import (
	"context"
	"encoding/json"
	"math"
	"net/http"
	"sort"
	"time"

	"github.com/labstack/echo/v4"

	"github.com/sudo-adduser-jordan/eveindustry/internal/auth"
	"github.com/sudo-adduser-jordan/eveindustry/internal/calc"
	"github.com/sudo-adduser-jordan/eveindustry/internal/market"
	"github.com/sudo-adduser-jordan/eveindustry/internal/store"
)

// registerMoonScans mounts the three heavy moon-scan routes.
func registerMoonScans(v1 *echo.Group, d Deps) {
	if d.MoonScans == nil || d.Users == nil || d.Market == nil ||
		d.Industry == nil || d.Reference == nil {
		return
	}
	v1.POST("/moon-scans", handleMoonScanStore(d))
	v1.GET("/moon-scans/systems", handleMoonScanSystems(d))
	v1.GET("/moon-scans/systems/:system", handleMoonScanSystem(d))
}

// moonCorpID ports corporationIdForUser: the main character's corporation,
// else the first character's.
func moonCorpID(chars []auth.Character) *int64 {
	for _, ch := range chars {
		if ch.IsMain && ch.CorporationID != nil && *ch.CorporationID != 0 {
			return ch.CorporationID
		}
	}
	for _, ch := range chars {
		if ch.CorporationID != nil && *ch.CorporationID != 0 {
			return ch.CorporationID
		}
	}
	return nil
}

// POST /moon-scans — parse, price, store. 201 when at least one moon stored.
func handleMoonScanStore(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		verrs := validationErrors{}
		raw := decodePlanBody(c, verrs)
		if raw == nil && len(verrs) > 0 {
			return verrs.invalid(c)
		}
		if raw == nil {
			raw = map[string]any{}
		}
		data, _ := raw["scan_data"].(string)
		if _, present := raw["scan_data"]; !present || raw["scan_data"] == nil || data == "" {
			verrs.add("scan_data", "The scan data field is required.")
		} else if len([]rune(data)) > 200000 {
			verrs.add("scan_data", "The scan data field must not be greater than 200000 characters.")
		}
		var regionID *int
		if v, present := raw["region_id"]; present && v != nil {
			n, isNum := toFloat(v)
			if !isNum || n != float64(int(n)) {
				verrs.add("region_id", "The region id field must be an integer.")
			} else if _, ok := market.Regions[int(n)]; !ok {
				verrs.add("region_id", "The selected region id is invalid.")
			} else {
				regionID = newInt(int(n))
			}
		}
		mode := "raw"
		if v, present := raw["pricing_mode"]; present && v != nil {
			s, isStr := v.(string)
			if !isStr || (s != "raw" && s != "compressed" && s != "refined") {
				verrs.add("pricing_mode", "The pricing mode field must be one of raw, compressed, refined.")
			} else {
				mode = s
			}
		}
		if len(verrs) > 0 {
			return verrs.invalid(c)
		}
		uid := c.Get(string(ctxUIDKey)).(int64)
		ctx := c.Request().Context()
		region := market.DefaultRegion
		if regionID != nil {
			region = *regionID
		}
		moons, errs := analyseMoonScan(ctx, d, uid, data, region, mode)
		if errs == nil {
			errs = []string{}
		}
		stored := false
		for _, m := range moons {
			if m["id"] != nil {
				stored = true
				break
			}
		}
		status := http.StatusOK
		if stored {
			status = http.StatusCreated
		}
		return Success(c, status, map[string]any{
			"pricing_mode": mode, "moons": moons, "errors": errs,
		}, map[string]any{"freshness": freshnessFor(c, d, region)})
	}
}

// analyseMoonScan ports MoonScanService::analyse + the lookup wrapper:
// parse, enrich, persist under the user's corporation, attach ids.
func analyseMoonScan(ctx context.Context, d Deps, uid int64, data string, region int, mode string) ([]map[string]any, []string) {
	parsed, errs := calc.ParseMoonScan(data)
	chars, err := d.Users.CharactersForUser(ctx, uid)
	if err != nil {
		return nil, []string{err.Error()}
	}
	corpID := moonCorpID(chars)
	typeSet := map[int]bool{}
	for _, m := range parsed {
		for _, o := range m.Ores {
			if o.TypeID != 0 {
				typeSet[o.TypeID] = true
			}
		}
	}
	ids := make([]int, 0, len(typeSet))
	for id := range typeSet {
		ids = append(ids, id)
	}
	types := oreTypeMap(ctx, d, ids)
	prices := moonPrices(ctx, d, ids, types, region, mode)
	valued := calc.ValueMoons(parsed, types, prices)
	out := make([]map[string]any, 0, len(valued))
	for _, vm := range valued {
		ores := make([]any, 0, len(vm.Ores))
		for _, o := range vm.Ores {
			ores = append(ores, map[string]any{
				"name": o.Name, "type_id": o.TypeID, "quantity": o.Quantity,
				"price_per_unit": o.PricePerUnit, "tier": o.Tier,
				"ore_units": o.OreUnits, "value_contribution": o.ValueContribution,
			})
		}
		row := map[string]any{
			"id": nil, "name": vm.Name, "grade": vm.Grade, "total_value": vm.TotalValue,
			"ores": ores, "occupied": false,
			"occupying_corporation_id": nil, "occupying_corporation_name": nil,
		}
		planet := calc.ParsePlanetName(vm.Name)
		system := calc.ParseSystemName(planet)
		if system != "" && d.MoonScans != nil {
			sys, err := d.MoonScans.UpsertMoonScanSystem(ctx, system, corpID, int64(region), mode)
			if err == nil && sys != nil {
				var planetP *string
				if planet != "" {
					planetP = &planet
				}
				oresJSON := mustJSONStr(oresCompact(ores))
				id, err := d.MoonScans.UpsertMoonScanResult(ctx, sys.ID, vm.Name, planetP, vm.Grade, oresJSON, int64(vm.TotalValue))
				if err == nil {
					row["id"] = id
				}
			}
		}
		out = append(out, row)
	}
	return out, errs
}

func oresCompact(ores []any) []any {
	out := make([]any, 0, len(ores))
	for _, v := range ores {
		o := v.(map[string]any)
		out = append(out, map[string]any{
			"name": o["name"], "type_id": o["type_id"], "quantity": o["quantity"],
			"tier": o["tier"], "ore_units": o["ore_units"],
			"price_per_unit": o["price_per_unit"], "value_contribution": o["value_contribution"],
		})
	}
	return out
}

// oreTypeMap batch-loads ore type rows (name, volume, portion).
func oreTypeMap(ctx context.Context, d Deps, ids []int) map[int]calc.OreType {
	out := map[int]calc.OreType{}
	if len(ids) == 0 || d.Reference == nil {
		return out
	}
	rows, err := d.Reference.TypesByIDs(ctx, ids)
	if err != nil {
		return out
	}
	for _, r := range rows {
		t := calc.OreType{TypeID: r.TypeID, Name: r.Name}
		if r.Volume.Valid {
			t.Volume = r.Volume.Float64
		}
		if r.PortionSize.Valid && r.PortionSize.Int64 > 0 {
			t.Portion = int(r.PortionSize.Int64)
		}
		out[r.TypeID] = t
	}
	return out
}

// moonPrices ports resolveEffectivePrices over resolvePrices().
func moonPrices(ctx context.Context, d Deps, ids []int, types map[int]calc.OreType, region int, mode string) map[int]float64 {
	priceOf := func(list []int) map[int]float64 {
		out := map[int]float64{}
		if len(list) == 0 {
			return out
		}
		resolved, err := resolvePrices(ctx, d.Market, list, region)
		if err != nil {
			return out
		}
		for id, r := range resolved {
			out[id] = r.Price
		}
		return out
	}
	switch mode {
	case "compressed":
		rawNames := map[int]string{}
		want := []string{}
		for _, id := range ids {
			if t, ok := types[id]; ok && t.Name != "" {
				rawNames[id] = t.Name
				want = append(want, "Compressed "+t.Name)
			}
		}
		byName := map[string]int{}
		if len(want) > 0 && d.Reference != nil {
			if rows, err := d.Reference.TypesByNames(ctx, want); err == nil {
				for _, r := range rows {
					byName[r.Name] = r.TypeID
				}
			}
		}
		compIDs := map[int]bool{}
		for _, id := range byName {
			compIDs[id] = true
		}
		list := make([]int, 0, len(compIDs))
		for id := range compIDs {
			list = append(list, id)
		}
		return calc.EffectivePricesCompressed(ids, byName, priceOf(list), rawNames)
	case "refined":
		mats, err := d.Industry.TypeMaterials(ctx, ids)
		if err != nil {
			return map[int]float64{}
		}
		minSet := map[int]bool{}
		refined := map[int][]calc.RefinedMaterial{}
		for ore, list := range mats {
			for _, m := range list {
				refined[ore] = append(refined[ore], calc.RefinedMaterial{Quantity: m.Quantity, Mineral: m.MaterialTypeID})
				minSet[m.MaterialTypeID] = true
			}
		}
		minIDs := make([]int, 0, len(minSet))
		for id := range minSet {
			minIDs = append(minIDs, id)
		}
		portions := map[int]int{}
		for id, t := range types {
			portions[id] = t.Portion
		}
		return calc.EffectivePricesRefined(ids, portions, refined, priceOf(minIDs))
	default:
		return calc.EffectivePricesRaw(ids, priceOf(ids))
	}
}

// GET /moon-scans/systems — the corporation's scanned systems, by name.
// Empty without a character.
func handleMoonScanSystems(d Deps) echo.HandlerFunc {
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
		items := []any{}
		if corpID := moonCorpID(chars); corpID != nil {
			rows, err := d.MoonScans.MoonScanSystems(ctx, *corpID)
			if err != nil {
				return dbError(c, err)
			}
			for _, r := range rows {
				items = append(items, moonSystemShape(r))
			}
		}
		return paginated(c, items, page, perPage)
	}
}

func moonSystemShape(r store.MoonScanSystem) map[string]any {
	var corpID any
	if r.CorporationID.Valid {
		corpID = r.CorporationID.Int64
	}
	return map[string]any{
		"id": r.ID, "name": r.SystemName, "corporation_id": corpID,
		"region_id": r.RegionID, "pricing_mode": r.PricingMode,
		"last_scanned_at": r.LastScannedAt.UTC().Format(time.RFC3339),
	}
}

// GET /moon-scans/systems/:system — the system's moons best first, re-priced
// on the fly. 422 without a corporation, 404 unscanned. system meta carries
// the stored name/region/mode plus a fresh scanned_at.
func handleMoonScanSystem(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		page, perPage, ok := listPage(c)
		if !ok {
			return errHandled
		}
		system := c.Param("system")
		verrs := validationErrors{}
		var mode *string
		if vals, present := c.QueryParams()["pricing_mode"]; present {
			v := ""
			if len(vals) > 0 {
				v = vals[0]
			}
			if v != "raw" && v != "compressed" && v != "refined" {
				verrs.add("pricing_mode", "The pricing mode field must be one of raw, compressed, refined.")
				return verrs.invalid(c)
			}
			mode = &v
		}
		uid := c.Get(string(ctxUIDKey)).(int64)
		ctx := c.Request().Context()
		chars, err := d.Users.CharactersForUser(ctx, uid)
		if err != nil {
			return dbError(c, err)
		}
		corpID := moonCorpID(chars)
		if corpID == nil {
			return Failure(c, http.StatusUnprocessableEntity, "No corporation found.", nil)
		}
		rec, err := d.MoonScans.MoonScanSystem(ctx, *corpID, system)
		if err != nil {
			return dbError(c, err)
		}
		if rec == nil {
			return Failure(c, http.StatusNotFound, "No scan data found for this system.", nil)
		}
		rows, err := d.MoonScans.MoonScanMoons(ctx, rec.ID)
		if err != nil {
			return dbError(c, err)
		}
		effective := rec.PricingMode
		if mode != nil {
			effective = *mode
		}
		moons := storedMoons(rows)
		if mode != nil || effective != rec.PricingMode {
			repriced, err := repriceMoons(ctx, d, rows, int(rec.RegionID), effective)
			if err != nil {
				return dbError(c, err)
			}
			moons = repriced
		}
		return Success(c, http.StatusOK, pagedMoons(moons, page, perPage), map[string]any{
			"system": map[string]any{
				"name": rec.SystemName, "region_id": rec.RegionID,
				"pricing_mode": effective,
				"scanned_at": rec.LastScannedAt.UTC().Format(time.RFC3339),
			},
			"freshness": freshnessFor(c, d, int(rec.RegionID)),
		})
	}
}

func pagedMoons(moons []any, page, perPage int) []any {
	total := len(moons)
	start := (page - 1) * perPage
	if start > total {
		start = total
	}
	end := start + perPage
	if end > total {
		end = total
	}
	return moons[start:end]
}

// storedMoons renders rows as-is (stored pricing).
func storedMoons(rows []store.MoonScanResult) []any {
	out := make([]any, 0, len(rows))
	for _, r := range rows {
		var ores any = []any{}
		if r.OresJSON != "" {
			var decoded any
			if json.Unmarshal([]byte(r.OresJSON), &decoded) == nil {
				ores = decoded
			}
		}
		var occID any
		if r.OccupyingCorporationID.Valid {
			occID = r.OccupyingCorporationID.Int64
		}
		var occName any
		if r.OccupyingCorporationName.Valid {
			occName = r.OccupyingCorporationName.String
		}
		out = append(out, map[string]any{
			"id": r.ID, "name": r.MoonName, "grade": r.Grade,
			"total_value": float64(r.TotalValue), "ores": ores,
			"occupied": r.Occupied,
			"occupying_corporation_id": occID, "occupying_corporation_name": occName,
		})
	}
	return out
}

// repriceMoons ports repriceSystemMoons: stored ore_units kept, prices and
// totals recomputed under the requested mode.
func repriceMoons(ctx context.Context, d Deps, rows []store.MoonScanResult, region int, mode string) ([]any, error) {
	type oreIn struct {
		name     string
		typeID   int
		quantity float64
		tier     int
		units    int
	}
	parsed := make([]struct {
		row  store.MoonScanResult
		ores []oreIn
	}, 0, len(rows))
	typeSet := map[int]bool{}
	for _, r := range rows {
		var stored []map[string]any
		if r.OresJSON != "" {
			_ = json.Unmarshal([]byte(r.OresJSON), &stored)
		}
		entry := struct {
			row  store.MoonScanResult
			ores []oreIn
		}{row: r}
		for _, o := range stored {
			tid := intOfMoon(o["type_id"])
			entry.ores = append(entry.ores, oreIn{
				name: strOfMoon(o["name"]), typeID: tid,
				quantity: floatOfMoon(o["quantity"]), tier: intOfMoon(o["tier"]),
				units: intOfMoon(o["ore_units"]),
			})
			if tid != 0 {
				typeSet[tid] = true
			}
		}
		parsed = append(parsed, entry)
	}
	ids := make([]int, 0, len(typeSet))
	for id := range typeSet {
		ids = append(ids, id)
	}
	types := oreTypeMap(ctx, d, ids)
	prices := moonPrices(ctx, d, ids, types, region, mode)
	out := make([]any, 0, len(parsed))
	for _, p := range parsed {
		ores := make([]any, 0, len(p.ores))
		var total float64
		maxTier := 0
		for _, o := range p.ores {
			units := o.units
			if units <= 0 && o.typeID != 0 {
				vol := calc.DefaultOreVolume
				if t, ok := types[o.typeID]; ok && t.Volume > 0 {
					vol = t.Volume
				}
				units = int(math.Floor(o.quantity * calc.TotalMoonM3 / vol))
			}
			price := prices[o.typeID]
			isk := float64(units) * price
			total += isk
			if o.tier > maxTier {
				maxTier = o.tier
			}
			ores = append(ores, map[string]any{
				"name": o.name, "type_id": o.typeID, "quantity": o.quantity,
				"tier": o.tier, "ore_units": units,
				"price_per_unit": price, "value_contribution": isk,
			})
		}
		var occID any
		if p.row.OccupyingCorporationID.Valid {
			occID = p.row.OccupyingCorporationID.Int64
		}
		var occName any
		if p.row.OccupyingCorporationName.Valid {
			occName = p.row.OccupyingCorporationName.String
		}
		out = append(out, map[string]any{
			"id": p.row.ID, "name": p.row.MoonName,
			"grade": calc.MoonGrade(maxTier), "total_value": total, "ores": ores,
			"occupied": p.row.Occupied,
			"occupying_corporation_id": occID, "occupying_corporation_name": occName,
		})
	}
	sort.Slice(out, func(i, j int) bool {
		return out[i].(map[string]any)["total_value"].(float64) > out[j].(map[string]any)["total_value"].(float64)
	})
	return out, nil
}

func intOfMoon(v any) int {
	switch n := v.(type) {
	case int:
		return n
	case int64:
		return int(n)
	case float64:
		return int(n)
	}
	return 0
}

func floatOfMoon(v any) float64 {
	switch n := v.(type) {
	case int:
		return float64(n)
	case int64:
		return float64(n)
	case float64:
		return n
	}
	return 0
}

func strOfMoon(v any) string {
	s, _ := v.(string)
	return s
}
