package jobs

import (
	"context"
	"encoding/json"
	"fmt"
	"time"

	"github.com/redis/go-redis/v9"
)

// Queues mirror the Horizon supervisors.
const (
	QueueDefault = "default"
	QueueUpdates = "updates"
	QueueMarket  = "market"
)

// Envelope is one queued job.
type Envelope struct {
	ID       string          `json:"id"`
	Name     string          `json:"name"`
	Payload  json.RawMessage `json:"payload,omitempty"`
	Attempts int             `json:"attempts"`
	MaxTries int             `json:"max_tries"`
	Enqueued time.Time       `json:"enqueued_at"`
}

// Queue is a named FIFO per queue name.
type Queue interface {
	Push(ctx context.Context, queue string, e Envelope) error
	Pop(ctx context.Context, queues []string, wait time.Duration) (string, Envelope, bool, error)
	Len(ctx context.Context, queue string) (int64, error)
}

// RedisQueue ports the Horizon Redis queues onto lists (LPUSH/BRPOP).
type RedisQueue struct {
	Client *redis.Client
	Prefix string
}

func (q *RedisQueue) key(queue string) string {
	if q.Prefix != "" {
		return q.Prefix + ":queue:" + queue
	}
	return "jobs:queue:" + queue
}

// Push appends a job (LPUSH; workers BRPOP from the tail).
func (q *RedisQueue) Push(ctx context.Context, queue string, e Envelope) error {
	if e.ID == "" {
		e.ID = fmt.Sprintf("%d", time.Now().UnixNano())
	}
	if e.Enqueued.IsZero() {
		e.Enqueued = time.Now()
	}
	raw, err := json.Marshal(e)
	if err != nil {
		return err
	}
	return q.Client.LPush(ctx, q.key(queue), raw).Err()
}

// Pop blocks up to wait for the first available job across queues.
func (q *RedisQueue) Pop(ctx context.Context, queues []string, wait time.Duration) (string, Envelope, bool, error) {
	keys := make([]string, 0, len(queues))
	for _, name := range queues {
		keys = append(keys, q.key(name))
	}
	res, err := q.Client.BRPop(ctx, wait, keys...).Result()
	if err == redis.Nil {
		return "", Envelope{}, false, nil
	}
	if err != nil {
		return "", Envelope{}, false, err
	}
	var e Envelope
	if err := json.Unmarshal([]byte(res[1]), &e); err != nil {
		return "", Envelope{}, false, err
	}
	for i, k := range keys {
		if k == res[0] {
			return queues[i], e, true, nil
		}
	}
	return "", e, true, nil
}

// Len reports the pending depth of a queue.
func (q *RedisQueue) Len(ctx context.Context, queue string) (int64, error) {
	return q.Client.LLen(ctx, q.key(queue)).Result()
}

// MemoryQueue is an in-process FIFO for tests and --sync-style runs.
type MemoryQueue struct {
	items map[string][]Envelope
}

// NewMemoryQueue builds an empty in-process queue.
func NewMemoryQueue() *MemoryQueue { return &MemoryQueue{items: map[string][]Envelope{}} }

// Push appends a job.
func (q *MemoryQueue) Push(_ context.Context, queue string, e Envelope) error {
	if e.ID == "" {
		e.ID = fmt.Sprintf("%d", time.Now().UnixNano())
	}
	q.items[queue] = append(q.items[queue], e)
	return nil
}

// Pop returns the oldest pending job across queues (no waiting).
func (q *MemoryQueue) Pop(_ context.Context, queues []string, _ time.Duration) (string, Envelope, bool, error) {
	for _, name := range queues {
		if len(q.items[name]) > 0 {
			e := q.items[name][0]
			q.items[name] = q.items[name][1:]
			return name, e, true, nil
		}
	}
	return "", Envelope{}, false, nil
}

// Len reports the pending depth of a queue.
func (q *MemoryQueue) Len(_ context.Context, queue string) (int64, error) {
	return int64(len(q.items[queue])), nil
}

// Payload helpers.
func MarshalPayload(v any) json.RawMessage {
	raw, _ := json.Marshal(v)
	return raw
}
