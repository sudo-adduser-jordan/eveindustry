package http

import (
	"net/http"
	"sort"
	"strings"

	"github.com/labstack/echo/v4"

	"github.com/sudo-adduser-jordan/eveindustry/internal/assets"
	"github.com/sudo-adduser-jordan/eveindustry/internal/calc"
	"github.com/sudo-adduser-jordan/eveindustry/internal/store"
)

// registerAssetExtras mounts materials, ships and the stock-manager type search.
func registerAssetExtras(v1 *echo.Group, d Deps) {
	if d.AssetBrowser.Characters == nil || d.Users == nil || d.Industry == nil || d.Market == nil || d.Reference == nil {
		return
	}
	v1.GET("/assets/materials", handleAssetMaterials(d))
	v1.GET("/assets/ships", handleAssetShips(d))
	v1.GET("/stock/search", handleStockSearch(d), requireStockManager)
}

// GET /assets/materials — mineral/PI/material stacks summed per type and
// grouped, priced at the Forge market. Corporate assets included only when
// the main may fetch them (else meta.corporation_assets_warning explains).
func handleAssetMaterials(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		uid := c.Get(string(ctxUIDKey)).(int64)
		ctx := c.Request().Context()
		chars, err := d.Users.CharactersForUser(ctx, uid)
		if err != nil {
			return dbError(c, err)
		}
		corpOK := assets.CorpDenial(chars) == nil
		var warning any
		if !corpOK {
			var mainName string
			for _, ch := range chars {
				if ch.IsMain {
					mainName = ch.Name
					break
				}
			}
			if mainName == "" {
				warning = "Corporation assets are not included: no main character is set."
			} else {
				warning = "Corporation assets are not included: main character " + mainName + " cannot fetch them."
			}
		}
		all, filled := cachedOrLiveAssets(ctx, d, uid, chars, corpOK)
		typeIDs := map[int]bool{}
		for _, a := range all {
			if a.TypeID > 0 {
				typeIDs[a.TypeID] = true
			}
		}
		ids := assetKeysInt(typeIDs)
		groups, err := d.Industry.GroupsOf(ctx, ids)
		if err != nil {
			return dbError(c, err)
		}
		byGroup := map[string]map[int]*matAgg{}
		var order []string
		seenGroup := map[string]bool{}
		typeNames := map[int]string{}
		for _, a := range all {
			gid := groups[a.TypeID]
			if gid == 0 {
				gid = calc.FallbackMaterialGroups[a.TypeID]
			}
			gname, ok := calc.MaterialGroups[gid]
			if !ok {
				continue
			}
			if byGroup[gname] == nil {
				byGroup[gname] = map[int]*matAgg{}
			}
			if _, ok := byGroup[gname][a.TypeID]; !ok {
				byGroup[gname][a.TypeID] = &matAgg{typeID: a.TypeID, typeName: a.TypeName}
			}
			byGroup[gname][a.TypeID].qty += a.Quantity
			typeNames[a.TypeID] = a.TypeName
			if !seenGroup[gname] {
				seenGroup[gname] = true
				order = append(order, gname)
			}
		}
		var priceIDs []int
		for _, items := range byGroup {
			for id := range items {
				priceIDs = append(priceIDs, id)
			}
		}
		prices := map[int]float64{}
		if len(priceIDs) > 0 {
			if resolved, err := resolvePrices(ctx, d.Market, priceIDs, 10000002); err == nil {
				for id, r := range resolved {
					prices[id] = r.Price
				}
			}
		}
		sort.Strings(order)
		var grand float64
		groupsOut := make([]any, 0, len(order))
		for _, gname := range order {
			items := byGroup[gname]
			list := make([]*matAgg, 0, len(items))
			for _, m := range items {
				list = append(list, m)
			}
			sort.Slice(list, func(i, j int) bool {
				return strings.ToLower(list[i].typeName) < strings.ToLower(list[j].typeName)
			})
			var total float64
			rows := make([]any, 0, len(list))
			for _, m := range list {
				unit := prices[m.typeID]
				tv := unit * float64(m.qty)
				total += tv
				rows = append(rows, map[string]any{
					"type_id": m.typeID, "type_name": m.typeName, "quantity": m.qty,
					"unit_price": unit, "total_value": tv,
				})
			}
			grand += total
			groupsOut = append(groupsOut, map[string]any{
				"name": gname, "items": rows, "total": total,
			})
		}
		return Success(c, http.StatusOK, map[string]any{
			"grand_total": grand, "groups": groupsOut,
		}, map[string]any{
			"last_updated": lastUpdated(filled), "corporation_assets_warning": warning,
		})
	}
}

type matAgg struct {
	typeID   int
	typeName string
	qty      int64
}

// GET /assets/ships — hulls grouped by ship group, sorted by name.
func handleAssetShips(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		uid := c.Get(string(ctxUIDKey)).(int64)
		ctx := c.Request().Context()
		chars, err := d.Users.CharactersForUser(ctx, uid)
		if err != nil {
			return dbError(c, err)
		}
		corpOK := assets.CorpDenial(chars) == nil
		all, filled := cachedOrLiveAssets(ctx, d, uid, chars, corpOK)
		typeIDs := map[int]bool{}
		for _, a := range all {
			if a.TypeID > 0 {
				typeIDs[a.TypeID] = true
			}
		}
		ids := assetKeysInt(typeIDs)
		groupOf, err := d.Industry.GroupsOf(ctx, ids)
		if err != nil {
			return dbError(c, err)
		}
		shipGroups, err := d.Industry.GroupsInCategory(ctx, 6)
		if err != nil {
			return dbError(c, err)
		}
		inShip := map[int]bool{}
		for _, g := range shipGroups {
			inShip[g] = true
		}
		gids := map[int]bool{}
		for _, g := range groupOf {
			if inShip[g] {
				gids[g] = true
			}
		}
		names, err := d.Industry.GroupNames(ctx, assetKeysInt(gids))
		if err != nil {
			return dbError(c, err)
		}
		if len(names) == 0 {
			return Success(c, http.StatusOK, map[string]any{
				"total_ships": 0, "type_count": 0, "ship_groups": []any{},
			}, map[string]any{"last_updated": lastUpdated(filled)})
		}
		grouped := map[string]*shipGroup{}
		var total int
		for _, a := range all {
			gid := groupOf[a.TypeID]
			gname, ok := names[gid]
			if !ok {
				continue
			}
			if grouped[gname] == nil {
				grouped[gname] = &shipGroup{name: gname}
			}
			grouped[gname].ships = append(grouped[gname].ships, a)
			total++
		}
		var order []string
		for name := range grouped {
			order = append(order, name)
		}
		sort.Strings(order)
		out := make([]any, 0, len(order))
		for _, name := range order {
			g := grouped[name]
			sort.SliceStable(g.ships, func(i, j int) bool {
				if c := strings.Compare(strings.ToLower(g.ships[i].TypeName), strings.ToLower(g.ships[j].TypeName)); c != 0 {
					return c < 0
				}
				return strings.ToLower(strOrEmpty(g.ships[i].Name)) < strings.ToLower(strOrEmpty(g.ships[j].Name))
			})
			ships := make([]any, 0, len(g.ships))
			for _, s := range g.ships {
				ships = append(ships, assetNodeShape(assetNode{enrichedAsset: s}))
			}
			out = append(out, map[string]any{
				"group_name": name, "count": len(g.ships), "ships": ships,
			})
		}
		return Success(c, http.StatusOK, map[string]any{
			"total_ships": total, "type_count": len(order), "ship_groups": out,
		}, map[string]any{"last_updated": lastUpdated(filled)})
	}
}

type shipGroup struct {
	name  string
	ships []enrichedAsset
}

// GET /stock/search — published types matching ?q (max 20), stock managers only.
func handleStockSearch(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		q := firstParam(c.QueryParams()["q"])
		if strings.TrimSpace(q) == "" || len([]rune(q)) > 100 {
			verrs := validationErrors{}
			verrs.add("q", "The q field is required.")
			return verrs.invalid(c)
		}
		types, err := d.Reference.SearchTypes(c.Request().Context(), strings.TrimSpace(q), 20, nil)
		if err != nil {
			return dbError(c, err)
		}
		if types == nil {
			types = []store.EveType{}
		}
		sort.Slice(types, func(i, j int) bool { return types[i].Name < types[j].Name })
		if len(types) > 20 {
			types = types[:20]
		}
		items := make([]any, 0, len(types))
		for _, t := range types {
			items = append(items, map[string]any{
				"type_id": t.TypeID, "name": t.Name,
				"icon_url": "https://images.evetech.net/types/" + itoaProg(t.TypeID) + "/icon?size=32",
			})
		}
		return Success(c, http.StatusOK, items, map[string]any{"limit": 20})
	}
}

func assetKeysInt(m map[int]bool) []int {
	out := make([]int, 0, len(m))
	for k := range m {
		out = append(out, k)
	}
	return out
}
