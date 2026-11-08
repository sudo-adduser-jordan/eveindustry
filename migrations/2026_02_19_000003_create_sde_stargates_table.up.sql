CREATE TABLE `sde_stargates` (
  `stargate_id` INT UNSIGNED NOT NULL,
  `solar_system_id` INT UNSIGNED NULL,
  `destination_stargate_id` INT UNSIGNED NULL,
  `type_id` INT UNSIGNED NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`stargate_id`),
  KEY (`solar_system_id`),
  KEY (`destination_stargate_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
