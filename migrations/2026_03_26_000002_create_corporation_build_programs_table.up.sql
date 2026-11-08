CREATE TABLE `corporation_build_programs` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
  `corporation_id` BIGINT UNSIGNED NOT NULL,
  `created_by_user_id` BIGINT UNSIGNED NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `program_type` VARCHAR(255) NOT NULL DEFAULT 'manufacturing' COMMENT 'manufacturing or reaction',
  `t2_type_id` BIGINT UNSIGNED NULL,
  `t2_type_name` VARCHAR(255) NULL,
  `steps` JSON NOT NULL,
  `settings` JSON NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  KEY (`corporation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
