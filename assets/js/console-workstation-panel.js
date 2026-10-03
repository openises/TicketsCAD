/**
 * NewUI v4.0 — Console rebuild: "Nearby workstations" adjacent-transmit
 * mute UI (Phase 152, spec.md user story #12 / plan.md section 3.5).
 *
 * A console-wide bar (not per-strip — plan.md's own PTT-adjacent trigger
 * requirement is satisfied by keeping this reachable in ONE click from
 * anywhere on the page, not by cluttering every strip with its own copy)
 * showing: this workstation's own editable label, a "Hearing yourself?"
 * affordance that opens the nearby-workstations panel, and a PERSISTENT
 * visible badge whenever at least one mute pairing is active — plan.md is
 * explicit that this must stay visible after setup, not just at the
 * moment it was configured, since the whole point is a desk that's
 * already been paired correctly needs no further attention.
 *
 * Hidden entirely when window.ConsoleWorkstation is unavailable (an
 * install that hasn't deployed console-workstation.js) or when the
 * backend returns nothing usable — this feature is additive over the
 * base console, never a requirement to use it.
 *
 * ES5 IIFE — no arrow functions, no let/const, no template literals.
 */
(function () {
    'use strict';

    var API = 'api/console-workstation-mutes.php';
    var SEARCH_API = 'api/console-workstation-search.php';

    var barEl = document.getElementById('consoleWorkstationBar');
    if (!barEl) { return; } // console.php must render the container

    var csrfTokenEl = document.querySelector('meta[name="csrf-token"]');
    var csrfToken = csrfTokenEl ? csrfTokenEl.getAttribute('content') : '';

    var state = { myId: null, myLabel: null, nearby: [], mutedIds: [], discoveryEnabled: false };
    var panelOpen = false;
    var searching = false;
    var searchResultMsg = '';

    function myToken() {
        return (window.ConsoleWorkstation && typeof window.ConsoleWorkstation.getToken === 'function')
            ? window.ConsoleWorkstation.getToken() : null;
    }

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
        body.workstation_token = myToken();
        body.csrf_token = csrfToken;
        return fetchJson(API, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
        });
    }

    function postSearchAction(body) {
        body.workstation_token = myToken();
        body.csrf_token = csrfToken;
        return fetchJson(SEARCH_API, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
        });
    }

    function relTime(mysqlDt) {
        if (!mysqlDt) { return 'never'; }
        var t = new Date(String(mysqlDt).replace(' ', 'T'));
        if (isNaN(t.getTime())) { return String(mysqlDt); }
        var s = Math.floor((Date.now() - t.getTime()) / 1000);
        if (s < 60) { return 'just now'; }
        if (s < 3600) { return Math.floor(s / 60) + 'm ago'; }
        return Math.floor(s / 3600) + 'h ago';
    }

    function load() {
        var t = myToken();
        if (!t) { barEl.classList.add('d-none'); return; }
        fetchJson(API + '?workstation_token=' + encodeURIComponent(t), { credentials: 'same-origin' })
            .then(function (resp) {
                if (!resp || resp.error) { barEl.classList.add('d-none'); return; }
                state.myId = resp.my_workstation_id;
                state.myLabel = resp.my_label;
                state.nearby = resp.nearby || [];
                state.mutedIds = resp.muted_ids || [];
                state.discoveryEnabled = !!resp.discovery_enabled;
                if (window.ConsoleBeacon && typeof window.ConsoleBeacon.setMyBeaconCode === 'function') {
                    window.ConsoleBeacon.setMyBeaconCode(
                        resp.my_beacon_code !== undefined && resp.my_beacon_code !== null ? resp.my_beacon_code : null
                    );
                }
                render();
            })
            .catch(function () { /* transient network hiccup -- the bar just stays as it was */ });
    }

    function render() {
        barEl.innerHTML = '';
        barEl.classList.remove('d-none');

        var row = el('div', 'console-workstation-bar-row');

        var labelSpan = el('span', 'console-workstation-mine', 'This workstation: ' + (state.myLabel || '(unnamed)'));
        row.appendChild(labelSpan);

        var renameBtn = el('button', 'btn btn-sm btn-link p-0 ms-1', null);
        renameBtn.type = 'button';
        renameBtn.title = 'Name this desk (e.g. "Desk 1") so a neighbor can recognize it';
        renameBtn.appendChild(el('i', 'bi bi-pencil-square'));
        renameBtn.addEventListener('click', function () {
            var next = window.prompt('Name this workstation (e.g. "Desk 1"):', state.myLabel || '');
            if (next === null) { return; }
            postAction({ action: 'set_label', label: next }).then(function () { load(); });
        });
        row.appendChild(renameBtn);

        var toggleBtn = el('button', 'btn btn-sm btn-outline-secondary ms-2', null);
        toggleBtn.type = 'button';
        toggleBtn.appendChild(el('i', 'bi bi-ear me-1'));
        toggleBtn.appendChild(document.createTextNode('Hearing yourself? Nearby workstations'));
        toggleBtn.addEventListener('click', function () {
            panelOpen = !panelOpen;
            renderPanel();
        });
        row.appendChild(toggleBtn);

        // Phase 155 (GH#108 S1): the docs always said the workstation token is
        // "visible on its Console page", but nothing rendered it. An
        // administrator binds a phone number to this desk by pasting it on the
        // Phone Extensions page, so it gets its own one-click entry here.
        if (myToken()) {
            var tokenBtn = el('button', 'btn btn-sm btn-outline-secondary ms-2', null);
            tokenBtn.type = 'button';
            tokenBtn.id = 'consoleWorkstationTokenBtn';
            tokenBtn.title = 'Show this workstation\'s token (an administrator uses it to bind a phone number to this desk)';
            tokenBtn.appendChild(el('i', 'bi bi-key me-1'));
            tokenBtn.appendChild(document.createTextNode('Phone token'));
            tokenBtn.addEventListener('click', function () {
                panelOpen = true;
                renderPanel();
                var tokenInput = document.getElementById('consoleWorkstationTokenInput');
                if (tokenInput && tokenInput.focus) { tokenInput.focus(); tokenInput.select(); }
            });
            row.appendChild(tokenBtn);
        }

        if (state.mutedIds.length) {
            var mutedLabels = [];
            for (var i = 0; i < state.nearby.length; i++) {
                if (state.mutedIds.indexOf(state.nearby[i].id) !== -1) { mutedLabels.push(state.nearby[i].label || ('#' + state.nearby[i].id)); }
            }
            var badge = el('span', 'badge text-bg-warning ms-2 console-workstation-muted-badge', null);
            badge.appendChild(el('i', 'bi bi-volume-mute-fill me-1'));
            badge.appendChild(document.createTextNode('Not playing: ' + mutedLabels.join(', ')));
            badge.title = 'This workstation will not play these workstations\' own outgoing transmissions -- everyone else still comes through normally';
            row.appendChild(badge);
        }

        barEl.appendChild(row);

        var panelEl = el('div', 'console-workstation-panel mt-2' + (panelOpen ? '' : ' d-none'));
        panelEl.id = 'consoleWorkstationPanelBody';
        barEl.appendChild(panelEl);
        if (panelOpen) { renderPanel(); }
    }

    function runBeaconSearch() {
        if (searching || !window.ConsoleBeacon) { return; }
        searching = true;
        searchResultMsg = '';
        renderPanel();
        postSearchAction({ action: 'start' }).then(function (resp) {
            if (resp && resp.error) {
                searching = false;
                searchResultMsg = resp.error;
                renderPanel();
                return;
            }
            window.ConsoleBeacon.startDetection(function (codes) {
                postSearchAction({ action: 'report', detected_codes: codes }).then(function (r2) {
                    searching = false;
                    var matched = (r2 && r2.matched) || [];
                    searchResultMsg = matched.length
                        ? 'Found and muted: ' + matched.map(function (m) { return m.label || ('workstation #' + m.id); }).join(', ')
                        : 'No nearby workstations detected. Try again, or use the list below.';
                    load(); // refresh nearby/muted state to reflect any new pairings
                });
            });
        });
    }

    function renderPanel() {
        var panelEl = document.getElementById('consoleWorkstationPanelBody');
        if (!panelEl) { return; }
        panelEl.classList.toggle('d-none', !panelOpen);
        if (!panelOpen) { return; }
        panelEl.innerHTML = '';

        var tokenValue = myToken();
        if (tokenValue) {
            var tokenWrap = el('div', 'console-workstation-token mb-2');
            tokenWrap.appendChild(el('div', 'text-body-secondary small mb-1',
                'This workstation\'s token. To give this desk its own phone number, an administrator pastes it ' +
                'on the Phone Extensions page (Settings > Communications & Integrations).'));
            var group = el('div', 'input-group input-group-sm');
            var tokenInput = document.createElement('input');
            tokenInput.type = 'text';
            tokenInput.readOnly = true;
            tokenInput.id = 'consoleWorkstationTokenInput';
            tokenInput.className = 'form-control form-control-sm font-monospace';
            tokenInput.value = tokenValue;
            tokenInput.setAttribute('aria-label', 'This workstation\'s token');
            group.appendChild(tokenInput);
            var copyBtn = el('button', 'btn btn-outline-secondary', null);
            copyBtn.type = 'button';
            copyBtn.id = 'consoleWorkstationTokenCopy';
            copyBtn.setAttribute('aria-label', 'Copy this workstation\'s token');
            copyBtn.appendChild(el('i', 'bi bi-clipboard'));
            copyBtn.addEventListener('click', function () {
                tokenInput.select();
                try { document.execCommand('copy'); } catch (e) { /* the text is selected; Ctrl+C still works */ }
            });
            group.appendChild(copyBtn);
            tokenWrap.appendChild(group);
            panelEl.appendChild(tokenWrap);
        }

        if (state.discoveryEnabled) {
            var searchRow = el('div', 'mb-2');
            var searchBtn = el('button', 'btn btn-sm btn-outline-primary', null);
            searchBtn.type = 'button';
            searchBtn.disabled = searching;
            searchBtn.appendChild(el('i', 'bi bi-broadcast me-1'));
            searchBtn.appendChild(document.createTextNode(searching ? 'Listening…' : 'Search automatically'));
            searchBtn.title = 'Every other nearby workstation plays a brief, near-inaudible tone; this workstation listens for it';
            searchBtn.addEventListener('click', runBeaconSearch);
            searchRow.appendChild(searchBtn);
            if (searchResultMsg) {
                searchRow.appendChild(el('div', 'text-body-secondary small mt-1', searchResultMsg));
            }
            panelEl.appendChild(searchRow);
        }

        if (!state.nearby.length) {
            panelEl.appendChild(el('div', 'text-body-secondary small', 'No other workstations have been active recently — nothing to pair with yet.'));
            return;
        }

        var hint = el('div', 'text-body-secondary small mb-1',
            'If you can hear yourself echo a moment after you transmit, another nearby workstation is probably ' +
            'playing this channel back through its own speakers. Check the one that matches the desk near you.');
        panelEl.appendChild(hint);

        var list = el('div', 'console-workstation-nearby-list');
        for (var i = 0; i < state.nearby.length; i++) {
            (function (w) {
                var muted = state.mutedIds.indexOf(w.id) !== -1;
                var row = el('label', 'console-workstation-nearby-item form-check', null);
                var input = document.createElement('input');
                input.type = 'checkbox';
                input.className = 'form-check-input';
                input.checked = muted;
                input.addEventListener('change', function () {
                    input.disabled = true;
                    postAction({ action: 'set_mute_pair', target_workstation_id: w.id, muted: input.checked })
                        .then(function (resp) {
                            input.disabled = false;
                            if (resp && resp.error) { window.alert(resp.error); input.checked = !input.checked; return; }
                            load();
                        });
                });
                row.appendChild(input);
                row.appendChild(el('span', 'form-check-label ms-1',
                    'Don\'t play "' + (w.label || ('workstation #' + w.id)) + '"\'s own transmissions to me'));
                row.appendChild(el('span', 'text-body-secondary small ms-2', 'last active ' + relTime(w.last_seen_at)));
                list.appendChild(row);
            })(state.nearby[i]);
        }
        panelEl.appendChild(list);
    }

    load();
})();
