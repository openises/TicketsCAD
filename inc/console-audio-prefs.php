<?php
/**
 * Phase 114b3 — per-user console audio/selection state (select, monitor,
 * mute, volume, simulselect membership), persisted per operator so it
 * survives navigation/refresh — matches console-designer.md §4's own
 * design note: "Per-user runtime state (volume, mute, selected) is
 * screen-prefs, NOT layout."
 *
 * Stored via the existing user_screen_prefs table (inc/screen-prefs.php),
 * screen key 'console-audio', through prefs_get_raw()/prefs_set() — NOT
 * prefs_get()'s columns/sort/options envelope, which only carries scalar
 * 'options' values across reads and would silently drop this nested
 * per-channel shape.
 *
 * Shape: {"channels": {"<channel_id>": {"selected":bool,"muted":bool,
 *          "volume":0-100,"simulselect":bool}, ...}}
 *
 * Gated on screen.console only (not action.console_tx) — even a
 * listen-only operator gets to control their own listening mix.
 */

const CONSOLE_AUDIO_PREFS_SCREEN = 'console-audio';
const CONSOLE_AUDIO_MAX_CHANNELS = 300; // generous ceiling, matches console_view_save_strips()'s own 64-per-view cap headroom

/** Default per-channel state — chosen so an untouched channel behaves EXACTLY like today (gain 1.0, see console-audio-logic.js). */
function console_audio_default_channel_state() {
    return ['selected' => false, 'mon' => true, 'muted' => false, 'volume' => 100, 'simulselect' => false];
}

/** Validate + clamp a raw client payload into the canonical stored shape. Never throws. */
function console_audio_prefs_clean($input) {
    $out = ['channels' => []];
    $channels = is_array($input['channels'] ?? null) ? $input['channels'] : [];
    $n = 0;
    foreach ($channels as $chId => $state) {
        if ($n >= CONSOLE_AUDIO_MAX_CHANNELS) { break; }
        $id = (int) $chId;
        if ($id <= 0 || !is_array($state)) { continue; }
        $vol = isset($state['volume']) ? (int) $state['volume'] : 100;
        if ($vol < 0) { $vol = 0; }
        if ($vol > 100) { $vol = 100; }
        $out['channels'][(string) $id] = [
            'selected'    => !empty($state['selected']),
            'mon'         => array_key_exists('mon', $state) ? !empty($state['mon']) : true,
            'muted'       => !empty($state['muted']),
            'volume'      => $vol,
            'simulselect' => !empty($state['simulselect']),
        ];
        $n++;
    }
    return $out;
}

function console_audio_prefs_get($userId) {
    $raw = prefs_get_raw((int) $userId, CONSOLE_AUDIO_PREFS_SCREEN);
    return console_audio_prefs_clean($raw);
}

function console_audio_prefs_save($userId, $input) {
    $clean = console_audio_prefs_clean($input);
    $ok = prefs_set((int) $userId, CONSOLE_AUDIO_PREFS_SCREEN, $clean);
    return ['ok' => $ok, 'state' => $clean];
}
