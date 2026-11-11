package cache

import (
	"context"
	"encoding/json"
	"time"

	"github.com/jmoiron/sqlx"
)

type Cache struct {
	DB     *sqlx.DB
	Prefix string
}

func (c *Cache) key(k string) string {
	if c == nil || c.Prefix == "" {
		return k
	}
	return c.Prefix + ":" + k
}

func (c *Cache) db() *sqlx.DB {
	if c == nil {
		return nil
	}
	return c.DB
}

func (c *Cache) GetJSON(ctx context.Context, k string, v any) bool {
	db := c.db()
	if db == nil {
		return false
	}
	var raw string
	err := db.GetContext(ctx, &raw, `SELECT value FROM go_cache
		WHERE key = ? AND (expires_at IS NULL OR expires_at > CURRENT_TIMESTAMP)`, c.key(k))
	if err != nil || json.Unmarshal([]byte(raw), v) != nil {
		return false
	}
	return true
}

func (c *Cache) SetJSON(ctx context.Context, k string, v any, ttl time.Duration) {
	db := c.db()
	if db == nil {
		return
	}
	raw, err := json.Marshal(v)
	if err != nil {
		return
	}
	var expires any
	if ttl > 0 {
		expires = time.Now().UTC().Add(ttl).Format("2006-01-02 15:04:05")
	} else {
		expires = nil
	}
	_, _ = db.ExecContext(ctx, `INSERT INTO go_cache (key, value, expires_at)
		VALUES (?, ?, ?)
		ON CONFLICT(key) DO UPDATE SET value = excluded.value, expires_at = excluded.expires_at`,
		c.key(k), string(raw), expires)
}

func (c *Cache) Del(ctx context.Context, keys ...string) {
	db := c.db()
	if db == nil || len(keys) == 0 {
		return
	}
	ks := make([]string, 0, len(keys))
	for _, k := range keys {
		ks = append(ks, c.key(k))
	}
	q, args, err := sqlx.In(`DELETE FROM go_cache WHERE key IN (?)`, ks)
	if err != nil {
		return
	}
	_, _ = db.ExecContext(ctx, db.Rebind(q), args...)
}

func (c *Cache) Has(ctx context.Context, k string) bool {
	db := c.db()
	if db == nil {
		return false
	}
	var one int
	err := db.GetContext(ctx, &one, `SELECT 1 FROM go_cache
		WHERE key = ? AND (expires_at IS NULL OR expires_at > CURRENT_TIMESTAMP)`, c.key(k))
	return err == nil
}
