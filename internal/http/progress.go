package http

import (
	"context"
	"errors"
	"net/http"

	"github.com/sudo-adduser-jordan/eveindustry/internal/esi"
	"sort"

	"github.com/labstack/echo/v4"

	"github.com/sudo-adduser-jordan/eveindustry/internal/auth"
	"github.com/sudo-adduser-jordan/eveindustry/internal/calc"
	"github.com/sudo-adduser-jordan/eveindustry/internal/store"
)

// ProgressCharacters is the ESI surface the progress endpoints need.
type ProgressCharacters interface {
	GetAssets(ctx context.Context, characterID int64) ([]map[string]any, error)
	GetCorporationAssets(ctx context.Context, characterID, corporationID int64) ([]map[string]any, error)
}

// ProgressUniverse resolves structure and system names over ESI.
type ProgressUniverse interface {
	GetSystem(ctx context.Context, systemID int) (map[string]any, error)
	GetStructure(ctx context.Context, structureID uint64, characterID int64) (map[string]any, error)
}

// ProgressReference is the SDE surface for stations and systems.
type ProgressReference interface {
	FindStation(ctx context.Context, stationID uint64) (*store.Station, error)
	FindSystem(ctx context.Context, systemID int) (*store.SolarSystem, error)
}

// ProgressDeps carries the progress endpoints' collaborators. Nil Characters
// keeps the routes unregistered until the ESI phase wires them.
type ProgressDeps struct {
	Characters ProgressCharacters
	Universe   ProgressUniverse
	Reference  ProgressReference
}

// registerProgress mounts the four build-program progress routes.
func registerProgress(v1 *echo.Group, d Deps) {
	if d.Progress.Characters == nil || d.Users == nil || d.Programs == nil ||
		d.CorpPrograms == nil || d.Corp == nil || d.Industry == nil {
		return
	}
	v1.GET("/build-programs/:id/progress", handlePersonalProgress(d))
	v1.GET("/build-programs/:id/asset-locations", handlePersonalAssetLocations(d))
	v1.GET("/corporation/build-programs/:id/progress", handleCorpProgress(d))
	v1.GET("/corporation/build-programs/:id/asset-locations", handleCorpAssetLocations(d))
}

// GET /build-programs/:id/progress — 404 foreign; 400 with no characters.
func handlePersonalProgress(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		id, ok := planID(c)
		if !ok {
			return errHandled
		}
		uid := c.Get(string(ctxUIDKey)).(int64)
		ctx := c.Request().Context()
		row, err := d.Programs.BuildProgram(ctx, id, uid)
		if err != nil {
			return dbError(c, err)
		}
		if row == nil {
			return Failure(c, http.StatusNotFound, "Not found.", nil)
		}
		filter, ok := progressFilter(c)
		if !ok {
			return errHandled
		}
		chars, err := d.Users.CharactersForUser(ctx, uid)
		if err != nil {
			return dbError(c, err)
		}
		if len(chars) == 0 {
			return Failure(c, http.StatusBadRequest, "No characters linked.", nil)
		}
		qtys, names, err := personalAssetQuantities(ctx, d, chars, filter)
		if err != nil {
			return dbError(c, err)
		}
		meta, err := progressMeta(ctx, d, decodeSteps(row.StepsJSON))
		if err != nil {
			return dbError(c, err)
		}
		sum := calc.SummariseProgress(decodeSteps(row.StepsJSON), decodeSettings(row.SettingsJSON), qtys, qtys, meta)
		return Success(c, http.StatusOK, progressShape(names, sum), nil)
	}
}

// GET /build-programs/:id/asset-locations — paginated, 404 foreign.
func handlePersonalAssetLocations(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		id, ok := planID(c)
		if !ok {
			return errHandled
		}
		uid := c.Get(string(ctxUIDKey)).(int64)
		ctx := c.Request().Context()
		row, err := d.Programs.BuildProgram(ctx, id, uid)
		if err != nil {
			return dbError(c, err)
		}
		if row == nil {
			return Failure(c, http.StatusNotFound, "Not found.", nil)
		}
		page, perPage, ok := listPage(c)
		if !ok {
			return errHandled
		}
		chars, err := d.Users.CharactersForUser(ctx, uid)
		if err != nil {
			return dbError(c, err)
		}
		locs, err := personalAssetLocations(ctx, d, chars)
		if err != nil {
			return dbError(c, err)
		}
		return paginated(c, locs, page, perPage)
	}
}

// GET /corporation/build-programs/:id/progress — 404 foreign/disabled;
// 403 when the user has no character in the corp; 400 when the director
// character is gone.
func handleCorpProgress(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		id, ok := planID(c)
		if !ok {
			return errHandled
		}
		uid := c.Get(string(ctxUIDKey)).(int64)
		ctx := c.Request().Context()
		cfg, _, err := corpConfigForUser(ctx, d, uid)
		if err != nil {
			return dbError(c, err)
		}
		if cfg == nil {
			return Failure(c, http.StatusNotFound, "Not found.", nil)
		}
		row, err := d.CorpPrograms.CorpProgram(ctx, id, cfg.CorporationID)
		if err != nil {
			return dbError(c, err)
		}
		if row == nil {
			return Failure(c, http.StatusNotFound, "Not found.", nil)
		}
		chars, err := d.Users.CharactersForUser(ctx, uid)
		if err != nil {
			return dbError(c, err)
		}
		inCorp := false
		for _, ch := range chars {
			if ch.CorporationID != nil && *ch.CorporationID == cfg.CorporationID {
				inCorp = true
				break
			}
		}
		if !inCorp {
			return Failure(c, http.StatusForbidden, "Access denied.", nil)
		}
		inFilter, outFilter, ok := corpProgressFilters(c)
		if !ok {
			return errHandled
		}
		var director *auth.Character
		for i := range chars {
			if chars[i].CharacterID == cfg.CharacterID {
				director = &chars[i]
				break
			}
		}
		if director == nil {
			// The director may belong to another user; fetch minimal identity.
			director = &auth.Character{CharacterID: cfg.CharacterID, Name: ""}
		}
		assets, err := d.Progress.Characters.GetCorporationAssets(ctx, cfg.CharacterID, cfg.CorporationID)
		if err != nil {
			return dbError(c, err)
		}
		steps := decodeSteps(row.StepsJSON)
		inQ, outQ := splitCorpQuantities(assets, inFilter, outFilter)
		meta, err := progressMeta(ctx, d, steps)
		if err != nil {
			return dbError(c, err)
		}
		sum := calc.SummariseProgress(steps, decodeSettings(nullStrOrEmpty(row.SettingsJSON)), inQ, outQ, meta)
		names := []any{}
		if director.Name != "" {
			names = []any{director.Name}
		}
		payload := progressShape(names, sum)
		// Corporate responses carry character_name instead of character_names.
		payload["character_name"] = ""
		if len(names) > 0 {
			payload["character_name"] = names[0]
		}
		delete(payload, "character_names")
		return Success(c, http.StatusOK, payload, nil)
	}
}

// GET /corporation/build-programs/:id/asset-locations — paginated.
func handleCorpAssetLocations(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		id, ok := planID(c)
		if !ok {
			return errHandled
		}
		uid := c.Get(string(ctxUIDKey)).(int64)
		ctx := c.Request().Context()
		cfg, _, err := corpConfigForUser(ctx, d, uid)
		if err != nil {
			return dbError(c, err)
		}
		if cfg == nil {
			return Failure(c, http.StatusNotFound, "Not found.", nil)
		}
		row, err := d.CorpPrograms.CorpProgram(ctx, id, cfg.CorporationID)
		if err != nil {
			return dbError(c, err)
		}
		if row == nil {
			return Failure(c, http.StatusNotFound, "Not found.", nil)
		}
		page, perPage, ok := listPage(c)
		if !ok {
			return errHandled
		}
		chars, err := d.Users.CharactersForUser(ctx, uid)
		if err != nil {
			return dbError(c, err)
		}
		assets, err := d.Progress.Characters.GetCorporationAssets(ctx, cfg.CharacterID, cfg.CorporationID)
		if err != nil {
			return dbError(c, err)
		}
		locs, err := describeLocations(ctx, d, assets, chars)
		if err != nil {
			return dbError(c, err)
		}
		return paginated(c, locs, page, perPage)
	}
}

// personalAssetQuantities aggregates asset quantities over the user's
// ESI-active characters; revoked/unlinked characters are skipped, other ESI
// failures propagate.
func personalAssetQuantities(ctx context.Context, d Deps, chars []auth.Character, filter map[int64]bool) (map[int]float64, []any, error) {
	qtys := map[int]float64{}
	names := []any{}
	active := !useFilter(filter)
	var assetMap map[int64]map[string]any
	for _, ch := range chars {
		if !ch.IsEsiActive {
			continue
		}
		assets, err := d.Progress.Characters.GetAssets(ctx, ch.CharacterID)
		if err != nil {
			if isCredentialErr(err) {
				continue
			}
			return nil, nil, err
		}
		names = append(names, ch.Name)
		if active {
			assetMap = nil
		} else {
			assetMap = buildAssetMap(assets)
		}
		for _, a := range assets {
			typeID, _ := asIntProg(a["type_id"])
			qty, _ := asNumProg(a["quantity"])
			if typeID == 0 || qty <= 0 {
				continue
			}
			if !active && !filter[rootLocationID(a, assetMap)] {
				continue
			}
			qtys[typeID] += qty
		}
	}
	return qtys, names, nil
}

func useFilter(f map[int64]bool) bool { return len(f) > 0 }

// personalAssetLocations collects distinct root locations over ESI-active
// characters (empty when the user has none).
func personalAssetLocations(ctx context.Context, d Deps, chars []auth.Character) ([]any, error) {
	var all []map[string]any
	for _, ch := range chars {
		if !ch.IsEsiActive {
			continue
		}
		assets, err := d.Progress.Characters.GetAssets(ctx, ch.CharacterID)
		if err != nil {
			if isCredentialErr(err) {
				continue
			}
			return nil, err
		}
		all = append(all, assets...)
	}
	if len(all) == 0 {
		return []any{}, nil
	}
	return describeLocations(ctx, d, all, chars)
}

// splitCorpQuantities counts materials at input locations and finished
// products at output locations (empty filter counts everything).
func splitCorpQuantities(assets []map[string]any, inFilter, outFilter map[int64]bool) (map[int]float64, map[int]float64) {
	inQ := map[int]float64{}
	outQ := map[int]float64{}
	inActive := useFilter(inFilter)
	outActive := useFilter(outFilter)
	assetMap := buildAssetMap(assets)
	for _, a := range assets {
		typeID, _ := asIntProg(a["type_id"])
		qty, _ := asNumProg(a["quantity"])
		if typeID == 0 || qty <= 0 {
			continue
		}
		root := rootLocationID(a, assetMap)
		if !inActive || inFilter[root] {
			inQ[typeID] += qty
		}
		if !outActive || outFilter[root] {
			outQ[typeID] += qty
		}
	}
	return inQ, outQ
}

// progressMeta gathers group/blueprint/volume metadata for the step tree.
func progressMeta(ctx context.Context, d Deps, steps []any) (calc.ProgressMeta, error) {
	ids := map[int]bool{}
	collectProgressTypeIDs(steps, ids)
	list := make([]int, 0, len(ids))
	for id := range ids {
		list = append(list, id)
	}
	groups, err := d.Industry.GroupsOf(ctx, list)
	if err != nil {
		return calc.ProgressMeta{}, err
	}
	names, err := d.Industry.TypeNames(ctx, list)
	if err != nil {
		return calc.ProgressMeta{}, err
	}
	vols, err := d.Industry.TypeVolumes(ctx, list)
	if err != nil {
		return calc.ProgressMeta{}, err
	}
	bpNames := map[string]string{}
	for id, n := range names {
		if groups[id] != 0 {
			bpNames[itoaProg(id)] = n
		}
		_ = n
	}
	// Blueprint names: only blueprint type ids carry names in the summary;
	// collectSummaryData keys them by blueprint id, so expose all names.
	for id, n := range names {
		bpNames[itoaProg(id)] = n
	}
	return calc.ProgressMeta{Groups: groups, BlueprintNames: bpNames, Volumes: vols}, nil
}

func collectProgressTypeIDs(steps []any, into map[int]bool) {
	for _, sv := range steps {
		sm, ok := sv.(map[string]any)
		if !ok {
			continue
		}
		if id, ok := asIntProg(sm["type_id"]); ok && id != 0 {
			into[id] = true
		}
		if id, ok := asIntProg(sm["blueprint_type_id"]); ok && id != 0 {
			into[id] = true
		}
		if ml, ok := sm["materials"].([]any); ok {
			for _, mv := range ml {
				if mm, ok := mv.(map[string]any); ok {
					if id, ok := asIntProg(mm["type_id"]); ok && id != 0 {
						into[id] = true
					}
				}
			}
		}
		if kl, ok := sm["children"].([]any); ok {
			collectProgressTypeIDs(kl, into)
		}
	}
}

// progressShape renders a ProgressSummary like BuildProgramProgressResource:
// character_names + floats + nested steps (children stay raw step maps).
func progressShape(names []any, s calc.ProgressSummary) map[string]any {
	if names == nil {
		names = []any{}
	}
	steps := make([]any, 0, len(s.Steps))
	for _, sv := range s.Steps {
		steps = append(steps, progressStepShape(sv.(map[string]any)))
	}
	secs := make([]any, 0, len(s.Sections))
	for _, sv := range s.Sections {
		sm := sv.(map[string]any)
		secs = append(secs, map[string]any{
			"name": sm["name"], "step_count": sm["step_count"],
			"overall_progress": toFloatProg(sm["overall_progress"]),
		})
	}
	return map[string]any{
		"character_names":     names,
		"overall_progress":    toFloatProg(s.OverallProgress),
		"steps":               steps,
		"required_blueprints": anyList(s.RequiredBlueprints),
		"base_materials":      supplyList(s.BaseMaterials),
		"fuel_blocks":         supplyList(s.FuelBlocks),
		"reactions_summary":   reactionList(s.ReactionsSummary),
		"sections":            secs,
	}
}

func progressStepShape(sm map[string]any) map[string]any {
	mats := []any{}
	if ml, ok := sm["materials"].([]any); ok {
		for _, mv := range ml {
			mm := mv.(map[string]any)
			mats = append(mats, map[string]any{
				"type_id": toIntProg(mm["type_id"]), "name": strProg(mm["name"]),
				"required": toIntProg(mm["required"]), "available": toIntProg(mm["available"]),
				"progress": toFloatProg(mm["progress"]), "has_enough": boolProg(mm["has_enough"]),
			})
		}
	}
	kids := []any{}
	if kl, ok := sm["children"].([]any); ok {
		for _, kv := range kl {
			kids = append(kids, progressStepShape(kv.(map[string]any)))
		}
	}
	return map[string]any{
		"type_id": toIntProg(sm["type_id"]), "name": strProg(sm["name"]),
		"quantity_required": toIntProg(sm["quantity_required"]),
		"available":         toIntProg(sm["available"]),
		"progress":          toFloatProg(sm["progress"]), "has_enough": boolProg(sm["has_enough"]),
		"materials": mats, "children": kids,
	}
}

func anyList(in []any) []any {
	if in == nil {
		return []any{}
	}
	return in
}

func supplyList(in []any) []any {
	out := make([]any, 0, len(in))
	for _, v := range in {
		m := v.(map[string]any)
		out = append(out, map[string]any{
			"type_id": toIntProg(m["type_id"]), "name": strProg(m["name"]),
			"quantity": toIntProg(m["quantity"]), "available": toIntProg(m["available"]),
			"group_name": strProgDefault(m["group_name"], "Other"),
			"volume":     toFloatProg(m["volume"]),
		})
	}
	return out
}

func reactionList(in []any) []any {
	out := make([]any, 0, len(in))
	for _, v := range in {
		r := v.(map[string]any)
		ins := []any{}
		if il, ok := r["inputs"].([]any); ok {
			for _, iv := range il {
				im := iv.(map[string]any)
				ins = append(ins, map[string]any{
					"type_id": toIntProg(im["type_id"]), "name": strProg(im["name"]),
					"required_qty": toIntProg(im["required_qty"]),
					"available":    toIntProg(im["available"]),
				})
			}
		}
		out = append(out, map[string]any{
			"type_id": toIntProg(r["type_id"]), "name": strProg(r["name"]),
			"blueprint_type_id": toIntProg(r["blueprint_type_id"]),
			"total_runs":        toIntProg(r["total_runs"]),
			"output_qty":        toIntProg(r["output_qty"]),
			"available":         toIntProg(r["available"]),
			"net_output_qty":    toIntProg(r["net_output_qty"]),
			"net_runs":          toIntProg(r["net_runs"]),
			"input_volume":      toFloatProg(r["input_volume"]),
			"output_volume":     toFloatProg(r["output_volume"]),
			"inputs":            ins,
		})
	}
	return out
}

// --- asset locations ---

func buildAssetMap(assets []map[string]any) map[int64]map[string]any {
	m := make(map[int64]map[string]any, len(assets))
	for _, a := range assets {
		if id, ok := asInt64Prog(a["item_id"]); ok {
			m[id] = a
		}
	}
	return m
}

// rootLocationID ports AssetLocationResolver::rootLocationId (depth guard 20).
func rootLocationID(asset map[string]any, assetMap map[int64]map[string]any) int64 {
	depth := 0
	for {
		locType, _ := asset["location_type"].(string)
		locID, _ := asInt64Prog(asset["location_id"])
		if locType != "item" || depth > 20 {
			return locID
		}
		parent, ok := assetMap[locID]
		if !ok {
			return locID
		}
		asset = parent
		depth++
	}
}

// describeLocations ports AssetLocationResolver::describe: station, player
// structure (ESI with a scoped character), or bare system; sorted by system
// name then location name, unknown systems last.
func describeLocations(ctx context.Context, d Deps, assets []map[string]any, chars []auth.Character) ([]any, error) {
	assetMap := buildAssetMap(assets)
	roots := map[int64]bool{}
	for _, a := range assets {
		if r := rootLocationID(a, assetMap); r > 0 {
			roots[r] = true
		}
	}
	var scoped []auth.Character
	for _, ch := range chars {
		if hasScope(ch, "esi-universe.read_structures.v1") {
			scoped = append(scoped, ch)
		}
	}
	type loc struct {
		id      int64
		name    string
		sysID   *int
		sysName string
	}
	out := make([]loc, 0, len(roots))
	for id := range roots {
		var name string
		var sysID *int
		switch {
		case id >= 60000000 && id < 64000000:
			if d.Progress.Reference != nil {
				if st, err := d.Progress.Reference.FindStation(ctx, uint64(id)); err != nil {
					return nil, err
				} else if st != nil {
					name = st.StationName
					if st.SolarSystemID.Valid {
						v := int(st.SolarSystemID.Int64)
						sysID = &v
					}
				}
			}
		case id > 2000000000000:
			// Player-owned structure: live ESI with a scoped character.
			if d.Progress.Universe != nil {
				for _, ch := range scoped {
					if st, err := d.Progress.Universe.GetStructure(ctx, uint64(id), ch.CharacterID); err == nil && st != nil {
						if n, ok := st["name"].(string); ok {
							name = n
						}
						if s, ok := asIntProg(st["solar_system_id"]); ok && s != 0 {
							sysID = &s
						}
						if name != "" {
							break
						}
					}
				}
			}
		case id >= 30000000 && id < 33000000:
			v := int(id)
			sysID = &v
			if d.Progress.Reference != nil {
				if s, err := d.Progress.Reference.FindSystem(ctx, v); err != nil {
					return nil, err
				} else if s != nil {
					name = s.SolarSystemName
				}
			}
		}
		sysName := ""
		if sysID != nil {
			if d.Progress.Reference != nil {
				if s, err := d.Progress.Reference.FindSystem(ctx, *sysID); err != nil {
					return nil, err
				} else if s != nil {
					sysName = s.SolarSystemName
				}
			}
			if sysName == "" && d.Progress.Universe != nil {
				if s, err := d.Progress.Universe.GetSystem(ctx, *sysID); err == nil && s != nil {
					if n, ok := s["name"].(string); ok {
						sysName = n
					}
				}
			}
		}
		if name == "" {
			name = "Location #" + itoaProg(int(id))
		}
		sortKey := sysName
		if sortKey == "" {
			sortKey = "~"
		}
		_ = sortKey
		out = append(out, loc{id: id, name: name, sysID: sysID, sysName: sysName})
	}
	sorted := out
	sort.Slice(sorted, func(i, j int) bool {
		ki := sorted[i].sysName
		if ki == "" {
			ki = "~"
		}
		kj := sorted[j].sysName
		if kj == "" {
			kj = "~"
		}
		if ki != kj {
			return ki < kj
		}
		return sorted[i].name < sorted[j].name
	})
	res := make([]any, 0, len(sorted))
	for _, l := range sorted {
		var sysID any
		if l.sysID != nil {
			sysID = *l.sysID
		}
		var sysName any
		if l.sysName != "" {
			sysName = l.sysName
		}
		res = append(res, map[string]any{
			"location_id": l.id, "name": l.name,
			"solar_system_id": sysID, "solar_system_name": sysName,
		})
	}
	return res, nil
}

// --- small coercions ---

func asIntProg(v any) (int, bool) {
	switch n := v.(type) {
	case int:
		return n, true
	case int64:
		return int(n), true
	case float64:
		return int(n), true
	}
	return 0, false
}

func asInt64Prog(v any) (int64, bool) {
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

func asNumProg(v any) (float64, bool) {
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

func toIntProg(v any) int {
	n, _ := asNumProg(v)
	return int(n)
}

func toFloatProg(v any) float64 {
	n, _ := asNumProg(v)
	return n
}

func strProg(v any) string {
	s, _ := v.(string)
	return s
}

func strProgDefault(v any, def string) string {
	if s, ok := v.(string); ok && s != "" {
		return s
	}
	return def
}

func boolProg(v any) bool {
	b, _ := v.(bool)
	return b
}

func itoaProg(i int) string {
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

// listPage parses ?page=&per_page= (defaults 1/50, max 200); 422 on garbage.
func listPage(c echo.Context) (int, int, bool) {
	page, ok := queryInt(c, "page", 1, 1, 1<<31-1)
	if !ok {
		return 0, 0, false
	}
	perPage, ok := queryInt(c, "per_page", 50, 1, 200)
	if !ok {
		return 0, 0, false
	}
	return page, perPage, true
}

var errBadInt = errors.New("bad int")

func queryInt(c echo.Context, key string, def, min, max int) (int, bool) {
	raw := c.QueryParam(key)
	if raw == "" {
		return def, true
	}
	n, err := parseIntProg(raw)
	if err != nil || n < min || n > max {
		verrs := validationErrors{}
		verrs.add(key, "The "+key+" field must be an integer.")
		verrs.invalid(c)
		return 0, false
	}
	return n, true
}

// queryIDs reads ?location_ids[]=1&location_ids[]=2 (or repeated
// ?location_ids=1); 422 on non-integers.
func queryIDs(c echo.Context, key string) (map[int64]bool, bool) {
	vals := c.QueryParams()[key]
	if vals == nil {
		vals = c.QueryParams()[key+"[]"]
	}
	if len(vals) == 0 {
		return nil, true
	}
	if len(vals) > 500 {
		verrs := validationErrors{}
		verrs.add(key, "The "+key+" field must not have more than 500 items.")
		verrs.invalid(c)
		return nil, false
	}
	out := make(map[int64]bool, len(vals))
	for _, v := range vals {
		n64, err := parseIntProg(v)
		n := int64(n64)
		if err != nil {
			verrs := validationErrors{}
			verrs.add(key, "Each "+key+" entry must be an integer.")
			verrs.invalid(c)
			return nil, false
		}
		out[n] = true
	}
	return out, true
}

func parseIntProg(s string) (int, error) {
	neg := false
	if len(s) > 0 && s[0] == '-' {
		neg = true
		s = s[1:]
	}
	if s == "" {
		return 0, errBadInt
	}
	n := 0
	for i := 0; i < len(s); i++ {
		if s[i] < '0' || s[i] > '9' {
			return 0, errBadInt
		}
		n = n*10 + int(s[i]-'0')
	}
	if neg {
		n = -n
	}
	return n, nil
}

// progressFilter parses ?location_ids (personal progress).
func progressFilter(c echo.Context) (map[int64]bool, bool) {
	f, ok := queryIDs(c, "location_ids")
	if !ok {
		return nil, false
	}
	return f, true
}

// corpProgressFilters parses ?input_location_ids & ?output_location_ids.
func corpProgressFilters(c echo.Context) (map[int64]bool, map[int64]bool, bool) {
	inF, ok := queryIDs(c, "input_location_ids")
	if !ok {
		return nil, nil, false
	}
	outF, ok := queryIDs(c, "output_location_ids")
	if !ok {
		return nil, nil, false
	}
	return inF, outF, true
}

// paginated slices items and renders the {data, meta.pagination} envelope.
func paginated(c echo.Context, items []any, page, perPage int) error {
	total := len(items)
	start := (page - 1) * perPage
	if start > total {
		start = total
	}
	end := start + perPage
	if end > total {
		end = total
	}
	return Success(c, http.StatusOK, items[start:end], map[string]any{
		"pagination": map[string]any{
			"page": page, "per_page": perPage, "total": total,
			"last_page": (total + perPage - 1) / perPage,
		},
	})
}

// isCredentialErr mirrors assetsOrNull: revoked/unlinked tokens yield no
// assets; every other ESI failure propagates.
func isCredentialErr(err error) bool {
	var reqErr *esi.RequestError
	if errors.As(err, &reqErr) {
		return reqErr.Outcome == esi.OutcomeCredential
	}
	return false
}
