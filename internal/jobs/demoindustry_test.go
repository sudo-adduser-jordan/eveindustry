package jobs

import (
	"context"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/sudo-adduser-jordan/eveindustry/internal/esi"
	"github.com/sudo-adduser-jordan/eveindustry/internal/industry"
	"github.com/sudo-adduser-jordan/eveindustry/internal/store"
	"github.com/sudo-adduser-jordan/eveindustry/migrations"
)

func demoTestDB(t *testing.T) (*store.SQL, *industry.SQL) {
	t.Helper()
	db, err := store.Connect(filepath.Join(t.TempDir(), "demo.sqlite"))
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { _ = db.DB.Close() })
	entries, err := migrations.FS.ReadDir(".")
	if err != nil {
		t.Fatal(err)
	}
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
	return store.NewSQL(db.DB), industry.NewSQL(db.DB)
}

func TestDemoIndustrySeedFillsPool(t *testing.T) {
	ctx := context.Background()
	s, ind := demoTestDB(t)
	if err := s.SeedDemoIndustry(ctx, buildDemoIndustrySeed()); err != nil {
		t.Fatal(err)
	}
	if err := s.SeedDemoIndustry(ctx, buildDemoIndustrySeed()); err != nil {
		t.Fatalf("reseed must be a no-op: %v", err)
	}
	pool, err := ind.DemoBlueprintPool(ctx)
	if err != nil {
		t.Fatal(err)
	}
	if len(pool) < 6 {
		t.Fatalf("pool = %d, want >= 6", len(pool))
	}
	rows, err := s.T1Blueprints(ctx, "", 0)
	if err != nil {
		t.Fatal(err)
	}
	if len(rows) < 6 {
		t.Fatalf("t1 blueprints = %d, want >= 6", len(rows))
	}
	formulas, err := s.ReactionFormulas(ctx, "", 0)
	if err != nil {
		t.Fatal(err)
	}
	if len(formulas) < 3 {
		t.Fatalf("formulas = %d, want >= 3", len(formulas))
	}
	now := time.Date(2026, 11, 8, 12, 0, 0, 0, time.UTC)
	ch := esi.DemoCharacter{ID: 99000000001, Name: "Demo One", CorporationID: 99100000001, CorpName: "Demo Corp"}
	r := &esi.DemoResponder{
		Chars: func(ctx context.Context) ([]esi.DemoCharacter, error) { return []esi.DemoCharacter{ch}, nil },
		Pool: func(ctx context.Context) ([][2]int, error) {
			return ind.DemoBlueprintPool(ctx)
		},
		Now: func() time.Time { return now },
	}
	_, jobsRaw, _ := r.Respond(ctx, "get", "/characters/99000000001/industry/jobs/", &ch, map[string]string{}, nil)
	jobs, _ := jobsRaw.([]any)
	active := 0
	for _, j := range jobs {
		if m, ok := j.(map[string]any); ok && m["status"] == "active" {
			active++
		}
	}
	if active == 0 {
		t.Fatalf("no active demo jobs in %+v", jobs)
	}
	_, bpsRaw, _ := r.Respond(ctx, "get", "/characters/99000000001/blueprints/", &ch, map[string]string{}, nil)
	bps, _ := bpsRaw.([]any)
	if len(bps) == 0 {
		t.Fatal("no demo blueprints")
	}
	seenFormula := false
	for _, b := range bps {
		if m, ok := b.(map[string]any); ok {
			if id, _ := m["type_id"].(int); id >= 99201 && id <= 99203 {
				if q, _ := m["quantity"].(int); q > 0 {
					seenFormula = true
				}
			}
		}
	}
	if !seenFormula {
		t.Fatal("demo blueprints lack an owned reaction formula")
	}
}
