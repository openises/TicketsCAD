<?php
/**
 * GH#58 (kb0ubz, 2026-08-13) — every photo/file upload was rejected with
 * "Disk usage at 31.2% — uploads blocked (threshold: 0%)", on a host with
 * 684 GB free, regardless of file size. Root cause: upload_config()
 * (inc/upload-config.php, extracted from api/upload.php for this test)
 * only guarded db_fetch_value()'s return against `null` -- but PDO's
 * fetchColumn() returns `false`, not `null`, when no row matches, and
 * `upload_disk_block_pct`/`upload_disk_warn_pct`/`upload_max_file_size`
 * are never seeded on any install (no migration seeds them, no Settings
 * UI edits them). (float) false is 0.0, so the block check
 * `disk_used_pct >= block_pct` became `>= 0.0` -- always true.
 *
 * @requires-db
 */

$root = dirname(__DIR__);
require_once $root . '/config.php';

$pass = 0; $fail = 0;
function test(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "[PASS] $name\n"; }
    else     { $fail++; echo "[FAIL] $name" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

try {
    db_fetch_value('SELECT 1');
} catch (Throwable $e) {
    echo "SKIP: no database connection (" . $e->getMessage() . ")\n";
    echo "\n0 passed, 0 failed\n";
    exit(0);
}

require_once $root . '/inc/upload-config.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$probeKey = 'gh58_probe_' . bin2hex(random_bytes(4));

// Clean slate: make sure the probe key genuinely has no row.
db_query("DELETE FROM `{$prefix}settings` WHERE `name` = ?", [$probeKey]);

test('a MISSING settings row falls back to the coded default, not 0',
    upload_config($probeKey, 42) === 42,
    'got ' . var_export(upload_config($probeKey, 42), true));

test('the real disk-block-percent key falls back to 80, not 0 (the exact reported symptom)',
    (float) upload_config('upload_disk_block_pct', 80) !== 0.0,
    'threshold resolved to ' . var_export((float) upload_config('upload_disk_block_pct', 80), true));

test('…and specifically resolves to the documented default of 80',
    (float) upload_config('upload_disk_block_pct', 80) === 80.0);

// A present row must still win over the default -- the fix must not have
// widened the guard into "always ignore the database".
db_query("INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES (?, ?)", [$probeKey, '17']);
test('a PRESENT settings row still wins over the default',
    upload_config($probeKey, 42) === '17',
    'got ' . var_export(upload_config($probeKey, 42), true));

// A row whose value is the literal string '0' must be honoured as a real,
// intentional zero -- not treated as "missing" the way GH#58's own bug did.
db_query("UPDATE `{$prefix}settings` SET `value` = ? WHERE `name` = ?", ['0', $probeKey]);
test("a row whose value is literally '0' is honoured, not treated as missing",
    upload_config($probeKey, 42) === '0',
    'got ' . var_export(upload_config($probeKey, 42), true));

db_query("DELETE FROM `{$prefix}settings` WHERE `name` = ?", [$probeKey]);

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
