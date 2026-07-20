-- NewUI v4.0 — Audit Log Table
-- OCSF-inspired lightweight event logging for all NewUI operations.
--
-- Design principles:
--   - Every write operation (create, update, delete) is logged
--   - Structured event categories instead of free-text codes
--   - Actor (who) + Target (what) pattern from OCSF
--   - Severity levels 0-5 (Informational through Critical)
--   - JSON details column for flexible per-event data
--   - Separate from legacy `log` table (dispatch operations)

CREATE TABLE IF NOT EXISTS newui_audit_log (
    id            BIGINT AUTO_INCREMENT PRIMARY KEY,

    -- When
    event_time    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    -- Who (actor)
    user_id       INT          DEFAULT NULL,
    user_name     VARCHAR(64)  DEFAULT NULL,
    ip_address    VARCHAR(45)  DEFAULT NULL,    -- IPv4 or IPv6

    -- What category (OCSF-inspired event classes)
    category      VARCHAR(32)  NOT NULL,         -- auth, config, personnel, incident, import, system, data
    activity      VARCHAR(32)  NOT NULL,         -- create, update, delete, login, logout, export, import, assign, error

    -- Severity (OCSF levels: 0=Unknown, 1=Info, 2=Low, 3=Medium, 4=High, 5=Critical)
    severity      TINYINT      NOT NULL DEFAULT 1,

    -- What was affected (target)
    target_type   VARCHAR(48)  DEFAULT NULL,     -- member, team, certification, incident, vehicle, equipment, user, setting, etc.
    target_id     VARCHAR(64)  DEFAULT NULL,     -- PK of target record (string for flexibility)

    -- Human-readable summary
    summary       VARCHAR(512) NOT NULL,         -- "Created member 'Sarah Chen' (KC9ABC)"

    -- Structured details (JSON for flexible per-event context)
    details       JSON         DEFAULT NULL,     -- {"old": {...}, "new": {...}, "fields_changed": [...]}

    -- Indexes
    KEY idx_event_time (event_time),
    KEY idx_category   (category),
    KEY idx_user_id    (user_id),
    KEY idx_target     (target_type, target_id),
    KEY idx_severity   (severity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
