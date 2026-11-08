ALTER TABLE `station_configs` ADD COLUMN `solar_system_id` INT UNSIGNED NULL AFTER `system_security`;
ALTER TABLE `station_configs` ADD COLUMN `tax_rate` DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER `solar_system_id`;
