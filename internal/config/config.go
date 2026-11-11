package config

import (
	"os"
)

type Config struct {
	AppEnv    string
	Port      string
	AppURL    string
	SourceURL string

	DBPath string

	SessionKey    string
	SessionSecure bool
	SessionDomain string

	ESIClientID          string
	ESIClientSecret      string
	ESIUserAgent         string
	ESICompatibilityDate string
}

func getenv(key, fallback string) string {
	if v := os.Getenv(key); v != "" {
		return v
	}
	return fallback
}

func Load() Config {
	return Config{
		AppEnv:    getenv("APP_ENV", "local"),
		Port:      getenv("PORT", "8080"),
		AppURL:    getenv("APP_URL", "http://localhost:8080"),
		SourceURL: getenv("APP_SOURCE_URL", "https://github.com/sudo-adduser-jordan/eveindustry"),

		DBPath: getenv("DB_PATH", "storage/eveindustry.sqlite"),

		SessionKey:    os.Getenv("SESSION_KEY"),
		SessionSecure: getenv("SESSION_SECURE_COOKIE", "") == "true",
		SessionDomain: os.Getenv("SESSION_DOMAIN"),

		ESIClientID:          os.Getenv("EVE_CLIENT_ID"),
		ESIClientSecret:      os.Getenv("EVE_CLIENT_SECRET"),
		ESIUserAgent:         getenv("ESI_USER_AGENT", "EveIndustrialist/1.0"),
		ESICompatibilityDate: os.Getenv("ESI_COMPATIBILITY_DATE"),
	}
}
