<?php
/**
 * Phase 104g (a beta tester GH #15, 2026-07-02) — Unit / responder history log.
 *
 * GET /api/unit-history.php?responder_id=X&limit=N
 *   Returns a merged timeline for one unit:
 *     - Action-log rows (`action` table) where `responder = X`
 *     - Status transitions (from `log` table with action_type=30)
 *     - Assign / clear rows (from `assigns` table)
 *     - Free-form notes (from `responder_notes` — see below)
 *
 * GET /api/unit-history.php?action=notes&responder_id=X
 *   Returns just the free-form notes.
 *
 * POST action=add_note { responder_id, note, category? }
 *   Adds a free-form note to a responder. Category is a free
 *   string (default 'general'); useful for filtering later.
 *
 * DELETE ?id=X
 *   Soft-delete a note (deleted_at stamped).
 *
 * Motivation: Eric on 2026-07-02 asked for notes on responders +
 * units for ICS-214 report construction. Same page also answers
 * a beta tester's #15 "where does extra_data go?" question by showing
 * every write in one timeline.
 */

ini_set('display_errors', '0');
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

function _uh_ensure_notes_table(): void {
    static $done = false;
    if ($done) return;
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        db_query("CREATE TABLE IF NOT EXISTS `{$prefix}responder_notes` (
            `id`           INT AUTO_INCREMENT PRIMARY KEY,
            `responder_id` INT NOT NULL,
            `category`     VARCHAR(32) NOT NULL DEFAULT 'general',
            `note`         TEXT NOT NULL,
            `by_user`      INT NOT NULL DEFAULT 0,
            `by_username`  VARCHAR(64) NOT NULL DEFAULT '',
            `corrects_id`  INT NULL,
            `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `deleted_at`   DATETIME NULL,
            `deleted_by`   INT NULL,
            KEY `idx_responder_time` (`responder_id`, `created_at`),
            KEY `idx_category`       (`category`),
            KEY `idx_corrects`       (`corrects_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $done = true;
    } catch (Exception $e) {
        error_log('[unit-history] ensure_notes_table: ' . $e->getMessage());
    }
}

// GH#46 — this endpoint had no CSRF check on its state-changing actions
// (add_note, delete) before this change; the note-correction UI is the
// first thing to expose a Delete button here, so close the gap while
// touching the file rather than ship a new, unprotected mutation path.
// Query string (DELETE has no body in the current JS) first, then JSON
// body / form POST — mirrors api/file-upload.php's _file_upload_csrf_ok().
function _uh_csrf_ok(array $input = []): bool {
    $tok = $_GET['csrf_token'] ?? ($input['csrf_token'] ?? '');
    return csrf_verify((string) $tok);
}

_uh_ensure_notes_table();

if ($method === 'DELETE') {
    if (!_uh_csrf_ok()) {
        json_error('Invalid CSRF token', 403);
    }
    if (!rbac_can('action.change_unit_status') && !is_admin()) {
        json_error('Forbidden', 403);
    }
    parse_str($_SERVER['QUERY_STRING'] ?? '', $q);
    $id = (int) ($q['id'] ?? 0);
    if ($id <= 0) json_error('Invalid note id');
    try {
        db_query("UPDATE `{$prefix}responder_notes`
                  SET deleted_at = NOW(), deleted_by = ? WHERE id = ?",
                 [(int) $current_user_id, $id]);
        json_response(['deleted' => true]);
    } catch (Exception $e) {
        json_error('Delete failed: ' . $e->getMessage(), 500);
    }
}

$input = ($method === 'POST')
    ? (json_decode(file_get_contents('php://input'), true) ?: $_POST)
    : $_GET;
$action = $input['action'] ?? '';

if ($method === 'POST' && $action === 'add_note') {
    if (!_uh_csrf_ok($input)) {
        json_error('Invalid CSRF token', 403);
    }
    if (!rbac_can('action.change_unit_status') && !is_admin()) {
        json_error('Forbidden', 403);
    }
    $rid = (int) ($input['responder_id'] ?? 0);
    $note = trim((string) ($input['note'] ?? ''));
    $cat = substr(trim((string) ($input['category'] ?? 'general')), 0, 32) ?: 'general';
    if ($rid <= 0) json_error('Invalid responder_id');
    if ($note === '') json_error('Note text required');

    // GH#46 — append-a-correction. A correction is a normal note that
    // references the one it corrects; the original is never edited or
    // hidden. Restricted to the original note's own author, or an admin
    // (Eric + cbyrdmo, 2026-08-12) -- deliberately narrower than the
    // general action.change_unit_status gate above, which still governs
    // plain (non-correcting) notes and delete.
    $correctsId = (int) ($input['corrects_id'] ?? 0);
    if ($correctsId > 0) {
        $target = db_fetch_one(
            "SELECT id, responder_id, by_user FROM `{$prefix}responder_notes`
             WHERE id = ? AND deleted_at IS NULL",
            [$correctsId]
        );
        if (!$target) json_error('The note being corrected no longer exists', 404);
        if ((int) $target['responder_id'] !== $rid) json_error('Invalid correction target', 400);
        if ((int) $target['by_user'] !== (int) $current_user_id && !is_admin()) {
            json_error('Only the original author or an admin can correct this note', 403);
        }
    } else {
        $correctsId = null;
    }

    try {
        db_query("INSERT INTO `{$prefix}responder_notes`
                  (responder_id, category, note, by_user, by_username, corrects_id, created_at)
                  VALUES (?, ?, ?, ?, ?, ?, NOW())",
                 [$rid, $cat, $note, (int) $current_user_id,
                  substr($_SESSION['user'] ?? '', 0, 64), $correctsId]);
        json_response(['id' => (int) db_insert_id()]);
    } catch (Exception $e) {
        json_error('Save failed: ' . $e->getMessage(), 500);
    }
}

// GET — read paths
$rid   = (int) ($input['responder_id'] ?? 0);
$limit = min((int) ($input['limit'] ?? 200), 1000);
if ($rid <= 0) {
    json_error('responder_id required');
}

if ($action === 'notes') {
    try {
        $notes = db_fetch_all(
            "SELECT id, category, note, by_user, by_username, corrects_id, created_at
             FROM `{$prefix}responder_notes`
             WHERE responder_id = ? AND deleted_at IS NULL
             ORDER BY created_at DESC LIMIT ?",
            [$rid, $limit]
        );
        json_response(['notes' => _uh_annotate_note_perms($notes)]);
    } catch (Exception $e) {
        json_response(['notes' => [], 'error' => $e->getMessage()]);
    }
}

/**
 * GH#46 — attach the two UI-affordance flags per note, computed server-side
 * so the frontend never has to reason about permissions itself:
 *   can_correct — only the note's own author, or an admin (narrower; see
 *                 the add_note corrects_id check above, which is the real
 *                 enforcement -- this flag only controls whether the
 *                 button renders).
 *   can_delete  — the existing, broader gate already used by the DELETE
 *                 handler above (unchanged scope; just newly surfaced so a
 *                 button can be shown for it).
 */
function _uh_annotate_note_perms(array $notes): array {
    global $current_user_id;
    $canDelete = rbac_can('action.change_unit_status') || is_admin();
    $isAdmin   = is_admin();
    foreach ($notes as &$n) {
        $n['can_correct'] = $isAdmin || ((int) ($n['by_user'] ?? 0) === (int) $current_user_id);
        $n['can_delete']  = $canDelete;
    }
    unset($n);
    return $notes;
}

// Full merged timeline
$events = [];

try {
    // Free-form notes.
    $notes = db_fetch_all(
        "SELECT id, category, note AS description, by_username, created_at
         FROM `{$prefix}responder_notes`
         WHERE responder_id = ? AND deleted_at IS NULL
         ORDER BY created_at DESC LIMIT ?",
        [$rid, $limit]
    );
    foreach ($notes as $n) {
        $events[] = [
            'kind'        => 'note',
            'when'        => $n['created_at'],
            'category'    => $n['category'],
            'description' => $n['description'],
            'user'        => $n['by_username'],
            'ref_id'      => (int) $n['id'],
        ];
    }
} catch (Exception $e) { /* table absent */ }

try {
    // Status transition log rows (action_type = 30).
    $logs = db_fetch_all(
        "SELECT l.`when`, l.info AS description, u.user AS username
         FROM `{$prefix}log` l
         LEFT JOIN `{$prefix}user` u ON l.who = u.id
         WHERE l.code = 30 AND l.info LIKE ?
         ORDER BY l.`when` DESC LIMIT ?",
        ['%' . _uh_responder_name($rid) . '%', $limit]
    );
    foreach ($logs as $l) {
        $events[] = [
            'kind'        => 'status_change',
            'when'        => $l['when'],
            'description' => $l['description'],
            'user'        => $l['username'] ?? '',
        ];
    }
} catch (Exception $e) { /* fine */ }

try {
    // Action-log rows tagged with this responder id.
    $acts = db_fetch_all(
        "SELECT a.date AS `when`, a.description, u.user AS username, a.ticket_id, a.action_type
         FROM `{$prefix}action` a
         LEFT JOIN `{$prefix}user` u ON a.user = u.id
         WHERE a.responder = ?
         ORDER BY a.date DESC LIMIT ?",
        [$rid, $limit]
    );
    foreach ($acts as $a) {
        $events[] = [
            'kind'        => 'action',
            'when'        => $a['when'],
            'description' => $a['description'],
            'user'        => $a['username'] ?? '',
            'ticket_id'   => (int) $a['ticket_id'],
            'action_type' => (int) $a['action_type'],
        ];
    }
} catch (Exception $e) { /* fine */ }

try {
    // Assignment rows (dispatch + clear pairs).
    $assigns = db_fetch_all(
        "SELECT ticket_id, dispatched, responding, on_scene, clear
         FROM `{$prefix}assigns`
         WHERE responder_id = ?
         ORDER BY dispatched DESC LIMIT ?",
        [$rid, $limit]
    );
    foreach ($assigns as $a) {
        if (!empty($a['dispatched']) && substr($a['dispatched'], 0, 4) !== '0000') {
            $events[] = [
                'kind'        => 'assign',
                'when'        => $a['dispatched'],
                'description' => 'Dispatched to incident #' . (int) $a['ticket_id'],
                'user'        => '',
                'ticket_id'   => (int) $a['ticket_id'],
            ];
        }
        if (!empty($a['clear']) && substr($a['clear'], 0, 4) !== '0000') {
            $events[] = [
                'kind'        => 'assign',
                'when'        => $a['clear'],
                'description' => 'Cleared from incident #' . (int) $a['ticket_id'],
                'user'        => '',
                'ticket_id'   => (int) $a['ticket_id'],
            ];
        }
    }
} catch (Exception $e) { /* fine */ }

// Merge-sort by 'when' desc.
usort($events, function ($a, $b) {
    return strcmp((string) ($b['when'] ?? ''), (string) ($a['when'] ?? ''));
});
if (count($events) > $limit) $events = array_slice($events, 0, $limit);

function _uh_responder_name(int $rid): string {
    static $cache = [];
    if (isset($cache[$rid])) return $cache[$rid];
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $r = db_fetch_one("SELECT name, handle FROM `{$prefix}responder` WHERE id = ? LIMIT 1", [$rid]);
        return $cache[$rid] = ($r ? ($r['handle'] ?: $r['name']) : '');
    } catch (Exception $e) { return $cache[$rid] = ''; }
}

json_response(['events' => $events, 'responder_id' => $rid]);
