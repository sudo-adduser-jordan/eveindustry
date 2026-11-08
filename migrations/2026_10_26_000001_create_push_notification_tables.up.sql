CREATE TABLE `push_subscriptions` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `endpoint` TEXT NOT NULL,
  `endpoint_hash` VARCHAR(64) NOT NULL,
  `public_key` VARCHAR(255) NOT NULL,
  `auth_token` VARCHAR(255) NOT NULL,
  `content_encoding` VARCHAR(20) NOT NULL DEFAULT 'aes128gcm',
  `user_agent` VARCHAR(255) NULL,
  `subscribed_at` TIMESTAMP NOT NULL,
  `last_used_at` TIMESTAMP NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY (`endpoint_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `notification_preferences` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `type` VARCHAR(64) NOT NULL,
  `enabled` TINYINT(1) NOT NULL,
  `lead_minutes` SMALLINT UNSIGNED NULL,
  `character_ids` JSON NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY (`user_id`,`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `notification_settings` (
  `user_id` BIGINT UNSIGNED NOT NULL,
  `quiet_start` VARCHAR(5) NULL,
  `quiet_end` VARCHAR(5) NULL,
  `timezone` VARCHAR(64) NOT NULL DEFAULT 'UTC',
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `notification_log` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `type` VARCHAR(64) NOT NULL,
  `dedupe_key` VARCHAR(191) NOT NULL,
  `sent_at` TIMESTAMP NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY (`user_id`,`dedupe_key`),
  KEY (`sent_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
