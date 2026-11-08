package config

import (
	"os"
	"strconv"
)

// Config mirrors the subset of .env / config/*.php the Go API needs in Phase 0.
// Full game-data, ESI, queue and mail settings arrive with later phases.
type Config struct {
	AppEnv     string
	Port       string
	AppURL     string
	SourceURL  string
	DiscordURL string

	DBDSN string

	RedisAddr     string
	RedisPassword string
	RedisDB       int

	SessionKey    string
	SessionSecure bool
	SessionDomain string
	DiscordBot    bool

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

// Load reads configuration from the environment.
func Load() Config {
	dbDB := getenv("DB_DATABASE", "eve_auth")
	_ = dbDB
	redisDB, _ := strconv.Atoi(getenv("REDIS_DB", "0"))

	return Config{
		AppEnv:     getenv("APP_ENV", "local"),
		Port:       getenv("PORT", "8080"),
		AppURL:     getenv("APP_URL", "http://localhost:8080"),
		SourceURL:  getenv("APP_SOURCE_URL", "https://github.com/sudo-adduser-jordan/eveindustry"),
		DiscordURL: getenv("DISCORD_INVITE_URL", ""),

		// DB_* mirrors .env.example (mysql, port 3306). Empty password/host
		// means "not configured": the server still starts and /health reports
		// database: failed instead of crashing (same as Laravel returning 503).
		DBDSN: dsnFromEnv(),

		RedisAddr:     getenv("REDIS_HOST", "") + ":" + getenv("REDIS_PORT", "6379"),
		RedisPassword: getenv("REDIS_PASSWORD", ""),
		RedisDB:       redisDB,

		SessionKey:    os.Getenv("SESSION_KEY"),
		SessionSecure: getenv("SESSION_SECURE_COOKIE", "") == "true",
		SessionDomain: os.Getenv("SESSION_DOMAIN"),
		DiscordBot:    getenv("DISCORD_BOT", "") == "true",

		ESIClientID:          os.Getenv("EVE_CLIENT_ID"),
		ESIClientSecret:      os.Getenv("EVE_CLIENT_SECRET"),
		ESIUserAgent:         getenv("ESI_USER_AGENT", "EveIndustrialist/1.0"),
		ESICompatibilityDate: os.Getenv("ESI_COMPATIBILITY_DATE"),
	}
}

func dsnFromEnv() string {
	host := os.Getenv("DB_HOST")
	if host == "" {
		return ""
	}
	user := getenv("DB_USERNAME", "eve_auth")
	pass := os.Getenv("DB_PASSWORD")
	port := getenv("DB_PORT", "3306")
	name := getenv("DB_DATABASE", "eve_auth")
	// go-sql-driver/mysql DSN with parseTime so DATETIME scans into time.Time.
	dsn := user + ":" + pass + "@tcp(" + host + ":" + port + ")/" + name + "?parseTime=true&multiStatements=true"
	return dsn
}
