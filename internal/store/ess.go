package store

import (
	"context"
	"database/sql"
	"time"
)

// EssSystem mirrors one ess_tracked_systems row.
type EssSystem struct {
	ID              int64          `db:"id"`
	CorporationID   int64          `db:"corporation_id"`
	SolarSystemID   int            `db:"solar_system_id"`
	SolarSystemName string         `db:"solar_system_name"`
	CycleMinutes    int            `db:"cycle_minutes"`
	CycleAnchorAt   time.Time      `db:"cycle_anchor_at"`
	Notes           sql.NullString `db:"notes"`
	CreatedByUserID sql.NullInt64  `db:"created_by_user_id"`
}

// EssPayout mirrors one ess_payout_events row.
type EssPayout struct {
	ID               int64           `db:"id"`
	SystemID         int64           `db:"ess_tracked_system_id"`
	OccurredAt       time.Time       `db:"occurred_at"`
	AmountISK        sql.NullFloat64 `db:"amount_isk"`
	RecordedByUserID sql.NullInt64   `db:"recorded_by_user_id"`
	Notes            sql.NullString  `db:"notes"`
}

// EssStore covers the corporation ESS tracker, scoped by corporation.
type EssStore interface {
	EssSystems(ctx context.Context, corporationID int64, limit, offset int) ([]EssSystem, int, error)
	EssSystem(ctx context.Context, id int64) (*EssSystem, error)
	UpsertEssSystem(ctx context.Context, s EssSystem) (*EssSystem, error)
	UpdateEssSystem(ctx context.Context, s EssSystem) (*EssSystem, error)
	DeleteEssSystem(ctx context.Context, id int64) error
	EssPayouts(ctx context.Context, systemID int64, limit int) ([]EssPayout, error)
	CreateEssPayout(ctx context.Context, p EssPayout) (*EssPayout, error)
}

const essSystemCols = `id, corporation_id, solar_system_id, solar_system_name,
	cycle_minutes, cycle_anchor_at, notes, created_by_user_id`

// EssSystems lists a corporation's tracked systems by name.
func (s *SQL) EssSystems(ctx context.Context, corporationID int64, limit, offset int) ([]EssSystem, int, error) {
	var total int
	if err := s.db.GetContext(ctx, &total, `SELECT COUNT(*) FROM ess_tracked_systems WHERE corporation_id = ?`, corporationID); err != nil {
		return nil, 0, err
	}
	var out []EssSystem
	err := s.db.SelectContext(ctx, &out, `SELECT `+essSystemCols+`
		FROM ess_tracked_systems WHERE corporation_id = ? ORDER BY solar_system_name LIMIT ? OFFSET ?`,
		corporationID, limit, offset)
	if out == nil {
		out = []EssSystem{}
	}
	return out, total, err
}

func (s *SQL) EssSystem(ctx context.Context, id int64) (*EssSystem, error) {
	var out EssSystem
	err := s.db.GetContext(ctx, &out, `SELECT `+essSystemCols+`
		FROM ess_tracked_systems WHERE id = ?`, id)
	if err == sql.ErrNoRows {
		return nil, nil
	}
	return &out, err
}

// UpsertEssSystem ports the store upsert (one row per corp+system).
func (s *SQL) UpsertEssSystem(ctx context.Context, e EssSystem) (*EssSystem, error) {
	_, err := s.db.ExecContext(ctx, `
		INSERT INTO ess_tracked_systems
			(corporation_id, solar_system_id, solar_system_name, cycle_minutes, cycle_anchor_at, notes, created_by_user_id, created_at, updated_at)
		VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
		ON DUPLICATE KEY UPDATE solar_system_name = VALUES(solar_system_name), cycle_minutes = VALUES(cycle_minutes),
			cycle_anchor_at = VALUES(cycle_anchor_at), notes = VALUES(notes), updated_at = NOW()`,
		e.CorporationID, e.SolarSystemID, e.SolarSystemName, e.CycleMinutes, e.CycleAnchorAt, e.Notes, e.CreatedByUserID)
	if err != nil {
		return nil, err
	}
	var out EssSystem
	err = s.db.GetContext(ctx, &out, `SELECT `+essSystemCols+`
		FROM ess_tracked_systems WHERE corporation_id = ? AND solar_system_id = ?`, e.CorporationID, e.SolarSystemID)
	if err != nil {
		return nil, err
	}
	return &out, nil
}

// UpdateEssSystem applies the cycle edit.
func (s *SQL) UpdateEssSystem(ctx context.Context, e EssSystem) (*EssSystem, error) {
	if _, err := s.db.ExecContext(ctx, `UPDATE ess_tracked_systems SET cycle_minutes = ?,
		cycle_anchor_at = ?, notes = ?, updated_at = NOW() WHERE id = ?`,
		e.CycleMinutes, e.CycleAnchorAt, e.Notes, e.ID); err != nil {
		return nil, err
	}
	return s.EssSystem(ctx, e.ID)
}

func (s *SQL) DeleteEssSystem(ctx context.Context, id int64) error {
	_, err := s.db.ExecContext(ctx, `DELETE FROM ess_tracked_systems WHERE id = ?`, id)
	return err
}

const essPayoutCols = `id, ess_tracked_system_id, occurred_at, amount_isk, recorded_by_user_id, notes`

// EssPayouts loads the latest payouts, newest first.
func (s *SQL) EssPayouts(ctx context.Context, systemID int64, limit int) ([]EssPayout, error) {
	var out []EssPayout
	err := s.db.SelectContext(ctx, &out, `SELECT `+essPayoutCols+`
		FROM ess_payout_events WHERE ess_tracked_system_id = ? ORDER BY occurred_at DESC LIMIT ?`, systemID, limit)
	if out == nil {
		out = []EssPayout{}
	}
	return out, err
}

// CreateEssPayout records a payout.
func (s *SQL) CreateEssPayout(ctx context.Context, p EssPayout) (*EssPayout, error) {
	res, err := s.db.ExecContext(ctx, `
		INSERT INTO ess_payout_events
			(ess_tracked_system_id, occurred_at, amount_isk, recorded_by_user_id, notes, created_at, updated_at)
		VALUES (?, ?, ?, ?, ?, NOW(), NOW())`,
		p.SystemID, p.OccurredAt, p.AmountISK, p.RecordedByUserID, p.Notes)
	if err != nil {
		return nil, err
	}
	id, err := res.LastInsertId()
	if err != nil {
		return nil, err
	}
	var out EssPayout
	err = s.db.GetContext(ctx, &out, `SELECT `+essPayoutCols+` FROM ess_payout_events WHERE id = ?`, id)
	if err != nil {
		return nil, err
	}
	return &out, nil
}
