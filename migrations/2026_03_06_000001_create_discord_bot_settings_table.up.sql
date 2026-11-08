CREATE TABLE `discord_bot_settings` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
  `bot_token` TEXT NULL,
  `bot_name` VARCHAR(255) NOT NULL DEFAULT 'EVE Industrialist Bot',
  `bot_avatar_url` VARCHAR(512) NULL,
  `welcome_channel_id` VARCHAR(30) NULL,
  `welcome_message` TEXT NULL,
  `is_enabled` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
