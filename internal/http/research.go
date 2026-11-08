package http

import (
	"context"
	"net/http"
	"strconv"

	"github.com/labstack/echo/v4"

	"github.com/sudo-adduser-jordan/eveindustry/internal/auth"
	"github.com/sudo-adduser-jordan/eveindustry/internal/calc"
	"github.com/sudo-adduser-jordan/eveindustry/internal/industry"
	"github.com/sudo-adduser-jordan/eveindustry/internal/store"
)

// ResearchDeps carries the ESI + research stores.
type ResearchDeps struct {
	Characters ResearchESI
	Research   store.ResearchStore
}

// ResearchESI is the ESI surface research needs.
type ResearchESI interface {
	GetBlueprints(ctx context.Context, characterID int64) ([]map[string]any, error)
	GetIndustryJobs(ctx context.Context, characterID int64, includeCompleted bool) ([]map[string]any, error)
}

// registerResearch mounts the research pair. Reaction jobs feed the formula
// counts; both need live ESI blueprints.
func registerResearch(v1 *echo.Group, d Deps) {
	if d.Research.Characters == nil || d.Research.Research == nil || d.Users == nil {
		return
	}
	v1.GET("/industry/blueprint-research", handleBlueprintResearch(d))
	v1.GET("/industry/reaction-formula-research", handleFormulaResearch(d))
}

// researchChars returns the user's characters plus scope flags.
func researchChars(c echo.Context, d Deps, uid int64) ([]auth.Character, error) {
	return d.Users.CharactersForUser(c.Request().Context(), uid)
}

func parseResearchParams(c echo.Context, verrs validationErrors, sortOptions []string) (search string, filterID int, sortBy, sortDir string, page, perPage int) {
	search = c.QueryParam("search")
	if len([]rune(search)) > 100 {
		verrs.add("search", "The search field must not be greater than 100 characters.")
	}
	if raw := c.QueryParam("market_group"); raw != "" {
		v, err := strconv.Atoi(raw)
		if err != nil || v < 1 {
			verrs.add("market_group", "The market group field must be an integer greater than 0.")
		} else {
			filterID = v
		}
	}
	if raw := c.QueryParam("category"); raw != "" {
		v, err := strconv.Atoi(raw)
		if err != nil || v < 1 {
			verrs.add("category", "The category field must be an integer greater than 0.")
		} else {
			filterID = v
		}
	}
	sortBy = "name"
	if raw := c.QueryParam("sort"); raw != "" {
		ok := false
		for _, o := range sortOptions {
			if raw == o {
				ok = true
				break
			}
		}
		if !ok {
			verrs.add("sort", "The selected sort is invalid.")
		} else {
			sortBy = raw
		}
	}
	sortDir = "asc"
	if raw := c.QueryParam("dir"); raw != "" {
		if raw != "asc" && raw != "desc" {
			verrs.add("dir", "The selected dir is invalid.")
		} else {
			sortDir = raw
		}
	}
	page, perPage = parsePagination(c, verrs)
	return search, filterID, sortBy, sortDir, page, perPage
}

// ownedBlueprints ports getOwnedBlueprints(): best ME/TE per type over the
// user's in-scope characters. Characters whose ESI call fails are skipped.
func ownedBlueprints(ctx context.Context, d Deps, chars []auth.Character) map[int]calc.OwnedBlueprint {
	var entries []calc.OwnedEntry
	for _, ch := range chars {
		if !ch.IsEsiActive || !hasScope(ch, "esi-characters.read_blueprints.v1") {
			continue
		}
		bps, err := d.Research.Characters.GetBlueprints(ctx, ch.CharacterID)
		if err != nil {
			continue
		}
		for _, bp := range bps {
			id := intVal(bp["type_id"], 0)
			if id == 0 {
				continue
			}
			entries = append(entries, calc.OwnedEntry{
				TypeID: id, ME: intVal(bp["material_efficiency"], 0),
				TE: intVal(bp["time_efficiency"], 0), Owner: ch.Name,
			})
		}
	}
	return calc.MergeOwnedBlueprints(entries)
}

// GET /industry/blueprint-research — mirrors ResearchController@blueprints.
func handleBlueprintResearch(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		verrs := validationErrors{}
		search, groupID, sortBy, sortDir, page, perPage := parseResearchParams(c, verrs, []string{"name", "owned", "market_group"})
		if len(verrs) > 0 {
			return verrs.invalid(c)
		}
		uid := c.Get(string(ctxUIDKey)).(int64)
		ctx := c.Request().Context()
		chars, err := researchChars(c, d, uid)
		if err != nil {
			return dbError(c, err)
		}
		hasData := false
		for _, ch := range chars {
			if hasScope(ch, "esi-characters.read_blueprints.v1") {
				hasData = true
				break
			}
		}
		owned := map[int]calc.OwnedBlueprint{}
		if hasData {
			owned = ownedBlueprints(ctx, d, chars)
		}
		rows, err := d.Research.Research.T1Blueprints(ctx, search, groupID)
		if err != nil {
			return dbError(c, err)
		}
		type row struct {
			store.ResearchBlueprint
			owned *calc.OwnedBlueprint
		}
		typed := make([]row, 0, len(rows))
		for _, r := range rows {
			r := r
			var o *calc.OwnedBlueprint
			if ob, ok := owned[r.TypeID]; ok {
				ob := ob
				o = &ob
			}
			typed = append(typed, row{ResearchBlueprint: r, owned: o})
		}
		switch sortBy {
		case "owned":
			calc.SortOwnedFirst(typed, func(r row) bool { return r.owned != nil }, sortDir == "desc")
		case "market_group":
			calc.SortByGroupName(typed, func(r row) string {
				if r.MarketGroupName == nil {
					return ""
				}
				return *r.MarketGroupName
			}, sortDir == "desc")
		default:
			if sortDir == "desc" {
				for i, j := 0, len(typed)-1; i < j; i, j = i+1, j-1 {
					typed[i], typed[j] = typed[j], typed[i]
				}
			}
		}
		missing := []any{}
		for _, ch := range chars {
			if !hasScope(ch, "esi-characters.read_blueprints.v1") {
				missing = append(missing, map[string]any{"character_id": ch.CharacterID, "name": ch.Name})
			}
		}
		groups, err := d.Research.Research.BlueprintMarketGroups(ctx)
		if err != nil {
			return dbError(c, err)
		}
		mgOut := make([]any, 0, len(groups))
		for _, g := range groups {
			mgOut = append(mgOut, map[string]any{"market_group_id": g.ID, "name": g.Name})
		}
		offset, pagination := paginate(len(typed), page, perPage)
		end := offset + perPage
		if end > len(typed) {
			end = len(typed)
		}
		out := make([]any, 0, end-offset)
		for _, r := range typed[offset:end] {
			var me, te any
			owners := []any{}
			ownedFlag := false
			if r.owned != nil {
				ownedFlag = true
				me, te = r.owned.MaterialEfficiency, r.owned.TimeEfficiency
				for _, n := range r.owned.OwnerNames {
					owners = append(owners, n)
				}
			}
			out = append(out, map[string]any{
				"type_id": r.TypeID, "name": r.Name,
				"market_group_id": r.MarketGroupID, "market_group_name": r.MarketGroupName,
				"owned": ownedFlag, "me": me, "te": te, "owner_names": owners,
			})
		}
		return Success(c, http.StatusOK, out, map[string]any{
			"has_blueprint_data": hasData, "characters_without_scope": missing,
			"market_groups": mgOut, "pagination": pagination,
		})
	}
}

// GET /industry/reaction-formula-research — mirrors
// ResearchController@formulas: every published formula with owned counts
// (hangars + locked in running reaction jobs).
func handleFormulaResearch(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		verrs := validationErrors{}
		search, groupID, sortBy, sortDir, _, _ := parseResearchParams(c, verrs, []string{"name", "owned", "category"})
		if len(verrs) > 0 {
			return verrs.invalid(c)
		}
		uid := c.Get(string(ctxUIDKey)).(int64)
		ctx := c.Request().Context()
		chars, err := researchChars(c, d, uid)
		if err != nil {
			return dbError(c, err)
		}
		hasData := false
		for _, ch := range chars {
			if hasScope(ch, "esi-characters.read_blueprints.v1") {
				hasData = true
				break
			}
		}
		ownedQty := map[int]int{}
		ownedOwners := map[int][]string{}
		if hasData {
			addOwner := func(id int, qty int, name string) {
				ownedQty[id] += qty
				for _, n := range ownedOwners[id] {
					if n == name {
						return
					}
				}
				ownedOwners[id] = append(ownedOwners[id], name)
			}
			for _, ch := range chars {
				if !ch.IsEsiActive {
					continue
				}
				if hasScope(ch, "esi-characters.read_blueprints.v1") {
					if bps, err := d.Research.Characters.GetBlueprints(ctx, ch.CharacterID); err == nil {
						for _, bp := range bps {
							id := intVal(bp["type_id"], 0)
							if id == 0 {
								continue
							}
							if q := intVal(bp["quantity"], 1); q > 0 {
								addOwner(id, q, ch.Name)
							}
						}
					}
				}
				if hasScope(ch, "esi-industry.read_character_jobs.v1") {
					if jobs, err := d.Research.Characters.GetIndustryJobs(ctx, ch.CharacterID, false); err == nil {
						for _, job := range jobs {
							status, _ := job["status"].(string)
							if status != "active" && status != "ready" {
								continue
							}
							if intVal(job["activity_id"], 0) != industry.ActivityReactions {
								continue
							}
							if id := intVal(job["blueprint_type_id"], 0); id != 0 {
								addOwner(id, 1, ch.Name)
							}
						}
					}
				}
			}
		}
		rows, err := d.Research.Research.ReactionFormulas(ctx, search, groupID)
		if err != nil {
			return dbError(c, err)
		}
		type row struct {
			store.ResearchFormula
		}
		typed := make([]row, 0, len(rows))
		for _, r := range rows {
			typed = append(typed, row{r})
		}
		switch sortBy {
		case "owned":
			calc.SortOwnedFirst(typed, func(r row) bool {
				_, ok := ownedQty[r.TypeID]
				return ok
			}, sortDir == "desc")
		case "category":
			calc.SortByGroupName(typed, func(r row) string {
				if r.GroupName == nil {
					return ""
				}
				return *r.GroupName
			}, sortDir == "desc")
		default:
			if sortDir == "desc" {
				for i, j := 0, len(typed)-1; i < j; i, j = i+1, j-1 {
					typed[i], typed[j] = typed[j], typed[i]
				}
			}
		}
		missing := []any{}
		for _, ch := range chars {
			if !hasScope(ch, "esi-characters.read_blueprints.v1") {
				missing = append(missing, map[string]any{"character_id": ch.CharacterID, "name": ch.Name})
			}
		}
		out := make([]any, 0, len(typed))
		for _, r := range typed {
			owners := []any{}
			for _, n := range ownedOwners[r.TypeID] {
				owners = append(owners, n)
			}
			_, isOwned := ownedQty[r.TypeID]
			out = append(out, map[string]any{
				"type_id": r.TypeID, "name": r.Name, "group_id": r.GroupID,
				"category": formulaCategory(r.GroupID),
				"owned": isOwned, "quantity": ownedQty[r.TypeID], "owner_names": owners,
			})
		}
		cats := []any{
			map[string]any{"group_id": 1888, "name": "Composite"},
			map[string]any{"group_id": 1889, "name": "Biochemical"},
			map[string]any{"group_id": 4097, "name": "Hybrid"},
		}
		return Success(c, http.StatusOK, out, map[string]any{
			"has_blueprint_data": hasData, "characters_without_scope": missing,
			"categories": cats,
		})
	}
}

func formulaCategory(groupID *int) string {
	if groupID != nil {
		switch *groupID {
		case 1888:
			return "Composite"
		case 1889:
			return "Biochemical"
		case 4097:
			return "Hybrid"
		}
	}
	return "Other"
}
