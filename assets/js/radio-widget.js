/**
 * NewUI v4.0 — Radio Widget (Phase 84s)
 *
 * Floating widget on the Communications panel that opens when the
 * dispatcher clicks the RADIO button. Streams live audio off TG 3127
 * via the SSE endpoint `api/dmr-stream.php`, supports DVR-style rewind
 * (up to 30 seconds), and PTT either by holding the on-widget button
 * or by holding Space.
 *
 * Architecture:
 *   - SSE connection to api/dmr-stream.php; each event carries a base64-
 *     encoded PCM chunk (8 kHz mono s16le) plus call metadata.
 *   - Browser maintains a 30-second ring buffer of PCM samples + a play
 *     pointer; the AudioWorklet (or fallback ScriptProcessor) reads
 *     samples at the pointer and writes to the audio output.
 *   - Live = play pointer chases the write pointer.
 *   - Rewind = play pointer steps back; the ring buffer keeps writing
 *     so the listener can catch up to live again later.
 *   - PTT capture uses MediaRecorder + an AnalyserNode for VU meter;
 *     captured chunks are POSTed to api/dmr-tx-audio.php which forwards
 *     to hbp_client for HBP encoding + transmission.
 *
 * ES5 IIFE — no arrow functions, no let/const, no template literals.
 */
(function () {
    'use strict';

    // ── Constants ───────────────────────────────────────────────
    var SAMPLE_RATE      = 8000;            // DMR codec native
    var RING_SECONDS     = 30;              // DVR rewind capacity
    var RING_SAMPLES     = SAMPLE_RATE * RING_SECONDS;
    var REWIND_STEP_DEFAULT = 5;            // sec/click — Eric's pick
    var REWIND_STEP_MIN  = 2;
    var REWIND_STEP_MAX  = 10;
    var STREAM_URL       = 'api/dmr-stream.php';
    var TX_AUDIO_URL     = 'api/dmr-tx-audio.php';
    var TX_STREAM_URL    = 'api/dmr-tx-stream.php';   // deprecated, kept for fallback
    var WS_TOKEN_URL     = 'api/dmr-token.php';
    var WS_PATH          = '/dmr-ws';                  // Apache proxy_wstunnel target
    var HISTORY_URL      = 'api/dmr-history.php?limit=100';
    var PTT_MIN_HOLD_MS  = 250;             // ignore <250 ms clicks
    var STORAGE_KEY      = 'radio-widget-pos';
    var REWIND_PREF_KEY  = 'radio-widget-rewind-s';

    function loadRewindStep() {
        try {
            var v = parseFloat(localStorage.getItem(REWIND_PREF_KEY));
            if (!isFinite(v)) return REWIND_STEP_DEFAULT;
            if (v < REWIND_STEP_MIN) return REWIND_STEP_MIN;
            if (v > REWIND_STEP_MAX) return REWIND_STEP_MAX;
            return v;
        } catch (e) { return REWIND_STEP_DEFAULT; }
    }
    function saveRewindStep(v) {
        try { localStorage.setItem(REWIND_PREF_KEY, String(v)); } catch (e) {}
    }
    var rewindStepS = loadRewindStep();

    // ── DOM ─────────────────────────────────────────────────────
    var widget = null;
    var feedEl = null;
    var statusBadge = null;
    var channelLabel = null;
    var rewindBtn = null;
    var playPauseBtn = null;
    var liveBtn = null;
    var scrubber = null;
    var posLabel = null;
    var pttBtn = null;
    var vuBar = null;

    // ── State ───────────────────────────────────────────────────
    var visible      = false;
    var minimized    = false;
    var es           = null;       // EventSource
    var audioCtx     = null;       // Web Audio API context
    var workletNode  = null;       // Worklet or fallback script processor
    var muted        = false;      // play/pause state (rewind/live)

    // Phase 101 (Eric beta 2026-07-01) — audio-mute distinct from the
    // play/pause muted state. When audioMuted the DVR ring keeps
    // filling + text/history keeps rendering + unread badge still
    // increments; only final playback into the AudioContext is
    // silenced. Persisted per-browser via localStorage.
    var audioMuted   = false;
    try {
        audioMuted = (localStorage.getItem('radio_audio_muted') === '1');
    } catch (e) { /* Safari private mode etc. */ }

    // GH #55 (Eric 2026-07-04) — Live monitor, mirroring the Zello widget.
    // ON: channel audio plays even while the widget is minimized or you have
    // navigated to another page. OFF: audio only while the widget is open and
    // expanded. Defaults ON so the DMR widget's long-standing always-monitor
    // behavior is preserved; the toggle just adds the ability to turn it off.
    // Mute is independent and overrides this (silence wins).
    var liveMonitor = true;
    try {
        var _lm = localStorage.getItem('radio_live_monitor');
        if (_lm !== null) liveMonitor = (_lm === '1');
    } catch (e) { /* ignore */ }
    // Audio may play now given widget visibility + the live-monitor pref.
    function audioAllowed() { return liveMonitor || (visible && !minimized); }

    var ring         = null;       // Float32Array ring buffer
    var writeIndex   = 0;          // next sample-write index (mod RING)
    var totalWritten = 0;          // monotonic sample counter for "absolute" time
    var playIndex    = 0;          // next sample-read index (mod RING)
    var totalPlayed  = 0;          // monotonic sample-read counter
    var liveLagSamples = 0;        // (totalWritten - totalPlayed) before pause
    var atLive       = true;       // play pointer chasing write?
    var calls        = [];         // recent call metadata
    // Phase 85c-fix-17: was 30, capped the visible feed regardless of
    // what HISTORY_URL fetched. We pull 100 historical calls from the
    // API; the trim was throwing away 70 of them on initial render.
    // 150 leaves headroom for live cards appearing on top of history.
    var callsMaxFeed = 150;
    var dragState    = { active: false, startX: 0, startY: 0, origLeft: 0, origTop: 0 };
    var resizeState  = { active: false, startX: 0, startY: 0, origW: 0, origH: 0 };

    // PTT
    var pttActive = false;
    var pttKeyDown = false;
    var pttHoldStartMs = 0;
    var micStream = null;
    var pttAnalyser = null;
    var pttRecorder = null;
    var vuRaf = null;

    // ── Init ────────────────────────────────────────────────────
    function init() {
        var tpl = document.getElementById('tpl-radio-widget');
        if (!tpl) {
            console.warn('[radio] no tpl-radio-widget — widget cannot init');
            return;
        }
        var node = tpl.content ? tpl.content.cloneNode(true) : tpl.cloneNode(true);
        widget = node.querySelector('.radio-widget');
        if (!widget) return;
        document.body.appendChild(widget);

        feedEl       = widget.querySelector('#radioFeed');
        statusBadge  = widget.querySelector('.radio-status-badge');
        channelLabel = widget.querySelector('.radio-header-channel');
        rewindBtn    = widget.querySelector('#radioRewind');
        playPauseBtn = widget.querySelector('#radioPlayPause');
        liveBtn      = widget.querySelector('#radioLive');
        scrubber     = widget.querySelector('#radioScrubber');
        posLabel     = widget.querySelector('#radioPosLabel');
        pttBtn       = widget.querySelector('#radioPttBtn');
        vuBar        = widget.querySelector('.radio-vu-bar');

        // Restore position
        var saved = loadPosition();
        if (saved) {
            widget.style.left = saved.left + 'px';
            widget.style.top  = saved.top + 'px';
            if (saved.width)  widget.style.width  = saved.width  + 'px';
            if (saved.height) widget.style.height = saved.height + 'px';
        } else {
            widget.style.right = '16px';
            widget.style.bottom = '16px';
            widget.style.left = '';
            widget.style.top = '';
        }

        wireHeader();
        wireTransport();
        wirePtt();
        wireKeyboard();
        wireDrag();
        wireResize();

        // Phase 84-followup-9: SINGLE source of truth for opening.
        // The widget's own delegator handles every data-action="radio"
        // click on every page. The OLD code also subscribed to the
        // EventBus 'radio:toggle' event that app.js emits — but
        // app.js's emit ran in addition to this delegator, causing a
        // SINGLE click on the dashboard's RADIO button to fire the
        // toggle TWICE (open then close in microseconds — the widget
        // never appeared to the user).
        //
        // The delegator catches all clicks for every page; the
        // EventBus subscription is intentionally NOT registered.
        // app.js's EventBus.emit('radio:toggle') is now harmless
        // (no listener).
        document.addEventListener('click', function (e) {
            var t = e.target;
            while (t && t !== document) {
                if (t.getAttribute && t.getAttribute('data-action') === 'radio') {
                    e.preventDefault();
                    e.stopPropagation();
                    toggleVisible();
                    return;
                }
                t = t.parentNode;
            }
        }, true);  // capture-phase so we run BEFORE any per-page handler
        // Note: we used to auto-open the widget on page load when
        // localStorage said it was previously open, but that caused
        // every page navigation to fire SSE + history + N parallel
        // dmr-lookup calls (which can hit radioid.net live, 200-500ms
        // each) — making non-radio pages feel sluggish. The widget
        // now stays closed until the user clicks RADIO. Phase 85
        // dispatch-console scope will revisit cross-page persistence
        // via a top-frame iframe or service worker.
    }

    // ── Public toggle ───────────────────────────────────────────
    function toggleVisible() {
        if (!widget) init();
        if (!widget) return;
        if (visible) {
            hide();
        } else {
            show();
        }
    }

    function show() {
        visible = true;
        try { localStorage.setItem('radio-widget-open', '1'); } catch (e) {}
        widget.classList.remove('radio-hidden');
        clampPosition();
        ensureAudio();
        // Phase 85c-fix-3: actively resume the playback context as
        // soon as the widget is opened — the RADIO click IS a user
        // gesture but the resume() must happen explicitly. Without
        // this, audioCtx stays suspended and no sound plays even
        // when the worklet receives ring-buffer samples.
        if (audioCtx && audioCtx.state === 'suspended') {
            audioCtx.resume().then(function () {
                console.info('[radio] audioCtx resumed on show, state=' + audioCtx.state);
            }).catch(function (e) {
                console.warn('[radio] audioCtx resume failed on show:', e);
            });
        } else if (audioCtx) {
            console.info('[radio] audioCtx state on show: ' + audioCtx.state);
        }
        ensureStream();
        startUiLoop();
        loadHistoryOnce();
        // Prefetch mic permission so the first PTT doesn't race the
        // browser prompt. We don't keep the stream — just trigger the
        // permission dialog one time, then release.
        prefetchMic();
        console.info('[radio] widget visible');
    }

    // GH #55 — feed flows newest-at-bottom (matching the Zello widget), so
    // keep the newest in view. Auto-scroll only when the operator is already
    // near the bottom ("following"), so scrolling up to read history is not
    // yanked back down. force=true always scrolls (initial history load / own TX).
    function scrollFeedToBottom(force) {
        if (!feedEl) return;
        var nearBottom = (feedEl.scrollHeight - feedEl.scrollTop - feedEl.clientHeight) < 60;
        if (force || nearBottom) {
            feedEl.scrollTop = feedEl.scrollHeight;
        }
    }

    // Phase 84-followup: pull recent dmr_messages on first show so the
    // operator sees prior traffic instead of an empty feed. Only fires
    // once per page load — re-opening the widget doesn't re-fetch.
    var historyLoaded = false;
    function loadHistoryOnce() {
        if (historyLoaded || !feedEl) return;
        historyLoaded = true;
        fetch(HISTORY_URL, { credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (j) {
                if (!j || !j.rows || !j.rows.length) return;
                console.info('[radio] loaded ' + j.rows.length + ' historical call(s)');
                var empty = feedEl.querySelector('.radio-feed-empty');
                if (empty) empty.remove();
                // GH #55: rows arrive oldest-first; appendChild() leaves
                // oldest at the top and newest at the bottom (chronological,
                // matching the Zello widget), then scroll to the newest.
                for (var i = 0; i < j.rows.length; i++) {
                    var row = j.rows[i];
                    seenHistoryIds[row.id] = true;  // dedup vs backfillRecentHistory
                    var card = renderHistoryCall(row);
                    if (card) feedEl.appendChild(card);
                }
                scrollFeedToBottom(true);
            })
            .catch(function (e) { console.warn('[radio] history load failed', e); });
    }

    // Phase 85c-fix-5: fetch the most recent dmr_messages row(s)
    // and prepend any we haven't seen yet. Called after a TX
    // succeeds so the dispatcher's own transmission appears in the
    // feed immediately. Bounded by limit=5 to keep it light.
    var seenHistoryIds = {};
    function refreshHistoryAfterTx() {
        if (!feedEl) return;
        // Small delay so the proxy's DB write has landed.
        setTimeout(function () {
            fetch('api/dmr-history.php?limit=5', { credentials: 'same-origin' })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (j) {
                    if (!j || !j.rows || !j.rows.length) return;
                    var empty = feedEl.querySelector('.radio-feed-empty');
                    if (empty) empty.remove();
                    // GH #55: append oldest-first so newest lands at the
                    // bottom; this is the operator's own just-sent TX, so
                    // always scroll to reveal it.
                    for (var i = 0; i < j.rows.length; i++) {
                        var row = j.rows[i];
                        if (seenHistoryIds[row.id]) continue;
                        seenHistoryIds[row.id] = true;
                        var card = renderHistoryCall(row);
                        if (card) feedEl.appendChild(card);
                    }
                    scrollFeedToBottom(true);
                })
                .catch(function (e) { console.warn('[radio] history refresh failed', e); });
        }, 500);
    }

    // Phase 85c-fix-7: SSE reconnect-window backfill. Called from the
    // 'open' handler every time the EventSource opens (initial + every
    // 5-min refresh). Pulls the most recent N rows and prepends any
    // unseen ones so cards that fired into the disconnect gap still
    // appear. Uses the same seenHistoryIds dedup as refreshHistoryAfterTx.
    function backfillRecentHistory() {
        if (!feedEl) return;
        fetch('api/dmr-history.php?limit=10', { credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (j) {
                if (!j || !j.rows || !j.rows.length) return;
                var added = 0;
                for (var i = 0; i < j.rows.length; i++) {
                    var row = j.rows[i];
                    if (seenHistoryIds[row.id]) continue;
                    seenHistoryIds[row.id] = true;
                    var card = renderHistoryCall(row);
                    if (card) {
                        feedEl.appendChild(card);
                        added++;
                    }
                }
                if (added > 0) {
                    var empty = feedEl.querySelector('.radio-feed-empty');
                    if (empty) empty.remove();
                    scrollFeedToBottom(false); // only if the operator is following
                    console.info('[radio] backfilled ' + added + ' missed call(s) after SSE open');
                }
            })
            .catch(function (e) { console.warn('[radio] backfill failed', e); });
    }

    function renderHistoryCall(row) {
        // Reuse renderCall() to keep the markup consistent, then mark
        // it non-replayable (its audio isn't in the live ring buffer)
        // and fill the transcript if echo_bot saved one.
        var msg = {
            call_id   : 'hist-' + row.id,
            src_id    : row.src_id || '?',
            callsign  : row.callsign || null,
            talkgroup : row.talkgroup,
        };
        var card = renderCall(msg, false);
        if (!card) return null;
        // Phase 85c-fix-9: history cards play their saved WAV via
        // api/dmr-audio.php. Stamp the row id on the card so
        // replayCall() can route to the recorded path instead of
        // the ring-buffer path.
        card.setAttribute('data-history-id', String(row.id));
        var replayBtn = card.querySelector('.radio-call-replay');
        if (replayBtn) {
            if (row.audio_path) {
                replayBtn.title = 'Play recorded transmission';
            } else {
                replayBtn.disabled = true;
                replayBtn.title = 'No recording available';
            }
        }
        // Fill timestamp from the saved start time, not "now".
        // Phase 85c-fix-10: server VM is set to America/Chicago and
        // PHP date() stores local time directly. Parse as local
        // (no 'Z' suffix) so what gets displayed is exactly what was
        // saved, not a UTC→local conversion.
        var timeSpan = card.querySelector('.radio-call-time');
        if (timeSpan && row.started_at) {
            var d = new Date(row.started_at.replace(' ', 'T'));
            if (!isNaN(d.getTime())) {
                timeSpan.textContent = String(d.getHours()).padStart(2, '0') + ':' +
                                       String(d.getMinutes()).padStart(2, '0') + ':' +
                                       String(d.getSeconds()).padStart(2, '0');
            }
        }
        // Phase 85c-fix-11: render duration next to timestamp.
        var durSpan = card.querySelector('.radio-call-duration');
        if (durSpan && row.duration_ms) {
            durSpan.textContent = ' · ' + formatDuration(row.duration_ms);
        }
        // Fill transcript (or hide the pending placeholder).
        var tEl = card.querySelector('.radio-call-transcript');
        if (tEl) {
            if (row.transcript) {
                tEl.classList.remove('radio-call-pending');
                tEl.textContent = row.transcript;
            } else {
                tEl.textContent = '(no transcript)';
                tEl.classList.add('radio-call-pending');
            }
        }
        // Name lookup is cheap — fire it for every history card too.
        if (row.src_id) resolveDmrId(row.src_id, card);
        return card;
    }

    // Phase 84w: rAF loop nudges the scrubber forward as playback
    // consumes samples. Without it, the slider stays stuck at the
    // rewound position even though pullSamples is advancing the
    // playhead — the writeSamples + setPlayLag paths were the only
    // ones calling updatePosUi() before.
    var uiRaf = null;
    function startUiLoop() {
        if (uiRaf) return;
        var tick = function () {
            updatePosUi();
            uiRaf = requestAnimationFrame(tick);
        };
        uiRaf = requestAnimationFrame(tick);
    }
    function stopUiLoop() {
        if (uiRaf) cancelAnimationFrame(uiRaf);
        uiRaf = null;
    }

    function clampPosition() {
        // Phase 84-followup-8: when the user toggles the widget on,
        // ALWAYS ensure the panel is anchored to the visible bottom-
        // right. Several users have reported "the widget isn't
        // loading" when in fact the panel was off-screen due to a
        // stale localStorage position from a previous page layout.
        // Drag-to-reposition during this session still works (the
        // saved position is honoured on subsequent toggles within
        // the same tab); but every fresh show() starts visible.
        var r = widget.getBoundingClientRect();
        var ww = window.innerWidth, wh = window.innerHeight;
        var offscreen = (r.width <= 0 || r.height <= 0 ||
                         r.left < 0 || r.top < 0 ||
                         r.left > ww - 40 || r.top > wh - 40);
        if (offscreen) {
            widget.style.left = '';
            widget.style.top  = '';
            widget.style.right = '16px';
            widget.style.bottom = '16px';
            try { localStorage.removeItem(STORAGE_KEY); } catch (e) {}
        }
        // Force on top of any other Bootstrap layer (modal=1055, toast=1090).
        widget.style.zIndex = '2000';
        // Phase 84-followup-9: when the widget says it's visible but
        // the user can't see it, ~always a CSS conflict — most often
        // an ancestor with overflow:hidden / position:relative
        // clipping the position:fixed child, or a parent stylesheet
        // forcing display:none. Override the obvious offenders
        // inline so they win over anything else.
        widget.style.display    = 'flex';
        widget.style.visibility = 'visible';
        widget.style.opacity    = '1';
        var rect = widget.getBoundingClientRect();
        var cs = window.getComputedStyle(widget);
        console.info('[radio] panel rect:',
            'left=' + Math.round(rect.left), 'top=' + Math.round(rect.top),
            'w=' + Math.round(rect.width), 'h=' + Math.round(rect.height),
            'viewport=' + ww + 'x' + wh);
        console.info('[radio] computed style:',
            'display=' + cs.display,
            'visibility=' + cs.visibility,
            'opacity=' + cs.opacity,
            'position=' + cs.position,
            'zIndex=' + cs.zIndex,
            'parent=' + (widget.parentNode ? widget.parentNode.tagName : '?'));
    }

    var micPrefetched = false;
    function prefetchMic() {
        if (micPrefetched) return;
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            showBanner('Microphone API unavailable in this browser; PTT disabled.', 'warn');
            return;
        }
        navigator.mediaDevices.getUserMedia({ audio: true })
            .then(function (s) {
                micPrefetched = true;
                // Stop tracks immediately — we just wanted the permission grant.
                try { s.getTracks().forEach(function (t) { t.stop(); }); } catch (e) {}
                console.info('[radio] mic permission granted');
            })
            .catch(function (err) {
                showBanner('Microphone permission denied — PTT will not work. ' +
                           'Re-allow in browser site settings.', 'warn');
                console.warn('[radio] mic prefetch denied:', err);
            });
    }

    function hide() {
        visible = false;
        try { localStorage.setItem('radio-widget-open', '0'); } catch (e) {}
        widget.classList.add('radio-hidden');
        stopUiLoop();
        // Don't close the stream — keep DVR buffer filling so user
        // can re-open and rewind without losing context.
    }

    // ── Header (minimize/close/mute, drag handled separately) ───
    function wireHeader() {
        var minBtn = widget.querySelector('#radioMinimize');
        var closeBtn = widget.querySelector('#radioClose');

        // Phase 101 — audio-mute toggle. Distinct from the play/pause
        // control (which drives DVR rewind). This one just silences
        // the final speaker output; the ring buffer keeps filling,
        // text/history keep rendering, unread badge still ticks.
        var muteBtn = widget.querySelector('#radioAudioMuteBtn');
        if (muteBtn) {
            renderAudioMuteButton(muteBtn);
            muteBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                audioMuted = !audioMuted;
                try { localStorage.setItem('radio_audio_muted', audioMuted ? '1' : '0'); } catch (err) {}
                renderAudioMuteButton(muteBtn);
            });
        }

        // GH #55 — live-monitor toggle (mirrors the Zello widget).
        var liveBtnHdr = widget.querySelector('#radioLiveBtn');
        if (liveBtnHdr) {
            renderRadioLiveButton(liveBtnHdr);
            liveBtnHdr.addEventListener('click', function (e) {
                e.stopPropagation();
                liveMonitor = !liveMonitor;
                try { localStorage.setItem('radio_live_monitor', liveMonitor ? '1' : '0'); } catch (err) {}
                renderRadioLiveButton(liveBtnHdr);
            });
        }

        if (minBtn) minBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            minimized = !minimized;
            widget.classList.toggle('radio-minimized', minimized);
        });
        if (closeBtn) closeBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            hide();
        });
    }

    // ── Transport (rewind / play-pause / live / scrubber) ───────
    function wireTransport() {
        if (rewindBtn) {
            rewindBtn.addEventListener('click', function () { rewindBy(rewindStepS); });
            // Right-click (or long-press) the rewind button to change the step.
            rewindBtn.addEventListener('contextmenu', function (e) {
                e.preventDefault();
                openRewindSettings();
            });
            updateRewindLabel();
        }
        if (playPauseBtn) playPauseBtn.addEventListener('click', function () {
            setMuted(!muted);
        });
        if (liveBtn) liveBtn.addEventListener('click', function () {
            jumpToLive();
        });
        if (scrubber) {
            scrubber.addEventListener('input', function () {
                var pos = parseFloat(scrubber.value);  // 0 .. RING_SECONDS, where RING_SECONDS = live
                var lagSec = (RING_SECONDS - pos);
                if (lagSec <= 0.2) {
                    jumpToLive();
                } else {
                    setPlayLagSeconds(lagSec);
                }
            });
        }
        var settingsBtn = widget.querySelector('#radioSettings');
        if (settingsBtn) settingsBtn.addEventListener('click', openRewindSettings);
    }

    function updateRewindLabel() {
        if (!rewindBtn) return;
        var lbl = rewindBtn.querySelector('.radio-transport-label');
        if (lbl) lbl.textContent = '-' + rewindStepS + 's';
        rewindBtn.setAttribute('title', 'Rewind ' + rewindStepS + ' sec (right-click to change)');
    }

    function openRewindSettings() {
        // Toggle existing popover if open
        var existing = widget.querySelector('.radio-settings-pop');
        if (existing) { existing.remove(); return; }

        var pop = document.createElement('div');
        pop.className = 'radio-settings-pop';
        pop.innerHTML =
            '<label class="form-label small mb-1">Rewind step (seconds per click)</label>' +
            '<div class="d-flex align-items-center gap-2">' +
                '<input type="number" class="form-control form-control-sm" ' +
                'id="radioRewindInput" min="' + REWIND_STEP_MIN + '" max="' + REWIND_STEP_MAX +
                '" step="0.5" value="' + rewindStepS + '" style="max-width:80px">' +
                '<button class="btn btn-sm btn-primary" id="radioRewindSave">Save</button>' +
                '<button class="btn btn-sm btn-outline-secondary" id="radioRewindClose">Cancel</button>' +
            '</div>' +
            '<div class="small text-secondary mt-1">Range ' + REWIND_STEP_MIN + '–' + REWIND_STEP_MAX + ' seconds.</div>';

        // Anchor under the rewind button
        var transport = widget.querySelector('.radio-transport');
        if (transport && transport.parentNode) {
            transport.parentNode.insertBefore(pop, transport.nextSibling);
        } else {
            widget.appendChild(pop);
        }
        var inp = pop.querySelector('#radioRewindInput');
        inp.focus(); inp.select();
        pop.querySelector('#radioRewindSave').addEventListener('click', function () {
            var v = parseFloat(inp.value);
            if (!isFinite(v) || v < REWIND_STEP_MIN || v > REWIND_STEP_MAX) {
                inp.classList.add('is-invalid');
                return;
            }
            rewindStepS = v;
            saveRewindStep(v);
            updateRewindLabel();
            pop.remove();
        });
        pop.querySelector('#radioRewindClose').addEventListener('click', function () {
            pop.remove();
        });
        inp.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') pop.querySelector('#radioRewindSave').click();
            if (e.key === 'Escape') pop.remove();
        });
    }

    function rewindBy(sec) {
        var lag = currentLagSamples() + Math.round(sec * SAMPLE_RATE);
        if (lag > RING_SAMPLES - 1) lag = RING_SAMPLES - 1;
        setPlayLag(lag);
    }

    function jumpToLive() {
        atLive = true;
        playIndex = writeIndex;
        totalPlayed = totalWritten;
        liveLagSamples = 0;
        updatePosUi();
    }

    function setPlayLagSeconds(sec) {
        setPlayLag(Math.round(sec * SAMPLE_RATE));
    }
    function setPlayLag(lagSamples) {
        if (lagSamples < 0) lagSamples = 0;
        if (lagSamples > RING_SAMPLES - 1) lagSamples = RING_SAMPLES - 1;
        // Phase 84v: can't rewind past the start of recorded audio —
        // clamping prevents totalPlayed from going negative which would
        // skip pullSamples() and leave the user staring at silence.
        if (lagSamples > totalWritten) lagSamples = totalWritten;
        atLive = (lagSamples === 0);
        liveLagSamples = lagSamples;
        playIndex = (writeIndex - lagSamples + RING_SAMPLES) % RING_SAMPLES;
        totalPlayed = totalWritten - lagSamples;
        // When the user rewinds, force unmute so the audio is audible.
        if (lagSamples > 0 && muted) setMuted(false);
        updatePosUi();
        console.info('[radio] rewind lag=' + lagSamples + ' samples (' +
                     (lagSamples / SAMPLE_RATE).toFixed(1) + 's), ring has ' +
                     totalWritten + ' samples recorded');
    }

    function currentLagSamples() {
        if (atLive) return 0;
        return liveLagSamples;
    }

    function setMuted(m) {
        muted = m;
        var icon = playPauseBtn.querySelector('i');
        if (icon) icon.className = muted ? 'bi bi-play-fill' : 'bi bi-pause-fill';
    }

    // Phase 101 — Reflect current audioMuted state on the header
    // toolbar button. Icon flips between volume-up-fill and
    // volume-mute-fill; a11y attributes track it too.
    function renderAudioMuteButton(btn) {
        if (!btn) btn = widget && widget.querySelector('#radioAudioMuteBtn');
        if (!btn) return;
        var icon = btn.querySelector('i');
        if (icon) {
            icon.className = audioMuted
                ? 'bi bi-volume-mute-fill'
                : 'bi bi-volume-up-fill';
        }
        btn.title = audioMuted ? 'Unmute incoming audio' : 'Mute incoming audio';
        btn.setAttribute('aria-pressed', audioMuted ? 'true' : 'false');
        btn.setAttribute('aria-label', btn.title);
    }

    // GH #55 — reflect the live-monitor state on its header button, matching
    // the Zello widget (solid broadcast-pin + green when active).
    function renderRadioLiveButton(btn) {
        if (!btn) btn = widget && widget.querySelector('#radioLiveBtn');
        if (!btn) return;
        var icon = btn.querySelector('i');
        if (icon) {
            icon.className = liveMonitor ? 'bi bi-broadcast-pin' : 'bi bi-broadcast';
        }
        btn.title = liveMonitor
            ? 'Live monitor ON — audio plays even when the widget is minimized. Click to limit audio to when the widget is open.'
            : 'Live monitor off — audio plays only while the widget is open. Click to keep hearing the channel when minimized.';
        btn.setAttribute('aria-pressed', liveMonitor ? 'true' : 'false');
        btn.setAttribute('aria-label', liveMonitor ? 'Live monitor on' : 'Live monitor off');
        if (liveMonitor) {
            btn.classList.remove('btn-outline-secondary');
            btn.classList.add('btn-success');
        } else {
            btn.classList.remove('btn-success');
            btn.classList.add('btn-outline-secondary');
        }
    }

    function updatePosUi() {
        if (!scrubber) return;
        var lagSec = currentLagSamples() / SAMPLE_RATE;
        var sliderVal = RING_SECONDS - lagSec;
        if (sliderVal < 0) sliderVal = 0;
        scrubber.value = sliderVal;
        if (posLabel) {
            if (atLive) {
                posLabel.textContent = 'live';
            } else {
                posLabel.textContent = '-' + lagSec.toFixed(1) + 's';
            }
        }
    }

    // ── Web Audio (output) ──────────────────────────────────────
    function ensureAudio() {
        if (audioCtx) return;
        try {
            var AC = window.AudioContext || window.webkitAudioContext;
            audioCtx = new AC({ sampleRate: SAMPLE_RATE });
        } catch (e) {
            console.error('[radio] AudioContext failed:', e);
            return;
        }
        ring = new Float32Array(RING_SAMPLES);

        // Audio worklet for low-latency stream output. Fall back to
        // ScriptProcessorNode if Worklet isn't supported.
        // Phase 84v: worklet must APPEND received samples to its
        // pending buffer, not replace it — replacing dropped any
        // unplayed samples every time the main thread sent a new
        // chunk, so the audio always pulled from the very latest
        // batch (silence after rewind, glitchy during live).
        // Cap the pending buffer at 1 second to bound memory.
        var workletCode =
            "class RadioOut extends AudioWorkletProcessor {" +
            "  constructor() { super(); var self = this; this.buf = new Float32Array(0); this.port.onmessage = function (e) {" +
            "    if (!e.data || !e.data.length) return;" +
            "    var combined = new Float32Array(self.buf.length + e.data.length);" +
            "    combined.set(self.buf, 0); combined.set(e.data, self.buf.length);" +
            "    if (combined.length > 8000) combined = combined.subarray(combined.length - 8000);" +
            "    self.buf = combined;" +
            "  }; }" +
            "  process(inputs, outputs) {" +
            "    var out = outputs[0][0]; var i = 0;" +
            "    if (this.buf && this.buf.length) {" +
            "      var n = Math.min(out.length, this.buf.length);" +
            "      for (; i < n; i++) out[i] = this.buf[i];" +
            "      this.buf = this.buf.subarray(n);" +
            "    }" +
            "    for (; i < out.length; i++) out[i] = 0;" +
            "    this.port.postMessage(out.length);" +
            "    return true;" +
            "  }" +
            "}" +
            "registerProcessor('radio-out', RadioOut);";
        var blob = new Blob([workletCode], { type: 'application/javascript' });
        var url = URL.createObjectURL(blob);
        if (audioCtx.audioWorklet) {
            audioCtx.audioWorklet.addModule(url).then(function () {
                workletNode = new AudioWorkletNode(audioCtx, 'radio-out');
                workletNode.port.onmessage = function (e) {
                    // Worklet asks for more samples; deliver from the ring.
                    var need = e.data;
                    workletNode.port.postMessage(pullSamples(need));
                };
                workletNode.connect(audioCtx.destination);
                // Prime the pump
                workletNode.port.postMessage(pullSamples(2048));
            }).catch(function (err) {
                console.warn('[radio] worklet load failed; using ScriptProcessor:', err);
                fallbackScriptProcessor();
            });
        } else {
            fallbackScriptProcessor();
        }

        // Resume on user gesture in case it starts suspended.
        if (audioCtx.state === 'suspended') {
            var resume = function () {
                audioCtx.resume();
                document.removeEventListener('click', resume);
                document.removeEventListener('keydown', resume);
            };
            document.addEventListener('click', resume);
            document.addEventListener('keydown', resume);
        }
    }

    function fallbackScriptProcessor() {
        var bufSize = 1024;
        var spn = audioCtx.createScriptProcessor(bufSize, 0, 1);
        spn.onaudioprocess = function (e) {
            var out = e.outputBuffer.getChannelData(0);
            var samples = pullSamples(out.length);
            for (var i = 0; i < out.length; i++) out[i] = samples[i] || 0;
        };
        spn.connect(audioCtx.destination);
        workletNode = spn;
    }

    function pullSamples(n) {
        var out = new Float32Array(n);
        // Phase 101 — respect either the play/pause muted state OR
        // the audio-mute toggle. GH #55 — also the live-monitor gate:
        // when live-monitor is off and the widget isn't open+expanded,
        // stay silent. Return an all-zeros buffer either way so the
        // AudioWorklet keeps ticking without dropping the pipeline.
        if (muted || audioMuted || !audioAllowed()) return out;
        var avail = totalWritten - totalPlayed;
        if (avail <= 0) return out;
        var i = 0;
        while (i < n && totalPlayed < totalWritten) {
            out[i++] = ring[playIndex];
            playIndex = (playIndex + 1) % RING_SAMPLES;
            totalPlayed++;
        }
        // If we were at live, keep play pointer chasing write pointer
        if (atLive) {
            // Re-sync slack — if write is way ahead (e.g. tab was
            // backgrounded), jump forward to avoid stale audio.
            var slack = totalWritten - totalPlayed;
            if (slack > SAMPLE_RATE) {
                playIndex = writeIndex;
                totalPlayed = totalWritten;
            }
        } else {
            // Phase 84w: as the playhead consumes samples while no
            // new audio is arriving, the lag shrinks — that's what
            // makes the scrubber slide back toward LIVE.
            liveLagSamples = totalWritten - totalPlayed;
            if (liveLagSamples <= 0) {
                liveLagSamples = 0;
                atLive = true;
            }
        }
        return out;
    }

    // ── SSE stream (incoming PCM) ───────────────────────────────
    function ensureStream() {
        if (es) return;
        try {
            es = new EventSource(STREAM_URL);
        } catch (e) {
            console.error('[radio] EventSource construction failed:', e);
            setStatus('disconnected');
            showBanner('EventSource not supported by this browser.', 'err');
            return;
        }
        setStatus('idle');
        console.info('[radio] SSE opening', STREAM_URL);

        es.addEventListener('open', function () {
            setStatus('idle');
            console.info('[radio] SSE open');
            clearBanner();
            // Successful open resets the backoff so the next failure
            // starts the retry clock from 2s again, not 30s.
            sseReconnectDelayMs = 2000;
            // Phase 85c-fix-7: Apache prefork caps each SSE at ~5 min,
            // so the widget cycles through disconnect/reconnect every
            // 5 min. Any call_start/audio/call_end that fired during
            // the brief disconnect window is lost forever via SSE —
            // but the DB row exists (echo_bot wrote inbound, proxy
            // wrote our own TX). Backfill from history on every open
            // so the operator never misses a card. seenHistoryIds
            // dedups against earlier loads.
            backfillRecentHistory();
        });
        es.addEventListener('error', function (ev) {
            setStatus('disconnected');
            // readyState 2 = CLOSED, 0 = CONNECTING (auto-reconnecting),
            // 1 = OPEN. EventSource handles transient errors itself, but
            // permanent CLOSED (server timeout, non-event-stream
            // response, etc.) needs us to schedule a manual retry —
            // otherwise the widget shows a stale "disconnected" banner
            // forever and the operator has no audio.
            var rs = es ? es.readyState : -1;
            console.warn('[radio] SSE error, readyState=' + rs, ev);
            if (rs === 2) {
                scheduleSseReconnect();
            }
        });
        es.addEventListener('keepalive', function () { /* heartbeat — ignore */ });

        // Phase 85c-fix-12: catch-all listener for anything the
        // named-event listeners didn't pick up. If the bridge ever
        // emits an event type we forgot to handle, or the relay
        // mangles the event name, this surfaces it instead of
        // silently dropping. Also logs every received event type
        // and count so we can verify the audio frames are reaching
        // the browser — separate from whether they play.
        var sseEventCounts = {};
        var sseEventLogTimer = null;
        function sseTallyEvent(type) {
            sseEventCounts[type] = (sseEventCounts[type] || 0) + 1;
            if (sseEventLogTimer) return;
            sseEventLogTimer = setTimeout(function () {
                console.info('[radio] SSE event counts (last 5s):', sseEventCounts);
                sseEventCounts = {};
                sseEventLogTimer = null;
            }, 5000);
        }
        // The native `message` event fires for SSE messages WITHOUT
        // an event: field (which shouldn't happen with our relay,
        // but log it if it does).
        es.addEventListener('message', function (ev) {
            sseTallyEvent('(unnamed-message)');
            console.warn('[radio] unnamed SSE message:', ev.data.slice(0, 200));
        });
        // Wrap the named listeners with a counter. We override the
        // ones we added above so the tally captures them. Done via
        // intercept rather than adding more addEventListener calls
        // so we don't double-fire the original handlers.
        ['audio', 'call_start', 'call_end', 'transcript'].forEach(function (t) {
            es.addEventListener(t, function () { sseTallyEvent(t); });
        });

        // Phase 85c-fix-12: the audio / call_start / call_end /
        // transcript handlers BELONG HERE on the initial EventSource.
        // For most of Phase 85, they were misplaced inside
        // scheduleSseReconnect — meaning the live-audio handler was
        // never attached on the first connection, and only got
        // attached to the OLD failed EventSource on reconnect (which
        // was then replaced by a new one with no handlers). Live RX
        // audio was silently broken end-to-end since the refactor.
        es.addEventListener('audio', function (ev) {
            try {
                var msg = JSON.parse(ev.data);
                handleAudioFrame(msg);
            } catch (e) { console.warn('[radio] bad audio frame:', e); }
        });
        es.addEventListener('call_start', function (ev) {
            try {
                var msg = JSON.parse(ev.data);
                handleCallStart(msg);
            } catch (e) {}
        });
        es.addEventListener('call_end', function (ev) {
            try {
                var msg = JSON.parse(ev.data);
                handleCallEnd(msg);
            } catch (e) {}
        });
        es.addEventListener('transcript', function (ev) {
            try {
                var msg = JSON.parse(ev.data);
                handleTranscript(msg);
            } catch (e) {}
        });
    }

    // Phase 84-followup: when EventSource hits readyState=2 (CLOSED)
    // — server hung up, PHP timeout, network blip — we manually
    // schedule a fresh EventSource. Exponential backoff capped at
    // 30 s so a downed bridge doesn't hammer the proxy.
    var sseReconnectTimer = null;
    var sseReconnectDelayMs = 2000;
    function scheduleSseReconnect() {
        if (sseReconnectTimer) return;          // already scheduled
        var delay = sseReconnectDelayMs;
        showBanner('Live audio stream disconnected. Reconnecting in ' +
                   Math.round(delay / 1000) + 's…', 'warn');
        sseReconnectTimer = setTimeout(function () {
            sseReconnectTimer = null;
            if (es) { try { es.close(); } catch (e) {} es = null; }
            console.info('[radio] reconnecting SSE…');
            ensureStream();
        }, delay);
        // Next attempt waits twice as long, up to 30 s.
        sseReconnectDelayMs = Math.min(sseReconnectDelayMs * 2, 30000);
    }

    var _audioFrameCount = 0;
    var _audioByteCount = 0;
    var _audioFrameLogAt = 0;
    function handleAudioFrame(msg) {
        // msg.pcm is base64 of 16-bit LE PCM at 8 kHz mono
        if (!msg.pcm) return;
        // Phase 85c-fix-6: if THIS session originated the TX, the
        // bridge is looping our own audio back to us via SSE. Skip
        // playback (we'd hear ourselves with ~300ms lag) and skip
        // the live history card (refreshHistoryAfterTx will fetch
        // it from the DB after tx_ack). Other sessions still see
        // the call normally.
        if (msg.tx_origin && isMyTxInFlight) return;
        var bytes = b64ToBytes(msg.pcm);
        var samples = pcm16ToFloat32(bytes);
        writeSamples(samples);
        if (msg.call_id) setStatus('live');
        _audioFrameCount++;
        _audioByteCount += bytes.length;
        // Log first frame + every 100th + transition into audio. Helps
        // diagnose "no playback" — if this log fires but no sound, the
        // ring buffer or worklet output is broken; if it never fires,
        // the SSE pipeline isn't delivering audio events.
        if (_audioFrameCount === 1 || _audioFrameCount - _audioFrameLogAt >= 100) {
            _audioFrameLogAt = _audioFrameCount;
            console.info('[radio] RX audio frame #' + _audioFrameCount,
                'samples=' + samples.length,
                'totalBytes=' + _audioByteCount,
                'audioCtx.state=' + (audioCtx ? audioCtx.state : 'null'),
                'ringFill=' + (totalWritten - totalPlayed) + '/' + RING_SAMPLES);
        }
        // Phase 85c-fix-3: force-resume playback context on first
        // received audio frame. AudioContexts created without an
        // active user gesture start in 'suspended' state. The
        // first frame is the right moment to nudge it running —
        // user has interacted to open the widget, audio is
        // arriving, we want speakers active.
        if (audioCtx && audioCtx.state === 'suspended') {
            audioCtx.resume().then(function () {
                console.info('[radio] audioCtx resumed for playback');
            }).catch(function (e) {
                console.warn('[radio] audioCtx resume failed:', e);
            });
        }
    }

    function writeSamples(samples) {
        if (!ring) return;
        var n = samples.length;
        for (var i = 0; i < n; i++) {
            ring[writeIndex] = samples[i];
            writeIndex = (writeIndex + 1) % RING_SAMPLES;
        }
        totalWritten += n;
        if (atLive) {
            // Keep play pointer with write pointer at live.
            playIndex = writeIndex;
            totalPlayed = totalWritten;
        }
        updatePosUi();
    }

    function handleCallStart(msg) {
        // Skip live card for own TX — refreshHistoryAfterTx fills it
        // in from the DB after tx_ack with audio_path attached.
        if (msg.tx_origin && isMyTxInFlight) return;
        var card = renderCall(msg, true);
        if (card) {
            // Phase 84y: record where in the ring buffer this call
            // begins so the per-tile replay button can jump back to
            // exactly the start sample.
            card.setAttribute('data-call-start', String(totalWritten));
            feedEl.appendChild(card);
            scrollFeedToBottom(false); // GH #55 — follow newest at bottom
            // Phase 84w: resolve src_id → personnel name/callsign and
            // overwrite the card's title once the answer arrives.
            // Lookups are cached client-side so repeated calls from
            // the same source don't re-query the server.
            if (msg.src_id) resolveDmrId(msg.src_id, card);
        }
        var empty = feedEl.querySelector('.radio-feed-empty');
        if (empty) empty.remove();
        setStatus('live');
    }

    var dmrLookupCache = {};
    var dmrLookupPending = {};
    var DMR_LOOKUP_LS_PREFIX = 'radio-dmrlookup-';
    var DMR_LOOKUP_TTL_MS = 7 * 24 * 60 * 60 * 1000;  // 1 week

    // Hydrate from localStorage on init so navigating between pages
    // doesn't re-fire N parallel /api/dmr-lookup.php requests for
    // operators we already resolved minutes ago.
    function lookupCacheGet(key) {
        if (dmrLookupCache[key]) return dmrLookupCache[key];
        try {
            var raw = localStorage.getItem(DMR_LOOKUP_LS_PREFIX + key);
            if (!raw) return null;
            var obj = JSON.parse(raw);
            if (!obj || !obj._ts) return null;
            if ((Date.now() - obj._ts) > DMR_LOOKUP_TTL_MS) return null;
            dmrLookupCache[key] = obj;
            return obj;
        } catch (e) { return null; }
    }
    function lookupCacheSet(key, value) {
        dmrLookupCache[key] = value;
        try {
            var copy = {};
            for (var k in value) copy[k] = value[k];
            copy._ts = Date.now();
            localStorage.setItem(DMR_LOOKUP_LS_PREFIX + key, JSON.stringify(copy));
        } catch (e) { /* localStorage full or disabled — non-fatal */ }
    }

    function resolveDmrId(dmrId, card) {
        var key = String(dmrId);
        var cached = lookupCacheGet(key);
        if (cached) {
            applyDmrIdLookup(card, dmrId, cached);
            return;
        }
        if (dmrLookupPending[key]) {
            dmrLookupPending[key].push(card);
            return;
        }
        dmrLookupPending[key] = [card];
        fetch('api/dmr-lookup.php?dmr_id=' + encodeURIComponent(key), {
            credentials: 'same-origin',
        }).then(function (r) {
            return r.ok ? r.json() : { source: 'unknown' };
        }).then(function (j) {
            lookupCacheSet(key, j);
            var cards = dmrLookupPending[key] || [];
            delete dmrLookupPending[key];
            for (var i = 0; i < cards.length; i++) applyDmrIdLookup(cards[i], dmrId, j);
        }).catch(function () {
            delete dmrLookupPending[key];
        });
    }
    function applyDmrIdLookup(card, dmrId, info) {
        if (!card) return;
        var srcSpan = card.querySelector('.radio-call-src');
        if (!srcSpan) return;
        var parts = [String(dmrId)];
        if (info.callsign) parts.push(info.callsign);
        if (info.name) parts.push(info.name);
        srcSpan.textContent = parts.join(' · ');
        if (info.source === 'personnel') {
            srcSpan.setAttribute('data-source', 'personnel');
            srcSpan.title = 'Personnel match (member #' + info.member_id + ')';
        } else if (info.source === 'radioid_cache') {
            srcSpan.setAttribute('data-source', 'radioid');
            srcSpan.title = 'radioid.net cache' + (info.country ? ' (' + info.country + ')' : '');
        }
    }

    function handleCallEnd(msg) {
        if (msg.tx_origin && isMyTxInFlight) return;
        var card = feedEl.querySelector('[data-call-id="' + cssEsc(msg.call_id) + '"]');
        if (card) {
            card.classList.remove('radio-call-live');
            // Phase 84y: snapshot the ring write-pointer so replay
            // knows where the call ended. Used to bound the replay
            // window (and could be used later to auto-stop at end).
            card.setAttribute('data-call-end', String(totalWritten));
            // Phase 85c-fix-23: stamp `data-history-id` on the live
            // card once the bridge has persisted the dmr_messages row.
            // Without this, the card has `data-call-start` (a ring
            // buffer offset) but no history id, so once the audio
            // ages past the 30 s ring window replay falls into the
            // "older than DVR window" banner instead of playing the
            // saved WAV. ~1.5 s gives the bridge time to finish
            // writing the row + audio_path.
            setTimeout(function () { promoteEndedCallToHistory(card); }, 1500);
        }
        setStatus('idle');
    }

    // Phase 85c-fix-23: find the dmr_messages row matching this just-
    // ended live card and stamp its id onto the card so future replay
    // routes through api/dmr-audio.php instead of the dead ring buffer.
    function promoteEndedCallToHistory(card) {
        if (!card || card.getAttribute('data-history-id')) return;
        var callEndedAtMs = Date.now();
        fetch('api/dmr-history.php?limit=5', { credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (j) {
                if (!j || !j.rows || !j.rows.length) return;
                // Rows arrive oldest-first. We want the newest one
                // whose start time is within a sensible window of our
                // card (the call started before now and ended just
                // before this fetch; the row's `started_at` should be
                // within the last ~60 s).
                for (var i = j.rows.length - 1; i >= 0; i--) {
                    var row = j.rows[i];
                    if (!row.id || !row.started_at) continue;
                    if (seenHistoryIds[row.id]) continue;
                    var rowMs = Date.parse(row.started_at.replace(' ', 'T'));
                    if (!isFinite(rowMs)) continue;
                    var age = callEndedAtMs - rowMs;
                    if (age < -5000 || age > 60000) continue;
                    seenHistoryIds[row.id] = true;
                    card.setAttribute('data-history-id', String(row.id));
                    // If the row carries an audio_path, the play button
                    // is now wired to a real recording — keep it.
                    // Otherwise (no recording) gray out the button.
                    if (!row.audio_path) {
                        var btn = card.querySelector('.radio-call-replay');
                        if (btn) {
                            btn.disabled = true;
                            btn.title = 'No recording on file';
                        }
                    }
                    return;
                }
            })
            .catch(function () { /* silent — replay will still try */ });
    }

    function handleTranscript(msg) {
        var card = feedEl.querySelector('[data-call-id="' + cssEsc(msg.call_id) + '"]');
        if (!card) return;
        var t = card.querySelector('.radio-call-transcript');
        if (t) {
            t.classList.remove('radio-call-pending');
            t.textContent = msg.text || '(no transcript)';
        }
    }

    function renderCall(msg, isLive) {
        if (!feedEl) return null;
        var card = document.createElement('div');
        card.className = 'radio-call' + (isLive ? ' radio-call-live' : '');
        card.setAttribute('data-call-id', msg.call_id || '');
        var head = document.createElement('div');
        head.className = 'radio-call-head';
        head.innerHTML =
            '<i class="bi bi-broadcast"></i>' +
            ' <span class="radio-call-src">' + escHTML(msg.src_id || 'unknown') + '</span>' +
            ' <span class="text-secondary small">→ TG ' + escHTML(msg.talkgroup || '?') + '</span>' +
            ' <span class="radio-call-time">' + nowHHMM() + '</span>' +
            ' <span class="radio-call-duration text-secondary small"></span>' +
            ' <button type="button" class="radio-call-replay" title="Replay this transmission" aria-label="Replay">' +
                '<i class="bi bi-play-circle"></i>' +
            '</button>';
        card.appendChild(head);
        head.querySelector('.radio-call-replay').addEventListener('click', function (e) {
            e.stopPropagation();
            replayCall(card);
        });
        var t = document.createElement('div');
        t.className = 'radio-call-transcript radio-call-pending';
        t.textContent = '…';
        card.appendChild(t);
        // Trim feed. GH #55 — the feed now flows newest-at-bottom (matching
        // the Zello widget), so the OLDEST card is the first one; drop it
        // when over the cap (was existing[last] when newest was at the top).
        var existing = feedEl.querySelectorAll('.radio-call');
        if (existing.length > callsMaxFeed) existing[0].remove();
        return card;
    }

    function setStatus(s) {
        if (!statusBadge) return;
        statusBadge.className = 'radio-status-badge status-' + s;
    }

    // Phase 84y: replay one specific transmission. Reads the
    // start-sample data attribute the call_start handler stamped on
    // the card, and jumps the playhead there via setPlayLag(). If
    // the call's start is older than RING_SECONDS, the audio has
    // been overwritten — surface a banner rather than playing
    // garbage from the middle of the ring.
    function replayCall(card) {
        // Phase 85c-fix-9: history cards (data-history-id) play their
        // saved WAV file via api/dmr-audio.php. Live cards
        // (data-call-start, set during call_start SSE event) replay
        // from the ring buffer. The two paths don't overlap.
        var historyId = card.getAttribute('data-history-id');
        if (historyId) {
            playRecordedAudio(card, parseInt(historyId, 10));
            return;
        }
        var startStr = card.getAttribute('data-call-start');
        if (!startStr) return;
        var startSample = parseInt(startStr, 10);
        if (!isFinite(startSample)) return;
        var lag = totalWritten - startSample;
        if (lag < 0) lag = 0;
        // The ring only holds RING_SAMPLES; older calls have been
        // overwritten and can't be replayed in-widget. Try one last
        // promote-on-demand in case the card's history-id hadn't
        // landed yet, then route to the saved WAV if found.
        if (lag > RING_SAMPLES - 1 || lag > totalWritten) {
            promoteEndedCallToHistory(card);
            // After promotion runs (async), if a history id was stamped,
            // play the WAV. Otherwise show the original banner.
            setTimeout(function () {
                var hid = card.getAttribute('data-history-id');
                if (hid) {
                    playRecordedAudio(card, parseInt(hid, 10));
                } else {
                    showBanner('That transmission is older than the 30-second DVR window and no recording is on file.', 'warn');
                }
            }, 1600);
            return;
        }
        // Force unmute and snap the playhead to the call's first sample.
        if (muted) setMuted(false);
        setPlayLag(lag);
        console.info('[radio] replay call: lag=' + lag + ' samples (' +
                     (lag / SAMPLE_RATE).toFixed(1) + 's behind live)');
    }

    // Phase 85c-fix-9: HTML5 <audio> playback for history rows whose
    // WAV file lives on the bridge VM. Reuses one <audio> element per
    // card so a second click toggles pause; clicking another card
    // stops the previous one. Server-stored bridge token lives in
    // dmr_channels so the URL doesn't need a token parameter.
    var historyAudioPlayers = {};
    var activeHistoryAudioId = null;
    function stopAllHistoryPlayback(exceptRowId) {
        for (var k in historyAudioPlayers) {
            if (!historyAudioPlayers.hasOwnProperty(k)) continue;
            if (exceptRowId != null && String(k) === String(exceptRowId)) continue;
            var p = historyAudioPlayers[k];
            try { if (p && !p.paused) { p.pause(); p.currentTime = 0; } } catch (e) {}
            if (feedEl) {
                var pc = feedEl.querySelector('[data-history-id="' + k + '"]');
                if (pc) pc.classList.remove('radio-call-playing');
            }
        }
    }
    function playRecordedAudio(card, rowId) {
        if (!isFinite(rowId)) return;
        // Phase 85c-fix-15: bulletproof "only one history clip plays
        // at a time" — iterate every cached player and pause any
        // that's currently active, regardless of bookkeeping state.
        // The earlier activeHistoryAudioId pattern was missing some
        // edge cases (rapid clicks, ended-then-replayed, etc.) and
        // Eric reported overlapping audio.
        stopAllHistoryPlayback(rowId);
        var audio = historyAudioPlayers[rowId];
        if (audio && !audio.paused) {
            // Toggle pause on a second click — but stopAllHistoryPlayback
            // above already paused it; treat as off and return.
            card.classList.remove('radio-call-playing');
            activeHistoryAudioId = null;
            return;
        }
        if (!audio) {
            audio = new Audio('api/dmr-audio.php?msg_id=' + rowId);
            audio.preload = 'auto';
            audio.addEventListener('ended', function () {
                card.classList.remove('radio-call-playing');
                if (activeHistoryAudioId === rowId) activeHistoryAudioId = null;
            });
            audio.addEventListener('error', function () {
                card.classList.remove('radio-call-playing');
                showBanner('Recording playback failed (check bridge connectivity).', 'err');
                console.warn('[radio] history playback error rowId=' + rowId, audio.error);
            });
            historyAudioPlayers[rowId] = audio;
        }
        card.classList.add('radio-call-playing');
        activeHistoryAudioId = rowId;
        var p = audio.play();
        if (p && p.catch) p.catch(function (e) {
            card.classList.remove('radio-call-playing');
            console.warn('[radio] audio.play() rejected:', e);
        });
    }

    // ── PTT ─────────────────────────────────────────────────────
    function wirePtt() {
        if (!pttBtn) return;
        pttBtn.addEventListener('mousedown', pttStart);
        pttBtn.addEventListener('touchstart', pttStart, { passive: true });
        pttBtn.addEventListener('mouseup', pttEnd);
        pttBtn.addEventListener('mouseleave', pttEnd);
        pttBtn.addEventListener('touchend', pttEnd);
    }

    function wireKeyboard() {
        document.addEventListener('keydown', function (e) {
            if (e.code !== 'Space' || !visible) return;
            // Don't intercept space when an input is focused.
            var tag = (document.activeElement && document.activeElement.tagName) || '';
            if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;
            if (e.repeat) return;
            if (pttKeyDown) return;
            pttKeyDown = true;
            pttStart(e);
            e.preventDefault();
        });
        document.addEventListener('keyup', function (e) {
            if (e.code !== 'Space' || !visible) return;
            if (!pttKeyDown) return;
            pttKeyDown = false;
            pttEnd(e);
            e.preventDefault();
        });
    }

    var preTxMuted = false;
    function pttStart() {
        if (pttActive) return;
        pttActive = true;
        pttHoldStartMs = Date.now();
        pttBtn.classList.add('radio-ptt-active');
        setStatus('tx');
        // Phase 84-followup-10: mute incoming playback while WE
        // transmit — anything that BrandMeister relays back to us
        // (echo, or our own TX hitting our hotspot) would create a
        // feedback loop through the dispatcher's speakers/mic.
        // Remember the user's previous mute state so we restore it
        // when PTT ends.
        preTxMuted = muted;
        if (!muted) setMuted(true);
        // Phase 84t-followup: a fresh PTT attempt always starts with
        // a clean banner — stale "Transmit failed" text from prior
        // errors lingers until SSE reconnects, which can persist
        // through several successful retries.
        clearBanner();
        // Begin capture (best-effort)
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            console.warn('[radio] getUserMedia unavailable — PTT disabled');
            return;
        }
        navigator.mediaDevices.getUserMedia({ audio: { channelCount: 1, sampleRate: 16000 } })
            .then(startRecording)
            .catch(function (err) {
                console.warn('[radio] mic denied/failed:', err);
                pttEnd();
            });
    }

    var micCtx = null;   // SEPARATE AudioContext for the microphone.
    var pttWorklet = null;     // Phase 85b downsampler worklet node.
    var pttStreamCtl = null;   // ReadableStream controller — feeds upload body.
    var pttStreamPromise = null;   // fetch promise for the streaming POST.
    function startRecording(stream) {
        micStream = stream;
        try {
            // Phase 84-followup-10: the playback AudioContext runs at
            // 8 kHz to match the DMR PCM coming over SSE; getUserMedia
            // returns a stream at the browser's native rate (usually
            // 48 kHz). Web Audio forbids connecting nodes across
            // AudioContexts with different rates. Use a SEPARATE
            // AudioContext (browser default rate) for the mic path —
            // it never needs to interconnect with the playback graph.
            if (!micCtx) {
                var AC = window.AudioContext || window.webkitAudioContext;
                micCtx = new AC();   // omit sampleRate -> browser default
            }
            // AudioContexts created without an active user gesture
            // start in 'suspended' state; nothing in the audio graph
            // runs until resume() is called. PTT click IS a user
            // gesture so this should always succeed.
            if (micCtx.state === 'suspended') {
                micCtx.resume().then(function () {
                    console.info('[radio] micCtx resumed, state=' + micCtx.state +
                                 ' rate=' + micCtx.sampleRate);
                }).catch(function (e) {
                    console.warn('[radio] micCtx resume failed:', e);
                });
            } else {
                console.info('[radio] micCtx state=' + micCtx.state +
                             ' rate=' + micCtx.sampleRate);
            }
            var src = micCtx.createMediaStreamSource(stream);
            pttAnalyser = micCtx.createAnalyser();
            pttAnalyser.fftSize = 256;
            src.connect(pttAnalyser);
            pumpVu();
        } catch (e) { console.warn('[radio] vu setup failed:', e); }

        // Phase 85c — WebSocket TX (mirrors Zello proxy pattern).
        // Browser opens wss://host/dmr-ws, sends one auth message
        // with a token from /api/dmr-token.php, then streams binary
        // PCM frames as the worklet produces them. The proxy daemon
        // forwards via a server-side chunked HTTP POST to the
        // bridge — reliable, unlike browser-side streaming fetch
        // which broke in Firefox 140 (Phase 85b post-mortem).
        startWsRecording(stream).catch(function (err) {
            console.warn('[radio] WS TX setup failed; falling back to batched:', err);
            startBatchedRecording(stream);
        });
    }

    // Phase 85c — WebSocket TX. Browser → /api/dmr-token.php for a
    // single-use auth token → opens wss://host/dmr-ws → sends
    // {cmd:"auth", token} → on auth_ok, AudioWorklet mic downsamples
    // 48 kHz → 8 kHz mono s16le and ws.send(binary) for each frame.
    // On PTT release: {cmd:"ptt_end"}; proxy responds with tx_ack.
    var pttWs = null;
    var pttWsCtx = null;       // {token, channel} from token endpoint
    // Phase 85c-fix-6: while THIS session is transmitting, the bridge
    // loops own-TX audio back via SSE (tx_origin:true) so other
    // dispatcher sessions hear it. The originating session would
    // double-render (live card from SSE + post-TX card from DB refresh)
    // and would route its own voice back into its speakers. Suppress
    // both for any tx_origin event seen while isMyTxInFlight is set.
    var isMyTxInFlight = false;
    function startWsRecording(stream) {
        // Step 1: mint a one-shot auth token over the regular PHP
        // session (cookie-authenticated).
        return fetch(WS_TOKEN_URL, { credentials: 'same-origin' })
            .then(function (r) {
                if (!r.ok) throw new Error('token endpoint HTTP ' + r.status);
                return r.json();
            })
            .then(function (tokenInfo) {
                pttWsCtx = tokenInfo;
                console.info('[radio] DMR token issued for', tokenInfo.user,
                             '(channel #' + (tokenInfo.channel_id || 'default') + ')');
                return openPttWs(tokenInfo, stream);
            });
    }

    function openPttWs(tokenInfo, stream) {
        return new Promise(function (resolve, reject) {
            var proto = location.protocol === 'https:' ? 'wss:' : 'ws:';
            var wsUrl = proto + '//' + location.host + WS_PATH;
            console.info('[radio] DMR WS connecting:', wsUrl);
            pttWs = new WebSocket(wsUrl);
            pttWs.binaryType = 'arraybuffer';
            var authed = false;
            var bytesSent = 0;

            pttWs.addEventListener('open', function () {
                console.info('[radio] DMR WS open');
                pttWs.send(JSON.stringify({
                    cmd: 'auth',
                    token: tokenInfo.token,
                    channel: tokenInfo.channel_id || 0,
                }));
            });

            pttWs.addEventListener('message', function (e) {
                if (typeof e.data !== 'string') return;
                var msg;
                try { msg = JSON.parse(e.data); } catch (err) { return; }
                if (msg.type === 'auth_ok') {
                    authed = true;
                    isMyTxInFlight = true;
                    console.info('[radio] DMR WS auth_ok on channel:',
                                 msg.channel ? msg.channel.label : '?');
                    pttWs.send(JSON.stringify({ cmd: 'ptt_start' }));
                    // Start the AudioWorklet pump.
                    startWsMicPump(stream).then(function (b) {
                        bytesSent = b;
                        resolve();
                    }).catch(reject);
                } else if (msg.type === 'tx_started') {
                    console.info('[radio] DMR WS tx_started');
                } else if (msg.type === 'tx_ack') {
                    console.info('[radio] DMR WS tx_ack:', msg);
                    isMyTxInFlight = false;
                    clearBanner();
                    if (msg.ok) {
                        showBanner('Transmit sent (' + (msg.packets_sent || 0) +
                                   ' packets, ' + (msg.bytes_received || 0) +
                                   ' bytes received by bridge)', 'info');
                        setTimeout(clearBanner, 4000);
                        // Phase 85c-fix-5: the proxy records this TX in
                        // dmr_messages — refresh history so the new
                        // entry appears in the call feed.
                        refreshHistoryAfterTx();
                    } else {
                        showBanner('Transmit failed at bridge (HTTP ' +
                                   (msg.http_code || '?') + ')', 'err');
                    }
                } else if (msg.type === 'error') {
                    console.warn('[radio] DMR WS error event:', msg.message);
                    showBanner('TX: ' + msg.message, 'err');
                }
            });

            pttWs.addEventListener('error', function (ev) {
                console.warn('[radio] DMR WS error', ev);
                if (!authed) reject(new Error('WS error before auth'));
            });

            pttWs.addEventListener('close', function (ev) {
                console.info('[radio] DMR WS closed code=' + ev.code +
                             ' reason=' + (ev.reason || ''));
                pttWs = null;
                pttWsCtx = null;
                isMyTxInFlight = false;
                if (!authed) reject(new Error('WS closed before auth'));
            });
        });
    }

    function startWsMicPump(stream) {
        // Same AudioWorklet downsampler as Phase 85b — produces
        // 8 kHz mono s16le PCM frames. Each frame goes out as one
        // binary WebSocket message.
        // Windowed-sinc FIR low-pass + decimation. Phase 85c-fix-4
        // upgrade from the box-average filter — box-average had a
        // soft frequency rolloff (sinc-shaped) that let voice
        // sibilance up around 4-6 kHz still alias into the audio
        // band. The windowed-sinc gives a sharp cutoff at 3600 Hz
        // (90% of target Nyquist) with a Blackman window for tight
        // stopband (>-70 dB) and 31 taps = 0.6 ms group delay at
        // 48 kHz — imperceptible.
        //
        // For each input sample we update the circular buffer of the
        // last N inputs. We compute output samples only when the
        // fractional phase accumulator overflows (decimation by the
        // sample-rate ratio). Each output costs N multiply-adds.
        // At 8 kHz output × 31 taps = 248k mults/sec — negligible.
        //
        // The taps are computed at constructor time from the actual
        // AudioContext sample rate, so non-integer ratios (44.1 kHz
        // mic -> 8 kHz = ratio 5.5125) work without phase drift.
        var workletCode =
            "class MicDownsampler extends AudioWorkletProcessor {" +
            "  constructor(opts) { super();" +
            "    this.targetRate = (opts && opts.processorOptions && opts.processorOptions.targetRate) || 8000;" +
            "    this.ratio = sampleRate / this.targetRate;" +
            "    var nTaps = 31;" +
            "    var fc = (this.targetRate * 0.45) / sampleRate;" +  // 3600 Hz / 48000 = 0.075
            "    var taps = new Float32Array(nTaps);" +
            "    var center = (nTaps - 1) / 2;" +
            "    var sum = 0;" +
            "    for (var k = 0; k < nTaps; k++) {" +
            "      var n = k - center;" +
            "      var sinc = (n === 0) ? (2 * fc) : Math.sin(2 * Math.PI * fc * n) / (Math.PI * n);" +
            // Blackman window — better stopband than Hamming
            "      var win = 0.42 - 0.5 * Math.cos(2 * Math.PI * k / (nTaps - 1)) + 0.08 * Math.cos(4 * Math.PI * k / (nTaps - 1));" +
            "      taps[k] = sinc * win;" +
            "      sum += taps[k];" +
            "    }" +
            // Normalize so DC gain = 1.0 (no level change)
            "    for (var k = 0; k < nTaps; k++) taps[k] /= sum;" +
            "    this.taps = taps;" +
            "    this.nTaps = nTaps;" +
            "    this.buf = new Float32Array(nTaps);" +
            "    this.bufIdx = 0;" +
            "    this.frac = 0;" +
            // Phase 85c-fix-22: accumulate output samples into a fixed-
            // size buffer (160 int16 samples = 320 bytes = one 20 ms
            // AMBE+2 frame at 8 kHz) and only postMessage once we've
            // filled a full frame. Without this, every process() callback
            // produced ~21 samples → 42-byte WebSocket frames at ~367/sec,
            // which the proxy forwarded as 367 chunked-encoding chunks/sec
            // into the bridge → AMBE encoder saw bursty sub-frame PCM and
            // produced audible distortion. One frame at a time at 50 Hz
            // matches the AMBE encoder's natural cadence and drops WS
            // overhead by 7×.
            "    this.FRAME_SAMPLES = 160;" +    // 20 ms @ 8 kHz
            "    this.outBuf = new Int16Array(this.FRAME_SAMPLES);" +
            "    this.outLen = 0;" +
            "  }" +
            "  process(inputs, outputs) {" +
            "    var ch = inputs[0] && inputs[0][0];" +
            "    if (!ch || !ch.length) return true;" +
            "    var i, k, acc, idx;" +
            "    var n = this.nTaps;" +
            "    var taps = this.taps;" +
            "    var buf = this.buf;" +
            "    var outBuf = this.outBuf;" +
            "    var FRAME = this.FRAME_SAMPLES;" +
            "    for (i = 0; i < ch.length; i++) {" +
            // shift sample into circular buffer
            "      buf[this.bufIdx] = ch[i];" +
            "      this.bufIdx = (this.bufIdx + 1) % n;" +
            "      this.frac += 1;" +
            "      if (this.frac >= this.ratio) {" +
            "        this.frac -= this.ratio;" +
            // FIR convolution: dot product taps with last N samples
            "        acc = 0;" +
            "        idx = this.bufIdx;" +
            "        for (k = 0; k < n; k++) {" +
            "          acc += taps[k] * buf[idx];" +
            "          idx = (idx + 1) % n;" +
            "        }" +
            "        var s = Math.max(-1, Math.min(1, acc));" +
            "        outBuf[this.outLen++] = s < 0 ? s * 0x8000 : s * 0x7FFF;" +
            // Flush when the AMBE-frame-sized buffer is full.
            "        if (this.outLen >= FRAME) {" +
            "          var ab = new ArrayBuffer(FRAME * 2);" +
            "          new Int16Array(ab).set(outBuf);" +
            "          this.port.postMessage(ab, [ab]);" +
            "          this.outLen = 0;" +
            "        }" +
            "      }" +
            "    }" +
            "    return true;" +
            "  }" +
            "}" +
            "registerProcessor('mic-downsampler', MicDownsampler);";
        var blob = new Blob([workletCode], { type: 'application/javascript' });
        var url = URL.createObjectURL(blob);

        return micCtx.audioWorklet.addModule(url).then(function () {
            pttWorklet = new AudioWorkletNode(micCtx, 'mic-downsampler', {
                processorOptions: { targetRate: 8000 },
            });
            var src = micCtx.createMediaStreamSource(stream);
            var sink = micCtx.createGain();
            sink.gain.value = 0;
            src.connect(pttWorklet);
            pttWorklet.connect(sink);
            sink.connect(micCtx.destination);

            var totalBytes = 0;
            var messageCount = 0;
            var startTime = Date.now();
            pttWorklet.port.onmessage = function (e) {
                messageCount++;
                if (pttWs && pttWs.readyState === WebSocket.OPEN) {
                    pttWs.send(e.data);
                    totalBytes += e.data.byteLength;
                }
                if (messageCount % 100 === 0) {
                    var elapsed = (Date.now() - startTime) / 1000;
                    // Target is 16000 bytes/sec for real-time 8 kHz mono s16le.
                    // Anything less means we're feeding the bridge slower
                    // than it can transmit → silence/glitches on the radio.
                    var byteRate = elapsed > 0 ? Math.round(totalBytes / elapsed) : 0;
                    console.info('[radio] WS pump:', messageCount,
                                 'frames,', totalBytes, 'bytes sent,',
                                 byteRate, 'B/s (target 16000)');
                }
            };
            pttWorklet._diagnosticTotals = function () {
                var elapsed = (Date.now() - startTime) / 1000;
                return {
                    messages: messageCount,
                    bytes: totalBytes,
                    elapsed_sec: elapsed,
                    byte_rate: elapsed > 0 ? Math.round(totalBytes / elapsed) : 0,
                };
            };
            console.info('[radio] WS mic pump active at',
                         Math.round(micCtx.sampleRate), 'Hz -> 8000 Hz',
                         '(ratio ' + (micCtx.sampleRate / 8000).toFixed(3) + ')');
            return totalBytes;
        });
    }

    function startBatchedRecording(stream) {
        // Legacy path — MediaRecorder collects WebM/Opus until release,
        // then POSTs the whole blob. Kept as fallback for browsers
        // without streaming fetch (Safari, older Edge).
        try {
            pttRecorder = new MediaRecorder(stream, { mimeType: 'audio/webm;codecs=opus' });
        } catch (e) {
            try { pttRecorder = new MediaRecorder(stream); }
            catch (e2) { console.warn('[radio] MediaRecorder failed:', e2); return; }
        }
        var chunks = [];
        pttRecorder.ondataavailable = function (e) {
            if (e.data && e.data.size > 0) chunks.push(e.data);
        };
        pttRecorder.onstop = function () {
            if (!pttRecorder || chunks.length === 0) return;
            var blob = new Blob(chunks, { type: pttRecorder.mimeType || 'audio/webm' });
            sendPtt(blob);
        };
        pttRecorder.start(250);
    }

    function startStreamingRecording(stream) {
        // AudioWorklet processor: receives mic frames at the context's
        // native sample rate (usually 48 kHz), downsamples to 8 kHz
        // mono, converts to s16le, and posts the bytes to the main
        // thread. Inline as a Blob URL — same pattern as the playback
        // worklet (CSP now allows blob: in script-src, Phase 84-fu-6).
        var workletCode =
            "class MicDownsampler extends AudioWorkletProcessor {" +
            "  constructor(opts) { super();" +
            "    this.targetRate = (opts && opts.processorOptions && opts.processorOptions.targetRate) || 8000;" +
            "    this.ratio = sampleRate / this.targetRate;" +
            "    this.pos = 0;" +
            "  }" +
            "  process(inputs, outputs) {" +
            "    var ch = inputs[0] && inputs[0][0];" +
            "    if (!ch || !ch.length) return true;" +
            "    var out = []; var i;" +
            "    for (i = 0; i < ch.length; i++) {" +
            "      this.pos += 1;" +
            "      if (this.pos >= this.ratio) {" +
            "        this.pos -= this.ratio;" +
            "        var s = Math.max(-1, Math.min(1, ch[i]));" +
            "        out.push(s < 0 ? s * 0x8000 : s * 0x7FFF);" +
            "      }" +
            "    }" +
            "    if (out.length) {" +
            "      var buf = new ArrayBuffer(out.length * 2);" +
            "      var view = new DataView(buf);" +
            "      for (i = 0; i < out.length; i++) view.setInt16(i * 2, out[i], true);" +
            "      this.port.postMessage(buf, [buf]);" +
            "    }" +
            "    return true;" +
            "  }" +
            "}" +
            "registerProcessor('mic-downsampler', MicDownsampler);";
        var blob = new Blob([workletCode], { type: 'application/javascript' });
        var url = URL.createObjectURL(blob);

        return micCtx.audioWorklet.addModule(url).then(function () {
            pttWorklet = new AudioWorkletNode(micCtx, 'mic-downsampler', {
                processorOptions: { targetRate: 8000 },
            });
            // Connect mic -> worklet -> zero-gain sink -> destination.
            // The worklet must be wired to a downstream node that's
            // ultimately connected to destination, OR process() never
            // runs. Routing through a gain=0 sink keeps it silent.
            var src = micCtx.createMediaStreamSource(stream);
            var sink = micCtx.createGain();
            sink.gain.value = 0;
            src.connect(pttWorklet);
            pttWorklet.connect(sink);
            sink.connect(micCtx.destination);

            // Build the streaming POST. The ReadableStream below is
            // our body — its enqueue() is called by the worklet's
            // onmessage as PCM chunks arrive. cancel() runs when the
            // network end closes (we don't expect that — we close
            // from our side via controller.close() in pttEnd).
            var bodyStream = new ReadableStream({
                start: function (ctl) { pttStreamCtl = ctl; },
                cancel: function () { pttStreamCtl = null; },
            });

            var totalBytesEnqueued = 0;
            var messageCount = 0;
            pttWorklet.port.onmessage = function (e) {
                messageCount++;
                if (!pttStreamCtl) return;
                try {
                    var bytes = new Uint8Array(e.data);
                    pttStreamCtl.enqueue(bytes);
                    totalBytesEnqueued += bytes.length;
                    // Log every ~half second so we can see the rate.
                    if (messageCount % 50 === 0) {
                        console.info('[radio] worklet:', messageCount,
                                     'messages,', totalBytesEnqueued, 'bytes enqueued');
                    }
                } catch (err) {
                    console.warn('[radio] enqueue failed:', err);
                }
            };
            pttWorklet._diagnosticTotals = function () {
                return { messages: messageCount, bytes: totalBytesEnqueued };
            };

            console.info('[radio] streaming TX: AudioWorklet at',
                Math.round(micCtx.sampleRate), 'Hz -> 8000 Hz target');

            pttStreamPromise = fetch(TX_STREAM_URL, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/octet-stream' },
                body: bodyStream,
                duplex: 'half',
            }).then(function (r) {
                if (!r.ok) {
                    return r.text().then(function (t) {
                        console.warn('[radio] tx-stream HTTP', r.status, t);
                        showBanner('Transmit failed (HTTP ' + r.status + '): ' +
                                   t.slice(0, 140), 'err');
                    });
                }
                return r.json().then(function (j) {
                    console.info('[radio] tx-stream OK', j);
                    clearBanner();
                    showBanner('Transmit sent (' + (j.packets_sent || 0) +
                               ' packets, stream ' + (j.stream_id || '').slice(0, 8) + ')',
                               'info');
                    setTimeout(clearBanner, 4000);
                });
            }).catch(function (err) {
                console.warn('[radio] tx-stream post failed:', err);
                showBanner('Transmit failed: ' + (err && err.message ? err.message : err), 'err');
            });
        });
    }

    function pumpVu() {
        if (!pttAnalyser || !pttActive) return;
        var data = new Uint8Array(pttAnalyser.fftSize);
        pttAnalyser.getByteTimeDomainData(data);
        var peak = 0;
        for (var i = 0; i < data.length; i++) {
            var v = Math.abs(data[i] - 128);
            if (v > peak) peak = v;
        }
        var pct = Math.min(100, (peak / 128) * 100 * 2.5);
        if (vuBar) vuBar.style.width = pct.toFixed(1) + '%';
        vuRaf = requestAnimationFrame(pumpVu);
    }

    function pttEnd() {
        if (!pttActive) return;
        pttActive = false;
        pttBtn.classList.remove('radio-ptt-active');
        setStatus('idle');
        if (vuRaf) cancelAnimationFrame(vuRaf);
        if (vuBar) vuBar.style.width = '0%';
        // Phase 85c — WebSocket path: send ptt_end so the proxy
        // closes its upstream body (bridge sees EOF and fires
        // terminator bursts). The proxy replies asynchronously with
        // tx_ack which our message handler renders into the success
        // banner.
        if (pttWorklet && typeof pttWorklet._diagnosticTotals === 'function') {
            var t = pttWorklet._diagnosticTotals();
            console.info('[radio] PTT end totals: worklet emitted',
                t.messages, 'messages,', t.bytes, 'bytes total');
        }

        // Phase 85c-fix-21: keep the mic → worklet → WebSocket pipeline
        // ALIVE for a drain window after PTT release. Mic input latency
        // (mic hardware + OS audio stack + browser AudioContext) is
        // typically 50–200 ms — audio the user spoke just before
        // releasing is still working its way through that pipeline at
        // the moment we get the release event. If we tear the pipeline
        // down immediately (as fix-20 did) or send ptt_end immediately
        // (as pre-fix-20 did), the tail of every transmission gets
        // clipped. The fix: defer ALL teardown by DRAIN_MS so in-flight
        // audio reaches the worklet, gets converted, hits ws.send(),
        // and lands at the proxy before we say "we're done".
        var DRAIN_MS = 250;
        if (pttStreamCtl) {
            try { pttStreamCtl.close(); } catch (e) {}
            pttStreamCtl = null;
        }
        if (pttRecorder && pttRecorder.state === 'recording') pttRecorder.stop();

        setTimeout(function () {
            // Stop mic AFTER the drain so any audio in the mic→browser
            // pipeline at PTT-release time has had time to flow through.
            if (micStream) {
                try { micStream.getTracks().forEach(function (t) { t.stop(); }); }
                catch (e) {}
                micStream = null;
            }
            // Null the worklet's onmessage so any straggler frame
            // posted between disconnect() and GC doesn't fire ws.send.
            if (pttWorklet) {
                try { pttWorklet.port.onmessage = null; } catch (e) {}
                try { pttWorklet.disconnect(); } catch (e) {}
                pttWorklet = null;
            }
            // Now tell the proxy the body is done; bridge sees EOF and
            // emits terminator bursts.
            var wsToClose = pttWs;
            pttWs = null;
            if (wsToClose && wsToClose.readyState === WebSocket.OPEN) {
                try {
                    wsToClose.send(JSON.stringify({ cmd: 'ptt_end' }));
                } catch (e) {}
                // Give tx_ack a moment to land before yanking the socket.
                setTimeout(function () {
                    try { wsToClose.close(); } catch (e) {}
                }, 2000);
            } else if (wsToClose) {
                try { wsToClose.close(); } catch (e) {}
            }
        }, DRAIN_MS);
        // Restore the playback-mute state from before PTT pressed.
        if (!preTxMuted && muted) setMuted(false);
        var held = Date.now() - pttHoldStartMs;
        if (held < PTT_MIN_HOLD_MS) {
            // Treat as accidental tap — don't transmit.
            pttRecorder = null;
        }
    }

    function sendPtt(blob) {
        var fd = new FormData();
        fd.append('audio', blob, 'ptt.webm');
        fd.append('mime', blob.type);
        console.info('[radio] tx-audio POST', blob.size, 'bytes', blob.type);
        fetch(TX_AUDIO_URL, {
            method: 'POST',
            credentials: 'same-origin',
            body: fd,
        }).then(function (r) {
            if (!r.ok) {
                r.text().then(function (t) {
                    console.warn('[radio] tx-audio HTTP', r.status, t);
                    showBanner('Transmit failed (HTTP ' + r.status + '): ' + t.slice(0, 140), 'err');
                });
            } else {
                r.json().then(function (j) {
                    console.info('[radio] tx-audio OK', j);
                    // Confirm success in the UI so the operator sees
                    // the transmit landed even if they can't hear
                    // themselves on a separate radio.
                    clearBanner();
                    showBanner('Transmit sent (' + (j.duration_ms||0) + ' ms audio, tx ' +
                               (j.tx_id||'').slice(0,8) + ')', 'info');
                    setTimeout(clearBanner, 4000);
                }).catch(function () { clearBanner(); });
            }
        }).catch(function (e) {
            console.warn('[radio] tx-audio post failed:', e);
            showBanner('Transmit request failed: ' + (e && e.message ? e.message : e), 'err');
        });
    }

    // ── Drag / Resize ───────────────────────────────────────────
    function wireDrag() {
        var hdr = widget.querySelector('.radio-header');
        if (!hdr) return;
        hdr.addEventListener('mousedown', function (e) {
            // ignore clicks on header buttons
            if (e.target.closest('button')) return;
            dragState.active = true;
            dragState.startX = e.clientX;
            dragState.startY = e.clientY;
            var r = widget.getBoundingClientRect();
            dragState.origLeft = r.left;
            dragState.origTop = r.top;
            e.preventDefault();
        });
        document.addEventListener('mousemove', function (e) {
            if (!dragState.active) return;
            widget.style.left = (dragState.origLeft + (e.clientX - dragState.startX)) + 'px';
            widget.style.top  = (dragState.origTop  + (e.clientY - dragState.startY)) + 'px';
            widget.style.right = ''; widget.style.bottom = '';
        });
        document.addEventListener('mouseup', function () {
            if (dragState.active) { dragState.active = false; savePosition(); }
        });
    }

    function wireResize() {
        var handle = widget.querySelector('.radio-resize-handle');
        if (!handle) return;
        handle.addEventListener('mousedown', function (e) {
            resizeState.active = true;
            resizeState.startX = e.clientX;
            resizeState.startY = e.clientY;
            var r = widget.getBoundingClientRect();
            resizeState.origW = r.width;
            resizeState.origH = r.height;
            e.preventDefault();
        });
        document.addEventListener('mousemove', function (e) {
            if (!resizeState.active) return;
            var w = resizeState.origW + (e.clientX - resizeState.startX);
            var h = resizeState.origH + (e.clientY - resizeState.startY);
            if (w >= 280) widget.style.width  = w + 'px';
            if (h >= 320) widget.style.height = h + 'px';
        });
        document.addEventListener('mouseup', function () {
            if (resizeState.active) { resizeState.active = false; savePosition(); }
        });
    }

    function savePosition() {
        try {
            var r = widget.getBoundingClientRect();
            localStorage.setItem(STORAGE_KEY, JSON.stringify({
                left: r.left, top: r.top, width: r.width, height: r.height,
            }));
        } catch (e) {}
    }
    function loadPosition() {
        try {
            var s = localStorage.getItem(STORAGE_KEY);
            return s ? JSON.parse(s) : null;
        } catch (e) { return null; }
    }

    // ── Helpers ─────────────────────────────────────────────────
    function b64ToBytes(b64) {
        var binary = atob(b64);
        var bytes = new Uint8Array(binary.length);
        for (var i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);
        return bytes;
    }
    function pcm16ToFloat32(bytes) {
        var len = bytes.length / 2;
        var out = new Float32Array(len);
        for (var i = 0; i < len; i++) {
            var lo = bytes[i*2], hi = bytes[i*2 + 1];
            var s = (hi << 8) | lo;
            if (s & 0x8000) s -= 0x10000;
            out[i] = s / 32768;
        }
        return out;
    }
    function escHTML(s) {
        s = String(s == null ? '' : s);
        return s.replace(/[&<>"']/g, function (c) {
            return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
        });
    }
    function cssEsc(s) {
        s = String(s == null ? '' : s);
        return s.replace(/[^a-zA-Z0-9_-]/g, '\\$&');
    }
    function showBanner(msg, kind) {
        if (!widget) return;
        var bar = widget.querySelector('.radio-banner');
        if (!bar) {
            bar = document.createElement('div');
            bar.className = 'radio-banner';
            var header = widget.querySelector('.radio-header');
            if (header && header.parentNode) {
                header.parentNode.insertBefore(bar, header.nextSibling);
            } else {
                widget.insertBefore(bar, widget.firstChild);
            }
        }
        bar.innerHTML = '';
        var span = document.createElement('span');
        span.textContent = msg;
        bar.appendChild(span);
        var dismiss = document.createElement('button');
        dismiss.type = 'button';
        dismiss.className = 'radio-banner-dismiss';
        dismiss.setAttribute('aria-label', 'Dismiss');
        dismiss.innerHTML = '<i class="bi bi-x"></i>';
        dismiss.addEventListener('click', clearBanner);
        bar.appendChild(dismiss);
        bar.setAttribute('data-kind', kind || 'info');
    }
    function clearBanner() {
        if (!widget) return;
        var bar = widget.querySelector('.radio-banner');
        if (bar) bar.remove();
    }

    // Phase 85c-fix-11: render call durations next to timestamps.
    // Sub-minute: "12s" (truncated to whole seconds). 1+ min: "M:SS".
    function formatDuration(ms) {
        if (!ms || ms < 0) return '';
        var secs = Math.round(ms / 1000);
        if (secs < 60) return secs + 's';
        var m = Math.floor(secs / 60);
        var s = secs - m * 60;
        return m + ':' + String(s).padStart(2, '0');
    }

    function nowHHMM() {
        var d = new Date();
        var h = String(d.getHours()).padStart(2, '0');
        var m = String(d.getMinutes()).padStart(2, '0');
        var s = String(d.getSeconds()).padStart(2, '0');
        return h + ':' + m + ':' + s;
    }

    // ── Boot ────────────────────────────────────────────────────
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
