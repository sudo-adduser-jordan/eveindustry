ALTER TABLE `build_programs` ADD COLUMN `program_type` VARCHAR(255) NOT NULL DEFAULT 'manufacturing' AFTER `name`;
ALTER TABLE `build_programs` MODIFY COLUMN `t2_type_id` INT NULL;
ALTER TABLE `build_programs` MODIFY COLUMN `t2_type_name` VARCHAR(255) NULL;
