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
	ID              int64          `db:"id"`
	UserID          int64          `db:"user_id"`
	Endpoint        string         `db:"endpoint"`
	EndpointHash    string         `db:"endpoint_hash"`
	PublicKey       string         `db:"public_key"`
	AuthToken       string         `db:"auth_token"`
	ContentEncoding string         `db:"content_encoding"`
	UserAgent       sql.NullString `db:"user_agent"`
	SubscribedAt    time.Time      `db:"subscribed_at"`
}

// NotificationPref mirrors one notification_preferences row.
type NotificationPref struct {
	UserID       int64         `db:"user_id"`
	Type         string        `db:"type"`
	Enabled      bool          `db:"enabled"`
	LeadMinutes  sql.NullInt64 `db:"lead_minutes"`
	CharacterIDs string        `db:"character_ids"`
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
	PushSubscriptions(ctx context.Context, userID int64) ([]PushSubscription, error)
	UpdateSubscriptionUsed(ctx context.Context, id int64, at time.Time) error
	DeleteSubscription(ctx context.Context, id int64) error
	ClaimNotification(ctx context.Context, userID int64, typ, dedupe string, at time.Time) (bool, error)
	ReleaseNotificationClaim(ctx context.Context, userID int64, dedupe string) error
	NotifyCorporationIDs(ctx context.Context, userID int64) ([]int64, error)
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
		VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
		ON CONFLICT(endpoint_hash) DO UPDATE SET user_id = excluded.user_id, endpoint = excluded.endpoint,
			public_key = excluded.public_key, auth_token = excluded.auth_token,
			content_encoding = excluded.content_encoding, user_agent = excluded.user_agent,
			subscribed_at = iif(user_id = excluded.user_id, subscribed_at, excluded.subscribed_at), updated_at = CURRENT_TIMESTAMP`,
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
		VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
		ON CONFLICT(user_id, type) DO UPDATE SET enabled = excluded.enabled, lead_minutes = excluded.lead_minutes,
			character_ids = excluded.character_ids, updated_at = CURRENT_TIMESTAMP`,
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
		VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
		ON CONFLICT(user_id) DO UPDATE SET quiet_start = excluded.quiet_start, quiet_end = excluded.quiet_end,
			timezone = excluded.timezone, updated_at = CURRENT_TIMESTAMP`,
		q.UserID, q.Start, q.End, q.Timezone)
	return err
}

// PushSubscriptions lists a user's devices.
func (s *SQL) PushSubscriptions(ctx context.Context, userID int64) ([]PushSubscription, error) {
	var out []PushSubscription
	err := s.db.SelectContext(ctx, &out, `SELECT `+pushCols+`
		FROM push_subscriptions WHERE user_id = ? ORDER BY id`, userID)
	if out == nil {
		out = []PushSubscription{}
	}
	return out, err
}

// UpdateSubscriptionUsed stamps last_used_at after a delivery.
func (s *SQL) UpdateSubscriptionUsed(ctx context.Context, id int64, at time.Time) error {
	_, err := s.db.ExecContext(ctx, `UPDATE push_subscriptions SET last_used_at = ? WHERE id = ?`, at, id)
	return err
}

// DeleteSubscription removes one device.
func (s *SQL) DeleteSubscription(ctx context.Context, id int64) error {
	_, err := s.db.ExecContext(ctx, `DELETE FROM push_subscriptions WHERE id = ?`, id)
	return err
}

// ClaimNotification ports the deliver() claim: insertOrIgnore on the
// (user, dedupe) key. False means already sent.
func (s *SQL) ClaimNotification(ctx context.Context, userID int64, typ, dedupe string, at time.Time) (bool, error) {
	res, err := s.db.ExecContext(ctx, `INSERT OR IGNORE INTO notification_log (user_id, type, dedupe_key, sent_at)
		VALUES (?, ?, ?, ?)`, userID, typ, dedupe, at)
	if err != nil {
		return false, err
	}
	n, err := res.RowsAffected()
	return n > 0, err
}

// ReleaseNotificationClaim drops the claim so the next run retries.
func (s *SQL) ReleaseNotificationClaim(ctx context.Context, userID int64, dedupe string) error {
	_, err := s.db.ExecContext(ctx, `DELETE FROM notification_log WHERE user_id = ? AND dedupe_key = ?`, userID, dedupe)
	return err
}

// PruneNotificationLog deletes entries older than 14 days.
func (s *SQL) PruneNotificationLog(ctx context.Context) (int64, error) {
	res, err := s.db.ExecContext(ctx, `DELETE FROM notification_log WHERE sent_at < datetime('now', '-14 days')`)
	if err != nil {
		return 0, err
	}
	return res.RowsAffected()
}

// NotifyCorporationIDs lists distinct non-zero corporation ids of a user.
func (s *SQL) NotifyCorporationIDs(ctx context.Context, userID int64) ([]int64, error) {
	var out []int64
	err := s.db.SelectContext(ctx, &out, `SELECT DISTINCT corporation_id FROM characters
		WHERE user_id = ? AND corporation_id > 0`, userID)
	if out == nil {
		out = []int64{}
	}
	return out, err
}
