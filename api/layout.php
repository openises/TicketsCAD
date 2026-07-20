<?php
/**
 * NewUI v4.0 API - Dashboard Layout
 *
 * GET  /api/layout.php                          - Get current user's default layout
 * POST /api/layout.php                          - Save default layout
 *   Body: { "layout": [...], "hidden": [...] }
 *
 * Snapshot operations (via ?action= parameter):
 * GET  ?action=list_snapshots                   - List user's saved snapshots
 * GET  ?action=load_snapshot&name=xxx           - Load a named snapshot
 * POST ?action=save_snapshot                    - Save a named snapshot
 *   Body: { "layout_name": "...", "layout": [...], "hidden": [...] }
 * DELETE ?action=delete_snapshot&name=xxx       - Delete a named snapshot
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$action = $_GET['action'] ?? '';

/**
 * RBAC stale-layout guard (specs/rbac-enforcement-2026-06). Strip any widget the
 * current user no longer has `widget.X` for, so a layout saved while they had broader
 * permissions (or an admin-saved default) cannot resurrect a now-ungranted widget.
 * Mirrors dash_can()'s statistics→widget.stats mapping via dash_widget_perm().
 */
function layout_filter_perms($layout) {
    if (!is_array($layout)) return $layout;
    return array_values(array_filter($layout, function ($item) {
        $id = is_array($item) ? ($item['id'] ?? '') : '';
        if ($id === '') return true; // malformed/unknown — no enforcement signal
        return rbac_can(dash_widget_perm($id));
    }));
}

// CSRF (F-011) — verify on any state-changing method, before any DB write.
// Token may arrive in JSON body, X-CSRF-Token header, or query (DELETE).
if (in_array($_SERVER['REQUEST_METHOD'], ['POST', 'DELETE'], true)) {
    $csrfTok = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if ($csrfTok === '' && isset($_GET['csrf_token'])) {
        $csrfTok = (string) $_GET['csrf_token'];
    }
    if ($csrfTok === '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $csrfTok = (string) ($body['csrf_token'] ?? '');
    }
    if (!csrf_verify($csrfTok)) {
        json_error('Invalid CSRF token', 403);
    }
}

// ── Snapshot: List ─────────────────────────────────────────────────
if ($action === 'list_snapshots' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $rows = db_fetch_all(
            "SELECT `layout_name`, `updated_at` FROM `{$prefix}dashboard_layouts`
             WHERE `user_id` = ? AND `layout_name` != 'default'
             ORDER BY `updated_at` DESC",
            [$current_user_id]
        );
        json_response(['snapshots' => $rows ?: []]);
    } catch (PDOException $e) {
        json_response(['snapshots' => []]);
    }
}

// ── Snapshot: Load ─────────────────────────────────────────────────
if ($action === 'load_snapshot' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $name = $_GET['name'] ?? '';
    if ($name === '' || $name === 'default') {
        json_error('Invalid snapshot name');
    }
    try {
        $row = db_fetch_one(
            "SELECT `layout_json`, `hidden_widgets` FROM `{$prefix}dashboard_layouts`
             WHERE `user_id` = ? AND `layout_name` = ? LIMIT 1",
            [$current_user_id, $name]
        );
        if ($row) {
            json_response([
                'layout'         => layout_filter_perms(json_decode($row['layout_json'], true)),
                'hidden_widgets' => json_decode($row['hidden_widgets'], true),
            ]);
        }
        json_error('Snapshot not found', 404);
    } catch (PDOException $e) {
        json_error('Snapshot not found', 404);
    }
}

// ── Snapshot: Save ─────────────────────────────────────────────────
if ($action === 'save_snapshot' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $name = $input['layout_name'] ?? '';

    if ($name === '' || $name === 'default') {
        json_error('Invalid snapshot name');
    }
    if (!isset($input['layout'])) {
        json_error('Missing layout data');
    }

    // Limit to 20 snapshots per user
    try {
        $count = db_fetch_one(
            "SELECT COUNT(*) as cnt FROM `{$prefix}dashboard_layouts`
             WHERE `user_id` = ? AND `layout_name` != 'default'",
            [$current_user_id]
        );
        if ($count && (int)$count['cnt'] >= 20) {
            json_error('Maximum 20 snapshots reached. Delete one first.');
        }
    } catch (PDOException $e) {}

    $layout_json = json_encode($input['layout']);
    $hidden_json = json_encode($input['hidden'] ?? []);

    try {
        $sql = "INSERT INTO `{$prefix}dashboard_layouts`
                (`user_id`, `layout_name`, `layout_json`, `hidden_widgets`)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                `layout_json` = VALUES(`layout_json`),
                `hidden_widgets` = VALUES(`hidden_widgets`),
                `updated_at` = CURRENT_TIMESTAMP";

        db_query($sql, [$current_user_id, $name, $layout_json, $hidden_json]);
        json_response(['saved' => true]);
    } catch (PDOException $e) {
        json_response(['saved' => false, 'error' => 'Layout table not found']);
    }
}

// ── Snapshot: Delete ───────────────────────────────────────────────
if ($action === 'delete_snapshot' && $_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $name = $_GET['name'] ?? '';
    if ($name === '' || $name === 'default') {
        json_error('Invalid snapshot name');
    }
    try {
        db_query(
            "DELETE FROM `{$prefix}dashboard_layouts`
             WHERE `user_id` = ? AND `layout_name` = ?",
            [$current_user_id, $name]
        );
        json_response(['deleted' => true]);
    } catch (PDOException $e) {
        json_error('Delete failed');
    }
}

// ── Default layout: GET ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $row = db_fetch_one(
            "SELECT `layout_json`, `hidden_widgets` FROM `{$prefix}dashboard_layouts`
             WHERE `user_id` = ? AND `layout_name` = 'default' LIMIT 1",
            [$current_user_id]
        );

        if ($row) {
            json_response([
                'layout'         => layout_filter_perms(json_decode($row['layout_json'], true)),
                'hidden_widgets' => json_decode($row['hidden_widgets'], true),
            ]);
        }
    } catch (PDOException $e) {
        // Table may not exist yet — fall through to defaults
    }

    json_response([
        'layout'         => null,
        'hidden_widgets' => [],
    ]);
}

// ── Default layout: POST (auto-save) ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input || !isset($input['layout'])) {
        json_error('Missing layout data');
    }

    $layout_json   = json_encode($input['layout']);
    $hidden_json   = json_encode($input['hidden'] ?? []);

    try {
        $sql = "INSERT INTO `{$prefix}dashboard_layouts`
                (`user_id`, `layout_name`, `layout_json`, `hidden_widgets`)
                VALUES (?, 'default', ?, ?)
                ON DUPLICATE KEY UPDATE
                `layout_json` = VALUES(`layout_json`),
                `hidden_widgets` = VALUES(`hidden_widgets`),
                `updated_at` = CURRENT_TIMESTAMP";

        db_query($sql, [$current_user_id, $layout_json, $hidden_json]);
        json_response(['saved' => true]);
    } catch (PDOException $e) {
        json_response(['saved' => false, 'error' => 'Layout table not found. Run sql/dashboard_tables.sql']);
    }
}

json_error('Method not allowed', 405);
