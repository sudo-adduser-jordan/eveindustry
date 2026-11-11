package main

import (
	"context"
	"encoding/json"
	"fmt"
	"os"
	"os/signal"
	"sort"
	"strings"
	"syscall"

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
	if err := run(os.Args[1:]); err != nil {
		fmt.Fprintln(os.Stderr, "job: "+err.Error())
		os.Exit(1)
	}
}

func parseJobArgs(args []string) (string, json.RawMessage, error) {
	if len(args) == 0 || strings.TrimSpace(args[0]) == "" {
		return "", nil, fmt.Errorf("usage: job <name> [json-payload]")
	}
	var payload json.RawMessage
	if len(args) > 1 {
		if !json.Valid([]byte(args[1])) {
			return "", nil, fmt.Errorf("payload is not valid JSON: %q", args[1])
		}
		payload = json.RawMessage(args[1])
	}
	return args[0], payload, nil
}

func checkJobGate(name string) error {
	if name == "demo-reset" && os.Getenv("DEMO_ENABLED") != "true" {
		return fmt.Errorf("demo-reset needs DEMO_ENABLED=true")
	}
	return nil
}

func run(args []string) error {
	name, payload, err := parseJobArgs(args)
	if err != nil {
		return err
	}
	if err := checkJobGate(name); err != nil {
		return err
	}
	cfg := config.Load()
	db, err := store.Connect(cfg.DBPath)
	if err != nil {
		return fmt.Errorf("database not reachable (DB_PATH=%q): %w", cfg.DBPath, err)
	}
	defer func() { _ = db.DB.Close() }()

	log := logx.New("job")
	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGINT, syscall.SIGTERM)
	defer stop()

	hd := jobs.HandlerDeps{Log: log, Cache: &cache.Cache{DB: db.DB}}
	hd.DB = store.NewSQL(db.DB)
	hd.Industry = industry.NewSQL(db.DB)
	coord := esi.NewMemStore()
	limiter := esi.NewLimiter(coord, "eve-industrialist")
	circuit := esi.NewCircuit(coord)
	esiClient := esi.NewClient(coord, limiter, circuit, cfg.ESIUserAgent)
	esiClient.Cache = esi.NewHTTPCache(coord)
	if os.Getenv("ESI_DIAGNOSTICS_ENABLED") == "true" || os.Getenv("ESI_DIAGNOSTICS_ENABLED") == "1" {
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

	r := jobs.NewRunner(jobs.Deps{Log: log})
	jobs.RegisterRefreshJobs(r, hd)
	jobs.RegisterSweepJobs(r, hd)
	jobs.RegisterImportJobs(r, hd)
	jobs.RegisterPrecomputeJobs(r, hd)
	jobs.RegisterMaintenanceJobs(r, hd)
	jobs.RegisterNotifyJobs(r, hd)
	diagDeps := jobs.DiagFlusherDeps{HandlerDeps: hd, Stream: &esi.SQLStream{DB: db.DB}}
	jobs.RegisterDiagJobs(r, diagDeps)

	def, ok := r.Jobs[name]
	if !ok {
		return fmt.Errorf("unknown job %q (registered: %s)", name, jobNames(r))
	}
	log.Info("job started", "job", name)
	if err := r.RunOne(ctx, jobs.Envelope{Name: name, Payload: payload, MaxTries: def.MaxTries}); err != nil {
		return fmt.Errorf("%s failed: %w", name, err)
	}
	log.Info("job done", "job", name)
	return nil
}

func jobNames(r *jobs.Runner) string {
	names := make([]string, 0, len(r.Jobs))
	for name := range r.Jobs {
		names = append(names, name)
	}
	sort.Strings(names)
	return strings.Join(names, ", ")
}
