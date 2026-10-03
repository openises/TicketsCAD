<?php
/**
 * Phase 155, GH#108 slice S3 -- matching a caller to a Constituent.
 *
 * Findings fixed here:
 *   F7  a real carrier's "+16125551234" never matched a stored "(612) 555-1234"
 *       (the old three-REPLACE LIKE left ')' '.' '+' in place), so every call
 *       created a duplicate bare Constituent and a repeat caller never found
 *       their own history;
 *   F6  a call from one of OUR OWN extensions (a three-digit workstation)
 *       never produced a Constituent, so the maintainer's acceptance sentence
 *       "I want the caller ID from that workstation to be in the constituents"
 *       could not pass with extensions 101-103.
 *
 * Fixtures are created by the REAL writer (api/constituents.php POST through
 * tests/_p155_api_probe.php, which stores the phone exactly as typed) and the
 * resolver is the REAL _p153_resolve_constituent(). The two lookups that must
 * never disagree -- the ring-time resolver and the dispatcher's manual
 * ?phone= lookup -- are both driven for the same inputs.
 *
 * get_variable() caches the settings table per process, so the
 * phone_internal_constituents setting is exercised with one FRESH process per
 * value (tests/_p155_resolve_constituent_probe.php).
 *
 * @requires-db
 * Usage: php tests/test_phone_constituent_match.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/_test_admin.php';
require_once __DIR__ . '/../inc/inbound-calls.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

$prefix = $GLOBALS['db_prefix'] ?? '';
echo "=== Phase 155 / S3 -- Constituent matching for real carrier numbers ===\n\n";

function p155cm_run(array $cmd): ?array {
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($proc)) return null;
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]); fclose($pipes[2]);
    proc_close($proc);
    $d = json_decode(trim((string) $out), true);
    return is_array($d) ? $d : null;
}
function p155cm_api(string $file, string $method, string $action, int $uid, string $payload = ''): ?array {
    return p155cm_run([PHP_BINARY ?: 'php', __DIR__ . '/_p155_api_probe.php', $file, $method, $action, (string) $uid, $payload]);
}
function p155cm_resolve(string $number): ?int {
    $r = p155cm_run([PHP_BINARY ?: 'php', __DIR__ . '/_p155_resolve_constituent_probe.php', $number]);
    return isset($r['id']) && $r['id'] !== null ? (int) $r['id'] : null;
}
function p155cm_count(string $like): int {
    global $prefix;
    return (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}constituents` WHERE `contact` LIKE ?", [$like]);
}

$adminId = test_admin_user_id();
$cIds = []; $extIds = [];
$tag = 'S3TEST' . bin2hex(random_bytes(3));
$origInternal = db_fetch_one("SELECT `value` FROM `{$prefix}settings` WHERE `name` = 'phone_internal_constituents'");
$cleanup = function () use ($prefix, &$cIds, &$extIds, $tag, $origInternal) {
    foreach ($cIds as $id) { try { db_query("DELETE FROM `{$prefix}constituents` WHERE id = ?", [$id]); } catch (Throwable $e) {} }
    try { db_query("DELETE FROM `{$prefix}constituents` WHERE `contact` LIKE ?", ['%' . $tag . '%']); } catch (Throwable $e) {}
    foreach ($extIds as $id) { try { db_query("DELETE FROM `{$prefix}phone_extensions` WHERE id = ?", [$id]); } catch (Throwable $e) {} }
    try {
        if ($origInternal === null || $origInternal === false) {
            db_query("DELETE FROM `{$prefix}settings` WHERE `name` = 'phone_internal_constituents'");
        } else {
            db_query("INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES ('phone_internal_constituents', ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)", [$origInternal['value']]);
        }
    } catch (Throwable $e) {}
    try { db_query("DELETE FROM `{$prefix}newui_audit_log` WHERE `activity` = 'phone_ext_constituents.update' AND `user_name` = 'p155-probe'"); } catch (Throwable $e) {}
};
$cleanup();
register_shutdown_function($cleanup);

try {
    // ── The pure matcher ────────────────────────────────────────────────
    echo "--- phone_digits_match_sql (pure) ---\n\n";
    t('phone_digits drops every non-digit', phone_digits('+1 (612) 555-1234 x5') === '161255512345' && phone_digits('abc') === '');
    t('fewer than four digits is no match at all (the manual lookup\'s long-standing guard)',
        phone_digits_match_sql(['`phone`'], '555') === null && phone_digits_match_sql(['`phone`'], 'call me') === null);
    t('...unless the caller lowers the minimum (used only for our own extensions)',
        phone_digits_match_sql(['`phone`'], '101', 'resolve', 1) !== null);
    $m10 = phone_digits_match_sql(['`phone`'], '+1 (612) 555-1234', 'resolve');
    t('ten or more digits matches on the rightmost TEN of both sides',
        $m10 !== null && $m10['params'] === ['6125551234'] && strpos($m10['sql'], 'RIGHT(') !== false && strpos($m10['sql'], 'CHAR_LENGTH(') !== false);
    $m7 = phone_digits_match_sql(['`phone`'], '555-1234', 'resolve');
    t('fewer than ten digits is exact equality of the digit string (strict: no LIKE)',
        $m7 !== null && $m7['params'] === ['5551234'] && strpos($m7['sql'], 'LIKE') === false);
    $s7 = phone_digits_match_sql(['`phone`'], '555-1234', 'search');
    t('search mode is a superset of resolve mode (adds a contains match)',
        $s7 !== null && strpos($s7['sql'], 'LIKE') !== false && in_array('5551234', $s7['params'], true) && in_array('%5551234%', $s7['params'], true));
    $threw = false;
    try { phone_digits_match_sql(['`phone`; DROP TABLE constituents; --'], '6125551234'); } catch (InvalidArgumentException $e) { $threw = true; }
    t('an untrusted column identifier is refused (the identifier is interpolated, so it is validated)', $threw);
    t('a number with an absurd digit count never matches', phone_digits_match_sql(['`phone`'], str_repeat('9', 30)) === null);

    // ── Real fixtures through the real writer ───────────────────────────
    echo "\n--- one person, many spellings (real writer + real resolver) ---\n\n";
    $area = (string) random_int(200, 989);
    $mid  = (string) random_int(200, 989);
    $last = (string) random_int(1000, 9999);
    $stored = "($area) $mid-$last";
    $r = p155cm_api('api/constituents.php', 'POST', '', $adminId, json_encode(['contact' => $tag . ' Person', 'phone' => $stored]));
    $personId = (int) ($r['body']['id'] ?? ($r['body']['constituent']['id'] ?? 0));
    if ($personId <= 0) {
        $personId = (int) db_fetch_value("SELECT id FROM `{$prefix}constituents` WHERE contact = ? ORDER BY id DESC LIMIT 1", [$tag . ' Person']);
    }
    $cIds[] = $personId;
    t('the real writer stored "' . $stored . '" exactly as typed',
        $personId > 0 && db_fetch_value("SELECT `phone` FROM `{$prefix}constituents` WHERE id = ?", [$personId]) === $stored, json_encode($r));

    $digits = $area . $mid . $last;
    $spellings = [
        'E.164'               => "+1$digits",
        'plain digits'        => $digits,
        'dashed'              => "$area-$mid-$last",
        'dotted'              => "$area.$mid.$last",
        'parenthesised'       => "($area) $mid-$last",
        'country code, dashed'=> "1-$area-$mid-$last",
        'spaced'              => "$area $mid $last",
        'international prefix'=> "+1 ($area) $mid $last",
    ];
    $before = p155cm_count($tag . '%') + p155cm_count('%' . $digits . '%');
    foreach ($spellings as $label => $in) {
        $id = _p153_resolve_constituent($in);
        t("resolver: $label -> the ONE existing Constituent", $id === $personId, "got " . var_export($id, true));
    }
    t('...and none of those calls created a duplicate bare Constituent',
        (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}constituents` WHERE `phone` LIKE ? OR `contact` LIKE ?", ["%$mid%$last%", "%$digits%"]) === 1);

    echo "\n--- the manual lookup agrees (api/constituents.php?phone=) ---\n\n";
    foreach (['E.164' => "+1$digits", 'dotted' => "$area.$mid.$last", 'dashed' => "$area-$mid-$last"] as $label => $in) {
        $r = p155cm_api('api/constituents.php', 'GET', '', $adminId, 'phone=' . rawurlencode($in));
        $ids = array_map(function ($row) { return (int) $row['id']; }, $r['body']['constituents'] ?? []);
        t("manual lookup: $label finds the same Constituent", in_array($personId, $ids, true), json_encode($ids));
    }
    $r = p155cm_api('api/constituents.php', 'GET', '', $adminId, 'phone=' . rawurlencode("$mid-$last"));
    $ids = array_map(function ($row) { return (int) $row['id']; }, $r['body']['constituents'] ?? []);
    t('manual lookup keeps its lenient behaviour: the typed seven-digit local number still finds the ten-digit one',
        in_array($personId, $ids, true), json_encode($ids));
    $viaResolver = _p153_resolve_constituent("$mid-$last");
    t('...while the strict resolver does NOT guess from seven digits (it would create a new bare record instead)',
        $viaResolver !== $personId && $viaResolver !== null);
    if ($viaResolver) { $cIds[] = $viaResolver; }

    $r = p155cm_api('api/constituents.php', 'GET', '', $adminId, 'phone=' . rawurlencode('55'));
    t('fewer than four digits in the manual lookup is still an empty result', ($r['body']['constituents'] ?? null) === []);

    // A different area code with the same last seven is NOT the same person.
    $otherArea = $area === '612' ? '651' : '612';
    $otherId = _p153_resolve_constituent("$otherArea-$mid-$last");
    if ($otherId) { $cIds[] = $otherId; }
    t('the same last seven digits with a DIFFERENT area code is a different person (strict resolver)',
        $otherId !== null && $otherId !== $personId);
    $otherAgain = _p153_resolve_constituent("+1$otherArea$mid$last");
    t('...and that second person is then found again by any spelling (no duplicate)', $otherAgain === $otherId);

    // Short caller that is not an extension: still a no-op.
    $shortThrew = _p153_resolve_constituent('555');
    t('a three-digit caller that is NOT one of our extensions still creates nothing', $shortThrew === null);
    t('a null caller is a no-op', _p153_resolve_constituent(null) === null && _p153_resolve_constituent('') === null);

    // ── F6: calls from OUR OWN extensions ────────────────────────────────
    echo "\n--- calls from our own extensions (F6) ---\n\n";
    $extNum = null;
    for ($i = 0; $i < 50; $i++) {
        $cand = (string) random_int(100, 899);
        if (!db_fetch_value("SELECT COUNT(*) FROM `{$prefix}phone_extensions` WHERE extension = ?", [$cand])
            && !db_fetch_value("SELECT COUNT(*) FROM `{$prefix}constituents` WHERE phone = ?", [$cand])) { $extNum = $cand; break; }
    }
    require_once __DIR__ . '/../inc/phone-extensions.php';
    $ce = phone_extension_create($extNum, $tag . ' Desk', false, '', null);
    $extIds[] = $ce['id'];

    // No stored row = the default. It is exercised in a fresh process below.
    db_query("DELETE FROM `{$prefix}settings` WHERE `name` = 'phone_internal_constituents'");
    $cid1 = p155cm_resolve($extNum);
    if ($cid1) { $cIds[] = $cid1; }
    t('a THREE-digit extension caller now resolves to a Constituent (the acceptance sentence can pass with extensions 101-103)',
        $cid1 !== null && $cid1 > 0);
    $row = $cid1 ? db_fetch_one("SELECT contact, phone FROM `{$prefix}constituents` WHERE id = ?", [$cid1]) : null;
    t('...named from the extension\'s real label, with the extension number as its phone (nothing fabricated)',
        $row && $row['phone'] === $extNum && strpos($row['contact'], $tag . ' Desk') === 0 && strpos($row['contact'], "ext $extNum") !== false, json_encode($row));
    $cid2 = p155cm_resolve($extNum);
    t('...and the next call from that extension finds the same record (no duplicate)', $cid2 === $cid1);
    t('...matched EXACTLY on the extension (a stored number merely containing it does not match)',
        p155cm_resolve($extNum . '9') !== $cid1);
    foreach ([$extNum . '9'] as $n) { $x = p155cm_resolve($n); if ($x) { $cIds[] = $x; } }

    // The setting off: one fresh process per value.
    db_query("INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES ('phone_internal_constituents', '0') ON DUPLICATE KEY UPDATE `value` = '0'");
    $countBefore = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}constituents`");
    $off = p155cm_resolve($extNum);
    t('with phone_internal_constituents = 0 a call from the extension resolves to NO Constituent (an agency can keep desks out of the public list)',
        $off === null);
    t('...and creates nothing', (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}constituents`") === $countBefore);
    db_query("DELETE FROM `{$prefix}settings` WHERE `name` = 'phone_internal_constituents'");
    t('...deleting the row restores the default (ON)', p155cm_resolve($extNum) === $cid1);
    db_query("INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES ('phone_internal_constituents', '1') ON DUPLICATE KEY UPDATE `value` = '1'");
    t('...and an explicit 1 is ON', p155cm_resolve($extNum) === $cid1);

    // The writer endpoint (Super Admin only, audited).
    echo "\n--- phone_internal_constituents writer ---\n\n";
    $r = p155cm_api('api/phone-extensions.php', 'POST', 'internal_constituents_save', $adminId, json_encode(['enabled' => false]));
    t('Super Admin turns it off through the endpoint', ($r['status'] ?? 0) === 200 && ($r['body']['internal_constituents'] ?? null) === false, json_encode($r));
    t('...and it is stored as the literal 0', db_fetch_value("SELECT `value` FROM `{$prefix}settings` WHERE `name` = 'phone_internal_constituents'") === '0');
    t('...a fresh process reads it as OFF (the "0 is falsy" trap is avoided)', p155cm_resolve($extNum) === null);
    $aud = db_fetch_value("SELECT COUNT(*) FROM `{$prefix}newui_audit_log` WHERE `activity` = 'phone_ext_constituents.update' AND `user_name` = 'p155-probe'");
    t('...and audited', (int) $aud === 1);
    $r = p155cm_api('api/phone-extensions.php', 'POST', 'internal_constituents_save', $adminId, json_encode(['enabled' => true, 'csrf_token' => 'bogus']));
    t('a bad CSRF token is refused', ($r['status'] ?? 0) === 403);
    $src = (string) file_get_contents(__DIR__ . '/../api/phone-extensions.php');
    t('the action needs action.manage_config with no is_admin() fallback',
        (bool) preg_match("/internal_constituents_save.*?rbac_can\\('action\\.manage_config'\\)/s", $src));

    echo "\n--- structure ---\n\n";
    $inbound = (string) file_get_contents(__DIR__ . '/../inc/inbound-calls.php');
    $cons    = (string) file_get_contents(__DIR__ . '/../api/constituents.php');
    t('the ring-time resolver and the manual lookup both use phone_digits_match_sql (one definition)',
        strpos($inbound, 'phone_digits_match_sql(') !== false && strpos($cons, 'phone_digits_match_sql(') !== false);
    $adminPage = (string) file_get_contents(__DIR__ . '/../phone-extensions-admin.php');
    $adminJs   = (string) file_get_contents(__DIR__ . '/../assets/js/phone-extensions-admin.js');
    t('the admin card has the switch, and its script saves through internal_constituents_save',
        strpos($adminPage, 'id="peInternalConstituents"') !== false && strpos($adminJs, "apiPost('internal_constituents_save'") !== false);
    $r = p155cm_api('api/phone-extensions.php', 'GET', 'pbx_settings', $adminId);
    t('the settings the card loads carry the stored value as a boolean (the switch reflects the real setting)',
        isset($r['body']['settings']['internal_constituents']) && is_bool($r['body']['settings']['internal_constituents']), json_encode($r['body']['settings'] ?? null));
    t('neither carries its own hand-rolled three-REPLACE LIKE any more',
        strpos($inbound, "REPLACE(REPLACE(REPLACE(`phone`") === false && strpos($cons, "REPLACE(REPLACE(REPLACE(`phone`") === false);
} finally {
    $cleanup();
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
