package store

import (
	"context"
	"database/sql"
)

// SdeType mirrors the eve_types upsert shape.
type SdeType struct {
	TypeID        int
	Name          string
	Description   string
	GroupID       *int64
	MarketGroupID *int64
	Volume        *float64
	PackagedVolume *float64
	Mass          *float64
	Capacity      *float64
	PortionSize   *int64
	IconID        *int64
	GraphicID     *int64
	MetaGroupID   *int64
	Published     bool
}

// SdeStore covers static-data imports.
type SdeStore interface {
	UpsertEveTypes(ctx context.Context, rows []SdeType) error
	UpsertStations(ctx context.Context, rows []SdeStation) error
	UpsertSolarSystems(ctx context.Context, rows []SdeSystem) error
	UpsertStargates(ctx context.Context, rows []SdeStargate) error
	UpsertTypeMaterials(ctx context.Context, rows []SdeMaterial) error
	UpsertBlueprintActivities(ctx context.Context, rows []SdeBlueprint) error
	UpsertMarketGroups(ctx context.Context, rows []SdeMarketGroup) error
	UpsertInvGroups(ctx context.Context, rows []SdeGroup) error
	UpsertInvCategories(ctx context.Context, rows []SdeCategory) error
	MaxBlueprintTypeID(ctx context.Context) (int64, error)
}

// SdeStation mirrors one sde_stations row.
type SdeStation struct {
	StationID     uint64
	Name          string
	SolarSystemID *int
	ConstellationID *int
	RegionID      *int
	TypeID        *int
	CorporationID *int
	Reprocessing  *float64
	Security      *float64
}

// SdeSystem mirrors one sde_solar_systems row.
type SdeSystem struct {
	SystemID        int
	Name            string
	ConstellationID *int
	RegionID        *int
	Security        *float64
	SecurityClass   *string
}

// SdeStargate mirrors one sde_stargates row.
type SdeStargate struct {
	GateID      uint64
	SystemID    *int
	DestGateID  *uint64
	TypeID      *int
}

// SdeMaterial mirrors one type_materials row.
type SdeMaterial struct {
	TypeID         int
	MaterialTypeID int
	Quantity       int
}

// SdeBlueprint mirrors one blueprint_activities row.
type SdeBlueprint struct {
	BlueprintTypeID int
	ActivityID      int
	MaterialsJSON   string
	ProductsJSON    string
	Time            int
}

// SdeMarketGroup mirrors one market_groups row.
type SdeMarketGroup struct {
	GroupID       int
	Name          string
	Description   *string
	ParentGroupID *int
	IconID        *int
	HasTypes      bool
}

// SdeGroup mirrors one inv_groups row.
type SdeGroup struct {
	GroupID     int
	Name        string
	CategoryID  *int
	Published   bool
}

// SdeCategory mirrors one inv_categories row.
type SdeCategory struct {
	CategoryID int
	Name       string
	Published  bool
}

func execChunks(ctx context.Context, db *sql.DB, query string, args []any) error {
	_, err := db.ExecContext(ctx, query, args...)
	return err
}

// UpsertEveTypes bulk-upserts eve_types rows.
func (s *SQL) UpsertEveTypes(ctx context.Context, rows []SdeType) error {
	for _, chunk := range chunkSdeTypes(rows, 500) {
		q := `INSERT INTO eve_types (type_id, name, description, group_id, market_group_id, volume,
			packaged_volume, mass, capacity, portion_size, icon_id, graphic_id, meta_group_id, published, created_at, updated_at) VALUES `
		var args []any
		for i, r := range chunk {
			if i > 0 {
				q += ","
			}
			q += `(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())`
			args = append(args, r.TypeID, r.Name, r.Description, r.GroupID, r.MarketGroupID, r.Volume,
				r.PackagedVolume, r.Mass, r.Capacity, r.PortionSize, r.IconID, r.GraphicID, r.MetaGroupID, r.Published)
		}
		q += ` ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description), group_id = VALUES(group_id),
			market_group_id = VALUES(market_group_id), volume = VALUES(volume), packaged_volume = VALUES(packaged_volume),
			mass = VALUES(mass), capacity = VALUES(capacity), portion_size = VALUES(portion_size), icon_id = VALUES(icon_id),
			graphic_id = VALUES(graphic_id), meta_group_id = VALUES(meta_group_id), published = VALUES(published), updated_at = NOW()`
		if err := execChunks(ctx, s.db.DB, q, args); err != nil {
			return err
		}
	}
	return nil
}

func chunkSdeTypes(in []SdeType, n int) [][]SdeType {
	var out [][]SdeType
	for len(in) > 0 {
		if len(in) < n {
			n = len(in)
		}
		out = append(out, in[:n])
		in = in[n:]
	}
	return out
}

// UpsertStations bulk-upserts sde_stations rows.
func (s *SQL) UpsertStations(ctx context.Context, rows []SdeStation) error {
	for _, chunk := range chunkSdeStations(rows, 500) {
		q := `INSERT INTO sde_stations (station_id, station_name, solar_system_id, constellation_id, region_id,
			station_type_id, corporation_id, reprocessing_efficiency, security, created_at, updated_at) VALUES `
		var args []any
		for i, r := range chunk {
			if i > 0 {
				q += ","
			}
			q += `(?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())`
			args = append(args, r.StationID, r.Name, r.SolarSystemID, r.ConstellationID, r.RegionID,
				r.TypeID, r.CorporationID, r.Reprocessing, r.Security)
		}
		q += ` ON DUPLICATE KEY UPDATE station_name = VALUES(station_name), solar_system_id = VALUES(solar_system_id),
			constellation_id = VALUES(constellation_id), region_id = VALUES(region_id), station_type_id = VALUES(station_type_id),
			corporation_id = VALUES(corporation_id), reprocessing_efficiency = VALUES(reprocessing_efficiency),
			security = VALUES(security), updated_at = NOW()`
		if err := execChunks(ctx, s.db.DB, q, args); err != nil {
			return err
		}
	}
	return nil
}

func chunkSdeStations(in []SdeStation, n int) [][]SdeStation {
	var out [][]SdeStation
	for len(in) > 0 {
		if len(in) < n {
			n = len(in)
		}
		out = append(out, in[:n])
		in = in[n:]
	}
	return out
}

// UpsertSolarSystems bulk-upserts sde_solar_systems rows.
func (s *SQL) UpsertSolarSystems(ctx context.Context, rows []SdeSystem) error {
	for _, chunk := range chunkSdeSystems(rows, 500) {
		q := `INSERT INTO sde_solar_systems (solar_system_id, solar_system_name, constellation_id, region_id,
			security, security_class, created_at, updated_at) VALUES `
		var args []any
		for i, r := range chunk {
			if i > 0 {
				q += ","
			}
			q += `(?, ?, ?, ?, ?, ?, NOW(), NOW())`
			args = append(args, r.SystemID, r.Name, r.ConstellationID, r.RegionID, r.Security, r.SecurityClass)
		}
		q += ` ON DUPLICATE KEY UPDATE solar_system_name = VALUES(solar_system_name),
			constellation_id = VALUES(constellation_id), region_id = VALUES(region_id),
			security = VALUES(security), security_class = VALUES(security_class), updated_at = NOW()`
		if err := execChunks(ctx, s.db.DB, q, args); err != nil {
			return err
		}
	}
	return nil
}

func chunkSdeSystems(in []SdeSystem, n int) [][]SdeSystem {
	var out [][]SdeSystem
	for len(in) > 0 {
		if len(in) < n {
			n = len(in)
		}
		out = append(out, in[:n])
		in = in[n:]
	}
	return out
}

// UpsertStargates bulk-upserts sde_stargates rows.
func (s *SQL) UpsertStargates(ctx context.Context, rows []SdeStargate) error {
	for _, chunk := range chunkSdeStargates(rows, 500) {
		q := `INSERT INTO sde_stargates (stargate_id, solar_system_id, destination_stargate_id, type_id, created_at, updated_at) VALUES `
		var args []any
		for i, r := range chunk {
			if i > 0 {
				q += ","
			}
			q += `(?, ?, ?, ?, NOW(), NOW())`
			args = append(args, r.GateID, r.SystemID, r.DestGateID, r.TypeID)
		}
		q += ` ON DUPLICATE KEY UPDATE solar_system_id = VALUES(solar_system_id),
			destination_stargate_id = VALUES(destination_stargate_id), type_id = VALUES(type_id), updated_at = NOW()`
		if err := execChunks(ctx, s.db.DB, q, args); err != nil {
			return err
		}
	}
	return nil
}

func chunkSdeStargates(in []SdeStargate, n int) [][]SdeStargate {
	var out [][]SdeStargate
	for len(in) > 0 {
		if len(in) < n {
			n = len(in)
		}
		out = append(out, in[:n])
		in = in[n:]
	}
	return out
}

// UpsertTypeMaterials bulk-upserts type_materials rows.
func (s *SQL) UpsertTypeMaterials(ctx context.Context, rows []SdeMaterial) error {
	for _, chunk := range chunkSdeMaterials(rows, 1000) {
		q := `INSERT INTO type_materials (type_id, material_type_id, quantity, created_at, updated_at) VALUES `
		var args []any
		for i, r := range chunk {
			if i > 0 {
				q += ","
			}
			q += `(?, ?, ?, NOW(), NOW())`
			args = append(args, r.TypeID, r.MaterialTypeID, r.Quantity)
		}
		q += ` ON DUPLICATE KEY UPDATE quantity = VALUES(quantity), updated_at = NOW()`
		if err := execChunks(ctx, s.db.DB, q, args); err != nil {
			return err
		}
	}
	return nil
}

func chunkSdeMaterials(in []SdeMaterial, n int) [][]SdeMaterial {
	var out [][]SdeMaterial
	for len(in) > 0 {
		if len(in) < n {
			n = len(in)
		}
		out = append(out, in[:n])
		in = in[n:]
	}
	return out
}

// UpsertBlueprintActivities bulk-upserts blueprint_activities rows.
func (s *SQL) UpsertBlueprintActivities(ctx context.Context, rows []SdeBlueprint) error {
	for _, chunk := range chunkSdeBlueprints(rows, 500) {
		q := `INSERT INTO blueprint_activities (blueprint_type_id, activity_id, materials, products, time, created_at, updated_at) VALUES `
		var args []any
		for i, r := range chunk {
			if i > 0 {
				q += ","
			}
			q += `(?, ?, ?, ?, ?, NOW(), NOW())`
			args = append(args, r.BlueprintTypeID, r.ActivityID, r.MaterialsJSON, r.ProductsJSON, r.Time)
		}
		q += ` ON DUPLICATE KEY UPDATE materials = VALUES(materials), products = VALUES(products),
			time = VALUES(time), updated_at = NOW()`
		if err := execChunks(ctx, s.db.DB, q, args); err != nil {
			return err
		}
	}
	return nil
}

func chunkSdeBlueprints(in []SdeBlueprint, n int) [][]SdeBlueprint {
	var out [][]SdeBlueprint
	for len(in) > 0 {
		if len(in) < n {
			n = len(in)
		}
		out = append(out, in[:n])
		in = in[n:]
	}
	return out
}

// UpsertMarketGroups bulk-upserts market_groups rows.
func (s *SQL) UpsertMarketGroups(ctx context.Context, rows []SdeMarketGroup) error {
	for _, chunk := range chunkSdeMarketGroups(rows, 500) {
		q := `INSERT INTO market_groups (market_group_id, name, description, parent_group_id, icon_id, has_types, created_at, updated_at) VALUES `
		var args []any
		for i, r := range chunk {
			if i > 0 {
				q += ","
			}
			q += `(?, ?, ?, ?, ?, ?, NOW(), NOW())`
			args = append(args, r.GroupID, r.Name, r.Description, r.ParentGroupID, r.IconID, r.HasTypes)
		}
		q += ` ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description),
			parent_group_id = VALUES(parent_group_id), icon_id = VALUES(icon_id), has_types = VALUES(has_types), updated_at = NOW()`
		if err := execChunks(ctx, s.db.DB, q, args); err != nil {
			return err
		}
	}
	return nil
}

func chunkSdeMarketGroups(in []SdeMarketGroup, n int) [][]SdeMarketGroup {
	var out [][]SdeMarketGroup
	for len(in) > 0 {
		if len(in) < n {
			n = len(in)
		}
		out = append(out, in[:n])
		in = in[n:]
	}
	return out
}

// UpsertInvGroups bulk-upserts inv_groups rows.
func (s *SQL) UpsertInvGroups(ctx context.Context, rows []SdeGroup) error {
	for _, chunk := range chunkSdeGroups(rows, 500) {
		q := `INSERT INTO inv_groups (group_id, name, category_id, published, created_at, updated_at) VALUES `
		var args []any
		for i, r := range chunk {
			if i > 0 {
				q += ","
			}
			q += `(?, ?, ?, ?, NOW(), NOW())`
			args = append(args, r.GroupID, r.Name, r.CategoryID, r.Published)
		}
		q += ` ON DUPLICATE KEY UPDATE name = VALUES(name), category_id = VALUES(category_id),
			published = VALUES(published), updated_at = NOW()`
		if err := execChunks(ctx, s.db.DB, q, args); err != nil {
			return err
		}
	}
	return nil
}

func chunkSdeGroups(in []SdeGroup, n int) [][]SdeGroup {
	var out [][]SdeGroup
	for len(in) > 0 {
		if len(in) < n {
			n = len(in)
		}
		out = append(out, in[:n])
		in = in[n:]
	}
	return out
}

// UpsertInvCategories bulk-upserts inv_categories rows.
func (s *SQL) UpsertInvCategories(ctx context.Context, rows []SdeCategory) error {
	for _, chunk := range chunkSdeCategories(rows, 500) {
		q := `INSERT INTO inv_categories (category_id, name, published, created_at, updated_at) VALUES `
		var args []any
		for i, r := range chunk {
			if i > 0 {
				q += ","
			}
			q += `(?, ?, ?, NOW(), NOW())`
			args = append(args, r.CategoryID, r.Name, r.Published)
		}
		q += ` ON DUPLICATE KEY UPDATE name = VALUES(name), published = VALUES(published), updated_at = NOW()`
		if err := execChunks(ctx, s.db.DB, q, args); err != nil {
			return err
		}
	}
	return nil
}

func chunkSdeCategories(in []SdeCategory, n int) [][]SdeCategory {
	var out [][]SdeCategory
	for len(in) > 0 {
		if len(in) < n {
			n = len(in)
		}
		out = append(out, in[:n])
		in = in[n:]
	}
	return out
}

// MaxBlueprintTypeID returns the highest blueprint id (chunk fan-out bound).
func (s *SQL) MaxBlueprintTypeID(ctx context.Context) (int64, error) {
	var max sql.NullInt64
	err := s.db.GetContext(ctx, &max, `SELECT MAX(blueprint_type_id) FROM blueprint_activities`)
	if err != nil || !max.Valid {
		return 0, err
	}
	return max.Int64, nil
}

// EveTypeName resolves one type name for SDE enrichment.
func (s *SQL) EveTypeName(ctx context.Context, typeID int) (string, error) {
	var name sql.NullString
	err := s.db.GetContext(ctx, &name, `SELECT name FROM eve_types WHERE type_id = ?`, typeID)
	if err == sql.ErrNoRows {
		return "", nil
	}
	if err != nil || !name.Valid {
		return "", err
	}
	return name.String, nil
}
