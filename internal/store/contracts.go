package store

import (
	"context"
	"database/sql"
	"math"
	"time"

	"github.com/jmoiron/sqlx"
)

// ContractsStore covers the contract browser and appraisal reads. All
// contract data comes from the active snapshot generation; no active
// generation means empty results, mirroring the PHP global scope
// (whereRaw('1 = 0')) and the appraisal's early return.
type ContractsStore interface {
	ActiveGeneration(ctx context.Context) (*uint64, error)
	TypesByName(ctx context.Context, names []string) (map[string]TypedName, error)
	TypesByID(ctx context.Context, typeIDs []int) (map[int]string, error)
	ContractPricing(ctx context.Context, generation uint64, typeID int, sellOnly, buyOnly bool, lowest bool) (unitPrice float64, contractsFound int, err error)
	ContractList(ctx context.Context, generation uint64, typeFilter, search string, limit, offset int) ([]SnapshotContract, int, error)
	ContractTypeStats(ctx context.Context, generation uint64) (map[string]int, error)
	ContractShow(ctx context.Context, generation uint64, contractID uint64) (*SnapshotContract, error)
	ContractItems(ctx context.Context, generation uint64, contractID uint64) ([]SnapshotItem, error)
	ContractsFreshnessTime(ctx context.Context, generation uint64) (*time.Time, error)
}

// TypedName is one eve_types row for name resolution.
type TypedName struct {
	TypeID int    `db:"type_id"`
	Name   string `db:"name"`
}

// SnapshotContract mirrors public_contract_snapshot_contracts columns read
// by the browser.
type SnapshotContract struct {
	ContractID          uint64         `db:"contract_id"`
	RegionID            int            `db:"region_id"`
	Type                string         `db:"type"`
	Title               sql.NullString `db:"title"`
	IssuerID            sql.NullInt64  `db:"issuer_id"`
	Price               float64        `db:"price"`
	Buyout              sql.NullFloat64 `db:"buyout"`
	Reward              sql.NullFloat64 `db:"reward"`
	Collateral          sql.NullFloat64 `db:"collateral"`
	Volume              float64        `db:"volume"`
	DateIssued          sql.NullTime   `db:"date_issued"`
	DateExpired         sql.NullTime   `db:"date_expired"`
	ItemsCount          int            `db:"items_count"`
}

// SnapshotItem mirrors public_contract_snapshot_items columns read by the
// detail endpoint.
type SnapshotItem struct {
	TypeID             int             `db:"type_id"`
	Quantity           int             `db:"quantity"`
	IsIncluded         bool            `db:"is_included"`
	IsBlueprintCopy    sql.NullBool    `db:"is_blueprint_copy"`
	MaterialEfficiency sql.NullInt64   `db:"material_efficiency"`
	TimeEfficiency     sql.NullInt64   `db:"time_efficiency"`
	Runs               sql.NullInt64   `db:"runs"`
}

func (s *SQL) ActiveGeneration(ctx context.Context) (*uint64, error) {
	var gen sql.NullInt64
	err := s.db.GetContext(ctx, &gen,
		`SELECT active_generation_id FROM public_contract_snapshot_state WHERE id = 1`)
	if err == sql.ErrNoRows || !gen.Valid {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	v := uint64(gen.Int64)
	return &v, nil
}

func (s *SQL) TypesByName(ctx context.Context, names []string) (map[string]TypedName, error) {
	out := map[string]TypedName{}
	if len(names) == 0 {
		return out, nil
	}
	q, args, err := sqlx.In(`SELECT type_id, name FROM eve_types WHERE name IN (?)`, names)
	if err != nil {
		return nil, err
	}
	var rows []TypedName
	if err := s.db.SelectContext(ctx, &rows, s.db.Rebind(q), args...); err != nil {
		return nil, err
	}
	for _, r := range rows {
		out[r.Name] = r
	}
	return out, nil
}

// TypesByID resolves SDE type names for item lines.
func (s *SQL) TypesByID(ctx context.Context, typeIDs []int) (map[int]string, error) {
	out := map[int]string{}
	if len(typeIDs) == 0 {
		return out, nil
	}
	q, args, err := sqlx.In(`SELECT type_id, name FROM eve_types WHERE type_id IN (?)`, typeIDs)
	if err != nil {
		return nil, err
	}
	var rows []TypedName
	if err := s.db.SelectContext(ctx, &rows, s.db.Rebind(q), args...); err != nil {
		return nil, err
	}
	for _, r := range rows {
		out[r.TypeID] = r.Name
	}
	return out, nil
}

// ContractPricing ports getContractPricing(): item_exchange contracts of the
// active generation where typeID is the only item type; AVG or MIN of the
// per-unit contract price (price / total quantity). The aggregate is
// whitelisted in Go, never interpolated from input.
func (s *SQL) ContractPricing(ctx context.Context, generation uint64, typeID int, sellOnly, buyOnly bool, lowest bool) (float64, int, error) {
	agg := "AVG"
	if lowest {
		agg = "MIN"
	}
	filter := ""
	args := []any{generation, generation, generation, typeID}
	if sellOnly {
		filter = "AND pci.is_included = 1"
	} else if buyOnly {
		filter = "AND pci.is_included = 0"
	}
	var row struct {
		Count     int      `db:"contract_count"`
		UnitPrice *float64 `db:"unit_price"`
	}
	// agg is internal only (AVG/MIN): safe to interpolate.
	err := s.db.GetContext(ctx, &row, `
		SELECT COUNT(*) AS contract_count, `+agg+`(pc.price / NULLIF(
			(SELECT SUM(pci2.quantity) FROM public_contract_snapshot_items pci2
			 WHERE pci2.generation_id = pc.generation_id AND pci2.contract_id = pc.contract_id), 0)
		) AS unit_price
		FROM public_contract_snapshot_contracts pc
		WHERE pc.generation_id = ? AND pc.contract_id IN (
			SELECT pci.contract_id FROM public_contract_snapshot_items pci
			JOIN public_contract_snapshot_contracts pc2
			  ON pc2.contract_id = pci.contract_id AND pc2.generation_id = pci.generation_id
			WHERE pci.generation_id = ? AND pc2.generation_id = ?
			  AND pc2.type = 'item_exchange' `+filter+`
			GROUP BY pci.contract_id
			HAVING COUNT(DISTINCT pci.type_id) = 1 AND MIN(pci.type_id) = ?
		)`, args...)
	if err != nil {
		return 0, 0, err
	}
	price := 0.0
	if row.UnitPrice != nil {
		price = math.Round(*row.UnitPrice*100) / 100
	}
	return price, row.Count, nil
}

func (s *SQL) ContractList(ctx context.Context, generation uint64, typeFilter, search string, limit, offset int) ([]SnapshotContract, int, error) {
	where := "pc.generation_id = ?"
	args := []any{generation}
	if typeFilter != "" {
		where += " AND pc.type = ?"
		args = append(args, typeFilter)
	}
	if search != "" {
		where += " AND pc.title LIKE ?"
		args = append(args, "%"+search+"%")
	}
	var total int
	if err := s.db.GetContext(ctx, &total,
		`SELECT COUNT(*) FROM public_contract_snapshot_contracts pc WHERE `+where, args...); err != nil {
		return nil, 0, err
	}
	var out []SnapshotContract
	err := s.db.SelectContext(ctx, &out, `
		SELECT pc.contract_id, pc.region_id, pc.type, pc.title, pc.issuer_id,
		       pc.price, pc.buyout, pc.reward, pc.collateral, pc.volume,
		       pc.date_issued, pc.date_expired,
		       (SELECT COUNT(*) FROM public_contract_snapshot_items pci
		         WHERE pci.generation_id = pc.generation_id AND pci.contract_id = pc.contract_id) AS items_count
		FROM public_contract_snapshot_contracts pc
		WHERE `+where+`
		ORDER BY pc.date_issued DESC, pc.contract_id DESC
		LIMIT ? OFFSET ?`, append(args, limit, offset)...)
	if out == nil {
		out = []SnapshotContract{}
	}
	return out, total, err
}

func (s *SQL) ContractTypeStats(ctx context.Context, generation uint64) (map[string]int, error) {
	var rows []struct {
		Type  string `db:"type"`
		Count int    `db:"count"`
	}
	err := s.db.SelectContext(ctx, &rows,
		`SELECT type, COUNT(*) AS count FROM public_contract_snapshot_contracts
		 WHERE generation_id = ? GROUP BY type`, generation)
	if err != nil {
		return nil, err
	}
	out := map[string]int{}
	for _, r := range rows {
		out[r.Type] = r.Count
	}
	return out, nil
}

func (s *SQL) ContractShow(ctx context.Context, generation uint64, contractID uint64) (*SnapshotContract, error) {
	var c SnapshotContract
	err := s.db.GetContext(ctx, &c, `
		SELECT pc.contract_id, pc.region_id, pc.type, pc.title, pc.issuer_id,
		       pc.price, pc.buyout, pc.reward, pc.collateral, pc.volume,
		       pc.date_issued, pc.date_expired,
		       (SELECT COUNT(*) FROM public_contract_snapshot_items pci
		         WHERE pci.generation_id = pc.generation_id AND pci.contract_id = pc.contract_id) AS items_count
		FROM public_contract_snapshot_contracts pc
		WHERE pc.generation_id = ? AND pc.contract_id = ?`, generation, contractID)
	if err == sql.ErrNoRows {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	return &c, nil
}

func (s *SQL) ContractItems(ctx context.Context, generation uint64, contractID uint64) ([]SnapshotItem, error) {
	var out []SnapshotItem
	err := s.db.SelectContext(ctx, &out, `
		SELECT type_id, quantity, is_included, is_blueprint_copy,
		       material_efficiency, time_efficiency, runs
		FROM public_contract_snapshot_items
		WHERE generation_id = ? AND contract_id = ?`, generation, contractID)
	if out == nil {
		out = []SnapshotItem{}
	}
	return out, err
}

func (s *SQL) ContractsFreshnessTime(ctx context.Context, generation uint64) (*time.Time, error) {
	var t sql.NullTime
	err := s.db.GetContext(ctx, &t, `
		SELECT MAX(COALESCE(http_last_modified, updated_at))
		FROM public_contract_snapshot_contracts WHERE generation_id = ?`, generation)
	if err != nil || !t.Valid {
		return nil, err
	}
	return &t.Time, nil
}
