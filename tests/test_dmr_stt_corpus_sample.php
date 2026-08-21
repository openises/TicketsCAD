<?php
/**
 * DMR/AMBE STT measurement harness — corpus sampler self-test.
 *
 * Drives the REAL parsing/classification/sampling/manifest-merge functions
 * in inc/dmr_stt_corpus.php against a synthetic echo_bot.py-shaped log
 * (built to match that file's actual log lines byte-for-byte, including
 * two real examples lifted from the live dvswitch-host host on 2026-08-21 —
 * the hallucination-loop call and one callsign-chaos call) and a temp
 * directory of placeholder "audio" files (empty files — this test proves
 * the FILE-HANDLING and MANIFEST logic, not audio content, so a zero-byte
 * placeholder is the correct fixture, not a shortcut).
 *
 * Needs no network, no real audio, no database, no live host — runs
 * identically in CI. Separate from tests/test_dmr_stt_corpus_measurement.php,
 * which scores the REAL corpus (and SKIPs cleanly when it doesn't exist).
 *
 * Usage: php tests/test_dmr_stt_corpus_sample.php
 */

require_once __DIR__ . '/../inc/dmr_stt_corpus.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== DMR/AMBE STT — corpus sampler self-test (synthetic fixtures) ===\n\n";

// ── Fixture log text — real echo_bot.py line shapes, including two blocks
//    transcribed verbatim from the live dvswitch-host host (2026-08-21) so
//    the parser is proven against text it will actually see, not just an
//    idealized shape. ──────────────────────────────────────────────────
$FIXTURE_LOG = <<<'LOG'
Aug 20 04:35:53 dvswitch-host python3[75114]: 2026-08-20 04:35:53,027 INFO call de000000 ended — 88 packets, decoding
Aug 20 04:35:53 dvswitch-host python3[75114]: 2026-08-20 04:35:53,027 INFO voice payloads: 84  src=3227191  tg=3127
Aug 20 04:35:53 dvswitch-host python3[75114]: 2026-08-20 04:35:53,249 INFO wrote /var/cache/ticketscad-dvswitch/minnesota-statewide-20260820-043547-3227191-tg3127-de000000.wav (5.04 sec)
Aug 20 04:35:55 dvswitch-host python3[75114]: 2026-08-20 04:35:55,499 INFO STT (2.25s): 'This is KF0ZGT, radio test, radio test.'
Aug 20 11:56:39 dvswitch-host python3[75114]: 2026-08-20 11:56:39,591 INFO call e3000000 ended — 97 packets, decoding
Aug 20 11:56:39 dvswitch-host python3[75114]: 2026-08-20 11:56:39,591 INFO voice payloads: 93  src=3127075  tg=3127
Aug 20 11:56:39 dvswitch-host python3[75114]: 2026-08-20 11:56:39,816 INFO wrote /var/cache/ticketscad-dvswitch/minnesota-statewide-20260820-115628-3127075-tg3127-e3000000.wav (5.58 sec)
Aug 20 11:56:51 dvswitch-host python3[75114]: 2026-08-20 11:56:51,176 INFO STT (11.36s): "How often could I do that feel? Do I have a sound? When I'm faj... When I'm faj... ...faj... ...faj... ...faj... ...faj... ...faj..."
Aug 20 12:27:07 dvswitch-host python3[75114]: 2026-08-20 12:27:07,767 INFO call e4000000 ended — 40 packets, decoding
Aug 20 12:27:07 dvswitch-host python3[75114]: 2026-08-20 12:27:07,767 INFO voice payloads: 36  src=3144889  tg=3127
Aug 20 12:27:07 dvswitch-host python3[75114]: 2026-08-20 12:27:07,851 INFO wrote /var/cache/ticketscad-dvswitch/minnesota-statewide-20260820-122702-3144889-tg3127-e4000000.wav (2.16 sec)
Aug 20 12:27:09 dvswitch-host python3[75114]: 2026-08-20 12:27:09,534 INFO STT (1.68s): 'BZ.'
Aug 20 00:08:06 dvswitch-host python3[75114]: 2026-08-20 00:08:06,752 INFO call c0000000 ended — 712 packets, decoding
Aug 20 00:08:06 dvswitch-host python3[75114]: 2026-08-20 00:08:06,752 INFO voice payloads: 708  src=3127202  tg=3127
Aug 20 00:08:08 dvswitch-host python3[75114]: 2026-08-20 00:08:08,301 INFO wrote /var/cache/ticketscad-dvswitch/minnesota-statewide-20260820-000735-3127202-tg3127-c0000000.wav (42.48 sec)
Aug 20 00:08:22 dvswitch-host python3[75114]: 2026-08-20 00:08:22,397 INFO STT (14.10s): 'the'
Aug 17 02:40:09 dvswitch-host python3[75114]: 2026-08-17 02:40:09,297 INFO call ee000000 ended — 30 packets, decoding
Aug 17 02:40:09 dvswitch-host python3[75114]: 2026-08-17 02:40:09,297 INFO voice payloads: 26  src=3127300  tg=3127
Aug 17 02:40:09 dvswitch-host python3[75114]: 2026-08-17 02:40:09,400 INFO wrote /var/cache/ticketscad-dvswitch/minnesota-statewide-20260817-024005-3127300-tg3127-ee000000.wav (1.75 sec)
Aug 17 02:40:09 dvswitch-host python3[75114]: 2026-08-17 02:40:09,410 INFO STT (0.11s): ''
Aug 17 02:40:09 dvswitch-host python3[75114]: 2026-08-17 02:40:09,411 INFO empty STT — no reply
Aug 17 02:41:09 dvswitch-host python3[75114]: 2026-08-17 02:41:09,297 INFO call ef000000 ended — 4 packets, decoding
Aug 17 02:41:09 dvswitch-host python3[75114]: 2026-08-17 02:41:09,297 INFO voice payloads: 0  src=0  tg=0
LOG;

// ── dmr_stt_parse_echo_bot_log() ─────────────────────────────────────────
echo "--- dmr_stt_parse_echo_bot_log() ---\n";
$calls = dmr_stt_parse_echo_bot_log($FIXTURE_LOG);
t('parses exactly 5 call blocks (the 6th block has zero voice payloads / no wav — correctly dropped)', count($calls) === 5);

$byId = [];
foreach ($calls as $c) $byId[$c['call_id']] = $c;

t('preserves call_id case-insensitively as lowercase hex', isset($byId['de000000']) && isset($byId['e3000000']));
t('extracts src_radio_id and talkgroup', $byId['de000000']['src_radio_id'] === '3227191' && $byId['de000000']['talkgroup'] === '3127');
t('extracts audio_duration_sec from the "wrote" line', abs($byId['de000000']['audio_duration_sec'] - 5.04) < 1e-9);
t('extracts wav_basename via basename() of the logged (remote) path',
    $byId['de000000']['wav_basename'] === 'minnesota-statewide-20260820-043547-3227191-tg3127-de000000.wav');
t('derives started_at from the filename-embedded timestamp',
    $byId['de000000']['started_at_from_filename'] === '2026-08-20T04:35:47');

// Python-repr unquoting: single-quoted transcript with no embedded quote.
t("unquotes a plain single-quoted transcript ('This is KF0ZGT...')",
    $byId['de000000']['transcript'] === 'This is KF0ZGT, radio test, radio test.');

// Python-repr unquoting: double-quoted transcript because the text itself
// contains a single quote ("When I'm faj...").
t('unquotes a double-quoted transcript (chosen by Python repr because the text contains an apostrophe)',
    strpos($byId['e3000000']['transcript'], "When I'm faj") !== false);

t('a call whose STT line is empty (\'\') parses to an empty-string transcript, not null',
    $byId['ee000000']['transcript'] === '');

t('the zero-voice-payload block (no "wrote" line) produces no corpus record',
    !isset($byId['ef000000']));

// ── dmr_stt_classify_call() ──────────────────────────────────────────────
echo "\n--- dmr_stt_classify_call() ---\n";
t('the real hallucination-loop call classifies as "repetition"', dmr_stt_classify_call($byId['e3000000']) === 'repetition');
t('an empty transcript classifies as "empty"', dmr_stt_classify_call($byId['ee000000']) === 'empty');
t('a single-token callsign fragment ("BZ.") classifies as "short"', dmr_stt_classify_call($byId['e4000000']) === 'short');
t('a clean multi-word sentence classifies as "normal"', dmr_stt_classify_call($byId['de000000']) === 'normal');
$longWords = [];
for ($i = 0; $i < 50; $i++) $longWords[] = 'word' . $i; // distinct words — must not trip the repetition heuristic
$longCall = ['transcript' => implode(' ', $longWords)];
t('a 50-DISTINCT-word transcript classifies as "long" (not "repetition")', dmr_stt_classify_call($longCall) === 'long');

// ── dmr_stt_stratified_sample() ─────────────────────────────────────────
echo "\n--- dmr_stt_stratified_sample() ---\n";
$sample = dmr_stt_stratified_sample($calls, ['per_category' => 1, 'max_total' => 50]);
$sampleIds = array_column($sample, 'call_id');
t('per_category=1 picks at most one call per heuristic category', count($sample) <= 5);
t('every category present in the fixture is represented',
    count(array_unique(array_map('dmr_stt_classify_call', $calls))) === count(array_unique(array_map('dmr_stt_classify_call', $sample)))
);

$forced = dmr_stt_stratified_sample($calls, ['per_category' => 0, 'max_total' => 50, 'force_call_ids' => ['E3000000']]);
t('force_call_ids is case-insensitive and always includes the forced call even with per_category=0',
    in_array('e3000000', array_column($forced, 'call_id'), true));

$capped = dmr_stt_stratified_sample($calls, ['per_category' => 10, 'max_total' => 2]);
t('max_total is a hard cap on the sample size', count($capped) <= 2);

// ── dmr_stt_manifest_entry_from_call() + merge (never clobber labeling) ─
echo "\n--- manifest entry + merge semantics ---\n";
$entry = dmr_stt_manifest_entry_from_call($byId['de000000'], 'whisper:base.en');
t('a fresh manifest entry starts with ground_truth_transcript = null', $entry['ground_truth_transcript'] === null);
t('a fresh manifest entry records the raw whisper transcript for comparison',
    $entry['whisper_transcript'] === 'This is KF0ZGT, radio test, radio test.');

$existingLabeled = [
    [
        'call_id' => 'de000000',
        'audio_file' => 'minnesota-statewide-20260820-043547-3227191-tg3127-de000000.wav',
        'whisper_transcript' => 'This is KF0ZGT, radio test, radio test.',
        'ground_truth_transcript' => 'This is KF0ZGT, radio test, radio test.',
        'ground_truth_labeled_by' => 'eric',
        'ground_truth_labeled_at' => '2026-08-21T12:00:00Z',
        'notes' => 'clean baseline example',
    ],
];
$freshEntries = array_map(
    fn($c) => dmr_stt_manifest_entry_from_call($c, 'whisper:base.en'),
    array_values(array_filter($calls, fn($c) => in_array($c['call_id'], ['de000000', 'e3000000'], true)))
);
$merged = dmr_stt_merge_manifest($existingLabeled, $freshEntries);

t('merge keeps the same number of entries as the union of ids (2, not 3 — no duplicate de000000)', count($merged) === 2);
$mergedById = [];
foreach ($merged as $m) $mergedById[$m['call_id']] = $m;
t('merge PRESERVES the existing labeled entry verbatim, including ground_truth_transcript',
    $mergedById['de000000']['ground_truth_transcript'] === 'This is KF0ZGT, radio test, radio test.'
    && $mergedById['de000000']['ground_truth_labeled_by'] === 'eric');
t('merge ADDS the genuinely-new call as an unlabeled entry',
    isset($mergedById['e3000000']) && $mergedById['e3000000']['ground_truth_transcript'] === null);

// A re-run with a DIFFERENT (narrower) fresh sample must not drop the
// earlier labeled entry, even though this second sample doesn't include it.
$secondRunEntries = [dmr_stt_manifest_entry_from_call($byId['e4000000'], 'whisper:base.en')];
$mergedAgain = dmr_stt_merge_manifest($merged, $secondRunEntries);
t('a later merge with an unrelated sample still keeps every earlier entry (3 total: de000000, e3000000, e4000000)',
    count($mergedAgain) === 3);

// ── dmr_stt_labeled_entries() ────────────────────────────────────────────
echo "\n--- dmr_stt_labeled_entries() ---\n";
$labeled = dmr_stt_labeled_entries($mergedAgain);
t('exactly the one hand-labeled entry is reported as labeled', count($labeled) === 1 && $labeled[0]['call_id'] === 'de000000');
$blankLabel = dmr_stt_merge_manifest([], [array_merge(dmr_stt_manifest_entry_from_call($byId['ee000000'], 'whisper:base.en'), ['ground_truth_transcript' => '   '])]);
t('a ground_truth_transcript that is only whitespace does NOT count as labeled',
    count(dmr_stt_labeled_entries($blankLabel)) === 0);

// ── End-to-end: the CLI tool's file-handling logic, via a real temp dir ─
echo "\n--- end-to-end: sampling into a real temp corpus directory ---\n";
$tmpBase = sys_get_temp_dir() . '/newui-dmr-stt-corpus-test-' . getmypid() . '-' . mt_rand();
$wavDir = $tmpBase . '/wavs';
$outDir = $tmpBase . '/corpus';
mkdir($wavDir, 0755, true);
// Only create placeholder audio for 3 of the 5 parsed calls, to prove the
// tool correctly skips a sampled call whose audio is missing rather than
// failing hard.
foreach (['de000000', 'e3000000', 'e4000000'] as $id) {
    file_put_contents($wavDir . '/' . $byId[$id]['wav_basename'], 'FAKE-WAV-PLACEHOLDER');
}

$sampleForCopy = dmr_stt_stratified_sample($calls, ['per_category' => 10, 'max_total' => 50]);
$copiedCount = 0; $skippedMissing = 0;
if (!is_dir($outDir . '/audio')) mkdir($outDir . '/audio', 0755, true);
$manifestEntries = [];
foreach ($sampleForCopy as $c) {
    $src = $wavDir . '/' . $c['wav_basename'];
    if (!is_file($src)) { $skippedMissing++; continue; }
    copy($src, $outDir . '/audio/' . $c['wav_basename']);
    $copiedCount++;
    $manifestEntries[] = dmr_stt_manifest_entry_from_call($c, 'whisper:base.en');
}
file_put_contents($outDir . '/manifest.json', json_encode($manifestEntries, JSON_PRETTY_PRINT));

t('exactly the 3 calls with placeholder audio were copied', $copiedCount === 3);
t('the 2 calls with no placeholder audio were skipped, not fatal', $skippedMissing === 2);
t('manifest.json was written and is valid JSON', json_decode((string) file_get_contents($outDir . '/manifest.json'), true) !== null);
t('copied audio files actually exist on disk', is_file($outDir . '/audio/' . $byId['de000000']['wav_basename']));

// Cleanup — this test creates real temp files, so it must not leave them.
function _rrmdir(string $dir): void {
    if (!is_dir($dir)) return;
    foreach (scandir($dir) as $f) {
        if ($f === '.' || $f === '..') continue;
        $p = $dir . '/' . $f;
        is_dir($p) ? _rrmdir($p) : @unlink($p);
    }
    @rmdir($dir);
}
_rrmdir($tmpBase);
t('temp fixture directory cleaned up', !is_dir($tmpBase));

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
