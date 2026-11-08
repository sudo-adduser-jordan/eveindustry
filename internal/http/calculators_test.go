package http

import (
	"database/sql"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/sudo-adduser-jordan/eveindustry/internal/industry"
	"github.com/sudo-adduser-jordan/eveindustry/internal/store"
)

func industryActivityRow(bp, act, tm int, mats, prods string) *industry.BlueprintActivityRow {
	return &industry.BlueprintActivityRow{
		BlueprintTypeID: bp, ActivityID: act, Time: tm,
		Materials: sql.NullString{String: mats, Valid: true},
		Products:  sql.NullString{String: prods, Valid: true},
	}
}

func calcTestDeps() Deps {
	_, _, mkt := testDeps()
	d := industryTestDeps()
	d.Market = mkt
	m := mkt
	d.Trading = m
	m.aggregates = map[int]float64{34: 10.5}
	m.hasSnapshot = true
	return d
}

func post(t *testing.T, d Deps, path, body string) (int, map[string]any) {
	t.Helper()
	e := New(d)
	req := httptest.NewRequest(http.MethodPost, path, strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	rec := httptest.NewRecorder()
	e.ServeHTTP(rec, req)
	var out map[string]any
	if err := json.Unmarshal(rec.Body.Bytes(), &out); err != nil {
		t.Fatalf("POST %s: body is not JSON: %v (%s)", path, err, rec.Body.String())
	}
	return rec.Code, out
}

func TestManufacturingCalculate(t *testing.T) {
	d := calcTestDeps()
	code, body := post(t, d, "/api/v1/calculators/manufacturing", `{
		"blueprint_type_id": 786, "product_type_id": 587, "runs": 10,
		"me_level": 10, "te_level": 10, "industry_skill": 5, "advanced_industry_skill": 5
	}`)
	if code != http.StatusOK {
		t.Fatalf("code = %d %v", code, body)
	}
	data := body["data"].(map[string]any)
	mats := data["materials"].([]any)
	if len(mats) != 1 || mats[0].(map[string]any)["adjusted_quantity"].(float64) != 900 {
		t.Fatalf("materials = %v", mats)
	}
	fullBuy := data["scenarios"].(map[string]any)["full_buy"].(map[string]any)
	// 900 x 10.5 = 9450 cost; revenue 0 (no price for 587); profit -9450
	if fullBuy["material_cost"].(float64) != 9450 || fullBuy["profit"].(float64) != -9450 {
		t.Fatalf("full_buy = %v", fullBuy)
	}
	if data["product"].(map[string]any)["total_output"].(float64) != 10 {
		t.Fatalf("output = %v", data["product"])
	}
}

func TestManufacturingCalculateValidation(t *testing.T) {
	d := calcTestDeps()
	if code, _ := post(t, d, "/api/v1/calculators/manufacturing", `{"runs": 0}`); code != http.StatusUnprocessableEntity {
		t.Fatalf("bad body = %d", code)
	}
	// unknown blueprint with no materials posted -> 422 on blueprint_type_id
	if code, _ := post(t, d, "/api/v1/calculators/manufacturing", `{
		"blueprint_type_id": 1, "product_type_id": 587, "runs": 1,
		"me_level": 0, "te_level": 0, "industry_skill": 0, "advanced_industry_skill": 0
	}`); code != http.StatusUnprocessableEntity {
		t.Fatalf("no materials = %d", code)
	}
}

func TestReactionCalculate(t *testing.T) {
	d := calcTestDeps()
	stub := d.Industry.(*stubIndustry)
	stub.activities[[2]int{999, 11}] = industryActivityRow(
		999, 11, 600,
		`[{"type_id":34,"name":"Tritanium","quantity":50}]`,
		`[{"type_id":587,"name":"Product","quantity":5}]`,
	)
	code, body := post(t, d, "/api/v1/calculators/reactions", `{
		"formula_type_id": 999, "product_type_id": 587, "runs": 5, "reactions_skill": 5
	}`)
	if code != http.StatusOK {
		t.Fatalf("code = %d %v", code, body)
	}
	data := body["data"].(map[string]any)
	mats := data["materials"].([]any)
	// 50 x 5 runs, no rig: 250
	if len(mats) != 1 || mats[0].(map[string]any)["adjusted_quantity"].(float64) != 250 {
		t.Fatalf("materials = %v", mats)
	}
	if data["product"].(map[string]any)["total_output"].(float64) != 25 {
		t.Fatalf("output = %v", data["product"])
	}
	// unknown formula -> 422
	if code, _ := post(t, d, "/api/v1/calculators/reactions", `{
		"formula_type_id": 1, "product_type_id": 587, "runs": 1, "reactions_skill": 0
	}`); code != http.StatusUnprocessableEntity {
		t.Fatalf("no materials = %d", code)
	}
}

func TestQueueSummary(t *testing.T) {
	d := calcTestDeps()
	code, body := post(t, d, "/api/v1/calculators/manufacturing/queue-summary",
		`{"items": [{"revenue": 1000, "input_cost": 800, "fees": 40}]}`)
	if code != http.StatusOK {
		t.Fatalf("code = %d %v", code, body)
	}
	totals := body["data"].(map[string]any)["totals"].(map[string]any)
	if totals["profit"].(float64) != 160 {
		t.Fatalf("totals = %v", totals)
	}
	if code, _ := post(t, d, "/api/v1/calculators/manufacturing/queue-summary", `{"items": []}`); code != http.StatusUnprocessableEntity {
		t.Fatalf("empty items = %d", code)
	}
}

func TestReactionPlanner(t *testing.T) {
	d := calcTestDeps()
	stub := d.Industry.(*stubIndustry)
	stub.activities[[2]int{999, 11}] = industryActivityRow(999, 11, 600,
		`[{"type_id":34,"name":"Tritanium","quantity":50}]`,
		`[{"type_id":587,"name":"Composite","quantity":5}]`,
	)
	m := d.Market.(*stubMarket)
	m.aggregates = map[int]float64{34: 10.5}
	m.hasSnapshot = true
	code, body := post(t, d, "/api/v1/calculators/reaction-planner", `{
		"entries": [{"formula_type_id": 999, "slots": 1, "runs_per_slot": 5}]
	}`)
	if code != http.StatusOK {
		t.Fatalf("code = %d %v", code, body)
	}
	data := body["data"].(map[string]any)
	raw := data["raw_materials"].([]any)
	// 50 x 5 runs = 250 goo @ 10.5 -> input 2625
	if len(raw) != 1 || raw[0].(map[string]any)["quantity"].(float64) != 250 {
		t.Fatalf("raw = %v", raw)
	}
	if data["profit"].(float64) != -2625 || data["slots"].(map[string]any)["total"].(float64) != 1 {
		t.Fatalf("summary = %v", data)
	}
	if code, _ := post(t, d, "/api/v1/calculators/reaction-planner", `{"entries": []}`); code != http.StatusUnprocessableEntity {
		t.Fatalf("empty entries = %d", code)
	}
}

func TestReactionBrowser(t *testing.T) {
	d := calcTestDeps()
	m := d.Market.(*stubMarket)
	m.aggregates = map[int]float64{34: 10.5, 587: 100}
	m.hasSnapshot = true
	m.history = []store.MarketHistoryRow{{Volume: 100, OrderCount: 10}, {Volume: 200, OrderCount: 20}}
	code, body := post(t, d, "/api/v1/calculators/reaction-browser", `{"runs": 5}`)
	if code != http.StatusOK {
		t.Fatalf("code = %d %v", code, body)
	}
	data := body["data"].([]any)
	if len(data) != 1 {
		t.Fatalf("rows = %v", data)
	}
	row := data[0].(map[string]any)
	// input 250 x 10.5 = 2625; output 25 x 100 = 2500; scc 100; profit -225
	if row["profit"].(float64) != -225 {
		t.Fatalf("profit = %v", row)
	}
	if row["reaction_complexity"] != "intermediate" {
		t.Fatalf("complexity = %v", row)
	}
	score := row["sell_score"].(map[string]any)
	if score["total_days"].(float64) != 2 || score["score"].(float64) == 0 {
		t.Fatalf("sell score = %v", score)
	}
}

func TestInventionCalculate(t *testing.T) {
	d := calcTestDeps()
	code, body := post(t, d, "/api/v1/calculators/invention", `{
		"t2_type_id": 587, "encryption_skill": 5, "science1_skill": 5,
		"science2_skill": 5, "invention_runs": 1
	}`)
	if code != http.StatusOK {
		t.Fatalf("code = %d %v", code, body)
	}
	data := body["data"].(map[string]any)
	inv := data["invention"].(map[string]any)
	// 0.3 * (1 + 5/40 + 5/30 + 5/30) = 0.4375; ceil(1/0.4375) = 3 attempts
	if inv["probability"].(float64) != 0.4375 || inv["expected_attempts"].(float64) != 3 {
		t.Fatalf("invention = %v", inv)
	}
	mats := data["t2_materials"].([]any)
	// 100 x (4*1) runs at ME 2: 400 x 0.98 = 392
	if len(mats) != 1 || mats[0].(map[string]any)["adjusted_quantity"].(float64) != 392 {
		t.Fatalf("materials = %v", mats)
	}
	// unknown item -> 422 on t2_type_id
	if code, _ := post(t, d, "/api/v1/calculators/invention", `{
		"t2_type_id": 1, "encryption_skill": 0, "science1_skill": 0,
		"science2_skill": 0, "invention_runs": 1
	}`); code != http.StatusUnprocessableEntity {
		t.Fatalf("no chain = %d", code)
	}
}

func TestDecryptors(t *testing.T) {
	d := calcTestDeps()
	code, body := get(t, d, "/api/v1/calculators/invention/decryptors")
	if code != http.StatusOK || len(body["data"].([]any)) != 7 {
		t.Fatalf("code = %d %v", code, body)
	}
}
