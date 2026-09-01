<?php
/**
 * GH#130 (reported 2026-08-31, rjonesbsink) — tools/check-doc-links.php had
 * two independent bugs (items #1 and (implicitly) the scanning approach
 * shared with the other three tools reported in the same issue):
 *
 *   1. No .claude/ exclusion — a background Agent's isolated worktree
 *      lives at .claude/worktrees/<agent-id>/, a full nested copy of the
 *      tree while active, so every real problem was reported once per
 *      concurrently-running agent worktree in addition to the real file.
 *   2. False-flagged markdown link syntax shown as an EXAMPLE inside an
 *      inline code span — docs/SUPPORT-PATTERNS.md:59 has
 *      `` `[text](url)` `` illustrating what NOT to write in a plain-text
 *      email body; the checker's link-extraction regex had no concept of
 *      code spans and reported it as a broken link to a target literally
 *      named "url".
 *
 * This file proves both fixes directly, driving the REAL tool as a
 * subprocess against real fixture files (not a hand-copied reimplementation
 * of its regex logic).
 */

require_once __DIR__ . '/_test_node_probe.php';

$pass = 0; $fail = 0;
function ok(string $m): void   { global $pass; $pass++; echo "  PASS: $m\n"; }
function bad(string $m): void  { global $fail; $fail++; echo "  FAIL: $m\n"; }
function is_ok($cond, string $m): void { $cond ? ok($m) : bad($m); }

$root = dirname(__DIR__);
$php  = PHP_BINARY ?: 'php';
$tool = $root . '/tools/check-doc-links.php';

echo "\n=== GH#130 — check-doc-links.php: .claude/ exclusion + code-span awareness ===\n";

if (!is_file($tool)) {
    echo "  SKIP: tools/check-doc-links.php not found\n";
    echo "\n=== $pass passed, $fail failed ===\n";
    exit(0);
}

// Build an isolated scratch fixture tree so this test can never be affected
// by (or accidentally flag) anything else in the real repo.
$tmp = sys_get_temp_dir() . '/tcad_gh130_' . getmypid() . '_' . mt_rand();
mkdir($tmp, 0777, true);
mkdir($tmp . '/docs', 0777, true);
mkdir($tmp . '/.claude/worktrees/agent-fake', 0777, true);
mkdir($tmp . '/.claude/worktrees/agent-fake/docs', 0777, true);

// ── 1. Code-span awareness ──────────────────────────────────────────────
// A literal `[text](url)` shown as an EXAMPLE inside a code span must NOT
// be reported as a broken link — but a REAL broken link elsewhere in the
// same file still must be caught (proves this isn't just "ignore
// everything with brackets").
file_put_contents($tmp . '/docs/example.md', <<<'MD'
# Example doc

Don't write `[text](url)` link syntax in plain text.

Also don't write a fenced example:
```
[another](fake-target)
```

But THIS one is real and broken: [broken](./does-not-exist.md)
MD
);

$out1 = test_run_cli([$php, $tool, $tmp]);
is_ok($out1 !== null, 'tool ran successfully against the fixture');
if ($out1 !== null) {
    is_ok(strpos($out1, "'url'") === false && !preg_match('/\burl\b\s+MISSING/', $out1),
        'FIX: the code-span example `[text](url)` is NOT reported as a broken link');
    is_ok(strpos($out1, 'fake-target') === false,
        'FIX: the fenced-code-block example is NOT reported as a broken link');
    is_ok(strpos($out1, 'does-not-exist.md') !== false,
        'sanity: a REAL broken link elsewhere in the same file is still caught (the fix is not "ignore everything")');
}

// ── 2. .claude/worktrees/ exclusion ──────────────────────────────────────
// The exact same broken-link fixture, duplicated inside a fake agent
// worktree, must be reported ONLY once (from the real path), not twice.
copy($tmp . '/docs/example.md', $tmp . '/.claude/worktrees/agent-fake/docs/example.md');

$out2 = test_run_cli([$php, $tool, $tmp]);
is_ok($out2 !== null, 'tool ran successfully with a fake agent worktree present');
if ($out2 !== null) {
    $realHits = substr_count($out2, 'docs/example.md');
    $worktreeHits = substr_count($out2, '.claude/worktrees');
    is_ok($worktreeHits === 0,
        'FIX: nothing under .claude/worktrees/ is reported at all', "found {$worktreeHits} reference(s)");
    is_ok($realHits >= 1,
        'the real docs/example.md finding is still reported (the exclusion did not hide everything)');
}

// Cleanup
function gh130_rrmdir(string $dir): void {
    if (!is_dir($dir)) return;
    foreach (scandir($dir) as $f) {
        if ($f === '.' || $f === '..') continue;
        $p = $dir . '/' . $f;
        is_dir($p) ? gh130_rrmdir($p) : @unlink($p);
    }
    @rmdir($dir);
}
gh130_rrmdir($tmp);

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
