ALTER TABLE `moon_scan_results` ADD COLUMN `occupied` TINYINT(1) NOT NULL DEFAULT 0 AFTER `scanned_at`;
ALTER TABLE `moon_scan_results` ADD COLUMN `occupying_corporation_id` BIGINT UNSIGNED NULL AFTER `occupied`;
ALTER TABLE `moon_scan_results` ADD COLUMN `occupying_corporation_name` VARCHAR(255) NULL AFTER `occupying_corporation_id`;
