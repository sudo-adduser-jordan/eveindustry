package market

import (
	"testing"
)

func TestCalcStationTradeProfit(t *testing.T) {
	p := CalcStationTradeProfit(100, 120, 3, 3.6)
	// buy broker 3, sell broker 3.6, tax 4.32 -> net 120-100-3-3.6-4.32 = 9.08
	if p.NetProfitPerUnit != 9.08 {
		t.Fatalf("net = %+v", p)
	}
	// gross = 20/120*100 = 16.67
	if p.GrossMarginPct != 16.67 {
		t.Fatalf("gross = %+v", p)
	}
	// break-even = 103 / (1-0.066) = 110.28
	if p.BreakEven != 110.28 {
		t.Fatalf("breakeven = %+v", p)
	}
	zero := CalcStationTradeProfit(120, 100, 3, 3.6)
	if zero != (StationProfit{}) {
		t.Fatalf("inverted spread must be zero: %+v", zero)
	}
}

func TestCalcArbitrageProfit(t *testing.T) {
	p := CalcArbitrageProfit(100, 130, 3, 3.6, 10, 2)
	// tax 4.68, transport 20 -> net 130-100-4.68-20 = 5.32
	if p.NetProfitPerUnit != 5.32 {
		t.Fatalf("net = %+v", p)
	}
	if CalcArbitrageProfit(0, 130, 3, 3.6, 0, 0) != (ArbitrageProfit{}) {
		t.Fatal("zero buy must be zero")
	}
}

func TestBuildStationOpportunities(t *testing.T) {
	name := "Widget"
	cands := []StationCandidate{
		{TypeID: 1, TypeName: &name, BestBuy: 100, BestSell: 120, BuyOrderCount: 3, SellOrderCount: 5},
		{TypeID: 2, BestBuy: 10, BestSell: 11, BuyOrderCount: 1, SellOrderCount: 1},
	}
	ten, forty := 10, 40
	// type 2 has no history entry -> included with volume 0 (ESI-error path);
	// type 1 below the floor -> dropped.
	got := BuildStationOpportunities(cands, StationScanInput{BrokerFeePct: 3, SalesTaxPct: 3.6, MinMarginPct: 0, MinDailyVolume: 20},
		map[int]*int{1: &ten, 99: &forty})
	if len(got) != 1 || got[0].TypeID != 2 {
		t.Fatalf("got %+v", got)
	}
	if got[0].DailyIskProfit != 0 || got[0].TypeName != "Type #2" {
		t.Fatalf("row: %+v", got[0])
	}
}

func TestBuildArbitrageOpportunities(t *testing.T) {
	five := 5
	got := BuildArbitrageOpportunities(
		map[int]float64{1: 100, 2: 100},
		map[int]float64{1: 130, 2: 101},
		map[int]TypeVolume{1: {Name: "A", VolumeM3: 2}, 2: {Name: "B", VolumeM3: 0}},
		ArbitrageScanInput{SalesTaxPct: 3.6, MinMarginPct: 5, MinDailyVolume: 5},
		map[int]*int{1: &five},
	)
	// type 2: margin (101-100-3.636)/101*100 < 0 -> dropped; no history entry
	// for it either, but the margin filter hits first.
	if len(got) != 1 || got[0].TypeID != 1 {
		t.Fatalf("got %+v", got)
	}
	if got[0].CargoValueM3 == nil {
		t.Fatal("cargo value should be set")
	}
}

func TestDailyVolume(t *testing.T) {
	if DailyVolume([]uint64{10, 20, 30}) != 20 {
		t.Fatal("avg")
	}
	if DailyVolume(nil) != 0 {
		t.Fatal("empty")
	}
}
