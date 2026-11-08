package http

import (
	"context"
	"encoding/json"
	"net/http"

	"github.com/labstack/echo/v4"

	"github.com/sudo-adduser-jordan/eveindustry/internal/auth"
)

// StockReader is the ESI subset the stock endpoints need. *esi.CharacterClient
// satisfies it; tests stub it.
type StockReader interface {
	GetAssets(ctx context.Context, characterID int64) ([]map[string]any, error)
	GetBlueprints(ctx context.Context, characterID int64) ([]map[string]any, error)
	GetIndustryJobs(ctx context.Context, characterID int64, includeCompleted bool) ([]map[string]any, error)
}

// StockDeps carries the reader; nil keeps the endpoints unregistered until
// the ESI phase wires it.
type StockDeps struct {
	Characters StockReader
}

// registerStock mounts the three stock endpoints (manufacturing, reaction
// planner asset + formula stock). All are per-user ESI reads.
func registerStock(v1 *echo.Group, d Deps) {
	if d.Stock.Characters == nil || d.Users == nil {
		return
	}
	mfg := v1.Group("/calculators")
	mfg.POST("/manufacturing/stock", handleAssetStock(d))
	mfg.POST("/reaction-planner/stock", handleAssetStock(d))
	mfg.POST("/reaction-planner/formula-stock", handleFormulaStock(d))
}

const assetsScope = "esi-assets.read_assets.v1"
const blueprintsScope = "esi-characters.read_blueprints.v1"
const industryJobsScope = "esi-industry.read_character_jobs.v1"

// ESI_ACTIVITY_REACTIONS mirrors ReactionFormulaResearchService.
const esiActivityReactions = 9

func hasScope(ch auth.Character, scope string) bool {
	for _, s := range ch.Scopes {
		if s == scope {
			return true
		}
	}
	return false
}

// assetStock ports CharacterStockService::assetStock(): summed quantities
// per requested type over the user's in-scope characters; characters whose
// ESI call fails are skipped.
func assetStock(ctx context.Context, d Deps, chars []auth.Character, typeIDs []int) map[int]int {
	want := map[int]bool{}
	for _, id := range typeIDs {
		want[id] = true
	}
	stock := map[int]int{}
	for _, ch := range chars {
		if !hasScope(ch, assetsScope) {
			continue
		}
		assets, err := d.Stock.Characters.GetAssets(ctx, ch.CharacterID)
		if err != nil {
			continue
		}
		for _, a := range assets {
			id := intVal(a["type_id"], 0)
			qty := intVal(a["quantity"], 0)
			if id != 0 && qty > 0 && want[id] {
				stock[id] += qty
			}
		}
	}
	return stock
}

// stockLevels ports CharacterStockService::levels(): one row per requested
// id in request order, duplicates dropped, 0 when missing.
func stockLevels(typeIDs []int, stock map[int]int) []any {
	seen := map[int]bool{}
	out := []any{}
	for _, id := range typeIDs {
		if seen[id] {
			continue
		}
		seen[id] = true
		out = append(out, map[string]any{"type_id": id, "quantity": stock[id]})
	}
	return out
}

func parseTypeIDsBody(c echo.Context, verrs validationErrors, field string) []int {
	var in map[string]any
	if err := json.NewDecoder(c.Request().Body).Decode(&in); err != nil {
		verrs.add("body", "The request body must be valid JSON.")
		return nil
	}
	raw, ok := in[field]
	if !ok || raw == nil {
		verrs.add(field, "The "+field+" field is required.")
		return nil
	}
	list, ok := raw.([]any)
	if !ok || len(list) == 0 {
		verrs.add(field, "The "+field+" field must be a non-empty array.")
		return nil
	}
	out := []int{}
	for _, v := range list {
		n, ok := v.(float64)
		if !ok || n != float64(int(n)) {
			verrs.add(field, "Each entry must be an integer.")
			return nil
		}
		out = append(out, int(n))
	}
	return out
}

// POST /calculators/{manufacturing|reaction-planner}/stock — mirrors the
// stock actions: owned quantity per requested type id.
func handleAssetStock(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		verrs := validationErrors{}
		typeIDs := parseTypeIDsBody(c, verrs, "type_ids")
		if len(verrs) > 0 {
			return verrs.invalid(c)
		}
		uid := c.Get(string(ctxUIDKey)).(int64)
		chars, err := d.Users.CharactersForUser(c.Request().Context(), uid)
		if err != nil {
			return dbError(c, err)
		}
		return Success(c, http.StatusOK, stockLevels(typeIDs, assetStock(c.Request().Context(), d, chars, typeIDs)), nil)
	}
}

// POST /calculators/reaction-planner/formula-stock — mirrors
// ReactionPlannerController@formulaStock: owned formulas from blueprints
// plus active/ready reaction jobs; has_blueprint_scope false yields [].
func handleFormulaStock(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		verrs := validationErrors{}
		formulaIDs := parseTypeIDsBody(c, verrs, "formula_type_ids")
		if len(verrs) > 0 {
			return verrs.invalid(c)
		}
		uid := c.Get(string(ctxUIDKey)).(int64)
		ctx := c.Request().Context()
		chars, err := d.Users.CharactersForUser(ctx, uid)
		if err != nil {
			return dbError(c, err)
		}
		hasBP := false
		for _, ch := range chars {
			if hasScope(ch, blueprintsScope) {
				hasBP = true
				break
			}
		}
		formulas := []any{}
		if hasBP {
			formulas = stockLevels(formulaIDs, formulaQuantities(ctx, d, chars, formulaIDs))
		}
		return Success(c, http.StatusOK, map[string]any{
			"has_blueprint_scope": hasBP,
			"formulas":            formulas,
		}, nil)
	}
}

// formulaQuantities ports getOwnedFormulas() restricted to the requested
// ids: inventory blueprint quantities (max(0, qty)) plus +1 per
// active/ready reaction job locking that formula.
func formulaQuantities(ctx context.Context, d Deps, chars []auth.Character, formulaIDs []int) map[int]int {
	want := map[int]bool{}
	for _, id := range formulaIDs {
		want[id] = true
	}
	stock := map[int]int{}
	for _, ch := range chars {
		// is_esi_active defaults true in the DB; only a known-inactive
		// character skips the blueprints call (PHP's !== false check).
		if hasScope(ch, blueprintsScope) && ch.IsEsiActive {
			if bps, err := d.Stock.Characters.GetBlueprints(ctx, ch.CharacterID); err == nil {
				for _, bp := range bps {
					id := intVal(bp["type_id"], 0)
					if !want[id] {
						continue
					}
					if q := intVal(bp["quantity"], 1); q > 0 {
						stock[id] += q
					}
				}
			}
		}
		if hasScope(ch, industryJobsScope) {
			if jobs, err := d.Stock.Characters.GetIndustryJobs(ctx, ch.CharacterID, false); err == nil {
				for _, job := range jobs {
					status, _ := job["status"].(string)
					if status != "active" && status != "ready" {
						continue
					}
					if intVal(job["activity_id"], 0) != esiActivityReactions {
						continue
					}
					if id := intVal(job["blueprint_type_id"], 0); want[id] && id != 0 {
						stock[id]++
					}
				}
			}
		}
	}
	return stock
}
