package pi

import (
	"testing"
	"time"
)

func TestCategorizePinType(t *testing.T) {
	if CategorizePinType(3068, "whatever") != PinExtractor {
		t.Fatal("map")
	}
	if CategorizePinType(999999, "Temperate Extractor Control") != PinExtractor {
		t.Fatal("name fallback")
	}
	if CategorizePinType(999999, "Basic Industry Facility") != PinBasicFactory {
		t.Fatal("basic fallback")
	}
	if CategorizePinType(0, "???") != PinUnknown {
		t.Fatal("unknown")
	}
}

func TestExtractorTotals(t *testing.T) {
	total, avg, cycles := ExtractorTotals(100, 900, 3600)
	if cycles != 4 {
		t.Fatalf("cycles = %d", cycles)
	}
	if total <= 0 || avg <= 0 {
		t.Fatalf("total=%v avg=%v", total, avg)
	}
	if _, _, c := ExtractorTotals(0, 900, 3600); c != 0 {
		t.Fatal("zero qty")
	}
}

func TestStorageUsage(t *testing.T) {
	r := TypeResolver{Names: map[int]string{34: "Tritanium"}, Volumes: map[int]float64{34: 0.01}}
	out := StorageUsage([]map[string]any{{"type_id": 34, "amount": 100}}, StorageCapacity, r)
	if out["used_volume"] != 1.0 || out["total_items"] != int64(100) {
		t.Fatalf("usage = %v", out)
	}
	if out["usage_percent"] != 0.0 {
		t.Fatalf("pct = %v", out["usage_percent"])
	}
}

func TestAnalyzePlanet(t *testing.T) {
	r := TypeResolver{
		Names:   map[int]string{3068: "Temperate ECU", 500: "Bacteria", 34: "Tritanium"},
		Volumes: map[int]float64{34: 0.01},
	}
	now := time.Date(2026, 11, 8, 12, 0, 0, 0, time.UTC)
	details := map[string]any{
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
		"links": []any{map[string]any{"source_pin_id": 1}},
	}
	a := AnalyzePlanet(details, r, func(id int) map[string]any { return nil }, now)
	if a.Summary["extractor_count"] != 1 || a.Summary["launchpad_count"] != 1 {
		t.Fatalf("summary = %v", a.Summary)
	}
	if len(a.Alerts) != 0 {
		t.Fatalf("alerts = %v", a.Alerts)
	}
	if len(a.Outputs) != 1 {
		t.Fatalf("outputs = %v", a.Outputs)
	}
	// expired extractor alerts
	details["pins"].([]any)[0].(map[string]any)["expiry_time"] = "2026-11-01T10:00:00Z"
	a = AnalyzePlanet(details, r, func(id int) map[string]any { return nil }, now)
	if len(a.Alerts) != 1 {
		t.Fatalf("expired alerts = %v", a.Alerts)
	}
}

func TestBuildCapacityAnalysis(t *testing.T) {
	in := []map[string]any{
		{"character_name": "A", "planet_name": "P1", "planet_type": "temperate",
			"summary": map[string]any{"extractor_count": 2, "factory_count": 0}},
		{"character_name": "A", "planet_name": "P2", "planet_type": "lava",
			"summary": map[string]any{"extractor_count": 0, "factory_count": 3}},
		{"character_name": "A", "planet_name": "P3", "planet_type": "oceanic",
			"summary": map[string]any{"extractor_count": 1, "factory_count": 10}},
	}
	out := BuildCapacityAnalysis(in)
	got := map[string]string{}
	for _, v := range out {
		m := v.(map[string]any)
		got[m["planet_name"].(string)] = m["status"].(string)
	}
	if got["P1"] != "under_capacity" || got["P2"] != "idle_factories" || got["P3"] != "over_capacity" {
		t.Fatalf("statuses = %v", got)
	}
}
