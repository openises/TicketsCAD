<?php
/**
 * GH#153 — the State / Province list library (inc/states.php) and its two
 * migrations, against the real database.
 *
 *   A. single-row edits: create / update / delete, validation, duplicate
 *      codes (case-insensitive), not-found, the "customized" flag, audit rows;
 *   B. usage counts over real records (places, constituents, warnings);
 *   C. replace vs add, renames, skipped codes, a stale preview refused,
 *      saved records never rewritten;
 *   D. utf8mb4: "Québec", "Michoacán de Ocampo", "Manawatū-Whanganui"
 *      stored and read back byte-for-byte;
 *   E. CSV: BOM, CRLF, ";" delimiter, header order, duplicates, bad codes,
 *      oversize, non-UTF-8, U+FFFD, row cap, formula neutralisation and the
 *      export → import round trip;
 *   F. the field label (caption form.state + legacy keys);
 *   G. the migrations, run as real subprocesses: the US seed only into an
 *      empty, never-edited table; the latin1 → utf8mb4 conversion, duplicate
 *      clean-up and unique key, and that both are idempotent.
 *
 * Leaves the database as it found it: the list (with its ids), the
 * customized flag, the captions, the fixture records and its audit rows.
 *
 * @requires-db
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/states.php';
require_once __DIR__ . '/_gh142_cli.php';

$pass = 0; $fail = 0;
function gh153l_t(string $label, bool $cond, string $detail = ''): void
{
    global $pass, $fail;
    echo ($cond ? '[PASS] ' : '[FAIL] ') . $label . ($cond || $detail === '' ? '' : ' — ' . $detail) . "\n";
    $cond ? $pass++ : $fail++;
}
function gh153l_throws(callable $fn, string $class = 'InvalidArgumentException'): bool
{
    try { $fn(); } catch (Throwable $e) { return $e instanceof $class; }
    return false;
}

echo "=== GH#153 — State / Province library and migrations ===\n\n";

try {
    db_fetch_value('SELECT COUNT(*) FROM ' . db_table('states_translator'));
} catch (Throwable $e) {
    echo "SKIP: states_translator is not reachable (" . $e->getMessage() . ")\n";
    echo "\n=== 0 passed, 0 failed ===\n";
    exit(0);
}

$prefix = $GLOBALS['db_prefix'] ?? '';
$T = db_table('states_translator');
$_SESSION['user_id'] = null;
$_SESSION['user'] = 'gh153-lib-test';

// ── snapshot, restored on any exit ──────────────────────────────────────
$snapRows = db_fetch_all("SELECT `id`, `code`, `name` FROM {$T} ORDER BY `id`");
$snapFlag = db_fetch_value('SELECT `value` FROM ' . db_table('settings') . ' WHERE `name` = ?', [STATES_CUSTOMIZED_SETTING]);
$snapCaps = db_fetch_all('SELECT `caption_key`, `lang`, `value` FROM ' . db_table('captions_i18n')
    . " WHERE `caption_key` IN ('form.state', 'newinc.label.state', 'field.state')");
$fixtures = ['places' => [], 'constituents' => [], 'warnings' => []];

$restore = function () use ($T, $prefix, $snapRows, $snapFlag, $snapCaps, &$fixtures) {
    try {
        $cs = db_fetch_value("SELECT `CHARACTER_SET_NAME` FROM information_schema.COLUMNS
            WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = ? AND `COLUMN_NAME` = 'name'", [$prefix . 'states_translator']);
        if (strtolower((string) $cs) !== 'utf8mb4') {
            db_query("ALTER TABLE {$T} CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        }
        db_query("DELETE FROM {$T}");
        if (!states_has_unique_code_index()) {
            db_query("ALTER TABLE {$T} ADD UNIQUE KEY `uk_states_code` (`code`)");
        }
        foreach ($snapRows as $r) {
            db_query("INSERT INTO {$T} (`id`, `code`, `name`) VALUES (?, ?, ?)", [$r['id'], $r['code'], $r['name']]);
        }
    } catch (Throwable $e) { echo "[restore] list: " . $e->getMessage() . "\n"; }
    try {
        if ($snapFlag === false || $snapFlag === null) {
            db_query('DELETE FROM ' . db_table('settings') . ' WHERE `name` = ?', [STATES_CUSTOMIZED_SETTING]);
        } else {
            db_query('UPDATE ' . db_table('settings') . ' SET `value` = ? WHERE `name` = ?', [$snapFlag, STATES_CUSTOMIZED_SETTING]);
        }
        db_query('DELETE FROM ' . db_table('captions_i18n') . " WHERE `caption_key` IN ('form.state', 'newinc.label.state', 'field.state')");
        foreach ($snapCaps as $c) {
            db_query('INSERT INTO ' . db_table('captions_i18n') . " (`caption_key`, `lang`, `value`, `category`) VALUES (?, ?, ?, 'form')",
                [$c['caption_key'], $c['lang'], $c['value']]);
        }
        foreach ($fixtures as $table => $ids) {
            foreach ($ids as $id) { db_query('DELETE FROM ' . db_table($table) . ' WHERE `id` = ?', [$id]); }
        }
        $fixtures = ['places' => [], 'constituents' => [], 'warnings' => []];
        db_query('DELETE FROM ' . db_table('newui_audit_log') . ' WHERE `user_name` = ?', ['gh153-lib-test']);
    } catch (Throwable $e) { echo "[restore] other: " . $e->getMessage() . "\n"; }
};
register_shutdown_function($restore);

function gh153l_codes(): array
{
    $c = array_map(static function ($r) { return $r['code']; }, states_list());
    sort($c);
    return $c;
}
function gh153l_name(string $code): ?string
{
    $v = db_fetch_value('SELECT `name` FROM ' . db_table('states_translator') . ' WHERE `code` = ?', [$code]);
    return $v === false ? null : (string) $v;
}

$us = states_build_selection(['us50' => ['dc']])['rows'];
$canada = states_build_selection(['ca' => []])['rows'];
$mexico = states_build_selection(['mx' => []])['rows'];

// ═════════ A. single-row edits ═════════
echo "--- A. single-row edits ---\n";
db_query('DELETE FROM ' . db_table('settings') . ' WHERE `name` = ?', [STATES_CUSTOMIZED_SETTING]);
db_query("DELETE FROM {$T}");
foreach ($us as [$c, $n]) { db_query("INSERT INTO {$T} (`code`, `name`) VALUES (?, ?)", [$c, $n]); }
gh153l_t('baseline: the 50 states + DC', states_count() === 51);
gh153l_t('...and the list is not marked as customized yet', states_is_customized() === false);

$zq = states_create('zq', '  Zed   Queue ');
gh153l_t('create stores the code in capitals and tidies the name', $zq['code'] === 'ZQ' && gh153l_name('ZQ') === 'Zed Queue');
gh153l_t('...and marks the list as customized', states_is_customized() === true);
gh153l_t('a second ZQ is refused', gh153l_throws(function () { states_create('ZQ', 'Other'); }));
gh153l_t('...case-insensitively (zq)', gh153l_throws(function () { states_create('zq', 'Other'); }));
foreach ([['TOOLONG', 'x', 'a 7-character code'], ['A B', 'x', 'a code with a space'], ['', 'x', 'an empty code'],
          ['É1', 'x', 'a non-ASCII code'], ['Q1', '', 'an empty name'], ['Q2', str_repeat('a', 65), 'a 65-character name'],
          ['Q3', "bad\x01name", 'a control character'], ['Q4', "Qu\xC3\x28bec", 'invalid UTF-8']] as [$c, $n, $why]) {
    gh153l_t("create refuses $why", gh153l_throws(function () use ($c, $n) { states_create($c, $n); }));
}
$long = str_repeat('é', 64);
$q5 = states_create('Q5', $long);
gh153l_t('a 64-character multibyte name is accepted and read back intact', gh153l_name('Q5') === $long);

$u = states_update($zq['id'], 'zr', 'Zed Ar');
gh153l_t('update changes code and name', $u['changed'] === true && gh153l_name('ZR') === 'Zed Ar' && gh153l_name('ZQ') === null);
gh153l_t('update to a code another entry holds is refused', gh153l_throws(function () use ($zq) { states_update($zq['id'], 'mn', 'Mine'); }));
gh153l_t('update of a missing entry says so', gh153l_throws(function () { states_update(987654321, 'QQ', 'x'); }, 'StatesNotFoundException'));
$same = states_update($zq['id'], 'ZR', 'Zed Ar');
gh153l_t('an update that changes nothing reports changed=false', $same['changed'] === false);
$d = states_delete($zq['id']);
gh153l_t('delete removes the entry and returns it', $d['deleted']['code'] === 'ZR' && gh153l_name('ZR') === null);
gh153l_t('deleting it again says it no longer exists', gh153l_throws(function () use ($zq) { states_delete($zq['id']); }, 'StatesNotFoundException'));

$aud = db_fetch_all('SELECT `activity`, `target_type`, `summary`, `details` FROM ' . db_table('newui_audit_log')
    . " WHERE `user_name` = 'gh153-lib-test' AND `target_type` = 'state_province' ORDER BY `id`");
$acts = array_column($aud, 'activity');
gh153l_t('create, update and delete were each audited (with the codes in the summary)',
    in_array('create', $acts, true) && in_array('update', $acts, true) && in_array('delete', $acts, true)
    && strpos(implode(' ', array_column($aud, 'summary')), 'ZR') !== false, implode(',', $acts));

// ═════════ B. usage ═════════
echo "\n--- B. how many saved records hold a code ---\n";
states_create('Q7', 'Fixture Seven');
states_create('Q8', 'Fixture Eight');
db_query('INSERT INTO ' . db_table('places') . " (`name`, `state`) VALUES ('GH153 fixture place', 'Q7')");
$fixtures['places'][] = (int) db_insert_id();
db_query('INSERT INTO ' . db_table('constituents') . " (`contact`, `phone`, `state`) VALUES ('GH153 fixture', '555-0153', 'q7')");
$fixtures['constituents'][] = (int) db_insert_id();
db_query('INSERT INTO ' . db_table('warnings') . " (`title`, `street`, `city`, `state`, `lat`, `lng`, `description`)
          VALUES ('GH153 fixture', '1 Main', 'Town', 'Q7', 0, 0, 'fixture')");
$fixtures['warnings'][] = (int) db_insert_id();
$use = states_usage(['q7', 'Q8', 'Q7']);
gh153l_t('usage answers once per code, normalised', array_keys($use) === ['Q7', 'Q8']);
gh153l_t('Q7 is held by 3 records (place, constituent — lower-case "q7" — and warn location)', $use['Q7']['total'] === 3,
    json_encode($use['Q7']));
$labels = array_column($use['Q7']['by'], 'label');
gh153l_t('...broken down by where they are', in_array('Places', $labels, true) && in_array('Constituents', $labels, true) && in_array('Warn locations', $labels, true));
gh153l_t('Q8 is held by none', $use['Q8']['total'] === 0 && $use['Q8']['by'] === []);
gh153l_t('an empty request is an empty answer', states_usage([]) === []);
$cols = states_usage_columns();
gh153l_t('the usage columns include incidents, members, units and facilities', count(array_filter($cols, static function ($c) {
    return in_array($c[0] . '.' . $c[1], ['ticket.state', 'member.state', 'responder.state', 'facilities.state'], true);
})) === 4);

$pv = states_preview($us, 'replace');
$rm = array_column($pv['removes'], 'usage', 'code');
gh153l_t('a Replace preview removes Q5, Q7, Q8 and reports Q7\'s 3 records', isset($rm['Q5'], $rm['Q7'], $rm['Q8']) && $rm['Q7'] === 3 && $rm['Q8'] === 0);
gh153l_t('...counts them in records_using_removed', $pv['records_using_removed'] >= 3);
gh153l_t('...and ends with the 51 entries chosen', $pv['final_count'] === 51 && $pv['current_count'] === 54);
$pa = states_preview($canada, 'add');
gh153l_t('an Add preview of Canada adds 13 and removes nothing', count($pa['adds']) === 13 && $pa['removes'] === [] && $pa['final_count'] === 67);

// ═════════ C. replace vs add ═════════
echo "\n--- C. replace vs add ---\n";
$hashBefore = states_list_hash(states_list());
$r = states_apply($canada, 'replace', $hashBefore, 'test');
gh153l_t('Replace with Canada leaves exactly the 13 provinces and territories',
    $r['final_count'] === 13 && states_count() === 13 && gh153l_codes() === array_values(array_map(static function ($x) { return $x[0]; }, (function ($c) { usort($c, static function ($a, $b) { return strcmp($a[0], $b[0]); }); return $c; })($canada))));
gh153l_t('...reporting 54 removed and 13 added', $r['removed'] === 54 && $r['added'] === 13, json_encode($r));
gh153l_t('the saved records were NOT rewritten (the place still says Q7)',
    db_fetch_value('SELECT `state` FROM ' . db_table('places') . ' WHERE `id` = ?', [$fixtures['places'][0]]) === 'Q7');
$audit = db_fetch_one('SELECT `summary`, `details` FROM ' . db_table('newui_audit_log')
    . " WHERE `user_name` = 'gh153-lib-test' AND `target_type` = 'state_province' AND `summary` LIKE 'Replaced%' ORDER BY `id` DESC LIMIT 1");
$det = $audit ? json_decode((string) $audit['details'], true) : null;
$removedQ7 = null;
foreach (($det['removed'] ?? []) as $x) { if ($x['code'] === 'Q7') { $removedQ7 = $x; } }
gh153l_t('the bulk change was audited with counts and each removed code\'s record count',
    $det !== null && $det['counts']['added'] === 13 && $det['counts']['removed'] === 54 && ($removedQ7['records_holding_code'] ?? null) === 3);

gh153l_t('applying with a stale preview hash is refused', gh153l_throws(function () use ($mexico, $hashBefore) {
    states_apply($mexico, 'add', $hashBefore, 'test');
}, 'StatesStaleException'));
gh153l_t('...and changed nothing', states_count() === 13);
$r = states_apply($mexico, 'add', states_list_hash(states_list()), 'test');
gh153l_t('Add Mexico: 32 added, nothing removed, 45 entries', $r['added'] === 32 && $r['removed'] === 0 && states_count() === 45);
$r = states_apply($canada, 'add', null, 'test');
gh153l_t('Add Canada again: nothing to do, 13 skipped', $r['changed'] === false && $r['skipped'] === 13 && states_count() === 45);
$on = db_fetch_one("SELECT `id` FROM {$T} WHERE `code` = 'ON'");
states_update((int) $on['id'], 'ON', 'Ontario (edited)');
$r = states_apply($canada, 'replace', null, 'test');
gh153l_t('Replace with Canada renames the edited Ontario back, keeps its row, drops Mexico',
    $r['renamed'] === 1 && $r['removed'] === 32 && gh153l_name('ON') === 'Ontario'
    && (int) db_fetch_value("SELECT `id` FROM {$T} WHERE `code` = 'ON'") === (int) $on['id']);
$r = states_apply([], 'replace', null, 'test');
gh153l_t('an administrator can empty the list on purpose', states_count() === 0 && $r['removed'] === 13);
gh153l_t('a bad mode is refused', gh153l_throws(function () use ($canada) { states_diff([], $canada, 'merge'); }));
foreach ([[[['on', 'Ontario']], 'a code not in stored form'], [[['ON', 'Ontario'], ['ON', 'Again']], 'a repeated code'],
          [[['TOOLONG', 'x']], 'an invalid code'], [[['ON']], 'a row without a name'], [[['ON', "  Ontario "]], 'an untidy name']] as [$bad, $why]) {
    gh153l_t("apply refuses a target with $why (and changes nothing)", gh153l_throws(function () use ($bad) {
        states_apply($bad, 'replace', null, 'test');
    }) && states_count() === 0);
}

// ═════════ D. utf8mb4 ═════════
echo "\n--- D. accented names survive ---\n";
states_apply($mexico, 'replace', null, 'test');
gh153l_t('"Michoacán de Ocampo" and "Nuevo León" read back byte-for-byte',
    gh153l_name('MIC') === 'Michoacán de Ocampo' && gh153l_name('NLE') === 'Nuevo León');
states_create('QX', 'Québec');
states_create('MWQ', 'Manawatū-Whanganui');
gh153l_t('"Québec" reads back intact', gh153l_name('QX') === 'Québec');
gh153l_t('"Manawatū-Whanganui" (ū is not latin1) reads back intact', gh153l_name('MWQ') === 'Manawatū-Whanganui');
$st = states_schema_status();
gh153l_t('the table reports utf8mb4 and a unique key on code', $st['utf8mb4'] === true && $st['unique_code'] === true && $st['duplicates'] === []);

// ═════════ E. CSV ═════════
echo "\n--- E. CSV ---\n";
$tricky = [['ZQ', '=SUM(A1)'], ['Q1', '+1 Street'], ['Q2', '-minus'], ['Q3', '@handle'], ['Q4', 'Plain, with comma'],
           ['Q5', 'He said "hi"'], ['Q6', 'Semi;colon'], ['Q7', 'Québec'], ['A-1', 'Hyphen code']];
$csv = states_csv_build($tricky);
gh153l_t('export starts with a UTF-8 byte-order mark and the header, CRLF line ends', strpos($csv, "\xEF\xBB\xBFcode,name\r\n") === 0 && substr_count($csv, "\r\n") === 10);
gh153l_t('a cell starting with = + - @ is prefixed with an apostrophe',
    strpos($csv, "ZQ,'=SUM(A1)\r\n") !== false && strpos($csv, "Q1,'+1 Street") !== false
    && strpos($csv, "Q2,'-minus") !== false && strpos($csv, "Q3,'@handle") !== false);
gh153l_t('commas, quotes and semicolons are quoted RFC 4180 style',
    strpos($csv, '"Plain, with comma"') !== false && strpos($csv, '"He said ""hi"""') !== false && strpos($csv, '"Semi;colon"') !== false);
$back = states_csv_parse($csv);
gh153l_t('the export parses back to exactly the same rows (apostrophes stripped)', $back['errors'] === [] && $back['rows'] === $tricky,
    json_encode($back, JSON_UNESCAPED_UNICODE));
gh153l_t('a hand-written apostrophe that is not a formula guard is kept', states_csv_parse("code,name\nQA,'Tis Place")['rows'] === [['QA', "'Tis Place"]]);
$exported = states_csv_build(states_list());
$reimported = states_csv_parse($exported);
$want = array_map(static function ($r) { return [$r['code'], $r['name']]; }, states_list());
gh153l_t('export of the live list re-imports identically', $reimported['errors'] === [] && $reimported['rows'] === $want);
$p = states_csv_parse("\xEF\xBB\xBFcode,name\r\nON,Ontario\r\n\r\nqc,Québec\r\n");
gh153l_t('BOM + CRLF + a blank line: two rows, codes in capitals', $p['errors'] === [] && $p['rows'] === [['ON', 'Ontario'], ['QC', 'Québec']]);
$p = states_csv_parse("Name,Notes,CODE\nOntario,x,ON\n");
gh153l_t('columns in any order and any case, extra columns ignored', $p['errors'] === [] && $p['rows'] === [['ON', 'Ontario']]);
$p = states_csv_parse("code;name\nON;Ontario\nQC;\"Québec; Province\"\n");
gh153l_t('a ";" file (European Excel) is read', $p['errors'] === [] && $p['rows'] === [['ON', 'Ontario'], ['QC', 'Québec; Province']]);
$p = states_csv_parse("code,name\nON,Ontario\non,Other\n");
gh153l_t('a repeated code is reported with both line numbers', count($p['errors']) === 1 && $p['errors'][0]['line'] === 3
    && strpos($p['errors'][0]['message'], 'line 2') !== false);
$p = states_csv_parse("code,name\nTOOLONG,Too long\nQ1,\n,No code\nOK,Fine\n");
gh153l_t('a bad code, an empty name and an empty code are each reported, the good row kept', count($p['errors']) === 3
    && array_column($p['errors'], 'line') === [2, 3, 4] && $p['rows'] === [['OK', 'Fine']]);
gh153l_t('a file without the header is refused at line 1', states_csv_parse("ON,Ontario\n")['errors'][0]['line'] === 1);
gh153l_t('a file over 256 KB is refused', strpos(states_csv_parse(str_repeat('x', STATES_IMPORT_MAX_BYTES + 1))['errors'][0]['message'], '256 KB') !== false);
gh153l_t('a Windows-1252 file (not UTF-8) is refused with how to fix it',
    strpos(states_csv_parse("code,name\nQC,Qu\xE9bec\n")['errors'][0]['message'], 'CSV UTF-8') !== false);
gh153l_t('a file the browser could not decode (U+FFFD) is refused', count(states_csv_parse("code,name\nQC,Qu\u{FFFD}bec\n")['errors']) === 1);
$big = "code,name\n";
for ($i = 0; $i < STATES_IMPORT_MAX_ROWS + 1; $i++) { $big .= 'C' . base_convert((string) $i, 10, 36) . ",Row $i\n"; }
$p = states_csv_parse($big);
gh153l_t('more than 2,000 rows is refused', count($p['errors']) === 1 && strpos($p['errors'][0]['message'], '2000') !== false);
gh153l_t('an empty file is refused', states_csv_parse("")['errors'] !== [] && states_csv_parse("\xEF\xBB\xBF\r\n")['errors'] !== []);

// ═════════ F. field label ═════════
echo "\n--- F. field label ---\n";
db_query('INSERT INTO ' . db_table('captions_i18n') . " (`caption_key`, `lang`, `value`, `category`) VALUES ('newinc.label.state', 'en', 'State', 'newinc')
          ON DUPLICATE KEY UPDATE `value` = 'State'");
$res = states_set_field_label('Province', 'en');
gh153l_t('the label is set on form.state (what every page reads)', states_field_label('en') === 'Province' && $res['label'] === 'Province');
gh153l_t('...and on the legacy newinc.label.state row, so Translations shows no stale "State"',
    db_fetch_value('SELECT `value` FROM ' . db_table('captions_i18n') . " WHERE `caption_key` = 'newinc.label.state' AND `lang` = 'en'") === 'Province');
gh153l_t('a language without its own row falls back to English', states_field_label('zz') === 'Province');
foreach (['' => 'an empty label', str_repeat('x', 41) => 'a 41-character label', '<b>Prov</b>' => 'markup'] as $bad => $why) {
    gh153l_t("the label refuses $why", gh153l_throws(function () use ($bad) { states_set_field_label((string) $bad, 'en'); }));
}
$la = db_fetch_one('SELECT `summary` FROM ' . db_table('newui_audit_log') . " WHERE `user_name` = 'gh153-lib-test' AND `target_type` = 'caption' ORDER BY `id` DESC LIMIT 1");
gh153l_t('the label change was audited', $la !== null && strpos((string) $la['summary'], 'Province') !== false);

// ═════════ G. migrations (real subprocesses) ═════════
echo "\n--- G. migrations ---\n";
$seed = __DIR__ . '/../sql/run_phase84_states_seed.php';
$conv = __DIR__ . '/../sql/run_gh153_states_translator_utf8mb4.php';

states_apply($canada, 'replace', null, 'test');
$r1 = gh142_run_php([$seed]);
gh153l_t('the US seed on a non-empty list exits 0 and adds nothing', $r1['code'] === 0 && states_count() === 13 && gh153l_name('MN') === null,
    $r1['out'] . $r1['err']);
db_query("DELETE FROM {$T}");
states_mark_customized();
$r2 = gh142_run_php([$seed]);
gh153l_t('the US seed on a list an administrator emptied leaves it empty', $r2['code'] === 0 && states_count() === 0, $r2['out'] . $r2['err']);
db_query('DELETE FROM ' . db_table('settings') . ' WHERE `name` = ?', [STATES_CUSTOMIZED_SETTING]);
$r3 = gh142_run_php([$seed]);
gh153l_t('the US seed on an empty, never-edited list adds the 50 states + DC', $r3['code'] === 0 && states_count() === 51
    && gh153l_name('DC') === 'District of Columbia' && gh153l_name('MN') === 'Minnesota', $r3['out'] . $r3['err']);
gh153l_t('...and no longer seeds the stray "England / UK" row', gh153l_name('UK') === null);

// latin1, no unique key, one exact duplicate and one conflicting duplicate
db_query("ALTER TABLE {$T} DROP INDEX `uk_states_code`");
db_query("ALTER TABLE {$T} CONVERT TO CHARACTER SET latin1 COLLATE latin1_swedish_ci");
db_query("INSERT INTO {$T} (`code`, `name`) VALUES ('mn', 'minnesota '), ('WI', 'Wisconsin (old)')");
gh153l_t('setup: the table is latin1 without the unique key', states_schema_status()['utf8mb4'] === false && !states_has_unique_code_index());
$m1 = gh142_run_php([$conv]);
gh153l_t('the conversion exits 0 even with a conflicting duplicate (an upgrade is not blocked)', $m1['code'] === 0, $m1['out'] . $m1['err']);
gh153l_t('...converts to utf8mb4', states_schema_status()['utf8mb4'] === true);
gh153l_t('...removes the exact duplicate of MN and keeps the original row',
    (int) db_fetch_value("SELECT COUNT(*) FROM {$T} WHERE UPPER(`code`) = 'MN'") === 1 && gh153l_name('MN') === 'Minnesota');
gh153l_t('...leaves both WI entries alone and says so', (int) db_fetch_value("SELECT COUNT(*) FROM {$T} WHERE `code` = 'WI'") === 2
    && strpos($m1['out'], 'WI is used by different entries') !== false);
gh153l_t('...and does not add the unique key yet', !states_has_unique_code_index());
gh153l_t('the page would show the WI duplicate', array_column(states_duplicate_codes(), 'code') === ['WI']);
db_query("DELETE FROM {$T} WHERE `code` = 'WI' AND `name` = 'Wisconsin (old)'");
$m2 = gh142_run_php([$conv]);
gh153l_t('once resolved, the next run adds the unique key', $m2['code'] === 0 && states_has_unique_code_index(), $m2['out'] . $m2['err']);
$m3 = gh142_run_php([$conv]);
gh153l_t('a third run has nothing to do', $m3['code'] === 0 && strpos($m3['out'], '[SKIP] code and name are already utf8mb4') !== false
    && strpos($m3['out'], '[SKIP] unique key on code already present') !== false, $m3['out']);
gh153l_t('with the key in place the database itself refuses a duplicate code', gh153l_throws(function () use ($T) {
    db_query("INSERT INTO {$T} (`code`, `name`) VALUES ('mn', 'Second Minnesota')");
}, 'PDOException'));

$restore();
gh153l_t('the original list is back, ids and all', db_fetch_all("SELECT `id`, `code`, `name` FROM {$T} ORDER BY `id`") === $snapRows);

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
