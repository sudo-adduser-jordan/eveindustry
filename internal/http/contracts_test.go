package http

import (
	"context"
	"database/sql"
	"net/http"
	"testing"
	"time"

	"github.com/sudo-adduser-jordan/eveindustry/internal/store"
)

// stubContracts implements store.ContractsStore for handler tests.
type stubContracts struct {
	generation *uint64
	types      map[string]store.TypedName
	names      map[int]string
	price      float64
	found      int
	list       []store.SnapshotContract
	total      int
	stats      map[string]int
	show       *store.SnapshotContract
	items      []store.SnapshotItem
	freshAt    *time.Time
}

func (s *stubContracts) ActiveGeneration(ctx context.Context) (*uint64, error) {
	return s.generation, nil
}
func (s *stubContracts) TypesByName(ctx context.Context, names []string) (map[string]store.TypedName, error) {
	return s.types, nil
}
func (s *stubContracts) TypesByID(ctx context.Context, ids []int) (map[int]string, error) {
	return s.names, nil
}
func (s *stubContracts) ContractPricing(ctx context.Context, gen uint64, typeID int, sellOnly, buyOnly bool, lowest bool) (float64, int, error) {
	return s.price, s.found, nil
}
func (s *stubContracts) ContractList(ctx context.Context, gen uint64, typeFilter, search string, limit, offset int) ([]store.SnapshotContract, int, error) {
	return s.list, s.total, nil
}
func (s *stubContracts) ContractTypeStats(ctx context.Context, gen uint64) (map[string]int, error) {
	return s.stats, nil
}
func (s *stubContracts) ContractShow(ctx context.Context, gen uint64, id uint64) (*store.SnapshotContract, error) {
	return s.show, nil
}
func (s *stubContracts) ContractItems(ctx context.Context, gen uint64, id uint64) ([]store.SnapshotItem, error) {
	return s.items, nil
}
func (s *stubContracts) ContractsFreshnessTime(ctx context.Context, gen uint64) (*time.Time, error) {
	return s.freshAt, nil
}

func contractsTestDeps() Deps {
	gen := uint64(7)
	now := time.Now()
	return Deps{Contracts: &stubContracts{
		generation: &gen,
		types:      map[string]store.TypedName{"Tritanium": {TypeID: 34, Name: "Tritanium"}},
		names:      map[int]string{34: "Tritanium"},
		price:      12.5, found: 3,
		list: []store.SnapshotContract{{ContractID: 1, RegionID: 10000002, Type: "item_exchange",
			Title: sql.NullString{String: "WTS Trit", Valid: true}, Price: 100, Volume: 1,
			DateIssued: sql.NullTime{Time: now, Valid: true}, ItemsCount: 1}},
		total: 1,
		stats: map[string]int{"item_exchange": 1},
		show: &store.SnapshotContract{ContractID: 1, RegionID: 10000002, Type: "item_exchange",
			Price: 100, Volume: 1, ItemsCount: 1},
		items:   []store.SnapshotItem{{TypeID: 34, Quantity: 10, IsIncluded: true}},
		freshAt: &now,
	}}
}

func TestAppraisal(t *testing.T) {
	d := contractsTestDeps()
	code, body := post(t, d, "/api/v1/market/appraisals", `{"items": "Tritanium\nTritanium\nUnknown Item"}`)
	if code != http.StatusOK {
		t.Fatalf("code = %d %v", code, body)
	}
	data := body["data"].(map[string]any)
	items := data["items"].([]any)
	if len(items) != 1 || items[0].(map[string]any)["line_total"].(float64) != 25 {
		t.Fatalf("items = %v", items)
	}
	unmatched := data["unmatched"].([]any)
	if len(unmatched) != 1 || unmatched[0].(string) != "Unknown Item" {
		t.Fatalf("unmatched = %v", unmatched)
	}
	if body["meta"].(map[string]any)["freshness"].(map[string]any)["source"] != "public_contracts" {
		t.Fatalf("freshness = %v", body["meta"])
	}
	if code, _ := post(t, d, "/api/v1/market/appraisals", `{"items": ""}`); code != http.StatusUnprocessableEntity {
		t.Fatalf("empty items = %d", code)
	}
}

func TestContractBrowser(t *testing.T) {
	d := contractsTestDeps()
	code, body := get(t, d, "/api/v1/market/contracts")
	if code != http.StatusOK {
		t.Fatalf("code = %d %v", code, body)
	}
	if len(body["data"].([]any)) != 1 {
		t.Fatalf("rows = %v", body["data"])
	}
	stats := body["meta"].(map[string]any)["stats"].(map[string]any)
	if stats["total"].(float64) != 1 {
		t.Fatalf("stats = %v", stats)
	}
	code, body = get(t, d, "/api/v1/market/contracts/1")
	if code != http.StatusOK {
		t.Fatalf("show = %d %v", code, body)
	}
	if len(body["data"].(map[string]any)["items"].([]any)) != 1 {
		t.Fatalf("items = %v", body["data"])
	}
	if code, _ := get(t, d, "/api/v1/market/contracts?type=bogus"); code != http.StatusUnprocessableEntity {
		t.Fatalf("bad type = %d", code)
	}
}
