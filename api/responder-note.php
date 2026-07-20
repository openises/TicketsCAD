<?php
/**
 * Phase 99n-v2 (2026-06-29) — Add a free-text note to a responder.
 *
 * Eric beta: when a dispatcher presses N on a unit that has no
 * active assignment, the previous behavior was an alert + redirect
 * ("open the unit detail page to assign first"). That's hostile UX.
 *
 * Instead: the dispatcher can record a unit-level note. We don't
 * have a dedicated responder_notes table, so we lean on audit_log:
 *
 *   entity_type = 'asset'
 *   action      = 'note'
 *   entity_table = 'responder'
 *   entity_id    = $responder_id
 *   summary      = the note text
 *   details      = { user_id, ip, note }
 *
 * The unit-detail page can render these via a recent-audit-log
 * lookup keyed by entity_table='responder' AND entity_id=N.
 *
 * POST  JSON: { responder_id, note, csrf_token }
 * Auth: any logged-in user with action.add_note (same RBAC gate
 * as incident notes).
 */

declare(strict_types=1);

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/audit.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed', 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
if (empty($input['csrf_token']) || !csrf_verify($input['csrf_token'])) {
    json_error('Invalid CSRF token', 403);
}
if (!rbac_can('action.add_note')) {
    json_error('Insufficient permissions: add_note', 403);
}

$responder_id = (int) ($input['responder_id'] ?? 0);
$note         = trim((string) ($input['note'] ?? ''));

if ($responder_id <= 0) json_error('Invalid responder_id');
if ($note === '')       json_error('Note text is required');
if (strlen($note) > 2000) json_error('Note must be 2000 chars or fewer');

$prefix = $GLOBALS['db_prefix'] ?? '';

try {
    $resp = db_fetch_one(
        "SELECT `id`, `name`, `handle` FROM `{$prefix}responder` WHERE `id` = ? LIMIT 1",
        [$responder_id]
    );
} catch (Throwable $e) {
    json_error('Database error: ' . $e->getMessage(), 500);
}
if (!$resp) {
    json_error('Responder not found', 404);
}

$label = $resp['handle'] ?: $resp['name'] ?: ('unit #' . $responder_id);

// GH #75 — persist the note to responder_notes, the SAME table the unit
// History tab (api/unit-history.php) and the unit-detail page read from. This
// endpoint originally wrote ONLY to audit_log (an old comment above even
// claimed "we don't have a dedicated responder_notes table" — stale; the
// table exists now), so notes added from the dashboard quick-action and the
// unit-detail title bar landed where nothing on those pages reads and
// appeared to vanish. Write the durable note first, then the audit trail.
try {
    db_query("CREATE TABLE IF NOT EXISTS `{$prefix}responder_notes` (
        `id`           INT AUTO_INCREMENT PRIMARY KEY,
        `responder_id` INT NOT NULL,
        `category`     VARCHAR(32) NOT NULL DEFAULT 'general',
        `note`         TEXT NOT NULL,
        `by_user`      INT NOT NULL DEFAULT 0,
        `by_username`  VARCHAR(64) NOT NULL DEFAULT '',
        `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `deleted_at`   DATETIME NULL,
        `deleted_by`   INT NULL,
        KEY `idx_responder_time` (`responder_id`, `created_at`),
        KEY `idx_category`       (`category`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    db_query("INSERT INTO `{$prefix}responder_notes`
              (responder_id, category, note, by_user, by_username, created_at)
              VALUES (?, 'general', ?, ?, ?, NOW())",
        [$responder_id, $note, (int) ($_SESSION['user_id'] ?? 0),
         substr((string) ($_SESSION['user'] ?? ''), 0, 64)]);
} catch (Throwable $e) {
    error_log('[responder-note] responder_notes insert failed: ' . $e->getMessage());
    json_error('Could not save note: ' . $e->getMessage(), 500);
}

audit_log('asset', 'note', 'responder', $responder_id,
    "Note on {$label}: " . substr($note, 0, 200),
    [
        'responder_id' => $responder_id,
        'handle'       => $resp['handle'],
        'name'         => $resp['name'],
        'note'         => $note,
        'source'       => 'dashboard_quick_note',
    ]
);

json_response([
    'success' => true,
    'message' => 'Note recorded for ' . $label,
]);
