<?php
/**
 * DMR/AMBE STT measurement harness — corpus parsing, classification, and
 * manifest management. Pure, DB-free, filesystem-free-except-where-noted
 * functions so tests/test_dmr_stt_corpus_sample.php can drive every one of
 * them against synthetic fixtures without a live host, real audio, or a
 * database. The one CLI wrapper that touches the filesystem for real
 * (tools/dmr_stt_corpus_sample.php) is a thin shell around these.
 *
 * Log format parsed (dmr_stt_parse_echo_bot_log): the INFO lines
 * services/dvswitch/echo_bot.py's finish_call() writes to stdout/journald,
 * one call per block, always in this order (see that file — the calls are
 * processed synchronously in the main loop, never interleaved):
 *
 *   INFO call <hex-stream-id> ended — N packets, decoding
 *   INFO voice payloads: N  src=<radio-id>  tg=<talkgroup>
 *   INFO wrote <path>.wav (X.XX sec)
 *   INFO STT (Y.YYs): '<transcript>'      (Python repr — single- or
 *                                          double-quoted depending on
 *                                          whether the text itself
 *                                          contains a single quote)
 *   INFO empty STT — no reply             (present only when transcript
 *                                          is empty; absence is NOT an
 *                                          error, most calls omit it)
 *
 * A block missing "wrote" or "STT" (e.g. a call with zero voice payloads,
 * which finish_call() returns early on before ever writing a WAV) produces
 * no corpus record — there is nothing to sample.
 *
 * Fetching the log is deliberately OUT OF SCOPE for this file and the CLI
 * tool that wraps it: no SSH, no hardcoded hostname, no credentials. An
 * operator fetches it themselves (see docs/DMR-STT-MEASUREMENT.md) with:
 *   ssh dvswitch-host 'sudo journalctl -u ticketscad-echo-bot --no-pager' \
 *       > echo-bot.log
 * and separately copies the recordings directory down, then points this
 * tool at both local paths. That keeps the tool itself safe to run
 * anywhere (including CI, where the log/wav-dir simply won't exist) and
 * keeps no remote-access secrets anywhere in this repository.
 */

if (!function_exists('dmr_stt_parse_echo_bot_log')) {
    /**
     * Parse raw (or already systemd-journal-prefixed) echo_bot.py log text
     * into an array of call records. Order of appearance in $logText is
     * preserved. Tolerant of arbitrary surrounding log noise — only the
     * five INFO patterns above are recognized, everything else is ignored,
     * so this can be fed either a full `journalctl -u ticketscad-echo-bot`
     * dump or a pre-filtered subset (grep for the same patterns).
     *
     * Each record: ['call_id'=>string hex, 'wav_path'=>string (as logged —
     * may be a remote absolute path), 'wav_basename'=>string,
     * 'audio_duration_sec'=>?float, 'src_radio_id'=>?string,
     * 'talkgroup'=>?string, 'stt_duration_sec'=>?float,
     * 'transcript'=>string, 'started_at_from_filename'=>?string (ISO 8601,
     * derived from the wav filename's embedded YYYYMMDD-HHMMSS if present)].
     */
    function dmr_stt_parse_echo_bot_log(string $logText): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $logText) ?: [];

        $calls = [];
        /** @var array|null $cur */
        $cur = null;

        foreach ($lines as $line) {
            if (preg_match('/INFO call ([0-9a-fA-F]{2,})\s+ended\b/', $line, $m)) {
                // Starting a new block flushes any prior one that reached
                // at least "wrote" — a block that never got a wav (early
                // return on "no voice payloads") is discarded, matching
                // finish_call()'s own behavior.
                if ($cur !== null && $cur['wav_path'] !== null) {
                    $calls[] = dmr_stt_finalize_call_record($cur);
                }
                $cur = [
                    'call_id' => strtolower($m[1]),
                    'wav_path' => null,
                    'audio_duration_sec' => null,
                    'src_radio_id' => null,
                    'talkgroup' => null,
                    'stt_duration_sec' => null,
                    'transcript' => null,
                ];
                continue;
            }

            if ($cur === null) continue; // noise before the first call block

            if (preg_match('/INFO voice payloads:\s*\d+\s+src=(\d+)\s+tg=(\d+)/', $line, $m)) {
                $cur['src_radio_id'] = $m[1];
                $cur['talkgroup'] = $m[2];
                continue;
            }

            if (preg_match('/INFO wrote (\S+\.wav)\s+\(([\d.]+)\s*sec\)/', $line, $m)) {
                $cur['wav_path'] = $m[1];
                $cur['audio_duration_sec'] = (float) $m[2];
                continue;
            }

            if (preg_match('/INFO STT \(([\d.]+)s\):\s*(.+)$/', $line, $m)) {
                $cur['stt_duration_sec'] = (float) $m[1];
                $cur['transcript'] = dmr_stt_unquote_python_repr(trim($m[2]));
                continue;
            }
            // "empty STT — no reply" carries no extra data beyond what the
            // preceding STT line already recorded (transcript === '').
        }

        if ($cur !== null && $cur['wav_path'] !== null) {
            $calls[] = dmr_stt_finalize_call_record($cur);
        }

        return $calls;
    }
}

if (!function_exists('dmr_stt_finalize_call_record')) {
    /** Fill in derived fields (basename, started_at) for a parsed block. */
    function dmr_stt_finalize_call_record(array $cur): array
    {
        $basename = basename($cur['wav_path']);
        $cur['wav_basename'] = $basename;
        $cur['started_at_from_filename'] = dmr_stt_started_at_from_filename($basename);
        if ($cur['transcript'] === null) $cur['transcript'] = '';
        return $cur;
    }
}

if (!function_exists('dmr_stt_started_at_from_filename')) {
    /**
     * echo_bot.py names WAVs "<label>-YYYYMMDD-HHMMSS-<src>-tg<tg>-<sid>.wav"
     * (see finish_call()'s wav_filename construction) — extract the
     * timestamp portion as an ISO 8601 string, or null if the filename
     * doesn't match (e.g. the "/tmp/rx-<sid>.wav" write-failure fallback
     * path, which carries no embedded timestamp).
     */
    function dmr_stt_started_at_from_filename(string $basename): ?string
    {
        if (!preg_match('/-(\d{8})-(\d{6})-/', $basename, $m)) return null;
        $d = $m[1]; $t = $m[2];
        $iso = sprintf(
            '%s-%s-%sT%s:%s:%s',
            substr($d, 0, 4), substr($d, 4, 2), substr($d, 6, 2),
            substr($t, 0, 2), substr($t, 2, 2), substr($t, 4, 2)
        );
        return $iso;
    }
}

if (!function_exists('dmr_stt_unquote_python_repr')) {
    /**
     * echo_bot.py logs the transcript via Python's %r / repr() — a
     * single-quoted string, or double-quoted if the text itself contains
     * an unescaped single quote (Python's own repr() rule). Strip the
     * outer quote and unescape the handful of backslash sequences Python's
     * repr can emit for ordinary printable English text (\\, \', \").
     * Not a full Python-repr parser (no \xNN / \uNNNN handling) — real
     * radio transcripts are plain English and don't need one; if a
     * genuinely exotic transcript ever breaks this, it fails visibly as a
     * leftover backslash in the corpus, not silently as wrong data.
     */
    function dmr_stt_unquote_python_repr(string $s): string
    {
        if ($s === '') return '';
        $quote = $s[0];
        if (($quote === "'" || $quote === '"') && strlen($s) >= 2 && substr($s, -1) === $quote) {
            $inner = substr($s, 1, -1);
            $inner = str_replace(['\\\\', "\\'", '\\"'], ['\\', "'", '"'], $inner);
            return $inner;
        }
        return $s; // already unquoted, or didn't match the expected shape
    }
}

if (!function_exists('dmr_stt_classify_call')) {
    /**
     * Heuristic category for stratified sampling. Purely a CURATION aid —
     * never authoritative, never used for scoring. One call record ->
     * exactly one category string:
     *
     *   empty         transcript is '' after trimming
     *   repetition    wer_cer_has_repetition_loop() fires (hallucination
     *                 loop shape — see that function's docblock)
     *   short         1-2 normalized words, non-empty (the callsign-chaos
     *                 failure mode clusters here: "BZ.", "KV-0BZ", ...)
     *   long          more than 40 normalized words (favors sampling at
     *                 least a few long, error-accumulating transmissions)
     *   normal        everything else
     */
    function dmr_stt_classify_call(array $call): string
    {
        $text = (string) ($call['transcript'] ?? '');
        if (trim($text) === '') return 'empty';
        require_once __DIR__ . '/wer_cer.php';
        if (wer_cer_has_repetition_loop($text)) return 'repetition';
        $wordCount = count(wer_tokenize_words($text, true));
        if ($wordCount <= 2) return 'short';
        if ($wordCount > 40) return 'long';
        return 'normal';
    }
}

if (!function_exists('dmr_stt_stratified_sample')) {
    /**
     * Select a stratified sample from parsed call records.
     *
     * Keyed internally on wav_basename, NOT call_id. echo_bot.py's stream
     * id (call_id) is a 4-byte value scoped to ONE running process — it
     * restarts from 00000000 every time the bot service restarts (see
     * services/dvswitch/echo_bot.py's _calls dict, which is in-memory
     * process state). A log spanning more than one process lifetime (this
     * bot has been running since mid-June 2026 with ordinary restarts)
     * WILL contain the same call_id string many times over, referring to
     * completely unrelated calls. wav_basename embeds the label,
     * YYYYMMDD-HHMMSS, src, tg, AND call_id together and is therefore
     * always unique — that is the only safe dedup/selection key here.
     * (Discovered while building this tool: an unfiltered 2-month log
     * produced a 282-call "stratified sample of 45" until this fix, purely
     * from call_id collisions across bot restarts miscounting distinct
     * calls as duplicates/already-selected.)
     *
     * $opts:
     *   'per_category' => int (default 6)  — max picks per heuristic
     *                     category (see dmr_stt_classify_call), spread
     *                     evenly across the input's time range rather than
     *                     always the first N found.
     *   'force_call_ids' => string[]       — call_ids OR wav basenames
     *                     that MUST be included regardless of category
     *                     quota (for a human operator who has already
     *                     identified specific interesting calls, e.g.
     *                     from reading the log directly). A bare call_id
     *                     (e.g. "e3000000") can match MULTIPLE calls if
     *                     the log spans more than one bot process
     *                     lifetime — all matches are included (safer to
     *                     over-include than to silently drop the one the
     *                     operator meant); pass the full wav_basename
     *                     instead when precision matters. Included first;
     *                     category quotas apply to the remainder.
     *   'max_total' => int (default 50)    — hard cap on the returned
     *                     sample size.
     *
     * Returns the selected call records (same shape as
     * dmr_stt_parse_echo_bot_log's output), in original log order.
     */
    function dmr_stt_stratified_sample(array $calls, array $opts = []): array
    {
        $perCategory = $opts['per_category'] ?? 6;
        $forceIds = array_map('strtolower', $opts['force_call_ids'] ?? []);
        $maxTotal = $opts['max_total'] ?? 50;

        $selected = []; // wav_basename => true

        if ($forceIds) {
            foreach ($calls as $c) {
                $key = strtolower($c['wav_basename']);
                $cid = strtolower($c['call_id']);
                foreach ($forceIds as $fid) {
                    if ($cid === $fid || $key === $fid || strpos($key, $fid) !== false) {
                        $selected[$c['wav_basename']] = true;
                        break;
                    }
                }
            }
        }

        // Bucket the remaining calls by category, preserving log order
        // (which is chronological) within each bucket.
        $buckets = [];
        foreach ($calls as $c) {
            if (isset($selected[$c['wav_basename']])) continue;
            $buckets[dmr_stt_classify_call($c)][] = $c;
        }

        foreach ($buckets as $category => $bucket) {
            if (count($selected) >= $maxTotal) break;
            // Evenly spread picks across the bucket rather than always
            // taking the earliest N — a stride sample.
            $n = count($bucket);
            $take = min($perCategory, $n, max(0, $maxTotal - count($selected)));
            if ($take <= 0) continue;
            $stride = $n / $take;
            $picked = [];
            for ($k = 0; $k < $take; $k++) {
                $idx = (int) floor($k * $stride);
                if ($idx >= $n) $idx = $n - 1;
                $picked[$idx] = true;
            }
            foreach (array_keys($picked) as $idx) {
                $selected[$bucket[$idx]['wav_basename']] = true;
            }
        }

        $out = [];
        foreach ($calls as $c) {
            if (isset($selected[$c['wav_basename']])) $out[] = $c;
        }
        return $out;
    }
}

if (!function_exists('dmr_stt_manifest_entry_from_call')) {
    /** Build a fresh manifest entry (unlabeled) from a parsed call record. */
    function dmr_stt_manifest_entry_from_call(array $call, string $whisperEngine): array
    {
        return [
            'call_id' => $call['call_id'],
            'audio_file' => $call['wav_basename'],
            'started_at' => $call['started_at_from_filename'],
            'src_radio_id' => $call['src_radio_id'],
            'talkgroup' => $call['talkgroup'],
            'audio_duration_sec' => $call['audio_duration_sec'],
            'stt_duration_sec' => $call['stt_duration_sec'],
            'whisper_transcript' => $call['transcript'],
            'whisper_engine' => $whisperEngine,
            'category_hint' => dmr_stt_classify_call($call),
            'ground_truth_transcript' => null,
            'ground_truth_labeled_by' => null,
            'ground_truth_labeled_at' => null,
            'notes' => '',
        ];
    }
}

if (!function_exists('dmr_stt_merge_manifest')) {
    /**
     * Merge freshly-sampled entries into an existing manifest WITHOUT ever
     * discarding a human's labeling work. Keyed on audio_file (the wav
     * basename), NOT call_id — see dmr_stt_stratified_sample()'s docblock
     * for why call_id alone is not a safe unique key across a log that
     * spans more than one echo_bot.py process lifetime (its stream id
     * resets to 00000000 on every bot restart). audio_file embeds the
     * label + timestamp + src + tg + call_id together and is always
     * unique.
     *   - an audio_file present in $existing keeps every field from
     *     $existing verbatim (including a filled-in
     *     ground_truth_transcript) — the new entry for that same
     *     audio_file is dropped entirely, even if the Whisper transcript
     *     "changed" (it can't, for a WAV that already exists — the audio
     *     never changes; a re-run just re-derives the same facts).
     *   - an audio_file only in $newEntries is appended as-is (unlabeled).
     *   - $existing entries whose audio_file isn't in $newEntries are kept
     *     (a re-run with a narrower filter must not silently drop earlier
     *     labeling work).
     * Returns the merged entry list, existing entries first (stable
     * ordering so a diff between manifest.json versions stays readable).
     */
    function dmr_stt_merge_manifest(array $existing, array $newEntries): array
    {
        $seen = [];
        $merged = [];
        foreach ($existing as $e) {
            $id = $e['audio_file'] ?? null;
            if ($id === null) continue;
            $merged[] = $e;
            $seen[$id] = true;
        }
        foreach ($newEntries as $e) {
            $id = $e['audio_file'] ?? null;
            if ($id === null || isset($seen[$id])) continue;
            $merged[] = $e;
            $seen[$id] = true;
        }
        return $merged;
    }
}

if (!function_exists('dmr_stt_labeled_entries')) {
    /** Entries with a non-empty, human-filled ground_truth_transcript. */
    function dmr_stt_labeled_entries(array $manifestEntries): array
    {
        return array_values(array_filter($manifestEntries, function ($e) {
            $gt = $e['ground_truth_transcript'] ?? null;
            return is_string($gt) && trim($gt) !== '';
        }));
    }
}
