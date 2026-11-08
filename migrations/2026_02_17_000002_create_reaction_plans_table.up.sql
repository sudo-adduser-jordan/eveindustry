CREATE TABLE `reaction_plans` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `entries` JSON NOT NULL,
  `default_runs_per_slot` INT NOT NULL DEFAULT 240,
  `rig_bonus` DECIMAL(5,2) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
