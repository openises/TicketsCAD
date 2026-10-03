'use strict';
/**
 * Shared Node/jsdom harness for the Phase 155 phone tests (GH#108 S1-S5).
 *
 * Boots the REAL inc/phone-widget-template.php markup and the REAL
 * assets/js/{console-workstation,phone-dial-logic,phone-widget}.js in jsdom
 * against a FAKE JsSIP, a fake fetch and a fake BroadcastChannel. There is no
 * PBX here: everything that touches a real SIP server (registration, audio,
 * INVITE routing) is simulated, and every test that uses this file says so.
 *
 * File name starts with `_` and is not .php, so tools/test_all.php never runs
 * it as a test; tests/_phone_*_node.js scripts require it.
 */
var fs = require('fs');
var path = require('path');
var JSDOM = require('jsdom').JSDOM;

function makeFakeJsSIP() {
    var J = { version: 'fake', uas: [], calls: [], throwOnUa: null };

    J.WebSocketInterface = function (url) {
        if (!/^wss?:\/\//i.test(String(url))) { throw new Error('Invalid url: ' + url); }
        this.url = url;
    };

    function FakeSession(originator, peer, headers, displayName) {
        this.originator = originator;
        this.handlers = {};
        this.answerCalls = [];
        this.terminated = null;
        this.muted = false;
        this.remote_identity = { uri: { user: peer }, display_name: displayName || '' };
        var hdrs = headers || {};
        // Like the real JsSIP, the session does NOT expose its INVITE as a
        // property: the request is only available on the newRTCSession event.
        this._inviteRequest = { getHeader: function (name) {
            var k = Object.keys(hdrs).filter(function (x) { return x.toLowerCase() === String(name).toLowerCase(); })[0];
            return k === undefined ? undefined : hdrs[k];
        } };
    }
    FakeSession.prototype.on = function (ev, fn) { (this.handlers[ev] = this.handlers[ev] || []).push(fn); };
    FakeSession.prototype.emit = function (ev, data) { (this.handlers[ev] || []).forEach(function (fn) { fn(data || {}); }); };
    FakeSession.prototype.answer = function (opts) { this.answerCalls.push(opts); };
    FakeSession.prototype.terminate = function (opts) { this.terminated = opts || {}; this.emit('ended', {}); };
    FakeSession.prototype.mute = function () { this.muted = true; };
    FakeSession.prototype.unmute = function () { this.muted = false; };
    J.FakeSession = FakeSession;

    J.UA = function (config) {
        if (J.throwOnUa) { throw new Error(J.throwOnUa); }
        this.config = config;
        this.handlers = {};
        this.registered = false;
        this.started = false;
        this.stopped = false;
        J.uas.push(this);
    };
    J.UA.prototype.on = function (ev, fn) { (this.handlers[ev] = this.handlers[ev] || []).push(fn); };
    J.UA.prototype.emit = function (ev, data) { (this.handlers[ev] || []).forEach(function (fn) { fn(data || {}); }); };
    J.UA.prototype.start = function () { this.started = true; };
    J.UA.prototype.stop = function () { this.stopped = true; this.registered = false; };
    J.UA.prototype.isRegistered = function () { return this.registered; };
    J.UA.prototype.call = function (target, opts) {
        var peer = String(target).replace(/^sip:/, '').split('@')[0];
        var s = new FakeSession('local', peer);
        J.calls.push({ target: target, opts: opts, session: s });
        this.emit('newRTCSession', { session: s, originator: 'local' });
        return s;
    };
    J.latestUa = function () { return J.uas[J.uas.length - 1] || null; };
    J.activeUas = function () { return J.uas.filter(function (u) { return u.started && !u.stopped; }); };
    /** Simulate the PBX accepting the REGISTER. */
    J.register = function (ua) { ua.registered = true; ua.emit('registered', {}); };
    /** Simulate an INVITE arriving from the PBX. */
    J.invite = function (ua, peer, headers, displayName) {
        var s = new FakeSession('remote', peer, headers, displayName);
        ua.emit('newRTCSession', { session: s, originator: 'remote', request: s._inviteRequest });
        return s;
    };
    return J;
}

function makeFakeBroadcastChannel() {
    var registry = [];
    function BC(name) { this.name = name; this.onmessage = null; registry.push(this); }
    BC.prototype.postMessage = function (data) {
        var self = this;
        registry.slice().forEach(function (other) {
            if (other !== self && other.name === self.name && typeof other.onmessage === 'function') {
                Promise.resolve().then(function () { other.onmessage({ data: data }); });
            }
        });
    };
    BC.prototype.close = function () { var i = registry.indexOf(this); if (i >= 0) { registry.splice(i, 1); } };
    return BC;
}

function extractTemplate(root) {
    var src = fs.readFileSync(path.join(root, 'inc/phone-widget-template.php'), 'utf8');
    var m = src.match(/<template id="tpl-phone-widget">[\s\S]*?<\/template>/);
    if (!m) { throw new Error('could not extract the phone widget template'); }
    return m[0];
}

/**
 * opts: root, url, standalone, payload (object | function(url) -> {status, body}),
 *       workstation (bool, default true), storage (initial localStorage),
 *       secure (default true), mediaDevices (default true), csrf, skipWidget
 */
async function build(opts) {
    var root = opts.root;
    var url = opts.url || 'http://localhost/newui/dashboard.php';
    var dom = new JSDOM(
        '<!doctype html><html><head><title>Test page</title></head><body>' + extractTemplate(root) + '</body></html>',
        { url: url, runScripts: 'outside-only', pretendToBeVisual: true }
    );
    var w = dom.window;
    if (w.document.readyState !== 'complete') {
        await new Promise(function (r) { w.addEventListener('load', r); });
    }

    var env = { window: w, document: w.document, J: makeFakeJsSIP(), fetchLog: [], openLog: [], dom: dom };

    if (opts.storage) {
        Object.keys(opts.storage).forEach(function (k) { w.localStorage.setItem(k, opts.storage[k]); });
    }
    if (opts.standalone) { w.PHONE_STANDALONE = true; }
    if (opts.csrf) { w.PHONE_CSRF = opts.csrf; }

    w.fetch = function (u, o) {
        var body = o && o.body ? (function () { try { return JSON.parse(o.body); } catch (e) { return o.body; } })() : null;
        env.fetchLog.push({ url: String(u), opts: o || {}, body: body });
        var res;
        if (typeof opts.fetchHandler === 'function') { res = opts.fetchHandler(String(u), o || {}, body); }
        if (!res && /action=my_extension/.test(String(u))) {
            res = { status: 200, body: typeof opts.payload === 'function' ? opts.payload(String(u)) : (opts.payload || { bound: false }) };
        }
        if (!res) { res = { status: 404, body: { error: 'unhandled in test: ' + u } }; }
        return Promise.resolve({
            ok: res.status >= 200 && res.status < 300,
            status: res.status,
            json: function () { return Promise.resolve(res.body); }
        });
    };
    w.open = function (u, name, features) {
        env.openLog.push({ url: String(u), name: name, features: features });
        return opts.popupBlocked ? null : { focus: function () { env.focused = true; } };
    };
    w.JsSIP = env.J;
    w.BroadcastChannel = makeFakeBroadcastChannel();
    Object.defineProperty(w, 'isSecureContext', { value: opts.secure !== false, configurable: true });
    if (opts.mediaDevices !== false) {
        Object.defineProperty(w.navigator, 'mediaDevices', {
            value: { getUserMedia: function () { return Promise.resolve({}); } }, configurable: true
        });
    }

    env.load = function (rel) { w.eval(fs.readFileSync(path.join(root, rel), 'utf8')); };
    env.flush = async function (n) {
        for (var i = 0; i < (n || 6); i++) { await new Promise(function (r) { setTimeout(r, 0); }); }
    };
    env.$ = function (sel) { return w.document.querySelector(sel); };
    // Is the element shown INSIDE the widget (no d-none on it or an ancestor up
    // to the widget root)? Whether the widget itself is open is a separate
    // question: env.widgetOpen().
    env.shown = function (sel) {
        var el = w.document.querySelector(sel);
        if (!el) { return false; }
        while (el && el !== w.document.body) {
            if (el.classList && el.classList.contains('d-none')) { return false; }
            if (el.classList && el.classList.contains('phone-widget')) { return true; }
            el = el.parentElement;
        }
        return true;
    };
    env.widgetOpen = function () {
        var el = w.document.querySelector('.phone-widget');
        return !!el && !el.classList.contains('phone-hidden');
    };
    env.click = function (sel) { env.$(sel).dispatchEvent(new w.MouseEvent('click', { bubbles: true })); };
    env.storageEvent = function (key) {
        w.dispatchEvent(new w.StorageEvent('storage', { key: key }));
    };

    if (!opts.skipWidget) {
        if (opts.workstation !== false) { env.load('assets/js/console-workstation.js'); }
        env.load('assets/js/phone-dial-logic.js');
        env.load('assets/js/phone-widget.js');
        await env.flush();
    }
    env.close = function () { try { w.close(); } catch (e) { /* ignore */ } };
    return env;
}

/** A small reporter: check(name, cond, detail) prints PASS|/FAIL| lines. */
function reporter() {
    var out = [];
    return {
        check: function (name, cond, detail) {
            out.push((cond ? 'PASS|' : 'FAIL|') + name + '|' + (detail === undefined ? '' : detail));
        },
        done: function () { console.log(out.join('\n')); process.exit(0); },
        lines: out
    };
}

module.exports = { build: build, reporter: reporter, makeFakeJsSIP: makeFakeJsSIP };
