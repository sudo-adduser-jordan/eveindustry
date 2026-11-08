package esi

import (
	"encoding/json"
	"sync"
	"time"
)

// CoordStore is the coordination backend for rate limiting, the error
// circuit and token-refresh locks. MemStore serves tests and single-binary
// deployments; a Redis implementation lands with the jobs/deploy phase for
// multi-process coordination.
type CoordStore interface {
	Get(key string) ([]byte, bool)
	Put(key string, value []byte, ttl time.Duration)
	Del(key string)
	// Lock runs fn while holding key, waiting up to wait for it. Only one
	// holder across processes (best effort for MemStore: in-process only).
	Lock(key string, hold, wait time.Duration, fn func() error) error
}

// MemStore is an in-process CoordStore with TTL expiry.
type MemStore struct {
	mu    sync.Mutex
	vals  map[string]memVal
	locks map[string]time.Time
}

type memVal struct {
	data []byte
	exp  time.Time
}

// NewMemStore builds an empty store.
func NewMemStore() *MemStore {
	return &MemStore{vals: map[string]memVal{}, locks: map[string]time.Time{}}
}

func (s *MemStore) Get(key string) ([]byte, bool) {
	s.mu.Lock()
	defer s.mu.Unlock()
	v, ok := s.vals[key]
	if !ok || time.Now().After(v.exp) {
		return nil, false
	}
	return v.data, true
}

func (s *MemStore) Put(key string, value []byte, ttl time.Duration) {
	s.mu.Lock()
	defer s.mu.Unlock()
	if ttl <= 0 {
		delete(s.vals, key)
		return
	}
	s.vals[key] = memVal{data: value, exp: time.Now().Add(ttl)}
}

func (s *MemStore) Del(key string) {
	s.mu.Lock()
	defer s.mu.Unlock()
	delete(s.vals, key)
}

func (s *MemStore) Lock(key string, hold, wait time.Duration, fn func() error) error {
	deadline := time.Now().Add(wait)
	for {
		s.mu.Lock()
		if exp, held := s.locks[key]; !held || time.Now().After(exp) {
			s.locks[key] = time.Now().Add(hold)
			s.mu.Unlock()
			err := fn()
			s.mu.Lock()
			delete(s.locks, key)
			s.mu.Unlock()
			return err
		}
		s.mu.Unlock()
		if time.Now().After(deadline) {
			return &RequestError{Outcome: OutcomeCoordination, RetryAfterSecs: 5}
		}
		time.Sleep(10 * time.Millisecond)
	}
}

func storeJSON[T any](s CoordStore, key string, v T, ttl time.Duration) {
	raw, err := json.Marshal(v)
	if err != nil {
		return
	}
	s.Put(key, raw, ttl)
}

func loadJSON[T any](s CoordStore, key string) (T, bool) {
	var zero T
	raw, ok := s.Get(key)
	if !ok {
		return zero, false
	}
	if err := json.Unmarshal(raw, &zero); err != nil {
		return zero, false
	}
	return zero, true
}
