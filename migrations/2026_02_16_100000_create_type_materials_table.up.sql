CREATE TABLE `type_materials` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
  `type_id` INT UNSIGNED NOT NULL,
  `material_type_id` INT UNSIGNED NOT NULL,
  `quantity` INT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY (`type_id`,`material_type_id`),
  KEY (`material_type_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
