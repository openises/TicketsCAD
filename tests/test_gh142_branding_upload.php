<?php
/**
 * GH#142 (Phase 155) - agency logo: the upload validation and storage pipeline.
 *
 * Feeds branding_process_upload() REAL images and REAL hostile files (generated
 * by tests/_gh142_branding_fixtures.php with GD and hand-built bytes), then the
 * real storage functions against the real table. Never a hand-seeded row.
 *
 * Covers: PNG and JPEG accepted and normalised; EXIF GPS present in the input
 * and ABSENT in the stored bytes; a PHP/HTML trailer and a tEXt chunk gone after
 * re-encode; transparency preserved (palette PNG too); SVG refused even when it
 * claims to be a PNG; GIF, animated PNG, animated WebP, corrupt, empty and
 * truncated refused; a header-only 30000 x 30000 bomb refused WITHOUT allocating
 * (peak memory asserted); over 2 MB refused; over 256 KB after processing
 * refused (and a big flat image is reduced to fit); the no-GD path stores
 * PNG/JPEG as-is, cuts a PNG at IEND and reports the warning; a decode failure
 * is a refusal, never a fall-back to the original bytes; stored sha256 equals
 * the hash of the decoded bytes; replacing issues a new asset_key; the dark
 * variant needs a light one first; removing light removes dark.
 *
 * @requires-db
 * Usage: php tests/test_gh142_branding_upload.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/branding.php';
require_once __DIR__ . '/_gh142_branding_fixtures.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== GH#142 - branding upload pipeline ===\n\n";

if (!branding_gd_available()) {
    echo "SKIP: the PHP GD extension is not loaded, so the fixtures cannot be generated\n";
    echo "\n=== 0 passed, 0 failed ===\n";
    exit(0);
}

$png = gh142_png();
$jpg = gh142_jpeg();

// ── 1. Real images are accepted and normalised ─────────────────────────
$r = branding_process_upload($png);
t('a real PNG is accepted', !empty($r['ok']));
t('...stays a PNG at the same size', ($r['mime'] ?? '') === 'image/png' && ($r['width'] ?? 0) === 120 && ($r['height'] ?? 0) === 60);
t('...the result decodes as a real PNG', ($i = @getimagesizefromstring($r['bytes'] ?? '')) !== false && $i['mime'] === 'image/png');
t('...the stored sha256 is the hash of the stored bytes', ($r['sha256'] ?? '') === hash('sha256', $r['bytes'] ?? ''));

$r = branding_process_upload($jpg);
t('a real JPEG is accepted and stays a JPEG', !empty($r['ok']) && $r['mime'] === 'image/jpeg');

$r = branding_process_upload(gh142_palette_png());
t('a palette PNG with a transparent index is accepted', !empty($r['ok']));
if (!empty($r['ok'])) {
    $im = imagecreatefromstring($r['bytes']);
    $argb = imagecolorat($im, 0, 0);
    t('...its transparent corner is STILL transparent after re-encode (alpha preserved)', (($argb >> 24) & 0x7F) >= 100);
    $centre = imagecolorat($im, (int) ($r['width'] / 2), (int) ($r['height'] / 2));
    t('...and its opaque centre is still opaque', (($centre >> 24) & 0x7F) === 0);
}

$r = branding_process_upload(gh142_png(3000, 2000));
t('a 3000 x 2000 PNG is reduced to a 1200 pixel long edge, aspect kept',
    !empty($r['ok']) && $r['width'] === 1200 && $r['height'] === 800);
t('...and the reduction is reported', !empty($r['ok']) && count($r['warnings']) === 1 && strpos($r['warnings'][0], '1200 x 800') !== false);

$webp = gh142_webp();
if ($webp !== null && function_exists('imagecreatefromwebp')) {
    $r = branding_process_upload($webp);
    t('a real WebP is accepted (when GD can decode it) and is stored as a PNG', !empty($r['ok']) && $r['mime'] === 'image/png');
} else {
    echo "[SKIP] WebP: this GD cannot write/read WebP\n";
}

// ── 2. Metadata and trailers are stripped by the re-encode ─────────────
$j = gh142_jpeg_with_exif_gps($jpg);
$gpsIn = gh142_has_gps($j);
if ($gpsIn !== null) { t('fixture: the input JPEG really carries EXIF GPS', $gpsIn === true); }
t('fixture: the input JPEG carries the EXIF marker text', gh142_contains($j, 'GH142-EXIF-MARKER'));
$r = branding_process_upload($j);
t('a JPEG with EXIF GPS is accepted', !empty($r['ok']));
t('...the stored bytes carry NO EXIF/COM marker text', !empty($r['ok']) && !gh142_contains($r['bytes'], 'GH142-EXIF-MARKER'));
t('...the stored bytes carry no Exif segment at all', !empty($r['ok']) && !gh142_contains($r['bytes'], "Exif\0\0"));
$gpsOut = !empty($r['ok']) ? gh142_has_gps($r['bytes']) : null;
if ($gpsOut !== null) { t('...and no GPS block is readable from them', $gpsOut === false); }

$poly = gh142_png_polyglot($png);
t('fixture: the polyglot carries a PHP trailer and a tEXt chunk', gh142_contains($poly, 'GH142-PWNED') && gh142_contains($poly, 'GH142-META-MARKER'));
$r = branding_process_upload($poly);
t('a valid PNG with a PHP/HTML trailer and a tEXt chunk is accepted', !empty($r['ok']));
t('...the PHP trailer is ABSENT after re-encode', !empty($r['ok']) && !gh142_contains($r['bytes'], 'GH142-PWNED') && !gh142_contains($r['bytes'], '<?php'));
t('...the script tag is absent too', !empty($r['ok']) && !gh142_contains($r['bytes'], '<script'));
t('...and so is the tEXt metadata', !empty($r['ok']) && !gh142_contains($r['bytes'], 'GH142-META-MARKER'));

// ── 3. Things that must be refused ─────────────────────────────────────
$refused = function (string $label, string $bytes, string $code = '') {
    $r = branding_process_upload($bytes);
    t($label . ' is refused', empty($r['ok']));
    if ($code !== '') { t("...with the reason '{$code}'", ($r['code'] ?? '') === $code); }
    return $r;
};
$r = $refused('an SVG (named .png, claiming image/png)', gh142_svg(), 'svg');
t('...and the message says what to do (export as PNG)', strpos($r['message'] ?? '', 'PNG') !== false);
$refused('an SVG preceded by a byte-order mark and whitespace', "\xEF\xBB\xBF \n" . gh142_svg(), 'svg');
$refused('a GIF', gh142_gif(), 'not_image');
$refused('a GIF89a header followed by PHP', "GIF89a<?php echo 'x'; ?>", 'not_image');
$refused('an animated PNG (acTL chunk)', gh142_apng($png), 'animated');
$refused('an animated WebP', gh142_animated_webp());
$refused('an empty file', '', 'empty');
$refused('a PNG truncated mid-file', substr($png, 0, 70), 'corrupt');
$refused('plain text', str_repeat('hello ', 50), 'not_image');
$refused('a PNG signature followed by garbage', "\x89PNG\r\n\x1a\n" . str_repeat("\xAB", 200));
$refused('a file larger than 2 MB', str_repeat('A', BRANDING_MAX_UPLOAD_BYTES + 1), 'too_large');

// A structurally valid PNG whose image data is garbage: the header checks all
// pass, the decode FAILS, and the original bytes must never be stored.
$ihdr = pack('N', 50) . pack('N', 50) . "\x08\x02\x00\x00\x00";
$badIdat = "\x89PNG\r\n\x1a\n" . gh142_png_chunk('IHDR', $ihdr) . gh142_png_chunk('IDAT', random_bytes(64)) . gh142_png_chunk('IEND', '');
$r = branding_process_upload($badIdat);
t('a PNG with valid chunks but undecodable image data is refused (a decode failure never falls back to the original bytes)',
    empty($r['ok']) && ($r['code'] ?? '') === 'decode_failed');
t('...and no bytes are returned to store', !isset($r['bytes']));

// ── 4. Decompression bomb: refused from the header, nothing allocated ──
$bomb = gh142_png_bomb(30000, 30000);
t('fixture: the bomb is tiny on disk', strlen($bomb) < 400);
$peakBefore = memory_get_peak_usage(true);
$r = branding_process_upload($bomb);
$peakDelta = memory_get_peak_usage(true) - $peakBefore;
t('a header-only 30000 x 30000 bomb is refused', empty($r['ok']) && ($r['code'] ?? '') === 'too_many_pixels');
t('...without allocating (peak memory grew by under 4 MB; a decode would need ~3.6 GB)', $peakDelta < 4 * 1048576);
$r = branding_process_upload(gh142_png_bomb(5000, 5000));
t('a header claiming 5000 x 5000 (25 million pixels) is refused on total pixels', empty($r['ok']) && ($r['code'] ?? '') === 'too_many_pixels');
$r = branding_process_upload(gh142_png_bomb(7000, 10));
t('a header claiming 7000 pixels on one edge is refused on the edge limit', empty($r['ok']) && ($r['code'] ?? '') === 'too_many_pixels');

// ── 5. The 256 KB stored cap ───────────────────────────────────────────
$noise = gh142_noise_png(600, 600);
t('fixture: the noise PNG is under the 2 MB upload cap but over the 256 KB stored cap',
    strlen($noise) < BRANDING_MAX_UPLOAD_BYTES && strlen($noise) > BRANDING_MAX_STORED_BYTES);
$r = branding_process_upload($noise);
t('an incompressible image that cannot fit 256 KB even when reduced is refused', empty($r['ok']) && ($r['code'] ?? '') === 'stored_too_large');
t('...and the message names the cap', strpos($r['message'] ?? '', '256 KB') !== false);

// ── 6. No GD: stored as-is, trailer cut, warning reported ──────────────
$r = branding_process_upload($poly, ['force_no_gd' => true]);
t('with GD forced off, a valid PNG is accepted', !empty($r['ok']));
t('...stored as uploaded (the tEXt metadata is NOT stripped, and the warning says so)',
    !empty($r['ok']) && gh142_contains($r['bytes'], 'GH142-META-MARKER') && count($r['warnings']) === 1
    && strpos($r['warnings'][0], 'NOT removed') !== false);
t('...but a PNG is still cut at IEND, so the PHP trailer is gone', !empty($r['ok']) && !gh142_contains($r['bytes'], 'GH142-PWNED'));
$r = branding_process_upload($jpg, ['force_no_gd' => true]);
t('with GD forced off, a JPEG is accepted as-is with the warning', !empty($r['ok']) && $r['bytes'] === $jpg && count($r['warnings']) === 1);
$r = branding_process_upload(gh142_svg(), ['force_no_gd' => true]);
t('with GD forced off, SVG is STILL refused', empty($r['ok']) && ($r['code'] ?? '') === 'svg');
$r = branding_process_upload($bomb, ['force_no_gd' => true]);
t('with GD forced off, the bomb is STILL refused from the header', empty($r['ok']) && ($r['code'] ?? '') === 'too_many_pixels');
if ($webp !== null) {
    $r = branding_process_upload($webp, ['force_no_gd' => true]);
    t('with GD forced off, WebP is refused (it cannot be validated by decoding)', empty($r['ok']) && ($r['code'] ?? '') === 'webp_unsupported');
}
t('accepted types without GD are PNG and JPEG only', branding_accepted_types(false) === ['image/png', 'image/jpeg']);
$bigNoGd = branding_process_upload(gh142_png(2000, 1500), ['force_no_gd' => true]);
// A flat 2000x1500 PNG is small on disk, so it passes; prove the stored cap applies anyway.
t('with GD forced off a small PNG passes the stored cap', !empty($bigNoGd['ok']));
$r = branding_process_upload($noise, ['force_no_gd' => true]);
t('with GD forced off, an over-256-KB file is refused (it cannot be reduced)', empty($r['ok']) && ($r['code'] ?? '') === 'stored_too_large');

// ── 7. Real storage ────────────────────────────────────────────────────
if (!branding_table_exists()) {
    echo "SKIP: branding_logos is missing (run sql/run_migrations.php); the storage checks were not run\n";
    echo "\n=== $pass passed, $fail failed ===\n";
    exit($fail > 0 ? 1 : 0);
}
$snapshot = db_fetch_all("SELECT * FROM " . db_table('branding_logos'));
db_query("DELETE FROM " . db_table('branding_logos'));
branding_reset_cache();
try {
    $p1 = branding_process_upload($png);
    $s1 = branding_store_logo(0, 'light', $p1, 7, 'zz142-tester');
    t('storing the install-wide light logo succeeds', !empty($s1['ok']) && $s1['replaced'] === false && $s1['id'] > 0);
    t('...the asset key is 32 lowercase hex characters', branding_valid_key($s1['asset_key'] ?? ''));
    $row = db_fetch_one("SELECT * FROM " . db_table('branding_logos') . " WHERE `id` = ?", [$s1['id']]);
    $decoded = base64_decode($row['data_b64'], true);
    t('...the stored base64 decodes to the processed bytes', $decoded === $p1['bytes']);
    t('...the stored sha256 equals the hash of the DECODED stored bytes', $row['sha256'] === hash('sha256', $decoded));
    t('...byte_size, dimensions, mime and uploader are recorded',
        (int) $row['byte_size'] === strlen($p1['bytes']) && (int) $row['width'] === 120 && (int) $row['height'] === 60
        && $row['mime'] === 'image/png' && (int) $row['uploaded_by'] === 7 && $row['uploaded_by_name'] === 'zz142-tester');
    t('...no original filename column exists to leak one',
        !array_key_exists('orig_name', $row) && !array_key_exists('filename', $row));

    $p2 = branding_process_upload(gh142_png(200, 100));
    $s2 = branding_store_logo(0, 'light', $p2, 7, 'zz142-tester');
    t('replacing the logo succeeds and reports it replaced', !empty($s2['ok']) && $s2['replaced'] === true && $s2['id'] === $s1['id']);
    t('...a replacement issues a NEW asset_key', $s2['asset_key'] !== $s1['asset_key']);
    t('...the old key no longer exists', !db_fetch_value("SELECT 1 FROM " . db_table('branding_logos') . " WHERE `asset_key` = ?", [$s1['asset_key']]));
    t('...and there is still exactly one install-wide light row', (int) db_fetch_value("SELECT COUNT(*) FROM " . db_table('branding_logos') . " WHERE `org_id` = 0 AND `variant` = 'light'") === 1);

    $dark = branding_store_logo(5, 'dark', $p1, 7, 'zz142-tester');
    t('a dark logo is refused when its scope has no light logo yet', empty($dark['ok']) && ($dark['code'] ?? '') === 'light_first');

    $dOk = branding_store_logo(0, 'dark', $p1, 7, 'zz142-tester');
    t('a dark logo is accepted once the light one exists', !empty($dOk['ok']));
    $bad = branding_store_logo(0, 'sepia', $p1, 7, 'x');
    t('an invalid variant is refused', empty($bad['ok']) && ($bad['code'] ?? '') === 'bad_scope');
    $neg = branding_store_logo(-1, 'light', $p1, 7, 'x');
    t('a negative organization id is refused', empty($neg['ok']) && ($neg['code'] ?? '') === 'bad_scope');
    $refusedInput = branding_store_logo(0, 'light', ['ok' => false], 7, 'x');
    t('storing a refused upload result is refused', empty($refusedInput['ok']));

    $del = branding_delete_logo(0, 'dark');
    t('removing the dark logo removes only the dark logo', !empty($del['ok']) && $del['deleted'] === 1
        && (int) db_fetch_value("SELECT COUNT(*) FROM " . db_table('branding_logos') . " WHERE `org_id` = 0 AND `variant` = 'light'") === 1);
    t('...and records who uploaded it and its fingerprint for the audit entry',
        isset($del['removed'][0]) && $del['removed'][0]['uploaded_by'] === 7 && strlen($del['removed'][0]['sha256']) === 12);
    branding_store_logo(0, 'dark', $p1, 7, 'zz142-tester');
    $del = branding_delete_logo(0, 'light');
    t('removing the LIGHT logo also removes the dark one (no invisible orphan)', !empty($del['ok']) && $del['deleted'] === 2
        && (int) db_fetch_value("SELECT COUNT(*) FROM " . db_table('branding_logos') . " WHERE `org_id` = 0") === 0);
    $del = branding_delete_logo(0, 'light');
    t('removing a logo that is not there is a clean no-op', !empty($del['ok']) && $del['deleted'] === 0);
} finally {
    db_query("DELETE FROM " . db_table('branding_logos'));
    foreach ($snapshot as $row) {
        $cols = array_keys($row);
        db_query("INSERT INTO " . db_table('branding_logos') . " (`" . implode('`, `', $cols) . "`) VALUES (" . implode(', ', array_fill(0, count($cols), '?')) . ")", array_values($row));
    }
    branding_reset_cache();
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
