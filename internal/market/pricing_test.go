package market

import (
	"testing"
	"time"
)

func fp(v float64) *float64 { return &v }

func TestPercentileAverage(t *testing.T) {
	tests := []struct {
		name       string
		orders     []Order
		percentile float64
		want       float64
	}{
		{"empty", nil, 5, 0},
		{"zero volume", []Order{{Price: 10, VolumeRemain: 0}}, 5, 0},
		// total 200, target 10: first order crosses -> 10 units @ 10.0
		{"single crossing", []Order{{10, 100}, {11, 100}}, 5, 10},
		// total 100, target 5: full first order (5 units @ 8) fits exactly
		{"exact fit", []Order{{8, 5}, {20, 95}}, 5, 8},
		// total 1000, target 50: 50 units @ 5.0
		{"partial first", []Order{{5, 100}, {7, 900}}, 5, 5},
		// total 100, target 50 (50th pct): 30@10 + 20 of 40@20 -> (300+400)/50 = 14
		{"spanning two", []Order{{10, 30}, {20, 40}, {30, 30}}, 50, 14},
		// rounding: (0.105*1)/1 = 0.105 -> 0.11? weighted/acc = 0.105 -> round 0.11
		{"rounds half up", []Order{{0.105, 1}}, 100, 0.11},
		// percentile 100 takes everything: (10*1+20*1)/2 = 15
		{"full volume", []Order{{10, 1}, {20, 1}}, 100, 15},
	}
	for _, tc := range tests {
		if got := PercentileAverage(tc.orders, tc.percentile); got != tc.want {
			t.Errorf("%s: got %v want %v", tc.name, got, tc.want)
		}
	}
}

func TestFallbackPriceValue(t *testing.T) {
	if got := FallbackPriceValue(fp(9.5), fp(10)); got != 9.5 {
		t.Errorf("prefers sell_min, got %v", got)
	}
	if got := FallbackPriceValue(nil, fp(10)); got != 10 {
		t.Errorf("falls back to average, got %v", got)
	}
	if got := FallbackPriceValue(nil, nil); got != 0 {
		t.Errorf("no data is 0, got %v", got)
	}
	if got := FallbackPriceValue(fp(0), fp(-1)); got != 0 {
		t.Errorf("non-positive is 0, got %v", got)
	}
}

func TestResolveSellPrices(t *testing.T) {
	rows := []FallbackRow{
		{TypeID: 35, RegionID: 10000002, Average: fp(7.5)},
		{TypeID: 36, RegionID: 10000043, SellMin: fp(3.25)},
	}
	got := ResolveSellPrices(
		[]int{34, 35, 36, 37, 34}, // duplicate 34 collapses
		map[int]float64{34: 10.5},
		rows,
	)
	want := []ResolvedPrice{
		{34, 10.5, SourceOrders},
		{35, 7.5, SourceFallback},
		{36, 3.25, SourceFallback},
		{37, 0, SourceNone},
	}
	if len(got) != len(want) {
		t.Fatalf("got %d entries, want %d", len(got), len(want))
	}
	for i := range want {
		if got[i] != want[i] {
			t.Errorf("[%d] got %+v want %+v", i, got[i], want[i])
		}
	}
}

func TestBuildFreshness(t *testing.T) {
	now := time.Date(2026, 11, 8, 12, 0, 0, 0, time.UTC)
	fresh := now.Add(-30 * time.Minute)
	staleTs := now.Add(-3 * time.Hour)
	costs := now.Add(-time.Hour)
	global := now.Add(-time.Hour)

	m := BuildFreshness(now, &fresh, &costs, &global)
	if m.Source != FreshnessSourceOrders || m.Stale || m.PricesAsOf == nil {
		t.Errorf("fresh orders: %+v", m)
	}
	// stale orders make the whole block stale (isStale ORs the windows)
	m = BuildFreshness(now, &staleTs, &costs, &global)
	if !m.Stale || m.Source != FreshnessSourceOrders {
		t.Errorf("old orders should be stale: %+v", m)
	}
	// missing costs alone also stale
	m = BuildFreshness(now, &fresh, nil, &global)
	if !m.Stale {
		t.Errorf("missing costs should be stale: %+v", m)
	}
	m = BuildFreshness(now, nil, &costs, &fresh)
	// Missing orders timestamps count as stale (PHP olderThan(null) is true),
	// even when global prices are fresh.
	if m.Source != FreshnessSourcePrices || !m.Stale {
		t.Errorf("global fallback: %+v", m)
	}
	m = BuildFreshness(now, nil, nil, nil)
	if m.Source != FreshnessSourceNone || !m.Stale || m.PricesAsOf != nil {
		t.Errorf("no data: %+v", m)
	}
}

func TestIsTradeRegion(t *testing.T) {
	if !IsTradeRegion(10000002) || IsTradeRegion(99999999) {
		t.Error("region allow-list wrong")
	}
}
