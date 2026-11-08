ALTER TABLE `station_configs` ADD COLUMN `activity_type` VARCHAR(255) NOT NULL DEFAULT 'manufacturing' AFTER `user_id`;
