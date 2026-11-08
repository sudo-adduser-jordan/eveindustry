ALTER TABLE `characters` ADD COLUMN `is_esi_active` TINYINT(1) NOT NULL DEFAULT 1 AFTER `scopes`;
