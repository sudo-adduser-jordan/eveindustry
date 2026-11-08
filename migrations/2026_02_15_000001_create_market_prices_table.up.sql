CREATE TABLE `market_prices` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
  `type_id` INT NOT NULL,
  `region_id` INT NOT NULL DEFAULT 10000002,
  `average_price` DECIMAL(20,2) NULL,
  `adjusted_price` DECIMAL(20,2) NULL,
  `sell_min` DECIMAL(20,2) NULL,
  `buy_max` DECIMAL(20,2) NULL,
  `sell_volume` BIGINT NULL,
  `buy_volume` BIGINT NULL,
  `daily_volume` BIGINT NULL,
  `fetched_at` TIMESTAMP NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY (`type_id`,`region_id`),
  KEY (`type_id`),
  KEY (`region_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
