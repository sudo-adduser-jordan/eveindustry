package jobs

import (
	"archive/zip"
	"bufio"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"time"

	"github.com/sudo-adduser-jordan/eveindustry/internal/store"
)

// sdeBaseURL mirrors SdeImportService::SDE_BASE_URL.
const sdeBaseURL = "https://developers.eveonline.com/static-data/eve-online-static-data-latest-jsonl.zip"

// RegisterSDEJobs wires sde:import (default, 1 try, 1h), esi:import-types
// (updates, chunks of 1000), sde:import-blueprints (updates) and
// esi:import-market-groups (updates).
func RegisterSDEJobs(r *Runner, h HandlerDeps) {
	r.Register("sde-import", JobDef{
		Queue: QueueDefault, MaxTries: 1, Timeout: time.Hour,
		Run: func(ctx context.Context, d Deps, raw json.RawMessage) error {
			var p struct {
				Force    bool     `json:"force"`
				Datasets []string `json:"datasets"`
			}
			_ = json.Unmarshal(raw, &p)
			return ImportSDE(ctx, h, p.Force, p.Datasets)
		},
	})
	r.Register("process-eve-type-chunk", JobDef{
		Queue: QueueUpdates, MaxTries: 3, Timeout: 600 * time.Second,
		Run: func(ctx context.Context, d Deps, raw json.RawMessage) error {
			var p struct {
				TypeIDs       []int `json:"type_ids"`
				PublishedOnly bool  `json:"published_only"`
			}
			if err := json.Unmarshal(raw, &p); err != nil {
				return nil
			}
			return ProcessEveTypeChunk(ctx, h, p.TypeIDs, p.PublishedOnly)
		},
	})
	r.Register("process-blueprint-data-chunk", JobDef{
		Queue: QueueUpdates, MaxTries: 3, Timeout: 600 * time.Second,
		Run: func(ctx context.Context, d Deps, raw json.RawMessage) error {
			var p struct {
				TypeIDs []int `json:"type_ids"`
			}
			if err := json.Unmarshal(raw, &p); err != nil {
				return nil
			}
			return ProcessBlueprintDataChunk(ctx, h, p.TypeIDs)
		},
	})
	r.Register("process-market-group-chunk", JobDef{
		Queue: QueueUpdates, MaxTries: 3, Timeout: 600 * time.Second,
		Run: func(ctx context.Context, d Deps, raw json.RawMessage) error {
			var p struct {
				GroupIDs []int `json:"group_ids"`
			}
			if err := json.Unmarshal(raw, &p); err != nil {
				return nil
			}
			return ProcessMarketGroupChunk(ctx, h, p.GroupIDs)
		},
	})
}

// SDESchedule mirrors the static-data cadences.
func SDESchedule(h HandlerDeps) []ScheduleEntry {
	return []ScheduleEntry{
		{
			Name: "sde:import", Every: 24 * time.Hour, At: "02:00", Overlap: 24 * time.Hour,
			Run: func(ctx context.Context, r *Runner, q Queue) error {
				return r.Dispatch(ctx, q, "sde-import", nil)
			},
		},
		{
			Name: "esi:import-types", Every: 7 * 24 * time.Hour, Overlap: 7 * 24 * time.Hour,
			Run: func(ctx context.Context, r *Runner, q Queue) error {
				ids, err := UniverseTypeIDs(ctx, h)
				if err != nil {
					return err
				}
				for _, chunk := range chunkInts(ids, 1000) {
					raw, _ := json.Marshal(map[string]any{"type_ids": chunk, "published_only": false})
					if err := r.Dispatch(ctx, q, "process-eve-type-chunk", raw); err != nil {
						return err
					}
				}
				return nil
			},
		},
		{
			Name: "sde:import-blueprints", Every: 7 * 24 * time.Hour, Overlap: 7 * 24 * time.Hour,
			Run: func(ctx context.Context, r *Runner, q Queue) error {
				maxBP, err := h.DB.MaxBlueprintTypeID(ctx)
				if err != nil {
					return err
				}
				var ids []int
				for id := int64(1); id <= maxBP+1000; id += 1000 {
					end := id + 999
					if end > maxBP+1000 {
						end = maxBP + 1000
					}
					_ = end
					var chunk []int
					for i := id; i <= end; i++ {
						chunk = append(chunk, int(i))
					}
					ids = chunk
					raw, _ := json.Marshal(map[string]any{"type_ids": ids})
					if err := r.Dispatch(ctx, q, "process-blueprint-data-chunk", raw); err != nil {
						return err
					}
				}
				return nil
			},
		},
		{
			Name: "esi:import-market-groups", Every: 24 * time.Hour, Overlap: 24 * time.Hour,
			Run: func(ctx context.Context, r *Runner, q Queue) error {
				// 1st and 15th of the month at 03:00 like the cron line.
				now := time.Now().UTC()
				if (now.Day() != 1 && now.Day() != 15) || now.Hour() != 3 {
					return nil
				}
				ids, err := h.ESIMarket.GetMarketGroupIDs(ctx)
				if err != nil {
					return err
				}
				for _, chunk := range chunkInts(ids, 500) {
					raw, _ := json.Marshal(map[string]any{"group_ids": chunk})
					if err := r.Dispatch(ctx, q, "process-market-group-chunk", raw); err != nil {
						return err
					}
				}
				return nil
			},
		},
	}
}

// ImportSDE ports the sde:import command: checksum gate, zip download, and
// per-dataset JSONL imports.
func ImportSDE(ctx context.Context, h HandlerDeps, force bool, datasets []string) error {
	all := []string{"systems", "categories", "groups", "types", "marketgroups", "stations", "stargates", "materials", "blueprints"}
	if len(datasets) == 0 {
		datasets = all
	}
	want := map[string]bool{}
	for _, d := range datasets {
		want[d] = true
	}
	if !force {
		remote, err := sdeRemoteChecksum(ctx)
		if err != nil || remote == "" {
			return err
		}
		if h.Cache != nil {
			var stored string
			if h.Cache.GetJSON(ctx, "sde.last_checksum", &stored) && stored == remote {
				h.logger().Info("sde up to date")
				return nil
			}
		}
		defer func() {
			if h.Cache != nil {
				if r, err := sdeRemoteChecksum(ctx); err == nil && r != "" {
					h.Cache.SetJSON(ctx, "sde.last_checksum", r, 0)
				}
			}
		}()
	}
	dir, err := os.MkdirTemp("", "sde_*")
	if err != nil {
		return err
	}
	defer os.RemoveAll(dir)
	zipPath := filepath.Join(dir, "sde-latest.zip")
	if err := downloadFile(ctx, sdeBaseURL, zipPath, 600*time.Second); err != nil {
		return err
	}
	defer os.Remove(zipPath)
	zr, err := zip.OpenReader(zipPath)
	if err != nil {
		return err
	}
	defer zr.Close()
	files := map[string]*zip.File{}
	for _, f := range zr.File {
		files[f.Name] = f
	}
	openJSONL := func(name string) (io.ReadCloser, error) {
		f, ok := files[name]
		if !ok {
			return nil, fmt.Errorf("missing %s in SDE zip", name)
		}
		return f.Open()
	}
	importers := map[string]func() error{
		"systems":      func() error { return importSystems(ctx, h, openJSONL) },
		"categories":   func() error { return importCategories(ctx, h, openJSONL) },
		"groups":       func() error { return importGroups(ctx, h, openJSONL) },
		"types":        func() error { return importTypes(ctx, h, openJSONL) },
		"marketgroups": func() error { return importMarketGroups(ctx, h, openJSONL) },
		"stations":     func() error { return importStations(ctx, h, openJSONL) },
		"stargates":    func() error { return importStargates(ctx, h, openJSONL) },
		"materials":    func() error { return importMaterials(ctx, h, openJSONL) },
		"blueprints":   func() error { return importBlueprints(ctx, h, openJSONL) },
	}
	for _, name := range all {
		if !want[name] {
			continue
		}
		h.logger().Info("sde importing", "dataset", name)
		if err := importers[name](); err != nil {
			return fmt.Errorf("sde %s: %w", name, err)
		}
	}
	return nil
}

func eachJSONL(open func(string) (io.ReadCloser, error), name string, fn func(map[string]any) error) error {
	rc, err := open(name)
	if err != nil {
		return err
	}
	defer rc.Close()
	sc := bufio.NewScanner(rc)
	sc.Buffer(make([]byte, 1024*1024), 16*1024*1024)
	for sc.Scan() {
		line := strings.TrimSpace(sc.Text())
		if line == "" {
			continue
		}
		var m map[string]any
		if err := json.Unmarshal([]byte(line), &m); err != nil {
			continue
		}
		if err := fn(m); err != nil {
			return err
		}
	}
	return sc.Err()
}

func locStr(v any, def string) string {
	if s, ok := v.(string); ok {
		return s
	}
	if m, ok := v.(map[string]any); ok {
		if s, ok := m["en"].(string); ok && s != "" {
			return s
		}
		for _, val := range m {
			if s, ok := val.(string); ok && s != "" {
				return s
			}
		}
	}
	return def
}

func intOrAny(v any) *int64 {
	switch n := v.(type) {
	case int:
		v := int64(n)
		return &v
	case int64:
		return &n
	case float64:
		v := int64(n)
		return &v
	}
	return nil
}

func floatOrAny(v any) *float64 {
	switch n := v.(type) {
	case int:
		v := float64(n)
		return &v
	case int64:
		v := float64(n)
		return &v
	case float64:
		return &n
	}
	return nil
}

func importTypes(ctx context.Context, h HandlerDeps, open func(string) (io.ReadCloser, error)) error {
	var batch []store.SdeType
	flush := func() error {
		if len(batch) == 0 {
			return nil
		}
		err := h.DB.UpsertEveTypes(ctx, batch)
		batch = nil
		return err
	}
	err := eachJSONL(open, "types.jsonl", func(row map[string]any) error {
		id, _ := numOf(row["_key"])
		if id == 0 {
			return nil
		}
		batch = append(batch, store.SdeType{
			TypeID: int(id), Name: locStr(row["name"], "Unknown"), Description: locStr(row["description"], ""),
			GroupID: intOrAny(row["groupID"]), MarketGroupID: intOrAny(row["marketGroupID"]),
			Volume: floatOrAny(row["volume"]), PackagedVolume: floatOrAny(row["packagedVolume"]),
			Mass: floatOrAny(row["mass"]), Capacity: floatOrAny(row["capacity"]),
			PortionSize: intOrAny(row["portionSize"]), IconID: intOrAny(row["iconID"]),
			GraphicID: intOrAny(row["graphicID"]), MetaGroupID: intOrAny(row["metaGroupID"]),
			Published: boolOr(row["published"], false),
		})
		if len(batch) >= 500 {
			return flush()
		}
		return nil
	})
	if err != nil {
		return err
	}
	return flush()
}

func importStations(ctx context.Context, h HandlerDeps, open func(string) (io.ReadCloser, error)) error {
	var batch []store.SdeStation
	flush := func() error {
		if len(batch) == 0 {
			return nil
		}
		err := h.DB.UpsertStations(ctx, batch)
		batch = nil
		return err
	}
	names := map[int]string{}
	err := eachJSONL(open, "npcStations.jsonl", func(row map[string]any) error {
		id, _ := numOf(row["_key"])
		if id == 0 {
			return nil
		}
		tid, _ := numOf(row["typeID"])
		name := names[int(tid)]
		if name == "" {
			if t, err := h.DB.EveTypeName(ctx, int(tid)); err == nil {
				name = t
				names[int(tid)] = t
			}
		}
		if name == "" {
			name = "Station " + itoaJob(int(id))
		}
		batch = append(batch, store.SdeStation{
			StationID: uint64(id), Name: name,
			SolarSystemID: intPtr(row["solarSystemID"]), ConstellationID: intPtr(row["constellationID"]),
			RegionID: intPtr(row["regionID"]), TypeID: intPtr(row["typeID"]),
			CorporationID: intPtr(row["ownerID"]), Reprocessing: floatPtrAny(row["reprocessingEfficiency"]),
			Security: floatPtrAny(row["security"]),
		})
		if len(batch) >= 500 {
			return flush()
		}
		return nil
	})
	if err != nil {
		return err
	}
	return flush()
}

func importSystems(ctx context.Context, h HandlerDeps, open func(string) (io.ReadCloser, error)) error {
	var batch []store.SdeSystem
	flush := func() error {
		if len(batch) == 0 {
			return nil
		}
		err := h.DB.UpsertSolarSystems(ctx, batch)
		batch = nil
		return err
	}
	err := eachJSONL(open, "mapSolarSystems.jsonl", func(row map[string]any) error {
		id, _ := numOf(row["_key"])
		if id == 0 {
			return nil
		}
		batch = append(batch, store.SdeSystem{
			SystemID: int(id), Name: locStr(row["solarSystemName"], "Unknown"),
			ConstellationID: intPtr(row["constellationID"]), RegionID: intPtr(row["regionID"]),
			Security: floatPtrAny(row["security"]), SecurityClass: strPtrAny(row["securityClass"]),
		})
		if len(batch) >= 500 {
			return flush()
		}
		return nil
	})
	if err != nil {
		return err
	}
	return flush()
}

func importStargates(ctx context.Context, h HandlerDeps, open func(string) (io.ReadCloser, error)) error {
	var batch []store.SdeStargate
	flush := func() error {
		if len(batch) == 0 {
			return nil
		}
		err := h.DB.UpsertStargates(ctx, batch)
		batch = nil
		return err
	}
	err := eachJSONL(open, "mapStargates.jsonl", func(row map[string]any) error {
		id, _ := numOf(row["_key"])
		if id == 0 {
			return nil
		}
		dest, _ := numOf(row["destinationStargateID"])
		var destP *uint64
		if dest != 0 {
			v := uint64(dest)
			destP = &v
		}
		batch = append(batch, store.SdeStargate{
			GateID: uint64(id), SystemID: intPtr(row["solarSystemID"]),
			DestGateID: destP, TypeID: intPtr(row["typeID"]),
		})
		if len(batch) >= 500 {
			return flush()
		}
		return nil
	})
	if err != nil {
		return err
	}
	return flush()
}

func importMaterials(ctx context.Context, h HandlerDeps, open func(string) (io.ReadCloser, error)) error {
	var batch []store.SdeMaterial
	flush := func() error {
		if len(batch) == 0 {
			return nil
		}
		err := h.DB.UpsertTypeMaterials(ctx, batch)
		batch = nil
		return err
	}
	err := eachJSONL(open, "typeMaterials.jsonl", func(row map[string]any) error {
		tid, _ := numOf(row["_key"])
		if tid == 0 {
			return nil
		}
		if mats, ok := row["materials"].([]any); ok {
			for _, mv := range mats {
				m, _ := mv.(map[string]any)
				if m == nil {
					continue
				}
				mid, _ := numOf(firstKey(m, "materialTypeID", "material_type_id", "typeID", "type_id"))
				qty, _ := numOf(m["quantity"])
				if mid == 0 || qty == 0 {
					continue
				}
				batch = append(batch, store.SdeMaterial{TypeID: int(tid), MaterialTypeID: int(mid), Quantity: int(qty)})
			}
		}
		if len(batch) >= 1000 {
			return flush()
		}
		return nil
	})
	if err != nil {
		return err
	}
	return flush()
}

func importBlueprints(ctx context.Context, h HandlerDeps, open func(string) (io.ReadCloser, error)) error {
	names := map[int]string{}
	typeName := func(id int) string {
		if n, ok := names[id]; ok {
			return n
		}
		n, _ := h.DB.EveTypeName(ctx, id)
		if n == "" {
			n = "Type " + itoaJob(id)
		}
		names[id] = n
		return n
	}
	activityIDs := map[string]int{"manufacturing": 1, "research_material": 4, "research_time": 3, "copying": 5, "invention": 8, "reactions": 11}
	var batch []store.SdeBlueprint
	flush := func() error {
		if len(batch) == 0 {
			return nil
		}
		err := h.DB.UpsertBlueprintActivities(ctx, batch)
		batch = nil
		return err
	}
	err := eachJSONL(open, "blueprints.jsonl", func(row map[string]any) error {
		bp, _ := numOf(firstKey(row, "blueprintTypeID", "_key"))
		if bp == 0 {
			return nil
		}
		acts, _ := row["activities"].(map[string]any)
		for name, av := range acts {
			actID, ok := activityIDs[name]
			if !ok {
				continue
			}
			a, _ := av.(map[string]any)
			if a == nil {
				continue
			}
			mats := resolveSDETypeList(a["materials"], typeName)
			prods := resolveSDETypeList(a["products"], typeName)
			mj, _ := json.Marshal(mats)
			pj, _ := json.Marshal(prods)
			t, _ := numOf(a["time"])
			batch = append(batch, store.SdeBlueprint{
				BlueprintTypeID: int(bp), ActivityID: actID,
				MaterialsJSON: string(mj), ProductsJSON: string(pj), Time: int(t),
			})
		}
		if len(batch) >= 200 {
			return flush()
		}
		return nil
	})
	if err != nil {
		return err
	}
	return flush()
}

func resolveSDETypeList(v any, nameOf func(int) string) []map[string]any {
	list, _ := v.([]any)
	out := make([]any, 0, len(list))
	for _, item := range list {
		m, _ := item.(map[string]any)
		if m == nil {
			continue
		}
		tid, _ := numOf(firstKey(m, "typeID", "type_id"))
		qty, _ := numOf(m["quantity"])
		if tid == 0 {
			continue
		}
		out = append(out, map[string]any{"type_id": int(tid), "name": nameOf(int(tid)), "quantity": int(qty)})
	}
	raw, _ := json.Marshal(out)
	var back []map[string]any
	_ = json.Unmarshal(raw, &back)
	return back
}

func importMarketGroups(ctx context.Context, h HandlerDeps, open func(string) (io.ReadCloser, error)) error {
	var batch []store.SdeMarketGroup
	flush := func() error {
		if len(batch) == 0 {
			return nil
		}
		err := h.DB.UpsertMarketGroups(ctx, batch)
		batch = nil
		return err
	}
	err := eachJSONL(open, "marketGroups.jsonl", func(row map[string]any) error {
		id, _ := numOf(row["_key"])
		if id == 0 {
			return nil
		}
		batch = append(batch, store.SdeMarketGroup{
			GroupID: int(id), Name: locStr(row["name"], "Unknown"),
			Description: strPtrOrNil(row["description"]), ParentGroupID: intPtr(row["parentGroupID"]),
			IconID: intPtr(row["iconID"]), HasTypes: boolOr(row["hasTypes"], false),
		})
		if len(batch) >= 500 {
			return flush()
		}
		return nil
	})
	if err != nil {
		return err
	}
	return flush()
}

func importGroups(ctx context.Context, h HandlerDeps, open func(string) (io.ReadCloser, error)) error {
	var batch []store.SdeGroup
	flush := func() error {
		if len(batch) == 0 {
			return nil
		}
		err := h.DB.UpsertInvGroups(ctx, batch)
		batch = nil
		return err
	}
	err := eachJSONL(open, "groups.jsonl", func(row map[string]any) error {
		id, _ := numOf(row["_key"])
		if id == 0 {
			return nil
		}
		batch = append(batch, store.SdeGroup{
			GroupID: int(id), Name: locStr(row["name"], "Unknown"),
			CategoryID: intPtr(row["categoryID"]), Published: boolOr(row["published"], false),
		})
		if len(batch) >= 500 {
			return flush()
		}
		return nil
	})
	if err != nil {
		return err
	}
	return flush()
}

func importCategories(ctx context.Context, h HandlerDeps, open func(string) (io.ReadCloser, error)) error {
	var batch []store.SdeCategory
	flush := func() error {
		if len(batch) == 0 {
			return nil
		}
		err := h.DB.UpsertInvCategories(ctx, batch)
		batch = nil
		return err
	}
	err := eachJSONL(open, "categories.jsonl", func(row map[string]any) error {
		id, _ := numOf(row["_key"])
		if id == 0 {
			return nil
		}
		batch = append(batch, store.SdeCategory{
			CategoryID: int(id), Name: locStr(row["name"], "Unknown"), Published: boolOr(row["published"], false),
		})
		if len(batch) >= 500 {
			return flush()
		}
		return nil
	})
	if err != nil {
		return err
	}
	return flush()
}

func firstKey(m map[string]any, keys ...string) any {
	for _, k := range keys {
		if v, ok := m[k]; ok && v != nil {
			return v
		}
	}
	return nil
}

func intPtrAny(v any) *int64 {
	if n, ok := numOf(v); ok {
		val := int64(n)
		return &val
	}
	return nil
}

func intPtr(v any) *int {
	if n, ok := numOf(v); ok {
		val := int(n)
		return &val
	}
	return nil
}

func boolOr(v any, def bool) bool {
	if b, ok := v.(bool); ok {
		return b
	}
	return def
}

func strPtrOrNil(v any) *string {
	if s, ok := v.(string); ok && s != "" {
		return &s
	}
	if m, ok := v.(map[string]any); ok {
		if s, ok := m["en"].(string); ok && s != "" {
			return &s
		}
	}
	return nil
}

func importSDEFile(open func(string) (io.ReadCloser, error), name string, fn func(map[string]any) error) error {
	rc, err := open(name)
	if err != nil {
		return err
	}
	defer rc.Close()
	sc := bufio.NewScanner(rc)
	sc.Buffer(make([]byte, 1024*1024), 16*1024*1024)
	for sc.Scan() {
		line := strings.TrimSpace(sc.Text())
		if line == "" {
			continue
		}
		var m map[string]any
		if err := json.Unmarshal([]byte(line), &m); err != nil {
			continue
		}
		if err := fn(m); err != nil {
			return err
		}
	}
	return sc.Err()
}

// UniverseTypeIDs ports the esi:import-types ID enumeration.
func UniverseTypeIDs(ctx context.Context, h HandlerDeps) ([]int, error) {
	var out []int
	page := 1
	for {
		list, total, err := h.ESIUniverse.GetUniverseTypeIDs(ctx, page)
		if err != nil {
			return out, err
		}
		out = append(out, list...)
		if page >= total || len(list) == 0 {
			break
		}
		page++
	}
	return out, nil
}

// ProcessEveTypeChunk ports the chunk job: ESI type info into eve_types.
func ProcessEveTypeChunk(ctx context.Context, h HandlerDeps, typeIDs []int, publishedOnly bool) error {
	var rows []store.SdeType
	for _, chunk := range chunkInts(typeIDs, 50) {
		for _, id := range chunk {
			info, err := h.ESIUniverse.GetTypeInfo(ctx, id)
			if err != nil || info == nil {
				continue
			}
			if publishedOnly {
				if pub, _ := info["published"].(bool); !pub {
					continue
				}
			}
			rows = append(rows, store.SdeType{
				TypeID:      id,
				Name:        locStr(info["name"], "Unknown"),
				Description: locStr(info["description"], ""),
				GroupID:     intPtrAny(info["group_id"]), MarketGroupID: intPtrAny(info["market_group_id"]),
				Volume: floatPtrAny(info["volume"]), PackagedVolume: floatPtrAny(info["packaged_volume"]),
				Mass: floatPtrAny(info["mass"]), Capacity: floatPtrAny(info["capacity"]),
				PortionSize: intPtrAny(info["portion_size"]), IconID: intPtrAny(info["icon_id"]),
				GraphicID: intPtrAny(info["graphic_id"]), MetaGroupID: intPtrAny(info["meta_group_id"]),
				Published: isTrue(info["published"]),
			})
			if len(rows) >= 500 {
				if err := h.DB.UpsertEveTypes(ctx, rows); err != nil {
					return err
				}
				rows = nil
			}
		}
	}
	if len(rows) > 0 {
		return h.DB.UpsertEveTypes(ctx, rows)
	}
	return nil
}

// ProcessBlueprintDataChunk ports the Fuzzwork chunk job.
func ProcessBlueprintDataChunk(ctx context.Context, h HandlerDeps, typeIDs []int) error {
	var rows []store.SdeBlueprint
	for _, id := range typeIDs {
		mats, prods, secs, err := fetchFuzzworkBlueprint(ctx, id)
		if err != nil || len(mats) == 0 {
			continue
		}
		mj, _ := json.Marshal(mats)
		pj, _ := json.Marshal(prods)
		for act, t := range secs {
			rows = append(rows, store.SdeBlueprint{
				BlueprintTypeID: id, ActivityID: act,
				MaterialsJSON: string(mj), ProductsJSON: string(pj), Time: t,
			})
		}
		if len(rows) >= 200 {
			if err := h.DB.UpsertBlueprintActivities(ctx, rows); err != nil {
				return err
			}
			rows = nil
		}
	}
	if len(rows) > 0 {
		return h.DB.UpsertBlueprintActivities(ctx, rows)
	}
	return nil
}

// ProcessMarketGroupChunk ports the market-group tree chunk.
func ProcessMarketGroupChunk(ctx context.Context, h HandlerDeps, groupIDs []int) error {
	var rows []store.SdeMarketGroup
	for _, id := range groupIDs {
		info, err := h.ESIMarket.GetMarketGroupInfo(ctx, id)
		if err != nil || info == nil {
			continue
		}
		name, _ := info["name"].(string)
		g := store.SdeMarketGroup{GroupID: id, Name: name}
		if d, ok := info["description"].(string); ok {
			g.Description = &d
		}
		if p, ok := numOf(info["parent_group_id"]); ok {
			v := int(p)
			g.ParentGroupID = &v
		}
		if ic, ok := numOf(info["icon_id"]); ok {
			v := int(ic)
			g.IconID = &v
		}
		rows = append(rows, g)
		if len(rows) >= 500 {
			if err := h.DB.UpsertMarketGroups(ctx, rows); err != nil {
				return err
			}
			rows = nil
		}
	}
	if len(rows) > 0 {
		return h.DB.UpsertMarketGroups(ctx, rows)
	}
	return nil
}

func sdeRemoteChecksum(ctx context.Context) (string, error) {
	req, err := http.NewRequestWithContext(ctx, http.MethodHead, sdeBaseURL, nil)
	if err != nil {
		return "", err
	}
	resp, err := http.DefaultClient.Do(req)
	if err != nil {
		return "", err
	}
	defer resp.Body.Close()
	for _, h := range []string{"ETag", "Last-Modified", "Content-Length"} {
		if v := resp.Header.Get(h); v != "" {
			return h + ":" + v, nil
		}
	}
	return "", fmt.Errorf("no checksum headers")
}

func floatPtrAny(v any) *float64 {
	if n, ok := numOf(v); ok {
		val := n
		return &val
	}
	return nil
}

func strPtrAny(v any) *string {
	if s, ok := v.(string); ok && s != "" {
		return &s
	}
	if m, ok := v.(map[string]any); ok {
		if e, ok := m["en"].(string); ok && e != "" {
			return &e
		}
	}
	return nil
}

func isTrue(v any) bool {
	b, _ := v.(bool)
	return b
}

// fetchFuzzworkBlueprint ports the per-type Fuzzwork lookup with activity
// materials, products and times.
func fetchFuzzworkBlueprint(ctx context.Context, typeID int) (mats []map[string]any, prods []map[string]any, secs map[int]int, err error) {
	secs = map[int]int{}
	url := "https://www.fuzzwork.co.uk/blueprint/api/blueprint.php?typeid=" + itoa(int64(typeID))
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, url, nil)
	if err != nil {
		return nil, nil, secs, err
	}
	resp, err := http.DefaultClient.Do(req)
	if err != nil {
		return nil, nil, secs, err
	}
	defer resp.Body.Close()
	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		return nil, nil, secs, fmt.Errorf("fuzzwork HTTP %d", resp.StatusCode)
	}
	var data struct {
		ActivityMaterials map[string][]map[string]any `json:"activityMaterials"`
		BlueprintDetails  struct {
			Times           map[string]int `json:"times"`
			ProductTypeID   int            `json:"productTypeID"`
			ProductTypeName string         `json:"productTypeName"`
			ProductQuantity int            `json:"productQuantity"`
		} `json:"blueprintDetails"`
	}
	if err := json.NewDecoder(io.LimitReader(resp.Body, 8<<20)).Decode(&data); err != nil {
		return nil, nil, secs, err
	}
	actIDs := map[string]int{"manufacturing": 1, "research_material": 4, "research_time": 3, "copying": 5, "invention": 8, "reactions": 11}
	for actName, list := range data.ActivityMaterials {
		actID, ok := actIDs[actName]
		if !ok {
			continue
		}
		for _, m := range list {
			tid, _ := numOf(firstFuzz(m, "typeid", "typeID", "type_id"))
			qty, _ := numOf(m["quantity"])
			name, _ := m["name"].(string)
			if tid == 0 {
				continue
			}
			mats = append(mats, map[string]any{"type_id": int(tid), "name": name, "quantity": int(qty)})
		}
		if t, ok := data.BlueprintDetails.Times[actName]; ok {
			secs[actID] = t
		}
	}
	prods = []map[string]any{{
		"type_id": data.BlueprintDetails.ProductTypeID,
		"name":    data.BlueprintDetails.ProductTypeName,
		"quantity": func() int {
			if data.BlueprintDetails.ProductQuantity == 0 {
				return 1
			}
			return data.BlueprintDetails.ProductQuantity
		}(),
	}}
	return mats, prods, secs, nil
}

func firstFuzz(m map[string]any, keys ...string) any {
	for _, k := range keys {
		if v, ok := m[k]; ok && v != nil {
			return v
		}
	}
	return nil
}
