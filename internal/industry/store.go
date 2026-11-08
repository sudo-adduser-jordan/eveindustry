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

// MfgActivity is one activity-1 row with products for the browser.
type MfgActivity struct {
	BlueprintTypeID int
	Materials       []map[string]any
	Products        []map[string]any
	Time            int
}

// AllManufacturingActivities ports the activity fetch of
// getAllManufacturingBlueprints / getManufacturingBlueprintsForMarketGroup:
// every activity-1 row with products (invented-BPO exclusion happens in
// calc over InventedBlueprintIDs()).
func (s *SQL) AllManufacturingActivities(ctx context.Context) ([]MfgActivity, error) {
	var rows []struct {
		BlueprintTypeID int            `db:"blueprint_type_id"`
		Materials       sql.NullString `db:"materials"`
		Products        sql.NullString `db:"products"`
		Time            int            `db:"time"`
	}
	err := s.db.SelectContext(ctx, &rows, `
		SELECT blueprint_type_id, materials, products, time
		FROM blueprint_activities WHERE activity_id = 1 AND products IS NOT NULL`)
	if err != nil {
		return nil, err
	}
	out := make([]MfgActivity, 0, len(rows))
	for _, r := range rows {
		out = append(out, MfgActivity{
			BlueprintTypeID: r.BlueprintTypeID,
			Materials:       DecodeJSONList(nullString(r.Materials)),
			Products:        DecodeJSONList(nullString(r.Products)),
			Time:            r.Time,
		})
	}
	return out, nil
}

// InventedBlueprintIDs ports getInventedBlueprintTypeIds(): blueprint type
// ids produced by invention activities (T2/T3 BPOs don't exist as originals).
func (s *SQL) InventedBlueprintIDs(ctx context.Context) (map[int]bool, error) {
	var rows []struct {
		Products sql.NullString `db:"products"`
	}
	err := s.db.SelectContext(ctx, &rows, `
		SELECT products FROM blueprint_activities WHERE activity_id = 8 AND products IS NOT NULL`)
	if err != nil {
		return nil, err
	}
	out := map[int]bool{}
	for _, r := range rows {
		if !r.Products.Valid {
			continue
		}
		for _, p := range DecodeJSONList(nullString(r.Products)) {
			if id := intVal(p["type_id"], 0); id != 0 {
				out[id] = true
			}
		}
	}
	return out, nil
}

// MarketGroupDescendants ports collectDescendantGroupIds(): breadth-first
// walk, one query per tree level.
func (s *SQL) MarketGroupDescendants(ctx context.Context, parentID int) ([]int, error) {
	seen := map[int]bool{parentID: true}
	var all []int
	level := []int{parentID}
	for len(level) > 0 {
		q, args, err := sqlx.In(`SELECT market_group_id FROM market_groups WHERE parent_group_id IN (?)`, level)
		if err != nil {
			return nil, err
		}
		var children []int
		if err := s.db.SelectContext(ctx, &children, s.db.Rebind(q), args...); err != nil {
			return nil, err
		}
		level = nil
		for _, c := range children {
			if seen[c] {
				continue
			}
			seen[c] = true
			all = append(all, c)
			level = append(level, c)
		}
	}
	return all, nil
}

// PublishedTypesInGroups ports collectMarketGroupTypeIds().
func (s *SQL) PublishedTypesInGroups(ctx context.Context, groupIDs []int) ([]int, error) {
	if len(groupIDs) == 0 {
		return nil, nil
	}
	q, args, err := sqlx.In(`SELECT type_id FROM eve_types WHERE market_group_id IN (?) AND published = 1`, groupIDs)
	if err != nil {
		return nil, err
	}
	var out []int
	err = s.db.SelectContext(ctx, &out, s.db.Rebind(q), args...)
	return out, err
}

// TypeGroupMeta returns group_id + meta_group_id per type for the browser
// exclusion and category filters.
type TypeGroupMeta struct {
	TypeID      int           `db:"type_id"`
	GroupID     sql.NullInt64 `db:"group_id"`
	MetaGroupID sql.NullInt64 `db:"meta_group_id"`
}

func (s *SQL) TypeGroupMeta(ctx context.Context, typeIDs []int) ([]TypeGroupMeta, error) {
	if len(typeIDs) == 0 {
		return nil, nil
	}
	q, args, err := sqlx.In(`SELECT type_id, group_id, meta_group_id FROM eve_types WHERE type_id IN (?)`, typeIDs)
	if err != nil {
		return nil, err
	}
	var out []TypeGroupMeta
	err = s.db.SelectContext(ctx, &out, s.db.Rebind(q), args...)
	return out, err
}

// GroupsInCategory resolves inv_groups for a category (category filter).
func (s *SQL) GroupsInCategory(ctx context.Context, categoryID int) ([]int, error) {
	var out []int
	err := s.db.SelectContext(ctx, &out, `SELECT group_id FROM inv_groups WHERE category_id = ?`, categoryID)
	return out, err
}

// PrecomputedCost mirrors one manufacturing_costs row.
type PrecomputedCost struct {
	ProductTypeID           int     `db:"product_type_id"`
	BlueprintTypeID         int     `db:"blueprint_type_id"`
	ProductName             string  `db:"product_name"`
	BlueprintName           string  `db:"blueprint_name"`
	PortionSize             int     `db:"portion_size"`
	InputCost               float64 `db:"input_cost"`
	OutputValue             float64 `db:"output_value"`
	BaseProfit              float64 `db:"base_profit"`
	ProfitMargin            float64 `db:"profit_margin"`
	SellScore               int     `db:"sell_score"`
	SellScoreAvgDailyVolume float64 `db:"sell_score_avg_daily_volume"`
	SellScoreAvgOrderCount  float64 `db:"sell_score_avg_order_count"`
	SellScoreActiveDays     int     `db:"sell_score_active_days"`
	SellScoreTotalDays      int     `db:"sell_score_total_days"`
}

// PrecomputedCosts ports browsePrecomputed()'s query: costs for the group's
// products in one region/ME, ordered by base profit desc, paginated in SQL.
func (s *SQL) PrecomputedCosts(ctx context.Context, productIDs []int, regionID, meLevel, limit, offset int) ([]PrecomputedCost, int, error) {
	if len(productIDs) == 0 {
		return nil, 0, nil
	}
	q, args, err := sqlx.In(`SELECT COUNT(*) FROM manufacturing_costs
		WHERE product_type_id IN (?) AND region_id = ? AND me_level = ?`, productIDs, regionID, meLevel)
	if err != nil {
		return nil, 0, err
	}
	var total int
	if err := s.db.GetContext(ctx, &total, s.db.Rebind(q), args...); err != nil {
		return nil, 0, err
	}
	q2, args2, err := sqlx.In(`SELECT product_type_id, blueprint_type_id, product_name, blueprint_name,
		portion_size, input_cost, output_value, base_profit, profit_margin,
		sell_score, sell_score_avg_daily_volume, sell_score_avg_order_count,
		sell_score_active_days, sell_score_total_days
		FROM manufacturing_costs
		WHERE product_type_id IN (?) AND region_id = ? AND me_level = ?
		ORDER BY base_profit DESC LIMIT ? OFFSET ?`, productIDs, regionID, meLevel, limit, offset)
	if err != nil {
		return nil, 0, err
	}
	var out []PrecomputedCost
	if err := s.db.SelectContext(ctx, &out, s.db.Rebind(q2), args2...); err != nil {
		return nil, 0, err
	}
	return out, total, nil
}

// IndustryStore covers the blueprint/invention/category reference reads.
type IndustryStore interface {
	SearchBlueprintTypes(ctx context.Context, like string, limit int) ([]BlueprintTypeHit, error)
	SearchReactionTypes(ctx context.Context, like string, limit int) ([]BlueprintTypeHit, error)
	SearchT2Candidates(ctx context.Context, like string) ([]BlueprintTypeHit, error)
	ActivityByBlueprint(ctx context.Context, blueprintTypeID, activityID int) (*BlueprintActivityRow, error)
	ActivityProducingType(ctx context.Context, typeID, activityID int) (*BlueprintActivityRow, error)
	ActivityProducing(ctx context.Context, typeID, activityID int) (*BlueprintActivityRow, error)
	GroupsOf(ctx context.Context, typeIDs []int) (map[int]int, error)
	HasActivity(ctx context.Context, blueprintTypeID, activityID int) (bool, error)
	TypeNames(ctx context.Context, typeIDs []int) (map[int]string, error)
	TypeVolumes(ctx context.Context, typeIDs []int) (map[int]float64, error)
	TypePortions(ctx context.Context, typeIDs []int) (map[int]int, error)
	TypeMaterials(ctx context.Context, typeIDs []int) (map[int][]ReprocessMaterial, error)
	AllReactionFormulas(ctx context.Context) ([]ReactionFormula, error)
	AllManufacturingActivities(ctx context.Context) ([]MfgActivity, error)
	InventedBlueprintIDs(ctx context.Context) (map[int]bool, error)
	MarketGroupDescendants(ctx context.Context, parentID int) ([]int, error)
	PublishedTypesInGroups(ctx context.Context, groupIDs []int) ([]int, error)
	TypeGroupMeta(ctx context.Context, typeIDs []int) ([]TypeGroupMeta, error)
	GroupsInCategory(ctx context.Context, categoryID int) ([]int, error)
	GroupNames(ctx context.Context, groupIDs []int) (map[int]string, error)
	DemoBlueprintPool(ctx context.Context) ([][2]int, error)
	PrecomputedCosts(ctx context.Context, productIDs []int, regionID, meLevel, limit, offset int) ([]PrecomputedCost, int, error)
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

// ActivityProducing aliases ActivityProducingType for calc.ChainResolver.
func (s *SQL) ActivityProducing(ctx context.Context, typeID, activityID int) (*BlueprintActivityRow, error) {
	return s.ActivityProducingType(ctx, typeID, activityID)
}

// GroupsOf returns eve_types.group_id per type for chain fuel-block checks.
func (s *SQL) GroupsOf(ctx context.Context, typeIDs []int) (map[int]int, error) {
	out := map[int]int{}
	if len(typeIDs) == 0 {
		return out, nil
	}
	q, args, err := sqlx.In(`SELECT type_id, group_id FROM eve_types WHERE type_id IN (?)`, typeIDs)
	if err != nil {
		return nil, err
	}
	var rows []struct {
		TypeID  int           `db:"type_id"`
		GroupID sql.NullInt64 `db:"group_id"`
	}
	if err := s.db.SelectContext(ctx, &rows, s.db.Rebind(q), args...); err != nil {
		return nil, err
	}
	for _, r := range rows {
		if r.GroupID.Valid {
			out[r.TypeID] = int(r.GroupID.Int64)
		}
	}
	return out, nil
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

// TypeVolumes ports the show-page volume lookup: packaged_volume wins over
// volume, missing types read as 0.
func (s *SQL) TypeVolumes(ctx context.Context, typeIDs []int) (map[int]float64, error) {
	out := map[int]float64{}
	if len(typeIDs) == 0 {
		return out, nil
	}
	q, args, err := sqlx.In(`SELECT type_id, COALESCE(packaged_volume, volume, 0) AS v FROM eve_types WHERE type_id IN (?)`, typeIDs)
	if err != nil {
		return nil, err
	}
	var rows []struct {
		TypeID int     `db:"type_id"`
		V      float64 `db:"v"`
	}
	if err := s.db.SelectContext(ctx, &rows, s.db.Rebind(q), args...); err != nil {
		return nil, err
	}
	for _, r := range rows {
		out[r.TypeID] = r.V
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

// ReprocessMaterial is one type_materials row: quantity of material per
// portion of the reprocessed type.
type ReprocessMaterial struct {
	TypeID         int `db:"type_id"`
	MaterialTypeID int `db:"material_type_id"`
	Quantity       int `db:"quantity"`
}

// TypePortions batch-loads portion_size per type (callers default missing to 1).
func (s *SQL) TypePortions(ctx context.Context, typeIDs []int) (map[int]int, error) {
	out := map[int]int{}
	if len(typeIDs) == 0 {
		return out, nil
	}
	q, args, err := sqlx.In(`SELECT type_id, portion_size FROM eve_types WHERE type_id IN (?)`, typeIDs)
	if err != nil {
		return nil, err
	}
	var rows []struct {
		TypeID  int           `db:"type_id"`
		Portion sql.NullInt64 `db:"portion_size"`
	}
	if err := s.db.SelectContext(ctx, &rows, s.db.Rebind(q), args...); err != nil {
		return nil, err
	}
	for _, r := range rows {
		if r.Portion.Valid && r.Portion.Int64 > 0 {
			out[r.TypeID] = int(r.Portion.Int64)
		}
	}
	return out, nil
}

// TypeMaterials batch-loads type_materials rows grouped by type. Types absent
// from the table (or Fuzzwork-only) yield no entry; the Go port skips the
// Fuzzwork fallback by design (see plan.md conventions).
func (s *SQL) TypeMaterials(ctx context.Context, typeIDs []int) (map[int][]ReprocessMaterial, error) {
	out := map[int][]ReprocessMaterial{}
	if len(typeIDs) == 0 {
		return out, nil
	}
	q, args, err := sqlx.In(`SELECT type_id, material_type_id, quantity FROM type_materials WHERE type_id IN (?)`, typeIDs)
	if err != nil {
		return nil, err
	}
	var rows []ReprocessMaterial
	if err := s.db.SelectContext(ctx, &rows, s.db.Rebind(q), args...); err != nil {
		return nil, err
	}
	for _, r := range rows {
		out[r.TypeID] = append(out[r.TypeID], r)
	}
	return out, nil
}

// GroupNames batch-loads inv_groups names for ship grouping.
func (s *SQL) GroupNames(ctx context.Context, groupIDs []int) (map[int]string, error) {
	out := map[int]string{}
	if len(groupIDs) == 0 {
		return out, nil
	}
	q, args, err := sqlx.In(`SELECT group_id, name FROM inv_groups WHERE group_id IN (?)`, groupIDs)
	if err != nil {
		return nil, err
	}
	var rows []struct {
		GroupID int    `db:"group_id"`
		Name    string `db:"name"`
	}
	if err := s.db.SelectContext(ctx, &rows, s.db.Rebind(q), args...); err != nil {
		return nil, err
	}
	for _, r := range rows {
		out[r.GroupID] = r.Name
	}
	return out, nil
}

func jsonUnmarshalDemo(s string, v any) error { return json.Unmarshal([]byte(s), v) }

type demoPair struct{ bp, prod int }

func sortDemoPool(pool []demoPair) {
	for i := 1; i < len(pool); i++ {
		for j := i; j > 0 && pool[j].bp < pool[j-1].bp; j-- {
			pool[j], pool[j-1] = pool[j-1], pool[j]
		}
	}
}

// DemoBlueprintPool ports DemoEsiResponder::blueprintPool(): priced
// manufacturing [blueprint, product] pairs ordered by blueprint, cap 400.
func (s *SQL) DemoBlueprintPool(ctx context.Context) ([][2]int, error) {
	type row struct {
		BP       int            `db:"blueprint_type_id"`
		Products sql.NullString `db:"products"`
	}
	var rows []row
	err := s.db.SelectContext(ctx, &rows, `SELECT b.blueprint_type_id, b.products
		FROM blueprint_activities b JOIN eve_types t ON t.type_id = b.blueprint_type_id
		WHERE b.activity_id = 1 AND t.published = 1 AND t.market_group_id IS NOT NULL
		ORDER BY b.blueprint_type_id LIMIT 6000`)
	if err != nil {
		return nil, err
	}
	cands := map[int]int{}
	var pids []int
	for _, r := range rows {
		var prods []struct {
			TypeID int `json:"type_id"`
		}
		if r.Products.Valid {
			_ = jsonUnmarshalDemo(r.Products.String, &prods)
		}
		if len(prods) > 0 && prods[0].TypeID > 0 {
			if _, ok := cands[prods[0].TypeID]; !ok {
				cands[prods[0].TypeID] = r.BP
				pids = append(pids, prods[0].TypeID)
			}
		}
	}
	priced := map[int]bool{}
	if len(pids) > 0 {
		q, args, err := sqlx.In(`SELECT DISTINCT type_id FROM market_prices WHERE type_id IN (?) AND sell_min > 0`, pids)
		if err != nil {
			return nil, err
		}
		var got []int
		if err := s.db.SelectContext(ctx, &got, s.db.Rebind(q), args...); err != nil {
			return nil, err
		}
		for _, id := range got {
			priced[id] = true
		}
	}
	var pool []demoPair
	for pid, bp := range cands {
		if priced[pid] {
			pool = append(pool, demoPair{bp, pid})
		}
	}
	sortDemoPool(pool)
	out := make([][2]int, 0, len(pool))
	for _, p := range pool {
		if len(out) >= 400 {
			break
		}
		out = append(out, [2]int{p.bp, p.prod})
	}
	return out, nil
}
