package jobs

import (
	"context"
	"encoding/json"
	"time"

	"github.com/sudo-adduser-jordan/eveindustry/internal/esi"
	"github.com/sudo-adduser-jordan/eveindustry/internal/market"
	"github.com/sudo-adduser-jordan/eveindustry/internal/store"
)

const (
	sweepFastCadenceMinutes = 15
	sweepFastCadenceMaxSecs = 600
	sweepRunningTimeout     = 30 * time.Minute
	pageCacheTTL            = 2 * time.Hour
	pageCacheBase           = "market.orders.page."
)

type sweepPageCache struct {
	ETag     string  `json:"etag"`
	OrderIDs []int64 `json:"order_ids"`
	Pages    int     `json:"pages"`
}

func RegisterSweepJobs(r *Runner, h HandlerDeps) {
	r.Register("fetch-region-orders", JobDef{
		Queue: QueueMarket, MaxTries: 2, Timeout: 300 * time.Second,
		Run: func(ctx context.Context, d Deps, raw json.RawMessage) error {
			var p struct {
				RegionID      int  `json:"region_id"`
				ExcludeRegion bool `json:"exclude_region"`
			}
			if err := json.Unmarshal(raw, &p); err != nil {
				return nil
			}
			return FetchRegionOrders(ctx, h, p.RegionID, p.ExcludeRegion)
		},
	})
	r.Register("fetch-region-order-pages", JobDef{
		Queue: QueueMarket, MaxTries: 10, Timeout: 60 * time.Second,
		Run: func(ctx context.Context, d Deps, raw json.RawMessage) error {
			var p struct {
				RegionID int    `json:"region_id"`
				Page     int    `json:"page"`
				SweepID  string `json:"sweep_id"`
			}
			if err := json.Unmarshal(raw, &p); err != nil {
				return nil
			}
			return FetchRegionOrderPages(ctx, h, p.RegionID, p.Page, p.SweepID)
		},
	})
	r.Register("complete-market-order-sweep", JobDef{
		Queue: QueueMarket, MaxTries: 2, Timeout: 300 * time.Second,
		Run: func(ctx context.Context, d Deps, raw json.RawMessage) error {
			var p struct {
				SweepID  string `json:"sweep_id"`
				Complete bool   `json:"complete"`
			}
			if err := json.Unmarshal(raw, &p); err != nil {
				return nil
			}
			return CompleteMarketOrderSweep(ctx, h, p.SweepID, p.Complete)
		},
	})
}

func SweepSchedule(h HandlerDeps, forgeID int) []ScheduleEntry {
	return []ScheduleEntry{
		{
			Name: "market:fetch-region-orders", Every: 15 * time.Minute, Overlap: 15 * time.Minute,
			When: func(ctx context.Context) bool { return SweepIsDue(ctx, h, forgeID) },
			Run: func(ctx context.Context, r *Runner, q Queue) error {
				raw, _ := json.Marshal(map[string]any{"region_id": forgeID})
				return r.Dispatch(ctx, q, "fetch-region-orders", raw)
			},
		},
		{
			Name: "market:fetch-region-orders-rest", Every: time.Hour, Overlap: time.Hour,
			Run: func(ctx context.Context, r *Runner, q Queue) error {
				raw, _ := json.Marshal(map[string]any{"region_id": forgeID, "exclude_region": true})
				return r.Dispatch(ctx, q, "fetch-region-orders", raw)
			},
		},
	}
}

var hubRegions = []int{10000002, 10000043, 10000032, 10000042, 10000030}

func SweepIsDue(ctx context.Context, h HandlerDeps, regionID int) bool {
	return time.Now().Minute()%SweepCadenceMinutes(ctx, h, regionID) < sweepFastCadenceMinutes
}

func SweepCadenceMinutes(ctx context.Context, h HandlerDeps, regionID int) int {
	last, err := h.DB.LastCompletedSweep(ctx, regionID)
	if err != nil || last == nil || !last.DurationSeconds.Valid {
		return 30
	}
	if last.DurationSeconds.Int64 < sweepFastCadenceMaxSecs {
		return sweepFastCadenceMinutes
	}
	return 30
}

func FetchRegionOrders(ctx context.Context, h HandlerDeps, regionID int, excludeRegion bool) error {
	if excludeRegion {
		for _, id := range hubRegions {
			if id == regionID {
				continue
			}
			if err := fetchOneRegion(ctx, h, id); err != nil {
				h.logger().Error("sweep region failed", "region", id, "err", err)
			}
		}
		return nil
	}
	return fetchOneRegion(ctx, h, regionID)
}

func fetchOneRegion(ctx context.Context, h HandlerDeps, regionID int) error {
	startedAt := time.Now()
	cached := cachedSweepPage(ctx, h, regionID, 1)
	first := h.ESIMarket.FetchRegionOrdersPage(ctx, regionID, 1, cached.ETag)
	if first.Status == "rate_limited" {
		h.logger().Info("sweep rate-limited", "region", regionID, "retry_after", first.RetryAfter)
		return nil
	}
	if !first.Successful() || first.Status == "missing" {
		return nil
	}
	sweep, err := h.DB.StartSweep(ctx, regionID, startedAt, sweepRunningTimeout)
	if err != nil {
		return err
	}
	if sweep == nil {
		return nil
	}
	totalPages := first.Pages
	if first.Status == "not_modified" {
		if err := h.DB.MarkSweepOrdersSeen(ctx, sweep.SweepID, regionID, cached.OrderIDs); err != nil {
			return err
		}
		if cached.Pages > 0 {
			totalPages = cached.Pages
		}
	} else {
		if err := storeSweepPage(ctx, h, sweep.SweepID, regionID, 1, first); err != nil {
			return err
		}
	}
	if err := h.DB.SetPagesTotal(ctx, sweep.SweepID, totalPages); err != nil {
		return err
	}
	if totalPages <= 1 {
		return CompleteMarketOrderSweep(ctx, h, sweep.SweepID, true)
	}
	for page := 2; page <= totalPages; page++ {
		raw, _ := json.Marshal(map[string]any{"region_id": regionID, "page": page, "sweep_id": sweep.SweepID})
		_ = raw
		if err := dispatchInline(ctx, h, regionID, page, sweep.SweepID); err != nil {
			return CompleteMarketOrderSweep(ctx, h, sweep.SweepID, false)
		}
	}
	return CompleteMarketOrderSweep(ctx, h, sweep.SweepID, true)
}

func dispatchInline(ctx context.Context, h HandlerDeps, regionID, page int, sweepID string) error {
	return FetchRegionOrderPages(ctx, h, regionID, page, sweepID)
}

func FetchRegionOrderPages(ctx context.Context, h HandlerDeps, regionID, page int, sweepID string) error {
	cached := cachedSweepPage(ctx, h, regionID, page)
	result := h.ESIMarket.FetchRegionOrdersPage(ctx, regionID, page, cached.ETag)
	switch result.Status {
	case "rate_limited":
		return nil
	case "not_modified":
		return h.DB.MarkSweepOrdersSeen(ctx, sweepID, regionID, cached.OrderIDs)
	case "missing":
		return h.DB.MarkSweepPageMissing(ctx, sweepID)
	case "ok":
		return storeSweepPage(ctx, h, sweepID, regionID, page, result)
	default:
		return errSweepPageFailed(regionID, page, result.HTTPStatus)
	}
}

type sweepPageError struct {
	region, page, status int
}

func (e *sweepPageError) Error() string {
	return "sweep page failed"
}

func errSweepPageFailed(region, page, status int) error {
	return &sweepPageError{region: region, page: page, status: status}
}

func storeSweepPage(ctx context.Context, h HandlerDeps, sweepID string, regionID, page int, result esi.RegionOrdersPage) error {
	var orders []store.SweepOrder
	var ids []int64
	for _, o := range result.Orders {
		id, _ := intOf(o["order_id"])
		if id == 0 {
			continue
		}
		issued := parseESITime(strOf(o["issued"]))
		tid, _ := intOf(o["type_id"])
		volRem, _ := intOf(o["volume_remain"])
		volTot, _ := intOf(o["volume_total"])
		var dur *int
		if d, ok := intOf(o["duration"]); ok && d != 0 {
			v := int(d)
			dur = &v
		}
		orders = append(orders, store.SweepOrder{
			OrderID: id, RegionID: regionID, TypeID: int(tid),
			IsBuy: boolOf(o["is_buy_order"]), Price: floatOf(o["price"]),
			VolumeRemain: volRem, VolumeTotal: volTot,
			MinVolume:  intOr(o["min_volume"], 1),
			LocationID: intPtrOf(o["location_id"]), Duration: dur,
			Range: strPtrOf(o["range"]), Issued: issued,
		})
		ids = append(ids, id)
	}
	if err := h.DB.StoreSweepOrders(ctx, sweepID, regionID, orders); err != nil {
		return err
	}
	if result.ETag != "" {
		setSweepPageCache(ctx, h, regionID, page, result.ETag, ids, result.Pages)
	}
	return nil
}

func CompleteMarketOrderSweep(ctx context.Context, h HandlerDeps, sweepID string, complete bool) error {
	if !complete {
		h.DB.FailSweep(ctx, sweepID, "Page receipt count is incomplete; retaining the active aggregate generation")
		return nil
	}
	sweep, err := h.DB.SweepByID(ctx, sweepID)
	if err != nil || sweep == nil {
		return err
	}
	if sweep.Status == store.SweepCompleted {
		return nil
	}
	if sweep.Status == store.SweepRunning && sweep.PagesTotal > 0 && sweep.PagesFetched < sweep.PagesTotal {
		h.DB.FailSweep(ctx, sweepID, "Page receipt count is incomplete; retaining the active aggregate generation")
		return nil
	}
	claimed, err := h.DB.ClaimCompletion(ctx, sweepID)
	if err != nil || claimed == nil {
		return err
	}
	if claimed.Status != store.SweepCompleting && claimed.Status != store.SweepCompleted {
		return nil
	}
	deleted, err := h.DB.DeleteUnseenOrders(ctx, claimed.RegionID, claimed.StartedAt)
	if err != nil {
		return err
	}
	types, err := RebuildAggregates(ctx, h, claimed.RegionID, sweepID, claimed.StartedAt)
	if err != nil {
		return err
	}
	finished, err := h.DB.FinishSweep(ctx, sweepID, time.Now(), deleted, int64(types))
	if err != nil {
		return err
	}
	if finished != nil {
		_, _ = h.DB.PublishActiveSnapshot(ctx, finished.RegionID, sweepID, finished.StartedAt)
	}
	return nil
}

func RebuildAggregates(ctx context.Context, h HandlerDeps, regionID int, sweepID string, snapshotAt time.Time) (int, error) {
	cutoff := time.Now().AddDate(0, 0, -90)
	sides, err := h.DB.AggregateSides(ctx, regionID, cutoff)
	if err != nil {
		return 0, err
	}
	type agg struct {
		bestBid, bestAsk      *float64
		sellVol, buyVol       int64
		sellOrders, buyOrders int
	}
	rows := map[int]*agg{}
	var sellTypes []int
	seen := map[int]bool{}
	for _, s := range sides {
		a := rows[s.TypeID]
		if a == nil {
			a = &agg{}
			rows[s.TypeID] = a
		}
		if s.IsBuy {
			v := s.MaxPrice
			a.bestBid = &v
			a.buyVol += s.Volume
			a.buyOrders += s.Orders
		} else {
			v := s.MinPrice
			a.bestAsk = &v
			a.sellVol += s.Volume
			a.sellOrders += s.Orders
			if !seen[s.TypeID] {
				seen[s.TypeID] = true
				sellTypes = append(sellTypes, s.TypeID)
			}
		}
	}
	p5 := map[int]float64{}
	for _, chunk := range chunkInts(sellTypes, 500) {
		prices, err := h.DB.SellOrdersByTypes(ctx, regionID, chunk, cutoff)
		if err != nil {
			return 0, err
		}
		byType := map[int][]market.Order{}
		for _, p := range prices {
			byType[p.TypeID] = append(byType[p.TypeID], market.Order{Price: p.Price, VolumeRemain: p.Volume})
		}
		for tid, orders := range byType {
			sortOrders(orders)
			if v := market.PercentileAverage(orders, 5); v > 0 {
				p5[tid] = v
			}
		}
	}
	var out []store.SweepAggregate
	for tid, a := range rows {
		r := store.SweepAggregate{RegionID: regionID, TypeID: tid, SweepID: sweepID,
			SnapshotAt: snapshotAt, BestBid: a.bestBid, BestAsk: a.bestAsk,
			SellVolume: a.sellVol, BuyVolume: a.buyVol, SellOrders: a.sellOrders, BuyOrders: a.buyOrders}
		if v, ok := p5[tid]; ok {
			r.SellPriceP5 = &v
		}
		out = append(out, r)
	}
	if err := h.DB.UpsertAggregates(ctx, out); err != nil {
		return 0, err
	}
	return len(out), nil
}

func sortOrders(orders []market.Order) {
	for i := 1; i < len(orders); i++ {
		for j := i; j > 0 && orders[j].Price < orders[j-1].Price; j-- {
			orders[j], orders[j-1] = orders[j-1], orders[j]
		}
	}
}

func chunkInts(in []int, n int) [][]int {
	var out [][]int
	for len(in) > 0 {
		if len(in) < n {
			n = len(in)
		}
		out = append(out, in[:n])
		in = in[n:]
	}
	return out
}

func cachedSweepPage(ctx context.Context, h HandlerDeps, regionID, page int) sweepPageCache {
	var out sweepPageCache
	if h.Cache == nil {
		return out
	}
	_ = h.Cache.GetJSON(ctx, pageCacheKey(itoa(int64(regionID))+"."+itoa(int64(page))), &out)
	return out
}

func setSweepPageCache(ctx context.Context, h HandlerDeps, regionID, page int, etag string, ids []int64, pages int) {
	if h.Cache == nil {
		return
	}
	h.Cache.SetJSON(ctx, pageCacheKey(itoa(int64(regionID))+"."+itoa(int64(page))), sweepPageCache{ETag: etag, OrderIDs: ids, Pages: pages}, pageCacheTTL)
}

func pageCacheKey(k string) string { return pageCacheBase + k }

func intOf(v any) (int64, bool) {
	switch n := v.(type) {
	case int:
		return int64(n), true
	case int64:
		return n, true
	case float64:
		return int64(n), true
	}
	return 0, false
}

func floatOf(v any) float64 {
	switch n := v.(type) {
	case int:
		return float64(n)
	case int64:
		return float64(n)
	case float64:
		return n
	}
	return 0
}

func intOr(v any, def int64) int64 {
	if n, ok := intOf(v); ok && n != 0 {
		return n
	}
	return def
}

func boolOf(v any) bool {
	b, _ := v.(bool)
	return b
}

func strOf(v any) string {
	s, _ := v.(string)
	return s
}

func intPtrOf(v any) *int64 {
	if n, ok := intOf(v); ok && n != 0 {
		return &n
	}
	return nil
}

func strPtrOf(v any) *string {
	if s, ok := v.(string); ok && s != "" {
		return &s
	}
	return nil
}

func parseESITime(s string) *time.Time {
	for _, layout := range []string{time.RFC3339, "2006-01-02T15:04:05Z"} {
		if t, err := time.Parse(layout, s); err == nil {
			u := t.UTC()
			return &u
		}
	}
	return nil
}
