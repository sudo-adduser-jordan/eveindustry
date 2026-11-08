package esi

import (
	"bytes"
	"context"
	"encoding/json"
	"io"
	"net/http"
	"net/url"
	"strconv"
	"strings"
	"time"
)

// Auth carries one character's credential for an authenticated call. The
// caller refreshes expired tokens via Refresh before sending (or passes a
// Refresher for the automatic post-401 retry).
type Auth struct {
	CharacterID int64
	AccessToken string
}

// Response is one finished ESI response.
type Response struct {
	Status  int
	Headers http.Header
	Body    []byte
}

// JSON decodes the body; nil for empty bodies.
func (r *Response) JSON() any {
	if len(r.Body) == 0 {
		return nil
	}
	var v any
	if err := json.Unmarshal(r.Body, &v); err != nil {
		return nil
	}
	return v
}

// Successful mirrors 2xx.
func (r *Response) Successful() bool { return r.Status >= 200 && r.Status < 300 }

// Pages reads X-Pages, or 0 when absent/unparseable.
func (r *Response) Pages() int {
	p, err := strconv.Atoi(r.Headers.Get("X-Pages"))
	if err != nil || p < 1 {
		return 0
	}
	return p
}

// Client ports EsiHttpClient::send/requireSuccess over the Limiter+Circuit.
// Dials with a 5s connect timeout and 30s total timeout like the PHP
// connectTimeout/timeout pair.
type Client struct {
	HTTP              *http.Client
	BaseURL           string
	UserAgent         string
	CompatibilityDate string
	Limiter           *Limiter
	Circuit           *Circuit
	store             CoordStore
	// Refresh rotates one character's token; nil disables the post-401 retry.
	Refresh func(ctx context.Context, characterID int64, priorAccess string, force bool) (string, error)
}

// NewClient builds a transport with the PHP timeouts.
func NewClient(store CoordStore, limiter *Limiter, circuit *Circuit, userAgent string) *Client {
	transport := &http.Transport{}
	return &Client{
		HTTP:      &http.Client{Timeout: 30 * time.Second, Transport: transport},
		BaseURL:   BaseURL,
		UserAgent: userAgent,
		Limiter:   limiter,
		Circuit:   circuit,
		store:     store,
	}
}

// Do sends one request. params go in the query for GET/HEAD/DELETE and in a
// JSON body otherwise. auth nil means a public call.
func (c *Client) Do(ctx context.Context, method, endpoint string, params map[string]string, headers map[string]string, auth *Auth) (*Response, error) {
	return c.DoJSON(ctx, method, endpoint, params, nil, headers, auth)
}

// DoJSON is Do with an explicit JSON body for non-GET methods (arrays for
// the affiliation/names endpoints, objects elsewhere).
func (c *Client) DoJSON(ctx context.Context, method, endpoint string, query map[string]string, body any, headers map[string]string, auth *Auth) (*Response, error) {
	method = strings.ToUpper(method)
	if c.Circuit != nil && c.Circuit.IsLimited() {
		reset := 1
		if c.Circuit != nil {
			reset = maxInt(1, c.Circuit.ResetsIn())
		}
		return nil, &RequestError{Outcome: OutcomeErrorLimited, RetryAfterSecs: reset}
	}
	group := c.resolveGroup(endpoint, method)
	var identity string
	if auth != nil {
		identity = strconv.FormatInt(auth.CharacterID, 10)
	}
	var reservation string
	if group == "legacy" {
		if c.Limiter != nil && c.Limiter.IsLimited(group, identity) {
			return nil, &RequestError{Outcome: OutcomeRateLimited, RetryAfterSecs: maxInt(1, c.Limiter.ResetsIn(group, identity, 2))}
		}
	} else if c.Limiter != nil {
		id, err := c.Limiter.Reserve(group, identity, 5)
		if err != nil {
			if re, ok := err.(*RequestError); ok {
				return nil, re
			}
			return nil, &RequestError{Outcome: OutcomeCoordination, RetryAfterSecs: 5}
		}
		if id == "" {
			wait := 5
			if c.Limiter != nil {
				wait = maxInt(1, c.Limiter.ResetsIn(group, identity, 5))
			}
			return nil, &RequestError{Outcome: OutcomeRateLimited, RetryAfterSecs: wait}
		}
		reservation = id
	}

	resp, err := c.sendOnce(ctx, method, endpoint, query, body, headers, auth)
	if err != nil {
		return nil, err
	}
	if c.Limiter != nil && reservation != "" {
		_ = c.Limiter.Reconcile(group, identity, reservation, responseHeaders(resp))
	}
	c.learnGroup(endpoint, method, resp)
	if auth != nil && resp.Status == http.StatusUnauthorized && !isMissingScope(resp) && c.Refresh != nil {
		fresh, rerr := c.Refresh(ctx, auth.CharacterID, auth.AccessToken, true)
		if rerr != nil {
			if re, ok := rerr.(*RequestError); ok {
				return nil, re
			}
			return nil, &RequestError{Outcome: OutcomeTransient, RetryAfterSecs: 60, Status: resp.Status}
		}
		auth.AccessToken = fresh
		retry, rerr := c.sendOnce(ctx, method, endpoint, query, body, headers, auth)
		if rerr != nil {
			return nil, rerr
		}
		return c.requireSuccess(retry)
	}
	return c.requireSuccess(resp)
}

func (c *Client) sendOnce(ctx context.Context, method, endpoint string, query map[string]string, body any, headers map[string]string, auth *Auth) (*Response, error) {
	target := endpoint
	if !strings.HasPrefix(endpoint, "http") {
		target = c.BaseURL + endpoint
	}
	var rdr io.Reader
	if method == http.MethodGet || method == http.MethodHead || method == http.MethodDelete {
		if len(query) > 0 {
			q := url.Values{}
			for k, v := range query {
				q.Set(k, v)
			}
			target += "?" + q.Encode()
		}
	} else if body != nil {
		raw, err := json.Marshal(body)
		if err != nil {
			return nil, &RequestError{Outcome: OutcomeCoordination, RetryAfterSecs: 5}
		}
		rdr = bytes.NewReader(raw)
	} else if len(query) > 0 {
		raw, _ := json.Marshal(query)
		rdr = bytes.NewReader(raw)
	}
	req, err := http.NewRequestWithContext(ctx, method, target, rdr)
	if err != nil {
		return nil, &RequestError{Outcome: OutcomeCoordination, RetryAfterSecs: 5}
	}
	ua := c.UserAgent
	if ua == "" {
		ua = "EveIndustrialist/1.0"
	}
	req.Header.Set("User-Agent", ua)
	if c.CompatibilityDate != "" {
		req.Header.Set("X-Compatibility-Date", c.CompatibilityDate)
	}
	for k, v := range headers {
		req.Header.Set(k, v)
	}
	if auth != nil {
		req.Header.Set("Authorization", "Bearer "+auth.AccessToken)
	}
	if rdr != nil {
		req.Header.Set("Content-Type", "application/json")
	}
	started := time.Now()
	hresp, err := c.HTTP.Do(req)
	_ = started
	if err != nil {
		return nil, &RequestError{Outcome: OutcomeTransient, RetryAfterSecs: 30}
	}
	defer hresp.Body.Close()
	raw, _ := io.ReadAll(io.LimitReader(hresp.Body, 32<<20))
	resp := &Response{Status: hresp.StatusCode, Headers: hresp.Header.Clone(), Body: raw}
	c.trackCircuit(hresp)
	return resp, nil
}

func (c *Client) trackCircuit(hresp *http.Response) {
	if c.Circuit == nil {
		return
	}
	var remain *int
	if v := hresp.Header.Get("X-Esi-Error-Limit-Remain"); v != "" {
		if n, err := strconv.Atoi(v); err == nil {
			remain = &n
		}
	}
	reset := ErrorDefaultResetSeconds
	if v := hresp.Header.Get("X-Esi-Error-Limit-Reset"); v != "" {
		if n, err := strconv.Atoi(v); err == nil {
			reset = n
		}
	}
	retry := 0
	if hresp.StatusCode == 420 {
		retry = retryAfterSecs(hresp.Header)
	}
	c.Circuit.TrackResponse(remain, maxInt(1, reset), hresp.StatusCode == 420, retry)
}

// resolveGroup ports resolveRateLimitGroup(): learned route-group cache,
// else legacy. (The pinned esi_routes table port lands with the sub-clients.)
func (c *Client) resolveGroup(endpoint, method string) string {
	path := endpoint
	if u, err := url.Parse(endpoint); err == nil && u.Path != "" {
		path = u.Path
	}
	path = stripVersionPrefix(strings.TrimSuffix(path, "/"))
	key := "esi.routegroup." + sha256hex(strings.ToLower(method)+" "+path)
	if raw, ok := c.store.Get(key); ok {
		return string(raw)
	}
	return "legacy"
}

func stripVersionPrefix(path string) string {
	for _, v := range []string{"/latest/", "/legacy/", "/dev/"} {
		if strings.HasPrefix(path, v) {
			return path[len(v)-1:]
		}
	}
	return path
}

func (c *Client) learnGroup(endpoint, method string, resp *Response) {
	remote := resp.Headers.Get("X-Ratelimit-Group")
	if remote == "" {
		return
	}
	ok := true
	for _, r := range remote {
		if !(r >= 'a' && r <= 'z' || r >= '0' && r <= '9' || r == '-') {
			ok = false
			break
		}
	}
	if !ok {
		return
	}
	path := endpoint
	if u, err := url.Parse(endpoint); err == nil && u.Path != "" {
		path = u.Path
	}
	path = stripVersionPrefix(strings.TrimSuffix(path, "/"))
	key := "esi.routegroup." + sha256hex(strings.ToLower(method)+" "+path)
	c.store.Put(key, []byte(remote), 24*time.Hour)
	if c.Limiter != nil {
		_ = c.Limiter.Reconcile(remote, "", "", responseHeaders(resp))
	}
}

func responseHeaders(r *Response) ResponseHeaders {
	h := ResponseHeaders{Status: r.Status}
	if v := r.Headers.Get("X-Ratelimit-Used"); v != "" {
		if n, err := strconv.Atoi(v); err == nil {
			h.Used = &n
		}
	}
	if v := r.Headers.Get("X-Ratelimit-Remaining"); v != "" {
		if n, err := strconv.Atoi(v); err == nil {
			h.Remaining = &n
		}
	}
	h.Advertised = r.Headers.Get("X-Ratelimit-Limit")
	if ra := retryAfterSecs(r.Headers); ra > 0 {
		h.RetryAfterSecs = &ra
	}
	return h
}

func retryAfterSecs(h http.Header) int {
	v := h.Get("Retry-After")
	if v == "" {
		return 0
	}
	if n, err := strconv.Atoi(v); err == nil {
		return maxInt(0, n)
	}
	if t, err := http.ParseTime(v); err == nil {
		return maxInt(0, int(time.Until(t).Seconds()))
	}
	return 0
}

func isMissingScope(r *Response) bool {
	msg := strings.ToLower(responseErrText(r) + " " + r.Headers.Get("WWW-Authenticate"))
	for _, marker := range []string{"insufficient_scope", "missing scope", "missing required scope", "not have the required scope", "not valid for any required scope"} {
		if strings.Contains(msg, marker) {
			return true
		}
	}
	return false
}

func responseErrText(r *Response) string {
	var data map[string]any
	if err := json.Unmarshal(r.Body, &data); err != nil {
		return ""
	}
	e, _ := data["error"].(string)
	return e
}

// requireSuccess ports requireSuccess(): 2xx/304 pass, everything else maps
// to an outcome with a retry delay.
func (c *Client) requireSuccess(r *Response) (*Response, error) {
	if r.Successful() || r.Status == http.StatusNotModified {
		return r, nil
	}
	var outcome Outcome
	switch {
	case r.Status == 420:
		outcome = OutcomeErrorLimited
	case r.Status == 429:
		outcome = OutcomeRateLimited
	case isMissingScope(r):
		outcome = OutcomeMissingScope
	case r.Status == 401:
		outcome = OutcomeCredential
	case r.Status == 403:
		outcome = OutcomeForbidden
	case r.Status == 404:
		outcome = OutcomeNotFound
	case r.Status >= 500:
		outcome = OutcomeTransient
	default:
		outcome = OutcomeInvalid
	}
	delay := 0
	switch outcome {
	case OutcomeErrorLimited:
		delay = 1
		if c.Circuit != nil {
			delay = maxInt(1, c.Circuit.ResetsIn())
		}
	case OutcomeRateLimited, OutcomeTransient:
		if ra := retryAfterSecs(r.Headers); ra > 0 {
			delay = maxInt(1, ra)
		} else {
			delay = 60
		}
	}
	return nil, &RequestError{Outcome: outcome, RetryAfterSecs: delay, Status: r.Status}
}

func maxInt(a, b int) int {
	if a > b {
		return a
	}
	return b
}
