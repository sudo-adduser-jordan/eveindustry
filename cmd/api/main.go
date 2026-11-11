package main

import (
	"context"
	"errors"
	"log/slog"
	"net/http"
	"os"
	"os/signal"
	"syscall"
	"time"

	"github.com/jmoiron/sqlx"

	"github.com/sudo-adduser-jordan/eveindustry/internal/auth"
	"github.com/sudo-adduser-jordan/eveindustry/internal/cache"
	"github.com/sudo-adduser-jordan/eveindustry/internal/config"
	"github.com/sudo-adduser-jordan/eveindustry/internal/esi"
	gohttp "github.com/sudo-adduser-jordan/eveindustry/internal/http"
	"github.com/sudo-adduser-jordan/eveindustry/internal/industry"
	"github.com/sudo-adduser-jordan/eveindustry/internal/jobs"
	"github.com/sudo-adduser-jordan/eveindustry/internal/notify"
	"github.com/sudo-adduser-jordan/eveindustry/internal/store"
)

// API server: GET /up, GET /api/v1/health (DB + cache probes with 503 on
// failure, mirroring HealthController) and the /api/v1 JSON surface.
// Storage is an embedded SQLite file (DB_PATH); a missing database does not
// crash the process, health just reports failed.
func main() {
	cfg := config.Load()
	log := slog.New(slog.NewJSONHandler(os.Stdout, nil))

	var sqlxDB *sqlx.DB
	if db, err := store.Connect(cfg.DBPath); err != nil {
		log.Warn("database not reachable at startup; /health will report failed", "error", err)
	} else {
		sqlxDB = db.DB
		defer func() { _ = db.DB.Close() }()
	}

	deps := gohttp.Deps{
		Cache: &cache.Cache{DB: sqlxDB},
		App: gohttp.AppConfig{
			SourceURL: cfg.SourceURL, DiscordURL: cfg.DiscordURL, DiscordBot: cfg.DiscordBot,
		},
	}
	key, ephemeral := auth.SessionKeyFromEnv(cfg.SessionKey, os.Getenv("APP_KEY"))
	if ephemeral {
		log.Warn("no SESSION_KEY or APP_KEY: using an ephemeral session key; logins die on restart")
	}
	deps.Sessions = auth.NewCookieSessions(key, 0, cfg.SessionSecure, cfg.SessionDomain)
	deps.DemoEnabled = os.Getenv("DEMO_ENABLED") == "true"
	deps.SSOConfigured = cfg.ESIClientID != "" && cfg.ESIClientSecret != ""
	if sqlxDB != nil {
		deps.DBPing = gohttp.PingDB(sqlxDB.DB)
		queries := store.NewSQL(sqlxDB)
		deps.Reference = queries
		deps.Market = queries
		deps.Trading = queries
		deps.Contracts = queries
		deps.Corp = queries
		deps.Users = queries
		deps.Industry = industry.NewSQL(sqlxDB)

		// ESI stack: shared coordination, token refresher, character client.
		// Encrypted-at-rest tokens decrypt when APP_KEY decodes to 32 bytes.
		coord := esi.NewMemStore()
		limiter := esi.NewLimiter(coord, "eve-industrialist")
		circuit := esi.NewCircuit(coord)
		esiClient := esi.NewClient(coord, limiter, circuit, cfg.ESIUserAgent)
		esiClient.Cache = esi.NewHTTPCache(coord)

		diagEnabled := os.Getenv("ESI_DIAGNOSTICS_ENABLED") == "true" || os.Getenv("ESI_DIAGNOSTICS_ENABLED") == "1"
		if diagEnabled && sqlxDB != nil {
			stream := &esi.SQLStream{DB: sqlxDB}
			rec := &esi.DiagRecorder{Enabled: true, Sink: &esi.StreamSink{Stream: stream}}
			esiClient.Recorder = rec
			esiClient.Cache.Recorder = rec
		}
		esiClient.CompatibilityDate = cfg.ESICompatibilityDate
		ssoHTTP := &http.Client{Timeout: 30 * time.Second}
		refresher := esi.NewRefresher(coord, queries, ssoHTTP, cfg.ESIClientID, cfg.ESIClientSecret)
		var appKey []byte
		if len(key) >= 32 && !ephemeral {
			appKey = key[:32]
		}
		esiChars := esi.NewCharacterClient(esiClient, refresher, queries, nil, appKey)
		esiUniverse := esi.NewUniverseClient(esiClient, queries, nil, queries)
		demo := jobs.DemoResponder(queries, deps.Industry)
		esiChars.Demo = demo
		esiUniverse.Demo = demo
		deps.Skills.Characters = esiChars
		deps.Stock.Characters = esiChars
		deps.Production.Characters = esiChars
		deps.StockReport.Characters = esiChars
		deps.StockReport.Stock = queries
		deps.Research.Characters = esiChars
		deps.Research.Research = queries
		deps.IndustryDeps.Characters = esiChars
		deps.IndustryDeps.Universe = esiUniverse
		deps.Planning = queries
		deps.Progress.Characters = esiChars
		deps.Progress.Universe = esiUniverse
		deps.Progress.Reference = queries
		deps.WholemapReads.Characters = esiChars
		deps.WholemapReads.Reference = queries
		deps.AssetBrowser.Characters = esiChars
		deps.AssetBrowser.Universe = esiUniverse
		deps.AssetBrowser.Reference = queries
		deps.Donations = queries
		deps.DonationCharacters = esiChars
		deps.Ess = queries
		deps.Windows = queries
		deps.Projects = esiChars
		deps.AdminUsers = queries
		deps.Sponsors = queries
		deps.AdminStats = queries
		deps.Diag = queries
		deps.Discord = queries
		deps.Notify = queries
		deps.Abyssal.Characters = esiChars
		deps.Abyssal.Reference = queries
		deps.Abyssal.Universe = esiUniverse
		deps.Abyssal.Dogma = esiChars
		deps.NotifyPush = gohttp.NotifyPush{VapidPublicKey: os.Getenv("VAPID_PUBLIC_KEY")}
		deps.NotifySender = &notify.WebPushSender{
			Subject: os.Getenv("VAPID_SUBJECT"), PublicKey: os.Getenv("VAPID_PUBLIC_KEY"),
			PrivateKey: os.Getenv("VAPID_PRIVATE_KEY"),
		}
		deps.DiscordBotEnabled = os.Getenv("FEATURE_DISCORD_BOT") == "true"
		deps.Planetary.Characters = esiChars
		deps.Planetary.Universe = esiUniverse
		deps.SponsorImages = gohttp.DiskSponsorStorage{Dir: sponsorDir()}
		deps.CorpPrograms = queries
		deps.StockItems = queries
		deps.DemoUsers = queries
		deps.Plans = queries
		deps.MoonScans = queries
		deps.Wholemap = queries
		deps.Programs = queries
	}
	if sqlxDB != nil {
		deps.CachePing = func(ctx context.Context) bool {
			ctx, cancel := context.WithTimeout(ctx, 2*time.Second)
			defer cancel()
			var one int
			return sqlxDB.GetContext(ctx, &one, `SELECT 1`) == nil && one == 1
		}
	}

	e := gohttp.New(deps)
	e.Logger.SetOutput(os.Stdout)

	addr := ":" + cfg.Port
	srv := &http.Server{Addr: addr, Handler: e}

	go func() {
		log.Info("go api listening", "addr", addr, "env", cfg.AppEnv)
		if err := srv.ListenAndServe(); err != nil && !errors.Is(err, http.ErrServerClosed) {
			log.Error("server exited", "error", err)
			os.Exit(1)
		}
	}()

	quit := make(chan os.Signal, 1)
	signal.Notify(quit, syscall.SIGINT, syscall.SIGTERM)
	<-quit

	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()
	if err := srv.Shutdown(ctx); err != nil {
		log.Error("graceful shutdown failed", "error", err)
	}
}

func sponsorDir() string {
	if v := os.Getenv("SPONSOR_DIR"); v != "" {
		return v
	}
	return "storage/sponsors"
}
