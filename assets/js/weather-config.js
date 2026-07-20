/**
 * Weather Alerts admin controller (Phase 112, Phase 1).
 *
 * Drives weather-alerts.php against api/weather-alerts.php. ES5 IIFE, no build
 * step, DOM-safe (textContent for all API-sourced strings).
 */
(function () {
    'use strict';

    var API = 'api/weather-alerts.php';
    function csrf() {
        var el = document.getElementById('csrfToken');
        return el ? el.value : '';
    }

    function getJson(action, params) {
        // GH #88 — build the query string properly. Extra params must be their
        // own &key=value pairs; the old callers that did
        // getJson('nws_counties&state=' + state) got the whole string
        // encodeURIComponent'd into a single action value ("nws_counties&state=MN"),
        // which the API rejected as "unknown action".
        var url = API + '?action=' + encodeURIComponent(action);
        if (params) {
            for (var k in params) {
                if (Object.prototype.hasOwnProperty.call(params, k)) {
                    url += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(params[k]);
                }
            }
        }
        return fetch(url, { credentials: 'same-origin' })
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
        var el = document.getElementById('wxToast');
        if (!el) return;
        el.className = 'alert alert-' + (kind || 'info');
        el.textContent = msg;
        el.classList.remove('d-none');
        window.setTimeout(function () { el.classList.add('d-none'); }, 4000);
    }
    function showResult(obj) {
        var el = document.getElementById('wxTestResult');
        if (!el) return;
        el.textContent = typeof obj === 'string' ? obj : JSON.stringify(obj, null, 2);
        el.classList.remove('d-none');
    }
    function val(id) { var e = document.getElementById(id); return e ? e.value : ''; }
    function checked(id) { var e = document.getElementById(id); return !!(e && e.checked); }
    function setVal(id, v) { var e = document.getElementById(id); if (e) e.value = (v === null || v === undefined) ? '' : v; }
    function setChecked(id, v) { var e = document.getElementById(id); if (e) e.checked = !!v; }

    // ── Settings ──────────────────────────────────────────────────────────
    function loadConfig() {
        return getJson('config').then(function (res) {
            var c = res.config || {};
            setChecked('wxEnabled', c.weather_alerts_enabled === '1');
            setVal('wxUaContact', c.weather_ua_contact);
            setVal('wxPollSeconds', c.weather_poll_seconds || '60');
            setVal('wxProvider', c.weather_provider || 'nws');
            setVal('wxTtsClear', c.weather_tts_clear_channel_seconds || '3.0');
            setVal('wxTtsCallsign', c.weather_tts_callsign);
            setVal('wxTtsMax', c.weather_tts_max_seconds || '45');
            setVal('wxTtsVoice', c.weather_tts_voice);
            setVal('wxTtsPrefix', c.weather_tts_prefix);
            setVal('wxGeofenceUnits', c.weather_geofence_units === '0' ? '0' : '1');
            setVal('wxRadioAutofire', c.weather_radio_allow_autofire === '1' ? '1' : '0');
            updateMasterBadge(c.weather_alerts_enabled === '1');
            showWarning(res.warning || '');
        });
    }
    function updateMasterBadge(on) {
        var b = document.getElementById('wxMasterBadge');
        if (!b) return;
        b.textContent = on ? 'ENABLED' : 'OFF';
        b.className = 'badge ms-3 ' + (on ? 'bg-danger' : 'bg-secondary');
    }
    function showWarning(msg) {
        var el = document.getElementById('wxWarning');
        if (!el) return;
        if (msg) { el.textContent = msg; el.classList.remove('d-none'); }
        else { el.classList.add('d-none'); }
    }
    function saveSettings() {
        postJson('save_settings', {
            weather_alerts_enabled: checked('wxEnabled') ? 1 : 0,
            weather_ua_contact: val('wxUaContact'),
            weather_poll_seconds: val('wxPollSeconds'),
            weather_provider: val('wxProvider'),
            weather_tts_clear_channel_seconds: val('wxTtsClear'),
            weather_tts_callsign: val('wxTtsCallsign'),
            weather_tts_max_seconds: val('wxTtsMax'),
            weather_tts_voice: val('wxTtsVoice'),
            weather_tts_prefix: val('wxTtsPrefix'),
            weather_geofence_units: val('wxGeofenceUnits') === '0' ? 0 : 1,
            weather_radio_allow_autofire: val('wxRadioAutofire') === '1' ? 1 : 0
        }).then(function (res) {
            if (res.error) { toast(res.error, 'danger'); return; }
            toast('Settings saved.', 'success');
            updateMasterBadge(checked('wxEnabled'));
            showWarning(res.warning || '');
        });
    }

    // ── Areas ─────────────────────────────────────────────────────────────
    var areaCache = [];
    function loadAreas() {
        return getJson('areas').then(function (res) {
            areaCache = res.areas || [];
            renderAreas();
            fillRuleAreaSelect();
        });
    }
    function areaValue(a) {
        if (a.kind === 'state') return a.state_code || '';
        if (a.kind === 'zones') return a.zones || '';
        if (a.kind === 'point_radius') return (a.lat + ', ' + a.lng + ' r=' + a.radius_miles + 'mi');
        return '';
    }
    function renderAreas() {
        var tb = document.getElementById('wxAreaRows');
        if (!tb) return;
        tb.textContent = '';
        if (!areaCache.length) {
            var tr = document.createElement('tr');
            var td = document.createElement('td');
            td.colSpan = 5; td.className = 'text-body-secondary';
            td.textContent = 'No coverage areas yet. Add one, or load the Minnesota example.';
            tr.appendChild(td); tb.appendChild(tr); return;
        }
        areaCache.forEach(function (a) {
            var tr = document.createElement('tr');
            tr.appendChild(cell(a.label));
            tr.appendChild(cell(a.kind));
            tr.appendChild(cell(areaValue(a)));
            tr.appendChild(cell(a.active === '1' || a.active === 1 ? 'yes' : 'no'));
            var act = document.createElement('td');
            act.appendChild(btn('Edit', 'btn-outline-secondary', function () { editArea(a); }));
            act.appendChild(btn('Delete', 'btn-outline-danger', function () { delArea(a); }));
            tr.appendChild(act);
            tb.appendChild(tr);
        });
    }
    function editArea(a) {
        document.getElementById('wxAreaEditor').classList.remove('d-none');
        setVal('wxAreaId', a ? a.id : '');
        setVal('wxAreaLabel', a ? a.label : '');
        setVal('wxAreaKind', a ? a.kind : 'state');
        setVal('wxAreaState', a ? a.state_code : '');
        setVal('wxAreaZones', a ? a.zones : '');
        setVal('wxAreaLat', a ? a.lat : '');
        setVal('wxAreaLng', a ? a.lng : '');
        setVal('wxAreaRadius', a ? a.radius_miles : '40');
        setChecked('wxAreaActive', a ? (a.active === '1' || a.active === 1) : true);
        syncAreaKind();
    }
    function syncAreaKind() {
        var kind = val('wxAreaKind');
        toggle('.wx-area-state', kind === 'state');
        toggle('.wx-area-zones', kind === 'zones');
        toggle('.wx-area-point', kind === 'point_radius');
    }
    function saveArea() {
        postJson('save_area', {
            id: val('wxAreaId'), label: val('wxAreaLabel'), kind: val('wxAreaKind'),
            state_code: val('wxAreaState'), zones: val('wxAreaZones'),
            lat: val('wxAreaLat'), lng: val('wxAreaLng'), radius_miles: val('wxAreaRadius'),
            active: checked('wxAreaActive') ? 1 : 0
        }).then(function (res) {
            if (res.error) { toast(res.error, 'danger'); return; }
            document.getElementById('wxAreaEditor').classList.add('d-none');
            toast('Area saved.', 'success');
            loadAreas();
        });
    }
    function delArea(a) {
        if (!window.confirm('Delete area "' + a.label + '" and its rules?')) return;
        postJson('delete_area', { id: a.id }).then(function (res) {
            if (res.error) { toast(res.error, 'danger'); return; }
            toast('Area deleted.', 'success');
            loadAreas(); loadRules();
        });
    }

    // ── Rules ─────────────────────────────────────────────────────────────
    function fillRuleAreaSelect() {
        var sel = document.getElementById('wxRuleArea');
        if (!sel) return;
        sel.textContent = '';
        areaCache.forEach(function (a) {
            var o = document.createElement('option');
            o.value = a.id; o.textContent = a.label;
            sel.appendChild(o);
        });
    }
    function loadRules() {
        return getJson('rules').then(function (res) {
            var rules = res.rules || [];
            var tb = document.getElementById('wxRuleRows');
            if (!tb) return;
            tb.textContent = '';
            if (!rules.length) {
                var tr = document.createElement('tr');
                var td = document.createElement('td');
                td.colSpan = 7; td.className = 'text-body-secondary';
                td.textContent = 'No routing rules yet.';
                tr.appendChild(td); tb.appendChild(tr); return;
            }
            rules.forEach(function (r) {
                var tr = document.createElement('tr');
                tr.appendChild(cell(r.label));
                tr.appendChild(cell(r.area_label || ('#' + r.area_id)));
                tr.appendChild(cell(r.target + (r.target_ref ? (' (' + r.target_ref + ')') : '')));
                tr.appendChild(cell(r.min_severity));
                tr.appendChild(cell(r.action_mode));
                tr.appendChild(cell(r.active === '1' || r.active === 1 ? 'yes' : 'no'));
                var act = document.createElement('td');
                act.appendChild(btn('Edit', 'btn-outline-secondary', function () { editRule(r); }));
                act.appendChild(btn('Delete', 'btn-outline-danger', function () { delRule(r); }));
                tr.appendChild(act);
                tb.appendChild(tr);
            });
        });
    }
    function editRule(r) {
        document.getElementById('wxRuleEditor').classList.remove('d-none');
        setVal('wxRuleId', r ? r.id : '');
        setVal('wxRuleLabel', r ? r.label : '');
        setVal('wxRuleArea', r ? r.area_id : (areaCache[0] ? areaCache[0].id : ''));
        setVal('wxRuleTarget', r ? r.target : 'tray');
        setVal('wxRuleRef', r ? r.target_ref : '');
        setVal('wxRuleSeverity', r ? r.min_severity : 'Severe');
        setVal('wxRuleUrgency', r ? r.min_urgency : 'Expected');
        setVal('wxRuleMode', r ? r.action_mode : 'notify');
        setVal('wxRuleMsgTypes', r ? r.message_types : 'Alert,Update');
        setVal('wxRuleAllow', r ? r.event_allow : '');
        setVal('wxRuleDeny', r ? r.event_deny : '');
        setChecked('wxRuleRepeat', r ? (r.repeat_on_update === '1' || r.repeat_on_update === 1) : true);
        setChecked('wxRuleActive', r ? (r.active === '1' || r.active === 1) : true);
        syncRuleTarget();
    }
    function syncRuleTarget() {
        var t = val('wxRuleTarget');
        toggle('.wx-rule-ref', t === 'dmr' || t === 'zello' || t === 'sms');
    }
    function saveRule() {
        postJson('save_rule', {
            id: val('wxRuleId'), label: val('wxRuleLabel'), area_id: val('wxRuleArea'),
            target: val('wxRuleTarget'), target_ref: val('wxRuleRef'),
            min_severity: val('wxRuleSeverity'), min_urgency: val('wxRuleUrgency'),
            action_mode: val('wxRuleMode'), message_types: val('wxRuleMsgTypes'),
            event_allow: val('wxRuleAllow'), event_deny: val('wxRuleDeny'),
            repeat_on_update: checked('wxRuleRepeat') ? 1 : 0,
            active: checked('wxRuleActive') ? 1 : 0
        }).then(function (res) {
            if (res.error) { toast(res.error, 'danger'); return; }
            document.getElementById('wxRuleEditor').classList.add('d-none');
            toast('Rule saved.', 'success');
            loadRules();
        });
    }
    function delRule(r) {
        if (!window.confirm('Delete rule "' + r.label + '"?')) return;
        postJson('delete_rule', { id: r.id }).then(function (res) {
            if (res.error) { toast(res.error, 'danger'); return; }
            toast('Rule deleted.', 'success');
            loadRules();
        });
    }

    // ── County picker (works for any US state via NWS /zones) ────────────
    function csvList(id) {
        return val(id).split(',').map(function (s) { return s.trim(); })
            .filter(function (s) { return s !== ''; });
    }
    function loadCounties() {
        var state = (val('wxCountyState') || '').toUpperCase();
        if (!/^[A-Z]{2}$/.test(state)) { toast('Enter a 2-letter state code first.', 'warning'); return; }
        var box = document.getElementById('wxCountyList');
        box.classList.remove('d-none');
        box.textContent = 'Loading counties…';
        getJson('nws_counties', { state: state }).then(function (res) {
            if (res.error) { box.textContent = res.error; return; }
            var counties = res.counties || [];
            var selected = {};
            csvList('wxAreaZones').forEach(function (c) { selected[c.toUpperCase()] = true; });
            box.textContent = '';
            counties.forEach(function (c) {
                var lbl = document.createElement('label');
                lbl.className = 'd-block';
                lbl.style.breakInside = 'avoid';
                var cb = document.createElement('input');
                cb.type = 'checkbox'; cb.className = 'form-check-input me-1 wx-county-cb';
                cb.value = c.id; cb.checked = !!selected[c.id.toUpperCase()];
                cb.addEventListener('change', syncCountiesToCsv);
                lbl.appendChild(cb);
                lbl.appendChild(document.createTextNode(c.name));
                box.appendChild(lbl);
            });
        });
    }
    function syncCountiesToCsv() {
        // Rebuild the CSV: keep any hand-typed codes NOT in the loaded list,
        // then append every checked county.
        var box = document.getElementById('wxCountyList');
        var cbs = box.querySelectorAll('.wx-county-cb');
        var listed = {}, checked = [], i;
        for (i = 0; i < cbs.length; i++) {
            listed[cbs[i].value.toUpperCase()] = true;
            if (cbs[i].checked) checked.push(cbs[i].value);
        }
        var keep = csvList('wxAreaZones').filter(function (c) { return !listed[c.toUpperCase()]; });
        setVal('wxAreaZones', keep.concat(checked).join(','));
    }

    // ── Rule event-type quick picks (toggle a term in the allow CSV) ─────
    function toggleEventTerm(term) {
        var list = csvList('wxRuleAllow');
        var lower = list.map(function (s) { return s.toLowerCase(); });
        var idx = lower.indexOf(term.toLowerCase());
        if (idx >= 0) { list.splice(idx, 1); }
        else if (term.toLowerCase() === 'warning') { list = ['warning']; } // subsumes the rest
        else { list.push(term); }
        setVal('wxRuleAllow', list.join(','));
    }

    // ── Small DOM helpers ─────────────────────────────────────────────────
    function cell(text) { var td = document.createElement('td'); td.textContent = (text === null || text === undefined) ? '' : text; return td; }
    function btn(label, cls, fn) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'btn btn-sm ' + cls + ' me-1';
        b.textContent = label;
        b.addEventListener('click', fn);
        return b;
    }
    function toggle(selector, show) {
        var els = document.querySelectorAll(selector);
        for (var i = 0; i < els.length; i++) {
            if (show) els[i].classList.remove('d-none'); else els[i].classList.add('d-none');
        }
    }

    // ── Wire up ───────────────────────────────────────────────────────────
    function on(id, ev, fn) { var e = document.getElementById(id); if (e) e.addEventListener(ev, fn); }

    document.addEventListener('DOMContentLoaded', function () {
        on('wxSaveSettings', 'click', saveSettings);
        on('wxAddArea', 'click', function () { editArea(null); });
        on('wxAreaKind', 'change', syncAreaKind);
        on('wxSaveArea', 'click', saveArea);
        on('wxCancelArea', 'click', function () { document.getElementById('wxAreaEditor').classList.add('d-none'); });
        on('wxLoadCounties', 'click', loadCounties);
        var evtBtns = document.querySelectorAll('#wxRuleQuickTypes .wx-evt');
        for (var eb = 0; eb < evtBtns.length; eb++) {
            evtBtns[eb].addEventListener('click', function () {
                toggleEventTerm(this.getAttribute('data-evt'));
            });
        }
        on('wxAddRule', 'click', function () { editRule(null); });
        on('wxRuleTarget', 'change', syncRuleTarget);
        on('wxSaveRule', 'click', saveRule);
        on('wxCancelRule', 'click', function () { document.getElementById('wxRuleEditor').classList.add('d-none'); });

        on('wxTestFixture', 'click', function () {
            postJson('test_fixture', {}).then(function (res) { showResult(res); toast(res.note || 'Test sent.', 'info'); });
        });
        on('wxDryRun', 'click', function () {
            postJson('dry_run', {}).then(function (res) { showResult(res.summary || res); });
        });
        on('wxTestPoll', 'click', function () {
            postJson('test_poll', {}).then(function (res) { showResult(res.summary || res); });
        });
        on('wxLoadMn', 'click', function () {
            if (!window.confirm('Load the Minnesota example areas + rules? They are added INACTIVE for you to review.')) return;
            postJson('load_minnesota_example', {}).then(function (res) {
                if (res.error) { toast(res.error, 'danger'); return; }
                toast(res.note || 'Loaded.', 'success');
                loadAreas(); loadRules();
            });
        });

        loadConfig();
        loadAreas().then(loadRules);
    });
})();
