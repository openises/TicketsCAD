/**
 * Public Incident Board admin controller (Phase 138, Section G).
 *
 * Drives public-board-admin.php against api/public-board-admin.php. ES5 IIFE,
 * no build step, per project convention. window.PB_IS_BOARD_ADMIN /
 * window.PB_IS_ORG_SELF (set inline by the PHP page) are DISPLAY hints only —
 * every write still goes through the server's own independent RBAC + org
 * checks (see api/public-board-admin.php and pb_resolve_admin_write_org() in
 * inc/public-board.php). This file never assumes a fetch will succeed just
 * because a panel is visible.
 *
 * User-sourced strings (org names, incident type names/groups, stub labels)
 * are rendered via textContent/DOM methods, never innerHTML, matching the
 * defensive posture this codebase uses on every surface that displays
 * dispatcher-entered free text.
 */
(function () {
    'use strict';

    var API = 'api/public-board-admin.php';

    function csrf() {
        var el = document.getElementById('csrfToken');
        return el ? el.value : '';
    }

    function getJson(action) {
        return fetch(API + '?action=' + encodeURIComponent(action), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); });
    }
    function postJson(action, payload) {
        payload = payload || {};
        payload.action = action;
        payload.csrf_token = csrf();
        return fetch(API, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        }).then(function (r) { return r.json(); });
    }

    function toast(msg, kind) {
        var el = document.getElementById('pbToast');
        if (!el) return;
        el.className = 'alert alert-' + (kind || 'info');
        el.textContent = msg;
        el.classList.remove('d-none');
        window.setTimeout(function () { el.classList.add('d-none'); }, 5000);
    }

    function val(id) { var e = document.getElementById(id); return e ? e.value : ''; }
    function setVal(id, v) { var e = document.getElementById(id); if (e) e.value = (v === null || v === undefined) ? '' : v; }
    function checked(id) { var e = document.getElementById(id); return !!(e && e.checked); }
    function setChecked(id, v) { var e = document.getElementById(id); if (e) e.checked = !!v; }
    function setText(id, v) { var e = document.getElementById(id); if (e) e.textContent = v; }

    // ═══════════════════════════════════════════════════════════════════
    //  Panel 1 — master switch + address precision
    // ═══════════════════════════════════════════════════════════════════

    // Client-side preview only — reimplements pb_round_coords()'s rounding
    // table (inc/public-board.php) against a fixed example point/address so
    // an admin sees the effect of the selected precision level immediately.
    // NEVER the authority: the server always re-applies this rule for real
    // incidents, and a per-incident Security Label may cap it further.
    function pbRoundCoordsPreview(lat, lng, level) {
        switch (level) {
            case 'exact':  return { lat: lat, lng: lng };
            case 'block':  return { lat: Math.round(lat * 1000) / 1000, lng: Math.round(lng * 1000) / 1000 };
            case 'city':   return { lat: Math.round(lat * 100) / 100, lng: Math.round(lng * 100) / 100 };
            case 'hidden': return { lat: null, lng: null };
            default:       return { lat: null, lng: null };
        }
    }
    function pbStreetNameOnlyPreview(street) {
        return street.replace(/^\s*[0-9][0-9A-Za-z\-\/]*\s+/, '').trim();
    }
    function updatePrecisionPreview() {
        var level = val('pbPrecision');
        var exLat = 44.9778, exLng = -93.2650, exStreet = '123 Main St', exCity = 'your deployment', exState = 'MN';
        var coords = pbRoundCoordsPreview(exLat, exLng, level);
        var streetPart;
        switch (level) {
            case 'exact': streetPart = exStreet + ', ' + exCity + ', ' + exState; break;
            case 'block': streetPart = pbStreetNameOnlyPreview(exStreet) + ', ' + exCity + ', ' + exState; break;
            case 'city':
            case 'hidden':
            default:      streetPart = exCity + ', ' + exState; break;
        }
        var coordPart = (coords.lat !== null)
            ? ' (' + coords.lat + ', ' + coords.lng + ')'
            : (level === 'hidden' ? ' — no map pin' : '');
        setText('pbPrecisionPreview', streetPart + coordPart);
    }

    var lastLoadedEnabled = false; // tracks off/on transitions for the pre-enable gate

    function loadSettings() {
        return getJson('settings').then(function (res) {
            if (res.error) { toast(res.error, 'danger'); return; }
            var c = res.settings || {};
            lastLoadedEnabled = c.public_board_enabled === '1';
            setChecked('pbEnabled', lastLoadedEnabled);
            setVal('pbPrecision', c.public_board_address_precision || 'block');
            setVal('pbDelay', c.public_board_default_delay_secs !== null && c.public_board_default_delay_secs !== undefined
                ? c.public_board_default_delay_secs : '90');
            setVal('pbRlRequests', c.public_board_rate_limit_requests || '30');
            setVal('pbRlWindow', c.public_board_rate_limit_window_secs || '60');
            excludedGroupsRaw = c.public_board_excluded_groups || '';
            updateMasterBadge(lastLoadedEnabled);
            updatePrecisionPreview();
            renderExcludedGroupsSelect(); // in case types already loaded first
        });
    }

    function updateMasterBadge(enabled) {
        var el = document.getElementById('pbMasterBadge');
        if (!el) return;
        el.textContent = enabled ? 'shared board: ON' : 'shared board: off';
        el.className = 'badge ms-3 ' + (enabled ? 'bg-success' : 'bg-secondary');
    }

    // Reads every board-wide setting field currently in the DOM (across
    // panels 1/3/4 — there is only ONE combined settings row server-side,
    // save_settings always writes all six keys together) and POSTs it.
    // $ack === true sends the pre-enable acknowledgment flag.
    function postSettings(ack) {
        var payload = {
            public_board_enabled: checked('pbEnabled') ? 1 : 0,
            public_board_address_precision: val('pbPrecision') || 'block',
            public_board_default_delay_secs: val('pbDelay') || '90',
            public_board_excluded_groups: currentExcludedGroupsCsv(),
            public_board_rate_limit_requests: val('pbRlRequests') || '30',
            public_board_rate_limit_window_secs: val('pbRlWindow') || '60'
        };
        if (ack) payload.public_board_ack_sensitive_types = 1;
        return postJson('save_settings', payload).then(function (res) {
            if (res.error) {
                toast(res.error, 'danger');
                // Race-condition case: the client-side pre-check passed but
                // the server's independent re-check found sensitive types
                // still Full (e.g. another admin edited a type in between).
                // Re-open the modal with fresh data rather than leaving the
                // admin stuck on a rejected save with no obvious next step.
                if (res.error.indexOf('sensitive') !== -1) {
                    showSensitiveModal();
                }
                return res;
            }
            toast('Settings saved.', 'success');
            lastLoadedEnabled = checked('pbEnabled');
            updateMasterBadge(lastLoadedEnabled);
            return res;
        });
    }

    // pendingSensitiveConfirm holds the callback the modal's "Enable board"
    // button runs when the admin acknowledges — set fresh by whichever
    // caller opened the modal (the master switch or an org row's own
    // enable toggle; value/mission review finding #1, 2026-08-13: this
    // modal used to be wired ONLY to the master switch's save, even though
    // an org's own board exposes the exact same shared in_types data).
    var pendingSensitiveConfirm = null;

    function showSensitiveModal(onConfirm) {
        getJson('sensitive_types').then(function (res) {
            var list = (res && res.types) ? res.types : [];
            var listEl = document.getElementById('pbSensitiveList');
            var introEl = document.getElementById('pbSensitiveIntro');
            var ackEl = document.getElementById('pbSensitiveAck');
            var confirmEl = document.getElementById('pbSensitiveConfirm');
            listEl.textContent = '';
            list.forEach(function (t) {
                var li = document.createElement('li');
                li.textContent = t.type + (t.group ? ' (' + t.group + ')' : '');
                listEl.appendChild(li);
            });
            introEl.textContent = list.length + ' incident type(s) that look medical/sensitive are still set '
                + 'to Full visibility on the public board:';
            ackEl.checked = false;
            confirmEl.disabled = true;
            pendingSensitiveConfirm = onConfirm;
            var modalEl = document.getElementById('pbSensitiveModal');
            var modal = window.bootstrap ? window.bootstrap.Modal.getOrCreateInstance(modalEl) : null;
            if (modal) modal.show();
        });
    }

    // Shared off->on pre-check (tasks.md G1b) — UX convenience only; the
    // server independently re-checks this exact condition on EVERY write
    // that flips a public-board switch on, regardless of what happens
    // here (both save_settings and save_organization — see
    // api/public-board-admin.php). `checkFn` is only called when
    // transitioning off->on; `saveFn(ack)` performs the actual save.
    function withSensitivePreCheck(wasEnabled, willEnable, saveFn) {
        if (!wasEnabled && willEnable) {
            getJson('sensitive_types').then(function (res) {
                var list = (res && res.types) ? res.types : [];
                if (list.length > 0) {
                    showSensitiveModal(function () { return saveFn(true); });
                } else {
                    saveFn(false);
                }
            });
        } else {
            saveFn(false);
        }
    }

    function saveMasterSettings() {
        withSensitivePreCheck(lastLoadedEnabled, checked('pbEnabled'), postSettings);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  Panel 2 — Organizations
    // ═══════════════════════════════════════════════════════════════════

    function baseUrlForPublicBoard() {
        return window.location.origin
            + window.location.pathname.replace(/public-board-admin\.php.*$/, 'public-board.php');
    }

    function loadOrganizations() {
        return getJson('organizations').then(function (res) {
            var tbody = document.getElementById('pbOrgRows');
            if (!tbody) return;
            tbody.textContent = '';
            if (res.error) {
                var tr = document.createElement('tr');
                var td = document.createElement('td');
                td.colSpan = 6; td.className = 'text-danger'; td.textContent = res.error;
                tr.appendChild(td); tbody.appendChild(tr);
                return;
            }
            var orgs = res.organizations || [];
            if (orgs.length === 0) {
                var tr2 = document.createElement('tr');
                var td2 = document.createElement('td');
                td2.colSpan = 6; td2.className = 'text-body-secondary'; td2.textContent = 'No organizations found.';
                tr2.appendChild(td2); tbody.appendChild(tr2);
                return;
            }
            orgs.forEach(function (o) { tbody.appendChild(buildOrgRow(o)); });
        });
    }

    function buildOrgRow(o) {
        var tr = document.createElement('tr');
        tr.setAttribute('data-org-id', o.id);

        var tdName = document.createElement('td');
        var nameDiv = document.createElement('div');
        nameDiv.textContent = o.name || ('Org #' + o.id);
        tdName.appendChild(nameDiv);
        var enabled = String(o.public_board_enabled) === '1';
        var count = (o.open_incident_count !== undefined && o.open_incident_count !== null)
            ? parseInt(o.open_incident_count, 10) : null;
        if (enabled && count === 0) {
            // §7 health-check "check 3" diagnostic, surfaced inline here
            // (Section H's automated probe ships later — this panel already
            // has the data it needs from the organizations GET response).
            var diag = document.createElement('div');
            diag.className = 'small text-warning';
            diag.textContent = '0 open incidents currently tagged to this organization.';
            tdName.appendChild(diag);
        }
        tr.appendChild(tdName);

        var tdEnabled = document.createElement('td');
        var swWrap = document.createElement('div');
        swWrap.className = 'form-check form-switch';
        var sw = document.createElement('input');
        sw.type = 'checkbox'; sw.className = 'form-check-input pb-org-enabled';
        sw.checked = enabled;
        swWrap.appendChild(sw);
        tdEnabled.appendChild(swWrap);
        tr.appendChild(tdEnabled);

        var tdSlug = document.createElement('td');
        var slugInput = document.createElement('input');
        slugInput.type = 'text';
        slugInput.className = 'form-control form-control-sm pb-org-slug';
        slugInput.placeholder = 'e.g. your-server';
        slugInput.pattern = '[a-z0-9-]+';
        slugInput.value = o.public_board_slug || '';
        tdSlug.appendChild(slugInput);
        tr.appendChild(tdSlug);

        var tdUrl = document.createElement('td');
        var urlSpan = document.createElement('span');
        urlSpan.className = 'small font-monospace pb-org-url';
        updateOrgUrlSpan(urlSpan, slugInput.value);
        tdUrl.appendChild(urlSpan);
        tr.appendChild(tdUrl);

        var tdCopy = document.createElement('td');
        var copyBtn = document.createElement('button');
        copyBtn.type = 'button';
        copyBtn.className = 'btn btn-sm btn-outline-secondary';
        copyBtn.title = 'Copy URL';
        copyBtn.innerHTML = '<i class="bi bi-clipboard"></i>';
        copyBtn.addEventListener('click', function () {
            var url = urlSpan.textContent;
            if (url && navigator.clipboard) {
                navigator.clipboard.writeText(url).then(function () { toast('URL copied.', 'success'); });
            }
        });
        tdCopy.appendChild(copyBtn);
        tr.appendChild(tdCopy);

        var tdSave = document.createElement('td');
        var saveBtn = document.createElement('button');
        saveBtn.type = 'button';
        saveBtn.className = 'btn btn-sm btn-primary';
        saveBtn.textContent = 'Save';
        saveBtn.addEventListener('click', function () { saveOrgRow(o.id, sw, slugInput, urlSpan, enabled); });
        tdSave.appendChild(saveBtn);
        tr.appendChild(tdSave);

        slugInput.addEventListener('input', function () { updateOrgUrlSpan(urlSpan, slugInput.value); });
        sw.addEventListener('change', function () { updateOrgUrlSpan(urlSpan, slugInput.value, sw.checked); });

        return tr;
    }

    function updateOrgUrlSpan(span, slug, enabledOverride) {
        var enabled = enabledOverride;
        if (enabled === undefined) enabled = true; // default preview state while typing
        slug = (slug || '').trim();
        if (!slug) { span.textContent = '(no slug set)'; return; }
        span.textContent = (enabled ? '' : '(disabled) ') + baseUrlForPublicBoard() + '?org=' + slug;
    }

    // Value/mission review finding #1 (2026-08-13) — this used to POST
    // straight through with no sensitive-type pre-check at all, even
    // though an org's own board exposes the exact same shared in_types
    // data the master switch's Panel 1 modal already warns about. An Org
    // Admin (action.manage_public_board_org, no access to Panel 1/3) had
    // ZERO warning before publishing full detail on medical/DV/mental-
    // health-shaped incident types. `wasEnabled` is the state loaded from
    // the server (captured in buildOrgRow), not the current checkbox
    // value, so a toggle-off-then-on-again in the same edit still counts
    // as an off->on transition relative to what's actually saved.
    function saveOrgRow(orgId, switchEl, slugInput, urlSpan, wasEnabled) {
        var slug = (slugInput.value || '').trim().toLowerCase();
        slugInput.value = slug;
        var willEnable = switchEl.checked;

        function doSave(ack) {
            var payload = {
                org_id: orgId,
                public_board_enabled: willEnable ? 1 : 0,
                public_board_slug: slug
            };
            if (ack) payload.public_board_ack_sensitive_types = 1;
            return postJson('save_organization', payload).then(function (res) {
                if (res.error) {
                    toast(res.error, 'danger');
                    // Same race-condition handling as the master switch:
                    // the client-side pre-check passed but the server's
                    // independent re-check found sensitive types still
                    // Full (e.g. another admin/session edited a type
                    // between the check and this save).
                    if (res.error.indexOf('sensitive') !== -1) {
                        showSensitiveModal(function () { return doSave(true); });
                    }
                    return res;
                }
                toast('Organization board settings saved.', 'success');
                updateOrgUrlSpan(urlSpan, slug, willEnable);
                return res;
            });
        }

        withSensitivePreCheck(!!wasEnabled, willEnable, doSave);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  Panel 3 — Incident type rules
    // ═══════════════════════════════════════════════════════════════════

    var typesCache = [];
    var sensitiveIds = {};
    var excludedGroupsRaw = '';

    function loadSensitiveTypes() {
        return getJson('sensitive_types').then(function (res) {
            sensitiveIds = {};
            var list = (res && res.types) ? res.types : [];
            list.forEach(function (t) { sensitiveIds[String(t.id)] = true; });
        }).catch(function () { sensitiveIds = {}; });
    }

    function loadTypes() {
        return getJson('types').then(function (res) {
            if (res.error) { toast(res.error, 'danger'); return; }
            typesCache = res.types || [];
            return loadSensitiveTypes().then(function () {
                renderTypes();
                renderExcludedGroupsSelect();
            });
        });
    }

    function distinctGroups() {
        var seen = {};
        var out = [];
        typesCache.forEach(function (t) {
            var g = (t.group || '').trim();
            if (g && !seen[g]) { seen[g] = true; out.push(g); }
        });
        out.sort();
        return out;
    }

    function currentExcludedGroupsCsv() {
        var sel = document.getElementById('pbExcludedGroups');
        if (!sel) return excludedGroupsRaw;
        var chosen = [];
        for (var i = 0; i < sel.options.length; i++) {
            if (sel.options[i].selected) chosen.push(sel.options[i].value);
        }
        return chosen.join(',');
    }

    function renderExcludedGroupsSelect() {
        var sel = document.getElementById('pbExcludedGroups');
        if (!sel) return;
        var selectedNow = {};
        excludedGroupsRaw.split(',').forEach(function (g) {
            g = g.trim();
            if (g) selectedNow[g] = true;
        });
        // Preserve any in-progress selection the admin already made this
        // session (don't clobber it just because settings reloaded).
        for (var i = 0; i < sel.options.length; i++) {
            if (sel.options[i].selected) selectedNow[sel.options[i].value] = true;
        }
        sel.textContent = '';
        distinctGroups().forEach(function (g) {
            var opt = document.createElement('option');
            opt.value = g;
            opt.textContent = g;
            opt.selected = !!selectedNow[g];
            sel.appendChild(opt);
        });
    }

    function saveExcludedGroups() {
        postSettings(false);
    }

    function renderTypes() {
        var tbody = document.getElementById('pbTypeRows');
        if (!tbody) return;
        tbody.textContent = '';
        if (typesCache.length === 0) {
            var tr = document.createElement('tr');
            var td = document.createElement('td');
            td.colSpan = 6; td.className = 'text-body-secondary'; td.textContent = 'No incident types found.';
            tr.appendChild(td); tbody.appendChild(tr);
            return;
        }
        var groups = {};
        var groupOrder = [];
        typesCache.forEach(function (t) {
            var g = (t.group && t.group.trim()) ? t.group.trim() : '(Ungrouped)';
            if (!groups[g]) { groups[g] = []; groupOrder.push(g); }
            groups[g].push(t);
        });
        groupOrder.sort();
        groupOrder.forEach(function (g) {
            tbody.appendChild(buildGroupHeaderRow(g, groups[g]));
            groups[g].forEach(function (t) { tbody.appendChild(buildTypeRow(t)); });
        });
    }

    function buildGroupHeaderRow(groupName, rows) {
        var tr = document.createElement('tr');
        tr.className = 'table-secondary';
        var td = document.createElement('td');
        td.colSpan = 6;

        var label = document.createElement('strong');
        label.textContent = groupName + ' ';
        td.appendChild(label);

        var btnPresence = document.createElement('button');
        btnPresence.type = 'button';
        btnPresence.className = 'btn btn-sm btn-outline-warning ms-2';
        btnPresence.textContent = 'Mark group Presence-only';
        btnPresence.addEventListener('click', function () { bulkMarkGroup(groupName, rows, 'presence_only'); });
        td.appendChild(btnPresence);

        var btnNever = document.createElement('button');
        btnNever.type = 'button';
        btnNever.className = 'btn btn-sm btn-outline-danger ms-2';
        btnNever.textContent = 'Mark group Never-publish';
        btnNever.addEventListener('click', function () { bulkMarkGroup(groupName, rows, 'never_publish'); });
        td.appendChild(btnNever);

        tr.appendChild(td);
        return tr;
    }

    function bulkMarkGroup(groupName, rows, mode) {
        var label = mode === 'presence_only' ? 'Presence-only' : 'Never-publish';
        if (!window.confirm('This will change ' + rows.length + ' incident type(s) in "' + groupName
            + '" to ' + label + '. Continue?')) {
            return;
        }
        var calls = rows.map(function (t) {
            var payload = {
                id: t.id,
                public_board_never_publish: mode === 'never_publish' ? 1 : (String(t.public_board_never_publish) === '1' ? 1 : 0),
                public_board_visibility: mode === 'presence_only' ? 'presence_only' : (t.public_board_visibility || 'full'),
                public_board_publish_delay_secs: t.public_board_publish_delay_secs,
                public_board_stub_label: t.public_board_stub_label
            };
            return postJson('save_type', payload);
        });
        Promise.all(calls).then(function (results) {
            var failed = results.filter(function (r) { return r && r.error; }).length;
            if (failed > 0) {
                toast(failed + ' of ' + rows.length + ' type(s) failed to save.', 'danger');
            } else {
                toast('Updated ' + rows.length + ' incident type(s).', 'success');
            }
            loadTypes();
        });
    }

    function buildTypeRow(t) {
        var tr = document.createElement('tr');
        tr.setAttribute('data-type-id', t.id);
        var isFull = (t.public_board_visibility || 'full') === 'full';
        if (sensitiveIds[String(t.id)] && isFull) {
            tr.classList.add('table-warning');
        }

        var tdName = document.createElement('td');
        tdName.textContent = t.type || ('#' + t.id);
        tr.appendChild(tdName);

        var tdNever = document.createElement('td');
        tdNever.className = 'text-center';
        var neverCb = document.createElement('input');
        neverCb.type = 'checkbox';
        neverCb.className = 'form-check-input pb-type-never';
        neverCb.checked = String(t.public_board_never_publish) === '1';
        tdNever.appendChild(neverCb);
        tr.appendChild(tdNever);

        var tdDelay = document.createElement('td');
        var delayInput = document.createElement('input');
        delayInput.type = 'number';
        delayInput.min = '0';
        delayInput.className = 'form-control form-control-sm pb-type-delay';
        delayInput.style.maxWidth = '110px';
        delayInput.placeholder = 'board default';
        if (t.public_board_publish_delay_secs !== null && t.public_board_publish_delay_secs !== undefined) {
            delayInput.value = t.public_board_publish_delay_secs;
        }
        tdDelay.appendChild(delayInput);
        tr.appendChild(tdDelay);

        var tdVis = document.createElement('td');
        var visName = 'pbVis' + t.id;
        var visibility = t.public_board_visibility || 'full';
        var stubInputRef; // set below, referenced by the radio handler

        function makeRadio(value, labelText) {
            var wrap = document.createElement('div');
            wrap.className = 'form-check form-check-inline';
            var radio = document.createElement('input');
            radio.type = 'radio';
            radio.className = 'form-check-input pb-type-vis';
            radio.name = visName;
            radio.value = value;
            radio.checked = visibility === value;
            var lbl = document.createElement('label');
            lbl.className = 'form-check-label';
            lbl.textContent = labelText;
            var id = 'pbVis' + t.id + '_' + value;
            radio.id = id;
            lbl.setAttribute('for', id);
            wrap.appendChild(radio);
            wrap.appendChild(lbl);
            radio.addEventListener('change', function () {
                if (stubInputRef) stubInputRef.disabled = (radio.value !== 'presence_only');
            });
            return wrap;
        }

        tdVis.appendChild(makeRadio('full', 'Full'));
        tdVis.appendChild(makeRadio('presence_only', 'Presence-only'));
        tr.appendChild(tdVis);

        var tdStub = document.createElement('td');
        var stubInput = document.createElement('input');
        stubInput.type = 'text';
        stubInput.maxLength = 64;
        stubInput.className = 'form-control form-control-sm pb-type-stub';
        stubInput.placeholder = 'Response';
        stubInput.value = t.public_board_stub_label || '';
        stubInput.disabled = visibility !== 'presence_only';
        stubInputRef = stubInput;
        tdStub.appendChild(stubInput);
        tr.appendChild(tdStub);

        var tdSave = document.createElement('td');
        var saveBtn = document.createElement('button');
        saveBtn.type = 'button';
        saveBtn.className = 'btn btn-sm btn-outline-primary';
        saveBtn.title = 'Save this incident type';
        saveBtn.innerHTML = '<i class="bi bi-save"></i>';
        saveBtn.addEventListener('click', function () {
            var visEl = tr.querySelector('.pb-type-vis:checked');
            var delayVal = delayInput.value.trim();
            postJson('save_type', {
                id: t.id,
                public_board_never_publish: neverCb.checked ? 1 : 0,
                public_board_publish_delay_secs: delayVal === '' ? null : parseInt(delayVal, 10),
                public_board_visibility: visEl ? visEl.value : 'full',
                public_board_stub_label: stubInput.value
            }).then(function (res) {
                if (res.error) { toast(res.error, 'danger'); return; }
                toast('Saved "' + (t.type || ('#' + t.id)) + '".', 'success');
                loadTypes();
            });
        });
        tdSave.appendChild(saveBtn);
        tr.appendChild(tdSave);

        return tr;
    }

    // ═══════════════════════════════════════════════════════════════════
    //  Panel 4 — Rate limiting (shares postSettings with panel 1)
    // ═══════════════════════════════════════════════════════════════════

    function saveRateLimits() {
        postSettings(false);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  Wiring
    // ═══════════════════════════════════════════════════════════════════

    function on(id, ev, fn) {
        var el = document.getElementById(id);
        if (el) el.addEventListener(ev, fn);
    }

    document.addEventListener('DOMContentLoaded', function () {
        on('pbPrecision', 'change', updatePrecisionPreview);
        on('pbSaveMaster', 'click', saveMasterSettings);
        on('pbSaveExcluded', 'click', saveExcludedGroups);
        on('pbSaveRate', 'click', saveRateLimits);
        on('pbSensitiveAck', 'change', function () {
            var confirmEl = document.getElementById('pbSensitiveConfirm');
            if (confirmEl) confirmEl.disabled = !this.checked;
        });
        on('pbSensitiveConfirm', 'click', function () {
            var modalEl = document.getElementById('pbSensitiveModal');
            var modal = window.bootstrap ? window.bootstrap.Modal.getInstance(modalEl) : null;
            var confirmFn = pendingSensitiveConfirm;
            if (!confirmFn) return;
            confirmFn().then(function (res) {
                if (!res || !res.error) {
                    if (modal) modal.hide();
                }
            });
        });

        if (window.PB_IS_BOARD_ADMIN) {
            loadSettings();
            loadTypes();
        }
        loadOrganizations();
    });
})();
