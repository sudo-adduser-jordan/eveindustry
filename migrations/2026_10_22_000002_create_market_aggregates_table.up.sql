CREATE TABLE `market_aggregates` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
  `region_id` INT UNSIGNED NOT NULL,
  `type_id` INT UNSIGNED NOT NULL,
  `sweep_id` CHAR(36) NOT NULL,
  `snapshot_at` TIMESTAMP NOT NULL,
  `best_bid` DOUBLE NULL,
  `best_ask` DOUBLE NULL,
  `sell_price_p5` DOUBLE NULL,
  `sell_volume` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `buy_volume` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `sell_orders` INT UNSIGNED NOT NULL DEFAULT 0,
  `buy_orders` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `market_aggregates_region_type_unique` (`region_id`,`type_id`),
  KEY `market_aggregates_region_snapshot_idx` (`region_id`,`snapshot_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
