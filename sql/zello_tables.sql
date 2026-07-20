-- NewUI v4.0 - Zello Push-to-Talk Integration Tables
-- Message log, per-user config, and WebSocket auth tokens.

-- 1. Message log — stores all incoming/outgoing Zello messages
CREATE TABLE IF NOT EXISTS `zello_messages` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `channel`          VARCHAR(100) NOT NULL DEFAULT '',
  -- Phase E: DM partner Zello username (inbound = sender of a `for`-addressed
  -- text, outbound = the user we DM'd). Blank = channel broadcast.
  `recipient`        VARCHAR(100) NOT NULL DEFAULT '',
  `direction`        ENUM('incoming','outgoing') NOT NULL DEFAULT 'incoming',
  `message_type`     VARCHAR(20) NOT NULL DEFAULT 'text',
  `sender_username`  VARCHAR(100) NOT NULL DEFAULT '',
  `sender_display`   VARCHAR(100) NOT NULL DEFAULT '',
  `content`          TEXT,
  `incident_id`      INT UNSIGNED DEFAULT NULL,
  -- Voice-message metadata: duration of the recording and the relative URL of
  -- the saved .ogg under cache/zello-audio/. NULL for text/location rows.
  -- (proxy/ZelloProxyApp.php logMessage() writes both for voice messages.)
  `duration_ms`      INT DEFAULT NULL,
  `media_url`        VARCHAR(255) DEFAULT NULL,
  `created`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_channel    (`channel`),
  INDEX idx_recipient  (`recipient`),
  INDEX idx_created    (`created`),
  INDEX idx_incident   (`incident_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Per-user Zello preferences
CREATE TABLE IF NOT EXISTS `zello_user_config` (
  `user`          VARCHAR(64) NOT NULL PRIMARY KEY,
  `ptt_key`       VARCHAR(20) NOT NULL DEFAULT 'Space',
  `auto_connect`  TINYINT(1) NOT NULL DEFAULT 0,
  `play_sounds`   TINYINT(1) NOT NULL DEFAULT 1,
  `updated`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Short-lived WebSocket auth tokens (browser → proxy auth)
--    Tokens expire after 2 minutes and are single-use.
CREATE TABLE IF NOT EXISTS `zello_ws_tokens` (
  `token`       VARCHAR(64) NOT NULL PRIMARY KEY,
  `user`        VARCHAR(64) NOT NULL,
  `user_level`  INT NOT NULL DEFAULT 99,
  `created`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_created (`created`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
