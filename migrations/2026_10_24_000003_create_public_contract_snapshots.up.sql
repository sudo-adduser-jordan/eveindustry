CREATE TABLE `public_contract_generations` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
  `status` VARCHAR(16) NOT NULL,
  `contract_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `item_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `published_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  KEY (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `public_contract_snapshot_state` (
  `id` TINYINT UNSIGNED NOT NULL,
  `active_generation_id` BIGINT UNSIGNED NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`active_generation_id`) REFERENCES `public_contract_generations` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `public_contract_snapshot_contracts` (
  `generation_id` BIGINT UNSIGNED NOT NULL,
  `contract_id` BIGINT UNSIGNED NOT NULL,
  `region_id` INT UNSIGNED NOT NULL,
  `type` VARCHAR(30) NOT NULL,
  `title` VARCHAR(255) NULL,
  `for_corporation` TINYINT(1) NOT NULL DEFAULT 0,
  `issuer_corporation_id` BIGINT UNSIGNED NULL,
  `issuer_id` BIGINT UNSIGNED NULL,
  `date_issued` TIMESTAMP NULL,
  `date_expired` TIMESTAMP NULL,
  `days_to_complete` INT UNSIGNED NOT NULL DEFAULT 0,
  `start_location_id` BIGINT UNSIGNED NULL,
  `end_location_id` BIGINT UNSIGNED NULL,
  `station_id` BIGINT UNSIGNED NULL,
  `system_id` INT UNSIGNED NULL,
  `constellation_id` INT UNSIGNED NULL,
  `price` DOUBLE NOT NULL DEFAULT 0,
  `buyout` DOUBLE NULL,
  `reward` DOUBLE NULL,
  `collateral` DOUBLE NULL,
  `volume` DOUBLE NOT NULL DEFAULT 0,
  `http_last_modified` TIMESTAMP NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`generation_id`,`contract_id`),
  KEY (`region_id`),
  KEY (`type`),
  FOREIGN KEY (`generation_id`) REFERENCES `public_contract_generations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `public_contract_snapshot_items` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
  `generation_id` BIGINT UNSIGNED NOT NULL,
  `contract_id` BIGINT UNSIGNED NOT NULL,
  `type_id` INT UNSIGNED NOT NULL,
  `quantity` INT UNSIGNED NOT NULL DEFAULT 1,
  `is_included` TINYINT(1) NOT NULL DEFAULT 1,
  `is_blueprint_copy` TINYINT(1) NULL,
  `material_efficiency` SMALLINT UNSIGNED NULL,
  `time_efficiency` SMALLINT UNSIGNED NULL,
  `runs` INT NULL,
  `record_id` BIGINT UNSIGNED NULL,
  `item_id` BIGINT UNSIGNED NULL,
  `http_last_modified` TIMESTAMP NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  KEY (`contract_id`),
  KEY (`type_id`),
  KEY `pc_items_generation_contract_type_idx` (`generation_id`,`contract_id`,`type_id`),
  FOREIGN KEY (`generation_id`) REFERENCES `public_contract_generations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Data migration: seed generation 1 from the live tables so readers keep
-- working before the first Everef refresh completes.
INSERT INTO `public_contract_generations` (`id`, `status`, `contract_count`, `item_count`, `created_at`, `published_at`)
SELECT 1, 'published', (SELECT COUNT(*) FROM `public_contracts`), (SELECT COUNT(*) FROM `public_contract_items`), NOW(), NOW();
INSERT INTO `public_contract_snapshot_contracts` (`generation_id`, `contract_id`, `region_id`, `type`, `title`, `for_corporation`, `issuer_corporation_id`, `issuer_id`, `date_issued`, `date_expired`, `days_to_complete`, `start_location_id`, `end_location_id`, `station_id`, `system_id`, `constellation_id`, `price`, `buyout`, `reward`, `collateral`, `volume`, `http_last_modified`, `created_at`, `updated_at`)
SELECT 1, `contract_id`, `region_id`, `type`, `title`, `for_corporation`, `issuer_corporation_id`, `issuer_id`, `date_issued`, `date_expired`, `days_to_complete`, `start_location_id`, `end_location_id`, `station_id`, `system_id`, `constellation_id`, `price`, `buyout`, `reward`, `collateral`, `volume`, `http_last_modified`, `created_at`, `updated_at`
FROM `public_contracts` ORDER BY `contract_id`;
INSERT INTO `public_contract_snapshot_items` (`id`, `generation_id`, `contract_id`, `type_id`, `quantity`, `is_included`, `is_blueprint_copy`, `material_efficiency`, `time_efficiency`, `runs`, `record_id`, `item_id`, `http_last_modified`, `created_at`, `updated_at`)
SELECT `id`, 1, `contract_id`, `type_id`, `quantity`, `is_included`, `is_blueprint_copy`, `material_efficiency`, `time_efficiency`, `runs`, `record_id`, `item_id`, `http_last_modified`, `created_at`, `updated_at`
FROM `public_contract_items` ORDER BY `id`;
INSERT INTO `public_contract_snapshot_state` (`id`, `active_generation_id`, `updated_at`) VALUES (1, 1, NOW());
