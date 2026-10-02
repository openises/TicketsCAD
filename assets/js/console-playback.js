/**
 * NewUI v4.0 — Console rebuild: browser-leg audio playback (Phase 152)
 *
 * Plays the ONE console session's inbound audio stream — whatever the
 * live matrix has already mixed together for "browser:<session>" from
 * every currently-"listened" channel, at the gain each route was given
 * (server-side, per-route — see api/console-patch.php; this file applies
 * NO further per-channel gain, because by the time a frame reaches here
 * it is already the combined output of possibly several sources).
 *
 * Ring-buffer + AudioWorklet-pull pattern copied from radio-widget.js's
 * ensureAudio()/pullSamples() (proven in production) rather than re-
 * derived — the one difference: the AudioContext is created at 8000 Hz
 * directly (matching frame.py's native rate) so the browser's own audio
 * hardware does the final upsample; there is no scrub/rewind ring here,
 * just a small live buffer, since "replay the last 30 seconds" is not
 * this feature's job.
 *
 * ES5 IIFE — no arrow functions, no let/const, no template literals.
 */
(function () {
    'use strict';

    var SAMPLE_RATE = 8000;
    var RING_SECONDS = 2; // small live buffer -- this is not a scrub/replay feature
    var RING_SAMPLES = SAMPLE_RATE * RING_SECONDS;

    var Matrix = window.ConsoleMatrix;
    if (!Matrix) { return; } // console-mic.js must load first

    var audioCtx = null;
    var workletNode = null;
    var ring = null;
    var writeIndex = 0, playIndex = 0, totalWritten = 0, totalPlayed = 0;
    var muted = false;

    function ensureAudio() {
        if (audioCtx) { return; }
        try {
            var AC = window.AudioContext || window.webkitAudioContext;
            audioCtx = new AC({ sampleRate: SAMPLE_RATE });
        } catch (e) {
            console.error('[console-playback] AudioContext failed:', e);
            return;
        }
        ring = new Float32Array(RING_SAMPLES);

        var workletCode =
            "class ConsolePlaybackOut extends AudioWorkletProcessor {" +
            "  constructor() { super(); var self = this; this.buf = new Float32Array(0); this.port.onmessage = function (e) {" +
            "    if (!e.data || !e.data.length) return;" +
            "    var combined = new Float32Array(self.buf.length + e.data.length);" +
            "    combined.set(self.buf, 0); combined.set(e.data, self.buf.length);" +
            "    if (combined.length > 4000) combined = combined.subarray(combined.length - 4000);" +
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
            "registerProcessor('console-playback-out', ConsolePlaybackOut);";
        var blob = new Blob([workletCode], { type: 'application/javascript' });
        var url = URL.createObjectURL(blob);
        audioCtx.audioWorklet.addModule(url).then(function () {
            workletNode = new AudioWorkletNode(audioCtx, 'console-playback-out');
            workletNode.port.onmessage = function (e) {
                workletNode.port.postMessage(pullSamples(e.data));
            };
            workletNode.connect(audioCtx.destination);
            workletNode.port.postMessage(pullSamples(2048));
        }).catch(function (err) {
            console.warn('[console-playback] worklet load failed:', err);
        });

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

    function pullSamples(n) {
        var out = new Float32Array(n);
        if (muted) { return out; }
        var avail = totalWritten - totalPlayed;
        if (avail <= 0) { return out; }
        var i = 0;
        while (i < n && totalPlayed < totalWritten) {
            out[i++] = ring[playIndex];
            playIndex = (playIndex + 1) % RING_SAMPLES;
            totalPlayed++;
        }
        // If we've fallen behind by more than the whole ring (tab was
        // backgrounded, a GC pause, etc.), snap forward rather than ever
        // trying to "catch up" by playing a growing backlog — always
        // prefer dropping stale audio over unbounded latency.
        if (totalWritten - totalPlayed > RING_SAMPLES) {
            totalPlayed = totalWritten - RING_SAMPLES;
            playIndex = writeIndex;
        }
        return out;
    }

    // One incoming 320-byte s16le frame -> 160 float32 samples into the ring.
    function pushFrame(arrayBuffer) {
        if (!audioCtx) { return; }
        var samples = new Int16Array(arrayBuffer);
        for (var i = 0; i < samples.length; i++) {
            ring[writeIndex] = samples[i] / 32768;
            writeIndex = (writeIndex + 1) % RING_SAMPLES;
            totalWritten++;
        }
    }

    Matrix.subscribeAudioFrame(function (frame) {
        ensureAudio();
        pushFrame(frame);
    });

    window.ConsolePlayback = {
        setMuted: function (v) { muted = !!v; },
    };
})();
