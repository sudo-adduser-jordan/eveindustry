package esi

import (
	"sync"
	"time"
)

type Memo struct {
	mu   sync.Mutex
	vals map[string]memoVal
}

type memoVal struct {
	data any
	exp  time.Time
}

func NewMemo() *Memo { return &Memo{vals: map[string]memoVal{}} }

func (m *Memo) Get(key string) (any, bool) {
	m.mu.Lock()
	defer m.mu.Unlock()
	v, ok := m.vals[key]
	if !ok || time.Now().After(v.exp) {
		return nil, false
	}
	return v.data, true
}

func (m *Memo) Set(key string, data any, ttl time.Duration) {
	m.mu.Lock()
	defer m.mu.Unlock()
	m.vals[key] = memoVal{data: data, exp: time.Now().Add(ttl)}
}

func (m *Memo) Forget(key string) {
	m.mu.Lock()
	defer m.mu.Unlock()
	delete(m.vals, key)
}
