package health

import "context"

type Prober func(ctx context.Context) bool

type Result struct {
	Status string            `json:"status"`
	Checks map[string]string `json:"checks"`
}

const (
	OK     = "ok"
	Failed = "failed"
)

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
			_ = recover()
		}()
		ok = p(ctx)
	}()
	if ok {
		return OK
	}
	return Failed
}
