<?php
/**
 * NewUI v4.0 — Net Control Board (Phase 109 Slice A)
 *
 * The event's single operating picture: assigned units × [roster /
 * current zone / last check-in / global status], with fast zone entry
 * (click, keyboard digit) and automatic ICS-214 logging on every move.
 *
 * Data loaded client-side via api/net-control.php; zones edited via
 * api/event-zones.php; zone moves via api/event-zone-update.php.
 *
 * See specs/phase-109-event-scoped-statuses/spec.md (Slice A).
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/inc/i18n.php';

// Pick the session profile matching the client cookie (mobile vs
// desktop) — must fire before session_start().
require_once __DIR__ . '/inc/session-bootstrap.php';
sess_bootstrap_auto();
session_start();

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
require_once __DIR__ . '/inc/rbac.php';
rbac_require_screen('screen.net_control');
require_once __DIR__ . '/inc/force-pw-change.php';
force_pw_change_redirect();

$user     = e($_SESSION['user']);
$theme    = $_SESSION['day_night'] ?? 'Day';
$bs_theme = ($theme === 'Night') ? 'dark' : 'light';
$csrf     = csrf_token();

// Can this user define/rename/delete zones? Drives the "Edit zones" UI.
$canManageZones = rbac_can('action.manage_event_zones') ? 1 : 0;
// Can this user move units between zones?
$canUpdateZone  = rbac_can('action.update_zone') ? 1 : 0;
// Slice C — can this user check cache equipment in/out?
$canIssueEquipment = rbac_can('action.issue_equipment') ? 1 : 0;

// ── Open events (status = 2). The board defaults to the most recent
//    open incident; the client persists the operator's choice in
//    localStorage. Best-effort query so a schema quirk never 500s the
//    page. ──
$prefix = $GLOBALS['db_prefix'] ?? '';
$openEvents = [];
try {
    $openEvents = db_fetch_all(
        "SELECT `id`, `scope`, `incident_number`
         FROM `{$prefix}ticket`
         WHERE `status` = 2 AND (`deleted_at` IS NULL)
         ORDER BY `id` DESC
         LIMIT 200"
    );
} catch (Exception $e) {
    // Pre-wastebasket install (no deleted_at) — retry without it.
    try {
        $openEvents = db_fetch_all(
            "SELECT `id`, `scope` FROM `{$prefix}ticket`
             WHERE `status` = 2 ORDER BY `id` DESC LIMIT 200"
        );
    } catch (Exception $e2) {
        $openEvents = [];
    }
}
?>
<!DOCTYPE html>
<html lang="<?php echo e(i18n_lang()); ?>" data-bs-theme="<?php echo $bs_theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?php echo e($csrf); ?>">
    <title><?php echo e(t('netcontrol.title', 'Net Control')); ?> — <?php echo e(t('login.title', 'Tickets NewUI')); ?> <?php echo NEWUI_VERSION; ?></title>

    <!-- Vendor CSS -->
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-icons.min.css">

    <!-- App CSS -->
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?php echo asset_v('assets/css/dashboard.css'); ?>">
    <!-- Slice D — Leaflet for the zone map editor -->
    <link rel="stylesheet" href="assets/vendor/leaflet/leaflet.css">

    <!-- Print CSS -->
    <link rel="stylesheet" href="assets/css/print.css" media="print">

    <style>
        /* Net Control Board — compact, keyboard-first. */
        .nc-zone-chip {
            display: inline-flex; align-items: center; gap: .25rem;
            padding: .15rem .5rem; border-radius: .35rem;
            font-size: .78rem; font-weight: 600; line-height: 1.2;
            border: 1px solid rgba(128,128,128,.35);
        }
        .nc-zone-badge {
            display: inline-block; padding: .1rem .45rem; border-radius: .3rem;
            font-size: .8rem; font-weight: 600; cursor: pointer;
            border: 1px solid rgba(128,128,128,.4);
        }
        .nc-zone-badge.nc-zone-empty { color: var(--bs-secondary); font-style: italic; }
        .nc-board-row.nc-selected { outline: 2px solid var(--bs-primary); outline-offset: -2px; }
        .nc-board-row td { vertical-align: middle; }
        .nc-roster-lead { font-weight: 700; }
        .nc-roster-lead .bi-star-fill { color: #f0ad4e; }
        .nc-checkin-warn { color: var(--bs-warning) !important; font-weight: 600; }
        .nc-checkin-danger { color: var(--bs-danger) !important; font-weight: 700; }
        .nc-zone-picker-btn { min-width: 3.2rem; }
        .nc-idx-key {
            display: inline-block; min-width: 1.1rem; text-align: center;
            font-size: .65rem; opacity: .65; border: 1px solid currentColor;
            border-radius: .2rem; margin-right: .2rem; padding: 0 .2rem;
        }
        #ncBoardTable td, #ncBoardTable th { font-size: .85rem; }
    </style>
</head>
<body>

<?php include_once NEWUI_ROOT . '/inc/navbar.php'; ?>

<!-- Page Content -->
<div class="container-fluid p-3">

    <!-- Header: title + event selector + refresh -->
    <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
        <h5 class="mb-0">
            <i class="bi bi-broadcast-pin text-primary me-2"></i><?php echo e(t('netcontrol.title', 'Net Control')); ?>
            <span class="badge bg-secondary ms-2" id="ncUnitCount" style="font-size:.65rem;">0</span>
        </h5>
        <div class="d-flex gap-2 align-items-center flex-wrap">
            <label for="ncEventSelect" class="small text-body-secondary mb-0"><?php echo e(t('netcontrol.event', 'Event')); ?>:</label>
            <select id="ncEventSelect" class="form-select form-select-sm" style="min-width:16rem;">
                <?php if (empty($openEvents)): ?>
                    <option value="0"><?php echo e(t('netcontrol.no_open', 'No open events')); ?></option>
                <?php else: foreach ($openEvents as $ev):
                    $label = trim((string) ($ev['scope'] ?? ''));
                    if ($label === '') $label = 'Incident';
                    $num = isset($ev['incident_number']) && $ev['incident_number'] !== null
                            ? (string) $ev['incident_number'] : ('#' . (int) $ev['id']);
                ?>
                    <option value="<?php echo (int) $ev['id']; ?>"><?php echo e($num . ' — ' . $label); ?></option>
                <?php endforeach; endif; ?>
            </select>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="ncRefreshBtn" title="Refresh">
                <i class="bi bi-arrow-clockwise"></i>
            </button>
            <?php // Phase 111 — explicit active-event toggle (deliberate,
                  // not a dropdown side-effect, because it flips a
                  // server-wide setting that routes inbound radio
                  // messages into this event's ICS-214 log). Only shown
                  // to users who can manage it. OFF by default.
                  if (is_admin() || (function_exists('rbac_can') && rbac_can('action.manage_active_event'))): ?>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="ncAutoLogBtn"
                    title="When ON, inbound Meshtastic/Zello/DMR messages auto-log to this event's ICS-214 (requires an 'add note' routing rule).">
                <i class="bi bi-broadcast me-1"></i><span id="ncAutoLogLabel">Auto-log: <span class="text-body-secondary">off</span></span>
            </button>
            <?php endif; ?>
            <a href="index.php" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i><?php echo e(t('profile.back_to_dashboard', 'Dashboard')); ?>
            </a>
        </div>
    </div>

    <?php // Phase 111 active-event wiring — self-contained, does not
          // touch net-control.js. Reflects/sets the SERVER active event
          // (api/active-event.php) for message auto-logging, scoped to
          // whatever event is selected in #ncEventSelect. ?>
    <script>
    (function () {
        'use strict';
        var btn = document.getElementById('ncAutoLogBtn');
        if (!btn) return; // user lacks manage permission
        var sel = document.getElementById('ncEventSelect');
        var lbl = document.getElementById('ncAutoLogLabel');
        var activeId = 0;
        function csrf() {
            var m = document.querySelector('meta[name="csrf-token"]');
            return m ? m.content : '';
        }
        function currentEvent() { return sel ? parseInt(sel.value, 10) || 0 : 0; }
        function render() {
            var on = activeId && activeId === currentEvent();
            lbl.innerHTML = 'Auto-log: <span class="' + (on ? 'text-success fw-semibold' : 'text-body-secondary') + '">' + (on ? 'ON' : 'off') + '</span>';
            btn.classList.toggle('btn-outline-success', !!on);
            btn.classList.toggle('btn-outline-secondary', !on);
        }
        function refresh() {
            fetch('api/active-event.php', { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (d) { activeId = parseInt(d.active_event_ticket_id, 10) || 0; render(); })
                .catch(function () {});
        }
        btn.addEventListener('click', function () {
            var ev = currentEvent();
            var turnOn = !(activeId && activeId === ev);
            if (turnOn && !ev) { alert('Pick an event first.'); return; }
            fetch('api/active-event.php', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ ticket_id: turnOn ? ev : 0, csrf_token: csrf() })
            }).then(function (r) { return r.json(); })
              .then(function (d) {
                  if (d.error) { alert(d.error); return; }
                  activeId = parseInt(d.active_event_ticket_id, 10) || 0;
                  render();
              }).catch(function () { alert('Could not update active event.'); });
        });
        if (sel) sel.addEventListener('change', render);
        refresh();
    })();
    </script>

    <!-- Zones toolbar -->
    <div class="card mb-3">
        <div class="card-body py-2 d-flex align-items-center flex-wrap gap-2">
            <span class="text-body-secondary small me-1"><?php echo e(t('netcontrol.zones', 'Zones')); ?>:</span>
            <span id="ncZoneChips" class="d-flex flex-wrap gap-1"></span>
            <?php if ($canManageZones): ?>
            <button type="button" class="btn btn-sm btn-outline-primary ms-auto" id="ncEditZonesBtn">
                <i class="bi bi-pencil-square me-1"></i><?php echo e(t('netcontrol.edit_zones', 'Edit zones')); ?>
            </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Alert area -->
    <div id="ncAlert"></div>

    <!-- Board -->
    <div class="card">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 align-middle" id="ncBoardTable">
                <thead>
                    <tr>
                        <th style="width:14%;"><?php echo e(t('netcontrol.col.team', 'Team')); ?></th>
                        <th style="width:34%;"><?php echo e(t('netcontrol.col.roster', 'Roster')); ?></th>
                        <th style="width:18%;"><?php echo e(t('netcontrol.col.zone', 'Zone')); ?></th>
                        <th style="width:16%;"><?php echo e(t('netcontrol.col.checkin', 'Last check-in')); ?></th>
                        <th style="width:18%;"><?php echo e(t('netcontrol.col.global', 'Status')); ?></th>
                    </tr>
                </thead>
                <tbody id="ncBoardBody">
                    <tr><td colspan="5" class="text-center text-body-secondary py-4">
                        <div class="spinner-border spinner-border-sm text-primary" role="status"></div>
                    </td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Signed-out tray (Phase 109 Slice B) — units explicitly signed out of
         the event drop here; click "Sign in" to return one to the board. -->
    <div id="ncSignedOutTray" class="mt-3" style="display:none;">
        <div class="card border-secondary-subtle">
            <div class="card-header py-1 px-2 small fw-semibold text-body-secondary bg-body-tertiary">
                <i class="bi bi-box-arrow-right me-1"></i><?php echo e(t('netcontrol.signed_out', 'Signed out')); ?>
                <span class="badge bg-secondary ms-1" id="ncSignedOutCount">0</span>
            </div>
            <div class="card-body p-2 d-flex flex-wrap gap-2" id="ncSignedOutList"></div>
        </div>
    </div>

    <p class="small text-body-secondary mt-2 mb-0">
        <i class="bi bi-keyboard me-1"></i>
        <?php echo e(t('netcontrol.hint', 'Click a unit\'s row to select it, then press a zone number (1–9) to move it. Or click the Zone cell for a picker.')); ?>
    </p>
</div>

<!-- Zone picker popover (shared, positioned on demand) -->
<div id="ncZonePicker" class="card shadow" style="display:none;position:absolute;z-index:1080;min-width:12rem;">
    <div class="card-body p-2">
        <div class="small fw-bold mb-1" id="ncZonePickerTitle"><?php echo e(t('netcontrol.set_zone', 'Set zone')); ?></div>
        <div id="ncZonePickerButtons" class="d-flex flex-wrap gap-1"></div>
    </div>
</div>

<?php if ($canManageZones): ?>
<!-- Edit-zones modal -->
<div class="modal fade" id="ncZonesModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title"><?php echo e(t('netcontrol.manage_zones', 'Manage Event Zones')); ?></h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="ncZonesModalList" class="mb-3"></div>
                <hr>
                <!-- Slice D — zone templates (reuse a zone set year to year) -->
                <div class="mb-2">
                    <div class="fw-semibold small mb-1">Templates <span class="text-body-secondary fw-normal">— reuse a zone set year to year</span></div>
                    <div class="input-group input-group-sm mb-1">
                        <select class="form-select" id="ncTemplateSelect"><option value="">— pick a template —</option></select>
                        <button type="button" class="btn btn-outline-primary" id="ncApplyTemplateBtn">Apply</button>
                    </div>
                    <div class="input-group input-group-sm">
                        <input type="text" class="form-control" id="ncTemplateName" maxlength="64" placeholder="Save current zones as… (name)">
                        <button type="button" class="btn btn-outline-secondary" id="ncSaveTemplateBtn">Save</button>
                    </div>
                </div>
                <hr>
                <div class="row g-2 align-items-end">
                    <div class="col-5">
                        <label class="form-label small mb-0" for="ncNewZoneName">Name</label>
                        <input type="text" class="form-control form-control-sm" id="ncNewZoneName" maxlength="64" placeholder="Zone 3">
                    </div>
                    <div class="col-3">
                        <label class="form-label small mb-0" for="ncNewZoneCode">Code</label>
                        <input type="text" class="form-control form-control-sm" id="ncNewZoneCode" maxlength="16" placeholder="3">
                    </div>
                    <div class="col-2">
                        <label class="form-label small mb-0" for="ncNewZoneColor">Color</label>
                        <input type="color" class="form-control form-control-sm form-control-color" id="ncNewZoneColor" value="#0d6efd">
                    </div>
                    <div class="col-2">
                        <button type="button" class="btn btn-sm btn-primary w-100" id="ncAddZoneBtn">
                            <i class="bi bi-plus-lg"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($canManageZones): ?>
<!-- Zone map editor (Phase 109 Slice D) — draw a zone's point/polygon -->
<div class="modal fade" id="ncZoneMapModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content">
        <div class="modal-header py-2">
            <h6 class="modal-title">Map area for <span id="ncZoneMapName" class="fw-semibold"></span></h6>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="ncZoneMapZoneId">
            <div id="ncZoneMap" style="width:100%;height:420px;border-radius:6px;"></div>
            <div class="form-text mt-1">
                Click the map to add polygon corners (1 click = a point marker, 3+ = a shaded zone).
                <span class="fw-semibold">Clear</span> removes the shape; <span class="fw-semibold">Save</span> stores it —
                it then renders on the Situation big screen.
            </div>
        </div>
        <div class="modal-footer py-2">
            <button type="button" class="btn btn-sm btn-outline-danger me-auto" id="ncZoneMapClear"><i class="bi bi-eraser me-1"></i>Clear</button>
            <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="button" class="btn btn-sm btn-primary" id="ncZoneMapSave"><i class="bi bi-check-lg me-1"></i>Save shape</button>
        </div>
    </div></div>
</div>
<?php endif; ?>

<?php if ($canIssueEquipment): ?>
<!-- Issue-equipment modal (Phase 109 Slice C) -->
<div class="modal fade" id="ncIssueModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
        <div class="modal-header py-2">
            <h6 class="modal-title">Issue equipment to <span id="ncIssueMemberName" class="fw-semibold"></span></h6>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="ncIssueMemberId">
            <label class="form-label form-label-sm">Cache item</label>
            <select class="form-select form-select-sm" id="ncIssueItem"><option value="">Loading…</option></select>
            <div class="form-text">Only items flagged "available for events" and not already issued are listed.</div>
        </div>
        <div class="modal-footer py-2">
            <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="button" class="btn btn-sm btn-primary" id="ncIssueConfirm">Issue</button>
        </div>
    </div></div>
</div>
<?php endif; ?>

<!-- Vendor JS -->
<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<!-- Slice D — Leaflet + tile-provider prefs for the zone map editor -->
<script src="assets/vendor/leaflet/leaflet.js"></script>
<script src="assets/js/leaflet-quadkey.js"></script>
<script src="assets/js/map-prefs.js"></script>

<!-- App JS -->
<script src="assets/js/theme-manager.js"></script>
<script>
    window.NC_CONFIG = {
        csrf: <?php echo json_encode($csrf); ?>,
        canManageZones: <?php echo $canManageZones ? 'true' : 'false'; ?>,
        canUpdateZone: <?php echo $canUpdateZone ? 'true' : 'false'; ?>,
        canIssueEquipment: <?php echo $canIssueEquipment ? 'true' : 'false'; ?>
    };
</script>
<script src="assets/js/net-control.js?v=<?php echo asset_v('assets/js/net-control.js'); ?>"></script>

</body>
</html>
