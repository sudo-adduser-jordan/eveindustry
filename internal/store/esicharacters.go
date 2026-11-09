package store

import (
	"context"
	"database/sql"
	"time"

	"github.com/sudo-adduser-jordan/eveindustry/internal/esi"
)

// ESICharacterStore is the SQL CredentialStore for ESI calls. Tokens are
// read and written raw: PHP decrypts encrypted-at-rest rows on read
// (EsiToken::encrypted) and the Go character client decrypts them the same
// way when the APP_KEY is configured, so mixed PHP/Go operation keeps
// working (PHP reads both raw and encrypted rows).
var _ esi.CredentialStore = (*SQL)(nil)

type esiCredRow struct {
	AccessToken    sql.NullString `db:"access_token"`
	RefreshToken   sql.NullString `db:"refresh_token"`
	TokenExpiresAt sql.NullTime   `db:"token_expires_at"`
	IsEsiActive    bool           `db:"is_esi_active"`
}

// Credentials loads one character's ESI credential. Absent row reports
// ok=false (callers fail closed with a credential outcome).
func (s *SQL) Credentials(ctx context.Context, characterID int64) (esi.Credential, bool, error) {
	var row esiCredRow
	err := s.db.GetContext(ctx, &row, `
		SELECT access_token, refresh_token, token_expires_at, is_esi_active
		FROM characters WHERE character_id = ?`, characterID)
	if err == sql.ErrNoRows {
		return esi.Credential{}, false, nil
	}
	if err != nil {
		return esi.Credential{}, false, err
	}
	cred := esi.Credential{CharacterID: characterID, IsEsiActive: row.IsEsiActive}
	if row.AccessToken.Valid {
		cred.AccessToken = row.AccessToken.String
	}
	if row.RefreshToken.Valid {
		cred.RefreshToken = row.RefreshToken.String
	}
	if row.TokenExpiresAt.Valid {
		cred.TokenExpiresAt = row.TokenExpiresAt.Time
	}
	return cred, true, nil
}

// SaveTokens persists a rotation (raw; never re-encrypts).
func (s *SQL) SaveTokens(ctx context.Context, characterID int64, access, refresh string, expiresAt time.Time) error {
	_, err := s.db.ExecContext(ctx, `
		UPDATE characters SET access_token = ?, refresh_token = ?, token_expires_at = ?
		WHERE character_id = ?`, access, refresh, expiresAt, characterID)
	return err
}

// Deactivate marks the character ESI-inactive (invalid_grant path).
func (s *SQL) Deactivate(ctx context.Context, characterID int64) error {
	_, err := s.db.ExecContext(ctx, `UPDATE characters SET is_esi_active = 0 WHERE character_id = ?`, characterID)
	return err
}
