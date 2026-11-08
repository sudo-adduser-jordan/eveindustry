ALTER TABLE `characters` ADD COLUMN `is_director_ceo` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_esi_active`;
ALTER TABLE `characters` ADD COLUMN `roles` JSON NULL AFTER `is_director_ceo`;
