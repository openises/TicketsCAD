<?php
/**
 * Phase 155, GH#108 slice S1 -- the `phone_register_scope` setting, end to end.
 *
 * A SIP registration lives only as long as the page's JavaScript, and
 * TicketsCAD is a multi-page application: "register on every page" means the
 * extension drops off the PBX on every navigation. The setting decides where
 * the browser phone registers ('phone_page' = the Console and the standalone
 * Phone window, the default; 'every_page'), and the widget also runs a
 * single-registrant election so two windows of one browser never both register.
 *
 * Writer  : phone_register_scope_save() via api/phone-extensions.php
 *           register_scope_save (action.manage_config, CSRF, audited)
 * Reader  : phone_register_scope() -> my_extension payload -> phone-widget.js
 * Proof   : the SAME extension on the SAME page gets 0 or 1 SIP user agents
 *           depending only on the setting (Part 3, jsdom with a FAKE JsSIP --
 *           no PBX exists in this test, so registration itself is simulated).
 *
 * get_variable() caches the whole settings table for the life of a process,
 * so every setting value is read by a FRESH subprocess (the real endpoint,
 * through tests/_p155_api_probe.php).
 *
 * @requires-db
 * Usage: php tests/test_phone_register_scope.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/_test_admin.php';
require_once __DIR__ . '/_phone_node_runner.php';
require_once __DIR__ . '/../inc/phone-extensions.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

$prefix = $GLOBALS['db_prefix'] ?? '';
echo "=== Phase 155 / S1 -- phone_register_scope ===\n\n";

function p155rs_probe(string $method, string $action, int $userId, string $payload = ''): ?array {
    $cmd = [PHP_BINARY ?: 'php', __DIR__ . '/_p155_api_probe.php', 'api/phone-extensions.php', $method, $action, (string) $userId, $payload];
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($proc)) return null;
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]); fclose($pipes[2]);
    proc_close($proc);
    $d = json_decode(trim((string) $out), true);
    return is_array($d) ? $d : null;
}
function p155rs_raw(string $name) {
    global $prefix;
    $row = db_fetch_one("SELECT `value` FROM `{$prefix}settings` WHERE `name` = ?", [$name]);
    return $row ? $row['value'] : null;
}
function p155rs_set(string $name, ?string $value): void {
    global $prefix;
    if ($value === null) { db_query("DELETE FROM `{$prefix}settings` WHERE `name` = ?", [$name]); return; }
    db_query("INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)", [$name, $value]);
}

$adminId = test_admin_user_id();
$orgAdminId = 900015501;   // Org Admin: holds action.manage_calls, NOT action.manage_config
$dispatcherId = 900015502; // Dispatcher: neither
$origScope = p155rs_raw('phone_register_scope');
$extIds = [];

$cleanup = function () use ($prefix, $orgAdminId, $dispatcherId, $origScope, &$extIds) {
    foreach ([$orgAdminId, $dispatcherId] as $uid) {
        try { db_query("DELETE FROM `{$prefix}user_roles` WHERE `user_id` = ?", [$uid]); } catch (Throwable $e) {}
        try { db_query("DELETE FROM `{$prefix}user` WHERE `id` = ?", [$uid]); } catch (Throwable $e) {}
    }
    foreach ($extIds as $id) { try { db_query("DELETE FROM `{$prefix}phone_extensions` WHERE id = ?", [$id]); } catch (Throwable $e) {} }
    try { p155rs_set('phone_register_scope', $origScope === null ? null : (string) $origScope); } catch (Throwable $e) {}
    try { db_query("DELETE FROM `{$prefix}newui_audit_log` WHERE `activity` = 'phone_register_scope.update' AND `user_name` = 'p155-probe'"); } catch (Throwable $e) {}
};
$cleanup();
register_shutdown_function($cleanup);

try {
    // ── Part 1: reader + validation (functions) ─────────────────────────
    echo "--- Part 1: reader and validation ---\n\n";
    t('the allowed values are exactly phone_page and every_page',
        phone_register_scope_values() === ['phone_page', 'every_page']);
    $threw = false;
    try { phone_register_scope_save('everywhere'); } catch (InvalidArgumentException $e) { $threw = true; }
    t('the writer refuses an unknown value', $threw);
    t('...and the stored value is untouched by a refused save', p155rs_raw('phone_register_scope') === ($origScope === false ? null : $origScope));

    // ── Part 2: the real endpoint, one fresh process per value ──────────
    echo "\n--- Part 2: my_extension payload, one subprocess per stored value ---\n\n";
    foreach (['phone_page', 'every_page'] as $v) {
        p155rs_set('phone_register_scope', $v);
        $r = p155rs_probe('GET', 'my_extension', $adminId, 'workstation_token=p155-no-such-token');
        t("stored '$v' -> unbound payload carries register_scope '$v'",
            ($r['status'] ?? 0) === 200 && ($r['body']['register_scope'] ?? null) === $v, json_encode($r));
    }
    p155rs_set('phone_register_scope', 'garbage-value');
    $r = p155rs_probe('GET', 'my_extension', $adminId, 'workstation_token=p155-no-such-token');
    t('a corrupt stored value is reported as the safe default phone_page', ($r['body']['register_scope'] ?? null) === 'phone_page');
    p155rs_set('phone_register_scope', null);
    $r = p155rs_probe('GET', 'my_extension', $adminId, 'workstation_token=p155-no-such-token');
    t('no stored value at all (a fresh install) -> phone_page', ($r['body']['register_scope'] ?? null) === 'phone_page');

    // Bound branch too: a real extension bound to a token, created by the real writer.
    $tok = 'p155-scope-token-' . bin2hex(random_bytes(4));
    $ext = (string) random_int(200000, 899999);
    $created = phone_extension_create($ext, 'S1 scope test', false, $tok, null);
    $extIds[] = $created['id'];
    p155rs_set('phone_register_scope', 'every_page');
    $r = p155rs_probe('GET', 'my_extension', $adminId, 'workstation_token=' . $tok);
    t('the BOUND payload also carries register_scope (the widget needs it either way)',
        ($r['body']['bound'] ?? false) === true && ($r['body']['register_scope'] ?? null) === 'every_page', json_encode($r['body'] ?? null));

    // ── Part 2b: the writer endpoint ────────────────────────────────────
    echo "\n--- Part 2b: register_scope_save (RBAC, CSRF, audit) ---\n\n";
    db_query("INSERT INTO `{$prefix}user` (`id`, `user`, `passwd`) VALUES (?, ?, ?)",
        [$orgAdminId, 'p155scopeorgadmin', password_hash('unused-test-fixture', PASSWORD_BCRYPT)]);
    db_query("INSERT INTO `{$prefix}user_roles` (`user_id`, `role_id`) VALUES (?, 2)", [$orgAdminId]);
    db_query("INSERT INTO `{$prefix}user` (`id`, `user`, `passwd`) VALUES (?, ?, ?)",
        [$dispatcherId, 'p155scopedispatcher', password_hash('unused-test-fixture', PASSWORD_BCRYPT)]);
    db_query("INSERT INTO `{$prefix}user_roles` (`user_id`, `role_id`) VALUES (?, 3)", [$dispatcherId]);
    p155rs_set('phone_register_scope', 'phone_page');

    $r = p155rs_probe('POST', 'register_scope_save', $adminId, json_encode(['register_scope' => 'every_page']));
    t('Super Admin saves the scope', ($r['status'] ?? 0) === 200 && ($r['body']['register_scope'] ?? null) === 'every_page', json_encode($r));
    t('...and it is really stored', p155rs_raw('phone_register_scope') === 'every_page');
    $aud = db_fetch_one("SELECT summary FROM `{$prefix}newui_audit_log` WHERE `activity` = 'phone_register_scope.update' AND `user_name` = 'p155-probe' ORDER BY id DESC LIMIT 1");
    t('...and audited', $aud !== null && strpos((string) $aud['summary'], 'every_page') !== false);

    p155rs_set('phone_register_scope', 'phone_page');
    $r = p155rs_probe('POST', 'register_scope_save', $orgAdminId, json_encode(['register_scope' => 'every_page']));
    t('an Org Admin (holds manage_calls, NOT manage_config) is refused with 403',
        ($r['status'] ?? 0) === 403, json_encode($r));
    t('...and the setting did not change', p155rs_raw('phone_register_scope') === 'phone_page');

    $r = p155rs_probe('POST', 'register_scope_save', $dispatcherId, json_encode(['register_scope' => 'every_page']));
    t('a Dispatcher is refused too', ($r['status'] ?? 0) === 403);

    $r = p155rs_probe('POST', 'register_scope_save', $adminId, json_encode(['register_scope' => 'every_page', 'csrf_token' => 'bogus']));
    t('a bad CSRF token is refused with 403', ($r['status'] ?? 0) === 403);
    t('...and nothing changed', p155rs_raw('phone_register_scope') === 'phone_page');

    $r = p155rs_probe('POST', 'register_scope_save', $adminId, json_encode(['register_scope' => 'sometimes']));
    t('an unknown value is a 400, not a stored corruption', ($r['status'] ?? 0) === 400 && p155rs_raw('phone_register_scope') === 'phone_page');

    $apiSrc = (string) file_get_contents(__DIR__ . '/../api/phone-extensions.php');
    t('the action gates on action.manage_config with NO `|| is_admin()` fallback (Phase 138 lesson)',
        strpos($apiSrc, "rbac_can('action.manage_config')") !== false
        && !preg_match('/manage_config\'\)\s*\|\|\s*is_admin\(\)/', $apiSrc));

    // ── Part 2c: the admin page ─────────────────────────────────────────
    echo "\n--- Part 2c: the admin card ---\n\n";
    $page = (string) file_get_contents(__DIR__ . '/../phone-extensions-admin.php');
    $js   = (string) file_get_contents(__DIR__ . '/../assets/js/phone-extensions-admin.js');
    // Rendered through the REAL page file as two different users.
    $renderPage = function (int $uid): array {
        $cmd = [PHP_BINARY ?: 'php', __DIR__ . '/_p155_page_probe.php', 'phone-extensions-admin.php', (string) $uid];
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
        $out = stream_get_contents($pipes[1]); fclose($pipes[1]); fclose($pipes[2]); proc_close($proc);
        $d = json_decode(trim((string) $out), true);
        return is_array($d) ? $d : ['status' => 0, 'body' => ''];
    };
    $asAdmin = $renderPage($adminId);
    $asOrgAdmin = $renderPage($orgAdminId);
    t('Super Admin sees the "Where the phone registers" card and the Constituents switch',
        ($asAdmin['status'] ?? 0) === 200 && strpos((string) $asAdmin['body'], 'id="peScopePanel"') !== false
        && strpos((string) $asAdmin['body'], 'id="peInternalConstituents"') !== false);
    t('an Org Admin (manage_calls, not manage_config) gets the page WITHOUT those install-wide controls',
        ($asOrgAdmin['status'] ?? 0) === 200 && strpos((string) $asOrgAdmin['body'], 'id="peScopePanel"') === false
        && strpos((string) $asOrgAdmin['body'], 'id="pePhoneScope"') === false && strpos((string) $asOrgAdmin['body'], 'id="peBtnSavePbx"') !== false);
    t('the card offers exactly the two real values',
        strpos($page, '<option value="phone_page">') !== false && strpos($page, '<option value="every_page">') !== false);
    t('phone-extensions-admin.js saves through register_scope_save', strpos($js, "apiPost('register_scope_save'") !== false);
    t('phone-extensions-admin.js loads the stored value into the select', strpos($js, 'settings.register_scope') !== false);

    // ── Part 3: the setting changes what the widget does ────────────────
    echo "\n--- Part 3: widget behaviour (jsdom, FAKE JsSIP, no PBX) ---\n\n";
    $nr = phone_node_run('_phone_register_scope_node.js');
    if ($nr !== null) { foreach ($nr as $row) { t($row[0], $row[1]); } }

} finally {
    $cleanup();
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
