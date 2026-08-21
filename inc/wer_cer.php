<?php
/**
 * DMR/AMBE STT measurement harness — pure Word/Character Error Rate math.
 *
 * Origin: specs/dmr-ambe-stt-improvement-ideas.md's own highest-priority
 * recommendation ("Build a WER/CER measurement harness before touching
 * anything else" — idea #1 of 49, ranked above every accuracy change it
 * lists) — the project has never had any accuracy measurement anywhere
 * (see that doc's grounding section), so nothing below it can be honestly
 * judged without this existing first.
 *
 * DELIBERATELY pure PHP, not a wrapper around the Python `jiwer` package
 * the research doc mentions as an example. Two independent reasons, not
 * just one:
 *   1. This codebase is PHP-primary with zero existing Python-in-CI
 *      dependency (services/*.py are deployed standalone to dvswitch-host,
 *      never imported by the PHP app or exercised by tools/test_all.php).
 *      Adding a Python+pip dependency to the test suite for one feature
 *      would be a new category of CI fragility this project doesn't
 *      otherwise have.
 *   2. jiwer's own WER is exactly this: reference/hypothesis tokenized,
 *      then a Levenshtein edit-distance over the token sequences, divided
 *      by the reference token count. CER is the same over characters
 *      instead of words. There is nothing jiwer computes that isn't
 *      reproducible in ~80 lines of dependency-free PHP, and doing so
 *      means this test can run anywhere php.ini runs — no `pip install`,
 *      no venv, no "works on my machine."
 *
 * Both wer_score() and cer_score() share one core edit-distance-with-
 * backtrace routine (_wer_cer_edit_ops) operating on a generic token
 * array — words for WER, individual (multibyte-safe) characters for CER.
 * This is the same algorithm class NIST sclite / jiwer / every standard
 * ASR-scoring tool uses: a full O(n*m) Levenshtein DP matrix, then a
 * backtrace from the bottom-right corner to classify each edit as a
 * substitution, deletion, or insertion (not just a raw distance number —
 * the breakdown is what makes a WER report actionable, e.g. "this call's
 * error is almost entirely one substituted callsign token" vs. "half the
 * words were deleted because the tail got cut off").
 *
 * Normalization (wer_normalize_text): lowercase + strip punctuation other
 * than a word-internal apostrophe or hyphen, collapse whitespace. This
 * matters far more here than in typical ASR benchmarks, because the
 * single worst documented failure mode in the research doc is callsign
 * punctuation noise ("BZ." / "KB0, BZ." / "KB0BZ." all differing only in
 * trailing/embedded punctuation for the SAME spoken callsign) — comparing
 * raw strings would inflate WER on exactly the axis this harness exists
 * to measure honestly, so normalization is ON by default and callers who
 * want raw-string comparison can pass normalize=false explicitly.
 *
 * Zero-length-reference convention (both wer_score/cer_score): a reference
 * transcript should essentially never be empty in a real labeled corpus
 * (an operator wouldn't label a call "no speech" as ground truth for a
 * call that has speech), but the corpus DOES legitimately contain empty
 * hypotheses (41% of real calls per the research doc) and it is possible
 * to construct a reference that ended up empty (e.g. mislabeled, or a
 * true no-speech call kept for the trust-gate use case). Standard WER is
 * undefined at reference-length zero (division by zero). This harness
 * defines it explicitly rather than throwing: 0.0 if the hypothesis is
 * ALSO empty (both sides agree there was nothing to transcribe), else 1.0
 * (capped, not infinite) if the hypothesis produced anything at all. This
 * keeps every score a finite, sortable number — never NAN/INF — which
 * matters for aggregate averaging over a whole corpus.
 */

if (!function_exists('wer_normalize_text')) {
    /**
     * Lowercase, strip punctuation (keeping a word-internal apostrophe or
     * hyphen), collapse whitespace. Multibyte-safe (mb_strtolower).
     */
    function wer_normalize_text(string $s): string
    {
        $s = mb_strtolower($s, 'UTF-8');
        // Keep letters, numbers, whitespace, apostrophe, hyphen. Everything
        // else (periods, commas, quotes, ellipses, em-dashes, etc.) becomes
        // a space so "KB0BZ." and "KB0BZ" both normalize to "kb0bz", and
        // "KB0, BZ." normalizes to "kb0 bz" (two tokens) rather than
        // "kb0, bz." (which would never equal any clean transcription).
        $s = preg_replace("/[^\p{L}\p{N}\s'\-]/u", ' ', $s);
        $s = preg_replace('/\s+/u', ' ', $s);
        return trim((string) $s);
    }
}

if (!function_exists('wer_tokenize_words')) {
    /** Normalized string -> array of word tokens (empty array for ''). */
    function wer_tokenize_words(string $s, bool $normalize = true): array
    {
        $norm = $normalize ? wer_normalize_text($s) : trim($s);
        if ($norm === '') return [];
        return preg_split('/\s+/u', $norm) ?: [];
    }
}

if (!function_exists('wer_tokenize_chars')) {
    /**
     * Normalized string -> array of individual (multibyte-safe) characters,
     * including internal spaces (so "cat" vs "ca t" is a genuine 1-char
     * insertion, matching how a space-bearing STT error actually reads).
     */
    function wer_tokenize_chars(string $s, bool $normalize = true): array
    {
        $norm = $normalize ? wer_normalize_text($s) : trim($s);
        if ($norm === '') return [];
        // preg_split with PREG_SPLIT_NO_EMPTY over an empty pattern is the
        // standard multibyte-safe char-split idiom (mb_str_split exists
        // since PHP 7.4, which this project already requires — used here
        // directly for clarity).
        return mb_str_split($norm, 1, 'UTF-8') ?: [];
    }
}

if (!function_exists('_wer_cer_edit_ops')) {
    /**
     * Core Levenshtein DP + backtrace, generic over any two token arrays.
     * Returns ['distance'=>int,'substitutions'=>int,'deletions'=>int,
     * 'insertions'=>int,'ref_len'=>int,'hyp_len'=>int].
     *
     * $ref/$hyp are compared by string equality per token (===), so callers
     * are responsible for normalization before tokenizing if they want
     * case/punctuation-insensitive comparison (wer_score/cer_score both do
     * this by default via wer_normalize_text).
     */
    function _wer_cer_edit_ops(array $ref, array $hyp): array
    {
        $n = count($ref);
        $m = count($hyp);

        if ($n === 0 && $m === 0) {
            return ['distance' => 0, 'substitutions' => 0, 'deletions' => 0, 'insertions' => 0, 'ref_len' => 0, 'hyp_len' => 0];
        }

        // dp[i][j] = min edit distance between ref[0..i) and hyp[0..j)
        $dp = [];
        for ($i = 0; $i <= $n; $i++) {
            $dp[$i] = array_fill(0, $m + 1, 0);
            $dp[$i][0] = $i;
        }
        for ($j = 0; $j <= $m; $j++) {
            $dp[0][$j] = $j;
        }
        for ($i = 1; $i <= $n; $i++) {
            for ($j = 1; $j <= $m; $j++) {
                if ($ref[$i - 1] === $hyp[$j - 1]) {
                    $dp[$i][$j] = $dp[$i - 1][$j - 1];
                } else {
                    $sub = $dp[$i - 1][$j - 1] + 1;
                    $del = $dp[$i - 1][$j] + 1;
                    $ins = $dp[$i][$j - 1] + 1;
                    $dp[$i][$j] = min($sub, $del, $ins);
                }
            }
        }

        // Backtrace from the bottom-right corner, classifying each step.
        // Tie-break preference (match > substitution > deletion >
        // insertion) is arbitrary among equally-optimal paths — total
        // distance (and therefore WER/CER) is path-independent, only the
        // S/D/I split could vary on a tie, and this ordering matches the
        // convention most WER-scoring tools (sclite, jiwer) use.
        $i = $n; $j = $m;
        $sub = 0; $del = 0; $ins = 0;
        while ($i > 0 || $j > 0) {
            if ($i > 0 && $j > 0 && $ref[$i - 1] === $hyp[$j - 1] && $dp[$i][$j] === $dp[$i - 1][$j - 1]) {
                $i--; $j--;
                continue;
            }
            if ($i > 0 && $j > 0 && $dp[$i][$j] === $dp[$i - 1][$j - 1] + 1) {
                $sub++; $i--; $j--;
                continue;
            }
            if ($i > 0 && $dp[$i][$j] === $dp[$i - 1][$j] + 1) {
                $del++; $i--;
                continue;
            }
            if ($j > 0 && $dp[$i][$j] === $dp[$i][$j - 1] + 1) {
                $ins++; $j--;
                continue;
            }
            // Unreachable for a correctly-built DP matrix; guards against
            // an infinite loop if that invariant is ever violated by a
            // future edit.
            break;
        }

        return [
            'distance' => $dp[$n][$m],
            'substitutions' => $sub,
            'deletions' => $del,
            'insertions' => $ins,
            'ref_len' => $n,
            'hyp_len' => $m,
        ];
    }
}

if (!function_exists('wer_score')) {
    /**
     * Word Error Rate between a reference (ground truth) and hypothesis
     * (STT output) transcript.
     *
     * Returns ['wer'=>float,'substitutions'=>int,'deletions'=>int,
     * 'insertions'=>int,'ref_word_count'=>int,'hyp_word_count'=>int,
     * 'distance'=>int]. wer can legitimately exceed 1.0 (more edits than
     * reference words, e.g. a short reference with a long garbled
     * hypothesis) — that is correct, standard WER behavior, not a bug.
     */
    function wer_score(string $reference, string $hypothesis, bool $normalize = true): array
    {
        $ref = wer_tokenize_words($reference, $normalize);
        $hyp = wer_tokenize_words($hypothesis, $normalize);
        $ops = _wer_cer_edit_ops($ref, $hyp);

        $refLen = $ops['ref_len'];
        if ($refLen === 0) {
            // Documented zero-reference convention — see file docblock.
            $wer = ($ops['hyp_len'] === 0) ? 0.0 : 1.0;
        } else {
            // PHP's `/` returns an int, not a float, when both operands are
            // ints AND evenly divide (e.g. distance=0 -> 0/$refLen is int(0),
            // not float(0.0)) — cast explicitly so 'wer' always honors its
            // documented float type regardless of whether the score happens
            // to land on a whole number.
            $wer = (float) $ops['distance'] / $refLen;
        }

        return [
            'wer' => $wer,
            'substitutions' => $ops['substitutions'],
            'deletions' => $ops['deletions'],
            'insertions' => $ops['insertions'],
            'ref_word_count' => $refLen,
            'hyp_word_count' => $ops['hyp_len'],
            'distance' => $ops['distance'],
        ];
    }
}

if (!function_exists('cer_score')) {
    /**
     * Character Error Rate — same shape as wer_score() but over individual
     * characters (including spaces) instead of words. Same zero-reference
     * convention as wer_score().
     */
    function cer_score(string $reference, string $hypothesis, bool $normalize = true): array
    {
        $ref = wer_tokenize_chars($reference, $normalize);
        $hyp = wer_tokenize_chars($hypothesis, $normalize);
        $ops = _wer_cer_edit_ops($ref, $hyp);

        $refLen = $ops['ref_len'];
        if ($refLen === 0) {
            $cer = ($ops['hyp_len'] === 0) ? 0.0 : 1.0;
        } else {
            // See the matching cast in wer_score() above — same PHP
            // int-division-returns-int edge case applies here too.
            $cer = (float) $ops['distance'] / $refLen;
        }

        return [
            'cer' => $cer,
            'substitutions' => $ops['substitutions'],
            'deletions' => $ops['deletions'],
            'insertions' => $ops['insertions'],
            'ref_char_count' => $refLen,
            'hyp_char_count' => $ops['hyp_len'],
            'distance' => $ops['distance'],
        ];
    }
}

if (!function_exists('wer_cer_has_repetition_loop')) {
    /**
     * Cheap heuristic flag for the hallucination/repetition-loop failure
     * mode the research doc captured verbatim ("When I'm faj... When I'm
     * faj... ...faj... ...faj..." repeated 7 times). Not a scoring
     * function — a corpus-curation / trust-gate helper: does the SAME
     * normalized word (or short phrase) repeat consecutively at least
     * $minRepeats times in a row anywhere in the text?
     *
     * Deliberately simple (consecutive-token run-length over the
     * normalized word array) rather than a general n-gram repetition
     * detector — good enough to flag the documented failure shape without
     * false-positiving on ordinary repeated words in real speech ("very,
     * very tired" is 2 repeats, below the default threshold of 3).
     */
    function wer_cer_has_repetition_loop(string $text, int $minRepeats = 3): bool
    {
        $words = wer_tokenize_words($text, true);
        $run = 1;
        for ($i = 1, $n = count($words); $i < $n; $i++) {
            if ($words[$i] === $words[$i - 1] && $words[$i] !== '') {
                $run++;
                if ($run >= $minRepeats) return true;
            } else {
                $run = 1;
            }
        }
        return false;
    }
}
