<?php
/**
 * NewUI v4.0 API - SOP Pages
 *
 * GET /api/sop-pages.php
 *   No params: returns all pages as flat list (id, slug, title, parent_id, sort_order, updated_at)
 *   ?slug=xxx: returns full page with content + breadcrumb
 *   ?id=xxx:   returns full page with content + breadcrumb
 */

require_once __DIR__ . '/auth.php';

$prevDisplay = ini_get('display_errors');
ini_set('display_errors', '0');

$slug = isset($_GET['slug']) ? trim($_GET['slug']) : '';
$id   = isset($_GET['id']) ? (int) $_GET['id'] : 0;

try {
    // Single page request
    if ($slug !== '' || $id > 0) {
        if ($slug !== '') {
            $page = db_fetch_one(
                "SELECT p.*, u.`user` AS `created_by_name`, u2.`user` AS `updated_by_name`
                 FROM " . db_table('sop_pages') . " p
                 LEFT JOIN " . db_table('user') . " u ON p.`created_by` = u.`id`
                 LEFT JOIN " . db_table('user') . " u2 ON p.`updated_by` = u2.`id`
                 WHERE p.`slug` = ?",
                [$slug]
            );
        } else {
            $page = db_fetch_one(
                "SELECT p.*, u.`user` AS `created_by_name`, u2.`user` AS `updated_by_name`
                 FROM " . db_table('sop_pages') . " p
                 LEFT JOIN " . db_table('user') . " u ON p.`created_by` = u.`id`
                 LEFT JOIN " . db_table('user') . " u2 ON p.`updated_by` = u2.`id`
                 WHERE p.`id` = ?",
                [$id]
            );
        }

        if (!$page) {
            ini_set('display_errors', $prevDisplay);
            json_error('Page not found', 404);
        }

        // Build breadcrumb (parent chain)
        $breadcrumb = [];
        $current_parent = $page['parent_id'];
        $max_depth = 20; // prevent infinite loops
        while ($current_parent && $max_depth > 0) {
            $parent = db_fetch_one(
                "SELECT `id`, `slug`, `title`, `parent_id` FROM " . db_table('sop_pages') . " WHERE `id` = ?",
                [$current_parent]
            );
            if (!$parent) {
                break;
            }
            array_unshift($breadcrumb, [
                'id'    => (int) $parent['id'],
                'slug'  => $parent['slug'],
                'title' => $parent['title'],
            ]);
            $current_parent = $parent['parent_id'];
            $max_depth--;
        }

        ini_set('display_errors', $prevDisplay);
        json_response([
            'page' => [
                'id'              => (int) $page['id'],
                'slug'            => $page['slug'],
                'title'           => $page['title'],
                'content'         => $page['content'],
                'parent_id'       => $page['parent_id'] ? (int) $page['parent_id'] : null,
                'sort_order'      => (int) $page['sort_order'],
                'created_by'      => (int) $page['created_by'],
                'created_by_name' => $page['created_by_name'] ?: 'Unknown',
                'created_at'      => $page['created_at'],
                'updated_by'      => $page['updated_by'] ? (int) $page['updated_by'] : null,
                'updated_by_name' => $page['updated_by_name'] ?: null,
                'updated_at'      => $page['updated_at'],
            ],
            'breadcrumb' => $breadcrumb,
        ]);
    }

    // List all pages (flat)
    $pages = db_fetch_all(
        "SELECT `id`, `slug`, `title`, `parent_id`, `sort_order`, `updated_at`
         FROM " . db_table('sop_pages') . "
         ORDER BY `parent_id` IS NULL DESC, `parent_id`, `sort_order`, `title`"
    );

    $result = [];
    foreach ($pages as $p) {
        $result[] = [
            'id'         => (int) $p['id'],
            'slug'       => $p['slug'],
            'title'      => $p['title'],
            'parent_id'  => $p['parent_id'] ? (int) $p['parent_id'] : null,
            'sort_order' => (int) $p['sort_order'],
            'updated_at' => $p['updated_at'],
        ];
    }

    ini_set('display_errors', $prevDisplay);
    json_response(['pages' => $result]);

} catch (Exception $e) {
    ini_set('display_errors', $prevDisplay);
    json_error('Database error: ' . $e->getMessage(), 500);
}
