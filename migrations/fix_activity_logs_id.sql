-- Fix activity_logs table for TiDB compatibility
-- Add AUTO_INCREMENT to id column

ALTER TABLE activity_logs MODIFY COLUMN id INT AUTO_INCREMENT PRIMARY KEY;
