package http

import (
	"net/http"
	"strconv"

	"github.com/labstack/echo/v4"

	"github.com/sudo-adduser-jordan/eveindustry/internal/market"
)

// registerTrading mounts the scanner pair, mirroring
// routes/api_v1/market.php: station-trading and region-arbitrage.
func registerTrading(v1 *echo.Group, d Deps) {
	if d.Trading == nil || d.Market == nil {
		return
	}
	v1.GET("/market/station-trading", handleStationTrading(d))
	v1.GET("/market/region-arbitrage", handleRegionArbitrage(d))
}

// parseScanFees validates the shared scanner inputs from MarketRequest::scanRules().
func parseScanFees(c echo.Context, verrs validationErrors) (brokerFee, salesTax, minMargin float64, minDailyVolume int) {
	brokerFee, salesTax, minMargin = market.DefaultBrokerFee, market.DefaultSalesTax, market.DefaultMinMargin
	minDailyVolume = market.DefaultMinDailyVolume
	if raw := c.QueryParam("broker_fee"); raw != "" {
		v, err := strconv.ParseFloat(raw, 64)
		if err != nil || v < 0 || v > 10 {
			verrs.add("broker_fee", "The broker fee field must be between 0 and 10.")
		} else {
			brokerFee = v
		}
	}
	if raw := c.QueryParam("sales_tax"); raw != "" {
		v, err := strconv.ParseFloat(raw, 64)
		if err != nil || v < 0 || v > 15 {
			verrs.add("sales_tax", "The sales tax field must be between 0 and 15.")
		} else {
			salesTax = v
		}
	}
	if raw := c.QueryParam("min_margin"); raw != "" {
		v, err := strconv.ParseFloat(raw, 64)
		if err != nil || v < 0 || v > 100 {
			verrs.add("min_margin", "The min margin field must be between 0 and 100.")
		} else {
			minMargin = v
		}
	}
	if raw := c.QueryParam("min_daily_volume"); raw != "" {
		v, err := strconv.Atoi(raw)
		if err != nil || v < 0 {
			verrs.add("min_daily_volume", "The min daily volume field must be at least 0.")
		} else {
			minDailyVolume = v
		}
	}
	return brokerFee, salesTax, minMargin, minDailyVolume
}

// historyVolumes loads 30-day average daily volumes per type. A query error
// maps to the PHP ESI-error path (nil history): the candidate is included
// with volume 0 rather than dropped.
func historyVolumes(c echo.Context, d Deps, typeIDs []int, regionID int) map[int]*int {
	volumes := make(map[int]*int, len(typeIDs))
	for _, id := range typeIDs {
		rows, err := d.Market.History(c.Request().Context(), id, regionID, 30)
		if err != nil {
			volumes[id] = nil
			continue
		}
		vols := make([]uint64, 0, len(rows))
		for _, r := range rows {
			vols = append(vols, r.Volume)
		}
		v := market.DailyVolume(vols)
		volumes[id] = &v
	}
	return volumes
}

// GET /market/station-trading — mirrors TradingController@stationTrading.
func handleStationTrading(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		verrs := validationErrors{}
		regionID := parseRegionID(c, verrs)
		brokerFee, salesTax, minMargin, minDailyVolume := parseScanFees(c, verrs)
		page, perPage := parsePagination(c, verrs)
		if len(verrs) > 0 {
			return verrs.invalid(c)
		}
		cands, err := d.Trading.StationCandidates(c.Request().Context(), regionID, brokerFee/100, salesTax/100, minMargin)
		if err != nil {
			return dbError(c, err)
		}
		ids := make([]int, 0, len(cands))
		for _, cand := range cands {
			ids = append(ids, cand.TypeID)
		}
		results := market.BuildStationOpportunities(cands, market.StationScanInput{
			BrokerFeePct: brokerFee, SalesTaxPct: salesTax,
			MinMarginPct: minMargin, MinDailyVolume: minDailyVolume,
		}, historyVolumes(c, d, ids, regionID))

		offset, pagination := paginate(len(results), page, perPage)
		end := offset + perPage
		if end > len(results) {
			end = len(results)
		}
		out := make([]any, 0, end-offset)
		for _, r := range results[offset:end] {
			out = append(out, r)
		}
		return Success(c, http.StatusOK, out, map[string]any{
			"region":     map[string]any{"region_id": regionID, "region_name": market.RegionName(regionID)},
			"freshness":  freshnessFor(c, d, regionID),
			"pagination": pagination,
		})
	}
}

// GET /market/region-arbitrage — mirrors TradingController@regionArbitrage.
func handleRegionArbitrage(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		verrs := validationErrors{}
		buyRegion := market.DefaultRegion
		if raw := c.QueryParam("buy_region_id"); raw != "" {
			id, err := strconv.Atoi(raw)
			if err != nil || !market.IsTradeRegion(id) {
				verrs.add("buy_region_id", "The selected buy region id is invalid.")
			} else {
				buyRegion = id
			}
		}
		sellRegion := market.DefaultSellRegion
		if raw := c.QueryParam("sell_region_id"); raw != "" {
			id, err := strconv.Atoi(raw)
			if err != nil || !market.IsTradeRegion(id) {
				verrs.add("sell_region_id", "The selected sell region id is invalid.")
			} else {
				sellRegion = id
			}
		}
		brokerFee, salesTax, minMargin, minDailyVolume := parseScanFees(c, verrs)
		transport := 0.0
		if raw := c.QueryParam("transport_isk_m3"); raw != "" {
			v, err := strconv.ParseFloat(raw, 64)
			if err != nil || v < 0 {
				verrs.add("transport_isk_m3", "The transport isk m3 field must be at least 0.")
			} else {
				transport = v
			}
		}
		page, perPage := parsePagination(c, verrs)
		if len(verrs) == 0 && buyRegion == sellRegion {
			verrs.add("sell_region_id", "Buy and sell regions must be different.")
		}
		if len(verrs) > 0 {
			return verrs.invalid(c)
		}
		ctx := c.Request().Context()
		buyPrices, err := d.Trading.BestPrices(ctx, buyRegion, false)
		if err != nil {
			return dbError(c, err)
		}
		sellPrices, err := d.Trading.BestPrices(ctx, sellRegion, true)
		if err != nil {
			return dbError(c, err)
		}
		var results []market.ArbitrageOpportunity
		if len(buyPrices) > 0 && len(sellPrices) > 0 {
			ids := make([]int, 0, len(buyPrices))
			for id := range buyPrices {
				if _, ok := sellPrices[id]; ok {
					ids = append(ids, id)
				}
			}
			types, err := d.Trading.ArbitrageTypes(ctx, ids)
			if err != nil {
				return dbError(c, err)
			}
			results = market.BuildArbitrageOpportunities(buyPrices, sellPrices, types, market.ArbitrageScanInput{
				BrokerFeePct: brokerFee, SalesTaxPct: salesTax,
				TransportIskPerM3: transport, MinMarginPct: minMargin, MinDailyVolume: minDailyVolume,
			}, historyVolumes(c, d, ids, sellRegion))
		}
		if results == nil {
			results = []market.ArbitrageOpportunity{}
		}
		offset, pagination := paginate(len(results), page, perPage)
		end := offset + perPage
		if end > len(results) {
			end = len(results)
		}
		out := make([]any, 0, end-offset)
		for _, r := range results[offset:end] {
			out = append(out, r)
		}
		byRegion := map[string]any{
			strconv.Itoa(buyRegion):  freshnessFor(c, d, buyRegion),
			strconv.Itoa(sellRegion): freshnessFor(c, d, sellRegion),
		}
		return Success(c, http.StatusOK, out, map[string]any{
			"buy_region":          map[string]any{"region_id": buyRegion, "region_name": market.RegionName(buyRegion)},
			"sell_region":         map[string]any{"region_id": sellRegion, "region_name": market.RegionName(sellRegion)},
			"freshness":           freshnessFor(c, d, buyRegion),
			"freshness_by_region": byRegion,
			"pagination":          pagination,
		})
	}
}
