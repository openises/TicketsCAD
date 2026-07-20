(function () {
    'use strict';

    var map = null;
    var marker = null;
    var incidentData = null;

    // Responder search state
    var allResponders = [];
    var selectedResponderId = null;
    var searchTimeout = null;

    // On-scene timer state
    var sceneTimerInterval = null;
    var SCENE_WARN_MINUTES = 30;   // Yellow warning threshold
    var SCENE_DANGER_MINUTES = 60; // Red danger threshold

    // ── Initialization ──
    function init() {
        // Esc returns to dashboard — capture phase so nothing can block it
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                e.preventDefault();
                e.stopPropagation();
                window.location.href = 'index.php';
            }
        }, true);

        var id = getIncidentId();
        if (!id) {
            showAlert('No incident ID specified. <a href="index.php" class="alert-link">Return to dashboard</a>', 'danger');
            document.getElementById('loadingSpinner').classList.add('d-none');
            return;
        }
        loadIncident(id);
        initAssignControls();
        initNoteForm();
        initStatusControls();
        initPARCard(id);  // Phase 16a (2026-06-11)
        initTopMayday(id); // 2026-06-11 — always-visible Mayday button
        initSecurityBadge(id); // Phase 18c (2026-06-11) — security label badge + dialog
        initMajorLink(id); // 2026-06 — link this incident to a major incident
    }

    // ═══════════════════════════════════════════════════════════════
    //  MAJOR INCIDENT LINK (2026-06)
    //
    //  Proper dispatcher entry point for attaching THIS incident to a
    //  parent major incident. The card is only rendered server-side when
    //  the user has action.link_major, so a missing card === no-op here.
    //  Two paths: pick an existing open major, or "+ Create new" which
    //  POSTs create then link. Shows the current link with an Unlink
    //  option when already attached. All writes carry the CSRF token.
    // ═══════════════════════════════════════════════════════════════
    function initMajorLink(ticketId) {
        var card = document.getElementById('majorLinkCard');
        if (!card) return; // not permitted → server omitted the card

        var select = document.getElementById('majorLinkSelect');
        var linkBtn = document.getElementById('btnLinkMajor');
        var newWrap = document.getElementById('majorNewWrap');

        function refresh() {
            fetch('api/major-incidents.php', { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (list) {
                    if (!list || list.error) return;
                    // Find whether this ticket is linked to any major. The
                    // list endpoint doesn't carry per-ticket links, so we
                    // check each open major's detail lazily only if needed;
                    // cheaper: ask each major's detail is overkill, so we
                    // instead scan the majors and fetch detail to detect a
                    // link. To keep it light we look at the linked_count and
                    // fall back to detail lookups only for majors with links.
                    findCurrentLink(list, ticketId, function (linkedMajor) {
                        if (linkedMajor) {
                            renderCurrentLink(linkedMajor, ticketId);
                        } else {
                            renderLinkControls(list);
                        }
                    });
                })
                .catch(function () { /* silent — card just stays in default state */ });
        }

        function findCurrentLink(list, tid, cb) {
            // Check majors that actually have links; first match wins.
            var candidates = [];
            for (var i = 0; i < list.length; i++) {
                if (parseInt(list[i].linked_count, 10) > 0) candidates.push(list[i]);
            }
            if (!candidates.length) { cb(null); return; }
            var pending = candidates.length;
            var found = null;
            for (var j = 0; j < candidates.length; j++) {
                (function (major) {
                    fetch('api/major-incidents.php?id=' + encodeURIComponent(major.id),
                          { credentials: 'same-origin' })
                        .then(function (r) { return r.json(); })
                        .then(function (detail) {
                            if (detail && detail.linked_incidents) {
                                for (var k = 0; k < detail.linked_incidents.length; k++) {
                                    if (parseInt(detail.linked_incidents[k].ticket_id, 10) === tid) {
                                        found = detail;
                                        break;
                                    }
                                }
                            }
                        })
                        .catch(function () {})
                        .then(function () {
                            pending--;
                            if (pending === 0) cb(found);
                        });
                })(candidates[j]);
            }
        }

        function renderCurrentLink(major, tid) {
            document.getElementById('majorLinkControls').classList.add('d-none');
            var wrap = document.getElementById('majorCurrentLink');
            wrap.classList.remove('d-none');
            wrap.innerHTML =
                '<div class="d-flex align-items-center justify-content-between">' +
                '<span><i class="bi bi-link-45deg me-1"></i>Linked to ' +
                '<a href="major-incidents.php?id=' + encodeURIComponent(major.id) + '" class="fw-semibold">' +
                escHtml(major.name) + '</a></span>' +
                '<button type="button" class="btn btn-sm btn-outline-danger py-0" id="btnUnlinkMajor" ' +
                'title="Unlink this incident from the major"><i class="bi bi-x-lg"></i></button>' +
                '</div>';
            var badge = document.getElementById('majorLinkBadge');
            if (badge) { badge.textContent = 'Linked'; badge.className = 'badge bg-danger ms-auto'; }
            var unlinkBtn = document.getElementById('btnUnlinkMajor');
            if (unlinkBtn) {
                unlinkBtn.addEventListener('click', function () {
                    if (!confirm('Unlink this incident from "' + (major.name || 'the major incident') + '"?')) return;
                    majorPost({ action: 'unlink', major_id: major.id, ticket_id: tid }, function (data) {
                        showAlert(escHtml(data.message || 'Unlinked from major incident.'), 'info');
                        refresh();
                    });
                });
            }
        }

        function renderLinkControls(list) {
            document.getElementById('majorCurrentLink').classList.add('d-none');
            document.getElementById('majorLinkControls').classList.remove('d-none');
            var badge = document.getElementById('majorLinkBadge');
            if (badge) badge.classList.add('d-none');
            // Rebuild the open-major options (keep placeholder + create-new).
            var keepNew = '<option value="__new__">+ Create new major incident…</option>';
            var html = '<option value="">— Select a major incident —</option>';
            var openCount = 0;
            for (var i = 0; i < list.length; i++) {
                if (String(list[i].status) !== 'open') continue;
                openCount++;
                html += '<option value="' + escHtml(list[i].id) + '">' +
                        escHtml(list[i].name) + '</option>';
            }
            select.innerHTML = html + keepNew;
        }

        function majorPost(payload, onSuccess) {
            payload.csrf_token = getCsrfToken();
            fetch('api/major-incidents.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            }).then(function (r) { return r.json(); }).then(function (data) {
                if (data && data.error) { showAlert(escHtml(data.error), 'danger'); return; }
                onSuccess(data);
            }).catch(function (err) {
                showAlert('Major incident request failed: ' + escHtml(err.message), 'danger');
            });
        }

        select.addEventListener('change', function () {
            if (select.value === '__new__') {
                newWrap.classList.remove('d-none');
                linkBtn.disabled = false;
            } else {
                newWrap.classList.add('d-none');
                linkBtn.disabled = (select.value === '');
            }
        });

        linkBtn.addEventListener('click', function () {
            if (select.value === '__new__') {
                var name = document.getElementById('majorNewName').value.trim();
                if (name === '') { showAlert('Enter a name for the new major incident.', 'warning'); return; }
                var sev = parseInt(document.getElementById('majorNewSeverity').value, 10) || 0;
                linkBtn.disabled = true;
                // Create, then link this incident to the new major.
                majorPost({ action: 'create', name: name, severity: sev }, function (data) {
                    majorPost({ action: 'link', major_id: data.major_id, ticket_id: ticketId }, function (linkData) {
                        showAlert(escHtml(linkData.message || 'Created and linked to new major incident.'), 'success');
                        refresh();
                    });
                });
            } else {
                var majorId = parseInt(select.value, 10);
                if (!majorId) return;
                linkBtn.disabled = true;
                majorPost({ action: 'link', major_id: majorId, ticket_id: ticketId }, function (data) {
                    showAlert(escHtml(data.message || 'Incident linked to major incident.'), 'success');
                    refresh();
                });
            }
        });

        refresh();
    }

    // 2026-06-11 — Top-bar Mayday button. Always visible (the PAR
    // card may be hidden because PAR is disabled). The engine
    // special-cases kind='mayday' so the cycle fires even when
    // par_enabled=0.
    function initTopMayday(ticketId) {
        var btn = document.getElementById('btnMaydayTop');
        if (!btn) return;
        // Show the button to anyone who can see the incident — Mayday
        // is a safety action, not an admin action.
        btn.classList.remove('d-none');
        btn.addEventListener('click', function () {
            if (!confirm('DECLARE MAYDAY?\n\nThis initiates an urgent Personnel Accountability Report (PAR) for all assigned units, posts to dispatch chat, and is audit-logged. Use only in genuine emergencies.\n\nContinue?')) return;
            fetch('api/par.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action:    'initiate',
                    ticket_id: ticketId,
                    kind:      'mayday',
                    csrf_token: getCsrfToken()
                })
            }).then(function (r) { return r.json(); }).then(function (data) {
                if (data && data.error) { showAlert(data.error, 'danger'); return; }
                showAlert('MAYDAY declared. PAR initiated. Audit-logged.', 'danger');
                // Flash the button so it's obvious the call landed.
                btn.classList.add('btn-warning');
                btn.classList.remove('btn-danger');
                setTimeout(function () {
                    btn.classList.add('btn-danger');
                    btn.classList.remove('btn-warning');
                }, 1500);
            });
        });
    }

    // ═══════════════════════════════════════════════════════════════
    //  SECURITY LABEL BADGE (Phase 18c, 2026-06-11)
    //
    //  Calls api/security-labels.php?action=resolve&ticket=N to find
    //  the label currently applied to this incident. Renders a colored
    //  badge in the title row. Clicking the badge opens an inline
    //  modal that lets the user pick a new label + reason.
    // ═══════════════════════════════════════════════════════════════
    function initSecurityBadge(ticketId) {
        var badge = document.getElementById('secLabelBadge');
        if (!badge) return;

        function refresh() {
            fetch('api/security-labels.php?action=resolve&ticket=' + ticketId, { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.error || !data.resolved) return;
                    var l = data.resolved;
                    badge.textContent = l.name + (l._resolved_from === 'incident_override' ? ' (override)' : '');
                    badge.style.background = l.badge_bg_color || '#6c757d';
                    badge.style.color      = l.badge_text_color || '#ffffff';
                    badge.classList.remove('d-none');
                });
        }

        badge.addEventListener('click', function () {
            // Build the picker modal on demand.
            fetch('api/security-labels.php', { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    var labels = (data.labels || []);
                    var opts = labels.map(function (l) {
                        return '<option value="' + l.id + '"' +
                               (l.audit_required_reason == 1 ? ' data-needs-reason="1"' : '') +
                               '>' + escHtml(l.name) + '</option>';
                    }).join('');
                    var html =
                        '<div class="modal fade" id="secOverrideModal" tabindex="-1">' +
                        '  <div class="modal-dialog"><div class="modal-content">' +
                        '    <div class="modal-header py-2"><h6 class="modal-title">Set incident security label</h6>' +
                        '      <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>' +
                        '    <div class="modal-body">' +
                        '      <label class="form-label form-label-sm">Label</label>' +
                        '      <select class="form-select form-select-sm" id="secOverridePick">' + opts + '</select>' +
                        '      <label class="form-label form-label-sm mt-2">Reason</label>' +
                        '      <textarea class="form-control form-control-sm" id="secOverrideReason" rows="2" placeholder="Why is this label being applied?"></textarea>' +
                        '      <div class="form-text small">Required for restricted/confidential labels.</div>' +
                        '    </div>' +
                        '    <div class="modal-footer py-2">' +
                        '      <button type="button" class="btn btn-sm btn-outline-secondary" id="secOverrideClear">Revert to default</button>' +
                        '      <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>' +
                        '      <button type="button" class="btn btn-sm btn-danger" id="secOverrideApply">Apply</button>' +
                        '    </div>' +
                        '  </div></div></div>';
                    var existing = document.getElementById('secOverrideModal');
                    if (existing) existing.remove();
                    var wrap = document.createElement('div');
                    wrap.innerHTML = html;
                    document.body.appendChild(wrap.firstChild);
                    var modalEl = document.getElementById('secOverrideModal');
                    var modal = new bootstrap.Modal(modalEl);
                    modal.show();

                    document.getElementById('secOverrideApply').addEventListener('click', function () {
                        var sel = document.getElementById('secOverridePick');
                        var labelId = parseInt(sel.value, 10);
                        var reason = document.getElementById('secOverrideReason').value;
                        fetch('api/security-labels.php', {
                            method: 'POST', credentials: 'same-origin',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({
                                action: 'apply_override',
                                ticket_id: ticketId,
                                label_id: labelId,
                                reason: reason,
                                csrf_token: getCsrfToken()
                            })
                        }).then(function (r) { return r.json(); }).then(function (data) {
                            if (data.error) { showAlert(data.error, 'danger'); return; }
                            modal.hide();
                            showAlert('Security label applied.', 'success');
                            refresh();
                        });
                    });

                    document.getElementById('secOverrideClear').addEventListener('click', function () {
                        fetch('api/security-labels.php', {
                            method: 'POST', credentials: 'same-origin',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({
                                action: 'clear_override',
                                ticket_id: ticketId,
                                csrf_token: getCsrfToken()
                            })
                        }).then(function (r) { return r.json(); }).then(function (data) {
                            if (data.error) { showAlert(data.error, 'danger'); return; }
                            modal.hide();
                            showAlert('Override cleared.', 'success');
                            refresh();
                        });
                    });
                });
        });

        refresh();
    }

    function escHtml(s) {
        if (s === null || s === undefined) return '';
        var d = document.createElement('div');
        d.textContent = String(s);
        return d.innerHTML;
    }

    // ═══════════════════════════════════════════════════════════════
    //  PAR CARD (Phase 16a, 2026-06-11)
    //
    //  Polls /api/par.php?action=for_ticket&ticket=N every 10 seconds
    //  to surface the latest cycle + the next-due timestamp. Hidden
    //  entirely when par_enabled=false.
    // ═══════════════════════════════════════════════════════════════
    function initPARCard(ticketId) {
        var card    = document.getElementById('parCard');
        if (!card) return;
        var initBtn       = document.getElementById('btnInitiatePAR');
        var maydayBtn     = document.getElementById('btnMayday');
        var overrideInput = document.getElementById('parOverrideMin');
        var overrideBtn   = document.getElementById('btnSaveParOverride');
        var historyDiv    = document.getElementById('parHistory');

        if (initBtn) {
            // Phase 29A (2026-06-12) — inline-Cancel pattern. No browser
            // confirm() popup. Click immediately fires the cycle, then
            // the button transforms into a 5-second countdown "Cancel"
            // affordance. Click within 5s and the cycle aborts with a
            // dispatcher-error audit reason. Past 5s, button reverts.
            var initOriginalHtml = initBtn.innerHTML;
            var initOriginalClass = initBtn.className;
            var lastInitiatedCycleId = null;
            var cancelCountdownTimer = null;

            initBtn.addEventListener('click', function () {
                if (initBtn.dataset.cancelMode === '1') {
                    // Cancel path
                    if (!lastInitiatedCycleId) return;
                    var cid = lastInitiatedCycleId;
                    clearInterval(cancelCountdownTimer);
                    fetch('api/par.php', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            action:     'abort',
                            cycle_id:   cid,
                            reason:     'cancelled by dispatcher within 5s — initiate clicked in error',
                            csrf_token: getCsrfToken()
                        })
                    }).then(function (r) { return r.json(); }).then(function (data) {
                        if (data && data.error) { showAlert(data.error, 'danger'); return; }
                        showAlert('PAR cycle cancelled.', 'info');
                        resetInitButton();
                        refreshPAR(ticketId);
                    });
                    return;
                }
                // Initiate path — no confirm, fire immediately
                fetch('api/par.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action:    'initiate',
                        ticket_id: ticketId,
                        kind:      'manual',
                        csrf_token: getCsrfToken()
                    })
                }).then(function (r) { return r.json(); }).then(function (data) {
                    if (data && data.error) { showAlert(data.error, 'danger'); return; }
                    lastInitiatedCycleId = data && data.cycle && data.cycle.id;
                    showAlert('PAR initiated.', 'success');
                    refreshPAR(ticketId);
                    if (lastInitiatedCycleId) {
                        startCancelCountdown(5);
                    }
                });
            });

            function startCancelCountdown(secs) {
                initBtn.dataset.cancelMode = '1';
                initBtn.className = 'btn btn-sm btn-outline-warning';
                var remaining = secs;
                function paint() {
                    initBtn.innerHTML = '<i class="bi bi-arrow-counterclockwise me-1"></i>Cancel (' + remaining + 's)';
                }
                paint();
                cancelCountdownTimer = setInterval(function () {
                    remaining--;
                    if (remaining <= 0) {
                        clearInterval(cancelCountdownTimer);
                        resetInitButton();
                        return;
                    }
                    paint();
                }, 1000);
            }
            function resetInitButton() {
                initBtn.dataset.cancelMode = '';
                initBtn.innerHTML = initOriginalHtml;
                initBtn.className = initOriginalClass;
                lastInitiatedCycleId = null;
            }
        }
        if (maydayBtn) {
            maydayBtn.addEventListener('click', function () {
                if (!confirm('Declare MAYDAY and initiate urgent PAR for all units? This action is audit-logged.')) return;
                firePAR('mayday');
            });
        }

        // Phase 27 hotfix (2026-06-12) — Cancel-current-cycle button.
        var cancelBtn = document.getElementById('btnCancelParCycle');
        if (cancelBtn) {
            cancelBtn.addEventListener('click', function () {
                var cycleId = parseInt(cancelBtn.dataset.cycleId || '0', 10);
                if (!cycleId) return;
                var reason = prompt('Cancel this PAR cycle? Optional reason for the audit log:');
                if (reason === null) return;
                fetch('api/par.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action:     'abort',
                        cycle_id:   cycleId,
                        reason:     reason,
                        csrf_token: getCsrfToken()
                    })
                }).then(function (r) { return r.json(); }).then(function (data) {
                    if (data && data.error) { showAlert(data.error, 'danger'); return; }
                    showAlert('PAR cycle cancelled.', 'info');
                    refreshPAR(ticketId);
                });
            });
        }

        function firePAR(kind) {
            fetch('api/par.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action:    'initiate',
                    ticket_id: ticketId,
                    kind:      kind,
                    csrf_token: getCsrfToken()
                })
            }).then(function (r) { return r.json(); }).then(function (data) {
                if (data && data.error) { showAlert(data.error, 'danger'); return; }
                showAlert(kind === 'mayday' ? 'MAYDAY declared. PAR initiated.' : 'PAR initiated.', 'success');
                refreshPAR(ticketId);
            });
        }

        // Phase 16d — per-incident override save.
        if (overrideBtn) {
            overrideBtn.addEventListener('click', function () {
                fetch('api/par.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'set_override',
                        ticket_id: ticketId,
                        cadence_minutes: overrideInput.value,
                        csrf_token: getCsrfToken()
                    })
                }).then(function (r) { return r.json(); }).then(function (data) {
                    if (data && data.error) { showAlert(data.error, 'danger'); return; }
                    showAlert('PAR cadence override saved.', 'success');
                    refreshPAR(ticketId);
                });
            });
        }

        // Phase 16d — history pane.
        function loadPARHistory() {
            if (!historyDiv) return;
            fetch('api/par.php?action=history&ticket=' + encodeURIComponent(ticketId),
                  { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data || data.error || !data.history || !data.history.length) {
                        historyDiv.innerHTML = '<div class="text-body-secondary small">No prior PAR cycles for this incident.</div>';
                        return;
                    }
                    var html = '<table class="table table-sm mb-0"><thead><tr>' +
                        '<th class="small">Started</th><th class="small">Kind</th>' +
                        '<th class="small">Status</th><th class="small text-end">Units / Acked / Missed</th></tr></thead><tbody>';
                    for (var i = 0; i < data.history.length; i++) {
                        var h = data.history[i];
                        html += '<tr>' +
                            '<td class="small">' + esc(h.initiated_at) + '</td>' +
                            '<td class="small">' + esc(h.initiated_kind) + '</td>' +
                            '<td class="small">' + esc(h.status) + '</td>' +
                            '<td class="small text-end">' + h.units + ' / <span class="text-success">' + h.acked + '</span> / ' +
                                (h.missed > 0 ? '<span class="text-danger">' + h.missed + '</span>' : h.missed) +
                            '</td></tr>';
                    }
                    html += '</tbody></table>';
                    historyDiv.innerHTML = html;
                });
        }
        // Lazy-load history when the <details> is opened.
        var detailsEl = historyDiv ? historyDiv.closest('details') : null;
        if (detailsEl) detailsEl.addEventListener('toggle', function () {
            if (detailsEl.open) loadPARHistory();
        });

        function refreshPAR(tid) {
            fetch('api/par.php?action=for_ticket&ticket=' + encodeURIComponent(tid),
                  { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data || data.error) return;
                    // Phase 27B (2026-06-11) — update header badge.
                    updateParHeaderBadge(data);
                    if (!data.enabled) {
                        // 2026-06-11 — Render an activation hint
                        // instead of hiding the card entirely. Admins
                        // need to be able to find the master switch
                        // without rummaging through settings.
                        card.classList.remove('d-none');
                        var body = card.querySelector('.card-body');
                        if (body) {
                            body.innerHTML =
                                '<div class="text-body-secondary small">' +
                                '<i class="bi bi-info-circle me-1"></i>' +
                                'PAR check features are not enabled. ' +
                                '<a href="settings.php#par-checks" class="alert-link">Enable in Settings → PAR Checks</a> ' +
                                'to use scheduled accountability reports, mobile acks, and dispatcher prompts. ' +
                                'The <strong>MAYDAY</strong> button at the top works regardless of this setting.' +
                                '</div>';
                        }
                        // Hide the inline Initiate / Mayday buttons in
                        // the card header — the top Mayday handles it.
                        ['btnInitiatePAR','btnMayday','btnSaveParOverride'].forEach(function (id) {
                            var el = document.getElementById(id);
                            if (el) el.classList.add('d-none');
                        });
                        var badge = document.getElementById('parStatusBadge');
                        if (badge) {
                            badge.textContent = 'disabled';
                            badge.className = 'badge ms-2 small bg-secondary';
                        }
                        return;
                    }
                    card.classList.remove('d-none');

                    var lastEl = document.getElementById('parLastTime');
                    var nextEl = document.getElementById('parNextDue');
                    var badge  = document.getElementById('parStatusBadge');
                    var tbl    = document.getElementById('parAckTable');
                    if (!lastEl || !nextEl || !badge || !tbl) return;

                    if (data.latest && data.latest.cycle) {
                        lastEl.textContent = friendlyTime(data.latest.cycle.initiated_at);
                        badge.textContent = data.latest.cycle.status;
                        badge.className = 'badge ms-2 small ' +
                            (data.latest.cycle.status === 'pending'  ? 'bg-warning' :
                             data.latest.cycle.status === 'complete' ? 'bg-success' :
                             data.latest.cycle.status === 'aborted'  ? 'bg-secondary' : 'bg-info');
                        tbl.innerHTML = renderAckRows(data.latest.acks || []);
                        // Phase 27 hotfix: Cancel button visible only while pending
                        var cancelBtn = document.getElementById('btnCancelParCycle');
                        if (cancelBtn) {
                            if (data.latest.cycle.status === 'pending') {
                                cancelBtn.classList.remove('d-none');
                                cancelBtn.dataset.cycleId = data.latest.cycle.id;
                            } else {
                                cancelBtn.classList.add('d-none');
                            }
                        }
                    } else {
                        lastEl.textContent = 'never';
                        badge.textContent = 'no cycle';
                        badge.className = 'badge ms-2 small bg-secondary';
                        tbl.innerHTML = '<div class="text-body-secondary small">No PAR cycle yet.</div>';
                        var cancelBtn2 = document.getElementById('btnCancelParCycle');
                        if (cancelBtn2) cancelBtn2.classList.add('d-none');
                    }
                    if (data.due_at) {
                        nextEl.textContent = friendlyCountdown(data.due_at);
                    } else {
                        nextEl.textContent = '—';
                    }
                    // Phase 16d — show current override.
                    var overrideEl = document.getElementById('parOverrideMin');
                    if (overrideEl && data.override !== undefined) {
                        overrideEl.value = (data.override === null || data.override === '') ? '' : data.override;
                    }
                })
                .catch(function () {});
        }

        function renderAckRows(acks) {
            if (!acks.length) {
                return '<div class="text-body-secondary small">No units assigned at PAR time.</div>';
            }
            var html = '<table class="table table-sm mb-0"><tbody>';
            for (var i = 0; i < acks.length; i++) {
                var a = acks[i];
                var stateBadge = '<span class="badge ' +
                    (a.state === 'acked'   ? 'bg-success' :
                     a.state === 'missed'  ? 'bg-danger' :
                     a.state === 'aborted' ? 'bg-secondary' : 'bg-warning') +
                    '">' + a.state + '</span>';
                var ack = a.state === 'pending'
                    ? '<button class="btn btn-xs btn-outline-success" data-cid="' + a.par_cycle_id +
                      '" data-rid="' + a.responder_id + '" data-unit="' + esc(a.unit_name || '') + '">Ack on behalf</button>'
                    : (a.member_count !== null ? a.member_count + ' members ' : '') +
                      (a.acked_via && a.acked_via !== 'mobile'
                          ? '<span class="badge bg-light text-dark me-1" title="How they replied">' + esc(a.acked_via.replace('_',' ')) + '</span>'
                          : '') +
                      (a.acked_at ? '@ ' + friendlyTime(a.acked_at) : '');
                html += '<tr>' +
                    '<td class="small">' + esc(a.unit_name) + '</td>' +
                    '<td class="small">' + stateBadge + '</td>' +
                    '<td class="small text-end">' + ack + '</td>' +
                    '</tr>';
                if (a.comments) {
                    html += '<tr><td colspan="3" class="small text-body-secondary fst-italic">"' + esc(a.comments) + '"</td></tr>';
                }
            }
            html += '</tbody></table>';
            return html;
        }

        // Phase 27A (2026-06-12) — dispatcher ack-on-behalf modal.
        // Many volunteer units don't have the mobile app; they reply
        // by voice over radio. Dispatcher needs a proper form (not a
        // browser prompt()) to record the channel + transcribe what
        // was said + capture member count. The backend already
        // accepts via/member_count/comments — we just stopped using
        // half of it.
        document.getElementById('parAckTable').addEventListener('click', function (e) {
            var btn = e.target.closest('button[data-cid]');
            if (!btn) return;
            openParAckModal({
                cycle_id:     parseInt(btn.getAttribute('data-cid'), 10),
                responder_id: parseInt(btn.getAttribute('data-rid'), 10),
                unit_name:    btn.getAttribute('data-unit') || 'this unit'
            });
        });

        function openParAckModal(ctx) {
            var modalEl = document.getElementById('parAckModal');
            if (!modalEl) {
                // Inject the modal HTML once on first use so we don't
                // bloat the page template for everyone.
                modalEl = buildParAckModal();
                document.body.appendChild(modalEl);
            }
            // Reset form fields
            modalEl.querySelector('#parAckUnitName').textContent = ctx.unit_name;
            modalEl.querySelector('#parAckVia').value = 'voice_radio';
            // Phase 116b (GH #85) — pre-fill the expected head-count from the
            // unit's assigned crew so PAR reflects who's on the unit. The
            // dispatcher can still override with what the unit actually reports.
            var expectedCrew = '';
            if (incidentData && incidentData.assignments) {
                for (var pci = 0; pci < incidentData.assignments.length; pci++) {
                    var pa = incidentData.assignments[pci];
                    if (parseInt(pa.responder_id, 10) === parseInt(ctx.responder_id, 10)
                        && pa.crew_count > 0) {
                        expectedCrew = String(pa.crew_count);
                        break;
                    }
                }
            }
            modalEl.querySelector('#parAckMembers').value = expectedCrew;
            modalEl.querySelector('#parAckComments').value = '';
            modalEl.querySelector('#parAckNotes').value = '';
            modalEl.dataset.cid = ctx.cycle_id;
            modalEl.dataset.rid = ctx.responder_id;
            var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
            modal.show();
            // Focus the channel dropdown for keyboard-first dispatch
            setTimeout(function () {
                modalEl.querySelector('#parAckVia').focus();
            }, 250);
        }

        function buildParAckModal() {
            var wrap = document.createElement('div');
            wrap.innerHTML =
                '<div class="modal fade" id="parAckModal" tabindex="-1" aria-labelledby="parAckModalLabel" aria-hidden="true">' +
                '  <div class="modal-dialog modal-dialog-centered">' +
                '    <div class="modal-content">' +
                '      <div class="modal-header py-2">' +
                '        <h6 class="modal-title" id="parAckModalLabel">' +
                '          <i class="bi bi-shield-check text-success me-1"></i>' +
                '          Acknowledge PAR for <span id="parAckUnitName" class="fw-bold"></span>' +
                '        </h6>' +
                '        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>' +
                '      </div>' +
                '      <div class="modal-body small">' +
                '        <p class="text-body-secondary mb-3">Acknowledge on this unit\'s behalf when they replied off-app. Record exactly what they said so the after-action ICS-214 has a faithful log.</p>' +
                '        <div class="mb-2">' +
                '          <label for="parAckVia" class="form-label form-label-sm fw-bold">How did they reply? <span class="text-danger">*</span></label>' +
                '          <select class="form-select form-select-sm" id="parAckVia">' +
                '            <option value="voice_radio">Voice over radio</option>' +
                '            <option value="phone">Phone call</option>' +
                '            <option value="in_person">In person</option>' +
                '            <option value="dispatcher_manual">Other / dispatcher initiative</option>' +
                '          </select>' +
                '        </div>' +
                '        <div class="mb-2">' +
                '          <label for="parAckMembers" class="form-label form-label-sm fw-bold">Personnel accounted for</label>' +
                '          <input type="number" class="form-control form-control-sm" id="parAckMembers" min="0" max="99" placeholder="e.g. 4">' +
                '          <div class="form-text small">Number the unit reported. Leave blank if they didn\'t say.</div>' +
                '        </div>' +
                '        <div class="mb-2">' +
                '          <label for="parAckComments" class="form-label form-label-sm fw-bold">What did they say? (visible to all)</label>' +
                '          <textarea class="form-control form-control-sm" id="parAckComments" rows="2" placeholder="Verbatim if practical: &quot;Tanker-2, 4 personnel all OK, working west flank&quot;"></textarea>' +
                '          <div class="form-text small">Goes into the PAR audit + the after-action ICS-214 for this unit.</div>' +
                '        </div>' +
                '        <div class="mb-2">' +
                '          <label for="parAckNotes" class="form-label form-label-sm">Private dispatcher notes (optional)</label>' +
                '          <textarea class="form-control form-control-sm" id="parAckNotes" rows="1" placeholder="Internal only — e.g. &quot;voice sounded strained — flag for follow-up&quot;"></textarea>' +
                '        </div>' +
                '      </div>' +
                '      <div class="modal-footer py-2">' +
                '        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>' +
                '        <button type="button" class="btn btn-sm btn-success" id="parAckSubmit">' +
                '          <i class="bi bi-check-lg me-1"></i>Record acknowledgement' +
                '        </button>' +
                '      </div>' +
                '    </div>' +
                '  </div>' +
                '</div>';
            var el = wrap.firstElementChild;
            // Wire submit handler once at build time
            el.querySelector('#parAckSubmit').addEventListener('click', function () {
                var payload = {
                    action:       'ack',
                    cycle_id:     parseInt(el.dataset.cid, 10),
                    responder_id: parseInt(el.dataset.rid, 10),
                    via:          el.querySelector('#parAckVia').value || 'dispatcher_manual',
                    comments:     el.querySelector('#parAckComments').value.trim(),
                    notes:        el.querySelector('#parAckNotes').value.trim(),
                    csrf_token:   getCsrfToken()
                };
                var mc = el.querySelector('#parAckMembers').value;
                if (mc !== '') payload.member_count = parseInt(mc, 10);
                fetch('api/par.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                }).then(function (r) { return r.json(); }).then(function (data) {
                    if (data && data.error) {
                        showAlert(data.error, 'danger');
                        return;
                    }
                    bootstrap.Modal.getInstance(el).hide();
                    refreshPAR(ticketId);
                });
            });
            // Enter inside the textareas should not submit — but
            // Ctrl+Enter should fire the submit, dispatcher-first.
            el.addEventListener('keydown', function (e) {
                if (e.ctrlKey && e.key === 'Enter') {
                    el.querySelector('#parAckSubmit').click();
                }
            });
            return el;
        }

        // Phase 27B (2026-06-12) — header PAR badge updater.
        // Reads the same /api/par.php data refreshPAR() already pulls
        // and reflects status in the page-header badge so dispatchers
        // see at a glance whether PAR is active and when the next
        // cycle is due.
        function updateParHeaderBadge(data) {
            var badge = document.getElementById('parHeaderBadge');
            var text  = document.getElementById('parHeaderText');
            if (!badge || !text) return;
            if (!data.enabled) {
                badge.classList.add('d-none');
                hideParOverdueBanner();
                return;
            }
            badge.classList.remove('d-none');
            var cadenceMin = data.cadence && data.cadence.cadence_minutes;
            var nextLabel = data.due_at ? friendlyCountdown(data.due_at) : 'unscheduled';
            var srcLabel  = data.cadence && data.cadence.source
                ? ' [' + data.cadence.source.replace('_', ' ') + ']'
                : '';
            text.textContent = 'PAR ' + (cadenceMin ? cadenceMin + 'm · next ' + nextLabel : 'on demand') + srcLabel;
            // Tint by urgency.
            // Phase 27 follow-up (2026-06-12) — par_due_at() returns
            // a Unix-seconds integer, NOT a date string. Date.parse()
            // returned NaN before, which fell through to "blue."
            badge.classList.remove('bg-info', 'bg-warning', 'bg-danger');
            badge.classList.remove('text-dark');
            var secs = null;
            if (data.due_at) {
                secs = parseInt(data.due_at, 10) - Math.floor(Date.now() / 1000);
            }
            if (secs !== null && secs <= 0) {
                badge.classList.add('bg-danger');
                showParOverdueBanner(data, -secs);
            } else if (secs !== null && secs <= 120) {
                badge.classList.add('bg-warning', 'text-dark');
                hideParOverdueBanner();
            } else {
                badge.classList.add('bg-info', 'text-dark');
                hideParOverdueBanner();
            }
        }

        // Phase 27 follow-up (2026-06-12) — sticky-banner driver.
        // The badge alone is too subtle for an overdue PAR. This
        // function reveals the alarm strip, pulses the browser title,
        // and re-plays the audio tone on a 30s cadence until the
        // dispatcher initiates a cycle or snoozes.
        var parAlarmSnoozedUntil = 0;          // epoch seconds
        var parAlarmLastTone     = 0;          // epoch seconds
        var parAlarmTitleTimer   = null;
        var parAlarmOriginalTitle = null;

        function showParOverdueBanner(data, overdueSecs) {
            var banner = document.getElementById('parOverdueBanner');
            var detail = document.getElementById('parOverdueDetail');
            if (!banner || !detail) return;

            // Snoozed? Keep banner hidden, badge stays red.
            var nowSec = Math.floor(Date.now() / 1000);
            if (nowSec < parAlarmSnoozedUntil) return;

            banner.classList.remove('d-none');
            var min = Math.floor(overdueSecs / 60);
            var sec = overdueSecs % 60;
            var howLong = (min > 0 ? min + 'm ' : '') + sec + 's overdue.';
            detail.textContent = howLong + ' Initiate a PAR cycle now.';

            // Pulse the browser tab title so a dispatcher with the tab
            // backgrounded notices.
            if (parAlarmOriginalTitle === null) {
                parAlarmOriginalTitle = document.title;
            }
            if (!parAlarmTitleTimer) {
                var toggle = false;
                parAlarmTitleTimer = setInterval(function () {
                    toggle = !toggle;
                    document.title = toggle
                        ? '🚨 PAR OVERDUE — ' + parAlarmOriginalTitle
                        : parAlarmOriginalTitle;
                }, 1000);
            }

            // Audio every 30s until ack / snooze / initiate
            if (window.AudioAlerts && (nowSec - parAlarmLastTone) >= 30) {
                try { window.AudioAlerts.playTone('parOverdue'); } catch (e) {}
                parAlarmLastTone = nowSec;
            }
        }

        function hideParOverdueBanner() {
            var banner = document.getElementById('parOverdueBanner');
            if (banner) banner.classList.add('d-none');
            if (parAlarmTitleTimer) {
                clearInterval(parAlarmTitleTimer);
                parAlarmTitleTimer = null;
                if (parAlarmOriginalTitle !== null) {
                    document.title = parAlarmOriginalTitle;
                }
            }
        }

        function wireParOverdueBannerButtons() {
            var initBtn  = document.getElementById('btnParOverdueInitiate');
            var snoozeBtn = document.getElementById('btnParOverdueSnooze');
            if (initBtn && !initBtn.dataset.wired) {
                initBtn.dataset.wired = '1';
                initBtn.addEventListener('click', function () {
                    var topInit = document.getElementById('btnInitiatePAR');
                    if (topInit) topInit.click();
                });
            }
            if (snoozeBtn && !snoozeBtn.dataset.wired) {
                snoozeBtn.dataset.wired = '1';
                snoozeBtn.addEventListener('click', function () {
                    parAlarmSnoozedUntil = Math.floor(Date.now() / 1000) + 5 * 60;
                    hideParOverdueBanner();
                });
            }
        }
        wireParOverdueBannerButtons();

        function esc(s) {
            if (s === null || s === undefined) return '';
            return String(s).replace(/[&<>"']/g, function (c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
            });
        }
        function friendlyTime(s) {
            if (!s) return '—';
            var d = new Date(s.replace(' ', 'T'));
            return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        }
        function friendlyCountdown(ts) {
            var now = Math.floor(Date.now() / 1000);
            var diff = ts - now;
            if (diff <= 0) return 'OVERDUE';
            var min = Math.floor(diff / 60), sec = diff % 60;
            return (min > 0 ? min + 'm ' : '') + sec + 's';
        }

        refreshPAR(ticketId);
        setInterval(function () { refreshPAR(ticketId); }, 10000);
    }

    function getIncidentId() {
        var params = new URLSearchParams(window.location.search);
        var id = parseInt(params.get('id'), 10);
        return id > 0 ? id : null;
    }

    function getCsrfToken() {
        var el = document.getElementById('csrfToken');
        return el ? el.value : '';
    }

    function setInitialFocus() {
        var params = new URLSearchParams(window.location.search);
        var tab = params.get('tab');

        setTimeout(function () {
            if (tab === 'assign') {
                // Focus responder search when arriving via Dispatch/Units action
                var search = document.getElementById('responderSearch');
                if (search) search.focus();
            } else {
                // Default: focus the note text area for quick note entry
                var note = document.getElementById('noteText');
                if (note) note.focus();
            }
        }, 200); // Slight delay to ensure DOM is ready after render
    }

    // ── Load incident data from API ──
    function loadIncident(id) {
        fetch('api/incident-detail.php?id=' + id)
            .then(function (r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.json();
            })
            .then(function (data) {
                if (data.error) {
                    showAlert(escHtml(data.error), 'danger');
                    document.getElementById('loadingSpinner').classList.add('d-none');
                    return;
                }

                incidentData = data;

                // Update page title
                document.title = '#' + data.incident.id + ' ' + data.incident.scope + ' — Tickets NewUI';

                // Render all sections
                renderHeader(data.incident);
                renderDescription(data.incident);
                renderLocation(data.incident);
                renderContact(data.incident);
                renderFacilities(data.incident);
                renderTimeStatus(data.incident);
                renderAdditional(data.incident);
                renderProtocol(data.incident);
                renderAssignments(data.assignments);
                renderActions(data.actions);
                initMap(data.incident);

                // Show content, hide spinner
                document.getElementById('loadingSpinner').classList.add('d-none');
                document.getElementById('mainContent').classList.remove('d-none');

                // Init status controls with current value
                syncStatusSelect(data.incident);

                // Navigation button — Phase 71 launcher (Apple/Google/Waze
                // chooser, remembers preference). Falls back to a plain
                // Google Maps web link if NavigateLauncher hasn't loaded.
                var navBtn = document.getElementById('btnNavigate');
                if (navBtn && data.incident.lat && data.incident.lng &&
                    parseFloat(data.incident.lat) !== 0 && parseFloat(data.incident.lng) !== 0) {
                    navBtn.classList.remove('d-none');
                    var addrParts = [data.incident.street, data.incident.city, data.incident.state];
                    var addr = addrParts.filter(function (s) { return s; }).join(', ');
                    if (typeof NavigateLauncher !== 'undefined') {
                        NavigateLauncher.attach(navBtn, {
                            lat: parseFloat(data.incident.lat),
                            lng: parseFloat(data.incident.lng),
                            address: addr || null
                        });
                    } else {
                        navBtn.href = 'https://www.google.com/maps/dir/?api=1&destination=' +
                            data.incident.lat + ',' + data.incident.lng;
                    }
                }

                // Winlink ICS-213 export button
                var wlBtn = document.getElementById('btnWinlinkExport');
                if (wlBtn) {
                    wlBtn.href = 'api/winlink-export.php?form=ics213&ticket_id=' + data.incident.id;
                    wlBtn.classList.remove('d-none');
                }

                // 2026-06-28 — defer the responders fetch so the page
                // paints immediately with the incident details. The
                // Available Responders panel is collapsed by default
                // and the search input handles its own re-check, so
                // delaying ~50ms costs the user nothing visible and
                // makes the perceived load time the incident-detail
                // fetch only (~150ms) instead of both calls combined.
                if (window.requestIdleCallback) {
                    window.requestIdleCallback(loadResponders, { timeout: 500 });
                } else {
                    setTimeout(loadResponders, 50);
                }

                // Enable inline edit buttons
                initEditButtons();

                // Set initial keyboard focus based on URL tab parameter
                setInitialFocus();
            })
            .catch(function (err) {
                showAlert('Failed to load incident: ' + escHtml(err.message), 'danger');
                document.getElementById('loadingSpinner').classList.add('d-none');
            });
    }

    // ── Reload just the assignments and actions (after changes) ──
    function refreshIncident() {
        var id = getIncidentId();
        if (!id) return;

        fetch('api/incident-detail.php?id=' + id)
            .then(function (r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.json();
            })
            .then(function (data) {
                if (data.error) return;
                incidentData = data;
                renderAssignments(data.assignments);
                renderActions(data.actions);
                renderHeader(data.incident);
                renderTimeStatus(data.incident);
                syncStatusSelect(data.incident);
                loadResponders();
            })
            .catch(function () {});
    }

    // ── Load available responders for assignment dropdown + available panel ──
    // 2026-06-28 — guard against duplicate fetches (idle-callback timing
    // can race with manual triggers like search-focus / panel-expand).
    // Returns the in-flight promise so callers can chain off it.
    var _respondersPromise = null;
    function loadResponders() {
        if (_respondersPromise) return _respondersPromise;
        _respondersPromise = fetch('api/responders.php')
            .then(function (r) { return r.json(); })
            .then(function (data) {
                allResponders = data.responders || [];
                renderAvailableResponders();
                return allResponders;
            })
            .catch(function () {
                allResponders = [];
                renderAvailableResponders();
                _respondersPromise = null;  // allow retry on transient failure
                return [];
            });
        return _respondersPromise;
    }

    // 2026-06-28 (Eric beta) — sortable Available Responders panel.
    // Default sort = distance ASC (matches the dispatcher's primary
    // "who's closest" question). Click a column header to switch the
    // key; click the same header to toggle direction.
    var availSortKey = 'distance';
    var availSortDir = 'asc';

    function bindAvailSortHandlers() {
        var headers = document.querySelectorAll('.avail-sort-hdr');
        for (var i = 0; i < headers.length; i++) {
            // Idempotent: dataset flag prevents double-binding on re-render.
            if (headers[i].dataset.bound === '1') continue;
            headers[i].dataset.bound = '1';
            headers[i].addEventListener('click', function () {
                var key = this.getAttribute('data-sort');
                if (key === availSortKey) {
                    availSortDir = (availSortDir === 'asc') ? 'desc' : 'asc';
                } else {
                    availSortKey = key;
                    // Distance defaults to ASC (closest first), name to ASC
                    // (A-Z), status to ASC (so 'Available' sorts above
                    // 'Out of Service'). All keys start ASC on first click.
                    availSortDir = 'asc';
                }
                renderAvailableResponders();
            });
        }
    }

    // ── Render Available Responders panel ──
    function renderAvailableResponders() {
        // 2026-06-28 — render target is the table tbody (rebuilt as a
        // sortable table). Previous selector '#availableRespondersList .px-2'
        // collided with the sort-header div (also .px-2) so unit rows
        // were rendering INTO the header. Now uses a unique tbody ID.
        var container = document.getElementById('availableRespondersTbody');
        var countBadge = document.getElementById('availableCount');
        if (!container) return;

        // Get currently assigned responder IDs
        var assignedIds = {};
        if (incidentData && incidentData.assignments) {
            incidentData.assignments.forEach(function (a) {
                if (!a.clear || a.clear === '0000-00-00 00:00:00') {
                    assignedIds[a.responder_id] = true;
                }
            });
        }

        if (allResponders.length === 0) {
            container.innerHTML = '<tr><td colspan="3" class="text-center text-body-secondary py-2 small">No responders found</td></tr>';
            if (countBadge) countBadge.textContent = '0';
            return;
        }

        // 2026-06-28 (Eric beta request, follow-on) — Available
        // Responders is the dispatcher's primary decision point ("who's
        // closest and available to send"). Compute haversine distance
        // from the incident to each responder, sort ASC (closest first,
        // units with no location data to the bottom), and flag stale
        // GPS the same way the assignments table does.
        var stThresh = (window.STALE_LOCATION_MIN && window.STALE_LOCATION_MIN > 0)
            ? window.STALE_LOCATION_MIN : 30;
        var incLat = incidentData && incidentData.incident && incidentData.incident.lat;
        var incLng = incidentData && incidentData.incident && incidentData.incident.lng;
        var haveIncidentLoc = (typeof incLat === 'number' && typeof incLng === 'number');
        var nowMs = Date.now();
        function haversineKm(lat1, lng1, lat2, lng2) {
            var R = 6371.0;
            var dLat = (lat2 - lat1) * Math.PI / 180;
            var dLng = (lng2 - lng1) * Math.PI / 180;
            var a = Math.sin(dLat/2) * Math.sin(dLat/2)
                  + Math.cos(lat1*Math.PI/180) * Math.cos(lat2*Math.PI/180)
                  * Math.sin(dLng/2) * Math.sin(dLng/2);
            return 2 * R * Math.asin(Math.sqrt(a));
        }
        // Decorate each responder with distance_km + stale_min + age.
        // Mutate-safe via slice() so the global allResponders isn't reordered.
        var decorated = allResponders.slice().map(function (r) {
            var rLat = parseFloat(r.lat);
            var rLng = parseFloat(r.lng);
            var haveRespLoc = !isNaN(rLat) && !isNaN(rLng) && (rLat !== 0 || rLng !== 0);
            var d = (haveIncidentLoc && haveRespLoc)
                ? haversineKm(rLat, rLng, incLat, incLng)
                : null;
            var ageMin = null;
            var staleMin = null;
            if (haveRespLoc && r.updated) {
                var updMs = Date.parse(String(r.updated).replace(' ', 'T') + 'Z');
                if (!isNaN(updMs)) {
                    ageMin = Math.round((nowMs - updMs) / 60000);
                    if (ageMin > stThresh) staleMin = ageMin;
                }
            }
            return Object.assign({}, r, {
                _distanceKm: d,
                _staleMin:   staleMin,
                _ageMin:     ageMin,
                _noLocation: !haveRespLoc,
            });
        });
        // Sort by the user-selected key. Distance is the historic
        // default; name + status added 2026-06-28 per Eric beta request.
        var dirMul = (availSortDir === 'desc') ? -1 : 1;
        decorated.sort(function (x, y) {
            var cmp = 0;
            if (availSortKey === 'name') {
                // Prefer the handle (callsign / short form); fall back to name.
                var nx = (x.handle || x.name || '').toLowerCase();
                var ny = (y.handle || y.name || '').toLowerCase();
                if (nx < ny) cmp = -1; else if (nx > ny) cmp = 1;
            } else if (availSortKey === 'status') {
                var sx = (x.status_name || '').toLowerCase();
                var sy = (y.status_name || '').toLowerCase();
                if (sx < sy) cmp = -1; else if (sx > sy) cmp = 1;
            } else {
                // distance (default)
                var dx = (typeof x._distanceKm === 'number') ? x._distanceKm : Infinity;
                var dy = (typeof y._distanceKm === 'number') ? y._distanceKm : Infinity;
                if (dx < dy) cmp = -1; else if (dx > dy) cmp = 1;
            }
            return cmp * dirMul;
        });

        // Update the column-header arrows so the user sees which one's active.
        var headers = document.querySelectorAll('.avail-sort-hdr');
        var arrow   = (availSortDir === 'desc') ? '▼' : '▲';
        for (var hi = 0; hi < headers.length; hi++) {
            var isActive = (headers[hi].getAttribute('data-sort') === availSortKey);
            var arrowEl  = headers[hi].querySelector('.avail-sort-arrow');
            if (arrowEl) arrowEl.innerHTML = isActive ? arrow : '';
            // Highlight the active header so it stands out.
            if (isActive) {
                headers[hi].classList.add('text-primary');
                headers[hi].classList.remove('text-body-secondary');
            } else {
                headers[hi].classList.remove('text-primary');
            }
        }

        var html = '';
        var availCount = 0;

        decorated.forEach(function (r) {
            var isAssigned = assignedIds[r.id];
            var statusText = r.status_name || 'Unknown';
            var isAvailable = statusText.toLowerCase().indexOf('avail') !== -1;

            var badgeClass = 'bg-secondary';
            if (isAvailable && !isAssigned) {
                badgeClass = 'bg-success';
                availCount++;
            } else if (isAssigned) {
                badgeClass = 'bg-warning text-dark';
            } else {
                badgeClass = 'bg-secondary';
            }

            var icon = isAssigned ? '<i class="bi bi-check-circle-fill text-primary me-1" style="font-size: 0.65rem;"></i>' : '';
            var rowClass = !isAssigned ? 'avail-resp-item' : 'text-body-secondary';
            var rowStyle = !isAssigned ? 'cursor:pointer;' : '';

            // Build the distance/freshness cell. Four cases:
            //   1. No resp location at all → yellow ⚠ "Off-grid"
            //   2. Have resp location, no incident anchor → show GPS age
            //      with stale flag (helps dispatcher pre-call to see
            //      which units are even GPS-trackable + how fresh)
            //   3. Both → distance + optional stale flag (the normal path)
            //   4. Anything else → dim '—'
            var distCell;
            if (r._noLocation) {
                distCell = '<i class="bi bi-exclamation-triangle-fill text-warning" '
                         + 'title="No location data for this unit"></i>';
            } else if (!haveIncidentLoc && typeof r._ageMin === 'number') {
                // Show GPS age with a clock icon so it doesn't look like
                // a unit of distance ("3h" alone reads as a distance unit
                // — the clock icon makes it clear it's elapsed time).
                // Triggers when the unit has GPS but the incident has no
                // anchor to compute distance from.
                var ageLabel = r._ageMin < 60
                    ? r._ageMin + 'm'
                    : Math.round(r._ageMin / 60) + 'h';
                var ageCls = (r._staleMin !== null) ? 'text-warning' : 'text-body-secondary';
                var ageTitle = (r._staleMin !== null)
                    ? 'GPS fix is ' + r._ageMin + ' min old (stale). Set incident location to see distance.'
                    : 'Last GPS fix ' + ageLabel + ' ago. Set incident location to see distance.';
                distCell = '<span class="' + ageCls + '" '
                         + 'title="' + ageTitle + '" style="white-space:nowrap;">'
                         + '<i class="bi bi-clock-history me-1"></i>'
                         + '<span class="font-monospace small">' + ageLabel + '</span>'
                         + '</span>';
            } else if (typeof r._distanceKm === 'number') {
                var staleHtml = '';
                if (r._staleMin !== null) {
                    staleHtml = ' <i class="bi bi-exclamation-triangle-fill text-warning" '
                              + 'title="Location ' + r._staleMin + ' min old"></i>';
                }
                var distLbl = (window.formatDistanceKm)
                    ? window.formatDistanceKm(r._distanceKm)
                    : r._distanceKm.toFixed(1) + ' km';
                distCell = '<span class="font-monospace" '
                         + 'title="Distance from incident">'
                         + distLbl + staleHtml + '</span>';
            } else {
                distCell = '<span class="text-body-tertiary">—</span>';
            }

            html += '<tr class="' + rowClass + '"'
                + (!isAssigned ? ' data-responder-id="' + r.id + '" data-responder-name="' + escHtml(r.handle || r.name) + '"' : '')
                + (rowStyle ? ' style="' + rowStyle + '"' : '')
                + '>'
                + '<td>'
                + icon
                + '<span class="fw-semibold me-1">' + escHtml(r.handle || '') + '</span>'
                + '<span class="text-body-secondary small">' + escHtml(r.name || '') + '</span>'
                + '</td>'
                + '<td class="text-end">' + distCell + '</td>'
                + '<td class="text-end">'
                + '<span class="badge ' + badgeClass + '" style="font-size: 0.6rem;">'
                + (isAssigned ? 'Assigned' : escHtml(statusText))
                + '</span>'
                + '</td>'
                + '</tr>';
        });

        container.innerHTML = html;
        if (countBadge) countBadge.textContent = availCount;

        // 2026-06-28 — wire the sort headers (idempotent — guarded by dataset.bound).
        bindAvailSortHandlers();

        // Click to quick-assign: fill search input with responder name
        container.querySelectorAll('.avail-resp-item').forEach(function (item) {
            item.addEventListener('click', function () {
                var respId = parseInt(item.dataset.responderId);
                var respName = item.dataset.responderName;
                var searchInput = document.getElementById('responderSearch');
                if (searchInput && respName) {
                    searchInput.value = respName;
                    searchInput.dispatchEvent(new Event('input'));
                    // Auto-select this responder
                    setTimeout(function () {
                        selectResponder(respId);
                    }, 100);
                }
            });
        });
    }

    // ── Responder search/assign controls ──
    var dropdownHighlightIdx = -1;

    function initAssignControls() {
        var searchInput = document.getElementById('responderSearch');
        var dropdown = document.getElementById('responderDropdown');
        var assignBtn = document.getElementById('btnAssignResponder');

        if (!searchInput || !dropdown || !assignBtn) return;

        // Search input — debounced filter
        searchInput.addEventListener('input', function () {
            var query = searchInput.value.trim().toLowerCase();
            selectedResponderId = null;
            assignBtn.disabled = true;
            dropdownHighlightIdx = -1;

            if (searchTimeout) clearTimeout(searchTimeout);

            if (query.length < 1) {
                dropdown.classList.add('d-none');
                dropdown.innerHTML = '';
                return;
            }

            searchTimeout = setTimeout(function () {
                renderResponderDropdown(query);
            }, 150);
        });

        // Keyboard navigation in dropdown: arrows + enter + escape
        searchInput.addEventListener('keydown', function (e) {
            var items = dropdown.querySelectorAll('.responder-option');
            if (!items.length || dropdown.classList.contains('d-none')) {
                // Enter when a responder is already selected → assign immediately
                if (e.key === 'Enter' && selectedResponderId) {
                    e.preventDefault();
                    assignResponder(selectedResponderId);
                }
                return;
            }

            if (e.key === 'ArrowDown') {
                e.preventDefault();
                dropdownHighlightIdx = Math.min(dropdownHighlightIdx + 1, items.length - 1);
                highlightDropdownItem(items);
                return;
            }

            if (e.key === 'ArrowUp') {
                e.preventDefault();
                dropdownHighlightIdx = Math.max(dropdownHighlightIdx - 1, 0);
                highlightDropdownItem(items);
                return;
            }

            if (e.key === 'Enter') {
                e.preventDefault();
                if (dropdownHighlightIdx >= 0 && items[dropdownHighlightIdx]) {
                    var rid = parseInt(items[dropdownHighlightIdx].getAttribute('data-responder-id'), 10);
                    selectResponder(rid);
                    // Auto-assign immediately on Enter
                    assignResponder(rid);
                } else if (items.length === 1) {
                    // Only one result — pick it and assign
                    var singleRid = parseInt(items[0].getAttribute('data-responder-id'), 10);
                    selectResponder(singleRid);
                    assignResponder(singleRid);
                }
                return;
            }

            if (e.key === 'Escape') {
                e.preventDefault();
                dropdown.classList.add('d-none');
                dropdownHighlightIdx = -1;
                return;
            }
        });

        // Close dropdown when clicking outside
        document.addEventListener('click', function (e) {
            if (!searchInput.contains(e.target) && !dropdown.contains(e.target)) {
                dropdown.classList.add('d-none');
                dropdownHighlightIdx = -1;
            }
        });

        // Focus re-opens dropdown if there's text, and auto-expands Available Units
        searchInput.addEventListener('focus', function () {
            var query = searchInput.value.trim().toLowerCase();
            if (query.length >= 1 && dropdown.children.length > 0) {
                dropdown.classList.remove('d-none');
            }
            // Auto-expand the Available Units panel
            var availCollapse = document.getElementById('availableRespondersList');
            if (availCollapse && !availCollapse.classList.contains('show')) {
                bootstrap.Collapse.getOrCreateInstance(availCollapse).show();
            }
        });

        // Assign button click
        assignBtn.addEventListener('click', function () {
            if (!selectedResponderId) return;
            assignResponder(selectedResponderId);
        });
    }

    function highlightDropdownItem(items) {
        for (var i = 0; i < items.length; i++) {
            if (i === dropdownHighlightIdx) {
                items[i].classList.add('active');
                items[i].scrollIntoView({ block: 'nearest' });
            } else {
                items[i].classList.remove('active');
            }
        }
    }

    function renderResponderDropdown(query) {
        var dropdown = document.getElementById('responderDropdown');
        if (!dropdown) return;

        // Get list of currently assigned (active) responder IDs
        var assignedIds = {};
        if (incidentData && incidentData.assignments) {
            for (var i = 0; i < incidentData.assignments.length; i++) {
                var a = incidentData.assignments[i];
                if (!a.cleared) {
                    assignedIds[a.responder_id] = true;
                }
            }
        }

        // 2026-06-28 (Eric beta) — also surface distance + location
        // freshness in the search dropdown so 'closest medic' is
        // a one-glance answer. Same helper rules as the Available
        // Responders panel.
        var stThresh = (window.STALE_LOCATION_MIN && window.STALE_LOCATION_MIN > 0)
            ? window.STALE_LOCATION_MIN : 30;
        var incLat = incidentData && incidentData.incident && incidentData.incident.lat;
        var incLng = incidentData && incidentData.incident && incidentData.incident.lng;
        var haveIncidentLoc = (typeof incLat === 'number' && typeof incLng === 'number');
        var nowMs = Date.now();
        function _hav(lat1, lng1, lat2, lng2) {
            var R = 6371.0;
            var dLat = (lat2 - lat1) * Math.PI / 180;
            var dLng = (lng2 - lng1) * Math.PI / 180;
            var a = Math.sin(dLat/2) * Math.sin(dLat/2)
                  + Math.cos(lat1*Math.PI/180) * Math.cos(lat2*Math.PI/180)
                  * Math.sin(dLng/2) * Math.sin(dLng/2);
            return 2 * R * Math.asin(Math.sqrt(a));
        }

        // Filter responders + decorate with distance/age
        var matches = [];
        for (var j = 0; j < allResponders.length; j++) {
            var r = allResponders[j];
            if (assignedIds[r.id]) continue;
            var searchStr = (r.name + ' ' + r.handle + ' ' + (r.type_name || '')).toLowerCase();
            if (searchStr.indexOf(query) === -1) continue;

            var rLat = parseFloat(r.lat), rLng = parseFloat(r.lng);
            var haveResp = !isNaN(rLat) && !isNaN(rLng) && (rLat !== 0 || rLng !== 0);
            var dkm = (haveIncidentLoc && haveResp) ? _hav(rLat, rLng, incLat, incLng) : null;
            var ageMin = null, staleMin = null;
            if (haveResp && r.updated) {
                var ms = Date.parse(String(r.updated).replace(' ', 'T') + 'Z');
                if (!isNaN(ms)) {
                    ageMin = Math.round((nowMs - ms) / 60000);
                    if (ageMin > stThresh) staleMin = ageMin;
                }
            }
            matches.push(Object.assign({}, r, {
                _distanceKm: dkm, _ageMin: ageMin, _staleMin: staleMin, _noLocation: !haveResp
            }));
        }

        // Sort: closest first; units without location to the bottom.
        matches.sort(function (x, y) {
            var dx = (typeof x._distanceKm === 'number') ? x._distanceKm : Infinity;
            var dy = (typeof y._distanceKm === 'number') ? y._distanceKm : Infinity;
            if (dx === dy) return 0;
            return dx < dy ? -1 : 1;
        });

        if (matches.length === 0) {
            dropdown.innerHTML = '<div class="list-group-item text-body-secondary py-2">No matching responders</div>';
            dropdown.classList.remove('d-none');
            return;
        }

        // Limit to 10 results
        var html = '';
        var limit = Math.min(matches.length, 10);
        for (var k = 0; k < limit; k++) {
            var resp = matches[k];
            var statusBg = resp.active_assignments > 0 ? 'bg-warning text-dark' : 'bg-success';
            var statusText = resp.active_assignments > 0
                ? 'Assigned (' + resp.active_assignments + ')'
                : (resp.status_name || 'Available');

            // Build the distance/age chip for this row.
            var distChip = '';
            if (resp._noLocation) {
                distChip = '<i class="bi bi-exclamation-triangle-fill text-warning ms-2" '
                         + 'title="No location data for this unit" style="font-size:0.65rem;"></i>';
            } else if (typeof resp._distanceKm === 'number') {
                var staleHtml = (resp._staleMin !== null)
                    ? ' <i class="bi bi-exclamation-triangle-fill text-warning" title="Location ' + resp._staleMin + ' min old"></i>'
                    : '';
                var distLbl2 = (window.formatDistanceKm)
                    ? window.formatDistanceKm(resp._distanceKm)
                    : resp._distanceKm.toFixed(1) + ' km';
                distChip = '<span class="badge bg-body-secondary text-body ms-2 font-monospace" style="font-size:0.6rem;">'
                         + distLbl2 + staleHtml + '</span>';
            } else if (typeof resp._ageMin === 'number') {
                var ageLbl = resp._ageMin < 60 ? resp._ageMin + 'm' : Math.round(resp._ageMin/60) + 'h';
                var ageCls = (resp._staleMin !== null) ? 'bg-warning text-dark' : 'bg-body-secondary text-body';
                // Same clarity fix as the panel — clock icon prefixes the
                // age label so it doesn't look like a unit of distance.
                distChip = '<span class="badge ' + ageCls + ' ms-2" '
                         + 'title="Last GPS fix ' + ageLbl + ' ago. Set incident location to see distance." '
                         + 'style="font-size:0.6rem;">' +
                         '<i class="bi bi-clock-history me-1"></i>' +
                         '<span class="font-monospace">' + ageLbl + '</span>' +
                         '</span>';
            }

            html += '<a href="#" class="list-group-item list-group-item-action py-1 px-2 responder-option"' +
                ' data-responder-id="' + resp.id + '">' +
                '<div class="d-flex justify-content-between align-items-center">' +
                '<span class="fw-semibold">' + escHtml(resp.name) + '</span>' +
                '<span class="d-flex align-items-center">' +
                  distChip +
                  '<span class="badge ' + statusBg + ' ms-2" style="font-size: 0.65rem;">' + escHtml(statusText) + '</span>' +
                '</span>' +
                '</div>' +
                '<small class="text-body-secondary">' +
                escHtml(resp.handle || '') +
                (resp.type_name ? ' · ' + escHtml(resp.type_name) : '') +
                '</small>' +
                '</a>';
        }

        if (matches.length > limit) {
            html += '<div class="list-group-item text-body-secondary py-1 small text-center">' +
                '+ ' + (matches.length - limit) + ' more — refine search</div>';
        }

        dropdown.innerHTML = html;
        dropdown.classList.remove('d-none');

        // Bind click handlers on dropdown items
        var options = dropdown.querySelectorAll('.responder-option');
        for (var m = 0; m < options.length; m++) {
            options[m].addEventListener('click', function (e) {
                e.preventDefault();
                var rid = parseInt(this.getAttribute('data-responder-id'), 10);
                selectResponder(rid);
            });
        }
    }

    function selectResponder(rid) {
        var searchInput = document.getElementById('responderSearch');
        var dropdown = document.getElementById('responderDropdown');
        var assignBtn = document.getElementById('btnAssignResponder');

        // Find the responder name
        for (var i = 0; i < allResponders.length; i++) {
            if (allResponders[i].id === rid) {
                searchInput.value = allResponders[i].handle || allResponders[i].name;
                break;
            }
        }

        selectedResponderId = rid;
        assignBtn.disabled = false;
        dropdown.classList.add('d-none');

        // Focus the assign button so user can hit Enter
        assignBtn.focus();
    }

    // ── API: Assign responder ──
    function assignResponder(responderId) {
        var ticketId = getIncidentId();
        if (!ticketId) return;

        var assignBtn = document.getElementById('btnAssignResponder');
        assignBtn.disabled = true;
        assignBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Assigning...';

        fetch('api/incident-assign.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'assign',
                ticket_id: ticketId,
                responder_id: responderId,
                csrf_token: getCsrfToken()
            })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            assignBtn.innerHTML = '<i class="bi bi-plus-lg me-1"></i>Assign';
            assignBtn.disabled = true;

            if (data.error) {
                showAlert(escHtml(data.error), 'danger');
                return;
            }

            // Clear search
            document.getElementById('responderSearch').value = '';
            selectedResponderId = null;

            showAlert(escHtml(data.message), 'success');
            refreshIncident();
        })
        .catch(function (err) {
            assignBtn.innerHTML = '<i class="bi bi-plus-lg me-1"></i>Assign';
            assignBtn.disabled = true;
            showAlert('Failed to assign responder: ' + escHtml(err.message), 'danger');
        });
    }

    // ── API: Update assignment status ──
    // Phase 25 (2026-06-11) — accepts either a legacy named string
    // ('responding'/'on_scene'/'clear') OR a numeric un_status row id
    // from the new dropdown. The server figures out which.
    //
    // Phase 104f (a beta tester GH #10, 2026-07-02) — if the server rejects
    // with extra_data_required (422), open the situation-page extra-
    // data modal (exposed as window.TCADStatusExtraDataPrompt when
    // app.js loads on incident-detail — hard fallback to a plain
    // prompt() if the modal isn't available) and retry the request
    // with the collected value. This closes the "incident-detail
    // bypasses extra_data" validation gap a beta tester reported.
    function updateAssignmentStatus(assignId, newStatusOrId, extraData) {
        var ticketId = getIncidentId();
        if (!ticketId) return;

        var body = {
            action: 'update_status',
            ticket_id: ticketId,
            assign_id: assignId,
            csrf_token: getCsrfToken()
        };
        if (typeof newStatusOrId === 'number' || /^\d+$/.test(String(newStatusOrId))) {
            body.new_status_id = parseInt(newStatusOrId, 10);
        } else {
            body.new_status = newStatusOrId;
        }
        if (extraData) body.extra_data = extraData;

        fetch('api/incident-assign.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        })
        .then(function (r) {
            return r.json().then(function (data) { return { status: r.status, data: data }; });
        })
        .then(function (res) {
            var data = res.data;
            if (data && data.error === 'extra_data_required' && !extraData) {
                // Look up the status's extra_data_type via the cached
                // un_statuses so we render the right input widget.
                loadUnitStatuses(function (statuses) {
                    var opt = null;
                    if (typeof newStatusOrId === 'number' || /^\d+$/.test(String(newStatusOrId))) {
                        var sid = parseInt(newStatusOrId, 10);
                        for (var i = 0; i < statuses.length; i++) {
                            if (parseInt(statuses[i].id, 10) === sid) { opt = statuses[i]; break; }
                        }
                    }
                    // Two fallback UIs: the reusable modal exposed by
                    // app.js (situation page) if this page loaded it,
                    // else a plain prompt() so the workflow still
                    // completes on incident-detail-only sessions.
                    var edType = (opt && opt.extra_data_type) || 'note';
                    var edLabel = (opt && opt.extra_data_label) || data.label || 'value';
                    if (window.TCADStatusExtraDataPrompt) {
                        window.TCADStatusExtraDataPrompt({
                            type: edType, label: edLabel, status_val: opt ? opt.status_val : ''
                        }, function (val) {
                            if (val === null) return;
                            updateAssignmentStatus(assignId, newStatusOrId, { type: edType, value: val });
                        });
                    } else {
                        var promptMsg = 'This status requires ' + edLabel + '. Enter value:';
                        var val = window.prompt(promptMsg);
                        if (val === null || val === '') return;
                        updateAssignmentStatus(assignId, newStatusOrId, { type: edType, value: val });
                    }
                });
                return;
            }
            if (data && data.error) {
                showAlert(escHtml(data.error), 'danger');
                return;
            }
            refreshIncident();
        })
        .catch(function (err) {
            showAlert('Failed to update status: ' + escHtml(err.message), 'danger');
        });
    }

    // Phase 25 — Fetched once per incident-detail load + cached.
    var unUnitStatuses = null;
    function loadUnitStatuses(cb) {
        if (unUnitStatuses) { cb(unUnitStatuses); return; }
        fetch('api/un-statuses.php', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                unUnitStatuses = data.statuses || [];
                cb(unUnitStatuses);
            })
            .catch(function () { unUnitStatuses = []; cb([]); });
    }

    // ── API: Unassign responder ──
    function unassignResponder(assignId) {
        var ticketId = getIncidentId();
        if (!ticketId) return;

        fetch('api/incident-assign.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'unassign',
                ticket_id: ticketId,
                assign_id: assignId,
                csrf_token: getCsrfToken()
            })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.error) {
                showAlert(escHtml(data.error), 'danger');
                return;
            }
            showAlert(escHtml(data.message), 'info');
            refreshIncident();
        })
        .catch(function (err) {
            showAlert('Failed to unassign: ' + escHtml(err.message), 'danger');
        });
    }

    // ── Note form controls ──
    function initNoteForm() {
        var noteText = document.getElementById('noteText');
        var noteBtn = document.getElementById('btnAddNote');

        if (!noteText || !noteBtn) return;

        // Enable/disable send button based on content
        noteText.addEventListener('input', function () {
            noteBtn.disabled = noteText.value.trim() === '';
        });

        // Enter to send, Shift+Enter for new line
        // PageUp/PageDown scroll the page even when textarea is focused
        noteText.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                if (noteText.value.trim() !== '') {
                    submitNote();
                }
            }
            if (e.key === 'PageUp' || e.key === 'PageDown') {
                e.preventDefault();
                var amount = window.innerHeight * 0.8;
                window.scrollBy(0, e.key === 'PageUp' ? -amount : amount);
            }
        });

        // Button click
        noteBtn.addEventListener('click', function () {
            submitNote();
        });
    }

    function submitNote() {
        var ticketId = getIncidentId();
        var noteText = document.getElementById('noteText');
        var noteBtn = document.getElementById('btnAddNote');
        var note = noteText.value.trim();

        if (!ticketId || note === '') return;

        noteBtn.disabled = true;
        noteBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

        fetch('api/incident-update.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'add_note',
                ticket_id: ticketId,
                note: note,
                csrf_token: getCsrfToken()
            })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            noteBtn.innerHTML = '<i class="bi bi-send"></i>';
            noteBtn.disabled = true;

            if (data.error) {
                showAlert(escHtml(data.error), 'danger');
                return;
            }

            noteText.value = '';
            refreshIncident();
        })
        .catch(function (err) {
            noteBtn.innerHTML = '<i class="bi bi-send"></i>';
            noteBtn.disabled = false;
            showAlert('Failed to add note: ' + escHtml(err.message), 'danger');
        });
    }

    // ── Status change controls ──
    function initStatusControls() {
        var statusBtn = document.getElementById('btnChangeStatus');
        if (!statusBtn) return;

        statusBtn.addEventListener('click', function () {
            changeStatus();
        });
    }

    function syncStatusSelect(inc) {
        var statusSelect = document.getElementById('statusSelect');
        var statusControls = document.getElementById('statusControls');
        if (!statusSelect || !statusControls) return;

        statusSelect.value = String(inc.status);
        statusControls.classList.remove('d-none');
    }

    function changeStatus() {
        var ticketId = getIncidentId();
        var statusSelect = document.getElementById('statusSelect');
        var statusBtn = document.getElementById('btnChangeStatus');
        var newStatus = parseInt(statusSelect.value, 10);

        if (!ticketId) return;

        // Check if status actually changed
        if (incidentData && incidentData.incident.status === newStatus) {
            showAlert('Incident is already in that status.', 'info');
            return;
        }

        // If scheduling, prompt for booked date
        var bookedDate = '';
        if (newStatus === 3) {
            bookedDate = prompt('Enter scheduled date/time (YYYY-MM-DD HH:MM):');
            if (!bookedDate) return;
        }

        // 2026-06-11 — When closing, count active assignments and prompt
        // the dispatcher before doing the auto-cascade. The server-side
        // close path DOES auto-clear assignments + reset unit statuses
        // (see api/incident-update.php), but Eric wanted the explicit
        // "X units still assigned — confirm close" interlock so a
        // dispatcher doesn't close mid-incident by accident.
        if (newStatus === 1 && incidentData &&
            incidentData.responders && incidentData.responders.length > 0) {
            var active = incidentData.responders.filter(function (r) {
                // contract audit 2026-07-07: `clear_time` was a dead
                // fallback (never emitted); `cleared` is the real key.
                return !r.cleared;
            });
            if (active.length > 0) {
                var names = active.map(function (r) {
                    return r.name || r.handle || ('Unit #' + r.id);
                }).join(', ');
                var word = active.length === 1 ? 'unit is' : 'units are';
                if (!confirm(
                    active.length + ' ' + word + ' still assigned to this incident:\n\n' +
                    names + '\n\n' +
                    'Closing will automatically clear their assignments and reset them to Available.\n\n' +
                    'Continue closing?'
                )) {
                    return;
                }
            }
        }

        statusBtn.disabled = true;
        statusBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Updating...';

        var body = {
            action: 'update_status',
            ticket_id: ticketId,
            new_status: newStatus,
            csrf_token: getCsrfToken()
        };
        if (bookedDate) {
            body.booked_date = bookedDate;
        }

        fetch('api/incident-update.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            statusBtn.innerHTML = '<i class="bi bi-arrow-repeat me-1"></i>Update';
            statusBtn.disabled = false;

            if (data.error) {
                showAlert(escHtml(data.error), 'danger');
                return;
            }

            showAlert(escHtml(data.message), 'success');
            refreshIncident();
        })
        .catch(function (err) {
            statusBtn.innerHTML = '<i class="bi bi-arrow-repeat me-1"></i>Update';
            statusBtn.disabled = false;
            showAlert('Failed to change status: ' + escHtml(err.message), 'danger');
        });
    }

    // ── Render: Header ──
    function renderHeader(inc) {
        // Page title. Phase 99m (Eric beta 2026-06-29): when an
        // admin-configured incident_number is present, show it as
        // the primary identifier in the page title — that's the
        // operationally-meaningful number a dispatcher reads to
        // a caller, not the internal database id.
        // Phase 99o: prefix label is admin-configurable ("Incident"
        // / "Case" / "Call" / ...) via window.INCIDENT_NUMBER_LABEL.
        // Phase 99p (Eric beta 2026-06-29) — drop the "(id #N)" suffix.
        // Dispatchers don't care about the internal id; the page URL
        // already carries it for anyone who needs it.
        var label = window.INCIDENT_NUMBER_LABEL || 'Incident';
        var titleId = inc.incident_number || ('#' + inc.id);
        document.getElementById('pageTitle').innerHTML =
            '<i class="bi bi-file-earmark-text text-primary me-2"></i>' + label + ' ' + titleId;

        // Severity badge
        var sevBadge = document.getElementById('severityBadge');
        var sevLabels = ['Low', 'Medium', 'High'];
        sevBadge.textContent = sevLabels[inc.severity] || 'Unknown';
        sevBadge.style.backgroundColor = inc.severity_color;
        sevBadge.style.color = inc.severity >= 2 ? '#fff' : '#000';

        // Status badge
        var statusBadge = document.getElementById('statusBadge');
        statusBadge.textContent = inc.status_text;
        var statusClasses = { 1: 'bg-secondary', 2: 'bg-success', 3: 'bg-info' };
        statusBadge.className = 'badge ' + (statusClasses[inc.status] || 'bg-secondary');

        // Type badge
        var typeBadge = document.getElementById('typeBadge');
        typeBadge.textContent = inc.type_name + (inc.type_group ? ' (' + inc.type_group + ')' : '');

        // Scope (title)
        document.getElementById('incidentScope').textContent = inc.scope;

        // Meta line. Phase 99p — same label + number, no internal id.
        var meta = label + ' ' + (inc.incident_number || ('#' + inc.id));
        if (inc.created) meta += ' | Created: ' + formatDateTime(inc.created);
        if (inc.updated) meta += ' | Updated: ' + formatDateTime(inc.updated);
        if (inc.created_by_name) meta += ' | By: ' + inc.created_by_name;
        document.getElementById('incidentMeta').textContent = meta;
    }

    // ── Render: Description ──
    function renderDescription(inc) {
        document.getElementById('incidentDesc').textContent = inc.description || '(No description)';

        if (inc.affected && inc.affected.trim()) {
            document.getElementById('incidentAffected').classList.remove('d-none');
            document.getElementById('affectedText').textContent = inc.affected;
        }
    }

    // ── Render: Location ──
    function renderLocation(inc) {
        document.getElementById('locStreet').textContent = inc.street || '—';
        document.getElementById('locCity').textContent = inc.city || '—';
        document.getElementById('locState').textContent = inc.state || '—';

        if (inc.address_about && inc.address_about.trim()) {
            document.getElementById('locCrossCol').classList.remove('d-none');
            document.getElementById('locCross').textContent = inc.address_about;
        }
        if (inc.to_address && inc.to_address.trim()) {
            document.getElementById('locDestCol').classList.remove('d-none');
            document.getElementById('locDest').textContent = inc.to_address;
        }

        if (inc.lat && inc.lng) {
            document.getElementById('locLat').textContent = parseFloat(inc.lat).toFixed(6);
            document.getElementById('locLng').textContent = parseFloat(inc.lng).toFixed(6);
        } else {
            document.getElementById('locCoordsRow').classList.add('d-none');
        }
    }

    // ── Render: Contact ──
    function renderContact(inc) {
        document.getElementById('contactName').textContent = inc.contact || '—';
        document.getElementById('contactPhone').textContent = inc.phone || '—';
        document.getElementById('contact911').textContent = inc.nine_one_one || '—';

        // Auto-expand if any contact info exists
        if (inc.contact || inc.phone || inc.nine_one_one) {
            var collapse = document.getElementById('collapseContact');
            if (collapse) bootstrap.Collapse.getOrCreateInstance(collapse).show();
        }
    }

    // ── Render: Facilities ──
    function renderFacilities(inc) {
        var facName = inc.facility_name || '—';
        if (inc.facility_city) facName += ' (' + inc.facility_city + ')';
        document.getElementById('facIncident').textContent = facName;

        var recName = inc.rec_facility_name || '—';
        if (inc.rec_facility_city) recName += ' (' + inc.rec_facility_city + ')';
        document.getElementById('facReceiving').textContent = recName;

        // 2026-06-26 — Keep the card visible even when no facilities are set
        // so the edit pencil is always reachable (a beta tester beta report:
        // dispatcher had no way to attach facilities after creation).
        document.getElementById('facilitiesCard').classList.remove('d-none');
    }

    // ── Render: Time & Status ──
    function renderTimeStatus(inc) {
        document.getElementById('timeStatus').innerHTML =
            '<span class="badge ' + (inc.status === 2 ? 'bg-success' : inc.status === 3 ? 'bg-info' : 'bg-secondary') + '">' +
            escHtml(inc.status_text) + '</span>';
        document.getElementById('timeProbStart').textContent = inc.problemstart ? formatDateTime(inc.problemstart) : '—';
        document.getElementById('timeProbEnd').textContent = inc.problemend ? formatDateTime(inc.problemend) : '—';

        if (inc.booked_date) {
            document.getElementById('timeBookedCol').classList.remove('d-none');
            document.getElementById('timeBooked').textContent = formatDateTime(inc.booked_date);
        }
    }

    // ── Render: Additional Details ──
    function renderAdditional(inc) {
        var text = inc.comments || '';
        // 2026-06-26 — Always keep the card visible so the edit pencil is
        // reachable even when the incident has no comments yet. Previously
        // an empty incident hid the entire section with no way back in.
        document.getElementById('additionalCard').classList.remove('d-none');
        if (text.trim()) {
            document.getElementById('additionalComments').textContent = text;
        } else {
            document.getElementById('additionalComments').textContent = '(No additional details)';
        }
    }

    // ── Render: Protocol ──
    function renderProtocol(inc) {
        if (inc.protocol && inc.protocol.trim()) {
            document.getElementById('protocolPanel').classList.remove('d-none');
            document.getElementById('protocolContent').innerHTML = formatProtocol(inc.protocol);
        }
    }

    // ── Render: Assignments (with action buttons + on-scene timer) ──
    function renderAssignments(assignments) {
        var container = document.getElementById('assignmentsList');
        var badge = document.getElementById('assignedCount');
        var active = 0;

        // Clear previous timer interval
        if (sceneTimerInterval) {
            clearInterval(sceneTimerInterval);
            sceneTimerInterval = null;
        }

        if (!assignments || assignments.length === 0) {
            container.innerHTML = '<div class="text-center text-body-secondary py-3 small">' +
                '<i class="bi bi-people me-1"></i>No responders assigned</div>';
            badge.textContent = '0';
            badge.className = 'badge bg-secondary ms-auto';
            return;
        }

        // 2026-06-28 (Eric beta request) — sort assignments by distance
        // from the incident, with units lacking location data sorted to
        // the bottom. Mutate-safe: clone the array first.
        var sortable = assignments.slice();
        sortable.sort(function (x, y) {
            var dx = (typeof x.distance_km === 'number') ? x.distance_km : Infinity;
            var dy = (typeof y.distance_km === 'number') ? y.distance_km : Infinity;
            if (dx === dy) return 0;
            return dx < dy ? -1 : 1;
        });
        assignments = sortable;

        // Stale-threshold: any responder whose `updated` timestamp is
        // older than this many minutes shows a yellow ⚠ icon in the
        // distance column. Default 30 min; per-install override via
        // settings.stale_location_threshold_minutes (exposed by
        // inc/navbar.php as window.STALE_LOCATION_MIN).
        var STALE_THRESHOLD_MIN = (window.STALE_LOCATION_MIN && window.STALE_LOCATION_MIN > 0)
            ? window.STALE_LOCATION_MIN : 30;
        var nowMs = Date.now();

        var html = '<div class="table-responsive"><table class="table table-sm table-hover mb-0 small">' +
            '<thead><tr>' +
            '<th>Unit</th><th>Status</th>' +
            '<th title="Distance from incident">Dist</th>' +
            '<th>Disp</th><th>Resp</th><th>Scene</th><th>Clr</th>' +
            '<th title="On-scene elapsed time"><i class="bi bi-stopwatch me-1"></i>Timer</th>' +
            '<th title="Destination hospital for this unit (per-unit receiving facility). In a mass-casualty incident each unit can go to a different facility."><i class="bi bi-hospital me-1"></i>Dest</th>' +
            '<th class="text-end">Actions</th>' +
            '</tr></thead><tbody>';

        var hasOnScene = false;

        for (var i = 0; i < assignments.length; i++) {
            var a = assignments[i];
            // Compute the distance / freshness rendering for this row.
            // distCell: "1.2 km" with optional ⚠ if stale, or "—" with
            // ⚠ if no location data at all.
            var distCell;
            if (typeof a.distance_km !== 'number' || a.distance_km === null) {
                distCell = '<span class="text-warning" title="No location data for this unit">' +
                    '<i class="bi bi-geo-alt-fill"></i> —</span>';
            } else {
                var staleHtml = '';
                if (a.responder_updated) {
                    var updMs = Date.parse(a.responder_updated.replace(' ', 'T') + 'Z');
                    if (!isNaN(updMs)) {
                        var ageMin = (nowMs - updMs) / 60000;
                        if (ageMin > STALE_THRESHOLD_MIN) {
                            staleHtml = ' <i class="bi bi-exclamation-triangle-fill text-warning" ' +
                                'title="Location is ' + Math.round(ageMin) + ' min old"></i>';
                        }
                    }
                }
                var distLblA = (window.formatDistanceKm)
                    ? window.formatDistanceKm(a.distance_km)
                    : a.distance_km.toFixed(1) + ' km';
                distCell = '<span class="text-nowrap">' + distLblA + staleHtml + '</span>';
            }
            a._distCell = distCell;
            var isCleared = a.cleared;
            var rowClass = isCleared ? 'assignment-cleared' : '';

            if (!isCleared) active++;

            // Determine current lifecycle state
            var currentState = 'Dispatched';
            var stateClass = 'text-warning';
            if (isCleared) {
                currentState = 'Cleared';
                stateClass = 'text-body-secondary';
            } else if (a.on_scene) {
                currentState = 'On Scene';
                stateClass = 'text-success';
            } else if (a.responding) {
                currentState = 'Responding';
                stateClass = 'text-info';
            }

            // Phase 25 (2026-06-11) — dropdown of all admin-configured
            // statuses replaces the hardcoded 3 buttons. Populated after
            // the page loads via api/un-statuses.php; rendered as a
            // <select> with each option carrying the configured color.
            // The select's value is the un_status id; the change
            // handler routes to updateAssignmentStatus(assignId, id).
            var actionHtml = '';
            if (!isCleared) {
                // Phase 33B (2026-06-12) — pair the dropdown with a small
                // Remove button that calls the existing unassign action.
                // Eric: "I'm also looking to understand how we remove an
                // assignment from an incident. I assume certain status
                // changes would remove the assignment?"
                // Yes — picking a status with incident_action='clear'
                // ends the assignment normally. Remove is for the
                // "this was added in error" case.
                actionHtml = '<div class="d-flex gap-1 justify-content-end">' +
                    '<select class="form-select form-select-sm assign-status-pick" ' +
                    'data-assign-id="' + a.id + '" data-current="' + (a.responder_un_status_id || '') + '" ' +
                    'style="min-width:140px;font-size:0.75rem;">' +
                    '<option value="">— Set status…</option>' +
                    '</select>' +
                    '<button type="button" class="btn btn-sm btn-outline-secondary assign-remove" ' +
                    'data-assign-id="' + a.id + '" data-unit="' + escHtml(a.responder_handle || a.responder_name) + '" ' +
                    'title="Remove this unit from the incident (assignment added in error). For normal completion use a status whose Incident Action is Clear.">' +
                    '<i class="bi bi-x-lg"></i>' +
                    '</button>' +
                    '</div>';
            } else {
                actionHtml = '<span class="text-body-tertiary small">Cleared</span>';
            }

            // On-scene timer cell
            var timerHtml = '<span class="text-body-tertiary">—</span>';
            if (a.on_scene && !isCleared) {
                hasOnScene = true;
                timerHtml = '<span class="scene-timer font-monospace fw-semibold" data-scene-time="' +
                    escHtml(a.on_scene) + '">--:--</span>';
            } else if (a.on_scene && isCleared && a.clear) {
                // Show final elapsed for cleared units
                timerHtml = '<span class="text-body-secondary font-monospace small">' +
                    calcElapsed(a.on_scene, a.clear) + '</span>';
            }

            var unitLabel = escHtml(a.responder_handle || a.responder_name);

            // Phase 116b (GH #85) — crew: the personnel assigned to this unit.
            // Dispatching a unit puts its crew on the incident (accountability),
            // shown as a compact line under the unit name. Role in parens.
            var crewHtml = '';
            if (a.crew && a.crew.length > 0) {
                var crewNames = [];
                for (var cx = 0; cx < a.crew.length; cx++) {
                    var cm = a.crew[cx];
                    var roleTxt = cm.role ? ' (' + escHtml(cm.role) + ')' : '';
                    crewNames.push(escHtml(cm.name) + roleTxt);
                }
                crewHtml = '<div class="small text-body-secondary fw-normal mt-1" ' +
                    'title="Crew assigned to this unit — on the incident for accountability">' +
                    '<i class="bi bi-people-fill me-1"></i>' + crewNames.join(', ') + '</div>';
            }

            // Phase 116 / GH #20 — per-unit Destination (receiving facility).
            // The dropdown shows each unit's EFFECTIVE destination: its own
            // per-unit override if set, otherwise the incident's Receiving
            // Facility (the call default). This makes it visible that, by
            // default, every unit delivers to the call's facility — and lets a
            // dispatcher send individual units elsewhere, without the
            // incident-vs-per-unit confusion that caused GH #20. Inherited
            // (default) values render italic so an explicit per-unit choice
            // reads differently from the inherited default.
            var incFacId  = (incidentData && incidentData.incident && incidentData.incident.rec_facility)
                ? (parseInt(incidentData.incident.rec_facility, 10) || 0) : 0;
            var ownDest   = a.rec_facility_id ? (parseInt(a.rec_facility_id, 10) || 0) : 0;
            var effDest   = ownDest > 0 ? ownDest : incFacId;
            var inherited = (ownDest === 0 && incFacId > 0);
            var destHtml;
            if (isCleared) {
                var clearedName = a.rec_facility_name || (incFacId > 0 && incidentData.incident.rec_facility_name) || '';
                destHtml = clearedName
                    ? '<span class="text-body-secondary small">' + escHtml(clearedName) + '</span>'
                    : '<span class="text-body-tertiary">—</span>';
            } else {
                destHtml = '<select class="form-select form-select-sm assign-dest-pick' + (inherited ? ' fst-italic' : '') + '" ' +
                    'data-assign-id="' + a.id + '" data-current="' + effDest + '" ' +
                    'title="' + (inherited
                        ? 'Defaults to the call&#39;s receiving facility — change to send this unit to a different facility'
                        : 'This unit&#39;s destination facility') + '" ' +
                    'style="min-width:120px;font-size:0.72rem;">' +
                    '<option value="0">— Dest…</option>' +
                    '</select>';
            }

            html += '<tr class="' + rowClass + '" data-assign-id="' + a.id + '">' +
                '<td class="fw-semibold" title="' + escHtml(a.responder_name) + '">' + unitLabel + crewHtml + '</td>' +
                '<td><span class="' + stateClass + ' fw-semibold">' + escHtml(currentState) + '</span></td>' +
                '<td>' + (a._distCell || '<span class="text-body-tertiary">—</span>') + '</td>' +
                '<td>' + formatTime(a.dispatched) + '</td>' +
                '<td>' + formatTime(a.responding) + '</td>' +
                '<td>' + formatTime(a.on_scene) + '</td>' +
                '<td>' + formatTime(a.clear) + '</td>' +
                '<td>' + timerHtml + '</td>' +
                '<td>' + destHtml + '</td>' +
                '<td class="text-end text-nowrap">' + actionHtml + '</td>' +
                '</tr>';
        }

        html += '</tbody></table></div>';
        container.innerHTML = html;

        badge.textContent = active + ' active';
        badge.className = 'badge ms-auto ' + (active > 0 ? 'bg-success' : 'bg-secondary');

        // Start live timer updates if any unit is on scene
        if (hasOnScene) {
            updateSceneTimers();
            sceneTimerInterval = setInterval(updateSceneTimers, 1000);
        }

        // Phase 25 — populate the status dropdowns and bind change handler.
        var picks = container.querySelectorAll('.assign-status-pick');
        if (picks.length > 0) {
            loadUnitStatuses(function (statuses) {
                picks.forEach(function (sel) {
                    var current = sel.getAttribute('data-current') || '';
                    var html = '<option value="">— Set status…</option>';
                    statuses.forEach(function (s) {
                        if (s.hide === 'y' || s.hide === '1') return;
                        var style = '';
                        if (s.bg_color) style += 'background:' + s.bg_color + ';';
                        if (s.text_color) style += 'color:' + s.text_color + ';';
                        var selected = (String(s.id) === String(current)) ? ' selected' : '';
                        html += '<option value="' + s.id + '" style="' + style + '"' + selected + '>' +
                                escHtml(s.status_val) + '</option>';
                    });
                    sel.innerHTML = html;
                    sel.addEventListener('change', function () {
                        if (!this.value) return;
                        var assignId = parseInt(this.getAttribute('data-assign-id'), 10);
                        var statusId = parseInt(this.value, 10);
                        this.disabled = true;
                        updateAssignmentStatus(assignId, statusId);
                    });
                });
            });
        }

        // Phase 33B (2026-06-12) — Remove button on each assignment row.
        var removeBtns = container.querySelectorAll('.assign-remove');
        for (var k = 0; k < removeBtns.length; k++) {
            removeBtns[k].addEventListener('click', function () {
                var assignId = parseInt(this.getAttribute('data-assign-id'), 10);
                var unit     = this.getAttribute('data-unit') || 'this unit';
                if (!confirm('Remove ' + unit + ' from this incident? Use this for assignments added in error. For normal completion, set the status to one whose Incident Action is Clear.')) return;
                this.disabled = true;
                fetch('api/incident-assign.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action:     'unassign',
                        ticket_id:  ticketId,
                        assign_id:  assignId,
                        csrf_token: getCsrfToken()
                    })
                }).then(function (r) { return r.json(); }).then(function (data) {
                    if (data && data.error) { showAlert(data.error, 'danger'); return; }
                    showAlert(data.message || 'Unit removed.', 'info');
                    loadAssignments();
                });
            });
        }

        // Phase 116 — populate + bind the per-unit Destination (receiving
        // facility) selectors. Each active unit can be sent to its OWN
        // facility (mass-casualty: different units to different hospitals).
        // Writes assigns.rec_facility_id via api/incident-assign.php
        // action=set_rec_facility; bed automation then credits the correct
        // facility for that unit.
        var destPicks = container.querySelectorAll('.assign-dest-pick');
        if (destPicks.length > 0) {
            loadFacilitiesList(function (facs) {
                for (var d = 0; d < destPicks.length; d++) {
                    (function (sel) {
                        var current = sel.getAttribute('data-current') || '0';
                        var oh = '<option value="0">— Dest…</option>';
                        for (var fi = 0; fi < facs.length; fi++) {
                            var f = facs[fi];
                            var lbl = f.name || ('Facility #' + f.id);
                            var selAttr = (String(f.id) === String(current)) ? ' selected' : '';
                            oh += '<option value="' + f.id + '"' + selAttr + '>' + escHtml(lbl) + '</option>';
                        }
                        sel.innerHTML = oh;
                        sel.addEventListener('change', function () {
                            var assignId = parseInt(this.getAttribute('data-assign-id'), 10);
                            var facId = parseInt(this.value, 10) || 0;
                            var selfRef = this;
                            selfRef.disabled = true;
                            fetch('api/incident-assign.php', {
                                method: 'POST',
                                credentials: 'same-origin',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({
                                    action:      'set_rec_facility',
                                    ticket_id:   ticketId,
                                    assign_id:   assignId,
                                    facility_id: facId,
                                    csrf_token:  getCsrfToken()
                                })
                            }).then(function (r) { return r.json(); }).then(function (data) {
                                selfRef.disabled = false;
                                if (data && data.error) { showAlert(data.error, 'danger'); return; }
                                selfRef.setAttribute('data-current', String(facId));
                                showAlert(data.message || 'Destination updated.', 'info');
                            }).catch(function () {
                                selfRef.disabled = false;
                                showAlert('Failed to set destination.', 'danger');
                            });
                        });
                    })(destPicks[d]);
                }
            });
        }
    }

    // ── On-scene timer helpers ──
    function updateSceneTimers() {
        var timers = document.querySelectorAll('.scene-timer');
        var now = new Date();
        for (var i = 0; i < timers.length; i++) {
            var raw = timers[i].getAttribute('data-scene-time');
            if (!raw) continue;
            var sceneDate = new Date(raw.replace(' ', 'T'));
            if (isNaN(sceneDate.getTime())) continue;
            var diffMs = now - sceneDate;
            if (diffMs < 0) diffMs = 0;
            var totalSec = Math.floor(diffMs / 1000);
            var hours = Math.floor(totalSec / 3600);
            var mins = Math.floor((totalSec % 3600) / 60);
            var secs = totalSec % 60;

            var display;
            if (hours > 0) {
                display = hours + ':' + (mins < 10 ? '0' : '') + mins + ':' + (secs < 10 ? '0' : '') + secs;
            } else {
                display = mins + ':' + (secs < 10 ? '0' : '') + secs;
            }
            timers[i].textContent = display;

            // Color thresholds
            var totalMins = Math.floor(totalSec / 60);
            if (totalMins >= SCENE_DANGER_MINUTES) {
                timers[i].className = 'scene-timer font-monospace fw-semibold text-danger';
                timers[i].title = 'On scene over ' + SCENE_DANGER_MINUTES + ' minutes';
            } else if (totalMins >= SCENE_WARN_MINUTES) {
                timers[i].className = 'scene-timer font-monospace fw-semibold text-warning';
                timers[i].title = 'On scene over ' + SCENE_WARN_MINUTES + ' minutes';
            } else {
                timers[i].className = 'scene-timer font-monospace fw-semibold text-success';
                timers[i].title = 'On scene ' + totalMins + ' min';
            }
        }
    }

    function calcElapsed(startStr, endStr) {
        if (!startStr || !endStr) return '—';
        var start = new Date(startStr.replace(' ', 'T'));
        var end = new Date(endStr.replace(' ', 'T'));
        if (isNaN(start.getTime()) || isNaN(end.getTime())) return '—';
        var diffMs = end - start;
        if (diffMs < 0) diffMs = 0;
        var totalSec = Math.floor(diffMs / 1000);
        var hours = Math.floor(totalSec / 3600);
        var mins = Math.floor((totalSec % 3600) / 60);
        var secs = totalSec % 60;
        if (hours > 0) {
            return hours + ':' + (mins < 10 ? '0' : '') + mins + ':' + (secs < 10 ? '0' : '') + secs;
        }
        return mins + ':' + (secs < 10 ? '0' : '') + secs;
    }

    // ── Render: Actions (Activity Log) ──
    function renderActions(actions) {
        var container = document.getElementById('actionsList');
        var badge = document.getElementById('actionsCount');

        badge.textContent = actions.length;

        if (!actions || actions.length === 0) {
            container.innerHTML = '<div class="text-center text-body-secondary py-3 small">' +
                '<i class="bi bi-list-ul me-1"></i>No activity recorded</div>';
            return;
        }

        var html = '<div class="list-group list-group-flush">';
        for (var i = 0; i < actions.length; i++) {
            var ac = actions[i];

            // Color-code by type
            var borderColor = 'var(--bs-secondary)';
            var icon = 'bi-chat-left-text';
            if (ac.action_type === 100) {
                borderColor = 'var(--bs-success)';
                icon = 'bi-plus-circle';
            } else if (ac.action_type >= 10 && ac.action_type < 20) {
                borderColor = 'var(--bs-warning)';
                icon = 'bi-arrow-repeat';
            } else if (ac.action_type >= 20 && ac.action_type < 30) {
                borderColor = 'var(--bs-info)';
                icon = 'bi-people';
            }

            html += '<div class="list-group-item py-2 px-3 action-entry" style="border-left: 3px solid ' + borderColor + ';">' +
                '<div class="d-flex justify-content-between">' +
                '<span class="small fw-semibold"><i class="bi ' + icon + ' me-1"></i>' + escHtml(ac.description) + '</span>' +
                '<small class="text-body-secondary text-nowrap ms-2">' + formatDateTime(ac.date) + '</small>' +
                '</div>' +
                (ac.user_name ? '<small class="text-body-secondary">' + escHtml(ac.user_name) + '</small>' : '') +
                '</div>';
        }
        html += '</div>';
        container.innerHTML = html;
    }

    // ── Map ──
    function initMap(inc) {
        var container = document.getElementById('detailMap');
        if (!container || typeof L === 'undefined') return;

        var hasCoords = inc.lat && inc.lng && (inc.lat !== 0 || inc.lng !== 0);

        if (hasCoords) {
            map = L.map('detailMap', { zoomControl: true }).setView([inc.lat, inc.lng], 15);
        } else {
            // No coords — fetch defaults
            fetch('api/map-config.php')
                .then(function (r) { return r.json(); })
                .then(function (cfg) {
                    map = L.map('detailMap', { zoomControl: true })
                        .setView([cfg.def_lat || 39.8283, cfg.def_lng || -98.5795], cfg.def_zoom || 5);
                    var bl = null;
                    if (window.MapPrefs) {
                        bl = window.MapPrefs.addDefaultBasemap(map);
                        // Eric 2026-07-03 — mirror unit-detail/unit-edit
                        // and enable includeMarkupOverlays so the
                        // dispatcher sees custom map-overlay categories
                        // ("Summer Fet", "Race Route", "Precincts",
                        // etc.) on the incident-detail map, not just on
                        // the dashboard + situation view.
                        window.MapPrefs.addLayerControl(map, {
                            currentBase: bl,
                            includeMarkupOverlays: true
                        });
                    } else {
                        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '&copy; OSM', maxZoom: 19 }).addTo(map);
                    }
                    setTimeout(function () { map.invalidateSize(); }, 200);
                })
                .catch(function () {});
            return;
        }

        var baseLayer = null;
        if (window.MapPrefs) {
            baseLayer = window.MapPrefs.addDefaultBasemap(map);
            window.MapPrefs.addLayerControl(map, {
                currentBase: baseLayer,
                includeMarkupOverlays: true
            });
        } else {
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '&copy; OSM', maxZoom: 19 }).addTo(map);
        }

        // Incident marker
        marker = L.marker([inc.lat, inc.lng]).addTo(map);
        marker.bindPopup(
            '<strong>' + escHtml(inc.scope) + '</strong><br>' +
            '<small>' + escHtml(inc.street || '') + (inc.city ? ', ' + escHtml(inc.city) : '') + '</small>'
        );

        // Add facility markers if they have coords
        if (inc.facility_lat && inc.facility_lng) {
            L.circleMarker([inc.facility_lat, inc.facility_lng], {
                radius: 6, color: '#0d6efd', fillColor: '#0d6efd', fillOpacity: 0.8
            }).addTo(map).bindPopup('<small><strong>Facility:</strong> ' + escHtml(inc.facility_name || '') + '</small>');
        }
        if (inc.rec_facility_lat && inc.rec_facility_lng) {
            L.circleMarker([inc.rec_facility_lat, inc.rec_facility_lng], {
                radius: 6, color: '#198754', fillColor: '#198754', fillOpacity: 0.8
            }).addTo(map).bindPopup('<small><strong>Receiving:</strong> ' + escHtml(inc.rec_facility_name || '') + '</small>');
        }

        setTimeout(function () { map.invalidateSize(); }, 200);
    }

    // ── Utilities ──
    function formatDateTime(dt) {
        if (!dt) return '—';
        // Remove seconds for compact display
        var d = new Date(dt.replace(' ', 'T'));
        if (isNaN(d.getTime())) return dt;
        var month = ('0' + (d.getMonth() + 1)).slice(-2);
        var day = ('0' + d.getDate()).slice(-2);
        var hours = ('0' + d.getHours()).slice(-2);
        var mins = ('0' + d.getMinutes()).slice(-2);
        return month + '/' + day + '/' + d.getFullYear() + ' ' + hours + ':' + mins;
    }

    function formatTime(dt) {
        if (!dt) return '<span class="text-body-tertiary">—</span>';
        var d = new Date(dt.replace(' ', 'T'));
        if (isNaN(d.getTime())) return dt;
        var hours = ('0' + d.getHours()).slice(-2);
        var mins = ('0' + d.getMinutes()).slice(-2);
        return hours + ':' + mins;
    }

    function formatProtocol(text) {
        if (!text) return '';
        var safe = escHtml(text);
        safe = safe.replace(/\n/g, '<br>');
        safe = safe.replace(/(^|\<br\>)(\d+\.)/g, '$1<strong>$2</strong>');
        return safe;
    }

    function showAlert(message, type) {
        var area = document.getElementById('alertArea');
        area.innerHTML =
            '<div class="alert alert-' + type + ' alert-dismissible fade show" role="alert">' +
            message +
            '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>' +
            '</div>';
        area.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function escHtml(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    // ── Inline Editing ──────────────────────────────────────────

    // Show edit pencil buttons once data is loaded
    function initEditButtons() {
        var btns = document.querySelectorAll('.edit-section-btn');
        for (var i = 0; i < btns.length; i++) {
            btns[i].classList.remove('d-none');
            btns[i].addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation(); // Don't toggle collapse
                enterEditMode(this.getAttribute('data-section'));
            });
        }

        // Save button handlers
        var saveBtns = document.querySelectorAll('.edit-save-btn');
        for (var j = 0; j < saveBtns.length; j++) {
            saveBtns[j].addEventListener('click', function () {
                saveSection(this.getAttribute('data-section'));
            });
        }

        // Cancel button handlers
        var cancelBtns = document.querySelectorAll('.edit-cancel-btn');
        for (var k = 0; k < cancelBtns.length; k++) {
            cancelBtns[k].addEventListener('click', function () {
                exitEditMode(this.getAttribute('data-section'));
            });
        }

        // Check URL for ?tab=edit — auto-enter edit on description
        var params = new URLSearchParams(window.location.search);
        if (params.get('tab') === 'edit') {
            enterEditMode('description');
        }
    }

    function enterEditMode(section) {
        var inc = incidentData ? incidentData.incident : {};

        if (section === 'description') {
            document.getElementById('editScope').value = inc.scope || '';
            document.getElementById('editDescription').value = inc.description || '';
            document.getElementById('editAffected').value = inc.affected || '';
            document.getElementById('descDisplay').classList.add('d-none');
            document.getElementById('descEdit').classList.remove('d-none');
            document.getElementById('editScope').focus();
            // Ensure the collapse is open
            var collapse = document.getElementById('collapseDesc');
            if (collapse && !collapse.classList.contains('show')) {
                bootstrap.Collapse.getOrCreateInstance(collapse).show();
            }
        } else if (section === 'location') {
            document.getElementById('editStreet').value = inc.street || '';
            document.getElementById('editCity').value = inc.city || '';
            document.getElementById('editState').value = inc.state || '';
            // 2026-06-26 — Cross St / Area + Destination Address. The fields
            // map to ticket.address_about and ticket.to_address respectively.
            var elAbout = document.getElementById('editAddressAbout');
            var elToAddr = document.getElementById('editToAddress');
            if (elAbout) elAbout.value = inc.address_about || '';
            if (elToAddr) elToAddr.value = inc.to_address || '';
            // 2026-06-28 (Eric beta SAR) — populate lat/lng fields.
            var elLat = document.getElementById('editLat');
            var elLng = document.getElementById('editLng');
            if (elLat) elLat.value = (typeof inc.lat === 'number') ? inc.lat.toFixed(6) : '';
            if (elLng) elLng.value = (typeof inc.lng === 'number') ? inc.lng.toFixed(6) : '';
            document.getElementById('locDisplay').classList.add('d-none');
            document.getElementById('locEdit').classList.remove('d-none');
            document.getElementById('editStreet').focus();
            var locCollapse = document.getElementById('collapseLoc');
            if (locCollapse && !locCollapse.classList.contains('show')) {
                bootstrap.Collapse.getOrCreateInstance(locCollapse).show();
            }
            // 2026-06-28 — wire the Lookup button + map-click pin-drop
            // (idempotent — guarded by dataset flags). Map clicks only
            // surface the "Update Location?" popup when the location
            // edit form is currently visible.
            bindLookupButton();
            bindMapClickForPinDrop();
        } else if (section === 'facilities') {
            // 2026-06-26 — facilities edit. Populate the two selects from
            // api/facilities.php (cached after first call), then preselect
            // the incident's current facility / rec_facility.
            populateFacilityEditSelects(function () {
                var facSel = document.getElementById('editFacility');
                var recSel = document.getElementById('editRecFacility');
                if (facSel) facSel.value = String(inc.facility || 0);
                if (recSel) recSel.value = String(inc.rec_facility || 0);
            });
            document.getElementById('facDisplay').classList.add('d-none');
            document.getElementById('facEdit').classList.remove('d-none');
            var facCollapse = document.getElementById('collapseFac');
            if (facCollapse && !facCollapse.classList.contains('show')) {
                // Sonar js:S1848 (2026-07-03): the old
                // `new bootstrap.Collapse(el, { toggle: true })` pattern
                // relied on constructor side-effects and discarded the
                // instance, which also risks a second instance being
                // created if the element already had one attached. Use
                // getOrCreateInstance so subsequent shows reuse the same
                // Collapse object.
                bootstrap.Collapse.getOrCreateInstance(facCollapse).show();
            }
        } else if (section === 'contact') {
            document.getElementById('editContact').value = inc.contact || '';
            document.getElementById('editPhone').value = inc.phone || '';
            document.getElementById('editNineOneOne').value = inc.nine_one_one || '';
            document.getElementById('contactDisplay').classList.add('d-none');
            document.getElementById('contactEdit').classList.remove('d-none');
            document.getElementById('editContact').focus();
            var contactCollapse = document.getElementById('collapseContact');
            if (contactCollapse && !contactCollapse.classList.contains('show')) {
                bootstrap.Collapse.getOrCreateInstance(contactCollapse).show();
            }
        } else if (section === 'additional') {
            document.getElementById('editComments').value = inc.comments || '';
            document.getElementById('additionalDisplay').classList.add('d-none');
            document.getElementById('additionalEdit').classList.remove('d-none');
            document.getElementById('editComments').focus();
            // Show the card if it was hidden (no comments)
            document.getElementById('additionalCard').classList.remove('d-none');
            var addCollapse = document.getElementById('collapseAdditional');
            if (addCollapse && !addCollapse.classList.contains('show')) {
                bootstrap.Collapse.getOrCreateInstance(addCollapse).show();
            }
        }
    }

    // 2026-06-28 (Eric beta SAR) — location-edit map interaction.
    //
    // Two entry points to set/update the incident's lat/lng:
    //   1. Lookup button — forward-geocode the typed street/city/state
    //      via Nominatim, drop the marker on the result.
    //   2. Click on the map — only fires when the location edit form
    //      is visible. Opens a popup at the click location with
    //      "Update Location" / "Cancel" buttons.
    //
    // Both paths reverse-geocode after placing the marker to pre-fill
    // any EMPTY street/city/state fields (never overwrites typed input).

    function _isLocationEditOpen() {
        var edit = document.getElementById('locEdit');
        return edit && !edit.classList.contains('d-none');
    }

    function bindLookupButton() {
        var btn = document.getElementById('btnLookupAddress');
        if (!btn || btn.dataset.bound === '1') return;
        btn.dataset.bound = '1';
        btn.addEventListener('click', function () {
            if (!map) {
                showAlert('Map is not initialized yet.', 'warning');
                return;
            }
            var elStreet = document.getElementById('editStreet');
            var elCity   = document.getElementById('editCity');
            var elState  = document.getElementById('editState');
            var street = (elStreet && elStreet.value || '').trim();
            var city   = (elCity   && elCity.value   || '').trim();
            var state  = (elState  && elState.value  || '').trim();
            if (!street && !city) {
                showAlert('Type a street or city above first, then click Lookup.', 'warning');
                if (elStreet) elStreet.focus();
                return;
            }
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Looking up...';
            // Build a Nominatim forward-geocode query. Add ', USA' to
            // bias to US results when state looks like a US 2-letter code.
            var parts = [];
            if (street) parts.push(street);
            if (city)   parts.push(city);
            if (state)  parts.push(state);
            var q = parts.join(', ');
            if (state && state.length <= 3) q += ', USA';
            var url = 'https://nominatim.openstreetmap.org/search?format=json&addressdetails=1&limit=1&q=' +
                      encodeURIComponent(q);
            fetch(url)
                .then(function (r) { return r.json(); })
                .then(function (results) {
                    if (!results || !results.length) {
                        showAlert('No match found for "' + q + '". Try a simpler query or click the map to drop a pin manually.', 'warning');
                        return;
                    }
                    var hit = results[0];
                    var lat = parseFloat(hit.lat);
                    var lng = parseFloat(hit.lon);
                    if (isNaN(lat) || isNaN(lng)) return;
                    _placePinAt(lat, lng, /*reverseGeocode*/ false);
                    map.setView([lat, lng], 16, { animate: true });
                    // Surface what Nominatim matched so the dispatcher
                    // can sanity-check the result before saving.
                    showAlert('Found: ' + (hit.display_name || (lat + ', ' + lng)), 'success');
                })
                .catch(function (err) {
                    showAlert('Lookup failed: ' + err.message, 'danger');
                })
                .then(function () {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bi bi-search me-1"></i>Lookup';
                });
        });
    }

    function bindMapClickForPinDrop() {
        if (!map || map._locEditClickBound) return;
        map._locEditClickBound = true;
        map.on('click', function (e) {
            // Inert unless the location edit form is currently visible.
            // This matches Eric's design: dispatcher must opt into edit
            // mode (pencil → expand → edit) before map clicks do anything.
            if (!_isLocationEditOpen()) return;
            _showPinConfirmPopup(e.latlng);
        });
    }

    function _showPinConfirmPopup(latlng) {
        // Build a tiny Leaflet popup at the clicked location with two
        // buttons. Confirming sets the marker + populates lat/lng + does
        // a reverse-geocode. Cancelling just closes.
        var html =
            '<div class="text-center small" style="min-width:180px;">' +
                '<div class="mb-2">' +
                    '<strong>Update incident location to here?</strong><br>' +
                    '<span class="text-body-secondary font-monospace small">' +
                        latlng.lat.toFixed(6) + ', ' + latlng.lng.toFixed(6) +
                    '</span>' +
                '</div>' +
                '<div class="d-flex gap-1 justify-content-center">' +
                    '<button type="button" class="btn btn-sm btn-primary" id="pinConfirmBtn">' +
                        '<i class="bi bi-check-lg"></i> Update Location' +
                    '</button>' +
                    '<button type="button" class="btn btn-sm btn-outline-secondary" id="pinCancelBtn">Cancel</button>' +
                '</div>' +
            '</div>';
        var popup = L.popup({ closeButton: false, autoClose: true, closeOnClick: false })
            .setLatLng(latlng)
            .setContent(html)
            .openOn(map);
        // Wire the buttons after the popup is in the DOM.
        setTimeout(function () {
            var ok = document.getElementById('pinConfirmBtn');
            var cancel = document.getElementById('pinCancelBtn');
            if (ok) ok.addEventListener('click', function () {
                _placePinAt(latlng.lat, latlng.lng, /*reverseGeocode*/ true);
                map.closePopup(popup);
            });
            if (cancel) cancel.addEventListener('click', function () {
                map.closePopup(popup);
            });
        }, 0);
    }

    // Shared: place/move the incident marker, populate lat/lng inputs,
    // and (optionally) reverse-geocode to fill empty address fields.
    function _placePinAt(lat, lng, reverseGeocode) {
        if (marker) {
            marker.setLatLng([lat, lng]);
        } else {
            marker = L.marker([lat, lng]).addTo(map);
        }
        var elLat = document.getElementById('editLat');
        var elLng = document.getElementById('editLng');
        if (elLat) elLat.value = lat.toFixed(6);
        if (elLng) elLng.value = lng.toFixed(6);

        if (!reverseGeocode) return;
        // Reverse-geocode via Nominatim. Best-effort; only fill EMPTY
        // fields so we don't overwrite the dispatcher's typed values.
        fetch('https://nominatim.openstreetmap.org/reverse?format=json&lat=' +
              encodeURIComponent(lat) + '&lon=' + encodeURIComponent(lng))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.address) return;
                var a = data.address;
                var elStreet = document.getElementById('editStreet');
                var elCity   = document.getElementById('editCity');
                var elState  = document.getElementById('editState');
                if (elStreet && !elStreet.value) {
                    var house = a.house_number ? (a.house_number + ' ') : '';
                    var road  = a.road || a.pedestrian || a.path || '';
                    if (house || road) elStreet.value = (house + road).trim();
                }
                if (elCity && !elCity.value) {
                    elCity.value = a.city || a.town || a.village || a.hamlet || a.county || '';
                }
                if (elState && !elState.value) {
                    var stRaw = a.state || '';
                    elState.value = _stateToCode(stRaw) || stRaw.substr(0, 20);
                }
            })
            .catch(function () { /* silent — pin is set, address blank */ });
    }

    // US-state name → 2-letter code lookup (Nominatim returns full state
    // names; we want 2-letter codes for the state field).
    function _stateToCode(name) {
        if (!name) return '';
        var m = {
            'alabama':'AL','alaska':'AK','arizona':'AZ','arkansas':'AR','california':'CA',
            'colorado':'CO','connecticut':'CT','delaware':'DE','florida':'FL','georgia':'GA',
            'hawaii':'HI','idaho':'ID','illinois':'IL','indiana':'IN','iowa':'IA',
            'kansas':'KS','kentucky':'KY','louisiana':'LA','maine':'ME','maryland':'MD',
            'massachusetts':'MA','michigan':'MI','minnesota':'MN','mississippi':'MS','missouri':'MO',
            'montana':'MT','nebraska':'NE','nevada':'NV','new hampshire':'NH','new jersey':'NJ',
            'new mexico':'NM','new york':'NY','north carolina':'NC','north dakota':'ND','ohio':'OH',
            'oklahoma':'OK','oregon':'OR','pennsylvania':'PA','rhode island':'RI','south carolina':'SC',
            'south dakota':'SD','tennessee':'TN','texas':'TX','utah':'UT','vermont':'VT',
            'virginia':'VA','washington':'WA','west virginia':'WV','wisconsin':'WI','wyoming':'WY',
            'district of columbia':'DC'
        };
        return m[name.toLowerCase()] || '';
    }

    function exitEditMode(section) {
        if (section === 'description') {
            document.getElementById('descDisplay').classList.remove('d-none');
            document.getElementById('descEdit').classList.add('d-none');
        } else if (section === 'location') {
            document.getElementById('locDisplay').classList.remove('d-none');
            document.getElementById('locEdit').classList.add('d-none');
        } else if (section === 'facilities') {
            document.getElementById('facDisplay').classList.remove('d-none');
            document.getElementById('facEdit').classList.add('d-none');
        } else if (section === 'contact') {
            document.getElementById('contactDisplay').classList.remove('d-none');
            document.getElementById('contactEdit').classList.add('d-none');
        } else if (section === 'additional') {
            document.getElementById('additionalDisplay').classList.remove('d-none');
            document.getElementById('additionalEdit').classList.add('d-none');
            // 2026-06-26 — Card stays visible so the edit pencil remains
            // reachable. Empty state shows a "(No additional details)"
            // placeholder rendered by renderAdditional().
        }
    }

    function saveSection(section) {
        var ticketId = getIncidentId();
        if (!ticketId) return;

        var fields = {};

        if (section === 'description') {
            fields.scope = document.getElementById('editScope').value.trim();
            fields.description = document.getElementById('editDescription').value.trim();
            fields.affected = document.getElementById('editAffected').value.trim();
        } else if (section === 'location') {
            fields.street = document.getElementById('editStreet').value.trim();
            fields.city = document.getElementById('editCity').value.trim();
            fields.state = document.getElementById('editState').value.trim();
            // 2026-06-26 — destination + cross-street parity with create form
            var elAbout = document.getElementById('editAddressAbout');
            var elToAddr = document.getElementById('editToAddress');
            if (elAbout) fields.address_about = elAbout.value.trim();
            if (elToAddr) fields.to_address = elToAddr.value.trim();
            // 2026-06-28 (Eric beta SAR) — include lat/lng if set.
            var elLat = document.getElementById('editLat');
            var elLng = document.getElementById('editLng');
            if (elLat) fields.lat = elLat.value.trim();
            if (elLng) fields.lng = elLng.value.trim();
        } else if (section === 'facilities') {
            // 2026-06-26 — facilities edit. Selects carry the facility id
            // (or "0" for None). Whitelist on the server casts to int.
            var facSel = document.getElementById('editFacility');
            var recSel = document.getElementById('editRecFacility');
            if (facSel) fields.facility = parseInt(facSel.value, 10) || 0;
            if (recSel) fields.rec_facility = parseInt(recSel.value, 10) || 0;
        } else if (section === 'contact') {
            fields.contact = document.getElementById('editContact').value.trim();
            fields.phone = document.getElementById('editPhone').value.trim();
            fields.nine_one_one = document.getElementById('editNineOneOne').value.trim();
        } else if (section === 'additional') {
            fields.comments = document.getElementById('editComments').value.trim();
        }

        // Disable save button
        var saveBtn = document.querySelector('.edit-save-btn[data-section="' + section + '"]');
        if (saveBtn) {
            saveBtn.disabled = true;
            saveBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Saving...';
        }

        fetch('api/incident-update.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'update_fields',
                ticket_id: ticketId,
                fields: fields,
                csrf_token: getCsrfToken()
            })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (saveBtn) {
                saveBtn.disabled = false;
                saveBtn.innerHTML = '<i class="bi bi-check-lg me-1"></i>Save';
            }

            if (data.error) {
                showAlert(escHtml(data.error), 'danger');
                return;
            }

            showAlert(escHtml(data.message), 'success');
            exitEditMode(section);

            // Reload the incident data to refresh all displays
            loadIncident(ticketId);
        })
        .catch(function (err) {
            if (saveBtn) {
                saveBtn.disabled = false;
                saveBtn.innerHTML = '<i class="bi bi-check-lg me-1"></i>Save';
            }
            showAlert('Failed to save: ' + escHtml(err.message), 'danger');
        });
    }

    // ═══════════════════════════════════════════════════════════════
    //  Facilities edit-mode populator
    //
    //  Caches the facilities list from api/facilities.php so opening
    //  the edit form a second time doesn't re-fetch. Both selects get
    //  the same option set; the caller's callback restores the current
    //  facility / rec_facility selection after the options are in place.
    //  2026-06-26 — added for the incident-detail facilities edit flow.
    // ═══════════════════════════════════════════════════════════════
    var facilitiesCache = null;

    function populateFacilityEditSelects(onReady) {
        if (facilitiesCache) {
            applyFacilityOptions(facilitiesCache);
            if (onReady) onReady();
            return;
        }
        fetch('api/facilities.php', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                facilitiesCache = (data && data.facilities) ? data.facilities : [];
                applyFacilityOptions(facilitiesCache);
                if (onReady) onReady();
            })
            .catch(function () {
                facilitiesCache = [];
                applyFacilityOptions(facilitiesCache);
                if (onReady) onReady();
            });
    }

    // Phase 116 — return the facilities list (shared cache) to a callback,
    // WITHOUT touching the edit-form selects. Used by the per-unit Destination
    // pickers on the assignment rows.
    function loadFacilitiesList(cb) {
        if (facilitiesCache) { cb(facilitiesCache); return; }
        fetch('api/facilities.php', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                facilitiesCache = (data && data.facilities) ? data.facilities : [];
                cb(facilitiesCache);
            })
            .catch(function () { facilitiesCache = []; cb(facilitiesCache); });
    }

    function applyFacilityOptions(list) {
        var ids = ['editFacility', 'editRecFacility'];
        for (var s = 0; s < ids.length; s++) {
            var sel = document.getElementById(ids[s]);
            if (!sel) continue;
            // Wipe + rebuild from scratch so re-opens stay correct.
            sel.innerHTML = '<option value="0">— None —</option>';
            for (var i = 0; i < list.length; i++) {
                var f = list[i];
                var opt = document.createElement('option');
                opt.value = String(f.id);
                var label = f.name || ('Facility #' + f.id);
                if (f.type_name) label += ' (' + f.type_name + ')';
                else if (f.type) label += ' (' + f.type + ')';
                opt.textContent = label;
                sel.appendChild(opt);
            }
        }
    }

    // ═══════════════════════════════════════════════════════════════
    //  Call History (2026-06-26)
    //
    //  Mirrors the new-incident.php panel: query api/call-history.php
    //  + api/constituents.php in parallel, render results into the
    //  card body. Always pulls against the CURRENT phone + street on
    //  the incident (not the originals at create time), so a dispatcher
    //  who updated the address sees prior calls at the new location.
    // ═══════════════════════════════════════════════════════════════
    function initCallHistoryCard() {
        var btn = document.getElementById('btnSearchHistory');
        if (!btn) return;
        btn.addEventListener('click', searchIncidentCallHistory);
    }

    function searchIncidentCallHistory() {
        var inc = incidentData ? incidentData.incident : null;
        if (!inc) return;
        var phone  = (inc.phone || '').trim();
        var street = (inc.street || '').trim();
        var container = document.getElementById('callHistoryResults');
        var countBadge = document.getElementById('callHistoryCount');

        if (!phone && !street) {
            container.innerHTML = '<span class="text-body-secondary small">' +
                'This incident has no phone or address to search by.</span>';
            if (countBadge) {
                countBadge.textContent = '0';
                countBadge.className = 'badge bg-secondary ms-auto';
            }
            return;
        }

        container.innerHTML = '<div class="text-center py-2"><span class="spinner-border spinner-border-sm"></span> Searching…</div>';

        var params = new URLSearchParams();
        if (phone) params.set('phone', phone);
        if (street) params.set('street', street);

        var constituentPromise = phone
            ? fetch('api/constituents.php?phone=' + encodeURIComponent(phone))
                .then(function (r) { return r.json(); })
                .catch(function () { return { constituents: [] }; })
            : Promise.resolve({ constituents: [] });

        var historyPromise = fetch('api/call-history.php?' + params.toString())
            .then(function (r) { return r.json(); })
            .catch(function () { return { results: [] }; });

        Promise.all([constituentPromise, historyPromise]).then(function (results) {
            var constituents = (results[0] && results[0].constituents) || [];
            var data = results[1] || { results: [] };
            // Filter out the current incident from prior-calls results so
            // dispatchers don't see this incident listed as its own history.
            var others = [];
            for (var i = 0; i < (data.results || []).length; i++) {
                if (data.results[i].id !== inc.id) others.push(data.results[i]);
            }
            var html = '';

            if (constituents.length > 0) {
                html += '<div class="alert alert-info py-1 px-2 mb-2 small">';
                html += '<strong><i class="bi bi-person-lines-fill me-1"></i>Contact Found</strong>';
                for (var c = 0; c < constituents.length; c++) {
                    var con = constituents[c];
                    html += '<div class="mt-1">';
                    html += '<strong>' + escHtml(con.contact) + '</strong>';
                    var addr = [con.street, con.city, con.state].filter(function (x) { return x; }).join(', ');
                    if (addr) html += ' &mdash; ' + escHtml(addr);
                    html += '</div>';
                    if (con.miscellaneous) {
                        html += '<div class="alert alert-warning py-1 px-2 mt-1 mb-0">';
                        html += '<i class="bi bi-exclamation-triangle me-1"></i>' + escHtml(con.miscellaneous);
                        html += '</div>';
                    }
                }
                html += '</div>';
            }

            if (others.length === 0) {
                if (constituents.length === 0) {
                    container.innerHTML = '<span class="text-body-secondary small">No previous calls or contacts found.</span>';
                    if (countBadge) {
                        countBadge.textContent = '0';
                        countBadge.className = 'badge bg-secondary ms-auto';
                    }
                    return;
                }
                if (countBadge) {
                    countBadge.textContent = String(constituents.length);
                    countBadge.className = 'badge bg-info ms-auto';
                }
                container.innerHTML = html;
                return;
            }

            if (countBadge) {
                countBadge.textContent = String(others.length + constituents.length);
                countBadge.className = 'badge bg-info ms-auto';
            }

            html += '<div class="table-responsive"><table class="table table-sm table-hover mb-0 small">' +
                '<thead><tr><th>Date</th><th>Type</th><th>Scope</th><th>Status</th></tr></thead><tbody>';
            for (var r2 = 0; r2 < others.length; r2++) {
                var row = others[r2];
                var statusClass = row.status === 2 ? 'text-success' : (row.status === 3 ? 'text-warning' : 'text-body-secondary');
                var statusText = row.status === 2 ? 'Open' : (row.status === 3 ? 'Scheduled' : 'Closed');
                html += '<tr>' +
                    '<td>' + escHtml(row.date || '') + '</td>' +
                    '<td>' + escHtml(row.incident_type || '') + '</td>' +
                    '<td><a href="incident-detail.php?id=' + row.id + '">' + escHtml(row.scope || '') + '</a></td>' +
                    '<td class="' + statusClass + '">' + statusText + '</td>' +
                    '</tr>';
            }
            html += '</tbody></table></div>';
            container.innerHTML = html;
        }).catch(function () {
            container.innerHTML = '<span class="text-danger small">Failed to search call history.</span>';
        });
    }

    // ═══════════════════════════════════════════════════════════════
    //  Patients (2026-06-26)
    //
    //  Loads patients for this incident from api/patients.php and
    //  renders an inline editable list (name + DOB + gender + condition).
    //  Add / Save / Remove all hit api/patients.php with CSRF + RBAC.
    //
    //  NEW patients (not yet saved) live in the DOM with data-id="new"
    //  until the user clicks Save, at which point an 'add' POST returns
    //  the real id. Existing patients carry their real DB id.
    // ═══════════════════════════════════════════════════════════════
    function initPatientsCard() {
        var btn = document.getElementById('btnAddPatient');
        if (!btn) return;
        btn.addEventListener('click', function () { addPatientRow(null); });
        loadPatients();
    }

    function loadPatients() {
        var ticketId = getIncidentId();
        if (!ticketId) return;
        var container = document.getElementById('patientList');
        fetch('api/patients.php?ticket_id=' + ticketId, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var list = (data && data.patients) ? data.patients : [];
                container.innerHTML = '';
                if (list.length === 0) {
                    container.innerHTML = '<div class="text-body-secondary small">No patients recorded.</div>';
                } else {
                    for (var i = 0; i < list.length; i++) addPatientRow(list[i]);
                }
                updatePatientCountBadge();
            })
            .catch(function () {
                container.innerHTML = '<div class="text-danger small">Failed to load patients.</div>';
            });
    }

    function addPatientRow(patient) {
        var container = document.getElementById('patientList');
        // First add — wipe the "no patients" placeholder if present.
        var placeholder = container.querySelector('.text-body-secondary');
        if (placeholder && container.children.length === 1) {
            container.innerHTML = '';
        }

        var id = patient ? patient.id : 'new';
        var name = patient ? (patient.name || patient.fullname || '') : '';
        var dob = patient ? (patient.dob || '') : '';
        var gender = patient ? (patient.gender || 0) : 0;
        var desc = patient ? (patient.description || '') : '';

        var div = document.createElement('div');
        div.className = 'patient-entry card card-body p-2 mb-2';
        div.dataset.id = id;
        var num = container.querySelectorAll('.patient-entry').length + 1;
        div.innerHTML =
            '<div class="d-flex align-items-center justify-content-between mb-1">' +
                '<strong class="small">Patient #' + num + '</strong>' +
                '<div class="d-flex gap-1">' +
                    '<button type="button" class="btn btn-sm btn-primary patient-save" title="Save patient">' +
                        '<i class="bi bi-check-lg"></i>' +
                    '</button>' +
                    '<button type="button" class="btn btn-sm btn-outline-danger patient-remove" title="Remove patient">' +
                        '<i class="bi bi-x-lg"></i>' +
                    '</button>' +
                '</div>' +
            '</div>' +
            '<div class="row g-2">' +
                '<div class="col-md-6">' +
                    '<input type="text" class="form-control form-control-sm patient-name" placeholder="Name" value="' + escHtmlAttr(name) + '">' +
                '</div>' +
                '<div class="col-md-3">' +
                    '<div class="input-group input-group-sm">' +
                        '<input type="text" class="form-control form-control-sm patient-dob" placeholder="DOB" value="' + escHtmlAttr(dob) + '" title="Accepts 10/12/70, 101270, 12/1964, Dec 1964, 1964">' +
                        '<span class="input-group-text bg-body-tertiary text-body-secondary small patient-dob-age d-none" style="min-width:48px; padding:0.25rem 0.4rem;"></span>' +
                    '</div>' +
                '</div>' +
                '<div class="col-md-3">' +
                    '<select class="form-select form-select-sm patient-gender">' +
                        '<option value="0"' + (gender === 0 ? ' selected' : '') + '>—</option>' +
                        '<option value="1"' + (gender === 1 ? ' selected' : '') + '>Male</option>' +
                        '<option value="2"' + (gender === 2 ? ' selected' : '') + '>Female</option>' +
                        '<option value="3"' + (gender === 3 ? ' selected' : '') + '>Other</option>' +
                    '</select>' +
                '</div>' +
                '<div class="col-12">' +
                    '<textarea class="form-control form-control-sm patient-desc" rows="1" placeholder="Condition / notes">' + escHtml(desc) + '</textarea>' +
                '</div>' +
            '</div>';
        container.appendChild(div);

        div.querySelector('.patient-save').addEventListener('click', function () {
            savePatientRow(div);
        });
        div.querySelector('.patient-remove').addEventListener('click', function () {
            removePatientRow(div);
        });

        // 2026-06-28 (Eric beta) — wire the DOB parser. Same as
        // new-incident.php: blur normalizes to MM/DD/YYYY (or partial
        // MM/YYYY / YYYY), shows age badge, sets data-iso for save.
        if (window.DobHelper) {
            var dobIn  = div.querySelector('.patient-dob');
            var dobAge = div.querySelector('.patient-dob-age');
            window.DobHelper.bindInput(dobIn, dobAge);
        }

        updatePatientCountBadge();

        // Focus the name field on freshly added rows
        if (!patient) {
            div.querySelector('.patient-name').focus();
        }
    }

    function savePatientRow(rowEl) {
        var ticketId = getIncidentId();
        if (!ticketId) return;
        var id = rowEl.dataset.id;
        var dobEl = rowEl.querySelector('.patient-dob');
        // Phase 2026-06-28 — normalize DOB to ISO at save time so
        // partial dates round-trip cleanly. readIso prefers data-iso
        // (set on blur), falls back to re-parsing raw if user typed
        // and clicked save without blurring first.
        var dobValue = (window.DobHelper)
            ? (window.DobHelper.readIso(dobEl) || dobEl.value.trim())
            : dobEl.value.trim();
        var payload = {
            csrf_token:  getCsrfToken(),
            name:        rowEl.querySelector('.patient-name').value.trim(),
            dob:         dobValue,
            gender:      parseInt(rowEl.querySelector('.patient-gender').value, 10) || 0,
            description: rowEl.querySelector('.patient-desc').value.trim()
        };

        if (id === 'new' || !id) {
            payload.action = 'add';
            payload.ticket_id = ticketId;
        } else {
            payload.action = 'update';
            payload.id = parseInt(id, 10);
        }

        var saveBtn = rowEl.querySelector('.patient-save');
        saveBtn.disabled = true;
        saveBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

        fetch('api/patients.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            saveBtn.disabled = false;
            saveBtn.innerHTML = '<i class="bi bi-check-lg"></i>';
            if (data && data.error) {
                showAlert(escHtml(data.error), 'danger');
                return;
            }
            // On add, capture the new id so subsequent saves update in place.
            if (payload.action === 'add' && data && data.id) {
                rowEl.dataset.id = String(data.id);
            }
            showAlert(escHtml(data.message || 'Saved'), 'success');
        })
        .catch(function (err) {
            saveBtn.disabled = false;
            saveBtn.innerHTML = '<i class="bi bi-check-lg"></i>';
            showAlert('Failed to save patient: ' + escHtml(err.message), 'danger');
        });
    }

    function removePatientRow(rowEl) {
        var id = rowEl.dataset.id;
        // Brand-new unsaved row — just drop it from the DOM.
        if (id === 'new' || !id) {
            rowEl.remove();
            updatePatientCountBadge();
            return;
        }
        if (!confirm('Remove this patient from the incident?')) return;
        fetch('api/patients.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'delete',
                id: parseInt(id, 10),
                csrf_token: getCsrfToken()
            })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data && data.error) {
                showAlert(escHtml(data.error), 'danger');
                return;
            }
            rowEl.remove();
            updatePatientCountBadge();
            showAlert(escHtml(data.message || 'Patient removed'), 'info');
        })
        .catch(function (err) {
            showAlert('Failed to remove patient: ' + escHtml(err.message), 'danger');
        });
    }

    function updatePatientCountBadge() {
        var rows = document.querySelectorAll('.patient-entry');
        var badge = document.getElementById('patientCount');
        if (!badge) return;
        badge.textContent = String(rows.length);
        badge.className = 'badge ms-auto ' + (rows.length > 0 ? 'bg-danger' : 'bg-secondary');
    }

    // Attribute-safe HTML escape (handles double quotes in value="…").
    function escHtmlAttr(str) {
        if (str === null || str === undefined) return '';
        return String(str).replace(/&/g, '&amp;').replace(/"/g, '&quot;')
            .replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    // Phase 104h (a beta tester GH #13, 2026-07-02) — real-time refresh via
    // SSE. Any dispatcher edit (assign, status, action-note, patient,
    // incident-status) on THIS incident pushes an event through the
    // EventBus that navbar.php auto-loads. Debounce so a burst of
    // events (e.g. dispatch of 4 units in a row) collapses into one
    // refetch instead of four.
    var _phase104h_refetch_timer = null;
    function _phase104h_schedule_refresh(evtName) {
        if (_phase104h_refetch_timer) clearTimeout(_phase104h_refetch_timer);
        _phase104h_refetch_timer = setTimeout(function () {
            _phase104h_refetch_timer = null;
            refreshIncident();
        }, 250);
    }
    function _phase104h_wire_sse() {
        if (typeof EventBus === 'undefined' || !EventBus.on) {
            // EventBus not loaded (should be via navbar). Retry once
            // 500ms later — if still not there, silently skip.
            setTimeout(function () {
                if (typeof EventBus !== 'undefined' && EventBus.on) _phase104h_wire_sse();
            }, 500);
            return;
        }
        var tid = getIncidentId();
        if (!tid) return;
        // Filter each event to THIS incident — the SSE stream is
        // per-user, but events carry a ticket_id we can match on.
        function forThisIncident(payload) {
            if (!payload || payload.ticket_id === undefined) return true; // no id → assume yes
            return parseInt(payload.ticket_id, 10) === parseInt(tid, 10);
        }
        // Issue #13 (a beta tester 2026-07-05) — also listen for the events the system
        // ACTUALLY emits for notes + status: notes fire 'incident:note' (not
        // 'action:added', which nothing publishes) and status changes fire
        // 'responder:status' (not 'assign:update'). Without these, a note or
        // status change made elsewhere — especially from mobile — never
        // refreshed an open CAD incident window.
        var events = [
            'incident:update', 'incident:close', 'incident:note', 'action:added',
            'responder:status', 'assign:update', 'assign:new', 'patient:add', 'patient:update'
        ];
        events.forEach(function (e) {
            EventBus.on(e, function (payload) {
                if (!forThisIncident(payload)) return;
                _phase104h_schedule_refresh(e);
            });
        });
    }

    // ── Boot ──
    document.addEventListener('DOMContentLoaded', function () {
        init();
        // The init() call above kicks off loadIncident(), which only
        // populates the description/location/etc. sections. The two new
        // sections (call history + patients) bind to controls that exist
        // in the static HTML, so we can wire them up immediately.
        initCallHistoryCard();
        initPatientsCard();
        // Wire SSE last so getIncidentId() has the ?id= URL param ready.
        _phase104h_wire_sse();
    });

})();
