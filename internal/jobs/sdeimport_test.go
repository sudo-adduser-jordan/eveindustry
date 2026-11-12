package jobs

import (
	"context"
	"testing"

	"github.com/sudo-adduser-jordan/eveindustry/internal/esi"
	"github.com/sudo-adduser-jordan/eveindustry/internal/store"
)

type stubTypeStore struct {
	types map[int]*esi.LocalType
}

func (s *stubTypeStore) EveTypeByID(ctx context.Context, typeID int) (*esi.LocalType, error) {
	return s.types[typeID], nil
}

func (s *stubTypeStore) SdeStationByID(ctx context.Context, stationID uint64) (*esi.LocalStation, error) {
	return nil, nil
}

func int64Ptr(v int64) *int64 { return &v }

func TestProcessEveTypeChunkPreservesCachedGroups(t *testing.T) {
	ctx := context.Background()
	s, _ := demoTestDB(t)
	if err := s.UpsertEveTypes(ctx, []store.SdeType{
		{TypeID: 34, Name: "Tritanium", GroupID: int64Ptr(18), MarketGroupID: int64Ptr(1857), Published: true},
	}); err != nil {
		t.Fatal(err)
	}
	h := HandlerDeps{
		DB: s,
		ESIUniverse: esi.NewUniverseClient(nil, nil, nil, &stubTypeStore{types: map[int]*esi.LocalType{
			34: {TypeID: 34, Name: "Tritanium", GroupID: int64Ptr(18), MarketGroupID: int64Ptr(1857), Published: true},
		}}),
	}
	if err := ProcessEveTypeChunk(ctx, h, []int{34}, false); err != nil {
		t.Fatal(err)
	}
	row, err := s.EveTypeByID(ctx, 34)
	if err != nil || row == nil {
		t.Fatalf("row = %+v %v", row, err)
	}
	if row.GroupID == nil || *row.GroupID != 18 {
		t.Fatalf("group_id = %+v, want 18", row.GroupID)
	}
	if row.MarketGroupID == nil || *row.MarketGroupID != 1857 {
		t.Fatalf("market_group_id = %+v, want 1857", row.MarketGroupID)
	}
}
