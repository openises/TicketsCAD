/**
 * Phase 149 (2026-08-22) — Inbound SIP/PBX trunk admin page.
 * Modeled on assets/js/matrix-admin.js's own list+modal shape.
 *
 * Phase 155 (2026-10-02): a live "Bridge" connection badge per trunk (driven
 * by the heartbeat the bridge sends), a Setup dialog that generates the
 * bridge.ini for the chosen phone system, and a Send-test-call button. A beta
 * tester minted a token and then had nothing telling them what to do next or
 * whether the bridge was running.
 *
 * ES5 only (project rule).
 */
(function () {
    'use strict';

    var csrfToken = document.getElementById('csrfToken').value;
    var trunks = [];
    var orgs = [];
    var modalEl = document.getElementById('stModal');
    var modal = window.bootstrap ? new bootstrap.Modal(modalEl) : null;
    var setupEl = document.getElementById('stSetupModal');
    var setupModal = window.bootstrap ? new bootstrap.Modal(setupEl) : null;
    var setupState = { trunkId: 0, label: '', token: '', pollTimer: null };
    var STEPS = {
        threecx: [
            'Needs <strong>3CX AI Edition</strong> (formerly Enterprise) - the only edition with the Call Control API. In the 3CX Admin Console open <strong>Integrations &rarr; API &rarr; Add</strong>, give it any <em>Client ID</em>, tick <em>3CX Call Control API Access</em>, and under <em>Extensions to monitor</em> add <strong>every dispatch extension (3CX sends nothing for an empty list)</strong>. Leave DID numbers empty. Save, and <strong>copy the API key - 3CX shows it once</strong>.',
            'On a computer that can reach both 3CX and this server, install Python 3, then run <code>pip install requests websockets</code>.',
            'Copy the <code>services/sip-bridge</code> folder there, and save the text below as <code>bridge.ini</code> next to <code>bridge.py</code>. Fill in your 3CX address and the API key.',
            'Run <code>python bridge.py --config bridge.ini --check</code> &mdash; it names exactly what is wrong, if anything. Fix every red line.',
            'Run <code>python bridge.py --config bridge.ini</code> (or install it as a service). The badge above turns green within about 30 seconds.'
        ],
        ami: [
            'In Asterisk\'s <code>manager.conf</code> create a dedicated user for the bridge with <code>read = call,cdr</code>, and reload the manager.',
            'On a computer that can reach both Asterisk and this server, install Python 3, then run <code>pip install requests</code>.',
            'Copy the <code>services/sip-bridge</code> folder there, and save the text below as <code>bridge.ini</code> next to <code>bridge.py</code>.',
            'Run <code>python bridge.py --config bridge.ini --check</code> and fix every red line.',
            'Run <code>python bridge.py --config bridge.ini</code> (or install it as a service). The badge above turns green within about 30 seconds.'
        ],
        webhook: [
            'In your SIP provider\'s portal, point its call-event webhook at <code>http://&lt;bridge-computer&gt;:8085/</code>.',
            'On the bridge computer install Python 3, then run <code>pip install requests</code>.',
            'Copy the <code>services/sip-bridge</code> folder there, and save the text below as <code>bridge.ini</code> next to <code>bridge.py</code>. The built-in <code>generic</code> adapter expects JSON with <code>event</code>, <code>caller_number</code>, <code>called_number</code> and <code>call_id</code>; a provider with a different shape needs one small adapter function in <code>bridge.py</code>.',
            'Run <code>python bridge.py --config bridge.ini --check</code> and fix every red line.',
            'Run <code>python bridge.py --config bridge.ini</code>. The badge above turns green within about 30 seconds.'
        ]
    };

    function csrfHeaders() { return { 'Content-Type': 'application/json' }; }

    function apiGet(action, params) {
        var qs = 'action=' + encodeURIComponent(action);
        if (params) {
            for (var k in params) {
                if (params.hasOwnProperty(k)) qs += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(params[k]);
            }
        }
        return fetch('api/sip-trunks.php?' + qs, { credentials: 'same-origin' }).then(function (r) { return r.json(); });
    }

    function apiPost(action, body) {
        body = body || {};
        body.csrf_token = csrfToken;
        return fetch('api/sip-trunks.php?action=' + encodeURIComponent(action), {
            method: 'POST',
            credentials: 'same-origin',
            headers: csrfHeaders(),
            body: JSON.stringify(body)
        }).then(function (r) { return r.json(); });
    }

    function showToast(msg, isError) {
        var el = document.getElementById('stToast');
        el.className = 'alert ' + (isError ? 'alert-danger' : 'alert-success');
        el.textContent = msg;
        setTimeout(function () { el.className = 'alert d-none'; }, 6000);
    }

    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function orgName(id) {
        if (id === null || id === undefined) return 'Install-wide';
        for (var i = 0; i < orgs.length; i++) {
            if (String(orgs[i].id) === String(id)) return orgs[i].name || ('Org #' + id);
        }
        return 'Org #' + id;
    }

    function humanAge(seconds) {
        if (seconds == null) return '';
        if (seconds < 90) return seconds + 's';
        if (seconds < 5400) return Math.round(seconds / 60) + ' min';
        if (seconds < 172800) return Math.round(seconds / 3600) + ' h';
        return Math.round(seconds / 86400) + ' d';
    }

    function bridgeBadgeParts(t) {
        if (t.heartbeat_supported === false) {
            return { cls: 'text-bg-secondary', text: 'Unknown', title: 'Run php sql/run_migrations.php to enable bridge status' };
        }
        if (t.connection_state === 'connected') {
            return { cls: 'text-bg-success', text: 'Connected', title: (t.bridge_info || 'bridge') + ' - last heartbeat ' + humanAge(t.heartbeat_age_seconds) + ' ago' };
        }
        if (t.connection_state === 'silent') {
            return { cls: 'text-bg-warning', text: 'Silent ' + humanAge(t.heartbeat_age_seconds), title: 'The bridge connected before but has not been heard from since. Is it still running?' };
        }
        return { cls: 'text-bg-secondary', text: 'Waiting for bridge', title: 'No bridge has contacted this trunk yet.', setupHint: true };
    }

    function bridgeBadge(t) {
        var b = bridgeBadgeParts(t);
        return '<span class="badge ' + b.cls + '" title="' + escapeHtml(b.title + (b.setupHint ? ' Click Setup for the steps.' : '')) + '">' + escapeHtml(b.text) + '</span>'
            + (t.last_call_at ? '<div class="small text-body-secondary">last call ' + escapeHtml(String(t.last_call_at).slice(0, 16)) + '</div>' : '');
    }

    function renderList() {
        var tbody = document.getElementById('stListRows');
        if (!trunks.length) {
            tbody.innerHTML = '<tr><td colspan="9" class="text-body-secondary">No trunks configured yet. Click "New Trunk" to add one.</td></tr>';
            return;
        }
        var html = '';
        for (var i = 0; i < trunks.length; i++) {
            var t = trunks[i];
            html += '<tr>'
                + '<td>' + escapeHtml(t.label) + '</td>'
                + '<td>' + escapeHtml(orgName(t.org_id)) + '</td>'
                + '<td>' + (t.mute_bypass_enabled ? '<span class="badge text-bg-success">On</span>' : '<span class="badge text-bg-secondary">Off</span>') + '</td>'
                + '<td class="text-end">' + escapeHtml(t.wrapup_seconds) + '</td>'
                + '<td class="text-end">' + escapeHtml(t.reassign_grace_seconds) + '</td>'
                + '<td>' + (t.has_token ? '<span class="badge text-bg-success">Set</span>' : '<span class="badge text-bg-warning">None</span>') + '</td>'
                + '<td>' + bridgeBadge(t) + '</td>'
                + '<td>' + (Number(t.enabled) === 1 ? '<span class="badge text-bg-success">Enabled</span>' : '<span class="badge text-bg-secondary">Disabled</span>') + '</td>'
                + '<td class="text-end">'
                + '<button type="button" class="btn btn-sm btn-outline-primary st-setup" data-id="' + t.id + '" title="Connect your phone system"><i class="bi bi-plug me-1"></i>Setup</button> '
                + '<button type="button" class="btn btn-sm btn-outline-secondary st-edit" data-id="' + t.id + '"><i class="bi bi-pencil"></i></button> '
                + '<button type="button" class="btn btn-sm btn-outline-' + (Number(t.enabled) === 1 ? 'warning' : 'success') + ' st-toggle" data-id="' + t.id + '">'
                + (Number(t.enabled) === 1 ? '<i class="bi bi-pause-fill"></i>' : '<i class="bi bi-play-fill"></i>') + '</button>'
                + '</td></tr>';
        }
        tbody.innerHTML = html;

        var setupBtns = tbody.querySelectorAll('.st-setup');
        for (var s2 = 0; s2 < setupBtns.length; s2++) {
            setupBtns[s2].addEventListener('click', function (ev) { openSetup(ev.currentTarget.getAttribute('data-id'), ''); });
        }
        var editBtns = tbody.querySelectorAll('.st-edit');
        for (var e = 0; e < editBtns.length; e++) {
            editBtns[e].addEventListener('click', function (ev) { openEdit(ev.currentTarget.getAttribute('data-id')); });
        }
        var toggleBtns = tbody.querySelectorAll('.st-toggle');
        for (var g = 0; g < toggleBtns.length; g++) {
            toggleBtns[g].addEventListener('click', function (ev) { toggleTrunk(ev.currentTarget.getAttribute('data-id')); });
        }
    }

    function loadTrunks() {
        apiGet('trunks').then(function (data) {
            if (data && data.trunks) {
                trunks = data.trunks;
                renderList();
            } else if (data && data.error) {
                showToast(data.error, true);
            }
        }).catch(function () { showToast('Failed to load trunks', true); });
    }

    function loadOrgs() {
        // Best-effort — the org dropdown is a convenience; a failure here
        // just leaves it with only the "install-wide" option.
        fetch('api/organizations.php', { credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) {
                if (!data) return;
                orgs = data.organizations || data.orgs || [];
                var sel = document.getElementById('stOrgId');
                for (var i = 0; i < orgs.length; i++) {
                    var opt = document.createElement('option');
                    opt.value = orgs[i].id;
                    opt.textContent = orgs[i].name || ('Org #' + orgs[i].id);
                    sel.appendChild(opt);
                }
                renderList(); // re-render now that org names are resolvable
            })
            .catch(function () { /* best effort */ });
    }

    // ── Setup dialog (Phase 155) ──────────────────────────────────────────
    function appBaseUrl() {
        var path = window.location.pathname.replace(/[^\/]*$/, '').replace(/\/$/, '');
        return window.location.origin + path;
    }

    function buildIni(type, url, token) {
        var tok = token || 'PASTE-THE-TRUNK-TOKEN-HERE';
        var lines = ['[sip-bridge]'];
        if (type === 'threecx') {
            lines.push('mode = threecx',
                'ticketscad_url = ' + url,
                'bearer_token = ' + tok,
                '',
                '# 3CX Admin Console > Integrations > API',
                'threecx_url = https://YOUR-3CX-ADDRESS',
                'threecx_client_id = THE-CLIENT-ID-YOU-ENTERED',
                'threecx_client_secret = PASTE-THE-3CX-API-KEY',
                '# threecx_monitor_dns = 100,101,102',
                '# threecx_trunk_did_map = 10001=+16125550100   # 3CX does not report the dialed number',
                '# threecx_verify_tls = false    # only for a self-signed 3CX certificate',
                '# capture_file = threecx-capture.jsonl   # record real traffic (numbers are masked) to send us');
        } else if (type === 'ami') {
            lines.push('mode = ami',
                'ticketscad_url = ' + url,
                'bearer_token = ' + tok,
                '',
                'ami_host = YOUR-ASTERISK-ADDRESS',
                'ami_port = 5038',
                'ami_user = cad-bridge',
                'ami_secret = PASTE-THE-MANAGER-PASSWORD');
        } else {
            lines.push('mode = webhook',
                'ticketscad_url = ' + url,
                'bearer_token = ' + tok,
                '',
                'listen_port = 8085',
                'provider = generic');
        }
        lines.push('', 'heartbeat_seconds = 30');
        return lines.join('\n');
    }

    function refreshSetupConfig() {
        var type = document.getElementById('stPbxType').value;
        var url = document.getElementById('stSetupUrl').value.replace(/\/+$/, '');
        document.getElementById('stConfigOut').textContent = buildIni(type, url, setupState.token);
        var steps = STEPS[type] || [];
        var html = '';
        for (var i = 0; i < steps.length; i++) { html += '<li class="mb-1">' + steps[i] + '</li>'; }
        document.getElementById('stSetupSteps').innerHTML = html;
        document.getElementById('stSetupTokenNote').textContent = setupState.token
            ? '(your new token is already filled in - it is shown only now)'
            : '(replace the token placeholder - use Rotate Token on the trunk if you no longer have it)';
    }

    function paintSetupStatus(t) {
        var b = bridgeBadgeParts(t);
        var el = document.getElementById('stSetupStatus');
        el.className = 'badge ' + b.cls;
        el.textContent = t.connection_state === 'connected' ? 'Bridge connected' : b.text;
        document.getElementById('stSetupStatusNote').textContent = b.title;
    }

    function pollSetupStatus() {
        if (!setupState.trunkId) return;
        apiGet('trunk', { id: setupState.trunkId }).then(function (data) {
            if (data && data.trunk) paintSetupStatus(data.trunk);
        }).catch(function () {});
    }

    function openSetup(id, token) {
        var t = null;
        for (var i = 0; i < trunks.length; i++) { if (String(trunks[i].id) === String(id)) { t = trunks[i]; break; } }
        setupState.trunkId = parseInt(id, 10) || 0;
        setupState.label = t ? t.label : '';
        setupState.token = token || '';
        document.getElementById('stSetupTrunkLabel').textContent = setupState.label;
        document.getElementById('stSetupUrl').value = appBaseUrl();
        document.getElementById('stTestCallNote').textContent = '';
        document.getElementById('stBtnTestCall').disabled = false;
        refreshSetupConfig();
        if (t) paintSetupStatus(t);
        if (setupModal) setupModal.show();
        if (setupState.pollTimer) clearInterval(setupState.pollTimer);
        setupState.pollTimer = setInterval(pollSetupStatus, 5000);
        pollSetupStatus();
    }

    function sendTestCall() {
        if (!setupState.trunkId) return;
        if (!window.confirm('This rings a TEST call on every logged-in dispatcher\'s screen for about 12 seconds (banner and tone). Continue?')) return;
        var note = document.getElementById('stTestCallNote');
        var btn = document.getElementById('stBtnTestCall');
        btn.disabled = true;
        apiPost('trunk_test_call', { id: setupState.trunkId }).then(function (res) {
            if (res && res.success) {
                note.textContent = 'Ringing now - look for the banner under the navbar...';
                var pid = res.provider_call_id;
                var tid = setupState.trunkId;
                setTimeout(function () {
                    apiPost('trunk_test_call_end', { id: tid, provider_call_id: pid }).then(function () {
                        note.textContent = 'Test finished. If you saw and heard the banner, the TicketsCAD side works.';
                        btn.disabled = false;
                    });
                }, 12000);
            } else {
                note.textContent = (res && res.error) || 'Test call failed';
                btn.disabled = false;
            }
        }).catch(function () { note.textContent = 'Test call failed'; btn.disabled = false; });
    }

    function resetModal() {
        document.getElementById('stId').value = '0';
        document.getElementById('stLabel').value = '';
        document.getElementById('stOrgId').value = '';
        document.getElementById('stWrapup').value = '90';
        document.getElementById('stGrace').value = '20';
        document.getElementById('stMuteBypass').checked = true;
        document.getElementById('stEnabled').checked = true;
        document.getElementById('stModalError').className = 'alert alert-danger small mt-2 d-none';
        document.getElementById('stTokenWrap').className = 'd-none';
        document.getElementById('stBtnDelete').className = 'btn btn-outline-danger btn-sm d-none';
        document.getElementById('stBtnRotate').className = 'btn btn-outline-warning btn-sm me-auto d-none';
    }

    function openNew() {
        resetModal();
        document.getElementById('stModalTitle').innerHTML = '<i class="bi bi-plus-lg me-2"></i>New Trunk';
        if (modal) modal.show();
    }

    function openEdit(id) {
        var t = null;
        for (var i = 0; i < trunks.length; i++) { if (String(trunks[i].id) === String(id)) { t = trunks[i]; break; } }
        if (!t) return;
        resetModal();
        document.getElementById('stModalTitle').innerHTML = '<i class="bi bi-pencil me-2"></i>Edit Trunk';
        document.getElementById('stId').value = t.id;
        document.getElementById('stLabel').value = t.label;
        document.getElementById('stOrgId').value = t.org_id === null ? '' : t.org_id;
        document.getElementById('stWrapup').value = t.wrapup_seconds;
        document.getElementById('stGrace').value = t.reassign_grace_seconds;
        document.getElementById('stMuteBypass').checked = !!Number(t.mute_bypass_enabled);
        document.getElementById('stEnabled').checked = Number(t.enabled) === 1;
        document.getElementById('stBtnDelete').className = 'btn btn-outline-danger btn-sm';
        document.getElementById('stBtnRotate').className = 'btn btn-outline-warning btn-sm me-auto';
        if (modal) modal.show();
    }

    function showModalError(msg) {
        var el = document.getElementById('stModalError');
        el.textContent = msg;
        el.className = 'alert alert-danger small mt-2';
    }

    function showToken(token, note) {
        document.getElementById('stTokenValue').value = token;
        document.getElementById('stTokenWrap').className = '';
        if (note) showToast(note, false);
    }

    function saveTrunk() {
        var id = parseInt(document.getElementById('stId').value, 10) || 0;
        var body = {
            label: document.getElementById('stLabel').value,
            org_id: document.getElementById('stOrgId').value,
            wrapup_seconds: document.getElementById('stWrapup').value,
            reassign_grace_seconds: document.getElementById('stGrace').value,
            mute_bypass_enabled: document.getElementById('stMuteBypass').checked ? 1 : 0
        };
        if (id > 0) {
            body.id = id;
            apiPost('trunk_update', body).then(function (res) {
                if (res && res.success) {
                    showToast('Trunk saved.', false);
                    loadTrunks();
                    if (modal) modal.hide();
                } else {
                    showModalError((res && res.error) || 'Save failed');
                }
            });
        } else {
            apiPost('trunk_create', body).then(function (res) {
                if (res && res.trunk_id) {
                    loadTrunks();
                    if (modal) modal.hide();
                    // loadTrunks() is async; give it a beat so the new row exists for the dialog's label/status.
                    setTimeout(function () { openSetup(res.trunk_id, res.bearer_token); }, 400);
                } else {
                    showModalError((res && res.error) || 'Create failed');
                }
            });
        }
    }

    function toggleTrunk(id) {
        apiPost('trunk_toggle', { id: id }).then(function (res) {
            if (res && res.success) { loadTrunks(); } else { showToast((res && res.error) || 'Toggle failed', true); }
        });
    }

    function deleteTrunk() {
        var id = parseInt(document.getElementById('stId').value, 10) || 0;
        if (!id) return;
        if (!window.confirm('Delete this trunk? Historical call records are kept; the trunk config itself cannot be undone.')) return;
        apiPost('trunk_delete', { id: id }).then(function (res) {
            if (res && res.success) {
                showToast('Trunk deleted.', false);
                loadTrunks();
                if (modal) modal.hide();
            } else {
                showModalError((res && res.error) || 'Delete failed');
            }
        });
    }

    function rotateToken() {
        var id = parseInt(document.getElementById('stId').value, 10) || 0;
        if (!id) return;
        if (!window.confirm('Rotate this trunk\'s bearer token? The old token stops working immediately.')) return;
        apiPost('trunk_rotate_token', { id: id }).then(function (res) {
            if (res && res.bearer_token) {
                loadTrunks();
                if (modal) modal.hide();
                setTimeout(function () { openSetup(id, res.bearer_token); }, 400);
            } else {
                showModalError((res && res.error) || 'Rotate failed');
            }
        });
    }

    function init() {
        loadTrunks();
        loadOrgs();
        document.getElementById('stBtnNew').addEventListener('click', openNew);
        document.getElementById('stBtnSave').addEventListener('click', saveTrunk);
        document.getElementById('stBtnDelete').addEventListener('click', deleteTrunk);
        document.getElementById('stBtnRotate').addEventListener('click', rotateToken);
        var copyBtn = document.getElementById('stBtnCopyToken');
        copyBtn.addEventListener('click', function () {
            var input = document.getElementById('stTokenValue');
            input.select();
            try { document.execCommand('copy'); } catch (e) {}
        });
        document.getElementById('stPbxType').addEventListener('change', refreshSetupConfig);
        document.getElementById('stSetupUrl').addEventListener('input', refreshSetupConfig);
        document.getElementById('stBtnTestCall').addEventListener('click', sendTestCall);
        document.getElementById('stBtnCopyConfig').addEventListener('click', function () {
            var text = document.getElementById('stConfigOut').textContent;
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).catch(function () {});
            } else {
                var ta = document.createElement('textarea');
                ta.value = text;
                document.body.appendChild(ta);
                ta.select();
                try { document.execCommand('copy'); } catch (e) {}
                document.body.removeChild(ta);
            }
            showToast('bridge.ini copied.', false);
        });
        setupEl.addEventListener('hidden.bs.modal', function () {
            if (setupState.pollTimer) { clearInterval(setupState.pollTimer); setupState.pollTimer = null; }
            setupState.token = '';      // never keep a minted token around longer than the dialog
            loadTrunks();
        });
        // Keep the Bridge column live while the page is open and visible.
        setInterval(function () { if (document.visibilityState === 'visible') loadTrunks(); }, 10000);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
