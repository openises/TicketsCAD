/**
 * Phase 155 (GH#108 S5) — AllStar Relay (test) settings page.
 * Backend: api/allstar-relay.php (settings / save_settings / test_connection).
 * The AMI secret is never sent to the browser: the page only learns whether one
 * is stored, and a blank secret field on save means "keep the stored one".
 *
 * ES5 only (project rule).
 */
(function () {
    'use strict';

    var csrfToken = document.getElementById('csrfToken').value;
    var FIELDS = {
        ssh_alias: 'arSshAlias',
        ami_host: 'arAmiHost',
        ami_port: 'arAmiPort',
        ami_user: 'arAmiUser',
        extension: 'arExtension',
        remote_audio_dir: 'arAudioDir',
        remote_staging_dir: 'arStagingDir',
        remote_recordings_dir: 'arRecordingsDir'
    };

    function api(action, body) {
        var opts = { credentials: 'same-origin' };
        if (body) {
            body.csrf_token = csrfToken;
            opts.method = 'POST';
            opts.headers = { 'Content-Type': 'application/json' };
            opts.body = JSON.stringify(body);
        }
        return fetch('api/allstar-relay.php?action=' + encodeURIComponent(action), opts)
            .then(function (r) { return r.json(); });
    }

    function toast(msg, isError) {
        var el = document.getElementById('arToast');
        el.className = 'alert ' + (isError ? 'alert-danger' : 'alert-success');
        el.textContent = msg;
        setTimeout(function () { el.className = 'alert d-none'; }, 8000);
    }

    function fill(settings) {
        var k;
        for (k in FIELDS) {
            if (FIELDS.hasOwnProperty(k) && typeof settings[k] !== 'undefined') {
                document.getElementById(FIELDS[k]).value = settings[k];
            }
        }
        document.getElementById('arEnabled').checked = !!settings.enabled;
        document.getElementById('arAmiSecret').value = '';
        document.getElementById('arAmiSecret').placeholder = settings.ami_secret_set ? '(stored -- leave blank to keep)' : '';
        document.getElementById('arSecretHelp').textContent = settings.ami_secret_set
            ? 'A secret is stored. It is never shown again; type a new one only to replace it.'
            : 'No secret is stored yet.';
    }

    function load() {
        api('settings').then(function (res) {
            if (res && res.settings) { fill(res.settings); }
            else { toast((res && res.error) || 'Could not load the settings', true); }
        }).catch(function () { toast('Could not load the settings', true); });
    }

    function save() {
        var body = { enabled: document.getElementById('arEnabled').checked ? 1 : 0 };
        var k;
        for (k in FIELDS) {
            if (FIELDS.hasOwnProperty(k)) { body[k] = document.getElementById(FIELDS[k]).value; }
        }
        var secret = document.getElementById('arAmiSecret').value;
        if (secret !== '') { body.ami_secret = secret; }
        api('save_settings', body).then(function (res) {
            if (res && res.ok) {
                fill(res.settings);
                toast(res.changed && res.changed.length ? 'Saved.' : 'Saved (nothing had changed).', false);
            } else {
                toast((res && res.error) || 'Save failed', true);
            }
        }).catch(function () { toast('Save failed', true); });
    }

    function test() {
        var box = document.getElementById('arTestResult');
        var btn = document.getElementById('arBtnTest');
        btn.disabled = true;
        box.className = 'alert alert-info';
        box.textContent = 'Testing…';
        api('test_connection', {}).then(function (res) {
            btn.disabled = false;
            if (res && typeof res.ok !== 'undefined') {
                box.className = 'alert ' + (res.ok ? 'alert-success' : 'alert-danger');
                box.textContent = res.detail;
            } else {
                box.className = 'alert alert-danger';
                box.textContent = (res && res.error) || 'The test could not run.';
            }
        }).catch(function () {
            btn.disabled = false;
            box.className = 'alert alert-danger';
            box.textContent = 'The test could not run (request error).';
        });
    }

    function init() {
        load();
        document.getElementById('arBtnSave').addEventListener('click', save);
        document.getElementById('arBtnTest').addEventListener('click', test);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
