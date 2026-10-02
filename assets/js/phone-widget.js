/**
 * Phase 153 (2026-09-08) — browser-native WebRTC phone widget.
 *
 * Registers this browser as its own bound SIP extension against the
 * Asterisk PBX (api/phone-extensions.php?action=my_extension resolves
 * WHICH extension, keyed by this browser's own console-workstation.js
 * token) using JsSIP over WSS, and drives dial/answer/hangup/mute through
 * it. Structurally modeled on zello-widget.js / radio-widget.js: template
 * clone, drag/resize, Detach via window.WindowDetach, show/hide persisted
 * across navigation the same way (Eric's 2026-09-08 "widget disappeared
 * on navigation" fix for Zello, applied here from day one instead of
 * being a second bug to find later).
 *
 * ES5 only (project rule) — JsSIP itself (assets/vendor/jssip/) is a
 * third-party bundle and is exempt, this file is not.
 */
(function () {
    'use strict';

    var widget = null;
    var visible = false;
    var wasOpen = false;
    var statusBadge = null;
    var extensionLabel = null;
    var footerEl = null;

    var ua = null;
    var currentSession = null;
    var remoteAudio = null;
    var callTimerIv = null;
    var callStartedAt = null;
    var muted = false;
    var myExtension = null;   // {extension, label, sip_username, sip_password, wss_url, general_number}

    var dragState = { active: false, startX: 0, startY: 0, origLeft: 0, origTop: 0 };
    var resizeState = { active: false, startX: 0, startY: 0, origW: 0, origH: 0 };

    // ── Workstation token (Phase 152's own convention) ──────────────────
    function getWorkstationToken() {
        try {
            if (window.ConsoleWorkstation && window.ConsoleWorkstation.getToken) {
                return window.ConsoleWorkstation.getToken();
            }
        } catch (e) {}
        return '';
    }

    // ── Status / footer ──────────────────────────────────────────────
    function setStatus(cls, text) {
        if (statusBadge) {
            statusBadge.className = 'phone-status-badge status-' + cls;
        }
        if (footerEl) {
            footerEl.textContent = text;
        }
    }

    // ── Fetch my extension + start registration ─────────────────────
    function loadMyExtension(cb) {
        var token = getWorkstationToken();
        fetch('api/phone-extensions.php?action=my_extension&workstation_token=' + encodeURIComponent(token), {
            credentials: 'same-origin'
        }).then(function (r) { return r.json(); }).then(function (data) {
            myExtension = data || null;
            if (cb) cb(myExtension);
        }).catch(function () {
            setStatus('disconnected', 'Could not reach the server.');
        });
    }

    function renderBoundState() {
        var unbound = widget.querySelector('#phoneUnboundPanel');
        var body = widget.querySelector('#phoneBody');
        var footer = widget.querySelector('#phoneFooter');
        var extLabel = widget.querySelector('.phone-header-extension');
        var generalBtn = widget.querySelector('#phoneGeneralBtn');
        var generalNum = widget.querySelector('#phoneGeneralNum');

        if (!myExtension || !myExtension.bound) {
            unbound.classList.remove('d-none');
            body.classList.add('d-none');
            footer.textContent = 'Not bound to an extension';
            var tokenInput = widget.querySelector('#phoneMyToken');
            if (tokenInput) tokenInput.value = getWorkstationToken();
            return;
        }
        unbound.classList.add('d-none');
        body.classList.remove('d-none');
        extLabel.textContent = myExtension.extension + ' — ' + myExtension.label;

        if (myExtension.general_number) {
            generalNum.textContent = myExtension.general_number;
            generalBtn.classList.remove('d-none');
        } else {
            generalBtn.classList.add('d-none');
        }
    }

    function startRegistration() {
        if (!myExtension || !myExtension.bound || !myExtension.wss_url) {
            setStatus('disconnected', myExtension && myExtension.bound
                ? 'PBX connection not configured yet (ask an administrator).'
                : 'Not bound to an extension');
            return;
        }
        if (typeof window.JsSIP === 'undefined') {
            setStatus('disconnected', 'Phone library failed to load.');
            return;
        }
        if (ua) {
            try { ua.stop(); } catch (e) {}
            ua = null;
        }

        setStatus('connecting', 'Connecting…');

        var hostMatch = /^wss:\/\/([^/]+)/i.exec(myExtension.wss_url);
        var host = hostMatch ? hostMatch[1].split(':')[0] : 'localhost';

        var socket = new window.JsSIP.WebSocketInterface(myExtension.wss_url);
        var config = {
            sockets: [socket],
            uri: 'sip:' + myExtension.sip_username + '@' + host,
            password: myExtension.sip_password,
            display_name: myExtension.label,
            register: true,
            session_timers: false
        };

        ua = new window.JsSIP.UA(config);

        ua.on('registered', function () { setStatus('connected', 'Registered as ' + myExtension.extension); });
        ua.on('unregistered', function () { setStatus('disconnected', 'Unregistered'); });
        ua.on('registrationFailed', function (e) {
            var cause = (e && e.cause) ? e.cause : 'unknown reason';
            setStatus('disconnected', 'Registration failed: ' + cause
                + ' — if this is the first time this browser has connected, visit '
                + myExtension.wss_url.replace(/^wss:\/\//i, 'https://').replace(/\/ws$/, '/')
                + ' once and accept the certificate.');
        });
        ua.on('disconnected', function () { setStatus('disconnected', 'Disconnected from PBX'); });

        ua.on('newRTCSession', function (e) { handleNewSession(e.session, e.originator); });

        ua.start();
    }

    // ── Call session handling ────────────────────────────────────────
    function handleNewSession(session, originator) {
        if (currentSession) {
            // Already on a call -- politely reject a second incoming leg.
            if (originator === 'remote') {
                try { session.terminate({ status_code: 486, reason_phrase: 'Busy Here' }); } catch (e) {}
            }
            return;
        }
        currentSession = session;

        session.on('peerconnection', function (e) {
            e.peerconnection.addEventListener('track', function (ev) {
                if (remoteAudio && ev.streams && ev.streams[0]) {
                    remoteAudio.srcObject = ev.streams[0];
                    remoteAudio.play().catch(function () {});
                }
            });
        });

        if (originator === 'remote') {
            var callerNum = (session.remote_identity && session.remote_identity.uri) ? session.remote_identity.uri.user : 'Unknown';
            var callerName = (session.remote_identity && session.remote_identity.display_name) ? session.remote_identity.display_name : '';
            showIncoming(callerName ? (callerName + ' <' + callerNum + '>') : callerNum);
        } else {
            var toNum = (session.remote_identity && session.remote_identity.uri) ? session.remote_identity.uri.user : '';
            showInCall(toNum);
        }

        session.on('progress', function () { setStatus('in-call', 'Ringing…'); });
        session.on('accepted', function () { setStatus('in-call', 'In call'); });
        session.on('confirmed', function () {
            setStatus('in-call', 'In call');
            hideIncoming();
            var peer = (session.remote_identity && session.remote_identity.uri) ? session.remote_identity.uri.user : '';
            showInCall(peer);
            startCallTimer();
        });
        session.on('ended', function () { endCall(); });
        session.on('failed', function (e) {
            var cause = (e && e.cause) ? e.cause : 'call failed';
            setStatus('connected', 'Call ended: ' + cause);
            endCall();
        });
    }

    function showIncoming(fromLabel) {
        widget.querySelector('#phoneIncomingFrom').textContent = fromLabel;
        widget.querySelector('#phoneIncoming').classList.remove('d-none');
        widget.querySelector('#phoneDialpad').classList.add('d-none');
        if (!visible) { try { show(); } catch (e) {} }
    }

    function hideIncoming() {
        widget.querySelector('#phoneIncoming').classList.add('d-none');
    }

    function showInCall(peerLabel) {
        widget.querySelector('#phoneIncallPeer').textContent = peerLabel || '—';
        widget.querySelector('#phoneIncall').classList.remove('d-none');
        widget.querySelector('#phoneDialpad').classList.add('d-none');
    }

    function startCallTimer() {
        callStartedAt = Date.now();
        if (callTimerIv) clearInterval(callTimerIv);
        callTimerIv = setInterval(function () {
            var secs = Math.floor((Date.now() - callStartedAt) / 1000);
            var m = Math.floor(secs / 60);
            var s = secs % 60;
            widget.querySelector('#phoneIncallTimer').textContent =
                (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
        }, 500);
    }

    function endCall() {
        if (callTimerIv) { clearInterval(callTimerIv); callTimerIv = null; }
        currentSession = null;
        muted = false;
        if (remoteAudio) { remoteAudio.srcObject = null; }
        widget.querySelector('#phoneIncoming').classList.add('d-none');
        widget.querySelector('#phoneIncall').classList.add('d-none');
        widget.querySelector('#phoneDialpad').classList.remove('d-none');
        widget.querySelector('#phoneDialInput').value = '';
        var muteBtn = widget.querySelector('#phoneMuteCallBtn');
        if (muteBtn) muteBtn.setAttribute('aria-pressed', 'false');
        if (ua && ua.isRegistered && ua.isRegistered()) {
            setStatus('connected', 'Registered as ' + (myExtension ? myExtension.extension : ''));
        }
    }

    function placeCall(number) {
        number = (number || '').trim();
        if (!number || !ua) return;
        try {
            ua.call(number, {
                mediaConstraints: { audio: true, video: false },
                pcConfig: { iceServers: [] }
            });
        } catch (e) {
            setStatus('connected', 'Call failed to start.');
        }
    }

    // ── Init ─────────────────────────────────────────────────────────
    function init() {
        var tpl = document.getElementById('tpl-phone-widget');
        if (!tpl) return;

        var clone = tpl.content ? tpl.content.cloneNode(true) : tpl.cloneNode(true);
        widget = clone.querySelector('.phone-widget');
        if (!widget) return;
        document.body.appendChild(widget);

        statusBadge = widget.querySelector('.phone-status-badge');
        footerEl = widget.querySelector('#phoneFooter');
        extensionLabel = widget.querySelector('.phone-header-extension');

        remoteAudio = document.createElement('audio');
        remoteAudio.autoplay = true;
        remoteAudio.style.display = 'none';
        document.body.appendChild(remoteAudio);

        try {
            wasOpen = (localStorage.getItem('phone_widget_open') === '1');
        } catch (e) { wasOpen = false; }

        var saved = loadPosition();
        if (saved) {
            widget.style.left = saved.left + 'px';
            widget.style.top = saved.top + 'px';
            if (saved.width) widget.style.width = saved.width + 'px';
            if (saved.height) widget.style.height = saved.height + 'px';
        } else {
            widget.style.right = '20px';
            widget.style.bottom = '20px';
        }

        widget.classList.add('phone-hidden');

        attachListeners();

        loadMyExtension(function () {
            renderBoundState();
            startRegistration();
        });

        function bindToggle() {
            if (typeof EventBus !== 'undefined' && EventBus && EventBus.on) {
                EventBus.on('phone:toggle', function () { toggle(); });
                return true;
            }
            return false;
        }
        if (!bindToggle()) {
            var tries = 0;
            var iv = setInterval(function () {
                if (bindToggle() || ++tries > 40) clearInterval(iv);
            }, 50);
        }

        if (wasOpen) {
            try { show(); } catch (e) {}
        }
    }

    function attachListeners() {
        var header = widget.querySelector('.phone-header');
        header.addEventListener('mousedown', function (e) {
            if (e.target.closest('.phone-header-actions')) return;
            startDrag(e);
        });
        document.addEventListener('mousemove', function (e) {
            if (dragState.active) onDrag(e);
            if (resizeState.active) onResize(e);
        });
        document.addEventListener('mouseup', function () {
            if (dragState.active) stopDrag();
            if (resizeState.active) stopResize();
        });
        var resizeHandle = widget.querySelector('.phone-resize-handle');
        if (resizeHandle) resizeHandle.addEventListener('mousedown', startResize);

        widget.querySelector('#phoneMinimize').addEventListener('click', function () { hide(); });
        widget.querySelector('#phoneClose').addEventListener('click', function () { hide(); });

        var detachBtn = widget.querySelector('#phoneDetach');
        var detachHandle = null;
        if (detachBtn && window.WindowDetach) {
            detachBtn.addEventListener('click', function () {
                if (detachHandle) return;
                detachBtn.disabled = true;
                detachHandle = window.WindowDetach.open(widget, {
                    width: 320, height: 460, title: 'Phone — TicketsCAD',
                    onOpen: function () { widget.classList.add('phone-detached'); },
                    onClose: function () {
                        detachHandle = null;
                        detachBtn.disabled = false;
                        widget.classList.remove('phone-detached');
                    }
                });
            });
        }

        var copyBtn = widget.querySelector('#phoneCopyToken');
        if (copyBtn) {
            copyBtn.addEventListener('click', function () {
                var input = widget.querySelector('#phoneMyToken');
                input.select();
                try { document.execCommand('copy'); } catch (e) {}
            });
        }

        // Dial pad
        var dialInput = widget.querySelector('#phoneDialInput');
        var keys = widget.querySelectorAll('.phone-key');
        for (var i = 0; i < keys.length; i++) {
            keys[i].addEventListener('click', function (e) {
                dialInput.value += e.currentTarget.getAttribute('data-key');
            });
        }
        widget.querySelector('#phoneBackspaceBtn').addEventListener('click', function () {
            dialInput.value = dialInput.value.slice(0, -1);
        });
        widget.querySelector('#phoneCallBtn').addEventListener('click', function () {
            placeCall(dialInput.value);
        });
        dialInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') placeCall(dialInput.value);
        });
        widget.querySelector('#phoneGeneralBtn').addEventListener('click', function () {
            if (myExtension && myExtension.general_number) placeCall(myExtension.general_number);
        });

        // Incoming
        widget.querySelector('#phoneAnswerBtn').addEventListener('click', function () {
            if (currentSession) {
                currentSession.answer({ mediaConstraints: { audio: true, video: false } });
            }
        });
        widget.querySelector('#phoneDeclineBtn').addEventListener('click', function () {
            if (currentSession) { try { currentSession.terminate(); } catch (e) {} }
        });

        // In-call
        widget.querySelector('#phoneHangupBtn').addEventListener('click', function () {
            if (currentSession) { try { currentSession.terminate(); } catch (e) {} }
        });
        widget.querySelector('#phoneMuteCallBtn').addEventListener('click', function (e) {
            if (!currentSession) return;
            muted = !muted;
            try {
                if (muted) currentSession.mute({ audio: true });
                else currentSession.unmute({ audio: true });
            } catch (err) {}
            e.currentTarget.setAttribute('aria-pressed', muted ? 'true' : 'false');
            e.currentTarget.innerHTML = muted
                ? '<i class="bi bi-mic-mute-fill"></i>'
                : '<i class="bi bi-mic-fill"></i>';
        });
    }

    // ── Show / hide / toggle ─────────────────────────────────────────
    function show() {
        if (!widget) return;
        widget.classList.remove('phone-hidden');
        try { localStorage.setItem('phone_widget_open', '1'); } catch (e) {}
        visible = true;
    }
    function hide() {
        if (!widget) return;
        widget.classList.add('phone-hidden');
        try { localStorage.setItem('phone_widget_open', '0'); } catch (e) {}
        visible = false;
    }
    function toggle() { visible ? hide() : show(); }

    // ── Drag ─────────────────────────────────────────────────────────
    function startDrag(e) {
        e.preventDefault();
        var rect = widget.getBoundingClientRect();
        widget.style.left = rect.left + 'px';
        widget.style.top = rect.top + 'px';
        widget.style.right = 'auto';
        widget.style.bottom = 'auto';
        dragState.active = true;
        dragState.startX = e.clientX;
        dragState.startY = e.clientY;
        dragState.origLeft = rect.left;
        dragState.origTop = rect.top;
        widget.classList.add('dragging');
    }
    function onDrag(e) {
        var dx = e.clientX - dragState.startX;
        var dy = e.clientY - dragState.startY;
        var newLeft = dragState.origLeft + dx;
        var newTop = dragState.origTop + dy;
        var w = widget.offsetWidth;
        var h = widget.offsetHeight;
        newLeft = Math.max(0, Math.min(window.innerWidth - w, newLeft));
        newTop = Math.max(0, Math.min(window.innerHeight - h, newTop));
        widget.style.left = newLeft + 'px';
        widget.style.top = newTop + 'px';
    }
    function stopDrag() {
        dragState.active = false;
        widget.classList.remove('dragging');
        savePosition();
    }

    // ── Resize ───────────────────────────────────────────────────────
    function startResize(e) {
        e.preventDefault();
        e.stopPropagation();
        resizeState.active = true;
        resizeState.startX = e.clientX;
        resizeState.startY = e.clientY;
        resizeState.origW = widget.offsetWidth;
        resizeState.origH = widget.offsetHeight;
        widget.classList.add('dragging');
    }
    function onResize(e) {
        var dx = e.clientX - resizeState.startX;
        var dy = e.clientY - resizeState.startY;
        var newW = Math.max(260, Math.min(480, resizeState.origW + dx));
        var newH = Math.max(280, Math.min(window.innerHeight * 0.9, resizeState.origH + dy));
        widget.style.width = newW + 'px';
        widget.style.height = newH + 'px';
    }
    function stopResize() {
        resizeState.active = false;
        widget.classList.remove('dragging');
        savePosition();
    }

    function savePosition() {
        try {
            var rect = widget.getBoundingClientRect();
            localStorage.setItem('phone_widget_pos', JSON.stringify({
                left: Math.round(rect.left),
                top: Math.round(rect.top),
                width: widget.offsetWidth,
                height: widget.offsetHeight
            }));
        } catch (e) {}
    }
    function loadPosition() {
        try {
            var raw = localStorage.getItem('phone_widget_pos');
            if (raw) return JSON.parse(raw);
        } catch (e) {}
        return null;
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    window.PhoneWidget = { show: show, hide: hide, toggle: toggle, placeCall: placeCall };
})();
