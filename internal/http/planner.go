package http

import (
	"encoding/json"
	"net/http"

	"github.com/labstack/echo/v4"

	"github.com/sudo-adduser-jordan/eveindustry/internal/calc"
	"github.com/sudo-adduser-jordan/eveindustry/internal/market"
)

func handleReactionPlanner(d Deps) echo.HandlerFunc {
	type entry struct {
		FormulaTypeID *int     `json:"formula_type_id"`
		Slots         *int     `json:"slots"`
		RunsPerSlot   *int     `json:"runs_per_slot"`
		RigBonus      *float64 `json:"rig_bonus"`
	}
	type input struct {
		Entries         []entry  `json:"entries"`
		RigTier         *string  `json:"rig_tier"`
		SecuritySpace   *string  `json:"security_space"`
		FacilityTax     *float64 `json:"facility_tax"`
		SystemCostIndex *float64 `json:"system_cost_index"`
		PriceRegionID   *int     `json:"price_region_id"`
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
		if len(in.Entries) == 0 {
			verrs.add("entries", "The entries field is required.")
		}
		entries := make([]calc.PlanEntry, 0, len(in.Entries))
		for _, e := range in.Entries {
			formula, ok1 := intField(verrs, "entries", e.FormulaTypeID, 1, 1<<31-1, true)
			slots, ok2 := intField(verrs, "entries", e.Slots, 1, 1000, true)
			rps, ok3 := intField(verrs, "entries", e.RunsPerSlot, 1, 100000, true)
			if !ok1 || !ok2 || !ok3 {
				continue
			}
			entries = append(entries, calc.PlanEntry{FormulaTypeID: formula, Slots: slots, RunsPerSlot: rps, RigBonus: e.RigBonus})
		}
		rigTier, _ := oneOf(verrs, "rig_tier", in.RigTier, "none", "t1", "t2")
		secSpace, _ := oneOf(verrs, "security_space", in.SecuritySpace, "lowsec", "nullsec")
		facilityTax, _ := numField(verrs, "facility_tax", in.FacilityTax, 0, 50)
		sysCostIdx, _ := numField(verrs, "system_cost_index", in.SystemCostIndex, 0, 100)
		if len(verrs) > 0 {
			return verrs.invalid(c)
		}
		if secSpace == "" {
			secSpace = "lowsec"
		}
		priceRegion := market.DefaultRegion
		if in.PriceRegionID != nil {
			priceRegion = *in.PriceRegionID
		}
		ctx := c.Request().Context()

		globalRig := calc.ResolveRigBonus(rigTier, secSpace)
		chain, err := calc.ResolveReactionChain(ctx, d.Industry, entries, globalRig)
		if err != nil {
			return dbError(c, err)
		}

		typeIDs := []int{}
		seen := map[int]bool{}
		add := func(id int) {
			if id > 0 && !seen[id] {
				seen[id] = true
				typeIDs = append(typeIDs, id)
			}
		}
		for _, r := range chain.RawMaterials {
			add(r.TypeID)
		}
		for _, o := range chain.CompositeOutputs {
			if o.ProductTypeID != nil {
				add(*o.ProductTypeID)
			}
		}
		resolved, err := resolvePrices(ctx, d.Market, typeIDs, priceRegion)
		if err != nil {
			return dbError(c, err)
		}
		priceOf := func(id int) (float64, string) {
			if r, ok := resolved[id]; ok {
				return r.Price, r.Source
			}
			return 0, market.SourceNone
		}
		typeVols, err := d.Trading.ArbitrageTypes(ctx, typeIDs)
		if err != nil {
			return dbError(c, err)
		}

		totalInput := 0.0
		rawOut := make([]any, 0, len(chain.RawMaterials))
		for _, r := range chain.RawMaterials {
			unit, source := priceOf(r.TypeID)
			line := unit * float64(r.Quantity)
			totalInput += line
			rawOut = append(rawOut, map[string]any{
				"type_id": r.TypeID, "name": r.Name, "quantity": r.Quantity,
				"price_source": source, "unit_price": unit, "total_cost": line,
				"volume": typeVols[r.TypeID].VolumeM3,
			})
		}
		totalOutput := 0.0
		compOut := make([]any, 0, len(chain.CompositeOutputs))
		for _, o := range chain.CompositeOutputs {
			unit, source := 0.0, market.SourceNone
			if o.ProductTypeID != nil {
				unit, source = priceOf(*o.ProductTypeID)
			}
			value := unit * float64(o.TotalOutput)
			totalOutput += value
			compOut = append(compOut, map[string]any{
				"formula_type_id": o.FormulaTypeID, "product_type_id": o.ProductTypeID,
				"product_name": o.ProductName, "slots": o.Slots, "runs_per_slot": o.RunsPerSlot,
				"total_runs": o.TotalRuns, "total_output": o.TotalOutput,
				"portion_size": o.PortionSize, "price_source": source,
				"unit_price": unit, "total_value": value,
			})
		}

		jobFee, facilityTax, totalJobCost := calc.JobCost(totalInput, sysCostIdx, facilityTax)
		sccFee := totalOutput * calc.SCCFeeRate
		profit := totalOutput - totalInput - totalJobCost - sccFee

		return Success(c, http.StatusOK, map[string]any{
			"effective_rig_bonus": calc.Round2(globalRig),
			"composite_outputs":   compOut,
			"composite_slots":     chain.CompositeSlots,
			"intermediate_slots":  chain.IntermediateSlots,
			"raw_materials":       rawOut,
			"slots": map[string]any{
				"composite": chain.TotalCompositeSlots, "intermediate": chain.TotalIntermediateSlots,
				"total": chain.TotalSlots,
			},
			"totals": map[string]any{"input_cost": totalInput, "output_value": totalOutput},
			"fees": map[string]any{
				"system_cost_index": sysCostIdx, "facility_tax_rate": facilityTax,
				"job_fee": jobFee, "facility_tax": facilityTax,
				"total_job_cost": totalJobCost, "scc_fee": sccFee,
			},
			"profit": profit, "profit_margin": calc.Margin(profit, totalInput),
		}, map[string]any{
			"freshness": freshnessFor(c, d, priceRegion),
		})
	}
}
