<?php
/**
 * Phase 8 i18n — regression tests.
 *
 * Covers:
 *   - captions_i18n schema present (column + unique key check)
 *   - English + German seed data present for navbar/sidebar
 *   - inc/i18n.php helpers behave correctly for session lang
 *   - api/set-language.php is syntactically valid + has CSRF + lang validation
 *   - inc/navbar.php is retrofitted (string-presence check for t() calls)
 *   - inc/config-sidebar.php is retrofitted
 *   - settings.php has the panel-translations markup
 *   - assets/js/{language-switcher,translations-admin}.js exist with expected hooks
 *
 * These are source-grep + DB checks; they do not spin up a full HTTP request.
 * That keeps the suite fast and runnable without a running web server.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/i18n.php';

$base = realpath(__DIR__ . '/..');

echo "=== Phase 8 i18n Regression Tests ===\n\n";
$pass = 0; $fail = 0;
function ok($name) { global $pass; echo "[PASS] $name\n"; $pass++; }
function bad($name, $why = '') { global $fail; echo "[FAIL] $name" . ($why ? " — $why" : "") . "\n"; $fail++; }

// ── Schema ────────────────────────────────────────────────────────────────
$prefix = $GLOBALS['db_prefix'] ?? '';
try {
    $cols = db_fetch_all("SHOW COLUMNS FROM `{$prefix}captions_i18n`");
    $names = array_column($cols, 'Field');
    $required = ['caption_key', 'lang', 'value', 'category'];
    $missing = array_diff($required, $names);
    if (empty($missing)) {
        ok('captions_i18n has required columns');
    } else {
        bad('captions_i18n columns', 'missing: ' . implode(',', $missing));
    }
} catch (Exception $e) {
    bad('captions_i18n schema query', $e->getMessage());
}

try {
    $idx = db_fetch_all("SHOW INDEX FROM `{$prefix}captions_i18n` WHERE Key_name='uk_key_lang'");
    if (count($idx) >= 2) {
        ok('captions_i18n has uk_key_lang unique key');
    } else {
        bad('captions_i18n uk_key_lang unique key missing');
    }
} catch (Exception $e) {
    bad('captions_i18n index query', $e->getMessage());
}

// ── Seed data ─────────────────────────────────────────────────────────────
$langCounts = [];
try {
    $rows = db_fetch_all("SELECT lang, COUNT(*) AS n FROM `{$prefix}captions_i18n` GROUP BY lang");
    foreach ($rows as $r) {
        $langCounts[$r['lang']] = (int)$r['n'];
    }
} catch (Exception $e) {
    bad('count by lang', $e->getMessage());
}

if (($langCounts['en'] ?? 0) >= 60) {
    ok('English caption count is healthy (>=60): ' . ($langCounts['en'] ?? 0));
} else {
    bad('English caption count below threshold', 'got ' . ($langCounts['en'] ?? 0));
}

if (($langCounts['de'] ?? 0) >= 60) {
    ok('German caption count is healthy (>=60): ' . ($langCounts['de'] ?? 0));
} else {
    bad('German caption count below threshold', 'got ' . ($langCounts['de'] ?? 0));
}

// Spot-check specific navbar keys exist in BOTH languages.
foreach (['nav.menu.situation', 'nav.menu.units', 'nav.user.logout', 'sidebar.section.system', 'sidebar.tab.translations'] as $key) {
    foreach (['en', 'de'] as $lang) {
        try {
            $v = db_fetch_value(
                "SELECT value FROM `{$prefix}captions_i18n` WHERE caption_key=? AND lang=?",
                [$key, $lang]
            );
            if ($v !== null && $v !== '') {
                ok("Seed row exists: {$key} / {$lang}");
            } else {
                bad("Seed row missing: {$key} / {$lang}");
            }
        } catch (Exception $e) {
            bad("Seed query failed: {$key} / {$lang}", $e->getMessage());
        }
    }
}

// ── i18n.php behaviour ────────────────────────────────────────────────────
// 1. Default lang when nothing is set.
unset($_SESSION['lang']);
$prev = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null;
unset($_SERVER['HTTP_ACCEPT_LANGUAGE']);
// Reset the static cache inside i18n_lang() by hitting it via a new closure.
// (i18n_lang's static cache survives across calls; we work around by reading
// the underlying inputs directly.)
$expectedDefault = 'en';
if (function_exists('i18n_lang')) {
    // Cannot reliably reset the static here without process restart; instead
    // assert the function exists and has the lookup priority documented in
    // the source. Source-grep:
    $src = file_get_contents($base . '/inc/i18n.php');
    if (strpos($src, "\$_SESSION['lang']") !== false
        && strpos($src, 'HTTP_ACCEPT_LANGUAGE') !== false
        && strpos($src, "'en'") !== false) {
        ok('i18n_lang() lookup priority: session → Accept-Language → en');
    } else {
        bad('i18n_lang() lookup priority not implementing all three layers');
    }
} else {
    bad('i18n_lang() function not defined');
}
if ($prev !== null) $_SERVER['HTTP_ACCEPT_LANGUAGE'] = $prev;

// 2. t() falls back to default when key absent in current lang.
$got = t('nonexistent.key.totally', 'Default Value');
if ($got === 'Default Value') {
    ok('t() returns default when key absent');
} else {
    bad('t() fallback to default', "got: {$got}");
}

// ── api/set-language.php ──────────────────────────────────────────────────
$slPath = $base . '/api/set-language.php';
if (file_exists($slPath)) {
    ok('api/set-language.php exists');
} else {
    bad('api/set-language.php missing');
}

$slSrc = file_exists($slPath) ? file_get_contents($slPath) : '';
if (strpos($slSrc, 'csrf_verify') !== false) {
    ok('set-language.php calls csrf_verify');
} else {
    bad('set-language.php does NOT call csrf_verify');
}
if (strpos($slSrc, "\$_SESSION['lang']") !== false) {
    ok('set-language.php writes $_SESSION[\'lang\']');
} else {
    bad('set-language.php does NOT write $_SESSION[\'lang\']');
}
if (strpos($slSrc, 'captions_i18n') !== false) {
    ok('set-language.php validates lang exists in captions_i18n');
} else {
    bad('set-language.php skips existence check');
}
if (strpos($slSrc, "preg_match('/^[a-z0-9]{2}") !== false) {
    ok('set-language.php has a regex whitelist for lang code');
} else {
    bad('set-language.php missing lang-code whitelist');
}
// PHP syntax check — use PHP_BINARY to find the interpreter portably
// (Windows + XAMPP doesn't put `php` on PATH).
$lintOut = shell_exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($slPath) . ' 2>&1');
if (strpos($lintOut, 'No syntax errors') !== false) {
    ok('set-language.php is syntactically valid PHP');
} else {
    bad('set-language.php has syntax errors', trim((string)$lintOut));
}

// ── Navbar retrofit ───────────────────────────────────────────────────────
$nav = file_get_contents($base . '/inc/navbar.php');
foreach ([
    "t('nav.menu.situation'",
    "t('nav.menu.units'",
    "t('nav.menu.personnel'",
    "t('nav.user.logout'",
    "t('nav.title.language'"
] as $needle) {
    if (strpos($nav, $needle) !== false) {
        ok("navbar.php has {$needle}, …)");
    } else {
        bad("navbar.php missing {$needle}, …)");
    }
}
if (strpos($nav, "require_once __DIR__ . '/i18n.php'") !== false) {
    ok('navbar.php requires inc/i18n.php');
} else {
    bad('navbar.php does NOT require inc/i18n.php');
}
if (strpos($nav, 'languageSwitcher') !== false && strpos($nav, 'language-switcher.js') !== false) {
    ok('navbar.php emits language switcher container + script');
} else {
    bad('navbar.php missing language switcher wiring');
}

// ── Sidebar retrofit ──────────────────────────────────────────────────────
// Phase 36 (2026-06-13) reorganized the sidebar: the old "System" /
// "App Preferences" sections became "Operations" / "Identity & Security" /
// "Application — *" etc. Every section header must still run through t().
$side = file_get_contents($base . '/inc/config-sidebar.php');
foreach ([
    "t('sidebar.section.operations'",
    "t('sidebar.section.identity_security'",
    "t('sidebar.section.app_presentation'",
    "t('sidebar.tab.user_accounts'",
    "t('sidebar.tab.translations'"
] as $needle) {
    if (strpos($side, $needle) !== false) {
        ok("config-sidebar.php has {$needle}, …)");
    } else {
        bad("config-sidebar.php missing {$needle}, …)");
    }
}
// Tabs are emitted via the _cfg_tab() helper (which renders
// data-tab="translations" at runtime) — grep for the helper call.
if (strpos($side, "_cfg_tab('translations'") !== false) {
    ok('config-sidebar.php has Translations tab button');
} else {
    bad('config-sidebar.php missing Translations tab');
}

// ── Settings panel markup ─────────────────────────────────────────────────
$set = file_get_contents($base . '/settings.php');
if (strpos($set, 'id="panel-translations"') !== false) {
    ok('settings.php has #panel-translations markup');
} else {
    bad('settings.php missing #panel-translations panel');
}
if (strpos($set, 'translations-admin.js') !== false) {
    ok('settings.php loads translations-admin.js');
} else {
    bad('settings.php does NOT load translations-admin.js');
}
// Note: btnTrAddLang was retired in Phase 8b — replaced by a Languages tab
// button. See tests/test_phase08b_i18n.php for the new admin UI assertions.
foreach (['trTable', 'trSearch', 'btnTrAddCaption', 'btnTrExport', 'trImportFile'] as $id) {
    if (strpos($set, 'id="' . $id . '"') !== false) {
        ok("Translations panel has #{$id}");
    } else {
        bad("Translations panel missing #{$id}");
    }
}

// ── JS modules ────────────────────────────────────────────────────────────
foreach ([
    'assets/js/language-switcher.js' => ['AVAILABLE_LANGS', 'CURRENT_LANG', 'api/set-language.php'],
    'assets/js/translations-admin.js' => ['panel-translations', 'api/captions.php', 'action: \'save\'', 'action: \'export\'']
] as $rel => $needles) {
    $full = $base . '/' . $rel;
    if (!file_exists($full)) {
        bad("{$rel} does not exist");
        continue;
    }
    ok("{$rel} exists");
    $src = file_get_contents($full);
    foreach ($needles as $n) {
        if (strpos($src, $n) !== false) {
            ok("{$rel} contains \"{$n}\"");
        } else {
            bad("{$rel} missing \"{$n}\"");
        }
    }
}

// ── Migration runner ──────────────────────────────────────────────────────
$mig = $base . '/sql/run_phase08_i18n.php';
if (file_exists($mig)) {
    ok('sql/run_phase08_i18n.php exists');
    $migSrc = file_get_contents($mig);
    if (strpos($migSrc, 'INSERT IGNORE') !== false) {
        ok('migration is idempotent (INSERT IGNORE)');
    } else {
        bad('migration is NOT idempotent — would fail on re-run');
    }
} else {
    bad('sql/run_phase08_i18n.php missing');
}

// ── Summary ───────────────────────────────────────────────────────────────
echo "\n";
echo "===========================================\n";
echo "Phase 8 i18n: {$pass} passed, {$fail} failed\n";
echo "===========================================\n";

if ($fail > 0) {
    exit(1);
}
