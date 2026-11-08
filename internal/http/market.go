package http

import (
	"net/http"
	"strconv"
	"time"

	"github.com/labstack/echo/v4"

	"github.com/sudo-adduser-jordan/eveindustry/internal/market"
	"github.com/sudo-adduser-jordan/eveindustry/internal/store"
)

// registerMarket mounts GET /api/v1/market/* and /market/freshness, mirroring
// routes/api_v1/market.php and MarketFreshnessController. The trading
// scanners, appraisals and contracts stay on PHP until their services move.
func registerMarket(v1 *echo.Group, d Deps) {
	if d.Market == nil {
		return
	}
	v1.GET("/market/prices", handlePrices(d))
	v1.GET("/market/history/:typeId", handleHistory(d))
	v1.GET("/market/orders", handleOrders(d))
	v1.GET("/market/freshness", handleFreshness(d))
}

// GET /market/prices?type_ids[]=&region_id= — mirrors MarketDataController@prices:
// one row per requested type in request order (duplicates removed), with the
// meta.region_id + meta.freshness block.
func handlePrices(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		verrs := validationErrors{}
		typeIDs := parseTypeIDs(c, verrs)
		regionID := parseRegionID(c, verrs)
		if len(verrs) > 0 {
			return verrs.invalid(c)
		}
		ctx := c.Request().Context()

		// 1. orders: active-sweep aggregates, else raw market_orders.
		// Mirrors MarketService::orderSellPrices() incl. the 90-day default
		// window and the 5% default percentile.
		orderPrices := map[int]float64{}
		if agg, hasSnapshot, err := d.Market.AggregateSellPrices(ctx, typeIDs, regionID); err != nil {
			return dbError(c, err)
		} else if hasSnapshot {
			orderPrices = agg
		} else {
			raw, err := d.Market.RawSellOrders(ctx, typeIDs, regionID, time.Now().AddDate(0, 0, -market.MaxOrderAgeDays))
			if err != nil {
				return dbError(c, err)
			}
			byType := map[int][]market.Order{}
			for _, o := range raw {
				byType[o.TypeID] = append(byType[o.TypeID], market.Order{Price: o.Price, VolumeRemain: int64(o.VolumeRemain)})
			}
			for _, id := range typeIDs {
				orders := byType[id]
				// Sort cheapest first, like the PHP sortBy('price').
				for i := 1; i < len(orders); i++ {
					for j := i; j > 0 && orders[j].Price < orders[j-1].Price; j-- {
						orders[j], orders[j-1] = orders[j-1], orders[j]
					}
				}
				orderPrices[id] = market.PercentileAverage(orders, market.DefaultPercentile)
			}
		}

		// 2. fallback: market_prices for the region, then Jita.
		fallbackRegions := []int{regionID}
		if regionID != market.DefaultRegion {
			fallbackRegions = append(fallbackRegions, market.DefaultRegion)
		}
		var fallbackRows []market.FallbackRow
		if rows, err := d.Market.FallbackPrices(ctx, typeIDs, fallbackRegions); err != nil {
			return dbError(c, err)
		} else {
			// Preserve [region, Jita] preference order for ResolveSellPrices.
			ordered := map[int][]store.FallbackPrice{}
			for _, r := range rows {
				ordered[r.RegionID] = append(ordered[r.RegionID], r)
			}
			for _, region := range fallbackRegions {
				for _, r := range ordered[region] {
					fallbackRows = append(fallbackRows, toFallbackRow(r))
				}
			}
		}

		resolved := market.ResolveSellPrices(typeIDs, orderPrices, fallbackRows)
		rows := make([]any, 0, len(resolved))
		for _, r := range resolved {
			rows = append(rows, map[string]any{
				"type_id": r.TypeID, "price": r.Price, "source": r.Source,
			})
		}
		return Success(c, http.StatusOK, rows, map[string]any{
			"region_id": regionID,
			"freshness": freshnessFor(c, d, regionID),
		})
	}
}

// GET /market/history/:typeId?region_id=&days=&page=&per_page= — mirrors
// MarketDataController@history (404 when the type is unknown).
func handleHistory(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		verrs := validationErrors{}
		regionID := parseRegionID(c, verrs)
		days := market.DefaultHistoryDays
		if raw := c.QueryParam("days"); raw != "" {
			n, err := strconv.Atoi(raw)
			if err != nil || n < 1 || n > market.MaxHistoryDays {
				verrs.add("days", "The days field must be between 1 and 365.")
			} else {
				days = n
			}
		}
		page, perPage := parsePagination(c, verrs)
		if len(verrs) > 0 {
			return verrs.invalid(c)
		}
		typeID, err := strconv.Atoi(c.Param("typeId"))
		if err != nil || typeID < 1 {
			return Failure(c, http.StatusNotFound, "Type not found.", nil)
		}
		ctx := c.Request().Context()
		exists, err := d.Market.TypeExists(ctx, typeID)
		if err != nil {
			return dbError(c, err)
		}
		if !exists {
			return Failure(c, http.StatusNotFound, "Type not found.", nil)
		}
		rows, err := d.Market.History(ctx, typeID, regionID, days)
		if err != nil {
			return dbError(c, err)
		}
		offset, pagination := paginate(len(rows), page, perPage)
		end := offset + perPage
		if end > len(rows) {
			end = len(rows)
		}
		out := make([]any, 0, end-offset)
		for _, r := range rows[offset:end] {
			out = append(out, map[string]any{
				"date":        r.Date.Format("2006-01-02"),
				"average":     r.Average,
				"highest":     r.Highest,
				"lowest":      r.Lowest,
				"volume":      r.Volume,
				"order_count": r.OrderCount,
			})
		}
		return Success(c, http.StatusOK, out, map[string]any{
			"type_id": typeID, "region_id": regionID,
			"freshness":  freshnessFor(c, d, regionID),
			"pagination": pagination,
		})
	}
}

// GET /market/orders?type_id=&region_id=&side=&page=&per_page= — mirrors
// MarketDataController@orders (best first: sells cheapest, buys highest).
func handleOrders(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		verrs := validationErrors{}
		regionID := parseRegionID(c, verrs)
		side := c.QueryParam("side")
		if side == "" {
			side = "sell"
		}
		if side != "sell" && side != "buy" {
			verrs.add("side", "The selected side is invalid.")
		}
		typeID := 0
		if raw := c.QueryParam("type_id"); raw == "" {
			verrs.add("type_id", "The type id field is required.")
		} else if n, err := strconv.Atoi(raw); err != nil || n < 1 {
			verrs.add("type_id", "The type id field must be an integer greater than 0.")
		} else {
			typeID = n
		}
		page, perPage := parsePagination(c, verrs)
		if len(verrs) > 0 {
			return verrs.invalid(c)
		}
		rows, total, err := d.Market.PagedOrders(c.Request().Context(), typeID, regionID, side == "buy", perPage, (page-1)*perPage)
		if err != nil {
			return dbError(c, err)
		}
		_, pagination := paginate(total, page, perPage)
		out := make([]any, 0, len(rows))
		for _, r := range rows {
			out = append(out, map[string]any{
				"order_id":      r.OrderID,
				"type_id":       r.TypeID,
				"region_id":     r.RegionID,
				"is_buy_order":  r.IsBuyOrder,
				"price":         r.Price,
				"volume_remain": r.VolumeRemain,
				"volume_total":  r.VolumeTotal,
				"location_id":   nullableInt64(r.LocationID),
				"issued":        nullableTime(r.Issued),
			})
		}
		return Success(c, http.StatusOK, out, map[string]any{
			"type_id": typeID, "region_id": regionID, "side": side,
			"freshness":  freshnessFor(c, d, regionID),
			"pagination": pagination,
		})
	}
}

// GET /market/freshness — mirrors MarketFreshnessController@index: the
// freshness block for every trade region.
func handleFreshness(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		ctx := c.Request().Context()
		now := time.Now()
		out := make([]any, 0, len(market.Regions))
		for regionID, regionName := range market.Regions {
			orders, costs, global, err := d.Market.FreshnessTimestamps(ctx, regionID)
			if err != nil {
				return dbError(c, err)
			}
			m := market.BuildFreshness(now, orders, costs, global)
			out = append(out, map[string]any{
				"region_id":    regionID,
				"region_name":  regionName,
				"orders_as_of": m.PricesAsOf,
				"costs_as_of":  m.CostsAsOf,
				"global_as_of": isoTime(global),
				"stale":        m.Stale,
			})
		}
		return Success(c, http.StatusOK, out, nil)
	}
}

func freshnessFor(c echo.Context, d Deps, regionID int) map[string]any {
	orders, costs, global, err := d.Market.FreshnessTimestamps(c.Request().Context(), regionID)
	if err != nil {
		return map[string]any{
			"prices_as_of": nil, "costs_as_of": nil,
			"source": market.FreshnessSourceNone, "stale": true,
		}
	}
	m := market.BuildFreshness(time.Now(), orders, costs, global)
	return map[string]any{
		"prices_as_of": m.PricesAsOf, "costs_as_of": m.CostsAsOf,
		"source": m.Source, "stale": m.Stale,
	}
}

func toFallbackRow(r store.FallbackPrice) market.FallbackRow {
	var sellMin, avg *float64
	if r.SellMin.Valid {
		v := r.SellMin.Float64
		sellMin = &v
	}
	if r.Average.Valid {
		v := r.Average.Float64
		avg = &v
	}
	return market.FallbackRow{TypeID: r.TypeID, RegionID: r.RegionID, SellMin: sellMin, Average: avg}
}

func isoTime(t *time.Time) *string {
	if t == nil {
		return nil
	}
	s := t.UTC().Format(time.RFC3339)
	return &s
}
