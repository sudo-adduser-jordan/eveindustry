// Package store is the sqlx data layer for the Go API. Table and column
// names mirror database/migrations/*.php exactly; every query has an
// interface so handlers stay testable without MySQL.
package store

import (
	"context"
	"database/sql"
	"time"

	"github.com/jmoiron/sqlx"
)

// DB wraps sqlx for dependency injection. Nil *DB means "database not
// configured": handlers answer 503 rather than crashing (same contract as
// the PHP readiness check).
type DB struct {
	*sqlx.DB
}

// Connect opens MySQL with parseTime so DATETIME scans into time.Time,
// matching the DSN built by internal/config.
func Connect(dsn string) (*DB, error) {
	db, err := sqlx.Connect("mysql", dsn)
	if err != nil {
		return nil, err
	}
	return &DB{db}, nil
}

// --- Row shapes (column names = migration columns) ---

type MarketGroup struct {
	MarketGroupID int            `db:"market_group_id"`
	Name          string         `db:"name"`
	Description   sql.NullString `db:"description"`
	ParentGroupID sql.NullInt64  `db:"parent_group_id"`
	HasTypes      bool           `db:"has_types"`
}

type EveType struct {
	TypeID        int             `db:"type_id"`
	Name          string          `db:"name"`
	Description   sql.NullString  `db:"description"`
	GroupID       sql.NullInt64   `db:"group_id"`
	MarketGroupID sql.NullInt64   `db:"market_group_id"`
	Volume        sql.NullFloat64 `db:"volume"`
	Mass          sql.NullFloat64 `db:"mass"`
	PortionSize   sql.NullInt64   `db:"portion_size"`
	Published     bool            `db:"published"`
	MetaGroupID   sql.NullInt64   `db:"meta_group_id"`
}

type MarketPriceRow struct {
	TypeID       int             `db:"type_id"`
	RegionID     int             `db:"region_id"`
	AveragePrice sql.NullFloat64 `db:"average_price"`
	SellMin      sql.NullFloat64 `db:"sell_min"`
}

type MarketOrderRow struct {
	OrderID      uint64        `db:"order_id"`
	RegionID     int           `db:"region_id"`
	TypeID       int           `db:"type_id"`
	IsBuyOrder   bool          `db:"is_buy_order"`
	Price        float64       `db:"price"`
	VolumeRemain uint64        `db:"volume_remain"`
	VolumeTotal  uint64        `db:"volume_total"`
	LocationID   sql.NullInt64 `db:"location_id"`
	Issued       sql.NullTime  `db:"issued"`
}

type MarketHistoryRow struct {
	Date       time.Time `db:"date"`
	Average    float64   `db:"average"`
	Highest    float64   `db:"highest"`
	Lowest     float64   `db:"lowest"`
	Volume     uint64    `db:"volume"`
	OrderCount uint64    `db:"order_count"`
}

type AggregateRow struct {
	TypeID      int             `db:"type_id"`
	SellPriceP5 sql.NullFloat64 `db:"sell_price_p5"`
}

type SolarSystem struct {
	SolarSystemID   int             `db:"solar_system_id"`
	SolarSystemName string          `db:"solar_system_name"`
	ConstellationID sql.NullInt64   `db:"constellation_id"`
	RegionID        sql.NullInt64   `db:"region_id"`
	Security        sql.NullFloat64 `db:"security"`
	SecurityClass   sql.NullString  `db:"security_class"`
}

type Station struct {
	StationID     uint64          `db:"station_id"`
	StationName   string          `db:"station_name"`
	SolarSystemID sql.NullInt64   `db:"solar_system_id"`
	RegionID      sql.NullInt64   `db:"region_id"`
	Security      sql.NullFloat64 `db:"security"`
}

// --- Interfaces (handlers depend on these, tests stub them) ---

// ReferenceStore covers GET /api/v1/types, /market-groups, /systems, /stations.
type ReferenceStore interface {
	RootGroups(ctx context.Context) ([]MarketGroup, error)
	ChildCount(ctx context.Context, groupID int) (int, error)
	MarketGroup(ctx context.Context, groupID int) (*MarketGroup, error)
	ChildGroups(ctx context.Context, groupID int) ([]MarketGroup, error)
	TypesInGroup(ctx context.Context, groupID int, excludeMeta []int) ([]EveType, error)
	AllGroups(ctx context.Context) ([]MarketGroup, error)
	ManufacturableGroupIDs(ctx context.Context) (map[int]bool, error)
	SearchTypes(ctx context.Context, query string, limit int, excludeMeta []int) ([]EveType, error)
	TypeExists(ctx context.Context, typeID int) (bool, error)
	FindType(ctx context.Context, typeID int) (*EveType, error)
	FindSystem(ctx context.Context, systemID int) (*SolarSystem, error)
	SearchSystems(ctx context.Context, query string, limit int) ([]SolarSystem, error)
	SystemsByName(ctx context.Context, names []string) ([]SolarSystem, error)
	FindStation(ctx context.Context, stationID uint64) (*Station, error)
	SearchStations(ctx context.Context, query string, systemID *int, limit int) ([]Station, error)
	StationsInSystem(ctx context.Context, systemID int) ([]Station, error)
}

// MarketStore covers GET /api/v1/market/* and the freshness block.
type MarketStore interface {
	// AggregateSellPrices reads the region's active sweep generation.
	// hasSnapshot is false when the region was never swept (the caller
	// falls back to raw orders, mirroring sellPrices() returning null).
	AggregateSellPrices(ctx context.Context, typeIDs []int, regionID int) (prices map[int]float64, hasSnapshot bool, err error)
	RawSellOrders(ctx context.Context, typeIDs []int, regionID int, since time.Time) ([]RawOrder, error)
	FallbackPrices(ctx context.Context, typeIDs []int, regionIDs []int) ([]FallbackPrice, error)
	TypeExists(ctx context.Context, typeID int) (bool, error)
	PagedOrders(ctx context.Context, typeID, regionID int, buy bool, limit, offset int) ([]MarketOrderRow, int, error)
	History(ctx context.Context, typeID, regionID, days int) ([]MarketHistoryRow, error)
	FreshnessTimestamps(ctx context.Context, regionID int) (orders, costs, global *time.Time, err error)
}

// RawOrder is the minimal order shape for the percentile calc.
type RawOrder struct {
	TypeID       int     `db:"type_id"`
	Price        float64 `db:"price"`
	VolumeRemain uint64  `db:"volume_remain"`
}

// FallbackPrice is one market_prices row for price resolution.
type FallbackPrice struct {
	TypeID   int             `db:"type_id"`
	RegionID int             `db:"region_id"`
	SellMin  sql.NullFloat64 `db:"sell_min"`
	Average  sql.NullFloat64 `db:"average_price"`
}

// SQL is the sqlx implementation of ReferenceStore + MarketStore.
type SQL struct {
	db *sqlx.DB
}

// NewSQL wraps an open sqlx DB. Used by cmd/api when DATABASE is configured.
func NewSQL(db *sqlx.DB) *SQL { return &SQL{db: db} }

func (s *SQL) RootGroups(ctx context.Context) ([]MarketGroup, error) {
	var out []MarketGroup
	err := s.db.SelectContext(ctx, &out,
		`SELECT market_group_id, name, description, parent_group_id, has_types
		 FROM market_groups WHERE parent_group_id IS NULL ORDER BY name`)
	return out, err
}

func (s *SQL) ChildCount(ctx context.Context, groupID int) (int, error) {
	var n int
	err := s.db.GetContext(ctx, &n,
		`SELECT COUNT(*) FROM market_groups WHERE parent_group_id = ?`, groupID)
	return n, err
}

func (s *SQL) MarketGroup(ctx context.Context, groupID int) (*MarketGroup, error) {
	var g MarketGroup
	err := s.db.GetContext(ctx, &g,
		`SELECT market_group_id, name, description, parent_group_id, has_types
		 FROM market_groups WHERE market_group_id = ?`, groupID)
	if err == sql.ErrNoRows {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	return &g, nil
}

func (s *SQL) ChildGroups(ctx context.Context, groupID int) ([]MarketGroup, error) {
	var out []MarketGroup
	err := s.db.SelectContext(ctx, &out,
		`SELECT market_group_id, name, description, parent_group_id, has_types
		 FROM market_groups WHERE parent_group_id = ? ORDER BY name`, groupID)
	return out, err
}

func metaFilter(excludeMeta []int) (string, []any) {
	if len(excludeMeta) == 0 {
		return "", nil
	}
	placeholders := ""
	args := make([]any, 0, len(excludeMeta))
	for i, id := range excludeMeta {
		if i > 0 {
			placeholders += ","
		}
		placeholders += "?"
		args = append(args, id)
	}
	return " AND (meta_group_id IS NULL OR meta_group_id NOT IN (" + placeholders + "))", args
}

func (s *SQL) TypesInGroup(ctx context.Context, groupID int, excludeMeta []int) ([]EveType, error) {
	filter, args := metaFilter(excludeMeta)
	var out []EveType
	args = append([]any{groupID}, args...)
	err := s.db.SelectContext(ctx, &out,
		`SELECT type_id, name, description, group_id, market_group_id, volume, mass,
		        portion_size, published, meta_group_id
		 FROM eve_types WHERE market_group_id = ? AND published = 1`+filter+` ORDER BY name`,
		args...)
	return out, err
}

func (s *SQL) AllGroups(ctx context.Context) ([]MarketGroup, error) {
	var out []MarketGroup
	err := s.db.SelectContext(ctx, &out,
		`SELECT market_group_id, name, description, parent_group_id, has_types
		 FROM market_groups ORDER BY name`)
	return out, err
}

// ManufacturableGroupIDs ports the leaf/ancestor/sibling logic of
// MarketBrowserService::getManufacturableMarketGroupIds() down to the group
// walk: products come from blueprint_activities (manufacturing minus
// invented), leaves from published eve_types, then ancestors + siblings.
func (s *SQL) ManufacturableGroupIDs(ctx context.Context) (map[int]bool, error) {
	var invented []int
	err := s.db.SelectContext(ctx, &invented,
		`SELECT DISTINCT blueprint_type_id FROM blueprint_activities
		 WHERE activity_id = 8 AND products IS NOT NULL`)
	if err != nil {
		return nil, err
	}

	// NOTE: products is JSON; MySQL JSON_TABLE expansion keeps this in SQL.
	// When products is NULL-heavy this degrades gracefully to an empty set.
	query := `SELECT DISTINCT t.market_group_id FROM eve_types t
		JOIN blueprint_activities b ON JSON_CONTAINS(b.products, CAST(t.type_id AS JSON), '$[*].type_id')
		WHERE b.activity_id = 1 AND b.products IS NOT NULL
		  AND t.published = 1 AND t.market_group_id IS NOT NULL`
	if len(invented) > 0 {
		q, args, err := sqlx.In(query+` AND b.blueprint_type_id NOT IN (?)`, invented)
		if err != nil {
			return nil, err
		}
		var leaves []int
		if err := s.db.SelectContext(ctx, &leaves, s.db.Rebind(q), args...); err != nil {
			return nil, err
		}
		return s.expandGroupAncestors(ctx, leaves)
	}
	var leaves []int
	if err := s.db.SelectContext(ctx, &leaves, query); err != nil {
		return nil, err
	}
	return s.expandGroupAncestors(ctx, leaves)
}

func (s *SQL) expandGroupAncestors(ctx context.Context, leaves []int) (map[int]bool, error) {
	groups, err := s.AllGroups(ctx)
	if err != nil {
		return nil, err
	}
	parent := map[int]*int{}
	childrenOf := map[int][]int{}
	for _, g := range groups {
		if g.ParentGroupID.Valid {
			p := int(g.ParentGroupID.Int64)
			parent[g.MarketGroupID] = &p
			childrenOf[p] = append(childrenOf[p], g.MarketGroupID)
		} else {
			parent[g.MarketGroupID] = nil
		}
	}
	keep := map[int]bool{}
	for _, leaf := range leaves {
		for cur := leaf; ; {
			if keep[cur] {
				break
			}
			keep[cur] = true
			p, ok := parent[cur]
			if !ok || p == nil {
				break
			}
			cur = *p
		}
	}
	// Siblings of manufactured-item leaves (one level), mirroring PHP step 5.
	leafParents := map[int]bool{}
	for _, leaf := range leaves {
		if p, ok := parent[leaf]; ok && p != nil {
			leafParents[*p] = true
		}
	}
	for _, g := range groups {
		if g.ParentGroupID.Valid && leafParents[int(g.ParentGroupID.Int64)] {
			keep[g.MarketGroupID] = true
		}
	}
	return keep, nil
}

func (s *SQL) SearchTypes(ctx context.Context, query string, limit int, excludeMeta []int) ([]EveType, error) {
	filter, args := metaFilter(excludeMeta)
	var out []EveType
	args = append([]any{"%" + query + "%"}, args...)
	args = append(args, limit)
	err := s.db.SelectContext(ctx, &out,
		`SELECT type_id, name, description, group_id, market_group_id, volume, mass,
		        portion_size, published, meta_group_id
		 FROM eve_types WHERE name LIKE ? AND published = 1
		  AND market_group_id IS NOT NULL`+filter+` ORDER BY name LIMIT ?`,
		args...)
	return out, err
}

func (s *SQL) TypeExists(ctx context.Context, typeID int) (bool, error) {
	var n int
	err := s.db.GetContext(ctx, &n, `SELECT COUNT(*) FROM eve_types WHERE type_id = ?`, typeID)
	return n > 0, err
}

func (s *SQL) FindType(ctx context.Context, typeID int) (*EveType, error) {
	var t EveType
	err := s.db.GetContext(ctx, &t,
		`SELECT type_id, name, description, group_id, market_group_id, volume, mass,
		        portion_size, published, meta_group_id
		 FROM eve_types WHERE type_id = ?`, typeID)
	if err == sql.ErrNoRows {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	return &t, nil
}

func (s *SQL) FindSystem(ctx context.Context, systemID int) (*SolarSystem, error) {
	var sys SolarSystem
	err := s.db.GetContext(ctx, &sys,
		`SELECT solar_system_id, solar_system_name, constellation_id, region_id,
		        security, security_class
		 FROM sde_solar_systems WHERE solar_system_id = ?`, systemID)
	if err == sql.ErrNoRows {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	return &sys, nil
}

func (s *SQL) SearchSystems(ctx context.Context, query string, limit int) ([]SolarSystem, error) {
	var out []SolarSystem
	err := s.db.SelectContext(ctx, &out,
		`SELECT solar_system_id, solar_system_name, constellation_id, region_id,
		        security, security_class
		 FROM sde_solar_systems WHERE solar_system_name LIKE ? ORDER BY solar_system_name LIMIT ?`,
		"%"+query+"%", limit)
	return out, err
}

func (s *SQL) SystemsByName(ctx context.Context, names []string) ([]SolarSystem, error) {
	if len(names) == 0 {
		return nil, nil
	}
	q, args, err := sqlx.In(
		`SELECT solar_system_id, solar_system_name, constellation_id, region_id,
		        security, security_class
		 FROM sde_solar_systems WHERE solar_system_name IN (?)`, names)
	if err != nil {
		return nil, err
	}
	var out []SolarSystem
	err = s.db.SelectContext(ctx, &out, s.db.Rebind(q), args...)
	return out, err
}

func (s *SQL) FindStation(ctx context.Context, stationID uint64) (*Station, error) {
	var st Station
	err := s.db.GetContext(ctx, &st,
		`SELECT station_id, station_name, solar_system_id, region_id, security
		 FROM sde_stations WHERE station_id = ?`, stationID)
	if err == sql.ErrNoRows {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	return &st, nil
}

func (s *SQL) SearchStations(ctx context.Context, query string, systemID *int, limit int) ([]Station, error) {
	var out []Station
	if systemID != nil {
		err := s.db.SelectContext(ctx, &out,
			`SELECT station_id, station_name, solar_system_id, region_id, security
			 FROM sde_stations WHERE solar_system_id = ? ORDER BY station_name LIMIT ?`,
			*systemID, limit)
		return out, err
	}
	err := s.db.SelectContext(ctx, &out,
		`SELECT station_id, station_name, solar_system_id, region_id, security
		 FROM sde_stations WHERE station_name LIKE ? ORDER BY station_name LIMIT ?`,
		"%"+query+"%", limit)
	return out, err
}

func (s *SQL) StationsInSystem(ctx context.Context, systemID int) ([]Station, error) {
	var out []Station
	err := s.db.SelectContext(ctx, &out,
		`SELECT station_id, station_name, solar_system_id, region_id, security
		 FROM sde_stations WHERE solar_system_id = ? ORDER BY station_name`, systemID)
	return out, err
}

func (s *SQL) AggregateSellPrices(ctx context.Context, typeIDs []int, regionID int) (map[int]float64, bool, error) {
	if len(typeIDs) == 0 {
		return map[int]float64{}, true, nil
	}
	// Follow the durable per-region pointer to the active complete sweep,
	// mirroring MarketAggregateService::currentSnapshot(). No pointer row
	// (or a NULL active sweep) means the region was never swept: the caller
	// computes from raw market_orders instead.
	var sweepID sql.NullString
	err := s.db.GetContext(ctx, &sweepID,
		`SELECT active_sweep_id FROM market_region_states WHERE region_id = ?`, regionID)
	if err == sql.ErrNoRows || (err == nil && !sweepID.Valid) {
		return nil, false, nil
	}
	if err != nil {
		return nil, false, err
	}
	q, args, err := sqlx.In(
		`SELECT type_id, sell_price_p5 FROM market_aggregates
		 WHERE region_id = ? AND sweep_id = ? AND type_id IN (?) AND sell_price_p5 IS NOT NULL`,
		regionID, sweepID.String, typeIDs)
	if err != nil {
		return nil, false, err
	}
	var rows []AggregateRow
	if err := s.db.SelectContext(ctx, &rows, s.db.Rebind(q), args...); err != nil {
		return nil, false, err
	}
	out := make(map[int]float64, len(typeIDs))
	for _, id := range typeIDs {
		out[id] = 0
	}
	for _, r := range rows {
		if r.SellPriceP5.Valid && r.SellPriceP5.Float64 > 0 {
			out[r.TypeID] = r.SellPriceP5.Float64
		}
	}
	return out, true, nil
}

func (s *SQL) RawSellOrders(ctx context.Context, typeIDs []int, regionID int, since time.Time) ([]RawOrder, error) {
	if len(typeIDs) == 0 {
		return nil, nil
	}
	q, args, err := sqlx.In(
		`SELECT type_id, price, volume_remain FROM market_orders
		 WHERE region_id = ? AND type_id IN (?)
		   AND is_buy_order = 0 AND volume_remain > 0 AND issued >= ?`,
		regionID, typeIDs, since)
	if err != nil {
		return nil, err
	}
	var out []RawOrder
	err = s.db.SelectContext(ctx, &out, s.db.Rebind(q), args...)
	return out, err
}

func (s *SQL) FallbackPrices(ctx context.Context, typeIDs []int, regionIDs []int) ([]FallbackPrice, error) {
	if len(typeIDs) == 0 {
		return nil, nil
	}
	q, args, err := sqlx.In(
		`SELECT type_id, region_id, sell_min, average_price FROM market_prices
		 WHERE type_id IN (?) AND region_id IN (?)`,
		typeIDs, regionIDs)
	if err != nil {
		return nil, err
	}
	var out []FallbackPrice
	err = s.db.SelectContext(ctx, &out, s.db.Rebind(q), args...)
	return out, err
}

func (s *SQL) PagedOrders(ctx context.Context, typeID, regionID int, buy bool, limit, offset int) ([]MarketOrderRow, int, error) {
	var total int
	err := s.db.GetContext(ctx, &total,
		`SELECT COUNT(*) FROM market_orders
		 WHERE region_id = ? AND type_id = ? AND is_buy_order = ? AND volume_remain > 0`,
		regionID, typeID, buy)
	if err != nil {
		return nil, 0, err
	}
	dir := "ASC"
	if buy {
		dir = "DESC"
	}
	var out []MarketOrderRow
	// dir is internal only (ASC/DESC), never user input: safe to interpolate.
	err = s.db.SelectContext(ctx, &out,
		`SELECT order_id, region_id, type_id, is_buy_order, price, volume_remain,
		        volume_total, location_id, issued
		 FROM market_orders
		 WHERE region_id = ? AND type_id = ? AND is_buy_order = ? AND volume_remain > 0
		 ORDER BY price `+dir+`, order_id LIMIT ? OFFSET ?`,
		regionID, typeID, buy, limit, offset)
	return out, total, err
}

func (s *SQL) History(ctx context.Context, typeID, regionID, days int) ([]MarketHistoryRow, error) {
	var out []MarketHistoryRow
	err := s.db.SelectContext(ctx, &out,
		`SELECT date, average, highest, lowest, volume, order_count FROM market_history
		 WHERE region_id = ? AND type_id = ? ORDER BY date DESC LIMIT ?`,
		regionID, typeID, days)
	if out == nil {
		out = []MarketHistoryRow{}
	}
	return out, err
}

// FreshnessTimestamps ports MarketFreshnessService::loadTimestamps() for one
// region. Orders cascade: durable active_snapshot_at, else the last
// completed sweep's started_at, else the last raw order write. Costs come
// from manufacturing_costs; global from market_prices under Jita.
func (s *SQL) FreshnessTimestamps(ctx context.Context, regionID int) (orders, costs, global *time.Time, err error) {
	var o, c, g sql.NullTime
	if e := s.db.GetContext(ctx, &o,
		`SELECT active_snapshot_at FROM market_region_states
		  WHERE region_id = ? AND active_sweep_id IS NOT NULL`, regionID); e != nil && e != sql.ErrNoRows {
		return nil, nil, nil, e
	}
	if !o.Valid {
		if e := s.db.GetContext(ctx, &o,
			`SELECT MAX(started_at) FROM market_order_sweeps
			  WHERE region_id = ? AND status = 'completed'`, regionID); e != nil {
			return nil, nil, nil, e
		}
	}
	if !o.Valid {
		if e := s.db.GetContext(ctx, &o,
			`SELECT MAX(updated_at) FROM market_orders WHERE region_id = ?`, regionID); e != nil {
			return nil, nil, nil, e
		}
	}
	if e := s.db.GetContext(ctx, &c,
		`SELECT MAX(updated_at) FROM manufacturing_costs WHERE region_id = ?`, regionID); e != nil {
		return nil, nil, nil, e
	}
	if e := s.db.GetContext(ctx, &g,
		`SELECT MAX(fetched_at) FROM market_prices WHERE region_id = 10000002`); e != nil {
		return nil, nil, nil, e
	}
	if o.Valid {
		orders = &o.Time
	}
	if c.Valid {
		costs = &c.Time
	}
	if g.Valid {
		global = &g.Time
	}
	return orders, costs, global, nil
}
