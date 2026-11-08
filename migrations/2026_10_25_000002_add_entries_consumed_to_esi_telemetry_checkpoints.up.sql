ALTER TABLE `esi_telemetry_checkpoints` ADD COLUMN `entries_consumed` BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER `last_stream_id`;
