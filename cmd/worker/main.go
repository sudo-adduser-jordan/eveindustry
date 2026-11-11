package main

import (
	"context"
	"log/slog"
	"os"
	"os/signal"
	"syscall"
	"time"

	"github.com/sudo-adduser-jordan/eveindustry/internal/cache"
	"github.com/sudo-adduser-jordan/eveindustry/internal/config"
	"github.com/sudo-adduser-jordan/eveindustry/internal/esi"
	"github.com/sudo-adduser-jordan/eveindustry/internal/industry"
	"github.com/sudo-adduser-jordan/eveindustry/internal/jobs"
	"github.com/sudo-adduser-jordan/eveindustry/internal/notify"
	"github.com/sudo-adduser-jordan/eveindustry/internal/store"
)

// Command worker consumes queues and runs jobs. APP_ROLE selects lanes:
// "worker" runs default, "worker-market" runs market, "worker-updates"
// runs updates, "worker-all" runs everything (dev default).
func main() {
	cfg := config.Load()
	log := slog.New(slog.NewJSONHandler(os.Stdout, nil))
	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGINT, syscall.SIGTERM)
	defer stop()

	role := os.Getenv("APP_ROLE")
	queues := []string{jobs.QueueDefault}
	switch role {
	case "worker-market":
		queues = []string{jobs.QueueMarket}
	case "worker-updates":
		queues = []string{jobs.QueueUpdates}
	case "worker-all", "worker", "":
		if role == "" || role == "worker" {
			queues = []string{jobs.QueueDefault}
		} else {
			queues = []string{jobs.QueueDefault, jobs.QueueUpdates, jobs.QueueMarket}
		}
	}

	var db *store.DB
	if conn, err := store.Connect(cfg.DBPath); err != nil {
		log.Error("worker without database", "err", err)
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
		if diagEnabled && db != nil {
			stream := &esi.SQLStream{DB: db.DB}
			rec := &esi.DiagRecorder{Enabled: true, Sink: &esi.StreamSink{Stream: stream}}
			esiClient.Recorder = rec
			esiClient.Cache.Recorder = rec
		}
		hd.ESIChars = esi.NewCharacterClient(esiClient, nil, hd.DB, nil, nil)
		hd.ESIUniverse = esi.NewUniverseClient(esiClient, hd.DB, nil, hd.DB)
		hd.ESIMarket = esi.NewMarketClient(esiClient, nil)
		// Demo stack mirrors cmd/api: refresh jobs rebuild demo users' caches
		// through the responder, never live ESI.
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
	diagDeps := jobs.DiagFlusherDeps{HandlerDeps: hd}
	if db != nil {
		diagDeps.Stream = &esi.SQLStream{DB: db.DB}
	}
	jobs.RegisterDiagJobs(r, diagDeps)
	jobs.RegisterNotifyJobs(r, hd)
	jobs.RegisterImportJobs(r, hd)
	for _, e := range jobs.RefreshSchedule(hd) {
		_ = e
	}

	log.Info("worker started", "queues", queues)
	for {
		select {
		case <-ctx.Done():
			return
		default:
		}
		queue, env, ok, err := q.Pop(ctx, queues, 5*time.Second)
		if err != nil {
			log.Error("queue pop failed", "err", err)
			time.Sleep(5 * time.Second)
			continue
		}
		if !ok {
			time.Sleep(time.Second)
			continue
		}
		if err := r.RunOne(ctx, env); err != nil {
			if next, retry := r.ShouldRetry(env); retry {
				if perr := q.Push(ctx, queue, next); perr != nil {
					log.Error("requeue failed", "job", env.Name, "err", perr)
				}
				continue
			}
			log.Error("job dropped after retries", "job", env.Name, "attempts", env.Attempts+1)
		}
	}
}
