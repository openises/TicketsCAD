/**
 * NewUI v4.0 — Media Hub SharedWorker
 * Phase 101-5 (Eric beta 2026-07-01) — cross-page audio + WS persistence.
 *
 * One instance per browser session, shared across every TicketsCAD tab
 * of the same origin. Holds the Zello proxy WebSocket connection so
 * page navigation doesn't drop it. Broadcasts Zello events to every
 * connected tab; routes tab actions (send_text, send_image, ptt_*)
 * back to the Zello proxy WSS.
 *
 * Non-goals for THIS slice (101-5-1 + 101-5-2):
 *   - Voice audio streaming (voice_chunk / MSE) — still per-tab in
 *     the widget for now. Moves here in slice 101-5-4 with the
 *     audio-owner election.
 *   - DMR SSE relocation — slice 101-5-3.
 *   - PTT audio routing tab→SW→proxy — slice 101-5-5.
 *
 * See specs/phase-101-5-shared-worker/spec.md for the full design.
 */

'use strict';

// ── State ────────────────────────────────────────────────────────

/** @type {WebSocket|null} */
var zelloWs = null;
/** @type {string} */
var zelloStatus = 'disconnected'; // 'connecting' | 'connected' | 'authenticated' | 'error' | 'disconnected' | 'rate_limited'
/** @type {string} */
var zelloStatusDetail = '';
/** @type {number} */
var proxyPort = 8090;   // overridden by tabs during hello
/** @type {number} */
var zelloReconnectAttempts = 0;
/** @type {*} */
var zelloReconnectTimer = null;
/** @type {number} */
var lastAuthAt = 0;

// Connected tab ports. Key = a monotonic tabId assigned at hello.
// Value = { port, tabId, visible, focused, lastFocusAt }.
var tabs = {};
var nextTabId = 1;

// Cache the last "welcome payload" style state so a newly-arriving
// tab can be brought up to date in one message.
var recentBroadcast = [];       // rolling last-N broadcast events
var RECENT_BROADCAST_MAX = 50;

// ── Boot log ─────────────────────────────────────────────────────

function log(msg) {
    // Console lands in the browser's SharedWorker devtools context
    // (chrome://inspect/#workers), separate from tab consoles.
    try { console.info('[media-hub] ' + msg); } catch (e) {}
}
log('SharedWorker boot');

// ── Tab port lifecycle ────────────────────────────────────────────

self.onconnect = function (e) {
    var port = e.ports[0];
    var tabId = nextTabId++;
    tabs[tabId] = {
        port: port,
        tabId: tabId,
        visible: true,
        focused: false,
        lastFocusAt: 0
    };
    log('port connect tabId=' + tabId + ' (open ports=' + Object.keys(tabs).length + ')');

    port.onmessage = function (ev) {
        handleTabMessage(tabId, ev.data);
    };
    // Some browsers don't fire onclose reliably; also listen for onmessageerror.
    port.onmessageerror = function () {
        log('port messageerror tabId=' + tabId);
    };

    port.start && port.start();

    // Reply immediately with welcome — even before hello arrives from
    // the tab. This gives the tab confirmation the SW is alive.
    postToTab(tabId, {
        kind: 'welcome',
        tabId: tabId,
        status: {
            zello: { status: zelloStatus, detail: zelloStatusDetail }
        },
        // A newly-arriving tab replays the last N broadcasts so it
        // sees recent activity even if it just loaded mid-conversation.
        recent: recentBroadcast.slice()
    });
};

function handleTabMessage(tabId, msg) {
    if (!msg || typeof msg !== 'object') return;
    var tab = tabs[tabId];
    if (!tab) return;

    switch (msg.kind) {

        case 'hello':
            // Tab identifies itself with visibility state + preferred
            // proxy port. First hello wins for proxy port; subsequent
            // tabs must match (they always do — same origin).
            tab.visible = !!msg.visible;
            tab.focused = !!msg.focused;
            tab.lastFocusAt = msg.focused ? Date.now() : 0;
            if (msg.proxyPort && !zelloWs) {
                proxyPort = msg.proxyPort;
            }
            if (msg.wsUrl && !zelloWs) {
                zelloWsUrl = msg.wsUrl;
            }
            // If we don't have a live WSS yet AND at least one tab is
            // present, start the connect. Auth token has to come from
            // the tab (see 'auth_token' message below) since the SW
            // can't hit api/zello-token.php with the right credentials
            // reliably from every browser.
            if (!zelloWs && msg.authToken) {
                connectZello(msg.authToken);
            } else if (!zelloWs) {
                // Ask the tab to fetch a token for us.
                postToTab(tabId, { kind: 'need_auth_token' });
            }
            break;

        case 'auth_token':
            // Tab replies with a fresh Zello proxy auth token.
            if (!zelloWs && msg.token) {
                connectZello(msg.token);
            }
            break;

        case 'focus':
            tab.visible = !!msg.visible;
            tab.focused = !!msg.focused;
            if (msg.focused) tab.lastFocusAt = Date.now();
            // Owner election is slice 101-5-4; nothing to do here yet.
            break;

        case 'leave':
            log('port leave tabId=' + tabId);
            delete tabs[tabId];
            // If no tabs remain, close the WSS to be a good citizen.
            if (!Object.keys(tabs).length) {
                shutdownZello('no tabs');
            }
            break;

        case 'zello_send':
            // Passthrough. Handles three shapes so callers don't
            // have to encode:
            //   msg.payload = object (JSON command; auto-stringified)
            //   msg.payload = string (text frame verbatim)
            //   msg.binary  = ArrayBuffer (raw binary frame — PTT audio)
            if (!zelloWs || zelloWs.readyState !== 1) {
                log('zello_send dropped — WSS not ready (state=' + (zelloWs && zelloWs.readyState) + ')');
                break;
            }
            try {
                if (msg.binary) {
                    zelloWs.send(msg.binary);
                } else if (typeof msg.payload === 'string') {
                    zelloWs.send(msg.payload);
                } else if (msg.payload) {
                    zelloWs.send(JSON.stringify(msg.payload));
                }
            } catch (e) { log('send failed: ' + e.message); }
            break;

        default:
            log('unknown tab message kind=' + msg.kind);
    }
}

function postToTab(tabId, message) {
    var t = tabs[tabId];
    if (!t) return;
    try { t.port.postMessage(message); }
    catch (e) { log('postToTab ' + tabId + ' failed: ' + e.message); }
}

function broadcast(message, opts) {
    // opts.remember=true → keep in recentBroadcast for late-arriving
    // tabs. Voice_chunk and other high-frequency events set false.
    var remember = opts && opts.remember;
    if (remember) {
        recentBroadcast.push(message);
        if (recentBroadcast.length > RECENT_BROADCAST_MAX) {
            recentBroadcast.shift();
        }
    }
    for (var tid in tabs) {
        if (!tabs.hasOwnProperty(tid)) continue;
        try { tabs[tid].port.postMessage(message); }
        catch (e) { log('broadcast to ' + tid + ' failed: ' + e.message); }
    }
}

// ── Zello WSS lifecycle ──────────────────────────────────────────

var zelloWsUrl = null;

function connectZello(token) {
    if (zelloWs && zelloWs.readyState !== 3) {
        log('connectZello: already open');
        return;
    }
    // Fallback URL if the tab didn't provide one (shouldn't happen
    // once hello lands, but keep sane defaults).
    var url = zelloWsUrl;
    if (!url) {
        // Best-effort — SharedWorker has no window.location so we
        // rely on the tab to supply this via hello.
        log('connectZello: no wsUrl set; refusing');
        return;
    }
    log('connectZello url=' + url);
    setZelloStatus('connecting', 'Connecting to ' + url);
    try {
        zelloWs = new WebSocket(url);
    } catch (e) {
        log('WebSocket ctor failed: ' + e.message);
        setZelloStatus('error', e.message);
        scheduleZelloReconnect();
        return;
    }
    zelloWs.onopen = function () {
        log('zello ws open');
        zelloReconnectAttempts = 0;
        setZelloStatus('connected', 'WebSocket connected, authenticating…');
        try {
            zelloWs.send(JSON.stringify({ cmd: 'auth', token: token }));
        } catch (e) {
            log('auth send failed: ' + e.message);
        }
        lastAuthAt = Date.now();
    };
    zelloWs.onmessage = function (evt) {
        handleZelloMessage(evt.data);
    };
    zelloWs.onerror = function () {
        log('zello ws error');
    };
    zelloWs.onclose = function (ev) {
        log('zello ws close code=' + ev.code + ' reason=' + ev.reason);
        setZelloStatus('disconnected', 'Connection closed (' + ev.code + ')');
        zelloWs = null;
        scheduleZelloReconnect();
    };
}

function shutdownZello(reason) {
    log('shutdownZello: ' + reason);
    if (zelloReconnectTimer) {
        clearTimeout(zelloReconnectTimer);
        zelloReconnectTimer = null;
    }
    if (zelloWs) {
        try { zelloWs.close(1000, reason || 'shutdown'); } catch (e) {}
    }
    zelloWs = null;
    setZelloStatus('disconnected', reason || '');
}

function scheduleZelloReconnect() {
    if (!Object.keys(tabs).length) return; // no consumers → don't retry
    if (zelloReconnectTimer) return;
    var delay = Math.min(30000, Math.pow(2, zelloReconnectAttempts) * 1000);
    zelloReconnectAttempts++;
    log('reconnect in ' + delay + 'ms (attempt ' + zelloReconnectAttempts + ')');
    setZelloStatus('reconnecting', 'Reconnecting in ' + Math.round(delay / 1000) + 's');
    zelloReconnectTimer = setTimeout(function () {
        zelloReconnectTimer = null;
        // Ask any connected tab for a fresh auth token.
        var anyTabId = Object.keys(tabs)[0];
        if (anyTabId) postToTab(parseInt(anyTabId, 10), { kind: 'need_auth_token' });
    }, delay);
}

function setZelloStatus(status, detail) {
    zelloStatus = status;
    zelloStatusDetail = detail || '';
    broadcast({
        kind: 'status',
        target: 'zello',
        status: status,
        detail: detail || ''
    }, { remember: true });
}

function handleZelloMessage(raw) {
    // Try JSON first — every Zello control message is JSON. Binary
    // audio frames (voice_chunk) also arrive here as ArrayBuffer or
    // Blob, but we don't process voice chunks in the SW yet (per
    // slice 101-5-2 scope). We just forward them so a per-tab
    // handler can decide what to do.
    if (typeof raw !== 'string') {
        // Forward binary blob to all tabs; the widget still handles
        // voice_chunk audio per-tab in this slice.
        broadcast({ kind: 'zello_binary', payload: raw }, { remember: false });
        return;
    }
    var data;
    try { data = JSON.parse(raw); }
    catch (e) {
        log('zello parse err: ' + e.message);
        return;
    }
    // Forward every JSON event verbatim. The widget already knows how
    // to render each type (text_message, image_message, voice_message,
    // status, channel_status, ptt_ack, etc.). We just wrap in an SW
    // envelope so the widget can distinguish SW-delivered from
    // tab-local.
    broadcast({ kind: 'zello_event', payload: data }, { remember: shouldRemember(data) });
}

function shouldRemember(data) {
    // Keep message-history events in the replay buffer so a
    // late-arriving tab catches up; skip high-frequency status
    // updates (channel_status, voice_chunk, ptt_ack) which aren't
    // useful in a replay context.
    if (!data || !data.type) return false;
    switch (data.type) {
        case 'text_message':
        case 'image_message':
        case 'voice_message':
        case 'location':
            return true;
        default:
            return false;
    }
}
