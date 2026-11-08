package http

import (
	"context"
	"net/http"
	"sort"
	"strings"

	"github.com/labstack/echo/v4"

	"github.com/sudo-adduser-jordan/eveindustry/internal/auth"
)

// AssetBrowserESI is the ESI surface the asset browser needs.
type AssetBrowserESI interface {
	GetAssets(ctx context.Context, characterID int64) ([]map[string]any, error)
	GetCorporationAssets(ctx context.Context, characterID, corporationID int64) ([]map[string]any, error)
	GetAssetNames(ctx context.Context, characterID int64, itemIDs []int64) (map[int64]string, error)
	GetCorporationAssetNames(ctx context.Context, characterID, corporationID int64, itemIDs []int64) (map[int64]string, error)
}

// AssetBrowserDeps carries the ESI + reference surface the asset browser needs.
type AssetBrowserDeps struct {
	Characters AssetBrowserESI
	Universe   UniverseReader
	Reference  ProgressReference
}

// registerAssets mounts the asset browser: personal/corporate lists,
// by-location groupings, and the refresh queue stub.
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

var corpHangarFlags = map[string]string{
	"CorpSAG1": "Corp Hangar 1", "CorpSAG2": "Corp Hangar 2",
	"CorpSAG3": "Corp Hangar 3", "CorpSAG4": "Corp Hangar 4",
	"CorpSAG5": "Corp Hangar 5", "CorpSAG6": "Corp Hangar 6",
	"CorpSAG7": "Corp Hangar 7", "CorpDeliveries": "Corp Deliveries",
}

var assetSortFields = map[string]bool{
	"type_name": true, "quantity": true, "location_name": true,
	"character_name": true, "owner_type": true,
}

// enrichedAsset is a flat ESI asset with resolved names.
type enrichedAsset struct {
	itemID     int64
	typeID     int
	typeName   string
	name       *string
	quantity   int64
	locationID int64
	location   string
	flag       string
	charID     int64
	charName   string
	owner      string
	corpName   *string
	singleton  bool
	parentID   *int64
}

// fetchUserAssets ports fetchAndCacheAssets without the cache: live ESI per
// character plus corporate via the main (director/CEO + scope required).
// lastUpdated is always nil until the jobs phase warms snapshots.
func fetchUserAssets(ctx context.Context, d Deps, chars []auth.Character, corpOK bool) []enrichedAsset {
	var out []enrichedAsset
	var scoped []auth.Character
	for _, ch := range chars {
		if hasScope(ch, "esi-universe.read_structures.v1") {
			scoped = append(scoped, ch)
		}
	}
	var main *auth.Character
	for i := range chars {
		if chars[i].IsMain {
			main = &chars[i]
			break
		}
	}
	for _, ch := range chars {
		if !ch.IsEsiActive || !hasScope(ch, "esi-assets.read_assets.v1") {
			continue
		}
		raw, err := d.AssetBrowser.Characters.GetAssets(ctx, ch.CharacterID)
		if err != nil {
			continue
		}
		typeIDs := map[int]bool{}
		for _, a := range raw {
			if id, ok := asInt64Prog(a["type_id"]); ok && id > 0 {
				typeIDs[int(id)] = true
			}
		}
		names := batchTypeNames(ctx, d, keysInt(typeIDs))
		singletons := singletonIDs(raw)
		custom := map[int64]string{}
		if len(singletons) > 0 {
			if n, err := d.AssetBrowser.Characters.GetAssetNames(ctx, ch.CharacterID, singletons); err == nil {
				custom = n
			}
		}
		for _, a := range raw {
			out = append(out, enrichOne(a, &ch, "personal", nil, names, custom, scoped, ctx, d))
		}
	}
	if corpOK && main != nil && main.CorporationID != nil {
		raw, err := d.AssetBrowser.Characters.GetCorporationAssets(ctx, main.CharacterID, *main.CorporationID)
		if err == nil {
			typeIDs := map[int]bool{}
			for _, a := range raw {
				if id, ok := asInt64Prog(a["type_id"]); ok && id > 0 {
					typeIDs[int(id)] = true
				}
			}
			names := batchTypeNames(ctx, d, keysInt(typeIDs))
			singletons := singletonIDs(raw)
			custom := map[int64]string{}
			if len(singletons) > 0 {
				if n, err := d.AssetBrowser.Characters.GetCorporationAssetNames(ctx, main.CharacterID, *main.CorporationID, singletons); err == nil {
					custom = n
				}
			}
			var corpName *string
			if main.CorporationName != nil {
				corpName = main.CorporationName
			}
			for _, a := range raw {
				out = append(out, enrichOne(a, main, "corporate", corpName, names, custom, scoped, ctx, d))
			}
		}
	}
	return out
}

func singletonIDs(raw []map[string]any) []int64 {
	var out []int64
	seen := map[int64]bool{}
	for _, a := range raw {
		if b, _ := a["is_singleton"].(bool); !b {
			continue
		}
		if id, ok := asInt64Prog(a["item_id"]); ok && !seen[id] {
			seen[id] = true
			out = append(out, id)
		}
	}
	return out
}

func keysInt(m map[int]bool) []int {
	out := make([]int, 0, len(m))
	for k := range m {
		out = append(out, k)
	}
	return out
}

func batchTypeNames(ctx context.Context, d Deps, ids []int) map[int]string {
	if len(ids) == 0 {
		return map[int]string{}
	}
	names, err := d.Industry.TypeNames(ctx, ids)
	if err != nil {
		return map[int]string{}
	}
	return names
}

func enrichOne(a map[string]any, ch *auth.Character, owner string, corpName *string, names map[int]string, custom map[int64]string, scoped []auth.Character, ctx context.Context, d Deps) enrichedAsset {
	itemID, _ := asInt64Prog(a["item_id"])
	typeID, _ := asInt64Prog(a["type_id"])
	tname := names[int(typeID)]
	if tname == "" {
		tname = resolveAssetTypeName(ctx, d, int(typeID))
	}
	var customName *string
	if n, ok := custom[itemID]; ok {
		customName = &n
	}
	locID, _ := asInt64Prog(a["location_id"])
	locType, _ := a["location_type"].(string)
	flag, _ := a["location_flag"].(string)
	if f, ok := corpHangarFlags[flag]; ok {
		flag = f
	}
	var parent *int64
	if locType == "item" {
		parent = &locID
	}
	qty, _ := asInt64Prog(a["quantity"])
	if qty == 0 {
		if f, ok := asNumProg(a["quantity"]); ok {
			qty = int64(f)
		}
	}
	return enrichedAsset{
		itemID: itemID, typeID: int(typeID), typeName: tname, name: customName,
		quantity: qty, locationID: locID, location: assetLocationName(ctx, d, a, scoped),
		flag: flag, charID: ch.CharacterID, charName: ch.Name, owner: owner,
		corpName: corpName, singleton: boolProg(a["is_singleton"]), parentID: parent,
	}
}

func resolveAssetTypeName(ctx context.Context, d Deps, typeID int) string {
	if typeID <= 0 {
		return "Unknown"
	}
	if info, err := d.AssetBrowser.Universe.GetTypeInfo(ctx, typeID); err == nil && info != nil {
		if n, ok := info["name"].(string); ok && n != "" {
			return n
		}
	}
	return "Type #" + itoaProg(typeID)
}

// assetLocationName resolves stations, structures (scoped chars) and systems;
// items inside containers name their parent type ("<type> (in <parent>)"
// is NOT PHP — PHP walks to the root station; nested items show the root
// location name via resolveAssetLocationName recursion).
func assetLocationName(ctx context.Context, d Deps, a map[string]any, scoped []auth.Character) string {
	locID, _ := asInt64Prog(a["location_id"])
	if locID <= 0 {
		return "Unknown Location"
	}
	if d.AssetBrowser.Reference != nil {
		if locID >= 60000000 && locID < 64000000 {
			if st, err := d.AssetBrowser.Reference.FindStation(ctx, uint64(locID)); err == nil && st != nil {
				return st.StationName
			}
		} else if locID >= 30000000 && locID < 33000000 {
			if s, err := d.AssetBrowser.Reference.FindSystem(ctx, int(locID)); err == nil && s != nil {
				return s.SolarSystemName
			}
		}
	}
	uv := d.AssetBrowser.Universe
	if locID >= 60000000 && locID < 64000000 {
		if st, err := uv.GetStation(ctx, uint64(locID)); err == nil && st != nil {
			if n, ok := st["name"].(string); ok && n != "" {
				return n
			}
		}
	} else if locID > 2000000000000 {
		for _, ch := range scoped {
			if st, err := uv.GetStructure(ctx, uint64(locID), ch.CharacterID); err == nil && st != nil {
				if n, ok := st["name"].(string); ok && n != "" {
					return n
				}
			}
		}
	} else if locID >= 30000000 && locID < 33000000 {
		if s, err := uv.GetSystem(ctx, int(locID)); err == nil && s != nil {
			if n, ok := s["name"].(string); ok && n != "" {
				return n
			}
		}
	}
	return "Location #" + itoaProg(int(locID))
}

// corpDenial ports corporationAssetsDenial's static checks (no re-verify
// roundtrip: the Go port trusts the stored Director/CEO flag + scope).
func corpDenial(chars []auth.Character) *string {
	var main *auth.Character
	for i := range chars {
		if chars[i].IsMain {
			main = &chars[i]
			break
		}
	}
	msg := func(s string) *string { return &s }
	if main == nil {
		return msg("No main character is set. Corporation assets can only be retrieved by your main character.")
	}
	if main.CorporationID == nil || *main.CorporationID == 0 {
		return msg("Your main character (" + main.Name + ") is not in a corporation.")
	}
	if !main.IsDirectorCEO {
		return msg("Your main character (" + main.Name + ") does not hold the Director or CEO role in their corporation.")
	}
	if !hasScope(*main, "esi-assets.read_corporation_assets.v1") {
		return msg("Your main character (" + main.Name + ") does not have the Corporation Assets ESI scope. Re-authenticate to grant it.")
	}
	return nil
}

// GET /assets or /corporation/assets — paginated tree with search, sort,
// parent/location filters and the children toggle.
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
			if denial := corpDenial(chars); denial != nil {
				return Failure(c, http.StatusForbidden, *denial, nil)
			}
		}
		all := fetchUserAssets(ctx, d, chars, true)
		owner := "personal"
		if corporate {
			owner = "corporate"
		}
		var filtered []enrichedAsset
		for _, a := range all {
			if a.owner != owner {
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
				if t.locationID == *locationID {
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
		return Success(c, http.StatusOK, pagedSlice(items, page, perPage), map[string]any{
			"last_updated": nil,
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
		byID[a.itemID] = n
	}
	var tree []*assetNode
	for _, n := range byID {
		if n.parentID != nil {
			if p, ok := byID[*n.parentID]; ok {
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
	return strings.Contains(strings.ToLower(n.typeName), lower) ||
		strings.Contains(strings.ToLower(strOrEmpty(n.name)), lower) ||
		strings.Contains(strings.ToLower(n.location), lower) ||
		strings.Contains(strings.ToLower(n.charName), lower) ||
		strings.Contains(strings.ToLower(n.owner), lower)
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
			return n.quantity
		case "location_name":
			return n.location
		case "character_name":
			return n.charName
		case "owner_type":
			return n.owner
		default:
			return n.typeName
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
		if n.itemID == parent {
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
	if n.parentID != nil {
		pid = *n.parentID
	}
	var corp any
	if n.corpName != nil {
		corp = *n.corpName
	}
	var nm any
	if n.name != nil {
		nm = *n.name
	}
	return map[string]any{
		"item_id": n.itemID, "type_id": n.typeID, "type_name": n.typeName,
		"name": nm, "quantity": n.quantity, "location_id": n.locationID,
		"location_name": n.location, "location_flag": n.flag,
		"character_id": n.charID, "character_name": n.charName,
		"owner_type": n.owner, "is_singleton": n.singleton,
		"corporation_name": corp, "parent_item_id": pid,
		"children": kids, "children_count": count, "has_children": has,
	}
}

// GET /assets/by-location — grouped by system then station with a summary.
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
			if denial := corpDenial(chars); denial != nil {
				return Failure(c, http.StatusForbidden, *denial, nil)
			}
		}
		all := fetchUserAssets(ctx, d, chars, true)
		owner := "personal"
		if corporate {
			owner = "corporate"
		}
		var filtered []enrichedAsset
		for _, a := range all {
			if a.owner == owner {
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
			qty += a.quantity
			types[a.typeID] = true
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
		}, map[string]any{"last_updated": nil})
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

// groupAssetsByLocation ports getSolarSystemGroupedAssets: top-level tree
// items grouped by root location, stations grouped under their solar system
// (NPC stations via SDE, systems via ESI, structures stay unknown), both
// sorted by name.
func groupAssetsByLocation(ctx context.Context, d Deps, tree []assetNode) []systemGroup {
	stationMap := map[int64]*stationGroup{}
	var order []int64
	for _, n := range tree {
		loc := n.locationID
		if loc == 0 {
			loc = -n.itemID
		}
		if _, ok := stationMap[loc]; !ok {
			stationMap[loc] = &stationGroup{id: n.locationID, name: n.location}
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

// stationSystem ports resolveStationSolarSystem (without the ESI structure
// cache: structures resolve through a scoped live lookup instead).
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

// POST /assets/refresh — jobs-phase stub: answers 202 at once.
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
