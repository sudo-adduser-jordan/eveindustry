package jobs

import (
	"context"
	"encoding/json"
	"math"
	"sort"
	"time"

	"github.com/sudo-adduser-jordan/eveindustry/internal/calc"
	"github.com/sudo-adduser-jordan/eveindustry/internal/market"
	"github.com/sudo-adduser-jordan/eveindustry/internal/store"
)

type demoMoonOre struct {
	TypeID int
	Name   string
	Qty    float64
}

type demoMoon struct {
	Moon   string
	Planet string
	Ores   []demoMoonOre
}

func seedDemoMoonScans(ctx context.Context, h HandlerDeps, corpID int64, corpName string) {
	if h.DB == nil || corpID == 0 {
		return
	}
	existing, err := h.DB.MoonScanSystems(ctx, corpID)
	if err == nil && len(existing) > 0 {
		return
	}
	systems := []struct {
		name   string
		region int
		moons  []demoMoon
	}{
		{"Tama", 10000002, []demoMoon{
			{"Tama III - Moon 1", "Tama III", []demoMoonOre{
				{45490, "Zeolites", 0.35}, {45491, "Sylvite", 0.25}, {45492, "Bitumens", 0.2},
				{45493, "Coesite", 0.12}, {45494, "Cobaltite", 0.08},
			}},
			{"Tama IV - Moon 4", "Tama IV", []demoMoonOre{
				{45506, "Cinnabar", 0.08}, {45494, "Cobaltite", 0.3}, {45493, "Coesite", 0.3}, {45492, "Bitumens", 0.32},
			}},
			{"Tama V - Moon 2", "Tama V", []demoMoonOre{
				{45491, "Sylvite", 0.5}, {45490, "Zeolites", 0.5},
			}},
		}},
		{"Amamake", 10000030, []demoMoon{
			{"Amamake VII - Moon 3", "Amamake VII", []demoMoonOre{
				{45494, "Cobaltite", 0.4}, {45493, "Coesite", 0.35}, {45492, "Bitumens", 0.25},
			}},
			{"Amamake IX - Moon 1", "Amamake IX", []demoMoonOre{
				{45490, "Zeolites", 0.6}, {45491, "Sylvite", 0.4},
			}},
		}},
	}
	ids := map[int]bool{}
	for _, s := range systems {
		for _, m := range s.moons {
			for _, o := range m.Ores {
				ids[o.TypeID] = true
			}
		}
	}
	var idList []int
	for id := range ids {
		idList = append(idList, id)
	}
	sort.Ints(idList)
	volumes := map[int]float64{}
	if rows, err := h.DB.TypesByIDs(ctx, idList); err == nil {
		for _, r := range rows {
			if r.Volume.Valid && r.Volume.Float64 > 0 {
				volumes[r.TypeID] = r.Volume.Float64
			}
		}
	}
	for _, s := range systems {
		sys, err := h.DB.UpsertMoonScanSystem(ctx, s.name, &corpID, int64(s.region), "raw")
		if err != nil || sys == nil {
			h.logger().Warn("demo moon system seed failed", "system", s.name, "err", err)
			continue
		}
		prices := demoMoonPrices(ctx, h.DB, idList, s.region)
		for _, m := range s.moons {
			ores := make([]any, 0, len(m.Ores))
			var total float64
			maxTier := 0
			for _, o := range m.Ores {
				tier := calc.OreTiers[o.TypeID]
				if tier > maxTier {
					maxTier = tier
				}
				vol := volumes[o.TypeID]
				if vol <= 0 {
					vol = calc.DefaultOreVolume
				}
				units := int(math.Floor(o.Qty * calc.TotalMoonM3 / vol))
				price := prices[o.TypeID]
				value := float64(units) * price
				total += value
				ores = append(ores, map[string]any{
					"name": o.Name, "type_id": o.TypeID, "quantity": o.Qty,
					"tier": tier, "ore_units": units,
					"price_per_unit": price, "value_contribution": value,
				})
			}
			raw, _ := json.Marshal(ores)
			planet := m.Planet
			id, err := h.DB.UpsertMoonScanResult(ctx, sys.ID, m.Moon, &planet, calc.MoonGrade(maxTier), string(raw), int64(total))
			if err != nil {
				h.logger().Warn("demo moon result seed failed", "moon", m.Moon, "err", err)
				continue
			}
			if s.name == "Tama" && m.Moon == "Tama III - Moon 1" {
				name := corpName
				if _, err := h.DB.SetMoonOccupation(ctx, id, true, &corpID, &name); err != nil {
					h.logger().Warn("demo moon occupation seed failed", "moon", m.Moon, "err", err)
				}
			}
		}
	}
}

func demoMoonPrices(ctx context.Context, db *store.SQL, ids []int, region int) map[int]float64 {
	out := map[int]float64{}
	for _, id := range ids {
		out[id] = 0
	}
	if len(ids) == 0 {
		return out
	}
	orderPrices := map[int]float64{}
	if agg, ok, err := db.AggregateSellPrices(ctx, ids, region); err == nil && ok {
		orderPrices = agg
	}
	var missing []int
	for _, id := range ids {
		if orderPrices[id] <= 0 {
			missing = append(missing, id)
		}
	}
	if len(missing) > 0 {
		if raw, err := db.RawSellOrders(ctx, missing, region, time.Now().AddDate(0, 0, -market.MaxOrderAgeDays)); err == nil {
			byType := map[int][]market.Order{}
			for _, o := range raw {
				byType[o.TypeID] = append(byType[o.TypeID], market.Order{Price: o.Price, VolumeRemain: int64(o.VolumeRemain)})
			}
			for _, id := range missing {
				orders := byType[id]
				for i := 1; i < len(orders); i++ {
					for j := i; j > 0 && orders[j].Price < orders[j-1].Price; j-- {
						orders[j], orders[j-1] = orders[j-1], orders[j]
					}
				}
				if v := market.PercentileAverage(orders, market.DefaultPercentile); v > 0 {
					orderPrices[id] = v
				}
			}
		}
	}
	regions := []int{region}
	if region != market.DefaultRegion {
		regions = append(regions, market.DefaultRegion)
	}
	var fallback []market.FallbackRow
	if rows, err := db.FallbackPrices(ctx, ids, regions); err == nil {
		byRegion := map[int][]store.FallbackPrice{}
		for _, r := range rows {
			byRegion[r.RegionID] = append(byRegion[r.RegionID], r)
		}
		for _, regionID := range regions {
			for _, r := range byRegion[regionID] {
				var sell, avg *float64
				if r.SellMin.Valid {
					v := r.SellMin.Float64
					sell = &v
				}
				if r.Average.Valid {
					v := r.Average.Float64
					avg = &v
				}
				fallback = append(fallback, market.FallbackRow{
					TypeID: r.TypeID, RegionID: r.RegionID, SellMin: sell, Average: avg,
				})
			}
		}
	}
	for _, r := range market.ResolveSellPrices(ids, orderPrices, fallback) {
		out[r.TypeID] = r.Price
	}
	return out
}
