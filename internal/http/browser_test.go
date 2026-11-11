package http

import (
	"net/http"
	"testing"

	"github.com/sudo-adduser-jordan/eveindustry/internal/industry"
)

func browserTestDeps() Deps {
	d := calcTestDeps()
	stub := d.Industry.(*stubIndustry)
	stub.mfgActs = []industry.MfgActivity{{
		BlueprintTypeID: 786,
		Materials:       []map[string]any{{"type_id": 34, "name": "Tritanium", "quantity": 100}},
		Products:        []map[string]any{{"type_id": 587, "name": "Rifter", "quantity": 1}},
		Time:            100,
	}}
	stub.precomputed = []industry.PrecomputedCost{{
		ProductTypeID: 587, BlueprintTypeID: 786, ProductName: "Rifter",
		BlueprintName: "Rifter Blueprint", PortionSize: 1,
		InputCost: 900, OutputValue: 1000, BaseProfit: 60, ProfitMargin: 6.7,
	}}
	stub.precomputedTotal = 1
	m := d.Market.(*stubMarket)
	m.aggregates = map[int]float64{34: 10.5, 587: 1000}
	m.hasSnapshot = true
	return d
}

func TestManufacturingBrowserCalculate(t *testing.T) {
	d := browserTestDeps()
	code, body := post(t, d, "/api/v1/calculators/manufacturing-browser", `{
		"runs": 10, "me_level": 10, "market_group_id": 2
	}`)
	if code != http.StatusOK {
		t.Fatalf("code = %d %v", code, body)
	}
	data := body["data"].([]any)
	if len(data) != 1 {
		t.Fatalf("rows = %v", data)
	}
	row := data[0].(map[string]any)
	if row["profit"].(float64) != 150 || row["total_input_cost"].(float64) != 9450 {
		t.Fatalf("row = %v", row)
	}
	if body["meta"].(map[string]any)["effective_structure_me_bonus"].(float64) != 0 {
		t.Fatalf("meta = %v", body["meta"])
	}
	if code, _ := post(t, d, "/api/v1/calculators/manufacturing-browser", `{"runs": 1}`); code != http.StatusUnprocessableEntity {
		t.Fatalf("missing scope = %d", code)
	}
}

func TestManufacturingBrowserBrowse(t *testing.T) {
	d := browserTestDeps()
	code, body := get(t, d, "/api/v1/calculators/manufacturing-browser?market_group_id=2&runs=2")
	if code != http.StatusOK {
		t.Fatalf("code = %d %v", code, body)
	}
	data := body["data"].([]any)
	if len(data) != 1 {
		t.Fatalf("rows = %v", data)
	}
	row := data[0].(map[string]any)
	if row["profit"].(float64) != 120 || row["total_output"].(float64) != 2 {
		t.Fatalf("row = %v", row)
	}
	base := row["base"].(map[string]any)
	if base["profit"].(float64) != 60 {
		t.Fatalf("base = %v", base)
	}
	if code, _ := get(t, d, "/api/v1/calculators/manufacturing-browser"); code != http.StatusUnprocessableEntity {
		t.Fatalf("missing group = %d", code)
	}
}
