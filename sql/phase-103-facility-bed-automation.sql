-- Phase 103 (a beta tester GH #20, 2026-07-01) — facility bed-count automation
--
-- Adds a per-facility flag controlling whether the simple beds_a/beds_o
-- counters change automatically on unit "delivery" status transitions.
--
-- Values:
--   'manual' (default) — nothing fires automatically; a facility admin
--                        edits beds_a/beds_o via facility-edit.php.
--   'auto'             — a unit whose transport DESTINATION is this
--                        facility, transitioning to a delivery-flavored
--                        status (see BED_AUTO_STATUS_PATTERNS in
--                        inc/bed_auto.php), decrements beds_a by 1 and
--                        increments beds_o by 1, once per (assign_id,
--                        facility_id) pair.
--
--   The destination facility resolves COALESCE(assigns.rec_facility_id,
--   ticket.rec_facility) — per-unit first (a mass-casualty incident sends
--   different units to different hospitals), incident-level fallback. The
--   per-unit column is legacy; NewUI's per-unit write path was restored in
--   Phase 116 (see specs/phase-116-per-unit-receiving-facility). Do not
--   reduce this to an incident-only read.
--
-- Idempotent: the ALTER checks are wrapped by the installer so re-runs
-- are safe.

ALTER TABLE `facilities`
    ADD COLUMN `bed_auto_mode` VARCHAR(16) NOT NULL DEFAULT 'manual'
        COMMENT 'Bed-count automation mode: manual | auto'
        AFTER `beds_info`;

-- Audit table for the automation. Populated at runtime by
-- _bed_auto_ensure_log_table() on first use, but declared here so
-- fresh installs pick it up in one place.
CREATE TABLE IF NOT EXISTS `facility_bed_auto_log` (
    `id`            INT AUTO_INCREMENT PRIMARY KEY,
    `assign_id`     INT NOT NULL,
    `facility_id`   INT NOT NULL,
    `responder_id`  INT NOT NULL,
    `ticket_id`     INT NOT NULL,
    `delta_a`       INT NOT NULL DEFAULT 0,
    `delta_o`       INT NOT NULL DEFAULT 0,
    `status_id`     INT NOT NULL DEFAULT 0,
    `status_val`    VARCHAR(64) DEFAULT '',
    `applied_by`    INT NOT NULL DEFAULT 0,
    `applied_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_assign_facility` (`assign_id`, `facility_id`),
    KEY `idx_facility_time` (`facility_id`, `applied_at`),
    KEY `idx_responder`     (`responder_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
