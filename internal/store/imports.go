package store

import (
	"context"
	"database/sql"
	"time"
)

// MarketHistoryImportRow mirrors one market_history row for import.
type MarketHistoryImportRow struct {
	RegionID   int
	TypeID     int
	Date       string
	Average    float64
	Highest    float64
	Lowest     float64
	Volume     int64
	OrderCount int64
}

// ImportStore covers the market/contract import writers.
type ImportStore interface {
	UpsertMarketHistory(ctx context.Context, rows []MarketHistoryRow) (int64, error)
	UpsertGlobalPrices(ctx context.Context, regionID int, prices []GlobalPrice, fetchedAt time.Time) (int64, error)
	CreateContractGeneration(ctx context.Context) (int64, error)
	MarkContractGeneration(ctx context.Context, id int64, status string, contracts, items int64) error
	PublishContractGeneration(ctx context.Context, id int64) (bool, error)
	InsertSnapshotContracts(ctx context.Context, generationID int64, rows []SnapshotContractRow) error
	InsertSnapshotItems(ctx context.Context, generationID int64, rows []SnapshotItemRow) error
	OrphanSnapshotItems(ctx context.Context, generationID int64) (int64, error)
}

// GlobalPrice is one ESI /markets/prices entry for storage.
type GlobalPrice struct {
	TypeID   int
	Average  *float64
	Adjusted *float64
}

// UpsertMarketHistory ports the everef daily import batch.
func (s *SQL) UpsertMarketHistory(ctx context.Context, rows []MarketHistoryImportRow) (int64, error) {
	var total int64
	for _, chunk := range chunkMarketHistory(rows, 1000) {
		q := `INSERT INTO market_history (region_id, type_id, date, average, highest, lowest, volume, order_count, created_at, updated_at) VALUES `
		var args []any
		for i, r := range chunk {
			if i > 0 {
				q += ","
			}
			q += `(?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)`
			args = append(args, r.RegionID, r.TypeID, r.Date, r.Average, r.Highest, r.Lowest, r.Volume, r.OrderCount)
		}
		q += ` ON CONFLICT(region_id, type_id, date) DO UPDATE SET average = excluded.average, highest = excluded.highest, lowest = excluded.lowest,
			volume = excluded.volume, order_count = excluded.order_count, updated_at = CURRENT_TIMESTAMP`
		res, err := s.db.ExecContext(ctx, q, args...)
		if err != nil {
			return total, err
		}
		n, _ := res.RowsAffected()
		total += n
	}
	return total, nil
}

func chunkMarketHistory(in []MarketHistoryImportRow, n int) [][]MarketHistoryImportRow {
	var out [][]MarketHistoryImportRow
	for len(in) > 0 {
		if len(in) < n {
			n = len(in)
		}
		out = append(out, in[:n])
		in = in[n:]
	}
	return out
}

// UpsertGlobalPrices ports fetchAndStoreGlobalPrices (Forge rows).
func (s *SQL) UpsertGlobalPrices(ctx context.Context, regionID int, prices []GlobalPrice, fetchedAt time.Time) (int64, error) {
	var total int64
	for _, chunk := range chunkGlobalPrices(prices, 500) {
		q := `INSERT INTO market_prices (type_id, region_id, average_price, adjusted_price, fetched_at, created_at, updated_at) VALUES `
		var args []any
		for i, r := range chunk {
			if i > 0 {
				q += ","
			}
			q += `(?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)`
			args = append(args, r.TypeID, regionID, r.Average, r.Adjusted, fetchedAt)
		}
		q += ` ON CONFLICT(type_id, region_id) DO UPDATE SET average_price = excluded.average_price, adjusted_price = excluded.adjusted_price,
			fetched_at = excluded.fetched_at, updated_at = CURRENT_TIMESTAMP`
		res, err := s.db.ExecContext(ctx, q, args...)
		if err != nil {
			return total, err
		}
		n, _ := res.RowsAffected()
		total += n
	}
	return total, nil
}

func chunkGlobalPrices(in []GlobalPrice, n int) [][]GlobalPrice {
	var out [][]GlobalPrice
	for len(in) > 0 {
		if len(in) < n {
			n = len(in)
		}
		out = append(out, in[:n])
		in = in[n:]
	}
	return out
}

// SnapshotContractRow mirrors the everef contracts.csv shape for staging.
type SnapshotContractRow struct {
	ContractID      int64
	RegionID        int
	Type            string
	Title           *string
	ForCorporation  bool
	IssuerCorpID    *int64
	IssuerID        *int64
	DateIssued      *time.Time
	DateExpired     *time.Time
	DaysToComplete  int
	StartLocationID *int64
	EndLocationID   *int64
	StationID       *int64
	SystemID        *int
	ConstellationID *int
	Price           float64
	Buyout          *float64
	Reward          *float64
	Collateral      *float64
	Volume          float64
	HTTPModified    *time.Time
}

// SnapshotItemRow mirrors the everef contract_items.csv shape.
type SnapshotItemRow struct {
	ContractID         int64
	TypeID             int
	Quantity           int
	IsIncluded         bool
	IsBlueprintCopy    *bool
	MaterialEfficiency *int
	TimeEfficiency     *int
	Runs               *int
	RecordID           *int64
	ItemID             *int64
	HTTPModified       *time.Time
}

// CreateContractGeneration stages a building generation.
func (s *SQL) CreateContractGeneration(ctx context.Context) (int64, error) {
	res, err := s.db.ExecContext(ctx, `INSERT INTO public_contract_generations
		(status, contract_count, item_count, created_at) VALUES ('building', 0, 0, CURRENT_TIMESTAMP)`)
	if err != nil {
		return 0, err
	}
	return res.LastInsertId()
}

// MarkContractGeneration sets a generation status (complete/failed/superseded).
func (s *SQL) MarkContractGeneration(ctx context.Context, id int64, status string, contracts, items int64) error {
	if status == "complete" {
		_, err := s.db.ExecContext(ctx, `UPDATE public_contract_generations SET status = ?,
			contract_count = ?, item_count = ? WHERE id = ?`, status, contracts, items, id)
		return err
	}
	_, err := s.db.ExecContext(ctx, `UPDATE public_contract_generations SET status = ? WHERE id = ?`, status, id)
	return err
}

// PublishContractGeneration atomically publishes when still newest.
func (s *SQL) PublishContractGeneration(ctx context.Context, id int64) (bool, error) {
	tx, err := s.db.BeginTxx(ctx, nil)
	if err != nil {
		return false, err
	}
	defer tx.Rollback()
	if _, err := tx.ExecContext(ctx, `UPDATE public_contract_generations SET status = 'complete',
		contract_count = contract_count, item_count = item_count WHERE id = ?`, id); err != nil {
		return false, err
	}
	var active sql.NullInt64
	err = tx.GetContext(ctx, &active, `SELECT active_generation_id FROM public_contract_snapshot_state WHERE id = 1`)
	if err != nil && err != sql.ErrNoRows {
		return false, err
	}
	if active.Valid && active.Int64 >= id {
		if _, err := tx.ExecContext(ctx, `UPDATE public_contract_generations SET status = 'superseded' WHERE id = ?`, id); err != nil {
			return false, err
		}
		return false, tx.Commit()
	}
	now := time.Now()
	if _, err := tx.ExecContext(ctx, `UPDATE public_contract_generations SET status = 'published',
		published_at = ? WHERE id = ?`, now, id); err != nil {
		return false, err
	}
	if _, err := tx.ExecContext(ctx, `INSERT INTO public_contract_snapshot_state (id, active_generation_id, updated_at)
		VALUES (1, ?, ?) ON CONFLICT(id) DO UPDATE SET active_generation_id = excluded.active_generation_id, updated_at = CURRENT_TIMESTAMP`, id, now); err != nil {
		return false, err
	}
	return true, tx.Commit()
}

// InsertSnapshotContracts stages contracts.csv rows.
func (s *SQL) InsertSnapshotContracts(ctx context.Context, generationID int64, rows []SnapshotContractRow) error {
	for _, chunk := range chunkSnapshotContracts(rows, 500) {
		q := `INSERT INTO public_contract_snapshot_contracts (generation_id, contract_id, region_id, type, title,
			for_corporation, issuer_corporation_id, issuer_id, date_issued, date_expired, days_to_complete,
			start_location_id, end_location_id, station_id, system_id, constellation_id, price, buyout, reward,
			collateral, volume, http_last_modified, created_at, updated_at) VALUES `
		var args []any
		for i, r := range chunk {
			if i > 0 {
				q += ","
			}
			q += `(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)`
			args = append(args, generationID, r.ContractID, r.RegionID, r.Type, r.Title, r.ForCorporation,
				r.IssuerCorpID, r.IssuerID, r.DateIssued, r.DateExpired, r.DaysToComplete,
				r.StartLocationID, r.EndLocationID, r.StationID, r.SystemID, r.ConstellationID,
				r.Price, r.Buyout, r.Reward, r.Collateral, r.Volume, r.HTTPModified)
		}
		if _, err := s.db.ExecContext(ctx, q, args...); err != nil {
			return err
		}
	}
	return nil
}

func chunkSnapshotContracts(in []SnapshotContractRow, n int) [][]SnapshotContractRow {
	var out [][]SnapshotContractRow
	for len(in) > 0 {
		if len(in) < n {
			n = len(in)
		}
		out = append(out, in[:n])
		in = in[n:]
	}
	return out
}

// InsertSnapshotItems stages contract_items.csv rows.
func (s *SQL) InsertSnapshotItems(ctx context.Context, generationID int64, rows []SnapshotItemRow) error {
	for _, chunk := range chunkSnapshotItems(rows, 500) {
		q := `INSERT INTO public_contract_snapshot_items (generation_id, contract_id, type_id, quantity,
			is_included, is_blueprint_copy, material_efficiency, time_efficiency, runs, record_id, item_id,
			http_last_modified, created_at, updated_at) VALUES `
		var args []any
		for i, r := range chunk {
			if i > 0 {
				q += ","
			}
			q += `(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)`
			args = append(args, generationID, r.ContractID, r.TypeID, r.Quantity, r.IsIncluded,
				r.IsBlueprintCopy, r.MaterialEfficiency, r.TimeEfficiency, r.Runs, r.RecordID, r.ItemID, r.HTTPModified)
		}
		if _, err := s.db.ExecContext(ctx, q, args...); err != nil {
			return err
		}
	}
	return nil
}

func chunkSnapshotItems(in []SnapshotItemRow, n int) [][]SnapshotItemRow {
	var out [][]SnapshotItemRow
	for len(in) > 0 {
		if len(in) < n {
			n = len(in)
		}
		out = append(out, in[:n])
		in = in[n:]
	}
	return out
}

// OrphanSnapshotItems counts staged items without a contract.
func (s *SQL) OrphanSnapshotItems(ctx context.Context, generationID int64) (int64, error) {
	var n int64
	err := s.db.GetContext(ctx, &n, `SELECT COUNT(*) FROM public_contract_snapshot_items i
		LEFT JOIN public_contract_snapshot_contracts c
			ON c.generation_id = i.generation_id AND c.contract_id = i.contract_id
		WHERE i.generation_id = ? AND c.contract_id IS NULL`, generationID)
	return n, err
}
