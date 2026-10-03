<?php
/**
 * No tracked file may link a community address that is known to be dead, and the
 * About page must link the real one.
 *
 * WHY THIS IS A TEST (Phase 155, found by the community-responsiveness build)
 *
 *   about.php sent every user who clicked "Join the Google Group" to a group
 *   address that answers "Content unavailable". The real community group is
 *   open-source-cad; everything else in the tree (SUPPORT.md, README.md, the
 *   issue chooser, sql/run_links.php) already used it. Two places in one file
 *   disagreed with the rest of the project for months because nothing compared
 *   them: each link rendered perfectly well, it simply went nowhere.
 *
 *   A dead link cannot be caught by reading the page, so this test holds a short
 *   LIST of addresses known to be dead (add to it when another is found) and
 *   scans EVERY tracked file for them, so a copy-paste from an old document
 *   cannot bring one back.
 *
 *   The dead address is assembled at run time from pieces so this file does not
 *   contain it and cannot match itself. specs/ is skipped: those files are the
 *   historical record that DESCRIBES the dead address when it was found.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$pass = 0; $fail = 0;
function dc_ok(string $what, bool $cond, string $why = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "[PASS] {$what}\n"; }
    else       { $fail++; echo "[FAIL] {$what}" . ($why !== '' ? " -- {$why}" : '') . "\n"; }
}

echo "=== Dead community addresses ===\n\n";

// Each: [dead address (assembled), what to use instead, why it is dead].
$googleGroups = 'groups.' . 'google.com/g/';
$dead = [
    [$googleGroups . 'tickets' . '-cad', $googleGroups . 'open-source-cad',
     'the group answers "Content unavailable"; the real community group is open-source-cad'],
];

/** Every tracked file (git), or a directory walk when git is not available. */
function dc_tracked_files(string $root): array {
    $out = [];
    $proc = @proc_open(['git', '-C', $root, 'ls-files', '-z'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
    if (is_resource($proc)) {
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]); fclose($pipes[2]);
        $code = proc_close($proc);
        if ($code === 0 && $stdout !== '' && $stdout !== false) {
            foreach (explode("\0", $stdout) as $f) {
                if ($f !== '') $out[] = $f;
            }
            return $out;
        }
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $fi) {
        $rel = str_replace('\\', '/', substr($fi->getPathname(), strlen($root) + 1));
        if (preg_match('#^(\.git|vendor|cache|uploads|node_modules)/#', $rel)) continue;
        if ($fi->isFile()) $out[] = $rel;
    }
    return $out;
}

$files = dc_tracked_files($root);
dc_ok('the scan covers a real tree (more than 500 tracked files)', count($files) > 500, count($files) . ' files');

$binary = '/\.(png|jpe?g|gif|ico|webp|pdf|zip|gz|woff2?|ttf|eot|mp3|wav|mp4|webm|sig|pem|bin|dat|gpkg)$/i';
$hits = [];
foreach ($files as $rel) {
    if (strpos($rel, 'specs/') === 0) continue;            // the historical record describes it
    if ($rel === 'tests/test_dead_community_urls.php') continue;
    if (preg_match($binary, $rel)) continue;
    $path = $root . '/' . $rel;
    if (!is_file($path) || filesize($path) > 8 * 1024 * 1024) continue;
    $body = (string) @file_get_contents($path);
    foreach ($dead as [$bad, $use, $why]) {
        if (stripos($body, $bad) !== false) {
            $hits[] = $rel . '  (use ' . $use . ')';
        }
    }
}
dc_ok('no tracked file links a known-dead community address', $hits === [], implode('; ', $hits));

// The About page is where users actually meet it: both links must be the real group.
$about = (string) @file_get_contents($root . '/about.php');
$real = $googleGroups . 'open-source-cad';
dc_ok('about.php links the real Google Group', substr_count($about, 'https://' . $real) >= 2,
      'expected the sidebar link and the Community card button, found ' . substr_count($about, 'https://' . $real));
foreach ($dead as [$bad, , $why]) {
    dc_ok('about.php no longer links the dead address (' . $why . ')', stripos($about, $bad) === false);
}

// The seeded External Links page entry (sql/run_links.php) must agree with About.
$links = (string) @file_get_contents($root . '/sql/run_links.php');
dc_ok('the seeded external link points at the same group as About', strpos($links, 'https://' . $real) !== false);

echo "\n=== {$pass} passed, {$fail} failed ===\n";
exit($fail > 0 ? 1 : 0);
