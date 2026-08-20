<?php
/**
 * ICS form delete — regression tests.
 *
 * REPORTED BY: Chris Byrd, 2026-07-29 (chased 2026-08-02): a saved ICS form
 * could not be deleted by anyone, draft or finalized. api/ics-forms.php
 * supported save / export_xml / export_pdf and nothing else.
 *
 * WHAT THIS PROVES, and how honestly:
 *
 *  - The authorisation decision and the delete itself are driven through the
 *    PRODUCTION functions in inc/ics-forms-write.php — the same file
 *    api/ics-forms.php requires. Not a copy, not a re-implementation.
 *  - Fixture forms are created by executing the INSERT statement EXTRACTED
 *    FROM api/ics-forms.php's save action, so the test cannot pass against a
 *    row shape the real writer never produces. That failure mode has cost this
 *    project real time twice (bed automation GH #20 rounds 3 and 4, where the
 *    tests hand-seeded the one column layout that happened to work), so the
 *    param list below is deliberately the endpoint's own.
 *  - The endpoint-level guarantees that cannot be exercised without Apache
 *    (CSRF verification, the read filter on every GET path, the absence of any
 *    hard delete) are asserted against the SOURCE. Those are gates: they fail
 *    when someone removes the protection, which is exactly what they are for.
 *
 * Run: php tests/test_ics_form_delete.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/_test_admin.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$root   = dirname(__DIR__);
$pass = 0;
$fail = 0;

function ok(bool $cond, string $label): void {
    global $pass, $fail;
    if ($cond) { $pass++; }
    else { $fail++; echo "FAIL: {$label}\n"; }
}

// ── Prerequisites ────────────────────────────────────────────────────────
// A virgin database with no ics_forms table has nothing to say here. Print
// the canonical 0/0 summary alongside SKIP so tools/test_all.php scores this
// as a skip rather than a silent 0/0 pass (tools/suite_contract.php).
try {
    $haveTable = (int) db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
        [$prefix . 'ics_forms']
    );
} catch (Throwable $e) {
    $haveTable = 0;
}
if ($haveTable === 0) {
    echo "SKIP: ics_forms table not present on this install\n";
    echo "\n=== 0 passed, 0 failed ===\n";
    exit(0);
}

require_once __DIR__ . '/../inc/ics-forms-write.php';
require_once __DIR__ . '/../inc/ics-form-types.php';

// ═════════════════════════════════════════════════════════════════════════
// 1. Migration — applies the columns, and is idempotent (run it twice)
// ═════════════════════════════════════════════════════════════════════════
$migration = $root . '/sql/run_ics_forms_soft_delete.php';
ok(is_file($migration), 'migration sql/run_ics_forms_soft_delete.php exists');

// The runner ksorts its glob; this file must sort AFTER the CREATE or an
// upgrade would try to ALTER a table that does not exist yet.
$names = ['run_ics_forms_soft_delete.php', 'run_ics_forms.php'];
sort($names, SORT_STRING);
ok($names[0] === 'run_ics_forms.php',
   'migration sorts after run_ics_forms.php so the table exists when it runs');

if (is_file($migration)) {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($migration) . ' 2>&1';
    $first  = shell_exec($cmd);
    $second = shell_exec($cmd);
    ok(is_string($first) && strpos($first, 'Done —') !== false, 'migration run 1 completes');
    ok(is_string($second) && strpos($second, 'Done — 0 change(s)') !== false,
       'migration is idempotent — second run reports 0 changes');
    ok(is_string($second) && stripos($second, '[WARN]') === false,
       'migration second run raises no warnings');
}

$cols = [];
foreach (db_fetch_all("SHOW COLUMNS FROM `{$prefix}ics_forms`") as $c) {
    $cols[$c['Field']] = $c;
}
ok(isset($cols['deleted_at']), 'ics_forms.deleted_at exists');
ok(isset($cols['deleted_by']), 'ics_forms.deleted_by exists');
ok(isset($cols['deleted_at']) && $cols['deleted_at']['Null'] === 'YES',
   'deleted_at is NULLable (NULL = not deleted)');
ok(isset($cols['created_by']), 'ics_forms.created_by exists — ownership is a real column');
ok(ics_forms_has_soft_delete(true) === true, 'ics_forms_has_soft_delete() sees the columns');

// ═════════════════════════════════════════════════════════════════════════
// 2. Fixture — create forms with the PRODUCTION INSERT from api/ics-forms.php
// ═════════════════════════════════════════════════════════════════════════
$apiSrc = file_get_contents($root . '/api/ics-forms.php');

/**
 * Pull the save action's INSERT out of the endpoint and run it. If the real
 * writer's column list ever changes, this stops matching (or the bound
 * parameter count stops lining up) and every test below fails loudly — which
 * is the point. A fixture that drifts from the writer proves nothing.
 *
 * Phase 140 (2026-08-16): api/ics-forms.php's save handler now branches on
 * ics_forms_has_custom_type_columns() and writes ONE OF TWO literal INSERT
 * statements -- an 8-column one (adds custom_type_id) on a migrated
 * install, the original 7-column one otherwise. Both literally appear in
 * the source, so a single "grab the first INSERT INTO ics_forms" regex no
 * longer identifies THE writer -- it has to pick the SAME branch the real
 * endpoint would take for this database's actual schema state, matching
 * ics_forms_has_soft_delete()'s existing role in this same test file. These
 * fixtures are all built-in-type (213) rows, so custom_type_id is always
 * NULL when that column exists.
 */
function make_form(string $src, string $formType, ?int $incidentId, string $title,
                   int $createdBy, string $createdByName, string $status): int {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $hasCustomCols = ics_forms_has_custom_type_columns(true);

    if (!preg_match_all('/"INSERT INTO `\{\$prefix\}ics_forms`(.*?)"/s', $src, $matches)) {
        throw new RuntimeException('could not find any ics_forms INSERT in api/ics-forms.php');
    }
    $params = [$formType, $incidentId, $title,
        json_encode(['subject' => $title, 'message' => 'fixture body'], JSON_UNESCAPED_UNICODE),
        $createdBy, $createdByName, $status];

    $chosen = null;
    foreach ($matches[1] as $fragment) {
        $placeholderCount = substr_count($fragment, '?');
        $wantsCustomCol = strpos($fragment, 'custom_type_id') !== false;
        if ($hasCustomCols === $wantsCustomCol && $placeholderCount === ($hasCustomCols ? 8 : 7)) {
            $chosen = $fragment;
            break;
        }
    }
    if ($chosen === null) {
        throw new RuntimeException('none of the ' . count($matches[1])
            . ' extracted ics_forms INSERT variant(s) match this database\'s schema state '
            . '(has_custom_type_columns=' . ($hasCustomCols ? 'true' : 'false') . ')');
    }
    if ($hasCustomCols) {
        $params[] = null; // custom_type_id -- these fixtures are always built-in-type rows
    }

    $sql = 'INSERT INTO `' . $prefix . 'ics_forms`' . $chosen;
    db_query($sql, $params);
    return (int) db_insert_id();
}

$icsFormsInsertCount = preg_match_all('/"INSERT INTO `\{\$prefix\}ics_forms`/', $apiSrc);
ok($icsFormsInsertCount === 1 || $icsFormsInsertCount === 2,
   'the save action writes ics_forms via one INSERT (or the Phase 140 schema-conditional pair this test now selects between)');

$adminId   = test_admin_user_id();
$creatorId = 990101;   // a real-shaped id that is not the admin
$strangerId = 990102;
$created   = [];       // fixture ids, torn down at the end

$mkDraft = function (int $owner = 0) use ($apiSrc, $creatorId, &$created) {
    $id = make_form($apiSrc, '213', null, 'Delete test draft',
        $owner ?: $creatorId, 'fixture', 'draft');
    $created[] = $id;
    return $id;
};
$mkFinal = function (int $owner = 0) use ($apiSrc, $creatorId, &$created) {
    $id = make_form($apiSrc, '214', null, 'Delete test final',
        $owner ?: $creatorId, 'fixture', 'final');
    $created[] = $id;
    return $id;
};

// ═════════════════════════════════════════════════════════════════════════
// 3. The decision — pure function, every combination
// ═════════════════════════════════════════════════════════════════════════
$draftRow = ['status' => 'draft', 'created_by' => $creatorId];
$finalRow = ['status' => 'final', 'created_by' => $creatorId];
$sentRow  = ['status' => 'sent',  'created_by' => $creatorId];

ok(ics_form_delete_decision($draftRow, $creatorId, false)['allowed'] === true,
   'creator may delete their own draft');
ok(ics_form_delete_decision($draftRow, $creatorId, false)['reason'] === 'creator',
   'and the basis recorded is ownership, not privilege');
ok(ics_form_delete_decision($draftRow, $strangerId, false)['allowed'] === false,
   'a non-creator non-admin may NOT delete a draft');
ok(ics_form_delete_decision($draftRow, $strangerId, false)['reason'] === 'not_creator',
   'refusal reason is not_creator');
ok(ics_form_delete_decision($finalRow, $creatorId, false)['allowed'] === false,
   'a finalized form refuses its own creator when not privileged');
ok(ics_form_delete_decision($finalRow, $creatorId, false)['reason'] === 'finalized',
   'refusal reason is finalized');
ok(ics_form_delete_decision($sentRow, $creatorId, false)['allowed'] === false,
   'a sent form is finalized too — not a draft');
ok(ics_form_delete_decision($finalRow, $strangerId, true)['allowed'] === true,
   'a privileged user may delete a finalized form');
ok(ics_form_delete_decision($draftRow, $strangerId, true)['allowed'] === true,
   'a privileged user may delete anyone\'s draft');

// created_by defaults to 0 — "no known creator" must never match a user, and
// an unauthenticated caller (user 0) must never inherit an ownerless form.
ok(ics_form_delete_decision(['status' => 'draft', 'created_by' => 0], 0, false)['allowed'] === false,
   'user 0 cannot claim an ownerless (created_by=0) draft');
ok(ics_form_delete_decision(['status' => 'draft', 'created_by' => 0], $creatorId, false)['allowed'] === false,
   'an ownerless draft is not deletable by a random user');
ok(ics_form_delete_decision(
       ['status' => 'draft', 'created_by' => $creatorId, 'deleted_at' => '2026-08-02 10:00:00'],
       $creatorId, true)['reason'] === 'already_deleted',
   'a form already in the wastebasket is not deleted twice');

// Case-insensitivity: status is a free varchar, so 'Draft' must behave as draft.
ok(ics_form_delete_decision(['status' => 'Draft', 'created_by' => $creatorId], $creatorId, false)['allowed'] === true,
   'status comparison is case-insensitive');

// ═════════════════════════════════════════════════════════════════════════
// 4. The writer — real rows, real UPDATE
// ═════════════════════════════════════════════════════════════════════════
$_SESSION['user_id'] = $creatorId;
$_SESSION['user']    = 'fixture-creator';

$draftId = $mkDraft();
$res = ics_form_soft_delete($draftId, $creatorId, false);
ok($res['deleted'] === true, 'writer: creator deletes their own draft');

$row = db_fetch_one("SELECT * FROM `{$prefix}ics_forms` WHERE `id` = ?", [$draftId]);
ok($row !== null && $row !== false, 'the row still EXISTS after delete — soft, not hard');
ok(!empty($row['deleted_at']), 'deleted_at is stamped');
ok((int) $row['deleted_by'] === $creatorId, 'deleted_by records who did it');
ok($row['form_data_json'] !== '' && $row['form_data_json'] !== null,
   'the form body is untouched by the delete');

// Non-creator, non-privileged
$draft2 = $mkDraft();
$res = ics_form_soft_delete($draft2, $strangerId, false);
ok($res['deleted'] === false && $res['reason'] === 'not_creator',
   'writer: a stranger cannot delete someone else\'s draft');
$still = db_fetch_one("SELECT `deleted_at` FROM `{$prefix}ics_forms` WHERE `id` = ?", [$draft2]);
ok($still && $still['deleted_at'] === null, 'and the refused form is NOT marked deleted');

// Finalized + non-privileged, even as the creator
$finalId = $mkFinal();
$res = ics_form_soft_delete($finalId, $creatorId, false);
ok($res['deleted'] === false && $res['reason'] === 'finalized',
   'writer: a finalized form refuses a non-admin creator');
$still = db_fetch_one("SELECT `deleted_at` FROM `{$prefix}ics_forms` WHERE `id` = ?", [$finalId]);
ok($still && $still['deleted_at'] === null, 'and the finalized form is NOT marked deleted');

// Finalized + privileged
$res = ics_form_soft_delete($finalId, $adminId, true);
ok($res['deleted'] === true && $res['reason'] === 'privileged',
   'writer: an admin CAN delete a finalized form');
$row = db_fetch_one("SELECT * FROM `{$prefix}ics_forms` WHERE `id` = ?", [$finalId]);
ok($row && (int) $row['deleted_by'] === $adminId, 'deleted_by is the admin');

// Second delete of the same row
$res = ics_form_soft_delete($finalId, $adminId, true);
ok($res['deleted'] === false && $res['reason'] === 'already_deleted',
   'writer: deleting an already-deleted form is refused, not repeated');

// Nonexistent / invalid
ok(ics_form_soft_delete(0, $adminId, true)['reason'] === 'invalid_id', 'writer: id 0 refused');
$maxId = (int) db_fetch_value("SELECT COALESCE(MAX(id),0) FROM `{$prefix}ics_forms`");
ok(ics_form_soft_delete($maxId + 5000, $adminId, true)['reason'] === 'not_found',
   'writer: unknown id refused as not_found');

// Every refusal reason renders a sentence, not a bare token.
foreach (['already_deleted', 'finalized', 'not_creator', 'no_schema', 'not_found', 'anything'] as $r) {
    ok(strlen(ics_form_delete_message($r)) > 10, "reason '{$r}' has a human message");
}

// ═════════════════════════════════════════════════════════════════════════
// 5. Audit — the entry exists, names the right actor and target
// ═════════════════════════════════════════════════════════════════════════
$auditTable = function_exists('db_table') ? db_table('newui_audit_log') : ($prefix . 'newui_audit_log');
$auditOk = true;
try {
    db_fetch_value("SELECT COUNT(*) FROM {$auditTable}");
} catch (Throwable $e) {
    $auditOk = false;
}

if ($auditOk) {
    // The admin's delete of $finalId — $_SESSION was still the creator, so
    // re-drive one with the session set to the admin to check the actor.
    $_SESSION['user_id'] = $adminId;
    $_SESSION['user']    = 'fixture-admin';
    $auditTarget = $mkFinal();
    ics_form_soft_delete($auditTarget, $adminId, true);

    $entry = db_fetch_one(
        "SELECT * FROM {$auditTable}
          WHERE `target_type` = 'ics_forms' AND `target_id` = ?
          ORDER BY id DESC LIMIT 1",
        [(string) $auditTarget]
    );
    ok($entry !== null && $entry !== false, 'audit: a row is written for the delete');
    if ($entry) {
        ok((int) $entry['user_id'] === $adminId, 'audit: actor is the deleting user');
        ok($entry['activity'] === 'delete', 'audit: activity is delete');
        ok((int) $entry['severity'] >= 4, 'audit: severity is HIGH or above');
        ok(strpos((string) $entry['summary'], 'ICS-214') !== false,
           'audit: summary names the form type');
        ok(strpos((string) $entry['summary'], 'wastebasket') !== false,
           'audit: summary says where it went');
        $details = json_decode((string) $entry['details'], true);
        ok(is_array($details), 'audit: details is JSON');
        ok(is_array($details) && ($details['form_type'] ?? '') === '214',
           'audit: details carry the form type');
        ok(is_array($details) && ($details['basis'] ?? '') === 'privileged',
           'audit: details record WHY it was allowed');
        ok(is_array($details) && array_key_exists('incident_id', $details),
           'audit: details carry the linked incident (null when standalone)');
    }

    // An incident-linked form names its incident in the summary.
    $incForm = make_form($apiSrc, '213', 4242, 'Linked form', $adminId, 'fixture-admin', 'draft');
    $created[] = $incForm;
    ics_form_soft_delete($incForm, $adminId, true);
    $e2 = db_fetch_one(
        "SELECT `summary`, `details` FROM {$auditTable}
          WHERE `target_type` = 'ics_forms' AND `target_id` = ? ORDER BY id DESC LIMIT 1",
        [(string) $incForm]
    );
    ok($e2 && strpos((string) $e2['summary'], '#4242') !== false,
       'audit: summary names the linked incident');
} else {
    // No audit table on this install — say so rather than claim a pass.
    echo "NOTE: newui_audit_log absent; audit assertions skipped\n";
}

// ═════════════════════════════════════════════════════════════════════════
// 6. It disappears from the list, and RESTORES intact
// ═════════════════════════════════════════════════════════════════════════
$_SESSION['user_id'] = $adminId;
$listId = $mkDraft($adminId);
$before = db_fetch_one("SELECT * FROM `{$prefix}ics_forms` WHERE `id` = ?", [$listId]);

// The hub list's WHERE, as api/ics-forms.php builds it.
$visible = function (int $id) use ($prefix) {
    return (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}ics_forms` WHERE 1=1 AND `deleted_at` IS NULL AND `id` = ?",
        [$id]
    );
};
ok($visible($listId) === 1, 'a live form is in the list');

ics_form_soft_delete($listId, $adminId, true);
ok($visible($listId) === 0, 'a deleted form is GONE from the list');

// It is in the wastebasket, found by the wastebasket's own query shape.
$inBin = (int) db_fetch_value(
    "SELECT COUNT(*) FROM `{$prefix}ics_forms` WHERE `deleted_at` IS NOT NULL AND `id` = ?",
    [$listId]
);
ok($inBin === 1, 'a deleted form is IN the wastebasket');

// Restore, exactly as api/wastebasket.php's restore action does it.
db_query("UPDATE `{$prefix}ics_forms` SET `deleted_at` = NULL, `deleted_by` = NULL WHERE `id` = ?",
    [$listId]);
$after = db_fetch_one("SELECT * FROM `{$prefix}ics_forms` WHERE `id` = ?", [$listId]);
ok($visible($listId) === 1, 'a restored form is back in the list');
ok($after && $after['form_data_json'] === $before['form_data_json'],
   'restore: the form body is byte-identical');
ok($after && $after['title'] === $before['title'], 'restore: the title survives');
ok($after && $after['status'] === $before['status'], 'restore: the status survives');
ok($after && (int) $after['created_by'] === (int) $before['created_by'],
   'restore: the creator survives');
ok($after && $after['deleted_at'] === null && $after['deleted_by'] === null,
   'restore: the wastebasket marks are cleared');

// ═════════════════════════════════════════════════════════════════════════
// 7. Wastebasket wiring — registered, labelled, and NOT purgeable
// ═════════════════════════════════════════════════════════════════════════
$wbSrc = file_get_contents($root . '/api/wastebasket.php');

ok(preg_match("/'ics_forms'\s*=>\s*\[/", $wbSrc) === 1,
   'wastebasket: ics_forms is a registered type');
ok(preg_match("/'ics_forms'\s*=>\s*\[.*?'purgeable'\s*=>\s*false/s", $wbSrc) === 1,
   'wastebasket: ics_forms is marked NOT purgeable');
ok(strpos($wbSrc, 'function wb_is_purgeable') !== false,
   'wastebasket: wb_is_purgeable() exists');
ok(preg_match("/if\s*\(!wb_is_purgeable\(\\\$cfg\)\)\s*\{\s*json_error/", $wbSrc) === 1,
   'wastebasket: the purge action refuses a non-purgeable type');
// GH#43 (2026-08-08) — the skip is now a block (it also tallies a
// human-readable count of what it left behind, so an admin can tell
// "skipped on purpose" from "silently failed"), not a bare one-liner, so
// this allows anything between the guard and its continue rather than
// requiring them adjacent.
ok(preg_match("/if\s*\(!wb_is_purgeable\(\\\$cfg\)\)\s*\{.*?continue;/s", $wbSrc) === 1,
   'wastebasket: "Empty wastebasket" skips non-purgeable types');
ok(strpos($wbSrc, '$skippedLabels') !== false,
   'wastebasket: "Empty wastebasket" reports what it skipped, not just what it purged (GH#43)');
ok(strpos($wbSrc, "'can_purge'") !== false,
   'wastebasket: the list emits can_purge for the UI');
ok(strpos($wbSrc, "case 'ics_forms':") !== false,
   'wastebasket: ics_forms rows get a readable label');

// The configured SELECT must be valid against the live table — this is the
// schema-mismatch pattern's own gate. A config naming a column that does not
// exist would render an empty wastebasket with an error_log nobody reads.
if (preg_match("/'ics_forms'\s*=>\s*\[.*?'select'\s*=>\s*'([^']+)'/s", $wbSrc, $m)) {
    $selectOk = true;
    try {
        db_fetch_all("SELECT {$m[1]} FROM `{$prefix}ics_forms` LIMIT 1");
    } catch (Throwable $e) {
        $selectOk = false;
        echo "  (wastebasket select failed: " . $e->getMessage() . ")\n";
    }
    ok($selectOk, 'wastebasket: the configured ics_forms SELECT runs against the real table');
} else {
    ok(false, 'wastebasket: could not read the ics_forms select list');
}

// The settings UI must offer the filter and honour can_purge.
$setSrc = file_get_contents($root . '/settings.php');
ok(strpos($setSrc, '<option value="ics_forms">') !== false,
   'settings: the wastebasket filter offers ICS Forms');
ok(strpos($setSrc, 'item.can_purge === false') !== false,
   'settings: the purge button is hidden for non-purgeable rows');

// ═════════════════════════════════════════════════════════════════════════
// 8. NOTHING hard-deletes an ICS form
// ═════════════════════════════════════════════════════════════════════════
// Scope: shipped application code. tests/ and tools/ are excluded because a
// test tears down its own fixtures and a maintenance script may legitimately
// need to; the rule is about paths a USER can reach.
$hardDeletes = [];
$scanDirs = ['api', 'inc', 'sql', 'assets/js', 'services'];
$scanFiles = glob($root . '/*.php') ?: [];
foreach ($scanDirs as $d) {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root . '/' . $d, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile() && preg_match('/\.(php|js|sql|py)$/', $f->getFilename())) {
            $scanFiles[] = $f->getPathname();
        }
    }
}
foreach ($scanFiles as $f) {
    $src = @file_get_contents($f);
    if ($src === false) continue;
    // "DELETE FROM …ics_forms". Deliberately loose about what sits between
    // FROM and the table name, because every writer in this codebase builds
    // SQL by concatenation — `"DELETE FROM " . db_table('ics_forms')`,
    // `"DELETE FROM `{$prefix}ics_forms`"`, `' . $prefix . 'ics_forms'`. A
    // pattern that only understood one of those spellings is the Phase 125
    // blind spot verbatim: tools/schema_audit.php read string literals in
    // isolation and so could not see a single one of the 89 writer INSERTs.
    // Bounded by ";" so it cannot leap between statements.
    if (preg_match('/\bDELETE\s+(?:\w+\s+)?FROM\b[^;]{0,80}?ics_forms/i', $src)) {
        $hardDeletes[] = str_replace($root . DIRECTORY_SEPARATOR, '', $f);
    }
    if (preg_match('/TRUNCATE\s+(?:TABLE\s+)?[`\'"]?\{?\$?\w*\}?ics_forms/i', $src)) {
        $hardDeletes[] = str_replace($root . DIRECTORY_SEPARATOR, '', $f) . ' (TRUNCATE)';
    }
}
ok(empty($hardDeletes),
   'no shipped code path hard-deletes an ICS form' .
   (empty($hardDeletes) ? '' : ' — found in: ' . implode(', ', array_unique($hardDeletes))));

// ═════════════════════════════════════════════════════════════════════════
// 9. The endpoint — CSRF, the read filter, and the delete action
// ═════════════════════════════════════════════════════════════════════════
ok(strpos($apiSrc, "\$action === 'delete'") !== false,
   'endpoint: api/ics-forms.php has a delete action');
ok(preg_match('/if\s*\(!csrf_verify\(\$token\)\)\s*\{\s*json_error/', $apiSrc) === 1,
   'endpoint: POST verifies the CSRF token');
// The CSRF check must come BEFORE the action dispatch, or a delete could run
// on an unverified request.
$csrfPos   = strpos($apiSrc, 'csrf_verify($token)');
$deletePos = strpos($apiSrc, "\$action === 'delete'");
ok($csrfPos !== false && $deletePos !== false && $csrfPos < $deletePos,
   'endpoint: CSRF is verified BEFORE the delete action can run');

// Every read of a single form, and the list, must exclude wastebasket rows.
ok(substr_count($apiSrc, '$icsNotDeleted') >= 5,
   'endpoint: the not-deleted filter is applied on every read path');
$readQueries = [];
preg_match_all('/SELECT[^"]*FROM `\{\$prefix\}ics_forms`[^"]*/', $apiSrc, $qm);
foreach ($qm[0] as $q) {
    // The save action's own lookups are by id for a write we are about to do;
    // every OTHER select of a whole form is a read surface.
    if (strpos($q, 'WHERE `id` = ?') !== false && strpos($q, '$icsNotDeleted') === false
        && strpos($q, '`status`, `created_by`') === false) {
        $readQueries[] = trim(preg_replace('/\s+/', ' ', $q));
    }
}
ok(empty($readQueries),
   'endpoint: no single-form read forgets the filter' .
   (empty($readQueries) ? '' : ' — ' . implode(' | ', $readQueries)));

// The delete action must go through the shared writer, not its own UPDATE.
ok(strpos($apiSrc, 'ics_form_soft_delete(') !== false,
   'endpoint: delete goes through inc/ics-forms-write.php');
ok(strpos($apiSrc, "require_once __DIR__ . '/../inc/ics-forms-write.php'") !== false,
   'endpoint: requires the writer include');
ok(preg_match('/UPDATE `\{\$prefix\}ics_forms`\s*SET `deleted_at`/', $apiSrc) === 0,
   'endpoint: does NOT carry its own soft-delete UPDATE');

// can_delete must be emitted by the API, since the JS reads that exact key.
ok(substr_count($apiSrc, "'can_delete'") >= 1 || strpos($apiSrc, "can_delete") !== false,
   'endpoint: emits can_delete');
$jsSrc = file_get_contents($root . '/assets/js/ics-forms.js');
ok(strpos($jsSrc, 'f.can_delete') !== false || strpos($jsSrc, 'can_delete') !== false,
   'js: reads can_delete (the key the API actually emits)');
ok(strpos($jsSrc, "action: 'delete'") !== false, 'js: posts action=delete');
ok(strpos($jsSrc, 'csrf_token: csrfToken') !== false, 'js: sends the CSRF token');
ok(strpos($jsSrc, 'wastebasket') !== false,
   'js: the confirmation tells the user it goes to the wastebasket');

// A <button> inside a form without type="button" submits it and reloads the
// page — a documented recurrence in this codebase (GH #84).
ok(preg_match('/<button type="button" class="btn btn-sm btn-outline-danger ics-delete-btn/', $jsSrc) === 1,
   'js: the rendered delete button is type="button"');
// escHtml() in this file escapes via a text node, which leaves " and ' alone —
// fine for element text, wrong for an attribute holding a user-supplied title.
ok(preg_match('/data-form-label="\'\s*\+\s*escAttr\(/', $jsSrc) === 1,
   'js: the form label attribute is escaped with escAttr, not escHtml');
$pageSrc = file_get_contents($root . '/ics-forms.php');
ok(preg_match('/<button type="button"[^>]*id="btnDeleteForm"/', $pageSrc) === 1,
   'page: the editor delete button is type="button"');
ok(strpos($pageSrc, 'colspan="7"') !== false,
   'page: the saved-forms placeholder spans the new Actions column');
ok(strpos($jsSrc, 'colspan="7"') !== false,
   'js: the empty-list row spans the new Actions column');

// ═════════════════════════════════════════════════════════════════════════
// 10. RBAC — seeded in BOTH files, excluded from Dispatcher, granted 1+2
// ═════════════════════════════════════════════════════════════════════════
$rbacSql = file_get_contents($root . '/sql/rbac.sql');
$rbacPhp = file_get_contents($root . '/sql/run_00_rbac.php');

ok(strpos($rbacSql, "'action.delete_ics_form'") !== false,
   'rbac.sql seeds action.delete_ics_form');
ok(strpos($rbacPhp, "'action.delete_ics_form'") !== false,
   'run_00_rbac.php seeds action.delete_ics_form');

// rbac.sql grants Dispatcher via a broad NOT IN — a new admin-only permission
// MUST appear in that exclusion list or Dispatcher silently acquires it on the
// next re-import. Three permissions have leaked exactly this way.
// NOTE the closing anchor: an inline comment in that list already contains
// "2026-07-29);", so a lazy match to the first ");" stops half way up the
// list and would report a permission missing that is plainly there. Anchor on
// the paren that actually closes the statement — at the start of its own line.
if (preg_match('/SELECT 3, `id` FROM `permissions`\s+WHERE `code` NOT IN \((.*?)\n\s*\);/s', $rbacSql, $m)) {
    ok(strpos($m[1], 'action.delete_ics_form') !== false,
       'rbac.sql: action.delete_ics_form is in the Dispatcher exclusion list');
} else {
    ok(false, 'rbac.sql: could not locate the Dispatcher exclusion list');
}

// Live DB state.
$permRow = null;
try {
    $permRow = db_fetch_one("SELECT * FROM `{$prefix}permissions` WHERE `code` = ?",
        ['action.delete_ics_form']);
} catch (Throwable $e) { /* RBAC not installed */ }

if ($permRow) {
    ok($permRow['category'] === 'action', 'DB: the permission is in the action category');
    $permId = (int) $permRow['id'];
    $holders = array_map('intval', array_column(db_fetch_all(
        "SELECT `role_id` FROM `{$prefix}role_permissions` WHERE `permission_id` = ?",
        [$permId]), 'role_id'));
    ok(in_array(1, $holders, true), 'DB: Super Admin holds it');
    ok(in_array(2, $holders, true), 'DB: Org Admin holds it');
    ok(!in_array(3, $holders, true), 'DB: Dispatcher does NOT hold it by default');
    ok(!in_array(4, $holders, true), 'DB: Operator does NOT hold it');
    ok(!in_array(5, $holders, true), 'DB: Read-Only does NOT hold it');
    ok(!in_array(6, $holders, true), 'DB: Field Unit does NOT hold it');
} else {
    echo "NOTE: permissions table absent or unseeded; live grant assertions skipped\n";
}

// The endpoint's POST gate must admit the permission, or granting it alone
// would do nothing (a setting wired to nothing).
ok(strpos($apiSrc, "rbac_can('action.delete_ics_form')") !== false,
   'endpoint: the delete permission is actually consulted');
ok(preg_match("/!rbac_can\('action\.delete_ics_form'\)\)\s*\{\s*json_error\('Forbidden/", $apiSrc) === 1,
   'endpoint: a user holding ONLY the delete permission gets past the POST gate');

// Phase 128 — no gate may read a legacy level.
foreach ([
    'inc/ics-forms-write.php' => file_get_contents($root . '/inc/ics-forms-write.php'),
    'api/ics-forms.php'       => $apiSrc,
] as $name => $src) {
    ok(preg_match('/\$_SESSION\s*\[\s*[\'"]level[\'"]\s*\]/', $src) === 0,
       "{$name} does not authorise on a legacy level");
}

// ═════════════════════════════════════════════════════════════════════════
// Teardown — remove fixtures. (A test cleaning up after itself is not a
// user-reachable delete path; section 8 scopes its scan to shipped code.)
// ═════════════════════════════════════════════════════════════════════════
foreach (array_unique($created) as $id) {
    try {
        db_query("DELETE FROM `{$prefix}ics_forms` WHERE `id` = ? AND `title` LIKE ?",
            [$id, '%test%']);
    } catch (Throwable $e) { /* leave it rather than fail the run */ }
}
try {
    db_query("DELETE FROM `{$prefix}ics_forms` WHERE `created_by` IN (?, ?)",
        [$creatorId, $strangerId]);
    db_query("DELETE FROM `{$prefix}ics_forms` WHERE `title` IN ('Delete test draft', 'Delete test final', 'Linked form')");
} catch (Throwable $e) { /* best effort */ }
if ($auditOk) {
    try {
        db_query("DELETE FROM {$auditTable} WHERE `target_type` = 'ics_forms' AND `user_id` IN (?, ?)",
            [$creatorId, $strangerId]);
    } catch (Throwable $e) { /* best effort */ }
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
