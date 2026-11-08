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

	_ "github.com/go-sql-driver/mysql"
	"github.com/jmoiron/sqlx"
	"github.com/redis/go-redis/v9"

	"github.com/sudo-adduser-jordan/eveindustry/internal/auth"
	"github.com/sudo-adduser-jordan/eveindustry/internal/config"
	"github.com/sudo-adduser-jordan/eveindustry/internal/esi"
	gohttp "github.com/sudo-adduser-jordan/eveindustry/internal/http"
	"github.com/sudo-adduser-jordan/eveindustry/internal/industry"
	"github.com/sudo-adduser-jordan/eveindustry/internal/store"
)

// Phase 0 API server: GET /up, GET /api/v1/health (DB + Redis probes with
// 503 on failure, mirroring HealthController) and GET /api/v1/me (401 stub
// until the Sanctum/SSO port in Phase 2). Missing DB/Redis config does not
// crash the process; health just reports failed.
func main() {
	cfg := config.Load()
	log := slog.New(slog.NewJSONHandler(os.Stdout, nil))

	var sqlxDB *sqlx.DB
	if cfg.DBDSN != "" {
		db, err := sqlx.Connect("mysql", cfg.DBDSN)
		if err != nil {
			log.Warn("database not reachable at startup; /health will report failed", "error", err)
		} else {
			sqlxDB = db
			defer func() { _ = db.Close() }()
		}
	}

	var rdb *redis.Client
	// config.RedisAddr is ":6379" when REDIS_HOST is unset; only dial when a host is set.
	if host := os.Getenv("REDIS_HOST"); host != "" {
		rdb = redis.NewClient(&redis.Options{
			Addr:     cfg.RedisAddr,
			Password: cfg.RedisPassword,
			DB:       cfg.RedisDB,
		})
		defer func() { _ = rdb.Close() }()
	}

	deps := gohttp.Deps{
		App: gohttp.AppConfig{
			SourceURL: cfg.SourceURL, DiscordURL: cfg.DiscordURL, DiscordBot: cfg.DiscordBot,
		},
	}
	key, ephemeral := auth.SessionKeyFromEnv(cfg.SessionKey, os.Getenv("APP_KEY"))
	if ephemeral {
		log.Warn("no SESSION_KEY or APP_KEY: using an ephemeral session key; logins die on restart")
	}
	deps.Sessions = auth.NewCookieSessions(key, 0, cfg.SessionSecure, cfg.SessionDomain)
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
		esiClient.CompatibilityDate = cfg.ESICompatibilityDate
		ssoHTTP := &http.Client{Timeout: 30 * time.Second}
		refresher := esi.NewRefresher(coord, queries, ssoHTTP, cfg.ESIClientID, cfg.ESIClientSecret)
		var appKey []byte
		if len(key) >= 32 && !ephemeral {
			appKey = key[:32]
		}
		esiChars := esi.NewCharacterClient(esiClient, refresher, queries, nil, appKey)
		esiUniverse := esi.NewUniverseClient(esiClient, queries, nil, queries)
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
		deps.CorpPrograms = queries
		deps.StockItems = queries
		deps.Plans = queries
		deps.MoonScans = queries
		deps.Wholemap = queries
		deps.Programs = queries
	}
	if rdb != nil {
		deps.CachePing = func(ctx context.Context) bool {
			ctx, cancel := context.WithTimeout(ctx, 2*time.Second)
			defer cancel()
			key := "health:go:ping"
			if err := rdb.Set(ctx, key, "1", 10*time.Second).Err(); err != nil {
				return false
			}
			v, err := rdb.Get(ctx, key).Result()
			_ = rdb.Del(ctx, key)
			return err == nil && v == "1"
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
