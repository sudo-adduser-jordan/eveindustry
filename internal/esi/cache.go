package esi

import (
	"sync"
	"time"
)

// Memo is a tiny TTL cache mirroring the Cache::remember/forget shapes the
// character client uses. The full validator-based ESI HTTP cache lands
// later; per-method 1h TTLs match the PHP cache durations.
type Memo struct {
	mu   sync.Mutex
	vals map[string]memoVal
}

type memoVal struct {
	data any
	exp  time.Time
}

// NewMemo builds an empty memo.
func NewMemo() *Memo { return &Memo{vals: map[string]memoVal{}} }

// Get returns cached data or false on miss/expiry.
func (m *Memo) Get(key string) (any, bool) {
	m.mu.Lock()
	defer m.mu.Unlock()
	v, ok := m.vals[key]
	if !ok || time.Now().After(v.exp) {
		return nil, false
	}
	return v.data, true
}

// Set stores data for ttl. Empty slices are stored (the caller decides
// what must not be cached — e.g. empty asset snapshots).
func (m *Memo) Set(key string, data any, ttl time.Duration) {
	m.mu.Lock()
	defer m.mu.Unlock()
	m.vals[key] = memoVal{data: data, exp: time.Now().Add(ttl)}
}

// Forget drops a key.
func (m *Memo) Forget(key string) {
	m.mu.Lock()
	defer m.mu.Unlock()
	delete(m.vals, key)
}
