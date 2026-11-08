package http

import (
	"context"
	"net/http"
	"testing"

	"github.com/sudo-adduser-jordan/eveindustry/internal/auth"
	"github.com/sudo-adduser-jordan/eveindustry/internal/store"
)

type stubAssetChars struct {
	assets     map[int64][]map[string]any
	corpAssets map[int64][]map[string]any
}

func (s *stubAssetChars) GetAssets(ctx context.Context, id int64) ([]map[string]any, error) {
	return s.assets[id], nil
}
func (s *stubAssetChars) GetCorporationAssets(ctx context.Context, charID, corpID int64) ([]map[string]any, error) {
	return s.corpAssets[corpID], nil
}
func (s *stubAssetChars) GetAssetNames(ctx context.Context, id int64, items []int64) (map[int64]string, error) {
	return map[int64]string{}, nil
}
func (s *stubAssetChars) GetCorporationAssetNames(ctx context.Context, charID, corpID int64, items []int64) (map[int64]string, error) {
	return map[int64]string{}, nil
}

type stubAssetUniverse struct{}

func (s *stubAssetUniverse) GetTypeInfo(ctx context.Context, id int) (map[string]any, error) {
	return map[string]any{"type_id": id, "name": "Tritanium"}, nil
}
func (s *stubAssetUniverse) GetBestTypeImageUrl(ctx context.Context, id int, bp bool) string {
	return ""
}
func (s *stubAssetUniverse) GetStation(ctx context.Context, id uint64) (map[string]any, error) {
	return nil, nil
}
func (s *stubAssetUniverse) GetSystem(ctx context.Context, id int) (map[string]any, error) {
	return nil, nil
}
func (s *stubAssetUniverse) GetStructure(ctx context.Context, id uint64, char int64) (map[string]any, error) {
	return nil, nil
}

type stubAssetRef struct{ store.ReferenceStore }

func (s *stubAssetRef) FindStation(ctx context.Context, id uint64) (*store.Station, error) {
	if id == 60003760 {
		return &store.Station{StationID: id, StationName: "Jita IV", SolarSystemID: sqlNullInt64(30000142)}, nil
	}
	return nil, nil
}
func (s *stubAssetRef) FindSystem(ctx context.Context, id int) (*store.SolarSystem, error) {
	if id == 30000142 {
		return &store.SolarSystem{SolarSystemID: id, SolarSystemName: "Jita"}, nil
	}
	return nil, nil
}

func assetBrowserTestDeps(corpMain bool) Deps {
	stub := testAuth()
	corpID := int64(2001)
	main := auth.Character{CharacterID: 11, Name: "Main", IsMain: true, IsEsiActive: true,
		CorporationID: &corpID, CorporationName: strPtr("ACME"),
		Scopes: []string{"esi-assets.read_assets.v1"}}
	if corpMain {
		main.IsDirectorCEO = true
		main.Scopes = append(main.Scopes, "esi-assets.read_corporation_assets.v1")
	}
	stub.chars = []auth.Character{main,
		{CharacterID: 12, Name: "Alt", IsEsiActive: true, Scopes: []string{"esi-assets.read_assets.v1"}}}
	return Deps{Sessions: stub, Users: stub,
		AssetBrowser: AssetBrowserDeps{
			Characters: &stubAssetChars{
				assets: map[int64][]map[string]any{
					11: {
						{"item_id": 1, "type_id": 34, "quantity": 100.0, "location_id": 60003760, "location_type": "station", "location_flag": "Hangar"},
						{"item_id": 2, "type_id": 587, "quantity": 1.0, "location_id": 60003760, "location_type": "station", "location_flag": "Hangar", "is_singleton": true},
						{"item_id": 3, "type_id": 34, "quantity": 50.0, "location_id": 2, "location_type": "item", "location_flag": "Cargo"},
					},
				},
				corpAssets: map[int64][]map[string]any{2001: {
					{"item_id": 9, "type_id": 34, "quantity": 500.0, "location_id": 60003760, "location_type": "station", "location_flag": "CorpSAG1"},
				}},
			},
			Universe:  &stubAssetUniverse{},
			Reference: &stubAssetRef{},
		},
		Industry: &stubIndustry{names: map[int]string{34: "Tritanium", 587: "Rifter"},
			groups: map[int]int{34: 18, 587: 25}},
		Market: &stubMarket{},
		Reference: &stubReference{types: []store.EveType{{TypeID: 34, Name: "Tritanium"}}},
	}
}

func TestAssetsIndex(t *testing.T) {
	d := assetBrowserTestDeps(false)
	code, body := get(t, d, "/api/v1/assets")
	if code != http.StatusOK {
		t.Fatalf("index = %d %v", code, body)
	}
	items := body["data"].([]any)
	// top-level: Tritanium stack + Rifter; 50 Trit nested in the Rifter
	if len(items) != 2 {
		t.Fatalf("items = %v", items)
	}
	var rifter map[string]any
	for _, it := range items {
		if it.(map[string]any)["type_id"] == 587.0 {
			rifter = it.(map[string]any)
		}
	}
	if rifter == nil || len(rifter["children"].([]any)) != 1 {
		t.Fatalf("rifter = %v", rifter)
	}
	if rifter["location_name"] != "Jita IV" || rifter["type_name"] != "Rifter" {
		t.Fatalf("enrich = %v", rifter)
	}
	// search narrows to matching parent + matching children
	code, body = get(t, d, "/api/v1/assets?search=tritanium")
	if code != http.StatusOK || len(body["data"].([]any)) != 2 {
		t.Fatalf("search = %d %v", code, body)
	}
	// corporate without director -> 403 with reason
	if code, body := get(t, d, "/api/v1/corporation/assets"); code != http.StatusForbidden {
		t.Fatalf("corp gate = %d %v", code, body)
	}
	// location filter
	code, body = get(t, d, "/api/v1/assets?location_id=60003760")
	if code != http.StatusOK || len(body["data"].([]any)) != 2 {
		t.Fatalf("loc filter = %d %v", code, body)
	}
	// bad sort -> 422
	if code, _ := get(t, d, "/api/v1/assets?sort=nope"); code != http.StatusUnprocessableEntity {
		t.Fatalf("bad sort = %d", code)
	}
	// refresh -> 202
	if code := postRaw(t, d, "/api/v1/assets/refresh", `{}`); code != http.StatusAccepted {
		t.Fatalf("refresh = %d", code)
	}
}

func TestAssetsCorporateAndByLocation(t *testing.T) {
	d := assetBrowserTestDeps(true)
	code, body := get(t, d, "/api/v1/corporation/assets")
	if code != http.StatusOK {
		t.Fatalf("corp = %d %v", code, body)
	}
	items := body["data"].([]any)
	if len(items) != 1 || items[0].(map[string]any)["location_flag"] != "Corp Hangar 1" {
		t.Fatalf("corp items = %v", items)
	}
	code, body = get(t, d, "/api/v1/assets/by-location")
	if code != http.StatusOK {
		t.Fatalf("by-location = %d %v", code, body)
	}
	data := body["data"].(map[string]any)
	sum := data["summary"].(map[string]any)
	// flat: 3 personal items (incl. nested), corp excluded from personal
	if sum["total_items"] != 3.0 || sum["total_quantity"] != 151.0 {
		t.Fatalf("summary = %v", sum)
	}
	systems := data["solar_systems"].([]any)
	if len(systems) != 1 || systems[0].(map[string]any)["solar_system_name"] != "Jita" {
		t.Fatalf("systems = %v", systems)
	}
	stations := systems[0].(map[string]any)["stations"].([]any)
	if len(stations) != 1 || stations[0].(map[string]any)["item_count"] != 2.0 {
		t.Fatalf("stations = %v", stations)
	}
	code, body = get(t, d, "/api/v1/corporation/assets/by-location")
	if code != http.StatusOK {
		t.Fatalf("corp by-location = %d %v", code, body)
	}
}
