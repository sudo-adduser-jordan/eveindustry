package http

import (
	"context"
	"net/http"
	"testing"

	"github.com/sudo-adduser-jordan/eveindustry/internal/market"
	"github.com/sudo-adduser-jordan/eveindustry/internal/store"
)

func (s *stubMarket) StationCandidates(ctx context.Context, regionID int, brokerRate, taxRate, minMargin float64) ([]market.StationCandidate, error) {
	name := "Widget"
	return []market.StationCandidate{
		{TypeID: 34, TypeName: &name, BestBuy: 100, BestSell: 120, BuyOrderCount: 2, SellOrderCount: 4},
	}, nil
}

func (s *stubMarket) BestPrices(ctx context.Context, regionID int, buy bool) (map[int]float64, error) {
	if buy {
		return map[int]float64{34: 130}, nil
	}
	return map[int]float64{34: 100}, nil
}

func (s *stubMarket) ArbitrageTypes(ctx context.Context, typeIDs []int) (map[int]market.TypeVolume, error) {
	return map[int]market.TypeVolume{34: {Name: "Widget", VolumeM3: 2}}, nil
}

func tradingTestDeps() Deps {
	d := marketTestDeps()
	m := d.Market.(*stubMarket)
	d.Trading = m
	m.history = []store.MarketHistoryRow{
		{Volume: 10}, {Volume: 20}, {Volume: 30},
	}
	return d
}

func TestStationTrading(t *testing.T) {
	d := tradingTestDeps()
	code, body := get(t, d, "/api/v1/market/station-trading?region_id=10000002")
	if code != http.StatusOK {
		t.Fatalf("code = %d %v", code, body)
	}
	rows := body["data"].([]any)
	if len(rows) != 1 {
		t.Fatalf("rows = %v", rows)
	}
	row := rows[0].(map[string]any)
	if row["daily_isk_profit"].(float64) != 182 {
		t.Fatalf("row = %v", row)
	}
	if body["meta"].(map[string]any)["region"].(map[string]any)["region_name"] != "The Forge (Jita)" {
		t.Fatalf("meta = %v", body["meta"])
	}
}

func TestStationTradingRejectsBadFee(t *testing.T) {
	d := tradingTestDeps()
	if code, _ := get(t, d, "/api/v1/market/station-trading?broker_fee=99"); code != http.StatusUnprocessableEntity {
		t.Fatalf("code = %d", code)
	}
}

func TestRegionArbitrage(t *testing.T) {
	d := tradingTestDeps()
	code, body := get(t, d, "/api/v1/market/region-arbitrage?buy_region_id=10000002&sell_region_id=10000043")
	if code != http.StatusOK {
		t.Fatalf("code = %d %v", code, body)
	}
	rows := body["data"].([]any)
	if len(rows) != 1 {
		t.Fatalf("rows = %v", rows)
	}
	meta := body["meta"].(map[string]any)
	if _, ok := meta["freshness_by_region"].(map[string]any)["10000002"]; !ok {
		t.Fatalf("freshness_by_region = %v", meta)
	}
	if code, _ := get(t, d, "/api/v1/market/region-arbitrage?buy_region_id=10000002&sell_region_id=10000002"); code != http.StatusUnprocessableEntity {
		t.Fatalf("same region = %d", code)
	}
}
