package http

import (
	"net/http"
	"strconv"

	"github.com/labstack/echo/v4"

	"github.com/sudo-adduser-jordan/eveindustry/internal/industry"
	"github.com/sudo-adduser-jordan/eveindustry/internal/market"
)

// registerIndustryReference mounts the manufacturing/invention side of the
// reference group: /blueprints, /reactions/formulas, /invention/items,
// /manufacturing/categories and /corporations.
func registerIndustryReference(v1 *echo.Group, d Deps) {
	if d.Industry == nil {
		return
	}
	v1.GET("/blueprints", handleBlueprintSearch(d))
	v1.GET("/blueprints/:blueprintTypeId", handleBlueprintShow(d))
	v1.GET("/reactions/formulas", handleFormulaSearch(d))
	v1.GET("/reactions/formulas/:formulaTypeId", handleFormulaShow(d))
	v1.GET("/invention/items", handleInventionSearch(d))
	v1.GET("/invention/items/:typeId", handleInventionShow(d))
	v1.GET("/manufacturing/categories", handleProductCategories(d))
	v1.GET("/corporations", handleCorporationSearch(d))
}

func summaryList(hits []industry.BlueprintTypeHit) []any {
	out := make([]any, 0, len(hits))
	for _, h := range hits {
		out = append(out, map[string]any{
			"type_id":  h.TypeID,
			"name":     h.Name,
			"group_id": nullableInt64(h.GroupID),
			"icon_url": market.TypeImageURL(h.TypeID),
		})
	}
	return out
}

// GET /blueprints?q= — mirrors BlueprintController@index (local SDE only;
// the ESI search fallback needs the ESI client from a later phase).
func handleBlueprintSearch(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		verrs := validationErrors{}
		q, ok := parseSearchTerm(c, verrs)
		if !ok {
			return verrs.invalid(c)
		}
		hits, err := d.Industry.SearchBlueprintTypes(c.Request().Context(),
			"%"+industry.EscapeLike(q)+"%", industry.BlueprintSearchLimit)
		if err != nil {
			return dbError(c, err)
		}
		return Success(c, http.StatusOK, summaryList(hits), map[string]any{"limit": industry.BlueprintSearchLimit})
	}
}

// GET /blueprints/:id — mirrors BlueprintController@show (404 unknown).
// Serves the local blueprint_activities manufacturing row with deduped
// materials; the Fuzzwork SDE fallback needs the ESI-phase HTTP client.
func handleBlueprintShow(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		id, err := strconv.Atoi(c.Param("blueprintTypeId"))
		if err != nil || id < 1 {
			return Failure(c, http.StatusNotFound, "Blueprint not found.", nil)
		}
		row, err := d.Industry.ActivityByBlueprint(c.Request().Context(), id, industry.ActivityManufacturing)
		if err != nil {
			return dbError(c, err)
		}
		if row == nil {
			return Failure(c, http.StatusNotFound, "Blueprint not found.", nil)
		}
		return Success(c, http.StatusOK, map[string]any{
			"type_id":   id,
			"activity":  "manufacturing",
			"time":      row.Time,
			"materials": industry.DedupeMaterials(industry.DecodeJSONList(nullStrPtr(row.Materials))),
			"products":  industry.NormalizeItems(industry.DecodeJSONList(nullStrPtr(row.Products))),
		}, nil)
	}
}

// GET /reactions/formulas?q= — mirrors ReactionFormulaController@index.
func handleFormulaSearch(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		verrs := validationErrors{}
		q, ok := parseSearchTerm(c, verrs)
		if !ok {
			return verrs.invalid(c)
		}
		hits, err := d.Industry.SearchReactionTypes(c.Request().Context(),
			"%"+industry.EscapeLike(q)+"%", industry.ReactionSearchLimit)
		if err != nil {
			return dbError(c, err)
		}
		return Success(c, http.StatusOK, summaryList(hits), map[string]any{"limit": industry.ReactionSearchLimit})
	}
}

// GET /reactions/formulas/:id — mirrors ReactionFormulaController@show.
func handleFormulaShow(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		id, err := strconv.Atoi(c.Param("formulaTypeId"))
		if err != nil || id < 1 {
			return Failure(c, http.StatusNotFound, "Reaction formula not found.", nil)
		}
		row, err := d.Industry.ActivityByBlueprint(c.Request().Context(), id, industry.ActivityReaction)
		if err != nil {
			return dbError(c, err)
		}
		if row == nil {
			return Failure(c, http.StatusNotFound, "Reaction formula not found.", nil)
		}
		return Success(c, http.StatusOK, map[string]any{
			"type_id":   id,
			"activity":  "reaction",
			"time":      row.Time,
			"materials": industry.DedupeMaterials(industry.DecodeJSONList(nullStrPtr(row.Materials))),
			"products":  industry.NormalizeItems(industry.DecodeJSONList(nullStrPtr(row.Products))),
		}, nil)
	}
}

// GET /invention/items?q= — mirrors InventionItemController@index: published
// T2 items with an invention chain whose name matches.
func handleInventionSearch(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		verrs := validationErrors{}
		q, ok := parseSearchTerm(c, verrs)
		if !ok {
			return verrs.invalid(c)
		}
		ctx := c.Request().Context()
		cands, err := d.Industry.SearchT2Candidates(ctx, "%"+industry.EscapeLike(q)+"%")
		if err != nil {
			return dbError(c, err)
		}
		out := []any{}
		for _, cand := range cands {
			if len(out) >= industry.InventionSearchLimit {
				break
			}
			t2mfg, err := d.Industry.ActivityProducingType(ctx, cand.TypeID, industry.ActivityManufacturing)
			if err != nil {
				return dbError(c, err)
			}
			if t2mfg == nil {
				continue
			}
			chain, err := industry.HasTechChain(ctx, d.Industry, t2mfg)
			if err != nil {
				return dbError(c, err)
			}
			if !chain {
				continue
			}
			out = append(out, map[string]any{
				"type_id":  cand.TypeID,
				"name":     cand.Name,
				"group_id": nullableInt64(cand.GroupID),
				"icon_url": market.TypeImageURL(cand.TypeID),
			})
		}
		return Success(c, http.StatusOK, out, map[string]any{"limit": industry.InventionSearchLimit})
	}
}

// GET /invention/items/:typeId — mirrors InventionItemController@show.
func handleInventionShow(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		id, err := strconv.Atoi(c.Param("typeId"))
		if err != nil || id < 1 {
			return Failure(c, http.StatusNotFound, "No invention chain for this item.", nil)
		}
		chain, err := industry.ResolveChain(c.Request().Context(), d.Industry, id)
		if err != nil {
			return dbError(c, err)
		}
		if chain == nil {
			return Failure(c, http.StatusNotFound, "No invention chain for this item.", nil)
		}
		return Success(c, http.StatusOK, chain, nil)
	}
}

// GET /manufacturing/categories — mirrors ManufacturingCategoryController@index.
func handleProductCategories(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		cats, err := industry.ProductCategories(c.Request().Context(), d.Industry)
		if err != nil {
			return dbError(c, err)
		}
		out := make([]any, 0, len(cats))
		for _, cat := range cats {
			out = append(out, map[string]any{
				"category_id": cat.CategoryID,
				"name":        cat.Name,
				"item_count":  cat.ItemCount,
			})
		}
		return Success(c, http.StatusOK, out, nil)
	}
}

// GET /corporations?q= — mirrors CorporationController@index. Without an
// authenticated ESI character (auth phase) there is no one to search as, so
// this returns [] exactly as the PHP endpoint does for a user with no
// character — with the same limit meta.
func handleCorporationSearch(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		verrs := validationErrors{}
		if _, ok := parseSearchTerm(c, verrs); !ok {
			return verrs.invalid(c)
		}
		return Success(c, http.StatusOK, []any{}, map[string]any{"limit": industry.CorporationSearchLimit})
	}
}
