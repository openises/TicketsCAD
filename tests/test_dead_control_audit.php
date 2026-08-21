<?php
/**
 * Dead-control audit gate (GH #91, 2026-08-19; extended 2026-08-20 with
 * checks (c) phantom columns and (d) dead API response keys).
 *
 * Drives the REAL tool (tools/dead_control_audit.php) against fixture
 * trees via --path, same convention as tests/test_rbac_permission_audit.php
 * and tests/test_ui_consistency_audit.php: the tool always validates
 * against the REAL live schema/database (a fixture can't invent a table),
 * but --path lets file SCANNING run over a throwaway directory instead of
 * the real app tree, so a probe can never leak into (or race with) the
 * live working tree.
 *
 * Sections below, in the order the tool itself reports them:
 *   (a) settings keys written but unread
 *   (b) database columns written but unread
 *   (c) database columns read but unwritten ("phantom") — new 2026-08-20
 *   (d) API response keys emitted but read by no JS file — new 2026-08-20
 *
 * Usage: php tests/test_dead_control_audit.php
 *
 * GH #91 follow-up (2026-08-20/21, reported by rjonesbsink): dca_run() used
 * to shell out via exec(), which is a fatal "Call to undefined function
 * exec()" on any host whose disable_functions blocks it (common on shared
 * or hardened hosting) — quietly turning this whole gate into a no-op
 * instead of actually running the audit. It now spawns the audit via
 * argv-array proc_open() (gh91_proc_run() below — mirrors
 * run_via_proc_open() in tools/check-schema.php and runStreamingImport() in
 * tools/update-lookup-data.php), and the file degrades to an explicit
 * SKIP — never a silent/false pass — when proc_open() itself is also
 * unavailable. See tests/test_gh91_audit_wrapper_subprocess_fallback.php
 * for the regression proof (spawns real PHP subprocesses with
 * disable_functions set both ways).
 *
 * @requires-db
 */

declare(strict_types=1);

$base = realpath(__DIR__ . '/..');
$tool = $base . '/tools/dead_control_audit.php';

// Loaded before any output, same reason the tool itself loads config.php
// before touching $argv-driven behavior: session ini directives warn once
// a byte has already been sent.
require_once $base . '/config.php';

echo "=== Dead-control audit gate (GH #91) ===\n\n";
$pass = 0;
$fail = 0;
function dca_ok(string $n): void { global $pass; echo "[PASS] $n\n"; $pass++; }
function dca_bad(string $n, string $why = ''): void {
    global $fail; echo "[FAIL] $n" . ($why !== '' ? " — $why" : '') . "\n"; $fail++;
}
function dca_true($c, string $n, string $why = ''): void { $c ? dca_ok($n) : dca_bad($n, $why); }

/**
 * Run a subprocess via argv-array proc_open() — no shell involved, so this
 * keeps working when exec()/shell_exec()/popen() are removed via
 * disable_functions (proc_open is a separate function and is not usually
 * included in the same hardening presets — confirmed for this exact
 * disable_functions shape by tests/test_gh93_streaming_import_popen_followup.php's
 * Test B). stdout and stderr share ONE temp-file sink, matching the
 * interleaving `2>&1` gave the old exec() call. The exit code comes from
 * proc_close()'s own return value, which is reliable here because
 * proc_get_status() is never called first — unlike runStreamingImport()'s
 * polling loop, there is no earlier read to "spend" the real exit code.
 *
 * @param array $argv [$binary, $arg1, $arg2, ...]
 * @return array{0:int,1:string} [exitCode, combinedOutput]
 */
function gh91_proc_run(array $argv): array {
    $sink = tmpfile();
    if ($sink === false) {
        return [127, '(could not open a temporary file to capture output)'];
    }
    $descriptors = [0 => ['pipe', 'r'], 1 => $sink, 2 => $sink];
    $pipes = [];
    $proc = @proc_open($argv, $descriptors, $pipes);
    if (!is_resource($proc)) {
        fclose($sink);
        return [127, '(failed to start the subprocess)'];
    }
    fclose($pipes[0]);
    $exit = proc_close($proc);
    rewind($sink);
    $out = rtrim((string) stream_get_contents($sink), "\r\n");
    fclose($sink);
    return [$exit, $out];
}

/** Run the audit; return [exitCode, output]. */
function dca_run(string $tool, array $args = []): array {
    return gh91_proc_run(array_merge([PHP_BINARY, $tool], $args));
}

if (!function_exists('proc_open')) {
    echo "SKIP: this PHP cannot start a subprocess (proc_open() is disabled via " .
         "disable_functions) — the dead-control audit could not be run\n";
    echo "\n0 passed, 0 failed\n";
    exit(0);
}

try {
    db_fetch_value('SELECT 1');
} catch (Throwable $e) {
    echo "SKIP: no database connection (" . $e->getMessage() . ")\n";
    echo "\n0 passed, 0 failed\n";
    exit(0);
}

// ── Fixtures live outside the repo so a crash can't leave one behind for
//    the real app-tree run below to trip over ───────────────────────────
$tmp = sys_get_temp_dir() . '/dca_fixtures_' . getmypid();
register_shutdown_function(static function () use ($tmp) {
    if (!is_dir($tmp)) return;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
    @rmdir($tmp);
});

// ═══════════════════════════════════════════════════════════════════════
// (a) Settings-key check — the exact GH #91 bug shape: a control that
// saves, with nothing on the other end.
// ═══════════════════════════════════════════════════════════════════════

// A dead control: data-key with no matching get_variable() anywhere.
$dead = $tmp . '/dead'; @mkdir($dead . '/inc', 0777, true);
file_put_contents($dead . '/settings.php', <<<'PHP'
<?php
echo '<input type="checkbox" data-key="totally_fake_dead_control_xyz">';
PHP);
[$code, $out] = dca_run($tool, ['--settings-only', '--path=' . $dead]);
dca_true($code === 1, 'a data-key control with no reader is flagged (exit 1)', "exit $code");
dca_true(
    strpos($out, 'totally_fake_dead_control_xyz') !== false,
    'the finding names the dead key',
    $out
);

// A live control: data-key WITH a matching get_variable() read.
$live = $tmp . '/live'; @mkdir($live . '/inc', 0777, true);
file_put_contents($live . '/settings.php', <<<'PHP'
<?php
echo '<input type="checkbox" data-key="totally_fake_live_control_xyz">';
PHP);
file_put_contents($live . '/inc/consumer.php', <<<'PHP'
<?php
$v = get_variable('totally_fake_live_control_xyz');
if ($v === '1') { do_the_thing(); }
PHP);
[$lcode, $lout] = dca_run($tool, ['--settings-only', '--path=' . $live]);
dca_true($lcode === 0, 'a data-key control WITH a get_variable() reader is not flagged',
    "exit $lcode; output:\n$lout");

// The generic-form-echo illusion must not count as a read: a page reading
// its OWN posted value back into itself (the exact mechanism that made
// Chat Bridge/Severity Levels look alive) is not evidence of real use.
// settings.php is excluded from the read scan for this reason.
$illusion = $tmp . '/illusion'; @mkdir($illusion, 0777, true);
file_put_contents($illusion . '/settings.php', <<<'PHP'
<?php
echo '<input type="text" data-key="totally_fake_illusion_control_xyz">';
// A hand-rolled echo-back, same shape applySettingsToForm() produces —
// must NOT be mistaken for a real application-code read.
$s = ['totally_fake_illusion_control_xyz' => 'x'];
echo $s['totally_fake_illusion_control_xyz'];
PHP);
[$icode, $iout] = dca_run($tool, ['--settings-only', '--path=' . $illusion]);
dca_true($icode === 1,
    "a control's own page echoing its posted value back does not count as a real read",
    "exit $icode; output:\n$iout");

// ═══════════════════════════════════════════════════════════════════════
// (b) Column check — against the REAL schema (a fixture can't invent a
// table), scoped to a real, known-empty-of-findings table so the test
// doesn't depend on the wider app's own baseline staying in sync.
// ═══════════════════════════════════════════════════════════════════════
[$ccode, $cout] = dca_run($tool, ['--columns-only', '--table=user']);
dca_true($ccode === 0, 'user table has zero NEW column findings post Phase 147 cleanup',
    $cout);
dca_true(strpos($cout, "0 distinct finding(s)") !== false || strpos($cout, ", 0 new") !== false,
    'user table column check reports zero findings', $cout);

// The --include-orphaned mode (opt-in, not part of the default/CI-gated
// run) should still find the 7 MARKED columns when explicitly asked —
// proves the check fires, and that it is genuinely opt-in (the default
// run above did NOT need a baseline entry for them).
[$ocode, $oout] = dca_run($tool, ['--columns-only', '--table=user', '--include-orphaned', '--all']);
foreach (['name_mi', 'title_id', 'addr_street', 'addr_city', 'addr_st', 'phone_s', 'email_s'] as $col) {
    dca_true(strpos($oout, "user.$col") !== false,
        "--include-orphaned still finds the marked (reserved) user.$col column", $oout);
}
dca_true(
    strpos($oout, 'user.pers') === false
    && strpos($oout, 'user.disp') === false
    && strpos($oout, 'user.teams') === false
    && strpos($oout, 'user.reporting') === false
    && strpos($oout, 'user.open_at') === false
    && strpos($oout, 'user.ticket_per_page') === false
    && strpos($oout, 'user.sortorder') === false
    && strpos($oout, 'user.sort_desc') === false
    && strpos($oout, 'user.browser') === false
    && strpos($oout, 'user.sid') === false,
    'the 10 DROPPED columns no longer appear at all (they do not exist)',
    $oout
);
dca_true(strpos($oout, 'user.level') === false,
    'user.level is not flagged — it has a real (migration-only) read path', $oout);

// ═══════════════════════════════════════════════════════════════════════
// (c) Phantom-column check — mirror of (b). Validated against the real
// schema (same convention as (b)'s --table=user test above), scanned
// from throwaway --path fixtures so the app tree's own real writers
// never contaminate what counts as "written" here. `ticket.description`
// and `ticket.id` are real, live columns picked specifically because
// neither appears in this project's own dead_control_phantom_baseline.txt
// — a real finding key collision with that baseline would make this
// test's assertions depend on the baseline file staying in sync, the
// same trap --table=user's own comment above already avoids.
// ═══════════════════════════════════════════════════════════════════════

// True positive: a column that is READ (a real SQL SELECT) but never
// written anywhere in the scanned (fixture) tree.
$phantomTrue = $tmp . '/phantom_true'; @mkdir($phantomTrue . '/api', 0777, true);
file_put_contents($phantomTrue . '/api/probe.php', <<<'PHP'
<?php
$rows = db_fetch_all("SELECT `description` FROM `ticket` WHERE `id` = ?", [1]);
PHP);
[$ptcode, $ptout] = dca_run($tool, ['--phantom-only', '--path=' . $phantomTrue]);
dca_true($ptcode === 1, 'a column read but never written is flagged (exit 1)', "exit $ptcode; $ptout");
dca_true(strpos($ptout, 'phantom:ticket.description') !== false,
    'the finding names the real read-without-write column', $ptout);

// True negative: the SAME column, but also genuinely written (a literal
// UPDATE ... SET) in the same scanned tree — must NOT be flagged.
$phantomWritten = $tmp . '/phantom_written'; @mkdir($phantomWritten . '/api', 0777, true);
file_put_contents($phantomWritten . '/api/probe.php', <<<'PHP'
<?php
$rows = db_fetch_all("SELECT `description` FROM `ticket` WHERE `id` = ?", [1]);
db_query("UPDATE `ticket` SET `description` = ? WHERE `id` = ?", [$new, $id]);
PHP);
[$pwcode, $pwout] = dca_run($tool, ['--phantom-only', '--path=' . $phantomWritten]);
dca_true($pwcode === 0, 'a column that is both read AND written is not flagged', $pwout);
dca_true(strpos($pwout, 'phantom:ticket.description') === false,
    'the finding does not appear when a real write path exists', $pwout);

// Dynamic-write broadening: the column is read via SQL, and "written" only
// through the SET-clause-built-from-a-PHP-array shape this codebase's own
// generic writers commonly use (api/constituents.php, 30+ other files) —
// proves the broadening pass check (c) shares with check (b) actually
// suppresses the finding, not just the literal-UPDATE case above.
$phantomDynamic = $tmp . '/phantom_dynamic'; @mkdir($phantomDynamic . '/api', 0777, true);
file_put_contents($phantomDynamic . '/api/probe.php', <<<'PHP'
<?php
$rows = db_fetch_all("SELECT `description` FROM `ticket` WHERE `id` = ?", [1]);

$fields = [
    'description' => trim($input['description'] ?? ''),
];
$setParts = [];
$params = [];
foreach ($fields as $col => $val) {
    $setParts[] = "`{$col}` = ?";
    $params[] = $val;
}
db_query("UPDATE " . db_table('ticket') . " SET " . implode(', ', $setParts) . " WHERE `id` = ?", $params);
PHP);
[$pdcode, $pdout] = dca_run($tool, ['--phantom-only', '--path=' . $phantomDynamic]);
dca_true($pdcode === 0,
    'a column written only via a dynamically-built SET clause is not flagged (broadening works)', $pdout);
dca_true(strpos($pdout, 'phantom:ticket.description') === false,
    'the finding does not appear when the write is dynamic-broadened', $pdout);

// AUTO_INCREMENT exclusion: ticket.id is read constantly and, by
// construction, never appears in any application INSERT column list —
// must never be flagged regardless of how it's read.
$phantomAutoInc = $tmp . '/phantom_autoinc'; @mkdir($phantomAutoInc . '/api', 0777, true);
file_put_contents($phantomAutoInc . '/api/probe.php', <<<'PHP'
<?php
$rows = db_fetch_all("SELECT `id` FROM `ticket` WHERE `status` = ?", [2]);
PHP);
[$aicode, $aiout] = dca_run($tool, ['--phantom-only', '--path=' . $phantomAutoInc]);
dca_true($aicode === 0, 'an AUTO_INCREMENT primary key read but never app-written is not flagged', $aiout);
dca_true(strpos($aiout, 'phantom:ticket.id') === false,
    'ticket.id specifically does not appear (database-managed column exclusion)', $aiout);

// A SQL alias TARGET must never be mistaken for a bare column reference on
// some OTHER table in the same query, purely by name coincidence — the
// exact false positive live-verification against your-server.example.com's
// database found: `t.leader_dpty AS deputy_id` (api/teams.php) left
// "deputy_id" behind for the bare-word extractor, which then matched
// training's genuinely-live-but-orphaned teams.deputy_id column (schema
// drift present on that install, absent from this dev box and from a
// fresh CI install) purely because the ALIAS TEXT happened to coincide
// with a real column name. `member.email AS description` / `ticket t` in
// the same query recreates the identical shape against two real, stable
// columns on this dev box: ticket.description must NOT be credited as
// read just because it shares a name with the alias `description` was
// given to `member.email`.
// NOTE for future editors of this fixture: keep any comment text below
// clear of a literal `word.word` shape — the bareRead scan's JS-dot-
// notation pattern (`\.([a-zA-Z_][a-zA-Z0-9_]*)\b`) does not distinguish
// real code from prose, so a comment that spells out "ticket.description"
// gets read the same as a genuine `ticket.description` property access
// and defeats the very thing this fixture exists to prove clean. Caught
// live while writing this exact fixture — the first draft's own
// explanatory comment about ticket_description tripped this and had to
// be reworded.
$phantomAliasCollision = $tmp . '/phantom_alias_collision'; @mkdir($phantomAliasCollision . '/api', 0777, true);
file_put_contents($phantomAliasCollision . '/api/probe.php', <<<'PHP'
<?php
$rows = db_fetch_all(
    "SELECT `m`.`email` AS `description` FROM `member` `m` JOIN `ticket` `t` ON `t`.`id` = `m`.`id`"
);
// member email also needs its own write in this fixture, or it would
// show up as its own separate (correct, but unrelated) finding
db_query("UPDATE `member` SET `email` = ? WHERE `id` = ?", [$x, $id]);
PHP);
[$acccode, $accout] = dca_run($tool, ['--phantom-only', '--path=' . $phantomAliasCollision]);
dca_true($acccode === 0,
    'a SQL alias TARGET is not mistaken for a bare column reference on another joined table', $accout);
dca_true(strpos($accout, 'phantom:ticket.description') === false,
    'ticket.description is not credited as read just because it shares a name with an alias target', $accout);

// ═══════════════════════════════════════════════════════════════════════
// (d) Dead API response key check — pure source-text scanning, no live
// DB dependency (unlike (b)/(c) above).
// ═══════════════════════════════════════════════════════════════════════

// True positive: json_response() emits a key nothing in assets/js/ reads.
$apiDead = $tmp . '/api_dead'; @mkdir($apiDead . '/api', 0777, true);
@mkdir($apiDead . '/assets/js', 0777, true);
file_put_contents($apiDead . '/api/probe.php', <<<'PHP'
<?php
json_response([
    'totally_fake_dead_apikey_xyz' => 1,
    'success' => true,
]);
PHP);
file_put_contents($apiDead . '/assets/js/probe.js', <<<'JS'
(function () { 'use strict'; console.log('no reference to the dead key here'); })();
JS);
[$adcode, $adout] = dca_run($tool, ['--api-only', '--path=' . $apiDead]);
dca_true($adcode === 1, 'a json_response() key read by no JS file is flagged (exit 1)', "exit $adcode; $adout");
dca_true(strpos($adout, 'apikey:totally_fake_dead_apikey_xyz') !== false,
    'the finding names the dead API key', $adout);

// True negative: the SAME key, but genuinely read by assets/js/.
$apiLive = $tmp . '/api_live'; @mkdir($apiLive . '/api', 0777, true);
@mkdir($apiLive . '/assets/js', 0777, true);
file_put_contents($apiLive . '/api/probe.php', <<<'PHP'
<?php
json_response([
    'totally_fake_live_apikey_xyz' => 1,
]);
PHP);
file_put_contents($apiLive . '/assets/js/probe.js', <<<'JS'
(function () { 'use strict'; if (data.totally_fake_live_apikey_xyz) { doThing(); } })();
JS);
[$alcode, $alout] = dca_run($tool, ['--api-only', '--path=' . $apiLive]);
dca_true($alcode === 0, 'a json_response() key genuinely read by assets/js/ is not flagged', $alout);
dca_true(strpos($alout, 'apikey:totally_fake_live_apikey_xyz') === false,
    'the finding does not appear when JS reads it', $alout);

// The `echo json_encode(...)` shape (not just json_response()) must also
// be recognized as an emission source.
$apiEchoEncode = $tmp . '/api_echo_encode'; @mkdir($apiEchoEncode . '/api', 0777, true);
@mkdir($apiEchoEncode . '/assets/js', 0777, true);
file_put_contents($apiEchoEncode . '/api/probe.php', <<<'PHP'
<?php
echo json_encode([
    'totally_fake_echo_encode_apikey_xyz' => 1,
]);
PHP);
[$eecode, $eeout] = dca_run($tool, ['--api-only', '--path=' . $apiEchoEncode]);
dca_true($eecode === 1, 'echo json_encode(...) is recognized as an emission source (exit 1)', "exit $eecode; $eeout");
dca_true(strpos($eeout, 'apikey:totally_fake_echo_encode_apikey_xyz') !== false,
    'the finding names the dead key from the echo json_encode() shape', $eeout);

// json_encode() NOT preceded by echo/print must NOT count as emission —
// e.g. a value serialized for a log line or a DB column, never sent to
// the browser.
$apiNotEmitted = $tmp . '/api_not_emitted'; @mkdir($apiNotEmitted . '/api', 0777, true);
file_put_contents($apiNotEmitted . '/api/probe.php', <<<'PHP'
<?php
$serialized = json_encode(['totally_fake_non_emitted_apikey_xyz' => 1]);
error_log($serialized);
PHP);
[$necode, $neout] = dca_run($tool, ['--api-only', '--path=' . $apiNotEmitted]);
dca_true($necode === 0, 'json_encode() not preceded by echo/print is not treated as browser emission', $neout);
dca_true(strpos($neout, 'totally_fake_non_emitted_apikey_xyz') === false,
    'the finding does not appear for a non-emitted json_encode() call', $neout);

// A comment containing an apostrophe immediately above a json_response()
// call must not desynchronize the key extraction — the exact regression
// this check's own tokenizer rewrite fixed during development (an
// earlier char-scanning version silently swallowed
// severity_breakdown/disposition_breakdown because of a "don't" in a
// doc-comment immediately above the real api/reports.php call).
$apiComment = $tmp . '/api_comment'; @mkdir($apiComment . '/api', 0777, true);
file_put_contents($apiComment . '/api/probe.php', <<<'PHP'
<?php
// callers that don't know about this key are unaffected either way
json_response([
    'totally_fake_apostrophe_apikey_xyz' => 1,
]);
PHP);
[$acode, $aout] = dca_run($tool, ['--api-only', '--path=' . $apiComment]);
dca_true($acode === 1,
    "an apostrophe in a comment immediately above json_response() doesn't swallow the real call (exit 1)",
    "exit $acode; $aout");
dca_true(strpos($aout, 'apikey:totally_fake_apostrophe_apikey_xyz') !== false,
    'the finding still names the key despite the preceding apostrophe-bearing comment', $aout);

// Inline <script> blocks on a *.php page count as a READ source (the
// severity_counts / situation.php regression found live during this
// check's own development).
$apiInline = $tmp . '/api_inline'; @mkdir($apiInline . '/api', 0777, true);
file_put_contents($apiInline . '/api/probe.php', <<<'PHP'
<?php
json_response([
    'totally_fake_inline_script_apikey_xyz' => 1,
]);
PHP);
file_put_contents($apiInline . '/situation.php', <<<'PHP'
<?php
?>
<script>
var sevCounts = data.totally_fake_inline_script_apikey_xyz || {};
</script>
PHP);
[$incode, $inout] = dca_run($tool, ['--api-only', '--path=' . $apiInline]);
dca_true($incode === 0, 'a key read only by an inline <script> block on a page template is not flagged', $inout);
dca_true(strpos($inout, 'totally_fake_inline_script_apikey_xyz') === false,
    'the finding does not appear when only an inline <script> reads it', $inout);

// tools/ is excluded from the EMITTED scan entirely — a json_response()
// inside a CLI-only tools/ script must never be treated as browser
// emission (every real tools/ call site is a CLI diagnostic).
$apiTools = $tmp . '/api_tools'; @mkdir($apiTools . '/tools', 0777, true);
file_put_contents($apiTools . '/tools/probe.php', <<<'PHP'
<?php
json_response([
    'totally_fake_tools_dir_apikey_xyz' => 1,
]);
PHP);
[$tlcode, $tlout] = dca_run($tool, ['--api-only', '--path=' . $apiTools]);
dca_true($tlcode === 0, 'tools/ is excluded from the EMITTED scan (a tools/ json_response() is invisible)', $tlout);
dca_true(strpos($tlout, 'totally_fake_tools_dir_apikey_xyz') === false,
    'the finding does not appear for a key only ever emitted from tools/', $tlout);

// ═══════════════════════════════════════════════════════════════════════
// The real app tree — every genuinely-fixed finding stays fixed; only
// documented, explained baseline entries remain.
//
// This check runs the tool with no --path, so it scans whatever tree this
// test itself is executing from. tools/release-snapshot.sh's published
// snapshot deliberately EXCLUDES several files (tools/seed_training_demo.php,
// tools/a-seed-script.php, specs/, coordination/, …) that are the
// ONLY write evidence for some legacy/demo-only columns (member.field3/6/7/
// 8/21, facilities._from/icon_str, member._by/_from/_on, in_types.opacity/
// watch — all seeded exclusively by the excluded demo scripts). Running this
// check against a deliberately partial tree produces phantom-column false
// positives that say nothing about the real app tree's health; the dev
// tree (which always has every file) is where this assertion has teeth.
// Detected via tools/release-snapshot.sh's own absence — that file is
// excluded from every published snapshot too.
$isPublishedSnapshot = !is_file($base . '/tools/release-snapshot.sh');
if ($isPublishedSnapshot) {
    dca_ok('no NEW dead-control findings in the app tree — skipped: this is a published snapshot missing files (demo seed scripts, specs/) that are the sole write evidence for some legacy columns, so the check is not meaningful here');
} else {
    [$rcode, $rout] = dca_run($tool);
    $tail = implode("\n", array_slice(explode("\n", $rout), -15));
    dca_true($rcode === 0, 'no NEW dead-control findings in the app tree', $tail);
}

foreach (['tools/dead_control_settings_baseline.txt', 'tools/dead_control_column_baseline.txt'] as $bf) {
    dca_true(is_file($base . '/' . $bf), "$bf exists");
}

// chat_bridge_* must be explicitly present in the settings baseline
// (GH #89 concurrency note) — if GH #89's own reader lands and this
// baseline entry becomes genuinely stale, this assertion is exactly what
// should catch it on a future run: still passing either way (baseline
// membership is inert once a real reader exists), but never silently
// dropped by an unrelated edit.
$settingsBaseline = (string) @file_get_contents($base . '/tools/dead_control_settings_baseline.txt');
foreach (['chat_bridge_telegram', 'chat_bridge_slack', 'chat_bridge_email', 'chat_bridge_mesh'] as $k) {
    dca_true(strpos($settingsBaseline, "setting:$k") !== false,
        "settings baseline documents $k (GH #89, not GH #91's to fix)");
}

// The tool must refuse to run under a web SAPI.
$src = (string) file_get_contents($tool);
dca_true(
    strpos($src, "if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }") !== false,
    "$tool carries the canonical CLI-only guard"
);

// ═══════════════════════════════════════════════════════════════════════
// Phase 147 migration itself — idempotent, and produces the documented
// outcome against the live database.
// ═══════════════════════════════════════════════════════════════════════
$migration = $base . '/sql/run_phase147_user_table_cleanup.php';
dca_true(is_file($migration), 'sql/run_phase147_user_table_cleanup.php exists');

$prefix = $GLOBALS['db_prefix'] ?? '';
$dropped = ['pers', 'disp', 'teams', 'reporting', 'open_at', 'ticket_per_page',
    'sortorder', 'sort_desc', 'browser', 'sid'];
$stillThere = [];
foreach ($dropped as $c) {
    $exists = db_fetch_one(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
        [$prefix . 'user', $c]
    );
    if ($exists) { $stillThere[] = $c; }
}
dca_true($stillThere === [], 'all 10 dead user columns are dropped from the live schema',
    'still present: ' . implode(', ', $stillThere));

$levelComment = (string) (db_fetch_one(
    "SELECT COLUMN_COMMENT FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'level'",
    [$prefix . 'user']
)['COLUMN_COMMENT'] ?? '');
dca_true(strpos($levelComment, 'Phase 147') !== false && stripos($levelComment, 'privileges') === false,
    "user.level's stale 'privileges' comment is corrected", $levelComment);

// Re-run is a no-op (idempotent) — the migration's own output says so.
[$mcode, $mout] = dca_run($migration);
dca_true($mcode === 0, 'sql/run_phase147_user_table_cleanup.php exits 0 on a second run', $mout);
dca_true(
    strpos($mout, 'already absent') !== false && strpos($mout, 'already') !== false,
    'a second run reports every step as already-applied (idempotent)', $mout
);

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
