package esi

import (
	"context"
	"fmt"
	"github.com/sudo-adduser-jordan/eveindustry/internal/auth"
	"net/http"
	"strconv"
	"time"
)

type Credential struct {
	CharacterID    int64
	AccessToken    string
	RefreshToken   string
	TokenExpiresAt time.Time
	IsEsiActive    bool
}

type CredentialStore interface {
	Credentials(ctx context.Context, characterID int64) (Credential, bool, error)
	SaveTokens(ctx context.Context, characterID int64, access, refresh string, expiresAt time.Time) error
	Deactivate(ctx context.Context, characterID int64) error
}

type CharacterClient struct {
	client  *Client
	refresh *Refresher
	creds   CredentialStore
	memo    *Memo
	key     []byte
	Demo    *DemoResponder
}

func NewCharacterClient(client *Client, refresh *Refresher, creds CredentialStore, memo *Memo, appKey []byte) *CharacterClient {
	if memo == nil {
		memo = NewMemo()
	}
	return &CharacterClient{client: client, refresh: refresh, creds: creds, memo: memo, key: appKey}
}

func (c *CharacterClient) credential(ctx context.Context, characterID int64) (Auth, error) {
	cred, ok, err := c.creds.Credentials(ctx, characterID)
	if err != nil {
		return Auth{}, &RequestError{Outcome: OutcomeCoordination, RetryAfterSecs: 5}
	}
	if !ok || !cred.IsEsiActive {
		return Auth{}, &RequestError{Outcome: OutcomeCredential}
	}
	access := cred.AccessToken
	if IsLaravelEncrypted(access) {
		if len(c.key) < 32 {
			return Auth{}, &RequestError{Outcome: OutcomeCredential}
		}
		dec, err := DecryptLaravelToken(access, c.key)
		if err != nil {
			return Auth{}, &RequestError{Outcome: OutcomeCredential}
		}
		access = dec
	}
	if cred.TokenExpiresAt.IsZero() || cred.TokenExpiresAt.Before(time.Now().Add(30*time.Second)) {
		if c.refresh == nil {
			return Auth{}, &RequestError{Outcome: OutcomeCredential}
		}
		if err := c.refresh.RefreshCharacterToken(ctx, characterID, access, false); err != nil {
			return Auth{}, err
		}
		cred, ok, err = c.creds.Credentials(ctx, characterID)
		if err != nil || !ok {
			return Auth{}, &RequestError{Outcome: OutcomeCredential}
		}
		access = cred.AccessToken
	}
	return Auth{CharacterID: characterID, AccessToken: access}, nil
}

func (c *CharacterClient) authed(ctx context.Context, method, endpoint string, characterID int64, body any) (any, error) {
	if c.Demo != nil && auth.IsDemoID(characterID) {
		chars, _ := c.Demo.Chars(ctx)
		var ch *DemoCharacter
		for _, cc := range chars {
			if cc.ID == characterID {
				cc := cc
				ch = &cc
				break
			}
		}
		if ch != nil {
			status, payload, _ := c.Demo.Respond(ctx, method, endpoint, ch, nil, body)
			if status != 0 && payload != nil {
				if status >= 200 && status < 300 {
					return payload, nil
				}
				return nil, &RequestError{Outcome: OutcomeNotFound, Status: status}
			}
		}
		return nil, &RequestError{Outcome: OutcomeNotFound, Status: 404}
	}
	auth, err := c.credential(ctx, characterID)
	if err != nil {
		return nil, err
	}
	resp, err := c.client.DoJSON(ctx, method, endpoint, nil, body, nil, &auth)
	if err != nil {
		return nil, err
	}
	return resp.JSON(), nil
}

func (c *CharacterClient) GetCharacter(ctx context.Context, characterID int64) (map[string]any, error) {
	key := fmt.Sprintf("esi.character.%d", characterID)
	if v, ok := c.memo.Get(key); ok {
		return v.(map[string]any), nil
	}
	resp, err := c.client.DoJSON(ctx, http.MethodGet, fmt.Sprintf("/characters/%d/", characterID), nil, nil, nil, nil)
	if err != nil {
		return nil, err
	}
	data, _ := resp.JSON().(map[string]any)
	c.memo.Set(key, data, time.Hour)
	return data, nil
}

func (c *CharacterClient) GetAffiliation(ctx context.Context, characterID int64) (map[string]any, error) {
	key := fmt.Sprintf("esi.affiliation.%d", characterID)
	if v, ok := c.memo.Get(key); ok {
		if v == nil {
			return nil, nil
		}
		return v.(map[string]any), nil
	}
	resp, err := c.client.DoJSON(ctx, http.MethodPost, "/characters/affiliation/", nil, []int64{characterID}, nil, nil)
	if err != nil {
		return nil, err
	}
	if list, ok := resp.JSON().([]any); ok {
		for _, item := range list {
			if m, ok := item.(map[string]any); ok && int64Of(m["character_id"]) == characterID {
				c.memo.Set(key, m, time.Hour)
				return m, nil
			}
		}
	}
	return nil, nil
}

func (c *CharacterClient) GetSkills(ctx context.Context, characterID int64) (map[int]int, error) {
	key := fmt.Sprintf("esi.skills.%d", characterID)
	if v, ok := c.memo.Get(key); ok {
		return v.(map[int]int), nil
	}
	data, err := c.authed(ctx, http.MethodGet, fmt.Sprintf("/characters/%d/skills/", characterID), characterID, nil)
	if err != nil {
		if reqErr, ok := err.(*RequestError); ok && reqErr.Outcome == OutcomeCredential {
			return nil, nil
		}
		return nil, err
	}
	out := map[int]int{}
	if m, ok := data.(map[string]any); ok {
		if list, ok := m["skills"].([]any); ok {
			for _, s := range list {
				if sm, ok := s.(map[string]any); ok {
					out[intOf(sm["skill_id"])] = intOf(sm["trained_skill_level"])
				}
			}
		}
	}
	c.memo.Set(key, out, time.Hour)
	return out, nil
}

func (c *CharacterClient) Paginated(ctx context.Context, endpoint string, query map[string]string, characterID int64) ([]map[string]any, error) {
	if c.Demo != nil && auth.IsDemoID(characterID) {
		return c.demoPaginated(ctx, endpoint, query, characterID)
	}
	auth, err := c.credential(ctx, characterID)
	if err != nil {
		return nil, err
	}
	var all []map[string]any
	var expectedPages *int
	var lastModified string
	var firstSeen = true
	for page := 1; page <= 10000; page++ {
		q := map[string]string{"page": fmt.Sprintf("%d", page)}
		for k, v := range query {
			q[k] = v
		}
		resp, err := c.client.DoJSON(ctx, http.MethodGet, endpoint, q, nil, nil, &auth)
		if err != nil {
			return nil, err
		}
		list, ok := resp.JSON().([]any)
		if !ok {
			return nil, &RequestError{Outcome: OutcomeIncomplete, RetryAfterSecs: 30, Status: resp.Status}
		}
		if p := resp.Pages(); p > 0 {
			if p > 10000 {
				return nil, &RequestError{Outcome: OutcomeIncomplete, RetryAfterSecs: 30, Status: resp.Status}
			}
			if expectedPages != nil && *expectedPages != p {
				return nil, &RequestError{Outcome: OutcomeIncomplete, RetryAfterSecs: 30, Status: resp.Status}
			}
			if expectedPages == nil {
				expectedPages = &p
			}
		}
		if mod := resp.Headers.Get("Last-Modified"); mod != "" {
			if firstSeen {
				lastModified = mod
			} else if lastModified != "" && mod != lastModified {
				return nil, &RequestError{Outcome: OutcomeIncomplete, RetryAfterSecs: 30, Status: resp.Status}
			}
		}
		firstSeen = false
		for _, item := range list {
			if m, ok := item.(map[string]any); ok {
				all = append(all, m)
			} else {
				return nil, &RequestError{Outcome: OutcomeIncomplete, RetryAfterSecs: 30, Status: resp.Status}
			}
		}
		if expectedPages != nil {
			if page >= *expectedPages {
				return all, nil
			}
		} else if len(list) < 1000 {
			return all, nil
		}
	}
	return nil, &RequestError{Outcome: OutcomeIncomplete, RetryAfterSecs: 30}
}

func (c *CharacterClient) demoPaginated(ctx context.Context, endpoint string, query map[string]string, characterID int64) ([]map[string]any, error) {
	chars, _ := c.Demo.Chars(ctx)
	var ch *DemoCharacter
	for _, cc := range chars {
		if cc.ID == characterID {
			cc := cc
			ch = &cc
			break
		}
	}
	var all []map[string]any
	for page := 1; page <= 10000; page++ {
		q := map[string]string{"page": fmt.Sprintf("%d", page)}
		for k, v := range query {
			q[k] = v
		}
		status, payload, headers := c.Demo.Respond(ctx, http.MethodGet, endpoint, ch, q, nil)
		if status == 0 || payload == nil {
			return nil, &RequestError{Outcome: OutcomeNotFound, Status: 404}
		}
		if status < 200 || status >= 300 {
			return nil, &RequestError{Outcome: OutcomeNotFound, Status: status}
		}
		list, ok := payload.([]any)
		if !ok {
			return nil, &RequestError{Outcome: OutcomeIncomplete, RetryAfterSecs: 30, Status: status}
		}
		for _, item := range list {
			m, ok := item.(map[string]any)
			if !ok {
				return nil, &RequestError{Outcome: OutcomeIncomplete, RetryAfterSecs: 30, Status: status}
			}
			all = append(all, m)
		}
		pages := 1
		if headers != nil {
			if p, err := strconv.Atoi(headers["X-Pages"]); err == nil && p > 0 {
				pages = p
			}
		}
		if page >= pages {
			break
		}
	}
	if all == nil {
		all = []map[string]any{}
	}
	return all, nil
}

func (c *CharacterClient) GetAssets(ctx context.Context, characterID int64) ([]map[string]any, error) {
	key := fmt.Sprintf("esi.assets.%d", characterID)
	if v, ok := c.memo.Get(key); ok {
		return v.([]map[string]any), nil
	}
	all, err := c.Paginated(ctx, fmt.Sprintf("/characters/%d/assets/", characterID), nil, characterID)
	if err != nil {
		return nil, err
	}
	if len(all) > 0 {
		c.memo.Set(key, all, time.Hour)
	}
	return all, nil
}

func (c *CharacterClient) GetLocation(ctx context.Context, characterID int64) (map[string]any, error) {
	raw, err := c.authed(ctx, http.MethodGet, fmt.Sprintf("/characters/%d/location/", characterID), characterID, nil)
	if err != nil {
		return nil, err
	}
	m, ok := raw.(map[string]any)
	if !ok || len(m) == 0 {
		return nil, nil
	}
	return m, nil
}

func (c *CharacterClient) GetBlueprints(ctx context.Context, characterID int64) ([]map[string]any, error) {
	key := fmt.Sprintf("esi.blueprints.%d", characterID)
	if v, ok := c.memo.Get(key); ok {
		return v.([]map[string]any), nil
	}
	all, err := c.Paginated(ctx, fmt.Sprintf("/characters/%d/blueprints/", characterID), nil, characterID)
	if err != nil {
		return nil, err
	}
	c.memo.Set(key, all, time.Hour)
	return all, nil
}

func (c *CharacterClient) GetSkillqueue(ctx context.Context, characterID int64) ([]map[string]any, error) {
	key := fmt.Sprintf("esi.skillqueue.%d", characterID)
	if v, ok := c.memo.Get(key); ok {
		if v == nil {
			return nil, nil
		}
		return v.([]map[string]any), nil
	}
	raw, err := c.authed(ctx, http.MethodGet, fmt.Sprintf("/characters/%d/skillqueue/", characterID), characterID, nil)
	if err != nil {
		if reqErr, ok := err.(*RequestError); ok && reqErr.Outcome == OutcomeCredential {
			return nil, nil
		}
		return nil, err
	}
	list, _ := raw.([]any)
	out := make([]map[string]any, 0, len(list))
	for _, item := range list {
		if m, ok := item.(map[string]any); ok {
			out = append(out, m)
		}
	}
	c.memo.Set(key, out, time.Hour)
	return out, nil
}

func (c *CharacterClient) GetIndustryJobs(ctx context.Context, characterID int64, includeCompleted bool) ([]map[string]any, error) {
	key := fmt.Sprintf("esi.industry_jobs.%d.active", characterID)
	query := map[string]string{}
	if includeCompleted {
		key = fmt.Sprintf("esi.industry_jobs.%d.with_completed", characterID)
		query["include_completed"] = "true"
	}
	if v, ok := c.memo.Get(key); ok {
		return v.([]map[string]any), nil
	}
	all, err := c.Paginated(ctx, fmt.Sprintf("/characters/%d/industry/jobs/", characterID), query, characterID)
	if err != nil {
		return nil, err
	}
	c.memo.Set(key, all, 5*time.Minute)
	return all, nil
}

func (c *CharacterClient) GetCharacterRoles(ctx context.Context, characterID int64) (map[string]any, error) {
	key := fmt.Sprintf("esi.roles.%d", characterID)
	if v, ok := c.memo.Get(key); ok {
		if v == nil {
			return nil, nil
		}
		return v.(map[string]any), nil
	}
	data, err := c.authed(ctx, http.MethodGet, fmt.Sprintf("/characters/%d/roles/", characterID), characterID, nil)
	if err != nil {
		return nil, err
	}
	out, _ := data.(map[string]any)
	c.memo.Set(key, out, time.Hour)
	return out, nil
}

func (c *CharacterClient) GetCorporationBlueprints(ctx context.Context, characterID, corporationID int64) ([]map[string]any, error) {
	key := fmt.Sprintf("esi.corp_blueprints.%d", corporationID)
	if v, ok := c.memo.Get(key); ok {
		return v.([]map[string]any), nil
	}
	all, err := c.Paginated(ctx, fmt.Sprintf("/corporations/%d/blueprints/", corporationID), nil, characterID)
	if err != nil {
		return nil, err
	}
	c.memo.Set(key, all, time.Hour)
	return all, nil
}

func (c *CharacterClient) GetCorporationAssets(ctx context.Context, characterID, corporationID int64) ([]map[string]any, error) {
	key := fmt.Sprintf("esi.corp_assets.%d", corporationID)
	if v, ok := c.memo.Get(key); ok {
		return v.([]map[string]any), nil
	}
	all, err := c.Paginated(ctx, fmt.Sprintf("/corporations/%d/assets/", corporationID), nil, characterID)
	if err != nil {
		return nil, err
	}
	if len(all) > 0 {
		c.memo.Set(key, all, time.Hour)
	}
	return all, nil
}

func (c *CharacterClient) GetCorporationIndustryJobs(ctx context.Context, characterID int64, corporationID int64, includeCompleted bool) ([]map[string]any, error) {
	key := fmt.Sprintf("esi.corp_industry_jobs.%d.active", corporationID)
	query := map[string]string{}
	if includeCompleted {
		key = fmt.Sprintf("esi.corp_industry_jobs.%d.with_completed", corporationID)
		query["include_completed"] = "true"
	}
	if v, ok := c.memo.Get(key); ok {
		return v.([]map[string]any), nil
	}
	all, err := c.Paginated(ctx, fmt.Sprintf("/corporations/%d/industry/jobs/", corporationID), query, characterID)
	if err != nil {
		return nil, err
	}
	c.memo.Set(key, all, 5*time.Minute)
	return all, nil
}

func (c *CharacterClient) GetCharacterPlanets(ctx context.Context, characterID int64) ([]map[string]any, error) {
	key := fmt.Sprintf("esi.planets.%d", characterID)
	if v, ok := c.memo.Get(key); ok {
		if v == nil {
			return nil, nil
		}
		return v.([]map[string]any), nil
	}
	raw, err := c.authed(ctx, http.MethodGet, fmt.Sprintf("/characters/%d/planets/", characterID), characterID, nil)
	if err != nil {
		return nil, err
	}
	list, _ := raw.([]any)
	out := make([]map[string]any, 0, len(list))
	for _, item := range list {
		if m, ok := item.(map[string]any); ok {
			out = append(out, m)
		}
	}
	c.memo.Set(key, out, 10*time.Minute)
	return out, nil
}

func (c *CharacterClient) GetCharacterPlanetDetails(ctx context.Context, characterID int64, planetID int) (map[string]any, error) {
	key := fmt.Sprintf("esi.planet_details.%d.%d", characterID, planetID)
	if v, ok := c.memo.Get(key); ok {
		if v == nil {
			return nil, nil
		}
		return v.(map[string]any), nil
	}
	raw, err := c.authed(ctx, http.MethodGet, fmt.Sprintf("/characters/%d/planets/%d/", characterID, planetID), characterID, nil)
	if err != nil {
		return nil, err
	}
	m, _ := raw.(map[string]any)
	if m == nil {
		return nil, nil
	}
	c.memo.Set(key, m, 10*time.Minute)
	return m, nil
}

func (c *CharacterClient) ClearPlanetaryCache(characterID int64) {
	c.memo.Forget(fmt.Sprintf("esi.planets.%d", characterID))
}

func (c *CharacterClient) GetDynamicItem(ctx context.Context, typeID int, itemID int64, characterID int64) (map[string]any, error) {
	key := fmt.Sprintf("esi.dynamic_item.%d", itemID)
	if v, ok := c.memo.Get(key); ok {
		if v == nil {
			return nil, nil
		}
		return v.(map[string]any), nil
	}
	raw, err := c.authed(ctx, http.MethodGet, fmt.Sprintf("/dogma/dynamic/items/%d/%d/", typeID, itemID), characterID, nil)
	if err != nil {
		return nil, err
	}
	m, _ := raw.(map[string]any)
	if m == nil {
		return nil, nil
	}
	c.memo.Set(key, m, time.Hour)
	return m, nil
}

func (c *CharacterClient) GetCorporationAssetNames(ctx context.Context, characterID, corporationID int64, itemIDs []int64) (map[int64]string, error) {
	out := map[int64]string{}
	for i := 0; i < len(itemIDs); i += 1000 {
		end := i + 1000
		if end > len(itemIDs) {
			end = len(itemIDs)
		}
		data, err := c.authed(ctx, http.MethodPost, fmt.Sprintf("/corporations/%d/assets/names/", corporationID), characterID, itemIDs[i:end])
		if err != nil {
			return nil, err
		}
		if list, ok := data.([]any); ok {
			for _, item := range list {
				m, ok := item.(map[string]any)
				if !ok {
					continue
				}
				id, ok := m["item_id"].(float64)
				name, _ := m["name"].(string)
				if !ok || name == "" || name == "None" {
					continue
				}
				out[int64(id)] = name
			}
		}
	}
	return out, nil
}

func (c *CharacterClient) GetCorporationWallets(ctx context.Context, characterID, corporationID int64) ([]map[string]any, error) {
	key := fmt.Sprintf("esi.corp_wallets.%d", corporationID)
	if v, ok := c.memo.Get(key); ok {
		if v == nil {
			return nil, nil
		}
		return v.([]map[string]any), nil
	}
	raw, err := c.authed(ctx, http.MethodGet, fmt.Sprintf("/corporations/%d/wallets/", corporationID), characterID, nil)
	if err != nil {
		return nil, err
	}
	list, _ := raw.([]any)
	out := make([]map[string]any, 0, len(list))
	for _, item := range list {
		if m, ok := item.(map[string]any); ok {
			out = append(out, m)
		}
	}
	c.memo.Set(key, out, 5*time.Minute)
	return out, nil
}

func (c *CharacterClient) GetCorporationWalletJournal(ctx context.Context, characterID, corporationID int64, division int) ([]map[string]any, error) {
	key := fmt.Sprintf("esi.corp_wallet_journal.%d.%d", corporationID, division)
	if v, ok := c.memo.Get(key); ok {
		return v.([]map[string]any), nil
	}
	out, err := c.Paginated(ctx, fmt.Sprintf("/corporations/%d/wallets/%d/journal/", corporationID, division), nil, characterID)
	if err != nil {
		return nil, err
	}
	c.memo.Set(key, out, 5*time.Minute)
	return out, nil
}

func (c *CharacterClient) ClearCorporationWalletCache(corporationID int64) {
	c.memo.Forget(fmt.Sprintf("esi.corp_wallets.%d", corporationID))
	for d := 1; d <= 7; d++ {
		c.memo.Forget(fmt.Sprintf("esi.corp_wallet_journal.%d.%d", corporationID, d))
	}
}

func (c *CharacterClient) cursorProjects(ctx context.Context, characterID int64, endpoint, field, idField string, params map[string]string) ([]map[string]any, error) {
	auth, err := c.credential(ctx, characterID)
	if err != nil {
		return nil, err
	}
	byID := map[string]map[string]any{}
	seen := map[string]bool{}
	after := "0"
	for {
		if seen[after] {
			return nil, &RequestError{Outcome: OutcomeIncomplete, RetryAfterSecs: 30}
		}
		seen[after] = true
		q := map[string]string{"after": after, "limit": "100"}
		for k, v := range params {
			q[k] = v
		}
		resp, err := c.client.DoJSON(ctx, http.MethodGet, endpoint, q, nil, nil, &auth)
		if err != nil {
			return nil, err
		}
		data, ok := resp.JSON().(map[string]any)
		if !ok {
			return nil, &RequestError{Outcome: OutcomeIncomplete, RetryAfterSecs: 30}
		}
		var page []any
		if p, ok := data[field].([]any); ok {
			page = p
		} else if list, ok := resp.JSON().([]any); ok {
			page = list
		} else {
			return nil, &RequestError{Outcome: OutcomeIncomplete, RetryAfterSecs: 30}
		}
		for _, item := range page {
			m, ok := item.(map[string]any)
			if !ok {
				continue
			}
			id, _ := m[idField]
			if id == nil {
				id, _ = m["project_id"]
			}
			if id == nil {
				return nil, &RequestError{Outcome: OutcomeIncomplete, RetryAfterSecs: 30}
			}
			byID[fmt.Sprintf("%v", id)] = m
		}
		next := ""
		if cur, ok := data["cursor"].(map[string]any); ok {
			next, _ = cur["after"].(string)
		}
		if next == "" {
			break
		}
		after = next
	}
	out := make([]map[string]any, 0, len(byID))
	for _, m := range byID {
		out = append(out, m)
	}
	return out, nil
}

func (c *CharacterClient) GetCorporationProjects(ctx context.Context, characterID, corporationID int64) ([]map[string]any, error) {
	key := fmt.Sprintf("esi.corp_projects.%d", corporationID)
	if v, ok := c.memo.Get(key); ok {
		if v == nil {
			return nil, nil
		}
		return v.([]map[string]any), nil
	}
	out, err := c.cursorProjects(ctx, characterID,
		fmt.Sprintf("/corporations/%d/projects/", corporationID), "projects", "id",
		map[string]string{"state": "All"})
	if err != nil {
		return nil, err
	}
	c.memo.Set(key, out, 5*time.Minute)
	return out, nil
}

func (c *CharacterClient) GetCorporationProjectContributors(ctx context.Context, characterID, corporationID int64, projectID string) ([]map[string]any, error) {
	key := fmt.Sprintf("esi.corp_project_contributors.%d.%s", corporationID, projectID)
	if v, ok := c.memo.Get(key); ok {
		if v == nil {
			return nil, nil
		}
		return v.([]map[string]any), nil
	}
	out, err := c.cursorProjects(ctx, characterID,
		fmt.Sprintf("/corporations/%d/projects/%s/contributors/", corporationID, projectID),
		"contributors", "character_id", nil)
	if err != nil {
		return nil, err
	}
	c.memo.Set(key, out, 5*time.Minute)
	return out, nil
}

func (c *CharacterClient) ClearCorporationProjectsCache(corporationID int64) {
	c.memo.Forget(fmt.Sprintf("esi.corp_projects.%d", corporationID))
}

func (c *CharacterClient) ClearCorporationProjectContributorsCache(corporationID int64, projectID string) {
	c.memo.Forget(fmt.Sprintf("esi.corp_project_contributors.%d.%s", corporationID, projectID))
}

func (c *CharacterClient) GetAssetNames(ctx context.Context, characterID int64, itemIDs []int64) (map[int64]string, error) {
	out := map[int64]string{}
	for i := 0; i < len(itemIDs); i += 1000 {
		end := i + 1000
		if end > len(itemIDs) {
			end = len(itemIDs)
		}
		data, err := c.authed(ctx, http.MethodPost, fmt.Sprintf("/characters/%d/assets/names/", characterID), characterID, itemIDs[i:end])
		if err != nil {
			return nil, err
		}
		if list, ok := data.([]any); ok {
			for _, item := range list {
				if m, ok := item.(map[string]any); ok {
					name, _ := m["name"].(string)
					if name != "" && name != "None" {
						out[int64Of(m["item_id"])] = name
					}
				}
			}
		}
	}
	return out, nil
}

func (c *CharacterClient) ClearCache(characterID int64) {
	c.memo.Forget(fmt.Sprintf("esi.character.%d", characterID))
	c.memo.Forget(fmt.Sprintf("esi.skills.%d", characterID))
	c.memo.Forget(fmt.Sprintf("esi.industry_jobs.%d.active", characterID))
	c.memo.Forget(fmt.Sprintf("esi.industry_jobs.%d.with_completed", characterID))
	c.memo.Forget(fmt.Sprintf("esi.industry_jobs.%d", characterID))
	c.memo.Forget(fmt.Sprintf("esi.blueprints.%d", characterID))
	c.memo.Forget(fmt.Sprintf("esi.assets.%d", characterID))
	c.memo.Forget(fmt.Sprintf("esi.roles.%d", characterID))
}

func intOf(v any) int {
	switch n := v.(type) {
	case int:
		return n
	case int64:
		return int(n)
	case float64:
		return int(n)
	}
	return 0
}

func int64Of(v any) int64 {
	switch n := v.(type) {
	case int:
		return int64(n)
	case int64:
		return n
	case float64:
		return int64(n)
	}
	return 0
}
