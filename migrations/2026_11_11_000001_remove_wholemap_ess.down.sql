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
