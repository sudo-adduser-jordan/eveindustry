package http

import (
	"context"
	"fmt"
	"math"
	"net/http"
	"sort"
	"strings"

	"github.com/labstack/echo/v4"

	"github.com/sudo-adduser-jordan/eveindustry/internal/auth"
)

// AbyssalESI fetches mutated-item data (CharacterClient satisfies it).
type AbyssalESI interface {
	GetDynamicItem(ctx context.Context, typeID int, itemID int64, characterID int64) (map[string]any, error)
}

var abyssalSkipIDs = map[int]bool{
	422: true, 277: true, 182: true, 278: true, 183: true,
	279: true, 633: true, 1795: true,
}

var abyssalMillisecondNames = map[string]bool{"duration": true, "speed": true, "reloadTime": true}
var abyssalCubicMeterNames = map[string]bool{"miningAmount": true}

// AbyssalUniverse is UniverseReader plus the dogma lookups.
type AbyssalUniverse interface {
	UniverseReader
	DogmaReader
}

// AbyssalDeps reuses the asset browser ESI surface plus dogma lookups.
type AbyssalDeps struct {
	Characters AssetBrowserESI
	Reference  ProgressReference
	Universe   AbyssalUniverse
	Dogma      AbyssalESI
}

// registerAbyssal mounts the mutated-module browser.
func registerAbyssal(v1 *echo.Group, d Deps) {
	if d.Abyssal.Characters == nil || d.Abyssal.Universe == nil || d.Abyssal.Dogma == nil || d.Users == nil || d.Industry == nil {
		return
	}
	v1.GET("/assets/abyssal", handleAbyssal(d))
}

// GET /assets/abyssal — mutated modules grouped by base type, ranked by roll
// quality. Slow on a cold cache: it asks ESI about every item.
func handleAbyssal(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		uid := c.Get(string(ctxUIDKey)).(int64)
		ctx := c.Request().Context()
		chars, err := d.Users.CharactersForUser(ctx, uid)
		if err != nil {
			return dbError(c, err)
		}
		ad := d
		ad.AssetBrowser = AssetBrowserDeps{Characters: d.Abyssal.Characters, Universe: d.Abyssal.Universe, Reference: d.Abyssal.Reference}
		all := fetchUserAssets(ctx, ad, chars, true)
		typeIDs := map[int]bool{}
		for _, a := range all {
			if a.typeID > 0 {
				typeIDs[a.typeID] = true
			}
		}
		ids := keysInt(typeIDs)
		abyssal := map[int]bool{}
		if len(ids) > 0 {
			if meta, err := d.Industry.TypeGroupMeta(ctx, ids); err == nil {
				for _, m := range meta {
					if m.MetaGroupID.Valid && m.MetaGroupID.Int64 == 15 {
						abyssal[m.TypeID] = true
					}
				}
			}
		}
		if len(abyssal) == 0 {
			return Success(c, http.StatusOK, []any{}, map[string]any{"last_updated": nil})
		}
		var scoped []auth.Character
		for _, ch := range chars {
			if hasScope(ch, "esi-assets.read_assets.v1") {
				scoped = append(scoped, ch)
			}
		}
		if len(scoped) == 0 {
			return Success(c, http.StatusOK, []any{}, map[string]any{"last_updated": nil})
		}
		byID := map[int64]enrichedAsset{}
		for _, a := range all {
			byID[a.itemID] = a
		}
		groups := map[int]*abyssalGroup{}
		for _, a := range all {
			if !abyssal[a.typeID] || a.itemID == 0 {
				continue
			}
			owner := scoped[0]
			for _, ch := range scoped {
				if ch.CharacterID == a.charID {
					owner = ch
					break
				}
			}
			dyn, err := d.Abyssal.Dogma.GetDynamicItem(ctx, a.typeID, a.itemID, owner.CharacterID)
			if err != nil || dyn == nil {
				continue
			}
			srcID := intOfAbyss(dyn["source_type_id"])
			if srcID == 0 {
				continue
			}
			item := analyzeAbyssalItem(ctx, d.Abyssal.Universe, a, dyn, srcID, byID)
			if item == nil {
				continue
			}
			g := groups[srcID]
			if g == nil {
				g = &abyssalGroup{sourceID: srcID, sourceName: resolveAssetTypeName(ctx, ad, srcID)}
				groups[srcID] = g
			}
			g.items = append(g.items, *item)
		}
		out := make([]any, 0, len(groups))
		for _, g := range groups {
			sort.Slice(g.items, func(i, j int) bool { return g.items[i].score > g.items[j].score })
			items := make([]any, 0, len(g.items))
			for _, it := range g.items {
				items = append(items, it.shape())
			}
			out = append(out, map[string]any{
				"source_type_id": g.sourceID, "source_type_name": g.sourceName, "items": items,
			})
		}
		sort.Slice(out, func(i, j int) bool {
			return strings.ToLower(out[i].(map[string]any)["source_type_name"].(string)) <
				strings.ToLower(out[j].(map[string]any)["source_type_name"].(string))
		})
		return Success(c, http.StatusOK, out, map[string]any{"last_updated": nil})
	}
}

type abyssalGroup struct {
	sourceID   int
	sourceName string
	items      []abyssalItem
}

type abyssCmp struct {
	id       int
	name     string
	unit     *int
	icon     *int
	value    float64
	base     float64
	pct      *float64
	highGood bool
	isGood   bool
}

type abyssalItem struct {
	itemID    int64
	typeID    int
	typeName  string
	charID    int64
	charName  string
	location  string
	container *string
	mutator   *int
	score     float64
	attrs     []any
}

func (it abyssalItem) shape() map[string]any {
	return map[string]any{
		"item_id": it.itemID, "type_id": it.typeID, "type_name": it.typeName,
		"character_id": it.charID, "character_name": it.charName,
		"location_name": it.location, "container_name": it.container,
		"mutator_type_id": it.mutator, "score": math.Round(it.score*10) / 10,
		"attributes": it.attrs,
	}
}

// analyzeAbyssalItem ports the per-item dogma comparison.
func analyzeAbyssalItem(ctx context.Context, uni AbyssalUniverse, a enrichedAsset, dyn map[string]any, srcID int, byID map[int64]enrichedAsset) *abyssalItem {
	base, err := uni.DogmaTypeAttributes(ctx, srcID)
	if err != nil {
		return nil
	}
	dynAttrs := map[int]float64{}
	if list, ok := dyn["dogma_attributes"].([]any); ok {
		for _, item := range list {
			m, ok := item.(map[string]any)
			if !ok {
				continue
			}
			id, _ := asNumProg(m["attribute_id"])
			val, _ := asNumProg(m["value"])
			if id != 0 {
				dynAttrs[int(id)] = val
			}
		}
	}
	var order []int
	byAttr := map[int]*abyssCmp{}
	score := 0.0
	attrMeta := func(id int) map[string]any {
		m, err := uni.GetDogmaAttribute(ctx, id)
		if err != nil {
			return nil
		}
		return m
	}
	for id, val := range dynAttrs {
		if abyssalSkipIDs[id] {
			continue
		}
		baseVal, ok := base[id]
		if !ok || math.Abs(val-baseVal) < 0.0001 {
			continue
		}
		meta := attrMeta(id)
		highGood := false
		var unit, icon *int
		var rawName string
		if meta != nil {
			if h, ok := meta["high_is_good"].(bool); ok {
				highGood = h
			}
			if u, ok := asNumProg(meta["unit_id"]); ok {
				v := int(u)
				unit = &v
			}
			if ic, ok := asNumProg(meta["icon_id"]); ok {
				v := int(ic)
				icon = &v
			}
			rawName, _ = meta["name"].(string)
		}
		if abyssalMillisecondNames[rawName] {
			v := 115
			unit = &v
		}
		if abyssalCubicMeterNames[rawName] {
			v := 120
			unit = &v
		}
		var pct *float64
		if baseVal != 0 {
			p := (val - baseVal) / math.Abs(baseVal) * 100
			pct = &p
			if pct != nil {
				if highGood && val >= baseVal || !highGood && val <= baseVal {
					score += math.Abs(*pct)
				} else {
					score -= math.Abs(*pct)
				}
			}
		} else if highGood == (val >= baseVal) {
			// zero base: direction only
		}
		name := "Attribute #" + itoaProg(id)
		if meta != nil {
			if dn, ok := meta["display_name"].(string); ok && dn != "" {
				name = dn
			} else if n, ok := meta["name"].(string); ok && n != "" {
				name = n
			}
		}
		c := &abyssCmp{id: id, name: name, unit: unit, icon: icon, value: val, base: baseVal, pct: pct, highGood: highGood}
		c.isGood = (highGood && val >= baseVal) || (!highGood && val <= baseVal)
		byAttr[id] = c
		order = append(order, id)
	}
	// effective mining speed
	mining := findAbyssMining(byAttr, dynAttrs, attrMeta, base)
	duration := findAbyssDuration(byAttr, dynAttrs, attrMeta, base)
	if mining != nil && duration != nil && duration.value > 0 && duration.base > 0 {
		eff := mining.value / (duration.value / 1000)
		effBase := mining.base / (duration.base / 1000)
		var pct *float64
		if effBase != 0 {
			p := (eff - effBase) / math.Abs(effBase) * 100
			pct = &p
		}
		u := 9001
		byAttr[-1] = &abyssCmp{id: -1, name: "Effective Mining Speed", unit: &u, value: eff, base: effBase, pct: pct, highGood: true, isGood: eff >= effBase}
		order = append(order, -1)
	}
	attrs := make([]any, 0, len(order))
	for _, id := range order {
		c := byAttr[id]
		var unitID any
		if c.unit != nil {
			unitID = *c.unit
		}
		var iconID any
		if c.icon != nil {
			iconID = *c.icon
		}
		var pct any
		if c.pct != nil {
			p := math.Round(*c.pct*100) / 100
			pct = p
		}
		var attrID any = c.id
		if c.id == -1 {
			attrID = "_effective_m3s"
		}
		attrs = append(attrs, map[string]any{
			"attribute_id": attrID, "name": c.name, "unit_id": unitID, "icon_id": iconID,
			"value": abyssFormat(c.value, c.unit), "base_value": abyssFormat(c.base, c.unit),
			"change": abyssChange(c.value, c.base, c.unit),
			"pct_change": pct, "high_is_good": c.highGood, "is_good": c.isGood,
		})
	}
	sort.Slice(attrs, func(i, j int) bool {
		return attrs[i].(map[string]any)["name"].(string) < attrs[j].(map[string]any)["name"].(string)
	})
	var container *string
	if a.parentID != nil {
		if p, ok := byID[*a.parentID]; ok {
			name := strOrNilAbyss(p.name)
			if name == "" {
				name = p.typeName
			}
			container = &name
		}
	}
	var mutator *int
	if m, ok := asNumProg(dyn["mutator_type_id"]); ok && m != 0 {
		v := int(m)
		mutator = &v
	}
	return &abyssalItem{
		itemID: a.itemID, typeID: a.typeID, typeName: a.typeName,
		charID: a.charID, charName: a.charName, location: a.location,
		container: container, mutator: mutator, score: score, attrs: attrs,
	}
}

type abyssVal struct {
	value float64
	base  float64
}

func findAbyssMining(byAttr map[int]*abyssCmp, dynAttrs map[int]float64, meta func(int) map[string]any, base map[int]float64) *abyssVal {
	for _, c := range byAttr {
		u := -1
		if c.unit != nil {
			u = *c.unit
		}
		if u == 120 && strings.Contains(strings.ToLower(c.name), "mining") {
			return &abyssVal{value: c.value, base: c.base}
		}
	}
	for id, val := range dynAttrs {
		if _, ok := byAttr[id]; ok {
			continue
		}
		m := meta(id)
		if m == nil {
			continue
		}
		raw, _ := m["name"].(string)
		uid := -1
		if u, ok := asNumProg(m["unit_id"]); ok {
			uid = int(u)
		}
		if uid == 120 || abyssalCubicMeterNames[raw] {
			if b, ok := base[id]; ok {
				return &abyssVal{value: val, base: b}
			}
		}
	}
	return nil
}

func findAbyssDuration(byAttr map[int]*abyssCmp, dynAttrs map[int]float64, meta func(int) map[string]any, base map[int]float64) *abyssVal {
	for _, c := range byAttr {
		u := -1
		if c.unit != nil {
			u = *c.unit
		}
		if u == 115 {
			return &abyssVal{value: c.value, base: c.base}
		}
	}
	for id, val := range dynAttrs {
		if _, ok := byAttr[id]; ok {
			continue
		}
		m := meta(id)
		if m == nil {
			continue
		}
		raw, _ := m["name"].(string)
		uid := -1
		if u, ok := asNumProg(m["unit_id"]); ok {
			uid = int(u)
		}
		if abyssalMillisecondNames[raw] || uid == 115 {
			if b, ok := base[id]; ok && b > 0 {
				return &abyssVal{value: val, base: b}
			}
		}
	}
	return nil
}

func strOrNilAbyss(s *string) string {
	if s == nil {
		return ""
	}
	return *s
}

func intOfAbyss(v any) int {
	n, _ := asNumProg(v)
	return int(n)
}

// abyssFormat ports formatAttributeValue.
func abyssFormat(value float64, unit *int) string {
	u := -1
	if unit != nil {
		u = *unit
	}
	switch u {
	case 104, 105, 106:
		return numFmt(value*100, 2) + "%"
	case 101:
		return numFmt((1-value)*100, 2) + "%"
	case 108:
		if value != 0 {
			return "true"
		}
		return "false"
	case 109, 118:
		return numFmt(value, 4) + "x"
	case 115:
		return numFmt(value/1000, 2) + "s"
	case 119:
		return numFmt(value, 2) + "s"
	case 120:
		return numFmt(value, 2) + " m³"
	case 121:
		return numFmt(value, 2) + " m/s"
	case 9:
		return numFmt(value, 2) + " tf"
	case 11, 30:
		return numFmt(value, 2) + " MW"
	case 113:
		return numFmt(value, 2) + " GJ"
	case 9001:
		return numFmt(value, 2) + " m³/s"
	case 117:
		if value >= 1000 {
			return numFmt(value/1000, 2) + " km"
		}
		return numFmt(value, 2) + " m"
	default:
		return numFmt(value, 2)
	}
}

// abyssChange ports formatAttributeChange.
func abyssChange(value, base float64, unit *int) string {
	u := -1
	if unit != nil {
		u = *unit
	}
	diff := value - base
	sign := "+"
	if diff < 0 {
		sign = ""
	}
	switch u {
	case 104, 105, 106:
		return sign + numFmt(diff*100, 2) + "%"
	case 101:
		dd := (base - value) * 100
		s := "+"
		if dd < 0 {
			s = ""
		}
		return s + numFmt(dd, 2) + "%"
	case 108:
		return ""
	case 109, 118:
		return sign + numFmt(diff, 4) + "x"
	case 115:
		return sign + numFmt(diff/1000, 2) + "s"
	case 119:
		return sign + numFmt(diff, 2) + "s"
	case 120:
		return sign + numFmt(diff, 2) + " m³"
	case 121:
		return sign + numFmt(diff, 2) + " m/s"
	case 9:
		return sign + numFmt(diff, 2) + " tf"
	case 11, 30:
		return sign + numFmt(diff, 2) + " MW"
	case 113:
		return sign + numFmt(diff, 2) + " GJ"
	case 9001:
		return sign + numFmt(diff, 2) + " m³/s"
	case 117:
		if math.Abs(diff) >= 1000 {
			return sign + numFmt(diff/1000, 2) + " km"
		}
		return sign + numFmt(diff, 2) + " m"
	default:
		return sign + numFmt(diff, 2)
	}
}

// numFmt mirrors PHP number_format with thousands separators: round to an
// integer count of the smallest unit first so float noise never leaks.
func numFmt(v float64, decimals int) string {
	neg := v < 0
	if neg {
		v = -v
	}
	pow := int64(1)
	for i := 0; i < decimals; i++ {
		pow *= 10
	}
	total := int64(math.Round(v * float64(pow)))
	intPart := total / pow
	fracPart := total % pow
	intStr := fmt.Sprintf("%d", intPart)
	var b strings.Builder
	for i, ch := range intStr {
		if i > 0 && (len(intStr)-i)%3 == 0 {
			b.WriteByte(',')
		}
		b.WriteRune(ch)
	}
	out := b.String()
	if decimals > 0 {
		frac := fmt.Sprintf("%d", fracPart)
		for len(frac) < decimals {
			frac = "0" + frac
		}
		out += "." + frac
	}
	if neg {
		out = "-" + out
	}
	return out
}
