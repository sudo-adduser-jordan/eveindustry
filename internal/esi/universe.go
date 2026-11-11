package esi

import (
	"context"
	"fmt"
	"github.com/sudo-adduser-jordan/eveindustry/internal/auth"
	"net/http"
	"strconv"
	"time"
)

type UniverseClient struct {
	client *Client
	creds  CredentialStore
	memo   *Memo
	types  TypeStore
	Demo   *DemoResponder
}

type TypeStore interface {
	EveTypeByID(ctx context.Context, typeID int) (*LocalType, error)
	SdeStationByID(ctx context.Context, stationID uint64) (*LocalStation, error)
}

type LocalType struct {
	TypeID         int
	Name           string
	Description    string
	GroupID        *int64
	MarketGroupID  *int64
	Volume         *float64
	PackagedVolume *float64
	Mass           *float64
	Capacity       *float64
	PortionSize    *int
	IconID         *int
	GraphicID      *int
	Published      bool
}

type LocalStation struct {
	StationID     uint64
	Name          string
	SolarSystemID *int64
}

func NewUniverseClient(client *Client, creds CredentialStore, memo *Memo, types TypeStore) *UniverseClient {
	if memo == nil {
		memo = NewMemo()
	}
	return &UniverseClient{client: client, creds: creds, memo: memo, types: types}
}

func (c *UniverseClient) GetTypeInfo(ctx context.Context, typeID int) (map[string]any, error) {
	if c.types != nil {
		if local, err := c.types.EveTypeByID(ctx, typeID); err == nil && local != nil {
			return map[string]any{
				"type_id": local.TypeID, "name": local.Name,
				"description": local.Description, "group_id": local.GroupID,
				"market_group_id": local.MarketGroupID, "volume": local.Volume,
				"packaged_volume": local.PackagedVolume, "mass": local.Mass,
				"capacity": local.Capacity, "portion_size": local.PortionSize,
				"icon_id": local.IconID, "graphic_id": local.GraphicID,
				"published": local.Published,
			}, nil
		}
	}
	key := fmt.Sprintf("esi.type.%d", typeID)
	if v, ok := c.memo.Get(key); ok {
		if v == nil {
			return nil, nil
		}
		return v.(map[string]any), nil
	}
	resp, err := c.client.DoJSON(ctx, http.MethodGet, fmt.Sprintf("/universe/types/%d/", typeID), nil, nil, nil, nil)
	if err != nil {
		return nil, err
	}
	if !resp.Successful() {
		return nil, nil
	}
	data, _ := resp.JSON().(map[string]any)
	c.memo.Set(key, data, 30*24*time.Hour)
	return data, nil
}

func (c *UniverseClient) imageOK(ctx context.Context, url string) bool {
	req, err := http.NewRequestWithContext(ctx, http.MethodHead, url, nil)
	if err != nil {
		return false
	}
	req.Header.Set("User-Agent", "Mozilla/5.0 (compatible; ESI-Image-Checker/1.0)")
	hresp, err := c.client.HTTP.Do(req)
	if err == nil {
		hresp.Body.Close()
		if hresp.StatusCode >= 200 && hresp.StatusCode < 300 {
			return true
		}
		if hresp.StatusCode != 405 && hresp.StatusCode != 501 {
		}
	}
	get, err := http.NewRequestWithContext(ctx, http.MethodGet, url, nil)
	if err != nil {
		return false
	}
	get.Header.Set("User-Agent", "Mozilla/5.0 (compatible; ESI-Image-Checker/1.0)")
	gresp, err := c.client.HTTP.Do(get)
	if err != nil {
		return false
	}
	gresp.Body.Close()
	return gresp.StatusCode >= 200 && gresp.StatusCode < 300
}

func (c *UniverseClient) GetBestTypeImageUrl(ctx context.Context, typeID int, isBlueprint bool) string {
	key := fmt.Sprintf("esi.type_image_best.%d", typeID)
	if v, ok := c.memo.Get(key); ok {
		if v == nil {
			return ""
		}
		return v.(string)
	}
	styles := []struct {
		style string
		px    int
	}{
		{"icon", 64}, {"bp", 64}, {"bpc", 64}, {"render", 512}, {"icon", 32},
	}
	if isBlueprint {
		styles = []struct {
			style string
			px    int
		}{{"bp", 64}, {"bpc", 64}, {"render", 512}, {"icon", 64}, {"icon", 32}}
	}
	for _, s := range styles {
		url := fmt.Sprintf("https://images.evetech.net/types/%d/%s?size=%d", typeID, s.style, s.px)
		if c.imageOK(ctx, url) {
			c.memo.Set(key, url, 30*24*time.Hour)
			return url
		}
	}
	c.memo.Set(key, nil, 30*24*time.Hour)
	return ""
}

func (c *UniverseClient) GetStation(ctx context.Context, stationID uint64) (map[string]any, error) {
	key := fmt.Sprintf("esi.station.%d", stationID)
	if v, ok := c.memo.Get(key); ok {
		if v == nil {
			return nil, nil
		}
		return v.(map[string]any), nil
	}
	if c.types != nil {
		if local, err := c.types.SdeStationByID(ctx, stationID); err == nil && local != nil && local.Name != "" {
			out := map[string]any{"station_id": local.StationID, "name": local.Name, "solar_system_id": local.SolarSystemID}
			c.memo.Set(key, out, 30*24*time.Hour)
			return out, nil
		}
	}
	resp, err := c.client.DoJSON(ctx, http.MethodGet, fmt.Sprintf("/universe/stations/%d/", stationID), nil, nil, nil, nil)
	if err != nil {
		return nil, err
	}
	if !resp.Successful() {
		return nil, nil
	}
	data, _ := resp.JSON().(map[string]any)
	c.memo.Set(key, data, 30*24*time.Hour)
	return data, nil
}

func (c *UniverseClient) GetSystem(ctx context.Context, systemID int) (map[string]any, error) {
	key := fmt.Sprintf("esi.system.%d", systemID)
	if v, ok := c.memo.Get(key); ok {
		if v == nil {
			return nil, nil
		}
		return v.(map[string]any), nil
	}
	resp, err := c.client.DoJSON(ctx, http.MethodGet, fmt.Sprintf("/universe/systems/%d/", systemID), nil, nil, nil, nil)
	if err != nil {
		return nil, err
	}
	if !resp.Successful() {
		return nil, nil
	}
	data, _ := resp.JSON().(map[string]any)
	c.memo.Set(key, data, 30*24*time.Hour)
	return data, nil
}

func (c *UniverseClient) GetStructure(ctx context.Context, structureID uint64, characterID int64) (map[string]any, error) {
	key := fmt.Sprintf("esi.structure.%d.char.%d", structureID, characterID)
	if v, ok := c.memo.Get(key); ok {
		if v == nil {
			return nil, nil
		}
		return v.(map[string]any), nil
	}
	cred, ok, err := c.creds.Credentials(ctx, characterID)
	if err != nil {
		return nil, &RequestError{Outcome: OutcomeCoordination, RetryAfterSecs: 5}
	}
	if !ok || !cred.IsEsiActive {
		return nil, &RequestError{Outcome: OutcomeCredential}
	}
	auth := Auth{CharacterID: characterID, AccessToken: cred.AccessToken}
	resp, err := c.client.DoJSON(ctx, http.MethodGet, fmt.Sprintf("/universe/structures/%d/", structureID), nil, nil, nil, &auth)
	if err != nil {
		return nil, err
	}
	if !resp.Successful() {
		c.memo.Set(key, nil, 24*time.Hour)
		return nil, nil
	}
	data, _ := resp.JSON().(map[string]any)
	c.memo.Set(key, data, 24*time.Hour)
	return data, nil
}

func (c *UniverseClient) GetPlanetarySchematic(ctx context.Context, schematicID int) (map[string]any, error) {
	key := fmt.Sprintf("esi.schematic.%d", schematicID)
	if v, ok := c.memo.Get(key); ok {
		if v == nil {
			return nil, nil
		}
		return v.(map[string]any), nil
	}
	resp, err := c.client.DoJSON(ctx, http.MethodGet, fmt.Sprintf("/universe/schematics/%d/", schematicID), nil, nil, nil, nil)
	if err != nil {
		return nil, err
	}
	m, _ := resp.JSON().(map[string]any)
	if m == nil {
		return nil, nil
	}
	c.memo.Set(key, m, 30*24*time.Hour)
	return m, nil
}

func (c *UniverseClient) GetPlanetInfo(ctx context.Context, planetID int) (map[string]any, error) {
	if c.Demo != nil && auth.IsDemoID(int64(planetID)) {
		return c.Demo.planetInfo(ctx, int64(planetID)), nil
	}
	key := fmt.Sprintf("esi.planet_info.%d", planetID)
	if v, ok := c.memo.Get(key); ok {
		if v == nil {
			return nil, nil
		}
		return v.(map[string]any), nil
	}
	resp, err := c.client.DoJSON(ctx, http.MethodGet, fmt.Sprintf("/universe/planets/%d/", planetID), nil, nil, nil, nil)
	if err != nil {
		return nil, err
	}
	m, _ := resp.JSON().(map[string]any)
	if m == nil {
		return nil, nil
	}
	c.memo.Set(key, m, 30*24*time.Hour)
	return m, nil
}

func (c *UniverseClient) GetDogmaAttribute(ctx context.Context, attributeID int) (map[string]any, error) {
	key := fmt.Sprintf("esi.dogma.attribute.%d", attributeID)
	if v, ok := c.memo.Get(key); ok {
		if v == nil {
			return nil, nil
		}
		return v.(map[string]any), nil
	}
	resp, err := c.client.DoJSON(ctx, http.MethodGet, fmt.Sprintf("/dogma/attributes/%d/", attributeID), nil, nil, nil, nil)
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

func (c *UniverseClient) DogmaTypeAttributes(ctx context.Context, typeID int) (map[int]float64, error) {
	key := fmt.Sprintf("esi.dogma.type.%d", typeID)
	if v, ok := c.memo.Get(key); ok {
		if v == nil {
			return nil, nil
		}
		return v.(map[int]float64), nil
	}
	resp, err := c.client.DoJSON(ctx, http.MethodGet, fmt.Sprintf("/universe/types/%d/", typeID), nil, nil, nil, nil)
	if err != nil {
		return nil, err
	}
	out := map[int]float64{}
	if resp.Successful() {
		if m, ok := resp.JSON().(map[string]any); ok {
			if list, ok := m["dogma_attributes"].([]any); ok {
				for _, item := range list {
					am, ok := item.(map[string]any)
					if !ok {
						continue
					}
					id, _ := am["attribute_id"].(float64)
					val, _ := am["value"].(float64)
					if id != 0 {
						out[int(id)] = val
					}
				}
			}
		}
	}
	c.memo.Set(key, out, 30*24*time.Hour)
	return out, nil
}

func (c *UniverseClient) GetCorporation(ctx context.Context, corporationID int64) (map[string]any, error) {
	key := fmt.Sprintf("esi.corporation.%d", corporationID)
	if v, ok := c.memo.Get(key); ok {
		if v == nil {
			return nil, nil
		}
		return v.(map[string]any), nil
	}
	resp, err := c.client.DoJSON(ctx, http.MethodGet, fmt.Sprintf("/corporations/%d/", corporationID), nil, nil, nil, nil)
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

func (c *UniverseClient) GetAlliance(ctx context.Context, allianceID int64) (map[string]any, error) {
	key := fmt.Sprintf("esi.alliance.%d", allianceID)
	if v, ok := c.memo.Get(key); ok {
		if v == nil {
			return nil, nil
		}
		return v.(map[string]any), nil
	}
	resp, err := c.client.DoJSON(ctx, http.MethodGet, fmt.Sprintf("/alliances/%d/", allianceID), nil, nil, nil, nil)
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

type UniverseTypePage struct {
	TypeIDs    []int
	TotalPages int
}

func (c *UniverseClient) GetUniverseTypeIDs(ctx context.Context, page int) ([]int, int, error) {
	q := map[string]string{"page": strconv.Itoa(page)}
	resp, err := c.client.DoJSON(ctx, http.MethodGet, "/universe/types/", q, nil, nil, nil)
	if err != nil {
		return nil, 0, err
	}
	if !resp.Successful() {
		return nil, 0, nil
	}
	pages := resp.Pages()
	list, _ := resp.JSON().([]any)
	out := make([]int, 0, len(list))
	for _, item := range list {
		if n, ok := item.(float64); ok {
			out = append(out, int(n))
		}
	}
	return out, pages, nil
}
