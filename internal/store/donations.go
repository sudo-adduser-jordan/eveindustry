package store

import (
	"context"
	"database/sql"

	"github.com/jmoiron/sqlx"
)

type DonationConfig struct {
	ID              int64          `db:"id"`
	CorporationID   int64          `db:"corporation_id"`
	CorporationName sql.NullString `db:"corporation_name"`
	CharacterID     int64          `db:"character_id"`
	EnabledByUserID int64          `db:"enabled_by_user_id"`
	IsActive        bool           `db:"is_active"`
}

type DonationStore interface {
	DonationConfigForCorps(ctx context.Context, corpIDs []int64) (*DonationConfig, error)
	UpsertDonationConfig(ctx context.Context, corpID int64, corpName string, characterID, userID int64) (*DonationConfig, error)
	DeactivateDonationConfig(ctx context.Context, corpID int64) error
}

const donationConfigCols = `id, corporation_id, corporation_name, character_id, enabled_by_user_id, is_active`

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

func (s *SQL) UpsertDonationConfig(ctx context.Context, corpID int64, corpName string, characterID, userID int64) (*DonationConfig, error) {
	_, err := s.db.ExecContext(ctx, `
		INSERT INTO corporate_donation_configs
			(corporation_id, corporation_name, character_id, enabled_by_user_id, is_active, created_at, updated_at)
		VALUES (?, ?, ?, ?, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
		ON CONFLICT(corporation_id) DO UPDATE SET corporation_name = excluded.corporation_name, character_id = excluded.character_id,
			enabled_by_user_id = excluded.enabled_by_user_id, is_active = 1, updated_at = CURRENT_TIMESTAMP`,
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

func (s *SQL) DeactivateDonationConfig(ctx context.Context, corpID int64) error {
	_, err := s.db.ExecContext(ctx, `UPDATE corporate_donation_configs
		SET is_active = 0, updated_at = CURRENT_TIMESTAMP WHERE corporation_id = ?`, corpID)
	return err
}
