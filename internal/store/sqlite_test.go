package store

import (
	"context"
	"database/sql"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/sudo-adduser-jordan/eveindustry/migrations"
)

func applyMigrations(t *testing.T) *SQL {
	t.Helper()
	entries, err := migrations.FS.ReadDir(".")
	if err != nil {
		t.Fatal(err)
	}
	db, err := Connect(filepath.Join(t.TempDir(), "test.sqlite"))
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { _ = db.DB.Close() })
	var ups []string
	for _, e := range entries {
		if strings.HasSuffix(e.Name(), ".up.sql") {
			ups = append(ups, e.Name())
		}
	}
	for i := 0; i < len(ups); i++ {
		for j := i + 1; j < len(ups); j++ {
			if ups[j] < ups[i] {
				ups[i], ups[j] = ups[j], ups[i]
			}
		}
	}
	for _, name := range ups {
		raw, err := migrations.FS.ReadFile(name)
		if err != nil {
			t.Fatal(err)
		}
		for _, stmt := range strings.Split(string(raw), ";\n") {
			stmt = strings.TrimSpace(stmt)
			if stmt == "" {
				continue
			}
			if _, err := db.DB.Exec(stmt); err != nil {
				t.Fatalf("%s: %v\n%s", name, err, stmt)
			}
		}
	}
	return NewSQL(db.DB)
}

func nullStr(s string) sql.NullString { return sql.NullString{String: s, Valid: true} }
func int64ptr(v int64) *int64         { return &v }

func TestSQLiteUpsertPaths(t *testing.T) {
	s := applyMigrations(t)
	ctx := context.Background()
	now := time.Now().UTC().Truncate(time.Second)

	uid, err := s.EnsureDemoUser(ctx, "demo@eve-industrialist.invalid")
	if err != nil || uid == 0 {
		t.Fatalf("EnsureDemoUser: %v %d", err, uid)
	}

	md := EndpointMetricDelta{DimensionHash: "h1", BucketAt: now.Truncate(time.Hour),
		Operation: "op", EventKind: "http_attempt", HTTPStatus: 200, Reason: "ok",
		Requests: 2, Successes: 2, DurationCount: 2, DurationMax: 50, FirstSeen: now, LastSeen: now}
	if err := s.MergeEndpointMetric(ctx, md); err != nil {
		t.Fatalf("MergeEndpointMetric insert: %v", err)
	}
	md.Requests, md.Successes, md.DurationMax, md.DurationCount = 3, 1, 70, 1
	md.FirstSeen, md.LastSeen = now.Add(-time.Hour), now.Add(time.Hour)
	if err := s.MergeEndpointMetric(ctx, md); err != nil {
		t.Fatalf("MergeEndpointMetric merge: %v", err)
	}
	var sum struct {
		Req   int64  `db:"request_count"`
		Max   int    `db:"duration_max_ms"`
		First string `db:"first_observed_at"`
		Last  string `db:"last_observed_at"`
	}
	if err := s.db.GetContext(ctx, &sum, `SELECT request_count, duration_max_ms,
		first_observed_at, last_observed_at FROM esi_endpoint_metrics WHERE dimension_hash = 'h1'`); err != nil {
		t.Fatal(err)
	}
	if sum.Req != 5 || sum.Max != 70 {
		t.Fatalf("merged endpoint: %+v", sum)
	}
	if sum.First > sum.Last {
		t.Fatalf("min/max flipped: %+v", sum)
	}

	bd := BudgetMetricDelta{DimensionHash: "b1", BucketAt: now.Truncate(time.Hour),
		Granularity: "minute", Mechanism: "token", Observations: 5, UsedSum: int64ptr(3)}
	if err := s.MergeBudgetMetric(ctx, bd); err != nil {
		t.Fatalf("MergeBudgetMetric insert: %v", err)
	}
	bd.Observations, bd.RemainingMin, bd.RemainingMax = 7, int64ptr(10), int64ptr(20)
	if err := s.MergeBudgetMetric(ctx, bd); err != nil {
		t.Fatalf("MergeBudgetMetric merge: %v", err)
	}
}

func TestSQLiteSweepLifecycle(t *testing.T) {
	s := applyMigrations(t)
	ctx := context.Background()
	now := time.Now().UTC().Truncate(time.Second)

	sw, err := s.StartSweep(ctx, 10000002, now, time.Hour)
	if err != nil || sw == nil {
		t.Fatalf("StartSweep: %v %+v", err, sw)
	}
	if again, err := s.StartSweep(ctx, 10000002, now, time.Hour); err != nil || again != nil {
		t.Fatalf("StartSweep dup while running: %v %+v", err, again)
	}
	if err := s.SetPagesTotal(ctx, sw.SweepID, 1); err != nil {
		t.Fatalf("SetPagesTotal: %v", err)
	}
	issued := now.Add(-time.Hour)
	orders := []SweepOrder{
		{OrderID: 1, RegionID: 10000002, TypeID: 34, Price: 10.5, VolumeRemain: 100, VolumeTotal: 100, MinVolume: 1, Issued: &issued},
		{OrderID: 2, RegionID: 10000002, TypeID: 35, IsBuy: true, Price: 9.5, VolumeRemain: 50, VolumeTotal: 50, MinVolume: 1},
	}
	if err := s.StoreSweepOrders(ctx, sw.SweepID, 10000002, orders); err != nil {
		t.Fatalf("StoreSweepOrders: %v", err)
	}
	if err := s.StoreSweepOrders(ctx, sw.SweepID, 10000002, orders); err != nil {
		t.Fatalf("StoreSweepOrders restate: %v", err)
	}
	var stored int
	if err := s.db.GetContext(ctx, &stored, `SELECT COUNT(*) FROM market_orders WHERE region_id = 10000002`); err != nil || stored != 2 {
		t.Fatalf("orders stored: %v %d", err, stored)
	}
	if err := s.MarkSweepOrdersSeen(ctx, sw.SweepID, 10000002, []int64{1, 2}); err != nil {
		t.Fatalf("MarkSweepOrdersSeen: %v", err)
	}
	if err := s.IncrementSweep(ctx, sw.SweepID, 1, 0, 2); err != nil {
		t.Fatalf("IncrementSweep: %v", err)
	}
	if _, err := s.ClaimCompletion(ctx, sw.SweepID); err != nil {
		t.Fatalf("ClaimCompletion: %v", err)
	}
	finished := now.Add(65 * time.Second)
	fin, err := s.FinishSweep(ctx, sw.SweepID, finished, 0, 2)
	if err != nil || fin == nil {
		t.Fatalf("FinishSweep: %v %+v", err, fin)
	}
	if !fin.DurationSeconds.Valid || fin.DurationSeconds.Int64 != 65 {
		t.Fatalf("duration_seconds: %+v", fin.DurationSeconds)
	}
	ok, err := s.PublishActiveSnapshot(ctx, 10000002, sw.SweepID, finished)
	if err != nil || !ok {
		t.Fatalf("PublishActiveSnapshot: %v %v", err, ok)
	}
	if _, err := s.db.ExecContext(ctx, `UPDATE market_orders SET updated_at = datetime('now', '-1 day') WHERE region_id = 10000002`); err != nil {
		t.Fatal(err)
	}
	deleted, err := s.DeleteUnseenOrders(ctx, 10000002, now)
	if err != nil || deleted != 2 {
		t.Fatalf("DeleteUnseenOrders: %v %d", err, deleted)
	}
}

func TestSQLiteJSONEach(t *testing.T) {
	s := applyMigrations(t)
	ctx := context.Background()

	if _, err := s.db.ExecContext(ctx, `INSERT INTO market_groups
		(market_group_id, name, parent_group_id, has_types) VALUES (100, 'G', NULL, 1)`); err != nil {
		t.Fatal(err)
	}
	if _, err := s.db.ExecContext(ctx, `INSERT INTO eve_types
		(type_id, name, market_group_id, published) VALUES (34, 'Tritanium', 100, 1)`); err != nil {
		t.Fatal(err)
	}
	if _, err := s.db.ExecContext(ctx, `INSERT INTO blueprint_activities
		(blueprint_type_id, activity_id, products) VALUES (35, 1, '[{"type_id": 34, "quantity": 100}]')`); err != nil {
		t.Fatal(err)
	}
	groups, err := s.ManufacturableGroupIDs(ctx)
	if err != nil {
		t.Fatal(err)
	}
	if !groups[100] {
		t.Fatalf("manufacturable groups: %v, want group 100", groups)
	}
}

func TestSQLiteContractPublish(t *testing.T) {
	s := applyMigrations(t)
	ctx := context.Background()

	gen, err := s.CreateContractGeneration(ctx)
	if err != nil || gen == 0 {
		t.Fatalf("CreateContractGeneration: %v %d", err, gen)
	}
	pub, err := s.PublishContractGeneration(ctx, gen)
	if err != nil || !pub {
		t.Fatalf("PublishContractGeneration: %v %v", err, pub)
	}
	if pub, err := s.PublishContractGeneration(ctx, gen); err != nil || pub {
		t.Fatalf("PublishContractGeneration restate: %v %v", err, pub)
	}
	if n, err := s.PruneDiagTable(ctx, "esi_diagnostic_events", "occurred_at", nil, time.Now(), 500); err != nil || n != 0 {
		t.Fatalf("PruneDiagTable empty: %v %d", err, n)
	}
}
