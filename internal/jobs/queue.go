package jobs

import (
	"context"
	"encoding/json"
	"fmt"
	"time"
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
