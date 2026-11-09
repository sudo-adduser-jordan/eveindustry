CREATE TABLE `notification_settings_old` (
  `user_id` INTEGER NOT NULL,
  `quiet_start` TEXT NULL,
  `quiet_end` TEXT NULL,
  `timezone` TEXT NOT NULL DEFAULT 'UTC',
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL
);
INSERT INTO `notification_settings_old` (`user_id`, `quiet_start`, `quiet_end`, `timezone`, `created_at`, `updated_at`)
  SELECT `user_id`, `quiet_start`, `quiet_end`, `timezone`, `created_at`, `updated_at` FROM `notification_settings`;
DROP TABLE `notification_settings`;
ALTER TABLE `notification_settings_old` RENAME TO `notification_settings`;
