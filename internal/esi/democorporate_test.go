package esi

import (
	"context"
	"testing"
	"time"
)

func TestDemoCorporateTabs(t *testing.T) {
	now := time.Date(2026, 11, 8, 12, 0, 0, 0, time.UTC)
	r := demoResponder()
	r.Now = func() time.Time { return now }
	ctx := context.Background()

	st, body, _ := r.Respond(ctx, "get", "/corporations/99100000001/wallets/1/journal/", nil, map[string]string{}, nil)
	if st != 200 {
		t.Fatalf("journal = %d", st)
	}
	found := false
	for _, item := range body.([]any) {
		if m, ok := item.(map[string]any); ok && m["ref_type"] == "player_donation" {
			found = true
		}
	}
	if !found {
		t.Fatal("no player_donation entries")
	}

	cc := NewCharacterClient(nil, nil, &stubCreds{}, nil, nil)
	cc.Demo = r
	projs, err := cc.GetCorporationProjects(ctx, 99000000001, 99100000001)
	if err != nil || len(projs) < 3 {
		t.Fatalf("projects = %d %v", len(projs), err)
	}
	contribs, err := cc.GetCorporationProjectContributors(ctx, 99000000001, 99100000001, "demo-p1")
	if err != nil || len(contribs) == 0 {
		t.Fatalf("contributors = %d %v", len(contribs), err)
	}
	name, err := cc.GetCharacter(ctx, 99000000001)
	if err != nil || name["name"] != "Demo One" {
		t.Fatalf("character = %v %v", name, err)
	}
}
