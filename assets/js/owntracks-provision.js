/**
 * Shared OwnTracks tracking-token provisioning (GH #84).
 *
 * Extracted from roster.js so BOTH the roster (per-member) and the unit-edit
 * page (per assigned crew member) can offer identical per-member token
 * provisioning without duplicating the logic. Tokens are always per-MEMBER
 * (member_tracking_tokens.member_id); a unit surfaces this for each of its
 * assigned personnel.
 *
 *   window.OTProvision.mount(containerEl, memberId, countEl?)
 *       — render the token panel (list + New/Rotate buttons) into containerEl.
 *   window.OTProvision.provision(memberId, mode)   ('file'|'qr'|'url'|'email')
 *   window.OTProvision.rotate(memberId)
 *   window.OTProvision.revoke(tokenId, memberId)
 *   window.OTProvision.refresh(memberId)  — reload every mounted panel for a member
 *
 * ES5, no build step, self-contained (own esc + csrf helpers).
 */
(function () {
    'use strict';

    var API = 'api/owntracks-config.php';
    var mounts = []; // { el, memberId, countEl }

    function esc(s) {
        if (s === null || s === undefined) return '';
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function csrf() {
        var el = document.getElementById('csrfToken');
        return el ? el.value : '';
    }

    function load(el, memberId, countEl) {
        if (!el) return;
        el.innerHTML = '<div class="text-body-secondary small">Loading tokens…</div>';
        fetch(API + '?action=list_tokens&member_id=' + encodeURIComponent(memberId), { credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : { tokens: [] }; })
            .then(function (d) {
                render(el, d.tokens || [], memberId);
                if (countEl) countEl.textContent = (d.tokens || []).length;
            })
            .catch(function () { el.innerHTML = '<div class="text-danger small">Failed to load tokens.</div>'; });
    }

    function render(el, tokens, memberId) {
        if (!el) return;
        // OwnTracks Android has no QR scanner — the File path is the Android-friendly
        // route; QR is iOS-only. (Verified against owntracks/android LoadActivity.kt.)
        var m = memberId;
        var actionBar = '<div class="d-flex gap-1 mb-2 flex-wrap">'
            + '<button type="button" class="btn btn-sm btn-success" onclick="OTProvision.provision(' + m + ', \'file\')" title="Android-friendly: downloads an .otrc file you load in OwnTracks"><i class="bi bi-file-earmark-arrow-down me-1"></i>New: File (Android)</button>'
            + '<button type="button" class="btn btn-sm btn-success" onclick="OTProvision.provision(' + m + ', \'qr\')" title="iOS-friendly: scan from inside the OwnTracks iOS app"><i class="bi bi-qr-code me-1"></i>New: QR (iOS)</button>'
            + '<button type="button" class="btn btn-sm btn-success" onclick="OTProvision.provision(' + m + ', \'url\')" title="Raw owntracks:///config?inline=… URL"><i class="bi bi-link-45deg me-1"></i>New: URL</button>'
            + '<button type="button" class="btn btn-sm btn-success" onclick="OTProvision.provision(' + m + ', \'email\')"><i class="bi bi-envelope me-1"></i>New: Email</button>'
            + '<button type="button" class="btn btn-sm btn-warning" onclick="OTProvision.rotate(' + m + ')"><i class="bi bi-arrow-repeat me-1"></i>Rotate</button>'
            + '</div>';
        if (!tokens || !tokens.length) {
            el.innerHTML = actionBar + '<div class="text-body-secondary small fst-italic">No OwnTracks tokens have been provisioned for this member yet. Use one of the New buttons above to send a setup link.</div>';
            return;
        }
        var html = actionBar + '<table class="table table-sm table-hover mb-0"><thead><tr>'
            + '<th>#</th><th>Label</th><th>Status</th><th>Created</th><th>Last Used</th><th>Expires</th><th class="text-end">Action</th></tr></thead><tbody>';
        for (var i = 0; i < tokens.length; i++) {
            var t = tokens[i];
            var status = (t.status || 'active');
            var badge = 'secondary';
            if (status === 'active') badge = 'success';
            if (status === 'expiring') badge = 'warning';
            if (status === 'expired') badge = 'secondary';
            if (status === 'revoked') badge = 'danger';
            html += '<tr>'
                + '<td class="text-body-secondary">' + parseInt(t.id, 10) + '</td>'
                + '<td>' + esc(t.token_label || '') + '</td>'
                + '<td><span class="badge bg-' + badge + '">' + esc(status) + '</span></td>'
                + '<td class="text-body-secondary small">' + esc(t.created_at || '') + '</td>'
                + '<td class="text-body-secondary small">' + esc(t.last_used_at || '—') + '</td>'
                + '<td class="text-body-secondary small">' + esc(t.valid_until || '—') + '</td>'
                + '<td class="text-end">';
            if (status !== 'revoked') {
                html += '<button type="button" class="btn btn-xs btn-outline-danger" onclick="OTProvision.revoke(' + parseInt(t.id, 10) + ', ' + m + ')" title="Revoke now"><i class="bi bi-x-octagon"></i></button>';
            }
            html += '</td></tr>';
        }
        html += '</tbody></table>';
        el.innerHTML = html;
    }

    function refresh(memberId) {
        // Drop detached panels, reload the rest for this member.
        mounts = mounts.filter(function (mo) { return document.body.contains(mo.el); });
        mounts.forEach(function (mo) {
            if (String(mo.memberId) === String(memberId)) load(mo.el, mo.memberId, mo.countEl);
        });
    }

    function mount(el, memberId, countEl) {
        if (!el) return;
        mounts = mounts.filter(function (mo) { return mo.el !== el; });
        mounts.push({ el: el, memberId: memberId, countEl: countEl || null });
        load(el, memberId, countEl);
    }

    function provision(memberId, mode) {
        if (mode === 'file') {
            var fileUrl = API + '?action=link&mode=file&member_id=' + memberId;
            var ifr = document.createElement('iframe');
            ifr.style.display = 'none';
            ifr.src = fileUrl;
            document.body.appendChild(ifr);
            setTimeout(function () { document.body.removeChild(ifr); refresh(memberId); }, 2500);

            var w2 = window.open('', '_blank');
            if (w2) {
                w2.document.write('<html><head><title>OwnTracks setup — Android</title>'
                    + '</head><body style="margin:24px;font-family:system-ui;max-width:620px;line-height:1.5">'
                    + '<h3>OwnTracks setup file (.otrc) — Android instructions</h3>'
                    + '<p>A file named <code>owntracks-&lt;username&gt;-&lt;date&gt;.otrc</code> is downloading now '
                    + '(check your browser\'s Downloads folder). It contains this member\'s OwnTracks server URL, '
                    + 'username, and a freshly-minted token — treat it like a password.</p>'
                    + '<h4 style="margin-top:18px">Easiest path — download directly on the phone</h4>'
                    + '<ol><li>Open this admin page on the phone\'s browser (or message yourself the page URL).</li>'
                    + '<li>Click <em>New: File (Android)</em>. The phone saves <code>.otrc</code> to <em>Downloads</em>.</li>'
                    + '<li>Open the phone\'s <strong>Files</strong> app → <em>Downloads</em> → tap the <code>.otrc</code> file.</li>'
                    + '<li>Android will offer to open it with <strong>OwnTracks</strong>. Tap that.</li>'
                    + '<li>OwnTracks opens the import screen showing the new settings. Tap <em>Apply</em>.</li></ol>'
                    + '<h4 style="margin-top:18px">If the Open-With dialog doesn\'t offer OwnTracks</h4>'
                    + '<ol><li>Open the <strong>OwnTracks</strong> app on the phone.</li>'
                    + '<li>☰ menu → <em>Preferences</em> → <em>Configuration management</em>.</li>'
                    + '<li>Tap <em>Import</em>, navigate to <em>Downloads</em> and pick the <code>.otrc</code> file.</li></ol>'
                    + '<p style="background:#fff8e1;border:1px solid #f0c14b;border-radius:6px;padding:10px 12px;margin-top:16px">'
                    + '<strong>Note —</strong> OwnTracks Android does NOT include a QR code scanner. '
                    + 'Use this file path (or the URL button) for Android. The QR button is for iOS only.</p>'
                    + '</body></html>');
                w2.document.close();
            }
            return;
        }

        var url = API + '?action=link&member_id=' + memberId + '&mode=' + encodeURIComponent(mode);
        fetch(url, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d.error) { alert('Provision failed: ' + d.error); return; }
                if (mode === 'email') {
                    alert(d.sent ? ('Setup link emailed to ' + (d.address || 'member')) : 'Email send failed.');
                } else if (mode === 'qr') {
                    var w = window.open('', '_blank');
                    if (w) {
                        w.document.write('<html><head><title>OwnTracks QR (iOS)</title>'
                            + '<script src="assets/vendor/qrcode/qrcode-generator.min.js"></' + 'script>'
                            + '</head><body style="margin:24px;font-family:system-ui;max-width:560px;line-height:1.4">'
                            + '<h3>OwnTracks setup QR — token id ' + d.token_id + '</h3>'
                            + '<div id="qr"></div>'
                            + '<div style="background:#e7f3ff;border:1px solid #5aa9e6;border-radius:6px;padding:10px 12px;margin-top:14px">'
                            + '<strong>iOS users —</strong> open the OwnTracks app → ⓘ <em>Info</em> tab → ⚙ <em>Settings</em> '
                            + '→ <em>Configuration</em> → ⋮ → <em>Scan</em>. Aim the in-app scanner at this QR.</div>'
                            + '<div style="background:#fff0f0;border:1px solid #d35a5a;border-radius:6px;padding:10px 12px;margin-top:10px">'
                            + '<strong>Android users —</strong> close this window and click <em>New: File (Android)</em> instead. '
                            + 'OwnTracks Android does not include a QR scanner.</div>'
                            + '<p style="color:#666;font-size:.85em;word-break:break-all;margin-top:14px">URL: ' + esc(d.qr_text || '') + '</p>'
                            + '<script>var t = qrcode(0, "L"); t.addData(' + JSON.stringify(d.qr_text || '') + '); t.make(); document.getElementById("qr").innerHTML = t.createSvgTag(6);</' + 'script>'
                            + '</body></html>');
                        w.document.close();
                    } else {
                        prompt('Pop-up blocked. Copy this URL and open it on the phone (iOS only — scan from inside the OwnTracks app):', d.qr_text);
                    }
                } else {
                    prompt('OwnTracks setup URL (token id ' + d.token_id + ') — tap on the phone:', d.url);
                }
                refresh(memberId);
            });
    }

    function rotate(memberId) {
        if (!confirm('Rotate this member\'s OwnTracks token? The previous token will keep working for the configured dual-window before expiring.')) return;
        fetch(API + '?action=rotate', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ csrf_token: csrf(), member_id: memberId })
        }).then(function (r) { return r.json(); })
            .then(function (d) {
                if (d.error) { alert('Rotation failed: ' + d.error); return; }
                prompt('New secret (token id ' + d.token_id + '). Copy now — it can\'t be retrieved later.\nWindow: ' + (d.dual_window_days || '?') + ' days.', d.secret_raw || '');
                refresh(memberId);
            });
    }

    function revoke(tokenId, memberId) {
        if (!confirm('Revoke token #' + tokenId + ' immediately? The phone will be locked out on its next post.')) return;
        fetch(API + '?action=revoke', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ csrf_token: csrf(), token_id: tokenId })
        }).then(function (r) { return r.json(); })
            .then(function () { refresh(memberId); });
    }

    window.OTProvision = {
        mount: mount, refresh: refresh,
        provision: provision, rotate: rotate, revoke: revoke
    };
    // Back-compat aliases (roster historically called these global names).
    window.__ot_provision = provision;
    window.__ot_rotate = rotate;
    window.__ot_revoke = revoke;
})();
