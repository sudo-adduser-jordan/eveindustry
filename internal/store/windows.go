package store

import (
	"context"
	"database/sql"

	"github.com/jmoiron/sqlx"
)

// StructureWindow mirrors one structure_vulnerability_windows row.
type StructureWindow struct {
	ID              int64          `db:"id"`
	CorporationID   int64          `db:"corporation_id"`
	SolarSystemID   int            `db:"solar_system_id"`
	SolarSystemName string         `db:"solar_system_name"`
	StructureType   string         `db:"structure_type"`
	StructureName   string         `db:"structure_name"`
	DayOfWeek       sql.NullInt64  `db:"day_of_week"`
	StartTimeUTC    string         `db:"start_time_utc"`
	DurationMinutes int            `db:"duration_minutes"`
	ReinforceExitAt sql.NullTime   `db:"reinforce_exit_at"`
	Notes           sql.NullString `db:"notes"`
	CreatedByUserID sql.NullInt64  `db:"created_by_user_id"`
}

// WindowStore covers corporation structure vulnerability windows.
type WindowStore interface {
	StructureWindows(ctx context.Context, corporationID int64, limit, offset int) ([]StructureWindow, int, error)
	StructureWindow(ctx context.Context, id int64) (*StructureWindow, error)
	CreateStructureWindow(ctx context.Context, w StructureWindow) (*StructureWindow, error)
	UpdateStructureWindow(ctx context.Context, w StructureWindow) (*StructureWindow, error)
	DeleteStructureWindow(ctx context.Context, id int64) error
}

const windowCols = `id, corporation_id, solar_system_id, solar_system_name, structure_type,
	structure_name, day_of_week, start_time_utc, duration_minutes, reinforce_exit_at, notes, created_by_user_id`

// StructureWindows lists a corporation's windows by system, type and name.
func (s *SQL) StructureWindows(ctx context.Context, corporationID int64, limit, offset int) ([]StructureWindow, int, error) {
	var total int
	if err := s.db.GetContext(ctx, &total, `SELECT COUNT(*) FROM structure_vulnerability_windows WHERE corporation_id = ?`, corporationID); err != nil {
		return nil, 0, err
	}
	var out []StructureWindow
	err := s.db.SelectContext(ctx, &out, `SELECT `+windowCols+`
		FROM structure_vulnerability_windows WHERE corporation_id = ?
		ORDER BY solar_system_name, structure_type, structure_name, id LIMIT ? OFFSET ?`,
		corporationID, limit, offset)
	if out == nil {
		out = []StructureWindow{}
	}
	return out, total, err
}

func (s *SQL) StructureWindow(ctx context.Context, id int64) (*StructureWindow, error) {
	var out StructureWindow
	err := s.db.GetContext(ctx, &out, `SELECT `+windowCols+`
		FROM structure_vulnerability_windows WHERE id = ?`, id)
	if err == sql.ErrNoRows {
		return nil, nil
	}
	return &out, err
}

// CreateStructureWindow adds a vulnerability window.
func (s *SQL) CreateStructureWindow(ctx context.Context, w StructureWindow) (*StructureWindow, error) {
	res, err := s.db.ExecContext(ctx, `
		INSERT INTO structure_vulnerability_windows
			(corporation_id, solar_system_id, solar_system_name, structure_type, structure_name,
			 day_of_week, start_time_utc, duration_minutes, reinforce_exit_at, notes, created_by_user_id, created_at, updated_at)
		VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())`,
		w.CorporationID, w.SolarSystemID, w.SolarSystemName, w.StructureType, w.StructureName,
		w.DayOfWeek, w.StartTimeUTC, w.DurationMinutes, w.ReinforceExitAt, w.Notes, w.CreatedByUserID)
	if err != nil {
		return nil, err
	}
	id, err := res.LastInsertId()
	if err != nil {
		return nil, err
	}
	return s.StructureWindow(ctx, id)
}

// UpdateStructureWindow applies the window edit.
func (s *SQL) UpdateStructureWindow(ctx context.Context, w StructureWindow) (*StructureWindow, error) {
	if _, err := s.db.ExecContext(ctx, `UPDATE structure_vulnerability_windows SET solar_system_id = ?,
		solar_system_name = ?, structure_type = ?, structure_name = ?, day_of_week = ?,
		start_time_utc = ?, duration_minutes = ?, reinforce_exit_at = ?, notes = ?, updated_at = NOW()
		WHERE id = ?`,
		w.SolarSystemID, w.SolarSystemName, w.StructureType, w.StructureName, w.DayOfWeek,
		w.StartTimeUTC, w.DurationMinutes, w.ReinforceExitAt, w.Notes, w.ID); err != nil {
		return nil, err
	}
	return s.StructureWindow(ctx, w.ID)
}

func (s *SQL) DeleteStructureWindow(ctx context.Context, id int64) error {
	_, err := s.db.ExecContext(ctx, `DELETE FROM structure_vulnerability_windows WHERE id = ?`, id)
	return err
}

// CorpWindows lists tracked windows for corporations (notification scan).
func (s *SQL) CorpWindows(ctx context.Context, corpIDs []int64) ([]StructureWindow, error) {
	if len(corpIDs) == 0 {
		return nil, nil
	}
	q, args, err := sqlx.In(`SELECT `+windowCols+` FROM structure_vulnerability_windows WHERE corporation_id IN (?)`, corpIDs)
	if err != nil {
		return nil, err
	}
	var out []StructureWindow
	if err := s.db.SelectContext(ctx, &out, s.db.Rebind(q), args...); err != nil {
		return nil, err
	}
	if out == nil {
		out = []StructureWindow{}
	}
	return out, nil
}
