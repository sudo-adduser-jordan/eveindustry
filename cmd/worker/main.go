// Command worker runs one background job by name.
//
// Phase 3 will back this with a durable queue (riverqueue/asynq) and three
// lanes mirroring Horizon (default/market/updates). Until then each job is
// registered under its PHP class name with its queue lane, and execution
// reports "not ported" so nothing silently pretends to work.
package main

import (
	"fmt"
	"os"
)

// Job mirrors one class in app/Jobs plus its queue lane from AGENTS.md:
// per-user refreshes on default, sweeps on market, imports on updates.
type Job struct {
	Name  string
	Queue string
}

var jobs = []Job{
	{"RefreshCharacterProfile", "default"},
	{"RefreshDashboardStats", "default"},
	{"RefreshIndustryData", "default"},
	{"RefreshAssetData", "default"},
	{"DispatchUserNotifications", "default"},
	{"FlushEsiDiagnostics", "default"},
	{"FetchRegionOrders", "market"},
	{"FetchRegionOrderPages", "market"},
	{"CompleteMarketOrderSweep", "market"},
	{"ProcessEveTypeChunk", "updates"},
	{"ProcessBlueprintDataChunk", "updates"},
	{"ImportDailyMarketHistory", "updates"},
	{"ImportPublicContracts", "updates"},
	{"PrecomputeManufacturingCostsChunk", "updates"},
	{"ManufacturingCostWriter", "updates"},
	{"FetchRegionHistory", "updates"},
	{"FetchRegionHistoryChunk", "updates"},
}

func main() {
	if len(os.Args) != 3 || os.Args[1] != "run" {
		fmt.Fprintln(os.Stderr, "usage: worker run <JobName>")
		fmt.Fprintln(os.Stderr, "registered jobs:")
		for _, j := range jobs {
			fmt.Fprintf(os.Stderr, "  %-32s %s\n", j.Name, j.Queue)
		}
		os.Exit(2)
	}
	fmt.Fprintf(os.Stderr, "worker: job %s is not ported to Go yet\n", os.Args[2])
	os.Exit(1)
}
