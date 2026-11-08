ALTER TABLE `wholemap_signatures` ADD COLUMN `locked` TINYINT(1) NOT NULL DEFAULT 0 AFTER `description`;
ALTER TABLE `wholemap_signatures` ADD COLUMN `created_by` VARCHAR(100) NULL AFTER `locked`;
ALTER TABLE `wholemap_signatures` ADD COLUMN `updated_by` VARCHAR(100) NULL AFTER `created_by`;
ALTER TABLE `wormhole_connections` ADD COLUMN `size` ENUM('small','medium','large','xlarge') NOT NULL DEFAULT 'large' AFTER `wormhole_type`;
CREATE TABLE `wholemap_system_notes` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `system_id` INT UNSIGNED NOT NULL,
  `system_name` VARCHAR(255) NULL,
  `status` ENUM('unknown','friendly','occupied','hostile') NOT NULL DEFAULT 'unknown',
  `comment` VARCHAR(255) NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY (`user_id`,`system_id`),
  KEY (`system_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `wormhole_jump_logs` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
  `connection_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `character_id` INT UNSIGNED NULL,
  `character_name` VARCHAR(100) NULL,
  `ship_type_id` INT UNSIGNED NULL,
  `ship_type_name` VARCHAR(100) NULL,
  `ship_mass` FLOAT NULL,
  `jumped_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  KEY (`connection_id`,`jumped_at`),
  KEY (`user_id`),
  FOREIGN KEY (`connection_id`) REFERENCES `wormhole_connections` (`None`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
