<?php
/**
 * NewUI v4.0 API — Languages Registry (Phase 8b i18n)
 *
 * Manages which languages are enabled for the install, their display
 * names, sort order, and which one is the install default.
 *
 *   GET  /api/languages.php
 *     → { languages: [
 *           { code, display_name, native_name, enabled, is_default,
 *             sort_order, caption_count, completeness },
 *           …
 *         ],
 *         total_keys: N,
 *         default: "en"
 *       }
 *
 *   POST action=save        — upsert {code, display_name, native_name?, sort_order?}
 *   POST action=toggle_enabled — {code, enabled: 0|1}; refuses to disable default
 *   POST action=set_default — {code}; lang must be enabled
 *   POST action=delete      — {code}; refuses 'en' and current default;
 *                              cascades to delete all captions_i18n rows for that lang
 *
 * Auth: any logged-in user can GET (the navbar switcher needs the list).
 *       POST requires rbac_can('action.manage_config') OR legacy level <= 1.
 *
 * CSRF: required on every POST.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/audit.php';
require_once __DIR__ . '/../inc/i18n.php';

ini_set('display_errors', '0');

$prefix = $GLOBALS['db_prefix'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
$tbl    = "`{$prefix}languages`";
$capTbl = "`{$prefix}captions_i18n`";

// ─── GET — list languages with completeness ──────────────────────────────
if ($method === 'GET') {
    try {
        $langs = db_fetch_all(
            "SELECT code, display_name, native_name, enabled, is_default,
                    sort_order, created_at, updated_at
             FROM {$tbl} ORDER BY sort_order, code"
        );

        // Total distinct caption keys = denominator for completeness %.
        $totalKeys = (int) db_fetch_value(
            "SELECT COUNT(DISTINCT caption_key) FROM {$capTbl}"
        );

        // Per-lang non-empty caption counts.
        $counts = db_fetch_all(
            "SELECT lang, COUNT(*) AS n
             FROM {$capTbl}
             WHERE value <> ''
             GROUP BY lang"
        );
        $countMap = [];
        foreach ($counts as $c) {
            $countMap[$c['lang']] = (int) $c['n'];
        }

        foreach ($langs as &$l) {
            $l['enabled']       = (int) $l['enabled'];
            $l['is_default']    = (int) $l['is_default'];
            $l['sort_order']    = (int) $l['sort_order'];
            $l['caption_count'] = $countMap[$l['code']] ?? 0;
            $l['completeness']  = $totalKeys > 0
                ? round($l['caption_count'] * 100.0 / $totalKeys, 1)
                : 0.0;
        }
        unset($l);

        json_response([
            'languages'  => $langs,
            'total_keys' => $totalKeys,
            'default'    => i18n_default_lang(),
        ]);
    } catch (Exception $e) {
        json_error('Failed to list languages: ' . $e->getMessage(), 500);
    }
}

// ─── POST — write operations ──────────────────────────────────────────────
if ($method !== 'POST') {
    json_error('Method not allowed', 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];

// CSRF
$csrfTok = $input['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!csrf_verify((string) $csrfTok)) {
    json_error('Invalid CSRF token', 403);
}

// RBAC (admins only)
$currLevel = (int) ($_SESSION['level'] ?? 99);
if (!rbac_can('action.manage_config') && $currLevel > 1) {
    json_error('Admin access required', 403);
}

$action = $input['action'] ?? '';
$code   = isset($input['code']) ? strtolower(trim((string) $input['code'])) : '';

// Validate code on actions that need it. Always-applicable:
// 2-8 chars, lowercase alpha+digit+dash.
function _ll_validate_code(string $c): bool
{
    return (bool) preg_match('/^[a-z0-9]{2}([a-z0-9\-]{0,6})$/', $c);
}

// ─── action=save (upsert) ────────────────────────────────────────────────
if ($action === 'save') {
    if (!_ll_validate_code($code)) {
        json_error('Invalid language code; expected 2-8 ASCII chars', 400);
    }
    $display = trim((string) ($input['display_name'] ?? ''));
    $native  = trim((string) ($input['native_name']  ?? ''));
    $sort    = isset($input['sort_order']) ? (int) $input['sort_order'] : 100;
    if ($display === '') {
        $display = strtoupper($code);
    }

    try {
        db_query(
            "INSERT INTO {$tbl} (code, display_name, native_name, enabled, is_default, sort_order)
             VALUES (?, ?, ?, 1, 0, ?)
             ON DUPLICATE KEY UPDATE
                 display_name = VALUES(display_name),
                 native_name  = VALUES(native_name),
                 sort_order   = VALUES(sort_order)",
            [$code, $display, $native, $sort]
        );
        audit_log('config', 'save', 'language', 0, "Saved language '{$code}'");
        json_response(['saved' => true, 'code' => $code]);
    } catch (Exception $e) {
        json_error('Save failed: ' . $e->getMessage(), 500);
    }
}

// ─── action=toggle_enabled ────────────────────────────────────────────────
if ($action === 'toggle_enabled') {
    if (!_ll_validate_code($code)) {
        json_error('Invalid language code', 400);
    }
    $enabled = !empty($input['enabled']) ? 1 : 0;

    try {
        $row = db_fetch_one(
            "SELECT enabled, is_default FROM {$tbl} WHERE code = ? LIMIT 1",
            [$code]
        );
        if (!$row) {
            json_error('Unknown language code', 404);
        }
        if (!$enabled && (int) $row['is_default'] === 1) {
            json_error('Cannot disable the install default language', 409);
        }
        db_query(
            "UPDATE {$tbl} SET enabled = ? WHERE code = ?",
            [$enabled, $code]
        );
        audit_log('config', $enabled ? 'enable' : 'disable', 'language', 0,
            ($enabled ? 'Enabled' : 'Disabled') . " language '{$code}'");
        json_response(['updated' => true, 'code' => $code, 'enabled' => $enabled]);
    } catch (Exception $e) {
        json_error('Toggle failed: ' . $e->getMessage(), 500);
    }
}

// ─── action=set_default ──────────────────────────────────────────────────
if ($action === 'set_default') {
    if (!_ll_validate_code($code)) {
        json_error('Invalid language code', 400);
    }
    try {
        $row = db_fetch_one(
            "SELECT enabled FROM {$tbl} WHERE code = ? LIMIT 1",
            [$code]
        );
        if (!$row) {
            json_error('Unknown language code', 404);
        }
        if ((int) $row['enabled'] !== 1) {
            json_error('Cannot make a disabled language the default — enable it first', 409);
        }
        // Atomic: zero everything, then set the chosen row.
        db_query("UPDATE {$tbl} SET is_default = 0");
        db_query("UPDATE {$tbl} SET is_default = 1 WHERE code = ?", [$code]);
        audit_log('config', 'set_default', 'language', 0, "Install default → '{$code}'");
        json_response(['updated' => true, 'default' => $code]);
    } catch (Exception $e) {
        json_error('Set-default failed: ' . $e->getMessage(), 500);
    }
}

// ─── action=delete ────────────────────────────────────────────────────────
if ($action === 'delete') {
    if (!_ll_validate_code($code)) {
        json_error('Invalid language code', 400);
    }
    if ($code === 'en') {
        json_error('Cannot delete English — it is the engine fallback', 409);
    }
    try {
        $row = db_fetch_one(
            "SELECT is_default FROM {$tbl} WHERE code = ? LIMIT 1",
            [$code]
        );
        if (!$row) {
            json_error('Unknown language code', 404);
        }
        if ((int) $row['is_default'] === 1) {
            json_error('Cannot delete the install default — pick a new default first', 409);
        }

        // Cascade: drop captions_i18n rows for this lang.
        $capDeleted = (int) db_fetch_value(
            "SELECT COUNT(*) FROM {$capTbl} WHERE lang = ?",
            [$code]
        );
        db_query("DELETE FROM {$capTbl} WHERE lang = ?", [$code]);
        db_query("DELETE FROM {$tbl} WHERE code = ?", [$code]);

        // Anyone whose preferred_lang was this code goes back to NULL,
        // so their next login falls through to the install default.
        try {
            db_query(
                "UPDATE `{$prefix}user` SET preferred_lang = NULL WHERE preferred_lang = ?",
                [$code]
            );
        } catch (Exception $e) {
            // pre-8b — column missing — fine
        }

        audit_log('config', 'delete', 'language', 0,
            "Deleted language '{$code}' (and {$capDeleted} caption rows)");
        json_response([
            'deleted'             => true,
            'code'                => $code,
            'captions_removed'    => $capDeleted,
        ]);
    } catch (Exception $e) {
        json_error('Delete failed: ' . $e->getMessage(), 500);
    }
}

json_error('Unknown action: ' . $action, 400);
