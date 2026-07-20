<?php
/**
 * Phase 41 — Email Distribution Lists API.
 *
 *   GET  ?action=list                   → all active lists with member counts
 *   GET  ?action=detail&id=N            → single list + its members (resolved)
 *   POST ?action=create                 → create list  (body: name, slug?, description?)
 *   POST ?action=update                 → edit list    (body: id, name?, description?)
 *   POST ?action=archive                → archive list (body: id)
 *   POST ?action=add_member             → add a recipient  (body: list_id, member_type, ref_id|inline_email, display_name?)
 *   POST ?action=remove_member          → remove a recipient (body: id)
 *   POST ?action=import_csv             → bulk import (body: list_id, csv_text)
 *   GET  ?action=resolve&id=N           → resolved recipient list (final addresses + names),
 *                                          with cycle detection on nested sub-lists
 *
 * RBAC: action.manage_config (admin).
 */
ini_set('display_errors', '0');
header('Content-Type: application/json');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/functions.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';

if (!rbac_can('action.manage_config')) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden — requires action.manage_config']);
    exit;
}

$prefix = $GLOBALS['db_prefix'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

$input = [];
if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $input = $raw ? (json_decode($raw, true) ?: []) : $_POST;
    if (!$action && !empty($input['action'])) $action = $input['action'];
}

function _slugify(string $s): string {
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9]+/i', '-', $s);
    return trim($s, '-');
}

if ($action === 'list' && $method === 'GET') {
    try {
        $rows = db_fetch_all(
            "SELECT l.id, l.name, l.slug, l.description, l.created_at,
                    (SELECT COUNT(*) FROM `{$prefix}email_list_members` m WHERE m.list_id = l.id) AS member_count
               FROM `{$prefix}email_lists` l
              WHERE l.archived_at IS NULL
              ORDER BY l.name"
        );
        json_response(['lists' => $rows]);
    } catch (Exception $e) { json_error('list failed: ' . $e->getMessage(), 500); }
}

if ($action === 'detail' && $method === 'GET') {
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) json_error('id required');
    try {
        $list = db_fetch_one("SELECT * FROM `{$prefix}email_lists` WHERE id = ?", [$id]);
        if (!$list) json_error('not found', 404);
        // Resolve member display names via JOINs. NOTE: member has no
        // `username` column (GH #71) — build the display name from
        // first/last with callsign fallback.
        $members = db_fetch_all(
            "SELECT lm.id, lm.member_type, lm.ref_id, lm.inline_email, lm.display_name,
                    NULLIF(TRIM(CONCAT(COALESCE(m.first_name, ''), ' ', COALESCE(m.last_name, ''))), '')
                        AS member_username,
                    m.callsign AS member_callsign, m.email AS member_email,
                    c.contact AS constituent_name, c.email AS constituent_email,
                    sl.name AS sub_list_name
               FROM `{$prefix}email_list_members` lm
               LEFT JOIN `{$prefix}member` m ON m.id = lm.ref_id AND lm.member_type = 'member'
               LEFT JOIN `{$prefix}constituents` c ON c.id = lm.ref_id AND lm.member_type = 'constituent'
               LEFT JOIN `{$prefix}email_lists` sl ON sl.id = lm.ref_id AND lm.member_type = 'list'
              WHERE lm.list_id = ?
              ORDER BY lm.member_type, lm.added_at DESC",
            [$id]
        );
        json_response(['list' => $list, 'members' => $members]);
    } catch (Exception $e) { json_error('detail failed: ' . $e->getMessage(), 500); }
}

if ($action === 'create' && $method === 'POST') {
    if (empty($input['csrf_token']) || !csrf_verify($input['csrf_token'])) {
        json_error('Invalid CSRF token', 403);
    }
    $name = substr(trim((string) ($input['name'] ?? '')), 0, 64);
    if ($name === '') json_error('name required');
    $slug = !empty($input['slug']) ? substr(_slugify((string) $input['slug']), 0, 64) : substr(_slugify($name), 0, 64);
    $desc = isset($input['description']) ? substr((string) $input['description'], 0, 1024) : null;
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    try {
        db_query(
            "INSERT INTO `{$prefix}email_lists` (name, slug, description, created_by) VALUES (?, ?, ?, ?)",
            [$name, $slug, $desc, $userId ?: null]
        );
        json_response(['id' => (int) db_insert_id(), 'name' => $name, 'slug' => $slug]);
    } catch (Exception $e) {
        if (stripos($e->getMessage(), 'Duplicate') !== false) json_error('A list with that slug already exists.', 409);
        json_error('create failed: ' . $e->getMessage(), 500);
    }
}

if ($action === 'update' && $method === 'POST') {
    if (empty($input['csrf_token']) || !csrf_verify($input['csrf_token'])) {
        json_error('Invalid CSRF token', 403);
    }
    $id = (int) ($input['id'] ?? 0);
    if ($id <= 0) json_error('id required');
    $sets = [];
    $params = [];
    if (isset($input['name'])) {
        $sets[] = "name = ?";
        $params[] = substr(trim((string) $input['name']), 0, 64);
    }
    if (isset($input['description'])) {
        $sets[] = "description = ?";
        $params[] = substr((string) $input['description'], 0, 1024);
    }
    if (!$sets) json_error('nothing to update');
    $params[] = $id;
    try {
        db_query("UPDATE `{$prefix}email_lists` SET " . implode(', ', $sets) . " WHERE id = ?", $params);
        json_response(['ok' => true]);
    } catch (Exception $e) { json_error('update failed: ' . $e->getMessage(), 500); }
}

if ($action === 'archive' && $method === 'POST') {
    if (empty($input['csrf_token']) || !csrf_verify($input['csrf_token'])) {
        json_error('Invalid CSRF token', 403);
    }
    $id = (int) ($input['id'] ?? 0);
    if ($id <= 0) json_error('id required');
    try {
        db_query("UPDATE `{$prefix}email_lists` SET archived_at = NOW() WHERE id = ?", [$id]);
        json_response(['ok' => true]);
    } catch (Exception $e) { json_error('archive failed: ' . $e->getMessage(), 500); }
}

if ($action === 'add_member' && $method === 'POST') {
    if (empty($input['csrf_token']) || !csrf_verify($input['csrf_token'])) {
        json_error('Invalid CSRF token', 403);
    }
    $listId = (int) ($input['list_id'] ?? 0);
    $type   = (string) ($input['member_type'] ?? '');
    if ($listId <= 0 || !in_array($type, ['member','constituent','inline','list'], true)) {
        json_error('list_id + valid member_type required');
    }
    $refId = isset($input['ref_id']) ? (int) $input['ref_id'] : null;
    $inline = isset($input['inline_email']) ? substr(trim((string) $input['inline_email']), 0, 255) : null;
    $name = isset($input['display_name']) ? substr(trim((string) $input['display_name']), 0, 128) : null;

    if ($type === 'inline' && (!$inline || !filter_var($inline, FILTER_VALIDATE_EMAIL))) {
        json_error('inline_email required and must be a valid address');
    }
    if (in_array($type, ['member','constituent','list'], true) && (!$refId || $refId <= 0)) {
        json_error('ref_id required for ' . $type);
    }
    if ($type === 'list' && $refId === $listId) {
        json_error('A list cannot include itself.');
    }
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    try {
        db_query(
            "INSERT INTO `{$prefix}email_list_members`
                (list_id, member_type, ref_id, inline_email, display_name, added_by)
             VALUES (?, ?, ?, ?, ?, ?)",
            [$listId, $type, $refId, $inline, $name, $userId ?: null]
        );
        json_response(['id' => (int) db_insert_id()]);
    } catch (Exception $e) { json_error('add_member failed: ' . $e->getMessage(), 500); }
}

if ($action === 'remove_member' && $method === 'POST') {
    if (empty($input['csrf_token']) || !csrf_verify($input['csrf_token'])) {
        json_error('Invalid CSRF token', 403);
    }
    $id = (int) ($input['id'] ?? 0);
    if ($id <= 0) json_error('id required');
    try {
        db_query("DELETE FROM `{$prefix}email_list_members` WHERE id = ?", [$id]);
        json_response(['ok' => true]);
    } catch (Exception $e) { json_error('remove failed: ' . $e->getMessage(), 500); }
}

if ($action === 'import_csv' && $method === 'POST') {
    if (empty($input['csrf_token']) || !csrf_verify($input['csrf_token'])) {
        json_error('Invalid CSRF token', 403);
    }
    $listId = (int) ($input['list_id'] ?? 0);
    $csv = (string) ($input['csv_text'] ?? '');
    if ($listId <= 0 || $csv === '') json_error('list_id + csv_text required');
    $userId = (int) ($_SESSION['user_id'] ?? 0);

    $added = 0; $skipped = 0; $errors = [];
    foreach (preg_split('/\r?\n/', trim($csv)) as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0) continue;
        $parts = str_getcsv($line);
        $email = trim($parts[0] ?? '');
        $name  = isset($parts[1]) ? trim($parts[1]) : null;
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $skipped++; $errors[] = "skipped: $line"; continue; }
        try {
            db_query(
                "INSERT INTO `{$prefix}email_list_members`
                    (list_id, member_type, inline_email, display_name, added_by)
                 VALUES (?, 'inline', ?, ?, ?)",
                [$listId, $email, $name, $userId ?: null]
            );
            $added++;
        } catch (Exception $e) { $skipped++; $errors[] = "err($email): " . $e->getMessage(); }
    }
    json_response(['added' => $added, 'skipped' => $skipped, 'errors' => array_slice($errors, 0, 10)]);
}

if ($action === 'resolve' && $method === 'GET') {
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) json_error('id required');
    $visited = [];
    $resolved = [];
    $resolve = function ($listId) use (&$resolve, &$visited, &$resolved, $prefix) {
        if (isset($visited[$listId])) return; // cycle guard
        $visited[$listId] = true;
        $members = db_fetch_all("SELECT * FROM `{$prefix}email_list_members` WHERE list_id = ?", [$listId]);
        foreach ($members as $m) {
            switch ($m['member_type']) {
                case 'member':
                    $row = db_fetch_one(
                        "SELECT email, NULLIF(TRIM(CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, ''))), '') AS full_name, callsign
                           FROM `{$prefix}member` WHERE id = ?",
                        [$m['ref_id']]
                    );
                    if ($row && !empty($row['email'])) {
                        $resolved[$row['email']] = $m['display_name'] ?? ($row['full_name'] ?? $row['callsign']);
                    }
                    break;
                case 'constituent':
                    // constituents uses `contact` for the person's name (GH #71)
                    $row = db_fetch_one("SELECT email, contact FROM `{$prefix}constituents` WHERE id = ?", [$m['ref_id']]);
                    if ($row && !empty($row['email'])) $resolved[$row['email']] = $m['display_name'] ?? $row['contact'];
                    break;
                case 'inline':
                    $resolved[$m['inline_email']] = $m['display_name'] ?? null;
                    break;
                case 'list':
                    if (!isset($visited[$m['ref_id']])) $resolve((int) $m['ref_id']);
                    break;
            }
        }
    };
    $resolve($id);
    json_response(['count' => count($resolved), 'recipients' => array_map(
        function ($email, $name) { return ['email' => $email, 'name' => $name]; },
        array_keys($resolved), array_values($resolved)
    )]);
}

json_error('Unknown action: ' . $action);
