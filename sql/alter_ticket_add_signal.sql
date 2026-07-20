-- Add a `signal` column to the ticket table.
--
-- Background-agent flagged 2026-06-27 that api/incident-create.php
-- reads $input['signal'] but never persists it anywhere. The
-- new-incident form has a <select id="signal" name="signal"> populated
-- with hints.tag (the 8-char signal code), but the ticket table had no
-- column to store the selected value. Result: every operator who
-- picked a signal saw their selection silently discarded on create.
--
-- Per CLAUDE.md "Some tables may be missing columns depending on the
-- installation's age" — this is one of those columns. signal_code is
-- sized to match hints.tag (varchar(8) NOT NULL on hints), nullable
-- so existing rows don't need a backfill.
--
-- Idempotent: guarded ALTER via information_schema check pattern.

SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'ticket'
      AND COLUMN_NAME  = 'signal'
);

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `ticket` ADD COLUMN `signal` VARCHAR(8) NULL AFTER `nine_one_one`',
    'SELECT "ticket.signal column already exists" AS notice'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
