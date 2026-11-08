package cache

import (
	"context"
	"encoding/json"
	"time"

	"github.com/redis/go-redis/v9"
)

// Cache is a JSON-over-Redis helper mirroring the Cache::remember/forever
// shapes the refresh jobs and warm reads use. A nil client disables caching
// (every read misses, writes drop) so tests and DB-only runs stay live.
type Cache struct {
	Client *redis.Client
	Prefix string
}

func (c *Cache) key(k string) string {
	if c == nil || c.Prefix == "" {
		return k
	}
	return c.Prefix + ":" + k
}

func (c *Cache) client() *redis.Client {
	if c == nil {
		return nil
	}
	return c.Client
}

// GetJSON loads and decodes a key. False on miss/expiry/decoding failure.
func (c *Cache) GetJSON(ctx context.Context, k string, v any) bool {
	cl := c.client()
	if cl == nil {
		return false
	}
	raw, err := cl.Get(ctx, c.key(k)).Bytes()
	if err != nil || json.Unmarshal(raw, v) != nil {
		return false
	}
	return true
}

// SetJSON stores a key with TTL (<=0 means keep forever, like Cache::forever).
func (c *Cache) SetJSON(ctx context.Context, k string, v any, ttl time.Duration) {
	cl := c.client()
	if cl == nil {
		return
	}
	raw, err := json.Marshal(v)
	if err != nil {
		return
	}
	if ttl <= 0 {
		_ = cl.Set(ctx, c.key(k), raw, 0).Err()
		return
	}
	_ = cl.Set(ctx, c.key(k), raw, ttl).Err()
}

// Del drops keys.
func (c *Cache) Del(ctx context.Context, keys ...string) {
	cl := c.client()
	if cl == nil {
		return
	}
	ks := make([]string, 0, len(keys))
	for _, k := range keys {
		ks = append(ks, c.key(k))
	}
	_ = cl.Del(ctx, ks...).Err()
}

// Has reports key existence.
func (c *Cache) Has(ctx context.Context, k string) bool {
	cl := c.client()
	if cl == nil {
		return false
	}
	n, err := cl.Exists(ctx, c.key(k)).Result()
	return err == nil && n > 0
}
