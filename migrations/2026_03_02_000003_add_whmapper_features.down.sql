DROP TABLE IF EXISTS `wormhole_jump_logs`;
DROP TABLE IF EXISTS `wholemap_system_notes`;
ALTER TABLE `wormhole_connections` DROP COLUMN `size`;
ALTER TABLE `wholemap_signatures` DROP COLUMN `locked`;
ALTER TABLE `wholemap_signatures` DROP COLUMN `created_by`;
ALTER TABLE `wholemap_signatures` DROP COLUMN `updated_by`;
