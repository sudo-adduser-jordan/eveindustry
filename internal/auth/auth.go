package auth

import (
	"fmt"
	"strings"
	"time"
)

const (
	DemoBase    = 99_000_000_000
	DemoCeiling = 99_300_000_000
)

func IsDemoID(id int64) bool { return id >= DemoBase && id < DemoCeiling }

func PortraitURL(characterID int64) string {
	if IsDemoID(characterID) {
		return fmt.Sprintf("/demo/portrait/%d.svg?size=128", characterID)
	}
	return fmt.Sprintf("https://images.evetech.net/characters/%d/portrait?size=128", characterID)
}

type User struct {
	ID             int64
	Name           string
	IsAdmin        bool
	IsSuperAdmin   bool
	IsActive       bool
	IsStockManager bool
	IsDemo         bool
	CreatedAt      *time.Time
	LastLoginAt    *time.Time
}

func (u *User) Admin() bool { return u != nil && (u.IsAdmin || u.IsSuperAdmin) }

type Character struct {
	CharacterID     int64
	Name            string
	CorporationID   *int64
	CorporationName *string
	AllianceID      *int64
	AllianceName    *string
	IsMain          bool
	IsEsiActive     bool
	IsDirectorCEO   bool
	CachedLocation  *string
	CachedTraining  *string
	Scopes          []string
	Roles           []string
}

func (c Character) PortraitURL() string { return PortraitURL(c.CharacterID) }

func MePayload(u *User, characters []Character, sourceURL string) map[string]any {
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
	return map[string]any{
		"id": u.ID, "name": u.Name, "display_name": display, "avatar_url": avatar,
		"admin": u.Admin(), "stock_manager": u.IsStockManager, "demo": u.IsDemo,
		"source_url": sourceURL,
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

var demoAllowedWritePrefixes = []string{
	"api/v1/logout",
	"api/v1/calculators/",
	"api/v1/market/appraisals",
	"api/v1/moon-scans",
	"api/v1/moon-scans/",
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
