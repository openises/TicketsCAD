/**
 * Radio AI approval card UI (Phase 85f-5).
 *
 * Mounts on the standalone /radio-ai.php page. Polls
 * /api/radio-ai-pending.php every 10 seconds and renders each pending
 * row as a card with caller, transcript, draft response, and
 * Approve / Edit / Reject buttons.
 *
 * Approve → POST /api/radio-ai-decide.php {action: 'approve'}; uses
 *   the page's "Dry run" toggle so operators can rehearse without
 *   going on-air. Reject → POST {action: 'reject'}. Edit → swaps the
 *   draft for a textarea + Save button that POSTs {action: 'edit'};
 *   row stays pending_approval for the operator to approve next.
 *
 * Originally embedded inside the radio widget; pulled into its own
 * page 2026-06-18 to keep CAD focused on dispatch and let the Radio
 * AI feature evolve independently (net-control assistant, message
 * relay, Skywarn-net stand-up, etc.). The radio widget no longer
 * loads or references this script.
 *
 * ES5 IIFE, no jQuery, no build step.
 */
(function () {
    'use strict';

    var POLL_MS  = 10000;          // 10s — gentle on the DB
    var LIST_ID  = 'radioAiPanelList';
    var TITLE_ID = 'radioAiPanelTitle';
    var DRY_ID   = 'radioAiDryRun';
    var EMPTY_ID = 'radioAiEmpty';
    var AUTO_ID  = 'radioAiAutoApprove';
    var AUTO_STATUS_ID = 'radioAiAutoApproveStatus';
    var AUTO_EXPIRY_ID = 'radioAiAutoApproveExpiry';
    var AUTO_LS_KEY    = 'radio_ai_auto_approve_until';   // ms epoch
    var AUTO_MAX_MS    = 2 * 60 * 60 * 1000;              // 2 hours

    var pollTimer = null;
    var inflight  = false;
    var lastIds   = '';
    // Track which IDs have an auto-approve POST in flight so we don't
    // double-fire on overlapping polls.
    var autoInflight = Object.create(null);

    function csrfToken() {
        var m = document.querySelector('meta[name="csrf-token"]');
        return m ? m.getAttribute('content') : (window.CSRF_TOKEN || '');
    }

    function escapeHTML(s) {
        if (s == null) return '';
        return String(s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function renderRows(rows) {
        var list  = document.getElementById(LIST_ID);
        var title = document.getElementById(TITLE_ID);
        var empty = document.getElementById(EMPTY_ID);
        if (!list || !title) return;

        title.textContent = rows.length
            ? 'Pending approvals (' + rows.length + ')'
            : 'Pending approvals';

        if (!rows.length) {
            if (empty) empty.classList.remove('d-none');
            list.innerHTML = '';
            lastIds = '';
            return;
        }
        if (empty) empty.classList.add('d-none');

        // Skip repaint when the visible signature is unchanged — protects
        // any inline textarea the operator is currently typing into.
        var ids = rows.map(function (r) { return r.id + ':' + r.status + ':' + r.age_sec; }).join('|');
        if (ids === lastIds) return;
        lastIds = ids;

        var frag = document.createDocumentFragment();
        rows.forEach(function (row) { frag.appendChild(buildCard(row)); });
        list.innerHTML = '';
        list.appendChild(frag);
    }

    function buildCard(row) {
        var card = document.createElement('div');
        card.className = 'radio-ai-card status-' + escapeHTML(row.status);
        card.setAttribute('data-id', String(row.id));

        var head = document.createElement('div');
        head.className = 'radio-ai-card-head';
        // Non-DMR cards (Phase 112 Phase 6: Zello weather read-outs) show
        // their destination so the operator knows WHERE approve will key.
        var destBadge = '';
        if (row.target_kind === 'zello') {
            destBadge = '<span class="badge text-bg-info ms-1">Zello: ' +
                escapeHTML(row.target_ref || 'default channel') + '</span>';
        }
        head.innerHTML =
            '<span class="radio-ai-caller">' + escapeHTML(row.caller_callsign || row.caller_src_id || 'unknown') + '</span>' +
            destBadge +
            '<span class="radio-ai-status">' + escapeHTML(row.status) + '</span>' +
            '<span class="radio-ai-age text-secondary small">' + row.age_sec + 's ago</span>';
        card.appendChild(head);

        var txp = document.createElement('div');
        txp.className = 'radio-ai-transcript';
        txp.innerHTML = '<strong>Caller said:</strong> ' + escapeHTML(row.transcript || '');
        card.appendChild(txp);

        if (row.status === 'pending_generation') {
            var spin = document.createElement('div');
            spin.className = 'radio-ai-spin text-secondary small';
            spin.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Generating draft...';
            card.appendChild(spin);
            return card;
        }

        if (row.status === 'error') {
            var err = document.createElement('div');
            err.className = 'radio-ai-error text-danger small';
            err.textContent = 'Error: ' + (row.error_msg || 'unknown');
            card.appendChild(err);
            var dismiss = document.createElement('button');
            dismiss.type = 'button';
            dismiss.className = 'btn btn-sm btn-outline-secondary mt-1';
            dismiss.textContent = 'Dismiss';
            dismiss.addEventListener('click', function () { decide(row.id, 'reject'); });
            card.appendChild(dismiss);
            return card;
        }

        var draft = document.createElement('div');
        draft.className = 'radio-ai-draft';
        draft.innerHTML = '<strong>Draft response:</strong> <span class="radio-ai-draft-text">' +
            escapeHTML(row.draft_response || '') + '</span>';
        card.appendChild(draft);

        if (row.status === 'filtered') {
            var warn = document.createElement('div');
            warn.className = 'radio-ai-filter small text-warning';
            warn.innerHTML = '<i class="bi bi-shield-exclamation me-1"></i>' +
                'Content filter flagged this draft — review before approving.';
            card.appendChild(warn);
        }

        var actions = document.createElement('div');
        actions.className = 'radio-ai-actions btn-group btn-group-sm';
        actions.innerHTML =
            '<button type="button" class="btn btn-success" data-act="approve">' +
                '<i class="bi bi-mic-fill me-1"></i>Approve &amp; Send</button>' +
            '<button type="button" class="btn btn-outline-secondary" data-act="edit">' +
                '<i class="bi bi-pencil me-1"></i>Edit</button>' +
            '<button type="button" class="btn btn-outline-danger" data-act="reject">' +
                '<i class="bi bi-trash me-1"></i>Reject</button>';
        actions.querySelectorAll('button').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var act = btn.getAttribute('data-act');
                if (act === 'edit') { swapToEdit(card, row); return; }
                decide(row.id, act);
            });
        });
        card.appendChild(actions);

        return card;
    }

    function swapToEdit(card, row) {
        var draftSpan = card.querySelector('.radio-ai-draft-text');
        var actions = card.querySelector('.radio-ai-actions');
        if (!draftSpan || !actions) return;

        var ta = document.createElement('textarea');
        ta.className = 'form-control form-control-sm radio-ai-edit-ta mt-1';
        ta.rows = 3;
        ta.value = row.draft_response || '';

        var btns = document.createElement('div');
        btns.className = 'btn-group btn-group-sm mt-1';
        btns.innerHTML =
            '<button type="button" class="btn btn-primary" data-act="save">' +
                '<i class="bi bi-check2 me-1"></i>Save</button>' +
            '<button type="button" class="btn btn-outline-secondary" data-act="cancel">Cancel</button>';
        btns.querySelectorAll('button').forEach(function (b) {
            b.addEventListener('click', function () {
                if (b.getAttribute('data-act') === 'cancel') { refreshNow(); }
                else {
                    var val = ta.value.trim();
                    if (!val) return;
                    decide(row.id, 'edit', val);
                }
            });
        });

        draftSpan.parentNode.replaceChild(ta, draftSpan);
        actions.parentNode.replaceChild(btns, actions);
        ta.focus();
        ta.setSelectionRange(ta.value.length, ta.value.length);
    }

    function decide(id, action, editedText, done) {
        var dry = !!(document.getElementById(DRY_ID) && document.getElementById(DRY_ID).checked);
        var body = { id: id, action: action, csrf_token: csrfToken() };
        if (action === 'approve') body.dry_run = dry;
        if (action === 'edit')    body.edited_text = editedText || '';
        var isAuto = !!done;     // auto-approve passes a callback

        fetch('api/radio-ai-decide.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken(),
            },
            body: JSON.stringify(body),
        })
            .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
            .then(function (res) {
                if (!res.ok) {
                    console.warn('[radio-ai] decide failed:', res.body);
                    // Suppress alert when we're running unattended —
                    // an alert dialog from auto-approve would steal
                    // focus from whatever the operator is doing.
                    if (!isAuto) alert('Decision failed: ' + (res.body.error || 'unknown'));
                    return;
                }
                if (action === 'approve' && res.body.bridge) {
                    var b = res.body.bridge;
                    console.info('[radio-ai] ' +
                        (b.dry_run ? 'Dry run OK — ' : 'Sent on-air — ') +
                        b.packets_sent + ' packets, ' + b.duration_ms + ' ms');
                }
                refreshNow();
            })
            .catch(function (e) {
                console.warn('[radio-ai] decide network error:', e);
                if (!isAuto) alert('Network error: ' + e);
            })
            .then(function () { if (done) done(); });
    }

    function pollOnce() {
        if (inflight) return;
        if (!document.getElementById(LIST_ID)) return;     // wrong page
        inflight = true;
        fetch('api/radio-ai-pending.php', { credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : Promise.reject('HTTP ' + r.status); })
            .then(function (j) {
                var rows = (j && j.rows) || [];
                renderRows(rows);
                // Auto-approve fires AFTER render so the operator sees
                // the card flash by — useful as a sanity check that
                // the right kind of traffic is being auto-handled.
                if (isAutoApproveActive()) {
                    maybeAutoApprove(rows);
                }
                refreshAutoApproveBadge();
            })
            .catch(function (e) {
                console.warn('[radio-ai] pending poll error:', e);
                if (String(e).indexOf('403') !== -1) stopPolling();
            })
            .then(function () { inflight = false; });
    }

    // ── Auto-approve ─────────────────────────────────────────────────
    // Stored as a localStorage timestamp (ms epoch). UI toggles it on
    // by setting now + 2h; off by clearing it. Either way, when the
    // timestamp is in the past, the feature is effectively OFF, so a
    // stale entry left in storage between sessions is harmless.
    function isAutoApproveActive() {
        var until = parseInt(localStorage.getItem(AUTO_LS_KEY) || '0', 10);
        return until > Date.now();
    }
    function autoApproveExpiry() {
        return parseInt(localStorage.getItem(AUTO_LS_KEY) || '0', 10);
    }
    function setAutoApprove(enable) {
        if (enable) {
            localStorage.setItem(AUTO_LS_KEY, String(Date.now() + AUTO_MAX_MS));
        } else {
            localStorage.removeItem(AUTO_LS_KEY);
        }
        refreshAutoApproveBadge();
    }
    function refreshAutoApproveBadge() {
        var cb     = document.getElementById(AUTO_ID);
        var badge  = document.getElementById(AUTO_STATUS_ID);
        var expEl  = document.getElementById(AUTO_EXPIRY_ID);
        if (!cb || !badge || !expEl) return;
        var until  = autoApproveExpiry();
        var active = until > Date.now();
        cb.checked = active;
        if (active) {
            badge.classList.remove('d-none');
            var d = new Date(until);
            var hh = String(d.getHours()).padStart(2, '0');
            var mm = String(d.getMinutes()).padStart(2, '0');
            expEl.textContent = 'off at ' + hh + ':' + mm;
        } else {
            badge.classList.add('d-none');
            // Clean up any stale storage so the next session starts
            // fresh without a previously-set expiry sitting around.
            if (until > 0) localStorage.removeItem(AUTO_LS_KEY);
        }
    }
    function maybeAutoApprove(rows) {
        var dry = !!(document.getElementById(DRY_ID) && document.getElementById(DRY_ID).checked);
        rows.forEach(function (row) {
            // Only pending_approval — NEVER auto-approve a filtered
            // draft (content filter caught something) or an error row
            // or anything mid-generation. Those need human eyes.
            if (row.status !== 'pending_approval') return;
            if (autoInflight[row.id]) return;
            autoInflight[row.id] = true;
            console.info('[radio-ai] auto-approve firing on row #' + row.id +
                (dry ? ' (dry run)' : ' (on-air)'));
            decide(row.id, 'approve', null, function () {
                delete autoInflight[row.id];
            });
        });
    }

    function refreshNow() { pollOnce(); }

    function startPolling() {
        if (pollTimer) return;
        pollOnce();
        pollTimer = setInterval(pollOnce, POLL_MS);
    }
    function stopPolling() {
        if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
    }

    function init() {
        if (!document.getElementById(LIST_ID)) return;  // not on the radio-ai page

        // Auto-approve toggle wiring + restore from localStorage.
        var auto = document.getElementById(AUTO_ID);
        if (auto) {
            auto.addEventListener('change', function () {
                setAutoApprove(auto.checked);
                if (auto.checked) {
                    console.info('[radio-ai] auto-approve ON until ' +
                        new Date(autoApproveExpiry()).toLocaleTimeString());
                } else {
                    console.info('[radio-ai] auto-approve OFF (operator)');
                }
            });
        }
        refreshAutoApproveBadge();
        // Watchdog: every 15s independently of polling, check whether
        // auto-approve has expired and flip the UI off if so. Doesn't
        // depend on a poll arriving — important if RX is idle for a
        // while and the expiry passes mid-quiet.
        setInterval(function () {
            var until = autoApproveExpiry();
            if (until > 0 && until <= Date.now()) {
                console.info('[radio-ai] auto-approve 2-hour safety expired — switching OFF');
                setAutoApprove(false);
            }
        }, 15000);

        startPolling();
        document.addEventListener('visibilitychange', function () {
            if (document.hidden) stopPolling(); else startPolling();
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
