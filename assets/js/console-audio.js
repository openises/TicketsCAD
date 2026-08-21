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
 * "audio bus" that would let N independent Zello channels or N DMR
 * talkgroups play simultaneously at independently-controlled levels is
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
            if (meta.adapter === 'zello' || meta.adapter === 'dmr_bm' || meta.adapter === 'dmr_local') {
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
        // channels: [{id, adapter, capabilities}, ...] — called once
        // per console.js refresh with the current registry list so this
        // module knows which adapter family each channel id belongs to.
        registerChannels: function (channels) {
            channelMeta = {};
            for (var i = 0; i < channels.length; i++) {
                var ch = channels[i];
                channelMeta[String(ch.id)] = { adapter: ch.adapter, capabilities: ch.capabilities || {} };
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

        // Hold-to-talk across every simulselect member's adapter family
        // (see the architectural-honesty note above). Returns the set of
        // adapter families actually keyed, for UI feedback.
        simulselectPttStart: function () {
            var members = ConsoleAudio.simulselectMembers();
            var keyed = [];
            var seenZello = false, seenRadio = false;
            for (var i = 0; i < members.length; i++) {
                var meta = channelMeta[String(members[i])];
                if (!meta) { continue; }
                if (meta.adapter === 'zello' && !seenZello) {
                    seenZello = true;
                    if (window.ZelloConsoleAudio && window.ZelloConsoleAudio.ptt) {
                        window.ZelloConsoleAudio.ptt.start();
                        keyed.push('zello');
                    }
                } else if ((meta.adapter === 'dmr_bm' || meta.adapter === 'dmr_local') && !seenRadio) {
                    seenRadio = true;
                    if (window.RadioConsoleAudio && window.RadioConsoleAudio.ptt) {
                        window.RadioConsoleAudio.ptt.start();
                        keyed.push('radio');
                    }
                }
            }
            return keyed;
        },

        simulselectPttStop: function () {
            if (window.ZelloConsoleAudio && window.ZelloConsoleAudio.ptt) { window.ZelloConsoleAudio.ptt.stop(); }
            if (window.RadioConsoleAudio && window.RadioConsoleAudio.ptt) { window.RadioConsoleAudio.ptt.stop(); }
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
