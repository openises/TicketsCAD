<?php
/**
 * SPEC-STATUS.md B2 (internal audit, 2026-08-20) — Radio AI (Claude on
 * Radio, Phase 85f) had a fully-built operator-approval flow
 * (api/radio-ai-pending.php, api/radio-ai-decide.php, radio-ai.php,
 * assets/js/radio-ai-approval.js) sitting behind a listener daemon
 * (inc/radio_ai_listener.php) that could only ever be armed by hand-running
 * a SQL UPDATE against the `settings` table. `radio_ai_enabled` — and
 * `radio_ai_wake_word`, `radio_ai_model`, `radio_ai_channel_ids`,
 * `radio_ai_topic_scope` — each had a READER (inc/radio_ai_listener.php,
 * inc/radio_ai_client.php) and NO WRITER anywhere in the application: zero
 * hits across settings.php, assets/js/config.js, api/config-admin.php.
 * There was no admin panel and no kill switch.
 *
 * This test proves the fix closes exactly that gap, for exactly those five
 * keys, through exactly the mechanism the fix uses — the shared
 * api/config-admin.php?section=settings upsert (the same endpoint every
 * other admin settings panel in this app already goes through) — and
 * deliberately proves it does NOT add controls for the three OTHER
 * radio_ai_* keys that are seeded by sql/run_phase85f_radio_ai.php but have
 * ZERO readers anywhere in the tree (radio_ai_max_response_words,
 * radio_ai_auto_discard_seconds, radio_ai_daily_token_budget) — building a
 * UI for those would be the exact "setting that was never wired to
 * anything" pattern this project's CLAUDE.md already documents once
 * (tile_mode): a control that looks like an enforced limit but isn't.
 *
 * WHY A COLD-PROCESS PROBE (tests/_radio_ai_settings_probe.php):
 * get_variable() caches every `settings` row in a function-static array on
 * its first call per process and never re-reads afterward — the same
 * problem tests/_p132_settings_probe.php, tests/_p132_probe.php, and
 * tests/_par_setting_probe.php already exist for. Section 3 below writes a
 * sentinel value with the EXACT SQL api/config-admin.php's settings POST
 * handler runs (confirmed present in that file's source by section 1, not
 * assumed), then reads it back in a genuinely separate PHP process through
 * BOTH get_variable() (the reader every other settings panel's consumer
 * code uses) AND radio_ai_setting() (the reader inc/radio_ai_listener.php
 * and inc/radio_ai_client.php actually call) — proving they agree, i.e.
 * this feature is NOT hit by the documented "two settings stores"
 * (`settings` vs `config`, get_variable() vs get_setting()) pitfall.
 *
 * WHY NOT A LIVE HTTP TEST: api/config-admin.php's settings section
 * requires a real authenticated session + CSRF token, and this suite's
 * fresh-install CI job runs with no Apache (NEWUI_TEST_NO_HTTP=1) — the
 * same reasoning tests/test_vehicle_owner_agency.php's docblock gives for
 * choosing structural + logic-level checks over a live request. The actual
 * live HTTP round trip (toggle through the real Settings UI, confirm the
 * listener daemon picks it up, toggle back off) was verified manually
 * against a running local install as part of shipping this change — see
 * the session's live-verification notes, not this automated file.
 *
 * Usage: php tests/test_radio_ai_settings_panel.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/config.php';

$pass = 0; $fail = 0;
function ok(string $m): void  { global $pass; $pass++; echo "  PASS: $m\n"; }
function bad(string $m, string $hint = ''): void {
    global $fail; $fail++; echo "  FAIL: $m" . ($hint !== '' ? " — $hint" : '') . "\n";
}
function chk($cond, string $m, string $hint = ''): void { $cond ? ok($m) : bad($m, $hint); }

echo "\n=== Radio AI Settings panel (SPEC-STATUS.md B2) ===\n";

// The five keys this panel is responsible for wiring up — each already had
// a reader before this change; none had a writer.
$WIRED_KEYS = [
    'radio_ai_enabled',
    'radio_ai_wake_word',
    'radio_ai_channel_ids',
    'radio_ai_topic_scope',
    'radio_ai_model',
];
// Seeded (sql/run_phase85f_radio_ai.php) but with ZERO readers anywhere in
// the tree as of this change — deliberately NOT exposed in the new panel.
$DELIBERATELY_NOT_WIRED_KEYS = [
    'radio_ai_max_response_words',
    'radio_ai_auto_discard_seconds',
    'radio_ai_daily_token_budget',
];

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 1. Structural: the writer exists in the shipped source --\n";
// ─────────────────────────────────────────────────────────────────────────

$sidebarSrc = (string) file_get_contents($root . '/inc/config-sidebar.php');
$settingsSrc = (string) file_get_contents($root . '/settings.php');
$configJsSrc = (string) file_get_contents($root . '/assets/js/config.js');
$configAdminSrc = (string) file_get_contents($root . '/api/config-admin.php');

chk(strpos($sidebarSrc, "_cfg_tab('radio-ai'") !== false,
    'sidebar registers the radio-ai tab');
chk((bool) preg_match('/\$canCfg\s*\)\s*:\s*\?>\s*<\?php\s*_cfg_tab\(\'radio-ai\'/s', $sidebarSrc)
        || (bool) preg_match('/if\s*\(\s*\$canCfg\s*\)\s*:.*?_cfg_tab\(\'radio-ai\'/s', $sidebarSrc),
    'the radio-ai sidebar entry is gated behind $canCfg (action.manage_config)',
    'expected the tab registration to sit inside an if ($canCfg): ... endif; block');

chk(strpos($settingsSrc, 'id="panel-radio-ai"') !== false,
    'settings.php has the panel-radio-ai panel');
chk(strpos($settingsSrc, 'id="radioAiConfigForm"') !== false,
    'settings.php has the radioAiConfigForm form');
foreach (['setRadioAiEnabled', 'setRadioAiWakeWord', 'setRadioAiChannelIds', 'setRadioAiTopicScope', 'setRadioAiModel'] as $fieldId) {
    chk(strpos($settingsSrc, 'id="' . $fieldId . '"') !== false,
        "settings.php has the {$fieldId} field");
}
chk(strpos($settingsSrc, 'setRadioAiEnabled') !== false
        && (bool) preg_match('/type="checkbox"[^>]*id="setRadioAiEnabled"|id="setRadioAiEnabled"[^>]*type="checkbox"/', $settingsSrc),
    'the master enable control is a real checkbox, not a text field');
chk(strpos($settingsSrc, 'href="radio-ai.php"') !== false,
    'the panel links to the real operator-approval console (radio-ai.php)');

// Panel must NOT expose the three consumer-less keys — see file docblock.
$panelStart = strpos($settingsSrc, 'id="panel-radio-ai"');
$panelEnd   = $panelStart !== false ? strpos($settingsSrc, 'id="panel-owntracks-defaults"', $panelStart) : false;
$panelHtml  = ($panelStart !== false && $panelEnd !== false)
    ? substr($settingsSrc, $panelStart, $panelEnd - $panelStart)
    : '';
chk($panelHtml !== '', 'the radio-ai panel block could be isolated for the negative-control check below');
foreach ($DELIBERATELY_NOT_WIRED_KEYS as $deadKey) {
    chk(strpos($panelHtml, $deadKey) === false,
        "the panel does NOT expose {$deadKey} (no reader exists for it — see docblock)");
}

chk(strpos($configJsSrc, "tab === 'radio-ai'") !== false,
    'config.js dispatches the radio-ai tab to a loader');
chk(strpos($configJsSrc, 'function loadRadioAiConfig()') !== false,
    'config.js defines loadRadioAiConfig()');

// Isolate loadRadioAiConfig()'s body so the key-map assertions below can't
// accidentally match some OTHER function that happens to mention the same
// setting name (e.g. a comment elsewhere in the file).
$fnStart = strpos($configJsSrc, 'function loadRadioAiConfig()');
$fnEnd   = $fnStart !== false ? strpos($configJsSrc, "\n    function ", $fnStart + 10) : false;
$fnBody  = ($fnStart !== false && $fnEnd !== false) ? substr($configJsSrc, $fnStart, $fnEnd - $fnStart) : '';
chk($fnBody !== '', 'loadRadioAiConfig() body could be isolated for the wiring checks below');

chk(strpos($configJsSrc, "apiPost('settings'") !== false
        && strpos($fnBody, 'apiPost(') !== false,
    'loadRadioAiConfig() saves through the shared settings endpoint, not a bespoke one');

chk(preg_match('/AUDIT_HIGH/', $configAdminSrc)
        && strpos($configAdminSrc, "'radio_ai_enabled'") !== false
        && strpos($configAdminSrc, 'radioAiEnabledBefore') !== false,
    'api/config-admin.php has a dedicated before/after audit entry for radio_ai_enabled');
chk(strpos($configAdminSrc, "INSERT INTO `{\$prefix}settings` (`name`, `value`) VALUES (?, ?)") !== false
        && strpos($configAdminSrc, 'ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)') !== false,
    'api/config-admin.php\'s settings POST handler uses the standard upsert this test replays in section 3');

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 2. Cross-file wiring: every key this panel writes has a real reader --\n";
// ─────────────────────────────────────────────────────────────────────────
// The exact disease this panel fixes: a settings key with a READER and no
// WRITER. Prove the inverse can never quietly regress — every key the new
// panel writes must appear, verbatim, as a radio_ai_setting(...) argument
// somewhere the feature actually consumes it.

$listenerSrc = (string) file_get_contents($root . '/inc/radio_ai_listener.php');
$clientSrc   = (string) file_get_contents($root . '/inc/radio_ai_client.php');
$readerSrc   = $listenerSrc . "\n" . $clientSrc;

foreach ($WIRED_KEYS as $key) {
    chk(strpos($fnBody, "'{$key}'") !== false,
        "loadRadioAiConfig() writes the literal key '{$key}'");
    chk((bool) preg_match('/radio_ai_setting\(\s*[\'"]' . preg_quote($key, '/') . '[\'"]/', $readerSrc),
        "'{$key}' is read via radio_ai_setting(...) somewhere in the listener/client");
}

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 3. Round trip through the exact write path, read via a cold process --\n";
// ─────────────────────────────────────────────────────────────────────────

$haveDb = false;
try { db_fetch_value('SELECT 1'); $haveDb = true; } catch (Throwable $e) {}

if (!$haveDb) {
    echo "\nSKIP: no database available for the round-trip section\n";
    echo "\n=== {$pass} passed, {$fail} failed ===\n";
    exit($fail > 0 ? 1 : 0);
}

$prefix = $GLOBALS['db_prefix'] ?? '';

function radioai_probe(string $name): ?array {
    $php = PHP_BINARY ?: 'php';
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg(__DIR__ . '/_radio_ai_settings_probe.php')
         . ' ' . escapeshellarg($name) . ' 2>&1';
    $out = @shell_exec($cmd);
    if ($out === null) return null;
    $j = json_decode(trim((string) $out), true);
    return is_array($j) ? $j : null;
}

// Snapshot originals so this test never leaves the shared dev DB with test
// sentinels sitting in a real setting other tests (or a human admin poking
// around) might read.
$originals = [];
foreach ($WIRED_KEYS as $key) {
    $originals[$key] = db_fetch_value(
        "SELECT `value` FROM `{$prefix}settings` WHERE `name` = ?", [$key]
    );
}
register_shutdown_function(function () use ($prefix, $originals) {
    foreach ($originals as $key => $val) {
        if ($val === false) {
            db_query("DELETE FROM `{$prefix}settings` WHERE `name` = ?", [$key]);
        } else {
            db_query(
                "INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
                [$key, $val]
            );
        }
    }
});

$sentinels = [
    'radio_ai_enabled'      => '1',                 // not the seeded '0' default
    'radio_ai_wake_word'    => 'sentinelword',       // not the seeded 'claude' default
    'radio_ai_channel_ids'  => '9101,9102',          // not the seeded '3' default
    'radio_ai_topic_scope'  => 'broad',              // not the seeded 'ham_general_science' default
    'radio_ai_model'        => 'claude-sentinel-9',  // not the seeded default model id
];

foreach ($sentinels as $key => $sentinel) {
    // Write EXACTLY the way api/config-admin.php's settings POST handler
    // writes (section 1 confirmed that literal SQL is what ships) — this is
    // not a hand-invented query, it's the real upsert.
    db_query(
        "INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
        [$key, $sentinel]
    );

    $probe = radioai_probe($key);
    chk(is_array($probe), "cold-process probe returned JSON for {$key}", var_export($probe, true));
    if (!is_array($probe)) continue;

    chk(($probe['get_variable'] ?? null) === $sentinel,
        "a FRESH process reading get_variable('{$key}') sees the value the settings endpoint wrote",
        var_export($probe['get_variable'] ?? null, true));
    chk(($probe['radio_ai_setting'] ?? null) === $sentinel,
        "a FRESH process reading radio_ai_setting('{$key}', ...) — the SAME reader inc/radio_ai_listener.php calls — sees the identical value",
        var_export($probe['radio_ai_setting'] ?? null, true));
    chk(($probe['get_variable'] ?? null) === ($probe['radio_ai_setting'] ?? null),
        "get_variable() and radio_ai_setting() agree for {$key} — not hit by the documented two-settings-stores pitfall");
}

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 4. Kill-switch semantics: listener re-checks radio_ai_enabled every poll --\n";
// ─────────────────────────────────────────────────────────────────────────
// Structural proof that turning the master switch off takes effect without
// a daemon restart: radio_ai_enabled is read INSIDE the while($running) poll
// loop (not once before it), and a false reading sleeps without touching
// the wake-word/queue logic at all.

chk((bool) preg_match(
        '/while\s*\(\s*\$running\s*\)\s*\{.*?radio_ai_setting\(\s*[\'"]radio_ai_enabled[\'"]/s',
        $listenerSrc
    ),
    'radio_ai_enabled is read from INSIDE the listener\'s poll loop, not just once at startup',
    'if this regresses to a startup-only read, disabling the feature would require a daemon restart to take effect'
);
chk((bool) preg_match('/if\s*\(\s*!\$enabled\s*\)\s*\{\s*sleep\(\$interval\);\s*continue;/', $listenerSrc),
    'when disabled, the loop sleeps and continues WITHOUT reaching the wake-word/queue code below it');

echo "\n=== {$pass} passed, {$fail} failed ===\n";
exit($fail > 0 ? 1 : 0);
