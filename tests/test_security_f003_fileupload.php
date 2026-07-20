<?php
/**
 * F-003 regression — file-upload.php (legacy companion to upload.php)
 * must enforce CSRF, allowlist extensions/MIMEs, and per-entity access.
 */

require __DIR__ . '/../config.php';

$base = realpath(__DIR__ . '/..');

echo "=== F-003 file-upload.php Regression Tests ===\n\n";
$pass = 0; $fail = 0;
function ok($name) { global $pass; echo "[PASS] $name\n"; $pass++; }
function bad($name, $why = '') { global $fail; echo "[FAIL] $name" . ($why ? " — $why" : "") . "\n"; $fail++; }

$src = file_get_contents($base . '/api/file-upload.php');
// 2026-06-28 Phase 94 Stage 4j refactor: api/file-upload.php now delegates
// the validation + content-sniff + random-filename + INSERT to
// inc/file-write.php :: file_attach_to_internal(). Pattern-matching tests
// below scan BOTH files so the security guarantees still register as
// present after the refactor.
$srcHelper = file_get_contents($base . '/inc/file-write.php');
$srcAll = $src . "\n" . $srcHelper;

// 1. CSRF helper used and called for both POST and DELETE
if (strpos($src, '_file_upload_csrf_ok') !== false
    && preg_match_all('/_file_upload_csrf_ok\(\)/', $src) >= 2) {
    ok('file-upload.php verifies CSRF on POST and DELETE');
} else {
    bad('file-upload.php verifies CSRF on POST and DELETE');
}

// 2. finfo-derived MIME, no $_FILES['type'] for storage
// (May live in inc/file-write.php after the Stage 4j refactor.)
if (strpos($srcAll, 'finfo_open(FILEINFO_MIME_TYPE)') !== false) {
    ok('file-upload.php uses finfo_file for MIME detection');
} else {
    bad('file-upload.php uses finfo_file for MIME detection');
}
// The pre-fix line `$file['type']` should not be assigned to filetype DB column anymore
if (preg_match('/\$file\[.type.\][^,]*\)/i', $src)) {
    // Could be benign — check that filetype column receives $detectedMime
    if (preg_match('/INSERT[\s\S]*filetype[\s\S]*\$detectedMime/i', $src)
        || preg_match('/\[\$title,\s*\$storedName,\s*\$file\[.name.\],\s*\$entityId,\s*\$canonicalExt,\s*\$detectedMime\]/', $src)) {
        ok('file-upload.php inserts detected MIME (not user-supplied) into filetype column');
    } else {
        bad('file-upload.php inserts detected MIME (not user-supplied)');
    }
} else {
    ok('file-upload.php does not echo $_FILES[type] anywhere');
}

// 3. Extension allowlist (and the previous blocklist removed)
// (Allowlist canonically lives in inc/file-write.php after the Stage 4j
// refactor — file_write_allowed_ext_mime() / file_write_mime_to_ext().)
if ((strpos($src, 'ALLOWED_EXT_MIME') !== false || strpos($srcHelper, 'file_write_allowed_ext_mime') !== false)
    && (strpos($src, 'MIME_TO_EXT') !== false || strpos($srcHelper, 'file_write_mime_to_ext') !== false)) {
    ok('file-upload.php uses extension allowlist');
} else {
    bad('file-upload.php uses extension allowlist');
}
// The original blocklist line is gone
if (strpos($src, "'php', 'phtml', 'php3'") !== false) {
    bad('file-upload.php removed legacy blocklist', 'old blocklist still present');
} else {
    ok('file-upload.php removed legacy blocklist');
}

// 4. Per-entity access via the helper
if (strpos($src, '_file_upload_access') !== false
    && strpos($src, 'user_can_access_entity') !== false) {
    ok('file-upload.php applies per-entity access via user_can_access_entity');
} else {
    bad('file-upload.php applies per-entity access');
}

// 5. Download path forces a safe MIME and sets nosniff
if (strpos($src, 'X-Content-Type-Options: nosniff') !== false
    && strpos($src, '$safeMime') !== false
    && strpos($src, "header('Content-Type: '") !== false) {
    ok('file-upload.php download sets nosniff and a safe Content-Type');
} else {
    bad('file-upload.php download sets nosniff and a safe Content-Type');
}

// 6. Content-Disposition is attachment for non-image/non-pdf
if (strpos($src, "'inline'") !== false && strpos($src, "'attachment'") !== false) {
    ok('file-upload.php uses attachment by default, inline only for image/pdf');
} else {
    bad('file-upload.php uses attachment by default, inline only for image/pdf');
}

// 7. Random filename (not user-supplied) is used for storage
// (Random-naming canonically lives in inc/file-write.php after the
// Stage 4j refactor.)
if (preg_match('/\$storedName\s*=\s*.file_.\s*\.\s*bin2hex\s*\(\s*random_bytes/', $srcAll)) {
    ok('file-upload.php stores files under random names (no user-supplied filename)');
} else {
    bad('file-upload.php stores files under random names');
}

echo "\n=== Results: $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
