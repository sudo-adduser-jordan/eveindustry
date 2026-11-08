ALTER TABLE `users` ADD COLUMN `is_stock_manager_enabled` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_active`;
