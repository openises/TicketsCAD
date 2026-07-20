<?php
/**
 * NewUI v4.0 — Unit History Log
 *
 * Phase 104g (a beta tester GH #15, 2026-07-02) — single timeline showing
 * everything that happened to a responder / unit:
 *   * Dispatches + clears (from assigns)
 *   * Status transitions (from log)
 *   * Action-log rows (from action)
 *   * Free-form notes (from responder_notes)
 *
 * The notes surface answers Eric's follow-on ask on 2026-07-02:
 * "notes for responders and their units — useful for constructing
 * ICS-214 reports for the people who were operating their units."
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/inc/i18n.php';
require_once __DIR__ . '/inc/rbac.php';

// 2026-07-04 (GH #13) — pick the session profile matching the
// client's cookie (TCADMOBILE vs PHPSESSID). Without this, a
// browser holding a mobile cookie opens an empty desktop session
// here and bounces to login -> redirect loop.
require_once __DIR__ . '/inc/session-bootstrap.php';
sess_bootstrap_auto();
session_start();
if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
require_once __DIR__ . '/inc/force-pw-change.php';
force_pw_change_redirect();

$theme    = $_SESSION['day_night'] ?? 'Day';
$bs_theme = ($theme === 'Night') ? 'dark' : 'light';
$csrf     = csrf_token();
$active_page = 'roster';

$rid = (int) ($_GET['responder_id'] ?? 0);
?>
<!DOCTYPE html>
<html lang="<?php echo e(i18n_lang()); ?>" data-bs-theme="<?php echo $bs_theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?php echo e($csrf); ?>">
    <title>Unit History &mdash; Tickets NewUI <?php echo NEWUI_VERSION; ?></title>
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?php echo NEWUI_VERSION; ?>">
    <link rel="stylesheet" href="assets/css/mobile.css">
    <link rel="stylesheet" href="assets/css/print.css" media="print">
    <style>
        .uh-event { border-left: 3px solid var(--bs-border-color); padding: 6px 10px; margin-bottom: 6px; background: var(--bs-body-tertiary-bg, transparent); border-radius: 0 6px 6px 0; }
        .uh-event.uh-note          { border-left-color: #6f42c1; }
        .uh-event.uh-status_change { border-left-color: #0d6efd; }
        .uh-event.uh-action        { border-left-color: #198754; }
        .uh-event.uh-assign        { border-left-color: #fd7e14; }
        .uh-when { font-size: 0.75rem; color: var(--bs-body-secondary); }
        .uh-kind { font-size: 0.65rem; text-transform: uppercase; padding: 1px 6px; border-radius: 3px; background: var(--bs-secondary-bg); }
    </style>
</head>
<body>

<?php include_once NEWUI_ROOT . '/inc/navbar.php'; ?>

<main id="main-content" class="container-fluid p-3">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <h5 class="mb-0">
            <i class="bi bi-clock-history text-primary me-2"></i>Unit History Log
            <span id="unitLabel" class="text-body-secondary small ms-2"></span>
        </h5>
        <div class="d-flex gap-2">
            <a href="units.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Units</a>
        </div>
    </div>

    <p class="text-body-secondary small mb-3">
        Merged timeline for a single unit — dispatches, status changes, action-log
        notes, and free-form notes shown newest-first. Notes here are useful for
        ICS-214 report construction — capture what the unit did in a plain-English
        sentence and it flows into any 214 export you run later.
        See <a href="help.php#topic-unit-history" target="_blank">help.php — Unit History &amp; Notes</a>.
    </p>

    <?php if ($rid <= 0): ?>
        <div class="card">
            <div class="card-body">
                <div class="mb-2 small">Pick a unit:</div>
                <select class="form-select form-select-sm" id="uhPicker" style="max-width: 400px;"></select>
            </div>
        </div>
    <?php else: ?>
        <div class="row g-3">
            <div class="col-lg-8">
                <div class="card">
                    <div class="card-header d-flex align-items-center py-1">
                        <span class="fw-semibold small">Timeline</span>
                        <select class="form-select form-select-sm ms-auto" id="uhFilter" style="width: auto;">
                            <option value="all">All events</option>
                            <option value="note">Notes only</option>
                            <option value="status_change">Status changes</option>
                            <option value="action">Action log</option>
                            <option value="assign">Assignments</option>
                        </select>
                    </div>
                    <div class="card-body" id="uhTimeline">
                        <div class="text-body-secondary small">Loading…</div>
                    </div>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="card">
                    <div class="card-header py-1"><span class="fw-semibold small">Add note</span></div>
                    <div class="card-body">
                        <div class="mb-2">
                            <label class="form-label form-label-sm">Category</label>
                            <input type="text" class="form-control form-control-sm" id="uhCategory"
                                   value="general" maxlength="32">
                            <div class="form-text small">e.g. general, ics-214, incident-XYZ, training.</div>
                        </div>
                        <div class="mb-2">
                            <label class="form-label form-label-sm">Note</label>
                            <textarea class="form-control form-control-sm" id="uhNote" rows="4"
                                      placeholder="What did this unit do?" maxlength="1024"></textarea>
                        </div>
                        <button class="btn btn-sm btn-primary" id="uhAddBtn">
                            <i class="bi bi-plus-lg me-1"></i>Add
                        </button>
                        <span id="uhAddStatus" class="ms-2 small"></span>
                    </div>
                </div>
                <div class="card mt-3">
                    <div class="card-header py-1"><span class="fw-semibold small">Export for ICS-214</span></div>
                    <div class="card-body small">
                        <div class="text-body-secondary mb-2">
                            Copy the notes stream in plain text — paste into your ICS-214.
                        </div>
                        <button class="btn btn-sm btn-outline-secondary" id="uhCopyNotesBtn">
                            <i class="bi bi-clipboard me-1"></i>Copy notes to clipboard
                        </button>
                        <span id="uhCopyStatus" class="ms-2 small"></span>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</main>

<input type="hidden" id="uhResponderId" value="<?php echo (int) $rid; ?>">
<input type="hidden" id="uhCsrf" value="<?php echo e($csrf); ?>">

<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script>
(function () {
    'use strict';
    var rid = parseInt(document.getElementById('uhResponderId').value, 10);
    var csrf = document.getElementById('uhCsrf').value;

    function escHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }
    function fmt(when) {
        if (!when) return '';
        var d = new Date(when.replace(' ', 'T') + 'Z');
        if (isNaN(d.getTime())) return when;
        return d.toLocaleString();
    }

    if (rid <= 0) {
        // Picker mode — populate units.
        fetch('api/responders.php', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var picker = document.getElementById('uhPicker');
                if (!picker) return;
                var opts = '<option value="">— pick a unit —</option>';
                (data.responders || []).forEach(function (r) {
                    var label = (r.handle ? r.handle + ' — ' : '') + (r.name || '');
                    opts += '<option value="' + r.id + '">' + escHtml(label) + '</option>';
                });
                picker.innerHTML = opts;
                picker.addEventListener('change', function () {
                    if (this.value) location.href = 'unit-history.php?responder_id=' + this.value;
                });
            });
        return;
    }

    var events = [];
    function render() {
        var filter = document.getElementById('uhFilter').value;
        var tl = document.getElementById('uhTimeline');
        var filtered = events.filter(function (e) {
            return filter === 'all' || e.kind === filter;
        });
        if (!filtered.length) {
            tl.innerHTML = '<div class="text-body-secondary small">No events.</div>';
            return;
        }
        var html = '';
        filtered.forEach(function (e) {
            html += '<div class="uh-event uh-' + escHtml(e.kind) + '">'
                 + '<div class="d-flex align-items-center gap-2 mb-1">'
                 +   '<span class="uh-kind">' + escHtml(e.kind.replace('_', ' ')) + '</span>'
                 +   '<span class="uh-when">' + escHtml(fmt(e.when)) + '</span>'
                 +   (e.user ? '<span class="uh-when">— ' + escHtml(e.user) + '</span>' : '')
                 + '</div>'
                 + '<div>' + escHtml(e.description || '') + '</div>'
                 + '</div>';
        });
        tl.innerHTML = html;
    }
    function load() {
        fetch('api/unit-history.php?responder_id=' + rid, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                events = data.events || [];
                render();
                // Look up unit label from responders API.
                return fetch('api/responders.php', { credentials: 'same-origin' });
            })
            .then(function (r) { return r ? r.json() : null; })
            .then(function (data) {
                if (!data) return;
                var r = (data.responders || []).find(function (x) { return parseInt(x.id, 10) === rid; });
                if (r) {
                    document.getElementById('unitLabel').textContent =
                        (r.handle ? r.handle + ' — ' : '') + (r.name || '');
                }
            })
            .catch(function () {
                document.getElementById('uhTimeline').innerHTML =
                    '<div class="text-danger small">Failed to load history.</div>';
            });
    }
    load();
    document.getElementById('uhFilter').addEventListener('change', render);

    document.getElementById('uhAddBtn').addEventListener('click', function () {
        var note = document.getElementById('uhNote').value.trim();
        var cat  = document.getElementById('uhCategory').value.trim() || 'general';
        var status = document.getElementById('uhAddStatus');
        if (!note) {
            status.textContent = 'Enter a note first.';
            status.className = 'ms-2 small text-danger';
            return;
        }
        fetch('api/unit-history.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({
                action: 'add_note',
                responder_id: rid,
                note: note,
                category: cat,
                csrf_token: csrf
            })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.id) {
                document.getElementById('uhNote').value = '';
                status.textContent = 'Saved.';
                status.className = 'ms-2 small text-success';
                load();
            } else {
                status.textContent = 'Error: ' + (data.error || 'unknown');
                status.className = 'ms-2 small text-danger';
            }
        })
        .catch(function () {
            status.textContent = 'Network error.';
            status.className = 'ms-2 small text-danger';
        });
    });

    document.getElementById('uhCopyNotesBtn').addEventListener('click', function () {
        var lines = events.filter(function (e) { return e.kind === 'note'; })
                          .map(function (e) {
            return fmt(e.when) + (e.user ? ' [' + e.user + ']' : '') + ' — ' + (e.description || '');
        });
        var txt = lines.join('\n');
        var status = document.getElementById('uhCopyStatus');
        if (!txt) {
            status.textContent = 'No notes to copy.';
            status.className = 'ms-2 small text-body-secondary';
            return;
        }
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(txt).then(function () {
                status.textContent = 'Copied ' + lines.length + ' notes.';
                status.className = 'ms-2 small text-success';
            });
        } else {
            var ta = document.createElement('textarea');
            ta.value = txt; document.body.appendChild(ta); ta.select();
            try { document.execCommand('copy'); } catch (e) {}
            document.body.removeChild(ta);
            status.textContent = 'Copied ' + lines.length + ' notes.';
            status.className = 'ms-2 small text-success';
        }
    });
})();
</script>
</body>
</html>
