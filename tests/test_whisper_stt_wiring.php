<?php
/**
 * Phase 80a regression — faster-whisper STT integration into bridge.py
 *
 * Doesn't run Python here; checks the contract surface so future
 * refactors that drop the wiring fail the suite.
 */

$pass = 0;
$fail = 0;

function _t($pass, $fail, $label, $ok) {
    if ($ok) { echo "[PASS] {$label}\n"; return [$pass+1, $fail]; }
    echo "[FAIL] {$label}\n"; return [$pass, $fail+1];
}

$src = file_get_contents(__DIR__ . '/../services/dvswitch/bridge.py');
$env = file_get_contents(__DIR__ . '/../services/dvswitch/example.env');

echo "=== Phase 80a faster-whisper STT wiring ===\n";

[$pass, $fail] = _t($pass, $fail,
    "bridge.py defines WhisperSTT class",
    strpos($src, 'class WhisperSTT') !== false);
[$pass, $fail] = _t($pass, $fail,
    "WhisperSTT lazy-imports faster_whisper",
    strpos($src, 'from faster_whisper import WhisperModel') !== false);
[$pass, $fail] = _t($pass, $fail,
    "WhisperSTT supports int8 compute_type for low memory",
    strpos($src, "compute_type: str = \"int8\"") !== false
    || strpos($src, "compute_type='int8'") !== false
    || strpos($src, "compute_type=\"int8\"") !== false);
[$pass, $fail] = _t($pass, $fail,
    "WhisperSTT.transcribe accepts pre-upsampled 16kHz blob",
    strpos($src, 'pcm_16k: Optional[bytes] = None') !== false);
[$pass, $fail] = _t($pass, $fail,
    "DVSwitchBridge constructor accepts whisper_stt kwarg",
    strpos($src, 'whisper_stt: Optional["WhisperSTT"] = None') !== false);
[$pass, $fail] = _t($pass, $fail,
    "_finalize_call runs whisper after vosk if configured",
    strpos($src, 'self.whisper_stt and pcm') !== false);
[$pass, $fail] = _t($pass, $fail,
    "whisper override preserves vosk output in partials",
    strpos($src, '"[vosk] " + vosk_text') !== false);
[$pass, $fail] = _t($pass, $fail,
    "whisper failure logs whisper_error but doesn't clobber vosk transcript",
    strpos($src, 'whisper_error') !== false);
[$pass, $fail] = _t($pass, $fail,
    "main() reads DMR_WHISPER_MODEL env var",
    strpos($src, 'DMR_WHISPER_MODEL') !== false);
[$pass, $fail] = _t($pass, $fail,
    "main() reads DMR_WHISPER_COMPUTE env var",
    strpos($src, 'DMR_WHISPER_COMPUTE') !== false);
[$pass, $fail] = _t($pass, $fail,
    "example.env documents DMR_WHISPER_MODEL",
    strpos($env, 'DMR_WHISPER_MODEL') !== false);
[$pass, $fail] = _t($pass, $fail,
    "example.env documents DMR_WHISPER_COMPUTE",
    strpos($env, 'DMR_WHISPER_COMPUTE') !== false);

echo "\n=== TOTAL: {$pass} passed, {$fail} failed ===\n";
exit($fail === 0 ? 0 : 1);
