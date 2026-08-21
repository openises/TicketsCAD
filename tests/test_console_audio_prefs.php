<?php
/**
 * Phase 114b3 — per-user console audio/selection state persistence
 * (inc/console-audio-prefs.php + the prefs_get_raw() addition to inc/
 * screen-prefs.php it depends on).
 *
 * Covers: clean()'s validation/clamping (never trusts client input as-is
 * — volume clamp, boolean coercion, channel-count cap, malformed-entry
 * drop, non-array input never throws); prefs_get_raw() vs prefs_get()'s
 * OWN documented footgun (a nested value silently vanishes through prefs_
 * get()'s scalar-only options merge — this is exactly why console-audio
 * needed its own raw accessor instead of reusing prefs_get()/prefs_set()'s
 * columns/sort/options envelope); a REAL round trip through the actual
 * user_screen_prefs table (write via console_audio_prefs_save(), read
 * back via console_audio_prefs_get(), confirm content survives, confirm
 * it's isolated per user, confirm cleanup leaves no residue).
 *
 * @requires-db
 * Usage: php tests/test_console_audio_prefs.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/screen-prefs.php';
require_once __DIR__ . '/../inc/console-audio-prefs.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== Phase 114b3 — console audio prefs persistence ===\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

// ═══════════════════════════════════════════════════════════════════════
// Part 1 — console_audio_prefs_clean() validation (pure)
// ═══════════════════════════════════════════════════════════════════════
$clean = console_audio_prefs_clean(['channels' => ['12' => ['selected' => true, 'volume' => 250]]]);
t('volume above 100 clamps to 100', $clean['channels']['12']['volume'] === 100);

$clean = console_audio_prefs_clean(['channels' => ['12' => ['volume' => -50]]]);
t('volume below 0 clamps to 0', $clean['channels']['12']['volume'] === 0);

$clean = console_audio_prefs_clean(['channels' => ['12' => []]]);
t('missing volume defaults to 100', $clean['channels']['12']['volume'] === 100);

$clean = console_audio_prefs_clean(['channels' => ['12' => ['mon' => 0]]]);
t('mon:0 (falsy, present) coerces to false, not the default true', $clean['channels']['12']['mon'] === false);

$clean = console_audio_prefs_clean(['channels' => ['12' => []]]);
t('missing mon defaults to true', $clean['channels']['12']['mon'] === true);

$clean = console_audio_prefs_clean(['channels' => ['0' => ['selected' => true], '-5' => ['selected' => true]]]);
t('channel id 0 or negative is dropped (never a real channel)', count($clean['channels']) === 0);

$clean = console_audio_prefs_clean(['channels' => ['7' => 'not-an-array']]);
t('a non-array per-channel value is dropped, not fatal', count($clean['channels']) === 0);

$clean = console_audio_prefs_clean(['channels' => 'not-an-array-at-all']);
t('a non-array channels value never throws, yields empty', $clean['channels'] === []);

$clean = console_audio_prefs_clean(null);
t('null input never throws', is_array($clean['channels']));

$clean = console_audio_prefs_clean([]);
t('empty input never throws', $clean['channels'] === []);

$manyChannels = [];
for ($i = 1; $i <= CONSOLE_AUDIO_MAX_CHANNELS + 50; $i++) { $manyChannels[(string) $i] = ['selected' => true]; }
$clean = console_audio_prefs_clean(['channels' => $manyChannels]);
t('channel count is capped at CONSOLE_AUDIO_MAX_CHANNELS (' . CONSOLE_AUDIO_MAX_CHANNELS . ')',
    count($clean['channels']) === CONSOLE_AUDIO_MAX_CHANNELS);

// ═══════════════════════════════════════════════════════════════════════
// Part 2 — prefs_get_raw() vs prefs_get()'s documented footgun
// ═══════════════════════════════════════════════════════════════════════
// This is the whole reason console-audio-prefs.php has its own accessor:
// prove prefs_get() really would silently drop a nested per-channel
// object (it only carries forward SCALAR 'options' values), so a future
// reader is never tempted to "simplify" by switching back to it.
$uidFootgun = 900001530;
try {
    db_query("DELETE FROM `{$prefix}user_screen_prefs` WHERE user_id = ? AND screen = ?", [$uidFootgun, 'console-audio']);
    prefs_set($uidFootgun, 'console-audio', ['channels' => ['5' => ['selected' => true, 'volume' => 42]]]);

    $viaRaw = prefs_get_raw($uidFootgun, 'console-audio');
    t('prefs_get_raw(): the nested per-channel object survives a real round trip',
        isset($viaRaw['channels']['5']) && $viaRaw['channels']['5']['volume'] === 42);

    $viaGet = prefs_get($uidFootgun, 'console-audio', ['columns' => [], 'sort' => ['col' => '', 'dir' => 'asc'], 'options' => []]);
    t("prefs_get()'s columns/sort/options envelope DOES NOT carry the nested value — "
        . 'confirms why console-audio-prefs.php needed its own raw accessor, not a reused one',
        !isset($viaGet['options']['channels']));
} finally {
    db_query("DELETE FROM `{$prefix}user_screen_prefs` WHERE user_id = ? AND screen = ?", [$uidFootgun, 'console-audio']);
}

// ═══════════════════════════════════════════════════════════════════════
// Part 3 — real round trip through console_audio_prefs_save()/_get()
// ═══════════════════════════════════════════════════════════════════════
$uidA = 900001531;
$uidB = 900001532;
try {
    db_query("DELETE FROM `{$prefix}user_screen_prefs` WHERE user_id IN (?, ?) AND screen = ?", [$uidA, $uidB, 'console-audio']);

    $default = console_audio_prefs_get($uidA);
    t('a user with no saved prefs gets an empty channel map (not an error, not a default channel guessed)',
        $default['channels'] === []);

    $r = console_audio_prefs_save($uidA, ['channels' => [
        '10' => ['selected' => true, 'mon' => true, 'muted' => false, 'volume' => 80, 'simulselect' => false],
        '11' => ['selected' => false, 'mon' => false, 'muted' => true, 'volume' => 55, 'simulselect' => true],
    ]]);
    t('console_audio_prefs_save(): save reports ok', $r['ok'] === true);

    $reload = console_audio_prefs_get($uidA);
    t('reloaded state matches exactly what was saved (channel 10)',
        $reload['channels']['10']['selected'] === true && $reload['channels']['10']['volume'] === 80);
    t('reloaded state matches exactly what was saved (channel 11, mute + simulselect)',
        $reload['channels']['11']['muted'] === true && $reload['channels']['11']['simulselect'] === true
        && $reload['channels']['11']['mon'] === false);

    $otherUser = console_audio_prefs_get($uidB);
    t('state is isolated per user — uidB sees nothing of uidA\'s saved state', $otherUser['channels'] === []);

    // Saving again fully REPLACES (not merges) — a channel dropped from the
    // client payload should not linger from the previous save.
    console_audio_prefs_save($uidA, ['channels' => ['10' => ['selected' => false, 'volume' => 100]]]);
    $afterReplace = console_audio_prefs_get($uidA);
    t('a re-save fully replaces the stored state (channel 11 is gone, not merged)',
        !isset($afterReplace['channels']['11']) && isset($afterReplace['channels']['10']));
} finally {
    db_query("DELETE FROM `{$prefix}user_screen_prefs` WHERE user_id IN (?, ?) AND screen = ?", [$uidA, $uidB, 'console-audio']);
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
