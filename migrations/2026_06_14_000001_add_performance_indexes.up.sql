ALTER TABLE `market_orders` ADD KEY `market_orders_region_buy_type_idx` (`region_id`,`is_buy_order`,`type_id`);
ALTER TABLE `market_history` ADD KEY `market_history_region_date_idx` (`region_id`,`date`);
ALTER TABLE `manufacturing_costs` ADD KEY `mfg_cost_region_me_product_idx` (`region_id`,`me_level`,`product_type_id`);
ALTER TABLE `characters` ADD KEY `characters_user_esi_active_idx` (`user_id`,`is_esi_active`);
