package esi

import (
	"context"
	"net/http"
	"net/http/httptest"
	"testing"
)

func TestGetServerStatus(t *testing.T) {
	hits := 0
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		hits++
		if r.URL.Path != "/status/" {
			t.Errorf("path = %q, want /status/", r.URL.Path)
		}
		w.Write([]byte(`{"players":23456,"server_version":"2824473","vip":false}`))
	}))
	defer srv.Close()
	uv, _ := universeTestStack(srv, nil)
	st, err := uv.GetServerStatus(context.Background())
	if err != nil || st == nil || st.Players != 23456 || st.ServerVersion != "2824473" || st.VIP {
		t.Fatalf("status = %+v err = %v", st, err)
	}
	if _, err := uv.GetServerStatus(context.Background()); err != nil {
		t.Fatalf("cached: %v", err)
	}
	if hits != 1 {
		t.Fatalf("hits = %d, want 1 (memoized)", hits)
	}
}

func TestGetServerStatusDown(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusServiceUnavailable)
	}))
	defer srv.Close()
	uv, _ := universeTestStack(srv, nil)
	st, err := uv.GetServerStatus(context.Background())
	if err == nil || st != nil {
		t.Fatalf("down: st = %+v err = %v, want a transient error", st, err)
	}
}
