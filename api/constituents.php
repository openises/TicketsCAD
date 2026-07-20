<?php
/**
 * NewUI v4.0 API - Constituents (Contact Database)
 *
 * GET  /api/constituents.php              — List all constituents (paginated)
 * GET  /api/constituents.php?id=X         — Get single constituent
 * GET  /api/constituents.php?phone=X      — Lookup by phone number (searches all 4 phone fields)
 * GET  /api/constituents.php?search=X     — Search by name, phone, or address
 * POST /api/constituents.php              — Create or update (JSON body with fields, id present = update)
 * POST /api/constituents.php  action=delete — Delete constituent
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';

$prevDisplay = ini_get('display_errors');
ini_set('display_errors', '0');

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    handleGet();
} elseif ($method === 'POST') {
    handlePost();
} else {
    json_error('Method not allowed', 405);
}

ini_set('display_errors', $prevDisplay);

// ── GET handlers ──

function handleGet() {
    // Single constituent by ID
    if (!empty($_GET['id'])) {
        $id = intval($_GET['id']);
        try {
            $row = db_fetch_one(
                "SELECT * FROM " . db_table('constituents') . " WHERE `id` = ?",
                [$id]
            );
        } catch (Exception $e) {
            json_error('Constituents table not available');
        }
        if (!$row) json_error('Constituent not found', 404);
        json_response(['constituent' => $row]);
    }

    // Phase 73h — Reference / Spotter-ID lookup. Drives the Skywarn-style
    // "structured callback" flow on new-incident: dispatcher types a
    // known spotter ID, blur triggers this endpoint, returns the
    // single matching constituent (or null) so caller name / phone /
    // address auto-populate without typing.
    //
    // Exact match first (the typical pattern for radio shorthand
    // identifiers like '2415' or 'N0NKI'). Falls back to prefix /
    // suffix matches up to 5 results so a dispatcher with an
    // ambiguous-but-narrowing input still gets a chooser. Returns
    // {constituent: {...}} for a single exact hit (mirrors ?id=X) and
    // {constituents: [...]} for the multi-match fallback so the JS
    // can pick the right UI without inspecting both keys.
    if (!empty($_GET['reference'])) {
        $ref = trim($_GET['reference']);
        if ($ref === '') {
            json_response(['constituent' => null]);
        }
        try {
            $exact = db_fetch_one(
                "SELECT * FROM " . db_table('constituents')
                . " WHERE `reference` = ? LIMIT 1",
                [$ref]
            );
            if ($exact) {
                json_response(['constituent' => $exact]);
            }
            $like = $ref . '%';
            $likeAny = '%' . $ref . '%';
            $rows = db_fetch_all(
                "SELECT * FROM " . db_table('constituents') . "
                 WHERE `reference` LIKE ? OR `reference` LIKE ?
                 ORDER BY (`reference` = ?) DESC,
                          (`reference` LIKE ?) DESC,
                          `reference` ASC
                 LIMIT 5",
                [$like, $likeAny, $ref, $like]
            );
            json_response([
                'constituent'  => null,
                'constituents' => $rows,
            ]);
        } catch (Exception $e) {
            error_log('[constituents reference lookup] SQL failure: ' . $e->getMessage());
            json_response(['constituent' => null, 'constituents' => []]);
        }
    }

    // Phone lookup (used during incident creation)
    if (!empty($_GET['phone'])) {
        $phone = trim($_GET['phone']);
        // Strip non-digits for flexible matching
        $digits = preg_replace('/\D/', '', $phone);
        if (strlen($digits) < 4) {
            json_response(['constituents' => []]);
        }
        $pattern = '%' . $digits . '%';
        // Clean phone fields of non-digits for comparison
        try {
            $rows = db_fetch_all(
                "SELECT * FROM " . db_table('constituents') . "
                 WHERE REPLACE(REPLACE(REPLACE(`phone`, '-', ''), ' ', ''), '(', '') LIKE ?
                    OR REPLACE(REPLACE(REPLACE(`phone_2`, '-', ''), ' ', ''), '(', '') LIKE ?
                    OR REPLACE(REPLACE(REPLACE(`phone_3`, '-', ''), ' ', ''), '(', '') LIKE ?
                    OR REPLACE(REPLACE(REPLACE(`phone_4`, '-', ''), ' ', ''), '(', '') LIKE ?
                 LIMIT 10",
                [$pattern, $pattern, $pattern, $pattern]
            );
        } catch (Exception $e) {
            $rows = [];
        }
        json_response(['constituents' => $rows]);
    }

    // Search
    if (!empty($_GET['search'])) {
        $term = '%' . trim($_GET['search']) . '%';
        try {
            $rows = db_fetch_all(
                "SELECT * FROM " . db_table('constituents') . "
                 WHERE `contact` LIKE ? OR `phone` LIKE ? OR `street` LIKE ?
                    OR `city` LIKE ? OR `miscellaneous` LIKE ? OR `email` LIKE ?
                 ORDER BY `contact`
                 LIMIT 50",
                [$term, $term, $term, $term, $term, $term]
            );
        } catch (Exception $e) {
            $rows = [];
        }
        json_response(['constituents' => $rows]);
    }

    // List all (paginated)
    $page = max(1, intval($_GET['page'] ?? 1));
    $perPage = 50;
    $offset = ($page - 1) * $perPage;

    try {
        $total = db_fetch_value(
            "SELECT COUNT(*) FROM " . db_table('constituents')
        );
        $rows = db_fetch_all(
            "SELECT * FROM " . db_table('constituents') . "
             ORDER BY `contact`
             LIMIT " . intval($perPage) . " OFFSET " . intval($offset)
        );
    } catch (Exception $e) {
        json_response(['constituents' => [], 'total' => 0, 'page' => 1, 'pages' => 0]);
    }

    json_response([
        'constituents' => $rows,
        'total'        => intval($total),
        'page'         => $page,
        'pages'        => ceil(intval($total) / $perPage)
    ]);
}

// ── POST handlers ──

function handlePost() {
    global $current_user_id;

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) json_error('Invalid JSON body');

    // CSRF validation
    $csrf = $input['csrf_token'] ?? '';
    if (!csrf_verify($csrf)) {
        json_error('Invalid or expired security token. Please refresh the page.', 403);
    }

    // RBAC enforcement (specs/rbac-enforcement-2026-06).
    // Writes require action.manage_members; reads (GET) stay open to viewers.
    if (!rbac_can('action.manage_members')) {
        json_error('Insufficient permissions: manage members', 403);
    }

    // Delete action
    if (($input['action'] ?? '') === 'delete') {
        $id = intval($input['id'] ?? 0);
        if (!$id) json_error('Missing id');
        try {
            db_query("DELETE FROM " . db_table('constituents') . " WHERE `id` = ?", [$id]);
        } catch (Exception $e) {
            json_error('Failed to delete: ' . $e->getMessage());
        }
        json_response(['success' => true]);
    }

    // Merge action
    if (($input['action'] ?? '') === 'merge') {
        $primaryId   = intval($input['primary_id'] ?? 0);
        $secondaryId = intval($input['secondary_id'] ?? 0);
        $mergedFields = $input['fields'] ?? [];

        if (!$primaryId || !$secondaryId) json_error('Missing record IDs for merge');
        if ($primaryId === $secondaryId) json_error('Cannot merge a record with itself');

        // Verify both exist
        $primary = db_fetch_one("SELECT * FROM " . db_table('constituents') . " WHERE `id` = ?", [$primaryId]);
        $secondary = db_fetch_one("SELECT * FROM " . db_table('constituents') . " WHERE `id` = ?", [$secondaryId]);
        if (!$primary) json_error('Primary record not found', 404);
        if (!$secondary) json_error('Secondary record not found', 404);

        // Whitelist of mergeable fields
        $allowedFields = [
            'contact', 'phone', 'phone_type', 'phone_2', 'phone_2_type',
            'phone_3', 'phone_3_type', 'phone_4', 'phone_4_type', 'email',
            'street', 'apartment', 'city', 'state', 'post_code',
            'community', 'miscellaneous', 'reference'
        ];

        $setParts = [];
        $params = [];
        foreach ($allowedFields as $f) {
            if (isset($mergedFields[$f])) {
                $setParts[] = "`{$f}` = ?";
                $params[] = $mergedFields[$f];
            }
        }
        $setParts[] = '`updated` = NOW()';
        $setParts[] = '`_by` = ?';
        $params[] = $current_user_id;
        $params[] = $primaryId;

        try {
            // Update primary with merged values
            db_query(
                "UPDATE " . db_table('constituents') . " SET " . implode(', ', $setParts) . " WHERE `id` = ?",
                $params
            );

            // Delete secondary
            db_query("DELETE FROM " . db_table('constituents') . " WHERE `id` = ?", [$secondaryId]);

            $merged = db_fetch_one("SELECT * FROM " . db_table('constituents') . " WHERE `id` = ?", [$primaryId]);
            json_response(['success' => true, 'constituent' => $merged]);
        } catch (Exception $e) {
            json_error('Merge failed: ' . $e->getMessage());
        }
    }

    // Validate required fields
    $contact = trim($input['contact'] ?? '');
    $phone = trim($input['phone'] ?? '');
    if (!$contact) json_error('Contact name is required');
    if (!$phone) json_error('Phone number is required');

    $fields = [
        'contact'       => $contact,
        'street'        => trim($input['street'] ?? ''),
        'apartment'     => trim($input['apartment'] ?? ''),
        'community'     => trim($input['community'] ?? ''),
        'city'          => trim($input['city'] ?? ''),
        'post_code'     => trim($input['post_code'] ?? ''),
        'state'         => trim($input['state'] ?? ''),
        'miscellaneous' => trim($input['miscellaneous'] ?? ''),
        'phone'         => $phone,
        'phone_type'    => trim($input['phone_type'] ?? ''),
        'phone_2'       => trim($input['phone_2'] ?? ''),
        'phone_2_type'  => trim($input['phone_2_type'] ?? ''),
        'phone_3'       => trim($input['phone_3'] ?? ''),
        'phone_3_type'  => trim($input['phone_3_type'] ?? ''),
        'phone_4'       => trim($input['phone_4'] ?? ''),
        'phone_4_type'  => trim($input['phone_4_type'] ?? ''),
        'email'         => trim($input['email'] ?? ''),
        'lat'           => !empty($input['lat']) ? floatval($input['lat']) : null,
        'lng'           => !empty($input['lng']) ? floatval($input['lng']) : null,
        'reference'     => trim($input['reference'] ?? ''),
        'updated'       => date('Y-m-d H:i:s'),
        '_by'           => $current_user_id
    ];

    $id = intval($input['id'] ?? 0);

    try {
        if ($id > 0) {
            // Update
            $setParts = [];
            $params = [];
            foreach ($fields as $col => $val) {
                $setParts[] = "`{$col}` = ?";
                $params[] = $val;
            }
            $params[] = $id;
            db_query(
                "UPDATE " . db_table('constituents') . " SET " . implode(', ', $setParts) . " WHERE `id` = ?",
                $params
            );
        } else {
            // Insert
            $cols = array_keys($fields);
            $placeholders = array_fill(0, count($cols), '?');
            db_query(
                "INSERT INTO " . db_table('constituents') . " (`" . implode('`, `', $cols) . "`) VALUES (" . implode(', ', $placeholders) . ")",
                array_values($fields)
            );
            $id = db_insert_id();
        }
    } catch (Exception $e) {
        json_error('Failed to save: ' . $e->getMessage());
    }

    // Return the saved record
    $saved = db_fetch_one("SELECT * FROM " . db_table('constituents') . " WHERE `id` = ?", [$id]);
    json_response(['success' => true, 'constituent' => $saved]);
}
