/**
 * Phase 73k — DVSwitch (DMR) admin panel controller.
 *
 * Lives behind the settings.php panel id="panel-dvswitch-dmr". Mirrors
 * the routing/Mesh-Console patterns: list rows from
 * /api/dvswitch.php?action=channels, modal-driven create/edit, on-row
 * test buttons that proxy through to the bridge's /health and /tx/test
 * via the api/dvswitch.php?action=channel_test_* endpoints.
 *
 * Bearer-token convention: the plain token is shown exactly once (on
 * create or rotate). The admin pastes it back into the Test modal when
 * they want to ping the bridge. We never round-trip the plain token
 * through the DB.
 */
(function () {
    'use strict';

    function $(id) { return document.getElementById(id); }
    function esc(s) {
        var d = document.createElement('div');
        d.appendChild(document.createTextNode(s == null ? '' : String(s)));
        return d.innerHTML;
    }
    function csrf() {
        var m = document.querySelector('meta[name="csrf-token"]');
        return m ? m.content : '';
    }

    var modal = null;
    var testModal = null;

    function ensureModals() {
        if (!modal) {
            var el = $('dvsChannelModal');
            if (el && typeof bootstrap !== 'undefined') {
                modal = bootstrap.Modal.getOrCreateInstance(el);
            }
        }
        if (!testModal) {
            var el2 = $('dvsTestModal');
            if (el2 && typeof bootstrap !== 'undefined') {
                testModal = bootstrap.Modal.getOrCreateInstance(el2);
            }
        }
    }

    function loadChannels() {
        var body = $('dvsTableBody');
        if (!body) return;
        body.innerHTML = '<tr><td colspan="9" class="text-center text-body-secondary small">Loading...</td></tr>';
        fetch('api/dvswitch.php?action=channels', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d.error) {
                    body.innerHTML = '<tr><td colspan="9" class="text-danger small">'
                        + esc(d.error) + '</td></tr>';
                    return;
                }
                var rows = d.channels || [];
                if (!rows.length) {
                    body.innerHTML = '<tr><td colspan="9" class="text-center text-body-secondary small">'
                        + 'No channels configured. Click <strong>New Channel</strong> to link a talkgroup.</td></tr>';
                    return;
                }
                body.innerHTML = rows.map(rowHtml).join('');
                bindRowButtons();
            })
            .catch(function (err) {
                body.innerHTML = '<tr><td colspan="9" class="text-danger small">'
                    + esc(err.message || String(err)) + '</td></tr>';
            });
    }

    function rowHtml(c) {
        var enabled = parseInt(c.enabled, 10) === 1;
        var statusBadge = enabled
            ? '<span class="badge bg-success">enabled</span>'
            : '<span class="badge bg-secondary">disabled</span>';
        if (c.last_error) {
            statusBadge += ' <span class="badge bg-danger" title="' + esc(c.last_error)
                + '">last-err</span>';
        }
        if (c.last_seen_at) {
            statusBadge += ' <span class="badge bg-info text-dark">seen ' + esc(c.last_seen_at) + '</span>';
        }
        if (!parseInt(c.has_token, 10)) {
            statusBadge += ' <span class="badge bg-warning text-dark">no token</span>';
        }
        var mode = ({
            rx_only: 'RX', tx_only: 'TX', bidirectional: 'RX+TX',
        })[c.link_mode] || c.link_mode;

        return ''
            + '<tr data-id="' + c.id + '">'
            + '  <td><strong>' + esc(c.label) + '</strong></td>'
            + '  <td>' + esc(c.talkgroup) + '</td>'
            + '  <td>' + esc(c.network) + '</td>'
            + '  <td><span class="badge bg-primary">' + esc(mode) + '</span></td>'
            + '  <td>' + esc(c.chat_channel) + '</td>'
            + '  <td><code class="small">' + esc(c.bridge_host) + ':' + esc(c.bridge_port) + '</code></td>'
            + '  <td><code class="small">'
            + esc(c.usrp_listen_port) + '/' + esc(c.usrp_send_port) + '</code></td>'
            + '  <td>' + statusBadge + '</td>'
            + '  <td class="text-end">'
            + '    <button class="btn btn-xs btn-outline-secondary dvs-edit" title="Edit">'
            + '      <i class="bi bi-pencil"></i></button>'
            + '    <button class="btn btn-xs btn-outline-warning dvs-toggle" title="'
            + (enabled ? 'Disable' : 'Enable') + '">'
            + '      <i class="bi bi-' + (enabled ? 'pause' : 'play') + '-fill"></i></button>'
            + '    <button class="btn btn-xs btn-outline-info dvs-test" title="Test">'
            + '      <i class="bi bi-broadcast"></i></button>'
            + '    <button class="btn btn-xs btn-outline-secondary dvs-rotate" title="Rotate token">'
            + '      <i class="bi bi-arrow-clockwise"></i></button>'
            + '    <button class="btn btn-xs btn-outline-danger dvs-delete" title="Delete">'
            + '      <i class="bi bi-trash"></i></button>'
            + '  </td>'
            + '</tr>';
    }

    function bindRowButtons() {
        document.querySelectorAll('#dvsTableBody tr').forEach(function (tr) {
            var id = parseInt(tr.getAttribute('data-id'), 10);
            tr.querySelector('.dvs-edit').addEventListener('click', function () { openEdit(id); });
            tr.querySelector('.dvs-toggle').addEventListener('click', function () { toggleChannel(id); });
            tr.querySelector('.dvs-test').addEventListener('click', function () { openTest(id); });
            tr.querySelector('.dvs-rotate').addEventListener('click', function () { rotateToken(id); });
            tr.querySelector('.dvs-delete').addEventListener('click', function () { deleteChannel(id); });
        });
    }

    function clearForm() {
        $('dvsId').value = '';
        $('dvsLabel').value = '';
        $('dvsTg').value = '';
        $('dvsNetwork').value = 'BrandMeister';
        $('dvsLinkMode').value = 'rx_only';
        $('dvsBridgeHost').value = '10.0.0.10';
        $('dvsBridgePort').value = '18091';
        $('dvsChatChannel').value = 'dispatch';
        $('dvsUsrpListenPort').value = '';
        $('dvsUsrpSendPort').value = '';
        $('dvsSttEngine').value = '';
        $('dvsTtsEngine').value = '';
        $('dvsRouteToBroker').checked = true;
        $('dvsTokenReveal').classList.add('d-none');
        $('dvsTokenValue').textContent = '';
        $('dvsModalTitle').textContent = 'New DMR Channel';
        // Editable fields are editable on create; on edit, label/ports are read-only
        ['dvsLabel', 'dvsUsrpListenPort', 'dvsUsrpSendPort'].forEach(function (id) {
            $(id).readOnly = false;
        });
    }

    function openNew() {
        clearForm();
        ensureModals();
        modal && modal.show();
    }

    function openEdit(id) {
        clearForm();
        fetch('api/dvswitch.php?action=channel&id=' + id, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d.error) { alert(d.error); return; }
                var c = d.channel;
                $('dvsId').value = c.id;
                $('dvsLabel').value = c.label;
                $('dvsLabel').readOnly = true;  // changing it would orphan the systemd unit
                $('dvsTg').value = c.talkgroup;
                $('dvsNetwork').value = c.network;
                $('dvsLinkMode').value = c.link_mode;
                $('dvsBridgeHost').value = c.bridge_host;
                $('dvsBridgePort').value = c.bridge_port;
                $('dvsChatChannel').value = c.chat_channel;
                $('dvsUsrpListenPort').value = c.usrp_listen_port;
                $('dvsUsrpListenPort').readOnly = true;
                $('dvsUsrpSendPort').value = c.usrp_send_port;
                $('dvsUsrpSendPort').readOnly = true;
                $('dvsSttEngine').value = c.stt_engine || '';
                $('dvsTtsEngine').value = c.tts_engine || '';
                $('dvsRouteToBroker').checked = parseInt(c.route_to_broker, 10) === 1;
                $('dvsModalTitle').textContent = 'Edit DMR Channel — ' + c.label;
                ensureModals();
                modal && modal.show();
            });
    }

    function submitForm(ev) {
        ev.preventDefault();
        var id = parseInt($('dvsId').value, 10) || 0;
        var payload = {
            csrf_token: csrf(),
            label: $('dvsLabel').value.trim(),
            talkgroup: $('dvsTg').value.trim(),
            network: $('dvsNetwork').value,
            link_mode: $('dvsLinkMode').value,
            bridge_host: $('dvsBridgeHost').value.trim(),
            bridge_port: parseInt($('dvsBridgePort').value, 10) || 18091,
            chat_channel: $('dvsChatChannel').value.trim(),
            tts_engine: $('dvsTtsEngine').value || null,
            stt_engine: $('dvsSttEngine').value || null,
            route_to_broker: $('dvsRouteToBroker').checked ? 1 : 0,
        };
        if ($('dvsUsrpListenPort').value) payload.usrp_listen_port = parseInt($('dvsUsrpListenPort').value, 10);
        if ($('dvsUsrpSendPort').value)   payload.usrp_send_port   = parseInt($('dvsUsrpSendPort').value, 10);

        if (id) {
            payload.action = 'channel_update';
            payload.id = id;
        } else {
            payload.action = 'channel_create';
        }

        fetch('api/dvswitch.php', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        }).then(function (r) { return r.json(); }).then(function (d) {
            if (d.error) { alert(d.error); return; }
            if (d.bridge_token) {
                $('dvsTokenReveal').classList.remove('d-none');
                $('dvsTokenValue').textContent = d.bridge_token;
            } else {
                modal && modal.hide();
            }
            loadChannels();
        });
    }

    function toggleChannel(id) {
        // Need to know current state — fetch fresh row.
        fetch('api/dvswitch.php?action=channel&id=' + id, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); }).then(function (d) {
                if (d.error) { alert(d.error); return; }
                var nowEnabled = parseInt(d.channel.enabled, 10) === 1;
                fetch('api/dvswitch.php', {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'channel_toggle',
                        csrf_token: csrf(),
                        id: id,
                        enabled: !nowEnabled,
                    }),
                }).then(function (r) { return r.json(); }).then(function (resp) {
                    if (resp.error) { alert(resp.error); return; }
                    loadChannels();
                });
            });
    }

    function rotateToken(id) {
        if (!confirm('Mint a new bearer token? The old token will stop working immediately.')) return;
        fetch('api/dvswitch.php', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'channel_rotate_token',
                csrf_token: csrf(),
                id: id,
            }),
        }).then(function (r) { return r.json(); }).then(function (d) {
            if (d.error) { alert(d.error); return; }
            if (d.bridge_token) {
                openEdit(id);
                setTimeout(function () {
                    $('dvsTokenReveal').classList.remove('d-none');
                    $('dvsTokenValue').textContent = d.bridge_token;
                }, 250);
            }
        });
    }

    function deleteChannel(id) {
        if (!confirm('Soft-delete this channel? It will be disabled and its token cleared. Re-enabling requires a new token.')) return;
        fetch('api/dvswitch.php', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'channel_delete',
                csrf_token: csrf(),
                id: id,
            }),
        }).then(function (r) { return r.json(); }).then(function (d) {
            if (d.error) { alert(d.error); return; }
            loadChannels();
        });
    }

    function openTest(id) {
        $('dvsTestId').value = id;
        $('dvsTestToken').value = '';
        $('dvsTestResult').textContent = '(no response yet)';
        ensureModals();
        testModal && testModal.show();
    }

    function runHealthTest() {
        var id = $('dvsTestId').value;
        var tok = encodeURIComponent($('dvsTestToken').value.trim());
        if (!tok) { alert('Paste the bearer token first'); return; }
        $('dvsTestResult').textContent = 'probing…';
        fetch('api/dvswitch.php?action=channel_test_health&id=' + id + '&token=' + tok,
              { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                $('dvsTestResult').textContent = JSON.stringify(d, null, 2);
                loadChannels();
            })
            .catch(function (e) {
                $('dvsTestResult').textContent = 'ERR: ' + e.message;
            });
    }

    function runTxTest() {
        var id = parseInt($('dvsTestId').value, 10);
        var tok = $('dvsTestToken').value.trim();
        if (!tok) { alert('Paste the bearer token first'); return; }
        $('dvsTestResult').textContent = 'sending tone…';
        fetch('api/dvswitch.php', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'channel_test_tx',
                csrf_token: csrf(),
                id: id, token: tok, duration_s: 0.5,
            }),
        }).then(function (r) { return r.json(); }).then(function (d) {
            $('dvsTestResult').textContent = JSON.stringify(d, null, 2);
        }).catch(function (e) {
            $('dvsTestResult').textContent = 'ERR: ' + e.message;
        });
    }

    function runTxText() {
        var id = parseInt($('dvsTestId').value, 10);
        var tok = $('dvsTestToken').value.trim();
        var text = ($('dvsTxTextBody').value || '').trim();
        if (!tok)  { alert('Paste the bearer token first'); return; }
        if (!text) { alert('Type something to speak first'); return; }
        $('dvsTestResult').textContent = 'synthesising + transmitting…';
        fetch('api/dvswitch.php', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'channel_tx_text',
                csrf_token: csrf(),
                id: id, token: tok, text: text,
            }),
        }).then(function (r) { return r.json(); }).then(function (d) {
            $('dvsTestResult').textContent = JSON.stringify(d, null, 2);
            // Refresh transcripts panel if open — the TX record will
            // appear once bridge.py's _post_ingest writes it back.
            if (!$('dvsMessagesBox').classList.contains('d-none')) {
                setTimeout(loadRecentMessages, 1500);
            }
        }).catch(function (e) {
            $('dvsTestResult').textContent = 'ERR: ' + e.message;
        });
    }

    function loadRecentMessages() {
        var id = parseInt($('dvsTestId').value, 10);
        if (!id) return;
        var box = $('dvsMessagesBox');
        var tbody = $('dvsMessagesTable').querySelector('tbody');
        box.classList.remove('d-none');
        tbody.innerHTML = '<tr><td colspan="6" class="text-body-secondary">loading…</td></tr>';
        fetch('api/dvswitch.php?action=channel_recent_messages&id=' + id + '&limit=25',
              { credentials: 'same-origin' })
            .then(function (r) { return r.json(); }).then(function (d) {
                if (d.error) {
                    tbody.innerHTML = '<tr><td colspan="6" class="text-danger">' + esc(d.error) + '</td></tr>';
                    return;
                }
                var msgs = d.messages || [];
                if (!msgs.length) {
                    tbody.innerHTML = '<tr><td colspan="6" class="text-body-secondary">no transcripts yet for this channel</td></tr>';
                    return;
                }
                var html = msgs.map(function (m) {
                    var dirIcon = m.direction === 'tx'
                        ? '<i class="bi bi-mic-fill text-warning" title="TX"></i>'
                        : '<i class="bi bi-broadcast text-success" title="RX"></i>';
                    var who = m.radio_callsign || m.radio_id || '—';
                    var transcript = m.transcript || (m.error ? '⚠ ' + m.error : '(no transcript)');
                    // Phase 77b — DVR-style playback button for any call that
                    // has audio_path set. The "play" cell holds a button that
                    // injects an <audio> element on click; we lazy-mount the
                    // element so we don't preload 25 audio streams when the
                    // panel opens.
                    var playCell = '<td class="text-nowrap small">—</td>';
                    if (m.audio_path) {
                        playCell = '<td class="text-nowrap small">' +
                            '<button type="button" class="btn btn-outline-secondary btn-sm dvs-play-btn"' +
                            ' data-msg-id="' + esc(m.id) + '" data-duration="' +
                            esc(m.duration_ms || '') + '"' +
                            ' title="DVR playback (asks for bridge token if not set)">' +
                            '<i class="bi bi-play-circle"></i>' +
                            '</button>' +
                            '</td>';
                    }
                    return '<tr>' +
                        '<td class="text-nowrap small">' + esc(m.call_started_at || '') + '</td>' +
                        '<td>' + dirIcon + '</td>' +
                        '<td class="text-nowrap small">' + esc(m.talkgroup || '') + '</td>' +
                        '<td class="text-nowrap small">' + esc(who) + '</td>' +
                        '<td>' + esc(transcript) + '</td>' +
                        playCell +
                    '</tr>';
                }).join('');
                tbody.innerHTML = html;
                bindDvsPlayButtons(tbody);
            }).catch(function (e) {
                tbody.innerHTML = '<tr><td colspan="6" class="text-danger">load failed: ' + esc(e.message) + '</td></tr>';
            });
    }

    // Phase 77b — DVR-style audio playback.
    // The bridge bearer token is needed to fetch recordings; ask once
    // per session and reuse from sessionStorage. Clicking "play" replaces
    // the button with an inline <audio> player. The dispatcher gets
    // standard HTML5 transport controls (play/pause, scrub, volume,
    // playbackRate via right-click on most browsers) plus a custom
    // speed selector for the 1.5x catch-up case Eric called out.
    function bindDvsPlayButtons(tbody) {
        var btns = tbody.querySelectorAll('.dvs-play-btn');
        for (var i = 0; i < btns.length; i++) {
            btns[i].addEventListener('click', function (e) {
                var btn = e.currentTarget;
                var msgId = btn.getAttribute('data-msg-id');
                if (!msgId) return;
                var token = getOrPromptBridgeToken();
                if (!token) return;
                var url = 'api/dmr-audio.php?msg_id=' + encodeURIComponent(msgId)
                        + '&token=' + encodeURIComponent(token);
                // Build the inline player + speed selector.
                var wrap = document.createElement('span');
                wrap.className = 'd-inline-flex align-items-center gap-1';
                var audio = document.createElement('audio');
                audio.src = url;
                audio.controls = true;
                audio.preload = 'metadata';
                audio.style.height = '28px';
                audio.style.verticalAlign = 'middle';
                var rateSel = document.createElement('select');
                rateSel.className = 'form-select form-select-sm';
                rateSel.style.width = '4.5rem';
                rateSel.title = 'Playback speed';
                var rates = [{v: '0.75', t: '0.75×'}, {v: '1', t: '1×'},
                             {v: '1.25', t: '1.25×'}, {v: '1.5', t: '1.5×'},
                             {v: '2', t: '2×'}];
                for (var r = 0; r < rates.length; r++) {
                    var opt = document.createElement('option');
                    opt.value = rates[r].v;
                    opt.textContent = rates[r].t;
                    if (rates[r].v === '1') opt.selected = true;
                    rateSel.appendChild(opt);
                }
                rateSel.addEventListener('change', function () {
                    audio.playbackRate = parseFloat(rateSel.value) || 1;
                });
                wrap.appendChild(audio);
                wrap.appendChild(rateSel);
                btn.parentNode.replaceChild(wrap, btn);
                // Start playing automatically; if the browser blocks
                // autoplay the user can still hit the play control.
                audio.play().catch(function () {});
            });
        }
    }

    function getOrPromptBridgeToken() {
        var KEY = 'dvs.bridge_token';
        var existing = '';
        try { existing = window.sessionStorage.getItem(KEY) || ''; } catch (e) {}
        if (existing) return existing;
        var typed = window.prompt(
            'Bridge bearer token (the value you saved at channel-mint time):'
        );
        if (!typed) return '';
        try { window.sessionStorage.setItem(KEY, typed); } catch (e) {}
        return typed;
    }

    function esc(s) {
        if (s === null || s === undefined) return '';
        return String(s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function init() {
        var panel = $('panel-dvswitch-dmr');
        if (!panel) return;
        var btnNew = $('dvsBtnNew');
        var btnReload = $('dvsBtnReload');
        if (btnNew) btnNew.addEventListener('click', openNew);
        if (btnReload) btnReload.addEventListener('click', loadChannels);
        var form = $('dvsForm');
        if (form) form.addEventListener('submit', submitForm);
        var hBtn = $('dvsTestHealthBtn');
        var tBtn = $('dvsTestTxBtn');
        var txtBtn = $('dvsTxTextBtn');
        var msgBtn = $('dvsLoadMessagesBtn');
        if (hBtn) hBtn.addEventListener('click', runHealthTest);
        if (tBtn) tBtn.addEventListener('click', runTxTest);
        if (txtBtn) txtBtn.addEventListener('click', runTxText);
        if (msgBtn) msgBtn.addEventListener('click', loadRecentMessages);
        var copyBtn = $('dvsCopyToken');
        if (copyBtn) {
            copyBtn.addEventListener('click', function () {
                var t = $('dvsTokenValue').textContent;
                if (navigator.clipboard) {
                    navigator.clipboard.writeText(t).then(function () {
                        copyBtn.innerHTML = '<i class="bi bi-check2 me-1"></i>Copied';
                    });
                }
            });
        }
        loadChannels();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
