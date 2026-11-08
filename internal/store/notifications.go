package store

import (
	"context"
	"crypto/sha256"
	"database/sql"
	"encoding/hex"
	"time"
)

// PushSubscription mirrors one push_subscriptions row.
type PushSubscription struct {
	ID            int64          `db:"id"`
	UserID        int64          `db:"user_id"`
	Endpoint      string         `db:"endpoint"`
	EndpointHash  string         `db:"endpoint_hash"`
	PublicKey     string         `db:"public_key"`
	AuthToken     string         `db:"auth_token"`
	ContentEncoding string       `db:"content_encoding"`
	UserAgent     sql.NullString `db:"user_agent"`
	SubscribedAt  time.Time      `db:"subscribed_at"`
}

// NotificationPref mirrors one notification_preferences row.
type NotificationPref struct {
	UserID       int64          `db:"user_id"`
	Type         string         `db:"type"`
	Enabled      bool           `db:"enabled"`
	LeadMinutes  sql.NullInt64  `db:"lead_minutes"`
	CharacterIDs string         `db:"character_ids"`
}

// QuietHours mirrors one notification_settings row.
type QuietHours struct {
	UserID   int64          `db:"user_id"`
	Start    sql.NullString `db:"quiet_start"`
	End      sql.NullString `db:"quiet_end"`
	Timezone string         `db:"timezone"`
}

// NotifyStore covers push subscriptions and notification preferences.
type NotifyStore interface {
	UpsertPushSubscription(ctx context.Context, s PushSubscription) error
	DeletePushSubscription(ctx context.Context, userID int64, endpointHash string) error
	PushSubscriptionCount(ctx context.Context, userID int64) (int, error)
	NotificationPrefs(ctx context.Context, userID int64) ([]NotificationPref, error)
	UpsertNotificationPref(ctx context.Context, p NotificationPref) error
	QuietHours(ctx context.Context, userID int64) (*QuietHours, error)
	UpdateQuietHours(ctx context.Context, q QuietHours) error
}

// HashEndpoint mirrors PushSubscription::hashEndpoint.
func HashEndpoint(endpoint string) string {
	sum := sha256.Sum256([]byte(endpoint))
	return hex.EncodeToString(sum[:])
}

const pushCols = `id, user_id, endpoint, endpoint_hash, public_key, auth_token,
	content_encoding, user_agent, subscribed_at`

// UpsertPushSubscription ports subscribe: same endpoint takes over the user.
func (s *SQL) UpsertPushSubscription(ctx context.Context, sub PushSubscription) error {
	_, err := s.db.ExecContext(ctx, `
		INSERT INTO push_subscriptions
			(user_id, endpoint, endpoint_hash, public_key, auth_token, content_encoding, user_agent, subscribed_at, created_at, updated_at)
		VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
		ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), endpoint = VALUES(endpoint),
			public_key = VALUES(public_key), auth_token = VALUES(auth_token),
			content_encoding = VALUES(content_encoding), user_agent = VALUES(user_agent),
			subscribed_at = IF(user_id = VALUES(user_id), subscribed_at, VALUES(subscribed_at)), updated_at = NOW()`,
		sub.UserID, sub.Endpoint, sub.EndpointHash, sub.PublicKey, sub.AuthToken,
		sub.ContentEncoding, sub.UserAgent, sub.SubscribedAt)
	return err
}

func (s *SQL) DeletePushSubscription(ctx context.Context, userID int64, endpointHash string) error {
	_, err := s.db.ExecContext(ctx, `DELETE FROM push_subscriptions WHERE user_id = ? AND endpoint_hash = ?`, userID, endpointHash)
	return err
}

func (s *SQL) PushSubscriptionCount(ctx context.Context, userID int64) (int, error) {
	var n int
	err := s.db.GetContext(ctx, &n, `SELECT COUNT(*) FROM push_subscriptions WHERE user_id = ?`, userID)
	return n, err
}

func (s *SQL) NotificationPrefs(ctx context.Context, userID int64) ([]NotificationPref, error) {
	var out []NotificationPref
	err := s.db.SelectContext(ctx, &out, `SELECT user_id, type, enabled, lead_minutes, character_ids
		FROM notification_preferences WHERE user_id = ?`, userID)
	if out == nil {
		out = []NotificationPref{}
	}
	return out, err
}

func (s *SQL) UpsertNotificationPref(ctx context.Context, p NotificationPref) error {
	_, err := s.db.ExecContext(ctx, `
		INSERT INTO notification_preferences (user_id, type, enabled, lead_minutes, character_ids, created_at, updated_at)
		VALUES (?, ?, ?, ?, ?, NOW(), NOW())
		ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), lead_minutes = VALUES(lead_minutes),
			character_ids = VALUES(character_ids), updated_at = NOW()`,
		p.UserID, p.Type, p.Enabled, p.LeadMinutes, p.CharacterIDs)
	return err
}

func (s *SQL) QuietHours(ctx context.Context, userID int64) (*QuietHours, error) {
	var out QuietHours
	err := s.db.GetContext(ctx, &out, `SELECT user_id, quiet_start, quiet_end, timezone
		FROM notification_settings WHERE user_id = ?`, userID)
	if err == sql.ErrNoRows {
		return &QuietHours{UserID: userID, Timezone: "UTC"}, nil
	}
	if err != nil {
		return nil, err
	}
	return &out, nil
}

func (s *SQL) UpdateQuietHours(ctx context.Context, q QuietHours) error {
	_, err := s.db.ExecContext(ctx, `
		INSERT INTO notification_settings (user_id, quiet_start, quiet_end, timezone, created_at, updated_at)
		VALUES (?, ?, ?, ?, NOW(), NOW())
		ON DUPLICATE KEY UPDATE quiet_start = VALUES(quiet_start), quiet_end = VALUES(quiet_end),
			timezone = VALUES(timezone), updated_at = NOW()`,
		q.UserID, q.Start, q.End, q.Timezone)
	return err
}
