-- ═══════════════════════════════════════════════════════════════
-- Phase C: Org-Scoped Features — Schema Migrations
-- ═══════════════════════════════════════════════════════════════

-- 1. Add org_id to member_types so each org can have its own type definitions
--    NULL org_id means "global" (visible to all orgs)

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'member_types'
                     AND COLUMN_NAME = 'org_id');

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `member_types` ADD COLUMN `org_id` INT DEFAULT NULL AFTER `sort_order`, ADD INDEX `idx_org_id` (`org_id`)',
    'SELECT "member_types.org_id already exists"');

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


-- 2. Add org_id to ticket table so incidents belong to an organization
--    NULL org_id means "unscoped" (visible to all orgs)

SET @col_exists2 = (SELECT COUNT(*) FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE()
                      AND TABLE_NAME = 'ticket'
                      AND COLUMN_NAME = 'org_id');

SET @sql2 = IF(@col_exists2 = 0,
    'ALTER TABLE `ticket` ADD COLUMN `org_id` INT DEFAULT NULL, ADD INDEX `idx_ticket_org` (`org_id`)',
    'SELECT "ticket.org_id already exists"');

PREPARE stmt2 FROM @sql2;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;
