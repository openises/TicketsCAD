<?php
/**
 * B18 (SPEC-STATUS.md, 2026-08-21) — the dead, unauthenticatable APRS-IS
 * listener is retired.
 *
 * `services/aprs-is/listener.py` (2026-06-13) POSTed to
 * `/api/location.php?action=report` with only a CSRF token and no session.
 * That endpoint requires an authenticated session AND dispatcher/admin RBAC
 * (api/location.php, the `action === 'report'` branch) — a daemon can
 * satisfy neither, so the listener could never actually authenticate. It
 * never ran anywhere. The maintained replacement,
 * `services/aprs/aprs_listener.py` (2026-07-08), writes directly to the
 * database via mysql.connector and has no such gap.
 *
 * This is a pure filesystem/source audit — no database needed.
 *
 * Usage: php tests/test_b18_aprs_is_listener_retired.php
 */

declare(strict_types=1);

$base = realpath(__DIR__ . '/..');

echo "=== B18 — dead APRS-IS listener retired ===\n\n";
$pass = 0;
$fail = 0;
function ok(string $n): void { global $pass; echo "[PASS] $n\n"; $pass++; }
function bad(string $n, string $why = ''): void {
    global $fail; echo "[FAIL] $n" . ($why !== '' ? " — $why" : '') . "\n"; $fail++;
}

// ── 1. The dead directory is gone. ──────────────────────────────────
if (!is_dir($base . '/services/aprs-is')) {
    ok('services/aprs-is/ no longer exists');
} else {
    bad('services/aprs-is/ no longer exists', 'directory still present');
}

// ── 2. The working replacement is still present and unchanged in shape. ──
if (is_file($base . '/services/aprs/aprs_listener.py')) {
    ok('services/aprs/aprs_listener.py (the maintained listener) still exists');
} else {
    bad('services/aprs/aprs_listener.py (the maintained listener) still exists',
        'file missing — this test would also fail the retirement it is meant to prove');
}
if (is_file($base . '/services/aprs/install.sh')
    && is_file($base . '/services/aprs/ticketscad-aprs-listener.service')) {
    ok('services/aprs/install.sh + systemd unit still present');
} else {
    bad('services/aprs/install.sh + systemd unit still present');
}

// The maintained listener writes directly to the database, not through
// api/location.php — confirm it has no HTTP POST to that endpoint (the
// exact shape that made the retired listener unauthenticatable).
$maintained = file_get_contents($base . '/services/aprs/aprs_listener.py');
if ($maintained !== false && strpos($maintained, 'api/location.php') === false) {
    ok('services/aprs/aprs_listener.py does not POST to api/location.php (direct-SQL design)');
} else {
    bad('services/aprs/aprs_listener.py does not POST to api/location.php (direct-SQL design)');
}

// ── 3. No live/deployable path still points at the retired listener. ───
// Scoped to the directories an operator or the deploy pipeline actually
// reads: docs/, services/, tools/, api/, inc/, settings.php, .gitignore.
// Deliberately EXCLUDES specs/ and coordination/ — those are dated,
// historical records (a shipped phase spec, a dated agent handoff note)
// that this project's convention leaves unedited after the fact; they are
// not consulted by any install/deploy path.
$scanTargets = [];
foreach (['docs', 'services', 'tools', 'api', 'inc'] as $dir) {
    $full = $base . '/' . $dir;
    if (!is_dir($full)) continue;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
        $full, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        /** @var SplFileInfo $file */
        if (!$file->isFile()) continue;
        $ext = strtolower($file->getExtension());
        if (!in_array($ext, ['php', 'md', 'sh', 'py', 'yml', 'yaml', 'service', 'example', 'ini'], true)) continue;
        $scanTargets[] = $file->getPathname();
    }
}
$scanTargets[] = $base . '/settings.php';
$scanTargets[] = $base . '/.gitignore';

$thisFile = __FILE__;
$offenders = [];
foreach ($scanTargets as $path) {
    if (realpath($path) === realpath($thisFile)) continue; // this test names it deliberately
    $src = file_get_contents($path);
    if ($src === false) continue;
    if (strpos($src, 'services/aprs-is') !== false) {
        $rel = str_replace($base . '/', '', $path);
        $offenders[] = str_replace('\\', '/', $rel);
    }
}
// The setup guide and the location-providers guide deliberately name the
// retired path ONCE each, in a "here's what used to be here and why it's
// gone" historical note — that is the intended, permanent shape (matching
// how this project documents every other retired-thing lesson), not a
// live pointer telling anyone to deploy it. Allow exactly those two.
$allowedHistoricalNote = [
    'docs/APRS-LISTENER-SETUP.md',
    'docs/LOCATION-PROVIDERS-GUIDE.md',
    'docs/training-scripts/aprs-video-brief.md',
];
$realOffenders = array_values(array_diff($offenders, $allowedHistoricalNote));
t_result($realOffenders, $offenders, $allowedHistoricalNote);

function t_result(array $realOffenders, array $offenders, array $allowed): void {
    if (empty($realOffenders)) {
        ok('no live doc/deploy path (outside the allowed historical notes) still points at services/aprs-is');
    } else {
        bad('no live doc/deploy path (outside the allowed historical notes) still points at services/aprs-is',
            'found in: ' . implode(', ', $realOffenders));
    }
}

// The allowed files must actually still exist and actually still contain
// the note — otherwise the allowlist above is silently doing nothing.
//
// Both docs/training-scripts/ and tools/deploy.sh are release-machinery-
// adjacent files tools/release-snapshot.sh deliberately EXCLUDES from a
// published/public snapshot (the former is dev-only training material, the
// latter describes the private deploy pipeline). Their absence there is by
// design, not a regression — so this test detects "am I running inside a
// staged/public tree" via tools/release-snapshot.sh's own absence (it is
// excluded from every snapshot too) and treats a missing file as
// inconclusive rather than failed in that context only. In the dev tree,
// where release-snapshot.sh always exists, both checks stay fully strict.
$isPublishedSnapshot = !is_file($base . '/tools/release-snapshot.sh');
foreach ($allowedHistoricalNote as $rel) {
    $full = $base . '/' . $rel;
    if (is_file($full) && strpos(file_get_contents($full), 'services/aprs-is') !== false) {
        ok("$rel still carries its historical retirement note");
    } elseif ($isPublishedSnapshot && !is_file($full)) {
        ok("$rel not present in this tree — expected in a published snapshot, skipping content check");
    } else {
        bad("$rel still carries its historical retirement note", 'missing or file gone');
    }
}

// ── 4. tools/deploy.sh never referenced either APRS service by name
//      (confirms nothing there needs updating — a deploy is a full-tree
//      archive extract, not a per-file list). tools/deploy.sh is itself
//      excluded from a published snapshot (see above), so its absence
//      there is expected, not a finding. ─────────────────────────────
$deployPath = $base . '/tools/deploy.sh';
if (!is_file($deployPath)) {
    if ($isPublishedSnapshot) {
        ok('tools/deploy.sh not present in this tree — expected in a published snapshot, skipping content check');
    } else {
        bad('tools/deploy.sh has no APRS-specific wiring to go stale (full-tree deploy)',
            'tools/deploy.sh missing from the dev tree — should always exist there');
    }
} else {
    $deploy = file_get_contents($deployPath);
    if ($deploy !== false && strpos($deploy, 'aprs') === false) {
        ok('tools/deploy.sh has no APRS-specific wiring to go stale (full-tree deploy)');
    } else {
        bad('tools/deploy.sh has no APRS-specific wiring to go stale (full-tree deploy)',
            'deploy.sh now mentions aprs — re-check');
    }
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
