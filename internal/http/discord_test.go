package http

import (
	"context"
	"database/sql"
	"net/http"
	"testing"

	"github.com/sudo-adduser-jordan/eveindustry/internal/store"
)

type stubDiscord struct {
	settings store.DiscordBotSettings
}

func (s *stubDiscord) DiscordSettings(ctx context.Context) (*store.DiscordBotSettings, error) {
	s.settings.BotName = "EVE Industrialist Bot"
	out := s.settings
	return &out, nil
}
func (s *stubDiscord) UpdateDiscordSettings(ctx context.Context, in store.DiscordBotSettings) (*store.DiscordBotSettings, error) {
	s.settings = in
	out := in
	return &out, nil
}

func discordTestDeps(enabled bool) Deps {
	d := adminTestDeps()
	d.Discord = &stubDiscord{}
	d.DiscordBotEnabled = enabled
	return d
}

func TestDiscordBot(t *testing.T) {
	d := discordTestDeps(true)
	code, body := get(t, d, "/api/v1/admin/discord-bot")
	if code != http.StatusOK {
		t.Fatalf("show = %d %v", code, body)
	}
	if body["data"].(map[string]any)["has_bot_token"] != false {
		t.Fatalf("token leaked: %v", body["data"])
	}
	code, body = putJSON(t, d, "/api/v1/admin/discord-bot", `{"bot_name":"Bot","welcome_message":"Hi {user}!","bot_token":"tok123","is_enabled":true}`)
	if code != http.StatusOK {
		t.Fatalf("update = %d %v", code, body)
	}
	data := body["data"].(map[string]any)
	if data["has_bot_token"] != true || data["is_enabled"] != true {
		t.Fatalf("updated = %v", data)
	}
	// bad channel id
	if code, _ := putJSON(t, d, "/api/v1/admin/discord-bot", `{"bot_name":"B","welcome_message":"Hi","welcome_channel_id":"abc"}`); code != http.StatusUnprocessableEntity {
		t.Fatalf("bad channel = %d", code)
	}
	// clear token
	code, body = putJSON(t, d, "/api/v1/admin/discord-bot", `{"bot_name":"Bot","welcome_message":"Hi","clear_bot_token":true}`)
	if code != http.StatusOK || body["data"].(map[string]any)["has_bot_token"] != false {
		t.Fatalf("clear = %d %v", code, body)
	}
	// feature off -> 404
	d2 := discordTestDeps(false)
	if code, _ := get(t, d2, "/api/v1/admin/discord-bot"); code != http.StatusNotFound {
		t.Fatalf("disabled = %d", code)
	}
	var _ = sql.NullString{}
}
