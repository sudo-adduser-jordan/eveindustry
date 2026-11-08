package http

import (
	"encoding/json"
	"net/http"
	"strconv"

	"github.com/labstack/echo/v4"

	"github.com/sudo-adduser-jordan/eveindustry/internal/store"
)

func strconvParseID(s string) (int64, error) { return strconv.ParseInt(s, 10, 64) }

func jsonUnmarshal(raw []byte, v any) error { return json.Unmarshal(raw, v) }

func marshalEntries(entries []map[string]any) string {
	raw, err := json.Marshal(entries)
	if err != nil || raw == nil {
		return "[]"
	}
	return string(raw)
}

func oneOfField(raw map[string]any, verrs validationErrors, field string, allowed []string, required bool) (string, bool) {
	v, present := raw[field]
	if !present || v == nil {
		if required {
			verrs.add(field, "The "+field+" field is required.")
		}
		return "", !required
	}
	s, ok := v.(string)
	if !ok {
		verrs.add(field, "The "+field+" field must be a string.")
		return "", false
	}
	for _, a := range allowed {
		if s == a {
			return s, true
		}
	}
	verrs.add(field, "The selected "+field+" is invalid.")
	return "", false
}

func numFieldJSON(raw map[string]any, verrs validationErrors, field string, min, max float64, required bool) (float64, bool) {
	v, present := raw[field]
	if !present || v == nil {
		if required {
			verrs.add(field, "The "+field+" field is required.")
		}
		return 0, !required
	}
	n, ok := v.(float64)
	if !ok || n < min || n > max {
		verrs.add(field, "The "+field+" field is invalid.")
		return 0, false
	}
	return n, true
}

func optInt(raw map[string]any, verrs validationErrors, field string) (*int, bool) {
	v, present := raw[field]
	if !present || v == nil {
		return nil, true
	}
	n, ok := v.(float64)
	if !ok || n != float64(int(n)) {
		verrs.add(field, "The "+field+" field must be an integer.")
		return nil, false
	}
	out := int(n)
	return &out, true
}

func optInt64(raw map[string]any, verrs validationErrors, field string) (*int64, bool) {
	v, present := raw[field]
	if !present || v == nil {
		return nil, true
	}
	n, ok := v.(float64)
	if !ok || n != float64(int64(n)) {
		verrs.add(field, "The "+field+" field must be an integer.")
		return nil, false
	}
	out := int64(n)
	return &out, true
}

// registerPlans mounts the invention/reaction plan CRUD (apiResource without
// show names: index, store, show, update, destroy).
func registerPlans(v1 *echo.Group, d Deps) {
	if d.Plans == nil {
		return
	}
	v1.GET("/invention-plans", handleInventionPlansIndex(d))
	v1.POST("/invention-plans", handleInventionPlanStore(d))
	v1.GET("/invention-plans/:id", handleInventionPlanShow(d))
	v1.PUT("/invention-plans/:id", handleInventionPlanUpdate(d))
	v1.DELETE("/invention-plans/:id", handleInventionPlanDestroy(d))
	v1.GET("/reaction-plans", handleReactionPlansIndex(d))
	v1.POST("/reaction-plans", handleReactionPlanStore(d))
	v1.GET("/reaction-plans/:id", handleReactionPlanShow(d))
	v1.PUT("/reaction-plans/:id", handleReactionPlanUpdate(d))
	v1.DELETE("/reaction-plans/:id", handleReactionPlanDestroy(d))
}

func planID(c echo.Context) (int64, bool) {
	id, err := strconvParseID(c.Param("id"))
	if err != nil || id < 1 {
		_ = Failure(c, http.StatusNotFound, "Not found.", nil)
		return 0, false
	}
	return id, true
}

func inventionPlanShape(p store.InventionPlan) map[string]any {
	return map[string]any{
		"id": p.ID, "name": p.Name, "t2_type_id": p.T2TypeID, "t2_type_name": p.T2TypeName,
		"decryptor_key": p.DecryptorKey, "t1_me": p.T1ME, "t1_te": p.T1TE,
		"encryption_skill": p.EncryptionSkill, "science1_skill": p.Science1Skill, "science2_skill": p.Science2Skill,
		"invention_runs": p.InventionRuns, "structure_type": p.StructureType,
		"rig_me": p.RigME, "rig_te": p.RigTE, "system_security": p.SystemSecurity,
		"facility_tax": p.FacilityTax, "solar_system_id": p.SolarSystemID, "station_id": p.StationID,
		"price_region_id": p.PriceRegionID,
		"created_at": p.CreatedAt.UTC().Format("2006-01-02T15:04:05Z"),
		"updated_at": p.UpdatedAt.UTC().Format("2006-01-02T15:04:05Z"),
	}
}

func reactionPlanShape(p store.ReactionPlan, entries []any) map[string]any {
	if entries == nil {
		entries = []any{}
	}
	return map[string]any{
		"id": p.ID, "name": p.Name, "entries": entries,
		"default_runs_per_slot": p.DefaultRunsPerSlot, "rig_bonus": p.RigBonus,
		"structure_type": p.StructureType, "rig_tier": p.RigTier, "security_space": p.SecuritySpace,
		"facility_tax": p.FacilityTax, "station_id": p.StationID, "solar_system_id": p.SolarSystemID,
		"price_region_id": p.PriceRegionID,
		"created_at": p.CreatedAt.UTC().Format("2006-01-02T15:04:05Z"),
		"updated_at": p.UpdatedAt.UTC().Format("2006-01-02T15:04:05Z"),
	}
}

// --- invention plans ---

func handleInventionPlansIndex(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		verrs := validationErrors{}
		page, perPage := parsePagination(c, verrs)
		if len(verrs) > 0 {
			return verrs.invalid(c)
		}
		uid := c.Get(string(ctxUIDKey)).(int64)
		rows, total, err := d.Plans.InventionPlans(c.Request().Context(), uid, perPage, (page-1)*perPage)
		if err != nil {
			return dbError(c, err)
		}
		_, pagination := paginate(total, page, perPage)
		out := make([]any, 0, len(rows))
		for _, r := range rows {
			out = append(out, inventionPlanShape(r))
		}
		return Success(c, http.StatusOK, out, map[string]any{"pagination": pagination})
	}
}

func decodePlanBody(c echo.Context, verrs validationErrors) map[string]any {
	var raw map[string]any
	if err := json.NewDecoder(c.Request().Body).Decode(&raw); err != nil {
		verrs.add("body", "The request body must be valid JSON.")
		return nil
	}
	if raw == nil {
		raw = map[string]any{}
	}
	return raw
}

func stringField(raw map[string]any, verrs validationErrors, field string, maxLen int, required bool) *string {
	return strField(raw, verrs, field, maxLen, required, false)
}

func intFieldJSON2(raw map[string]any, verrs validationErrors, field string, min, max int, required bool) (int, bool) {
	v, present := raw[field]
	if !present || v == nil {
		if required {
			verrs.add(field, "The "+field+" field is required.")
		}
		return 0, !required
	}
	n, ok := v.(float64)
	if !ok || n != float64(int(n)) || int(n) < min || int(n) > max {
		verrs.add(field, "The "+field+" field is invalid.")
		return 0, false
	}
	return int(n), true
}

func handleInventionPlanStore(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		verrs := validationErrors{}
		raw := decodePlanBody(c, verrs)
		if raw == nil && len(verrs) > 0 {
			return verrs.invalid(c)
		}
		name := stringField(raw, verrs, "name", 255, true)
		t2id, _ := intFieldJSON2(raw, verrs, "t2_type_id", 1, 1<<31-1, true)
		t2name := stringField(raw, verrs, "t2_type_name", 255, true)
		decryptor, _ := oneOfStr(raw, "decryptor_key", 50)
		t1me, _ := intFieldJSON2(raw, verrs, "t1_me", 0, 10, false)
		t1te, _ := intFieldJSON2(raw, verrs, "t1_te", 0, 20, false)
		enc, _ := intFieldJSON2(raw, verrs, "encryption_skill", 0, 5, false)
		sci1, _ := intFieldJSON2(raw, verrs, "science1_skill", 0, 5, false)
		sci2, _ := intFieldJSON2(raw, verrs, "science2_skill", 0, 5, false)
		runs, _ := intFieldJSON2(raw, verrs, "invention_runs", 1, 10000, false)
		structType, _ := oneOfField(raw, verrs, "structure_type", []string{"none", "raitaru", "azbel", "sotiyo"}, false)
		rigME, _ := oneOfField(raw, verrs, "rig_me", []string{"none", "t1", "t2"}, false)
		rigTE, _ := oneOfField(raw, verrs, "rig_te", []string{"none", "t1", "t2"}, false)
		sysSec, _ := oneOfField(raw, verrs, "system_security", []string{"highsec", "lowsec", "nullsec"}, false)
		tax, _ := numFieldJSON(raw, verrs, "facility_tax", 0, 50, false)
		sysID, _ := optInt(raw, verrs, "solar_system_id")
		stID, _ := optInt64(raw, verrs, "station_id")
		regionID, _ := optInt(raw, verrs, "price_region_id")
		if len(verrs) > 0 {
			return verrs.invalid(c)
		}
		var sysID64 *int64
		if sysID != nil {
			v := int64(*sysID)
			sysID64 = &v
		}
		uid := c.Get(string(ctxUIDKey)).(int64)
		row, err := d.Plans.CreateInventionPlan(c.Request().Context(), store.InventionPlan{
			UserID: uid, Name: derefStr(name, ""), T2TypeID: t2id, T2TypeName: derefStr(t2name, ""),
			DecryptorKey: derefStr(decryptor, "none"), T1ME: t1me, T1TE: t1te,
			EncryptionSkill: enc, Science1Skill: sci1, Science2Skill: sci2,
			InventionRuns: ifZero(runs, 1), StructureType: emptyToNil(structType), RigME: emptyToNil(rigME),
			RigTE: emptyToNil(rigTE), SystemSecurity: emptyToNil(sysSec), FacilityTax: tax,
			SolarSystemID: sysID64, StationID: stID, PriceRegionID: regionID,
		})
		if err != nil {
			return dbError(c, err)
		}
		return Success(c, http.StatusCreated, inventionPlanShape(*row), nil)
	}
}

func ifZero(v, def int) int {
	if v == 0 {
		return def
	}
	return v
}

func emptyToNil(s string) *string {
	if s == "" {
		return nil
	}
	v := s
	return &v
}

func defStr(s, def string) string {
	if s == "" {
		return def
	}
	return s
}

func oneOfStr(raw map[string]any, field string, maxLen int) (*string, bool) {
	v, present := raw[field]
	if !present || v == nil {
		return nil, true
	}
	s, ok := v.(string)
	if !ok || len([]rune(s)) > maxLen {
		return nil, false
	}
	return &s, true
}

func handleInventionPlanShow(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		id, ok := planID(c)
		if !ok {
			return errHandled
		}
		row, err := d.Plans.InventionPlan(c.Request().Context(), id, c.Get(string(ctxUIDKey)).(int64))
		if err != nil {
			return dbError(c, err)
		}
		if row == nil {
			return Failure(c, http.StatusNotFound, "Not found.", nil)
		}
		return Success(c, http.StatusOK, inventionPlanShape(*row), nil)
	}
}

func handleInventionPlanUpdate(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		id, ok := planID(c)
		if !ok {
			return errHandled
		}
		uid := c.Get(string(ctxUIDKey)).(int64)
		ctx := c.Request().Context()
		cur, err := d.Plans.InventionPlan(ctx, id, uid)
		if err != nil {
			return dbError(c, err)
		}
		if cur == nil {
			return Failure(c, http.StatusNotFound, "Not found.", nil)
		}
		verrs := validationErrors{}
		raw := decodePlanBody(c, verrs)
		if raw == nil && len(verrs) > 0 {
			return verrs.invalid(c)
		}
		if v, present := raw["name"]; present && v != nil {
			s, isStr := v.(string)
			if !isStr || s == "" || len([]rune(s)) > 255 {
				verrs.add("name", "The name field is invalid.")
			} else {
				cur.Name = s
			}
		}
		if v, present := raw["t2_type_id"]; present && v != nil {
			if n, ok := intFieldJSON2(map[string]any{"v": v}, verrs, "v", 1, 1<<31-1, true); ok {
				cur.T2TypeID = n
			} else {
				verrs.add("t2_type_id", "The t2 type id field must be an integer.")
			}
		}
		if v, present := raw["t2_type_name"]; present && v != nil {
			if s, isStr := v.(string); !isStr || s == "" || len([]rune(s)) > 255 {
				verrs.add("t2_type_name", "The t2 type name field is invalid.")
			} else {
				cur.T2TypeName = s
			}
		}
		applyInventionSettings(raw, verrs, cur)
		if len(verrs) > 0 {
			return verrs.invalid(c)
		}
		row, err := d.Plans.UpdateInventionPlan(ctx, *cur)
		if err != nil {
			return dbError(c, err)
		}
		return Success(c, http.StatusOK, inventionPlanShape(*row), nil)
	}
}

func applyInventionSettings(raw map[string]any, verrs validationErrors, cur *store.InventionPlan) {
	if v, present := raw["decryptor_key"]; present {
		if v == nil {
			cur.DecryptorKey = "none"
		} else if s, ok := v.(string); !ok || len([]rune(s)) > 50 {
			verrs.add("decryptor_key", "The decryptor key field is invalid.")
		} else {
			cur.DecryptorKey = s
		}
	}
	ints := []struct {
		key string
		min int
		max int
		set func(int)
	}{
		{"t1_me", 0, 10, func(n int) { cur.T1ME = n }},
		{"t1_te", 0, 20, func(n int) { cur.T1TE = n }},
		{"encryption_skill", 0, 5, func(n int) { cur.EncryptionSkill = n }},
		{"science1_skill", 0, 5, func(n int) { cur.Science1Skill = n }},
		{"science2_skill", 0, 5, func(n int) { cur.Science2Skill = n }},
		{"invention_runs", 1, 10000, func(n int) { cur.InventionRuns = n }},
	}
	for _, f := range ints {
		if v, present := raw[f.key]; present && v != nil {
			if n, ok := intFieldJSON2(map[string]any{"v": v}, verrs, "v", f.min, f.max, true); ok {
				f.set(n)
			} else {
				verrs.add(f.key, "The "+f.key+" field is invalid.")
			}
		}
	}
	strs := []struct {
		key     string
		allowed []string
		set     func(*string)
	}{
		{"structure_type", []string{"none", "raitaru", "azbel", "sotiyo"}, func(s *string) { cur.StructureType = s }},
		{"rig_me", []string{"none", "t1", "t2"}, func(s *string) { cur.RigME = s }},
		{"rig_te", []string{"none", "t1", "t2"}, func(s *string) { cur.RigTE = s }},
		{"system_security", []string{"highsec", "lowsec", "nullsec"}, func(s *string) { cur.SystemSecurity = s }},
	}
	for _, f := range strs {
		if v, present := raw[f.key]; present {
			if v == nil {
				f.set(nil)
				continue
			}
			s, isStr := v.(string)
			if !isStr {
				verrs.add(f.key, "The "+f.key+" field must be a string.")
				continue
			}
			ok := false
			for _, a := range f.allowed {
				if s == a {
					ok = true
					break
				}
			}
			if !ok {
				verrs.add(f.key, "The selected "+f.key+" is invalid.")
				continue
			}
			f.set(&s)
		}
	}
	if v, present := raw["facility_tax"]; present {
		if v == nil {
			cur.FacilityTax = 0
		} else if n, ok := v.(float64); !ok || n < 0 || n > 50 {
			verrs.add("facility_tax", "The facility tax field must be between 0 and 50.")
		} else {
			cur.FacilityTax = n
		}
	}
	if v, present := raw["solar_system_id"]; present {
		if v == nil {
			cur.SolarSystemID = nil
		} else if n, ok := intFieldJSON2(map[string]any{"v": v}, verrs, "v", 1, 1<<31-1, true); ok {
			id := int64(n)
			cur.SolarSystemID = &id
		} else {
			verrs.add("solar_system_id", "The solar system id field must be an integer.")
		}
	}
	if v, present := raw["station_id"]; present {
		if v == nil {
			cur.StationID = nil
		} else if n, ok := intFieldJSON2(map[string]any{"v": v}, verrs, "v", 1, 1<<31-1, true); ok {
			id := int64(n)
			cur.StationID = &id
		} else {
			verrs.add("station_id", "The station id field must be an integer.")
		}
	}
	if v, present := raw["price_region_id"]; present {
		if v == nil {
			cur.PriceRegionID = nil
		} else if n, ok := intFieldJSON2(map[string]any{"v": v}, verrs, "v", 1, 1<<31-1, true); ok {
			cur.PriceRegionID = &n
		} else {
			verrs.add("price_region_id", "The price region id field must be an integer.")
		}
	}
}

func handleInventionPlanDestroy(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		id, ok := planID(c)
		if !ok {
			return errHandled
		}
		deleted, err := d.Plans.DeleteInventionPlan(c.Request().Context(), id, c.Get(string(ctxUIDKey)).(int64))
		if err != nil {
			return dbError(c, err)
		}
		if !deleted {
			return Failure(c, http.StatusNotFound, "Not found.", nil)
		}
		return c.NoContent(http.StatusNoContent)
	}
}

// --- reaction plans ---

func handleReactionPlansIndex(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		verrs := validationErrors{}
		page, perPage := parsePagination(c, verrs)
		if len(verrs) > 0 {
			return verrs.invalid(c)
		}
		uid := c.Get(string(ctxUIDKey)).(int64)
		rows, total, err := d.Plans.ReactionPlans(c.Request().Context(), uid, perPage, (page-1)*perPage)
		if err != nil {
			return dbError(c, err)
		}
		_, pagination := paginate(total, page, perPage)
		out := make([]any, 0, len(rows))
		for _, r := range rows {
			out = append(out, reactionPlanShape(r, parseEntries(r.EntriesJSON)))
		}
		return Success(c, http.StatusOK, out, map[string]any{"pagination": pagination})
	}
}

func parseEntries(raw string) []any {
	var out []any
	if err := jsonUnmarshal([]byte(raw), &out); err != nil || out == nil {
		return []any{}
	}
	return out
}

func handleReactionPlanStore(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		verrs := validationErrors{}
		raw := decodePlanBody(c, verrs)
		if raw == nil && len(verrs) > 0 {
			return verrs.invalid(c)
		}
		name := stringField(raw, verrs, "name", 255, true)
		entries, entriesOK := validateEntries(raw, verrs, true)
		defRuns, _ := intFieldJSON2(raw, verrs, "default_runs_per_slot", 1, 100000, false)
		rigBonus, _ := numFieldJSON(raw, verrs, "rig_bonus", 0, 10, false)
		structType, _ := oneOfField(raw, verrs, "structure_type", []string{"athanor", "tatara"}, false)
		rigTier, _ := oneOfField(raw, verrs, "rig_tier", []string{"none", "t1", "t2"}, false)
		secSpace, _ := oneOfField(raw, verrs, "security_space", []string{"lowsec", "nullsec"}, false)
		tax, _ := numFieldJSON(raw, verrs, "facility_tax", 0, 50, false)
		stID, _ := optInt64(raw, verrs, "station_id")
		sysID, _ := optInt(raw, verrs, "solar_system_id")
		regionID, _ := optInt(raw, verrs, "price_region_id")
		if len(verrs) > 0 || !entriesOK {
			return verrs.invalid(c)
		}
		uid := c.Get(string(ctxUIDKey)).(int64)
		row, err := d.Plans.CreateReactionPlan(c.Request().Context(), store.ReactionPlan{
			UserID: uid, Name: derefStr(name, ""), EntriesJSON: marshalEntries(entries),
			DefaultRunsPerSlot: ifZero(defRuns, 240), RigBonus: rigBonus,
			StructureType: defStr(structType, "athanor"), RigTier: defStr(rigTier, "none"),
			SecuritySpace: defStr(secSpace, "lowsec"), FacilityTax: tax,
			StationID: stID, SolarSystemID: sysID, PriceRegionID: regionID,
		})
		if err != nil {
			return dbError(c, err)
		}
		return Success(c, http.StatusCreated, reactionPlanShape(*row, parseEntries(row.EntriesJSON)), nil)
	}
}

func validateEntries(raw map[string]any, verrs validationErrors, required bool) ([]map[string]any, bool) {
	v, present := raw["entries"]
	if !present || v == nil {
		if required {
			verrs.add("entries", "The entries field is required.")
		}
		return nil, !required
	}
	list, ok := v.([]any)
	if !ok || (required && len(list) == 0) {
		verrs.add("entries", "The entries field must be a non-empty array.")
		return nil, false
	}
	out := make([]map[string]any, 0, len(list))
	for _, item := range list {
		m, ok := item.(map[string]any)
		if !ok {
			verrs.add("entries", "Each entry must be an object.")
			return nil, false
		}
		entry := map[string]any{}
		fid, ok := intFieldJSON2(map[string]any{"v": m["formula_type_id"]}, verrs, "v", 1, 1<<31-1, true)
		if !ok {
			verrs.add("entries", "Each entry needs an integer formula_type_id.")
			return nil, false
		}
		entry["formula_type_id"] = fid
		fn, ok := m["formula_name"].(string)
		if !ok || fn == "" {
			verrs.add("entries", "Each entry needs a formula_name.")
			return nil, false
		}
		entry["formula_name"] = fn
		if iv, present := m["icon_url"]; present && iv != nil {
			if s, isStr := iv.(string); isStr {
				entry["icon_url"] = s
			} else {
				verrs.add("entries", "Each icon_url must be a string.")
				return nil, false
			}
		}
		slots, ok := intFieldJSON2(map[string]any{"v": m["slots"]}, verrs, "v", 1, 1000, true)
		if !ok {
			verrs.add("entries", "Each entry needs slots between 1 and 1000.")
			return nil, false
		}
		entry["slots"] = slots
		rps, ok := intFieldJSON2(map[string]any{"v": m["runs_per_slot"]}, verrs, "v", 1, 100000, true)
		if !ok {
			verrs.add("entries", "Each entry needs runs_per_slot between 1 and 100000.")
			return nil, false
		}
		entry["runs_per_slot"] = rps
		for k, v := range m {
			if k != "formula_type_id" && k != "formula_name" && k != "icon_url" && k != "slots" && k != "runs_per_slot" {
				entry[k] = v
			}
		}
		out = append(out, entry)
	}
	return out, true
}

func handleReactionPlanShow(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		id, ok := planID(c)
		if !ok {
			return errHandled
		}
		row, err := d.Plans.ReactionPlan(c.Request().Context(), id, c.Get(string(ctxUIDKey)).(int64))
		if err != nil {
			return dbError(c, err)
		}
		if row == nil {
			return Failure(c, http.StatusNotFound, "Not found.", nil)
		}
		return Success(c, http.StatusOK, reactionPlanShape(*row, parseEntries(row.EntriesJSON)), nil)
	}
}

func handleReactionPlanUpdate(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		id, ok := planID(c)
		if !ok {
			return errHandled
		}
		uid := c.Get(string(ctxUIDKey)).(int64)
		ctx := c.Request().Context()
		cur, err := d.Plans.ReactionPlan(ctx, id, uid)
		if err != nil {
			return dbError(c, err)
		}
		if cur == nil {
			return Failure(c, http.StatusNotFound, "Not found.", nil)
		}
		verrs := validationErrors{}
		raw := decodePlanBody(c, verrs)
		if raw == nil && len(verrs) > 0 {
			return verrs.invalid(c)
		}
		if v, present := raw["name"]; present && v != nil {
			if s, isStr := v.(string); !isStr || s == "" || len([]rune(s)) > 255 {
				verrs.add("name", "The name field is invalid.")
			} else {
				cur.Name = s
			}
		}
		if _, present := raw["entries"]; present {
			entries, ok := validateEntries(raw, verrs, true)
			if ok {
				cur.EntriesJSON = marshalEntries(entries)
			}
		}
		if v, present := raw["default_runs_per_slot"]; present && v != nil {
			if n, ok := intFieldJSON2(map[string]any{"v": v}, verrs, "v", 1, 100000, true); ok {
				cur.DefaultRunsPerSlot = n
			} else {
				verrs.add("default_runs_per_slot", "The default runs per slot field is invalid.")
			}
		}
		if v, present := raw["rig_bonus"]; present && v != nil {
			if n, ok := v.(float64); !ok || n < 0 || n > 10 {
				verrs.add("rig_bonus", "The rig bonus field must be between 0 and 10.")
			} else {
				cur.RigBonus = n
			}
		}
		for _, f := range []struct {
			key     string
			allowed []string
		}{
			{"structure_type", []string{"athanor", "tatara"}},
			{"rig_tier", []string{"none", "t1", "t2"}},
			{"security_space", []string{"lowsec", "nullsec"}},
		} {
			if v, present := raw[f.key]; present && v != nil {
				s, isStr := v.(string)
				ok := isStr
				if ok {
					ok = false
					for _, a := range f.allowed {
						if s == a {
							ok = true
							break
						}
					}
				}
				if !ok {
					verrs.add(f.key, "The selected "+f.key+" is invalid.")
					continue
				}
				switch f.key {
				case "structure_type":
					cur.StructureType = s
				case "rig_tier":
					cur.RigTier = s
				case "security_space":
					cur.SecuritySpace = s
				}
			}
		}
		if v, present := raw["facility_tax"]; present {
			if v == nil {
				cur.FacilityTax = 0
			} else if n, isNum := v.(float64); !isNum || n < 0 || n > 50 {
				verrs.add("facility_tax", "The facility tax field must be between 0 and 50.")
			} else {
				cur.FacilityTax = n
			}
		}
		if v, present := raw["station_id"]; present {
			if v == nil {
				cur.StationID = nil
			} else if n, ok := intFieldJSON2(map[string]any{"v": v}, verrs, "v", 1, 1<<31-1, true); ok {
				id := int64(n)
				cur.StationID = &id
			} else {
				verrs.add("station_id", "The station id field must be an integer.")
			}
		}
		if v, present := raw["solar_system_id"]; present {
			if v == nil {
				cur.SolarSystemID = nil
			} else if n, ok := intFieldJSON2(map[string]any{"v": v}, verrs, "v", 1, 1<<31-1, true); ok {
				cur.SolarSystemID = &n
			} else {
				verrs.add("solar_system_id", "The solar system id field must be an integer.")
			}
		}
		if v, present := raw["price_region_id"]; present {
			if v == nil {
				cur.PriceRegionID = nil
			} else if n, ok := intFieldJSON2(map[string]any{"v": v}, verrs, "v", 1, 1<<31-1, true); ok {
				cur.PriceRegionID = &n
			} else {
				verrs.add("price_region_id", "The price region id field must be an integer.")
			}
		}
		if len(verrs) > 0 {
			return verrs.invalid(c)
		}
		row, err := d.Plans.UpdateReactionPlan(ctx, *cur)
		if err != nil {
			return dbError(c, err)
		}
		return Success(c, http.StatusOK, reactionPlanShape(*row, parseEntries(row.EntriesJSON)), nil)
	}
}

func handleReactionPlanDestroy(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		id, ok := planID(c)
		if !ok {
			return errHandled
		}
		deleted, err := d.Plans.DeleteReactionPlan(c.Request().Context(), id, c.Get(string(ctxUIDKey)).(int64))
		if err != nil {
			return dbError(c, err)
		}
		if !deleted {
			return Failure(c, http.StatusNotFound, "Not found.", nil)
		}
		return c.NoContent(http.StatusNoContent)
	}
}
