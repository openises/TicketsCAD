<?php
/**
 * NewUI v4.0 API - SOP Save
 *
 * POST /api/sop-save.php
 *   Creates or updates an SOP page.
 *   JSON body: { id (optional), title, slug (optional), content, parent_id, summary }
 *   Auto-generates slug from title if not provided.
 *   Saves current version to sop_revisions before updating.
 *   Returns the saved page.
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
// Write-only endpoint; creating/updating SOP pages requires action.manage_sop.
if (!rbac_can('action.manage_sop')) {
    json_error('Insufficient permissions: manage SOP', 403);
}

$id        = isset($input['id']) ? (int) $input['id'] : 0;
$title     = trim($input['title'] ?? '');
$slug      = trim($input['slug'] ?? '');
$content   = $input['content'] ?? '';
$parent_id = isset($input['parent_id']) && $input['parent_id'] !== '' ? (int) $input['parent_id'] : null;
$summary   = trim($input['summary'] ?? '');

// Validate
$errors = [];
if ($title === '') {
    $errors[] = 'Title is required';
}
if ($content === '') {
    $errors[] = 'Content is required';
}
if (!empty($errors)) {
    json_response(['errors' => $errors], 422);
}

// Auto-generate slug from title if not provided
if ($slug === '') {
    $slug = strtolower($title);
    $slug = preg_replace('/[^a-z0-9\-]/', '-', $slug);
    $slug = preg_replace('/-+/', '-', $slug);
    $slug = trim($slug, '-');
    if (strlen($slug) > 128) {
        $slug = substr($slug, 0, 128);
    }
}

$now = date('Y-m-d H:i:s');

try {
    if ($id > 0) {
        // ── Update existing page ──

        // Fetch current version to save as revision
        $existing = db_fetch_one(
            "SELECT `title`, `content` FROM " . db_table('sop_pages') . " WHERE `id` = ?",
            [$id]
        );

        if (!$existing) {
            ini_set('display_errors', $prevDisplay);
            json_error('Page not found', 404);
        }

        // Save current version as a revision
        db_query(
            "INSERT INTO " . db_table('sop_revisions') . "
             (`page_id`, `content`, `title`, `edited_by`, `edited_at`, `summary`)
             VALUES (?, ?, ?, ?, ?, ?)",
            [$id, $existing['content'], $existing['title'], $current_user_id, $now, $summary]
        );

        // Check slug uniqueness (exclude self)
        $slugCheck = db_fetch_one(
            "SELECT `id` FROM " . db_table('sop_pages') . " WHERE `slug` = ? AND `id` != ?",
            [$slug, $id]
        );
        if ($slugCheck) {
            $slug = $slug . '-' . $id;
        }

        // Prevent setting parent to self or to a descendant
        if ($parent_id === $id) {
            $parent_id = null;
        }

        // Update the page
        db_query(
            "UPDATE " . db_table('sop_pages') . "
             SET `title` = ?, `slug` = ?, `content` = ?, `parent_id` = ?,
                 `updated_by` = ?, `updated_at` = ?
             WHERE `id` = ?",
            [$title, $slug, $content, $parent_id, $current_user_id, $now, $id]
        );

    } else {
        // ── Create new page ──

        // Check slug uniqueness
        $slugCheck = db_fetch_one(
            "SELECT `id` FROM " . db_table('sop_pages') . " WHERE `slug` = ?",
            [$slug]
        );
        if ($slugCheck) {
            $slug = $slug . '-' . time();
        }

        // Get next sort_order for this parent
        $maxSort = db_fetch_value(
            "SELECT COALESCE(MAX(`sort_order`), -1) FROM " . db_table('sop_pages') . "
             WHERE " . ($parent_id ? "`parent_id` = ?" : "`parent_id` IS NULL"),
            $parent_id ? [$parent_id] : []
        );

        db_query(
            "INSERT INTO " . db_table('sop_pages') . "
             (`slug`, `title`, `content`, `parent_id`, `sort_order`, `created_by`, `created_at`)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            [$slug, $title, $content, $parent_id, ((int) $maxSort) + 1, $current_user_id, $now]
        );

        $id = db_insert_id();
    }

    // Fetch the saved page to return
    $page = db_fetch_one(
        "SELECT * FROM " . db_table('sop_pages') . " WHERE `id` = ?",
        [$id]
    );

    audit_log('config', $input['id'] ? 'update' : 'create', 'sop_page', $id, ($input['id'] ? "Updated" : "Created") . " SOP page '{$title}'");

    ini_set('display_errors', $prevDisplay);
    json_response([
        'success' => true,
        'page' => [
            'id'         => (int) $page['id'],
            'slug'       => $page['slug'],
            'title'      => $page['title'],
            'content'    => $page['content'],
            'parent_id'  => $page['parent_id'] ? (int) $page['parent_id'] : null,
            'sort_order' => (int) $page['sort_order'],
            'created_by' => (int) $page['created_by'],
            'created_at' => $page['created_at'],
            'updated_by' => $page['updated_by'] ? (int) $page['updated_by'] : null,
            'updated_at' => $page['updated_at'],
        ],
    ]);

} catch (Exception $e) {
    ini_set('display_errors', $prevDisplay);
    json_error('Database error: ' . $e->getMessage(), 500);
}
