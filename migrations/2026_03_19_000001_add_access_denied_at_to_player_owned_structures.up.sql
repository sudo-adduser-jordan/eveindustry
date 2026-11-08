ALTER TABLE `player_owned_structures` MODIFY COLUMN `name` VARCHAR(255) NULL;
ALTER TABLE `player_owned_structures` ADD COLUMN `access_denied_at` TIMESTAMP NULL AFTER `structure_id`;
