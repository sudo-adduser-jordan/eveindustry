package http

import (
	"context"
	"net/http"
	"strings"

	"github.com/labstack/echo/v4"

	"github.com/sudo-adduser-jordan/eveindustry/internal/auth"
)

type AppConfig struct {
	SourceURL string
}

type ctxKey string

const (
	ctxUserKey ctxKey = "auth.user"
	ctxUIDKey  ctxKey = "auth.uid"
)

func CurrentUser(c echo.Context) (*auth.User, bool) {
	u, ok := c.Get(string(ctxUserKey)).(*auth.User)
	return u, ok && u != nil
}

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
		return Success(c, http.StatusOK, auth.MePayload(u, chars, d.App.SourceURL), nil)
	}
}

func handleLogout(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		if d.Sessions != nil {
			d.Sessions.Destroy(c.Response(), c.Request())
		}
		return c.NoContent(http.StatusNoContent)
	}
}

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

const DemoEmail = "demo@eve-industrialist.invalid"

type DemoUsers interface {
	EnsureDemoUser(ctx context.Context, email string) (int64, error)
	TouchDemoUser(ctx context.Context, id int64) error
}

func sessionUser(c echo.Context, d Deps) *auth.User {
	if d.Sessions == nil || d.Users == nil {
		return nil
	}
	uid, ok := d.Sessions.UserID(c.Request())
	if !ok {
		return nil
	}
	u, err := d.Users.UserByID(c.Request().Context(), uid)
	if err != nil || u == nil {
		return nil
	}
	return u
}

func handleLogin(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		if sessionUser(c, d) != nil {
			return c.Redirect(http.StatusFound, "/app/dashboard")
		}
		return c.Redirect(http.StatusFound, "/app/login")
	}
}

func handleWelcome(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		if sessionUser(c, d) != nil {
			return c.Redirect(http.StatusFound, "/app/dashboard")
		}
		return c.Redirect(http.StatusFound, "/app/login")
	}
}

func handleDemoLogin(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		if !d.DemoEnabled || d.DemoUsers == nil || d.Sessions == nil {
			return Failure(c, http.StatusNotFound, "Not Found.", nil)
		}
		uid, err := d.DemoUsers.EnsureDemoUser(c.Request().Context(), DemoEmail)
		if err != nil {
			return dbError(c, err)
		}
		if err := d.DemoUsers.TouchDemoUser(c.Request().Context(), uid); err != nil {
			return dbError(c, err)
		}
		warmDemoAssets(c.Request().Context(), d, uid)
		d.Sessions.Issue(c.Response(), uid)
		if strings.Contains(c.Request().Header.Get("Accept"), "application/json") {
			return Success(c, http.StatusOK, map[string]any{"redirect": "/app/dashboard"}, nil)
		}
		return c.Redirect(http.StatusFound, "/app/dashboard")
	}
}
