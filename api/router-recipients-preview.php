<?php
/**
 * Phase 99v-4 (a beta tester/Eric beta 2026-06-30) — Predicate preview endpoint.
 *
 * POST /api/router-recipients-preview.php
 *   { "predicate": { ... predicate tree ... },
 *     "sample_payload": { "ticket_id": 73, ... }  // optional, for $payload.X
 *   }
 *
 * Returns:
 *   {
 *     "count": N,
 *     "users": [{id, user}, ...],   // up to 25, sorted by name
 *     "truncated": bool             // true if count > 25
 *   }
 *
 * Used by the Settings → Message Routing predicate builder's
 * "Preview recipients" button so admin sees who the rule would
 * notify BEFORE saving.
 *
 * Admin-only (action.manage_routing).
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/router_recipients.php';

ini_set('display_errors', '0');

if (!is_admin() && !rbac_can('action.manage_routing')) {
    json_error('Insufficient permissions', 403);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('POST required', 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$predicate = $input['predicate'] ?? null;
$payload   = is_array($input['sample_payload'] ?? null) ? $input['sample_payload'] : [];

if (!is_array($predicate) || empty($predicate)) {
    json_error('predicate (JSON object) required');
}

try {
    $userIds = router_recipients_resolve($predicate, $payload);
} catch (Exception $e) {
    json_error('predicate failed to resolve: ' . $e->getMessage(), 500);
}

$count = count($userIds);
$users = [];
if ($count > 0) {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $limit  = 25;
    $sampleIds = array_slice($userIds, 0, $limit);
    $place = implode(',', array_fill(0, count($sampleIds), '?'));
    try {
        $users = db_fetch_all(
            "SELECT id, user FROM `{$prefix}user` WHERE id IN ($place) ORDER BY user",
            $sampleIds
        );
    } catch (Exception $e) {
        // Fall back to bare ids if user lookup fails (orphans, etc.)
        foreach ($sampleIds as $uid) $users[] = ['id' => $uid, 'user' => "(user $uid)"];
    }
}

json_response([
    'count'     => $count,
    'users'     => $users,
    'truncated' => $count > 25,
]);
