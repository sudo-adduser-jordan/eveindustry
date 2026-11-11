package http

import (
	"net/http"
	"strconv"

	"github.com/labstack/echo/v4"

	"github.com/sudo-adduser-jordan/eveindustry/internal/industry"
	"github.com/sudo-adduser-jordan/eveindustry/internal/market"
)

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

func handleCorporationSearch(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		verrs := validationErrors{}
		if _, ok := parseSearchTerm(c, verrs); !ok {
			return verrs.invalid(c)
		}
		return Success(c, http.StatusOK, []any{}, map[string]any{"limit": industry.CorporationSearchLimit})
	}
}
