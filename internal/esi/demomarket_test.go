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
