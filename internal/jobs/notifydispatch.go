package jobs

import (
	"context"
	"encoding/json"
	"time"

	"github.com/sudo-adduser-jordan/eveindustry/internal/auth"
	"github.com/sudo-adduser-jordan/eveindustry/internal/industry"
	"github.com/sudo-adduser-jordan/eveindustry/internal/notify"
	"github.com/sudo-adduser-jordan/eveindustry/internal/pi"
	"github.com/sudo-adduser-jordan/eveindustry/internal/store"
)

// RegisterNotifyJobs wires notifications:dispatch (default, 1 try, 120s per user).
func RegisterNotifyJobs(r *Runner, h HandlerDeps) {
	r.Register("dispatch-user-notifications", JobDef{
		Queue: QueueDefault, MaxTries: 1, Timeout: 120 * time.Second,
		Run: func(ctx context.Context, d Deps, raw json.RawMessage) error {
			var p struct {
				UserID int64 `json:"user_id"`
			}
			if err := json.Unmarshal(raw, &p); err != nil || p.UserID == 0 {
				return nil
			}
			_, err := DispatchUserNotifications(ctx, h, p.UserID, time.Now().UTC())
			return err
		},
	})
}

// NotifySchedule mirrors notifications:dispatch every 5 minutes: prune the
// log, then queue detection for active users with a subscribed device.
func NotifySchedule(h HandlerDeps) []ScheduleEntry {
	return []ScheduleEntry{
		{
			Name: "notifications:dispatch", Every: 5 * time.Minute, Overlap: 5 * time.Minute,
			Run: func(ctx context.Context, r *Runner, q Queue) error {
				if _, err := h.DB.PruneNotificationLog(ctx); err != nil {
					return err
				}
				users, err := h.DB.ActiveUserIDs(ctx)
				if err != nil {
					return err
				}
				for _, uid := range users {
					n, err := h.DB.PushSubscriptionCount(ctx, uid)
					if err != nil || n == 0 {
						continue
					}
					raw, _ := json.Marshal(map[string]any{"user_id": uid})
					if err := r.Dispatch(ctx, q, "dispatch-user-notifications", raw); err != nil {
						return err
					}
				}
				return nil
			},
		},
	}
}

// DispatchUserNotifications ports NotificationDispatcher::dispatchForUser.
func DispatchUserNotifications(ctx context.Context, h HandlerDeps, userID int64, now time.Time) (int, error) {
	chars, err := h.DB.EsiActiveCharacters(ctx, &userID)
	if err != nil {
		return 0, err
	}
	subs, err := h.DB.PushSubscriptions(ctx, userID)
	if err != nil {
		return 0, err
	}
	if len(subs) == 0 {
		return 0, nil
	}
	qh, err := h.DB.QuietHours(ctx, userID)
	if err != nil {
		return 0, err
	}
	if notify.IsQuiet(qh, now) {
		return 0, nil
	}
	ownIDs := map[int64]bool{}
	hasCorp := false
	for _, ch := range chars {
		ownIDs[ch.CharacterID] = true
		if ch.CorporationID != nil && *ch.CorporationID != 0 {
			hasCorp = true
		}
	}
	stored, err := h.DB.NotificationPrefs(ctx, userID)
	if err != nil {
		return 0, err
	}
	effective := notify.EffectivePrefs(stored, ownIDs)
	since := notBefore(subs, now)
	delivered := 0
	for _, t := range notify.Types {
		st := effective[t.Key]
		if !st.Enabled {
			continue
		}
		if t.RequiresCorp && !hasCorp {
			continue
		}
		msgs, err := detectType(ctx, h, userID, t.Key, chars, st, now, since)
		if err != nil {
			h.logger().Warn("detector failed", "type", t.Key, "user", userID, "err", err)
			continue
		}
		for _, m := range msgs {
			if deliver(ctx, h, userID, subs, m, now) {
				delivered++
			}
		}
	}
	return delivered, nil
}

func notBefore(subs []store.PushSubscription, now time.Time) time.Time {
	floor := now.Add(-notify.MaxLookbackHours * time.Hour)
	earliest := now
	first := true
	for _, s := range subs {
		if first || s.SubscribedAt.Before(earliest) {
			earliest = s.SubscribedAt
			first = false
		}
	}
	if first || floor.After(earliest) {
		return floor
	}
	return earliest
}

func detectType(ctx context.Context, h HandlerDeps, userID int64, key string, chars []auth.Character, st notify.PrefState, now, since time.Time) ([]notify.Message, error) {
	switch key {
	case "industry.job_completed":
		jobs, err := finishedJobs(ctx, h, chars, since, now)
		if err != nil {
			return nil, err
		}
		allowed := map[int64]bool{}
		for _, id := range st.CharacterIDs {
			allowed[id] = true
		}
		return notify.DetectIndustryCompleted(jobs, allowed), nil
	case "pi.extractor_expiring":
		lead := 60
		if st.LeadMinutes != nil {
			lead = *st.LeadMinutes
		}
		planets, err := piPlanets(ctx, h, chars, now)
		if err != nil {
			return nil, err
		}
		allowed := map[int64]bool{}
		for _, id := range st.CharacterIDs {
			allowed[id] = true
		}
		return notify.DetectPiExpiring(planets, allowed, time.Duration(lead)*time.Minute, now, since), nil
	case "structure.window_opening", "structure.timer":
		lead := 60
		if st.LeadMinutes != nil {
			lead = *st.LeadMinutes
		}
		corpIDs, err := h.DB.NotifyCorporationIDs(ctx, userID)
		if err != nil {
			return nil, err
		}
		windows, err := h.DB.CorpWindows(ctx, corpIDs)
		if err != nil {
			return nil, err
		}
		return notify.DetectStructureWindows(windows, key == "structure.timer", time.Duration(lead)*time.Minute, now), nil
	}
	return nil, nil
}

// finishedJobs ports getFinishedJobsForUser: active/ready jobs ending in
// (since, until], reading the same cached ESI lists as the dashboard.
func finishedJobs(ctx context.Context, h HandlerDeps, chars []auth.Character, since, until time.Time) ([]industry.FinishedJob, error) {
	activityNames := map[int]string{
		1: "Manufacturing", 3: "Time efficiency research", 4: "Material efficiency research",
		5: "Copying", 8: "Invention", 9: "Reactions",
	}
	var out []industry.FinishedJob
	for _, ch := range chars {
		jobs, err := h.ESIChars.GetIndustryJobs(ctx, ch.CharacterID, false)
		if err != nil {
			continue
		}
		seen := map[int64]bool{}
		for _, j := range jobs {
			status, _ := j["status"].(string)
			if status != "active" && status != "ready" {
				continue
			}
			end, ok := parseJobTime(strOfJob(j["end_date"]))
			if !ok || !end.After(since) || end.After(until) {
				continue
			}
			id, _ := intOf(j["job_id"])
			if seen[id] || id == 0 {
				continue
			}
			seen[id] = true
			typeID, _ := intOf(firstNonZero(j, "product_type_id", "blueprint_type_id"))
			name := "an item"
			if typeID != 0 {
				if info, err := h.ESIUniverse.GetTypeInfo(ctx, int(typeID)); err == nil && info != nil {
					if n, ok := info["name"].(string); ok && n != "" {
						name = n
					}
				}
			}
			act, _ := intOf(j["activity_id"])
			out = append(out, industry.FinishedJob{
				JobID: id, CharacterID: ch.CharacterID, CharacterName: ch.Name,
				ActivityID: int(act), ActivityName: activityNames[int(act)],
				ItemName: name, EndDate: end.Format(time.RFC3339),
			})
		}
	}
	return out, nil
}

// piPlanets ports the extractor detector's colony scan.
func piPlanets(ctx context.Context, h HandlerDeps, chars []auth.Character, now time.Time) ([]notify.PiPlanetWithChar, error) {
	colonies, err := pi.AnalyzeColonies(ctx, pi.ColonyDeps{
		Characters: h.ESIChars, Universe: h.ESIUniverse, Industry: h.Industry,
	}, chars, now)
	if err != nil {
		return nil, err
	}
	var out []notify.PiPlanetWithChar
	for _, c := range colonies {
		p := notify.PiPlanetWithChar{
			CharacterID: c.Char.CharacterID, CharacterName: c.Char.Name,
			PlanetID: c.PlanetID, PlanetName: c.Name,
		}
		for _, pv := range c.Analysis.Pins {
			pin, _ := pv.(map[string]any)
			if pin == nil {
				continue
			}
			ext, _ := pin["extractor"].(map[string]any)
			if ext == nil {
				continue
			}
			exp, _ := ext["expiry_time"].(string)
			if exp == "" {
				continue
			}
			if t, err := time.Parse(time.RFC3339, exp); err == nil {
				u := t.UTC()
				p.Pins = append(p.Pins, notify.PiPin{Expiry: &u})
			}
		}
		out = append(out, p)
	}
	return out, nil
}

// deliver ports the dispatcher deliver(): claim, send to every device, prune
// gone subscriptions, release the claim when nothing was accepted.
func deliver(ctx context.Context, h HandlerDeps, userID int64, subs []store.PushSubscription, m notify.Message, now time.Time) bool {
	claimed, err := h.DB.ClaimNotification(ctx, userID, m.Type, m.DedupeKey, now)
	if err != nil || !claimed {
		return false
	}
	accepted := false
	for _, sub := range subs {
		sent, gone := h.Sender.Send(ctx, sub, m.Payload())
		if sent {
			accepted = true
			_ = h.DB.UpdateSubscriptionUsed(ctx, sub.ID, now)
		} else if gone {
			_ = h.DB.DeleteSubscription(ctx, sub.ID)
		}
	}
	if !accepted {
		_ = h.DB.ReleaseNotificationClaim(ctx, userID, m.DedupeKey)
	}
	return accepted
}

func parseJobTime(s string) (time.Time, bool) {
	for _, layout := range []string{time.RFC3339, "2006-01-02T15:04:05Z", time.RFC3339Nano} {
		if t, err := time.Parse(layout, s); err == nil {
			return t.UTC(), true
		}
	}
	return time.Time{}, false
}

func strOfJob(v any) string {
	s, _ := v.(string)
	return s
}

func firstNonZero(j map[string]any, keys ...string) any {
	for _, k := range keys {
		if n, ok := intOf(j[k]); ok && n != 0 {
			return n
		}
	}
	return 0
}
