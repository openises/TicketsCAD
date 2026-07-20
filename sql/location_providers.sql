-- ============================================================
-- Location Providers — Schema & Seed Data
-- Manages multiple location data sources for tracking
-- responder/unit positions on the map.
-- ============================================================

-- ── Provider definitions ─────────────────────────────────────

CREATE TABLE IF NOT EXISTS `location_providers` (
    `id`            INT AUTO_INCREMENT PRIMARY KEY,
    `code`          VARCHAR(32) NOT NULL UNIQUE,
    `name`          VARCHAR(64) NOT NULL,
    `enabled`       TINYINT(1) NOT NULL DEFAULT 0,
    `priority`      INT NOT NULL DEFAULT 50 COMMENT 'Lower number = higher priority',
    `config_json`   TEXT DEFAULT NULL COMMENT 'Provider-specific configuration',
    `icon`          VARCHAR(64) DEFAULT NULL COMMENT 'Bootstrap icon class',
    `color`         VARCHAR(16) DEFAULT NULL COMMENT 'Hex color for map markers',
    `created_at`    DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_lp_enabled` (`enabled`),
    KEY `idx_lp_priority` (`priority`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Location reports from all providers ──────────────────────

CREATE TABLE IF NOT EXISTS `location_reports` (
    `id`              BIGINT AUTO_INCREMENT PRIMARY KEY,
    `provider_id`     INT NOT NULL,
    `unit_identifier` VARCHAR(64) NOT NULL COMMENT 'Callsign, device ID, or IMEI',
    `lat`             DECIMAL(10,7) NOT NULL,
    `lng`             DECIMAL(10,7) NOT NULL,
    `altitude`        DECIMAL(7,1) DEFAULT NULL COMMENT 'Meters above sea level',
    `speed`           DECIMAL(6,1) DEFAULT NULL COMMENT 'km/h',
    `heading`         DECIMAL(5,1) DEFAULT NULL COMMENT 'Degrees 0-359.9',
    `accuracy`        DECIMAL(6,1) DEFAULT NULL COMMENT 'Meters',
    `battery`         TINYINT UNSIGNED DEFAULT NULL COMMENT 'Percentage 0-100',
    `raw_data`        TEXT DEFAULT NULL COMMENT 'Original payload for debugging',
    `reported_at`     DATETIME NOT NULL,
    `received_at`     DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_lr_unit` (`unit_identifier`),
    KEY `idx_lr_provider` (`provider_id`),
    KEY `idx_lr_reported` (`reported_at`),
    KEY `idx_lr_unit_time` (`unit_identifier`, `reported_at` DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Bind responders to provider identifiers ──────────────────

CREATE TABLE IF NOT EXISTS `unit_location_bindings` (
    `id`              INT AUTO_INCREMENT PRIMARY KEY,
    `responder_id`    INT NOT NULL,
    `provider_id`     INT NOT NULL,
    `unit_identifier` VARCHAR(64) NOT NULL COMMENT 'Callsign or device ID for this provider',
    `priority`        INT NOT NULL DEFAULT 50 COMMENT 'Lower = preferred when multiple bindings exist',
    `active`          TINYINT(1) NOT NULL DEFAULT 1,
    `source`          ENUM('manual','personnel') NOT NULL DEFAULT 'manual' COMMENT 'manual = operator-created, personnel = auto-bound from unit personnel assignment',
    `assignment_id`   INT NULL COMMENT 'unit_personnel_assignments.id when source=personnel (Phase 62 auto-bind)',
    `created_at`      DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_ulb_responder` (`responder_id`),
    KEY `idx_ulb_provider` (`provider_id`),
    KEY `idx_ulb_unit` (`unit_identifier`),
    KEY `idx_ulb_active` (`active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Seed default providers ───────────────────────────────────

INSERT IGNORE INTO `location_providers` (`code`, `name`, `enabled`, `priority`, `config_json`, `icon`, `color`) VALUES
('aprs',        'APRS-IS',           0, 10, '{"server":"rotate.aprs2.net","port":14580,"filter":"r/44.97/-93.26/100","passcode":""}',                 'bi-broadcast',      '#00AA00'),
('meshtastic',  'Meshtastic',        0, 20, '{"mode":"ip","ip_host":"meshtastic.local","ip_port":80,"serial_port":"","serial_baud":115200}',          'bi-router',         '#FF6600'),
-- 2026-06-22 fix: endpoint was '?action=report&provider_code=owntracks' which never
-- matched the dispatcher in api/location.php (line 33 checks ?provider=owntracks).
-- One-shot repair for existing installs lives in run_owntracks_url_fix.php.
('owntracks',   'OwnTracks',         0, 30, '{"endpoint":"/api/location.php?provider=owntracks","secret":""}',                                       'bi-phone',          '#0066FF'),
-- 2026-06-22: Traccar + OpenGTS get distinct provider rows. Both share the same
-- /api/location.php?provider=<code> dispatcher; opengts speaks the legacy GPRMC
-- query-string format, traccar speaks the modern osmand JSON POST. See api/
-- location.php for the receiver and docs/TRACCAR-SETUP.md for the operator path.
('opengts',     'OpenGTS',           0, 40, '{"endpoint":"/api/location.php?provider=opengts","note":"GPRMC HTTP query-string format"}',             'bi-geo-alt',        '#993399'),
('traccar',     'Traccar',           0, 45, '{"endpoint":"/api/location.php?provider=traccar","note":"Traccar Server JSON forwarder (osmand format)"}', 'bi-geo-fill',       '#FF8800'),
('dmr',         'DMR Radio GPS',     0, 50, '{"server":"","port":62031,"auth_key":""}',                                                               'bi-broadcast-pin',  '#CC0000'),
-- 2026-06-26: Zello shared-location. The Zello WebSocket proxy writes
-- location_reports from on_location events; unit_identifier = the sender
-- Zello username (member_comm_identifiers.values_json.username). The
-- channel member must enable location sharing in their Zello app.
-- Existing installs get this via run_zello_location_provider.php.
-- NOTE: no semicolons inside string values in this file — the importer
-- (run_03_location_providers.php) splits statements on ';' and an embedded
-- one silently truncated this whole INSERT for two weeks (fresh installs
-- got ZERO default providers, diagnosed 2026-07-07).
('zello',       'Zello Location',    0, 55, '{"note":"Populated by the Zello proxy from on_location events (unit_identifier = sender Zello username). Member must share location from their Zello app."}', 'bi-broadcast', '#FFB300'),
('internal',    'Internal GPS',      1, 60, '{"update_interval":30,"high_accuracy":true}',                                                            'bi-crosshair',      '#3366FF'),
('google_lat',  'Google Latitude',   0, 70, '{"api_key":"","deprecated":true}',                                                                       'bi-google',         '#4285F4');
