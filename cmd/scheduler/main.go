// Command scheduler prints the tick table for the Go scheduler.
//
// Phase 3 replaces cron schedule:run with a gocron/robfig-cron process
// running this table (cadences mirror routes/console.php; times are UTC).
// `scheduler list` is the machine-readable form the supervisor will tick.
package main

import (
	"fmt"
	"os"
)

// Entry is one recurring task: the artisan command it replaces, its cadence
// and the queue lane its jobs fan out to.
type Entry struct {
	Command string
	Cadence string
	Queue   string
}

var table = []Entry{
	{"sde:import", "daily 02:00", "-"},
	{"market:update-prices", "daily 04:00", "-"},
	{"esi:import-types", "weekly Sun 03:00", "updates"},
	{"sde:import-blueprints", "weekly Sun 05:00", "updates"},
	{"characters:refresh-data", "every 30m", "default"},
	{"market:fetch-region-orders --region=10000002", "every 15m (gated)", "market"},
	{"market:fetch-region-orders --exclude=10000002", "hourly", "market"},
	{"market:import-daily-history", "every 3h", "updates"},
	{"contracts:import", "hourly", "updates"},
	{"dashboard:refresh", "every 15m", "default"},
	{"industry:refresh", "every 30m", "default"},
	{"assets:refresh", "every 30m", "default"},
	{"esi:import-market-groups", "cron 0 3 1,15 * *", "updates"},
	{"manufacturing:precompute-costs", "every 4h", "updates"},
	{"market:snapshots-prune --write", "daily", "-"},
	{"esi:diagnostics-flush", "every 1m", "default"},
	{"esi:diagnostics-prune", "daily 06:30", "default"},
	{"notifications:dispatch", "every 5m", "default"},
	{"demo:reset", "daily 03:30", "default"},
}

func main() {
	if len(os.Args) == 2 && os.Args[1] == "list" {
		for _, e := range table {
			fmt.Printf("%-45s %-18s %s\n", e.Command, e.Cadence, e.Queue)
		}
		return
	}
	fmt.Fprintln(os.Stderr, "usage: scheduler list   (tick loop lands in Phase 3)")
	os.Exit(2)
}
