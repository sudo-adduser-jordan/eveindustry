ALTER TABLE `reaction_plans` ADD COLUMN `structure_type` VARCHAR(255) NOT NULL DEFAULT 'athanor' AFTER `rig_bonus`;
ALTER TABLE `reaction_plans` ADD COLUMN `rig_tier` VARCHAR(255) NOT NULL DEFAULT 'none' AFTER `structure_type`;
ALTER TABLE `reaction_plans` ADD COLUMN `security_space` VARCHAR(255) NOT NULL DEFAULT 'lowsec' AFTER `rig_tier`;
