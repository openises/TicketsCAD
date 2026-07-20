<?php
/**
 * i18n Form-Label Canonicalization Tests  (GitHub issue #42)
 *
 * a beta tester (Canada) renamed "State" -> "Prov" in the Translations admin
 * panel, which edits the seeded `form.state` caption_i18n row. The
 * member / facility / unit / vehicle / warn-location forms, however,
 * looked the label up under a DIFFERENT key (`field.state`), and the
 * new-incident form under a THIRD key (`newinc.label.state`) — so his
 * one override never reached those forms.
 *
 * The fix canonicalizes every State / City / Zip label onto the single
 * seeded `form.*` key that the admin panel actually exposes. These
 * tests lock that in:
 *   1. the canonical form.* rows are seeded,
 *   2. an override on form.state flows through t() (a beta tester's scenario),
 *   3. the view files never regress back to field.state / newinc.label.state
 *      for these three labels.
 *
 * Usage: php tests/test_i18n_form_labels.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/i18n.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$passed = 0;
$failed = 0;

function test($label, $condition) {
    global $passed, $failed;
    if ($condition) {
        echo "[PASS] $label\n";
        $passed++;
    } else {
        echo "[FAIL] $label\n";
        $failed++;
    }
}

echo "=== i18n Form-Label Canonicalization (#42) ===\n\n";

// ── 1. Canonical form.* seeds present ────────────────────────────────
echo "-- Canonical seeds --\n";
$haveSeeds = true;
foreach (['form.state', 'form.city', 'form.zip'] as $key) {
    try {
        $v = db_fetch_value(
            "SELECT `value` FROM `{$prefix}captions_i18n` WHERE `caption_key` = ? AND `lang` = 'en'",
            [$key]
        );
        test("$key seeded (en)", $v !== null && $v !== false && $v !== '');
        if ($v === null || $v === false) { $haveSeeds = false; }
    } catch (Exception $e) {
        test("$key seeded (en)", false);
        $haveSeeds = false;
    }
}

// ── 2. Override on form.state flows through t()  (a beta tester's scenario) ──
// IMPORTANT: t() caches all captions in a per-process static on its
// FIRST call, so we must write the override BEFORE any t() call runs.
echo "\n-- Override round-trip (must run before any t() call) --\n";
if ($haveSeeds) {
    $SENT = 'ProvSentinel_' . 'x42';   // avoid Date/random — fixed sentinel
    $orig = db_fetch_value(
        "SELECT `value` FROM `{$prefix}captions_i18n` WHERE `caption_key` = 'form.state' AND `lang` = 'en'"
    );
    $restored = false;
    try {
        db_query(
            "UPDATE `{$prefix}captions_i18n` SET `value` = ? WHERE `caption_key` = 'form.state' AND `lang` = 'en'",
            [$SENT]
        );
        // FIRST t() call in this process -> cache loads WITH the override.
        $got = t('form.state', 'State');
        test('t(form.state) returns admin override', $got === $SENT);

        // A label that has no override still resolves (falls to seed/default).
        $city = t('form.city', 'City');
        test('t(form.city) resolves (seed or default)', is_string($city) && $city !== '');
    } finally {
        // Always restore the seed so the test is non-destructive.
        db_query(
            "UPDATE `{$prefix}captions_i18n` SET `value` = ? WHERE `caption_key` = 'form.state' AND `lang` = 'en'",
            [$orig !== null ? $orig : 'State']
        );
        $restored = true;
    }
    test('form.state seed restored after test', $restored);
} else {
    echo "[SKIP] override round-trip — canonical seeds missing on this install\n";
}

// ── 3. Regression guard: view files use the canonical key ────────────
// If a future edit reintroduces field.state / newinc.label.state for
// these three labels, this test fails and flags the re-fragmentation.
echo "\n-- View-file regression guard --\n";
$root = dirname(__DIR__);
$views = [
    'constituents.php', 'facility-detail.php', 'facility-edit.php',
    'incident-detail.php', 'new-incident.php', 'roster.php',
    'settings.php', 'unit-detail.php', 'unit-edit.php', 'vehicles.php',
];
$badPatterns = [
    "t('field.state'", "t('field.city'", "t('field.zip'",
    "t('newinc.label.state'", "t('newinc.label.city'", "t('newinc.label.zip'",
];
foreach ($views as $vf) {
    $path = $root . '/' . $vf;
    $src = @file_get_contents($path);
    if ($src === false) {
        test("$vf readable", false);
        continue;
    }
    $offenders = [];
    foreach ($badPatterns as $bad) {
        if (strpos($src, $bad) !== false) {
            $offenders[] = $bad;
        }
    }
    test("$vf uses canonical form.* keys (no field.*/newinc.label.* for state/city/zip)",
        empty($offenders));
}

// At least one view must actually contain t('form.state' — proves the
// canonicalization landed rather than the labels simply vanishing.
$anyCanonical = false;
foreach ($views as $vf) {
    $src = @file_get_contents($root . '/' . $vf);
    if ($src !== false && strpos($src, "t('form.state'") !== false) {
        $anyCanonical = true;
        break;
    }
}
test('at least one view renders t(form.state)', $anyCanonical);

// ── Summary ──────────────────────────────────────────────────────────
echo "\n=== $passed passed, $failed failed ===\n";
exit($failed === 0 ? 0 : 1);
