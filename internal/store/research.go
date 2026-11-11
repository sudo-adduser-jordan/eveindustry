package store

import (
	"context"
)

type ResearchStore interface {
	T1Blueprints(ctx context.Context, search string, marketGroupID int) ([]ResearchBlueprint, error)
	ReactionFormulas(ctx context.Context, search string, groupID int) ([]ResearchFormula, error)
	BlueprintMarketGroups(ctx context.Context) ([]GroupName, error)
}

type ResearchBlueprint struct {
	TypeID          int     `db:"type_id"`
	Name            string  `db:"name"`
	MarketGroupID   *int    `db:"market_group_id"`
	MarketGroupName *string `db:"market_group_name"`
}

type ResearchFormula struct {
	TypeID    int     `db:"type_id"`
	Name      string  `db:"name"`
	GroupID   *int    `db:"group_id"`
	GroupName *string `db:"group_name"`
}

type GroupName struct {
	ID   int    `db:"id"`
	Name string `db:"name"`
}

func (s *SQL) T1Blueprints(ctx context.Context, search string, marketGroupID int) ([]ResearchBlueprint, error) {
	q := `SELECT t.type_id, t.name, t.market_group_id, g.name AS market_group_name
		FROM eve_types t
		JOIN blueprint_activities b ON t.type_id = b.blueprint_type_id
		LEFT JOIN market_groups g ON t.market_group_id = g.market_group_id
		WHERE b.activity_id = 1 AND t.meta_group_id IS NULL AND t.published = 1`
	args := []any{}
	if search != "" {
		q += ` AND t.name LIKE ?`
		args = append(args, "%"+search+"%")
	}
	if marketGroupID > 0 {
		q += ` AND t.market_group_id = ?`
		args = append(args, marketGroupID)
	}
	q += ` ORDER BY t.name`
	var out []ResearchBlueprint
	if err := s.db.SelectContext(ctx, &out, q, args...); err != nil {
		return nil, err
	}
	return out, nil
}

func (s *SQL) ReactionFormulas(ctx context.Context, search string, groupID int) ([]ResearchFormula, error) {
	q := `SELECT t.type_id, t.name, t.group_id, g.name AS group_name
		FROM eve_types t
		JOIN blueprint_activities b ON t.type_id = b.blueprint_type_id
		LEFT JOIN inv_groups g ON t.group_id = g.group_id
		WHERE b.activity_id = 11 AND t.published = 1`
	args := []any{}
	if search != "" {
		q += ` AND t.name LIKE ?`
		args = append(args, "%"+search+"%")
	}
	if groupID > 0 {
		q += ` AND t.group_id = ?`
		args = append(args, groupID)
	}
	q += ` ORDER BY t.name`
	var out []ResearchFormula
	if err := s.db.SelectContext(ctx, &out, q, args...); err != nil {
		return nil, err
	}
	return out, nil
}

func (s *SQL) BlueprintMarketGroups(ctx context.Context) ([]GroupName, error) {
	var out []GroupName
	err := s.db.SelectContext(ctx, &out, `
		SELECT DISTINCT g.market_group_id AS id, g.name AS name
		FROM market_groups g
		JOIN eve_types t ON t.market_group_id = g.market_group_id
		JOIN blueprint_activities b ON b.blueprint_type_id = t.type_id
		WHERE t.meta_group_id = 1 AND t.published = 1 AND b.activity_id = 1
		ORDER BY g.name`)
	return out, err
}
