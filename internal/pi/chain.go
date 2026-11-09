package pi

import (
	"math"
	"sort"
)

// Factory counts mirror the P1_TO_P3_* and P0_TO_P3_* constants.
const (
	P1ToP3P3Factories = 6
	P1ToP3P2A         = 6
	P1ToP3P2B         = 6
	P1ToP33InP3       = 3
	P1ToP33InP2       = 5
	P0ToP3P3Factories = 1
	P0ToP3P2A         = 3
	P0ToP3P2B         = 3
	P0ToP33InP2       = 2
	P0ToP3BifTotal    = 11
)

// P1ToP3Result mirrors calculateP1toP3Plan().
type P1ToP3Result struct {
	OutputTypeID        int            `json:"output_type_id"`
	OutputName          string         `json:"output_name"`
	OutputQtyPerDay     float64        `json:"output_qty_per_day"`
	FactorySlots        int            `json:"factory_slots"`
	FactorySplit        map[string]any `json:"factory_split"`
	P2Inputs            []P2Input      `json:"p2_inputs"`
	P1Inputs            []DailyInput   `json:"p1_inputs"`
	TotalDailyP1Cost    float64        `json:"total_daily_p1_cost"`
	P3SellMin           *float64       `json:"p3_sell_min"`
	P3BuyMax            *float64       `json:"p3_buy_max"`
	P3PriceSource       *string        `json:"p3_price_source"`
	DailyP3SellValue    float64        `json:"daily_p3_sell_value"`
	DailyP3BuyValue     float64        `json:"daily_p3_buy_value"`
	DailyProfitSell     float64        `json:"daily_profit_sell"`
	DailyProfitBuy      float64        `json:"daily_profit_buy"`
	MarginPercentSell   *float64       `json:"margin_percent_sell"`
	DailyImportValue    float64        `json:"daily_import_value"`
	DailyExportValue    float64        `json:"daily_export_value"`
	DailyImportTax      float64        `json:"daily_import_tax"`
	DailyExportTax      float64        `json:"daily_export_tax"`
	DailyTaxTotal       float64        `json:"daily_tax_total"`
	DailyProfitAfterTax float64        `json:"daily_profit_after_tax"`
	PricesAvailable     bool           `json:"prices_available"`
}

// P2Input is one intermediate P2 line.
type P2Input struct {
	TypeID        int     `json:"type_id"`
	Name          string  `json:"name"`
	Factories     int     `json:"factories"`
	DailyProduced float64 `json:"daily_produced"`
	DailyConsumed float64 `json:"daily_consumed"`
}

// DailyInput is one priced daily input line (P1 or P0).
type DailyInput struct {
	TypeID      int     `json:"type_id"`
	Name        string  `json:"name"`
	DailyQty    float64 `json:"daily_qty"`
	UnitCost    float64 `json:"unit_cost"`
	DailyCost   float64 `json:"daily_cost"`
	HasPrice    bool    `json:"has_price"`
	PriceSource *string `json:"price_source"`
}

func priceInput(prices map[int]PriceRow, typeID int) (unit float64, source *string, ok bool) {
	p, present := prices[typeID]
	if !present {
		return 0, nil, false
	}
	switch {
	case p.BuyMax != nil && *p.BuyMax > 0:
		return *p.BuyMax, sptr("buy_max"), true
	case p.Average != nil && *p.Average > 0:
		return *p.Average, sptr("average_price"), true
	}
	return 0, nil, false
}

func priceOutput(prices map[int]PriceRow, typeID int) (sellMin, buyMax *float64, source *string) {
	p, present := prices[typeID]
	if !present {
		return nil, nil, nil
	}
	if p.SellMin != nil && *p.SellMin > 0 {
		sellMin = fptr(*p.SellMin)
		source = sptr("sell_min")
	}
	if p.BuyMax != nil && *p.BuyMax > 0 {
		buyMax = fptr(*p.BuyMax)
		if source == nil {
			source = sptr("buy_max")
		}
	}
	if sellMin == nil && buyMax == nil && p.Average != nil && *p.Average > 0 {
		sellMin = fptr(*p.Average)
		source = sptr("average_price")
	}
	return sellMin, buyMax, source
}

func p1ToP2ByOutput(output int) *Schematic {
	for i := range SchematicsP1ToP2 {
		if SchematicsP1ToP2[i].OutputTypeID == output {
			return &SchematicsP1ToP2[i]
		}
	}
	return nil
}

// P1ToP3Plan ports calculateP1toP3Plan(). Nil when a P2 input has no
// P1→P2 schematic or the P3 recipe has an unsupported input count.
func P1ToP3Plan(p3 Schematic, prices map[int]PriceRow) *P1ToP3Result {
	n := len(p3.Inputs)
	var p3Factories int
	var p2Counts []int
	switch n {
	case 2:
		p3Factories = P1ToP3P3Factories
		p2Counts = []int{P1ToP3P2A, P1ToP3P2B}
	case 3:
		p3Factories = P1ToP33InP3
		p2Counts = []int{P1ToP33InP2, P1ToP33InP2, P1ToP33InP2}
	default:
		return nil
	}
	p2Schematics := make([]*Schematic, n)
	for i, in := range p3.Inputs {
		s := p1ToP2ByOutput(in[0])
		if s == nil {
			return nil
		}
		p2Schematics[i] = s
	}

	const p2PerCycle = 5
	p2CyclesDay := float64(SecondsPerDay) / CycleP1ToP2
	p3CyclesDay := float64(SecondsPerDay) / CycleP2ToP3
	supply := make([]float64, n)
	for i := range supply {
		supply[i] = float64(p2Counts[i]) * p2PerCycle * p2CyclesDay
	}
	effCycles := float64(p3Factories) * p3CyclesDay
	for i, in := range p3.Inputs {
		if c := supply[i] / math.Max(1, float64(in[1])); c < effCycles {
			effCycles = c
		}
	}
	effOutput := effCycles * float64(p3.OutputQty)

	consumed := make([]float64, n)
	cyclesNeeded := make([]float64, n)
	for i, in := range p3.Inputs {
		consumed[i] = effCycles * float64(in[1])
		cyclesNeeded[i] = consumed[i] / p2PerCycle
	}
	p1Qtys := map[int]float64{}
	var p1Order []int
	addP1 := func(id int, q float64) {
		if _, ok := p1Qtys[id]; !ok {
			p1Order = append(p1Order, id)
		}
		p1Qtys[id] += q
	}
	for i := range p2Schematics {
		for _, in := range p2Schematics[i].Inputs {
			addP1(in[0], cyclesNeeded[i]*float64(in[1]))
		}
	}

	r := &P1ToP3Result{
		OutputTypeID: p3.OutputTypeID, OutputName: p3.Name,
		OutputQtyPerDay: effOutput, FactorySlots: FactorySlots,
		FactorySplit:    map[string]any{"p3_factories": p3Factories, "total": FactorySlots},
		PricesAvailable: true,
	}
	for _, id := range p1Order {
		unit, source, ok := priceInput(prices, id)
		if !ok {
			r.PricesAvailable = false
		}
		cost := unit * p1Qtys[id]
		r.TotalDailyP1Cost += cost
		r.P1Inputs = append(r.P1Inputs, DailyInput{
			TypeID: id, Name: ResolveInputName(id), DailyQty: p1Qtys[id],
			UnitCost: unit, DailyCost: cost, HasPrice: source != nil, PriceSource: source,
		})
	}
	sellMin, buyMax, source := priceOutput(prices, p3.OutputTypeID)
	r.P3SellMin, r.P3BuyMax, r.P3PriceSource = sellMin, buyMax, source
	if sellMin == nil && buyMax == nil {
		r.PricesAvailable = false
	}
	sv, bv := 0.0, 0.0
	if sellMin != nil {
		sv = *sellMin * effOutput
	}
	if buyMax != nil {
		bv = *buyMax * effOutput
	}
	r.DailyP3SellValue, r.DailyP3BuyValue = sv, bv
	r.DailyProfitSell, r.DailyProfitBuy = sv-r.TotalDailyP1Cost, bv-r.TotalDailyP1Cost
	if r.TotalDailyP1Cost > 0 {
		r.MarginPercentSell = fptr(math.Round(r.DailyProfitSell/r.TotalDailyP1Cost*100*100) / 100)
	}
	for i, in := range p3.Inputs {
		name := p2Names[in[0]]
		if name == "" {
			name = "Type " + itoa(in[0])
		}
		r.P2Inputs = append(r.P2Inputs, P2Input{
			TypeID: in[0], Name: name, Factories: p2Counts[i],
			DailyProduced: supply[i], DailyConsumed: consumed[i],
		})
	}
	r.DailyImportValue, r.DailyExportValue = r.TotalDailyP1Cost, sv
	return r
}

// P0ToP3Result mirrors calculateP0toP3Plan().
type P0ToP3Result struct {
	OutputTypeID        int              `json:"output_type_id"`
	OutputName          string           `json:"output_name"`
	OutputQtyPerDay     float64          `json:"output_qty_per_day"`
	FactorySlots        int              `json:"factory_slots"`
	FactorySplit        map[string]any   `json:"factory_split"`
	P2Inputs            []P2Input        `json:"p2_inputs"`
	P1Intermediates     []P1Intermediate `json:"p1_intermediates"`
	P0Inputs            []DailyInput     `json:"p0_inputs"`
	TotalDailyP0Cost    float64          `json:"total_daily_p0_cost"`
	P3SellMin           *float64         `json:"p3_sell_min"`
	P3BuyMax            *float64         `json:"p3_buy_max"`
	P3PriceSource       *string          `json:"p3_price_source"`
	DailyP3SellValue    float64          `json:"daily_p3_sell_value"`
	DailyP3BuyValue     float64          `json:"daily_p3_buy_value"`
	DailyProfitSell     float64          `json:"daily_profit_sell"`
	DailyProfitBuy      float64          `json:"daily_profit_buy"`
	MarginPercentSell   *float64         `json:"margin_percent_sell"`
	DailyImportValue    float64          `json:"daily_import_value"`
	DailyExportValue    float64          `json:"daily_export_value"`
	DailyImportTax      float64          `json:"daily_import_tax"`
	DailyExportTax      float64          `json:"daily_export_tax"`
	DailyTaxTotal       float64          `json:"daily_tax_total"`
	DailyProfitAfterTax float64          `json:"daily_profit_after_tax"`
	PricesAvailable     bool             `json:"prices_available"`
}

// P1Intermediate is one P1 line with its BIF count.
type P1Intermediate struct {
	TypeID   int     `json:"type_id"`
	Name     string  `json:"name"`
	DailyQty float64 `json:"daily_qty"`
	BifCount int     `json:"bif_count"`
}

func p0ToP1ByOutput(output int) *Schematic {
	for i := range SchematicsP0ToP1 {
		if SchematicsP0ToP1[i].OutputTypeID == output {
			return &SchematicsP0ToP1[i]
		}
	}
	return nil
}

// P0ToP3Plan ports calculateP0toP3Plan(). Nil on missing schematics.
// Insertion order of first-seen P1 types drives the BIF allocation, as in PHP.
func P0ToP3Plan(p3 Schematic, prices map[int]PriceRow) *P0ToP3Result {
	n := len(p3.Inputs)
	var p2Counts []int
	if n == 3 {
		p2Counts = []int{P0ToP33InP2, P0ToP33InP2, P0ToP33InP2}
	} else {
		p2Counts = []int{P0ToP3P2A, P0ToP3P2B}
	}
	p2Schematics := make([]*Schematic, n)
	for i, in := range p3.Inputs {
		s := p1ToP2ByOutput(in[0])
		if s == nil {
			return nil
		}
		p2Schematics[i] = s
	}

	const bifPerDay = 20 * SecondsPerDay / CycleP0ToP1   // 960
	const p1PerAifDay = 40 * SecondsPerDay / CycleP1ToP2 // 960 per input type
	const p2PerCycle = 5
	p2CyclesDay := float64(SecondsPerDay) / CycleP1ToP2
	p3CyclesDay := float64(SecondsPerDay) / CycleP2ToP3

	// P1 demand per group, first-seen order preserved.
	demands := map[int][]float64{}
	var p1Order []int
	for i := 0; i < n; i++ {
		for _, in := range p2Schematics[i].Inputs {
			id := in[0]
			if _, ok := demands[id]; !ok {
				demands[id] = make([]float64, n)
				p1Order = append(p1Order, id)
			}
			demands[id][i] += float64(p2Counts[i]) * p1PerAifDay
		}
	}
	totalDemand := 0.0
	for _, id := range p1Order {
		for _, v := range demands[id] {
			totalDemand += v
		}
	}
	bif := map[int]int{}
	assigned := 0
	for _, id := range p1Order {
		sum := 0.0
		for _, v := range demands[id] {
			sum += v
		}
		share := 0.0
		if totalDemand > 0 {
			share = sum / totalDemand
		}
		bif[id] = max(1, int(math.Round(P0ToP3BifTotal*share)))
		assigned += bif[id]
	}
	for assigned > P0ToP3BifTotal {
		maxBif, maxID := -1, p1Order[0]
		for _, id := range p1Order {
			if bif[id] > maxBif {
				maxBif, maxID = bif[id], id
			}
		}
		if bif[maxID] <= 1 {
			break
		}
		bif[maxID]--
		assigned--
	}
	for assigned < P0ToP3BifTotal {
		minRatio := math.Inf(1)
		minID := p1Order[0]
		for _, id := range p1Order {
			sum := 0.0
			for _, v := range demands[id] {
				sum += v
			}
			supply := float64(bif[id]) * bifPerDay
			ratio := math.Inf(1)
			if sum > 0 {
				ratio = supply / sum
			}
			if ratio < minRatio {
				minRatio, minID = ratio, id
			}
		}
		bif[minID]++
		assigned++
	}

	// P1 supply split per group proportionally to demand.
	supplyFor := map[int]map[int]float64{}
	for _, id := range p1Order {
		supply := float64(bif[id]) * bifPerDay
		total := 0.0
		for _, v := range demands[id] {
			total += v
		}
		m := map[int]float64{}
		for i := 0; i < n; i++ {
			if total > 0 {
				m[i] = supply * demands[id][i] / total
			}
		}
		supplyFor[id] = m
	}
	effAif := make([]float64, n)
	for i := range effAif {
		effAif[i] = float64(p2Counts[i]) * p2CyclesDay
		for _, in := range p2Schematics[i].Inputs {
			id, qty := in[0], in[1]
			if c := supplyFor[id][i] / math.Max(1, float64(qty)); c < effAif[i] {
				effAif[i] = c
			}
		}
	}
	produced := make([]float64, n)
	for i := range produced {
		produced[i] = effAif[i] * p2PerCycle
	}
	effP3 := float64(P0ToP3P3Factories) * p3CyclesDay
	for i, in := range p3.Inputs {
		if c := produced[i] / math.Max(1, float64(in[1])); c < effP3 {
			effP3 = c
		}
	}
	effOutput := effP3 * float64(p3.OutputQty)

	consumed := make([]float64, n)
	cyclesNeeded := make([]float64, n)
	for i, in := range p3.Inputs {
		consumed[i] = effP3 * float64(in[1])
		cyclesNeeded[i] = consumed[i] / math.Max(1, p2PerCycle)
	}
	p1Qtys := map[int]float64{}
	for i := range p2Schematics {
		for _, in := range p2Schematics[i].Inputs {
			p1Qtys[in[0]] += cyclesNeeded[i] * float64(in[1])
		}
	}
	p0Qtys := map[int]float64{}
	for p1ID, q := range p1Qtys {
		s := p0ToP1ByOutput(p1ID)
		if s == nil {
			return nil
		}
		p0Qtys[s.Inputs[0][0]] += q * float64(s.Inputs[0][1]) / float64(s.OutputQty)
	}

	r := &P0ToP3Result{
		OutputTypeID: p3.OutputTypeID, OutputName: p3.Name,
		OutputQtyPerDay: effOutput, FactorySlots: FactorySlots,
		PricesAvailable: true,
	}
	r.FactorySplit = map[string]any{
		"p3_factories": P0ToP3P3Factories, "bif_total": P0ToP3BifTotal,
		"bif_allocation": bif, "total": FactorySlots,
	}
	for _, id := range p1Order {
		if q, ok := p1Qtys[id]; ok {
			name := p1Names[id]
			if name == "" {
				name = "Type " + itoa(id)
			}
			r.P1Intermediates = append(r.P1Intermediates, P1Intermediate{
				TypeID: id, Name: name, DailyQty: q, BifCount: bif[id],
			})
		}
	}
	for id, q := range p0Qtys {
		unit, source, ok := priceInput(prices, id)
		if !ok {
			r.PricesAvailable = false
		}
		cost := unit * q
		r.TotalDailyP0Cost += cost
		r.P0Inputs = append(r.P0Inputs, DailyInput{
			TypeID: id, Name: ResolveInputName(id), DailyQty: q,
			UnitCost: unit, DailyCost: cost, HasPrice: source != nil, PriceSource: source,
		})
	}
	sort.Slice(r.P0Inputs, func(i, j int) bool { return r.P0Inputs[i].TypeID < r.P0Inputs[j].TypeID })
	sellMin, buyMax, source := priceOutput(prices, p3.OutputTypeID)
	r.P3SellMin, r.P3BuyMax, r.P3PriceSource = sellMin, buyMax, source
	if sellMin == nil && buyMax == nil {
		r.PricesAvailable = false
	}
	sv, bv := 0.0, 0.0
	if sellMin != nil {
		sv = *sellMin * effOutput
	}
	if buyMax != nil {
		bv = *buyMax * effOutput
	}
	r.DailyP3SellValue, r.DailyP3BuyValue = sv, bv
	r.DailyProfitSell, r.DailyProfitBuy = sv-r.TotalDailyP0Cost, bv-r.TotalDailyP0Cost
	if r.TotalDailyP0Cost > 0 {
		r.MarginPercentSell = fptr(math.Round(r.DailyProfitSell/r.TotalDailyP0Cost*100*100) / 100)
	}
	for i, in := range p3.Inputs {
		name := p2Names[in[0]]
		if name == "" {
			name = "Type " + itoa(in[0])
		}
		r.P2Inputs = append(r.P2Inputs, P2Input{
			TypeID: in[0], Name: name, Factories: p2Counts[i],
			DailyProduced: produced[i], DailyConsumed: consumed[i],
		})
	}
	r.DailyImportValue, r.DailyExportValue = r.TotalDailyP0Cost, sv
	return r
}

// CalculateChainTier ports the p1_to_p3/p0_to_p3 branch, sorted by daily
// profit descending.
func CalculateChainTier(tier string, prices map[int]PriceRow) []any {
	out := []any{}
	for _, p3 := range SchematicsP2ToP3 {
		if tier == "p1_to_p3" {
			if r := P1ToP3Plan(p3, prices); r != nil {
				out = append(out, r)
			}
		} else {
			if r := P0ToP3Plan(p3, prices); r != nil {
				out = append(out, r)
			}
		}
	}
	sort.Slice(out, func(i, j int) bool {
		return profitOf(out[i]) > profitOf(out[j])
	})
	return out
}

func profitOf(v any) float64 {
	switch r := v.(type) {
	case *P1ToP3Result:
		return r.DailyProfitSell
	case *P0ToP3Result:
		return r.DailyProfitSell
	}
	return 0
}
