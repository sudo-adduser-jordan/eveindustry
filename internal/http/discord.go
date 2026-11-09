package http

import (
	"database/sql"
	"net/http"
	"regexp"
	"strings"

	"github.com/labstack/echo/v4"

	"github.com/sudo-adduser-jordan/eveindustry/internal/store"
)

// registerDiscordBot mounts the welcome-bot settings (admin). The whole group
// 404s unless the discord_bot feature switch is on, like EnsureFeatureEnabled.
func registerDiscordBot(v1 *echo.Group, d Deps) {
	if d.Discord == nil || !d.DiscordBotEnabled {
		return
	}
	g := v1.Group("/admin", requireAdminDeps(d))
	g.GET("/discord-bot", handleDiscordShow(d))
	g.PUT("/discord-bot", handleDiscordUpdate(d))
}

func discordShape(s store.DiscordBotSettings) map[string]any {
	var avatar, channel, message any
	if s.BotAvatarURL.Valid {
		avatar = s.BotAvatarURL.String
	}
	if s.WelcomeChannelID.Valid {
		channel = s.WelcomeChannelID.String
	}
	if s.WelcomeMessage.Valid {
		message = s.WelcomeMessage.String
	}
	return map[string]any{
		"is_enabled":    s.IsEnabled,
		"has_bot_token": s.BotToken.Valid && s.BotToken.String != "",
		"bot_name":      s.BotName, "bot_avatar_url": avatar,
		"welcome_channel_id": channel, "welcome_message": message,
	}
}

// GET /admin/discord-bot — settings without the token.
func handleDiscordShow(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		s, err := d.Discord.DiscordSettings(c.Request().Context())
		if err != nil {
			return dbError(c, err)
		}
		return Success(c, http.StatusOK, discordShape(*s), nil)
	}
}

var snowflakeRe = regexp.MustCompile(`^\d{17,20}$`)

// PUT /admin/discord-bot — save settings; token kept unless replaced/cleared.
func handleDiscordUpdate(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		verrs := validationErrors{}
		raw := decodePlanBody(c, verrs)
		if raw == nil && len(verrs) > 0 {
			return verrs.invalid(c)
		}
		if raw == nil {
			raw = map[string]any{}
		}
		s, err := d.Discord.DiscordSettings(c.Request().Context())
		if err != nil {
			return dbError(c, err)
		}
		name, _ := raw["bot_name"].(string)
		if strings.TrimSpace(name) == "" || len([]rune(name)) > 80 {
			verrs.add("bot_name", "The bot name field is required.")
		}
		var avatar *string
		if _, present := raw["bot_avatar_url"]; present {
			if v := raw["bot_avatar_url"]; v != nil {
				a, isStr := v.(string)
				if !isStr || len([]rune(a)) > 512 || !strings.Contains(a, ".") {
					verrs.add("bot_avatar_url", "The bot avatar url field must be a valid URL.")
				} else {
					avatar = &a
				}
			}
		} else if s.BotAvatarURL.Valid {
			v := s.BotAvatarURL.String
			avatar = &v
		}
		var channel *string
		if _, present := raw["welcome_channel_id"]; present {
			if v := raw["welcome_channel_id"]; v != nil {
				ch, isStr := v.(string)
				if !isStr || !snowflakeRe.MatchString(ch) {
					verrs.add("welcome_channel_id", "The welcome channel id format is invalid.")
				} else {
					channel = &ch
				}
			}
		} else if s.WelcomeChannelID.Valid {
			v := s.WelcomeChannelID.String
			channel = &v
		}
		message, _ := raw["welcome_message"].(string)
		if strings.TrimSpace(message) == "" || len([]rune(message)) > 2000 {
			verrs.add("welcome_message", "The welcome message field is required.")
		}
		enabled := false
		if v, present := raw["is_enabled"]; present && v != nil {
			if b, isBool := v.(bool); !isBool {
				verrs.add("is_enabled", "The is enabled field must be true or false.")
			} else {
				enabled = b
			}
		}
		token := s.BotToken
		if v, present := raw["bot_token"]; present && v != nil && v != "" {
			t, isStr := v.(string)
			if !isStr || len([]rune(t)) > 255 {
				verrs.add("bot_token", "The bot token is invalid.")
			} else {
				token = sql.NullString{String: t, Valid: true}
			}
		} else if v, present := raw["clear_bot_token"]; present && v != nil {
			if b, isBool := v.(bool); isBool && b {
				token = sql.NullString{}
			}
		}
		if len(verrs) > 0 {
			return verrs.invalid(c)
		}
		updated, err := d.Discord.UpdateDiscordSettings(c.Request().Context(), store.DiscordBotSettings{
			BotToken: token, BotName: strings.TrimSpace(name),
			BotAvatarURL: sqlNullStrOpt(avatar), WelcomeChannelID: sqlNullStrOpt(channel),
			WelcomeMessage: sqlNullStrOpt(&message), IsEnabled: enabled,
		})
		if err != nil {
			return dbError(c, err)
		}
		return Success(c, http.StatusOK, discordShape(*updated), nil)
	}
}
