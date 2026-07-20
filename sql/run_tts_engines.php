<?php
/**
 * Phase 113 — pluggable TTS engines ("Voice & Speech").
 *
 * tts_engines      — one row per configured engine (driver + config). The
 *                    API key is NEVER stored here; config_json holds a
 *                    key_ref filename under ../keys/tts/ (mode 0640).
 * tts_applications — the per-"speech application" routing table: which engine
 *                    + voice + sample-rate each place TicketsCAD speaks uses.
 *
 * Seeds a default offline Piper engine (reusing any existing zello_tts_piper_*
 * settings so it works out of the box where the Zello proxy already has Piper)
 * and the standard application rows — everything points at Piper until an
 * admin picks something else, so behaviour is unchanged on upgrade.
 *
 * Idempotent — guarded CREATE/seed; safe to run repeatedly.
 */

if (php_sapi_name() !== 'cli' && basename($_SERVER['SCRIPT_NAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('CLI or migration-runner only');
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

echo "Phase 113 — TTS engines\n";

try {
    db_query("CREATE TABLE IF NOT EXISTS `{$prefix}tts_engines` (
        `id`          INT AUTO_INCREMENT PRIMARY KEY,
        `engine_key`  VARCHAR(48) NOT NULL,
        `driver`      VARCHAR(24) NOT NULL DEFAULT 'piper'
                      COMMENT 'piper | openai_compat | deepgram | google | azure',
        `label`       VARCHAR(80) NOT NULL DEFAULT '',
        `config_json` TEXT NULL COMMENT 'driver config: endpoint, model, voice, key_ref (NOT the key), extras',
        `enabled`     TINYINT(1) NOT NULL DEFAULT 1,
        `last_ok_at`  DATETIME NULL,
        `last_error`  VARCHAR(255) NULL,
        `sort_order`  INT NOT NULL DEFAULT 0,
        UNIQUE KEY `uk_engine_key` (`engine_key`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "[OK] tts_engines table ready\n";

    db_query("CREATE TABLE IF NOT EXISTS `{$prefix}tts_applications` (
        `id`                 INT AUTO_INCREMENT PRIMARY KEY,
        `app_key`            VARCHAR(32) NOT NULL,
        `label`              VARCHAR(80) NOT NULL DEFAULT '',
        `engine_id`          INT NULL COMMENT 'chosen engine; NULL = the default Piper engine',
        `voice`              VARCHAR(120) NULL COMMENT 'voice override; NULL = engine default',
        `rate`               INT NOT NULL DEFAULT 8000 COMMENT 'target sample rate (Hz)',
        `fallback_engine_id` INT NULL,
        `sort_order`         INT NOT NULL DEFAULT 0,
        UNIQUE KEY `uk_app_key` (`app_key`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "[OK] tts_applications table ready\n";
} catch (Exception $e) {
    echo "[FAIL] schema: " . $e->getMessage() . "\n";
    exit(1);
}

// ── Seed the default Piper engine (reuse existing zello_tts_piper_* config) ──
try {
    $exists = db_fetch_one("SELECT id FROM `{$prefix}tts_engines` WHERE engine_key = 'piper-default'");
    if (!$exists) {
        $piperBin   = db_fetch_value("SELECT value FROM `{$prefix}settings` WHERE name = 'zello_tts_piper_bin'")   ?: '';
        $piperVoice = db_fetch_value("SELECT value FROM `{$prefix}settings` WHERE name = 'zello_tts_piper_voice'") ?: '';
        $piperRate  = db_fetch_value("SELECT value FROM `{$prefix}settings` WHERE name = 'zello_tts_piper_rate'")  ?: '22050';
        $ffmpegBin  = db_fetch_value("SELECT value FROM `{$prefix}settings` WHERE name = 'zello_tts_ffmpeg_bin'")  ?: 'ffmpeg';
        $cfg = json_encode([
            'bin'         => $piperBin,
            'voice'       => $piperVoice,
            'native_rate' => (int) $piperRate,
            'ffmpeg'      => $ffmpegBin,
        ], JSON_UNESCAPED_SLASHES);
        db_query(
            "INSERT INTO `{$prefix}tts_engines` (engine_key, driver, label, config_json, enabled, sort_order)
             VALUES ('piper-default', 'piper', 'Piper (offline, default)', ?, 1, 0)",
            [$cfg]
        );
        echo "[OK] seeded default Piper engine" . ($piperBin ? " (from existing Zello TTS config)" : " (unconfigured)") . "\n";
    } else {
        echo "[OK] default Piper engine already present\n";
    }

    $piperId = (int) db_fetch_value("SELECT id FROM `{$prefix}tts_engines` WHERE engine_key = 'piper-default'");

    // ── Seed the speech applications (all default to Piper) ──
    $apps = [
        ['weather_bulletin', 'Weather bulletins (radio read-out)', 8000,  10],
        ['radio_ai_reply',   'Radio AI replies',                   8000,  20],
        ['zello_readout',    'Zello read-outs',                    16000, 30],
        ['announcement',     'Station / intercom announcements',   22050, 40],
        ['sip_callout',      'Phone / SIP callouts',               8000,  50],
        ['test',             'Test — Listen sample',               22050, 90],
    ];
    foreach ($apps as $a) {
        $has = db_fetch_one("SELECT id FROM `{$prefix}tts_applications` WHERE app_key = ?", [$a[0]]);
        if (!$has) {
            db_query(
                "INSERT INTO `{$prefix}tts_applications` (app_key, label, engine_id, rate, sort_order)
                 VALUES (?, ?, ?, ?, ?)",
                [$a[0], $a[1], $piperId ?: null, $a[2], $a[3]]
            );
        }
    }
    echo "[OK] speech applications seeded (default → Piper)\n";
} catch (Exception $e) {
    echo "[WARN] seed: " . $e->getMessage() . "\n";
}

echo "Done.\n";
