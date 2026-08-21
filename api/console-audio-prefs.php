<?php
/**
 * Phase 114b3 — per-user console audio/selection state API.
 *
 * GET                          -> {ok:true, state:{channels:{...}}}
 * POST {channels:{...}, csrf_token} -> save, echoes the cleaned state back
 *
 * screen.console only (not action.console_tx) — see inc/console-audio-
 * prefs.php's docblock for why a listen-only operator still gets this.
 */
ini_set('display_errors', '0');

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/functions.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/screen-prefs.php';
require_once __DIR__ . '/../inc/console-audio-prefs.php';

if (!rbac_can('screen.console')) {
    json_error('Forbidden', 403);
}

$uid = (int) ($_SESSION['user_id'] ?? 0);
if ($uid <= 0) {
    json_error('Auth required', 401);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        json_response(['ok' => true, 'state' => console_audio_prefs_get($uid)]);
    } catch (Exception $e) {
        json_error_safe('Failed to load console audio prefs', $e);
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed', 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
if (!csrf_verify($input['csrf_token'] ?? '')) {
    json_error('Invalid CSRF token', 403);
}

try {
    $r = console_audio_prefs_save($uid, $input);
    if (!$r['ok']) { json_error('Save failed', 500); }
    json_response(['ok' => true, 'state' => $r['state']]);
} catch (Exception $e) {
    json_error_safe('Save failed', $e);
}
