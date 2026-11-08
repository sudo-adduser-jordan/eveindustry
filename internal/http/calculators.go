package http

import (
	"context"
	"encoding/json"
	"net/http"

	"github.com/labstack/echo/v4"

	"github.com/sudo-adduser-jordan/eveindustry/internal/calc"
	"github.com/sudo-adduser-jordan/eveindustry/internal/industry"
	"github.com/sudo-adduser-jordan/eveindustry/internal/market"
)

// registerCalculators mounts the stateless calculator POSTs. The skills and
// stock endpoints need an authenticated character, so they stay on PHP until
// the auth phase.
func registerCalculators(v1 *echo.Group, d Deps) {
	if d.Market == nil || d.Industry == nil || d.Trading == nil {
		return
	}
	calcGroup := v1.Group("/calculators")
	calcGroup.POST("/manufacturing", handleManufacturingCalculate(d))
	calcGroup.POST("/manufacturing/queue-summary", handleQueueSummary)
	calcGroup.POST("/reactions", handleReactionCalculate(d))
}

// manufacturingInput mirrors ManufacturingCalculateRequest rules.
type manufacturingInput struct {
	BlueprintTypeID       *int             `json:"blueprint_type_id"`
	ProductTypeID         *int             `json:"product_type_id"`
	Runs                  *int             `json:"runs"`
	MELLevel              *int             `json:"me_level"`
	TELevel               *int             `json:"te_level"`
	IndustrySkill         *int             `json:"industry_skill"`
	AdvancedIndustrySkill *int             `json:"advanced_industry_skill"`
	StructureMEBonus      *float64         `json:"structure_me_bonus"`
	StructureTEBonus      *float64         `json:"structure_te_bonus"`
	ReactionMEBonus       *float64         `json:"reaction_me_bonus"`
	Materials             []postedMaterial `json:"materials"`
	BaseTime              *int             `json:"base_time"`
	PortionSize           *int             `json:"portion_size"`
	RegionID              *int             `json:"region_id"`
	BuybackPercentage     *float64         `json:"buyback_percentage"`
	SolarSystemID         *int             `json:"solar_system_id"`
	TaxRate               *float64         `json:"tax_rate"`
	ReactionSolarSystemID *int             `json:"reaction_solar_system_id"`
	ReactionTaxRate       *float64         `json:"reaction_tax_rate"`
}

type postedMaterial struct {
	TypeID   *int    `json:"type_id"`
	Name     *string `json:"name"`
	Quantity *int    `json:"quantity"`
}

func intField(verrs validationErrors, field string, v *int, min, max int, required bool) (int, bool) {
	if v == nil {
		if required {
			verrs.add(field, "The "+field+" field is required.")
		}
		return 0, false
	}
	if *v < min || *v > max {
		verrs.add(field, "The "+field+" field is out of range.")
		return 0, false
	}
	return *v, true
}

func numField(verrs validationErrors, field string, v *float64, min, max float64) (float64, bool) {
	if v == nil {
		return 0, true
	}
	if *v < min || *v > max {
		verrs.add(field, "The "+field+" field is out of range.")
		return 0, false
	}
	return *v, true
}

// POST /calculators/manufacturing — mirrors ManufacturingController@calculate.
// SDE auto-resolves materials/time/portion when not posted; a blueprint with
// no materials is a 422 on blueprint_type_id. Live ESI inputs (regional
// price seeding, adjusted prices, cost indices) are skipped until the ESI
// phase: prices resolve from local tables and fee blocks compute to zero.
func handleManufacturingCalculate(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		var in manufacturingInput
		if err := json.NewDecoder(c.Request().Body).Decode(&in); err != nil {
			verrs := validationErrors{}
			verrs.add("body", "The request body must be valid JSON.")
			return verrs.invalid(c)
		}
		verrs := validationErrors{}
		blueprintID, _ := intField(verrs, "blueprint_type_id", in.BlueprintTypeID, 1, 1<<31-1, true)
		productID, _ := intField(verrs, "product_type_id", in.ProductTypeID, 1, 1<<31-1, true)
		runs, _ := intField(verrs, "runs", in.Runs, 1, 100000, true)
		meLevel, _ := intField(verrs, "me_level", in.MELLevel, 0, 10, true)
		teLevel, _ := intField(verrs, "te_level", in.TELevel, 0, 20, true)
		indSkill, _ := intField(verrs, "industry_skill", in.IndustrySkill, 0, 5, true)
		advSkill, _ := intField(verrs, "advanced_industry_skill", in.AdvancedIndustrySkill, 0, 5, true)
		structME, _ := numField(verrs, "structure_me_bonus", in.StructureMEBonus, 0, 10)
		structTE, _ := numField(verrs, "structure_te_bonus", in.StructureTEBonus, 0, 55)
		reactionME, _ := numField(verrs, "reaction_me_bonus", in.ReactionMEBonus, 0, 10)
		buybackPct, _ := numField(verrs, "buyback_percentage", in.BuybackPercentage, 0, 100)
		taxRate, _ := numField(verrs, "tax_rate", in.TaxRate, 0, 100)
		reactionTaxRate, _ := numField(verrs, "reaction_tax_rate", in.ReactionTaxRate, 0, 100)
		for i, m := range in.Materials {
			prefix := "materials"
			if m.TypeID == nil {
				verrs.add(prefix, "Material rows need a type id.")
			}
			if m.Name == nil {
				verrs.add(prefix, "Material rows need a name.")
			}
			if m.Quantity == nil || *m.Quantity < 0 {
				verrs.add(prefix, "Material quantities must be at least 0.")
			}
			_ = i
		}
		regionID := market.DefaultRegion
		if in.RegionID != nil {
			regionID = *in.RegionID
		}
		portionSize := 1
		if in.PortionSize != nil {
			if *in.PortionSize < 1 {
				verrs.add("portion_size", "The portion size field must be at least 1.")
			} else {
				portionSize = *in.PortionSize
			}
		}
		baseTime := 0
		if in.BaseTime != nil {
			if *in.BaseTime < 0 {
				verrs.add("base_time", "The base time field must be at least 0.")
			} else {
				baseTime = *in.BaseTime
			}
		}
		if len(verrs) > 0 {
			return verrs.invalid(c)
		}
		ctx := c.Request().Context()

		// Auto-resolve materials/time/portion from the SDE activity.
		baseMaterials := make([]calc.BaseMaterial, 0, len(in.Materials))
		for _, m := range in.Materials {
			name := ""
			if m.Name != nil {
				name = *m.Name
			}
			baseMaterials = append(baseMaterials, calc.BaseMaterial{TypeID: *m.TypeID, Name: name, Quantity: *m.Quantity})
		}
		if len(baseMaterials) == 0 || baseTime == 0 {
			row, err := d.Industry.ActivityByBlueprint(ctx, blueprintID, industry.ActivityManufacturing)
			if err != nil {
				return dbError(c, err)
			}
			if row != nil {
				if len(baseMaterials) == 0 {
					for _, m := range industry.DecodeJSONList(nullStrPtr(row.Materials)) {
						name, _ := m["name"].(string)
						baseMaterials = append(baseMaterials, calc.BaseMaterial{
							TypeID: intVal(m["type_id"], 0), Name: name, Quantity: intVal(m["quantity"], 0),
						})
					}
				}
				if baseTime == 0 {
					baseTime = row.Time
				}
				if portionSize == 1 {
					for _, p := range industry.DecodeJSONList(nullStrPtr(row.Products)) {
						if q := intVal(p["quantity"], 0); q > 0 {
							portionSize = q
							break
						}
					}
				}
			}
		}
		if len(baseMaterials) == 0 {
			verrs.add("blueprint_type_id", "No materials found for this blueprint.")
			return verrs.invalid(c)
		}

		materials := calc.CalculateMaterials(baseMaterials, meLevel, runs, structME)
		jobTime := calc.CalculateProductionTime(baseTime, teLevel, runs, indSkill, advSkill, structTE)

		// Component build data at ME 10, like the PHP planner call.
		reactionMEBonus := reactionME
		if in.ReactionMEBonus == nil {
			reactionMEBonus = structME
		}
		components, err := resolveComponents(ctx, d.Industry, materials, structME, reactionMEBonus)
		if err != nil {
			return dbError(c, err)
		}

		// Prices for materials, product and sub-materials.
		typeIDs := []int{productID}
		for _, m := range materials {
			typeIDs = append(typeIDs, m.TypeID)
		}
		for _, comp := range components {
			for _, sub := range comp.Materials {
				typeIDs = append(typeIDs, sub.TypeID)
			}
		}
		resolved, err := resolvePrices(ctx, d.Market, typeIDs, regionID)
		if err != nil {
			return dbError(c, err)
		}
		prices := make(map[int]calc.PricedValue, len(resolved))
		for id, r := range resolved {
			prices[id] = calc.PricedValue{Price: r.Price, Source: r.Source}
		}
		typeVols, err := d.Trading.ArbitrageTypes(ctx, typeIDs)
		if err != nil {
			return dbError(c, err)
		}
		volumes := make(map[int]float64, len(typeVols))
		for id, tv := range typeVols {
			volumes[id] = tv.VolumeM3
		}
		multiplier := 1.0
		if in.BuybackPercentage != nil {
			multiplier = buybackPct / 100
		}

		result := calc.CalculateJob(calc.JobInput{
			Materials: materials, ProductTypeID: productID, Runs: runs, PortionSize: portionSize,
			Prices: prices, Volumes: volumes, Components: components,
			BuybackMultiplier: multiplier,
			AdjustedPrices:    map[int]float64{},
			SciManufacturing:  0, SciReaction: 0,
			TaxRate: taxRate, ReactionTaxRate: reactionTaxRate,
			HasMainSystem: in.SolarSystemID != nil, HasReactionSystem: in.ReactionSolarSystemID != nil,
		}, jobTime)

		return Success(c, http.StatusOK, manufacturingPayload(result), map[string]any{
			"freshness": freshnessFor(c, d, regionID),
		})
	}
}

// manufacturingPayload shapes a calc.JobResult like ManufacturingJobResource.
func manufacturingPayload(r calc.JobResult) map[string]any {
	mats := make([]any, 0, len(r.Materials))
	for _, m := range r.Materials {
		var comp any
		if m.Component != nil {
			subs := make([]any, 0, len(m.Component.Materials))
			for _, s := range m.Component.Materials {
				subs = append(subs, map[string]any{
					"type_id": s.TypeID, "name": s.Name,
					"base_quantity": s.BaseQuantity, "adjusted_quantity": s.AdjustedQuantity,
					"quantity_per_run": s.QuantityPerRun, "unit_price": s.UnitPrice,
					"total_cost": s.TotalCost, "volume": s.Volume,
				})
			}
			comp = map[string]any{
				"is_buildable": true, "blueprint_type_id": m.Component.BlueprintTypeID,
				"portion_size": m.Component.PortionSize, "runs_needed": m.Component.RunsNeeded,
				"base_time": m.Component.BaseTime, "materials": subs, "build_cost": m.Component.BuildCost,
			}
		}
		mats = append(mats, map[string]any{
			"type_id": m.TypeID, "name": m.Name,
			"base_quantity": m.BaseQuantity, "adjusted_quantity": m.AdjustedQuantity,
			"quantity_per_run": m.QuantityPerRun, "unit_price": m.UnitPrice,
			"total_cost": m.TotalCost, "price_source": m.PriceSource, "volume": m.Volume,
			"component": comp,
		})
	}
	cp := r.CostProfit
	return map[string]any{
		"product": map[string]any{
			"sell_price": cp.ProductSellPrice, "price_source": cp.ProductPriceSource,
			"total_output": cp.TotalOutput, "total_revenue": cp.TotalRevenue,
		},
		"time": map[string]any{
			"base_time_seconds":    r.Time.BaseTimeSeconds,
			"time_per_run_seconds": r.Time.TimePerRunSeconds,
			"total_time_seconds":   r.Time.TotalTimeSeconds,
		},
		"materials":     mats,
		"build_cheaper": r.BuildCheaper,
		"scc_fee":       r.SCCFee,
		"build_fee": map[string]any{
			"eiv": r.BuildFee.EIV, "sci": r.BuildFee.SCI, "job_cost": r.BuildFee.JobCost,
			"tax_rate": r.BuildFee.TaxRate, "facility_tax": r.BuildFee.FacilityTax,
			"total": r.BuildFee.Total, "components_fee": r.BuildFee.ComponentsFee,
			"total_full_build": r.BuildFee.TotalFullBuild, "has_data": r.BuildFee.HasData,
		},
		"scenarios": map[string]any{
			"full_buy": map[string]any{
				"material_cost": r.TotalBuyCost, "profit": r.ProfitFullBuy, "margin": r.MarginFullBuy,
				"per_unit": r.PerUnitFullBuy, "job_fees": r.BuildFee.Total,
				"total_fees": r.TotalFeeFullBuy, "total_cost": r.TotalCostFullBuyWithFee,
				"profit_after_fees": r.ProfitFullBuyWithFee, "margin_after_fees": r.MarginFullBuyWithFee,
				"per_unit_after_fees": r.PerUnitFullBuyWithFee,
			},
			"full_build": map[string]any{
				"material_cost": r.TotalBuildCost, "profit": r.ProfitFullBuild, "margin": r.MarginFullBuild,
				"per_unit": r.PerUnitFullBuild, "job_fees": r.BuildFee.TotalFullBuild,
				"total_fees": r.TotalFeeFullBuild, "total_cost": r.TotalCostFullBuildWithFee,
				"profit_after_fees": r.ProfitFullBuildWithFee, "margin_after_fees": r.MarginFullBuildWithFee,
				"per_unit_after_fees": r.PerUnitFullBuildWithFee,
			},
		},
		"market_buy": map[string]any{
			"material_cost": cp.TotalMaterialCost, "total_revenue": cp.TotalRevenue,
			"scc_fee": cpProfitSCC(cp), "profit": cp.Profit, "profit_positive": cp.ProfitPositive,
			"profit_per_unit": cp.ProfitPerUnit, "profit_margin": cp.ProfitMargin,
		},
	}
}

func cpProfitSCC(cp calc.CostProfit) float64 { return cp.TotalRevenue * calc.SCCFeeRate }

func intVal(v any, fallback int) int {
	switch n := v.(type) {
	case int:
		return n
	case int64:
		return int(n)
	case float64:
		return int(n)
	}
	return fallback
}

// POST /calculators/reactions — mirrors ReactionsController@calculate.
// SDE auto-resolves materials/time/portion when not posted; a formula with
// no materials is a 422 on formula_type_id.
func handleReactionCalculate(d Deps) echo.HandlerFunc {
	type input struct {
		FormulaTypeID    *int             `json:"formula_type_id"`
		ProductTypeID    *int             `json:"product_type_id"`
		Runs             *int             `json:"runs"`
		ReactionsSkill   *int             `json:"reactions_skill"`
		RigBonus         *float64         `json:"rig_bonus"`
		StructureTEBonus *float64         `json:"structure_te_bonus"`
		Materials        []postedMaterial `json:"materials"`
		BaseTime         *int             `json:"base_time"`
		PortionSize      *int             `json:"portion_size"`
		RegionID         *int             `json:"region_id"`
	}
	return func(c echo.Context) error {
		var in input
		if err := json.NewDecoder(c.Request().Body).Decode(&in); err != nil {
			verrs := validationErrors{}
			verrs.add("body", "The request body must be valid JSON.")
			return verrs.invalid(c)
		}
		verrs := validationErrors{}
		formulaID, _ := intField(verrs, "formula_type_id", in.FormulaTypeID, 1, 1<<31-1, true)
		productID, _ := intField(verrs, "product_type_id", in.ProductTypeID, 1, 1<<31-1, true)
		runs, _ := intField(verrs, "runs", in.Runs, 1, 100000, true)
		skill, _ := intField(verrs, "reactions_skill", in.ReactionsSkill, 0, 5, true)
		rigBonus, _ := numField(verrs, "rig_bonus", in.RigBonus, 0, 10)
		structTE, _ := numField(verrs, "structure_te_bonus", in.StructureTEBonus, 0, 30)
		for _, m := range in.Materials {
			if m.TypeID == nil || m.Name == nil || m.Quantity == nil || *m.Quantity < 0 {
				verrs.add("materials", "Material rows need type id, name and quantity >= 0.")
				break
			}
		}
		regionID := market.DefaultRegion
		if in.RegionID != nil {
			regionID = *in.RegionID
		}
		portionSize := 1
		if in.PortionSize != nil {
			if *in.PortionSize < 1 {
				verrs.add("portion_size", "The portion size field must be at least 1.")
			} else {
				portionSize = *in.PortionSize
			}
		}
		baseTime := 0
		if in.BaseTime != nil {
			if *in.BaseTime < 0 {
				verrs.add("base_time", "The base time field must be at least 0.")
			} else {
				baseTime = *in.BaseTime
			}
		}
		if len(verrs) > 0 {
			return verrs.invalid(c)
		}
		ctx := c.Request().Context()

		base := make([]calc.BaseMaterial, 0, len(in.Materials))
		for _, m := range in.Materials {
			name := ""
			if m.Name != nil {
				name = *m.Name
			}
			base = append(base, calc.BaseMaterial{TypeID: *m.TypeID, Name: name, Quantity: *m.Quantity})
		}
		if len(base) == 0 || baseTime == 0 {
			row, err := d.Industry.ActivityByBlueprint(ctx, formulaID, industry.ActivityReaction)
			if err != nil {
				return dbError(c, err)
			}
			if row != nil {
				if len(base) == 0 {
					for _, m := range industry.DecodeJSONList(nullStrPtr(row.Materials)) {
						name, _ := m["name"].(string)
						base = append(base, calc.BaseMaterial{TypeID: intVal(m["type_id"], 0), Name: name, Quantity: intVal(m["quantity"], 0)})
					}
				}
				if baseTime == 0 {
					baseTime = row.Time
				}
				if portionSize == 1 {
					for _, p := range industry.DecodeJSONList(nullStrPtr(row.Products)) {
						if q := intVal(p["quantity"], 0); q > 0 {
							portionSize = q
							break
						}
					}
				}
			}
		}
		if len(base) == 0 {
			verrs.add("formula_type_id", "No materials found for this reaction formula.")
			return verrs.invalid(c)
		}

		materials := calc.ReactionMaterials(base, runs, rigBonus)
		jobTime := calc.ReactionTime(baseTime, runs, skill, structTE)

		typeIDs := []int{productID}
		for _, m := range materials {
			typeIDs = append(typeIDs, m.TypeID)
		}
		resolved, err := resolvePrices(ctx, d.Market, typeIDs, regionID)
		if err != nil {
			return dbError(c, err)
		}
		prices := make(map[int]calc.PricedValue, len(resolved))
		for id, r := range resolved {
			prices[id] = calc.PricedValue{Price: r.Price, Source: r.Source}
		}
		cp := calc.ReactionCostProfit(materials, productID, runs, portionSize, prices)

		mats := make([]any, 0, len(cp.Materials))
		for _, m := range cp.Materials {
			mats = append(mats, map[string]any{
				"type_id": m.TypeID, "name": m.Name,
				"base_quantity": m.BaseQuantity, "adjusted_quantity": m.AdjustedQuantity,
				"quantity_per_run": m.QuantityPerRun, "unit_price": m.UnitPrice,
				"total_cost": m.TotalCost, "price_source": m.PriceSource,
			})
		}
		return Success(c, http.StatusOK, map[string]any{
			"product": map[string]any{
				"sell_price": cp.ProductSellPrice, "price_source": cp.ProductPriceSource,
				"total_output": cp.TotalOutput, "total_revenue": cp.TotalRevenue,
			},
			"time": map[string]any{
				"base_time_seconds":    jobTime.BaseTimeSeconds,
				"time_per_run_seconds": jobTime.TimePerRunSeconds,
				"total_time_seconds":   jobTime.TotalTimeSeconds,
			},
			"materials": mats,
			"summary": map[string]any{
				"material_cost": cp.TotalMaterialCost, "total_revenue": cp.TotalRevenue,
				"scc_fee": cp.TotalRevenue * calc.SCCFeeRate, "profit": cp.Profit,
				"profit_positive": cp.ProfitPositive, "profit_per_unit": cp.ProfitPerUnit,
				"profit_margin": cp.ProfitMargin,
			},
		}, map[string]any{
			"freshness": freshnessFor(c, d, regionID),
		})
	}
}

// resolveComponents ports InventionPlannerService::resolveComponentBuildData()
// at ME 10 for sub-component blueprints (reactions at ME 0): for each
// material, the manufacturing activity producing it (else the reaction
// formula), portion size, runs needed and ME-adjusted sub-materials.
func resolveComponents(ctx context.Context, st industry.IndustryStore, materials []calc.Material, structureME, reactionME float64) (map[int]*calc.ComponentData, error) {
	components := map[int]*calc.ComponentData{}
	for _, m := range materials {
		act, err := st.ActivityProducingType(ctx, m.TypeID, industry.ActivityManufacturing)
		if err != nil {
			return nil, err
		}
		isReaction := false
		if act == nil {
			act, err = st.ActivityProducingType(ctx, m.TypeID, industry.ActivityReaction)
			if err != nil {
				return nil, err
			}
			isReaction = act != nil
		}
		if act == nil {
			continue
		}
		portion := 1
		for _, p := range industry.DecodeJSONList(nullStrPtr(act.Products)) {
			if intVal(p["type_id"], 0) == m.TypeID {
				if q := intVal(p["quantity"], 0); q > 0 {
					portion = q
				}
				break
			}
		}
		runsNeeded := m.AdjustedQuantity
		if portion > 0 {
			runsNeeded = (m.AdjustedQuantity + portion - 1) / portion
		}
		effME, effBonus := 10, structureME
		if isReaction {
			effME, effBonus = 0, reactionME
		}
		subs := []calc.BaseMaterial{}
		for _, sm := range industry.DecodeJSONList(nullStrPtr(act.Materials)) {
			name, _ := sm["name"].(string)
			subs = append(subs, calc.BaseMaterial{TypeID: intVal(sm["type_id"], 0), Name: name, Quantity: intVal(sm["quantity"], 0)})
		}
		components[m.TypeID] = &calc.ComponentData{
			BlueprintTypeID: act.BlueprintTypeID, IsReaction: isReaction,
			PortionSize: portion, RunsNeeded: runsNeeded, BaseTime: act.Time,
			Materials: calc.CalculateMaterials(subs, effME, runsNeeded, effBonus),
		}
	}
	return components, nil
}

// POST /calculators/manufacturing/queue-summary — mirrors
// ManufacturingController@queueSummary (pure maths, no store needed).
func handleQueueSummary(c echo.Context) error {
	var body struct {
		Items []calc.QueueItem `json:"items"`
	}
	if err := json.NewDecoder(c.Request().Body).Decode(&body); err != nil {
		verrs := validationErrors{}
		verrs.add("body", "The request body must be valid JSON.")
		return verrs.invalid(c)
	}
	verrs := validationErrors{}
	if len(body.Items) == 0 {
		verrs.add("items", "The items field is required.")
		return verrs.invalid(c)
	}
	if len(body.Items) > 500 {
		verrs.add("items", "The items field must not have more than 500 items.")
		return verrs.invalid(c)
	}
	for _, it := range body.Items {
		if it.Revenue < 0 || it.InputCost < 0 || it.Fees < 0 {
			verrs.add("items", "Queue values must be at least 0.")
			break
		}
	}
	if len(verrs) > 0 {
		return verrs.invalid(c)
	}
	rows, totals := calc.Totals(body.Items)
	out := make([]any, 0, len(rows))
	for _, r := range rows {
		out = append(out, r)
	}
	return Success(c, http.StatusOK, map[string]any{"items": out, "totals": totals}, nil)
}
