package store

import (
	"context"
	"database/sql"
)

// StockStore covers the industry stock manager watch list.
type StockStore interface {
	// WatchedItems returns the user's tracked items ordered by type name.
	WatchedItems(ctx context.Context, userID int64) ([]WatchedItem, error)
	// WatchedItem returns one tracked item owned by the user, else nil.
	WatchedItem(ctx context.Context, id, userID int64) (*WatchedItem, error)
	UpsertWatchedItem(ctx context.Context, in WatchedItemUpsert) (*WatchedItem, error)
	UpdateWatchedItemQuantity(ctx context.Context, id, userID, desired int64) (*WatchedItem, error)
	DeleteWatchedItem(ctx context.Context, id, userID int64) (bool, error)
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

// WatchedItemUpsert mirrors IndustryStockService::saveItem: insert or update
// the wanted quantity for a (user, type) pair.
type WatchedItemUpsert struct {
	UserID          int64
	TypeID          int
	TypeName        string
	DesiredQuantity int64
}

func (s *SQL) UpsertWatchedItem(ctx context.Context, in WatchedItemUpsert) (*WatchedItem, error) {
	_, err := s.db.ExecContext(ctx, `
		INSERT INTO industry_stock_items (user_id, type_id, type_name, desired_quantity, created_at, updated_at)
		VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
		ON CONFLICT(user_id, type_id) DO UPDATE SET type_name = excluded.type_name, desired_quantity = excluded.desired_quantity, updated_at = CURRENT_TIMESTAMP`,
		in.UserID, in.TypeID, in.TypeName, in.DesiredQuantity)
	if err != nil {
		return nil, err
	}
	var out WatchedItem
	err = s.db.GetContext(ctx, &out, `SELECT id, type_id, type_name, desired_quantity
		FROM industry_stock_items WHERE user_id = ? AND type_id = ?`, in.UserID, in.TypeID)
	return &out, err
}

func (s *SQL) WatchedItem(ctx context.Context, id, userID int64) (*WatchedItem, error) {
	var out WatchedItem
	err := s.db.GetContext(ctx, &out, `SELECT id, type_id, type_name, desired_quantity
		FROM industry_stock_items WHERE id = ? AND user_id = ?`, id, userID)
	if err == sql.ErrNoRows {
		return nil, nil
	}
	return &out, err
}

func (s *SQL) UpdateWatchedItemQuantity(ctx context.Context, id, userID int64, desired int64) (*WatchedItem, error) {
	if _, err := s.db.ExecContext(ctx, `UPDATE industry_stock_items SET desired_quantity = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ?`, desired, id, userID); err != nil {
		return nil, err
	}
	return s.WatchedItem(ctx, id, userID)
}

func (s *SQL) DeleteWatchedItem(ctx context.Context, id, userID int64) (bool, error) {
	res, err := s.db.ExecContext(ctx, `DELETE FROM industry_stock_items WHERE id = ? AND user_id = ?`, id, userID)
	if err != nil {
		return false, err
	}
	n, err := res.RowsAffected()
	return n > 0, err
}
