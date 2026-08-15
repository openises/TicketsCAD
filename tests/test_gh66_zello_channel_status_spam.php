<?php
/**
 * GH#66 (Ron Jones, 2026-08-15): Zello sends on_channel_status on both
 * join AND leave, with `status` unchanged ("online" both times) — Ron
 * measured 8 channel-status frames across 4 join/leave cycles, 0 logons
 * among them, so these are not reconnect artifacts. The widget appended
 * "Channel 'X' is online" to the message box on every single one, with
 * no count and no name; on a busy channel it becomes the only thing in
 * the box.
 *
 * Three fixes, tested here:
 *
 * 1. proxy/ZelloUpstream.php now dedupes the message-box forward: only
 *    fires when (channel, status, error) actually changed since the
 *    last frame for that channel. Keyed case-insensitively — Ron's own
 *    log shows the same channel arriving as both 'TicketsCad-CleveOps'
 *    and 'ticketscad-cleveops'.
 * 2. proxy/ZelloProxyApp.php's structured channel_status broadcast used
 *    `$data['users_online'] ?? 0`, collapsing "Zello did not report
 *    occupancy" and "zero people online" into the same value. Now
 *    preserves the absence as null.
 * 3. assets/js/zello-widget.js's label renderer now distinguishes null
 *    (unknown — falls back to the status word) from an explicit 0
 *    (renders "(0 online)").
 *
 * Fix 1 is driven live via Reflection against the real
 * handleUpstreamMessage(), mirroring tests/test_gh21_channel_status_
 * error.php's established pattern for this exact class. Fixes 2 and 3
 * are mechanical one-line changes verified at the source-text level —
 * ZelloProxyApp's broadcast() has no reachable client to intercept
 * without a fake WebSocket connection, so a live drive would need far
 * more scaffolding than the two-line change it is proving.
 */

require __DIR__ . '/../config.php';

if (!function_exists('plog')) {
    function plog($msg) { /* silent in tests */ }
}

if (!file_exists(__DIR__ . '/../vendor/autoload.php')) {
    echo "SKIP: vendor/autoload.php not found (run 'composer install' to enable Zello proxy tests)\n";
    echo "=== 0 passed, 0 failed ===\n";
    exit(0);
}
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../proxy/ZelloUpstream.php';

use NewUI\Proxy\ZelloUpstream;

echo "=== GH#66: Zello channel_status dedup + honest users_online ===\n\n";
$pass = 0;
$fail = 0;

function t66($label, $cond, $hint = '') {
    global $pass, $fail;
    if ($cond) { echo "  [PASS] $label\n"; $pass++; }
    else       { echo "  [FAIL] $label" . ($hint !== '' ? " — $hint" : '') . "\n"; $fail++; }
}

$loop = \React\EventLoop\Loop::get();

$statusEvents = [];
$onStatus  = function ($type, $detail) use (&$statusEvents) { $statusEvents[] = [$type, $detail]; };
$onMessage = function ($data) { /* not exercised by this test */ };

$ref = new ReflectionMethod(ZelloUpstream::class, 'handleUpstreamMessage');
$ref->setAccessible(true);

// Fresh upstream (and fresh $statusEvents) per section — the dedup
// fingerprint is per-instance state, so sections must not bleed into
// each other any more than two real proxy restarts would.
function newUpstream66($loop, $onMessage, $onStatus): ZelloUpstream {
    return new ZelloUpstream($loop, ['zello_dispatch_channel' => 'TicketsCad-CleveOps'], $onMessage, $onStatus);
}

function fireFrame66(ReflectionMethod $ref, ZelloUpstream $upstream, array $data): void {
    $ref->invoke($upstream, json_encode($data));
}

function channelStatusCount(array $events): int {
    $n = 0;
    foreach ($events as $e) { if ($e[0] === 'channel_status') { $n++; } }
    return $n;
}

$onlineFrame = [
    'command'      => 'on_channel_status',
    'channel'      => 'TicketsCad-CleveOps',
    'status'       => 'online',
    'users_online' => 3,
];

// ── 1. A join immediately followed by a leave — the exact shape Ron
//    measured (status unchanged, no logon in between) — must forward
//    to the widget only ONCE, not twice. ──
$statusEvents = [];
$up1 = newUpstream66($loop, $onMessage, $onStatus);
fireFrame66($ref, $up1, $onlineFrame);              // "join"
fireFrame66($ref, $up1, $onlineFrame);               // "leave" (identical frame)
t66('an identical repeat frame is NOT forwarded a second time',
    channelStatusCount($statusEvents) === 1,
    'forwarded ' . channelStatusCount($statusEvents) . ' times, expected 1');

// ── 2. Four join/leave cycles (Ron's exact reproduction count) ──
$statusEvents = [];
$up2 = newUpstream66($loop, $onMessage, $onStatus);
for ($i = 0; $i < 4; $i++) {
    fireFrame66($ref, $up2, $onlineFrame);
    fireFrame66($ref, $up2, $onlineFrame);
}
t66('4 join/leave cycles of an unchanged status forward exactly once total',
    channelStatusCount($statusEvents) === 1,
    'forwarded ' . channelStatusCount($statusEvents) . ' times, expected 1');

// ── 3. A GENUINE change (status flips) must still forward — dedup must
//    not get stuck permanently suppressing this channel. ──
$statusEvents = [];
$up3 = newUpstream66($loop, $onMessage, $onStatus);
fireFrame66($ref, $up3, $onlineFrame);
fireFrame66($ref, $up3, ['command' => 'on_channel_status', 'channel' => 'TicketsCad-CleveOps', 'status' => 'offline']);
t66('a real status change still forwards after a prior identical frame',
    channelStatusCount($statusEvents) === 2,
    'forwarded ' . channelStatusCount($statusEvents) . ' times, expected 2');

// ── 4. Case-insensitive dedup key — Ron's own log shows the same
//    channel under two castings. ──
$statusEvents = [];
$up4 = newUpstream66($loop, $onMessage, $onStatus);
fireFrame66($ref, $up4, $onlineFrame); // 'TicketsCad-CleveOps'
fireFrame66($ref, $up4, [
    'command' => 'on_channel_status', 'channel' => 'ticketscad-cleveops', // lowercased
    'status' => 'online', 'users_online' => 3,
]);
t66('the same channel under a different casing is still deduped against the first frame',
    channelStatusCount($statusEvents) === 1,
    'forwarded ' . channelStatusCount($statusEvents) . ' times, expected 1 (case-insensitive dedup failed)');

// ── 5. A DIFFERENT channel is tracked independently — dedup state must
//    not bleed across channels. ──
$statusEvents = [];
$up5 = newUpstream66($loop, $onMessage, $onStatus);
fireFrame66($ref, $up5, $onlineFrame); // TicketsCad-CleveOps
fireFrame66($ref, $up5, [
    'command' => 'on_channel_status', 'channel' => 'SomeOtherChannel',
    'status' => 'online', 'users_online' => 3,
]);
t66('a different channel with the same status is NOT deduped against an unrelated channel',
    channelStatusCount($statusEvents) === 2,
    'forwarded ' . channelStatusCount($statusEvents) . ' times, expected 2');

// ── Source-level checks for fixes 2 and 3 (mechanical, no reachable
//    broadcast client to intercept live) ──
$appSrc = file_get_contents(__DIR__ . '/../proxy/ZelloProxyApp.php');
$onChannelStatusStart = strpos($appSrc, "if (\$command === 'on_channel_status')");
$onChannelStatusBlock = $onChannelStatusStart !== false
    ? substr($appSrc, $onChannelStatusStart, 1000) : '';
t66('ZelloProxyApp no longer collapses an absent users_online to 0 (the actual assignment line, not just prose mentioning the old pattern)',
    strpos($onChannelStatusBlock, "'users_online'   => \$data['users_online'] ?? 0,") === false);
t66('ZelloProxyApp preserves users_online absence as null (array_key_exists guard)',
    strpos($onChannelStatusBlock, "array_key_exists('users_online'") !== false);

$widgetSrc = file_get_contents(__DIR__ . '/../assets/js/zello-widget.js');
$caseStart = strpos($widgetSrc, "case 'channel_status':");
$caseBlock = $caseStart !== false ? substr($widgetSrc, $caseStart, 900) : '';
t66('the widget label renderer distinguishes null/undefined from an explicit 0',
    strpos($caseBlock, 'data.users_online !== null') !== false
    && strpos($caseBlock, 'data.users_online !== undefined') !== false);
t66('the widget no longer uses bare truthiness on users_online (would treat 0 same as absent)',
    !preg_match('/case \'channel_status\':.{0,200}if \(data\.users_online\) \{/s', $widgetSrc));

echo "\n=== " . $pass . " passed, " . $fail . " failed ===\n";
exit($fail > 0 ? 1 : 0);
