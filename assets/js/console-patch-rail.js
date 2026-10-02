/**
 * NewUI v4.0 — Console rebuild: the patch rail (Phase 152)
 *
 * A persistent bar above the strip bank listing every ACTIVE standing
 * patch/group (api/matrix.php's comm_routes — never the per-session
 * ephemeral browser-leg routes api/console-patch.php manages, which are
 * personal to one operator's own mic and already visible on their own
 * strip's Matrix Audio toggle). Live via the comm:route_... and
 * comm:group_... SSE events (assets/js/event-bus.js's SSE_TYPES) —
 * never polling.
 *
 * Creation is checkbox-select + one confirm button (5-persona review,
 * unanimous rejection of drag-to-patch): a screen.console holder with
 * action.patch_create checks 2+ strips' "patch" checkboxes, a "Couple N
 * Selected" button appears, one click creates either a single route (2
 * channels) or a group (3+, via api/matrix.php's group_create). Breaking
 * is instant, one click, no confirmation — always fails toward LESS
 * connectivity, per the same review.
 *
 * Patch badges (tpl.show.patchchips, previously a disabled "future"
 * checkbox in the designer) render on any strip currently a member of an
 * active route/group — the real backing feature that show-flag existed
 * for, now wired up in the same commit that builds it, per this
 * project's own standing discipline against offering a control with no
 * backend.
 *
 * ES5 IIFE — no arrow functions, no let/const, no template literals.
 */
(function () {
    'use strict';

    var API = 'api/matrix.php';
    var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
    var canPatch = document.body.getAttribute('data-can-patch') === '1';

    var railEl = document.getElementById('consolePatchRail');
    if (!railEl) { return; } // console.php must render the container

    var routes = {};   // id -> route row (matrix_route_full() shape)
    var selected = {}; // channelId (string) -> true, while picking a coupling

    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) { n.className = cls; }
        if (text !== undefined && text !== null) { n.textContent = text; }
        return n;
    }

    function post(payload, cb) {
        payload.csrf_token = csrf;
        fetch(API, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        }).then(function (r) { return r.json(); }).then(function (j) { if (cb) { cb(j); } })
            .catch(function () { if (cb) { cb({ ok: false, error: 'network error' }); } });
    }

    function fmtCountdown(expiresAt) {
        if (!expiresAt) { return null; }
        var t = new Date(String(expiresAt).replace(' ', 'T')).getTime();
        var s = Math.round((t - Date.now()) / 1000);
        if (s <= 0) { return 'expired'; }
        if (s < 3600) { return Math.ceil(s / 60) + 'm left'; }
        return Math.floor(s / 3600) + 'h ' + Math.round((s % 3600) / 60) + 'm left';
    }

    // channelId (string or number) -> true if it's the src or dst of ANY
    // currently-active route. Consulted by console.js's renderStrip() for
    // the patchchips badge.
    function isPatched(channelId) {
        var id = String(channelId);
        for (var rid in routes) {
            if (!Object.prototype.hasOwnProperty.call(routes, rid)) { continue; }
            var r = routes[rid];
            if (String(r.src_channel_id) === id || String(r.dst_channel_id) === id) { return true; }
        }
        return false;
    }

    function breakOne(routeId) {
        post({ action: 'delete', id: routeId }, function (j) {
            if (!j || !j.ok) { window.alert((j && j.error) || 'Break failed'); }
            // No local mutation needed here — the comm:route_removed SSE
            // event this same call triggers is what actually updates the
            // rail, exactly like every other operator's rail updates.
        });
    }

    function breakGroup(groupId) {
        post({ action: 'group_break', group_id: groupId }, function (j) {
            if (!j || !j.ok) { window.alert((j && j.error) || 'Break failed'); }
        });
    }

    function render() {
        railEl.innerHTML = '';
        var byGroup = {};
        var standalone = [];
        for (var id in routes) {
            if (!Object.prototype.hasOwnProperty.call(routes, id)) { continue; }
            var r = routes[id];
            if (r.group_id) {
                (byGroup[r.group_id] = byGroup[r.group_id] || []).push(r);
            } else {
                standalone.push(r);
            }
        }
        var any = standalone.length > 0 || Object.keys(byGroup).length > 0;
        railEl.classList.toggle('d-none', !any && !Object.keys(selected).length);

        for (var i = 0; i < standalone.length; i++) {
            (function (r) {
                var chip = el('div', 'console-patch-chip' + (r.allow_cross_class ? ' console-patch-chip-crossclass' : ''));
                var label = (r.src_label || r.src_channel_id) + ' → ' + (r.dst_label || r.dst_channel_id);
                chip.appendChild(el('span', 'console-patch-chip-label', label));
                if (r.allow_cross_class) {
                    var cs = el('span', 'badge text-bg-warning ms-1', r.operator_callsign || 'cross-class');
                    cs.title = 'Cross-class override — FCC Part 97.113';
                    chip.appendChild(cs);
                }
                var cd = fmtCountdown(r.expires_at);
                if (cd) { chip.appendChild(el('span', 'console-patch-chip-countdown ms-1', cd)); }
                if (canPatch) {
                    var brk = el('button', 'btn btn-sm btn-outline-danger console-patch-break', null);
                    brk.type = 'button';
                    brk.title = 'Break this patch';
                    brk.appendChild(el('i', 'bi bi-x-lg'));
                    brk.addEventListener('click', function () { breakOne(r.id); });
                    chip.appendChild(brk);
                }
                railEl.appendChild(chip);
            })(standalone[i]);
        }
        for (var gid in byGroup) {
            if (!Object.prototype.hasOwnProperty.call(byGroup, gid)) { continue; }
            (function (gid, legs) {
                var names = {};
                for (var k = 0; k < legs.length; k++) {
                    names[legs[k].src_label || legs[k].src_channel_id] = true;
                    names[legs[k].dst_label || legs[k].dst_channel_id] = true;
                }
                var chip = el('div', 'console-patch-chip console-patch-chip-group');
                chip.appendChild(el('i', 'bi bi-diagram-3 me-1'));
                chip.appendChild(el('span', 'console-patch-chip-label', Object.keys(names).join(' + ')));
                var cd = fmtCountdown(legs[0].expires_at);
                if (cd) { chip.appendChild(el('span', 'console-patch-chip-countdown ms-1', cd)); }
                if (canPatch) {
                    var brk = el('button', 'btn btn-sm btn-outline-danger console-patch-break', null);
                    brk.type = 'button';
                    brk.title = 'Break this group';
                    brk.appendChild(el('i', 'bi bi-x-lg'));
                    brk.addEventListener('click', function () { breakGroup(gid); });
                    chip.appendChild(brk);
                }
                railEl.appendChild(chip);
            })(gid, byGroup[gid]);
        }

        renderConfirmBar();
    }

    // ── Checkbox-select creation flow ───────────────────────────────
    var confirmBar = null;
    function renderConfirmBar() {
        if (confirmBar) { confirmBar.parentNode.removeChild(confirmBar); confirmBar = null; }
        var ids = Object.keys(selected);
        if (ids.length < 2) { return; }
        confirmBar = el('div', 'console-patch-confirm-bar');
        confirmBar.appendChild(el('span', 'me-2 small', ids.length + ' channel(s) selected'));
        var btn = el('button', 'btn btn-sm btn-primary', 'Couple Selected');
        btn.type = 'button';
        btn.addEventListener('click', function () {
            btn.disabled = true;
            var chanIds = ids.map(function (s) { return parseInt(s, 10); });
            var action = chanIds.length === 2
                ? { action: 'create', src_channel_id: chanIds[0], dst_channel_id: chanIds[1] }
                : { action: 'group_create', channel_ids: chanIds };
            post(action, function (j) {
                btn.disabled = false;
                if (!j || !j.ok) {
                    var msg = (j && j.error) || 'Coupling failed';
                    // A regulatory refusal (cross-class, no override) is
                    // expected and common enough to surface plainly rather
                    // than as a generic alert.
                    window.alert(msg);
                    return;
                }
                selected = {};
                if (window.EventBus) { window.EventBus.emit('console:selection-clear'); }
                render();
            });
        });
        confirmBar.appendChild(btn);
        var cancel = el('button', 'btn btn-sm btn-outline-secondary ms-1', 'Cancel');
        cancel.type = 'button';
        cancel.addEventListener('click', function () {
            selected = {};
            if (window.EventBus) { window.EventBus.emit('console:selection-clear'); }
            render();
        });
        confirmBar.appendChild(cancel);
        railEl.parentNode.insertBefore(confirmBar, railEl.nextSibling);
    }

    function toggleSelect(channelId, want) {
        var id = String(channelId);
        if (want) { selected[id] = true; } else { delete selected[id]; }
        renderConfirmBar();
    }

    // ── Live updates ─────────────────────────────────────────────────
    function upsertRoute(r) { routes[r.id] = r; render(); if (window.EventBus) { window.EventBus.emit('console:patches-changed'); } }
    function removeRoute(id) { delete routes[id]; render(); if (window.EventBus) { window.EventBus.emit('console:patches-changed'); } }

    // event-bus.js is injected by inc/navbar.php's loadGlobal() as a
    // dynamically-created <script> — per spec that fetches/executes as
    // soon as it's ready, with NO ordering guarantee relative to this
    // page's own static <script> tags (defer has no effect on a script
    // inserted after the initial parse). A bare "if (window.EventBus)"
    // registered once at load time can silently never subscribe if this
    // file happens to finish loading first. Poll briefly rather than
    // assume — cheap, and only runs until the subscription succeeds once.
    (function waitForEventBus(triesLeft) {
        if (window.EventBus) {
            window.EventBus.on('comm:route_created', function (d) { if (d && d.route) { upsertRoute(d.route); } });
            window.EventBus.on('comm:route_updated', function (d) { if (d && d.route) { upsertRoute(d.route); } });
            window.EventBus.on('comm:route_removed', function (d) { if (d && d.route && d.route.id) { removeRoute(d.route.id); } });
            window.EventBus.on('comm:group_created', function (d) {
                if (d && d.routes) { for (var i = 0; i < d.routes.length; i++) { routes[d.routes[i].id] = d.routes[i]; } render(); }
            });
            window.EventBus.on('comm:group_removed', function (d) {
                if (!d || !d.group_id) { return; }
                for (var id in routes) {
                    if (Object.prototype.hasOwnProperty.call(routes, id) && String(routes[id].group_id) === String(d.group_id)) { delete routes[id]; }
                }
                render();
            });
            return;
        }
        if (triesLeft > 0) { setTimeout(function () { waitForEventBus(triesLeft - 1); }, 100); }
    })(50); // 5s ceiling — event-bus.js is a small, same-origin, cache-friendly file

    // Initial load — admins get the full list via GET; a plain Dispatcher
    // (action.patch_create only, no action.manage_matrix) is ALSO allowed
    // to GET as of this phase's RBAC widening (api/matrix.php), so this
    // works for every screen.console + action.patch_create holder, not
    // just admins.
    if (canPatch) {
        fetch(API).then(function (r) { return r.ok ? r.json() : null; }).then(function (j) {
            if (j && j.routes) {
                for (var i = 0; i < j.routes.length; i++) { routes[j.routes[i].id] = j.routes[i]; }
                render();
            }
        }).catch(function () { /* transient — live SSE events still arrive */ });
    }

    window.ConsolePatchRail = {
        isPatched: isPatched,
        canPatch: function () { return canPatch; },
        toggleSelect: toggleSelect,
        isSelected: function (channelId) { return !!selected[String(channelId)]; },
    };
})();
