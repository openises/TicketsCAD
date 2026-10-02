/**
 * NewUI v4.0 — Console rebuild: browser-leg session + mic capture
 * (Phase 152, "wire real per-strip PTT/Monitor/Mute/Volume to the browser
 * leg" task)
 *
 * Owns the ONE WebSocket connection a console session has to the audio-
 * matrix's browser leg (services/audio-matrix/legs/browser.py) — a
 * dispatcher's own mic+speaker becomes exactly one matrix channel,
 * "browser:<console_sessions.id>", for the life of this tab. Per-strip
 * "independent audio" comes from being able to have MULTIPLE routes
 * to/from that ONE channel at once (browser<->DMR-TG1 AND browser<->
 * DMR-TG2 simultaneously) — NOT from one WS per strip. api/console-
 * patch.php is what creates/removes those routes; this file owns the
 * connection those routes actually carry audio over.
 *
 * SCOPE (5-persona review #2, specs/phase-152-comms-console-v2/tasks.md):
 * only channels the Python audio-matrix can actually reach today — DMR
 * (dmr_bm/dmr_local). Zello has NO leg in the matrix and stays a launcher
 * strip; bridging it is a deliberately separate, unscoped future phase.
 * MATRIX_ADAPTERS below is the single place that list lives.
 *
 * intercom_dd (the dispatcher-to-dispatcher voice channel, added
 * 2026-09-06) is ALSO in MATRIX_ADAPTERS, unlike Zello/DMR's "opt-in
 * toggle replacing a legacy widget" framing above -- it has no legacy
 * widget at all (there is nothing else it could play through), so it
 * rides this SAME browser leg by construction, per spec.md's own "rides
 * the browser leg like any other channel, no new transport" framing.
 *
 * Mic downsampler: the EXACT AudioWorklet from assets/js/radio-widget.js's
 * startWsMicPump() (windowed-sinc FIR, 48kHz-ish -> 8kHz mono s16le, one
 * 320-byte/160-sample/20ms frame per postMessage) — matching browser.py's
 * wire contract byte-for-byte (frame.py's BYTES_PER_FRAME). Reused
 * verbatim rather than re-derived, per this project's own browser-audio-
 * to-voice-service skill: the frame-size/timing pitfalls in that skill
 * were already solved once for DMR: do not re-invent them here.
 *
 * ES5 IIFE — no arrow functions, no let/const, no template literals.
 */
(function () {
    'use strict';

    var SESSION_API = 'api/console-session.php';
    var PATCH_API = 'api/console-patch.php';
    var RECONNECT_DELAY_MS = 5000;

    // Only adapters the audio-matrix has a real leg for today. Zello is
    // deliberately absent — see the file docblock. intercom_dd is present
    // for the opposite reason: it has NO other leg to fall back to.
    var MATRIX_ADAPTERS = { dmr_bm: true, dmr_local: true, intercom_dd: true };

    var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

    var sessionToken = null;
    var wsUrl = null;
    var ws = null;
    var wsAuthed = false;
    var micCtx = null;
    var micStream = null;
    var micWorklet = null;
    var connecting = false;
    var everConnected = false;      // true once we've had a working connection at least once
    var pendingConnectCallbacks = []; // fn(ok) -- fired once by the NEXT connect state transition
    var txStateSubscribers = [];    // fn(channelKeyOrId, 'started'|'ended')
    var connStateSubscribers = [];  // fn('connected'|'disconnected'|'failed')
    var audioFrameSubscribers = []; // fn(ArrayBuffer) -- one 320-byte 20ms frame

    function notifyConnState(state) {
        for (var i = 0; i < connStateSubscribers.length; i++) {
            try { connStateSubscribers[i](state); } catch (e) { /* one bad subscriber */ }
        }
        // One-shot callers of connect(cb) get resolved by whatever the NEXT
        // transition actually is — 'connected' resolves true, anything else
        // (failed/disconnected) resolves false. Drained, not peeked, so a
        // callback never fires twice.
        if (pendingConnectCallbacks.length) {
            var cbs = pendingConnectCallbacks;
            pendingConnectCallbacks = [];
            var ok = (state === 'connected');
            for (var j = 0; j < cbs.length; j++) {
                try { cbs[j](ok); } catch (e) { /* one bad callback */ }
            }
        }
    }
    function notifyTxState(chanId, tx) {
        for (var i = 0; i < txStateSubscribers.length; i++) {
            try { txStateSubscribers[i](chanId, tx); } catch (e) { /* one bad subscriber */ }
        }
    }
    function notifyAudioFrame(frame) {
        for (var i = 0; i < audioFrameSubscribers.length; i++) {
            try { audioFrameSubscribers[i](frame); } catch (e) { /* one bad subscriber */ }
        }
    }

    function isMatrixBacked(adapter) {
        return !!MATRIX_ADAPTERS[adapter];
    }

    function patchPost(payload, cb) {
        payload.csrf_token = csrf;
        payload.session_token = sessionToken;
        fetch(PATCH_API, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        }).then(function (r) { return r.json().then(function (j) { return { status: r.status, json: j }; }); })
            .then(function (res) { if (cb) { cb(res.json && res.json.ok, res.json); } })
            .catch(function () { if (cb) { cb(false, { error: 'network error' }); } });
    }

    // ── Mic capture (verbatim downsampler from radio-widget.js) ───────
    function startMicWorklet(stream) {
        var workletCode =
            "class MicDownsampler extends AudioWorkletProcessor {" +
            "  constructor(opts) { super();" +
            "    this.targetRate = (opts && opts.processorOptions && opts.processorOptions.targetRate) || 8000;" +
            "    this.ratio = sampleRate / this.targetRate;" +
            "    var nTaps = 31;" +
            "    var fc = (this.targetRate * 0.45) / sampleRate;" +
            "    var taps = new Float32Array(nTaps);" +
            "    var center = (nTaps - 1) / 2;" +
            "    var sum = 0;" +
            "    for (var k = 0; k < nTaps; k++) {" +
            "      var n = k - center;" +
            "      var sinc = (n === 0) ? (2 * fc) : Math.sin(2 * Math.PI * fc * n) / (Math.PI * n);" +
            "      var win = 0.42 - 0.5 * Math.cos(2 * Math.PI * k / (nTaps - 1)) + 0.08 * Math.cos(4 * Math.PI * k / (nTaps - 1));" +
            "      taps[k] = sinc * win;" +
            "      sum += taps[k];" +
            "    }" +
            "    for (var k = 0; k < nTaps; k++) taps[k] /= sum;" +
            "    this.taps = taps;" +
            "    this.nTaps = nTaps;" +
            "    this.buf = new Float32Array(nTaps);" +
            "    this.bufIdx = 0;" +
            "    this.frac = 0;" +
            "    this.FRAME_SAMPLES = 160;" +
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
            "      buf[this.bufIdx] = ch[i];" +
            "      this.bufIdx = (this.bufIdx + 1) % n;" +
            "      this.frac += 1;" +
            "      if (this.frac >= this.ratio) {" +
            "        this.frac -= this.ratio;" +
            "        acc = 0;" +
            "        idx = this.bufIdx;" +
            "        for (k = 0; k < n; k++) {" +
            "          acc += taps[k] * buf[idx];" +
            "          idx = (idx + 1) % n;" +
            "        }" +
            "        var s = Math.max(-1, Math.min(1, acc));" +
            "        outBuf[this.outLen++] = s < 0 ? s * 0x8000 : s * 0x7FFF;" +
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

        micCtx = new (window.AudioContext || window.webkitAudioContext)();
        return micCtx.audioWorklet.addModule(url).then(function () {
            micWorklet = new AudioWorkletNode(micCtx, 'mic-downsampler', {
                processorOptions: { targetRate: 8000 },
            });
            var src = micCtx.createMediaStreamSource(stream);
            var sink = micCtx.createGain();
            sink.gain.value = 0; // never echo the mic to this tab's own speakers
            src.connect(micWorklet);
            micWorklet.connect(sink);
            sink.connect(micCtx.destination);
            micWorklet.port.onmessage = function (e) {
                if (ws && ws.readyState === WebSocket.OPEN && wsAuthed) {
                    ws.send(e.data);
                }
            };
        });
    }

    function stopMicCapture() {
        if (micStream) {
            var tracks = micStream.getTracks();
            for (var i = 0; i < tracks.length; i++) { tracks[i].stop(); }
            micStream = null;
        }
        if (micCtx) { try { micCtx.close(); } catch (e) {} micCtx = null; }
        micWorklet = null;
    }

    // ── Session + WebSocket lifecycle ─────────────────────────────────
    function mintSession(then) {
        // Phase 152 workstation identity -- passed along so api/console-
        // session.php can register/touch this desk's console_workstations
        // row (best-effort on the server side; window.ConsoleWorkstation
        // itself is optional here too, since an install that hasn't
        // deployed console-workstation.js must not break session minting).
        var body = { action: 'create', csrf_token: csrf };
        if (window.ConsoleWorkstation && typeof window.ConsoleWorkstation.getToken === 'function') {
            body.workstation_token = window.ConsoleWorkstation.getToken();
        }
        fetch(SESSION_API, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        }).then(function (r) { return r.json(); }).then(function (j) {
            if (j && j.ok && j.session_token) {
                sessionToken = j.session_token;
                wsUrl = j.ws_url || null;
            }
            then();
        }).catch(function () { then(); });
    }

    function openWs() {
        if (!wsUrl) { notifyConnState('failed'); return; }
        try { ws = new WebSocket(wsUrl); } catch (e) { notifyConnState('failed'); return; }
        ws.binaryType = 'arraybuffer';
        ws.addEventListener('open', function () {
            ws.send(JSON.stringify({ session_token: sessionToken }));
        });
        ws.addEventListener('message', function (e) {
            if (typeof e.data !== 'string') {
                // A binary message is one 320-byte 20ms audio frame — hand
                // it to console-playback.js's subscriber(s). Never handled
                // here directly: this file owns the CONNECTION, not what
                // happens to received audio.
                notifyAudioFrame(e.data);
                return;
            }
            var msg;
            try { msg = JSON.parse(e.data); } catch (err) { return; }
            if (msg.type === 'handshake_ok') {
                wsAuthed = true;
                everConnected = true;
                connecting = false;
                notifyConnState('connected');
            } else if (msg.type === 'tx_started' || msg.type === 'tx_ended') {
                notifyTxState(null, msg.type === 'tx_started' ? 'started' : 'ended');
            } else if (msg.type === 'error') {
                console.warn('[console-mic] server error:', msg.message);
            }
        });
        ws.addEventListener('close', function () {
            var wasAuthed = wsAuthed;
            ws = null;
            wsAuthed = false;
            stopMicCapture();
            connecting = false;
            // Fail-loud disconnect (5-persona review #1, prerequisite #7):
            // a persistent alarm, not a quiet gray strip — this is what
            // console.js's UI hooks into, never a silent retry-and-hope.
            notifyConnState(wasAuthed ? 'disconnected' : 'failed');
            setTimeout(function () {
                if (!connecting && !ws) { connect(); }
            }, RECONNECT_DELAY_MS);
        });
        ws.addEventListener('error', function () { /* close fires too; nothing extra to do */ });
    }

    // cb(ok), optional: fires once this specific call's outcome is known
    // (true = authed and ready to listen()/talkStart(), false = failed).
    // Safe to call while already connected (resolves immediately) or
    // already connecting (queues onto the in-flight attempt) — callers
    // never need to check isConnected() first.
    function connect(cb) {
        if (ws && wsAuthed) { if (cb) { cb(true); } return; }
        if (cb) { pendingConnectCallbacks.push(cb); }
        if (connecting) { return; }
        connecting = true;
        mintSession(function () {
            if (!sessionToken || !wsUrl) {
                connecting = false;
                // Fail soft (a not-yet-deployed matrix service is the
                // correct, common default -- see console.js's own silent
                // handling of 'failed'), but say WHY on the console rather
                // than leaving a checkbox that just un-checks itself with
                // no explanation -- a solo-sysadmin persona review (2026-
                // 09-08) found this was the single biggest reason
                // "Matrix Audio"/"Join Intercom" could look completely
                // broken with no diagnosable cause anywhere. See docs/
                // AUDIO-MATRIX-SETUP.md's "Expose the browser leg" step.
                if (!sessionToken) {
                    console.warn('[console-mic] could not open a console session (screen.console permission, or api/console-session.php unreachable) -- Matrix Audio/Join Intercom will not connect.');
                } else {
                    console.warn('[console-mic] matrix_ws_url is not configured on this install -- Matrix Audio/Join Intercom cannot connect until an admin sets it. See docs/AUDIO-MATRIX-SETUP.md, "Expose the browser leg".');
                }
                notifyConnState('failed');
                return;
            }
            navigator.mediaDevices.getUserMedia({ audio: true }).then(function (stream) {
                micStream = stream;
                openWs();
                startMicWorklet(stream).catch(function (e) {
                    console.warn('[console-mic] mic worklet failed to start:', e);
                });
            }).catch(function (e) {
                connecting = false;
                console.warn('[console-mic] getUserMedia failed:', e);
                notifyConnState('failed');
            });
        });
    }

    // ── Public API ──────────────────────────────────────────────────
    window.ConsoleMatrix = {
        isMatrixBacked: isMatrixBacked,
        isConnected: function () { return !!(ws && wsAuthed); },
        everConnected: function () { return everConnected; },
        connect: connect,
        // Phase 152 thin position layer (console-positions.js) piggybacks
        // its heartbeat on this SAME console-session token when one
        // exists, rather than minting a second one -- but positions must
        // keep working on an install with no matrix service deployed at
        // all, where this never becomes non-null, so callers treat null
        // as "no token to attach, heartbeat anyway" rather than an error.
        getSessionToken: function () { return sessionToken; },

        listen: function (channelId, gainDb, cb) {
            patchPost({ action: 'connect', channel_id: channelId, direction: 'listen', gain_db: gainDb }, cb);
        },
        unlisten: function (channelId, cb) {
            patchPost({ action: 'disconnect', channel_id: channelId, direction: 'listen' }, cb);
        },
        setListenGain: function (channelId, gainDb, cb) {
            patchPost({ action: 'set_gain', channel_id: channelId, gain_db: gainDb }, cb);
        },
        talkStart: function (channelId, cb) {
            patchPost({ action: 'connect', channel_id: channelId, direction: 'talk' }, cb);
        },
        talkEnd: function (channelId, cb) {
            patchPost({ action: 'disconnect', channel_id: channelId, direction: 'talk' }, cb);
        },

        subscribeTxState: function (fn) { txStateSubscribers.push(fn); },
        subscribeConnState: function (fn) { connStateSubscribers.push(fn); },
        // console-playback.js subscribes here instead of opening a second
        // WS connection — this file owns the one real socket.
        subscribeAudioFrame: function (fn) { audioFrameSubscribers.push(fn); },
    };
})();
