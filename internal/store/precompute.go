package store

import (
	"context"
	"time"

	"github.com/jmoiron/sqlx"
)

// PrecomputedCost mirrors one manufacturing_costs row for import.
type PrecomputedCost struct {
	ProductID      int
	BlueprintID    int
	RegionID       int
	MEL            int
	ProductName    string
	BlueprintName  string
	Portion        int
	InputCost      float64
	OutputValue    float64
	BaseProfit     float64
	ProfitMargin   float64
	SellScore      int
	ScoreAvgVolume float64
	ScoreAvgOrders float64
	ScoreActive    int
	ScoreTotal     int
	PricesAsOf     *time.Time
}

// PrecomputeStore covers the manufacturing cost precompute writer.
type PrecomputeStore interface {
	UpsertManufacturingCosts(ctx context.Context, rows []PrecomputedCost) error
	RecentHistoryByTypes(ctx context.Context, typeIDs []int, regionID, days int) (map[int][]MarketHistoryRow, error)
}

// UpsertManufacturingCosts ports the precompute writer (chunked upserts).
func (s *SQL) UpsertManufacturingCosts(ctx context.Context, rows []PrecomputedCost) error {
	for _, chunk := range chunkPrecomputed(rows, 500) {
		q := `INSERT INTO manufacturing_costs (product_type_id, blueprint_type_id, region_id, me_level,
			product_name, blueprint_name, portion_size, input_cost, output_value, base_profit, profit_margin,
			sell_score, sell_score_avg_daily_volume, sell_score_avg_order_count, sell_score_active_days,
			sell_score_total_days, prices_as_of, created_at, updated_at) VALUES `
		var args []any
		for i, r := range chunk {
			if i > 0 {
				q += ","
			}
			q += `(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)`
			args = append(args, r.ProductID, r.BlueprintID, r.RegionID, r.MEL, r.ProductName,
				r.BlueprintName, r.Portion, r.InputCost, r.OutputValue, r.BaseProfit, r.ProfitMargin,
				r.SellScore, r.ScoreAvgVolume, r.ScoreAvgOrders, r.ScoreActive, r.ScoreTotal, r.PricesAsOf)
		}
		q += ` ON CONFLICT(product_type_id, region_id, me_level) DO UPDATE SET product_name = excluded.product_name, blueprint_name = excluded.blueprint_name,
			portion_size = excluded.portion_size, input_cost = excluded.input_cost, output_value = excluded.output_value,
			base_profit = excluded.base_profit, profit_margin = excluded.profit_margin, sell_score = excluded.sell_score,
			sell_score_avg_daily_volume = excluded.sell_score_avg_daily_volume,
			sell_score_avg_order_count = excluded.sell_score_avg_order_count,
			sell_score_active_days = excluded.sell_score_active_days,
			sell_score_total_days = excluded.sell_score_total_days,
			prices_as_of = excluded.prices_as_of, updated_at = CURRENT_TIMESTAMP`
		if _, err := s.db.ExecContext(ctx, q, args...); err != nil {
			return err
		}
	}
	return nil
}

func chunkPrecomputed(in []PrecomputedCost, n int) [][]PrecomputedCost {
	var out [][]PrecomputedCost
	for len(in) > 0 {
		if len(in) < n {
			n = len(in)
		}
		out = append(out, in[:n])
		in = in[n:]
	}
	return out
}

// HistoryPoint is one market_history row for sell scores.
type HistoryPoint struct {
	TypeID     int       `db:"type_id"`
	Date       time.Time `db:"date"`
	Volume     uint64    `db:"volume"`
	OrderCount uint64    `db:"order_count"`
}

// RecentHistoryByTypes ports recentHistoryByType: latest `days` rows per
// type, newest first.
func (s *SQL) RecentHistoryByTypes(ctx context.Context, typeIDs []int, regionID, days int) (map[int][]MarketHistoryRow, error) {
	out := map[int][]MarketHistoryRow{}
	if len(typeIDs) == 0 {
		return out, nil
	}
	q, args, err := sqlx.In(`SELECT type_id, date, average, highest, lowest, volume, order_count
		FROM market_history WHERE region_id = ? AND type_id IN (?)
		ORDER BY type_id, date DESC`, regionID, typeIDs)
	if err != nil {
		return nil, err
	}
	type row struct {
		TypeID int `db:"type_id"`
		MarketHistoryRow
	}
	var rows []row
	if err := s.db.SelectContext(ctx, &rows, s.db.Rebind(q), args...); err != nil {
		return nil, err
	}
	seen := map[int]int{}
	for _, r := range rows {
		if seen[r.TypeID] >= days {
			continue
		}
		seen[r.TypeID]++
		out[r.TypeID] = append(out[r.TypeID], r.MarketHistoryRow)
	}
	return out, nil
}
