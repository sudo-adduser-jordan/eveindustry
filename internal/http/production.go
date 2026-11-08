package http

import (
	"context"
	"net/http"
	"strconv"

	"github.com/labstack/echo/v4"

	"github.com/sudo-adduser-jordan/eveindustry/internal/auth"
	"github.com/sudo-adduser-jordan/eveindustry/internal/calc"
	"github.com/sudo-adduser-jordan/eveindustry/internal/esi"
	"github.com/sudo-adduser-jordan/eveindustry/internal/industry"
)

// ProductionESI is the ESI surface can-produce needs.
// *esi.CharacterClient satisfies it; tests stub it.
type ProductionESI interface {
	GetBlueprints(ctx context.Context, characterID int64) ([]map[string]any, error)
	GetAssets(ctx context.Context, characterID int64) ([]map[string]any, error)
	GetCharacterRoles(ctx context.Context, characterID int64) (map[string]any, error)
	GetCorporationBlueprints(ctx context.Context, characterID, corporationID int64) ([]map[string]any, error)
	GetCorporationAssets(ctx context.Context, characterID, corporationID int64) ([]map[string]any, error)
}

// ProductionDeps carries the ESI client for can-produce.
type ProductionDeps struct {
	Characters ProductionESI
}

func corpLogo(corporationID int64) string {
	if auth.IsDemoID(corporationID) {
		return "/demo/logo/" + itoa64(corporationID) + ".svg?size=64"
	}
	return "https://images.evetech.net/corporations/" + itoa64(corporationID) + "/logo?size=64"
}

func itoa64(n int64) string {
	if n == 0 {
		return "0"
	}
	var buf [20]byte
	i := len(buf)
	for n > 0 {
		i--
		buf[i] = byte('0' + n%10)
		n /= 10
	}
	return string(buf[i:])
}

// registerProduction mounts GET /industry/can-produce. The refresh endpoint
// is a writes-phase route (IndustryRefreshController); without the cache it
// would be a no-op, so only the live read lands here.
func registerProduction(v1 *echo.Group, d Deps) {
	if d.Production.Characters == nil || d.Users == nil || d.Industry == nil {
		return
	}
	v1.GET("/industry/can-produce", handleCanProduce(d))
}

// GET /industry/can-produce — mirrors CanProduceController@index: per
// character (and per eligible corporation) the blueprints held,
// cross-referenced with hangar materials. Computed live.
func handleCanProduce(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		uid := c.Get(string(ctxUIDKey)).(int64)
		ctx := c.Request().Context()
		chars, err := d.Users.CharactersForUser(ctx, uid)
		if err != nil {
			return dbError(c, err)
		}
		out := []any{}
		for _, ch := range chars {
			if !ch.IsEsiActive {
				continue
			}
			src, corp, err := charProduce(ctx, d, ch)
			if err != nil {
				if re, ok := err.(*esi.RequestError); ok && re.Outcome == esi.OutcomeCredential {
					continue
				}
				return Failure(c, http.StatusInternalServerError, "ESI request failed: "+err.Error(), nil)
			}
			out = append(out, src)
			if corp != nil {
				out = append(out, corp)
			}
		}
		return Success(c, http.StatusOK, out, nil)
	}
}

// charProduce ports buildCharacterSource + buildCorporationSource for one
// character: personal assets/blueprints, plus the corp hangars when the
// character holds Director/Station_Manager with the three corp scopes.
func charProduce(ctx context.Context, d Deps, ch auth.Character) (map[string]any, map[string]any, error) {
	personal, err := produceSource(ctx, d, ch.CharacterID, ch.Name, ch.PortraitURL(), nil)
	if err != nil {
		return nil, nil, err
	}
	var corp map[string]any
	has := func(scope string) bool {
		for _, s := range ch.Scopes {
			if s == scope {
				return true
			}
		}
		return false
	}
	if ch.CorporationID != nil && *ch.CorporationID != 0 &&
		has("esi-corporations.read_blueprints.v1") &&
		has("esi-assets.read_corporation_assets.v1") &&
		has("esi-characters.read_corporation_roles.v1") {
		roles, err := d.Production.Characters.GetCharacterRoles(ctx, ch.CharacterID)
		if err != nil {
			return nil, nil, err
		}
		var list []string
		if r, ok := roles["roles"].([]any); ok {
			for _, v := range r {
				if s, ok := v.(string); ok {
					list = append(list, s)
				}
			}
		}
		if hasRole(list, "Director") || hasRole(list, "Station_Manager") {
			bps, err := d.Production.Characters.GetCorporationBlueprints(ctx, ch.CharacterID, *ch.CorporationID)
			if err != nil {
				return nil, nil, err
			}
			assets, err := d.Production.Characters.GetCorporationAssets(ctx, ch.CharacterID, *ch.CorporationID)
			if err != nil {
				return nil, nil, err
			}
			corpName := ""
			if ch.CorporationName != nil {
				corpName = *ch.CorporationName
			}
			corp, err = produceSourceData(ctx, d, bps, assets, map[string]any{
				"type": "corporation", "character_id": ch.CharacterID, "character_name": ch.Name,
				"corporation_id": *ch.CorporationID, "corporation_name": corpName,
				"corporation_logo": corpLogo(*ch.CorporationID),
			})
			if err != nil {
				return nil, nil, err
			}
		}
	}
	return personal, corp, nil
}

func produceSource(ctx context.Context, d Deps, characterID int64, name, portrait string, corpName *string) (map[string]any, error) {
	bps, err := d.Production.Characters.GetBlueprints(ctx, characterID)
	if err != nil {
		return nil, err
	}
	assets, err := d.Production.Characters.GetAssets(ctx, characterID)
	if err != nil {
		return nil, err
	}
	cn := ""
	if corpName != nil {
		cn = *corpName
	}
	return produceSourceData(ctx, d, bps, assets, map[string]any{
		"type": "character", "character_id": characterID, "character_name": name,
		"portrait_url": portrait, "corporation_name": cn,
	})
}

func produceSourceData(ctx context.Context, d Deps, bps, assets []map[string]any, base map[string]any) (map[string]any, error) {
	materials := calc.AggregateAssetMaterials(assets)
	outBPs := make([]calc.ProductionBlueprint, 0, len(bps))
	for _, bp := range bps {
		outBPs = append(outBPs, calc.ProductionBlueprint{
			TypeID: intVal(bp["type_id"], 0),
			MaterialEfficiency: intVal(bp["material_efficiency"], 0),
			TimeEfficiency: intVal(bp["time_efficiency"], 0),
			Runs: intVal(bp["runs"], -1),
		})
	}
	acts := map[int]calc.SDEActivity{}
	bpIDs := map[int]bool{}
	for _, bp := range outBPs {
		bpIDs[bp.TypeID] = true
	}
	for id := range bpIDs {
		row, err := d.Industry.ActivityByBlueprint(ctx, id, industry.ActivityManufacturing)
		if err != nil {
			return nil, err
		}
		if row == nil {
			continue
		}
		mats := industry.DecodeJSONList(nullStrPtr(row.Materials))
		prods := industry.DecodeJSONList(nullStrPtr(row.Products))
		act := calc.SDEActivity{}
		for _, m := range mats {
			name, _ := m["name"].(string)
			act.Materials = append(act.Materials, calc.BaseMaterial{TypeID: intVal(m["type_id"], 0), Name: name, Quantity: intVal(m["quantity"], 0)})
		}
		for _, p := range prods {
			name, _ := p["name"].(string)
			act.Products = append(act.Products, calc.BaseMaterial{TypeID: intVal(p["type_id"], 0), Name: name, Quantity: intVal(p["quantity"], 0)})
		}
		acts[id] = act
	}
	names := map[int]string{}
	allIDs := map[int]bool{}
	for id := range materials {
		allIDs[id] = true
	}
	for _, bp := range outBPs {
		if a, ok := acts[bp.TypeID]; ok {
			for _, m := range a.Materials {
				if m.Name == "" {
					allIDs[m.TypeID] = true
				}
			}
		}
	}
	if len(allIDs) > 0 {
		ids := make([]int, 0, len(allIDs))
		for id := range allIDs {
			ids = append(ids, id)
		}
		if n, err := d.Industry.TypeNames(ctx, ids); err != nil {
			return nil, err
		} else {
			names = n
		}
	}
	// fill SDE-missing names from the type table
	for id, a := range acts {
		changed := false
		for i, m := range a.Materials {
			if m.Name == "" {
				if n := names[m.TypeID]; n != "" {
					a.Materials[i].Name = n
					changed = true
				}
			}
		}
		for i, p := range a.Products {
			if p.Name == "" {
				if n := names[p.TypeID]; n != "" {
					a.Products[i].Name = n
				} else {
					a.Products[i].Name = "Type #" + strconv.Itoa(p.TypeID)
				}
				changed = true
			}
		}
		if changed {
			acts[id] = a
		}
	}
	buildable := calc.CalculateBuildableItems(outBPs, materials, acts)
	items := make([]any, 0, len(buildable))
	for _, b := range buildable {
		mats := make([]any, 0, len(b.Materials))
		for _, m := range b.Materials {
			mats = append(mats, map[string]any{
				"type_id": m.TypeID, "name": m.Name, "required_per_run": m.RequiredPerRun,
				"available": m.Available, "runs_possible": m.RunsPossible,
			})
		}
		items = append(items, map[string]any{
			"blueprint_type_id": b.BlueprintTypeID, "product_type_id": b.ProductTypeID,
			"product_name": b.ProductName, "product_qty_per_run": b.ProductQtyPerRun,
			"me": b.ME, "te": b.TE, "max_runs": b.MaxRuns,
			"buildable_runs": b.BuildableRuns, "total_output": b.TotalOutput,
			"materials": mats,
		})
	}
	avail := calc.ResolveMaterialNames(materials, names)
	availOut := make([]any, 0, len(avail))
	for _, a := range avail {
		availOut = append(availOut, a)
	}
	base["material_count"] = len(materials)
	base["blueprint_count"] = len(bps)
	base["buildable"] = items
	base["available_materials"] = availOut
	return base, nil
}
