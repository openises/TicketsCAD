<?php
/**
 * audit_log() calls must match the function's real signature AND be able to run.
 *
 * Phase 155. api/aprs-license-accept.php could never write its legal-trail row:
 * the call passed three arguments in the shape of an older logger (a TypeError at
 * the call) and sat behind function_exists('audit_log') in a file that never
 * loaded inc/audit.php (so the call was skipped before the TypeError could even
 * happen). Sweeping the tree for the same two faults found six more writers
 * wrong the same way -- api/messaging-send.php (every send went unaudited),
 * api/mesh.php (deleting a bridge answered HTTP 500 after the bridge was already
 * deleted), api/map-layer-prefs.php, api/auth.php (the four authentication-failure
 * events), and eight inc/ files (account lockout, FCC station ID, facility
 * scope denial, bed release, ...).
 *
 *   PART 1  tools/audit_log_arity.php over the real tree: zero findings. It reads
 *           the signature from the live function, tokenizes (a string or a
 *           comment that mentions audit_log( is never a call), and follows
 *           includes to prove inc/audit.php is loaded.
 *   PART 2  the same tool over fixtures: every bad shape is caught, every good
 *           shape is left alone. A gate that is only ever shown clean code has not
 *           been shown to catch anything.
 *   PART 3  the real endpoints that were wrong, driven for real, asking the audit
 *           table whether the row is there.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/_p155_helpers.php';
require_once __DIR__ . '/_test_admin.php';

$pass = 0; $fail = 0;
function al_ok(string $what, bool $cond, string $why = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "[PASS] {$what}\n"; }
    else       { $fail++; echo "[FAIL] {$what}" . ($why !== '' ? " -- {$why}" : '') . "\n"; }
}

$root = dirname(__DIR__);
echo "=== audit_log() arity and reachability ===\n\n";

/** Run the tool; returns [exit code, stdout]. */
function al_run_tool(string $root, array $args = []): array {
    $r = p155_run_process(array_merge([$root . '/tools/audit_log_arity.php'], $args), 120);
    return [$r['exit'], $r['stdout'] . $r['stderr']];
}

// ── PART 1: the real tree ──────────────────────────────────────────────────────
echo "-- Part 1: the real tree --\n";
[$code, $out] = al_run_tool($root);
al_ok('tools/audit_log_arity.php finds nothing wrong in the real tree', $code === 0, trim(substr($out, 0, 1500)));
preg_match('/(\d+) call\(s\) in (\d+) file\(s\) checked, (\d+) entry point/', $out, $m);
al_ok('...and it actually looked at the calls (a parser that stops matching must not read as clean)',
      isset($m[1]) && (int) $m[1] > 300 && (int) $m[2] > 100 && (int) $m[3] > 100,
      'calls=' . ($m[1] ?? '?') . ' files=' . ($m[2] ?? '?') . ' reach=' . ($m[3] ?? '?'));

// ── PART 2: fixtures ───────────────────────────────────────────────────────────
echo "\n-- Part 2: every bad shape is caught, every good shape left alone --\n";
$fx = sys_get_temp_dir() . '/al_fixtures_' . getmypid();
@mkdir($fx . '/api', 0777, true);
@mkdir($fx . '/inc', 0777, true);
register_shutdown_function(static function () use ($fx) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fx, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
    @rmdir($fx);
});

/** Write a fixture file whose audit_log() call (or calls) sit on known lines. */
function al_fixture(string $fx, string $rel, string $body, bool $loadsAudit = true): void {
    $head = "<?php\n" . ($loadsAudit ? "require_once __DIR__ . '/../inc/audit.php';\n" : '');
    file_put_contents($fx . '/' . $rel, $head . $body);
}

// Each bad shape: [name, fixture body, expected fragment of the finding].
$bad = [
    ['the old shape: details array in the summary slot (mesh bridge delete)',
        "audit_log('mesh', 'delete_bridge', 'mesh_bridges', \$id, 'Deleted', ['label' => 'x']);\naudit_log('mesh', 'delete_bridge', 'mesh_bridges', \$id, ['label' => 'x']);\n",
        'argument 5 ($summary) expects string'],
    ['the legacy 3-argument shape: details array in the targetType slot (aprs licence, messaging)',
        "audit_log('settings|aprs|license_attestation', \"summary {\$x}\", ['user_id' => 1]);\n",
        'argument 3 ($targetType) expects ?string'],
    ['array() long syntax is an array too (caught in the targetType slot)',
        "audit_log('a', 'b', array('x'));\n",
        'argument 3 ($targetType) expects ?string but is an array literal'],
    ['a string literal where the details array belongs',
        "audit_log('a', 'b', 'c', 1, 's', 'not-an-array');\n",
        'argument 6 ($details) expects ?array but is a string literal'],
    ['a null literal for the non-nullable summary',
        "audit_log('a', 'b', 'c', 1, null);\n",
        'argument 5 ($summary) expects string but is a null literal'],
    ['too few arguments',
        "audit_log('only-a-category');\n",
        '1 argument(s) given, 2 required'],
    ['too many arguments (a call written for a different signature)',
        "audit_log('a', 'b', 'c', 1, 's', [], 4, null, 'extra');\n",
        '9 arguments given but the function takes 8'],
    ['a string literal where the int severity belongs',
        "audit_log('a', 'b', 'c', 1, 's', [], 'high');\n",
        'argument 7 ($severity) expects int'],
    ['a function with a fixed return type: json_encode() is a string, details must be an array',
        "audit_log('a', 'b', 'c', 1, 's', json_encode(['x' => 1]));\n",
        'argument 6 ($details) expects ?array'],
    ['a concatenation is a string, whatever its parts',
        "audit_log('a', 'b', 'c', 1, 's', 'x' . \$y);\n",
        'argument 6 ($details) expects ?array'],
    ['a named argument of the wrong type',
        "audit_log('a', 'b', details: 'oops');\n",
        'argument 3 ($details) expects ?array'],
    ['audit_login() with the wrong thing in its details slot',
        "audit_login(1, 'bob', 'login', 'summary', 'oops');\n",
        'argument 5 ($details) expects ?array'],
    ['audit_data_access() with a string where the field list belongs',
        "audit_data_access('ticket', 1, 'patient_name');\n",
        'argument 3 ($fields) expects array'],
];
foreach ($bad as $i => [$name, $body, $expect]) {
    $rel = 'api/bad' . $i . '.php';
    al_fixture($fx, $rel, $body);
    [$c, $o] = al_run_tool($root, ['--path=' . $fx, '--no-baseline']);
    $mine = '';
    foreach (explode("\n", $o) as $line) { if (strpos($line, $rel) !== false) { $mine .= $line . "\n"; } }
    al_ok('caught: ' . $name, $c === 1 && strpos($mine, $expect) !== false, "wanted '{$expect}', got: " . trim($mine));
    @unlink($fx . '/' . $rel);
}

// Good shapes: not one finding among them.
$good = <<<'PHP'
audit_log('config', 'update', 'setting', 'theme', 'Changed theme');
audit_log('config', 'update', 'setting', $id, "Updated '{$name}' (user_id={$uid}), parens (like this) and, commas");
audit_log('personnel', 'create', 'member', $id, "Created {$n}", ['callsign' => 'KC9ABC'], AUDIT_HIGH);
audit_log('a', 'b', null, null, 's', null);
audit_log('a', 'b', $type, $target, $summary, $details ?? null, defined('AUDIT_LOW') ? AUDIT_LOW : 2);
audit_log('a', 'b', 'c', 1, 's', $arr, 4, AUDIT_ACTOR_SYSTEM);
audit_log(category: 'a', activity: 'b', details: ['x' => 1]);
audit_log(...$argsFromElsewhere);
audit_log('a', 'b', 'c', 1, 'Deleted ' . count($rows) . ' row(s)', ['n' => count($rows)]);
audit_log('a', 'b', 'c', 1, <<<TXT
heredoc summary with audit_log('x', [1]) inside it
TXT
);
$logger->audit_log([1, 2, 3]);                // a method of that name is somebody else's function
Other::audit_log(['x' => 1]);                 // so is a static
// audit_log('commented', 'out', ['array']);    <- a comment is not a call
$s = "audit_log('in', 'a string', ['array'])";   // neither is a string
function audit_log_wrapper($a) { return $a; }  // a different name entirely
audit_login(1, 'bob', 'login', 'summary');
audit_login(null, 'bob', 'login_failed', 'bad password', ['ip' => '1.2.3.4']);
audit_data_access('ticket', $id, ['patient_name']);
PHP;
al_fixture($fx, 'api/good.php', $good . "\n");
[$c, $o] = al_run_tool($root, ['--path=' . $fx, '--no-baseline']);
al_ok('every good shape is left alone (including strings, comments, heredocs, methods and spreads)', $c === 0, trim(substr($o, 0, 1200)));
@unlink($fx . '/api/good.php');

// Reachability.
al_fixture($fx, 'api/no_load.php', "audit_log('a', 'b', 'c', 1, 's');\n", false);
[$c, $o] = al_run_tool($root, ['--path=' . $fx, '--no-baseline']);
al_ok('caught: a file that calls audit_log() but never loads inc/audit.php',
      $c === 1 && strpos($o, 'api/no_load.php') !== false && strpos($o, 'loads inc/audit.php') !== false, trim($o));

file_put_contents($fx . '/api/guarded_no_load.php',
    "<?php\nif (function_exists('audit_log')) { audit_log('a', 'b', 'c', 1, 's'); }\n");
@unlink($fx . '/api/no_load.php');
[$c, $o] = al_run_tool($root, ['--path=' . $fx, '--no-baseline']);
al_ok('caught: the same file with the call hidden behind function_exists() (the silent form)',
      $c === 1 && strpos($o, 'api/guarded_no_load.php') !== false, trim($o));
@unlink($fx . '/api/guarded_no_load.php');

file_put_contents($fx . '/inc/loader.php', "<?php\nrequire_once __DIR__ . '/audit.php';\n");
file_put_contents($fx . '/api/transitive.php',
    "<?php\nrequire_once __DIR__ . '/../inc/loader.php';\naudit_log('a', 'b', 'c', 1, 's');\n");
file_put_contents($fx . '/api/dirname_form.php',
    "<?php\nrequire_once dirname(__DIR__) . '/inc/audit.php';\naudit_log('a', 'b', 'c', 1, 's');\n");
file_put_contents($fx . '/api/const_form.php',
    "<?php\nrequire_once NEWUI_ROOT . '/inc/audit.php';\naudit_log('a', 'b', 'c', 1, 's');\n");
file_put_contents($fx . '/api/dynamic_include.php',
    "<?php\nrequire_once \$somewhereElse;\naudit_log('a', 'b', 'c', 1, 's');\n");
[$c, $o] = al_run_tool($root, ['--path=' . $fx, '--no-baseline']);
al_ok('left alone: audit.php loaded through an intermediate include, via dirname(__DIR__), via NEWUI_ROOT, or an include it cannot resolve (it will not guess)',
      $c === 0, trim($o));
foreach (['inc/loader.php', 'api/transitive.php', 'api/dirname_form.php', 'api/const_form.php', 'api/dynamic_include.php'] as $f) { @unlink($fx . '/' . $f); }

// The baseline mechanism: a verified false positive can be recorded, and a stale entry is itself a failure.
al_fixture($fx, 'api/baselined.php', "audit_log('only-a-category');\n");
file_put_contents($fx . '/baseline.txt', "api/baselined.php:3  deliberate fixture: proves the baseline mechanism\n");
[$c, $o] = al_run_tool($root, ['--path=' . $fx, '--baseline=' . $fx . '/baseline.txt']);
al_ok('a baselined finding is accepted', $c === 0, trim($o));
@unlink($fx . '/api/baselined.php');
[$c, $o] = al_run_tool($root, ['--path=' . $fx, '--baseline=' . $fx . '/baseline.txt']);
al_ok('a baseline entry that no longer matches anything fails the run (so the list cannot rot)',
      $c === 1 && strpos($o, 'STALE baseline') !== false, trim($o));

// ── PART 3: the real endpoints ─────────────────────────────────────────────────
echo "\n-- Part 3: the endpoints that were wrong, driven for real --\n";
$prefix = $GLOBALS['db_prefix'] ?? '';
try {
    $adminId = test_admin_user_id();
    db_fetch_value("SELECT COUNT(*) FROM `{$prefix}newui_audit_log`");
    db_fetch_value("SELECT COUNT(*) FROM `{$prefix}mesh_bridges`");
} catch (Throwable $e) {
    echo "SKIP: database not reachable or tables missing: " . $e->getMessage() . "\n";
    echo "\n=== {$pass} passed, {$fail} failed ===\n";
    exit($fail > 0 ? 1 : 0);
}

$audit = static function (string $category, string $activity, string $extraWhere = '', array $params = []) use ($prefix): array {
    return db_fetch_all(
        "SELECT * FROM `{$prefix}newui_audit_log` WHERE category = ? AND activity = ? AND user_name = 'p155-probe' {$extraWhere} ORDER BY id",
        array_merge([$category, $activity], $params));
};
test_fixture_guard_track_where('newui_audit_log', "user_name = 'p155-probe' AND category IN ('mesh','comms','auth','config')");

// 3a. Deleting a mesh bridge: it answered HTTP 500 "delete failed" AFTER deleting, because audit_log() was undefined.
db_query("INSERT INTO `{$prefix}mesh_bridges` (label) VALUES ('p155 audit arity bridge')");
$bid = (int) db_insert_id();
test_fixture_guard_track('mesh_bridges', $bid);
test_fixture_guard_track_where('bridge_tokens', 'bridge_id = ?', [$bid]);
$r = p155_probe('session', 'api/mesh.php', $adminId, ['action' => 'delete_bridge', 'bridge_id' => $bid], 'POST', 'action=delete_bridge');
al_ok('mesh: deleting a bridge answers 200 ok (it used to answer 500 after deleting)', $r['http'] === 200 && !empty($r['json']['ok']),
      'http=' . $r['http'] . ' ' . $r['raw']);
$gone = db_fetch_one("SELECT deleted_at FROM `{$prefix}mesh_bridges` WHERE id = ?", [$bid]);
al_ok('mesh: the bridge really is soft-deleted', $gone && $gone['deleted_at'] !== null);
al_ok('mesh: the delete wrote its audit row', count($audit('mesh', 'delete_bridge', 'AND target_id = ?', [(string) $bid])) === 1);

// 3b. Sending a message: every send went unaudited (wrong arguments + swallowed TypeError).
db_query("DELETE FROM `{$prefix}messages` WHERE body = 'p155 audit arity message'");
test_fixture_guard_track_where('messages', "body = 'p155 audit arity message'");
$r = p155_probe('session', 'api/messaging-send.php', $adminId,
    ['channel' => 'smtp', 'to' => 'nobody@example.invalid', 'body' => 'p155 audit arity message'], 'POST');
al_ok('messaging-send: the request completes with a JSON body (the send itself fails -- no mail is configured -- which is not the point)',
      is_array($r['json']) && ($r['json']['channel'] ?? '') === 'smtp', $r['raw']);
$rows = $audit('comms', 'send', "AND target_type = 'messaging_channel'");
al_ok('messaging-send: the send was audited, in the real shape', count($rows) >= 1 && $rows[count($rows) - 1]['target_id'] === 'smtp'
      && strpos((string) $rows[count($rows) - 1]['summary'], 'recipient(s) via smtp') !== false, json_encode($rows));
$d = $rows ? json_decode((string) $rows[count($rows) - 1]['details'], true) : null;
al_ok('messaging-send: the details carry the channel, the recipients and the per-recipient results',
      is_array($d) && ($d['channel'] ?? '') === 'smtp' && ($d['recipients'][0] ?? '') === 'nobody@example.invalid' && isset($d['results'][0]));

// 3c. Changing the install's default map layers: audited behind a guard that was false.
$hadRow = db_fetch_one("SELECT `value` FROM `{$prefix}settings` WHERE `name` = 'map_layer_defaults'");
$hadVal = $hadRow ? (string) $hadRow['value'] : null;
test_fixture_guard_track_cleanup(static function () use ($prefix, $hadVal) {
    if ($hadVal === null) {
        db_query("DELETE FROM `{$prefix}settings` WHERE `name` = 'map_layer_defaults'");
    } else {
        db_query("UPDATE `{$prefix}settings` SET `value` = ? WHERE `name` = 'map_layer_defaults'", [$hadVal]);
    }
}, 'restore map_layer_defaults');
$r = p155_probe('session', 'api/map-layer-prefs.php', $adminId, ['admin_defaults' => []], 'POST');
al_ok('map-layer-prefs: saving the administrator default is accepted', $r['http'] === 200 && !empty($r['json']['ok']), 'http=' . $r['http'] . ' ' . $r['raw']);
al_ok('map-layer-prefs: saving the administrator default is audited',
      count($audit('config', 'update', "AND target_type = 'settings' AND summary = 'Updated default map layer visibility'")) >= 1);
// 3d. The authentication-failure events in api/auth.php were behind a guard that was false.
$ghost = 987654321;   // a session for a user who holds no roles at all
$r = p155_probe('session', 'api/aprs-license-accept.php', $ghost, [], 'POST');
al_ok('auth: a session whose user holds no roles is refused with 403', $r['http'] === 403, 'http=' . $r['http'] . ' ' . $r['raw']);
$rows = db_fetch_all("SELECT * FROM `{$prefix}newui_audit_log` WHERE category = 'auth' AND activity = 'no_roles' AND target_id = ?", [(string) $ghost]);
al_ok('auth: the refusal was audited (the four auth.php audit events never were)', count($rows) >= 1, 'rows=' . count($rows));
test_fixture_guard_track_where('newui_audit_log', "category = 'auth' AND activity = 'no_roles' AND target_id = ?", [(string) $ghost]);

// 3e. The static guarantee, restated where a reader of this test will see it.
foreach (['api/aprs-license-accept.php', 'api/auth.php', 'api/mesh.php', 'api/map-layer-prefs.php', 'api/messaging-send.php',
          'inc/backup_schedule.php', 'inc/bed_auto.php', 'inc/facility-bed-release.php', 'inc/facility-scope.php',
          'inc/fcc_station_id.php', 'inc/incident-number.php', 'inc/login-security.php', 'inc/message-log-retention.php'] as $f) {
    $src = (string) file_get_contents($root . '/' . $f);
    al_ok("{$f} loads inc/audit.php itself", (bool) preg_match('~require_once\s+__DIR__\s*\.\s*\'(/\.\.)?/(inc/)?audit\.php\'~', $src));
}

// Tidy up now rather than leaving it to the shutdown sweep (which stays as the backstop).
db_query("DELETE FROM `{$prefix}newui_audit_log` WHERE user_name = 'p155-probe' AND category IN ('mesh','comms','auth','config')");
db_query("DELETE FROM `{$prefix}newui_audit_log` WHERE category = 'auth' AND activity = 'no_roles' AND target_id = ?", [(string) $ghost]);
db_query("DELETE FROM `{$prefix}messages` WHERE body = 'p155 audit arity message'");
db_query("DELETE FROM `{$prefix}bridge_tokens` WHERE bridge_id = ?", [$bid]);
db_query("DELETE FROM `{$prefix}mesh_bridges` WHERE id = ?", [$bid]);

echo "\n=== {$pass} passed, {$fail} failed ===\n";
exit($fail > 0 ? 1 : 0);
