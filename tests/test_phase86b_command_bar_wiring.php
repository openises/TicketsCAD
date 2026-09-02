<?php
/**
 * Phase 86b — command-bar wiring for the new /major, /road, /radio and
 * settings-deep-link commands (post 5-persona review,
 * specs/phase-86b-command-bar/changes.md).
 *
 * This file exists because the first draft of this change shipped two
 * classes of broken link that a plain "does the code look right" read
 * missed: (1) two handler functions (doToggleRoadConditions/doToggleRadio)
 * were referenced before they were defined, and (2) all six settings
 * anchor hashes were guessed (#users, #training-records, #zones, ...)
 * instead of read from the real _cfg_tab() call sites in
 * inc/config-sidebar.php — none of the six guesses matched. Every
 * assertion below cross-checks command-bar.js against the ACTUAL other
 * file it depends on, not against a remembered/assumed value.
 *
 * Usage: php tests/test_phase86b_command_bar_wiring.php
 */

$base = realpath(__DIR__ . '/..');
$passed = 0; $failed = 0;
function t($label, $cond) { global $passed, $failed; echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n"; $cond ? $passed++ : $failed++; }

echo "=== Phase 86b command-bar wiring ===\n\n";

$cb = @file_get_contents($base . '/assets/js/command-bar.js');
if ($cb === false) { t('assets/js/command-bar.js readable', false); echo "\n=== $passed passed, $failed failed ===\n"; exit(1); }

// --- 1. Every referenced handler function is actually defined in this file ---
foreach (['doToggleRoadConditions', 'doToggleRadio'] as $fn) {
    t("handler {$fn}() is used as a command handler",
        (bool) preg_match('/handler:\s*' . preg_quote($fn, '/') . '\b/', $cb));
    t("handler {$fn}() is defined (function declaration exists)",
        (bool) preg_match('/function\s+' . preg_quote($fn, '/') . '\s*\(/', $cb));
}

// --- 2. /major routes to the real major-incidents.php, which must exist ---
t("'/major' command routes to major-incidents.php",
    (bool) preg_match("/name:\s*'major'.*?go\('major-incidents\.php'\)/s", $cb));
t('major-incidents.php exists', is_file($base . '/major-incidents.php'));

// --- 3. road/radio handlers target the REAL control elements, verified
//        against the actual markup files that render them ---
t('doToggleRoadConditions() targets .ctrl-btn[data-action="road-conditions"]',
    (bool) preg_match('/doToggleRoadConditions[\s\S]{0,300}?data-action="road-conditions"/', $cb));
$indexPhp = @file_get_contents($base . '/index.php');
t('index.php actually renders a data-action="road-conditions" control (the target this handler clicks)',
    $indexPhp !== false && strpos($indexPhp, 'data-action="road-conditions"') !== false);

t('doToggleRadio() targets [data-action="radio"]',
    (bool) preg_match('/doToggleRadio[\s\S]{0,300}?data-action="radio"/', $cb));
$navbarPhp = @file_get_contents($base . '/inc/navbar.php');
t('inc/navbar.php (loaded globally) actually renders a data-action="radio" control',
    $navbarPhp !== false && strpos($navbarPhp, 'data-action="radio"') !== false);

// --- 4. Settings deep-link hashes must equal the REAL _cfg_tab() slug,
//        read live from inc/config-sidebar.php — not a remembered guess.
//        config.js's activateTab(tab) does getElementById('panel-' + tab)
//        itself, so the hash must be the bare slug with NO 'panel-' prefix. ---
$sidebar = @file_get_contents($base . '/inc/config-sidebar.php');
t('inc/config-sidebar.php readable', $sidebar !== false);

preg_match_all("/_cfg_tab\('([a-zA-Z0-9_-]+)'/", (string) $sidebar, $sm);
$realSlugs = $sm[1] ?? [];
t('found at least one real _cfg_tab() slug to check against', count($realSlugs) > 0);

$expectedHashes = [
    'users'         => 'user-accounts',
    'audit'         => 'audit-log',
    'types'         => 'incident-types',
    'organizations' => 'organizations',
    'training'      => 'training',
    'zones'         => 'alert-zones',
];
foreach ($expectedHashes as $cmdName => $slug) {
    t("real _cfg_tab() slug list contains '{$slug}' (target of /{$cmdName})", in_array($slug, $realSlugs, true));

    preg_match("/name:\s*'" . preg_quote($cmdName, '/') . "'.*?go\('([^']+)'\)/s", $cb, $hm);
    $actualTarget = $hm[1] ?? null;
    t("/{$cmdName} command's go() target is exactly 'settings.php#{$slug}'",
        $actualTarget === "settings.php#{$slug}");

    // Never allow the double-prefixed mistake this test exists to catch.
    t("/{$cmdName} command's hash is NOT double-prefixed with 'panel-'",
        $actualTarget !== null && strpos($actualTarget, '#panel-') === false);
}

// --- 5. /password targets profile.php's own real hash map entry ---
$profilePhp = @file_get_contents($base . '/profile.php');
t("profile.php has its own '#password' => 'tab-password' hash-map entry",
    $profilePhp !== false && strpos($profilePhp, "'#password': 'tab-password'") !== false);
preg_match("/name:\s*'password'.*?go\('([^']+)'\)/s", $cb, $pm);
t("/password command's go() target is exactly 'profile.php#password'",
    ($pm[1] ?? null) === 'profile.php#password');

// --- 6. Deliberately-deferred commands (see changes.md) must NOT be
//        wired to a page that doesn't exist yet — either the command is
//        absent, or its target file is real. Prevents silently
//        reintroducing a broken link for /who or /find without updating
//        this test. ---
foreach (['who' => 'current-logins.php', 'find' => 'find.php'] as $cmdName => $targetFile) {
    $isWired = (bool) preg_match("/name:\s*'" . preg_quote($cmdName, '/') . "'/", $cb);
    if ($isWired) {
        t("/{$cmdName} is wired, so its target {$targetFile} must actually exist",
            is_file($base . '/' . $targetFile));
    } else {
        t("/{$cmdName} is correctly NOT wired yet (deferred per changes.md)", true);
    }
}

// --- 7. No name/alias collisions anywhere in the COMMANDS array ---
preg_match_all("/name:\s*'([a-zA-Z0-9_-]+)'/", $cb, $nm);
$names = $nm[1];
t('COMMANDS array has at least the expected new entries',
    count(array_intersect(['major', 'road', 'radio', 'users', 'audit', 'types', 'organizations', 'password', 'training', 'zones'], $names)) === 10);
t('no duplicate command names in the COMMANDS array', count($names) === count(array_unique($names)));

echo "\n=== $passed passed, $failed failed ===\n";
exit($failed === 0 ? 0 : 1);
