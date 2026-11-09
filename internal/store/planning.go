package store

import (
	"context"
	"database/sql"
	"time"
)

// StationConfig mirrors one station_configs row.
type StationConfig struct {
	ID             int64     `db:"id"`
	UserID         int64     `db:"user_id"`
	ActivityType   string    `db:"activity_type"`
	Name           string    `db:"name"`
	StructureType  string    `db:"structure_type"`
	RigME          string    `db:"rig_me"`
	RigTE          string    `db:"rig_te"`
	SystemSecurity string    `db:"system_security"`
	SolarSystemID  *int      `db:"solar_system_id"`
	TaxRate        *float64  `db:"tax_rate"`
	IsDefault      bool      `db:"is_default"`
	CreatedAt      time.Time `db:"created_at"`
	UpdatedAt      time.Time `db:"updated_at"`
}

// StationConfigInput mirrors the validated store/update payloads.
type StationConfigInput struct {
	Name           *string
	StructureType  *string
	RigME          *string
	RigTE          *string
	SystemSecurity *string
	SolarSystemID  *int
	TaxRate        *float64
	IsDefault      *bool
}

// PlanningStore covers the planning writes (station configs first).
type PlanningStore interface {
	StationConfigs(ctx context.Context, userID int64, activity string, limit, offset int) ([]StationConfig, int, error)
	// AllStationConfigs lists the user's configs (optional activity filter),
	// defaults first then by name — the structures index.
	AllStationConfigs(ctx context.Context, userID int64, activity *string) ([]StationConfig, error)
	StationConfig(ctx context.Context, id, userID int64) (*StationConfig, error)
	CreateStationConfig(ctx context.Context, userID int64, activity string, in StationConfigInput) (*StationConfig, error)
	UpdateStationConfig(ctx context.Context, id, userID int64, in StationConfigInput) (*StationConfig, error)
	DeleteStationConfig(ctx context.Context, id, userID int64) (bool, error)
	SetDefaultStationConfig(ctx context.Context, id, userID int64) (*StationConfig, error)
}

const stationConfigColumns = `id, user_id, activity_type, name, structure_type,
	rig_me, rig_te, system_security, solar_system_id, tax_rate, is_default,
	created_at, updated_at`

func scanStationConfig(row stationConfigRow) *StationConfig {
	out := &StationConfig{
		ID: row.ID, UserID: row.UserID, ActivityType: row.ActivityType, Name: row.Name,
		StructureType: row.StructureType, RigME: row.RigME, RigTE: row.RigTE,
		SystemSecurity: row.SystemSecurity, IsDefault: row.IsDefault,
		CreatedAt: row.CreatedAt, UpdatedAt: row.UpdatedAt,
	}
	if row.SolarSystemID.Valid {
		v := int(row.SolarSystemID.Int64)
		out.SolarSystemID = &v
	}
	if row.TaxRate.Valid {
		v := row.TaxRate.Float64
		out.TaxRate = &v
	}
	return out
}

type stationConfigRow struct {
	ID             int64           `db:"id"`
	UserID         int64           `db:"user_id"`
	ActivityType   string          `db:"activity_type"`
	Name           string          `db:"name"`
	StructureType  string          `db:"structure_type"`
	RigME          string          `db:"rig_me"`
	RigTE          string          `db:"rig_te"`
	SystemSecurity string          `db:"system_security"`
	SolarSystemID  sql.NullInt64   `db:"solar_system_id"`
	TaxRate        sql.NullFloat64 `db:"tax_rate"`
	IsDefault      bool            `db:"is_default"`
	CreatedAt      time.Time       `db:"created_at"`
	UpdatedAt      time.Time       `db:"updated_at"`
}

func (s *SQL) StationConfigs(ctx context.Context, userID int64, activity string, limit, offset int) ([]StationConfig, int, error) {
	var total int
	err := s.db.GetContext(ctx, &total, `
		SELECT COUNT(*) FROM station_configs WHERE user_id = ? AND activity_type = ?`, userID, activity)
	if err != nil {
		return nil, 0, err
	}
	var rows []stationConfigRow
	err = s.db.SelectContext(ctx, &rows, `
		SELECT `+stationConfigColumns+` FROM station_configs
		WHERE user_id = ? AND activity_type = ?
		ORDER BY is_default DESC, name ASC LIMIT ? OFFSET ?`, userID, activity, limit, offset)
	if err != nil {
		return nil, 0, err
	}
	out := make([]StationConfig, 0, len(rows))
	for _, r := range rows {
		out = append(out, *scanStationConfig(r))
	}
	return out, total, nil
}

// StationConfig loads one config owned by the user: nil when missing or
// another user's (the PHP denyAsNotFound 404).
func (s *SQL) StationConfig(ctx context.Context, id, userID int64) (*StationConfig, error) {
	var row stationConfigRow
	err := s.db.GetContext(ctx, &row, `
		SELECT `+stationConfigColumns+` FROM station_configs WHERE id = ? AND user_id = ?`, id, userID)
	if err == sql.ErrNoRows {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	return scanStationConfig(row), nil
}

func (s *SQL) CreateStationConfig(ctx context.Context, userID int64, activity string, in StationConfigInput) (*StationConfig, error) {
	isDefault := in.IsDefault != nil && *in.IsDefault
	tx, err := s.db.BeginTxx(ctx, nil)
	if err != nil {
		return nil, err
	}
	defer tx.Rollback()
	if isDefault {
		if _, err := tx.ExecContext(ctx, `
			UPDATE station_configs SET is_default = 0
			WHERE user_id = ? AND activity_type = ?`, userID, activity); err != nil {
			return nil, err
		}
	}
	res, err := tx.ExecContext(ctx, `
		INSERT INTO station_configs
			(user_id, activity_type, name, structure_type, rig_me, rig_te, system_security, solar_system_id, tax_rate, is_default, created_at, updated_at)
		VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())`,
		userID, activity, strVal(in.Name), strVal(in.StructureType), strVal(in.RigME),
		strVal(in.RigTE), strVal(in.SystemSecurity), intVal(in.SolarSystemID), floatVal(in.TaxRate), isDefault)
	if err != nil {
		return nil, err
	}
	id, err := res.LastInsertId()
	if err != nil {
		return nil, err
	}
	var row stationConfigRow
	if err := tx.GetContext(ctx, &row, `SELECT `+stationConfigColumns+` FROM station_configs WHERE id = ?`, id); err != nil {
		return nil, err
	}
	if err := tx.Commit(); err != nil {
		return nil, err
	}
	return scanStationConfig(row), nil
}

func (s *SQL) UpdateStationConfig(ctx context.Context, id, userID int64, in StationConfigInput) (*StationConfig, error) {
	cur, err := s.StationConfig(ctx, id, userID)
	if err != nil || cur == nil {
		return cur, err
	}
	tx, err := s.db.BeginTxx(ctx, nil)
	if err != nil {
		return nil, err
	}
	defer tx.Rollback()
	if in.IsDefault != nil && *in.IsDefault {
		if _, err := tx.ExecContext(ctx, `
			UPDATE station_configs SET is_default = 0
			WHERE user_id = ? AND activity_type = ? AND id <> ?`,
			userID, cur.ActivityType, id); err != nil {
			return nil, err
		}
	}
	set := map[string]any{}
	if in.Name != nil {
		set["name"] = *in.Name
	}
	if in.StructureType != nil {
		set["structure_type"] = *in.StructureType
	}
	if in.RigME != nil {
		set["rig_me"] = *in.RigME
	}
	if in.RigTE != nil {
		set["rig_te"] = *in.RigTE
	}
	if in.SystemSecurity != nil {
		set["system_security"] = *in.SystemSecurity
	}
	if in.SolarSystemID != nil {
		set["solar_system_id"] = *in.SolarSystemID
	}
	if in.TaxRate != nil {
		set["tax_rate"] = *in.TaxRate
	}
	if in.IsDefault != nil {
		set["is_default"] = *in.IsDefault
	}
	if len(set) > 0 {
		q := `UPDATE station_configs SET updated_at = NOW()`
		var args []any
		for k, v := range set {
			q += ", " + k + " = ?"
			args = append(args, v)
		}
		q += ` WHERE id = ? AND user_id = ?`
		args = append(args, id, userID)
		if _, err := tx.ExecContext(ctx, q, args...); err != nil {
			return nil, err
		}
	}
	var row stationConfigRow
	if err := tx.GetContext(ctx, &row, `SELECT `+stationConfigColumns+` FROM station_configs WHERE id = ?`, id); err != nil {
		return nil, err
	}
	if err := tx.Commit(); err != nil {
		return nil, err
	}
	return scanStationConfig(row), nil
}

func (s *SQL) DeleteStationConfig(ctx context.Context, id, userID int64) (bool, error) {
	res, err := s.db.ExecContext(ctx, `DELETE FROM station_configs WHERE id = ? AND user_id = ?`, id, userID)
	if err != nil {
		return false, err
	}
	n, err := res.RowsAffected()
	return n > 0, err
}

func (s *SQL) SetDefaultStationConfig(ctx context.Context, id, userID int64) (*StationConfig, error) {
	cur, err := s.StationConfig(ctx, id, userID)
	if err != nil || cur == nil {
		return cur, err
	}
	tx, err := s.db.BeginTxx(ctx, nil)
	if err != nil {
		return nil, err
	}
	defer tx.Rollback()
	if _, err := tx.ExecContext(ctx, `
		UPDATE station_configs SET is_default = 0 WHERE user_id = ? AND activity_type = ?`,
		userID, cur.ActivityType); err != nil {
		return nil, err
	}
	if _, err := tx.ExecContext(ctx, `UPDATE station_configs SET is_default = 1, updated_at = NOW() WHERE id = ?`, id); err != nil {
		return nil, err
	}
	var row stationConfigRow
	if err := tx.GetContext(ctx, &row, `SELECT `+stationConfigColumns+` FROM station_configs WHERE id = ?`, id); err != nil {
		return nil, err
	}
	if err := tx.Commit(); err != nil {
		return nil, err
	}
	return scanStationConfig(row), nil
}

func intVal(v *int) any {
	if v == nil {
		return nil
	}
	return *v
}

func floatVal(v *float64) any {
	if v == nil {
		return nil
	}
	return *v
}

func strVal(s *string) string {
	if s == nil {
		return ""
	}
	return *s
}

// AllStationConfigs ports StructureListService::forUser.
func (s *SQL) AllStationConfigs(ctx context.Context, userID int64, activity *string) ([]StationConfig, error) {
	q := `SELECT ` + stationConfigColumns + ` FROM station_configs WHERE user_id = ?`
	args := []any{userID}
	if activity != nil {
		q += ` AND activity_type = ?`
		args = append(args, *activity)
	}
	q += ` ORDER BY is_default DESC, name ASC`
	var rows []stationConfigRow
	if err := s.db.SelectContext(ctx, &rows, q, args...); err != nil {
		return nil, err
	}
	out := make([]StationConfig, 0, len(rows))
	for _, r := range rows {
		out = append(out, *scanStationConfig(r))
	}
	return out, nil
}
