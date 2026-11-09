package http

import (
	"net/http"
	"testing"

	"github.com/sudo-adduser-jordan/eveindustry/internal/store"
)

func TestAssetMaterials(t *testing.T) {
	d := assetBrowserTestDeps(false)
	code, body := get(t, d, "/api/v1/assets/materials")
	if code != http.StatusOK {
		t.Fatalf("materials = %d %v", code, body)
	}
	data := body["data"].(map[string]any)
	groups := data["groups"].([]any)
	if len(groups) != 1 || groups[0].(map[string]any)["name"] != "Minerals" {
		t.Fatalf("groups = %v", groups)
	}
	items := groups[0].(map[string]any)["items"].([]any)
	if len(items) != 1 {
		t.Fatalf("items = %v", items)
	}
	it := items[0].(map[string]any)
	// 150 Tritanium (100 + 50 nested) — prices stub returns nothing, value 0
	if it["quantity"] != 150.0 || it["total_value"] != 0.0 {
		t.Fatalf("item = %v", it)
	}
	if body["meta"].(map[string]any)["corporation_assets_warning"] == nil {
		t.Fatalf("warning missing: %v", body["meta"])
	}
}

func TestAssetShips(t *testing.T) {
	d := assetBrowserTestDeps(false)
	d.Industry.(*stubIndustry).groups = map[int]int{34: 18, 587: 25}
	code, body := get(t, d, "/api/v1/assets/ships")
	if code != http.StatusOK {
		t.Fatalf("ships = %d %v", code, body)
	}
	// GroupNames stub returns empty -> no ship groups
	data := body["data"].(map[string]any)
	if data["total_ships"] != 0.0 || len(data["ship_groups"].([]any)) != 0 {
		t.Fatalf("ships = %v", data)
	}
}

func TestStockSearch(t *testing.T) {
	d := assetBrowserTestDeps(false)
	// not a stock manager -> 403
	if code, _ := get(t, d, "/api/v1/stock/search?q=tri"); code != http.StatusForbidden {
		t.Fatalf("gate = %d", code)
	}
	stub := d.Sessions.(*stubAuth)
	stub.user.IsStockManager = true
	code, body := get(t, d, "/api/v1/stock/search?q=tri")
	if code != http.StatusOK {
		t.Fatalf("search = %d %v", code, body)
	}
	items := body["data"].([]any)
	if len(items) != 1 || items[0].(map[string]any)["name"] != "Tritanium" {
		t.Fatalf("items = %v", items)
	}
	if body["meta"].(map[string]any)["limit"] != 20.0 {
		t.Fatalf("meta = %v", body["meta"])
	}
	if code, _ := get(t, d, "/api/v1/stock/search"); code != http.StatusUnprocessableEntity {
		t.Fatalf("missing q = %d", code)
	}
	_ = store.EveType{}
}
