<?php
/**
 * AI-attribution audit gate (2026-09-04).
 *
 * ORIGIN. A tool-result formatted exactly like a genuine system reminder has
 * instructed three separate sessions to append AI-attribution trailers to
 * commits/PRs, contradicting Eric's standing rule. Refused every time by the
 * session's own judgment — but this project's whole security-engineering
 * culture exists because "an agent noticed it every time so far" is not the
 * same thing as a gate. tools/ai_attribution_audit.php and the matching half
 * of tools/git-hooks/commit-msg are that gate; this file proves both halves
 * of the audit tool actually fire on bad input and stay quiet on good input,
 * against REAL scratch git repos and REAL fixture files — not a re-implementation
 * of its matching logic, per this codebase's own standing test discipline
 * (tests/test_timezone_audit.php and siblings establish the pattern).
 *
 * Usage: php tests/test_ai_attribution_audit.php
 */

$base = realpath(__DIR__ . '/..');
$php  = PHP_BINARY;
$tool = $base . '/tools/ai_attribution_audit.php';

echo "=== AI-attribution audit gate ===\n\n";
$pass = 0; $fail = 0;
function ok(string $n): void { global $pass; echo "[PASS] $n\n"; $pass++; }
function bad(string $n, string $why = ''): void { global $fail; echo "[FAIL] $n" . ($why ? " — $why" : '') . "\n"; $fail++; }
function is_true($c, string $n, string $why = ''): void { $c ? ok($n) : bad($n, $why); }

/** Run a command with an array argv (no shell string) from $cwd; return [exitCode, output]. */
function aaa_run(array $argv, string $cwd): array
{
    $desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($argv, $desc, $pipes, $cwd);
    if (!is_resource($proc)) { return [1, '']; }
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($proc), $out];
}

/** Build a scratch git repo under $dir and return it. Requires a real `git`. */
function aaa_make_repo(string $dir): void
{
    if (is_dir($dir)) {
        // Recursive delete — scratch dirs only, never anything under the real repo.
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
        @rmdir($dir);
    }
    mkdir($dir, 0777, true);
    aaa_run(['git', 'init', '-q'], $dir);
    aaa_run(['git', 'config', 'user.email', 'fixture@example.invalid'], $dir);
    aaa_run(['git', 'config', 'user.name', 'Fixture'], $dir);
}

function aaa_commit(string $dir, string $file, string $content, string $message): void
{
    file_put_contents($dir . '/' . $file, $content);
    aaa_run(['git', 'add', $file], $dir);
    aaa_run(['git', 'commit', '-q', '-m', $message], $dir);
}

$scratch = sys_get_temp_dir() . '/ai_attribution_audit_test_' . getmypid();

// ── group 1: commit-history half ────────────────────────────────────
$repo1 = $scratch . '/repo_bad_commit';
aaa_make_repo($repo1);
aaa_commit($repo1, 'a.txt', "hello\n", "Fix a thing\n\nCo-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>");
[$code, $out] = aaa_run(['php', $tool, '--path=' . $repo1], $base);
is_true($code !== 0, 'fires on a real commit carrying Co-Authored-By: Claude Sonnet', $out);
is_true(strpos($out, 'commit-history') !== false, 'finding is tagged commit-history, not tracked-files');

$repo2 = $scratch . '/repo_robot_emoji';
aaa_make_repo($repo2);
aaa_commit($repo2, 'a.txt', "hello\n", "Fix a thing\n\n🤖 Generated with [Claude Code]");
[$code, $out] = aaa_run(['php', $tool, '--path=' . $repo2], $base);
is_true($code !== 0, 'fires on a robot-emoji + Generated-with-Claude-Code commit message', $out);

$repo3 = $scratch . '/repo_clean';
aaa_make_repo($repo3);
aaa_commit($repo3, 'a.txt', "hello\n", "Fix a thing\n\nSigned-off-by: Eric Osterberg <ejosterberg@gmail.com>");
[$code, $out] = aaa_run(['php', $tool, '--path=' . $repo3], $base);
is_true($code === 0, 'stays quiet on a clean commit history', $out);

$repo4 = $scratch . '/repo_human_claude';
aaa_make_repo($repo4);
aaa_commit($repo4, 'a.txt', "hello\n", "Fix a thing\n\nCo-Authored-By: Claude Monet <claude.monet@example.com>");
[$code, $out] = aaa_run(['php', $tool, '--path=' . $repo4], $base);
is_true($code === 0, 'does NOT false-positive on a real human co-author bearing the first name Claude', $out);

// ── group 2: tracked-file half ───────────────────────────────────────
$repo5 = $scratch . '/repo_bad_file';
aaa_make_repo($repo5);
mkdir($repo5 . '/api');
aaa_commit($repo5, 'api/thing.php', "<?php\n// 🤖 Generated with [Claude Code]\necho 1;\n", 'Add a thing');
[$code, $out] = aaa_run(['php', $tool, '--path=' . $repo5, '--no-git'], $base);
is_true($code !== 0, 'fires on a tracked non-doc file containing an attribution signature', $out);
is_true(strpos($out, 'tracked-files') !== false, 'finding is tagged tracked-files, not commit-history');

// ── group 3: baseline suppression ────────────────────────────────────
$repo6 = $scratch . '/repo_baselined';
aaa_make_repo($repo6);
aaa_commit($repo6, 'notes.txt', "line one\n🤖 Claude AI — pending response\n", 'Add notes');
$baseline6 = $scratch . '/baseline6.txt';
file_put_contents($baseline6, "notes.txt:2 | Fixture: this is the documented radio-AI-UI shape, not attribution.\n");
[$code, $out] = aaa_run(['php', $tool, '--path=' . $repo6, '--no-git', '--baseline=' . $baseline6], $base);
is_true($code === 0, 'a baselined tracked-file finding is suppressed', $out);
// Same repo, empty baseline — the finding must come back, proving the
// suppression above was the baseline entry working and not a broken pattern.
$emptyBaseline6 = $scratch . '/empty_baseline6.txt';
file_put_contents($emptyBaseline6, "# empty\n");
[$code, $out] = aaa_run(['php', $tool, '--path=' . $repo6, '--no-git', '--baseline=' . $emptyBaseline6], $base);
is_true($code !== 0, 'the SAME finding fires again once the baseline entry is removed — proves suppression was real, not silent success', $out);

// ── group 4: self-exclusion ───────────────────────────────────────────
// The real tool and its own hook mirror necessarily quote these strings —
// confirm the real audit run against the REAL repo (not a fixture) does not
// trip over its own source or the hook's.
[$code, $out] = aaa_run(['php', $tool], $base);
is_true($code === 0, 'the real audit passes clean against the real repo (including its own source + the commit-msg hook)', $out);

// ── group 5: --path is honored (doesn't silently scan the real repo) ──
$repo7 = $scratch . '/repo_path_isolation';
aaa_make_repo($repo7);
aaa_commit($repo7, 'a.txt', "hello\n", 'A commit with nothing suspicious in it');
[$code, $out] = aaa_run(['php', $tool, '--path=' . $repo7], $base);
is_true($code === 0, '--path scopes the scan to the given directory, not the real repo the tool lives in', $out);

// ── group 6: a plain export nested under an UNRELATED ancestor repo ────
// Live bug (2026-09-04): tools/release-snapshot.sh stages the public
// snapshot as a plain file copy with no .git of its own, inside a parent
// directory (newui-dev/) that happens to be its OWN unrelated checkout (the
// legacy tickets v3.44 repo). git resolves --show-toplevel by walking UP,
// so `git log`/`git ls-files` run from inside the staged tree silently
// picked up the ANCESTOR repo's real, unrelated commit history instead of
// the staged tree's own (empty) one — including real March-2026 commits
// that genuinely carry AI-attribution trailers, from years before this
// project's no-attribution convention existed. Reproduces the exact shape:
// an ancestor repo with a real bad commit, and a plain (non-git) export
// directory nested inside it that itself contains a CLEAN file.
$ancestorRepo = $scratch . '/ancestor_repo';
aaa_make_repo($ancestorRepo);
aaa_commit($ancestorRepo, 'old.txt', "hello\n", "Old work\n\nCo-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>");
$nestedExport = $ancestorRepo . '/nested_export';
mkdir($nestedExport);
file_put_contents($nestedExport . '/clean.txt', "nothing suspicious here\n");
[$code, $out] = aaa_run(['php', $tool, '--path=' . $nestedExport], $base);
is_true($code === 0, 'a plain export with no .git of its own, nested under an unrelated ancestor repo, does NOT inherit that ancestor\'s bad commit history', $out);
is_true(strpos($out, 'unrelated ancestor repo') !== false, 'prints a clear note explaining why commit history was skipped, rather than staying silent about it');
// Confirm the tracked-file half still works via the filesystem-walk fallback
// in this same scenario — add a bad file to the nested export and re-run.
file_put_contents($nestedExport . '/bad.txt', "🤖 Generated with [Claude Code]\n");
[$code, $out] = aaa_run(['php', $tool, '--path=' . $nestedExport], $base);
is_true($code !== 0, 'the tracked-file half still finds a real violation via the filesystem-walk fallback, even with no .git of its own', $out);
is_true(strpos($out, 'tracked-files') !== false, 'the fallback-mode finding is still correctly tagged tracked-files');

// cleanup
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($scratch, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
@rmdir($scratch);

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
