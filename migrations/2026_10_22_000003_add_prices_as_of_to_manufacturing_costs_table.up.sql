ALTER TABLE `manufacturing_costs` ADD COLUMN `prices_as_of` TIMESTAMP NULL AFTER `sell_score_total_days`;
ALTER TABLE `manufacturing_costs` ADD KEY `mfg_cost_region_updated_idx` (`region_id`,`updated_at`);
