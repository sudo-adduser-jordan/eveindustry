package main

import (
	"context"
	"log/slog"
	"os"
	"os/signal"
	"syscall"
	"time"

	_ "github.com/go-sql-driver/mysql"
	"github.com/jmoiron/sqlx"
	"github.com/redis/go-redis/v9"

	"github.com/sudo-adduser-jordan/eveindustry/internal/cache"
	"github.com/sudo-adduser-jordan/eveindustry/internal/config"
	"github.com/sudo-adduser-jordan/eveindustry/internal/esi"
	"github.com/sudo-adduser-jordan/eveindustry/internal/industry"
	"github.com/sudo-adduser-jordan/eveindustry/internal/jobs"
	"github.com/sudo-adduser-jordan/eveindustry/internal/store"
)

// Command scheduler ticks every minute and dispatches due entries, mirroring
// routes/console.php (single scheduler per environment; the Redis lock
// implements withoutOverlapping + onOneServer).
func main() {
	cfg := config.Load()
	log := slog.New(slog.NewJSONHandler(os.Stdout, nil))
	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGINT, syscall.SIGTERM)
	defer stop()

	var rdb *redis.Client
	if host := os.Getenv("REDIS_HOST"); host != "" {
		rdb = redis.NewClient(&redis.Options{Addr: cfg.RedisAddr, Password: cfg.RedisPassword, DB: cfg.RedisDB})
		defer func() { _ = rdb.Close() }()
	}
	var db *sqlx.DB
	if cfg.DBDSN != "" {
		var err error
		db, err = sqlx.Connect("mysql", cfg.DBDSN)
		if err != nil {
			log.Error("scheduler without database", "err", err)
			db = nil
		}
	}
	hd := jobs.HandlerDeps{Log: log, Redis: rdb, Cache: &cache.Cache{Client: rdb}}
	if db != nil {
		hd.DB = store.NewSQL(db)
		hd.Industry = industry.NewSQL(db)
		coord := esi.NewMemStore()
		limiter := esi.NewLimiter(coord, "eve-industrialist")
		circuit := esi.NewCircuit(coord)
		esiClient := esi.NewClient(coord, limiter, circuit, cfg.ESIUserAgent)
		esiClient.Cache = esi.NewHTTPCache(coord)
		hd.ESIChars = esi.NewCharacterClient(esiClient, nil, hd.DB, nil, nil)
		hd.ESIUniverse = esi.NewUniverseClient(esiClient, hd.DB, nil, hd.DB)
		hd.ESIMarket = esi.NewMarketClient(esiClient, nil)
	}
	var q jobs.Queue = jobs.NewMemoryQueue()
	if rdb != nil {
		q = &jobs.RedisQueue{Client: rdb}
	}
	r := jobs.NewRunner(jobs.Deps{Log: log, Redis: rdb})
	jobs.RegisterRefreshJobs(r, hd)
	sched := &jobs.Scheduler{
		Runner: r, Queue: q, Redis: rdb, Now: time.Now,
		Log: func(msg string, args ...any) { log.Warn(msg, args...) },
	}
	for _, e := range jobs.RefreshSchedule(hd) {
		sched.Entries = append(sched.Entries, e)
	}
	for _, e := range jobs.SweepSchedule(hd, 10000002) {
		sched.Entries = append(sched.Entries, e)
	}
	for _, e := range jobs.ImportSchedule(hd, 10000002) {
		sched.Entries = append(sched.Entries, e)
	}
	for _, e := range jobs.PrecomputeSchedule(hd) {
		sched.Entries = append(sched.Entries, e)
	}

	log.Info("scheduler started", "entries", len(sched.Entries))
	last := map[string]time.Time{}
	tick := time.NewTicker(time.Minute)
	defer tick.Stop()
	sched.Tick(ctx, map[string]time.Time{})
	for {
		select {
		case <-ctx.Done():
			return
		case t := <-tick.C:
			_ = t
			last = sched.Tick(ctx, last)
		}
	}
}
