<?php
/**
 * Phase 139 (2026-08-14) — Quick Notes internal writers/readers.
 *
 * Every function here takes $userId explicitly and scopes every query to
 * it — quick_notes are strictly private (Eric's explicit answer: never
 * shared or visible to anyone else, not even an admin). There is no RBAC
 * permission for this feature; ownership by user_id IS the access control.
 *
 * Copy targets:
 *   - Incident activity log (`action` table) — via the existing
 *     incident_add_note_internal() helper (inc/incident-write.php).
 *   - ICS-214 Activity Log — appends {time, activity} to the chosen
 *     form's form_data_json.activity_log array.
 *   - A personal SOP-Wiki page (sop_pages.owner_user_id = $userId) —
 *     appends the note text to the page's content and records a
 *     sop_revisions row, exactly like a normal SOP edit. Scoped to pages
 *     this user owns (or a brand-new personal page); never touches a
 *     shared org-wide page (owner_user_id IS NULL) or another user's
 *     personal page.
 *
 * Every copy_to_* function takes $move (bool): false = copy (the note
 * survives, Eric's default), true = move (the note is deleted after a
 * successful copy).
 */

require_once __DIR__ . '/incident-write.php';

/** Create a note. Returns ['id' => int, 'errors' => []] or ['errors' => [...]]. */
function quick_note_create_internal(int $userId, string $noteText): array {
    $noteText = trim($noteText);
    if ($userId <= 0) return ['errors' => ['Invalid user']];
    if ($noteText === '') return ['errors' => ['Note text is required']];

    $prefix = $GLOBALS['db_prefix'] ?? '';
    $now = date('Y-m-d H:i:s');
    db_query(
        "INSERT INTO `{$prefix}quick_notes` (`user_id`, `note_text`, `captured_at`, `created_at`, `updated_at`)
         VALUES (?, ?, ?, ?, ?)",
        [$userId, $noteText, $now, $now, $now]
    );
    return ['id' => (int) db_insert_id(), 'errors' => []];
}

/** List a user's own notes, newest first. */
function quick_notes_list_internal(int $userId, ?bool $done = null): array {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $sql = "SELECT `id`, `note_text`, `captured_at`, `done`, `created_at`, `updated_at`
              FROM `{$prefix}quick_notes` WHERE `user_id` = ?";
    $params = [$userId];
    if ($done !== null) {
        $sql .= " AND `done` = ?";
        $params[] = $done ? 1 : 0;
    }
    $sql .= " ORDER BY `captured_at` DESC, `id` DESC";
    $rows = db_fetch_all($sql, $params);
    foreach ($rows as &$r) {
        $r['id'] = (int) $r['id'];
        $r['done'] = (int) $r['done'] === 1;
    }
    return $rows;
}

/** Fetch one note, only if owned by $userId. Null if not found/not owned. */
function _qn_owned_note(int $userId, int $noteId): ?array {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $row = db_fetch_one(
        "SELECT * FROM `{$prefix}quick_notes` WHERE `id` = ? AND `user_id` = ?",
        [$noteId, $userId]
    );
    return $row ?: null;
}

function quick_note_delete_internal(int $userId, int $noteId): array {
    if (!_qn_owned_note($userId, $noteId)) return ['errors' => ['Note not found']];
    $prefix = $GLOBALS['db_prefix'] ?? '';
    db_query("DELETE FROM `{$prefix}quick_notes` WHERE `id` = ? AND `user_id` = ?", [$noteId, $userId]);
    return ['errors' => []];
}

function quick_note_set_done_internal(int $userId, int $noteId, bool $done): array {
    if (!_qn_owned_note($userId, $noteId)) return ['errors' => ['Note not found']];
    $prefix = $GLOBALS['db_prefix'] ?? '';
    db_query(
        "UPDATE `{$prefix}quick_notes` SET `done` = ?, `updated_at` = ? WHERE `id` = ? AND `user_id` = ?",
        [$done ? 1 : 0, date('Y-m-d H:i:s'), $noteId, $userId]
    );
    return ['errors' => []];
}

/**
 * Copy or move a note into an incident's activity log.
 * The activity-log entry's `date` is NOW (matches every other entry in
 * that log — it records when the entry was logged, not backdated), with
 * the note's ORIGINAL capture timestamp prefixed into the text itself so
 * that information travels with the copy per Eric's own description of
 * the feature ("with a timestamp that would be automatically captured").
 */
function quick_note_copy_to_activity_log_internal(int $userId, int $noteId, int $ticketId, bool $move): array {
    $note = _qn_owned_note($userId, $noteId);
    if (!$note) return ['errors' => ['Note not found']];
    if ($ticketId <= 0) return ['errors' => ['Invalid incident']];

    $prefix = $GLOBALS['db_prefix'] ?? '';
    // Soft-delete audit (tools/soft_delete_audit.php): a note must never
    // land in a wastebasketed incident's activity log -- exclude the same
    // way every other ticket read in this codebase does. `deleted_at` is
    // NOT in base_schema.sql (arrives with the wastebasket migration), so
    // fall back on an install that hasn't run it (api/net-checkins.php's
    // own pattern for exactly this column).
    try {
        $ticket = db_fetch_one(
            "SELECT `id` FROM `{$prefix}ticket` WHERE `id` = ? AND `deleted_at` IS NULL",
            [$ticketId]
        );
    } catch (Exception $e) {
        $ticket = db_fetch_one("SELECT `id` FROM `{$prefix}ticket` WHERE `id` = ?", [$ticketId]);
    }
    if (!$ticket) return ['errors' => ['Incident not found']];

    $text = '[Captured ' . date('Y-m-d H:i', strtotime($note['captured_at'])) . '] ' . $note['note_text'];
    $result = incident_add_note_internal($ticketId, $text, $userId);
    if (!empty($result['errors'])) return $result;

    if ($move) quick_note_delete_internal($userId, $noteId);
    return ['errors' => [], 'action_id' => $result['id'] ?? null];
}

/** List existing ICS-214 forms for an incident, for the target picker. */
function quick_note_ics214_forms_for_incident(int $ticketId): array {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $deletedClause = '';
    try {
        if (function_exists('ics_forms_has_soft_delete') && ics_forms_has_soft_delete()) {
            $deletedClause = ' AND `deleted_at` IS NULL';
        }
    } catch (Throwable $e) {}
    $rows = db_fetch_all(
        "SELECT `id`, `title`, `created_at` FROM `{$prefix}ics_forms`
          WHERE `form_type` = '214' AND `incident_id` = ?{$deletedClause}
          ORDER BY `created_at` DESC",
        [$ticketId]
    );
    foreach ($rows as &$r) { $r['id'] = (int) $r['id']; }
    return $rows;
}

/**
 * Copy or move a note into an ICS-214's Activity Log rows. Unlike the
 * incident activity log above, a 214 row IS literally {time, activity} --
 * so `time` here is the note's own ORIGINAL capture time, not "now".
 */
function quick_note_copy_to_ics214_internal(int $userId, int $noteId, int $formId, bool $move): array {
    $note = _qn_owned_note($userId, $noteId);
    if (!$note) return ['errors' => ['Note not found']];
    if ($formId <= 0) return ['errors' => ['Invalid ICS-214 form']];

    $prefix = $GLOBALS['db_prefix'] ?? '';
    $form = db_fetch_one(
        "SELECT `id`, `form_data_json` FROM `{$prefix}ics_forms` WHERE `id` = ? AND `form_type` = '214'",
        [$formId]
    );
    if (!$form) return ['errors' => ['ICS-214 form not found']];

    $data = json_decode((string) $form['form_data_json'], true);
    if (!is_array($data)) $data = [];
    if (!isset($data['activity_log']) || !is_array($data['activity_log'])) $data['activity_log'] = [];

    $data['activity_log'][] = [
        'time'     => date('H:i', strtotime($note['captured_at'])),
        'activity' => $note['note_text'],
    ];

    db_query(
        "UPDATE `{$prefix}ics_forms` SET `form_data_json` = ?, `updated_at` = ? WHERE `id` = ?",
        [json_encode($data, JSON_UNESCAPED_UNICODE), date('Y-m-d H:i:s'), $formId]
    );

    if ($move) quick_note_delete_internal($userId, $noteId);
    return ['errors' => []];
}

/** This user's own personal SOP-Wiki pages, tree-ordered, for the drag-drop target list. */
function quick_note_personal_wiki_tree_internal(int $userId): array {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $rows = db_fetch_all(
        "SELECT `id`, `title`, `parent_id`, `sort_order` FROM `{$prefix}sop_pages`
          WHERE `owner_user_id` = ?
          ORDER BY `parent_id` IS NULL DESC, `parent_id`, `sort_order`, `title`",
        [$userId]
    );
    foreach ($rows as &$r) {
        $r['id'] = (int) $r['id'];
        $r['parent_id'] = $r['parent_id'] ? (int) $r['parent_id'] : null;
    }
    return $rows;
}

/**
 * Copy or move a note onto a personal wiki page — appends the note text
 * to the page's content and records a revision, exactly like a normal
 * SOP edit. $pageId must already be owned by $userId (existing personal
 * page); if null, $newPageTitle creates a brand-new personal page (a
 * fresh root-level page, parent_id NULL — same tree, own subtree since
 * every page it can ever be reparented under is filtered to this user's
 * own pages by quick_note_personal_wiki_tree_internal()).
 */
function quick_note_copy_to_wiki_internal(int $userId, int $noteId, ?int $pageId, ?string $newPageTitle, bool $move): array {
    $note = _qn_owned_note($userId, $noteId);
    if (!$note) return ['errors' => ['Note not found']];

    $prefix = $GLOBALS['db_prefix'] ?? '';
    $now = date('Y-m-d H:i:s');
    $appendText = '[Captured ' . date('Y-m-d H:i', strtotime($note['captured_at'])) . '] ' . $note['note_text'];

    if ($pageId !== null && $pageId > 0) {
        $page = db_fetch_one(
            "SELECT `id`, `title`, `content` FROM `{$prefix}sop_pages` WHERE `id` = ? AND `owner_user_id` = ?",
            [$pageId, $userId]
        );
        if (!$page) return ['errors' => ['Personal wiki page not found']];

        db_query(
            "INSERT INTO `{$prefix}sop_revisions` (`page_id`, `content`, `title`, `edited_by`, `edited_at`, `summary`)
             VALUES (?, ?, ?, ?, ?, ?)",
            [$pageId, $page['content'], $page['title'], $userId, $now, 'Quick note appended']
        );
        $newContent = rtrim($page['content']) . "\n\n" . $appendText;
        db_query(
            "UPDATE `{$prefix}sop_pages` SET `content` = ?, `updated_by` = ?, `updated_at` = ? WHERE `id` = ?",
            [$newContent, $userId, $now, $pageId]
        );
    } else {
        $title = trim((string) $newPageTitle);
        if ($title === '') return ['errors' => ['A title is required to create a new personal page']];
        $slug = 'personal-' . $userId . '-' . preg_replace('/[^a-z0-9\-]/', '-', strtolower($title));
        $slug = preg_replace('/-+/', '-', $slug);
        $slug = substr(trim($slug, '-'), 0, 128);
        // Slug collisions: append the timestamp, matching api/sop-save.php's own pattern.
        $exists = db_fetch_value("SELECT 1 FROM `{$prefix}sop_pages` WHERE `slug` = ?", [$slug]);
        if ($exists) $slug .= '-' . time();

        db_query(
            "INSERT INTO `{$prefix}sop_pages`
                (`slug`, `title`, `content`, `parent_id`, `sort_order`, `owner_user_id`, `created_by`, `created_at`)
             VALUES (?, ?, ?, NULL, 0, ?, ?, ?)",
            [$slug, $title, $appendText, $userId, $userId, $now]
        );
        $pageId = (int) db_insert_id();
    }

    if ($move) quick_note_delete_internal($userId, $noteId);
    return ['errors' => [], 'page_id' => $pageId];
}
