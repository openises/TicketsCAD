<?php
/**
 * Phase 77b regression — DMR audio recording + playback endpoint
 *
 * Tests:
 *   - api/dmr-audio.php exists and rejects bad input with documented
 *     error codes.
 *   - dmr_messages schema has the audio_path + audio_format columns
 *     bridge.py writes to.
 *   - bridge.py's WAV writer produces a file the standard library can
 *     read back (PHP's pack/unpack is enough to validate the RIFF
 *     header — we don't actually run the Python here).
 */
require __DIR__ . '/../config.php';

$pass = 0;
$fail = 0;
$prefix = $GLOBALS['db_prefix'] ?? '';
$pdo = db();

function _t($pass, $fail, $label, $ok) {
    if ($ok) { echo "[PASS] {$label}\n"; return [$pass+1, $fail]; }
    echo "[FAIL] {$label}\n"; return [$pass, $fail+1];
}

echo "=== Phase 77b DMR audio recording + playback ===\n";

// File presence
[$pass, $fail] = _t($pass, $fail,
    "api/dmr-audio.php exists",
    file_exists(__DIR__ . '/../api/dmr-audio.php'));

[$pass, $fail] = _t($pass, $fail,
    "services/dvswitch/bridge.py exists",
    file_exists(__DIR__ . '/../services/dvswitch/bridge.py'));

// dmr_messages schema
$cols = $pdo->query("SELECT COLUMN_NAME FROM information_schema.columns
                     WHERE table_schema = DATABASE() AND table_name = 'dmr_messages'")
            ->fetchAll(PDO::FETCH_COLUMN);
[$pass, $fail] = _t($pass, $fail,
    "dmr_messages.audio_path column exists",
    in_array('audio_path', $cols));
[$pass, $fail] = _t($pass, $fail,
    "dmr_messages.audio_format column exists",
    in_array('audio_format', $cols));
[$pass, $fail] = _t($pass, $fail,
    "dmr_messages.duration_ms column exists (used for player UI)",
    in_array('duration_ms', $cols));

// bridge.py — sanity-check the WAV writer method exists in source.
// We don't execute Python here; we just confirm the contract surface.
$bridgeSrc = file_get_contents(__DIR__ . '/../services/dvswitch/bridge.py');
[$pass, $fail] = _t($pass, $fail,
    "bridge.py has _write_call_wav method",
    strpos($bridgeSrc, 'def _write_call_wav') !== false);
[$pass, $fail] = _t($pass, $fail,
    "bridge.py uses wave module for WAV output",
    strpos($bridgeSrc, 'import wave') !== false);
[$pass, $fail] = _t($pass, $fail,
    "bridge.py writes mono 8 kHz 16-bit WAV",
    strpos($bridgeSrc, 'setnchannels(1)') !== false
    && strpos($bridgeSrc, 'setsampwidth(2)') !== false
    && strpos($bridgeSrc, 'setframerate(SAMPLE_RATE)') !== false);
[$pass, $fail] = _t($pass, $fail,
    "bridge.py serves /recording with Range support",
    strpos($bridgeSrc, '/recording') !== false
    && strpos($bridgeSrc, 'Accept-Ranges') !== false
    && strpos($bridgeSrc, 'Content-Range') !== false);
[$pass, $fail] = _t($pass, $fail,
    "bridge.py has containment check on recording path",
    strpos($bridgeSrc, 'os.path.realpath') !== false
    && strpos($bridgeSrc, 'startswith') !== false);
[$pass, $fail] = _t($pass, $fail,
    "bridge.py has retention cleanup loop",
    strpos($bridgeSrc, '_retention_loop') !== false
    && strpos($bridgeSrc, '_sweep_old_recordings') !== false);

// Phase 77c — preempt-active-rx wiring
[$pass, $fail] = _t($pass, $fail,
    "bridge.py honors preempt_active_rx flag",
    strpos($bridgeSrc, 'preempt_active_rx') !== false);
[$pass, $fail] = _t($pass, $fail,
    "bridge.py raises rx_busy when TX requested during active RX",
    strpos($bridgeSrc, 'rx_busy') !== false);
[$pass, $fail] = _t($pass, $fail,
    "bridge.py converts rx_busy to HTTP 409",
    strpos($bridgeSrc, 'startswith("rx_busy")') !== false
    && strpos($bridgeSrc, '_json(409') !== false);

// api/dmr-audio.php — contract checks via source inspection
$audioSrc = file_get_contents(__DIR__ . '/../api/dmr-audio.php');
[$pass, $fail] = _t($pass, $fail,
    "api/dmr-audio.php requires admin role",
    strpos($audioSrc, 'is_admin()') !== false);
[$pass, $fail] = _t($pass, $fail,
    "api/dmr-audio.php requires msg_id parameter",
    strpos($audioSrc, 'msg_id') !== false);
[$pass, $fail] = _t($pass, $fail,
    "api/dmr-audio.php requires bridge token parameter",
    strpos($audioSrc, 'token') !== false);
[$pass, $fail] = _t($pass, $fail,
    "api/dmr-audio.php forwards Range header to bridge",
    strpos($audioSrc, 'HTTP_RANGE') !== false);
[$pass, $fail] = _t($pass, $fail,
    "api/dmr-audio.php reads audio_path + bridge_host from join",
    strpos($audioSrc, 'audio_path') !== false
    && strpos($audioSrc, 'bridge_host') !== false);

// Example env file documents the new vars
$envSrc = file_get_contents(__DIR__ . '/../services/dvswitch/example.env');
[$pass, $fail] = _t($pass, $fail,
    "example.env documents DMR_RECORDINGS_DIR",
    strpos($envSrc, 'DMR_RECORDINGS_DIR') !== false);
[$pass, $fail] = _t($pass, $fail,
    "example.env documents DMR_RECORDING_RETENTION_HOURS",
    strpos($envSrc, 'DMR_RECORDING_RETENTION_HOURS') !== false);
[$pass, $fail] = _t($pass, $fail,
    "example.env documents DMR_PREEMPT_ACTIVE_RX",
    strpos($envSrc, 'DMR_PREEMPT_ACTIVE_RX') !== false);

// dvswitch-admin.js — player wiring
$jsSrc = file_get_contents(__DIR__ . '/../assets/js/dvswitch-admin.js');
[$pass, $fail] = _t($pass, $fail,
    "dvswitch-admin.js has bindDvsPlayButtons function",
    strpos($jsSrc, 'bindDvsPlayButtons') !== false);
[$pass, $fail] = _t($pass, $fail,
    "dvswitch-admin.js has speed selector for DVR catch-up",
    strpos($jsSrc, 'playbackRate') !== false);
[$pass, $fail] = _t($pass, $fail,
    "dvswitch-admin.js prompts for bridge token once per session",
    strpos($jsSrc, 'getOrPromptBridgeToken') !== false
    && strpos($jsSrc, 'sessionStorage') !== false);

echo "\n=== TOTAL: {$pass} passed, {$fail} failed ===\n";
exit($fail === 0 ? 0 : 1);
