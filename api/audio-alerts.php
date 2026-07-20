<?php
/**
 * Phase 41 — Custom audio tones (composer + per-event overrides).
 *
 *   GET  ?action=list                        → custom tones + event overrides
 *   POST ?action=save_tone                   → name, notes[], gap, type
 *   POST ?action=delete_tone (name)          → remove custom tone (also clears overrides)
 *   POST ?action=assign (event_key, tone)    → wire event_key → tone (built-in or custom)
 *   POST ?action=clear_assignment (event_key)→ drop the override (revert to built-in)
 *
 * The tones live in the settings row `audio_alert_custom_tones` (JSON map of
 * name → tone definition). Per-event overrides live in
 * `audio_alert_event_overrides` (JSON map of event_key → tone name).
 *
 * RBAC: action.manage_config for every write; reads are open to anyone the
 * navbar audio-alerts module would already load this for.
 */
ini_set('display_errors', '0');
header('Content-Type: application/json');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/functions.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

$input = [];
if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $input = $raw ? (json_decode($raw, true) ?: []) : $_POST;
    if (!$action && !empty($input['action'])) $action = $input['action'];
}

function _read_json_setting(string $key, $default = []) {
    $v = get_variable($key);
    if ($v === false || $v === '' || $v === null) return $default;
    $d = json_decode((string) $v, true);
    return is_array($d) ? $d : $default;
}

function _write_json_setting(string $key, array $value): void {
    global $prefix;
    $json = json_encode($value, JSON_UNESCAPED_SLASHES);
    // Upsert into settings (legacy schema uses `name`).
    db_query(
        "INSERT INTO `{$prefix}settings` (name, value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE value = VALUES(value)",
        [$key, $json]
    );
}

function _require_manage_config(): void {
    if (!rbac_can('action.manage_config')) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden — requires action.manage_config']);
        exit;
    }
}

// Event keys the audio-alerts module already understands; the UI offers these
// in the override dropdown so admins don't have to type magic strings.
$KNOWN_EVENTS = ['newIncident','highSeverity','unitAssigned','chatMessage','statusChange','parOverdue','broadcast'];
$KNOWN_TYPES  = ['sine','square','triangle','sawtooth'];

if ($action === 'list' && $method === 'GET') {
    json_response([
        'custom_tones'    => _read_json_setting('audio_alert_custom_tones', []),
        'event_overrides' => _read_json_setting('audio_alert_event_overrides', []),
        'known_events'    => $KNOWN_EVENTS,
        'known_wave_types'=> $KNOWN_TYPES,
    ]);
}

if ($action === 'save_tone' && $method === 'POST') {
    _require_manage_config();
    if (empty($input['csrf_token']) || !csrf_verify($input['csrf_token'])) {
        json_error('Invalid CSRF token', 403);
    }
    $name = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($input['name'] ?? ''));
    if ($name === '' || strlen($name) > 32) json_error('name required (a-z, 0-9, _, -; 1-32 chars)');
    $notes = $input['notes'] ?? [];
    if (!is_array($notes) || count($notes) === 0 || count($notes) > 64) {
        json_error('notes must be an array of 1-64 entries');
    }
    $clean = [];
    foreach ($notes as $n) {
        $hz  = (float) ($n['hz'] ?? 0);
        $dur = (int)   ($n['dur'] ?? 0);
        if ($hz < 20 || $hz > 8000) json_error('hz must be 20-8000');
        if ($dur < 20 || $dur > 2000) json_error('dur must be 20-2000ms');
        $clean[] = ['hz' => $hz, 'dur' => $dur];
    }
    $gap  = max(0, min(1000, (int) ($input['gap'] ?? 40)));
    $type = in_array((string) ($input['type'] ?? 'sine'), $KNOWN_TYPES, true) ? $input['type'] : 'sine';

    $all = _read_json_setting('audio_alert_custom_tones', []);
    $all[$name] = ['notes' => $clean, 'gap' => $gap, 'type' => $type];
    _write_json_setting('audio_alert_custom_tones', $all);
    json_response(['ok' => true, 'name' => $name, 'tone' => $all[$name]]);
}

if ($action === 'delete_tone' && $method === 'POST') {
    _require_manage_config();
    if (empty($input['csrf_token']) || !csrf_verify($input['csrf_token'])) {
        json_error('Invalid CSRF token', 403);
    }
    $name = (string) ($input['name'] ?? '');
    if ($name === '') json_error('name required');

    $all = _read_json_setting('audio_alert_custom_tones', []);
    if (!isset($all[$name])) json_error('tone not found', 404);
    unset($all[$name]);
    _write_json_setting('audio_alert_custom_tones', $all);

    // Drop any event override pointing at this tone.
    $ov = _read_json_setting('audio_alert_event_overrides', []);
    $changed = false;
    foreach ($ov as $k => $v) {
        if ($v === $name) { unset($ov[$k]); $changed = true; }
    }
    if ($changed) _write_json_setting('audio_alert_event_overrides', $ov);
    json_response(['ok' => true]);
}

if ($action === 'assign' && $method === 'POST') {
    _require_manage_config();
    if (empty($input['csrf_token']) || !csrf_verify($input['csrf_token'])) {
        json_error('Invalid CSRF token', 403);
    }
    $event = (string) ($input['event_key'] ?? '');
    $tone  = (string) ($input['tone'] ?? '');
    if (!in_array($event, $KNOWN_EVENTS, true)) json_error('unknown event_key');
    if ($tone === '') json_error('tone required');

    // Validate tone exists: either a built-in name or a saved custom name.
    $builtins = $KNOWN_EVENTS; // built-in tones are 1:1 with the event keys
    $custom = _read_json_setting('audio_alert_custom_tones', []);
    if (!in_array($tone, $builtins, true) && !isset($custom[$tone])) {
        json_error('tone not known (built-in or saved-custom only)', 404);
    }
    $ov = _read_json_setting('audio_alert_event_overrides', []);
    $ov[$event] = $tone;
    _write_json_setting('audio_alert_event_overrides', $ov);
    json_response(['ok' => true, 'event_key' => $event, 'tone' => $tone]);
}

if ($action === 'clear_assignment' && $method === 'POST') {
    _require_manage_config();
    if (empty($input['csrf_token']) || !csrf_verify($input['csrf_token'])) {
        json_error('Invalid CSRF token', 403);
    }
    $event = (string) ($input['event_key'] ?? '');
    if (!in_array($event, $KNOWN_EVENTS, true)) json_error('unknown event_key');
    $ov = _read_json_setting('audio_alert_event_overrides', []);
    if (isset($ov[$event])) {
        unset($ov[$event]);
        _write_json_setting('audio_alert_event_overrides', $ov);
    }
    json_response(['ok' => true]);
}

json_error('Unknown action: ' . $action);
