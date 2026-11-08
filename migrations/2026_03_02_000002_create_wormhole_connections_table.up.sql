CREATE TABLE `wormhole_connections` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `signature_id` BIGINT UNSIGNED NOT NULL,
  `from_system_id` INT UNSIGNED NOT NULL,
  `from_system_name` VARCHAR(255) NULL,
  `to_system_id` INT UNSIGNED NULL,
  `to_system_name` VARCHAR(255) NULL,
  `wormhole_type` VARCHAR(10) NULL,
  `life` ENUM('stable','critical') NOT NULL DEFAULT 'stable',
  `mass` ENUM('stable','destab','critical') NOT NULL DEFAULT 'stable',
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  KEY (`user_id`,`from_system_id`),
  KEY (`from_system_id`),
  KEY (`to_system_id`),
  FOREIGN KEY (`signature_id`) REFERENCES `wholemap_signatures` (`None`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
