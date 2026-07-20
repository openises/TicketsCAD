/**
 * NewUI v4.0 - Profile 2FA Management
 *
 * Handles the enrollment wizard, device management, backup code
 * regeneration, and 2FA disable flow on profile.php.
 *
 * ES5 IIFE — no arrow functions, no let/const, no template literals.
 */
(function () {
    'use strict';

    // ── State ──────────────────────────────────────────────────
    var enrolled = false;
    var enrollSecret = '';
    var enrollBackupCodes = [];

    // ── DOM refs ───────────────────────────────────────────────
    var statusBadge   = document.getElementById('tfaStatusBadge');
    var statusText    = document.getElementById('tfaStatusText');
    var actionsDiv    = document.getElementById('tfaActions');
    var enrollCard    = document.getElementById('enrollCard');
    var devicesCard   = document.getElementById('devicesCard');
    var regenCard     = document.getElementById('regenCard');

    // ── Helpers ────────────────────────────────────────────────

    function apiGet(url, cb) {
        fetch(url, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) { cb(null, data); })
            .catch(function (err) { cb(err); });
    }

    function apiPost(url, body, cb) {
        body.csrf_token = CSRF_TOKEN;
        fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        })
        .then(function (r) { return r.json(); })
        .then(function (data) { cb(null, data); })
        .catch(function (err) { cb(err); });
    }

    /**
     * Parse a User-Agent string into a short device description.
     */
    function parseUA(ua) {
        if (!ua) return 'Unknown Device';
        var browser = 'Browser';
        var os = 'Unknown OS';

        if (ua.indexOf('Firefox') !== -1) browser = 'Firefox';
        else if (ua.indexOf('Edg/') !== -1) browser = 'Edge';
        else if (ua.indexOf('Chrome') !== -1) browser = 'Chrome';
        else if (ua.indexOf('Safari') !== -1) browser = 'Safari';

        if (ua.indexOf('Windows') !== -1) os = 'Windows';
        else if (ua.indexOf('Mac OS') !== -1) os = 'macOS';
        else if (ua.indexOf('Linux') !== -1) os = 'Linux';
        else if (ua.indexOf('Android') !== -1) os = 'Android';
        else if (ua.indexOf('iPhone') !== -1 || ua.indexOf('iPad') !== -1) os = 'iOS';

        return browser + ' on ' + os;
    }

    function formatDate(dtStr) {
        if (!dtStr) return '—';
        var d = new Date(dtStr.replace(' ', 'T'));
        if (isNaN(d.getTime())) return dtStr;
        return d.toLocaleDateString() + ' ' + d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    }

    function showEl(el) {
        if (el) el.classList.remove('d-none');
    }

    function hideEl(el) {
        if (el) el.classList.add('d-none');
    }

    // ── Load 2FA status ────────────────────────────────────────

    function loadStatus() {
        apiGet('api/tfa.php', function (err, data) {
            if (err || !data) {
                statusText.textContent = 'Unable to load 2FA status.';
                return;
            }
            enrolled = data.enrolled;
            renderStatus(data);
        });
    }

    function renderStatus(data) {
        if (data.enrolled) {
            statusBadge.className = 'badge bg-success';
            statusBadge.textContent = 'Enabled';
            statusText.textContent = 'Two-factor authentication is active on your account.';
            actionsDiv.innerHTML = '<button type="button" class="btn btn-sm btn-outline-danger" id="btnDisable2FA">' +
                '<i class="bi bi-shield-x me-1"></i>Disable 2FA</button>';

            document.getElementById('btnDisable2FA').addEventListener('click', function () {
                var modal = new bootstrap.Modal(document.getElementById('disableModal'));
                modal.show();
                setTimeout(function () {
                    document.getElementById('disablePassword').focus();
                }, 300);
            });

            hideEl(enrollCard);
            showEl(devicesCard);
            showEl(regenCard);
            loadDevices();
        } else {
            statusBadge.className = 'badge bg-secondary';
            statusBadge.textContent = 'Not Enrolled';

            var msg = 'Two-factor authentication is not set up.';
            if (data.required) {
                msg += ' Your role requires 2FA enrollment.';
            }
            statusText.textContent = msg;

            actionsDiv.innerHTML = '<button type="button" class="btn btn-sm btn-primary" id="btnSetup2FA">' +
                '<i class="bi bi-shield-plus me-1"></i>Set Up 2FA</button>';

            document.getElementById('btnSetup2FA').addEventListener('click', startEnrollment);

            hideEl(devicesCard);
            hideEl(regenCard);
        }
    }

    // ── Enrollment Wizard ──────────────────────────────────────

    function setWizardStep(stepNum) {
        var steps = document.querySelectorAll('.tfa-step');
        for (var i = 0; i < steps.length; i++) {
            var s = parseInt(steps[i].getAttribute('data-step'), 10);
            steps[i].className = 'tfa-step' + (s === stepNum ? ' active' : (s < stepNum ? ' done' : ''));
        }
        var panels = document.querySelectorAll('.tfa-wizard-panel');
        for (var j = 0; j < panels.length; j++) {
            panels[j].style.display = 'none';
        }
        var target = document.getElementById('step' + stepNum);
        if (target) target.style.display = 'block';
    }

    function startEnrollment() {
        showEl(enrollCard);
        setWizardStep(1);
        document.getElementById('enrollPassword').value = '';
        document.getElementById('enrollPassword').classList.remove('is-invalid');
        document.getElementById('enrollPassword').focus();
    }

    // Step 1: confirm password -> enroll
    function handleStep1() {
        var pw = document.getElementById('enrollPassword');
        var errDiv = document.getElementById('enrollPasswordError');
        pw.classList.remove('is-invalid');

        if (!pw.value) {
            pw.classList.add('is-invalid');
            errDiv.textContent = 'Password is required.';
            pw.focus();
            return;
        }

        var btn = document.getElementById('btnEnrollStep1');
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Verifying...';

        apiPost('api/tfa.php', { action: 'enroll', password: pw.value }, function (err, data) {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-arrow-right me-1"></i>Continue';

            if (err || !data || data.error) {
                pw.classList.add('is-invalid');
                errDiv.textContent = (data && data.error) ? data.error : 'Enrollment failed.';
                pw.focus();
                return;
            }

            enrollSecret = data.secret;
            enrollBackupCodes = data.backup_codes || [];

            // Render QR code locally using qrcode-generator library
            var qrContainer = document.getElementById('qrCodeContainer');
            qrContainer.innerHTML = '';
            try {
                var qr = qrcode(0, 'M');
                qr.addData(data.uri);
                qr.make();
                qrContainer.innerHTML = qr.createSvgTag(4, 0);
                var svg = qrContainer.querySelector('svg');
                if (svg) {
                    svg.setAttribute('width', '250');
                    svg.setAttribute('height', '250');
                    svg.style.display = 'block';
                    svg.style.margin = '0 auto';
                }
            } catch (qrErr) {
                qrContainer.innerHTML = '<div class="alert alert-warning py-2 small">' +
                    '<i class="bi bi-exclamation-triangle me-1"></i>' +
                    'Could not generate QR code. Please enter the secret key manually.</div>';
            }

            // Show account info
            document.getElementById('tfaAccountName').textContent = 'TicketsCAD (' + (data.uri.split(':')[1] || '').split('?')[0] + ')';
            document.getElementById('tfaSecretKey').value = data.secret;

            setWizardStep(2);
        });
    }

    // Step 2: user confirms they scanned
    function handleStep2() {
        setWizardStep(3);
        setTimeout(function () {
            document.getElementById('enrollVerifyCode').focus();
        }, 100);
    }

    // Step 3: verify TOTP code
    function handleStep3() {
        var codeInput = document.getElementById('enrollVerifyCode');
        var errDiv = document.getElementById('enrollVerifyError');
        codeInput.classList.remove('is-invalid');

        var code = codeInput.value.replace(/[^0-9]/g, '');
        if (code.length !== 6) {
            codeInput.classList.add('is-invalid');
            errDiv.textContent = 'Enter a 6-digit code.';
            codeInput.focus();
            return;
        }

        var btn = document.getElementById('btnEnrollStep3');
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Verifying...';

        apiPost('api/tfa.php', { action: 'confirm', code: code }, function (err, data) {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-check-lg me-1"></i>Verify';

            if (err || !data || data.error) {
                codeInput.classList.add('is-invalid');
                errDiv.textContent = (data && data.error) ? data.error : 'Verification failed.';
                codeInput.value = '';
                codeInput.focus();
                return;
            }

            // Success — show backup codes
            renderBackupCodes(enrollBackupCodes, 'backupCodesGrid');
            setWizardStep(4);
        });
    }

    function renderBackupCodes(codes, containerId) {
        var grid = document.getElementById(containerId);
        grid.innerHTML = '';
        for (var i = 0; i < codes.length; i++) {
            var span = document.createElement('span');
            span.className = 'tfa-backup-code';
            span.textContent = codes[i];
            grid.appendChild(span);
        }
    }

    function codesToText(codes) {
        var lines = ['TicketsCAD — Two-Factor Authentication Backup Codes',
            'Generated: ' + new Date().toLocaleString(),
            '================================================',
            ''];
        for (var i = 0; i < codes.length; i++) {
            lines.push('  ' + codes[i]);
        }
        lines.push('');
        lines.push('Each code can only be used once.');
        lines.push('Store these codes in a safe place.');
        return lines.join('\n');
    }

    function copyToClipboard(text, btn) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function () {
                var orig = btn.innerHTML;
                btn.innerHTML = '<i class="bi bi-check me-1"></i>Copied!';
                setTimeout(function () { btn.innerHTML = orig; }, 2000);
            });
        }
    }

    function downloadTextFile(text, filename) {
        var blob = new Blob([text], { type: 'text/plain' });
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        setTimeout(function () {
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        }, 100);
    }

    // ── Remembered Devices ─────────────────────────────────────

    function loadDevices() {
        apiGet('api/tfa.php?action=devices', function (err, data) {
            var tbody = document.getElementById('devicesBody');
            var revokeAllBtn = document.getElementById('btnRevokeAll');

            if (err || !data || !data.devices) {
                tbody.innerHTML = '<tr><td colspan="5" class="text-center text-body-secondary py-3">Unable to load devices.</td></tr>';
                return;
            }

            var devices = data.devices;

            if (devices.length === 0) {
                tbody.innerHTML = '<tr><td colspan="5" class="text-center text-body-secondary py-3">No remembered devices.</td></tr>';
                hideEl(revokeAllBtn);
                return;
            }

            showEl(revokeAllBtn);
            tbody.innerHTML = '';

            for (var i = 0; i < devices.length; i++) {
                var d = devices[i];
                var tr = document.createElement('tr');
                tr.setAttribute('data-device-id', d.id);
                tr.innerHTML = '<td>' + escHtml(parseUA(d.user_agent)) + '</td>' +
                    '<td class="font-monospace small">' + escHtml(d.ip_address) + '</td>' +
                    '<td class="small">' + escHtml(formatDate(d.created_at)) + '</td>' +
                    '<td class="small">' + escHtml(formatDate(d.expires_at)) + '</td>' +
                    '<td><button type="button" class="btn btn-sm btn-outline-danger btn-revoke" data-id="' + d.id + '">' +
                    '<i class="bi bi-x-lg"></i></button></td>';
                tbody.appendChild(tr);
            }

            // Attach revoke handlers
            var btns = tbody.querySelectorAll('.btn-revoke');
            for (var j = 0; j < btns.length; j++) {
                btns[j].addEventListener('click', handleRevokeDevice);
            }
        });
    }

    function handleRevokeDevice(e) {
        var btn = e.currentTarget;
        var deviceId = parseInt(btn.getAttribute('data-id'), 10);
        btn.disabled = true;

        apiPost('api/tfa.php', { action: 'revoke_device', device_id: deviceId }, function (err, data) {
            if (err || !data || data.error) {
                btn.disabled = false;
                return;
            }
            // Remove the row
            var row = btn.closest('tr');
            if (row) row.remove();

            // Check if table is now empty
            var remaining = document.querySelectorAll('#devicesBody tr');
            if (remaining.length === 0) {
                document.getElementById('devicesBody').innerHTML =
                    '<tr><td colspan="5" class="text-center text-body-secondary py-3">No remembered devices.</td></tr>';
                hideEl(document.getElementById('btnRevokeAll'));
            }
        });
    }

    function handleRevokeAll() {
        if (!confirm('Revoke all remembered devices? You will need to enter a 2FA code on your next login from each device.')) {
            return;
        }
        var btn = document.getElementById('btnRevokeAll');
        btn.disabled = true;

        apiPost('api/tfa.php', { action: 'revoke_all_devices' }, function (err, data) {
            btn.disabled = false;
            if (err || !data || data.error) return;
            document.getElementById('devicesBody').innerHTML =
                '<tr><td colspan="5" class="text-center text-body-secondary py-3">No remembered devices.</td></tr>';
            hideEl(btn);
        });
    }

    // ── Regenerate Backup Codes ────────────────────────────────

    function handleRegenerate() {
        var codeInput = document.getElementById('regenCode');
        var errDiv = document.getElementById('regenCodeError');
        codeInput.classList.remove('is-invalid');

        var code = codeInput.value.replace(/[^0-9]/g, '');
        if (code.length !== 6) {
            codeInput.classList.add('is-invalid');
            errDiv.textContent = 'Enter a 6-digit code.';
            codeInput.focus();
            return;
        }

        var btn = document.getElementById('btnRegenCodes');
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Regenerating...';

        apiPost('api/tfa.php', { action: 'regenerate', code: code }, function (err, data) {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-arrow-repeat me-1"></i>Regenerate';

            if (err || !data || data.error) {
                codeInput.classList.add('is-invalid');
                errDiv.textContent = (data && data.error) ? data.error : 'Regeneration failed.';
                codeInput.value = '';
                codeInput.focus();
                return;
            }

            var newCodes = data.backup_codes || [];
            renderBackupCodes(newCodes, 'regenCodesGrid');
            showEl(document.getElementById('regenResult'));
            hideEl(document.getElementById('regenForm'));

            // Wire copy/download for regen codes
            document.getElementById('btnCopyRegenCodes').addEventListener('click', function () {
                copyToClipboard(newCodes.join('\n'), this);
            });
            document.getElementById('btnDownloadRegenCodes').addEventListener('click', function () {
                downloadTextFile(codesToText(newCodes), 'ticketscad-backup-codes.txt');
            });
        });
    }

    // ── Disable 2FA ────────────────────────────────────────────

    function handleDisable() {
        var pw = document.getElementById('disablePassword');
        var code = document.getElementById('disableCode');
        var errDiv = document.getElementById('disableError');
        hideEl(errDiv);

        if (!pw.value || !code.value) {
            showEl(errDiv);
            errDiv.textContent = 'Both password and authentication code are required.';
            return;
        }

        var btn = document.getElementById('btnDisableConfirm');
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Disabling...';

        apiPost('api/tfa.php', { action: 'disable', password: pw.value, code: code.value }, function (err, data) {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-shield-x me-1"></i>Disable 2FA';

            if (err || !data || data.error) {
                showEl(errDiv);
                errDiv.textContent = (data && data.error) ? data.error : 'Failed to disable 2FA.';
                return;
            }

            // Close modal and refresh status
            var modalEl = document.getElementById('disableModal');
            var modal = bootstrap.Modal.getInstance(modalEl);
            if (modal) modal.hide();

            // Clear fields
            pw.value = '';
            code.value = '';

            enrolled = false;
            loadStatus();
        });
    }

    // ── HTML escaping ──────────────────────────────────────────

    function escHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str || ''));
        return div.innerHTML;
    }

    // ── Auto-submit on 6 digits ────────────────────────────────

    function autoSubmitOnComplete(inputEl, callback) {
        inputEl.addEventListener('input', function () {
            var val = this.value.replace(/[^0-9]/g, '');
            this.value = val;
            if (val.length === 6) {
                callback();
            }
        });
    }

    // ── Event Binding ──────────────────────────────────────────

    document.getElementById('btnEnrollStep1').addEventListener('click', handleStep1);
    document.getElementById('btnEnrollStep2').addEventListener('click', handleStep2);
    document.getElementById('btnEnrollStep3').addEventListener('click', handleStep3);

    // Auto-submit enrollment verify code
    autoSubmitOnComplete(document.getElementById('enrollVerifyCode'), handleStep3);

    // Step 4 done button
    document.getElementById('btnEnrollDone').addEventListener('click', function () {
        hideEl(enrollCard);
        enrolled = true;
        loadStatus();
    });

    // Copy/download backup codes (enrollment)
    document.getElementById('btnCopyCodes').addEventListener('click', function () {
        copyToClipboard(enrollBackupCodes.join('\n'), this);
    });
    document.getElementById('btnDownloadCodes').addEventListener('click', function () {
        downloadTextFile(codesToText(enrollBackupCodes), 'ticketscad-backup-codes.txt');
    });

    // Copy secret key
    document.getElementById('btnCopySecret').addEventListener('click', function () {
        copyToClipboard(document.getElementById('tfaSecretKey').value, this);
    });

    // Revoke all
    document.getElementById('btnRevokeAll').addEventListener('click', handleRevokeAll);

    // Regenerate
    document.getElementById('btnRegenCodes').addEventListener('click', handleRegenerate);

    // Disable
    document.getElementById('btnDisableConfirm').addEventListener('click', handleDisable);

    // Auto-submit disable code
    autoSubmitOnComplete(document.getElementById('disableCode'), handleDisable);

    // ── Init ───────────────────────────────────────────────────
    loadStatus();

})();
