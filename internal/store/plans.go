package store

import (
	"context"
	"database/sql"
	"time"
)

// InventionPlan mirrors one invention_plans row.
type InventionPlan struct {
	ID              int64     `db:"id"`
	UserID          int64     `db:"user_id"`
	Name            string    `db:"name"`
	T2TypeID        int       `db:"t2_type_id"`
	T2TypeName      string    `db:"t2_type_name"`
	DecryptorKey    string    `db:"decryptor_key"`
	T1ME            int       `db:"t1_me"`
	T1TE            int       `db:"t1_te"`
	EncryptionSkill int       `db:"encryption_skill"`
	Science1Skill   int       `db:"science1_skill"`
	Science2Skill   int       `db:"science2_skill"`
	InventionRuns   int       `db:"invention_runs"`
	StructureType   *string   `db:"structure_type"`
	RigME           *string   `db:"rig_me"`
	RigTE           *string   `db:"rig_te"`
	SystemSecurity  *string   `db:"system_security"`
	FacilityTax     float64   `db:"facility_tax"`
	SolarSystemID   *int64    `db:"solar_system_id"`
	StationID       *int64    `db:"station_id"`
	PriceRegionID   *int      `db:"price_region_id"`
	CreatedAt       time.Time `db:"created_at"`
	UpdatedAt       time.Time `db:"updated_at"`
}

// ReactionPlan mirrors one reaction_plans row (entries are raw JSON).
type ReactionPlan struct {
	ID                 int64     `db:"id"`
	UserID             int64     `db:"user_id"`
	Name               string    `db:"name"`
	EntriesJSON        string    `db:"entries"`
	DefaultRunsPerSlot int       `db:"default_runs_per_slot"`
	RigBonus           float64   `db:"rig_bonus"`
	StructureType      string    `db:"structure_type"`
	RigTier            string    `db:"rig_tier"`
	SecuritySpace      string    `db:"security_space"`
	FacilityTax        float64   `db:"facility_tax"`
	StationID          *int64    `db:"station_id"`
	SolarSystemID      *int      `db:"solar_system_id"`
	PriceRegionID      *int      `db:"price_region_id"`
	CreatedAt          time.Time `db:"created_at"`
	UpdatedAt          time.Time `db:"updated_at"`
}

// PlanStore covers saved planner setups. Reads are owner-scoped (nil when
// missing or another user's, matching the hidden-as-404 policies).
type PlanStore interface {
	InventionPlans(ctx context.Context, userID int64, limit, offset int) ([]InventionPlan, int, error)
	InventionPlan(ctx context.Context, id, userID int64) (*InventionPlan, error)
	CreateInventionPlan(ctx context.Context, p InventionPlan) (*InventionPlan, error)
	UpdateInventionPlan(ctx context.Context, p InventionPlan) (*InventionPlan, error)
	DeleteInventionPlan(ctx context.Context, id, userID int64) (bool, error)
	ReactionPlans(ctx context.Context, userID int64, limit, offset int) ([]ReactionPlan, int, error)
	ReactionPlan(ctx context.Context, id, userID int64) (*ReactionPlan, error)
	CreateReactionPlan(ctx context.Context, p ReactionPlan) (*ReactionPlan, error)
	UpdateReactionPlan(ctx context.Context, p ReactionPlan) (*ReactionPlan, error)
	DeleteReactionPlan(ctx context.Context, id, userID int64) (bool, error)
}

const inventionPlanCols = `id, user_id, name, t2_type_id, t2_type_name, decryptor_key,
	t1_me, t1_te, encryption_skill, science1_skill, science2_skill, invention_runs,
	structure_type, rig_me, rig_te, system_security, facility_tax,
	solar_system_id, station_id, price_region_id, created_at, updated_at`

const reactionPlanCols = `id, user_id, name, entries, default_runs_per_slot, rig_bonus,
	structure_type, rig_tier, security_space, facility_tax,
	station_id, solar_system_id, price_region_id, created_at, updated_at`

func (s *SQL) InventionPlans(ctx context.Context, userID int64, limit, offset int) ([]InventionPlan, int, error) {
	var total int
	if err := s.db.GetContext(ctx, &total, `SELECT COUNT(*) FROM invention_plans WHERE user_id = ?`, userID); err != nil {
		return nil, 0, err
	}
	var out []InventionPlan
	err := s.db.SelectContext(ctx, &out, `SELECT `+inventionPlanCols+`
		FROM invention_plans WHERE user_id = ? ORDER BY name LIMIT ? OFFSET ?`, userID, limit, offset)
	if out == nil {
		out = []InventionPlan{}
	}
	return out, total, err
}

func (s *SQL) InventionPlan(ctx context.Context, id, userID int64) (*InventionPlan, error) {
	var out InventionPlan
	err := s.db.GetContext(ctx, &out, `SELECT `+inventionPlanCols+` FROM invention_plans WHERE id = ? AND user_id = ?`, id, userID)
	if err == sql.ErrNoRows {
		return nil, nil
	}
	return &out, err
}

func (s *SQL) CreateInventionPlan(ctx context.Context, p InventionPlan) (*InventionPlan, error) {
	res, err := s.db.ExecContext(ctx, `
		INSERT INTO invention_plans
			(user_id, name, t2_type_id, t2_type_name, decryptor_key, t1_me, t1_te,
			 encryption_skill, science1_skill, science2_skill, invention_runs,
			 structure_type, rig_me, rig_te, system_security, facility_tax,
			 solar_system_id, station_id, price_region_id, created_at, updated_at)
		VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())`,
		p.UserID, p.Name, p.T2TypeID, p.T2TypeName, p.DecryptorKey, p.T1ME, p.T1TE,
		p.EncryptionSkill, p.Science1Skill, p.Science2Skill, p.InventionRuns,
		p.StructureType, p.RigME, p.RigTE, p.SystemSecurity, p.FacilityTax,
		p.SolarSystemID, p.StationID, p.PriceRegionID)
	if err != nil {
		return nil, err
	}
	id, err := res.LastInsertId()
	if err != nil {
		return nil, err
	}
	return s.InventionPlan(ctx, id, p.UserID)
}

func (s *SQL) UpdateInventionPlan(ctx context.Context, p InventionPlan) (*InventionPlan, error) {
	_, err := s.db.ExecContext(ctx, `
		UPDATE invention_plans SET name = ?, t2_type_id = ?, t2_type_name = ?, decryptor_key = ?,
			t1_me = ?, t1_te = ?, encryption_skill = ?, science1_skill = ?, science2_skill = ?,
			invention_runs = ?, structure_type = ?, rig_me = ?, rig_te = ?, system_security = ?,
			facility_tax = ?, solar_system_id = ?, station_id = ?, price_region_id = ?, updated_at = NOW()
		WHERE id = ? AND user_id = ?`,
		p.Name, p.T2TypeID, p.T2TypeName, p.DecryptorKey, p.T1ME, p.T1TE,
		p.EncryptionSkill, p.Science1Skill, p.Science2Skill, p.InventionRuns,
		p.StructureType, p.RigME, p.RigTE, p.SystemSecurity, p.FacilityTax,
		p.SolarSystemID, p.StationID, p.PriceRegionID, p.ID, p.UserID)
	if err != nil {
		return nil, err
	}
	return s.InventionPlan(ctx, p.ID, p.UserID)
}

func (s *SQL) DeleteInventionPlan(ctx context.Context, id, userID int64) (bool, error) {
	res, err := s.db.ExecContext(ctx, `DELETE FROM invention_plans WHERE id = ? AND user_id = ?`, id, userID)
	if err != nil {
		return false, err
	}
	n, err := res.RowsAffected()
	return n > 0, err
}

func (s *SQL) ReactionPlans(ctx context.Context, userID int64, limit, offset int) ([]ReactionPlan, int, error) {
	var total int
	if err := s.db.GetContext(ctx, &total, `SELECT COUNT(*) FROM reaction_plans WHERE user_id = ?`, userID); err != nil {
		return nil, 0, err
	}
	var out []ReactionPlan
	err := s.db.SelectContext(ctx, &out, `SELECT `+reactionPlanCols+`
		FROM reaction_plans WHERE user_id = ? ORDER BY name LIMIT ? OFFSET ?`, userID, limit, offset)
	if out == nil {
		out = []ReactionPlan{}
	}
	return out, total, err
}

func (s *SQL) ReactionPlan(ctx context.Context, id, userID int64) (*ReactionPlan, error) {
	var out ReactionPlan
	err := s.db.GetContext(ctx, &out, `SELECT `+reactionPlanCols+` FROM reaction_plans WHERE id = ? AND user_id = ?`, id, userID)
	if err == sql.ErrNoRows {
		return nil, nil
	}
	return &out, err
}

func (s *SQL) CreateReactionPlan(ctx context.Context, p ReactionPlan) (*ReactionPlan, error) {
	res, err := s.db.ExecContext(ctx, `
		INSERT INTO reaction_plans
			(user_id, name, entries, default_runs_per_slot, rig_bonus, structure_type,
			 rig_tier, security_space, facility_tax, station_id, solar_system_id,
			 price_region_id, created_at, updated_at)
		VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())`,
		p.UserID, p.Name, p.EntriesJSON, p.DefaultRunsPerSlot, p.RigBonus, p.StructureType,
		p.RigTier, p.SecuritySpace, p.FacilityTax, p.StationID, p.SolarSystemID, p.PriceRegionID)
	if err != nil {
		return nil, err
	}
	id, err := res.LastInsertId()
	if err != nil {
		return nil, err
	}
	return s.ReactionPlan(ctx, id, p.UserID)
}

func (s *SQL) UpdateReactionPlan(ctx context.Context, p ReactionPlan) (*ReactionPlan, error) {
	_, err := s.db.ExecContext(ctx, `
		UPDATE reaction_plans SET name = ?, entries = ?, default_runs_per_slot = ?, rig_bonus = ?,
			structure_type = ?, rig_tier = ?, security_space = ?, facility_tax = ?,
			station_id = ?, solar_system_id = ?, price_region_id = ?, updated_at = NOW()
		WHERE id = ? AND user_id = ?`,
		p.Name, p.EntriesJSON, p.DefaultRunsPerSlot, p.RigBonus, p.StructureType,
		p.RigTier, p.SecuritySpace, p.FacilityTax, p.StationID, p.SolarSystemID,
		p.PriceRegionID, p.ID, p.UserID)
	if err != nil {
		return nil, err
	}
	return s.ReactionPlan(ctx, p.ID, p.UserID)
}

func (s *SQL) DeleteReactionPlan(ctx context.Context, id, userID int64) (bool, error) {
	res, err := s.db.ExecContext(ctx, `DELETE FROM reaction_plans WHERE id = ? AND user_id = ?`, id, userID)
	if err != nil {
		return false, err
	}
	n, err := res.RowsAffected()
	return n > 0, err
}
