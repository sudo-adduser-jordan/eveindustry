package http

import (
	"net/http"
	"testing"
)

func TestFactoryPlanner(t *testing.T) {
	d := marketTestDeps()
	code, body := get(t, d, "/api/v1/calculators/factory-planner?tier=p1_to_p2&tax_rate=10")
	if code != http.StatusOK {
		t.Fatalf("code = %d %v", code, body)
	}
	data := body["data"].(map[string]any)
	if data["tier"] != "p1_to_p2" || data["factory_slots"].(float64) != 18 {
		t.Fatalf("meta = %v", data)
	}
	if len(data["results"].([]any)) != 24 {
		t.Fatalf("want 24 schematics, got %d", len(data["results"].([]any)))
	}
	first := data["results"].([]any)[0].(map[string]any)
	if first["daily_profit_after_tax"] == nil || first["prices_available"] != false {
		t.Fatalf("row = %v", first)
	}
	if code, _ := get(t, d, "/api/v1/calculators/factory-planner?tier=nope"); code != http.StatusUnprocessableEntity {
		t.Fatalf("bad tier = %d", code)
	}
	if code, _ := get(t, d, "/api/v1/calculators/factory-planner?tax_rate=101"); code != http.StatusUnprocessableEntity {
		t.Fatalf("bad tax = %d", code)
	}
}
