CREATE TABLE `moon_scan_results` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
  `moon_name` VARCHAR(255) NOT NULL,
  `planet_name` VARCHAR(255) NULL,
  `system_name` VARCHAR(255) NULL,
  `grade` VARCHAR(2) NOT NULL DEFAULT 'D',
  `ores` JSON NOT NULL,
  `total_value` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `pricing_mode` VARCHAR(16) NOT NULL DEFAULT 'raw',
  `region_id` BIGINT UNSIGNED NOT NULL,
  `scanned_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  KEY `moon_scan_results_system_name_scanned_at_index` (`system_name`,`scanned_at`),
  KEY (`scanned_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
