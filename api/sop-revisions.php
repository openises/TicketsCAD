<?php
/**
 * NewUI v4.0 API - SOP Revisions
 *
 * GET /api/sop-revisions.php?page_id=xxx
 *   Returns list of revisions with editor name, date, and summary.
 *
 * GET /api/sop-revisions.php?revision_id=xxx
 *   Returns a single revision's full content.
 */

require_once __DIR__ . '/auth.php';

$prevDisplay = ini_get('display_errors');
ini_set('display_errors', '0');

$page_id     = isset($_GET['page_id']) ? (int) $_GET['page_id'] : 0;
$revision_id = isset($_GET['revision_id']) ? (int) $_GET['revision_id'] : 0;

try {
    // Single revision request
    if ($revision_id > 0) {
        $revision = db_fetch_one(
            "SELECT r.*, u.`user` AS `edited_by_name`
             FROM " . db_table('sop_revisions') . " r
             LEFT JOIN " . db_table('user') . " u ON r.`edited_by` = u.`id`
             WHERE r.`id` = ?",
            [$revision_id]
        );

        if (!$revision) {
            ini_set('display_errors', $prevDisplay);
            json_error('Revision not found', 404);
        }

        ini_set('display_errors', $prevDisplay);
        json_response([
            'revision' => [
                'id'             => (int) $revision['id'],
                'page_id'        => (int) $revision['page_id'],
                'title'          => $revision['title'],
                'content'        => $revision['content'],
                'edited_by'      => (int) $revision['edited_by'],
                'edited_by_name' => $revision['edited_by_name'] ?: 'Unknown',
                'edited_at'      => $revision['edited_at'],
                'summary'        => $revision['summary'],
            ],
        ]);
    }

    // List revisions for a page
    if ($page_id <= 0) {
        json_error('page_id is required');
    }

    $revisions = db_fetch_all(
        "SELECT r.`id`, r.`page_id`, r.`title`, r.`edited_by`, r.`edited_at`, r.`summary`,
                u.`user` AS `edited_by_name`
         FROM " . db_table('sop_revisions') . " r
         LEFT JOIN " . db_table('user') . " u ON r.`edited_by` = u.`id`
         WHERE r.`page_id` = ?
         ORDER BY r.`edited_at` DESC",
        [$page_id]
    );

    $result = [];
    foreach ($revisions as $r) {
        $result[] = [
            'id'             => (int) $r['id'],
            'page_id'        => (int) $r['page_id'],
            'title'          => $r['title'],
            'edited_by'      => (int) $r['edited_by'],
            'edited_by_name' => $r['edited_by_name'] ?: 'Unknown',
            'edited_at'      => $r['edited_at'],
            'summary'        => $r['summary'],
        ];
    }

    ini_set('display_errors', $prevDisplay);
    json_response(['revisions' => $result]);

} catch (Exception $e) {
    ini_set('display_errors', $prevDisplay);
    json_error('Database error: ' . $e->getMessage(), 500);
}
