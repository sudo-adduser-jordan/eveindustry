ALTER TABLE `player_owned_structures` MODIFY COLUMN `name` VARCHAR(255) NULL;
ALTER TABLE `player_owned_structures` DROP COLUMN `access_denied_at`;
