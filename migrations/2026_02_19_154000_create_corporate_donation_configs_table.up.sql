CREATE TABLE `corporate_donation_configs` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
  `corporation_id` BIGINT UNSIGNED NOT NULL,
  `corporation_name` VARCHAR(255) NULL,
  `character_id` BIGINT UNSIGNED NOT NULL COMMENT 'Character providing wallet access',
  `enabled_by_user_id` BIGINT UNSIGNED NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY (`corporation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
