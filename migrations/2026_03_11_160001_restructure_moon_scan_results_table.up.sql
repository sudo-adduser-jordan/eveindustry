-- Data migration: fold system-level columns into moon_scan_systems, keeping
-- the latest scan per (system, corporation) like updateOrInsert did.
INSERT INTO `moon_scan_systems` (`system_name`, `corporation_id`, `region_id`, `pricing_mode`, `last_scanned_at`, `created_at`, `updated_at`)
SELECT `system_name`, `corporation_id`, `region_id`, `pricing_mode`, `scanned_at`, `scanned_at`, `scanned_at`
FROM (
  SELECT *, ROW_NUMBER() OVER (PARTITION BY `system_name`, `corporation_id` ORDER BY `scanned_at` DESC) AS `rn`
  FROM `moon_scan_results`
  WHERE `system_name` IS NOT NULL AND `corporation_id` IS NOT NULL
) t WHERE `rn` = 1
ON DUPLICATE KEY UPDATE
  `region_id` = VALUES(`region_id`),
  `pricing_mode` = VALUES(`pricing_mode`),
  `last_scanned_at` = VALUES(`last_scanned_at`),
  `updated_at` = VALUES(`updated_at`);
INSERT INTO `moon_scan_systems` (`system_name`, `corporation_id`, `region_id`, `pricing_mode`, `last_scanned_at`, `created_at`, `updated_at`)
SELECT `system_name`, NULL, `region_id`, `pricing_mode`, `scanned_at`, `scanned_at`, `scanned_at`
FROM (
  SELECT *, ROW_NUMBER() OVER (PARTITION BY `system_name` ORDER BY `scanned_at` DESC) AS `rn`
  FROM `moon_scan_results`
  WHERE `system_name` IS NOT NULL AND `corporation_id` IS NULL
) t WHERE `rn` = 1;
ALTER TABLE `moon_scan_results` ADD COLUMN `system_id` BIGINT UNSIGNED NULL AFTER `id`;
ALTER TABLE `moon_scan_results` ADD CONSTRAINT `moon_scan_results_system_id_foreign` FOREIGN KEY (`system_id`) REFERENCES `moon_scan_systems` (`id`) ON DELETE CASCADE;
UPDATE `moon_scan_results` r
JOIN `moon_scan_systems` s ON s.`system_name` = r.`system_name`
  AND (s.`corporation_id` = r.`corporation_id` OR (s.`corporation_id` IS NULL AND r.`corporation_id` IS NULL))
SET r.`system_id` = s.`id`;
DROP INDEX `moon_scan_results_system_name_scanned_at_index` ON `moon_scan_results`;
DROP INDEX `moon_scan_results_corporation_id_index` ON `moon_scan_results`;
ALTER TABLE `moon_scan_results` DROP COLUMN `system_name`;
ALTER TABLE `moon_scan_results` DROP COLUMN `region_id`;
ALTER TABLE `moon_scan_results` DROP COLUMN `pricing_mode`;
ALTER TABLE `moon_scan_results` DROP COLUMN `corporation_id`;
ALTER TABLE `moon_scan_results` ADD UNIQUE KEY `moon_scan_results_system_id_moon_name_unique` (`system_id`,`moon_name`);
