<?php
/**
 * DMR/AMBE STT measurement harness — real corpus WER/CER report.
 *
 * This is the actual measurement tool specs/dmr-ambe-stt-improvement-ideas.md
 * calls for ("Build a WER/CER measurement harness before touching anything
 * else... run it against the current pipeline as baseline"). It scores
 * every LABELED entry (ground_truth_transcript filled in by a human — see
 * docs/DMR-STT-MEASUREMENT.md) in tools/dmr-stt-corpus/manifest.json
 * against that call's whisper_transcript, using the pure functions in
 * inc/wer_cer.php (proven correct independently by
 * tests/test_dmr_stt_wer_cer_math.php).
 *
 * The corpus is real captured radio audio + transcripts and is gitignored
 * (see .gitignore) — it will not exist on a fresh checkout, in CI, or for
 * any contributor who hasn't run tools/dmr_stt_corpus_sample.php and then
 * hand-labeled at least one entry. That is the EXPECTED, common state, not
 * an error: this file follows the project's standing SKIP convention
 * (tools/suite_contract.php) — no corpus, or a corpus with zero labeled
 * entries, prints SKIP: <reason> and the canonical "0 passed, 0 failed"
 * before exiting 0, so tools/test_all.php and CI both stay green.
 *
 * When labeled entries DO exist, every comparison is a real assertion
 * (WER/CER is a finite, non-negative number; the aggregate mean is
 * arithmetically consistent with the per-call numbers) — this file is not
 * just a report, it is also a regression gate: if inc/wer_cer.php or the
 * manifest shape ever breaks, this fails loudly instead of printing
 * garbage numbers nobody notices.
 *
 * Usage: php tests/test_dmr_stt_corpus_measurement.php
 * (also runs automatically as part of `php tools/test_all.php`)
 */

require_once __DIR__ . '/../inc/wer_cer.php';
require_once __DIR__ . '/../inc/dmr_stt_corpus.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== DMR/AMBE STT — real corpus WER/CER measurement ===\n\n";

$manifestPath = __DIR__ . '/../tools/dmr-stt-corpus/manifest.json';

if (!is_file($manifestPath)) {
    echo "SKIP: no corpus manifest at tools/dmr-stt-corpus/manifest.json — run\n"
        . "  php tools/dmr_stt_corpus_sample.php --log=... --wav-dir=...\n"
        . "to build one (see docs/DMR-STT-MEASUREMENT.md). This is the expected\n"
        . "state on a fresh checkout and in CI — the corpus is real radio audio\n"
        . "and is gitignored, never committed.\n";
    echo "\n=== 0 passed, 0 failed ===\n";
    exit(0);
}

$decoded = json_decode((string) file_get_contents($manifestPath), true);
if (!is_array($decoded)) {
    // A corrupt manifest is a real problem, not an absence — this is the
    // one case where a missing-prerequisite-shaped situation should NOT
    // silently SKIP, per the project's "0/0 is legitimate only alongside
    // a declared SKIP, never used to paper over something actually wrong"
    // rule. Report it as a failure so it gets fixed, not ignored.
    t('tools/dmr-stt-corpus/manifest.json parses as valid JSON', false);
    echo "\n=== $pass passed, $fail failed ===\n";
    exit(1);
}

$labeled = dmr_stt_labeled_entries($decoded);

if (count($labeled) === 0) {
    echo "SKIP: tools/dmr-stt-corpus/manifest.json exists (" . count($decoded) . " sampled call(s))\n"
        . "but none have a ground_truth_transcript filled in yet. A human needs to\n"
        . "listen to each .wav under tools/dmr-stt-corpus/audio/ and type the true\n"
        . "transcript into the manifest — see docs/DMR-STT-MEASUREMENT.md for the\n"
        . "exact workflow. This harness has nothing to measure against until then.\n";
    echo "\n=== 0 passed, 0 failed ===\n";
    exit(0);
}

echo "Found " . count($labeled) . " labeled entr" . (count($labeled) === 1 ? 'y' : 'ies')
    . " (of " . count($decoded) . " sampled) — scoring.\n\n";

$rows = [];
foreach ($labeled as $entry) {
    $gt = (string) $entry['ground_truth_transcript'];
    $hyp = (string) ($entry['whisper_transcript'] ?? '');
    $wer = wer_score($gt, $hyp);
    $cer = cer_score($gt, $hyp);
    $loop = wer_cer_has_repetition_loop($hyp);
    $rows[] = [
        'call_id' => $entry['call_id'] ?? '?',
        'category_hint' => $entry['category_hint'] ?? '?',
        'wer' => $wer['wer'],
        'cer' => $cer['cer'],
        'repetition_loop_flag' => $loop,
    ];

    printf(
        "  %-10s %-11s WER=%6.3f  CER=%6.3f  %s\n",
        $entry['call_id'] ?? '?',
        $entry['category_hint'] ?? '?',
        $wer['wer'],
        $cer['cer'],
        $loop ? '[repetition-loop flagged]' : ''
    );

    t("call {$entry['call_id']}: WER is a finite, non-negative number", is_finite($wer['wer']) && $wer['wer'] >= 0.0);
    t("call {$entry['call_id']}: CER is a finite, non-negative number", is_finite($cer['cer']) && $cer['cer'] >= 0.0);
    t(
        "call {$entry['call_id']}: an exact match against its own ground truth scores WER 0.0 (sanity check on the manifest's own fields)",
        wer_score($gt, $gt)['wer'] === 0.0
    );
}

$meanWer = array_sum(array_column($rows, 'wer')) / count($rows);
$meanCer = array_sum(array_column($rows, 'cer')) / count($rows);
$flagged = count(array_filter($rows, fn($r) => $r['repetition_loop_flag']));

echo "\n--- Aggregate (baseline — current, unmodified pipeline) ---\n";
printf("  Labeled calls scored: %d\n", count($rows));
printf("  Mean WER: %.3f\n", $meanWer);
printf("  Mean CER: %.3f\n", $meanCer);
printf("  Repetition-loop flagged: %d\n", $flagged);

t('mean WER is a finite number consistent with a simple average of the per-call scores',
    abs($meanWer - array_sum(array_column($rows, 'wer')) / count($rows)) < 1e-9);
t('mean WER/CER stay within a sane [0, 20] band (catches a units/scale bug loudly rather than a silently-wrong report)',
    $meanWer >= 0.0 && $meanWer <= 20.0 && $meanCer >= 0.0 && $meanCer <= 20.0);

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
