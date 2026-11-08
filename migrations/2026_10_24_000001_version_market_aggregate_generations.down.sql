DELETE a FROM `market_aggregates` a
JOIN (
  SELECT `id`, ROW_NUMBER() OVER (PARTITION BY `region_id`, `type_id` ORDER BY `snapshot_at` DESC, `id` DESC) AS `rn`
  FROM `market_aggregates`
) t ON t.`id` = a.`id`
WHERE t.`rn` > 1;
ALTER TABLE `market_aggregates` DROP INDEX `market_aggregates_region_sweep_idx`;
DROP INDEX `market_aggregates_region_sweep_type_unique` ON `market_aggregates`;
ALTER TABLE `market_aggregates` ADD UNIQUE KEY `market_aggregates_region_type_unique` (`region_id`,`type_id`);
DROP TABLE IF EXISTS `market_region_states`;
ALTER TABLE `market_order_sweeps` DROP COLUMN `generation`;
