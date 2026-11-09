CREATE TABLE `notification_settings_new` (
  `user_id` INTEGER NOT NULL,
  `quiet_start` TEXT NULL,
  `quiet_end` TEXT NULL,
  `timezone` TEXT NOT NULL DEFAULT 'UTC',
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  UNIQUE (`user_id`)
);
INSERT INTO `notification_settings_new` (`user_id`, `quiet_start`, `quiet_end`, `timezone`, `created_at`, `updated_at`)
  SELECT `user_id`, `quiet_start`, `quiet_end`, `timezone`, `created_at`, `updated_at`
  FROM `notification_settings`
  WHERE rowid IN (SELECT MAX(rowid) FROM `notification_settings` GROUP BY `user_id`);
DROP TABLE `notification_settings`;
ALTER TABLE `notification_settings_new` RENAME TO `notification_settings`;
