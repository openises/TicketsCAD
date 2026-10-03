<?php
/**
 * Phase 155 (GH#145) - the two Email List options.
 *
 *   email_list_skip_member_statuses   JSON array of lower-cased member_status
 *                                     labels whose members are NOT emailed
 *                                     through a list. Seeded with whichever of
 *                                     "suspended" / "retired" exist among the
 *                                     labels on this install (else []).
 *   email_list_require_email_on_add   '0' (default) = a member or contact with
 *                                     no email can be added and is flagged;
 *                                     '1' = refuse to add one.
 *
 * Why the default skip list is not empty: a retired volunteer should not keep
 * receiving incident details by email once lists start working. It is visible
 * (row badge, banner line, list-table count) and one click to change in
 * Settings -> Email Lists -> List options.
 *
 * Idempotent: an existing row is never overwritten (an administrator may have
 * chosen a value). Verifies its own outcome and exits non-zero if either row is
 * missing or unreadable afterwards - a migration that prints a failure and
 * exits 0 is a migration that never ran.
 *
 * Self-sufficient in any order: it needs only `settings`, which always exists,
 * and it tolerates a missing `member_status` table (seeds []).
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config.php';

echo "Phase 155 - email list options\n";
echo "===============================\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';
$SKIP = 'email_list_skip_member_statuses';
$REQ  = 'email_list_require_email_on_add';

$exists = static function (string $name) use ($prefix): bool {
    return (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}settings` WHERE `name` = ?", [$name]) > 0;
};

try {
    if (!$exists($SKIP)) {
        $labels = [];
        try {
            foreach (db_fetch_all("SELECT `status_val` FROM `{$prefix}member_status`") as $r) {
                $l = strtolower(trim((string) $r['status_val']));
                if ($l === 'suspended' || $l === 'retired') $labels[$l] = true;
            }
        } catch (Throwable $e) {
            echo "[--] member_status not readable (" . get_class($e) . ") - seeding an empty skip list\n";
        }
        $json = json_encode(array_keys($labels));
        db_query("INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES (?, ?)", [$SKIP, $json]);
        echo "[OK] seeded {$SKIP} = {$json}\n";
    } else {
        echo "[SKIP] {$SKIP} already set\n";
    }

    if (!$exists($REQ)) {
        db_query("INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES (?, '0')", [$REQ]);
        echo "[OK] seeded {$REQ} = 0\n";
    } else {
        echo "[SKIP] {$REQ} already set\n";
    }
} catch (Throwable $e) {
    echo "[FAIL] " . $e->getMessage() . "\n";
    exit(1);
}

// Verify the outcome: ask the database, not the log lines just printed.
$bad = [];
$skipVal = db_fetch_value("SELECT `value` FROM `{$prefix}settings` WHERE `name` = ?", [$SKIP]);
if ($skipVal === false || $skipVal === null || !is_array(json_decode((string) $skipVal, true))) {
    $bad[] = "{$SKIP} missing or not a JSON array";
}
$reqVal = db_fetch_value("SELECT `value` FROM `{$prefix}settings` WHERE `name` = ?", [$REQ]);
if ($reqVal === false || $reqVal === null || ((string) $reqVal !== '0' && (string) $reqVal !== '1')) {
    $bad[] = "{$REQ} missing or not 0/1";
}
if ($bad) {
    foreach ($bad as $b) echo "[FAIL] {$b}\n";
    exit(1);
}

echo "\nDone - both options verified.\n";
exit(0);
