package http

import (
	"context"
	"database/sql"
	"encoding/json"
	"net/http"
	"strconv"

	"github.com/labstack/echo/v4"

	"github.com/sudo-adduser-jordan/eveindustry/internal/calc"
	"github.com/sudo-adduser-jordan/eveindustry/internal/store"
)

func registerPrograms(v1 *echo.Group, d Deps) {
	if d.Programs == nil {
		return
	}
	v1.GET("/build-programs", handleBuildProgramsIndex(d))
	v1.GET("/build-programs/:id", handleBuildProgramShow(d))
	v1.POST("/build-programs", handleBuildProgramStore(d))
	v1.POST("/build-programs/from-reaction-plan", handleBuildProgramFromReactionPlan(d))
	v1.POST("/build-programs/merge", handleBuildProgramMerge(d))
	v1.PUT("/build-programs/:id", handleBuildProgramUpdate(d))
	v1.PATCH("/build-programs/:id/location-filter", handleBuildProgramLocationFilter(d))
	v1.DELETE("/build-programs/:id", handleBuildProgramDestroy(d))
}

func programID(c echo.Context) (int64, bool) {
	id, err := strconv.ParseInt(c.Param("id"), 10, 64)
	if err != nil || id < 1 {
		_ = Failure(c, http.StatusNotFound, "Not found.", nil)
		return 0, false
	}
	return id, true
}

func nullInt64Val(v sql.NullInt64) any {
	if v.Valid {
		return v.Int64
	}
	return nil
}

func nullStrVal(v sql.NullString) any {
	if v.Valid {
		return v.String
	}
	return nil
}

func programSummaryShape(p store.BuildProgram) map[string]any {
	steps := decodeSteps(p.StepsJSON)
	settings := decodeSettings(p.SettingsJSON)
	lids := []any{}
	if l, ok := settings["location_ids"].([]any); ok {
		for _, v := range l {
			if n, ok := toFloat(v); ok {
				lids = append(lids, int(n))
			}
		}
	}
	var t2id any
	if p.T2TypeID.Valid {
		t2id = p.T2TypeID.Int64
	}
	var t2name any
	if p.T2TypeName.Valid {
		t2name = p.T2TypeName.String
	}
	return map[string]any{
		"id": p.ID, "name": p.Name, "program_type": p.ProgramType,
		"t2_type_id": t2id, "t2_type_name": t2name,
		"step_count": len(steps), "location_ids": lids,
		"updated_at": p.UpdatedAt.UTC().Format("2006-01-02T15:04:05Z"),
	}
}

func programDetailShape(p store.BuildProgram) (map[string]any, map[string]any) {
	steps := decodeSteps(p.StepsJSON)
	settings := decodeSettings(p.SettingsJSON)
	secs := []any{}
	if s, ok := settings["sections"].([]any); ok {
		for _, v := range s {
			if m, ok := v.(map[string]any); ok {
				secs = append(secs, map[string]any{"name": strOf(m["name"]), "step_count": intOf(m["step_count"])})
			}
		}
	}
	lids := []any{}
	if l, ok := settings["location_ids"].([]any); ok {
		for _, v := range l {
			if n, ok := toFloat(v); ok {
				lids = append(lids, int(n))
			}
		}
	}
	var t2id any
	if p.T2TypeID.Valid {
		t2id = p.T2TypeID.Int64
	}
	var t2name any
	if p.T2TypeName.Valid {
		t2name = p.T2TypeName.String
	}
	data := map[string]any{
		"id": p.ID, "name": p.Name, "program_type": p.ProgramType,
		"t2_type_id": t2id, "t2_type_name": t2name,
		"steps": shapeSteps(steps), "sections": secs, "location_ids": lids,
		"created_at": p.CreatedAt.UTC().Format("2006-01-02T15:04:05Z"),
		"updated_at": p.UpdatedAt.UTC().Format("2006-01-02T15:04:05Z"),
	}
	volumes := map[string]any{}
	var collect func(items []any)
	collect = func(items []any) {
		for _, it := range items {
			m, ok := it.(map[string]any)
			if !ok {
				continue
			}
			if mats, ok := m["materials"].([]any); ok {
				for _, mm := range mats {
					if mmm, ok := mm.(map[string]any); ok {
						if tid, ok := toFloat(mmm["type_id"]); ok {
							volumes[strconv.Itoa(int(tid))] = 0.0
						}
					}
				}
			}
			if ch, ok := m["children"].([]any); ok {
				collect(ch)
			}
		}
	}
	collect(steps)
	return data, volumes
}

func programResourceShape(p store.BuildProgram) map[string]any {
	return map[string]any{
		"id": p.ID, "name": p.Name, "program_type": p.ProgramType,
		"t2_type_id": nullInt64Val(p.T2TypeID), "t2_type_name": nullStrVal(p.T2TypeName),
		"steps":      shapeSteps(decodeSteps(p.StepsJSON)),
		"settings":   decodeSettings(p.SettingsJSON),
		"created_at": p.CreatedAt.UTC().Format("2006-01-02T15:04:05Z"),
		"updated_at": p.UpdatedAt.UTC().Format("2006-01-02T15:04:05Z"),
	}
}

func sqlNullInt(v *int) sql.NullInt64 {
	if v == nil {
		return sql.NullInt64{}
	}
	return sql.NullInt64{Int64: int64(*v), Valid: true}
}

func sqlNullStr(v *string) sql.NullString {
	if v == nil {
		return sql.NullString{}
	}
	return sql.NullString{String: *v, Valid: true}
}

func reactionBonusFromSettings(settings map[string]any) *float64 {
	st, _ := settings["reaction_structure_type"].(string)
	if st == "" {
		return nil
	}
	rig, _ := settings["reaction_rig_me"].(string)
	sec, _ := settings["reaction_system_security"].(string)
	if sec == "" {
		sec = "lowsec"
	}
	v := calcReactionME(rig, sec)
	return &v
}

func calcReactionME(rig, sec string) float64 {
	base := 0.0
	switch rig {
	case "t1":
		base = 2.0
	case "t2":
		base = 2.4
	}
	mult := 1.0
	if sec == "nullsec" {
		mult = 1.1
	}
	return base * mult
}

func handleBuildProgramsIndex(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		uid := c.Get(string(ctxUIDKey)).(int64)
		rows, err := d.Programs.BuildPrograms(c.Request().Context(), uid)
		if err != nil {
			return dbError(c, err)
		}
		out := make([]any, 0, len(rows))
		for _, r := range rows {
			out = append(out, programSummaryShape(r))
		}
		return Success(c, http.StatusOK, out, nil)
	}
}

func handleBuildProgramShow(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		id, ok := programID(c)
		if !ok {
			return errHandled
		}
		uid := c.Get(string(ctxUIDKey)).(int64)
		ctx := c.Request().Context()
		row, err := d.Programs.BuildProgram(ctx, id, uid)
		if err != nil {
			return dbError(c, err)
		}
		if row == nil {
			return Failure(c, http.StatusNotFound, "Not found.", nil)
		}
		data, volumes := programDetailShape(*row)
		ids := map[int]bool{}
		var walk func(items []any)
		walk = func(items []any) {
			for _, it := range items {
				m, ok := it.(map[string]any)
				if !ok {
					continue
				}
				if mats, ok := m["materials"].([]any); ok {
					for _, mm := range mats {
						if mmm, ok := mm.(map[string]any); ok {
							if tid, ok := toFloat(mmm["type_id"]); ok && tid > 0 {
								ids[int(tid)] = true
							}
						}
					}
				}
				if ch, ok := m["children"].([]any); ok {
					walk(ch)
				}
			}
		}
		if steps, ok := data["steps"].([]any); ok {
			walk(steps)
		}
		if len(ids) > 0 && d.Trading != nil {
			idList := make([]int, 0, len(ids))
			for id := range ids {
				idList = append(idList, id)
			}
			if vols, err := d.Trading.ArbitrageTypes(ctx, idList); err != nil {
				return dbError(c, err)
			} else {
				for id, tv := range vols {
					volumes[strconv.Itoa(id)] = tv.VolumeM3
				}
			}
		}
		return Success(c, http.StatusOK, data, map[string]any{"volumes": volumes})
	}
}

func shapeSteps(steps []any) []any {
	out := make([]any, 0, len(steps))
	for _, it := range steps {
		m, ok := it.(map[string]any)
		if !ok {
			continue
		}
		mats := []any{}
		if raw, ok := m["materials"].([]any); ok {
			for _, mm := range raw {
				if mmm, ok := mm.(map[string]any); ok {
					qty := 0
					if v, ok := toFloat(mmm["adjusted_quantity"]); ok && v != 0 {
						qty = int(v)
					} else if v, ok := toFloat(mmm["quantity"]); ok {
						qty = int(v)
					}
					mats = append(mats, map[string]any{
						"type_id": intOf(mmm["type_id"]), "name": strOf(mmm["name"]), "quantity": qty,
					})
				}
			}
		}
		var children []any
		if raw, ok := m["children"].([]any); ok {
			children = shapeSteps(raw)
		} else {
			children = []any{}
		}
		var runs any
		if v, ok := m["runs_needed"]; ok {
			if n, isNum := toFloat(v); isNum {
				runs = int(n)
			}
		}
		var bpID any
		if v, ok := m["blueprint_type_id"]; ok {
			if n, isNum := toFloat(v); isNum {
				bpID = int(n)
			}
		}
		out = append(out, map[string]any{
			"type_id": intOf(m["type_id"]), "name": strOfDefault(m["name"], "Unknown"),
			"quantity_required": intOf(m["quantity_required"]), "runs_needed": runs,
			"activity_type": m["activity_type"], "blueprint_type_id": bpID,
			"materials": mats, "children": children,
		})
	}
	return out
}

func strOf(v any) string {
	if s, ok := v.(string); ok {
		return s
	}
	return ""
}

func strOfDefault(v any, def string) string {
	if s, ok := v.(string); ok && s != "" {
		return s
	}
	return def
}

func intOf(v any) int {
	if n, ok := toFloat(v); ok {
		return int(n)
	}
	return 0
}

func toFloat(v any) (float64, bool) {
	switch n := v.(type) {
	case float64:
		return n, true
	case int:
		return float64(n), true
	case int64:
		return float64(n), true
	}
	return 0, false
}

func handleBuildProgramStore(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		verrs := validationErrors{}
		raw := decodePlanBody(c, verrs)
		if raw == nil && len(verrs) > 0 {
			return verrs.invalid(c)
		}
		name := stringField(raw, verrs, "name", 255, true)
		steps, ok := validateBuildSteps(raw, verrs, true)
		if !ok || len(verrs) > 0 {
			return verrs.invalid(c)
		}
		settings := mapOf(raw, "settings")
		uid := c.Get(string(ctxUIDKey)).(int64)
		ctx := c.Request().Context()
		nested, err := nestProgramSteps(ctx, d, steps, reactionBonusFromSettings(settings))
		if err != nil {
			return dbError(c, err)
		}
		row, err := d.Programs.CreateBuildProgram(ctx, store.BuildProgram{
			UserID: uid, Name: derefStr(name, ""), ProgramType: "manufacturing",
			StepsJSON: marshalSteps(nested), SettingsJSON: marshalSettings(settings),
		})
		if err != nil {
			return dbError(c, err)
		}
		return Success(c, http.StatusCreated, programResourceShape(*row), nil)
	}
}

func nestProgramSteps(ctx context.Context, d Deps, steps []map[string]any, reactionBonus *float64) ([]any, error) {
	mats := make([]calc.ChainMaterial, 0, len(steps))
	metas := make([]map[string]any, 0, len(steps))
	for _, s := range steps {
		sub := []calc.ChainMaterial{}
		if raw, ok := s["materials"].([]any); ok {
			for _, mm := range raw {
				if m, ok := mm.(map[string]any); ok {
					sub = append(sub, chainMaterialOf(m))
				}
			}
		}
		mats = append(mats, sub...)
		metas = append(metas, s)
	}
	_ = metas
	out := make([]any, 0, len(steps))
	for _, s := range steps {
		sub := []calc.ChainMaterial{}
		if raw, ok := s["materials"].([]any); ok {
			for _, mm := range raw {
				if m, ok := mm.(map[string]any); ok {
					sub = append(sub, chainMaterialOf(m))
				}
			}
		}
		children, err := calc.ResolveFullMaterialChain(ctx, d.Industry, sub, 10, 0, map[int]bool{}, reactionBonus)
		if err != nil {
			return nil, err
		}
		kids := make([]any, 0, len(children))
		for _, ch := range children {
			kids = append(kids, chainStepShape(ch))
		}
		tid, _ := toFloat(s["type_id"])
		entry := map[string]any{
			"type_id": int(tid), "name": strOfDefault(s["name"], "Unknown"),
			"quantity_required": intOf(s["quantity_required"]),
			"materials":         s["materials"],
			"children":          kids,
		}
		if v, ok := s["runs_needed"]; ok {
			if n, isNum := toFloat(v); isNum {
				entry["runs_needed"] = int(n)
			}
		}
		act, err := d.Industry.ActivityProducing(ctx, int(tid), 1)
		if err != nil {
			return nil, err
		}
		if act == nil {
			act, err = d.Industry.ActivityProducing(ctx, int(tid), 11)
			if err != nil {
				return nil, err
			}
		}
		if act != nil {
			if act.ActivityID == 11 {
				entry["activity_type"] = "reaction"
			} else {
				entry["activity_type"] = "manufacturing"
			}
			entry["blueprint_type_id"] = act.BlueprintTypeID
		}
		out = append(out, entry)
	}
	return out, nil
}

func chainMaterialOf(m map[string]any) calc.ChainMaterial {
	qty := 0
	if v, ok := toFloat(m["adjusted_quantity"]); ok && v != 0 {
		qty = int(v)
	} else if v, ok := toFloat(m["quantity"]); ok {
		qty = int(v)
	}
	if v, ok := toFloat(m["quantity_required"]); ok && v != 0 {
		qty = int(v)
	}
	return calc.ChainMaterial{TypeID: intOf(m["type_id"]), Name: strOf(m["name"]), AdjustedQuantity: qty, Quantity: qty}
}

func chainStepShape(s calc.ChainStep) map[string]any {
	mats := make([]any, 0, len(s.Materials))
	for _, m := range s.Materials {
		mats = append(mats, map[string]any{
			"type_id": m.TypeID, "name": m.Name,
			"adjusted_quantity": m.AdjustedQuantity, "quantity": m.Quantity,
		})
	}
	kids := make([]any, 0, len(s.Children))
	for _, ch := range s.Children {
		kids = append(kids, chainStepShape(ch))
	}
	return map[string]any{
		"type_id": s.TypeID, "name": s.Name, "quantity_required": s.QuantityRequired,
		"runs_needed": s.RunsNeeded, "portion_size": s.PortionSize,
		"activity_type": s.ActivityType, "blueprint_type_id": s.BlueprintTypeID,
		"materials": mats, "children": kids,
	}
}

func handleBuildProgramFromReactionPlan(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		verrs := validationErrors{}
		raw := decodePlanBody(c, verrs)
		if raw == nil && len(verrs) > 0 {
			return verrs.invalid(c)
		}
		name := stringField(raw, verrs, "name", 255, true)
		entriesRaw, present := raw["entries"]
		if !present || entriesRaw == nil {
			verrs.add("entries", "The entries field is required.")
		}
		var entries []calc.ReactionPlanEntry
		if list, ok := entriesRaw.([]any); ok {
			for _, item := range list {
				m, ok := item.(map[string]any)
				if !ok {
					verrs.add("entries", "Each entry must be an object.")
					break
				}
				fid, ok := intFieldJSON2(map[string]any{"v": m["formula_type_id"]}, verrs, "v", 1, 1<<31-1, true)
				if !ok {
					verrs.add("entries", "Each entry needs an integer formula_type_id.")
					break
				}
				slots, ok := intFieldJSON2(map[string]any{"v": m["slots"]}, verrs, "v", 1, 1000, true)
				if !ok {
					verrs.add("entries", "Each entry needs slots between 1 and 1000.")
					break
				}
				rps, ok := intFieldJSON2(map[string]any{"v": m["runs_per_slot"]}, verrs, "v", 1, 100000, true)
				if !ok {
					verrs.add("entries", "Each entry needs runs_per_slot between 1 and 100000.")
					break
				}
				fname, _ := m["formula_name"].(string)
				entries = append(entries, calc.ReactionPlanEntry{FormulaTypeID: fid, FormulaName: fname, Slots: slots, RunsPerSlot: rps})
			}
		} else if present {
			verrs.add("entries", "The entries field must be an array.")
		}
		settings := mapOf(raw, "settings")
		rigTier, _ := settings["reaction_rig_me"].(string)
		secSpace, _ := settings["reaction_system_security"].(string)
		if secSpace == "" {
			secSpace = "lowsec"
		}
		if len(verrs) > 0 {
			return verrs.invalid(c)
		}
		ctx := c.Request().Context()
		steps, firstID, firstName, err := calc.ReactionPlanSteps(ctx, d.Industry, entries, calc.ResolveRigBonus(rigTier, secSpace))
		if err != nil {
			return dbError(c, err)
		}
		if len(steps) == 0 {
			verrs.add("entries", "No valid reaction entries found")
			return verrs.invalid(c)
		}
		stepOut := make([]any, 0, len(steps))
		for _, s := range steps {
			stepOut = append(stepOut, chainStepShape(s))
		}
		uid := c.Get(string(ctxUIDKey)).(int64)
		var t2id *int
		var t2name *string
		if firstID != nil {
			t2id = firstID
		}
		if firstName != nil {
			t2name = firstName
		}
		row, err := d.Programs.CreateBuildProgram(ctx, store.BuildProgram{
			UserID: uid, Name: derefStr(name, ""), ProgramType: "reaction",
			T2TypeID: sqlNullInt(t2id), T2TypeName: sqlNullStr(t2name),
			StepsJSON: marshalSteps(stepOut), SettingsJSON: marshalSettings(settings),
		})
		if err != nil {
			return dbError(c, err)
		}
		return Success(c, http.StatusCreated, programResourceShape(*row), nil)
	}
}

func handleBuildProgramMerge(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		verrs := validationErrors{}
		raw := decodePlanBody(c, verrs)
		if raw == nil && len(verrs) > 0 {
			return verrs.invalid(c)
		}
		name := stringField(raw, verrs, "name", 255, true)
		var ids []int
		if v, present := raw["program_ids"]; !present || v == nil {
			verrs.add("program_ids", "The program ids field is required.")
		} else if list, ok := v.([]any); !ok || len(list) < 2 {
			verrs.add("program_ids", "The program ids field must have at least 2 items.")
		} else {
			for _, item := range list {
				n, isNum := toFloat(item)
				if !isNum || n != float64(int64(n)) || int64(n) < 1 {
					verrs.add("program_ids", "Each program id must be an integer.")
					break
				}
				ids = append(ids, int(n))
			}
		}
		if len(verrs) > 0 {
			return verrs.invalid(c)
		}
		uid := c.Get(string(ctxUIDKey)).(int64)
		ctx := c.Request().Context()
		id64 := make([]int64, 0, len(ids))
		for _, id := range ids {
			id64 = append(id64, int64(id))
		}
		rows, err := d.Programs.BuildProgramsByIDs(ctx, uid, id64)
		if err != nil {
			return dbError(c, err)
		}
		if len(rows) < 2 {
			verrs.add("program_ids", "At least two of your own programs are required to merge")
			return verrs.invalid(c)
		}
		inputs := make([]calc.MergeProgram, 0, len(rows))
		for _, r := range rows {
			var steps []any
			_ = jsonUnmarshal([]byte(r.StepsJSON), &steps)
			inputs = append(inputs, calc.MergeProgram{
				ID: int(r.ID), Name: r.Name, ProgramType: r.ProgramType,
				Steps: steps, Settings: decodeSettings(r.SettingsJSON),
			})
		}
		merged := calc.MergePrograms(inputs, ids)
		row, err := d.Programs.CreateBuildProgram(ctx, store.BuildProgram{
			UserID: uid, Name: derefStr(name, ""), ProgramType: merged.ProgramType,
			StepsJSON: marshalSteps(merged.Steps), SettingsJSON: marshalSettings(merged.Settings),
		})
		if err != nil {
			return dbError(c, err)
		}
		return Success(c, http.StatusCreated, programResourceShape(*row), nil)
	}
}

func handleBuildProgramUpdate(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		id, ok := programID(c)
		if !ok {
			return errHandled
		}
		verrs := validationErrors{}
		raw := decodePlanBody(c, verrs)
		if raw == nil && len(verrs) > 0 {
			return verrs.invalid(c)
		}
		name := stringField(raw, verrs, "name", 255, true)
		if len(verrs) > 0 || name == nil {
			if name == nil && len(verrs) == 0 {
				verrs.add("name", "The name field is required.")
			}
			return verrs.invalid(c)
		}
		uid := c.Get(string(ctxUIDKey)).(int64)
		row, err := d.Programs.RenameBuildProgram(c.Request().Context(), id, uid, *name)
		if err != nil {
			return dbError(c, err)
		}
		if row == nil {
			return Failure(c, http.StatusNotFound, "Not found.", nil)
		}
		return Success(c, http.StatusOK, programResourceShape(*row), nil)
	}
}

func handleBuildProgramLocationFilter(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		id, ok := programID(c)
		if !ok {
			return errHandled
		}
		verrs := validationErrors{}
		raw := decodePlanBody(c, verrs)
		if raw == nil {
			raw = map[string]any{}
		}
		if len(verrs) > 0 {
			return verrs.invalid(c)
		}
		var locIDs []int
		if v, present := raw["location_ids"]; present && v != nil {
			list, isArr := v.([]any)
			if !isArr {
				verrs.add("location_ids", "The location ids field must be an array.")
				return verrs.invalid(c)
			}
			for _, item := range list {
				n, isNum := toFloat(item)
				if !isNum || n != float64(int(n)) {
					verrs.add("location_ids", "Each location id must be an integer.")
					return verrs.invalid(c)
				}
				locIDs = append(locIDs, int(n))
			}
		}
		uid := c.Get(string(ctxUIDKey)).(int64)
		ctx := c.Request().Context()
		row, err := d.Programs.BuildProgram(ctx, id, uid)
		if err != nil {
			return dbError(c, err)
		}
		if row == nil {
			return Failure(c, http.StatusNotFound, "Not found.", nil)
		}
		settings := decodeSettings(row.SettingsJSON)
		out := make([]any, 0, len(locIDs))
		for _, n := range locIDs {
			out = append(out, n)
		}
		settings["location_ids"] = out
		updated, err := d.Programs.UpdateBuildProgramSettings(ctx, id, uid, marshalSettings(settings))
		if err != nil {
			return dbError(c, err)
		}
		if updated == nil {
			return Failure(c, http.StatusNotFound, "Not found.", nil)
		}
		return Success(c, http.StatusOK, programResourceShape(*updated), nil)
	}
}

func handleBuildProgramDestroy(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		id, ok := programID(c)
		if !ok {
			return errHandled
		}
		deleted, err := d.Programs.DeleteBuildProgram(c.Request().Context(), id, c.Get(string(ctxUIDKey)).(int64))
		if err != nil {
			return dbError(c, err)
		}
		if !deleted {
			return Failure(c, http.StatusNotFound, "Not found.", nil)
		}
		return c.NoContent(http.StatusNoContent)
	}
}

func validateBuildSteps(raw map[string]any, verrs validationErrors, required bool) ([]map[string]any, bool) {
	v, present := raw["steps"]
	if !present || v == nil {
		if required {
			verrs.add("steps", "The steps field is required.")
		}
		return nil, !required
	}
	list, ok := v.([]any)
	if !ok || (required && len(list) == 0) {
		verrs.add("steps", "The steps field must be an array.")
		return nil, false
	}
	out := make([]map[string]any, 0, len(list))
	for _, item := range list {
		m, ok := item.(map[string]any)
		if !ok {
			verrs.add("steps", "Each step must be an object.")
			return nil, false
		}
		id, isNum := toFloat(m["type_id"])
		if !isNum || id < 1 {
			verrs.add("steps", "Each step needs an integer type_id.")
			return nil, false
		}
		name, isStr := m["name"].(string)
		if !isStr || name == "" || len([]rune(name)) > 255 {
			verrs.add("steps", "Each step needs a name up to 255 characters.")
			return nil, false
		}
		qty, isNum := toFloat(m["quantity_required"])
		if !isNum || qty < 1 {
			verrs.add("steps", "Each step needs quantity_required of at least 1.")
			return nil, false
		}
		step := map[string]any{
			"type_id": int(id), "name": name, "quantity_required": int(qty),
		}
		if v, present := m["runs_needed"]; present && v != nil {
			n, isNum := toFloat(v)
			if !isNum || n < 0 || n != float64(int(n)) {
				verrs.add("steps", "Each runs_needed must be an integer of at least 0.")
				return nil, false
			}
			step["runs_needed"] = int(n)
		}
		if v, present := m["materials"]; present && v != nil {
			if _, isArr := v.([]any); !isArr {
				verrs.add("steps", "Each materials must be an array.")
				return nil, false
			}
			step["materials"] = v
		}
		out = append(out, step)
	}
	return out, true
}

func mapOf(raw map[string]any, key string) map[string]any {
	if v, ok := raw[key].(map[string]any); ok {
		return v
	}
	return map[string]any{}
}

func decodeSteps(raw string) []any {
	var out []any
	if err := jsonUnmarshal([]byte(raw), &out); err != nil || out == nil {
		return []any{}
	}
	return out
}

func decodeSettings(raw string) map[string]any {
	var out map[string]any
	if err := jsonUnmarshal([]byte(raw), &out); err != nil || out == nil {
		return map[string]any{}
	}
	return out
}

func marshalSteps(steps []any) string {
	raw, err := json.Marshal(steps)
	if err != nil || raw == nil {
		return "[]"
	}
	return string(raw)
}

func marshalSettings(settings map[string]any) string {
	if settings == nil {
		return "{}"
	}
	raw, err := json.Marshal(settings)
	if err != nil {
		return "{}"
	}
	return string(raw)
}
