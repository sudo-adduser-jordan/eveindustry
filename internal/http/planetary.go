package http

import (
	"context"
	"errors"
	"net/http"
	"sort"
	"time"

	"github.com/labstack/echo/v4"

	"github.com/sudo-adduser-jordan/eveindustry/internal/auth"
	"github.com/sudo-adduser-jordan/eveindustry/internal/pi"
)

// PlanetaryColonyESI fetches colonies (CharacterClient satisfies it).
type PlanetaryColonyESI interface {
	GetCharacterPlanets(ctx context.Context, characterID int64) ([]map[string]any, error)
	GetCharacterPlanetDetails(ctx context.Context, characterID int64, planetID int) (map[string]any, error)
	ClearPlanetaryCache(characterID int64)
}

// PlanetaryUniverseESI resolves schematics, planets and types (UniverseClient).
type PlanetaryUniverseESI interface {
	GetPlanetarySchematic(ctx context.Context, schematicID int) (map[string]any, error)
	GetPlanetInfo(ctx context.Context, planetID int) (map[string]any, error)
	GetTypeInfo(ctx context.Context, typeID int) (map[string]any, error)
}

// PlanetaryDeps carries the ESI surface the planetary dashboard needs.
type PlanetaryDeps struct {
	Characters PlanetaryColonyESI
	Universe   PlanetaryUniverseESI
}

// registerPlanetary mounts the PI dashboard + refresh.
func registerPlanetary(v1 *echo.Group, d Deps) {
	if d.Planetary.Characters == nil || d.Planetary.Universe == nil || d.Users == nil || d.Industry == nil {
		return
	}
	v1.GET("/planetary", handlePlanetaryShow(d))
	v1.POST("/planetary/refresh", handlePlanetaryRefresh(d))
}

type colonyRaw struct {
	ch      *auth.Character
	colony  map[string]any
	details map[string]any
}

// GET /planetary — colonies with extractors, factories, storage, outputs,
// alerts and capacity. ESI-active characters with the PI scope.
// GET /planetary — colonies with extractors, factories, storage, outputs,
// alerts and capacity. ESI-active characters with the PI scope.
func handlePlanetaryShow(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		uid := c.Get(string(ctxUIDKey)).(int64)
		ctx := c.Request().Context()
		dash, err := planetaryDashboardData(ctx, d, uid, time.Now().UTC())
		if err != nil {
			return dbError(c, err)
		}
		return Success(c, http.StatusOK, dash, nil)
	}
}

// planetaryChar is one dashboard character with analyzed planets.
type planetaryChar struct {
	ch      *auth.Character
	planets []any
}

// collectPlanetary fetches and analyzes every eligible colony.
func collectPlanetary(ctx context.Context, d Deps, uid int64, now time.Time) ([]planetaryChar, error) {
	chars, err := d.Users.CharactersForUser(ctx, uid)
	if err != nil {
		return nil, err
	}
	var raw []colonyRaw
	for i := range chars {
		ch := &chars[i]
		if !ch.IsEsiActive || !hasScope(*ch, "esi-planets.manage_planets.v1") {
			continue
		}
		planets, err := d.Planetary.Characters.GetCharacterPlanets(ctx, ch.CharacterID)
		if err != nil {
			return nil, err
		}
		if planets == nil {
			continue
		}
		for _, colony := range planets {
			pid := piInt(colony["planet_id"])
			if pid == 0 {
				continue
			}
			details, err := d.Planetary.Characters.GetCharacterPlanetDetails(ctx, ch.CharacterID, pid)
			if err != nil {
				return nil, err
			}
			if details == nil {
				return nil, errColonyIncomplete
			}
			raw = append(raw, colonyRaw{ch: ch, colony: colony, details: details})
		}
	}
	resolver := piBatchResolver(ctx, d, raw)
	out := []planetaryChar{}
	byChar := map[int64]int{}
	for _, r := range raw {
		i, ok := byChar[r.ch.CharacterID]
		if !ok {
			i = len(out)
			byChar[r.ch.CharacterID] = i
			out = append(out, planetaryChar{ch: r.ch})
		}
		out[i].planets = append(out[i].planets, planetaryAnalyze(ctx, d, r.ch, r.colony, r.details, resolver, now))
	}
	return out, nil
}

// planetaryDashboardData ports the dashboard + PI-show aggregation.
func planetaryDashboardData(ctx context.Context, d Deps, uid int64, now time.Time) (map[string]any, error) {
	chars, err := collectPlanetary(ctx, d, uid, now)
	if err != nil {
		return nil, err
	}
	charOut := make([]any, 0, len(chars))
	for _, pc := range chars {
		var portrait any
		if pc.ch.PortraitURL() != "" {
			portrait = pc.ch.PortraitURL()
		}
		charOut = append(charOut, map[string]any{
			"character_id": pc.ch.CharacterID, "character_name": pc.ch.Name,
			"portrait_url": portrait, "planets": pc.planets,
		})
	}
	type outAgg struct {
		typeID  int
		name    string
		perHour float64
		planets int
	}
	agg := map[int]*outAgg{}
	var alerts []any
	var totalPlanets, totalExt, totalFac int
	var planetsFlat []map[string]any
	for _, c := range charOut {
		cm := c.(map[string]any)
		for _, pv := range cm["planets"].([]any) {
			pm := pv.(map[string]any)
			planetsFlat = append(planetsFlat, map[string]any{
				"character_name": cm["character_name"], "planet_name": pm["planet_name"],
				"planet_type": pm["planet_type"], "summary": pm["summary"],
			})
			totalPlanets++
			sum := pm["summary"].(map[string]any)
			totalExt += sum["extractor_count"].(int)
			totalFac += sum["factory_count"].(int)
			for _, ov := range pm["outputs"].([]any) {
				o := ov.(map[string]any)
				tid := o["type_id"].(int)
				a := agg[tid]
				if a == nil {
					a = &outAgg{typeID: tid, name: o["name"].(string)}
					agg[tid] = a
				}
				a.perHour += o["per_hour"].(float64)
				a.planets++
			}
			for _, av := range pm["alerts"].([]any) {
				a := av.(map[string]any)
				a["character_name"] = cm["character_name"]
				a["planet_name"] = pm["planet_name"]
				alerts = append(alerts, a)
			}
		}
	}
	if alerts == nil {
		alerts = []any{}
	}
	outputs := make([]any, 0, len(agg))
	for _, a := range agg {
		outputs = append(outputs, map[string]any{
			"type_id": a.typeID, "name": a.name,
			"icon_url": "https://images.evetech.net/types/" + itoaProg(a.typeID) + "/icon?size=32",
			"per_hour": a.perHour, "planets": a.planets,
		})
	}
	sort.Slice(outputs, func(i, j int) bool {
		return outputs[i].(map[string]any)["per_hour"].(float64) > outputs[j].(map[string]any)["per_hour"].(float64)
	})
	return map[string]any{
		"summary": map[string]any{
			"characters": len(charOut), "planets": totalPlanets,
			"extractors": totalExt, "factories": totalFac,
		},
		"outputs": outputs, "alerts": alerts,
		"capacity":   pi.BuildCapacityAnalysis(planetsFlat),
		"characters": charOut,
	}, nil
}

// planetaryAnalyze ports fetchCharacterPlanetaryData's per-planet block.
func planetaryAnalyze(ctx context.Context, d Deps, ch *auth.Character, colony, details map[string]any, resolver pi.TypeResolver, now time.Time) any {
	pid := piInt(colony["planet_id"])
	name := "Planet " + itoaProg(pid)
	if info, err := d.Planetary.Universe.GetPlanetInfo(ctx, pid); err == nil && info != nil {
		if n, ok := info["name"].(string); ok && n != "" {
			name = n
		}
	}
	analysis := pi.AnalyzePlanet(details, resolver, func(id int) map[string]any {
		s, err := d.Planetary.Universe.GetPlanetarySchematic(ctx, id)
		if err != nil {
			return nil
		}
		return s
	}, now)
	extractors := []any{}
	factories := []any{}
	for _, pv := range analysis.Pins {
		p := pv.(map[string]any)
		if e, ok := p["extractor"]; ok {
			extractors = append(extractors, e)
		}
		if f, ok := p["factory"]; ok {
			ff := f.(map[string]any)
			factories = append(factories, map[string]any{
				"schematic_id": ff["schematic_id"], "schematic_name": ff["schematic_name"],
				"cycle_time": ff["cycle_time"],
			})
		}
	}
	outs := make([]any, 0, len(analysis.Outputs))
	for _, ov := range analysis.Outputs {
		o := ov.(map[string]any)
		outs = append(outs, map[string]any{
			"type_id": o["type_id"], "name": o["name"],
			"icon_url": "https://images.evetech.net/types/" + itoaProg(o["type_id"].(int)) + "/icon?size=32",
			"per_hour": o["per_hour"], "source": o["source"],
		})
	}
	summary := map[string]any{
		"extractor_count": analysis.Summary["extractor_count"],
		"factory_count":   analysis.Summary["factory_count"],
		"storage_count":   analysis.Summary["storage_count"],
		"launchpad_count": analysis.Summary["launchpad_count"],
		"route_count":     len(analysis.Routes),
	}
	return map[string]any{
		"planet_id": pid, "planet_name": name,
		"planet_type":   strOrColony(colony["planet_type"], "unknown"),
		"upgrade_level": piInt(colony["upgrade_level"]), "num_pins": piInt(colony["num_pins"]),
		"summary": summary, "alerts": stripPlanetAlertContext(analysis.Alerts),
		"outputs": outs, "extractors": extractors, "factories": factories,
		"pins": analysis.Pins, "routes": analysis.Routes,
	}
}

// piBatchResolver collects every type id across the raw colonies and
// batch-resolves names (industry store, ESI fallback) and volumes.
func piBatchResolver(ctx context.Context, d Deps, raw []colonyRaw) pi.TypeResolver {
	set := map[int]bool{}
	var pinIDs, contentIDs []int
	for _, r := range raw {
		for _, pv := range piListOf(r.details["pins"]) {
			p, _ := pv.(map[string]any)
			if p == nil {
				continue
			}
			if id := piInt(p["type_id"]); id != 0 && !set[id] {
				set[id] = true
				pinIDs = append(pinIDs, id)
			}
			if ext, ok := p["extractor_details"].(map[string]any); ok {
				if id := piInt(ext["product_type_id"]); id != 0 && !set[id] {
					set[id] = true
					pinIDs = append(pinIDs, id)
				}
			}
			for _, cv := range piListOf(p["contents"]) {
				c, _ := cv.(map[string]any)
				if c == nil {
					continue
				}
				if id := piInt(c["type_id"]); id != 0 && !set[id] {
					set[id] = true
					contentIDs = append(contentIDs, id)
				}
			}
		}
		for _, rv := range piListOf(r.details["routes"]) {
			route, _ := rv.(map[string]any)
			if route == nil {
				continue
			}
			if id := piInt(route["content_type_id"]); id != 0 && !set[id] {
				set[id] = true
				contentIDs = append(contentIDs, id)
			}
		}
	}
	names := map[int]string{}
	if len(pinIDs) > 0 {
		if n, err := d.Industry.TypeNames(ctx, pinIDs); err == nil {
			for id, name := range n {
				names[id] = name
			}
		}
	}
	all := append(append([]int{}, pinIDs...), contentIDs...)
	vols := map[int]float64{}
	if len(all) > 0 {
		if v, err := d.Industry.TypeVolumes(ctx, all); err == nil {
			for id, vol := range v {
				vols[id] = vol
			}
		}
	}
	// ESI fallback for names the DB lacks.
	var missing []int
	for _, id := range all {
		if names[id] == "" {
			missing = append(missing, id)
		}
	}
	for _, id := range missing {
		if info, err := d.Planetary.Universe.GetTypeInfo(ctx, id); err == nil && info != nil {
			if n, ok := info["name"].(string); ok && n != "" {
				names[id] = n
			}
			if v, ok := asNumProg(info["volume"]); ok && v > 0 && vols[id] == 0 {
				vols[id] = v
			}
		}
		if names[id] == "" {
			names[id] = "Type " + itoaProg(id)
		}
	}
	return pi.TypeResolver{Names: names, Volumes: vols}
}

func piListOf(v any) []any {
	if l, ok := v.([]any); ok {
		return l
	}
	return []any{}
}

// POST /planetary/refresh — forget colony memos, 204.
func handlePlanetaryRefresh(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		uid := c.Get(string(ctxUIDKey)).(int64)
		chars, err := d.Users.CharactersForUser(c.Request().Context(), uid)
		if err != nil {
			return dbError(c, err)
		}
		for _, ch := range chars {
			d.Planetary.Characters.ClearPlanetaryCache(ch.CharacterID)
		}
		return c.NoContent(http.StatusNoContent)
	}
}

// piResolver builds the type resolver from industry stores + ESI fallback.
func piResolver(ctx context.Context, d Deps) pi.TypeResolver {
	return pi.TypeResolver{
		Names:   piNames(ctx, d),
		Volumes: piVolumes(ctx, d),
	}
}

func piNames(ctx context.Context, d Deps) map[int]string {
	return map[int]string{}
}

func piVolumes(ctx context.Context, d Deps) map[int]float64 {
	return map[int]float64{}
}

func piInt(v any) int {
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

func strOrColony(v any, def string) string {
	if s, ok := v.(string); ok && s != "" {
		return s
	}
	return def
}

func stripPlanetAlertContext(alerts []any) []any {
	out := make([]any, 0, len(alerts))
	for _, av := range alerts {
		a := av.(map[string]any)
		out = append(out, map[string]any{
			"type": a["type"], "severity": a["severity"], "message": a["message"],
			"pin_id": a["pin_id"],
		})
	}
	return out
}

var errColonyIncomplete = errors.New("planetary colony refresh did not complete")
