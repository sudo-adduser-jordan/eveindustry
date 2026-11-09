package esi

import (
	"context"
	"fmt"
	"net/http"
	"net/http/httptest"
	"testing"
)

type stubTypes struct{}

func (stubTypes) EveTypeByID(ctx context.Context, id int) (*LocalType, error) {
	if id == 34 {
		return &LocalType{TypeID: 34, Name: "Tritanium", Published: true}, nil
	}
	return nil, nil
}
func (stubTypes) SdeStationByID(ctx context.Context, id uint64) (*LocalStation, error) {
	if id == 60003760 {
		return &LocalStation{StationID: id, Name: "Jita 4-4"}, nil
	}
	return nil, nil
}

func universeTestStack(srv *httptest.Server, hits *int) (*UniverseClient, *MemStore) {
	st := NewMemStore()
	lim := NewLimiter(st, "test")
	circ := NewCircuit(st)
	cl := NewClient(st, lim, circ, "Test/1.0")
	cl.BaseURL = srv.URL
	cl.HTTP = srv.Client()
	return NewUniverseClient(cl, nil, NewMemo(), stubTypes{}), st
}

func TestGetTypeInfoLocalFirst(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Write([]byte(`{"type_id":35,"name":"Pyerite"}`))
	}))
	defer srv.Close()
	uv, _ := universeTestStack(srv, nil)
	// local hit: no HTTP needed (server would 404 unknown paths anyway)
	info, err := uv.GetTypeInfo(context.Background(), 34)
	if err != nil || info["name"] != "Tritanium" {
		t.Fatalf("local: %+v %v", info, err)
	}
	// miss falls back to ESI
	info, err = uv.GetTypeInfo(context.Background(), 35)
	if err != nil || info["name"] != "Pyerite" {
		t.Fatalf("esi: %+v %v", info, err)
	}
	// cached: server gone still works
	srv.Close()
	if _, err := uv.GetTypeInfo(context.Background(), 35); err != nil {
		t.Fatalf("cache: %v", err)
	}
}

func TestGetBestTypeImageUrl(t *testing.T) {
	var seen []string
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		seen = append(seen, r.Method+" "+r.URL.Path)
		if r.URL.Path == "/types/34/icon" {
			w.WriteHeader(200)
			return
		}
		w.WriteHeader(400)
	}))
	defer srv.Close()
	uv, _ := universeTestStack(srv, nil)
	// point image check at the test server by shadowing: call probe paths directly
	if !uv.imageOK(context.Background(), srv.URL+"/types/34/icon?size=64") {
		t.Fatal("HEAD 200 should pass")
	}
	if uv.imageOK(context.Background(), srv.URL+"/types/99/bpc?size=64") {
		t.Fatal("400 should fail")
	}
	_ = fmt.Sprint(seen)
}

func TestGetStationLocalFirst(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Write([]byte(`{"station_id":1,"name":"Remote"}`))
	}))
	defer srv.Close()
	uv, _ := universeTestStack(srv, nil)
	st, err := uv.GetStation(context.Background(), 60003760)
	if err != nil || st["name"] != "Jita 4-4" {
		t.Fatalf("local: %+v %v", st, err)
	}
}
