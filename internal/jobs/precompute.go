package jobs

import (
	"context"
	"encoding/json"
	"sort"
	"time"

	"github.com/sudo-adduser-jordan/eveindustry/internal/calc"
	"github.com/sudo-adduser-jordan/eveindustry/internal/industry"
	"github.com/sudo-adduser-jordan/eveindustry/internal/market"
	"github.com/sudo-adduser-jordan/eveindustry/internal/store"
)

func RegisterPrecomputeJobs(r *Runner, h HandlerDeps) {
	r.Register("manufacturing-precompute-costs", JobDef{
		Queue: QueueUpdates, MaxTries: 3, Timeout: 600 * time.Second, Unique: 30 * time.Minute,
		Run: func(ctx context.Context, d Deps, raw json.RawMessage) error {
			var p struct {
				RegionID int `json:"region_id"`
			}
			if err := json.Unmarshal(raw, &p); err != nil || p.RegionID == 0 {
				return nil
			}
			return PrecomputeManufacturingCosts(ctx, h, p.RegionID)
		},
	})
}

func PrecomputeSchedule(h HandlerDeps) []ScheduleEntry {
	return []ScheduleEntry{
		{
			Name: "manufacturing:precompute-costs", Every: 4 * time.Hour, Overlap: 4 * time.Hour,
			Run: func(ctx context.Context, r *Runner, q Queue) error {
				for _, region := range hubRegions {
					raw, _ := json.Marshal(map[string]any{"region_id": region})
					if err := r.Dispatch(ctx, q, "manufacturing-precompute-costs", raw); err != nil {
						return err
					}
				}
				return nil
			},
		},
	}
}

func PrecomputeManufacturingCosts(ctx context.Context, h HandlerDeps, regionID int) error {
	acts, err := h.Industry.AllManufacturingActivities(ctx)
	if err != nil {
		return err
	}
	if len(acts) == 0 {
		return nil
	}
	byBP := map[int][]industry.MfgActivity{}
	for _, a := range acts {
		byBP[a.BlueprintTypeID] = append(byBP[a.BlueprintTypeID], a)
	}
	var bps []int
	for bp := range byBP {
		bps = append(bps, bp)
	}
	invented, err := h.Industry.InventedBlueprintIDs(ctx)
	if err != nil {
		return err
	}
	var items []calc.BrowserBlueprint
	productIDs := map[int]bool{}
	nameIDs := map[int]bool{}
	for _, bp := range bps {
		if invented[bp] {
			continue
		}
		row := byBP[bp][0]
		mats := decodeItemMaps(row.Materials)
		prods := decodeItemMaps(row.Products)
		if len(prods) == 0 {
			continue
		}
		items = append(items, calc.BrowserBlueprint{
			BlueprintTypeID: bp, BlueprintName: "",
			Materials: mats, Products: prods, Time: row.Time,
		})
		for _, m := range mats {
			productIDs[m.TypeID] = true
			nameIDs[m.TypeID] = true
		}
		for _, p := range prods {
			productIDs[p.TypeID] = true
			nameIDs[p.TypeID] = true
		}
		nameIDs[bp] = true
	}
	sort.Slice(items, func(i, j int) bool { return items[i].BlueprintTypeID < items[j].BlueprintTypeID })
	if len(items) == 0 {
		return nil
	}
	names, err := h.Industry.TypeNames(ctx, keysOfInts(nameIDs))
	if err != nil {
		return err
	}
	for i := range items {
		items[i].BlueprintName = names[items[i].BlueprintTypeID]
		for j := range items[i].Materials {
			items[i].Materials[j].Name = names[items[i].Materials[j].TypeID]
		}
		for j := range items[i].Products {
			items[i].Products[j].Name = names[items[i].Products[j].TypeID]
		}
	}
	typeIDs := keysOfInts(productIDs)
	resolved, err := resolvePrices(ctx, h, typeIDs, regionID)
	if err != nil {
		return err
	}
	prices := map[int]float64{}
	var pricesAsOf *time.Time
	_ = pricesAsOf
	for id, r := range resolved {
		prices[id] = r.Price
		if r.Source != market.SourceNone {
			_ = r
		}
	}
	hist, err := h.DB.RecentHistoryByTypes(ctx, typeIDs, regionID, 30)
	if err != nil {
		return err
	}
	var rows []store.PrecomputedCost
	for _, item := range items {
		if len(item.Products) == 0 {
			continue
		}
		prod := item.Products[0]
		histRows := hist[prod.TypeID]
		var vols []uint64
		var ords []uint64
		for _, hr := range histRows {
			vols = append(vols, hr.Volume)
			ords = append(ords, hr.OrderCount)
		}
		score := calc.CalculateSellScore(calc.SellScoreInput{Volumes: vols, OrderCounts: ords}, prod.Quantity)
		outputValue := prices[prod.TypeID] * float64(prod.Quantity)
		for me := 0; me <= 10; me++ {
			base := make([]calc.BaseMaterial, 0, len(item.Materials))
			for _, m := range item.Materials {
				base = append(base, calc.BaseMaterial{TypeID: m.TypeID, Quantity: m.Quantity})
			}
			mats := calc.CalculateMaterials(base, me, 1, 0)
			var inputCost float64
			for _, m := range mats {
				inputCost += prices[m.TypeID] * float64(m.AdjustedQuantity)
			}
			_, profit, margin := calc.Summary(outputValue, inputCost, 0)
			rows = append(rows, store.PrecomputedCost{
				ProductID: prod.TypeID, BlueprintID: item.BlueprintTypeID, RegionID: regionID,
				MEL: me, ProductName: prod.Name, BlueprintName: item.BlueprintName,
				Portion: prod.Quantity, InputCost: inputCost, OutputValue: outputValue,
				BaseProfit: profit, ProfitMargin: margin,
				SellScore: score.Score, ScoreAvgVolume: score.AvgDailyVolume,
				ScoreAvgOrders: score.AvgOrderCount, ScoreActive: score.ActiveDays,
				ScoreTotal: score.TotalDays,
			})
			if len(rows) >= 500 {
				if err := h.DB.UpsertManufacturingCosts(ctx, rows); err != nil {
					return err
				}
				rows = nil
			}
		}
	}
	if len(rows) > 0 {
		if err := h.DB.UpsertManufacturingCosts(ctx, rows); err != nil {
			return err
		}
	}
	h.logger().Info("precomputed manufacturing costs", "region", regionID, "blueprints", len(items))
	return nil
}

func decodeItemList(raw *string) []calc.BaseMaterial {
	items := industry.DecodeJSONList(raw)
	out := make([]calc.BaseMaterial, 0, len(items))
	for _, m := range items {
		id, _ := intOf(m["type_id"])
		qty, _ := intOf(m["quantity"])
		if id == 0 {
			continue
		}
		out = append(out, calc.BaseMaterial{TypeID: int(id), Quantity: int(qty)})
	}
	return out
}

func keysOfInts(m map[int]bool) []int {
	out := make([]int, 0, len(m))
	for k := range m {
		out = append(out, k)
	}
	return out
}

func resolvePrices(ctx context.Context, h HandlerDeps, typeIDs []int, regionID int) (map[int]market.ResolvedPrice, error) {
	dedup := map[int]bool{}
	var ordered []int
	for _, id := range typeIDs {
		if !dedup[id] {
			dedup[id] = true
			ordered = append(ordered, id)
		}
	}
	orderPrices := map[int]float64{}
	if agg, has, err := h.DB.AggregateSellPrices(ctx, ordered, regionID); err != nil {
		return nil, err
	} else if has {
		orderPrices = agg
	} else {
		raw, err := h.DB.RawSellOrders(ctx, ordered, regionID, time.Now().AddDate(0, 0, -market.MaxOrderAgeDays))
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
	fbRows, err := h.DB.FallbackPrices(ctx, ordered, fallbackRegions)
	if err != nil {
		return nil, err
	}
	byRegion := map[int][]market.FallbackRow{}
	for _, r := range fbRows {
		var sellMin, avg *float64
		if r.SellMin.Valid {
			v := r.SellMin.Float64
			sellMin = &v
		}
		if r.Average.Valid {
			v := r.Average.Float64
			avg = &v
		}
		byRegion[r.RegionID] = append(byRegion[r.RegionID], market.FallbackRow{TypeID: r.TypeID, RegionID: r.RegionID, SellMin: sellMin, Average: avg})
	}
	var fallbackRows []market.FallbackRow
	for _, region := range fallbackRegions {
		fallbackRows = append(fallbackRows, byRegion[region]...)
	}
	out := map[int]market.ResolvedPrice{}
	for _, r := range market.ResolveSellPrices(ordered, orderPrices, fallbackRows) {
		out[r.TypeID] = r
	}
	return out, nil
}

func decodeItemMaps(items []map[string]any) []calc.BaseMaterial {
	out := make([]calc.BaseMaterial, 0, len(items))
	for _, m := range items {
		id, _ := intOf(m["type_id"])
		qty, _ := intOf(m["quantity"])
		if id == 0 {
			continue
		}
		out = append(out, calc.BaseMaterial{TypeID: int(id), Quantity: int(qty)})
	}
	return out
}
