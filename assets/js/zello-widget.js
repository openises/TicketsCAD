/**
 * NewUI v4.0 - Zello Network Radio Widget
 *
 * Floating widget for Zello text messaging (Phase 1 & 2 MVP).
 * Handles WebSocket connection to the PHP proxy, text send/receive,
 * message feed display, drag/resize, and unread badge tracking.
 *
 * ES5 IIFE — no arrow functions, no let/const, no template literals.
 */
(function () {
    'use strict';

    // ── State ────────────────────────────────────────────────────
    var widget = null;        // DOM element
    var feedEl = null;        // Message feed container
    var inputEl = null;       // Text input
    var statusBadge = null;   // Status indicator dot
    var channelLabel = null;  // Current channel display
    var ws = null;            // WebSocket connection (fallback / per-tab mode)
    var visible = false;

    // Zello multi-channel (2026-07-05, spec zello-multichannel-2026-07 Phase 1).
    // The configured channel list from api/zello-token.php. When more than one
    // channel is configured, RX feed items get a small channel badge so a
    // dispatcher monitoring several channels can tell them apart. Single-channel
    // installs stay uncluttered (no badges). Phase 2 adds the channel bank; TX
    // to a non-dispatch channel (Phases 3-4) needs a Zello Work account.
    var configuredChannels = [];
    var dispatchChannel = '';
    function appendChannelBadge(senderDiv, channel) {
        if (!senderDiv || !channel || configuredChannels.length <= 1) return;
        var b = document.createElement('span');
        b.className = 'zello-msg-channel badge bg-secondary';
        b.style.cssText = 'font-size:0.58rem;font-weight:600;margin-left:5px;vertical-align:middle;';
        b.textContent = channel;
        b.title = 'Channel: ' + channel;
        senderDiv.appendChild(b);
    }

    // ── Zello multi-channel Phase 2 (2026-07-05) — channel bank ──────────
    // A row of buttons (one per configured channel) with a per-channel unread
    // counter, so a dispatcher monitoring several channels sees at a glance
    // where traffic is and can focus a channel. Focus is display-only for now
    // (persisted per browser); transmitting to the focused channel is Phase 3
    // (needs a Zello Work account to verify). The feed stays combined and
    // channel-labeled — the bank is a focus + activity indicator, not a filter.
    var CHAN_ACTIVE_KEY = 'newui_zello_active_channel';
    var activeChannel = '';
    var channelUnread = {};       // { channelName: unreadCount }
    var channelBankEl = null;
    function _loadActiveChannel() {
        try { activeChannel = localStorage.getItem(CHAN_ACTIVE_KEY) || ''; } catch (e) { activeChannel = ''; }
    }
    function noteChannelTraffic(channel, direction) {
        if (!channel || configuredChannels.length <= 1) return;
        if (direction === 'outgoing') return;      // don't count our own TX
        if (channel === activeChannel) return;     // the focused channel isn't "unread"
        channelUnread[channel] = (channelUnread[channel] || 0) + 1;
        renderChannelBank();
    }
    function setActiveChannel(ch) {
        activeChannel = ch || '';
        channelUnread[activeChannel] = 0;
        try { localStorage.setItem(CHAN_ACTIVE_KEY, activeChannel); } catch (e) {}
        renderChannelBank();
    }
    function renderChannelBank() {
        if (!widget) return;
        if (!configuredChannels || configuredChannels.length <= 1) {
            if (channelBankEl && channelBankEl.parentNode) {
                channelBankEl.parentNode.removeChild(channelBankEl);
                channelBankEl = null;
            }
            return;
        }
        if (!activeChannel || configuredChannels.indexOf(activeChannel) === -1) {
            activeChannel = dispatchChannel || configuredChannels[0];
        }
        if (!channelBankEl) {
            channelBankEl = document.createElement('div');
            channelBankEl.className = 'zello-channel-bank';
            channelBankEl.style.cssText = 'display:flex;flex-wrap:wrap;gap:4px;padding:4px 6px;border-bottom:1px solid var(--bs-border-color,rgba(0,0,0,.12));';
            if (feedEl && feedEl.parentNode) { feedEl.parentNode.insertBefore(channelBankEl, feedEl); }
        }
        while (channelBankEl.firstChild) { channelBankEl.removeChild(channelBankEl.firstChild); }
        for (var i = 0; i < configuredChannels.length; i++) {
            var ch = configuredChannels[i];
            var isActive = (ch === activeChannel);
            var n = channelUnread[ch] || 0;
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn btn-sm zello-chan-btn ' + (isActive ? 'btn-primary' : 'btn-outline-secondary');
            btn.style.cssText = 'font-size:0.7rem;padding:1px 7px;';
            btn.setAttribute('data-channel', ch);
            btn.title = isActive ? ('Focused channel: ' + ch) : ('Focus ' + ch);
            btn.textContent = ch;
            if (n > 0) {
                var badge = document.createElement('span');
                badge.className = 'badge bg-danger ms-1';
                badge.style.fontSize = '0.6rem';
                badge.textContent = (n > 99 ? '99+' : String(n));
                btn.appendChild(badge);
            }
            btn.addEventListener('click', function () { setActiveChannel(this.getAttribute('data-channel')); });
            channelBankEl.appendChild(btn);
        }
    }

    // Phase 101-5 (Eric beta 2026-07-01) — SharedWorker media-hub port.
    // When the browser supports SharedWorker, the widget routes its
    // Zello traffic through /sw/media-hub.js instead of holding a
    // per-tab WSS. This lets the connection + message state survive
    // page navigations. Widget still owns the <audio> elements and
    // per-tab UI. Safari mobile + Firefox private windows lack
    // SharedWorker → widget falls back to today's per-tab code.
    var swPort = null;
    var swSupported = (typeof SharedWorker !== 'undefined');
    // authTokenFetching guards against double-fetching the auth token
    // when both the tab's own connect + the SW's need_auth_token
    // request race on initial page load.
    var authTokenFetching = false;

    // Phase 101 (Eric beta 2026-07-01) — audio-mute toggle. Distinct
    // from the connection lifecycle: when muted the WSS + SSE keep
    // flowing, incoming messages still increment the unread badge,
    // and text/image/status all render — only audible playback is
    // suppressed. Persisted per-browser via localStorage so a
    // dispatcher's preference survives navigation + refresh.
    var audioMuted = false;
    try {
        audioMuted = (localStorage.getItem('zello_audio_muted') === '1');
    } catch (e) { /* Safari private mode etc. — ignore */ }

    // GH #55 (Eric 2026-07-04) — Live monitor. When ON, incoming channel
    // audio plays even while the widget is minimized or you have navigated
    // to another page (the connection is kept alive and playback is no
    // longer gated on the panel being visible). OFF (default) preserves the
    // prior behavior: audio only plays while the widget is open. Mute is
    // independent and overrides this — muted means silent regardless.
    // Persisted per-browser so the choice survives navigation + refresh.
    var liveMonitor = false;
    try {
        liveMonitor = (localStorage.getItem('zello_live_monitor') === '1');
    } catch (e) { /* ignore */ }
    // True when audio is allowed to play right now given widget visibility
    // and the live-monitor preference.
    function audioAllowed() { return visible || liveMonitor; }

    // GH #41 — live-monitor audio never auto-played because incoming Zello
    // audio is played with HTMLAudioElement.play() from a WebSocket/
    // SharedWorker message callback, which the browser's media-autoplay
    // policy blocks unless the page has sticky user activation. The manual
    // Play button worked only because it runs inside a real click gesture.
    // Bank activation on the FIRST user gesture (click/keydown anywhere) by
    // priming a muted element, so later socket-driven .play() calls in this
    // document are permitted. This is the same technique radio-widget.js /
    // audio-alerts.js already use for their AudioContext.
    var audioUnlocked = false;
    function unlockAudioPlayback() {
        if (audioUnlocked) return;
        audioUnlocked = true;
        try {
            var a = new Audio();
            a.muted = true;
            var p = a.play();
            if (p && p.catch) { p.catch(function () { /* best effort */ }); }
        } catch (e) { /* ignore */ }
        document.removeEventListener('click', unlockAudioPlayback, true);
        document.removeEventListener('keydown', unlockAudioPlayback, true);
    }
    document.addEventListener('click', unlockAudioPlayback, true);
    document.addEventListener('keydown', unlockAudioPlayback, true);

    var unreadCount = 0;
    var connected = false;
    var currentStatus = 'disconnected';
    var messages = [];        // In-memory message cache
    var proxyPort = 8090;
    var historyLoaded = false;

    // PTT state
    var pttBtn = null;         // PTT button element
    var pttActive = false;     // Currently transmitting
    var pttKey = 'Space';      // Configurable PTT key
    var pttTimer = null;       // Timer for transmit duration display
    var pttStartTime = 0;

    // Audio capture state
    var micStream = null;      // MediaStream from getUserMedia
    var audioCtx = null;       // AudioContext for VU meter
    var analyser = null;       // AnalyserNode for VU meter
    var recorder = null;       // MediaRecorder for outgoing audio
    var sendChain = null;      // Serial promise chain for ordered chunk sends.
                               // arrayBuffer() resolves out-of-order under load —
                               // chaining guarantees the WS sees bytes in
                               // recording order so the proxy's WebmOpusExtractor
                               // sees a valid stream. stopTransmit awaits this
                               // chain before sending ptt_stop.
    var vuAnimFrame = null;    // requestAnimationFrame ID for VU meter
    var vuPeak = 0;            // GH #55 — peak mic level seen during a TX,
                               // used to warn on an all-silent transmission

    // Incoming audio stream state: stream_id => { queue: [blobUrl], playing: bool, currentAudio: Audio }
    var activeAudioStreams = {};

    // Phase 99am (Eric beta 2026-07-01) — Global playback lock. Tracks
    // the currently-playing history-message audio + a callback that
    // stops it. When the user clicks any other message's play button,
    // we invoke the previous stopper first so voices never overlap.
    // Live streaming audio (via MSE) is separate and NOT affected.
    var currentPlayback = null;   // { stop: function }
    function acquirePlayback(stopFn) {
        if (currentPlayback && currentPlayback.stop && currentPlayback.stop !== stopFn) {
            try { currentPlayback.stop(); } catch (e) { /* ignore */ }
        }
        currentPlayback = { stop: stopFn };
    }
    function releasePlayback(stopFn) {
        if (currentPlayback && currentPlayback.stop === stopFn) {
            currentPlayback = null;
        }
    }

    // Drag state
    var dragState = { active: false, startX: 0, startY: 0, origLeft: 0, origTop: 0 };

    // Resize state
    var resizeState = { active: false, startX: 0, startY: 0, origW: 0, origH: 0 };

    // ── Init ─────────────────────────────────────────────────────
    function init() {
        var tpl = document.getElementById('tpl-zello-widget');
        if (!tpl) return;

        // Clone template
        var clone = tpl.content ? tpl.content.cloneNode(true) : tpl.cloneNode(true);
        widget = clone.querySelector('.zello-widget');
        if (!widget) return;

        document.body.appendChild(widget);

        // Cache elements
        feedEl       = widget.querySelector('#zelloFeed');
        inputEl      = widget.querySelector('#zelloTextInput');
        statusBadge  = widget.querySelector('.zello-status-badge');
        channelLabel = widget.querySelector('.zello-header-channel');
        _loadActiveChannel();   // Phase 2 — restore the operator's focused channel

        // Read proxy port from meta or default
        var portMeta = document.querySelector('meta[name="zello-proxy-port"]');
        if (portMeta) {
            proxyPort = parseInt(portMeta.getAttribute('content'), 10) || 8090;
        }

        // Position: restore from localStorage or default to bottom-right
        var saved = loadPosition();
        if (saved) {
            widget.style.left = saved.left + 'px';
            widget.style.top  = saved.top + 'px';
            if (saved.width)  widget.style.width  = saved.width + 'px';
            if (saved.height) widget.style.height = saved.height + 'px';
        } else {
            widget.style.right  = '20px';
            widget.style.bottom = '20px';
        }

        // Start hidden
        widget.classList.add('zello-hidden');

        // Cache PTT button
        pttBtn = widget.querySelector('#zelloPttBtn');

        // Attach event listeners
        attachListeners();

        // Listen for the toggle event (the console's "Open Zello" button
        // and the command bar both emit zello:toggle).
        // 2026-07-08: on console.php, EventBus is injected by navbar via an
        // ASYNC loadGlobal(), so it can still be undefined when init() runs
        // at DOMContentLoaded — the old one-shot guard then silently never
        // bound, and "Open Zello" did nothing. Bind resiliently: attach now
        // if EventBus is ready, otherwise poll briefly until it lands.
        function bindZelloToggle() {
            if (typeof EventBus !== 'undefined' && EventBus && EventBus.on) {
                EventBus.on('zello:toggle', function () { toggle(); });
                return true;
            }
            return false;
        }
        if (!bindZelloToggle()) {
            var _zbTries = 0;
            var _zbIv = setInterval(function () {
                if (bindZelloToggle() || ++_zbTries > 40) { clearInterval(_zbIv); }
            }, 50);   // up to ~2 s for the async EventBus to arrive
        }

        // GH #55 — with live-monitor enabled, connect right away so channel
        // audio plays even if the operator never opens the widget on this
        // page. connectWebSocket() routes to the SharedWorker when present
        // and is idempotent, so this composes safely with the media-hub.
        if (liveMonitor) {
            try { connectWebSocket(); } catch (e) {}
        }
    }

    // ── Attach Listeners ─────────────────────────────────────────
    function attachListeners() {
        // Phase 101 — Mute button toggles audioMuted state.
        var muteBtn = widget.querySelector('#zelloMuteBtn');
        if (muteBtn) {
            renderMuteButton(muteBtn); // reflect persisted state on init
            muteBtn.addEventListener('click', function () {
                audioMuted = !audioMuted;
                try { localStorage.setItem('zello_audio_muted', audioMuted ? '1' : '0'); } catch (e) {}
                renderMuteButton(muteBtn);
                // If we just muted while something is playing via the
                // global playback lock, stop it. Live streaming audio
                // stops via the streaming path's own gate.
                if (audioMuted && currentPlayback && currentPlayback.stop) {
                    try { currentPlayback.stop(); } catch (e) {}
                    currentPlayback = null;
                }
            });
        }

        // GH #55 — Live-monitor button toggles cross-UI audio.
        var liveBtn = widget.querySelector('#zelloLiveBtn');
        if (liveBtn) {
            renderLiveButton(liveBtn); // reflect persisted state on init
            liveBtn.addEventListener('click', function () {
                liveMonitor = !liveMonitor;
                try { localStorage.setItem('zello_live_monitor', liveMonitor ? '1' : '0'); } catch (e) {}
                renderLiveButton(liveBtn);
                // Turning live-monitor ON while collapsed: make sure the
                // connection is (re)established so audio can flow. The
                // per-tab reconnect gate now honours liveMonitor; nudge it.
                if (liveMonitor && !visible && (!ws || ws.readyState === WebSocket.CLOSED)) {
                    try { connectWebSocket(); } catch (e) {}
                }
            });
        }

        // Close button
        var closeBtn = widget.querySelector('#zelloClose');
        if (closeBtn) {
            closeBtn.addEventListener('click', function () {
                hide();
            });
        }

        // Minimize button
        var minBtn = widget.querySelector('#zelloMinimize');
        if (minBtn) {
            minBtn.addEventListener('click', function () {
                hide();
            });
        }

        // Send button
        var sendBtn = widget.querySelector('#zelloSendBtn');
        if (sendBtn) {
            sendBtn.addEventListener('click', function () {
                sendText();
            });
        }

        // Enter key in text input; Tab jumps to PTT button (not Send)
        if (inputEl) {
            inputEl.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    sendText();
                } else if (e.key === 'Tab' && !e.shiftKey) {
                    e.preventDefault();
                    if (pttBtn) pttBtn.focus();
                }
            });
            // Phase 100 (2026-07-01) — clipboard paste of an image
            // sends it as a Zello image message (JPEG). Plain-text
            // paste falls through to default browser behavior.
            inputEl.addEventListener('paste', handlePaste);
        }

        // Drag: header mousedown. Skip button/anchor clicks so the
        // header toolbar (mute, archive link, minimize, close) work
        // as expected. Phase 101 archive link is an <a> — the earlier
        // `closest('button')` check missed it, causing drag to
        // consume the click and preventing the new-tab navigation.
        var header = widget.querySelector('.zello-header');
        if (header) {
            header.addEventListener('mousedown', function (e) {
                if (e.target.closest('button, a')) return;
                startDrag(e);
            });
        }

        // Resize: handle mousedown
        var resizeHandle = widget.querySelector('.zello-resize-handle');
        if (resizeHandle) {
            resizeHandle.addEventListener('mousedown', function (e) {
                startResize(e);
            });
        }

        // PTT button: mouse hold
        if (pttBtn) {
            pttBtn.addEventListener('mousedown', function (e) {
                e.preventDefault();
                startTransmit();
            });
            pttBtn.addEventListener('mouseup', function () {
                stopTransmit();
            });
            pttBtn.addEventListener('mouseleave', function () {
                if (pttActive) stopTransmit();
            });
            // Touch support for mobile
            pttBtn.addEventListener('touchstart', function (e) {
                e.preventDefault();
                startTransmit();
            });
            pttBtn.addEventListener('touchend', function () {
                stopTransmit();
            });
            pttBtn.addEventListener('touchcancel', function () {
                if (pttActive) stopTransmit();
            });
        }

        // Esc closes the widget
        document.addEventListener('keydown', function (e) {
            if (!visible) return;
            if (e.key === 'Escape') {
                e.preventDefault();
                hide();
                return;
            }
        });

        // Spacebar PTT: only when widget is visible and text input is NOT focused
        document.addEventListener('keydown', function (e) {
            if (!visible) return;
            if (e.code !== pttKey) return;
            // Don't intercept if typing in the text input or any other input/textarea
            var tag = document.activeElement ? document.activeElement.tagName : '';
            if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;
            if (e.repeat) return; // Ignore key repeat
            e.preventDefault();
            startTransmit();
        });

        document.addEventListener('keyup', function (e) {
            if (!visible) return;
            if (e.code !== pttKey) return;
            var tag = document.activeElement ? document.activeElement.tagName : '';
            if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;
            e.preventDefault();
            stopTransmit();
        });

        // Global mouse events for drag/resize
        document.addEventListener('mousemove', function (e) {
            if (dragState.active)   onDrag(e);
            if (resizeState.active) onResize(e);
        });

        document.addEventListener('mouseup', function () {
            if (dragState.active)   stopDrag();
            if (resizeState.active) stopResize();
        });
    }

    // ── Show / Hide / Toggle ─────────────────────────────────────
    function show() {
        visible = true;
        widget.classList.remove('zello-hidden');

        // Connect WebSocket if not connected
        if (!ws || ws.readyState !== WebSocket.OPEN) {
            connectWebSocket();
        }

        // Load message history on first show
        if (!historyLoaded) {
            loadHistory();
            historyLoaded = true;
        }

        // Clear unread
        clearUnread();

        // Focus text input
        if (inputEl) {
            setTimeout(function () { inputEl.focus(); }, 100);
        }
    }

    function hide() {
        visible = false;
        widget.classList.add('zello-hidden');
    }

    function toggle() {
        if (visible) {
            hide();
        } else {
            show();
        }
    }

    // ── WebSocket Connection ─────────────────────────────────────

    // Phase 101-5 — compute the Zello proxy WSS URL. Used by both the
    // per-tab code and the SharedWorker (via hello.wsUrl since a SW
    // has no window.location).
    function computeZelloWsUrl() {
        if (window.location.protocol === 'https:') {
            return 'wss://' + window.location.host + '/zello-ws';
        }
        return 'ws://' + window.location.hostname + ':' + proxyPort;
    }

    function connectWebSocket() {
        // Phase 101-5 — SharedWorker branch. When present, we do NOT
        // open a per-tab WSS; the SW owns it. connectSharedWorker()
        // handles token fetch, hello, event routing.
        if (swSupported) {
            connectSharedWorker();
            return;
        }

        if (ws && (ws.readyState === WebSocket.OPEN || ws.readyState === WebSocket.CONNECTING)) {
            return;
        }

        updateStatus('connecting');
        addSystemMessage('Requesting auth token...');

        // Step 1: fetch a fresh auth token from the server
        fetchAuthToken().then(function (tokenData) {
            if (tokenData.error) {
                updateStatus('error');
                addSystemMessage('Auth failed: ' + tokenData.error);
                return;
            }
            if (tokenData.channels) {
                configuredChannels = tokenData.channels;
                dispatchChannel = tokenData.dispatch_channel || '';
                renderChannelBank();
            }
            // Step 2: open WebSocket with the token
            openSocket(tokenData.token);
        }).catch(function (err) {
            updateStatus('error');
            addSystemMessage('Token request failed: ' + err.message);
        });
    }

    // Phase 101-5 — SharedWorker connect. Idempotent: subsequent
    // calls after the port is up are no-ops.
    function connectSharedWorker() {
        if (swPort) return;
        try {
            var worker = new SharedWorker('sw/media-hub.js', 'newui-media-hub');
            swPort = worker.port;
        } catch (e) {
            // Fall back to per-tab. Some corporate browsers throw on SW.
            addSystemMessage('SharedWorker unavailable — using per-tab mode');
            swSupported = false;
            connectWebSocket();
            return;
        }
        swPort.onmessage = function (ev) { handleSwMessage(ev.data); };
        swPort.start();
        addSystemMessage('Requesting auth token...');
        // Send hello immediately so the SW knows we're here.
        try {
            swPort.postMessage({
                kind: 'hello',
                visible: !document.hidden,
                focused: document.hasFocus(),
                proxyPort: proxyPort,
                wsUrl: computeZelloWsUrl()
                // authToken purposely omitted — SW will ask via
                // need_auth_token if it needs one.
            });
        } catch (e) {
            addSystemMessage('SharedWorker port error: ' + e.message);
        }
        // Best-effort leave notification.
        window.addEventListener('beforeunload', function () {
            try { swPort.postMessage({ kind: 'leave' }); } catch (e) {}
        });
    }

    function handleSwMessage(msg) {
        if (!msg || typeof msg !== 'object') return;
        switch (msg.kind) {
            case 'welcome':
                // SW replies immediately with current zello status +
                // rolling recent-broadcast buffer so a fresh tab sees
                // recent activity without a re-fetch.
                if (msg.status && msg.status.zello) {
                    updateStatus(msg.status.zello.status || 'disconnected');
                }
                if (msg.recent && msg.recent.length) {
                    for (var i = 0; i < msg.recent.length; i++) {
                        var r = msg.recent[i];
                        if (r && r.kind === 'zello_event' && r.payload) {
                            handleProxyMessage(JSON.stringify(r.payload));
                        }
                    }
                }
                break;

            case 'need_auth_token':
                if (authTokenFetching) break;
                authTokenFetching = true;
                fetchAuthToken().then(function (data) {
                    authTokenFetching = false;
                    if (data.channels) {
                        configuredChannels = data.channels;
                        dispatchChannel = data.dispatch_channel || '';
                        renderChannelBank();
                    }
                    if (data.token) {
                        try { swPort.postMessage({ kind: 'auth_token', token: data.token }); } catch (e) {}
                    } else if (data.error) {
                        addSystemMessage('Auth failed: ' + data.error);
                    }
                }).catch(function (err) {
                    authTokenFetching = false;
                    addSystemMessage('Token request failed: ' + err.message);
                });
                break;

            case 'status':
                if (msg.target === 'zello') {
                    updateStatus(msg.status);
                    if (msg.detail) addSystemMessage(msg.detail);
                }
                break;

            case 'zello_event':
                // Every JSON event Zello proxy emits, unwrapped from
                // the SW envelope. Feed through the same render code
                // the per-tab WS uses.
                if (msg.payload) {
                    handleProxyMessage(JSON.stringify(msg.payload));
                }
                break;

            case 'zello_binary':
                // Phase 101-5 — voice_chunk audio still flows via
                // JSON+base64 in this slice; raw binary would only
                // arrive from a future proxy change. Log + ignore for now.
                break;

            default:
                // Ignore unknown kinds so a newer SW protocol doesn't
                // break older widget code.
                break;
        }
    }

    // Phase 101-5 — shared token fetch used by both transport modes.
    function fetchAuthToken() {
        return fetch('api/zello-token.php', { credentials: 'same-origin' })
            .then(function (res) { return res.json(); });
    }

    // Phase 101-5 — send to the Zello proxy through whichever
    // transport is live. Accepts:
    //   * a plain object  → JSON.stringify + text frame
    //   * a string        → text frame verbatim
    //   * an ArrayBuffer  → binary frame (PTT audio bytes)
    // In SW mode the ArrayBuffer is transferred zero-copy.
    function sendToProxy(payload) {
        var isBuffer = (payload && payload.byteLength !== undefined
                        && (payload instanceof ArrayBuffer
                            || (typeof ArrayBuffer !== 'undefined' && ArrayBuffer.isView && ArrayBuffer.isView(payload))));
        if (swPort) {
            try {
                if (isBuffer) {
                    var buf = (payload instanceof ArrayBuffer) ? payload : payload.buffer;
                    swPort.postMessage({ kind: 'zello_send', binary: buf }, [buf]);
                } else {
                    swPort.postMessage({ kind: 'zello_send', payload: payload });
                }
                return true;
            } catch (e) { /* port died — silently drop; SW reconnect will resume */ }
            return false;
        }
        if (ws && ws.readyState === WebSocket.OPEN) {
            if (isBuffer) {
                ws.send(payload);
            } else {
                ws.send(typeof payload === 'string' ? payload : JSON.stringify(payload));
            }
            return true;
        }
        return false;
    }

    function openSocket(token) {
        // On HTTPS the browser blocks an insecure ws:// connection ("operation is
        // insecure"). Connect via wss:// through the reverse-proxied /zello-ws path
        // (Apache terminates TLS and forwards to ws://localhost:proxyPort). On plain
        // HTTP, connect straight to the proxy port.
        var url;
        if (window.location.protocol === 'https:') {
            url = 'wss://' + window.location.host + '/zello-ws';
        } else {
            url = 'ws://' + window.location.hostname + ':' + proxyPort;
        }

        try {
            ws = new WebSocket(url);
        } catch (e) {
            updateStatus('error');
            addSystemMessage('WebSocket connection failed: ' + e.message);
            return;
        }

        ws.onopen = function () {
            // Send auth with DB-backed token (no session ID needed)
            ws.send(JSON.stringify({
                cmd: 'auth',
                token: token
            }));
        };

        ws.onmessage = function (evt) {
            handleProxyMessage(evt.data);
        };

        ws.onclose = function () {
            connected = false;
            updateStatus('disconnected');
            addSystemMessage('Disconnected from proxy');

            // Auto-reconnect after 5 seconds if the widget is visible OR
            // live-monitor is on (GH #55 — keep the channel alive while
            // minimized so audio can still play). When a SharedWorker owns
            // the connection this per-tab path is not used.
            if (visible || liveMonitor) {
                setTimeout(function () {
                    if ((visible || liveMonitor) && (!ws || ws.readyState === WebSocket.CLOSED)) {
                        connectWebSocket();
                    }
                }, 5000);
            }
        };

        ws.onerror = function () {
            updateStatus('error');
        };
    }

    // ── Handle Proxy Messages ────────────────────────────────────
    function handleProxyMessage(raw) {
        var data;
        try {
            data = JSON.parse(raw);
        } catch (e) {
            return;
        }

        var type = data.type || '';

        switch (type) {
            case 'auth_ok':
                connected = true;
                addSystemMessage('Connected as ' + (data.user || 'unknown'));
                if (data.channel && channelLabel) {
                    channelLabel.textContent = data.channel;
                }
                break;

            case 'status':
                // Phase 99ak (Eric beta 2026-07-01) — 'channel_status'
                // is an informational sub-state (e.g. "Channel
                // 'TicketsCAD-Group' is online") that should surface in
                // the log trail WITHOUT overwriting the primary
                // connection-state badge. Recognized primary states:
                // connecting/connected/authenticated/disconnected/
                // reconnecting/kicked/auth_failed/rate_limited/error.
                var primaryStates = {
                    'connecting': 1, 'connected': 1, 'authenticated': 1,
                    'disconnected': 1, 'reconnecting': 1, 'kicked': 1,
                    'auth_failed': 1, 'rate_limited': 1, 'error': 1,
                    'disabled': 1, 'failed': 1
                };
                if (primaryStates[data.status]) {
                    updateStatus(data.status);
                }
                if (data.detail) {
                    addSystemMessage(data.detail);
                }
                break;

            case 'text_message':
                addMessageToFeed({
                    direction:       data.direction || 'incoming',
                    sender_username: data.sender_username || '',
                    sender_display:  data.sender_display || data.sender_username || '',
                    channel:         data.channel || '',
                    text:            data.text || '',
                    timestamp:       data.timestamp || new Date().toISOString(),
                    id:              data.id || null
                });
                break;

            case 'channel_status':
                if (channelLabel) {
                    var chText = data.channel || '';
                    if (data.users_online) {
                        chText += ' (' + data.users_online + ' online)';
                    } else if (data.status) {
                        chText += ' — ' + data.status;
                    }
                    channelLabel.textContent = chText;
                }
                break;

            case 'voice_start':
                addVoiceIndicator(data);
                break;

            case 'voice_header':
                handleVoiceHeader(data);
                break;

            case 'voice_chunk':
                handleVoiceChunk(data);
                break;

            case 'voice_stop':
                // Just remove the "talking" animation — voice_message will follow
                removeVoiceIndicator(data.stream_id);
                break;

            case 'voice_message':
                // Stream finished — check if we already played it via streaming
                var wasStreamed = finalizeStreamingAudio(data.stream_id);
                // Suppress auto-play if we already streamed the audio live
                if (wasStreamed) {
                    data._noAutoPlay = true;
                }
                addVoiceMessage(data);
                break;

            case 'location':
                addMessageToFeed({
                    direction:       'incoming',
                    sender_username: data.sender_username || '',
                    sender_display:  data.sender_username || '',
                    channel:         data.channel || '',
                    text:            'Location: ' + (data.latitude || '?') + ', ' + (data.longitude || '?'),
                    timestamp:       data.timestamp || new Date().toISOString()
                });
                break;

            case 'image_message':
                // Phase 100 (2026-07-01) — render an inbound or
                // outbound image inline in the message feed.
                addImageMessage(data);
                break;

            case 'ptt_ack':
                // Proxy acknowledged PTT — upstream is ready for audio
                if (data.status === 'denied') {
                    stopTransmit();
                    addSystemMessage('PTT denied: ' + (data.reason || 'channel busy'));
                }
                break;

            case 'error':
                addSystemMessage('Error: ' + (data.message || 'Unknown error'));
                break;
        }
    }

    // ── Send Text Message ────────────────────────────────────────
    function sendText() {
        if (!inputEl) return;
        var text = inputEl.value.trim();
        if (text === '') return;

        // Phase 101-5 — SW mode has no per-tab ws; check either
        // transport is live before dispatching. sendToProxy still
        // returns false if both fail, but we surface the user error
        // up-front so paste/type doesn't silently disappear.
        if (!swPort && (!ws || ws.readyState !== WebSocket.OPEN)) {
            addSystemMessage('Not connected. Cannot send message.');
            return;
        }

        sendToProxy({
            cmd: 'send_text',
            text: text
        });

        inputEl.value = '';
        inputEl.focus();
    }

    // ── Message Feed ─────────────────────────────────────────────
    function addMessageToFeed(msg) {
        if (!feedEl) return;

        // Remove empty state
        var emptyEl = feedEl.querySelector('.zello-feed-empty');
        if (emptyEl) emptyEl.remove();

        var div = document.createElement('div');
        div.className = 'zello-msg ' + (msg.direction === 'outgoing' ? 'zello-msg-outgoing' : 'zello-msg-incoming');

        var senderDiv = document.createElement('div');
        senderDiv.className = 'zello-msg-sender';
        senderDiv.textContent = msg.direction === 'outgoing' ? 'You' : (msg.sender_display || msg.sender_username || 'Unknown');
        appendChannelBadge(senderDiv, msg.channel);
        noteChannelTraffic(msg.channel, msg.direction);
        div.appendChild(senderDiv);

        var textDiv = document.createElement('div');
        textDiv.className = 'zello-msg-text';
        textDiv.textContent = msg.text || '';
        div.appendChild(textDiv);

        var timeDiv = document.createElement('div');
        timeDiv.className = 'zello-msg-time';
        timeDiv.textContent = formatTime(msg.timestamp);
        div.appendChild(timeDiv);

        feedEl.appendChild(div);
        scrollToBottom();

        // Track unread if widget is hidden
        if (!visible) {
            incrementUnread();
        }

        // Store in memory
        messages.push(msg);
    }

    // ── Image message (Phase 100, 2026-07-01) ───────────────────
    // Renders an incoming/outgoing image inline in the message feed:
    //   [sender name] [timestamp]
    //   [thumbnail <img> — click to expand full-size in modal]
    // data.thumb is a data:image/jpeg;base64 URI (~10-15 KB); data.full_url
    // is a cache/zello-images/... path fetched on click.
    function addImageMessage(data) {
        if (!feedEl) return;
        var emptyEl = feedEl.querySelector('.zello-feed-empty');
        if (emptyEl) emptyEl.remove();

        var isOutgoing = (data.direction === 'outgoing');
        var div = document.createElement('div');
        div.className = 'zello-msg ' + (isOutgoing ? 'zello-msg-outgoing' : 'zello-msg-incoming');

        var senderDiv = document.createElement('div');
        senderDiv.className = 'zello-msg-sender';
        senderDiv.textContent = isOutgoing ? 'You' : (data.sender_display || data.sender_username || 'Unknown');
        appendChannelBadge(senderDiv, data.channel);
        noteChannelTraffic(data.channel, data.direction);
        div.appendChild(senderDiv);

        var img = document.createElement('img');
        img.className = 'zello-msg-image';
        img.src = data.thumb || data.full_url || '';
        img.alt = 'Image from ' + (data.sender_username || 'Zello');
        img.style.maxWidth  = '200px';
        img.style.maxHeight = '200px';
        img.style.cursor    = 'pointer';
        img.style.borderRadius = '4px';
        img.style.display   = 'block';
        img.style.marginTop = '2px';
        img.title = 'Click to expand';
        img.addEventListener('click', function () {
            expandImage(data.full_url || data.thumb, data);
        });
        div.appendChild(img);

        var timeDiv = document.createElement('div');
        timeDiv.className = 'zello-msg-time';
        timeDiv.textContent = formatTime(data.timestamp);
        div.appendChild(timeDiv);

        feedEl.appendChild(div);
        scrollToBottom();

        if (!visible) incrementUnread();
        messages.push(data);
    }

    // Phase 100 — full-size expand. Creates (or reuses) a Bootstrap
    // modal, drops the image URL in, shows it. Clicking outside or
    // pressing Esc dismisses.
    function expandImage(url, data) {
        if (!url) return;
        var modal = document.getElementById('zelloImageModal');
        if (!modal) {
            modal = document.createElement('div');
            modal.id = 'zelloImageModal';
            modal.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;'
                + 'background:rgba(0,0,0,0.85);display:flex;align-items:center;'
                + 'justify-content:center;z-index:100050;cursor:zoom-out;';
            modal.addEventListener('click', function (e) {
                if (e.target === modal || e.target.tagName === 'IMG') modal.remove();
            });
            document.addEventListener('keydown', function onKey(e) {
                if (e.key === 'Escape') {
                    var m = document.getElementById('zelloImageModal');
                    if (m) m.remove();
                    document.removeEventListener('keydown', onKey);
                }
            });
            document.body.appendChild(modal);
        }
        modal.innerHTML = '';
        var big = document.createElement('img');
        big.src = url;
        big.style.cssText = 'max-width:95vw;max-height:95vh;border-radius:6px;'
            + 'box-shadow:0 10px 40px rgba(0,0,0,0.6);';
        modal.appendChild(big);
    }

    // Phase 100 — clipboard-paste handler bound to the widget's text
    // input. When the user pastes an image (Ctrl-V from a screenshot,
    // camera roll copy, etc.) instead of text, we:
    //   1. grab the first image/* entry from clipboardData.items
    //   2. draw it to a canvas, resample to <=1600px longest edge
    //   3. JPEG-encode via canvas.toBlob('image/jpeg', 0.85)
    //   4. also encode a ~180px thumbnail
    //   5. base64 both, send as one JSON 'send_image' action to proxy
    // If the paste is plain text, we do nothing (native behavior takes over).
    function handlePaste(ev) {
        var cd = ev.clipboardData || window.clipboardData;
        if (!cd || !cd.items) return;
        var imgItem = null;
        for (var i = 0; i < cd.items.length; i++) {
            if (cd.items[i].type && cd.items[i].type.indexOf('image/') === 0) {
                imgItem = cd.items[i];
                break;
            }
        }
        if (!imgItem) return; // no image in clipboard, let default handler run
        ev.preventDefault();

        var blob = imgItem.getAsFile();
        if (!blob) {
            addSystemMessage('Paste failed: could not read image from clipboard');
            return;
        }
        var url = URL.createObjectURL(blob);
        var probe = new Image();
        probe.onload = function () {
            var srcW = probe.naturalWidth;
            var srcH = probe.naturalHeight;
            var maxEdge = 1600;
            var scale = Math.min(1, maxEdge / Math.max(srcW, srcH));
            var outW = Math.max(1, Math.round(srcW * scale));
            var outH = Math.max(1, Math.round(srcH * scale));

            // Full image
            var canvas = document.createElement('canvas');
            canvas.width = outW; canvas.height = outH;
            var ctx = canvas.getContext('2d');
            ctx.drawImage(probe, 0, 0, outW, outH);
            canvas.toBlob(function (fullBlob) {
                if (!fullBlob) { addSystemMessage('Image encode failed'); URL.revokeObjectURL(url); return; }

                // Thumbnail — 180px longest edge, quality 0.75
                var thumbMax = 180;
                var tScale = Math.min(1, thumbMax / Math.max(outW, outH));
                var tW = Math.max(1, Math.round(outW * tScale));
                var tH = Math.max(1, Math.round(outH * tScale));
                var tCanvas = document.createElement('canvas');
                tCanvas.width = tW; tCanvas.height = tH;
                tCanvas.getContext('2d').drawImage(canvas, 0, 0, tW, tH);
                tCanvas.toBlob(function (thumbBlob) {
                    if (!thumbBlob) { addSystemMessage('Thumbnail encode failed'); URL.revokeObjectURL(url); return; }
                    URL.revokeObjectURL(url);
                    Promise.all([blobToB64(fullBlob), blobToB64(thumbBlob)]).then(function (arr) {
                        var ok = sendToProxy({
                            cmd:       'send_image',
                            channel:   '',
                            recipient: '',
                            width:     outW,
                            height:    outH,
                            thumb_b64: arr[1],
                            full_b64:  arr[0]
                        });
                        if (!ok) {
                            addSystemMessage('Not connected. Cannot send image.');
                            return;
                        }
                        addSystemMessage('Sending image (' + outW + 'x' + outH + ', ~' + Math.round(fullBlob.size / 1024) + ' KB)…');
                    });
                }, 'image/jpeg', 0.75);
            }, 'image/jpeg', 0.85);
        };
        probe.onerror = function () {
            URL.revokeObjectURL(url);
            addSystemMessage('Paste failed: could not decode pasted image');
        };
        probe.src = url;
    }

    // Read a Blob and resolve to bare base64 (no data:… prefix).
    function blobToB64(blob) {
        return new Promise(function (resolve, reject) {
            var reader = new FileReader();
            reader.onload = function () {
                var s = reader.result || '';
                var comma = s.indexOf(',');
                resolve(comma >= 0 ? s.substring(comma + 1) : s);
            };
            reader.onerror = function () { reject(new Error('read failed')); };
            reader.readAsDataURL(blob);
        });
    }

    function addSystemMessage(text) {
        if (!feedEl) return;

        var emptyEl = feedEl.querySelector('.zello-feed-empty');
        if (emptyEl) emptyEl.remove();

        var div = document.createElement('div');
        div.className = 'zello-msg-alert';
        div.textContent = text;
        feedEl.appendChild(div);
        scrollToBottom();
    }

    function addVoiceIndicator(data) {
        if (!feedEl) return;

        var emptyEl = feedEl.querySelector('.zello-feed-empty');
        if (emptyEl) emptyEl.remove();

        var streamId = data.stream_id || 0;
        var isOutgoing = data.direction === 'outgoing';

        var div = document.createElement('div');
        div.className = 'zello-msg ' + (isOutgoing ? 'zello-msg-outgoing' : 'zello-msg-incoming') + ' zello-msg-voice-active';
        div.setAttribute('data-stream-id', streamId);

        var senderDiv = document.createElement('div');
        senderDiv.className = 'zello-msg-sender';
        senderDiv.textContent = isOutgoing ? (data.sender_display || 'You') : (data.sender_display || data.sender_username || 'Unknown');
        appendChannelBadge(senderDiv, data.channel);
        div.appendChild(senderDiv);

        var voiceDiv = document.createElement('div');
        voiceDiv.className = 'zello-msg-voice zello-voice-talking';
        voiceDiv.innerHTML = '<i class="bi bi-mic-fill zello-voice-pulse"></i> <span>' + (isOutgoing ? 'Transmitting...' : 'Talking...') + '</span>';
        div.appendChild(voiceDiv);

        var timeDiv = document.createElement('div');
        timeDiv.className = 'zello-msg-time';
        timeDiv.textContent = formatTime(new Date().toISOString());
        div.appendChild(timeDiv);

        feedEl.appendChild(div);
        scrollToBottom();

        // a beta tester GH #55 (2026-07-04) — do NOT bump the unread badge
        // here. This live "transmitting..." indicator fires on stream
        // START and the completed voice message (addVoiceMessage)
        // fires on stream END; counting both made every transmission
        // show +2 on the Zello button. The completed message is the
        // durable item, so it alone carries the unread count.
    }

    function removeVoiceIndicator(streamId) {
        if (!feedEl) return;
        var el = feedEl.querySelector('.zello-msg-voice-active[data-stream-id="' + streamId + '"]');
        if (el) {
            el.remove();
        }
    }

    function addVoiceMessage(data) {
        if (!feedEl) return;

        var emptyEl = feedEl.querySelector('.zello-feed-empty');
        if (emptyEl) emptyEl.remove();

        // Remove the "talking" indicator for this stream if it's still there
        removeVoiceIndicator(data.stream_id);

        var durationSec = ((data.duration_ms || 0) / 1000).toFixed(1);
        var isOutgoing = data.direction === 'outgoing';

        var div = document.createElement('div');
        div.className = 'zello-msg ' + (isOutgoing ? 'zello-msg-outgoing' : 'zello-msg-incoming') + ' zello-msg-voice-complete';

        var senderDiv = document.createElement('div');
        senderDiv.className = 'zello-msg-sender';
        senderDiv.textContent = isOutgoing ? (data.sender_display || 'You') : (data.sender_display || data.sender_username || 'Unknown');
        appendChannelBadge(senderDiv, data.channel);
        noteChannelTraffic(data.channel, data.direction);
        div.appendChild(senderDiv);

        var voiceDiv = document.createElement('div');
        voiceDiv.className = 'zello-msg-voice';

        // Play button
        var playBtn = document.createElement('button');
        playBtn.type = 'button';
        playBtn.className = 'zello-voice-play';
        playBtn.innerHTML = '<i class="bi bi-play-fill"></i>';
        playBtn.title = 'Play voice message';
        voiceDiv.appendChild(playBtn);

        // Duration label
        var labelSpan = document.createElement('span');
        labelSpan.className = 'zello-voice-label';
        labelSpan.textContent = 'Voice (' + durationSec + 's)';
        voiceDiv.appendChild(labelSpan);

        // Progress bar
        var progressDiv = document.createElement('div');
        progressDiv.className = 'zello-voice-progress';
        var progressBar = document.createElement('div');
        progressBar.className = 'zello-voice-progress-bar';
        progressDiv.appendChild(progressBar);
        voiceDiv.appendChild(progressDiv);

        div.appendChild(voiceDiv);

        var timeDiv = document.createElement('div');
        timeDiv.className = 'zello-msg-time';
        timeDiv.textContent = formatTime(data.timestamp || new Date().toISOString());
        div.appendChild(timeDiv);

        feedEl.appendChild(div);
        scrollToBottom();

        if (!visible) {
            incrementUnread();
        }

        // Wire up audio playback
        var audioUrl = data.audio_url || '';
        if (audioUrl) {
            var audio = new Audio(audioUrl);
            var isPlaying = false;

            // Phase 99am — one-shot stopper for the global playback
            // lock (see acquirePlayback / releasePlayback above).
            function stopThisAudio() {
                if (!audio.paused) audio.pause();
                audio.currentTime = 0;
                playBtn.innerHTML = '<i class="bi bi-play-fill"></i>';
                playBtn.classList.remove('playing');
                progressBar.style.width = '0%';
                isPlaying = false;
            }

            playBtn.addEventListener('click', function () {
                if (isPlaying) {
                    stopThisAudio();
                    releasePlayback(stopThisAudio);
                } else {
                    // Stop any other message currently playing.
                    acquirePlayback(stopThisAudio);
                    audio.play().catch(function () {
                        // Autoplay blocked — user needs to click
                    });
                }
            });

            audio.addEventListener('play', function () {
                isPlaying = true;
                acquirePlayback(stopThisAudio);
                playBtn.innerHTML = '<i class="bi bi-pause-fill"></i>';
                playBtn.classList.add('playing');
            });

            audio.addEventListener('timeupdate', function () {
                if (audio.duration > 0) {
                    var pct = (audio.currentTime / audio.duration) * 100;
                    progressBar.style.width = pct + '%';
                }
            });

            audio.addEventListener('ended', function () {
                isPlaying = false;
                playBtn.innerHTML = '<i class="bi bi-play-fill"></i>';
                playBtn.classList.remove('playing');
                progressBar.style.width = '0%';
                releasePlayback(stopThisAudio);
            });

            audio.addEventListener('error', function () {
                labelSpan.textContent = 'Voice (' + durationSec + 's) — playback error';
                releasePlayback(stopThisAudio);
                // Issue #41 followup (a beta tester 2026-07-04): "playback
                // error" alone is undiagnosable from a screenshot.
                // Probe the audio URL and refine the label so the
                // report tells us WHICH failure this is: the file
                // missing from the recordings dir (404 — proxy write
                // path vs web root mismatch), auth (403), or a real
                // codec/decode failure (file served fine but the
                // browser can't play it).
                fetch(audioUrl, { method: 'HEAD', credentials: 'same-origin' })
                    .then(function (r) {
                        labelSpan.textContent = 'Voice (' + durationSec + 's) — '
                            + (r.ok ? 'audio decode failed'
                                    : 'recording missing (HTTP ' + r.status + ')');
                    })
                    .catch(function () {
                        labelSpan.textContent = 'Voice (' + durationSec + 's) — recording unreachable (network)';
                    });
            });

            // Auto-play if widget is visible (skip for history items).
            // Phase 99am — never auto-play OUTGOING messages: they are
            // your own transmission arriving back as a completed .ogg,
            // and hearing yourself immediately after PTT-off is
            // universally unwanted. Card still renders + play button
            // still works for manual replay.
            // Phase 101 — audio-mute is respected on auto-play too.
            var isOwnOutgoing = (data.direction === 'outgoing');
            // GH #55 — play completed messages when the widget is open OR when
            // live-monitor is on (so audio follows you while minimized). tryPlay
            // still enforces mute + the same gate as a safety net.
            if (audioAllowed() && !data._noAutoPlay && !isOwnOutgoing) {
                acquirePlayback(stopThisAudio);
                tryPlay(audio);
            }
        }
    }

    // ── Streaming Audio Playback (MSE — MediaSource Extensions) ──

    /**
     * Check if MediaSource Extensions are available for Opus streaming.
     * Falls back to Web Audio API decodeAudioData if not supported.
     */
    var MSE_SUPPORTED = (function () {
        if (typeof MediaSource === 'undefined') return false;
        try {
            return MediaSource.isTypeSupported('audio/webm;codecs=opus');
        } catch (e) {
            return false;
        }
    })();

    /**
     * Handle an incoming voice chunk (MSE approach).
     *
     * The proxy sends WebM data: on first chunk, includes an init segment
     * (EBML header + Tracks) plus a Cluster. Subsequent chunks are Clusters.
     * The browser appends all data into a SourceBuffer, keeping the Opus
     * decoder alive across chunks for seamless, artifact-free playback.
     */
    function handleVoiceChunk(data) {
        var streamId = data.stream_id || 0;
        if (!streamId) return;

        // Initialize MSE stream on first chunk
        if (!activeAudioStreams[streamId]) {
            if (!MSE_SUPPORTED) {
                // Fallback: skip streaming, wait for final .ogg
                return;
            }
            initMseStream(streamId);
        }

        // Mute playback of streams that arrive while we are transmitting
        // (Zello echoes our own audio back as a separate incoming stream)
        var stream = activeAudioStreams[streamId];
        if (stream && stream.audio) {
            stream.audio.muted = !!pttActive;
        }

        var stream = activeAudioStreams[streamId];
        if (!stream || stream.error) return;

        // Decode base64 data
        var initBytes = data.webm_init ? base64ToUint8Array(data.webm_init) : null;
        var dataBytes = data.webm_data ? base64ToUint8Array(data.webm_data) : null;

        // Combine init + data if both present
        var appendData;
        if (initBytes && initBytes.length > 0 && dataBytes && dataBytes.length > 0) {
            appendData = new Uint8Array(initBytes.length + dataBytes.length);
            appendData.set(initBytes, 0);
            appendData.set(dataBytes, initBytes.length);
        } else if (dataBytes && dataBytes.length > 0) {
            appendData = dataBytes;
        } else {
            return;
        }

        // Queue data for appending to SourceBuffer
        stream.queue.push(appendData);
        processQueue(streamId);

        // Update visual indicator on first chunk
        if (!stream.started) {
            stream.started = true;
            updateVoiceIndicatorStreaming(streamId);
        }
    }

    /**
     * Initialize a MediaSource-based audio stream for a given stream ID.
     * Creates MediaSource → <audio> element → SourceBuffer pipeline.
     */
    function initMseStream(streamId) {
        var ms = new MediaSource();
        var audio = document.createElement('audio');

        var stream = {
            mediaSource: ms,
            sourceBuffer: null,
            audio: audio,
            queue: [],          // Pending data chunks to append
            updating: false,    // Whether SourceBuffer.appendBuffer is in progress
            started: false,
            error: false,
            ended: false,
            playedAudible: false  // GH #41 — did live audio ACTUALLY play out?
        };

        activeAudioStreams[streamId] = stream;

        audio.src = URL.createObjectURL(ms);

        ms.addEventListener('sourceopen', function () {
            if (stream.error) return;
            try {
                var sb = ms.addSourceBuffer('audio/webm;codecs=opus');
                stream.sourceBuffer = sb;

                sb.addEventListener('updateend', function () {
                    stream.updating = false;
                    processQueue(streamId);
                });

                sb.addEventListener('error', function () {
                    stream.error = true;
                    console.error('SourceBuffer error on stream ' + streamId);
                });

                // Process any queued data that arrived before sourceopen
                processQueue(streamId);
            } catch (e) {
                stream.error = true;
                console.error('Failed to create SourceBuffer:', e);
            }
        });

        // Start playback once we have enough data. Phase 101 —
        // audio mute respected on live streaming as well.
        audio.addEventListener('canplay', function () {
            tryPlay(audio);
        });

        // GH #41 — record whether the live stream ACTUALLY produced audible
        // playback. If it did, the completed .ogg auto-play is suppressed to
        // avoid double audio; if it did NOT (SourceBuffer/codec failure, or
        // autoplay still blocked), the completed message must fall back to
        // auto-playing so the operator isn't left with silent live-monitor
        // (a beta tester's symptom: "playing audio" shown but nothing came out, only
        // the after-the-fact playback button worked).
        audio.addEventListener('timeupdate', function () {
            if (!audio.muted && audio.currentTime > 0) {
                stream.playedAudible = true;
            }
        });

        // Try to start playback immediately (gesture-dependent)
        tryPlay(audio);
    }

    /**
     * Process the pending data queue for a stream's SourceBuffer.
     * Appends one chunk at a time (SourceBuffer requires sequential appends).
     */
    function processQueue(streamId) {
        var stream = activeAudioStreams[streamId];
        if (!stream || !stream.sourceBuffer || stream.updating || stream.error) return;
        if (stream.queue.length === 0) {
            // If stream has ended and queue is empty, signal end of stream
            if (stream.ended && stream.mediaSource.readyState === 'open') {
                try {
                    stream.mediaSource.endOfStream();
                } catch (e) {
                    // May fail if already ended — ignore
                }
            }
            return;
        }

        var data = stream.queue.shift();
        try {
            stream.updating = true;
            stream.sourceBuffer.appendBuffer(data);
        } catch (e) {
            stream.updating = false;
            stream.error = true;
            console.error('appendBuffer failed:', e);
        }
    }

    /**
     * Clean up streaming audio state when the stream ends.
     * @return {boolean} true if the stream was actively being played via streaming
     */
    function finalizeStreamingAudio(streamId) {
        var stream = activeAudioStreams[streamId];
        if (!stream) return false;

        // Signal end of stream — let the audio element finish playing remaining buffer
        stream.ended = true;
        processQueue(streamId);

        // Clean up after playback finishes (give it time to play out)
        var audio = stream.audio;
        if (audio) {
            audio.addEventListener('ended', function () {
                cleanupMseStream(streamId);
            });
            // Safety timeout: clean up after 30 seconds regardless
            setTimeout(function () {
                cleanupMseStream(streamId);
            }, 30000);
        } else {
            cleanupMseStream(streamId);
        }

        // GH #41 — only claim the audio "was streamed" (which suppresses the
        // completed .ogg auto-play) if the live stream ACTUALLY produced
        // audible playback and didn't error. When MSE fails silently — the
        // exact case where "playing audio" showed but nothing came out — this
        // returns false so the completed message auto-plays as a fallback.
        return !!stream.playedAudible && !stream.error;
    }

    /**
     * Release MSE resources for a stream.
     */
    function cleanupMseStream(streamId) {
        var stream = activeAudioStreams[streamId];
        if (!stream) return;

        if (stream.audio) {
            stream.audio.pause();
            if (stream.audio.src) {
                URL.revokeObjectURL(stream.audio.src);
            }
            stream.audio.src = '';
        }
        stream.mediaSource = null;
        stream.sourceBuffer = null;
        stream.audio = null;
        stream.queue = [];
        delete activeAudioStreams[streamId];
    }

    /**
     * Update the voice indicator to show streaming/playing status.
     */
    function updateVoiceIndicatorStreaming(streamId) {
        if (!feedEl) return;
        var el = feedEl.querySelector('.zello-msg-voice-active[data-stream-id="' + streamId + '"]');
        if (el) {
            var voiceDiv = el.querySelector('.zello-msg-voice');
            if (voiceDiv) {
                voiceDiv.className = 'zello-msg-voice zello-voice-streaming';
                voiceDiv.innerHTML = '<i class="bi bi-volume-up-fill"></i> <span>Playing audio...</span>';
            }
        }
    }

    /**
     * Decode a base64 string to Uint8Array.
     */
    function base64ToUint8Array(b64) {
        try {
            var binary = atob(b64);
            var bytes = new Uint8Array(binary.length);
            for (var i = 0; i < binary.length; i++) {
                bytes[i] = binary.charCodeAt(i);
            }
            return bytes;
        } catch (e) {
            return new Uint8Array(0);
        }
    }

    function scrollToBottom() {
        if (feedEl) {
            feedEl.scrollTop = feedEl.scrollHeight;
        }
    }

    // ── Message History ──────────────────────────────────────────
    function loadHistory() {
        fetch('api/zello-messages.php?limit=50', {
            credentials: 'same-origin'
        }).then(function (res) {
            return res.json();
        }).then(function (data) {
            var msgs = data.messages || [];
            // Messages come back newest-first; reverse for feed display
            for (var i = msgs.length - 1; i >= 0; i--) {
                var m = msgs[i];
                if (m.message_type === 'voice') {
                    addVoiceMessage({
                        direction:       m.direction || 'incoming',
                        sender_username: m.sender_username || '',
                        sender_display:  m.sender_display || m.sender_username || '',
                        channel:         m.channel || '',
                        duration_ms:     m.duration_ms || 0,
                        audio_url:       m.media_url || '',
                        timestamp:       m.created || '',
                        id:              m.id || null,
                        stream_id:       0,
                        _noAutoPlay:     true  // Don't auto-play history items
                    });
                } else if (m.message_type === 'image') {
                    // Phase 101 (Eric beta 2026-07-01) — image history.
                    // Live path carries a data-URI thumbnail; history
                    // rows only have the full_url stored server-side.
                    // Widget renderer falls back to full_url for the
                    // thumbnail spot when data.thumb is empty — the
                    // browser will scale it down for the card and use
                    // the same URL for expand.
                    addImageMessage({
                        direction:       m.direction || 'incoming',
                        sender_username: m.sender_username || '',
                        sender_display:  m.sender_display || m.sender_username || '',
                        channel:         m.channel || '',
                        thumb:           '',                     // no inline thumb stored
                        full_url:        m.media_url || '',
                        timestamp:       m.created || '',
                        id:              m.id || null,
                        _noAutoPlay:     true
                    });
                } else {
                    addMessageToFeed({
                        direction:       m.direction || 'incoming',
                        sender_username: m.sender_username || '',
                        sender_display:  m.sender_display || m.sender_username || '',
                        channel:         m.channel || '',
                        text:            m.content || '',
                        timestamp:       m.created || '',
                        id:              m.id || null
                    });
                }
            }
        }).catch(function () {
            // Silently fail — history is optional
        });
    }

    // Phase 101 — Update the mute button's icon + tooltip + a11y
    // attribute based on current audioMuted state.
    function renderMuteButton(btn) {
        if (!btn) btn = widget && widget.querySelector('#zelloMuteBtn');
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

    // GH #55 — Update the live-monitor button's icon + tooltip + a11y
    // to reflect the current liveMonitor state.
    function renderLiveButton(btn) {
        if (!btn) btn = widget && widget.querySelector('#zelloLiveBtn');
        if (!btn) return;
        var icon = btn.querySelector('i');
        if (icon) {
            // Filled/solid broadcast pin when live; hollow broadcast when off.
            icon.className = liveMonitor ? 'bi bi-broadcast-pin' : 'bi bi-broadcast';
        }
        btn.title = liveMonitor
            ? 'Live monitor ON — audio plays even when the widget is minimized. Click to limit audio to when the widget is open.'
            : 'Live monitor off — audio plays only while the widget is open. Click to keep hearing the channel when minimized.';
        btn.setAttribute('aria-pressed', liveMonitor ? 'true' : 'false');
        btn.setAttribute('aria-label', liveMonitor ? 'Live monitor on' : 'Live monitor off');
        // Visual emphasis when active (solid button), matching Bootstrap.
        if (liveMonitor) {
            btn.classList.remove('btn-outline-secondary');
            btn.classList.add('btn-success');
        } else {
            btn.classList.remove('btn-success');
            btn.classList.add('btn-outline-secondary');
        }
    }

    // Phase 101 — helper used by every audio playback path so the
    // mute state has one gate. Callers still fire the fetch / decode
    // / MediaSource plumbing so cards render immediately; only the
    // actual audible playback is skipped when muted.
    // GH #55 — also the single gate for live-monitor: when the widget is
    // collapsed and live monitor is OFF, audible playback is skipped (the
    // card + unread badge still update). The manual play button calls
    // audioEl.play() directly and is intentionally NOT routed through here,
    // so an operator can always replay a message on demand.
    function tryPlay(audioEl) {
        if (audioMuted) return;     // silent — card + progress bar still show
        if (!audioAllowed()) return; // collapsed + live-monitor off — no auto audio
        try {
            var p = audioEl.play();
            if (p && p.catch) {
                p.catch(function () {
                    // GH #41 — if autoplay is still blocked (no user gesture
                    // banked yet), tell the operator ONCE instead of failing
                    // silently, so a dead Live Monitor is never a mystery.
                    if (!tryPlay._warned) {
                        tryPlay._warned = true;
                        try {
                            addSystemMessage('Live audio is blocked until you interact with the page once — click anywhere to enable it.');
                        } catch (e2) { /* ignore */ }
                    }
                });
            }
        } catch (e) { /* ignore */ }
    }

    // ── Status ───────────────────────────────────────────────────
    function updateStatus(status) {
        currentStatus = status;
        if (statusBadge) {
            // Remove old status classes
            statusBadge.className = 'zello-status-badge status-' + status;
        }
    }

    // ── Unread Badge ─────────────────────────────────────────────
    function incrementUnread() {
        unreadCount++;
        if (typeof EventBus !== 'undefined') {
            EventBus.emit('zello:unread', { count: unreadCount });
        }
    }

    function clearUnread() {
        unreadCount = 0;
        if (typeof EventBus !== 'undefined') {
            EventBus.emit('zello:unread', { count: 0 });
        }
    }

    // ── PTT (Push to Talk) ──────────────────────────────────────
    function startTransmit() {
        if (pttActive) return;
        if (!connected) {
            addSystemMessage('Not connected. Cannot transmit.');
            return;
        }

        pttActive = true;
        pttStartTime = Date.now();
        vuPeak = 0; // GH #55 — reset per-transmission mic-level peak

        // Visual feedback
        if (pttBtn) {
            pttBtn.classList.add('transmitting');
            pttBtn.innerHTML = '<i class="bi bi-mic-fill me-1"></i> TRANSMITTING...';
        }

        // Start duration timer
        updateTransmitTimer();

        // Tell the proxy we're starting a voice stream (transport-
        // agnostic via sendToProxy — SW or per-tab).
        sendToProxy({ cmd: 'ptt_start' });

        // Start mic capture and VU meter
        startMicCapture();
    }

    function stopTransmit() {
        if (!pttActive) return;
        pttActive = false;

        // Unmute any streams that were muted during our transmission
        for (var sid in activeAudioStreams) {
            if (activeAudioStreams[sid] && activeAudioStreams[sid].audio) {
                activeAudioStreams[sid].audio.muted = false;
            }
        }

        var duration = Date.now() - pttStartTime;
        var seconds = (duration / 1000).toFixed(1);

        // GH #55 (Eric 2026-07-04) — turn a silent transmission from a mystery
        // into a visible warning. If the mic level never rose above the noise
        // floor for a transmission longer than ~0.7s, the browser sent only
        // Opus DTX (silence) frames — the proxy drops the whole over ("0
        // packets / no audio frames") so nobody hears it. Almost always a
        // muted mic, the wrong input device, or the OS mic level at zero.
        if (duration > 700 && vuPeak < 4) {
            addSystemMessage('⚠️ No microphone signal detected during that transmission — it went out silent. '
                + 'Check that the correct microphone is selected and not muted (browser mic permission / OS input device / mic level).');
        }

        // Visual feedback
        if (pttBtn) {
            pttBtn.classList.remove('transmitting');
            pttBtn.style.removeProperty('--vu-level');
            pttBtn.innerHTML = '<i class="bi bi-mic-fill me-1"></i> Push to Talk';
        }

        // Stop duration timer
        if (pttTimer) {
            clearInterval(pttTimer);
            pttTimer = null;
        }

        // Stop VU meter animation
        if (vuAnimFrame) {
            cancelAnimationFrame(vuAnimFrame);
            vuAnimFrame = null;
        }

        // Stop mic stream
        if (micStream) {
            micStream.getTracks().forEach(function (t) { t.stop(); });
            micStream = null;
        }

        // Stop recorder — the onstop callback fires after the final
        // ondataavailable, but the chunk SEND is async (blob.arrayBuffer
        // is a Promise). We collect all in-flight Promises in
        // pendingChunkSends and await them before firing ptt_stop so
        // the proxy never sees an empty stream or orphan post-stop
        // audio frames.
        //
        // Eric beta 2026-06-30 — the prior FileReader + 100ms setTimeout
        // approach lost audio on PTT presses shorter than ~400ms: the
        // FileReader.onload callbacks fired AFTER the ptt_stop and the
        // proxy closed the stream before the audio bytes landed.
        var dur = duration;
        function flushAndStop() {
            // Await the serial send chain (chunks sent in recording
            // order — see startMicCapture comment). ptt_stop goes on the
            // wire AFTER the last audio chunk has landed.
            var chain = sendChain || Promise.resolve();
            sendChain = Promise.resolve(); // reset for next transmission
            chain.then(function () {
                sendToProxy({ cmd: 'ptt_stop', duration_ms: dur });
            });
        }
        if (recorder && recorder.state !== 'inactive') {
            recorder.onstop = function () {
                recorder = null;
                flushAndStop();
            };
            // requestData() forces a final ondataavailable BEFORE
            // recorder.stop(), so even very short presses produce a
            // chunk in pendingChunkSends.
            try { recorder.requestData(); } catch (e) { /* OK */ }
            recorder.stop();
        } else {
            recorder = null;
            flushAndStop();
        }

        // Update hint with last duration
        var hint = widget.querySelector('.zello-ptt-hint');
        if (hint) {
            hint.textContent = 'Last transmission: ' + seconds + 's — Hold Space or click to talk';
            setTimeout(function () {
                if (!pttActive && hint) {
                    hint.textContent = 'Hold Space or click to talk';
                }
            }, 5000);
        }
    }

    // ── Mic Capture & VU Meter ──────────────────────────────────
    function startMicCapture() {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            addSystemMessage('Microphone not available in this browser');
            return;
        }

        navigator.mediaDevices.getUserMedia({
            audio: {
                sampleRate: 16000,
                channelCount: 1,
                echoCancellation: true,
                noiseSuppression: true
            }
        }).then(function (stream) {
            micStream = stream;

            // GH #55 — surface which mic we actually got + whether it's live,
            // so a silent transmission can be traced to the device in one look.
            try {
                var track = stream.getAudioTracks()[0];
                if (track) {
                    var s = track.getSettings ? track.getSettings() : {};
                    addSystemMessage('Mic: "' + (track.label || 'default')
                        + '" (' + (track.muted ? 'MUTED' : 'live')
                        + (track.enabled ? '' : ', disabled')
                        + (s.deviceId ? '' : '') + ')');
                }
            } catch (e) { /* diagnostics only — never block TX */ }

            // Set up AudioContext for VU meter
            var AC = window.AudioContext || window.webkitAudioContext;
            if (!audioCtx) {
                audioCtx = new AC();
            }
            var source = audioCtx.createMediaStreamSource(stream);
            analyser = audioCtx.createAnalyser();
            analyser.fftSize = 256;
            source.connect(analyser);
            // Don't connect to destination — we only want to monitor, not play back

            // Start VU meter animation
            updateVuMeter();

            // Set up MediaRecorder for audio capture
            var mimeType = 'audio/webm;codecs=opus';
            if (!MediaRecorder.isTypeSupported(mimeType)) {
                mimeType = 'audio/webm';
            }
            if (!MediaRecorder.isTypeSupported(mimeType)) {
                addSystemMessage('Audio recording not supported in this browser');
                return;
            }

            recorder = new MediaRecorder(stream, {
                mimeType: mimeType,
                audioBitsPerSecond: 64000
            });

            // Eric beta 2026-06-30 (round 2) — chunks MUST arrive in
            // recording order at the proxy or the WebmOpusExtractor sees
            // a corrupted stream and produces garbled audio. blob's
            // arrayBuffer() Promise resolves whenever the underlying
            // ArrayBuffer is ready, NOT in submission order, so under
            // load you can read+send chunk N AFTER chunk N+1. Promise.all
            // gives you completion-tracking but not ordering.
            //
            // Fix: serialize the sends through a single Promise chain.
            // Each new chunk waits for the prior chunk's send to land
            // on the WS before issuing its own arrayBuffer() + send.
            // stopTransmit awaits this chain before sending ptt_stop —
            // so the proxy sees: [audio_n, audio_n+1, ..., audio_last,
            // ptt_stop] in strict order.
            sendChain = Promise.resolve();
            recorder.ondataavailable = function (e) {
                if (!e.data || e.data.size === 0) return;
                if (!pttActive) return; // hard gate — don't leak post-release
                // Phase 101-5 fix (Eric beta 2026-07-01) — in SW mode
                // `ws` is null; the transport check must go through
                // sendToProxy which knows about both modes. Was
                // dropping every audio chunk pre-send in SW mode.
                if (!swPort && (!ws || ws.readyState !== WebSocket.OPEN)) return;
                var blob = e.data;
                sendChain = sendChain.then(function () {
                    return blob.arrayBuffer().then(function (buf) {
                        // Phase 101-5 — binary PTT chunk through
                        // whichever transport is live. SW mode
                        // zero-copy-transfers the ArrayBuffer.
                        sendToProxy(buf);
                    });
                }).catch(function () { /* keep chain alive on errors */ });
            };

            // Record in 100ms chunks — short enough that a 300ms PTT
            // press still yields ~3 chunks, large enough that the WebM
            // extractor has meaningful payload per call. The previous
            // 50ms experiment caused garbled audio because the ordering
            // race had more chances to fire per second. Original was
            // 200ms which was reliable but lost audio on <400ms presses.
            recorder.start(100);

        }).catch(function (err) {
            addSystemMessage('Mic access denied: ' + err.message);
        });
    }

    function updateVuMeter() {
        if (!pttActive || !analyser || !pttBtn) return;

        var dataArray = new Uint8Array(analyser.frequencyBinCount);
        analyser.getByteFrequencyData(dataArray);

        // Calculate RMS level (0 to 100)
        var sum = 0;
        for (var i = 0; i < dataArray.length; i++) {
            sum += dataArray[i] * dataArray[i];
        }
        var rms = Math.sqrt(sum / dataArray.length);
        var level = Math.min(100, Math.round((rms / 128) * 100));

        // Update CSS custom property for VU meter visual
        pttBtn.style.setProperty('--vu-level', level + '%');
        if (level > vuPeak) vuPeak = level; // GH #55 — track TX peak

        vuAnimFrame = requestAnimationFrame(updateVuMeter);
    }

    // sendAudioToProxy removed — audio chunks are now sent in real-time
    // via ondataavailable during recording

    function updateTransmitTimer() {
        if (pttTimer) clearInterval(pttTimer);
        pttTimer = setInterval(function () {
            if (!pttActive || !pttBtn) {
                clearInterval(pttTimer);
                pttTimer = null;
                return;
            }
            var elapsed = ((Date.now() - pttStartTime) / 1000).toFixed(0);
            pttBtn.innerHTML = '<i class="bi bi-mic-fill me-1"></i> TRANSMITTING ' + elapsed + 's';
        }, 500);
    }

    // ── Drag ─────────────────────────────────────────────────────
    function startDrag(e) {
        e.preventDefault();
        var rect = widget.getBoundingClientRect();

        // If positioned with right/bottom, switch to left/top
        widget.style.left = rect.left + 'px';
        widget.style.top  = rect.top + 'px';
        widget.style.right  = 'auto';
        widget.style.bottom = 'auto';

        dragState.active = true;
        dragState.startX = e.clientX;
        dragState.startY = e.clientY;
        dragState.origLeft = rect.left;
        dragState.origTop  = rect.top;

        widget.classList.add('dragging');
    }

    function onDrag(e) {
        var dx = e.clientX - dragState.startX;
        var dy = e.clientY - dragState.startY;
        var newLeft = dragState.origLeft + dx;
        var newTop  = dragState.origTop + dy;

        // Constrain to viewport
        var w = widget.offsetWidth;
        var h = widget.offsetHeight;
        newLeft = Math.max(0, Math.min(window.innerWidth - w, newLeft));
        newTop  = Math.max(0, Math.min(window.innerHeight - h, newTop));

        widget.style.left = newLeft + 'px';
        widget.style.top  = newTop + 'px';
    }

    function stopDrag() {
        dragState.active = false;
        widget.classList.remove('dragging');
        savePosition();
    }

    // ── Resize ───────────────────────────────────────────────────
    function startResize(e) {
        e.preventDefault();
        e.stopPropagation();
        resizeState.active = true;
        resizeState.startX = e.clientX;
        resizeState.startY = e.clientY;
        resizeState.origW  = widget.offsetWidth;
        resizeState.origH  = widget.offsetHeight;
        widget.classList.add('dragging');
    }

    function onResize(e) {
        var dx = e.clientX - resizeState.startX;
        var dy = e.clientY - resizeState.startY;
        var newW = Math.max(280, Math.min(600, resizeState.origW + dx));
        var newH = Math.max(300, Math.min(window.innerHeight * 0.9, resizeState.origH + dy));

        widget.style.width  = newW + 'px';
        widget.style.height = newH + 'px';
    }

    function stopResize() {
        resizeState.active = false;
        widget.classList.remove('dragging');
        savePosition();
    }

    // ── Position Persistence ─────────────────────────────────────
    function savePosition() {
        try {
            var rect = widget.getBoundingClientRect();
            localStorage.setItem('zello_widget_pos', JSON.stringify({
                left:   Math.round(rect.left),
                top:    Math.round(rect.top),
                width:  widget.offsetWidth,
                height: widget.offsetHeight
            }));
        } catch (e) {
            // localStorage might be unavailable
        }
    }

    function loadPosition() {
        try {
            var raw = localStorage.getItem('zello_widget_pos');
            if (raw) {
                return JSON.parse(raw);
            }
        } catch (e) {
            // Ignore
        }
        return null;
    }

    // ── Helpers ──────────────────────────────────────────────────
    function formatTime(ts) {
        if (!ts) return '';
        var d = new Date(ts);
        if (isNaN(d.getTime())) return ts;
        var h = d.getHours();
        var m = d.getMinutes();
        var s = d.getSeconds();
        return (h < 10 ? '0' : '') + h + ':' +
               (m < 10 ? '0' : '') + m + ':' +
               (s < 10 ? '0' : '') + s;
    }

    // ── Bootstrap ────────────────────────────────────────────────
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
