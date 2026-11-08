CREATE TABLE `inv_groups` (
  `group_id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `category_id` INT UNSIGNED NULL,
  `published` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`group_id`),
  KEY (`name`),
  KEY (`category_id`),
  KEY (`published`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
