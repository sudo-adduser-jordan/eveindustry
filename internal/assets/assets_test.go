package assets

import (
	"context"
	"strings"
	"testing"
	"time"

	"github.com/sudo-adduser-jordan/eveindustry/internal/auth"
	"github.com/sudo-adduser-jordan/eveindustry/internal/esi"
)

type stubIndustry struct{ names map[int]string }

func (s stubIndustry) TypeNames(ctx context.Context, ids []int) (map[int]string, error) {
	out := map[int]string{}
	for _, id := range ids {
		if n, ok := s.names[id]; ok {
			out[id] = n
		}
	}
	return out, nil
}

func TestFetchUserAssetsDemo(t *testing.T) {
	now := time.Date(2026, 11, 8, 12, 0, 0, 0, time.UTC)
	mkChar := func(id int64, name string, main bool) esi.DemoCharacter {
		return esi.DemoCharacter{ID: id, Name: name, CorporationID: 99100000001,
			CorpName: "Demo Corp", AllianceID: 99200000001, AllianceName: "Demo Alliance",
			Roles: []string{"Director"}, CreatedAt: now.Add(-30 * 24 * time.Hour)}
	}
	demoChars := []esi.DemoCharacter{
		mkChar(99000000001, "Demo One", true),
		mkChar(99000000002, "Demo Two", false),
		mkChar(99000000003, "Demo Three", false),
	}
	responder := &esi.DemoResponder{
		Chars: func(ctx context.Context) ([]esi.DemoCharacter, error) { return demoChars, nil },
		Pool:  func(ctx context.Context) ([][2]int, error) { return [][2]int{{786, 587}}, nil },
		Now:   func() time.Time { return now },
	}
	client := esi.NewCharacterClient(nil, nil, nil, nil, nil)
	client.Demo = responder

	corpID := int64(99100000001)
	corpName := "Demo Corp"
	chars := []auth.Character{
		{CharacterID: 99000000001, Name: "Demo One", CorporationID: &corpID, CorporationName: &corpName,
			IsMain: true, IsEsiActive: true, IsDirectorCEO: true, Scopes: esi.DemoScopes},
		{CharacterID: 99000000002, Name: "Demo Two", CorporationID: &corpID, CorporationName: &corpName,
			IsEsiActive: true, Scopes: esi.DemoScopes},
		{CharacterID: 99000000003, Name: "Demo Three", CorporationID: &corpID, CorporationName: &corpName,
			IsEsiActive: true, Scopes: esi.DemoScopes},
	}
	if denial := CorpDenial(chars); denial != nil {
		t.Fatalf("demo corp gate denied: %s", *denial)
	}
	d := Deps{Characters: client, Industry: stubIndustry{names: map[int]string{34: "Tritanium", 587: "Rifter"}}}
	all := FetchUserAssets(context.Background(), d, chars, true)
	if len(all) == 0 {
		t.Fatal("demo fetches no assets")
	}
	var personal, corporate int
	var corpFlagged bool
	for _, a := range all {
		switch a.Owner {
		case "personal":
			personal++
		case "corporate":
			corporate++
			if strings.HasPrefix(a.Flag, "Corp Hangar") {
				corpFlagged = true
			}
		default:
			t.Fatalf("owner = %q", a.Owner)
		}
		if a.TypeName == "" || a.Location == "" {
			t.Fatalf("unenriched asset = %+v", a)
		}
	}
	if personal == 0 {
		t.Fatal("no personal demo assets")
	}
	if corporate == 0 || !corpFlagged {
		t.Fatalf("corporate = %d flagged = %v", corporate, corpFlagged)
	}
}
