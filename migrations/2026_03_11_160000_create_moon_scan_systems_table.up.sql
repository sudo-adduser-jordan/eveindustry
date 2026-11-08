CREATE TABLE `moon_scan_systems` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
  `system_name` VARCHAR(255) NOT NULL,
  `corporation_id` BIGINT UNSIGNED NULL,
  `region_id` BIGINT UNSIGNED NOT NULL,
  `pricing_mode` VARCHAR(16) NOT NULL DEFAULT 'raw',
  `last_scanned_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY (`system_name`,`corporation_id`),
  KEY (`corporation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
