package jobs

import (
	"context"
	"encoding/json"
	"time"
)

func charPayload(id int64) json.RawMessage {
	raw, _ := json.Marshal(map[string]int64{"character_id": id})
	return raw
}

func userPayload(userID int64, force bool) json.RawMessage {
	raw, _ := json.Marshal(map[string]any{"user_id": userID, "force": force})
	return raw
}

func decodeUserPayload(raw json.RawMessage) (int64, bool) {
	var p struct {
		UserID int64 `json:"user_id"`
		Force  bool  `json:"force"`
	}
	if err := json.Unmarshal(raw, &p); err != nil {
		return 0, false
	}
	return p.UserID, p.Force
}

func decodeCharPayload(raw json.RawMessage) (int64, bool) {
	var p struct {
		CharacterID int64 `json:"character_id"`
	}
	if err := json.Unmarshal(raw, &p); err != nil {
		return 0, false
	}
	return p.CharacterID, true
}

func RegisterRefreshJobs(r *Runner, h HandlerDeps) {
	r.Register("refresh-character-profile", JobDef{
		Queue: QueueDefault, MaxTries: 3, Timeout: 180 * time.Second, Unique: time.Hour,
		Run: func(ctx context.Context, d Deps, raw json.RawMessage) error {
			id, ok := decodeCharPayload(raw)
			if !ok {
				return nil
			}
			return RefreshCharacterProfile(ctx, h, id)
		},
	})
	r.Register("refresh-asset-data", JobDef{
		Queue: QueueDefault, MaxTries: 3, Timeout: 180 * time.Second, Unique: time.Hour,
		Run: func(ctx context.Context, d Deps, raw json.RawMessage) error {
			uid, force := decodeUserPayload(raw)
			if uid == 0 {
				return nil
			}
			return RefreshAssetData(ctx, h, uid, force)
		},
	})
	r.Register("refresh-industry-data", JobDef{
		Queue: QueueDefault, MaxTries: 2, Timeout: 120 * time.Second, Unique: time.Hour,
		Run: func(ctx context.Context, d Deps, raw json.RawMessage) error {
			uid, _ := decodeUserPayload(raw)
			if uid == 0 {
				return nil
			}
			return RefreshIndustryData(ctx, h, uid)
		},
	})
	r.Register("refresh-dashboard-stats", JobDef{
		Queue: QueueDefault, MaxTries: 3, Timeout: 180 * time.Second, Unique: time.Hour,
		Run: func(ctx context.Context, d Deps, raw json.RawMessage) error {
			uid, _ := decodeUserPayload(raw)
			if uid == 0 {
				return nil
			}
			return RefreshDashboardStats(ctx, h, uid)
		},
	})
}

func RefreshSchedule(h HandlerDeps) []ScheduleEntry {
	return []ScheduleEntry{
		{
			Name: "characters:refresh-data", Every: 30 * time.Minute, Overlap: 30 * time.Minute,
			Run: func(ctx context.Context, r *Runner, q Queue) error {
				chars, err := h.DB.EsiActiveCharacters(ctx, nil)
				if err != nil {
					return err
				}
				for _, ch := range chars {
					if err := r.Dispatch(ctx, q, "refresh-character-profile", charPayload(ch.CharacterID)); err != nil {
						return err
					}
				}
				return nil
			},
		},
		{
			Name: "industry:refresh", Every: 30 * time.Minute, Overlap: 30 * time.Minute,
			Run: func(ctx context.Context, r *Runner, q Queue) error {
				return fanOutUsers(ctx, r, q, h, "refresh-industry-data")
			},
		},
		{
			Name: "assets:refresh", Every: 30 * time.Minute, Overlap: 30 * time.Minute,
			Run: func(ctx context.Context, r *Runner, q Queue) error {
				return fanOutUsers(ctx, r, q, h, "refresh-asset-data")
			},
		},
		{
			Name: "dashboard:refresh", Every: 15 * time.Minute, Overlap: 15 * time.Minute,
			Run: func(ctx context.Context, r *Runner, q Queue) error {
				return fanOutUsers(ctx, r, q, h, "refresh-dashboard-stats")
			},
		},
	}
}

func fanOutUsers(ctx context.Context, r *Runner, q Queue, h HandlerDeps, job string) error {
	users, err := ActiveUserIDs(ctx, h.DB)
	if err != nil {
		return err
	}
	for _, uid := range users {
		if err := r.Dispatch(ctx, q, job, userPayload(uid, false)); err != nil {
			return err
		}
	}
	return nil
}
