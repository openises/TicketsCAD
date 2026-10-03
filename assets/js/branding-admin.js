/**
 * NewUI v4.0 - Agency Logo & Branding admin page (GH#142, Phase 155).
 *
 * Backend: api/branding-admin.php. Library: inc/branding.php. The page is
 * branding-admin.php. ES5 IIFE, no framework.
 *
 * Everything that comes from the server (organization names, the uploader's
 * name, the alternative text) reaches the DOM through textContent / setAttribute
 * only, never innerHTML.
 *
 * The live preview uses the SAME classes production uses (assets/css/
 * branding.css), so it cannot drift from what the login page, the top bar and
 * the printed letterhead really render. It updates from the form's current state
 * and from a client-side object URL of a file that has been chosen but not yet
 * uploaded. Nothing is saved until Upload / Save is clicked. The browser checks
 * here (size, type) are a convenience: the server is the authority and re-checks
 * everything by content, never by name or MIME type.
 */
(function () {
    'use strict';

    var API = 'api/branding-admin.php';
    var main = document.getElementById('main-content');
    if (!main) return;

    var csrfMeta = document.querySelector('meta[name="csrf-token"]');
    var csrf = csrfMeta ? csrfMeta.getAttribute('content') : '';
    var status = null;        // last status response
    var pending = {};         // 'install:light' -> object URL of a chosen-but-unsaved file
    var selectedOrg = 0;      // organization id whose slots are open
    var previewNight = false;

    var SIZE_LOGIN = { small: 'branding-size-login-small', medium: 'branding-size-login-medium', large: 'branding-size-login-large' };
    var SIZE_PRINT = { small: 'branding-size-print-small', medium: 'branding-size-print-medium', large: 'branding-size-print-large' };

    // ── tiny DOM helpers ────────────────────────────────────────────────
    function byId(id) { return document.getElementById(id); }

    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text !== undefined && text !== null) n.textContent = String(text);
        return n;
    }

    function clear(node) {
        while (node.firstChild) node.removeChild(node.firstChild);
    }

    function show(node, on) {
        if (!node) return;
        if (on) node.classList.remove('d-none'); else node.classList.add('d-none');
    }

    function announce(message, kind) {
        var box = byId('brToast');
        if (!box) return;
        box.className = 'alert alert-' + (kind || 'info');
        box.textContent = message;
    }

    function request(url, options) {
        return fetch(url, options).then(function (resp) {
            return resp.json().then(function (data) {
                if (!resp.ok || (data && data.error)) {
                    throw new Error((data && data.error) ? data.error : ('Request failed (' + resp.status + ').'));
                }
                return data;
            }, function () {
                throw new Error('The server sent an unreadable reply (' + resp.status + ').');
            });
        });
    }

    function postJson(body) {
        body.csrf_token = csrf;
        return request(API, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify(body)
        });
    }

    function postForm(fd) {
        fd.append('csrf_token', csrf);
        return request(API, { method: 'POST', credentials: 'same-origin', body: fd });
    }

    function formatBytes(n) {
        if (n >= 1048576) return (n / 1048576).toFixed(1) + ' MB';
        if (n >= 1024) return Math.round(n / 1024) + ' KB';
        return n + ' bytes';
    }

    function typeName(mime) {
        if (mime === 'image/png') return 'PNG';
        if (mime === 'image/jpeg') return 'JPEG';
        if (mime === 'image/webp') return 'WebP';
        return mime;
    }

    function altText() {
        var box = byId('brSetAlt');
        var v = box ? box.value.trim() : '';
        if (v !== '') return v;
        return (status && status.settings && status.settings.branding_logo_alt) || 'Agency logo';
    }

    // ── slots: one upload slot per (scope, variant) ─────────────────────
    function slotKey(scope, orgId, variant) {
        return scope === 'install' ? ('install:' + variant) : ('org:' + orgId + ':' + variant);
    }

    function thumb(cls, url) {
        var box = el('div', 'br-thumb ' + cls);
        if (url) {
            var img = el('img');
            img.setAttribute('src', url);
            img.setAttribute('alt', altText());
            box.appendChild(img);
        }
        return box;
    }

    function buildSlot(scope, orgId, variant, meta, editable) {
        var key = slotKey(scope, orgId, variant);
        var idBase = 'brFile-' + key.replace(/[^a-z0-9]+/gi, '-');
        var wrap = el('div', 'br-slot');
        wrap.setAttribute('data-slot', key);

        var heading = variant === 'light' ? 'For light backgrounds' : 'For dark backgrounds (optional)';
        var label = el('label', 'form-label small fw-semibold', heading);
        label.setAttribute('for', idBase);
        wrap.appendChild(label);

        var thumbs = el('div', 'br-thumbs');
        var url = pending[key] || (meta ? meta.url : '');
        thumbs.appendChild(thumb('br-thumb-checker', url));
        thumbs.appendChild(thumb('br-thumb-light', url));
        thumbs.appendChild(thumb('br-thumb-dark', url));
        if (!url) {
            var none = el('span', 'text-body-secondary small align-self-center', 'No logo uploaded.');
            thumbs.appendChild(none);
        }
        wrap.appendChild(thumbs);

        if (meta) {
            wrap.appendChild(el('div', 'small text-body-secondary mb-1',
                typeName(meta.mime) + ', ' + meta.width + ' x ' + meta.height + ' px, ' + formatBytes(meta.byte_size) +
                (meta.uploaded_by_name ? '. Uploaded by ' + meta.uploaded_by_name + ' on ' : '. Updated ') + meta.updated_at +
                '. Fingerprint ' + meta.sha256 + '.'));
        } else if (variant === 'dark') {
            wrap.appendChild(el('div', 'small text-body-secondary mb-1',
                'Without one, dark theme shows the light logo (on a white plate, if you chose that below).'));
        }

        if (editable) {
            var input = el('input', 'form-control form-control-sm');
            input.setAttribute('type', 'file');
            input.setAttribute('id', idBase);
            input.setAttribute('accept', acceptList());
            input.setAttribute('data-file-for', key);
            wrap.appendChild(input);

            var row = el('div', 'mt-1');
            var up = el('button', 'btn btn-sm btn-primary me-2', meta ? 'Replace' : 'Upload');
            up.setAttribute('type', 'button');
            up.setAttribute('data-act', 'upload');
            up.setAttribute('data-slot-btn', key);
            row.appendChild(up);
            if (meta) {
                var rm = el('button', 'btn btn-sm btn-outline-danger', 'Remove');
                rm.setAttribute('type', 'button');
                rm.setAttribute('data-act', 'remove');
                rm.setAttribute('data-slot-btn-rm', key);
                if (variant === 'light') {
                    rm.setAttribute('title', 'Removing the light logo also removes the dark one.');
                }
                row.appendChild(rm);
            }
            wrap.appendChild(row);

            input.addEventListener('change', function () {
                onFileChosen(key, input);
            });
            up.addEventListener('click', function () {
                doUpload(scope, orgId, variant, key, input);
            });
            if (meta) {
                rm.addEventListener('click', function () {
                    doRemove(scope, orgId, variant, key);
                });
            }
        }
        return wrap;
    }

    function acceptList() {
        var list = (status && status.caps && status.caps.accepted) || ['image/png', 'image/jpeg'];
        return list.join(',');
    }

    function renderSlots(container, scope, orgId, logos, editable) {
        clear(container);
        container.appendChild(buildSlot(scope, orgId, 'light', logos.light, editable));
        container.appendChild(buildSlot(scope, orgId, 'dark', logos.dark, editable));
    }

    // ── actions ─────────────────────────────────────────────────────────
    function onFileChosen(key, input) {
        if (pending[key]) {
            try { URL.revokeObjectURL(pending[key]); } catch (e) { /* ignore */ }
            delete pending[key];
        }
        var f = input.files && input.files[0];
        if (f) {
            var problem = precheck(f);
            if (problem) {
                announce(problem, 'warning');
            } else {
                pending[key] = URL.createObjectURL(f);
            }
        }
        renderPreview();
    }

    function precheck(file) {
        if (!file) return 'Choose an image first.';
        var caps = (status && status.caps) || {};
        var name = String(file.name || '').toLowerCase();
        if (/\.svgz?$/.test(name) || file.type === 'image/svg+xml') {
            return 'SVG images are not accepted because they can carry script. Export your logo as a PNG and upload that.';
        }
        if (caps.max_upload_bytes && file.size > caps.max_upload_bytes) {
            return 'That file is larger than ' + formatBytes(caps.max_upload_bytes) + '. Use a smaller image.';
        }
        if (caps.accepted && file.type && caps.accepted.indexOf(file.type) === -1) {
            return 'This server accepts ' + caps.accepted.map(typeName).join(', ') + ' images. Export your logo as a PNG.';
        }
        return '';
    }

    function doUpload(scope, orgId, variant, key, input) {
        var f = input.files && input.files[0];
        var problem = precheck(f);
        if (problem) {
            announce(problem, 'warning');
            input.focus();
            return;
        }
        var fd = new FormData();
        fd.append('action', 'upload_logo');
        fd.append('scope', scope);
        if (scope === 'org') fd.append('org_id', String(orgId));
        fd.append('variant', variant);
        fd.append('logo', f);
        announce('Uploading...', 'info');
        postForm(fd).then(function (r) {
            if (pending[key]) {
                try { URL.revokeObjectURL(pending[key]); } catch (e) { /* ignore */ }
                delete pending[key];
            }
            var msg = (r.success ? 'Logo saved' : 'Logo not saved');
            if (r.logo) msg += ' (' + typeName(r.logo.mime) + ', ' + r.logo.width + ' x ' + r.logo.height + ' px)';
            msg += '.';
            if (r.warnings && r.warnings.length) msg += ' ' + r.warnings.join(' ');
            announce(msg, r.warnings && r.warnings.length ? 'warning' : 'success');
            return loadStatus().then(function () { focusSlot(key); });
        }).catch(function (err) {
            announce(err.message, 'danger');
            input.focus();
        });
    }

    function doRemove(scope, orgId, variant, key) {
        var what = variant === 'light' ? 'the logo (and the dark one with it)' : 'the dark-background logo';
        if (!window.confirm('Remove ' + what + '?')) return;
        var body = { action: 'delete_logo', scope: scope, variant: variant };
        if (scope === 'org') body.org_id = orgId;
        postJson(body).then(function (r) {
            announce(r.deleted > 0 ? 'Logo removed.' : 'There was nothing to remove.', 'success');
            return loadStatus().then(function () { focusSlot(key); });
        }).catch(function (err) {
            announce(err.message, 'danger');
        });
    }

    function focusSlot(key) {
        var btn = document.querySelector('[data-slot-btn="' + key + '"]') || document.querySelector('[data-slot="' + key + '"] input');
        if (btn && btn.focus) btn.focus();
    }

    // ── organizations ───────────────────────────────────────────────────
    function orgDepth(orgs, org) {
        var byId = {};
        var i;
        for (i = 0; i < orgs.length; i++) byId[orgs[i].id] = orgs[i];
        var depth = 0;
        var cur = org;
        while (cur && cur.parent_org_id && byId[cur.parent_org_id] && depth < 8) {
            depth++;
            cur = byId[cur.parent_org_id];
        }
        return depth;
    }

    function renderOrgs() {
        var card = byId('brOrgCard');
        var orgs = status.orgs || [];
        var activeCount = 0;
        var i;
        for (i = 0; i < orgs.length; i++) if (orgs[i].active) activeCount++;

        var orgLogosOn = status.settings.branding_org_logos === '1';
        var visible;
        if (status.can_install) {
            visible = activeCount >= 2;
        } else {
            visible = status.can_org && orgLogosOn;
        }
        show(card, visible);
        if (!visible) return;

        show(byId('brOrgOff'), !orgLogosOn);
        show(byId('brOrgNone'), !status.can_install && status.can_org && status.caller_org_id === 0);

        var body = byId('brOrgTable').getElementsByTagName('tbody')[0];
        clear(body);
        for (i = 0; i < orgs.length; i++) {
            (function (org) {
                var tr = el('tr');
                if (org.id === selectedOrg) tr.className = 'br-org-row-selected';
                var nameCell = el('td');
                var name = el('span', '', org.name);
                name.style.paddingLeft = (orgDepth(orgs, org) * 1.25) + 'rem';
                nameCell.appendChild(name);
                if (!org.active) nameCell.appendChild(el('span', 'badge text-bg-secondary ms-2', 'inactive'));
                tr.appendChild(nameCell);

                var logoCell = el('td');
                if (org.light) {
                    var img = el('img', 'branding-logo');
                    img.setAttribute('src', org.light.url);
                    img.setAttribute('alt', altText());
                    img.style.maxHeight = '28px';
                    logoCell.appendChild(img);
                    logoCell.appendChild(el('span', 'small text-body-secondary ms-2', org.dark ? 'own light + dark' : 'own logo'));
                } else if (org.inherits) {
                    logoCell.appendChild(el('span', 'small text-body-secondary', 'Uses: ' + org.inherits));
                } else {
                    logoCell.appendChild(el('span', 'small text-body-secondary', 'No logo'));
                }
                tr.appendChild(logoCell);

                var actCell = el('td', 'text-end');
                var btn = el('button', 'btn btn-sm btn-outline-primary', 'Manage');
                btn.setAttribute('type', 'button');
                btn.setAttribute('aria-label', 'Manage the logo for ' + org.name);
                btn.addEventListener('click', function () {
                    selectedOrg = org.id;
                    renderOrgs();
                    var first = document.querySelector('#brOrgSlots input[type="file"]');
                    if (first) first.focus();
                });
                actCell.appendChild(btn);
                tr.appendChild(actCell);
                body.appendChild(tr);
            })(orgs[i]);
        }

        // An org-scoped caller has exactly one row: open it straight away.
        if (!status.can_install && orgs.length === 1 && selectedOrg === 0) {
            selectedOrg = orgs[0].id;
        }
        var manage = byId('brOrgManage');
        var chosen = null;
        for (i = 0; i < orgs.length; i++) if (orgs[i].id === selectedOrg) chosen = orgs[i];
        if (!chosen) {
            show(manage, false);
            return;
        }
        show(manage, true);
        byId('brOrgHeading').textContent = chosen.name;
        var editable = status.can_install || (status.can_org && chosen.id === status.caller_org_id && orgLogosOn);
        renderSlots(byId('brOrgSlots'), 'org', chosen.id, { light: chosen.light, dark: chosen.dark }, editable);
    }

    // ── settings ────────────────────────────────────────────────────────
    function settingControls() {
        return document.querySelectorAll('[data-setting]');
    }

    function readControl(node) {
        if (node.type === 'checkbox') return node.checked ? '1' : '0';
        return String(node.value);
    }

    function applySettings() {
        var nodes = settingControls();
        var i;
        for (i = 0; i < nodes.length; i++) {
            var name = nodes[i].getAttribute('data-setting');
            var v = status.settings[name];
            if (v === undefined) continue;
            if (nodes[i].type === 'checkbox') nodes[i].checked = (v === '1'); else nodes[i].value = v;
        }
    }

    function collectSettings() {
        var out = {};
        var nodes = settingControls();
        var i;
        for (i = 0; i < nodes.length; i++) {
            out[nodes[i].getAttribute('data-setting')] = readControl(nodes[i]);
        }
        return out;
    }

    function currentSetting(name) {
        var node = document.querySelector('[data-setting="' + name + '"]');
        if (node) return readControl(node);
        return status && status.settings ? status.settings[name] : '';
    }

    function saveSettings() {
        postJson({ action: 'save_settings', settings: collectSettings() }).then(function (r) {
            status.settings = r.settings;
            applySettings();
            renderOrgs();
            renderPreview();
            announce('Settings saved.', 'success');
            var b = byId('brSaveSettings');
            if (b) b.focus();
        }).catch(function (err) {
            announce(err.message, 'danger');
        });
    }

    // ── live preview ────────────────────────────────────────────────────
    /** Which logo the preview shows: pending file, else stored. */
    function previewSources() {
        var light = '';
        var dark = '';
        if (status.can_install) {
            light = pending['install:light'] || (status.install.light ? status.install.light.url : '');
            dark = pending['install:dark'] || (status.install.dark ? status.install.dark.url : '');
        } else {
            var own = null;
            var i;
            for (i = 0; i < (status.orgs || []).length; i++) if (status.orgs[i].id === status.caller_org_id) own = status.orgs[i];
            var k = 'org:' + status.caller_org_id + ':';
            light = pending[k + 'light'] || (own && own.light ? own.light.url : '') || (status.install.light ? status.install.light.url : '');
            dark = pending[k + 'dark'] || (own && own.dark ? own.dark.url : '') || (status.install.dark ? status.install.dark.url : '');
        }
        return { light: light, dark: dark };
    }

    function logoImgs(parent, src, sizeClass) {
        var plate = currentSetting('branding_dark_fallback') === 'plate';
        var l = el('img', 'branding-logo branding-logo-light ' + sizeClass +
            (src.dark ? ' branding-has-dark' : (plate ? ' branding-logo-plate' : '')));
        l.setAttribute('src', src.light);
        l.setAttribute('alt', altText());
        parent.appendChild(l);
        if (src.dark) {
            var d = el('img', 'branding-logo branding-logo-dark ' + sizeClass);
            d.setAttribute('src', src.dark);
            d.setAttribute('alt', altText());
            parent.appendChild(d);
        }
    }

    function renderPreview() {
        if (!status) return;
        var src = previewSources();
        var theme = previewNight ? 'dark' : 'light';

        // Login card
        var card = byId('brPvLoginCard');
        card.setAttribute('data-bs-theme', theme);
        var login = byId('brPvLogin');
        clear(login);
        var loginOn = currentSetting('branding_login') === '1';
        if (src.light && loginOn) {
            logoImgs(login, src, SIZE_LOGIN[currentSetting('branding_login_size')] || SIZE_LOGIN.medium);
        } else {
            login.appendChild(el('i', 'bi bi-broadcast-pin fs-1 text-primary'));
        }
        login.appendChild(el('h6', 'mt-2 mb-0', 'Tickets NewUI'));
        login.appendChild(el('small', 'text-body-secondary', 'Login screen' + (src.light && !loginOn ? ' (logo switched off)' : '')));

        // Top bar
        var nav = byId('brPvNav');
        nav.setAttribute('data-bs-theme', theme);
        clear(nav);
        var brand = el('span', 'navbar-brand d-flex align-items-center gap-2 mb-0');
        if (currentSetting('branding_navbar') === 'agency' && src.light) {
            logoImgs(brand, src, 'branding-size-navbar');
        } else {
            var product = el('img', 'd-block');
            product.setAttribute('src', 'assets/logo-light.png');
            product.setAttribute('alt', 'Tickets');
            product.setAttribute('height', '36');
            brand.appendChild(product);
        }
        brand.appendChild(el('span', 'fw-semibold', 'Tickets'));
        nav.appendChild(brand);

        // Printed page
        var page = byId('brPvPage');
        clear(page);
        var printOn = currentSetting('branding_print') === '1' && !!src.light;
        var replace = currentSetting('branding_print_banner') === 'replace';
        if (!(printOn && replace)) {
            page.appendChild(el('div', 'br-preview-banner', 'Tickets CAD — Printed ' + new Date().toLocaleDateString()));
        }
        if (printOn) {
            var lh = el('div', 'print-letterhead print-letterhead-visible print-letterhead-align-' + currentSetting('branding_print_align'));
            var img = el('img', 'branding-logo branding-logo-light ' + (SIZE_PRINT[currentSetting('branding_print_size')] || SIZE_PRINT.medium));
            img.setAttribute('src', src.light);
            img.setAttribute('alt', altText());
            lh.appendChild(img);
            page.appendChild(lh);
        } else if (src.light) {
            page.appendChild(el('div', 'small text-body-secondary mb-1', 'Printed letterhead is switched off.'));
        }
        var lines = el('div', 'placeholder-glow');
        var n;
        for (n = 0; n < 4; n++) {
            var bar = el('span', 'placeholder col-' + (n === 3 ? '7' : '12') + ' mb-1 d-block');
            lines.appendChild(bar);
        }
        page.appendChild(lines);
    }

    // ── load ────────────────────────────────────────────────────────────
    function applyStatus(d) {
        status = d;
        show(byId('brSchemaBanner'), d.schema_ready === false);
        show(byId('brGdWarning'), d.caps && d.caps.gd === false);
        if (d.caps) {
            byId('brMaxUpload').textContent = String(Math.round(d.caps.max_upload_bytes / 1048576));
            byId('brMaxStored').textContent = String(Math.round(d.caps.max_stored_bytes / 1024));
            var intro = byId('brInstallIntro');
            intro.setAttribute('data-max-edge', String(d.caps.max_edge));
        }
        show(byId('brInstallReadOnly'), !d.can_install);
        renderSlots(byId('brInstallSlots'), 'install', 0, d.install, d.can_install && d.schema_ready !== false);
        applySettings();
        renderOrgs();
        renderPreview();
    }

    function loadStatus() {
        return request(API + '?action=status', { credentials: 'same-origin' }).then(applyStatus).catch(function (err) {
            announce(err.message, 'danger');
        });
    }

    // ── wire up ─────────────────────────────────────────────────────────
    var nodes = settingControls();
    var i;
    for (i = 0; i < nodes.length; i++) {
        nodes[i].addEventListener('change', renderPreview);
        if (nodes[i].type === 'text') nodes[i].addEventListener('input', renderPreview);
    }
    var saveBtn = byId('brSaveSettings');
    if (saveBtn) saveBtn.addEventListener('click', saveSettings);

    function setNight(on) {
        previewNight = on;
        byId('brPvDay').classList.toggle('active', !on);
        byId('brPvNight').classList.toggle('active', on);
        byId('brPvDay').setAttribute('aria-pressed', on ? 'false' : 'true');
        byId('brPvNight').setAttribute('aria-pressed', on ? 'true' : 'false');
        renderPreview();
    }
    byId('brPvDay').addEventListener('click', function () { setNight(false); });
    byId('brPvNight').addEventListener('click', function () { setNight(true); });

    loadStatus();
})();
