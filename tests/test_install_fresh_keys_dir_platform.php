<?php
/**
 * Found 2026-08-25 running tools/release-snapshot.sh's staged-tree test
 * pass against a genuinely fresh checkout — not reported by any user, and
 * masked on this dev machine's own long-lived install by a stale leftover
 * directory (see below).
 *
 * tools/install_fresh.php's "keys/private.pem + keys/public.pem exist"
 * step computed its own $keysDir as realpath(__DIR__ . '/../../keys') —
 * a sibling of the app root on EVERY platform. That is exactly the
 * assumption inc/field-encrypt.php's own header documents as wrong on
 * Windows (GHSA-3jmh-c6f6-64jc) and fixed there via
 * FE_KEYS_DIR/fe_keys_dir_for(), which resolves to
 * %ProgramData%\TicketsCAD\keys on Windows instead of a sibling
 * directory. install_fresh.php was never updated when that fix landed:
 * its check() looked at the stale sibling path while apply()'s
 * fe_ensure_keys() correctly wrote to FE_KEYS_DIR — two different
 * directories on Windows — so check() could never see what apply() had
 * just ensured, and the step "applied" on every single run, forever.
 *
 * A brand-new checkout exposes this on the very first re-run. This dev
 * machine's own long-lived install masked it because a keys/ directory
 * left over from before the Windows relocation fix happened to already
 * sit at the stale sibling path this check was still reading.
 *
 * Fixed by requiring inc/field-encrypt.php up front and using the same
 * FE_KEYS_DIR/FE_PRIVATE_KEY/FE_PUBLIC_KEY constants the running
 * application itself uses — this check can no longer disagree with
 * where keys actually live, on any platform, by construction.
 *
 * Per this project's own established discipline (test_fe_keys_dir_
 * platform.php, test_zello_audio_dir_platform.php), this asserts against
 * an EXPLICIT simulated platform via fe_default_keys_dir_for()'s own
 * $windows parameter rather than this CI machine's actual platform —
 * "a test that can only see its own platform's answer is how the Windows
 * case shipped twice" (that function's own docblock).
 */

$root = dirname(__DIR__);
$pass = 0; $fail = 0;
function ok(string $what) { global $pass; echo "  PASS  $what\n"; $pass++; }
function bad(string $what, string $why = '') {
    global $fail; echo "  FAIL  $what" . ($why ? " — $why" : '') . "\n"; $fail++;
}

echo "=== tools/install_fresh.php: keys check uses the real, platform-aware FE_KEYS_DIR ===\n\n";

$src = file_get_contents($root . '/tools/install_fresh.php');

// ── 1. The old hand-rolled sibling-path computation must be gone as LIVE
// CODE (a $keysDir assignment) — the fix's own explanatory comment
// deliberately quotes the old expression in prose to document what
// changed, so this must not just grep for the substring anywhere in the
// file, or the fix's own docblock would trip its own regression test. ────
if (preg_match('/\$keysDir\s*=\s*realpath\(\s*__DIR__\s*\.\s*[\'"]\/\.\.\/\.\.\/keys[\'"]\s*\)/', $src)) {
    bad('tools/install_fresh.php still ASSIGNS $keysDir from a hand-computed sibling path', 'exact regression of the bug this test exists to catch — this disagrees with FE_KEYS_DIR on Windows');
} else {
    ok('tools/install_fresh.php no longer assigns $keysDir from a hand-computed sibling path (the old expression appears only in this fix\'s own explanatory comment)');
}

// ── 2. inc/field-encrypt.php is required (so FE_KEYS_DIR/FE_PRIVATE_KEY/
// FE_PUBLIC_KEY exist before the check() callback can reference them —
// not just lazily inside apply(), which was the OLD, buggy shape). ────────
if (preg_match('/require_once\s+__DIR__\s*\.\s*[\'"]\/\.\.\/inc\/field-encrypt\.php[\'"]\s*;/', $src)) {
    ok('tools/install_fresh.php requires inc/field-encrypt.php');
} else {
    bad('tools/install_fresh.php does not require inc/field-encrypt.php', 'FE_KEYS_DIR/FE_PRIVATE_KEY/FE_PUBLIC_KEY would be undefined');
}

// ── 3. The require happens BEFORE the "keys/private.pem + ..." step is
// registered (not lazily inside its own apply() callback) — otherwise
// check() still can't see the constants on the very first run. ───────────
$stepPos = strpos($src, "step('keys/private.pem + keys/public.pem exist'");
$reqPos  = strpos($src, "require_once __DIR__ . '/../inc/field-encrypt.php'");
if ($stepPos === false) {
    bad('found the keys/private.pem + keys/public.pem exist step to check ordering', 'step name changed?');
} elseif ($reqPos === false) {
    bad('found the field-encrypt.php require to check ordering');
} elseif ($reqPos < $stepPos) {
    ok('inc/field-encrypt.php is required BEFORE the keys step is registered, not lazily inside its own apply() callback');
} else {
    bad('inc/field-encrypt.php is required AFTER (or inside) the keys step', 'check() would still see undefined constants on a fresh checkout\'s first run');
}

// ── 4. check() reads FE_PRIVATE_KEY / FE_PUBLIC_KEY — the SAME constants
// the running application uses to find its own keys — not a separately
// computed path. ───────────────────────────────────────────────────────
if (preg_match('/fn\(\)\s*=>\s*file_exists\(\s*FE_PRIVATE_KEY\s*\)\s*&&\s*file_exists\(\s*FE_PUBLIC_KEY\s*\)/', $src)) {
    ok("the step's check() reads FE_PRIVATE_KEY/FE_PUBLIC_KEY directly — the same source of truth as inc/field-encrypt.php's own fe_ensure_keys()");
} else {
    bad("the step's check() does not reference FE_PRIVATE_KEY/FE_PUBLIC_KEY directly", 're-verify the fix — a re-derived path could still disagree with the real constants');
}

// ── 5. fe_default_keys_dir_for() itself is unchanged by this fix — this
// pins down that the fix is "read the existing, already-correct source
// of truth", not a second implementation of platform detection. ──────────
require_once $root . '/inc/field-encrypt.php';
if (function_exists('fe_default_keys_dir_for')) {
    $posix   = fe_default_keys_dir_for('/var/www/newui', false);
    $windows = fe_default_keys_dir_for('C:\\inetpub\\wwwroot\\newui', true);
    if (strpos($posix, '/keys') !== false && strpos($posix, 'ProgramData') === false) {
        ok("fe_default_keys_dir_for(..., windows: false) resolves to a POSIX sibling path ({$posix})");
    } else {
        bad("fe_default_keys_dir_for(..., windows: false) did not resolve as expected", $posix);
    }
    if (stripos($windows, 'ProgramData') !== false && stripos($windows, 'TicketsCAD') !== false) {
        ok("fe_default_keys_dir_for(..., windows: true) resolves under %ProgramData%\\TicketsCAD ({$windows}) — NOT a sibling of the app root");
    } else {
        bad("fe_default_keys_dir_for(..., windows: true) did not resolve under ProgramData as expected", $windows);
    }
} else {
    bad('fe_default_keys_dir_for() is not defined', 'inc/field-encrypt.php may have changed shape');
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
