<?php
/**
 * Gate: a release must not silently revert a change that exists only in the
 * PUBLIC repository.
 *
 * ── WHAT IS BEING PROVEN ─────────────────────────────────────────────
 *
 * Releases are published as a scrubbed, one-way, full-tree REPLACE of the
 * public repo. Anything committed only there — a merged outside pull request,
 * a Dependabot fix, a hand edit — is overwritten by the dev tree's version, or
 * deleted if the dev tree has no such file. Nothing fails and nobody is told.
 *
 * tools/release-divergence-check.php is the gate that stops that. This file
 * proves it BY CONSTRUCTION: every case below builds real git repositories on
 * disk, arranges the exact situation being claimed, runs the real checker as a
 * subprocess, and asserts on its exit code and its output.
 *
 * It deliberately does NOT grep the checker's source for keywords. This project
 * has been bitten repeatedly by tests that passed against a state the real
 * writer never produces (see the `assigns.rec_facility_id` and
 * `un_status.extra_data_target` episodes in CLAUDE.md), and a gate whose test
 * only reads the gate's own source proves the gate exists, not that it works.
 * The one structural claim left — that tools/release-snapshot.sh actually calls
 * the checker and honours its exit code — is proven by RUNNING the real
 * release-snapshot.sh against a fixture repo with stubbed neighbours, not by
 * reading it.
 *
 * Usage: php tests/test_release_divergence_guard.php
 */

declare(strict_types=1);

$root    = realpath(__DIR__ . '/..');
$checker = $root . '/tools/release-divergence-check.php';

$passed = 0;
$failed = 0;

function t(string $label, bool $condition, string $hint = ''): void
{
    global $passed, $failed;
    if ($condition) {
        echo "[PASS] {$label}\n";
        $passed++;
    } else {
        echo "[FAIL] {$label}" . ($hint !== '' ? " — {$hint}" : '') . "\n";
        $failed++;
    }
}

// ─────────────────────────────────────────────────────────────────────
// Subprocess + fixture helpers
// ─────────────────────────────────────────────────────────────────────

/** @return array{code:int,out:string,err:string} */
function rdg_run(array $argv, ?string $cwd = null, ?array $env = null): array
{
    $desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $pipes = [];
    $proc = @proc_open($argv, $desc, $pipes, $cwd, $env);
    if (!is_resource($proc)) return ['code' => 127, 'out' => '', 'err' => 'could not start'];
    $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
    return ['code' => proc_close($proc), 'out' => (string) $out, 'err' => (string) $err];
}

function rdg_have(string $bin): bool
{
    $probe = rdg_run([$bin, '--version']);
    return $probe['code'] === 0 || $probe['out'] !== '';
}

/**
 * Locate the bash that ships alongside git.
 *
 * On Windows, spawning plain `bash` from PHP resolves to C:\Windows\System32\
 * bash.exe — WSL's bash, a Linux userland that cannot see the Windows PHP or
 * the Windows paths this fixture uses, so tools/release-snapshot.sh dies at its
 * first `php` call for reasons that have nothing to do with the thing under
 * test. Git's own bash is the shell the release script is actually run with, so
 * derive it from `git --exec-path` and walk up to the sibling bin/.
 * On Linux this lands on /usr/bin/bash, which is the same shell either way.
 */
function rdg_bash(): ?string
{
    $r = rdg_run(['git', '--exec-path']);
    if ($r['code'] !== 0) return rdg_have('bash') ? 'bash' : null;
    $dir = str_replace('\\', '/', trim($r['out']));
    for ($i = 0; $i < 6 && $dir !== '' && $dir !== '.' && $dir !== '/'; $i++) {
        foreach (['/bin/bash.exe', '/bin/bash'] as $suffix) {
            if (is_file($dir . $suffix)) return $dir . $suffix;
        }
        $dir = dirname($dir);
    }
    return rdg_have('bash') ? 'bash' : null;
}

function rdg_rmtree(string $dir): void
{
    if (!is_dir($dir)) return;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        // git object files are read-only on Windows; clear the bit or unlink fails.
        @chmod($f->getPathname(), 0777);
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($dir);
}

/** Write path => content into $dir, creating directories. A null value deletes. */
function rdg_write(string $dir, array $files): void
{
    foreach ($files as $rel => $content) {
        $abs = $dir . '/' . $rel;
        if ($content === null) { @unlink($abs); continue; }
        $parent = dirname($abs);
        if (!is_dir($parent)) mkdir($parent, 0777, true);
        file_put_contents($abs, $content);
    }
}

function rdg_git(string $dir, array $args): array
{
    return rdg_run(array_merge(['git', '-C', $dir], $args));
}

function rdg_repo_init(string $dir): bool
{
    mkdir($dir, 0777, true);
    if (rdg_run(['git', 'init', '-q', '-b', 'main', $dir])['code'] !== 0) return false;
    rdg_git($dir, ['config', 'user.email', 'fixture@example.invalid']);
    rdg_git($dir, ['config', 'user.name', 'fixture']);
    rdg_git($dir, ['config', 'commit.gpgsign', 'false']);
    // The fixtures assert on exact bytes; leave line endings alone.
    rdg_git($dir, ['config', 'core.autocrlf', 'false']);
    return true;
}

function rdg_commit(string $dir, string $msg): bool
{
    rdg_git($dir, ['add', '-A']);
    return rdg_git($dir, ['commit', '-q', '-m', $msg])['code'] === 0;
}

// ─────────────────────────────────────────────────────────────────────
// Preconditions
// ─────────────────────────────────────────────────────────────────────

echo "=== Release divergence guard ===\n\n";

if (!is_file($checker)) {
    echo "SKIP: tools/release-divergence-check.php not found\n";
    echo "\n0 passed, 0 failed\n";
    exit(0);
}
if (!rdg_have('git')) {
    echo "SKIP: git is not on PATH — this gate builds real git repositories\n";
    echo "\n0 passed, 0 failed\n";
    exit(0);
}

// Forward slashes throughout: PHP is happy with them on Windows, and section 10
// hands these paths to MSYS bash, which is not happy with backslashes.
$tmp = str_replace('\\', '/', sys_get_temp_dir()) . '/newui-divergence-' . getmypid();
rdg_rmtree($tmp);
mkdir($tmp, 0777, true);

register_shutdown_function(static function () use ($tmp) { rdg_rmtree($tmp); });

/**
 * Build a fixture public repo whose v1.0.0 tag is "the last release", plus a
 * staging tree. Returns [publicDir, snapshotDir].
 *
 * $baseline    files as published at v1.0.0
 * $publicAfter files changed/added in the public repo AFTER that release
 *              (i.e. the merged pull request); [] for none
 * $snapshot    the staged tree about to be published
 */
function rdg_fixture(string $tmp, string $name, array $baseline, array $publicAfter, array $snapshot): array
{
    $pub  = $tmp . '/' . $name . '-public';
    $snap = $tmp . '/' . $name . '-snapshot';

    if (!rdg_repo_init($pub)) return ['', ''];
    rdg_write($pub, $baseline);
    rdg_commit($pub, 'release v1.0.0');
    rdg_git($pub, ['tag', '-a', 'v1.0.0', '-m', 'v1.0.0']);

    if ($publicAfter !== []) {
        rdg_write($pub, $publicAfter);
        rdg_commit($pub, 'fix: contributed in the public repo');
    }

    mkdir($snap, 0777, true);
    rdg_write($snap, $snapshot);
    return [$pub, $snap];
}

/** Run the real checker against a fixture. */
function rdg_check(string $checker, string $snap, string $pub, ?string $exceptions, array $extra = []): array
{
    $argv = [PHP_BINARY, $checker,
        '--snapshot=' . $snap,
        '--public-repo=' . $pub,
        '--public-ref=main',
        '--no-fetch'];
    if ($exceptions !== null) $argv[] = '--exceptions=' . $exceptions;
    foreach ($extra as $a) $argv[] = $a;
    return rdg_run($argv);
}

/** The same run, decoded. */
function rdg_json(string $checker, string $snap, string $pub, ?string $exceptions, array $extra = []): array
{
    $r = rdg_check($checker, $snap, $pub, $exceptions, array_merge($extra, ['--json']));
    $r['json'] = json_decode($r['out'], true) ?: [];
    return $r;
}

function rdg_revert_paths(array $r): array
{
    return array_map(static fn($x) => $x['path'], $r['json']['reverts'] ?? []);
}

// A baseline every scenario shares. Nothing here may contain a string the
// release scrub rewrites, or the wiring test at the end would fail the scan.
$BASE = [
    'app.php'      => "<?php\n// application, release 1\nreturn 1;\n",
    'helper.php'   => "<?php\n// helper, release 1\nfunction h() { return 'h'; }\n",
    'README.md'    => "# Fixture\n\nA fixture project.\n",
    'CHANGELOG.md' => "# Changelog\n\n## 1.0.0\n\n- first release\n",
];

$exceptionsCommon = $tmp . '/exceptions.txt';
file_put_contents($exceptionsCommon,
    "authoritative  *  CHANGELOG.md  # the public repo owns the published changelog\n");

$exceptionsEmpty = $tmp . '/exceptions-empty.txt';
file_put_contents($exceptionsEmpty, "# no exceptions declared\n");

// ─────────────────────────────────────────────────────────────────────
// 1. The clean case — only changes the dev tree explains
// ─────────────────────────────────────────────────────────────────────

echo "-- 1. Only expected updates: the guard must PASS --\n";

[$pub, $snap] = rdg_fixture($tmp, 'clean', $BASE, [], [
    'app.php'    => "<?php\n// application, release 2\nreturn 2;\n",   // dev updated it
    'helper.php' => $BASE['helper.php'],                                // untouched
    'README.md'  => $BASE['README.md'],
    'docs/NEW.md' => "# New in release 2\n",                            // dev added it
]);
t('fixture public repo built', $pub !== '' && is_dir($pub . '/.git'));

$r = rdg_json($checker, $snap, $pub, $exceptionsCommon);
t('exits 0 when nothing public-only is at risk', $r['code'] === 0,
    'exit ' . $r['code'] . ' err=' . trim($r['err']));
t('no reverts reported', ($r['json']['counts']['revert'] ?? -1) === 0);
t('the dev-side edit is classified as an ordinary update',
    ($r['json']['counts']['update'] ?? -1) === 1);
t('the dev-side addition is classified as new',
    ($r['json']['counts']['new'] ?? -1) === 1);
t('it really compared against the last release tag',
    ($r['json']['baseline_ref'] ?? '') === 'v1.0.0', ($r['json']['baseline_ref'] ?? 'none'));

$h = rdg_check($checker, $snap, $pub, $exceptionsCommon);
t('the human report says so out loud',
    strpos($h['out'], 'no public-only change would be reverted') !== false);

// ─────────────────────────────────────────────────────────────────────
// 2. A merged pull request edits a file we also ship
// ─────────────────────────────────────────────────────────────────────

echo "\n-- 2. Public-only EDIT: the guard must STOP the release --\n";

$contributed = "<?php\n// helper, release 1\nfunction h() { return 'h'; }\n"
             . "// contributor fix: reject an out-of-range index\n";

[$pub2, $snap2] = rdg_fixture($tmp, 'edit', $BASE, ['helper.php' => $contributed], [
    'app.php'    => "<?php\n// application, release 2\nreturn 2;\n",
    'helper.php' => $BASE['helper.php'],        // the port never happened
    'README.md'  => $BASE['README.md'],
]);

$r2 = rdg_json($checker, $snap2, $pub2, $exceptionsCommon);
t('exits NON-ZERO when a public-only edit would be overwritten', $r2['code'] === 1,
    'exit ' . $r2['code']);
t('exactly one revert is reported', ($r2['json']['counts']['revert'] ?? -1) === 1);
t('and it names helper.php', rdg_revert_paths($r2) === ['helper.php'],
    implode(',', rdg_revert_paths($r2)));
t('it does NOT flag the file the dev tree legitimately updated',
    !in_array('app.php', rdg_revert_paths($r2), true));

$h2 = rdg_check($checker, $snap2, $pub2, $exceptionsCommon);
t('the human report names the file', strpos($h2['out'], 'helper.php') !== false);
t('the human report says it would be OVERWRITTEN',
    strpos($h2['out'], 'OVERWRITTEN') !== false);
t('the human report quotes the line that would be lost',
    strpos($h2['out'], 'contributor fix: reject an out-of-range index') !== false);
t('the human report explains WHY this happens (full-tree replace)',
    stripos($h2['out'], 'REPLACES the public tree wholesale') !== false);
t('the human report names the commit that made the public change',
    strpos($h2['out'], 'contributed in the public repo') !== false);
t('the human report recommends porting with the contributor as author',
    strpos($h2['out'], '--author=') !== false);

// ─────────────────────────────────────────────────────────────────────
// 3. A merged pull request ADDS a file the dev tree has never seen
// ─────────────────────────────────────────────────────────────────────

echo "\n-- 3. Public-only NEW FILE: the guard must STOP the release --\n";

$added = "<?php\n// Channel adapter contributed in the public repo\nreturn 'adapter';\n";

[$pub3, $snap3] = rdg_fixture($tmp, 'add', $BASE, ['inc/channels/extra.php' => $added], [
    'app.php'    => $BASE['app.php'],
    'helper.php' => $BASE['helper.php'],
    'README.md'  => $BASE['README.md'],
]);

$r3 = rdg_json($checker, $snap3, $pub3, $exceptionsCommon);
t('exits NON-ZERO when a public-only file would be deleted', $r3['code'] === 1);
t('and it names the added path', rdg_revert_paths($r3) === ['inc/channels/extra.php'],
    implode(',', rdg_revert_paths($r3)));
t('classified as a deletion, not an overwrite',
    ($r3['json']['reverts'][0]['kind'] ?? '') === 'delete');

$h3 = rdg_check($checker, $snap3, $pub3, $exceptionsCommon);
t('the human report says the file would be DELETED',
    strpos($h3['out'], 'would be DELETED') !== false);
t('the human report notes it appeared after the last release',
    strpos($h3['out'], 'added to the public repo after the last release') !== false);
t('the human report excerpts the file that would be lost',
    strpos($h3['out'], 'Channel adapter contributed in the public repo') !== false);

// ─────────────────────────────────────────────────────────────────────
// 4. A deletion the DEV tree intended is not a revert
// ─────────────────────────────────────────────────────────────────────

echo "\n-- 4. Dev-side deletion of an unchanged public file: PASS --\n";

[$pub4, $snap4] = rdg_fixture($tmp, 'del', $BASE, [], [
    'app.php'   => $BASE['app.php'],
    'README.md' => $BASE['README.md'],
    // helper.php deliberately dropped by the dev tree
]);

$r4 = rdg_json($checker, $snap4, $pub4, $exceptionsCommon);
t('exits 0 — removing a file nobody changed in public is our call', $r4['code'] === 0,
    'exit ' . $r4['code']);
t('it is counted as a deletion', ($r4['json']['counts']['delete'] ?? -1) === 1);
t('and not as a revert', ($r4['json']['counts']['revert'] ?? -1) === 0);

// ── but deleting a file that WAS changed in public still stops the release ──

[$pub4b, $snap4b] = rdg_fixture($tmp, 'del-changed', $BASE,
    ['helper.php' => $contributed], [
        'app.php'   => $BASE['app.php'],
        'README.md' => $BASE['README.md'],
    ]);
$r4b = rdg_json($checker, $snap4b, $pub4b, $exceptionsCommon);
t('deleting a file that WAS edited in public is still a revert', $r4b['code'] === 1);
t('and it names it', rdg_revert_paths($r4b) === ['helper.php']);

// ─────────────────────────────────────────────────────────────────────
// 4b. A public-only DELETION would be undone by re-shipping the file
// ─────────────────────────────────────────────────────────────────────
//
// The shape a content-only check misses: the contributor removed a file, so
// there is no new content anywhere to notice. The change being reverted is the
// removal itself, and the snapshot quietly puts the file back.

echo "\n-- 4b. Public-only DELETION: re-shipping the file is also a revert --\n";

$pub4c = $tmp . '/undelete-public';
rdg_repo_init($pub4c);
rdg_write($pub4c, $BASE);
rdg_commit($pub4c, 'release v1.0.0');
rdg_git($pub4c, ['tag', '-a', 'v1.0.0', '-m', 'v1.0.0']);
rdg_git($pub4c, ['rm', '-q', 'helper.php']);
rdg_commit($pub4c, 'chore: drop the deprecated helper');

$snap4c = $tmp . '/undelete-snapshot';
mkdir($snap4c, 0777, true);
rdg_write($snap4c, $BASE);   // our tree still ships helper.php

$r4c = rdg_json($checker, $snap4c, $pub4c, $exceptionsCommon);
t('re-shipping a file the public repo deleted is a revert, not a "new file"',
    $r4c['code'] === 1 && rdg_revert_paths($r4c) === ['helper.php'],
    'exit ' . $r4c['code'] . ' paths=' . implode(',', rdg_revert_paths($r4c)));
t('it is not miscounted as an addition', ($r4c['json']['counts']['new'] ?? -1) === 0);
t('and is classified as a resurrection',
    ($r4c['json']['reverts'][0]['kind'] ?? '') === 'resurrect');

$h4c = rdg_check($checker, $snap4c, $pub4c, $exceptionsCommon);
t('the report explains the file would be brought back',
    stripos($h4c['out'], 'BRING IT BACK') !== false);

// ─────────────────────────────────────────────────────────────────────
// 5. The declared exception (CHANGELOG.md) and its rules
// ─────────────────────────────────────────────────────────────────────

echo "\n-- 5. Declared exceptions --\n";

// The public changelog is rewritten every release, so public != baseline AND
// public != snapshot: without the declaration this is a textbook revert.
$publicChangelog = "# Changelog\n\n## 1.0.1\n\n- a fix\n\n## 1.0.0\n\n- first release\n";
[$pub5, $snap5] = rdg_fixture($tmp, 'exc', $BASE, ['CHANGELOG.md' => $publicChangelog], [
    'app.php'      => $BASE['app.php'],
    'helper.php'   => $BASE['helper.php'],
    'README.md'    => $BASE['README.md'],
    'CHANGELOG.md' => "# Changelog\n\nprocess note stub\n",
]);

$r5no = rdg_json($checker, $snap5, $pub5, $exceptionsEmpty);
t('WITHOUT the declaration, the changelog reads as a revert (the check is real)',
    $r5no['code'] === 1 && rdg_revert_paths($r5no) === ['CHANGELOG.md'],
    'exit ' . $r5no['code'] . ' paths=' . implode(',', rdg_revert_paths($r5no)));

$r5 = rdg_json($checker, $snap5, $pub5, $exceptionsCommon);
t('WITH the declaration it passes', $r5['code'] === 0, 'exit ' . $r5['code']);
t('and is reported as public-authoritative, not hidden',
    ($r5['json']['counts']['authoritative'] ?? -1) === 1);

$h5 = rdg_check($checker, $snap5, $pub5, $exceptionsCommon);
t('the exception and its reason are printed, so it is never a silent skip',
    strpos($h5['out'], 'public-authoritative: CHANGELOG.md') !== false
    && strpos($h5['out'], 'owns the published changelog') !== false);

// A record with no reason must be rejected outright.
$badNoReason = $tmp . '/exceptions-noreason.txt';
file_put_contents($badNoReason, "authoritative  *  CHANGELOG.md\n");
$rBad = rdg_check($checker, $snap5, $pub5, $badNoReason);
t('an exception with no stated reason is refused', $rBad['code'] === 1);
t('and the error says why', stripos($rBad['err'], 'reason') !== false, trim($rBad['err']));

$badKind = $tmp . '/exceptions-badkind.txt';
file_put_contents($badKind, "ignore  *  CHANGELOG.md  # because I said so\n");
$rKind = rdg_check($checker, $snap5, $pub5, $badKind);
t('an unknown record kind is refused', $rKind['code'] === 1);

$badSha = $tmp . '/exceptions-badsha.txt';
file_put_contents($badSha, "ported  latest  helper.php  # ported upstream\n");
$rSha = rdg_check($checker, $snap5, $pub5, $badSha);
t('a `ported` record that does not pin a blob hash is refused', $rSha['code'] === 1);

// ─────────────────────────────────────────────────────────────────────
// 6. The acknowledgement is pinned, so it expires by itself
// ─────────────────────────────────────────────────────────────────────

echo "\n-- 6. `ported` acknowledgement, pinned to the exact public blob --\n";

[$pub6, $snap6] = rdg_fixture($tmp, 'ported', $BASE, ['helper.php' => $contributed], [
    'app.php'    => "<?php\n// application, release 2\nreturn 2;\n",
    'helper.php' => $BASE['helper.php'],
    'README.md'  => $BASE['README.md'],
]);
$blob = trim(rdg_git($pub6, ['rev-parse', 'main:helper.php'])['out']);
t('fixture blob hash resolved', preg_match('/^[0-9a-f]{40}$/', $blob) === 1, $blob);

$ackFile = $tmp . '/exceptions-ack.txt';
file_put_contents($ackFile,
    "authoritative  *  CHANGELOG.md  # the public repo owns the published changelog\n"
    . "ported  {$blob}  helper.php  # applied to the dev repo as commit deadbee\n");

$r6 = rdg_json($checker, $snap6, $pub6, $ackFile);
t('an acknowledged port no longer blocks the release', $r6['code'] === 0, 'exit ' . $r6['code']);
t('and is counted as acknowledged rather than ignored',
    ($r6['json']['counts']['acknowledged'] ?? -1) === 1);

// The contributor pushes again: the pin no longer matches, so it comes back.
rdg_write($pub6, ['helper.php' => $contributed . "// a second contributed fix\n"]);
rdg_commit($pub6, 'fix: a second change in the public repo');

$r6b = rdg_json($checker, $snap6, $pub6, $ackFile);
t('when the public file changes again the acknowledgement stops applying',
    $r6b['code'] === 1 && rdg_revert_paths($r6b) === ['helper.php'],
    'exit ' . $r6b['code'] . ' paths=' . implode(',', rdg_revert_paths($r6b)));

$h6b = rdg_check($checker, $snap6, $pub6, $ackFile);
t('and the report explains that the pinned blob no longer matches',
    strpos($h6b['out'], 'pins a different public') !== false);

// ─────────────────────────────────────────────────────────────────────
// 7. The override: explicit, counted, never the default
// ─────────────────────────────────────────────────────────────────────

echo "\n-- 7. The override --\n";

[$pub7, $snap7] = rdg_fixture($tmp, 'override', $BASE, [
    'helper.php'             => $contributed,
    'inc/channels/extra.php' => $added,
], [
    'app.php'    => $BASE['app.php'],
    'helper.php' => $BASE['helper.php'],
    'README.md'  => $BASE['README.md'],
]);

$r7 = rdg_json($checker, $snap7, $pub7, $exceptionsCommon);
t('two public-only changes are found', ($r7['json']['counts']['revert'] ?? -1) === 2,
    implode(',', rdg_revert_paths($r7)));

$r7ok = rdg_check($checker, $snap7, $pub7, $exceptionsCommon, ['--allow-revert=2']);
t('--allow-revert=<exact count> lets the release proceed', $r7ok['code'] === 0,
    'exit ' . $r7ok['code']);
t('and it says loudly what is being discarded',
    strpos($r7ok['out'], 'DISCARDING') !== false);
t('the discarded paths are still listed under the override',
    strpos($r7ok['out'], 'helper.php') !== false
    && strpos($r7ok['out'], 'inc/channels/extra.php') !== false);

$r7wrong = rdg_check($checker, $snap7, $pub7, $exceptionsCommon, ['--allow-revert=1']);
t('a count that does not match the findings is refused', $r7wrong['code'] === 1);
t('and the refusal explains that the approved set changed',
    strpos($r7wrong['out'], 'does not match') !== false);

$r7bare = rdg_check($checker, $snap7, $pub7, $exceptionsCommon, ['--allow-revert']);
t('a bare --allow-revert is refused — an override must state a number',
    $r7bare['code'] === 1);
t('and says how to invoke it properly',
    stripos($r7bare['err'] . $r7bare['out'], 'needs the number') !== false);

// The override is never implied.
$r7default = rdg_check($checker, $snap7, $pub7, $exceptionsCommon);
t('without the flag the release stops', $r7default['code'] === 1);

// ─────────────────────────────────────────────────────────────────────
// 8. No baseline: refuse, never report clean
// ─────────────────────────────────────────────────────────────────────

echo "\n-- 8. When the baseline cannot be established --\n";

$pub8 = $tmp . '/nobaseline-public';
rdg_repo_init($pub8);
rdg_write($pub8, $BASE);
rdg_commit($pub8, 'initial, never tagged');
$snap8 = $tmp . '/nobaseline-snapshot';
mkdir($snap8, 0777, true);
rdg_write($snap8, $BASE);   // identical trees: a naive check would say "clean"

$r8 = rdg_check($checker, $snap8, $pub8, $exceptionsCommon);
t('an untagged public repo makes the checker REFUSE, not pass', $r8['code'] === 1,
    'exit ' . $r8['code']);
t('it never claims the tree is clean without a baseline',
    strpos($r8['out'], 'no public-only change would be reverted') === false);
t('and the message says a baseline could not be established',
    stripos($r8['err'], 'cannot establish what the last release published') !== false,
    trim($r8['err']));

$r8ok = rdg_check($checker, $snap8, $pub8, $exceptionsCommon, ['--baseline=main']);
t('--baseline=<ref> gives it one and it proceeds', $r8ok['code'] === 0, 'exit ' . $r8ok['code']);

// ─────────────────────────────────────────────────────────────────────
// 9. Line endings are not a finding
// ─────────────────────────────────────────────────────────────────────

echo "\n-- 9. An EOL-only difference is not a change --\n";

[$pub9, $snap9] = rdg_fixture($tmp, 'eol', $BASE, [], [
    'app.php'    => str_replace("\n", "\r\n", $BASE['app.php']),
    'helper.php' => $BASE['helper.php'],
    'README.md'  => $BASE['README.md'],
]);
$r9 = rdg_json($checker, $snap9, $pub9, $exceptionsCommon);
t('CRLF vs LF is not reported as an update', ($r9['json']['counts']['update'] ?? -1) === 0);
t('nor as a revert', ($r9['json']['counts']['revert'] ?? -1) === 0);
t('it is simply unchanged', ($r9['json']['counts']['same'] ?? 0) >= 3);

// ─────────────────────────────────────────────────────────────────────
// 10. The real release script calls the gate and obeys it
// ─────────────────────────────────────────────────────────────────────
//
// Everything above proves the checker. This proves the WIRING, by running the
// real tools/release-snapshot.sh — its own bytes, copied unmodified — inside a
// fixture repo whose neighbours are stubs. A gate nothing calls is not a gate,
// and reading the script to confirm it calls something is the kind of proof
// this project has learned not to accept.

echo "\n-- 10. tools/release-snapshot.sh honours the gate --\n";

$snapshotScript = $root . '/tools/release-snapshot.sh';
$bash = rdg_bash();

// release-snapshot.sh calls `php`, `tar` and `python` by name. The PHP binary
// running this test is normally NOT on PATH on Windows, so hand the child an
// environment whose PATH contains it — the alternative is a test that only
// passes on a machine configured a particular way, which is a test that will
// one day pass for the wrong reason. putenv() is not enough: the child's
// environment block comes from the real process environment, which putenv()
// does not reliably reach on Windows, so build the block explicitly.
$childEnv = getenv();
$phpDir = str_replace('\\', '/', dirname(PHP_BINARY));
$pathKey = 'PATH';
foreach (array_keys($childEnv) as $k) {
    if (strcasecmp($k, 'PATH') === 0) { $pathKey = $k; break; }
}
$childEnv[$pathKey] = $phpDir . PATH_SEPARATOR . ($childEnv[$pathKey] ?? '');
/** The stub checker reads STUB_EXIT to choose its verdict. */
$envWith = static function (string $code) use ($childEnv, $pathKey): array {
    $childEnv['STUB_EXIT'] = $code;
    return $childEnv;
};

$toolProbe = $bash === null
    ? ['code' => 127, 'out' => '', 'err' => 'no bash']
    : rdg_run([$bash, '-c', 'command -v php && command -v tar && command -v python'], null, $childEnv);

if (!is_file($snapshotScript) || $bash === null || $toolProbe['code'] !== 0) {
    echo "SKIP: end-to-end wiring check needs bash + php + tar + python on PATH"
        . " — the checker's own behaviour above is unaffected\n";
} else {
    $dev = $tmp . '/dev';
    rdg_repo_init($dev);
    rdg_write($dev, [
        'app.php'   => "<?php\nreturn 1;\n",
        'README.md' => "# Fixture app\n",
        // Stub the SBOM gate: this test is about step 7, and the SBOM gates are
        // covered by their own suite.
        'tools/generate-sbom.php' => "<?php\nexit(0);\n",
    ]);
    copy($snapshotScript, $dev . '/tools/release-snapshot.sh');
    // A stub checker that records how it was called and fails, so the only way
    // the script can exit 1 with the DO-NOT-PUBLISH banner is by calling it.
    $argvLog = str_replace('\\', '/', $tmp . '/divergence-argv.txt');
    rdg_write($dev, ['tools/release-divergence-check.php' =>
        "<?php\nfile_put_contents('" . $argvLog . "', implode(\"\\n\", \$argv));\n"
        . "fwrite(STDOUT, \"STUB CHECKER RAN\\n\");\nexit((int) getenv('STUB_EXIT'));\n"]);
    rdg_commit($dev, 'fixture app');

    $stage = $tmp . '/stage';

    $fail = rdg_run([$bash, 'tools/release-snapshot.sh', $stage], $dev, $envWith('1'));
    t('release-snapshot.sh actually runs the divergence check',
        strpos($fail['out'] . $fail['err'], 'STUB CHECKER RAN') !== false,
        trim(substr($fail['out'] . $fail['err'], -400)));
    t('a failing divergence check FAILS the release', $fail['code'] !== 0,
        'exit ' . $fail['code']);
    t('and the release script says DO NOT PUBLISH',
        strpos($fail['out'] . $fail['err'], 'DO NOT PUBLISH') !== false);

    $recorded = is_file($argvLog) ? file_get_contents($argvLog) : '';
    t('the checker is pointed at the staging tree that was just built',
        strpos($recorded, '--snapshot=' . $stage) !== false, $recorded);
    t('and at the declared-exceptions file',
        strpos($recorded, '--exceptions=') !== false, $recorded);

    $pass = rdg_run([$bash, 'tools/release-snapshot.sh', $stage], $dev, $envWith('0'));
    t('a passing divergence check lets the release complete', $pass['code'] === 0,
        'exit ' . $pass['code'] . ' ' . trim(substr($pass['out'] . $pass['err'], -400)));
    t('the completion message reminds the maintainer to tag the release',
        stripos($pass['out'], 'TAG the release') !== false);

    // Options must reach the checker rather than being swallowed as a stage dir.
    @unlink($argvLog);
    rdg_run([$bash, 'tools/release-snapshot.sh', $stage, '--allow-revert=3'], $dev, $envWith('0'));
    $recorded2 = is_file($argvLog) ? file_get_contents($argvLog) : '';
    t('--allow-revert is forwarded to the checker, not mistaken for the stage dir',
        strpos($recorded2, '--allow-revert=3') !== false
        && strpos($recorded2, '--snapshot=' . $stage) !== false, $recorded2);

    $bogus = rdg_run([$bash, 'tools/release-snapshot.sh', $stage, '--not-an-option'], $dev, $envWith('0'));
    t('an unknown option is rejected instead of silently becoming the stage dir',
        $bogus['code'] === 2, 'exit ' . $bogus['code']);
}

// ─────────────────────────────────────────────────────────────────────
// 11. The exceptions file that actually ships
// ─────────────────────────────────────────────────────────────────────

echo "\n-- 11. The repository's own declared exceptions parse --\n";

$realExceptions = $root . '/tools/release-public-exceptions.txt';
t('tools/release-public-exceptions.txt exists', is_file($realExceptions));
if (is_file($realExceptions)) {
    $rReal = rdg_check($checker, $snap5, $pub5, $realExceptions);
    t('it parses (every record has a kind, a pin and a reason)',
        stripos($rReal['err'], 'release-divergence-check:') === false, trim($rReal['err']));
    t('and it declares CHANGELOG.md public-authoritative',
        strpos($rReal['out'], 'public-authoritative: CHANGELOG.md') !== false);
}

// ─────────────────────────────────────────────────────────────────────
// 12. Untagged syncs (tools/publish-sync.sh) are a baseline too
// ─────────────────────────────────────────────────────────────────────
//
// Phase 155. The standing practice is to sync every verified fix to the public
// repo at once and tag only RELEASES. With the newest `v*` tag as the only
// baseline, every file an earlier UNTAGGED sync touched differs from the
// baseline, so each one was reported as a public-only change that publishing
// would revert. On 2026-10-02 that was 17 findings across 7 files, every one
// individually checked as "dev moved forward", and the release needed
// --allow-revert=17. These cases reproduce that shape exactly, and then prove
// the fix did not open the opposite hole: a GENUINE outside change made after
// the sync commit must still stop the release.

echo "\n-- 12. Untagged sync commits (Dev-Commit trailer) as the baseline --\n";

$devShaA = str_repeat('a', 40);
$syncMsg = "TicketsCAD sync aaaaaaa: GH#1\n\nDev-Commit: {$devShaA}\nFixes-Included: GH#1\n";

/**
 * A public repo: release v1.0.0, then an UNTAGGED sync commit that updated
 * app.php to release 1.1 and carries a Dev-Commit trailer. $after, if given,
 * is committed on top of it (an outside change made after the sync).
 */
$untagged = static function (string $name, array $after = []) use ($tmp, $BASE, $devShaA, $syncMsg): array {
    $pub = $tmp . '/' . $name . '-public';
    rdg_repo_init($pub);
    rdg_write($pub, $BASE);
    rdg_commit($pub, 'release v1.0.0');
    rdg_git($pub, ['tag', '-a', 'v1.0.0', '-m', 'v1.0.0']);
    rdg_write($pub, ['app.php' => "<?php\n// application, release 1.1 (synced untagged)\nreturn 11;\n"]);
    rdg_commit($pub, $syncMsg);
    if ($after !== []) {
        rdg_write($pub, $after);
        rdg_commit($pub, 'fix: contributed in the public repo after the sync');
    }
    $snap = $tmp . '/' . $name . '-snapshot';
    mkdir($snap, 0777, true);
    // Dev has moved on again: app.php is release 2, nothing else changed.
    rdg_write($snap, [
        'app.php'    => "<?php\n// application, release 2\nreturn 2;\n",
        'helper.php' => $BASE['helper.php'],
        'README.md'  => $BASE['README.md'],
    ]);
    return [$pub, $snap];
};

[$pub12, $snap12] = $untagged('untagged-clean');
$syncSha12 = trim(rdg_git($pub12, ['rev-parse', 'HEAD'])['out']);
$r12 = rdg_json($checker, $snap12, $pub12, $exceptionsCommon);
t('an earlier untagged sync is NOT reported as a public-only change',
    ($r12['json']['counts']['revert'] ?? -1) === 0, json_encode($r12['json']['reverts'] ?? []));
t('so the release is not stopped and needs no --allow-revert', $r12['code'] === 0, 'exit ' . $r12['code']);
t('the baseline is the sync commit, which is newer than the tag',
    ($r12['json']['baseline_ref'] ?? '') === $syncSha12, ($r12['json']['baseline_ref'] ?? 'none'));
t('and the report says why',
    stripos((string) ($r12['json']['baseline_source'] ?? ''), 'Dev-Commit') !== false,
    (string) ($r12['json']['baseline_source'] ?? ''));

// The failure as it happened: the same fixture judged by the TAG alone. This is
// the control that proves the cases above pass because of the fix and not
// because the fixture is trivially clean.
$r12tag = rdg_json($checker, $snap12, $pub12, $exceptionsCommon, ['--baseline=v1.0.0']);
t('control: judged against the tag alone, the same tree IS (wrongly) reported as a revert',
    ($r12tag['json']['counts']['revert'] ?? 0) >= 1 && $r12tag['code'] === 1,
    'reverts=' . ($r12tag['json']['counts']['revert'] ?? 'n/a') . ' exit ' . $r12tag['code']);

// A genuine outside change AFTER the sync must still be caught.
[$pub12b, $snap12b] = $untagged('untagged-outside', [
    'helper.php' => "<?php\n// helper, a contributor's fix merged publicly\nfunction h() { return 'fixed'; }\n",
]);
$r12b = rdg_json($checker, $snap12b, $pub12b, $exceptionsCommon);
t('a real outside change made after the sync commit still STOPS the release',
    $r12b['code'] === 1 && in_array('helper.php', rdg_revert_paths($r12b), true),
    'exit ' . $r12b['code'] . ' reverts=' . json_encode(rdg_revert_paths($r12b)));
t('and only that file is named (the synced app.php is not noise)',
    rdg_revert_paths($r12b) === ['helper.php'], json_encode(rdg_revert_paths($r12b)));

// A tag NEWER than the last sync commit wins: releases are baselines too.
$pub12c = $tmp . '/tag-after-sync-public';
rdg_repo_init($pub12c);
rdg_write($pub12c, $BASE);
rdg_commit($pub12c, 'release v1.0.0');
rdg_git($pub12c, ['tag', '-a', 'v1.0.0', '-m', 'v1.0.0']);
rdg_write($pub12c, ['app.php' => "<?php\n// synced\nreturn 11;\n"]);
rdg_commit($pub12c, $syncMsg);
rdg_write($pub12c, ['app.php' => "<?php\n// released as 1.2\nreturn 12;\n"]);
rdg_commit($pub12c, 'release v1.2.0');
rdg_git($pub12c, ['tag', '-a', 'v1.2.0', '-m', 'v1.2.0']);
$snap12c = $tmp . '/tag-after-sync-snapshot';
mkdir($snap12c, 0777, true);
rdg_write($snap12c, ['app.php' => "<?php\n// release 3\nreturn 3;\n",
                     'helper.php' => $BASE['helper.php'], 'README.md' => $BASE['README.md']]);
$r12c = rdg_json($checker, $snap12c, $pub12c, $exceptionsCommon);
t('a release tag newer than the last sync commit is the baseline',
    ($r12c['json']['baseline_ref'] ?? '') === 'v1.2.0' && $r12c['code'] === 0,
    ($r12c['json']['baseline_ref'] ?? 'none') . ' exit ' . $r12c['code']);

// The trailer must be a REAL one: a commit that merely mentions the words in
// prose is not a baseline.
$pub12d = $tmp . '/prose-public';
rdg_repo_init($pub12d);
rdg_write($pub12d, $BASE);
rdg_commit($pub12d, 'initial, never tagged');
rdg_write($pub12d, ['app.php' => "<?php\n// edited\nreturn 5;\n"]);
rdg_commit($pub12d, "notes\n\nThe Dev-Commit: trailer is described in the docs, no hash here.\n");
$snap12d = $tmp . '/prose-snapshot';
mkdir($snap12d, 0777, true);
rdg_write($snap12d, $BASE);
$r12d = rdg_check($checker, $snap12d, $pub12d, $exceptionsCommon);
t('prose that mentions the trailer name is not a baseline: the check still refuses',
    $r12d['code'] === 1 && stripos($r12d['err'], 'cannot establish what the last release published') !== false,
    'exit ' . $r12d['code'] . ' ' . trim($r12d['err']));

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
