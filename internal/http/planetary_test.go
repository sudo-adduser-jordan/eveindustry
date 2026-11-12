package http

import (
	"context"
	"net/http"
	"testing"

	"github.com/sudo-adduser-jordan/eveindustry/internal/auth"
)

type stubPlanetaryChars struct {
	planets map[int64][]map[string]any
	details map[int64]map[int]map[string]any
}

func (s *stubPlanetaryChars) GetCharacterPlanets(ctx context.Context, id int64) ([]map[string]any, error) {
	return s.planets[id], nil
}
func (s *stubPlanetaryChars) GetCharacterPlanetDetails(ctx context.Context, id int64, pid int) (map[string]any, error) {
	return s.details[id][pid], nil
}
func (s *stubPlanetaryChars) ClearPlanetaryCache(id int64) {}

type stubPlanetaryUniverse struct{}

func (s *stubPlanetaryUniverse) GetPlanetarySchematic(ctx context.Context, id int) (map[string]any, error) {
	return map[string]any{"schematic_name": "Bacteria", "cycle_time": 1800}, nil
}
func (s *stubPlanetaryUniverse) GetPlanetInfo(ctx context.Context, id int) (map[string]any, error) {
	return map[string]any{"name": "Jita I"}, nil
}
func (s *stubPlanetaryUniverse) GetTypeInfo(ctx context.Context, id int) (map[string]any, error) {
	return map[string]any{"type_id": id, "name": "Tritanium", "volume": 0.01}, nil
}

func planetaryTestDeps() Deps {
	stub := testAuth()
	stub.chars = []auth.Character{{
		CharacterID: 11, Name: "Main", IsMain: true, IsEsiActive: true,
		Scopes: []string{"esi-planets.manage_planets.v1"},
	}}
	return Deps{Sessions: stub, Users: stub,
		Planetary: PlanetaryDeps{
			Characters: &stubPlanetaryChars{
				planets: map[int64][]map[string]any{11: {{
					"planet_id": 40000001, "planet_type": "temperate",
					"upgrade_level": 5, "num_pins": 2, "last_update": "2026-11-08T10:00:00Z",
				}}},
				details: map[int64]map[int]map[string]any{11: {40000001: {
					"pins": []any{
						map[string]any{
							"pin_id": 1, "type_id": 3068,
							"extractor_details": map[string]any{"product_type_id": 500, "qty_per_cycle": 100, "cycle_time": 900},
							"install_time":      "2026-11-08T10:00:00Z", "expiry_time": "2026-11-09T10:00:00Z",
						},
						map[string]any{
							"pin_id": 2, "type_id": 2256,
							"contents": []any{map[string]any{"type_id": 34, "amount": 50}},
						},
					},
					"routes": []any{
						map[string]any{"content_type_id": 500, "quantity": 10, "source_pin_id": 1, "destination_pin_id": 2},
					},
				}}},
			},
			Universe: &stubPlanetaryUniverse{},
		},
		Industry: &stubIndustry{
			names:  map[int]string{3068: "Temperate ECU", 500: "Bacteria", 34: "Tritanium"},
			groups: map[int]int{34: 18},
		},
	}
}

func TestPlanetaryShow(t *testing.T) {
	d := planetaryTestDeps()
	code, body := get(t, d, "/api/v1/planetary")
	if code != http.StatusOK {
		t.Fatalf("show = %d %v", code, body)
	}
	data := body["data"].(map[string]any)
	sum := data["summary"].(map[string]any)
	if sum["characters"] != 1.0 || sum["planets"] != 1.0 || sum["extractors"] != 1.0 || sum["factories"] != 0.0 {
		t.Fatalf("summary = %v", sum)
	}
	outputs := data["outputs"].([]any)
	if len(outputs) != 1 || outputs[0].(map[string]any)["source"] != nil {
		if len(outputs) != 1 {
			t.Fatalf("outputs = %v", outputs)
		}
	}
	if outputs[0].(map[string]any)["per_hour"] != 400.0 {
		t.Fatalf("per_hour = %v", outputs[0])
	}
	chars := data["characters"].([]any)
	planet := chars[0].(map[string]any)["planets"].([]any)[0].(map[string]any)
	if planet["planet_name"] != "Jita I" || len(planet["extractors"].([]any)) != 1 {
		t.Fatalf("planet = %v", planet)
	}
	for _, key := range []string{"extractor_count", "factory_count", "storage_count", "launchpad_count", "route_count"} {
		if _, ok := planet[key]; !ok {
			t.Fatalf("planet missing flat count %q: %v", key, planet)
		}
	}
	if _, ok := planet["storages"]; !ok {
		t.Fatalf("planet missing storages: %v", planet)
	}
	ext := planet["extractors"].([]any)[0].(map[string]any)
	if ext["icon_url"] == nil || ext["icon_url"] == "" {
		t.Fatalf("extractor missing icon_url: %v", ext)
	}
	storage := planet["storages"].([]any)[0].(map[string]any)
	if storage["kind"] != "launchpad" {
		t.Fatalf("storage kind = %v", storage)
	}
	if items, ok := storage["items"].([]any); !ok || len(items) != 1 {
		t.Fatalf("storage items = %v", storage)
	} else if items[0].(map[string]any)["icon_url"] == nil {
		t.Fatalf("storage item missing icon_url: %v", items[0])
	}
	for _, ov := range outputs {
		if _, ok := ov.(map[string]any)["source"]; ok {
			t.Fatalf("output leaks internal source: %v", ov)
		}
	}
	if data["capacity"].([]any)[0].(map[string]any)["status"] != "under_capacity" {
		t.Fatalf("capacity = %v", data["capacity"])
	}
	if code := postRaw(t, d, "/api/v1/planetary/refresh", `{}`); code != http.StatusNoContent {
		t.Fatalf("refresh = %d", code)
	}
	d2 := planetaryTestDeps()
	d2.Sessions.(*stubAuth).chars[0].Scopes = nil
	d2.Users.(*stubAuth).chars[0].Scopes = nil
	code, body = get(t, d2, "/api/v1/planetary")
	if code != http.StatusOK {
		t.Fatalf("scoped out = %d", code)
	}
	if body["data"].(map[string]any)["summary"].(map[string]any)["characters"] != 0.0 {
		t.Fatalf("expected empty: %v", body["data"])
	}
}
