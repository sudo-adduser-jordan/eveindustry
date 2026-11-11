package esi

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"net/http"
	"sort"
	"strconv"
	"strings"
	"time"
)

type cacheEntry struct {
	Status     int               `json:"status"`
	Body       []byte            `json:"body,omitempty"`
	Headers    map[string]string `json:"headers"`
	FreshUntil int64             `json:"fresh_until"`
	ExpiresAt  int64             `json:"expires_at"`
}

type HTTPCache struct {
	Store     CoordStore
	Retention time.Duration
	MaxBytes  int
	Recorder  *DiagRecorder
}

func NewHTTPCache(store CoordStore) *HTTPCache {
	return &HTTPCache{Store: store, Retention: 24 * time.Hour, MaxBytes: 4 * 1024 * 1024}
}

func cacheKey(method, endpoint string, query map[string]string, headers map[string]string, identity string) string {
	var qs []string
	for k, v := range query {
		qs = append(qs, k+"="+v)
	}
	sort.Strings(qs)
	var hs []string
	for k, v := range headers {
		hs = append(hs, strings.ToLower(k)+"="+v)
	}
	sort.Strings(hs)
	sum := sha256.Sum256([]byte(strings.ToLower(method) + " " + endpoint + "?" + strings.Join(qs, "&") + "|" + strings.Join(hs, "&") + "|" + identity))
	return "esi.http." + hex.EncodeToString(sum[:])
}

func validEntry(e *cacheEntry) bool {
	return e != nil && e.Status == 200 && len(e.Body) > 0
}

func (c *HTTPCache) Remember(ctx context.Context, client *Client, endpoint string, query map[string]string, headers map[string]string, auth *Auth) (*Response, error) {
	identity := ""
	if auth != nil {
		identity = strconv.FormatInt(auth.CharacterID, 10)
	}
	key := cacheKey("get", endpoint, query, headers, identity)
	if raw, ok := c.Store.Get(key); ok {
		var e cacheEntry
		if json.Unmarshal(raw, &e) == nil && validEntry(&e) && e.FreshUntil > time.Now().Unix() {
			c.recordCache(ctx, auth, endpoint, DiagCacheHit)
			return entryResponse(&e), nil
		}
	}
	var fetched *Response
	err := c.Store.Lock("lock."+key, 45*time.Second, 2*time.Second, func() error {
		if raw, ok := c.Store.Get(key); ok {
			var e cacheEntry
			if json.Unmarshal(raw, &e) == nil && validEntry(&e) && e.FreshUntil > time.Now().Unix() {
				c.recordCache(ctx, auth, endpoint, DiagCacheHit)
				fetched = entryResponse(&e)
				return nil
			}
		}
		cond := map[string]string{}
		for k, v := range headers {
			cond[k] = v
		}
		var stored *cacheEntry
		if raw, ok := c.Store.Get(key); ok {
			var e cacheEntry
			if json.Unmarshal(raw, &e) == nil && validEntry(&e) {
				stored = &e
				if et := e.Headers["etag"]; et != "" {
					cond["If-None-Match"] = et
				} else if lm := e.Headers["last-modified"]; lm != "" {
					cond["If-Modified-Since"] = lm
				}
			}
		}
		if stored != nil {
			c.recordCache(ctx, auth, endpoint, DiagCacheRevalidate)
		} else {
			c.recordCache(ctx, auth, endpoint, DiagCacheMiss)
		}
		resp, err := client.sendOnce(ctx, http.MethodGet, endpoint, query, nil, cond, auth)
		if err != nil {
			return err
		}
		if resp.Status == http.StatusNotModified {
			if stored == nil {
				return &RequestError{Outcome: OutcomeIncomplete, RetryAfterSecs: 30, Status: 304}
			}
			merged := map[string]string{}
			for k, v := range stored.Headers {
				merged[strings.ToLower(k)] = v
			}
			for k, v := range resp.Headers {
				merged[strings.ToLower(k)] = strings.Join(v, ", ")
			}
			if resp.Headers.Get("Age") == "" {
				delete(merged, "age")
			}
			delete(merged, "content-length")
			stored.Headers = merged
			fetched = entryResponse(stored)
			return nil
		}
		if resp.Status == http.StatusOK && len(resp.Body) > 0 {
			if c.cacheable(resp) {
				e := cacheEntry{Status: 200, Body: resp.Body, Headers: lowerHeaders(resp.Headers)}
				e.FreshUntil, e.ExpiresAt = freshness(resp, time.Now())
				if raw, err := json.Marshal(e); err == nil {
					c.Store.Put(key, raw, c.Retention)
				}
			}
			if cc := strings.ToLower(resp.Headers.Get("Cache-Control")); strings.Contains(cc, "no-store") {
				c.Store.Del(key)
			}
		}
		fetched = resp
		return nil
	})
	if err != nil {
		if reqErr, ok := err.(*RequestError); ok {
			return nil, reqErr
		}
		return nil, &RequestError{Outcome: OutcomeCoordination, RetryAfterSecs: 5}
	}
	return fetched, nil
}

func entryResponse(e *cacheEntry) *Response {
	h := http.Header{}
	for k, v := range e.Headers {
		h.Set(k, v)
	}
	return &Response{Status: 200, Headers: h, Body: e.Body}
}

func lowerHeaders(h http.Header) map[string]string {
	out := map[string]string{}
	for k, v := range h {
		out[strings.ToLower(k)] = strings.Join(v, ", ")
	}
	return out
}

func (c *HTTPCache) cacheable(resp *Response) bool {
	if len(resp.Body) > c.MaxBytes {
		return false
	}
	control := strings.ToLower(resp.Headers.Get("Cache-Control"))
	if strings.Contains(control, "no-store") {
		return false
	}
	if resp.Headers.Get("Cache-Control") == "" && resp.Headers.Get("Expires") == "" {
		return false
	}
	vary := strings.ToLower(resp.Headers.Get("Vary"))
	for _, part := range strings.Split(vary, ",") {
		switch strings.TrimSpace(part) {
		case "", "accept", "accept-language", "accept-encoding", "authorization":
		default:
			return false
		}
	}
	return true
}

func freshness(resp *Response, observed time.Time) (freshUntil, expiresAt int64) {
	now := observed.Unix()
	date := now
	if d, err := http.ParseTime(resp.Headers.Get("Date")); err == nil {
		date = d.Unix()
	}
	modified := date
	if m, err := http.ParseTime(resp.Headers.Get("Last-Modified")); err == nil {
		modified = m.Unix()
	}
	age := 0
	if a, err := strconv.Atoi(resp.Headers.Get("Age")); err == nil && a > 0 {
		age = a
	}
	if delta := int(observed.Unix() - date); delta > age {
		age = delta
	}
	control := strings.ToLower(resp.Headers.Get("Cache-Control"))
	fresh := now
	if strings.Contains(control, "max-age=") {
		for _, part := range strings.Split(control, ",") {
			part = strings.TrimSpace(part)
			if strings.HasPrefix(part, "max-age=") {
				if n, err := strconv.Atoi(strings.TrimPrefix(part, "max-age=")); err == nil && n >= 0 {
					fresh = observed.Unix() - int64(age) + int64(n)
				}
			}
		}
	} else if exp := resp.Headers.Get("Expires"); exp != "" {
		if t, err := http.ParseTime(exp); err == nil {
			fresh = t.Unix()
		}
	}
	_ = modified
	return fresh, fresh
}

func (c *HTTPCache) recordCache(ctx context.Context, auth *Auth, endpoint string, kind DiagEventKind) {
	if c.Recorder == nil || !c.Recorder.Enabled {
		return
	}
	var charID int64
	if auth != nil {
		charID = auth.CharacterID
	}
	c.Recorder.Record(ctx, DiagEvent{
		Kind: kind, Method: "GET", Endpoint: endpoint,
		Operation: NormalizeRoute("get", endpoint), CharacterID: charID, Reason: "cache",
	})
}
