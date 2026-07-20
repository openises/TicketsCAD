<?php
/**
 * NewUI v4.0 - Dashboard
 *
 * Main entry point. Requires authentication — redirects to login.php if not logged in.
 * Loads the GridStack widget dashboard with Bootstrap 5 chrome.
 */

require_once __DIR__ . '/config.php';

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


// If user's role requires 2FA but they haven't enrolled, force enrollment
if (!empty($_SESSION['tfa_enrollment_required'])) {
    header('Location: profile.php?setup_2fa=1');
    exit;
}

require_once __DIR__ . '/inc/rbac.php';
require_once __DIR__ . '/inc/i18n.php';
require_once __DIR__ . '/inc/incident-number.php';

// Phase 99o (Eric beta 2026-06-29) — admin-configurable label for
// rendered case numbers. Inject as a JS global so widgets render
// consistent labels everywhere. Default "Incident".
$incNumLabel = incnum_get_label();

$user     = e($_SESSION['user']);
$level = current_role_name();
$theme    = $_SESSION['day_night'] ?? 'Day';
$bs_theme = ($theme === 'Night') ? 'dark' : 'light';
$csrf     = csrf_token();
$userPerms = rbac_user_permissions();
?>
<!DOCTYPE html>
<html lang="<?php echo e(i18n_lang()); ?>" data-bs-theme="<?php echo $bs_theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?php echo e($csrf); ?>">
    <title><?php echo e(t('login.title', 'Tickets NewUI')); ?> <?php echo NEWUI_VERSION; ?></title>

    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#1570ef">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <link rel="apple-touch-icon" href="assets/logo-light.png">

    <!-- Vendor CSS -->
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/vendor/gridstack/gridstack.min.css">
    <link rel="stylesheet" href="assets/vendor/gridstack/gridstack-extra.min.css">
    <link rel="stylesheet" href="assets/vendor/leaflet/leaflet.css">
    <link rel="stylesheet" href="assets/vendor/leaflet/plugins/leaflet-openweathermap.css">

    <!-- App CSS -->
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?php echo asset_v('assets/css/dashboard.css'); ?>">
    <link rel="stylesheet" href="assets/css/unit-tracking.css?v=<?php echo asset_v('assets/css/unit-tracking.css'); ?>">
    <link rel="stylesheet" href="assets/css/widgets.css?v=<?php echo asset_v('assets/css/widgets.css'); ?>">
    <link rel="stylesheet" href="assets/css/zello-widget.css?v=<?php echo asset_v('assets/css/zello-widget.css'); ?>">
    <!-- radio-widget.css moved to inc/navbar.php so it's loaded on every page -->
    <link rel="stylesheet" href="assets/css/chat.css?v=<?php echo asset_v('assets/css/chat.css'); ?>">
    <link rel="stylesheet" href="assets/css/mobile.css">
    <link rel="stylesheet" href="assets/css/print.css" media="print">

    <!-- Print CSS -->
    <link rel="stylesheet" href="assets/css/print.css" media="print">
</head>
<body>

<!-- Skip to content link for keyboard/screen reader users -->
<a href="#dashboard" class="skip-link visually-hidden-focusable position-absolute top-0 start-0 bg-primary text-white px-3 py-2" style="z-index:9999;">Skip to dashboard</a>

<?php include_once NEWUI_ROOT . '/inc/navbar.php'; ?>

    <!-- Row 2: Widget Toggles -->
    <nav class="navbar border-bottom nav-widgets" data-bs-theme="<?php echo $bs_theme; ?>" role="toolbar" aria-label="Widget toggles">
        <div class="container-fluid">
            <div class="d-flex align-items-center gap-2" id="widgetToggles">
                <span class="text-body-secondary small me-1"><?php echo e(t('dash.toolbar.widgets', 'Widgets')); ?>:</span>
                <button class="btn btn-sm btn-outline-warning widget-toggle-reset" id="resetLayout" title="<?php echo e(t('dash.toolbar.reset', 'Reset layout to defaults')); ?>" aria-label="<?php echo e(t('dash.toolbar.reset', 'Reset layout to defaults')); ?>">
                    <i class="bi bi-arrow-counterclockwise"></i>
                </button>
                <button class="btn btn-sm btn-outline-secondary" id="undoLayout" title="<?php echo e(t('dash.toolbar.undo', 'Undo last layout change')); ?>" aria-label="<?php echo e(t('dash.toolbar.undo', 'Undo last layout change')); ?>" disabled>
                    <i class="bi bi-arrow-left"></i>
                </button>
                <!-- Snapshot dropdown -->
                <div class="dropdown">
                    <button class="btn btn-sm btn-outline-info dropdown-toggle" type="button" id="snapshotMenu" data-bs-toggle="dropdown" aria-expanded="false" title="<?php echo e(t('dash.toolbar.snapshots', 'Layout snapshots')); ?>" aria-label="<?php echo e(t('dash.toolbar.snapshots', 'Layout snapshots')); ?>">
                        <i class="bi bi-bookmark"></i>
                    </button>
                    <div class="dropdown-menu snapshot-dropdown" aria-labelledby="snapshotMenu" style="min-width: 280px;">
                        <div class="px-3 py-2">
                            <div class="input-group input-group-sm">
                                <label for="snapshotName" class="visually-hidden"><?php echo e(t('dash.toolbar.snapshot_name', 'Snapshot name...')); ?></label>
                                <input type="text" class="form-control" id="snapshotName" placeholder="<?php echo e(t('dash.toolbar.snapshot_name', 'Snapshot name...')); ?>" maxlength="50">
                                <button class="btn btn-outline-success" id="saveSnapshot" title="<?php echo e(t('dash.toolbar.snapshot_save', 'Save current layout')); ?>" aria-label="<?php echo e(t('dash.toolbar.snapshot_save', 'Save current layout')); ?>">
                                    <i class="bi bi-plus-lg"></i> <?php echo e(t('btn.save', 'Save')); ?>
                                </button>
                            </div>
                        </div>
                        <div class="dropdown-divider"></div>
                        <div id="snapshotList" class="snapshot-list">
                            <span class="dropdown-item-text text-body-secondary small">No saved snapshots</span>
                        </div>
                    </div>
                </div>
                <span class="vr mx-1 opacity-25"></span>
<?php if (dash_can('statistics', $userPerms)): ?>
                <button class="btn btn-sm btn-outline-secondary widget-toggle active" data-widget="statistics" title="<?php echo e(t('dash.widget.statistics', 'Statistics')); ?>">
                    <i class="bi bi-graph-up"></i>
                </button>
<?php endif; ?>
<?php if (dash_can('incidents', $userPerms)): ?>
                <button class="btn btn-sm btn-outline-secondary widget-toggle active" data-widget="incidents" title="<?php echo e(t('dash.widget.incidents', 'Incidents')); ?>">
                    <i class="bi bi-exclamation-triangle"></i>
                </button>
<?php endif; ?>
<?php if (dash_can('responders', $userPerms)): ?>
                <button class="btn btn-sm btn-outline-secondary widget-toggle active" data-widget="responders" title="<?php echo e(t('dash.widget.responders', 'Responders')); ?>">
                    <i class="bi bi-people"></i>
                </button>
<?php endif; ?>
<?php if (dash_can('facilities', $userPerms)): ?>
                <button class="btn btn-sm btn-outline-secondary widget-toggle active" data-widget="facilities" title="<?php echo e(t('dash.widget.facilities', 'Facilities')); ?>">
                    <i class="bi bi-building"></i>
                </button>
<?php endif; ?>
<?php if (dash_can('controls', $userPerms)): ?>
                <button class="btn btn-sm btn-outline-secondary widget-toggle active" data-widget="controls" title="<?php echo e(t('dash.widget.controls', 'Controls')); ?>">
                    <i class="bi bi-sliders"></i>
                </button>
<?php endif; ?>
<?php if (dash_can('comms', $userPerms)): ?>
                <button class="btn btn-sm btn-outline-secondary widget-toggle active" data-widget="comms" title="<?php echo e(t('dash.widget.comms', 'Communications')); ?>">
                    <i class="bi bi-headset"></i>
                </button>
<?php endif; ?>
<?php if (dash_can('map', $userPerms)): ?>
                <button class="btn btn-sm btn-outline-secondary widget-toggle active" data-widget="map" title="<?php echo e(t('dash.widget.map', 'Map')); ?>">
                    <i class="bi bi-geo-alt"></i>
                </button>
<?php endif; ?>
<?php if (dash_can('log', $userPerms)): ?>
                <button class="btn btn-sm btn-outline-secondary widget-toggle active" data-widget="log" title="<?php echo e(t('dash.widget.log', 'Recent Events')); ?>">
                    <i class="bi bi-journal-text"></i>
                </button>
<?php endif; ?>
<?php if (dash_can('audit_log', $userPerms)): ?>
                <button class="btn btn-sm btn-outline-secondary widget-toggle active" data-widget="audit_log" title="<?php echo e(t('dash.widget.audit_log', 'Recent activity (audit)')); ?>">
                    <i class="bi bi-shield-check"></i>
                </button>
<?php endif; ?>
<?php if (dash_can('time_entries', $userPerms)): ?>
                <button class="btn btn-sm btn-outline-secondary widget-toggle active" data-widget="time_entries" title="<?php echo e(t('dash.widget.time_entries', 'My time (volunteer hours)')); ?>">
                    <i class="bi bi-clock-history"></i>
                </button>
<?php endif; ?>
            </div>
        </div>
    </nav>
</header>

<!-- Dashboard Grid -->
<div class="container-fluid p-2" role="main">
    <div class="grid-stack" id="dashboard" aria-label="Dashboard widgets"></div>
</div>

<!-- Widget Templates (hidden, cloned by JS) -->
<template id="tpl-statistics">
    <div class="widget-body widget-statistics" role="region" aria-label="Statistics">
        <div class="row g-1 text-center" id="statsGrid">
            <div class="col-3 col-xl"><div class="stat-card"><div class="stat-value" data-stat="open_tickets">-</div><div class="stat-label"><?php echo e(t('dash.stat.open_tickets', 'Open')); ?></div></div></div>
            <div class="col-3 col-xl"><div class="stat-card text-danger"><div class="stat-value" data-stat="unassigned">-</div><div class="stat-label"><?php echo e(t('dash.stat.unassigned', 'Unassigned')); ?></div></div></div>
            <div class="col-3 col-xl"><div class="stat-card"><div class="stat-value" data-stat="on_scene">-</div><div class="stat-label"><?php echo e(t('dash.stat.on_scene', 'On Scene')); ?></div></div></div>
            <div class="col-3 col-xl"><div class="stat-card text-success"><div class="stat-value" data-stat="available_responders">-</div><div class="stat-label"><?php echo e(t('dash.stat.available_responders', 'Available')); ?></div></div></div>
            <div class="col-3 col-xl"><div class="stat-card"><div class="stat-value" data-stat="dispatched_not_responding">-</div><div class="stat-label"><?php echo e(t('dash.stat.dispatched', 'Dispatched')); ?></div></div></div>
            <div class="col-3 col-xl"><div class="stat-card"><div class="stat-value" data-stat="responding_not_on_scene">-</div><div class="stat-label"><?php echo e(t('dash.stat.responding', 'Responding')); ?></div></div></div>
            <div class="col-3 col-xl"><div class="stat-card"><div class="stat-value" data-stat="closed_today">-</div><div class="stat-label"><?php echo e(t('dash.stat.closed_today', 'Closed Today')); ?></div></div></div>
            <div class="col-3 col-xl"><div class="stat-card"><div class="stat-value" data-stat="avg_to_dispatch">-</div><div class="stat-label"><?php echo e(t('dash.stat.avg_dispatch', 'Avg Dispatch')); ?></div></div></div>
        </div>
    </div>
</template>

<template id="tpl-incidents">
    <div class="widget-body widget-incidents" role="region" aria-label="Incidents">
        <div class="table-responsive">
            <!-- GH #63 (a beta tester) — data-col-id attributes feed the
                 ScreenPrefs column picker (screen 'widget-incidents';
                 opener button injected by widget-manager.js). Keep the
                 ids in sync with inc/screen-prefs.php's catalog. -->
            <table class="table table-sm table-hover mb-0" id="incidentsWidgetTable">
                <thead class="sticky-top">
                    <tr>
                        <!-- Phase 99o-v2 (Eric beta 2026-06-29):
                             dropped the internal ID column. The case
                             number is now the operationally-meaningful
                             identifier dispatchers care about. The
                             link to the detail page rides on the case
                             number cell (data-id is still emitted on
                             the row so click handlers continue to
                             work). -->
                        <th class="sortable" data-sort="incident_number" data-col-id="case" data-col-label="<?php echo e($incNumLabel); ?> #"><?php echo e($incNumLabel); ?> #</th>
                        <th class="sortable" data-sort="scope" data-col-id="scope" data-col-label="Scope">Scope</th>
                        <th class="sortable" data-sort="incident_type" data-col-id="type" data-col-label="Type">Type</th>
                        <th class="sortable" data-sort="location" data-col-id="location" data-col-label="Location">Location</th>
                        <th class="sortable" data-sort="severity" data-type="number" data-col-id="sev" data-col-label="Severity">Sev</th>
                        <th class="sortable" data-sort="units_assigned" data-type="number" data-col-id="units" data-col-label="Units">Units</th>
                        <th class="sortable" data-sort="patients_count" data-type="number" data-col-id="pts" data-col-label="Patients">Pts</th>
                        <th class="sortable" data-sort="actions_count" data-type="number" data-col-id="act" data-col-label="Actions">Act</th>
                        <th class="sortable" data-sort="updated" data-type="date" data-col-id="updated" data-col-label="Updated">Updated</th>
                    </tr>
                </thead>
                <tbody id="incidentsBody"></tbody>
            </table>
        </div>
    </div>
</template>

<template id="tpl-responders">
    <div class="widget-body widget-responders" role="region" aria-label="Responders">
        <div class="table-responsive">
            <!-- GH #63 (a beta tester) — data-col-id attributes feed the
                 ScreenPrefs column picker (screen 'widget-responders';
                 opener button injected by widget-manager.js). Keep the
                 ids in sync with inc/screen-prefs.php's catalog. -->
            <table class="table table-sm table-hover mb-0" id="respondersWidgetTable">
                <thead class="sticky-top">
                    <tr>
                        <th class="sortable" data-sort="name" data-col-id="name" data-col-label="Name">Name</th>
                        <th class="sortable" data-sort="handle" data-col-id="handle" data-col-label="Handle">Handle</th>
                        <th class="sortable" data-sort="type_name" data-col-id="type" data-col-label="Type">Type</th>
                        <th class="sortable" data-sort="status_name" data-col-id="status" data-col-label="Status">Status</th>
                        <th class="sortable" data-sort="active_assignments" data-type="number" data-col-id="assigned" data-col-label="Assigned">Assigned</th>
                    </tr>
                </thead>
                <tbody id="respondersBody"></tbody>
            </table>
        </div>
    </div>
</template>

<template id="tpl-facilities">
    <div class="widget-body widget-facilities" role="region" aria-label="Facilities">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0" id="facilitiesWidgetTable">
                <thead class="sticky-top">
                    <tr>
                        <th class="sortable" data-sort="name"        data-col-id="name"   data-col-label="Name">Name</th>
                        <th class="sortable" data-sort="type_name"   data-col-id="type"   data-col-label="Type">Type</th>
                        <th class="sortable" data-sort="status_name" data-col-id="status" data-col-label="Status">Status</th>
                        <th class="sortable" data-sort="beds_a" data-type="number" data-col-id="beds" data-col-label="Beds (Avail / Occ)">Beds</th>
                        <th class="sortable" data-sort="hours_today" data-col-id="hours"  data-col-label="Hours">Hours</th>
                    </tr>
                </thead>
                <tbody id="facilitiesBody"></tbody>
            </table>
        </div>
    </div>
</template>

<template id="tpl-map">
    <div class="widget-body widget-map" role="region" aria-label="Map">
        <div id="mapContainer" style="width:100%; height:100%;"></div>
    </div>
</template>

<template id="tpl-log">
    <div class="widget-body widget-log" role="region" aria-label="Recent Events">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead class="sticky-top">
                    <tr>
                        <th class="sortable" data-sort="when" data-type="date">Time</th>
                        <th class="sortable" data-sort="code_type">Event</th>
                        <th class="sortable" data-sort="by">By</th>
                        <th class="sortable" data-sort="info">Info</th>
                    </tr>
                </thead>
                <tbody id="logBody"></tbody>
            </table>
        </div>
    </div>
</template>

<template id="tpl-audit_log">
    <div class="widget-body widget-audit-log" role="region" aria-label="Recent activity">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead class="sticky-top">
                    <tr>
                        <th>Time</th>
                        <th>Actor</th>
                        <th>Event</th>
                        <th>Target</th>
                    </tr>
                </thead>
                <tbody id="auditLogBody">
                    <tr><td colspan="4" class="text-center text-body-secondary small py-3">Loading...</td></tr>
                </tbody>
            </table>
        </div>
        <div class="px-2 py-1 border-top small">
            <a href="settings.php#audit-log" class="text-decoration-none">
                <i class="bi bi-box-arrow-up-right me-1"></i>View all audit events
            </a>
        </div>
    </div>
</template>

<!-- Phase 80d - "My time" volunteer-hours widget -->
<template id="tpl-time_entries">
    <div class="widget-body widget-time-entries" id="timeEntriesWidget" role="region" aria-label="My time">
        <div class="text-center p-3 text-body-secondary small">Loading...</div>
    </div>
</template>

<template id="tpl-controls">
    <div class="widget-body widget-controls" role="region" aria-label="Controls">
        <div class="d-flex flex-wrap gap-1 p-1 justify-content-center">
            <button class="ctrl-btn btn btn-sm btn-outline-secondary" data-action="show-assigned" title="Filter incidents to my units' calls (toggle)">
                <i class="bi bi-check-circle text-success"></i>
                <span>Assigned</span>
            </button>
            <button class="ctrl-btn btn btn-sm btn-outline-secondary" data-action="road-conditions" title="Toggle road-conditions overlay on map. Right-click to manage road conditions.">
                <i class="bi bi-cone-striped text-warning"></i>
                <span>Roads</span>
            </button>
            <?php if (function_exists('rbac_can') && rbac_can('screen.roster')): ?>
            <button class="ctrl-btn btn btn-sm btn-outline-secondary" data-action="open-units" title="Open Units page">
                <i class="bi bi-truck text-primary"></i>
                <span>Units</span>
            </button>
            <?php endif; ?>
            <?php if (function_exists('rbac_can') && rbac_can('screen.facilities')): ?>
            <button class="ctrl-btn btn btn-sm btn-outline-secondary" data-action="open-facilities" title="Open Facilities page">
                <i class="bi bi-hospital text-info"></i>
                <span>Facilities</span>
            </button>
            <?php endif; ?>
            <button class="ctrl-btn btn btn-sm btn-outline-secondary" data-action="mail" title="Open internal messaging">
                <i class="bi bi-mailbox text-primary"></i>
                <span>Mail</span>
            </button>
            <button class="ctrl-btn btn btn-sm btn-outline-secondary" data-action="print" title="Print the dashboard">
                <i class="bi bi-printer"></i>
                <span>Print</span>
            </button>
            <?php if (function_exists('rbac_can') && rbac_can('screen.settings')): ?>
            <button class="ctrl-btn btn btn-sm btn-outline-secondary" data-action="settings" title="Open settings">
                <i class="bi bi-gear"></i>
                <span>Settings</span>
            </button>
            <?php endif; ?>
        </div>
    </div>
</template>

<template id="tpl-comms">
    <div class="widget-body widget-comms" role="region" aria-label="Communications">
        <div class="d-flex flex-wrap gap-1 p-1 justify-content-center">
            <button class="ctrl-btn btn btn-sm btn-outline-secondary" data-action="chat" title="Chat">
                <i class="bi bi-chat-dots text-primary"></i>
                <span>Chat</span>
            </button>
            <button class="ctrl-btn btn btn-sm btn-outline-secondary" data-action="sms" title="SMS">
                <i class="bi bi-phone text-success"></i>
                <span>SMS</span>
            </button>
            <button class="ctrl-btn btn btn-sm btn-outline-secondary" data-action="radio" title="Radio">
                <i class="bi bi-broadcast text-danger"></i>
                <span>Radio</span>
            </button>
            <button class="ctrl-btn btn btn-sm btn-outline-secondary" data-action="zello" title="Zello">
                <i class="bi bi-megaphone text-warning"></i>
                <span>Zello</span>
            </button>
            <button class="ctrl-btn btn btn-sm btn-outline-secondary" data-action="alerts" title="Alerts">
                <i class="bi bi-bell text-danger"></i>
                <span>Alerts</span>
            </button>
        </div>
    </div>
</template>

<!-- Zello Widget Template -->
<template id="tpl-zello-widget">
    <div class="zello-widget zello-hidden">
        <div class="zello-header">
            <span class="zello-status-badge status-disconnected"></span>
            <i class="bi bi-megaphone zello-header-icon"></i>
            <span class="zello-header-title">Zello</span>
            <span class="zello-header-channel"></span>
            <div class="zello-header-actions">
                <!-- Phase 101 (Eric beta 2026-07-01) — header toolbar in
                     the Responders-widget style. Archive + Mute live
                     with Minimize + Close. -->
                <a href="zello-archive.php" target="_blank" rel="noopener"
                   class="btn btn-sm btn-outline-secondary" id="zelloArchive"
                   title="Open archive in a new tab" aria-label="Open Zello archive">
                    <i class="bi bi-clock-history"></i>
                </a>
                <!-- GH #55 (Eric 2026-07-04) — Live monitor: when ON, channel
                     audio keeps playing even while the widget is minimized /
                     you navigate to another page. OFF (default) = audio only
                     when the widget is open (prior behavior). Mute (below)
                     silences everything and overrides this. -->
                <button class="btn btn-sm btn-outline-secondary" id="zelloLiveBtn"
                        title="Live monitor off — audio plays only while the widget is open"
                        aria-label="Live monitor off" aria-pressed="false">
                    <i class="bi bi-broadcast"></i>
                </button>
                <button class="btn btn-sm btn-outline-secondary" id="zelloMuteBtn"
                        title="Mute incoming audio" aria-label="Mute incoming audio"
                        aria-pressed="false">
                    <i class="bi bi-volume-up-fill"></i>
                </button>
                <button class="btn btn-sm btn-outline-secondary" id="zelloMinimize" title="Minimize" aria-label="Minimize Zello">
                    <i class="bi bi-dash"></i>
                </button>
                <button class="btn btn-sm btn-outline-secondary" id="zelloClose" title="Close" aria-label="Close Zello">
                    <i class="bi bi-x"></i>
                </button>
            </div>
        </div>
        <div class="zello-feed" id="zelloFeed">
            <div class="zello-feed-empty">
                <span><i class="bi bi-megaphone d-block mb-2" style="font-size:1.5rem"></i>No messages yet.<br>Connect to start receiving.</span>
            </div>
        </div>
        <div class="zello-input-row">
            <input type="text" class="form-control form-control-sm" id="zelloTextInput"
                   placeholder="Type a message..." autocomplete="off">
            <button class="btn btn-sm btn-primary" id="zelloSendBtn" title="Send" aria-label="Send message">
                <i class="bi bi-send"></i>
            </button>
        </div>
        <div class="zello-ptt-bar">
            <button class="zello-ptt-btn" id="zelloPttBtn">
                <i class="bi bi-mic-fill me-1"></i> Push to Talk
            </button>
            <div class="zello-ptt-hint">Hold Space or click to talk</div>
        </div>
        <div class="zello-resize-handle"></div>
    </div>
</template>

<!-- Radio Widget template moved to inc/navbar.php so it loads on every page. -->


<!-- Chat Widget Template -->
<template id="tpl-chat-widget">
    <div class="chat-widget chat-hidden">
        <div class="chat-header">
            <i class="bi bi-chat-dots chat-header-icon"></i>
            <span class="chat-header-title">Chat</span>
            <div class="chat-header-actions">
                <button class="btn btn-sm btn-outline-secondary" id="chatMinimize" title="Minimize" aria-label="Minimize Chat">
                    <i class="bi bi-dash"></i>
                </button>
                <button class="btn btn-sm btn-outline-secondary" id="chatClose" title="Close" aria-label="Close Chat">
                    <i class="bi bi-x"></i>
                </button>
            </div>
        </div>
        <div class="chat-channel-bar">
            <label for="chatChannelSelect" class="visually-hidden">Channel</label>
            <select class="form-select form-select-sm" id="chatChannelSelect">
                <option value="general">General</option>
                <option value="dispatch">Dispatch</option>
                <option value="admin">Admin</option>
            </select>
            <div class="dropdown">
                <button class="btn btn-sm btn-outline-secondary chat-signal-btn" id="chatSignalBtn" title="Send Signal/Code" aria-label="Send signal code">
                    <i class="bi bi-broadcast-pin"></i> Codes
                </button>
                <div class="dropdown-menu chat-signal-menu" id="chatSignalMenu">
                    <span class="dropdown-item-text text-body-secondary small">Loading...</span>
                </div>
            </div>
        </div>
        <div class="chat-feed">
            <div class="chat-feed-empty">
                <span><i class="bi bi-chat-dots d-block mb-2" style="font-size:1.5rem"></i>No messages yet.<br>Start the conversation.</span>
            </div>
        </div>
        <div class="chat-input-row">
            <label for="chatTextInput" class="visually-hidden">Message</label>
            <input type="text" class="form-control form-control-sm" id="chatTextInput"
                   placeholder="Type a message..." autocomplete="off">
            <button class="btn btn-sm btn-primary" id="chatSendBtn" title="Send" aria-label="Send message">
                <i class="bi bi-send"></i>
            </button>
        </div>
        <div class="chat-resize-handle"></div>
    </div>
</template>

<!-- Print date helper -->
<script>window.addEventListener('beforeprint', function () { document.body.setAttribute('data-print-date', new Date().toLocaleDateString() + ' ' + new Date().toLocaleTimeString()); });</script>

<!-- Vendor JS -->
<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="assets/js/toolbar.js?v=<?php echo asset_v('assets/js/toolbar.js'); ?>"></script>
<script>
// Phase 99o (Eric beta 2026-06-29) — expose admin-configured label
// so widget renderers can use a consistent name everywhere.
window.INCIDENT_NUMBER_LABEL = <?php echo json_encode($incNumLabel); ?>;
</script>
<script src="assets/vendor/gridstack/gridstack-all.js"></script>
<script src="assets/vendor/leaflet/leaflet.js"></script>
<script src="assets/js/leaflet-mobile-fit.js?v=<?php echo function_exists("asset_v")?asset_v("assets/js/leaflet-mobile-fit.js"):NEWUI_VERSION; ?>"></script>
<script src="assets/vendor/leaflet/plugins/leaflet-openweathermap.js"></script>
<script src="assets/vendor/leaflet/plugins/L.Graticule.js"></script>
<script src="assets/js/leaflet-quadkey.js"></script>
<script src="assets/js/map-prefs.js"></script>
<script src="assets/js/map-image-overlays.js?v=<?php echo function_exists('asset_v') ? asset_v('assets/js/map-image-overlays.js') : NEWUI_VERSION; ?>"></script>
<script src="assets/js/unit-tracking.js"></script>

<!-- App JS -->
<script src="assets/js/event-bus.js?v=<?php echo asset_v('assets/js/event-bus.js'); ?>"></script>
<script>var USER_PERMISSIONS = <?php echo json_encode($userPerms); ?>;</script>
<?php
// RBAC widget enforcement (specs/rbac-enforcement-2026-06): the authoritative
// list of dashboard widgets this user may render. widget-manager.js filters
// DEFAULT_LAYOUT / saved layouts against this so a stale layout or localStorage
// entry can't resurrect an ungranted widget.
$__allowedWidgets = array_values(array_filter(
    ['statistics','incidents','responders','facilities','controls','comms','map','log','audit_log','time_entries'],
    fn($w) => dash_can($w, $userPerms)
));
?>
<script>var ALLOWED_WIDGETS = <?php echo json_encode($__allowedWidgets); ?>;</script>
<script>var USER_ID = <?php echo (int)$_SESSION['user_id']; ?>;</script>
<script src="assets/js/data-service.js?v=<?php echo asset_v('assets/js/data-service.js'); ?>"></script>
<script src="assets/js/theme-manager.js?v=<?php echo asset_v('assets/js/theme-manager.js'); ?>"></script>
<script src="assets/js/screen-prefs.js?v=<?php echo asset_v('assets/js/screen-prefs.js'); ?>"></script>
<script src="assets/js/facility-status.js?v=<?php echo asset_v('assets/js/facility-status.js'); ?>"></script>
<script src="assets/js/widget-manager.js?v=<?php echo asset_v('assets/js/widget-manager.js'); ?>"></script>
<script src="assets/js/app.js?v=<?php echo asset_v('assets/js/app.js'); ?>"></script>
<script src="assets/js/zello-widget.js?v=<?php echo asset_v('assets/js/zello-widget.js'); ?>"></script>
<!-- radio-widget.js moved to inc/navbar.php so it loads on every page -->
<script src="assets/js/chat-widget.js?v=<?php echo asset_v('assets/js/chat-widget.js'); ?>"></script>
<script src="assets/js/keyboard-nav.js?v=<?php echo asset_v('assets/js/keyboard-nav.js'); ?>"></script>
<script src="assets/js/audio-alerts.js?v=<?php echo asset_v('assets/js/audio-alerts.js'); ?>"></script>
<script src="assets/js/widgets/audit-log-widget.js?v=<?php echo asset_v('assets/js/widgets/audit-log-widget.js'); ?>"></script>
<script src="assets/js/widgets/time-entries-widget.js?v=<?php echo asset_v('assets/js/widgets/time-entries-widget.js'); ?>"></script>
<script>
// Phase 80d - "My time" widget init. Polls every 60s and re-renders on
// widget:shown so the toggles toolbar interaction works as expected.
(function () {
    'use strict';
    function tryRender() {
        var el = document.getElementById('timeEntriesWidget');
        if (el && window.TimeEntriesWidget) {
            window.TimeEntriesWidget.render(el);
        }
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { setTimeout(tryRender, 400); });
    } else {
        setTimeout(tryRender, 400);
    }
    if (typeof EventBus !== 'undefined') {
        EventBus.on('widget:shown', function (data) {
            if (data && data.widget === 'time_entries') setTimeout(tryRender, 100);
        });
    }
    setInterval(tryRender, 60000);
})();
</script>

<!-- Phase 99n-v2 (Eric beta 2026-06-29) — Responder quick-action modal.
     One modal element reused for both Status and Note actions across
     0/1/N assignment counts. JS in app.js fills the body dynamically. -->
<div class="modal fade" id="responderActionModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title" id="responderActionModalTitle">Unit Quick Action</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body py-2" id="responderActionModalBody">
                <!-- populated by app.js -->
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Phase 115 (Eric 2026-07-06) — Facility quick-action modal. One element
     reused for Status / Note / Beds; app.js fills the body + wires Apply.
     GH #70 (a beta tester 2026-07-07): all strings routed through the caption
     table — shell via t(), JS-built form labels via FAC_MODAL_I18N. -->
<div class="modal fade" id="facilityActionModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title" id="facilityActionModalTitle"><?php echo e(t('facmodal.title', 'Facility Quick Action')); ?></h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body py-2" id="facilityActionModalBody">
                <!-- populated by app.js -->
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-sm btn-primary" id="facilityActionApply"><?php echo e(t('facmodal.apply', 'Apply')); ?></button>
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal"><?php echo e(t('facmodal.close', 'Close')); ?></button>
            </div>
        </div>
    </div>
</div>
<script>
// GH #70 — dashboard widget CARD HEADER titles route through the caption
// table (dash.widget.* keys, seeded by run_phase08_i18n.php) so a per-install
// rename (e.g. Facility->Clinic) reaches the widget header, not just the
// show/hide toggle button. widget-manager.js reads this and adds the icon.
window.DASH_WIDGET_TITLES = {
    statistics:   <?php echo json_encode(t('dash.widget.statistics', 'Statistics')); ?>,
    incidents:    <?php echo json_encode(t('dash.widget.incidents', 'Incidents')); ?>,
    responders:   <?php echo json_encode(t('dash.widget.responders', 'Responders')); ?>,
    facilities:   <?php echo json_encode(t('dash.widget.facilities', 'Facilities')); ?>,
    controls:     <?php echo json_encode(t('dash.widget.controls', 'Controls')); ?>,
    comms:        <?php echo json_encode(t('dash.widget.comms', 'Communications')); ?>,
    map:          <?php echo json_encode(t('dash.widget.map', 'Map')); ?>,
    log:          <?php echo json_encode(t('dash.widget.log', 'Recent Events')); ?>,
    audit_log:    <?php echo json_encode(t('dash.widget.audit_log', 'Recent activity')); ?>,
    time_entries: <?php echo json_encode(t('dash.widget.time_entries', 'My time')); ?>
};
window.FAC_MODAL_I18N = {
    set_status:     <?php echo json_encode(t('facmodal.set_status', 'Set Status')); ?>,
    bed_counts:     <?php echo json_encode(t('facmodal.bed_counts', 'Bed Counts')); ?>,
    add_note:       <?php echo json_encode(t('facmodal.add_note', 'Add Note')); ?>,
    status:         <?php echo json_encode(t('facmodal.status', 'Status')); ?>,
    note:           <?php echo json_encode(t('facmodal.note', 'Note')); ?>,
    optional:       <?php echo json_encode(t('facmodal.optional', '(optional)')); ?>,
    beds_available: <?php echo json_encode(t('facmodal.beds_available', 'Beds Available')); ?>,
    beds_occupied:  <?php echo json_encode(t('facmodal.beds_occupied', 'Beds Occupied')); ?>,
    ph_reason:      <?php echo json_encode(t('facmodal.ph_reason', 'Reason / detail…')); ?>,
    ph_beds:        <?php echo json_encode(t('facmodal.ph_beds', 'Bed/capacity detail…')); ?>,
    ph_note:        <?php echo json_encode(t('facmodal.ph_note', 'Facility note…')); ?>,
    loading:        <?php echo json_encode(t('facmodal.loading', 'Loading…')); ?>,
    pick_status:    <?php echo json_encode(t('facmodal.pick_status', 'Pick a status')); ?>,
    no_statuses:    <?php echo json_encode(t('facmodal.no_statuses', 'No facility statuses are configured yet. Add them under Settings → Facilities, then you can set a facility\'s status from here.')); ?>
};
</script>

</body>
</html>
