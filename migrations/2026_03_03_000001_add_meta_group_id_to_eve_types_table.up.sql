ALTER TABLE `eve_types` ADD COLUMN `meta_group_id` SMALLINT UNSIGNED NULL AFTER `graphic_id`;
ALTER TABLE `eve_types` ADD KEY (`meta_group_id`);
