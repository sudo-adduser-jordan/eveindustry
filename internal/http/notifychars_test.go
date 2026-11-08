package http

import (
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"

	"github.com/sudo-adduser-jordan/eveindustry/internal/auth"
	"github.com/sudo-adduser-jordan/eveindustry/internal/store"
)

type stubNotify struct {
	subs  map[int64]map[string]store.PushSubscription
	prefs map[int64][]store.NotificationPref
	quiet map[int64]*store.QuietHours
}

func (s *stubNotify) UpsertPushSubscription(ctx context.Context, sub store.PushSubscription) error {
	if s.subs == nil {
		s.subs = map[int64]map[string]store.PushSubscription{}
	}
	if s.subs[sub.UserID] == nil {
		s.subs[sub.UserID] = map[string]store.PushSubscription{}
	}
	s.subs[sub.UserID][sub.EndpointHash] = sub
	return nil
}
func (s *stubNotify) DeletePushSubscription(ctx context.Context, uid int64, hash string) error {
	delete(s.subs[uid], hash)
	return nil
}
func (s *stubNotify) PushSubscriptionCount(ctx context.Context, uid int64) (int, error) {
	return len(s.subs[uid]), nil
}
func (s *stubNotify) NotificationPrefs(ctx context.Context, uid int64) ([]store.NotificationPref, error) {
	return s.prefs[uid], nil
}
func (s *stubNotify) UpsertNotificationPref(ctx context.Context, p store.NotificationPref) error {
	var out []store.NotificationPref
	for _, e := range s.prefs[p.UserID] {
		if e.Type != p.Type {
			out = append(out, e)
		}
	}
	s.prefs[p.UserID] = append(out, p)
	return nil
}
func (s *stubNotify) QuietHours(ctx context.Context, uid int64) (*store.QuietHours, error) {
	if q, ok := s.quiet[uid]; ok {
		return q, nil
	}
	return &store.QuietHours{UserID: uid, Timezone: "UTC"}, nil
}
func (s *stubNotify) UpdateQuietHours(ctx context.Context, q store.QuietHours) error {
	if s.quiet == nil {
		s.quiet = map[int64]*store.QuietHours{}
	}
	qc := q
	s.quiet[q.UserID] = &qc
	return nil
}

func notifyTestDeps() Deps {
	stub := testAuth()
	corpID := int64(2001)
	stub.chars = []auth.Character{
		{CharacterID: 11, Name: "Main", IsMain: true, IsEsiActive: true, CorporationID: &corpID},
		{CharacterID: 12, Name: "Alt", IsEsiActive: true, CorporationID: &corpID},
	}
	return Deps{Sessions: stub, Users: stub,
		Notify:     &stubNotify{prefs: map[int64][]store.NotificationPref{}},
		NotifyPush: NotifyPush{},
		Plans:      &stubPlans{inv: map[int64]store.InventionPlan{}, reac: map[int64]store.ReactionPlan{}, now: time.Now()},
		Corp:       &stubCorp{},
	}
}

func TestCharacters(t *testing.T) {
	d := notifyTestDeps()
	if code := postRaw(t, d, "/api/v1/characters/12/main", `{}`); code != http.StatusNoContent {
		t.Fatalf("setmain = %d", code)
	}
	if code, _ := deleteReq(t, d, "/api/v1/characters/12"); code != http.StatusNoContent {
		t.Fatalf("delete = %d", code)
	}
	// only one left -> 422
	if code, _ := deleteReq(t, d, "/api/v1/characters/11"); code != http.StatusUnprocessableEntity {
		t.Fatalf("last = %d", code)
	}
	// foreign -> 404
	d2 := notifyTestDeps()
	d2.Sessions.(*stubAuth).uid = 2
	if code := postRaw(t, d2, "/api/v1/characters/11/main", `{}`); code != http.StatusNotFound {
		t.Fatalf("foreign = %d", code)
	}
}

func TestNotifications(t *testing.T) {
	d := notifyTestDeps()
	code, body := get(t, d, "/api/v1/notifications")
	if code != http.StatusOK {
		t.Fatalf("show = %d %v", code, body)
	}
	data := body["data"].(map[string]any)
	if data["devices"] != 0.0 || len(data["types"].([]any)) != 4 {
		t.Fatalf("config = %v", data)
	}
	types := data["types"].([]any)
	if types[0].(map[string]any)["enabled"] != true || types[1].(map[string]any)["enabled"] != false {
		t.Fatalf("defaults = %v", types)
	}
	if types[2].(map[string]any)["available"] != true {
		t.Fatalf("corp available = %v", types[2])
	}
	// subscribe
	sub := `{"endpoint":"https://push.example/x","keys":{"p256dh":"k","auth":"a"}}`
	if code, body := putJSON(t, d, "/api/v1/notifications/subscription", sub); code != http.StatusOK {
		t.Fatalf("sub = %d %v", code, body)
	} else if body["data"].(map[string]any)["devices"] != 1.0 {
		t.Fatalf("devices = %v", body["data"])
	}
	if code, _ := putJSON(t, d, "/api/v1/notifications/subscription", `{"endpoint":"http://x"}`); code != http.StatusUnprocessableEntity {
		t.Fatalf("bad endpoint = %d", code)
	}
	// update prefs
	upd := `{"types":{"pi.extractor_expiring":{"enabled":true,"lead_minutes":15,"character_ids":[12]}},"quiet_hours":{"start":"22:00","end":"07:00","timezone":"UTC"}}`
	if code, body := putJSON(t, d, "/api/v1/notifications", upd); code != http.StatusOK {
		t.Fatalf("update = %d %v", code, body)
	} else {
		types := body["data"].(map[string]any)["types"].([]any)
		var pi map[string]any
		for _, tv := range types {
			if tv.(map[string]any)["key"] == "pi.extractor_expiring" {
				pi = tv.(map[string]any)
			}
		}
		if pi["enabled"] != true || pi["lead_minutes"] != 15.0 {
			t.Fatalf("pi = %v", pi)
		}
	}
	// unknown type -> 422
	if code, _ := putJSON(t, d, "/api/v1/notifications", `{"types":{"nope.x":{"enabled":true}}}`); code != http.StatusUnprocessableEntity {
		t.Fatalf("bad type = %d", code)
	}
	// foreign character -> 422
	if code, _ := putJSON(t, d, "/api/v1/notifications", `{"types":{"pi.extractor_expiring":{"character_ids":[99]}}}`); code != http.StatusUnprocessableEntity {
		t.Fatalf("bad char = %d", code)
	}
	// unsubscribe
	if code, body := deleteJSON(t, d, "/api/v1/notifications/subscription", `{"endpoint":"https://push.example/x"}`); code != http.StatusOK {
		t.Fatalf("unsub = %d %v", code, body)
	} else if body["data"].(map[string]any)["devices"] != 0.0 {
		t.Fatalf("devices = %v", body["data"])
	}
	// test -> 503
	if code, _ := post(t, d, "/api/v1/notifications/test", `{}`); code != http.StatusServiceUnavailable {
		t.Fatalf("test = %d", code)
	}
}

func deleteJSON(t *testing.T, d Deps, path, body string) (int, map[string]any) {
	t.Helper()
	e := New(d)
	req := httptest.NewRequest(http.MethodDelete, path, strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	rec := httptest.NewRecorder()
	e.ServeHTTP(rec, req)
	var out map[string]any
	if err := json.Unmarshal(rec.Body.Bytes(), &out); err != nil {
		t.Fatalf("DELETE %s: body is not JSON: %v (%s)", path, err, rec.Body.String())
	}
	return rec.Code, out
}
