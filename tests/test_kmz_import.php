<?php
/**
 * maps-comprehensive-2026-06 — Feature 3: KMZ import.
 *
 * Verifies:
 *   - kmz_extract_kml() unzips a real KMZ and pulls out doc.kml
 *   - falls back to the first *.kml entry when doc.kml is absent
 *   - kmz_to_mmarkup_rows() round-trips KMZ → mmarkup rows (reusing the KML
 *     converter), so polygons/lines/points survive the zip
 *   - a corrupt/empty zip throws RuntimeException (caller returns json_error,
 *     no crash)
 *   - plain .kml import still works through kml_to_mmarkup_rows()
 *   - the API + config.js + settings.php wiring is in place
 *
 * Functional where it can be: the converter is pure (no DB), so we exercise it
 * directly. ZipArchive is available in the XAMPP CLI.
 */

declare(strict_types=1);

require_once __DIR__ . '/../inc/map-format-converters.php';

$pass = 0; $fail = 0; $failures = [];
function ok(bool $v, string $what): void {
    global $pass, $fail, $failures;
    if ($v) { $pass++; return; }
    $fail++; $failures[] = "FAIL: $what";
}
function has(string $h, string $n, string $what): void { ok(strpos($h, $n) !== false, $what); }

// ── Build a small KML document with a polygon, a line, and a point ──
function sample_kml(): string {
    return '<?xml version="1.0" encoding="UTF-8"?>'
        . '<kml xmlns="http://www.opengis.net/kml/2.2"><Document>'
        . '<Placemark><name>Zone A</name><Polygon><outerBoundaryIs><LinearRing>'
        . '<coordinates>-93.25,44.95,0 -93.24,44.95,0 -93.24,44.96,0 -93.25,44.96,0 -93.25,44.95,0</coordinates>'
        . '</LinearRing></outerBoundaryIs></Polygon></Placemark>'
        . '<Placemark><name>Route 1</name><LineString>'
        . '<coordinates>-93.25,44.95,0 -93.20,44.97,0</coordinates></LineString></Placemark>'
        . '<Placemark><name>Marker X</name><Point>'
        . '<coordinates>-93.22,44.955,0</coordinates></Point></Placemark>'
        . '</Document></kml>';
}

// ── Helper: build a KMZ (zip) in memory with a chosen inner filename ──
function build_kmz(string $innerName, string $kml): string {
    $tmp = tempnam(sys_get_temp_dir(), 'kmztest_');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);
    $zip->addFromString($innerName, $kml);
    $zip->close();
    $bytes = file_get_contents($tmp);
    @unlink($tmp);
    return $bytes;
}

// ── 1. doc.kml convention ──
$kmzDoc = build_kmz('doc.kml', sample_kml());
$extracted = kmz_extract_kml($kmzDoc);
has($extracted, '<kml', 'kmz_extract_kml: doc.kml extracted as KML');
has($extracted, 'Zone A', 'kmz_extract_kml: KML content preserved');

// ── 2. fallback to first *.kml when doc.kml absent ──
$kmzOther = build_kmz('overlays/zones.kml', sample_kml());
$extracted2 = kmz_extract_kml($kmzOther);
has($extracted2, 'Route 1', 'kmz_extract_kml: falls back to first *.kml entry');

// ── 3. KMZ round-trips to mmarkup rows ──
$rows = kmz_to_mmarkup_rows($kmzDoc, 7);
ok(count($rows) === 3, 'kmz_to_mmarkup_rows: 3 shapes imported (poly, line, point)');
$types = array_map(function ($r) { return $r['type']; }, $rows);
ok(in_array('P', $types, true), 'kmz import: polygon present');
ok(in_array('L', $types, true), 'kmz import: line present');
ok(in_array('M', $types, true), 'kmz import: marker/point present');
ok($rows[0]['category_id'] === 7, 'kmz import: default category applied');
// Coordinates survived the trip (first polygon vertex lat ~44.95).
$polyCoords = json_decode($rows[0]['coordinates'], true);
ok(is_array($polyCoords) && abs($polyCoords[0][0] - 44.95) < 0.001, 'kmz import: polygon coords intact');

// ── 4. corrupt / empty zip handled gracefully ──
$threw = false;
try { kmz_extract_kml('this is not a zip at all'); }
catch (RuntimeException $e) { $threw = true; }
ok($threw, 'corrupt KMZ throws RuntimeException (no crash)');

$threwEmpty = false;
try { kmz_extract_kml(''); }
catch (RuntimeException $e) { $threwEmpty = true; }
ok($threwEmpty, 'empty KMZ throws RuntimeException');

// ── 5. a valid zip with no KML inside ──
$kmzNoKml = build_kmz('readme.txt', 'no kml here');
$threwNoKml = false;
try { kmz_extract_kml($kmzNoKml); }
catch (RuntimeException $e) { $threwNoKml = true; }
ok($threwNoKml, 'zip without a KML throws RuntimeException');

// ── 6. plain .kml still works (no regression) ──
$kmlRows = kml_to_mmarkup_rows(sample_kml(), null);
ok(count($kmlRows) === 3, 'plain KML still imports 3 shapes (no regression)');

// ── 7. API + UI wiring ──
$api = file_get_contents(__DIR__ . '/../api/map-markups.php');
has($api, "\$fmt === 'kmz'", 'map-markups API handles the kmz format');
has($api, 'kmz_to_mmarkup_rows', 'API calls the KMZ converter');
has($api, 'base64_decode', 'API decodes base64 KMZ payload');
has($api, 'RuntimeException', 'API catches RuntimeException for bad zips');

$cfg = file_get_contents(__DIR__ . '/../assets/js/config.js');
has($cfg, ".endsWith('.kmz')", 'config.js detects .kmz uploads');
has($cfg, 'readAsDataURL', 'config.js reads KMZ as base64 data URL');
has($cfg, "content_encoding = 'base64'", 'config.js flags base64 encoding');

$settings = file_get_contents(__DIR__ . '/../settings.php');
has($settings, '.kmz', 'settings.php file input accepts .kmz');
has($settings, 'KMZ (zipped KML)', 'settings.php import label is honest about KMZ');

// ── Report ──
echo "Feature 3 — KMZ import tests\n";
echo "============================\n";
if ($fail > 0) {
    foreach ($failures as $m) echo "$m\n";
}
// Runner-compatible results line ("N passed, M failed").
echo "$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
