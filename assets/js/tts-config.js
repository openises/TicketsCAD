/**
 * Phase 113 — Voice & Speech admin controller.
 *
 * Renders the engine list + speech-application routing table, drives the
 * engine editor modal (driver-specific fields), and the Test-Listen button
 * (POST api/tts.php action=test → play the returned WAV in the browser).
 * ES5 IIFE, no build step.
 */
(function () {
    'use strict';

    var API = 'api/tts.php';
    var state = { engines: [], applications: [], drivers: {} };
    var engineModal = null;

    function csrf() {
        var m = document.querySelector('meta[name="csrf-token"]');
        return m ? m.content : '';
    }
    function esc(s) {
        var d = document.createElement('div');
        d.textContent = (s === null || s === undefined) ? '' : String(s);
        return d.innerHTML;
    }
    function toast(msg, kind) {
        var el = document.getElementById('ttsToast');
        if (!el) return;
        el.className = 'alert alert-' + (kind || 'info');
        el.textContent = msg;
        el.classList.remove('d-none');
        clearTimeout(el._t);
        el._t = setTimeout(function () { el.classList.add('d-none'); }, 4000);
    }

    function postJSON(payload) {
        payload.csrf_token = csrf();
        return fetch(API, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        }).then(function (r) { return r.json(); });
    }

    function load() {
        fetch(API + '?action=list', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.success) { toast((data && data.error) || 'Load failed', 'danger'); return; }
                state.engines = data.engines || [];
                state.applications = data.applications || [];
                state.drivers = data.drivers || {};
                renderEngines();
                renderApps();
            })
            .catch(function () { toast('Network error loading Voice & Speech', 'danger'); });
    }

    function engineName(id) {
        if (!id) return 'Piper (default)';
        for (var i = 0; i < state.engines.length; i++) {
            if (state.engines[i].id === id) return state.engines[i].label || state.engines[i].engine_key;
        }
        return '#' + id;
    }

    function renderEngines() {
        var tb = document.getElementById('ttsEnginesBody');
        if (!state.engines.length) {
            tb.innerHTML = '<tr><td colspan="4" class="text-center text-body-secondary py-3">No engines</td></tr>';
            return;
        }
        tb.innerHTML = state.engines.map(function (e) {
            var status = e.last_error
                ? '<span class="text-danger" title="' + esc(e.last_error) + '"><i class="bi bi-exclamation-triangle"></i> error</span>'
                : (e.last_ok_at ? '<span class="text-success"><i class="bi bi-check-circle"></i> ok</span>'
                                : '<span class="text-body-secondary">—</span>');
            if (!e.enabled) status = '<span class="text-body-secondary">disabled</span>';
            var driverLabel = (state.drivers[e.driver] && state.drivers[e.driver].label) || e.driver;
            var keyBadge = e.has_key ? ' <span class="badge bg-secondary">key set</span>' : '';
            var del = (e.engine_key === 'piper-default')
                ? '' : '<button class="btn btn-xs btn-outline-danger tts-del" data-id="' + e.id + '"><i class="bi bi-trash"></i></button>';
            return '<tr>'
                + '<td>' + esc(e.label || e.engine_key) + keyBadge + '</td>'
                + '<td class="small text-body-secondary">' + esc(driverLabel) + '</td>'
                + '<td>' + status + '</td>'
                + '<td class="text-end">'
                + '<button class="btn btn-xs btn-outline-info tts-test-row me-1" data-id="' + e.id + '"><i class="bi bi-play-circle"></i> Test</button>'
                + '<button class="btn btn-xs btn-outline-secondary tts-edit me-1" data-id="' + e.id + '"><i class="bi bi-pencil"></i></button>'
                + del + '</td></tr>';
        }).join('');
    }

    function engineOptions(selectedId) {
        var opts = '<option value="">Piper (default)</option>';
        state.engines.forEach(function (e) {
            opts += '<option value="' + e.id + '"' + (e.id === selectedId ? ' selected' : '') + '>'
                 + esc(e.label || e.engine_key) + '</option>';
        });
        return opts;
    }

    function renderApps() {
        var tb = document.getElementById('ttsAppsBody');
        if (!state.applications.length) {
            tb.innerHTML = '<tr><td colspan="6" class="text-center text-body-secondary py-3">No applications</td></tr>';
            return;
        }
        tb.innerHTML = state.applications.map(function (a) {
            return '<tr data-app="' + esc(a.app_key) + '">'
                + '<td>' + esc(a.label || a.app_key) + '</td>'
                + '<td><select class="form-select form-select-sm tts-app-engine">' + engineOptions(a.engine_id) + '</select></td>'
                + '<td><input class="form-control form-control-sm tts-app-voice" value="' + esc(a.voice || '') + '" placeholder="engine default" style="min-width:8rem;"></td>'
                + '<td><input type="number" class="form-control form-control-sm tts-app-rate" value="' + a.rate + '" style="width:6rem;"></td>'
                + '<td><select class="form-select form-select-sm tts-app-fallback">' + engineOptions(a.fallback_engine_id) + '</select></td>'
                + '<td><button class="btn btn-xs btn-primary tts-app-save"><i class="bi bi-save"></i></button></td>'
                + '</tr>';
        }).join('');
    }

    // ── Engine editor modal ──────────────────────────────────────────
    function driverFieldsHtml(driver, cfg) {
        cfg = cfg || {};
        var d = state.drivers[driver];
        if (!d) return '';
        var labels = {
            bin: 'Piper binary path', voice: 'Voice model / voice name',
            native_rate: 'Native sample rate (Hz)', ffmpeg: 'ffmpeg path (or "ffmpeg")',
            endpoint: 'Endpoint base URL', model: 'Model', in_rate: 'Server PCM rate (Hz)',
            encoding: 'Encoding (linear16 / mulaw / alaw)'
        };
        var ph = {
            bin: '/opt/piper/piper', voice: '/opt/piper/voices/en_US-lessac-medium.onnx',
            native_rate: '22050', ffmpeg: 'ffmpeg',
            endpoint: 'http://127.0.0.1:8880/v1', model: 'kokoro', in_rate: '24000',
            encoding: 'linear16'
        };
        return d.fields.map(function (f) {
            var val = cfg[f] !== undefined && cfg[f] !== null ? cfg[f] : '';
            return '<div class="mb-2"><label class="form-label form-label-sm mb-0">' + esc(labels[f] || f) + '</label>'
                 + '<input class="form-control form-control-sm tts-cfg" data-field="' + f + '" value="' + esc(val) + '" placeholder="' + esc(ph[f] || '') + '"></div>';
        }).join('');
    }

    function openEngineModal(engine) {
        engine = engine || { id: 0, engine_key: '', driver: 'piper', label: '', config: {}, enabled: 1 };
        document.getElementById('ttsEngId').value = engine.id || 0;
        document.getElementById('ttsEngKey').value = engine.engine_key || '';
        document.getElementById('ttsEngLabel').value = engine.label || '';
        document.getElementById('ttsEngEnabled').checked = engine.enabled !== 0;
        document.getElementById('ttsEngKey2').value = '';
        document.getElementById('ttsEngineModalTitle').textContent = engine.id ? 'Edit engine' : 'Add engine';

        var driverSel = document.getElementById('ttsEngDriver');
        driverSel.innerHTML = Object.keys(state.drivers).map(function (k) {
            return '<option value="' + k + '"' + (k === engine.driver ? ' selected' : '') + '>' + esc(state.drivers[k].label) + '</option>';
        }).join('');

        function refreshFields() {
            var drv = driverSel.value;
            document.getElementById('ttsEngFields').innerHTML = driverFieldsHtml(drv, engine.config);
            var needsKey = state.drivers[drv] && state.drivers[drv].needs_key;
            document.getElementById('ttsEngKeyWrap').style.display = needsKey ? '' : 'none';
        }
        driverSel.onchange = refreshFields;
        refreshFields();

        if (!engineModal) engineModal = new bootstrap.Modal(document.getElementById('ttsEngineModal'));
        engineModal.show();
    }

    function collectEngineForm() {
        var cfg = {};
        document.querySelectorAll('#ttsEngFields .tts-cfg').forEach(function (inp) {
            if (inp.value !== '') cfg[inp.getAttribute('data-field')] = inp.value;
        });
        var payload = {
            id: parseInt(document.getElementById('ttsEngId').value, 10) || 0,
            engine_key: document.getElementById('ttsEngKey').value.trim(),
            driver: document.getElementById('ttsEngDriver').value,
            label: document.getElementById('ttsEngLabel').value.trim(),
            enabled: document.getElementById('ttsEngEnabled').checked ? 1 : 0,
            config: cfg
        };
        var key = document.getElementById('ttsEngKey2').value;
        if (key) payload.api_key = key;
        return payload;
    }

    function playAudio(dataUri) {
        var a = document.getElementById('ttsTestAudio');
        a.src = dataUri;
        a.play().catch(function () {});
    }

    function runTest(engineId, voice) {
        toast('Synthesizing test audio…', 'info');
        postJSON({ action: 'test', engine_id: engineId || 0, voice: voice || '' })
            .then(function (res) {
                if (!res || !res.success) {
                    var extra = (res && res.failovers && res.failovers.length)
                        ? ' (' + res.failovers.map(function (f) { return (f.engine || f.engine_id) + ': ' + f.detail; }).join('; ') + ')' : '';
                    toast('Test failed: ' + ((res && res.error) || 'unknown') + extra, 'danger');
                    return;
                }
                toast('Playing sample from engine "' + res.engine + '"', 'success');
                playAudio(res.audio);
            })
            .catch(function () { toast('Network error during test', 'danger'); });
    }

    // ── Event wiring ─────────────────────────────────────────────────
    document.addEventListener('DOMContentLoaded', function () {
        load();

        document.getElementById('ttsAddEngine').addEventListener('click', function () { openEngineModal(null); });

        document.getElementById('ttsEnginesBody').addEventListener('click', function (e) {
            var edit = e.target.closest('.tts-edit');
            if (edit) {
                var id = parseInt(edit.getAttribute('data-id'), 10);
                openEngineModal(state.engines.filter(function (x) { return x.id === id; })[0]);
                return;
            }
            var del = e.target.closest('.tts-del');
            if (del) {
                if (!confirm('Delete this engine? Applications using it fall back to Piper.')) return;
                postJSON({ action: 'delete_engine', id: parseInt(del.getAttribute('data-id'), 10) })
                    .then(function (r) { if (r.success) { toast('Engine deleted', 'success'); load(); } else { toast(r.error || 'Delete failed', 'danger'); } });
                return;
            }
            var test = e.target.closest('.tts-test-row');
            if (test) { runTest(parseInt(test.getAttribute('data-id'), 10), ''); }
        });

        document.getElementById('ttsEngSave').addEventListener('click', function () {
            var payload = collectEngineForm();
            payload.action = 'save_engine';
            if (!payload.engine_key) { toast('Name is required', 'warning'); return; }
            postJSON(payload).then(function (r) {
                if (r.success) { toast('Engine saved', 'success'); if (engineModal) engineModal.hide(); load(); }
                else { toast(r.error || 'Save failed', 'danger'); }
            });
        });

        document.getElementById('ttsEngTest').addEventListener('click', function () {
            // Test the CURRENT form (save first so the engine exists), then test it.
            var payload = collectEngineForm();
            payload.action = 'save_engine';
            if (!payload.engine_key) { toast('Name is required to test', 'warning'); return; }
            postJSON(payload).then(function (r) {
                if (!r.success) { toast(r.error || 'Save failed', 'danger'); return; }
                document.getElementById('ttsEngId').value = r.id;
                load();
                runTest(r.id, '');
            });
        });

        document.getElementById('ttsAppsBody').addEventListener('click', function (e) {
            var save = e.target.closest('.tts-app-save');
            if (!save) return;
            var row = save.closest('tr');
            postJSON({
                action: 'save_application',
                app_key: row.getAttribute('data-app'),
                engine_id: row.querySelector('.tts-app-engine').value,
                voice: row.querySelector('.tts-app-voice').value,
                rate: parseInt(row.querySelector('.tts-app-rate').value, 10) || 8000,
                fallback_engine_id: row.querySelector('.tts-app-fallback').value
            }).then(function (r) {
                if (r.success) { toast('Application saved', 'success'); }
                else { toast(r.error || 'Save failed', 'danger'); }
            });
        });
    });
})();
