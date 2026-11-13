package http

import (
	"net/http"
	"testing"
)

func TestMostProfitable(t *testing.T) {
	d := browserTestDeps()
	code, body := get(t, d, "/api/v1/industry/most-profitable?runs=2")
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
	if code, _ := get(t, d, "/api/v1/industry/most-profitable?price_region_id=1"); code != http.StatusUnprocessableEntity {
		t.Fatalf("bad region = %d", code)
	}
}
