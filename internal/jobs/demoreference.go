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
	Types      []store.SdeType
	Stations   []store.SdeStation
	Systems    []store.SdeSystem
	Groups     []store.SdeGroup
	Categories []store.SdeCategory
}

// demoReferenceTypeIDs lists every static demo type: mineral and isotope
// stacks plus ships and abyssal modules (sources, mutators, mutated).
// Sorted for stable seeding order.
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
	for _, ab := range esi.DemoAbyssal {
		add(ab.Source)
		add(ab.Mutator)
		add(ab.Mutated)
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
			info = nil
		}
		name, _ := mapStr(info)["name"].(string)
		var gid *int64
		if g, ok := intOfESI(mapStr(info)["group_id"]); ok && g > 0 {
			gid = &g
		}
		pub, _ := mapStr(info)["published"].(bool)
		if name == "" {
			// Offline fallback: static demo names so ships/abyssal tabs still
			// render without ESI.
			if fb, ok := esi.DemoTypeFallbacks[id]; ok {
				name = fb.Name
				g := int64(fb.Group)
				gid = &g
				pub = true
			}
		}
		if name == "" {
			continue
		}
		row := store.SdeType{TypeID: id, Name: name, GroupID: gid, Published: pub}
		ref.Types = append(ref.Types, row)
	}
	// Mutated abyssal types are demo-only: ESI has no such type, so force the
	// abyssal meta group and a source-matching group for each.
	meta := int64(15)
	byID := map[int]*store.SdeType{}
	for i := range ref.Types {
		byID[ref.Types[i].TypeID] = &ref.Types[i]
	}
	for _, ab := range esi.DemoAbyssal {
		name := esi.DemoAbyssalNames[ab.Mutated]
		if name == "" {
			name = "Abyssal Module " + itoaDemo(ab.Mutated)
		}
		var gid *int64
		if srcRow, ok := byID[ab.Source]; ok && srcRow.GroupID != nil {
			gid = srcRow.GroupID
		} else if fb, ok := esi.DemoTypeFallbacks[ab.Source]; ok {
			g := int64(fb.Group)
			gid = &g
		}
		if row, ok := byID[ab.Mutated]; ok {
			row.Name = name
			row.GroupID = gid
			row.MetaGroupID = &meta
			row.Published = true
		} else {
			ref.Types = append(ref.Types, store.SdeType{
				TypeID: ab.Mutated, Name: name, GroupID: gid,
				MetaGroupID: &meta, Published: true,
			})
			byID[ab.Mutated] = &ref.Types[len(ref.Types)-1]
		}
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
	// Ship/module groups and categories: without these GroupsInCategory(6)
	// is empty and the ships tab renders empty.
	groupCat := map[int]int{25: 6, 27: 6, 419: 6, 463: 6, 60: 7, 38: 7, 77: 7, 74: 7, 1964: 17}
	for _, g := range esi.DemoShipGroups {
		cat := groupCat[g.GroupID]
		c := cat
		ref.Groups = append(ref.Groups, store.SdeGroup{GroupID: g.GroupID, Name: g.Name, CategoryID: &c, Published: true})
	}
	for _, c := range esi.DemoShipCategories {
		ref.Categories = append(ref.Categories, store.SdeCategory{CategoryID: c.CategoryID, Name: c.Name, Published: true})
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
	if len(ref.Groups) > 0 {
		if err := h.DB.UpsertInvGroups(ctx, ref.Groups); err != nil {
			h.logger().Warn("demo reference groups failed", "err", err)
		}
	}
	if len(ref.Categories) > 0 {
		if err := h.DB.UpsertInvCategories(ctx, ref.Categories); err != nil {
			h.logger().Warn("demo reference categories failed", "err", err)
		}
	}
}

func mapStr(m map[string]any) map[string]any {
	if m == nil {
		return map[string]any{}
	}
	return m
}

func itoaDemo(i int) string {
	if i == 0 {
		return "0"
	}
	neg := i < 0
	if neg {
		i = -i
	}
	var b [20]byte
	p := len(b)
	for i > 0 {
		p--
		b[p] = byte('0' + i%10)
		i /= 10
	}
	if neg {
		p--
		b[p] = '-'
	}
	return string(b[p:])
}
