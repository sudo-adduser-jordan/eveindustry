package http

import (
	"context"
	"database/sql"
	"encoding/json"
	"net/http"

	"github.com/labstack/echo/v4"

	"github.com/sudo-adduser-jordan/eveindustry/internal/calc"
	"github.com/sudo-adduser-jordan/eveindustry/internal/industry"
	"github.com/sudo-adduser-jordan/eveindustry/internal/store"
)

// registerCorpPrograms mounts corporate build programs. Every route resolves
// the user's managed corporation first and 404s when there is none, like the
// old routes did.
func registerCorpPrograms(v1 *echo.Group, d Deps) {
	if d.CorpPrograms == nil || d.Corp == nil || d.Users == nil || d.Industry == nil {
		return
	}
	v1.POST("/corporation/build-programs", handleCorpProgramStore(d))
	v1.POST("/corporation/build-programs/from-reaction-plan", handleCorpProgramFromReactionPlan(d))
	v1.POST("/corporation/build-programs/merge", handleCorpProgramMerge(d))
	v1.PUT("/corporation/build-programs/:id", handleCorpProgramUpdate(d))
	v1.PATCH("/corporation/build-programs/:id/location-filter", handleCorpProgramLocationFilter(d))
	v1.DELETE("/corporation/build-programs/:id", handleCorpProgramDestroy(d))
}

// managedCorpID mirrors CorporationBuildProgramService::getConfigForUser +
// canUserManage: the active corp config whose corp the user directs or
// factory-manages. Nil when the user manages nothing (callers 404).
func managedCorpID(ctx context.Context, d Deps, uid int64) (*int64, error) {
	chars, err := d.Users.CharactersForUser(ctx, uid)
	if err != nil {
		return nil, err
	}
	seen := map[int64]bool{}
	for _, ch := range chars {
		if ch.CorporationID == nil || *ch.CorporationID == 0 || seen[*ch.CorporationID] {
			continue
		}
		seen[*ch.CorporationID] = true
		cfg, err := d.Corp.ActiveCorpConfig(ctx, *ch.CorporationID)
		if err != nil {
			return nil, err
		}
		if cfg == nil {
			continue
		}
		members, err := d.Corp.UserCorpCharacters(ctx, uid, *ch.CorporationID)
		if err != nil {
			return nil, err
		}
		for _, m := range members {
			if m.IsDirectorCEO || hasRole(m.Roles, "Factory_Manager") {
				id := *ch.CorporationID
				return &id, nil
			}
		}
	}
	return nil, nil
}

func corpProgramShape(p store.CorpProgram) map[string]any {
	steps := decodeSteps(p.StepsJSON)
	settings := decodeSettings(derefStr(industry.StrVal(p.SettingsJSON), "{}"))
	return map[string]any{
		"id": p.ID, "name": p.Name, "program_type": p.ProgramType,
		"t2_type_id": nullInt64Val(p.T2TypeID), "t2_type_name": nullStrVal(p.T2TypeName),
		"steps": shapeSteps(steps), "settings": settings,
		"created_at": p.CreatedAt.UTC().Format("2006-01-02T15:04:05Z"),
		"updated_at": p.UpdatedAt.UTC().Format("2006-01-02T15:04:05Z"),
	}
}

func corpProgramDetailShape(p store.CorpProgram) (map[string]any, map[string]any) {
	steps := decodeSteps(p.StepsJSON)
	settings := decodeSettings(derefStr(industry.StrVal(p.SettingsJSON), "{}"))
	var secs []any
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
	data := map[string]any{
		"id": p.ID, "name": p.Name, "program_type": p.ProgramType,
		"t2_type_id": nullInt64Val(p.T2TypeID), "t2_type_name": nullStrVal(p.T2TypeName),
		"steps": shapeSteps(steps), "sections": secs, "location_ids": lids,
		"created_at": p.CreatedAt.UTC().Format("2006-01-02T15:04:05Z"),
		"updated_at": p.UpdatedAt.UTC().Format("2006-01-02T15:04:05Z"),
	}
	return data, map[string]any{}
}

// POST /corporation/build-programs — 201.
func handleCorpProgramStore(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		verrs := validationErrors{}
		raw := decodePlanBody(c, verrs)
		if raw == nil && len(verrs) > 0 {
			return verrs.invalid(c)
		}
		name := stringField(raw, verrs, "name", 255, true)
		steps, ok := validateBuildSteps(raw, verrs, true)
		if !ok {
			return verrs.invalid(c)
		}
		settings := mapOf(raw, "settings")
		var t2id *int
		if v, present := raw["t2_type_id"]; present && v != nil {
			if n, isNum := toFloat(v); !isNum || n != float64(int(n)) {
				verrs.add("t2_type_id", "The t2 type id field must be an integer.")
			} else {
				t2id = newInt(int(n))
			}
		}
		var t2name *string
		if v, present := raw["t2_type_name"]; present && v != nil {
			if s, isStr := v.(string); !isStr || len([]rune(s)) > 255 {
				verrs.add("t2_type_name", "The t2 type name is invalid.")
			} else {
				t2name = &s
			}
		}
		if len(verrs) > 0 {
			return verrs.invalid(c)
		}
		uid := c.Get(string(ctxUIDKey)).(int64)
		ctx := c.Request().Context()
		corpID, err := managedCorpID(ctx, d, uid)
		if err != nil {
			return dbError(c, err)
		}
		if corpID == nil {
			return Failure(c, http.StatusNotFound, "Not found.", nil)
		}
		nested, err := nestProgramSteps(ctx, d, steps, reactionBonusFromSettings(settings))
		if err != nil {
			return dbError(c, err)
		}
		row, err := d.CorpPrograms.CreateCorpProgram(ctx, store.CorpProgram{
			CorporationID: *corpID, CreatedByUserID: uid, Name: derefStr(name, ""),
			ProgramType: "manufacturing", T2TypeID: sqlNullInt(t2id), T2TypeName: sqlNullStr(t2name),
			StepsJSON: marshalSteps(nested), SettingsJSON: sqlNullStrP(marshalSettings(settings)),
		})
		if err != nil {
			return dbError(c, err)
		}
		return Success(c, http.StatusCreated, corpProgramShape(*row), nil)
	}
}

// POST /corporation/build-programs/from-reaction-plan — 201 or 422.
func handleCorpProgramFromReactionPlan(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		verrs := validationErrors{}
		raw := decodePlanBody(c, verrs)
		if raw == nil && len(verrs) > 0 {
			return verrs.invalid(c)
		}
		name := stringField(raw, verrs, "name", 255, true)
		entryMaps, ok := validateEntries(raw, verrs, true)
		if !ok {
			return verrs.invalid(c)
		}
		var entries []calc.ReactionPlanEntry
		for _, m := range entryMaps {
			fid, _ := m["formula_type_id"].(int)
			slots, _ := m["slots"].(int)
			rps, _ := m["runs_per_slot"].(int)
			fname, _ := m["formula_name"].(string)
			entries = append(entries, calc.ReactionPlanEntry{FormulaTypeID: fid, FormulaName: fname, Slots: slots, RunsPerSlot: rps})
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
		uid := c.Get(string(ctxUIDKey)).(int64)
		ctx := c.Request().Context()
		corpID, err := managedCorpID(ctx, d, uid)
		if err != nil {
			return dbError(c, err)
		}
		if corpID == nil {
			return Failure(c, http.StatusNotFound, "Not found.", nil)
		}
		steps, firstID, firstName, err := calc.ReactionPlanSteps(ctx, d.Industry, entries, calc.ResolveRigBonus(rigTier, secSpace))
		if err != nil {
			return dbError(c, err)
		}
		if len(steps) == 0 {
			verrs.add("entries", "No valid reaction entries found")
			return verrs.invalid(c)
		}
		out := make([]any, 0, len(steps))
		for _, s := range steps {
			out = append(out, chainStepShape(s))
		}
		var t2id *int
		var t2name *string
		if firstID != nil {
			t2id = firstID
			t2name = firstName
		}
		row, err := d.CorpPrograms.CreateCorpProgram(ctx, store.CorpProgram{
			CorporationID: *corpID, CreatedByUserID: uid, Name: derefStr(name, ""),
			ProgramType: "reaction", T2TypeID: sqlNullInt(t2id), T2TypeName: sqlNullStr(t2name),
			StepsJSON: marshalSteps(out), SettingsJSON: sqlNullStrP(marshalSettings(settings)),
		})
		if err != nil {
			return dbError(c, err)
		}
		return Success(c, http.StatusCreated, corpProgramShape(*row), nil)
	}
}

func nullStrOrEmpty(v sql.NullString) string {
	if v.Valid {
		return v.String
	}
	return ""
}

func jsonMarshal(v any) ([]byte, error) { return json.Marshal(v) }

func newInt(v int) *int { return &v }

func sqlNullStrP(s string) sql.NullString {
	if s == "" || s == "{}" {
		return sql.NullString{}
	}
	return sql.NullString{String: s, Valid: true}
}

func mustJSONStr(v any) string {
	b, err := json.Marshal(v)
	if err != nil {
		return "{}"
	}
	return string(b)
}

func intListField(raw map[string]any, verrs validationErrors, field string, minLen int) ([]int, bool) {
	v, present := raw[field]
	if !present || v == nil {
		verrs.add(field, "The "+field+" field is required.")
		return nil, false
	}
	return intListValue(v, verrs, field, minLen)
}

func intListFieldOpt(raw map[string]any, verrs validationErrors, field string) ([]int, bool) {
	v, present := raw[field]
	if !present || v == nil {
		return nil, true
	}
	return intListValue(v, verrs, field, 0)
}

func intListValue(v any, verrs validationErrors, field string, minLen int) ([]int, bool) {
	list, ok := v.([]any)
	if !ok || len(list) < minLen {
		verrs.add(field, "The "+field+" field must be an array.")
		return nil, false
	}
	out := make([]int, 0, len(list))
	for _, item := range list {
		n, isNum := toFloat(item)
		if !isNum || n != float64(int(n)) {
			verrs.add(field, "Each "+field+" entry must be an integer.")
			return nil, false
		}
		out = append(out, int(n))
	}
	return out, true
}

func intSliceAny(ids []int) []any {
	out := make([]any, 0, len(ids))
	for _, id := range ids {
		out = append(out, id)
	}
	return out
}

// POST /corporation/build-programs/merge — 201 or 422.
func handleCorpProgramMerge(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		verrs := validationErrors{}
		raw := decodePlanBody(c, verrs)
		if raw == nil && len(verrs) > 0 {
			return verrs.invalid(c)
		}
		name := stringField(raw, verrs, "name", 255, true)
		ids, _ := intListField(raw, verrs, "program_ids", 2)
		if len(verrs) > 0 || name == nil {
			if name == nil && len(verrs) == 0 {
				verrs.add("name", "The name field is required.")
			}
			return verrs.invalid(c)
		}
		uid := c.Get(string(ctxUIDKey)).(int64)
		ctx := c.Request().Context()
		corpID, err := managedCorpID(ctx, d, uid)
		if err != nil {
			return dbError(c, err)
		}
		if corpID == nil {
			return Failure(c, http.StatusNotFound, "Not found.", nil)
		}
		id64 := make([]int64, 0, len(ids))
		for _, id := range ids {
			id64 = append(id64, int64(id))
		}
		rows, err := d.CorpPrograms.CorpProgramsByIDs(ctx, *corpID, id64)
		if err != nil {
			return dbError(c, err)
		}
		if len(rows) < 2 {
			verrs.add("program_ids", "At least two programs from your corporation are required to merge")
			return verrs.invalid(c)
		}
		inputs := make([]calc.MergeProgram, 0, len(rows))
		byID := map[int64]store.CorpProgram{}
		for _, r := range rows {
			byID[r.ID] = r
		}
		for _, id := range id64 {
			r, ok := byID[id]
			if !ok {
				continue
			}
			var steps []any
			_ = jsonUnmarshal([]byte(r.StepsJSON), &steps)
			if steps == nil {
				steps = []any{}
			}
			var settings map[string]any
			_ = jsonUnmarshal([]byte(nullStrOrEmpty(r.SettingsJSON)), &settings)
			if settings == nil {
				settings = map[string]any{}
			}
			inputs = append(inputs, calc.MergeProgram{ID: int(r.ID), Name: r.Name, ProgramType: r.ProgramType, Steps: steps, Settings: settings})
		}
		intIDs := make([]int, 0, len(id64))
		for _, id := range id64 {
			intIDs = append(intIDs, int(id))
		}
		merged := calc.MergePrograms(inputs, intIDs)
		row, err := d.CorpPrograms.CreateCorpProgram(ctx, store.CorpProgram{
			CorporationID: *corpID, CreatedByUserID: uid, Name: *name,
			ProgramType: merged.ProgramType, StepsJSON: marshalSteps(merged.Steps),
			SettingsJSON: sqlNullStrP(mustJSONStr(merged.Settings)),
		})
		if err != nil {
			return dbError(c, err)
		}
		return Success(c, http.StatusCreated, corpProgramShape(*row), nil)
	}
}

// PUT /corporation/build-programs/:id — rename.
func handleCorpProgramUpdate(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		id, ok := planID(c)
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
		ctx := c.Request().Context()
		corpID, err := managedCorpID(ctx, d, uid)
		if err != nil {
			return dbError(c, err)
		}
		if corpID == nil {
			return Failure(c, http.StatusNotFound, "Not found.", nil)
		}
		row, err := d.CorpPrograms.RenameCorpProgram(ctx, id, *corpID, *name)
		if err != nil {
			return dbError(c, err)
		}
		if row == nil {
			return Failure(c, http.StatusNotFound, "Not found.", nil)
		}
		data, _ := corpProgramDetailShape(*row)
		return Success(c, http.StatusOK, data, nil)
	}
}

// PATCH /corporation/build-programs/:id/location-filter — input+output lists.
func handleCorpProgramLocationFilter(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		id, ok := planID(c)
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
		inIDs, ok := intListFieldOpt(raw, verrs, "input_location_ids")
		if !ok {
			return verrs.invalid(c)
		}
		outIDs, ok := intListFieldOpt(raw, verrs, "output_location_ids")
		if !ok {
			return verrs.invalid(c)
		}
		uid := c.Get(string(ctxUIDKey)).(int64)
		ctx := c.Request().Context()
		corpID, err := managedCorpID(ctx, d, uid)
		if err != nil {
			return dbError(c, err)
		}
		if corpID == nil {
			return Failure(c, http.StatusNotFound, "Not found.", nil)
		}
		row, err := d.CorpPrograms.CorpProgram(ctx, id, *corpID)
		if err != nil {
			return dbError(c, err)
		}
		if row == nil {
			return Failure(c, http.StatusNotFound, "Not found.", nil)
		}
		var settings map[string]any
		_ = jsonUnmarshal([]byte(nullStrOrEmpty(row.SettingsJSON)), &settings)
		if settings == nil {
			settings = map[string]any{}
		}
		settings["input_location_ids"] = intSliceAny(inIDs)
		settings["output_location_ids"] = intSliceAny(outIDs)
		bs, _ := jsonMarshal(settings)
		updated, err := d.CorpPrograms.UpdateCorpProgramSettings(ctx, id, *corpID, string(bs))
		if err != nil {
			return dbError(c, err)
		}
		if updated == nil {
			return Failure(c, http.StatusNotFound, "Not found.", nil)
		}
		data, _ := corpProgramDetailShape(*updated)
		return Success(c, http.StatusOK, data, nil)
	}
}

// DELETE /corporation/build-programs/:id — 204.
func handleCorpProgramDestroy(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		id, ok := planID(c)
		if !ok {
			return errHandled
		}
		uid := c.Get(string(ctxUIDKey)).(int64)
		ctx := c.Request().Context()
		corpID, err := managedCorpID(ctx, d, uid)
		if err != nil {
			return dbError(c, err)
		}
		if corpID == nil {
			return Failure(c, http.StatusNotFound, "Not found.", nil)
		}
		deleted, err := d.CorpPrograms.DeleteCorpProgram(ctx, id, *corpID)
		if err != nil {
			return dbError(c, err)
		}
		if !deleted {
			return Failure(c, http.StatusNotFound, "Not found.", nil)
		}
		return c.NoContent(http.StatusNoContent)
	}
}
