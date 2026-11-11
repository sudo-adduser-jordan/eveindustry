package notify

import (
	"context"
	"database/sql"
	"encoding/json"
	"time"

	"github.com/sudo-adduser-jordan/eveindustry/internal/auth"
	"github.com/sudo-adduser-jordan/eveindustry/internal/store"
)

type Recipient struct {
	User  auth.User
	Chars []auth.Character
}

type PushStore interface {
	PushSubscriptions(ctx context.Context, userID int64) ([]store.PushSubscription, error)
	UpdateSubscriptionUsed(ctx context.Context, id int64, at time.Time) error
	DeleteSubscription(ctx context.Context, id int64) error
	NotificationPrefs(ctx context.Context, userID int64) ([]store.NotificationPref, error)
	QuietHours(ctx context.Context, userID int64) (*store.QuietHours, error)
	ClaimNotification(ctx context.Context, userID int64, typ, dedupe string, at time.Time) (bool, error)
	ReleaseNotificationClaim(ctx context.Context, userID int64, dedupe string) error
	CorporationIDs(ctx context.Context, userID int64) ([]int64, error)
}

type QuietWindow struct {
	Start    string
	End      string
	Timezone string
}

type Message struct {
	Type      string
	DedupeKey string
	Title     string
	Body      string
	URL       string
}

func (m Message) Payload() map[string]any {
	return map[string]any{"title": m.Title, "body": m.Body, "url": m.URL, "tag": m.DedupeKey}
}

type Sender interface {
	Send(ctx context.Context, sub store.PushSubscription, payload map[string]any) (sent, gone bool)
}

func EffectivePrefs(stored []store.NotificationPref, charIDs map[int64]bool) map[string]PrefState {
	byType := map[string]store.NotificationPref{}
	for _, p := range stored {
		byType[p.Type] = p
	}
	out := map[string]PrefState{}
	for _, t := range Types {
		row, ok := byType[t.Key]
		st := PrefState{Enabled: t.DefaultEnabled, CharacterIDs: []int64{}}
		if t.LeadOptions != nil {
			lead := 60
			st.LeadMinutes = &lead
		}
		if ok {
			st.Enabled = row.Enabled
			if t.LeadOptions != nil && row.LeadMinutes.Valid {
				v := int(row.LeadMinutes.Int64)
				st.LeadMinutes = &v
			}
			var ids []int64
			if row.CharacterIDs != "" {
				_ = json.Unmarshal([]byte(row.CharacterIDs), &ids)
			}
			for _, id := range ids {
				if charIDs[id] {
					st.CharacterIDs = append(st.CharacterIDs, id)
				}
			}
		}
		out[t.Key] = st
	}
	return out
}

func IsQuiet(q *store.QuietHours, now time.Time) bool {
	if q == nil || !q.Start.Valid || !q.End.Valid {
		return false
	}
	loc, err := time.LoadLocation(q.Timezone)
	if err != nil {
		loc = time.UTC
	}
	cur := now.In(loc).Format("15:04")
	s, e := q.Start.String, q.End.String
	if s <= e {
		return cur >= s && cur < e
	}
	return cur >= s || cur < e
}

const MaxLookbackHours = 6

func NotBefore(subs []store.PushSubscription, now time.Time) time.Time {
	floor := now.Add(-MaxLookbackHours * time.Hour)
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

type PrefState struct {
	Enabled      bool
	LeadMinutes  *int
	CharacterIDs []int64
}

type NotifyType struct {
	Key            string
	Label          string
	Description    string
	Category       string
	DefaultEnabled bool
	LeadOptions    []int
	SupportsChars  bool
	RequiresCorp   bool
}

var Types = []NotifyType{
	{"industry.job_completed", "Industry job completed",
		"A manufacturing, research, invention or reaction job has finished and is ready to deliver.",
		"Industry", true, nil, true, false},
	{"pi.extractor_expiring", "Planetary extractor expiring",
		"Extractor heads on one of your colonies are about to stop, or have stopped.",
		"Planetary", false, []int{15, 60, 360}, true, false},
	{"structure.window_opening", "Structure vulnerability window opening",
		"Recorded structure vulnerability windows are opening for your corporation.",
		"Corporation", false, []int{15, 60, 360}, false, true},
	{"structure.timer", "Structure timer ending",
		"A recorded reinforcement timer for your corporation is about to end.",
		"Corporation", false, []int{15, 60, 360}, false, true},
}

var _ = sql.ErrNoRows
