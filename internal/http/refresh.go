package http

import (
	"net/http"
	"sync"
	"time"

	"github.com/labstack/echo/v4"
)

// registerIndustryRefresh mounts the three industry cache refresh stubs.
// The PHP originals flush per-user ESI caches and recompute synchronously;
// the Go port answers 204 immediately (the data jobs re-warm in background)
// and keeps the 30-minute throttle contract on clear via 429.
func registerIndustryRefresh(v1 *echo.Group, d Deps) {
	if d.RefreshThrottle == nil {
		d.RefreshThrottle = NewRefreshThrottle()
	}
	v1.POST("/industry/availability/clear", handleIndustryClear(d))
	v1.POST("/industry/availability/refresh", handleIndustryRefreshAvailability(d))
	v1.POST("/industry/can-produce/refresh", handleIndustryRefreshCanProduce(d))
}

// POST /industry/availability/clear — 204, or 429 within 30 min of last clear.
func handleIndustryClear(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		uid := c.Get(string(ctxUIDKey)).(int64)
		if msg, ok := d.RefreshThrottle.Clear(uid); !ok {
			return Failure(c, http.StatusTooManyRequests, msg, nil)
		}
		return c.NoContent(http.StatusNoContent)
	}
}

// POST /industry/availability/refresh — 204.
func handleIndustryRefreshAvailability(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		return c.NoContent(http.StatusNoContent)
	}
}

// POST /industry/can-produce/refresh — 204.
func handleIndustryRefreshCanProduce(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		return c.NoContent(http.StatusNoContent)
	}
}

// RefreshThrottle mirrors IndustryRefreshService::clearAllCaches throttle:
// one clear per user per 30 minutes.
type RefreshThrottle struct {
	mu   sync.Mutex
	last map[int64]time.Time
	now  func() time.Time
}

// NewRefreshThrottle builds an empty throttle on wall-clock time.
func NewRefreshThrottle() *RefreshThrottle {
	return &RefreshThrottle{last: map[int64]time.Time{}, now: time.Now}
}

// Clear records a clear for uid. ok=false carries the 429 message.
func (t *RefreshThrottle) Clear(uid int64) (string, bool) {
	t.mu.Lock()
	defer t.mu.Unlock()
	if t.last == nil {
		t.last = map[int64]time.Time{}
	}
	now := time.Now()
	if t.now != nil {
		now = t.now()
	}
	if prev, ok := t.last[uid]; ok && now.Sub(prev) < 30*time.Minute {
		return "Industry caches were cleared recently. Try again later.", false
	}
	t.last[uid] = now
	return "", true
}
