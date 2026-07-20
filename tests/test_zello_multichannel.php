<?php
/**
 * Zello multi-channel — Phase 1 (channel labels on RX)
 * spec: specs/zello-multichannel-2026-07 (Eric 2026-07-05)
 *
 * Phase 1 hands the browser the configured channel list (api/zello-token.php)
 * and badges each RX feed item with its channel when >1 channel is configured.
 * Single-channel installs stay uncluttered. TX-to-active-channel (Phases 3-4)
 * needs a Zello Work account and is not built here.
 */
$base = realpath(__DIR__ . '/..');
$passed = 0; $failed = 0;
function t($l, $c) { global $passed, $failed; echo ($c ? "[PASS] " : "[FAIL] ") . $l . "\n"; $c ? $passed++ : $failed++; }

echo "=== Zello multi-channel Phase 1 (channel labels) ===\n\n";

// ── Endpoint hands over the channel list ──
$tok = @file_get_contents($base . '/api/zello-token.php');
if ($tok !== false) {
    t("zello-token.php returns dispatch_channel + channels",
        strpos($tok, "'dispatch_channel'") !== false && strpos($tok, "'channels'") !== false);
    t("zello-token.php seeds channels with dispatch first, then de-duped extras",
        strpos($tok, 'zello_dispatch_channel') !== false &&
        strpos($tok, 'zello_extra_channels') !== false &&
        strpos($tok, '!in_array($c, $channels, true)') !== false);
} else { t("zello-token.php readable", false); }

// ── Widget wiring ──
$js = @file_get_contents($base . '/assets/js/zello-widget.js');
if ($js !== false) {
    t("widget captures configuredChannels from the token response",
        substr_count($js, 'configuredChannels = ') >= 2 &&
        strpos($js, 'tokenData.channels') !== false &&
        strpos($js, 'data.channels') !== false);
    t("widget only badges when >1 channel is configured (single-channel stays clean)",
        (bool) preg_match('/function appendChannelBadge\([^)]*\)\s*\{[^}]*configuredChannels\.length <= 1\)\s*return/s', $js));
    t("badge is applied to all four feed render paths (text, image, voice-live, voice-complete)",
        substr_count($js, 'appendChannelBadge(senderDiv, msg.channel)') === 1 &&
        substr_count($js, 'appendChannelBadge(senderDiv, data.channel)') === 3);
} else { t("zello-widget.js readable", false); }

// ── Channel-list build logic (mirrors the endpoint) ──
$build = function ($dispatch, $extraCsv) {
    $channels = [];
    $dispatch = trim($dispatch);
    if ($dispatch !== '') { $channels[] = $dispatch; }
    foreach (explode(',', $extraCsv) as $c) {
        $c = trim($c);
        if ($c !== '' && !in_array($c, $channels, true)) { $channels[] = $c; }
    }
    return $channels;
};
t("dispatch channel is first", $build('Dispatch', 'Ops, Tac')[0] === 'Dispatch');
t("extras appended + trimmed", $build('Dispatch', ' Ops , Tac ') === ['Dispatch', 'Ops', 'Tac']);
t("duplicate of dispatch in extras is dropped", $build('Dispatch', 'Dispatch, Ops') === ['Dispatch', 'Ops']);
t("no channels configured yields empty list (badges suppressed)", $build('', '') === []);

// ── Phase 2 — channel bank UI (buttons + per-channel unread + focus) ──
if ($js !== false) {
    t("Phase 2: renderChannelBank only draws when >1 channel is configured",
        (bool) preg_match('/function renderChannelBank\(\)\s*\{.*?configuredChannels\.length <= 1\)\s*\{[^}]*removeChild/s', $js));
    t("Phase 2: per-channel unread via noteChannelTraffic (skips own TX + the focused channel)",
        (bool) preg_match('/function noteChannelTraffic\([^)]*\)\s*\{[^}]*direction === .outgoing.\)\s*return;[^}]*channel === activeChannel\)\s*return;/s', $js));
    t("Phase 2: noteChannelTraffic wired into the durable RX render paths",
        substr_count($js, 'noteChannelTraffic(msg.channel, msg.direction)') === 1 &&
        substr_count($js, 'noteChannelTraffic(data.channel, data.direction)') === 2);
    t("Phase 2: focused channel is persisted per-browser (localStorage)",
        strpos($js, "CHAN_ACTIVE_KEY = 'newui_zello_active_channel'") !== false &&
        (bool) preg_match('/function setActiveChannel\([^)]*\)\s*\{[^}]*localStorage\.setItem\(CHAN_ACTIVE_KEY/s', $js));
    t("Phase 2: channel-bank buttons focus a channel on click",
        (bool) preg_match('/addEventListener\(.click., function \(\)\s*\{\s*setActiveChannel\(this\.getAttribute\(.data-channel.\)\)/s', $js));
    t("Phase 2: bank is built with DOM nodes (textContent), not innerHTML (XSS-safe)",
        strpos($js, 'btn.textContent = ch;') !== false);
}

echo "\n=== $passed passed, $failed failed ===\n";
exit($failed === 0 ? 0 : 1);
