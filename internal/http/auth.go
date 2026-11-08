package http

import (
	"net/http"

	"github.com/labstack/echo/v4"

	"github.com/sudo-adduser-jordan/eveindustry/internal/auth"
)

// AppConfig carries the public app metadata MeResource exposes.
type AppConfig struct {
	SourceURL  string
	DiscordURL string
	DiscordBot bool
}

type ctxKey string

const (
	ctxUserKey ctxKey = "auth.user"
	ctxUIDKey  ctxKey = "auth.uid"
)

// CurrentUser returns the authenticated user set by RequireAuth.
func CurrentUser(c echo.Context) (*auth.User, bool) {
	u, ok := c.Get(string(ctxUserKey)).(*auth.User)
	return u, ok && u != nil
}

// RequireAuth mirrors auth:sanctum for the versioned API: no valid session
// (or no such user) is a 401 unauthenticated, never a redirect. /health
// stays public; register it before this middleware runs.
func RequireAuth(d Deps) echo.MiddlewareFunc {
	return func(next echo.HandlerFunc) echo.HandlerFunc {
		return func(c echo.Context) error {
			if d.Sessions == nil || d.Users == nil {
				return Failure(c, http.StatusUnauthorized, "Unauthenticated.", nil)
			}
			uid, ok := d.Sessions.UserID(c.Request())
			if !ok {
				return Failure(c, http.StatusUnauthorized, "Unauthenticated.", nil)
			}
			u, err := d.Users.UserByID(c.Request().Context(), uid)
			if err != nil {
				return dbError(c, err)
			}
			if u == nil {
				return Failure(c, http.StatusUnauthorized, "Unauthenticated.", nil)
			}
			c.Set(string(ctxUserKey), u)
			c.Set(string(ctxUIDKey), uid)
			return next(c)
		}
	}
}

// BlockDemo mirrors BlockDemoUser: demo accounts are read-mostly; writes
// outside the allow list get 403 demo_restricted.
func BlockDemo(next echo.HandlerFunc) echo.HandlerFunc {
	return func(c echo.Context) error {
		if u, ok := CurrentUser(c); ok {
			if auth.DemoWriteBlocked(u.IsDemo, c.Request().Method, c.Request().URL.Path) {
				return c.JSON(http.StatusForbidden, map[string]any{
					"message": "This is disabled on the demo account.",
					"code":    "demo_restricted",
				})
			}
		}
		return next(c)
	}
}

// GET /me — mirrors MeController@show: the user with main-first characters.
func handleMe(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		u, ok := CurrentUser(c)
		if !ok {
			return Failure(c, http.StatusUnauthorized, "Unauthenticated.", nil)
		}
		uid := c.Get(string(ctxUIDKey)).(int64)
		chars, err := d.Users.CharactersForUser(c.Request().Context(), uid)
		if err != nil {
			return dbError(c, err)
		}
		return Success(c, http.StatusOK, auth.MePayload(u, chars, d.App.SourceURL, d.App.DiscordURL, d.App.DiscordBot), nil)
	}
}

// POST /logout — mirrors SessionController@destroy: clear the session, 204.
func handleLogout(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		if d.Sessions != nil {
			d.Sessions.Destroy(c.Response(), c.Request())
		}
		return c.NoContent(http.StatusNoContent)
	}
}

// DELETE /account — mirrors AccountController@destroy: delete the user row
// (characters cascade), clear the session, 204.
func handleAccountDelete(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		uid, ok := c.Get(string(ctxUIDKey)).(int64)
		if !ok {
			return Failure(c, http.StatusUnauthorized, "Unauthenticated.", nil)
		}
		if err := d.Users.DeleteUser(c.Request().Context(), uid); err != nil {
			return dbError(c, err)
		}
		if d.Sessions != nil {
			d.Sessions.Destroy(c.Response(), c.Request())
		}
		return c.NoContent(http.StatusNoContent)
	}
}
