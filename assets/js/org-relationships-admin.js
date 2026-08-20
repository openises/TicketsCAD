/**
 * NewUI v4.0 - Cross-Org Standing Relationships Admin (Phase 143, GH#70 Phase 3)
 *
 * List + propose + member management (add/approve/reject/withdraw) +
 * activation control for org_relationships. Backend: api/org-relationships.php.
 * Follows the same list-panel/detail-panel/confirm-modal shape as
 * assets/js/org-routing-admin.js (Phase 141), extended with a richer
 * detail/manage panel because a standing relationship has N member orgs
 * (not a fixed pair) and a time-boxed activation lifecycle a routing rule
 * doesn't have.
 *
 * Countdown technique starts from this codebase's own established precedent
 * (assets/js/config.js's pmFriendlyDelta() for pending routed messages) --
 * parse the MySQL datetime string as browser-local time
 * (`new Date(str.replace(' ','T'))`) -- but adds ONE thing that precedent
 * doesn't: clock-skew correction against the api/org-relationships.php
 * `server_now` field, sampled on every list load. Verified necessary live,
 * not theoretical: a real dev-machine/DB clock offset of ~59 minutes turned
 * a 1-minute activation window into a displayed "60:54 remaining" during
 * this phase's own manual verification pass, using the mirrored
 * skew-blind technique. `server_now` was already being returned by the API
 * (matching api/pending-messages.php's 'now' convention) specifically so a
 * consumer COULD correct for this; this file is the one that actually
 * does. serverSkewMs (serverNowMs - Date.now(), sampled once per list
 * fetch) is added to every Date.now() read inside a countdown calculation.
 *
 * Display-only flags (window.ORR_CAN_*) drive which buttons RENDER; every
 * write is independently re-checked server-side by api/org-relationships.php,
 * which re-derives row-level authorization from scratch via
 * org_relationship_can_act_for_org() -- never from anything this file sends
 * or computes.
 */
(function () {
    'use strict';

    var csrfToken = '';
    var orgList = [];              // all orgs, for the add-member / propose pickers
    var relationships = [];        // last-loaded list, cached for the detail panel
    var currentDetailId = 0;       // 0 = list view
    var countdownTimer = null;
    var pendingWithdraw = null;    // { memberId, orgName, relationshipId }
    var pendingDeactivateRelId = 0;
    var serverSkewMs = 0;          // serverNowMs - Date.now(), resampled on every list load

    document.addEventListener('DOMContentLoaded', function () {
        csrfToken = document.getElementById('csrfToken') ? document.getElementById('csrfToken').value : '';
        bindEvents();
        loadOrgs();
        loadRelationships();
        bindRealtime();
        countdownTimer = setInterval(tickCountdowns, 1000);
    });

    // ═══════════════════════════════════════════════════════════
    // Live updates — SSE (event-bus.js is loaded globally via navbar)
    // ═══════════════════════════════════════════════════════════
    function bindRealtime() {
        if (!window.EventBus || typeof window.EventBus.on !== 'function') return;
        var debounce = null;
        var refresh = function () {
            if (debounce) return;
            debounce = setTimeout(function () {
                debounce = null;
                loadRelationships();
            }, 500);
        };
        window.EventBus.on('org_relationship:activated', refresh);
        window.EventBus.on('org_relationship:deactivated', refresh);
    }

    // ═══════════════════════════════════════════════════════════
    // Orgs
    // ═══════════════════════════════════════════════════════════
    function loadOrgs() {
        fetch('api/organizations.php?action=list')
            .then(function (r) { return r.json(); })
            .then(function (resp) {
                orgList = resp.organizations || [];
                populateProposeOrgList();
                // The add-member <select> is populated by renderAddMemberRow()
                // when the detail panel renders (it needs the CURRENT
                // relationship's member list to exclude existing members) --
                // there is no separate standalone populate step here. If a
                // detail panel is already open when this fetch resolves
                // (e.g. orgs were slow to load), refresh it now that orgList
                // is populated.
                if (currentDetailId) {
                    var rel = findRelationship(currentDetailId);
                    if (rel) renderAddMemberRow(rel);
                }
            })
            .catch(function () { /* pickers stay empty; save will fail server-side with a clear error */ });
    }

    function orgName(id) {
        id = parseInt(id, 10);
        for (var i = 0; i < orgList.length; i++) {
            if (parseInt(orgList[i].id, 10) === id) return orgList[i].name;
        }
        return 'Org #' + id;
    }

    // ═══════════════════════════════════════════════════════════
    // List panel
    // ═══════════════════════════════════════════════════════════
    function loadRelationships() {
        var tbody = document.getElementById('orrListRows');
        return fetch('api/org-relationships.php?list=1')
            .then(function (r) { return r.json(); })
            .then(function (resp) {
                if (resp.error) {
                    if (tbody) tbody.innerHTML = '<tr><td colspan="7" class="text-danger">' + escHtml(resp.error) + '</td></tr>';
                    return;
                }
                relationships = resp.relationships || [];
                if (resp.server_now) {
                    var serverNowMs = new Date(String(resp.server_now).replace(' ', 'T')).getTime();
                    if (!isNaN(serverNowMs)) serverSkewMs = serverNowMs - Date.now();
                }
                renderList();
                if (currentDetailId) {
                    var rel = findRelationship(currentDetailId);
                    if (rel) renderDetail(rel); else closeDetail();
                }
            })
            .catch(function (err) {
                if (tbody) tbody.innerHTML = '<tr><td colspan="7" class="text-danger">Failed to load: ' + escHtml(err.message) + '</td></tr>';
            });
    }

    function findRelationship(id) {
        for (var i = 0; i < relationships.length; i++) {
            if (parseInt(relationships[i].id, 10) === parseInt(id, 10)) return relationships[i];
        }
        return null;
    }

    function statusBadge(status) {
        if (status === 'active') return '<span class="badge bg-success">Active</span>';
        if (status === 'rejected') return '<span class="badge bg-danger">Rejected</span>';
        return '<span class="badge bg-secondary">Pending</span>';
    }

    function tierBadges(rel) {
        var accessBadge = rel.access_tier === 'assist'
            ? '<span class="badge bg-warning text-dark" title="Access tier">Assist</span>'
            : '<span class="badge bg-info text-dark" title="Access tier">View</span>';
        var redactBadge = rel.redaction_profile === 'assist'
            ? '<span class="badge bg-warning text-dark" title="Redaction profile">Assist</span>'
            : '<span class="badge bg-info text-dark" title="Redaction profile">View</span>';
        return accessBadge + ' / ' + redactBadge;
    }

    function memberSummary(rel) {
        var parts = [];
        for (var i = 0; i < rel.members.length; i++) {
            var m = rel.members[i];
            var cls = m.status === 'approved' ? 'text-success' : (m.status === 'rejected' ? 'text-danger' : 'text-body-secondary');
            parts.push('<span class="' + cls + '">' + escHtml(m.org_name) + '</span>');
        }
        return parts.join(', ');
    }

    function activationCell(rel) {
        if (!rel.requires_activation) {
            return '<span class="badge bg-secondary-subtle text-body-secondary border">Always on</span>';
        }
        if (rel.status !== 'active') {
            return '<span class="text-body-tertiary small">&mdash;</span>';
        }
        if (!rel.live_activation) {
            var canAct = window.ORR_CAN_ACT_GLOBAL || (window.ORR_CAN_ACTIVATE && callerHasApprovedMember(rel));
            return '<span class="badge bg-secondary">Not activated</span>'
                + (canAct ? ' <button class="btn btn-sm btn-outline-success py-0 px-1 ms-1" data-quick-activate="' + rel.id + '" title="Activate"><i class="bi bi-lightning-charge"></i></button>' : '');
        }
        return '<span class="font-monospace small" data-orr-countdown data-activated-at="' + escAttr(rel.live_activation.activated_at) +
            '" data-max-minutes="' + (rel.live_activation.max_activation_minutes === null ? '' : rel.live_activation.max_activation_minutes) +
            '">' + countdownText(rel.live_activation.activated_at, rel.live_activation.max_activation_minutes) + '</span>';
    }

    function callerHasApprovedMember(rel) {
        var mine = rel.my_org_ids || [];
        for (var i = 0; i < rel.members.length; i++) {
            var m = rel.members[i];
            if (m.status === 'approved' && mine.indexOf(m.org_id) !== -1) return true;
        }
        return false;
    }

    function renderList() {
        var tbody = document.getElementById('orrListRows');
        if (!tbody) return;

        if (relationships.length === 0) {
            tbody.innerHTML = '<tr><td colspan="7" class="text-body-secondary py-3 text-center">'
                + 'No standing relationships yet. Use "Propose Relationship" above to create one.</td></tr>';
            return;
        }

        var html = '';
        relationships.forEach(function (rel) {
            html += '<tr>';
            html += '<td><a href="#" data-open-detail="' + rel.id + '">' + escHtml(rel.name) + '</a></td>';
            html += '<td class="small text-body-secondary">' + escHtml(rel.relationship_type) + '</td>';
            html += '<td class="small">' + memberSummary(rel) + '</td>';
            html += '<td>' + tierBadges(rel) + '</td>';
            html += '<td>' + statusBadge(rel.status) + '</td>';
            html += '<td>' + activationCell(rel) + '</td>';
            html += '<td class="text-end"><button class="btn btn-sm btn-outline-primary" data-open-detail="' + rel.id + '" title="Manage"><i class="bi bi-gear"></i></button></td>';
            html += '</tr>';
        });
        tbody.innerHTML = html;
    }

    // ═══════════════════════════════════════════════════════════
    // Countdown ticking (no re-fetch every second — just re-render the
    // already-cached activated_at/max_activation_minutes each tick; a full
    // reload only happens once something actually crosses zero).
    // ═══════════════════════════════════════════════════════════
    function countdownText(activatedAt, maxMinutes) {
        if (maxMinutes === null || maxMinutes === undefined || maxMinutes === '') {
            return 'Active — no ceiling (manual deactivation only)';
        }
        var activatedMs = new Date(String(activatedAt).replace(' ', 'T')).getTime();
        var expiresMs = activatedMs + (parseInt(maxMinutes, 10) * 60000);
        // Skew-corrected "now" -- see this file's own docblock for why this
        // matters (verified live, not theoretical).
        var remainingMs = expiresMs - (Date.now() + serverSkewMs);
        if (remainingMs <= 0) return 'Expired — refreshing…';
        var totalSec = Math.floor(remainingMs / 1000);
        var mm = Math.floor(totalSec / 60);
        var ss = totalSec % 60;
        return 'Active — ' + mm + ':' + (ss < 10 ? '0' : '') + ss + ' remaining';
    }

    var _expiredSeen = false;
    function tickCountdowns() {
        var els = document.querySelectorAll('[data-orr-countdown]');
        var anyExpired = false;
        for (var i = 0; i < els.length; i++) {
            var el = els[i];
            var activatedAt = el.getAttribute('data-activated-at');
            var maxRaw = el.getAttribute('data-max-minutes');
            var maxMinutes = maxRaw === '' ? null : parseInt(maxRaw, 10);
            var text = countdownText(activatedAt, maxMinutes);
            el.textContent = text;
            if (text.indexOf('Expired') === 0) anyExpired = true;
        }
        if (anyExpired && !_expiredSeen) {
            _expiredSeen = true;
            // Debounced single reload once — the read-time predicate on the
            // server has ALREADY revoked access by the time this fires; this
            // reload only updates what the admin UI displays.
            setTimeout(function () { _expiredSeen = false; loadRelationships(); }, 1500);
        }
    }

    // ═══════════════════════════════════════════════════════════
    // Detail / manage panel
    // ═══════════════════════════════════════════════════════════
    function openDetail(id) {
        currentDetailId = parseInt(id, 10);
        var rel = findRelationship(currentDetailId);
        if (!rel) { showToast('danger', 'Relationship not found.'); return; }
        renderDetail(rel);
        document.getElementById('orrListPanel').classList.add('d-none');
        document.getElementById('orrDetailPanel').classList.remove('d-none');
        document.getElementById('orrDetailPanel').scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function closeDetail() {
        currentDetailId = 0;
        document.getElementById('orrDetailPanel').classList.add('d-none');
        document.getElementById('orrListPanel').classList.remove('d-none');
        loadRelationships();
    }

    function renderDetail(rel) {
        document.getElementById('orrDetailId').value = rel.id;
        document.getElementById('orrDetailTitle').innerHTML =
            '<i class="bi bi-diagram-3 me-2"></i>' + escHtml(rel.name) + ' ' + statusBadge(rel.status);

        var ceilingText = rel.max_activation_minutes ? (rel.max_activation_minutes + ' min ceiling') : 'no ceiling';
        document.getElementById('orrDetailMeta').innerHTML =
            '<div class="col-auto"><span class="text-body-secondary">Type:</span> ' + escHtml(rel.relationship_type) + '</div>' +
            '<div class="col-auto"><span class="text-body-secondary">Access tier:</span> ' + escHtml(rel.access_tier) + '</div>' +
            '<div class="col-auto"><span class="text-body-secondary">Redaction profile:</span> ' + escHtml(rel.redaction_profile) + '</div>' +
            '<div class="col-auto"><span class="text-body-secondary">Requires activation:</span> ' + (rel.requires_activation ? 'Yes (' + ceilingText + ')' : 'No — always on while active') + '</div>' +
            '<div class="col-auto"><span class="text-body-secondary">Proposed by:</span> ' + escHtml(rel.created_by_name || '') + '</div>';

        renderMemberRows(rel);
        renderAddMemberRow(rel);
        renderActivationBlock(rel);
        hideDetailError();
    }

    function renderMemberRows(rel) {
        var tbody = document.getElementById('orrMemberRows');
        if (!tbody) return;
        var mine = rel.my_org_ids || [];
        var html = '';
        rel.members.forEach(function (m) {
            var canActThisRow = window.ORR_CAN_ACT_GLOBAL || mine.indexOf(m.org_id) !== -1;
            var detail = '';
            var actions = '';
            if (m.status === 'approved') {
                detail = '<span class="small text-body-secondary">approved by ' + escHtml(m.approved_by_name || '') + (m.approved_at ? ' at ' + escHtml(m.approved_at) : '') + '</span>';
                if (canActThisRow) {
                    actions = '<button class="btn btn-sm btn-outline-danger" data-withdraw-member="' + m.id + '" data-org-name="' + escAttr(m.org_name) + '" data-rel-id="' + rel.id + '">'
                        + '<i class="bi bi-person-dash me-1"></i>Remove</button>';
                }
            } else if (m.status === 'pending') {
                detail = '<span class="small text-body-secondary">proposed by ' + escHtml(m.proposed_by_name || '') + (m.proposed_at ? ' at ' + escHtml(m.proposed_at) : '') + '</span>';
                if (canActThisRow) {
                    actions = '<button class="btn btn-sm btn-outline-success me-1" data-approve-member="' + m.id + '"><i class="bi bi-check-lg me-1"></i>Approve</button>'
                        + '<button class="btn btn-sm btn-outline-danger" data-withdraw-member="' + m.id + '" data-org-name="' + escAttr(m.org_name) + '" data-rel-id="' + rel.id + '" data-is-reject="1">'
                        + '<i class="bi bi-x-lg me-1"></i>Reject</button>';
                }
            } else {
                detail = '<span class="small text-danger">rejected/withdrawn by ' + escHtml(m.rejected_by_name || '') + (m.rejection_reason ? ': ' + escHtml(m.rejection_reason) : '') + '</span>';
            }
            html += '<tr>';
            html += '<td>' + escHtml(m.org_name) + '</td>';
            html += '<td>' + statusBadgeMember(m.status) + '</td>';
            html += '<td>' + detail + '</td>';
            html += '<td class="text-end">' + actions + '</td>';
            html += '</tr>';
        });
        tbody.innerHTML = html;
    }

    function statusBadgeMember(status) {
        if (status === 'approved') return '<span class="badge bg-success">Approved</span>';
        if (status === 'rejected') return '<span class="badge bg-danger">Rejected</span>';
        return '<span class="badge bg-secondary">Pending</span>';
    }

    function renderAddMemberRow(rel) {
        var row = document.getElementById('orrAddMemberRow');
        var canAdd = window.ORR_CAN_ACT_GLOBAL || window.ORR_CAN_MANAGE_ORG;
        if (!row) return;
        row.classList.toggle('d-none', !canAdd);
        if (!canAdd) return;
        var sel = document.getElementById('orrAddMemberOrg');
        if (!sel) return;
        var existingIds = rel.members
            .filter(function (m) { return m.status !== 'rejected'; })
            .map(function (m) { return m.org_id; });
        sel.innerHTML = '<option value="">Select an organization…</option>';
        orgList.forEach(function (o) {
            if (existingIds.indexOf(parseInt(o.id, 10)) !== -1) return;
            var opt = document.createElement('option');
            opt.value = String(o.id);
            opt.textContent = o.name;
            sel.appendChild(opt);
        });
    }

    function renderActivationBlock(rel) {
        var block = document.getElementById('orrActivationBlock');
        if (!block) return;

        if (!rel.requires_activation) {
            block.innerHTML = '<div class="alert alert-secondary small mb-0"><i class="bi bi-info-circle me-1"></i>'
                + 'This relationship does not require activation — every approved member org is visible to every '
                + 'other approved member org continuously while the relationship itself is <strong>active</strong>.</div>';
            return;
        }
        if (rel.status !== 'active') {
            block.innerHTML = '<div class="alert alert-secondary small mb-0"><i class="bi bi-info-circle me-1"></i>'
                + 'Activation is unavailable until every named member has consented (relationship status: '
                + escHtml(rel.status) + ').</div>';
            return;
        }

        var canActivate = window.ORR_CAN_ACT_GLOBAL || (window.ORR_CAN_ACTIVATE && callerHasApprovedMember(rel));

        if (rel.live_activation) {
            var a = rel.live_activation;
            var html = '<div class="alert alert-success d-flex align-items-center justify-content-between flex-wrap gap-2 mb-0">';
            html += '<div><i class="bi bi-lightning-charge-fill me-1"></i><strong>Currently activated</strong> by '
                + escHtml(a.activated_by_name || '') + (a.activation_reason ? ': ' + escHtml(a.activation_reason) : '') + '<br>'
                + '<span class="font-monospace small" data-orr-countdown data-activated-at="' + escAttr(a.activated_at)
                + '" data-max-minutes="' + (a.max_activation_minutes === null ? '' : a.max_activation_minutes) + '">'
                + countdownText(a.activated_at, a.max_activation_minutes) + '</span></div>';
            if (canActivate) {
                html += '<button class="btn btn-sm btn-outline-danger" id="orrBtnDeactivate" data-rel-id="' + rel.id + '">'
                    + '<i class="bi bi-slash-circle me-1"></i>Deactivate</button>';
            }
            html += '</div>';
            block.innerHTML = html;
        } else {
            var msg = '<div class="alert alert-secondary d-flex align-items-center justify-content-between flex-wrap gap-2 mb-0">'
                + '<div><i class="bi bi-hourglass-split me-1"></i>Not currently activated. No visibility is granted until activated.</div>';
            if (canActivate) {
                msg += '<button class="btn btn-sm btn-success" id="orrBtnActivate" data-rel-id="' + rel.id + '" data-ceiling="'
                    + (rel.max_activation_minutes === null ? '' : rel.max_activation_minutes) + '">'
                    + '<i class="bi bi-lightning-charge me-1"></i>Activate</button>';
            }
            msg += '</div>';
            block.innerHTML = msg;
        }
    }

    // ═══════════════════════════════════════════════════════════
    // Propose modal
    // ═══════════════════════════════════════════════════════════
    function populateProposeOrgList() {
        var container = document.getElementById('orrPropOrgList');
        if (!container) return;
        var html = '';
        orgList.forEach(function (o) {
            html += '<div class="form-check">'
                + '<input class="form-check-input" type="checkbox" value="' + o.id + '" id="orrPropOrg' + o.id + '">'
                + '<label class="form-check-label" for="orrPropOrg' + o.id + '">' + escHtml(o.name) + '</label>'
                + '</div>';
        });
        container.innerHTML = html || '<div class="text-body-secondary small">No organizations defined yet.</div>';
    }

    function openProposeModal() {
        document.getElementById('orrPropName').value = '';
        document.getElementById('orrPropType').value = 'mutual_aid';
        document.getElementById('orrPropAccessView').checked = true;
        document.getElementById('orrPropRedactView').checked = true;
        document.getElementById('orrPropRequiresActivation').checked = true;
        document.getElementById('orrPropCeiling').value = '';
        var checks = document.querySelectorAll('#orrPropOrgList input[type=checkbox]');
        for (var i = 0; i < checks.length; i++) checks[i].checked = false;
        hideProposeError();
        var modalEl = document.getElementById('orrProposeModal');
        if (modalEl && window.bootstrap) bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }

    function submitPropose() {
        hideProposeError();
        var name = document.getElementById('orrPropName').value.trim();
        if (!name) { showProposeError('Name is required.'); return; }

        var checks = document.querySelectorAll('#orrPropOrgList input[type=checkbox]:checked');
        var memberOrgIds = [];
        for (var i = 0; i < checks.length; i++) memberOrgIds.push(parseInt(checks[i].value, 10));
        if (memberOrgIds.length < 2) { showProposeError('Select at least 2 member organizations.'); return; }

        var ceilingVal = document.getElementById('orrPropCeiling').value;
        var payload = {
            action: 'propose',
            csrf_token: csrfToken,
            name: name,
            relationship_type: document.getElementById('orrPropType').value,
            member_org_ids: memberOrgIds,
            access_tier: document.getElementById('orrPropAccessAssist').checked ? 'assist' : 'view',
            redaction_profile: document.getElementById('orrPropRedactAssist').checked ? 'assist' : 'view',
            requires_activation: document.getElementById('orrPropRequiresActivation').checked,
            max_activation_minutes: ceilingVal ? parseInt(ceilingVal, 10) : null
        };

        postJson(payload)
            .then(function (resp) {
                if (resp.error) { showProposeError(resp.error); return; }
                var modalEl = document.getElementById('orrProposeModal');
                if (modalEl && window.bootstrap) {
                    var inst = bootstrap.Modal.getInstance(modalEl);
                    if (inst) inst.hide();
                }
                showToast('success', 'Relationship proposed (status: ' + (resp.status || 'pending') + ').');
                loadRelationships();
            })
            .catch(function (err) { showProposeError('Failed: ' + err.message); });
    }

    // ═══════════════════════════════════════════════════════════
    // Member management — add / approve / reject-or-withdraw
    // ═══════════════════════════════════════════════════════════
    function addMember() {
        var sel = document.getElementById('orrAddMemberOrg');
        var orgId = sel ? parseInt(sel.value, 10) : 0;
        if (!orgId) { showDetailError('Select an organization to add.'); return; }
        postJson({ action: 'add_member', relationship_id: currentDetailId, org_id: orgId, csrf_token: csrfToken })
            .then(function (resp) {
                if (resp.error) { showDetailError(resp.error); return; }
                showToast('success', 'Organization added.');
                loadRelationships();
            })
            .catch(function (err) { showDetailError('Failed: ' + err.message); });
    }

    function approveMember(memberId) {
        postJson({ action: 'approve_member', member_id: memberId, csrf_token: csrfToken })
            .then(function (resp) {
                if (resp.error) { showDetailError(resp.error); return; }
                showToast('success', 'Membership approved.');
                loadRelationships();
            })
            .catch(function (err) { showDetailError('Failed: ' + err.message); });
    }

    // ── Named-confirmation withdraw/reject flow ──
    // The typed org name is a UX guard ONLY — see api/org-relationships.php's
    // own docblock. The server never reads or trusts anything asserted here;
    // the real authorization is org_relationship_can_act_for_org(), re-run
    // server-side against the row's own org_id.
    function openWithdrawModal(memberId, orgNameVal, relId, isReject) {
        pendingWithdraw = { memberId: memberId, orgName: orgNameVal, relationshipId: relId, isReject: !!isReject };
        var desc = isReject
            ? 'You are about to reject ' + orgNameVal + '’s pending invitation to this relationship.'
            : 'You are about to remove ' + orgNameVal + ' from this relationship.';
        document.getElementById('orrWithdrawDesc').textContent = desc;
        document.getElementById('orrWithdrawOrgNameEcho').textContent = orgNameVal;
        document.getElementById('orrWithdrawConfirmInput').value = '';
        document.getElementById('orrWithdrawReason').value = '';
        document.getElementById('orrBtnConfirmWithdraw').disabled = true;
        var modalEl = document.getElementById('orrWithdrawModal');
        if (modalEl && window.bootstrap) bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }

    function confirmWithdraw() {
        if (!pendingWithdraw) return;
        var reason = document.getElementById('orrWithdrawReason').value.trim();
        postJson({ action: 'reject_member', member_id: pendingWithdraw.memberId, reason: reason || undefined, csrf_token: csrfToken })
            .then(function (resp) {
                if (resp.error) { showToast('danger', resp.error); return; }
                var modalEl = document.getElementById('orrWithdrawModal');
                if (modalEl && window.bootstrap) {
                    var inst = bootstrap.Modal.getInstance(modalEl);
                    if (inst) inst.hide();
                }
                showToast('success', pendingWithdraw.isReject ? 'Membership rejected.' : 'Organization removed.');
                pendingWithdraw = null;
                loadRelationships();
            })
            .catch(function (err) { showToast('danger', 'Failed: ' + err.message); });
    }

    // ═══════════════════════════════════════════════════════════
    // Activation / deactivation
    // ═══════════════════════════════════════════════════════════
    function openActivateModal(relId, ceiling) {
        document.getElementById('orrActivateReason').value = '';
        var minutesInput = document.getElementById('orrActivateMinutes');
        minutesInput.value = ceiling ? ceiling : '';
        if (ceiling) {
            minutesInput.setAttribute('max', ceiling);
            document.getElementById('orrActivateCeilingNote').textContent = 'Ceiling for this relationship: ' + ceiling + ' minutes (the server clamps to this regardless of what is entered here).';
        } else {
            minutesInput.removeAttribute('max');
            document.getElementById('orrActivateCeilingNote').textContent = 'No ceiling configured — leave blank for manual-deactivation-only, or set a duration.';
        }
        hideActivateError();
        var modalEl = document.getElementById('orrActivateModal');
        modalEl.setAttribute('data-rel-id', relId);
        if (modalEl && window.bootstrap) bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }

    function confirmActivate() {
        var modalEl = document.getElementById('orrActivateModal');
        var relId = parseInt(modalEl.getAttribute('data-rel-id'), 10);
        var reason = document.getElementById('orrActivateReason').value.trim();
        var minutesVal = document.getElementById('orrActivateMinutes').value;
        var ceilingAttr = document.getElementById('orrActivateMinutes').getAttribute('max');
        var minutes = minutesVal ? parseInt(minutesVal, 10) : null;
        // Client-side clamp is UX only — server re-clamps authoritatively.
        if (minutes !== null && ceilingAttr && minutes > parseInt(ceilingAttr, 10)) minutes = parseInt(ceilingAttr, 10);

        postJson({ action: 'activate', relationship_id: relId, reason: reason || undefined, max_activation_minutes: minutes, csrf_token: csrfToken })
            .then(function (resp) {
                if (resp.error) { showActivateError(resp.error); return; }
                var inst = window.bootstrap ? bootstrap.Modal.getInstance(modalEl) : null;
                if (inst) inst.hide();
                showToast('success', 'Relationship activated.');
                loadRelationships();
            })
            .catch(function (err) { showActivateError('Failed: ' + err.message); });
    }

    function openDeactivateModal(relId) {
        pendingDeactivateRelId = relId;
        var modalEl = document.getElementById('orrDeactivateModal');
        if (modalEl && window.bootstrap) bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }

    function confirmDeactivate() {
        if (!pendingDeactivateRelId) return;
        postJson({ action: 'deactivate', relationship_id: pendingDeactivateRelId, csrf_token: csrfToken })
            .then(function (resp) {
                if (resp.error) { showToast('danger', resp.error); return; }
                var modalEl = document.getElementById('orrDeactivateModal');
                if (modalEl && window.bootstrap) {
                    var inst = bootstrap.Modal.getInstance(modalEl);
                    if (inst) inst.hide();
                }
                showToast('success', 'Relationship deactivated.');
                pendingDeactivateRelId = 0;
                loadRelationships();
            })
            .catch(function (err) { showToast('danger', 'Failed: ' + err.message); });
    }

    // ═══════════════════════════════════════════════════════════
    // Events
    // ═══════════════════════════════════════════════════════════
    function bindEvents() {
        var btnNew = document.getElementById('orrBtnNew');
        if (btnNew) btnNew.addEventListener('click', openProposeModal);
        var btnSubmitPropose = document.getElementById('orrBtnSubmitPropose');
        if (btnSubmitPropose) btnSubmitPropose.addEventListener('click', submitPropose);

        var btnBack = document.getElementById('orrBtnBack');
        if (btnBack) btnBack.addEventListener('click', closeDetail);

        var listRows = document.getElementById('orrListRows');
        if (listRows) {
            listRows.addEventListener('click', function (ev) {
                var openBtn = ev.target.closest('[data-open-detail]');
                if (openBtn) { ev.preventDefault(); openDetail(openBtn.getAttribute('data-open-detail')); return; }
                var quickActivate = ev.target.closest('[data-quick-activate]');
                if (quickActivate) {
                    var relId = parseInt(quickActivate.getAttribute('data-quick-activate'), 10);
                    var rel = findRelationship(relId);
                    openActivateModal(relId, rel ? rel.max_activation_minutes : null);
                }
            });
        }

        var btnAddMember = document.getElementById('orrBtnAddMember');
        if (btnAddMember) btnAddMember.addEventListener('click', addMember);

        var memberRows = document.getElementById('orrMemberRows');
        if (memberRows) {
            memberRows.addEventListener('click', function (ev) {
                var approveBtn = ev.target.closest('[data-approve-member]');
                if (approveBtn) { approveMember(parseInt(approveBtn.getAttribute('data-approve-member'), 10)); return; }
                var withdrawBtn = ev.target.closest('[data-withdraw-member]');
                if (withdrawBtn) {
                    openWithdrawModal(
                        parseInt(withdrawBtn.getAttribute('data-withdraw-member'), 10),
                        withdrawBtn.getAttribute('data-org-name'),
                        parseInt(withdrawBtn.getAttribute('data-rel-id'), 10),
                        withdrawBtn.getAttribute('data-is-reject') === '1'
                    );
                }
            });
        }

        var withdrawInput = document.getElementById('orrWithdrawConfirmInput');
        if (withdrawInput) {
            withdrawInput.addEventListener('input', function () {
                var expected = pendingWithdraw ? pendingWithdraw.orgName : '';
                document.getElementById('orrBtnConfirmWithdraw').disabled = (withdrawInput.value !== expected);
            });
        }
        var btnConfirmWithdraw = document.getElementById('orrBtnConfirmWithdraw');
        if (btnConfirmWithdraw) btnConfirmWithdraw.addEventListener('click', confirmWithdraw);

        var activationBlock = document.getElementById('orrActivationBlock');
        if (activationBlock) {
            activationBlock.addEventListener('click', function (ev) {
                var activateBtn = ev.target.closest('#orrBtnActivate');
                if (activateBtn) { openActivateModal(parseInt(activateBtn.getAttribute('data-rel-id'), 10), activateBtn.getAttribute('data-ceiling') ? parseInt(activateBtn.getAttribute('data-ceiling'), 10) : null); return; }
                var deactivateBtn = ev.target.closest('#orrBtnDeactivate');
                if (deactivateBtn) { openDeactivateModal(parseInt(deactivateBtn.getAttribute('data-rel-id'), 10)); return; }
            });
        }

        var btnConfirmActivate = document.getElementById('orrBtnConfirmActivate');
        if (btnConfirmActivate) btnConfirmActivate.addEventListener('click', confirmActivate);
        var btnConfirmDeactivate = document.getElementById('orrBtnConfirmDeactivate');
        if (btnConfirmDeactivate) btnConfirmDeactivate.addEventListener('click', confirmDeactivate);
    }

    // ═══════════════════════════════════════════════════════════
    // Utilities
    // ═══════════════════════════════════════════════════════════
    function postJson(payload) {
        return fetch('api/org-relationships.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        }).then(function (r) { return r.json(); });
    }

    function showToast(type, message) {
        var area = document.getElementById('orrToast');
        if (!area) return;
        area.className = 'alert alert-' + type;
        area.textContent = message;
        area.classList.remove('d-none');
        setTimeout(function () { area.classList.add('d-none'); }, 5000);
    }

    function showProposeError(message) {
        var el = document.getElementById('orrProposeError');
        if (!el) return;
        el.textContent = message;
        el.classList.remove('d-none');
    }
    function hideProposeError() {
        var el = document.getElementById('orrProposeError');
        if (el) el.classList.add('d-none');
    }

    function showDetailError(message) {
        var el = document.getElementById('orrDetailError');
        if (!el) return;
        el.textContent = message;
        el.classList.remove('d-none');
    }
    function hideDetailError() {
        var el = document.getElementById('orrDetailError');
        if (el) el.classList.add('d-none');
    }

    function showActivateError(message) {
        var el = document.getElementById('orrActivateError');
        if (!el) return;
        el.textContent = message;
        el.classList.remove('d-none');
    }
    function hideActivateError() {
        var el = document.getElementById('orrActivateError');
        if (el) el.classList.add('d-none');
    }

    function escHtml(str) {
        if (!str && str !== 0) return '';
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(String(str)));
        return div.innerHTML;
    }

    function escAttr(str) {
        if (!str && str !== 0) return '';
        return String(str).replace(/&/g, '&amp;').replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }
})();
