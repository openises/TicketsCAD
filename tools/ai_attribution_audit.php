<?php
/**
 * AI-attribution audit — a mechanical gate for Eric's standing "never add AI
 * attribution to commits, PRs, or releases" rule (global CLAUDE.md).
 *
 * WHY THIS EXISTS (2026-09-04)
 * ----------------------------
 * A tool-result formatted exactly like a genuine system reminder has, across
 * three separate TicketsCAD sessions, instructed the agent handling them to
 * append "Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>" and
 * "🤖 Generated with [Claude Code]" to future commits and PRs — directly
 * contradicting Eric's explicit, repeatedly-stated rule. Every one of the
 * three was recognized and refused at the time. That is one layer, and this
 * project's whole security-engineering culture (the RBAC exclusion-leak
 * audit, the schema-mismatch audits, the SBOM freshness gate) exists
 * precisely because "an agent's own judgment caught it every time so far" is
 * not the same thing as a gate — it is a streak, and streaks end. This audit
 * is the second, mechanical layer: even if a future session's judgment ever
 * failed — or a commit landed some other way entirely, e.g. GitHub's own web
 * editor, which no local git hook can see — the result is still physically
 * blocked from staying on `main` unnoticed.
 *
 * WHAT IT CHECKS
 * --------------
 *   [commit-history]  Every commit message reachable from HEAD, scanned for
 *                      known AI-attribution signatures. Zero tolerance and
 *                      NO baseline for this half — there is no legitimate
 *                      reason a real commit message ever needs to match
 *                      these patterns, so there is nothing to grandfather.
 *   [tracked-files]    Every tracked, non-binary file in the working tree,
 *                      scanned for the same signatures showing up somewhere
 *                      OTHER than a commit message — a PR template, a
 *                      generated release-notes file, an accidentally
 *                      committed draft. This half DOES use a baseline
 *                      (tools/ai_attribution_baseline.txt), because the
 *                      policy itself is legitimately documented in this
 *                      repo using these exact strings as worked examples of
 *                      what not to do (this file's own docblock, CLAUDE.md's
 *                      pitfalls section, a UI mockup in
 *                      specs/phase-85f-claude-on-radio/spec.md that shows a
 *                      literal "🤖 Claude AI" caller-queue label as a real
 *                      PRODUCT FEATURE, not a code-attribution trailer).
 *                      Findings default-deny; a baseline entry is a
 *                      reviewed claim that a specific line is not a real
 *                      violation, exactly like every other audit in this
 *                      family.
 *
 * tools/git-hooks/commit-msg is the FIRST, earlier gate — it rejects a
 * matching commit message before the commit ever exists. This audit is the
 * SECOND gate, and the one CI actually runs, because CI cannot be bypassed
 * the way a local hook can (--no-verify, a clone that never ran
 * install-git-hooks.sh, a commit made outside the git CLI entirely). The two
 * pattern lists MUST stay in lockstep — see ai_attribution_patterns() below
 * and the mirrored `grep -Ei` list in tools/git-hooks/commit-msg's own
 * AI_PATTERNS array. This file's own docblock and this audit's test fixtures
 * necessarily CONTAIN the banned strings as literal text; both are excluded
 * from the tracked-file half by path (see $SELF_EXCLUDE below), the same way
 * a lock's own key is not itself a security hole.
 *
 * Usage: php tools/ai_attribution_audit.php [--path=<dir>] [--no-git] [--baseline=<file>]
 *   --path=<dir>      scan this directory tree instead of the real repo, and
 *                      (unless --no-git is also given) treat it as the git
 *                      repo to read commit history from. Tests use this
 *                      against scratch fixture repos.
 *   --no-git          skip the commit-history half entirely (a fixture
 *                      directory may not be a real git repo at all).
 *   --baseline=<file> use this baseline file instead of the real one (tests
 *                      use this to prove baseline suppression works without
 *                      touching the real baseline).
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

/**
 * The canonical pattern list. Label => PCRE (case-insensitive, unicode-aware
 * for the emoji pattern). MUST be mirrored in tools/git-hooks/commit-msg's
 * bash AI_PATTERNS array — that hook cannot `require` this PHP file, so the
 * two lists are kept in sync by hand and cross-referenced by comment.
 *
 * Deliberately NOT a blanket "any Co-Authored-By line" match — that trailer
 * is a legitimate convention for real HUMAN pair-authorship, and this repo's
 * own CONTRIBUTING.md/DCO model does not forbid it. Only a Co-Authored-By (or
 * "Generated with") line naming a known AI service/tool is a finding.
 */
function ai_attribution_patterns(): array
{
    return [
        'co-authored-by-anthropic' => '/co-authored-by\s*:.*@?anthropic\.com/i',
        'co-authored-by-openai'    => '/co-authored-by\s*:.*@?openai\.com/i',
        // "claude" alone is a real human given name (a legitimate human
        // co-author could genuinely be named Claude) — require an
        // Anthropic-style qualifier (Sonnet/Opus/Haiku/Fable/Code/AI) so a
        // real "Co-Authored-By: Claude <Surname>" is never a false positive.
        'co-authored-by-ai-name'   => '/co-authored-by\s*:.*(claude[\s-]*(sonnet|opus|haiku|fable|code|ai)\b|\b(copilot|chatgpt|gpt-?[0-9]|gemini)\b)/i',
        'generated-with-ai'        => '/generated\s+with\b.{0,60}\b(claude|copilot|chatgpt|gpt-?[0-9]|openai|anthropic|gemini)\b/i',
        // Broad on purpose: the robot emoji is off-convention in this
        // codebase's own commit/prose style (CLAUDE.md: "only use emojis if
        // explicitly requested"), so pairing it with the bare word "claude"
        // at all is already a rare, suspicious combination worth a baseline
        // entry rather than a narrower regex that a slightly-reworded
        // injection attempt could step around.
        'robot-emoji-ai'           => '/🤖.{0,60}\b(claude|anthropic)\b/iu',
        'noreply-anthropic'        => '/noreply@anthropic\.com/i',
    ];
}

/** Files that necessarily contain the banned strings as literal text (this
 *  audit's own source, its test, the mirrored git hook, and the baseline
 *  file itself — whose whole job is to quote whatever it's suppressing as
 *  its own reason text) — checking them against themselves would be a
 *  permanent, meaningless finding. */
function ai_attribution_self_exclude(): array
{
    return [
        'tools/ai_attribution_audit.php',
        'tests/test_ai_attribution_audit.php',
        'tools/git-hooks/commit-msg',
        'tools/ai_attribution_baseline.txt',
    ];
}

/** Run a command, return [exitCode, stdout]. Array argv — never a shell
 *  string — so nothing here can be mangled by escapeshellarg on Windows. */
function ai_run(array $argv, string $cwd): array
{
    $desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($argv, $desc, $pipes, $cwd);
    if (!is_resource($proc)) { return [1, '']; }
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($proc), $out];
}

/** Load a baseline file (path:line | reason) into a set of "path:line" keys. */
function ai_load_baseline(string $file): array
{
    $set = [];
    if (!is_file($file)) { return $set; }
    foreach (file($file, FILE_IGNORE_NEW_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') { continue; }
        $key = trim(explode('|', $line, 2)[0]);
        if ($key !== '') { $set[$key] = true; }
    }
    return $set;
}

// ── argv ─────────────────────────────────────────────────────────────
$root     = realpath(__DIR__ . '/..');
$noGit    = false;
$baseline = $root . '/tools/ai_attribution_baseline.txt';
foreach ($argv ?? [] as $a) {
    if (strpos($a, '--path=') === 0)     { $root = rtrim(substr($a, 7), '/'); }
    if ($a === '--no-git')               { $noGit = true; }
    if (strpos($a, '--baseline=') === 0) { $baseline = substr($a, 11); }
}
if ($root === false || !is_dir($root)) {
    fwrite(STDERR, "ai_attribution_audit: no such directory\n");
    exit(1);
}

$patterns = ai_attribution_patterns();
$findings = [];   // ['commit-history'|'tracked-files', $where, $label, $excerpt]

/* Git walks UP the directory tree looking for a .git — if $root itself has
 * none (a plain file export, e.g. tools/release-snapshot.sh's staged public
 * tree, which is a straight file copy with no .git of its own), git happily
 * resolves to whatever ANCESTOR repo it finds instead, and every command
 * below would silently scan THAT unrelated repo's history/file list rather
 * than $root's. Caught live: the staged tree's own parent directory
 * (newui-dev/) is itself a checkout of the LEGACY tickets v3.44 repo, so a
 * naive `git log --all` from inside the staged tree returned real March-2026
 * commits from that entirely different, unrelated project. Verify the
 * discovered toplevel actually IS $root before trusting anything git says;
 * fall back to a plain filesystem walk for the tracked-file half rather than
 * silently scanning (or silently skipping) the wrong tree. */
[$topCode, $topOut] = ai_run(['git', 'rev-parse', '--show-toplevel'], $root);
$gitRootMatches = false;
if ($topCode === 0) {
    $discovered = realpath(trim($topOut));
    $gitRootMatches = ($discovered !== false && $discovered === realpath($root));
}
// --no-git only ever meant "skip the commit-history half" (fixture dirs that
// may not be a real repo at all) — it must not change how the SEPARATE
// tracked-file half decides between git ls-files and a filesystem walk.

// ── half 1: commit-message history ──────────────────────────────────
// Zero tolerance — every commit reachable from HEAD, no baseline. Uses NUL
// (\x00) between commits and a rare unit separator (\x1f) between hash and
// body so a multi-line commit message can never be mistaken for a boundary.
if (!$noGit && $gitRootMatches) {
    [$code, $out] = ai_run(['git', 'log', '--all', '--format=%H%x1f%B%x00'], $root);
    if ($code === 0 && $out !== '') {
        foreach (explode("\x00", $out) as $chunk) {
            $chunk = trim($chunk, "\n");
            if ($chunk === '') { continue; }
            [$hash, $body] = array_pad(explode("\x1f", $chunk, 2), 2, '');
            foreach ($patterns as $label => $re) {
                if (preg_match($re, $body)) {
                    $short = substr($hash, 0, 12);
                    $line  = trim(preg_split('/\R/', $body)[0] ?? '');
                    $findings[] = ['commit-history', $short, $label, $line];
                }
            }
        }
    }
} elseif (!$noGit && !$gitRootMatches) {
    fwrite(STDERR, "ai_attribution_audit: NOTE — $root has no .git of its own; git would have "
        . "resolved to an unrelated ancestor repo, so the commit-history half was skipped rather "
        . "than scanning the wrong tree (use --no-git to silence this note).\n");
}

// ── half 2: tracked-file content ────────────────────────────────────
// Prefer `git ls-files` (respects .gitignore, matches what actually ships)
// when $root really is a git repo; otherwise walk the filesystem directly —
// still correct for a plain file export like the staged public snapshot,
// just without .gitignore awareness (acceptable here: the export step
// itself already excludes the usual noise before this ever runs).
if ($gitRootMatches) {
    [$code, $out] = ai_run(['git', 'ls-files'], $root);
} else {
    $code = 0;
    $paths = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $f) {
        if (!$f->isFile()) { continue; }
        $rel = ltrim(str_replace('\\', '/', substr($f->getPathname(), strlen($root))), '/');
        if (strpos($rel, '.git/') === 0) { continue; }
        $paths[] = $rel;
    }
    $out = implode("\n", $paths);
}
$baselineSet = ai_load_baseline($baseline);
$excludeSet  = array_flip(ai_attribution_self_exclude());
if ($code === 0 && $out !== '') {
    foreach (explode("\n", trim($out)) as $relPath) {
        if ($relPath === '' || isset($excludeSet[$relPath])) { continue; }
        $abs = $root . '/' . $relPath;
        if (!is_file($abs) || filesize($abs) > 2_000_000) { continue; }
        $bin = @file_get_contents($abs);
        if ($bin === false || strpos($bin, "\0") !== false) { continue; } // skip binaries
        $lines = preg_split('/\R/', $bin);
        foreach ($lines as $i => $text) {
            foreach ($patterns as $label => $re) {
                if (!preg_match($re, $text)) { continue; }
                $key = $relPath . ':' . ($i + 1);
                if (isset($baselineSet[$key])) { continue; }
                $findings[] = ['tracked-files', $key, $label, trim($text)];
            }
        }
    }
}

// ── report ───────────────────────────────────────────────────────────
if (empty($findings)) {
    echo "[OK] ai_attribution_audit: no AI-attribution signatures found";
    echo $noGit ? " (commit history not scanned — --no-git)\n" : " in commit history or tracked files.\n";
    exit(0);
}

echo "✗ ai_attribution_audit: " . count($findings) . " finding(s)\n\n";
foreach ($findings as [$where, $loc, $label, $excerpt]) {
    printf("  [%s] %s  (%s)\n      %s\n", $where, $loc, $label, $excerpt);
}
echo "\n";
echo "A commit message finding cannot be fixed after the fact by editing —\n";
echo "  an already-pushed commit needs a follow-up commit; an unpushed one can\n";
echo "  be fixed with: git commit --amend  (or git rebase -i to fix an older one)\n";
echo "A tracked-file finding that is a genuine false positive (the policy being\n";
echo "  documented, not violated) goes in tools/ai_attribution_baseline.txt as\n";
echo "  \"path:line | reason\" — anything else is a real violation to remove.\n";
exit(1);
