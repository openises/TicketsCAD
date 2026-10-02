/**
 * NewUI v4.0 — Console rebuild: acoustic proximity auto-discovery
 * (Phase 152, plan.md section 3.5's own sub-section, Eric's idea).
 *
 * TONE PLAN (the pure math below is deliberately DOM/WebAudio-free so it
 * can be driven directly under Node, matching this project's established
 * pattern for console-audio-logic.js — see tests/test_console_audio_
 * state.php and this feature's own tests/test_phase152_beacon_
 * discovery.php):
 *
 *   - 8 near-ultrasonic frequencies (FREQS below), evenly spaced across
 *     18.0-20.0 kHz. Reproducible by ordinary consumer speakers,
 *     capturable by ordinary mics, and at or beyond the upper edge of
 *     most adult hearing (which rolls off well below 20 kHz with age) —
 *     the implementable version of "a tone the room doesn't really
 *     notice" (true sub-audible/infrasonic doesn't work: consumer
 *     speakers can't reproduce it and browser audio pipelines roll it
 *     off long before getUserMedia would ever see it).
 *   - Each workstation's beacon_code (a small integer in [0,27], assigned
 *     server-side — inc/console-workstations.php) maps to ONE unique pair
 *     of those 8 frequencies (2-of-8, the same *shape* as DTMF's 2-of-8
 *     matrix, shifted up in frequency) — C(8,2) = 28 unique codes, ample
 *     for any realistic room. codeToPair()/pairToCode() are the two
 *     directions of that mapping, using the standard combinatorial
 *     enumeration (i<j, i outer loop, j inner loop) so both directions
 *     always agree with each other and with the server's own identical
 *     assignment order.
 *
 * MECHANISM: an initiator calls api/console-workstation-search.php's
 * start action, which broadcasts comm:beacon_request over SSE
 * (Prerequisite 7's plumbing, already registered as an entitled comm:%
 * prefix). Every OTHER connected console session's copy of this file
 * hears that event, waits a small random stagger (reduces, but does not
 * eliminate, the chance of two responses landing in the exact same FFT
 * analysis frame — a disclosed limitation, not hidden: this whole feature
 * is "best-effort convenience, not a guaranteed result," per plan.md's
 * own words), and plays its own workstation's 2-tone code once. The
 * initiator's own browser runs an AnalyserNode FFT for the whole search
 * window, decodes whichever pairs it detects back into codes, and POSTs
 * the resulting integer codes (never raw audio) to the search endpoint's
 * report action, which resolves them to real workstations server-side
 * and creates the mute pairing in BOTH directions automatically.
 *
 * ES5 IIFE — no arrow functions, no let/const, no template literals.
 */
(function () {
    'use strict';

    // ── Pure tone-plan math (Node-testable, no DOM/WebAudio referenced
    //    anywhere below this point until the "browser glue" section) ────
    var FREQS = [18000, 18286, 18571, 18857, 19143, 19429, 19714, 20000]; // Hz
    var CODE_COUNT = 28; // C(8,2)

    function codeToPair(code) {
        var idx = 0;
        for (var i = 0; i < FREQS.length; i++) {
            for (var j = i + 1; j < FREQS.length; j++) {
                if (idx === code) { return [i, j]; }
                idx++;
            }
        }
        return null;
    }

    function pairToCode(i, j) {
        if (i === j) { return -1; }
        if (i > j) { var tmp = i; i = j; j = tmp; }
        var idx = 0;
        for (var a = 0; a < FREQS.length; a++) {
            for (var b = a + 1; b < FREQS.length; b++) {
                if (a === i && b === j) { return idx; }
                idx++;
            }
        }
        return -1;
    }

    // Nearest FFT bin index for a given frequency, sampleRate and fftSize
    // (standard DFT bin-width formula: sampleRate / fftSize Hz per bin).
    function freqToBin(freq, sampleRate, fftSize) {
        return Math.round(freq / (sampleRate / fftSize));
    }

    window.ConsoleBeacon = {
        FREQS: FREQS,
        CODE_COUNT: CODE_COUNT,
        codeToPair: codeToPair,
        pairToCode: pairToCode,
        freqToBin: freqToBin,
    };

    // ── Browser glue (WebAudio + SSE + getUserMedia) ───────────────────
    var DETECT_THRESHOLD = 140; // getByteFrequencyData() range is 0-255
    var TONE_DURATION_MS = 700;
    var SEARCH_WINDOW_MS = 4000;
    var MAX_RESPONSE_STAGGER_MS = 500;
    var POLL_INTERVAL_MS = 100;
    var FFT_SIZE = 4096;

    var toneCtx = null;
    var myBeaconCode = null;

    function playCode(code) {
        var pair = codeToPair(code);
        if (!pair) { return; }
        try {
            if (!toneCtx) { toneCtx = new (window.AudioContext || window.webkitAudioContext)(); }
            var gain = toneCtx.createGain();
            gain.gain.value = 0.15;
            gain.connect(toneCtx.destination);
            var stopAt = toneCtx.currentTime + TONE_DURATION_MS / 1000;
            [pair[0], pair[1]].forEach(function (idx) {
                var osc = toneCtx.createOscillator();
                osc.frequency.value = FREQS[idx];
                osc.connect(gain);
                osc.start();
                osc.stop(stopAt);
            });
        } catch (e) {
            // WebAudio unavailable or blocked -- this workstation simply
            // won't be discoverable via the beacon; manual entry still
            // works regardless.
        }
    }

    // Runs the initiator's own detection window and reports what it heard.
    // onDone(codesArray) always fires, even on total failure (empty array)
    // -- callers must not assume getUserMedia succeeded.
    function startDetection(onDone) {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) { onDone([]); return; }
        navigator.mediaDevices.getUserMedia({
            audio: { echoCancellation: false, noiseSuppression: false, autoGainControl: false },
        }).then(function (stream) {
            var ctx = new (window.AudioContext || window.webkitAudioContext)();
            var src = ctx.createMediaStreamSource(stream);
            var analyser = ctx.createAnalyser();
            analyser.fftSize = FFT_SIZE;
            src.connect(analyser);
            var data = new Uint8Array(analyser.frequencyBinCount);
            var bins = FREQS.map(function (f) { return freqToBin(f, ctx.sampleRate, FFT_SIZE); });
            var detected = {};

            var pollTimer = window.setInterval(function () {
                analyser.getByteFrequencyData(data);
                var loud = [];
                for (var i = 0; i < bins.length; i++) {
                    if (data[bins[i]] >= DETECT_THRESHOLD) { loud.push(i); }
                }
                for (var a = 0; a < loud.length; a++) {
                    for (var b = a + 1; b < loud.length; b++) {
                        var code = pairToCode(loud[a], loud[b]);
                        if (code !== -1) { detected[code] = true; }
                    }
                }
            }, POLL_INTERVAL_MS);

            window.setTimeout(function () {
                window.clearInterval(pollTimer);
                stream.getTracks().forEach(function (t) { t.stop(); });
                try { ctx.close(); } catch (e) { /* already closing */ }
                var codes = [];
                for (var k in detected) {
                    if (Object.prototype.hasOwnProperty.call(detected, k)) { codes.push(parseInt(k, 10)); }
                }
                onDone(codes);
            }, SEARCH_WINDOW_MS);
        }).catch(function () { onDone([]); });
    }

    // Every OTHER workstation's copy of this file answers a search by
    // playing its own tone -- never the initiator's own copy (compared by
    // token, not by any server-side exclusion, since a PHP session has no
    // notion of which browser tab it's attached to).
    function waitForEventBus(triesLeft) {
        if (window.EventBus) {
            window.EventBus.on('comm:beacon_request', function (data) {
                var myToken = window.ConsoleWorkstation ? window.ConsoleWorkstation.getToken() : null;
                if (!myToken || !data || data.initiator_workstation_token === myToken) { return; }
                if (myBeaconCode === null) { return; } // not yet resolved server-side
                window.setTimeout(function () { playCode(myBeaconCode); }, Math.random() * MAX_RESPONSE_STAGGER_MS);
            });
            return;
        }
        if (triesLeft <= 0) { return; }
        window.setTimeout(function () { waitForEventBus(triesLeft - 1); }, 100);
    }
    waitForEventBus(50);

    window.ConsoleBeacon.playCode = playCode;
    window.ConsoleBeacon.startDetection = startDetection;
    // console-workstation-panel.js calls this once it knows the server's
    // resolved beacon_code for this workstation (from api/console-
    // workstation-mutes.php's GET response) -- this file never resolves
    // its own code independently, so the two always agree.
    window.ConsoleBeacon.setMyBeaconCode = function (code) { myBeaconCode = code; };
})();
