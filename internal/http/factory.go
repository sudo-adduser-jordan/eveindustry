package http

import (
	"net/http"
	"strconv"

	"github.com/labstack/echo/v4"

	"github.com/sudo-adduser-jordan/eveindustry/internal/market"
	"github.com/sudo-adduser-jordan/eveindustry/internal/pi"
	"github.com/sudo-adduser-jordan/eveindustry/internal/store"
)

// registerFactoryPlanner mounts GET /calculators/factory-planner.
func registerFactoryPlanner(v1 *echo.Group, d Deps) {
	if d.Market == nil {
		return
	}
	v1.Group("/calculators").GET("/factory-planner", handleFactoryPlanner(d))
}

// GET /calculators/factory-planner — mirrors FactoryPlannerController@calculate:
// daily planet figures for every schematic in a tier, after customs tax.
func handleFactoryPlanner(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		verrs := validationErrors{}
		tier := "p2_to_p3"
		if raw := c.QueryParam("tier"); raw != "" {
			if !pi.ValidTier(raw) {
				verrs.add("tier", "The selected tier is invalid.")
			} else {
				tier = raw
			}
		}
		regionID := market.DefaultRegion
		if raw := c.QueryParam("region_id"); raw != "" {
			v, err := strconv.Atoi(raw)
			if err != nil {
				verrs.add("region_id", "The region id field must be an integer.")
			} else {
				regionID = v
			}
		}
		taxRate := 0.0
		if raw := c.QueryParam("tax_rate"); raw != "" {
			v, err := strconv.ParseFloat(raw, 64)
			if err != nil || v < 0 || v > 100 {
				verrs.add("tax_rate", "The tax rate field must be between 0 and 100.")
			} else {
				taxRate = v
			}
		}
		if len(verrs) > 0 {
			return verrs.invalid(c)
		}
		ctx := c.Request().Context()

		rows, err := d.Market.MarketPriceRows(ctx, pi.TierTypeIDs(tier), regionID)
		if err != nil {
			return dbError(c, err)
		}
		prices := make(map[int]pi.PriceRow, len(rows))
		for _, r := range rows {
			prices[r.TypeID] = storePriceRow(r)
		}

		var results []any
		if tier == "p1_to_p3" || tier == "p0_to_p3" {
			for _, r := range pi.CalculateChainTier(tier, prices) {
				results = append(results, applyChainTax(r, taxRate))
			}
		} else {
			for _, r := range pi.ApplyCustomsTax(pi.CalculateSimpleTier(pi.SchematicsForTier(tier), prices), taxRate) {
				results = append(results, r)
			}
		}
		if results == nil {
			results = []any{}
		}
		return Success(c, http.StatusOK, map[string]any{
			"tier": tier, "tier_label": pi.TierLabels[tier],
			"factory_slots": pi.FactorySlots, "tax_rate": taxRate,
			"results": results,
		}, map[string]any{
			"freshness": freshnessFor(c, d, regionID),
		})
	}
}

func storePriceRow(r store.MarketPriceRowFull) pi.PriceRow {
	var buy, sell, avg *float64
	if r.BuyMax.Valid {
		v := r.BuyMax.Float64
		buy = &v
	}
	if r.SellMin.Valid {
		v := r.SellMin.Float64
		sell = &v
	}
	if r.Average.Valid {
		v := r.Average.Float64
		avg = &v
	}
	return pi.PriceRow{BuyMax: buy, SellMin: sell, Average: avg}
}

func applyChainTax(v any, rate float64) any {
	switch r := v.(type) {
	case *pi.P1ToP3Result:
		it, et, tot, after := pi.CustomsTax(r.DailyImportValue, r.DailyExportValue, r.DailyProfitSell, rate)
		r.DailyImportTax, r.DailyExportTax, r.DailyTaxTotal, r.DailyProfitAfterTax = it, et, tot, after
		return r
	case *pi.P0ToP3Result:
		it, et, tot, after := pi.CustomsTax(r.DailyImportValue, r.DailyExportValue, r.DailyProfitSell, rate)
		r.DailyImportTax, r.DailyExportTax, r.DailyTaxTotal, r.DailyProfitAfterTax = it, et, tot, after
		return r
	}
	return v
}
