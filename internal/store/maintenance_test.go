package store

import (
	"context"
	"reflect"
	"testing"

	"github.com/sudo-adduser-jordan/eveindustry/internal/esi"
)

// Demo characters carry the full demo scope set so scope-gated tabs (assets,
// industry) serve demo data instead of rendering empty.
func TestDemoCharacterScopes(t *testing.T) {
	s := applyMigrations(t)
	ctx := context.Background()
	uid, err := s.EnsureDemoUser(ctx, "demo@eve-industrialist.invalid")
	if err != nil || uid == 0 {
		t.Fatalf("EnsureDemoUser: %v %d", err, uid)
	}
	demo := DemoCharacter{
		CharacterID: 99000000001, UserID: uid, Name: "Demo One",
		CorporationID: 99100000001, CorpName: "Demo Corp",
		IsMain: true, IsDirectorCEO: true, Roles: []string{"Director"},
		CachedLoc: "Jita", CachedTrain: "Advanced Mass Reactions 4",
	}
	if err := s.CreateDemoCharacter(ctx, demo); err != nil {
		t.Fatalf("CreateDemoCharacter: %v", err)
	}
	chars, err := s.CharactersForUser(ctx, uid)
	if err != nil || len(chars) != 1 {
		t.Fatalf("CharactersForUser: %v %d", err, len(chars))
	}
	if !reflect.DeepEqual(chars[0].Scopes, esi.DemoScopes) {
		t.Fatalf("scopes = %v, want demo scopes", chars[0].Scopes)
	}

	// Resets predating the scope fix left characters scopeless; touching the
	// demo account backfills them so the tabs recover before the next reset.
	if _, err := s.db.ExecContext(ctx, `UPDATE characters SET scopes = '[]' WHERE user_id = ?`, uid); err != nil {
		t.Fatal(err)
	}
	if err := s.TouchDemoUser(ctx, uid); err != nil {
		t.Fatalf("TouchDemoUser: %v", err)
	}
	chars, err = s.CharactersForUser(ctx, uid)
	if err != nil || len(chars) != 1 {
		t.Fatalf("CharactersForUser after touch: %v %d", err, len(chars))
	}
	if !reflect.DeepEqual(chars[0].Scopes, esi.DemoScopes) {
		t.Fatalf("backfilled scopes = %v, want demo scopes", chars[0].Scopes)
	}
}
