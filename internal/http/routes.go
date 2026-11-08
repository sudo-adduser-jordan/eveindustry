package http

import (
	"context"
	"database/sql"
	"net/http"
	"time"

	"github.com/labstack/echo/v4"
	"github.com/labstack/echo/v4/middleware"

	"github.com/sudo-adduser-jordan/eveindustry/internal/auth"
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
	Reference     store.ReferenceStore
	Market        store.MarketStore
	Industry      industry.IndustryStore
	Trading       store.TradingStore
	Contracts     store.ContractsStore
	Corp          store.CorpStore
	Skills        SkillsDeps
	Stock         StockDeps
	Production    ProductionDeps
	Research      ResearchDeps
	StockReport   StockReportDeps
	Planning      store.PlanningStore
	Plans         store.PlanStore
	CorpPrograms  store.CorpProgramStore
	StockItems    store.StockStore
	Wholemap      store.WholemapStore
	Programs      store.ProgramStore
	IndustryDeps  IndustryDeps
	Progress      ProgressDeps
	MoonScans     store.MoonScanStore
	WholemapReads WholemapReadsDeps
	AssetBrowser  AssetBrowserDeps
	Donations         store.DonationStore
	DonationCharacters DonationESI

	// Sessions and Users drive RequireAuth. Either nil means nobody can
	// authenticate, so every authed route 401s (never silently open).
	Sessions auth.Sessions
	Users    store.AuthStore

	// App carries the public metadata /me exposes.
	App AppConfig

	// RefreshThrottle keeps the 30-minute clear throttle for industry
	// caches. Nil means a fresh in-memory throttle (tests override).
	RefreshThrottle *RefreshThrottle
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

	// Everything else needs a session, like the PHP auth:sanctum group.
	// Demo accounts are read-mostly, like the PHP demo-guard group.
	authed := v1.Group("", RequireAuth(d), BlockDemo)
	authed.GET("/me", handleMe(d))
	authed.POST("/logout", handleLogout(d))
	authed.DELETE("/account", handleAccountDelete(d))
	authed.GET("/market/freshness", handleFreshness(d))

	registerReference(authed, d)
	registerMarket(authed, d)
	registerIndustryReference(authed, d)
	registerTrading(authed, d)
	registerCalculators(authed, d)
	registerInvention(authed, d)
	registerContracts(authed, d)
	registerBrowser(authed, d)
	registerFactoryPlanner(authed, d)
	registerCorporateExport(authed, d)
	registerSkills(authed, d)
	registerStock(authed, d)
	registerIndustry(authed, d)
	registerProduction(authed, d)
	registerResearch(authed, d)
	registerStockReport(authed, d)
	registerPlanning(authed, d)
	registerWholemap(authed, d)
	registerPlans(authed, d)
	registerCorpPrograms(authed, d)
	registerCorpProgramPages(authed, d)
	registerStockItems(authed, d)
	registerStructures(authed, d)
	registerIndustryRefresh(authed, d)
	registerPrograms(authed, d)
	registerProgress(authed, d)
	registerAvailability(authed, d)
	registerMoonScans(authed, d)
	registerWholemapReads(authed, d)
	registerAssets(authed, d)
	registerAssetExtras(authed, d)
	registerDonations(authed, d)

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
