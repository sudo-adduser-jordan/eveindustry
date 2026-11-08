CREATE TABLE `build_programs` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `t2_type_id` INT NOT NULL,
  `t2_type_name` VARCHAR(255) NOT NULL,
  `steps` JSON NOT NULL,
  `settings` JSON NOT NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
