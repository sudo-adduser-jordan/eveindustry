package esi

import (
	"context"
	"fmt"
	"net/http"
	"time"
)

// Credential is one character's ESI credential as the client needs it.
type Credential struct {
	CharacterID    int64
	AccessToken    string
	RefreshToken   string
	TokenExpiresAt time.Time
	IsEsiActive    bool
}

// CredentialStore loads credentials and persists rotations. SQL lands in
// the store package; tests stub it.
type CredentialStore interface {
	Credentials(ctx context.Context, characterID int64) (Credential, bool, error)
	SaveTokens(ctx context.Context, characterID int64, access, refresh string, expiresAt time.Time) error
	Deactivate(ctx context.Context, characterID int64) error
}

// CharacterClient ports CharacterClient: public profile/affiliation plus
// authenticated skills/roles/blueprints/assets/names/location/skillqueue,
// with the same 1h caches (except empty asset snapshots, which are never
// cached so a transient failure cannot lock a character into emptiness).
type CharacterClient struct {
	client  *Client
	refresh *Refresher
	creds   CredentialStore
	memo    *Memo
	key     []byte // APP_KEY bytes for encrypted-at-rest tokens; nil = raw only
}

// NewCharacterClient builds the client. key may be nil when tokens are
// stored raw; encrypted payloads then fail closed like the PHP cast.
func NewCharacterClient(client *Client, refresh *Refresher, creds CredentialStore, memo *Memo, appKey []byte) *CharacterClient {
	if memo == nil {
		memo = NewMemo()
	}
	return &CharacterClient{client: client, refresh: refresh, creds: creds, memo: memo, key: appKey}
}

// credential resolves a live bearer: inactive or missing characters fail
// closed (credential outcome, mirroring the is_esi_active guard), expiring
// tokens rotate through the refresher first.
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

// GetCharacter ports getCharacter(): public profile, 1h cache.
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

// GetAffiliation ports getCharacterAffiliation(): POST ids, 1h cache.
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

// GetSkills ports getCharacterSkills(): skill_id → trained level, 1h cache.
// A nil map with nil error means the credential is dead (caller substitutes
// zeros, like the PHP skillMap default).
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

// Paginated ports authenticatedPaginatedRequest(): complete lists only.
// Guards: non-list JSON, bad/changed X-Pages, Last-Modified drift and the
// 10000-page safety cap all fail as incomplete, never a prefix. query
// carries extra params like include_completed=true.
func (c *CharacterClient) Paginated(ctx context.Context, endpoint string, query map[string]string, characterID int64) ([]map[string]any, error) {
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

// GetAssets ports getCharacterAssets(): full snapshot, 1h cache; empty
// results are never cached.
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

// GetLocation ports fetchPilotCurrentPosition(): the character's current
// solar system, never cached (the caller stores the name). A nil map with
// nil error means ESI has no position (docked?) — the caller 502s.
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

// GetBlueprints ports getCharacterBlueprints(): full snapshot, 1h cache.
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

// GetIndustryJobs ports getCharacterIndustryJobs(): active jobs (or with
// completed), 5 minute cache. Scope/active gating is the caller's job.
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

// GetCharacterRoles ports getCharacterRoles(): the roles object, 1h memo.
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

// GetCorporationBlueprints ports getCorporationBlueprints(): full snapshot,
// 1h memo.
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

// GetCorporationAssets ports getCorporationAssets(): full snapshot, 1h memo;
// empty results are never cached.
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

// GetCorporationIndustryJobs ports getCorporationIndustryJobs(): corp jobs
// started by the corp, 5 minute cache. Eligibility gating is the caller's job.
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

// GetCorporationAssetNames ports getCorporationAssetNames(): POST chunks of
// 1000 against the corporation endpoint, skipping blank/'None' names.
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

// GetCorporationWallets ports getCorporationWallets(): division balances,
// 5-minute memo. A nil slice with nil error means the provider may not
// collect (eligibility handled by the caller).
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

// GetCorporationWalletJournal ports getCorporationWalletJournal(): journal
// entries for one division, 5-minute memo.
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

// ClearCorporationWalletCache drops memoized wallets + journals for a corp.
func (c *CharacterClient) ClearCorporationWalletCache(corporationID int64) {
	c.memo.Forget(fmt.Sprintf("esi.corp_wallets.%d", corporationID))
	for d := 1; d <= 7; d++ {
		c.memo.Forget(fmt.Sprintf("esi.corp_wallet_journal.%d.%d", corporationID, d))
	}
}

// GetAssetNames ports getCharacterAssetNames(): POST chunks of 1000,
// skipping blank/'None' names.
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

// ClearCache ports clearCharacterCache().
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
