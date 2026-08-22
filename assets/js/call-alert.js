/**
 * Phase 149 (2026-08-22) — inbound SIP/PBX call alert banner.
 *
 * Loaded globally from inc/navbar.php (never per-page — see this
 * project's own documented lesson about SSE-dependent scripts). Renders
 * a persistent, page-wide, non-blocking strip (spec.md FR-5/FR-8) with
 * one compact card per currently-visible call, oldest first (FR-11's
 * "no routing algorithm" made visible — if it's on the strip as ringing,
 * nobody has it yet).
 *
 * Built together with the §6a keyboard bindings (↑/↓/A/T/Esc), per
 * plan.md's own build-order note: the highlight-cursor model is part of
 * this file's own state, not a separate layer bolted on afterward.
 *
 * ES5 only — no arrow functions, no let/const, no template literals
 * (project rule).
 */
(function () {
    'use strict';

    if (typeof window.CALL_ALERT_USER_ID === 'undefined') return; // navbar not loaded (shouldn't happen)

    var USER_ID    = window.CALL_ALERT_USER_ID;
    var CSRF_TOKEN = window.CALL_ALERT_CSRF || '';

    var calls = {};          // id -> call object
    var missedCalls = {};    // id -> abandoned-call object (Milestone 6, FR-14)
    var highlightedId = null;
    var acked = {};          // id -> true, client-local only (spec.md FR-9)
    var container = null;
    var initialized = false;
    var keyboardBound = false;
    var missedRefreshTimer = null;
    var heartbeatTimer = null;

    var ACK_STORAGE_KEY = 'ticketsCallAlertAck';

    function loadAcked() {
        try {
            var raw = sessionStorage.getItem(ACK_STORAGE_KEY);
            return raw ? JSON.parse(raw) : {};
        } catch (e) { return {}; }
    }
    function saveAcked() {
        try { sessionStorage.setItem(ACK_STORAGE_KEY, JSON.stringify(acked)); } catch (e) { /* ignore */ }
    }

    function csrfHeaders() {
        return { 'Content-Type': 'application/json' };
    }

    function isTypingTarget(el) {
        if (!el) return false;
        var tag = el.tagName;
        return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || el.isContentEditable === true;
    }

    // ── Data model helpers ──────────────────────────────────────────

    /** Calls currently worth showing on the strip (never ended/reviewed). */
    function visibleCalls() {
        var out = [];
        for (var id in calls) {
            if (!calls.hasOwnProperty(id)) continue;
            var c = calls[id];
            if (c.state === 'ringing' || c.state === 'claimed' || c.state === 'wrapup') {
                out.push(c);
            }
        }
        out.sort(function (a, b) {
            var ta = a.ringing_at ? new Date(a.ringing_at.replace(' ', 'T')).getTime() : 0;
            var tb = b.ringing_at ? new Date(b.ringing_at.replace(' ', 'T')).getTime() : 0;
            return ta - tb;
        });
        return out;
    }

    function findCallIndex(list, id) {
        for (var i = 0; i < list.length; i++) {
            if (String(list[i].id) === String(id)) return i;
        }
        return -1;
    }

    /** Does the current user already have a claimed/wrapup call of their own? (FR-10 self-quieting) */
    function userHasActiveCall() {
        for (var id in calls) {
            if (!calls.hasOwnProperty(id)) continue;
            var c = calls[id];
            if ((c.state === 'claimed' || c.state === 'wrapup') && Number(c.claimed_by) === Number(USER_ID)) {
                return true;
            }
        }
        return false;
    }

    function ensureHighlight() {
        var list = visibleCalls();
        if (!list.length) { highlightedId = null; return; }
        if (findCallIndex(list, highlightedId) !== -1) return;
        // FR-11: oldest RINGING call is the default highlight; else the
        // oldest visible card of any kind.
        var ringing = null;
        for (var i = 0; i < list.length; i++) {
            if (list[i].state === 'ringing') { ringing = list[i]; break; }
        }
        highlightedId = (ringing || list[0]).id;
    }

    // ── Server actions ──────────────────────────────────────────────

    function postAction(action, body) {
        body = body || {};
        body.csrf_token = CSRF_TOKEN;
        return fetch('api/inbound-calls.php?action=' + encodeURIComponent(action), {
            method: 'POST',
            credentials: 'same-origin',
            headers: csrfHeaders(),
            body: JSON.stringify(body)
        }).then(function (r) { return r.json(); }).catch(function () { return { success: false, reason: 'network_error' }; });
    }

    /**
     * Claim (Answer) a ringing call. On success, opens a NEW browser tab
     * for the New Incident prefill (spec.md FR-20) — never navigates the
     * current tab, so unsaved work is never at risk.
     */
    function claimCall(id) {
        postAction('claim', { id: id }).then(function (res) {
            if (res && res.success) {
                window.open('new-incident.php?call_id=' + encodeURIComponent(id), '_blank');
            } else if (res) {
                showToast(res.reason === 'already_claimed'
                    ? ('Already claimed by ' + (res.claimed_by_name || 'someone else'))
                    : 'Could not claim that call (' + (res.reason || 'unknown') + ')');
            }
        });
    }

    /**
     * Quick reassignment (FR-18a) — fast, ungated, no reason. Only
     * offered on a NON-stale claimed-by-another card (see callCardHtml);
     * a stale card's action is forceReclaimCall() below instead. If the
     * grace window elapsed between the card rendering and the click (a
     * real but narrow race), fall back to the FR-17 override path rather
     * than leaving the user with nothing to do — the capability degrades
     * gracefully, per plan.md §4a.
     */
    function reassignCall(id) {
        postAction('reassign', { id: id }).then(function (res) {
            if (res && res.success) {
                window.open('new-incident.php?call_id=' + encodeURIComponent(id), '_blank');
            } else if (res && res.reason === 'grace_window_elapsed') {
                forceReclaimCall(id, false);
            } else if (res) {
                showToast('Could not reassign that call (' + (res.reason || 'unknown') + ')');
            }
        });
    }

    /**
     * Two-tier reclaim (FR-16/FR-17). $isStale is a client-side HINT only
     * (used to decide whether to skip the reason prompt) — the server
     * independently re-verifies staleness itself and is the authoritative
     * gate (inbound_call_force_reclaim()'s own docblock).
     */
    function forceReclaimCall(id, isStale) {
        var reason = null;
        if (!isStale) {
            // FR-17: overriding an ACTIVE (non-stale) claim requires a
            // typed reason. No custom modal dialog exists in this
            // ES5/no-build-tool codebase yet for this rare, deliberately
            // friction-full path — window.prompt() is a plain, honest
            // browser-native text prompt, consistent with this feature's
            // "fast path is a button, slow path can cost a dialog" design.
            reason = window.prompt('Overriding an active claim requires a reason (visible in the audit trail):', '');
            if (reason === null) return; // user cancelled
            reason = reason.replace(/^\s+|\s+$/g, '');
            if (reason === '') { showToast('A reason is required to override an active claim'); return; }
        }
        postAction('force_reclaim', { id: id, reason: reason }).then(function (res) {
            if (res && res.success) {
                window.open('new-incident.php?call_id=' + encodeURIComponent(id), '_blank');
            } else if (res) {
                showToast('Could not reclaim that call (' + (res.reason || 'unknown') + ')');
            }
        });
    }

    /**
     * The 15s claim heartbeat (plan.md §4, "Claim heartbeat & staleness").
     * Only for `state === 'claimed'` -- once the PBX reports the call
     * ended (state -> 'wrapup'), there is nothing left to prove liveness
     * against, matching inbound_call_heartbeat()'s own server-side gate
     * (`WHERE ... AND state = 'claimed'`, which would refuse a wrapup-
     * state beat anyway; this just avoids the pointless request).
     */
    function sendHeartbeats() {
        for (var id in calls) {
            if (!calls.hasOwnProperty(id)) continue;
            var c = calls[id];
            if (c.state === 'claimed' && Number(c.claimed_by) === Number(USER_ID)) {
                postAction('heartbeat', { id: c.id });
            }
        }
    }

    function acknowledgeCall(id) {
        acked[id] = true;
        saveAcked();
        render();
    }

    /**
     * One-click callback (spec.md FR-23) — reuses the SAME New Incident
     * prefill mechanism Answer/Take use (call-prefill.js), just without a
     * claim/reassign step first: the call is already terminal (abandoned),
     * there is nothing left to claim. Opens in a NEW tab, same as Answer.
     */
    function callbackCall(id) {
        window.open('new-incident.php?call_id=' + encodeURIComponent(id), '_blank');
    }

    /** Clears a missed call from the live panel without deleting the
     *  record (spec.md's "Missed Calls" section, plan.md Milestone 6). */
    function reviewCall(id) {
        postAction('reviewed', { id: id }).then(function (res) {
            if (res && res.success) {
                delete missedCalls[id];
                render();
            }
        });
    }

    /** Missed/abandoned calls not yet reviewed (FR-14) — a separate,
     *  lower-urgency list that persists until a human acts on it. */
    function fetchMissedCalls() {
        fetch('api/inbound-calls.php?action=list_missed', { credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) {
                if (!data || !data.calls) return;
                // Mutate in place, never reassign the whole object -- the
                // exposed window.CallAlert._missedCalls reference (tests/
                // debugging) would otherwise go stale after the first
                // refresh, the same pitfall a wholesale reassignment of
                // `calls` would create.
                for (var id in missedCalls) { if (missedCalls.hasOwnProperty(id)) delete missedCalls[id]; }
                for (var i = 0; i < data.calls.length; i++) {
                    missedCalls[data.calls[i].id] = data.calls[i];
                }
                render();
            })
            .catch(function () { /* best effort */ });
    }

    function showToast(msg) {
        if (window.EventBus) {
            try { window.EventBus.dispatchEvent && null; } catch (e) { /* no-op */ }
        }
        // Simple, dependency-free fallback — a full toast system is out of
        // scope for this feature; this project's notification tray already
        // records the underlying SSE event for anyone who wants history.
        if (window.console && window.console.info) window.console.info('[call-alert] ' + msg);
    }

    // ── Rendering ────────────────────────────────────────────────────

    function callCardHtml(call, isHighlighted) {
        var badge = '';
        var actions = '';
        var cls = 'call-alert-card';
        if (call.state === 'ringing') {
            cls += ' call-alert-card-ringing';
            badge = '<span class="badge text-bg-danger me-2">Ringing</span>';
            actions = '<button type="button" class="btn btn-sm btn-danger call-alert-answer" data-id="' + call.id + '">'
                + '<i class="bi bi-telephone-fill me-1"></i>Answer</button>';
        } else if (call.state === 'claimed' || call.state === 'wrapup') {
            var isMine = Number(call.claimed_by) === Number(USER_ID);
            cls += call.stale ? ' call-alert-card-stale' : ' call-alert-card-claimed';
            badge = call.stale
                ? '<span class="badge text-bg-warning me-2">Stale</span>'
                : '<span class="badge text-bg-secondary me-2">' + (call.state === 'wrapup' ? 'Wrapping up' : 'Claimed') + '</span>';
            if (!isMine && call.stale) {
                // FR-16: a STALE claim's force-reclaim needs no reason --
                // low-friction, since this is recovering from an apparent
                // technical failure, not overriding a colleague's live
                // decision (plan.md §4).
                actions = '<button type="button" class="btn btn-sm btn-outline-warning call-alert-reclaim" data-id="' + call.id + '">'
                    + '<i class="bi bi-arrow-repeat me-1"></i>Reclaim</button>';
            } else if (!isMine) {
                actions = '<button type="button" class="btn btn-sm btn-outline-primary call-alert-take" data-id="' + call.id + '">'
                    + '<i class="bi bi-arrow-left-right me-1"></i>Take</button>';
            }
        }
        var ackBtn = '<button type="button" class="btn btn-sm btn-outline-secondary call-alert-ack ms-1" data-id="' + call.id + '" title="Acknowledge (local only)">'
            + '<i class="bi bi-x-lg"></i></button>';

        return '<div class="' + cls + (isHighlighted ? ' call-alert-card-highlighted' : '') + '" data-call-id="' + call.id + '">'
            + '<div class="call-alert-card-body">'
            + badge
            + '<strong>' + escapeHtml(call.caller_number || 'Unknown number') + '</strong>'
            + (call.trunk_label ? ' <span class="text-muted small">(' + escapeHtml(call.trunk_label) + ')</span>' : '')
            + (call.claimed_by_name ? ' <span class="text-muted small">— ' + escapeHtml(call.claimed_by_name) + '</span>' : '')
            + '</div>'
            + '<div class="call-alert-card-actions">' + actions + ackBtn + '</div>'
            + '</div>';
    }

    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function missedCount() {
        var n = 0;
        for (var id in missedCalls) { if (missedCalls.hasOwnProperty(id)) n++; }
        return n;
    }

    /** The "Missed Calls" section (spec.md FR-14/FR-23) — a separate,
     *  lower-urgency, collapsible list that persists until a human
     *  reviews or starts a callback on each entry. */
    function missedSectionHtml() {
        var n = missedCount();
        if (n === 0) return '';
        var rows = '';
        for (var id in missedCalls) {
            if (!missedCalls.hasOwnProperty(id)) continue;
            var c = missedCalls[id];
            rows += '<div class="call-alert-missed-row" data-call-id="' + c.id + '">'
                + '<span class="text-muted small">' + escapeHtml(c.caller_number || 'Unknown number') + '</span>'
                + (c.trunk_label ? ' <span class="text-muted small">(' + escapeHtml(c.trunk_label) + ')</span>' : '')
                + '<span class="call-alert-card-actions">'
                + '<button type="button" class="btn btn-sm btn-outline-warning call-alert-callback" data-id="' + c.id + '">'
                + '<i class="bi bi-telephone-outbound me-1"></i>Callback</button>'
                + '<button type="button" class="btn btn-sm btn-outline-secondary call-alert-review ms-1" data-id="' + c.id + '" title="Mark reviewed">'
                + '<i class="bi bi-check-lg"></i></button>'
                + '</span></div>';
        }
        return '<div class="call-alert-missed">'
            + '<button type="button" class="btn btn-sm btn-link call-alert-missed-toggle" data-bs-toggle="collapse" data-bs-target="#callAlertMissedList">'
            + '<i class="bi bi-telephone-x me-1"></i>Missed Calls <span class="badge text-bg-secondary">' + n + '</span>'
            + '</button>'
            + '<div class="collapse" id="callAlertMissedList">' + rows + '</div>'
            + '</div>';
    }

    function render() {
        if (!container) return;
        ensureHighlight();
        var list = visibleCalls().filter(function (c) { return !(c.state === 'ringing' && acked[c.id]); });
        var missedHtml = missedSectionHtml();
        if (!list.length && missedHtml === '') {
            container.className = 'd-none';
            container.innerHTML = '';
            return;
        }
        var html = '';
        if (list.length) {
            html += '<div class="call-alert-strip">';
            for (var i = 0; i < list.length; i++) {
                html += callCardHtml(list[i], String(list[i].id) === String(highlightedId));
            }
            html += '</div>';
        }
        html += missedHtml;
        container.innerHTML = html;
        container.className = '';

        var answerBtns = container.querySelectorAll('.call-alert-answer');
        for (var a = 0; a < answerBtns.length; a++) {
            answerBtns[a].addEventListener('click', function (e) { claimCall(e.currentTarget.getAttribute('data-id')); });
        }
        var takeBtns = container.querySelectorAll('.call-alert-take');
        for (var b = 0; b < takeBtns.length; b++) {
            takeBtns[b].addEventListener('click', function (e) { reassignCall(e.currentTarget.getAttribute('data-id')); });
        }
        var reclaimBtns = container.querySelectorAll('.call-alert-reclaim');
        for (var g = 0; g < reclaimBtns.length; g++) {
            reclaimBtns[g].addEventListener('click', function (e) { forceReclaimCall(e.currentTarget.getAttribute('data-id'), true); });
        }
        var ackBtns = container.querySelectorAll('.call-alert-ack');
        for (var c2 = 0; c2 < ackBtns.length; c2++) {
            ackBtns[c2].addEventListener('click', function (e) { acknowledgeCall(e.currentTarget.getAttribute('data-id')); });
        }
        var callbackBtns = container.querySelectorAll('.call-alert-callback');
        for (var d = 0; d < callbackBtns.length; d++) {
            callbackBtns[d].addEventListener('click', function (e) { callbackCall(e.currentTarget.getAttribute('data-id')); });
        }
        var reviewBtns = container.querySelectorAll('.call-alert-review');
        for (var f = 0; f < reviewBtns.length; f++) {
            reviewBtns[f].addEventListener('click', function (e) { reviewCall(e.currentTarget.getAttribute('data-id')); });
        }
    }

    // ── SSE wiring ───────────────────────────────────────────────────

    function upsertCall(payload) {
        if (!payload || typeof payload.call_id === 'undefined') return;
        var id = payload.call_id;
        var wasKnown = !!calls[id];
        var wasRinging = wasKnown && calls[id].state === 'ringing';
        calls[id] = {
            id: id,
            trunk_id: payload.trunk_id,
            trunk_label: payload.trunk_label,
            caller_number: payload.caller_number,
            called_number: payload.called_number,
            state: payload.state,
            claimed_by: payload.claimed_by,
            claimed_by_name: payload.claimed_by_name,
            stale: !!payload.stale,
            mute_bypass: !!payload.mute_bypass,
            ringing_at: payload.ringing_at || (calls[id] ? calls[id].ringing_at : null)
        };
        return { isNewRing: !wasKnown && payload.state === 'ringing', wasRinging: wasRinging };
    }

    function handleRingingEvent(data) {
        var info = upsertCall(data);
        render();
        if (info && info.isNewRing && typeof AudioAlerts !== 'undefined') {
            // FR-10: a user already on a claimed call does not get the
            // audible interrupt for a NEW ring (still sees it, dimmed, via
            // render() above).
            if (!userHasActiveCall()) {
                if (data.mute_bypass) {
                    AudioAlerts.playTone('callRinging');
                } else {
                    AudioAlerts.playIfUnmuted('callRinging');
                }
            }
        }
    }

    function handleClaimedEvent(data) {
        upsertCall(data);
        render();
    }

    function handleStaleEvent(data) {
        upsertCall(data);
        render();
        if (typeof AudioAlerts !== 'undefined') AudioAlerts.playIfUnmuted('callStale');
    }

    function handleTerminalEvent(data) {
        if (typeof data.call_id === 'undefined') return;
        // FR-14: an abandoned call moves into the Missed Calls section
        // instead of simply vanishing the moment it stops ringing.
        if (data.state === 'abandoned') {
            missedCalls[data.call_id] = {
                id: data.call_id,
                caller_number: data.caller_number,
                trunk_label: data.trunk_label,
                ringing_at: data.ringing_at
            };
        }
        delete calls[data.call_id];
        render();
    }

    function wireEventBus() {
        if (typeof EventBus === 'undefined') return;
        EventBus.on('call:ringing', handleRingingEvent);
        EventBus.on('call:claimed', handleClaimedEvent);
        EventBus.on('call:released', handleClaimedEvent);
        EventBus.on('call:stale', handleStaleEvent);
        EventBus.on('call:wrapup', handleClaimedEvent);
        EventBus.on('call:ended', handleTerminalEvent);
        EventBus.on('call:abandoned', handleTerminalEvent);
    }

    // ── §6a Keyboard bindings ────────────────────────────────────────

    function moveHighlight(delta) {
        var list = visibleCalls();
        if (!list.length) return;
        var idx = findCallIndex(list, highlightedId);
        if (idx === -1) idx = 0;
        idx = (idx + delta + list.length) % list.length;
        highlightedId = list[idx].id;
        render();
    }

    function actOnHighlighted(key) {
        if (highlightedId === null) return;
        var call = calls[highlightedId];
        if (!call) return;
        var isOtherClaim = (call.state === 'claimed' || call.state === 'wrapup') && Number(call.claimed_by) !== Number(USER_ID);
        if (key === 'a' && call.state === 'ringing') {
            claimCall(call.id);
        } else if (key === 't' && isOtherClaim && call.stale) {
            forceReclaimCall(call.id, true);
        } else if (key === 't' && isOtherClaim) {
            reassignCall(call.id);
        } else if (key === 'esc') {
            acknowledgeCall(call.id);
        }
    }

    function onKeydown(e) {
        if (isTypingTarget(e.target)) return;
        if (!visibleCalls().length) return; // never contends with another page's shortcuts when no call needs attention
        if (e.ctrlKey || e.metaKey || e.altKey) return;

        if (e.key === 'ArrowUp') { e.preventDefault(); moveHighlight(-1); return; }
        if (e.key === 'ArrowDown') { e.preventDefault(); moveHighlight(1); return; }
        if (e.key === 'a' || e.key === 'A') { e.preventDefault(); actOnHighlighted('a'); return; }
        if (e.key === 't' || e.key === 'T') { e.preventDefault(); actOnHighlighted('t'); return; }
        if (e.key === 'Escape') { e.preventDefault(); actOnHighlighted('esc'); return; }
    }

    function bindKeyboard() {
        if (keyboardBound) return;
        keyboardBound = true;
        document.addEventListener('keydown', onKeydown);
    }

    // ── Init ─────────────────────────────────────────────────────────

    function init() {
        if (initialized) return;
        container = document.getElementById('callAlertBanner');
        if (!container) return;
        acked = loadAcked();

        fetch('api/inbound-calls.php?action=list', { credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) {
                if (!data || !data.calls) return; // 403/404 (no permission, or feature not configured) — harmless no-op
                initialized = true;
                for (var i = 0; i < data.calls.length; i++) {
                    calls[data.calls[i].id] = data.calls[i];
                }
                render();
                wireEventBus();
                bindKeyboard();
                fetchMissedCalls();
                // Periodic refresh only (Milestone 6, FR-14) -- missed
                // calls are lower-urgency than an active ring, so a
                // coarse 60s poll is enough; live SSE 'call:abandoned'
                // events (handleTerminalEvent above) already add a call
                // the INSTANT it goes missed on THIS tab without waiting
                // for the poll.
                if (missedRefreshTimer === null) {
                    missedRefreshTimer = setInterval(fetchMissedCalls, 60000);
                }
                if (heartbeatTimer === null) {
                    heartbeatTimer = setInterval(sendHeartbeats, 15000);
                }
            })
            .catch(function () { /* silent — matches every other best-effort navbar fetch */ });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // Exposed for tests/manual debugging only.
    window.CallAlert = {
        _calls: calls,
        _missedCalls: missedCalls,
        _render: render,
        _moveHighlight: moveHighlight,
        _actOnHighlighted: actOnHighlighted,
        _onKeydown: onKeydown,
        _upsertCall: upsertCall,
        _handleTerminalEvent: handleTerminalEvent,
        _reviewCall: reviewCall,
        _callbackCall: callbackCall,
        _forceReclaimCall: forceReclaimCall,
        _reassignCall: reassignCall,
        _sendHeartbeats: sendHeartbeats,
        _getHighlighted: function () { return highlightedId; },
        _setHighlighted: function (id) { highlightedId = id; }
    };
})();
