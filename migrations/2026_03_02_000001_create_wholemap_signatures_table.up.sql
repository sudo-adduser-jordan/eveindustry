CREATE TABLE `wholemap_signatures` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `signature_id` VARCHAR(7) NULL,
  `system_id` INT UNSIGNED NOT NULL,
  `system_name` VARCHAR(255) NULL,
  `type` ENUM('unknown','combat','data','relic','ore','gas','wormhole','ghost') NOT NULL DEFAULT 'unknown',
  `name` VARCHAR(100) NULL,
  `bookmark` VARCHAR(100) NULL,
  `description` TEXT NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  KEY (`user_id`,`system_id`),
  KEY (`system_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
