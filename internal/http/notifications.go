package http

import (
	"context"
	"database/sql"
	"encoding/json"
	"net/http"
	"strings"
	"time"

	"github.com/labstack/echo/v4"

	"github.com/sudo-adduser-jordan/eveindustry/internal/notify"
	"github.com/sudo-adduser-jordan/eveindustry/internal/store"
)

type notifyType struct {
	key            string
	label          string
	description    string
	category       string
	defaultEnabled bool
	leadOptions    []int
	supportsChars  bool
	requiresCorp   bool
}

var notifyTypes = []notifyType{
	{"industry.job_completed", "Industry job completed",
		"A manufacturing, research, invention or reaction job has finished and is ready to deliver.",
		"Industry", true, nil, true, false},
	{"pi.extractor_expiring", "Planetary extractor expiring",
		"Extractor heads on one of your colonies are about to stop, or have stopped.",
		"Planetary", false, []int{15, 60, 360}, true, false},
	{"structure.window_opening", "Structure vulnerability window opening",
		"Structure vulnerability windows are recorded for your corporation in the vulnerability tracker.",
		"Corporation", false, []int{15, 60, 360}, false, true},
	{"structure.timer", "Structure timer ending",
		"A recorded reinforcement timer for your corporation is about to end.",
		"Corporation", false, []int{15, 60, 360}, false, true},
}

type NotifyPush struct {
	Enabled        bool
	VapidPublicKey string
}

func registerNotifications(v1 *echo.Group, d Deps) {
	if d.Notify == nil || d.Users == nil {
		return
	}
	v1.GET("/notifications", handleNotifyShow(d))
	v1.PUT("/notifications", handleNotifyUpdate(d))
	v1.PUT("/notifications/subscription", handleNotifySubscribe(d))
	v1.DELETE("/notifications/subscription", handleNotifyUnsubscribe(d))
	v1.POST("/notifications/test", handleNotifyTest(d))
}

func notifyConfig(ctx context.Context, d Deps, uid int64) (map[string]any, error) {
	chars, err := d.Users.CharactersForUser(ctx, uid)
	if err != nil {
		return nil, err
	}
	hasCorp := false
	ownIDs := map[int64]bool{}
	for _, ch := range chars {
		ownIDs[ch.CharacterID] = true
		if ch.CorporationID != nil && *ch.CorporationID != 0 {
			hasCorp = true
		}
	}
	stored := map[string]store.NotificationPref{}
	prefs, err := d.Notify.NotificationPrefs(ctx, uid)
	if err != nil {
		return nil, err
	}
	for _, p := range prefs {
		stored[p.Type] = p
	}
	count, err := d.Notify.PushSubscriptionCount(ctx, uid)
	if err != nil {
		return nil, err
	}
	types := make([]any, 0, len(notifyTypes))
	for _, t := range notifyTypes {
		row, ok := stored[t.key]
		enabled := t.defaultEnabled
		var lead any
		if t.leadOptions != nil {
			lead = 60
		}
		var charIDs []any
		if ok {
			enabled = row.Enabled
			if t.leadOptions != nil && row.LeadMinutes.Valid {
				lead = int(row.LeadMinutes.Int64)
			}
			var ids []int64
			if row.CharacterIDs != "" {
				_ = json.Unmarshal([]byte(row.CharacterIDs), &ids)
			}
			for _, id := range ids {
				if ownIDs[id] {
					charIDs = append(charIDs, id)
				}
			}
		}
		if charIDs == nil {
			charIDs = []any{}
		}
		var leadOpts any
		if t.leadOptions != nil {
			leadOpts = t.leadOptions
		}
		types = append(types, map[string]any{
			"key": t.key, "label": t.label, "description": t.description,
			"category": t.category, "available": !t.requiresCorp || hasCorp,
			"enabled": enabled, "default_enabled": t.defaultEnabled,
			"lead_minutes": lead, "lead_options": leadOpts,
			"supports_characters": t.supportsChars, "character_ids": charIDs,
		})
	}
	qh := map[string]any{"start": nil, "end": nil, "timezone": "UTC"}
	if q, err := d.Notify.QuietHours(ctx, uid); err != nil {
		return nil, err
	} else if q != nil {
		if q.Start.Valid {
			qh["start"] = q.Start.String
		}
		if q.End.Valid {
			qh["end"] = q.End.String
		}
		if q.Timezone != "" {
			qh["timezone"] = q.Timezone
		}
	}
	return map[string]any{
		"enabled":          d.NotifyPush.Enabled && d.NotifyPush.VapidPublicKey != "",
		"vapid_public_key": strOrNil(d.NotifyPush.VapidPublicKey),
		"devices":          count, "types": types, "quiet_hours": qh,
	}, nil
}

func strOrNil(s string) any {
	if s == "" {
		return nil
	}
	return s
}

func handleNotifyShow(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		uid := c.Get(string(ctxUIDKey)).(int64)
		cfg, err := notifyConfig(c.Request().Context(), d, uid)
		if err != nil {
			return dbError(c, err)
		}
		return Success(c, http.StatusOK, cfg, nil)
	}
}

func handleNotifySubscribe(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		verrs := validationErrors{}
		raw := decodePlanBody(c, verrs)
		if raw == nil && len(verrs) > 0 {
			return verrs.invalid(c)
		}
		if raw == nil {
			raw = map[string]any{}
		}
		endpoint, _ := raw["endpoint"].(string)
		if !strings.HasPrefix(endpoint, "https://") || len([]rune(endpoint)) > 2000 {
			verrs.add("endpoint", "The endpoint field must be a valid URL.")
		}
		keys, _ := raw["keys"].(map[string]any)
		var p256, authT string
		if keys != nil {
			p256, _ = keys["p256dh"].(string)
			authT, _ = keys["auth"].(string)
		}
		if p256 == "" || len([]rune(p256)) > 255 {
			verrs.add("keys.p256dh", "The keys.p256dh field is required.")
		}
		if authT == "" || len([]rune(authT)) > 255 {
			verrs.add("keys.auth", "The keys.auth field is required.")
		}
		enc := "aes128gcm"
		if v, present := raw["content_encoding"]; present && v != nil {
			if s, isStr := v.(string); !isStr || (s != "aes128gcm" && s != "aesgcm") {
				verrs.add("content_encoding", "The selected content encoding is invalid.")
			} else {
				enc = s
			}
		}
		if len(verrs) > 0 {
			return verrs.invalid(c)
		}
		uid := c.Get(string(ctxUIDKey)).(int64)
		ctx := c.Request().Context()
		var ua sql.NullString
		if a := c.Request().UserAgent(); a != "" {
			if len([]rune(a)) > 255 {
				a = string([]rune(a)[:255])
			}
			ua = sql.NullString{String: a, Valid: true}
		}
		if err := d.Notify.UpsertPushSubscription(ctx, store.PushSubscription{
			UserID: uid, Endpoint: endpoint, EndpointHash: store.HashEndpoint(endpoint),
			PublicKey: p256, AuthToken: authT, ContentEncoding: enc,
			UserAgent: ua, SubscribedAt: time.Now().UTC(),
		}); err != nil {
			return dbError(c, err)
		}
		count, err := d.Notify.PushSubscriptionCount(ctx, uid)
		if err != nil {
			return dbError(c, err)
		}
		return Success(c, http.StatusOK, map[string]any{"devices": count}, nil)
	}
}

func handleNotifyUnsubscribe(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		verrs := validationErrors{}
		raw := decodePlanBody(c, verrs)
		if raw == nil && len(verrs) > 0 {
			return verrs.invalid(c)
		}
		if raw == nil {
			raw = map[string]any{}
		}
		endpoint, _ := raw["endpoint"].(string)
		if endpoint == "" || len([]rune(endpoint)) > 2000 {
			verrs.add("endpoint", "The endpoint field is required.")
			return verrs.invalid(c)
		}
		uid := c.Get(string(ctxUIDKey)).(int64)
		ctx := c.Request().Context()
		if err := d.Notify.DeletePushSubscription(ctx, uid, store.HashEndpoint(endpoint)); err != nil {
			return dbError(c, err)
		}
		count, err := d.Notify.PushSubscriptionCount(ctx, uid)
		if err != nil {
			return dbError(c, err)
		}
		return Success(c, http.StatusOK, map[string]any{"devices": count}, nil)
	}
}

func handleNotifyUpdate(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		verrs := validationErrors{}
		raw := decodePlanBody(c, verrs)
		if raw == nil && len(verrs) > 0 {
			return verrs.invalid(c)
		}
		if raw == nil {
			raw = map[string]any{}
		}
		uid := c.Get(string(ctxUIDKey)).(int64)
		ctx := c.Request().Context()
		chars, err := d.Users.CharactersForUser(ctx, uid)
		if err != nil {
			return dbError(c, err)
		}
		ownIDs := map[int64]bool{}
		for _, ch := range chars {
			ownIDs[ch.CharacterID] = true
		}
		type change struct {
			enabled *bool
			lead    *int64
			charIDs []int64
			hasIDs  bool
		}
		changes := map[string]change{}
		if v, present := raw["types"]; present && v != nil {
			m, isMap := v.(map[string]any)
			if !isMap {
				verrs.add("types", "The types field must be an array.")
				return verrs.invalid(c)
			}
			for key, cv := range m {
				var t *notifyType
				for i := range notifyTypes {
					if notifyTypes[i].key == key {
						t = &notifyTypes[i]
						break
					}
				}
				if t == nil {
					verrs.add("types."+key, "Unknown notification type.")
					continue
				}
				cm, isMap := cv.(map[string]any)
				if !isMap {
					verrs.add("types."+key, "Invalid change.")
					continue
				}
				chg := change{}
				if e, present := cm["enabled"]; present && e != nil {
					if b, isBool := e.(bool); !isBool {
						verrs.add("types."+key+".enabled", "Must be a boolean.")
					} else {
						chg.enabled = &b
					}
				}
				if l, present := cm["lead_minutes"]; present && l != nil {
					n, isNum := toFloat(l)
					if !isNum || t.leadOptions == nil || !intIn(t.leadOptions, int(n)) {
						verrs.add("types."+key+".lead_minutes", "This notification has no such lead time.")
					} else {
						v := int64(n)
						chg.lead = &v
					}
				}
				if ids, present := cm["character_ids"]; present && ids != nil {
					list, isList := ids.([]any)
					if !isList || len(list) > 50 {
						verrs.add("types."+key+".character_ids", "Invalid characters.")
					} else {
						chg.hasIDs = true
						for _, iv := range list {
							n, isNum := toFloat(iv)
							if !isNum || !ownIDs[int64(n)] {
								verrs.add("types."+key+".character_ids", "Unknown character.")
								chg.hasIDs = false
								break
							}
							chg.charIDs = append(chg.charIDs, int64(n))
						}
					}
				}
				changes[key] = chg
			}
		}
		var quiet *store.QuietHours
		if v, present := raw["quiet_hours"]; present && v != nil {
			qm, isMap := v.(map[string]any)
			if !isMap {
				verrs.add("quiet_hours", "Invalid quiet hours.")
			} else {
				qh := store.QuietHours{UserID: uid, Timezone: "UTC"}
				if cur, err := d.Notify.QuietHours(ctx, uid); err != nil {
					return dbError(c, err)
				} else if cur != nil {
					qh = *cur
					qh.UserID = uid
				}
				if s, present := qm["start"]; present {
					if s == nil {
						qh.Start = sql.NullString{}
					} else if str, isStr := s.(string); !isStr || !validHM(str) {
						verrs.add("quiet_hours.start", "Invalid time.")
					} else {
						qh.Start = sql.NullString{String: str, Valid: true}
					}
				}
				if s, present := qm["end"]; present {
					if s == nil {
						qh.End = sql.NullString{}
					} else if str, isStr := s.(string); !isStr || !validHM(str) {
						verrs.add("quiet_hours.end", "Invalid time.")
					} else {
						qh.End = sql.NullString{String: str, Valid: true}
					}
				}
				if tz, present := qm["timezone"]; present && tz != nil {
					if str, isStr := tz.(string); !isStr || !validTZ(str) {
						verrs.add("quiet_hours.timezone", "Invalid timezone.")
					} else {
						qh.Timezone = str
					}
				}
				if !qh.Start.Valid || !qh.End.Valid {
					qh.Start, qh.End = sql.NullString{}, sql.NullString{}
				}
				quiet = &qh
			}
		}
		if len(verrs) > 0 {
			return verrs.invalid(c)
		}
		for key, chg := range changes {
			cur := store.NotificationPref{UserID: uid, Type: key, Enabled: defaultNotifyEnabled(key)}
			if prefs, err := d.Notify.NotificationPrefs(ctx, uid); err != nil {
				return dbError(c, err)
			} else {
				for _, p := range prefs {
					if p.Type == key {
						cur = p
						break
					}
				}
			}
			if chg.enabled != nil {
				cur.Enabled = *chg.enabled
			}
			if chg.lead != nil {
				cur.LeadMinutes = sql.NullInt64{Int64: *chg.lead, Valid: true}
			}
			if chg.hasIDs {
				bs, _ := json.Marshal(chg.charIDs)
				if chg.charIDs == nil {
					bs = []byte("[]")
				}
				cur.CharacterIDs = string(bs)
			}
			if err := d.Notify.UpsertNotificationPref(ctx, cur); err != nil {
				return dbError(c, err)
			}
		}
		if quiet != nil {
			if err := d.Notify.UpdateQuietHours(ctx, *quiet); err != nil {
				return dbError(c, err)
			}
		}
		cfg, err := notifyConfig(ctx, d, uid)
		if err != nil {
			return dbError(c, err)
		}
		return Success(c, http.StatusOK, cfg, nil)
	}
}

func handleNotifyTest(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		if d.NotifySender == nil {
			return Failure(c, http.StatusServiceUnavailable, "Push notifications are not configured on this server.", nil)
		}
		uid := c.Get(string(ctxUIDKey)).(int64)
		ctx := c.Request().Context()
		subs, err := d.Notify.PushSubscriptions(ctx, uid)
		if err != nil {
			return dbError(c, err)
		}
		msg := notify.Message{
			Type: "industry.job_completed", DedupeKey: "test",
			Title: "Notifications are working",
			Body:  "This is a test notification from Eve Industrialist.",
			URL:   "/app/dashboard",
		}
		sent := 0
		for _, sub := range subs {
			ok, gone := d.NotifySender.Send(ctx, sub, msg.Payload())
			if gone {
				_ = d.Notify.DeletePushSubscription(ctx, uid, sub.EndpointHash)
			} else if ok {
				sent++
			}
		}
		return Success(c, http.StatusOK, map[string]any{"sent": sent}, nil)
	}
}

func defaultNotifyEnabled(key string) bool {
	for _, t := range notifyTypes {
		if t.key == key {
			return t.defaultEnabled
		}
	}
	return false
}

func intIn(list []int, v int) bool {
	for _, x := range list {
		if x == v {
			return true
		}
	}
	return false
}

func validHM(s string) bool {
	if len(s) != 5 || s[2] != ':' {
		return false
	}
	h, m := 0, 0
	for i, ch := range s {
		if i == 2 {
			continue
		}
		if ch < '0' || ch > '9' {
			return false
		}
		if i < 2 {
			h = h*10 + int(ch-'0')
		} else {
			m = m*10 + int(ch-'0')
		}
	}
	_ = m
	return h < 24 && m < 60
}

func validTZ(s string) bool {
	if s == "" || len(s) > 64 {
		return false
	}
	for _, ch := range s {
		if !(ch >= 'A' && ch <= 'Z' || ch >= 'a' && ch <= 'z' || ch >= '0' && ch <= '9' || ch == '_' || ch == '-' || ch == '+' || ch == '/') {
			return false
		}
	}
	return true
}
