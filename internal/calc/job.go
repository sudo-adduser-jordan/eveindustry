package calc

// PricedMaterial is a material with its resolved unit price and source,
// mirroring one calculateCostProfit() material row.
type PricedMaterial struct {
	Material
	UnitPrice   float64          `json:"unit_price"`
	TotalCost   float64          `json:"total_cost"`
	PriceSource string           `json:"price_source"`
	Volume      float64          `json:"volume"`
	Component   *ComponentResult `json:"component"`
}

// ComponentResult mirrors the component block attached per material.
type ComponentResult struct {
	IsBuildable     bool             `json:"is_buildable"`
	BlueprintTypeID int              `json:"blueprint_type_id"`
	PortionSize     int              `json:"portion_size"`
	RunsNeeded      int              `json:"runs_needed"`
	BaseTime        int              `json:"base_time"`
	Materials       []PricedMaterial `json:"materials"`
	BuildCost       float64          `json:"build_cost"`
}

// ComponentData is the pre-resolved build data for one material, mirroring
// one resolveComponentBuildData() entry.
type ComponentData struct {
	BlueprintTypeID int
	IsReaction      bool
	PortionSize     int
	RunsNeeded      int
	BaseTime        int
	Materials       []Material
}

// JobInput carries everything CalculateJob needs after the handler has
// fetched SDE rows, prices and volumes.
type JobInput struct {
	Materials         []Material
	ProductTypeID     int
	Runs              int
	PortionSize       int
	Prices            map[int]PricedValue
	Volumes           map[int]float64
	Components        map[int]*ComponentData
	BuybackMultiplier float64
	AdjustedPrices    map[int]float64
	SciManufacturing  float64
	SciReaction       float64
	TaxRate           float64
	ReactionTaxRate   float64
	HasMainSystem     bool
	HasReactionSystem bool
}

// PricedValue is one resolved sell price with its source.
type PricedValue struct {
	Price  float64
	Source string
}

// JobResult mirrors ManufacturingJobCalculator::calculate() return keys.
type JobResult struct {
	Materials                 []PricedMaterial
	CostProfit                CostProfit
	Time                      JobTime
	TotalBuyCost              float64
	TotalBuildCost            float64
	BuildCheaper              bool
	TotalOutput               int
	TotalRevenue              float64
	SCCFee                    float64
	ProfitFullBuy             float64
	MarginFullBuy             float64
	PerUnitFullBuy            float64
	ProfitFullBuild           float64
	MarginFullBuild           float64
	PerUnitFullBuild          float64
	BuildFee                  BuildFee
	TotalFeeFullBuy           float64
	TotalFeeFullBuild         float64
	TotalCostFullBuyWithFee   float64
	ProfitFullBuyWithFee      float64
	MarginFullBuyWithFee      float64
	PerUnitFullBuyWithFee     float64
	TotalCostFullBuildWithFee float64
	ProfitFullBuildWithFee    float64
	MarginFullBuildWithFee    float64
	PerUnitFullBuildWithFee   float64
}

type CostProfit struct {
	Materials          []PricedMaterial
	TotalMaterialCost  float64
	ProductSellPrice   float64
	ProductPriceSource string
	TotalOutput        int
	TotalRevenue       float64
	Profit             float64
	ProfitPositive     bool
	ProfitPerUnit      float64
	ProfitMargin       float64
}

type BuildFee struct {
	EIV            float64
	SCI            float64
	JobCost        float64
	TaxRate        float64
	FacilityTax    float64
	Total          float64
	ComponentsFee  float64
	TotalFullBuild float64
	HasData        bool
}

// CalculateJob ports the body of ManufacturingJobCalculator::calculate()
// over pre-fetched inputs. Live ESI inputs (regional price seeding,
// adjusted prices, cost indices) arrive as empty/zero until the ESI phase;
// the fee blocks then compute to zero with HasData still reflecting whether
// a system was configured, exactly as if ESI had returned nothing.
func CalculateJob(in JobInput, time JobTime) JobResult {
	costProfit := costProfit(in.Materials, in.ProductTypeID, in.Runs, in.PortionSize, in.Prices, in.BuybackMultiplier)

	totalBuyCost := 0.0
	totalBuildCost := 0.0
	for i := range costProfit.Materials {
		mat := &costProfit.Materials[i]
		mat.Volume = in.Volumes[mat.TypeID]
		totalBuyCost += mat.TotalCost
		comp := in.Components[mat.TypeID]
		if comp == nil {
			totalBuildCost += mat.TotalCost
			continue
		}
		buildCost := 0.0
		subs := make([]PricedMaterial, 0, len(comp.Materials))
		for _, sub := range comp.Materials {
			price := 0.0
			source := "none"
			if p, ok := in.Prices[sub.TypeID]; ok {
				price, source = p.Price, p.Source
			}
			unit := price * in.BuybackMultiplier
			total := unit * float64(sub.AdjustedQuantity)
			buildCost += total
			subs = append(subs, PricedMaterial{
				Material: sub, UnitPrice: unit, TotalCost: total,
				PriceSource: source, Volume: in.Volumes[sub.TypeID],
			})
		}
		mat.Component = &ComponentResult{
			IsBuildable: true, BlueprintTypeID: comp.BlueprintTypeID,
			PortionSize: comp.PortionSize, RunsNeeded: comp.RunsNeeded,
			BaseTime: comp.BaseTime, Materials: subs, BuildCost: Round2(buildCost),
		}
		totalBuildCost += buildCost
	}

	totalOutput := costProfit.TotalOutput
	totalRevenue := costProfit.TotalRevenue

	_, profitFullBuy, marginFullBuy := Summary(totalRevenue, totalBuyCost, 0)
	sccFee := totalRevenue * SCCFeeRate
	perUnitFullBuy := 0.0
	if totalOutput > 0 {
		perUnitFullBuy = profitFullBuy / float64(totalOutput)
	}
	_, profitFullBuild, marginFullBuild := Summary(totalRevenue, Round2(totalBuildCost), 0)
	perUnitFullBuild := 0.0
	if totalOutput > 0 {
		perUnitFullBuild = profitFullBuild / float64(totalOutput)
	}

	// Build fees.
	eiv, jobCost, facilityTax, mainFee := 0.0, 0.0, 0.0, 0.0
	needsFee := in.HasMainSystem || in.HasReactionSystem
	if in.HasMainSystem {
		for _, mat := range costProfit.Materials {
			eiv += in.AdjustedPrices[mat.TypeID] * float64(mat.AdjustedQuantity)
		}
		jobCost = eiv * in.SciManufacturing
		facilityTax = totalRevenue * (in.TaxRate / 100)
		mainFee = jobCost + facilityTax
	}
	componentsFee := 0.0
	if needsFee {
		for i := range costProfit.Materials {
			mat := &costProfit.Materials[i]
			comp := in.Components[mat.TypeID]
			if comp == nil {
				continue
			}
			compSci := in.SciManufacturing
			compTax := in.TaxRate
			hasSystem := in.HasMainSystem
			if comp.IsReaction {
				compSci, compTax, hasSystem = in.SciReaction, in.ReactionTaxRate, in.HasReactionSystem
			}
			if !hasSystem || compSci <= 0 {
				continue
			}
			compEiv := 0.0
			for _, sub := range mat.Component.Materials {
				compEiv += in.AdjustedPrices[sub.TypeID] * float64(sub.AdjustedQuantity)
			}
			// PHP bases component facility tax on the component's buy cost.
			componentsFee += compEiv*compSci + mat.TotalCost*(compTax/100)
		}
	}
	totalFullBuildFee := mainFee + componentsFee

	_, profitFullBuyWithFee, marginFullBuyWithFee := Summary(totalRevenue, totalBuyCost, mainFee)
	totalFeeFullBuy := mainFee + sccFee
	totalCostFullBuyWithFee := totalBuyCost + totalFeeFullBuy
	perUnitFullBuyWithFee := 0.0
	if totalOutput > 0 {
		perUnitFullBuyWithFee = profitFullBuyWithFee / float64(totalOutput)
	}
	_, profitFullBuildWithFee, marginFullBuildWithFee := Summary(totalRevenue, Round2(totalBuildCost), totalFullBuildFee)
	totalFeeFullBuild := totalFullBuildFee + sccFee
	totalCostFullBuildWithFee := Round2(totalBuildCost + totalFeeFullBuild)
	perUnitFullBuildWithFee := 0.0
	if totalOutput > 0 {
		perUnitFullBuildWithFee = profitFullBuildWithFee / float64(totalOutput)
	}

	return JobResult{
		Materials: costProfit.Materials, CostProfit: costProfit, Time: time,
		TotalBuyCost: Round2(totalBuyCost), TotalBuildCost: Round2(totalBuildCost),
		BuildCheaper: totalBuildCost < totalBuyCost,
		TotalOutput:  totalOutput, TotalRevenue: Round2(totalRevenue), SCCFee: Round2(sccFee),
		ProfitFullBuy: Round2(profitFullBuy), MarginFullBuy: marginFullBuy, PerUnitFullBuy: Round2(perUnitFullBuy),
		ProfitFullBuild: Round2(profitFullBuild), MarginFullBuild: marginFullBuild, PerUnitFullBuild: Round2(perUnitFullBuild),
		BuildFee: BuildFee{
			EIV: Round2(eiv), SCI: in.SciManufacturing, JobCost: Round2(jobCost),
			TaxRate: in.TaxRate, FacilityTax: Round2(facilityTax), Total: Round2(mainFee),
			ComponentsFee: Round2(componentsFee), TotalFullBuild: Round2(totalFullBuildFee), HasData: needsFee,
		},
		TotalFeeFullBuy: Round2(totalFeeFullBuy), TotalFeeFullBuild: Round2(totalFeeFullBuild),
		TotalCostFullBuyWithFee: Round2(totalCostFullBuyWithFee),
		ProfitFullBuyWithFee:    Round2(profitFullBuyWithFee), MarginFullBuyWithFee: marginFullBuyWithFee,
		PerUnitFullBuyWithFee:     Round2(perUnitFullBuyWithFee),
		TotalCostFullBuildWithFee: totalCostFullBuildWithFee,
		ProfitFullBuildWithFee:    Round2(profitFullBuildWithFee), MarginFullBuildWithFee: marginFullBuildWithFee,
		PerUnitFullBuildWithFee: Round2(perUnitFullBuildWithFee),
	}
}

// costProfit ports calculateCostProfit(): unit prices with the buyback
// multiplier (materials only, never the product), product value, SCC summary.
func costProfit(materials []Material, productTypeID, runs, portionSize int, prices map[int]PricedValue, buybackMultiplier float64) CostProfit {
	out := make([]PricedMaterial, 0, len(materials))
	totalMaterialCost := 0.0
	for _, m := range materials {
		price, source := 0.0, "none"
		if p, ok := prices[m.TypeID]; ok {
			price, source = p.Price*buybackMultiplier, p.Source
		}
		total := price * float64(m.AdjustedQuantity)
		totalMaterialCost += total
		out = append(out, PricedMaterial{
			Material: m, UnitPrice: price, TotalCost: total, PriceSource: source,
		})
	}
	productPrice, productSource := 0.0, "none"
	if p, ok := prices[productTypeID]; ok {
		productPrice, productSource = p.Price, p.Source
	}
	totalOutput := runs * portionSize
	totalRevenue := productPrice * float64(totalOutput)
	sccFee := totalRevenue * SCCFeeRate
	profit := totalRevenue - totalMaterialCost - sccFee
	perUnit := 0.0
	if totalOutput > 0 {
		perUnit = profit / float64(totalOutput)
	}
	return CostProfit{
		Materials: out, TotalMaterialCost: totalMaterialCost,
		ProductSellPrice: productPrice, ProductPriceSource: productSource,
		TotalOutput: totalOutput, TotalRevenue: totalRevenue,
		Profit: profit, ProfitPositive: profit >= 0,
		ProfitPerUnit: perUnit, ProfitMargin: Margin(profit, totalMaterialCost),
	}
}
