package http

import (
	"encoding/json"
	"net/http"

	"github.com/labstack/echo/v4"

	"github.com/sudo-adduser-jordan/eveindustry/internal/calc"
	"github.com/sudo-adduser-jordan/eveindustry/internal/industry"
	"github.com/sudo-adduser-jordan/eveindustry/internal/market"
)

// registerInvention mounts POST /calculators/invention and the decryptor
// list. The skills endpoint needs an authenticated character, so it stays on
// PHP until the auth phase.
func registerInvention(v1 *echo.Group, d Deps) {
	if d.Market == nil || d.Industry == nil || d.Trading == nil {
		return
	}
	calcGroup := v1.Group("/calculators")
	calcGroup.POST("/invention", handleInventionCalculate(d))
	calcGroup.GET("/invention/decryptors", handleDecryptors)
}

// handleDecryptors mirrors InventionController@decryptors: static list, no store.
func handleDecryptors(c echo.Context) error {
	out := make([]any, 0, len(calc.Decryptors()))
	for _, d := range calc.Decryptors() {
		out = append(out, d)
	}
	return Success(c, http.StatusOK, out, nil)
}

// POST /calculators/invention — mirrors InventionController@calculate: the
// full invention + T2 manufacturing chain. An unresolvable chain is a 422 on
// t2_type_id.
func handleInventionCalculate(d Deps) echo.HandlerFunc {
	type input struct {
		T2TypeID               *int     `json:"t2_type_id"`
		DecryptorKey           *string  `json:"decryptor_key"`
		EncryptionSkill        *int     `json:"encryption_skill"`
		Science1Skill          *int     `json:"science1_skill"`
		Science2Skill          *int     `json:"science2_skill"`
		BaseProbability        *float64 `json:"base_probability"`
		BaseRuns               *int     `json:"base_runs"`
		InventionRuns          *int     `json:"invention_runs"`
		T1ME                   *int     `json:"t1_me"`
		T1TE                   *int     `json:"t1_te"`
		IndustrySkill          *int     `json:"industry_skill"`
		AdvancedIndustrySkill  *int     `json:"advanced_industry_skill"`
		StructureType          *string  `json:"structure_type"`
		RigME                  *string  `json:"rig_me"`
		RigTE                  *string  `json:"rig_te"`
		SystemSecurity         *string  `json:"system_security"`
		ReactionStructureType  *string  `json:"reaction_structure_type"`
		ReactionRigME          *string  `json:"reaction_rig_me"`
		ReactionSystemSecurity *string  `json:"reaction_system_security"`
		FacilityTax            *float64 `json:"facility_tax"`
		SystemCostIndex        *float64 `json:"system_cost_index"`
		PriceRegionID          *int     `json:"price_region_id"`
	}
	oneOf := func(verrs validationErrors, field string, v *string, allowed ...string) (string, bool) {
		if v == nil {
			return "", true
		}
		for _, a := range allowed {
			if *v == a {
				return *v, true
			}
		}
		verrs.add(field, "The selected "+field+" is invalid.")
		return "", false
	}
	return func(c echo.Context) error {
		var in input
		if err := json.NewDecoder(c.Request().Body).Decode(&in); err != nil {
			verrs := validationErrors{}
			verrs.add("body", "The request body must be valid JSON.")
			return verrs.invalid(c)
		}
		verrs := validationErrors{}
		t2ID, _ := intField(verrs, "t2_type_id", in.T2TypeID, 1, 1<<31-1, true)
		encSkill, _ := intField(verrs, "encryption_skill", in.EncryptionSkill, 0, 5, true)
		sci1, _ := intField(verrs, "science1_skill", in.Science1Skill, 0, 5, true)
		sci2, _ := intField(verrs, "science2_skill", in.Science2Skill, 0, 5, true)
		invRuns, _ := intField(verrs, "invention_runs", in.InventionRuns, 1, 10000, true)
		baseProb, _ := numField(verrs, "base_probability", in.BaseProbability, 0, 1)
		baseRuns := 0
		if in.BaseRuns != nil {
			var ok bool
			if baseRuns, ok = intField(verrs, "base_runs", in.BaseRuns, 1, 1000, true); !ok {
				baseRuns = 0
			}
		}
		structType, _ := oneOf(verrs, "structure_type", in.StructureType, "none", "raitaru", "azbel", "sotiyo")
		rigME, _ := oneOf(verrs, "rig_me", in.RigME, "none", "t1", "t2")
		_, _ = oneOf(verrs, "rig_te", in.RigTE, "none", "t1", "t2")
		sysSec, _ := oneOf(verrs, "system_security", in.SystemSecurity, "highsec", "lowsec", "nullsec")
		reactRigME, _ := oneOf(verrs, "reaction_rig_me", in.ReactionRigME, "none", "t1", "t2")
		reactSysSec, _ := oneOf(verrs, "reaction_system_security", in.ReactionSystemSecurity, "highsec", "lowsec", "nullsec")
		facilityTax, _ := numField(verrs, "facility_tax", in.FacilityTax, 0, 50)
		sysCostIdx, _ := numField(verrs, "system_cost_index", in.SystemCostIndex, 0, 100)
		if len(verrs) > 0 {
			return verrs.invalid(c)
		}
		rigTE := ""
		if in.RigTE != nil {
			rigTE = *in.RigTE
		}
		if sysSec == "" {
			sysSec = "highsec"
		}
		ctx := c.Request().Context()

		chain, err := industry.ResolveChain(ctx, d.Industry, t2ID)
		if err != nil {
			return dbError(c, err)
		}
		if chain == nil {
			verrs.add("t2_type_id", "Could not resolve invention chain")
			return verrs.invalid(c)
		}

		decryptor := calc.DecryptorByKey("")
		if in.DecryptorKey != nil {
			decryptor = calc.DecryptorByKey(*in.DecryptorKey)
		}
		if in.BaseProbability == nil {
			baseProb = chain.BaseProbability
		}
		if in.BaseRuns == nil {
			baseRuns = chain.BaseRuns
		}
		probability := calc.InventionProbability(baseProb, encSkill, sci1, sci2, decryptor.ProbabilityMultiplier)
		bpc := calc.CalculateT2BPC(baseRuns, decryptor)

		structMEBonus := calc.ManufacturingME(structType, rigME, sysSec)
		structTEBonus := calc.ManufacturingTE(structType, rigTE, sysSec)
		_ = structTEBonus
		reactionMEBonus := structMEBonus
		if in.ReactionStructureType != nil {
			if reactSysSec == "" {
				reactSysSec = "lowsec"
			}
			reactionMEBonus = calc.ReactionME(reactRigME, reactSysSec)
		}

		// T2 materials at the invented BPC's ME over all planned runs.
		baseMats := make([]calc.BaseMaterial, 0, len(chain.T2Manufacturing.Materials))
		for _, m := range chain.T2Manufacturing.Materials {
			name := ""
			if m.Name != nil {
				name = *m.Name
			}
			baseMats = append(baseMats, calc.BaseMaterial{TypeID: m.TypeID, Name: name, Quantity: m.Quantity})
		}
		t2Materials := calc.CalculateMaterials(baseMats, bpc.ME, bpc.Runs*invRuns, structMEBonus)
		components, err := resolveComponents(ctx, d.Industry, t2Materials, structMEBonus, reactionMEBonus)
		if err != nil {
			return dbError(c, err)
		}

		// Prices and volumes.
		typeIDs := []int{chain.T2ItemTypeID, chain.T1ItemTypeID}
		for _, m := range t2Materials {
			typeIDs = append(typeIDs, m.TypeID)
		}
		for _, dc := range chain.Datacores {
			typeIDs = append(typeIDs, dc.TypeID)
		}
		if decryptor.TypeID != nil {
			typeIDs = append(typeIDs, *decryptor.TypeID)
		}
		for _, comp := range components {
			for _, sub := range comp.Materials {
				typeIDs = append(typeIDs, sub.TypeID)
			}
		}
		priceRegion := market.DefaultRegion
		if in.PriceRegionID != nil {
			priceRegion = *in.PriceRegionID
		}
		resolved, err := resolvePrices(ctx, d.Market, typeIDs, priceRegion)
		if err != nil {
			return dbError(c, err)
		}
		prices := make(map[int]float64, len(resolved))
		for id, r := range resolved {
			prices[id] = r.Price
		}
		typeVols, err := d.Trading.ArbitrageTypes(ctx, typeIDs)
		if err != nil {
			return dbError(c, err)
		}
		volumes := make(map[int]float64, len(typeVols))
		for id, tv := range typeVols {
			volumes[id] = tv.VolumeM3
		}

		portion := 1
		if len(chain.T2Manufacturing.Products) > 0 && chain.T2Manufacturing.Products[0].Quantity > 0 {
			portion = chain.T2Manufacturing.Products[0].Quantity
		}
		dcs := make([]calc.DatacoreSpec, 0, len(chain.Datacores))
		for _, dc := range chain.Datacores {
			dcs = append(dcs, calc.DatacoreSpec{TypeID: dc.TypeID, Name: dc.Name, Quantity: dc.Quantity, SkillID: dc.SkillID, SkillName: dc.SkillName})
		}
		t1Name := ""
		if chain.T1ItemName != nil {
			t1Name = *chain.T1ItemName
		}
		result := calc.CalculateInventionJob(calc.InventionInput{
			T2Materials: t2Materials, T2PortionSize: portion,
			T2ItemTypeID: chain.T2ItemTypeID, T1ItemTypeID: chain.T1ItemTypeID, T1ItemName: t1Name,
			Datacores: dcs, Decryptor: decryptor, Prices: prices, Volumes: volumes,
			Components: components, BPCRuns: bpc.Runs, InventionRuns: invRuns,
			SystemCostIndex: sysCostIdx, FacilityTaxRate: facilityTax,
			StructureMEBonus: structMEBonus, StructureTEBonus: structTEBonus,
		}, probability)

		return Success(c, http.StatusOK, inventionPayload(result, chain), map[string]any{
			"freshness": freshnessFor(c, d, priceRegion),
		})
	}
}

// inventionPayload shapes a calc.InventionResult like InventionJobResource.
func inventionPayload(r calc.InventionResult, chain *industry.Chain) map[string]any {
	mats := make([]any, 0, len(r.T2Materials))
	for _, m := range r.T2Materials {
		mats = append(mats, pricedMaterialShape(m))
	}
	dcs := make([]any, 0, len(r.Datacores))
	for _, d := range r.Datacores {
		dcs = append(dcs, map[string]any{
			"type_id": d.TypeID, "name": d.Name, "quantity": d.Quantity,
			"skill_id": d.SkillID, "skill_name": d.SkillName,
			"unit_price": d.UnitPrice, "total_cost": d.TotalCost, "volume": d.Volume,
			"total_quantity": d.TotalQuantity, "total_quantity_cost": d.TotalCostAll,
		})
	}
	return map[string]any{
		"invention": map[string]any{
			"probability": r.Probability, "expected_attempts": r.ExpectedAttempts,
			"total_attempts": r.TotalAttempts, "cost_per_attempt": r.InventionCostPerAttempt,
			"cost_per_success": r.InventionCostPerSuccess, "total_cost": r.TotalInventionCost,
			"datacore_cost_per_attempt": r.DatacoreCostPerAttempt, "datacores": dcs,
			"decryptor": map[string]any{
				"unit_price": r.DecryptorUnitPrice, "volume": r.DecryptorVolume,
				"total_quantity": r.DecryptorTotalQuantity, "total_cost": r.DecryptorTotalCost,
			},
		},
		"t1_item": map[string]any{
			"type_id": chain.T1ItemTypeID, "name": chain.T1ItemName,
		},
		"t2_bpc": r.BPC,
		"t2_materials": mats,
		"material_costs": map[string]any{
			"buy": r.TotalT2MaterialCost, "build": r.TotalT2BuildCost,
		},
		"t2_job": map[string]any{
			"job_fee": r.T2JobFee, "facility_tax": r.T2FacilityTax, "total": r.T2TotalJobCost,
		},
		"output": map[string]any{
			"sell_price": r.T2SellPrice, "total_output": r.TotalOutput, "total_value": r.TotalOutputValue,
		},
		"structure_bonuses": map[string]any{
			"me": r.StructureMEBonus, "te": r.StructureTEBonus,
		},
		"build_cheaper": r.BuildCheaper,
		"best": map[string]any{
			"total_cost": r.TotalCost, "profit": r.Profit,
			"profit_margin": r.ProfitMargin, "profit_per_unit": r.ProfitPerUnit,
		},
		"scenarios": map[string]any{
			"full_buy": map[string]any{
				"total_cost": r.TotalCostFullBuy, "profit": r.ProfitFullBuy,
				"margin": r.MarginFullBuy, "per_unit": r.PerUnitFullBuy,
			},
			"full_build": map[string]any{
				"total_cost": r.TotalCostFullBuild, "profit": r.ProfitFullBuild,
				"margin": r.MarginFullBuild, "per_unit": r.PerUnitFullBuild,
			},
		},
	}
}

func pricedMaterialShape(m calc.PricedMaterial) map[string]any {
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
			"base_time": m.Component.BaseTime, "materials": subs,
			"build_cost": m.Component.BuildCost,
			"material_cost": m.Component.MaterialCost, "job_cost": m.Component.JobCost,
		}
	}
	return map[string]any{
		"type_id": m.TypeID, "name": m.Name,
		"base_quantity": m.BaseQuantity, "adjusted_quantity": m.AdjustedQuantity,
		"quantity_per_run": m.QuantityPerRun, "unit_price": m.UnitPrice,
		"total_cost": m.TotalCost, "volume": m.Volume, "component": comp,
	}
}
