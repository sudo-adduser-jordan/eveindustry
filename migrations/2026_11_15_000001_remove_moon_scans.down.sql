CREATE TABLE `moon_scan_systems` (
  `id` INTEGER NOT NULL,
  `system_name` TEXT NOT NULL,
  `corporation_id` INTEGER NULL,
  `region_id` INTEGER NOT NULL,
  `pricing_mode` TEXT NOT NULL DEFAULT 'raw',
  `last_scanned_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE (`system_name`,`corporation_id`)
);
CREATE INDEX `idx_moon_scan_systems_corporation_id` ON `moon_scan_systems` (`corporation_id`);
CREATE TABLE `moon_scan_results` (
  `id` INTEGER NOT NULL,
  `system_id` INTEGER NULL,
  `moon_name` TEXT NOT NULL,
  `planet_name` TEXT NULL,
  `grade` TEXT NOT NULL DEFAULT 'D',
  `ores` TEXT NOT NULL,
  `total_value` INTEGER NOT NULL DEFAULT 0,
  `scanned_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `occupied` INTEGER NOT NULL DEFAULT 0,
  `occupying_corporation_id` INTEGER NULL,
  `occupying_corporation_name` TEXT NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `moon_scan_results_system_id_foreign` FOREIGN KEY (`system_id`) REFERENCES `moon_scan_systems` (`id`) ON DELETE CASCADE,
  CONSTRAINT `moon_scan_results_system_id_moon_name_unique` UNIQUE (`system_id`,`moon_name`)
);
CREATE INDEX `idx_moon_scan_results_scanned_at` ON `moon_scan_results` (`scanned_at`);
CREATE INDEX `idx_moon_scan_results_system_id` ON `moon_scan_results` (`system_id`);
