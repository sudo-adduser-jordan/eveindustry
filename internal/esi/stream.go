package esi

import (
	"context"
	"strconv"

	"github.com/jmoiron/sqlx"
)

// SQLStream ports the diagnostics buffer onto the go_diag_stream table so
// the flag-gated ESI capture works without Redis. Row ids are the stream
// ids (plain integers compare correctly via CompareStreamIDs); drops are
// one row per event in go_diag_drops. A nil DB disables buffering.
type SQLStream struct {
	DB *sqlx.DB
}

// StreamEntry is one buffered event: id => payload.
type StreamEntry struct {
	ID   string
	JSON any
}

// Add appends one JSON event.
func (s *SQLStream) Add(ctx context.Context, payload string) error {
	if s == nil || s.DB == nil {
		return nil
	}
	_, err := s.DB.ExecContext(ctx, `INSERT INTO go_diag_stream (payload) VALUES (?)`, payload)
	return err
}

// Read returns up to count entries after afterID (exclusive), id => payload.
func (s *SQLStream) Read(ctx context.Context, after string, count int) ([]StreamEntry, error) {
	if s == nil || s.DB == nil {
		return nil, nil
	}
	afterID := int64(0)
	if after != "" {
		if n, err := strconv.ParseInt(after, 10, 64); err == nil {
			afterID = n
		} else if ms, seq := splitStreamID(after); ms != 0 || seq != 0 {
			afterID = ms
		}
	}
	var rows []struct {
		ID      int64  `db:"id"`
		Payload string `db:"payload"`
	}
	err := s.DB.SelectContext(ctx, &rows, `SELECT id, payload FROM go_diag_stream
		WHERE id > ? ORDER BY id LIMIT ?`, afterID, count)
	if err != nil {
		return nil, err
	}
	out := make([]StreamEntry, 0, len(rows))
	for _, r := range rows {
		out = append(out, StreamEntry{ID: strconv.FormatInt(r.ID, 10), JSON: r.Payload})
	}
	return out, nil
}

// Len returns the buffered entry count.
func (s *SQLStream) Len(ctx context.Context) int {
	if s == nil || s.DB == nil {
		return 0
	}
	var n int
	if err := s.DB.GetContext(ctx, &n, `SELECT COUNT(*) FROM go_diag_stream`); err != nil {
		return 0
	}
	return n
}

// OldestID returns the lowest entry id, or "".
func (s *SQLStream) OldestID(ctx context.Context) string {
	if s == nil || s.DB == nil {
		return ""
	}
	var id int64
	if err := s.DB.GetContext(ctx, &id, `SELECT id FROM go_diag_stream ORDER BY id LIMIT 1`); err != nil {
		return ""
	}
	return strconv.FormatInt(id, 10)
}

// TotalAdded returns the highest id ever issued, or -1 when unknown.
func (s *SQLStream) TotalAdded(ctx context.Context) int64 {
	if s == nil || s.DB == nil {
		return -1
	}
	var id int64
	if err := s.DB.GetContext(ctx, &id, `SELECT COALESCE(MAX(id), 0) FROM go_diag_stream`); err != nil {
		return -1
	}
	return id
}

// Epoch identifies the buffer generation. SQLite never resets the table,
// so the epoch is stable and the flusher's gap logic stays dormant.
func (s *SQLStream) Epoch(_ context.Context) string { return "sqlite" }

// TrimThrough drops every entry at or below id.
func (s *SQLStream) TrimThrough(ctx context.Context, id string) error {
	if s == nil || s.DB == nil {
		return nil
	}
	n, err := strconv.ParseInt(id, 10, 64)
	if err != nil {
		return nil
	}
	_, err = s.DB.ExecContext(ctx, `DELETE FROM go_diag_stream WHERE id <= ?`, n)
	return err
}

// RecordDrop counts one lost event (oversized payload or failed emit).
func (s *SQLStream) RecordDrop(ctx context.Context) {
	if s == nil || s.DB == nil {
		return
	}
	_, _ = s.DB.ExecContext(ctx, `INSERT INTO go_diag_drops DEFAULT VALUES`)
}

// PendingDrops returns the unacknowledged drop count.
func (s *SQLStream) PendingDrops(ctx context.Context) int {
	if s == nil || s.DB == nil {
		return 0
	}
	var n int
	if err := s.DB.GetContext(ctx, &n, `SELECT COUNT(*) FROM go_diag_drops`); err != nil {
		return 0
	}
	return n
}

// AcknowledgeDrops clears up to count drop rows.
func (s *SQLStream) AcknowledgeDrops(ctx context.Context, count int) {
	if s == nil || s.DB == nil || count <= 0 {
		return
	}
	_, _ = s.DB.ExecContext(ctx, `DELETE FROM go_diag_drops WHERE id IN
		(SELECT id FROM go_diag_drops ORDER BY id LIMIT ?)`, count)
}

// CompareStreamIDs ports StreamId::compare for "ms-seq" ids (plain integer
// row ids compare as ms with seq 0).
func CompareStreamIDs(a, b string) int {
	am, as := splitStreamID(a)
	bm, bs := splitStreamID(b)
	if am != bm {
		if am < bm {
			return -1
		}
		return 1
	}
	if as != bs {
		if as < bs {
			return -1
		}
		return 1
	}
	return 0
}

// StreamIDMillis ports StreamId::milliseconds.
func StreamIDMillis(id string) int64 {
	ms, _ := splitStreamID(id)
	return ms
}

func splitStreamID(id string) (int64, int64) {
	var ms, seq int64
	for i := 0; i < len(id); i++ {
		if id[i] == '-' {
			for _, ch := range id[:i] {
				if ch >= '0' && ch <= '9' {
					ms = ms*10 + int64(ch-'0')
				}
			}
			for _, ch := range id[i+1:] {
				if ch >= '0' && ch <= '9' {
					seq = seq*10 + int64(ch-'0')
				} else {
					break
				}
			}
			break
		}
		if id[i] < '0' || id[i] > '9' {
			break
		}
		if i == len(id)-1 {
			for _, ch := range id {
				ms = ms*10 + int64(ch-'0')
			}
		}
	}
	return ms, seq
}

// nextStreamID bumps the sequence so MINID trims through id.
func nextStreamID(id string) string {
	ms, seq := splitStreamID(id)
	return strconv.FormatInt(ms, 10) + "-" + strconv.FormatInt(seq+1, 10)
}
