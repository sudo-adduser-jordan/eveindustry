CREATE TABLE `station_configs` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `structure_type` VARCHAR(255) NOT NULL DEFAULT 'none',
  `rig_me` VARCHAR(255) NOT NULL DEFAULT 'none',
  `rig_te` VARCHAR(255) NOT NULL DEFAULT 'none',
  `system_security` VARCHAR(255) NOT NULL DEFAULT 'highsec',
  `is_default` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  KEY (`user_id`,`is_default`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
