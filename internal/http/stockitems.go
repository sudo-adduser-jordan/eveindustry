package http

import (
	"net/http"

	"github.com/labstack/echo/v4"

	"github.com/sudo-adduser-jordan/eveindustry/internal/store"
)

// registerStockItems mounts the stock-manager item CRUD (stock-manager gate
// + per-user ownership, 404 for foreign ids).
func registerStockItems(v1 *echo.Group, d Deps) {
	if d.StockItems == nil || d.Industry == nil {
		return
	}
	v1.POST("/stock/items", handleStockItemStore(d), requireStockManager)
	v1.PUT("/stock/items/:id", handleStockItemUpdate(d), requireStockManager)
	v1.DELETE("/stock/items/:id", handleStockItemDestroy(d), requireStockManager)
}

// POST /stock/items — upsert by (user, type): create or change the wanted
// quantity. 201. Type must exist in eve_types.
func handleStockItemStore(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		verrs := validationErrors{}
		raw := decodePlanBody(c, verrs)
		if raw == nil && len(verrs) > 0 {
			return verrs.invalid(c)
		}
		var typeID int
		if v, present := raw["type_id"]; !present || v == nil {
			verrs.add("type_id", "The type id field is required.")
		} else if n, ok := toFloat(v); !ok || n != float64(int(n)) || int(n) < 1 {
			verrs.add("type_id", "The type id field must be an integer greater than 0.")
		} else {
			typeID = int(n)
		}
		var qty int64 = -1
		if v, present := raw["desired_quantity"]; !present || v == nil {
			verrs.add("desired_quantity", "The desired quantity field is required.")
		} else if n, ok := toFloat(v); !ok || n != float64(int64(n)) || n < 0 {
			verrs.add("desired_quantity", "The desired quantity field must be an integer of at least 0.")
		} else {
			qty = int64(n)
		}
		if len(verrs) > 0 {
			return verrs.invalid(c)
		}
		uid := c.Get(string(ctxUIDKey)).(int64)
		ctx := c.Request().Context()
		names, err := d.Industry.TypeNames(ctx, []int{typeID})
		if err != nil {
			return dbError(c, err)
		}
		name, ok := names[typeID]
		if !ok {
			verrs.add("type_id", "The selected type id is invalid.")
			return verrs.invalid(c)
		}
		row, err := d.StockItems.UpsertWatchedItem(ctx, store.WatchedItemUpsert{
			UserID: uid, TypeID: typeID, TypeName: name, DesiredQuantity: qty,
		})
		if err != nil {
			return dbError(c, err)
		}
		return Success(c, http.StatusCreated, stockItemShape(*row), nil)
	}
}

func stockItemShape(r store.WatchedItem) map[string]any {
	return map[string]any{
		"id": r.ID, "type_id": r.TypeID, "type_name": r.TypeName,
		"desired_quantity": r.DesiredQuantity,
	}
}

// PUT /stock/items/:id — change the wanted quantity. 404 foreign/missing.
func handleStockItemUpdate(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		id, ok := stockItemID(c)
		if !ok {
			return errHandled
		}
		verrs := validationErrors{}
		raw := decodePlanBody(c, verrs)
		if raw == nil && len(verrs) > 0 {
			return verrs.invalid(c)
		}
		v, present := raw["desired_quantity"]
		if !present || v == nil {
			verrs.add("desired_quantity", "The desired quantity field is required.")
			return verrs.invalid(c)
		}
		n, isNum := toFloat(v)
		if !isNum || n != float64(int64(n)) || n < 0 {
			verrs.add("desired_quantity", "The desired quantity field must be an integer of at least 0.")
			return verrs.invalid(c)
		}
		uid := c.Get(string(ctxUIDKey)).(int64)
		row, err := d.StockItems.UpdateWatchedItemQuantity(c.Request().Context(), id, uid, int64(n))
		if err != nil {
			return dbError(c, err)
		}
		if row == nil {
			return Failure(c, http.StatusNotFound, "Not found.", nil)
		}
		return Success(c, http.StatusOK, stockItemShape(*row), nil)
	}
}

// DELETE /stock/items/:id — 204. 404 foreign/missing.
func handleStockItemDestroy(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		id, ok := stockItemID(c)
		if !ok {
			return errHandled
		}
		uid := c.Get(string(ctxUIDKey)).(int64)
		deleted, err := d.StockItems.DeleteWatchedItem(c.Request().Context(), id, uid)
		if err != nil {
			return dbError(c, err)
		}
		if !deleted {
			return Failure(c, http.StatusNotFound, "Not found.", nil)
		}
		return c.NoContent(http.StatusNoContent)
	}
}

func stockItemID(c echo.Context) (int64, bool) {
	id, err := strconvParseID(c.Param("id"))
	if err != nil || id < 1 {
		_ = Failure(c, http.StatusNotFound, "Not found.", nil)
		return 0, false
	}
	return id, true
}
