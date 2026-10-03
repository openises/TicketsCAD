<?php
/**
 * Phase 155 — the community-responsiveness documents, templates and settings agree
 * with the code that reads them.
 *
 * WHY THESE ARE TESTS
 *
 *   This project's recurring failure is a thing asserted in one place and not
 *   true in another: a settings key with a UI and no reader, a doc sentence in
 *   the present tense describing something that does not exist, JS reading a key
 *   no endpoint emits. The community machinery has four places where that could
 *   happen, so each is checked against the real thing:
 *
 *     1. the issue FORM's question labels  <->  the strings the triage parser keys on
 *     2. the stated response targets (SUPPORT.md, status page)  <->  the settings' defaults
 *     3. every environment setting a tool reads  <->  the table that documents it
 *     4. every label the docs describe  <->  .github/labels.json
 *
 *   Plus: what ships publicly must not contain the strings the release scan
 *   rejects, and user-facing text must never mention the private/public split.
 */

declare(strict_types=1);

require_once __DIR__ . '/_community_test_lib.php';
define('TRIAGE_LIBRARY_ONLY', true);
define('STATUS_ISSUE_LIBRARY_ONLY', true);

$root = dirname(__DIR__);
foreach (['tools/triage-issue.php', 'tools/status-issue.php'] as $need) {
    if (!is_file($root . '/' . $need)) ct_skip("{$need} not present");
}
require_once $root . '/tools/triage-issue.php';
require_once $root . '/tools/status-issue.php';

echo "=== Community documents, templates and settings ===\n\n";

$read = static fn(string $rel): string => (string) @file_get_contents($root . '/' . $rel);

// ─────────────────────────────────────────────────────────────────────
echo "-- 1. The issue forms and the triage parser agree on the question text --\n";

$bug = $read('.github/ISSUE_TEMPLATE/bug_report.yml');
$labelOf = static function (string $yml, string $id): ?string {
    if (!preg_match('/^\s+id:\s*' . preg_quote($id, '/') . '\s*\n\s+attributes:\s*\n\s+label:\s*(.+?)\s*$/m', $yml, $m)) return null;
    return trim($m[1], " \t\"'");
};
$installLabel = $labelOf($bug, 'install-method');
$liveLabel = $labelOf($bug, 'live-operations');
ct('the bug form has an install-method question', $installLabel !== null, 'label not found');
ct('the bug form has the live-operations question', $liveLabel !== null, 'label not found');
ct('the install-method question\'s heading is EXACTLY what the parser keys on', $installLabel !== null && tr_norm($installLabel) === 'how is it installed?', (string) $installLabel);
ct('the live-operations question\'s heading is EXACTLY what the parser keys on', $liveLabel !== null && tr_norm($liveLabel) === 'is this affecting live operations right now?', (string) $liveLabel);

// Build the issue body the way GitHub renders a form: "### <label>\n\n<answer>".
$optionsOf = static function (string $yml, string $id): array {
    if (!preg_match('/^\s+id:\s*' . preg_quote($id, '/') . '\s*\n(?:.*\n)*?\s+options:\s*\n((?:\s+-\s+.+\n)+)/m', $yml, $m)) return [];
    preg_match_all('/^\s+-\s+(.+?)\s*$/m', $m[1], $o);
    return $o[1];
};
$installOptions = $optionsOf($bug, 'install-method');
$liveOptions = $optionsOf($bug, 'live-operations');
ct('the install-method form offers a Windows option', (bool) array_filter($installOptions, static fn(string $o): bool => stripos($o, 'windows') !== false), json_encode($installOptions));
ct('the live-operations form\'s FIRST option starts with "Yes" and the others do not (the parser reads "Yes" as live)',
    $liveOptions !== [] && preg_match('/^yes\b/i', $liveOptions[0]) === 1 && count(array_filter(array_slice($liveOptions, 1), static fn(string $o): bool => preg_match('/^yes\b/i', $o) === 1)) === 0, json_encode($liveOptions));

$cfg = tr_config();
$tpl = $read('.github/triage/acknowledgement.md');
$render = static function (string $install, string $live) use ($installLabel, $liveLabel): string {
    return "### Which version are you running?\n\n4.2.27\n\n### {$installLabel}\n\n{$install}\n\n### {$liveLabel}\n\n{$live}\n";
};
$plan = static function (string $body) use ($cfg, $tpl): array {
    return tr_plan(['action' => 'opened', 'issue' => ['number' => 1, 'title' => '[Bug]: x', 'body' => $body, 'labels' => [['name' => 'bug']],
        'user' => ['login' => 'a', 'type' => 'User'], 'author_association' => 'NONE']], $cfg, $tpl, '2026-10-02');
};
foreach ($installOptions as $opt) {
    $isWin = stripos($opt, 'windows') !== false;
    $labels = $plan($render($opt, (string) ($liveOptions[1] ?? 'No')))['labels'];
    ct("form option \"{$opt}\" " . ($isWin ? 'earns' : 'does not earn') . ' the Windows label', in_array($cfg['windows_label'], $labels, true) === $isWin, json_encode($labels));
}
foreach ($liveOptions as $i => $opt) {
    $labels = $plan($render((string) ($installOptions[0] ?? 'Docker'), $opt))['labels'];
    ct("form option \"{$opt}\" " . ($i === 0 ? 'earns' : 'does not earn') . ' the live-operations label', in_array('live operations', $labels, true) === ($i === 0), json_encode($labels));
}
ct('the bug form has the optional git-commit question (the version number alone stopped identifying a build)',
    strpos($bug, 'id: git-commit') !== false && strpos($bug, "git log -1 --format='%h %cd'") !== false);
ct('...and it is optional (a Docker or ZIP install has no commit)', !preg_match('/id:\s*git-commit(?:.*\n)*?\s+validations:\s*\n\s+required:\s*true/m', explode('id: install-method', $bug)[0]));

$question = $read('.github/ISSUE_TEMPLATE/question.yml');
ct('the question form no longer claims there is "no separate forum" (there is the Google Group)', stripos($question, 'no separate forum') === false && strpos($question, 'groups.google.com/g/open-source-cad') !== false);
$config = $read('.github/ISSUE_TEMPLATE/config.yml');
ct('the issue chooser links the Google Group and SUPPORT.md', strpos($config, 'groups.google.com/g/open-source-cad') !== false && strpos($config, 'blob/main/SUPPORT.md') !== false);
ct('...and still routes security reports to the PRIVATE channel first', strpos($config, 'security/advisories/new') !== false && strpos($config, 'blank_issues_enabled: false') !== false);
ct('every contact link is https', preg_match_all('/^\s+url:\s*(\S+)/m', $config, $um) > 0 && count(array_filter($um[1], static fn(string $u): bool => strpos($u, 'https://') !== 0)) === 0);

// ─────────────────────────────────────────────────────────────────────
echo "\n-- 2. Stated response targets match the settings' defaults --\n";

$support = $read('SUPPORT.md');
ct('SUPPORT.md exists', $support !== '');
ct('the first-reply target SUPPORT.md states is the triage default', preg_match('/aimed at within (\d+) days/', $support, $m) === 1 && (int) $m[1] === $cfg['target_days'], 'states ' . ($m[1] ?? '?') . ', default ' . $cfg['target_days']);
$siDefaults = si_config();
ct('the status-line cadence SUPPORT.md states is the one the status page and the inbox use', preg_match('/at least every (\d+) days/', $support, $m2) === 1 && (int) $m2[1] === $siDefaults['aging_days'], 'states ' . ($m2[1] ?? '?') . ', default ' . $siDefaults['aging_days']);
ct('the pull-request target is the SAME number', preg_match('/acknowledged within the same (\d+) days/', $support, $m3) === 1 && (int) $m3[1] === $cfg['target_days']);
ct('it is honest that this is a target and not a guarantee, consistent with GOVERNANCE.md',
    stripos($support, 'not a guarantee') !== false && stripos($support, 'no guaranteed response time') !== false);
ct('it keeps the security commitment SECURITY.md makes (3 business days, 10)', strpos($support, '3 business days') !== false && strpos($support, 'within 10') !== false
    && strpos($read('SECURITY.md'), '3 business days') !== false);
if (is_file($root . '/tools/community-watch.php')) {
    define('COMMUNITY_WATCH_LIBRARY_ONLY', true);
    require_once $root . '/tools/community-watch.php';
    foreach (array_keys(getenv()) as $k) if (preg_match('/^COMMUNITY_/', (string) $k)) putenv((string) $k);
    ct('the inbox\'s aging threshold default equals the cadence promised publicly', cw_config()['aging_days'] === $siDefaults['aging_days']);
}
ct('the README has a Get help section that links SUPPORT.md and the Google Group', preg_match('/^## Get help$/m', $read('README.md')) === 1
    && strpos($read('README.md'), '(SUPPORT.md)') !== false && strpos($read('README.md'), 'groups.google.com/g/open-source-cad') !== false);
ct('docs/INDEX.md links both documents', strpos($read('docs/INDEX.md'), '(../SUPPORT.md)') !== false && strpos($read('docs/INDEX.md'), '(COMMUNITY-OPERATIONS.md)') !== false);

// Every relative link in the new documents resolves.
$broken = [];
foreach (['SUPPORT.md' => '', 'docs/COMMUNITY-OPERATIONS.md' => 'docs/', 'docs/RELEASE-PROCESS.md' => 'docs/'] as $doc => $base) {
    preg_match_all('/\]\(([^)#\s]+)(?:#[^)]*)?\)/', $read($doc), $lm);
    foreach ($lm[1] as $link) {
        if (preg_match('#^[a-z]+://|^mailto:#i', $link)) continue;
        $target = realpath($root . '/' . $base . $link);
        if ($target === false) $broken[] = "{$doc} -> {$link}";
    }
}
ct('every relative link in SUPPORT.md, COMMUNITY-OPERATIONS.md and RELEASE-PROCESS.md resolves', $broken === [], implode(', ', $broken));

// ─────────────────────────────────────────────────────────────────────
echo "\n-- 3. Every setting a tool reads is documented, and every documented setting is read --\n";

$sources = [];
foreach (array_merge(glob($root . '/tools/*.php') ?: [], glob($root . '/tools/lib/*.php') ?: [], glob($root . '/tools/*.sh') ?: []) as $f) {
    $b = basename($f);
    if (in_array($b, ['triage-issue.php', 'sync-labels.php', 'release-notify.php', 'status-issue.php', 'community-watch.php', 'sync-lag-check.php', 'community.php', 'publish-sync.sh', 'deploy.sh'], true)) {
        $sources[$b] = (string) file_get_contents($f);
    }
}
$ops = $read('docs/COMMUNITY-OPERATIONS.md');
$runnerProvided = ['GITHUB_EVENT_PATH' => 'set by the Actions runner', 'GITHUB_REPOSITORY' => 'set by the Actions runner', 'GITHUB_ACTIONS' => 'set by the Actions runner'];
$read_vars = [];
foreach ($sources as $file => $src) {
    if (substr($file, -4) === '.sh') continue;
    preg_match_all('/(?:cm_env(?:_int|_bool)?|getenv)\(\s*\'([A-Z][A-Z0-9_]{3,})\'/', $src, $vm);
    foreach ($vm[1] as $v) $read_vars[$v][] = $file;
}
ksort($read_vars);
// The public repository does not carry the development-only tools (the inbox, the sync gate),
// so it has fewer settings to find; the floor only guards against the scan going blind.
ct('the tools read a non-trivial set of environment settings', count($read_vars) >= (is_file($root . '/tools/community-watch.php') ? 18 : 10), (string) count($read_vars));
$undocumented = [];
foreach ($read_vars as $v => $files) {
    if (isset($runnerProvided[$v])) continue;
    if (strpos($ops, "`{$v}`") === false) $undocumented[] = $v . ' (' . implode(', ', array_unique($files)) . ')';
}
ct('EVERY environment setting a tool reads is in docs/COMMUNITY-OPERATIONS.md', $undocumented === [], implode('; ', $undocumented));

// Shell-level settings.
foreach (['tools/publish-sync.sh' => ['GH_BIN', 'PUBLISH_SYNC_CI_POLL'], 'tools/deploy.sh' => ['DEPLOY_REQUIRE_SYNCED']] as $file => $vars) {
    if (!is_file($root . '/' . $file)) continue;
    foreach ($vars as $v) {
        ct("{$v} (read by {$file}) is documented", strpos($ops, $v) !== false);
    }
}

// Reverse: a documented variable must be read by something that exists.
if (is_file($root . '/tools/community-watch.php') && is_file($root . '/tools/sync-lag-check.php')) {
    preg_match_all('/^\|\s*`([A-Z][A-Z0-9_]{3,})`\s*\|/m', $ops, $dm);
    $phantom = [];
    $all = implode("\n", $sources);
    foreach (array_unique($dm[1]) as $v) {
        if (strpos($all, "'{$v}'") === false) $phantom[] = $v;
    }
    ct('every variable in the settings table is read by a tool (no phantom setting)', $phantom === [], implode(', ', $phantom));
    ct('the table lists a substantial set', count(array_unique($dm[1])) >= 20, (string) count(array_unique($dm[1])));
} else {
    echo "NOTE: the development-only tools are not in this tree; the phantom-setting half of this check runs in the development tree\n";
}

// ─────────────────────────────────────────────────────────────────────
echo "\n-- 4. Labels described in the docs exist --\n";

$labelsDoc = json_decode($read('.github/labels.json'), true);
$defined = array_map(static fn(array $l): string => $l['name'], (array) ($labelsDoc['labels'] ?? []));
$section = (string) preg_replace('/^.*?\n## Labels\n/s', '', $ops, 1);
$section = (string) preg_replace('/\n## .*$/s', '', $section, 1);
preg_match_all('/^\|\s*`([^`]+)`\s*\|/m', $section, $lm);
ct('the docs\' Labels table is found', count($lm[1]) >= 5, json_encode($lm[1]));
$missing = array_values(array_diff($lm[1], $defined));
ct('every label the docs describe is defined in .github/labels.json', $missing === [], implode(', ', $missing));
$notDocumented = array_values(array_diff(['needs triage', 'confirmed', 'live operations', 'planned', 'on hold', 'fixed in main', 'needs reporter'], $lm[1]));
ct('every label this phase adds is explained in the docs', $notDocumented === [], implode(', ', $notDocumented));

// ─────────────────────────────────────────────────────────────────────
echo "\n-- 5. Wording: what ships publicly --\n";

$userFacing = ['SUPPORT.md', '.github/triage/acknowledgement.md', '.github/status-issue-template.md', '.github/ISSUE_TEMPLATE/bug_report.yml',
               '.github/ISSUE_TEMPLATE/question.yml', '.github/ISSUE_TEMPLATE/config.yml'];
foreach ($userFacing as $f) {
    $t = $read($f);
    ct("{$f}: never mentions the private/public split, a snapshot, or a development tree",
        $t !== '' && !preg_match('/\b(snapshot|dev(elopment)? (repo|tree)|private (repo|repository|tree)|public repo(sitory)? is)\b/i', $t));
    ct("{$f}: makes no promise of a fix, a date or an offer", !preg_match('/\b(we will fix|will be fixed|guarantee[ds]? a|happy to (build|add))\b/i', $t));
}
ct('the acknowledgement points at files that exist (SECURITY.md, docs/SUPPORT-PATTERNS.md, the System Health page)',
    is_file($root . '/SECURITY.md') && is_file($root . '/docs/SUPPORT-PATTERNS.md') && is_file($root . '/status.php')
    && strpos($tpl, 'SECURITY.md') !== false && strpos($tpl, 'docs/SUPPORT-PATTERNS.md') !== false && strpos($tpl, 'status.php') !== false);

// The release scan's own pattern list, when this tree has the script.
$snap = $read('tools/release-snapshot.sh');
if (preg_match("/^PATTERNS='(.*)'\$/m", $snap, $pm)) {
    $re = '/' . str_replace('/', '\/', $pm[1]) . '/i';
    $shipped = ['SUPPORT.md', 'docs/COMMUNITY-OPERATIONS.md', 'docs/RELEASE-PROCESS.md', 'docs/SUPPORT-PATTERNS.md', 'README.md',
                'tools/triage-issue.php', 'tools/sync-labels.php', 'tools/release-notify.php', 'tools/status-issue.php', 'tools/lib/community.php',
                'tests/_community_test_lib.php', 'tests/_fake_gh.php', 'tests/test_triage_issue.php', 'tests/test_sync_labels.php',
                'tests/test_release_notify.php', 'tests/test_status_issue.php', 'tests/test_github_workflows_safety.php', 'tests/test_community_docs.php',
                'tests/test_sync_lag_check.php', 'tests/test_publish_sync.php', 'tests/test_community_watch.php'];
    foreach (array_merge($userFacing, glob($root . '/.github/workflows/*.yml') ?: [], ['.github/labels.json']) as $f) $shipped[] = str_replace($root . '/', '', str_replace('\\', '/', $f));
    $hits = [];
    foreach (array_unique($shipped) as $f) {
        if (strpos($f, 'community-watch') !== false || $f === 'tests/test_community_watch.php' || $f === 'tests/test_sync_lag_check.php' || $f === 'tests/test_publish_sync.php') {
            // These ship too (they self-skip without their tool), so they are scanned like the rest.
        }
        $t = $read($f);
        if ($t !== '' && preg_match($re, $t, $hm)) $hits[] = "{$f} ({$hm[0]})";
    }
    ct('nothing that ships contains a string the release scan rejects (a beta tester\'s name, an internal host, the dev repository\'s name)', $hits === [], implode('; ', $hits));
} else {
    echo "NOTE: tools/release-snapshot.sh is not in this tree; the release-scan check runs in the development tree\n";
}

// ─────────────────────────────────────────────────────────────────────
echo "\n-- 6. The maintainer-facing documents describe what exists --\n";

$rp = $read('docs/RELEASE-PROCESS.md');
foreach (['Dev-Commit', 'Fixes-Included', 'publish-sync.sh', 'sync-lag-check.php', '--issue=', 'DEPLOY_REQUIRE_SYNCED', 'newer of'] as $needle) {
    ct("RELEASE-PROCESS.md covers `{$needle}`", strpos($rp, $needle) !== false);
}
$sp = $read('docs/SUPPORT-PATTERNS.md');
ct('SUPPORT-PATTERNS.md carries the "fix is not there" row, the "no reply" row and the closed-issue row, and the gate rule',
    strpos($sp, 'I did a `git pull` and the fix isn\'t there') !== false && strpos($sp, 'Nobody has replied to me') !== false
    && strpos($sp, 'already closed') !== false && strpos($sp, 'Before any comment says a fix is available') !== false);
ct('...and the gate it names is a real option of the real tool', is_file($root . '/tools/sync-lag-check.php') ? strpos($read('tools/sync-lag-check.php'), "'issue' => null") !== false : true);

ct_finish();
