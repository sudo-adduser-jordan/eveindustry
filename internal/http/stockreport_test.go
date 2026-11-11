package http

import (
	"context"
	"net/http"
	"testing"

	"github.com/sudo-adduser-jordan/eveindustry/internal/auth"
	"github.com/sudo-adduser-jordan/eveindustry/internal/store"
)

type stubStockReport struct {
	roles  map[int64][]string
	assets map[int64][]map[string]any
	items  []store.WatchedItem
}

func (s *stubStockReport) GetCharacterRoles(ctx context.Context, id int64) (map[string]any, error) {
	var out []any
	for _, r := range s.roles[id] {
		out = append(out, r)
	}
	return map[string]any{"roles": out}, nil
}
func (s *stubStockReport) GetCorporationAssets(ctx context.Context, charID, corpID int64) ([]map[string]any, error) {
	return s.assets[corpID], nil
}
func (s *stubStockReport) WatchedItems(ctx context.Context, uid int64) ([]store.WatchedItem, error) {
	return s.items, nil
}
func (s *stubStockReport) WatchedItem(ctx context.Context, id, uid int64) (*store.WatchedItem, error) {
	for _, it := range s.items {
		if it.ID == id {
			it := it
			return &it, nil
		}
	}
	return nil, nil
}
func (s *stubStockReport) UpsertWatchedItem(ctx context.Context, in store.WatchedItemUpsert) (*store.WatchedItem, error) {
	for i, it := range s.items {
		if it.TypeID == in.TypeID {
			s.items[i].DesiredQuantity = in.DesiredQuantity
			out := s.items[i]
			return &out, nil
		}
	}
	out := store.WatchedItem{ID: int64(len(s.items)) + 1, TypeID: in.TypeID, TypeName: in.TypeName, DesiredQuantity: in.DesiredQuantity}
	s.items = append(s.items, out)
	return &out, nil
}
func (s *stubStockReport) UpdateWatchedItemQuantity(ctx context.Context, id, uid, qty int64) (*store.WatchedItem, error) {
	for i, it := range s.items {
		if it.ID == id {
			s.items[i].DesiredQuantity = qty
			out := s.items[i]
			return &out, nil
		}
	}
	return nil, nil
}
func (s *stubStockReport) DeleteWatchedItem(ctx context.Context, id, uid int64) (bool, error) {
	for i, it := range s.items {
		if it.ID == id {
			s.items = append(s.items[:i], s.items[i+1:]...)
			return true, nil
		}
	}
	return false, nil
}

func stockReportTestDeps(stockManager bool, roles []string) Deps {
	stub := testAuth()
	u := stub.user
	u.IsStockManager = stockManager
	corpID := int64(988)
	stub.chars = []auth.Character{{
		CharacterID: 11, Name: "Main", IsMain: true, IsEsiActive: true,
		CorporationID: &corpID, CorporationName: strPtr("ACME"),
		Scopes: []string{"esi-assets.read_corporation_assets.v1", "esi-characters.read_corporation_roles.v1"},
	}}
	return Deps{Sessions: stub, Users: stub, StockReport: StockReportDeps{
		Characters: &stubStockReport{
			roles:  map[int64][]string{11: roles},
			assets: map[int64][]map[string]any{988: {{"type_id": 34, "quantity": 100}}},
		},
		Stock: &stubStockReport{items: []store.WatchedItem{
			{ID: 1, TypeID: 34, TypeName: "Tritanium", DesiredQuantity: 150},
			{ID: 2, TypeID: 35, TypeName: "Pyerite", DesiredQuantity: 10},
		}},
	}}
}

func TestStockReport(t *testing.T) {
	d := stockReportTestDeps(true, []string{"Director"})
	code, body := get(t, d, "/api/v1/stock")
	if code != http.StatusOK {
		t.Fatalf("code = %d %v", code, body)
	}
	rows := body["data"].([]any)
	if len(rows) != 2 {
		t.Fatalf("rows = %v", rows)
	}
	r0 := rows[0].(map[string]any)
	if r0["on_hand"].(float64) != 100 || r0["shortfall"].(float64) != 50 {
		t.Fatalf("row0 = %v", r0)
	}
	if r0["desired_quantity"].(float64) != 150 {
		t.Fatalf("desired = %v", r0)
	}
	r1 := rows[1].(map[string]any)
	if r1["on_hand"].(float64) != 0 || r1["shortfall"].(float64) != 10 {
		t.Fatalf("row1 = %v", r1)
	}
	if body["meta"].(map[string]any)["warning"] != nil {
		t.Fatalf("warning = %v", body["meta"])
	}
}

func TestStockReportGateAndWarning(t *testing.T) {
	d := stockReportTestDeps(false, []string{"Director"})
	if code, _ := get(t, d, "/api/v1/stock"); code != http.StatusForbidden {
		t.Fatalf("gate = %d", code)
	}
	d = stockReportTestDeps(true, []string{"Member"})
	code, body := get(t, d, "/api/v1/stock")
	if code != http.StatusOK {
		t.Fatalf("code = %d", code)
	}
	if body["meta"].(map[string]any)["warning"] == nil {
		t.Fatalf("want warning, got %v", body["meta"])
	}
	if body["data"].([]any)[0].(map[string]any)["on_hand"].(float64) != 0 {
		t.Fatalf("on-hand must be 0 with warning: %v", body)
	}
}
