package market

import (
	"math"
	"time"
)

var Regions = map[int]string{
	10000002: "The Forge (Jita)",
	10000043: "Domain (Amarr)",
	10000032: "Sinq Laison (Dodixie)",
	10000042: "Heimatar (Rens)",
	10000030: "Metropolis (Hek)",
}

const DefaultRegion = 10000002

const DefaultSellRegion = 10000043

const (
	SourceOrders   = "orders"
	SourceFallback = "fallback"
	SourceNone     = "none"
)

const MaxOrderAgeDays = 90

const DefaultPercentile = 5.0

const MaxTypeIDs = 200

const (
	MinQueryLength     = 2
	TypeSearchLimit    = 50
	SystemSearchLimit  = 20
	StationSearchLimit = 20
)

const MaxHistoryDays = 365

const DefaultHistoryDays = 30

func IsTradeRegion(regionID int) bool {
	_, ok := Regions[regionID]
	return ok
}

type Order struct {
	Price        float64
	VolumeRemain int64
}

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

func FallbackPriceValue(sellMin, average *float64) float64 {
	for _, v := range []*float64{sellMin, average} {
		if v != nil && *v > 0 {
			return *v
		}
	}
	return 0
}

type ResolvedPrice struct {
	TypeID int
	Price  float64
	Source string
}

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

type FallbackRow struct {
	TypeID   int
	RegionID int
	SellMin  *float64
	Average  *float64
}

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

const (
	OrdersStaleAfter = 2 * time.Hour
	CostsStaleAfter  = 5 * time.Hour
	GlobalStaleAfter = 26 * time.Hour
)

const (
	FreshnessSourceOrders = "market_orders"
	FreshnessSourcePrices = "market_prices"
	FreshnessSourceNone   = "none"
)

type FreshnessMeta struct {
	PricesAsOf *string `json:"prices_as_of"`
	CostsAsOf  *string `json:"costs_as_of"`
	Source     string  `json:"source"`
	Stale      bool    `json:"stale"`
}

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
