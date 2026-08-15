<?php
/**
 * GH#61 (rjonesbsink, 2026-08-15) — the After Action report's action-log
 * timeline rows always showed a blank Details cell. api/reports.php:838 read
 * $act['action'] to build each row, but the `action` table has no `action`
 * column -- the narrative lives in `description`. PHP's `??` turned the
 * always-missing key into an empty string instead of a warning, so nothing
 * errored and the report looked like it worked while every row's details
 * came through blank. A smaller, second issue in the same three lines: 'who'
 * showed the raw `user` id (e.g. "3") rather than a name.
 *
 * @requires-db
 */

$root = dirname(__DIR__);
require_once $root . '/config.php';

$pass = 0; $fail = 0;
function test61(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "[PASS] $name\n"; }
    else     { $fail++; echo "[FAIL] $name" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

// Source-level: the exact defect (reading a column that doesn't exist) must
// not be reachable again, and the fix (reading `description`) must be there.
$reportsApi = file_get_contents($root . '/api/reports.php');
if (!preg_match('/\/\/ Action log entries.*?foreach \(\$actions_data as \$act\) \{.*?\n        \];/s', $reportsApi, $m)) {
    echo "[FAIL] could not isolate the action-log timeline block from api/reports.php — file structure changed?\n";
    echo "\n1 passed, 1 failed\n";
    exit(1);
}
$block = $m[0];
test61("reads the real 'description' column, not the nonexistent 'action' column",
    strpos($block, "\$act['description']") !== false
    && strpos($block, "\$act['action']") === false,
    'expected $act[\'description\'], the `action` table has no `action` column');
test61('resolves the user id to a display name (performed_by_name), not the raw id',
    strpos($block, "\$act['performed_by_name']") !== false
    && strpos($block, "\$act['user']") === false);
test61('the query LEFT JOINs user and falls back to the login name, matching api/equipment.php\'s own pattern',
    strpos($block, 'LEFT JOIN') !== false
    && strpos($block, "COALESCE(NULLIF(TRIM(CONCAT(u.name_f, ' ', u.name_l)), ''), u.`user`)") !== false);

try {
    db_fetch_value('SELECT 1');
} catch (Throwable $e) {
    echo "SKIP: no database connection (" . $e->getMessage() . ")\n";
    echo "\n$pass passed, $fail failed\n";
    exit($fail > 0 ? 1 : 0);
}

$prefix = $GLOBALS['db_prefix'] ?? '';

// Functional: drive the REAL fixed query (extracted from the block above)
// against a real ticket that has at least one action-log row with a real
// description, and confirm the timeline entry actually carries the text —
// not just that the source says the right column name.
if (!preg_match('/"(SELECT `a`\.\*.*?ORDER BY `a`\.`date`)"/s', $block, $qm)) {
    echo "[FAIL] could not extract the actual SQL string to drive functionally\n";
} else {
    $sql = str_replace('{$prefix}', $prefix, $qm[1]);

    $row = db_fetch_one(
        "SELECT `ticket_id` FROM `{$prefix}action`
          WHERE `description` IS NOT NULL AND `description` != ''
          ORDER BY `id` DESC LIMIT 1"
    );

    if (!$row) {
        echo "SKIP: no action-log row with a real description to test against\n";
    } else {
        $actions = db_fetch_all($sql, [(int) $row['ticket_id']]);
        test61('the real query returns at least one row for a ticket with action-log entries',
            count($actions) > 0);
        if ($actions) {
            $act = $actions[0];
            $timelineDetails = $act['description'] ?? '';
            test61('the timeline row\'s details are non-empty, driven through the real fixed query',
                trim($timelineDetails) !== '',
                'description key resolved to: ' . var_export($timelineDetails, true));
            test61('performed_by_name key exists on the row (used for "who")',
                array_key_exists('performed_by_name', $act));
        }
    }
}

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
