<?php
/**
 * NewUI v4.0 API - SOP Delete
 *
 * POST /api/sop-delete.php
 *   JSON body: { id }
 *   Re-parents any children to the deleted page's parent.
 *   Returns success.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/audit.php';

$prevDisplay = ini_get('display_errors');
ini_set('display_errors', '0');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('POST required', 405);
}

// Parse JSON body
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    json_error('Invalid JSON body');
}

// CSRF check
if (empty($input['csrf_token']) || !csrf_verify($input['csrf_token'])) {
    json_error('Invalid CSRF token', 403);
}

// RBAC enforcement (specs/rbac-enforcement-2026-06).
// Write-only endpoint; deleting SOP pages requires action.manage_sop.
if (!rbac_can('action.manage_sop')) {
    json_error('Insufficient permissions: manage SOP', 403);
}

$id = isset($input['id']) ? (int) $input['id'] : 0;
if ($id <= 0) {
    json_error('Page ID is required');
}

try {
    // Fetch the page to get its parent_id
    $page = db_fetch_one(
        "SELECT `id`, `parent_id`, `title` FROM " . db_table('sop_pages') . " WHERE `id` = ?",
        [$id]
    );

    if (!$page) {
        ini_set('display_errors', $prevDisplay);
        json_error('Page not found', 404);
    }

    // Re-parent children to the deleted page's parent
    db_query(
        "UPDATE " . db_table('sop_pages') . " SET `parent_id` = ? WHERE `parent_id` = ?",
        [$page['parent_id'], $id]
    );

    // Delete revisions for this page
    db_query(
        "DELETE FROM " . db_table('sop_revisions') . " WHERE `page_id` = ?",
        [$id]
    );

    // Delete the page
    db_query(
        "DELETE FROM " . db_table('sop_pages') . " WHERE `id` = ?",
        [$id]
    );

    audit_log('config', 'delete', 'sop_page', $id, "Deleted SOP page '" . $page['title'] . "'");

    ini_set('display_errors', $prevDisplay);
    json_response([
        'success' => true,
        'message' => 'Page "' . $page['title'] . '" deleted',
    ]);

} catch (Exception $e) {
    ini_set('display_errors', $prevDisplay);
    json_error('Database error: ' . $e->getMessage(), 500);
}
