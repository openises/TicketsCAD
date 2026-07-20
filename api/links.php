<?php
/**
 * NewUI v4.0 API - External Links
 *
 * GET  /api/links.php             — List all active links (optionally ?category=X)
 * POST /api/links.php action=save   — Create or update a link (admin only)
 * POST /api/links.php action=delete — Delete a link (admin only)
 * POST /api/links.php action=reorder — Update sort order (admin only)
 */

ini_set('display_errors', '0');

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';

$is_admin = is_admin();

// ── GET: list links ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $sql = "SELECT `id`, `title`, `url`, `description`, `icon`, `category`,
                       `sort_order`, `active`, `created_by`, `created_at`
                FROM `external_links`";
        $params = [];

        // Non-admins only see active links
        if (!$is_admin) {
            $sql .= " WHERE `active` = 1";
        }

        // Optional category filter
        $category = isset($_GET['category']) ? trim($_GET['category']) : '';
        if ($category !== '') {
            $sql .= ($is_admin ? " WHERE" : " AND") . " `category` = ?";
            $params[] = $category;
        }

        $sql .= " ORDER BY `category` ASC, `sort_order` ASC, `title` ASC";

        $links = db_fetch_all($sql, $params);

        // Collect unique categories
        $categories = array_values(array_unique(array_filter(array_column($links, 'category'))));
        sort($categories);

        json_response([
            'links'      => $links,
            'count'      => count($links),
            'categories' => $categories,
        ]);
    } catch (Exception $e) {
        error_log('Links API GET error: ' . $e->getMessage());
        json_error('Failed to load links', 500);
    }
}

// ── POST: admin actions ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF check
    $token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!csrf_verify($token)) {
        json_error('Invalid CSRF token', 403);
    }

    // Admin-only
    if (!$is_admin) {
        json_error('Insufficient permissions', 403);
    }

    $action = $_POST['action'] ?? '';

    // ── Save (create/update) ───────────────────────────────────────
    if ($action === 'save') {
        $id          = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $title       = trim($_POST['title'] ?? '');
        $url         = trim($_POST['url'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $icon        = trim($_POST['icon'] ?? 'bi-link-45deg');
        $category    = trim($_POST['category'] ?? 'General');
        $sort_order  = isset($_POST['sort_order']) ? (int) $_POST['sort_order'] : 0;
        $active      = isset($_POST['active']) ? (int) $_POST['active'] : 1;

        if ($title === '' || $url === '') {
            json_error('Title and URL are required');
        }

        // Basic URL validation
        if (!preg_match('/^https?:\/\//i', $url)) {
            $url = 'https://' . $url;
        }

        try {
            if ($id > 0) {
                // Update existing
                db_query(
                    "UPDATE `external_links`
                     SET `title` = ?, `url` = ?, `description` = ?, `icon` = ?,
                         `category` = ?, `sort_order` = ?, `active` = ?
                     WHERE `id` = ?",
                    [$title, $url, $description, $icon, $category, $sort_order, $active, $id]
                );
                json_response(['success' => true, 'id' => $id, 'message' => 'Link updated']);
            } else {
                // Create new
                db_query(
                    "INSERT INTO `external_links`
                     (`title`, `url`, `description`, `icon`, `category`, `sort_order`, `active`, `created_by`)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                    [$title, $url, $description, $icon, $category, $sort_order, $active, $current_user_id]
                );
                $new_id = (int) db_insert_id();
                json_response(['success' => true, 'id' => $new_id, 'message' => 'Link created'], 201);
            }
        } catch (Exception $e) {
            error_log('Links API save error: ' . $e->getMessage());
            json_error('Failed to save link', 500);
        }
    }

    // ── Delete ─────────────────────────────────────────────────────
    if ($action === 'delete') {
        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        if ($id <= 0) {
            json_error('Invalid link ID');
        }

        try {
            db_query("DELETE FROM `external_links` WHERE `id` = ?", [$id]);
            json_response(['success' => true, 'message' => 'Link deleted']);
        } catch (Exception $e) {
            error_log('Links API delete error: ' . $e->getMessage());
            json_error('Failed to delete link', 500);
        }
    }

    // ── Reorder ────────────────────────────────────────────────────
    if ($action === 'reorder') {
        // Expects order[] as an array of link IDs in desired order
        $order = $_POST['order'] ?? [];
        if (!is_array($order) || empty($order)) {
            json_error('Order array is required');
        }

        try {
            $stmt = db()->prepare("UPDATE `external_links` SET `sort_order` = ? WHERE `id` = ?");
            foreach ($order as $idx => $link_id) {
                $stmt->execute([(int) $idx, (int) $link_id]);
            }
            json_response(['success' => true, 'message' => 'Order updated']);
        } catch (Exception $e) {
            error_log('Links API reorder error: ' . $e->getMessage());
            json_error('Failed to update order', 500);
        }
    }

    json_error('Unknown action', 400);
}

json_error('Method not allowed', 405);
