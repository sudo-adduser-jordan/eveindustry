package logx

import (
	"context"
	"encoding/json"
	"fmt"
	"log/slog"
	"os"
	"path/filepath"
	"sync"
	"time"
)

const (
	colorReset   = "\x1b[0m"
	colorDim     = "\x1b[2m"
	colorBold    = "\x1b[1m"
	colorRed     = "\x1b[31m"
	colorGreen   = "\x1b[32m"
	colorYellow  = "\x1b[33m"
	colorBlue    = "\x1b[34m"
	colorMagenta = "\x1b[35m"
	colorCyan    = "\x1b[36m"
	colorWhite   = "\x1b[37m"
	colorGray    = "\x1b[90m"
)

type dualHandler struct {
	mu        *sync.Mutex
	level     slog.Leveler
	testFile  *os.File
	errorFile *os.File
	testEnc   *json.Encoder
	errorEnc  *json.Encoder
	service   string
	attrs     []slog.Attr
	groups    []string
}

func New(service string) *slog.Logger {
	_ = os.MkdirAll(filepath.Join("logs", "test"), 0o755)
	_ = os.MkdirAll(filepath.Join("logs", "errors"), 0o755)
	tf, _ := os.OpenFile(filepath.Join("logs", "test", "app.jsonl"), os.O_CREATE|os.O_APPEND|os.O_WRONLY, 0o644)
	ef, _ := os.OpenFile(filepath.Join("logs", "errors", "app.jsonl"), os.O_CREATE|os.O_APPEND|os.O_WRONLY, 0o644)
	h := &dualHandler{
		mu:        &sync.Mutex{},
		level:     slog.LevelDebug,
		testFile:  tf,
		errorFile: ef,
		service:   service,
	}
	if tf != nil {
		h.testEnc = json.NewEncoder(tf)
	}
	if ef != nil {
		h.errorEnc = json.NewEncoder(ef)
	}
	return slog.New(h)
}

func Default(service string) *slog.Logger {
	return New(service)
}

func (h *dualHandler) Enabled(_ context.Context, l slog.Level) bool {
	return l >= h.level.Level()
}

func (h *dualHandler) WithAttrs(attrs []slog.Attr) slog.Handler {
	nh := *h
	nh.attrs = append(append([]slog.Attr{}, h.attrs...), attrs...)
	return &nh
}

func (h *dualHandler) WithGroup(name string) slog.Handler {
	nh := *h
	nh.groups = append(append([]string{}, h.groups...), name)
	return &nh
}

func (h *dualHandler) Handle(_ context.Context, r slog.Record) error {
	fields := map[string]any{}
	for _, a := range h.attrs {
		fields[a.Key] = a.Value.Any()
	}
	r.Attrs(func(a slog.Attr) bool {
		key := a.Key
		for _, g := range h.groups {
			if key == "" {
				key = g
			} else {
				key = g + "." + key
			}
		}
		fields[key] = a.Value.Any()
		return true
	})
	entry := map[string]any{
		"timestamp": r.Time.UTC().Format(time.RFC3339Nano),
		"level":     r.Level.String(),
		"service":   h.service,
		"msg":       r.Message,
	}
	for k, v := range fields {
		entry[k] = v
	}
	h.mu.Lock()
	defer h.mu.Unlock()
	if h.testEnc != nil {
		_ = h.testEnc.Encode(entry)
	}
	if h.errorEnc != nil && r.Level >= slog.LevelWarn {
		_ = h.errorEnc.Encode(entry)
	}
	fmt.Fprintln(os.Stdout, prettyLine(h.service, r, fields))
	return nil
}

func prettyLine(service string, r slog.Record, fields map[string]any) string {
	ts := colorDim + r.Time.Format("2006-01-02T15:04:05.000Z07:00") + colorReset
	lvl := levelColor(r.Level) + colorBold + r.Level.String() + colorReset
	msg := colorWhite + colorBold + r.Message + colorReset
	out := ts + " " + lvl + " " + msg
	if service != "" {
		out += " " + colorGray + "service=" + colorizeValue(service) + colorReset
	}
	for k, v := range fields {
		out += " " + colorCyan + k + colorReset + "=" + colorizeValue(v)
	}
	return out
}

func levelColor(l slog.Level) string {
	switch {
	case l >= slog.LevelError:
		return colorRed
	case l >= slog.LevelWarn:
		return colorYellow
	case l >= slog.LevelInfo:
		return colorGreen
	default:
		return colorBlue
	}
}

func colorizeValue(v any) string {
	switch t := v.(type) {
	case nil:
		return colorGray + "null" + colorReset
	case string:
		return colorGreen + t + colorReset
	case bool:
		return colorYellow + fmt.Sprint(t) + colorReset
	case int, int8, int16, int32, int64, uint, uint8, uint16, uint32, uint64:
		return colorMagenta + fmt.Sprint(t) + colorReset
	case float32, float64:
		return colorMagenta + fmt.Sprint(t) + colorReset
	case time.Duration:
		return colorBlue + t.String() + colorReset
	case error:
		return colorRed + t.Error() + colorReset
	default:
		return colorWhite + fmt.Sprint(t) + colorReset
	}
}
