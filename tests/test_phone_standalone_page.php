<?php
/**
 * Phase 155, GH#108 slice S1 -- phone.php, the standalone Phone window.
 *
 * A SIP registration lives only as long as the page that made it, and
 * TicketsCAD is a multi-page application. phone.php is the one page that never
 * navigates: a small window opened from the navbar phone button that holds the
 * registration and keeps ringing while the operator works elsewhere.
 *
 * Drives the REAL page file (tests/_p155_page_probe.php, no web server):
 *   - signed out  -> redirected (302), nothing rendered
 *   - signed in without screen.call_queue -> 403 with the permission named,
 *     and NOT the widget
 *   - signed in with it -> the widget template, the standalone flag, the CSRF
 *     token for the widget's own claim request, JsSIP, and NO navbar
 *
 * @requires-db
 * Usage: php tests/test_phone_standalone_page.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/_test_admin.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

$prefix = $GLOBALS['db_prefix'] ?? '';
echo "=== Phase 155 / S1 -- phone.php, the standalone Phone window ===\n\n";

function p155pg_probe(string $page, int $userId): ?array {
    $cmd = [PHP_BINARY ?: 'php', __DIR__ . '/_p155_page_probe.php', $page, (string) $userId];
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($proc)) return null;
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]); fclose($pipes[2]);
    proc_close($proc);
    $d = json_decode(trim((string) $out), true);
    return is_array($d) ? $d : null;
}

$adminId  = test_admin_user_id();
$noRoleId = 900015511; // a real account with NO roles: holds no permission at all
$operatorId = 900015512; // Operator (holds screen.call_queue per Phase 149)
$fieldId = 900015513;    // Field Unit (withheld screen.call_queue)
$cleanup = function () use ($prefix, $noRoleId, $operatorId, $fieldId) {
    foreach ([$noRoleId, $operatorId, $fieldId] as $uid) {
        try { db_query("DELETE FROM `{$prefix}user_roles` WHERE `user_id` = ?", [$uid]); } catch (Throwable $e) {}
        try { db_query("DELETE FROM `{$prefix}user` WHERE `id` = ?", [$uid]); } catch (Throwable $e) {}
    }
};
$cleanup();
register_shutdown_function($cleanup);

try {
    $src = (string) file_get_contents(__DIR__ . '/../phone.php');
    t('phone.php redirects a signed-out visitor to login.php', strpos($src, "header('Location: login.php')") !== false);
    t('phone.php gates on screen.call_queue (the permission the navbar phone button already uses)',
        strpos($src, "rbac_can('screen.call_queue')") !== false);
    t('...with no `|| is_admin()` fallback', !preg_match('/screen\.call_queue\'\)\s*\|\|\s*is_admin/', $src));

    // Roles: find ones that do / do not hold the permission today (never assume).
    $holds = function (int $roleId) use ($prefix): bool {
        return (bool) db_fetch_value(
            "SELECT COUNT(*) FROM `{$prefix}role_permissions` rp JOIN `{$prefix}permissions` p ON p.id = rp.permission_id
              WHERE rp.role_id = ? AND p.code = 'screen.call_queue'", [$roleId]);
    };
    $operatorRole = (int) db_fetch_value("SELECT id FROM `{$prefix}roles` WHERE name = 'Operator' LIMIT 1");
    $fieldRole    = (int) db_fetch_value("SELECT id FROM `{$prefix}roles` WHERE name = 'Field Unit' LIMIT 1");

    foreach ([[$noRoleId, 'p155nopermphone'], [$operatorId, 'p155opphone'], [$fieldId, 'p155fieldphone']] as $u) {
        db_query("INSERT INTO `{$prefix}user` (`id`, `user`, `passwd`) VALUES (?, ?, ?)",
            [$u[0], $u[1], password_hash('unused-test-fixture', PASSWORD_BCRYPT)]);
    }
    if ($operatorRole) { db_query("INSERT INTO `{$prefix}user_roles` (`user_id`, `role_id`) VALUES (?, ?)", [$operatorId, $operatorRole]); }
    if ($fieldRole)    { db_query("INSERT INTO `{$prefix}user_roles` (`user_id`, `role_id`) VALUES (?, ?)", [$fieldId, $fieldRole]); }

    echo "\n--- signed out ---\n\n";
    $r = p155pg_probe('phone.php', 0);
    t('a signed-out request is redirected (302) and renders nothing',
        ($r['status'] ?? 0) === 302 && trim((string) ($r['body'] ?? 'x')) === '', json_encode($r['status'] ?? null));

    echo "\n--- signed in WITHOUT the permission ---\n\n";
    $r = p155pg_probe('phone.php', $noRoleId);
    t('an account with no permission gets 403', ($r['status'] ?? 0) === 403, (string) ($r['status'] ?? ''));
    t('...the page names the missing permission', strpos((string) ($r['body'] ?? ''), 'screen.call_queue') !== false);
    t('...and does NOT contain the widget, the credentials flag or any script',
        strpos((string) ($r['body'] ?? ''), 'tpl-phone-widget') === false
        && strpos((string) ($r['body'] ?? ''), 'PHONE_CSRF') === false
        && strpos((string) ($r['body'] ?? ''), '<script') === false);
    if ($fieldRole && !$holds($fieldRole)) {
        $r = p155pg_probe('phone.php', $fieldId);
        t('a Field Unit (role withheld screen.call_queue) is refused', ($r['status'] ?? 0) === 403);
    } else {
        echo "SKIP: this install grants Field Unit screen.call_queue (or has no such role); the no-role refusal above covers the gate\n";
    }

    echo "\n--- signed in WITH the permission ---\n\n";
    $r = p155pg_probe('phone.php', $adminId);
    $body = (string) ($r['body'] ?? '');
    t('Super Admin gets the page (200)', ($r['status'] ?? 0) === 200);
    t('...it carries the widget template', strpos($body, '<template id="tpl-phone-widget">') !== false);
    t('...it marks itself standalone before the widget script runs',
        strpos($body, 'window.PHONE_STANDALONE = true') !== false
        && strpos($body, 'window.PHONE_STANDALONE = true') < strpos($body, 'assets/js/phone-widget.js'));
    t('...it hands the widget a real CSRF token (for the claim request)',
        (bool) preg_match('/window\.PHONE_CSRF = "[0-9a-f]{16,}";/', $body)
        && (bool) preg_match('/<meta name="csrf-token" content="[0-9a-f]{16,}">/', $body));
    t('...JsSIP, the workstation helper, the logic and the widget load, in that order',
        (bool) preg_match('#jssip-3\.10\.1\.min\.js.*console-workstation\.js.*phone-dial-logic\.js.*phone-widget\.js#s', $body));
    t('...the scripts carry a cache-busting version', substr_count($body, '.js?v=') >= 4);
    t('...and there is NO navbar, banner or SSE in the window (minimal chrome)',
        strpos($body, 'navPhoneToggleBtn') === false && strpos($body, 'callAlertBanner') === false
        && strpos($body, 'event-bus.js') === false && strpos($body, 'EventSource') === false);
    t('...the page title names the phone, so the taskbar button does', strpos($body, '<title>Phone') !== false);

    if ($operatorRole && $holds($operatorRole)) {
        $r = p155pg_probe('phone.php', $operatorId);
        t('an Operator (holds screen.call_queue) gets the page too', ($r['status'] ?? 0) === 200
            && strpos((string) ($r['body'] ?? ''), 'tpl-phone-widget') !== false);
    }
} finally {
    $cleanup();
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
