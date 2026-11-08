CREATE TABLE `structure_character_access` (
  `structure_id` BIGINT UNSIGNED NOT NULL,
  `character_id` BIGINT UNSIGNED NOT NULL,
  `access_denied_at` TIMESTAMP NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`structure_id`,`character_id`),
  KEY (`character_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
