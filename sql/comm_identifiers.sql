-- ============================================================
-- Communication / Location Modes + Member Identifiers
-- NewUI v4.0 — Flexible per-member comm ID system
-- ============================================================

-- Comm mode definitions (admin-configurable)
-- fields_json defines what form fields appear when adding an identifier
CREATE TABLE IF NOT EXISTS comm_modes (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    code            VARCHAR(32)  NOT NULL UNIQUE COMMENT 'Machine key: aprs, dmr, meshtastic, etc.',
    name            VARCHAR(64)  NOT NULL COMMENT 'Display name',
    icon            VARCHAR(32)  DEFAULT NULL COMMENT 'Bootstrap icon suffix (e.g. broadcast)',
    color           VARCHAR(7)   NOT NULL DEFAULT '#6c757d' COMMENT 'Badge hex color',
    fields_json     TEXT         NOT NULL COMMENT 'JSON array of field definitions',
    capabilities    VARCHAR(64)  DEFAULT NULL COMMENT 'Comma-separated: V=Voice, L=Location, 1T=1-Way Text, 2T=2-Way Text',
    lookup_url      VARCHAR(255) DEFAULT NULL COMMENT 'External API lookup URL template',
    enabled         TINYINT(1)   NOT NULL DEFAULT 1,
    sort_order      INT          NOT NULL DEFAULT 0,
    notes           TEXT         DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-member communication identifiers
-- values_json stores key-value pairs matching the mode's fields_json keys
CREATE TABLE IF NOT EXISTS member_comm_identifiers (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    member_id       INT          NOT NULL,
    comm_mode_id    INT          NOT NULL,
    label           VARCHAR(64)  DEFAULT NULL COMMENT 'User-friendly label: Mobile APRS, Portable HT',
    values_json     TEXT         NOT NULL COMMENT 'JSON object: {"callsign_ssid":"W1ABC-9"}',
    is_primary      TINYINT(1)   NOT NULL DEFAULT 0,
    notes           VARCHAR(255) DEFAULT NULL,
    created_at      DATETIME     DEFAULT NULL,
    updated_at      DATETIME     DEFAULT NULL,
    KEY idx_member_id (member_id),
    KEY idx_comm_mode_id (comm_mode_id),
    KEY idx_member_mode (member_id, comm_mode_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Seed: Default communication/tracking modes ──────────────

-- 1. APRS (Automatic Packet Reporting System)
--    Identifier: callsign + SSID (e.g., W1ABC-9)
INSERT IGNORE INTO comm_modes (code, name, icon, color, fields_json, enabled, sort_order, notes) VALUES
('aprs', 'APRS', 'broadcast-pin', '#28a745',
 '[{"key":"callsign_ssid","label":"Callsign-SSID","type":"text","placeholder":"W1ABC-9","maxlength":16,"required":true},{"key":"symbol","label":"APRS Symbol","type":"text","placeholder":"/k","maxlength":4,"required":false}]',
 1, 10, 'Automatic Packet Reporting System — amateur radio position and messaging');

-- 2. DMR / MotoTRBO
--    Identifier: Radio ID (decimal number, 24-bit)
--    Multiple per person possible (mobile + portable)
INSERT IGNORE INTO comm_modes (code, name, icon, color, fields_json, lookup_url, enabled, sort_order, notes) VALUES
('dmr', 'DMR / MotoTRBO', 'phone-vibrate', '#dc3545',
 '[{"key":"radio_id","label":"Radio ID","type":"text","placeholder":"3101234","maxlength":10,"required":true},{"key":"color_code","label":"Color Code","type":"text","placeholder":"1","maxlength":2,"required":false},{"key":"timeslot","label":"Timeslot","type":"select","options":["","1","2"],"required":false}]',
 'https://database.radioid.net/api/dmr/user/?callsign={callsign}',
 1, 20, 'Digital Mobile Radio — uses decimal Radio ID. Lookup via radioid.net');

-- 3. Meshtastic
--    Identifier: node_id (4-byte hex), short_name (4 chars), long_name (39 chars)
INSERT IGNORE INTO comm_modes (code, name, icon, color, fields_json, enabled, sort_order, notes) VALUES
('meshtastic', 'Meshtastic', 'hdd-network', '#17a2b8',
 '[{"key":"node_id","label":"Node ID","type":"text","placeholder":"!aabbccdd","maxlength":10,"required":true},{"key":"short_name","label":"Short Name","type":"text","placeholder":"ERIC","maxlength":4,"required":true},{"key":"long_name","label":"Long Name","type":"text","placeholder":"Eric Base Station","maxlength":39,"required":false}]',
 1, 30, 'LoRa mesh networking — off-grid text messaging and position reporting');

-- 4. Zello
--    Identifier: Zello username
INSERT IGNORE INTO comm_modes (code, name, icon, color, fields_json, enabled, sort_order, notes) VALUES
('zello', 'Zello', 'mic', '#ffc107',
 '[{"key":"username","label":"Zello Username","type":"text","placeholder":"","maxlength":64,"required":true},{"key":"channel","label":"Default Channel","type":"text","placeholder":"","maxlength":64,"required":false}]',
 1, 40, 'Zello push-to-talk — voice and text messaging over IP');

-- 5. OwnTracks
--    Identifier: MQTT topic + tracker ID
INSERT IGNORE INTO comm_modes (code, name, icon, color, fields_json, enabled, sort_order, notes) VALUES
('owntracks', 'OwnTracks', 'geo-alt', '#6f42c1',
 '[{"key":"mqtt_topic","label":"MQTT Topic","type":"text","placeholder":"owntracks/user/device","maxlength":128,"required":true},{"key":"tracker_id","label":"Tracker ID","type":"text","placeholder":"EO","maxlength":2,"required":true}]',
 1, 50, 'OwnTracks — MQTT-based location publishing from mobile devices');

-- 6. Generic Radio
--    Catch-all for any radio system not specifically supported
INSERT IGNORE INTO comm_modes (code, name, icon, color, fields_json, enabled, sort_order, notes) VALUES
('radio', 'Generic Radio', 'broadcast', '#6c757d',
 '[{"key":"identifier","label":"Radio ID / Callsign","type":"text","placeholder":"","maxlength":32,"required":true},{"key":"frequency","label":"Frequency","type":"text","placeholder":"146.520 MHz","maxlength":20,"required":false},{"key":"tone","label":"Tone / Code","type":"text","placeholder":"100.0 Hz","maxlength":16,"required":false}]',
 1, 99, 'Generic radio identifier for systems without a dedicated mode');

-- Set capabilities for all modes (V=Voice, L=Location, 1T=1-Way Text, 2T=2-Way Text)
UPDATE comm_modes SET capabilities = 'L,1T'  WHERE code = 'aprs'       AND capabilities IS NULL;
UPDATE comm_modes SET capabilities = 'V,2T'  WHERE code = 'dmr'        AND capabilities IS NULL;
UPDATE comm_modes SET capabilities = 'L,2T'  WHERE code = 'meshtastic' AND capabilities IS NULL;
UPDATE comm_modes SET capabilities = 'V'     WHERE code = 'zello'      AND capabilities IS NULL;
UPDATE comm_modes SET capabilities = 'L'     WHERE code = 'owntracks'  AND capabilities IS NULL;
UPDATE comm_modes SET capabilities = 'V'     WHERE code = 'radio'      AND capabilities IS NULL;
