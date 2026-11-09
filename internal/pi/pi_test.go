package pi

import (
	"testing"
)

func fp(v float64) *float64 { return &v }

func TestCalculateSchematicProfit(t *testing.T) {
	s := Schematic{OutputTypeID: 2329, Name: "Biocells", OutputQty: 5, CycleTime: 3600,
		Inputs: [][2]int{{2396, 40}, {2399, 40}}}
	prices := map[int]PriceRow{
		2396: {BuyMax: fp(10)},
		2399: {BuyMax: fp(20)},
		2329: {SellMin: fp(300), BuyMax: fp(290)},
	}
	r := CalculateSchematicProfit(s, prices)
	// input 40*10+40*20 = 1200; sell value 5*300 = 1500; profit 300
	if r.TotalInputCost != 1200 || r.OutputSellValue != 1500 || r.ProfitPerCycleSell != 300 {
		t.Fatalf("got %+v", r)
	}
	// margin 300/1200*100 = 25
	if r.MarginPercentSell == nil || *r.MarginPercentSell != 25 {
		t.Fatalf("margin: %+v", r.MarginPercentSell)
	}
	// 24 cycles/day -> daily 7200
	if r.DailyProfitPerFactory != 7200 {
		t.Fatalf("daily: %v", r.DailyProfitPerFactory)
	}
	// missing output price -> unavailable
	r2 := CalculateSchematicProfit(s, map[int]PriceRow{2396: {BuyMax: fp(10)}, 2399: {BuyMax: fp(20)}})
	if r2.PricesAvailable || r2.OutputSellValue != 0 {
		t.Fatalf("missing output: %+v", r2)
	}
}

func TestCustomsTax(t *testing.T) {
	it, et, tot, after := CustomsTax(1000, 2000, 500, 10)
	if it != 100 || et != 200 || tot != 300 || after != 200 {
		t.Fatalf("got %v %v %v %v", it, et, tot, after)
	}
}

func TestValidTier(t *testing.T) {
	if !ValidTier("p2_to_p3") || ValidTier("nope") {
		t.Fatal("tier validation")
	}
	if len(TierTypeIDs("p0_to_p3")) == 0 {
		t.Fatal("chain tier needs transitive ids")
	}
}

func TestP1ToP3Plan(t *testing.T) {
	// Camera Drones: 10+10 P2 -> 3 P3; P2s from AIF schematics present in tables.
	var drones Schematic
	for _, s := range SchematicsP2ToP3 {
		if s.OutputTypeID == 2345 {
			drones = s
		}
	}
	makePrices := func(p1, p3 float64) map[int]PriceRow {
		m := map[int]PriceRow{}
		for _, id := range TierTypeIDs("p1_to_p3") {
			m[id] = PriceRow{BuyMax: fp(p1), SellMin: fp(p1), Average: fp(p1)}
		}
		m[2345] = PriceRow{SellMin: fp(p3)}
		return m
	}
	r := P1ToP3Plan(drones, makePrices(10, 1000))
	if r == nil {
		t.Fatal("nil plan")
	}
	// 6 P3 factories x 16 cycles = 96 max; P2 supply 6*5*24=720 each, need 10/cycle -> 72 cycles
	// -> 72 cycles x 3 = 216 output
	if r.OutputQtyPerDay != 216 {
		t.Fatalf("output: %v", r.OutputQtyPerDay)
	}
	if len(r.P1Inputs) == 0 || len(r.P2Inputs) != 2 {
		t.Fatalf("inputs: %+v", r)
	}
	// unknown recipe input count
	bad := Schematic{OutputTypeID: 1, Name: "x", OutputQty: 1, CycleTime: 1, Inputs: [][2]int{{2, 1}}}
	if P1ToP3Plan(bad, nil) != nil {
		t.Fatal("bad input count must be nil")
	}
}
