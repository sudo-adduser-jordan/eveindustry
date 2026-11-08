CREATE TABLE `ess_payout_events` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
  `ess_tracked_system_id` BIGINT UNSIGNED NOT NULL,
  `occurred_at` TIMESTAMP NOT NULL,
  `amount_isk` DECIMAL(20,2) NULL,
  `recorded_by_user_id` BIGINT UNSIGNED NULL,
  `notes` TEXT NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  KEY (`ess_tracked_system_id`,`occurred_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
