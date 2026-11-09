package http

import (
	"encoding/json"
	"net/http"
	"strconv"
	"strings"

	"github.com/labstack/echo/v4"

	"github.com/sudo-adduser-jordan/eveindustry/internal/store"
)

// registerWholemap mounts the wormhole-mapper writes. Reads for the map
// itself live in the heavy slice; ownership here is 403 like
// WholemapRecordPolicy (never hidden as 404).
func registerWholemap(v1 *echo.Group, d Deps) {
	if d.Wholemap == nil {
		return
	}
	v1.POST("/wholemap/signatures", handleSignatureStore(d))
	v1.POST("/wholemap/signatures/paste", handleSignaturePaste(d))
	v1.PUT("/wholemap/signatures/:id", handleSignatureUpdate(d))
	v1.POST("/wholemap/signatures/:id/lock-toggle", handleSignatureLockToggle(d))
	v1.DELETE("/wholemap/signatures/:id", handleSignatureDestroy(d))
	v1.POST("/wholemap/connections", handleConnectionStore(d))
	v1.PUT("/wholemap/connections/:id", handleConnectionUpdate(d))
	v1.DELETE("/wholemap/connections/:id", handleConnectionDestroy(d))
	v1.POST("/wholemap/jump-logs", handleJumpLogStore(d))
	v1.PUT("/wholemap/notes", handleNoteUpsert(d))
	v1.DELETE("/wholemap/notes/:id", handleNoteDestroy(d))
}

var signatureTypes = map[string]bool{
	"unknown": true, "combat": true, "data": true, "relic": true,
	"ore": true, "gas": true, "wormhole": true, "ghost": true,
}

func signatureShape(s store.WholemapSignature, conn *store.WormholeConnection) map[string]any {
	var connOut any
	if conn != nil {
		connOut = connectionShape(*conn)
	}
	return map[string]any{
		"id": s.ID, "signature_id": s.SignatureID, "system_id": s.SystemID,
		"system_name": s.SystemName, "type": s.Type, "name": s.Name,
		"bookmark": s.Bookmark, "description": s.Description, "locked": s.Locked,
		"created_by": s.CreatedBy, "updated_by": s.UpdatedBy,
		"created_at": s.CreatedAt.UTC().Format("2006-01-02T15:04:05Z"),
		"updated_at": s.UpdatedAt.UTC().Format("2006-01-02T15:04:05Z"),
		"connection": connOut,
	}
}

func connectionShape(c store.WormholeConnection) map[string]any {
	return map[string]any{
		"id": c.ID, "signature_id": c.SignatureID,
		"from_system_id": c.FromSystemID, "from_system_name": c.FromSystemName,
		"to_system_id": c.ToSystemID, "to_system_name": c.ToSystemName,
		"wormhole_type": c.WormholeType, "size": c.Size, "life": c.Life, "mass": c.Mass,
		"created_at": c.CreatedAt.UTC().Format("2006-01-02T15:04:05Z"),
		"updated_at": c.UpdatedAt.UTC().Format("2006-01-02T15:04:05Z"),
	}
}

func jumpLogShape(j store.WormholeJumpLog) map[string]any {
	return map[string]any{
		"id": j.ID, "connection_id": j.ConnectionID, "character_id": j.CharacterID,
		"character_name": j.CharacterName, "ship_type_id": j.ShipTypeID,
		"ship_type_name": j.ShipTypeName, "ship_mass": j.ShipMass,
		"jumped_at": j.JumpedAt.UTC().Format("2006-01-02T15:04:05Z"),
	}
}

func noteShape(n store.WholemapNote) map[string]any {
	return map[string]any{
		"id": n.ID, "system_id": n.SystemID, "system_name": n.SystemName,
		"status": n.Status, "comment": n.Comment,
		"created_at": n.CreatedAt.UTC().Format("2006-01-02T15:04:05Z"),
		"updated_at": n.UpdatedAt.UTC().Format("2006-01-02T15:04:05Z"),
	}
}

func wholemapID(c echo.Context) (int64, bool) {
	id, err := strconv.ParseInt(c.Param("id"), 10, 64)
	if err != nil || id < 1 {
		_ = Failure(c, http.StatusNotFound, "Not found.", nil)
		return 0, false
	}
	return id, true
}

// ownedSignature loads any-user row then enforces owner-only (403).
func ownedSignature(c echo.Context, d Deps, uid, id int64) (*store.WholemapSignature, bool) {
	sig, err := d.Wholemap.SignatureByID(c.Request().Context(), id)
	if err != nil {
		_ = dbError(c, err)
		return nil, false
	}
	if sig == nil {
		_ = Failure(c, http.StatusNotFound, "Not found.", nil)
		return nil, false
	}
	if sig.UserID != uid {
		_ = Failure(c, http.StatusForbidden, "Forbidden.", nil)
		return nil, false
	}
	return sig, true
}

func ownedConnection(c echo.Context, d Deps, uid, id int64) (*store.WormholeConnection, bool) {
	conn, err := d.Wholemap.ConnectionByID(c.Request().Context(), id)
	if err != nil {
		_ = dbError(c, err)
		return nil, false
	}
	if conn == nil {
		_ = Failure(c, http.StatusNotFound, "Not found.", nil)
		return nil, false
	}
	if conn.UserID != uid {
		_ = Failure(c, http.StatusForbidden, "Forbidden.", nil)
		return nil, false
	}
	return conn, true
}

func strField(raw map[string]any, verrs validationErrors, field string, maxLen int, required, allowEmpty bool) *string {
	v, present := raw[field]
	if !present || v == nil {
		if required {
			verrs.add(field, "The "+field+" field is required.")
		}
		return nil
	}
	s, ok := v.(string)
	if !ok {
		verrs.add(field, "The "+field+" field must be a string.")
		return nil
	}
	if s == "" {
		if required && !allowEmpty {
			verrs.add(field, "The "+field+" field is required.")
			return nil
		}
		if allowEmpty {
			return &s
		}
		return nil
	}
	if maxLen > 0 && len([]rune(s)) > maxLen {
		verrs.add(field, "The "+field+" field must not be greater than "+strconv.Itoa(maxLen)+" characters.")
		return nil
	}
	return &s
}

func intFieldJSON(raw map[string]any, verrs validationErrors, field string, required bool) (int, bool) {
	v, present := raw[field]
	if !present || v == nil {
		if required {
			verrs.add(field, "The "+field+" field is required.")
		}
		return 0, !required
	}
	switch n := v.(type) {
	case float64:
		if n != float64(int(n)) {
			verrs.add(field, "The "+field+" field must be an integer.")
			return 0, false
		}
		return int(n), true
	default:
		verrs.add(field, "The "+field+" field must be an integer.")
		return 0, false
	}
}

func decodeBody(c echo.Context, verrs validationErrors) map[string]any {
	var raw map[string]any
	if err := json.NewDecoder(c.Request().Body).Decode(&raw); err != nil {
		verrs.add("body", "The request body must be valid JSON.")
		return nil
	}
	if raw == nil {
		raw = map[string]any{}
	}
	return raw
}

// POST /wholemap/signatures — 201.
func handleSignatureStore(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		verrs := validationErrors{}
		raw := decodeBody(c, verrs)
		if raw == nil {
			return verrs.invalid(c)
		}
		sysID, ok := intFieldJSON(raw, verrs, "system_id", true)
		if !ok && len(verrs) == 0 {
			return verrs.invalid(c)
		}
		sysName := strField(raw, verrs, "system_name", 255, true, false)
		typeStr := strField(raw, verrs, "type", 0, true, false)
		if typeStr != nil && !signatureTypes[*typeStr] {
			verrs.add("type", "The selected type is invalid.")
			typeStr = nil
		}
		name := strField(raw, verrs, "name", 100, false, true)
		bookmark := strField(raw, verrs, "bookmark", 100, false, true)
		desc := strField(raw, verrs, "description", 1000, false, true)
		sigID := strField(raw, verrs, "signature_id", 7, false, true)
		if len(verrs) > 0 {
			return verrs.invalid(c)
		}
		uid := c.Get(string(ctxUIDKey)).(int64)
		name2 := ""
		if n := userDisplayName(c, d, uid); n != "" {
			name2 = n
		}
		row, err := d.Wholemap.CreateSignature(c.Request().Context(), store.WholemapSignature{
			UserID: uid, SignatureID: sigID, SystemID: sysID, SystemName: sysName,
			Type: derefStr(typeStr, "unknown"), Name: name, Bookmark: bookmark,
			Description: desc, CreatedBy: &name2, UpdatedBy: &name2,
		})
		if err != nil {
			return dbError(c, err)
		}
		conn, _ := d.Wholemap.ConnectionForSignature(c.Request().Context(), row.ID)
		return Success(c, http.StatusCreated, signatureShape(*row, conn), nil)
	}
}

func derefStr(s *string, fallback string) string {
	if s == nil {
		return fallback
	}
	return *s
}

func userDisplayName(c echo.Context, d Deps, uid int64) string {
	if u, ok := CurrentUser(c); ok {
		return u.Name
	}
	u, err := d.Users.UserByID(c.Request().Context(), uid)
	if err != nil || u == nil {
		return ""
	}
	return u.Name
}

// POST /wholemap/signatures/paste — {created, updated}.
func handleSignaturePaste(d Deps) echo.HandlerFunc {
	type row struct {
		sigID string
		typ   string
		name  string
	}
	return func(c echo.Context) error {
		verrs := validationErrors{}
		raw := decodeBody(c, verrs)
		if raw == nil {
			return verrs.invalid(c)
		}
		sysID, _ := intFieldJSON(raw, verrs, "system_id", true)
		sysName := strField(raw, verrs, "system_name", 255, true, false)
		paste := strField(raw, verrs, "paste", 10000, true, false)
		if len(verrs) > 0 {
			return verrs.invalid(c)
		}
		uid := c.Get(string(ctxUIDKey)).(int64)
		userName := userDisplayName(c, d, uid)
		ctx := c.Request().Context()
		created, updated := 0, 0
		for _, line := range strings.Split(strings.ReplaceAll(*paste, "\r\n", "\n"), "\n") {
			line = strings.TrimSpace(line)
			if line == "" {
				continue
			}
			cols := strings.Split(line, "\t")
			if len(cols) < 3 {
				continue
			}
			sigID := strings.TrimSpace(cols[0])
			typeName := strings.ToLower(strings.TrimSpace(cols[2]))
			name := ""
			if len(cols) > 3 {
				name = strings.TrimSpace(cols[3])
			}
			if sigID == "" || len([]rune(sigID)) > 7 {
				continue
			}
			typ := pasteTypes[typeName]
			if typ == "" {
				typ = "unknown"
			}
			existing, err := d.Wholemap.SignatureByKey(ctx, uid, sysID, sigID)
			if err != nil {
				return dbError(c, err)
			}
			if existing != nil {
				if existing.Locked {
					continue
				}
				existing.UpdatedBy = &userName
				if typ != "unknown" {
					existing.Type = typ
				}
				if name != "" {
					n := truncateRunes(name, 100)
					existing.Name = &n
				}
				if _, err := d.Wholemap.UpdateSignature(ctx, *existing); err != nil {
					return dbError(c, err)
				}
				updated++
				continue
			}
			var nm *string
			if name != "" {
				n := truncateRunes(name, 100)
				nm = &n
			}
			if _, err := d.Wholemap.CreateSignature(ctx, store.WholemapSignature{
				UserID: uid, SignatureID: &sigID, SystemID: sysID, SystemName: sysName,
				Type: typ, Name: nm, CreatedBy: &userName, UpdatedBy: &userName,
			}); err != nil {
				return dbError(c, err)
			}
			created++
		}
		return Success(c, http.StatusOK, map[string]any{"created": created, "updated": updated}, nil)
	}
}

var pasteTypes = map[string]string{
	"combat site": "combat", "data site": "data", "relic site": "relic",
	"ore site": "ore", "gas site": "gas", "wormhole": "wormhole", "ghost site": "ghost",
}

func truncateRunes(s string, n int) string {
	r := []rune(s)
	if len(r) <= n {
		return s
	}
	return string(r[:n])
}

// PUT /wholemap/signatures/:id — partial; locked rows 422 until unlocked.
func handleSignatureUpdate(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		id, ok := wholemapID(c)
		if !ok {
			return errHandled
		}
		uid := c.Get(string(ctxUIDKey)).(int64)
		sig, ok := ownedSignature(c, d, uid, id)
		if !ok {
			return errHandled
		}
		if sig.Locked {
			verrs := validationErrors{}
			verrs.add("signature", "Signature is locked.")
			return verrs.invalid(c)
		}
		verrs := validationErrors{}
		raw := decodeBody(c, verrs)
		if raw == nil {
			return verrs.invalid(c)
		}
		if v, ok := raw["signature_id"]; ok && v != nil {
			s, isStr := v.(string)
			if !isStr || len([]rune(s)) > 7 {
				verrs.add("signature_id", "The signature id field must not be greater than 7 characters.")
			} else {
				sig.SignatureID = &s
			}
		}
		if v, ok := raw["type"]; ok && v != nil {
			s, isStr := v.(string)
			if !isStr || !signatureTypes[s] {
				verrs.add("type", "The selected type is invalid.")
			} else {
				sig.Type = s
			}
		}
		for _, f := range []struct {
			key string
			max int
			set func(string)
		}{
			{"name", 100, func(s string) { sig.Name = &s }},
			{"bookmark", 100, func(s string) { sig.Bookmark = &s }},
			{"description", 1000, func(s string) { sig.Description = &s }},
		} {
			v, present := raw[f.key]
			if !present {
				continue
			}
			if v == nil {
				sigClear(f.key, sig)
				continue
			}
			s, isStr := v.(string)
			if !isStr || len([]rune(s)) > f.max {
				verrs.add(f.key, "The "+f.key+" field is invalid.")
				continue
			}
			f.set(s)
		}
		if len(verrs) > 0 {
			return verrs.invalid(c)
		}
		userName := userDisplayName(c, d, uid)
		sig.UpdatedBy = &userName
		row, err := d.Wholemap.UpdateSignature(c.Request().Context(), *sig)
		if err != nil {
			return dbError(c, err)
		}
		conn, _ := d.Wholemap.ConnectionForSignature(c.Request().Context(), row.ID)
		return Success(c, http.StatusOK, signatureShape(*row, conn), nil)
	}
}

// sigClear sets a nullable text field to NULL when JSON null was sent.
func sigClear(key string, sig *store.WholemapSignature) {
	switch key {
	case "name":
		sig.Name = nil
	case "bookmark":
		sig.Bookmark = nil
	case "description":
		sig.Description = nil
	}
}

// POST /wholemap/signatures/:id/lock-toggle.
func handleSignatureLockToggle(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		id, ok := wholemapID(c)
		if !ok {
			return errHandled
		}
		uid := c.Get(string(ctxUIDKey)).(int64)
		sig, ok := ownedSignature(c, d, uid, id)
		if !ok {
			return errHandled
		}
		sig.Locked = !sig.Locked
		row, err := d.Wholemap.UpdateSignature(c.Request().Context(), *sig)
		if err != nil {
			return dbError(c, err)
		}
		conn, _ := d.Wholemap.ConnectionForSignature(c.Request().Context(), row.ID)
		return Success(c, http.StatusOK, signatureShape(*row, conn), nil)
	}
}

// DELETE /wholemap/signatures/:id — 204, connection cascades in the DB.
func handleSignatureDestroy(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		id, ok := wholemapID(c)
		if !ok {
			return errHandled
		}
		uid := c.Get(string(ctxUIDKey)).(int64)
		if _, ok := ownedSignature(c, d, uid, id); !ok {
			return errHandled
		}
		if err := d.Wholemap.DeleteSignature(c.Request().Context(), id); err != nil {
			return dbError(c, err)
		}
		return c.NoContent(http.StatusNoContent)
	}
}

// POST /wholemap/connections — 201; the signature must be the caller's (403).
func handleConnectionStore(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		verrs := validationErrors{}
		raw := decodeBody(c, verrs)
		if raw == nil {
			return verrs.invalid(c)
		}
		sigID, ok := intFieldJSON(raw, verrs, "signature_id", true)
		if !ok {
			return verrs.invalid(c)
		}
		fromID, _ := intFieldJSON(raw, verrs, "from_system_id", true)
		fromName := strField(raw, verrs, "from_system_name", 255, true, false)
		var toID *int
		if v, present := raw["to_system_id"]; present && v != nil {
			n, ok := intFieldJSON(map[string]any{"v": v}, verrs, "v", true)
			_ = n
			if !ok {
				verrs.add("to_system_id", "The to system id field must be an integer.")
			} else {
				toID = &n
			}
		}
		toName := strField(raw, verrs, "to_system_name", 255, false, true)
		var whType *string
		if v, present := raw["wormhole_type"]; present && v != nil {
			s, isStr := v.(string)
			if !isStr || len([]rune(s)) > 10 {
				verrs.add("wormhole_type", "The wormhole type field is invalid.")
			} else {
				whType = &s
			}
		}
		size := "large"
		if v, present := raw["size"]; present && v != nil {
			s, isStr := v.(string)
			if !isStr || (s != "small" && s != "medium" && s != "large" && s != "xlarge") {
				verrs.add("size", "The selected size is invalid.")
			} else {
				size = s
			}
		}
		life := "stable"
		if v, present := raw["life"]; present && v != nil {
			s, isStr := v.(string)
			if !isStr || (s != "stable" && s != "critical") {
				verrs.add("life", "The selected life is invalid.")
			} else {
				life = s
			}
		}
		mass := "stable"
		if v, present := raw["mass"]; present && v != nil {
			s, isStr := v.(string)
			if !isStr || (s != "stable" && s != "destab" && s != "critical") {
				verrs.add("mass", "The selected mass is invalid.")
			} else {
				mass = s
			}
		}
		if len(verrs) > 0 {
			return verrs.invalid(c)
		}
		uid := c.Get(string(ctxUIDKey)).(int64)
		sig, err := d.Wholemap.SignatureByID(c.Request().Context(), int64(sigID))
		if err != nil {
			return dbError(c, err)
		}
		if sig == nil {
			verrs.add("signature_id", "The selected signature id is invalid.")
			return verrs.invalid(c)
		}
		if sig.UserID != uid {
			return Failure(c, http.StatusForbidden, "Forbidden.", nil)
		}
		row, err := d.Wholemap.CreateConnection(c.Request().Context(), store.WormholeConnection{
			UserID: uid, SignatureID: int64(sigID), FromSystemID: fromID, FromSystemName: fromName,
			ToSystemID: toID, ToSystemName: toName, WormholeType: whType,
			Size: size, Life: life, Mass: mass,
		})
		if err != nil {
			return dbError(c, err)
		}
		return Success(c, http.StatusCreated, connectionShape(*row), nil)
	}
}

// PUT /wholemap/connections/:id — partial.
func handleConnectionUpdate(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		id, ok := wholemapID(c)
		if !ok {
			return errHandled
		}
		uid := c.Get(string(ctxUIDKey)).(int64)
		conn, ok := ownedConnection(c, d, uid, id)
		if !ok {
			return errHandled
		}
		verrs := validationErrors{}
		raw := decodeBody(c, verrs)
		if raw == nil {
			return verrs.invalid(c)
		}
		if v, present := raw["to_system_id"]; present {
			if v == nil {
				conn.ToSystemID = nil
			} else if n, ok := intFieldJSON(map[string]any{"v": v}, verrs, "v", true); ok {
				conn.ToSystemID = &n
			} else {
				verrs.add("to_system_id", "The to system id field must be an integer.")
			}
		}
		if v, present := raw["to_system_name"]; present {
			if v == nil {
				conn.ToSystemName = nil
			} else {
				conn.ToSystemName = strField(map[string]any{"v": v}, verrs, "v", 255, true, true)
			}
		}
		if v, present := raw["wormhole_type"]; present {
			if v == nil {
				conn.WormholeType = nil
			} else if s, isStr := v.(string); !isStr || len([]rune(s)) > 10 {
				verrs.add("wormhole_type", "The wormhole type field is invalid.")
			} else {
				conn.WormholeType = &s
			}
		}
		for _, f := range []struct {
			key     string
			allowed map[string]bool
			set     func(string)
		}{
			{"size", map[string]bool{"small": true, "medium": true, "large": true, "xlarge": true}, func(s string) { conn.Size = s }},
			{"life", map[string]bool{"stable": true, "critical": true}, func(s string) { conn.Life = s }},
			{"mass", map[string]bool{"stable": true, "destab": true, "critical": true}, func(s string) { conn.Mass = s }},
		} {
			if v, present := raw[f.key]; present && v != nil {
				if s, isStr := v.(string); !isStr || !f.allowed[s] {
					verrs.add(f.key, "The selected "+f.key+" is invalid.")
				} else {
					f.set(s)
				}
			}
		}
		if len(verrs) > 0 {
			return verrs.invalid(c)
		}
		row, err := d.Wholemap.UpdateConnection(c.Request().Context(), *conn)
		if err != nil {
			return dbError(c, err)
		}
		return Success(c, http.StatusOK, connectionShape(*row), nil)
	}
}

// DELETE /wholemap/connections/:id — 204.
func handleConnectionDestroy(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		id, ok := wholemapID(c)
		if !ok {
			return errHandled
		}
		uid := c.Get(string(ctxUIDKey)).(int64)
		if _, ok := ownedConnection(c, d, uid, id); !ok {
			return errHandled
		}
		if err := d.Wholemap.DeleteConnection(c.Request().Context(), id); err != nil {
			return dbError(c, err)
		}
		return c.NoContent(http.StatusNoContent)
	}
}

// POST /wholemap/jump-logs — 201; the connection must be the caller's.
func handleJumpLogStore(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		verrs := validationErrors{}
		raw := decodeBody(c, verrs)
		if raw == nil {
			return verrs.invalid(c)
		}
		connID, ok := intFieldJSON(raw, verrs, "connection_id", true)
		if !ok {
			return verrs.invalid(c)
		}
		var charID *int
		if v, present := raw["character_id"]; present && v != nil {
			if n, ok := intFieldJSON(map[string]any{"v": v}, verrs, "v", true); ok {
				charID = &n
			} else {
				verrs.add("character_id", "The character id field must be an integer.")
			}
		}
		charName := strField(raw, verrs, "character_name", 100, false, true)
		var shipID *int
		if v, present := raw["ship_type_id"]; present && v != nil {
			if n, ok := intFieldJSON(map[string]any{"v": v}, verrs, "v", true); ok {
				shipID = &n
			} else {
				verrs.add("ship_type_id", "The ship type id field must be an integer.")
			}
		}
		shipName := strField(raw, verrs, "ship_type_name", 100, false, true)
		var shipMass *float64
		if v, present := raw["ship_mass"]; present && v != nil {
			if n, ok := v.(float64); ok {
				shipMass = &n
			} else {
				verrs.add("ship_mass", "The ship mass field must be a number.")
			}
		}
		if len(verrs) > 0 {
			return verrs.invalid(c)
		}
		uid := c.Get(string(ctxUIDKey)).(int64)
		conn, err := d.Wholemap.ConnectionByID(c.Request().Context(), int64(connID))
		if err != nil {
			return dbError(c, err)
		}
		if conn == nil {
			verrs.add("connection_id", "The selected connection id is invalid.")
			return verrs.invalid(c)
		}
		if conn.UserID != uid {
			return Failure(c, http.StatusForbidden, "Forbidden.", nil)
		}
		var charID64 *int64
		if charID != nil {
			v := int64(*charID)
			charID64 = &v
		}
		var shipIDInt *int
		if shipID != nil {
			shipIDInt = shipID
		}
		row, err := d.Wholemap.CreateJumpLog(c.Request().Context(), store.WormholeJumpLog{
			ConnectionID: int64(connID), UserID: uid, CharacterID: charID64,
			CharacterName: charName, ShipTypeID: shipIDInt, ShipTypeName: shipName, ShipMass: shipMass,
		})
		if err != nil {
			return dbError(c, err)
		}
		return Success(c, http.StatusCreated, jumpLogShape(*row), nil)
	}
}

// PUT /wholemap/notes — upsert keyed on user+system; 201 new, 200 replaced.
func handleNoteUpsert(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		verrs := validationErrors{}
		raw := decodeBody(c, verrs)
		if raw == nil {
			return verrs.invalid(c)
		}
		sysID, _ := intFieldJSON(raw, verrs, "system_id", true)
		sysName := strField(raw, verrs, "system_name", 255, true, false)
		status := strField(raw, verrs, "status", 0, true, false)
		if status != nil {
			s := *status
			if s != "unknown" && s != "friendly" && s != "occupied" && s != "hostile" {
				verrs.add("status", "The selected status is invalid.")
				status = nil
			}
		}
		comment := strField(raw, verrs, "comment", 255, false, true)
		if len(verrs) > 0 {
			return verrs.invalid(c)
		}
		uid := c.Get(string(ctxUIDKey)).(int64)
		row, created, err := d.Wholemap.UpsertNote(c.Request().Context(), store.WholemapNote{
			UserID: uid, SystemID: sysID, SystemName: sysName, Status: *status, Comment: comment,
		})
		if err != nil {
			return dbError(c, err)
		}
		code := http.StatusOK
		if created {
			code = http.StatusCreated
		}
		return Success(c, code, noteShape(*row), nil)
	}
}

// DELETE /wholemap/notes/:id — 204; foreign notes 403.
func handleNoteDestroy(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		id, ok := wholemapID(c)
		if !ok {
			return errHandled
		}
		uid := c.Get(string(ctxUIDKey)).(int64)
		note, err := d.Wholemap.NoteByID(c.Request().Context(), id)
		if err != nil {
			return dbError(c, err)
		}
		if note == nil {
			return Failure(c, http.StatusNotFound, "Not found.", nil)
		}
		if note.UserID != uid {
			return Failure(c, http.StatusForbidden, "Forbidden.", nil)
		}
		if err := d.Wholemap.DeleteNote(c.Request().Context(), id); err != nil {
			return dbError(c, err)
		}
		return c.NoContent(http.StatusNoContent)
	}
}
