<?php
/**
 * Phase 155 — label definitions as code (.github/labels.json + tools/sync-labels.php
 * + .github/workflows/labels.yml).
 *
 * Labels are the part of a decision a reporter can SEE ("planned", "on hold"),
 * so they are a reviewed file. What is proven here:
 *
 *   - the shipped definitions file is valid and defines every label the rest of
 *     the system relies on (the triage bot adds `needs triage`, `live
 *     operations` and the Windows label; the status page reads `planned`,
 *     `on hold`, `confirmed`, `needs reporter`);
 *   - the sync NEVER deletes or renames anything, leaves `create-only` labels
 *     alone once they exist, and changes nothing without --apply;
 *   - an invalid file is refused whole rather than half-applied.
 *
 * The tool runs as a real subprocess through the real command runner against a
 * recording fake gh.
 */

declare(strict_types=1);

require_once __DIR__ . '/_community_test_lib.php';
define('SYNC_LABELS_LIBRARY_ONLY', true);

$root = dirname(__DIR__);
$tool = $root . '/tools/sync-labels.php';
if (!is_file($tool)) ct_skip('tools/sync-labels.php not present');
require_once $tool;

echo "=== Label definitions as code ===\n\n";

// ─────────────────────────────────────────────────────────────────────
echo "-- 1. The shipped definitions file --\n";

$file = $root . '/.github/labels.json';
$doc = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
ct('.github/labels.json exists and is JSON', is_array($doc));
$errors = [];
$wanted = is_array($doc) ? sl_validate($doc, $errors) : [];
ct('it validates (names, colours, 100-character descriptions, modes)', $errors === [], implode('; ', $errors));
$names = array_map(static fn(array $l): string => $l['name'], $wanted);
foreach (['needs triage', 'confirmed', 'live operations', 'planned', 'on hold', 'fixed in main', 'needs reporter'] as $must) {
    ct("it defines `{$must}`", in_array($must, $names, true));
}
// Every label another part of the system adds or reads must exist, or that part
// silently does nothing (a bot cannot add a label the repository lacks).
$tri = (string) file_get_contents($root . '/tools/triage-issue.php');
$labelsTriageAdds = ['needs triage', 'live operations', 'bug', 'enhancement', 'question'];
foreach ($labelsTriageAdds as $l) ct("the triage bot adds `{$l}`, so labels.json defines it", in_array($l, $names, true));
ct('the DEFAULT Windows label the bot adds is defined', in_array('windows/iis', $names, true) && strpos($tri, "'windows/iis'") !== false);
ct('every label in the file has a description a stranger can read (not empty)',
    array_reduce($wanted, static fn(bool $ok, array $l): bool => $ok && strlen(trim($l['description'])) >= 10, true));
$byName = array_column($wanted, null, 'name');
ct('`fixed in main` is a SYNC label (its meaning is redefined here)', ($byName['fixed in main']['mode'] ?? '') === 'sync');
ct('labels whose live definition predates the file are create-only (never overwritten)',
    ($byName['bug']['mode'] ?? '') === 'create-only' && ($byName['windows/iis']['mode'] ?? '') === 'create-only');
ct('no label name is an abbreviation a stranger has to look up (all words are spelled out)',
    array_reduce($names, static fn(bool $ok, string $n): bool => $ok && !preg_match('/\b(wc|ws|na|nr)\b/i', $n), true));

// ─────────────────────────────────────────────────────────────────────
echo "\n-- 2. Validation --\n";

$e = [];
sl_validate(['labels' => []], $e);
ct('an empty list is refused', $e !== []);
$e = [];
sl_validate(['labels' => [['name' => 'a', 'color' => 'zzzzzz', 'description' => 'x']]], $e);
ct('a bad colour is refused', $e !== [] && strpos(implode(' ', $e), 'color') !== false);
$e = [];
sl_validate(['labels' => [['name' => 'a', 'color' => '#ABCDEF', 'description' => 'ok']]], $e);
ct('a colour with a leading # is accepted and normalised', $e === []);
$e = [];
$n = sl_validate(['labels' => [['name' => 'a', 'color' => 'ABCDEF', 'description' => 'ok']]], $e);
ct('...to lower case, no #', ($n[0]['color'] ?? '') === 'abcdef');
$e = [];
sl_validate(['labels' => [['name' => 'a', 'color' => 'abcdef', 'description' => str_repeat('x', 101)]]], $e);
ct('a 101-character description is refused (GitHub allows 100)', $e !== []);
$e = [];
sl_validate(['labels' => [['name' => 'Same', 'color' => 'abcdef', 'description' => 'a'], ['name' => 'same', 'color' => 'abcdef', 'description' => 'b']]], $e);
ct('two names that differ only in case are a duplicate (GitHub treats them as one)', $e !== []);
$e = [];
sl_validate(['labels' => [['name' => 'a', 'color' => 'abcdef', 'description' => 'a', 'mode' => 'delete']]], $e);
ct('an unknown mode is refused (there is no delete mode, and there never will be)', $e !== []);
$e = [];
sl_validate(['labels' => [['name' => '', 'color' => 'abcdef', 'description' => 'a']]], $e);
ct('an empty name is refused', $e !== []);

// ─────────────────────────────────────────────────────────────────────
echo "\n-- 3. The plan --\n";

$w = [
    ['name' => 'planned', 'color' => '1d76db', 'description' => 'Accepted.', 'mode' => 'sync'],
    ['name' => 'on hold', 'color' => 'c5def5', 'description' => 'Paused.', 'mode' => 'sync'],
    ['name' => 'bug', 'color' => 'd73a4a', 'description' => 'Not working.', 'mode' => 'create-only'],
    ['name' => 'confirmed', 'color' => '0e8a16', 'description' => 'Reproduced.', 'mode' => 'sync'],
];
$live = [
    ['name' => 'Planned', 'color' => '1D76DB', 'description' => 'Accepted.'],             // same, different case
    ['name' => 'on hold', 'color' => 'ffffff', 'description' => 'Paused.'],               // colour drifted
    ['name' => 'bug', 'color' => '000000', 'description' => 'hand-tuned, different'],      // create-only
    ['name' => 'wontfix', 'color' => 'ffffff', 'description' => ''],                       // not in the file
];
$p = sl_plan($w, $live);
ct('a missing label is created', count($p['create']) === 1 && $p['create'][0]['name'] === 'confirmed');
ct('a drifted SYNC label is edited', count($p['edit']) === 1 && $p['edit'][0]['name'] === 'on hold');
ct('a matching label (case-insensitive name, hex case ignored) is unchanged', $p['unchanged'] === ['planned']);
ct('a CREATE-ONLY label that exists is left alone even though it differs', $p['left_alone'] === ['bug']);
ct('a label the file does not mention is reported, never touched', $p['unmanaged'] === ['wontfix']);
ct('an edit uses the LIVE spelling of the name', ($sl = sl_plan([['name' => 'Planned', 'color' => '000000', 'description' => 'x', 'mode' => 'sync']], [['name' => 'planned', 'color' => '1d76db', 'description' => 'x']])) && ($sl['edit'][0]['live_name'] ?? '') === 'planned');

// ─────────────────────────────────────────────────────────────────────
echo "\n-- 4. The real tool, through the real command runner --\n";

$labelDoc = ct_json_file('labels.json', ['labels' => $w]);
$liveJson = json_encode($live);
$rules = [
    ['match' => 'repos/o/r/labels', 'out' => $liveJson],
    ['match' => 'label create', 'out' => ''],
    ['match' => 'label edit', 'out' => ''],
];
$run = static function (array $args, ?array $rulesOverride = null, ?string $fileOverride = null) use ($tool, $rules, $labelDoc): array {
    $g = ct_fake_gh($rulesOverride ?? $rules);
    $r = ct_php($tool, array_merge(['--file=' . ($fileOverride ?? $labelDoc), '--repo=o/r'], $args), $g['env']);
    return $r + ['log' => $g['log']];
};

$r = $run([]);
ct('without --apply nothing is created or edited', ct_calls_matching($r['log'], 'label create') === [] && ct_calls_matching($r['log'], 'label edit') === [] && $r['code'] === 0);
ct('and it says what it WOULD do', strpos($r['out'], 'would create  confirmed') !== false && strpos($r['out'], 'would edit    on hold') !== false, trim($r['out']));

$r = $run(['--apply']);
$creates = ct_calls_matching($r['log'], 'label create');
$edits = ct_calls_matching($r['log'], 'label edit');
ct('--apply creates the missing label with its colour and description',
    count($creates) === 1 && $creates[0]['args'][2] === 'confirmed' && in_array('0e8a16', $creates[0]['args'], true) && in_array('Reproduced.', $creates[0]['args'], true),
    json_encode($creates));
ct('--apply edits only the drifted one', count($edits) === 1 && $edits[0]['args'][2] === 'on hold');
ct('it NEVER issues a delete (no call mentions "delete")', ct_calls_matching($r['log'], 'delete') === []);
ct('the create-only label and the unmanaged one were never named in a write',
    ct_calls_matching($r['log'], 'label create bug') === [] && ct_calls_matching($r['log'], 'label edit bug') === []
    && ct_calls_matching($r['log'], 'wontfix') === []);
ct('exit 0', $r['code'] === 0);

$failing = $rules;
$failing[1] = ['match' => 'label create', 'out' => '', 'err' => 'HTTP 403', 'code' => 1];
$r = $run(['--apply'], $failing);
ct('a failed create makes the run exit 1 and says which', $r['code'] === 1 && strpos($r['out'], 'FAILED create confirmed') !== false);

$r = $run(['--apply', '--json']);
$j = json_decode($r['out'], true);
ct('--json reports the plan and what was applied', is_array($j) && ($j['applied']['created'] ?? null) === ['confirmed'] && ($j['applied']['edited'] ?? null) === ['on hold']);

$bad = ct_json_file('labels-bad.json', ['labels' => [['name' => 'a', 'color' => 'nope', 'description' => 'x']]]);
$r = $run(['--apply'], null, $bad);
ct('an invalid file is refused (exit 2) and NOTHING is sent to gh, not even a read', $r['code'] === 2 && ct_calls($r['log']) === []);
$r = $run(['--apply'], null, ct_tmp() . '/missing.json');
ct('a missing file is exit 2', $r['code'] === 2);

$none = [['match' => 'repos/o/r/labels', 'out' => '', 'err' => 'HTTP 502', 'code' => 1]];
$r = $run(['--apply'], $none);
ct('if the live labels cannot be read, nothing is changed (exit 1)', $r['code'] === 1 && ct_calls_matching($r['log'], 'label create') === []);

$g = ct_fake_gh($rules);
ct('an unknown option is exit 2', ct_php($tool, ['--wipe-everything', '--repo=o/r'], $g['env'])['code'] === 2);
ct('a bad repository name is exit 2', ct_php($tool, ['--repo=nope', '--file=' . $labelDoc], $g['env'])['code'] === 2);

// The workflow wires the tool: an unticked manual run must be a dry run.
$wf = (string) @file_get_contents($root . '/.github/workflows/labels.yml');
ct('the workflow runs the tool, and ONLY applies on a push or a ticked manual run',
    strpos($wf, 'php tools/sync-labels.php --apply') !== false && strpos($wf, "github.event_name == 'push' || inputs.apply") !== false
    && preg_match('/else\s+php tools\/sync-labels\.php\s*\n/', $wf) === 1);

ct_finish();
