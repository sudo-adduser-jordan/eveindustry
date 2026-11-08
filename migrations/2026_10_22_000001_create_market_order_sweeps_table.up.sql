CREATE TABLE `market_order_sweeps` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
  `sweep_id` CHAR(36) NOT NULL,
  `region_id` INT UNSIGNED NOT NULL,
  `status` VARCHAR(16) NOT NULL DEFAULT 'running',
  `started_at` TIMESTAMP NOT NULL,
  `finished_at` TIMESTAMP NULL,
  `duration_seconds` INT UNSIGNED NULL,
  `pages_total` INT UNSIGNED NOT NULL DEFAULT 0,
  `pages_fetched` INT UNSIGNED NOT NULL DEFAULT 0,
  `pages_not_modified` INT UNSIGNED NOT NULL DEFAULT 0,
  `orders_seen` INT UNSIGNED NOT NULL DEFAULT 0,
  `orders_deleted` INT UNSIGNED NOT NULL DEFAULT 0,
  `types_aggregated` INT UNSIGNED NOT NULL DEFAULT 0,
  `error` VARCHAR(500) NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY (`sweep_id`),
  KEY `market_order_sweeps_region_status_started_idx` (`region_id`,`status`,`started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE `market_orders` ADD KEY `market_orders_region_updated_idx` (`region_id`,`updated_at`);
