package store

import (
	"context"
	"database/sql"
	"time"

	"github.com/jmoiron/sqlx"
)

// BuildProgram mirrors one build_programs row. Steps and Settings are raw
// JSON payloads decoded by the handler layer.
type BuildProgram struct {
	ID           int64          `db:"id"`
	UserID       int64          `db:"user_id"`
	Name         string         `db:"name"`
	ProgramType  string         `db:"program_type"`
	T2TypeID     sql.NullInt64  `db:"t2_type_id"`
	T2TypeName   sql.NullString `db:"t2_type_name"`
	StepsJSON    string         `db:"steps"`
	SettingsJSON string         `db:"settings"`
	CreatedAt    time.Time      `db:"created_at"`
	UpdatedAt    time.Time      `db:"updated_at"`
}

// ProgramStore covers personal build programs. Reads are owner-scoped
// (nil when missing or another user's, matching the 404 policies).
type ProgramStore interface {
	BuildPrograms(ctx context.Context, userID int64) ([]BuildProgram, error)
	BuildProgram(ctx context.Context, id, userID int64) (*BuildProgram, error)
	BuildProgramsByIDs(ctx context.Context, userID int64, ids []int64) ([]BuildProgram, error)
	CreateBuildProgram(ctx context.Context, p BuildProgram) (*BuildProgram, error)
	RenameBuildProgram(ctx context.Context, id, userID int64, name string) (*BuildProgram, error)
	UpdateBuildProgramSettings(ctx context.Context, id, userID int64, settingsJSON string) (*BuildProgram, error)
	DeleteBuildProgram(ctx context.Context, id, userID int64) (bool, error)
}

const buildProgramCols = `id, user_id, name, program_type, t2_type_id, t2_type_name, steps, settings, created_at, updated_at`

func (s *SQL) BuildPrograms(ctx context.Context, userID int64) ([]BuildProgram, error) {
	var out []BuildProgram
	err := s.db.SelectContext(ctx, &out, `SELECT `+buildProgramCols+`
		FROM build_programs WHERE user_id = ? ORDER BY updated_at DESC`, userID)
	if out == nil {
		out = []BuildProgram{}
	}
	return out, err
}

func (s *SQL) BuildProgram(ctx context.Context, id, userID int64) (*BuildProgram, error) {
	var out BuildProgram
	err := s.db.GetContext(ctx, &out, `SELECT `+buildProgramCols+` FROM build_programs WHERE id = ? AND user_id = ?`, id, userID)
	if err == sql.ErrNoRows {
		return nil, nil
	}
	return &out, err
}

func (s *SQL) BuildProgramsByIDs(ctx context.Context, userID int64, ids []int64) ([]BuildProgram, error) {
	if len(ids) == 0 {
		return nil, nil
	}
	q, args, err := sqlx.In(`SELECT `+buildProgramCols+` FROM build_programs WHERE user_id = ? AND id IN (?)`, userID, ids)
	if err != nil {
		return nil, err
	}
	var out []BuildProgram
	if err := s.db.SelectContext(ctx, &out, s.db.Rebind(q), args...); err != nil {
		return nil, err
	}
	return out, nil
}

func (s *SQL) CreateBuildProgram(ctx context.Context, p BuildProgram) (*BuildProgram, error) {
	res, err := s.db.ExecContext(ctx, `
		INSERT INTO build_programs
			(user_id, name, program_type, t2_type_id, t2_type_name, steps, settings, created_at, updated_at)
		VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())`,
		p.UserID, p.Name, p.ProgramType, p.T2TypeID, p.T2TypeName, p.StepsJSON, p.SettingsJSON)
	if err != nil {
		return nil, err
	}
	id, err := res.LastInsertId()
	if err != nil {
		return nil, err
	}
	return s.BuildProgram(ctx, id, p.UserID)
}

func (s *SQL) RenameBuildProgram(ctx context.Context, id, userID int64, name string) (*BuildProgram, error) {
	if _, err := s.db.ExecContext(ctx, `UPDATE build_programs SET name = ?, updated_at = NOW() WHERE id = ? AND user_id = ?`, name, id, userID); err != nil {
		return nil, err
	}
	return s.BuildProgram(ctx, id, userID)
}

func (s *SQL) UpdateBuildProgramSettings(ctx context.Context, id, userID int64, settingsJSON string) (*BuildProgram, error) {
	if _, err := s.db.ExecContext(ctx, `UPDATE build_programs SET settings = ?, updated_at = NOW() WHERE id = ? AND user_id = ?`, settingsJSON, id, userID); err != nil {
		return nil, err
	}
	return s.BuildProgram(ctx, id, userID)
}

func (s *SQL) DeleteBuildProgram(ctx context.Context, id, userID int64) (bool, error) {
	res, err := s.db.ExecContext(ctx, `DELETE FROM build_programs WHERE id = ? AND user_id = ?`, id, userID)
	if err != nil {
		return false, err
	}
	n, err := res.RowsAffected()
	return n > 0, err
}
