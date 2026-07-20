<?php
/**
 * NewUI v4.0 — Weather Alerts admin (Phase 112, Phase 1).
 *
 * Standalone admin page (same pattern as workflow-designer.php / map-overlays.php)
 * for configuring NWS weather-alert coverage areas, routing rules, and the radio
 * read-out settings. The whole feature is OFF by default (weather_alerts_enabled)
 * — this page is where an admin opts in, per-install.
 *
 * Backend: api/weather-alerts.php (config/areas/rules CRUD + test tools).
 * Admin-only: action.manage_weather_alerts (Super Admin + Org Admin by default,
 * seeded by sql/run_weather_alerts_perm.php).
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/inc/i18n.php';
require_once __DIR__ . '/inc/rbac.php';

require_once __DIR__ . '/inc/session-bootstrap.php';
sess_bootstrap_auto();
session_start();

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
require_once __DIR__ . '/inc/force-pw-change.php';
force_pw_change_redirect();

if (!rbac_can('action.manage_weather_alerts')) {
    http_response_code(403);
    $theme    = $_SESSION['day_night'] ?? 'Day';
    $bs_theme = ($theme === 'Night') ? 'dark' : 'light';
    ?>
<!DOCTYPE html>
<html lang="<?php echo e(i18n_lang()); ?>" data-bs-theme="<?php echo $bs_theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Weather Alerts — Tickets NewUI</title>
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-icons.min.css">
</head>
<body>
<main class="container py-5" style="max-width: 640px;">
    <div class="alert alert-warning">
        <h5 class="alert-heading"><i class="bi bi-shield-lock me-2"></i>Permission required</h5>
        <p class="mb-2">Weather Alerts configuration requires the "Manage Weather Alerts" permission. Ask an administrator to grant your role <code>action.manage_weather_alerts</code>.</p>
        <a href="index.php" class="btn btn-sm btn-outline-secondary">Back to dashboard</a>
    </div>
</main>
</body>
</html>
    <?php
    exit;
}

$user        = e($_SESSION['user']);
$theme       = $_SESSION['day_night'] ?? 'Day';
$bs_theme    = ($theme === 'Night') ? 'dark' : 'light';
$csrf        = csrf_token();
$active_page = 'settings';
?>
<!DOCTYPE html>
<html lang="<?php echo e(i18n_lang()); ?>" data-bs-theme="<?php echo $bs_theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?php echo e($csrf); ?>">
    <title>Weather Alerts — Tickets NewUI <?php echo NEWUI_VERSION; ?></title>
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?php echo asset_v('assets/css/dashboard.css'); ?>">
</head>
<body>
<?php include_once NEWUI_ROOT . '/inc/navbar.php'; ?>

<main class="container-fluid py-3" style="max-width: 1100px;">
    <div class="d-flex align-items-center mb-3">
        <h4 class="mb-0"><i class="bi bi-cloud-lightning-rain-fill text-danger me-2"></i>Weather Alerts</h4>
        <span class="badge bg-secondary ms-3" id="wxMasterBadge">loading…</span>
        <a href="docs/WEATHER-ALERTS-GUIDE.md" target="_blank" class="ms-auto btn btn-sm btn-outline-secondary"><i class="bi bi-question-circle me-1"></i>Guide</a>
    </div>

    <div id="wxWarning" class="alert alert-warning d-none" role="alert"></div>
    <div id="wxToast" class="alert d-none" role="status"></div>

    <!-- Master switch + polling -->
    <div class="card mb-3">
        <div class="card-header"><i class="bi bi-toggles me-2"></i>Master switch &amp; polling</div>
        <div class="card-body">
            <p class="text-body-secondary small mb-3">
                NWS weather alerts are <strong>off by default</strong>. Turn this on only if your agency wants
                National Weather Service watches/warnings surfaced in the CAD. Installs outside the US can leave it off.
            </p>
            <div class="row g-3">
                <div class="col-md-4">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="wxEnabled">
                        <label class="form-check-label" for="wxEnabled">Enable weather alerts</label>
                    </div>
                </div>
                <div class="col-md-8">
                    <label class="form-label form-label-sm mb-0">Contact email (required — NWS User-Agent)</label>
                    <input type="email" class="form-control form-control-sm" id="wxUaContact" placeholder="dispatch@example.org">
                    <div class="form-text">The NWS API rejects anonymous requests. This contact identifies your install.</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label form-label-sm mb-0">Poll interval (seconds)</label>
                    <input type="number" min="30" class="form-control form-control-sm" id="wxPollSeconds" value="60">
                </div>
                <div class="col-md-4">
                    <label class="form-label form-label-sm mb-0">Provider</label>
                    <select class="form-select form-select-sm" id="wxProvider">
                        <option value="nws">NWS (United States)</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label form-label-sm mb-0">
                        Units inside alert polygons
                        <i class="bi bi-question-circle text-body-secondary" tabindex="0"
                           data-bs-toggle="popover" data-bs-trigger="hover focus" data-bs-placement="top"
                           data-bs-content="When a warning carries a storm polygon, cross-reference live unit positions (and the active event's zones) and name who is inside — in the notification and on the situation map."
                           title="Geofence cross-reference"></i>
                    </label>
                    <select class="form-select form-select-sm" id="wxGeofenceUnits">
                        <option value="1">Flag units and zones inside the polygon (recommended)</option>
                        <option value="0">Off</option>
                    </select>
                </div>
            </div>
            <div class="mt-3">
                <button class="btn btn-sm btn-primary" id="wxSaveSettings"><i class="bi bi-save me-1"></i>Save settings</button>
            </div>
        </div>
    </div>

    <!-- Coverage areas -->
    <div class="card mb-3">
        <div class="card-header d-flex align-items-center">
            <span><i class="bi bi-bounding-box me-2"></i>Coverage areas</span>
            <button class="btn btn-sm btn-outline-primary ms-auto" id="wxAddArea"><i class="bi bi-plus-lg"></i> Add area</button>
        </div>
        <div class="card-body">
            <p class="text-body-secondary small">Define where you want alerts from: a whole state, a set of NWS forecast zones/counties, or a point + radius.</p>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th>Label</th><th>Kind</th><th>Value</th><th>Active</th><th></th></tr></thead>
                    <tbody id="wxAreaRows"><tr><td colspan="5" class="text-body-secondary">Loading…</td></tr></tbody>
                </table>
            </div>
            <!-- Inline area editor -->
            <div class="border rounded p-3 mt-3 d-none" id="wxAreaEditor">
                <input type="hidden" id="wxAreaId">
                <div class="row g-2">
                    <div class="col-md-4">
                        <label class="form-label form-label-sm mb-0">Label</label>
                        <input class="form-control form-control-sm" id="wxAreaLabel" placeholder="MN statewide">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label form-label-sm mb-0">Kind</label>
                        <select class="form-select form-select-sm" id="wxAreaKind">
                            <option value="state">State</option>
                            <option value="zones">Zones / counties (UGC)</option>
                            <option value="point_radius">Point + radius</option>
                        </select>
                    </div>
                    <div class="col-md-5 wx-area-state">
                        <label class="form-label form-label-sm mb-0">State code</label>
                        <input class="form-control form-control-sm" id="wxAreaState" maxlength="2" placeholder="MN">
                    </div>
                    <div class="col-md-5 wx-area-zones d-none">
                        <label class="form-label form-label-sm mb-0">UGC zones/counties (CSV)</label>
                        <input class="form-control form-control-sm" id="wxAreaZones" placeholder="MNZ060,MNC053">
                        <div class="input-group input-group-sm mt-1">
                            <input class="form-control" id="wxCountyState" maxlength="2" placeholder="State (e.g. MN)" style="max-width:110px;">
                            <button type="button" class="btn btn-outline-secondary" id="wxLoadCounties">Pick counties…</button>
                        </div>
                        <div id="wxCountyList" class="border rounded p-2 mt-1 d-none"
                             style="max-height:180px;overflow-y:auto;columns:2;"></div>
                    </div>
                    <div class="col-md-5 wx-area-point d-none">
                        <label class="form-label form-label-sm mb-0">Lat / Lng / Radius (mi)</label>
                        <div class="input-group input-group-sm">
                            <input class="form-control" id="wxAreaLat" placeholder="44.98">
                            <input class="form-control" id="wxAreaLng" placeholder="-93.27">
                            <input class="form-control" id="wxAreaRadius" placeholder="40">
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-check form-switch mt-4">
                            <input class="form-check-input" type="checkbox" id="wxAreaActive" checked>
                            <label class="form-check-label" for="wxAreaActive">Active</label>
                        </div>
                    </div>
                </div>
                <div class="mt-2">
                    <button class="btn btn-sm btn-primary" id="wxSaveArea">Save area</button>
                    <button class="btn btn-sm btn-outline-secondary" id="wxCancelArea">Cancel</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Routing rules -->
    <div class="card mb-3">
        <div class="card-header d-flex align-items-center">
            <span><i class="bi bi-signpost-split me-2"></i>Routing rules</span>
            <button class="btn btn-sm btn-outline-primary ms-auto" id="wxAddRule"><i class="bi bi-plus-lg"></i> Add rule</button>
        </div>
        <div class="card-body">
            <p class="text-body-secondary small">Each rule sends alerts from one area to one destination when they clear a severity/urgency floor.
                <strong>Tray, chat, SMS and email</strong> deliver now; radio read-out (DMR/Zello) arrives in Phase 3.</p>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th>Label</th><th>Area</th><th>Target</th><th>Min severity</th><th>Mode</th><th>Active</th><th></th></tr></thead>
                    <tbody id="wxRuleRows"><tr><td colspan="7" class="text-body-secondary">Loading…</td></tr></tbody>
                </table>
            </div>
            <div class="border rounded p-3 mt-3 d-none" id="wxRuleEditor">
                <input type="hidden" id="wxRuleId">
                <div class="row g-2">
                    <div class="col-md-4">
                        <label class="form-label form-label-sm mb-0">Label</label>
                        <input class="form-control form-control-sm" id="wxRuleLabel" placeholder="Tray — severe MN">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label form-label-sm mb-0">Area</label>
                        <select class="form-select form-select-sm" id="wxRuleArea"></select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label form-label-sm mb-0">Target</label>
                        <select class="form-select form-select-sm" id="wxRuleTarget">
                            <option value="tray">Notification tray + chime</option>
                            <option value="chat">Chat</option>
                            <option value="sms">SMS</option>
                            <option value="email">Email</option>
                            <option value="dmr">DMR read-out (Phase 3)</option>
                            <option value="zello">Zello read-out (Phase 3)</option>
                        </select>
                    </div>
                    <div class="col-md-4 wx-rule-ref d-none">
                        <label class="form-label form-label-sm mb-0">Target ref (TG / channel / list)</label>
                        <input class="form-control form-control-sm" id="wxRuleRef" placeholder="3127">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label form-label-sm mb-0">Min severity</label>
                        <select class="form-select form-select-sm" id="wxRuleSeverity">
                            <option>Minor</option><option>Moderate</option><option selected>Severe</option><option>Extreme</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label form-label-sm mb-0">Min urgency</label>
                        <select class="form-select form-select-sm" id="wxRuleUrgency">
                            <option>Past</option><option>Future</option><option selected>Expected</option><option>Immediate</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label form-label-sm mb-0">Mode</label>
                        <select class="form-select form-select-sm" id="wxRuleMode">
                            <option value="notify">Notify</option>
                            <option value="auto_fire">Auto-fire</option>
                            <option value="operator_approve">Operator approve</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label form-label-sm mb-0">Message types</label>
                        <input class="form-control form-control-sm" id="wxRuleMsgTypes" value="Alert,Update">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label form-label-sm mb-0">Event allow (CSV, blank = all)</label>
                        <input class="form-control form-control-sm" id="wxRuleAllow" placeholder="tornado,severe thunderstorm">
                        <div class="mt-1 small" id="wxRuleQuickTypes">
                            <span class="text-body-secondary me-1">Quick picks:</span>
                            <!-- Each button toggles its term in the CSV above. "Warnings only"
                                 matches every NWS *Warning event by substring. -->
                            <button type="button" class="btn btn-outline-secondary btn-sm py-0 wx-evt" data-evt="warning">Warnings only</button>
                            <button type="button" class="btn btn-outline-secondary btn-sm py-0 wx-evt" data-evt="tornado warning">Tornado</button>
                            <button type="button" class="btn btn-outline-secondary btn-sm py-0 wx-evt" data-evt="severe thunderstorm warning">Svr T-storm</button>
                            <button type="button" class="btn btn-outline-secondary btn-sm py-0 wx-evt" data-evt="flash flood warning">Flash Flood</button>
                            <button type="button" class="btn btn-outline-secondary btn-sm py-0 wx-evt" data-evt="extreme wind warning">Extreme Wind</button>
                            <button type="button" class="btn btn-outline-secondary btn-sm py-0 wx-evt" data-evt="snow squall warning">Snow Squall</button>
                            <button type="button" class="btn btn-outline-secondary btn-sm py-0 wx-evt" data-evt="blizzard warning">Blizzard</button>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label form-label-sm mb-0">Event deny (CSV)</label>
                        <input class="form-control form-control-sm" id="wxRuleDeny" placeholder="">
                    </div>
                    <div class="col-md-2">
                        <div class="form-check form-switch mt-4">
                            <input class="form-check-input" type="checkbox" id="wxRuleRepeat" checked>
                            <label class="form-check-label" for="wxRuleRepeat">Repeat on update</label>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-check form-switch mt-4">
                            <input class="form-check-input" type="checkbox" id="wxRuleActive" checked>
                            <label class="form-check-label" for="wxRuleActive">Active</label>
                        </div>
                    </div>
                </div>
                <div class="mt-2">
                    <button class="btn btn-sm btn-primary" id="wxSaveRule">Save rule</button>
                    <button class="btn btn-sm btn-outline-secondary" id="wxCancelRule">Cancel</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Radio read-out settings (apply to DMR/Zello targets, Phase 3) -->
    <div class="card mb-3">
        <div class="card-header"><i class="bi bi-broadcast me-2"></i>Radio read-out settings <span class="badge bg-secondary ms-2">Phase 3</span></div>
        <div class="card-body">
            <p class="text-body-secondary small">Used when a rule's target is DMR or Zello. Relaying NWS warnings on amateur DMR (e.g. TG 3127) is SKYWARN — set your callsign so each transmission carries a §97.119 station ID.</p>
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label form-label-sm mb-0">Clear-channel wait (s)</label>
                    <input type="number" step="0.25" min="0" class="form-control form-control-sm" id="wxTtsClear" value="3.0">
                </div>
                <div class="col-md-3">
                    <label class="form-label form-label-sm mb-0">Callsign (amateur ID)</label>
                    <input class="form-control form-control-sm" id="wxTtsCallsign" placeholder="N0NKI">
                </div>
                <div class="col-md-3">
                    <label class="form-label form-label-sm mb-0">Max read-out (s)</label>
                    <input type="number" min="10" class="form-control form-control-sm" id="wxTtsMax" value="45">
                </div>
                <div class="col-md-3">
                    <label class="form-label form-label-sm mb-0">Piper voice id</label>
                    <input class="form-control form-control-sm" id="wxTtsVoice" placeholder="(default)">
                </div>
                <div class="col-12">
                    <label class="form-label form-label-sm mb-0">Read-out prefix</label>
                    <input class="form-control form-control-sm" id="wxTtsPrefix" value="Weather bulletin from the National Weather Service.">
                </div>
                <div class="col-12">
                    <label class="form-label form-label-sm mb-0">Unattended keying (auto-fire)</label>
                    <select class="form-select form-select-sm" id="wxRadioAutofire">
                        <option value="0">Operator must approve every transmission (recommended)</option>
                        <option value="1">Allow auto-fire rules to key unattended</option>
                    </select>
                    <div class="form-text">
                        Even when a rule is set to <em>Auto-fire</em>, nothing keys unattended unless this is
                        explicitly enabled — auto-fire rules degrade to the operator-approval queue instead.
                        FCC §97.103 puts control-operator responsibility on the licensee; leave this on
                        operator-approve unless you are running an attended watch.
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Test tools -->
    <div class="card mb-4">
        <div class="card-header"><i class="bi bi-tools me-2"></i>Test &amp; setup tools</div>
        <div class="card-body">
            <div class="d-flex flex-wrap gap-2">
                <button class="btn btn-sm btn-outline-primary" id="wxTestFixture"><i class="bi bi-bell me-1"></i>Send test alert to tray</button>
                <button class="btn btn-sm btn-outline-secondary" id="wxDryRun"><i class="bi bi-binoculars me-1"></i>Dry-run live NWS</button>
                <button class="btn btn-sm btn-outline-secondary" id="wxTestPoll"><i class="bi bi-cloud-download me-1"></i>Poll now (live)</button>
                <button class="btn btn-sm btn-outline-success ms-auto" id="wxLoadMn"><i class="bi bi-geo me-1"></i>Load Minnesota example</button>
            </div>
            <pre class="mt-3 mb-0 p-2 bg-body-tertiary rounded small d-none" id="wxTestResult" style="white-space:pre-wrap;"></pre>
        </div>
    </div>
</main>

<input type="hidden" id="csrfToken" value="<?php echo e($csrf); ?>">

<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="assets/js/theme-manager.js?v=<?php echo asset_v('assets/js/theme-manager.js'); ?>"></script>
<script src="assets/js/weather-config.js?v=<?php echo asset_v('assets/js/weather-config.js'); ?>"></script>
</body>
</html>
