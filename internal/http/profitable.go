package http

import (
	"net/http"

	"github.com/labstack/echo/v4"

	"github.com/sudo-adduser-jordan/eveindustry/internal/calc"
	"github.com/sudo-adduser-jordan/eveindustry/internal/market"
)

func registerMostProfitable(v1 *echo.Group, d Deps) {
	if d.Market == nil || d.Industry == nil {
		return
	}
	v1.GET("/industry/most-profitable", handleMostProfitable(d))
}

func handleMostProfitable(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		verrs := validationErrors{}
		_, _, meLevel, runs, structME, facilityTax, sysIdx, regionID, page, perPage := parseBrowserParams(c, verrs, false)
		if len(verrs) > 0 {
			return verrs.invalid(c)
		}
		if !market.IsTradeRegion(regionID) {
			verrs.add("price_region_id", "The selected price region id is invalid.")
			return verrs.invalid(c)
		}
		search := c.QueryParam("q")
		if len([]rune(search)) > 100 {
			verrs.add("q", "The q field must not be greater than 100 characters.")
			return verrs.invalid(c)
		}
		onlyProfitable := true
		if raw := c.QueryParam("only_profitable"); raw == "0" || raw == "false" || raw == "no" {
			onlyProfitable = false
		}
		ctx := c.Request().Context()
		rows, total, err := d.Industry.TopPrecomputedCosts(ctx, regionID, meLevel, perPage, (page-1)*perPage, search, onlyProfitable)
		if err != nil {
			return dbError(c, err)
		}
		_, pagination := paginateService(total, page, perPage)
		out := make([]any, 0, len(rows))
		for _, r := range rows {
			adj := calc.ApplyPrecomputedAdjustments(r.InputCost, r.OutputValue, r.PortionSize, runs, structME, facilityTax, sysIdx)
			out = append(out, map[string]any{
				"blueprint_type_id": r.BlueprintTypeID, "blueprint_name": r.BlueprintName,
				"product_type_id": r.ProductTypeID, "product_name": r.ProductName,
				"product_icon_url": market.TypeImageURL(r.ProductTypeID),
				"portion_size":     r.PortionSize, "total_output": adj.TotalOutput,
				"total_input_cost": adj.TotalInputCost, "total_output_value": adj.TotalOutputValue,
				"job_fee": adj.JobFee, "facility_tax": adj.FacilityTax, "total_job_cost": adj.TotalJobCost,
				"scc_fee": adj.SCCFee, "profit": adj.Profit, "profit_positive": adj.ProfitPositive,
				"profit_per_unit": adj.ProfitPerUnit, "profit_margin": adj.ProfitMargin,
				"base": map[string]any{
					"input_cost": r.InputCost, "output_value": r.OutputValue,
					"profit": r.BaseProfit, "profit_margin": r.ProfitMargin,
					"profit_positive": r.BaseProfit >= 0,
				},
				"sell_score": map[string]any{
					"score": r.SellScore, "avg_daily_volume": r.SellScoreAvgDailyVolume,
					"avg_order_count": r.SellScoreAvgOrderCount,
					"active_days":     r.SellScoreActiveDays, "total_days": r.SellScoreTotalDays,
				},
			})
		}
		return Success(c, http.StatusOK, out, map[string]any{
			"effective_structure_me_bonus": calc.Round2(structME),
			"freshness":                    freshnessFor(c, d, regionID),
			"pagination":                   pagination,
		})
	}
}
