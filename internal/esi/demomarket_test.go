package esi

import (
	"context"
	"net/http"
	"testing"
	"time"
)

func demoResponder() *DemoResponder {
	now := time.Date(2026, 11, 8, 12, 0, 0, 0, time.UTC)
	ch := DemoCharacter{ID: 99000000001, Name: "Demo One", CorporationID: 99100000001,
		CorpName: "Demo Corp", AllianceID: 99200000001, AllianceName: "Demo Alliance",
		Roles: []string{"Director"}, CreatedAt: now.Add(-30 * 24 * time.Hour)}
	return &DemoResponder{
		Chars: func(ctx context.Context) ([]DemoCharacter, error) { return []DemoCharacter{ch}, nil },
		Pool:  func(ctx context.Context) ([][2]int, error) { return [][2]int{{786, 587}}, nil },
		Now:   func() time.Time { return now },
	}
}

func TestDemoHandles(t *testing.T) {
	if !DemoHandles("/characters/99000000001/assets/") {
		t.Fatal("char endpoint")
	}
	if !DemoHandles("/corporations/99100000001/assets/") {
		t.Fatal("corp endpoint")
	}
	if !DemoHandles("/universe/planets/99250000001/") {
		t.Fatal("planet endpoint")
	}
	if DemoHandles("/characters/12345/assets/") {
		t.Fatal("real id")
	}
	if DemoHandles("/markets/prices/") {
		t.Fatal("non-demo endpoint")
	}
}

func TestDemoCharacterEndpoints(t *testing.T) {
	r := demoResponder()
	ctx := context.Background()
	ch := DemoCharacter{ID: 99000000001, Name: "Demo One", CorporationID: 99100000001,
		CorpName: "Demo Corp", Roles: []string{"Director"}}
	st, body, _ := r.Respond(ctx, "get", "/characters/99000000001/", &ch, nil, nil)
	if st != 200 || body.(map[string]any)["name"] != "Demo One" {
		t.Fatalf("profile = %d %v", st, body)
	}
	st, body, _ = r.Respond(ctx, "get", "/characters/99000000001/skills/", &ch, nil, nil)
	if st != 200 {
		t.Fatalf("skills = %d", st)
	}
	st, body, _ = r.Respond(ctx, "get", "/characters/99000000001/assets/", &ch, nil, map[string]string{})
	if st != 200 || len(body.([]any)) == 0 {
		t.Fatalf("assets = %d %v", st, body)
	}
	st, body, _ = r.Respond(ctx, "get", "/characters/99000000001/planets/", &ch, nil, nil)
	if st != 200 || len(body.([]any)) != 3 {
		t.Fatalf("planets = %d %v", st, body)
	}
	st, body, _ = r.Respond(ctx, "get", "/characters/99000000001/location/", &ch, nil, nil)
	if st != 200 || body.(map[string]any)["solar_system_id"] != 30002187 {
		t.Fatalf("location = %d %v", st, body)
	}
	st, body, _ = r.Respond(ctx, "get", "/characters/99000000001/industry/jobs/", &ch, map[string]string{}, nil)
	if st != 200 {
		t.Fatalf("jobs = %d", st)
	}
	st, _, _ = r.Respond(ctx, "get", "/characters/99000000001/unknown/", &ch, nil, nil)
	if st != 404 {
		t.Fatalf("unknown = %d", st)
	}
}

func TestDemoCorporationEndpoints(t *testing.T) {
	r := demoResponder()
	ctx := context.Background()
	st, body, _ := r.Respond(ctx, "get", "/corporations/99100000001/", nil, nil, nil)
	if st != 200 || body.(map[string]any)["ticker"] != "DEMO" {
		t.Fatalf("corp = %d %v", st, body)
	}
	st, body, _ = r.Respond(ctx, "get", "/corporations/99100000001/wallets/", nil, nil, nil)
	if st != 200 || len(body.([]any)) != 7 {
		t.Fatalf("wallets = %d %v", st, body)
	}
	st, _, _ = r.Respond(ctx, "get", "/corporations/123/", nil, nil, nil)
	if st != 404 {
		t.Fatalf("missing corp = %d", st)
	}
	st, body, _ = r.Respond(ctx, "post", "/characters/affiliation/", nil, nil, []any{float64(99000000001)})
	if st != 200 || len(body.([]any)) != 1 {
		t.Fatalf("affiliation = %d %v", st, body)
	}
	st, body, _ = r.Respond(ctx, "get", "/corporations/99100000001/assets/", nil, map[string]string{}, nil)
	items, ok := body.([]any)
	if st != 200 || !ok || len(items) == 0 {
		t.Fatalf("corp assets = %d %v", st, body)
	}
	seenFlags := map[string]bool{}
	seenIDs := map[int64]bool{}
	for _, it := range items {
		m, ok := it.(map[string]any)
		if !ok {
			t.Fatalf("corp asset shape = %v", it)
		}
		flag, _ := m["location_flag"].(string)
		seenFlags[flag] = true
		id, ok := m["item_id"].(int64)
		if !ok {
			t.Fatalf("corp asset item_id = %v", m["item_id"])
		}
		if seenIDs[id] {
			t.Fatalf("duplicate corp item_id %v", id)
		}
		seenIDs[id] = true
	}
	if !seenFlags["CorpSAG1"] {
		t.Fatalf("corp flags = %v, want corp hangar flags", seenFlags)
	}
}

func TestDemoPaginatedClientPaths(t *testing.T) {
	r := demoResponder()
	cc := NewCharacterClient(nil, nil, &stubCreds{}, nil, nil)
	cc.Demo = r
	ctx := context.Background()
	got, err := cc.GetAssets(ctx, 99000000001)
	if err != nil || len(got) == 0 {
		t.Fatalf("GetAssets demo = %d %v", len(got), err)
	}
	corp, err := cc.GetCorporationAssets(ctx, 99000000001, 99100000001)
	if err != nil || len(corp) == 0 {
		t.Fatalf("GetCorporationAssets demo = %d %v", len(corp), err)
	}
	personalIDs := map[int64]bool{}
	for _, a := range got {
		id, _ := a["item_id"].(int64)
		personalIDs[id] = true
	}
	for _, a := range corp {
		id, _ := a["item_id"].(int64)
		if personalIDs[id] {
			t.Fatalf("corp item_id %d collides with personal assets", id)
		}
		if _, ok := a["location_flag"].(string); !ok {
			t.Fatalf("corp asset flag = %v", a)
		}
	}
	if _, err := cc.GetAssets(ctx, 12345); err == nil {
		t.Fatal("real id must not resolve through the demo responder")
	}
}

func TestRouteGroups(t *testing.T) {
	if routeGroups["get /characters/{character_id}/assets"] != "char-asset" {
		t.Fatal("asset group")
	}
	if routeGroups["get /markets/{region_id}/orders/"] == "" && routeGroups["get /markets/{region_id}/orders"] == "" {
		t.Fatal("orders group missing")
	}
	// placeholder-insensitive matching is in resolveGroup; table sanity:
	n := 0
	for k := range routeGroups {
		if k == "" {
			t.Fatal("empty key")
		}
		n++
	}
	if n < 100 {
		t.Fatalf("only %d routes", n)
	}
}

func TestMarketClientPages(t *testing.T) {
	c := NewClient(NewMemStore(), NewLimiter(NewMemStore(), "t"), NewCircuit(NewMemStore()), "test")
	if c.Cache == nil {
		// Cache is opt-in; client works without it.
	}
	mc := NewMarketClient(c, NewMemo())
	_ = mc
	var _ = http.MethodGet
}
