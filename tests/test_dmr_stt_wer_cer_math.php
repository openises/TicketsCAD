<?php
/**
 * DMR/AMBE STT measurement harness — WER/CER math self-test.
 *
 * Proves the scoring arithmetic in inc/wer_cer.php is correct using
 * synthetic reference/hypothesis pairs with HAND-COMPUTED expected
 * WER/CER — this needs no real audio, no corpus, no database, and runs
 * identically everywhere (including a virgin CI checkout), so it always
 * contributes real pass/fail assertions regardless of whether the real
 * corpus (tests/test_dmr_stt_corpus_measurement.php) has any labeled
 * ground truth yet. Per specs/dmr-ambe-stt-improvement-ideas.md's own
 * framing: nothing about DMR/AMBE STT accuracy can be honestly judged
 * without a measurement harness, and a measurement harness whose own math
 * hasn't been proven correct isn't one — it's just a plausible-looking
 * number generator.
 *
 * Usage: php tests/test_dmr_stt_wer_cer_math.php
 */

require_once __DIR__ . '/../inc/wer_cer.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}
function approx($a, $b, $eps = 1e-9) { return abs($a - $b) < $eps; }

echo "=== DMR/AMBE STT — WER/CER math (synthetic, hand-computed) ===\n\n";

// ── wer_normalize_text() ────────────────────────────────────────────────
echo "--- wer_normalize_text() ---\n";
t('lowercases', wer_normalize_text('HELLO WORLD') === 'hello world');
t('strips trailing period', wer_normalize_text('BZ.') === 'bz');
t('strips comma + period, collapses whitespace', wer_normalize_text('KB0, BZ.') === 'kb0 bz');
t('keeps a word-internal hyphen', wer_normalize_text('KV-0BZ') === 'kv-0bz');
t('keeps a word-internal apostrophe', wer_normalize_text("I'm faj") === "i'm faj");
t('collapses multiple spaces', wer_normalize_text('a    b') === 'a b');
t('empty string normalizes to empty string', wer_normalize_text('') === '');

// ── wer_score(): exact / simple cases ───────────────────────────────────
echo "\n--- wer_score(): exact and simple edit cases ---\n";
$r = wer_score('the quick brown fox', 'the quick brown fox');
t('identical transcripts -> WER 0.0', approx($r['wer'], 0.0));
t('identical transcripts -> 0 substitutions/deletions/insertions', $r['substitutions'] === 0 && $r['deletions'] === 0 && $r['insertions'] === 0);
t(
    "'wer' is always a PHP float, never an int — PHP's / operator returns " .
    'int(0) (not float(0.0)) for an exact 0/N division, which strict === ' .
    '0.0 comparisons elsewhere (and any strict type check a caller might ' .
    'do) would silently fail against',
    is_float($r['wer'])
);
$rc = cer_score('same text here', 'same text here');
t("'cer' is likewise always a PHP float on an exact (zero-distance) match", is_float($rc['cer']));

$r = wer_score('the quick brown fox', 'the quick brown');
t('one trailing word dropped -> 1 deletion / 4 ref words = 0.25', approx($r['wer'], 0.25));
t('...classified as exactly 1 deletion, 0 sub, 0 ins', $r['deletions'] === 1 && $r['substitutions'] === 0 && $r['insertions'] === 0);

$r = wer_score('the quick brown fox', 'the quick brown fox jumped');
t('one extra trailing word -> 1 insertion / 4 ref words = 0.25', approx($r['wer'], 0.25));
t('...classified as exactly 1 insertion', $r['insertions'] === 1 && $r['substitutions'] === 0 && $r['deletions'] === 0);

$r = wer_score('the quick brown fox', 'the fast brown fox');
t('one word swapped -> 1 substitution / 4 ref words = 0.25', approx($r['wer'], 0.25));
t('...classified as exactly 1 substitution', $r['substitutions'] === 1 && $r['deletions'] === 0 && $r['insertions'] === 0);

// ── wer_score(): the exact real-world failure shape this harness exists
//    to measure — a garbled/missing callsign inside an otherwise-correct
//    sentence (specs/dmr-ambe-stt-improvement-ideas.md's own headline
//    example: 5 different renderings of one caller's true callsign).
echo "\n--- wer_score(): callsign-substitution shape ---\n";
$r = wer_score('this is KF0ZGT radio test radio test', 'this is BZ radio test radio test');
t('one substituted callsign token among 7 ref words -> WER 1/7', approx($r['wer'], 1 / 7));
t('normalization makes case/punctuation irrelevant to the callsign score',
    approx(wer_score('This is KF0ZGT.', 'this is kf0zgt')['wer'], 0.0));

// ── wer_score(): zero-reference convention ──────────────────────────────
echo "\n--- wer_score(): zero-reference-length convention ---\n";
$r = wer_score('', '');
t('empty reference + empty hypothesis -> WER 0.0 (both agree: nothing said)', approx($r['wer'], 0.0));
$r = wer_score('', 'hello there');
t('empty reference + non-empty hypothesis -> WER 1.0 (capped, not INF/NAN)', approx($r['wer'], 1.0));
t('WER is a finite float even at the zero-reference edge', is_finite($r['wer']));

// ── wer_score(): WER CAN exceed 1.0 — that is correct, not a bug ────────
echo "\n--- wer_score(): WER can legitimately exceed 1.0 ---\n";
$r = wer_score('hi', 'this is a completely different sentence with many extra words');
t('a short reference against a long unrelated hypothesis can score WER > 1.0', $r['wer'] > 1.0);

// ── wer_score(): total silence vs. real hallucinated output ─────────────
echo "\n--- wer_score(): empty hypothesis against real reference ---\n";
$r = wer_score('this is KF0ZGT radio test radio test', '');
t('empty hypothesis -> WER 1.0 (every ref word deleted)', approx($r['wer'], 1.0));
t('...classified as all deletions', $r['deletions'] === 7 && $r['substitutions'] === 0 && $r['insertions'] === 0);

// ── cer_score(): character-level, hand-computed ─────────────────────────
echo "\n--- cer_score(): exact and simple edit cases ---\n";
$r = cer_score('cat', 'cat');
t('identical -> CER 0.0', approx($r['cer'], 0.0));

$r = cer_score('cat', 'cats');
t("'cat' vs 'cats' -> 1 insertion / 3 ref chars = 0.333...", approx($r['cer'], 1 / 3));
t('...classified as exactly 1 insertion', $r['insertions'] === 1 && $r['substitutions'] === 0 && $r['deletions'] === 0);

$r = cer_score('cat', 'bat');
t("'cat' vs 'bat' -> 1 substitution / 3 ref chars = 0.333...", approx($r['cer'], 1 / 3));
t('...classified as exactly 1 substitution', $r['substitutions'] === 1);

$r = cer_score('kb0bz', 'bz');
t("'kb0bz' vs 'bz' (the real KB0BZ-vs-'BZ.' callsign shape) -> 3 deletions / 5 ref chars = 0.6", approx($r['cer'], 0.6));

// ── cer_score(): zero-reference convention (mirrors wer_score) ──────────
echo "\n--- cer_score(): zero-reference-length convention ---\n";
$r = cer_score('', '');
t('empty reference + empty hypothesis -> CER 0.0', approx($r['cer'], 0.0));
$r = cer_score('', 'x');
t('empty reference + non-empty hypothesis -> CER 1.0', approx($r['cer'], 1.0));

// ── cer_score(): multibyte safety ────────────────────────────────────────
echo "\n--- cer_score(): multibyte-safe character counting ---\n";
$r = cer_score('café', 'cafe');
t("'café' vs 'cafe' is a 1-character edit, not a byte-count mismatch (é is 2 bytes in UTF-8)",
    $r['ref_char_count'] === 4 && approx($r['cer'], 0.25));

// ── wer_score()/cer_score(): normalize=false (raw comparison) ───────────
echo "\n--- normalize=false: raw string comparison, no cleanup ---\n";
$r = wer_score('BZ.', 'bz', false);
t('raw mode treats punctuation/case as real differences (WER > 0 where normalized mode gave 0)',
    $r['wer'] > 0.0);

// ── wer_cer_has_repetition_loop(): the captured hallucination shape ─────
echo "\n--- wer_cer_has_repetition_loop() ---\n";
t(
    'the exact captured hallucination-loop transcript is flagged',
    wer_cer_has_repetition_loop(
        "How often could I do that feel? Do I have a sound? When I'm faj... " .
        "When I'm faj... ...faj... ...faj... ...faj... ...faj... ...faj..."
    )
);
t('an ordinary sentence with a natural doubled word ("very, very tired") is NOT flagged at the default threshold',
    !wer_cer_has_repetition_loop('I am very, very tired today.'));
t('a clean, non-repeating transcript is not flagged',
    !wer_cer_has_repetition_loop('This is KF0ZGT, radio test, radio test.'));
t('an empty transcript is not flagged',
    !wer_cer_has_repetition_loop(''));
t('a 2-repeat run does not meet the default 3-repeat threshold',
    !wer_cer_has_repetition_loop('go go home'));
t('a 3-repeat run meets the default threshold',
    wer_cer_has_repetition_loop('go go go home'));

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
