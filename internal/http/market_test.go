package http

import (
	"context"
	"net/http"
	"testing"
	"time"

	"github.com/sudo-adduser-jordan/eveindustry/internal/store"
)

// stubMarket is an in-memory store.MarketStore for handler tests.
type stubMarket struct {
	aggregates   map[int]float64
	hasSnapshot  bool
	rawOrders    []store.RawOrder
	fallbacks    []store.FallbackPrice
	orders       []store.MarketOrderRow
	ordersTotal  int
	history      []store.MarketHistoryRow
	ordersAt     *time.Time
	costsAt      *time.Time
	globalAt     *time.Time
	existingType map[int]bool
}

func (s *stubMarket) AggregateSellPrices(ctx context.Context, typeIDs []int, regionID int) (map[int]float64, bool, error) {
	if !s.hasSnapshot {
		return nil, false, nil
	}
	out := map[int]float64{}
	for _, id := range typeIDs {
		out[id] = s.aggregates[id]
	}
	return out, true, nil
}

func (s *stubMarket) RawSellOrders(ctx context.Context, typeIDs []int, regionID int, since time.Time) ([]store.RawOrder, error) {
	return s.rawOrders, nil
}

func (s *stubMarket) FallbackPrices(ctx context.Context, typeIDs []int, regionIDs []int) ([]store.FallbackPrice, error) {
	return s.fallbacks, nil
}

func (s *stubMarket) TypeExists(ctx context.Context, typeID int) (bool, error) {
	return s.existingType[typeID], nil
}

func (s *stubMarket) PagedOrders(ctx context.Context, typeID, regionID int, buy bool, limit, offset int) ([]store.MarketOrderRow, int, error) {
	return s.orders, s.ordersTotal, nil
}

func (s *stubMarket) History(ctx context.Context, typeID, regionID, days int) ([]store.MarketHistoryRow, error) {
	return s.history, nil
}

func (s *stubMarket) FreshnessTimestamps(ctx context.Context, regionID int) (*time.Time, *time.Time, *time.Time, error) {
	return s.ordersAt, s.costsAt, s.globalAt, nil
}
func (s *stubMarket) MarketPriceRows(ctx context.Context, typeIDs []int, regionID int) ([]store.MarketPriceRowFull, error) {
	return nil, nil
}

func marketTestDeps() Deps {
	now := time.Now()
	fresh := now.Add(-30 * time.Minute)
	authStub := testAuth()
	return Deps{Market: &stubMarket{
		aggregates:   map[int]float64{34: 10.5},
		hasSnapshot:  true,
		existingType: map[int]bool{34: true},
		ordersAt:     &fresh,
		costsAt:      &fresh,
		globalAt:     &fresh,
	}, Sessions: authStub, Users: authStub}
}

func TestPricesFromAggregates(t *testing.T) {
	d := marketTestDeps()
	code, body := get(t, d, "/api/v1/market/prices?type_ids[]=34&type_ids[]=35")
	if code != http.StatusOK {
		t.Fatalf("code = %d body %v", code, body)
	}
	rows := body["data"].([]any)
	if len(rows) != 2 {
		t.Fatalf("rows = %v", rows)
	}
	first := rows[0].(map[string]any)
	if first["price"].(float64) != 10.5 || first["source"] != "orders" {
		t.Fatalf("aggregated row: %v", first)
	}
	second := rows[1].(map[string]any)
	if second["price"].(float64) != 0 || second["source"] != "none" {
		t.Fatalf("missing row: %v", second)
	}
	if body["meta"].(map[string]any)["region_id"].(float64) != 10000002 {
		t.Fatalf("default region: %v", body["meta"])
	}
}

func TestPricesRejectBadInput(t *testing.T) {
	d := marketTestDeps()
	code, body := get(t, d, "/api/v1/market/prices?region_id=1")
	if code != http.StatusUnprocessableEntity || body["code"] != "validation_failed" {
		t.Fatalf("bad region: %d %v", code, body)
	}
	code, _ = get(t, d, "/api/v1/market/prices")
	if code != http.StatusUnprocessableEntity {
		t.Fatalf("missing type_ids: %d", code)
	}
}

func TestPricesWithoutSnapshotUseRawOrders(t *testing.T) {
	d := marketTestDeps()
	m := d.Market.(*stubMarket)
	m.hasSnapshot = false
	m.rawOrders = []store.RawOrder{
		{TypeID: 34, Price: 10, VolumeRemain: 100},
		{TypeID: 34, Price: 12, VolumeRemain: 100},
	}
	code, body := get(t, d, "/api/v1/market/prices?type_ids[]=34")
	if code != http.StatusOK {
		t.Fatalf("code = %d", code)
	}
	row := body["data"].([]any)[0].(map[string]any)
	// total 200, target 10 -> 10 units @ 10.0
	if row["price"].(float64) != 10 || row["source"] != "orders" {
		t.Fatalf("raw-order price: %v", row)
	}
}

func TestHistory404ForUnknownType(t *testing.T) {
	d := marketTestDeps()
	code, _ := get(t, d, "/api/v1/market/history/999")
	if code != http.StatusNotFound {
		t.Fatalf("unknown type history = %d", code)
	}
}

func TestFreshnessListsRegions(t *testing.T) {
	d := marketTestDeps()
	code, body := get(t, d, "/api/v1/market/freshness")
	if code != http.StatusOK {
		t.Fatalf("code = %d", code)
	}
	if len(body["data"].([]any)) != 5 {
		t.Fatalf("want 5 regions: %v", body["data"])
	}
}
