package calc

import (
	"math"
	"sort"

	"github.com/sudo-adduser-jordan/eveindustry/internal/industry"
	"github.com/sudo-adduser-jordan/eveindustry/internal/market"
)

// Reaction formula SDE group IDs mirror ReactionBrowserService.
const (
	GroupCompositeReactions   = 1888
	GroupBiochemicalReactions = 1889
	GroupHybridReactions      = 4097
)

// ReactionCategory ports resolveReactionCategory().
func ReactionCategory(groupID *int) string {
	if groupID == nil {
		return "other"
	}
	switch *groupID {
	case GroupCompositeReactions:
		return "composite"
	case GroupBiochemicalReactions:
		return "biochemical"
	case GroupHybridReactions:
		return "hybrid"
	default:
		return "other"
	}
}

// ReactionComplexity ports resolveReactionComplexity(): "complex" when any
// input is itself a reaction product, else "intermediate".
func ReactionComplexity(materials []industry.Item, productIDs map[int]bool) string {
	for _, m := range materials {
		if productIDs[m.TypeID] {
			return "complex"
		}
	}
	return "intermediate"
}

// SellScoreInput is one product's 30-day history, newest first.
type SellScoreInput struct {
	Volumes     []uint64
	OrderCounts []uint64
}

// SellScore ports calculateSellScore(): volume ratio (0-6), consistency
// (0-2), order activity (0-2), capped at 10.
type SellScore struct {
	Score          int     `json:"score"`
	AvgDailyVolume float64 `json:"avg_daily_volume"`
	AvgOrderCount  float64 `json:"avg_order_count"`
	ActiveDays     int     `json:"active_days"`
	TotalDays      int     `json:"total_days"`
}

func CalculateSellScore(in SellScoreInput, totalOutput int) SellScore {
	totalDays := len(in.Volumes)
	active := 0
	var volSum, ordSum uint64
	for i, v := range in.Volumes {
		if v > 0 {
			active++
		}
		volSum += v
		if i < len(in.OrderCounts) {
			ordSum += in.OrderCounts[i]
		}
	}
	avgVol, avgOrd := 0.0, 0.0
	if totalDays > 0 {
		avgVol = math.Round(float64(volSum)/float64(totalDays)*10) / 10
		avgOrd = math.Round(float64(ordSum)/float64(totalDays)*10) / 10
	}
	ratio := 0.0
	if totalOutput > 0 {
		ratio = avgVol / float64(totalOutput)
	}
	volumeScore := math.Min(6, math.Round(ratio*3))
	consistency := 0.0
	if totalDays > 0 {
		consistency = float64(active) / float64(totalDays)
	}
	consistencyScore := math.Round(consistency * 2)
	orderScore := 0.0
	if avgOrd >= 20 {
		orderScore = 2
	} else if avgOrd >= 5 {
		orderScore = 1
	}
	return SellScore{
		Score:          int(math.Min(10, volumeScore+consistencyScore+orderScore)),
		AvgDailyVolume: avgVol, AvgOrderCount: avgOrd,
		ActiveDays: active, TotalDays: totalDays,
	}
}

// ReactionBrowserItem is one priced reaction row (formatted display strings
// omitted, as v1's clean() does).
type ReactionBrowserItem struct {
	BlueprintTypeID    int       `json:"blueprint_type_id"`
	FormulaName        string    `json:"formula_name"`
	ReactionCategory   string    `json:"reaction_category"`
	ReactionComplexity string    `json:"reaction_complexity"`
	ProductTypeID      int       `json:"product_type_id"`
	ProductName        string    `json:"product_name"`
	ProductIconURL     string    `json:"product_icon_url"`
	PortionSize        int       `json:"portion_size"`
	TotalOutput        int       `json:"total_output"`
	TotalInputCost     float64   `json:"total_input_cost"`
	TotalOutputValue   float64   `json:"total_output_value"`
	JobFee             float64   `json:"job_fee"`
	FacilityTax        float64   `json:"facility_tax"`
	TotalJobCost       float64   `json:"total_job_cost"`
	SCCFee             float64   `json:"scc_fee"`
	Profit             float64   `json:"profit"`
	ProfitPositive     bool      `json:"profit_positive"`
	ProfitPerUnit      float64   `json:"profit_per_unit"`
	ProfitMargin       float64   `json:"profit_margin"`
	SellScore          SellScore `json:"sell_score"`
}

// BrowserReactionInput carries everything CalculateReactionBrowser needs.
type BrowserReactionInput struct {
	Formulas      []industry.ReactionFormula
	Prices        map[int]float64
	Histories     map[int]SellScoreInput
	Runs          int
	RigBonus      float64
	FacilityTax   float64
	SystemCostIdx float64
}

// CalculateReactionBrowser ports calculateAllProfitability(): every formula
// priced, fee'd and scored, sorted by profit descending.
func CalculateReactionBrowser(in BrowserReactionInput) []ReactionBrowserItem {
	productIDs := map[int]bool{}
	for _, f := range in.Formulas {
		for _, p := range f.Products {
			if id, ok := asInt(p["type_id"]); ok {
				productIDs[id] = true
			}
		}
	}
	out := []ReactionBrowserItem{}
	for _, f := range in.Formulas {
		if len(f.Products) == 0 {
			continue
		}
		pid, ok := asInt(f.Products[0]["type_id"])
		if !ok || pid == 0 {
			continue
		}
		pname, _ := f.Products[0]["name"].(string)
		if pname == "" {
			pname = "Unknown"
		}
		portion, _ := asInt(f.Products[0]["quantity"])
		if portion <= 0 {
			portion = 1
		}
		base := make([]BaseMaterial, 0, len(f.Materials))
		for _, m := range f.Materials {
			id, _ := asInt(m["type_id"])
			name, _ := m["name"].(string)
			qty, _ := asInt(m["quantity"])
			if name == "" {
				name = "Unknown"
			}
			base = append(base, BaseMaterial{TypeID: id, Name: name, Quantity: qty})
		}
		mats := ReactionMaterials(base, in.Runs, in.RigBonus)
		inputCost := 0.0
		for _, m := range mats {
			inputCost += in.Prices[m.TypeID] * float64(m.AdjustedQuantity)
		}
		totalOutput := in.Runs * portion
		outputValue := in.Prices[pid] * float64(totalOutput)
		jobFee, facilityTax, totalJobCost := JobCost(inputCost, in.SystemCostIdx, in.FacilityTax)
		scc := outputValue * SCCFeeRate
		profit := outputValue - inputCost - totalJobCost - scc
		perUnit := 0.0
		if totalOutput > 0 {
			perUnit = profit / float64(totalOutput)
		}
		itemMats := make([]industry.Item, 0, len(mats))
		for _, m := range mats {
			name := m.Name
			itemMats = append(itemMats, industry.Item{TypeID: m.TypeID, Name: &name, Quantity: m.AdjustedQuantity})
		}
		out = append(out, ReactionBrowserItem{
			BlueprintTypeID: f.BlueprintTypeID, FormulaName: f.FormulaName,
			ReactionCategory:   ReactionCategory(f.GroupID),
			ReactionComplexity: ReactionComplexity(itemMats, productIDs),
			ProductTypeID:      pid, ProductName: pname,
			ProductIconURL: market.TypeImageURL(pid),
			PortionSize:    portion, TotalOutput: totalOutput,
			TotalInputCost: inputCost, TotalOutputValue: outputValue,
			JobFee: jobFee, FacilityTax: facilityTax, TotalJobCost: totalJobCost,
			SCCFee: scc, Profit: profit, ProfitPositive: profit >= 0,
			ProfitPerUnit: perUnit, ProfitMargin: Margin(profit, inputCost),
			SellScore: CalculateSellScore(in.Histories[pid], totalOutput),
		})
	}
	sort.Slice(out, func(i, j int) bool { return out[i].Profit > out[j].Profit })
	return out
}

func asInt(v any) (int, bool) {
	switch n := v.(type) {
	case int:
		return n, true
	case int64:
		return int(n), true
	case float64:
		return int(n), true
	}
	return 0, false
}

// BrowserBlueprint is one T1 blueprint with SDE materials/products.
type BrowserBlueprint struct {
	BlueprintTypeID int
	BlueprintName   string
	Materials       []BaseMaterial
	Products        []BaseMaterial
	Time            int
}

// FilterManufacturingBlueprints ports getAllManufacturingBlueprints() and
// getManufacturingBlueprintsForMarketGroup(): drop invented BPOs, optional
// category allow-list, drop T2 modules and T2/T3 ships, name and sort.
func FilterManufacturingBlueprints(acts []industry.MfgActivity, invented map[int]bool, allowedProducts map[int]bool, excluded map[int]bool, names map[int]string) []BrowserBlueprint {
	out := []BrowserBlueprint{}
	for _, a := range acts {
		if invented[a.BlueprintTypeID] {
			continue
		}
		if len(a.Products) == 0 {
			continue
		}
		pid, _ := asInt(a.Products[0]["type_id"])
		if pid == 0 {
			continue
		}
		if allowedProducts != nil && !allowedProducts[pid] {
			continue
		}
		if excluded[pid] {
			continue
		}
		mats := make([]BaseMaterial, 0, len(a.Materials))
		for _, m := range a.Materials {
			id, _ := asInt(m["type_id"])
			name, _ := m["name"].(string)
			qty, _ := asInt(m["quantity"])
			if name == "" {
				name = "Unknown"
			}
			mats = append(mats, BaseMaterial{TypeID: id, Name: name, Quantity: qty})
		}
		prods := make([]BaseMaterial, 0, len(a.Products))
		for _, p := range a.Products {
			id, _ := asInt(p["type_id"])
			name, _ := p["name"].(string)
			qty, _ := asInt(p["quantity"])
			prods = append(prods, BaseMaterial{TypeID: id, Name: name, Quantity: qty})
		}
		name := names[a.BlueprintTypeID]
		if name == "" {
			name = "Unknown Blueprint"
		}
		out = append(out, BrowserBlueprint{
			BlueprintTypeID: a.BlueprintTypeID, BlueprintName: name,
			Materials: mats, Products: prods, Time: a.Time,
		})
	}
	sort.Slice(out, func(i, j int) bool { return out[i].BlueprintName < out[j].BlueprintName })
	return out
}

// ExcludedBrowserProducts ports getExcludedManufacturingProductTypeIds():
// T2 modules (category 7 + meta 2) and T2/T3 ships (category 6 + meta 2/14).
func ExcludedBrowserProducts(metas []industry.TypeGroupMeta, groupCats map[int]int) map[int]bool {
	out := map[int]bool{}
	for _, m := range metas {
		if !m.GroupID.Valid || !m.MetaGroupID.Valid {
			continue
		}
		cat := groupCats[int(m.GroupID.Int64)]
		meta := int(m.MetaGroupID.Int64)
		if meta == industry.MetaGroupTechII && cat == 7 {
			out[m.TypeID] = true
		}
		if cat == 6 && (meta == industry.MetaGroupTechII || meta == industry.MetaGroupTechIII) {
			out[m.TypeID] = true
		}
	}
	return out
}

// ManufacturingBrowserItem is one live-priced browser row.
type ManufacturingBrowserItem struct {
	BlueprintTypeID   int     `json:"blueprint_type_id"`
	BlueprintName     string  `json:"blueprint_name"`
	ProductTypeID     int     `json:"product_type_id"`
	ProductName       string  `json:"product_name"`
	ProductIconURL    string  `json:"product_icon_url"`
	PortionSize       int     `json:"portion_size"`
	TotalOutput       int     `json:"total_output"`
	TotalInputCost    float64 `json:"total_input_cost"`
	TotalOutputValue  float64 `json:"total_output_value"`
	JobFee            float64 `json:"job_fee"`
	FacilityTax       float64 `json:"facility_tax"`
	TotalJobCost      float64 `json:"total_job_cost"`
	SCCFee            float64 `json:"scc_fee"`
	Profit            float64 `json:"profit"`
	ProfitPositive    bool    `json:"profit_positive"`
	ProfitPerUnit     float64 `json:"profit_per_unit"`
	ProfitMargin      float64 `json:"profit_margin"`
	SellScore         SellScore `json:"sell_score"`
}

// CalculateManufacturingBrowser ports calculateAllProfitability(): ME
// materials, fees, SCC, sell scores, profit-desc sort.
type BrowserManufacturingInput struct {
	Items         []BrowserBlueprint
	Prices        map[int]float64
	Histories     map[int]SellScoreInput
	Runs          int
	MELLevel      int
	StructureME   float64
	FacilityTax   float64
	SystemCostIdx float64
}

func CalculateManufacturingBrowser(in BrowserManufacturingInput) []ManufacturingBrowserItem {
	out := []ManufacturingBrowserItem{}
	for _, item := range in.Items {
		if len(item.Products) == 0 {
			continue
		}
		prod := item.Products[0]
		if prod.TypeID == 0 {
			continue
		}
		mats := CalculateMaterials(item.Materials, in.MELLevel, in.Runs, in.StructureME)
		inputCost := 0.0
		for _, m := range mats {
			inputCost += in.Prices[m.TypeID] * float64(m.AdjustedQuantity)
		}
		totalOutput := in.Runs * max(prod.Quantity, 1)
		outputValue := in.Prices[prod.TypeID] * float64(totalOutput)
		jobFee, facilityTax, totalJobCost := JobCost(inputCost, in.SystemCostIdx, in.FacilityTax)
		scc := outputValue * SCCFeeRate
		profit := outputValue - inputCost - totalJobCost - scc
		perUnit := 0.0
		if totalOutput > 0 {
			perUnit = profit / float64(totalOutput)
		}
		pname := prod.Name
		if pname == "" {
			pname = "Unknown"
		}
		out = append(out, ManufacturingBrowserItem{
			BlueprintTypeID: item.BlueprintTypeID, BlueprintName: item.BlueprintName,
			ProductTypeID: prod.TypeID, ProductName: pname,
			ProductIconURL: market.TypeImageURL(prod.TypeID),
			PortionSize: max(prod.Quantity, 1), TotalOutput: totalOutput,
			TotalInputCost: inputCost, TotalOutputValue: outputValue,
			JobFee: jobFee, FacilityTax: facilityTax, TotalJobCost: totalJobCost,
			SCCFee: scc, Profit: profit, ProfitPositive: profit >= 0,
			ProfitPerUnit: perUnit, ProfitMargin: Margin(profit, inputCost),
			SellScore: CalculateSellScore(in.Histories[prod.TypeID], totalOutput),
		})
	}
	sort.Slice(out, func(i, j int) bool { return out[i].Profit > out[j].Profit })
	return out
}

// PrecomputedAdjustment ports applyUserAdjustments(): scale a base row
// (1 run, no bonus/fees) to the caller's runs, structure ME and fees.
type PrecomputedAdjustment struct {
	TotalOutput      float64 `json:"total_output"`
	TotalInputCost   float64 `json:"total_input_cost"`
	TotalOutputValue float64 `json:"total_output_value"`
	JobFee           float64 `json:"job_fee"`
	FacilityTax      float64 `json:"facility_tax"`
	TotalJobCost     float64 `json:"total_job_cost"`
	SCCFee           float64 `json:"scc_fee"`
	Profit           float64 `json:"profit"`
	ProfitPositive   bool    `json:"profit_positive"`
	ProfitPerUnit    float64 `json:"profit_per_unit"`
	ProfitMargin     float64 `json:"profit_margin"`
}

func ApplyPrecomputedAdjustments(baseInput, baseOutput float64, portionSize, runs int, structureME, facilityTax, sysCostIdx float64) PrecomputedAdjustment {
	input := baseInput * float64(runs) * (1 - structureME/100)
	totalOutput := runs * portionSize
	output := baseOutput * float64(runs)
	jobFee, facilityTaxAmt, totalJobCost := JobCost(input, sysCostIdx, facilityTax)
	scc := output * SCCFeeRate
	profit := output - input - totalJobCost - scc
	perUnit := 0.0
	if totalOutput > 0 {
		perUnit = profit / float64(totalOutput)
	}
	return PrecomputedAdjustment{
		TotalOutput: float64(totalOutput), TotalInputCost: input, TotalOutputValue: output,
		JobFee: jobFee, FacilityTax: facilityTaxAmt, TotalJobCost: totalJobCost,
		SCCFee: scc, Profit: profit, ProfitPositive: profit >= 0,
		ProfitPerUnit: perUnit, ProfitMargin: Margin(profit, input),
	}
}
