<?php
/**
 * GH#153 — the States / Provinces page, its script and its endpoint agree,
 * and the dropdowns behave with a configurable list.
 *
 * Part 1 (structure, runs everywhere):
 *   - every element id assets/js/states-admin.js looks up exists in
 *     states-admin.php, and every <button> there is type="button";
 *   - the script is one strict-mode ES5 IIFE and never uses innerHTML;
 *   - every action the script sends exists in api/states.php, every key it
 *     sends is one the endpoint reads, and the keys it reads are ones the
 *     endpoint/library emit;
 *   - the page and the API gate on the SAME permission, with no is_admin()
 *     fallback; the Settings sidebar links the page for the same holders;
 *   - api/incident-types.php emits states_from_db and both dropdown scripts
 *     honour it; New Incident's two geocoder paths use the shared helper;
 *   - the new caption key is seeded; the guide exists and is linked.
 *
 * Part 2 (behaviour, needs Node + jsdom — CI installs it; locally set
 * NODE_PATH): the REAL scripts run in jsdom against the REAL library's
 * answers — TCADStates with an emptied list, the geocoder helper with
 * Canadian/Mexican addresses, and a full click-through of the admin page
 * (wizard, import, edit, delete, add, XSS-shaped names rendered as text).
 * Without Node + jsdom Part 2 prints SKIP.
 *
 * @requires-db
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/states.php';

$pass = 0; $fail = 0;
function gh153w_t(string $label, bool $cond, string $detail = ''): void
{
    global $pass, $fail;
    echo ($cond ? '[PASS] ' : '[FAIL] ') . $label . ($cond || $detail === '' ? '' : ' — ' . substr($detail, 0, 400)) . "\n";
    $cond ? $pass++ : $fail++;
}

$root = realpath(__DIR__ . '/..');
$js = (string) file_get_contents($root . '/assets/js/states-admin.js');
$page = (string) file_get_contents($root . '/states-admin.php');
$api = (string) file_get_contents($root . '/api/states.php');
$lib = (string) file_get_contents($root . '/inc/states.php');
$sidebar = (string) file_get_contents($root . '/inc/config-sidebar.php');
$strip = static function (string $s): string {
    $s = preg_replace('#/\*.*?\*/#s', '', $s);
    return (string) preg_replace('#^\s*//.*$#m', '', $s);
};
$jsCode = $strip($js);

echo "=== GH#153 — States / Provinces wiring ===\n\n--- Part 1: structure ---\n";

preg_match_all("/\\\$\('([A-Za-z0-9_]+)'\)/", $jsCode, $m);
$wanted = array_values(array_unique($m[1]));
preg_match_all('/\bid="([A-Za-z0-9_\-]+)"/', $page, $m2);
$have = array_values(array_unique($m2[1]));
preg_match_all("/\.id = '([A-Za-z0-9_]+)'/", $jsCode, $dyn);
$missing = array_values(array_diff($wanted, $have, $dyn[1]));
gh153w_t('every element id the script looks up exists in the page (' . count($wanted) . ' ids)', $missing === [] && count($wanted) > 30, implode(', ', $missing));
$unusedAllowed = ['main-content', 'stBtnExport', 'stWizardTitle', 'stImportTitle', 'stWizStep1', 'stWizStep2', 'stWizStep3', 'stWizStep4', 'stWizStep5', 'stWizModeReplace', 'stWizModeAdd'];
$unused = array_values(array_diff($have, $wanted, $unusedAllowed));
gh153w_t('every id in the page is used by the script (no dead controls)', $unused === [], implode(', ', $unused));
preg_match_all('/<button\b[^>]*>/i', $page, $btns);
$untyped = array_filter($btns[0], static function ($b) { return !preg_match('/\btype="button"/', $b); });
gh153w_t('every <button> in the page is type="button" (' . count($btns[0]) . ' checked)', $untyped === [] && count($btns[0]) > 10);
gh153w_t('the script never uses innerHTML / outerHTML / insertAdjacentHTML / document.write / inline handlers',
    !preg_match('/\.innerHTML|\.outerHTML|insertAdjacentHTML|document\.write|onclick\s*=/', $jsCode));
gh153w_t('ES5 only: no arrow functions, let, const or template literals', !preg_match('/=>|\blet\s|\bconst\s|`/', $jsCode));
gh153w_t('the script is one IIFE in strict mode', preg_match('/^\s*\(function \(\) \{\s*\'use strict\';/m', $jsCode) === 1
    && substr(rtrim($jsCode), -5) === '})();');

echo "\n--- the script and the endpoint speak the same language ---\n";
preg_match_all("/action:\s*'([a-z_]+)'/", $jsCode, $pm);
$posted = array_values(array_unique($pm[1]));
preg_match_all("/case\s+'([a-z_]+)':/", $api, $cm);
gh153w_t('every POST action the script sends is handled by the endpoint (' . implode(', ', $posted) . ')',
    $posted !== [] && array_diff($posted, $cm[1]) === [], implode(',', array_diff($posted, $cm[1])));
gh153w_t('...and the endpoint has no POST action the page never uses', array_diff($cm[1], $posted) === [], implode(',', array_diff($cm[1], $posted)));
preg_match_all('/action=([a-z_]+)/', $jsCode . $page, $gm);
$gets = array_values(array_unique($gm[1]));
preg_match_all("/\\\$action === '([a-z_]+)'/", $api, $am);
gh153w_t('every GET action the page uses exists (' . implode(', ', $gets) . ')', array_diff($gets, $am[1]) === [], implode(',', array_diff($gets, $am[1])));
preg_match_all("/apiPost\(\{\s*([^}]*)\}/", $jsCode, $bm);
$sentKeys = [];
foreach ($bm[1] as $b) {
    preg_match_all('/([a-z_]+)\s*:/', $b, $km);
    $sentKeys = array_merge($sentKeys, $km[1]);
}
$sentKeys = array_values(array_unique($sentKeys));
$readKeys = [];
preg_match_all("/\\\$input\['([a-z_]+)'\]/", $api, $rk);
$readKeys = array_values(array_unique(array_merge($rk[1], ['csrf_token'])));
gh153w_t('every key the script POSTs is one the endpoint reads (' . implode(', ', $sentKeys) . ')', array_diff($sentKeys, $readKeys) === [],
    implode(',', array_diff($sentKeys, $readKeys)));
foreach (['states', 'count', 'schema', 'field_label', 'label_lang', 'languages'] as $k) {
    gh153w_t("the list answer carries '$k' (the script reads it)", strpos($api, "'$k'") !== false && strpos($jsCode, '.' . $k) !== false);
}
foreach (['adds', 'removes', 'renames', 'skipped', 'unchanged', 'current_count', 'final_count', 'list_hash', 'too_many',
          'records_using_removed', 'usage_by', 'existing_name', 'old_name', 'new_name', 'kept_from', 'dropped_from'] as $k) {
    gh153w_t("the preview carries '$k' and the script reads it", strpos($lib, "'$k'") !== false && strpos($jsCode, '.' . $k) !== false);
}

echo "\n--- permissions ---\n";
gh153w_t("the page gates on rbac_can('action.manage_config')", strpos($page, "if (!rbac_can('action.manage_config'))") !== false);
gh153w_t('...and so does the endpoint, before anything else', (bool) preg_match("/require_once[^\n]*inc\/states\.php';\s*ini_set\('display_errors', '0'\);\s*\\\$method = [^\n]*\s*if \(!rbac_can\('action\.manage_config'\)\)/", $api));
gh153w_t('neither falls back to is_admin()', strpos($strip($page), 'is_admin(') === false && strpos($strip($api), 'is_admin(') === false);
gh153w_t('every POST checks CSRF before looking at the action', (bool) preg_match("/csrf_verify\(\(string\) \\\$input\['csrf_token'\]\)\)\s*\{\s*json_error\([^;]*403\);\s*\}\s*\\\$action = /", $api));
gh153w_t('the Settings sidebar links the page, for holders of the same permission',
    (bool) preg_match("/if \(\\\$canCfg\) \{\s*_cfg_link\('states-provinces', 'states-admin\.php', t\('sidebar\.tab\.states_provinces', 'States \/ Provinces'\)/", $sidebar));
gh153w_t('the sidebar caption key is seeded for the Translations page',
    strpos((string) file_get_contents($root . '/sql/run_gh153_states_captions.php'), "'sidebar.tab.states_provinces'") !== false);

echo "\n--- the dropdowns ---\n";
$it = (string) file_get_contents($root . '/api/incident-types.php');
gh153w_t('api/incident-types.php emits states_from_db and reads the list through states_list()',
    strpos($it, "'states_from_db'   => \$statesFromDb") !== false && strpos($it, 'states_list()') !== false);
$ss = $strip((string) file_get_contents($root . '/assets/js/states-select.js'));
$fe = $strip((string) file_get_contents($root . '/assets/js/facility-edit.js'));
gh153w_t('states-select.js and facility-edit.js keep an emptied list empty (states_from_db === true)',
    strpos($ss, 'data.states_from_db === true') !== false && strpos($fe, 'data.states_from_db === true') !== false);
$ni = (string) file_get_contents($root . '/assets/js/new-incident.js');
gh153w_t('New Incident: both geocoder paths use selectStateFromAddress and no exact-match loop is left',
    substr_count($ni, 'selectStateFromAddress(stateEl, r.address)') === 1 && substr_count($ni, 'selectStateFromAddress(stateEl, addr);') === 1
    && strpos($ni, 'r.address.state.toLowerCase()') === false && strpos($ni, '=== addr.state.toLowerCase()') === false);

echo "\n--- documentation ---\n";
$doc = $root . '/docs/STATES-AND-PROVINCES.md';
gh153w_t('docs/STATES-AND-PROVINCES.md exists', is_file($doc));
gh153w_t('the page links the guide through the documentation viewer', strpos($page, 'documentation/?doc=STATES-AND-PROVINCES') !== false);
gh153w_t('the user guide and the help page mention the page', strpos((string) file_get_contents($root . '/docs/NEWUI-USER-GUIDE.md'), 'States / Provinces') !== false
    && strpos((string) file_get_contents($root . '/help.php'), 'States / Provinces') !== false);

// ═════════ Part 2 ═════════
echo "\n--- Part 2: behaviour in jsdom ---\n";
$node = trim((string) @shell_exec(PHP_OS_FAMILY === 'Windows' ? 'where node 2>NUL' : 'command -v node 2>/dev/null'));
$node = $node === '' ? '' : strtok($node, "\r\n");
$jsdomOk = false;
if ($node !== '') {
    $probe = @shell_exec('"' . $node . '" -e "require(\'jsdom\');console.log(\'ok\')" 2>&1');
    $jsdomOk = is_string($probe) && strpos($probe, 'ok') !== false;
}
$haveDb = false;
try { db_fetch_value('SELECT COUNT(*) FROM ' . db_table('states_translator')); $haveDb = true; } catch (Throwable $e) {}

if (!$jsdomOk || !$haveDb) {
    echo 'SKIP: Part 2 needs ' . (!$jsdomOk ? 'Node + jsdom (set NODE_PATH)' : 'the database') . "\n";
} else {
    $run = static function (string $mode, array $fixture) use ($node, $root): array {
        $fx = tempnam(sys_get_temp_dir(), 'g153fx');
        file_put_contents($fx, json_encode($fixture, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $out = tempnam(sys_get_temp_dir(), 'g153o');
        $proc = proc_open([$node, __DIR__ . '/_gh153_states_harness.cjs', $mode, $root, $fx],
            [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'], 1 => ['file', $out, 'w'], 2 => ['file', $out . '.e', 'w']],
            $pipes, $root, null, ['bypass_shell' => true]);
        if (is_resource($proc)) { proc_close($proc); }
        $res = json_decode((string) @file_get_contents($out), true);
        $err = (string) @file_get_contents($out . '.e');
        @unlink($fx); @unlink($out); @unlink($out . '.e');
        return is_array($res) ? $res : ['checks' => [], 'errors' => ['harness produced no JSON: ' . substr($err, 0, 300)]];
    };
    $report = static function (string $mode, array $res, int $expectAtLeast): void {
        foreach ($res['checks'] ?? [] as $label => $ok) {
            gh153w_t("[$mode] $label", $ok === true);
        }
        gh153w_t("[$mode] the harness ran its checks without errors", count($res['checks'] ?? []) >= $expectAtLeast && ($res['errors'] ?? []) === [],
            implode(' | ', $res['errors'] ?? []));
    };

    $report('select', $run('select', []), 6);

    preg_match('/(    function foldAccents\(.*?)(?=    function handleGeocodeResult\()/s', $ni, $hm);
    gh153w_t('[geo] the helper could be extracted from new-incident.js', isset($hm[1]) && strpos($hm[1], 'function selectStateFromAddress') !== false);
    $report('geo', $run('geo', ['helper' => $hm[1] ?? '']), 9);

    // The page markup as served (PHP tags removed) and the REAL library's answers.
    $start = strpos($page, '<main');
    $end = strpos($page, '<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js">');
    $html = preg_replace('/<\?php.*?\?>/s', '', substr($page, $start, $end - $start));
    $listRows = [];
    $i = 1;
    foreach (states_build_selection(['us50' => ['dc']])['rows'] as [$c, $n]) {
        $listRows[] = ['id' => $i++, 'code' => $c, 'name' => $n];
    }
    $listRows[] = ['id' => 999001, 'code' => 'XS', 'name' => '<img src=x onerror="window.__xss=1">'];
    $real = states_list();
    gh153w_t('[admin] the fixture rows have exactly the shape states_list() returns', $real === [] || array_keys($real[0]) === array_keys($listRows[0]));
    $listAnswer = ['states' => $listRows, 'count' => count($listRows), 'max_rows' => STATES_MAX_ROWS, 'schema' => states_schema_status(),
        'field_label' => 'State', 'label_lang' => 'en', 'languages' => ['en'], 'customized' => false];
    $listAnswer['schema']['duplicates'] = [];
    $listAnswer['schema']['utf8mb4'] = true;
    $listAnswer['schema']['unique_code'] = true;
    $ca = states_build_selection(['ca' => []]);
    $preview = states_preview($ca['rows'], 'replace');
    $preview['conflicts'] = $ca['conflicts'];
    $preview['sources'] = $ca['sources'];
    $preview['label_suggestion'] = $ca['label_suggestion'];
    $preview['target_count'] = count($ca['rows']);
    if ($preview['removes']) {
        $preview['removes'][0]['usage'] = 3;
        $preview['removes'][0]['usage_by'] = [['label' => 'Incidents', 'table' => 'ticket', 'column' => 'state', 'count' => 2],
                                              ['label' => 'Members', 'table' => 'member', 'column' => 'state', 'count' => 1]];
        $preview['records_using_removed'] = 3;
    }
    $bad = states_csv_parse("code,name\nON,Ontario\non,Again\n");
    $good = states_csv_parse("code,name\nON,Ontario\n");
    $goodPreview = states_preview($good['rows'], 'replace') + ['ok' => true, 'errors' => [], 'target_count' => 1];
    $fixture = [
        'html' => $html,
        'list' => $listAnswer,
        'presets' => states_preset_catalog(),
        'preview' => $preview,
        'apply' => ['ok' => true, 'changed' => true, 'mode' => 'replace', 'added' => count($preview['adds']), 'removed' => count($preview['removes']),
                    'renamed' => 0, 'skipped' => 0, 'unchanged' => 0, 'final_count' => 13, 'label_suggestion' => 'Province'],
        'import_bad' => ['ok' => false, 'errors' => $bad['errors'], 'row_count' => count($bad['rows'])],
        'import_good' => $goodPreview,
        'usage' => ['usage' => states_usage([]) + [$listRows[0]['code'] => ['total' => 2, 'by' => [['label' => 'Incidents', 'table' => 'ticket', 'column' => 'state', 'count' => 2]]]]],
    ];
    gh153w_t('[admin] the bad-file fixture really is the parser\'s answer (line 3 duplicate)', ($bad['errors'][0]['line'] ?? 0) === 3);
    $res = $run('admin', $fixture);
    $report('admin', $res, 25);
    $postedActions = array_values(array_unique(array_map(static function ($p) { return $p['action'] ?? ''; }, $res['posts'] ?? [])));
    gh153w_t('[admin] the click-through exercised every POST action the endpoint has', array_diff($cm[1], $postedActions) === [],
        'missing: ' . implode(',', array_diff($cm[1], $postedActions)));
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
