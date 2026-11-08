package esi

import (
	"context"
	"fmt"
	"net/http"
	"strconv"
	"time"
)

// Region page statuses mirroring RegionOrdersPage.
const (
	RegionPageOK          = "ok"
	RegionPageNotModified = "not_modified"
	RegionPageMissing     = "missing"
	RegionPageRateLimited = "rate_limited"
	RegionPageFailed      = "failed"
)

// RegionOrdersPage is one fetched region-orders page.
type RegionOrdersPage struct {
	Status     string
	Orders     []map[string]any
	Pages      int
	ETag       string
	HTTPStatus int
	RetryAfter int
}

// Successful mirrors isSuccessful(): ok, not_modified and missing pages all
// advance the sweep.
func (p RegionOrdersPage) Successful() bool {
	return p.Status == RegionPageOK || p.Status == RegionPageNotModified || p.Status == RegionPageMissing
}

// MarketClient ports the ESI MarketClient: global prices, region order pages,
// type orders, history, cost indices and market groups. The DB-backed reads
// (all-orders, best prices, stored history) already live in the store
// package; this client only talks ESI with the PHP memo TTLs.
type MarketClient struct {
	client *Client
	memo   *Memo
}

// NewMarketClient builds the client; memo must not be nil in production.
func NewMarketClient(client *Client, memo *Memo) *MarketClient {
	if memo == nil {
		memo = NewMemo()
	}
	return &MarketClient{client: client, memo: memo}
}

// GetMarketPrices ports getMarketPrices(): global adjusted prices, 24h memo.
func (c *MarketClient) GetMarketPrices(ctx context.Context) ([]map[string]any, error) {
	const key = "esi.market.prices"
	if v, ok := c.memo.Get(key); ok {
		if v == nil {
			return nil, nil
		}
		return v.([]map[string]any), nil
	}
	resp, err := c.client.DoJSON(ctx, http.MethodGet, "/markets/prices/", nil, nil, nil, nil)
	if err != nil {
		return nil, err
	}
	if !resp.Successful() {
		return nil, nil
	}
	list, _ := resp.JSON().([]any)
	out := make([]map[string]any, 0, len(list))
	for _, item := range list {
		if m, ok := item.(map[string]any); ok {
			out = append(out, m)
		}
	}
	c.memo.Set(key, out, 24*time.Hour)
	return out, nil
}

// FetchRegionOrdersPage ports fetchRegionOrdersPage(): one all-orders page
// with ETag revalidation. Never throws: budget outcomes map to RATE_LIMITED
// (with the retry delay), everything else to the page status.
func (c *MarketClient) FetchRegionOrdersPage(ctx context.Context, regionID, page int, etag string) RegionOrdersPage {
	headers := map[string]string{}
	if etag != "" {
		headers["If-None-Match"] = etag
	}
	resp, err := c.client.DoJSON(ctx, http.MethodGet, fmt.Sprintf("/markets/%d/orders/", regionID),
		map[string]string{"order_type": "all", "page": strconv.Itoa(page)}, nil, headers, nil)
	if err != nil {
		if reqErr, ok := err.(*RequestError); ok {
			switch reqErr.Outcome {
			case OutcomeRateLimited, OutcomeErrorLimited, OutcomeCoordination:
				retry := reqErr.RetryAfterSecs
				if retry < 1 {
					retry = 1
				}
				return RegionOrdersPage{Status: RegionPageRateLimited, RetryAfter: retry, HTTPStatus: reqErr.Status}
			}
		}
		return RegionOrdersPage{Status: RegionPageFailed}
	}
	pages := resp.Pages()
	if pages < 1 {
		pages = 1
	}
	newEtag := resp.Headers.Get("ETag")
	if newEtag == "" {
		newEtag = etag
	}
	if resp.Status == http.StatusNotModified {
		return RegionOrdersPage{Status: RegionPageNotModified, Pages: pages, ETag: newEtag, HTTPStatus: 304}
	}
	if resp.Status == http.StatusNotFound && page > 1 {
		return RegionOrdersPage{Status: RegionPageMissing, Pages: pages, HTTPStatus: 404}
	}
	if !resp.Successful() {
		return RegionOrdersPage{Status: RegionPageFailed, HTTPStatus: resp.Status}
	}
	list, ok := resp.JSON().([]any)
	if !ok {
		return RegionOrdersPage{Status: RegionPageFailed, HTTPStatus: resp.Status}
	}
	orders := make([]map[string]any, 0, len(list))
	for _, item := range list {
		if m, ok := item.(map[string]any); ok {
			orders = append(orders, m)
		}
	}
	return RegionOrdersPage{Status: RegionPageOK, Orders: orders, Pages: pages, ETag: newEtag, HTTPStatus: resp.Status}
}

// GetMarketRegionOrders ports getMarketRegionOrders(): one type's orders,
// 2h memo.
func (c *MarketClient) GetMarketRegionOrders(ctx context.Context, regionID, typeID int, orderType string) ([]map[string]any, error) {
	key := fmt.Sprintf("esi.market.orders.%d.%d.%s", regionID, typeID, orderType)
	if v, ok := c.memo.Get(key); ok {
		if v == nil {
			return nil, nil
		}
		return v.([]map[string]any), nil
	}
	resp, err := c.client.DoJSON(ctx, http.MethodGet, fmt.Sprintf("/markets/%d/orders/", regionID),
		map[string]string{"type_id": strconv.Itoa(typeID), "order_type": orderType}, nil, nil, nil)
	if err != nil {
		return nil, err
	}
	if !resp.Successful() {
		return nil, nil
	}
	list, _ := resp.JSON().([]any)
	out := make([]map[string]any, 0, len(list))
	for _, item := range list {
		if m, ok := item.(map[string]any); ok {
			out = append(out, m)
		}
	}
	c.memo.Set(key, out, 2*time.Hour)
	return out, nil
}

// FetchMarketRegionHistory ports fetchMarketRegionHistoryFromEsi(): daily
// history for one type, 6h memo.
func (c *MarketClient) FetchMarketRegionHistory(ctx context.Context, regionID, typeID int) ([]map[string]any, error) {
	key := fmt.Sprintf("esi.market.history.%d.%d", regionID, typeID)
	if v, ok := c.memo.Get(key); ok {
		if v == nil {
			return nil, nil
		}
		return v.([]map[string]any), nil
	}
	resp, err := c.client.DoJSON(ctx, http.MethodGet, fmt.Sprintf("/markets/%d/history/", regionID),
		map[string]string{"type_id": strconv.Itoa(typeID)}, nil, nil, nil)
	if err != nil {
		return nil, err
	}
	if !resp.Successful() {
		return nil, nil
	}
	list, _ := resp.JSON().([]any)
	out := make([]map[string]any, 0, len(list))
	for _, item := range list {
		if m, ok := item.(map[string]any); ok {
			out = append(out, m)
		}
	}
	c.memo.Set(key, out, 6*time.Hour)
	return out, nil
}

// GetIndustrySystemCostIndices ports getIndustrySystemCostIndices(): 6h memo.
func (c *MarketClient) GetIndustrySystemCostIndices(ctx context.Context) ([]map[string]any, error) {
	const key = "esi.industry.systems"
	if v, ok := c.memo.Get(key); ok {
		if v == nil {
			return nil, nil
		}
		return v.([]map[string]any), nil
	}
	resp, err := c.client.DoJSON(ctx, http.MethodGet, "/industry/systems/", nil, nil, nil, nil)
	if err != nil {
		return nil, err
	}
	if !resp.Successful() {
		return nil, nil
	}
	list, _ := resp.JSON().([]any)
	out := make([]map[string]any, 0, len(list))
	for _, item := range list {
		if m, ok := item.(map[string]any); ok {
			out = append(out, m)
		}
	}
	c.memo.Set(key, out, 6*time.Hour)
	return out, nil
}

// GetMarketGroupIDs ports getMarketGroupIds(): 30d memo.
func (c *MarketClient) GetMarketGroupIDs(ctx context.Context) ([]int, error) {
	const key = "esi.market_group_ids"
	if v, ok := c.memo.Get(key); ok {
		if v == nil {
			return nil, nil
		}
		return v.([]int), nil
	}
	resp, err := c.client.DoJSON(ctx, http.MethodGet, "/markets/groups/", nil, nil, nil, nil)
	if err != nil {
		return nil, err
	}
	if !resp.Successful() {
		return nil, nil
	}
	list, _ := resp.JSON().([]any)
	out := make([]int, 0, len(list))
	for _, item := range list {
		if n, ok := item.(float64); ok {
			out = append(out, int(n))
		}
	}
	c.memo.Set(key, out, 30*24*time.Hour)
	return out, nil
}

// GetMarketGroupInfo ports getMarketGroupInfo(): 30d memo.
func (c *MarketClient) GetMarketGroupInfo(ctx context.Context, groupID int) (map[string]any, error) {
	key := fmt.Sprintf("esi.market_group.%d", groupID)
	if v, ok := c.memo.Get(key); ok {
		if v == nil {
			return nil, nil
		}
		return v.(map[string]any), nil
	}
	resp, err := c.client.DoJSON(ctx, http.MethodGet, fmt.Sprintf("/markets/groups/%d/", groupID), nil, nil, nil, nil)
	if err != nil {
		return nil, err
	}
	if !resp.Successful() {
		return nil, nil
	}
	m, _ := resp.JSON().(map[string]any)
	if m == nil {
		return nil, nil
	}
	c.memo.Set(key, m, 30*24*time.Hour)
	return m, nil
}
