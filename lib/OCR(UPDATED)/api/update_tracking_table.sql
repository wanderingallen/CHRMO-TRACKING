-- SQL script to update tracking table for mobile app integration
-- Run this in phpMyAdmin or MySQL command line

-- Add new columns to tracking table
ALTER TABLE `tracking` 
ADD COLUMN `ocr_content` TEXT NULL AFTER `file_type_icon`,
ADD COLUMN `mobile_timestamp` VARCHAR(50) NULL AFTER `ocr_content`,
ADD COLUMN `file_size` VARCHAR(20) NULL AFTER `mobile_timestamp`,
ADD COLUMN `user_email` VARCHAR(255) NULL AFTER `file_size`,
ADD COLUMN `file_path` VARCHAR(255) NULL AFTER `user_email`,
ADD COLUMN `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER `file_path`;

-- Add index for mobile_timestamp to prevent duplicates and improve performance
ALTER TABLE `tracking` 
ADD INDEX `idx_mobile_timestamp` (`mobile_timestamp`);

-- Add index for user_email for better query performance
ALTER TABLE `tracking` 
ADD INDEX `idx_user_email` (`user_email`);

-- Update stats table to include source information
ALTER TABLE `stats` 
ADD COLUMN `source` VARCHAR(50) DEFAULT 'Manual Entry' AFTER `date_archived`,
ADD COLUMN `document_type` VARCHAR(100) NULL AFTER `source`;
