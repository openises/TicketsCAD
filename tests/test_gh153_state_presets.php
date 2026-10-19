<?php
/**
 * GH#153 — the built-in State / Province presets (inc/state-presets.php) are
 * exactly what they claim to be, and they live in ONE place.
 *
 * Exact counts per preset and option, codes unique and within the 4-character
 * column, names non-empty valid UTF-8 within 64 characters, a cited source on
 * every preset, quick picks that point at real presets/options, the code
 * changes ISO made (India 2023, Mexico CMX), the conflicts a combination
 * produces, and the label each combination suggests. Driven through the REAL
 * builder (states_build_selection()), not a copy of its logic.
 *
 * No database needed.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/states.php';

$pass = 0; $fail = 0;
function gh153p_t(string $label, bool $cond, string $detail = ''): void
{
    global $pass, $fail;
    echo ($cond ? '[PASS] ' : '[FAIL] ') . $label . ($cond || $detail === '' ? '' : ' — ' . $detail) . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== GH#153 — State / Province presets ===\n\n";

$data = states_presets();
$presets = $data['presets'];

$expected = [
    'us50' => 50, 'us48' => 48, 'ca' => 13, 'mx' => 32, 'au' => 8, 'nz' => 16,
    'gb' => 4, 'ie' => 26, 'de' => 16, 'br' => 27, 'in' => 28,
];
gh153p_t('the preset ids are exactly the documented set', array_keys($presets) === array_keys($expected),
    implode(',', array_keys($presets)));
foreach ($expected as $id => $n) {
    gh153p_t("$id has exactly $n base entries", isset($presets[$id]) && count($presets[$id]['rows']) === $n,
        isset($presets[$id]) ? (string) count($presets[$id]['rows']) : 'missing');
}

$optCounts = [
    'us50' => ['dc' => 1, 'territories' => 5, 'armed_forces' => 3, 'freely_associated' => 3],
    'us48' => ['dc' => 1, 'territories' => 5, 'armed_forces' => 3, 'freely_associated' => 3],
    'nz'   => ['chatham' => 1],
    'in'   => ['union_territories' => 8],
];
foreach ($presets as $id => $p) {
    $want = $optCounts[$id] ?? [];
    $have = [];
    foreach ($p['options'] as $k => $o) { $have[$k] = count($o['rows']); }
    gh153p_t("$id options and their sizes are as documented", $have === $want, json_encode($have));
}

echo "\n--- every row of every preset, with every option ---\n";
$allValid = true; $problems = [];
foreach ($presets as $id => $p) {
    gh153p_t("$id names a source", trim((string) $p['source']) !== '');
    gh153p_t("$id has a name, a country and a suggested label",
        trim((string) $p['name']) !== '' && trim((string) $p['country']) !== '' && trim((string) $p['label']) !== '');
    $rows = $p['rows'];
    foreach ($p['options'] as $o) { $rows = array_merge($rows, $o['rows']); }
    $codes = [];
    foreach ($rows as $r) {
        $code = $r[0]; $name = $r[1];
        if ($code !== states_normalize_code($code) || states_code_error($code) !== null) {
            $problems[] = "$id code '$code'";
        }
        if ($name !== states_clean_name($name) || states_name_error($name) !== null) {
            $problems[] = "$id name '$name'";
        }
        $codes[] = $code;
    }
    gh153p_t("$id codes (with all options) are unique", count($codes) === count(array_unique($codes)),
        implode(',', array_diff_assoc($codes, array_unique($codes))));
    $maxLen = max(array_map('strlen', $codes));
    gh153p_t("$id codes fit the 4-character column (longest $maxLen)", $maxLen <= 4);
}
gh153p_t('every code is 1–4 of A–Z 0–9 - and every name is clean, valid UTF-8, ≤ 64 characters', $problems === [],
    implode('; ', array_slice($problems, 0, 5)));

echo "\n--- the details that are easy to get wrong ---\n";
$us48codes = array_column($presets['us48']['rows'], 0);
gh153p_t('US 48 is the 50 without Alaska and Hawaii',
    !in_array('AK', $us48codes, true) && !in_array('HI', $us48codes, true)
    && array_values(array_diff(array_column($presets['us50']['rows'], 0), $us48codes)) === ['AK', 'HI']);
gh153p_t('DC is an option (ticked by default), not one of the 50',
    !in_array('DC', array_column($presets['us50']['rows'], 0), true) && $presets['us50']['options']['dc']['default'] === true);
$terr = array_column($presets['us50']['options']['territories']['rows'], 0);
sort($terr);
gh153p_t('the territories are PR, VI, GU, AS, MP', $terr === ['AS', 'GU', 'MP', 'PR', 'VI']);
gh153p_t('armed forces codes are AA, AE, AP', array_column($presets['us50']['options']['armed_forces']['rows'], 0) === ['AA', 'AE', 'AP']);
gh153p_t('freely associated states are FM, MH, PW', array_column($presets['us50']['options']['freely_associated']['rows'], 0) === ['FM', 'MH', 'PW']);
$ca = array_column($presets['ca']['rows'], 1, 0);
gh153p_t('Canada: QC is "Quebec" (the English form, a stated choice) and YT is "Yukon"', ($ca['QC'] ?? '') === 'Quebec' && ($ca['YT'] ?? '') === 'Yukon');
$mx = array_column($presets['mx']['rows'], 1, 0);
gh153p_t('Mexico: CMX (not the retired DIF) is Ciudad de México', ($mx['CMX'] ?? '') === 'Ciudad de México' && !isset($mx['DIF']));
gh153p_t('Mexico: MIC is "Michoacán de Ocampo" (UTF-8 accent intact)', ($mx['MIC'] ?? '') === 'Michoacán de Ocampo');
$in = array_column($presets['in']['rows'], 0);
gh153p_t('India uses the 2023 ISO codes CG, OD, TS, UK and not CT, OR, TG, UT',
    count(array_intersect(['CG', 'OD', 'TS', 'UK'], $in)) === 4 && count(array_intersect(['CT', 'OR', 'TG', 'UT'], $in)) === 0);
$nz = array_column($presets['nz']['rows'], 1, 0);
gh153p_t('New Zealand: WGN is "Greater Wellington", MWT keeps its macron', ($nz['WGN'] ?? '') === 'Greater Wellington' && ($nz['MWT'] ?? '') === 'Manawatū-Whanganui');
$ie = array_column($presets['ie']['rows'], 1, 0);
gh153p_t('Ireland: Dublin is D and Galway is G (1-character codes)', ($ie['D'] ?? '') === 'Dublin' && ($ie['G'] ?? '') === 'Galway');
gh153p_t('Germany uses the German names (Bayern, Thüringen)', in_array('Bayern', array_column($presets['de']['rows'], 1), true) && in_array('Thüringen', array_column($presets['de']['rows'], 1), true));
gh153p_t('Brazil includes the Distrito Federal', in_array('DF', array_column($presets['br']['rows'], 0), true));

echo "\n--- quick picks and the builder ---\n";
foreach ($data['quick_picks'] as $qid => $q) {
    $ok = true;
    try { states_build_selection($q['selection']); } catch (Throwable $e) { $ok = false; }
    gh153p_t("quick pick '$qid' builds", $ok);
}
$b = states_build_selection(['us50' => ['dc', 'territories']]);
gh153p_t('"US 50 + DC + territories" is 56 rows, no conflicts', count($b['rows']) === 56 && $b['conflicts'] === []);
$b = states_build_selection(['us50' => ['dc', 'territories', 'armed_forces', 'freely_associated']]);
gh153p_t('every US postal code is 62 rows', count($b['rows']) === 62);
$b = states_build_selection(['us48' => [], 'us50' => []]);
gh153p_t('US 48 + US 50 together is simply the 50 (same names are not conflicts)', count($b['rows']) === 50 && $b['conflicts'] === []);
$b = states_build_selection(['us50' => ['dc'], 'ca' => [], 'mx' => []]);
gh153p_t('US + Canada + Mexico share no code (96 rows, no conflicts)', count($b['rows']) === 96 && $b['conflicts'] === []);
$b = states_build_selection(['us50' => [], 'au' => []]);
$conf = array_column($b['conflicts'], 'code');
gh153p_t('US + Australia: WA is a conflict; Washington (first chosen) is kept', $conf === ['WA']
    && $b['conflicts'][0]['kept'] === 'Washington' && $b['conflicts'][0]['dropped'] === 'Western Australia');
gh153p_t('...and the result has 57 rows (50 + 8 - 1)', count($b['rows']) === 57);
gh153p_t('Querétaro keeps its accent through the builder',
    in_array(['QUE', 'Querétaro'], states_build_selection(['mx' => []])['rows'], true));

foreach ([[[], 'empty selection'], [['zz' => []], 'unknown preset'], [['us50' => ['nope']], 'unknown option'], [['ca' => ['dc']], 'option from another preset']] as [$sel, $why]) {
    $threw = false;
    try { states_build_selection($sel); } catch (InvalidArgumentException $e) { $threw = true; }
    gh153p_t("the builder refuses an $why", $threw);
}

echo "\n--- suggested field label ---\n";
gh153p_t('Canada suggests "Province"', states_build_selection(['ca' => []])['label_suggestion'] === 'Province');
gh153p_t('US suggests "State"', states_build_selection(['us50' => ['dc']])['label_suggestion'] === 'State');
gh153p_t('US + Canada suggests "State or Province"', states_build_selection(['us50' => [], 'ca' => []])['label_suggestion'] === 'State or Province');
gh153p_t('Canada + UK suggests "Region"', states_build_selection(['ca' => [], 'gb' => []])['label_suggestion'] === 'Region');
gh153p_t('Ireland suggests "County"', states_build_selection(['ie' => []])['label_suggestion'] === 'County');

echo "\n--- the catalog the browser gets ---\n";
$cat = states_preset_catalog();
gh153p_t('the catalog lists every preset with its count and options', count($cat['presets']) === count($presets)
    && $cat['presets'][0]['count'] === count($presets[$cat['presets'][0]['id']]['rows']));
gh153p_t('the catalog carries the quick picks and the label choices', count($cat['quick_picks']) === count($data['quick_picks'])
    && in_array('Province', $cat['label_choices'], true));
gh153p_t('JSON-encoding the catalog keeps the UTF-8 names', strpos(json_encode($cat, JSON_UNESCAPED_UNICODE), 'Länder') !== false);

echo "\n--- one copy of the data ---\n";
$root = dirname(__DIR__);
$copies = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $path = str_replace('\\', '/', $f->getPathname());
    if (!preg_match('/\.(php|js)$/', $path) || preg_match('#/(vendor|tests|node_modules|\.git)/#', $path)) {
        continue;
    }
    $src = (string) @file_get_contents($path);
    if (strpos($src, 'Veracruz de Ignacio de la Llave') !== false || strpos($src, "'Federated States of Micronesia'") !== false) {
        $copies[] = substr($path, strlen(str_replace('\\', '/', $root)) + 1);
    }
}
gh153p_t('only inc/state-presets.php carries the preset data', $copies === ['inc/state-presets.php'], implode(', ', $copies));
$schema = (string) file_get_contents($root . '/sql/base_schema.sql');
gh153p_t('sql/base_schema.sql no longer seeds the US states (the seed lives in run_phase84 + the presets file)',
    strpos($schema, "INSERT IGNORE INTO `states_translator`") === false);

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
