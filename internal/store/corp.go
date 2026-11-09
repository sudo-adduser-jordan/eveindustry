package store

import (
	"context"
	"database/sql"
	"encoding/json"

	"github.com/jmoiron/sqlx"
)

// CorpStore covers the corporate-export availability check: active corp
// configs and the user's director/Factory_Manager standing.
type CorpStore interface {
	ActiveCorpConfig(ctx context.Context, corporationID int64) (*CorpConfig, error)
	UserCorpCharacters(ctx context.Context, userID, corporationID int64) ([]CorpCharacter, error)
	// CorpConfigForCorps returns the active feature config for the first
	// matching corporation, else nil.
	CorpConfigForCorps(ctx context.Context, corpIDs []int64) (*CorpConfigFull, error)
	UpsertCorpConfig(ctx context.Context, corpID int64, corpName string, characterID, userID int64) (*CorpConfigFull, error)
	DeactivateCorpConfig(ctx context.Context, corpID int64) error
}

// CorpConfig mirrors the corporation_build_program_configs row the endpoint reads.
type CorpConfig struct {
	CorporationID   int64  `db:"corporation_id"`
	CorporationName string `db:"corporation_name"`
	IsActive        bool   `db:"is_active"`
}

// CorpCharacter is one user character in a corporation with corp standing.
type CorpCharacter struct {
	CharacterID   int64
	IsEsiActive   bool
	Scopes        []string
	IsDirectorCEO bool
	Roles         []string
}

// ActiveCorpConfig ports findActiveForCorporation().
func (s *SQL) ActiveCorpConfig(ctx context.Context, corporationID int64) (*CorpConfig, error) {
	var c CorpConfig
	err := s.db.GetContext(ctx, &c, `
		SELECT corporation_id, corporation_name, is_active
		FROM corporation_build_program_configs
		WHERE corporation_id = ? AND is_active = 1`, corporationID)
	if err == sql.ErrNoRows {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	return &c, nil
}

func (s *SQL) UserCorpCharacters(ctx context.Context, userID, corporationID int64) ([]CorpCharacter, error) {
	var rows []struct {
		CharacterID   int64          `db:"character_id"`
		IsEsiActive   bool           `db:"is_esi_active"`
		Scopes        sql.NullString `db:"scopes"`
		IsDirectorCEO bool           `db:"is_director_ceo"`
		Roles         sql.NullString `db:"roles"`
	}
	err := s.db.SelectContext(ctx, &rows, `
		SELECT character_id, is_esi_active, scopes, is_director_ceo, roles FROM characters
		WHERE user_id = ? AND corporation_id = ?`, userID, corporationID)
	if err != nil {
		return nil, err
	}
	out := make([]CorpCharacter, 0, len(rows))
	for _, r := range rows {
		cc := CorpCharacter{CharacterID: r.CharacterID, IsEsiActive: r.IsEsiActive, IsDirectorCEO: r.IsDirectorCEO}
		if r.Scopes.Valid && r.Scopes.String != "" {
			var scopes []string
			if err := json.Unmarshal([]byte(r.Scopes.String), &scopes); err == nil {
				cc.Scopes = scopes
			}
		}
		if r.Roles.Valid && r.Roles.String != "" {
			var roles []string
			if err := json.Unmarshal([]byte(r.Roles.String), &roles); err == nil {
				cc.Roles = roles
			}
		}
		out = append(out, cc)
	}
	return out, nil
}

// CorpConfigFull mirrors one corporation_build_program_configs row.
type CorpConfigFull struct {
	ID              int64          `db:"id"`
	CorporationID   int64          `db:"corporation_id"`
	CorporationName sql.NullString `db:"corporation_name"`
	CharacterID     int64          `db:"character_id"`
	EnabledByUserID int64          `db:"enabled_by_user_id"`
	IsActive        bool           `db:"is_active"`
}

// CorpPageStore covers the corporate build-program feature flag: the active
// config for the user's corporations, enable (upsert) and disable.
type CorpPageStore interface {
	CorpConfigForCorps(ctx context.Context, corpIDs []int64) (*CorpConfigFull, error)
	UpsertCorpConfig(ctx context.Context, corpID int64, corpName string, characterID, userID int64) (*CorpConfigFull, error)
	DeactivateCorpConfig(ctx context.Context, corpID int64) error
}

const corpConfigFullCols = `id, corporation_id, corporation_name, character_id, enabled_by_user_id, is_active`

// CorpConfigForCorps returns the active config for the first matching
// corporation (caller order = user's character order), else nil.
func (s *SQL) CorpConfigForCorps(ctx context.Context, corpIDs []int64) (*CorpConfigFull, error) {
	if len(corpIDs) == 0 {
		return nil, nil
	}
	q, args, err := sqlx.In(`SELECT `+corpConfigFullCols+` FROM corporation_build_program_configs
		WHERE corporation_id IN (?) AND is_active = 1`, corpIDs)
	if err != nil {
		return nil, err
	}
	var rows []CorpConfigFull
	if err := s.db.SelectContext(ctx, &rows, s.db.Rebind(q), args...); err != nil {
		return nil, err
	}
	if len(rows) == 0 {
		return nil, nil
	}
	byCorp := make(map[int64]*CorpConfigFull, len(rows))
	for i := range rows {
		r := rows[i]
		byCorp[r.CorporationID] = &r
	}
	for _, id := range corpIDs {
		if c, ok := byCorp[id]; ok {
			return c, nil
		}
	}
	return nil, nil
}

// UpsertCorpConfig mirrors CorporationBuildProgramService::enable.
func (s *SQL) UpsertCorpConfig(ctx context.Context, corpID int64, corpName string, characterID, userID int64) (*CorpConfigFull, error) {
	_, err := s.db.ExecContext(ctx, `
		INSERT INTO corporation_build_program_configs
			(corporation_id, corporation_name, character_id, enabled_by_user_id, is_active, created_at, updated_at)
		VALUES (?, ?, ?, ?, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
		ON CONFLICT(corporation_id) DO UPDATE SET corporation_name = excluded.corporation_name, character_id = excluded.character_id,
			enabled_by_user_id = excluded.enabled_by_user_id, is_active = 1, updated_at = CURRENT_TIMESTAMP`,
		corpID, corpName, characterID, userID)
	if err != nil {
		return nil, err
	}
	var out CorpConfigFull
	err = s.db.GetContext(ctx, &out, `SELECT `+corpConfigFullCols+`
		FROM corporation_build_program_configs WHERE corporation_id = ?`, corpID)
	if err != nil {
		return nil, err
	}
	return &out, nil
}

// DeactivateCorpConfig mirrors CorporationBuildProgramService::disable:
// the row stays (programs are kept) but goes inactive.
func (s *SQL) DeactivateCorpConfig(ctx context.Context, corpID int64) error {
	_, err := s.db.ExecContext(ctx, `UPDATE corporation_build_program_configs
		SET is_active = 0, updated_at = CURRENT_TIMESTAMP WHERE corporation_id = ?`, corpID)
	return err
}
