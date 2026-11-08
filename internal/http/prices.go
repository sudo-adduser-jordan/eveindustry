package http

import (
	"context"
	"time"

	"github.com/sudo-adduser-jordan/eveindustry/internal/market"
	"github.com/sudo-adduser-jordan/eveindustry/internal/store"
)

// resolvePrices ports MarketService::getSellPricesWithSource() over the
// MarketStore: active-sweep aggregates when the region was swept, else raw
// orders at the 90-day/5% defaults, with market_prices fallback for
// [region, Jita]. Shared by the price and calculator endpoints.
func resolvePrices(ctx context.Context, ms store.MarketStore, typeIDs []int, regionID int) (map[int]market.ResolvedPrice, error) {
	dedup := map[int]bool{}
	var ordered []int
	for _, id := range typeIDs {
		if !dedup[id] {
			dedup[id] = true
			ordered = append(ordered, id)
		}
	}
	orderPrices := map[int]float64{}
	if agg, hasSnapshot, err := ms.AggregateSellPrices(ctx, ordered, regionID); err != nil {
		return nil, err
	} else if hasSnapshot {
		orderPrices = agg
	} else {
		raw, err := ms.RawSellOrders(ctx, ordered, regionID, time.Now().AddDate(0, 0, -market.MaxOrderAgeDays))
		if err != nil {
			return nil, err
		}
		byType := map[int][]market.Order{}
		for _, o := range raw {
			byType[o.TypeID] = append(byType[o.TypeID], market.Order{Price: o.Price, VolumeRemain: int64(o.VolumeRemain)})
		}
		for _, id := range ordered {
			orders := byType[id]
			for i := 1; i < len(orders); i++ {
				for j := i; j > 0 && orders[j].Price < orders[j-1].Price; j-- {
					orders[j], orders[j-1] = orders[j-1], orders[j]
				}
			}
			orderPrices[id] = market.PercentileAverage(orders, market.DefaultPercentile)
		}
	}

	fallbackRegions := []int{regionID}
	if regionID != market.DefaultRegion {
		fallbackRegions = append(fallbackRegions, market.DefaultRegion)
	}
	rows, err := ms.FallbackPrices(ctx, ordered, fallbackRegions)
	if err != nil {
		return nil, err
	}
	ordered2 := map[int][]store.FallbackPrice{}
	for _, r := range rows {
		ordered2[r.RegionID] = append(ordered2[r.RegionID], r)
	}
	var fallbackRows []market.FallbackRow
	for _, region := range fallbackRegions {
		for _, r := range ordered2[region] {
			fallbackRows = append(fallbackRows, toFallbackRow(r))
		}
	}
	out := make(map[int]market.ResolvedPrice, len(ordered))
	for _, r := range market.ResolveSellPrices(ordered, orderPrices, fallbackRows) {
		out[r.TypeID] = r
	}
	return out, nil
}
