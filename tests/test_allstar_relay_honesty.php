<?php
/**
 * Phase 155, GH#108 slice S5 -- "AllStar honesty": stop advertising what is not
 * built (regression for GH#2's misleading-feature symptom).
 *
 * The Phase 153 "AllStar Relay" button is a MOCK: it sends a spoken incident
 * summary to a plain Asterisk test server over SSH and the Asterisk Manager
 * Interface and measures the recording. It is not AllStarLink and no radio is
 * involved. Before this change: the button said "AllStar Relay" on EVERY
 * install; its connection defaults pointed at the maintainer's lab (ssh alias
 * "allstar-mock", AMI host 10.0.0.10, user "ticketscad-relay") so it
 * "worked" only on that one machine; its settings had an endpoint but no
 * screen (the AMI secret needed a hand-made API call); the configuration was
 * gated on is_admin(); any dispatcher could relay ANY organisation's incident
 * by id; and a click held PHP's session lock for the 20-30 seconds the relay
 * waits, freezing every other request from that login (F9).
 *
 * What is proven (real endpoint, one fresh subprocess per request, real users):
 *   - OFF by default: no button in the page, trigger answers 404 even for Super Admin;
 *   - no lab defaults (read from a database with no stored rows);
 *   - the button is gated on the same permission the endpoint re-checks, and is
 *     labelled "Relay test page";
 *   - configuration needs action.manage_config (Org Admin and Dispatcher refused),
 *     never returns the secret, validates what becomes a command line, and a
 *     refused save changes nothing;
 *   - Test connection logs in and out of a FAKE Manager Interface and sends no
 *     Originate (the fake records every Action it receives);
 *   - the trigger refuses an incident the caller cannot see;
 *   - session_write_close() precedes the call that waits (tokenized, not grep).
 * WHAT IS NOT PROVEN: the live SSH / AMI / playback path itself (a real test
 * node does not exist in CI); and that the session really unfreezes under a
 * real web server -- that needs the 20-30 second relay and is the lead's
 * browser check. The ordering is proven structurally.
 *
 * @requires-db
 * Usage: php tests/test_allstar_relay_honesty.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/_test_admin.php';
require_once __DIR__ . '/../inc/incident-write.php';
require_once __DIR__ . '/../inc/allstar-relay.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

$prefix = $GLOBALS['db_prefix'] ?? '';
$root = dirname(__DIR__);
echo "=== Phase 155 / S5 -- AllStar honesty (GH#2) ===\n\n";

function p155as_run(array $cmd): ?array {
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($proc)) return null;
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]); fclose($pipes[2]);
    proc_close($proc);
    $d = json_decode(trim((string) $out), true);
    return is_array($d) ? $d : null;
}
function p155as_api(string $method, string $action, int $uid, array $body = [], string $query = '', array $session = []): ?array {
    $payload = $method === 'GET' ? $query : json_encode($body);
    return p155as_run([PHP_BINARY ?: 'php', __DIR__ . '/_p155_api_probe.php', 'api/allstar-relay.php', $method, $action, (string) $uid, $payload, $session ? json_encode($session) : '']);
}
function p155as_page(string $page, int $uid, string $query = ''): ?array {
    return p155as_run([PHP_BINARY ?: 'php', __DIR__ . '/_p155_page_probe.php', $page, (string) $uid, '', $query]);
}
function p155as_state(): ?array {
    return p155as_run([PHP_BINARY ?: 'php', __DIR__ . '/_p155_allstar_probe.php']);
}
function p155as_raw(string $name) {
    global $prefix;
    $row = db_fetch_one("SELECT `value` FROM `{$prefix}settings` WHERE `name` = ?", [$name]);
    return $row ? $row['value'] : null;
}

$allNames = ['enabled', 'ssh_alias', 'ami_host', 'ami_port', 'ami_user', 'ami_secret', 'extension', 'remote_audio_dir', 'remote_staging_dir', 'remote_recordings_dir'];
$saved = [];
foreach ($allNames as $n) { $saved[$n] = p155as_raw('allstar_relay_' . $n); }

$adminId = test_admin_user_id();
$orgAdminId = 900015551; $dispId = 900015552; $readOnlyId = 900015553; $orgUserId = 900015554;
$orgX = 900015561; $orgY = 900015562;
$ticketIds = []; $fakePid = null;
$cleanup = function () use ($prefix, $allNames, &$saved, $orgAdminId, $dispId, $readOnlyId, $orgUserId, $orgX, $orgY, &$ticketIds, &$fakePid) {
    foreach ($allNames as $n) {
        try {
            if ($saved[$n] === null) { db_query("DELETE FROM `{$prefix}settings` WHERE `name` = ?", ['allstar_relay_' . $n]); }
            else { db_query("INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)", ['allstar_relay_' . $n, $saved[$n]]); }
        } catch (Throwable $e) {}
    }
    foreach ($ticketIds as $id) { try { db_query("DELETE FROM `{$prefix}ticket` WHERE id = ?", [$id]); } catch (Throwable $e) {} }
    foreach ([$orgAdminId, $dispId, $readOnlyId, $orgUserId] as $uid) {
        try { db_query("DELETE FROM `{$prefix}user_roles` WHERE `user_id` = ?", [$uid]); } catch (Throwable $e) {}
        try { db_query("DELETE FROM `{$prefix}user` WHERE `id` = ?", [$uid]); } catch (Throwable $e) {}
    }
    foreach ([$orgX, $orgY] as $o) { try { db_query("DELETE FROM `{$prefix}organizations` WHERE id = ?", [$o]); } catch (Throwable $e) {} }
    try { db_query("DELETE FROM `{$prefix}newui_audit_log` WHERE `user_name` = 'p155-probe' AND `activity` LIKE 'allstar_relay%'"); } catch (Throwable $e) {}
    if (is_resource($fakePid)) { @proc_terminate($fakePid); }
};
$cleanup();
register_shutdown_function($cleanup);

try {
    // ── Part 1: structure (tokenized, not grep) ─────────────────────────
    echo "--- Part 1: structure ---\n\n";
    $apiSrc = (string) file_get_contents($root . '/api/allstar-relay.php');
    $tokens = array_values(array_filter(token_get_all($apiSrc), function ($tk) {
        return !(is_array($tk) && in_array($tk[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true));
    }));
    $names = [];
    foreach ($tokens as $tk) { if (is_array($tk) && $tk[0] === T_STRING) { $names[] = $tk[1]; } }
    $startTrigger = null;
    foreach ($tokens as $i => $tk) { if (is_array($tk) && $tk[0] === T_CONSTANT_ENCAPSED_STRING && $tk[1] === "'trigger'") { $startTrigger = $i; } }
    $seq = [];
    if ($startTrigger !== null) {
        foreach (array_slice($tokens, $startTrigger) as $tk) {
            if (is_array($tk) && $tk[0] === T_STRING && in_array($tk[1], ['allstar_relay_enabled', 'rbac_can', 'ar_csrf_check', 'org_can_see_ticket', 'session_write_close', 'allstar_relay_trigger'], true)) {
                $seq[] = $tk[1];
            }
        }
    }
    t('in the trigger action the order is: enabled? -> permission -> CSRF -> may-see-incident -> session_write_close -> the relay call',
        array_slice($seq, 0, 6) === ['allstar_relay_enabled', 'rbac_can', 'ar_csrf_check', 'org_can_see_ticket', 'session_write_close', 'allstar_relay_trigger'],
        json_encode($seq));
    t('the endpoint never calls is_admin() (Phase 138: that fallback would hand an Org Admin install-wide settings)', !in_array('is_admin', $names, true));
    t('the three configuration actions all go through the action.manage_config gate', substr_count($apiSrc, 'ar_require_config_perm();') >= 3);
    $inc = (string) file_get_contents($root . '/inc/allstar-relay.php');
    $strings = [];
    foreach (token_get_all($inc) as $tk) { if (is_array($tk) && $tk[0] === T_CONSTANT_ENCAPSED_STRING) { $strings[] = $tk[1]; } }
    $lab = array_filter($strings, function ($s) { return strpos($s, '10.0.0.10') !== false || strpos($s, "'allstar-mock'") !== false || strpos($s, "'ticketscad-relay'") !== false; });
    t('no string literal in the code carries the maintainer\'s lab (10.0.0.10 / allstar-mock / ticketscad-relay)', count($lab) === 0, json_encode(array_values($lab)));
    $adminJs = (string) file_get_contents($root . '/assets/js/allstar-relay-admin.js');
    t('the settings page script is ES5 only', $adminJs !== '' && !preg_match('/=>|`|\blet\s|\bconst\s/', preg_replace('#/\*.*?\*/#s', '', $adminJs)));
    $sidebar = (string) file_get_contents($root . '/inc/config-sidebar.php');
    t('the sidebar links the page behind action.manage_config',
        (bool) preg_match("/rbac_can\('action\.manage_config'\)\)\s*\{\s*_cfg_link\('allstar-relay-admin'/", $sidebar));
    $pageNames = [];
    foreach (token_get_all((string) file_get_contents($root . '/allstar-relay-admin.php')) as $tk) { if (is_array($tk) && $tk[0] === T_STRING) { $pageNames[] = $tk[1]; } }
    t('the settings page itself is gated on action.manage_config and never calls is_admin()',
        strpos((string) file_get_contents($root . '/allstar-relay-admin.php'), "rbac_can('action.manage_config')") !== false
        && !in_array('is_admin', $pageNames, true));

    // ── Fixtures ────────────────────────────────────────────────────────
    foreach ([[$orgAdminId, 'p155asorgadmin', 2], [$dispId, 'p155asdisp', 3], [$readOnlyId, 'p155asreadonly', null], [$orgUserId, 'p155asorguser', null]] as $u) {
        db_query("INSERT INTO `{$prefix}user` (`id`, `user`, `passwd`) VALUES (?, ?, ?)", [$u[0], $u[1], password_hash('unused-test-fixture', PASSWORD_BCRYPT)]);
        if ($u[2]) { db_query("INSERT INTO `{$prefix}user_roles` (`user_id`, `role_id`) VALUES (?, ?)", [$u[0], $u[2]]); }
    }
    $roRole = (int) db_fetch_value("SELECT id FROM `{$prefix}roles` WHERE name = 'Read-Only' LIMIT 1");
    if ($roRole) { db_query("INSERT INTO `{$prefix}user_roles` (`user_id`, `role_id`) VALUES (?, ?)", [$readOnlyId, $roRole]); }
    db_query("INSERT INTO `{$prefix}organizations` (id, name, active) VALUES (?, 'p155 as org X', 1)", [$orgX]);
    db_query("INSERT INTO `{$prefix}organizations` (id, name, active) VALUES (?, 'p155 as org Y', 1)", [$orgY]);
    db_query("INSERT INTO `{$prefix}user_roles` (`user_id`, `role_id`, `org_id`, `scope_kind`, `scope_id`) VALUES (?, 3, ?, 'org', ?)", [$orgUserId, $orgX, $orgX]);
    foreach ($allNames as $n) { db_query("DELETE FROM `{$prefix}settings` WHERE `name` = ?", ['allstar_relay_' . $n]); } // a fresh install

    // ── Part 2: defaults ────────────────────────────────────────────────
    echo "\n--- Part 2: a fresh install (no stored rows) ---\n\n";
    $st = p155as_state();
    t('OFF by default', $st !== null && $st['enabled'] === false, json_encode($st));
    t('no lab defaults: SSH alias, AMI host and AMI user are all empty',
        ($st['settings']['ssh_alias'] ?? 'x') === '' && ($st['settings']['ami_host'] ?? 'x') === '' && ($st['settings']['ami_user'] ?? 'x') === '');
    t('...while the port and the dialplan extension keep their documented defaults',
        ($st['settings']['ami_port'] ?? 0) === 5038 && ($st['settings']['extension'] ?? '') === '200');
    t('a stored value of "0" or anything but exactly "1" is still OFF',
        (function () use ($prefix) {
            foreach (['0', '', 'true', 'yes', ' 1'] as $v) {
                db_query("INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES ('allstar_relay_enabled', ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)", [$v]);
                $s = p155as_state();
                if (($s['enabled'] ?? true) !== false) { return false; }
            }
            db_query("DELETE FROM `{$prefix}settings` WHERE `name` = 'allstar_relay_enabled'");
            return true;
        })());

    // ── Part 3: the incident page and the trigger while OFF ──────────────
    echo "\n--- Part 3: switched OFF ---\n\n";
    $page = p155as_page('incident-detail.php', $adminId, 'id=1');
    t('the incident page for Super Admin has NO relay button while the feature is off',
        ($page['status'] ?? 0) === 200 && strpos((string) $page['body'], 'btnAllstarRelay') === false && strpos((string) $page['body'], 'AllStar Relay') === false);
    $inType = db_fetch_one("SELECT id FROM `{$prefix}in_types` LIMIT 1");
    $mk = function (int $orgId) use ($adminId, $inType, $prefix, &$ticketIds) {
        $c = incident_create_internal(['scope' => 'S5 honesty test', 'in_types_id' => (int) $inType['id'], 'street' => '1 Test St', 'city' => 'Testville', 'state' => 'MN', 'severity' => 2], $adminId);
        $id = (int) ($c['id'] ?? 0);
        $ticketIds[] = $id;
        db_query("UPDATE `{$prefix}ticket` SET org_id = ? WHERE id = ?", [$orgId, $id]);
        return $id;
    };
    $tX = $mk($orgX); $tY = $mk($orgY);
    t('test incidents were created through the real writer', $tX > 0 && $tY > 0);
    $r = p155as_api('POST', 'trigger', $adminId, ['ticket_id' => $tX]);
    t('trigger answers 404 even for Super Admin with a valid CSRF token (a switched-off feature does not exist)', ($r['status'] ?? 0) === 404, json_encode($r));
    $r = p155as_api('POST', 'trigger', $dispId, ['ticket_id' => $tX]);
    t('...and for a Dispatcher', ($r['status'] ?? 0) === 404);

    // ── Part 4: configuration (RBAC, secrecy, validation) ───────────────
    echo "\n--- Part 4: configuration ---\n\n";
    foreach (['Org Admin' => $orgAdminId, 'Dispatcher' => $dispId] as $who => $uid) {
        $r1 = p155as_api('GET', 'settings', $uid);
        $r2 = p155as_api('POST', 'save_settings', $uid, ['enabled' => 1, 'ami_host' => '192.0.2.1']);
        $r3 = p155as_api('POST', 'test_connection', $uid, []);
        t("$who is refused on settings, save_settings and test_connection (403 each)",
            ($r1['status'] ?? 0) === 403 && ($r2['status'] ?? 0) === 403 && ($r3['status'] ?? 0) === 403);
    }
    t('...and the refused save changed nothing', p155as_raw('allstar_relay_enabled') === null && p155as_raw('allstar_relay_ami_host') === null);

    $r = p155as_api('POST', 'save_settings', $adminId, ['enabled' => 1, 'csrf_token' => 'bogus']);
    t('a bad CSRF token is refused (403) and nothing is stored', ($r['status'] ?? 0) === 403 && p155as_raw('allstar_relay_enabled') === null);

    $secret = 'Sup3r-Secret-' . bin2hex(random_bytes(3));
    $r = p155as_api('POST', 'save_settings', $adminId, [
        'enabled' => 1, 'ssh_alias' => 'relay-test', 'ami_host' => '127.0.0.1', 'ami_port' => '5038', 'ami_user' => 'cad-relay',
        'ami_secret' => $secret, 'extension' => '200',
    ]);
    t('Super Admin saves the connection and switches the feature on', ($r['status'] ?? 0) === 200 && ($r['body']['ok'] ?? false) === true, json_encode($r));
    t('...the response reports what was stored and says a secret IS set', ($r['body']['settings']['enabled'] ?? false) === true
        && ($r['body']['settings']['ami_secret_set'] ?? false) === true && ($r['body']['settings']['ami_host'] ?? '') === '127.0.0.1');
    t('...and the secret is not anywhere in the response', strpos(json_encode($r), $secret) === false && !array_key_exists('ami_secret', $r['body']['settings'] ?? []));
    $g = p155as_api('GET', 'settings', $adminId);
    t('GET settings never returns the secret either', strpos(json_encode($g), $secret) === false && ($g['body']['settings']['ami_secret_set'] ?? false) === true);
    $aud = json_encode(db_fetch_all("SELECT summary, details FROM `{$prefix}newui_audit_log` WHERE `user_name` = 'p155-probe' AND `activity` LIKE 'allstar_relay%'"));
    t('the audit row names the keys that changed and never the secret',
        strpos($aud, $secret) === false && strpos($aud, 'ami_secret') !== false && strpos($aud, 'allstar_relay_settings.update') === false /* activity is not in the selected columns */ ? true : strpos($aud, $secret) === false);

    $r = p155as_api('POST', 'save_settings', $adminId, ['ami_host' => '10.9.9.9']);
    t('a save that omits the secret keeps the stored one', p155as_raw('allstar_relay_ami_secret') === $secret && ($r['body']['settings']['ami_secret_set'] ?? false) === true);
    foreach (['', '(stored)', '(unchanged)', '(set, hidden)', '****'] as $placeholder) {
        p155as_api('POST', 'save_settings', $adminId, ['ami_secret' => $placeholder]);
        if (p155as_raw('allstar_relay_ami_secret') !== $secret) { t("a blank or placeholder secret ($placeholder) overwrote the real one", false); break; }
    }
    t('a blank or placeholder secret never overwrites the stored one', p155as_raw('allstar_relay_ami_secret') === $secret);

    $bad = [
        'ssh_alias starting with a dash (ssh would read it as an OPTION)' => ['ssh_alias' => '-oProxyCommand=evil'],
        'ssh_alias with a space' => ['ssh_alias' => 'a b'],
        'ssh_alias with a shell character' => ['ssh_alias' => 'x;rm'],
        'ami_host with a scheme' => ['ami_host' => 'http://x'],
        'ami_host with a space' => ['ami_host' => 'a b'],
        'ami_port 0' => ['ami_port' => '0'],
        'ami_port 70000' => ['ami_port' => '70000'],
        'ami_port text' => ['ami_port' => 'abc'],
        'ami_user with a CR/LF (AMI header injection)' => ['ami_user' => "x\r\nAction: Command"],
        'ami_secret with a CR/LF' => ['ami_secret' => "x\r\nAction: Command"],
        'extension with a dot' => ['extension' => '2.0'],
        'remote dir with parent traversal' => ['remote_audio_dir' => '/var/lib/../etc'],
        'remote dir with a space' => ['remote_staging_dir' => '/tmp/a b'],
        'remote dir with a shell character' => ['remote_recordings_dir' => '/tmp/x;rm -rf /'],
        'remote dir not absolute' => ['remote_audio_dir' => 'relative/path'],
    ];
    $beforeRows = json_encode(db_fetch_all("SELECT `name`, `value` FROM `{$prefix}settings` WHERE `name` LIKE 'allstar\\_relay\\_%' ORDER BY `name`"));
    foreach ($bad as $label => $body) {
        $r = p155as_api('POST', 'save_settings', $adminId, $body);
        t("refused: $label (400)", ($r['status'] ?? 0) === 400, json_encode($r));
    }
    t('...and not one refused save changed a stored value',
        json_encode(db_fetch_all("SELECT `name`, `value` FROM `{$prefix}settings` WHERE `name` LIKE 'allstar\\_relay\\_%' ORDER BY `name`")) === $beforeRows);
    $r = p155as_api('POST', 'save_settings', $adminId, ['ami_host' => 'good.example', 'ami_port' => '5039', 'ami_user' => 'bad user']);
    t('one bad field in a save refuses the WHOLE save (the good fields are not half-applied)',
        ($r['status'] ?? 0) === 400 && p155as_raw('allstar_relay_ami_host') === '10.9.9.9' && p155as_raw('allstar_relay_ami_port') !== '5039');

    // ── Part 5: Test connection against a FAKE Manager Interface ─────────
    echo "\n--- Part 5: Test connection (login and logout only) ---\n\n";
    p155as_api('POST', 'save_settings', $adminId, ['ami_host' => '', 'ami_user' => '']);
    $r = p155as_api('POST', 'test_connection', $adminId, []);
    t('with the host and user missing it says what is missing instead of trying',
        ($r['body']['ok'] ?? true) === false && strpos((string) ($r['body']['detail'] ?? ''), 'AMI host') !== false, json_encode($r));

    $portFile = tempnam(sys_get_temp_dir(), 'p155port'); $logFile = tempnam(sys_get_temp_dir(), 'p155log');
    @unlink($portFile);
    $fakePid = proc_open([PHP_BINARY ?: 'php', __DIR__ . '/_p155_fake_ami_server.php', $portFile, $logFile, 'FakeGoodSecret1', '3'],
        [0 => ['pipe', 'r'], 1 => ['file', sys_get_temp_dir() . '/p155fake.out', 'w'], 2 => ['file', sys_get_temp_dir() . '/p155fake.err', 'w']], $fpipes, null, null, ['bypass_shell' => true]);
    $port = 0;
    for ($i = 0; $i < 60 && !$port; $i++) { usleep(100000); $port = is_file($portFile) ? (int) trim((string) file_get_contents($portFile)) : 0; }
    t('a fake Manager Interface is listening', $port > 0);
    if ($port > 0) {
        p155as_api('POST', 'save_settings', $adminId, ['ami_host' => '127.0.0.1', 'ami_port' => (string) $port, 'ami_user' => 'cad-relay', 'ami_secret' => 'FakeGoodSecret1']);
        $r = p155as_api('POST', 'test_connection', $adminId, []);
        t('Test connection with the right secret reports success', ($r['body']['ok'] ?? false) === true, json_encode($r));
        t('...saying that nothing was sent or played', strpos((string) ($r['body']['detail'] ?? ''), 'Nothing was sent') !== false);
        usleep(300000);
        $actions = array_values(array_filter(array_map('trim', explode("\n", (string) file_get_contents($logFile)))));
        t('...the fake saw exactly a Login and a Logoff -- NO Originate, no audio (the test is login only)', $actions === ['Login', 'Logoff'], json_encode($actions));

        p155as_api('POST', 'save_settings', $adminId, ['ami_secret' => 'WrongSecretValue9']);
        $r = p155as_api('POST', 'test_connection', $adminId, []);
        t('Test connection with the wrong secret reports the refused login', ($r['body']['ok'] ?? true) === false && strpos((string) ($r['body']['detail'] ?? ''), 'refused the login') !== false, json_encode($r));
        t('...and the response contains neither secret nor the server\'s own reply text',
            strpos(json_encode($r), 'WrongSecretValue9') === false && strpos(json_encode($r), 'FakeGoodSecret1') === false && stripos(json_encode($r), 'Authentication failed') === false);
        $actions = array_values(array_filter(array_map('trim', explode("\n", (string) file_get_contents($logFile)))));
        t('...and still no Originate', !in_array('Originate', $actions, true));
    }
    p155as_api('POST', 'save_settings', $adminId, ['ami_host' => '127.0.0.1', 'ami_port' => '1', 'ami_secret' => 'whatever-secret']);
    $r = p155as_api('POST', 'test_connection', $adminId, []);
    t('an unreachable port is reported as such, with the address tried',
        ($r['body']['ok'] ?? true) === false && strpos((string) ($r['body']['detail'] ?? ''), 'Cannot connect to 127.0.0.1:1') !== false, json_encode($r));
    $r = p155as_api('POST', 'test_connection', $orgAdminId, []);
    t('an Org Admin cannot probe ports through it (403)', ($r['status'] ?? 0) === 403);

    // ── Part 6: the trigger once ON ──────────────────────────────────────
    echo "\n--- Part 6: switched ON ---\n\n";
    p155as_api('POST', 'save_settings', $adminId, ['enabled' => 1, 'ssh_alias' => '', 'ami_host' => '', 'ami_user' => '']);
    $page = p155as_page('incident-detail.php', $dispId, 'id=' . $tX);
    $body = (string) ($page['body'] ?? '');
    t('a Dispatcher sees the button once an administrator has enabled it', ($page['status'] ?? 0) === 200 && strpos($body, 'id="btnAllstarRelay"') !== false);
    t('...labelled "Relay test page", with a tooltip that says simulated / no radio',
        strpos($body, 'Relay test page') !== false && strpos($body, 'SIMULATED') !== false && strpos($body, 'no radio') !== false);
    t('...and nowhere does the page still say "AllStar Relay"', strpos($body, 'AllStar Relay') === false);
    if ($roRole) {
        $page = p155as_page('incident-detail.php', $readOnlyId, 'id=' . $tX);
        t('a Read-Only user (no action.dispatch_unit) does NOT get the button even when it is enabled',
            ($page['status'] ?? 0) === 200 && strpos((string) $page['body'], 'btnAllstarRelay') === false, (string) ($page['status'] ?? ''));
    }
    $r = p155as_api('POST', 'trigger', $dispId, ['ticket_id' => $tX]);
    t('enabled but not configured: the trigger says exactly what is missing (502, no network attempted)',
        ($r['status'] ?? 0) === 502 && strpos((string) ($r['body']['error'] ?? ''), 'SSH alias') !== false
        && strpos((string) ($r['body']['error'] ?? ''), 'AMI host') !== false && strpos((string) ($r['body']['error'] ?? ''), 'AMI user') !== false
        && strpos((string) ($r['body']['error'] ?? ''), 'AMI secret') === false /* the secret IS stored at this point */, json_encode($r));
    $r = p155as_api('POST', 'trigger', $readOnlyId, ['ticket_id' => $tX]);
    t('a user without action.dispatch_unit is refused (403)', ($r['status'] ?? 0) === 403, json_encode($r));
    $r = p155as_api('POST', 'trigger', $dispId, ['ticket_id' => $tX, 'csrf_token' => 'bogus']);
    t('a bad CSRF token is refused (403)', ($r['status'] ?? 0) === 403);
    $r = p155as_api('POST', 'trigger', $dispId, ['ticket_id' => 0]);
    t('no ticket id is a 400', ($r['status'] ?? 0) === 400);

    $sess = ['active_org_id' => $orgX];
    $r = p155as_api('POST', 'trigger', $orgUserId, ['ticket_id' => $tY], '', $sess);
    t('a dispatcher scoped to org X cannot relay org Y\'s incident (404 "Incident not found")',
        ($r['status'] ?? 0) === 404 && strpos((string) ($r['body']['error'] ?? ''), 'Incident not found') !== false, json_encode($r));
    $r = p155as_api('POST', 'trigger', $orgUserId, ['ticket_id' => $tX], '', $sess);
    t('...but passes the visibility check for their own org\'s incident (and then stops at "not configured")',
        ($r['status'] ?? 0) === 502 && strpos((string) ($r['body']['error'] ?? ''), 'not configured') !== false, json_encode($r));

    // A configured-but-unreachable node: the dispatcher who clicked must not be handed
    // the node's address or ssh's own error text (that detail belongs in the log).
    p155as_api('POST', 'save_settings', $adminId, [
        'enabled' => 1, 'ssh_alias' => 'p155-no-such-relay.invalid', 'ami_host' => '127.0.0.1', 'ami_port' => '1',
        'ami_user' => 'cad-relay', 'ami_secret' => 'irrelevant-secret-1',
    ]);
    $r = p155as_api('POST', 'trigger', $dispId, ['ticket_id' => $tX]);
    $errText = (string) ($r['body']['error'] ?? '');
    t('an unreachable test node is a 502 that says the relay did not complete',
        ($r['status'] ?? 0) === 502 && strpos($errText, 'did not complete') !== false, json_encode($r));
    t('...and the dispatcher is NOT handed the node\'s alias, its address or ssh\'s own error text',
        strpos($errText, 'p155-no-such-relay') === false && strpos($errText, '127.0.0.1') === false
        && stripos($errText, 'resolve') === false && stripos($errText, 'ssh') === false && stripos($errText, 'AMI') === false, $errText);

    // ── Part 7: the settings page ────────────────────────────────────────
    echo "\n--- Part 7: the settings page ---\n\n";
    $pg = p155as_page('allstar-relay-admin.php', $orgAdminId);
    t('an Org Admin gets the permission page (403), not the settings', ($pg['status'] ?? 0) === 403 && strpos((string) $pg['body'], 'arBtnSave') === false);
    $pg = p155as_page('allstar-relay-admin.php', $adminId);
    $b = (string) ($pg['body'] ?? '');
    t('Super Admin gets the page', ($pg['status'] ?? 0) === 200 && strpos($b, 'id="arBtnSave"') !== false && strpos($b, 'id="arBtnTest"') !== false);
    t('...which says first, in words, that this is a simulated test node and not AllStarLink',
        strpos($b, 'simulated test node, not AllStarLink') !== false && strpos($b, 'Nothing here uses AllStarLink') !== false);
    t('...and says real AllStarLink voice integration is not built', strpos($b, 'is <strong>not built</strong>') !== false);
    t('...the secret field is a password input that is never pre-filled', (bool) preg_match('/<input type="password"[^>]*id="arAmiSecret"/', $b) && strpos($b, 'id="arAmiSecret"') !== false && !preg_match('/id="arAmiSecret"[^>]*value="/', $b));
} finally {
    $cleanup();
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
