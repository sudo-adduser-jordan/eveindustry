package main

import (
	"context"
	"os"
	"os/signal"
	"syscall"
	"time"

	"github.com/sudo-adduser-jordan/eveindustry/internal/cache"
	"github.com/sudo-adduser-jordan/eveindustry/internal/config"
	"github.com/sudo-adduser-jordan/eveindustry/internal/esi"
	"github.com/sudo-adduser-jordan/eveindustry/internal/industry"
	"github.com/sudo-adduser-jordan/eveindustry/internal/jobs"
	"github.com/sudo-adduser-jordan/eveindustry/internal/logx"
	"github.com/sudo-adduser-jordan/eveindustry/internal/notify"
	"github.com/sudo-adduser-jordan/eveindustry/internal/store"
)

func main() {
	cfg := config.Load()
	log := logx.New("scheduler")
	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGINT, syscall.SIGTERM)
	defer stop()

	var db *store.DB
	if conn, err := store.Connect(cfg.DBPath); err != nil {
		log.Error("scheduler without database", "err", err)
	} else {
		db = conn
		defer func() { _ = conn.DB.Close() }()
	}
	var q jobs.Queue = jobs.NewMemoryQueue()
	var locks jobs.Locker
	hd := jobs.HandlerDeps{Log: log, Cache: &cache.Cache{}}
	if db != nil {
		q = &jobs.SQLQueue{DB: db.DB}
		locks = &jobs.DBLocker{DB: db.DB}
		hd.Cache = &cache.Cache{DB: db.DB}
		hd.DB = store.NewSQL(db.DB)
		hd.Industry = industry.NewSQL(db.DB)
		coord := esi.NewMemStore()
		limiter := esi.NewLimiter(coord, "eve-industrialist")
		circuit := esi.NewCircuit(coord)
		esiClient := esi.NewClient(coord, limiter, circuit, cfg.ESIUserAgent)
		esiClient.Cache = esi.NewHTTPCache(coord)

		diagEnabled := os.Getenv("ESI_DIAGNOSTICS_ENABLED") == "true" || os.Getenv("ESI_DIAGNOSTICS_ENABLED") == "1"
		if diagEnabled {
			stream := &esi.SQLStream{DB: db.DB}
			rec := &esi.DiagRecorder{Enabled: true, Sink: &esi.StreamSink{Stream: stream}}
			esiClient.Recorder = rec
			esiClient.Cache.Recorder = rec
		}
		hd.ESIChars = esi.NewCharacterClient(esiClient, nil, hd.DB, nil, nil)
		hd.ESIUniverse = esi.NewUniverseClient(esiClient, hd.DB, nil, hd.DB)
		hd.ESIMarket = esi.NewMarketClient(esiClient, nil)
		jobs.WireDemo(&hd)
		hd.Sender = &notify.WebPushSender{
			Subject: os.Getenv("VAPID_SUBJECT"), PublicKey: os.Getenv("VAPID_PUBLIC_KEY"),
			PrivateKey: os.Getenv("VAPID_PRIVATE_KEY"),
		}
	}
	r := jobs.NewRunner(jobs.Deps{Log: log, Locks: locks})
	jobs.RegisterRefreshJobs(r, hd)
	jobs.RegisterSweepJobs(r, hd)
	jobs.RegisterImportJobs(r, hd)
	jobs.RegisterPrecomputeJobs(r, hd)
	jobs.RegisterMaintenanceJobs(r, hd)
	jobs.RegisterNotifyJobs(r, hd)
	diagDeps := jobs.DiagFlusherDeps{HandlerDeps: hd}
	if db != nil {
		diagDeps.Stream = &esi.SQLStream{DB: db.DB}
	}
	jobs.RegisterDiagJobs(r, diagDeps)
	sched := &jobs.Scheduler{
		Runner: r, Queue: q, Locks: locks, Now: time.Now,
		Log: func(msg string, args ...any) { log.Warn(msg, args...) },
	}
	if hd.DB == nil {
		log.Warn("no database configured; scheduler idle (entries need SQLite)")
	} else {
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
		for _, e := range jobs.MaintenanceSchedule(hd, os.Getenv("DEMO_ENABLED") == "true", os.Getenv("DEMO_RESET_TIME")) {
			sched.Entries = append(sched.Entries, e)
		}
		for _, e := range jobs.DiagSchedule(diagDeps) {
			sched.Entries = append(sched.Entries, e)
		}
		for _, e := range jobs.NotifySchedule(hd) {
			sched.Entries = append(sched.Entries, e)
		}
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
