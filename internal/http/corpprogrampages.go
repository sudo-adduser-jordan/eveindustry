package http

import (
	"context"
	"net/http"
	"sort"
	"strconv"

	"github.com/labstack/echo/v4"

	"github.com/sudo-adduser-jordan/eveindustry/internal/auth"
	"github.com/sudo-adduser-jordan/eveindustry/internal/store"
)

// registerCorpProgramPages mounts the corporate build-program page: the
// feature overview, enable/disable and the per-program show page. Reads
// never 403 — the page offers to enable the feature instead.
func registerCorpProgramPages(v1 *echo.Group, d Deps) {
	if d.Corp == nil || d.CorpPrograms == nil || d.Users == nil || d.Industry == nil {
		return
	}
	v1.GET("/corporation/build-programs", handleCorpProgramPagesIndex(d))
	v1.POST("/corporation/build-programs/enable", handleCorpProgramPagesEnable(d))
	v1.POST("/corporation/build-programs/disable", handleCorpProgramPagesDisable(d))
	v1.GET("/corporation/build-programs/:id", handleCorpProgramPagesShow(d))
}

const corpAssetScope = "esi-assets.read_corporation_assets.v1"

// corpConfigForUser mirrors CorporationBuildProgramService::getConfigForUser:
// the active config of the first user corporation that has one.
func corpConfigForUser(ctx context.Context, d Deps, uid int64) (*store.CorpConfigFull, []auth.Character, error) {
	chars, err := d.Users.CharactersForUser(ctx, uid)
	if err != nil {
		return nil, nil, err
	}
	var ids []int64
	seen := map[int64]bool{}
	for _, ch := range chars {
		if ch.CorporationID == nil || *ch.CorporationID == 0 || seen[*ch.CorporationID] {
			continue
		}
		seen[*ch.CorporationID] = true
		ids = append(ids, *ch.CorporationID)
	}
	cfg, err := d.Corp.CorpConfigForCorps(ctx, ids)
	if err != nil {
		return nil, nil, err
	}
	return cfg, chars, nil
}

// eligibleCorpChars mirrors getEligibleCharactersForUser: Director/CEO
// standing plus the corporation-assets ESI scope.
func eligibleCorpChars(chars []auth.Character) []auth.Character {
	var out []auth.Character
	for _, ch := range chars {
		if ch.CorporationID == nil || *ch.CorporationID == 0 {
			continue
		}
		director := ch.IsDirectorCEO || hasRole(ch.Roles, "Director")
		if !director || !hasScope(ch, corpAssetScope) {
			continue
		}
		out = append(out, ch)
	}
	return out
}

// corpProgramsOverview mirrors the page controller's overview payload.
func corpProgramsOverview(ctx context.Context, d Deps, uid int64) (map[string]any, error) {
	cfg, chars, err := corpConfigForUser(ctx, d, uid)
	if err != nil {
		return nil, err
	}
	eligible := eligibleCorpChars(chars)
	elig := make([]any, 0, len(eligible))
	for _, ch := range eligible {
		var corpName any
		if ch.CorporationName != nil {
			corpName = *ch.CorporationName
		}
		elig = append(elig, map[string]any{
			"character_id": ch.CharacterID, "name": ch.Name, "corporation_name": corpName,
		})
	}
	out := map[string]any{
		"enabled": false, "enabled_by_me": false,
		"corporation_name": nil, "provider_character_name": nil,
		"can_view": false, "can_manage": false,
		"eligible_characters": elig, "programs": []any{},
	}
	if cfg == nil {
		return out, nil
	}
	out["enabled"] = true
	out["enabled_by_me"] = cfg.EnabledByUserID == uid
	if cfg.CorporationName.Valid {
		out["corporation_name"] = cfg.CorporationName.String
	}
	// provider character name: the enabling character among the user's own
	provider := ""
	for _, ch := range chars {
		if ch.CharacterID == cfg.CharacterID {
			provider = ch.Name
			break
		}
	}
	if provider != "" {
		out["provider_character_name"] = provider
	}
	canView := false
	for _, ch := range chars {
		if ch.CorporationID != nil && *ch.CorporationID == cfg.CorporationID {
			canView = true
			break
		}
	}
	out["can_view"] = canView
	canManage := false
	if canView {
		members, err := d.Corp.UserCorpCharacters(ctx, uid, cfg.CorporationID)
		if err != nil {
			return nil, err
		}
		for _, m := range members {
			if m.IsDirectorCEO || hasRole(m.Roles, "Factory_Manager") {
				canManage = true
				break
			}
		}
	}
	out["can_manage"] = canManage
	if !canView {
		return out, nil
	}
	rows, err := d.CorpPrograms.CorpPrograms(ctx, cfg.CorporationID)
	if err != nil {
		return nil, err
	}
	progs := make([]any, 0, len(rows))
	for _, r := range rows {
		progs = append(progs, corpProgramSummaryShape(r))
	}
	out["programs"] = progs
	return out, nil
}

func corpProgramSummaryShape(p store.CorpProgram) map[string]any {
	return map[string]any{
		"id": p.ID, "name": p.Name, "program_type": p.ProgramType,
		"t2_type_id": nullInt64Val(p.T2TypeID), "t2_type_name": nullStrVal(p.T2TypeName),
		"created_at": p.CreatedAt.UTC().Format("2006-01-02T15:04:05Z"),
		"updated_at": p.UpdatedAt.UTC().Format("2006-01-02T15:04:05Z"),
	}
}

// GET /corporation/build-programs — the overview, never 403.
func handleCorpProgramPagesIndex(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		uid := c.Get(string(ctxUIDKey)).(int64)
		out, err := corpProgramsOverview(c.Request().Context(), d, uid)
		if err != nil {
			return dbError(c, err)
		}
		return Success(c, http.StatusOK, out, nil)
	}
}

// POST /corporation/build-programs/enable — 422 without an eligible
// character; returns the new overview.
func handleCorpProgramPagesEnable(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		uid := c.Get(string(ctxUIDKey)).(int64)
		ctx := c.Request().Context()
		chars, err := d.Users.CharactersForUser(ctx, uid)
		if err != nil {
			return dbError(c, err)
		}
		eligible := eligibleCorpChars(chars)
		verrs := validationErrors{}
		raw := decodePlanBody(c, verrs)
		if raw == nil {
			raw = map[string]any{}
		}
		if len(verrs) > 0 {
			return verrs.invalid(c)
		}
		var picked *auth.Character
		if v, present := raw["character_id"]; present && v != nil {
			n, isNum := toFloat(v)
			if !isNum || n != float64(int64(n)) {
				verrs.add("character_id", "The character id field must be an integer.")
				return verrs.invalid(c)
			}
			for i := range eligible {
				if eligible[i].CharacterID == int64(n) {
					picked = &eligible[i]
					break
				}
			}
			if picked == nil {
				if len(eligible) == 0 {
					verrs.add("character_id", "No eligible character found. A character with the Director or CEO role and the corporation-assets ESI scope is required.")
				} else {
					verrs.add("character_id", "The selected character is not eligible.")
				}
				return verrs.invalid(c)
			}
		} else if len(eligible) > 0 {
			picked = &eligible[0]
		}
		if picked == nil {
			verrs.add("character_id", "No eligible character found. A character with the Director or CEO role and the corporation-assets ESI scope is required.")
			return verrs.invalid(c)
		}
		var corpName string
		if picked.CorporationName != nil {
			corpName = *picked.CorporationName
		}
		if _, err := d.Corp.UpsertCorpConfig(ctx, *picked.CorporationID, corpName, picked.CharacterID, uid); err != nil {
			return dbError(c, err)
		}
		out, err := corpProgramsOverview(ctx, d, uid)
		if err != nil {
			return dbError(c, err)
		}
		return Success(c, http.StatusOK, out, nil)
	}
}

// POST /corporation/build-programs/disable — 403 unless the caller enabled
// the feature; programs are kept. Returns the new overview.
func handleCorpProgramPagesDisable(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		uid := c.Get(string(ctxUIDKey)).(int64)
		ctx := c.Request().Context()
		cfg, _, err := corpConfigForUser(ctx, d, uid)
		if err != nil {
			return dbError(c, err)
		}
		if cfg == nil || cfg.EnabledByUserID != uid {
			return Failure(c, http.StatusForbidden, "Only the user who enabled this feature can disable it.", nil)
		}
		if err := d.Corp.DeactivateCorpConfig(ctx, cfg.CorporationID); err != nil {
			return dbError(c, err)
		}
		out, err := corpProgramsOverview(ctx, d, uid)
		if err != nil {
			return dbError(c, err)
		}
		return Success(c, http.StatusOK, out, nil)
	}
}

// GET /corporation/build-programs/:id — program detail + material volumes.
// 404 for foreign programs and whenever the feature is off for the user.
func handleCorpProgramPagesShow(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		id, ok := planID(c)
		if !ok {
			return errHandled
		}
		uid := c.Get(string(ctxUIDKey)).(int64)
		ctx := c.Request().Context()
		cfg, _, err := corpConfigForUser(ctx, d, uid)
		if err != nil {
			return dbError(c, err)
		}
		if cfg == nil {
			return Failure(c, http.StatusNotFound, "Not found.", nil)
		}
		row, err := d.CorpPrograms.CorpProgram(ctx, id, cfg.CorporationID)
		if err != nil {
			return dbError(c, err)
		}
		if row == nil {
			return Failure(c, http.StatusNotFound, "Not found.", nil)
		}
		canManage := false
		members, err := d.Corp.UserCorpCharacters(ctx, uid, cfg.CorporationID)
		if err != nil {
			return dbError(c, err)
		}
		for _, m := range members {
			if m.IsDirectorCEO || hasRole(m.Roles, "Factory_Manager") {
				canManage = true
				break
			}
		}
		var corpName any
		if cfg.CorporationName.Valid {
			corpName = cfg.CorporationName.String
		}
		steps := decodeSteps(row.StepsJSON)
		typeSet := map[int]bool{}
		collectCorpMaterialIDs(steps, typeSet)
		ids := make([]int, 0, len(typeSet))
		for id := range typeSet {
			ids = append(ids, id)
		}
		sort.Ints(ids)
		vols, err := d.Industry.TypeVolumes(ctx, ids)
		if err != nil {
			return dbError(c, err)
		}
		volMap := map[string]float64{}
		for _, id := range ids {
			volMap[strconv.Itoa(id)] = vols[id]
		}
		data, _ := corpProgramDetailShape(*row)
		return Success(c, http.StatusOK, map[string]any{
			"program": data, "corporation_name": corpName,
			"provider_character_name": nil, "can_manage": canManage, "volumes": volMap,
		}, nil)
	}
}

// collectCorpMaterialIDs mirrors the page controller's step-tree walk.
func collectCorpMaterialIDs(steps []any, into map[int]bool) {
	for _, s := range steps {
		m, ok := s.(map[string]any)
		if !ok {
			continue
		}
		if mats, ok := m["materials"].([]any); ok {
			for _, mm := range mats {
				if mat, ok := mm.(map[string]any); ok {
					if n, isNum := toFloat(mat["type_id"]); isNum && n > 0 {
						into[int(n)] = true
					}
				}
			}
		}
		if kids, ok := m["children"].([]any); ok {
			collectCorpMaterialIDs(kids, into)
		}
	}
}
