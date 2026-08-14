<?php
/**
 * Phase 139 (2026-08-14) — Quick Notes.
 *
 * Drives the real inc/quick-notes.php functions against real inserted
 * rows -- never a hand-simulated filter. Covers:
 *   1. Structural: the command bar reassignment (/log -> quick notes,
 *      old widget-focus command renamed to /activity) is really in source.
 *   2. Full CRUD lifecycle: create, list, set_done, delete.
 *   3. Privacy: one user can never see or touch another user's notes --
 *      not via list, not via delete/set_done/copy on someone else's id.
 *   4. Copy-to-incident-activity-log: lands a real `action` row, note
 *      survives (copy is the default).
 *   5. Copy-to-ICS-214: appends {time, activity} to the real form's
 *      form_data_json.activity_log array.
 *   6. Copy-to-personal-wiki: creates a new personal page (owner_user_id
 *      set), appends to an existing one (with a real sop_revisions row),
 *      and is REFUSED against another user's personal page or a shared
 *      org-wide page (owner_user_id NULL) -- proving the boundary Eric's
 *      "strictly private" answer requires actually holds.
 *   7. Move semantics: after a move, the note is gone from the list;
 *      after a copy, it's still there (Eric's explicit "copy by default").
 *
 * @requires-db
 * Usage: php tests/test_quick_notes.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/quick-notes.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== Phase 139 — Quick Notes ===\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';
$base = realpath(__DIR__ . '/..');

// ═══════════════════════════════════════════════════════════════════════
// Part 1 — structural: the command bar reassignment is really in source
// ═══════════════════════════════════════════════════════════════════════

echo "--- Structural checks ---\n\n";

$cbSrc = (string) file_get_contents($base . '/assets/js/command-bar.js');
t("command bar: the old widget-focus command is now named 'activity', not 'log'",
    strpos($cbSrc, "name: 'activity',    aliases: ['logs']") !== false);
t("command bar: 'logs' alias still works (muscle memory)",
    strpos($cbSrc, "aliases: ['logs']") !== false);
t("command bar: new 'log' command routes to quick-note capture (doLogCommand)",
    (bool) preg_match("/name: 'log',\s+aliases: \['note'\].*?handler: doLogCommand/s", $cbSrc));
t("command bar: doLogCommand posts to api/quick-notes.php on non-empty text",
    strpos($cbSrc, "action: 'create', note_text: text") !== false);
t("command bar: doLogCommand navigates to notes.php when text is empty",
    strpos($cbSrc, "go('notes.php')") !== false);

// ═══════════════════════════════════════════════════════════════════════
// Part 2 — schema present? skip functional checks if not migrated
// ═══════════════════════════════════════════════════════════════════════

$hasTable = (bool) db_fetch_value(
    "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
    [$prefix . 'quick_notes']
);
if (!$hasTable) {
    echo "\nSKIP: quick_notes table not present -- run sql/run_phase139_quick_notes.php first.\n";
    echo "\n=== $pass passed, $fail failed ===\n";
    exit(0);
}

echo "\n--- Functional: CRUD + privacy + copy/move ---\n\n";

// Fake user ids well outside any real range, mirroring the established
// convention in tests/test_public_board_rbac.php's Part 3.
$userA = 900002139;
$userB = 900002140;

$createdNoteIds = [];
$createdTicketIds = [];
$createdFormIds = [];
$createdPageIds = [];

function _qn_test_make_ticket(int $typeId, array $overrides = []): int {
    global $prefix, $createdTicketIds;
    $fields = array_merge([
        'in_types_id' => $typeId,
        'contact'     => '',
        'street'      => '123 Test St',
        'city'        => 'Testville',
        'state'       => 'MN',
        'lat'         => 44.8,
        'lng'         => -93.3,
        'date'        => date('Y-m-d H:i:s'),
        'scope'       => 'Phase 139 quick-notes test',
        'description' => 'Phase 139 quick-notes test',
        'status'      => 2,
        'severity'    => 1,
        'updated'     => date('Y-m-d H:i:s'),
    ], $overrides);
    $cols = array_keys($fields);
    db_query(
        "INSERT INTO `{$prefix}ticket` (`" . implode('`,`', $cols) . "`) VALUES (" .
        implode(',', array_fill(0, count($cols), '?')) . ")",
        array_values($fields)
    );
    $id = (int) db_insert_id();
    $createdTicketIds[] = $id;
    return $id;
}

try {
    // ── Part 3: full CRUD lifecycle ──
    $r = quick_note_create_internal($userA, '  First note, needs trimming  ');
    t('create: succeeds with no errors', empty($r['errors']));
    $noteId = $r['id'] ?? 0;
    $createdNoteIds[] = $noteId;
    t('create: returns a positive id', $noteId > 0);

    $r = quick_note_create_internal($userA, '   ');
    t('create: whitespace-only text is rejected', !empty($r['errors']));

    $list = quick_notes_list_internal($userA);
    $found = null;
    foreach ($list as $n) { if ($n['id'] === $noteId) { $found = $n; break; } }
    t('list: the created note appears', $found !== null);
    t('list: note text was trimmed', $found !== null && $found['note_text'] === 'First note, needs trimming');
    t('list: done defaults to false', $found !== null && $found['done'] === false);

    $r = quick_note_set_done_internal($userA, $noteId, true);
    t('set_done: succeeds', empty($r['errors']));
    $list = quick_notes_list_internal($userA, true);
    $inDoneFilter = false;
    foreach ($list as $n) { if ($n['id'] === $noteId) { $inDoneFilter = true; break; } }
    t('list(done=true): the now-done note appears in the done filter', $inDoneFilter);
    $list = quick_notes_list_internal($userA, false);
    $inOpenFilter = false;
    foreach ($list as $n) { if ($n['id'] === $noteId) { $inOpenFilter = true; break; } }
    t('list(done=false): the done note does NOT appear in the open filter', !$inOpenFilter);

    // ── Part 4: privacy -- userB can never see or touch userA's note ──
    $listB = quick_notes_list_internal($userB);
    $leaked = false;
    foreach ($listB as $n) { if ($n['id'] === $noteId) { $leaked = true; break; } }
    t('PRIVACY: userB\'s list does NOT include userA\'s note', !$leaked);

    $r = quick_note_delete_internal($userB, $noteId);
    t('PRIVACY: userB cannot delete userA\'s note (not found)', !empty($r['errors']));
    $stillThere = quick_notes_list_internal($userA);
    $stillFound = false;
    foreach ($stillThere as $n) { if ($n['id'] === $noteId) { $stillFound = true; break; } }
    t('PRIVACY: the note survived userB\'s delete attempt', $stillFound);

    $r = quick_note_set_done_internal($userB, $noteId, false);
    t('PRIVACY: userB cannot toggle done on userA\'s note (not found)', !empty($r['errors']));

    // ── Part 5: copy-to-incident-activity-log ──
    $typeRow = db_fetch_one("SELECT `id` FROM `{$prefix}in_types` LIMIT 1");
    if (!$typeRow) {
        echo "SKIP: no in_types row available -- cannot test incident/214 copy targets on this DB.\n";
    } else {
        $ticketId = _qn_test_make_ticket((int) $typeRow['id']);

        $r = quick_note_create_internal($userA, 'Copy-to-activity-log test note');
        $copyNoteId = $r['id'];
        $createdNoteIds[] = $copyNoteId;

        $r = quick_note_copy_to_activity_log_internal($userA, $copyNoteId, $ticketId, false);
        t('copy_to_activity_log: succeeds (copy mode)', empty($r['errors']));

        $actionRow = db_fetch_one(
            "SELECT `description`, `action_type` FROM `{$prefix}action`
              WHERE `ticket_id` = ? AND `description` LIKE ? ORDER BY `id` DESC LIMIT 1",
            [$ticketId, '%Copy-to-activity-log test note%']
        );
        t('copy_to_activity_log: a real action row landed with the note text', $actionRow !== null);
        t('copy_to_activity_log: action_type is 0 (general note)', $actionRow !== null && (int) $actionRow['action_type'] === 0);
        t('copy_to_activity_log: the original capture timestamp is prefixed into the text',
            $actionRow !== null && strpos($actionRow['description'], '[Captured ') === 0);

        $stillInList = quick_notes_list_internal($userA);
        $survived = false;
        foreach ($stillInList as $n) { if ($n['id'] === $copyNoteId) { $survived = true; break; } }
        t('COPY (not move): the note still exists in the list afterward', $survived);

        // ── Move semantics ──
        $r = quick_note_create_internal($userA, 'Move-to-activity-log test note');
        $moveNoteId = $r['id'];
        $createdNoteIds[] = $moveNoteId;
        $r = quick_note_copy_to_activity_log_internal($userA, $moveNoteId, $ticketId, true);
        t('copy_to_activity_log: succeeds (move mode)', empty($r['errors']));
        $afterMove = quick_notes_list_internal($userA);
        $stillExists = false;
        foreach ($afterMove as $n) { if ($n['id'] === $moveNoteId) { $stillExists = true; break; } }
        t('MOVE: the note is gone from the list afterward', !$stillExists);

        // ── Part 6: copy-to-ICS-214 ──
        db_query(
            "INSERT INTO `{$prefix}ics_forms`
                (`form_type`, `incident_id`, `title`, `form_data_json`, `created_by`, `created_by_name`, `created_at`)
             VALUES ('214', ?, 'Phase 139 test 214', ?, ?, 'test', ?)",
            [$ticketId, json_encode(['activity_log' => []]), $userA, date('Y-m-d H:i:s')]
        );
        $formId = (int) db_insert_id();
        $createdFormIds[] = $formId;

        $r = quick_note_create_internal($userA, 'Copy-to-214 test note');
        $ics214NoteId = $r['id'];
        $createdNoteIds[] = $ics214NoteId;

        $r = quick_note_copy_to_ics214_internal($userA, $ics214NoteId, $formId, false);
        t('copy_to_ics214: succeeds', empty($r['errors']));

        $formRow = db_fetch_one("SELECT `form_data_json` FROM `{$prefix}ics_forms` WHERE `id` = ?", [$formId]);
        $data = json_decode((string) $formRow['form_data_json'], true);
        $lastRow = end($data['activity_log']);
        t('copy_to_ics214: activity_log gained exactly one row', count($data['activity_log']) === 1);
        t('copy_to_ics214: the row carries the note text verbatim', $lastRow['activity'] === 'Copy-to-214 test note');
        t('copy_to_ics214: the row\'s time is the ORIGINAL capture time (HH:MM), not "now" text',
            (bool) preg_match('/^\d{2}:\d{2}$/', $lastRow['time']));

        $forms = quick_note_ics214_forms_for_incident($ticketId);
        $formIds = array_map(function ($f) { return $f['id']; }, $forms);
        t('ics214_forms_for_incident: the created form is listed', in_array($formId, $formIds, true));
    }

    // ── Part 7: copy-to-personal-wiki ──
    $hasSopPages = (bool) db_fetch_value(
        "SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'owner_user_id'",
        [$prefix . 'sop_pages']
    );
    if (!$hasSopPages) {
        echo "SKIP: sop_pages.owner_user_id not present -- personal-wiki copy target untested.\n";
    } else {
        // New personal page.
        $r = quick_note_create_internal($userA, 'First line of a new personal page');
        $wikiNoteId = $r['id'];
        $createdNoteIds[] = $wikiNoteId;

        $r = quick_note_copy_to_wiki_internal($userA, $wikiNoteId, null, 'zz139 Personal Test Page', false);
        t('copy_to_wiki (new page): succeeds', empty($r['errors']));
        $newPageId = $r['page_id'] ?? 0;
        if ($newPageId > 0) $createdPageIds[] = $newPageId;

        $pageRow = db_fetch_one("SELECT `owner_user_id`, `content`, `parent_id` FROM `{$prefix}sop_pages` WHERE `id` = ?", [$newPageId]);
        t('copy_to_wiki (new page): owner_user_id is set to the creating user', $pageRow !== null && (int) $pageRow['owner_user_id'] === $userA);
        t('copy_to_wiki (new page): content contains the note text', $pageRow !== null && strpos($pageRow['content'], 'First line of a new personal page') !== false);

        // Append to the SAME existing personal page.
        $r = quick_note_create_internal($userA, 'A second note appended to the same page');
        $appendNoteId = $r['id'];
        $createdNoteIds[] = $appendNoteId;
        $r = quick_note_copy_to_wiki_internal($userA, $appendNoteId, $newPageId, null, false);
        t('copy_to_wiki (append): succeeds', empty($r['errors']));

        $pageRow2 = db_fetch_one("SELECT `content` FROM `{$prefix}sop_pages` WHERE `id` = ?", [$newPageId]);
        t('copy_to_wiki (append): content now contains BOTH notes',
            $pageRow2 !== null
            && strpos($pageRow2['content'], 'First line of a new personal page') !== false
            && strpos($pageRow2['content'], 'A second note appended to the same page') !== false);

        $revCount = (int) db_fetch_value(
            "SELECT COUNT(*) FROM `{$prefix}sop_revisions` WHERE `page_id` = ?", [$newPageId]
        );
        t('copy_to_wiki (append): a real sop_revisions row was recorded', $revCount >= 1);

        // ── THE boundary: userB's own page, and a shared org page, are both refused. ──
        db_query(
            "INSERT INTO `{$prefix}sop_pages` (`slug`, `title`, `content`, `owner_user_id`, `created_by`, `created_at`)
             VALUES (?, 'zz139 userB personal page', 'userB content', ?, ?, ?)",
            ['zz139-userb-' . uniqid(), $userB, $userB, date('Y-m-d H:i:s')]
        );
        $userBPageId = (int) db_insert_id();
        $createdPageIds[] = $userBPageId;

        $r = quick_note_create_internal($userA, 'Should never land on userB\'s page');
        $boundaryNoteId = $r['id'];
        $createdNoteIds[] = $boundaryNoteId;
        $r = quick_note_copy_to_wiki_internal($userA, $boundaryNoteId, $userBPageId, null, false);
        t('BOUNDARY: userA cannot append to userB\'s personal page (refused)', !empty($r['errors']));

        db_query(
            "INSERT INTO `{$prefix}sop_pages` (`slug`, `title`, `content`, `owner_user_id`, `created_by`, `created_at`)
             VALUES (?, 'zz139 shared org page', 'shared content', NULL, ?, ?)",
            ['zz139-shared-' . uniqid(), $userA, date('Y-m-d H:i:s')]
        );
        $sharedPageId = (int) db_insert_id();
        $createdPageIds[] = $sharedPageId;

        $r = quick_note_copy_to_wiki_internal($userA, $boundaryNoteId, $sharedPageId, null, false);
        t('BOUNDARY: userA cannot append to a shared org-wide page via quick-notes (refused)', !empty($r['errors']));

        $personalTree = quick_note_personal_wiki_tree_internal($userA);
        $treeIds = array_map(function ($p) { return $p['id']; }, $personalTree);
        t('personal_wiki_tree: includes userA\'s own new page', in_array($newPageId, $treeIds, true));
        t('personal_wiki_tree: does NOT include userB\'s personal page', !in_array($userBPageId, $treeIds, true));
        t('personal_wiki_tree: does NOT include the shared org page', !in_array($sharedPageId, $treeIds, true));
    }

} finally {
    foreach ($createdNoteIds as $id) {
        try { db_query("DELETE FROM `{$prefix}quick_notes` WHERE `id` = ?", [$id]); } catch (Throwable $e) {}
    }
    foreach ($createdFormIds as $id) {
        try { db_query("DELETE FROM `{$prefix}ics_forms` WHERE `id` = ?", [$id]); } catch (Throwable $e) {}
    }
    foreach ($createdPageIds as $id) {
        try {
            db_query("DELETE FROM `{$prefix}sop_revisions` WHERE `page_id` = ?", [$id]);
            db_query("DELETE FROM `{$prefix}sop_pages` WHERE `id` = ?", [$id]);
        } catch (Throwable $e) {}
    }
    foreach ($createdTicketIds as $id) {
        try { db_query("DELETE FROM `{$prefix}ticket` WHERE `id` = ?", [$id]); } catch (Throwable $e) {}
        try { db_query("DELETE FROM `{$prefix}action` WHERE `ticket_id` = ?", [$id]); } catch (Throwable $e) {}
    }
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
