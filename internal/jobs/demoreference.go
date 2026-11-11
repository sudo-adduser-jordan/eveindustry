package jobs

import (
	"context"
	"sort"

	"github.com/sudo-adduser-jordan/eveindustry/internal/esi"
	"github.com/sudo-adduser-jordan/eveindustry/internal/store"
)

// demoReferenceSource is the ESI surface reference seeding needs. The worker
// universe client satisfies it; tests stub it.
type demoReferenceSource interface {
	GetTypeInfo(ctx context.Context, typeID int) (map[string]any, error)
	GetStation(ctx context.Context, stationID uint64) (map[string]any, error)
	GetSystem(ctx context.Context, systemID int) (map[string]any, error)
}

// demoReference is the set of reference rows the demo needs to render.
type demoReference struct {
	Types    []store.SdeType
	Stations []store.SdeStation
	Systems  []store.SdeSystem
}

// demoReferenceTypeIDs lists every static demo type: mineral and isotope
// stacks plus ships (materials grouping and type names). Sorted for stable
// seeding order.
func demoReferenceTypeIDs() []int {
	seen := map[int]bool{}
	var out []int
	add := func(id int) {
		if !seen[id] {
			seen[id] = true
			out = append(out, id)
		}
	}
	for id := range esi.DemoMinerals {
		add(id)
	}
	for _, id := range esi.DemoIsotopes {
		add(id)
	}
	for _, id := range esi.DemoShips {
		add(id)
	}
	sort.Ints(out)
	return out
}

// collectDemoReference fetches the demo's reference rows from ESI. Rows that
// fail to resolve are skipped: the demo renders with "Type N" fallbacks until
// the real SDE import fills them in.
func collectDemoReference(ctx context.Context, src demoReferenceSource) demoReference {
	var ref demoReference
	for _, id := range demoReferenceTypeIDs() {
		info, err := src.GetTypeInfo(ctx, id)
		if err != nil || info == nil {
			continue
		}
		name, _ := info["name"].(string)
		if name == "" {
			continue
		}
		row := store.SdeType{TypeID: id, Name: name}
		if gid, ok := intOfESI(info["group_id"]); ok && gid > 0 {
			row.GroupID = &gid
		}
		if pub, _ := info["published"].(bool); pub {
			row.Published = true
		}
		ref.Types = append(ref.Types, row)
	}
	seenSys := map[int]bool{}
	for _, hub := range esi.DemoHubs {
		st, err := src.GetStation(ctx, uint64(hub.StationID))
		if err != nil || st == nil {
			continue
		}
		name, _ := st["name"].(string)
		if name == "" {
			continue
		}
		row := store.SdeStation{StationID: uint64(hub.StationID), Name: name}
		if sys, ok := intOfESI(st["system_id"]); ok && sys > 0 {
			v := int(sys)
			row.SolarSystemID = &v
		}
		ref.Stations = append(ref.Stations, row)
		if row.SolarSystemID == nil || seenSys[*row.SolarSystemID] {
			continue
		}
		seenSys[*row.SolarSystemID] = true
		sys, err := src.GetSystem(ctx, *row.SolarSystemID)
		if err != nil || sys == nil {
			continue
		}
		sname, _ := sys["name"].(string)
		if sname == "" {
			continue
		}
		sysRow := store.SdeSystem{SystemID: *row.SolarSystemID, Name: sname}
		if cid, ok := intOfESI(sys["constellation_id"]); ok && cid > 0 {
			v := int(cid)
			sysRow.ConstellationID = &v
		}
		ref.Systems = append(ref.Systems, sysRow)
	}
	return ref
}

// seedDemoReferenceData ensures the reference rows the demo renders exist:
// type names/groups for demo stocks and ships plus the hub stations/systems.
// Best effort: failures only degrade labels and grouping until the real SDE
// import overwrites these rows via upsert. Runs before the cache warms.
func seedDemoReferenceData(ctx context.Context, h HandlerDeps) {
	if h.DB == nil || h.ESIUniverse == nil {
		return
	}
	ref := collectDemoReference(ctx, h.ESIUniverse)
	if len(ref.Types) > 0 {
		if err := h.DB.UpsertEveTypes(ctx, ref.Types); err != nil {
			h.logger().Warn("demo reference types failed", "err", err)
		}
	}
	if len(ref.Stations) > 0 {
		if err := h.DB.UpsertStations(ctx, ref.Stations); err != nil {
			h.logger().Warn("demo reference stations failed", "err", err)
		}
	}
	if len(ref.Systems) > 0 {
		if err := h.DB.UpsertSolarSystems(ctx, ref.Systems); err != nil {
			h.logger().Warn("demo reference systems failed", "err", err)
		}
	}
}
