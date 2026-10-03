<?php
/**
 * Phase 155 — tools/publish-sync.sh, the one-command, traceable publish.
 *
 * WHAT IS BEING PROVEN
 *
 *   The wrapper turns the manual publish checklist into one command AND leaves
 *   the record sync-lag-check needs: every public commit it makes carries
 *   `Dev-Commit: <full sha>` and `Fixes-Included: GH#..`. So the loop closes:
 *   publish, then the same gate that said "not published" says "published".
 *
 * HOW
 *
 *   The REAL tools/publish-sync.sh, tools/release-snapshot.sh and
 *   tools/sync-lag-check.php run inside a fixture dev repository against a
 *   fixture public repository whose origin is a real bare repository, so a push
 *   really lands (or, correctly, does not). Only two neighbours are stubbed, the
 *   SBOM generator and the divergence checker (both have their own suites; this
 *   file is about the wrapper). Needs bash, git, php, tar and python; skips
 *   cleanly without them.
 *
 * Never-events asserted: nothing is pushed without --push; nothing is tagged
 * without --release; a changelog line that matches the infra/PII scan is
 * refused; a dirty or off-main public clone is refused.
 */

declare(strict_types=1);

require_once __DIR__ . '/_community_test_lib.php';

$root = dirname(__DIR__);
foreach (['tools/publish-sync.sh', 'tools/release-snapshot.sh', 'tools/sync-lag-check.php', 'tools/lib/community.php'] as $need) {
    if (!is_file($root . '/' . $need)) ct_skip("{$need} not present (publish-sync is development-tree only)");
}
if (!ct_have('git')) ct_skip('git is not on PATH');
$bash = ct_bash();
if ($bash === null) ct_skip('no bash');

// The scripts call php/tar/python by name; hand the child a PATH that has them.
$phpDir = str_replace('\\', '/', dirname(PHP_BINARY));
$childEnv = ct_env();
$pathKey = 'PATH';
foreach (array_keys($childEnv) as $k) { if (strcasecmp((string) $k, 'PATH') === 0) { $pathKey = (string) $k; break; } }
$childEnv[$pathKey] = $phpDir . PATH_SEPARATOR . ($childEnv[$pathKey] ?? '');
$probe = cm_run([$bash, '-c', 'command -v php && command -v tar && command -v python'], null, null, $childEnv, 30);
if ($probe['code'] !== 0) ct_skip('needs php, tar and python on PATH inside bash (release-snapshot.sh uses all three)');

echo "=== publish-sync.sh ===\n\n";

$tmp = ct_tmp();

/** A dev repository containing the REAL scripts and stubs for the SBOM/divergence neighbours. */
function ps_make_dev(string $dir, string $root): array
{
    ct_repo_init($dir);
    $copy = static function (string $rel) use ($dir, $root): void {
        $dst = $dir . '/' . $rel;
        if (!is_dir(dirname($dst))) mkdir(dirname($dst), 0777, true);
        copy($root . '/' . $rel, $dst);
    };
    foreach (['tools/release-snapshot.sh', 'tools/publish-sync.sh', 'tools/sync-lag-check.php', 'tools/lib/community.php'] as $f) $copy($f);
    $b0 = ct_commit($dir, [
        'app.php' => "<?php // base\n", 'README.md' => "# Fixture\n", 'VERSION' => "9.9.8\n",
        'CHANGELOG.md' => "# Changelog\n\n## [Unreleased]\n\n## [9.9.8] — 2026-08-01\n\n- stub that must never reach the public repo\n",
        'specs/notes.md' => "private\n",
        'tools/deploy.sh' => "echo deploy\n",
        'tools/community-watch.php' => "<?php // dev only\n",
        '.github/workflows/community-watch.yml' => "name: community-watch\n",
        '.github/workflows/triage.yml' => "name: triage\n",
        'tools/triage-issue.php' => "<?php // ships\n",
        // Neighbours stubbed: each has its own suite.
        'tools/generate-sbom.php' => "<?php\nexit(0);\n",
        'tools/release-divergence-check.php' => "<?php\nexit((int) getenv('STUB_EXIT'));\n",
    ], 'Base', '2026-09-01T10:00:00+00:00');
    return [$b0];
}

/** A public clone with a real bare origin, its first commit carrying a trailer. */
function ps_make_public(string $tmp, string $name, string $baseDevSha): array
{
    $origin = "{$tmp}/{$name}-origin.git";
    $pub = "{$tmp}/{$name}-public";
    cm_run(['git', 'init', '-q', '--bare', '-b', 'main', $origin], null, null, ct_env(), 60);
    cm_run(['git', 'clone', '-q', $origin, $pub], null, null, ct_env(), 60);
    foreach ([['user.email', 'fixture@example.invalid'], ['user.name', 'fixture'], ['commit.gpgsign', 'false'], ['core.autocrlf', 'false']] as [$k, $v]) {
        ct_git($pub, ['config', $k, $v]);
    }
    $first = ct_commit($pub, [
        'app.php' => "<?php // base\n", 'README.md' => "# Fixture\n",
        'CHANGELOG.md' => "# Changelog\n\n## [Unreleased]\n\n## [9.9.8] — 2026-08-01\n\n- a public-only entry that must survive every sync\n",
    ], "TicketsCAD sync " . substr($baseDevSha, 0, 7) . "\n\nDev-Commit: {$baseDevSha}\n", '2026-09-01T11:00:00+00:00');
    ct_git($pub, ['push', '-q', '-u', 'origin', 'main']);
    return [$pub, $origin, $first];
}

// Safety net: unless a case supplies its own, `gh` is a stand-in that prints
// nothing, and a CI wait gives up after 3 seconds. A wrapper that (wrongly) waited
// on a real `gh` for a local-path "repository" would otherwise spin for 25 minutes.
$noGh = "{$tmp}/no-gh.sh";
file_put_contents($noGh, "#!/usr/bin/env bash
exit 0
");
chmod($noGh, 0755);
$run = static function (string $dev, string $pub, string $stage, array $args, array $env = []) use ($bash, $childEnv, $noGh): array {
    $e = $childEnv;
    $e['GH_BIN'] = str_replace('\\', '/', $noGh);
    $e['PUBLISH_SYNC_CI_POLL'] = '1';
    foreach ($env as $k => $v) $e[$k] = $v;
    $e['STUB_EXIT'] = $e['STUB_EXIT'] ?? '0';
    $cmd = array_merge([$bash, 'tools/publish-sync.sh', '--public-repo=' . $pub, '--stage=' . $stage, '--ci-timeout=3'], $args);
    return cm_run($cmd, $dev, null, $e, 300);
};
$headOf = static function (string $repo): string { return trim(ct_git($repo, ['rev-parse', 'HEAD'])['out']); };
$originMain = static function (string $origin): string {
    return trim(cm_run(['git', '--git-dir', $origin, 'rev-parse', 'main'], null, null, ct_env(), 30)['out']);
};
$msgOf = static function (string $repo): string { return ct_git($repo, ['log', '-1', '--format=%B'])['out']; };
$treeFiles = static function (string $repo): array {
    return array_values(array_filter(explode("\n", trim(ct_git($repo, ['ls-tree', '-r', '--name-only', 'HEAD'])['out']))));
};

$dev = "{$tmp}/dev";
[$b0] = ps_make_dev($dev, $root);
[$pub, $origin, $pubFirst] = ps_make_public($tmp, 'a', $b0);
$stage = "{$tmp}/stage";
$f1 = ct_commit($dev, ['app.php' => "<?php // fixed\n"], 'Fix SOP table overflow (GH#143)', '2026-09-11T00:00:00+00:00');
ct_commit($dev, ['specs/plan.md' => "private plan\n"], 'Plan notes for GH#144', '2026-09-11T01:00:00+00:00');

// ─────────────────────────────────────────────────────────────────────
echo "-- 1. A sync: commit, trailers, changelog; NOT pushed --\n";

$r = $run($dev, $pub, $stage, []);
ct('the wrapper exits 0', $r['code'] === 0, 'exit ' . $r['code'] . "\n" . substr($r['out'] . $r['err'], -900));
$devHead = $headOf($dev);
$msg = $msgOf($pub);
ct('the public commit names the EXACT dev commit it was built from (full sha)', strpos($msg, "Dev-Commit: {$devHead}\n") !== false, $msg);
ct('...and the issues it publishes (and not an issue only a specs-only commit mentioned)', strpos($msg, "Fixes-Included: GH#143\n") !== false && strpos($msg, 'GH#144') === false, $msg);
ct('...with a DCO sign-off', preg_match('/^Signed-off-by: fixture <fixture@example\.invalid>$/m', $msg) === 1, $msg);
ct('the subject is short and readable', preg_match('/^TicketsCAD sync [0-9a-f]{7}: GH#143$/m', $msg) === 1, $msg);
ct('NOTHING was pushed without --push (origin still at the first commit)', $originMain($origin) === $pubFirst);
ct('...and the output says it was not pushed', stripos($r['out'], 'NOT pushed') !== false);
ct('NO tag was created without --release', trim(ct_git($pub, ['tag', '-l'])['out']) === '');
$files = $treeFiles($pub);
ct('the public tree has the new code', trim((string) @file_get_contents($pub . '/app.php')) === '<?php // fixed');
ct('private material is absent from the public tree (specs/, deploy script, the dev-only tools and workflow)',
    !in_array('specs/notes.md', $files, true) && !in_array('tools/deploy.sh', $files, true) && !in_array('tools/sync-lag-check.php', $files, true)
    && !in_array('tools/publish-sync.sh', $files, true) && !in_array('tools/community-watch.php', $files, true)
    && !in_array('.github/workflows/community-watch.yml', $files, true), implode(', ', $files));
ct('...while the PUBLIC pieces ship (the triage workflow, its tool, the shared library)',
    in_array('.github/workflows/triage.yml', $files, true) && in_array('tools/triage-issue.php', $files, true) && in_array('tools/lib/community.php', $files, true), implode(', ', $files));
$cl = (string) file_get_contents($pub . '/CHANGELOG.md');
ct('the public CHANGELOG stayed public-authoritative (the dev stub never replaced it)', strpos($cl, 'a public-only entry that must survive every sync') !== false && strpos($cl, 'stub that must never reach') === false, $cl);
ct('and the fix is listed under [Unreleased] > Fixed', strpos($cl, "## [Unreleased]\n\n### Fixed\n\n- Fix SOP table overflow (GH#143).\n") !== false, $cl);

// ─────────────────────────────────────────────────────────────────────
echo "\n-- 2. Review, then push the commit already waiting --\n";

$syncSha = $headOf($pub);
$r = $run($dev, $pub, $stage, ['--push', '--no-wait-ci']);
ct('a second run with --push pushes the commit that was waiting (it does not make an empty one)', $r['code'] === 0 && $headOf($pub) === $syncSha && $originMain($origin) === $syncSha,
    'exit ' . $r['code'] . ' head=' . substr($headOf($pub), 0, 7) . ' origin=' . substr($originMain($origin), 0, 7) . "\n" . substr($r['out'] . $r['err'], -500));
$gate = static function (string $issue) use ($dev, $pub): array {
    return ct_php($dev . '/tools/sync-lag-check.php', ['--dev-repo=' . $dev, '--dev-ref=main', '--public-repo=' . $pub, '--public-ref=main', '--no-fetch',
        '--snapshot-script=' . $dev . '/tools/release-snapshot.sh', '--issue=' . $issue], ct_env());
};
ct('THE LOOP CLOSES: the gate that said "not published" now says published for GH#143', $gate('143')['code'] === 0);

// ─────────────────────────────────────────────────────────────────────
echo "\n-- 3. The next sync's baseline is the previous trailer --\n";

$f2 = ct_commit($dev, ['api/second.php' => "<?php // second\n"], 'Second fix (GH#150)', '2026-09-12T00:00:00+00:00');
ct('before publishing it, GH#150 is NOT published', $gate('150')['code'] === 1);
$r = $run($dev, $pub, $stage, ['--push', '--no-wait-ci']);
$msg2 = $msgOf($pub);
ct('the second sync succeeds and pushes', $r['code'] === 0 && $originMain($origin) === $headOf($pub), 'exit ' . $r['code'] . substr($r['out'] . $r['err'], -400));
ct('its trailers list ONLY what is new since the previous trailer (not GH#143 again)',
    strpos($msg2, "Fixes-Included: GH#150\n") !== false && strpos($msg2, 'GH#143') === false, $msg2);
ct('its Dev-Commit is the new dev head', strpos($msg2, 'Dev-Commit: ' . $headOf($dev) . "\n") !== false);
$cl = (string) file_get_contents($pub . '/CHANGELOG.md');
ct('the changelog has BOTH bullets, each once, newest on top',
    substr_count($cl, 'GH#143') === 1 && substr_count($cl, 'GH#150') === 1 && strpos($cl, 'GH#150') < strpos($cl, 'GH#143'), $cl);
ct('and now GH#150 is published', $gate('150')['code'] === 0);
$before = $headOf($pub);
$r = $run($dev, $pub, $stage, ['--push', '--no-wait-ci']);
ct('with nothing new, a run commits NOTHING and says so (no empty sync commits)', $r['code'] === 0 && $headOf($pub) === $before && stripos($r['out'], 'Nothing to publish') !== false, substr($r['out'], -300));

// ─────────────────────────────────────────────────────────────────────
echo "\n-- 4. Releases: only on request, only when VERSION agrees --\n";

ct_commit($dev, ['api/third.php' => "<?php // third\n"], 'Third fix (GH#160)', '2026-09-13T00:00:00+00:00');
$before = $headOf($pub);
$r = $run($dev, $pub, $stage, ['--release=v9.9.9']);
ct('--release with a VERSION file that disagrees is refused, and nothing is committed', $r['code'] === 1 && $headOf($pub) === $before && stripos($r['err'], 'VERSION says') !== false,
    'exit ' . $r['code'] . ' ' . trim($r['err']));
ct('a malformed --release is exit 2', $run($dev, $pub, $stage, ['--release=4.2'])['code'] === 2);
ct_commit($dev, ['VERSION' => "9.9.9\n"], 'Bump VERSION to 9.9.9', '2026-09-13T01:00:00+00:00');
$r = $run($dev, $pub, $stage, ['--release=v9.9.9']);
ct('with VERSION bumped, --release makes the commit', $r['code'] === 0 && $headOf($pub) !== $before, 'exit ' . $r['code'] . substr($r['out'] . $r['err'], -400));
ct('...and an annotated tag on it', trim(ct_git($pub, ['tag', '-l', 'v9.9.9'])['out']) === 'v9.9.9' && trim(ct_git($pub, ['cat-file', '-t', 'v9.9.9'])['out']) === 'tag');
$cl = (string) file_get_contents($pub . '/CHANGELOG.md');
ct('the changelog is dated and an empty [Unreleased] stands above it',
    preg_match('/## \[Unreleased\]\n\n## \[9\.9\.9\] — \d{4}-\d{2}-\d{2}\n\n### Fixed\n\n- Third fix \(GH#160\)\./', $cl) === 1, $cl);
ct('the tag is NOT in origin yet (no --push)', trim(cm_run(['git', '--git-dir', $origin, 'tag', '-l'], null, null, ct_env(), 30)['out']) === '');
$r = $run($dev, $pub, $stage, ['--push', '--no-wait-ci', '--release=v9.9.9']);
ct('--push --release then pushes the commit AND the tag', $r['code'] === 0 && trim(cm_run(['git', '--git-dir', $origin, 'tag', '-l'], null, null, ct_env(), 30)['out']) === 'v9.9.9'
    && $originMain($origin) === $headOf($pub), 'exit ' . $r['code'] . substr($r['out'] . $r['err'], -400));
ct('a tag that already exists is refused up front', $run($dev, $pub, $stage, ['--release=v9.9.9'])['code'] === 1);

// ─────────────────────────────────────────────────────────────────────
echo "\n-- 5. Refusals --\n";

ct_commit($dev, ['api/fourth.php' => "<?php // fourth\n"], 'Fourth fix (GH#170)', '2026-09-14T00:00:00+00:00');
$before = $headOf($pub);
file_put_contents($pub . '/stray.txt', "uncommitted\n");
$r = $run($dev, $pub, $stage, []);
ct('a public clone with uncommitted changes is refused (its tree is about to be replaced)', $r['code'] === 1 && $headOf($pub) === $before && stripos($r['err'], 'uncommitted') !== false, trim($r['err']));
unlink($pub . '/stray.txt');
ct_git($pub, ['checkout', '-q', '-b', 'other']);
$r = $run($dev, $pub, $stage, []);
ct('a public clone not on main is refused', $r['code'] === 1 && stripos($r['err'], 'not main') !== false, trim($r['err']));
ct_git($pub, ['checkout', '-q', 'main']);
ct('a public path that is not a repository is refused', $run($dev, $tmp . '/nowhere', $stage, [])['code'] === 1);
ct('an unknown option is exit 2', $run($dev, $pub, $stage, ['--frobnicate'])['code'] === 2);
ct('a bad --ci-timeout is exit 2', $run($dev, $pub, $stage, ['--ci-timeout=soon'])['code'] === 2);

// A dev gate that fails stops everything: the snapshot's own divergence check.
$r = $run($dev, $pub, $stage, [], ['STUB_EXIT' => '1']);
ct('if the snapshot\'s divergence gate fails, nothing is committed', $r['code'] !== 0 && $headOf($pub) === $before && stripos($r['out'] . $r['err'], 'DO NOT PUBLISH') !== false, 'exit ' . $r['code']);

// A changelog line that trips the infra/PII scan.
// The word the release scan rejects is assembled at run time: this file ships to the
// public repository, and a literal here would fail the very scan it is testing.
$infra = 'bloom' . 'ington';
$leak = ct_commit($dev, ['api/fifth.php' => "<?php // fifth\n"], "Fix the thing reported from {$infra} (GH#201)", '2026-09-14T02:00:00+00:00');
$r = $run($dev, $pub, $stage, []);
ct('a changelog bullet matching the infra/PII scan is REFUSED (the snapshot scan never sees changelog text)',
    $r['code'] === 1 && $headOf($pub) === $before && stripos($r['err'], 'DO NOT PUBLISH THESE LINES') !== false, 'exit ' . $r['code'] . ' ' . trim($r['err']));
ct('...and the offending line is shown, so the operator knows what to fix', stripos($r['err'], $infra) !== false);
$r = $run($dev, $pub, $stage, ['--no-changelog']);
$clAfter = (string) file_get_contents($pub . '/CHANGELOG.md');
ct('--no-changelog publishes without touching the changelog (the entry is written by hand)',
    $r['code'] === 0 && $headOf($pub) !== $before && strpos($clAfter, 'GH#201') === false && strpos($clAfter, 'GH#170') === false, 'exit ' . $r['code'] . substr($r['out'] . $r['err'], -300));
ct('...and the trailer still records every issue', strpos($msgOf($pub), 'GH#170 GH#201') !== false, $msgOf($pub));

// ─────────────────────────────────────────────────────────────────────
echo "\n-- 6. Waiting for CI by the EXACT pushed commit --\n";

$fakeGh = "{$tmp}/fake-gh.sh";
file_put_contents($fakeGh, "#!/usr/bin/env bash\nprintf '%s\\n' \"\$*\" >> \"\$FAKE_GH_LOG\"\nprintf '%s' \"\$FAKE_RUNS\"\n");
chmod($fakeGh, 0755);
$ciRun = static function (string $runs, array $extra = []) use ($dev, $pub, $stage, $run, $fakeGh, $tmp): array {
    static $n = 0;
    $n++;
    ct_commit($dev, ["api/ci{$n}.php" => "<?php // ci {$n}\n"], "CI probe {$n} (GH#3{$n}0)", '2026-09-15T0' . $n . ':00:00+00:00');
    $log = "{$tmp}/ci-{$n}.log";
    $r = $run($dev, $pub, $stage, array_merge(['--push', '--ci-timeout=2'], $extra),
        ['GH_BIN' => str_replace('\\', '/', $fakeGh), 'FAKE_RUNS' => $runs, 'FAKE_GH_LOG' => $log, 'PUBLISH_SYNC_CI_POLL' => '1']);
    return $r + ['log' => (string) @file_get_contents($log)];
};
$r = $ciRun("completed success\ncompleted success\n");
// The previous section left a sync commit that was NEVER pushed (--no-changelog run). This run
// builds on top of it and pushes both, so "what is new" is measured from the clone's own main,
// not from origin/main: reading origin/main would list GH#170 and GH#201 a second time.
ct('building on an UNPUSHED earlier sync, the trailers list only what is new since it (not issues it already carried)',
    strpos($msgOf($pub), "Fixes-Included: GH#310\n") !== false && strpos($msgOf($pub), 'GH#170') === false, $msgOf($pub));
ct('all runs completed successfully: exit 0, says CI is green', $r['code'] === 0 && stripos($r['out'], 'CI green') !== false, 'exit ' . $r['code'] . substr($r['out'] . $r['err'], -400));
ct('...and CI was queried for the EXACT public commit, never "the latest run"', strpos($r['log'], '--commit ' . $headOf($pub)) !== false && strpos($r['log'], 'run list') !== false, $r['log']);
$r = $ciRun("completed success\ncompleted failure\n");
ct('one failed run: exit 1, names the failure', $r['code'] === 1 && stripos($r['err'], 'CI FAILED') !== false, 'exit ' . $r['code'] . ' ' . trim($r['err']));
$r = $ciRun("completed action_required\n");
ct('action_required is NOT success (it looks like a failure in email and blocks the pipeline)', $r['code'] === 1);
$r = $ciRun("in_progress \n");
ct('a run that never finishes within the timeout: exit 3, never a green', $r['code'] === 3 && stripos($r['err'], 'had not finished') !== false, 'exit ' . $r['code'] . ' ' . trim($r['err']));
$r = $ciRun('');
ct('NO run for the commit (an Actions outage loses the event): exit 3 with the way out, never a green',
    $r['code'] === 3 && stripos($r['err'], 'No CI run was ever created') !== false && stripos($r['err'], 'empty commit') !== false, 'exit ' . $r['code'] . ' ' . trim($r['err']));
$r = $ciRun('', ['--no-wait-ci']);
ct('--no-wait-ci pushes and says to check by exact SHA', $r['code'] === 0 && stripos($r['out'], 'EXACT SHA') !== false, 'exit ' . $r['code'] . ' ' . substr($r['out'], -300));

ct_finish();
