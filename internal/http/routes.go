package http

import (
	"context"
	"database/sql"
	"net/http"
	"time"

	"github.com/labstack/echo/v4"
	"github.com/labstack/echo/v4/middleware"

	"github.com/sudo-adduser-jordan/eveindustry/internal/health"
	"github.com/sudo-adduser-jordan/eveindustry/internal/industry"
	"github.com/sudo-adduser-jordan/eveindustry/internal/setup"
	"github.com/sudo-adduser-jordan/eveindustry/internal/store"
)

// Deps are the already-wired collaborators for the router. Probes are plain
// funcs so handlers stay thin and unit tests can stub DB/Redis.
type Deps struct {
	DBPing    func(ctx context.Context) error
	CachePing func(ctx context.Context) bool

	SetupSDE    setup.Probe
	SetupPrices setup.Probe
	SetupOrders setup.Probe

	// Reference and Market are nil when no database is configured: data
	// routes answer 503 while /up and /health keep their contracts.
	Reference store.ReferenceStore
	Market    store.MarketStore
	Industry  industry.IndustryStore
	Trading   store.TradingStore
}

// New builds the Echo router for the Go API. Routes mirror routes/api.php
// mounted at /api/v1 plus the /up health path from bootstrap/app.php.
func New(d Deps) *echo.Echo {
	e := echo.New()
	e.HideBanner = true
	e.HTTPErrorHandler = jsonErrorHandler

	e.Use(middleware.Recover())
	e.Use(middleware.Secure())

	e.GET("/up", func(c echo.Context) error {
		return c.String(http.StatusOK, "ok")
	})

	v1 := e.Group("/api/v1")
	v1.GET("/health", handleHealth(d))

	// Phase 2 ports auth (Sanctum cookies / EVE SSO); until then every
	// caller is unauthenticated, so data routes below are unreachable
	// without a session the same way PHP's auth:sanctum group is.
	// The 401 stub keeps the smoke test (GET /api/v1/me -> 401) green.
	v1.GET("/me", func(c echo.Context) error {
		return Failure(c, http.StatusUnauthorized, "Unauthenticated.", nil)
	})

	registerReference(v1, d)
	registerMarket(v1, d)
	registerIndustryReference(v1, d)
	registerTrading(v1, d)
	registerCalculators(v1, d)
	registerInvention(v1, d)

	return e
}

// handleHealth mirrors App\Http\Controllers\Api\V1\HealthController: 200 when
// every check is ok, 503 when any failed. `setup` never affects the status.
func handleHealth(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		ctx, cancel := context.WithTimeout(c.Request().Context(), 5*time.Second)
		defer cancel()

		dbProbe := func(ctx context.Context) bool {
			if d.DBPing == nil {
				return false
			}
			return d.DBPing(ctx) == nil
		}
		cacheProbe := func(ctx context.Context) bool {
			if d.CachePing == nil {
				return false
			}
			return d.CachePing(ctx)
		}

		result := health.Check(ctx, dbProbe, cacheProbe)
		payload := map[string]any{
			"status": result.Status,
			"checks": result.Checks,
			"setup":  setup.Resolve(d.SetupSDE, d.SetupPrices, d.SetupOrders),
		}

		status := http.StatusOK
		if result.Status != health.OK {
			status = http.StatusServiceUnavailable
		}
		return Success(c, status, payload, nil)
	}
}

// PingDB adapts *sql.DB (or sqlx.DB via .DB) to Deps.DBPing.
func PingDB(db *sql.DB) func(ctx context.Context) error {
	return func(ctx context.Context) error {
		ctx, cancel := context.WithTimeout(ctx, 2*time.Second)
		defer cancel()
		return db.PingContext(ctx)
	}
}

func jsonErrorHandler(err error, c echo.Context) {
	if c.Response().Committed {
		return
	}
	code := http.StatusInternalServerError
	if he, ok := err.(*echo.HTTPError); ok {
		code = he.Code
	}
	// Echo uses 404 for unknown routes and 405 for wrong method; keep both
	// inside the {message, code} envelope.
	msg := http.StatusText(code)
	if msg == "" {
		msg = "Error."
	}
	_ = Failure(c, code, msg+".", nil)
}
