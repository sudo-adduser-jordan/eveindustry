package http

import (
	"database/sql"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/sudo-adduser-jordan/eveindustry/internal/industry"
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
