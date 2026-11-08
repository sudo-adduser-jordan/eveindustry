ALTER TABLE `moon_scan_results` DROP INDEX `moon_scan_results_system_id_moon_name_unique`;
ALTER TABLE `moon_scan_results` DROP FOREIGN KEY `moon_scan_results_system_id_foreign`;
ALTER TABLE `moon_scan_results` DROP COLUMN `system_id`;
ALTER TABLE `moon_scan_results` ADD COLUMN `system_name` VARCHAR(255) NULL AFTER `planet_name`;
ALTER TABLE `moon_scan_results` ADD COLUMN `region_id` BIGINT UNSIGNED NOT NULL DEFAULT 10000002 AFTER `total_value`;
ALTER TABLE `moon_scan_results` ADD COLUMN `pricing_mode` VARCHAR(16) NOT NULL DEFAULT 'raw' AFTER `region_id`;
ALTER TABLE `moon_scan_results` ADD COLUMN `corporation_id` BIGINT UNSIGNED NULL AFTER `region_id`;
ALTER TABLE `moon_scan_results` ADD KEY (`system_name`,`scanned_at`);
