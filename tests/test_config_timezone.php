<?php
/**
 * The config template must APPLY the agency timezone, not just default it.
 *
 * ORIGIN (2026-11-26, @geartvvideos on YouTube, Docker/localhost install).
 * Symptom: the top-of-app clock and the Time Check tab's clock read correct
 * (both tick from the browser's own `new Date()`), but incident event-note
 * timestamps were an hour behind, and Time Check reported the server zone as
 * America/Chicago even though Settings → Agency Time Zone was America/New_York.
 *
 * ROOT CAUSE. A Docker install generates config.php from config.example.php
 * via docker-config-gen.php. The live config.php reads the `area_timezone`
 * setting after inc/db.php loads and re-applies it to PHP AND the MySQL
 * session (inc/db.php syncs MySQL's time_zone to PHP's offset). But
 * config.example.php — the file every fresh/Docker install is built from —
 * had only a static `date_default_timezone_set('America/Chicago')` and NO
 * override block, so the Agency Time Zone setting was written to the DB and
 * never applied. PHP stayed on Chicago; NOW()/date() stamped notes one hour
 * behind Eastern. A dead control on every install built from the template.
 *
 * WHAT THIS GUARDS.
 *   1. The template defaults to America/New_York (a bare statement, so the
 *      Docker generator can substitute it) and no longer Chicago.
 *   2. The template contains the `area_timezone` read-and-apply block — the
 *      exact code that was missing — placed AFTER the DB layer is required
 *      (it needs a query). A pure presence check here is the right guard
 *      because the bug WAS the absence of this code.
 *   3. The REAL docker-config-gen.php honours NEWUI_TIMEZONE end to end:
 *      run as a subprocess into a temp file (never the live config.php), a
 *      valid zone is substituted into the default line and the override
 *      block survives; an invalid zone is rejected and the default kept; an
 *      unset env leaves America/New_York. This drives the actual generator,
 *      not a re-implementation of its logic.
 *   4. run_phase22_area_timezone.php seeds its default from the SAME env var,
 *      so a container's NEWUI_TIMEZONE is not silently overridden by the
 *      America/New_York seed on first boot (that would be a fresh dead
 *      control). Source-level guard: running it live would write the shared
 *      dev DB's settings table, and its runtime seed path is already covered
 *      by the migration suite.
 *
 * Needs no database. Usage: php tests/test_config_timezone.php
 */

$root = dirname(__DIR__);

$pass = 0; $fail = 0;
function ok(string $n): void { global $pass; echo "[PASS] $n\n"; $pass++; }
function bad(string $n, string $why = ''): void { global $fail; echo "[FAIL] $n" . ($why ? " — $why" : '') . "\n"; $fail++; }
function is_true($c, string $n, string $why = ''): void { $c ? ok($n) : bad($n, $why); }

echo "=== config template timezone application ===\n\n";

// ── 1. The template's pre-DB default ──────────────────────────────────────
$ex = (string) file_get_contents($root . '/config.example.php');

is_true(
    strpos($ex, "date_default_timezone_set('America/New_York');") !== false,
    'config.example.php default zone is America/New_York (bare statement)',
    'the Docker generator substitutes this exact line — keep it bare, no trailing comment'
);
is_true(
    strpos($ex, "America/Chicago") === false,
    'config.example.php no longer hardcodes America/Chicago',
    'the stale static Chicago default is the original bug'
);

// ── 2. The override block exists and runs after the DB layer ──────────────
$hasRead  = strpos($ex, "`name` = 'area_timezone'") !== false;
$hasApply = (bool) preg_match('/date_default_timezone_set\(\s*\$area_tz\s*\)/', $ex);
is_true($hasRead,  'config.example.php reads the area_timezone setting');
is_true($hasApply, 'config.example.php applies it via date_default_timezone_set($area_tz)');
is_true(
    strpos($ex, "SET time_zone = '{\$offset}'") !== false,
    'config.example.php re-syncs the MySQL session time_zone to the applied zone'
);

$posFns = strpos($ex, "require_once NEWUI_ROOT . '/inc/functions.php'");
$posTz  = strpos($ex, "`name` = 'area_timezone'");
is_true(
    $posFns !== false && $posTz !== false && $posTz > $posFns,
    'the override block runs AFTER inc/db.php + inc/functions.php are required',
    'it queries the settings table, so the DB layer must be loaded first'
);

// ── 3. Drive the REAL docker-config-gen.php into a temp file ──────────────
$genScript = $root . '/docker-config-gen.php';

/**
 * Run the real generator with env overrides, writing to a throwaway file.
 * Returns [exitCode, generatedContents|null, stdout, stderr].
 */
function gen_with(array $overrides): array
{
    global $root, $genScript;

    $out = tempnam(sys_get_temp_dir(), 'cfggen_out_');
    $so  = tempnam(sys_get_temp_dir(), 'cfggen_so_');
    $se  = tempnam(sys_get_temp_dir(), 'cfggen_se_');

    $env = getenv();                       // inherit a launchable environment
    unset($env['NEWUI_TIMEZONE'], $env['TZ']);   // start clean each call
    $env['CONFIG_GEN_OUT'] = $out;         // never touch the live config.php
    $env['NEWUI_DB_PASS']  = 'tz-test-pass';
    foreach ($overrides as $k => $v) {
        if ($v === null) { unset($env[$k]); } else { $env[$k] = $v; }
    }

    $desc = [0 => ['pipe', 'r'], 1 => ['file', $so, 'w'], 2 => ['file', $se, 'w']];
    $proc = proc_open([PHP_BINARY, $genScript], $desc, $pipes, $root, $env);
    if (!is_resource($proc)) {
        @unlink($out); @unlink($so); @unlink($se);
        return [-1, null, '', 'proc_open failed'];
    }
    if (isset($pipes[0])) { fclose($pipes[0]); }
    $code = proc_close($proc);             // blocks until the child exits

    $gen    = is_file($out) ? (string) file_get_contents($out) : null;
    $stdout = (string) @file_get_contents($so);
    $stderr = (string) @file_get_contents($se);
    @unlink($out); @unlink($so); @unlink($se);
    return [$code, $gen, $stdout, $stderr];
}

// 3a. A valid NEWUI_TIMEZONE is substituted; the override block survives.
[$code, $gen, $stdout, $stderr] = gen_with(['NEWUI_TIMEZONE' => 'America/Denver']);
is_true($code === 0 && $gen !== null, 'generator ran (valid NEWUI_TIMEZONE)', "exit $code / stderr: $stderr");
if ($gen !== null) {
    is_true(
        strpos($gen, "date_default_timezone_set('America/Denver');") !== false,
        'NEWUI_TIMEZONE=America/Denver is written into the generated default line'
    );
    is_true(
        strpos($gen, "date_default_timezone_set('America/New_York');") === false,
        'the America/New_York default was replaced, not left alongside'
    );
    is_true(
        strpos($gen, "`name` = 'area_timezone'") !== false,
        'the generated config.php STILL carries the area_timezone override block'
    );
    is_true(
        strpos($gen, "'tz-test-pass'") !== false,
        'the generator also substituted the DB password (end-to-end real run)'
    );
}

// 3b. An invalid zone is rejected — the default is kept, boot is not broken.
[$code, $gen, $stdout, $stderr] = gen_with(['NEWUI_TIMEZONE' => 'Not/AZone']);
is_true($code === 0 && $gen !== null, 'generator ran (invalid NEWUI_TIMEZONE)', "exit $code");
if ($gen !== null) {
    is_true(
        strpos($gen, "date_default_timezone_set('America/New_York');") !== false,
        'an invalid NEWUI_TIMEZONE leaves the template default (America/New_York)'
    );
    is_true(
        strpos($gen, "Not/AZone") === false,
        'the invalid zone is never persisted into config.php'
    );
    is_true(
        stripos($stderr, 'not a valid IANA zone') !== false,
        'the generator warns on an invalid NEWUI_TIMEZONE'
    );
}

// 3c. With no env override, the template default stands.
[$code, $gen] = gen_with([]);
is_true(
    $code === 0 && $gen !== null && strpos($gen, "date_default_timezone_set('America/New_York');") !== false,
    'with no NEWUI_TIMEZONE/TZ set, the default stays America/New_York'
);

// 3d. The conventional TZ env var is honoured too (NEWUI_TIMEZONE takes precedence).
[$code, $gen] = gen_with(['TZ' => 'America/Los_Angeles']);
is_true(
    $gen !== null && strpos($gen, "date_default_timezone_set('America/Los_Angeles');") !== false,
    'TZ=America/Los_Angeles is honoured when NEWUI_TIMEZONE is unset'
);

// ── 4. The seed default follows the same env var ──────────────────────────
$p22 = (string) file_get_contents($root . '/sql/run_phase22_area_timezone.php');
is_true(
    strpos($p22, "getenv('NEWUI_TIMEZONE')") !== false,
    'run_phase22_area_timezone.php derives its seed default from NEWUI_TIMEZONE',
    'otherwise the America/New_York seed silently overrides a container env setting'
);
is_true(
    strpos($p22, "in_array(\$envTz, \$validZones, true)") !== false,
    'run_phase22 validates the env zone before seeding it'
);

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
