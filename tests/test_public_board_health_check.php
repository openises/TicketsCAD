<?php
/**
 * Phase 138 — Public incident board: health_check_public_board() (tasks.md
 * H1/H3).
 *
 * Per the 2026-08-02 lesson this whole check exists to honour: a reassuring
 * status code proves nothing — only a structural comparison against the
 * live database does. Every scenario below inserts REAL rows into the REAL
 * `ticket`/`in_types`/`organizations` tables (same pattern as
 * tests/test_public_board_eligibility.php and
 * tests/test_public_board_org_scope.php — never a hand-simulated filter),
 * starts the REAL project tree under a local `php -S` server (see
 * tests/_pb_test_server.php), and asks health_check_public_board() to
 * compare what the database says SHOULD be excluded/masked against what the
 * LIVE api/public-board.php endpoint actually returned. Nothing leaves this
 * machine — the server binds to 127.0.0.1 on an ephemeral port.
 *
 * Covers plan.md §7's three checks:
 *   1. exclusion is active (a never-publish incident is confirmed ABSENT
 *      from the live response, not merely configured to be)
 *   2. address masking is active (never asserted 'critical' against
 *      correct production code — the assertion is "never cries wolf",
 *      mirroring test_web_exposure_backups_probe.php's negative case)
 *   3. an org-scoped board with zero org_id-tagged open incidents reports
 *      'info', never 'critical' and never 'unknown' (value/mission review
 *      finding #4)
 * ...plus the disabled-board case reporting 'ok', not 'unknown' — the same
 * "absent, not merely untested" distinction health_check_backups() uses.
 *
 * EVERY call to health_check_public_board() below runs in its OWN freshly
 * spawned PHP process (see pbhc_probe() at the bottom, copied from
 * tests/test_backup_end_to_end.php's proven e2e_probe() pattern — per
 * CLAUDE.md: "copy it, don't reinvent it"). get_variable() caches the
 * WHOLE settings table in a process-static on first call, and this file
 * writes public_board_enabled/public_board_address_precision via direct SQL
 * between every scenario — calling the function in-process a second time
 * would silently answer from the FIRST scenario's stale cache.
 *
 * @requires-db
 * Usage: php tests/test_public_board_health_check.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/_pb_test_server.php';

$pass = 0; $fail = 0;
function t($label, $cond, $hint = '') {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . ($cond ? '' : ($hint !== '' ? "\n       $hint" : '')) . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== Phase 138 — health_check_public_board() ===\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

try {
    $hasCol = db_fetch_value(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'public_board_never_publish'",
        [$prefix . 'in_types']
    );
} catch (Throwable $e) { $hasCol = false; }
if (!$hasCol) {
    echo "SKIP: Phase 138 schema not present (run sql/run_phase138_public_board.php first)\n";
    echo "\n=== $pass passed, $fail failed ===\n";
    exit(0);
}

$srv = pb_test_start_server();
if ($srv === null) {
    echo "SKIP: could not start a local PHP server for this test (proc_open/curl unavailable)\n";
    echo "\n=== $pass passed, $fail failed ===\n";
    exit(0);
}

/**
 * Run health_check_public_board() in a FRESH php process, pointed at our
 * throwaway server so _health_self_base_url() resolves to it. Copied from
 * tests/test_backup_end_to_end.php's e2e_probe() — same reason: a
 * write-then-read of a `settings` row inside ONE process is answered from
 * get_variable()'s static cache and proves nothing about what was actually
 * persisted.
 *
 * @return array|null decoded health_check_public_board() result, or null on
 *                     any probe failure (missing shell_exec, parse failure)
 */
function pbhc_probe(string $root, string $php, string $host): ?array
{
    $code = '<?php '
          . '$_SERVER["HTTP_HOST"] = ' . var_export($host, true) . '; '
          . '$_SERVER["SCRIPT_NAME"] = "/status.php"; '
          . 'unset($_SERVER["HTTPS"]); '
          . 'require_once ' . var_export($root . '/config.php', true) . '; '
          . 'require_once ' . var_export($root . '/inc/health-check.php', true) . '; '
          . 'ob_start(); '
          . '$__r = health_check_public_board(); '
          . 'ob_end_clean(); '
          . 'echo "<<<E2E>>>" . json_encode($__r);';
    $tmp = sys_get_temp_dir() . '/newui-pbhc-probe-' . getmypid() . '-' . mt_rand() . '.php';
    if (@file_put_contents($tmp, $code) === false) { return null; }
    $out = (string) @shell_exec(escapeshellarg($php) . ' ' . escapeshellarg($tmp) . ' 2>&1');
    @unlink($tmp);
    $at = strpos($out, '<<<E2E>>>');
    if ($at === false) { return null; }
    $json = trim(substr($out, $at + strlen('<<<E2E>>>')));
    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : null;
}

function pbhc_find_check(?array $result, string $name, $orgId = null) {
    foreach (($result['checks'] ?? []) as $c) {
        if (($c['name'] ?? '') !== $name) continue;
        if ($orgId !== null && (int) ($c['org_id'] ?? -1) !== (int) $orgId) continue;
        return $c;
    }
    return null;
}

$root = realpath(__DIR__ . '/..');
$php  = defined('PHP_BINARY') && PHP_BINARY ? PHP_BINARY : 'php';
$host = '127.0.0.1:' . $srv['port'];

if ($root === false || !function_exists('shell_exec')) {
    echo "SKIP: shell_exec()/realpath() unavailable — cannot spawn the isolated probe process this test requires\n";
    pb_test_stop_server($srv);
    echo "\n=== $pass passed, $fail failed ===\n";
    exit(0);
}

$createdTicketIds = [];
$createdTypeIds   = [];
$createdOrgIds    = [];
$origGlobalEnabled = null;
$origPrecision      = null;
$origOrgEnabled     = []; // id => original public_board_enabled value, for restore

try {
    $origGlobalEnabled = db_fetch_value("SELECT `value` FROM `{$prefix}settings` WHERE `name`='public_board_enabled'");
    $origPrecision     = db_fetch_value("SELECT `value` FROM `{$prefix}settings` WHERE `name`='public_board_address_precision'");

    // Keep precision at the shipped default ('block') so masking is exercised.
    db_query("INSERT INTO `{$prefix}settings` (`name`,`value`) VALUES ('public_board_address_precision','block')
              ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)");

    // ═══════════════════════════════════════════════════════════════════
    echo "-- 1. Disabled board reports 'ok', not 'unknown' --\n";
    // Temporarily disable the global switch AND every org's own board so
    // this scenario is genuinely "nothing enabled anywhere", not dependent
    // on ambient dev-DB state.
    $enabledOrgsRows = db_fetch_all("SELECT `id`,`public_board_enabled` FROM `{$prefix}organizations` WHERE `public_board_enabled` = 1");
    foreach ($enabledOrgsRows as $r) {
        $origOrgEnabled[(int) $r['id']] = (int) $r['public_board_enabled'];
        db_query("UPDATE `{$prefix}organizations` SET `public_board_enabled` = 0 WHERE `id` = ?", [$r['id']]);
    }
    db_query("INSERT INTO `{$prefix}settings` (`name`,`value`) VALUES ('public_board_enabled','0')
              ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)");

    $disabledResult = pbhc_probe($root, $php, $host);
    t('probe process ran and returned decodable JSON', $disabledResult !== null);
    t('checked === true', ($disabledResult['checked'] ?? false) === true);
    t('enabled === false', ($disabledResult['enabled'] ?? true) === false);
    t("severity is 'ok', not 'unknown'", ($disabledResult['severity'] ?? '') === 'ok',
        'severity=' . ($disabledResult['severity'] ?? '?'));
    t("summary mentions 'disabled'", stripos((string) ($disabledResult['summary'] ?? ''), 'disabled') !== false,
        (string) ($disabledResult['summary'] ?? ''));
    t('no HTTP probe was needed for the disabled case (checks array is empty)',
        empty($disabledResult['checks']));

    // Restore the orgs we just disabled — group 3 below re-enables its own
    // fresh throwaway org rather than reusing these.
    foreach ($origOrgEnabled as $oid => $val) {
        db_query("UPDATE `{$prefix}organizations` SET `public_board_enabled` = ? WHERE `id` = ?", [$val, $oid]);
    }

    // ═══════════════════════════════════════════════════════════════════
    echo "\n-- 2. Global board enabled + a never-publish incident: exclusion confirmed active --\n";
    db_query("INSERT INTO `{$prefix}settings` (`name`,`value`) VALUES ('public_board_enabled','1')
              ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)");

    db_query(
        "INSERT INTO `{$prefix}in_types` (`type`,`description`,`public_board_never_publish`) VALUES (?, 'zz138hc test type', 1)",
        ['zz138hc-' . uniqid()]
    );
    $neverPublishType = (int) db_insert_id();
    $createdTypeIds[] = $neverPublishType;

    db_query(
        "INSERT INTO `{$prefix}ticket`
            (`in_types_id`,`contact`,`street`,`city`,`state`,`lat`,`lng`,`date`,`scope`,`description`,`status`,`severity`,`updated`)
         VALUES (?, '', '123 ZZ138HC St', 'Testville', 'MN', 44.8, -93.3, ?, 'zz138hc test', 'zz138hc test', 2, 1, NOW())",
        [$neverPublishType, date('Y-m-d H:i:s', time() - 3600)]
    );
    $neverPublishTicket = (int) db_insert_id();
    $createdTicketIds[] = $neverPublishTicket;

    $enabledResult = pbhc_probe($root, $php, $host);
    t('probe process ran and returned decodable JSON (enabled scenario)', $enabledResult !== null);
    t('checked === true (enabled scenario)', ($enabledResult['checked'] ?? false) === true);
    t('enabled === true', ($enabledResult['enabled'] ?? false) === true);

    $exclusionCheck = pbhc_find_check($enabledResult, 'exclusion_active');
    t('an exclusion_active check ran', $exclusionCheck !== null);
    if ($exclusionCheck !== null) {
        t("exclusion_active is 'ok' — the never-publish incident was confirmed absent, not merely configured",
            ($exclusionCheck['severity'] ?? '') === 'ok',
            'severity=' . ($exclusionCheck['severity'] ?? '?') . ' message=' . ($exclusionCheck['message'] ?? ''));
    }

    // Belt-and-suspenders: independently confirm against the SAME live
    // endpoint the check itself probed, rather than trusting only the
    // check's own verdict.
    $liveResp = pb_test_http_get('http://' . $host . '/api/public-board.php');
    $liveJson = $liveResp !== null ? json_decode($liveResp['body'], true) : null;
    $liveIds  = is_array($liveJson) ? array_map(function ($i) { return (int) ($i['id'] ?? 0); }, $liveJson['incidents'] ?? []) : [];
    t('…and independently, the live shared-board response really does exclude our never-publish ticket',
        !in_array($neverPublishTicket, $liveIds, true));

    $maskingCheck = pbhc_find_check($enabledResult, 'masking_active');
    t('a masking_active check ran', $maskingCheck !== null);
    if ($maskingCheck !== null) {
        t("masking_active never reports 'critical' against correct production code",
            ($maskingCheck['severity'] ?? '') !== 'critical',
            'severity=' . ($maskingCheck['severity'] ?? '?') . ' message=' . ($maskingCheck['message'] ?? ''));
    }

    t("overall severity is not 'critical' or 'unknown' in the clean-exclusion scenario",
        !in_array($enabledResult['severity'] ?? '', ['critical', 'unknown'], true),
        'severity=' . ($enabledResult['severity'] ?? '?'));

    // ═══════════════════════════════════════════════════════════════════
    echo "\n-- 3. Org-scoped board, zero org_id-tagged incidents: 'info', never 'critical'/'unknown' --\n";
    // Disable the global switch so this org's board becomes the check's
    // primary probe target, and the scenario is fully self-contained: a
    // brand-new org with no incidents ever tagged to it at all.
    db_query("INSERT INTO `{$prefix}settings` (`name`,`value`) VALUES ('public_board_enabled','0')
              ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)");

    $uniq = 'zz138hc-' . substr(md5((string) mt_rand()), 0, 8);
    db_query(
        "INSERT INTO `{$prefix}organizations` (`name`,`public_board_enabled`,`public_board_slug`) VALUES (?,1,?)",
        ["ZZ138HC Org ({$uniq})", $uniq]
    );
    $emptyOrgId = (int) db_insert_id();
    $createdOrgIds[] = $emptyOrgId;

    $orgResult = pbhc_probe($root, $php, $host);
    t('probe process ran and returned decodable JSON (org-scoped scenario)', $orgResult !== null);
    t('checked === true (org-scoped scenario)', ($orgResult['checked'] ?? false) === true);
    t('enabled === true', ($orgResult['enabled'] ?? false) === true);

    $orgCheck = pbhc_find_check($orgResult, 'org_not_empty', $emptyOrgId);
    t('an org_not_empty check ran for our empty throwaway org', $orgCheck !== null);
    if ($orgCheck !== null) {
        t("org_not_empty is 'info' for a genuinely empty org board — never 'critical'",
            ($orgCheck['severity'] ?? '') === 'info',
            'severity=' . ($orgCheck['severity'] ?? '?'));
        t('…and names the org and its id in the message',
            strpos((string) ($orgCheck['message'] ?? ''), (string) $emptyOrgId) !== false
            && stripos((string) ($orgCheck['message'] ?? ''), 'ZZ138HC Org') !== false,
            (string) ($orgCheck['message'] ?? ''));
    }

    $exclusionCheck2 = pbhc_find_check($orgResult, 'exclusion_active');
    t("exclusion_active is 'info' when nothing is currently subject to a never-publish/excluded-group rule for this org (not 'unknown', not 'critical')",
        $exclusionCheck2 !== null && ($exclusionCheck2['severity'] ?? '') === 'info',
        $exclusionCheck2 !== null ? ('severity=' . ($exclusionCheck2['severity'] ?? '?')) : 'no check found');

    t("overall severity for the org-scoped empty-board scenario is 'info', not 'ok'/'critical'/'unknown'",
        ($orgResult['severity'] ?? '') === 'info',
        'severity=' . ($orgResult['severity'] ?? '?'));

    // ═══════════════════════════════════════════════════════════════════
    echo "\n-- 4. Sanity: the severity ranking documented in the source orders critical highest --\n";
    $src = (string) file_get_contents($root . '/inc/health-check.php');
    t('health_check_public_board() exists', strpos($src, 'function health_check_public_board(') !== false);
    t('the severity rank table orders critical above warn above unknown above info above ok',
        preg_match('/\$rank\s*=\s*\[\s*\'ok\'\s*=>\s*0,\s*\'info\'\s*=>\s*1,\s*\'unknown\'\s*=>\s*2,\s*\'warn\'\s*=>\s*3,\s*\'critical\'\s*=>\s*4/', $src) === 1);

} finally {
    pb_test_stop_server($srv);

    foreach ($createdTicketIds as $id) {
        try { db_query("DELETE FROM `{$prefix}ticket` WHERE id = ?", [$id]); } catch (Throwable $e) {}
    }
    foreach ($createdTypeIds as $id) {
        try { db_query("DELETE FROM `{$prefix}in_types` WHERE id = ?", [$id]); } catch (Throwable $e) {}
    }
    foreach ($createdOrgIds as $id) {
        try { db_query("DELETE FROM `{$prefix}organizations` WHERE id = ?", [$id]); } catch (Throwable $e) {}
    }
    foreach ($origOrgEnabled as $oid => $val) {
        try { db_query("UPDATE `{$prefix}organizations` SET `public_board_enabled` = ? WHERE `id` = ?", [$val, $oid]); } catch (Throwable $e) {}
    }

    if ($origGlobalEnabled !== null) {
        db_query("UPDATE `{$prefix}settings` SET `value` = ? WHERE `name` = 'public_board_enabled'", [$origGlobalEnabled]);
    } else {
        db_query("DELETE FROM `{$prefix}settings` WHERE `name` = 'public_board_enabled'");
    }
    if ($origPrecision !== null) {
        db_query("UPDATE `{$prefix}settings` SET `value` = ? WHERE `name` = 'public_board_address_precision'", [$origPrecision]);
    } else {
        db_query("DELETE FROM `{$prefix}settings` WHERE `name` = 'public_board_address_precision'");
    }
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
