/**
 * NewUI v4.0 — browser phone: DOM-free logic (Phase 155, GH#108 slices S1/S4).
 *
 * No DOM, no fetch, no JsSIP reference — deliberately, so tests can drive it
 * under Node without a browser (tests/test_phone_dial_logic.php), the same
 * technique console-audio-logic.js uses. assets/js/phone-widget.js is the
 * DOM/network/JsSIP glue layer that calls into this file.
 *
 * What lives here:
 *   - WHERE the phone registers: the install-wide phone_register_scope
 *     setting ('phone_page' = only the Console and the standalone Phone
 *     window, 'every_page') and the page-name rule that implements it.
 *   - WHO registers: a tiny single-registrant election over a
 *     localStorage-shaped record, so the Console tab and the Phone window of
 *     ONE browser never both register the same extension (a double
 *     registration rings twice and answers twice). The standalone Phone
 *     window outranks every other window; a registrant that stops
 *     heart-beating goes stale and another window takes over.
 *   - Which PBX leg this INVITE is (the X-Call-Linkedid header the reference
 *     dial plan sets), so answering in the widget can also claim the
 *     matching Phase 149 call.
 *   - Plain-English descriptions of JsSIP failure causes, so "Connection
 *     Error" reads as what an administrator or dispatcher can act on.
 *
 * ES5 IIFE — no arrow functions, no let/const, no template literals.
 * (Loads in a browser as window.PhoneDialLogic and under Node via require.)
 */
(function (root) {
    'use strict';

    // Pages whose widget registers when phone_register_scope = 'phone_page'.
    var REGISTER_PAGES = ['console.php', 'phone.php'];

    var REGISTRANT_KEY = 'ticketscad_phone_registrant';
    var REGISTRANT_STALE_MS = 15000;
    var REGISTRANT_HEARTBEAT_MS = 4000;

    // The SIP header the reference dial plan adds to the leg that rings a
    // browser, carrying the PBX's Linkedid so the widget can find the
    // matching inbound_calls row (docs/PHONE-TELEPHONY-GUIDE.md).
    var LINKEDID_HEADER = 'X-Call-Linkedid';

    function normalizeScope(value) {
        return value === 'every_page' ? 'every_page' : 'phone_page';
    }

    /** 'console.php' from '/newui/console.php?x=1'; '' or '/' -> 'index.php'. */
    function pageNameFromPath(pathname) {
        var p = String(pathname || '');
        var q = p.indexOf('?');
        if (q !== -1) { p = p.slice(0, q); }
        var h = p.indexOf('#');
        if (h !== -1) { p = p.slice(0, h); }
        var parts = p.split('/');
        var last = parts[parts.length - 1] || '';
        if (last === '') { return 'index.php'; }
        return last.toLowerCase();
    }

    /**
     * May the widget on this page try to register?
     * @param scope       'phone_page' | 'every_page' (anything else = phone_page)
     * @param pageName    result of pageNameFromPath()
     * @param standalone  true inside phone.php (always registers)
     */
    function shouldRegister(scope, pageName, standalone) {
        if (standalone) { return true; }
        if (normalizeScope(scope) === 'every_page') { return true; }
        return REGISTER_PAGES.indexOf(String(pageName || '').toLowerCase()) !== -1;
    }

    /** Read the workstation token through a getter that may throw or be absent. */
    function resolveToken(getter) {
        try {
            if (typeof getter === 'function') {
                var t = getter();
                return (t === null || t === undefined) ? '' : String(t).trim();
            }
        } catch (e) { /* fall through */ }
        return '';
    }

    // ── Single-registrant election ─────────────────────────────────────

    function parseRegistrant(raw) {
        if (!raw) { return null; }
        try {
            var r = JSON.parse(raw);
            if (r && typeof r.id === 'string' && typeof r.at === 'number') {
                return { id: r.id, at: r.at, standalone: !!r.standalone };
            }
        } catch (e) { /* corrupt value = no registrant */ }
        return null;
    }

    function serializeRegistrant(id, standalone, now) {
        return JSON.stringify({ id: id, standalone: !!standalone, at: now });
    }

    /**
     * Should THIS window hold the registration right now?
     *   no record, a stale record, or my own record  -> 'register'
     *   a fresh record held by someone else:
     *       I am the standalone Phone window and they are not -> 'register' (take over)
     *       otherwise -> 'yield'
     */
    function registrantDecide(record, myId, myStandalone, now) {
        if (!record) { return 'register'; }
        if (record.id === myId) { return 'register'; }
        if (now - record.at >= REGISTRANT_STALE_MS) { return 'register'; }
        if (myStandalone && !record.standalone) { return 'register'; }
        return 'yield';
    }

    // ── Correlating the widget's INVITE with a Phase 149 call ──────────

    /**
     * The PBX Linkedid carried by an incoming INVITE, or '' when absent/invalid.
     * `request` is the INVITE itself as JsSIP hands it over in the newRTCSession
     * event (e.request) -- an object with getHeader(name). That event is the
     * DOCUMENTED way to reach the INVITE: an RTCSession does not publish its
     * request as a property (a first draft read session.request, which exists
     * only on the test double and would have found nothing in a real browser).
     */
    function linkedIdFromRequest(request) {
        try {
            if (!request || typeof request.getHeader !== 'function') { return ''; }
            var v = request.getHeader(LINKEDID_HEADER);
            if (v === undefined || v === null) { return ''; }
            v = String(v).trim();
            // Asterisk Linkedids look like 1759421234.12; accept only a short,
            // boring token so a hostile header cannot smuggle anything into
            // the claim request.
            return /^[0-9A-Za-z._:-]{1,64}$/.test(v) ? v : '';
        } catch (e) {
            return '';
        }
    }

    /** The id from the newRTCSession event's request, falling back to anything
     *  the session itself exposes. */
    function linkedIdFromEvent(event) {
        var id = linkedIdFromRequest(event ? event.request : null);
        if (id) { return id; }
        var session = event ? event.session : null;
        return linkedIdFromRequest(session && (session.request || session._request));
    }

    // ── Plain-English failure text ─────────────────────────────────────

    /** The one-time-trust address for a WSS URL: wss://h:8089/ws -> https://h:8089/ */
    function certUrl(wssUrl) {
        return String(wssUrl || '').replace(/^wss:\/\//i, 'https://').replace(/\/ws\/?$/i, '/');
    }

    /** Why registration failed, in words an administrator can act on. */
    function describeRegistrationFailure(cause, wssUrl) {
        var c = String(cause || '');
        if (c === 'Authentication Error' || c === 'Rejected') {
            return 'The PBX rejected this extension\'s password. An administrator must make the password on the '
                 + 'Phone Extensions page match the PBX endpoint.';
        }
        if (c === 'Connection Error' || c === 'Request Timeout') {
            return 'Cannot reach the PBX. If this is the first time this browser has connected, open '
                 + certUrl(wssUrl) + ' once and accept the certificate; otherwise check the PBX address and your network.';
        }
        if (c === 'Not Found' || c === 'Unavailable') {
            return 'The PBX does not know this extension. Check that it exists on the PBX with the same number.';
        }
        return 'Registration failed (' + (c || 'unknown reason') + '). Ask an administrator to check the PBX connection.';
    }

    /** A dropped WebSocket (the PBX went away or the network did). */
    function describeDisconnect(wssUrl) {
        return 'Lost the connection to the PBX. Reconnecting automatically; if this stays, check the PBX address '
             + '(' + String(wssUrl || '') + '), the certificate and your network.';
    }

    /** Why a call (placed or answered) ended abnormally. */
    function describeCallFailure(cause) {
        var c = String(cause || '');
        if (c === 'User Denied Media Access') {
            return 'Microphone blocked. Allow microphone access for this site (the lock icon beside the address), then try again.';
        }
        if (c === 'WebRTC Error' || c === 'Bad Media Description' || c === 'Incompatible SDP') {
            return 'The call could not set up audio (' + c + '). Check the microphone, the PBX media settings, and STUN/TURN if '
                 + 'this browser is on a different network than the PBX.';
        }
        if (c === 'Busy') { return 'Busy.'; }
        if (c === 'Rejected') { return 'The call was rejected (not permitted, or declined).'; }
        if (c === 'Not Found') { return 'That number does not exist on the PBX.'; }
        if (c === 'Unavailable') { return 'That number is unavailable (not registered).'; }
        if (c === 'No Answer' || c === 'Request Timeout') { return 'No answer.'; }
        if (c === 'Connection Error') { return 'The PBX connection dropped during the call.'; }
        if (c === 'Canceled') { return 'Call cancelled.'; }
        if (c === 'Terminated' || c === 'BYE') { return 'Call ended.'; }
        if (c === 'RTP Timeout') { return 'Audio stopped arriving, so the call was ended.'; }
        return 'Call ended: ' + (c || 'call failed') + '.';
    }

    /** Why the microphone cannot be used at all, before any call is tried. */
    function describeMicBlocker(isSecureContext, hasMediaDevices) {
        if (isSecureContext === false) {
            return 'Calls need a secure page: this site is open over plain http://, so the browser will not allow '
                 + 'the microphone. Use the https:// address (or ask an administrator to enable HTTPS).';
        }
        if (hasMediaDevices === false) {
            return 'This browser cannot access a microphone (no media devices). Try a current Chrome, Edge or Firefox.';
        }
        return '';
    }

    var api = {
        REGISTER_PAGES: REGISTER_PAGES,
        REGISTRANT_KEY: REGISTRANT_KEY,
        REGISTRANT_STALE_MS: REGISTRANT_STALE_MS,
        REGISTRANT_HEARTBEAT_MS: REGISTRANT_HEARTBEAT_MS,
        LINKEDID_HEADER: LINKEDID_HEADER,
        normalizeScope: normalizeScope,
        pageNameFromPath: pageNameFromPath,
        shouldRegister: shouldRegister,
        resolveToken: resolveToken,
        parseRegistrant: parseRegistrant,
        serializeRegistrant: serializeRegistrant,
        registrantDecide: registrantDecide,
        linkedIdFromRequest: linkedIdFromRequest,
        linkedIdFromEvent: linkedIdFromEvent,
        certUrl: certUrl,
        describeRegistrationFailure: describeRegistrationFailure,
        describeDisconnect: describeDisconnect,
        describeCallFailure: describeCallFailure,
        describeMicBlocker: describeMicBlocker
    };

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    } else {
        root.PhoneDialLogic = api;
    }
})(typeof window !== 'undefined' ? window : this);
