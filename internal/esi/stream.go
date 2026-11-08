package esi

import (
	"context"
	"time"

	"github.com/redis/go-redis/v9"
)

// Diag stream keys mirror RedisStream (non-evicting Redis).
const (
	diagStreamKey   = "esi:diag:stream"
	diagEpochKey    = "esi:diag:stream:epoch"
	diagDropsKey    = "esi:diag:stream:drops"
	diagAddedKey    = "esi:diag:stream:added"
	diagDefaultCap  = 100000
)

// RedisStream ports the Redis diagnostics buffer: capped XADD, XRANGE reads,
// MINID trims, epoch + added/drop counters for gap accounting.
type RedisStream struct {
	Client *redis.Client
	Key    string
	Cap    int64
}

func (s *RedisStream) key() string {
	if s.Key != "" {
		return s.Key
	}
	return diagStreamKey
}

func (s *RedisStream) cap() int64 {
	if s.Cap > 0 {
		return s.Cap
	}
	return diagDefaultCap
}

// Add appends one JSON event, counting it toward totalAdded.
func (s *RedisStream) Add(ctx context.Context, payload string) error {
	pipe := s.Client.TxPipeline()
	pipe.XAdd(ctx, &redis.XAddArgs{Stream: s.key(), Approx: true, MaxLen: s.cap(), Values: map[string]any{"e": payload}})
	pipe.Incr(ctx, s.key()+":added")
	_, err := pipe.Exec(ctx)
	return err
}

// Read returns up to count entries after afterID (exclusive), id => payload.
func (s *RedisStream) Read(ctx context.Context, after string, count int) ([]StreamEntry, error) {
	start := "-"
	if after != "" {
		start = "(" + after
	}
	rows, err := s.Client.XRangeN(ctx, s.key(), start, "+", int64(count)).Result()
	if err != nil {
		return nil, err
	}
	out := make([]StreamEntry, 0, len(rows))
	for _, r := range rows {
		out = append(out, StreamEntry{ID: r.ID, JSON: r.Values["e"]})
	}
	return out, nil
}

// StreamEntry is one stream record.
type StreamEntry struct {
	ID   string
	JSON any
}

// Len returns the buffered entry count.
func (s *RedisStream) Len(ctx context.Context) int {
	n, err := s.Client.XLen(ctx, s.key()).Result()
	if err != nil {
		return 0
	}
	return int(n)
}

// OldestID returns the lowest entry id, or "".
func (s *RedisStream) OldestID(ctx context.Context) string {
	rows, err := s.Client.XRangeN(ctx, s.key(), "-", "+", 1).Result()
	if err != nil || len(rows) == 0 {
		return ""
	}
	return rows[0].ID
}

// TotalAdded returns the ever-added counter, or -1 when unknown.
func (s *RedisStream) TotalAdded(ctx context.Context) int64 {
	n, err := s.Client.Get(ctx, s.key()+":added").Int64()
	if err != nil {
		return -1
	}
	return n
}

// Epoch returns the buffer epoch, creating it once.
func (s *RedisStream) Epoch(ctx context.Context) string {
	// SET NX via SetArgs to avoid a separate round trip on repeat calls.
	ok, err := s.Client.SetNX(ctx, s.key()+":epoch", newEpoch(), 0).Result()
	if err != nil {
		return ""
	}
	_ = ok
	v, err := s.Client.Get(ctx, s.key()+":epoch").Result()
	if err != nil {
		return ""
	}
	return v
}

// TrimThrough drops every entry at or below id.
func (s *RedisStream) TrimThrough(ctx context.Context, id string) error {
	_, err := s.Client.Do(ctx, "XTRIM", s.key(), "MINID", nextStreamID(id)).Result()
	return err
}

// RecordDrop counts one oversized/sink failure.
func (s *RedisStream) RecordDrop(ctx context.Context) {
	_ = s.Client.Incr(ctx, s.key()+":drops").Err()
}

// PendingDrops returns the unacknowledged drop count.
func (s *RedisStream) PendingDrops(ctx context.Context) int {
	n, err := s.Client.Get(ctx, s.key()+":drops").Int64()
	if err != nil {
		return 0
	}
	return int(n)
}

// AcknowledgeDrops subtracts flushed drops.
func (s *RedisStream) AcknowledgeDrops(ctx context.Context, count int) {
	if count <= 0 {
		return
	}
	_ = s.Client.DecrBy(ctx, s.key()+":drops", int64(count)).Err()
}

// CompareStreamIDs ports StreamId::compare for "ms-seq" ids.
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
	return itoa64(ms) + "-" + itoa64(seq+1)
}

// newEpoch mints a random epoch marker.
func newEpoch() string {
	return itoa64(time.Now().UnixNano())
}

func itoa64(n int64) string {
	if n == 0 {
		return "0"
	}
	neg := n < 0
	if neg {
		n = -n
	}
	var b [20]byte
	p := len(b)
	for n > 0 {
		p--
		b[p] = byte('0' + n%10)
		n /= 10
	}
	if neg {
		p--
		b[p] = '-'
	}
	return string(b[p:])
}
