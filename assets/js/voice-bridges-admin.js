/**
 * Phase 155 (GH#151 + GH#129) — Digital Voice Bridges admin page.
 * Modeled on assets/js/stream-channels-admin.js's list+modal shape, with one
 * difference: every server-supplied string is put into the DOM with
 * textContent, never concatenated into HTML.
 *
 * ES5 only (project rule).
 */
(function () {
    'use strict';

    var API = 'api/voice-bridge-channels.php';
    var csrfToken = document.getElementById('csrfToken').value;
    var state = { channels: [], policy: null, fne: null, legsError: null };

    var modalEl = document.getElementById('vbModal');
    var modal = window.bootstrap ? new bootstrap.Modal(modalEl) : null;
    var snipEl = document.getElementById('vbSnipModal');
    var snipModal = window.bootstrap ? new bootstrap.Modal(snipEl) : null;

    var ADAPTER_LABEL = { dvmproject: 'DVMProject', usrp_bridge: 'USRP bridge' };
    var KEY_PREFIX = { dvmproject: 'dvm:', usrp_bridge: 'usrp:' };
    var CLASS_LABEL = { amateur: 'Amateur', commercial: 'Commercial' };
    var MODE_LABEL = { p25: 'P25', dmr: 'DMR', analog: 'Analog', other: 'Other' };

    // ── helpers ──────────────────────────────────────────────────────
    function byId(id) { return document.getElementById(id); }

    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) { n.className = cls; }
        if (text !== undefined && text !== null) { n.textContent = text; }
        return n;
    }

    function clear(node) {
        while (node.firstChild) { node.removeChild(node.firstChild); }
    }

    function parseJson(r) { return r.json(); }

    function apiGet(action, params) {
        var qs = 'action=' + encodeURIComponent(action);
        if (params) {
            for (var k in params) {
                if (Object.prototype.hasOwnProperty.call(params, k)) {
                    qs += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(params[k]);
                }
            }
        }
        return fetch(API + '?' + qs, { credentials: 'same-origin' }).then(parseJson);
    }

    function apiPost(action, body) {
        body = body || {};
        body.csrf_token = csrfToken;
        return fetch(API + '?action=' + encodeURIComponent(action), {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        }).then(parseJson);
    }

    var toastTimer = null;
    function showToast(msg, isError) {
        var n = byId('vbToast');
        n.className = 'alert ' + (isError ? 'alert-danger' : 'alert-success');
        n.textContent = msg;
        if (toastTimer) { clearTimeout(toastTimer); }
        toastTimer = setTimeout(function () { n.className = 'alert d-none'; }, 7000);
    }

    function fmtWhen(s) {
        if (!s) { return ''; }
        return String(s);
    }

    function isLoopback(ip) { return /^127\./.test(String(ip || '').replace(/^\s+|\s+$/g, '')); }

    // ── policy card ──────────────────────────────────────────────────
    function renderPolicy() {
        var p = state.policy || {};
        var text = byId('vbPolicyText');
        clear(text);
        var paras = p.paragraphs || [];
        for (var i = 0; i < paras.length; i++) {
            text.appendChild(el('p', 'mb-2', paras[i]));
        }
        var badge = byId('vbPolicyBadge');
        var who = byId('vbPolicyWho');
        if (p.acknowledged) {
            badge.className = 'badge text-bg-success';
            badge.textContent = 'Acknowledged';
            var a = p.ack || {};
            who.textContent = a.username
                ? 'Acknowledged by ' + a.username + (a.at ? ' on ' + fmtWhen(a.at) : '')
                    + (a.version ? ' (statement version ' + a.version + ')' : '') + '.'
                : 'Acknowledged (recorded outside this page).';
        } else {
            badge.className = 'badge text-bg-warning';
            badge.textContent = 'Not acknowledged';
            who.textContent = 'DVMProject channels cannot be created or enabled until this is acknowledged.';
        }
        byId('vbBtnAck').className = 'btn btn-sm btn-primary' + (p.acknowledged ? ' d-none' : '');
        byId('vbBtnUnack').className = 'btn btn-sm btn-outline-danger' + (p.acknowledged ? '' : ' d-none');
    }

    function acknowledge(yes) {
        if (!yes && !window.confirm('Withdraw the acknowledgment? Every DVMProject channel will be disabled and detached immediately.')) { return; }
        apiPost('policy_ack', { acknowledged: yes ? 1 : 0 }).then(function (res) {
            if (res && res.ok) {
                showToast(yes ? 'Acknowledgment recorded.' : 'Acknowledgment withdrawn; ' + (res.disabled || 0) + ' channel(s) disabled.', false);
                load();
            } else {
                showToast((res && res.error) || 'Could not save the acknowledgment', true);
            }
        }).catch(function () { showToast('Could not save the acknowledgment', true); });
    }

    // ── list ─────────────────────────────────────────────────────────
    function linkBadge(c) {
        var s = c.link_state || 'unknown';
        var cls = { connected: 'text-bg-success', degraded: 'text-bg-warning', down: 'text-bg-danger', unknown: 'text-bg-secondary' }[s] || 'text-bg-secondary';
        var b = el('span', 'badge ' + cls, 'Link: ' + s);
        if (c.last_error) { b.title = c.last_error; }
        return b;
    }

    function legText(c) {
        if (Number(c.enabled) !== 1) { return 'disabled (no socket bound)'; }
        if (c.leg === null || c.leg === undefined) { return 'service state unknown'; }
        if (!c.leg.attached) { return 'NOT attached in the audio-matrix service'; }
        return c.leg.receiving ? 'attached, receiving now' : 'attached, listening';
    }

    function renderList() {
        var tbody = byId('vbListRows');
        clear(tbody);
        if (state.legsError) {
            var tr0 = el('tr');
            var td0 = el('td', 'small text-warning', 'Audio-matrix service: ' + state.legsError);
            td0.colSpan = 7;
            tr0.appendChild(td0);
            tbody.appendChild(tr0);
        }
        if (!state.channels.length) {
            var tr = el('tr');
            var td = el('td', 'text-body-secondary', 'No digital voice bridge channels yet. Click "New Channel" to add one.');
            td.colSpan = 7;
            tr.appendChild(td);
            tbody.appendChild(tr);
            return;
        }
        state.channels.forEach(function (c) {
            var cfg = c.config || {};
            var tr = el('tr');
            var tdLabel = el('td');
            tdLabel.appendChild(el('div', null, c.label));
            tdLabel.appendChild(el('div', 'font-monospace small text-body-secondary', c.channel_key));
            tr.appendChild(tdLabel);
            tr.appendChild(el('td', null, ADAPTER_LABEL[c.adapter] || c.adapter));
            tr.appendChild(el('td', null, CLASS_LABEL[c.regulatory_class] || c.regulatory_class));
            tr.appendChild(el('td', null, (MODE_LABEL[cfg.mode] || cfg.mode || '') + (cfg.talkgroup ? ' / TG ' + cfg.talkgroup : '')));
            tr.appendChild(el('td', 'font-monospace small',
                (cfg.bridge_host || '') + ' → ' + (cfg.listen_host || '') + ':' + (cfg.listen_port || '')));
            var tdStatus = el('td', 'small');
            tdStatus.appendChild(linkBadge(c));
            tdStatus.appendChild(el('div', 'text-body-secondary', legText(c)));
            if (c.last_rx_at) { tdStatus.appendChild(el('div', 'text-body-secondary', 'last audio ' + fmtWhen(c.last_rx_at))); }
            if (c.leg && c.leg.rx_dropped_source > 0) {
                tdStatus.appendChild(el('div', 'text-warning',
                    c.leg.rx_dropped_source + ' datagram(s) dropped: not from the bridge address'));
            }
            if (c.leg && c.leg.tx_frames_blocked > 0) {
                tdStatus.appendChild(el('div', 'text-warning',
                    'audio was routed INTO this listen-only channel and discarded (' + c.leg.tx_frames_blocked + ' frames)'));
            }
            tr.appendChild(tdStatus);
            var tdAct = el('td', 'text-end');
            var edit = el('button', 'btn btn-sm btn-outline-secondary me-1');
            edit.type = 'button';
            edit.title = 'Edit ' + c.label;
            edit.setAttribute('aria-label', 'Edit ' + c.label);
            edit.appendChild(el('i', 'bi bi-pencil'));
            edit.addEventListener('click', function () { openEdit(c.id); });
            var snip = el('button', 'btn btn-sm btn-outline-secondary');
            snip.type = 'button';
            snip.title = 'Bridge configuration for ' + c.label;
            snip.setAttribute('aria-label', 'Bridge configuration for ' + c.label);
            snip.appendChild(el('i', 'bi bi-file-earmark-code'));
            snip.addEventListener('click', function () { openSnippet(c.id, c.label); });
            tdAct.appendChild(edit);
            tdAct.appendChild(snip);
            tr.appendChild(tdAct);
            tbody.appendChild(tr);
        });
    }

    function load() {
        return apiGet('list').then(function (data) {
            if (data && data.channels) {
                state.channels = data.channels;
                state.policy = data.policy;
                state.fne = data.fne;
                state.legsError = data.legs_error || null;
                renderPolicy();
                renderList();
                renderFne();
            } else if (data && data.error) {
                showToast(data.error, true);
            }
        }).catch(function () { showToast('Failed to load digital voice bridge channels', true); });
    }

    // ── create / edit modal ──────────────────────────────────────────
    function applyAdapterUi() {
        var ad = byId('vbAdapter').value;
        byId('vbSlugPrefix').textContent = KEY_PREFIX[ad] || '';
        var dvmOnly = modalEl.querySelectorAll('.vb-dvm-only');
        for (var i = 0; i < dvmOnly.length; i++) {
            dvmOnly[i].style.display = (ad === 'dvmproject') ? '' : 'none';
        }
        var help = byId('vbAdapterHelp');
        if (ad === 'dvmproject') {
            help.textContent = (state.policy && state.policy.acknowledged)
                ? 'Requires the DVMProject statement above (acknowledged).'
                : 'Requires the DVMProject statement above to be acknowledged first.';
        } else {
            help.textContent = 'For DVSwitch Analog_Bridge, AllStar chan_usrp or any other USRP-speaking program.';
        }
    }

    function updateNetWarn() {
        var w = byId('vbNetWarn');
        var bh = byId('vbBridgeHost').value, lh = byId('vbListenHost').value;
        if (!isLoopback(bh) || !isLoopback(lh)) {
            w.textContent = 'A non-loopback address means audio crosses your network over UDP with no authentication: '
                + 'anything on that network that can send to the listen port from the bridge address could speak into this '
                + 'channel. Running the bridge on this same host with 127.0.0.1 is the safe arrangement.';
            w.className = 'alert alert-warning py-1 px-2 mt-1 mb-0 small';
        } else {
            w.className = 'alert alert-warning py-1 px-2 mt-1 mb-0 small d-none';
        }
    }

    function showModalError(msg) {
        var n = byId('vbModalError');
        n.textContent = msg;
        n.className = 'alert alert-danger small mt-2';
    }

    // A refusal because the DVMProject statement has not been acknowledged:
    // say so in the dialog AND point at the statement, which is what the
    // admin has to act on.
    function showSaveFailure(res, fallback) {
        showModalError((res && res.error) || fallback);
        if (res && res.needs_policy_ack) {
            var card = byId('vbPolicyCard');
            card.classList.add('border-warning');
            if (card.scrollIntoView) { card.scrollIntoView({ block: 'center' }); }
            setTimeout(function () { card.classList.remove('border-warning'); }, 5000);
        }
    }

    function resetModal() {
        byId('vbId').value = '0';
        byId('vbAdapter').value = 'dvmproject';
        byId('vbAdapter').disabled = false;
        byId('vbSlug').value = '';
        byId('vbSlug').disabled = false;
        byId('vbLabel').value = '';
        byId('vbRegClass').value = 'amateur';
        byId('vbMode').value = 'p25';
        byId('vbTg').value = '';
        byId('vbPeer').value = '';
        byId('vbHang').value = '400';
        byId('vbBridgeHost').value = '127.0.0.1';
        byId('vbBridgeTxPort').value = '32001';
        byId('vbListenHost').value = '127.0.0.1';
        byId('vbListenPort').value = String(nextFreeListenPort());
        byId('vbEnabled').checked = true;
        byId('vbModalError').className = 'alert alert-danger small mt-2 d-none';
        byId('vbBtnDelete').className = 'btn btn-outline-danger btn-sm d-none me-auto';
        applyAdapterUi();
        updateNetWarn();
    }

    function nextFreeListenPort() {
        var used = {};
        state.channels.forEach(function (c) { used[Number((c.config || {}).listen_port)] = true; });
        var p = 34001;
        while (used[p]) { p++; }
        return p;
    }

    function openNew() {
        resetModal();
        byId('vbModalTitle').textContent = 'New Digital Voice Bridge Channel';
        if (modal) { modal.show(); }
    }

    function findChannel(id) {
        for (var i = 0; i < state.channels.length; i++) {
            if (String(state.channels[i].id) === String(id)) { return state.channels[i]; }
        }
        return null;
    }

    function openEdit(id) {
        var c = findChannel(id);
        if (!c) { return; }
        resetModal();
        var cfg = c.config || {};
        byId('vbModalTitle').textContent = 'Edit ' + c.label;
        byId('vbId').value = c.id;
        byId('vbAdapter').value = c.adapter;
        byId('vbAdapter').disabled = true;     // immutable: it decides the live leg's family
        byId('vbSlug').value = String(c.channel_key).replace(/^[a-z]+:/, '');
        byId('vbSlug').disabled = true;        // immutable: it is the live matrix's channel id
        byId('vbLabel').value = c.label;
        byId('vbRegClass').value = c.regulatory_class;
        byId('vbMode').value = cfg.mode || 'other';
        byId('vbTg').value = cfg.talkgroup || '';
        byId('vbPeer').value = (cfg.fne && cfg.fne.peer_id) ? cfg.fne.peer_id : '';
        byId('vbHang').value = cfg.rx_hang_ms || 400;
        byId('vbBridgeHost').value = cfg.bridge_host || '127.0.0.1';
        byId('vbBridgeTxPort').value = cfg.bridge_tx_port || 32001;
        byId('vbListenHost').value = cfg.listen_host || '127.0.0.1';
        byId('vbListenPort').value = cfg.listen_port || 34001;
        byId('vbEnabled').checked = Number(c.enabled) === 1;
        byId('vbBtnDelete').className = 'btn btn-outline-danger btn-sm me-auto';
        applyAdapterUi();
        updateNetWarn();
        if (modal) { modal.show(); }
    }

    function collect() {
        return {
            label: byId('vbLabel').value,
            regulatory_class: byId('vbRegClass').value,
            mode: byId('vbMode').value,
            talkgroup: byId('vbTg').value,
            fne_peer_id: byId('vbPeer').value,
            rx_hang_ms: byId('vbHang').value,
            bridge_host: byId('vbBridgeHost').value,
            bridge_tx_port: byId('vbBridgeTxPort').value,
            listen_host: byId('vbListenHost').value,
            listen_port: byId('vbListenPort').value,
            enabled: byId('vbEnabled').checked ? 1 : 0
        };
    }

    function saveChannel() {
        var id = parseInt(byId('vbId').value, 10) || 0;
        var body = collect();
        var btn = byId('vbBtnSave');
        btn.disabled = true;
        var done = function () { btn.disabled = false; };
        if (id > 0) {
            body.id = id;
            if (byId('vbAdapter').value !== 'dvmproject') { delete body.fne_peer_id; }
            apiPost('update', body).then(function (res) {
                done();
                if (res && res.ok) {
                    showToast('Channel saved and applied to the running audio-matrix service.', false);
                    load();
                    if (modal) { modal.hide(); }
                } else {
                    showSaveFailure(res, 'Save failed');
                }
            }).catch(function () { done(); showModalError('Save failed (network error)'); });
        } else {
            body.adapter = byId('vbAdapter').value;
            body.slug = byId('vbSlug').value;
            if (body.adapter !== 'dvmproject') { delete body.fne_peer_id; }
            apiPost('create', body).then(function (res) {
                done();
                if (res && res.ok) {
                    showToast('Channel created.', false);
                    load();
                    if (modal) { modal.hide(); }
                } else {
                    showSaveFailure(res, 'Create failed');
                }
            }).catch(function () { done(); showModalError('Create failed (network error)'); });
        }
    }

    function deleteChannel() {
        var id = parseInt(byId('vbId').value, 10) || 0;
        if (!id) { return; }
        if (!window.confirm('Delete this channel? Its UDP port is released immediately and every patch that uses it is removed.')) { return; }
        apiPost('delete', { id: id }).then(function (res) {
            if (res && res.ok) {
                showToast('Channel deleted (' + (res.routes_removed || 0) + ' patch(es) removed).', false);
                load();
                if (modal) { modal.hide(); }
            } else {
                showModalError((res && res.error) || 'Delete failed');
            }
        }).catch(function () { showModalError('Delete failed (network error)'); });
    }

    // ── bridge-config snippet ────────────────────────────────────────
    function openSnippet(id, label) {
        byId('vbSnipTitle').textContent = 'Bridge configuration — ' + label;
        byId('vbSnipText').textContent = 'Loading…';
        byId('vbSnipCopied').className = 'small text-success me-auto d-none';
        if (snipModal) { snipModal.show(); }
        apiGet('snippet', { id: id }).then(function (res) {
            byId('vbSnipText').textContent = (res && res.snippet) ? res.snippet : ((res && res.error) || 'Unavailable');
        }).catch(function () { byId('vbSnipText').textContent = 'Unavailable'; });
    }

    function copySnippet() {
        var text = byId('vbSnipText').textContent;
        var ok = function () { byId('vbSnipCopied').className = 'small text-success me-auto'; };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(ok, function () { selectSnippet(); });
        } else {
            selectSnippet();
        }
    }

    function selectSnippet() {
        var node = byId('vbSnipText');
        var range = document.createRange();
        range.selectNodeContents(node);
        var sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);
    }

    // ── FNE card ─────────────────────────────────────────────────────
    function renderFne() {
        var f = state.fne || {};
        byId('vbFneUrl').value = f.url || '';
        byId('vbFneStale').value = f.stale_secs || 30;
        byId('vbFneVerify').checked = f.verify_tls !== false;
        byId('vbFnePassword').value = '';
        byId('vbFnePwHelp').textContent = f.password_set
            ? 'A password is stored. Leave blank to keep it; type a new one to replace it.'
            : 'Write-only. No password is stored yet.';
    }

    function saveFne() {
        var body = {
            url: byId('vbFneUrl').value,
            verify_tls: byId('vbFneVerify').checked ? 1 : 0,
            stale_secs: byId('vbFneStale').value
        };
        var pw = byId('vbFnePassword').value;
        if (pw !== '') { body.password = pw; }
        apiPost('fne_settings', body).then(function (res) {
            var warn = byId('vbFneWarn');
            if (res && res.ok) {
                showToast('FNE status settings saved.', false);
                if (res.warning) {
                    warn.textContent = res.warning;
                    warn.className = 'alert alert-warning py-1 px-2 mt-2 mb-0';
                } else {
                    warn.className = 'alert alert-warning py-1 px-2 mt-2 mb-0 d-none';
                }
                state.fne = res.fne;
                renderFne();
            } else {
                showToast((res && res.error) || 'Could not save the FNE settings', true);
            }
        }).catch(function () { showToast('Could not save the FNE settings', true); });
    }

    function checkFne() {
        var out = byId('vbFneResult');
        clear(out);
        out.appendChild(el('span', 'text-body-secondary', 'Checking…'));
        apiGet('fne_status').then(function (res) {
            clear(out);
            if (!res || res.error) { out.appendChild(el('div', 'text-danger', (res && res.error) || 'Check failed')); return; }
            if (!res.configured) {
                out.appendChild(el('div', 'text-body-secondary', 'Not configured: link lights read "unknown".'));
                return;
            }
            if (!res.reachable) {
                out.appendChild(el('div', 'text-danger', 'FNE REST not reachable: ' + (res.error || 'unknown error')));
                return;
            }
            out.appendChild(el('div', null, 'FNE REST reachable. Peers connected: ' + res.peers_connected + ' of ' + res.peers_total + '.'));
            state.channels.forEach(function (c) {
                var r = res.channels ? res.channels[c.id] : null;
                if (!r) { return; }
                var line = c.label + ': link ' + r.state + (r.reason ? ' (' + r.reason + ')' : '');
                if (r.talkgroup) {
                    line += '; talkgroup ' + r.talkgroup.tgid + ' ' + (r.talkgroup.found === null ? 'could not be checked' : (r.talkgroup.found ? 'exists on the FNE' : 'NOT found on the FNE'));
                }
                out.appendChild(el('div', r.state === 'connected' ? 'text-success' : 'text-warning', line));
            });
        }).catch(function () { clear(out); out.appendChild(el('div', 'text-danger', 'Check failed (network error)')); });
    }

    // ── init ─────────────────────────────────────────────────────────
    function init() {
        load();
        byId('vbBtnNew').addEventListener('click', openNew);
        byId('vbBtnSave').addEventListener('click', saveChannel);
        byId('vbBtnDelete').addEventListener('click', deleteChannel);
        byId('vbBtnAck').addEventListener('click', function () { acknowledge(true); });
        byId('vbBtnUnack').addEventListener('click', function () { acknowledge(false); });
        byId('vbAdapter').addEventListener('change', applyAdapterUi);
        byId('vbBridgeHost').addEventListener('input', updateNetWarn);
        byId('vbListenHost').addEventListener('input', updateNetWarn);
        byId('vbSnipCopy').addEventListener('click', copySnippet);
        byId('vbFneSave').addEventListener('click', saveFne);
        byId('vbFneCheck').addEventListener('click', checkFne);
        modalEl.addEventListener('shown.bs.modal', function () {
            var first = byId('vbId').value === '0' ? byId('vbSlug') : byId('vbLabel');
            if (first) { first.focus(); }
        });
        modalEl.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); saveChannel(); }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
