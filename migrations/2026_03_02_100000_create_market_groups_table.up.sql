CREATE TABLE `market_groups` (
  `market_group_id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `description` TEXT NULL,
  `parent_group_id` INT UNSIGNED NULL,
  `icon_id` INT UNSIGNED NULL,
  `has_types` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`market_group_id`),
  KEY (`parent_group_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
