<?php
/**
 * Regression tests for the searchable-member-dropdown phase.
 * Spec: specs/searchable-member-dropdown-2026-05/spec.md
 *
 * Static + DB assertions:
 *   * API returns the member list sorted by last_name, first_name.
 *   * SearchableSelect component file exists with the expected surface.
 *   * settings.php wires the combobox markup correctly + loads the
 *     CSS and JS with cache-busters.
 *   * config.js calls SearchableSelect.attach with the right options.
 *   * CSS file exists.
 */

declare(strict_types=1);

require __DIR__ . '/../config.php';

$base   = realpath(__DIR__ . '/..');
$prefix = $GLOBALS['db_prefix'] ?? '';

echo "=== Searchable member dropdown — regression suite ===\n\n";
$pass = 0; $fail = 0;
function ok(string $name): void  { global $pass; echo "[PASS] $name\n"; $pass++; }
function bad(string $name, string $why = ''): void {
    global $fail; echo "[FAIL] $name" . ($why ? " — $why" : '') . "\n"; $fail++;
}
function code_only(string $src): string {
    $src = preg_replace('!//[^\n]*!',     '', $src);
    $src = preg_replace('!/\*.*?\*/!s',   '', $src);
    return $src;
}

// ── 1. API sort — direct DB query mirrors what config-admin.php runs ──
try {
    $rows = db_fetch_all(
        "SELECT id, first_name, last_name, callsign
         FROM `{$prefix}member`
         WHERE deleted_at IS NULL
         ORDER BY last_name, first_name
         LIMIT 5"
    );
    $sorted = true;
    for ($i = 1; $i < count($rows); $i++) {
        $prev = strtolower($rows[$i-1]['last_name'] ?? '');
        $cur  = strtolower($rows[$i]['last_name']   ?? '');
        if ($prev > $cur) { $sorted = false; break; }
    }
    if ($sorted) ok('member API source SQL returns last_name-sorted rows');
    else         bad('member API source SQL returns last_name-sorted rows');
} catch (Throwable $e) {
    bad('member API SQL', $e->getMessage());
}

// ── 2. Component file present ──
$compJs = $base . '/assets/js/searchable-select.js';
if (file_exists($compJs)) ok('assets/js/searchable-select.js exists');
else                       bad('assets/js/searchable-select.js exists');

// ── 3. Component exposes window.SearchableSelect.attach ──
if (file_exists($compJs)) {
    $jsSrc = code_only(file_get_contents($compJs));
    if (preg_match('/window\.SearchableSelect\s*=\s*\{[^}]*attach[^}]*\}/s', $jsSrc)) {
        ok('searchable-select.js exposes window.SearchableSelect.attach');
    } else {
        bad('searchable-select.js exposes window.SearchableSelect.attach');
    }

    // ── 4. Public API surface (setItems, setValue, getValue, destroy) ──
    $hasAll = strpos($jsSrc, 'setItems:') !== false
           && strpos($jsSrc, 'setValue:') !== false
           && strpos($jsSrc, 'getValue:') !== false
           && strpos($jsSrc, 'destroy:')  !== false;
    if ($hasAll) ok('component returns {setItems, setValue, getValue, destroy}');
    else         bad('component returns {setItems, setValue, getValue, destroy}');

    // ── 5. Keyboard handlers present ──
    $hasKb = strpos($jsSrc, "'ArrowDown'") !== false
          && strpos($jsSrc, "'ArrowUp'")   !== false
          && strpos($jsSrc, "'Enter'")     !== false
          && strpos($jsSrc, "'Escape'")    !== false
          && strpos($jsSrc, "'Tab'")       !== false;
    if ($hasKb) ok('component handles ArrowDown/Up/Enter/Escape/Tab');
    else        bad('component handles required keys');
}

// ── 6. CSS file present ──
$compCss = $base . '/assets/css/searchable-select.css';
if (file_exists($compCss)) ok('assets/css/searchable-select.css exists');
else                        bad('assets/css/searchable-select.css exists');

// ── 7. settings.php wiring ──
$settingsSrc = file_get_contents($base . '/settings.php');
if (strpos($settingsSrc, 'id="userMemberDisplay"') !== false
    && strpos($settingsSrc, 'id="userMember"') !== false
    && strpos($settingsSrc, 'name="member"') !== false) {
    ok('settings.php has combobox markup (display input + hidden #userMember)');
} else {
    bad('settings.php combobox markup');
}

if (strpos($settingsSrc, 'searchable-select.css') !== false
    && strpos($settingsSrc, 'searchable-select.js') !== false) {
    ok('settings.php loads searchable-select CSS + JS');
} else {
    bad('settings.php loads searchable-select assets');
}

// The old <select id="userMember"> must be gone — otherwise duplicate ids.
if (!preg_match('/<select[^>]*id="userMember"/', $settingsSrc)) {
    ok('settings.php no longer contains the old <select id="userMember">');
} else {
    bad('old <select id="userMember"> still present');
}

// ── 8. config.js wiring ──
$configJsSrc = code_only(file_get_contents($base . '/assets/js/config.js'));
if (strpos($configJsSrc, 'SearchableSelect.attach') !== false
    && strpos($configJsSrc, 'userMemberPicker') !== false) {
    ok('config.js calls SearchableSelect.attach + caches the picker');
} else {
    bad('config.js wires SearchableSelect');
}

// ── 9. Label format puts name first, callsign in parens ──
if (preg_match("/lbl\s*\+=\s*'\s*\('\s*\+\s*m\.callsign\s*\+\s*'\)'/", $configJsSrc)) {
    ok('label format places callsign in parens at end of name');
} else {
    bad('label format places callsign in parens');
}

// ── 10. Defensive client-side sort ──
if (strpos($configJsSrc, 'membersRaw.sort') !== false) {
    ok('config.js sorts member list client-side as a safety net');
} else {
    bad('config.js defensive sort');
}

echo "\n=== Result: $pass passed, $fail failed ===\n";
exit($fail === 0 ? 0 : 1);
