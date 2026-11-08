package calc

import (
	"math"
	"strings"
)

// SCCFeeRate mirrors IndustryProfitCalculator::SCC_FEE_RATE.
const SCCFeeRate = 0.04

// Skill type IDs mirror ManufacturingCalculatorService::SKILL_* and
// ReactionsCalculatorService::SKILL_REACTIONS.
const (
	SkillIndustry         = 3380
	SkillAdvancedIndustry = 3388
	SkillReactions        = 45746
)

// Summary ports IndustryProfitCalculator::summary(): profit after the SCC
// fee and any other costs, margin as profit ÷ input cost.
func Summary(outputValue, inputCost, otherCosts float64) (sccFee, profit, profitMargin float64) {
	sccFee = outputValue * SCCFeeRate
	profit = outputValue - inputCost - otherCosts - sccFee
	return sccFee, profit, Margin(profit, inputCost)
}

// Margin ports IndustryProfitCalculator::margin(): profit ÷ input cost as a
// percentage rounded to 0.1.
func Margin(profit, inputCost float64) float64 {
	if inputCost <= 0 {
		return 0
	}
	return math.Round(profit/inputCost*100*10) / 10
}

// QueueTotals ports IndustryProfitCalculator::totals().
type QueueItem struct {
	Revenue   float64 `json:"revenue"`
	InputCost float64 `json:"input_cost"`
	Fees      float64 `json:"fees"`
}

type QueueRow struct {
	Revenue      float64 `json:"revenue"`
	InputCost    float64 `json:"input_cost"`
	Fees         float64 `json:"fees"`
	Profit       float64 `json:"profit"`
	ProfitMargin float64 `json:"profit_margin"`
}

type QueueTotals struct {
	Revenue      float64 `json:"revenue"`
	InputCost    float64 `json:"input_cost"`
	Fees         float64 `json:"fees"`
	Profit       float64 `json:"profit"`
	ProfitMargin float64 `json:"profit_margin"`
}

func Totals(items []QueueItem) ([]QueueRow, QueueTotals) {
	rows := make([]QueueRow, 0, len(items))
	totals := QueueTotals{}
	for _, it := range items {
		profit := it.Revenue - it.InputCost - it.Fees
		rows = append(rows, QueueRow{
			Revenue: it.Revenue, InputCost: it.InputCost, Fees: it.Fees,
			Profit: profit, ProfitMargin: Margin(profit, it.InputCost),
		})
		totals.Revenue += it.Revenue
		totals.InputCost += it.InputCost
		totals.Fees += it.Fees
		totals.Profit += profit
	}
	totals.ProfitMargin = Margin(totals.Profit, totals.InputCost)
	return rows, totals
}

// BaseMaterial is one posted or SDE material: {type_id, name, quantity}.
type BaseMaterial struct {
	TypeID   int
	Name     string
	Quantity int
}

// Material ports one calculateMaterials() row. QuantityPerRun is float64
// because PHP's ceil() returns a float.
type Material struct {
	TypeID           int     `json:"type_id"`
	Name             string  `json:"name"`
	BaseQuantity     int     `json:"base_quantity"`
	AdjustedQuantity int     `json:"adjusted_quantity"`
	QuantityPerRun   float64 `json:"quantity_per_run"`
}

// CalculateMaterials ports ManufacturingCalculatorService::calculateMaterials():
// adjusted = max(runs, ceil(round(base*runs*meFactor*structFactor, 2))).
func CalculateMaterials(base []BaseMaterial, meLevel, runs int, structureMeBonus float64) []Material {
	meFactor := 1 - float64(meLevel)/100
	structFactor := 1 - structureMeBonus/100
	out := make([]Material, 0, len(base))
	for _, m := range base {
		adjusted := runs
		if q := int(math.Ceil(math.Round(float64(m.Quantity)*float64(runs)*meFactor*structFactor*100) / 100)); q > adjusted {
			adjusted = q
		}
		perRun := float64(adjusted)
		if runs > 0 {
			perRun = math.Ceil(float64(adjusted) / float64(runs))
		}
		name := m.Name
		if name == "" {
			name = "Unknown"
		}
		out = append(out, Material{
			TypeID: m.TypeID, Name: name,
			BaseQuantity: m.Quantity, AdjustedQuantity: adjusted, QuantityPerRun: perRun,
		})
	}
	return out
}

// JobTime ports calculateProductionTime().
type JobTime struct {
	BaseTimeSeconds     int    `json:"base_time_seconds"`
	TimePerRunSeconds   int    `json:"time_per_run_seconds"`
	TotalTimeSeconds    int    `json:"total_time_seconds"`
	TimePerRunFormatted string `json:"time_per_run_formatted"`
	TotalTimeFormatted  string `json:"total_time_formatted"`
}

func CalculateProductionTime(baseTimeSeconds, teLevel, runs, industrySkill, advancedIndustrySkill int, structureTeBonus float64) JobTime {
	teFactor := 1 - float64(teLevel)/100
	indFactor := 1 - float64(industrySkill)*4/100
	advFactor := 1 - float64(advancedIndustrySkill)*3/100
	structFactor := 1 - structureTeBonus/100
	perRun := float64(baseTimeSeconds) * teFactor * indFactor * advFactor * structFactor
	total := perRun * float64(runs)
	return JobTime{
		BaseTimeSeconds:     baseTimeSeconds,
		TimePerRunSeconds:   int(math.Round(perRun)),
		TotalTimeSeconds:    int(math.Round(total)),
		TimePerRunFormatted: FormatDuration(int(math.Round(perRun))),
		TotalTimeFormatted:  FormatDuration(int(math.Round(total))),
	}
}

// FormatDuration ports ManufacturingCalculatorService::formatDuration().
func FormatDuration(seconds int) string {
	days := seconds / 86400
	hours := (seconds % 86400) / 3600
	minutes := (seconds % 3600) / 60
	secs := seconds % 60
	var parts []string
	if days > 0 {
		parts = append(parts, itoa(days)+"d")
	}
	if hours > 0 || days > 0 {
		parts = append(parts, itoa(hours)+"h")
	}
	if minutes > 0 || hours > 0 || days > 0 {
		parts = append(parts, itoa(minutes)+"m")
	}
	parts = append(parts, itoa(secs)+"s")
	return strings.Join(parts, " ")
}

func itoa(n int) string {
	if n == 0 {
		return "0"
	}
	var buf [20]byte
	i := len(buf)
	for n > 0 {
		i--
		buf[i] = byte('0' + n%10)
		n /= 10
	}
	return string(buf[i:])
}

func Round2(v float64) float64 { return math.Round(v*100) / 100 }

// ReactionMaterials ports ReactionsCalculatorService::calculateMaterials():
// adjusted = max(runs, ceil(round(base*runs*rigFactor, 2))).
func ReactionMaterials(base []BaseMaterial, runs int, rigBonus float64) []Material {
	rigFactor := 1 - rigBonus/100
	out := make([]Material, 0, len(base))
	for _, m := range base {
		adjusted := runs
		if q := int(math.Ceil(math.Round(float64(m.Quantity)*float64(runs)*rigFactor*100) / 100)); q > adjusted {
			adjusted = q
		}
		perRun := float64(adjusted)
		if runs > 0 {
			perRun = math.Ceil(float64(adjusted) / float64(runs))
		}
		name := m.Name
		if name == "" {
			name = "Unknown"
		}
		out = append(out, Material{
			TypeID: m.TypeID, Name: name,
			BaseQuantity: m.Quantity, AdjustedQuantity: adjusted, QuantityPerRun: perRun,
		})
	}
	return out
}

// ReactionTime ports ReactionsCalculatorService::calculateProductionTime().
func ReactionTime(baseTimeSeconds, runs, reactionsSkill int, structureTeBonus float64) JobTime {
	skillFactor := 1 - float64(reactionsSkill)*4/100
	structFactor := 1 - structureTeBonus/100
	perRun := float64(baseTimeSeconds) * skillFactor * structFactor
	total := perRun * float64(runs)
	return JobTime{
		BaseTimeSeconds:     baseTimeSeconds,
		TimePerRunSeconds:   int(math.Round(perRun)),
		TotalTimeSeconds:    int(math.Round(total)),
		TimePerRunFormatted: FormatDuration(int(math.Round(perRun))),
		TotalTimeFormatted:  FormatDuration(int(math.Round(total))),
	}
}

// ReactionCostProfit ports ReactionsCalculatorService::calculateCostProfit()
// (no buyback multiplier, unlike manufacturing).
func ReactionCostProfit(materials []Material, productTypeID, runs, portionSize int, prices map[int]PricedValue) CostProfit {
	return costProfit(materials, productTypeID, runs, portionSize, prices, 1.0)
}
