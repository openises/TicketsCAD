<?php
/**
 * DMR/AMBE STT measurement harness — corpus sampler.
 *
 * Builds/updates a small local corpus (audio/ + manifest.json) for the
 * WER/CER measurement harness (tests/test_dmr_stt_corpus_measurement.php)
 * from real captured DMR calls, so a human can hand-label ground-truth
 * transcripts against real audio. See docs/DMR-STT-MEASUREMENT.md for the
 * full human workflow this feeds.
 *
 * This tool is DELIBERATELY offline and host-agnostic: it never SSHes
 * anywhere, never embeds a hostname or credential, and only reads files
 * you have already fetched onto this machine. An operator fetches the two
 * inputs themselves:
 *
 *   ssh dvswitch-host 'sudo journalctl -u ticketscad-echo-bot --no-pager' \
 *       > /path/to/echo-bot.log
 *   scp -r dvswitch-host:/var/cache/ticketscad-dvswitch/*.wav /path/to/wavs/
 *   # (or rsync — whatever the operator normally uses to pull files down)
 *
 * then runs:
 *
 *   php tools/dmr_stt_corpus_sample.php \
 *       --log=/path/to/echo-bot.log \
 *       --wav-dir=/path/to/wavs \
 *       [--out-dir=tools/dmr-stt-corpus] [--per-category=6] [--max-total=50]
 *       [--force=<call_id>,<call_id>,...] [--engine=whisper:base.en]
 *       [--dry-run]
 *
 * SAFE BY DESIGN:
 *   - read-only against --log and --wav-dir (never writes, never deletes
 *     anything under either).
 *   - never overwrites a labeled manifest entry — see
 *     dmr_stt_merge_manifest()'s docblock in inc/dmr_stt_corpus.php. Running
 *     this again after a human has filled in ground_truth_transcript values
 *     only ADDS new unlabeled entries; it never touches existing ones.
 *   - --dry-run reports exactly what would be copied/written without
 *     touching the filesystem at all.
 *   - the corpus directory (--out-dir, default tools/dmr-stt-corpus/) is
 *     gitignored — see .gitignore. Real radio audio and transcripts must
 *     never be committed.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../inc/dmr_stt_corpus.php';

function dmr_stt_sample_usage(): void
{
    fwrite(STDERR, "Usage: php tools/dmr_stt_corpus_sample.php --log=<path> --wav-dir=<path> [options]\n"
        . "  --log=PATH          journalctl output for ticketscad-echo-bot (required)\n"
        . "  --wav-dir=PATH       local directory holding the referenced .wav files (required)\n"
        . "  --out-dir=PATH       corpus output directory (default: tools/dmr-stt-corpus)\n"
        . "  --per-category=N     max sampled calls per heuristic category (default: 6)\n"
        . "  --max-total=N        hard cap on total sampled calls (default: 50)\n"
        . "  --force=ID,ID,...    call_ids or .wav basenames to force-include regardless\n"
        . "                       of quota (a bare call_id can match >1 call if the log\n"
        . "                       spans a bot restart — prefer the full .wav basename\n"
        . "                       when precision matters; see inc/dmr_stt_corpus.php)\n"
        . "  --engine=STRING      whisper_engine label recorded in the manifest (default: whisper:base.en)\n"
        . "  --dry-run            report only, write/copy nothing\n");
}

$opts = getopt('', ['log:', 'wav-dir:', 'out-dir::', 'per-category::', 'max-total::', 'force::', 'engine::', 'dry-run', 'help']);

if (isset($opts['help'])) { dmr_stt_sample_usage(); exit(0); }
if (!isset($opts['log']) || !isset($opts['wav-dir'])) {
    dmr_stt_sample_usage();
    exit(1);
}

$logPath = $opts['log'];
$wavDir = rtrim($opts['wav-dir'], '/\\');
$outDir = rtrim($opts['out-dir'] ?? (__DIR__ . '/dmr-stt-corpus'), '/\\');
$perCategory = isset($opts['per-category']) ? (int) $opts['per-category'] : 6;
$maxTotal = isset($opts['max-total']) ? (int) $opts['max-total'] : 50;
$forceIds = isset($opts['force']) && $opts['force'] !== ''
    ? array_filter(array_map('trim', explode(',', $opts['force'])))
    : [];
$engine = $opts['engine'] ?? 'whisper:base.en';
$dryRun = isset($opts['dry-run']);

if (!is_file($logPath)) {
    fwrite(STDERR, "ERROR: --log path not found: $logPath\n");
    exit(1);
}
if (!is_dir($wavDir)) {
    fwrite(STDERR, "ERROR: --wav-dir not found or not a directory: $wavDir\n");
    exit(1);
}

$logText = (string) file_get_contents($logPath);
$calls = dmr_stt_parse_echo_bot_log($logText);

echo "Parsed " . count($calls) . " finalized call(s) from $logPath\n";
if (count($calls) === 0) {
    echo "Nothing to sample — check that --log actually contains echo_bot.py 'call ... ended' blocks.\n";
    exit(0);
}

$byCategory = [];
foreach ($calls as $c) $byCategory[dmr_stt_classify_call($c)][] = $c;
echo "Category breakdown (heuristic — for sampling only, not authoritative):\n";
foreach ($byCategory as $cat => $bucket) {
    echo "  " . str_pad($cat, 12) . count($bucket) . "\n";
}

$sample = dmr_stt_stratified_sample($calls, [
    'per_category' => $perCategory,
    'max_total' => $maxTotal,
    'force_call_ids' => $forceIds,
]);
echo "\nSelected " . count($sample) . " call(s) for the corpus.\n";

$manifestPath = $outDir . '/manifest.json';
$existing = [];
if (is_file($manifestPath)) {
    $decoded = json_decode((string) file_get_contents($manifestPath), true);
    if (is_array($decoded)) $existing = $decoded;
    echo "Existing manifest found: " . count($existing) . " entr" . (count($existing) === 1 ? 'y' : 'ies')
        . " (" . count(dmr_stt_labeled_entries($existing)) . " already labeled — these are never touched).\n";
}
// Keyed on wav_basename (audio_file in the manifest), not call_id — see
// dmr_stt_stratified_sample()'s docblock for why call_id alone collides
// across bot-process restarts.
$existingAudioFiles = [];
foreach ($existing as $e) { if (isset($e['audio_file'])) $existingAudioFiles[$e['audio_file']] = true; }

$found = 0; $missing = [];
$newEntries = [];
$toCopy = []; // wav_basename => source path
foreach ($sample as $call) {
    $src = $wavDir . '/' . $call['wav_basename'];
    if (!is_file($src)) {
        $missing[] = $call['wav_basename'];
        continue;
    }
    $found++;
    if (!isset($existingAudioFiles[$call['wav_basename']])) {
        $newEntries[] = dmr_stt_manifest_entry_from_call($call, $engine);
        $toCopy[$call['wav_basename']] = $src;
    }
}

echo "\n$found of " . count($sample) . " sampled call(s) have their .wav present under $wavDir.\n";
if ($missing) {
    echo count($missing) . " sampled call(s) had NO matching .wav file (skipped, not an error):\n";
    foreach (array_slice($missing, 0, 10) as $m) echo "  - $m\n";
    if (count($missing) > 10) echo "  ... and " . (count($missing) - 10) . " more\n";
}

echo "\n" . count($newEntries) . " new (previously un-sampled) call(s) to add to the manifest.\n";

if ($dryRun) {
    echo "\n--dry-run: nothing written. Would create/update:\n";
    echo "  $manifestPath\n";
    foreach ($toCopy as $basename => $src) echo "  $outDir/audio/$basename\n";
    exit(0);
}

if (count($newEntries) === 0) {
    echo "Nothing new to add — manifest already covers every sampled call whose audio is present.\n";
    exit(0);
}

if (!is_dir($outDir . '/audio')) {
    if (!mkdir($outDir . '/audio', 0755, true) && !is_dir($outDir . '/audio')) {
        fwrite(STDERR, "ERROR: could not create $outDir/audio\n");
        exit(1);
    }
}

$copied = 0;
foreach ($toCopy as $basename => $src) {
    $dest = $outDir . '/audio/' . $basename;
    if (is_file($dest)) continue; // already there — never re-copy over it
    if (!copy($src, $dest)) {
        fwrite(STDERR, "WARNING: failed to copy $src -> $dest\n");
        continue;
    }
    $copied++;
}

$merged = dmr_stt_merge_manifest($existing, $newEntries);
$json = json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if ($json === false) {
    fwrite(STDERR, "ERROR: failed to encode manifest JSON: " . json_last_error_msg() . "\n");
    exit(1);
}
file_put_contents($manifestPath, $json . "\n");

echo "\nCopied $copied audio file(s) to $outDir/audio/\n";
echo "Wrote $manifestPath (" . count($merged) . " total entries, "
    . count(dmr_stt_labeled_entries($merged)) . " labeled).\n";
echo "\nNext step: a human listens to each unlabeled call's .wav and fills in\n"
    . "its ground_truth_transcript in the manifest. See docs/DMR-STT-MEASUREMENT.md.\n";
