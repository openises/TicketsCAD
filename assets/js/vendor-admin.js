/**
 * NewUI v4.0 - Service Providers admin (GH#148, Phase 155)
 *
 * Companies, rotation lists (with the computed next-up), service types, the rotation history (+ CSV) and the
 * dispatch settings. Backend: api/vendor-admin.php. Page: service-providers-admin.php.
 *
 * Rules: every server-authored string is written with textContent; every state change is a POST with the CSRF token
 * and is followed by a reload of the overview, so what the screen shows is always what the server holds; the
 * server enforces every rule (org scope, "no jumping to the front", "a company in the history can only be
 * retired") -- this file only reflects them (disabled buttons, explanatory text).
 */
(function () {
    'use strict';

    var csrf = '';
    var ov = null;               // the overview response
    var detail = null;           // the open list's detail response
    var selectedListId = 0;
    var facilities = null;
    var toastTimer = null;

    function $(id) { return document.getElementById(id); }

    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text !== undefined && text !== null) n.textContent = text;
        return n;
    }

    function clear(n) { while (n && n.firstChild) n.removeChild(n.firstChild); }
    function val(id) { var n = $(id); return n ? String(n.value).replace(/^\s+|\s+$/g, '') : ''; }
    function pad(n) { return (n < 10 ? '0' : '') + n; }

    function when(dt) {
        var m = /^(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2})/.exec(dt || '');
        return m ? (m[1] + ' ' + m[2]) : '';
    }

    function btn(label, cls, onClick, aria) {
        var b = el('button', 'btn btn-sm ' + cls, label);
        b.type = 'button';
        if (aria) b.setAttribute('aria-label', aria);
        b.addEventListener('click', onClick);
        return b;
    }

    function icon(name, extra) {
        var i = el('i', 'bi ' + name + (extra ? ' ' + extra : ''));
        i.setAttribute('aria-hidden', 'true');
        return i;
    }

    // ── network ──────────────────────────────────────────────────
    function parse(r) {
        return r.json().then(function (d) {
            if (d && typeof d === 'object') { d._http = r.status; return d; }
            return { error: 'Unexpected response', _http: r.status };
        }, function () { return { error: 'Unexpected response', _http: r.status }; });
    }

    function apiGet(query) {
        return fetch('api/vendor-admin.php?' + query, { credentials: 'same-origin' }).then(parse);
    }

    function apiPost(body) {
        body.csrf_token = csrf;
        return fetch('api/vendor-admin.php', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body)
        }).then(parse);
    }

    function toast(msg, isError) {
        var t = $('vpToast');
        t.textContent = msg;
        t.className = 'alert ' + (isError ? 'alert-danger' : 'alert-success');
        if (toastTimer) clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { t.className = 'alert d-none'; }, 6000);
    }

    /** POST, toast the outcome, reload the overview (and the open list). Resolves with the response. */
    function act(body, okMsg) {
        return apiPost(body).then(function (d) {
            if (d.error) { toast(d.error, true); return d; }
            if (okMsg) toast(okMsg, false);
            return reload().then(function () { return d; });
        }).catch(function () { toast('Could not reach the server. Nothing was changed.', true); return { error: 'network' }; });
    }

    function reload() {
        return apiGet('action=overview').then(function (d) {
            if (d.error) { toast(d.error, true); return; }
            ov = d;
            renderAll();
            if (selectedListId) return loadListDetail(selectedListId);
        });
    }

    // ── modals ───────────────────────────────────────────────────
    function showModal(id) {
        var m = $(id);
        if (window.bootstrap) window.bootstrap.Modal.getOrCreateInstance(m).show();
    }

    function hideModal(id) {
        var m = $(id);
        if (window.bootstrap) { var inst = window.bootstrap.Modal.getInstance(m); if (inst) inst.hide(); }
    }

    var promptCb = null;
    /** opts: title, message, date (bool), textLabel, textRequired (bool), okLabel */
    function askPrompt(opts, cb) {
        $('vpPromptTitle').textContent = opts.title;
        $('vpPromptMessage').textContent = opts.message || '';
        $('vpPromptDateWrap').classList.toggle('d-none', !opts.date);
        $('vpPromptTextWrap').classList.toggle('d-none', !opts.textLabel);
        $('vpPromptTextLabel').textContent = opts.textLabel || '';
        $('vpPromptText').value = '';
        $('vpPromptDate').value = '';
        $('vpPromptError').classList.add('d-none');
        $('vpBtnPromptOk').textContent = opts.okLabel || 'OK';
        promptCb = { opts: opts, cb: cb };
        showModal('vpPromptModal');
    }

    function promptOk() {
        if (!promptCb) return;
        var o = promptCb.opts;
        var text = val('vpPromptText');
        var date = val('vpPromptDate');
        var err = $('vpPromptError');
        if (o.date && !date) { err.textContent = 'Choose a date and time.'; err.classList.remove('d-none'); return; }
        if (o.textRequired && !text) { err.textContent = 'A reason is required.'; err.classList.remove('d-none'); return; }
        var cb = promptCb.cb;
        promptCb = null;
        hideModal('vpPromptModal');
        cb({ text: text, date: date });
    }

    // ═════════════════════════════════════════════════════════════
    // Companies tab
    // ═════════════════════════════════════════════════════════════
    function providerStatus(p) {
        var wrap = el('span');
        if (!p.is_active) { wrap.appendChild(el('span', 'badge text-bg-secondary', 'Retired')); return wrap; }
        if (p.suspended) {
            var b = el('span', 'badge text-bg-warning');
            b.appendChild(icon('bi-pause-circle', 'me-1'));
            b.appendChild(document.createTextNode('Suspended until ' + when(p.suspended_until)));
            wrap.appendChild(b);
            if (p.suspend_reason) wrap.appendChild(el('div', 'text-body-secondary small', p.suspend_reason));
            return wrap;
        }
        var ok = el('span', 'badge text-bg-success');
        ok.appendChild(icon('bi-check-circle', 'me-1'));
        ok.appendChild(document.createTextNode('Active'));
        wrap.appendChild(ok);
        return wrap;
    }

    function renderProviders() {
        var body = $('vpProviderRows');
        clear(body);
        if (!ov.providers.length) {
            var tr0 = el('tr');
            var td0 = el('td', 'text-body-secondary', 'No companies yet. Add one with "New company". A dispatcher can also log a call to a company that is not on any list.');
            td0.colSpan = 7;
            tr0.appendChild(td0);
            body.appendChild(tr0);
            return;
        }
        ov.providers.forEach(function (p) {
            var tr = el('tr');
            var tdName = el('td');
            tdName.appendChild(el('div', 'fw-semibold', p.name));
            if (p.contact_name) tdName.appendChild(el('div', 'text-body-secondary small', p.contact_name));
            tr.appendChild(tdName);
            var tdPhone = el('td', '', p.phone);
            if (p.phone_alt) { tdPhone.appendChild(el('br')); tdPhone.appendChild(document.createTextNode(p.phone_alt)); }
            tr.appendChild(tdPhone);
            var tdSvc = el('td');
            tdSvc.appendChild(document.createTextNode(p.services.length ? p.services.join(', ') : '—'));
            if (p.lists.length) tdSvc.appendChild(el('div', 'text-body-secondary small', p.lists.join(', ')));
            tr.appendChild(tdSvc);
            var tdSt = el('td');
            tdSt.appendChild(providerStatus(p));
            tr.appendChild(tdSt);
            tr.appendChild(el('td', '', p.org_name));
            tr.appendChild(el('td', 'text-body-secondary small', when(p.updated_at)));
            var tdAct = el('td', 'text-end text-nowrap');
            if (p.can_edit) {
                tdAct.appendChild(btn('Edit', 'btn-outline-primary', function () { openProviderModal(p); }, 'Edit ' + p.name));
                tdAct.appendChild(document.createTextNode(' '));
                if (p.is_active) {
                    if (p.suspended) {
                        tdAct.appendChild(btn('Lift suspension', 'btn-outline-secondary', function () {
                            act({ action: 'provider_unsuspend', id: p.id }, 'Suspension lifted for ' + p.name + '.');
                        }, 'Lift the suspension on ' + p.name));
                    } else {
                        tdAct.appendChild(btn('Suspend', 'btn-outline-warning', function () {
                            askPrompt({ title: 'Suspend ' + p.name, message: 'The company stays on its lists but is skipped, and shows "suspended until" to dispatchers.',
                                        date: true, textLabel: 'Reason', textRequired: true, okLabel: 'Suspend' }, function (r) {
                                act({ action: 'provider_suspend', id: p.id, until: r.date, reason: r.text }, p.name + ' suspended.');
                            });
                        }, 'Suspend ' + p.name));
                    }
                    tdAct.appendChild(document.createTextNode(' '));
                    tdAct.appendChild(btn('Retire', 'btn-outline-secondary', function () {
                        if (window.confirm('Retire ' + p.name + '? It disappears from every queue. Its history is kept.')) {
                            act({ action: 'provider_retire', id: p.id }, p.name + ' retired.');
                        }
                    }, 'Retire ' + p.name));
                } else {
                    tdAct.appendChild(btn('Reactivate', 'btn-outline-success', function () {
                        act(providerPayload(p, { is_active: 1 }), p.name + ' reactivated.');
                    }, 'Reactivate ' + p.name));
                }
                if (p.can_delete) {
                    tdAct.appendChild(document.createTextNode(' '));
                    tdAct.appendChild(btn('Delete', 'btn-outline-danger', function () {
                        if (window.confirm('Delete ' + p.name + ' permanently? (Only possible because it was never used.)')) {
                            act({ action: 'provider_delete', id: p.id }, p.name + ' deleted.');
                        }
                    }, 'Delete ' + p.name));
                }
            } else {
                tdAct.appendChild(el('span', 'text-body-secondary small', 'view only'));
            }
            tr.appendChild(tdAct);
            body.appendChild(tr);
        });
    }

    function providerPayload(p, over) {
        var o = { action: 'provider_save', id: p.id, name: p.name, contact_name: p.contact_name || '', phone: p.phone,
                  phone_alt: p.phone_alt || '', service_area: p.service_area || '', hours_note: p.hours_note || '',
                  notes: p.notes || '', yard_facility_id: p.yard_facility_id || 0, is_active: p.is_active ? 1 : 0 };
        for (var k in over) { if (over.hasOwnProperty(k)) o[k] = over[k]; }
        return o;
    }

    function fillOrgSelect(sel, current, isNew) {
        clear(sel);
        if (ov.can_all_agencies) {
            var all = el('option', '', 'All agencies');
            all.value = 'all';
            sel.appendChild(all);
        }
        ov.orgs.forEach(function (o) {
            var op = el('option', '', o.name);
            op.value = String(o.id);
            sel.appendChild(op);
        });
        if (!isNew) {
            sel.value = current === null ? 'all' : String(current);
            sel.disabled = true;
        } else {
            sel.disabled = false;
            if (!ov.can_all_agencies && ov.orgs.length) sel.value = String(ov.orgs[0].id);
            else sel.value = ov.orgs.length ? String(ov.orgs[0].id) : 'all';
        }
    }

    function ensureFacilities(done) {
        if (facilities !== null) { done(); return; }
        fetch('api/facilities.php', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) { facilities = (d && d.facilities) ? d.facilities : []; done(); })
            .catch(function () { facilities = []; done(); });
    }

    function openProviderModal(p) {
        var isNew = !p;
        $('vpProviderTitle').textContent = isNew ? 'New company' : 'Edit ' + p.name;
        $('vpProviderError').classList.add('d-none');
        var c = p || {};
        $('vpPid').value = String(c.id || 0);
        $('vpPName').value = c.name || '';
        $('vpPContact').value = c.contact_name || '';
        $('vpPPhone').value = c.phone || '';
        $('vpPPhoneAlt').value = c.phone_alt || '';
        $('vpPArea').value = c.service_area || '';
        $('vpPHours').value = c.hours_note || '';
        $('vpPNotes').value = c.notes || '';
        $('vpPActive').checked = isNew ? true : !!c.is_active;
        fillOrgSelect($('vpPOrg'), isNew ? null : c.org_id, isNew);
        ensureFacilities(function () {
            var sel = $('vpPYard');
            clear(sel);
            var none = el('option', '', '— none —');
            none.value = '';
            sel.appendChild(none);
            facilities.forEach(function (f) {
                var o = el('option', '', (f.name || ('facility #' + f.id)) + (f.street ? ' — ' + f.street : ''));
                o.value = String(f.id);
                sel.appendChild(o);
            });
            sel.value = c.yard_facility_id ? String(c.yard_facility_id) : '';
        });
        showModal('vpProviderModal');
        setTimeout(function () { $('vpPName').focus(); }, 300);
    }

    function saveProvider() {
        var id = parseInt($('vpPid').value, 10) || 0;
        var body = {
            action: 'provider_save', id: id, name: val('vpPName'), contact_name: val('vpPContact'), phone: val('vpPPhone'),
            phone_alt: val('vpPPhoneAlt'), service_area: val('vpPArea'), hours_note: val('vpPHours'), notes: String($('vpPNotes').value),
            yard_facility_id: parseInt($('vpPYard').value, 10) || 0, is_active: $('vpPActive').checked ? 1 : 0
        };
        if (!id) body.org_id = $('vpPOrg').value;
        apiPost(body).then(function (d) {
            if (d.error) { var e = $('vpProviderError'); e.textContent = d.error; e.classList.remove('d-none'); return; }
            hideModal('vpProviderModal');
            toast('Company saved.', false);
            reload();
        }).catch(function () {
            var e = $('vpProviderError'); e.textContent = 'Could not reach the server.'; e.classList.remove('d-none');
        });
    }

    // ═════════════════════════════════════════════════════════════
    // Rotation lists tab
    // ═════════════════════════════════════════════════════════════
    var MODE_LABEL = { round_robin: 'Round robin', strict_order: 'Strict order', manual: 'Manual' };

    function renderLists() {
        var body = $('vpListRows');
        clear(body);
        if (!ov.lists.length) {
            var tr0 = el('tr');
            var td0 = el('td', 'text-body-secondary', 'No rotation lists yet. Add one with "New list", then add companies to it. Dispatchers can still log calls without a list.');
            td0.colSpan = 7;
            tr0.appendChild(td0);
            body.appendChild(tr0);
            return;
        }
        ov.lists.forEach(function (l) {
            var tr = el('tr');
            var tdName = el('td');
            tdName.appendChild(el('div', 'fw-semibold', l.name));
            if (l.description) tdName.appendChild(el('div', 'text-body-secondary small', l.description));
            tr.appendChild(tdName);
            tr.appendChild(el('td', '', l.service_label));
            tr.appendChild(el('td', '', (MODE_LABEL[l.effective_mode] || l.effective_mode) + (l.mode ? '' : ' (install-wide)')));
            tr.appendChild(el('td', 'text-end', String(l.member_count)));
            var tdNext = el('td');
            if (l.next_up_name) {
                tdNext.appendChild(el('span', 'vp-next-up', l.next_up_name));
                tdNext.appendChild(el('div', 'text-body-secondary small', l.next_up_last_turn_at ? 'last turn ' + when(l.next_up_last_turn_at) : 'no turns yet'));
            } else {
                tdNext.appendChild(el('span', 'text-body-secondary', l.effective_mode === 'manual' ? 'manual (no suggestion)' : '—'));
            }
            tr.appendChild(tdNext);
            var tdSt = el('td');
            tdSt.appendChild(el('span', 'badge ' + (l.is_active ? 'text-bg-success' : 'text-bg-secondary'), l.is_active ? 'Active' : 'Retired'));
            if (l.is_default) { tdSt.appendChild(document.createTextNode(' ')); tdSt.appendChild(el('span', 'badge text-bg-primary', 'Default')); }
            tr.appendChild(tdSt);
            var tdAct = el('td', 'text-end text-nowrap');
            tdAct.appendChild(btn('Open', 'btn-outline-primary', function () { selectList(l.id); }, 'Open ' + l.name));
            tr.appendChild(tdAct);
            body.appendChild(tr);
        });
    }

    function selectedList() {
        for (var i = 0; i < ov.lists.length; i++) { if (ov.lists[i].id === selectedListId) return ov.lists[i]; }
        return null;
    }

    function selectList(id) {
        selectedListId = id;
        loadListDetail(id).then(function () {
            var card = $('vpListDetail');
            if (card.scrollIntoView) card.scrollIntoView({ block: 'nearest' });
        });
    }

    function loadListDetail(id) {
        return apiGet('action=list_detail&list_id=' + id).then(function (d) {
            if (d.error) { toast(d.error, true); selectedListId = 0; detail = null; renderListDetail(); return; }
            detail = d;
            renderListDetail();
        });
    }

    function renderListDetail() {
        var card = $('vpListDetail');
        var l = selectedListId ? selectedList() : null;
        card.classList.toggle('d-none', !l || !detail);
        if (!l || !detail) return;
        $('vpListDetailTitle').textContent = l.name + ' — ' + l.service_label;
        $('vpDefaultLabel').textContent = l.is_default ? 'Clear default' : 'Make default';
        $('vpBtnToggleDefault').disabled = !l.can_edit || (!l.is_active && !l.is_default);
        $('vpBtnEditList').disabled = !l.can_edit;
        $('vpBtnRetireList').disabled = !l.can_edit || !l.is_active;
        $('vpBtnDeleteList').disabled = !l.can_edit;

        var note = $('vpOrderNote');
        if (detail.order_locked) {
            note.textContent = 'Round robin with history: the order follows the dispatch ledger, so companies cannot be reordered by hand. ' +
                'Use "Move to end" (reason required, audited) for a company that should wait. A dispatcher’s one-off pick is an audited override on the call itself.';
        } else if (detail.mode === 'strict_order') {
            note.textContent = 'Strict order: the order below is the order companies are called, regardless of history. Reordering is audited.';
        } else if (detail.mode === 'manual') {
            note.textContent = 'Manual: dispatchers see this order but choose the company themselves; no company is marked "next up".';
        } else {
            note.textContent = 'Round robin, no history yet: arrange the starting order with the arrows. Once calls are logged the order follows the ledger.';
        }

        var body = $('vpMemberRows');
        clear(body);
        if (!detail.members.length) {
            var tr0 = el('tr');
            var td0 = el('td', 'text-body-secondary', 'No companies on this list yet. Add one below.');
            td0.colSpan = 6;
            tr0.appendChild(td0);
            body.appendChild(tr0);
        }
        var n = detail.members.length;
        detail.members.forEach(function (m, idx) {
            var tr = el('tr');
            if (m.is_head) tr.className = 'table-primary';
            tr.appendChild(el('td', '', String(m.rank)));
            var tdName = el('td');
            tdName.appendChild(el('div', 'fw-semibold', m.name));
            if (m.contact_name) tdName.appendChild(el('div', 'text-body-secondary small', m.contact_name));
            tr.appendChild(tdName);
            tr.appendChild(el('td', '', m.phone));
            tr.appendChild(el('td', 'text-body-secondary small', m.last_turn_at ? when(m.last_turn_at) : 'never'));
            var tdSt = el('td');
            if (m.is_head) { var h = el('span', 'badge text-bg-primary'); h.appendChild(icon('bi-arrow-right-circle-fill', 'me-1')); h.appendChild(document.createTextNode('NEXT UP')); tdSt.appendChild(h); }
            else if (!m.eligible) {
                tdSt.appendChild(el('span', 'badge text-bg-secondary', !m.is_active ? 'Retired' : (m.is_suspended ? 'Suspended until ' + when(m.suspended_until) : 'Not eligible')));
            } else { tdSt.appendChild(el('span', 'text-body-secondary small', 'ready')); }
            tr.appendChild(tdSt);

            var tdAct = el('td', 'text-end text-nowrap');
            if (l.can_edit) {
                var up = btn('', 'btn-outline-secondary', function () { moveMember(m, 'up'); }, 'Move ' + m.name + ' up');
                up.appendChild(icon('bi-arrow-up'));
                up.disabled = detail.order_locked || idx === 0;
                var down = btn('', 'btn-outline-secondary', function () { moveMember(m, 'down'); }, 'Move ' + m.name + ' down');
                down.appendChild(icon('bi-arrow-down'));
                down.disabled = detail.order_locked || idx === n - 1;
                tdAct.appendChild(up); tdAct.appendChild(document.createTextNode(' ')); tdAct.appendChild(down); tdAct.appendChild(document.createTextNode(' '));
                tdAct.appendChild(btn('Move to end', 'btn-outline-warning', function () {
                    askPrompt({ title: 'Move ' + m.name + ' to the end', message: 'The company waits behind everyone else on this list. This is recorded in the ledger.',
                                textLabel: 'Reason', textRequired: true, okLabel: 'Move to end' }, function (r) {
                        act({ action: 'member_move_to_end', list_id: selectedListId, provider_id: m.provider_id, reason: r.text }, m.name + ' moved to the end.');
                    });
                }, 'Move ' + m.name + ' to the end'));
                tdAct.appendChild(document.createTextNode(' '));
                tdAct.appendChild(btn('Remove', 'btn-outline-danger', function () {
                    if (window.confirm('Remove ' + m.name + ' from this list? Its history is kept.')) {
                        act({ action: 'member_remove', list_id: selectedListId, provider_id: m.provider_id }, m.name + ' removed from the list.');
                    }
                }, 'Remove ' + m.name));
            }
            tr.appendChild(tdAct);
            body.appendChild(tr);
        });

        var sel = $('vpAddMemberSelect');
        clear(sel);
        var onList = {};
        detail.members.forEach(function (m) { onList[m.provider_id] = true; });
        var first = el('option', '', '— choose a company —');
        first.value = '';
        sel.appendChild(first);
        ov.providers.forEach(function (p) {
            if (!p.is_active || onList[p.id]) return;
            if (p.org_id !== null && (l.org_id === null || p.org_id !== l.org_id)) return;   // the server enforces this too
            var o = el('option', '', p.name + ' — ' + p.phone);
            o.value = String(p.id);
            sel.appendChild(o);
        });
        $('vpAddMemberSelect').disabled = !l.can_edit;
        $('vpBtnAddMember').disabled = !l.can_edit;
    }

    function moveMember(m, dir) {
        var body = { action: 'member_move', list_id: selectedListId, provider_id: m.provider_id, direction: dir };
        if (!detail.has_history) { act(body, null); return; }
        // A list that already has dispatch history: reordering is recorded in the ledger and needs a reason.
        askPrompt({ title: 'Move ' + m.name + ' ' + dir, message: 'This list already has dispatch history. The move is recorded in the ledger.',
                    textLabel: 'Reason', textRequired: true, okLabel: 'Move ' + dir }, function (r) {
            body.reason = r.text;
            act(body, null);
        });
    }

    function openListModal(l) {
        var isNew = !l;
        $('vpListTitle').textContent = isNew ? 'New rotation list' : 'Edit ' + l.name;
        $('vpListError').classList.add('d-none');
        var c = l || {};
        $('vpLid').value = String(c.id || 0);
        $('vpLName').value = c.name || '';
        $('vpLDesc').value = c.description || '';
        $('vpLMode').value = c.mode || '';
        $('vpLSort').value = String(c.sort_order || 0);
        $('vpLActive').checked = isNew ? true : !!c.is_active;
        var ts = $('vpLType');
        clear(ts);
        ov.service_types.forEach(function (t) {
            var o = el('option', '', t.label + (t.is_active ? '' : ' (inactive)'));
            o.value = String(t.id);
            ts.appendChild(o);
        });
        if (c.service_type_id) ts.value = String(c.service_type_id);
        fillOrgSelect($('vpLOrg'), isNew ? null : c.org_id, isNew);
        showModal('vpListModal');
        setTimeout(function () { $('vpLName').focus(); }, 300);
    }

    function saveList() {
        var id = parseInt($('vpLid').value, 10) || 0;
        var body = {
            action: 'list_save', id: id, name: val('vpLName'), service_type_id: parseInt($('vpLType').value, 10) || 0,
            description: val('vpLDesc'), mode: $('vpLMode').value, sort_order: parseInt($('vpLSort').value, 10) || 0,
            is_active: $('vpLActive').checked ? 1 : 0
        };
        if (!id) body.org_id = $('vpLOrg').value;
        apiPost(body).then(function (d) {
            if (d.error) { var e = $('vpListError'); e.textContent = d.error; e.classList.remove('d-none'); return; }
            hideModal('vpListModal');
            toast('List saved.', false);
            if (!id && d.id) selectedListId = d.id;
            reload();
        }).catch(function () {
            var e = $('vpListError'); e.textContent = 'Could not reach the server.'; e.classList.remove('d-none');
        });
    }

    // ═════════════════════════════════════════════════════════════
    // Service types tab
    // ═════════════════════════════════════════════════════════════
    function renderTypes() {
        var body = $('vpTypeRows');
        clear(body);
        var canEdit = !!ov.can_all_agencies;           // install-wide data: the server refuses anyone else too
        $('vpTypesNote').classList.toggle('d-none', canEdit);
        ov.service_types.forEach(function (t) { body.appendChild(typeRow(t, !canEdit)); });
        if (canEdit) body.appendChild(typeRow(null, false));
    }

    function typeRow(t, readOnly) {
        var isNew = !t;
        var tr = el('tr');
        var tdCode = el('td');
        var code = el('input', 'form-control form-control-sm');
        code.type = 'text'; code.maxLength = 32;
        code.setAttribute('aria-label', isNew ? 'Code for a new service type' : 'Code');
        if (isNew) { code.placeholder = 'new code (optional)'; } else { code.value = t.code; code.readOnly = true; }
        tdCode.appendChild(code);
        tr.appendChild(tdCode);
        var lockables = [];

        var tdLabel = el('td');
        var label = el('input', 'form-control form-control-sm');
        label.type = 'text'; label.maxLength = 64; label.value = isNew ? '' : t.label;
        label.setAttribute('aria-label', isNew ? 'Label for a new service type' : 'Label for ' + t.code);
        if (isNew) label.placeholder = 'e.g. Heavy tow';
        tdLabel.appendChild(label);
        tr.appendChild(tdLabel);
        lockables.push(label);

        var tdDest = el('td');
        var dest = el('input', 'form-check-input');
        dest.type = 'checkbox'; dest.checked = isNew ? false : t.needs_destination === 1;
        dest.setAttribute('aria-label', 'Asks for a destination');
        tdDest.appendChild(dest);
        tr.appendChild(tdDest);
        lockables.push(dest);

        var tdAct = el('td');
        var act1 = el('input', 'form-check-input');
        act1.type = 'checkbox'; act1.checked = isNew ? true : t.is_active === 1;
        act1.setAttribute('aria-label', 'Active');
        tdAct.appendChild(act1);
        tr.appendChild(tdAct);
        lockables.push(act1);

        var tdSort = el('td');
        var sort = el('input', 'form-control form-control-sm');
        sort.type = 'number'; sort.step = '1'; sort.value = isNew ? '0' : String(t.sort_order);
        sort.setAttribute('aria-label', 'Order');
        tdSort.appendChild(sort);
        tr.appendChild(tdSort);
        lockables.push(sort);

        var tdBtn = el('td', 'text-end');
        if (readOnly) {
            lockables.forEach(function (x) { x.disabled = true; });
            tr.appendChild(tdBtn);
            return tr;
        }
        tdBtn.appendChild(btn(isNew ? 'Add' : 'Save', isNew ? 'btn-primary' : 'btn-outline-primary', function () {
            var body = { action: 'service_type_save', id: isNew ? 0 : t.id, label: label.value, code: isNew ? code.value : '',
                         needs_destination: dest.checked ? 1 : 0, is_active: act1.checked ? 1 : 0, sort_order: parseInt(sort.value, 10) || 0 };
            act(body, isNew ? 'Service type added.' : 'Service type saved.');
        }, isNew ? 'Add the new service type' : 'Save ' + t.code));
        tr.appendChild(tdBtn);
        return tr;
    }

    // ═════════════════════════════════════════════════════════════
    // History tab
    // ═════════════════════════════════════════════════════════════
    var EVENT_LABEL = { offered: 'Called', accepted: 'Accepted', declined: 'Declined', no_answer: 'No answer', unavailable: 'Unavailable',
                        withdrew: 'Withdrew', eta_update: 'ETA update', on_scene: 'On scene', completed: 'Completed', cancelled: 'Cancelled',
                        goa: 'Gone on arrival', note: 'Note', voided: 'Voided', joined_list: 'Joined list', moved_to_end: 'Moved to end',
                        removed_from_list: 'Removed from list', order_changed: 'Order changed' };
    var METHOD_LABEL = { rotation: 'next up', override: 'override', owner_request: 'driver/owner request', unlisted: 'not on a list' };

    function renderHistoryFilters() {
        var ls = $('vpHistList');
        var keepL = ls.value;
        clear(ls);
        var all1 = el('option', '', 'All lists'); all1.value = ''; ls.appendChild(all1);
        ov.lists.forEach(function (l) { var o = el('option', '', l.name); o.value = String(l.id); ls.appendChild(o); });
        ls.value = keepL;
        var ps = $('vpHistProvider');
        var keepP = ps.value;
        clear(ps);
        var all2 = el('option', '', 'All companies'); all2.value = ''; ps.appendChild(all2);
        ov.providers.forEach(function (p) { var o = el('option', '', p.name); o.value = String(p.id); ps.appendChild(o); });
        ps.value = keepP;
    }

    function historyQuery() {
        var q = [];
        if (val('vpHistList')) q.push('list_id=' + encodeURIComponent(val('vpHistList')));
        if (val('vpHistProvider')) q.push('provider_id=' + encodeURIComponent(val('vpHistProvider')));
        if (val('vpHistFrom')) q.push('date_from=' + encodeURIComponent(val('vpHistFrom')));
        if (val('vpHistTo')) q.push('date_to=' + encodeURIComponent(val('vpHistTo')));
        if ($('vpHistOverrides').checked) q.push('overrides_only=1');
        return q.join('&');
    }

    function loadHistory() {
        var q = historyQuery();
        $('vpBtnHistCsv').setAttribute('href', 'api/vendor-admin.php?action=history_csv' + (q ? '&' + q : ''));
        apiGet('action=history' + (q ? '&' + q : '')).then(function (d) {
            if (d.error) { toast(d.error, true); return; }
            var sum = $('vpHistSummary');
            clear(sum);
            if (!d.summary.length) {
                var t0 = el('tr'); var c0 = el('td', 'text-body-secondary', 'No calls recorded for this filter.'); c0.colSpan = 9; t0.appendChild(c0); sum.appendChild(t0);
            }
            d.summary.forEach(function (s) {
                var tr = el('tr');
                tr.appendChild(el('td', 'fw-semibold', s.provider_name));
                [s.offered, s.accepted, s.declined, s.no_answer, s.unavailable, s.overrides, s.owner_requests].forEach(function (n) { tr.appendChild(el('td', 'text-end', String(n))); });
                tr.appendChild(el('td', 'text-body-secondary small', s.last_offer_at ? when(s.last_offer_at) : ''));
                sum.appendChild(tr);
            });
            var rows = $('vpHistRows');
            clear(rows);
            d.events.forEach(function (e) {
                var tr = el('tr', e.voided ? 'text-decoration-line-through text-body-secondary' : '');
                tr.appendChild(el('td', 'text-nowrap', when(e.event_at)));
                tr.appendChild(el('td', '', e.dispatch_ref || ''));
                tr.appendChild(el('td', '', e.list_name || ''));
                var tdE = el('td', '', EVENT_LABEL[e.event_type] || e.event_type);
                if (e.voided) tdE.appendChild(el('span', 'badge text-bg-secondary ms-1', 'voided'));
                if (e.no_outcome) { var no = el('span', 'badge text-bg-warning ms-1'); no.appendChild(icon('bi-telephone-x', 'me-1')); no.appendChild(document.createTextNode('no outcome recorded')); tdE.appendChild(no); }
                tr.appendChild(tdE);
                tr.appendChild(el('td', '', e.provider_name));
                tr.appendChild(el('td', '', e.selection_method ? (METHOD_LABEL[e.selection_method] || e.selection_method) + (e.consumed_turn ? ' (used a turn)' : '') : (e.consumed_turn ? 'used a turn' : '')));
                tr.appendChild(el('td', '', e.expected_head_name || ''));
                tr.appendChild(el('td', 'text-body-secondary', [e.reason, e.detail].filter(function (x) { return x; }).join(' · ')));
                tr.appendChild(el('td', '', e.actor_name));
                rows.appendChild(tr);
            });
            $('vpHistNote').textContent = d.truncated ? '(showing the newest 500; narrow the filter or download the CSV)' : '(' + d.events.length + ')';
        }).catch(function () { toast('Could not load the history.', true); });
    }

    // ═════════════════════════════════════════════════════════════
    // Settings tab
    // ═════════════════════════════════════════════════════════════
    function renderSettings() {
        var s = ov.settings;
        $('vpSetEnabled').checked = s.vendor_dispatch_enabled === '1';
        $('vpSetMode').value = s.vendor_rotation_mode;
        $('vpSetAdvance').value = s.vendor_advance_rule;
        $('vpSetOverride').checked = s.vendor_allow_override === '1';
        $('vpSetReason').checked = s.vendor_override_requires_reason === '1';
        $('vpSetDial').value = s.phone_click_to_call;
        $('vpSetReason').disabled = !$('vpSetOverride').checked;
        var canEdit = !!ov.can_all_agencies;           // install-wide: the server refuses anyone else too
        $('vpSettingsNote').classList.toggle('d-none', canEdit);
        ['vpSetEnabled', 'vpSetMode', 'vpSetAdvance', 'vpSetOverride', 'vpSetDial', 'vpBtnSaveSettings'].forEach(function (id) { $(id).disabled = !canEdit; });
        if (!canEdit) $('vpSetReason').disabled = true;

        var cl = $('vpChecklist');
        clear(cl);
        var withMembers = 0;
        ov.lists.forEach(function (l) { if (l.is_active && l.member_count > 0) withMembers++; });
        [[ov.counts.providers > 0, 'At least one company is on file (' + ov.counts.providers + ').'],
         [ov.counts.lists > 0, 'At least one rotation list exists (' + ov.counts.lists + ').'],
         [withMembers > 0, 'At least one active list has companies on it (' + withMembers + ').'],
         [true, 'Roles that should dispatch hold "Dispatch Towing / Roadside Vendor" (Dispatcher, Org Admin and Super Admin do by default; change it in Roles & Permissions).']
        ].forEach(function (c) {
            var li = el('li', 'mb-1');
            li.appendChild(icon(c[0] ? 'bi-check-circle-fill text-success' : 'bi-circle text-body-secondary', 'me-1'));
            li.appendChild(document.createTextNode(c[1]));
            cl.appendChild(li);
        });
    }

    function saveSettings() {
        // Only what the administrator actually changed is sent, so saving this tab can never overwrite a setting it
        // does not own (phone_click_to_call is shared with the click-to-dial feature) with a stale value.
        var want = {
            vendor_dispatch_enabled: $('vpSetEnabled').checked ? '1' : '0',
            vendor_rotation_mode: $('vpSetMode').value,
            vendor_advance_rule: $('vpSetAdvance').value,
            vendor_allow_override: $('vpSetOverride').checked ? '1' : '0',
            vendor_override_requires_reason: $('vpSetReason').checked ? '1' : '0',
            phone_click_to_call: $('vpSetDial').value
        };
        var body = { action: 'settings_save' };
        var n = 0;
        Object.keys(want).forEach(function (k) { if (ov.settings[k] !== want[k]) { body[k] = want[k]; n++; } });
        if (!n) { toast('Nothing has changed.', false); return; }
        act(body, 'Settings saved.');
    }

    // ═════════════════════════════════════════════════════════════
    // Boot
    // ═════════════════════════════════════════════════════════════
    function renderAll() {
        renderProviders();
        renderLists();
        renderListDetail();
        renderTypes();
        renderHistoryFilters();
        renderSettings();
    }

    function bind() {
        $('vpBtnNewProvider').addEventListener('click', function () { openProviderModal(null); });
        $('vpBtnSaveProvider').addEventListener('click', saveProvider);
        $('vpBtnNewList').addEventListener('click', function () { openListModal(null); });
        $('vpBtnSaveList').addEventListener('click', saveList);
        $('vpBtnPromptOk').addEventListener('click', promptOk);
        $('vpPromptText').addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); promptOk(); } });
        $('vpBtnEditList').addEventListener('click', function () { var l = selectedList(); if (l) openListModal(l); });
        $('vpBtnCloseList').addEventListener('click', function () { selectedListId = 0; detail = null; renderListDetail(); });
        $('vpBtnToggleDefault').addEventListener('click', function () {
            var l = selectedList();
            if (l) act({ action: 'list_set_default', id: l.id, is_default: l.is_default ? 0 : 1 }, l.is_default ? 'Default cleared.' : l.name + ' is now the default list.');
        });
        $('vpBtnRetireList').addEventListener('click', function () {
            var l = selectedList();
            if (l && window.confirm('Retire ' + l.name + '? Dispatchers stop seeing it. Its history is kept.')) act({ action: 'list_retire', id: l.id }, l.name + ' retired.');
        });
        $('vpBtnDeleteList').addEventListener('click', function () {
            var l = selectedList();
            if (l && window.confirm('Delete ' + l.name + ' permanently? (Only possible when it was never used.)')) {
                act({ action: 'list_delete', id: l.id }, l.name + ' deleted.').then(function (d) { if (!d.error) { selectedListId = 0; detail = null; renderListDetail(); } });
            }
        });
        $('vpBtnAddMember').addEventListener('click', function () {
            var pid = parseInt($('vpAddMemberSelect').value, 10) || 0;
            if (!pid || !selectedListId) { toast('Choose a company to add.', true); return; }
            act({ action: 'member_add', list_id: selectedListId, provider_id: pid }, 'Company added to the list.');
        });
        $('vpBtnHistApply').addEventListener('click', loadHistory);
        $('vpTabHistory').addEventListener('shown.bs.tab', loadHistory);
        $('vpBtnSaveSettings').addEventListener('click', saveSettings);
        $('vpSetOverride').addEventListener('change', function () { $('vpSetReason').disabled = !$('vpSetOverride').checked; });
    }

    function boot() {
        var c = $('csrfToken');
        csrf = c ? c.value : '';
        bind();
        reload();
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
    else boot();
})();
