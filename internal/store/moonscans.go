package store

import (
	"context"
	"database/sql"
	"time"
)

// MoonScanSystem mirrors one moon_scan_systems row.
type MoonScanSystem struct {
	ID            int64          `db:"id"`
	SystemName    string         `db:"system_name"`
	CorporationID sql.NullInt64  `db:"corporation_id"`
	RegionID      int64          `db:"region_id"`
	PricingMode   string         `db:"pricing_mode"`
	LastScannedAt time.Time      `db:"last_scanned_at"`
}

// MoonScanResult mirrors one moon_scan_results row.
type MoonScanResult struct {
	ID                      int64          `db:"id"`
	SystemID                sql.NullInt64  `db:"system_id"`
	MoonName                string         `db:"moon_name"`
	PlanetName              sql.NullString `db:"planet_name"`
	Grade                   string         `db:"grade"`
	OresJSON                string         `db:"ores"`
	TotalValue              int64          `db:"total_value"`
	Occupied                bool           `db:"occupied"`
	OccupyingCorporationID   sql.NullInt64  `db:"occupying_corporation_id"`
	OccupyingCorporationName sql.NullString `db:"occupying_corporation_name"`
	ScannedAt               time.Time      `db:"scanned_at"`
}

// MoonScanStore covers the moon-scan lookup tables, scoped by corporation.
type MoonScanStore interface {
	MoonScanSystems(ctx context.Context, corporationID int64) ([]MoonScanSystem, error)
	MoonScanSystem(ctx context.Context, corporationID int64, systemName string) (*MoonScanSystem, error)
	UpsertMoonScanSystem(ctx context.Context, systemName string, corporationID *int64, regionID int64, pricingMode string) (*MoonScanSystem, error)
	MoonScanMoons(ctx context.Context, systemID int64) ([]MoonScanResult, error)
	UpsertMoonScanResult(ctx context.Context, systemID int64, moonName string, planetName *string, grade, oresJSON string, totalValue int64) (int64, error)
}

const moonScanSystemCols = `id, system_name, corporation_id, region_id, pricing_mode, last_scanned_at`

// MoonScanSystems ports systemsForUser: the corporation's systems by name.
func (s *SQL) MoonScanSystems(ctx context.Context, corporationID int64) ([]MoonScanSystem, error) {
	var out []MoonScanSystem
	err := s.db.SelectContext(ctx, &out, `SELECT `+moonScanSystemCols+`
		FROM moon_scan_systems WHERE corporation_id = ? ORDER BY system_name`, corporationID)
	if out == nil {
		out = []MoonScanSystem{}
	}
	return out, err
}

// MoonScanSystem ports findSystem: nil when the corp never scanned it.
func (s *SQL) MoonScanSystem(ctx context.Context, corporationID int64, systemName string) (*MoonScanSystem, error) {
	var out MoonScanSystem
	err := s.db.GetContext(ctx, &out, `SELECT `+moonScanSystemCols+`
		FROM moon_scan_systems WHERE corporation_id = ? AND system_name = ?`, corporationID, systemName)
	if err == sql.ErrNoRows {
		return nil, nil
	}
	return &out, err
}

// UpsertMoonScanSystem ports the analyse upsert (one row per system+corp).
// The unique key treats NULL corp as distinct per insert, so the lookup
// first updates when a row exists.
func (s *SQL) UpsertMoonScanSystem(ctx context.Context, systemName string, corporationID *int64, regionID int64, pricingMode string) (*MoonScanSystem, error) {
	var corpAny any
	if corporationID != nil {
		corpAny = *corporationID
	}
	res, err := s.db.ExecContext(ctx, `
		INSERT INTO moon_scan_systems (system_name, corporation_id, region_id, pricing_mode, last_scanned_at, created_at, updated_at)
		VALUES (?, ?, ?, ?, NOW(), NOW(), NOW())
		ON DUPLICATE KEY UPDATE region_id = VALUES(region_id), pricing_mode = VALUES(pricing_mode),
			last_scanned_at = VALUES(last_scanned_at), updated_at = NOW()`,
		systemName, corpAny, regionID, pricingMode)
	if err != nil {
		return nil, err
	}
	id, err := res.LastInsertId()
	if err != nil {
		return nil, err
	}
	if id == 0 && corporationID != nil {
		// Duplicate key on the non-null unique pair: update + reload.
		if _, err := s.db.ExecContext(ctx, `UPDATE moon_scan_systems SET region_id = ?, pricing_mode = ?,
			last_scanned_at = NOW(), updated_at = NOW() WHERE system_name = ? AND corporation_id = ?`,
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

// MoonScanMoons loads a system's moons best-first.
func (s *SQL) MoonScanMoons(ctx context.Context, systemID int64) ([]MoonScanResult, error) {
	var out []MoonScanResult
	err := s.db.SelectContext(ctx, &out, `SELECT `+moonScanResultCols+`
		FROM moon_scan_results WHERE system_id = ? ORDER BY total_value DESC`, systemID)
	if out == nil {
		out = []MoonScanResult{}
	}
	return out, err
}

// UpsertMoonScanResult ports the analyse upsert (one row per system+moon),
// returning the row id.
func (s *SQL) UpsertMoonScanResult(ctx context.Context, systemID int64, moonName string, planetName *string, grade, oresJSON string, totalValue int64) (int64, error) {
	var planetAny any
	if planetName != nil {
		planetAny = *planetName
	}
	res, err := s.db.ExecContext(ctx, `
		INSERT INTO moon_scan_results (system_id, moon_name, planet_name, grade, ores, total_value, scanned_at, created_at, updated_at)
		VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW(), NOW())
		ON DUPLICATE KEY UPDATE planet_name = VALUES(planet_name), grade = VALUES(grade), ores = VALUES(ores),
			total_value = VALUES(total_value), scanned_at = VALUES(scanned_at), updated_at = NOW()`,
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
