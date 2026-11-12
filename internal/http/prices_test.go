package http

import (
	"context"
	"testing"
	"time"

	"github.com/sudo-adduser-jordan/eveindustry/internal/store"
)

type stubPriceStore struct {
	agg map[int]float64
	raw []store.RawOrder
	fb  []store.FallbackPrice
}

func (s *stubPriceStore) AggregateSellPrices(ctx context.Context, typeIDs []int, regionID int) (map[int]float64, bool, error) {
	out := map[int]float64{}
	for _, id := range typeIDs {
		out[id] = s.agg[id]
	}
	return out, true, nil
}

func (s *stubPriceStore) RawSellOrders(ctx context.Context, typeIDs []int, regionID int, since time.Time) ([]store.RawOrder, error) {
	want := map[int]bool{}
	for _, id := range typeIDs {
		want[id] = true
	}
	var out []store.RawOrder
	for _, o := range s.raw {
		if want[o.TypeID] {
			out = append(out, o)
		}
	}
	return out, nil
}

func (s *stubPriceStore) FallbackPrices(ctx context.Context, typeIDs []int, regionIDs []int) ([]store.FallbackPrice, error) {
	return s.fb, nil
}

func (s *stubPriceStore) TypeExists(ctx context.Context, typeID int) (bool, error) {
	return true, nil
}

func (s *stubPriceStore) PagedOrders(ctx context.Context, typeID, regionID int, buy bool, limit, offset int) ([]store.MarketOrderRow, int, error) {
	return nil, 0, nil
}

func (s *stubPriceStore) History(ctx context.Context, typeID, regionID, days int) ([]store.MarketHistoryRow, error) {
	return nil, nil
}

func (s *stubPriceStore) FreshnessTimestamps(ctx context.Context, regionID int) (orders, costs, global *time.Time, err error) {
	return nil, nil, nil, nil
}

func (s *stubPriceStore) MarketPriceRows(ctx context.Context, typeIDs []int, regionID int) ([]store.MarketPriceRowFull, error) {
	return nil, nil
}

func TestResolvePricesFallsBackToRawOrdersPerType(t *testing.T) {
	ctx := context.Background()
	ms := &stubPriceStore{
		agg: map[int]float64{34: 5},
		raw: []store.RawOrder{
			{TypeID: 45490, Price: 1300, VolumeRemain: 6000},
			{TypeID: 45490, Price: 1400, VolumeRemain: 1500},
		},
	}
	got, err := resolvePrices(ctx, ms, []int{34, 45490}, 10000002)
	if err != nil {
		t.Fatal(err)
	}
	if got[34].Price != 5 {
		t.Fatalf("aggregate price shadowed: %+v", got[34])
	}
	if got[45490].Price <= 0 {
		t.Fatalf("missing aggregate must fall back to raw orders: %+v", got[45490])
	}
}
