CREATE TABLE `ess_tracked_systems` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
  `corporation_id` INT NOT NULL,
  `solar_system_id` INT NOT NULL,
  `solar_system_name` VARCHAR(255) NOT NULL,
  `cycle_minutes` SMALLINT UNSIGNED NOT NULL DEFAULT 165,
  `cycle_anchor_at` TIMESTAMP NOT NULL,
  `notes` TEXT NULL,
  `created_by_user_id` BIGINT UNSIGNED NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY (`corporation_id`,`solar_system_id`),
  KEY (`corporation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
