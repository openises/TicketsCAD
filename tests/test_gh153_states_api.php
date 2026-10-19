<?php
/**
 * GH#153 — api/states.php end to end, through the REAL endpoint (included in
 * a fresh CLI process by tests/_p155_api_probe.php, with a real session, the
 * real csrf_verify() and the real RBAC tables).
 *
 *   - Every action, read or write, is refused (403) for a user without
 *     action.manage_config — an Org Admin and a Dispatcher, throwaway
 *     fixture accounts — and the list is unchanged afterwards.
 *   - A bad CSRF token is refused for every write.
 *   - Super Admin: list, presets, usage, create/update/delete, preset
 *     preview/apply (and a stale preview refused with 409), CSV import
 *     preview/apply (errors block the apply), export and preset CSV
 *     downloads, the field label; changes are audited.
 *   - api/incident-types.php: an emptied list is served as [] with
 *     states_from_db = true, so the browser does not put the US back.
 *
 * Leaves the list, the captions, the flag, the fixture users and their audit
 * rows as it found them.
 *
 * @requires-db
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/_test_admin.php';
require_once __DIR__ . '/../inc/states.php';

$pass = 0; $fail = 0;
function gh153a_t(string $label, bool $cond, string $detail = ''): void
{
    global $pass, $fail;
    echo ($cond ? '[PASS] ' : '[FAIL] ') . $label . ($cond || $detail === '' ? '' : ' — ' . substr($detail, 0, 400)) . "\n";
    $cond ? $pass++ : $fail++;
}

/** One request to a real api/*.php file in a fresh process. */
function gh153a_call(string $api, string $method, string $action, int $userId, $payload = ''): array
{
    if (is_array($payload)) {
        $payload = $method === 'GET' ? http_build_query($payload) : json_encode($payload, JSON_UNESCAPED_UNICODE);
    }
    $cmd = [PHP_BINARY ?: 'php', __DIR__ . '/_p155_api_probe.php', $api, $method, $action, (string) $userId, (string) $payload];
    $out = tempnam(sys_get_temp_dir(), 'g153');
    $proc = proc_open($cmd, [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'], 1 => ['file', $out, 'w'], 2 => ['file', $out . '.err', 'w']],
        $pipes, dirname(__DIR__), null, ['bypass_shell' => true]);
    if (!is_resource($proc)) { return ['status' => -1, 'body' => 'proc_open failed']; }
    proc_close($proc);
    $raw = (string) @file_get_contents($out);
    @unlink($out); @unlink($out . '.err');
    $d = json_decode(trim($raw), true);
    return is_array($d) ? $d : ['status' => -1, 'body' => $raw];
}
function gh153a_api(string $method, string $action, int $userId, $payload = ''): array
{
    return gh153a_call('api/states.php', $method, $action, $userId, $payload);
}

echo "=== GH#153 — api/states.php ===\n\n";

try {
    db_fetch_value('SELECT COUNT(*) FROM ' . db_table('states_translator'));
} catch (Throwable $e) {
    echo "SKIP: states_translator is not reachable\n\n=== 0 passed, 0 failed ===\n";
    exit(0);
}

$T = db_table('states_translator');
$adminId = test_admin_user_id();
$orgAdminId = 900015301;     // role 2 — does NOT hold action.manage_config
$dispatcherId = 900015302;   // role 3
$snapRows = db_fetch_all("SELECT `id`, `code`, `name` FROM {$T} ORDER BY `id`");
$snapFlag = db_fetch_value('SELECT `value` FROM ' . db_table('settings') . ' WHERE `name` = ?', [STATES_CUSTOMIZED_SETTING]);
$snapCaps = db_fetch_all('SELECT `caption_key`, `lang`, `value`, `category` FROM ' . db_table('captions_i18n')
    . " WHERE `caption_key` IN ('form.state', 'newinc.label.state', 'field.state')");
$auditFrom = (int) db_fetch_value('SELECT COALESCE(MAX(`id`), 0) FROM ' . db_table('newui_audit_log'));

$cleanup = function () use ($T, $snapRows, $snapFlag, $snapCaps, $orgAdminId, $dispatcherId, $auditFrom) {
    try {
        db_query("DELETE FROM {$T}");
        foreach ($snapRows as $r) {
            db_query("INSERT INTO {$T} (`id`, `code`, `name`) VALUES (?, ?, ?)", [$r['id'], $r['code'], $r['name']]);
        }
        if ($snapFlag === false || $snapFlag === null) {
            db_query('DELETE FROM ' . db_table('settings') . ' WHERE `name` = ?', [STATES_CUSTOMIZED_SETTING]);
        } else {
            db_query('UPDATE ' . db_table('settings') . ' SET `value` = ? WHERE `name` = ?', [$snapFlag, STATES_CUSTOMIZED_SETTING]);
        }
        db_query('DELETE FROM ' . db_table('captions_i18n') . " WHERE `caption_key` IN ('form.state', 'newinc.label.state', 'field.state')");
        foreach ($snapCaps as $c) {
            db_query('INSERT INTO ' . db_table('captions_i18n') . ' (`caption_key`, `lang`, `value`, `category`) VALUES (?, ?, ?, ?)',
                [$c['caption_key'], $c['lang'], $c['value'], $c['category']]);
        }
        foreach ([$orgAdminId, $dispatcherId] as $uid) {
            db_query('DELETE FROM ' . db_table('user_roles') . ' WHERE `user_id` = ?', [$uid]);
            db_query('DELETE FROM ' . db_table('user') . ' WHERE `id` = ?', [$uid]);
        }
        db_query('DELETE FROM ' . db_table('newui_audit_log') . " WHERE `id` > ? AND `user_name` = 'p155-probe'
                  AND `target_type` IN ('state_province', 'caption')", [$auditFrom]);
    } catch (Throwable $e) {
        echo "[cleanup] " . $e->getMessage() . "\n";
    }
};
foreach ([$orgAdminId, $dispatcherId] as $uid) {
    db_query('DELETE FROM ' . db_table('user_roles') . ' WHERE `user_id` = ?', [$uid]);
    db_query('DELETE FROM ' . db_table('user') . ' WHERE `id` = ?', [$uid]);
}
register_shutdown_function($cleanup);

db_query('INSERT INTO ' . db_table('user') . ' (`id`, `user`, `passwd`) VALUES (?, ?, ?)',
    [$orgAdminId, 'gh153orgadmin', password_hash('unused-test-fixture', PASSWORD_BCRYPT)]);
db_query('INSERT INTO ' . db_table('user_roles') . ' (`user_id`, `role_id`) VALUES (?, 2)', [$orgAdminId]);
db_query('INSERT INTO ' . db_table('user') . ' (`id`, `user`, `passwd`) VALUES (?, ?, ?)',
    [$dispatcherId, 'gh153dispatcher', password_hash('unused-test-fixture', PASSWORD_BCRYPT)]);
db_query('INSERT INTO ' . db_table('user_roles') . ' (`user_id`, `role_id`) VALUES (?, 3)', [$dispatcherId]);

// A known starting list: the 50 states + DC.
db_query("DELETE FROM {$T}");
foreach (states_build_selection(['us50' => ['dc']])['rows'] as [$c, $n]) {
    db_query("INSERT INTO {$T} (`code`, `name`) VALUES (?, ?)", [$c, $n]);
}
$hash0 = states_list_hash(states_list());
$mnId = (int) db_fetch_value("SELECT `id` FROM {$T} WHERE `code` = 'MN'");
$csvGood = "code,name\nON,Ontario\nQC,Quebec\n";

// ── RBAC: everything refused without action.manage_config ─────────────
echo "--- users without action.manage_config ---\n";
$writes = [
    ['create', ['code' => 'ZQ', 'name' => 'Nope']],
    ['update', ['id' => $mnId, 'code' => 'MN', 'name' => 'Changed']],
    ['delete', ['id' => $mnId]],
    ['preset_preview', ['selection' => ['ca' => []], 'mode' => 'replace']],
    ['preset_apply', ['selection' => ['ca' => []], 'mode' => 'replace']],
    ['import_preview', ['csv' => $csvGood, 'mode' => 'replace']],
    ['import_apply', ['csv' => $csvGood, 'mode' => 'replace']],
    ['set_label', ['label' => 'Province', 'lang' => 'en']],
];
$reads = [['list', ''], ['presets', ''], ['usage', 'codes=MN'], ['export', ''], ['preset_csv', 'preset=ca']];
foreach ([$orgAdminId => 'an Org Admin', $dispatcherId => 'a Dispatcher'] as $uid => $who) {
    foreach ($writes as [$action, $body]) {
        $r = gh153a_api('POST', $action, $uid, $body + ['action' => $action]);
        gh153a_t("$who is refused POST $action (403)", ($r['status'] ?? 0) === 403, json_encode($r));
    }
    foreach ($reads as [$action, $qs]) {
        $r = gh153a_api('GET', $action, $uid, $qs);
        gh153a_t("$who is refused GET $action (403)", ($r['status'] ?? 0) === 403, json_encode($r));
    }
}
gh153a_t('...and after all of that the list is exactly as it was', states_list_hash(states_list()) === $hash0 && states_field_label('en') !== 'Province');

echo "\n--- CSRF ---\n";
foreach ($writes as [$action, $body]) {
    $r = gh153a_api('POST', $action, $adminId, $body + ['action' => $action, 'csrf_token' => 'bogus']);
    gh153a_t("Super Admin with a bad CSRF token is refused POST $action (403)", ($r['status'] ?? 0) === 403, json_encode($r));
}
gh153a_t('...and the list is still unchanged', states_list_hash(states_list()) === $hash0);

// ── Super Admin ────────────────────────────────────────────────────────
echo "\n--- Super Admin ---\n";
$r = gh153a_api('GET', 'list', $adminId);
$b = $r['body'] ?? [];
gh153a_t('list: 200 with states, count, schema, field_label, label_lang, languages, customized',
    ($r['status'] ?? 0) === 200 && count($b['states'] ?? []) === 51 && ($b['count'] ?? 0) === 51
    && isset($b['schema']['utf8mb4'], $b['field_label'], $b['label_lang'], $b['languages']) && array_key_exists('customized', $b), json_encode($r));
$r = gh153a_api('GET', 'presets', $adminId);
gh153a_t('presets: the catalog, quick picks and label choices', ($r['status'] ?? 0) === 200 && count($r['body']['presets'] ?? []) === 11
    && count($r['body']['quick_picks'] ?? []) >= 5 && in_array('Province', $r['body']['label_choices'] ?? [], true));

$r = gh153a_api('POST', 'create', $adminId, ['action' => 'create', 'code' => 'zq', 'name' => 'Zed Québec']);
$zqId = (int) ($r['body']['state']['id'] ?? 0);
gh153a_t('create: 200 and the entry in capitals with its accent', ($r['status'] ?? 0) === 200 && ($r['body']['state']['code'] ?? '') === 'ZQ'
    && db_fetch_value("SELECT `name` FROM {$T} WHERE `code` = 'ZQ'") === 'Zed Québec', json_encode($r));
$r = gh153a_api('POST', 'create', $adminId, ['action' => 'create', 'code' => 'ZQ', 'name' => 'Again']);
gh153a_t('create: a duplicate code is a 400 with a plain reason', ($r['status'] ?? 0) === 400 && strpos((string) ($r['body']['error'] ?? ''), 'already on the list') !== false, json_encode($r));
$r = gh153a_api('POST', 'create', $adminId, ['action' => 'create', 'code' => 'TOOLONG', 'name' => 'x']);
gh153a_t('create: a bad code is a 400', ($r['status'] ?? 0) === 400);
$r = gh153a_api('POST', 'update', $adminId, ['action' => 'update', 'id' => $zqId, 'code' => 'ZR', 'name' => 'Zed R']);
gh153a_t('update: 200 and changed', ($r['status'] ?? 0) === 200 && ($r['body']['changed'] ?? false) === true && ($r['body']['old_code'] ?? '') === 'ZQ', json_encode($r));
$r = gh153a_api('GET', 'usage', $adminId, 'codes=ZR,MN');
gh153a_t('usage: 200, one answer per code', ($r['status'] ?? 0) === 200 && isset($r['body']['usage']['ZR'], $r['body']['usage']['MN']), json_encode($r));
$r = gh153a_api('POST', 'delete', $adminId, ['action' => 'delete', 'id' => $zqId]);
gh153a_t('delete: 200', ($r['status'] ?? 0) === 200 && ($r['body']['deleted']['code'] ?? '') === 'ZR', json_encode($r));
$r = gh153a_api('POST', 'delete', $adminId, ['action' => 'delete', 'id' => $zqId]);
gh153a_t('delete again: 404', ($r['status'] ?? 0) === 404, json_encode($r));
$aud = db_fetch_all('SELECT `activity` FROM ' . db_table('newui_audit_log') . " WHERE `id` > ? AND `user_name` = 'p155-probe' AND `target_type` = 'state_province'", [$auditFrom]);
gh153a_t('create, update and delete are in the audit log under the acting user', count($aud) === 3
    && array_column($aud, 'activity') === ['create', 'update', 'delete'], json_encode($aud));

$r = gh153a_api('POST', 'preset_preview', $adminId, ['action' => 'preset_preview', 'selection' => ['ca' => []], 'mode' => 'replace']);
$pv = $r['body'] ?? [];
gh153a_t('preset_preview: 200 with the diff, the record counts and a list hash', ($r['status'] ?? 0) === 200
    && count($pv['adds'] ?? []) === 13 && count($pv['removes'] ?? []) === 51 && isset($pv['list_hash'], $pv['records_using_removed'])
    && ($pv['label_suggestion'] ?? '') === 'Province' && array_key_exists('usage_by', $pv['removes'][0] ?? []), json_encode($r));
gh153a_t('...and changed nothing', states_count() === 51);
$r = gh153a_api('POST', 'preset_apply', $adminId, ['action' => 'preset_apply', 'selection' => ['ca' => []], 'mode' => 'replace', 'list_hash' => 'stale']);
gh153a_t('preset_apply with a stale preview: 409 and nothing changed', ($r['status'] ?? 0) === 409 && states_count() === 51, json_encode($r));
$r = gh153a_api('POST', 'preset_apply', $adminId, ['action' => 'preset_apply', 'selection' => ['ca' => []], 'mode' => 'replace', 'list_hash' => $pv['list_hash'] ?? '']);
gh153a_t('preset_apply: 200, the list is now Canada', ($r['status'] ?? 0) === 200 && ($r['body']['final_count'] ?? 0) === 13 && states_count() === 13
    && ($r['body']['label_suggestion'] ?? '') === 'Province', json_encode($r));
$r = gh153a_api('POST', 'preset_preview', $adminId, ['action' => 'preset_preview', 'selection' => ['zz' => []], 'mode' => 'replace']);
gh153a_t('an unknown preset is a 400', ($r['status'] ?? 0) === 400);
$r = gh153a_api('POST', 'preset_preview', $adminId, ['action' => 'preset_preview', 'selection' => ['ca' => []], 'mode' => 'merge']);
gh153a_t('an unknown mode is a 400', ($r['status'] ?? 0) === 400);

$bad = "code,name\nON,Ontario\non,Again\nTOOLONG,x\n";
$r = gh153a_api('POST', 'import_preview', $adminId, ['action' => 'import_preview', 'csv' => $bad, 'mode' => 'add']);
gh153a_t('import_preview of a bad file: the problems, with line numbers', ($r['status'] ?? 0) === 200 && ($r['body']['ok'] ?? true) === false
    && array_column($r['body']['errors'] ?? [], 'line') === [3, 4], json_encode($r));
$r = gh153a_api('POST', 'import_apply', $adminId, ['action' => 'import_apply', 'csv' => $bad, 'mode' => 'add']);
gh153a_t('import_apply of a bad file: 400 and nothing changed', ($r['status'] ?? 0) === 400 && states_count() === 13);
$imp = "\xEF\xBB\xBFcode,name\r\nMIC,Michoacán de Ocampo\r\nON,Ontario\r\n";
$r = gh153a_api('POST', 'import_preview', $adminId, ['action' => 'import_preview', 'csv' => $imp, 'mode' => 'add']);
gh153a_t('import_preview (add): 1 to add, ON skipped', ($r['status'] ?? 0) === 200 && count($r['body']['adds'] ?? []) === 1
    && count($r['body']['skipped'] ?? []) === 1, json_encode($r));
$r = gh153a_api('POST', 'import_apply', $adminId, ['action' => 'import_apply', 'csv' => $imp, 'mode' => 'add', 'list_hash' => $r['body']['list_hash'] ?? '']);
gh153a_t('import_apply: 200, Michoacán added with its accent', ($r['status'] ?? 0) === 200 && states_count() === 14
    && db_fetch_value("SELECT `name` FROM {$T} WHERE `code` = 'MIC'") === 'Michoacán de Ocampo', json_encode($r));
$imported = db_fetch_one('SELECT `activity`, `summary` FROM ' . db_table('newui_audit_log') . " WHERE `id` > ? AND `user_name` = 'p155-probe' AND `activity` = 'import' AND `target_type` = 'state_province'", [$auditFrom]);
gh153a_t('the import is audited as an import', $imported !== null && strpos((string) $imported['summary'], 'CSV import') !== false);

$r = gh153a_api('GET', 'export', $adminId);
$csv = is_string($r['body'] ?? null) ? $r['body'] : '';
gh153a_t('export: a CSV with the BOM, the header and every entry', ($r['status'] ?? 0) === 200 && strpos($csv, "\xEF\xBB\xBFcode,name\r\n") === 0
    && substr_count($csv, "\r\n") === 15 && strpos($csv, 'MIC,Michoacán de Ocampo') !== false, substr($csv, 0, 120));
$r = gh153a_api('GET', 'preset_csv', $adminId, 'preset=us50');
$csv = is_string($r['body'] ?? null) ? $r['body'] : '';
gh153a_t('preset_csv for US 50 includes its default option (DC): 51 rows', ($r['status'] ?? 0) === 200 && substr_count($csv, "\r\n") === 52
    && strpos($csv, 'DC,District of Columbia') !== false);
$r = gh153a_api('GET', 'preset_csv', $adminId, ['selection' => json_encode(['us50' => ['territories']])]);
$csv = is_string($r['body'] ?? null) ? $r['body'] : '';
gh153a_t('preset_csv for a wizard selection: 50 + 5 territories, no DC', substr_count($csv, "\r\n") === 56 && strpos($csv, 'DC,') === false
    && strpos($csv, 'PR,Puerto Rico') !== false);

$r = gh153a_api('POST', 'set_label', $adminId, ['action' => 'set_label', 'label' => 'Province', 'lang' => 'en']);
gh153a_t('set_label: 200 and form.state is now "Province"', ($r['status'] ?? 0) === 200 && states_field_label('en') === 'Province', json_encode($r));
$r = gh153a_api('POST', 'set_label', $adminId, ['action' => 'set_label', 'label' => '', 'lang' => 'en']);
gh153a_t('set_label refuses an empty label (400)', ($r['status'] ?? 0) === 400);
$r = gh153a_api('POST', 'nonsense', $adminId, ['action' => 'nonsense']);
gh153a_t('an unknown action is a 404', ($r['status'] ?? 0) === 404);

// ── the page itself, rendered as each user ─────────────────────────────
echo "\n--- states-admin.php ---\n";
$page = function (int $uid): array {
    $cmd = [PHP_BINARY ?: 'php', __DIR__ . '/_p155_page_probe.php', 'states-admin.php', (string) $uid];
    $out = tempnam(sys_get_temp_dir(), 'g153p');
    $proc = proc_open($cmd, [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'], 1 => ['file', $out, 'w'], 2 => ['file', $out . '.e', 'w']],
        $pipes, dirname(__DIR__), null, ['bypass_shell' => true]);
    if (is_resource($proc)) { proc_close($proc); }
    $d = json_decode((string) @file_get_contents($out), true);
    @unlink($out); @unlink($out . '.e');
    return is_array($d) ? $d : ['status' => -1, 'body' => ''];
};
$p = $page($adminId);
gh153a_t('Super Admin gets the page: the list, the wizard, the import dialog, the label card',
    ($p['status'] ?? 0) === 200 && strpos($p['body'], 'id="stRows"') !== false && strpos($p['body'], 'id="stWizard"') !== false
    && strpos($p['body'], 'id="stImport"') !== false && strpos($p['body'], 'id="stLabelChoice"') !== false
    && strpos($p['body'], 'assets/js/states-admin.js?v=') !== false && strpos($p['body'], 'probe exception') === false,
    substr((string) ($p['body'] ?? ''), 0, 300));
gh153a_t('...with its CSRF token in the meta tag the script reads', (bool) preg_match('/<meta name="csrf-token" content="[0-9a-f]{16,}">/', (string) ($p['body'] ?? '')));
foreach ([$orgAdminId => 'an Org Admin', $dispatcherId => 'a Dispatcher'] as $uid => $who) {
    $p = $page($uid);
    gh153a_t("$who gets 403 and the permission notice, not the page", ($p['status'] ?? 0) === 403
        && strpos((string) $p['body'], 'Permission required') !== false && strpos((string) $p['body'], 'id="stWizard"') === false);
}
$p = $page(0);
// (The CLI SAPI records no headers, so the redirect itself is not visible here;
// what matters is that nothing of the page is sent.)
gh153a_t('someone not signed in gets nothing of the page', trim((string) ($p['body'] ?? 'x')) === '');

// ── what every dropdown reads ──────────────────────────────────────────
echo "\n--- api/incident-types.php (every State dropdown) ---\n";
$r = gh153a_call('api/incident-types.php', 'GET', '', $dispatcherId);
gh153a_t('a Dispatcher still READS the list (14 entries, states_from_db = true)', ($r['status'] ?? 0) === 200
    && count($r['body']['states'] ?? []) === 14 && ($r['body']['states_from_db'] ?? null) === true, json_encode(array_keys($r['body'] ?? [])));
gh153a_t('...each entry is {code, name} only', array_keys($r['body']['states'][0] ?? []) === ['code', 'name']);
db_query("DELETE FROM {$T}");
$r = gh153a_call('api/incident-types.php', 'GET', '', $dispatcherId);
gh153a_t('an emptied list is served as [] WITH states_from_db = true (the browser must not substitute the US)',
    ($r['status'] ?? 0) === 200 && ($r['body']['states'] ?? null) === [] && ($r['body']['states_from_db'] ?? null) === true, json_encode($r['body']['states_from_db'] ?? null));

$cleanup();
gh153a_t('the original list is back', db_fetch_all("SELECT `id`, `code`, `name` FROM {$T} ORDER BY `id`") === $snapRows);

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
