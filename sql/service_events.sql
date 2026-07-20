-- ═══════════════════════════════════════════════════════════
--  Phase 2: Service Events — uptime tracking history
-- ═══════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `newui_service_events` (
  `id`              INT AUTO_INCREMENT PRIMARY KEY,
  `service`         VARCHAR(64)   NOT NULL COMMENT 'database, webserver, zello_proxy, php, os',
  `event_type`      VARCHAR(32)   NOT NULL COMMENT 'start, stop, restart, crash, recovered, degraded',
  `detected_at`     DATETIME      NOT NULL,
  `uptime_seconds`  INT           DEFAULT NULL COMMENT 'Uptime at time of event',
  `details`         TEXT          DEFAULT NULL COMMENT 'JSON details (version, port, error msg, etc.)',
  `notes`           TEXT          DEFAULT NULL COMMENT 'Admin notes',
  KEY `idx_service`     (`service`),
  KEY `idx_detected_at` (`detected_at`),
  KEY `idx_svc_date`    (`service`, `detected_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Service state snapshot table — stores last known state per service
-- so we can detect transitions (was down, now up = "recovered")
CREATE TABLE IF NOT EXISTS `newui_service_state` (
  `service`         VARCHAR(64)   NOT NULL PRIMARY KEY,
  `last_status`     VARCHAR(16)   NOT NULL DEFAULT 'unknown' COMMENT 'ok, warn, error, unknown',
  `last_checked`    DATETIME      NOT NULL,
  `last_uptime_sec` INT           DEFAULT NULL,
  `consecutive_failures` INT      DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
