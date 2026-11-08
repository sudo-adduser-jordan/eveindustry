package http

import (
	"context"
	"net/http"

	"github.com/labstack/echo/v4"

	"github.com/sudo-adduser-jordan/eveindustry/internal/auth"
	"github.com/sudo-adduser-jordan/eveindustry/internal/esi"
	"github.com/sudo-adduser-jordan/eveindustry/internal/market"
	"github.com/sudo-adduser-jordan/eveindustry/internal/store"
)

// StockReportDeps carries the ESI + stock stores for the stock report.
type StockReportDeps struct {
	Characters StockReportESI
	Stock      store.StockStore
}

// StockReportESI is the ESI surface the report needs.
type StockReportESI interface {
	GetCharacterRoles(ctx context.Context, characterID int64) (map[string]any, error)
	GetCorporationAssets(ctx context.Context, characterID, corporationID int64) ([]map[string]any, error)
}


// registerStockReport mounts GET /industry/stock behind the stock-manager
// gate (403 like the PHP stock-manager middleware).
func registerStockReport(v1 *echo.Group, d Deps) {
	if d.StockReport.Characters == nil || d.StockReport.Stock == nil || d.Users == nil {
		return
	}
	v1.GET("/stock", handleStockReport(d), requireStockManager)
}

// requireStockManager mirrors the stock-manager middleware: 403 without the
// feature flag.
func requireStockManager(next echo.HandlerFunc) echo.HandlerFunc {
	return func(c echo.Context) error {
		if u, ok := CurrentUser(c); !ok || !u.IsStockManager {
			return Failure(c, http.StatusForbidden, "Access to the Industry Stock Manager has not been activated for your account.", nil)
		}
		return next(c)
	}
}

// corpAssetsWarning ports AssetService::getCorporationAssetsWarning(): nil
// when corporate assets are readable, else the human reason.
func corpAssetsWarning(ctx context.Context, d Deps, main *auth.Character) *string {
	if main == nil {
		return strPtr("No main character is set. Corporation assets can only be retrieved by your main character. Please mark a character as your main.")
	}
	has := func(scope string) bool {
		for _, s := range main.Scopes {
			if s == scope {
				return true
			}
		}
		return false
	}
	if !has("esi-assets.read_corporation_assets.v1") {
		msg := "Your main character (" + main.Name + ") does not have the Corporation Assets ESI scope. Re-authenticate to grant the required scope."
		return &msg
	}
	if has("esi-characters.read_corporation_roles.v1") {
		roles, err := d.StockReport.Characters.GetCharacterRoles(ctx, main.CharacterID)
		if err != nil {
			return nil
		}
		var list []string
		if r, ok := roles["roles"].([]any); ok {
			for _, v := range r {
				if s, ok := v.(string); ok {
					list = append(list, s)
				}
			}
		}
		if !hasRole(list, "Director") && !hasRole(list, "CEO") {
			msg := "Your main character (" + main.Name + ") does not hold the Director or CEO role in their corporation. Corporation assets cannot be retrieved."
			return &msg
		}
	}
	return nil
}

// GET /industry/stock — mirrors StockReportController@index. Reads live
// corporation hangars instead of the snapshot pipeline (which warms in the
// jobs phase); the refresh route is a later slice.
func handleStockReport(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		uid := c.Get(string(ctxUIDKey)).(int64)
		ctx := c.Request().Context()
		chars, err := d.Users.CharactersForUser(ctx, uid)
		if err != nil {
			return dbError(c, err)
		}
		var main *auth.Character
		for i := range chars {
			if chars[i].IsMain {
				main = &chars[i]
				break
			}
		}
		onHand := map[int]int{}
		warning := corpAssetsWarning(ctx, d, main)
		if warning == nil && main != nil && main.CorporationID != nil {
			assets, err := d.StockReport.Characters.GetCorporationAssets(ctx, main.CharacterID, *main.CorporationID)
			if err != nil {
				if re, ok := err.(*esi.RequestError); ok && re.Outcome == esi.OutcomeCredential {
					msg := "Corporation assets are no longer accessible; re-authenticate."
					warning = &msg
				} else {
					return Failure(c, http.StatusInternalServerError, "ESI request failed: "+err.Error(), nil)
				}
			} else {
				for _, a := range assets {
					id := intVal(a["type_id"], 0)
					if id > 0 {
						onHand[id] += intVal(a["quantity"], 0)
					}
				}
			}
		}
		items, err := d.StockReport.Stock.WatchedItems(ctx, uid)
		if err != nil {
			return dbError(c, err)
		}
		out := make([]any, 0, len(items))
		for _, it := range items {
			desired := int(it.DesiredQuantity)
			hand := onHand[it.TypeID]
			short := desired - hand
			if short < 0 {
				short = 0
			}
			out = append(out, map[string]any{
				"id": it.ID, "type_id": it.TypeID, "type_name": it.TypeName,
				"desired_quantity": desired,
				"on_hand": hand, "shortfall": short,
				"icon_url": market.TypeImageURL(it.TypeID),
			})
		}
		var warn any
		if warning != nil {
			warn = *warning
		}
		return Success(c, http.StatusOK, out, map[string]any{"warning": warn})
	}
}

func strPtr(s string) *string { return &s }
