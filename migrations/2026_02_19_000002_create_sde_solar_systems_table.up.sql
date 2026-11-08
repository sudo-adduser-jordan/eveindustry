CREATE TABLE `sde_solar_systems` (
  `solar_system_id` INT UNSIGNED NOT NULL,
  `solar_system_name` VARCHAR(255) NOT NULL,
  `constellation_id` INT UNSIGNED NULL,
  `region_id` INT UNSIGNED NULL,
  `security` FLOAT NULL,
  `security_class` VARCHAR(8) NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`solar_system_id`),
  KEY (`solar_system_name`),
  KEY (`constellation_id`),
  KEY (`region_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
