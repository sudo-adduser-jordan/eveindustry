package esi

import (
	"context"
	"encoding/json"
	"strings"
	"time"
)

func ClassifyReason(status *int, message string) string {
	if status == nil {
		return "connection"
	}
	s := *status
	if s == 304 {
		return "not_modified"
	}
	if s >= 200 && s < 300 {
		return "ok"
	}
	if s == 420 {
		return "throttled_420"
	}
	if s == 429 {
		return "throttled_429"
	}
	if s >= 500 {
		return "upstream_failure"
	}
	text := strings.ToLower(message)
	if text != "" {
		if strings.Contains(text, "scope") {
			return "missing_scope"
		}
		if strings.Contains(text, "role") {
			return "missing_role"
		}
		if strings.Contains(text, "corporation") && (strings.Contains(text, "member") || strings.Contains(text, "mismatch") || strings.Contains(text, "not ")) {
			return "corporation_mismatch"
		}
		if s == 400 && (strings.Contains(text, "invalid") || strings.Contains(text, " ids") || strings.Contains(text, "ids ")) {
			return "invalid_id_batch"
		}
	}
	switch {
	case s == 401:
		return "credential"
	case s == 403:
		return "forbidden"
	case s == 404:
		return "not_found"
	case s >= 400:
		return "client_error"
	default:
		return "unknown"
	}
}

type StreamSink struct {
	Stream   *SQLStream
	MaxBytes int
}

func (s *StreamSink) Emit(ctx context.Context, e DiagEvent) {
	raw, err := json.Marshal(diagPayload(e))
	if err != nil || (s.MaxBytes > 0 && len(raw) > s.MaxBytes) {
		if s.Stream != nil {
			s.Stream.RecordDrop(ctx)
		}
		return
	}
	if s.Stream == nil {
		return
	}
	if err := s.Stream.Add(ctx, string(raw)); err != nil {
		s.Stream.RecordDrop(ctx)
	}
}

func diagPayload(e DiagEvent) map[string]any {
	m := map[string]any{
		"event_id":    newEventID(),
		"occurred_at": e.OccurredAt.UTC().Format("2006-01-02 15:04:05.000000"),
		"kind":        string(e.Kind),
		"method":      e.Method,
		"endpoint":    e.Endpoint,
		"reason":      e.Reason,
	}
	if e.Operation != "" {
		m["operation_key"] = e.Operation
	}
	if e.CharacterID != 0 {
		m["character_id"] = e.CharacterID
	}
	if e.HTTPStatus != 0 {
		m["http_status"] = e.HTTPStatus
	}
	if e.DurationMs != nil {
		m["duration_ms"] = *e.DurationMs
	}
	if e.Group != "" {
		m["limiter_group"] = e.Group
	}
	if e.Dataset != "" {
		m["dataset"] = e.Dataset
	}
	return m
}

func NormalizeRoute(method, endpoint string) string {
	m := strings.ToLower(method)
	path := endpoint
	if u, err := urlParse(path); err == nil && u != "" {
		path = u
	}
	path = strings.TrimSuffix(stripVersionPrefix("/"+strings.Trim(path, "/")), "/")
	if !strings.HasPrefix(path, "/") {
		path = "/" + path
	}
	segs := strings.Split(strings.Trim(path, "/"), "/")
	for route := range routeGroups {
		sp := strings.IndexByte(route, ' ')
		if sp < 0 || route[:sp] != m {
			continue
		}
		ts := strings.Split(strings.Trim(route[sp+1:], "/"), "/")
		if len(ts) != len(segs) {
			continue
		}
		hit := true
		for i := range ts {
			t := ts[i]
			if len(t) > 2 && t[0] == '{' && t[len(t)-1] == '}' {
				continue
			}
			if t != segs[i] {
				hit = false
				break
			}
		}
		if hit {
			return route
		}
	}
	parts := strings.Split(path, "/")
	for i, part := range parts {
		if part != "" && isDigits(part) {
			parts[i] = "{id}"
		}
	}
	return m + " " + strings.Join(parts, "/")
}

func urlParse(s string) (string, error) {
	if i := strings.IndexByte(s, '?'); i >= 0 {
		return s[:i], nil
	}
	return s, nil
}

func newEventID() string {
	const alphabet = "0123456789ABCDEFGHJKMNPQRSTVWXYZ"
	ms := time.Now().UnixMilli()
	var b [26]byte
	for i := 9; i >= 0; i-- {
		b[i] = alphabet[ms&31]
		ms >>= 5
	}
	for i := 10; i < 26; i++ {
		b[i] = alphabet[time.Now().UnixNano()&31]
	}
	return string(b[:])
}

func isDigits(s string) bool {
	if s == "" {
		return false
	}
	for i := 0; i < len(s); i++ {
		if s[i] < '0' || s[i] > '9' {
			return false
		}
	}
	return true
}
