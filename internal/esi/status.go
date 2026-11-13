package esi

import (
	"context"
	"net/http"
	"time"
)

type ServerStatus struct {
	Players       int64
	ServerVersion string
	VIP           bool
}

func (c *UniverseClient) GetServerStatus(ctx context.Context) (*ServerStatus, error) {
	const key = "esi.server_status"
	if v, ok := c.memo.Get(key); ok {
		if v == nil {
			return nil, nil
		}
		if st, ok := v.(*ServerStatus); ok {
			return st, nil
		}
		return nil, nil
	}
	resp, err := c.client.DoJSON(ctx, http.MethodGet, "/status/", nil, nil, nil, nil)
	if err != nil {
		return nil, err
	}
	if !resp.Successful() {
		c.memo.Set(key, nil, time.Minute)
		return nil, nil
	}
	m, _ := resp.JSON().(map[string]any)
	if m == nil {
		c.memo.Set(key, nil, time.Minute)
		return nil, nil
	}
	st := &ServerStatus{}
	if players, ok := m["players"].(float64); ok {
		st.Players = int64(players)
	}
	if version, ok := m["server_version"].(string); ok {
		st.ServerVersion = version
	}
	if vip, ok := m["vip"].(bool); ok {
		st.VIP = vip
	}
	c.memo.Set(key, st, 5*time.Minute)
	return st, nil
}
