package store

import (
	"context"
	"database/sql"
	"encoding/json"
)

// CorpStore covers the corporate-export availability check: active corp
// configs and the user's director/Factory_Manager standing.
type CorpStore interface {
	ActiveCorpConfig(ctx context.Context, corporationID int64) (*CorpConfig, error)
	UserCorpCharacters(ctx context.Context, userID, corporationID int64) ([]CorpCharacter, error)
}

// CorpConfig mirrors the corporation_build_program_configs row the endpoint reads.
type CorpConfig struct {
	CorporationID   int64  `db:"corporation_id"`
	CorporationName string `db:"corporation_name"`
	IsActive        bool   `db:"is_active"`
}

// CorpCharacter is one user character in a corporation with corp standing.
type CorpCharacter struct {
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
		IsDirectorCEO bool           `db:"is_director_ceo"`
		Roles         sql.NullString `db:"roles"`
	}
	err := s.db.SelectContext(ctx, &rows, `
		SELECT is_director_ceo, roles FROM characters
		WHERE user_id = ? AND corporation_id = ?`, userID, corporationID)
	if err != nil {
		return nil, err
	}
	out := make([]CorpCharacter, 0, len(rows))
	for _, r := range rows {
		cc := CorpCharacter{IsDirectorCEO: r.IsDirectorCEO}
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
