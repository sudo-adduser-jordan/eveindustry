// Package pi ports FactoryPlanetPlannerService: factory-planet schematic
// tables and profitability for the P1→P2, P2→P3, P3→P4, P1→P3 and P0→P3
// tiers. Live ESI average-price fallbacks are skipped (no ESI client yet):
// rows price from local market_prices only.
package pi

import (
	"math"
	"sort"
)

const (
	CycleP1ToP2 = 3600
	CycleP2ToP3 = 5400
	CycleP3ToP4 = 18000
	CycleP0ToP1 = 1800

	FactorySlots  = 18
	SecondsPerDay = 86400
)

// TierLabels mirrors TIER_LABELS.
var TierLabels = map[string]string{
	"p1_to_p2": "P1 → P2 (Buy P1 materials)",
	"p2_to_p3": "P2 → P3 (Buy P2 materials)",
	"p3_to_p4": "P3 → P4 (Buy P3 materials)",
	"p1_to_p3": "P1 → P3 (Single Planet, Buy P1)",
	"p0_to_p3": "P0 → P3 (Single Planet, Buy P0)",
}

// ValidTiers is the request allow-list (keys of TIER_LABELS).
var ValidTiers = []string{"p1_to_p2", "p2_to_p3", "p3_to_p4", "p1_to_p3", "p0_to_p3"}

func ValidTier(tier string) bool {
	_, ok := TierLabels[tier]
	return ok
}

// Schematic is one factory schematic: output, cycle and inputs.
type Schematic struct {
	OutputTypeID int
	Name         string
	OutputQty    int
	CycleTime    int
	Inputs       [][2]int // [type_id, qty]
}

// SchematicsP1ToP2 mirrors SCHEMATICS_P1_TO_P2 (AIF: 40+40 P1 → 5 P2).
var SchematicsP1ToP2 = []Schematic{
	{2329, "Biocells", 5, CycleP1ToP2, [][2]int{{2396, 40}, {2399, 40}}},
	{3828, "Construction Blocks", 5, CycleP1ToP2, [][2]int{{2398, 40}, {2400, 40}}},
	{9836, "Consumer Electronics", 5, CycleP1ToP2, [][2]int{{2401, 40}, {2400, 40}}},
	{9832, "Coolant", 5, CycleP1ToP2, [][2]int{{2390, 40}, {3645, 40}}},
	{44, "Enriched Uranium", 5, CycleP1ToP2, [][2]int{{2399, 40}, {2400, 40}}},
	{3693, "Fertilizer", 5, CycleP1ToP2, [][2]int{{2393, 40}, {2395, 40}}},
	{15317, "Genetically Enhanced Livestock", 5, CycleP1ToP2, [][2]int{{2395, 40}, {3779, 40}}},
	{3725, "Livestock", 5, CycleP1ToP2, [][2]int{{2396, 40}, {2395, 40}}},
	{3689, "Mechanical Parts", 5, CycleP1ToP2, [][2]int{{2398, 40}, {2399, 40}}},
	{2327, "Microfiber Shielding", 5, CycleP1ToP2, [][2]int{{2397, 40}, {9828, 40}}},
	{9842, "Miniature Electronics", 5, CycleP1ToP2, [][2]int{{2401, 40}, {9828, 40}}},
	{2463, "Nanites", 5, CycleP1ToP2, [][2]int{{2393, 40}, {2398, 40}}},
	{2317, "Oxides", 5, CycleP1ToP2, [][2]int{{2392, 40}, {3683, 40}}},
	{2321, "Polyaramids", 5, CycleP1ToP2, [][2]int{{2397, 40}, {2392, 40}}},
	{3695, "Polytextiles", 5, CycleP1ToP2, [][2]int{{2396, 40}, {2397, 40}}},
	{9830, "Rocket Fuel", 5, CycleP1ToP2, [][2]int{{2389, 40}, {2390, 40}}},
	{3697, "Silicate Glass", 5, CycleP1ToP2, [][2]int{{2392, 40}, {9828, 40}}},
	{2312, "Supertensile Plastics", 5, CycleP1ToP2, [][2]int{{3683, 40}, {3779, 40}}},
	{9838, "Superconductors", 5, CycleP1ToP2, [][2]int{{2389, 40}, {3645, 40}}},
	{3691, "Synthetic Oil", 5, CycleP1ToP2, [][2]int{{2390, 40}, {3683, 40}}},
	{2319, "Test Cultures", 5, CycleP1ToP2, [][2]int{{2393, 40}, {3645, 40}}},
	{9840, "Transmitter", 5, CycleP1ToP2, [][2]int{{2389, 40}, {2401, 40}}},
	{3775, "Viral Agent", 5, CycleP1ToP2, [][2]int{{2393, 40}, {3779, 40}}},
	{2328, "Water-Cooled CPU", 5, CycleP1ToP2, [][2]int{{2398, 40}, {3645, 40}}},
}

// SchematicsP2ToP3 mirrors SCHEMATICS_P2_TO_P3 (HTPP: P2 → 3 P3).
var SchematicsP2ToP3 = []Schematic{
	{2358, "Biotech Research Reports", 3, CycleP2ToP3, [][2]int{{2463, 10}, {3725, 10}, {3828, 10}}},
	{2345, "Camera Drones", 3, CycleP2ToP3, [][2]int{{3697, 10}, {9830, 10}}},
	{2344, "Condensates", 3, CycleP2ToP3, [][2]int{{2317, 10}, {9832, 10}}},
	{2367, "Cryoprotectant Solution", 3, CycleP2ToP3, [][2]int{{2319, 10}, {3691, 10}, {3693, 10}}},
	{17392, "Data Chips", 3, CycleP2ToP3, [][2]int{{2312, 10}, {2327, 10}}},
	{2348, "Gel-Matrix Biopaste", 3, CycleP2ToP3, [][2]int{{2317, 10}, {2329, 10}, {9838, 10}}},
	{9834, "Guidance Systems", 3, CycleP2ToP3, [][2]int{{2328, 10}, {9840, 10}}},
	{2366, "Hazmat Detection Systems", 3, CycleP2ToP3, [][2]int{{3695, 10}, {3775, 10}, {9840, 10}}},
	{2361, "Hermetic Membranes", 3, CycleP2ToP3, [][2]int{{2321, 10}, {15317, 10}}},
	{17898, "High-Tech Transmitters", 3, CycleP2ToP3, [][2]int{{2321, 10}, {9840, 10}}},
	{2360, "Industrial Explosives", 3, CycleP2ToP3, [][2]int{{3693, 10}, {3695, 10}}},
	{2354, "Neocoms", 3, CycleP2ToP3, [][2]int{{2329, 10}, {3697, 10}}},
	{2352, "Nuclear Reactors", 3, CycleP2ToP3, [][2]int{{44, 10}, {2327, 10}}},
	{9846, "Planetary Vehicles", 3, CycleP2ToP3, [][2]int{{2312, 10}, {3689, 10}, {9842, 10}}},
	{9848, "Robotics", 3, CycleP2ToP3, [][2]int{{3689, 10}, {9836, 10}}},
	{2351, "Smartfab Units", 3, CycleP2ToP3, [][2]int{{3828, 10}, {9842, 10}}},
	{2349, "Supercomputers", 3, CycleP2ToP3, [][2]int{{2328, 10}, {9832, 10}, {9836, 10}}},
	{2346, "Synthetic Synapses", 3, CycleP2ToP3, [][2]int{{2312, 10}, {2319, 10}}},
	{12836, "Transcranial Microcontrollers", 3, CycleP2ToP3, [][2]int{{2329, 10}, {2463, 10}}},
	{17136, "Ukomi Superconductors", 3, CycleP2ToP3, [][2]int{{3691, 10}, {9838, 10}}},
	{28974, "Vaccines", 3, CycleP2ToP3, [][2]int{{3725, 10}, {3775, 10}}},
}

// SchematicsP3ToP4 mirrors SCHEMATICS_P3_TO_P4 (HTPP: P3/P1 → 1 P4).
var SchematicsP3ToP4 = []Schematic{
	{2867, "Broadcast Node", 1, CycleP3ToP4, [][2]int{{2354, 6}, {17392, 6}, {17898, 6}}},
	{2868, "Integrity Response Drones", 1, CycleP3ToP4, [][2]int{{2348, 6}, {2366, 6}, {9846, 6}}},
	{2869, "Nano-Factory", 1, CycleP3ToP4, [][2]int{{2360, 6}, {2398, 40}, {17136, 6}}},
	{2870, "Organic Mortar Applicators", 1, CycleP3ToP4, [][2]int{{2344, 6}, {2393, 40}, {9848, 6}}},
	{2871, "Recursive Computing Module", 1, CycleP3ToP4, [][2]int{{2346, 6}, {9834, 6}, {12836, 6}}},
	{2872, "Self-Harmonizing Power Core", 1, CycleP3ToP4, [][2]int{{2345, 6}, {2352, 6}, {2361, 6}}},
	{2875, "Sterile Conduits", 1, CycleP3ToP4, [][2]int{{2351, 6}, {3645, 40}, {28974, 6}}},
	{2876, "Wetware Mainframe", 1, CycleP3ToP4, [][2]int{{2349, 6}, {2358, 6}, {2367, 6}}},
}

// SchematicsP0ToP1 mirrors SCHEMATICS_P0_TO_P1 (BIF: 3000 P0 → 20 P1).
var SchematicsP0ToP1 = []Schematic{
	{2389, "Plasmoids", 20, CycleP0ToP1, [][2]int{{2308, 3000}}},
	{2390, "Electrolytes", 20, CycleP0ToP1, [][2]int{{2309, 3000}}},
	{2392, "Oxidizing Compound", 20, CycleP0ToP1, [][2]int{{2311, 3000}}},
	{2393, "Bacteria", 20, CycleP0ToP1, [][2]int{{2073, 3000}}},
	{2395, "Proteins", 20, CycleP0ToP1, [][2]int{{2287, 3000}}},
	{2396, "Biofuels", 20, CycleP0ToP1, [][2]int{{2288, 3000}}},
	{2397, "Industrial Fibers", 20, CycleP0ToP1, [][2]int{{2305, 3000}}},
	{2398, "Reactive Metals", 20, CycleP0ToP1, [][2]int{{2267, 3000}}},
	{2399, "Precious Metals", 20, CycleP0ToP1, [][2]int{{2270, 3000}}},
	{2400, "Toxic Metals", 20, CycleP0ToP1, [][2]int{{2272, 3000}}},
	{2401, "Chiral Structures", 20, CycleP0ToP1, [][2]int{{2306, 3000}}},
	{3645, "Water", 20, CycleP0ToP1, [][2]int{{2268, 3000}}},
	{3683, "Oxygen", 20, CycleP0ToP1, [][2]int{{2310, 3000}}},
	{3779, "Biomass", 20, CycleP0ToP1, [][2]int{{2286, 3000}}},
	{9828, "Silicon", 20, CycleP0ToP1, [][2]int{{2307, 3000}}},
}

var p0Names = map[int]string{
	2073: "Microorganisms", 2267: "Base Metals", 2268: "Aqueous Liquids",
	2270: "Noble Metals", 2272: "Heavy Metals", 2286: "Planktic Colonies",
	2287: "Complex Organisms", 2288: "Carbon Compounds", 2305: "Autotrophs",
	2306: "Non-CS Crystals", 2307: "Felsic Magma", 2308: "Suspended Plasma",
	2309: "Ionic Solutions", 2310: "Noble Gas",
}

var p1Names = map[int]string{
	2389: "Plasmoids", 2390: "Electrolytes", 2392: "Oxidizing Compound",
	2393: "Bacteria", 2395: "Proteins", 2396: "Biofuels", 2397: "Industrial Fibers",
	2398: "Reactive Metals", 2399: "Precious Metals", 2400: "Toxic Metals",
	2401: "Chiral Structures", 3645: "Water", 3683: "Oxygen",
	3779: "Biomass", 9828: "Silicon",
}

var p2Names = map[int]string{
	44: "Enriched Uranium", 2312: "Supertensile Plastics", 2317: "Oxides",
	2319: "Test Cultures", 2321: "Polyaramids", 2327: "Microfiber Shielding",
	2328: "Water-Cooled CPU", 2329: "Biocells", 2463: "Nanites",
	3689: "Mechanical Parts", 3691: "Synthetic Oil", 3693: "Fertilizer",
	3695: "Polytextiles", 3697: "Silicate Glass", 3725: "Livestock",
	3775: "Viral Agent", 3828: "Construction Blocks", 9830: "Rocket Fuel",
	9832: "Coolant", 9836: "Consumer Electronics", 9838: "Superconductors",
	9840: "Transmitter", 9842: "Miniature Electronics",
	15317: "Genetically Enhanced Livestock",
}

// ResolveInputName mirrors resolveInputName(): P0 → P1 → P2 → P3/P4
// schematic names, else "Type {id}". (P0_NAMES has no 2311 entry, so
// Reactive Gas falls back to "Type 2311", exactly like the PHP.)
func ResolveInputName(typeID int) string {
	if n, ok := p0Names[typeID]; ok {
		return n
	}
	if n, ok := p1Names[typeID]; ok {
		return n
	}
	if n, ok := p2Names[typeID]; ok {
		return n
	}
	for _, s := range SchematicsP2ToP3 {
		if s.OutputTypeID == typeID {
			return s.Name
		}
	}
	for _, s := range SchematicsP3ToP4 {
		if s.OutputTypeID == typeID {
			return s.Name
		}
	}
	return "Type " + itoa(typeID)
}

// PriceRow is one market_prices row for factory pricing.
type PriceRow struct {
	BuyMax  *float64
	SellMin *float64
	Average *float64
}

// SchematicInput is one priced input line.
type SchematicInput struct {
	TypeID      int     `json:"type_id"`
	Name        string  `json:"name"`
	Qty         int     `json:"qty"`
	UnitCost    float64 `json:"unit_cost"`
	TotalCost   float64 `json:"total_cost"`
	HasPrice    bool    `json:"has_price"`
	PriceSource *string `json:"price_source"`
}

// SchematicResult mirrors calculateSchematicProfit().
type SchematicResult struct {
	OutputTypeID             int              `json:"output_type_id"`
	OutputName               string           `json:"output_name"`
	OutputQty                int              `json:"output_qty"`
	CycleTime                int              `json:"cycle_time"`
	Inputs                   []SchematicInput `json:"inputs"`
	TotalInputCost           float64          `json:"total_input_cost"`
	OutputSellMin            *float64         `json:"output_sell_min"`
	OutputBuyMax             *float64         `json:"output_buy_max"`
	OutputPriceSource        *string          `json:"output_price_source"`
	OutputSellValue          float64          `json:"output_sell_value"`
	OutputBuyValue           float64          `json:"output_buy_value"`
	ProfitPerCycleSell       float64          `json:"profit_per_cycle_sell"`
	ProfitPerCycleBuy        float64          `json:"profit_per_cycle_buy"`
	MarginPercentSell        *float64         `json:"margin_percent_sell"`
	MarginPercentBuy         *float64         `json:"margin_percent_buy"`
	CyclesPerDay             float64          `json:"cycles_per_day"`
	DailyProfitPerFactory    float64          `json:"daily_profit_per_factory"`
	DailyProfitBuyPerFactory float64          `json:"daily_profit_buy_per_factory"`
	FactorySlots             int              `json:"factory_slots"`
	DailyInputCostPlanet     float64          `json:"daily_input_cost_planet"`
	DailyOutputSellPlanet    float64          `json:"daily_output_sell_planet"`
	DailyOutputBuyPlanet     float64          `json:"daily_output_buy_planet"`
	DailyProfitSellPlanet    float64          `json:"daily_profit_sell_planet"`
	DailyProfitBuyPlanet     float64          `json:"daily_profit_buy_planet"`
	PricesAvailable          bool             `json:"prices_available"`
	DailyImportTax           float64          `json:"daily_import_tax"`
	DailyExportTax           float64          `json:"daily_export_tax"`
	DailyTaxTotal            float64          `json:"daily_tax_total"`
	DailyProfitAfterTax      float64          `json:"daily_profit_after_tax"`
}

func fptr(v float64) *float64 { v2 := v; return &v2 }
func sptr(s string) *string   { v := s; return &v }

// CalculateSchematicProfit ports calculateSchematicProfit(). ESI average
// fallbacks are skipped: rows price from local market_prices only.
func CalculateSchematicProfit(s Schematic, prices map[int]PriceRow) SchematicResult {
	r := SchematicResult{
		OutputTypeID: s.OutputTypeID, OutputName: s.Name,
		OutputQty: s.OutputQty, CycleTime: s.CycleTime,
		FactorySlots: FactorySlots, PricesAvailable: true,
	}
	for _, in := range s.Inputs {
		id, qty := in[0], in[1]
		p := prices[id]
		var unit float64
		var source *string
		switch {
		case p.BuyMax != nil && *p.BuyMax > 0:
			unit, source = *p.BuyMax, sptr("buy_max")
		case p.Average != nil && *p.Average > 0:
			unit, source = *p.Average, sptr("average_price")
		default:
			r.PricesAvailable = false
		}
		cost := unit * float64(qty)
		r.TotalInputCost += cost
		r.Inputs = append(r.Inputs, SchematicInput{
			TypeID: id, Name: ResolveInputName(id), Qty: qty,
			UnitCost: unit, TotalCost: cost,
			HasPrice: source != nil, PriceSource: source,
		})
	}

	p := prices[s.OutputTypeID]
	if p.SellMin != nil && *p.SellMin > 0 {
		r.OutputSellMin = fptr(*p.SellMin)
		r.OutputPriceSource = sptr("sell_min")
	}
	if p.BuyMax != nil && *p.BuyMax > 0 {
		r.OutputBuyMax = fptr(*p.BuyMax)
		if r.OutputPriceSource == nil {
			r.OutputPriceSource = sptr("buy_max")
		}
	}
	if r.OutputSellMin == nil && r.OutputBuyMax == nil && p.Average != nil && *p.Average > 0 {
		r.OutputSellMin = fptr(*p.Average)
		r.OutputPriceSource = sptr("average_price")
	}
	if r.OutputSellMin == nil && r.OutputBuyMax == nil {
		r.PricesAvailable = false
	}

	sellVal, buyVal := 0.0, 0.0
	if r.OutputSellMin != nil {
		sellVal = *r.OutputSellMin * float64(s.OutputQty)
	}
	if r.OutputBuyMax != nil {
		buyVal = *r.OutputBuyMax * float64(s.OutputQty)
	}
	r.OutputSellValue, r.OutputBuyValue = sellVal, buyVal
	r.ProfitPerCycleSell = sellVal - r.TotalInputCost
	r.ProfitPerCycleBuy = buyVal - r.TotalInputCost
	if r.TotalInputCost > 0 {
		r.MarginPercentSell = fptr(math.Round(r.ProfitPerCycleSell/r.TotalInputCost*100*100) / 100)
		r.MarginPercentBuy = fptr(math.Round(r.ProfitPerCycleBuy/r.TotalInputCost*100*100) / 100)
	}
	if s.CycleTime > 0 {
		r.CyclesPerDay = float64(SecondsPerDay) / float64(s.CycleTime)
	}
	r.DailyProfitPerFactory = r.ProfitPerCycleSell * r.CyclesPerDay
	r.DailyProfitBuyPerFactory = r.ProfitPerCycleBuy * r.CyclesPerDay
	r.DailyInputCostPlanet = r.TotalInputCost * r.CyclesPerDay * FactorySlots
	r.DailyOutputSellPlanet = sellVal * r.CyclesPerDay * FactorySlots
	r.DailyOutputBuyPlanet = buyVal * r.CyclesPerDay * FactorySlots
	r.DailyProfitSellPlanet = r.DailyProfitPerFactory * FactorySlots
	r.DailyProfitBuyPlanet = r.DailyProfitBuyPerFactory * FactorySlots
	return r
}

// CalculateSimpleTier ports the p1_to_p2/p2_to_p3/p3_to_p4 branch of
// calculateProfitability(): every schematic priced, sorted by daily profit.
func CalculateSimpleTier(schematics []Schematic, prices map[int]PriceRow) []SchematicResult {
	out := make([]SchematicResult, 0, len(schematics))
	for _, s := range schematics {
		out = append(out, CalculateSchematicProfit(s, prices))
	}
	sort.Slice(out, func(i, j int) bool { return out[i].DailyProfitPerFactory > out[j].DailyProfitPerFactory })
	return out
}

// SchematicsForTier returns the table for the three simple tiers.
func SchematicsForTier(tier string) []Schematic {
	switch tier {
	case "p1_to_p2":
		return SchematicsP1ToP2
	case "p2_to_p3":
		return SchematicsP2ToP3
	case "p3_to_p4":
		return SchematicsP3ToP4
	}
	return nil
}

// TierTypeIDs collects every type id a tier needs prices for: schematic
// inputs and outputs, plus the transitive P1/P0 inputs of the chain tiers.
func TierTypeIDs(tier string) []int {
	seen := map[int]bool{}
	var out []int
	add := func(id int) {
		if !seen[id] {
			seen[id] = true
			out = append(out, id)
		}
	}
	tables := SchematicsForTier(tier)
	if tier == "p1_to_p3" || tier == "p0_to_p3" {
		tables = SchematicsP2ToP3
	}
	for _, s := range tables {
		add(s.OutputTypeID)
		for _, in := range s.Inputs {
			add(in[0])
		}
	}
	if tier == "p1_to_p3" || tier == "p0_to_p3" {
		for _, s := range SchematicsP1ToP2 {
			add(s.OutputTypeID)
			for _, in := range s.Inputs {
				add(in[0])
			}
		}
	}
	if tier == "p0_to_p3" {
		for _, s := range SchematicsP0ToP1 {
			add(s.OutputTypeID)
			for _, in := range s.Inputs {
				add(in[0])
			}
		}
	}
	return out
}

// CustomsTax ports the per-row tax math of applyCustomsTax(): tax_rate% on
// the import and export values, subtracted from profit. Callers pass the
// planet-level values for simple tiers or the daily import/export values
// for the P1/P0 → P3 chain tiers.
func CustomsTax(importVal, exportVal, profit, rate float64) (importTax, exportTax, total, afterTax float64) {
	importTax = importVal * rate / 100
	exportTax = exportVal * rate / 100
	total = importTax + exportTax
	return importTax, exportTax, total, profit - total
}

// ApplyCustomsTax applies CustomsTax to every simple-tier row in place.
func ApplyCustomsTax(results []SchematicResult, rate float64) []SchematicResult {
	for i := range results {
		it, et, tot, after := CustomsTax(
			results[i].DailyInputCostPlanet, results[i].DailyOutputSellPlanet,
			results[i].DailyProfitSellPlanet, rate)
		results[i].DailyImportTax, results[i].DailyExportTax = it, et
		results[i].DailyTaxTotal, results[i].DailyProfitAfterTax = tot, after
	}
	return results
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
