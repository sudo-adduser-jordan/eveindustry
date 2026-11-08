ALTER TABLE `build_programs` MODIFY COLUMN `t2_type_id` INT NULL;
ALTER TABLE `build_programs` MODIFY COLUMN `t2_type_name` VARCHAR(255) NULL;
ALTER TABLE `build_programs` DROP COLUMN `program_type`;
