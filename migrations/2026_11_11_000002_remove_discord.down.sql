CREATE TABLE `discord_bot_settings` (
  `id` INTEGER NOT NULL,
  `bot_token` TEXT NULL,
  `bot_name` TEXT NOT NULL DEFAULT 'EVE Industrialist Bot',
  `bot_avatar_url` TEXT NULL,
  `welcome_channel_id` TEXT NULL,
  `welcome_message` TEXT NULL,
  `is_enabled` INTEGER NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`)
);
