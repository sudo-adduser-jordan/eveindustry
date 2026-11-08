ALTER TABLE `characters` ADD COLUMN `cached_location` VARCHAR(255) NULL AFTER `alliance_name`;
ALTER TABLE `characters` ADD COLUMN `cached_training` VARCHAR(255) NULL AFTER `cached_location`;
