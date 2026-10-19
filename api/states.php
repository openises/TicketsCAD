<?php
/**
 * GH#153 — State / Province list administration (Settings → States / Provinces).
 *
 * GET  ?action=list            the list + table health + current field label
 * GET  ?action=presets         the built-in presets and quick picks
 * GET  ?action=usage&codes=A,B how many saved records hold each code
 * GET  ?action=export          the current list as a CSV download
 * GET  ?action=preset_csv&selection={json}  a preset selection as a CSV download
 * POST action=create           {code, name}
 * POST action=update           {id, code, name}
 * POST action=delete           {id}
 * POST action=preset_preview   {selection, mode}
 * POST action=preset_apply     {selection, mode, list_hash}
 * POST action=import_preview   {csv, mode}
 * POST action=import_apply     {csv, mode, list_hash}
 * POST action=set_label        {label, lang}
 *
 * RBAC: the WHOLE endpoint needs action.manage_config — the same permission
 * states-admin.php opens with (a page gate and its API gate must name the
 * same permission). Deliberately not `|| is_admin()`: is_admin() is itself
 * "is_super OR action.manage_config", so the fallback adds nothing but the
 * Phase 138 trap. Reading the list for the dropdowns is NOT here — every
 * signed-in user still gets it from api/incident-types.php.
 *
 * Every POST is CSRF-checked before the action is even looked at. The library
 * (inc/states.php) audits every change. Bodies are JSON (php://input).
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/audit.php';
require_once __DIR__ . '/../inc/i18n.php';
require_once __DIR__ . '/../inc/states.php';
ini_set('display_errors', '0');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if (!rbac_can('action.manage_config')) {
    json_error('Insufficient permissions: managing the State / Province list needs the "Manage configuration" permission', 403);
}

/** Send a CSV download and stop. */
function st_send_csv(string $filename, string $csv): void
{
    $GLOBALS['__api_guard_body_sent'] = true;
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    echo $csv;
    exit;
}

/** The {presetId: [optionKeys]} object a client sent, or a 400. */
function st_selection($raw): array
{
    if (is_string($raw)) {
        $raw = json_decode($raw, true);
    }
    if (!is_array($raw)) {
        json_error('Choose at least one list.');
    }
    $sel = [];
    foreach ($raw as $pid => $keys) {
        $sel[(string) $pid] = is_array($keys) ? array_values(array_map('strval', $keys)) : $keys;
    }
    return $sel;
}

function st_mode($raw): string
{
    $m = (string) $raw;
    if ($m !== 'replace' && $m !== 'add') {
        json_error('Choose whether to replace the list or add to it.');
    }
    return $m;
}

if ($method === 'GET') {
    $action = (string) ($_GET['action'] ?? 'list');

    if ($action === 'list') {
        try {
            $lang = i18n_lang();
            json_response([
                'states'      => states_list(),
                'count'       => states_count(),
                'max_rows'    => STATES_MAX_ROWS,
                'schema'      => states_schema_status(),
                'field_label' => states_field_label($lang),
                'label_lang'  => $lang,
                'languages'   => i18n_available_langs(),
                'customized'  => states_is_customized(),
            ]);
        } catch (Throwable $e) {
            json_error_safe('Could not read the State / Province list.', $e, 'states.list');
        }
    }

    if ($action === 'presets') {
        json_response(states_preset_catalog());
    }

    if ($action === 'usage') {
        $codes = array_filter(array_map('trim', explode(',', (string) ($_GET['codes'] ?? ''))), 'strlen');
        if (count($codes) > 500) {
            json_error('Too many codes in one request.');
        }
        try {
            json_response(['usage' => states_usage($codes)]);
        } catch (Throwable $e) {
            json_error_safe('Could not count the records that use these codes.', $e, 'states.usage');
        }
    }

    if ($action === 'export') {
        try {
            $rows = states_list();
        } catch (Throwable $e) {
            json_error_safe('Could not read the State / Province list.', $e, 'states.export');
        }
        audit_log('config', 'export', 'state_province', null, 'Exported the State / Province list (' . count($rows) . ' entries) as CSV');
        st_send_csv('states-provinces-' . date('Y-m-d') . '.csv', states_csv_build($rows));
    }

    if ($action === 'preset_csv') {
        try {
            if (isset($_GET['preset'])) {
                $sel = states_default_selection((string) $_GET['preset']);
            } else {
                $sel = st_selection($_GET['selection'] ?? '');
            }
            $built = states_build_selection($sel);
        } catch (InvalidArgumentException $e) {
            json_error($e->getMessage());
        }
        st_send_csv('states-preset-' . implode('-', array_keys($sel)) . '.csv', states_csv_build($built['rows']));
    }

    json_error('Unknown action', 404);
}

if ($method !== 'POST') {
    json_error('Method not allowed', 405);
}

$input = json_decode((string) file_get_contents('php://input'), true);
$input = is_array($input) ? $input : [];
if (empty($input['csrf_token']) || !csrf_verify((string) $input['csrf_token'])) {
    json_error('Invalid or expired security token. Please refresh the page.', 403);
}
$action = (string) ($input['action'] ?? '');

try {
    switch ($action) {
        case 'create':
            json_response(['ok' => true, 'state' => states_create((string) ($input['code'] ?? ''), (string) ($input['name'] ?? ''))]);
            break;

        case 'update':
            $r = states_update((int) ($input['id'] ?? 0), (string) ($input['code'] ?? ''), (string) ($input['name'] ?? ''));
            json_response(['ok' => true] + $r);
            break;

        case 'delete':
            json_response(['ok' => true] + states_delete((int) ($input['id'] ?? 0)));
            break;

        case 'preset_preview':
            $built = states_build_selection(st_selection($input['selection'] ?? null));
            $p = states_preview($built['rows'], st_mode($input['mode'] ?? ''));
            $p['conflicts'] = $built['conflicts'];
            $p['sources'] = $built['sources'];
            $p['label_suggestion'] = $built['label_suggestion'];
            $p['target_count'] = count($built['rows']);
            json_response($p);
            break;

        case 'preset_apply':
            $sel = st_selection($input['selection'] ?? null);
            $built = states_build_selection($sel);
            $source = 'presets ' . implode(', ', array_map(static function ($pid, $keys) {
                return $pid . ($keys ? ' (+' . implode(', ', $keys) . ')' : '');
            }, array_keys($sel), $sel));
            $r = states_apply($built['rows'], st_mode($input['mode'] ?? ''), (string) ($input['list_hash'] ?? ''), $source, 'update');
            $r['label_suggestion'] = $built['label_suggestion'];
            json_response(['ok' => true] + $r);
            break;

        case 'import_preview':
            $parsed = states_csv_parse((string) ($input['csv'] ?? ''));
            $mode = st_mode($input['mode'] ?? '');
            if ($parsed['errors']) {
                json_response(['ok' => false, 'errors' => $parsed['errors'], 'row_count' => count($parsed['rows'])]);
            }
            $p = states_preview($parsed['rows'], $mode);
            $p['ok'] = true;
            $p['errors'] = [];
            $p['target_count'] = count($parsed['rows']);
            json_response($p);
            break;

        case 'import_apply':
            $parsed = states_csv_parse((string) ($input['csv'] ?? ''));
            $mode = st_mode($input['mode'] ?? '');
            if ($parsed['errors']) {
                json_response(['ok' => false, 'errors' => $parsed['errors']], 400);
            }
            $r = states_apply($parsed['rows'], $mode, (string) ($input['list_hash'] ?? ''), 'a CSV import (' . count($parsed['rows']) . ' rows)', 'import');
            json_response(['ok' => true] + $r);
            break;

        case 'set_label':
            $r = states_set_field_label((string) ($input['label'] ?? ''), (string) ($input['lang'] ?? i18n_lang()));
            json_response(['ok' => true] + $r);
            break;

        default:
            json_error('Unknown action', 404);
    }
} catch (InvalidArgumentException $e) {
    json_error($e->getMessage());
} catch (StatesStaleException $e) {
    json_error($e->getMessage(), 409);
} catch (StatesNotFoundException $e) {
    json_error($e->getMessage(), 404);
} catch (Throwable $e) {
    json_error_safe('Saving the State / Province list failed. The list was not changed.', $e, 'states.' . $action);
}
