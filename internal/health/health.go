package health

import "context"

// Prober is one readiness check. It mirrors HealthService::run() in
// app/Services/HealthService.php: true means ok, false or an error means
// failed, and a probe must never panic the health endpoint.
type Prober func(ctx context.Context) bool

// Result mirrors the HealthService::check() return shape.
type Result struct {
	Status string            `json:"status"`
	Checks map[string]string `json:"checks"`
}

const (
	OK     = "ok"
	Failed = "failed"
)

// Check runs database and cache probes and reports ok unless any failed.
func Check(ctx context.Context, database, cache Prober) Result {
	checks := map[string]string{
		"database": run(ctx, database),
		"cache":    run(ctx, cache),
	}
	status := OK
	for _, v := range checks {
		if v == Failed {
			status = Failed
			break
		}
	}
	return Result{Status: status, Checks: checks}
}

func run(ctx context.Context, p Prober) string {
	if p == nil {
		return Failed
	}
	ok := false
	func() {
		defer func() {
			// A panicking probe counts as failed, like a thrown exception in PHP.
			_ = recover()
		}()
		ok = p(ctx)
	}()
	if ok {
		return OK
	}
	return Failed
}
