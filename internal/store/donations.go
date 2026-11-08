package store

import (
	"context"
	"database/sql"

	"github.com/jmoiron/sqlx"
)

// DonationConfig mirrors one corporate_donation_configs row.
type DonationConfig struct {
	ID              int64          `db:"id"`
	CorporationID   int64          `db:"corporation_id"`
	CorporationName sql.NullString `db:"corporation_name"`
	CharacterID     int64          `db:"character_id"`
	EnabledByUserID int64          `db:"enabled_by_user_id"`
	IsActive        bool           `db:"is_active"`
}

// DonationStore covers donation tracking configs, scoped by corporation.
type DonationStore interface {
	DonationConfigForCorps(ctx context.Context, corpIDs []int64) (*DonationConfig, error)
	UpsertDonationConfig(ctx context.Context, corpID int64, corpName string, characterID, userID int64) (*DonationConfig, error)
	DeactivateDonationConfig(ctx context.Context, corpID int64) error
}

const donationConfigCols = `id, corporation_id, corporation_name, character_id, enabled_by_user_id, is_active`

// DonationConfigForCorps returns the active config for the first matching
// corporation, else nil.
func (s *SQL) DonationConfigForCorps(ctx context.Context, corpIDs []int64) (*DonationConfig, error) {
	if len(corpIDs) == 0 {
		return nil, nil
	}
	q, args, err := sqlx.In(`SELECT `+donationConfigCols+` FROM corporate_donation_configs
		WHERE corporation_id IN (?) AND is_active = 1`, corpIDs)
	if err != nil {
		return nil, err
	}
	var rows []DonationConfig
	if err := s.db.SelectContext(ctx, &rows, s.db.Rebind(q), args...); err != nil {
		return nil, err
	}
	if len(rows) == 0 {
		return nil, nil
	}
	byCorp := make(map[int64]*DonationConfig, len(rows))
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

// UpsertDonationConfig mirrors CorporateDonationService::enable.
func (s *SQL) UpsertDonationConfig(ctx context.Context, corpID int64, corpName string, characterID, userID int64) (*DonationConfig, error) {
	_, err := s.db.ExecContext(ctx, `
		INSERT INTO corporate_donation_configs
			(corporation_id, corporation_name, character_id, enabled_by_user_id, is_active, created_at, updated_at)
		VALUES (?, ?, ?, ?, 1, NOW(), NOW())
		ON DUPLICATE KEY UPDATE corporation_name = VALUES(corporation_name), character_id = VALUES(character_id),
			enabled_by_user_id = VALUES(enabled_by_user_id), is_active = 1, updated_at = NOW()`,
		corpID, corpName, characterID, userID)
	if err != nil {
		return nil, err
	}
	var out DonationConfig
	err = s.db.GetContext(ctx, &out, `SELECT `+donationConfigCols+`
		FROM corporate_donation_configs WHERE corporation_id = ?`, corpID)
	if err != nil {
		return nil, err
	}
	return &out, nil
}

// DeactivateDonationConfig mirrors disable: the row stays but goes inactive.
func (s *SQL) DeactivateDonationConfig(ctx context.Context, corpID int64) error {
	_, err := s.db.ExecContext(ctx, `UPDATE corporate_donation_configs
		SET is_active = 0, updated_at = NOW() WHERE corporation_id = ?`, corpID)
	return err
}
