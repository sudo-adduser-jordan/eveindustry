package calc

import (
	"testing"
)

func TestCalculateMaterials(t *testing.T) {
	got := CalculateMaterials([]BaseMaterial{{TypeID: 34, Name: "Trit", Quantity: 100}}, 10, 10, 0)
	if got[0].AdjustedQuantity != 900 {
		t.Fatalf("got %+v", got[0])
	}
	if got[0].QuantityPerRun != 90 {
		t.Fatalf("per run: %+v", got[0])
	}
	got = CalculateMaterials([]BaseMaterial{{TypeID: 35, Quantity: 1}}, 10, 1, 0)
	if got[0].AdjustedQuantity != 1 {
		t.Fatalf("floor: %+v", got[0])
	}
	got = CalculateMaterials([]BaseMaterial{{TypeID: 36, Quantity: 100}}, 0, 1, 4)
	if got[0].AdjustedQuantity != 96 {
		t.Fatalf("structure: %+v", got[0])
	}
}

func TestCalculateProductionTime(t *testing.T) {
	got := CalculateProductionTime(3600, 20, 5, 5, 5, 2)
	if got.TimePerRunSeconds != 1919 || got.TotalTimeSeconds != 9596 {
		t.Fatalf("got %+v", got)
	}
	if got.TotalTimeFormatted != "2h 39m 56s" {
		t.Fatalf("format: %q", got.TotalTimeFormatted)
	}
}

func TestFormatDuration(t *testing.T) {
	cases := map[int]string{0: "0s", 59: "59s", 60: "1m 0s", 3661: "1h 1m 1s", 90061: "1d 1h 1m 1s"}
	for in, want := range cases {
		if got := FormatDuration(in); got != want {
			t.Fatalf("%d: got %q want %q", in, got, want)
		}
	}
}

func TestReactionMaterialsAndTime(t *testing.T) {
	got := ReactionMaterials([]BaseMaterial{{TypeID: 34, Quantity: 50}}, 5, 0)
	if got[0].AdjustedQuantity != 250 {
		t.Fatalf("got %+v", got[0])
	}
	tm := ReactionTime(600, 5, 5, 0)
	if tm.TimePerRunSeconds != 480 || tm.TotalTimeSeconds != 2400 {
		t.Fatalf("got %+v", tm)
	}
}

func TestSummaryAndTotals(t *testing.T) {
	scc, profit, margin := Summary(1000, 800, 0)
	if scc != 40 || profit != 160 || margin != 20.0 {
		t.Fatalf("got %v %v %v", scc, profit, margin)
	}
	if Margin(10, 0) != 0 {
		t.Fatal("zero input cost margin")
	}
	rows, totals := Totals([]QueueItem{{Revenue: 1000, InputCost: 800, Fees: 40}})
	if len(rows) != 1 || rows[0].Profit != 160 || totals.ProfitMargin != 20.0 {
		t.Fatalf("got %+v %+v", rows, totals)
	}
}

func TestCalculateJob(t *testing.T) {
	mats := []Material{{TypeID: 34, Name: "Trit", BaseQuantity: 100, AdjustedQuantity: 900, QuantityPerRun: 90}}
	in := JobInput{
		Materials: mats, ProductTypeID: 587, Runs: 10, PortionSize: 1,
		Prices:            map[int]PricedValue{34: {Price: 10, Source: "orders"}, 587: {Price: 10000, Source: "orders"}},
		Volumes:           map[int]float64{34: 0.01},
		BuybackMultiplier: 1,
	}
	got := CalculateJob(in, JobTime{})
	if got.TotalBuyCost != 9000 || got.TotalRevenue != 100000 || got.SCCFee != 4000 {
		t.Fatalf("got buy=%v rev=%v scc=%v", got.TotalBuyCost, got.TotalRevenue, got.SCCFee)
	}
	if got.ProfitFullBuy != 87000 || got.MarginFullBuy != 966.7 {
		t.Fatalf("got profit=%v margin=%v", got.ProfitFullBuy, got.MarginFullBuy)
	}
	if got.TotalBuildCost != 9000 || got.BuildCheaper {
		t.Fatalf("build: %+v", got)
	}
	if got.BuildFee.HasData {
		t.Fatal("no systems -> no fee data")
	}
}
