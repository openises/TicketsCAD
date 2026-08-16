<?php
/**
 * Phase 111 Slice C — per-person ICS-214 pulls radio-attributed reports.
 *
 * The builder's source (4) surfaces `action` rows attributed to a member via
 * author_member_id (Phase 111 Link 1/3) — a volunteer's Meshtastic/Zello/DMR
 * field reports, which often have no user_id and were previously invisible to
 * their 214. Tests seed real action rows and call ics214_build_timeline()
 * directly (no HTTP/auth), then clean up.
 *
 * Source (3) (user-authored) previously appeared to need a "user.member_id"
 * link -- that was actually two real bugs (GH#64 investigation, 2026-08-15):
 * `user` has no `member_id` column (the real column is `member`), and the
 * endpoint never populated $responder['member_id'] in the first place. Both
 * fixed in inc/ics214_timeline.php + api/ics214-par-export.php; source (3)
 * is now exercised live below, same as source (4).
 *
 * Usage: php tests/test_ics214_author_source.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/ics214_timeline.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$passed = 0; $failed = 0;
function t($l, $c) { global $passed, $failed; echo ($c ? "[PASS] " : "[FAIL] ") . $l . "\n"; $c ? $passed++ : $failed++; }

echo "=== Phase 111 Slice C — ICS-214 radio-attributed source ===\n\n";

$TEST_MEMBER = 990112;                 // synthetic member id; no member row needed
$from = date('Y-m-d 00:00:00', strtotime('-1 day'));
$to   = date('Y-m-d 23:59:59');
$stamp = date('Y-m-d H:i:s');

// Guard: this test needs the Slice-A author_member_id column.
$hasCol = (bool) db_fetch_one(
    "SELECT COLUMN_NAME FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME='author_member_id'",
    [$prefix . 'action']);
if (!$hasCol) { t('SKIP — action.author_member_id absent (pre-Slice-A DB)', true);
    echo "\n=== $passed passed, $failed failed ===\n"; exit(0); }

// Clean slate for this synthetic member.
db_query("DELETE FROM `{$prefix}action` WHERE author_member_id = ?", [$TEST_MEMBER]);

function seed_action($prefix, $member, $when, $text, $channel) {
    // The action note text lives in `description` (NOT NULL); author user in `user`.
    db_query("INSERT INTO `{$prefix}action`
                (`date`,`description`,`author_member_id`,`source_channel`,`ticket_id`,`user`)
              VALUES (?,?,?,?,0,0)",
        [$when, $text, $member, $channel]);
}

seed_action($prefix, $TEST_MEMBER, date('Y-m-d 10:00:00'), 'crowd heavy at bandshell', 'dmr');
seed_action($prefix, $TEST_MEMBER, date('Y-m-d 10:05:00'), 'first aid administered zone 2', 'zello');
seed_action($prefix, $TEST_MEMBER, date('Y-m-d 10:10:00'), 'radio check ok', 'local_chat');

$responder = ['id' => 999112, 'name' => 'Test Unit', 'handle' => 'T99', 'callsign' => '', 'member_id' => $TEST_MEMBER];
$entries = ics214_build_timeline($responder, 999112, $from, $to, 0);

// Only our seeded rows should be attributable to this synthetic member.
$radio = array_values(array_filter($entries, function ($e) { return $e['source'] === 'radio'; }));
t('radio-attributed rows surface in the timeline (3)', count($radio) === 3);

$byText = [];
foreach ($radio as $e) { $byText[] = $e['note']; }
$joined = implode(' || ', $byText);
t('DMR report carries a channel tag ("reported via DMR")',
    strpos($joined, 'crowd heavy at bandshell — reported via DMR') !== false);
t('Zello report carries a channel tag ("reported via ZELLO")',
    strpos($joined, 'first aid administered zone 2 — reported via ZELLO') !== false);
t('local_chat report has NO channel tag',
    strpos($joined, 'radio check ok') !== false &&
    strpos($joined, 'radio check ok — reported via') === false);

// Chronological ordering across the timeline.
$sorted = true;
for ($i = 1; $i < count($entries); $i++) { if ($entries[$i - 1]['t'] > $entries[$i]['t']) $sorted = false; }
t('timeline is chronologically ordered', $sorted);

// A responder with no member (member_id 0) yields no radio rows.
$noMember = ics214_build_timeline(['id' => 1, 'member_id' => 0], 1, $from, $to, 0);
$noneRadio = count(array_filter($noMember, function ($e) { return $e['source'] === 'radio'; })) === 0;
t('member_id 0 → no radio-attributed rows (no accidental attribution)', $noneRadio);

// ticket filter: a mismatched ticket_id excludes our ticket_id=0 rows.
$filtered = ics214_build_timeline($responder, 999112, $from, $to, 424242);
$filteredRadio = count(array_filter($filtered, function ($e) { return $e['source'] === 'radio'; }));
t('ticket filter excludes rows on other incidents', $filteredRadio === 0);

// ── Source (3): action-log entries authored by this responder's own user
//    account, resolved via user.member = member.id (GH#64 fix — was
//    user.member_id, a column that never existed). Reuses $TEST_MEMBER so
//    this also exercises the de-dup guard for real: one row is authored
//    by the user only, a second row is BOTH user-authored AND
//    author_member_id-attributed (e.g. a dispatcher typing a note that
//    also carries radio-attribution metadata) and must appear exactly
//    once, not twice.
db_query("DELETE FROM `{$prefix}user` WHERE `user` = '__gh64_ics214_test_user'");
db_query(
    "INSERT INTO `{$prefix}user` (`user`, `passwd`, `member`) VALUES ('__gh64_ics214_test_user', 'x', ?)",
    [$TEST_MEMBER]
);
$testUserId = (int) db_insert_id();

db_query(
    "INSERT INTO `{$prefix}action` (`date`, `description`, `user`, `ticket_id`)
     VALUES (?, 'user-authored note, no radio attribution', ?, 0)",
    [date('Y-m-d 10:15:00'), $testUserId]
);
db_query(
    "INSERT INTO `{$prefix}action` (`date`, `description`, `user`, `author_member_id`, `ticket_id`)
     VALUES (?, 'dual-attributed note', ?, ?, 0)",
    [date('Y-m-d 10:20:00'), $testUserId, $TEST_MEMBER]
);

$entriesWithUser = ics214_build_timeline($responder, 999112, $from, $to, 0);
$actionNotes = array_map(fn($e) => $e['note'], array_filter($entriesWithUser, fn($e) => $e['source'] === 'action'));
t('source (3): user-authored-only note surfaces exactly once',
    count(array_filter($actionNotes, fn($n) => $n === 'user-authored note, no radio attribution')) === 1,
    'notes: ' . implode(' | ', $actionNotes));

$radioNotes = array_map(fn($e) => $e['note'], array_filter($entriesWithUser, fn($e) => $e['source'] === 'radio'));
$dualCount = count(array_filter(array_merge($actionNotes, $radioNotes), fn($n) => strpos($n, 'dual-attributed note') === 0));
t('source (3)/(4) de-dup: a row matching BOTH user and author_member_id appears exactly once total',
    $dualCount === 1, 'total occurrences: ' . $dualCount . ' — action: ' . implode(' | ', $actionNotes) . ' radio: ' . implode(' | ', $radioNotes));

db_query("DELETE FROM `{$prefix}user` WHERE `id` = ?", [$testUserId]);

// De-dup guard exists in the builder (still assert the mechanism directly,
// on top of the live proof above).
$builderSrc = @file_get_contents(__DIR__ . '/../inc/ics214_timeline.php');
t('builder de-dups source 4 against source 3 by action.id',
    $builderSrc !== false &&
    strpos($builderSrc, '$seenActionIds[(int) $row[\'id\']] = true') !== false &&
    strpos($builderSrc, "if (isset(\$seenActionIds[(int) \$row['id']])) continue") !== false);

// The endpoint still enforces its IDOR gate (self-or-admin) after the refactor.
$epSrc = @file_get_contents(__DIR__ . '/../api/ics214-par-export.php');
t('endpoint keeps the IDOR gate (self-or-admin) + calls the builder',
    $epSrc !== false &&
    strpos($epSrc, 'if (!is_admin())') !== false &&
    strpos($epSrc, 'ics214_build_timeline(') !== false);

// Cleanup.
db_query("DELETE FROM `{$prefix}action` WHERE author_member_id = ?", [$TEST_MEMBER]);
db_query("DELETE FROM `{$prefix}action` WHERE `description` = 'user-authored note, no radio attribution'");

echo "\n=== $passed passed, $failed failed ===\n";
exit($failed === 0 ? 0 : 1);
