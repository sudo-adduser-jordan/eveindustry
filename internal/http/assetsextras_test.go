package http

import (
	"context"
	"net/http"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/sudo-adduser-jordan/eveindustry/internal/assets"
	"github.com/sudo-adduser-jordan/eveindustry/internal/auth"
	"github.com/sudo-adduser-jordan/eveindustry/internal/cache"
	"github.com/sudo-adduser-jordan/eveindustry/internal/store"
	"github.com/sudo-adduser-jordan/eveindustry/migrations"
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
	if it["quantity"] != 150.0 || it["total_value"] != 0.0 {
		t.Fatalf("item = %v", it)
	}
	if body["meta"].(map[string]any)["corporation_assets_warning"] == nil {
		t.Fatalf("warning missing: %v", body["meta"])
	}
}

func TestAssetMaterialsUsesCachedTimestamp(t *testing.T) {
	db, err := store.Connect(filepath.Join(t.TempDir(), "matts.sqlite"))
	if err != nil {
		t.Fatal(err)
	}
	defer func() { _ = db.DB.Close() }()
	for _, name := range []string{
		"0000_00_00_000000_baseline.up.sql",
		"2026_11_09_000001_notification_settings_unique.up.sql",
	} {
		raw, err := migrations.FS.ReadFile(name)
		if err != nil {
			t.Fatal(err)
		}
		for _, stmt := range strings.Split(string(raw), ";\n") {
			if strings.TrimSpace(stmt) == "" {
				continue
			}
			if _, err := db.DB.Exec(stmt); err != nil {
				t.Fatalf("%s: %v\n%s", name, err, stmt)
			}
		}
	}
	ctx := context.Background()
	cch := &cache.Cache{DB: db.DB}
	cached := []assets.Asset{{
		ItemID: 1, TypeID: 34, TypeName: "Tritanium", Quantity: 150,
		LocationID: 60003760, Location: "Jita IV", Flag: "Hangar",
		CharID: 11, CharName: "Main", Owner: "personal",
	}}
	cch.SetJSON(ctx, "assets.user.1", cached, 0)
	cch.SetJSON(ctx, "assets.user.1.cached_at", time.Now().Unix(), 0)
	stub := testAuth()
	stub.chars = []auth.Character{{
		CharacterID: 11, Name: "Main", IsMain: true, IsEsiActive: true,
		Scopes: []string{"esi-assets.read_assets.v1"},
	}}
	d := assetBrowserTestDeps(false)
	d.Sessions, d.Users = stub, stub
	d.Cache = cch
	d.Industry.(*stubIndustry).groups = map[int]int{34: 18}
	code, body := get(t, d, "/api/v1/assets/materials")
	if code != http.StatusOK {
		t.Fatalf("materials = %d %v", code, body)
	}
	if body["meta"].(map[string]any)["last_updated"] == nil {
		t.Fatalf("meta = %v, want the cache fill time", body["meta"])
	}
	data := body["data"].(map[string]any)
	if len(data["groups"].([]any)) != 1 {
		t.Fatalf("groups = %v", data["groups"])
	}
}

func TestAssetMaterialsFallbackGroups(t *testing.T) {
	d := assetBrowserTestDeps(false)
	d.Industry.(*stubIndustry).groups = nil
	code, body := get(t, d, "/api/v1/assets/materials")
	if code != http.StatusOK {
		t.Fatalf("materials = %d %v", code, body)
	}
	data := body["data"].(map[string]any)
	groups := data["groups"].([]any)
	if len(groups) != 1 || groups[0].(map[string]any)["name"] != "Minerals" {
		t.Fatalf("groups = %v", groups)
	}
}

func TestAssetShips(t *testing.T) {
	d := assetBrowserTestDeps(false)
	d.Industry.(*stubIndustry).groups = map[int]int{34: 18, 587: 25}
	code, body := get(t, d, "/api/v1/assets/ships")
	if code != http.StatusOK {
		t.Fatalf("ships = %d %v", code, body)
	}
	data := body["data"].(map[string]any)
	if data["total_ships"] != 0.0 || len(data["ship_groups"].([]any)) != 0 {
		t.Fatalf("ships = %v", data)
	}
}

func TestStockSearch(t *testing.T) {
	d := assetBrowserTestDeps(false)
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
