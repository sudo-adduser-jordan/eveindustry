package esi

import (
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"strings"
	"time"
)

// TokenPair is one rotated credential.
type TokenPair struct {
	AccessToken  string
	RefreshToken string
	ExpiresIn    int
}

// TokenStore persists rotated credentials and deactivations. The SQL
// implementation lands with the character store slice; tests use MemTokens.
type TokenStore interface {
	// Current returns the stored credential, or false when absent.
	Current(ctx context.Context, characterID int64) (access, refresh string, expiresAt time.Time, ok bool)
	// Rotate stores a refreshed pair with its new expiry.
	Rotate(ctx context.Context, characterID int64, pair TokenPair) error
	// Deactivate marks the character ESI-inactive (invalid_grant path).
	Deactivate(ctx context.Context, characterID int64) error
	// CooldownUntil returns when SSO retries may resume, or false.
	CooldownUntil(ctx context.Context, characterID int64) (time.Time, bool)
	// SetCooldown pauses SSO attempts until t.
	SetCooldown(ctx context.Context, characterID int64, t time.Time) error
}

// Refresher ports EsiAuthClient: SSO token rotation with a per-character
// lock (a waiting worker uses the winner's credential) and cooldowns.
type Refresher struct {
	store  CoordStore
	tokens TokenStore
	http   *http.Client
	base   string
	client string
	secret string
}

// NewRefresher builds a refresher against the SSO login host.
func NewRefresher(store CoordStore, tokens TokenStore, httpClient *http.Client, clientID, clientSecret string) *Refresher {
	return &Refresher{store: store, tokens: tokens, http: httpClient, base: LoginBase, client: clientID, secret: clientSecret}
}

func (r *Refresher) request(ctx context.Context, form url.Values) (map[string]any, int, error) {
	req, err := http.NewRequestWithContext(ctx, http.MethodPost, r.base+"/v2/oauth/token", strings.NewReader(form.Encode()))
	if err != nil {
		return nil, 0, err
	}
	req.Header.Set("Content-Type", "application/x-www-form-urlencoded")
	resp, err := r.http.Do(req)
	if err != nil {
		return nil, 0, err
	}
	defer resp.Body.Close()
	raw, _ := io.ReadAll(io.LimitReader(resp.Body, 1<<20))
	var data map[string]any
	_ = json.Unmarshal(raw, &data)
	if data == nil {
		data = map[string]any{}
	}
	return data, resp.StatusCode, nil
}

// RefreshToken ports refreshTokenOrFail(): transient on transport trouble,
// credential on invalid_grant, else the token payload.
func (r *Refresher) RefreshToken(ctx context.Context, refreshToken string) (TokenPair, error) {
	form := url.Values{
		"grant_type":    {"refresh_token"},
		"refresh_token": {refreshToken},
		"client_id":     {r.client},
		"client_secret": {r.secret},
	}
	data, status, err := r.request(ctx, form)
	if err != nil {
		return TokenPair{}, &RequestError{Outcome: OutcomeTransient, RetryAfterSecs: 60}
	}
	if status < 200 || status >= 300 {
		if status == 400 && data["error"] == "invalid_grant" {
			return TokenPair{}, &RequestError{Outcome: OutcomeCredential, Status: status}
		}
		return TokenPair{}, &RequestError{Outcome: OutcomeTransient, RetryAfterSecs: 60, Status: status}
	}
	access, _ := data["access_token"].(string)
	if access == "" {
		return TokenPair{}, &RequestError{Outcome: OutcomeTransient, RetryAfterSecs: 60, Status: status}
	}
	expires := 1199
	switch v := data["expires_in"].(type) {
	case float64:
		expires = int(v)
	case int:
		expires = v
	}
	refresh, _ := data["refresh_token"].(string)
	return TokenPair{AccessToken: access, RefreshToken: refresh, ExpiresIn: expires}, nil
}

// RefreshCharacterToken ports refreshCharacterTokenOrFail(): rotates when the
// stored access token is stale (or differs, meaning another worker won),
// honors SSO cooldowns, and deactivates on invalid_grant. force rotates even
// a fresh token (the post-401 retry path).
func (r *Refresher) RefreshCharacterToken(ctx context.Context, characterID int64, priorAccess string, force bool) error {
	key := fmt.Sprintf("esi.refresh.%d", characterID)
	return r.store.Lock(key, 45*time.Second, 5*time.Second, func() error {
		access, refresh, expiresAt, ok := r.tokens.Current(ctx, characterID)
		if access != priorAccess || (!force && ok && expiresAt.After(time.Now().Add(30*time.Second))) {
			return nil
		}
		if !ok || refresh == "" {
			return &RequestError{Outcome: OutcomeCredential}
		}
		if until, limited := r.tokens.CooldownUntil(ctx, characterID); limited && time.Now().Before(until) {
			return &RequestError{Outcome: OutcomeTransient, RetryAfterSecs: int(time.Until(until).Seconds()) + 1}
		}
		pair, err := r.RefreshToken(ctx, refresh)
		if err != nil {
			if re, isReq := err.(*RequestError); isReq && re.Outcome == OutcomeCredential {
				_ = r.tokens.Deactivate(ctx, characterID)
				return err
			}
			_ = r.tokens.SetCooldown(ctx, characterID, time.Now().Add(time.Minute))
			return err
		}
		if pair.RefreshToken == "" {
			pair.RefreshToken = refresh
		}
		return r.tokens.Rotate(ctx, characterID, pair)
	})
}
