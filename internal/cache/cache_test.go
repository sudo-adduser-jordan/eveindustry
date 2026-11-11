package cache

import (
	"context"
	"testing"
	"time"

	"github.com/jmoiron/sqlx"
	_ "modernc.org/sqlite"
)

const cacheTestDDL = `
CREATE TABLE go_cache (
  key TEXT NOT NULL,
  value TEXT NOT NULL,
  expires_at TEXT NULL,
  PRIMARY KEY (key)
);`

func TestSQLiteCache(t *testing.T) {
	db, err := sqlx.Connect("sqlite", ":memory:?_pragma=busy_timeout(5000)")
	if err != nil {
		t.Fatal(err)
	}
	defer func() { _ = db.Close() }()
	if _, err := db.Exec(cacheTestDDL); err != nil {
		t.Fatal(err)
	}
	c := &Cache{DB: db}
	ctx := context.Background()

	var v map[string]int
	if c.GetJSON(ctx, "k", &v) {
		t.Fatal("miss must be false")
	}
	c.SetJSON(ctx, "k", map[string]int{"a": 1}, time.Minute)
	if !c.GetJSON(ctx, "k", &v) || v["a"] != 1 {
		t.Fatalf("hit: %+v", v)
	}
	if !c.Has(ctx, "k") {
		t.Fatal("Has must be true")
	}
	c.SetJSON(ctx, "k", map[string]int{"a": 2}, time.Minute)
	if !c.GetJSON(ctx, "k", &v) || v["a"] != 2 {
		t.Fatalf("overwrite: %+v", v)
	}
	c.SetJSON(ctx, "forever", "x", 0)
	c.Del(ctx, "k")
	if c.GetJSON(ctx, "k", &v) || c.Has(ctx, "k") {
		t.Fatal("deleted key must miss")
	}
	var s string
	if !c.GetJSON(ctx, "forever", &s) || s != "x" {
		t.Fatal("forever key must hit")
	}
	c.SetJSON(ctx, "brief", "y", 100*time.Millisecond)
	<-time.After(1200 * time.Millisecond)
	var y string
	if c.GetJSON(ctx, "brief", &y) {
		t.Fatal("expired key must miss")
	}
	var nilC *Cache
	nilC.SetJSON(ctx, "k", 1, time.Minute)
	if nilC.GetJSON(ctx, "k", &v) || nilC.Has(ctx, "k") {
		t.Fatal("nil cache must miss")
	}
	nilC.Del(ctx, "k")
}
