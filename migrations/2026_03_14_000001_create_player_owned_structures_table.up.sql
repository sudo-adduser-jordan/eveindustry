CREATE TABLE `player_owned_structures` (
  `structure_id` BIGINT UNSIGNED NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `owner_id` BIGINT UNSIGNED NULL,
  `solar_system_id` INT UNSIGNED NULL,
  `type_id` INT UNSIGNED NULL,
  `position_x` DOUBLE NULL,
  `position_y` DOUBLE NULL,
  `position_z` DOUBLE NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`structure_id`),
  KEY (`owner_id`),
  KEY (`solar_system_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
