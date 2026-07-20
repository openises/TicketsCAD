<?php
/**
 * Phase 112 Phase 5 — county picker + event-type quick picks (repeater alerts).
 *
 * weather_nws_counties() is tested with an INJECTED fetcher (no live NWS call)
 * + its 30-day disk cache; static guards cover the admin API action and the
 * picker UI wiring. Generalizes to any US state — the repeater-alert recipe's
 * building block.
 *
 * Usage: php tests/test_weather_county_picker.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/weather_provider_nws.php';

$base   = realpath(__DIR__ . '/..');
$passed = 0; $failed = 0;
function t($l, $c) { global $passed, $failed; echo ($c ? "[PASS] " : "[FAIL] ") . $l . "\n"; $c ? $passed++ : $failed++; }
function rd($p) { return @file_get_contents($p); }

echo "=== Phase 112 Phase 5 — county picker ===\n\n";

// Use a fake state code so the disk cache never collides with real data.
$STATE = 'ZQ';
$cacheFile = $base . '/cache/nws_counties_' . $STATE . '.json';
@unlink($cacheFile);

$fixture = ['features' => [
    ['properties' => ['id' => 'ZQC003', 'name' => 'Charlie']],
    ['properties' => ['id' => 'ZQC001', 'name' => 'Alpha']],
    ['properties' => ['id' => 'ZQC002', 'name' => 'Bravo']],
    ['properties' => ['id' => '', 'name' => 'NoId']],          // dropped
]];
$calls = 0;
$fetcher = static function ($url, $ua) use ($fixture, &$calls) {
    $calls++;
    return ['ok' => true, 'body' => $fixture];
};

// ── Fetch + normalize + sort ─────────────────────────────────────────────────
$r = weather_nws_counties($STATE, 'test@example.com', $fetcher);
t('counties fetched, id-less rows dropped (3 of 4)', $r['ok'] && count($r['counties']) === 3);
t('counties sorted by name (Alpha, Bravo, Charlie)',
    $r['counties'][0]['name'] === 'Alpha' && $r['counties'][2]['name'] === 'Charlie' &&
    $r['counties'][0]['id'] === 'ZQC001');

// ── Disk cache: second call must NOT hit the fetcher ─────────────────────────
$r2 = weather_nws_counties($STATE, 'test@example.com', $fetcher);
t('second lookup served from the 30-day disk cache (fetcher not called again)',
    $r2['ok'] && count($r2['counties']) === 3 && $calls === 1);
t('cache file exists under cache/', is_file($cacheFile));
@unlink($cacheFile);

// ── Input validation ─────────────────────────────────────────────────────────
$bad = weather_nws_counties('Minnesota', 'x@y.z', $fetcher);
t('non-2-letter state rejected', !$bad['ok']);
$fail = weather_nws_counties($STATE, 'x@y.z', static function () { return ['ok' => false, 'body' => null]; });
t('fetch failure reported, not fatal', !$fail['ok'] && $fail['counties'] === []);
@unlink($cacheFile);

// ── Wiring guards ────────────────────────────────────────────────────────────
$api = rd($base . '/api/weather-alerts.php');
t('admin API exposes nws_counties (admin-gated, UA-contact required)',
    $api !== false && strpos($api, "'nws_counties'") !== false &&
    strpos($api, 'weather_nws_counties(') !== false);
$adm = rd($base . '/weather-alerts.php');
t('area editor has the county picker (state + Pick counties + list)',
    $adm !== false &&
    strpos($adm, 'id="wxCountyState"') !== false &&
    strpos($adm, 'id="wxLoadCounties"') !== false &&
    strpos($adm, 'id="wxCountyList"') !== false);
t('rule editor has the event-type quick picks (Warnings only, Tornado, …)',
    $adm !== false &&
    strpos($adm, 'id="wxRuleQuickTypes"') !== false &&
    strpos($adm, 'data-evt="warning"') !== false &&
    strpos($adm, 'data-evt="tornado warning"') !== false);
$js = rd($base . '/assets/js/weather-config.js');
t('controller wires loadCounties / syncCountiesToCsv / toggleEventTerm',
    $js !== false &&
    strpos($js, 'function loadCounties()') !== false &&
    strpos($js, 'function syncCountiesToCsv()') !== false &&
    strpos($js, 'function toggleEventTerm(term)') !== false);
$docs = rd($base . '/docs/WEATHER-ALERTS-GUIDE.md');
t('guide documents the repeater-alert recipe',
    $docs !== false && strpos($docs, 'automated repeater alerts') !== false);
t('TTS deployment doc exists (Piper + hosted-service design)',
    is_file($base . '/docs/TTS-DEPLOYMENT.md') &&
    strpos((string) rd($base . '/docs/TTS-DEPLOYMENT.md'), 'Piper') !== false);

echo "\n=== $passed passed, $failed failed ===\n";
exit($failed === 0 ? 0 : 1);
