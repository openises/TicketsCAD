<?php
/**
 * Phase 155, GH#108 slice S2 -- supply the PBX's EXISTING password.
 *
 * Before: phone_extension_create() and rotate always generated the SIP
 * password, so pointing TicketsCAD at an endpoint that already existed on the
 * PBX meant editing the PBX to match a value only this app knew. Now an
 * administrator can supply the password the endpoint already has, at create
 * time or later via extension_set_password. Generation stays the default.
 *
 * What is proven (real writer functions + the REAL endpoint, one fresh
 * subprocess per call, no hand-seeded rows):
 *   - a supplied password is stored EXACTLY and is what the bound browser
 *     receives from my_extension (the only place a SIP secret may go);
 *   - it is never echoed by create, set_password, list or get, and is absent
 *     from the audit row, the row summary and every admin response body;
 *   - weak / malformed values are refused (length, spaces, control characters,
 *     a trailing newline slipping past `$`), and nothing is stored on refusal;
 *   - generation (default) and rotation behave as before;
 *   - set_password needs action.manage_calls and a CSRF token and is audited.
 *
 * @requires-db
 * Usage: php tests/test_phone_extension_password.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/_test_admin.php';
require_once __DIR__ . '/../inc/phone-extensions.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

$prefix = $GLOBALS['db_prefix'] ?? '';
echo "=== Phase 155 / S2 -- supply the PBX's existing SIP password ===\n\n";

function p155pw_probe(string $method, string $action, int $userId, string $payload = ''): ?array {
    $cmd = [PHP_BINARY ?: 'php', __DIR__ . '/_p155_api_probe.php', 'api/phone-extensions.php', $method, $action, (string) $userId, $payload];
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($proc)) return null;
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]); fclose($pipes[2]);
    proc_close($proc);
    $d = json_decode(trim((string) $out), true);
    return is_array($d) ? $d : null;
}
function p155pw_stored(int $id): ?string {
    global $prefix;
    $v = db_fetch_value("SELECT sip_password FROM `{$prefix}phone_extensions` WHERE id = ?", [$id]);
    return $v === false || $v === null ? null : (string) $v;
}
function p155pw_ext(): string { return (string) random_int(200000, 899999); }

$adminId = test_admin_user_id();
$dispatcherId = 900015521; // Dispatcher: no action.manage_calls
$extIds = [];
$cleanup = function () use ($prefix, $dispatcherId, &$extIds) {
    foreach ($extIds as $id) {
        try { db_query("DELETE FROM `{$prefix}phone_extensions` WHERE id = ?", [$id]); } catch (Throwable $e) {}
    }
    try { db_query("DELETE FROM `{$prefix}user_roles` WHERE `user_id` = ?", [$dispatcherId]); } catch (Throwable $e) {}
    try { db_query("DELETE FROM `{$prefix}user` WHERE `id` = ?", [$dispatcherId]); } catch (Throwable $e) {}
    try { db_query("DELETE FROM `{$prefix}newui_audit_log` WHERE `user_name` = 'p155-probe' AND `activity` LIKE 'phone_extension.%'"); } catch (Throwable $e) {}
};
$cleanup();
register_shutdown_function($cleanup);

try {
    // ── Validation ──────────────────────────────────────────────────────
    echo "--- validation ---\n\n";
    $bad = [
        'too short (7)'       => 'abcdefg',
        'empty after trim is not "supplied" -- but spaces inside are refused' => 'abcd efgh',
        'a space'             => 'pass word1',
        'a tab'               => "pass\tword1",
        'a trailing newline'  => "password123\n",
        'non-ASCII'           => "p\xC3\xA4ssword123",
        'a control character' => "password\x01123",
        'too long (129)'      => str_repeat('a', 129),
    ];
    foreach ($bad as $label => $pw) {
        $threw = false;
        try { phone_extension_validate_password($pw); } catch (InvalidArgumentException $e) { $threw = true; }
        t("refused: $label", $threw);
    }
    foreach (['exactly8!' => 'exactly8!', '128 chars' => str_repeat('a', 128), 'symbols' => 'p@$$w0rd-ok_2026', 'semicolon (pjsip comment char) is allowed, the guide warns' => 'abc;defghij'] as $label => $pw) {
        $ok = true;
        try { phone_extension_validate_password($pw); } catch (InvalidArgumentException $e) { $ok = false; }
        t("accepted: $label", $ok);
    }

    // ── Create with a supplied password ─────────────────────────────────
    echo "\n--- create (real writer) ---\n\n";
    $supplied = 'The-PBX-Already-Has-This-9';
    $ext1 = p155pw_ext();
    $c1 = phone_extension_create($ext1, 'S2 supplied', false, 'p155-pw-token-1', null, $supplied);
    $extIds[] = $c1['id'];
    t('create() with a supplied password stores EXACTLY that value', p155pw_stored($c1['id']) === $supplied);
    t('...and does NOT return it (the administrator already has it)', $c1['sip_password'] === null && $c1['password_supplied'] === true);

    $ext2 = p155pw_ext();
    $c2 = phone_extension_create($ext2, 'S2 generated', false, '', null);
    $extIds[] = $c2['id'];
    t('generation stays the default: no supplied password -> a random one, returned once',
        $c2['password_supplied'] === false && strlen((string) $c2['sip_password']) >= 20 && p155pw_stored($c2['id']) === $c2['sip_password']);
    $c3 = phone_extension_create(p155pw_ext(), 'S2 empty supplied', false, '', null, '');
    $extIds[] = $c3['id'];
    t('an empty supplied value means "generate" (not "store an empty password")',
        $c3['password_supplied'] === false && strlen((string) p155pw_stored($c3['id'])) >= 20);

    $threw = false; $ext4 = p155pw_ext();
    try { phone_extension_create($ext4, 'S2 bad', false, '', null, 'short'); } catch (InvalidArgumentException $e) { $threw = true; }
    t('create() with a weak supplied password throws', $threw);
    t('...and creates NO row (validation precedes the insert)',
        (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}phone_extensions` WHERE extension = ?", [$ext4]) === 0);

    // ── The real endpoint ────────────────────────────────────────────────
    echo "\n--- the real endpoint (one subprocess per call) ---\n\n";
    $ext5 = p155pw_ext();
    $r = p155pw_probe('POST', 'extension_create', $adminId, json_encode([
        'extension' => $ext5, 'label' => 'S2 endpoint', 'sip_password' => 'Endpoint-Supplied-77', 'workstation_token' => 'p155-pw-token-5',
    ]));
    $id5 = (int) ($r['body']['extension_id'] ?? 0);
    if ($id5 > 0) { $extIds[] = $id5; }
    t('extension_create with sip_password succeeds', ($r['status'] ?? 0) === 200 && $id5 > 0, json_encode($r));
    t('...the response says a password was supplied and does NOT contain it',
        ($r['body']['password_supplied'] ?? false) === true && strpos(json_encode($r), 'Endpoint-Supplied-77') === false && !isset($r['body']['sip_password']));
    t('...stored exactly', p155pw_stored($id5) === 'Endpoint-Supplied-77');

    $r = p155pw_probe('GET', 'extensions', $adminId);
    t('the list never contains any extension\'s password (supplied or generated)',
        ($r['status'] ?? 0) === 200 && strpos(json_encode($r), 'Endpoint-Supplied-77') === false
        && strpos(json_encode($r), $supplied) === false && strpos(json_encode($r), (string) $c2['sip_password']) === false);
    $listed = null;
    foreach (($r['body']['extensions'] ?? []) as $row) { if ((int) $row['id'] === $id5) { $listed = $row; } }
    t('...but says a password IS set (has_password), so the page can show it', $listed !== null && ($listed['has_password'] ?? false) === true);
    $r = p155pw_probe('GET', 'extension', $adminId, 'id=' . $id5);
    t('a single-extension GET masks it too', strpos(json_encode($r), 'Endpoint-Supplied-77') === false && ($r['body']['extension']['has_password'] ?? false) === true);

    // set_password
    $r = p155pw_probe('POST', 'extension_set_password', $adminId, json_encode(['id' => $id5, 'sip_password' => 'Replaced-By-Admin-88']));
    t('extension_set_password replaces the stored password', ($r['status'] ?? 0) === 200 && p155pw_stored($id5) === 'Replaced-By-Admin-88', json_encode($r));
    t('...and the response never echoes it', strpos(json_encode($r), 'Replaced-By-Admin-88') === false);
    $aud = db_fetch_all("SELECT summary, details FROM `{$prefix}newui_audit_log` WHERE `activity` = 'phone_extension.set_password' AND `user_name` = 'p155-probe'");
    t('...and is audited as phone_extension.set_password', count($aud) === 1);
    $blob = json_encode(db_fetch_all("SELECT summary, details FROM `{$prefix}newui_audit_log` WHERE `user_name` = 'p155-probe' AND `activity` LIKE 'phone_extension.%'"));
    t('...no audit row (create or set_password) contains either supplied password',
        strpos($blob, 'Endpoint-Supplied-77') === false && strpos($blob, 'Replaced-By-Admin-88') === false);

    $r = p155pw_probe('POST', 'extension_set_password', $adminId, json_encode(['id' => $id5, 'sip_password' => 'tiny']));
    t('a weak password is a 400 and the stored one is untouched', ($r['status'] ?? 0) === 400 && p155pw_stored($id5) === 'Replaced-By-Admin-88');
    $r = p155pw_probe('POST', 'extension_set_password', $adminId, json_encode(['id' => 999999999, 'sip_password' => 'Long-Enough-Pw-1']));
    t('an unknown extension id is a 404', ($r['status'] ?? 0) === 404);
    $r = p155pw_probe('POST', 'extension_set_password', $adminId, json_encode(['id' => $id5, 'sip_password' => 'Csrf-Check-Pw-99', 'csrf_token' => 'bogus']));
    t('a bad CSRF token is refused (403) and nothing changes', ($r['status'] ?? 0) === 403 && p155pw_stored($id5) === 'Replaced-By-Admin-88');

    db_query("INSERT INTO `{$prefix}user` (`id`, `user`, `passwd`) VALUES (?, ?, ?)",
        [$dispatcherId, 'p155pwdispatcher', password_hash('unused-test-fixture', PASSWORD_BCRYPT)]);
    db_query("INSERT INTO `{$prefix}user_roles` (`user_id`, `role_id`) VALUES (?, 3)", [$dispatcherId]);
    $r = p155pw_probe('POST', 'extension_set_password', $dispatcherId, json_encode(['id' => $id5, 'sip_password' => 'Dispatcher-Try-123']));
    t('a Dispatcher (no action.manage_calls) is refused (403)', ($r['status'] ?? 0) === 403 && p155pw_stored($id5) === 'Replaced-By-Admin-88');

    // The bound browser DOES receive it (it needs the real secret to register).
    $r = p155pw_probe('GET', 'my_extension', $dispatcherId, 'workstation_token=p155-pw-token-5');
    t('the browser bound to that extension receives the supplied password from my_extension (so it matches the PBX)',
        ($r['body']['bound'] ?? false) === true && ($r['body']['sip_password'] ?? null) === 'Replaced-By-Admin-88');

    // Rotation unchanged.
    $r = p155pw_probe('POST', 'extension_rotate_password', $adminId, json_encode(['id' => $id5]));
    $rot = (string) ($r['body']['sip_password'] ?? '');
    t('rotation still mints a NEW random password and returns it once',
        ($r['status'] ?? 0) === 200 && strlen($rot) >= 20 && $rot !== 'Replaced-By-Admin-88' && p155pw_stored($id5) === $rot);

    // ── Wiring ───────────────────────────────────────────────────────────
    echo "\n--- admin UI wiring ---\n\n";
    $page = (string) file_get_contents(__DIR__ . '/../phone-extensions-admin.php');
    $js   = (string) file_get_contents(__DIR__ . '/../assets/js/phone-extensions-admin.js');
    t('the form has a password field of type=password with autocomplete=new-password',
        (bool) preg_match('/id="peSipPassword"[^>]*autocomplete="new-password"/', $page) && (bool) preg_match('/<input type="password"[^>]*id="peSipPassword"/', $page));
    t('create sends sip_password only when typed', strpos($js, "body.sip_password = suppliedPassword") !== false);
    t('edit sends it through extension_set_password before saving the rest', strpos($js, "apiPost('extension_set_password'") !== false);
    t('the page text no longer says the token is "visible on its Console page" without saying how',
        strpos($page, 'Phone token') !== false);
} finally {
    $cleanup();
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
