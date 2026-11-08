package http

import (
	"context"
	"net/http"
	"time"

	"github.com/labstack/echo/v4"

	"github.com/sudo-adduser-jordan/eveindustry/internal/auth"
	"github.com/sudo-adduser-jordan/eveindustry/internal/store"
)

// WholemapReadsDeps carries the ESI + reference surface the wholemap reads need.
type WholemapReadsDeps struct {
	Characters WholemapLocationESI
	Reference  ProgressReference
}

// WholemapLocationESI resolves the pilot's current position (never cached).
type WholemapLocationESI interface {
	GetLocation(ctx context.Context, characterID int64) (map[string]any, error)
}

// registerWholemapReads mounts GET /wholemap, /wholemap/location and the
// connection jump logs. Writes live in registerWholemap.
func registerWholemapReads(v1 *echo.Group, d Deps) {
	if d.Wholemap == nil || d.Users == nil {
		return
	}
	v1.GET("/wholemap", handleWholemapMap(d))
	v1.GET("/wholemap/location", handleWholemapLocation(d))
	v1.GET("/wholemap/connections/:id/jump-logs", handleJumpLogs(d))
}

// GET /wholemap — signatures, connections and notes from the last 24 hours.
// ?system_id narrows signatures and notes; connections are always the chain.
func handleWholemapMap(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		var systemID *int
		if raw := c.QueryParam("system_id"); raw != "" {
			n, err := parseIntProg(raw)
			if err != nil {
				verrs := validationErrors{}
				verrs.add("system_id", "The system id field must be an integer.")
				return verrs.invalid(c)
			}
			systemID = &n
		}
		uid := c.Get(string(ctxUIDKey)).(int64)
		ctx := c.Request().Context()
		since := time.Now().Add(-24 * time.Hour)
		sigs, err := d.Wholemap.RecentSignatures(ctx, uid, since, systemID)
		if err != nil {
			return dbError(c, err)
		}
		conns, err := d.Wholemap.RecentConnections(ctx, uid, since)
		if err != nil {
			return dbError(c, err)
		}
		notes, err := d.Wholemap.RecentNotes(ctx, uid, since, systemID)
		if err != nil {
			return dbError(c, err)
		}
		connBySig := map[int64]*store.WormholeConnection{}
		for i := range conns {
			connBySig[conns[i].SignatureID] = &conns[i]
		}
		sigItems := make([]any, 0, len(sigs))
		for _, g := range sigs {
			sigItems = append(sigItems, signatureShape(g, connBySig[g.ID]))
		}
		connItems := make([]any, 0, len(conns))
		systems := map[int]string{}
		for _, cn := range conns {
			connItems = append(connItems, connectionShape(cn))
			if cn.FromSystemName != nil {
				systems[cn.FromSystemID] = *cn.FromSystemName
			} else if _, ok := systems[cn.FromSystemID]; !ok {
				systems[cn.FromSystemID] = ""
			}
			if cn.ToSystemID != nil {
				name := ""
				if cn.ToSystemName != nil {
					name = *cn.ToSystemName
				}
				systems[*cn.ToSystemID] = name
			}
		}
		for _, g := range sigs {
			if g.SystemName != nil {
				systems[g.SystemID] = *g.SystemName
			} else if _, ok := systems[g.SystemID]; !ok {
				systems[g.SystemID] = ""
			}
		}
		noteItems := make([]any, 0, len(notes))
		for _, n := range notes {
			noteItems = append(noteItems, noteShape(n))
		}
		sysItems := make([]any, 0, len(systems))
		for id, name := range systems {
			sysItems = append(sysItems, map[string]any{"system_id": id, "system_name": name})
		}
		return Success(c, http.StatusOK, map[string]any{
			"signatures": sigItems, "connections": connItems,
			"notes": noteItems, "systems": sysItems,
		}, nil)
	}
}

// GET /wholemap/connections/:id/jump-logs — newest first. Foreign
// connections 403 (WholemapRecordPolicy), unknown ids 404.
func handleJumpLogs(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		id, err := parseIntProg(c.Param("id"))
		if err != nil || id < 1 {
			return Failure(c, http.StatusNotFound, "Not found.", nil)
		}
		page, perPage, ok := listPage(c)
		if !ok {
			return errHandled
		}
		uid := c.Get(string(ctxUIDKey)).(int64)
		ctx := c.Request().Context()
		conn, err := d.Wholemap.ConnectionByID(ctx, int64(id))
		if err != nil {
			return dbError(c, err)
		}
		if conn == nil {
			return Failure(c, http.StatusNotFound, "Not found.", nil)
		}
		if conn.UserID != uid {
			return Failure(c, http.StatusForbidden, "Access denied.", nil)
		}
		logs, err := d.Wholemap.JumpLogs(ctx, conn.ID)
		if err != nil {
			return dbError(c, err)
		}
		items := make([]any, 0, len(logs))
		for _, j := range logs {
			items = append(items, jumpLogShape(j))
		}
		return paginated(c, items, page, perPage)
	}
}

// GET /wholemap/location — live ESI position, stored on the character.
// 422 for foreign/missing characters or a missing location scope; 502 when
// ESI cannot answer.
func handleWholemapLocation(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		if d.WholemapReads.Characters == nil || d.WholemapReads.Reference == nil {
			return Failure(c, http.StatusBadGateway, "Unable to fetch location.", nil)
		}
		uid := c.Get(string(ctxUIDKey)).(int64)
		ctx := c.Request().Context()
		chars, err := d.Users.CharactersForUser(ctx, uid)
		if err != nil {
			return dbError(c, err)
		}
		var picked *auth.Character
		if raw := c.QueryParam("character_id"); raw != "" {
			n, err := parseIntProg(raw)
			if err != nil {
				verrs := validationErrors{}
				verrs.add("character_id", "The character id field must be an integer.")
				return verrs.invalid(c)
			}
			for i := range chars {
				if chars[i].CharacterID == int64(n) {
					picked = &chars[i]
					break
				}
			}
			if picked == nil {
				return Failure(c, http.StatusUnprocessableEntity, "Character not found.", nil)
			}
		} else {
			for i := range chars {
				if chars[i].IsMain {
					picked = &chars[i]
					break
				}
			}
			if picked == nil {
				return Failure(c, http.StatusUnprocessableEntity, "No main character linked.", nil)
			}
		}
		if !hasScope(*picked, "esi-location.read_location.v1") {
			return Failure(c, http.StatusUnprocessableEntity, "Location scope not granted.", nil)
		}
		pos, err := d.WholemapReads.Characters.GetLocation(ctx, picked.CharacterID)
		if err != nil {
			return Failure(c, http.StatusBadGateway, "Unable to fetch location.", nil)
		}
		sysID, ok := asInt64Prog(pos["solar_system_id"])
		if !ok || sysID == 0 {
			return Failure(c, http.StatusBadGateway, "Unable to fetch location.", nil)
		}
		var name string
		var security, secClass any
		var regionID any
		if s, err := d.WholemapReads.Reference.FindSystem(ctx, int(sysID)); err == nil && s != nil {
			name = s.SolarSystemName
			if s.Security.Valid {
				security = s.Security.Float64
			}
			if s.SecurityClass.Valid {
				secClass = s.SecurityClass.String
			}
			if s.RegionID.Valid {
				regionID = int(s.RegionID.Int64)
			}
		}
		if name == "" {
			return Failure(c, http.StatusBadGateway, "Unable to resolve system name.", nil)
		}
		if picked.CachedLocation == nil || *picked.CachedLocation != name {
			_ = d.Users.UpdateCharacterLocation(ctx, uid, picked.CharacterID, name)
		}
		return Success(c, http.StatusOK, map[string]any{
			"solar_system_id": int(sysID), "solar_system_name": name,
			"security": security, "security_class": secClass, "region_id": regionID,
		}, nil)
	}
}
