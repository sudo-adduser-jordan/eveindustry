package store

import (
	"context"
)

// StockStore covers the industry stock manager watch list.
type StockStore interface {
	// WatchedItems returns the user's tracked items ordered by type name.
	WatchedItems(ctx context.Context, userID int64) ([]WatchedItem, error)
}

// WatchedItem mirrors one industry_stock_items row.
type WatchedItem struct {
	ID              int64  `db:"id"`
	TypeID          int    `db:"type_id"`
	TypeName        string `db:"type_name"`
	DesiredQuantity int64  `db:"desired_quantity"`
}

func (s *SQL) WatchedItems(ctx context.Context, userID int64) ([]WatchedItem, error) {
	var out []WatchedItem
	err := s.db.SelectContext(ctx, &out, `
		SELECT id, type_id, type_name, desired_quantity
		FROM industry_stock_items WHERE user_id = ? ORDER BY type_name`, userID)
	if out == nil {
		out = []WatchedItem{}
	}
	return out, err
}
