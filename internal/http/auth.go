package http

import (
	"context"
	"html"
	"net/http"
	"strings"

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

// DemoEmail is the shared demo account email. It matches the address
// jobs.ResetDemoAccount warms, so demo sign-in lands on the reset data.
const DemoEmail = "demo@eve-industrialist.invalid"

// DemoUsers creates and touches the shared demo account for
// POST /demo/login. store.SQL implements it.
type DemoUsers interface {
	EnsureDemoUser(ctx context.Context, email string) (int64, error)
	TouchDemoUser(ctx context.Context, id int64) error
}

// sessionUser returns the logged-in user, or nil for anonymous callers and
// lookup failures. The login pages use it to bounce signed-in visitors to
// the app instead of failing on a sick database.
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

// GET /login — public sign-in page. The SPA redirects here on 401, so this
// must stay outside the /api/v1 envelope: unknown-path JSON 404s are what
// made the redirect look like a broken API response.
func handleLogin(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		if sessionUser(c, d) != nil {
			return c.Redirect(http.StatusFound, "/app/dashboard")
		}
		return c.HTML(http.StatusOK, loginPage(d))
	}
}

// GET /welcome — public landing page. Logout and account deletion land here.
func handleWelcome(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		if sessionUser(c, d) != nil {
			return c.Redirect(http.StatusFound, "/app/dashboard")
		}
		return c.HTML(http.StatusOK, welcomePage(d))
	}
}

// POST /demo/login — signs in the shared demo account. 404s when demo mode
// is off, like the PHP route.
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
		d.Sessions.Issue(c.Response(), uid)
		return c.Redirect(http.StatusFound, "/app/dashboard")
	}
}

func loginPage(d Deps) string {
	var b strings.Builder
	b.WriteString(`<!doctype html><html lang="en"><head><meta charset="utf-8">` +
		`<meta name="viewport" content="width=device-width,initial-scale=1">` +
		`<title>Log in — Eve Industrialist</title></head><body><main>`)
	b.WriteString(`<h1>Eve Industrialist</h1>`)
	b.WriteString(`<p>Planning and tracking tools for EVE Online industrialists. ` +
		`Unofficial fan tool. EVE Online © CCP hf.</p>`)
	if d.SSOConfigured {
		b.WriteString(`<p>EVE SSO sign-in has not been ported to the Go server yet; ` +
			`the SSO callback arrives in a later phase.</p>`)
	} else {
		b.WriteString(`<p>EVE SSO sign-in is not configured ` +
			`(set <code>EVE_CLIENT_ID</code> and <code>EVE_CLIENT_SECRET</code>).</p>`)
	}
	if d.DemoEnabled {
		b.WriteString(`<form method="post" action="/demo/login">` +
			`<button type="submit">Try the demo</button></form>`)
	} else {
		b.WriteString(`<p>Demo mode is off (set <code>DEMO_ENABLED=true</code>).</p>`)
	}
	b.WriteString(`<p><a href="/app/dashboard">Open the app</a></p>`)
	if d.App.SourceURL != "" {
		b.WriteString(`<p><a href="` + html.EscapeString(d.App.SourceURL) + `">Source (AGPL-3.0)</a></p>`)
	}
	b.WriteString(`</main></body></html>`)
	return b.String()
}

func welcomePage(d Deps) string {
	var b strings.Builder
	b.WriteString(`<!doctype html><html lang="en"><head><meta charset="utf-8">` +
		`<meta name="viewport" content="width=device-width,initial-scale=1">` +
		`<title>Welcome — Eve Industrialist</title></head><body><main>`)
	b.WriteString(`<h1>Eve Industrialist</h1>`)
	b.WriteString(`<p>You are signed out. Planning and tracking tools for ` +
		`EVE Online industrialists.</p>`)
	b.WriteString(`<p><a href="/login">Log in</a> · <a href="/app/dashboard">Open the app</a></p>`)
	if d.App.SourceURL != "" {
		b.WriteString(`<p><a href="` + html.EscapeString(d.App.SourceURL) + `">Source (AGPL-3.0)</a></p>`)
	}
	b.WriteString(`</main></body></html>`)
	return b.String()
}
