ALTER TABLE `manufacturing_costs` ADD KEY `mfg_cost_region_me_profit_idx` (`region_id`,`me_level`,`base_profit`);
ALTER TABLE `market_orders` ADD KEY `market_orders_region_type_buy_price_idx` (`region_id`,`type_id`,`is_buy_order`,`price`);
