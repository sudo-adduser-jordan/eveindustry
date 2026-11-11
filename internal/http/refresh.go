package http

import (
	"net/http"
	"sync"
	"time"

	"github.com/labstack/echo/v4"
)

func registerIndustryRefresh(v1 *echo.Group, d Deps) {
	if d.RefreshThrottle == nil {
		d.RefreshThrottle = NewRefreshThrottle()
	}
	v1.POST("/industry/availability/clear", handleIndustryClear(d))
	v1.POST("/industry/availability/refresh", handleIndustryRefreshAvailability(d))
	v1.POST("/industry/can-produce/refresh", handleIndustryRefreshCanProduce(d))
}

func handleIndustryClear(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		uid := c.Get(string(ctxUIDKey)).(int64)
		if msg, ok := d.RefreshThrottle.Clear(uid); !ok {
			return Failure(c, http.StatusTooManyRequests, msg, nil)
		}
		return c.NoContent(http.StatusNoContent)
	}
}

func handleIndustryRefreshAvailability(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		return c.NoContent(http.StatusNoContent)
	}
}

func handleIndustryRefreshCanProduce(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		return c.NoContent(http.StatusNoContent)
	}
}

type RefreshThrottle struct {
	mu   sync.Mutex
	last map[int64]time.Time
	now  func() time.Time
}

func NewRefreshThrottle() *RefreshThrottle {
	return &RefreshThrottle{last: map[int64]time.Time{}, now: time.Now}
}

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
