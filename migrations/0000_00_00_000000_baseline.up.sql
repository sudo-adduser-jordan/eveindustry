CREATE TABLE `users` (
  `id` INTEGER NOT NULL,
  `name` TEXT NOT NULL,
  `email` TEXT NOT NULL,
  `email_verified_at` TIMESTAMP NULL,
  `password` TEXT NULL,
  `is_admin` INTEGER NOT NULL DEFAULT 0,
  `is_super_admin` INTEGER NOT NULL DEFAULT 0,
  `is_active` INTEGER NOT NULL DEFAULT 1,
  `is_stock_manager_enabled` INTEGER NOT NULL DEFAULT 0,
  `is_demo` INTEGER NOT NULL DEFAULT 0,
  `last_login_at` TIMESTAMP NULL,
  `remember_token` TEXT NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE (`email`)
);
CREATE INDEX `idx_users_is_demo` ON `users` (`is_demo`);
CREATE TABLE `password_reset_tokens` (
  `email` TEXT NOT NULL,
  `token` TEXT NOT NULL,
  `created_at` TIMESTAMP NULL,
  PRIMARY KEY (`email`)
);
CREATE TABLE `sessions` (
  `id` TEXT NOT NULL,
  `user_id` INTEGER NULL,
  `ip_address` TEXT NULL,
  `user_agent` TEXT NULL,
  `payload` TEXT NOT NULL,
  `last_activity` INTEGER NOT NULL,
  PRIMARY KEY (`id`)
);
CREATE INDEX `idx_sessions_user_id` ON `sessions` (`user_id`);
CREATE INDEX `idx_sessions_last_activity` ON `sessions` (`last_activity`);
CREATE TABLE `characters` (
  `character_id` INTEGER NOT NULL,
  `user_id` INTEGER NOT NULL,
  `name` TEXT NOT NULL,
  `corporation_id` INTEGER NOT NULL,
  `corporation_name` TEXT NULL,
  `alliance_id` INTEGER NULL,
  `alliance_name` TEXT NULL,
  `cached_location` TEXT NULL,
  `cached_training` TEXT NULL,
  `is_main` INTEGER NOT NULL DEFAULT 0,
  `access_token` TEXT NULL,
  `refresh_token` TEXT NULL,
  `token_expires_at` TIMESTAMP NULL,
  `scopes` TEXT NULL,
  `is_esi_active` INTEGER NOT NULL DEFAULT 1,
  `is_director_ceo` INTEGER NOT NULL DEFAULT 0,
  `roles` TEXT NULL,
  `affiliation_updated_at` TIMESTAMP NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`character_id`)
);
CREATE INDEX `idx_characters_user_id` ON `characters` (`user_id`);
CREATE INDEX `idx_characters_corporation_id` ON `characters` (`corporation_id`);
CREATE INDEX `idx_characters_alliance_id` ON `characters` (`alliance_id`);
CREATE INDEX `characters_user_esi_active_idx` ON `characters` (`user_id`,`is_esi_active`);
CREATE TABLE `cache` (
  `key` TEXT NOT NULL,
  `value` TEXT NOT NULL,
  `expiration` INTEGER NOT NULL,
  PRIMARY KEY (`key`)
);
CREATE TABLE `cache_locks` (
  `key` TEXT NOT NULL,
  `owner` TEXT NOT NULL,
  `expiration` INTEGER NOT NULL,
  PRIMARY KEY (`key`)
);
CREATE TABLE `jobs` (
  `id` INTEGER NOT NULL,
  `queue` TEXT NOT NULL,
  `payload` TEXT NOT NULL,
  `attempts` INTEGER NOT NULL,
  `reserved_at` INTEGER NULL,
  `available_at` INTEGER NOT NULL,
  `created_at` INTEGER NOT NULL,
  PRIMARY KEY (`id`)
);
CREATE INDEX `idx_jobs_queue` ON `jobs` (`queue`);
CREATE TABLE `failed_jobs` (
  `id` INTEGER NOT NULL,
  `uuid` TEXT NOT NULL,
  `connection` TEXT NOT NULL,
  `queue` TEXT NOT NULL,
  `payload` TEXT NOT NULL,
  `exception` TEXT NOT NULL,
  `failed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE (`uuid`)
);
CREATE TABLE `market_prices` (
  `id` INTEGER NOT NULL,
  `type_id` INTEGER NOT NULL,
  `region_id` INTEGER NOT NULL DEFAULT 10000002,
  `average_price` REAL NULL,
  `adjusted_price` REAL NULL,
  `sell_min` REAL NULL,
  `buy_max` REAL NULL,
  `sell_volume` INTEGER NULL,
  `buy_volume` INTEGER NULL,
  `daily_volume` INTEGER NULL,
  `fetched_at` TIMESTAMP NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE (`type_id`,`region_id`)
);
CREATE INDEX `idx_market_prices_type_id` ON `market_prices` (`type_id`);
CREATE INDEX `idx_market_prices_region_id` ON `market_prices` (`region_id`);
CREATE TABLE `eve_types` (
  `type_id` INTEGER NOT NULL,
  `name` TEXT NOT NULL,
  `description` TEXT NULL,
  `group_id` INTEGER NULL,
  `market_group_id` INTEGER NULL,
  `volume` REAL NULL,
  `packaged_volume` REAL NULL,
  `mass` REAL NULL,
  `capacity` REAL NULL,
  `portion_size` INTEGER NULL,
  `icon_id` INTEGER NULL,
  `graphic_id` INTEGER NULL,
  `meta_group_id` INTEGER NULL,
  `published` INTEGER NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`type_id`)
);
CREATE INDEX `idx_eve_types_name` ON `eve_types` (`name`);
CREATE INDEX `idx_eve_types_group_id` ON `eve_types` (`group_id`);
CREATE INDEX `idx_eve_types_market_group_id` ON `eve_types` (`market_group_id`);
CREATE INDEX `idx_eve_types_published` ON `eve_types` (`published`);
CREATE INDEX `idx_eve_types_meta_group_id` ON `eve_types` (`meta_group_id`);
CREATE TABLE `blueprint_activities` (
  `id` INTEGER NOT NULL,
  `blueprint_type_id` INTEGER NOT NULL,
  `activity_id` INTEGER NOT NULL,
  `time` INTEGER NOT NULL DEFAULT 0,
  `materials` TEXT NULL,
  `products` TEXT NULL,
  `skills` TEXT NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE (`blueprint_type_id`,`activity_id`)
);
CREATE INDEX `idx_blueprint_activities_blueprint_type_id` ON `blueprint_activities` (`blueprint_type_id`);
CREATE INDEX `idx_blueprint_activities_activity_id` ON `blueprint_activities` (`activity_id`);
CREATE TABLE `type_materials` (
  `id` INTEGER NOT NULL,
  `type_id` INTEGER NOT NULL,
  `material_type_id` INTEGER NOT NULL,
  `quantity` INTEGER NOT NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE (`type_id`,`material_type_id`)
);
CREATE INDEX `idx_type_materials_material_type_id` ON `type_materials` (`material_type_id`);
CREATE TABLE `station_configs` (
  `id` INTEGER NOT NULL,
  `user_id` INTEGER NOT NULL,
  `activity_type` TEXT NOT NULL DEFAULT 'manufacturing',
  `name` TEXT NOT NULL,
  `structure_type` TEXT NOT NULL DEFAULT 'none',
  `rig_me` TEXT NOT NULL DEFAULT 'none',
  `rig_te` TEXT NOT NULL DEFAULT 'none',
  `system_security` TEXT NOT NULL DEFAULT 'highsec',
  `solar_system_id` INTEGER NULL,
  `tax_rate` REAL NOT NULL DEFAULT 0,
  `is_default` INTEGER NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`)
);
CREATE INDEX `idx_station_configs_user_id_is_default` ON `station_configs` (`user_id`,`is_default`);
CREATE TABLE `sponsors` (
  `id` INTEGER NOT NULL,
  `name` TEXT NOT NULL,
  `url` TEXT NOT NULL,
  `image_path` TEXT NOT NULL,
  `is_active` INTEGER NOT NULL DEFAULT 1,
  `sort_order` INTEGER NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`)
);
CREATE TABLE `reaction_plans` (
  `id` INTEGER NOT NULL,
  `user_id` INTEGER NOT NULL,
  `name` TEXT NOT NULL,
  `entries` TEXT NOT NULL,
  `default_runs_per_slot` INTEGER NOT NULL DEFAULT 240,
  `rig_bonus` REAL NOT NULL DEFAULT 0,
  `structure_type` TEXT NOT NULL DEFAULT 'athanor',
  `rig_tier` TEXT NOT NULL DEFAULT 'none',
  `security_space` TEXT NOT NULL DEFAULT 'lowsec',
  `facility_tax` REAL NOT NULL DEFAULT 0,
  `station_id` INTEGER NULL,
  `solar_system_id` INTEGER NULL,
  `price_region_id` INTEGER NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`)
);
CREATE INDEX `idx_reaction_plans_user_id` ON `reaction_plans` (`user_id`);
CREATE TABLE `sde_stations` (
  `station_id` INTEGER NOT NULL,
  `station_name` TEXT NOT NULL,
  `solar_system_id` INTEGER NULL,
  `constellation_id` INTEGER NULL,
  `region_id` INTEGER NULL,
  `station_type_id` INTEGER NULL,
  `corporation_id` INTEGER NULL,
  `reprocessing_efficiency` REAL NULL,
  `security` REAL NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`station_id`)
);
CREATE INDEX `idx_sde_stations_station_name` ON `sde_stations` (`station_name`);
CREATE INDEX `idx_sde_stations_solar_system_id` ON `sde_stations` (`solar_system_id`);
CREATE INDEX `idx_sde_stations_constellation_id` ON `sde_stations` (`constellation_id`);
CREATE INDEX `idx_sde_stations_region_id` ON `sde_stations` (`region_id`);
CREATE TABLE `sde_solar_systems` (
  `solar_system_id` INTEGER NOT NULL,
  `solar_system_name` TEXT NOT NULL,
  `constellation_id` INTEGER NULL,
  `region_id` INTEGER NULL,
  `security` REAL NULL,
  `security_class` TEXT NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`solar_system_id`)
);
CREATE INDEX `idx_sde_solar_systems_solar_system_name` ON `sde_solar_systems` (`solar_system_name`);
CREATE INDEX `idx_sde_solar_systems_constellation_id` ON `sde_solar_systems` (`constellation_id`);
CREATE INDEX `idx_sde_solar_systems_region_id` ON `sde_solar_systems` (`region_id`);
CREATE TABLE `sde_stargates` (
  `stargate_id` INTEGER NOT NULL,
  `solar_system_id` INTEGER NULL,
  `destination_stargate_id` INTEGER NULL,
  `type_id` INTEGER NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`stargate_id`)
);
CREATE INDEX `idx_sde_stargates_solar_system_id` ON `sde_stargates` (`solar_system_id`);
CREATE INDEX `idx_sde_stargates_destination_stargate_id` ON `sde_stargates` (`destination_stargate_id`);
CREATE TABLE `job_batches` (
  `id` TEXT NOT NULL,
  `name` TEXT NOT NULL,
  `total_jobs` INTEGER NOT NULL,
  `pending_jobs` INTEGER NOT NULL,
  `failed_jobs` INTEGER NOT NULL,
  `failed_job_ids` TEXT NOT NULL,
  `options` TEXT NULL,
  `cancelled_at` INTEGER NULL,
  `created_at` INTEGER NOT NULL,
  `finished_at` INTEGER NULL,
  PRIMARY KEY (`id`)
);
CREATE TABLE `market_orders` (
  `order_id` INTEGER NOT NULL,
  `region_id` INTEGER NOT NULL,
  `type_id` INTEGER NOT NULL,
  `is_buy_order` INTEGER NOT NULL DEFAULT 0,
  `price` REAL NOT NULL,
  `volume_remain` INTEGER NOT NULL,
  `volume_total` INTEGER NOT NULL,
  `min_volume` INTEGER NOT NULL DEFAULT 1,
  `location_id` INTEGER NULL,
  `duration` INTEGER NULL,
  `range` TEXT NULL,
  `issued` DATETIME NULL,
  `batch_id` TEXT NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`order_id`)
);
CREATE INDEX `idx_market_orders_region_id` ON `market_orders` (`region_id`);
CREATE INDEX `idx_market_orders_type_id` ON `market_orders` (`type_id`);
CREATE INDEX `idx_market_orders_batch_id` ON `market_orders` (`batch_id`);
CREATE INDEX `idx_market_orders_region_id_type_id` ON `market_orders` (`region_id`,`type_id`);
CREATE INDEX `market_orders_region_buy_type_idx` ON `market_orders` (`region_id`,`is_buy_order`,`type_id`);
CREATE INDEX `market_orders_region_type_buy_price_idx` ON `market_orders` (`region_id`,`type_id`,`is_buy_order`,`price`);
CREATE INDEX `market_orders_region_updated_idx` ON `market_orders` (`region_id`,`updated_at`);
CREATE TABLE `corporate_donation_configs` (
  `id` INTEGER NOT NULL,
  `corporation_id` INTEGER NOT NULL,
  `corporation_name` TEXT NULL,
  `character_id` INTEGER NOT NULL,
  `enabled_by_user_id` INTEGER NOT NULL,
  `is_active` INTEGER NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE (`corporation_id`)
);
CREATE TABLE `market_history` (
  `id` INTEGER NOT NULL,
  `region_id` INTEGER NOT NULL,
  `type_id` INTEGER NOT NULL,
  `date` DATE NOT NULL,
  `average` REAL NOT NULL,
  `highest` REAL NOT NULL,
  `lowest` REAL NOT NULL,
  `volume` INTEGER NOT NULL,
  `order_count` INTEGER NOT NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE (`region_id`,`type_id`,`date`)
);
CREATE INDEX `idx_market_history_region_id_type_id` ON `market_history` (`region_id`,`type_id`);
CREATE INDEX `idx_market_history_type_id` ON `market_history` (`type_id`);
CREATE INDEX `market_history_region_date_idx` ON `market_history` (`region_id`,`date`);
CREATE TABLE `public_contracts` (
  `contract_id` INTEGER NOT NULL,
  `region_id` INTEGER NOT NULL,
  `type` TEXT NOT NULL,
  `title` TEXT NULL,
  `for_corporation` INTEGER NOT NULL DEFAULT 0,
  `issuer_corporation_id` INTEGER NULL,
  `issuer_id` INTEGER NULL,
  `date_issued` TIMESTAMP NULL,
  `date_expired` TIMESTAMP NULL,
  `days_to_complete` INTEGER NOT NULL DEFAULT 0,
  `start_location_id` INTEGER NULL,
  `end_location_id` INTEGER NULL,
  `station_id` INTEGER NULL,
  `system_id` INTEGER NULL,
  `constellation_id` INTEGER NULL,
  `price` REAL NOT NULL DEFAULT 0,
  `buyout` REAL NULL,
  `reward` REAL NULL,
  `collateral` REAL NULL,
  `volume` REAL NOT NULL DEFAULT 0,
  `http_last_modified` TIMESTAMP NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`contract_id`)
);
CREATE INDEX `idx_public_contracts_region_id` ON `public_contracts` (`region_id`);
CREATE INDEX `idx_public_contracts_type` ON `public_contracts` (`type`);
CREATE TABLE `public_contract_items` (
  `id` INTEGER NOT NULL,
  `contract_id` INTEGER NOT NULL,
  `type_id` INTEGER NOT NULL,
  `quantity` INTEGER NOT NULL DEFAULT 1,
  `is_included` INTEGER NOT NULL DEFAULT 1,
  `is_blueprint_copy` INTEGER NULL,
  `material_efficiency` INTEGER NULL,
  `time_efficiency` INTEGER NULL,
  `runs` INTEGER NULL,
  `record_id` INTEGER NULL,
  `item_id` INTEGER NULL,
  `http_last_modified` TIMESTAMP NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`)
);
CREATE INDEX `idx_public_contract_items_contract_id` ON `public_contract_items` (`contract_id`);
CREATE INDEX `idx_public_contract_items_type_id` ON `public_contract_items` (`type_id`);
CREATE INDEX `idx_public_contract_items_contract_id_type_id` ON `public_contract_items` (`contract_id`,`type_id`);
CREATE TABLE `invention_plans` (
  `id` INTEGER NOT NULL,
  `user_id` INTEGER NOT NULL,
  `name` TEXT NOT NULL,
  `t2_type_id` INTEGER NOT NULL,
  `t2_type_name` TEXT NOT NULL,
  `decryptor_key` TEXT NOT NULL DEFAULT 'none',
  `t1_me` INTEGER NOT NULL DEFAULT 0,
  `t1_te` INTEGER NOT NULL DEFAULT 0,
  `encryption_skill` INTEGER NOT NULL DEFAULT 0,
  `science1_skill` INTEGER NOT NULL DEFAULT 0,
  `science2_skill` INTEGER NOT NULL DEFAULT 0,
  `invention_runs` INTEGER NOT NULL DEFAULT 1,
  `structure_type` TEXT NULL,
  `rig_me` TEXT NULL,
  `rig_te` TEXT NULL,
  `system_security` TEXT NULL,
  `facility_tax` REAL NOT NULL DEFAULT 0,
  `solar_system_id` INTEGER NULL,
  `station_id` INTEGER NULL,
  `price_region_id` INTEGER NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`)
);
CREATE TABLE `build_programs` (
  `id` INTEGER NOT NULL,
  `user_id` INTEGER NOT NULL,
  `name` TEXT NOT NULL,
  `program_type` TEXT NOT NULL DEFAULT 'manufacturing',
  `t2_type_id` INTEGER NULL,
  `t2_type_name` TEXT NULL,
  `steps` TEXT NOT NULL,
  `settings` TEXT NOT NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`)
);
CREATE INDEX `idx_build_programs_user_id` ON `build_programs` (`user_id`);
CREATE INDEX `build_programs_user_updated_idx` ON `build_programs` (`user_id`,`updated_at`);
CREATE TABLE `wholemap_signatures` (
  `id` INTEGER NOT NULL,
  `user_id` INTEGER NOT NULL,
  `signature_id` TEXT NULL,
  `system_id` INTEGER NOT NULL,
  `system_name` TEXT NULL,
  `type` TEXT NOT NULL DEFAULT 'unknown',
  `name` TEXT NULL,
  `bookmark` TEXT NULL,
  `description` TEXT NULL,
  `locked` INTEGER NOT NULL DEFAULT 0,
  `created_by` TEXT NULL,
  `updated_by` TEXT NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`)
);
CREATE INDEX `idx_wholemap_signatures_user_id_system_id` ON `wholemap_signatures` (`user_id`,`system_id`);
CREATE INDEX `idx_wholemap_signatures_system_id` ON `wholemap_signatures` (`system_id`);
CREATE TABLE `wormhole_connections` (
  `id` INTEGER NOT NULL,
  `user_id` INTEGER NOT NULL,
  `signature_id` INTEGER NOT NULL,
  `from_system_id` INTEGER NOT NULL,
  `from_system_name` TEXT NULL,
  `to_system_id` INTEGER NULL,
  `to_system_name` TEXT NULL,
  `wormhole_type` TEXT NULL,
  `size` TEXT NOT NULL DEFAULT 'large',
  `life` TEXT NOT NULL DEFAULT 'stable',
  `mass` TEXT NOT NULL DEFAULT 'stable',
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`)
);
CREATE INDEX `idx_wormhole_connections_user_id_from_system_id` ON `wormhole_connections` (`user_id`,`from_system_id`);
CREATE INDEX `idx_wormhole_connections_from_system_id` ON `wormhole_connections` (`from_system_id`);
CREATE INDEX `idx_wormhole_connections_to_system_id` ON `wormhole_connections` (`to_system_id`);
CREATE TABLE `wholemap_system_notes` (
  `id` INTEGER NOT NULL,
  `user_id` INTEGER NOT NULL,
  `system_id` INTEGER NOT NULL,
  `system_name` TEXT NULL,
  `status` TEXT NOT NULL DEFAULT 'unknown',
  `comment` TEXT NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE (`user_id`,`system_id`)
);
CREATE INDEX `idx_wholemap_system_notes_system_id` ON `wholemap_system_notes` (`system_id`);
CREATE TABLE `wormhole_jump_logs` (
  `id` INTEGER NOT NULL,
  `connection_id` INTEGER NOT NULL,
  `user_id` INTEGER NOT NULL,
  `character_id` INTEGER NULL,
  `character_name` TEXT NULL,
  `ship_type_id` INTEGER NULL,
  `ship_type_name` TEXT NULL,
  `ship_mass` REAL NULL,
  `jumped_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`)
);
CREATE INDEX `idx_wormhole_jump_logs_connection_id_jumped_at` ON `wormhole_jump_logs` (`connection_id`,`jumped_at`);
CREATE INDEX `idx_wormhole_jump_logs_user_id` ON `wormhole_jump_logs` (`user_id`);
CREATE TABLE `market_groups` (
  `market_group_id` INTEGER NOT NULL,
  `name` TEXT NOT NULL,
  `description` TEXT NULL,
  `parent_group_id` INTEGER NULL,
  `icon_id` INTEGER NULL,
  `has_types` INTEGER NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`market_group_id`)
);
CREATE INDEX `idx_market_groups_parent_group_id` ON `market_groups` (`parent_group_id`);
CREATE TABLE `manufacturing_costs` (
  `id` INTEGER NOT NULL,
  `product_type_id` INTEGER NOT NULL,
  `blueprint_type_id` INTEGER NOT NULL,
  `region_id` INTEGER NOT NULL,
  `me_level` INTEGER NOT NULL,
  `product_name` TEXT NOT NULL,
  `blueprint_name` TEXT NOT NULL,
  `portion_size` INTEGER NOT NULL DEFAULT 1,
  `input_cost` REAL NOT NULL,
  `output_value` REAL NOT NULL,
  `base_profit` REAL NOT NULL,
  `profit_margin` REAL NOT NULL,
  `sell_score` INTEGER NOT NULL DEFAULT 0,
  `sell_score_avg_daily_volume` REAL NOT NULL DEFAULT 0,
  `sell_score_avg_order_count` REAL NOT NULL DEFAULT 0,
  `sell_score_active_days` INTEGER NOT NULL DEFAULT 0,
  `sell_score_total_days` INTEGER NOT NULL DEFAULT 0,
  `prices_as_of` TIMESTAMP NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `mfg_cost_unique` UNIQUE (`product_type_id`,`region_id`,`me_level`)
);
CREATE INDEX `idx_manufacturing_costs_region_id` ON `manufacturing_costs` (`region_id`);
CREATE INDEX `idx_manufacturing_costs_me_level` ON `manufacturing_costs` (`me_level`);
CREATE INDEX `mfg_cost_region_me_product_idx` ON `manufacturing_costs` (`region_id`,`me_level`,`product_type_id`);
CREATE INDEX `mfg_cost_region_me_profit_idx` ON `manufacturing_costs` (`region_id`,`me_level`,`base_profit`);
CREATE INDEX `mfg_cost_region_updated_idx` ON `manufacturing_costs` (`region_id`,`updated_at`);
CREATE TABLE `inv_groups` (
  `group_id` INTEGER NOT NULL,
  `name` TEXT NOT NULL,
  `category_id` INTEGER NULL,
  `published` INTEGER NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`group_id`)
);
CREATE INDEX `idx_inv_groups_name` ON `inv_groups` (`name`);
CREATE INDEX `idx_inv_groups_category_id` ON `inv_groups` (`category_id`);
CREATE INDEX `idx_inv_groups_published` ON `inv_groups` (`published`);
CREATE TABLE `inv_categories` (
  `category_id` INTEGER NOT NULL,
  `name` TEXT NOT NULL,
  `published` INTEGER NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`category_id`)
);
CREATE INDEX `idx_inv_categories_name` ON `inv_categories` (`name`);
CREATE INDEX `idx_inv_categories_published` ON `inv_categories` (`published`);
CREATE TABLE `discord_bot_settings` (
  `id` INTEGER NOT NULL,
  `bot_token` TEXT NULL,
  `bot_name` TEXT NOT NULL DEFAULT 'EVE Industrialist Bot',
  `bot_avatar_url` TEXT NULL,
  `welcome_channel_id` TEXT NULL,
  `welcome_message` TEXT NULL,
  `is_enabled` INTEGER NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`)
);
CREATE TABLE `moon_scan_results` (
  `id` INTEGER NOT NULL,
  `system_id` INTEGER NULL,
  `moon_name` TEXT NOT NULL,
  `planet_name` TEXT NULL,
  `grade` TEXT NOT NULL DEFAULT 'D',
  `ores` TEXT NOT NULL,
  `total_value` INTEGER NOT NULL DEFAULT 0,
  `scanned_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `occupied` INTEGER NOT NULL DEFAULT 0,
  `occupying_corporation_id` INTEGER NULL,
  `occupying_corporation_name` TEXT NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `moon_scan_results_system_id_foreign` FOREIGN KEY (`system_id`) REFERENCES `moon_scan_systems` (`id`) ON DELETE CASCADE,
  CONSTRAINT `moon_scan_results_system_id_moon_name_unique` UNIQUE (`system_id`,`moon_name`)
);
CREATE INDEX `idx_moon_scan_results_scanned_at` ON `moon_scan_results` (`scanned_at`);
CREATE INDEX `idx_moon_scan_results_system_id` ON `moon_scan_results` (`system_id`);
CREATE TABLE `moon_scan_systems` (
  `id` INTEGER NOT NULL,
  `system_name` TEXT NOT NULL,
  `corporation_id` INTEGER NULL,
  `region_id` INTEGER NOT NULL,
  `pricing_mode` TEXT NOT NULL DEFAULT 'raw',
  `last_scanned_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE (`system_name`,`corporation_id`)
);
CREATE INDEX `idx_moon_scan_systems_corporation_id` ON `moon_scan_systems` (`corporation_id`);
CREATE TABLE `player_owned_structures` (
  `structure_id` INTEGER NOT NULL,
  `name` TEXT NULL,
  `owner_id` INTEGER NULL,
  `solar_system_id` INTEGER NULL,
  `type_id` INTEGER NULL,
  `position_x` REAL NULL,
  `position_y` REAL NULL,
  `position_z` REAL NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`structure_id`)
);
CREATE INDEX `idx_player_owned_structures_owner_id` ON `player_owned_structures` (`owner_id`);
CREATE INDEX `idx_player_owned_structures_solar_system_id` ON `player_owned_structures` (`solar_system_id`);
CREATE TABLE `structure_character_access` (
  `structure_id` INTEGER NOT NULL,
  `character_id` INTEGER NOT NULL,
  `access_denied_at` TIMESTAMP NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`structure_id`,`character_id`)
);
CREATE INDEX `idx_structure_character_access_character_id` ON `structure_character_access` (`character_id`);
CREATE TABLE `corporation_build_program_configs` (
  `id` INTEGER NOT NULL,
  `corporation_id` INTEGER NOT NULL,
  `corporation_name` TEXT NULL,
  `character_id` INTEGER NOT NULL,
  `enabled_by_user_id` INTEGER NOT NULL,
  `is_active` INTEGER NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE (`corporation_id`)
);
CREATE TABLE `corporation_build_programs` (
  `id` INTEGER NOT NULL,
  `corporation_id` INTEGER NOT NULL,
  `created_by_user_id` INTEGER NOT NULL,
  `name` TEXT NOT NULL,
  `program_type` TEXT NOT NULL DEFAULT 'manufacturing',
  `t2_type_id` INTEGER NULL,
  `t2_type_name` TEXT NULL,
  `steps` TEXT NOT NULL,
  `settings` TEXT NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`)
);
CREATE INDEX `idx_corporation_build_programs_corporation_id` ON `corporation_build_programs` (`corporation_id`);
CREATE INDEX `corp_build_programs_corp_updated_idx` ON `corporation_build_programs` (`corporation_id`,`updated_at`);
CREATE TABLE `industry_stock_items` (
  `id` INTEGER NOT NULL,
  `user_id` INTEGER NOT NULL,
  `type_id` INTEGER NOT NULL,
  `type_name` TEXT NOT NULL,
  `desired_quantity` INTEGER NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE (`user_id`,`type_id`)
);
CREATE TABLE `ess_tracked_systems` (
  `id` INTEGER NOT NULL,
  `corporation_id` INTEGER NOT NULL,
  `solar_system_id` INTEGER NOT NULL,
  `solar_system_name` TEXT NOT NULL,
  `cycle_minutes` INTEGER NOT NULL DEFAULT 165,
  `cycle_anchor_at` TIMESTAMP NOT NULL,
  `notes` TEXT NULL,
  `created_by_user_id` INTEGER NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE (`corporation_id`,`solar_system_id`)
);
CREATE INDEX `idx_ess_tracked_systems_corporation_id` ON `ess_tracked_systems` (`corporation_id`);
CREATE TABLE `ess_payout_events` (
  `id` INTEGER NOT NULL,
  `ess_tracked_system_id` INTEGER NOT NULL,
  `occurred_at` TIMESTAMP NOT NULL,
  `amount_isk` REAL NULL,
  `recorded_by_user_id` INTEGER NULL,
  `notes` TEXT NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`)
);
CREATE INDEX `idx_ess_payout_events_ess_tracked_system_id_occurred_at` ON `ess_payout_events` (`ess_tracked_system_id`,`occurred_at`);
CREATE TABLE `structure_vulnerability_windows` (
  `id` INTEGER NOT NULL,
  `corporation_id` INTEGER NOT NULL,
  `solar_system_id` INTEGER NOT NULL,
  `solar_system_name` TEXT NOT NULL,
  `structure_type` TEXT NOT NULL,
  `structure_name` TEXT NOT NULL,
  `day_of_week` INTEGER NULL,
  `start_time_utc` TIME NOT NULL,
  `duration_minutes` INTEGER NOT NULL DEFAULT 60,
  `reinforce_exit_at` TIMESTAMP NULL,
  `notes` TEXT NULL,
  `created_by_user_id` INTEGER NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`)
);
CREATE INDEX `svw_corp_system_idx` ON `structure_vulnerability_windows` (`corporation_id`,`solar_system_id`);
CREATE INDEX `svw_corp_idx` ON `structure_vulnerability_windows` (`corporation_id`);
CREATE TABLE `market_order_sweeps` (
  `id` INTEGER NOT NULL,
  `sweep_id` TEXT NOT NULL,
  `region_id` INTEGER NOT NULL,
  `generation` INTEGER NOT NULL DEFAULT 0,
  `status` TEXT NOT NULL DEFAULT 'running',
  `started_at` TIMESTAMP NOT NULL,
  `finished_at` TIMESTAMP NULL,
  `duration_seconds` INTEGER NULL,
  `pages_total` INTEGER NOT NULL DEFAULT 0,
  `pages_fetched` INTEGER NOT NULL DEFAULT 0,
  `pages_not_modified` INTEGER NOT NULL DEFAULT 0,
  `orders_seen` INTEGER NOT NULL DEFAULT 0,
  `orders_deleted` INTEGER NOT NULL DEFAULT 0,
  `types_aggregated` INTEGER NOT NULL DEFAULT 0,
  `error` TEXT NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE (`sweep_id`)
);
CREATE INDEX `market_order_sweeps_region_status_started_idx` ON `market_order_sweeps` (`region_id`,`status`,`started_at`);
CREATE TABLE `market_aggregates` (
  `id` INTEGER NOT NULL,
  `region_id` INTEGER NOT NULL,
  `type_id` INTEGER NOT NULL,
  `sweep_id` TEXT NOT NULL,
  `snapshot_at` TIMESTAMP NOT NULL,
  `best_bid` REAL NULL,
  `best_ask` REAL NULL,
  `sell_price_p5` REAL NULL,
  `sell_volume` INTEGER NOT NULL DEFAULT 0,
  `buy_volume` INTEGER NOT NULL DEFAULT 0,
  `sell_orders` INTEGER NOT NULL DEFAULT 0,
  `buy_orders` INTEGER NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `market_aggregates_region_sweep_type_unique` UNIQUE (`region_id`,`sweep_id`,`type_id`)
);
CREATE INDEX `market_aggregates_region_snapshot_idx` ON `market_aggregates` (`region_id`,`snapshot_at`);
CREATE INDEX `market_aggregates_region_sweep_idx` ON `market_aggregates` (`region_id`,`sweep_id`);
CREATE TABLE `asset_snapshot_owners` (
  `id` INTEGER NOT NULL,
  `owner_type` TEXT NOT NULL,
  `owner_id` INTEGER NOT NULL,
  `generation` INTEGER NOT NULL DEFAULT 0,
  `active_snapshot_id` INTEGER NULL,
  `last_checked_at` TIMESTAMP NULL,
  `last_succeeded_at` TIMESTAMP NULL,
  `next_due_at` TIMESTAMP NULL,
  `failure_reason` TEXT NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE (`owner_type`,`owner_id`)
);
CREATE INDEX `idx_asset_snapshot_owners_next_due_at` ON `asset_snapshot_owners` (`next_due_at`);
CREATE TABLE `asset_snapshots` (
  `id` INTEGER NOT NULL,
  `owner_id` INTEGER NOT NULL,
  `generation` INTEGER NOT NULL,
  `status` TEXT NOT NULL DEFAULT 'building',
  `item_count` INTEGER NOT NULL DEFAULT 0,
  `enrichment_pending` INTEGER NOT NULL DEFAULT 0,
  `source_updated_at` TIMESTAMP NULL,
  `source_expires_at` TIMESTAMP NULL,
  `published_at` TIMESTAMP NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE (`owner_id`,`generation`)
);
CREATE INDEX `idx_asset_snapshots_owner_id_status` ON `asset_snapshots` (`owner_id`,`status`);
CREATE TABLE `asset_snapshot_items` (
  `id` INTEGER NOT NULL,
  `snapshot_id` INTEGER NOT NULL,
  `item_id` INTEGER NOT NULL,
  `type_id` INTEGER NOT NULL,
  `quantity` INTEGER NOT NULL,
  `location_id` INTEGER NOT NULL,
  `parent_item_id` INTEGER NULL,
  `root_item_id` INTEGER NOT NULL,
  `root_location_id` INTEGER NOT NULL,
  `solar_system_id` INTEGER NULL,
  `solar_system_name` TEXT NOT NULL,
  `type_name` TEXT NOT NULL,
  `name` TEXT NULL,
  `location_name` TEXT NOT NULL,
  `character_name` TEXT NOT NULL,
  `owner_type` TEXT NOT NULL,
  `payload` TEXT NOT NULL,
  `raw_payload` TEXT NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE (`snapshot_id`,`item_id`)
);
CREATE INDEX `idx_asset_snapshot_items_snapshot_id_parent_item_id` ON `asset_snapshot_items` (`snapshot_id`,`parent_item_id`);
CREATE INDEX `idx_asset_snapshot_items_snapshot_id_root_item_id` ON `asset_snapshot_items` (`snapshot_id`,`root_item_id`);
CREATE INDEX `asset_items_snapshot_location_parent_idx` ON `asset_snapshot_items` (`snapshot_id`,`root_location_id`,`parent_item_id`);
CREATE INDEX `idx_asset_snapshot_items_snapshot_id_type_name` ON `asset_snapshot_items` (`snapshot_id`,`type_name`);
CREATE INDEX `idx_asset_snapshot_items_snapshot_id_quantity` ON `asset_snapshot_items` (`snapshot_id`,`quantity`);
CREATE TABLE `market_region_states` (
  `region_id` INTEGER NOT NULL,
  `latest_generation` INTEGER NOT NULL DEFAULT 0,
  `latest_sweep_id` TEXT NULL,
  `latest_started_at` TIMESTAMP NULL,
  `active_generation` INTEGER NOT NULL DEFAULT 0,
  `active_sweep_id` TEXT NULL,
  `active_snapshot_at` TIMESTAMP NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`region_id`)
);
CREATE INDEX `market_region_states_active_sweep_idx` ON `market_region_states` (`active_sweep_id`);
CREATE TABLE `public_contract_generations` (
  `id` INTEGER NOT NULL,
  `status` TEXT NOT NULL,
  `contract_count` INTEGER NOT NULL DEFAULT 0,
  `item_count` INTEGER NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `published_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`)
);
CREATE INDEX `idx_public_contract_generations_status` ON `public_contract_generations` (`status`);
CREATE TABLE `public_contract_snapshot_state` (
  `id` INTEGER NOT NULL,
  `active_generation_id` INTEGER NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`active_generation_id`) REFERENCES `public_contract_generations` (`id`) ON DELETE RESTRICT
);
CREATE TABLE `public_contract_snapshot_contracts` (
  `generation_id` INTEGER NOT NULL,
  `contract_id` INTEGER NOT NULL,
  `region_id` INTEGER NOT NULL,
  `type` TEXT NOT NULL,
  `title` TEXT NULL,
  `for_corporation` INTEGER NOT NULL DEFAULT 0,
  `issuer_corporation_id` INTEGER NULL,
  `issuer_id` INTEGER NULL,
  `date_issued` TIMESTAMP NULL,
  `date_expired` TIMESTAMP NULL,
  `days_to_complete` INTEGER NOT NULL DEFAULT 0,
  `start_location_id` INTEGER NULL,
  `end_location_id` INTEGER NULL,
  `station_id` INTEGER NULL,
  `system_id` INTEGER NULL,
  `constellation_id` INTEGER NULL,
  `price` REAL NOT NULL DEFAULT 0,
  `buyout` REAL NULL,
  `reward` REAL NULL,
  `collateral` REAL NULL,
  `volume` REAL NOT NULL DEFAULT 0,
  `http_last_modified` TIMESTAMP NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`generation_id`,`contract_id`),
  FOREIGN KEY (`generation_id`) REFERENCES `public_contract_generations` (`id`) ON DELETE CASCADE
);
CREATE INDEX `idx_public_contract_snapshot_contracts_region_id` ON `public_contract_snapshot_contracts` (`region_id`);
CREATE INDEX `idx_public_contract_snapshot_contracts_type` ON `public_contract_snapshot_contracts` (`type`);
CREATE TABLE `public_contract_snapshot_items` (
  `id` INTEGER NOT NULL,
  `generation_id` INTEGER NOT NULL,
  `contract_id` INTEGER NOT NULL,
  `type_id` INTEGER NOT NULL,
  `quantity` INTEGER NOT NULL DEFAULT 1,
  `is_included` INTEGER NOT NULL DEFAULT 1,
  `is_blueprint_copy` INTEGER NULL,
  `material_efficiency` INTEGER NULL,
  `time_efficiency` INTEGER NULL,
  `runs` INTEGER NULL,
  `record_id` INTEGER NULL,
  `item_id` INTEGER NULL,
  `http_last_modified` TIMESTAMP NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`generation_id`) REFERENCES `public_contract_generations` (`id`) ON DELETE CASCADE
);
CREATE INDEX `idx_public_contract_snapshot_items_contract_id` ON `public_contract_snapshot_items` (`contract_id`);
CREATE INDEX `idx_public_contract_snapshot_items_type_id` ON `public_contract_snapshot_items` (`type_id`);
CREATE INDEX `pc_items_generation_contract_type_idx` ON `public_contract_snapshot_items` (`generation_id`,`contract_id`,`type_id`);
CREATE TABLE `esi_diagnostic_events` (
  `id` INTEGER NOT NULL,
  `event_id` TEXT NOT NULL,
  `occurred_at` TIMESTAMP NOT NULL,
  `kind` TEXT NOT NULL,
  `method` TEXT NULL,
  `operation_key` TEXT NOT NULL DEFAULT '',
  `compatibility_date` TEXT NULL,
  `dataset` TEXT NULL,
  `job_class` TEXT NULL,
  `correlation_id` TEXT NULL,
  `source_identity` TEXT NOT NULL DEFAULT '',
  `application_id` TEXT NOT NULL DEFAULT '',
  `character_id` INTEGER NULL,
  `corporation_id` INTEGER NULL,
  `resource_id` INTEGER NULL,
  `page` INTEGER NULL,
  `batch_size` INTEGER NULL,
  `http_status` INTEGER NULL,
  `reason` TEXT NOT NULL DEFAULT 'unknown',
  `duration_ms` INTEGER NULL,
  `response_received` INTEGER NOT NULL DEFAULT 0,
  `legacy_remaining` INTEGER NULL,
  `legacy_reset_seconds` INTEGER NULL,
  `legacy_reset_at` TIMESTAMP NULL,
  `limiter_group` TEXT NULL,
  `token_limit` TEXT NULL,
  `token_used` INTEGER NULL,
  `token_remaining` INTEGER NULL,
  `retry_after_seconds` INTEGER NULL,
  `upstream_request_id` TEXT NULL,
  `batch_fingerprint` TEXT NULL,
  `release` TEXT NULL,
  PRIMARY KEY (`id`),
  UNIQUE (`event_id`)
);
CREATE INDEX `idx_esi_diagnostic_events_occurred_at_id` ON `esi_diagnostic_events` (`occurred_at`,`id`);
CREATE INDEX `esi_events_operation_status_idx` ON `esi_diagnostic_events` (`operation_key`,`http_status`,`occurred_at`);
CREATE INDEX `idx_esi_diagnostic_events_character_id_occurred_at` ON `esi_diagnostic_events` (`character_id`,`occurred_at`);
CREATE TABLE `esi_endpoint_metrics` (
  `id` INTEGER NOT NULL,
  `dimension_hash` TEXT NOT NULL,
  `bucket_at` TIMESTAMP NOT NULL,
  `operation_key` TEXT NOT NULL DEFAULT '',
  `event_kind` TEXT NOT NULL,
  `http_status` INTEGER NOT NULL DEFAULT 0,
  `reason` TEXT NOT NULL DEFAULT 'unknown',
  `dataset` TEXT NOT NULL DEFAULT '',
  `source_identity` TEXT NOT NULL DEFAULT '',
  `limiter_group` TEXT NOT NULL DEFAULT '',
  `request_count` INTEGER NOT NULL DEFAULT 0,
  `success_count` INTEGER NOT NULL DEFAULT 0,
  `error_count` INTEGER NOT NULL DEFAULT 0,
  `duration_sum_ms` INTEGER NOT NULL DEFAULT 0,
  `duration_count` INTEGER NOT NULL DEFAULT 0,
  `duration_max_ms` INTEGER NOT NULL DEFAULT 0,
  `first_observed_at` TIMESTAMP NULL,
  `last_observed_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `esi_endpoint_metrics_unique` UNIQUE (`dimension_hash`,`bucket_at`)
);
CREATE INDEX `idx_esi_endpoint_metrics_bucket_at` ON `esi_endpoint_metrics` (`bucket_at`);
CREATE INDEX `idx_esi_endpoint_metrics_operation_key_bucket_at` ON `esi_endpoint_metrics` (`operation_key`,`bucket_at`);
CREATE TABLE `esi_budget_metrics` (
  `id` INTEGER NOT NULL,
  `dimension_hash` TEXT NOT NULL,
  `bucket_at` TIMESTAMP NOT NULL,
  `granularity` TEXT NOT NULL,
  `source_identity` TEXT NOT NULL DEFAULT '',
  `application_id` TEXT NOT NULL DEFAULT '',
  `mechanism` TEXT NOT NULL,
  `limiter_group` TEXT NOT NULL DEFAULT '',
  `observations` INTEGER NOT NULL DEFAULT 0,
  `remaining_min` INTEGER NULL,
  `remaining_max` INTEGER NULL,
  `remaining_first` INTEGER NULL,
  `remaining_last` INTEGER NULL,
  `used_sum` INTEGER NULL,
  `reset_at_min` TIMESTAMP NULL,
  `reset_at_max` TIMESTAMP NULL,
  `circuit_opened_count` INTEGER NOT NULL DEFAULT 0,
  `circuit_closed_count` INTEGER NOT NULL DEFAULT 0,
  `pause_seconds` INTEGER NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  CONSTRAINT `esi_budget_metrics_unique` UNIQUE (`dimension_hash`,`bucket_at`)
);
CREATE INDEX `idx_esi_budget_metrics_granularity_bucket_at` ON `esi_budget_metrics` (`granularity`,`bucket_at`);
CREATE TABLE `esi_telemetry_checkpoints` (
  `name` TEXT NOT NULL,
  `stream_epoch` TEXT NULL,
  `last_stream_id` TEXT NULL,
  `entries_consumed` INTEGER NOT NULL DEFAULT 0,
  `last_flushed_at` TIMESTAMP NULL,
  `gap_count` INTEGER NOT NULL DEFAULT 0,
  `last_gap_at` TIMESTAMP NULL,
  `last_gap_reason` TEXT NULL,
  `dropped_events` INTEGER NOT NULL DEFAULT 0,
  `expired_rows` INTEGER NOT NULL DEFAULT 0,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`name`)
);
CREATE TABLE `push_subscriptions` (
  `id` INTEGER NOT NULL,
  `user_id` INTEGER NOT NULL,
  `endpoint` TEXT NOT NULL,
  `endpoint_hash` TEXT NOT NULL,
  `public_key` TEXT NOT NULL,
  `auth_token` TEXT NOT NULL,
  `content_encoding` TEXT NOT NULL DEFAULT 'aes128gcm',
  `user_agent` TEXT NULL,
  `subscribed_at` TIMESTAMP NOT NULL,
  `last_used_at` TIMESTAMP NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE (`endpoint_hash`)
);
CREATE TABLE `notification_preferences` (
  `id` INTEGER NOT NULL,
  `user_id` INTEGER NOT NULL,
  `type` TEXT NOT NULL,
  `enabled` INTEGER NOT NULL,
  `lead_minutes` INTEGER NULL,
  `character_ids` TEXT NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE (`user_id`,`type`)
);
CREATE TABLE `notification_settings` (
  `user_id` INTEGER NOT NULL,
  `quiet_start` TEXT NULL,
  `quiet_end` TEXT NULL,
  `timezone` TEXT NOT NULL DEFAULT 'UTC',
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL
);
CREATE TABLE `notification_log` (
  `id` INTEGER NOT NULL,
  `user_id` INTEGER NOT NULL,
  `type` TEXT NOT NULL,
  `dedupe_key` TEXT NOT NULL,
  `sent_at` TIMESTAMP NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE (`user_id`,`dedupe_key`)
);
CREATE INDEX `idx_notification_log_sent_at` ON `notification_log` (`sent_at`);
CREATE TABLE `go_jobs` (
  `id` INTEGER NOT NULL,
  `queue` TEXT NOT NULL,
  `name` TEXT NOT NULL,
  `payload` TEXT NOT NULL DEFAULT '',
  `attempts` INTEGER NOT NULL DEFAULT 0,
  `max_tries` INTEGER NOT NULL DEFAULT 1,
  `enqueued_at` TEXT NOT NULL,
  `available_at` TEXT NOT NULL DEFAULT (CURRENT_TIMESTAMP),
  PRIMARY KEY (`id`)
);
CREATE INDEX `idx_go_jobs_queue_available` ON `go_jobs` (`queue`, `available_at`);
CREATE TABLE `go_locks` (
  `name` TEXT NOT NULL,
  `expires_at` TEXT NOT NULL,
  PRIMARY KEY (`name`)
);
CREATE TABLE `go_cache` (
  `key` TEXT NOT NULL,
  `value` TEXT NOT NULL,
  `expires_at` TEXT NULL,
  PRIMARY KEY (`key`)
);
CREATE TABLE `go_diag_stream` (
  `id` INTEGER NOT NULL,
  `payload` TEXT NOT NULL,
  PRIMARY KEY (`id`)
);
CREATE INDEX `idx_go_diag_stream_id` ON `go_diag_stream` (`id`);
CREATE TABLE `go_diag_drops` (
  `id` INTEGER NOT NULL,
  PRIMARY KEY (`id`)
);

