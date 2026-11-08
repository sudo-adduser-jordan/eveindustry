CREATE TABLE `blueprint_activities` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
  `blueprint_type_id` INT UNSIGNED NOT NULL,
  `activity_id` TINYINT UNSIGNED NOT NULL,
  `time` INT UNSIGNED NOT NULL DEFAULT 0,
  `materials` JSON NULL,
  `products` JSON NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY (`blueprint_type_id`,`activity_id`),
  KEY (`blueprint_type_id`),
  KEY (`activity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
