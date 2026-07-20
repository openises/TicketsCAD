-- NewUI v4.0 - Additional tables for the dashboard
-- Run this against the newui database AFTER cloning tables from tickets.

CREATE TABLE IF NOT EXISTS `dashboard_layouts` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `layout_name` VARCHAR(50) NOT NULL DEFAULT 'default',
    `layout_json` TEXT NOT NULL COMMENT 'GridStack serialized layout',
    `hidden_widgets` TEXT DEFAULT '[]' COMMENT 'JSON array of hidden widget IDs',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `idx_user_layout` (`user_id`, `layout_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
