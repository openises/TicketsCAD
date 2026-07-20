-- OwnTracks outbox: queued cmd payloads waiting to be delivered to a
-- member's device on their next POST. Used by:
--   - api/owntracks-config.php (pushes setConfiguration commands here)
--   - api/location.php          (dequeues + sends to the device)
--   - settings.php OwnTracks Diagnostics panel (SELECTs to show queue)
--
-- The table definition was previously inlined as a CREATE TABLE IF NOT
-- EXISTS in api/owntracks-config.php — meaning it only ever ran when an
-- admin actually pushed config. Operators who opened OwnTracks
-- Diagnostics BEFORE ever pushing config hit a 'Table doesn't exist'
-- error because the SELECT path didn't have the same lazy-create.
-- Beta tester a beta tester flagged this 2026-06-26.
--
-- Lifting the schema into a proper migration so install_fresh.php
-- creates it during the foundational-imports pass, and removing the
-- need for the lazy-create downstream.

CREATE TABLE IF NOT EXISTS `owntracks_outbox` (
    `id`           INT NOT NULL AUTO_INCREMENT,
    `member_id`    INT NOT NULL,
    `payload_json` TEXT NOT NULL,
    `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `consumed_at`  DATETIME NULL,
    `created_by`   INT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_pending` (`member_id`, `consumed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
