<?php
/**
 * Phase 113 — Voice & Speech admin API.
 *
 * GET  ?action=list          → engines + applications + driver catalog
 * GET  ?action=test&...      → (see POST test) — POST preferred
 * POST action=save_engine    → create/update an engine (+ optional API key)
 * POST action=delete_engine  → remove an engine (apps fall back to Piper)
 * POST action=save_application → set engine/voice/rate/fallback for an app
 * POST action=test           → synthesize a sample, return WAV (base64) to play
 *
 * RBAC: action.manage_tts (Super/Org Admin). CSRF on writes. Every change
 * audit-logged under 'tts'. API keys are written to ../keys/tts/<file> (0640,
 * outside the webroot) — never stored in the DB or returned to the browser.
 */

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/audit.php';
require_once __DIR__ . '/../inc/tts/engine.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

function _tts_admin(): bool
{
    return (function_exists('is_admin') && is_admin())
        || (function_exists('rbac_can') && rbac_can('action.manage_tts'));
}
if (!_tts_admin()) { json_error('Insufficient permissions: manage_tts', 403); }

/** Driver catalog — what the UI offers + which config fields each needs. */
function _tts_drivers(): array
{
    return [
        'piper' => [
            'label'  => 'Piper (self-hosted, offline)',
            'fields' => ['bin', 'voice', 'native_rate', 'ffmpeg'],
            'needs_key' => false,
        ],
        'openai_compat' => [
            'label'  => 'OpenAI-compatible (OpenAI · Groq · Kokoro · Chatterbox · …)',
            'fields' => ['endpoint', 'model', 'voice', 'in_rate', 'ffmpeg'],
            'needs_key' => true,
        ],
        'deepgram' => [
            'label'  => 'Deepgram Aura (hosted, telephony-native, best free tier)',
            'fields' => ['voice', 'encoding'],
            'needs_key' => true,
        ],
    ];
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $method === 'GET' ? (string) ($_GET['action'] ?? 'list') : '';

// ── GET: list ────────────────────────────────────────────────────────────────
if ($method === 'GET' && $action === 'list') {
    try {
        $engines = db_fetch_all(
            "SELECT id, engine_key, driver, label, config_json, enabled, last_ok_at, last_error, sort_order
             FROM `{$prefix}tts_engines` ORDER BY sort_order, id"
        );
        foreach ($engines as &$e) {
            $cfg = json_decode((string) ($e['config_json'] ?? ''), true) ?: [];
            // Never leak a key; report only whether one is stored.
            $e['has_key'] = !empty($cfg['key_ref']) && tts_read_key($cfg['key_ref']) !== '';
            unset($cfg['key_ref']);
            $e['config'] = $cfg;
            unset($e['config_json']);
            $e['id'] = (int) $e['id'];
            $e['enabled'] = (int) $e['enabled'];
        }
        unset($e);
        $apps = db_fetch_all(
            "SELECT id, app_key, label, engine_id, voice, rate, fallback_engine_id, sort_order
             FROM `{$prefix}tts_applications` ORDER BY sort_order, id"
        );
        foreach ($apps as &$a) {
            $a['id']   = (int) $a['id'];
            $a['rate'] = (int) $a['rate'];
            $a['engine_id']          = $a['engine_id'] !== null ? (int) $a['engine_id'] : null;
            $a['fallback_engine_id'] = $a['fallback_engine_id'] !== null ? (int) $a['fallback_engine_id'] : null;
        }
        unset($a);
        json_response(['success' => true, 'engines' => $engines, 'applications' => $apps,
                       'drivers' => _tts_drivers()]);
    } catch (Throwable $e) {
        json_error_safe('List failed', $e, 'tts-list', 500);
    }
}

// ── POST: writes ─────────────────────────────────────────────────────────────
if ($method !== 'POST') { json_error('Method not allowed', 405); }

$input = json_decode(file_get_contents('php://input'), true) ?: [];
if (empty($input['csrf_token']) || !csrf_verify((string) $input['csrf_token'])) {
    json_error('Invalid CSRF token', 403);
}
$action = (string) ($input['action'] ?? '');

/** Persist an API key to ../keys/tts/<file> (0640). Returns the basename. */
function _tts_write_key(string $engineKey, string $key): string
{
    $dir = tts_keys_dir();
    if (!is_dir($dir)) { @mkdir($dir, 0750, true); }
    $file = preg_replace('/[^a-z0-9_\-]/i', '_', $engineKey) . '.key';
    $path = $dir . '/' . $file;
    file_put_contents($path, trim($key));
    @chmod($path, 0640);
    return $file;
}

switch ($action) {
    case 'save_engine': {
        $id        = (int) ($input['id'] ?? 0);
        $engineKey = trim((string) ($input['engine_key'] ?? ''));
        $driver    = trim((string) ($input['driver'] ?? ''));
        $label     = trim((string) ($input['label'] ?? ''));
        $enabled   = !empty($input['enabled']) ? 1 : 0;
        $cfgIn     = is_array($input['config'] ?? null) ? $input['config'] : [];
        $drivers   = _tts_drivers();
        if ($engineKey === '' || !isset($drivers[$driver])) {
            json_error('engine_key + a valid driver are required');
        }
        // Whitelist config keys to the driver's declared fields.
        $cfg = [];
        foreach ($drivers[$driver]['fields'] as $f) {
            if (isset($cfgIn[$f]) && $cfgIn[$f] !== '') $cfg[$f] = is_numeric($cfgIn[$f]) ? $cfgIn[$f] + 0 : (string) $cfgIn[$f];
        }
        try {
            // Preserve an existing key_ref unless a new key is supplied.
            if ($id > 0) {
                $existing = tts_get_engine($id);
                if ($existing && !empty($existing['config']['key_ref'])) {
                    $cfg['key_ref'] = $existing['config']['key_ref'];
                }
            }
            if (!empty($input['api_key'])) {
                $cfg['key_ref'] = _tts_write_key($engineKey, (string) $input['api_key']);
            }
            $cfgJson = json_encode($cfg, JSON_UNESCAPED_SLASHES);
            if ($id > 0) {
                db_query("UPDATE `{$prefix}tts_engines`
                          SET engine_key=?, driver=?, label=?, config_json=?, enabled=? WHERE id=?",
                    [$engineKey, $driver, $label, $cfgJson, $enabled, $id]);
            } else {
                db_query("INSERT INTO `{$prefix}tts_engines` (engine_key, driver, label, config_json, enabled, sort_order)
                          VALUES (?, ?, ?, ?, ?, 100)",
                    [$engineKey, $driver, $label, $cfgJson, $enabled]);
                $id = (int) db_insert_id();
            }
            audit_log('tts', 'save_engine', 'tts_engine', $id, "Saved TTS engine {$engineKey} ({$driver})",
                ['engine_key' => $engineKey, 'driver' => $driver, 'has_key' => !empty($cfg['key_ref'])]);
            json_response(['success' => true, 'id' => $id]);
        } catch (Throwable $e) {
            json_error_safe('Save failed', $e, 'tts-save-engine', 500);
        }
        break;
    }

    case 'delete_engine': {
        $id = (int) ($input['id'] ?? 0);
        if ($id <= 0) json_error('id required');
        try {
            $eng = tts_get_engine($id);
            if ($eng && ($eng['engine_key'] ?? '') === 'piper-default') {
                json_error('The default Piper engine cannot be deleted (it is the fallback)');
            }
            // Applications referencing it fall back to Piper (engine_id NULL).
            db_query("UPDATE `{$prefix}tts_applications` SET engine_id = NULL WHERE engine_id = ?", [$id]);
            db_query("UPDATE `{$prefix}tts_applications` SET fallback_engine_id = NULL WHERE fallback_engine_id = ?", [$id]);
            db_query("DELETE FROM `{$prefix}tts_engines` WHERE id = ?", [$id]);
            audit_log('tts', 'delete_engine', 'tts_engine', $id, 'Deleted TTS engine #' . $id, null);
            json_response(['success' => true]);
        } catch (Throwable $e) {
            json_error_safe('Delete failed', $e, 'tts-delete-engine', 500);
        }
        break;
    }

    case 'save_application': {
        $appKey   = trim((string) ($input['app_key'] ?? ''));
        if ($appKey === '') json_error('app_key required');
        $engineId = isset($input['engine_id']) && $input['engine_id'] !== '' ? (int) $input['engine_id'] : null;
        $fallback = isset($input['fallback_engine_id']) && $input['fallback_engine_id'] !== '' ? (int) $input['fallback_engine_id'] : null;
        $voice    = isset($input['voice']) ? trim((string) $input['voice']) : '';
        $rate     = (int) ($input['rate'] ?? 8000);
        if ($rate < 4000 || $rate > 48000) $rate = 8000;
        try {
            db_query("UPDATE `{$prefix}tts_applications`
                      SET engine_id=?, voice=?, rate=?, fallback_engine_id=? WHERE app_key=?",
                [$engineId, ($voice !== '' ? $voice : null), $rate, $fallback, $appKey]);
            audit_log('tts', 'save_application', 'tts_application', null,
                "Routed speech application {$appKey}", ['app_key' => $appKey, 'engine_id' => $engineId, 'rate' => $rate]);
            json_response(['success' => true]);
        } catch (Throwable $e) {
            json_error_safe('Save failed', $e, 'tts-save-app', 500);
        }
        break;
    }

    case 'test': {
        // Synthesize a short sample so the admin can HEAR the engine before
        // committing it. Prefer an explicit engine_id; else the 'test' app.
        $text = trim((string) ($input['text'] ?? ''));
        if ($text === '') $text = 'This is a TicketsCAD voice test. Severe thunderstorm warning for your area.';
        $text = mb_substr($text, 0, 300);
        $rate = (int) ($input['rate'] ?? 22050);
        if ($rate < 4000 || $rate > 48000) $rate = 22050;
        $opts = ['rate' => $rate];
        if (!empty($input['engine_id'])) $opts['engine_id'] = (int) $input['engine_id'];
        if (!empty($input['voice']))     $opts['voice']     = (string) $input['voice'];
        try {
            $r = tts_synthesize('test', $text, $opts);
            if (!$r['ok']) {
                json_response(['success' => false, 'error' => $r['detail'], 'failovers' => $r['failovers']]);
            }
            $wav = tts_pcm_to_wav($r['pcm'], (int) $r['rate']);
            audit_log('tts', 'test', 'tts_engine', (int) ($input['engine_id'] ?? 0),
                'TTS test-listen via ' . $r['engine'], ['engine' => $r['engine'], 'bytes' => strlen($wav)]);
            json_response([
                'success'   => true,
                'engine'    => $r['engine'],
                'rate'      => (int) $r['rate'],
                'failovers' => $r['failovers'],
                'audio'     => 'data:audio/wav;base64,' . base64_encode($wav),
            ]);
        } catch (Throwable $e) {
            json_error_safe('Test failed', $e, 'tts-test', 500);
        }
        break;
    }

    default:
        json_error('Unknown action: ' . $action);
}
