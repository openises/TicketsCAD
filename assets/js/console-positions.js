/**
 * NewUI v4.0 — Console rebuild: the thin position layer (Phase 152)
 *
 * Operator-facing widget on console.php: a position picker ("log into a
 * seat"), a read-only presence list (who's logged into which seat right
 * now), and the "clear at handoff" reset button. Backend: api/console-
 * positions.php; schema + business rules: inc/console-positions.php.
 *
 * Deliberately hidden entirely when the install has zero positions
 * configured (spec.md: "a solo/1-2-person install can ignore positions
 * entirely... this is additive, not a mode switch") — most installs will
 * never see this bar at all.
 *
 * No SSE wiring: presence is refreshed by the same periodic poll that
 * carries the heartbeat (one lightweight mechanism, matching plan.md
 * section 3's "piggybacking rather than inventing a second liveness
 * mechanism" — here read as ONE poll doing both jobs, not two separate
 * timers). A full live-push presence feed is more machinery than a
 * once-a-shift "who's where" glance needs; the console rebuild's SSE
 * budget (the comm:route_... / comm:group_... family, entitled-scope
 * inbound-call alerts) is spent on things that are wrong the instant
 * they're stale, which presence is not.
 *
 * ES5 IIFE — no arrow functions, no let/const, no template literals.
 */
(function () {
    'use strict';

    var API = 'api/console-positions.php';

    var barEl = document.getElementById('consolePositionBar');
    if (!barEl) { return; } // console.php must render the container

    var csrfTokenEl = document.querySelector('meta[name="csrf-token"]');
    var csrfToken = csrfTokenEl ? csrfTokenEl.getAttribute('content') : '';

    var state = { positions: [], presence: [], myPosition: null, heartbeatIntervalSeconds: 60 };
    var pollTimer = null;

    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) { n.className = cls; }
        if (text !== undefined && text !== null) { n.textContent = text; }
        return n;
    }

    function fetchJson(url, opts) {
        return fetch(url, opts).then(function (r) { return r.json(); });
    }

    function postAction(body) {
        body.csrf_token = csrfToken;
        return fetchJson(API, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
        });
    }

    function positionLabel(id) {
        for (var i = 0; i < state.positions.length; i++) {
            if (parseInt(state.positions[i].id, 10) === parseInt(id, 10)) { return state.positions[i].label; }
        }
        return '#' + id;
    }

    function load() {
        return fetchJson(API, { credentials: 'same-origin' }).then(function (resp) {
            if (!resp || resp.error) { return; }
            state.positions = resp.positions || [];
            state.presence = resp.presence || [];
            state.myPosition = resp.my_position || null;
            state.heartbeatIntervalSeconds = resp.heartbeat_interval_seconds || 60;
            render();
            schedulePoll();
        }).catch(function () { /* transient network hiccup -- next poll retries */ });
    }

    function schedulePoll() {
        if (pollTimer) { window.clearTimeout(pollTimer); }
        if (!state.positions.length) { return; } // nothing to keep polling for
        pollTimer = window.setTimeout(tick, state.heartbeatIntervalSeconds * 1000);
    }

    function tick() {
        // One call, two jobs (see file docblock): bumps this operator's own
        // heartbeat if they're logged into a position (a harmless no-op if
        // not), then a fresh GET refreshes the presence list either way --
        // this is how OTHER operators' presence/staleness stays current
        // without a second, independent refresh timer.
        var body = { action: 'heartbeat' };
        if (window.ConsoleMatrix && typeof window.ConsoleMatrix.getSessionToken === 'function') {
            var token = window.ConsoleMatrix.getSessionToken();
            if (token) { body.session_token = token; }
        }
        postAction(body).then(function () { load(); }).catch(function () { schedulePoll(); });
    }

    function render() {
        barEl.innerHTML = '';
        if (!state.positions.length) {
            barEl.classList.add('d-none');
            return;
        }
        barEl.classList.remove('d-none');

        var row = el('div', 'console-position-bar-row');

        if (state.myPosition) {
            row.appendChild(el('span', 'console-position-current', 'Position: ' + positionLabel(state.myPosition)));

            var handoffBtn = el('button', 'btn btn-sm btn-outline-secondary ms-2', null);
            handoffBtn.type = 'button';
            handoffBtn.appendChild(el('i', 'bi bi-flag-fill me-1'));
            handoffBtn.appendChild(document.createTextNode('Clear at Handoff'));
            handoffBtn.title = 'Mark this position\'s working channels as seen as of now';
            handoffBtn.addEventListener('click', function () {
                if (!window.confirm('Clear read-state for this position\'s working channels? This marks everything as seen as of right now.')) { return; }
                postAction({ action: 'clear_handoff', position_id: state.myPosition }).then(function (resp) {
                    if (resp && resp.error) { window.alert(resp.error); return; }
                    load();
                });
            });
            row.appendChild(handoffBtn);

            var logOutBtn = el('button', 'btn btn-sm btn-outline-secondary ms-2', null);
            logOutBtn.type = 'button';
            logOutBtn.textContent = 'Log Out';
            logOutBtn.addEventListener('click', function () {
                postAction({ action: 'log_out' }).then(function () { load(); });
            });
            row.appendChild(logOutBtn);
        } else {
            row.appendChild(el('span', 'console-position-current text-body-secondary', 'Not logged into a position'));

            var sel = document.createElement('select');
            sel.className = 'form-select form-select-sm d-inline-block w-auto ms-2';
            state.positions.forEach(function (p) {
                var opt = document.createElement('option');
                opt.value = String(p.id);
                opt.textContent = p.label;
                sel.appendChild(opt);
            });
            row.appendChild(sel);

            var logInBtn = el('button', 'btn btn-sm btn-primary ms-2', null);
            logInBtn.type = 'button';
            logInBtn.textContent = 'Log In';
            logInBtn.addEventListener('click', function () {
                var positionId = parseInt(sel.value, 10);
                if (!positionId) { return; }
                postAction({ action: 'log_in', position_id: positionId }).then(function (resp) {
                    if (resp && resp.error) { window.alert(resp.error); return; }
                    load();
                });
            });
            row.appendChild(logInBtn);
        }

        barEl.appendChild(row);

        if (state.presence.length) {
            var presenceRow = el('div', 'console-position-presence mt-1');
            state.presence.forEach(function (p) {
                var pill = el('span', 'console-position-pill' + (p.stale ? ' console-position-pill-stale' : ''), null);
                pill.appendChild(document.createTextNode(p.position_label + ': ' + p.username));
                if (p.stale) { pill.appendChild(el('i', 'bi bi-exclamation-triangle-fill ms-1', null)); }
                presenceRow.appendChild(pill);
            });
            barEl.appendChild(presenceRow);
        }
    }

    load();
})();
