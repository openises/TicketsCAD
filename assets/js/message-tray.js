/**
 * Dispatcher Message Tray controller (Phase 111 Slice B). ES5 IIFE, DOM-safe.
 *
 * Lists inbound field messages (broker store), logs/assigns/copies them to
 * incidents, starts sub-incidents, attributes senders, and replies/composes on
 * any mode. Keyboard-first: the compose box takes Enter to send.
 *
 * API: api/message-tray.php (list / incidents / members / log_active / assign /
 * copy / sub_incident / set_sender / reply / compose).
 */
(function () {
    'use strict';

    var API = 'api/message-tray.php';
    var channel = '';         // active channel filter
    var incidentsCache = [];
    var membersCache = [];
    var canAssign = (typeof window.MT_CAN_ASSIGN !== 'undefined') ? window.MT_CAN_ASSIGN : false;

    function csrf() { var e = document.getElementById('csrfToken'); return e ? e.value : ''; }
    function getJson(qs) {
        return fetch(API + '?' + qs, { credentials: 'same-origin' }).then(function (r) { return r.json(); });
    }
    function postJson(action, payload) {
        payload = payload || {}; payload.action = action; payload.csrf_token = csrf();
        return fetch(API, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload)
        }).then(function (r) { return r.json(); });
    }
    function toast(msg, kind) {
        var el = document.getElementById('mtToast'); if (!el) return;
        el.className = 'alert py-2 alert-' + (kind || 'info'); el.textContent = msg;
        el.classList.remove('d-none');
        window.setTimeout(function () { el.classList.add('d-none'); }, 3500);
    }
    function el(tag, cls, text) {
        var e = document.createElement(tag);
        if (cls) e.className = cls;
        if (text !== undefined && text !== null) e.textContent = text;
        return e;
    }
    function btn(label, cls, fn) {
        var b = el('button', 'btn btn-sm ' + cls + ' me-1 mb-1', label);
        b.type = 'button'; b.addEventListener('click', fn); return b;
    }

    // ── List ──────────────────────────────────────────────────────────────
    function load() {
        return getJson('action=list&channel=' + encodeURIComponent(channel)).then(function (res) {
            if (res.error) { toast(res.error, 'danger'); return; }
            renderActiveEvent(res.active_event);
            renderList(res.messages || []);
        });
    }
    function renderActiveEvent(ev) {
        var b = document.getElementById('mtActiveEvent'); if (!b) return;
        if (ev && ev.id) { b.textContent = 'active event: ' + ev.label; b.className = 'badge bg-success'; }
        else { b.textContent = 'active event: none set'; b.className = 'badge bg-secondary'; }
    }
    function renderList(messages) {
        var wrap = document.getElementById('mtList'); if (!wrap) return;
        wrap.textContent = '';
        if (!messages.length) {
            wrap.appendChild(el('div', 'text-body-secondary p-3', 'No inbound messages.'));
            return;
        }
        messages.forEach(function (m) { wrap.appendChild(card(m)); });
    }
    function card(m) {
        var logged = !!m.ticket_id;
        var unresolved = !m.sender_member_id;
        var c = el('div', 'card mt-card mb-2 ' + (logged ? 'mt-logged' : (unresolved ? 'mt-unresolved' : '')));
        var body = el('div', 'card-body py-2');

        var head = el('div', 'd-flex align-items-center gap-2 mb-1');
        head.appendChild(el('span', 'badge bg-secondary mt-chan', m.channel || '?'));
        var who = m.sender_name ? m.sender_name : (m.sender || 'unknown');
        head.appendChild(el('strong', '', who));
        if (unresolved && m.sender) head.appendChild(el('span', 'badge bg-warning text-dark', 'unattributed'));
        head.appendChild(el('span', 'text-body-secondary small ms-auto', m.created_at || ''));
        body.appendChild(head);

        body.appendChild(el('div', 'mb-1', m.body || ''));

        if (logged) {
            body.appendChild(el('div', 'small text-success mb-1', 'logged → ' + (m.ticket_label || ('#' + m.ticket_id))));
        }

        var actions = el('div', '');
        actions.appendChild(btn('Reply', 'btn-outline-primary', function () { startReply(m); }));
        if (canAssign) {
            actions.appendChild(btn('Log to active event', 'btn-outline-success', function () { logActive(m); }));
            actions.appendChild(btn('Assign…', 'btn-outline-secondary', function () { openAssign(m, 'assign'); }));
            actions.appendChild(btn('Copy…', 'btn-outline-secondary', function () { openAssign(m, 'copy'); }));
            actions.appendChild(btn('Sub-incident', 'btn-outline-secondary', function () { subIncident(m); }));
            if (unresolved) actions.appendChild(btn('Set sender', 'btn-outline-warning', function () { openSender(m); }));
        }
        body.appendChild(actions);
        c.appendChild(body);
        return c;
    }

    // ── Log / assign / copy / sub-incident ────────────────────────────────
    function logActive(m) {
        postJson('log_active', { message_id: m.id }).then(function (res) {
            if (res.error) { toast(res.error, 'danger'); return; }
            toast('Logged to active event.', 'success'); load();
        });
    }
    function openAssign(m, mode) {
        document.getElementById('mtAssignMsgId').value = m.id;
        document.getElementById('mtAssignMode').value = mode;
        document.getElementById('mtAssignTitle').textContent = (mode === 'copy' ? 'Copy to incident' : 'Assign to incident');
        document.getElementById('mtAssignHint').textContent = (mode === 'copy'
            ? 'Adds a second copy on the chosen incident; leaves any existing log in place.'
            : 'Logs this message onto the chosen incident.');
        fillIncidents(document.getElementById('mtAssignIncident'));
        bsShow('mtAssignModal');
    }
    function confirmAssign() {
        var id = document.getElementById('mtAssignMsgId').value;
        var mode = document.getElementById('mtAssignMode').value;
        var tid = document.getElementById('mtAssignIncident').value;
        postJson(mode === 'copy' ? 'copy' : 'assign', { message_id: id, ticket_id: tid }).then(function (res) {
            if (res.error) { toast(res.error, 'danger'); return; }
            bsHide('mtAssignModal'); toast(mode === 'copy' ? 'Copied.' : 'Assigned.', 'success'); load();
        });
    }
    function subIncident(m) {
        if (!window.confirm('Start a sub-incident under the active event from this message?')) return;
        postJson('sub_incident', { message_id: m.id }).then(function (res) {
            if (res.error) { toast(res.error, 'danger'); return; }
            toast('Sub-incident #' + (res.ticket_id || '?') + ' created.', 'success'); load();
        });
    }

    // ── Set sender ────────────────────────────────────────────────────────
    function openSender(m) {
        document.getElementById('mtSenderMsgId').value = m.id;
        document.getElementById('mtSenderRemember').checked = true;
        fillMembers(document.getElementById('mtSenderMember'));
        bsShow('mtSenderModal');
    }
    function confirmSender() {
        var id = document.getElementById('mtSenderMsgId').value;
        var mid = document.getElementById('mtSenderMember').value;
        var remember = document.getElementById('mtSenderRemember').checked ? 1 : 0;
        postJson('set_sender', { message_id: id, member_id: mid, remember: remember }).then(function (res) {
            if (res.error) { toast(res.error, 'danger'); return; }
            bsHide('mtSenderModal'); toast('Sender attributed.', 'success'); load();
        });
    }

    // ── Reply / compose ───────────────────────────────────────────────────
    function startReply(m) {
        document.getElementById('mtReplyTo').value = m.id;
        document.getElementById('mtComposeTitle').textContent = 'Reply to ' + (m.sender_name || m.sender || 'message');
        setSelect('mtComposeChannel', m.channel || 'local_chat');
        document.getElementById('mtCancelReply').classList.remove('d-none');
        document.getElementById('mtComposeBody').focus();
    }
    function cancelReply() {
        document.getElementById('mtReplyTo').value = '';
        document.getElementById('mtComposeTitle').textContent = 'New message';
        document.getElementById('mtCancelReply').classList.add('d-none');
    }
    function send() {
        var body = document.getElementById('mtComposeBody').value.trim();
        if (body === '') { toast('Type a message first.', 'warning'); return; }
        var chan = document.getElementById('mtComposeChannel').value;
        var replyTo = document.getElementById('mtReplyTo').value;
        var action = replyTo ? 'reply' : 'compose';
        var payload = { channel: chan, body: body };
        if (replyTo) payload.message_id = replyTo;
        postJson(action, payload).then(function (res) {
            if (res.error) { toast(res.error, 'danger'); return; }
            document.getElementById('mtComposeBody').value = '';
            cancelReply();
            toast(res.delivered === false ? 'Queued (channel offline).' : 'Sent.', res.delivered === false ? 'warning' : 'success');
            load();
        });
    }

    // ── Pickers ───────────────────────────────────────────────────────────
    function fillIncidents(sel) {
        var done = function () {
            sel.textContent = '';
            incidentsCache.forEach(function (i) {
                var o = el('option', '', i.label); o.value = i.id; sel.appendChild(o);
            });
        };
        if (incidentsCache.length) { done(); return; }
        getJson('action=incidents').then(function (res) { incidentsCache = res.incidents || []; done(); });
    }
    function fillMembers(sel) {
        var done = function () {
            sel.textContent = '';
            membersCache.forEach(function (mm) {
                var o = el('option', '', mm.name); o.value = mm.id; sel.appendChild(o);
            });
        };
        if (membersCache.length) { done(); return; }
        getJson('action=members').then(function (res) { membersCache = res.members || []; done(); });
    }

    // ── Small helpers ─────────────────────────────────────────────────────
    function setSelect(id, val) { var s = document.getElementById(id); if (s) s.value = val; }
    function bsShow(id) { var m = document.getElementById(id); if (m && window.bootstrap) bootstrap.Modal.getOrCreateInstance(m).show(); }
    function bsHide(id) { var m = document.getElementById(id); if (m && window.bootstrap) { var i = bootstrap.Modal.getInstance(m); if (i) i.hide(); } }
    function on(id, ev, fn) { var e = document.getElementById(id); if (e) e.addEventListener(ev, fn); }

    document.addEventListener('DOMContentLoaded', function () {
        on('mtRefresh', 'click', load);
        on('mtSend', 'click', send);
        on('mtCancelReply', 'click', cancelReply);
        on('mtAssignConfirm', 'click', confirmAssign);
        on('mtSenderConfirm', 'click', confirmSender);

        // Enter-to-send (Shift+Enter = newline).
        on('mtComposeBody', 'keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); }
        });

        // Channel filter buttons.
        var filter = document.getElementById('mtChannelFilter');
        if (filter) {
            var btns = filter.querySelectorAll('button');
            for (var i = 0; i < btns.length; i++) {
                btns[i].addEventListener('click', function (e) {
                    for (var j = 0; j < btns.length; j++) btns[j].classList.remove('active');
                    e.target.classList.add('active');
                    channel = e.target.getAttribute('data-chan') || '';
                    load();
                });
            }
        }

        load();
        window.setInterval(load, 15000); // light auto-refresh
    });
})();
