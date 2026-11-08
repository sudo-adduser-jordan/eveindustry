package store

import (
	"context"
	"database/sql"

	"github.com/sudo-adduser-jordan/eveindustry/internal/esi"
)

// SQL implements esi.TypeStore for the universe client: local SDE rows
// first, ESI fallback inside the client.
var _ esi.TypeStore = (*SQL)(nil)

// EveTypeByID returns the full eve_types row for UniverseClient.GetTypeInfo.
func (s *SQL) EveTypeByID(ctx context.Context, typeID int) (*esi.LocalType, error) {
	var row struct {
		TypeID         int             `db:"type_id"`
		Name           string          `db:"name"`
		Description    sql.NullString  `db:"description"`
		GroupID        sql.NullInt64   `db:"group_id"`
		MarketGroupID  sql.NullInt64   `db:"market_group_id"`
		Volume         sql.NullFloat64 `db:"volume"`
		PackagedVolume sql.NullFloat64 `db:"packaged_volume"`
		Mass           sql.NullFloat64 `db:"mass"`
		Capacity       sql.NullFloat64 `db:"capacity"`
		PortionSize    sql.NullInt64   `db:"portion_size"`
		IconID         sql.NullInt64   `db:"icon_id"`
		GraphicID      sql.NullInt64   `db:"graphic_id"`
		Published      bool            `db:"published"`
	}
	err := s.db.GetContext(ctx, &row, `
		SELECT type_id, name, description, group_id, market_group_id, volume,
		       packaged_volume, mass, capacity, portion_size, icon_id, graphic_id, published
		FROM eve_types WHERE type_id = ?`, typeID)
	if err == sql.ErrNoRows {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	out := &esi.LocalType{TypeID: row.TypeID, Name: row.Name, Published: row.Published}
	if row.Description.Valid {
		out.Description = row.Description.String
	}
	if row.GroupID.Valid {
		v := row.GroupID.Int64
		out.GroupID = &v
	}
	if row.MarketGroupID.Valid {
		v := row.MarketGroupID.Int64
		out.MarketGroupID = &v
	}
	if row.Volume.Valid {
		v := row.Volume.Float64
		out.Volume = &v
	}
	if row.PackagedVolume.Valid {
		v := row.PackagedVolume.Float64
		out.PackagedVolume = &v
	}
	if row.Mass.Valid {
		v := row.Mass.Float64
		out.Mass = &v
	}
	if row.Capacity.Valid {
		v := row.Capacity.Float64
		out.Capacity = &v
	}
	if row.PortionSize.Valid {
		v := int(row.PortionSize.Int64)
		out.PortionSize = &v
	}
	if row.IconID.Valid {
		v := int(row.IconID.Int64)
		out.IconID = &v
	}
	if row.GraphicID.Valid {
		v := int(row.GraphicID.Int64)
		out.GraphicID = &v
	}
	return out, nil
}

// SdeStationByID returns the sde_stations row for UniverseClient.GetStation.
func (s *SQL) SdeStationByID(ctx context.Context, stationID uint64) (*esi.LocalStation, error) {
	var row struct {
		StationID     uint64         `db:"station_id"`
		Name          string         `db:"station_name"`
		SolarSystemID sql.NullInt64  `db:"solar_system_id"`
	}
	err := s.db.GetContext(ctx, &row, `
		SELECT station_id, station_name, solar_system_id
		FROM sde_stations WHERE station_id = ?`, stationID)
	if err == sql.ErrNoRows {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	out := &esi.LocalStation{StationID: row.StationID, Name: row.Name}
	if row.SolarSystemID.Valid {
		v := row.SolarSystemID.Int64
		out.SolarSystemID = &v
	}
	return out, nil
}
