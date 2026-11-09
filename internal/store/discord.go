package store

import (
	"context"
	"database/sql"
)

// DiscordBotSettings mirrors the singleton discord_bot_settings row.
type DiscordBotSettings struct {
	ID               int64          `db:"id"`
	BotToken         sql.NullString `db:"bot_token"`
	BotName          string         `db:"bot_name"`
	BotAvatarURL     sql.NullString `db:"bot_avatar_url"`
	WelcomeChannelID sql.NullString `db:"welcome_channel_id"`
	WelcomeMessage   sql.NullString `db:"welcome_message"`
	IsEnabled        bool           `db:"is_enabled"`
}

// DiscordStore covers the welcome-bot settings singleton.
type DiscordStore interface {
	DiscordSettings(ctx context.Context) (*DiscordBotSettings, error)
	UpdateDiscordSettings(ctx context.Context, s DiscordBotSettings) (*DiscordBotSettings, error)
}

// DiscordSettings loads the singleton, creating defaults like getSettings().
func (s *SQL) DiscordSettings(ctx context.Context) (*DiscordBotSettings, error) {
	var out DiscordBotSettings
	err := s.db.GetContext(ctx, &out, `SELECT id, bot_token, bot_name, bot_avatar_url,
		welcome_channel_id, welcome_message, is_enabled FROM discord_bot_settings LIMIT 1`)
	if err == sql.ErrNoRows {
		if _, err := s.db.ExecContext(ctx, `INSERT INTO discord_bot_settings
			(bot_name, welcome_message, is_enabled, created_at, updated_at)
			VALUES ('EVE Industrialist Bot', 'Welcome to the server, {user}!', 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)`); err != nil {
			return nil, err
		}
		return s.DiscordSettings(ctx)
	}
	if err != nil {
		return nil, err
	}
	return &out, nil
}

// UpdateDiscordSettings applies the settings edit.
func (s *SQL) UpdateDiscordSettings(ctx context.Context, in DiscordBotSettings) (*DiscordBotSettings, error) {
	cur, err := s.DiscordSettings(ctx)
	if err != nil {
		return nil, err
	}
	if _, err := s.db.ExecContext(ctx, `UPDATE discord_bot_settings SET bot_token = ?,
		bot_name = ?, bot_avatar_url = ?, welcome_channel_id = ?, welcome_message = ?,
		is_enabled = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?`,
		in.BotToken, in.BotName, in.BotAvatarURL, in.WelcomeChannelID,
		in.WelcomeMessage, in.IsEnabled, cur.ID); err != nil {
		return nil, err
	}
	return s.DiscordSettings(ctx)
}
