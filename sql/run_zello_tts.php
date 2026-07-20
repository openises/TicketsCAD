<?php
/**
 * Gap 1 (docs/training-scripts/zello-config-video-brief.md) — Zello TTS
 * broadcast audio schema check.
 *
 * The "type text → spoken on the channel" path queues a row in `zello_outbox`
 * with `kind='tts'` (the proxy synthesises speech + keys it onto the channel
 * as Opus audio). That table + its `kind` column already exist (created by
 * sql/run_route_subaddress.php as `kind VARCHAR(16) NOT NULL DEFAULT 'text'`),
 * so there is NO destructive change here — this script only:
 *
 *   1. Ensures `zello_outbox` exists (delegates to run_route_subaddress.php
 *      if it's missing on a fresh install).
 *   2. Confirms `zello_outbox.kind` is present and at least VARCHAR(16) wide
 *      so the literal 'tts' fits; widens it if an older hand-rolled table made
 *      it narrower (idempotent, guarded).
 *
 * It does NOT seed the zello_tts_* settings (zello_tts_piper_bin,
 * zello_tts_piper_voice, zello_tts_ffmpeg_bin, zello_tts_sample_rate,
 * zello_tts_frame_ms). Those are admin-managed and the proxy degrades
 * gracefully when they're unset (reports "TTS not configured" and fails the
 * row rather than crashing). settings.php owns that surface — not touched here.
 *
 * Safe to re-run.
 */
require_once __DIR__ . '/../config.php';

echo "Gap 1 — Zello TTS broadcast audio (zello_outbox.kind check)\n";
echo "==========================================================\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

// Guard declarations so the script is safe to require more than once in a
// single process (e.g. a test that re-runs it to prove idempotency).
if (!function_exists('_ztts_table_exists')) {
    function _ztts_table_exists(string $table): bool
    {
        global $prefix;
        return (bool) db_fetch_value(
            "SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
            [$prefix . $table]
        );
    }
}

if (!function_exists('_ztts_col')) {
    function _ztts_col(string $table, string $col): ?array
    {
        global $prefix;
        $row = db_fetch_one(
            "SELECT COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = ? AND COLUMN_NAME = ?",
            [$prefix . $table, $col]
        );
        return $row ?: null;
    }
}

// ── 1. Ensure zello_outbox exists (Phase D creates it). ──
if (!_ztts_table_exists('zello_outbox')) {
    echo "[..] zello_outbox missing — running sql/run_route_subaddress.php to create it\n";
    ob_start();
    try {
        require __DIR__ . '/run_route_subaddress.php';
    } catch (Throwable $e) {
        ob_end_clean();
        echo "[FAIL] could not create zello_outbox: " . $e->getMessage() . "\n";
        exit(1);
    }
    ob_end_clean();
}

if (!_ztts_table_exists('zello_outbox')) {
    echo "[FAIL] zello_outbox still missing after migration attempt\n";
    exit(1);
}
echo "[OK] zello_outbox table present\n";

// ── 2. Confirm + (if needed) widen zello_outbox.kind so 'tts' fits. ──
$kind = _ztts_col('zello_outbox', 'kind');
if ($kind === null) {
    // Extremely unlikely (Phase D always adds it), but guard anyway.
    try {
        db_query(
            "ALTER TABLE `{$prefix}zello_outbox`
               ADD COLUMN `kind` VARCHAR(16) NOT NULL DEFAULT 'text'
               COMMENT 'text | tts'"
        );
        echo "[OK] added zello_outbox.kind VARCHAR(16)\n";
    } catch (Exception $e) {
        if (stripos($e->getMessage(), 'duplicate column') !== false) {
            echo "[OK] zello_outbox.kind already present (race)\n";
        } else {
            echo "[FAIL] adding zello_outbox.kind: " . $e->getMessage() . "\n";
            exit(1);
        }
    }
} else {
    $maxLen = $kind['CHARACTER_MAXIMUM_LENGTH'];
    if ($maxLen !== null && (int) $maxLen < 16) {
        try {
            db_query(
                "ALTER TABLE `{$prefix}zello_outbox`
                   MODIFY COLUMN `kind` VARCHAR(16) NOT NULL DEFAULT 'text'
                   COMMENT 'text | tts'"
            );
            echo "[OK] widened zello_outbox.kind to VARCHAR(16) (was {$maxLen})\n";
        } catch (Exception $e) {
            echo "[FAIL] widening zello_outbox.kind: " . $e->getMessage() . "\n";
            exit(1);
        }
    } else {
        echo "[OK] zello_outbox.kind is " . ($kind['DATA_TYPE'] ?? '?')
            . "(" . ($maxLen ?? '?') . ") — 'tts' fits\n";
    }
}

echo "\nDone. The 'tts' outbox path is schema-ready.\n";
echo "NOTE: install Piper + a voice model on the PROXY host and set the\n";
echo "      zello_tts_piper_bin / zello_tts_piper_voice settings before\n";
echo "      'Speak on channel' can produce audio (ffmpeg is already present).\n";
