<?php
/**
 * GH#59 follow-up (cbyrdmo, 2026-08-14) — the Incident Page fix (77d26c5,
 * tests/test_gh59_incident_status_pick.php) did not cover the Dashboard's
 * own "Responding / On Scene / Clear" quick-action buttons
 * (assets/js/app.js, _renderStatusModalBody). That control only ever sent
 * the bare action name ('on_scene') to api/incident-assign.php, never a
 * specific un_status id — so on an install with more than one status
 * mapped to the same action (e.g. "On Scene" + a custom "In Area" alias,
 * both incident_action='on_scene'), api/incident-assign.php's own
 * precedence rule ($newStatusStr !== '' ? $newStatusStr : ...) always won
 * on the string, and assign_update_status_internal() fell through to
 * "whichever status mapped to this action sorts first" — reproducing the
 * exact "picked On Scene, got In Area" symptom from a sibling code path
 * the original fix didn't (and structurally couldn't) reach.
 *
 * Fix (assets/js/app.js): resolve the specific un_status row(s) mapped to
 * the clicked action via _loadUnStatusOptions() before submitting.
 *   - Exactly one match  -> auto-submit new_status_id (no new_status string,
 *     so the endpoint's own precedence resolves to the numeric path).
 *   - Zero matches       -> fall back to the legacy new_status string path.
 *   - More than one match -> replace the generic button with one button per
 *     specific status (mirrors the "Or change the unit's overall status"
 *     list, which already worked correctly for exactly this reason).
 *
 * This file covers two things the Incident Page test does not:
 *   1. The JS decision logic itself (tokenized structural check — not a
 *      naive grep, which this project's own history shows a comment can
 *      trip; see the schema-mismatch / tile_mode pitfalls in CLAUDE.md).
 *   2. api/incident-assign.php's precedence rule really does resolve a
 *      new_status_id-only payload (no new_status key at all, matching
 *      exactly what the fixed JS now sends) to the numeric path, end to
 *      end through assign_update_status_internal() -- the original test
 *      called the writer directly with an int, which proves the writer is
 *      correct but not that the endpoint's string-vs-id precedence forwards
 *      an id-only payload the same way.
 *
 * @requires-db
 */

$root = dirname(__DIR__);
require_once $root . '/config.php';

$pass = 0; $fail = 0;
function t59($name, $ok, $detail = '') {
    global $pass, $fail;
    if ($ok) { $pass++; echo "[PASS] $name\n"; }
    else     { $fail++; echo "[FAIL] $name" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

echo "=== GH#59 dashboard quick-select regression ===\n\n";

// ── 1. Structural check on the real JS (tokenized, not a bare substring
//       search a comment could satisfy) ──
$jsPath = $root . '/assets/js/app.js';
$jsSrc  = (string) file_get_contents($jsPath);

// Tokenizing real JS in PHP isn't available (no JS tokenizer in core PHP),
// so instead assert on the ACTUAL CODE SHAPE with anchored regexes tight
// enough that a docblock/comment mentioning these names in prose could not
// satisfy them (each requires the exact call syntax, not just the words).
t59('_loadUnStatusOptions() is consulted before submitting a step change',
    (bool) preg_match('/_loadUnStatusOptions\(\)\.then\(function \(options\) \{\s*\n\s*var matches = options\.filter/', $jsSrc));
t59('a SINGLE match auto-submits with the specific status id (matches[0].id)',
    strpos($jsSrc, '_postAssignStep(btn, aid, tid, handle, step, matches[0].id, matches[0].status_val)') !== false);
t59('MULTIPLE matches trigger the explicit chooser, not a silent pick',
    strpos($jsSrc, '_showAssignStepChoices(btn, aid, tid, handle, step, matches)') !== false);
t59('ZERO matches falls back to the legacy action-name path (no regression for unmapped installs)',
    strpos($jsSrc, '_postAssignStep(btn, aid, tid, handle, step, null, null)') !== false);
t59('_postAssignStep sends new_status_id (not the bare action string) when a specific id is resolved',
    (bool) preg_match('/if \(statusId\) \{\s*\n\s*payload\.new_status_id = statusId;\s*\n\s*\} else \{\s*\n\s*payload\.new_status = step;/', $jsSrc));
t59('the chooser renders one button per specific status option, each carrying its own id',
    strpos($jsSrc, '_postAssignStep(btn, aid, tid, handle, step, o.id, o.status_val)') !== false);

// NEGATIVE CONTROL — prove the structural check can actually fail. Simulate
// the pre-fix shape (always sends the bare step name) and confirm the
// "sends new_status_id" assertion would have failed against it.
$crippled = str_replace(
    "if (statusId) {\n            payload.new_status_id = statusId;\n        } else {\n            payload.new_status = step;\n        }",
    "payload.new_status = step;",
    $jsSrc
);
t59('negative control: crippled source no longer contains the id-preferring branch (proves the check above is not vacuous)',
    $crippled !== $jsSrc
    && !preg_match('/if \(statusId\) \{\s*\n\s*payload\.new_status_id = statusId;/', $crippled));

// ── 2. End-to-end: does api/incident-assign.php's own precedence logic
//       resolve a new_status_id-ONLY payload (no new_status key at all,
//       exactly what the fixed JS sends) to the numeric path? ──
$apiSrc = (string) file_get_contents($root . '/api/incident-assign.php');
if (!preg_match(
    '/\$newStatusStr = trim\(\(string\) \(\$input\[\'new_status\'\] \?\? \'\'\)\);\s*\n\s*'
    . '\$newStatusId\s+= \(int\) \(\$input\[\'new_status_id\'\] \?\? 0\);\s*\n\s*'
    . '\$statusInput\s+= \$newStatusStr !== \'\'\s*\n\s*'
    . '\? \$newStatusStr\s*\n\s*: \(\$newStatusId > 0 \? \$newStatusId : \'\'\);/',
    $apiSrc,
    $m
)) {
    t59('located api/incident-assign.php\'s new_status/new_status_id precedence block', false,
        'block not found verbatim — endpoint may have been refactored; update this test\'s pattern');
} else {
    t59('located api/incident-assign.php\'s new_status/new_status_id precedence block', true);

    // Replicate the exact precedence expression against three inputs shaped
    // like real request bodies, proving the contract the new JS relies on:
    // an id-only payload (no 'new_status' key present at all) resolves to
    // the numeric path, not the empty-string / error path.
    function _gh59_resolve_status_input(array $input) {
        $newStatusStr = trim((string) ($input['new_status'] ?? ''));
        $newStatusId  = (int) ($input['new_status_id'] ?? 0);
        return $newStatusStr !== ''
            ? $newStatusStr
            : ($newStatusId > 0 ? $newStatusId : '');
    }

    $idOnly = _gh59_resolve_status_input(['assign_id' => 5, 'new_status_id' => 42]);
    t59('an id-only payload (the shape the fixed JS now sends) resolves to the INT, not empty string',
        $idOnly === 42, var_export($idOnly, true));

    $legacyStringOnly = _gh59_resolve_status_input(['assign_id' => 5, 'new_status' => 'on_scene']);
    t59('a legacy string-only payload (the zero-matches fallback shape) still resolves to the string',
        $legacyStringOnly === 'on_scene', var_export($legacyStringOnly, true));

    $neitherSupplied = _gh59_resolve_status_input(['assign_id' => 5]);
    t59('a payload with neither key resolves to empty string (existing "Invalid status" error path, unchanged)',
        $neitherSupplied === '', var_export($neitherSupplied, true));
}

// ── 3. Full path through the real writer, using the id-only resolution
//       above as input — proves the numeric id survives all the way to the
//       responder row, exactly as the Incident Page test already proved
//       for the direct-int call shape, but this time via the string/id
//       CHOICE the endpoint makes, not by skipping straight to the writer. ──
try {
    db_fetch_value('SELECT 1');
} catch (Throwable $e) {
    echo "SKIP: no database connection (" . $e->getMessage() . ")\n";
    echo "\n$pass passed, $fail failed\n";
    exit($fail > 0 ? 1 : 0);
}

require_once $root . '/inc/assignment-write.php';
$prefix = $GLOBALS['db_prefix'] ?? '';
$_SESSION = ['user_id' => 1, 'user' => 'admin', 'level' => 0];

$tid = 0; $rid = 0; $statOnScene = 0; $statInArea = 0; $assignId = 0;
try {
    $typeId = (int) db_fetch_value("SELECT id FROM `{$prefix}in_types` ORDER BY id LIMIT 1");
    db_query("INSERT INTO `{$prefix}ticket` (in_types_id, status, severity, scope, description, date, problemstart, _by)
              VALUES (?, 2, 0, 'gh59 dash fixture', 'GH#59 dashboard regression fixture', NOW(), NOW(), 1)", [$typeId]);
    $tid = (int) db_insert_id();

    db_query("INSERT INTO `{$prefix}responder` (name, handle, description, un_status_id, status_updated, updated)
              VALUES ('gh59d_unit', 'GH59D', 'test', 1, NOW(), NOW())");
    $rid = (int) db_insert_id();

    // "In Area" sorts FIRST (lower sort/id) -- same shape as cbyrdmo's
    // report, where the ambiguous auto-pick landed on "In Area" instead of
    // the "On Scene" the dispatcher actually clicked.
    db_query("INSERT INTO `{$prefix}un_status`
              (status_val, description, dispatch, watch, hide, excl_from_reset, `group`, sort,
               bg_color, text_color, incident_action, resets_par)
              VALUES ('gh59d In Area', 'test', 0, 0, 'n', 'n', 'busy', 1,
                      'transparent', '#000000', 'on_scene', 0)");
    $statInArea = (int) db_insert_id();

    db_query("INSERT INTO `{$prefix}un_status`
              (status_val, description, dispatch, watch, hide, excl_from_reset, `group`, sort,
               bg_color, text_color, incident_action, resets_par)
              VALUES ('gh59d On Scene', 'test', 0, 0, 'n', 'n', 'busy', 2,
                      'transparent', '#000000', 'on_scene', 0)");
    $statOnScene = (int) db_insert_id();

    $created = assign_create_internal($tid, $rid, '', 1);
    $assignId = (int) ($created['id'] ?? 0);
    t59('fixture unit assigned via the real writer', $assignId > 0);

    // Simulate the fixed dashboard JS: it resolved TWO matches for
    // incident_action='on_scene', the dispatcher clicked the "gh59d On
    // Scene" choice button, so ONLY new_status_id is sent (no new_status
    // string at all) -- exactly the payload shape section 2 proved
    // resolves to the numeric path.
    $resolved = _gh59_resolve_status_input(['assign_id' => $assignId, 'new_status_id' => $statOnScene]);
    $r = assign_update_status_internal($assignId, $resolved, 1);
    t59('assign_update_status_internal() accepts the endpoint-resolved value without error',
        empty($r['errors']), json_encode($r));

    $got = (int) db_fetch_value("SELECT un_status_id FROM `{$prefix}responder` WHERE id = ?", [$rid]);
    t59('the status the dispatcher actually clicked is written, not the lower-sorted "In Area" sibling',
        $got === $statOnScene,
        "picked {$statOnScene}, got {$got}"
        . ($got === $statInArea ? ' (collapsed to the ambiguous auto-pick — the exact dashboard bug)' : ''));
} catch (Exception $e) {
    echo "[FAIL] fixture threw: " . $e->getMessage() . "\n"; $fail++;
}

// Teardown.
try {
    if ($assignId > 0) db_query("DELETE FROM `{$prefix}assigns` WHERE id = ?", [$assignId]);
    if ($tid > 0) db_query("DELETE FROM `{$prefix}ticket` WHERE id = ?", [$tid]);
    if ($rid > 0) db_query("DELETE FROM `{$prefix}responder` WHERE id = ?", [$rid]);
    foreach ([$statInArea, $statOnScene] as $sid) {
        if ($sid > 0) db_query("DELETE FROM `{$prefix}un_status` WHERE id = ?", [$sid]);
    }
} catch (Exception $e) {
    echo "Teardown warning: " . $e->getMessage() . "\n";
}

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
