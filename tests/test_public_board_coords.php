<?php
/**
 * Phase 138 — Public incident board: pb_round_coords() pure unit tests.
 *
 * B1's contract must be locked before B3/B5 build on it (tasks.md B4).
 * No DB access — pure function, pure test.
 *
 * Usage: php tests/test_public_board_coords.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/public-board.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== Phase 138 — pb_round_coords() ===\n\n";

// ── exact — no rounding at all ───────────────────────────────────────
$r = pb_round_coords(44.842123, -93.298456, 'exact');
t('exact: lat unrounded', $r['lat'] === 44.842123);
t('exact: lng unrounded', $r['lng'] === -93.298456);

// ── block — 3 decimal places, ~110m ──────────────────────────────────
$r = pb_round_coords(44.842123, -93.298456, 'block');
t('block: lat rounded to 3dp', $r['lat'] === 44.842);
t('block: lng rounded to 3dp', $r['lng'] === -93.298);

// ── city — 2 decimal places, ~1.1km ──────────────────────────────────
$r = pb_round_coords(44.842123, -93.298456, 'city');
t('city: lat rounded to 2dp', $r['lat'] === 44.84);
t('city: lng rounded to 2dp', $r['lng'] === -93.3);

// ── hidden — no lat/lng at all ───────────────────────────────────────
$r = pb_round_coords(44.842123, -93.298456, 'hidden');
t('hidden: lat is null', $r['lat'] === null);
t('hidden: lng is null', $r['lng'] === null);

// ── Edge case: (0, 0) — Null Island, must still round normally ──────
$r = pb_round_coords(0.0, 0.0, 'block');
t('(0,0) at block level: lat is 0.0 not null/false', $r['lat'] === 0.0);
t('(0,0) at block level: lng is 0.0 not null/false', $r['lng'] === 0.0);
$r = pb_round_coords(0.0, 0.0, 'exact');
t('(0,0) at exact level survives unrounded', $r['lat'] === 0.0 && $r['lng'] === 0.0);

// ── Edge case: negative longitude (every US incident) ───────────────
$r = pb_round_coords(45.0, -93.987654, 'block');
t('negative longitude rounds correctly (sign preserved)', $r['lng'] === -93.988);
$r = pb_round_coords(45.0, -93.001, 'city');
t('negative longitude near zero rounds to 2dp correctly', $r['lng'] === -93.0);

// ── Edge case: null input — nothing to round, nothing leaks ─────────
$r = pb_round_coords(null, -93.0, 'exact');
t('null lat with real lng: both null (nothing partial leaks)', $r['lat'] === null && $r['lng'] === null);
$r = pb_round_coords(44.0, null, 'exact');
t('real lat with null lng: both null (nothing partial leaks)', $r['lat'] === null && $r['lng'] === null);
$r = pb_round_coords(null, null, 'block');
t('both null at block level: still both null', $r['lat'] === null && $r['lng'] === null);
$r = pb_round_coords(null, null, 'exact');
t('both null even at exact level (most permissive setting still cannot invent data)', $r['lat'] === null && $r['lng'] === null);

// ── Fail-closed on an unrecognized level string ──────────────────────
$r = pb_round_coords(44.842123, -93.298456, 'bogus-level');
t('unrecognized level fails CLOSED (both null), never leaks a coordinate',
    $r['lat'] === null && $r['lng'] === null);

// ── Return shape sanity ───────────────────────────────────────────────
$r = pb_round_coords(44.0, -93.0, 'exact');
t('return value has exactly the lat/lng keys', array_keys($r) === ['lat', 'lng']);

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
