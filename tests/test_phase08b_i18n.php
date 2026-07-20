<?php
/**
 * Phase 8b i18n — regression tests.
 *
 * Covers:
 *   - languages table schema + seed data
 *   - user.preferred_lang column exists
 *   - i18n_default_lang() / i18n_available_langs() / i18n_language_meta() /
 *     i18n_language_registry() helpers
 *   - api/languages.php endpoint structure (file presence, action handlers)
 *   - api/set-language.php now persists to user.preferred_lang
 *   - login.php seeds $_SESSION['lang'] from preferred_lang
 *   - inc/navbar.php emits LANGUAGE_REGISTRY for the switcher
 *   - inc/config-sidebar.php has the Languages tab
 *   - settings.php has the panel-languages markup
 *   - assets/js/languages-admin.js exists with expected hooks
 *
 * Source-grep + DB-introspection style, no HTTP needed.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/i18n.php';

$base   = realpath(__DIR__ . '/..');
$prefix = $GLOBALS['db_prefix'] ?? '';

echo "=== Phase 8b i18n Regression Tests ===\n\n";
$pass = 0; $fail = 0;
function ok($name) { global $pass; echo "[PASS] $name\n"; $pass++; }
function bad($name, $why = '') { global $fail; echo "[FAIL] $name" . ($why ? " — $why" : "") . "\n"; $fail++; }

// ── Schema: languages table ──────────────────────────────────────────────
try {
    $cols = db_fetch_all("SHOW COLUMNS FROM `{$prefix}languages`");
    $names = array_column($cols, 'Field');
    $required = ['code', 'display_name', 'native_name', 'enabled', 'is_default', 'sort_order'];
    $missing = array_diff($required, $names);
    if (empty($missing)) {
        ok('languages table has required columns');
    } else {
        bad('languages columns', 'missing: ' . implode(',', $missing));
    }
} catch (Exception $e) {
    bad('languages SHOW COLUMNS', $e->getMessage());
}

// ── Schema: user.preferred_lang column ───────────────────────────────────
try {
    $r = db_fetch_one(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'preferred_lang'",
        [$prefix . 'user']
    );
    if ($r) {
        ok('user.preferred_lang column exists');
    } else {
        bad('user.preferred_lang column missing');
    }
} catch (Exception $e) {
    bad('preferred_lang column check', $e->getMessage());
}

// ── Registry seed: en + de present, en is default ────────────────────────
try {
    $en = db_fetch_one("SELECT enabled, is_default FROM `{$prefix}languages` WHERE code = ?", ['en']);
    if ($en && (int)$en['enabled'] === 1) {
        ok('en is enabled in registry');
    } else {
        bad('en missing or disabled');
    }
    if ($en && (int)$en['is_default'] === 1) {
        ok('en is the install default');
    } else {
        bad('en is not the install default');
    }
    $de = db_fetch_one("SELECT enabled FROM `{$prefix}languages` WHERE code = ?", ['de']);
    if ($de && (int)$de['enabled'] === 1) {
        ok('de is enabled in registry');
    } else {
        bad('de missing or disabled');
    }
} catch (Exception $e) {
    bad('registry seed check', $e->getMessage());
}

// ── Default constraint: at most one row with is_default=1 ────────────────
try {
    $n = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}languages` WHERE is_default = 1");
    if ($n === 1) {
        ok('exactly one install-default language');
    } else {
        bad('install-default count', "expected 1, got {$n}");
    }
} catch (Exception $e) {
    bad('default-count query', $e->getMessage());
}

// ── i18n.php helpers ─────────────────────────────────────────────────────
if (function_exists('i18n_default_lang')) {
    ok('i18n_default_lang() defined');
    if (i18n_default_lang() === 'en') {
        ok('i18n_default_lang() returns "en"');
    } else {
        bad('i18n_default_lang() returned ' . i18n_default_lang());
    }
} else {
    bad('i18n_default_lang() missing');
}

if (function_exists('i18n_language_meta')) {
    ok('i18n_language_meta() defined');
    $m = i18n_language_meta('de');
    if ($m && $m['display_name'] === 'German') {
        ok('i18n_language_meta("de") returns German entry');
    } else {
        bad('i18n_language_meta("de")', var_export($m, true));
    }
    $m2 = i18n_language_meta('xx');
    if ($m2 === null) {
        ok('i18n_language_meta() returns null for unknown code');
    } else {
        bad('i18n_language_meta("xx") should be null');
    }
} else {
    bad('i18n_language_meta() missing');
}

if (function_exists('i18n_language_registry')) {
    ok('i18n_language_registry() defined');
    $reg = i18n_language_registry();
    if (is_array($reg) && count($reg) >= 2) {
        ok('i18n_language_registry() returns ≥2 entries');
    } else {
        bad('i18n_language_registry() returned fewer than 2 rows');
    }
} else {
    bad('i18n_language_registry() missing');
}

// i18n_available_langs() now reads from the registry, not from
// captions_i18n DISTINCT. Disabling de should remove it; we'll
// verify the SQL it issues by source grep (don't actually disable
// in this test — it would affect other suites).
$srcI18n = file_get_contents($base . '/inc/i18n.php');
if (strpos($srcI18n, 'languages` WHERE enabled = 1') !== false) {
    ok('i18n_available_langs() queries registry for enabled rows');
} else {
    bad('i18n_available_langs() does NOT query the registry');
}
if (strpos($srcI18n, "preferred_lang") !== false) {
    ok('i18n_lang() consults user.preferred_lang');
} else {
    bad('i18n_lang() does not consult user.preferred_lang');
}

// ── api/set-language.php now persists to user row ────────────────────────
$sl = file_get_contents($base . '/api/set-language.php');
if (strpos($sl, "UPDATE `{\$prefix}user` SET `preferred_lang`") !== false ||
    strpos($sl, 'SET `preferred_lang`') !== false) {
    ok('set-language.php persists preferred_lang to user row');
} else {
    bad('set-language.php does NOT persist preferred_lang');
}
if (strpos($sl, 'languages` WHERE code = ?') !== false) {
    ok('set-language.php validates against registry');
} else {
    bad('set-language.php does NOT validate against registry');
}
// The FUTURE marker should be gone now that 8b ships persistence.
if (strpos($sl, '// FUTURE') === false || strpos($sl, 'FUTURE (per-user persistence)') === false) {
    ok('set-language.php FUTURE-persistence comment retired');
} else {
    bad('set-language.php still has stale FUTURE comment');
}

// ── login.php seeds session lang from preferred_lang ────────────────────
$lg = file_get_contents($base . '/login.php');
if (strpos($lg, 'preferred_lang') !== false && strpos($lg, "\$_SESSION['lang']") !== false) {
    ok('login.php seeds $_SESSION[lang] from preferred_lang');
} else {
    bad('login.php does NOT seed lang from preferred_lang');
}

// ── api/languages.php endpoint shape ────────────────────────────────────
$lp = $base . '/api/languages.php';
if (file_exists($lp)) {
    ok('api/languages.php exists');
    $lpSrc = file_get_contents($lp);
    foreach (['save', 'toggle_enabled', 'set_default', 'delete'] as $act) {
        if (strpos($lpSrc, "\$action === '{$act}'") !== false) {
            ok("languages.php handles action={$act}");
        } else {
            bad("languages.php missing action={$act}");
        }
    }
    if (strpos($lpSrc, 'csrf_verify') !== false) {
        ok('languages.php enforces CSRF');
    } else {
        bad('languages.php does NOT enforce CSRF');
    }
    if (strpos($lpSrc, "code === 'en'") !== false && strpos($lpSrc, 'is_default') !== false) {
        ok('languages.php has guards against deleting en / current default');
    } else {
        bad('languages.php missing en / default delete guards');
    }
    // Syntax check via PHP_BINARY (Windows-safe).
    $lintOut = shell_exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($lp) . ' 2>&1');
    if (strpos((string)$lintOut, 'No syntax errors') !== false) {
        ok('languages.php is syntactically valid PHP');
    } else {
        bad('languages.php has syntax errors', trim((string)$lintOut));
    }
} else {
    bad('api/languages.php missing');
}

// ── Navbar emits the language registry ──────────────────────────────────
$nv = file_get_contents($base . '/inc/navbar.php');
if (strpos($nv, 'window.LANGUAGE_REGISTRY') !== false &&
    strpos($nv, 'i18n_language_registry()') !== false) {
    ok('navbar.php emits LANGUAGE_REGISTRY to JS');
} else {
    bad('navbar.php does NOT emit LANGUAGE_REGISTRY');
}

// ── Sidebar has the Languages tab ───────────────────────────────────────
// Phase 36 sidebar emits tab buttons via the _cfg_tab() helper (which
// renders data-tab="languages" at runtime) — grep for the helper call.
$sb = file_get_contents($base . '/inc/config-sidebar.php');
if (strpos($sb, "_cfg_tab('languages'") !== false) {
    ok('config-sidebar.php has Languages tab');
} else {
    bad('config-sidebar.php missing Languages tab');
}
if (strpos($sb, "t('sidebar.tab.languages'") !== false) {
    ok('Languages tab label is translatable');
} else {
    bad('Languages tab label is hardcoded');
}

// ── Settings panel-languages markup ─────────────────────────────────────
$st = file_get_contents($base . '/settings.php');
if (strpos($st, 'id="panel-languages"') !== false) {
    ok('settings.php has #panel-languages');
} else {
    bad('settings.php missing #panel-languages');
}
foreach (['langAddCode', 'langAddDisplay', 'langAddNative', 'langAddSort', 'langTableBody'] as $id) {
    if (strpos($st, 'id="' . $id . '"') !== false) {
        ok("panel-languages has #{$id}");
    } else {
        bad("panel-languages missing #{$id}");
    }
}
if (strpos($st, 'languages-admin.js') !== false) {
    ok('settings.php loads languages-admin.js');
} else {
    bad('settings.php does NOT load languages-admin.js');
}

// ── languages-admin.js content ──────────────────────────────────────────
$la = $base . '/assets/js/languages-admin.js';
if (file_exists($la)) {
    ok('assets/js/languages-admin.js exists');
    $laSrc = file_get_contents($la);
    foreach ([
        'panel-languages', 'api/languages.php',
        "action: 'save'", "action: 'toggle_enabled'", "action: 'set_default'", "action: 'delete'",
        'completeness', 'is_default'
    ] as $needle) {
        if (strpos($laSrc, $needle) !== false) {
            ok("languages-admin.js contains \"{$needle}\"");
        } else {
            bad("languages-admin.js missing \"{$needle}\"");
        }
    }
} else {
    bad('languages-admin.js missing');
}

// ── Switcher prefers registry over hardcoded LANG_NAMES ─────────────────
$ls = file_get_contents($base . '/assets/js/language-switcher.js');
if (strpos($ls, 'window.LANGUAGE_REGISTRY') !== false) {
    ok('language-switcher.js consults LANGUAGE_REGISTRY');
} else {
    bad('language-switcher.js does NOT use registry');
}

// ── Migration file ──────────────────────────────────────────────────────
$mig = $base . '/sql/run_phase08b_i18n.php';
if (file_exists($mig)) {
    ok('sql/run_phase08b_i18n.php exists');
    $migSrc = file_get_contents($mig);
    if (strpos($migSrc, 'CREATE TABLE IF NOT EXISTS') !== false &&
        strpos($migSrc, 'INSERT IGNORE') !== false &&
        strpos($migSrc, 'information_schema') !== false) {
        ok('phase08b migration is idempotent (IF NOT EXISTS, INSERT IGNORE, info_schema guard)');
    } else {
        bad('phase08b migration missing idempotency guards');
    }
} else {
    bad('phase08b migration missing');
}

// ── Translations panel header now renders completeness ─────────────────
$ta = file_get_contents($base . '/assets/js/translations-admin.js');
if (strpos($ta, 'filled * 100 / totalKeys') !== false) {
    ok('translations-admin.js computes per-column completeness');
} else {
    bad('translations-admin.js does NOT render completeness in header');
}
if (strpos($ta, 'onAddLanguage') === false) {
    ok('translations-admin.js no longer has the prompt-dialog Add Language path');
} else {
    bad('translations-admin.js still references retired onAddLanguage');
}

// ── Summary ─────────────────────────────────────────────────────────────
echo "\n";
echo "===========================================\n";
echo "Phase 8b i18n: {$pass} passed, {$fail} failed\n";
echo "===========================================\n";

if ($fail > 0) exit(1);
