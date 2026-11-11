package store

import (
	"context"

	"github.com/jmoiron/sqlx"

	"github.com/sudo-adduser-jordan/eveindustry/internal/market"
)

type TradingStore interface {
	StationCandidates(ctx context.Context, regionID int, brokerRate, taxRate, minMarginPct float64) ([]market.StationCandidate, error)
	BestPrices(ctx context.Context, regionID int, buy bool) (map[int]float64, error)
	ArbitrageTypes(ctx context.Context, typeIDs []int) (map[int]market.TypeVolume, error)
}

func (s *SQL) StationCandidates(ctx context.Context, regionID int, brokerRate, taxRate, minMarginPct float64) ([]market.StationCandidate, error) {
	var rows []struct {
		TypeID         int      `db:"type_id"`
		TypeName       *string  `db:"type_name"`
		BestBuy        *float64 `db:"best_buy"`
		BestSell       *float64 `db:"best_sell"`
		BuyOrderCount  int      `db:"buy_order_count"`
		SellOrderCount int      `db:"sell_order_count"`
		GrossMarginPct *float64 `db:"gross_margin_pct"`
		NetProfit      *float64 `db:"net_profit_per_unit"`
		NetMarginPct   *float64 `db:"net_margin_pct"`
		RoiPct         *float64 `db:"roi_pct"`
		BreakEven      *float64 `db:"break_even"`
	}
	err := s.db.SelectContext(ctx, &rows, `
		SELECT * FROM (
			SELECT
				o.type_id,
				t.name AS type_name,
				MAX(CASE WHEN o.is_buy_order = 1 THEN o.price END) AS best_buy,
				MIN(CASE WHEN o.is_buy_order = 0 THEN o.price END) AS best_sell,
				SUM(CASE WHEN o.is_buy_order = 1 THEN 1 ELSE 0 END) AS buy_order_count,
				SUM(CASE WHEN o.is_buy_order = 0 THEN 1 ELSE 0 END) AS sell_order_count,
				ROUND(((MIN(CASE WHEN o.is_buy_order = 0 THEN o.price END)
					- MAX(CASE WHEN o.is_buy_order = 1 THEN o.price END))
					/ MIN(CASE WHEN o.is_buy_order = 0 THEN o.price END)) * 100, 2) AS gross_margin_pct,
				ROUND(MIN(CASE WHEN o.is_buy_order = 0 THEN o.price END)
					- MAX(CASE WHEN o.is_buy_order = 1 THEN o.price END)
					- (MAX(CASE WHEN o.is_buy_order = 1 THEN o.price END) * ?)
					- (MIN(CASE WHEN o.is_buy_order = 0 THEN o.price END) * ?)
					- (MIN(CASE WHEN o.is_buy_order = 0 THEN o.price END) * ?), 2) AS net_profit_per_unit,
				ROUND(((MIN(CASE WHEN o.is_buy_order = 0 THEN o.price END)
					- MAX(CASE WHEN o.is_buy_order = 1 THEN o.price END)
					- (MAX(CASE WHEN o.is_buy_order = 1 THEN o.price END) * ?)
					- (MIN(CASE WHEN o.is_buy_order = 0 THEN o.price END) * ?)
					- (MIN(CASE WHEN o.is_buy_order = 0 THEN o.price END) * ?))
					/ MIN(CASE WHEN o.is_buy_order = 0 THEN o.price END)) * 100, 2) AS net_margin_pct,
				ROUND(((MIN(CASE WHEN o.is_buy_order = 0 THEN o.price END)
					- MAX(CASE WHEN o.is_buy_order = 1 THEN o.price END)
					- (MAX(CASE WHEN o.is_buy_order = 1 THEN o.price END) * ?)
					- (MIN(CASE WHEN o.is_buy_order = 0 THEN o.price END) * ?)
					- (MIN(CASE WHEN o.is_buy_order = 0 THEN o.price END) * ?))
					/ (MAX(CASE WHEN o.is_buy_order = 1 THEN o.price END)
						+ MAX(CASE WHEN o.is_buy_order = 1 THEN o.price END) * ?)) * 100, 2) AS roi_pct,
				ROUND((MAX(CASE WHEN o.is_buy_order = 1 THEN o.price END)
					+ MAX(CASE WHEN o.is_buy_order = 1 THEN o.price END) * ?)
					/ (1.0 - ?), 2) AS break_even
			FROM market_orders o
			LEFT JOIN eve_types t ON t.type_id = o.type_id
			WHERE o.region_id = ?
			GROUP BY o.type_id, t.name
		) AS candidates
		WHERE best_buy IS NOT NULL
		  AND best_sell IS NOT NULL
		  AND best_sell > best_buy
		  AND net_margin_pct >= ?
		ORDER BY net_profit_per_unit DESC`,
		brokerRate, brokerRate, taxRate,
		brokerRate, brokerRate, taxRate,
		brokerRate, brokerRate, taxRate, brokerRate,
		brokerRate, brokerRate+taxRate,
		regionID, minMarginPct)
	if err != nil {
		return nil, err
	}
	out := make([]market.StationCandidate, 0, len(rows))
	for _, r := range rows {
		out = append(out, market.StationCandidate{
			TypeID: r.TypeID, TypeName: r.TypeName,
			BestBuy: deref(r.BestBuy), BestSell: deref(r.BestSell),
			BuyOrderCount: r.BuyOrderCount, SellOrderCount: r.SellOrderCount,
		})
	}
	return out, nil
}

func deref(v *float64) float64 {
	if v == nil {
		return 0
	}
	return *v
}

func (s *SQL) BestPrices(ctx context.Context, regionID int, buy bool) (map[int]float64, error) {
	agg := "MIN"
	if buy {
		agg = "MAX"
	}
	var rows []struct {
		TypeID    int     `db:"type_id"`
		BestPrice float64 `db:"best_price"`
	}
	err := s.db.SelectContext(ctx, &rows,
		`SELECT type_id, `+agg+`(price) AS best_price FROM market_orders
		 WHERE region_id = ? AND is_buy_order = ? GROUP BY type_id`,
		regionID, buy)
	if err != nil {
		return nil, err
	}
	out := make(map[int]float64, len(rows))
	for _, r := range rows {
		out[r.TypeID] = r.BestPrice
	}
	return out, nil
}

func (s *SQL) ArbitrageTypes(ctx context.Context, typeIDs []int) (map[int]market.TypeVolume, error) {
	out := map[int]market.TypeVolume{}
	if len(typeIDs) == 0 {
		return out, nil
	}
	q, args, err := sqlx.In(`SELECT type_id, name, volume, packaged_volume FROM eve_types WHERE type_id IN (?)`, typeIDs)
	if err != nil {
		return nil, err
	}
	var rows []struct {
		TypeID         int      `db:"type_id"`
		Name           string   `db:"name"`
		Volume         *float64 `db:"volume"`
		PackagedVolume *float64 `db:"packaged_volume"`
	}
	if err := s.db.SelectContext(ctx, &rows, s.db.Rebind(q), args...); err != nil {
		return nil, err
	}
	for _, r := range rows {
		vol := 0.0
		if r.PackagedVolume != nil {
			vol = *r.PackagedVolume
		} else if r.Volume != nil {
			vol = *r.Volume
		}
		out[r.TypeID] = market.TypeVolume{Name: r.Name, VolumeM3: vol}
	}
	return out, nil
}
