ALTER TABLE `users` ADD COLUMN `is_demo` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_stock_manager_enabled`;
ALTER TABLE `users` ADD KEY (`is_demo`);
