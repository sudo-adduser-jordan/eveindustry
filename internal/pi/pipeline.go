package pi

import (
	"context"
	"errors"
	"time"

	"github.com/sudo-adduser-jordan/eveindustry/internal/auth"
)

type ColonyCharacters interface {
	GetCharacterPlanets(ctx context.Context, characterID int64) ([]map[string]any, error)
	GetCharacterPlanetDetails(ctx context.Context, characterID int64, planetID int) (map[string]any, error)
	ClearPlanetaryCache(characterID int64)
}

type ColonyUniverse interface {
	GetPlanetarySchematic(ctx context.Context, schematicID int) (map[string]any, error)
	GetPlanetInfo(ctx context.Context, planetID int) (map[string]any, error)
	GetTypeInfo(ctx context.Context, typeID int) (map[string]any, error)
}

type ColonyIndustry interface {
	TypeNames(ctx context.Context, ids []int) (map[int]string, error)
	TypeVolumes(ctx context.Context, ids []int) (map[int]float64, error)
}

type ColonyDeps struct {
	Characters ColonyCharacters
	Universe   ColonyUniverse
	Industry   ColonyIndustry
}

type Colony struct {
	Char     auth.Character
	PlanetID int
	Name     string
	Type     string
	Upgrade  int
	NumPins  int
	Analysis PlanetAnalysis
}

var errColonyIncomplete = errors.New("planetary colony refresh did not complete")

type rawColony struct {
	ch      *auth.Character
	colony  map[string]any
	details map[string]any
}

func AnalyzeColonies(ctx context.Context, d ColonyDeps, chars []auth.Character, now time.Time) ([]Colony, error) {
	var raw []rawColony
	for i := range chars {
		ch := &chars[i]
		if !ch.IsEsiActive {
			continue
		}
		scoped := false
		for _, s := range ch.Scopes {
			if s == "esi-planets.manage_planets.v1" {
				scoped = true
				break
			}
		}
		if !scoped {
			continue
		}
		planets, err := d.Characters.GetCharacterPlanets(ctx, ch.CharacterID)
		if err != nil {
			return nil, err
		}
		if planets == nil {
			continue
		}
		for _, colony := range planets {
			pid := IntOf(colony["planet_id"])
			if pid == 0 {
				continue
			}
			details, err := d.Characters.GetCharacterPlanetDetails(ctx, ch.CharacterID, pid)
			if err != nil {
				return nil, err
			}
			if details == nil {
				return nil, errColonyIncomplete
			}
			raw = append(raw, rawColony{ch: ch, colony: colony, details: details})
		}
	}
	resolver := batchResolver(ctx, d, raw)
	out := make([]Colony, 0, len(raw))
	for _, r := range raw {
		pid := IntOf(r.colony["planet_id"])
		name := "Planet " + Itoa(pid)
		if info, err := d.Universe.GetPlanetInfo(ctx, pid); err == nil && info != nil {
			if n, ok := info["name"].(string); ok && n != "" {
				name = n
			}
		}
		analysis := AnalyzePlanet(r.details, resolver, func(id int) map[string]any {
			s, err := d.Universe.GetPlanetarySchematic(ctx, id)
			if err != nil {
				return nil
			}
			return s
		}, now)
		out = append(out, Colony{
			Char: *r.ch, PlanetID: pid, Name: name,
			Type:    StrOf(r.colony["planet_type"], "unknown"),
			Upgrade: IntOf(r.colony["upgrade_level"]), NumPins: IntOf(r.colony["num_pins"]),
			Analysis: analysis,
		})
	}
	return out, nil
}

func batchResolver(ctx context.Context, d ColonyDeps, raw []rawColony) TypeResolver {
	set := map[int]bool{}
	var pinIDs, contentIDs []int
	for _, r := range raw {
		for _, pv := range listOf(r.details["pins"]) {
			p, _ := pv.(map[string]any)
			if p == nil {
				continue
			}
			if id := IntOf(p["type_id"]); id != 0 && !set[id] {
				set[id] = true
				pinIDs = append(pinIDs, id)
			}
			if ext, ok := p["extractor_details"].(map[string]any); ok {
				if id := IntOf(ext["product_type_id"]); id != 0 && !set[id] {
					set[id] = true
					pinIDs = append(pinIDs, id)
				}
			}
			for _, cv := range listOf(p["contents"]) {
				c, _ := cv.(map[string]any)
				if c == nil {
					continue
				}
				if id := IntOf(c["type_id"]); id != 0 && !set[id] {
					set[id] = true
					contentIDs = append(contentIDs, id)
				}
			}
		}
		for _, rv := range listOf(r.details["routes"]) {
			route, _ := rv.(map[string]any)
			if route == nil {
				continue
			}
			if id := IntOf(route["content_type_id"]); id != 0 && !set[id] {
				set[id] = true
				contentIDs = append(contentIDs, id)
			}
		}
	}
	names := map[int]string{}
	if len(pinIDs) > 0 && d.Industry != nil {
		if n, err := d.Industry.TypeNames(ctx, pinIDs); err == nil {
			for id, name := range n {
				names[id] = name
			}
		}
	}
	all := append(append([]int{}, pinIDs...), contentIDs...)
	vols := map[int]float64{}
	if len(all) > 0 && d.Industry != nil {
		if v, err := d.Industry.TypeVolumes(ctx, all); err == nil {
			for id, vol := range v {
				vols[id] = vol
			}
		}
	}
	var missing []int
	for _, id := range all {
		if names[id] == "" {
			missing = append(missing, id)
		}
	}
	for _, id := range missing {
		if d.Universe == nil {
			break
		}
		if info, err := d.Universe.GetTypeInfo(ctx, id); err == nil && info != nil {
			if n, ok := info["name"].(string); ok && n != "" {
				names[id] = n
			}
			if vol, ok := numOf(info["volume"]); ok && vol > 0 && vols[id] == 0 {
				vols[id] = vol
			}
		}
		if names[id] == "" {
			names[id] = "Type " + Itoa(id)
		}
	}
	return TypeResolver{Names: names, Volumes: vols}
}

func IntOf(v any) int {
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

func StrOf(v any, def string) string {
	if s, ok := v.(string); ok && s != "" {
		return s
	}
	return def
}

func Itoa(i int) string {
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

func numOf(v any) (float64, bool) {
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
