package http

import (
	"context"
	"net/http"
	"sort"
	"strings"
	"time"

	"github.com/labstack/echo/v4"

	"github.com/sudo-adduser-jordan/eveindustry/internal/assets"
	"github.com/sudo-adduser-jordan/eveindustry/internal/auth"
)

func registerAssets(v1 *echo.Group, d Deps) {
	if d.AssetBrowser.Characters == nil || d.Users == nil || d.Industry == nil {
		return
	}
	v1.GET("/assets", handleAssetsIndex(d, false))
	v1.GET("/corporation/assets", handleAssetsIndex(d, true))
	v1.GET("/assets/by-location", handleAssetsByLocation(d, false))
	v1.GET("/corporation/assets/by-location", handleAssetsByLocation(d, true))
	v1.POST("/assets/refresh", handleAssetsRefresh(d))
}

func cachedOrLiveAssets(ctx context.Context, d Deps, uid int64, chars []auth.Character, corpOK bool) ([]assets.Asset, *time.Time) {
	if d.Cache != nil {
		var cached []assets.Asset
		if d.Cache.GetJSON(ctx, "assets.user."+itoaProg(int(uid)), &cached) {
			var at int64
			var filled *time.Time
			if d.Cache.GetJSON(ctx, "assets.user."+itoaProg(int(uid))+".cached_at", &at) && at > 0 {
				t := time.Unix(at, 0).UTC()
				filled = &t
			}
			return cached, filled
		}
	}
	return assets.FetchUserAssets(ctx, assetDeps(d), chars, corpOK), nil
}

type enrichedAsset = assets.Asset

type AssetBrowserDeps struct {
	Characters assets.Characters
	Universe   UniverseReader
	Reference  ProgressReference
}

var assetSortFields = map[string]bool{
	"type_name": true, "quantity": true, "location_name": true,
	"character_name": true, "owner_type": true,
}

func assetDeps(d Deps) assets.Deps {
	return assets.Deps{
		Characters: d.AssetBrowser.Characters, Universe: d.AssetBrowser.Universe,
		Reference: assetRefAdapter{d.AssetBrowser.Reference}, Industry: d.Industry,
	}
}

type assetRefAdapter struct{ r ProgressReference }

func (a assetRefAdapter) FindStation(ctx context.Context, id uint64) (string, *int, bool) {
	if a.r == nil {
		return "", nil, false
	}
	st, err := a.r.FindStation(ctx, id)
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
	if a.r == nil {
		return "", false
	}
	st, err := a.r.FindSystem(ctx, id)
	if err != nil || st == nil {
		return "", false
	}
	return st.SolarSystemName, true
}

func warmDemoAssets(ctx context.Context, d Deps, uid int64) {
	if d.Cache == nil || d.Users == nil || d.Industry == nil || d.AssetBrowser.Characters == nil {
		return
	}
	chars, err := d.Users.CharactersForUser(ctx, uid)
	if err != nil || len(chars) == 0 {
		return
	}
	all := assets.FetchUserAssets(ctx, assetDeps(d), chars, true)
	d.Cache.SetJSON(ctx, "assets.user."+itoaProg(int(uid)), all, 0)
	d.Cache.SetJSON(ctx, "assets.user."+itoaProg(int(uid))+".cached_at", time.Now().Unix(), 0)
}

func handleAssetsIndex(d Deps, corporate bool) echo.HandlerFunc {
	return func(c echo.Context) error {
		qp := c.QueryParams()
		search := firstParam(qp["search"])
		if len([]rune(search)) > 100 {
			verrs := validationErrors{}
			verrs.add("search", "The search field must not be greater than 100 characters.")
			return verrs.invalid(c)
		}
		sortBy := firstParam(qp["sort"])
		if sortBy == "" {
			sortBy = "type_name"
		}
		if !assetSortFields[sortBy] {
			verrs := validationErrors{}
			verrs.add("sort", "The selected sort is invalid.")
			return verrs.invalid(c)
		}
		dir := firstParam(qp["dir"])
		if dir == "" {
			dir = "asc"
		}
		if dir != "asc" && dir != "desc" {
			verrs := validationErrors{}
			verrs.add("dir", "The selected dir is invalid.")
			return verrs.invalid(c)
		}
		includeChildren := queryBool(qp["include_children"], true)
		var parentID, locationID *int64
		if raw := firstParam(qp["parent_item_id"]); raw != "" {
			n, err := parseIntProg(raw)
			if err != nil || n < 1 {
				verrs := validationErrors{}
				verrs.add("parent_item_id", "The parent item id field must be an integer.")
				return verrs.invalid(c)
			}
			v := int64(n)
			parentID = &v
		}
		if raw := firstParam(qp["location_id"]); raw != "" {
			n, err := parseIntProg(raw)
			if err != nil || n < 1 {
				verrs := validationErrors{}
				verrs.add("location_id", "The location id field must be an integer.")
				return verrs.invalid(c)
			}
			v := int64(n)
			locationID = &v
		}
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
		if corporate {
			if denial := assets.CorpDenial(chars); denial != nil {
				return Failure(c, http.StatusForbidden, *denial, nil)
			}
		}
		all, filled := cachedOrLiveAssets(ctx, d, uid, chars, true)
		owner := "personal"
		if corporate {
			owner = "corporate"
		}
		var filtered []enrichedAsset
		for _, a := range all {
			if a.Owner != owner {
				continue
			}
			filtered = append(filtered, a)
		}
		tree := buildAssetTree(filtered, search, sortBy, dir)
		if parentID != nil {
			tree = findAssetChildren(tree, *parentID)
		} else if locationID != nil {
			kept := tree[:0]
			for _, t := range tree {
				if t.LocationID == *locationID {
					kept = append(kept, t)
				}
			}
			tree = kept
			if tree == nil {
				tree = []assetNode{}
			}
		}
		if !includeChildren {
			for i := range tree {
				tree[i].childrenCount = len(tree[i].children)
				tree[i].children = []assetNode{}
			}
		}
		items := make([]any, 0, len(tree))
		for _, t := range tree {
			items = append(items, assetNodeShape(t))
		}
		offset, pagination := paginate(len(items), page, perPage)
		end := offset + perPage
		if end > len(items) {
			end = len(items)
		}
		return Success(c, http.StatusOK, items[offset:end], map[string]any{
			"last_updated": lastUpdated(filled),
			"pagination":   pagination,
		})
	}
}

type assetNode struct {
	enrichedAsset
	children      []assetNode
	childrenCount int
}

func buildAssetTree(all []enrichedAsset, search, sortBy, dir string) []assetNode {
	byID := map[int64]*assetNode{}
	for _, a := range all {
		n := &assetNode{enrichedAsset: a}
		byID[a.ItemID] = n
	}
	var tree []*assetNode
	for _, n := range byID {
		if n.ParentID != nil {
			if p, ok := byID[*n.ParentID]; ok {
				p.children = append(p.children, *n)
				continue
			}
		}
		tree = append(tree, n)
	}
	out := make([]assetNode, 0, len(tree))
	for _, n := range tree {
		out = append(out, *n)
	}
	lower := strings.ToLower(search)
	if lower != "" {
		var kept []assetNode
		for _, n := range out {
			if assetMatches(n, lower) {
				kept = append(kept, n)
			} else {
				var kids []assetNode
				for _, k := range n.children {
					if assetMatches(k, lower) {
						kids = append(kids, k)
					}
				}
				if len(kids) > 0 {
					n.children = kids
					kept = append(kept, n)
				}
			}
		}
		out = kept
	}
	sortAssets(out, sortBy, dir)
	for i := range out {
		sortAssets(out[i].children, sortBy, dir)
	}
	if out == nil {
		out = []assetNode{}
	}
	return out
}

func assetMatches(n assetNode, lower string) bool {
	return strings.Contains(strings.ToLower(n.TypeName), lower) ||
		strings.Contains(strings.ToLower(strOrEmpty(n.Name)), lower) ||
		strings.Contains(strings.ToLower(n.Location), lower) ||
		strings.Contains(strings.ToLower(n.CharName), lower) ||
		strings.Contains(strings.ToLower(n.Owner), lower)
}

func strOrEmpty(s *string) string {
	if s == nil {
		return ""
	}
	return *s
}

func sortAssets(list []assetNode, sortBy, dir string) {
	val := func(n assetNode) any {
		switch sortBy {
		case "quantity":
			return n.Quantity
		case "location_name":
			return n.Location
		case "character_name":
			return n.CharName
		case "owner_type":
			return n.Owner
		default:
			return n.TypeName
		}
	}
	sort.SliceStable(list, func(i, j int) bool {
		a, b := val(list[i]), val(list[j])
		var less bool
		switch av := a.(type) {
		case int64:
			less = av < b.(int64)
		default:
			less = strings.ToLower(a.(string)) < strings.ToLower(b.(string))
		}
		if dir == "desc" {
			return !less
		}
		return less
	})
}

func findAssetChildren(tree []assetNode, parent int64) []assetNode {
	for _, n := range tree {
		if n.ItemID == parent {
			if n.children == nil {
				return []assetNode{}
			}
			return n.children
		}
		if found := findAssetChildren(n.children, parent); found != nil {
			return found
		}
	}
	return []assetNode{}
}

func assetNodeShape(n assetNode) map[string]any {
	kids := make([]any, 0, len(n.children))
	for _, k := range n.children {
		kids = append(kids, assetNodeShape(k))
	}
	count := n.childrenCount
	has := count > 0
	if n.childrenCount == 0 {
		count = len(n.children)
		has = len(n.children) > 0
	}
	var pid any
	if n.ParentID != nil {
		pid = *n.ParentID
	}
	var corp any
	if n.CorpName != nil {
		corp = *n.CorpName
	}
	var nm any
	if n.Name != nil {
		nm = *n.Name
	}
	return map[string]any{
		"item_id": n.ItemID, "type_id": n.TypeID, "type_name": n.TypeName,
		"name": nm, "quantity": n.Quantity, "location_id": n.LocationID,
		"location_name": n.Location, "location_flag": n.Flag,
		"character_id": n.CharID, "character_name": n.CharName,
		"owner_type": n.Owner, "is_singleton": n.Singleton,
		"corporation_name": corp, "parent_item_id": pid,
		"children": kids, "children_count": count, "has_children": has,
	}
}

func handleAssetsByLocation(d Deps, corporate bool) echo.HandlerFunc {
	return func(c echo.Context) error {
		qp := c.QueryParams()
		search := firstParam(qp["search"])
		if len([]rune(search)) > 100 {
			verrs := validationErrors{}
			verrs.add("search", "The search field must not be greater than 100 characters.")
			return verrs.invalid(c)
		}
		includeItems := queryBool(qp["include_items"], true)
		uid := c.Get(string(ctxUIDKey)).(int64)
		ctx := c.Request().Context()
		chars, err := d.Users.CharactersForUser(ctx, uid)
		if err != nil {
			return dbError(c, err)
		}
		if corporate {
			if denial := assets.CorpDenial(chars); denial != nil {
				return Failure(c, http.StatusForbidden, *denial, nil)
			}
		}
		all, filled := cachedOrLiveAssets(ctx, d, uid, chars, true)
		owner := "personal"
		if corporate {
			owner = "corporate"
		}
		var filtered []enrichedAsset
		for _, a := range all {
			if a.Owner == owner {
				filtered = append(filtered, a)
			}
		}
		tree := buildAssetTree(filtered, search, "type_name", "asc")
		sysGroups := groupAssetsByLocation(ctx, d, tree)
		summary := map[string]any{
			"total_items": len(filtered), "total_quantity": 0,
			"system_count": len(sysGroups), "location_count": 0, "type_count": 0,
		}
		_ = summary
		types := map[int]bool{}
		var qty int64
		for _, a := range filtered {
			qty += a.Quantity
			types[a.TypeID] = true
		}
		summary["total_quantity"] = qty
		summary["type_count"] = len(types)
		sysItems := make([]any, 0, len(sysGroups))
		locCount := 0
		for _, g := range sysGroups {
			locCount += len(g.stations)
			sysItems = append(sysItems, g.shape(includeItems))
		}
		summary["location_count"] = locCount
		return Success(c, http.StatusOK, map[string]any{
			"summary": summary, "solar_systems": sysItems,
		}, map[string]any{"last_updated": lastUpdated(filled)})
	}
}

type stationGroup struct {
	id    int64
	name  string
	sysID *int
	sys   string
	items []assetNode
}

type systemGroup struct {
	id       *int
	name     string
	stations []stationGroup
}

func groupAssetsByLocation(ctx context.Context, d Deps, tree []assetNode) []systemGroup {
	stationMap := map[int64]*stationGroup{}
	var order []int64
	for _, n := range tree {
		loc := n.LocationID
		if loc == 0 {
			loc = -n.ItemID
		}
		if _, ok := stationMap[loc]; !ok {
			stationMap[loc] = &stationGroup{id: n.LocationID, name: n.Location}
			order = append(order, loc)
		}
		stationMap[loc].items = append(stationMap[loc].items, n)
	}
	for _, key := range order {
		st := stationMap[key]
		st.sysID, st.sys = stationSystem(ctx, d, st.id)
	}
	sysIdx := map[string]int{}
	var systems []systemGroup
	for _, key := range order {
		st := stationMap[key]
		sk := st.sys
		if st.sysID != nil {
			sk = itoaProg(*st.sysID)
		}
		si, ok := sysIdx[sk]
		if !ok {
			si = len(systems)
			sysIdx[sk] = si
			systems = append(systems, systemGroup{id: st.sysID, name: st.sys})
		}
		systems[si].stations = append(systems[si].stations, *st)
	}
	sort.Slice(systems, func(i, j int) bool { return systems[i].name < systems[j].name })
	for i := range systems {
		sort.Slice(systems[i].stations, func(a, b int) bool {
			return systems[i].stations[a].name < systems[i].stations[b].name
		})
	}
	return systems
}

func stationSystem(ctx context.Context, d Deps, locID int64) (*int, string) {
	if locID >= 60000000 && locID < 64000000 && d.AssetBrowser.Reference != nil {
		if st, err := d.AssetBrowser.Reference.FindStation(ctx, uint64(locID)); err == nil && st != nil {
			if st.SolarSystemID.Valid {
				sysID := int(st.SolarSystemID.Int64)
				if s, err := d.AssetBrowser.Reference.FindSystem(ctx, sysID); err == nil && s != nil {
					return &sysID, s.SolarSystemName
				}
				return &sysID, "System #" + itoaProg(sysID)
			}
		}
	}
	uv := d.AssetBrowser.Universe
	if locID >= 30000000 && locID < 33000000 {
		if s, err := uv.GetSystem(ctx, int(locID)); err == nil && s != nil {
			if n, ok := s["name"].(string); ok && n != "" {
				v := int(locID)
				return &v, n
			}
		}
	}
	return nil, "Unknown System"
}

func (g systemGroup) shape(includeItems bool) map[string]any {
	sts := make([]any, 0, len(g.stations))
	for _, st := range g.stations {
		items := make([]any, 0, len(st.items))
		for _, it := range st.items {
			items = append(items, assetNodeShape(it))
		}
		if !includeItems {
			items = []any{}
		}
		sts = append(sts, map[string]any{
			"location_id": st.id, "location_name": st.name,
			"items": items, "item_count": len(st.items),
		})
	}
	var sysID any
	if g.id != nil {
		sysID = *g.id
	}
	return map[string]any{"solar_system_id": sysID, "solar_system_name": g.name, "stations": sts}
}

func handleAssetsRefresh(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		return c.NoContent(http.StatusAccepted)
	}
}

func firstParam(vals []string) string {
	if len(vals) == 0 {
		return ""
	}
	return vals[0]
}

func queryBool(vals []string, def bool) bool {
	if len(vals) == 0 {
		return def
	}
	switch strings.ToLower(vals[0]) {
	case "1", "true", "yes", "on":
		return true
	case "0", "false", "no", "off":
		return false
	}
	return def
}

func pagedSlice(items []any, page, perPage int) []any {
	total := len(items)
	start := (page - 1) * perPage
	if start > total {
		start = total
	}
	end := start + perPage
	if end > total {
		end = total
	}
	return items[start:end]
}

func lastUpdated(t *time.Time) any {
	if t == nil {
		return nil
	}
	return t.UTC().Format("2006-01-02T15:04:05Z")
}
