CREATE TABLE `push_subscriptions` (
  `id` INTEGER NOT NULL,
  `user_id` INTEGER NOT NULL,
  `endpoint` TEXT NOT NULL,
  `endpoint_hash` TEXT NOT NULL,
  `public_key` TEXT NOT NULL,
  `auth_token` TEXT NOT NULL,
  `content_encoding` TEXT NOT NULL DEFAULT 'aes128gcm',
  `user_agent` TEXT NULL,
  `subscribed_at` TIMESTAMP NOT NULL,
  `last_used_at` TIMESTAMP NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE (`endpoint_hash`)
);
CREATE TABLE `notification_preferences` (
  `id` INTEGER NOT NULL,
  `user_id` INTEGER NOT NULL,
  `type` TEXT NOT NULL,
  `enabled` INTEGER NOT NULL,
  `lead_minutes` INTEGER NULL,
  `character_ids` TEXT NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE (`user_id`,`type`)
);
CREATE TABLE `notification_settings` (
  `user_id` INTEGER NOT NULL,
  `quiet_start` TEXT NULL,
  `quiet_end` TEXT NULL,
  `timezone` TEXT NOT NULL DEFAULT 'UTC',
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  UNIQUE (`user_id`)
);
CREATE TABLE `notification_log` (
  `id` INTEGER NOT NULL,
  `user_id` INTEGER NOT NULL,
  `type` TEXT NOT NULL,
  `dedupe_key` TEXT NOT NULL,
  `sent_at` TIMESTAMP NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE (`user_id`,`dedupe_key`)
);
CREATE INDEX `idx_notification_log_sent_at` ON `notification_log` (`sent_at`);
