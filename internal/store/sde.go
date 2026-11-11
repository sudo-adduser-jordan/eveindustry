package store

import (
	"context"
	"database/sql"
)

type SdeType struct {
	TypeID         int
	Name           string
	Description    string
	GroupID        *int64
	MarketGroupID  *int64
	Volume         *float64
	PackagedVolume *float64
	Mass           *float64
	Capacity       *float64
	PortionSize    *int64
	IconID         *int64
	GraphicID      *int64
	MetaGroupID    *int64
	Published      bool
}

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

type SdeStation struct {
	StationID       uint64
	Name            string
	SolarSystemID   *int
	ConstellationID *int
	RegionID        *int
	TypeID          *int
	CorporationID   *int
	Reprocessing    *float64
	Security        *float64
}

type SdeSystem struct {
	SystemID        int
	Name            string
	ConstellationID *int
	RegionID        *int
	Security        *float64
	SecurityClass   *string
}

type SdeStargate struct {
	GateID     uint64
	SystemID   *int
	DestGateID *uint64
	TypeID     *int
}

type SdeMaterial struct {
	TypeID         int
	MaterialTypeID int
	Quantity       int
}

type SdeBlueprint struct {
	BlueprintTypeID int
	ActivityID      int
	MaterialsJSON   string
	ProductsJSON    string
	Time            int
}

type SdeMarketGroup struct {
	GroupID       int
	Name          string
	Description   *string
	ParentGroupID *int
	IconID        *int
	HasTypes      bool
}

type SdeGroup struct {
	GroupID    int
	Name       string
	CategoryID *int
	Published  bool
}

type SdeCategory struct {
	CategoryID int
	Name       string
	Published  bool
}

func execChunks(ctx context.Context, db *sql.DB, query string, args []any) error {
	_, err := db.ExecContext(ctx, query, args...)
	return err
}

func (s *SQL) UpsertEveTypes(ctx context.Context, rows []SdeType) error {
	for _, chunk := range chunkSdeTypes(rows, 500) {
		q := `INSERT INTO eve_types (type_id, name, description, group_id, market_group_id, volume,
			packaged_volume, mass, capacity, portion_size, icon_id, graphic_id, meta_group_id, published, created_at, updated_at) VALUES `
		var args []any
		for i, r := range chunk {
			if i > 0 {
				q += ","
			}
			q += `(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)`
			args = append(args, r.TypeID, r.Name, r.Description, r.GroupID, r.MarketGroupID, r.Volume,
				r.PackagedVolume, r.Mass, r.Capacity, r.PortionSize, r.IconID, r.GraphicID, r.MetaGroupID, r.Published)
		}
		q += ` ON CONFLICT(type_id) DO UPDATE SET name = excluded.name, description = excluded.description, group_id = excluded.group_id,
			market_group_id = excluded.market_group_id, volume = excluded.volume, packaged_volume = excluded.packaged_volume,
			mass = excluded.mass, capacity = excluded.capacity, portion_size = excluded.portion_size, icon_id = excluded.icon_id,
			graphic_id = excluded.graphic_id, meta_group_id = excluded.meta_group_id, published = excluded.published, updated_at = CURRENT_TIMESTAMP`
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

func (s *SQL) UpsertStations(ctx context.Context, rows []SdeStation) error {
	for _, chunk := range chunkSdeStations(rows, 500) {
		q := `INSERT INTO sde_stations (station_id, station_name, solar_system_id, constellation_id, region_id,
			station_type_id, corporation_id, reprocessing_efficiency, security, created_at, updated_at) VALUES `
		var args []any
		for i, r := range chunk {
			if i > 0 {
				q += ","
			}
			q += `(?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)`
			args = append(args, r.StationID, r.Name, r.SolarSystemID, r.ConstellationID, r.RegionID,
				r.TypeID, r.CorporationID, r.Reprocessing, r.Security)
		}
		q += ` ON CONFLICT(station_id) DO UPDATE SET station_name = excluded.station_name, solar_system_id = excluded.solar_system_id,
			constellation_id = excluded.constellation_id, region_id = excluded.region_id, station_type_id = excluded.station_type_id,
			corporation_id = excluded.corporation_id, reprocessing_efficiency = excluded.reprocessing_efficiency,
			security = excluded.security, updated_at = CURRENT_TIMESTAMP`
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

func (s *SQL) UpsertSolarSystems(ctx context.Context, rows []SdeSystem) error {
	for _, chunk := range chunkSdeSystems(rows, 500) {
		q := `INSERT INTO sde_solar_systems (solar_system_id, solar_system_name, constellation_id, region_id,
			security, security_class, created_at, updated_at) VALUES `
		var args []any
		for i, r := range chunk {
			if i > 0 {
				q += ","
			}
			q += `(?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)`
			args = append(args, r.SystemID, r.Name, r.ConstellationID, r.RegionID, r.Security, r.SecurityClass)
		}
		q += ` ON CONFLICT(solar_system_id) DO UPDATE SET solar_system_name = excluded.solar_system_name,
			constellation_id = excluded.constellation_id, region_id = excluded.region_id,
			security = excluded.security, security_class = excluded.security_class, updated_at = CURRENT_TIMESTAMP`
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

func (s *SQL) UpsertStargates(ctx context.Context, rows []SdeStargate) error {
	for _, chunk := range chunkSdeStargates(rows, 500) {
		q := `INSERT INTO sde_stargates (stargate_id, solar_system_id, destination_stargate_id, type_id, created_at, updated_at) VALUES `
		var args []any
		for i, r := range chunk {
			if i > 0 {
				q += ","
			}
			q += `(?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)`
			args = append(args, r.GateID, r.SystemID, r.DestGateID, r.TypeID)
		}
		q += ` ON CONFLICT(stargate_id) DO UPDATE SET solar_system_id = excluded.solar_system_id,
			destination_stargate_id = excluded.destination_stargate_id, type_id = excluded.type_id, updated_at = CURRENT_TIMESTAMP`
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

func (s *SQL) UpsertTypeMaterials(ctx context.Context, rows []SdeMaterial) error {
	for _, chunk := range chunkSdeMaterials(rows, 1000) {
		q := `INSERT INTO type_materials (type_id, material_type_id, quantity, created_at, updated_at) VALUES `
		var args []any
		for i, r := range chunk {
			if i > 0 {
				q += ","
			}
			q += `(?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)`
			args = append(args, r.TypeID, r.MaterialTypeID, r.Quantity)
		}
		q += ` ON CONFLICT(type_id, material_type_id) DO UPDATE SET quantity = excluded.quantity, updated_at = CURRENT_TIMESTAMP`
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

func (s *SQL) UpsertBlueprintActivities(ctx context.Context, rows []SdeBlueprint) error {
	for _, chunk := range chunkSdeBlueprints(rows, 500) {
		q := `INSERT INTO blueprint_activities (blueprint_type_id, activity_id, materials, products, time, created_at, updated_at) VALUES `
		var args []any
		for i, r := range chunk {
			if i > 0 {
				q += ","
			}
			q += `(?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)`
			args = append(args, r.BlueprintTypeID, r.ActivityID, r.MaterialsJSON, r.ProductsJSON, r.Time)
		}
		q += ` ON CONFLICT(blueprint_type_id, activity_id) DO UPDATE SET materials = excluded.materials, products = excluded.products,
			time = excluded.time, updated_at = CURRENT_TIMESTAMP`
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

func (s *SQL) UpsertMarketGroups(ctx context.Context, rows []SdeMarketGroup) error {
	for _, chunk := range chunkSdeMarketGroups(rows, 500) {
		q := `INSERT INTO market_groups (market_group_id, name, description, parent_group_id, icon_id, has_types, created_at, updated_at) VALUES `
		var args []any
		for i, r := range chunk {
			if i > 0 {
				q += ","
			}
			q += `(?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)`
			args = append(args, r.GroupID, r.Name, r.Description, r.ParentGroupID, r.IconID, r.HasTypes)
		}
		q += ` ON CONFLICT(market_group_id) DO UPDATE SET name = excluded.name, description = excluded.description,
			parent_group_id = excluded.parent_group_id, icon_id = excluded.icon_id, has_types = excluded.has_types, updated_at = CURRENT_TIMESTAMP`
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

func (s *SQL) UpsertInvGroups(ctx context.Context, rows []SdeGroup) error {
	for _, chunk := range chunkSdeGroups(rows, 500) {
		q := `INSERT INTO inv_groups (group_id, name, category_id, published, created_at, updated_at) VALUES `
		var args []any
		for i, r := range chunk {
			if i > 0 {
				q += ","
			}
			q += `(?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)`
			args = append(args, r.GroupID, r.Name, r.CategoryID, r.Published)
		}
		q += ` ON CONFLICT(group_id) DO UPDATE SET name = excluded.name, category_id = excluded.category_id,
			published = excluded.published, updated_at = CURRENT_TIMESTAMP`
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

func (s *SQL) UpsertInvCategories(ctx context.Context, rows []SdeCategory) error {
	for _, chunk := range chunkSdeCategories(rows, 500) {
		q := `INSERT INTO inv_categories (category_id, name, published, created_at, updated_at) VALUES `
		var args []any
		for i, r := range chunk {
			if i > 0 {
				q += ","
			}
			q += `(?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)`
			args = append(args, r.CategoryID, r.Name, r.Published)
		}
		q += ` ON CONFLICT(category_id) DO UPDATE SET name = excluded.name, published = excluded.published, updated_at = CURRENT_TIMESTAMP`
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

func (s *SQL) MaxBlueprintTypeID(ctx context.Context) (int64, error) {
	var max sql.NullInt64
	err := s.db.GetContext(ctx, &max, `SELECT MAX(blueprint_type_id) FROM blueprint_activities`)
	if err != nil || !max.Valid {
		return 0, err
	}
	return max.Int64, nil
}

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
