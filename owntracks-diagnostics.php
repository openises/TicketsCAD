<?php
/**
 * NewUI v4 — OwnTracks Diagnostics
 *
 * Phase 53 (2026-06-14). Built after a multi-phase debug saga
 * where setConfiguration pushes kept getting silently dropped by
 * OwnTracks Android (wrong cmd wrapper). This page surfaces enough
 * to diagnose that class of bug without SSH'ing into the DB:
 *
 *   - Install-wide table of every active OwnTracks member with
 *     last-post + post counts + outbox depth + override badge
 *   - Per-member drill-down:
 *       * effective_config (what we'd push right now)
 *       * layer breakdown (A hardcoded / B admin / C member / D incident)
 *       * tid expected vs actual (canary for "did the last push land?")
 *       * recent posts table with mode/trigger/connection/battery
 *       * post-gap histogram (visible jitter)
 *       * outbox log (pending + recently consumed payloads)
 *       * active incident assignments (which drive Layer D)
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

if (!rbac_can('action.manage_config')) {
    http_response_code(403);
    echo '<h1>Forbidden</h1><p>Requires <code>action.manage_config</code>.</p>';
    exit;
}

$user     = e($_SESSION['user']);
$level    = current_role_name();
$theme    = $_SESSION['day_night'] ?? 'Day';
$bs_theme = ($theme === 'Night') ? 'dark' : 'light';
$csrf     = csrf_token();
?>
<!DOCTYPE html>
<html lang="<?php echo e(i18n_lang()); ?>" data-bs-theme="<?php echo $bs_theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?php echo e($csrf); ?>">
    <title>OwnTracks Diagnostics — <?php echo e(t('login.title', 'Tickets NewUI')); ?> <?php echo NEWUI_VERSION; ?></title>
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?php echo asset_v('assets/css/dashboard.css'); ?>">
    <style>
        .badge-layer-D-incident { background:#dc3545; color:#fff }
        .badge-layer-C-member   { background:#fd7e14; color:#fff }
        .badge-layer-B-admin    { background:#0d6efd; color:#fff }
        .badge-layer-A-hardcoded { background:#6c757d; color:#fff }
        .gap-bar { display:inline-block; width:6px; vertical-align:bottom; background:#0d6efd; margin-right:1px }
        .gap-bar.warn { background:#ffc107 }
        .gap-bar.bad { background:#dc3545 }
        pre.payload { background:var(--bs-body-tertiary-bg); padding:6px 8px; font-size:.75rem; max-height:160px; overflow:auto }
    </style>
</head>
<body>
<?php include_once NEWUI_ROOT . '/inc/navbar.php'; ?>
</header>

<div class="container-fluid p-3">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <h5 class="mb-0">
            <i class="bi bi-geo-alt-fill text-primary me-2"></i>OwnTracks Diagnostics
            <small class="text-body-secondary fw-normal ms-2" id="updatedAt"></small>
        </h5>
        <div class="d-flex gap-2">
            <a href="settings.php#owntracks-defaults" class="btn btn-sm btn-outline-secondary"><i class="bi bi-sliders me-1"></i>Defaults</a>
            <button id="btnRefresh" class="btn btn-sm btn-primary"><i class="bi bi-arrow-clockwise me-1"></i>Refresh</button>
        </div>
    </div>

    <!-- Install-wide summary -->
    <div class="card mb-3">
        <div class="card-header py-2">
            <i class="bi bi-bar-chart me-2"></i><span class="fw-semibold">Install Summary</span>
            <span class="ms-2 small text-body-secondary" id="installTotals">loading…</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0 small" id="membersTable">
                    <thead class="table-light">
                        <tr>
                            <th>Member</th>
                            <th>Username</th>
                            <th>Token</th>
                            <th class="text-end">Posts / 1h</th>
                            <th class="text-end">Posts / 24h</th>
                            <th>Last post</th>
                            <th class="text-end">Outbox</th>
                            <th>Override?</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="membersTableBody">
                        <tr><td colspan="9" class="text-center py-3 text-body-secondary">Loading…</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Per-member drill-down -->
    <div class="card mb-3 d-none" id="memberDrill">
        <div class="card-header py-2 d-flex align-items-center">
            <i class="bi bi-person-badge me-2"></i><span class="fw-semibold" id="drillName">—</span>
            <span class="ms-2 small text-body-secondary" id="drillUsername"></span>
            <button class="btn btn-sm btn-outline-secondary ms-auto" id="btnCloseDrill">
                <i class="bi bi-x"></i>
            </button>
        </div>
        <div class="card-body">
            <!-- Phase 65: provider-state banner — surfaces the silent kill switch -->
            <div class="alert alert-danger d-none mb-3" id="providerDisabledBanner" role="alert">
                <div class="d-flex align-items-start">
                    <i class="bi bi-exclamation-octagon-fill fs-3 me-2"></i>
                    <div class="flex-grow-1">
                        <h6 class="alert-heading mb-1">OwnTracks provider is DISABLED</h6>
                        <div class="small">
                            Every POST to <code>/api/location.php</code> returns 200 but the report is
                            <strong>silently dropped</strong>. Phones can be uploading and no data lands.
                            Re-enable in
                            <a href="settings.php#location-providers" class="alert-link">Settings &rarr; Location Providers</a>.
                        </div>
                        <div class="small mt-1" id="providerDisabledStats"></div>
                    </div>
                </div>
            </div>
            <div class="alert alert-warning d-none mb-3" id="providerQuietBanner" role="alert">
                <i class="bi bi-cloud-slash me-1"></i>
                <strong>Provider is enabled but quiet.</strong>
                <span id="providerQuietDetail"></span>
            </div>

            <div class="row g-3">
                <div class="col-lg-7">
                    <h6 class="mb-2"><i class="bi bi-layers me-1"></i>Effective config (3-layer + incident)</h6>
                    <div class="table-responsive">
                        <table class="table table-sm small mb-2" id="layerTable">
                            <thead class="table-light"><tr><th>Knob</th><th>Effective</th><th>Winning layer</th><th>A hardcoded</th><th>B admin</th><th>C member</th><th>D incident</th></tr></thead>
                            <tbody></tbody>
                        </table>
                    </div>
                    <div class="alert alert-warning small py-2 d-none" id="tidWarn"></div>
                    <div class="alert alert-success small py-2 d-none" id="tidOk"></div>
                </div>
                <div class="col-lg-5">
                    <h6 class="mb-2"><i class="bi bi-fire me-1"></i>Active incident assignments</h6>
                    <div id="drillAssigns" class="small">—</div>
                    <h6 class="mb-2 mt-3"><i class="bi bi-bar-chart-steps me-1"></i>Last 20 post gaps (s)</h6>
                    <div id="gapChart" class="mb-2"></div>
                    <div class="small text-body-secondary" id="gapSummary"></div>
                </div>
            </div>

            <hr class="my-3">
            <div class="row g-3">
                <div class="col-lg-7">
                    <h6 class="mb-2"><i class="bi bi-card-list me-1"></i>Last 20 posts</h6>
                    <div class="table-responsive">
                        <table class="table table-sm small mb-0" id="postsTable">
                            <thead class="table-light"><tr><th>#</th><th>Received</th><th>TID</th><th>Mode</th><th>Conn</th><th class="text-end">Acc m</th><th class="text-end">Vel</th><th class="text-end">Batt</th></tr></thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
                <div class="col-lg-5">
                    <h6 class="mb-2"><i class="bi bi-inbox me-1"></i>Outbox (last 10)</h6>
                    <div id="outboxList"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<input type="hidden" id="csrfToken" value="<?php echo e($csrf); ?>">

<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="assets/js/theme-manager.js"></script>
<script>
(function () {
    'use strict';
    function $(id) { return document.getElementById(id); }
    function esc(s) { var d = document.createElement('div'); d.appendChild(document.createTextNode(s == null ? '' : String(s))); return d.innerHTML; }
    function fmtGapDuration(sec) {
        sec = parseInt(sec, 10);
        if (isNaN(sec) || sec < 0) return '0s';
        if (sec < 60) return sec + 's';
        var m = Math.floor(sec / 60);
        var s = sec % 60;
        if (m < 60) return s ? (m + 'm ' + s + 's') : (m + 'm');
        var h = Math.floor(m / 60);
        m = m % 60;
        return m ? (h + 'h ' + m + 'm') : (h + 'h');
    }
    function fmtAge(ts) {
        if (!ts) return '<span class="text-danger">never</span>';
        // 2026-06-14 (Phase 58): MySQL DATETIME columns are stored in the
        // server's local timezone (training is at -05:00). The previous
        // version appended 'Z' to force JS to treat the string as UTC,
        // which produced a 5-hour skew because Date.now() is in browser
        // local time. Parse as local instead — the server endpoint also
        // returns its current `now` field so the client can sanity-check
        // its own clock drift (rendered separately as "updated …").
        var sec = Math.floor((Date.now() - new Date(ts.replace(' ', 'T')).getTime()) / 1000);
        if (sec < 0) return 'just now';   // future drift / clock skew
        if (sec < 60) return sec + 's ago';
        if (sec < 3600) return Math.floor(sec / 60) + 'm ago';
        if (sec < 86400) return Math.floor(sec / 3600) + 'h ago';
        return Math.floor(sec / 86400) + 'd ago';
    }

    function loadInstall() {
        fetch('api/owntracks-config.php?action=get_install_diagnostics', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d.error) { $('membersTableBody').innerHTML = '<tr><td colspan="9" class="text-danger small p-3">' + esc(d.error) + '</td></tr>'; return; }
                $('updatedAt').textContent = 'updated ' + d.now;
                var t = d.totals || {};
                $('installTotals').textContent =
                    (t.active_members || 0) + ' members · ' +
                    (t.active_tokens || 0) + ' active tokens · ' +
                    (t.posts_1h_total || 0) + ' posts in last hour · ' +
                    (t.outbox_pending_total || 0) + ' outbox pending';
                var rows = d.members || [];
                if (!rows.length) {
                    $('membersTableBody').innerHTML = '<tr><td colspan="9" class="text-center p-3 text-body-secondary">No active OwnTracks tokens. Provision one from Roster → member → OwnTracks Tracking Tokens.</td></tr>';
                    return;
                }
                var html = '';
                for (var i = 0; i < rows.length; i++) {
                    var r = rows[i];
                    var stale = (!r.last_post || (Date.now() - new Date(r.last_post.replace(' ', 'T') + 'Z').getTime()) / 1000 > 1800);
                    html += '<tr' + (stale ? ' class="table-warning"' : '') + '>'
                          + '<td><strong>' + esc(r.member_name) + '</strong></td>'
                          + '<td><code class="small">' + esc(r.username || '—') + '</code></td>'
                          + '<td class="small">#' + r.token_id + ' ' + esc(r.token_label || '') + '</td>'
                          + '<td class="text-end">' + r.posts_1h + '</td>'
                          + '<td class="text-end">' + r.posts_24h + '</td>'
                          + '<td class="small">' + fmtAge(r.last_post) + '</td>'
                          + '<td class="text-end">' + (r.outbox_pending > 0 ? '<span class="badge bg-info text-dark">' + r.outbox_pending + ' pending</span>' : '—') + '</td>'
                          + '<td>' + (r.has_overrides == 1 ? '<span class="badge bg-warning text-dark">custom</span>' : '<span class="badge bg-secondary">inherit</span>') + '</td>'
                          + '<td class="text-end"><button class="btn btn-xs btn-outline-primary drill-btn" data-mid="' + r.member_id + '" data-name="' + esc(r.member_name) + '" data-user="' + esc(r.username || '') + '"><i class="bi bi-zoom-in me-1"></i>Drill</button></td>'
                          + '</tr>';
                }
                $('membersTableBody').innerHTML = html;
                var btns = document.querySelectorAll('.drill-btn');
                for (var b = 0; b < btns.length; b++) {
                    btns[b].addEventListener('click', function (ev) {
                        var t = ev.currentTarget;
                        loadDrill(t.getAttribute('data-mid'), t.getAttribute('data-name'), t.getAttribute('data-user'));
                    });
                }
            })
            .catch(function () { $('membersTableBody').innerHTML = '<tr><td colspan="9" class="text-danger small p-3">Failed to load.</td></tr>'; });
    }

    function loadDrill(memberId, name, username) {
        $('memberDrill').classList.remove('d-none');
        $('drillName').textContent = name || ('Member #' + memberId);
        $('drillUsername').textContent = username ? '— ' + username : '';
        document.getElementById('layerTable').querySelector('tbody').innerHTML = '<tr><td colspan="7" class="p-3 text-center">Loading…</td></tr>';
        fetch('api/owntracks-config.php?action=get_member_diagnostics&member_id=' + encodeURIComponent(memberId),
              { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(renderDrill)
            .catch(function () {
                document.getElementById('layerTable').querySelector('tbody').innerHTML = '<tr><td colspan="7" class="text-danger p-3">Failed to load.</td></tr>';
            });
    }

    function renderDrill(d) {
        if (d.error) {
            document.getElementById('layerTable').querySelector('tbody').innerHTML = '<tr><td colspan="7" class="text-danger p-3">' + esc(d.error) + '</td></tr>';
            return;
        }

        // Phase 65: provider-state banner. Surface "OwnTracks provider
        // is disabled in Settings → Location Providers" loudly — that
        // exact condition silently dropped 7 hours of POSTs on 2026-06-14.
        var disabledBanner = $('providerDisabledBanner');
        var quietBanner = $('providerQuietBanner');
        if (disabledBanner) disabledBanner.classList.add('d-none');
        if (quietBanner) quietBanner.classList.add('d-none');

        var ps = d.provider_state || null;
        if (ps) {
            if (!ps.enabled) {
                var stats = '';
                if (ps.last_ingest_at) {
                    stats = 'Last successful ingest: ' + esc(ps.last_ingest_at) + '.';
                } else {
                    stats = 'No reports have ever landed for this provider.';
                }
                $('providerDisabledStats').innerHTML = stats;
                if (disabledBanner) disabledBanner.classList.remove('d-none');
            } else if (ps.ingest_count_1h === 0) {
                // Enabled but no posts in the last hour — phone is offline,
                // wrong endpoint, auth broken, or the OwnTracks app is paused.
                var detail = 'Zero successful OwnTracks ingests in the last hour';
                if (ps.last_ingest_at) detail += ' (last was ' + esc(ps.last_ingest_at) + ').';
                else detail += '. No reports have ever landed.';
                $('providerQuietDetail').innerHTML = ' ' + detail;
                if (quietBanner) quietBanner.classList.remove('d-none');
            }
        }

        // TID match — the canary for "did our last setConfiguration actually land?"
        if (d.expected_tid && d.actual_tid) {
            if (d.tid_match) {
                $('tidOk').classList.remove('d-none'); $('tidWarn').classList.add('d-none');
                $('tidOk').innerHTML = '<i class="bi bi-check-circle me-1"></i><strong>TID match:</strong> phone is reporting <code>' + esc(d.actual_tid) + '</code> as expected — recent setConfiguration pushes are being accepted.';
            } else {
                $('tidWarn').classList.remove('d-none'); $('tidOk').classList.add('d-none');
                $('tidWarn').innerHTML = '<i class="bi bi-exclamation-triangle me-1"></i><strong>TID mismatch:</strong> we expect <code>' + esc(d.expected_tid) + '</code> but the phone is reporting <code>' + esc(d.actual_tid) + '</code>. The phone has not applied our setConfiguration. Check the OwnTracks app logs for JSON parse errors, or have the user re-import their .otrc file.';
            }
        } else {
            $('tidOk').classList.add('d-none'); $('tidWarn').classList.add('d-none');
        }

        // Layer breakdown
        var lbHtml = '';
        for (var i = 0; i < d.layer_breakdown.length; i++) {
            var lb = d.layer_breakdown[i];
            lbHtml += '<tr>'
                   +   '<td>' + esc(lb.label) + '<br><code class="small text-body-secondary">' + esc(lb.config_key) + '</code></td>'
                   +   '<td><strong>' + esc(lb.effective) + '</strong></td>'
                   +   '<td><span class="badge badge-layer-' + esc(lb.winner) + '">' + esc(lb.winner) + '</span></td>'
                   +   '<td class="text-body-secondary small">' + esc(lb.hardcoded == null ? '—' : lb.hardcoded) + '</td>'
                   +   '<td class="text-body-secondary small">' + esc(lb.admin == null ? '—' : lb.admin) + '</td>'
                   +   '<td class="text-body-secondary small">' + esc(lb.member == null ? '—' : lb.member) + '</td>'
                   +   '<td class="text-body-secondary small">' + esc(lb.incident == null ? '—' : lb.incident) + '</td>'
                   + '</tr>';
        }
        document.getElementById('layerTable').querySelector('tbody').innerHTML = lbHtml;

        // Active incident assignments
        if (d.active_assignments && d.active_assignments.length) {
            var ah = '<ul class="list-unstyled mb-0">';
            for (var a = 0; a < d.active_assignments.length; a++) {
                var as = d.active_assignments[a];
                ah += '<li><span class="badge bg-danger me-1">incident #' + as.ticket_id + '</span> on unit '
                    + esc(as.unit_handle || as.unit_name || as.responder_id) + ' (dispatched ' + esc(as.dispatched || '') + ')</li>';
            }
            ah += '</ul><div class="small text-body-secondary mt-1">Layer D (incident-active) is firing.</div>';
            $('drillAssigns').innerHTML = ah;
        } else {
            $('drillAssigns').innerHTML = '<div class="text-body-secondary fst-italic">No active incident assignments. Layer D is dormant.</div>';
        }

        // Gap histogram (sparkline) — bar i is the gap between
        // recent_posts[i] (newer) and recent_posts[i+1] (older).
        var gh = '';
        var gaps = d.post_gaps || [];
        var posts = d.recent_posts || [];
        var max = Math.max.apply(null, gaps.concat([1]));

        // Pub interval is in MINUTES in OwnTracks config; convert to seconds.
        var pubIntervalSec = null;
        if (d.effective_config && d.effective_config.pubInterval != null) {
            pubIntervalSec = parseInt(d.effective_config.pubInterval, 10) * 60;
        }

        for (var g = 0; g < gaps.length; g++) {
            var gp = gaps[g];
            var h = Math.max(2, Math.round((gp / max) * 32));
            var cls = gp < 10 ? 'bad' : (gp < 45 ? 'warn' : '');

            var newer = posts[g] || null;
            var older = posts[g + 1] || null;

            var tt = '';
            tt += '<div class="text-start"><strong>Gap: ' + fmtGapDuration(gp) + '</strong>';
            tt += ' <span class="text-body-secondary">(' + gp + 's)</span></div>';
            if (newer) {
                tt += '<div class="text-start small mt-1">Newer: #' + newer.id;
                if (newer.received_at) tt += ' &middot; ' + esc(newer.received_at);
                tt += '</div>';
            }
            if (older) {
                tt += '<div class="text-start small">Older: #' + older.id;
                if (older.received_at) tt += ' &middot; ' + esc(older.received_at);
                tt += '</div>';
            }
            if (pubIntervalSec) {
                var diff = gp - pubIntervalSec;
                var sign = diff >= 0 ? '+' : '-';
                tt += '<div class="text-start small mt-1 text-body-secondary">'
                    + 'Expected: ~' + fmtGapDuration(pubIntervalSec) + ' (pubInterval)'
                    + ' &middot; ' + sign + fmtGapDuration(Math.abs(diff)) + '</div>';
            }

            // Plain-text fallback in title= (used if Bootstrap tooltip init fails).
            var fallback = fmtGapDuration(gp) + ' gap (' + gp + 's)';
            if (newer && older) {
                fallback += '\nbetween #' + newer.id + ' and #' + older.id;
            }

            gh += '<div class="gap-bar ' + cls + '" style="height:' + h + 'px"'
                + ' data-bs-toggle="tooltip" data-bs-html="true" data-bs-placement="top"'
                + ' data-bs-title="' + tt.replace(/"/g, '&quot;') + '"'
                + ' title="' + fallback.replace(/"/g, '&quot;') + '"></div>';
        }
        $('gapChart').innerHTML = gh || '<div class="text-body-secondary small fst-italic">Not enough posts yet.</div>';

        // Re-init Bootstrap tooltips on the freshly-rendered bars.
        if (gaps.length && typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
            var bars = document.querySelectorAll('#gapChart .gap-bar');
            for (var b = 0; b < bars.length; b++) {
                var existing = bootstrap.Tooltip.getInstance(bars[b]);
                if (existing) existing.dispose();
                new bootstrap.Tooltip(bars[b]);
            }
        }

        if (gaps.length) {
            var sum = 0, mn = gaps[0], mx = gaps[0];
            for (var x = 0; x < gaps.length; x++) { sum += gaps[x]; if (gaps[x] < mn) mn = gaps[x]; if (gaps[x] > mx) mx = gaps[x]; }
            $('gapSummary').textContent = 'min ' + mn + 's · avg ' + Math.round(sum / gaps.length) + 's · max ' + mx + 's';
        } else { $('gapSummary').textContent = ''; }

        // Recent posts table
        var ph = '';
        var posts = d.recent_posts || [];
        for (var p = 0; p < posts.length; p++) {
            var po = posts[p];
            ph += '<tr>'
                + '<td>#' + po.id + '</td>'
                + '<td class="small">' + esc(po.received_at) + '</td>'
                + '<td><code>' + esc(po.tid || '—') + '</code></td>'
                + '<td class="small">' + esc(po.mode == null ? '—' : po.mode) + (po.trigger ? '/' + esc(po.trigger) : '') + '</td>'
                + '<td class="small">' + esc(po.connection || '—') + '</td>'
                + '<td class="text-end">' + esc(po.accuracy == null ? '—' : po.accuracy) + '</td>'
                + '<td class="text-end">' + esc(po.velocity == null ? '—' : po.velocity) + '</td>'
                + '<td class="text-end">' + (po.battery == null ? '—' : po.battery + '%') + '</td>'
                + '</tr>';
        }
        document.getElementById('postsTable').querySelector('tbody').innerHTML = ph || '<tr><td colspan="8" class="text-body-secondary p-3 text-center">No posts yet.</td></tr>';

        // Outbox
        var oh = '';
        for (var o = 0; o < (d.outbox || []).length; o++) {
            var ob = d.outbox[o];
            var status = ob.consumed_at
                ? '<span class="badge bg-success">consumed ' + esc(ob.consumed_at) + '</span>'
                : '<span class="badge bg-warning text-dark">pending</span>';
            oh += '<div class="border rounded p-2 mb-2 small">'
                + '<div class="d-flex"><strong>#' + ob.id + '</strong><span class="ms-auto">' + status + '</span></div>'
                + '<div class="text-body-secondary small">queued ' + esc(ob.created_at) + '</div>'
                + '<pre class="payload mb-0">' + esc(JSON.stringify(ob.payload, null, 2)) + '</pre>'
                + '</div>';
        }
        $('outboxList').innerHTML = oh || '<div class="text-body-secondary fst-italic small">Outbox empty.</div>';

        $('memberDrill').scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    $('btnRefresh').addEventListener('click', loadInstall);
    $('btnCloseDrill').addEventListener('click', function () { $('memberDrill').classList.add('d-none'); });
    loadInstall();
})();
</script>
</body>
</html>
