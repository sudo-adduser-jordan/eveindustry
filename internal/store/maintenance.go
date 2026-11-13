package store

import (
	"context"
	"database/sql"
	"encoding/json"
	"time"

	"github.com/sudo-adduser-jordan/eveindustry/internal/esi"
)

type DemoUser struct {
	ID   int64
	Name string
}

func (s *SQL) EnsureDemoUser(ctx context.Context, email string) (int64, error) {
	var id int64
	err := s.db.GetContext(ctx, &id, `SELECT id FROM users WHERE email = ?`, email)
	if err == nil {
		return id, nil
	}
	if err != sql.ErrNoRows {
		return 0, err
	}
	res, err := s.db.ExecContext(ctx, `INSERT INTO users (name, email, is_active, is_stock_manager_enabled, is_demo, last_login_at, created_at, updated_at)
		VALUES ('Demo Account', ?, 1, 1, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)`, email)
	if err != nil {
		return 0, err
	}
	return res.LastInsertId()
}

func (s *SQL) TouchDemoUser(ctx context.Context, id int64) error {
	scopes, _ := json.Marshal(esi.DemoScopes)
	_, err := s.db.ExecContext(ctx, `UPDATE characters SET scopes = ? WHERE user_id = ?`, string(scopes), id)
	if err != nil {
		return err
	}
	_, err = s.db.ExecContext(ctx, `UPDATE users SET name = 'Demo Account', is_admin = 0, is_super_admin = 0,
		is_active = 1, is_stock_manager_enabled = 1, is_demo = 1, last_login_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = ?`, id)
	return err
}

func (s *SQL) WipeUserContent(ctx context.Context, userID int64) error {
	for _, q := range []string{
		`DELETE FROM station_configs WHERE user_id = ?`,
		`DELETE FROM reaction_plans WHERE user_id = ?`,
		`DELETE FROM invention_plans WHERE user_id = ?`,
		`DELETE FROM build_programs WHERE user_id = ?`,
		`DELETE FROM industry_stock_items WHERE user_id = ?`,
		`DELETE FROM characters WHERE user_id = ?`,
	} {
		if _, err := s.db.ExecContext(ctx, q, userID); err != nil {
			return err
		}
	}
	for _, q := range []string{
		`DELETE FROM corporation_build_programs WHERE corporation_id >= 99100000000 AND corporation_id < 99200000000`,
		`DELETE FROM corporation_build_program_configs WHERE corporation_id >= 99100000000 AND corporation_id < 99200000000`,
		`DELETE FROM corporate_donation_configs WHERE corporation_id >= 99100000000 AND corporation_id < 99200000000`,
		`DELETE FROM structure_vulnerability_windows WHERE corporation_id >= 99100000000 AND corporation_id < 99200000000`,
		`DELETE FROM moon_scan_results WHERE system_id IN (SELECT id FROM moon_scan_systems WHERE corporation_id >= 99100000000 AND corporation_id < 99200000000)`,
		`DELETE FROM moon_scan_systems WHERE corporation_id >= 99100000000 AND corporation_id < 99200000000`,
	} {
		if _, err := s.db.ExecContext(ctx, q); err != nil {
			return err
		}
	}
	return nil
}

func (s *SQL) MaxCharacterColumn(ctx context.Context, column string, floor, ceiling int64) (int64, error) {
	var max sql.NullInt64
	allowed := map[string]bool{"character_id": true, "corporation_id": true, "alliance_id": true}
	if !allowed[column] {
		return floor, nil
	}
	err := s.db.GetContext(ctx, &max, `SELECT MAX(`+column+`) FROM characters WHERE `+column+` >= ? AND `+column+` < ?`, floor, ceiling)
	if err != nil || !max.Valid || max.Int64 < floor {
		return floor, err
	}
	return max.Int64, nil
}

type DemoCharacter struct {
	CharacterID   int64
	UserID        int64
	Name          string
	CorporationID int64
	CorpName      string
	AllianceID    *int64
	AllianceName  *string
	IsMain        bool
	IsDirectorCEO bool
	Roles         []string
	CachedLoc     string
	CachedTrain   string
}

func (s *SQL) CreateDemoCharacter(ctx context.Context, c DemoCharacter) error {
	roles, _ := json.Marshal(c.Roles)
	scopes, _ := json.Marshal(esi.DemoScopes)
	_, err := s.db.ExecContext(ctx, `INSERT INTO characters (character_id, user_id, name, corporation_id, corporation_name,
		alliance_id, alliance_name, is_main, access_token, refresh_token, token_expires_at, scopes,
		is_esi_active, is_director_ceo, roles, cached_location, cached_training, created_at, updated_at)
		VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'demo-access-token', 'demo-refresh-token', ?, ?, 1, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)`,
		c.CharacterID, c.UserID, c.Name, c.CorporationID, c.CorpName,
		c.AllianceID, c.AllianceName, c.IsMain, time.Now().AddDate(10, 0, 0), string(scopes),
		c.IsDirectorCEO, string(roles), c.CachedLoc, c.CachedTrain)
	return err
}

func (s *SQL) ActiveSweepIDs(ctx context.Context, regionID int, keep int) ([]string, error) {
	var out []string
	err := s.db.SelectContext(ctx, &out, `SELECT sweep_id FROM market_order_sweeps
		WHERE region_id = ? AND status = 'completed' ORDER BY generation DESC LIMIT ?`, regionID, keep)
	if out == nil {
		out = []string{}
	}
	return out, err
}

func (s *SQL) SweptRegionIDs(ctx context.Context) ([]int, error) {
	var out []int
	err := s.db.SelectContext(ctx, &out, `SELECT DISTINCT region_id FROM market_order_sweeps WHERE status = 'completed'`)
	if out == nil {
		out = []int{}
	}
	return out, err
}

func (s *SQL) PrunableSweeps(ctx context.Context, regionID int, cutoff time.Time, exclude []string) ([]string, error) {
	q := `SELECT sweep_id FROM market_order_sweeps
		WHERE region_id = ? AND status = 'completed' AND finished_at < ?`
	args := []any{regionID, cutoff}
	if len(exclude) > 0 {
		q += ` AND sweep_id NOT IN (?)`
		strs := make([]any, 0, len(exclude))
		for _, e := range exclude {
			strs = append(strs, e)
		}
		inq, inargs, err := sqlxIn(q, append(args, strs...))
		if err != nil {
			return nil, err
		}
		var out []string
		if err := s.db.SelectContext(ctx, &out, s.db.Rebind(inq), inargs...); err != nil {
			return nil, err
		}
		return out, nil
	}
	var out []string
	if err := s.db.SelectContext(ctx, &out, q, args...); err != nil {
		return nil, err
	}
	if out == nil {
		out = []string{}
	}
	return out, nil
}

func (s *SQL) DeleteSweepAggregates(ctx context.Context, regionID int, sweepID string, batch int) (int64, error) {
	var total int64
	for {
		res, err := s.db.ExecContext(ctx, `DELETE FROM market_aggregates
			WHERE rowid IN (SELECT rowid FROM market_aggregates WHERE region_id = ? AND sweep_id = ? LIMIT ?)`, regionID, sweepID, batch)
		if err != nil {
			return total, err
		}
		n, _ := res.RowsAffected()
		total += n
		if n < int64(batch) {
			break
		}
	}
	return total, nil
}

type ContractGenerationRow struct {
	ID     int64  `db:"id"`
	Status string `db:"status"`
}

func (s *SQL) PrunableContractGenerations(ctx context.Context, graceCutoff, failedCutoff time.Time, keep int, activeID *int64) ([]ContractGenerationRow, error) {
	var keepIDs []int64
	err := s.db.SelectContext(ctx, &keepIDs, `SELECT id FROM public_contract_generations
		WHERE status = 'published' ORDER BY id DESC LIMIT ?`, keep)
	if err != nil {
		return nil, err
	}
	keepSet := map[int64]bool{}
	for _, id := range keepIDs {
		keepSet[id] = true
	}
	if activeID != nil {
		keepSet[*activeID] = true
	}
	var rows []ContractGenerationRow
	err = s.db.SelectContext(ctx, &rows, `SELECT id, status FROM public_contract_generations
		WHERE (status = 'published' AND published_at < ?)
		   OR (status IN ('failed','superseded') AND created_at < ?)
		ORDER BY id`, graceCutoff, failedCutoff)
	if err != nil {
		return nil, err
	}
	var out []ContractGenerationRow
	for _, r := range rows {
		if r.Status == "published" && keepSet[r.ID] {
			continue
		}
		out = append(out, r)
	}
	return out, nil
}

func (s *SQL) ActiveContractGeneration(ctx context.Context) (*int64, error) {
	var id sql.NullInt64
	err := s.db.GetContext(ctx, &id, `SELECT active_generation_id FROM public_contract_snapshot_state WHERE id = 1`)
	if err == sql.ErrNoRows || !id.Valid {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	v := id.Int64
	return &v, nil
}

func (s *SQL) DeleteContractGenerationItems(ctx context.Context, generationID int64, batch int) (int64, error) {
	var total int64
	for {
		res, err := s.db.ExecContext(ctx, `DELETE FROM public_contract_snapshot_items
			WHERE rowid IN (SELECT rowid FROM public_contract_snapshot_items WHERE generation_id = ? LIMIT ?)`, generationID, batch)
		if err != nil {
			return total, err
		}
		n, _ := res.RowsAffected()
		total += n
		if n < int64(batch) {
			break
		}
	}
	return total, nil
}

func (s *SQL) DeleteContractGenerationContracts(ctx context.Context, generationID int64, batch int) (int64, error) {
	var total int64
	for {
		res, err := s.db.ExecContext(ctx, `DELETE FROM public_contract_snapshot_contracts
			WHERE rowid IN (SELECT rowid FROM public_contract_snapshot_contracts WHERE generation_id = ? LIMIT ?)`, generationID, batch)
		if err != nil {
			return total, err
		}
		n, _ := res.RowsAffected()
		total += n
		if n < int64(batch) {
			break
		}
	}
	return total, nil
}

func (s *SQL) DeleteContractGeneration(ctx context.Context, generationID int64) (bool, error) {
	active, err := s.ActiveContractGeneration(ctx)
	if err != nil {
		return false, err
	}
	if active != nil && *active == generationID {
		return false, nil
	}
	res, err := s.db.ExecContext(ctx, `DELETE FROM public_contract_generations WHERE id = ?`, generationID)
	if err != nil {
		return false, err
	}
	n, _ := res.RowsAffected()
	return n > 0, nil
}
