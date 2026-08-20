<?php
/**
 * GH#64 — pure interval-math tests for inc/interval-report.php.
 *
 * These drive interval_report_ts()/interval_report_diff()/interval_report_fmt()/
 * interval_report_compute() directly with synthetic edge-case inputs — that's
 * legitimate here (unlike hand-seeding a DATABASE row to fake what a real
 * writer would produce, which this project's CLAUDE.md explicitly warns
 * against): these are pure functions with no DB dependency, and the
 * synthetic inputs exist specifically to exercise edge cases real dispatch
 * traffic may or may not hit (a zero-date sentinel, a clock running
 * backwards, a fully partial milestone set). The companion
 * tests/test_interval_report_integration.php drives the SAME functions
 * against rows populated by the real writers (assign_create_internal() /
 * assign_update_status_internal()) to prove the read side works against
 * genuine production output, not just synthetic input.
 *
 * Usage: php tests/test_interval_report_math.php
 */

require_once __DIR__ . '/../inc/interval-report.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== GH#64 — interval-report pure math ===\n\n";

// ── interval_report_ts() ────────────────────────────────────────────────
echo "--- interval_report_ts() ---\n";
t('null input -> null', interval_report_ts(null) === null);
t('empty string -> null', interval_report_ts('') === null);
t('zero-date sentinel -> null', interval_report_ts('0000-00-00 00:00:00') === null);
t('a real datetime string parses to a timestamp', interval_report_ts('2026-08-16 12:21:07') === strtotime('2026-08-16 12:21:07'));
t('garbage string -> null (strtotime failure)', interval_report_ts('not-a-date') === null);

// ── interval_report_diff() ──────────────────────────────────────────────
echo "\n--- interval_report_diff() ---\n";
t('both null -> null', interval_report_diff(null, null) === null);
t('start null, end set -> null', interval_report_diff(null, '2026-08-16 12:21:07') === null);
t('start set, end null -> null', interval_report_diff('2026-08-16 12:21:07', null) === null);
t('start set, end zero-date -> null (sentinel treated as unset)', interval_report_diff('2026-08-16 12:21:07', '0000-00-00 00:00:00') === null);
t(
    'normal case computes exact seconds (90s turnout)',
    interval_report_diff('2026-08-16 12:21:07', '2026-08-16 12:22:37') === 90
);
t(
    'a multi-hour span computes correctly (not truncated at 60 min)',
    interval_report_diff('2026-08-16 08:00:00', '2026-08-16 10:05:30') === (2 * 3600 + 5 * 60 + 30)
);
t(
    'negative diff (clock ran backwards / bad data) -> null, never a negative "garbage" duration',
    interval_report_diff('2026-08-16 12:30:00', '2026-08-16 12:00:00') === null
);
t('zero-second diff is a legitimate 0, not null', interval_report_diff('2026-08-16 12:00:00', '2026-08-16 12:00:00') === 0);

// ── interval_report_fmt() ───────────────────────────────────────────────
echo "\n--- interval_report_fmt() ---\n";
t('null -> empty string (blank cell, not "0:00")', interval_report_fmt(null) === '');
t('negative -> empty string', interval_report_fmt(-5) === '');
t('0 seconds -> "0:00"', interval_report_fmt(0) === '0:00');
t('90 seconds -> "1:30"', interval_report_fmt(90) === '1:30');
t('59 seconds -> "0:59"', interval_report_fmt(59) === '0:59');
t('7530 seconds (2h05m30s) -> "125:30" (unbounded minutes, matches dispatch_log convention)', interval_report_fmt(7530) === '125:30');

// ── interval_report_compute() — the common no-transport case ───────────
echo "\n--- interval_report_compute(): no-transport call (the common case) ---\n";
$noTransport = [
    'dispatched' => '2026-08-16 12:21:07',
    'responding' => '2026-08-16 12:21:29',
    'on_scene'   => '2026-08-16 12:25:26',
    'u2fenr'     => null,
    'u2farr'     => null,
    'clear'      => '2026-08-16 12:47:50',
];
$legs = interval_report_compute($noTransport);
t('turnout computed (dispatched->responding)', $legs['turnout_secs'] === 22);
t('travel computed (responding->on_scene)', $legs['travel_secs'] === (3 * 60 + 57));
t('response computed (dispatched->on_scene)', $legs['response_secs'] === (4 * 60 + 19));
t('scene falls back to clear when u2fenr unset', $legs['scene_secs'] === interval_report_diff($noTransport['on_scene'], $noTransport['clear']));
t('transport is null — no facility leg happened, never an error', $legs['transport_secs'] === null);
t('total computed (dispatched->clear)', $legs['total_secs'] === interval_report_diff($noTransport['dispatched'], $noTransport['clear']));

// ── interval_report_compute() — the full six-milestone transport case ──
// (Mirrors the exact real-world example rjonesbsink posted on GH#64:
// dispatched 12:21:07, responding 12:21:29, on_scene 12:25:26,
// u2fenr 12:26:37, u2farr 12:27:23, clear 12:27:50.)
echo "\n--- interval_report_compute(): full transport (GH#64's real example) ---\n";
$full = [
    'dispatched' => '2026-08-16 12:21:07',
    'responding' => '2026-08-16 12:21:29',
    'on_scene'   => '2026-08-16 12:25:26',
    'u2fenr'     => '2026-08-16 12:26:37',
    'u2farr'     => '2026-08-16 12:27:23',
    'clear'      => '2026-08-16 12:27:50',
];
$legs = interval_report_compute($full);
t('turnout = 22s', $legs['turnout_secs'] === 22);
t('travel = 237s', $legs['travel_secs'] === 237);
t('response = 259s', $legs['response_secs'] === 259);
t('scene prefers u2fenr over clear when BOTH are set (71s, on_scene->u2fenr)', $legs['scene_secs'] === 71);
t('transport = 46s (u2fenr->u2farr)', $legs['transport_secs'] === 46);
t('total = 403s (dispatched->clear)', $legs['total_secs'] === 403);

// ── interval_report_compute() — fully empty row (never errors) ─────────
echo "\n--- interval_report_compute(): fully empty row ---\n";
$empty = ['dispatched' => null, 'responding' => null, 'on_scene' => null, 'u2fenr' => null, 'u2farr' => null, 'clear' => null];
$legs = interval_report_compute($empty);
t('every leg is null, no exception thrown', $legs === [
    'turnout_secs' => null, 'travel_secs' => null, 'response_secs' => null,
    'scene_secs' => null, 'transport_secs' => null, 'total_secs' => null,
]);

// ── interval_report_compute() — missing array keys (defensive) ─────────
echo "\n--- interval_report_compute(): missing keys entirely ---\n";
$legs = interval_report_compute([]);
t('an empty input array still returns all-null legs, never a PHP notice/error', $legs['response_secs'] === null && $legs['total_secs'] === null);

// ── interval_report_compute() — only dispatched+on_scene (a status config
// that skips "responding" entirely, or a dispatcher who jumped straight to
// On Scene) — response must still compute even with turnout/travel both null.
echo "\n--- interval_report_compute(): only dispatched + on_scene set ---\n";
$skipResponding = [
    'dispatched' => '2026-08-16 12:00:00',
    'responding' => null,
    'on_scene'   => '2026-08-16 12:06:00',
    'u2fenr'     => null,
    'u2farr'     => null,
    'clear'      => null,
];
$legs = interval_report_compute($skipResponding);
t('turnout null (no responding milestone)', $legs['turnout_secs'] === null);
t('travel null (no responding milestone)', $legs['travel_secs'] === null);
t('response STILL computes directly from dispatched->on_scene (360s)', $legs['response_secs'] === 360);
t('scene null (no clear, no u2fenr — call never ended in this data)', $legs['scene_secs'] === null);
t('total null (no clear)', $legs['total_secs'] === null);

echo "\n=== Results: $pass passed, $fail failed ===\n";
exit($fail === 0 ? 0 : 1);
