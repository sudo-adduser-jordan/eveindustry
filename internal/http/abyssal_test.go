package http

import (
	"context"
	"net/http"
	"testing"

	"github.com/sudo-adduser-jordan/eveindustry/internal/auth"
)

type stubAbyssChars struct {
	assets map[int64][]map[string]any
	dyn    map[int64]map[string]any
}

func (s *stubAbyssChars) GetAssets(ctx context.Context, id int64) ([]map[string]any, error) {
	return s.assets[id], nil
}
func (s *stubAbyssChars) GetCorporationAssets(ctx context.Context, charID, corpID int64) ([]map[string]any, error) {
	return nil, nil
}
func (s *stubAbyssChars) GetAssetNames(ctx context.Context, id int64, items []int64) (map[int64]string, error) {
	return map[int64]string{}, nil
}
func (s *stubAbyssChars) GetCorporationAssetNames(ctx context.Context, charID, corpID int64, items []int64) (map[int64]string, error) {
	return map[int64]string{}, nil
}
func (s *stubAbyssChars) GetDynamicItem(ctx context.Context, typeID int, itemID int64, charID int64) (map[string]any, error) {
	return s.dyn[itemID], nil
}

type stubAbyssUniverse struct {
	stubAssetUniverse
	dogma map[int]map[int]float64
	attrs map[int]map[string]any
	names map[int]string
}

func (s *stubAbyssUniverse) GetTypeInfo(ctx context.Context, id int) (map[string]any, error) {
	if n, ok := s.names[id]; ok {
		return map[string]any{"type_id": id, "name": n}, nil
	}
	return map[string]any{"type_id": id, "name": "Tritanium"}, nil
}

func (s *stubAbyssUniverse) GetDogmaAttribute(ctx context.Context, id int) (map[string]any, error) {
	return s.attrs[id], nil
}
func (s *stubAbyssUniverse) DogmaTypeAttributes(ctx context.Context, id int) (map[int]float64, error) {
	return s.dogma[id], nil
}

func abyssalTestDeps() Deps {
	stub := testAuth()
	stub.chars = []auth.Character{{
		CharacterID: 11, Name: "Main", IsMain: true, IsEsiActive: true,
		Scopes: []string{"esi-assets.read_assets.v1"},
	}}
	charsStub := &stubAbyssChars{
		assets: map[int64][]map[string]any{11: {
			{"item_id": 1, "type_id": 30000, "quantity": 1.0, "location_id": 60003760, "location_type": "station", "location_flag": "Hangar", "is_singleton": true},
			{"item_id": 2, "type_id": 34, "quantity": 10.0, "location_id": 60003760, "location_type": "station", "location_flag": "Hangar"},
		}},
		dyn: map[int64]map[string]any{1: {
			"source_type_id": 587, "mutator_type_id": 41299,
			"dogma_attributes": []any{
				map[string]any{"attribute_id": 100.0, "value": 1.2},
				map[string]any{"attribute_id": 422.0, "value": 1.0},
			},
		}},
	}
	d := Deps{Sessions: stub, Users: stub,
		Abyssal: AbyssalDeps{
			Characters: charsStub,
			Reference:  &stubAssetRef{},
			Universe: &stubAbyssUniverse{
				names: map[int]string{587: "Rifter", 30000: "Mutated Rifter", 34: "Tritanium"},
				dogma: map[int]map[int]float64{587: {100: 1.0}},
				attrs: map[int]map[string]any{100: {"name": "dmg", "display_name": "Damage", "high_is_good": true, "unit_id": 109}},
			},
			Dogma: charsStub,
		},
		Industry: &stubIndustry{
			names:  map[int]string{34: "Tritanium", 587: "Rifter", 30000: "Mutated Rifter"},
			groups: map[int]int{34: 18},
		},
		Market: &stubMarket{},
	}
	// TypeGroupMeta stub: mark 30000 as abyssal (meta group 15)
	d.Industry.(*stubIndustry).metaGroups = map[int]int64{30000: 15}
	return d
}

func TestAbyssal(t *testing.T) {
	d := abyssalTestDeps()
	code, body := get(t, d, "/api/v1/assets/abyssal")
	if code != http.StatusOK {
		t.Fatalf("abyssal = %d %v", code, body)
	}
	items := body["data"].([]any)
	if len(items) != 1 {
		t.Fatalf("groups = %v", items)
	}
	g := items[0].(map[string]any)
	if g["source_type_name"] != "Rifter" {
		t.Fatalf("group = %v", g)
	}
	it := g["items"].([]any)[0].(map[string]any)
	if it["score"] != 20.0 {
		t.Fatalf("score = %v", it)
	}
	attrs := it["attributes"].([]any)
	if len(attrs) != 1 {
		t.Fatalf("attrs = %v", attrs)
	}
	a := attrs[0].(map[string]any)
	if a["name"] != "Damage" || a["value"] != "1.2000x" || a["change"] != "+0.2000x" || a["is_good"] != true {
		t.Fatalf("attr = %v", a)
	}
	// no abyssal types -> empty
	d2 := abyssalTestDeps()
	d2.Industry.(*stubIndustry).metaGroups = map[int]int64{}
	code, body = get(t, d2, "/api/v1/assets/abyssal")
	if code != http.StatusOK || len(body["data"].([]any)) != 0 {
		t.Fatalf("empty = %d %v", code, body)
	}
}
