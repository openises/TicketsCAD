/**
 * Phase 153 (2026-09-08) — browser-native WebRTC phone widget.
 * Phase 155 (2026-10-02, GH#108 S1/S4) — registers where it should, one
 * Answer, plain-English failures.
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
 * Phase 155 behaviour (why this file changed):
 *   - WHERE it registers is the install-wide phone_register_scope
 *     setting (see phone-dial-logic.js). TicketsCAD is a multi-page app, so
 *     a registration lives only as long as the page that made it; the
 *     default registers on the Console and on the standalone Phone window
 *     (phone.php, which keeps ringing while the operator uses other
 *     tabs). Elsewhere the widget says so instead of showing a misleading
 *     "not bound" panel.
 *   - Only ONE window of a browser holds the registration (a tiny
 *     localStorage election; the standalone window outranks the rest).
 *   - Answering here also claims the matching Phase 149 call when the PBX
 *     tagged the INVITE with X-Call-Linkedid, and a banner Answer on any
 *     page answers the audio here: one click either way.
 *
 * ES5 only (project rule) — JsSIP itself (assets/vendor/jssip/) is a
 * third-party bundle and is exempt, this file is not.
 */
(function () {
    'use strict';

    var Logic = window.PhoneDialLogic || null;
    var STANDALONE = window.PHONE_STANDALONE === true;

    var WINDOW_NAME = 'ticketscad_phone';
    var WINDOW_URL = 'phone.php';
    var WINDOW_FEATURES = 'width=340,height=560,resizable=yes,scrollbars=no';
    var BANNER_CHANNEL = 'ticketscad-phone';
    var CLAIM_URL = 'api/inbound-calls.php?action=claim_by_provider';
    var CLAIM_RETRY_MS = 1500;

    var pageName = Logic ? Logic.pageNameFromPath(window.location.pathname) : '';
    var myId = 'pw' + Date.now().toString(36) + Math.random().toString(36).slice(2, 8);

    var widget = null;
    var visible = false;
    var wasOpen = false;
    var statusBadge = null;
    var footerEl = null;

    var ua = null;
    var currentSession = null;
    var currentLinkedId = '';
    var currentIncoming = false;
    var answered = false;
    var remoteAudio = null;
    var callTimerIv = null;
    var callStartedAt = null;
    var muted = false;
    var myExtension = null;   // {bound, extension, label, sip_username, sip_password, wss_url, general_number, register_scope}
    var eligible = null;      // null until the settings payload arrives
    var registrationStatus = { cls: 'disconnected', text: 'Not registered' };
    var electionIv = null;
    var electionWired = false;
    var bannerWired = false;
    var flashIv = null;
    var baseTitle = '';

    var dragState = { active: false, startX: 0, startY: 0, origLeft: 0, origTop: 0 };
    var resizeState = { active: false, startX: 0, startY: 0, origW: 0, origH: 0 };

    // ── Small helpers ───────────────────────────────────────────────────
    function storageGet(key) {
        try { return window.localStorage.getItem(key); } catch (e) { return null; }
    }
    function storageSet(key, value) {
        try { window.localStorage.setItem(key, value); } catch (e) { /* private window */ }
    }
    function storageRemove(key) {
        try { window.localStorage.removeItem(key); } catch (e) { /* private window */ }
    }

    // ── Workstation token (Phase 152's own convention) ──────────────────
    // console-workstation.js is loaded by inc/navbar.php before this file, on
    // every page (Phase 155: it used to load only on console.php, which is why
    // the widget showed an empty token everywhere else).
    function getWorkstationToken() {
        if (!Logic) { return ''; }
        return Logic.resolveToken(function () {
            return (window.ConsoleWorkstation && window.ConsoleWorkstation.getToken)
                ? window.ConsoleWorkstation.getToken() : '';
        });
    }

    function csrfToken() {
        if (window.PHONE_CSRF) { return window.PHONE_CSRF; }
        if (window.CALL_ALERT_CSRF) { return window.CALL_ALERT_CSRF; }
        var m = document.querySelector('meta[name="csrf-token"]');
        return m ? (m.getAttribute('content') || '') : '';
    }

    function postJson(url, body) {
        body = body || {};
        body.csrf_token = csrfToken();
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        }).then(function (r) { return r.json(); }).catch(function () { return null; });
    }

    // ── Status / footer ──────────────────────────────────────────────
    // The footer is also the only place a failure is explained, so it is an
    // aria-live region (see the template) and carries the full sentence.
    function setStatus(cls, text) {
        if (statusBadge) {
            statusBadge.className = 'phone-status-badge status-' + cls;
        }
        if (footerEl) {
            footerEl.textContent = text;
            footerEl.title = text;
        }
    }

    /** A registration-related status: remembered so a finished call can restore it. */
    function setRegistrationStatus(cls, text) {
        registrationStatus = { cls: cls, text: text };
        if (!currentSession) { setStatus(cls, text); }
    }

    function restoreRegistrationStatus() {
        setStatus(registrationStatus.cls, registrationStatus.text);
    }

    // ── Fetch my extension + decide what to show ────────────────────────
    function loadMyExtension(cb) {
        var token = getWorkstationToken();
        fetch('api/phone-extensions.php?action=my_extension&workstation_token=' + encodeURIComponent(token), {
            credentials: 'same-origin'
        }).then(function (r) {
            if (!r.ok) { throw new Error('HTTP ' + r.status); }
            return r.json();
        }).then(function (data) {
            myExtension = data || null;
            if (cb) { cb(myExtension); }
        }).catch(function (err) {
            myExtension = null;
            setRegistrationStatus('disconnected',
                'Could not load the phone settings from the server (' + (err && err.message ? err.message : 'network error')
                + '). Check your connection or sign in again.');
        });
    }

    function showElsewhere(reason) {
        var panel = widget.querySelector('#phoneElsewherePanel');
        var text = widget.querySelector('#phoneElsewhereText');
        if (text) {
            text.textContent = reason === 'other'
                ? 'The phone is active in another window of this browser.'
                : 'The phone is active in your Console or Phone window.';
        }
        panel.classList.remove('d-none');
        widget.querySelector('#phoneUnboundPanel').classList.add('d-none');
        widget.querySelector('#phoneBody').classList.add('d-none');
        setStatus('disconnected', reason === 'other'
            ? 'Another window of this browser is handling your calls.'
            : 'Calls are handled in your Console or Phone window.');
    }

    function hideElsewhere() {
        widget.querySelector('#phoneElsewherePanel').classList.add('d-none');
    }

    function renderBoundState() {
        var unbound = widget.querySelector('#phoneUnboundPanel');
        var body = widget.querySelector('#phoneBody');
        var extLabel = widget.querySelector('.phone-header-extension');
        var generalBtn = widget.querySelector('#phoneGeneralBtn');
        var generalNum = widget.querySelector('#phoneGeneralNum');

        hideElsewhere();
        if (!myExtension || !myExtension.bound) {
            unbound.classList.remove('d-none');
            body.classList.add('d-none');
            setRegistrationStatus('disconnected', 'Not bound to an extension');
            var tokenInput = widget.querySelector('#phoneMyToken');
            if (tokenInput) { tokenInput.value = getWorkstationToken(); }
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

    /** Decide, from the settings payload, what this page's widget does. */
    function applyState() {
        if (!Logic) {
            setRegistrationStatus('disconnected', 'The phone library failed to load. Reload the page.');
            return;
        }
        var scope = Logic.normalizeScope(myExtension ? myExtension.register_scope : null);
        eligible = Logic.shouldRegister(scope, pageName, STANDALONE);

        if (!eligible) {
            stopElection();
            stopRegistration();
            showElsewhere('page');
            return;
        }
        renderBoundState();
        if (myExtension && myExtension.bound) {
            if (!myExtension.wss_url) {
                setRegistrationStatus('disconnected',
                    'The PBX connection is not configured yet. Ask an administrator to set the PBX WebSocket URL.');
                return;
            }
            startElection();
            // Keyboard-first: in the Phone window the first thing an operator does is
            // type a number, so the number box is ready without a click.
            var dialBox = widget.querySelector('#phoneDialInput');
            if (STANDALONE && dialBox && dialBox.focus) { try { dialBox.focus(); } catch (e) { /* not focusable yet */ } }
        }
    }

    // ── Single-registrant election (one window of a browser registers) ──
    function electionTick() {
        if (!eligible || !myExtension || !myExtension.bound || !myExtension.wss_url) { return; }
        var now = Date.now();
        var rec = Logic.parseRegistrant(storageGet(Logic.REGISTRANT_KEY));
        var decision = Logic.registrantDecide(rec, myId, STANDALONE, now);
        if (decision === 'register') {
            storageSet(Logic.REGISTRANT_KEY, Logic.serializeRegistrant(myId, STANDALONE, now));
            if (widget.querySelector('#phoneElsewherePanel').classList.contains('d-none') === false) {
                renderBoundState();
                restoreRegistrationStatus();
            }
            if (!ua) { startRegistration(); }
        } else {
            if (currentSession) { return; } // never tear down a live call
            if (ua) { stopRegistration(); }
            showElsewhere('other');
        }
    }

    function startElection() {
        electionTick();
        if (!electionIv) {
            electionIv = setInterval(electionTick, Logic.REGISTRANT_HEARTBEAT_MS);
        }
        if (!electionWired) {
            electionWired = true;
            window.addEventListener('storage', function (e) {
                if (e && e.key === Logic.REGISTRANT_KEY) { electionTick(); }
            });
            window.addEventListener('pagehide', function () {
                releaseRegistrant();
                if (ua) { try { ua.stop(); } catch (err) { /* page is going away */ } }
            });
        }
    }

    function stopElection() {
        if (electionIv) { clearInterval(electionIv); electionIv = null; }
        releaseRegistrant();
    }

    function releaseRegistrant() {
        if (!Logic) { return; }
        var rec = Logic.parseRegistrant(storageGet(Logic.REGISTRANT_KEY));
        if (rec && rec.id === myId) { storageRemove(Logic.REGISTRANT_KEY); }
    }

    // ── Registration ────────────────────────────────────────────────────
    function stopRegistration() {
        if (ua) {
            try { ua.stop(); } catch (e) { /* already stopped */ }
            ua = null;
        }
    }

    function startRegistration() {
        if (!myExtension || !myExtension.bound || !myExtension.wss_url) {
            setRegistrationStatus('disconnected', myExtension && myExtension.bound
                ? 'The PBX connection is not configured yet. Ask an administrator to set the PBX WebSocket URL.'
                : 'Not bound to an extension');
            return;
        }
        if (typeof window.JsSIP === 'undefined') {
            setRegistrationStatus('disconnected', 'The phone library failed to load. Reload the page.');
            return;
        }
        stopRegistration();

        setRegistrationStatus('connecting', 'Connecting…');

        var hostMatch = /^wss:\/\/([^/]+)/i.exec(myExtension.wss_url);
        var host = hostMatch ? hostMatch[1].split(':')[0] : 'localhost';
        var wssUrl = myExtension.wss_url;

        var thisUa = null;
        try {
            var socket = new window.JsSIP.WebSocketInterface(wssUrl);
            var config = {
                sockets: [socket],
                uri: 'sip:' + myExtension.sip_username + '@' + host,
                password: myExtension.sip_password,
                display_name: myExtension.label,
                register: true,
                session_timers: false
            };
            thisUa = new window.JsSIP.UA(config);
            ua = thisUa;
        } catch (e) {
            ua = null;
            setRegistrationStatus('disconnected',
                'The phone settings are not valid (' + (e && e.message ? e.message : 'could not start') + '). '
                + 'Ask an administrator to check the PBX WebSocket URL and this extension.');
            return;
        }

        // A user agent that has been stopped (this window yielded, or re-registered)
        // finishes its own unregister/disconnect asynchronously and fires events
        // AFTER a newer one may be running; those must not overwrite the status.
        function current() { return ua === thisUa; }
        thisUa.on('connecting', function () { if (current()) { setRegistrationStatus('connecting', 'Connecting…'); } });
        thisUa.on('registered', function () {
            if (current()) { setRegistrationStatus('connected', 'Registered as ' + myExtension.extension); }
        });
        thisUa.on('unregistered', function () { if (current()) { setRegistrationStatus('disconnected', 'Unregistered'); } });
        thisUa.on('registrationFailed', function (e) {
            if (current()) { setRegistrationStatus('disconnected', Logic.describeRegistrationFailure(e && e.cause, wssUrl)); }
        });
        thisUa.on('disconnected', function () {
            if (current()) { setRegistrationStatus('disconnected', Logic.describeDisconnect(wssUrl)); }
        });
        thisUa.on('newRTCSession', function (e) {
            if (current()) { handleNewSession(e.session, e.originator, Logic.linkedIdFromEvent(e)); }
        });

        thisUa.start();
    }

    // ── Call session handling ────────────────────────────────────────
    function micBlocker() {
        return Logic.describeMicBlocker(
            window.isSecureContext,
            !!(window.navigator && window.navigator.mediaDevices && window.navigator.mediaDevices.getUserMedia)
        );
    }

    function pcConfig() {
        return { iceServers: [] };
    }

    function handleNewSession(session, originator, linkedId) {
        if (currentSession) {
            // Already on a call -- politely reject a second incoming leg.
            if (originator === 'remote') {
                try { session.terminate({ status_code: 486, reason_phrase: 'Busy Here' }); } catch (e) { /* gone */ }
            }
            return;
        }
        currentSession = session;
        currentLinkedId = '';
        currentIncoming = (originator === 'remote');
        answered = false;

        session.on('peerconnection', function (e) {
            e.peerconnection.addEventListener('track', function (ev) {
                if (remoteAudio && ev.streams && ev.streams[0]) {
                    remoteAudio.srcObject = ev.streams[0];
                    var p = remoteAudio.play();
                    if (p && p.catch) { p.catch(function () { /* autoplay policy: the user's click already unlocked it */ }); }
                }
            });
        });

        if (currentIncoming) {
            currentLinkedId = linkedId || '';
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
        session.on('ended', function () { endCall(null); });
        session.on('failed', function (e) {
            endCall(Logic.describeCallFailure(e && e.cause));
        });
    }

    function showIncoming(fromLabel) {
        widget.querySelector('#phoneIncomingFrom').textContent = fromLabel;
        widget.querySelector('#phoneIncoming').classList.remove('d-none');
        widget.querySelector('#phoneDialpad').classList.add('d-none');
        setStatus('in-call', 'Incoming call from ' + fromLabel);
        startRingFlash();
        if (!STANDALONE && !visible) { try { show(); } catch (e) { /* not ready */ } }
        // Keyboard-first: Enter/Space answers without reaching for the mouse.
        var answerBtn = widget.querySelector('#phoneAnswerBtn');
        if (answerBtn && answerBtn.focus) { try { answerBtn.focus(); } catch (e) { /* hidden */ } }
    }

    function hideIncoming() {
        widget.querySelector('#phoneIncoming').classList.add('d-none');
        stopRingFlash();
    }

    function showInCall(peerLabel) {
        widget.querySelector('#phoneIncallPeer').textContent = peerLabel || '—';
        widget.querySelector('#phoneIncall').classList.remove('d-none');
        widget.querySelector('#phoneDialpad').classList.add('d-none');
    }

    function startCallTimer() {
        callStartedAt = Date.now();
        if (callTimerIv) { clearInterval(callTimerIv); }
        callTimerIv = setInterval(function () {
            var secs = Math.floor((Date.now() - callStartedAt) / 1000);
            var m = Math.floor(secs / 60);
            var s = secs % 60;
            widget.querySelector('#phoneIncallTimer').textContent =
                (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
        }, 500);
    }

    // A document-title flash for the standalone window: it may be behind
    // other windows, and the title is what the taskbar shows.
    function startRingFlash() {
        if (!STANDALONE || flashIv) { return; }
        baseTitle = document.title;
        var on = false;
        flashIv = setInterval(function () {
            on = !on;
            document.title = on ? '(Incoming call) ' + baseTitle : baseTitle;
        }, 800);
    }
    function stopRingFlash() {
        if (flashIv) { clearInterval(flashIv); flashIv = null; }
        if (baseTitle) { document.title = baseTitle; }
    }

    /**
     * Tear the call UI down. The message, when given, is a plain-English reason
     * that stays in the footer (a normal hang-up passes null and the
     * registration status comes back).
     */
    function endCall(message) {
        if (callTimerIv) { clearInterval(callTimerIv); callTimerIv = null; }
        stopRingFlash();
        currentSession = null;
        currentLinkedId = '';
        currentIncoming = false;
        answered = false;
        muted = false;
        if (remoteAudio) { remoteAudio.srcObject = null; }
        widget.querySelector('#phoneIncoming').classList.add('d-none');
        widget.querySelector('#phoneIncall').classList.add('d-none');
        widget.querySelector('#phoneDialpad').classList.remove('d-none');
        widget.querySelector('#phoneDialInput').value = '';
        widget.querySelector('#phoneIncallTimer').textContent = '00:00';
        var muteBtn = widget.querySelector('#phoneMuteCallBtn');
        if (muteBtn) {
            muteBtn.setAttribute('aria-pressed', 'false');
            muteBtn.innerHTML = '<i class="bi bi-mic-fill"></i>';
        }
        if (message) {
            setStatus(ua && ua.isRegistered && ua.isRegistered() ? 'connected' : 'disconnected', message);
        } else {
            restoreRegistrationStatus();
        }
        // A window that was told to yield mid-call can do so now.
        if (eligible) { electionTick(); }
        var dial = widget.querySelector('#phoneDialInput');
        if (dial && dial.focus && visible) { try { dial.focus(); } catch (e) { /* hidden */ } }
    }

    function placeCall(number) {
        number = String(number || '').trim();
        if (!number) { return; }
        if (!Logic) { return; }
        if (eligible === false) {
            // This page does not hold the registration: the call belongs in the
            // Phone window, which can actually place it.
            openPhoneWindow(null);
            return;
        }
        if (currentSession) {
            setStatus('in-call', 'Finish or hang up the current call first.');
            return;
        }
        if (!ua || !ua.isRegistered || !ua.isRegistered()) {
            setStatus('disconnected', 'The phone is not registered with the PBX yet, so a call cannot be placed. '
                + registrationStatus.text);
            return;
        }
        var blocker = micBlocker();
        if (blocker) { setStatus('disconnected', blocker); return; }
        try {
            ua.call(number, {
                mediaConstraints: { audio: true, video: false },
                pcConfig: pcConfig()
            });
        } catch (e) {
            setStatus('connected', 'The call could not be started (' + (e && e.message ? e.message : 'error') + ').');
        }
    }

    // ── Answering: one click, either place ───────────────────────────────
    function answerCurrent(source) {
        var s = currentSession;
        if (!s || answered || !currentIncoming) { return false; }
        var blocker = micBlocker();
        if (blocker) { setStatus('disconnected', blocker); return false; }
        answered = true;
        try {
            s.answer({ mediaConstraints: { audio: true, video: false }, pcConfig: pcConfig() });
        } catch (e) {
            answered = false;
            setStatus('disconnected', 'The call could not be answered (' + (e && e.message ? e.message : 'error') + ').');
            return false;
        }
        // Answering in the widget also claims the matching Phase 149 call, so
        // the dispatcher does not have to click Answer a second time in the
        // banner. A banner Answer already claimed it -- never claim twice.
        if (source !== 'banner' && currentLinkedId) { claimForAnswer(currentLinkedId, 0); }
        return true;
    }

    function claimForAnswer(linkedId, attempt) {
        postJson(CLAIM_URL, { provider_call_id: linkedId }).then(function (res) {
            if (res && res.success && res.call && res.call.id) {
                // A NEW tab, same as the banner's Answer: never navigate away
                // from whatever the dispatcher was doing.
                window.open('new-incident.php?call_id=' + encodeURIComponent(res.call.id), '_blank');
                return;
            }
            // Not found yet: the bridge's "ringing" can trail the INVITE by a
            // moment. Try once more. Every other outcome (already yours,
            // someone else's, ended, bridge not running, network) leaves the
            // phone call itself untouched -- audio never depends on this.
            if (res && res.reason === 'not_found' && !attempt) {
                setTimeout(function () { claimForAnswer(linkedId, 1); }, CLAIM_RETRY_MS);
            }
        });
    }

    /** A banner Answer on any page of this browser answers the matching leg here. */
    function listenForBannerAnswer() {
        if (bannerWired) { return; }
        bannerWired = true;
        function onMessage(msg) {
            if (!msg || msg.type !== 'answer' || !msg.provider_call_id) { return; }
            if (currentSession && currentIncoming && !answered && currentLinkedId
                && String(msg.provider_call_id) === currentLinkedId) {
                answerCurrent('banner');
            }
        }
        try {
            if (typeof window.BroadcastChannel === 'function') {
                var bc = new window.BroadcastChannel(BANNER_CHANNEL);
                bc.onmessage = function (e) { onMessage(e && e.data); };
            }
        } catch (e) { /* no BroadcastChannel: the same-page event below still works */ }
        window.addEventListener('ticketscad:phone-answer', function (e) { onMessage(e && e.detail); });
    }

    // ── The Phone window ─────────────────────────────────────────────────
    function openPhoneWindow(ev) {
        var w = null;
        try { w = window.open(WINDOW_URL, WINDOW_NAME, WINDOW_FEATURES); } catch (e) { w = null; }
        if (w) {
            try { w.focus(); } catch (e2) { /* cross-window focus can be refused */ }
            if (ev && ev.preventDefault) { ev.preventDefault(); }
            return true;
        }
        // Pop-up blocked. An anchor falls back to its own href/target; a button
        // has nothing to fall back to, so say what happened.
        if (!ev || !ev.currentTarget || ev.currentTarget.tagName !== 'A') {
            setStatus('disconnected', 'The browser blocked the Phone window. Allow pop-ups for this site, then try again.');
        }
        return false;
    }

    // ── Init ─────────────────────────────────────────────────────────
    function init() {
        var tpl = document.getElementById('tpl-phone-widget');
        if (!tpl) { return; }

        var clone = tpl.content ? tpl.content.cloneNode(true) : tpl.cloneNode(true);
        widget = clone.querySelector('.phone-widget');
        if (!widget) { return; }
        document.body.appendChild(widget);

        statusBadge = widget.querySelector('.phone-status-badge');
        footerEl = widget.querySelector('#phoneFooter');

        remoteAudio = document.createElement('audio');
        remoteAudio.autoplay = true;
        remoteAudio.style.display = 'none';
        document.body.appendChild(remoteAudio);

        if (STANDALONE) {
            widget.classList.add('phone-standalone');
            widget.classList.remove('phone-hidden');
            visible = true;
        } else {
            wasOpen = (storageGet('phone_widget_open') === '1');
            var saved = loadPosition();
            if (saved) {
                widget.style.left = saved.left + 'px';
                widget.style.top = saved.top + 'px';
                if (saved.width) { widget.style.width = saved.width + 'px'; }
                if (saved.height) { widget.style.height = saved.height + 'px'; }
            } else {
                widget.style.right = '20px';
                widget.style.bottom = '20px';
            }
            widget.classList.add('phone-hidden');
        }

        attachListeners();
        listenForBannerAnswer();

        loadMyExtension(function () {
            applyState();
        });

        function bindToggle() {
            if (typeof EventBus !== 'undefined' && EventBus && EventBus.on) {
                EventBus.on('phone:toggle', function () { toggleOrOpen(); });
                return true;
            }
            return false;
        }
        if (!STANDALONE && !bindToggle()) {
            var tries = 0;
            var iv = setInterval(function () {
                if (bindToggle() || ++tries > 40) { clearInterval(iv); }
            }, 50);
        }

        if (!STANDALONE && wasOpen) {
            try { show(); } catch (e) { /* not ready */ }
        }
    }

    function attachListeners() {
        var header = widget.querySelector('.phone-header');
        if (!STANDALONE) {
            header.addEventListener('mousedown', function (e) {
                if (e.target.closest('.phone-header-actions')) { return; }
                startDrag(e);
            });
            document.addEventListener('mousemove', function (e) {
                if (dragState.active) { onDrag(e); }
                if (resizeState.active) { onResize(e); }
            });
            document.addEventListener('mouseup', function () {
                if (dragState.active) { stopDrag(); }
                if (resizeState.active) { stopResize(); }
            });
            var resizeHandle = widget.querySelector('.phone-resize-handle');
            if (resizeHandle) { resizeHandle.addEventListener('mousedown', startResize); }

            widget.querySelector('#phoneMinimize').addEventListener('click', function () { hide(); });
            widget.querySelector('#phoneClose').addEventListener('click', function () { hide(); });
        }

        var openBtn = widget.querySelector('#phoneOpenWindow');
        if (openBtn) { openBtn.addEventListener('click', function (e) { openPhoneWindow(e); }); }
        var elsewhereLink = widget.querySelector('#phoneElsewhereLink');
        if (elsewhereLink) { elsewhereLink.addEventListener('click', function (e) { openPhoneWindow(e); }); }

        var detachBtn = widget.querySelector('#phoneDetach');
        var detachHandle = null;
        if (detachBtn && window.WindowDetach && !STANDALONE) {
            detachBtn.addEventListener('click', function () {
                if (detachHandle) { return; }
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
                try { document.execCommand('copy'); } catch (e) { /* the text is selected; Ctrl+C still works */ }
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
            if (e.key === 'Enter') { placeCall(dialInput.value); }
        });
        widget.querySelector('#phoneGeneralBtn').addEventListener('click', function () {
            if (myExtension && myExtension.general_number) { placeCall(myExtension.general_number); }
        });

        // Incoming
        widget.querySelector('#phoneAnswerBtn').addEventListener('click', function () {
            answerCurrent('widget');
        });
        widget.querySelector('#phoneDeclineBtn').addEventListener('click', function () {
            if (currentSession) { try { currentSession.terminate(); } catch (e) { /* already over */ } }
        });

        // In-call
        widget.querySelector('#phoneHangupBtn').addEventListener('click', function () {
            if (currentSession) { try { currentSession.terminate(); } catch (e) { /* already over */ } }
        });
        widget.querySelector('#phoneMuteCallBtn').addEventListener('click', function (e) {
            if (!currentSession) { return; }
            muted = !muted;
            try {
                if (muted) { currentSession.mute({ audio: true }); }
                else { currentSession.unmute({ audio: true }); }
            } catch (err) { /* session ended under us */ }
            e.currentTarget.setAttribute('aria-pressed', muted ? 'true' : 'false');
            e.currentTarget.setAttribute('aria-label', muted ? 'Unmute microphone' : 'Mute microphone');
            e.currentTarget.innerHTML = muted
                ? '<i class="bi bi-mic-mute-fill"></i>'
                : '<i class="bi bi-mic-fill"></i>';
        });
    }

    // ── Show / hide / toggle ─────────────────────────────────────────
    function show() {
        if (!widget) { return; }
        widget.classList.remove('phone-hidden');
        if (!STANDALONE) { storageSet('phone_widget_open', '1'); }
        visible = true;
    }
    function hide() {
        if (!widget || STANDALONE) { return; }
        widget.classList.add('phone-hidden');
        storageSet('phone_widget_open', '0');
        visible = false;
    }
    function toggle() { if (visible) { hide(); } else { show(); } }

    /** The navbar button: on a page that does not hold the registration, go
     *  straight to the Phone window (the thing that can actually place calls). */
    function toggleOrOpen() {
        if (STANDALONE) { return; }
        if (eligible === false) { openPhoneWindow(null); return; }
        toggle();
    }

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
            window.localStorage.setItem('phone_widget_pos', JSON.stringify({
                left: Math.round(rect.left),
                top: Math.round(rect.top),
                width: widget.offsetWidth,
                height: widget.offsetHeight
            }));
        } catch (e) { /* private window */ }
    }
    function loadPosition() {
        try {
            var raw = window.localStorage.getItem('phone_widget_pos');
            if (raw) { return JSON.parse(raw); }
        } catch (e) { /* corrupt or blocked: use the default corner */ }
        return null;
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    window.PhoneWidget = {
        show: show,
        hide: hide,
        toggle: toggle,
        toggleOrOpen: toggleOrOpen,
        placeCall: placeCall,
        openWindow: function () { return openPhoneWindow(null); },
        /** true / false once the settings payload arrived, null before. */
        registersHere: function () { return eligible; }
    };
})();
