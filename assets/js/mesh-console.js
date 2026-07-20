/**
 * NewUI v4.0 — Mesh Console (Phase 35C, 2026-06-12).
 *
 * Live admin interface for mesh bridges. Polls /api/mesh.php for
 * bridge state, packet feed, coverage matrix; lets admin mint tokens,
 * send mesh texts, push device config.
 *
 * ES5 IIFE per repo conventions.
 */
(function () {
    'use strict';

    var TOKEN = (document.getElementById('csrfToken') || {}).value || '';
    var lastFeedId = 0;
    var feedTimer = null;
    var bridgesCache = [];

    // Defensive event-listener attach. A missing element no longer
    // throws and halts the whole script (Phase 40 fix).
    function on(id, ev, fn) {
        var el = document.getElementById(id);
        if (el) el.addEventListener(ev, fn);
    }

    // ── Tab switching ──
    function bindTabs() {
        var tabs = document.querySelectorAll('#meshTabs button[data-tab]');
        var panes = document.querySelectorAll('[data-tab-content]');
        for (var i = 0; i < tabs.length; i++) {
            tabs[i].addEventListener('click', function () {
                var name = this.getAttribute('data-tab');
                for (var j = 0; j < tabs.length; j++) {
                    tabs[j].classList.toggle('active', tabs[j] === this);
                }
                for (var k = 0; k < panes.length; k++) {
                    var match = panes[k].getAttribute('data-tab-content') === name;
                    panes[k].classList.toggle('show', match);
                    panes[k].classList.toggle('active', match);
                }
                if (name === 'inbox') { refreshInbox(); refreshZelloInbox(); }
                if (name === 'feed') refreshFeed();
                if (name === 'coverage') refreshCoverage();
                if (name === 'nodes') refreshNodes();
                if (name === 'channels') refreshChannels();
                if (name === 'map') refreshMap();
                if (name === 'send') refreshSendNodeList();
                if (name === 'setup') initSetupTab();
            });
        }
    }

    function showAlert(msg, kind) {
        var area = document.getElementById('alertArea');
        if (!area) return;
        var div = document.createElement('div');
        div.className = 'alert alert-' + (kind || 'info') + ' alert-dismissible fade show small py-2';
        div.innerHTML = escHtml(msg) + '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
        area.appendChild(div);
        setTimeout(function () { try { div.remove(); } catch (e) {} }, 6000);
    }
    function escHtml(s) {
        var d = document.createElement('div');
        d.textContent = String(s == null ? '' : s);
        return d.innerHTML;
    }
    function fmtAgo(iso) {
        if (!iso) return '—';
        var d = new Date(iso.replace(' ', 'T'));
        if (isNaN(d.getTime())) return iso;
        var s = Math.round((Date.now() - d.getTime()) / 1000);
        if (s < 60) return s + 's ago';
        if (s < 3600) return Math.floor(s / 60) + 'm ' + (s % 60) + 's ago';
        if (s < 86400) return Math.floor(s / 3600) + 'h ' + Math.floor((s % 3600) / 60) + 'm ago';
        return Math.floor(s / 86400) + 'd ago';
    }
    function rssiClass(r) {
        if (r == null) return '';
        if (r >= -60) return 'mesh-rssi-strong';
        if (r >= -100) return 'mesh-rssi-fair';
        return 'mesh-rssi-weak';
    }

    // ── Bridges grid ──
    function loadBridges() {
        return fetch('api/mesh.php?action=bridges', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || data.error) {
                    document.getElementById('bridgesGrid').innerHTML =
                        '<div class="text-danger p-3">' + escHtml(data && data.error || 'error') + '</div>';
                    return;
                }
                bridgesCache = data.bridges || [];
                renderBridges();
                populateBridgeSelects();
                document.getElementById('meshLastRefresh').textContent =
                    new Date().toLocaleTimeString();
            })
            .catch(function (e) {
                showAlert('Failed to load bridges: ' + e.message, 'danger');
            });
    }

    function renderBridges() {
        var grid = document.getElementById('bridgesGrid');
        if (!bridgesCache.length) {
            grid.innerHTML =
                '<div class="text-body-secondary p-3 small">' +
                'No bridges registered yet. Click <strong>Mint Bridge Token</strong> above to create one and copy the token to <code>/etc/ticketscad/meshbridge.env</code> on your bridge host.' +
                '</div>';
            return;
        }
        var html = '';
        bridgesCache.forEach(function (b) {
            var lastSeen = b.last_seen_at;
            var fresh = lastSeen ? (Date.now() - new Date(lastSeen.replace(' ', 'T')).getTime()) / 1000 : 99999;
            var clazz = b.revoked_at ? 'offline' : (fresh < 60 ? 'online' : (fresh < 300 ? 'warn' : 'offline'));
            html +=
                '<div class="col-lg-6">' +
                '  <div class="mesh-bridge-card ' + clazz + '">' +
                '    <div class="d-flex align-items-center gap-2 mb-1">' +
                '      <strong>' + escHtml(b.label) + '</strong>' +
                '      <span class="badge bg-secondary small">id=' + b.id + '</span>' +
                '      <span class="ms-auto small text-body-secondary">' +
                '        ' + (b.revoked_at ? '<span class="text-danger">revoked</span>' :
                            '<i class="bi bi-circle-fill ' + (clazz === 'online' ? 'text-success' :
                                       clazz === 'warn' ? 'text-warning' : 'text-danger') + '"></i> ' +
                            (lastSeen ? 'seen ' + fmtAgo(lastSeen) : 'never seen')) +
                '      </span>' +
                '    </div>' +
                (b.host_hint ? '    <div class="small text-body-secondary mb-1">' + escHtml(b.host_hint) + '</div>' : '') +
                '    <div class="row g-2 small">' +
                '      <div class="col-6"><span class="text-body-secondary">last packet:</span> ' +
                          (b.last_packet_at ? escHtml(fmtAgo(b.last_packet_at)) : '—') + '</div>' +
                '      <div class="col-3"><span class="text-body-secondary">pkts:</span> ' + (b.packet_count || 0) + '</div>' +
                '      <div class="col-3"><span class="text-body-secondary">tokens:</span> ' + (b.active_tokens || 0) + '</div>' +
                '    </div>' +
                '  </div>' +
                '</div>';
        });
        grid.innerHTML = html;
    }

    function populateBridgeSelects() {
        var selects = ['feedBridgeFilter', 'sendBridge', 'cfgBridge', 'mintBridgeSelect'];
        selects.forEach(function (id) {
            var sel = document.getElementById(id);
            if (!sel) return;
            var current = sel.value;
            // Keep the first option (e.g. "All bridges" / "— New bridge —")
            var first = sel.querySelector('option');
            sel.innerHTML = '';
            if (first) sel.appendChild(first);
            bridgesCache.forEach(function (b) {
                var opt = document.createElement('option');
                opt.value = b.id;
                opt.textContent = b.label + (b.revoked_at ? ' (revoked)' : '');
                sel.appendChild(opt);
            });
            sel.value = current;
        });
    }

    // ── Live feed ──
    function refreshFeed() {
        var filter = document.getElementById('feedBridgeFilter').value || 0;
        var url = 'api/mesh.php?action=feed&limit=80';
        if (parseInt(filter, 10) > 0) url += '&bridge_id=' + filter;
        fetch(url, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || data.error) return;
                renderFeed(data.packets || []);
            });
    }
    function renderFeed(packets) {
        var body = document.getElementById('meshFeedBody');
        if (!packets.length) {
            body.innerHTML = '<div class="text-body-secondary p-3 small">No packets in the window.</div>';
            return;
        }
        var html = '';
        packets.forEach(function (p) {
            var rcv = (p.received_at || '').replace('T', ' ').substr(0, 23);
            var sig = '';
            if (p.snr != null)  sig += ' SNR ' + Number(p.snr).toFixed(1);
            if (p.rssi != null) sig += ' RSSI <span class="' + rssiClass(p.rssi) + '">' + p.rssi + '</span>';
            if (p.hops != null) sig += ' hops ' + p.hops;
            // Phase 39A: show friendly name + hex ID together so it's still uniquely identifiable.
            var name = p.long_name || p.short_name || p.display_name;
            var srcCell = name
                ? '<span class="fw-semibold">' + escHtml(name) + '</span> <span class="text-body-tertiary font-monospace small">' + escHtml(p.src_node || '?') + '</span>'
                : '<span class="font-monospace">' + escHtml(p.src_node || '?') + '</span>';
            html +=
                '<div class="mesh-feed-row d-flex gap-2 align-items-center" data-packet-id="' + p.id + '" style="cursor:pointer">' +
                '  <span class="text-body-secondary font-monospace" style="min-width:130px;">' + escHtml(rcv) + '</span>' +
                '  <span class="badge bg-' + (p.protocol === 'meshcore' ? 'info' : 'primary') + '" style="min-width:75px;">' + escHtml(p.protocol) + '</span>' +
                '  <span class="badge bg-secondary" title="bridge ' + p.bridge_id + '">' + escHtml(p.bridge_label || ('#' + p.bridge_id)) + '</span>' +
                '  <span style="min-width:180px;">' + srcCell + '</span>' +
                '  <span class="badge bg-light text-dark">' + escHtml(p.port_kind || '?') + '</span>' +
                '  <span class="flex-grow-1 ms-2">' + escHtml(p.payload_text || (p.lat ? (Number(p.lat).toFixed(4) + ', ' + Number(p.lng).toFixed(4)) : '')) + '</span>' +
                '  <span class="small text-body-secondary text-nowrap">' + sig + '</span>' +
                '</div>';
        });
        body.innerHTML = html;
        // Wire row clicks → open detail modal
        body.querySelectorAll('.mesh-feed-row').forEach(function (row) {
            row.addEventListener('click', function () { openPacketDetail(this.getAttribute('data-packet-id')); });
        });
        // Track newest id for the next delta poll
        if (packets[0] && packets[0].id) lastFeedId = packets[0].id;
    }

    // Phase 39A: packet detail modal
    function openPacketDetail(id) {
        document.getElementById('packetIdLabel').textContent = '#' + id;
        var body = document.getElementById('packetDetailBody');
        body.innerHTML = '<div class="text-body-secondary small">Loading…</div>';
        new bootstrap.Modal(document.getElementById('packetDetailModal')).show();
        fetch('api/mesh.php?action=packet_detail&id=' + encodeURIComponent(id),
              { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || data.error) { body.innerHTML = '<div class="text-danger">' + escHtml(data && data.error || 'load failed') + '</div>'; return; }
                var p = data.packet;
                var row = function (k, v) { return v == null || v === '' ? '' : '<tr><th class="pe-3 text-nowrap small">' + escHtml(k) + '</th><td>' + v + '</td></tr>'; };
                var html = '<table class="table table-sm mb-0"><tbody>' +
                    row('Received', '<span class="font-monospace">' + escHtml(p.received_at) + '</span>') +
                    row('Protocol', '<span class="badge bg-' + (p.protocol === 'meshcore' ? 'info' : 'primary') + '">' + escHtml(p.protocol) + '</span>') +
                    row('Bridge', escHtml(p.bridge_label || '#' + p.bridge_id)) +
                    row('Source node', '<span class="font-monospace">' + escHtml(p.src_node) + '</span>') +
                    row('Long name', escHtml(p.long_name)) +
                    row('Short name', escHtml(p.short_name)) +
                    row('Hardware', escHtml(p.hw_model)) +
                    row('Role', escHtml(p.role)) +
                    row('Destination', '<span class="font-monospace">' + escHtml(p.dst_node || '') + '</span>') +
                    row('Port', escHtml(p.port_kind)) +
                    row('Packet ID', '<span class="font-monospace">' + escHtml(p.packet_id) + '</span>') +
                    row('SNR', p.snr != null ? Number(p.snr).toFixed(1) : null) +
                    row('RSSI', p.rssi != null ? ('<span class="' + rssiClass(p.rssi) + '">' + p.rssi + '</span>') : null) +
                    row('Hops', p.hops) +
                    row('Position', (p.lat != null && p.lng != null) ? (Number(p.lat).toFixed(5) + ', ' + Number(p.lng).toFixed(5)) : null) +
                    row('Text payload', p.payload_text ? '<pre class="mb-0 small">' + escHtml(p.payload_text) + '</pre>' : null) +
                    row('JSON payload', p.payload_json ? '<pre class="mb-0 small">' + escHtml(JSON.stringify(p.payload_json, null, 2)) + '</pre>' : null) +
                    row('Last node position', (p.last_lat != null && p.last_lng != null) ? (Number(p.last_lat).toFixed(5) + ', ' + Number(p.last_lng).toFixed(5)) : null) +
                    row('Node last seen', escHtml(p.node_last_seen)) +
                    '</tbody></table>';
                body.innerHTML = html;
            });
    }

    function startFeedTimer() {
        if (feedTimer) clearInterval(feedTimer);
        feedTimer = setInterval(function () {
            if (!document.getElementById('feedAutoRefresh').checked) return;
            // Only refresh if the feed tab is visible
            var feedPane = document.querySelector('[data-tab-content="feed"]');
            if (feedPane && feedPane.classList.contains('active')) refreshFeed();
        }, 5000);
    }

    // ── Inbox (Phase C) ──
    // Reply-able inbound TEXT messages. Each row shows transport + origin +
    // friendly name, the last reply's delivery status, and a Reply button
    // that opens the reply modal (threads back to the origin).
    var inboxTimer = null;
    var _replyPacket = null;          // the inbound row being replied to
    var _replyPollTimer = null;       // status poll for the in-flight reply

    function refreshInbox() {
        fetch('api/mesh.php?action=inbox&limit=80', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || data.error) {
                    document.getElementById('meshInboxBody').innerHTML =
                        '<div class="text-danger p-3 small">' + escHtml(data && data.error || 'load failed') + '</div>';
                    return;
                }
                renderInbox(data.messages || []);
            })
            .catch(function (e) { /* transient */ });
    }

    function _statusBadge(st) {
        if (!st) return '';
        var s = st.status || '';
        var cls = s === 'sent' ? 'success' : (s === 'failed' ? 'danger' :
                  (s === 'claimed' ? 'info' : 'secondary'));
        var label = s || 'queued';
        if (s === 'sent' && st.ack_ms != null) label = 'delivered ' + st.ack_ms + 'ms';
        else if (s === 'sent') label = 'sent';
        var tip = st.error ? (' title="' + escHtml(st.error) + '"') : '';
        return '<span class="badge bg-' + cls + '"' + tip + '>' + escHtml(label) + '</span>';
    }

    function renderInbox(messages) {
        var body = document.getElementById('meshInboxBody');
        var badge = document.getElementById('inboxBadge');
        if (badge) {
            if (messages.length) { badge.textContent = messages.length; badge.classList.remove('d-none'); }
            else badge.classList.add('d-none');
        }
        if (!messages.length) {
            body.innerHTML = '<div class="text-body-secondary p-3 small">No inbound text messages yet.</div>';
            return;
        }
        var html = '';
        messages.forEach(function (m) {
            var rcv = (m.received_at || '').replace('T', ' ').substr(0, 19);
            var origin = m.is_direct
                ? '<span class="font-monospace">' + escHtml(m.src_node || '?') + '</span>'
                : 'channel slot ' + (m.channel_idx != null ? m.channel_idx : '?');
            var who = m.friendly
                ? '<span class="fw-semibold">' + escHtml(m.friendly) + '</span> '
                : '';
            var kindBadge = m.is_direct
                ? '<span class="badge bg-dark">DM</span>'
                : '<span class="badge bg-secondary">CH ' + (m.channel_idx != null ? m.channel_idx : '?') + '</span>';
            html +=
                '<div class="mesh-feed-row d-flex gap-2 align-items-center">' +
                '  <span class="text-body-secondary font-monospace small" style="min-width:140px;">' + escHtml(rcv) + '</span>' +
                '  <span class="badge bg-' + (m.protocol === 'meshcore' ? 'info' : 'primary') + '" style="min-width:75px;">' + escHtml(m.protocol) + '</span>' +
                '  ' + kindBadge +
                '  <span style="min-width:200px;">' + who + origin + '</span>' +
                '  <span class="flex-grow-1 ms-1">' + escHtml(m.payload_text || '') + '</span>' +
                '  <span class="text-nowrap">' + _statusBadge(m.last_reply) + '</span>' +
                '  <button class="btn btn-sm btn-outline-primary text-nowrap" data-reply-id="' + m.id + '">' +
                '    <i class="bi bi-reply"></i> Reply</button>' +
                '</div>';
        });
        body.innerHTML = html;
        // Stash the messages so the reply modal can read the row's context.
        body._messages = messages;
        body.querySelectorAll('button[data-reply-id]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var id = parseInt(this.getAttribute('data-reply-id'), 10);
                var msg = null;
                for (var i = 0; i < messages.length; i++) if (messages[i].id === id) msg = messages[i];
                if (msg) openReply(msg);
            });
        });
    }

    function openReply(msg) {
        _replyPacket = msg;
        if (_replyPollTimer) { clearInterval(_replyPollTimer); _replyPollTimer = null; }
        var ctx = document.getElementById('replyContext');
        var hint = document.getElementById('replyHint');
        var statusLine = document.getElementById('replyStatusLine');
        var who = msg.friendly ? (escHtml(msg.friendly) + ' ') : '';
        if (msg.is_direct) {
            ctx.innerHTML = '<div><strong>Direct reply</strong> to ' + who +
                '<span class="font-monospace">' + escHtml(msg.src_node || '?') + '</span> ' +
                'over <span class="badge bg-' + (msg.protocol === 'meshcore' ? 'info' : 'primary') + '">' + escHtml(msg.protocol) + '</span></div>' +
                '<div class="text-body-secondary mt-1">“' + escHtml(msg.payload_text || '') + '”</div>';
            if (hint) hint.textContent = 'Reply goes directly back to the sending node.';
        } else {
            ctx.innerHTML = '<div><strong>Channel reply</strong> on slot ' + (msg.channel_idx != null ? msg.channel_idx : '?') +
                ' over <span class="badge bg-' + (msg.protocol === 'meshcore' ? 'info' : 'primary') + '">' + escHtml(msg.protocol) + '</span></div>' +
                '<div class="text-body-secondary mt-1">' + who + '“' + escHtml(msg.payload_text || '') + '”</div>';
            if (hint) hint.textContent = 'Reply is broadcast on the same channel slot the message arrived on.';
        }
        document.getElementById('replyText').value = '';
        statusLine.classList.add('d-none');
        statusLine.innerHTML = '';
        new bootstrap.Modal(document.getElementById('replyModal')).show();
        setTimeout(function () { try { document.getElementById('replyText').focus(); } catch (e) {} }, 300);
    }

    function submitReply() {
        if (!_replyPacket) return;
        var text = document.getElementById('replyText').value.trim();
        if (!text) return showAlert('Enter a reply first', 'warning');
        var body = { csrf_token: TOKEN, packet_id: _replyPacket.id, text: text };
        var statusLine = document.getElementById('replyStatusLine');
        fetch('api/mesh.php?action=reply', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        }).then(function (r) { return r.json(); })
          .then(function (data) {
            if (data && data.error) return showAlert(data.error, 'danger');
            statusLine.classList.remove('d-none');
            statusLine.innerHTML = 'Queued (id=' + data.id + '). <span id="replyStatusBadge" class="badge bg-secondary">queued</span>';
            pollReplyStatus(data.id);
            refreshInbox();
          });
    }

    // Poll the reply's delivery status until it settles (sent/failed) or
    // we give up after ~30s. Surfaces the MeshCore ACK round-trip when present.
    function pollReplyStatus(outboxId) {
        if (_replyPollTimer) clearInterval(_replyPollTimer);
        var tries = 0;
        _replyPollTimer = setInterval(function () {
            tries++;
            fetch('api/mesh.php?action=reply_status&ids=' + outboxId, { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    var st = (data && data.statuses && data.statuses[0]) || null;
                    var badge = document.getElementById('replyStatusBadge');
                    if (st && badge) badge.outerHTML = _statusBadge(st).replace('class="badge', 'id="replyStatusBadge" class="badge');
                    if (st && (st.status === 'sent' || st.status === 'failed')) {
                        clearInterval(_replyPollTimer); _replyPollTimer = null;
                        refreshInbox();
                    }
                    if (tries > 30) { clearInterval(_replyPollTimer); _replyPollTimer = null; }
                });
        }, 1000);
    }

    function startInboxTimer() {
        if (inboxTimer) clearInterval(inboxTimer);
        inboxTimer = setInterval(function () {
            var sw = document.getElementById('inboxAutoRefresh');
            if (sw && !sw.checked) return;
            var pane = document.querySelector('[data-tab-content="inbox"]');
            if (pane && pane.classList.contains('active')) { refreshInbox(); refreshZelloInbox(); }
        }, 5000);
    }
    on('btnInboxRefresh', 'click', refreshInbox);
    on('btnReplySubmit', 'click', submitReply);

    // ── Zello inbox (Phase E) ──
    // The Zello-transport sibling of the mesh inbox. Reply-able inbound Zello
    // TEXT, each tagged transport=zello with origin (channel, or sender user
    // for a DM). Reply queues via api/zello-inbox.php → zello_outbox (drained
    // by the proxy). A DM-origin row defaults to "DM sender"; a channel row to
    // "Channel"; the operator can override the mode in the reply modal.
    var _zReplyMsg = null;            // inbound Zello row being replied to
    var _zReplyPollTimer = null;

    function refreshZelloInbox() {
        fetch('api/zello-inbox.php?action=inbox&limit=80', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || data.error) {
                    document.getElementById('zelloInboxBody').innerHTML =
                        '<div class="text-body-secondary p-3 small">' +
                        escHtml(data && data.error || 'Zello not available') + '</div>';
                    return;
                }
                renderZelloInbox(data.messages || []);
            })
            .catch(function (e) { /* transient */ });
    }

    function renderZelloInbox(messages) {
        var body = document.getElementById('zelloInboxBody');
        var badge = document.getElementById('zelloInboxBadge');
        if (!body) return;
        if (badge) {
            if (messages.length) { badge.textContent = messages.length; badge.classList.remove('d-none'); }
            else badge.classList.add('d-none');
        }
        if (!messages.length) {
            body.innerHTML = '<div class="text-body-secondary p-3 small">No inbound Zello text yet.</div>';
            return;
        }
        var html = '';
        messages.forEach(function (m) {
            var rcv = (m.created || '').replace('T', ' ').substr(0, 19);
            var who = m.friendly
                ? '<span class="fw-semibold">' + escHtml(m.friendly) + '</span> '
                : '';
            var kindBadge = m.is_dm
                ? '<span class="badge bg-dark">DM</span>'
                : '<span class="badge bg-secondary">CH</span>';
            var origin = m.is_dm
                ? '<span class="font-monospace">@' + escHtml(m.sender_username || '?') + '</span>'
                : 'channel ' + escHtml(m.channel || '?');
            html +=
                '<div class="mesh-feed-row d-flex gap-2 align-items-center">' +
                '  <span class="text-body-secondary font-monospace small" style="min-width:140px;">' + escHtml(rcv) + '</span>' +
                '  <span class="badge bg-warning text-dark" style="min-width:55px;">zello</span>' +
                '  ' + kindBadge +
                '  <span style="min-width:180px;">' + who + origin + '</span>' +
                '  <span class="flex-grow-1 ms-1">' + escHtml(m.content || '') + '</span>' +
                '  <button class="btn btn-sm btn-outline-warning text-nowrap" data-zreply-id="' + m.id + '">' +
                '    <i class="bi bi-reply"></i> Reply</button>' +
                '</div>';
        });
        body.innerHTML = html;
        body.querySelectorAll('button[data-zreply-id]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var id = parseInt(this.getAttribute('data-zreply-id'), 10);
                var msg = null;
                for (var i = 0; i < messages.length; i++) if (messages[i].id === id) msg = messages[i];
                if (msg) openZelloReply(msg);
            });
        });
    }

    function openZelloReply(msg) {
        _zReplyMsg = msg;
        if (_zReplyPollTimer) { clearInterval(_zReplyPollTimer); _zReplyPollTimer = null; }
        var ctx = document.getElementById('zelloReplyContext');
        var hint = document.getElementById('zelloReplyHint');
        var statusLine = document.getElementById('zelloReplyStatusLine');
        var who = msg.friendly ? (escHtml(msg.friendly) + ' ') : '';
        ctx.innerHTML = '<div><strong>From</strong> ' + who +
            '<span class="font-monospace">@' + escHtml(msg.sender_username || '?') + '</span>' +
            (msg.is_dm ? ' <span class="badge bg-dark">DM</span>' : ' on channel <strong>' + escHtml(msg.channel || '?') + '</strong>') +
            '</div><div class="text-body-secondary mt-1">“' + escHtml(msg.content || '') + '”</div>';
        // Default the reply mode to match the inbound (DM → DM sender).
        var userRadio = document.getElementById('zReplyUser');
        var chanRadio = document.getElementById('zReplyChannel');
        // DM the sender requires a sender username.
        var canDm = !!(msg.sender_username);
        if (userRadio) userRadio.disabled = !canDm;
        if (msg.is_dm && canDm) { if (userRadio) userRadio.checked = true; }
        else { if (chanRadio) chanRadio.checked = true; }
        _syncZelloReplyHint();
        if (hint && !msg.channel) {
            hint.textContent = 'Channel reply uses the proxy default dispatch channel.';
        }
        document.getElementById('zelloReplyText').value = '';
        statusLine.classList.add('d-none');
        statusLine.innerHTML = '';
        new bootstrap.Modal(document.getElementById('zelloReplyModal')).show();
        setTimeout(function () { try { document.getElementById('zelloReplyText').focus(); } catch (e) {} }, 300);
    }

    function _zelloReplyMode() {
        var checked = document.querySelector('input[name="zReplyMode"]:checked');
        return checked ? checked.value : 'channel';
    }

    function _syncZelloReplyHint() {
        var hint = document.getElementById('zelloReplyHint');
        if (!hint || !_zReplyMsg) return;
        if (_zelloReplyMode() === 'user') {
            hint.textContent = 'Direct message back to @' + (_zReplyMsg.sender_username || '?') + '.';
        } else {
            hint.textContent = 'Broadcast on channel ' + (_zReplyMsg.channel || '(default)') + '.';
        }
    }

    function submitZelloReply() {
        if (!_zReplyMsg) return;
        var text = document.getElementById('zelloReplyText').value.trim();
        if (!text) return showAlert('Enter a reply first', 'warning');
        var mode = _zelloReplyMode();
        var body = { csrf_token: TOKEN, message_id: _zReplyMsg.id, mode: mode, text: text };
        var statusLine = document.getElementById('zelloReplyStatusLine');
        fetch('api/zello-inbox.php?action=reply', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        }).then(function (r) { return r.json(); })
          .then(function (data) {
            if (data && data.error) return showAlert(data.error, 'danger');
            statusLine.classList.remove('d-none');
            statusLine.innerHTML = 'Queued (id=' + data.id + '). <span id="zReplyStatusBadge" class="badge bg-secondary">queued</span>';
            pollZelloReplyStatus(data.id);
            refreshZelloInbox();
          });
    }

    function pollZelloReplyStatus(outboxId) {
        if (_zReplyPollTimer) clearInterval(_zReplyPollTimer);
        var tries = 0;
        _zReplyPollTimer = setInterval(function () {
            tries++;
            fetch('api/zello-inbox.php?action=reply_status&ids=' + outboxId, { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    var st = (data && data.statuses && data.statuses[0]) || null;
                    var badge = document.getElementById('zReplyStatusBadge');
                    if (st && badge) badge.outerHTML = _statusBadge(st).replace('class="badge', 'id="zReplyStatusBadge" class="badge');
                    if (st && (st.status === 'sent' || st.status === 'failed')) {
                        clearInterval(_zReplyPollTimer); _zReplyPollTimer = null;
                    }
                    if (tries > 30) { clearInterval(_zReplyPollTimer); _zReplyPollTimer = null; }
                });
        }, 1000);
    }

    on('btnZelloReplySubmit', 'click', submitZelloReply);
    (function () {
        var modeRadios = document.querySelectorAll('input[name="zReplyMode"]');
        for (var i = 0; i < modeRadios.length; i++) {
            modeRadios[i].addEventListener('change', _syncZelloReplyHint);
        }
        var zInput = document.getElementById('zelloReplyText');
        if (zInput) zInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); submitZelloReply(); }
        });
    })();

    // ── Send box ──
    // Phase B: the Send tab can address a send three ways, chosen by the
    // "Send to" selector (#sendMode):
    //   channel → broadcast on the chosen channel slot
    //   node    → direct to a raw node ID / MeshCore pubkey-prefix
    //   unit    → direct to a unit/person, resolved server-side to the
    //             transport address via the comm-identifier resolver
    function _syncSendMode() {
        var mode = document.getElementById('sendMode').value;
        var proto = document.getElementById('sendProtocol').value;
        var isZello = (proto === 'zello');
        var nodeWrap = document.getElementById('sendToNodeWrap');
        var unitWrap = document.getElementById('sendToUnitWrap');
        var slotWrap = document.getElementById('sendChannelSlotWrap');
        var zChanWrap = document.getElementById('sendZelloChannelWrap');
        var zTtsWrap = document.getElementById('sendZelloTtsWrap');
        // Phase F: Zello has no raw-node addressing; the "node" mode is mesh-only.
        if (nodeWrap) nodeWrap.style.display = (mode === 'node' && !isZello) ? '' : 'none';
        if (unitWrap) unitWrap.style.display = (mode === 'unit') ? '' : 'none';
        // Channel slot is mesh-only; Zello broadcasts use a named channel string.
        if (slotWrap) slotWrap.style.display = (mode === 'channel' && !isZello) ? '' : 'none';
        if (zChanWrap) zChanWrap.style.display = (mode === 'channel' && isZello) ? '' : 'none';
        // Gap 1: "Speak on channel" (TTS audio) is a Zello channel-broadcast
        // affordance only (voice has no per-user addressing). Hide + uncheck it
        // when not on a Zello channel send so a stale tick can't leak into a
        // text DM/broadcast.
        if (zTtsWrap) {
            var showTts = (mode === 'channel' && isZello);
            zTtsWrap.style.display = showTts ? '' : 'none';
            if (!showTts) {
                var ttsCb = document.getElementById('sendZelloTts');
                if (ttsCb) ttsCb.checked = false;
            }
        }
        if (mode === 'unit') refreshSendUnitList();
    }

    function bindSend() {
        var modeSel = document.getElementById('sendMode');
        if (modeSel) {
            modeSel.addEventListener('change', _syncSendMode);
            _syncSendMode();
        }
        // Re-filter the unit picker when the protocol changes (a unit may
        // resolve on Meshtastic but not MeshCore, or vice-versa). Switching
        // to/from Zello also toggles the Zello-channel field and reloads the
        // picker from the Zello target source, so re-sync the whole mode.
        var protoSel = document.getElementById('sendProtocol');
        if (protoSel) protoSel.addEventListener('change', function () {
            _syncSendMode();
            if (document.getElementById('sendMode').value === 'unit') refreshSendUnitList();
        });

        document.getElementById('btnSendText').addEventListener('click', function () {
            var text = document.getElementById('sendText').value.trim();
            if (!text) return showAlert('Enter a message first', 'warning');
            var mode = document.getElementById('sendMode').value;
            var protocol = document.getElementById('sendProtocol').value || 'any';
            var slot = parseInt(document.getElementById('sendChannelSlot').value, 10) || 0;

            // Phase F: Zello has its own transport (proxy-drained zello_outbox),
            // not the mesh bridge outbox — route it to the Zello originate API.
            if (protocol === 'zello') { return sendZello(mode, text); }

            var body = {
                action: 'send',
                text: text,
                target_bridge_id: parseInt(document.getElementById('sendBridge').value, 10) || 0,
                protocol: protocol,
                channel_slot: slot,
                csrf_token: TOKEN
            };

            if (mode === 'node') {
                body.to_node = document.getElementById('sendToNode').value || '';
            } else if (mode === 'unit') {
                if (protocol !== 'meshtastic' && protocol !== 'meshcore') {
                    return showAlert('Set Protocol to Meshtastic or MeshCore to message a unit/person.', 'warning');
                }
                var sel = document.getElementById('sendToUnit');
                var val = sel ? sel.value : '';
                if (!val) return showAlert('Pick a unit or person first.', 'warning');
                // value is "unit:<id>" or "member:<id>"
                var parts = val.split(':');
                if (parts[0] === 'unit') body.unit_id = parseInt(parts[1], 10);
                else body.member_id = parseInt(parts[1], 10);
            }
            // mode === 'channel' → no direct target; broadcasts on slot.

            fetch('api/mesh.php?action=send', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body)
            }).then(function (r) { return r.json(); })
              .then(function (data) {
                if (data && data.error) return showAlert(data.error, 'danger');
                var kind = data.direct ? 'DM' : 'Broadcast';
                var extra = data.resolved_from ? (' → ' + (data.to_node || '')) : '';
                showAlert(kind + extra + ' queued (id=' + data.id + '). Bridge will pick up within 5s.', 'success');
                document.getElementById('sendText').value = '';
              });
        });
    }

    // Phase B: load + render the unit/person picker. We cache the raw list
    // and re-render (filtered to the current protocol) whenever protocol
    // or send-mode changes.
    //
    // Phase F: Zello targets come from a DIFFERENT source (the Zello proxy's
    // own queue path, api/zello-inbox.php?action=originate_targets) than the
    // mesh targets (api/mesh.php?action=send_targets), so we keep two caches
    // keyed by transport family and pick the right one for the protocol.
    var _sendTargets = null;        // mesh targets (meshtastic/meshcore flags)
    var _sendTargetsZello = null;   // zello targets (zello flag)
    function refreshSendUnitList() {
        var proto = document.getElementById('sendProtocol').value;
        if (proto === 'zello') {
            if (_sendTargetsZello) { renderSendUnitList(); return; }
            fetch('api/zello-inbox.php?action=originate_targets', { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data || data.error) { _sendTargetsZello = { members: [], units: [] }; }
                    else { _sendTargetsZello = { members: data.members || [], units: data.units || [] }; }
                    renderSendUnitList();
                });
            return;
        }
        if (_sendTargets) { renderSendUnitList(); return; }
        fetch('api/mesh.php?action=send_targets', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || data.error) { _sendTargets = { members: [], units: [] }; }
                else { _sendTargets = { members: data.members || [], units: data.units || [] }; }
                renderSendUnitList();
            });
    }
    function renderSendUnitList() {
        var sel = document.getElementById('sendToUnit');
        var hint = document.getElementById('sendToUnitHint');
        if (!sel) return;
        var proto = document.getElementById('sendProtocol').value;
        var isZello = (proto === 'zello');
        // "concrete" = a protocol that can resolve a unit/person to an address.
        var concrete = (proto === 'meshtastic' || proto === 'meshcore' || isZello);
        var t = (isZello ? _sendTargetsZello : _sendTargets) || { members: [], units: [] };
        // Build options: units first, then people, filtered to those that
        // resolve on the selected protocol (when concrete).
        var html = '<option value="">— select —</option>';
        var nUnits = 0, nMembers = 0;
        if (t.units.length) {
            var uOpts = '';
            t.units.forEach(function (u) {
                if (concrete && !u[proto]) return;
                uOpts += '<option value="unit:' + u.unit_id + '">' + escHtml(u.name) + '</option>';
                nUnits++;
            });
            if (uOpts) html += '<optgroup label="Units">' + uOpts + '</optgroup>';
        }
        if (t.members.length) {
            var mOpts = '';
            t.members.forEach(function (m) {
                if (concrete && !m[proto]) return;
                mOpts += '<option value="member:' + m.member_id + '">' + escHtml(m.name) + '</option>';
                nMembers++;
            });
            if (mOpts) html += '<optgroup label="People">' + mOpts + '</optgroup>';
        }
        sel.innerHTML = html;
        if (hint) {
            if (!concrete) hint.textContent = 'Set Protocol to Meshtastic, MeshCore, or Zello to message a unit/person.';
            else if (nUnits + nMembers === 0) hint.textContent = 'No units/people have a ' + proto + ' identifier on file.';
            else hint.textContent = nUnits + ' unit(s) + ' + nMembers + ' person(s) with a ' + proto + ' address.';
        }
    }

    // Phase F: send over Zello. DM a unit/person (resolved server-side to their
    // Zello username) or broadcast to a Zello channel. Ends at a queued
    // zello_outbox row; the Zello proxy drains + relays it (no bridge involved).
    function sendZello(mode, text) {
        var body = { action: 'originate', text: text, csrf_token: TOKEN };

        // Gap 1: "Speak on channel" (TTS audio). Only meaningful for a channel
        // broadcast — Zello voice has no per-user addressing — so the checkbox
        // is hidden + cleared outside channel mode by _syncSendMode.
        var ttsCb = document.getElementById('sendZelloTts');
        var isTts = !!(ttsCb && ttsCb.checked);

        if (mode === 'node') {
            return showAlert('Zello has no raw-node addressing — pick "Channel (broadcast)" or "Direct — unit / person".', 'warning');
        } else if (mode === 'unit') {
            if (isTts) {
                return showAlert('Speaking on the channel is a broadcast — Zello voice has no per-unit addressing. Switch "Send to" to Channel.', 'warning');
            }
            var sel = document.getElementById('sendToUnit');
            var val = sel ? sel.value : '';
            if (!val) return showAlert('Pick a unit or person first.', 'warning');
            var parts = val.split(':');   // "unit:<id>" or "member:<id>"
            if (parts[0] === 'unit') body.unit_id = parseInt(parts[1], 10);
            else body.member_id = parseInt(parts[1], 10);
        } else {
            // channel broadcast — optional named channel; blank = dispatch channel.
            var ch = document.getElementById('sendZelloChannel');
            body.channel = (ch && ch.value.trim()) || '';
            if (isTts) body.tts = true;
        }

        fetch('api/zello-inbox.php?action=originate', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        }).then(function (r) { return r.json(); })
          .then(function (data) {
            if (data && data.error) return showAlert(data.error, 'danger');
            var kind = data.spoken ? 'Zello spoken broadcast'
                     : (data.direct ? 'Zello DM' : 'Zello broadcast');
            var dest = data.recipient ? (' → @' + data.recipient)
                     : (data.channel ? (' → ' + data.channel) : ' → dispatch channel');
            var tail = data.spoken
                ? ' queued (id=' + data.id + '). The proxy will synthesise speech and key it onto the channel (needs Piper configured on the proxy host).'
                : ' queued (id=' + data.id + '). The Zello proxy will relay it.';
            showAlert(kind + dest + tail, 'success');
            document.getElementById('sendText').value = '';
          })
          .catch(function () { showAlert('Zello send failed (network).', 'danger'); });
    }

    // Phase 39C + 40: populate the To-node datalist from /api/mesh.php?action=nodes.
    // The input itself is free-text — any !abc12345 the admin types works too.
    function refreshSendNodeList() {
        fetch('api/mesh.php?action=nodes&hours=720', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var dl = document.getElementById('sendToNodeOptions');
                var hint = document.getElementById('sendToNodeHint');
                if (!dl) return;
                var nodes = data.nodes || [];
                dl.innerHTML = '';
                nodes.forEach(function (n) {
                    var label = (n.long_name || n.short_name || n.node_id);
                    var opt = document.createElement('option');
                    opt.value = n.node_id;
                    opt.label = label + ' — ' + n.node_id;
                    dl.appendChild(opt);
                });
                if (hint) hint.textContent = nodes.length + ' nodes known. Type a node ID or pick from the list. Leave blank to broadcast.';
            });
    }

    // ── Nodes (Phase 39A) ──
    // Click-to-sort columns. Each column declares a key into the node row and a
    // type ('num' or 'str') so the comparator knows how to order. SNR/RSSI/hops/
    // last-heard sort numerically; names + IDs sort as strings. Nulls always sort
    // last regardless of direction so missing signal data doesn't crowd the top.
    var nodesCache = [];
    var NODE_COLS = [
        { label: 'Long name', key: 'long_name',     type: 'str' },
        { label: 'Short',     key: 'short_name',    type: 'str' },
        { label: 'Node ID',   key: 'node_id',       type: 'str' },
        { label: 'Protocol',  key: 'protocol',      type: 'str' },
        { label: 'Heard by',  key: 'bridge_label',  type: 'str' },
        { label: 'HW',        key: 'hw_model',      type: 'str' },
        { label: 'Role',      key: 'role',          type: 'str' },
        { label: 'Position',  key: '__pos',         type: 'num' },
        { label: 'SNR',       key: 'last_snr',      type: 'num' },
        { label: 'RSSI',      key: 'last_rssi',     type: 'num' },
        { label: 'Hops',      key: 'last_hops',     type: 'num' },
        { label: 'Last seen', key: 'last_seen_at',  type: 'num' }
    ];
    // Default sort: most-recently-heard first (last_seen_at desc).
    var nodesSort = { key: 'last_seen_at', dir: 'desc' };

    function nodeSortVal(n, col) {
        if (col.key === '__pos') {
            // Sort the position column by latitude when present (just a stable
            // numeric proxy); nodes without a fix fall to the bottom.
            return (n.last_lat != null && n.last_lng != null) ? Number(n.last_lat) : null;
        }
        var v = n[col.key];
        if (v === undefined || v === null || v === '') return null;
        if (col.type === 'num') {
            if (col.key === 'last_seen_at') {
                // ISO/MySQL datetime string -> epoch ms for numeric ordering.
                var t = Date.parse(String(v).replace(' ', 'T'));
                return isNaN(t) ? null : t;
            }
            var num = Number(v);
            return isNaN(num) ? null : num;
        }
        return String(v).toLowerCase();
    }

    function sortNodes(rows) {
        var col = null, i;
        for (i = 0; i < NODE_COLS.length; i++) {
            if (NODE_COLS[i].key === nodesSort.key) { col = NODE_COLS[i]; break; }
        }
        if (!col) return rows;
        var sign = nodesSort.dir === 'asc' ? 1 : -1;
        var out = rows.slice();
        out.sort(function (a, b) {
            var va = nodeSortVal(a, col);
            var vb = nodeSortVal(b, col);
            // Nulls always last, regardless of direction.
            if (va === null && vb === null) return 0;
            if (va === null) return 1;
            if (vb === null) return -1;
            if (va < vb) return -1 * sign;
            if (va > vb) return 1 * sign;
            return 0;
        });
        return out;
    }

    function renderNodesTable() {
        var body = document.getElementById('nodesBody');
        if (!body) return;
        if (!nodesCache.length) { body.innerHTML = '<div class="text-body-secondary p-3 small">No nodes seen in window.</div>'; return; }
        var head = '';
        var c, indicator;
        for (var ci = 0; ci < NODE_COLS.length; ci++) {
            c = NODE_COLS[ci];
            indicator = (nodesSort.key === c.key) ? (' <span class="mesh-sort-ind">' + (nodesSort.dir === 'asc' ? '▲' : '▼') + '</span>') : '';
            head += '<th class="mesh-sortable" data-sortkey="' + c.key + '" role="button" style="cursor:pointer;white-space:nowrap;">' +
                escHtml(c.label) + indicator + '</th>';
        }
        var html = '<div class="table-responsive"><table class="table table-sm table-hover mesh-coverage-table mb-0"><thead><tr>' +
            head + '</tr></thead><tbody>';
        var rows = sortNodes(nodesCache);
        rows.forEach(function (n) {
            var pos = (n.last_lat != null && n.last_lng != null) ? (Number(n.last_lat).toFixed(4) + ', ' + Number(n.last_lng).toFixed(4)) : '—';
            var snrTxt  = (n.last_snr != null)  ? Number(n.last_snr).toFixed(1) : '—';
            var rssiTxt = (n.last_rssi != null) ? ('<span class="' + rssiClass(n.last_rssi) + '">' + n.last_rssi + '</span>') : '—';
            var hopsTxt = (n.last_hops != null) ? n.last_hops : '—';
            html += '<tr>' +
                '<td class="fw-semibold">' + escHtml(n.long_name || '—') + '</td>' +
                '<td>' + escHtml(n.short_name || '') + '</td>' +
                '<td class="font-monospace small">' + escHtml(n.node_id) + '</td>' +
                '<td><span class="badge bg-' + (n.protocol === 'meshcore' ? 'info' : 'primary') + '">' + escHtml(n.protocol || '?') + '</span></td>' +
                '<td>' + escHtml(n.bridge_label || ('#' + (n.bridge_id || ''))) + '</td>' +
                '<td class="small">' + escHtml(n.hw_model || '') + '</td>' +
                '<td class="small">' + escHtml(n.role || '') + '</td>' +
                '<td class="small font-monospace">' + pos + '</td>' +
                '<td class="small">' + snrTxt + '</td>' +
                '<td class="small">' + rssiTxt + '</td>' +
                '<td class="small text-center">' + hopsTxt + '</td>' +
                '<td class="small text-body-secondary">' + escHtml(fmtAgo(n.last_seen_at)) + '</td>' +
                '</tr>';
        });
        html += '</tbody></table></div>';
        body.innerHTML = html;
        // Wire the header clicks after each render (innerHTML replaced the nodes).
        var ths = body.querySelectorAll('th.mesh-sortable');
        for (var ti = 0; ti < ths.length; ti++) {
            ths[ti].addEventListener('click', onNodeSortClick);
        }
    }

    function onNodeSortClick() {
        var key = this.getAttribute('data-sortkey');
        if (!key) return;
        if (nodesSort.key === key) {
            nodesSort.dir = (nodesSort.dir === 'asc') ? 'desc' : 'asc';
        } else {
            nodesSort.key = key;
            // Numeric columns default to descending (strongest signal / newest
            // first); string columns default to ascending (A→Z).
            var isNum = false, k;
            for (k = 0; k < NODE_COLS.length; k++) {
                if (NODE_COLS[k].key === key) { isNum = NODE_COLS[k].type === 'num'; break; }
            }
            nodesSort.dir = isNum ? 'desc' : 'asc';
        }
        renderNodesTable();
    }

    function refreshNodes() {
        var hours = document.getElementById('nodesHours').value || 168;
        fetch('api/mesh.php?action=nodes&hours=' + hours, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var body = document.getElementById('nodesBody');
                var count = document.getElementById('nodesCount');
                if (!data || data.error) { body.innerHTML = '<div class="text-danger p-3">' + escHtml(data && data.error || 'load failed') + '</div>'; return; }
                nodesCache = data.nodes || [];
                count.textContent = nodesCache.length + ' node(s) heard in last ' + data.hours + 'h';
                renderNodesTable();
            });
    }
    on('btnNodesRefresh', 'click', refreshNodes);
    on('nodesHours', 'change', refreshNodes);

    // ── Channels (Phase 39B) ──
    var channelsCache = [];
    function refreshChannels() {
        fetch('api/mesh.php?action=channels', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || data.error) return;
                channelsCache = data.channels || [];
                renderChannels();
                populateAssignSelects();
            });
    }
    function renderChannels() {
        var body = document.getElementById('channelsBody');
        if (!channelsCache.length) {
            body.innerHTML = '<div class="text-body-secondary p-3 small">No channels yet. Click <strong>New Channel</strong> to create one.</div>';
            return;
        }
        var html = '<div class="table-responsive"><table class="table table-sm table-hover mb-0">' +
            '<thead><tr><th>Name</th><th>PSK</th><th>Region</th><th>Modem</th><th>Slots used on</th><th class="text-end">Actions</th></tr></thead><tbody>';
        channelsCache.forEach(function (c) {
            var pskMasked = c.psk_b64.length > 8 ? (c.psk_b64.substr(0, 6) + '…' + c.psk_b64.substr(-4)) : c.psk_b64;
            html += '<tr>' +
                '<td><span class="fw-semibold">' + escHtml(c.name) + '</span> ' +
                (c.is_primary ? '<span class="badge bg-success ms-1">primary</span>' : '') +
                '</td>' +
                '<td class="font-monospace small text-body-secondary" title="' + escHtml(c.psk_b64) + '">' + escHtml(pskMasked) + '</td>' +
                '<td class="small">' + escHtml(c.region) + '</td>' +
                '<td class="small">' + escHtml(c.modem_preset) + '</td>' +
                '<td class="small">' + (c.bridge_count || 0) + ' bridge(s)</td>' +
                '<td class="text-end">' +
                '  <button class="btn btn-sm btn-outline-primary" onclick="window.__ch_share(' + c.id + ')"><i class="bi bi-share me-1"></i>Share / QR</button> ' +
                '  <button class="btn btn-sm btn-outline-danger" onclick="window.__ch_archive(' + c.id + ')" title="Archive"><i class="bi bi-archive"></i></button>' +
                '</td>' +
                '</tr>';
        });
        html += '</tbody></table></div>';
        body.innerHTML = html;
    }

    function populateAssignSelects() {
        var br = document.getElementById('assignBridge');
        var ch = document.getElementById('assignChannel');
        if (br) {
            var prev = br.value;
            br.innerHTML = '<option value="">— choose —</option>';
            bridgesCache.forEach(function (b) {
                br.innerHTML += '<option value="' + b.id + '">' + escHtml(b.label) + '</option>';
            });
            br.value = prev;
        }
        if (ch) {
            var prev = ch.value;
            ch.innerHTML = '<option value="">— choose —</option>';
            channelsCache.forEach(function (c) {
                ch.innerHTML += '<option value="' + c.id + '">' + escHtml(c.name) + '</option>';
            });
            ch.value = prev;
        }
    }

    on('btnChannelsRefresh', 'click', refreshChannels);
    on('btnNewChannel', 'click', function () {
        document.getElementById('newChName').value = '';
        document.getElementById('newChPsk').value = '';
        document.getElementById('newChNotes').value = '';
        document.getElementById('newChUplink').checked = true;
        document.getElementById('newChDownlink').checked = true;
        new bootstrap.Modal(document.getElementById('newChannelModal')).show();
    });
    on('btnCreateChannelSubmit', 'click', function () {
        var name = document.getElementById('newChName').value.trim();
        if (!name) return showAlert('Name required', 'warning');
        var body = {
            csrf_token: TOKEN,
            name: name,
            psk_b64: document.getElementById('newChPsk').value.trim(),
            region: document.getElementById('newChRegion').value,
            modem_preset: document.getElementById('newChModem').value,
            uplink_enabled: document.getElementById('newChUplink').checked,
            downlink_enabled: document.getElementById('newChDownlink').checked,
            notes: document.getElementById('newChNotes').value
        };
        fetch('api/mesh.php?action=channel_create', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        }).then(function (r) { return r.json(); })
          .then(function (data) {
            if (data && data.error) return showAlert(data.error, 'danger');
            showAlert('Channel "' + data.name + '" created.', 'success');
            bootstrap.Modal.getInstance(document.getElementById('newChannelModal')).hide();
            refreshChannels();
          });
    });

    window.__ch_archive = function (id) {
        if (!confirm('Archive this channel? Bridges that have it assigned keep it until reconfigured.')) return;
        fetch('api/mesh.php?action=channel_archive', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ csrf_token: TOKEN, id: id })
        }).then(function (r) { return r.json(); })
          .then(function (data) {
            if (data && data.error) return showAlert(data.error, 'danger');
            showAlert('Channel archived.', 'info');
            refreshChannels();
          });
    };

    window.__ch_share = function (id) {
        fetch('api/mesh.php?action=channel_share_url&id=' + id, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || data.error) return showAlert(data && data.error || 'load failed', 'danger');
                document.getElementById('shareChName').textContent = data.channel.name;
                document.getElementById('shareUrl').value = data.url;
                document.getElementById('sharePsk').value = data.psk_b64;
                var qrBox = document.getElementById('shareQrBox');
                // Phase 43d: use qrcode-generator (self-hosted; the previous
                // qrcode@1.5.3 CDN tag 404'd silently). Different API:
                // build a typeNumber=0 (auto) ECC=L code, then render as SVG.
                if (typeof qrcode === 'function') {
                    try {
                        var q = qrcode(0, 'L');
                        q.addData(data.qr_text);
                        q.make();
                        // cellSize=5, margin=2 -> ~220px square
                        qrBox.innerHTML = q.createSvgTag({ cellSize: 5, margin: 2 });
                    } catch (e) {
                        qrBox.innerHTML = '<div class="text-danger small">QR render failed: ' + (e.message || e) + '</div>';
                    }
                } else {
                    qrBox.innerHTML = '<div class="text-body-secondary small">QR library not loaded.</div>';
                }
                new bootstrap.Modal(document.getElementById('shareChannelModal')).show();
            });
    };

    on('btnCopyShareUrl', 'click', function () {
        document.getElementById('shareUrl').select();
        document.execCommand('copy');
        showAlert('Copied share URL', 'info');
    });
    on('btnCopySharePsk', 'click', function () {
        document.getElementById('sharePsk').select();
        document.execCommand('copy');
        showAlert('Copied PSK', 'info');
    });
    on('btnAssignChannel', 'click', function () {
        var bid = parseInt(document.getElementById('assignBridge').value, 10) || 0;
        var cid = parseInt(document.getElementById('assignChannel').value, 10) || 0;
        var slot = parseInt(document.getElementById('assignSlot').value, 10) || 0;
        if (!bid || !cid) return showAlert('Pick a bridge and channel.', 'warning');
        fetch('api/mesh.php?action=channel_assign', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ csrf_token: TOKEN, bridge_id: bid, channel_id: cid, slot: slot })
        }).then(function (r) { return r.json(); })
          .then(function (data) {
            if (data && data.error) return showAlert(data.error, 'danger');
            showAlert('Channel assigned. Bridge applies within ~5s.', 'success');
          });
    });

    // ── Map (Phase 39E) ──
    var meshMap = null, meshMarkers = [], _meshMapInitInflight = false;
    function refreshMap() {
        if (!meshMap && typeof L !== 'undefined' && !_meshMapInitInflight) {
            // First-time init: load the shared map defaults so this map
            // respects Settings → Map Defaults instead of always showing
            // Minneapolis. Re-entry guard: refreshMap may be called again
            // (timer / event) before our async init resolves; the flag
            // prevents stacking duplicate L.map() instances on the same
            // DOM node.
            _meshMapInitInflight = true;
            var loader = (window.MapDefaults && window.MapDefaults.load)
                ? window.MapDefaults.load()
                : Promise.resolve({ lat: 44.9778, lng: -93.2650, zoom: 8 });
            loader.then(function (d) {
                if (meshMap) { _meshMapInitInflight = false; return; }
                meshMap = L.map('meshMap').setView([d.lat, d.lng], d.zoom);
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
                    { attribution: '© OpenStreetMap', maxZoom: 19 }).addTo(meshMap);
                _meshMapInitInflight = false;
                refreshMap(); // re-run now that the map exists
            });
            return;
        }
        if (!meshMap) return;
        var hours = document.getElementById('mapHours').value || 24;
        fetch('api/mesh.php?action=nodes&hours=' + hours, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                meshMarkers.forEach(function (m) { meshMap.removeLayer(m); });
                meshMarkers = [];
                var nodes = (data.nodes || []).filter(function (n) { return n.last_lat != null && n.last_lng != null; });
                document.getElementById('mapNodeCount').textContent = nodes.length + ' node(s) with position';
                if (!nodes.length) return;
                var bounds = [];
                nodes.forEach(function (n) {
                    var color = n.protocol === 'meshcore' ? '#0dcaf0' : '#0d6efd';
                    var m = L.circleMarker([n.last_lat, n.last_lng], {
                        radius: 8, color: color, fillColor: color, fillOpacity: 0.7, weight: 2
                    });
                    var sig = '';
                    if (n.last_snr != null)  sig += 'SNR ' + Number(n.last_snr).toFixed(1) + ' ';
                    if (n.last_rssi != null) sig += 'RSSI ' + n.last_rssi;
                    m.bindPopup(
                        '<strong>' + escHtml(n.long_name || n.short_name || n.node_id) + '</strong><br>' +
                        '<span class="font-monospace small">' + escHtml(n.node_id) + '</span><br>' +
                        '<small>Heard by: ' + escHtml(n.bridge_label || ('#' + n.bridge_id)) + '</small><br>' +
                        '<small>' + escHtml(sig) + '</small><br>' +
                        '<small class="text-body-secondary">' + escHtml(fmtAgo(n.last_seen_at)) + '</small>'
                    );
                    m.addTo(meshMap);
                    meshMarkers.push(m);
                    bounds.push([n.last_lat, n.last_lng]);
                });
                if (bounds.length) meshMap.fitBounds(bounds, { padding: [30, 30], maxZoom: 12 });
                // Force redraw — Leaflet sometimes needs a kick after tab-show
                setTimeout(function () { meshMap.invalidateSize(); }, 100);
            });
    }
    on('btnMapRefresh', 'click', refreshMap);
    on('mapHours', 'change', refreshMap);

    // ── Setup tab (Phase 39F + Phase 40 rework) ──
    var setupInited = false;
    function initSetupTab() {
        // Always refresh bridges before showing — cache might be stale
        // or empty if the user lands directly on the Setup tab.
        loadBridges().then(populateSetupBridges);
        if (setupInited) return;
        setupInited = true;
        on('btnGenerateSetup', 'click', generateSetupScript);
        on('btnCopySetup', 'click', function () {
            var range = document.createRange();
            range.selectNode(document.getElementById('setupScriptBody'));
            window.getSelection().removeAllRanges();
            window.getSelection().addRange(range);
            document.execCommand('copy');
            window.getSelection().removeAllRanges();
            showAlert('Copied script', 'info');
        });
        on('btnSetupMintNew', 'click', function () {
            // Re-use the existing Mint Bridge Token modal
            var btn = document.getElementById('btnMeshNewToken');
            if (btn) btn.click();
        });
        on('setupProtocol', 'change', function () {
            var second = document.getElementById('setupSecondPortRow');
            if (this.value === 'both') second.classList.remove('d-none');
            else second.classList.add('d-none');
        });
        on('setupTransport', 'change', function () {
            // Adjust placeholder + hint based on transport.
            var target = document.getElementById('setupTarget');
            var hint   = document.getElementById('setupTargetHint');
            if (this.value === 'tcp') {
                target.placeholder = '192.168.1.50  (port :4403 default)';
                hint.innerHTML = 'TCP — Meshtastic node on Wi-Fi. Find its IP in the phone app.';
            } else if (this.value === 'ble') {
                target.placeholder = 'Meshtastic_a1b2  (paired name)';
                hint.innerHTML = 'BLE — pair first with <code>bluetoothctl</code> on the bridge host.';
            } else {
                target.placeholder = '/dev/ttyUSB0';
                hint.innerHTML = 'USB — <code>dmesg | grep tty</code> after plugging the radio in.';
            }
        });
    }
    function populateSetupBridges() {
        var sel = document.getElementById('setupBridgeSelect');
        if (!sel) return;
        sel.innerHTML = '<option value="">— pick existing —</option>';
        bridgesCache.forEach(function (b) {
            var lastSeen = b.last_seen_at ? ' · ' + fmtAgo(b.last_seen_at) : ' · never seen';
            sel.innerHTML += '<option value="' + b.id + '">' + escHtml(b.label) + lastSeen + '</option>';
        });
        if (!bridgesCache.length) {
            sel.innerHTML = '<option value="">No bridges yet — click "Mint a new bridge" below</option>';
        }
    }
    function generateSetupScript() {
        var bid = parseInt(document.getElementById('setupBridgeSelect').value, 10) || 0;
        if (!bid) return showAlert('Pick a bridge (or click "Mint a new bridge" first).', 'warning');
        var protoChoice = document.getElementById('setupProtocol').value;
        var t1 = document.getElementById('setupTransport').value;
        var tg1 = document.getElementById('setupTarget').value.trim() || '/dev/ttyUSB0';
        var portSpec1 = (t1 === 'tcp' ? 'tcp:' : t1 === 'ble' ? 'ble:' : '') + tg1;
        var proto1 = protoChoice === 'meshcore' ? 'meshcore' : 'meshtastic';
        var dual = (protoChoice === 'both');
        var portSpec2 = '', proto2 = '';
        if (dual) {
            var t2 = document.getElementById('setupTransport2').value;
            var tg2 = document.getElementById('setupTarget2').value.trim() || '/dev/ttyUSB1';
            portSpec2 = (t2 === 'tcp' ? 'tcp:' : '') + tg2;
            proto2    = 'meshcore';
        }
        var cadHost = window.location.origin;
        var bridge = null;
        for (var i = 0; i < bridgesCache.length; i++) if (bridgesCache[i].id === bid) bridge = bridgesCache[i];
        var label = bridge ? bridge.label : ('bridge-' + bid);

        var envLines = [
            'CAD_URL=' + cadHost,
            'CAD_TOKEN=<PASTE_TOKEN_FROM_MINT_DIALOG_HERE>',
            'PORT0_SPEC=' + portSpec1,
            'PORT0_PROTOCOL=' + proto1
        ];
        if (dual) {
            envLines.push('PORT1_SPEC=' + portSpec2);
            envLines.push('PORT1_PROTOCOL=' + proto2);
        }
        var execStart = '/home/ejosterberg/mesh-venv/bin/python /home/ejosterberg/bridge_v2.py \\\n' +
            '  --port ${PORT0_SPEC} --protocol ${PORT0_PROTOCOL}';
        if (dual) execStart += ' \\\n  --port ${PORT1_SPEC} --protocol ${PORT1_PROTOCOL}';
        execStart += ' \\\n  --cad-url ${CAD_URL} --cad-token ${CAD_TOKEN}';

        var sh =
            '#!/usr/bin/env bash\n' +
            '# TicketsCAD mesh bridge installer for "' + label + '"\n' +
            '# Generated ' + new Date().toISOString().slice(0, 19) + '\n' +
            '# Firmware: ' + protoChoice + (dual ? ' (slot 0=Meshtastic, slot 1=MeshCore)' : '') + '\n' +
            '# Transport[0]: ' + portSpec1 + (dual ? '\n# Transport[1]: ' + portSpec2 : '') + '\n' +
            'set -euo pipefail\n\n' +
            'echo "==> Installing system deps"\n' +
            'sudo apt-get update -y\n' +
            'sudo apt-get install -y python3-venv python3-pip git curl\n\n' +
            'echo "==> Creating virtualenv"\n' +
            'python3 -m venv $HOME/mesh-venv\n' +
            'source $HOME/mesh-venv/bin/activate\n' +
            'pip install --upgrade pip\n' +
            'pip install meshtastic pypubsub pyserial requests' +
            (protoChoice === 'meshcore' || dual ? ' meshcore-cli' : '') + '\n\n' +
            'echo "==> Downloading bridge_v2.py"\n' +
            "curl -sSfo $HOME/bridge_v2.py '" + cadHost + "/services/meshtastic/bridge_v2.py'\n\n" +
            'echo "==> Writing /etc/ticketscad/meshbridge.env"\n' +
            'sudo mkdir -p /etc/ticketscad\n' +
            'sudo tee /etc/ticketscad/meshbridge.env > /dev/null <<EOF\n' +
            envLines.join('\n') + '\n' +
            'EOF\n' +
            'sudo chmod 0640 /etc/ticketscad/meshbridge.env\n\n' +
            'echo "==> Installing systemd unit"\n' +
            "sudo tee /etc/systemd/system/meshbridge.service > /dev/null <<'EOF'\n" +
            '[Unit]\n' +
            'Description=TicketsCAD mesh bridge\n' +
            'After=network-online.target\n' +
            'Wants=network-online.target\n\n' +
            '[Service]\n' +
            'Type=simple\n' +
            'User=ejosterberg\n' +
            'EnvironmentFile=/etc/ticketscad/meshbridge.env\n' +
            'ExecStart=' + execStart + '\n' +
            'Restart=on-failure\n' +
            'RestartSec=10\n\n' +
            '[Install]\n' +
            'WantedBy=multi-user.target\n' +
            'EOF\n\n' +
            'echo "==> Now: sudo nano /etc/ticketscad/meshbridge.env  → paste your token"\n' +
            'echo "       sudo systemctl daemon-reload"\n' +
            'echo "       sudo systemctl enable --now meshbridge"\n' +
            'echo "       sudo journalctl -u meshbridge -f"\n';
        document.getElementById('setupScriptBody').textContent = sh;
        document.getElementById('setupScriptOut').classList.remove('d-none');
    }

    // ── Coverage ──
    function refreshCoverage() {
        var hours = document.getElementById('coverageHours').value || 24;
        fetch('api/mesh.php?action=coverage&hours=' + hours, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || data.error) return;
                renderCoverage(data.coverage || []);
                renderLatency(data.latency_samples || []);
            });
    }
    function renderCoverage(rows) {
        // Pivot src_node → bridge_id → metrics
        var bySrc = {};
        var bridgeIds = {};
        rows.forEach(function (r) {
            var k = r.src_node || '?';
            if (!bySrc[k]) bySrc[k] = {};
            bySrc[k][r.bridge_id] = r;
            bridgeIds[r.bridge_id] = r.bridge_label;
        });
        var bridges = Object.keys(bridgeIds);
        if (!bridges.length) {
            document.getElementById('coverageMatrix').innerHTML =
                '<div class="text-body-secondary p-3 small">No packets in window.</div>';
            return;
        }
        var html = '<div class="table-responsive"><table class="table table-sm mesh-coverage-table mb-0"><thead><tr><th>Source node</th>';
        bridges.forEach(function (id) {
            html += '<th>' + escHtml(bridgeIds[id] || ('#' + id)) + '</th>';
        });
        html += '</tr></thead><tbody>';
        Object.keys(bySrc).sort().forEach(function (src) {
            html += '<tr><td class="font-monospace">' + escHtml(src) + '</td>';
            bridges.forEach(function (id) {
                var cell = bySrc[src][id];
                if (!cell) {
                    html += '<td class="text-body-tertiary">—</td>';
                } else {
                    var snr  = cell.avg_snr  != null ? Number(cell.avg_snr).toFixed(1)  : '—';
                    var rssi = cell.avg_rssi != null ? Math.round(cell.avg_rssi) : '—';
                    html += '<td><strong>' + cell.pkt_count + '</strong> ' +
                            '<span class="small text-body-secondary">' +
                            'SNR ' + snr + ' / RSSI <span class="' + rssiClass(rssi) + '">' + rssi + '</span>' +
                            '</span></td>';
                }
            });
            html += '</tr>';
        });
        html += '</tbody></table></div>';
        document.getElementById('coverageMatrix').innerHTML = html;
    }
    function renderLatency(rows) {
        if (!rows.length) {
            document.getElementById('latencyTable').innerHTML =
                '<div class="text-body-secondary p-3 small">No multi-bridge packets in window.</div>';
            return;
        }
        var html = '<div class="table-responsive"><table class="table table-sm mesh-coverage-table mb-0">' +
                   '<thead><tr><th>Source</th><th>pkt id</th><th>heard by</th><th>spread (ms)</th></tr></thead><tbody>';
        rows.slice(0, 30).forEach(function (r) {
            html += '<tr>' +
                    '<td class="font-monospace">' + escHtml(r.src_node || '?') + '</td>' +
                    '<td class="small">' + escHtml(r.packet_id || '?') + '</td>' +
                    '<td>' + r.heard_by + '</td>' +
                    '<td>' + (r.spread_ms != null ? Math.round(r.spread_ms) : '—') + '</td>' +
                    '</tr>';
        });
        html += '</tbody></table></div>';
        document.getElementById('latencyTable').innerHTML = html;
    }
    on('btnCoverageRefresh', 'click', refreshCoverage);

    // ── Mint token ──
    function bindMintToken() {
        document.getElementById('btnMeshNewToken').addEventListener('click', function () {
            populateBridgeSelects();
            document.getElementById('mintResult').classList.add('d-none');
            document.getElementById('mintNewFields').classList.remove('d-none');
            document.getElementById('mintLabel').value = '';
            document.getElementById('mintHost').value = '';
            document.getElementById('mintBridgeSelect').value = '0';
            new bootstrap.Modal(document.getElementById('mintTokenModal')).show();
        });
        document.getElementById('mintBridgeSelect').addEventListener('change', function () {
            document.getElementById('mintNewFields').classList.toggle('d-none', this.value !== '0');
        });
        document.getElementById('btnMintTokenSubmit').addEventListener('click', function () {
            var bid = parseInt(document.getElementById('mintBridgeSelect').value, 10) || 0;
            var body = { csrf_token: TOKEN, bridge_id: bid };
            if (!bid) {
                body.label     = document.getElementById('mintLabel').value.trim();
                body.host_hint = document.getElementById('mintHost').value.trim();
                if (!body.label) return showAlert('Label required for new bridge', 'warning');
            }
            fetch('api/mesh.php?action=mint_token', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body)
            }).then(function (r) { return r.json(); })
              .then(function (data) {
                if (data && data.error) return showAlert(data.error, 'danger');
                document.getElementById('mintTokenOut').textContent = data.token;
                document.getElementById('mintNewFields').classList.add('d-none');
                document.getElementById('mintResult').classList.remove('d-none');
                loadBridges();
              });
        });
    }

    // ── Device config ──
    function bindConfig() {
        document.getElementById('btnCfgSetOwner').addEventListener('click', function () {
            var bid = parseInt(document.getElementById('cfgBridge').value, 10) || 0;
            if (!bid) return showAlert('Choose a bridge first', 'warning');
            var longName  = document.getElementById('cfgLongName').value.trim();
            var shortName = document.getElementById('cfgShortName').value.trim();
            if (!longName && !shortName) return showAlert('Enter a long or short name', 'warning');
            fetch('api/mesh.php?action=set_config', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    csrf_token: TOKEN,
                    target_bridge_id: bid,
                    kind: 'set_owner',
                    payload: { long_name: longName, short_name: shortName }
                })
            }).then(function (r) { return r.json(); })
              .then(function (data) {
                if (data && data.error) return showAlert(data.error, 'danger');
                showAlert('Owner-set queued (id=' + data.id + '). Bridge applies within ~5s.', 'success');
              });
        });
        document.getElementById('btnCfgReboot').addEventListener('click', function () {
            var bid = parseInt(document.getElementById('cfgBridge').value, 10) || 0;
            if (!bid) return showAlert('Choose a bridge first', 'warning');
            if (!confirm('Reboot the radio on this bridge?')) return;
            fetch('api/mesh.php?action=set_config', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    csrf_token: TOKEN,
                    target_bridge_id: bid,
                    kind: 'reboot', payload: {}
                })
            }).then(function (r) { return r.json(); })
              .then(function (data) {
                if (data && data.error) return showAlert(data.error, 'danger');
                showAlert('Reboot queued', 'info');
              });
        });
    }

    // ── Boot ──
    function init() {
        bindTabs();
        bindSend();
        bindMintToken();
        bindConfig();
        document.getElementById('btnMeshRefresh').addEventListener('click', loadBridges);
        document.getElementById('feedBridgeFilter').addEventListener('change', refreshFeed);
        loadBridges().then(refreshFeed).then(refreshCoverage);
        refreshChannels();
        refreshSendNodeList();
        startFeedTimer();
        startInboxTimer();
        refreshInbox();      // prime the mesh inbox badge before the tab opens
        refreshZelloInbox(); // prime the Zello inbox badge too
        // Enter sends the reply from the reply modal input.
        var replyInput = document.getElementById('replyText');
        if (replyInput) replyInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); submitReply(); }
        });
        setInterval(loadBridges, 15000);
        setInterval(refreshSendNodeList, 60000);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
