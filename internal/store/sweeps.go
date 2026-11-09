package store

import (
	"context"
	"database/sql"
	"sync/atomic"
	"time"

	"github.com/jmoiron/sqlx"
)

// Sweep statuses mirroring MarketOrderSweep.
const (
	SweepRunning    = "running"
	SweepCompleting = "completing"
	SweepCompleted  = "completed"
	SweepFailed     = "failed"
	SweepAbandoned  = "abandoned"
)

// MarketOrderSweep mirrors one market_order_sweeps row.
type MarketOrderSweep struct {
	ID              int64          `db:"id"`
	SweepID         string         `db:"sweep_id"`
	RegionID        int            `db:"region_id"`
	Generation      int64          `db:"generation"`
	Status          string         `db:"status"`
	StartedAt       time.Time      `db:"started_at"`
	FinishedAt      sql.NullTime   `db:"finished_at"`
	DurationSeconds sql.NullInt64  `db:"duration_seconds"`
	PagesTotal      int            `db:"pages_total"`
	PagesFetched    int            `db:"pages_fetched"`
	PagesNotMod     int            `db:"pages_not_modified"`
	OrdersSeen      int64          `db:"orders_seen"`
	OrdersDeleted   sql.NullInt64  `db:"orders_deleted"`
	TypesAggregated sql.NullInt64  `db:"types_aggregated"`
	Error           sql.NullString `db:"error"`
}

// SweepStore covers the region order sweep state machine.
type SweepStore interface {
	StartSweep(ctx context.Context, regionID int, startedAt time.Time, runningTimeout time.Duration) (*MarketOrderSweep, error)
	SweepByID(ctx context.Context, sweepID string) (*MarketOrderSweep, error)
	SetPagesTotal(ctx context.Context, sweepID string, pages int) error
	IncrementSweep(ctx context.Context, sweepID string, fetched, notModified int, seen int) error
	StoreSweepOrders(ctx context.Context, sweepID string, regionID int, orders []SweepOrder) error
	MarkSweepOrdersSeen(ctx context.Context, sweepID string, regionID int, orderIDs []int64) error
	MarkSweepPageMissing(ctx context.Context, sweepID string) error
	FailSweep(ctx context.Context, sweepID, reason string) error
	ClaimCompletion(ctx context.Context, sweepID string) (*MarketOrderSweep, error)
	DeleteUnseenOrders(ctx context.Context, regionID int, startedAt time.Time) (int64, error)
	FinishSweep(ctx context.Context, sweepID string, finishedAt time.Time, deleted, types int64) (*MarketOrderSweep, error)
	PublishActiveSnapshot(ctx context.Context, regionID int, sweepID string, snapshotAt time.Time) (bool, error)
	LastCompletedSweep(ctx context.Context, regionID int) (*MarketOrderSweep, error)
	LatestSweep(ctx context.Context, regionID int) (*MarketOrderSweep, error)
	UpsertAggregates(ctx context.Context, rows []SweepAggregate) error
	AggregateSides(ctx context.Context, regionID int, cutoff time.Time) ([]AggregateSide, error)
	SellOrdersByTypes(ctx context.Context, regionID int, typeIDs []int, cutoff time.Time) ([]SellOrderPrice, error)
	CurrentGeneration(ctx context.Context, regionID int) (latest, active int64, activeSweep string, err error)
}

// SweepOrder is one order row staged by a sweep.
type SweepOrder struct {
	OrderID      int64
	RegionID     int
	TypeID       int
	IsBuy        bool
	Price        float64
	VolumeRemain int64
	VolumeTotal  int64
	MinVolume    int64
	LocationID   *int64
	Duration     *int
	Range        *string
	Issued       *time.Time
}

// AggregateSide is one grouped side row for rebuild.
type AggregateSide struct {
	TypeID   int     `db:"type_id"`
	IsBuy    bool    `db:"is_buy_order"`
	MinPrice float64 `db:"min_price"`
	MaxPrice float64 `db:"max_price"`
	Volume   int64   `db:"volume"`
	Orders   int     `db:"orders"`
}

// SellOrderPrice is one sell order price/volume pair.
type SellOrderPrice struct {
	TypeID int     `db:"type_id"`
	Price  float64 `db:"price"`
	Volume int64   `db:"volume_remain"`
}

// AggregateRow is one market_aggregates upsert row.
type SweepAggregate struct {
	RegionID    int
	TypeID      int
	SweepID     string
	SnapshotAt  time.Time
	BestBid     *float64
	BestAsk     *float64
	SellPriceP5 *float64
	SellVolume  int64
	BuyVolume   int64
	SellOrders  int
	BuyOrders   int
}

const sweepCols = `id, sweep_id, region_id, generation, status, started_at, finished_at,
	duration_seconds, pages_total, pages_fetched, pages_not_modified, orders_seen,
	orders_deleted, types_aggregated, error`

// StartSweep ports start(): abandon stale runners, skip when one runs, else
// create a new generation and bump the region state.
func (s *SQL) StartSweep(ctx context.Context, regionID int, startedAt time.Time, runningTimeout time.Duration) (*MarketOrderSweep, error) {
	tx, err := s.db.BeginTxx(ctx, nil)
	if err != nil {
		return nil, err
	}
	defer tx.Rollback()
	if _, err := tx.ExecContext(ctx, `INSERT INTO market_region_states (region_id) VALUES (?)
		ON DUPLICATE KEY UPDATE region_id = region_id`, regionID); err != nil {
		return nil, err
	}
	if _, err := tx.ExecContext(ctx, `UPDATE market_order_sweeps SET status = ?, finished_at = ?, error = ?
		WHERE region_id = ? AND status IN ('running','completing') AND started_at < ?`,
		SweepAbandoned, time.Now(), "Did not finish in time", regionID, time.Now().Add(-runningTimeout)); err != nil {
		return nil, err
	}
	var runningID int64
	err = tx.GetContext(ctx, &runningID, `SELECT id FROM market_order_sweeps
		WHERE region_id = ? AND status IN ('running','completing') LIMIT 1 FOR UPDATE`, regionID)
	if err != nil && err != sql.ErrNoRows {
		return nil, err
	}
	if err == nil {
		if err := tx.Commit(); err != nil {
			return nil, err
		}
		return nil, nil
	}
	var gen int64
	if err := tx.GetContext(ctx, &gen, `SELECT latest_generation FROM market_region_states WHERE region_id = ? FOR UPDATE`, regionID); err != nil {
		return nil, err
	}
	gen++
	sweepID := newSweepID()
	if _, err := tx.ExecContext(ctx, `INSERT INTO market_order_sweeps
		(sweep_id, region_id, generation, status, started_at, created_at, updated_at)
		VALUES (?, ?, ?, 'running', ?, NOW(), NOW())`, sweepID, regionID, gen, startedAt); err != nil {
		return nil, err
	}
	if _, err := tx.ExecContext(ctx, `UPDATE market_region_states SET latest_generation = ?,
		latest_sweep_id = ?, latest_started_at = ? WHERE region_id = ?`, gen, sweepID, startedAt, regionID); err != nil {
		return nil, err
	}
	if err := tx.Commit(); err != nil {
		return nil, err
	}
	return s.SweepByID(ctx, sweepID)
}

func (s *SQL) SweepByID(ctx context.Context, sweepID string) (*MarketOrderSweep, error) {
	var out MarketOrderSweep
	err := s.db.GetContext(ctx, &out, `SELECT `+sweepCols+` FROM market_order_sweeps WHERE sweep_id = ?`, sweepID)
	if err == sql.ErrNoRows {
		return nil, nil
	}
	return &out, err
}

func (s *SQL) SetPagesTotal(ctx context.Context, sweepID string, pages int) error {
	_, err := s.db.ExecContext(ctx, `UPDATE market_order_sweeps SET pages_total = ?, updated_at = NOW()
		WHERE sweep_id = ? AND status IN ('running','completing')`, pages, sweepID)
	return err
}

func (s *SQL) IncrementSweep(ctx context.Context, sweepID string, fetched, notModified int, seen int) error {
	_, err := s.db.ExecContext(ctx, `UPDATE market_order_sweeps
		SET pages_fetched = pages_fetched + ?, pages_not_modified = pages_not_modified + ?,
			orders_seen = orders_seen + ?, updated_at = NOW()
		WHERE sweep_id = ? AND status IN ('running','completing')`, fetched, notModified, seen, sweepID)
	return err
}

// StoreSweepOrders upserts a page of orders and counts it on the sweep.
func (s *SQL) StoreSweepOrders(ctx context.Context, sweepID string, regionID int, orders []SweepOrder) error {
	tx, err := s.db.BeginTxx(ctx, nil)
	if err != nil {
		return err
	}
	defer tx.Rollback()
	if !s.sweepLockedIDTx(ctx, tx, regionID, sweepID) {
		return tx.Commit()
	}
	for _, chunk := range chunkSweepOrders(orders, 500) {
		q := `INSERT INTO market_orders (order_id, region_id, type_id, is_buy_order, price,
			volume_remain, volume_total, min_volume, location_id, duration, range, issued, batch_id, created_at, updated_at) VALUES `
		var args []any
		for i, o := range chunk {
			if i > 0 {
				q += ","
			}
			q += `(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())`
			args = append(args, o.OrderID, o.RegionID, o.TypeID, o.IsBuy, o.Price,
				o.VolumeRemain, o.VolumeTotal, o.MinVolume, nullInt64(o.LocationID),
				nullInt(o.Duration), nullString(o.Range), nullTime(o.Issued), sweepID)
		}
		q += ` ON DUPLICATE KEY UPDATE region_id = VALUES(region_id), type_id = VALUES(type_id),
			is_buy_order = VALUES(is_buy_order), price = VALUES(price), volume_remain = VALUES(volume_remain),
			volume_total = VALUES(volume_total), min_volume = VALUES(min_volume), location_id = VALUES(location_id),
			duration = VALUES(duration), range = VALUES(range), issued = VALUES(issued),
			batch_id = VALUES(batch_id), updated_at = NOW()`
		if _, err := tx.ExecContext(ctx, q, args...); err != nil {
			return err
		}
	}
	if _, err := tx.ExecContext(ctx, `UPDATE market_order_sweeps SET pages_fetched = pages_fetched + 1,
		orders_seen = orders_seen + ?, updated_at = NOW() WHERE sweep_id = ?`, len(orders), sweepID); err != nil {
		return err
	}
	return tx.Commit()
}

func (s *SQL) sweepLockedIDTx(ctx context.Context, tx *sqlx.Tx, regionID int, sweepID string) bool {
	var id int64
	err := tx.QueryRowContext(ctx, `SELECT id FROM market_order_sweeps
		WHERE region_id = ? AND sweep_id = ? AND status IN ('running','completing') FOR UPDATE`, regionID, sweepID).Scan(&id)
	return err == nil
}

func chunkSweepOrders(in []SweepOrder, n int) [][]SweepOrder {
	var out [][]SweepOrder
	for len(in) > 0 {
		if len(in) < n {
			n = len(in)
		}
		out = append(out, in[:n])
		in = in[n:]
	}
	return out
}

func nullInt64(v *int64) any {
	if v == nil {
		return nil
	}
	return *v
}

func nullInt(v *int) any {
	if v == nil {
		return nil
	}
	return *v
}

func nullString(v *string) any {
	if v == nil {
		return nil
	}
	return *v
}

func nullTime(v *time.Time) any {
	if v == nil {
		return nil
	}
	return *v
}

// MarkSweepOrdersSeen stamps known order ids with the sweep without rewriting.
func (s *SQL) MarkSweepOrdersSeen(ctx context.Context, sweepID string, regionID int, orderIDs []int64) error {
	tx, err := s.db.BeginTxx(ctx, nil)
	if err != nil {
		return err
	}
	defer tx.Rollback()
	if !s.sweepLockedIDTx(ctx, tx, regionID, sweepID) {
		return tx.Commit()
	}
	for _, chunk := range chunkInt64(orderIDs, 500) {
		q, args, err := sqlxIn(`UPDATE market_orders SET batch_id = ?, updated_at = NOW()
			WHERE region_id = ? AND order_id IN (?)`, append([]any{sweepID, regionID}, int64s(chunk)...))
		if err != nil {
			return err
		}
		if _, err := tx.ExecContext(ctx, q, args...); err != nil {
			return err
		}
	}
	if _, err := tx.ExecContext(ctx, `UPDATE market_order_sweeps SET pages_fetched = pages_fetched + 1,
		pages_not_modified = pages_not_modified + 1, orders_seen = orders_seen + ?, updated_at = NOW() WHERE sweep_id = ?`,
		len(orderIDs), sweepID); err != nil {
		return err
	}
	return tx.Commit()
}

func chunkInt64(in []int64, n int) [][]int64 {
	var out [][]int64
	for len(in) > 0 {
		if len(in) < n {
			n = len(in)
		}
		out = append(out, in[:n])
		in = in[n:]
	}
	return out
}

func int64s(in []int64) []any {
	out := make([]any, 0, len(in))
	for _, v := range in {
		out = append(out, v)
	}
	return out
}

// sqlxIn expands IN clauses and rebinds for the driver.
func sqlxIn(query string, args []any) (string, []any, error) {
	anyArgs := make([]any, 0, len(args))
	for _, a := range args {
		anyArgs = append(anyArgs, a)
	}
	q, expanded, err := sqlx.In(query, anyArgs...)
	if err != nil {
		return "", nil, err
	}
	return q, expanded, nil
}

// MarkSweepPageMissing counts a 404 page on the sweep.
func (s *SQL) MarkSweepPageMissing(ctx context.Context, sweepID string) error {
	return s.IncrementSweep(ctx, sweepID, 1, 0, 0)
}

// FailSweep records a failed sweep, keeping every order.
func (s *SQL) FailSweep(ctx context.Context, sweepID, reason string) error {
	if len(reason) > 490 {
		reason = reason[:490]
	}
	_, err := s.db.ExecContext(ctx, `UPDATE market_order_sweeps SET status = ?, finished_at = ?,
		duration_seconds = GREATEST(0, TIMESTAMPDIFF(SECOND, started_at, ?)), error = ?, updated_at = NOW()
		WHERE sweep_id = ? AND status IN ('running','completing')`,
		SweepFailed, time.Now(), time.Now(), reason, sweepID)
	return err
}

// ClaimCompletion moves a fully-fetched running sweep to completing.
func (s *SQL) ClaimCompletion(ctx context.Context, sweepID string) (*MarketOrderSweep, error) {
	_, err := s.db.ExecContext(ctx, `UPDATE market_order_sweeps SET status = ?, updated_at = NOW()
		WHERE sweep_id = ? AND status = 'running' AND pages_total > 0 AND pages_fetched >= pages_total`,
		SweepCompleting, sweepID)
	if err != nil {
		return nil, err
	}
	return s.SweepByID(ctx, sweepID)
}

// DeleteUnseenOrders removes region orders the sweep never stamped.
func (s *SQL) DeleteUnseenOrders(ctx context.Context, regionID int, startedAt time.Time) (int64, error) {
	var total int64
	for {
		res, err := s.db.ExecContext(ctx, `DELETE FROM market_orders
			WHERE region_id = ? AND updated_at < ? LIMIT 1000`, regionID, startedAt)
		if err != nil {
			return total, err
		}
		n, err := res.RowsAffected()
		if err != nil {
			return total, err
		}
		total += n
		if n < 1000 {
			break
		}
	}
	return total, nil
}

// FinishSweep marks completion with stats.
func (s *SQL) FinishSweep(ctx context.Context, sweepID string, finishedAt time.Time, deleted, types int64) (*MarketOrderSweep, error) {
	_, err := s.db.ExecContext(ctx, `UPDATE market_order_sweeps SET status = ?, finished_at = ?,
		duration_seconds = GREATEST(0, TIMESTAMPDIFF(SECOND, started_at, ?)),
		orders_deleted = ?, types_aggregated = ?, updated_at = NOW()
		WHERE sweep_id = ? AND status IN ('completing','completed')`,
		SweepCompleted, finishedAt, finishedAt, deleted, types, sweepID)
	if err != nil {
		return nil, err
	}
	return s.SweepByID(ctx, sweepID)
}

// PublishActiveSnapshot moves the active pointer when this sweep is newest.
func (s *SQL) PublishActiveSnapshot(ctx context.Context, regionID int, sweepID string, snapshotAt time.Time) (bool, error) {
	tx, err := s.db.BeginTxx(ctx, nil)
	if err != nil {
		return false, err
	}
	defer tx.Rollback()
	var latestGen, activeGen int64
	var latestSweep, activeSweep sql.NullString
	err = tx.QueryRowContext(ctx, `SELECT latest_generation, latest_sweep_id, active_generation, active_sweep_id
		FROM market_region_states WHERE region_id = ? FOR UPDATE`, regionID).Scan(&latestGen, &latestSweep, &activeGen, &activeSweep)
	if err != nil {
		return false, err
	}
	var gen int64
	var status string
	err = tx.QueryRowContext(ctx, `SELECT generation, status FROM market_order_sweeps
		WHERE region_id = ? AND sweep_id = ? FOR UPDATE`, regionID, sweepID).Scan(&gen, &status)
	if err != nil {
		return false, err
	}
	if !latestSweep.Valid || latestSweep.String != sweepID || latestGen != gen {
		return false, tx.Commit()
	}
	if gen < activeGen {
		return false, tx.Commit()
	}
	if gen == activeGen && activeSweep.Valid && activeSweep.String == sweepID {
		return true, tx.Commit()
	}
	if gen <= activeGen {
		return false, tx.Commit()
	}
	if _, err := tx.ExecContext(ctx, `UPDATE market_region_states SET active_generation = ?,
		active_sweep_id = ?, active_snapshot_at = ? WHERE region_id = ?`, gen, sweepID, snapshotAt, regionID); err != nil {
		return false, err
	}
	return true, tx.Commit()
}

// LastCompletedSweep returns the newest completed sweep.
func (s *SQL) LastCompletedSweep(ctx context.Context, regionID int) (*MarketOrderSweep, error) {
	var out MarketOrderSweep
	err := s.db.GetContext(ctx, &out, `SELECT `+sweepCols+` FROM market_order_sweeps
		WHERE region_id = ? AND status = 'completed' ORDER BY finished_at DESC, id DESC LIMIT 1`, regionID)
	if err == sql.ErrNoRows {
		return nil, nil
	}
	return &out, err
}

// LatestSweep returns the newest started sweep.
func (s *SQL) LatestSweep(ctx context.Context, regionID int) (*MarketOrderSweep, error) {
	var out MarketOrderSweep
	err := s.db.GetContext(ctx, &out, `SELECT `+sweepCols+` FROM market_order_sweeps
		WHERE region_id = ? ORDER BY started_at DESC, id DESC LIMIT 1`, regionID)
	if err == sql.ErrNoRows {
		return nil, nil
	}
	return &out, err
}

// CurrentGeneration reads the region state's generations.
func (s *SQL) CurrentGeneration(ctx context.Context, regionID int) (latest, active int64, activeSweep string, err error) {
	var as sql.NullString
	err = s.db.QueryRowContext(ctx, `SELECT latest_generation, active_generation, active_sweep_id
		FROM market_region_states WHERE region_id = ?`, regionID).Scan(&latest, &active, &as)
	if err == sql.ErrNoRows {
		return 0, 0, "", nil
	}
	if as.Valid {
		activeSweep = as.String
	}
	return latest, active, activeSweep, err
}

// UpsertAggregates writes rebuilt aggregate rows.
func (s *SQL) UpsertAggregates(ctx context.Context, rows []SweepAggregate) error {
	for _, chunk := range chunkAggregates(rows, 500) {
		q := `INSERT INTO market_aggregates (region_id, type_id, sweep_id, snapshot_at, best_bid, best_ask,
			sell_price_p5, sell_volume, buy_volume, sell_orders, buy_orders, created_at, updated_at) VALUES `
		var args []any
		for i, r := range chunk {
			if i > 0 {
				q += ","
			}
			q += `(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())`
			args = append(args, r.RegionID, r.TypeID, r.SweepID, r.SnapshotAt, r.BestBid, r.BestAsk,
				r.SellPriceP5, r.SellVolume, r.BuyVolume, r.SellOrders, r.BuyOrders)
		}
		q += ` ON DUPLICATE KEY UPDATE snapshot_at = VALUES(snapshot_at), best_bid = VALUES(best_bid),
			best_ask = VALUES(best_ask), sell_price_p5 = VALUES(sell_price_p5), sell_volume = VALUES(sell_volume),
			buy_volume = VALUES(buy_volume), sell_orders = VALUES(sell_orders), buy_orders = VALUES(buy_orders),
			updated_at = NOW()`
		if _, err := s.db.ExecContext(ctx, q, args...); err != nil {
			return err
		}
	}
	return nil
}

func chunkAggregates(in []SweepAggregate, n int) [][]SweepAggregate {
	var out [][]SweepAggregate
	for len(in) > 0 {
		if len(in) < n {
			n = len(in)
		}
		out = append(out, in[:n])
		in = in[n:]
	}
	return out
}

// AggregateSides ports the rebuild group query (fresh, non-empty orders).
func (s *SQL) AggregateSides(ctx context.Context, regionID int, cutoff time.Time) ([]AggregateSide, error) {
	var out []AggregateSide
	err := s.db.SelectContext(ctx, &out, `SELECT type_id, is_buy_order, MIN(price) AS min_price,
		MAX(price) AS max_price, SUM(volume_remain) AS volume, COUNT(*) AS orders
		FROM market_orders WHERE region_id = ? AND volume_remain > 0 AND issued >= ?
		GROUP BY type_id, is_buy_order`, regionID, cutoff)
	return out, err
}

// SellOrdersByTypes loads priced sell orders cheapest-first for the p5 calc.
func (s *SQL) SellOrdersByTypes(ctx context.Context, regionID int, typeIDs []int, cutoff time.Time) ([]SellOrderPrice, error) {
	if len(typeIDs) == 0 {
		return nil, nil
	}
	q, args, err := sqlxIn(`SELECT type_id, price, volume_remain FROM market_orders
		WHERE region_id = ? AND is_buy_order = 0 AND volume_remain > 0 AND issued >= ? AND type_id IN (?)
		ORDER BY type_id, price`, append([]any{regionID, cutoff}, intsToAny(typeIDs)...))
	if err != nil {
		return nil, err
	}
	var out []SellOrderPrice
	err = s.db.SelectContext(ctx, &out, s.db.Rebind(q), args...)
	return out, err
}

func intsToAny(in []int) []any {
	out := make([]any, 0, len(in))
	for _, v := range in {
		out = append(out, v)
	}
	return out
}

var sweepCounter int64

func newSweepID() string {
	// Uniqueness within a process is enough: the column is unique and the
	// insert runs inside the start transaction.
	n := time.Now().UnixNano()
	return "sw-" + itoa64sweep(n%1e12) + "-" + itoa64sweep(atomic.AddInt64(&sweepCounter, 1))
}

func itoa64sweep(n int64) string {
	if n == 0 {
		return "0"
	}
	var b [20]byte
	p := len(b)
	for n > 0 {
		p--
		b[p] = byte('0' + n%10)
		n /= 10
	}
	return string(b[p:])
}
