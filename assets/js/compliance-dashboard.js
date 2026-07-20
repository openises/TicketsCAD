/*
 * NewUI v4.0 — Security Compliance Dashboard JS (Phase 10)
 *
 * Fetches /api/security-compliance.php and renders all 7 sections with
 * green/yellow badges depending on each value's CJIS recommendation.
 * Refresh button forces a re-poll.
 *
 * ES5 IIFE, no jQuery, no template literals. Matches the conventions
 * documented in the project's CLAUDE.md.
 */
(function () {
    'use strict';

    function ready(fn) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', fn);
        } else {
            fn();
        }
    }

    function load() {
        var box = document.getElementById('complianceContent');
        if (!box) return;
        box.innerHTML = '<div class="text-center py-5">' +
            '<div class="spinner-border text-primary" role="status"></div>' +
            '<div class="mt-2 text-body-secondary">Loading...</div></div>';

        fetch('api/security-compliance.php', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                box.innerHTML = renderAll(data);
            })
            .catch(function (err) {
                box.innerHTML = '<div class="alert alert-danger">' +
                    '<i class="bi bi-exclamation-triangle me-2"></i>' +
                    'Failed to load: ' + (err && err.message ? esc(err.message) : 'network error') +
                    '</div>';
            });
    }

    function renderAll(data) {
        var d = data || {};
        var html = '';

        html += renderTopBar(d);
        html += '<div class="row g-3">';
        html += '<div class="col-lg-6">' + renderPasswordPolicy(d) + '</div>';
        html += '<div class="col-lg-6">' + renderLockout(d) + '</div>';
        html += '<div class="col-lg-6">' + renderTfa(d) + '</div>';
        html += '<div class="col-lg-6">' + renderSession(d) + '</div>';
        html += '<div class="col-lg-6">' + renderRotation(d) + '</div>';
        html += '<div class="col-lg-6">' + renderAuditHealth(d) + '</div>';
        html += '<div class="col-12">' + renderRecentActivity(d) + '</div>';
        html += '</div>';

        return html;
    }

    function renderTopBar(d) {
        var gen = d.generated_at ? new Date(d.generated_at).toLocaleString() : '—';
        // Compute an overall compliance score: how many of the major
        // checks meet CJIS.
        var checks = [
            d.password_policy && d.password_policy.min_length_meets_cjis,
            d.password_policy && d.password_policy.history_count_meets_cjis,
            d.account_lockout && d.account_lockout.attempts_meets_cjis,
            d.account_lockout && d.account_lockout.duration_meets_cjis,
            d.password_policy && d.password_policy.force_new_users,
            d.two_factor_auth && d.two_factor_auth.system_enabled
        ];
        var passed = 0;
        for (var i = 0; i < checks.length; i++) if (checks[i]) passed++;
        var pct = Math.round(100 * passed / checks.length);
        var color = pct >= 100 ? 'success' : (pct >= 67 ? 'warning' : 'danger');

        return '<div class="card mb-3 border-' + color + '">' +
            '<div class="card-body py-2 d-flex align-items-center justify-content-between">' +
            '<div>' +
            '<strong>Overall CJIS compliance:</strong> ' +
            '<span class="badge bg-' + color + ' ms-1">' + pct + '%</span>' +
            ' <small class="text-body-secondary ms-2">(' + passed + ' of ' + checks.length + ' major checks pass)</small>' +
            '</div>' +
            '<small class="text-body-secondary">Generated: ' + esc(gen) + '</small>' +
            '</div></div>';
    }

    function renderPasswordPolicy(d) {
        var p = d.password_policy || {};
        var ex = d.cjis_expected || {};
        var html = '<div class="card h-100">';
        html += '<div class="card-header"><i class="bi bi-key me-1"></i>Password Policy</div>';
        html += '<div class="card-body">';
        html += '<table class="table table-sm mb-0">';
        html += row('Minimum length',
            p.min_length, ex.password_min_length, p.min_length_meets_cjis,
            'CJIS recommends ≥ ' + ex.password_min_length);
        html += row('History count',
            p.history_count, ex.password_history_count, p.history_count_meets_cjis,
            'CJIS recommends ≥ ' + ex.password_history_count);
        html += row('Rotation reminder (days)',
            p.rotation_days || 'disabled', '—', null,
            p.rotation_days > 0 ? 'Banner fires after this many days' : 'Reminder disabled');
        html += row('Reminder snooze (days)',
            p.snooze_days, '—', null,
            'Days deferred when user clicks "Remind Me Later"');
        html += row('Force change for new users',
            p.force_new_users ? 'YES' : 'NO', 'YES', !!p.force_new_users,
            'New users prompted to choose own password on first login');
        html += '</table></div></div>';
        return html;
    }

    function renderLockout(d) {
        var l = d.account_lockout || {};
        var ex = d.cjis_expected || {};
        var html = '<div class="card h-100">';
        html += '<div class="card-header"><i class="bi bi-lock me-1"></i>Account Lockout</div>';
        html += '<div class="card-body">';
        html += '<table class="table table-sm mb-0">';
        html += row('Max failed attempts',
            l.max_attempts, '≤ ' + ex.lockout_max_attempts, l.attempts_meets_cjis,
            'CJIS recommends ≤ ' + ex.lockout_max_attempts);
        html += row('Window (minutes)', l.window_minutes, '—', null,
            'Failed attempts counted within this window');
        html += row('Lockout duration (minutes)',
            l.duration_minutes, '≥ ' + ex.lockout_duration_minutes, l.duration_meets_cjis,
            'CJIS recommends ≥ ' + ex.lockout_duration_minutes);
        html += '</table></div></div>';
        return html;
    }

    function renderTfa(d) {
        var t = d.two_factor_auth || {};
        var enrolled = t.enrolled_count + ' of ' + t.total_users + ' (' + t.enrollment_pct + '%)';
        var html = '<div class="card h-100">';
        html += '<div class="card-header"><i class="bi bi-shield-lock me-1"></i>Two-Factor Authentication</div>';
        html += '<div class="card-body">';
        html += '<table class="table table-sm mb-0">';
        html += row('System enabled', t.system_enabled ? 'YES' : 'NO', 'YES', !!t.system_enabled,
            'CJIS Advanced Authentication required for CJI access');
        html += row('Enrolled users', enrolled, '—', null,
            'Users with at least a TOTP secret stored');
        html += '</table></div></div>';
        return html;
    }

    function renderSession(d) {
        var s = d.session_management || {};
        var ex = d.cjis_expected || {};
        var html = '<div class="card h-100">';
        html += '<div class="card-header"><i class="bi bi-clock me-1"></i>Session Management</div>';
        html += '<div class="card-body">';
        html += '<table class="table table-sm mb-0">';
        html += row('Idle timeout (minutes)',
            s.timeout_minutes,
            '≤ ' + ex.session_timeout_minutes_max + ' (for CJI access)',
            s.timeout_meets_cjis_for_cji,
            'CJI handling roles should idle out within ' + ex.session_timeout_minutes_max + ' min');
        html += '</table></div></div>';
        return html;
    }

    function renderRotation(d) {
        var r = d.rotation || {};
        var html = '<div class="card h-100">';
        html += '<div class="card-header"><i class="bi bi-arrow-repeat me-1"></i>Password Rotation Status</div>';
        html += '<div class="card-body">';
        html += '<table class="table table-sm mb-0">';
        if (r.reminder_days > 0) {
            html += row('Overdue users',
                r.overdue_users + ' user(s) past ' + r.reminder_days + ' days',
                '—',
                r.overdue_users === 0,
                'Have not changed password in the reminder window');
            html += row('Currently snoozed',
                r.snoozed_users + ' user(s)', '—', null,
                'Active snooze; banner suppressed until expiry');
        } else {
            html += '<tr><td colspan="3" class="text-body-secondary text-center small">' +
                'Rotation reminder is disabled (rotation_days = 0)' +
                '</td></tr>';
        }
        html += '</table></div></div>';
        return html;
    }

    function renderAuditHealth(d) {
        var a = d.audit_log_health || {};
        var html = '<div class="card h-100">';
        html += '<div class="card-header"><i class="bi bi-journal-text me-1"></i>Audit Log Health</div>';
        html += '<div class="card-body">';
        html += '<table class="table table-sm mb-0">';
        html += row('Total rows', (a.total_rows || 0).toLocaleString(),
            '—', a.total_rows > 0, 'Rows in newui_audit_log');
        html += row('Oldest entry', a.oldest_entry || '—', '—', null, 'Audit retention horizon');
        html += row('Newest entry', a.newest_entry || '—', '—', null, 'Last logged event');
        html += '</table></div></div>';
        return html;
    }

    function renderRecentActivity(d) {
        var a = d.auth_activity_7d || {};
        var html = '<div class="card">';
        html += '<div class="card-header"><i class="bi bi-clock-history me-1"></i>Recent Auth Activity (7 days)</div>';
        html += '<div class="card-body">';
        html += '<div class="row g-3">';
        html += statCell('Successful logins', a.logins, 'success');
        html += statCell('Failed logins', a.login_failed, 'warning');
        html += statCell('Lockouts', a.lockouts, 'danger');
        html += statCell('Password changes', a.pw_changes, 'info');
        html += statCell('Admin resets', a.admin_resets, 'warning');
        html += statCell('2FA enrolments', a.tfa_enrols, 'primary');
        html += '</div>';
        html += '</div></div>';
        return html;
    }

    function row(label, current, recommended, meets, hint) {
        var badge = '';
        if (meets === true) {
            badge = '<span class="badge bg-success ms-2">✓</span>';
        } else if (meets === false) {
            badge = '<span class="badge bg-warning ms-2">!</span>';
        }
        return '<tr>' +
            '<td class="text-body-secondary small">' + esc(label) + '<br>' +
            '<span class="text-body-tertiary" style="font-size:0.7rem">' + esc(hint || '') + '</span></td>' +
            '<td class="text-end fw-semibold">' + esc(String(current)) + badge + '</td>' +
            '<td class="text-end text-body-secondary small">' + esc(String(recommended)) + '</td>' +
            '</tr>';
    }

    function statCell(label, value, color) {
        return '<div class="col-md-2 col-6 text-center">' +
            '<div class="display-6 text-' + color + '">' + ((value || 0).toLocaleString()) + '</div>' +
            '<div class="small text-body-secondary">' + esc(label) + '</div>' +
            '</div>';
    }

    function esc(s) {
        if (s === null || s === undefined) return '';
        return String(s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    ready(function () {
        var btn = document.getElementById('btnRefreshCompliance');
        if (btn) btn.addEventListener('click', load);
        load();
    });
})();
