// Package auth holds the user/character session model for the Go API.
//
// Sessions are HMAC-signed JSON cookies (stdlib only): tamper-evident, 120
// minute expiry mirroring SESSION_LIFETIME, cookie name eve_auth_session
// mirroring SESSION_COOKIE. They are NOT compatible with the PHP sessions:
// cutover is a flag-day for logins (users sign in again via EVE SSO).
package auth

import (
	"fmt"
	"strings"
)

// Demo ID range mirrors DemoIds: reserved ids far above CCP-issued ones.
const (
	DemoBase    = 99_000_000_000
	DemoCeiling = 99_300_000_000
)

// IsDemoID mirrors DemoIds::isDemo().
func IsDemoID(id int64) bool { return id >= DemoBase && id < DemoCeiling }

// PortraitURL mirrors Character::portrait_url: demo ids serve local SVGs.
func PortraitURL(characterID int64) string {
	if IsDemoID(characterID) {
		return fmt.Sprintf("/demo/portrait/%d.svg?size=128", characterID)
	}
	return fmt.Sprintf("https://images.evetech.net/characters/%d/portrait?size=128", characterID)
}

// User mirrors the App\Models\User fields the API exposes.
type User struct {
	ID             int64
	Name           string
	IsAdmin        bool
	IsSuperAdmin   bool
	IsActive       bool
	IsStockManager bool
	IsDemo         bool
}

// Admin mirrors User::isAdmin().
func (u *User) Admin() bool { return u != nil && (u.IsAdmin || u.IsSuperAdmin) }

// Character mirrors the CharacterResource fields (tokens never leave the DB).
type Character struct {
	CharacterID     int64
	Name            string
	CorporationID   *int64
	CorporationName *string
	AllianceID      *int64
	AllianceName    *string
	IsMain          bool
	IsEsiActive     bool
	CachedLocation  *string
	CachedTraining  *string
	Scopes          []string
}

// PortraitURL mirrors the Character::portrait_url accessor.
func (c Character) PortraitURL() string { return PortraitURL(c.CharacterID) }

// MePayload mirrors MeResource::toArray(): the user, main-first characters
// and feature flags. DisplayName falls back to the account name without a
// main character; AvatarURL is the main character portrait (nil without one).
func MePayload(u *User, characters []Character, sourceURL, discordURL string, discordBot bool) map[string]any {
	display := u.Name
	var avatar any
	for _, ch := range characters {
		if ch.IsMain {
			display = ch.Name
			avatar = ch.PortraitURL()
			break
		}
	}
	chars := make([]any, 0, len(characters))
	for _, ch := range characters {
		chars = append(chars, map[string]any{
			"character_id": ch.CharacterID, "name": ch.Name,
			"corporation_id": ch.CorporationID, "corporation_name": ch.CorporationName,
			"alliance_id": ch.AllianceID, "alliance_name": ch.AllianceName,
			"is_main": ch.IsMain, "is_esi_active": ch.IsEsiActive,
			"cached_location": ch.CachedLocation, "cached_training": ch.CachedTraining,
			"portrait_url": ch.PortraitURL(), "scopes": nonNilStrings(ch.Scopes),
		})
	}
	var discord any
	if discordURL != "" {
		discord = discordURL
	}
	return map[string]any{
		"id": u.ID, "name": u.Name, "display_name": display, "avatar_url": avatar,
		"admin": u.Admin(), "stock_manager": u.IsStockManager, "demo": u.IsDemo,
		"source_url": sourceURL, "discord_url": discord,
		"features":   map[string]any{"discord_bot": discordBot},
		"characters": chars,
	}
}

func nonNilStrings(in []string) []any {
	out := make([]any, 0, len(in))
	for _, s := range in {
		out = append(out, s)
	}
	return out
}

// DemoWriteBlocked mirrors BlockDemoUser: safe methods and allow-listed
// write paths pass; other demo writes get a 403 with code demo_restricted.
var demoAllowedWritePrefixes = []string{
	"api/v1/logout",
	"api/v1/calculators/",
	"api/v1/appraisals",
	"api/v1/build-programs",
	"api/v1/build-programs/",
	"api/v1/stock/items",
	"api/v1/stock/items/",
	"api/v1/station-configs",
	"api/v1/station-configs/",
	"api/v1/structures",
	"api/v1/structures/",
	"api/v1/assets/refresh",
	"api/v1/industry/availability/",
	"api/v1/industry/can-produce/refresh",
	"api/v1/industry/stock/refresh",
	"api/v1/planetary/refresh",
	"api/v1/corporation/projects/refresh",
	"api/v1/corporation/donations/refresh",
}

// DemoWriteBlocked reports whether a demo user's request must be rejected.
func DemoWriteBlocked(isDemo bool, method, path string) bool {
	if !isDemo {
		return false
	}
	m := strings.ToUpper(method)
	if m == "GET" || m == "HEAD" || m == "OPTIONS" {
		return false
	}
	p := strings.TrimPrefix(path, "/")
	if p == "logout" {
		return false
	}
	for _, prefix := range demoAllowedWritePrefixes {
		base := strings.TrimSuffix(prefix, "/")
		if p == base || strings.HasPrefix(p, base+"/") {
			return false
		}
	}
	return true
}
