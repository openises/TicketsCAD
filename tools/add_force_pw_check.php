<?php
/**
 * One-shot helper for Phase 9: add `force_pw_change_redirect()` to every
 * authenticated page entry after the existing `Location: login.php` check.
 *
 * Run once after deploying inc/force-pw-change.php; this script is
 * idempotent (skips files that already have the call).
 *
 * Files to touch: every .php in the newui root that begins with the
 * "redirect logged-out user" pattern.
 */

$root = realpath(__DIR__ . '/..');
if (!$root) { fwrite(STDERR, "can't resolve root\n"); exit(1); }

// Pages to retrofit. Listed explicitly so we don't accidentally hit
// admin-included partials or unrelated scripts.
$pages = [
    'index.php', 'profile.php', 'new-incident.php', 'roster.php',
    'incident-list.php', 'incident-detail.php',
    'units.php', 'unit-detail.php', 'unit-edit.php',
    'facilities.php', 'facility-detail.php', 'facility-edit.php',
    'facility-board.php', 'teams.php', 'scheduling.php',
    'messaging.php', 'callboard.php', 'constituents.php',
    'search.php', 'sop.php', 'reports.php',
    'help.php', 'about.php', 'quick-start.php', 'links.php',
    'mobile.php', 'ics-forms.php', 'settings.php',
    'status.php', 'status-time.php', 'roles.php',
    'time-approvals.php', 'import-export.php', 'situation.php',
];

// What we want present in each file (string we'll grep for to detect
// "already retrofitted").
$marker = 'force_pw_change_redirect()';

// The insertion block, with the same indentation as the surrounding
// code (no leading whitespace — the file already has a blank line after
// the exit() block where we drop this in).
$insert = "\nrequire_once __DIR__ . '/inc/force-pw-change.php';\nforce_pw_change_redirect();\n";

$touched = 0;
$skipped = 0;
$missing = 0;

foreach ($pages as $rel) {
    $abs = $root . DIRECTORY_SEPARATOR . $rel;
    if (!is_file($abs)) {
        echo "[MISS] $rel — file not found\n";
        $missing++;
        continue;
    }
    $src = file_get_contents($abs);
    if (strpos($src, $marker) !== false) {
        echo "[SKIP] $rel — already has force_pw_change_redirect()\n";
        $skipped++;
        continue;
    }

    // Find the closing `}` of the logged-out check. Match common patterns:
    //   if (empty($_SESSION['user_id'])) {
    //       header('Location: login.php');
    //       exit;
    //   }
    //
    // The pattern uses single quotes around Location string in every file
    // we've seen; if some file uses doubles we'd miss it but that's fine
    // for now (the script reports MISS and we hand-edit).
    $pattern = '/if\s*\(\s*empty\s*\(\s*\$_SESSION\[\s*[\'"]user_id[\'"]\s*\]\s*\)\s*\)\s*\{[^}]*?exit\s*;\s*\}/s';
    if (!preg_match($pattern, $src, $m, PREG_OFFSET_CAPTURE)) {
        echo "[MISS] $rel — couldn't find logged-out check pattern\n";
        $missing++;
        continue;
    }
    $end = $m[0][1] + strlen($m[0][0]);
    $new = substr($src, 0, $end) . $insert . substr($src, $end);
    file_put_contents($abs, $new);
    echo "[OK]   $rel — added force_pw_change_redirect()\n";
    $touched++;
}

echo "\nTouched: $touched, Skipped: $skipped, Missing: $missing\n";
