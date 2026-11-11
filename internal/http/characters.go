package http

import (
	"net/http"

	"github.com/labstack/echo/v4"
)

func registerCharacters(v1 *echo.Group, d Deps) {
	if d.Users == nil {
		return
	}
	v1.POST("/characters/:id/main", handleSetMain(d))
	v1.DELETE("/characters/:id", handleCharacterDelete(d))
}

func handleSetMain(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		id, ok := essID(c)
		if !ok {
			return errHandled
		}
		uid := c.Get(string(ctxUIDKey)).(int64)
		ctx := c.Request().Context()
		owner, _, _, err := d.Users.CharacterOwner(ctx, id)
		if err != nil {
			return dbError(c, err)
		}
		if owner == 0 || owner != uid {
			return Failure(c, http.StatusNotFound, "Not found.", nil)
		}
		if err := d.Users.SetMainCharacter(ctx, uid, id); err != nil {
			return dbError(c, err)
		}
		return c.NoContent(http.StatusNoContent)
	}
}

func handleCharacterDelete(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		id, ok := essID(c)
		if !ok {
			return errHandled
		}
		uid := c.Get(string(ctxUIDKey)).(int64)
		ctx := c.Request().Context()
		owner, _, count, err := d.Users.CharacterOwner(ctx, id)
		if err != nil {
			return dbError(c, err)
		}
		if owner == 0 || owner != uid {
			return Failure(c, http.StatusNotFound, "Not found.", nil)
		}
		if count <= 1 {
			verrs := validationErrors{}
			verrs.add("character", "Cannot remove your only character.")
			return verrs.invalid(c)
		}
		if err := d.Users.DeleteCharacter(ctx, uid, id); err != nil {
			return dbError(c, err)
		}
		return c.NoContent(http.StatusNoContent)
	}
}
