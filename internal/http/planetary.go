package http

import (
	"context"
	"net/http"
	"sort"
	"time"

	"github.com/labstack/echo/v4"

	"github.com/sudo-adduser-jordan/eveindustry/internal/auth"
	"github.com/sudo-adduser-jordan/eveindustry/internal/pi"
)

type PlanetaryColonyESI interface {
	GetCharacterPlanets(ctx context.Context, characterID int64) ([]map[string]any, error)
	GetCharacterPlanetDetails(ctx context.Context, characterID int64, planetID int) (map[string]any, error)
	ClearPlanetaryCache(characterID int64)
}

type PlanetaryUniverseESI interface {
	GetPlanetarySchematic(ctx context.Context, schematicID int) (map[string]any, error)
	GetPlanetInfo(ctx context.Context, planetID int) (map[string]any, error)
	GetTypeInfo(ctx context.Context, typeID int) (map[string]any, error)
}

type PlanetaryDeps struct {
	Characters PlanetaryColonyESI
	Universe   PlanetaryUniverseESI
}

func registerPlanetary(v1 *echo.Group, d Deps) {
	if d.Planetary.Characters == nil || d.Planetary.Universe == nil || d.Users == nil || d.Industry == nil {
		return
	}
	v1.GET("/planetary", handlePlanetaryShow(d))
	v1.POST("/planetary/refresh", handlePlanetaryRefresh(d))
}

func handlePlanetaryShow(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		uid := c.Get(string(ctxUIDKey)).(int64)
		ctx := c.Request().Context()
		now := time.Now().UTC()
		if d.Cache != nil {
			var cached []any
			if d.Cache.GetJSON(ctx, "planetary.user."+itoaProg(int(uid)), &cached) {
				return Success(c, http.StatusOK, assemblePlanetary(cached), nil)
			}
		}
		chars, err := collectPlanetary(ctx, d, uid, now)
		if err != nil {
			return dbError(c, err)
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
		if d.Cache != nil {
			d.Cache.SetJSON(ctx, "planetary.user."+itoaProg(int(uid)), charOut, 1200*time.Second)
		}
		return Success(c, http.StatusOK, assemblePlanetary(charOut), nil)
	}
}

func assemblePlanetary(charOut []any) map[string]any {
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
		cm, ok := c.(map[string]any)
		if !ok {
			continue
		}
		plist, _ := cm["planets"].([]any)
		for _, pv := range plist {
			pm, ok := pv.(map[string]any)
			if !ok {
				continue
			}
			planetsFlat = append(planetsFlat, map[string]any{
				"character_name": cm["character_name"], "planet_name": pm["planet_name"],
				"planet_type": pm["planet_type"], "summary": map[string]any{
					"extractor_count": planetCount(pm, "extractor_count"),
					"factory_count":   planetCount(pm, "factory_count"),
				},
			})
			totalPlanets++
			totalExt += int(planetCount(pm, "extractor_count"))
			totalFac += int(planetCount(pm, "factory_count"))
			if ol, ok := pm["outputs"].([]any); ok {
				for _, ov := range ol {
					o, ok := ov.(map[string]any)
					if !ok {
						continue
					}
					tid := int(numAny(o["type_id"]))
					a := agg[tid]
					if a == nil {
						name, _ := o["name"].(string)
						a = &outAgg{typeID: tid, name: name}
						agg[tid] = a
					}
					a.perHour += numAny(o["per_hour"])
					a.planets++
				}
			}
			if al, ok := pm["alerts"].([]any); ok {
				for _, av := range al {
					if a, ok := av.(map[string]any); ok {
						a["character_name"] = cm["character_name"]
						a["planet_name"] = pm["planet_name"]
						alerts = append(alerts, a)
					}
				}
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
	}
}

func numAny(v any) float64 {
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

func planetCount(pm map[string]any, key string) float64 {
	if v, ok := pm[key]; ok && v != nil {
		return numAny(v)
	}
	if sum, ok := pm["summary"].(map[string]any); ok {
		return numAny(sum[key])
	}
	return 0
}

type planetaryChar struct {
	ch      *auth.Character
	planets []any
}

func collectPlanetary(ctx context.Context, d Deps, uid int64, now time.Time) ([]planetaryChar, error) {
	chars, err := d.Users.CharactersForUser(ctx, uid)
	if err != nil {
		return nil, err
	}
	colonies, err := pi.AnalyzeColonies(ctx, pi.ColonyDeps{
		Characters: d.Planetary.Characters, Universe: d.Planetary.Universe, Industry: d.Industry,
	}, chars, now)
	if err != nil {
		return nil, err
	}
	byChar := map[int64]int{}
	var out []planetaryChar
	for _, c := range colonies {
		i, ok := byChar[c.Char.CharacterID]
		if !ok {
			i = len(out)
			byChar[c.Char.CharacterID] = i
			ch := c.Char
			out = append(out, planetaryChar{ch: &ch})
		}
		out[i].planets = append(out[i].planets, shapeColony(c))
	}
	return out, nil
}

func typeIconURL(typeID int) string {
	return "https://images.evetech.net/types/" + itoaProg(typeID) + "/icon?size=32"
}

func shapeColony(c pi.Colony) any {
	extractors := []any{}
	factories := []any{}
	storages := []any{}
	for _, pv := range c.Analysis.Pins {
		p, _ := pv.(map[string]any)
		if p == nil {
			continue
		}
		if e, ok := p["extractor"].(map[string]any); ok {
			pid := int(numAny(e["product_type_id"]))
			extractors = append(extractors, map[string]any{
				"product_type_id": pid, "product_name": e["product_name"],
				"icon_url": typeIconURL(pid),
				"per_hour": e["per_hour"], "is_active": e["is_active"],
				"install_time": e["install_time"], "expiry_time": e["expiry_time"],
			})
		}
		if f, ok := p["factory"].(map[string]any); ok {
			factories = append(factories, map[string]any{
				"schematic_id": f["schematic_id"], "schematic_name": f["schematic_name"],
				"cycle_time": f["cycle_time"],
			})
		}
		if s, ok := p["storage"].(map[string]any); ok {
			kind := pi.PinStorage
			if cat, _ := p["category"].(string); cat == pi.PinLaunchpad {
				kind = pi.PinLaunchpad
			}
			items := []any{}
			if l, ok := s["items"].([]any); ok {
				for _, iv := range l {
					im, _ := iv.(map[string]any)
					if im == nil {
						continue
					}
					tid := int(numAny(im["type_id"]))
					items = append(items, map[string]any{
						"type_id": tid, "name": im["name"],
						"icon_url": typeIconURL(tid), "amount": im["amount"],
					})
				}
			}
			storages = append(storages, map[string]any{
				"kind": kind, "capacity": s["capacity"],
				"used_volume": s["used_volume"], "usage_percent": s["usage_percent"],
				"items": items,
			})
		}
	}
	outs := make([]any, 0, len(c.Analysis.Outputs))
	for _, ov := range c.Analysis.Outputs {
		o, _ := ov.(map[string]any)
		if o == nil {
			continue
		}
		tid := int(numAny(o["type_id"]))
		outs = append(outs, map[string]any{
			"type_id": tid, "name": o["name"],
			"icon_url": typeIconURL(tid),
			"per_hour": o["per_hour"],
		})
	}
	return map[string]any{
		"planet_id": c.PlanetID, "planet_name": c.Name,
		"planet_type": c.Type, "upgrade_level": c.Upgrade, "num_pins": c.NumPins,
		"extractor_count": c.Analysis.Summary["extractor_count"],
		"factory_count":   c.Analysis.Summary["factory_count"],
		"storage_count":   c.Analysis.Summary["storage_count"],
		"launchpad_count": c.Analysis.Summary["launchpad_count"],
		"route_count":     len(c.Analysis.Routes),
		"alerts":          stripPlanetAlertContext(c.Analysis.Alerts),
		"outputs":         outs, "extractors": extractors, "factories": factories,
		"storages": storages,
	}
}

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

func stripPlanetAlertContext(alerts []any) []any {
	out := make([]any, 0, len(alerts))
	for _, av := range alerts {
		a := av.(map[string]any)
		out = append(out, map[string]any{
			"type": a["type"], "severity": a["severity"], "message": a["message"],
		})
	}
	return out
}
