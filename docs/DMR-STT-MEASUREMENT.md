# DMR/AMBE STT measurement harness

Origin: `specs/dmr-ambe-stt-improvement-ideas.md` — a 2026-08-21 research pass
into why the DMR echo bot's speech-to-text is unreliable (41% empty
transcripts, callsigns rendered 5 different ways for one caller, a captured
hallucination/repetition loop). That document's own highest-priority
recommendation, ranked above every accuracy-improving idea it lists, is:
**build a WER/CER measurement harness before touching anything else.** This
is that harness. It does not change any STT/pipeline behavior — it only
measures. See that document for the full prioritized list of ideas this
harness exists to evaluate.

## What this measures

**WER** (Word Error Rate) and **CER** (Character Error Rate) — the standard
ASR-accuracy metrics — between a human-verified **ground-truth transcript**
(what was actually said) and the pipeline's **Whisper transcript** (what
`services/dvswitch/echo_bot.py` actually produced) for a stratified sample of
real captured DMR calls.

```
WER = (substitutions + deletions + insertions) / reference_word_count
CER = same formula, over characters instead of words
```

Both are computed by a small, dependency-free PHP library
(`inc/wer_cer.php`) — **not** the Python `jiwer` package the research doc
mentions as an example. This project is PHP-primary with no existing
Python-in-CI dependency; jiwer's own WER is exactly a Levenshtein edit
distance over tokenized text divided by the reference length, which is
fully reproducible in plain PHP with no `pip install`, no venv, and no new
CI fragility. See that file's docblock for the full reasoning and the
zero-reference-length / WER-can-exceed-1.0 conventions it documents.

## The three pieces

| File | What it does |
|---|---|
| `inc/wer_cer.php` | Pure WER/CER math — normalization, tokenization, Levenshtein DP + backtrace, the zero-reference convention. No DB, no filesystem, no network. |
| `inc/dmr_stt_corpus.php` | Parses `echo_bot.py`'s journalctl log into call records, classifies them into heuristic sampling categories, does stratified sampling, and merges sampled entries into a manifest **without ever discarding a human's labeling work**. |
| `tools/dmr_stt_corpus_sample.php` | The CLI tool that ties the above together: reads a pre-fetched log + a pre-fetched WAV directory, samples, copies audio, writes `manifest.json`. Never touches a remote host itself — see "Fetching the source data" below. |

Tests:

| File | What it proves | Needs real audio? |
|---|---|---|
| `tests/test_dmr_stt_wer_cer_math.php` | The scoring math is correct, against hand-computed synthetic reference/hypothesis pairs (including the real callsign-substitution and hallucination-loop shapes from the research doc). | No — always runs, always green. |
| `tests/test_dmr_stt_corpus_sample.php` | The parser/classifier/sampler/manifest-merge logic is correct, against a synthetic log fixture (built from real log lines captured off the live host) and placeholder zero-byte "audio" files. | No — always runs, always green. |
| `tests/test_dmr_stt_corpus_measurement.php` | Scores the REAL corpus, if one exists with labeled entries. | Yes — SKIPs cleanly (`0 passed, 0 failed`, exit 0) when the corpus doesn't exist or has no labeled entries yet. This is the expected state on every fresh checkout and in CI. |

All three run automatically as part of `php tools/test_all.php` (which
discovers every `test_*.php` under `tools/` and `tests/` — no wiring
needed).

## Fetching the source data

`tools/dmr_stt_corpus_sample.php` deliberately never SSHes anywhere and
never embeds a hostname or credential — it only reads files already on the
local machine. Fetch them yourself first:

```bash
# 1. The echo-bot's log (call metadata + Whisper transcripts):
ssh dvswitch-host 'sudo journalctl -u ticketscad-echo-bot --no-pager' \
    > /tmp/echo-bot.log

# 2. The recorded call audio. As of 2026-08-21 this bot has NO retention
#    sweep of its own (see "Why the WAV files were still there" below) —
#    everything since the service's last install is still on disk. Pull
#    just what you need rather than the whole directory if it's large:
ssh dvswitch-host 'ls /var/cache/ticketscad-dvswitch/*.wav' # see what's there
scp dvswitch-host:/var/cache/ticketscad-dvswitch/*.wav /tmp/dmr-wavs/
```

Then sample and build the corpus:

```bash
php tools/dmr_stt_corpus_sample.php \
    --log=/tmp/echo-bot.log \
    --wav-dir=/tmp/dmr-wavs \
    --per-category=6 --max-total=40
```

This writes `tools/dmr-stt-corpus/manifest.json` (gitignored — see
`.gitignore`) and copies the sampled `.wav` files into
`tools/dmr-stt-corpus/audio/`. Every `ground_truth_transcript` field starts
`null` — see the labeling workflow below.

Re-running the tool later (e.g. to sample more calls, or after fetching a
fresh log) is safe: it never re-copies a file that's already in the corpus
and never touches an existing manifest entry, labeled or not — see
`dmr_stt_merge_manifest()`'s docblock in `inc/dmr_stt_corpus.php`.

### Why the WAV files were still there

`services/dvswitch/bridge.py` has a retention sweep
(`DMR_RECORDING_RETENTION_HOURS`, default 168h/7 days) — but per the
research doc's own grounding section, `bridge.py` is dead code, superseded
by `hbp_client.py` + `echo_bot.py`. **`echo_bot.py` has no retention logic
at all.** On `dvswitch-host` as of 2026-08-21 this meant every call recorded
since the bot's last fresh install (mid-June 2026) was still on disk —
1,823 `.wav` files, ~314 MB total, going back over two months, not just the
"last 7 days" the research doc's own log analysis was limited to (journald's
own log retention, not WAV retention, was the actual limiting factor
there). This is worth knowing for two reasons: (1) sampling can draw from a
much larger and more time-diverse population than the original 187-call
analysis implied, and (2) an unbounded recordings directory on a
2-vCPU/1.9GB host is itself worth someone's attention eventually — flagged
here, not fixed, since this harness's scope is measurement only.

## The human labeling workflow

This is the one step nothing in this harness can do for you — an AI agent
cannot listen to audio the way a human operator can, and this project's own
standing rule for STT accuracy work is that ground truth comes from a
person who actually heard the call.

1. Open `tools/dmr-stt-corpus/manifest.json` and `tools/dmr-stt-corpus/audio/`
   side by side.
2. For each entry, play its `audio_file` (any WAV player — `aplay`,
   Windows Media Player, VLC; the files are plain 8kHz/16-bit/mono PCM
   WAV).
3. Listen and type EXACTLY what was said into `ground_truth_transcript` —
   including the correct callsign (spelled as actually spoken, e.g. "Kilo
   Foxtrot Zero Zulu Golf Tango" if that's what the caller said, or the
   plain callsign "KF0ZGT" if that's clearer for scoring purposes — pick
   one convention and use it consistently across the corpus, since WER
   treats "Kilo Foxtrot Zero..." and "KF0ZGT" as completely different text
   even though they mean the same thing).
4. Fill in `ground_truth_labeled_by` (your name/handle) and
   `ground_truth_labeled_at` (an ISO 8601 timestamp) — these aren't scored,
   but they matter for anyone reviewing the corpus later.
5. Leave `notes` for anything worth flagging (background noise, two people
   talking over each other, a word you genuinely couldn't make out — see
   below).

If a call is genuinely unintelligible even to a human (happens — that's
real radio audio), write your best guess and note it in `notes`, or leave
`ground_truth_transcript` empty and explain why in `notes`. An empty
`ground_truth_transcript` is treated as "not yet labeled" and excluded from
scoring (see `dmr_stt_labeled_entries()`), which is the right behavior for
"I genuinely can't tell" — don't force a guess into the scored field.

You do NOT need to label every entry before getting useful numbers — the
measurement test scores whatever is labeled and SKIPs cleanly on the rest.
Even 5-10 labeled calls is enough to see whether a later pipeline change
moves the needle on the specific failure modes it targets.

## Running the measurement

```bash
php tests/test_dmr_stt_corpus_measurement.php
```

Example output once entries are labeled (illustrative — not real numbers,
since the corpus shipped with this harness has zero labeled entries; see
"Corpus status as shipped" below):

```
  de000000   normal      WER= 0.000  CER= 0.000
  e3000000   repetition  WER= 1.833  CER= 0.912  [repetition-loop flagged]
  e4000000   short       WER= 1.000  CER= 0.833

--- Aggregate (baseline — current, unmodified pipeline) ---
  Labeled calls scored: 3
  Mean WER: 0.944
  Mean CER: 0.582
  Repetition-loop flagged: 1
```

This same command is how you'd measure a LATER change (e.g. Tier 1 idea
#2's per-call hotword from the resolved DMR-ID): label a corpus once, run
the baseline, apply the change, re-run against the same labeled corpus, and
compare the two "Mean WER"/"Mean CER" lines. That comparison is exactly
what the research doc's own minimal experiment design calls for — this
harness is built to run twice.

## Corpus status as shipped (2026-08-21)

44 real calls sampled from `dvswitch-host`'s `ticketscad-echo-bot` service,
spanning 2026-06-18 through 2026-08-20, stratified across the 5 heuristic
categories (`empty`, `short`, `long`, `repetition`, `normal`) plus every
specific example the research doc cited by name:

- The captured hallucination/repetition loop ("When I'm faj... When I'm
  faj... ...faj..." ×7).
- The 5-call callsign-chaos thread ("BZ.", "KV-0BZ", "GV0BZ", "KB0, BZ.",
  "KB0BZ.") plus an earlier call from the same radio ID with the same
  callsign confusion.
- The garbled-phonetics example ("...Zero Delta Julia Kilo-Mophial...").
- Two fragment-only-junk examples ("the" from a 42-second transmission;
  "your name mobile").
- A clean baseline ("This is KF0ZGT, radio test, radio test.").

**Zero of the 44 entries are labeled yet** — `ground_truth_transcript` is
`null` throughout. `tests/test_dmr_stt_corpus_measurement.php` SKIPs
cleanly against this corpus today, which is the correct, proven behavior
(this exact corpus was used to verify the SKIP path). The corpus itself is
gitignored (`tools/dmr-stt-corpus/`) and lives only on disk wherever it was
built — **the next step for real numbers is a human labeling at least a
handful of these 44 calls** per the workflow above.

## A real bug this harness's own build caught

Two, worth naming since both were caught by driving the REAL functions
against real or realistic data rather than trusting the design on paper
(this project's standing root-cause-troubleshooting discipline):

1. **`call_id` is not a safe unique key across the corpus.**
   `echo_bot.py`'s stream id is 4 bytes of in-memory process state
   (`_calls` dict) that resets to `00000000` on every bot restart. Sampling
   against the full ~2-month log with `call_id` as the dedup/merge key
   collapsed genuinely different calls together and produced a "stratified
   sample of 40" that was actually 282 calls. Fixed by keying everything
   (`dmr_stt_stratified_sample()`, `dmr_stt_merge_manifest()`) on the WAV
   filename instead, which embeds the label + timestamp + radio ID +
   talkgroup + call_id together and is always unique.
2. **PHP's `/` operator returns `int`, not `float`, on an exact division**
   (e.g. `0 / 7` is `int(0)`, not `float(0.0)`). `wer_score()`/`cer_score()`
   both document a `float` return type for `wer`/`cer`, but a perfect-match
   score (distance 0) silently violated that contract before an explicit
   `(float)` cast was added. A strict `=== 0.0` sanity check in
   `tests/test_dmr_stt_corpus_measurement.php` caught this the first time
   the harness scored a real (if temporary, for-verification-only) labeled
   entry — see `tests/test_dmr_stt_wer_cer_math.php`'s regression test for
   the permanent guard.

## What this harness deliberately does NOT do

Per the task that produced it: **no change to the live STT pipeline.**
`services/dvswitch/echo_bot.py`'s actual `transcribe()` call, model
(`base.en`), and parameters are completely untouched — no hotwords, no
`initial_prompt`, no `repetition_penalty`. Every Tier 1 idea in
`specs/dmr-ambe-stt-improvement-ideas.md` that changes pipeline behavior is
still just an idea until Eric explicitly says to build it; this harness
only makes it possible to measure whether any of them actually help.
