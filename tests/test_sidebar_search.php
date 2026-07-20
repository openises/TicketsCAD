<?php
/**
 * test_sidebar_search.php — guards the settings-sidebar keyword search.
 *
 * The sidebar filter (inc/config-sidebar.php) matches each item against
 * (label + ' ' + data-kw), case-insensitive. This test statically extracts
 * those (label, keyword) pairs from the source — exactly what the browser
 * sees as textContent + data-kw — and asserts that common search terms a
 * user would type resolve to the correct panel. Prevents keyword-coverage
 * regressions (e.g. "twilio" finding nothing — the bug that prompted this).
 *
 * No DB/i18n boot required, so it runs on any checkout.
 */
$src = @file_get_contents(__DIR__ . '/../inc/config-sidebar.php');
if ($src === false) { fwrite(STDERR, "SKIP: config-sidebar.php not found\n"); echo "0/0\n"; exit(0); }

$items = [];
$re = "/_cfg_(?:tab|link)\\(\\s*'[^']*'\\s*,\\s*(?:'[^']*'\\s*,\\s*)?t\\(\\s*'[^']*'\\s*,\\s*'([^']*)'\\s*\\)(?:\\s*,\\s*'([^']*)')?/";
if (preg_match_all($re, $src, $m, PREG_SET_ORDER)) {
    foreach ($m as $g) $items[] = ['label' => $g[1], 'kw' => $g[2] ?? ''];
}
if (preg_match('/data-tab="wastebasket" data-kw="([^"]*)"/', $src, $w)) {
    $items[] = ['label' => 'Wastebasket', 'kw' => $w[1]];
}

function _side_match($items, $q) {
    $q = strtolower(trim($q)); $out = [];
    foreach ($items as $it) {
        if (strpos(strtolower($it['label'] . ' ' . $it['kw']), $q) !== false) $out[] = $it['label'];
    }
    return $out;
}

$cases = [
    ['twilio','SMS Configuration'], ['twil','SMS Configuration'], ['bulkvs','SMS Configuration'],
    ['smtp','Email Configuration'], ['owntracks','Provider Settings'], ['traccar','Location Ingest'],
    ['geofence','Alert Zones'], ['rbac','Roles & Permissions'], ['2fa','Two-Factor Auth'],
    ['ntp','Time Check'], ['ten-codes','Signal Codes'], ['provider','Provider Settings'],
    ['webhook','Webhooks / Events'], ['brandmeister','DMR'], ['nims','ICS Positions'],
    ['beds','Facilities'], ['captions','Translations'], ['piper','Voice & Speech'],
    ['cot','ATAK / TAK'], ['trash','Wastebasket'], ['diversion','Facility Statuses'],
    ['meshtastic','Mesh Bridges'], ['mailing','Email Lists'], ['dark','Display Settings'],
];

$pass = 0; $fail = 0; $fails = [];
foreach ($cases as [$q, $expect]) {
    $ok = false;
    foreach (_side_match($items, $q) as $h) if (stripos($h, $expect) !== false) { $ok = true; break; }
    if ($ok) { $pass++; } else { $fail++; $fails[] = "'$q' did not resolve to '$expect'"; }
}
// Coverage floor: the vast majority of items must carry keywords.
$withKw = count(array_filter($items, fn($i) => $i['kw'] !== ''));
$total  = count($items);
if ($total < 60) { $fail++; $fails[] = "only $total items parsed (expected 60+)"; } else { $pass++; }
if ($withKw < $total * 0.9) { $fail++; $fails[] = "keyword coverage $withKw/$total below 90%"; } else { $pass++; }

foreach ($fails as $f) fwrite(STDERR, "FAIL: $f\n");
echo ($pass) . "/" . ($pass + $fail) . " sidebar-search assertions passed\n";
exit($fail === 0 ? 0 : 1);
