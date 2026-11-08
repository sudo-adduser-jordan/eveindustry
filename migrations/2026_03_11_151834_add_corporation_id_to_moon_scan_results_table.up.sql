ALTER TABLE `moon_scan_results` ADD COLUMN `corporation_id` BIGINT UNSIGNED NULL AFTER `region_id`;
ALTER TABLE `moon_scan_results` ADD KEY `moon_scan_results_corporation_id_index` (`corporation_id`);
