package jobs

import (
	"context"
	"database/sql"
	"errors"
	"strconv"
	"time"

	"github.com/jmoiron/sqlx"
)

// SQLQueue ports the Horizon queue shapes onto the go_jobs table so
// scheduler and worker share one FIFO across processes without an external
// broker. Claim-by-DELETE arbitrates concurrent poppers:
// the winner deletes the row, losers see zero rows affected and retry.
type SQLQueue struct {
	DB *sqlx.DB
}

func sqlTime(t time.Time) string {
	return t.UTC().Format("2006-01-02 15:04:05")
}

// Push appends a job to a queue.
func (q *SQLQueue) Push(ctx context.Context, queue string, e Envelope) error {
	if e.ID == "" {
		e.ID = strconv.FormatInt(time.Now().UnixNano(), 10)
	}
	if e.Enqueued.IsZero() {
		e.Enqueued = time.Now()
	}
	_, err := q.DB.ExecContext(ctx, `INSERT INTO go_jobs
		(queue, name, payload, attempts, max_tries, enqueued_at)
		VALUES (?, ?, ?, ?, ?, ?)`,
		queue, e.Name, string(e.Payload), e.Attempts, e.MaxTries, sqlTime(e.Enqueued))
	return err
}

// Pop returns the oldest available job across queues, polling until wait
// lapses (the blocking-pop replacement for BRPOP).
func (q *SQLQueue) Pop(ctx context.Context, queues []string, wait time.Duration) (string, Envelope, bool, error) {
	deadline := time.Now().Add(wait)
	for {
		name, e, ok, err := q.popOne(ctx, queues)
		if err != nil || ok {
			return name, e, ok, err
		}
		if time.Now().After(deadline) {
			return "", Envelope{}, false, nil
		}
		select {
		case <-ctx.Done():
			return "", Envelope{}, false, ctx.Err()
		case <-time.After(200 * time.Millisecond):
		}
	}
}

func (q *SQLQueue) popOne(ctx context.Context, queues []string) (string, Envelope, bool, error) {
	if len(queues) == 0 {
		return "", Envelope{}, false, nil
	}
	placeholders := ""
	args := make([]any, 0, len(queues))
	for i, name := range queues {
		if i > 0 {
			placeholders += ","
		}
		placeholders += "?"
		args = append(args, name)
	}
	var row struct {
		ID       int64  `db:"id"`
		Queue    string `db:"queue"`
		Name     string `db:"name"`
		Payload  string `db:"payload"`
		Attempts int    `db:"attempts"`
		MaxTries int    `db:"max_tries"`
		Enqueued string `db:"enqueued_at"`
	}
	err := q.DB.GetContext(ctx, &row, `SELECT id, queue, name, payload,
		attempts, max_tries, enqueued_at FROM go_jobs
		WHERE queue IN (`+placeholders+`) AND available_at <= CURRENT_TIMESTAMP
		ORDER BY id LIMIT 1`, args...)
	if err != nil {
		if errors.Is(err, sql.ErrNoRows) {
			return "", Envelope{}, false, nil
		}
		return "", Envelope{}, false, err
	}
	res, err := q.DB.ExecContext(ctx, `DELETE FROM go_jobs WHERE id = ?`, row.ID)
	if err != nil {
		return "", Envelope{}, false, err
	}
	if n, _ := res.RowsAffected(); n == 0 {
		// Lost the race to another popper; report empty so the
		// caller polls again.
		return "", Envelope{}, false, nil
	}
	enqueued, _ := time.ParseInLocation("2006-01-02 15:04:05", row.Enqueued, time.UTC)
	return row.Queue, Envelope{
		ID:       strconv.FormatInt(row.ID, 10),
		Name:     row.Name,
		Payload:  []byte(row.Payload),
		Attempts: row.Attempts,
		MaxTries: row.MaxTries,
		Enqueued: enqueued,
	}, true, nil
}

// Len reports the pending available depth of a queue.
func (q *SQLQueue) Len(ctx context.Context, queue string) (int64, error) {
	var n int64
	err := q.DB.GetContext(ctx, &n, `SELECT COUNT(*) FROM go_jobs
		WHERE queue = ? AND available_at <= CURRENT_TIMESTAMP`, queue)
	return n, err
}

// Locker mirrors the old SETNX locks the runner (ShouldBeUnique) and the
// scheduler (withoutOverlapping) used. A nil Locker disables locking.
type Locker interface {
	Acquire(ctx context.Context, key string, window time.Duration) bool
	Release(ctx context.Context, key string)
}

// DBLocker implements Locker on the go_locks table. The upsert only
// overwrites expired rows; RowsAffected tells whether we won.
type DBLocker struct {
	DB *sqlx.DB
}

// Acquire holds the lock for window. False means another process owns it.
func (l *DBLocker) Acquire(ctx context.Context, key string, window time.Duration) bool {
	if l == nil || l.DB == nil || window <= 0 {
		return true
	}
	expiry := sqlTime(time.Now().Add(window))
	res, err := l.DB.ExecContext(ctx, `INSERT INTO go_locks (name, expires_at)
		VALUES (?, ?)
		ON CONFLICT(name) DO UPDATE SET expires_at = excluded.expires_at
		WHERE go_locks.expires_at <= CURRENT_TIMESTAMP`, key, expiry)
	if err != nil {
		return false
	}
	n, _ := res.RowsAffected()
	return n == 1
}

// Release drops the lock.
func (l *DBLocker) Release(ctx context.Context, key string) {
	if l == nil || l.DB == nil {
		return
	}
	_, _ = l.DB.ExecContext(ctx, `DELETE FROM go_locks WHERE name = ?`, key)
}
