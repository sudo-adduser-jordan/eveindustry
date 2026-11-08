CREATE TABLE `sde_stations` (
  `station_id` BIGINT UNSIGNED NOT NULL,
  `station_name` VARCHAR(255) NOT NULL,
  `solar_system_id` INT UNSIGNED NULL,
  `constellation_id` INT UNSIGNED NULL,
  `region_id` INT UNSIGNED NULL,
  `station_type_id` INT UNSIGNED NULL,
  `corporation_id` INT UNSIGNED NULL,
  `reprocessing_efficiency` FLOAT NULL,
  `security` FLOAT NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`station_id`),
  KEY (`station_name`),
  KEY (`solar_system_id`),
  KEY (`constellation_id`),
  KEY (`region_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
