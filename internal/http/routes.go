package http

import (
	"context"
	"database/sql"
	"net/http"
	"time"

	"github.com/labstack/echo/v4"
	"github.com/labstack/echo/v4/middleware"

	"github.com/sudo-adduser-jordan/eveindustry/internal/auth"
	"github.com/sudo-adduser-jordan/eveindustry/internal/cache"
	"github.com/sudo-adduser-jordan/eveindustry/internal/health"
	"github.com/sudo-adduser-jordan/eveindustry/internal/industry"
	"github.com/sudo-adduser-jordan/eveindustry/internal/setup"
	"github.com/sudo-adduser-jordan/eveindustry/internal/store"
)

type Deps struct {
	DBPing    func(ctx context.Context) error
	CachePing func(ctx context.Context) bool

	SetupSDE    setup.Probe
	SetupPrices setup.Probe
	SetupOrders setup.Probe

	Reference          store.ReferenceStore
	Market             store.MarketStore
	Industry           industry.IndustryStore
	Trading            store.TradingStore
	Contracts          store.ContractsStore
	Corp               store.CorpStore
	Skills             SkillsDeps
	Stock              StockDeps
	Production         ProductionDeps
	Research           ResearchDeps
	StockReport        StockReportDeps
	Planning           store.PlanningStore
	Plans              store.PlanStore
	CorpPrograms       store.CorpProgramStore
	StockItems         store.StockStore
	Programs           store.ProgramStore
	IndustryDeps       IndustryDeps
	Progress           ProgressDeps
	MoonScans          store.MoonScanStore
	AssetBrowser       AssetBrowserDeps
	Donations          store.DonationStore
	DonationCharacters DonationESI
	Windows            store.WindowStore
	Projects           ProjectESI
	AdminUsers         store.AdminUserStore
	Sponsors           store.SponsorStore
	AdminStats         store.AdminStatsStore
	SponsorImages      SponsorStorage
	Diag               store.DiagStore
	Cache              *cache.Cache
	Abyssal            AbyssalDeps
	Planetary          PlanetaryDeps

	SSOConfigured bool
	DemoEnabled   bool
	DemoUsers     DemoUsers

	Sessions auth.Sessions
	Users    store.AuthStore

	App AppConfig

	RefreshThrottle *RefreshThrottle
}

func New(d Deps) *echo.Echo {
	e := echo.New()
	e.HideBanner = true
	e.HTTPErrorHandler = jsonErrorHandler

	e.Use(middleware.Recover())
	e.Use(middleware.Secure())

	e.GET("/up", func(c echo.Context) error {
		return c.String(http.StatusOK, "ok")
	})

	e.GET("/", func(c echo.Context) error {
		return Success(c, http.StatusOK, map[string]any{
			"name":   "eveindustry",
			"api":    "/api/v1",
			"health": "/api/v1/health",
			"app":    "/app/",
		}, nil)
	})

	v1 := e.Group("/api/v1")
	v1.GET("/health", handleHealth(d))

	e.GET("/login", handleLogin(d))
	e.GET("/welcome", handleWelcome(d))
	e.POST("/demo/login", handleDemoLogin(d))
	registerDemoImages(e)

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
	registerMostProfitable(authed, d)
	registerProduction(authed, d)
	registerResearch(authed, d)
	registerStockReport(authed, d)
	registerPlanning(authed, d)
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
	registerAssets(authed, d)
	registerAssetExtras(authed, d)
	registerDonations(authed, d)
	registerWindows(authed, d)
	registerProjects(authed, d)
	registerAdmin(authed, d)
	registerSponsors(authed, d)
	registerDiagnostics(authed, d)
	registerCharacters(authed, d)
	registerDashboard(authed, d)
	registerAbyssal(authed, d)
	registerPlanetary(authed, d)

	return e
}

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
	msg := http.StatusText(code)
	if msg == "" {
		msg = "Error."
	}
	_ = Failure(c, code, msg+".", nil)
}
