// Package market holds the pure EVE market maths ported from
// app/Services/MarketService.php and app/Services/MarketFreshnessService.php.
// No I/O here: stores feed these functions and handlers shape the output.
package market

import (
	"math"
	"time"
)

// Trade hub regions. Mirrors MarketTradingService::REGIONS.
var Regions = map[int]string{
	10000002: "The Forge (Jita)",
	10000043: "Domain (Amarr)",
	10000032: "Sinq Laison (Dodixie)",
	10000042: "Heimatar (Rens)",
	10000030: "Metropolis (Hek)",
}

// DefaultRegion mirrors MarketService::DEFAULT_REGION_ID.
const DefaultRegion = 10000002

// Price sources. Mirror MarketService::PRICE_SOURCE_*.
const (
	SourceOrders   = "orders"
	SourceFallback = "fallback"
	SourceNone     = "none"
)

// MaxOrderAgeDays mirrors MarketService::MAX_ORDER_AGE_DAYS: the longest an
// EVE order can stay open, and the default window for the percentile calc.
const MaxOrderAgeDays = 90

// DefaultPercentile mirrors the 5% default in getSellPrices().
const DefaultPercentile = 5.0

// MaxTypeIDs mirrors PricesRequest::MAX_TYPE_IDS.
const MaxTypeIDs = 200

// Search limits mirror the PHP lookup services.
const (
	// MinQueryLength mirrors MarketGroupService::MIN_QUERY_LENGTH: search
	// endpoints return [] under 2 characters.
	MinQueryLength = 2
	// TypeSearchLimit mirrors MarketGroupService::SEARCH_LIMIT.
	TypeSearchLimit = 50
	// SystemSearchLimit mirrors SolarSystemLookupService::SEARCH_LIMIT.
	SystemSearchLimit = 20
	// StationSearchLimit mirrors StationLookupService::SEARCH_LIMIT.
	StationSearchLimit = 20
)

// MaxHistoryDays mirrors HistoryRequest::MAX_DAYS.
const MaxHistoryDays = 365

// DefaultHistoryDays mirrors HistoryRequest::DEFAULT_DAYS.
const DefaultHistoryDays = 30

func IsTradeRegion(regionID int) bool {
	_, ok := Regions[regionID]
	return ok
}

// Order is one [price, volume_remain] pair, cheapest first for the
// percentile average.
type Order struct {
	Price        float64
	VolumeRemain int64
}

// PercentileAverage is the "average sell 5%" maths shared with the
// precomputed aggregates. Ports MarketService::percentileAverage():
// volume-weighted average of the cheapest `percentile`% of sell volume,
// walking orders cheapest-first and partially including the order that
// crosses the target. Result rounded to 2 decimals like PHP round(..., 2).
func PercentileAverage(sortedOrders []Order, percentile float64) float64 {
	var total int64
	for _, o := range sortedOrders {
		total += o.VolumeRemain
	}
	if total <= 0 {
		return 0
	}

	target := float64(total) * (percentile / 100.0)
	if target < 1 {
		target = 1
	}

	var accumulated float64
	var weighted float64
	for _, o := range sortedOrders {
		vol := float64(o.VolumeRemain)
		if accumulated+vol <= target {
			weighted += o.Price * vol
			accumulated += vol
		} else {
			needed := target - accumulated
			weighted += o.Price * needed
			accumulated += needed
			break
		}
	}

	if accumulated <= 0 {
		return 0
	}
	return math.Round(weighted/accumulated*100) / 100
}

// FallbackPriceValue ports MarketService::marketPriceValue(): lowest sell,
// else the average; 0 when neither is positive.
func FallbackPriceValue(sellMin, average *float64) float64 {
	for _, v := range []*float64{sellMin, average} {
		if v != nil && *v > 0 {
			return *v
		}
	}
	return 0
}

// ResolvedPrice is one entry of getSellPricesWithSource().
type ResolvedPrice struct {
	TypeID int
	Price  float64
	Source string
}

// ResolveSellPrices ports MarketService::getSellPricesWithSource() over
// pre-fetched inputs: orderPrices holds the 5th-percentile figure per type
// (0/absent when the type has no sell orders) and fallbackRows holds
// market_prices rows ordered by region preference ([region, Jita]).
// Output follows the request order with duplicates removed, like the PHP
// controller building $rows from $request->typeIds().
func ResolveSellPrices(typeIDs []int, orderPrices map[int]float64, fallbackRows []FallbackRow) []ResolvedPrice {
	seen := map[int]bool{}
	out := make([]ResolvedPrice, 0, len(typeIDs))

	byRegion := map[int]map[int]FallbackRow{}
	for _, r := range fallbackRows {
		if byRegion[r.RegionID] == nil {
			byRegion[r.RegionID] = map[int]FallbackRow{}
		}
		if _, ok := byRegion[r.RegionID][r.TypeID]; !ok {
			byRegion[r.RegionID][r.TypeID] = r
		}
	}

	for _, id := range typeIDs {
		if seen[id] {
			continue
		}
		seen[id] = true

		if p := orderPrices[id]; p > 0 {
			out = append(out, ResolvedPrice{TypeID: id, Price: p, Source: SourceOrders})
			continue
		}
		price := 0.0
		for _, region := range fallbackRowsRegionOrder(fallbackRows) {
			if row, ok := byRegion[region][id]; ok {
				if v := FallbackPriceValue(row.SellMin, row.Average); v > 0 {
					price = v
					break
				}
			}
		}
		source := SourceNone
		if price > 0 {
			source = SourceFallback
		}
		out = append(out, ResolvedPrice{TypeID: id, Price: price, Source: source})
	}
	return out
}

// FallbackRow is one market_prices row relevant to price resolution.
type FallbackRow struct {
	TypeID   int
	RegionID int
	SellMin  *float64
	Average  *float64
}

// fallbackRowsRegionOrder preserves the caller's region preference order
// ([region, Jita]) instead of imposing one.
func fallbackRowsRegionOrder(rows []FallbackRow) []int {
	var order []int
	seen := map[int]bool{}
	for _, r := range rows {
		if !seen[r.RegionID] {
			seen[r.RegionID] = true
			order = append(order, r.RegionID)
		}
	}
	return order
}

// Freshness staleness windows. Mirror MarketFreshnessService constants.
const (
	OrdersStaleAfter = 2 * time.Hour
	CostsStaleAfter  = 5 * time.Hour
	GlobalStaleAfter = 26 * time.Hour
)

// Freshness sources. Mirror MarketFreshnessService::freshnessMeta(): these
// differ from the price-row sources above.
const (
	FreshnessSourceOrders = "market_orders"
	FreshnessSourcePrices = "market_prices"
	FreshnessSourceNone   = "none"
)

// FreshnessMeta is the meta.freshness block for price and cost responses.
type FreshnessMeta struct {
	PricesAsOf *string `json:"prices_as_of"`
	CostsAsOf  *string `json:"costs_as_of"`
	Source     string  `json:"source"`
	Stale      bool    `json:"stale"`
}

// BuildFreshness ports MarketFreshnessService::freshnessMeta() +
// isStale(): regional orders when the region has any timestamp, else the
// ESI global prices, else none. Stale when orders, costs or global is
// missing/older than its window (a nil timestamp counts as older).
// Nil timestamps serialize as null, matching the PHP ?string iso() output.
func BuildFreshness(now time.Time, ordersAsOf, costsAsOf, globalAsOf *time.Time) FreshnessMeta {
	iso := func(t *time.Time) *string {
		if t == nil {
			return nil
		}
		s := t.UTC().Format(time.RFC3339)
		return &s
	}

	olderThan := func(t *time.Time, window time.Duration) bool {
		return t == nil || now.Sub(*t) > window
	}
	stale := olderThan(ordersAsOf, OrdersStaleAfter) ||
		olderThan(costsAsOf, CostsStaleAfter) ||
		olderThan(globalAsOf, GlobalStaleAfter)

	if ordersAsOf != nil {
		return FreshnessMeta{
			PricesAsOf: iso(ordersAsOf),
			CostsAsOf:  iso(costsAsOf),
			Source:     FreshnessSourceOrders,
			Stale:      stale,
		}
	}
	if globalAsOf != nil {
		return FreshnessMeta{
			PricesAsOf: iso(globalAsOf),
			CostsAsOf:  iso(costsAsOf),
			Source:     FreshnessSourcePrices,
			Stale:      stale,
		}
	}
	return FreshnessMeta{
		PricesAsOf: nil,
		CostsAsOf:  iso(costsAsOf),
		Source:     FreshnessSourceNone,
		Stale:      true,
	}
}

// TypeImageURL ports EsiService::getTypeImageUrl() for the common icon case.
// (PHP swaps blueprint types to the bp style; the Go reference search keeps
// icon for all types until the blueprint lookup is ported.)
func TypeImageURL(typeID int) string {
	return "https://images.evetech.net/types/" + itoa(typeID) + "/icon?size=32"
}

func itoa(n int) string {
	if n == 0 {
		return "0"
	}
	neg := n < 0
	if neg {
		n = -n
	}
	var buf [20]byte
	i := len(buf)
	for n > 0 {
		i--
		buf[i] = byte('0' + n%10)
		n /= 10
	}
	if neg {
		i--
		buf[i] = '-'
	}
	return string(buf[i:])
}
