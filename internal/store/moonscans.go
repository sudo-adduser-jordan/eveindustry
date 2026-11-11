package store

import (
	"context"
	"database/sql"
	"time"
)

type MoonScanSystem struct {
	ID            int64         `db:"id"`
	SystemName    string        `db:"system_name"`
	CorporationID sql.NullInt64 `db:"corporation_id"`
	RegionID      int64         `db:"region_id"`
	PricingMode   string        `db:"pricing_mode"`
	LastScannedAt time.Time     `db:"last_scanned_at"`
}

type MoonScanResult struct {
	ID                       int64          `db:"id"`
	SystemID                 sql.NullInt64  `db:"system_id"`
	MoonName                 string         `db:"moon_name"`
	PlanetName               sql.NullString `db:"planet_name"`
	Grade                    string         `db:"grade"`
	OresJSON                 string         `db:"ores"`
	TotalValue               int64          `db:"total_value"`
	Occupied                 bool           `db:"occupied"`
	OccupyingCorporationID   sql.NullInt64  `db:"occupying_corporation_id"`
	OccupyingCorporationName sql.NullString `db:"occupying_corporation_name"`
	ScannedAt                time.Time      `db:"scanned_at"`
}

type MoonScanStore interface {
	MoonScanSystems(ctx context.Context, corporationID int64) ([]MoonScanSystem, error)
	MoonScanSystem(ctx context.Context, corporationID int64, systemName string) (*MoonScanSystem, error)
	UpsertMoonScanSystem(ctx context.Context, systemName string, corporationID *int64, regionID int64, pricingMode string) (*MoonScanSystem, error)
	MoonScanMoons(ctx context.Context, systemID int64) ([]MoonScanResult, error)
	UpsertMoonScanResult(ctx context.Context, systemID int64, moonName string, planetName *string, grade, oresJSON string, totalValue int64) (int64, error)
}

const moonScanSystemCols = `id, system_name, corporation_id, region_id, pricing_mode, last_scanned_at`

func (s *SQL) MoonScanSystems(ctx context.Context, corporationID int64) ([]MoonScanSystem, error) {
	var out []MoonScanSystem
	err := s.db.SelectContext(ctx, &out, `SELECT `+moonScanSystemCols+`
		FROM moon_scan_systems WHERE corporation_id = ? ORDER BY system_name`, corporationID)
	if out == nil {
		out = []MoonScanSystem{}
	}
	return out, err
}

func (s *SQL) MoonScanSystem(ctx context.Context, corporationID int64, systemName string) (*MoonScanSystem, error) {
	var out MoonScanSystem
	err := s.db.GetContext(ctx, &out, `SELECT `+moonScanSystemCols+`
		FROM moon_scan_systems WHERE corporation_id = ? AND system_name = ?`, corporationID, systemName)
	if err == sql.ErrNoRows {
		return nil, nil
	}
	return &out, err
}

func (s *SQL) UpsertMoonScanSystem(ctx context.Context, systemName string, corporationID *int64, regionID int64, pricingMode string) (*MoonScanSystem, error) {
	var corpAny any
	if corporationID != nil {
		corpAny = *corporationID
	}
	res, err := s.db.ExecContext(ctx, `
		INSERT INTO moon_scan_systems (system_name, corporation_id, region_id, pricing_mode, last_scanned_at, created_at, updated_at)
		VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
		ON CONFLICT(system_name, corporation_id) DO UPDATE SET region_id = excluded.region_id, pricing_mode = excluded.pricing_mode,
			last_scanned_at = excluded.last_scanned_at, updated_at = CURRENT_TIMESTAMP`,
		systemName, corpAny, regionID, pricingMode)
	if err != nil {
		return nil, err
	}
	id, err := res.LastInsertId()
	if err != nil {
		return nil, err
	}
	if id == 0 && corporationID != nil {
		if _, err := s.db.ExecContext(ctx, `UPDATE moon_scan_systems SET region_id = ?, pricing_mode = ?,
			last_scanned_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE system_name = ? AND corporation_id = ?`,
			regionID, pricingMode, systemName, *corporationID); err != nil {
			return nil, err
		}
		if corporationID != nil {
			return s.MoonScanSystem(ctx, *corporationID, systemName)
		}
	}
	var out MoonScanSystem
	if err := s.db.GetContext(ctx, &out, `SELECT `+moonScanSystemCols+`
		FROM moon_scan_systems WHERE id = ?`, id); err != nil {
		return nil, err
	}
	return &out, nil
}

const moonScanResultCols = `id, system_id, moon_name, planet_name, grade, ores,
	total_value, occupied, occupying_corporation_id, occupying_corporation_name, scanned_at`

func (s *SQL) MoonScanMoons(ctx context.Context, systemID int64) ([]MoonScanResult, error) {
	var out []MoonScanResult
	err := s.db.SelectContext(ctx, &out, `SELECT `+moonScanResultCols+`
		FROM moon_scan_results WHERE system_id = ? ORDER BY total_value DESC`, systemID)
	if out == nil {
		out = []MoonScanResult{}
	}
	return out, err
}

func (s *SQL) UpsertMoonScanResult(ctx context.Context, systemID int64, moonName string, planetName *string, grade, oresJSON string, totalValue int64) (int64, error) {
	var planetAny any
	if planetName != nil {
		planetAny = *planetName
	}
	res, err := s.db.ExecContext(ctx, `
		INSERT INTO moon_scan_results (system_id, moon_name, planet_name, grade, ores, total_value, scanned_at, created_at, updated_at)
		VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
		ON CONFLICT(system_id, moon_name) DO UPDATE SET planet_name = excluded.planet_name, grade = excluded.grade, ores = excluded.ores,
			total_value = excluded.total_value, scanned_at = excluded.scanned_at, updated_at = CURRENT_TIMESTAMP`,
		systemID, moonName, planetAny, grade, oresJSON, totalValue)
	if err != nil {
		return 0, err
	}
	id, err := res.LastInsertId()
	if err != nil {
		return 0, err
	}
	if id == 0 {
		var existing int64
		if err := s.db.GetContext(ctx, &existing, `SELECT id FROM moon_scan_results
			WHERE system_id = ? AND moon_name = ?`, systemID, moonName); err != nil {
			return 0, err
		}
		return existing, nil
	}
	return id, nil
}
