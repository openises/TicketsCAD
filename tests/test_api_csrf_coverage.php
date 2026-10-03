<?php
/**
 * Every state-changing endpoint under api/ that the session cookie authenticates must reject a
 * request that does not carry the session's CSRF token -- BEFORE it changes anything.
 *
 * WHY THIS IS A TEST THAT FINDS THE ENDPOINTS, NOT ONE THAT LISTS THEM
 *
 *   api/organizations.php answered delete_org (and save_org, assign_member, set_active_org...) with
 *   no CSRF check. The existing CSRF tests (tests/test_csrf_enforced.php names five endpoints,
 *   tests/test_security_csrf_bundle.php a handful more) can only vouch for the files somebody
 *   already knew about. Sweeping the whole of api/ with tools/csrf_coverage_audit.php found six
 *   more that had never had a check at all:
 *
 *     api/rbac.php              grant_role / revoke_grant / set_permissions / save_role / delete_role --
 *                               the whole Roles & Permissions editor. A page the administrator
 *                               visited could POST grant_role and make the attacker Super Admin.
 *     api/comm-identifiers.php  every member's callsigns/phones/radio ids and the comm modes
 *     api/training.php          its own source said "CSRF intentionally NOT added here" because the
 *                               caller did not send a token yet -- the cart before the horse
 *     api/facility-capacity.php update / save_category / delete_category
 *     api/owntracks-config.php  three GET actions (link, unit_link, push_pending) that MINT a
 *                               tracking token / consume the outbox
 *     api/inbound-calls.php     the heartbeat that holds a dispatcher's claim on a call open
 *
 *   PART 1  the tool over the real tree: nothing outside the reasoned baseline.
 *   PART 2  the tool over fixtures: every way of getting this wrong is caught, every correct shape
 *           this codebase uses is accepted. A gate shown only clean code has not been shown to catch
 *           anything -- and the first version of this one flagged 487 sites on the real tree, most
 *           of them false positives from shapes (a guard inside `if ($method === 'POST' || ...)`,
 *           a guard function called as a statement) that the fixtures now pin.
 *   PART 3  the fixed endpoints driven for real: no token, a wrong token, a token only in the
 *           header, a token only in the query -- and the data must not change when it is refused.
 *   PART 4  every browser caller of a fixed endpoint sends the token it now needs.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/_p155_helpers.php';
require_once __DIR__ . '/_test_admin.php';

$pass = 0; $fail = 0;
function cv_ok(string $what, bool $cond, string $why = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "[PASS] {$what}\n"; }
    else       { $fail++; echo "[FAIL] {$what}" . ($why !== '' ? " -- {$why}" : '') . "\n"; }
}

$root = dirname(__DIR__);
echo "=== API CSRF coverage ===\n\n";

/** Run the tool; returns [exit code, output]. */
function cv_run_tool(string $root, array $args = []): array {
    $r = p155_run_process(array_merge([$root . '/tools/csrf_coverage_audit.php'], $args), 120);
    return [$r['exit'], $r['stdout'] . $r['stderr']];
}

// ── PART 1: the real tree ──────────────────────────────────────────────────────
echo "-- Part 1: the real tree --\n";
[$code, $out] = cv_run_tool($root);
cv_ok('tools/csrf_coverage_audit.php finds no unguarded state change in api/', $code === 0, trim(substr($out, 0, 1800)));
preg_match('/(\d+) endpoint file\(s\); (\d+) change state \((\d+) session, (\d+) bearer, (\d+) unauthenticated\); (\d+) state-changing site\(s\) found, (\d+) covered/', $out, $m);
cv_ok('...and it really looked: >150 endpoints, >100 session-authenticated ones that change state, >600 sites covered (a parser that stops matching must not read as clean)',
      isset($m[1]) && (int) $m[1] > 150 && (int) $m[3] > 100 && (int) $m[7] > 600,
      $m ? ('files=' . $m[1] . ' session=' . $m[3] . ' covered=' . $m[7]) : trim(substr($out, 0, 300)));
cv_ok('...and no endpoint is both unauthenticated and state-changing', isset($m[5]) && (int) $m[5] === 0, 'unauthenticated=' . ($m[5] ?? '?'));

// ── PART 2: fixtures ───────────────────────────────────────────────────────────
echo "\n-- Part 2: every bad shape is caught, every good shape accepted --\n";
$fx = sys_get_temp_dir() . '/cv_fixtures_' . getmypid();
@mkdir($fx . '/api', 0777, true);
@mkdir($fx . '/inc', 0777, true);
register_shutdown_function(static function () use ($fx) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fx, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
    @rmdir($fx);
});
// The session gate every real endpoint requires, and one writer helper in inc/.
file_put_contents($fx . '/api/auth.php', "<?php\nsession_start();\nif (empty(\$_SESSION['user_id'])) { json_error('Not authenticated', 401); }\n");
file_put_contents($fx . '/inc/writers.php', "<?php\nfunction thing_save_internal(array \$d) { db_query(\"INSERT INTO thing (a) VALUES (?)\", [\$d['a']]); }\n");

$head = "<?php\nrequire_once __DIR__ . '/auth.php';\nrequire_once __DIR__ . '/../inc/writers.php';\n\$method = \$_SERVER['REQUEST_METHOD'];\n\$input = json_decode(file_get_contents('php://input'), true) ?: [];\n\$action = \$input['action'] ?? (\$_GET['action'] ?? '');\n\$tok = (string) (\$input['csrf_token'] ?? '');\n";

// name => PHP body. Every BAD one must produce a finding; every GOOD one none.
$bad = [
 'b01_no_csrf_at_all' =>
   "if (\$action === 'delete_org') { db_query(\"DELETE FROM organizations WHERE id = ?\", [1]); json_response(['ok' => true]); }\n",
 'b02_guard_in_another_branch' =>
   "if (\$action === 'save') {\n  if (!csrf_verify(\$tok)) { json_error('x', 403); }\n  db_query(\"INSERT INTO t (a) VALUES (1)\");\n}\nif (\$action === 'delete') {\n  db_query(\"DELETE FROM t WHERE id = 1\");\n}\n",
 'b03_post_checked_delete_not' =>
   "if (\$method === 'POST') {\n  if (!csrf_verify(\$tok)) { json_error('x', 403); }\n  db_query(\"INSERT INTO t (a) VALUES (1)\");\n}\nif (\$method === 'DELETE') {\n  db_query(\"DELETE FROM t WHERE id = 1\");\n}\n",
 'b04_result_ignored' =>
   "csrf_verify(\$tok);\ndb_query(\"INSERT INTO t (a) VALUES (1)\");\n",
 'b05_rejecting_branch_falls_through' =>
   "\$bad = false;\nif (!csrf_verify(\$tok)) { \$bad = true; }\ndb_query(\"INSERT INTO t (a) VALUES (1)\");\n",
 'b06_and_joined_so_omitting_skips' =>
   "if (\$tok !== '' && !csrf_verify(\$tok)) { json_error('x', 403); }\ndb_query(\"INSERT INTO t (a) VALUES (1)\");\n",
 'b07_guard_after_the_write' =>
   "db_query(\"INSERT INTO t (a) VALUES (1)\");\nif (!csrf_verify(\$tok)) { json_error('x', 403); }\n",
 'b08_helper_called_before_the_guard' =>
   "function doit() { db_query(\"DELETE FROM t WHERE id = 1\"); }\nif (\$action === 'a') { doit(); }\nif (\$action === 'b') { if (!csrf_verify(\$tok)) { json_error('x', 403); } doit(); }\n",
 'b09_get_that_writes' =>
   "if (\$method === 'POST') { if (!csrf_verify(\$tok)) { json_error('x', 403); } db_query(\"INSERT INTO t (a) VALUES (1)\"); }\nif (\$method === 'GET') { db_query(\"UPDATE t SET a = 1 WHERE id = ?\", [1]); }\n",
 'b10_writer_function_call' =>
   "thing_save_internal(['a' => 1]);\n",
 'b11_session_write' =>
   "\$_SESSION['active_org_id'] = (int) (\$input['org_id'] ?? 0);\n",
 'b13_file_operations' =>
   "unlink(\$input['path'] ?? '');\nmove_uploaded_file(\$_FILES['f']['tmp_name'], '/x');\n",
 'b14_guard_only_in_a_comment' =>
   "// if (!csrf_verify(\$tok)) { json_error('x', 403); }\n\$note = \"csrf_verify(\$tok) would go here\";\ndb_query(\"INSERT INTO t (a) VALUES (1)\");\n",
];
$good = [
 'g01_top_level_guard' =>
   "if (!csrf_verify(\$tok)) { json_error('Invalid CSRF token', 403); }\nif (\$action === 'a') { db_query(\"INSERT INTO t (a) VALUES (1)\"); }\nif (\$action === 'b') { db_query(\"DELETE FROM t WHERE id = 1\"); }\n",
 'g02_method_union_guard' =>
   "if (\$method === 'POST' || \$method === 'DELETE') {\n  if (!csrf_verify(\$tok)) { json_error('x', 403); }\n}\nif (\$method === 'GET') { \$rows = db_fetch_all(\"SELECT * FROM t\"); json_response(\$rows); }\nif (\$method === 'POST' && \$action === 'a') { db_query(\"INSERT INTO t (a) VALUES (1)\"); }\nif (\$method === 'DELETE') { db_query(\"DELETE FROM t WHERE id = 1\"); }\n",
 'g03_guard_inside_handler_function' =>
   "function handlePost() {\n  \$input = json_decode(file_get_contents('php://input'), true) ?: [];\n  if (empty(\$input['csrf_token']) || !csrf_verify(\$input['csrf_token'])) { json_error('x', 403); }\n  db_query(\"INSERT INTO t (a) VALUES (1)\");\n}\nif (\$method === 'POST') { handlePost(); }\n",
 'g04_csrf_require_helper' =>
   "csrf_require(\$input);\ndb_query(\"INSERT INTO t (a) VALUES (1)\");\n",
 'g05_local_guard_function' =>
   "function _require_csrf(array \$i) { if (!csrf_verify((string) (\$i['csrf_token'] ?? ''))) { json_error('x', 403); } }\nif (\$action === 'a') { _require_csrf(\$input); db_query(\"INSERT INTO t (a) VALUES (1)\"); }\nif (\$action === 'b') { _require_csrf(\$input); db_query(\"DELETE FROM t WHERE id = 1\"); }\n",
 'g06_positive_guard' =>
   "if (csrf_verify(\$tok)) { db_query(\"INSERT INTO t (a) VALUES (1)\"); } else { json_error('x', 403); }\n",
 'g07_header_or_body_token' =>
   "\$h = \$_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';\nif (!csrf_verify(\$h) && !csrf_verify(\$tok)) { json_error('x', 403); }\ndb_query(\"INSERT INTO t (a) VALUES (1)\");\n",
 'g08_early_method_exit' =>
   "if (\$method !== 'POST') { json_error('Method not allowed', 405); }\nif (!csrf_verify(\$tok)) { json_error('x', 403); }\ndb_query(\"INSERT INTO t (a) VALUES (1)\");\n",
 'g09_helper_only_called_after_the_guard' =>
   "function doit() { db_query(\"DELETE FROM t WHERE id = 1\"); }\nif (!csrf_verify(\$tok)) { json_error('x', 403); }\nif (\$action === 'a') { doit(); }\n",
 'g10_closure_in_a_guarded_branch' =>
   "if (\$action === 'a') {\n  if (!csrf_verify(\$tok)) { json_error('x', 403); }\n  \$mk = function (\$v) { db_query(\"INSERT INTO t (a) VALUES (?)\", [\$v]); };\n  \$mk(1);\n}\n",
 'g13_self_healing_function' =>
   "function ensure_seed() { db_query(\"INSERT IGNORE INTO t (a) VALUES (1)\"); }\nensure_seed();\nif (!csrf_verify(\$tok)) { json_error('x', 403); }\ndb_query(\"INSERT INTO t (a) VALUES (2)\");\n",
 'g14_bearer_gate_in_a_session_file' =>
   "function gate_auth() { \$h = \$_SERVER['HTTP_AUTHORIZATION'] ?? ''; return \$h === '' ? null : ['id' => 1]; }\n\$g = gate_auth();\nif (!\$g) { json_error('Bridge auth required', 401); }\ndb_query(\"INSERT INTO t (a) VALUES (1)\");\n",
 'g15_read_only' =>
   "\$rows = db_fetch_all(\"SELECT * FROM t WHERE id = ?\", [1]);\n\$msg = 'update failed';\njson_response(\$rows);\n",
 'g17_csrf_token_itself_is_not_a_write' =>
   "if (empty(\$_SESSION['csrf_token'])) { \$_SESSION['csrf_token'] = bin2hex(random_bytes(16)); }\njson_response(['t' => \$_SESSION['csrf_token']]);\n",
];
foreach ($bad as $name => $body) { file_put_contents("$fx/api/$name.php", $head . $body); }
foreach ($good as $name => $body) { file_put_contents("$fx/api/$name.php", $head . $body); }
// Shapes that cannot share the standard header.
file_put_contents("$fx/api/b12_unauthenticated_writer.php",
    "<?php\n\$input = json_decode(file_get_contents('php://input'), true) ?: [];\ndb_query(\"INSERT INTO t (a) VALUES (1)\");\n");
file_put_contents("$fx/api/g11_bearer_endpoint.php",
    "<?php\n\$h = \$_SERVER['HTTP_AUTHORIZATION'] ?? '';\nif (stripos(\$h, 'Bearer ') !== 0) { json_error('Bearer token required', 401); }\ndb_query(\"INSERT INTO t (a) VALUES (1)\");\n");
file_put_contents("$fx/api/g12_runs_before_the_session_loads.php",
    "<?php\nif (\$_SERVER['REQUEST_METHOD'] === 'POST' && isset(\$_GET['provider'])) {\n  db_query(\"INSERT INTO ingest (a) VALUES (1)\");\n  exit;\n}\nrequire_once __DIR__ . '/auth.php';\n\$input = json_decode(file_get_contents('php://input'), true) ?: [];\nif (!csrf_verify((string) (\$input['csrf_token'] ?? ''))) { json_error('x', 403); }\ndb_query(\"INSERT INTO t (a) VALUES (1)\");\n");

[$c, $o] = cv_run_tool($root, ['--path=' . $fx, '--no-baseline']);
$byFile = [];
foreach (explode("\n", $o) as $line) {
    if (preg_match('~^\s+(api/[A-Za-z0-9_]+\.php):(\d+)\s+(.*)$~', $line, $mm)) { $byFile[$mm[1]][] = $mm[2] . ' ' . $mm[3]; }
}
foreach (array_merge(array_keys($bad), ['b12_unauthenticated_writer']) as $name) {
    cv_ok("caught: {$name}", !empty($byFile["api/{$name}.php"]), 'no finding for it');
}
foreach (array_merge(array_keys($good), ['g11_bearer_endpoint', 'g12_runs_before_the_session_loads']) as $name) {
    cv_ok("accepted: {$name}", empty($byFile["api/{$name}.php"]), implode(' | ', $byFile["api/{$name}.php"] ?? []));
}
// The reasons, not just the presence of a finding.
cv_ok('b02 flags the UNGUARDED branch (the delete), not the guarded one',
      isset($byFile['api/b02_guard_in_another_branch.php']) && count($byFile['api/b02_guard_in_another_branch.php']) === 1
      && strpos($byFile['api/b02_guard_in_another_branch.php'][0], 'DELETE FROM') !== false, json_encode($byFile['api/b02_guard_in_another_branch.php'] ?? []));
cv_ok('b03 flags the DELETE branch the POST check never covered (the api/talkgroups.php shape)',
      isset($byFile['api/b03_post_checked_delete_not.php']) && count($byFile['api/b03_post_checked_delete_not.php']) === 1
      && strpos($byFile['api/b03_post_checked_delete_not.php'][0], 'DELETE FROM') !== false);
cv_ok('b06 says the && join is the problem', isset($byFile['api/b06_and_joined_so_omitting_skips.php'])
      && (bool) preg_grep('/joined to another condition with &&/', $byFile['api/b06_and_joined_so_omitting_skips.php']));
cv_ok('b05 says the rejecting branch does not terminate', isset($byFile['api/b05_rejecting_branch_falls_through.php'])
      && (bool) preg_grep('/does not terminate/', $byFile['api/b05_rejecting_branch_falls_through.php']));
cv_ok('b12 says it is neither session- nor bearer-authenticated', isset($byFile['api/b12_unauthenticated_writer.php'])
      && (bool) preg_grep('/neither session- nor bearer-authenticated/', $byFile['api/b12_unauthenticated_writer.php']));

// The baseline mechanism: reasoned, matched by line, and a stale or reason-less entry fails.
$bl = $fx . '/baseline.txt';
$bFile = 'api/b01_no_csrf_at_all.php';
$bLine = (int) (explode(' ', $byFile[$bFile][0] ?? '0')[0]);
file_put_contents($bl, "# fixture baseline\n{$bFile}:{$bLine}  deliberate: proves the baseline mechanism\n");
$isolated = sys_get_temp_dir() . '/cv_fx_one_' . getmypid();
@mkdir($isolated . '/api', 0777, true);
@mkdir($isolated . '/inc', 0777, true);
copy($fx . '/api/auth.php', $isolated . '/api/auth.php');
copy($fx . '/api/b01_no_csrf_at_all.php', $isolated . '/api/b01_no_csrf_at_all.php');
file_put_contents($isolated . '/inc/writers.php', file_get_contents($fx . '/inc/writers.php'));
[$c1, $o1] = cv_run_tool($root, ['--path=' . $isolated, '--baseline=' . $bl]);
cv_ok('a baselined finding (with its reason) is accepted', $c1 === 0, trim($o1));
file_put_contents($bl, "# fixture baseline\n{$bFile}:{$bLine}\n");
[$c2, $o2] = cv_run_tool($root, ['--path=' . $isolated, '--baseline=' . $bl]);
cv_ok('a baseline entry with NO reason fails the run', $c2 === 1 && strpos($o2, 'no reason given') !== false, trim($o2));
file_put_contents($bl, "# fixture baseline\napi/nothing.php:1  stale\n{$bFile}:{$bLine}  reason\n");
[$c3, $o3] = cv_run_tool($root, ['--path=' . $isolated, '--baseline=' . $bl]);
cv_ok('a baseline entry that matches nothing fails the run (the list cannot rot)', $c3 === 1 && strpos($o3, 'api/nothing.php:1') !== false, trim($o3));
foreach (['/api/auth.php', '/api/b01_no_csrf_at_all.php', '/inc/writers.php'] as $f) { @unlink($isolated . $f); }
@rmdir($isolated . '/api'); @rmdir($isolated . '/inc'); @rmdir($isolated);

// The real baseline: every entry reasoned (the tool enforces it; restate for the reader).
$baselineText = (string) file_get_contents($root . '/tools/csrf_coverage_baseline.txt');
$entries = 0; $reasonless = 0;
foreach (explode("\n", $baselineText) as $l) {
    $l = trim($l);
    if ($l === '' || $l[0] === '#') continue;
    $entries++;
    if (count(preg_split('/\s+/', $l, 2)) < 2 || strlen(preg_split('/\s+/', $l, 2)[1]) < 30) $reasonless++;
}
cv_ok('every entry in the real baseline carries a real reason (>= 30 characters)', $entries > 0 && $reasonless === 0, "entries={$entries} short={$reasonless}");

// ── PART 3: the fixed endpoints, driven for real ───────────────────────────────
echo "\n-- Part 3: the fixed endpoints, driven for real --\n";
$prefix = $GLOBALS['db_prefix'] ?? '';
try {
    $adminId = test_admin_user_id();
    db_fetch_value("SELECT COUNT(*) FROM `{$prefix}organizations`");
    db_fetch_value("SELECT COUNT(*) FROM `{$prefix}roles`");
} catch (Throwable $e) {
    echo "SKIP: database not reachable: " . $e->getMessage() . "\n";
    echo "\n=== {$pass} passed, {$fail} failed ===\n";
    exit($fail > 0 ? 1 : 0);
}

/** Drive an endpoint through tests/_gaps_csrf_probe.php. */
function cv_probe(string $api, int $uid, string $method, array $body, string $qs, string $mode): array {
    $r = p155_run_process([__DIR__ . '/_gaps_csrf_probe.php', $api, (string) $uid, $method, json_encode($body), $qs, $mode], 60);
    $lines = preg_split('/\r?\n/', trim($r['stdout']));
    $env = json_decode((string) end($lines), true);
    if (!is_array($env) || !isset($env['http'])) {
        return ['http' => 0, 'json' => null, 'raw' => 'probe output unparseable: ' . substr($r['stdout'] . ' | ' . $r['stderr'], 0, 400)];
    }
    $j = json_decode((string) $env['body'], true);
    return ['http' => (int) $env['http'], 'json' => is_array($j) ? $j : null, 'raw' => (string) $env['body']];
}
function cv_refused(array $r): bool {
    return $r['http'] === 403 && stripos((string) ($r['json']['error'] ?? ''), 'csrf') !== false
        || ($r['http'] === 403 && stripos((string) ($r['json']['error'] ?? ''), 'security token') !== false);
}
function cv_not_refused(array $r): bool { return !cv_refused($r) && $r['http'] !== 0; }

$orgName = 'P155 CSRF fixture org';
$roleName = 'P155 CSRF fixture role';
$modeCode = 'p155csrf';
$catName = 'P155 CSRF fixture category';
test_fixture_guard_track_where('organizations', 'name = ?', [$orgName]);
test_fixture_guard_track_where('roles', 'name = ?', [$roleName]);
test_fixture_guard_track_where('comm_modes', 'code = ?', [$modeCode]);
test_fixture_guard_track_where('capacity_categories', 'name = ?', [$catName]);
test_fixture_guard_track_where('newui_audit_log', "user_name = 'p155-csrf-probe'");
foreach ([['organizations', 'name', $orgName], ['roles', 'name', $roleName], ['comm_modes', 'code', $modeCode], ['capacity_categories', 'name', $catName]] as [$t, $col, $v]) {
    db_query("DELETE FROM `{$prefix}{$t}` WHERE `{$col}` = ?", [$v]);
}
$count = static function (string $t, string $col, string $v) use ($prefix): int {
    return (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}{$t}` WHERE `{$col}` = ?", [$v]);
};

// 3a. api/organizations.php -- delete_org was one POST away from any page the admin visited.
$r = cv_probe('api/organizations.php', $adminId, 'POST', ['action' => 'save_org', 'name' => $orgName], '', 'none');
cv_ok('organizations: save_org with NO token is refused', cv_refused($r), $r['http'] . ' ' . $r['raw']);
cv_ok('organizations: ...and nothing was created', $count('organizations', 'name', $orgName) === 0);
$r = cv_probe('api/organizations.php', $adminId, 'POST', ['action' => 'save_org', 'name' => $orgName], '', 'body');
cv_ok('organizations: save_org with the real token works', $r['http'] === 200 && !empty($r['json']['success']), $r['http'] . ' ' . $r['raw']);
$orgId = (int) db_fetch_value("SELECT id FROM `{$prefix}organizations` WHERE name = ?", [$orgName]);
cv_ok('organizations: ...and created the organization', $orgId > 0);
foreach (['none', 'bad'] as $mode) {
    $r = cv_probe('api/organizations.php', $adminId, 'POST', ['action' => 'delete_org', 'id' => $orgId], '', $mode);
    cv_ok("organizations: delete_org with token mode '{$mode}' is refused", cv_refused($r), $r['http'] . ' ' . $r['raw']);
    cv_ok("organizations: ...and the organization is still there ('{$mode}')", $count('organizations', 'name', $orgName) === 1);
}
$r = cv_probe('api/organizations.php', $adminId, 'POST', ['action' => 'assign_member', 'member_id' => 1, 'org_id' => $orgId], '', 'bad');
cv_ok('organizations: assign_member with a wrong token is refused', cv_refused($r), $r['http'] . ' ' . $r['raw']);
$r = cv_probe('api/organizations.php', $adminId, 'POST', ['action' => 'set_active_org', 'org_id' => $orgId], '', 'none');
cv_ok('organizations: set_active_org with NO token is refused (it rewrites the session)', cv_refused($r), $r['http'] . ' ' . $r['raw']);
$r = cv_probe('api/organizations.php', $adminId, 'POST', ['action' => 'set_active_org', 'org_id' => $orgId], '', 'body');
cv_ok('organizations: ...with the token it gets as far as the membership check (a DIFFERENT 403)',
      $r['http'] === 403 && !cv_refused($r) && stripos((string) ($r['json']['error'] ?? ''), 'not a member') !== false, $r['http'] . ' ' . $r['raw']);
$r = cv_probe('api/organizations.php', $adminId, 'POST', ['action' => 'delete_org', 'id' => $orgId], '', 'body');
cv_ok('organizations: delete_org with the real token deletes', $r['http'] === 200 && $count('organizations', 'name', $orgName) === 0, $r['http'] . ' ' . $r['raw']);

// 3b. api/rbac.php -- the Roles & Permissions editor, including grant_role.
$r = cv_probe('api/rbac.php', $adminId, 'POST', ['action' => 'save_role', 'name' => $roleName], '', 'none');
cv_ok('rbac: save_role with NO token is refused', cv_refused($r), $r['http'] . ' ' . $r['raw']);
cv_ok('rbac: ...and no role was created', $count('roles', 'name', $roleName) === 0);
$r = cv_probe('api/rbac.php', $adminId, 'POST', ['action' => 'save_role', 'name' => $roleName], '', 'header');
cv_ok('rbac: save_role with the token ONLY in X-CSRF-Token (what roles.js sends) works', $r['http'] === 200 && !empty($r['json']['success']), $r['http'] . ' ' . $r['raw']);
$roleId = (int) db_fetch_value("SELECT id FROM `{$prefix}roles` WHERE name = ?", [$roleName]);
cv_ok('rbac: ...and created the role', $roleId > 0);
$grantsBefore = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}user_roles` WHERE role_id = ?", [$roleId]);
$r = cv_probe('api/rbac.php', $adminId, 'POST',
    ['action' => 'grant_role', 'user_id' => $adminId, 'role_id' => $roleId, 'scope_kind' => 'global'], '', 'bad');
cv_ok('rbac: grant_role with a WRONG token is refused (the privilege-escalation POST)', cv_refused($r), $r['http'] . ' ' . $r['raw']);
cv_ok('rbac: ...and no grant row appeared',
      (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}user_roles` WHERE role_id = ?", [$roleId]) === $grantsBefore);
$r = cv_probe('api/rbac.php', $adminId, 'POST', ['action' => 'set_permissions', 'role_id' => $roleId, 'permission_ids' => [1]], '', 'none');
cv_ok('rbac: set_permissions with NO token is refused', cv_refused($r), $r['http'] . ' ' . $r['raw']);
cv_ok('rbac: ...and the role gained no permission',
      (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}role_permissions` WHERE role_id = ?", [$roleId]) === 0);
$r = cv_probe('api/rbac.php', $adminId, 'POST', ['action' => 'delete_role', 'id' => $roleId], '', 'none');
cv_ok('rbac: delete_role with NO token is refused', cv_refused($r) && $count('roles', 'name', $roleName) === 1, $r['http'] . ' ' . $r['raw']);
$r = cv_probe('api/rbac.php', $adminId, 'POST', ['action' => 'delete_role', 'id' => $roleId], '', 'body');
cv_ok('rbac: delete_role with the real token deletes', $r['http'] === 200 && $count('roles', 'name', $roleName) === 0, $r['http'] . ' ' . $r['raw']);

// 3c. api/comm-identifiers.php
$mode = ['action' => 'save_mode', 'code' => $modeCode, 'name' => 'P155 CSRF mode', 'fields_json' => []];
$r = cv_probe('api/comm-identifiers.php', $adminId, 'POST', $mode, '', 'none');
cv_ok('comm-identifiers: save_mode with NO token is refused', cv_refused($r) && $count('comm_modes', 'code', $modeCode) === 0, $r['http'] . ' ' . $r['raw']);
$r = cv_probe('api/comm-identifiers.php', $adminId, 'POST', $mode, '', 'body');
cv_ok('comm-identifiers: save_mode with the real token works', $r['http'] === 200 && $count('comm_modes', 'code', $modeCode) === 1, $r['http'] . ' ' . $r['raw']);
$modeId = (int) db_fetch_value("SELECT id FROM `{$prefix}comm_modes` WHERE code = ?", [$modeCode]);
$r = cv_probe('api/comm-identifiers.php', $adminId, 'POST', ['action' => 'delete_mode', 'id' => $modeId], '', 'bad');
cv_ok('comm-identifiers: delete_mode with a WRONG token is refused and the mode survives', cv_refused($r) && $count('comm_modes', 'code', $modeCode) === 1, $r['http'] . ' ' . $r['raw']);
$r = cv_probe('api/comm-identifiers.php', $adminId, 'POST', ['action' => 'save_identifier', 'member_id' => 1, 'comm_mode_id' => $modeId, 'value' => 'x'], '', 'none');
cv_ok('comm-identifiers: save_identifier with NO token is refused', cv_refused($r), $r['http'] . ' ' . $r['raw']);
$r = cv_probe('api/comm-identifiers.php', $adminId, 'POST', ['action' => 'delete_mode', 'id' => $modeId], '', 'body');
cv_ok('comm-identifiers: delete_mode with the real token deletes', $r['http'] === 200 && $count('comm_modes', 'code', $modeCode) === 0, $r['http'] . ' ' . $r['raw']);

// 3d. api/training.php
foreach (['none', 'bad'] as $m2) {
    $r = cv_probe('api/training.php', $adminId, 'POST', ['action' => 'delete', 'id' => 987654321], '', $m2);
    cv_ok("training: delete with token mode '{$m2}' is refused", cv_refused($r), $r['http'] . ' ' . $r['raw']);
}
$r = cv_probe('api/training.php', $adminId, 'POST', ['action' => 'delete', 'id' => 987654321], '', 'body');
cv_ok('training: with the real token it is accepted (nothing to delete, but not refused)', cv_not_refused($r) && $r['http'] === 200, $r['http'] . ' ' . $r['raw']);

// 3e. api/facility-capacity.php
$cat = ['action' => 'save_category', 'name' => $catName];
$r = cv_probe('api/facility-capacity.php', $adminId, 'POST', $cat, '', 'none');
cv_ok('facility-capacity: save_category with NO token is refused and creates nothing', cv_refused($r) && $count('capacity_categories', 'name', $catName) === 0, $r['http'] . ' ' . $r['raw']);
$r = cv_probe('api/facility-capacity.php', $adminId, 'POST', $cat, '', 'body');
cv_ok('facility-capacity: save_category with the real token works', $r['http'] === 200 && $count('capacity_categories', 'name', $catName) === 1, $r['http'] . ' ' . $r['raw']);
$catId = (int) db_fetch_value("SELECT id FROM `{$prefix}capacity_categories` WHERE name = ?", [$catName]);
$r = cv_probe('api/facility-capacity.php', $adminId, 'POST', ['action' => 'delete_category', 'id' => $catId], '', 'bad');
cv_ok('facility-capacity: delete_category with a WRONG token is refused and the category survives', cv_refused($r) && $count('capacity_categories', 'name', $catName) === 1, $r['http'] . ' ' . $r['raw']);
$r = cv_probe('api/facility-capacity.php', $adminId, 'POST', ['action' => 'update', 'facility_id' => 1, 'category_id' => $catId, 'total' => 5, 'available' => 5], '', 'none');
cv_ok('facility-capacity: update with NO token is refused', cv_refused($r), $r['http'] . ' ' . $r['raw']);
$r = cv_probe('api/facility-capacity.php', $adminId, 'POST', ['action' => 'delete_category', 'id' => $catId], '', 'body');
cv_ok('facility-capacity: delete_category with the real token deletes', $r['http'] === 200 && $count('capacity_categories', 'name', $catName) === 0, $r['http'] . ' ' . $r['raw']);

// 3f. api/owntracks-config.php -- three GET actions that change state; the token rides in the query.
$tokensBefore = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}member_tracking_tokens`");
foreach ([['link', 'member_id=0&mode=url'], ['unit_link', 'responder_id=0&mode=url'], ['push_pending', 'member_id=0']] as [$act, $q]) {
    foreach (['none', 'bad', 'header'] as $m3) {
        $r = cv_probe('api/owntracks-config.php', $adminId, 'GET', [], "action={$act}&{$q}", $m3);
        // A header token is NOT accepted for these (they are navigations that cannot set one)... but
        // csrf_request_token() does accept the header, so header mode is allowed to pass the gate.
        if ($m3 === 'header') {
            cv_ok("owntracks-config: {$act} with the token in the header gets past the gate", cv_not_refused($r), $r['http'] . ' ' . $r['raw']);
        } else {
            cv_ok("owntracks-config: {$act} with token mode '{$m3}' is refused", cv_refused($r), $r['http'] . ' ' . $r['raw']);
        }
    }
    $r = cv_probe('api/owntracks-config.php', $adminId, 'GET', [], "action={$act}&{$q}", 'query');
    cv_ok("owntracks-config: {$act} with ?csrf_token= gets as far as validating its own arguments", cv_not_refused($r) && $r['http'] === 400, $r['http'] . ' ' . $r['raw']);
}
cv_ok('owntracks-config: no tracking token was minted by any refused or argument-less request',
      (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}member_tracking_tokens`") === $tokensBefore);

// 3g. api/inbound-calls.php -- the heartbeat that holds a claim open.
$r = cv_probe('api/inbound-calls.php', $adminId, 'POST', ['id' => 1], 'action=heartbeat', 'none');
cv_ok('inbound-calls: heartbeat with NO token is refused', cv_refused($r), $r['http'] . ' ' . $r['raw']);
$r = cv_probe('api/inbound-calls.php', $adminId, 'POST', ['id' => 1], 'action=heartbeat', 'body');
cv_ok('inbound-calls: heartbeat with the real token is accepted', cv_not_refused($r) && $r['http'] === 200, $r['http'] . ' ' . $r['raw']);

// ── PART 4: every browser caller sends the token it now needs ───────────────────
echo "\n-- Part 4: every browser caller of a fixed endpoint sends its token --\n";
/** The text of the fetch(...) call that begins at $pos (balanced parentheses, string-aware). */
function cv_call_text(string $js, int $pos): string {
    $depth = 0; $q = ''; $n = strlen($js);
    for ($i = $pos; $i < $n; $i++) {
        $ch = $js[$i];
        if ($q !== '') { if ($ch === '\\') { $i++; } elseif ($ch === $q) { $q = ''; } continue; }
        if ($ch === "'" || $ch === '"') { $q = $ch; continue; }
        if ($ch === '(') { $depth++; }
        elseif ($ch === ')') { $depth--; if ($depth === 0) { return substr($js, $pos, $i - $pos + 1); } }
    }
    return substr($js, $pos, 600);
}
$jsFiles = glob($root . '/assets/js/*.js') ?: [];
$endpoints = ['api/organizations.php', 'api/rbac.php', 'api/comm-identifiers.php', 'api/training.php', 'api/facility-capacity.php'];
$posts = 0; $missing = [];
foreach ($jsFiles as $jf) {
    $js = (string) file_get_contents($jf);
    foreach ($endpoints as $ep) {
        $off = 0;
        while (($p = strpos($js, "fetch('" . $ep, $off)) !== false) {
            $off = $p + 5;
            $call = cv_call_text($js, $p);
            if (!preg_match("/method:\s*'(POST|PUT|DELETE)'/", $call)) { continue; }   // a GET read
            $posts++;
            $window = substr($js, max(0, $p - 1500), 1500) . $call;
            if (stripos($window, 'csrf') === false) {
                $missing[] = basename($jf) . ':' . (substr_count(substr($js, 0, $p), "\n") + 1) . " -> {$ep}";
            }
        }
    }
}
cv_ok("every fetch() that POSTs to a fixed endpoint has a CSRF token in or just above the call ({$posts} found)", $posts >= 15 && !$missing, implode('; ', $missing));
// The GET navigations that now need ?csrf_token=.
$otMissing = [];
foreach ($jsFiles as $jf) {
    $js = (string) file_get_contents($jf);
    // The whole action name: "action=link" must not match "action=link_ticket" (inbound calls).
    foreach (['action=link', 'action=unit_link'] as $needle) {
        if (!preg_match_all('/' . preg_quote($needle, '/') . '(?![A-Za-z0-9_])/', $js, $hits, PREG_OFFSET_CAPTURE)) { continue; }
        foreach ($hits[0] as [$text, $p]) {
            $stmt = preg_split('/;\s*\n/', substr($js, $p, 320))[0];
            if (strpos($stmt, 'csrf_token=') === false) { $otMissing[] = basename($jf) . ':' . (substr_count(substr($js, 0, $p), "\n") + 1) . " ({$needle})"; }
        }
    }
}
cv_ok('every browser URL for owntracks-config link/unit_link carries &csrf_token=', !$otMissing, implode('; ', $otMissing));
// api/app.js's organization switcher is the one POST that has no form around it.
$app = (string) file_get_contents($root . '/assets/js/app.js');
cv_ok('the organization switcher (app.js) sends csrf_token with set_active_org', (bool) preg_match("/action: 'set_active_org'[^}]*csrf_token/", $app));
$roster = (string) file_get_contents($root . '/assets/js/roster.js');
cv_ok('roster.js sends csrf_token with update_member_org, training add and training delete',
      (bool) preg_match("/action: 'update_member_org'[\s\S]{0,500}csrf_token/", $roster)
      && (bool) preg_match("/result: resultEl[^\n]*\n\s*csrf_token/", $roster)
      && (bool) preg_match("/action: 'delete', id: id, csrf_token/", $roster));

// ── Cleanup ────────────────────────────────────────────────────────────────────
foreach ([['organizations', 'name', $orgName], ['roles', 'name', $roleName], ['comm_modes', 'code', $modeCode], ['capacity_categories', 'name', $catName]] as [$t, $col, $v]) {
    db_query("DELETE FROM `{$prefix}{$t}` WHERE `{$col}` = ?", [$v]);
}
db_query("DELETE FROM `{$prefix}newui_audit_log` WHERE user_name = 'p155-csrf-probe'");

echo "\n=== {$pass} passed, {$fail} failed ===\n";
exit($fail > 0 ? 1 : 0);
