<?php
/**
 * sync-labels.php — make the repository's labels match .github/labels.json.
 *
 * Labels are state the community can SEE (a reporter reads "planned" or "on
 * hold" and learns where their request stands), so they are kept as a reviewed
 * file rather than as clicks nobody can reproduce. The triage bot only ever
 * ADDS labels that already exist; this tool is what makes them exist.
 *
 * ── WHAT IT WILL AND WILL NOT DO ─────────────────────────────────────
 *
 *   creates   a label in the file that the repository lacks;
 *   edits     colour/description of a `sync` label that has drifted;
 *   leaves    a `create-only` label alone once it exists (its live definition
 *             may have been tuned by hand before this file existed);
 *   NEVER     deletes or renames anything. Labels the file does not mention
 *             are counted and reported, not touched. Removing a label is a
 *             maintainer decision made by hand.
 *
 * Dry run by default: with no --apply it prints the plan and changes nothing.
 *
 * ── USAGE ────────────────────────────────────────────────────────────
 *
 *   php tools/sync-labels.php [--file=.github/labels.json] [--repo=owner/name]
 *                             [--apply] [--json]
 *
 * --repo defaults to $GITHUB_REPOSITORY. Needs the GitHub CLI authenticated
 * with issues: write (the Actions token is enough).
 *
 * Exit codes: 0 in sync (or applied); 1 a change failed; 2 bad invocation or
 * an invalid definitions file.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/lib/community.php';

/**
 * Validate a decoded definitions file. Returns the normalised label list and
 * fills $errors; an invalid file is refused whole rather than half-applied.
 *
 * @param array<string,mixed> $doc
 * @param string[] $errors
 * @return array<int,array{name:string,color:string,description:string,mode:string}>
 */
function sl_validate(array $doc, array &$errors): array
{
    $out = [];
    $seen = [];
    if (!isset($doc['labels']) || !is_array($doc['labels']) || $doc['labels'] === []) {
        $errors[] = 'the file has no "labels" list';
        return [];
    }
    foreach ($doc['labels'] as $i => $l) {
        $at = 'labels[' . $i . ']';
        if (!is_array($l)) { $errors[] = "{$at} is not an object"; continue; }
        $name = trim((string) ($l['name'] ?? ''));
        $color = strtolower(ltrim(trim((string) ($l['color'] ?? '')), '#'));
        $desc = (string) ($l['description'] ?? '');
        $mode = (string) ($l['mode'] ?? 'sync');
        if ($name === '' || strlen($name) > 50) $errors[] = "{$at}: name must be 1-50 characters";
        if (!preg_match('/^[0-9a-f]{6}$/', $color)) $errors[] = "{$at} ({$name}): color must be six hex digits";
        if (strlen($desc) > 100) $errors[] = "{$at} ({$name}): description is " . strlen($desc) . ' characters; GitHub allows 100';
        if (!in_array($mode, ['sync', 'create-only'], true)) $errors[] = "{$at} ({$name}): mode must be sync or create-only";
        $key = strtolower($name);                       // GitHub treats names case-insensitively
        if ($key !== '' && isset($seen[$key])) $errors[] = "{$at}: duplicate label name {$name}";
        $seen[$key] = true;
        $out[] = ['name' => $name, 'color' => $color, 'description' => $desc, 'mode' => $mode];
    }
    return $out;
}

/**
 * What would bring the repository in line with the file?
 *
 * @param array<int,array{name:string,color:string,description:string,mode:string}> $wanted
 * @param array<int,array<string,mixed>> $live rows from the labels API
 * @return array{create:array<int,array<string,string>>,edit:array<int,array<string,string>>,
 *               unchanged:string[],left_alone:string[],unmanaged:string[]}
 */
function sl_plan(array $wanted, array $live): array
{
    $byName = [];
    foreach ($live as $row) {
        if (is_array($row) && isset($row['name'])) $byName[strtolower((string) $row['name'])] = $row;
    }
    $plan = ['create' => [], 'edit' => [], 'unchanged' => [], 'left_alone' => [], 'unmanaged' => []];
    $managed = [];
    foreach ($wanted as $w) {
        $key = strtolower($w['name']);
        $managed[$key] = true;
        if (!isset($byName[$key])) { $plan['create'][] = $w; continue; }
        if ($w['mode'] === 'create-only') { $plan['left_alone'][] = $w['name']; continue; }
        $row = $byName[$key];
        $sameColor = strtolower(ltrim((string) ($row['color'] ?? ''), '#')) === $w['color'];
        $sameDesc = (string) ($row['description'] ?? '') === $w['description'];
        if ($sameColor && $sameDesc) { $plan['unchanged'][] = $w['name']; continue; }
        // Edit by the live (existing) spelling so a case-only difference does not rename it.
        $w['live_name'] = (string) $row['name'];
        $plan['edit'][] = $w;
    }
    foreach ($byName as $key => $row) {
        if (!isset($managed[$key])) $plan['unmanaged'][] = (string) $row['name'];
    }
    return $plan;
}

if (!defined('SYNC_LABELS_LIBRARY_ONLY')) {
    $opt = cm_parse_args($argv, ['file' => null, 'repo' => null, 'apply' => false, 'json' => false], 'sync-labels');

    $file = is_string($opt['file']) ? $opt['file'] : dirname(__DIR__) . '/.github/labels.json';
    $doc = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
    if (!is_array($doc)) {
        fwrite(STDERR, "sync-labels: cannot read a JSON labels file at {$file}\n");
        exit(2);
    }
    $errors = [];
    $wanted = sl_validate($doc, $errors);
    if ($errors !== []) {
        fwrite(STDERR, "sync-labels: {$file} is invalid, nothing was changed:\n  - " . implode("\n  - ", $errors) . "\n");
        exit(2);
    }

    $repo = cm_valid_slug(is_string($opt['repo']) ? $opt['repo'] : (string) getenv('GITHUB_REPOSITORY'));
    if ($repo === null) {
        fwrite(STDERR, "sync-labels: no valid owner/name (pass --repo= or set GITHUB_REPOSITORY)\n");
        exit(2);
    }

    $why = null;
    $live = cm_gh_api_list("repos/{$repo}/labels?per_page=100", ['--paginate'], $why);
    if ($live === null) {
        fwrite(STDERR, "sync-labels: could not read the repository's labels: {$why}\n");
        exit(1);
    }
    $plan = sl_plan($wanted, $live);

    $failed = [];
    $applied = ['created' => [], 'edited' => []];
    if (cm_flag($opt['apply'])) {
        foreach ($plan['create'] as $w) {
            $r = cm_gh(['label', 'create', $w['name'], '--repo', $repo,
                        '--color', $w['color'], '--description', $w['description']]);
            if ($r['code'] === 0) $applied['created'][] = $w['name'];
            else $failed[] = "create {$w['name']}: " . trim($r['err']);
        }
        foreach ($plan['edit'] as $w) {
            $r = cm_gh(['label', 'edit', $w['live_name'], '--repo', $repo,
                        '--color', $w['color'], '--description', $w['description']]);
            if ($r['code'] === 0) $applied['edited'][] = $w['name'];
            else $failed[] = "edit {$w['name']}: " . trim($r['err']);
        }
    }

    if (cm_flag($opt['json'])) {
        echo json_encode(['repo' => $repo, 'apply' => cm_flag($opt['apply']), 'plan' => $plan,
                          'applied' => $applied, 'failed' => $failed],
                         JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
    } else {
        $verb = cm_flag($opt['apply']) ? '' : 'would ';
        echo "sync-labels: {$repo}\n";
        foreach ($plan['create'] as $w) echo "  {$verb}create  {$w['name']}\n";
        foreach ($plan['edit'] as $w) echo "  {$verb}edit    {$w['name']}\n";
        echo '  unchanged ', count($plan['unchanged']), ', left alone ', count($plan['left_alone']),
             ', not in the file (untouched) ', count($plan['unmanaged']), "\n";
        if (!cm_flag($opt['apply']) && ($plan['create'] !== [] || $plan['edit'] !== [])) {
            echo "  (dry run: add --apply to make these changes)\n";
        }
        foreach ($failed as $f) echo "  FAILED {$f}\n";
    }
    exit($failed === [] ? 0 : 1);
}
