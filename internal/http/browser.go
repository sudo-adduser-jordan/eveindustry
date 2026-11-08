package http

import (
	"encoding/json"
	"net/http"
	"strconv"

	"github.com/labstack/echo/v4"

	"github.com/sudo-adduser-jordan/eveindustry/internal/calc"
	"github.com/sudo-adduser-jordan/eveindustry/internal/market"
)

// registerBrowser mounts the manufacturing browser pair.
func registerBrowser(v1 *echo.Group, d Deps) {
	if d.Market == nil || d.Industry == nil || d.Trading == nil {
		return
	}
	calcGroup := v1.Group("/calculators")
	calcGroup.GET("/manufacturing-browser", handleManufacturingBrowse(d))
	calcGroup.POST("/manufacturing-browser", handleManufacturingCalculate2(d))
}

func parseBrowserParams(c echo.Context, verrs validationErrors, isPost bool) (marketGroupID, categoryID *int, meLevel, runs int, structME, facilityTax, sysIdx float64, regionID, page, perPage int) {
	meLevel, runs = 0, 1
	perPage = 100
	page = 1
	regionID = market.DefaultRegion
	if raw := c.QueryParam("market_group_id"); raw != "" {
		v, err := strconv.Atoi(raw)
		if err != nil {
			verrs.add("market_group_id", "The market group id field must be an integer.")
		} else {
			marketGroupID = &v
		}
	}
	if raw := c.QueryParam("category_id"); raw != "" {
		v, err := strconv.Atoi(raw)
		if err != nil {
			verrs.add("category_id", "The category id field must be an integer.")
		} else {
			categoryID = &v
		}
	}
	if raw := c.QueryParam("me_level"); raw != "" {
		v, err := strconv.Atoi(raw)
		if err != nil || v < 0 || v > 10 {
			verrs.add("me_level", "The me level field must be between 0 and 10.")
		} else {
			meLevel = v
		}
	}
	if raw := c.QueryParam("runs"); raw != "" {
		v, err := strconv.Atoi(raw)
		if err != nil || v < 1 || v > 100000 {
			verrs.add("runs", "The runs field must be between 1 and 100000.")
		} else {
			runs = v
		}
	}
	rigTier := c.QueryParam("rig_tier")
	secSpace := c.QueryParam("security_space")
	if secSpace == "" {
		secSpace = "highsec"
	}
	for _, tier := range []string{rigTier} {
		if tier != "" && tier != "none" && tier != "t1" && tier != "t2" {
			verrs.add("rig_tier", "The selected rig tier is invalid.")
		}
	}
	if secSpace != "highsec" && secSpace != "lowsec" && secSpace != "nullsec" {
		verrs.add("security_space", "The selected security space is invalid.")
	}
	structME = calc.ManufacturingME("", rigTier, secSpace)
	if raw := c.QueryParam("facility_tax"); raw != "" {
		v, err := strconv.ParseFloat(raw, 64)
		if err != nil || v < 0 || v > 50 {
			verrs.add("facility_tax", "The facility tax field must be between 0 and 50.")
		} else {
			facilityTax = v
		}
	}
	if raw := c.QueryParam("system_cost_index"); raw != "" {
		v, err := strconv.ParseFloat(raw, 64)
		if err != nil || v < 0 || v > 100 {
			verrs.add("system_cost_index", "The system cost index field must be between 0 and 100.")
		} else {
			sysIdx = v
		}
	}
	if raw := c.QueryParam("price_region_id"); raw != "" {
		v, err := strconv.Atoi(raw)
		if err != nil {
			verrs.add("price_region_id", "The price region id field must be an integer.")
		} else {
			regionID = v
		}
	}
	if raw := c.QueryParam("page"); raw != "" {
		v, err := strconv.Atoi(raw)
		if err != nil || v < 1 {
			verrs.add("page", "The page field must be at least 1.")
		} else {
			page = v
		}
	}
	if raw := c.QueryParam("per_page"); raw != "" {
		v, err := strconv.Atoi(raw)
		if err != nil || v < 1 || v > 200 {
			verrs.add("per_page", "The per page field must be between 1 and 200.")
		} else {
			perPage = v
		}
	}
	return marketGroupID, categoryID, meLevel, runs, structME, facilityTax, sysIdx, regionID, page, perPage
}

// GET /calculators/manufacturing-browser — mirrors BrowserController@browse:
// precomputed costs for a market group with the caller's settings.
func handleManufacturingBrowse(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		verrs := validationErrors{}
		groupID, _, meLevel, runs, structME, facilityTax, sysIdx, regionID, page, perPage := parseBrowserParams(c, verrs, false)
		if len(verrs) > 0 {
			return verrs.invalid(c)
		}
		if groupID == nil {
			verrs.add("market_group_id", "The market group id field is required.")
			return verrs.invalid(c)
		}
		ctx := c.Request().Context()
		desc, err := d.Industry.MarketGroupDescendants(ctx, *groupID)
		if err != nil {
			return dbError(c, err)
		}
		typeIDs, err := d.Industry.PublishedTypesInGroups(ctx, append(desc, *groupID))
		if err != nil {
			return dbError(c, err)
		}
		rows, total, err := d.Industry.PrecomputedCosts(ctx, typeIDs, regionID, meLevel, perPage, (page-1)*perPage)
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
				"portion_size": r.PortionSize, "total_output": adj.TotalOutput,
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
					"active_days": r.SellScoreActiveDays, "total_days": r.SellScoreTotalDays,
				},
			})
		}
		return Success(c, http.StatusOK, out, map[string]any{
			"effective_structure_me_bonus": calc.Round2(structME),
			"freshness": freshnessFor(c, d, regionID),
			"pagination": pagination,
		})
	}
}

// POST /calculators/manufacturing-browser — mirrors BrowserController@calculate:
// live profitability for a market group or legacy category.
func handleManufacturingCalculate2(d Deps) echo.HandlerFunc {
	type input struct {
		Runs            *int     `json:"runs"`
		MELLevel        *int     `json:"me_level"`
		RigTier         *string  `json:"rig_tier"`
		SecuritySpace   *string  `json:"security_space"`
		FacilityTax     *float64 `json:"facility_tax"`
		SystemCostIndex *float64 `json:"system_cost_index"`
		PriceRegionID   *int     `json:"price_region_id"`
		MarketGroupID   *int     `json:"market_group_id"`
		CategoryID      *int     `json:"category_id"`
		Page            *int     `json:"page"`
		PerPage         *int     `json:"per_page"`
	}
	return func(c echo.Context) error {
		var in input
		if err := json.NewDecoder(c.Request().Body).Decode(&in); err != nil {
			verrs := validationErrors{}
			verrs.add("body", "The request body must be valid JSON.")
			return verrs.invalid(c)
		}
		verrs := validationErrors{}
		runs, _ := intField(verrs, "runs", in.Runs, 1, 100000, true)
		meLevel := 0
		if in.MELLevel != nil {
			var ok bool
			if meLevel, ok = intField(verrs, "me_level", in.MELLevel, 0, 10, true); !ok {
				meLevel = 0
			}
		}
		rigTier := ""
		if in.RigTier != nil {
			rigTier = *in.RigTier
			if rigTier != "none" && rigTier != "t1" && rigTier != "t2" {
				verrs.add("rig_tier", "The selected rig tier is invalid.")
			}
		}
		secSpace := "highsec"
		if in.SecuritySpace != nil {
			secSpace = *in.SecuritySpace
			if secSpace != "highsec" && secSpace != "lowsec" && secSpace != "nullsec" {
				verrs.add("security_space", "The selected security space is invalid.")
			}
		}
		facilityTax, _ := numField(verrs, "facility_tax", in.FacilityTax, 0, 50)
		sysIdx, _ := numField(verrs, "system_cost_index", in.SystemCostIndex, 0, 100)
		regionID := market.DefaultRegion
		if in.PriceRegionID != nil {
			regionID = *in.PriceRegionID
		}
		page, perPage := 1, 100
		if in.Page != nil {
			if *in.Page < 1 {
				verrs.add("page", "The page field must be at least 1.")
			} else {
				page = *in.Page
			}
		}
		if in.PerPage != nil {
			if *in.PerPage < 1 || *in.PerPage > 200 {
				verrs.add("per_page", "The per page field must be between 1 and 200.")
			} else {
				perPage = *in.PerPage
			}
		}
		if in.MarketGroupID == nil && in.CategoryID == nil {
			verrs.add("market_group_id", "Either market_group_id or category_id is required.")
		}
		if len(verrs) > 0 {
			return verrs.invalid(c)
		}
		ctx := c.Request().Context()
		structME := calc.ManufacturingME("", rigTier, secSpace)

		acts, err := d.Industry.AllManufacturingActivities(ctx)
		if err != nil {
			return dbError(c, err)
		}
		invented, err := d.Industry.InventedBlueprintIDs(ctx)
		if err != nil {
			return dbError(c, err)
		}
		var allowed map[int]bool
		if in.MarketGroupID != nil {
			desc, err := d.Industry.MarketGroupDescendants(ctx, *in.MarketGroupID)
			if err != nil {
				return dbError(c, err)
			}
			ids, err := d.Industry.PublishedTypesInGroups(ctx, append(desc, *in.MarketGroupID))
			if err != nil {
				return dbError(c, err)
			}
			allowed = map[int]bool{}
			for _, id := range ids {
				allowed[id] = true
			}
		} else {
			groups, err := d.Industry.GroupsInCategory(ctx, *in.CategoryID)
			if err != nil {
				return dbError(c, err)
			}
			// Product type ids whose group is in the category.
			var productIDs []int
			seen := map[int]bool{}
			for _, a := range acts {
				for _, p := range a.Products {
					if id := intVal(p["type_id"], 0); id != 0 && !seen[id] {
						seen[id] = true
						productIDs = append(productIDs, id)
					}
				}
			}
			metas, err := d.Industry.TypeGroupMeta(ctx, productIDs)
			if err != nil {
				return dbError(c, err)
			}
			inCat := map[int]bool{}
			for _, g := range groups {
				inCat[g] = true
			}
			allowed = map[int]bool{}
			for _, m := range metas {
				if m.GroupID.Valid && inCat[int(m.GroupID.Int64)] {
					allowed[m.TypeID] = true
				}
			}
		}

		// Exclusion set over all product types.
		var allProductIDs []int
		seen := map[int]bool{}
		for _, a := range acts {
			for _, p := range a.Products {
				if id := intVal(p["type_id"], 0); id != 0 && !seen[id] {
					seen[id] = true
					allProductIDs = append(allProductIDs, id)
				}
			}
		}
		metas, err := d.Industry.TypeGroupMeta(ctx, allProductIDs)
		if err != nil {
			return dbError(c, err)
		}
		groupIDs := map[int]bool{}
		for _, m := range metas {
			if m.GroupID.Valid {
				groupIDs[int(m.GroupID.Int64)] = true
			}
		}
		gids := make([]int, 0, len(groupIDs))
		for g := range groupIDs {
			gids = append(gids, g)
		}
		groupCats, err := d.Industry.GroupCategories(ctx, gids)
		if err != nil {
			return dbError(c, err)
		}
		excluded := calc.ExcludedBrowserProducts(metas, groupCats)

		names := map[int]string{}
		bpIDs := make([]int, 0, len(acts))
		for _, a := range acts {
			bpIDs = append(bpIDs, a.BlueprintTypeID)
		}
		if len(bpIDs) > 0 {
			if n, err := d.Industry.TypeNames(ctx, bpIDs); err != nil {
				return dbError(c, err)
			} else {
				names = n
			}
		}
		items := calc.FilterManufacturingBlueprints(acts, invented, allowed, excluded, names)

		// Prices + histories.
		typeIDs := []int{}
		seen2 := map[int]bool{}
		add := func(id int) {
			if id > 0 && !seen2[id] {
				seen2[id] = true
				typeIDs = append(typeIDs, id)
			}
		}
		for _, it := range items {
			for _, m := range it.Materials {
				add(m.TypeID)
			}
			add(it.Products[0].TypeID)
		}
		resolved, err := resolvePrices(ctx, d.Market, typeIDs, regionID)
		if err != nil {
			return dbError(c, err)
		}
		prices := make(map[int]float64, len(resolved))
		for id, r := range resolved {
			prices[id] = r.Price
		}
		histories := map[int]calc.SellScoreInput{}
		for _, it := range items {
			pid := it.Products[0].TypeID
			if _, ok := histories[pid]; ok {
				continue
			}
			rows, err := d.Market.History(ctx, pid, regionID, 30)
			if err != nil {
				return dbError(c, err)
			}
			var vols, ords []uint64
			for _, r := range rows {
				vols = append(vols, r.Volume)
				ords = append(ords, r.OrderCount)
			}
			histories[pid] = calc.SellScoreInput{Volumes: vols, OrderCounts: ords}
		}

		results := calc.CalculateManufacturingBrowser(calc.BrowserManufacturingInput{
			Items: items, Prices: prices, Histories: histories,
			Runs: runs, MELLevel: meLevel, StructureME: structME,
			FacilityTax: facilityTax, SystemCostIdx: sysIdx,
		})
		offset, pagination := paginateService(len(results), page, perPage)
		end := offset + perPage
		if end > len(results) {
			end = len(results)
		}
		out := make([]any, 0, end-offset)
		for _, r := range results[offset:end] {
			out = append(out, manufacturingBrowserShape(r, nil))
		}
		return Success(c, http.StatusOK, out, map[string]any{
			"effective_structure_me_bonus": calc.Round2(structME),
			"freshness": freshnessFor(c, d, regionID),
			"pagination": pagination,
		})
	}
}

func manufacturingBrowserShape(r calc.ManufacturingBrowserItem, base map[string]any) map[string]any {
	m := map[string]any{
		"blueprint_type_id": r.BlueprintTypeID, "blueprint_name": r.BlueprintName,
		"product_type_id": r.ProductTypeID, "product_name": r.ProductName,
		"product_icon_url": r.ProductIconURL, "portion_size": r.PortionSize,
		"total_output": r.TotalOutput, "total_input_cost": r.TotalInputCost,
		"total_output_value": r.TotalOutputValue, "job_fee": r.JobFee,
		"facility_tax": r.FacilityTax, "total_job_cost": r.TotalJobCost,
		"scc_fee": r.SCCFee, "profit": r.Profit, "profit_positive": r.ProfitPositive,
		"profit_per_unit": r.ProfitPerUnit, "profit_margin": r.ProfitMargin,
		"sell_score": map[string]any{
			"score": r.SellScore.Score, "avg_daily_volume": r.SellScore.AvgDailyVolume,
			"avg_order_count": r.SellScore.AvgOrderCount,
			"active_days": r.SellScore.ActiveDays, "total_days": r.SellScore.TotalDays,
		},
	}
	if base != nil {
		m["base"] = base
	}
	return m
}

// paginateService mirrors the service-level clamp: page into [1, last_page].
func paginateService(total, page, perPage int) (int, map[string]any) {
	lastPage := (total + perPage - 1) / perPage
	if lastPage < 1 {
		lastPage = 1
	}
	if page > lastPage {
		page = lastPage
	}
	if page < 1 {
		page = 1
	}
	return (page - 1) * perPage, map[string]any{
		"page": page, "per_page": perPage, "total": total, "last_page": lastPage,
	}
}
