CREATE TABLE `market_history` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
  `region_id` INT UNSIGNED NOT NULL,
  `type_id` INT UNSIGNED NOT NULL,
  `date` DATE NOT NULL,
  `average` DOUBLE NOT NULL,
  `highest` DOUBLE NOT NULL,
  `lowest` DOUBLE NOT NULL,
  `volume` BIGINT UNSIGNED NOT NULL,
  `order_count` BIGINT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY (`region_id`,`type_id`,`date`),
  KEY (`region_id`,`type_id`),
  KEY (`type_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
