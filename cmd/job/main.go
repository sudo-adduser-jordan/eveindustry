// Command job runs one registered background job once and exits:
//
//	go run ./cmd/job <name> [json-payload]
//
// Unlike the scheduler tick (which dispatches into queues for the worker),
// this executes the job inline in this process: handy for setup and manual
// recovery (sde:import, demo-reset, market:update-prices).
package main

import (
	"context"
	"encoding/json"
	"fmt"
	"log/slog"
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
	"github.com/sudo-adduser-jordan/eveindustry/internal/notify"
	"github.com/sudo-adduser-jordan/eveindustry/internal/store"
)

func main() {
	if err := run(os.Args[1:]); err != nil {
		fmt.Fprintln(os.Stderr, "job: "+err.Error())
		os.Exit(1)
	}
}

// parseJobArgs splits <name> [json-payload], rejecting blank names and
// malformed payloads before anything is wired.
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

// checkJobGate mirrors the scheduler's When guards for jobs that are unsafe
// to run unconditionally (wiping demo data on a non-demo install).
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

	log := slog.New(slog.NewJSONHandler(os.Stdout, nil))
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

// jobNames lists registered jobs for the unknown-job error.
func jobNames(r *jobs.Runner) string {
	names := make([]string, 0, len(r.Jobs))
	for name := range r.Jobs {
		names = append(names, name)
	}
	sort.Strings(names)
	return strings.Join(names, ", ")
}
