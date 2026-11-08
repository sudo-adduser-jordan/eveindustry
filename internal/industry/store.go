package industry

import (
	"context"
	"database/sql"
	"encoding/json"

	"github.com/jmoiron/sqlx"
)

// BlueprintActivityRow mirrors the blueprint_activities table: materials,
// products and skills are raw JSON payloads decoded by this package.
type BlueprintActivityRow struct {
	BlueprintTypeID int            `db:"blueprint_type_id"`
	ActivityID      int            `db:"activity_id"`
	Time            int            `db:"time"`
	Materials       sql.NullString `db:"materials"`
	Products        sql.NullString `db:"products"`
	Skills          sql.NullString `db:"skills"`
}

// ReactionFormula is one activity-11 row with its SDE formula name.
type ReactionFormula struct {
	BlueprintTypeID int
	FormulaName     string
	GroupID         *int
	Materials       []map[string]any
	Products        []map[string]any
	Time            int
}

// AllReactionFormulas ports getAllReactions()' first half: every activity-11
// row with products, joined to eve_types for the formula name/group.
func (s *SQL) AllReactionFormulas(ctx context.Context) ([]ReactionFormula, error) {
	var rows []struct {
		BlueprintTypeID int            `db:"blueprint_type_id"`
		Materials       sql.NullString `db:"materials"`
		Products        sql.NullString `db:"products"`
		Time            int            `db:"time"`
		Name            sql.NullString `db:"name"`
		GroupID         sql.NullInt64  `db:"group_id"`
	}
	err := s.db.SelectContext(ctx, &rows, `
		SELECT b.blueprint_type_id, b.materials, b.products, b.time, t.name, t.group_id
		FROM blueprint_activities b
		LEFT JOIN eve_types t ON t.type_id = b.blueprint_type_id
		WHERE b.activity_id = 11 AND b.products IS NOT NULL
		ORDER BY t.name`)
	if err != nil {
		return nil, err
	}
	out := make([]ReactionFormula, 0, len(rows))
	for _, r := range rows {
		mats := DecodeJSONList(nullString(r.Materials))
		prods := DecodeJSONList(nullString(r.Products))
		if len(prods) == 0 {
			continue
		}
		name := "Unknown Formula"
		if r.Name.Valid && r.Name.String != "" {
			name = r.Name.String
		}
		var gid *int
		if r.GroupID.Valid {
			v := int(r.GroupID.Int64)
			gid = &v
		}
		out = append(out, ReactionFormula{
			BlueprintTypeID: r.BlueprintTypeID, FormulaName: name, GroupID: gid,
			Materials: mats, Products: prods, Time: r.Time,
		})
	}
	return out, nil
}
// IndustryStore covers the blueprint/invention/category reference reads.
type IndustryStore interface {
	SearchBlueprintTypes(ctx context.Context, like string, limit int) ([]BlueprintTypeHit, error)
	SearchReactionTypes(ctx context.Context, like string, limit int) ([]BlueprintTypeHit, error)
	SearchT2Candidates(ctx context.Context, like string) ([]BlueprintTypeHit, error)
	ActivityByBlueprint(ctx context.Context, blueprintTypeID, activityID int) (*BlueprintActivityRow, error)
	ActivityProducingType(ctx context.Context, typeID, activityID int) (*BlueprintActivityRow, error)
	HasActivity(ctx context.Context, blueprintTypeID, activityID int) (bool, error)
	TypeNames(ctx context.Context, typeIDs []int) (map[int]string, error)
	AllReactionFormulas(ctx context.Context) ([]ReactionFormula, error)
	ProductTypeIDsExcludingInvented(ctx context.Context) ([]int, error)
	ProductGroupCounts(ctx context.Context, typeIDs []int) (map[int]int, error)
	GroupCategories(ctx context.Context, groupIDs []int) (map[int]int, error)
	CategoryNames(ctx context.Context, categoryIDs []int) (map[int]string, error)
}

// BlueprintTypeHit is one eve_types row for search results.
type BlueprintTypeHit struct {
	TypeID  int           `db:"type_id"`
	Name    string        `db:"name"`
	GroupID sql.NullInt64 `db:"group_id"`
}

// SQL is the sqlx implementation of IndustryStore.
type SQL struct {
	db *sqlx.DB
}

// NewSQL wraps an open sqlx DB.
func NewSQL(db *sqlx.DB) *SQL { return &SQL{db: db} }

func (s *SQL) SearchBlueprintTypes(ctx context.Context, like string, limit int) ([]BlueprintTypeHit, error) {
	var out []BlueprintTypeHit
	err := s.db.SelectContext(ctx, &out,
		`SELECT type_id, name, group_id FROM eve_types
		 WHERE name LIKE ? AND name LIKE '%Blueprint%' AND published = 1
		 ORDER BY name LIMIT ?`, like, limit)
	return out, err
}

func (s *SQL) SearchReactionTypes(ctx context.Context, like string, limit int) ([]BlueprintTypeHit, error) {
	var out []BlueprintTypeHit
	err := s.db.SelectContext(ctx, &out,
		`SELECT type_id, name, group_id FROM eve_types
		 WHERE name LIKE ? AND name LIKE '%Reaction Formula%' AND published = 1
		 ORDER BY name LIMIT ?`, like, limit)
	return out, err
}

func (s *SQL) SearchT2Candidates(ctx context.Context, like string) ([]BlueprintTypeHit, error) {
	var out []BlueprintTypeHit
	err := s.db.SelectContext(ctx, &out,
		`SELECT type_id, name, group_id FROM eve_types
		 WHERE name LIKE ? AND published = 1 AND meta_group_id = 2
		   AND market_group_id IS NOT NULL
		   AND name NOT LIKE '%Blueprint%' AND name NOT LIKE '%Reaction Formula%'
		 ORDER BY name`, like)
	return out, err
}

func (s *SQL) ActivityByBlueprint(ctx context.Context, blueprintTypeID, activityID int) (*BlueprintActivityRow, error) {
	var row BlueprintActivityRow
	err := s.db.GetContext(ctx, &row,
		`SELECT blueprint_type_id, activity_id, time, materials, products, skills
		 FROM blueprint_activities WHERE blueprint_type_id = ? AND activity_id = ?`,
		blueprintTypeID, activityID)
	if err == sql.ErrNoRows {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	return &row, nil
}

// ActivityProducingType ports BlueprintActivity::scopeWhereProducesType() on
// MySQL: JSON_CONTAINS over the products array.
func (s *SQL) ActivityProducingType(ctx context.Context, typeID, activityID int) (*BlueprintActivityRow, error) {
	doc, _ := json.Marshal([]map[string]int{{"type_id": typeID}})
	var row BlueprintActivityRow
	err := s.db.GetContext(ctx, &row,
		`SELECT blueprint_type_id, activity_id, time, materials, products, skills
		 FROM blueprint_activities
		 WHERE activity_id = ? AND products IS NOT NULL AND JSON_CONTAINS(products, CAST(? AS JSON))
		 LIMIT 1`, activityID, string(doc))
	if err == sql.ErrNoRows {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	return &row, nil
}

func (s *SQL) HasActivity(ctx context.Context, blueprintTypeID, activityID int) (bool, error) {
	var n int
	err := s.db.GetContext(ctx, &n,
		`SELECT COUNT(*) FROM blueprint_activities WHERE blueprint_type_id = ? AND activity_id = ?`,
		blueprintTypeID, activityID)
	return n > 0, err
}

func (s *SQL) TypeNames(ctx context.Context, typeIDs []int) (map[int]string, error) {
	out := map[int]string{}
	if len(typeIDs) == 0 {
		return out, nil
	}
	q, args, err := sqlx.In(`SELECT type_id, name FROM eve_types WHERE type_id IN (?)`, typeIDs)
	if err != nil {
		return nil, err
	}
	var rows []BlueprintTypeHit
	if err := s.db.SelectContext(ctx, &rows, s.db.Rebind(q), args...); err != nil {
		return nil, err
	}
	for _, r := range rows {
		out[r.TypeID] = r.Name
	}
	return out, nil
}

// ProductTypeIDsExcludingInvented ports the first half of
// getAvailableProductCategories(): product type ids from T1 (non-invented)
// manufacturing blueprints. Invented T2 blueprints are those with an
// invention activity producing them.
func (s *SQL) ProductTypeIDsExcludingInvented(ctx context.Context) ([]int, error) {
	// Invented T2 blueprints: invention activities' product type ids.
	var invRows []struct {
		Products sql.NullString `db:"products"`
	}
	if err := s.db.SelectContext(ctx, &invRows,
		`SELECT products FROM blueprint_activities WHERE activity_id = 8 AND products IS NOT NULL`); err != nil {
		return nil, err
	}
	invented := map[int]bool{}
	for _, r := range invRows {
		if !r.Products.Valid {
			continue
		}
		for _, p := range DecodeJSONList(&r.Products.String) {
			if id := intVal(p["type_id"], 0); id != 0 {
				invented[id] = true
			}
		}
	}
	// Manufacturing activities whose blueprint is itself an invented product.
	var mfg []struct {
		Blueprint int            `db:"blueprint_type_id"`
		Products  sql.NullString `db:"products"`
	}
	if err := s.db.SelectContext(ctx, &mfg,
		`SELECT blueprint_type_id, products FROM blueprint_activities
		 WHERE activity_id = 1 AND products IS NOT NULL`); err != nil {
		return nil, err
	}
	seen := map[int]bool{}
	var out []int
	for _, a := range mfg {
		if invented[a.Blueprint] {
			continue
		}
		if !a.Products.Valid {
			continue
		}
		for _, p := range DecodeJSONList(&a.Products.String) {
			if id := intVal(p["type_id"], 0); id != 0 && !seen[id] {
				seen[id] = true
				out = append(out, id)
			}
		}
	}
	return out, nil
}

func (s *SQL) ProductGroupCounts(ctx context.Context, typeIDs []int) (map[int]int, error) {
	out := map[int]int{}
	if len(typeIDs) == 0 {
		return out, nil
	}
	q, args, err := sqlx.In(
		`SELECT group_id, COUNT(*) AS item_count FROM eve_types
		 WHERE type_id IN (?) AND group_id IS NOT NULL GROUP BY group_id`, typeIDs)
	if err != nil {
		return nil, err
	}
	var rows []struct {
		GroupID   int `db:"group_id"`
		ItemCount int `db:"item_count"`
	}
	if err := s.db.SelectContext(ctx, &rows, s.db.Rebind(q), args...); err != nil {
		return nil, err
	}
	for _, r := range rows {
		out[r.GroupID] = r.ItemCount
	}
	return out, nil
}

func (s *SQL) GroupCategories(ctx context.Context, groupIDs []int) (map[int]int, error) {
	out := map[int]int{}
	if len(groupIDs) == 0 {
		return out, nil
	}
	q, args, err := sqlx.In(
		`SELECT group_id, category_id FROM inv_groups WHERE group_id IN (?) AND category_id IS NOT NULL`, groupIDs)
	if err != nil {
		return nil, err
	}
	var rows []struct {
		GroupID    int `db:"group_id"`
		CategoryID int `db:"category_id"`
	}
	if err := s.db.SelectContext(ctx, &rows, s.db.Rebind(q), args...); err != nil {
		return nil, err
	}
	for _, r := range rows {
		out[r.GroupID] = r.CategoryID
	}
	return out, nil
}

func (s *SQL) CategoryNames(ctx context.Context, categoryIDs []int) (map[int]string, error) {
	out := map[int]string{}
	if len(categoryIDs) == 0 {
		return out, nil
	}
	q, args, err := sqlx.In(`SELECT category_id, name FROM inv_categories WHERE category_id IN (?)`, categoryIDs)
	if err != nil {
		return nil, err
	}
	var rows []struct {
		CategoryID int    `db:"category_id"`
		Name       string `db:"name"`
	}
	if err := s.db.SelectContext(ctx, &rows, s.db.Rebind(q), args...); err != nil {
		return nil, err
	}
	for _, r := range rows {
		out[r.CategoryID] = r.Name
	}
	return out, nil
}
