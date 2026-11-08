package esi

import (
	"crypto/rand"
	"crypto/sha256"
	"encoding/hex"
	"math"
	"strconv"
	"strings"
	"time"
)

func sha256hex(s string) string {
	sum := sha256.Sum256([]byte(s))
	return hex.EncodeToString(sum[:])
}

// rateEntry is one sliding-window ledger entry.
type rateEntry struct {
	ID      string  `json:"id"`
	At      float64 `json:"at"`
	Cost    int     `json:"cost"`
	Pending bool    `json:"pending"`
}

// remoteCeiling mirrors the PHP remote remaining/until pair.
type remoteCeiling struct {
	Remaining int   `json:"remaining"`
	Until     int64 `json:"until"`
}

// limitConfig mirrors the advertised X-Ratelimit-Limit override.
type limitConfig struct {
	Limit  int `json:"limit"`
	Window int `json:"window"`
}

// Limiter ports EsiRateLimiter over a CoordStore: sliding-window floating
// token budgets per (group, identity), remote-ceiling reconciliation,
// 429 cooldowns and advertised limit overrides.
type Limiter struct {
	store CoordStore
	appID string
}

// NewLimiter builds a limiter. appID scopes identity keys like the PHP
// application_id ('eve-industrialist').
func NewLimiter(store CoordStore, appID string) *Limiter {
	if appID == "" {
		appID = "eve-industrialist"
	}
	return &Limiter{store: store, appID: appID}
}

func (l *Limiter) key(group, identity string) string {
	id := "ip:shared-egress"
	if identity != "" {
		id = "character:" + l.appID + ":" + identity
	}
	sum := sha256hex(group + "|" + id)
	return "esi.rl." + sum
}

func (l *Limiter) config(group string) (window int, limit int) {
	if c, ok := loadJSON[limitConfig](l.store, "esi.rl.config."+group); ok && c.Window > 0 {
		return c.Window, c.Limit
	}
	return DefaultWindowSeconds, DefaultLimit
}

func (l *Limiter) activeEntries(key string, window int) []rateEntry {
	entries, ok := loadJSON[[]rateEntry](l.store, key)
	if !ok {
		return nil
	}
	cutoff := float64(time.Now().UnixNano())/1e9 - float64(window)
	var kept []rateEntry
	for _, e := range entries {
		if e.At > cutoff {
			kept = append(kept, e)
		}
	}
	return kept
}

func (l *Limiter) putEntries(key string, entries []rateEntry, window int) {
	storeJSON(l.store, key, entries, time.Duration(window+60)*time.Second)
}

// Remaining mirrors remaining(): local budget floored by the remote ceiling.
func (l *Limiter) Remaining(group, identity string) int {
	window, limit := l.config(group)
	spent := 0
	for _, e := range l.activeEntries(l.key(group, identity), window) {
		spent += e.Cost
	}
	local := max(0, int64(limit-spent))
	if rc, ok := loadJSON[remoteCeiling](l.store, l.key(group, identity)+".remote"); ok && rc.Until > time.Now().Unix() {
		if int64(rc.Remaining) < local {
			return rc.Remaining
		}
	}
	return int(local)
}

// ResetsIn mirrors resetsInSeconds(): 0 when cost fits, else seconds until it does.
func (l *Limiter) ResetsIn(group, identity string, cost int) int {
	key := l.key(group, identity)
	cooldown := l.cooldownLeft(key)
	if l.Remaining(group, identity) >= cost {
		return cooldown
	}
	window, _ := l.config(group)
	entries := l.activeEntries(key, window)
	first := float64(time.Now().Unix())
	if len(entries) > 0 {
		first = entries[0].At
		for _, e := range entries[1:] {
			if e.At < first {
				first = e.At
			}
		}
	}
	wait := int64(math.Ceil(first + float64(window) - float64(time.Now().UnixNano())/1e9))
	remoteWait := int64(0)
	if rc, ok := loadJSON[remoteCeiling](l.store, key+".remote"); ok && rc.Remaining < cost {
		remoteWait = rc.Until - time.Now().Unix()
	}
	w := max(1, max(int64(cooldown), max(remoteWait, wait)))
	return int(w)
}

// IsLimited mirrors isLimited().
func (l *Limiter) IsLimited(group, identity string) bool {
	return l.ResetsIn(group, identity, 2) > 0
}

func (l *Limiter) cooldownLeft(key string) int {
	raw, ok := l.store.Get(key + ".cooldown")
	if !ok {
		return 0
	}
	deadline, err := strconv.ParseInt(string(raw), 10, 64)
	if err != nil {
		return 0
	}
	left := deadline - time.Now().Unix()
	if left < 0 {
		return 0
	}
	return int(left)
}

// Reserve mirrors reserve(): records a pending cost-5 entry, or "" when the
// budget does not cover cost. tryConsume uses cost 2.
func (l *Limiter) Reserve(group, identity string, cost int) (string, error) {
	key := l.key(group, identity)
	var id string
	err := l.store.Lock("lock."+key, 10*time.Second, 2*time.Second, func() error {
		if l.ResetsIn(group, identity, cost) > 0 {
			return nil
		}
		window, _ := l.config(group)
		entries := l.activeEntries(key, window)
		var b [12]byte
		if _, err := rand.Read(b[:]); err != nil {
			return err
		}
		id = hex.EncodeToString(b[:])
		entries = append(entries, rateEntry{ID: id, At: float64(time.Now().UnixNano()) / 1e9, Cost: cost, Pending: true})
		l.putEntries(key, entries, window)
		if rc, ok := loadJSON[remoteCeiling](l.store, key+".remote"); ok && rc.Until > time.Now().Unix() {
			rc.Remaining = int(max(0, int64(rc.Remaining-cost)))
			storeJSON(l.store, key+".remote", rc, time.Duration(max(1, rc.Until-time.Now().Unix()))*time.Second)
		}
		return nil
	})
	if err != nil {
		if re, ok := err.(*RequestError); ok {
			return "", re
		}
		return "", &RequestError{Outcome: OutcomeCoordination, RetryAfterSecs: 5}
	}
	return id, nil
}

// TryConsume mirrors tryConsume(): a cost-2 probe.
func (l *Limiter) TryConsume(group, identity string) (bool, error) {
	id, err := l.Reserve(group, identity, 2)
	return id != "", err
}

// ResponseHeaders carries the rate-limit headers of a finished response.
type ResponseHeaders struct {
	Status         int
	Used           *int
	Remaining      *int
	Advertised     string // raw X-Ratelimit-Limit, e.g. "600/900s", "100/1m"
	RetryAfterSecs *int
}

// Reconcile mirrors reconcile(): replaces the pending cost with the actual
// one (status-derived, or X-Ratelimit-Used), lowers the remote ceiling
// monotonically, learns advertised limits and records 429 cooldowns.
func (l *Limiter) Reconcile(group, identity, reservation string, h ResponseHeaders) error {
	key := l.key(group, identity)
	return l.store.Lock("lock."+key, 10*time.Second, 2*time.Second, func() error {
		window, _ := l.config(group)
		entries := l.activeEntries(key, window)
		cost := 0
		switch {
		case h.Status >= 500 || h.Status == 429:
			cost = 0
		case h.Status >= 400:
			cost = 5
		case h.Status >= 300:
			cost = 1
		default:
			cost = 2
		}
		if h.Used != nil {
			cost = *h.Used
		}
		reserved := 0
		for i := range entries {
			if entries[i].ID == reservation {
				reserved = entries[i].Cost
				entries[i].Cost = cost
				entries[i].Pending = false
			}
		}
		l.putEntries(key, entries, window)
		if h.Remaining != nil {
			pending := 0
			for _, e := range entries {
				if e.Pending {
					pending += e.Cost
				}
			}
			ceiling := max(0, int64(*h.Remaining-pending))
			if prior, ok := loadJSON[remoteCeiling](l.store, key+".remote"); ok {
				alt := max(0, int64(prior.Remaining+reserved-cost))
				if int64(*h.Remaining) < alt {
					ceiling = int64(*h.Remaining)
				} else {
					ceiling = alt
				}
			}
			until := time.Now().Unix() + int64(window)
			if prior, ok := loadJSON[remoteCeiling](l.store, key+".remote"); ok {
				until = prior.Until
			}
			storeJSON(l.store, key+".remote", remoteCeiling{Remaining: int(ceiling), Until: until}, time.Duration(max(1, until-time.Now().Unix()))*time.Second)
		}
		if lim, win, ok := parseAdvertised(h.Advertised); ok {
			storeJSON(l.store, "esi.rl.config."+group, limitConfig{Limit: lim, Window: win}, 24*time.Hour)
		}
		if h.Status == 429 {
			delay := int64(60)
			if h.RetryAfterSecs != nil {
				delay = max(1, int64(*h.RetryAfterSecs))
			}
			deadline := time.Now().Unix() + delay
			if cur := l.cooldownLeft(key); int64(cur) > 0 && time.Now().Unix()+int64(cur) > deadline {
				deadline = time.Now().Unix() + int64(cur)
			}
			l.store.Put(key+".cooldown", []byte(strconv.FormatInt(deadline, 10)), time.Duration(max(1, deadline-time.Now().Unix()))*time.Second)
		}
		return nil
	})
}

// parseAdvertised parses "600/900s", "600/15m", "100/1h" like the PHP regex.
func parseAdvertised(raw string) (limit, window int, ok bool) {
	parts := strings.SplitN(raw, "/", 2)
	if len(parts) != 2 {
		return 0, 0, false
	}
	lim, err := strconv.Atoi(parts[0])
	if err != nil || lim <= 0 {
		return 0, 0, false
	}
	n := 0
	i := 0
	for i < len(parts[1]) && parts[1][i] >= '0' && parts[1][i] <= '9' {
		n = n*10 + int(parts[1][i]-'0')
		i++
	}
	if n <= 0 || i >= len(parts[1]) {
		return 0, 0, false
	}
	switch parts[1][i:] {
	case "s":
		return lim, n, true
	case "m":
		return lim, n * 60, true
	case "h":
		return lim, n * 3600, true
	}
	return 0, 0, false
}
