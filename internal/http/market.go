package http

import (
	"net/http"
	"strconv"
	"time"

	"github.com/labstack/echo/v4"

	"github.com/sudo-adduser-jordan/eveindustry/internal/market"
	"github.com/sudo-adduser-jordan/eveindustry/internal/store"
)

func registerMarket(v1 *echo.Group, d Deps) {
	if d.Market == nil {
		return
	}
	v1.GET("/market/prices", handlePrices(d))
	v1.GET("/market/history/:typeId", handleHistory(d))
	v1.GET("/market/orders", handleOrders(d))
}

func handlePrices(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		verrs := validationErrors{}
		typeIDs := parseTypeIDs(c, verrs)
		regionID := parseRegionID(c, verrs)
		if len(verrs) > 0 {
			return verrs.invalid(c)
		}
		ctx := c.Request().Context()

		resolved, err := resolvePrices(ctx, d.Market, typeIDs, regionID)
		if err != nil {
			return dbError(c, err)
		}
		rows := make([]any, 0, len(typeIDs))
		seen := map[int]bool{}
		for _, typeID := range typeIDs {
			if seen[typeID] {
				continue
			}
			seen[typeID] = true
			entry, ok := resolved[typeID]
			price, source := 0.0, market.SourceNone
			if ok {
				price, source = entry.Price, entry.Source
			}
			rows = append(rows, map[string]any{
				"type_id": typeID, "price": price, "source": source,
			})
		}
		return Success(c, http.StatusOK, rows, map[string]any{
			"region_id": regionID,
			"freshness": freshnessFor(c, d, regionID),
		})
	}
}

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
