/**
 * NewUI v4.0 — Console select/monitor/mute/volume/simulselect DOM/network
 * glue (Phase 114b3). Wraps the pure state machine in console-audio-
 * logic.js: persists per-user state via api/console-audio-prefs.php,
 * applies the computed effective gain to the REAL zello-widget.js / radio-
 * widget.js audio output (window.ZelloConsoleAudio / window.RadioConsoleAudio
 * — see those files' own "Console audio hook" sections), and drives the
 * simulselect master-PTT button (window.ZelloConsoleAudio.ptt /
 * window.RadioConsoleAudio.ptt).
 *
 * Architectural honesty (documented here because it shapes what this file
 * can and can't do): zello-widget.js and radio-widget.js are each a
 * SINGLE global widget instance, not one instance per channel — the
 * "audio bus" that would allow N independent Zello channels or N DMR
 * talkgroups to play simultaneously at independently-controlled levels is
 * explicitly future work (Phase 114c, per console-designer.md's own
 * delivery-slices section). Given that constraint:
 *   - Select/monitor/mute/volume for a zello or dmr_bm/dmr_local strip
 *     control THE WIDGET'S real output (a real <audio>.volume scale for
 *     Zello, a real sample-gain scale for DMR) — genuine, not simulated.
 *     When more than one channel of the SAME adapter family exists, the
 *     effective gain applied is whichever such channel most recently
 *     changed state (the widget itself can only be tuned to one channel
 *     at a time regardless); this is a real, disclosed limitation, not a
 *     silent one.
 *   - Simulselect PTT keys BOTH widgets' real PTT (Zello startTransmit()/
 *     stopTransmit(), Radio pttStart()/pttEnd()) simultaneously when both
 *     adapter families have a simulselect member — a genuine "page out
 *     over radio AND Zello at once," which is the actual paging/
 *     announcement use case console-designer.md names. True multi-
 *     channel-within-one-adapter simulselect (e.g. two independent Zello
 *     channels at once) needs the 114c audio bus.
 *   - Non-audio (text) channels never touch either widget hook — see
 *     console-audio-logic.js's textProminence().
 *
 * ES5 IIFE — no arrow functions, no let/const, no template literals.
 */
(function () {
    'use strict';

    var API = 'api/console-audio-prefs.php';
    var SAVE_DEBOUNCE_MS = 500;

    var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
    var Logic = window.ConsoleAudioLogic;
    if (!Logic) { return; } // console-audio-logic.js must load first

    var state = { channels: {} };     // channel_id (string) -> {selected,muted,volume,simulselect}
    var channelMeta = {};             // channel_id (string) -> {adapter, capabilities}
    var subscribers = [];
    var saveTimer = null;
    var loaded = false;

    function notify() {
        for (var i = 0; i < subscribers.length; i++) {
            try { subscribers[i](state); } catch (e) { /* one bad subscriber shouldn't break the rest */ }
        }
    }

    function scheduleSave() {
        if (saveTimer) { clearTimeout(saveTimer); }
        saveTimer = setTimeout(function () {
            saveTimer = null;
            fetch(API, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ channels: state.channels, csrf_token: csrf })
            }).catch(function () { /* best effort — state still applied locally */ });
        }, SAVE_DEBOUNCE_MS);
    }

    // Linear 0..1 gain (Logic.effectiveGain()'s own scale) -> the dB value
    // api/console-patch.php's set_gain expects (matrix_normalize_gain()'s
    // range, inc/matrix-routes.php). 0 linear gain has no finite dB
    // equivalent (silence) — floored at the same -60 dB the server itself
    // clamps to, which is effectively inaudible (~0.1% amplitude), rather
    // than -Infinity.
    function linearGainToDb(gain) {
        if (gain <= 0.001) { return -60; }
        var db = 20 * Math.log10(gain);
        return Math.max(-60, Math.min(20, db));
    }

    // ── Apply effective gain to the real widget audio hooks ───────────
    function applyAudio() {
        var anySel = Logic.anySelected(state.channels);
        // Track, per adapter family, the gain of whichever channel of
        // that family most recently had its state touched (see the
        // architectural-honesty note above) — approximated here as "the
        // LAST channel of that adapter iterated with a non-default
        // state", which in practice is exactly right for the common case
        // of one Zello channel + one DMR channel on the board.
        var byAdapter = {}; // adapter -> {gain, muted, id}
        for (var id in state.channels) {
            if (!Object.prototype.hasOwnProperty.call(state.channels, id)) { continue; }
            var meta = channelMeta[id];
            if (!meta) { continue; }
            var s = state.channels[id];
            var gain = Logic.effectiveGain(s, anySel);
            var touched = s.selected || s.muted || s.volume !== 100;
            var isRadio = (meta.adapter === 'dmr_bm' || meta.adapter === 'dmr_local');
            // intercom_dd (added 2026-09-06) is matrix-capable like DMR but
            // has no legacy widget to be mutually exclusive WITH — it's
            // still gated on the SAME opt-in matrixAudio toggle (joining
            // the party line is a deliberate operator action, not
            // automatic on page load — the mic-permission prompt console-
            // mic.js's connect() triggers must only ever follow a genuine
            // user gesture), just with no singleton family for it below.
            var isMatrixCapable = isRadio || meta.adapter === 'intercom_dd';
            // Phase 152 — a channel with matrixAudio engaged gets its OWN
            // independent gain via the browser-leg route (below) and must
            // be EXCLUDED from the singleton radio-widget computation —
            // applying both would double-drive the same underlying DMR
            // bridge audio (services/audio-matrix/legs/dmr.py and
            // radio-widget.js both ultimately read/write the same bridge
            // seams; see console-mic.js's docblock). This is the one hard
            // rule that makes engaging Matrix Audio on a strip safe.
            if (isMatrixCapable && s.matrixAudio && window.ConsoleMatrix) {
                if (window.ConsoleMatrix.isConnected()) {
                    window.ConsoleMatrix.setListenGain(id, linearGainToDb(gain));
                }
                continue;
            }
            if (meta.adapter === 'zello' || isRadio) {
                var fam = (meta.adapter === 'zello') ? 'zello' : 'radio';
                if (touched || !byAdapter[fam]) {
                    byAdapter[fam] = { gain: gain, muted: !!s.muted };
                }
            }
        }
        if (byAdapter.zello && window.ZelloConsoleAudio && window.ZelloConsoleAudio.setLevel) {
            window.ZelloConsoleAudio.setLevel(byAdapter.zello.gain, byAdapter.zello.muted);
        }
        if (byAdapter.radio && window.RadioConsoleAudio && window.RadioConsoleAudio.setLevel) {
            window.RadioConsoleAudio.setLevel(byAdapter.radio.gain, byAdapter.radio.muted);
        }
    }

    // ── Public API ──────────────────────────────────────────────────
    var ConsoleAudio = {
        // channels: [{id, adapter, label, capabilities}, ...] — called once
        // per console.js refresh (or the simulselect widget's own boot)
        // with the current registry list so this module knows which
        // adapter family and display label each channel id has. label is
        // used by simulselectTargets()'s "TX target" readout.
        registerChannels: function (channels) {
            channelMeta = {};
            for (var i = 0; i < channels.length; i++) {
                var ch = channels[i];
                channelMeta[String(ch.id)] = { adapter: ch.adapter, label: ch.label || null, capabilities: ch.capabilities || {} };
                if (!state.channels[String(ch.id)]) {
                    state.channels[String(ch.id)] = Logic.defaultState();
                }
            }
            applyAudio();
        },

        getState: function (channelId) {
            return Logic.normalizeState(state.channels[String(channelId)]);
        },

        anySelected: function () { return Logic.anySelected(state.channels); },

        setSelected: function (channelId, val) {
            var id = String(channelId);
            state.channels[id] = Logic.normalizeState(state.channels[id]);
            state.channels[id].selected = !!val;
            applyAudio();
            scheduleSave();
            notify();
        },

        setMon: function (channelId, val) {
            var id = String(channelId);
            state.channels[id] = Logic.normalizeState(state.channels[id]);
            state.channels[id].mon = !!val;
            applyAudio();
            scheduleSave();
            notify();
        },

        setMuted: function (channelId, val) {
            var id = String(channelId);
            state.channels[id] = Logic.normalizeState(state.channels[id]);
            state.channels[id].muted = !!val;
            applyAudio();
            scheduleSave();
            notify();
        },

        setVolume: function (channelId, vol) {
            var id = String(channelId);
            state.channels[id] = Logic.normalizeState(state.channels[id]);
            state.channels[id].volume = Math.max(0, Math.min(100, parseInt(vol, 10) || 0));
            applyAudio();
            scheduleSave();
            notify();
        },

        // Phase 152 — engage/disengage the independent browser-leg audio
        // path for a matrix-backed (DMR, or intercom_dd) channel. Turning ON lazily
        // connects the session (mic permission prompt on first use, never
        // eagerly on page load) then creates the listen route; turning OFF
        // tears the route down. See applyAudio()'s own matrixAudio branch
        // for why this and the singleton radio-widget path are mutually
        // exclusive per channel. cb(ok) is optional, mainly for the UI to
        // revert an optimistic toggle if the connect/route attempt fails.
        setMatrixAudio: function (channelId, val, cb) {
            var id = String(channelId);
            state.channels[id] = Logic.normalizeState(state.channels[id]);
            var wasOn = state.channels[id].matrixAudio;
            state.channels[id].matrixAudio = !!val;
            if (!window.ConsoleMatrix) {
                state.channels[id].matrixAudio = false;
                if (cb) { cb(false); }
                return;
            }
            if (val) {
                window.ConsoleMatrix.connect(function (ok) {
                    if (!ok) {
                        state.channels[id].matrixAudio = false;
                        applyAudio(); notify();
                        if (cb) { cb(false); }
                        return;
                    }
                    var gain = Logic.effectiveGain(state.channels[id], Logic.anySelected(state.channels));
                    window.ConsoleMatrix.listen(id, linearGainToDb(gain), function (listenOk) {
                        if (!listenOk) { state.channels[id].matrixAudio = false; }
                        applyAudio(); scheduleSave(); notify();
                        if (cb) { cb(!!listenOk); }
                    });
                });
            } else if (wasOn) {
                window.ConsoleMatrix.unlisten(id, function (ok) {
                    applyAudio(); scheduleSave(); notify();
                    if (cb) { cb(!!ok); }
                });
            } else {
                applyAudio(); scheduleSave(); notify();
                if (cb) { cb(true); }
            }
        },

        setSimulselect: function (channelId, val) {
            var id = String(channelId);
            state.channels[id] = Logic.normalizeState(state.channels[id]);
            state.channels[id].simulselect = !!val;
            scheduleSave();
            notify();
        },

        textProminence: function (channelId) {
            return Logic.textProminence(state.channels[String(channelId)]);
        },

        simulselectMembers: function () {
            var txCapable = [];
            for (var id in channelMeta) {
                if (Object.prototype.hasOwnProperty.call(channelMeta, id)
                    && channelMeta[id].capabilities && channelMeta[id].capabilities.voice_tx) {
                    txCapable.push(id);
                }
            }
            return Logic.simulselectMembers(state.channels, txCapable);
        },

        // The single source of truth for "what will actually key when the
        // operator presses Group Transmit" -- shared by simulselectPttStart()
        // (which fires it) and simulselectTargets() (which shows it in the
        // readout BEFORE the press). Extracted 2026-09-08 after a SKYWARN-
        // net-control persona review caught the two functions disagreeing:
        // the readout listed every checked channel of the same adapter
        // family (e.g. two checked Zello channels), while simulselectPttStart
        // could only ever key the FIRST one it saw -- because zello-widget.js/
        // radio-widget.js are each a single global widget instance (see the
        // "Architectural honesty" note at the top of this file), a second
        // checked channel of the same family was silently never keyed, with
        // no warning that the readout's own promise ("Will transmit on: X, Y")
        // was already false the moment X and Y share an adapter family. This
        // function is now the ONLY place that decides "which checked channel
        // of each singleton-widget family wins" -- both callers below read
        // from it, so they cannot diverge again the same way.
        //
        // Matrix-backed members (intercom_dd, or a DMR channel with
        // matrixAudio engaged) have no singleton-widget limit -- each keys
        // independently via window.ConsoleMatrix.talkStart(id), so every one
        // of them is included, never deduplicated.
        _simulselectResolved: function () {
            var members = ConsoleAudio.simulselectMembers();
            var resolved = [];
            var seenZello = false, seenRadio = false;
            for (var i = 0; i < members.length; i++) {
                var id = members[i];
                var meta = channelMeta[String(id)];
                if (!meta) { continue; }
                var s = state.channels[String(id)];
                var isMatrixEngaged = meta.adapter === 'intercom_dd'
                    || ((meta.adapter === 'dmr_bm' || meta.adapter === 'dmr_local') && s && s.matrixAudio);
                if (isMatrixEngaged) {
                    resolved.push({ id: id, meta: meta, kind: 'matrix' });
                } else if (meta.adapter === 'zello') {
                    if (seenZello) { continue; }
                    seenZello = true;
                    resolved.push({ id: id, meta: meta, kind: 'zello' });
                } else if (meta.adapter === 'dmr_bm' || meta.adapter === 'dmr_local') {
                    if (seenRadio) { continue; }
                    seenRadio = true;
                    resolved.push({ id: id, meta: meta, kind: 'radio' });
                }
            }
            return resolved;
        },

        // Hold-to-talk across every resolved simulselect member (see
        // _simulselectResolved() above). Returns the set of adapter families
        // actually keyed, for UI feedback.
        //
        // Matrix-backed members (2026-09-08 -- unification plan step 3,
        // specs/phase-152-comms-console-v2/tasks.md) key via window.
        // ConsoleMatrix.talkStart(id) directly, ONE call per member, NOT
        // funneled through the zello/radio singleton-widget path below --
        // intercom_dd has no singleton widget to fall back to at all, and
        // a DMR channel with matrixAudio engaged already has its OWN
        // independent browser-leg route, separate from the singleton
        // radio-widget audio applyAudio() already keeps mutually exclusive
        // with it. This NEVER touches the zello-widget.js/radio-widget.js
        // PTT buttons themselves -- per the 3-persona design review's
        // unanimous verdict (2026-09-08), those stay frozen; this is a
        // second, independent transmit path added alongside them, not a
        // redefinition of what pressing THEIR buttons does.
        simulselectPttStart: function () {
            var resolved = ConsoleAudio._simulselectResolved();
            var keyed = [];
            for (var i = 0; i < resolved.length; i++) {
                var r = resolved[i];
                if (r.kind === 'matrix' && window.ConsoleMatrix) {
                    (function (id) {
                        window.ConsoleMatrix.connect(function (ok) {
                            if (ok) { window.ConsoleMatrix.talkStart(id); }
                        });
                    })(r.id);
                    keyed.push('matrix:' + r.id);
                } else if (r.kind === 'zello' && window.ZelloConsoleAudio && window.ZelloConsoleAudio.ptt) {
                    window.ZelloConsoleAudio.ptt.start();
                    keyed.push('zello');
                } else if (r.kind === 'radio' && window.RadioConsoleAudio && window.RadioConsoleAudio.ptt) {
                    window.RadioConsoleAudio.ptt.start();
                    keyed.push('radio');
                }
            }
            return keyed;
        },

        simulselectPttStop: function () {
            if (window.ZelloConsoleAudio && window.ZelloConsoleAudio.ptt) { window.ZelloConsoleAudio.ptt.stop(); }
            if (window.RadioConsoleAudio && window.RadioConsoleAudio.ptt) { window.RadioConsoleAudio.ptt.stop(); }
            if (window.ConsoleMatrix && window.ConsoleMatrix.isConnected()) {
                var members = ConsoleAudio.simulselectMembers();
                for (var i = 0; i < members.length; i++) {
                    var meta = channelMeta[String(members[i])];
                    if (!meta) { continue; }
                    var s = state.channels[String(members[i])];
                    var isMatrixEngaged = meta.adapter === 'intercom_dd'
                        || ((meta.adapter === 'dmr_bm' || meta.adapter === 'dmr_local') && s && s.matrixAudio);
                    if (isMatrixEngaged) { window.ConsoleMatrix.talkEnd(members[i]); }
                }
            }
        },

        // The ARES/RACES persona's explicit ask (2026-09-08 3-persona
        // review): a visible "TX target" readout naming exactly which
        // channels a PTT press will fire on, right next to the control --
        // never a settings-page checkbox the operator has to go hunt for.
        // Returns [{id, label}] for exactly the members _simulselectResolved()
        // says will actually key -- NOT one entry per checked channel. A
        // second checked channel of the same singleton-widget family (e.g.
        // two checked Zello channels) is real, visible state in the channel
        // list's own checkboxes, but is deliberately OMITTED here rather
        // than listed as a TX target: including it would repeat the exact
        // bug a SKYWARN-net-control persona review caught (the readout
        // promising a transmission that simulselectPttStart() could never
        // actually deliver).
        simulselectTargets: function () {
            var resolved = ConsoleAudio._simulselectResolved();
            var out = [];
            for (var i = 0; i < resolved.length; i++) {
                var meta = resolved[i].meta;
                out.push({ id: resolved[i].id, label: meta ? (meta.label || meta.adapter) : String(resolved[i].id) });
            }
            return out;
        },

        subscribe: function (fn) { subscribers.push(fn); },

        // Load persisted state from the server (once, at boot).
        load: function (then) {
            fetch(API).then(function (r) { return r.json(); }).then(function (j) {
                if (j && j.ok && j.state && j.state.channels) {
                    for (var id in j.state.channels) {
                        if (Object.prototype.hasOwnProperty.call(j.state.channels, id)) {
                            state.channels[id] = Logic.normalizeState(j.state.channels[id]);
                        }
                    }
                }
                loaded = true;
                applyAudio();
                notify();
                if (then) { then(); }
            }).catch(function () { loaded = true; if (then) { then(); } });
        },

        isLoaded: function () { return loaded; }
    };

    window.ConsoleAudio = ConsoleAudio;
})();
