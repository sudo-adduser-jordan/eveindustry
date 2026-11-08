package store

import (
	"context"
	"database/sql"
	"encoding/json"

	"github.com/sudo-adduser-jordan/eveindustry/internal/auth"
)

// AuthStore covers the session user lookup, /me characters and account
// deletion. Tokens never leave this package's queries.
type AuthStore interface {
	UserByID(ctx context.Context, userID int64) (*auth.User, error)
	CharactersForUser(ctx context.Context, userID int64) ([]auth.Character, error)
	DeleteUser(ctx context.Context, userID int64) error
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
	return out, nil
}

func (s *SQL) DeleteUser(ctx context.Context, userID int64) error {
	// characters cascade via FK; sessions are stateless cookies.
	_, err := s.db.ExecContext(ctx, `DELETE FROM users WHERE id = ?`, userID)
	return err
}
