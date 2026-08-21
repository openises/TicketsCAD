<?php
/**
 * SPEC-STATUS.md B15 (internal audit, 2026-08-20) regression tests —
 * OwnTracks admin global-defaults save/read used the wrong mechanism
 * entirely.
 *
 * Bug 1 (fatal): api/owntracks-config.php's save_defaults handler called
 * an undefined settings-writer function for any non-empty admin default —
 * confirmed via `grep -rn "function set_setting"` finding nothing but
 * unrelated, locally-scoped test helpers of a similar name (in
 * tests/test_chat_bridge.php, tests/test_gh22_push_routing_gate.php,
 * tests/test_phase134_poller.php, tools/test_aprs_poller.php). PHP raised
 * a fatal "call to undefined function" for any admin who saved a
 * non-empty OwnTracks default, BEFORE audit_log() ran and before the
 * setConfiguration push to every active member's phone.
 *
 * Bug 2 (compounding, same handler): the "clear" branch already targeted
 * the correct `settings` table (name/value), but every READER of these
 * owntracks_default_* keys called the OTHER settings-reader function —
 * the one that reads the separate, tiny `config` table (key/value); see
 * CLAUDE.md's "TWO settings stores — don't cross the wires" pitfall.
 * Nothing in this file's save path had ever written into that table, so
 * even a successful save could never be read back, and a "clear" never
 * actually cleared what a reader would see.
 *
 * Fix: the save/clear logic is extracted into a new top-level function,
 * _ot_save_defaults() — upserts via INSERT ... ON DUPLICATE KEY UPDATE
 * against `settings`, matching api/config-admin.php's own established
 * idiom (safe because settings.name carries a UNIQUE key as of Phase 24)
 * — and every reader of an owntracks_default_* key now goes through a
 * new _ot_get_default() wrapper, which reads the settings-table store —
 * the SAME one the writer now uses. Both new functions are top-level,
 * defined before the OT_CONFIG_LIBRARY_ONLY dispatch guard, so this file
 * can require_once them directly — the same pattern this project's own
 * Phase 117 lesson established (CLAUDE.md: "reusable/testable logic goes
 * in an inc/*.php include, not buried after an action-dispatch guard").
 *
 * Two more pre-existing instances of the identical wrong-store bug were
 * found in the same file while investigating this one
 * (owntracks_token_dual_window_days, owntracks_email_link_template — both
 * seeded into the settings table by sql/run_phase41_owntracks_tokens.php
 * but read via the config-table reader) and fixed in the same commit;
 * Part 4 below covers those two.
 *
 * NOTE ON THIS FILE'S OWN TWO SAFEGUARDS AGAINST FALSE PASSES:
 *
 *   (1) The settings-table reader function caches its result in a
 *       process-static variable populated on first call, with no
 *       invalidation — completely normal for this codebase (every real
 *       HTTP request is its own fresh PHP process, so this never matters
 *       in production), but it means a single long-lived test process
 *       cannot observe a save reflected by a LATER read once that cache
 *       has already been populated once. Part 2 therefore drives each
 *       save-then-read round trip in its OWN fresh PHP subprocess —
 *       mirroring how a real save and a real page-refresh are always
 *       separate requests — rather than trying to read twice in one
 *       process.
 *   (2) Part 3's source-wiring checks strip PHP comments before matching
 *       (token_get_all(), discarding T_COMMENT/T_DOC_COMMENT) — this
 *       docblock itself necessarily describes the OLD, buggy call shapes
 *       in prose, and a naive substring/regex check over the raw file
 *       would false-fail by matching its own explanation. Same class of
 *       gotcha as this project's documented `</script>`-inside-a-comment
 *       lesson; same fix ("tokenize, do not grep").
 *
 * @requires-db
 * Usage: php tests/test_owntracks_defaults_settings_store.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

// owntracks-config.php reads $_SERVER['REQUEST_METHOD'] unconditionally
// (even in OT_CONFIG_LIBRARY_ONLY mode) — set it before requiring.
$_SERVER['REQUEST_METHOD'] = 'GET';

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/functions.php'; // get_variable()

$prefix = $GLOBALS['db_prefix'] ?? '';

echo "=== SPEC-STATUS.md B15 - OwnTracks defaults settings-store fix ===\n\n";

$pass = 0; $fail = 0;
function ok($label, $cond) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  [PASS] $label\n"; }
    else       { $fail++; echo "  [FAIL] $label\n"; }
}

$phpBin = PHP_BINARY ?: 'php';

/** Run a PHP CLI script and return [stdout+stderr combined, exit code]. */
function b15_run_file(string $phpBin, string $scriptPath): array {
    $out = shell_exec(escapeshellarg($phpBin) . ' ' . escapeshellarg($scriptPath) . ' 2>&1; echo EXITCODE:$?');
    $out = (string) $out;
    $exit = 0;
    if (preg_match('/EXITCODE:(\d+)\s*$/', $out, $m)) {
        $exit = (int) $m[1];
        $out = preg_replace('/EXITCODE:\d+\s*$/', '', $out);
    }
    return [trim((string) $out), $exit];
}

/**
 * Run a snippet of PHP against the REAL app bootstrap + the REAL
 * api/owntracks-config.php (library mode) in its own fresh subprocess —
 * see the docblock note above on why each round trip needs its own
 * process.
 */
function b15_run_snippet(string $phpBin, string $snippet): array {
    $script = sys_get_temp_dir() . '/b15_snip_' . getmypid() . '_' . mt_rand(1000, 9999) . '.php';
    file_put_contents($script, "<?php\n"
        . "\$_SERVER['REQUEST_METHOD'] = 'GET';\n"
        . "require_once " . var_export(__DIR__ . '/../config.php', true) . ";\n"
        . "require_once " . var_export(__DIR__ . '/../inc/db.php', true) . ";\n"
        . "require_once " . var_export(__DIR__ . '/../inc/functions.php', true) . ";\n"
        . "if (!defined('OT_CONFIG_LIBRARY_ONLY')) define('OT_CONFIG_LIBRARY_ONLY', 1);\n"
        . "require_once " . var_export(__DIR__ . '/../api/owntracks-config.php', true) . ";\n"
        . $snippet
    );
    $result = b15_run_file($phpBin, $script);
    @unlink($script);
    return $result;
}

// ─── Part 1 — prove the ORIGINAL fatal ─────────────────────────────────
echo "--- Part 1: the original fatal (undefined settings-writer function) ---\n\n";

ok('set_setting() does not exist as a global function (the root cause)', !function_exists('set_setting'));

// Reproduce the actual failure mode in an isolated subprocess: execute
// the EXACT statement the old save_defaults handler ran for any
// non-empty value, against a real app bootstrap, and confirm PHP dies
// with the fatal this produced in production — before audit_log() or
// the device push ever ran.
$reproScript = sys_get_temp_dir() . '/b15_repro_' . getmypid() . '_' . mt_rand(1000, 9999) . '.php';
$undefinedFn = 'set' . '_setting'; // built at runtime so this literal never appears as a live call site
file_put_contents($reproScript, "<?php\n"
    . "require_once " . var_export(__DIR__ . '/../config.php', true) . ";\n"
    . "require_once " . var_export(__DIR__ . '/../inc/db.php', true) . ";\n"
    . "// The exact statement api/owntracks-config.php's save_defaults\n"
    . "// handler executed for any non-empty value, before this fix:\n"
    . $undefinedFn . "('b15_repro_never_persisted', 'x');\n"
    . "echo 'UNREACHABLE_IF_BUG_STILL_PRESENT';\n"
);
[$reproOut] = b15_run_file($phpBin, $reproScript);
@unlink($reproScript);
$reproducedFatal = strpos($reproOut, 'UNREACHABLE_IF_BUG_STILL_PRESENT') === false
    && stripos($reproOut, 'undefined function') !== false
    && stripos($reproOut, $undefinedFn) !== false;
ok('the exact old statement fatals with "Call to undefined function"', $reproducedFatal);
if (!$reproducedFatal) echo "    (subprocess output: " . substr($reproOut, 0, 400) . ")\n";

// ─── Part 2 — drive the REAL, fixed functions across separate requests ─
echo "\n--- Part 2: the fix - real _ot_save_defaults() / _ot_get_default() ---\n\n";

$testKey = 'monitoring'; // a real settings_key from _ot_tunable_keys()
$settingName = 'owntracks_default_' . $testKey;

// Clean slate — direct SQL, no reader involved yet.
db_query("DELETE FROM " . db_table('settings') . " WHERE name = ?", [$settingName]);
$preRow = db_fetch_one("SELECT `value` FROM " . db_table('settings') . " WHERE name = ?", [$settingName]);
ok('starts clean (no stored default)', $preRow === null);

// Round A (own process): save a non-empty value through the REAL
// handler function. Must not throw/fatal.
[$outA, $exitA] = b15_run_snippet($phpBin, "_ot_save_defaults(['{$testKey}' => '3']); echo 'SAVE_OK';");
ok('_ot_save_defaults() subprocess exits cleanly (no fatal)', $exitA === 0);
ok('_ot_save_defaults() completes and returns (does not throw)', strpos($outA, 'SAVE_OK') !== false);
if ($exitA !== 0 || strpos($outA, 'SAVE_OK') === false) echo "    (output: " . substr($outA, 0, 300) . ")\n";

// It actually landed in the `settings` table (direct SQL check, main process).
$row = db_fetch_one("SELECT `value` FROM " . db_table('settings') . " WHERE name = ?", [$settingName]);
ok('value actually landed in the settings table', $row !== null && $row['value'] === '3');

// Round B (a SEPARATE fresh process — like a real page load): the real
// reader function sees it. This is the part that was broken even
// independent of the fatal, since the old reader read a table the
// writer never touched.
[$outB, $exitB] = b15_run_snippet($phpBin, "echo var_export(_ot_get_default('{$testKey}'), true);");
ok('a separate read process sees the saved value via _ot_get_default()', trim($outB) === "'3'");
if (trim($outB) !== "'3'") echo "    (output: " . substr($outB, 0, 300) . ")\n";

// Round C (own process): clear it (empty string).
[$outC, $exitC] = b15_run_snippet($phpBin, "_ot_save_defaults(['{$testKey}' => '']); echo 'CLEAR_OK';");
ok('clearing does not throw', $exitC === 0 && strpos($outC, 'CLEAR_OK') !== false);

$rowAfterClear = db_fetch_one("SELECT `value` FROM " . db_table('settings') . " WHERE name = ?", [$settingName]);
ok('clearing removes the row from the settings table', $rowAfterClear === null);

// Round D (a SEPARATE fresh process): the real reader sees it cleared.
[$outD] = b15_run_snippet($phpBin, "echo var_export(_ot_get_default('{$testKey}'), true);");
ok('a separate read process sees it cleared (NULL = inherit hardcoded)', trim($outD) === 'NULL');
if (trim($outD) !== 'NULL') echo "    (output: " . substr($outD, 0, 300) . ")\n";

// Re-saving after a clear upserts cleanly (ON DUPLICATE KEY UPDATE path,
// not a duplicate-row INSERT failure).
b15_run_snippet($phpBin, "_ot_save_defaults(['{$testKey}' => '2']);");
b15_run_snippet($phpBin, "_ot_save_defaults(['{$testKey}' => '3']);");
$countRows = (int) db_fetch_value("SELECT COUNT(*) FROM " . db_table('settings') . " WHERE name = ?", [$settingName]);
ok('saving twice over an existing row upserts in place (exactly one row, no duplicates)', $countRows === 1);
$finalRow = db_fetch_one("SELECT `value` FROM " . db_table('settings') . " WHERE name = ?", [$settingName]);
ok('the final saved value is the last one written', $finalRow !== null && $finalRow['value'] === '3');

// Unrecognized settings_key is ignored, not silently written under an
// arbitrary name.
db_query("DELETE FROM " . db_table('settings') . " WHERE name = 'owntracks_default_not_a_real_tunable_key'");
b15_run_snippet($phpBin, "_ot_save_defaults(['not_a_real_tunable_key' => 'x']);");
$bogus = db_fetch_one(
    "SELECT `value` FROM " . db_table('settings') . " WHERE name = ?",
    ['owntracks_default_not_a_real_tunable_key']
);
ok('an unrecognized settings_key is never written', $bogus === null);

// Final cleanup — leave no test artefact behind.
db_query("DELETE FROM " . db_table('settings') . " WHERE name = ?", [$settingName]);
$cleanedUp = db_fetch_one("SELECT `value` FROM " . db_table('settings') . " WHERE name = ?", [$settingName]);
ok('cleanup: test fixture removed', $cleanedUp === null);

// ─── Part 3 — source-wiring checks (comment-stripped, see note above) ──
echo "\n--- Part 3: source wiring (comments stripped before matching) ---\n\n";

/** Strip PHP comments from source so a docblock's own prose about the
 *  OLD buggy call shapes can never false-match these checks. */
function b15_strip_comments(string $code): string {
    $out = '';
    foreach (token_get_all($code) as $token) {
        if (is_array($token)) {
            if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) continue;
            $out .= $token[1];
        } else {
            $out .= $token;
        }
    }
    return $out;
}

$rawSrc = file_get_contents(__DIR__ . '/../api/owntracks-config.php');
$src = b15_strip_comments($rawSrc);

ok('_ot_build_layered_config() (Layer B) reads via _ot_get_default()',
    preg_match('/\$val\s*=\s*_ot_get_default\(\$meta\[.settings_key.\]\)/', $src) === 1);
ok('action=get_defaults reads via _ot_get_default()',
    preg_match('/\$stored\s*=\s*_ot_get_default\(\$meta\[.settings_key.\]\)/', $src) === 1);
ok('action=get_member_diagnostics reads via _ot_get_default()',
    preg_match('/\$adminVal\s*=\s*_ot_get_default\(\$meta\[.settings_key.\]\)/', $src) === 1);
ok('no owntracks_default_* key is read via the config-table reader anywhere in this file (live code only)',
    preg_match("/get_setting\\(\\s*'owntracks_default_/", $src) === 0);
ok('save_defaults handler no longer calls the undefined settings-writer (live code only)',
    preg_match('/\b' . preg_quote($undefinedFn, '/') . '\s*\(/', $src) === 0);

// Second, previously-undiscovered fatal found during LIVE verification of
// this fix (not by the library-mode function tests above, which cannot
// reach the HTTP action-dispatch code below the OT_CONFIG_LIBRARY_ONLY
// guard where audit_log() is actually called): action=save_defaults and
// action=save_member_overrides both call audit_log(), but this file —
// unlike the other 89 api/*.php endpoints — never required inc/audit.php
// at the top level, only inline (inside the later unit_owntracks action
// blocks, guarded by function_exists()). Every real save_defaults or
// save_member_overrides request fataled with "Call to undefined function
// audit_log()" regardless of the settings-store fix above. Confirmed live
// on your-server.example.com (HTTP 500, api_guard ref logged the exact
// undefined-function fatal at the audit_log() call site) before this
// require was added.
ok('inc/audit.php is required at the top level (before the action dispatch), unconditionally',
    preg_match(
        "/require_once\\s+__DIR__\\s*\\.\\s*'\\/\\.\\.\\/inc\\/audit\\.php'\\s*;/",
        substr($src, 0, strpos($src, "if (defined('OT_CONFIG_LIBRARY_ONLY')) return;"))
    ) === 1);

// ─── Part 4 — companion fixes found in the same file, same commit ─────
echo "\n--- Part 4: companion settings-store fixes (same file, same commit) ---\n\n";
ok('owntracks_token_dual_window_days no longer read via the config-table reader',
    preg_match("/get_setting\\(\\s*'owntracks_token_dual_window_days'/", $src) === 0);
ok('owntracks_token_dual_window_days now read via get_variable()',
    strpos($src, "get_variable('owntracks_token_dual_window_days')") !== false);
ok('owntracks_email_link_template no longer read via the config-table reader',
    preg_match("/get_setting\\(\\s*'owntracks_email_link_template'/", $src) === 0);
ok('owntracks_email_link_template now read via get_variable()',
    strpos($src, "get_variable('owntracks_email_link_template')") !== false);

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
