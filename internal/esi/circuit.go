package esi

import (
	"strconv"
	"time"
)

type Circuit struct {
	store CoordStore
}

func NewCircuit(store CoordStore) *Circuit { return &Circuit{store: store} }

func (c *Circuit) TrackResponse(remain *int, resetSecs int, status420 bool, retryAfter int) {
	if resetSecs < 1 {
		resetSecs = ErrorDefaultResetSeconds
	}
	delay := resetSecs
	if status420 && retryAfter > delay {
		delay = retryAfter
	}
	now := time.Now().Unix()
	if remain != nil {
		c.store.Put("esi.error_limit_remain", []byte(strconv.Itoa(*remain)), time.Duration(delay)*time.Second)
		c.store.Put("esi.error_limit_reset", []byte(strconv.FormatInt(now+int64(delay), 10)), time.Duration(delay)*time.Second)
	}
	if !status420 && (remain == nil || *remain > ErrorLimitThreshold) {
		return
	}
	_ = c.store.Lock("esi.error_circuit.lock", 10*time.Second, 2*time.Second, func() error {
		previous := c.untilLocked()
		deadline := previous
		if now+int64(delay) > deadline {
			deadline = now + int64(delay)
		}
		c.store.Put("esi.error_circuit.until", []byte(strconv.FormatInt(deadline, 10)), time.Duration(max(1, deadline-now))*time.Second)
		return nil
	})
}

func (c *Circuit) untilLocked() int64 {
	raw, ok := c.store.Get("esi.error_circuit.until")
	if !ok {
		return 0
	}
	v, err := strconv.ParseInt(string(raw), 10, 64)
	if err != nil {
		return 0
	}
	return v
}

func (c *Circuit) ResetsIn() int {
	left := c.untilLocked() - time.Now().Unix()
	if left < 0 {
		return 0
	}
	return int(left)
}

func (c *Circuit) IsLimited() bool { return c.ResetsIn() > 0 }
