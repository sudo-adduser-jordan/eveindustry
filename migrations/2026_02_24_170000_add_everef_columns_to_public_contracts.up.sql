ALTER TABLE `public_contracts` ADD COLUMN `station_id` BIGINT UNSIGNED NULL AFTER `end_location_id`;
ALTER TABLE `public_contracts` ADD COLUMN `system_id` INT UNSIGNED NULL AFTER `station_id`;
ALTER TABLE `public_contracts` ADD COLUMN `constellation_id` INT UNSIGNED NULL AFTER `system_id`;
ALTER TABLE `public_contracts` ADD COLUMN `http_last_modified` TIMESTAMP NULL AFTER `volume`;
ALTER TABLE `public_contract_items` ADD COLUMN `http_last_modified` TIMESTAMP NULL AFTER `item_id`;
