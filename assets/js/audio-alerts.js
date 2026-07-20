/**
 * NewUI v4.0 - Audio Alerts System
 *
 * Generates alert tones via Web Audio API (OscillatorNode) when dispatch
 * events arrive over the EventBus.  No external sound files required.
 *
 * Preferences are stored in localStorage per-browser.
 * Audio context is created lazily on the first user interaction to comply
 * with browser autoplay policies.
 *
 * Global API:
 *   AudioAlerts.mute()        - mute all alerts
 *   AudioAlerts.unmute()       - unmute
 *   AudioAlerts.toggleMute()   - toggle mute state, returns new state
 *   AudioAlerts.isMuted()      - check mute state
 *   AudioAlerts.setVolume(n)   - set master volume 0-100
 *   AudioAlerts.getVolume()    - get current volume 0-100
 *   AudioAlerts.playTone(key)  - play a named tone (for test buttons)
 */
var AudioAlerts = (function () {
    'use strict';

    // ── Preference keys in localStorage ──────────────────────────────
    var STORAGE_KEY = 'ticketsAudioAlerts';

    var defaults = {
        enabled:       true,
        muted:         false,
        volume:        70,    // 0-100
        newIncident:   true,
        highSeverity:  true,
        unitAssigned:  true,
        chatMessage:   true,
        statusChange:  true,
        parOverdue:    true   // Phase 28B (2026-06-12) — global PAR-overdue siren
    };

    var prefs = loadPrefs();
    var audioCtx = null;
    var contextUnlocked = false;

    // ── Tone definitions ─────────────────────────────────────────────
    //  Each entry: { freqs: [[hz, durationMs], ...], gap: ms, type: oscillator type }
    var TONES = {
        newIncident: {
            // 3 ascending beeps
            notes: [
                { hz: 440, dur: 120 },
                { hz: 554, dur: 120 },
                { hz: 659, dur: 180 }
            ],
            gap: 80,
            type: 'sine'
        },
        highSeverity: {
            // rapid alternating tones, 5 times
            notes: [
                { hz: 800, dur: 80 },
                { hz: 600, dur: 80 },
                { hz: 800, dur: 80 },
                { hz: 600, dur: 80 },
                { hz: 800, dur: 80 },
                { hz: 600, dur: 80 },
                { hz: 800, dur: 80 },
                { hz: 600, dur: 80 },
                { hz: 800, dur: 80 },
                { hz: 600, dur: 80 }
            ],
            gap: 30,
            type: 'square'
        },
        unitAssigned: {
            // single short beep
            notes: [
                { hz: 880, dur: 100 }
            ],
            gap: 0,
            type: 'sine'
        },
        chatMessage: {
            // soft double-beep
            notes: [
                { hz: 523, dur: 80 },
                { hz: 523, dur: 80 }
            ],
            gap: 100,
            type: 'triangle'
        },
        statusChange: {
            // low tone
            notes: [
                { hz: 330, dur: 200 }
            ],
            gap: 0,
            type: 'sine'
        },
        parOverdue: {
            // Phase 27 follow-up (2026-06-12) — distinct urgent two-tone
            // siren so an overdue PAR cycle can't be missed even if the
            // dispatcher isn't looking at the screen.
            notes: [
                { hz: 1000, dur: 250 },
                { hz: 700, dur: 250 },
                { hz: 1000, dur: 250 },
                { hz: 700, dur: 250 }
            ],
            gap: 40,
            type: 'square'
        },
        broadcast: {
            // HAS broadcast — long urgent siren, very attention-grabbing
            notes: [
                { hz: 880, dur: 200 },
                { hz: 660, dur: 200 },
                { hz: 880, dur: 200 },
                { hz: 660, dur: 200 },
                { hz: 880, dur: 200 },
                { hz: 660, dur: 200 },
                { hz: 880, dur: 200 },
                { hz: 660, dur: 200 },
                { hz: 880, dur: 300 },
                { hz: 1100, dur: 400 }
            ],
            gap: 40,
            type: 'square'
        }
    };

    // ── Persistence ──────────────────────────────────────────────────

    function loadPrefs() {
        try {
            var raw = localStorage.getItem(STORAGE_KEY);
            if (raw) {
                var saved = JSON.parse(raw);
                var merged = {};
                for (var k in defaults) {
                    if (defaults.hasOwnProperty(k)) {
                        merged[k] = saved.hasOwnProperty(k) ? saved[k] : defaults[k];
                    }
                }
                return merged;
            }
        } catch (e) { /* ignore parse errors */ }
        var copy = {};
        for (var k in defaults) {
            if (defaults.hasOwnProperty(k)) {
                copy[k] = defaults[k];
            }
        }
        return copy;
    }

    function savePrefs() {
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(prefs));
        } catch (e) { /* storage full — ignore */ }
    }

    // ── AudioContext bootstrap ────────────────────────────────────────

    function ensureContext() {
        if (audioCtx && audioCtx.state !== 'closed') return true;
        try {
            var Ctor = window.AudioContext || window.webkitAudioContext;
            if (!Ctor) return false;
            audioCtx = new Ctor();
            return true;
        } catch (e) {
            return false;
        }
    }

    function unlockContext() {
        if (contextUnlocked) return;
        if (!ensureContext()) return;

        // Resume context on first user gesture
        if (audioCtx.state === 'suspended') {
            audioCtx.resume();
        }
        contextUnlocked = true;
    }

    // Listen for the first user interaction to unlock audio
    function attachUnlockListeners() {
        var events = ['click', 'keydown', 'touchstart'];
        function handler() {
            unlockContext();
            for (var i = 0; i < events.length; i++) {
                document.removeEventListener(events[i], handler, true);
            }
        }
        for (var i = 0; i < events.length; i++) {
            document.addEventListener(events[i], handler, true);
        }
    }

    // ── Tone playback ────────────────────────────────────────────────

    function playTone(key) {
        if (!prefs.enabled || prefs.muted) return;
        if (!ensureContext()) return;

        // Resume if suspended (belt-and-suspenders for autoplay policy)
        if (audioCtx.state === 'suspended') {
            audioCtx.resume();
        }

        // Phase 41: per-event override — admin can wire newIncident → some
        // custom-named composed tone via /api/audio-alerts.php?action=assign.
        var resolvedKey = applyOverrideKey(key);
        var tone = TONES[resolvedKey] || TONES[key];
        if (!tone) return;

        var vol = (prefs.volume / 100) * 0.5; // cap at 0.5 to avoid clipping
        var now = audioCtx.currentTime;
        var offset = 0;

        for (var i = 0; i < tone.notes.length; i++) {
            var note = tone.notes[i];
            var startTime = now + offset;
            var durSec = note.dur / 1000;
            var gapSec = tone.gap / 1000;

            scheduleNote(note.hz, startTime, durSec, vol, tone.type);
            offset += durSec + gapSec;
        }
    }

    function scheduleNote(hz, startTime, durSec, vol, waveType) {
        var osc = audioCtx.createOscillator();
        var gain = audioCtx.createGain();

        osc.type = waveType || 'sine';
        osc.frequency.setValueAtTime(hz, startTime);

        // Envelope: quick attack, sustain, quick release to avoid clicks
        gain.gain.setValueAtTime(0, startTime);
        gain.gain.linearRampToValueAtTime(vol, startTime + 0.01);
        gain.gain.setValueAtTime(vol, startTime + durSec - 0.015);
        gain.gain.linearRampToValueAtTime(0, startTime + durSec);

        osc.connect(gain);
        gain.connect(audioCtx.destination);

        osc.start(startTime);
        osc.stop(startTime + durSec + 0.01);
    }

    // ── EventBus integration ─────────────────────────────────────────

    function getCurrentUserId() {
        // USER_ID is set in index.php as a global var
        return (typeof USER_ID !== 'undefined') ? USER_ID : null;
    }

    function isOwnEvent(data) {
        var uid = getCurrentUserId();
        if (!uid) return false;
        // SSE events include user_id of the actor
        if (data && data.user_id && parseInt(data.user_id, 10) === parseInt(uid, 10)) {
            return true;
        }
        return false;
    }

    function subscribeEvents() {
        if (typeof EventBus === 'undefined') return;

        EventBus.on('incident:new', function (data) {
            if (isOwnEvent(data)) return;
            if (!prefs.newIncident) return;

            // Check if high-severity (severity 1 or 2)
            var sev = data && data.severity ? parseInt(data.severity, 10) : 0;
            if (sev > 0 && sev <= 2 && prefs.highSeverity) {
                playTone('highSeverity');
            } else {
                playTone('newIncident');
            }
        });

        EventBus.on('incident:update', function (data) {
            if (isOwnEvent(data)) return;
            // Play high-severity tone if severity changed to 1 or 2
            if (data && data.severity_changed) {
                var sev = parseInt(data.severity, 10);
                if (sev > 0 && sev <= 2 && prefs.highSeverity) {
                    playTone('highSeverity');
                    return;
                }
            }
            // Generic status change
            if (prefs.statusChange) {
                playTone('statusChange');
            }
        });

        EventBus.on('responder:assign', function (data) {
            if (isOwnEvent(data)) return;
            if (!prefs.unitAssigned) return;
            playTone('unitAssigned');
        });

        EventBus.on('chat:message', function (data) {
            if (isOwnEvent(data)) return;
            if (!prefs.chatMessage) return;
            playTone('chatMessage');
        });

        // responder:status also triggers status-change tone
        EventBus.on('responder:status', function (data) {
            if (isOwnEvent(data)) return;
            if (!prefs.statusChange) return;
            playTone('statusChange');
        });

        // Geofence alerts — always play (even from own actions — you need to know)
        EventBus.on('geofence:enter', function (data) {
            playTone('highSeverity'); // Use high-severity alert tone for geofence events
        });
        EventBus.on('geofence:exit', function (data) {
            playTone('statusChange');
        });

        // Weather alerts (Phase 112) — always play; a severe-weather warning
        // matters to everyone on the board and has no originating user.
        EventBus.on('weather:alert', function (data) {
            playTone('highSeverity');
        });

        // HAS broadcast — always plays (even if muted, this is an emergency)
        EventBus.on('message:broadcast', function (data) {
            if (isOwnEvent(data)) return;
            // Force-play broadcast tone regardless of mute state
            if (!ensureContext()) return;
            if (audioCtx.state === 'suspended') audioCtx.resume();
            var tone = TONES.broadcast;
            if (!tone) return;
            var vol = 0.5; // Full volume for emergencies
            var now = audioCtx.currentTime;
            var offset = 0;
            for (var i = 0; i < tone.notes.length; i++) {
                var note = tone.notes[i];
                var startTime = now + offset;
                var durSec = note.dur / 1000;
                var gapSec = tone.gap / 1000;
                scheduleNote(note.hz, startTime, durSec, vol, tone.type);
                offset += durSec + gapSec;
            }
        });

        // New internal message — play chat tone
        EventBus.on('message:new', function (data) {
            if (isOwnEvent(data)) return;
            if (!prefs.chatMessage) return;
            playTone('chatMessage');
        });
    }

    // ── Navbar mute button sync ──────────────────────────────────────

    function syncMuteButton() {
        var btn = document.getElementById('audioMuteBtn');
        if (!btn) return;

        var icon = btn.querySelector('i');
        if (!icon) return;

        if (prefs.muted || !prefs.enabled) {
            icon.className = 'bi bi-volume-mute';
            btn.title = 'Unmute alerts';
            btn.setAttribute('aria-label', 'Unmute alerts');
        } else {
            icon.className = 'bi bi-volume-up';
            btn.title = 'Mute alerts';
            btn.setAttribute('aria-label', 'Mute alerts');
        }
    }

    // ── Phase 41: server-side custom tones + event-key overrides ─────
    //
    // The Sound/Alerts settings panel stores its own composed tones (name +
    // notes + gap + wave type) and a map from built-in event key to the
    // tone that should fire instead. We pull both shortly after boot and
    // merge them into the TONES table so playTone(eventKey) automatically
    // routes through the override when present.

    var eventOverrides = {};

    function applyOverrideKey(key) {
        if (eventOverrides && eventOverrides[key]) {
            return eventOverrides[key];
        }
        return key;
    }

    function loadCustomTones() {
        if (typeof fetch !== 'function') return;
        fetch('api/audio-alerts.php?action=list', { credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) {
                if (!data) return;
                var customs = data.custom_tones || {};
                for (var name in customs) {
                    if (!customs.hasOwnProperty(name)) continue;
                    var t = customs[name];
                    if (!t || !t.notes || !t.notes.length) continue;
                    TONES[name] = {
                        notes: t.notes.slice(),
                        gap: t.gap || 40,
                        type: t.type || 'sine'
                    };
                }
                eventOverrides = data.event_overrides || {};
            })
            .catch(function () {});
    }

    // ── Init ─────────────────────────────────────────────────────────

    function init() {
        attachUnlockListeners();
        subscribeEvents();
        loadCustomTones();

        // Sync navbar mute button on load
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function () {
                syncMuteButton();
                bindMuteButton();
            });
        } else {
            syncMuteButton();
            bindMuteButton();
        }
    }

    function bindMuteButton() {
        var btn = document.getElementById('audioMuteBtn');
        if (!btn) return;
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            api.toggleMute();
        });
    }

    // ── Public API ───────────────────────────────────────────────────

    var api = {
        mute: function () {
            prefs.muted = true;
            savePrefs();
            syncMuteButton();
        },
        unmute: function () {
            prefs.muted = false;
            savePrefs();
            syncMuteButton();
        },
        toggleMute: function () {
            prefs.muted = !prefs.muted;
            savePrefs();
            syncMuteButton();
            return prefs.muted;
        },
        isMuted: function () {
            return prefs.muted;
        },
        setVolume: function (v) {
            prefs.volume = Math.max(0, Math.min(100, parseInt(v, 10) || 0));
            savePrefs();
        },
        getVolume: function () {
            return prefs.volume;
        },
        /**
         * Get/set individual preferences.
         * Keys: enabled, muted, volume, newIncident, highSeverity,
         *       unitAssigned, chatMessage, statusChange
         */
        getPref: function (key) {
            return prefs.hasOwnProperty(key) ? prefs[key] : undefined;
        },
        setPref: function (key, value) {
            if (defaults.hasOwnProperty(key)) {
                prefs[key] = value;
                savePrefs();
                if (key === 'enabled' || key === 'muted') {
                    syncMuteButton();
                }
            }
        },
        /**
         * Bulk-set preferences and save.
         */
        setPrefs: function (obj) {
            for (var k in obj) {
                if (obj.hasOwnProperty(k) && defaults.hasOwnProperty(k)) {
                    prefs[k] = obj[k];
                }
            }
            savePrefs();
            syncMuteButton();
        },
        /**
         * Get all current prefs (returns a copy).
         */
        getPrefs: function () {
            var copy = {};
            for (var k in prefs) {
                if (prefs.hasOwnProperty(k)) {
                    copy[k] = prefs[k];
                }
            }
            return copy;
        },
        /**
         * Play a named tone (for test buttons in settings).
         * Forces play even if muted.
         */
        playTone: function (key) {
            if (!ensureContext()) return;
            if (audioCtx.state === 'suspended') {
                audioCtx.resume();
            }
            var tone = TONES[key];
            if (!tone) return;

            var vol = (prefs.volume / 100) * 0.5;
            var now = audioCtx.currentTime;
            var offset = 0;

            for (var i = 0; i < tone.notes.length; i++) {
                var note = tone.notes[i];
                var startTime = now + offset;
                var durSec = note.dur / 1000;
                var gapSec = tone.gap / 1000;

                scheduleNote(note.hz, startTime, durSec, vol, tone.type);
                offset += durSec + gapSec;
            }
        },
        /**
         * Phase 41 — preview an arbitrary unsaved tone (composer button).
         * Bypasses prefs/mute so the admin always hears the result of
         * what they just typed in.
         */
        previewNotes: function (notes, gap, waveType) {
            if (!ensureContext()) return;
            if (audioCtx.state === 'suspended') audioCtx.resume();
            if (!notes || !notes.length) return;
            var vol = ((prefs.volume || 70) / 100) * 0.5;
            var now = audioCtx.currentTime;
            var offset = 0;
            var gapSec = (gap || 0) / 1000;
            var type = waveType || 'sine';
            for (var i = 0; i < notes.length; i++) {
                var n = notes[i];
                var hz = parseFloat(n.hz);
                var ms = parseFloat(n.dur);
                if (!(hz > 0) || !(ms > 0)) continue;
                var durSec = ms / 1000;
                scheduleNote(hz, now + offset, durSec, vol, type);
                offset += durSec + gapSec;
            }
        },
        /**
         * Re-fetch custom tones + event overrides from the server.
         * Called by the Sound/Alerts composer after a save so the new
         * tone is playable across tabs without a full reload.
         */
        reloadCustomTones: function () {
            loadCustomTones();
        },
        /**
         * Snapshot the merged TONES table (built-ins + custom) so the
         * composer UI can list everything the admin can assign.
         */
        getAllToneNames: function () {
            var names = [];
            for (var k in TONES) { if (TONES.hasOwnProperty(k)) names.push(k); }
            return names;
        }
    };

    init();

    return api;
})();
