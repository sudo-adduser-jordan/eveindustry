package auth

import (
	"crypto/hmac"
	"crypto/sha256"
	"encoding/base64"
	"encoding/json"
	"net/http"
	"strconv"
	"strings"
	"time"
)

// SessionCookie mirrors config/session.php: name eve_auth_session.
const SessionCookie = "eve_auth_session"

// Sessions issues and validates login sessions. The cookie transport keeps
// this usable without Redis; a Redis-backed implementation can replace it
// without touching handlers.
type Sessions interface {
	// UserID returns the logged-in user id, or false for missing, expired
	// or tampered cookies.
	UserID(r *http.Request) (int64, bool)
	// Issue writes a fresh session cookie for userID.
	Issue(w http.ResponseWriter, userID int64)
	// Destroy clears the session cookie (logout).
	Destroy(w http.ResponseWriter, r *http.Request)
}

type sessionPayload struct {
	UID int64 `json:"uid"`
	Exp int64 `json:"exp"`
	Iat int64 `json:"iat"`
}

// CookieSessions is the HMAC-signed JSON cookie implementation. Signatures
// use constant-time comparison; payloads are base64url without padding.
type CookieSessions struct {
	key    []byte
	ttl    time.Duration
	secure bool
	domain string
}

// NewCookieSessions builds the store. Zero ttl defaults to 120 minutes like
// SESSION_LIFETIME; negative ttls issue already-expired cookies.
func NewCookieSessions(key []byte, ttl time.Duration, secure bool, domain string) *CookieSessions {
	if ttl == 0 {
		ttl = 120 * time.Minute
	}
	return &CookieSessions{key: key, ttl: ttl, secure: secure, domain: domain}
}

// TTL exposes the session lifetime for tests.
func (s *CookieSessions) TTL() time.Duration { return s.ttl }

func (s *CookieSessions) sign(payload string) string {
	mac := hmac.New(sha256.New, s.key)
	mac.Write([]byte(payload))
	return base64.RawURLEncoding.EncodeToString(mac.Sum(nil))
}

func (s *CookieSessions) Issue(w http.ResponseWriter, userID int64) {
	now := time.Now()
	raw, _ := json.Marshal(sessionPayload{UID: userID, Exp: now.Add(s.ttl).Unix(), Iat: now.Unix()})
	payload := base64.RawURLEncoding.EncodeToString(raw)
	http.SetCookie(w, &http.Cookie{
		Name:     SessionCookie,
		Value:    payload + "." + s.sign(payload),
		Path:     "/",
		MaxAge:   int(s.ttl.Seconds()),
		HttpOnly: true,
		Secure:   s.secure,
		SameSite: http.SameSiteLaxMode,
		Domain:   s.domain,
	})
}

func (s *CookieSessions) UserID(r *http.Request) (int64, bool) {
	c, err := r.Cookie(SessionCookie)
	if err != nil || c.Value == "" {
		return 0, false
	}
	payload, sig, ok := strings.Cut(c.Value, ".")
	if !ok {
		return 0, false
	}
	want, err := base64.RawURLEncoding.DecodeString(sig)
	if err != nil {
		return 0, false
	}
	mac := hmac.New(sha256.New, s.key)
	mac.Write([]byte(payload))
	if !hmac.Equal(mac.Sum(nil), want) {
		return 0, false
	}
	raw, err := base64.RawURLEncoding.DecodeString(payload)
	if err != nil {
		return 0, false
	}
	var p sessionPayload
	if err := json.Unmarshal(raw, &p); err != nil || p.UID <= 0 {
		return 0, false
	}
	if time.Now().Unix() > p.Exp {
		return 0, false
	}
	return p.UID, true
}

func (s *CookieSessions) Destroy(w http.ResponseWriter, r *http.Request) {
	http.SetCookie(w, &http.Cookie{
		Name:     SessionCookie,
		Value:    "",
		Path:     "/",
		MaxAge:   -1,
		HttpOnly: true,
		Secure:   s.secure,
		SameSite: http.SameSiteLaxMode,
		Domain:   s.domain,
	})
}

// SessionKeyFromEnv resolves the signing key: SESSION_KEY first, else the
// Laravel APP_KEY (base64: stripped and decoded), else an ephemeral key with
// sessions that die on restart. Callers log the fallback loudly.
func SessionKeyFromEnv(sessionKey, appKey string) (key []byte, ephemeral bool) {
	if sessionKey != "" {
		return []byte(sessionKey), false
	}
	b64, _ := strings.CutPrefix(appKey, "base64:")
	if b64 != "" {
		if raw, err := base64.StdEncoding.DecodeString(b64); err == nil && len(raw) >= 32 {
			return raw, false
		}
	}
	seed := strconv.FormatInt(time.Now().UnixNano(), 10)
	mac := hmac.New(sha256.New, []byte("eveindustry-ephemeral-sessions"))
	mac.Write([]byte(seed))
	return mac.Sum(nil), true
}
