DROP INDEX `market_aggregates_region_type_unique` ON `market_aggregates`;
ALTER TABLE `market_aggregates` ADD UNIQUE KEY `market_aggregates_region_sweep_type_unique` (`region_id`,`sweep_id`,`type_id`);
ALTER TABLE `market_aggregates` ADD KEY `market_aggregates_region_sweep_idx` (`region_id`,`sweep_id`);
ALTER TABLE `market_order_sweeps` ADD COLUMN `generation` BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER `region_id`;
CREATE TABLE `market_region_states` (
  `region_id` INT UNSIGNED NOT NULL,
  `latest_generation` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `latest_sweep_id` CHAR(36) NULL,
  `latest_started_at` TIMESTAMP NULL,
  `active_generation` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `active_sweep_id` CHAR(36) NULL,
  `active_snapshot_at` TIMESTAMP NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`region_id`),
  KEY `market_region_states_active_sweep_idx` (`active_sweep_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Data migration: number sweeps per region in started_at order, then seed
-- the region state pointer table (latest sweep + latest completed sweep).
UPDATE `market_order_sweeps` s
JOIN (
  SELECT `id`, ROW_NUMBER() OVER (PARTITION BY `region_id` ORDER BY `started_at`, `id`) AS `g`
  FROM `market_order_sweeps`
) t ON t.`id` = s.`id`
SET s.`generation` = t.`g`;
INSERT INTO `market_region_states` (`region_id`, `latest_generation`, `latest_sweep_id`, `latest_started_at`, `active_generation`, `active_sweep_id`, `active_snapshot_at`, `created_at`, `updated_at`)
SELECT r.`region_id`, r.`generation`, r.`sweep_id`, r.`started_at`,
  COALESCE(c.`generation`, 0), c.`sweep_id`, c.`started_at`, NOW(), NOW()
FROM (
  SELECT *, ROW_NUMBER() OVER (PARTITION BY `region_id` ORDER BY `generation` DESC) AS `rn`
  FROM `market_order_sweeps`
) r
LEFT JOIN (
  SELECT *, ROW_NUMBER() OVER (PARTITION BY `region_id` ORDER BY `generation` DESC) AS `rn`
  FROM `market_order_sweeps`
  WHERE `status` = 'completed'
) c ON c.`region_id` = r.`region_id` AND c.`rn` = 1
WHERE r.`rn` = 1;
