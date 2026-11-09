package store

import (
	"context"
	"database/sql"
	"encoding/json"
	"time"

	"github.com/sudo-adduser-jordan/eveindustry/internal/auth"
)

// AuthStore covers the session user lookup, /me characters and account
// deletion. Tokens never leave this package's queries.
type AuthStore interface {
	UserByID(ctx context.Context, userID int64) (*auth.User, error)
	CharactersForUser(ctx context.Context, userID int64) ([]auth.Character, error)
	DeleteUser(ctx context.Context, userID int64) error
	UpdateCharacterLocation(ctx context.Context, userID, characterID int64, location string) error
	CharacterOwner(ctx context.Context, characterID int64) (userID int64, isMain bool, count int, err error)
	SetMainCharacter(ctx context.Context, userID, characterID int64) error
	DeleteCharacter(ctx context.Context, userID, characterID int64) error
	UpdateCharacterRefresh(ctx context.Context, characterID int64, r CharacterRefresh) error
	EsiActiveCharacters(ctx context.Context, userID *int64) ([]auth.Character, error)
}

type authUserRow struct {
	ID             int64  `db:"id"`
	Name           string `db:"name"`
	IsAdmin        bool   `db:"is_admin"`
	IsSuperAdmin   bool   `db:"is_super_admin"`
	IsActive       bool   `db:"is_active"`
	IsStockManager bool   `db:"is_stock_manager_enabled"`
	IsDemo         bool   `db:"is_demo"`
}

func (s *SQL) UserByID(ctx context.Context, userID int64) (*auth.User, error) {
	var row authUserRow
	err := s.db.GetContext(ctx, &row, `
		SELECT id, name, is_admin, is_super_admin, is_active,
		       is_stock_manager_enabled, is_demo
		FROM users WHERE id = ?`, userID)
	if err == sql.ErrNoRows {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	return &auth.User{
		ID: row.ID, Name: row.Name, IsAdmin: row.IsAdmin,
		IsSuperAdmin: row.IsSuperAdmin, IsActive: row.IsActive,
		IsStockManager: row.IsStockManager, IsDemo: row.IsDemo,
	}, nil
}

type authCharacterRow struct {
	CharacterID     int64          `db:"character_id"`
	Name            string         `db:"name"`
	CorporationID   int64          `db:"corporation_id"`
	CorporationName sql.NullString `db:"corporation_name"`
	AllianceID      sql.NullInt64  `db:"alliance_id"`
	AllianceName    sql.NullString `db:"alliance_name"`
	IsMain          bool           `db:"is_main"`
	IsEsiActive     bool           `db:"is_esi_active"`
	IsDirectorCEO   bool           `db:"is_director_ceo"`
	CachedLocation  sql.NullString `db:"cached_location"`
	CachedTraining  sql.NullString `db:"cached_training"`
	Scopes          sql.NullString `db:"scopes"`
	Roles           sql.NullString `db:"roles"`
}

func (s *SQL) CharactersForUser(ctx context.Context, userID int64) ([]auth.Character, error) {
	rows, err := func() ([]authCharacterRow, error) {
		var rows []authCharacterRow
		err := s.db.SelectContext(ctx, &rows, `
			SELECT character_id, name, corporation_id, corporation_name,
			       alliance_id, alliance_name, is_main, is_esi_active, is_director_ceo,
			       cached_location, cached_training, scopes, roles
			FROM characters WHERE user_id = ?
			ORDER BY is_main DESC, name`, userID)
		return rows, err
	}()
	if err != nil {
		return nil, err
	}
	return scanAuthCharacters(rows), nil
}

func scanAuthCharacters(rows []authCharacterRow) []auth.Character {
	out := make([]auth.Character, 0, len(rows))
	for _, r := range rows {
		ch := auth.Character{
			CharacterID: r.CharacterID, Name: r.Name,
			CorporationID: &r.CorporationID, IsMain: r.IsMain,
			IsEsiActive: r.IsEsiActive, IsDirectorCEO: r.IsDirectorCEO,
		}
		if r.CorporationName.Valid {
			v := r.CorporationName.String
			ch.CorporationName = &v
		}
		if r.AllianceID.Valid {
			v := r.AllianceID.Int64
			ch.AllianceID = &v
		}
		if r.AllianceName.Valid {
			v := r.AllianceName.String
			ch.AllianceName = &v
		}
		if r.CachedLocation.Valid {
			v := r.CachedLocation.String
			ch.CachedLocation = &v
		}
		if r.CachedTraining.Valid {
			v := r.CachedTraining.String
			ch.CachedTraining = &v
		}
		if r.Scopes.Valid && r.Scopes.String != "" {
			var scopes []string
			if err := json.Unmarshal([]byte(r.Scopes.String), &scopes); err == nil {
				ch.Scopes = scopes
			}
		}
		if r.Roles.Valid && r.Roles.String != "" {
			var roles []string
			if err := json.Unmarshal([]byte(r.Roles.String), &roles); err == nil {
				ch.Roles = roles
			}
		}
		out = append(out, ch)
	}
	return out
}

func (s *SQL) DeleteUser(ctx context.Context, userID int64) error {
	// characters cascade via FK; sessions are stateless cookies.
	_, err := s.db.ExecContext(ctx, `DELETE FROM users WHERE id = ?`, userID)
	return err
}

// UpdateCharacterLocation stores the ESI-resolved system name on the character.
func (s *SQL) UpdateCharacterLocation(ctx context.Context, userID, characterID int64, location string) error {
	_, err := s.db.ExecContext(ctx, `UPDATE characters SET cached_location = ?, updated_at = CURRENT_TIMESTAMP
		WHERE user_id = ? AND character_id = ?`, location, userID, characterID)
	return err
}

// CharacterOwner loads a character's owner and main flag plus the owner's
// character count (for the last-character guard).
func (s *SQL) CharacterOwner(ctx context.Context, characterID int64) (userID int64, isMain bool, count int, err error) {
	var row struct {
		UserID int64 `db:"user_id"`
		IsMain bool  `db:"is_main"`
	}
	err = s.db.GetContext(ctx, &row, `SELECT user_id, is_main FROM characters WHERE character_id = ?`, characterID)
	if err == sql.ErrNoRows {
		return 0, false, 0, nil
	}
	if err != nil {
		return 0, false, 0, err
	}
	err = s.db.GetContext(ctx, &count, `SELECT COUNT(*) FROM characters WHERE user_id = ?`, row.UserID)
	return row.UserID, row.IsMain, count, err
}

// SetMainCharacter clears the user's mains and promotes one character.
func (s *SQL) SetMainCharacter(ctx context.Context, userID, characterID int64) error {
	tx, err := s.db.BeginTxx(ctx, nil)
	if err != nil {
		return err
	}
	defer tx.Rollback()
	if _, err := tx.ExecContext(ctx, `UPDATE characters SET is_main = 0 WHERE user_id = ?`, userID); err != nil {
		return err
	}
	if _, err := tx.ExecContext(ctx, `UPDATE characters SET is_main = 1 WHERE user_id = ? AND character_id = ?`, userID, characterID); err != nil {
		return err
	}
	return tx.Commit()
}

// DeleteCharacter removes one character, promoting the oldest remaining one
// when it was the main.
func (s *SQL) DeleteCharacter(ctx context.Context, userID, characterID int64) error {
	tx, err := s.db.BeginTxx(ctx, nil)
	if err != nil {
		return err
	}
	defer tx.Rollback()
	var wasMain bool
	if err := tx.GetContext(ctx, &wasMain, `SELECT is_main FROM characters WHERE user_id = ? AND character_id = ?`, userID, characterID); err != nil {
		return err
	}
	if _, err := tx.ExecContext(ctx, `DELETE FROM characters WHERE user_id = ? AND character_id = ?`, userID, characterID); err != nil {
		return err
	}
	if wasMain {
		if _, err := tx.ExecContext(ctx, `UPDATE characters SET is_main = 1 WHERE user_id = ?
			AND character_id = (SELECT character_id FROM (SELECT character_id FROM characters WHERE user_id = ? ORDER BY created_at LIMIT 1) t)`, userID, userID); err != nil {
			return err
		}
	}
	return tx.Commit()
}

// DemoCharacterRow is the minimal character record the demo responder needs.
type DemoCharacterRow struct {
	CharacterID     int64          `db:"character_id"`
	Name            string         `db:"name"`
	CorporationID   sql.NullInt64  `db:"corporation_id"`
	CorporationName sql.NullString `db:"corporation_name"`
	AllianceID      sql.NullInt64  `db:"alliance_id"`
	AllianceName    sql.NullString `db:"alliance_name"`
	Roles           sql.NullString `db:"roles"`
	CreatedAt       time.Time      `db:"created_at"`
}

// DemoCharacters lists characters in the demo id range.
func (s *SQL) DemoCharacters(ctx context.Context) ([]DemoCharacterRow, error) {
	var out []DemoCharacterRow
	err := s.db.SelectContext(ctx, &out, `SELECT character_id, name, corporation_id,
		corporation_name, alliance_id, alliance_name, roles, created_at FROM characters
		WHERE character_id >= 99000000000 AND character_id < 99300000000 ORDER BY character_id`)
	if out == nil {
		out = []DemoCharacterRow{}
	}
	return out, err
}

// CharacterRefresh holds the refreshed character columns.
type CharacterRefresh struct {
	CorporationID   *int64
	CorporationName *string
	AllianceID      *int64
	AllianceName    *string
	CachedLocation  *string
	NullLocation    bool
	CachedTraining  *string
	NullTraining    bool
	Roles           []string
	NullRoles       bool
	IsDirectorCEO   *bool
}

// UpdateCharacterRefresh applies a profile refresh (only set fields change;
// Null* clears the column).
func (s *SQL) UpdateCharacterRefresh(ctx context.Context, characterID int64, r CharacterRefresh) error {
	set := ""
	var args []any
	if r.CorporationID != nil {
		set += "corporation_id = ?, "
		args = append(args, *r.CorporationID)
	}
	if r.CorporationName != nil {
		set += "corporation_name = ?, "
		args = append(args, *r.CorporationName)
	}
	if r.AllianceID != nil {
		set += "alliance_id = ?, "
		args = append(args, *r.AllianceID)
	}
	if r.AllianceName != nil {
		set += "alliance_name = ?, "
		args = append(args, *r.AllianceName)
	}
	if r.CachedLocation != nil {
		set += "cached_location = ?, "
		args = append(args, *r.CachedLocation)
	} else if r.NullLocation {
		set += "cached_location = NULL, "
	}
	if r.CachedTraining != nil {
		set += "cached_training = ?, "
		args = append(args, *r.CachedTraining)
	} else if r.NullTraining {
		set += "cached_training = NULL, "
	}
	if r.Roles != nil || r.NullRoles {
		set += "roles = ?, is_director_ceo = ?, "
		var roles any
		if r.Roles != nil {
			bs, _ := json.Marshal(r.Roles)
			roles = string(bs)
		}
		dir := false
		if r.IsDirectorCEO != nil {
			dir = *r.IsDirectorCEO
		}
		args = append(args, roles, dir)
	} else if r.IsDirectorCEO != nil {
		set += "is_director_ceo = ?, "
		args = append(args, *r.IsDirectorCEO)
	}
	if set == "" {
		return nil
	}
	set += "updated_at = CURRENT_TIMESTAMP"
	args = append(args, characterID)
	_, err := s.db.ExecContext(ctx, `UPDATE characters SET `+set+` WHERE character_id = ?`, args...)
	return err
}

// EsiActiveCharacters lists ESI-active characters for refresh fan-out.
func (s *SQL) EsiActiveCharacters(ctx context.Context, userID *int64) ([]auth.Character, error) {
	q := `SELECT character_id, name, corporation_id, corporation_name, alliance_id, alliance_name,
		is_main, is_esi_active, is_director_ceo, cached_location, cached_training, scopes, roles
		FROM characters WHERE is_esi_active = 1`
	var args []any
	if userID != nil {
		q += " AND user_id = ?"
		args = append(args, *userID)
	}
	q += " ORDER BY user_id, character_id"
	var rows []authCharacterRow
	if err := s.db.SelectContext(ctx, &rows, q, args...); err != nil {
		return nil, err
	}
	return scanAuthCharacters(rows), nil
}

// ActiveUserIDs ports the refresh fan-out: active users with a login in the
// last 7 days.
func (s *SQL) ActiveUserIDs(ctx context.Context) ([]int64, error) {
	var out []int64
	err := s.db.SelectContext(ctx, &out, `SELECT id FROM users
		WHERE is_active = 1 AND last_login_at >= datetime('now', '-7 days')
		ORDER BY id`)
	if out == nil {
		out = []int64{}
	}
	return out, err
}
