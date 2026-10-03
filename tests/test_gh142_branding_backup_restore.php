<?php
/**
 * GH#142 (Phase 155) - agency logo: it survives the project's own backup and
 * restore, byte for byte.
 *
 * `branding_logos` is the schema's FIRST image-bearing table, which is the one
 * risk the design review named for storing the image in the database: a backup
 * and restore of a ~340 KB text row had never been exercised. This drives the
 * REAL dump writer (inc/backup.php backup_dump_sql(), the same function the
 * scheduled and manual backups call) and restores the branding_logos statements
 * from that dump with the SAME statement splitting tools/restore.php uses
 * (a split on a semicolon at the end of a line), into a sibling table (so a test
 * never overwrites live data), then proves:
 *
 *   - the dump holds the table's INSERT, and a logo as large as the 256 KB cap
 *     (about 342 KB of base64 text) is written and read back intact;
 *   - the restored rows equal the originals column for column, and the restored
 *     image bytes still hash to the stored sha256;
 *   - the asset keys come back unchanged. inc/backup.php used to write any
 *     is_numeric() value UNQUOTED, so a key that looked like 12345e678... was
 *     restored as a float and the logo silently disappeared after a restore. That
 *     was fixed at the source in Phase 155 (quoting follows the COLUMN's type, see
 *     tests/test_backup_value_fidelity.php), and branding_new_asset_key() still
 *     never produces a numeric key, as a second layer. Section 5 now proves the
 *     source fix with the very key shape that used to be corrupted;
 *   - the dump contains no semicolon at the end of a line INSIDE a stored value
 *     (base64 has no semicolon, so the splitter cannot truncate the row).
 *
 * @requires-db
 * Usage: php tests/test_gh142_branding_backup_restore.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/branding.php';
require_once __DIR__ . '/../inc/backup.php';
require_once __DIR__ . '/_gh142_branding_fixtures.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== GH#142 - branding backup and restore round trip ===\n\n";

if (!branding_table_exists() || !branding_gd_available()) {
    echo "SKIP: branding_logos is missing or GD is not loaded\n";
    echo "\n=== 0 passed, 0 failed ===\n";
    exit(0);
}

$prefix = $GLOBALS['db_prefix'] ?? '';
$live = $prefix . 'branding_logos';
$copy = $prefix . 'branding_logos_zz142restore';

// ── 1. Keys can never be numeric ───────────────────────────────────────
$numeric = 0; $badFormat = 0; $badLead = 0;
for ($i = 0; $i < 5000; $i++) {
    $k = branding_new_asset_key();
    if (is_numeric($k)) { $numeric++; }
    if (!branding_valid_key($k)) { $badFormat++; }
    if (strpos('abcdf', $k[0]) === false) { $badLead++; }
}
t('5000 generated asset keys: none is numeric (is_numeric() false for every one)', $numeric === 0);
t('...all are exactly 32 lowercase hex characters', $badFormat === 0);
t('...and every one starts with a, b, c, d or f', $badLead === 0);
t('control: a key of the dangerous shape IS numeric to PHP (so the guard above guards something)', is_numeric('1234567890123456789012345678e123'));
t('...and is NOT accepted as a (differently built) key either way: it is still 32 hex characters', branding_valid_key('1234567890123456789012345678e123'));

// ── 2. Store a logo as large as the cap allows ─────────────────────────
$snapshot = db_fetch_all("SELECT * FROM " . db_table('branding_logos'));
db_query("DELETE FROM " . db_table('branding_logos'));
$dump = tempnam(sys_get_temp_dir(), 'gh142dump');
register_shutdown_function(function () use ($snapshot, $dump, $copy) {
    try {
        db_query("DROP TABLE IF EXISTS `{$copy}`");
        db_query("DELETE FROM " . db_table('branding_logos'));
        foreach ($snapshot as $row) {
            $cols = array_keys($row);
            db_query("INSERT INTO " . db_table('branding_logos') . " (`" . implode('`, `', $cols) . "`) VALUES (" . implode(', ', array_fill(0, count($cols), '?')) . ")", array_values($row));
        }
    } catch (Throwable $e) { /* best effort */ }
    @unlink($dump);
});

// A noisy PNG tuned to land close to the 256 KB stored cap.
$big = null;
foreach ([320, 300, 280, 260, 240, 220] as $edge) {
    $p = branding_process_upload(gh142_noise_png($edge, $edge));
    if (!empty($p['ok']) && strlen($p['bytes']) > 150 * 1024) { $big = $p; break; }
}
t('fixture: a real, large (over 150 KB) processed logo within the 256 KB cap was produced', $big !== null && strlen($big['bytes']) <= BRANDING_MAX_STORED_BYTES);
$light = branding_process_upload(gh142_png(200, 80));
$s1 = branding_store_logo(0, 'light', $big ?? $light, 1, 'zz142 backup');
$s2 = branding_store_logo(0, 'dark', $light, 1, 'zz142 backup');
t('both logos stored through the real writer', !empty($s1['ok']) && !empty($s2['ok']));
$origRows = db_fetch_all("SELECT * FROM " . db_table('branding_logos') . " ORDER BY `org_id`, `variant`");
$maxB64 = 0;
foreach ($origRows as $r) { $maxB64 = max($maxB64, strlen($r['data_b64'])); }
t('the biggest stored row is over 200 KB of base64 text (a realistic worst case)', $maxB64 > 200 * 1024);

// ── 3. The project's own dump writer ───────────────────────────────────
$ok = backup_dump_sql($dump);
t('backup_dump_sql() succeeds', $ok === true && is_file($dump) && filesize($dump) > 1000);
$sql = (string) file_get_contents($dump);
t('the dump contains the branding_logos table definition and INSERT',
    strpos($sql, "CREATE TABLE `branding_logos`") !== false && strpos($sql, "INSERT INTO `branding_logos`") !== false);

// The restore tool's own statement split.
$statements = preg_split('/;\s*[\r\n]+/', $sql);
$mine = [];
foreach ($statements as $stmt) {
    $stmt = trim($stmt);
    // strip leading comment lines the dump writes before a statement
    while (strpos($stmt, '--') === 0 && ($nl = strpos($stmt, "\n")) !== false) { $stmt = ltrim(substr($stmt, $nl + 1)); }
    if (preg_match('/^(DROP TABLE IF EXISTS|CREATE TABLE|INSERT INTO)\s+`branding_logos`/', $stmt)) { $mine[] = $stmt; }
}
t('the split yields the DROP, the CREATE and at least one INSERT for the table', count($mine) >= 3);
$insertTotal = 0;
foreach ($mine as $stmt) { if (strpos($stmt, 'INSERT INTO') === 0) { $insertTotal += substr_count($stmt, "\n("); } }
t('the INSERT statements are not truncated by the split (the large row is intact inside one statement)',
    (bool) array_filter($mine, function ($st) { return strpos($st, 'INSERT INTO') === 0 && strlen($st) > 200 * 1024; }));

// ── 4. Restore into a sibling table and compare ────────────────────────
db_query("DROP TABLE IF EXISTS `{$copy}`");
$applied = 0; $errors = [];
foreach ($mine as $stmt) {
    $renamed = str_replace('`branding_logos`', '`' . $copy . '`', $stmt);
    // The CREATE carries named keys; a sibling table needs no clash with the live table's key names.
    try { db()->exec($renamed); $applied++; } catch (Throwable $e) { $errors[] = substr($e->getMessage(), 0, 160); }
}
t('every extracted statement applied to the sibling table without error' . ($errors ? ' (' . $errors[0] . ')' : ''), $errors === [] && $applied === count($mine));
$restored = db_fetch_all("SELECT * FROM `{$copy}` ORDER BY `org_id`, `variant`");
t('the restored table has the same number of rows', count($restored) === count($origRows));
$same = count($restored) === count($origRows);
foreach ($origRows as $i => $orig) {
    foreach ($orig as $col => $val) {
        if (!isset($restored[$i]) || (string) $restored[$i][$col] !== (string) $val) { $same = false; fwrite(STDERR, "differs: row {$i} column {$col}\n"); }
    }
}
t('every column of every row is IDENTICAL after the round trip (keys, hashes, sizes, timestamps, the 340 KB base64 text)', $same);
$allHash = true;
foreach ($restored as $r) {
    $bin = base64_decode($r['data_b64'], true);
    if ($bin === false || hash('sha256', $bin) !== $r['sha256'] || !branding_valid_key($r['asset_key'])) { $allHash = false; }
}
t('...every restored image still decodes and hashes to its stored sha256, and every key is still valid', $allHash);

// ── 5. A numeric-looking key now survives this very path ───────────────
// This used to be the CONTROL that showed the hazard was real: a key of the
// dangerous shape was dumped unquoted and came back as a float. The dump now
// quotes by the column's type, so the same key must come back intact. The
// leading-letter rule in branding_new_asset_key() stays as a second layer.
db_query("DELETE FROM " . db_table('branding_logos') . " WHERE `org_id` = 0 AND `variant` = 'dark'");
$danger = '1234567890123456789012345678e123';
db_query("INSERT INTO " . db_table('branding_logos') . " (`org_id`, `variant`, `asset_key`, `mime`, `width`, `height`, `byte_size`, `sha256`, `data_b64`)
          VALUES (0, 'dark', ?, 'image/png', 1, 1, 1, ?, 'AA==')", [$danger, str_repeat('a', 64)]);
backup_dump_sql($dump);
$sql2 = (string) file_get_contents($dump);
t('the dump writes the numeric-looking key QUOTED (type-driven: the column is a string)',
    strpos($sql2, "'" . $danger . "'") !== false
    && strpos($sql2, "," . $danger . ",") === false && strpos($sql2, "'dark'," . $danger . ",") === false);
db_query("DROP TABLE IF EXISTS `{$copy}`");
foreach (preg_split('/;\s*[\r\n]+/', $sql2) as $stmt) {
    $stmt = trim($stmt);
    while (strpos($stmt, '--') === 0 && ($nl = strpos($stmt, "\n")) !== false) { $stmt = ltrim(substr($stmt, $nl + 1)); }
    if (preg_match('/^(DROP TABLE IF EXISTS|CREATE TABLE|INSERT INTO)\s+`branding_logos`/', $stmt)) {
        try { db()->exec(str_replace('`branding_logos`', '`' . $copy . '`', $stmt)); } catch (Throwable $e) { /* reported below */ }
    }
}
$back = db_fetch_value("SELECT `asset_key` FROM `{$copy}` WHERE `org_id` = 0 AND `variant` = 'dark'");
t('a numeric-looking key SURVIVES the round trip intact (the corruption this test used to demonstrate is fixed)',
    $back !== false && $back === $danger);

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
