<?php
/**
 * NewUI v4.0 - Settings / Configuration
 *
 * Hierarchical settings page with sidebar navigation.
 * 7 sections, each with sub-tabs. Phase 1 implements:
 *   - Incident Types (CRUD + regex match patterns)
 *   - Unit Statuses (CRUD)
 *   - Facilities (CRUD)
 *   - System Settings + API Keys
 *   - User Accounts (CRUD)
 * All other tabs show placeholder panels.
 *
 * Requires admin level (0 = Super, 1 = Admin).
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/inc/i18n.php';

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


// Require admin (level <= 1 means Super or Admin)
$userLevel = (int) ($_SESSION['level'] ?? 99);
if ($userLevel > 1) {
    http_response_code(403);
    // Themed Access-Denied card (specs/rbac-enforcement-2026-06) — consistent
    // with rbac_require_screen()'s denial used by every other gated screen.
    $GLOBALS['__denied_perm'] = 'screen.settings';
    require_once __DIR__ . '/inc/denied.php';
    exit;
}

$user     = e($_SESSION['user']);
$level    = get_level_text($userLevel);
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
    <title><?php echo e(t('nav.settings', 'Settings')); ?> — <?php echo e(t('login.title', 'Tickets NewUI')); ?> <?php echo NEWUI_VERSION; ?></title>

    <!-- Vendor CSS -->
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/vendor/leaflet/leaflet.css">

    <!-- App CSS -->
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?php echo asset_v('assets/css/dashboard.css'); ?>">
    <link rel="stylesheet" href="assets/css/config.css?v=<?php echo asset_v('assets/css/config.css'); ?>">
    <link rel="stylesheet" href="assets/css/searchable-select.css?v=<?php echo asset_v('assets/css/searchable-select.css'); ?>">
</head>
<body>

<?php include_once NEWUI_ROOT . '/inc/navbar.php'; ?>
</header>

<!-- Personnel sub-nav (shown when accessed from personnel section) -->
<?php
$personnelSections = ['certifications', 'ics-positions', 'training', 'member-types', 'member-statuses', 'organizations', 'teams'];
$hash = isset($_SERVER['HTTP_REFERER']) ? parse_url($_SERVER['HTTP_REFERER'], PHP_URL_FRAGMENT) : '';
// Always show it — the JS will handle hiding if not relevant
$personnel_active = '';
foreach ($personnelSections as $sec) {
    if (strpos($_SERVER['REQUEST_URI'] ?? '', '#' . $sec) !== false) {
        $personnel_active = $sec;
        break;
    }
}
?>
<div id="personnelSubNav" class="px-3 pt-2" style="display:none;">
    <?php include_once __DIR__ . '/inc/personnel-nav.php'; ?>
</div>
<script>
(function() {
    var personnelHashes = ['certifications','ics-positions','training','member-types','member-statuses','organizations','teams'];
    var nav = document.getElementById('personnelSubNav');
    if (!nav) return;
    function checkHash() {
        var hash = (window.location.hash || '').replace('#', '');
        nav.style.display = personnelHashes.indexOf(hash) >= 0 ? '' : 'none';
    }
    checkHash();
    window.addEventListener('hashchange', checkHash);
})();
</script>

<!-- Config Layout: Sidebar + Content -->
<div class="config-layout">

    <!-- ═══════════ SIDEBAR ═══════════ -->
    <?php $configActivePage = ''; include_once NEWUI_ROOT . '/inc/config-sidebar.php'; ?>

    <!-- ═══════════ CONTENT AREA ═══════════ -->
    <main class="config-content" id="configContent">

        <!-- Welcome / dashboard view -->
        <div class="config-panel active" id="panel-welcome">
            <div class="config-panel-title">
                <i class="bi bi-speedometer2 text-primary"></i> System Overview
            </div>

            <!-- Phase 38: Onboarding hints — populated from /api/config-summary.php -->
            <div id="welcomeHints" class="mb-3 d-none"></div>

            <!-- Quick stats cards -->
            <div class="row g-2 mb-3" id="welcomeStatsRow">
                <div class="col-6 col-md-4 col-lg-2">
                    <div class="card text-center border-0 bg-body-tertiary">
                        <div class="card-body py-2 px-1">
                            <div class="fs-4 fw-bold text-success" id="wsUsers">--</div>
                            <div class="small text-body-secondary">Users</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-4 col-lg-2">
                    <div class="card text-center border-0 bg-body-tertiary">
                        <div class="card-body py-2 px-1">
                            <div class="fs-4 fw-bold text-info" id="wsMembers">--</div>
                            <div class="small text-body-secondary">Personnel</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-4 col-lg-2">
                    <div class="card text-center border-0 bg-body-tertiary">
                        <div class="card-body py-2 px-1">
                            <div class="fs-4 fw-bold text-primary" id="wsUnits">--</div>
                            <div class="small text-body-secondary">Units</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-4 col-lg-2">
                    <div class="card text-center border-0 bg-body-tertiary">
                        <div class="card-body py-2 px-1">
                            <div class="fs-4 fw-bold text-warning" id="wsTypes">--</div>
                            <div class="small text-body-secondary">Incident Types</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-4 col-lg-2">
                    <div class="card text-center border-0 bg-body-tertiary">
                        <div class="card-body py-2 px-1">
                            <div class="fs-4 fw-bold text-danger" id="wsFacilities">--</div>
                            <div class="small text-body-secondary">Facilities</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-4 col-lg-2">
                    <div class="card text-center border-0 bg-body-tertiary">
                        <div class="card-body py-2 px-1">
                            <div class="fs-4 fw-bold text-info" id="wsTeams">--</div>
                            <div class="small text-body-secondary">Teams</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-3">

                <!-- Security Status -->
                <div class="col-md-6">
                    <div class="card border-0 bg-body-tertiary h-100">
                        <div class="card-header py-2 bg-transparent border-bottom-0">
                            <i class="bi bi-shield-check text-success me-1"></i>
                            <span class="fw-semibold small">Security Status</span>
                        </div>
                        <div class="card-body pt-0 small" id="wsSecurityBody">
                            <div class="d-flex justify-content-between py-1 border-bottom border-opacity-10">
                                <span>HTTPS</span>
                                <span id="wsHttps" class="badge bg-secondary">--</span>
                            </div>
                            <div class="d-flex justify-content-between py-1 border-bottom border-opacity-10">
                                <span>Two-Factor Auth</span>
                                <span id="wsTfa" class="badge bg-secondary">--</span>
                            </div>
                            <div class="d-flex justify-content-between py-1 border-bottom border-opacity-10">
                                <span>2FA Coverage</span>
                                <span id="wsTfaCoverage" class="text-body-secondary">--</span>
                            </div>
                            <div class="d-flex justify-content-between py-1 border-bottom border-opacity-10">
                                <span>Failed Logins (24h)</span>
                                <span id="wsFailedLogins" class="text-body-secondary">--</span>
                            </div>
                            <div class="d-flex justify-content-between py-1">
                                <span>Active Sessions</span>
                                <span id="wsSessions" class="text-body-secondary">--</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- System Info -->
                <div class="col-md-6">
                    <div class="card border-0 bg-body-tertiary h-100">
                        <div class="card-header py-2 bg-transparent border-bottom-0">
                            <i class="bi bi-hdd-rack text-secondary me-1"></i>
                            <span class="fw-semibold small">System Info</span>
                        </div>
                        <div class="card-body pt-0 small">
                            <div class="d-flex justify-content-between py-1 border-bottom border-opacity-10">
                                <span>Version</span>
                                <span class="font-monospace" id="wsVersion"><?php echo NEWUI_VERSION; ?></span>
                            </div>
                            <div class="d-flex justify-content-between py-1 border-bottom border-opacity-10">
                                <span>PHP</span>
                                <span class="font-monospace" id="wsPhp"><?php echo PHP_VERSION; ?></span>
                            </div>
                            <div class="d-flex justify-content-between py-1 border-bottom border-opacity-10">
                                <span>Database Size</span>
                                <span class="font-monospace" id="wsDbSize">--</span>
                            </div>
                            <div class="d-flex justify-content-between py-1 border-bottom border-opacity-10">
                                <span>Tables</span>
                                <span class="font-monospace" id="wsDbTables">--</span>
                            </div>
                            <div class="d-flex justify-content-between py-1">
                                <span>Location Providers</span>
                                <span id="wsLocProviders" class="text-body-secondary">--</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick Links -->
                <div class="col-12">
                    <div class="card border-0 bg-body-tertiary">
                        <div class="card-header py-2 bg-transparent border-bottom-0">
                            <i class="bi bi-lightning text-warning me-1"></i>
                            <span class="fw-semibold small">Quick Actions</span>
                        </div>
                        <div class="card-body pt-0">
                            <div class="d-flex flex-wrap gap-2">
                                <a href="status.php" class="btn btn-sm btn-outline-success"><i class="bi bi-heart-pulse me-1"></i>System Health</a>
                                <a href="#user-accounts" class="btn btn-sm btn-outline-primary config-quick-link"><i class="bi bi-people me-1"></i>User Accounts</a>
                                <a href="#incident-types" class="btn btn-sm btn-outline-warning config-quick-link"><i class="bi bi-exclamation-triangle me-1"></i>Incident Types</a>
                                <a href="#login-settings" class="btn btn-sm btn-outline-info config-quick-link"><i class="bi bi-shield-lock me-1"></i>Login Settings</a>
                                <a href="#members" class="btn btn-sm btn-outline-secondary config-quick-link"><i class="bi bi-person-badge me-1"></i>Personnel</a>
                                <a href="#email-config" class="btn btn-sm btn-outline-danger config-quick-link"><i class="bi bi-envelope me-1"></i>Email Config</a>
                                <a href="#tracking-providers" class="btn btn-sm btn-outline-primary config-quick-link"><i class="bi bi-broadcast me-1"></i>Location Providers</a>
                                <a href="#backup" class="btn btn-sm btn-outline-secondary config-quick-link"><i class="bi bi-cloud-download me-1"></i>Backup</a>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <!-- ── Audit Log ─────────────────────────────────────────── -->
        <div class="config-panel" id="panel-audit-log">
            <div class="config-panel-title">
                <i class="bi bi-journal-text text-danger"></i> Audit Log
            </div>
            <p class="text-body-secondary small mb-3">
                Browse system activity including logins, config changes, incident updates, and personnel modifications.
            </p>

            <!-- Filters -->
            <div class="row g-2 mb-3">
                <div class="col-md-2">
                    <select class="form-select form-select-sm" id="auditCategory">
                        <option value="">All Categories</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <select class="form-select form-select-sm" id="auditActivity">
                        <option value="">All Activities</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <select class="form-select form-select-sm" id="auditSeverity">
                        <option value="">Any Severity</option>
                        <option value="1">Info+</option>
                        <option value="2">Low+</option>
                        <option value="3">Medium+</option>
                        <option value="4">High+</option>
                        <option value="5">Critical</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <input type="text" class="form-control form-control-sm" id="auditUser" placeholder="User...">
                </div>
                <div class="col-md-2">
                    <input type="date" class="form-control form-control-sm" id="auditDateFrom" title="From date">
                </div>
                <div class="col-md-2">
                    <input type="date" class="form-control form-control-sm" id="auditDateTo" title="To date">
                </div>
            </div>
            <div class="row g-2 mb-3">
                <div class="col-md-6">
                    <input type="text" class="form-control form-control-sm" id="auditSearch" placeholder="Search summary text...">
                </div>
                <div class="col-md-6 d-flex align-items-center gap-2">
                    <button class="btn btn-sm btn-primary" id="btnAuditSearch"><i class="bi bi-search me-1"></i>Search</button>
                    <button class="btn btn-sm btn-outline-secondary" id="btnAuditClear"><i class="bi bi-x-lg me-1"></i>Clear</button>
                    <span class="text-body-secondary small ms-auto" id="auditSummary"></span>
                </div>
            </div>

            <!-- Results table -->
            <div class="table-responsive">
                <table class="table table-sm table-hover config-table" id="auditTable">
                    <thead>
                        <tr>
                            <th class="sortable" data-sort="event_time" style="width:130px;">Time</th>
                            <th class="sortable" data-sort="severity" style="width:70px;">Sev</th>
                            <th class="sortable" data-sort="category" style="width:90px;">Category</th>
                            <th class="sortable" data-sort="activity" style="width:80px;">Action</th>
                            <th class="sortable" data-sort="user_name" style="width:90px;">User</th>
                            <th class="sortable" data-sort="target_type" style="width:90px;">Target</th>
                            <th class="sortable" data-sort="summary">Summary</th>
                        </tr>
                    </thead>
                    <tbody id="auditTableBody"></tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div class="d-flex align-items-center justify-content-between mt-2">
                <nav><ul class="pagination pagination-sm mb-0" id="auditPagination"></ul></nav>
                <span class="text-body-secondary small" id="auditPageInfo"></span>
            </div>

            <div class="config-status-bar" id="auditStatus">Ready</div>
        </div>

        <!-- ── Wastebasket ─────────────────────────────────────────── -->
        <div class="config-panel" id="panel-wastebasket">
            <div class="config-panel-title">
                <i class="bi bi-trash3 text-danger"></i> Wastebasket
                <span class="badge bg-danger ms-2" id="wbTotalBadge">0</span>
            </div>
            <p class="text-body-secondary small mb-3">
                Deleted records are kept here and can be restored. Items older than the configured retention period
                can be permanently purged.
            </p>

            <!-- Toolbar -->
            <div class="d-flex gap-2 mb-3 align-items-center flex-wrap">
                <select class="form-select form-select-sm" id="wbTypeFilter" style="max-width:180px;">
                    <option value="">All Types</option>
                    <option value="member">Members</option>
                    <option value="responder">Units</option>
                    <option value="ticket">Incidents</option>
                    <option value="facilities">Facilities</option>
                </select>
                <button class="btn btn-sm btn-outline-secondary" id="wbRefreshBtn">
                    <i class="bi bi-arrow-clockwise me-1"></i>Refresh
                </button>
                <div class="ms-auto d-flex gap-2 align-items-center">
                    <label class="form-label form-label-sm mb-0 small">Purge items older than</label>
                    <input type="number" class="form-control form-control-sm" id="wbPurgeDays"
                           value="30" min="1" max="365" style="width:70px;">
                    <span class="small">days</span>
                    <button class="btn btn-sm btn-outline-danger" id="wbEmptyBtn">
                        <i class="bi bi-trash3 me-1"></i>Empty
                    </button>
                </div>
            </div>

            <!-- Results table -->
            <div class="table-responsive">
                <table class="table table-sm table-hover config-table" id="wbTable">
                    <thead>
                        <tr>
                            <th style="width:30px;"></th>
                            <th>Type</th>
                            <th>Name / Description</th>
                            <th>Deleted By</th>
                            <th>Deleted At</th>
                            <th style="width:140px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="wbTableBody"></tbody>
                </table>
            </div>

            <div class="config-status-bar" id="wbStatus">Ready</div>
        </div>

        <!-- ── Incident Types ──────────────────────────────────────── -->
        <div class="config-panel" id="panel-incident-types">
            <div class="config-panel-title">
                <i class="bi bi-tag text-primary"></i> Incident Types
            </div>

            <!-- Edit form (slide-down) -->
            <div class="config-edit-panel" id="typeEditPanel">
                <form id="typeForm">
                    <input type="hidden" id="typeId" name="id" value="">
                    <div class="row g-2">
                        <div class="col-md-5">
                            <label for="typeName" class="form-label form-label-sm">Type Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control form-control-sm" id="typeName" name="type" required maxlength="100">
                        </div>
                        <div class="col-md-3">
                            <label for="typeGroup" class="form-label form-label-sm">Group</label>
                            <input type="text" class="form-control form-control-sm" id="typeGroup" name="group" maxlength="50" placeholder="e.g. Fire, EMS, Law">
                        </div>
                        <div class="col-md-2">
                            <label for="typeSeverity" class="form-label form-label-sm">Severity</label>
                            <select class="form-select form-select-sm" id="typeSeverity" name="set_severity">
                                <option value="0">Normal</option>
                                <option value="1">Elevated</option>
                                <option value="2">Critical</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label for="typeSort" class="form-label form-label-sm">Sort Order</label>
                            <input type="number" class="form-control form-control-sm" id="typeSort" name="sort" value="0" min="0">
                        </div>
                        <div class="col-md-6">
                            <label for="typeDesc" class="form-label form-label-sm">Description</label>
                            <input type="text" class="form-control form-control-sm" id="typeDesc" name="description" maxlength="255">
                        </div>
                        <div class="col-md-3">
                            <label for="typeColor" class="form-label form-label-sm">Color</label>
                            <div class="input-group input-group-sm">
                                <input type="color" class="form-control form-control-sm form-control-color" id="typeColor" name="color" value="#0d6efd" style="width:38px;">
                                <input type="text" class="form-control form-control-sm" id="typeColorText" maxlength="7" placeholder="#hex">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <label for="typeRadius" class="form-label form-label-sm">
                                Map Radius
                                <i class="bi bi-question-circle text-body-secondary" tabindex="0"
                                   data-bs-toggle="popover" data-bs-trigger="hover focus" data-bs-placement="top"
                                   data-bs-content="Radius in meters of the area circle drawn on the map for incidents of this type. Set to 0 for no circle."
                                   title="Map radius help"></i>
                            </label>
                            <input type="number" class="form-control form-control-sm" id="typeRadius" name="radius" value="0" min="0">
                        </div>
                        <div class="col-12">
                            <label class="form-label form-label-sm fw-bold mb-1">
                                PAR Check Behavior for this Type
                                <i class="bi bi-question-circle text-body-secondary" tabindex="0"
                                   data-bs-toggle="popover" data-bs-trigger="hover focus" data-bs-placement="top"
                                   data-bs-content="Choose how Personnel Accountability Report cadence applies to incidents of this type. Per-incident overrides on the ticket itself still win over this value. Phase 32 (2026-06-12)."
                                   title="PAR cadence help"></i>
                            </label>
                            <div class="d-flex flex-wrap gap-3 align-items-center">
                                <div class="form-check">
                                    <input class="form-check-input par-mode-radio" type="radio" name="par_mode" id="typeParModeDefault" value="default" checked>
                                    <label class="form-check-label small" for="typeParModeDefault">
                                        Use agency / system default
                                    </label>
                                </div>
                                <div class="form-check d-flex align-items-center gap-2">
                                    <input class="form-check-input par-mode-radio" type="radio" name="par_mode" id="typeParModeOverride" value="override">
                                    <label class="form-check-label small" for="typeParModeOverride">
                                        Override with
                                    </label>
                                    <input type="number" class="form-control form-control-sm" id="typeParCadence" name="par_cadence_minutes" value="0" min="1" max="600" style="width:80px;" placeholder="min" disabled>
                                    <span class="small text-body-secondary">minutes</span>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input par-mode-radio" type="radio" name="par_mode" id="typeParModeDisabled" value="disabled">
                                    <label class="form-check-label small" for="typeParModeDisabled">
                                        Disable PAR for this type
                                    </label>
                                </div>
                            </div>
                        </div>
                        <div class="col-12">
                            <label for="typeProtocol" class="form-label form-label-sm">Protocol</label>
                            <textarea class="form-control form-control-sm" id="typeProtocol" name="protocol" rows="3"
                                      placeholder="Step-by-step response protocol..."></textarea>
                        </div>
                        <div class="col-md-6">
                            <label for="typeMatchPattern" class="form-label form-label-sm">
                                Regex Match Pattern
                                <small class="text-body-secondary">(auto-selects this type when description matches)</small>
                            </label>
                            <input type="text" class="form-control form-control-sm" id="typeMatchPattern" name="match_pattern"
                                   placeholder="fire.*structure|house fire|building fire">
                        </div>
                        <div class="col-md-6">
                            <label for="patternTestInput" class="form-label form-label-sm">Test Pattern</label>
                            <div class="input-group input-group-sm">
                                <input type="text" class="form-control" id="patternTestInput"
                                       placeholder="Type sample text to test the pattern...">
                                <span class="input-group-text" id="patternTestResult">
                                    <i class="bi bi-dash text-body-secondary"></i>
                                </span>
                            </div>
                        </div>
                    </div>
                    <div class="d-flex gap-2 mt-3">
                        <button type="submit" class="btn btn-sm btn-success"><i class="bi bi-check-lg me-1"></i>Save</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="btnCancelType"><i class="bi bi-x-lg me-1"></i>Cancel</button>
                        <button type="button" class="btn btn-sm btn-outline-danger ms-auto d-none" id="btnDeleteType"><i class="bi bi-trash me-1"></i>Delete</button>
                    </div>
                </form>
            </div>

            <!-- Toolbar -->
            <div class="config-toolbar">
                <input type="text" class="form-control form-control-sm" id="typeSearch" placeholder="Search types...">
                <select class="form-select form-select-sm" id="typeGroupFilter" style="max-width:160px;">
                    <option value="">All Groups</option>
                </select>
                <button class="btn btn-sm btn-success ms-auto" id="btnAddType"><i class="bi bi-plus-lg me-1"></i>Add Type</button>
            </div>

            <!-- Table -->
            <div class="table-responsive">
                <table class="table table-sm table-hover config-table">
                    <thead>
                        <tr>
                            <th style="width:40px;">ID</th>
                            <th>Type</th>
                            <th>Group</th>
                            <th>Sev</th>
                            <th>Color</th>
                            <th>Pattern</th>
                            <th style="width:50px;">Sort</th>
                        </tr>
                    </thead>
                    <tbody id="typesTableBody"></tbody>
                </table>
            </div>
            <div class="config-status-bar" id="typesStatus">Loading...</div>
        </div>

        <!-- ── Unit Statuses ───────────────────────────────────────── -->
        <div class="config-panel" id="panel-unit-statuses">
            <div class="config-panel-title">
                <i class="bi bi-signpost text-primary"></i> Unit Statuses
            </div>

            <div class="config-edit-panel" id="statusEditPanel">
                <form id="statusForm">
                    <input type="hidden" id="statusId" name="id" value="">
                    <div class="row g-2">
                        <div class="col-md-3">
                            <label for="statusDesc" class="form-label form-label-sm">Status Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control form-control-sm" id="statusDesc" name="description" required maxlength="60">
                        </div>
                        <div class="col-md-2">
                            <label for="statusVal" class="form-label form-label-sm">
                                Short Code
                                <i class="bi bi-question-circle text-body-secondary" tabindex="0"
                                   data-bs-toggle="popover" data-bs-trigger="hover focus" data-bs-placement="top"
                                   data-bs-content="A short code or abbreviation (max 20 chars). Auto-filled from name if left blank."
                                   title="Short code help"></i>
                            </label>
                            <input type="text" class="form-control form-control-sm" id="statusVal" name="status_val" maxlength="20" placeholder="Auto from name">
                        </div>
                        <div class="col-md-2">
                            <label for="statusGroup" class="form-label form-label-sm">Group</label>
                            <input type="text" class="form-control form-control-sm" id="statusGroup" name="group" maxlength="20" placeholder="e.g. Active">
                        </div>
                        <div class="col-md-3">
                            <label for="statusIncidentAction" class="form-label form-label-sm">
                                Incident Action
                                <i class="bi bi-question-circle text-body-secondary" tabindex="0"
                                   data-bs-toggle="popover" data-bs-trigger="hover focus" data-bs-placement="top"
                                   data-bs-content="When a dispatcher picks this status on an incident assignment, stamp the matching assigns timestamp. Dispatched = on assign; Responding/On Scene/Clear = the obvious assigns column. None = just record the status, no timestamp."
                                   title="Incident action help"></i>
                            </label>
                            <select class="form-select form-select-sm" id="statusIncidentAction" name="incident_action">
                                <option value="">None (no timestamp)</option>
                                <option value="dispatched">Dispatched</option>
                                <option value="responding">Responding</option>
                                <option value="on_scene">On Scene</option>
                                <option value="clear">Clear</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="statusDispatchLevel" class="form-label form-label-sm">
                                Dispatch Level
                                <i class="bi bi-question-circle text-body-secondary" tabindex="0"
                                   data-bs-toggle="popover" data-bs-trigger="hover focus" data-bs-placement="top" data-bs-html="true"
                                   data-bs-content="Controls how a dispatcher can assign a unit while it is in this status:<br><br><b>Available</b> — green badge; assigned without warning.<br><br><b>Inform Only (warn)</b> — yellow badge with ⚠; dispatcher can still assign but sees a confirmation prompt (&quot;Engine 1 is currently Out of Service. Assign anyway?&quot;). Useful for &quot;available but reduced&quot; statuses like Returning, In Quarters, At Facility.<br><br><b>Unavailable</b> — red badge; assignment is blocked entirely. Useful for hard out-of-service states (Maintenance, Out of Service)."
                                   title="Dispatch level help"></i>
                            </label>
                            <select class="form-select form-select-sm" id="statusDispatchLevel" name="dispatch">
                                <option value="0">Available (can dispatch)</option>
                                <option value="1">Inform Only (warn)</option>
                                <option value="2">Unavailable (enforce ban)</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label for="statusSort" class="form-label form-label-sm">Sort Order</label>
                            <input type="number" class="form-control form-control-sm" id="statusSort" name="sort" value="0" min="0">
                        </div>
                        <div class="col-md-2">
                            <label for="statusBgColor" class="form-label form-label-sm">Background</label>
                            <div class="input-group input-group-sm">
                                <input type="color" class="form-control form-control-color" id="statusBgColorPicker" value="#198754" style="width:34px;">
                                <input type="text" class="form-control" id="statusBgColor" name="bg_color" maxlength="16" placeholder="#198754">
                            </div>
                        </div>
                        <div class="col-md-2">
                            <label for="statusTextColor" class="form-label form-label-sm">Text</label>
                            <div class="input-group input-group-sm">
                                <input type="color" class="form-control form-control-color" id="statusTextColorPicker" value="#ffffff" style="width:34px;">
                                <input type="text" class="form-control" id="statusTextColor" name="text_color" maxlength="16" placeholder="#ffffff">
                            </div>
                        </div>
                        <div class="col-md-1">
                            <label class="form-label form-label-sm">Preview</label>
                            <div id="statusPreview" class="rounded px-2 py-1 text-center small" style="background:#198754;color:#fff;">Sample</div>
                        </div>
                        <div class="col-md-2">
                            <div class="form-check form-switch small mt-4">
                                <input class="form-check-input" type="checkbox" id="statusWatch" name="watch" value="1">
                                <label class="form-check-label" for="statusWatch">
                                    On Watch
                                    <i class="bi bi-question-circle text-body-secondary" tabindex="0"
                                       data-bs-toggle="popover" data-bs-trigger="hover focus" data-bs-placement="top" data-bs-html="true"
                                       data-bs-content="<b>Legacy v3 flag — currently has no functional effect in NewUI v4.</b><br><br>In the legacy CAD this marked statuses that should be included in a default &quot;status watch list&quot; widget. NewUI hasn't reimplemented that widget; the column is preserved so v3 admins' configurations don't lose data on upgrade. Reserved for a future &quot;watch list&quot; widget. Safe to leave at its current value."
                                       title="On Watch help"></i>
                                </label>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="form-check form-switch small mt-4">
                                <input class="form-check-input" type="checkbox" id="statusHide" name="hide" value="y">
                                <label class="form-check-label" for="statusHide">
                                    Hidden
                                    <i class="bi bi-question-circle text-body-secondary" tabindex="0"
                                       data-bs-toggle="popover" data-bs-trigger="hover focus" data-bs-placement="top" data-bs-html="true"
                                       data-bs-content="When checked, this status is hidden from the status-picker buttons users see (dispatcher unit detail, mobile field view). It still appears here in the admin list + in historical records so the audit trail stays complete.<br><br>Useful for retiring a legacy status without losing its data — users can't set it anymore, but past entries still display correctly."
                                       title="Hidden help"></i>
                                </label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-check form-switch small mt-4">
                                <input class="form-check-input" type="checkbox" id="statusExclReset" name="excl_from_reset" value="y">
                                <label class="form-check-label" for="statusExclReset">
                                    Exclude from Reset
                                    <i class="bi bi-question-circle text-body-secondary" tabindex="0"
                                       data-bs-toggle="popover" data-bs-trigger="hover focus" data-bs-placement="top"
                                       data-bs-content="When checked, units in this status will NOT be reset to Available during a bulk status reset."
                                       title="Exclude from reset help"></i>
                                </label>
                            </div>
                        </div>
                        <!-- Phase 31 (2026-06-12) — resets_par flag. When a
                             unit enters a status with this checked, their
                             per-unit PAR cadence timer is reset to "now."
                             Defaults: any status whose Incident Action is
                             Dispatched / Responding / On Scene. -->
                        <div class="col-md-3">
                            <div class="form-check form-switch small mt-4">
                                <input class="form-check-input" type="checkbox" id="statusResetsPar" name="resets_par" value="1">
                                <label class="form-check-label" for="statusResetsPar">
                                    Resets PAR Timer
                                    <i class="bi bi-question-circle text-body-secondary" tabindex="0"
                                       data-bs-toggle="popover" data-bs-trigger="hover focus" data-bs-placement="top"
                                       data-bs-content="When a unit enters this status, their per-unit PAR cadence timer is reset to now. Default: enabled for statuses whose Incident Action is Dispatched, Responding, or On Scene."
                                       title="Resets PAR help"></i>
                                </label>
                            </div>
                        </div>
                        <!-- GH #20 round 2 (2026-07-07) — per-status bed-delivery
                             flag. Replaces the English name-pattern matching that
                             could never fit agency-specific status names. -->
                        <div class="col-md-3">
                            <div class="form-check form-switch small mt-4">
                                <input class="form-check-input" type="checkbox" id="statusBedDelivery" name="bed_delivery" value="1">
                                <label class="form-check-label" for="statusBedDelivery">
                                    Counts as Facility Delivery
                                    <i class="bi bi-question-circle text-body-secondary" tabindex="0"
                                       data-bs-toggle="popover" data-bs-trigger="hover focus" data-bs-placement="top"
                                       data-bs-content="When a unit with a receiving facility enters this status, facilities set to Automatic bed counts decrement Beds Available and increment Beds Occupied (once per assignment). Check this on your at-facility / patient-delivered status. When any status has this checked, only checked statuses trigger the automation."
                                       title="Facility delivery help"></i>
                                </label>
                            </div>
                        </div>
                        <!-- GH #68 round 2 (2026-07-08) — explicit filter bucket.
                             Replaces name-pattern guessing on the units page's
                             Available / In Service / Unavailable buttons. -->
                        <div class="col-md-3">
                            <label class="form-label form-label-sm mb-0 small" for="statusUnitsFilter">
                                Units Filter Bucket
                                <i class="bi bi-question-circle text-body-secondary" tabindex="0"
                                   data-bs-toggle="popover" data-bs-trigger="hover focus" data-bs-placement="top"
                                   data-bs-content="Which filter button on the Units page shows units in this status: Available, In Service, or Unavailable. Auto uses legacy name matching — set an explicit value for custom status names."
                                   title="Units filter help"></i>
                            </label>
                            <select class="form-select form-select-sm" id="statusUnitsFilter" name="units_filter">
                                <option value="">Auto (match by name)</option>
                                <option value="available">Available</option>
                                <option value="in_service">In Service</option>
                                <option value="unavailable">Unavailable</option>
                            </select>
                        </div>
                        <!-- GH #66 (2026-07-08) — hide_from_board flag. Units whose
                             CURRENT status has this set are filtered out of the
                             situation Units tab + dashboard units widget. Dispatch
                             pickers and roster pages are unaffected. -->
                        <div class="col-md-3">
                            <div class="form-check form-switch small mt-4">
                                <input class="form-check-input" type="checkbox" id="statusHideFromBoard" name="hide_from_board" value="1">
                                <label class="form-check-label" for="statusHideFromBoard">
                                    Hide from Boards
                                    <i class="bi bi-question-circle text-body-secondary" tabindex="0"
                                       data-bs-toggle="popover" data-bs-trigger="hover focus" data-bs-placement="top"
                                       data-bs-content="Units currently in this status are hidden from the situation screen's Units tab and the dashboard units widget — useful for off-shift statuses on large rosters. They still appear in dispatch pickers, the units page, and rosters."
                                       title="Hide from boards help"></i>
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- Phase 95 (2026-06-28) — admin UI for extra_data_* fields.
                         Backend save endpoint already accepts these (api/config-admin.php
                         statuses section); the GET path returns them. The schema
                         is in place + responder_set_status_internal honors them.
                         This admin form was the missing piece. -->
                    <hr class="my-3">
                    <div class="row g-2">
                        <div class="col-12">
                            <h6 class="small fw-semibold text-body-secondary mb-1">
                                <i class="bi bi-collection me-1"></i>Extra Data Prompt
                                <i class="bi bi-question-circle text-body-secondary" tabindex="0"
                                   data-bs-toggle="popover" data-bs-trigger="hover focus" data-bs-placement="top"
                                   data-bs-html="true"
                                   data-bs-content="When a dispatcher (or field unit) picks this status, optionally prompt them for additional information and route it to the right place.<br><br><b>Examples:</b><br>&bull; <i>Transporting</i> → prompt for destination Facility → stamp on incident<br>&bull; <i>Responding</i> → prompt for starting Mileage → write to unit + action log<br>&bull; <i>Maintenance</i> → require a Note → write to unit detail<br>&bull; <i>On Scene</i> → optional Location override → action log<br><br>The prompt UI is rendered in the status-change modal automatically based on the configured Type."
                                   title="Extra data help"></i>
                            </h6>
                        </div>
                        <div class="col-md-3">
                            <label for="statusExtraDataType" class="form-label form-label-sm">Type</label>
                            <select class="form-select form-select-sm" id="statusExtraDataType" name="extra_data_type">
                                <option value="none">None — no prompt</option>
                                <option value="facility">Facility (dropdown)</option>
                                <option value="mileage">Mileage (number, miles)</option>
                                <option value="location">Location (lat/lng)</option>
                                <option value="note">Note (freetext)</option>
                                <option value="numeric">Numeric (any number)</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="statusExtraDataTarget" class="form-label form-label-sm">
                                Send To
                                <i class="bi bi-question-circle text-body-secondary" tabindex="0"
                                   data-bs-toggle="popover" data-bs-trigger="hover focus" data-bs-placement="top"
                                   data-bs-html="true"
                                   data-bs-content="<b>Action Log</b> — the value is appended to the incident's action-log entry so it appears in the incident detail's activity feed and any exported ICS-214. Best default; visible where the status change is meaningful.<br><br><b>Incident record</b> — writes to a per-type column on the ticket row (facility → ticket.rec_facility, mileage → ticket.mileage, etc.). Use when you want the value to be part of the incident's structured data, not just its log.<br><br><b>Unit record</b> — writes to a per-type column on the responder row (mileage → responder.mileage_last, note → responder.status_about, etc.). See the Unit History Log (Personnel &gt; Unit History) to review every write over time."
                                   title="Where the value ends up"></i>
                            </label>
                            <select class="form-select form-select-sm" id="statusExtraDataTarget" name="extra_data_target">
                                <option value="action_log">Action Log (default — shown in incident log)</option>
                                <option value="incident">Incident record (stored on the ticket)</option>
                                <option value="unit">Unit record (stored on the responder — see Unit History)</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label for="statusExtraDataLabel" class="form-label form-label-sm">Prompt Label</label>
                            <input type="text" class="form-control form-control-sm"
                                   id="statusExtraDataLabel" name="extra_data_label" maxlength="64"
                                   placeholder="e.g. Destination Facility">
                            <div class="form-text">Shown to the user as the prompt question.</div>
                        </div>
                        <div class="col-md-2">
                            <div class="form-check form-switch small mt-4">
                                <input class="form-check-input" type="checkbox"
                                       id="statusExtraDataRequired" name="extra_data_required" value="1">
                                <label class="form-check-label" for="statusExtraDataRequired">
                                    Required
                                </label>
                                <div class="form-text">Block save without a value.</div>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex gap-2 mt-3">
                        <button type="submit" class="btn btn-sm btn-success"><i class="bi bi-check-lg me-1"></i>Save</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="btnCancelStatus"><i class="bi bi-x-lg me-1"></i>Cancel</button>
                        <button type="button" class="btn btn-sm btn-outline-danger ms-auto d-none" id="btnDeleteStatus"><i class="bi bi-trash me-1"></i>Delete</button>
                    </div>
                </form>
            </div>

            <div class="config-toolbar">
                <input type="text" class="form-control form-control-sm" id="statusSearch" placeholder="Search statuses...">
                <select class="form-select form-select-sm" id="statusGroupFilter" style="max-width:160px;">
                    <option value="">All Groups</option>
                </select>
                <button class="btn btn-sm btn-success ms-auto" id="btnAddStatus"><i class="bi bi-plus-lg me-1"></i>Add Status</button>
            </div>

            <div class="table-responsive">
                <table class="table table-sm table-hover config-table">
                    <thead>
                        <tr>
                            <th style="width:40px;">ID</th>
                            <th>Status</th>
                            <th>Code</th>
                            <th>Group</th>
                            <th style="width:50px;">Sort</th>
                            <th>Dispatch</th>
                            <th>Watch</th>
                            <th>Hidden</th>
                            <th>Excl Reset</th>
                        </tr>
                    </thead>
                    <tbody id="statusesTableBody"></tbody>
                </table>
            </div>
            <div class="config-status-bar" id="statusesStatus">Loading...</div>
        </div>

        <!-- ── Severity Levels ──────────────────────────────────────── -->
        <div class="config-panel" id="panel-severity-levels">
            <div class="config-panel-title">
                <i class="bi bi-exclamation-triangle text-warning"></i> Severity Levels
            </div>
            <form id="severityForm">
                <p class="text-body-secondary small mb-3">Configure the color coding for each severity level. These colors are used throughout the system on incident badges, map markers, and dashboard widgets.</p>
                <div class="row g-3">
                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-body p-3">
                                <h6 class="card-title small fw-bold">Normal (Severity 0)</h6>
                                <label for="sevColor0" class="form-label form-label-sm">Color</label>
                                <div class="input-group input-group-sm">
                                    <input type="color" class="form-control form-control-color" id="sevColor0Picker" value="#00ff00" style="width:38px;">
                                    <input type="text" class="form-control" id="sevColor0" data-key="sev_0_color" maxlength="7" placeholder="#00ff00">
                                </div>
                                <label for="sevLabel0" class="form-label form-label-sm mt-2">Label</label>
                                <input type="text" class="form-control form-control-sm" id="sevLabel0" data-key="sev_0_label" maxlength="30" placeholder="Normal">
                                <div class="mt-2">
                                    <span class="badge rounded-pill px-3 py-2" id="sevPreview0" style="background:#00ff00;color:#000;">Normal</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-body p-3">
                                <h6 class="card-title small fw-bold">Elevated (Severity 1)</h6>
                                <label for="sevColor1" class="form-label form-label-sm">Color</label>
                                <div class="input-group input-group-sm">
                                    <input type="color" class="form-control form-control-color" id="sevColor1Picker" value="#ffff00" style="width:38px;">
                                    <input type="text" class="form-control" id="sevColor1" data-key="sev_1_color" maxlength="7" placeholder="#ffff00">
                                </div>
                                <label for="sevLabel1" class="form-label form-label-sm mt-2">Label</label>
                                <input type="text" class="form-control form-control-sm" id="sevLabel1" data-key="sev_1_label" maxlength="30" placeholder="Elevated">
                                <div class="mt-2">
                                    <span class="badge rounded-pill px-3 py-2" id="sevPreview1" style="background:#ffff00;color:#000;">Elevated</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-body p-3">
                                <h6 class="card-title small fw-bold">Critical (Severity 2)</h6>
                                <label for="sevColor2" class="form-label form-label-sm">Color</label>
                                <div class="input-group input-group-sm">
                                    <input type="color" class="form-control form-control-color" id="sevColor2Picker" value="#ff0000" style="width:38px;">
                                    <input type="text" class="form-control" id="sevColor2" data-key="sev_2_color" maxlength="7" placeholder="#ff0000">
                                </div>
                                <label for="sevLabel2" class="form-label form-label-sm mt-2">Label</label>
                                <input type="text" class="form-control form-control-sm" id="sevLabel2" data-key="sev_2_label" maxlength="30" placeholder="Critical">
                                <div class="mt-2">
                                    <span class="badge rounded-pill px-3 py-2" id="sevPreview2" style="background:#ff0000;color:#fff;">Critical</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="d-flex gap-2 mt-3">
                    <button type="submit" class="btn btn-sm btn-success"><i class="bi bi-check-lg me-1"></i>Save Severity Settings</button>
                </div>
            </form>
        </div>

        <!-- ── Display Settings ────────────────────────────────────── -->
        <div class="config-panel" id="panel-display-settings">
            <div class="config-panel-title">
                <i class="bi bi-display text-primary"></i> Display Settings
            </div>
            <form id="displaySettingsForm">
                <!-- 2026-06-11 — agency timezone. Stored in
                     settings.area_timezone and applied at every
                     request via config.php. Affects what timezone
                     all displayed times appear in AND the timezone
                     PHP stamps datetimes into the DB as. -->
                <div class="settings-group">
                    <div class="settings-group-title">Time</div>
                    <div class="row g-2 align-items-end">
                        <div class="col-md-6">
                            <label for="setAreaTimezone" class="form-label form-label-sm">
                                Agency Time Zone
                                <i class="bi bi-question-circle text-body-secondary" tabindex="0"
                                   data-bs-toggle="popover" data-bs-trigger="hover focus" data-bs-placement="top"
                                   data-bs-content="The IANA timezone all server-side timestamps use. Displayed dates, the recently-closed filter, log timestamps, and database stamps all run in this zone. Changing it does NOT shift historical data; existing stored datetimes display verbatim as wall-clock strings in the new zone."
                                   title="Timezone help"></i>
                            </label>
                            <select class="form-select form-select-sm" id="setAreaTimezone" data-key="area_timezone">
                                <?php
                                $allZones = DateTimeZone::listIdentifiers();
                                foreach ($allZones as $z) {
                                    echo '<option value="' . htmlspecialchars($z, ENT_QUOTES) . '">' .
                                         htmlspecialchars($z, ENT_QUOTES) . '</option>';
                                }
                                ?>
                            </select>
                            <div class="form-text small">
                                Common US: <code>America/New_York</code>, <code>America/Chicago</code>,
                                <code>America/Denver</code>, <code>America/Los_Angeles</code>,
                                <code>America/Phoenix</code> (no DST), <code>America/Anchorage</code>,
                                <code>Pacific/Honolulu</code>.
                                Server PHP currently runs as:
                                <code><?php echo e(date_default_timezone_get()); ?> (<?php echo e(date('P')); ?>)</code>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="settings-group">
                    <div class="settings-group-title">Dashboard</div>
                    <div class="row g-2">
                        <div class="col-md-3">
                            <label for="setPageSize" class="form-label form-label-sm">
                                Default Page Size
                                <i class="bi bi-question-circle text-body-secondary" tabindex="0"
                                   data-bs-toggle="popover" data-bs-trigger="hover focus" data-bs-placement="top"
                                   data-bs-content="Default number of rows to show per page on list screens. Enter any positive integer (e.g. 25, 50, 100). The Units list uses this now; more lists to follow."
                                   title="Page size help"></i>
                            </label>
                            <input type="number" class="form-control form-control-sm" id="setPageSize" data-key="page_size" min="1" step="1" placeholder="50" title="Rows per page — any positive integer">
                            <div class="form-text small">Any positive integer (default 50).</div>
                        </div>
                        <div class="col-md-3">
                            <label for="setRefreshRate" class="form-label form-label-sm">
                                Auto-Refresh (seconds)
                                <i class="bi bi-question-circle text-body-secondary" tabindex="0"
                                   data-bs-toggle="popover" data-bs-trigger="hover focus" data-bs-placement="top"
                                   data-bs-content="How often the dashboard polls the server for updates. Lower values are more responsive but use more bandwidth. 0 = manual refresh only."
                                   title="Refresh rate help"></i>
                            </label>
                            <input type="number" class="form-control form-control-sm" id="setRefreshRate" data-key="refresh_seconds" min="0" max="300" placeholder="30">
                        </div>
                        <div class="col-md-3">
                            <label for="setAbbrev" class="form-label form-label-sm">Abbreviation Mode</label>
                            <select class="form-select form-select-sm" id="setAbbrev" data-key="abbreviation_mode">
                                <option value="0">Full text</option>
                                <option value="1">Abbreviate long text</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="setSessionTimeout" class="form-label form-label-sm">
                                Default Timeout (min)
                                <i class="bi bi-question-circle text-body-secondary" tabindex="0"
                                   data-bs-toggle="popover" data-bs-trigger="hover focus" data-bs-placement="top"
                                   data-bs-content="Default session timeout in minutes. Can be overridden per role below. Set to 0 to disable."
                                   title="Session timeout help"></i>
                            </label>
                            <input type="number" class="form-control form-control-sm" id="setSessionTimeout" data-key="session_timeout_minutes" min="0" max="1440" placeholder="480">
                        </div>
                    </div>
                </div>

                <div class="settings-group">
                    <div class="settings-group-title">Appearance</div>
                    <div class="row g-2">
                        <div class="col-md-3">
                            <label for="setDefaultTheme" class="form-label form-label-sm">Default Theme</label>
                            <select class="form-select form-select-sm" id="setDefaultTheme" data-key="default_theme">
                                <option value="Day">Day (Light)</option>
                                <option value="Night">Night (Dark)</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="setCompactMode" class="form-label form-label-sm">Table Density</label>
                            <select class="form-select form-select-sm" id="setCompactMode" data-key="compact_tables">
                                <option value="0">Normal</option>
                                <option value="1">Compact</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="setLoginBanner" class="form-label form-label-sm">Login Banner Text</label>
                            <input type="text" class="form-control form-control-sm" id="setLoginBanner" data-key="login_banner" maxlength="500" placeholder="Authorized users only. All activity is logged.">
                        </div>
                    </div>
                    <!-- GH #55 (Eric 2026-07-04) — shared Push-to-Talk button
                         color for the Zello + DMR Radio consoles. -->
                    <div class="row g-2 mt-1">
                        <div class="col-md-4">
                            <label for="setPttColor" class="form-label form-label-sm">PTT Button Color</label>
                            <input type="color" class="form-control form-control-color form-control-sm" id="setPttColor" data-key="ptt_button_color" value="#dc3545" title="Push-to-Talk button color">
                            <div class="form-text">Idle color of the Push-to-Talk button on the Zello and DMR Radio consoles. Default red (#dc3545).</div>
                        </div>
                    </div>
                </div>

                <!-- Per-Role Session Timeouts moved to Login Settings in Phase 37
                     (panel-login-settings, "Per-Role Session Timeouts" section).
                     Data now lives on roles.session_timeout_minutes, not on
                     hard-coded `timeout_role_*` settings rows. -->
                <div class="alert alert-info py-2 small mb-2">
                    <i class="bi bi-info-circle me-1"></i>
                    Per-Role Session Timeouts moved to
                    <a href="#login-settings" data-tab-link="login-settings" class="alert-link">Login Settings</a>
                    and now read live from the Roles table.
                </div>

                <div class="d-flex gap-2 mt-3">
                    <button type="submit" class="btn btn-sm btn-success"><i class="bi bi-check-lg me-1"></i>Save Display Settings</button>
                </div>
            </form>

            <hr class="my-3">
            <h6 class="mt-3"><i class="bi bi-layout-sidebar me-1"></i>Navigation</h6>
            <p class="text-body-secondary small mb-2">These preferences are saved per-browser (localStorage), not per-account.</p>
            <div class="row g-2 mb-3">
                <div class="col-md-6">
                    <label for="navFadeDelay" class="form-label form-label-sm">
                        Menu label fade delay
                        <i class="bi bi-question-circle text-body-secondary" tabindex="0"
                           data-bs-toggle="popover" data-bs-trigger="hover focus" data-bs-placement="top"
                           data-bs-content="How long the text labels on navbar buttons stay visible after the mouse leaves. Set to 'Always show' to keep labels visible at all times."
                           title="Fade delay help"></i>
                    </label>
                    <select class="form-select form-select-sm" id="navFadeDelay">
                        <option value="0">Always show labels</option>
                        <option value="3000">3 seconds</option>
                        <option value="5000">5 seconds</option>
                        <option value="10000" selected>10 seconds (default)</option>
                        <option value="15000">15 seconds</option>
                        <option value="30000">30 seconds</option>
                    </select>
                </div>
            </div>

            <h6 class="mt-3"><i class="bi bi-map me-1"></i>Map Basemap</h6>
            <p class="text-body-secondary small mb-2">Choose which basemap loads by default for each theme. You can still switch basemaps from the layer control on any map.</p>
            <div class="row g-2 mb-3">
                <div class="col-md-6">
                    <label for="basemapLight" class="form-label form-label-sm">Light theme basemap</label>
                    <select class="form-select form-select-sm" id="basemapLight">
                        <option value="street" selected>Street Map (OSM)</option>
                        <option value="dark">Dark (CartoDB)</option>
                        <option value="terrain">Terrain (OpenTopo)</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label for="basemapDark" class="form-label form-label-sm">Dark theme basemap</label>
                    <select class="form-select form-select-sm" id="basemapDark">
                        <option value="street">Street Map (OSM)</option>
                        <option value="dark" selected>Dark (CartoDB)</option>
                        <option value="terrain">Terrain (OpenTopo)</option>
                    </select>
                </div>
            </div>
            <button class="btn btn-sm btn-success" id="btnSaveDisplayPrefs"><i class="bi bi-check-lg me-1"></i>Save Display Preferences</button>
        </div>

        <!-- ── Facility Types ───────────────────────────────────────── -->
        <div class="config-panel" id="panel-facility-types">
            <div class="config-panel-title">
                <i class="bi bi-hospital text-info"></i> Facility Types
            </div>

            <div class="config-edit-panel" id="facTypeEditPanel">
                <form id="facTypeForm">
                    <input type="hidden" id="facTypeId" name="id" value="">
                    <div class="row g-2">
                        <div class="col-md-3">
                            <label for="facTypeName" class="form-label form-label-sm">Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control form-control-sm" id="facTypeName" name="name" required maxlength="48">
                        </div>
                        <div class="col-md-6">
                            <label for="facTypeDesc" class="form-label form-label-sm">Description</label>
                            <input type="text" class="form-control form-control-sm" id="facTypeDesc" name="description" maxlength="96">
                        </div>
                        <div class="col-md-3">
                            <label for="facTypeIcon" class="form-label form-label-sm">
                                Map Icon
                                <i class="bi bi-question-circle text-body-secondary" tabindex="0"
                                   data-bs-toggle="popover" data-bs-trigger="hover focus" data-bs-placement="top"
                                   data-bs-content="The glyph shown for this facility type on map markers and popups. Pick from the list — the preview shows the icon."
                                   title="Icon help"></i>
                            </label>
                            <div class="d-flex align-items-center gap-2">
                                <select class="form-select form-select-sm" id="facTypeIcon" name="icon" data-type-icon-picker></select>
                                <i class="bi bi-geo-alt-fill fs-4 align-middle" data-type-icon-preview-for="facTypeIcon"></i>
                            </div>
                        </div>
                    </div>
                    <div class="d-flex gap-2 mt-3">
                        <button type="submit" class="btn btn-sm btn-success"><i class="bi bi-check-lg me-1"></i>Save</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="btnCancelFacType"><i class="bi bi-x-lg me-1"></i>Cancel</button>
                        <button type="button" class="btn btn-sm btn-outline-danger ms-auto d-none" id="btnDeleteFacType"><i class="bi bi-trash me-1"></i>Delete</button>
                    </div>
                </form>
            </div>

            <div class="config-toolbar">
                <button class="btn btn-sm btn-success ms-auto" id="btnAddFacType"><i class="bi bi-plus-lg me-1"></i>Add Facility Type</button>
            </div>

            <div class="table-responsive">
                <table class="table table-sm table-hover config-table">
                    <thead>
                        <tr>
                            <th style="width:50px;">ID</th>
                            <th>Name</th>
                            <th>Description</th>
                            <th style="width:60px;">Icon</th>
                        </tr>
                    </thead>
                    <tbody id="facTypesTableBody"></tbody>
                </table>
            </div>
            <div class="config-status-bar" id="facTypesStatus">Loading...</div>
        </div>

        <!-- ── Unit Types (GH #61) ─────────────────────────────────── -->
        <div class="config-panel" id="panel-unit-types">
            <div class="config-panel-title">
                <i class="bi bi-truck text-info"></i> Unit Types
            </div>
            <p class="text-body-secondary small mb-3">
                The unit "type" offered on the Unit Edit page (e.g. Engine, Ambulance, Command, Patrol).
            </p>

            <div class="config-edit-panel" id="unitTypeEditPanel">
                <form id="unitTypeForm">
                    <input type="hidden" id="unitTypeId" name="id" value="">
                    <div class="row g-2">
                        <div class="col-md-3">
                            <label for="unitTypeName" class="form-label form-label-sm">Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control form-control-sm" id="unitTypeName" name="name" required maxlength="32">
                        </div>
                        <div class="col-md-6">
                            <label for="unitTypeDesc" class="form-label form-label-sm">Description</label>
                            <input type="text" class="form-control form-control-sm" id="unitTypeDesc" name="description" maxlength="48">
                        </div>
                        <div class="col-md-3">
                            <label for="unitTypeIcon" class="form-label form-label-sm">
                                Map Icon
                                <i class="bi bi-question-circle text-body-secondary" tabindex="0"
                                   data-bs-toggle="popover" data-bs-trigger="hover focus" data-bs-placement="top"
                                   data-bs-content="The glyph shown for this unit type on map markers and popups. Pick from the list — the preview shows the icon."
                                   title="Icon help"></i>
                            </label>
                            <div class="d-flex align-items-center gap-2">
                                <select class="form-select form-select-sm" id="unitTypeIcon" name="icon" data-type-icon-picker></select>
                                <i class="bi bi-geo-alt-fill fs-4 align-middle" data-type-icon-preview-for="unitTypeIcon"></i>
                            </div>
                        </div>
                    </div>
                    <div class="d-flex gap-2 mt-3">
                        <button type="submit" class="btn btn-sm btn-success"><i class="bi bi-check-lg me-1"></i>Save</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="btnCancelUnitType"><i class="bi bi-x-lg me-1"></i>Cancel</button>
                        <button type="button" class="btn btn-sm btn-outline-danger ms-auto d-none" id="btnDeleteUnitType"><i class="bi bi-trash me-1"></i>Delete</button>
                    </div>
                </form>
            </div>

            <div class="config-toolbar">
                <button class="btn btn-sm btn-success ms-auto" id="btnAddUnitType"><i class="bi bi-plus-lg me-1"></i>Add Unit Type</button>
            </div>

            <div class="table-responsive">
                <table class="table table-sm table-hover config-table">
                    <thead>
                        <tr>
                            <th style="width:50px;">ID</th>
                            <th>Name</th>
                            <th>Description</th>
                            <th style="width:60px;">Icon</th>
                        </tr>
                    </thead>
                    <tbody id="unitTypesTableBody"></tbody>
                </table>
            </div>
            <div class="config-status-bar" id="unitTypesStatus">Loading...</div>
        </div>

        <!-- ── Facility Statuses (Issue #29 followup) ─────────────── -->
        <div class="config-panel" id="panel-facility-statuses">
            <div class="config-panel-title">
                <i class="bi bi-hospital text-success"></i> Facility Statuses
            </div>
            <p class="text-body-secondary small mb-3">
                Status values shown on the facility-edit page (e.g., Open, Standby, Full, Closed).
                Each status can mark the facility as generally available or unavailable.
            </p>
            <div class="card border-0 bg-body-tertiary mb-3">
                <div class="card-body py-2">
                    <div class="row g-2 align-items-end">
                        <div class="col-md-2">
                            <label class="form-label form-label-sm mb-0">Status</label>
                            <input type="text" class="form-control form-control-sm" id="facStatusVal" maxlength="20" placeholder="e.g. Open">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label form-label-sm mb-0">Description</label>
                            <input type="text" class="form-control form-control-sm" id="facStatusDesc" maxlength="60">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label form-label-sm mb-0">Group</label>
                            <input type="text" class="form-control form-control-sm" id="facStatusGroup" maxlength="20" placeholder="Availability">
                        </div>
                        <div class="col-md-1">
                            <label class="form-label form-label-sm mb-0">Sort</label>
                            <input type="number" class="form-control form-control-sm" id="facStatusSort" value="0" min="0">
                        </div>
                        <div class="col-md-1">
                            <label class="form-label form-label-sm mb-0">BG</label>
                            <input type="color" class="form-control form-control-color form-control-sm" id="facStatusBg" value="#198754">
                        </div>
                        <div class="col-md-1">
                            <label class="form-label form-label-sm mb-0">Text</label>
                            <input type="color" class="form-control form-control-color form-control-sm" id="facStatusText" value="#ffffff">
                        </div>
                        <div class="col-md-auto">
                            <button type="button" class="btn btn-sm btn-primary" id="btnAddFacStatus">
                                <i class="bi bi-plus-lg me-1"></i>Add
                            </button>
                        </div>
                    </div>
                    <div class="row g-2 mt-1">
                        <div class="col-md-3">
                            <div class="form-check form-check-sm">
                                <input class="form-check-input" type="checkbox" id="facStatusAvail" checked>
                                <label class="form-check-label small" for="facStatusAvail">Marks facility as available</label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-check form-check-sm">
                                <input class="form-check-input" type="checkbox" id="facStatusUnavail">
                                <label class="form-check-label small" for="facStatusUnavail">Marks facility as unavailable</label>
                            </div>
                        </div>
                    </div>
                    <input type="hidden" id="facStatusEditId" value="0">
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th class="ps-3" style="width:140px;">Preview</th>
                            <th>Status</th>
                            <th>Description</th>
                            <th>Group</th>
                            <th class="text-center" style="width:60px;">Sort</th>
                            <th class="text-center" style="width:80px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="facStatusesBody">
                        <tr><td colspan="6" class="text-center text-body-secondary py-3">Loading...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ── Sound / Alerts ───────────────────────────────────────── -->
        <div class="config-panel" id="panel-sound-alerts">
            <div class="config-panel-title">
                <i class="bi bi-volume-up text-danger"></i> Sound / Alerts
            </div>
            <p class="text-body-secondary small mb-3">
                Audio alerts are generated using Web Audio API tones &mdash; no sound files needed.
                Settings are stored locally in this browser.
            </p>
            <form id="soundAlertsForm">
                <!-- Master Controls -->
                <div class="settings-group">
                    <div class="settings-group-title">Master Controls</div>
                    <div class="row g-2 align-items-center">
                        <div class="col-md-3">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="saEnabled" value="1">
                                <label class="form-check-label fw-semibold" for="saEnabled">Enable Audio Alerts</label>
                            </div>
                        </div>
                        <div class="col-md-5">
                            <label for="saVolume" class="form-label form-label-sm mb-0">Master Volume</label>
                            <div class="d-flex align-items-center gap-2">
                                <i class="bi bi-volume-down text-body-secondary"></i>
                                <input type="range" class="form-range" id="saVolume" min="0" max="100" value="70" style="max-width:220px">
                                <i class="bi bi-volume-up text-body-secondary"></i>
                                <span class="small text-body-secondary" id="saVolumeLabel" style="min-width:36px">70%</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Per-Event Toggles -->
                <div class="settings-group">
                    <div class="settings-group-title">Event Alerts</div>
                    <table class="table table-sm table-hover mb-0 align-middle">
                        <thead>
                            <tr>
                                <th style="width:40%">Event</th>
                                <th style="width:30%">Description</th>
                                <th class="text-center" style="width:15%">Enabled</th>
                                <th class="text-center" style="width:15%">Preview</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><i class="bi bi-exclamation-triangle text-warning me-1"></i> New Incident</td>
                                <td class="text-body-secondary small">3 ascending beeps</td>
                                <td class="text-center">
                                    <div class="form-check form-switch d-inline-block mb-0">
                                        <input class="form-check-input" type="checkbox" id="saNewIncident" value="1">
                                        <label class="form-check-label visually-hidden" for="saNewIncident">Toggle</label>
                                    </div>
                                </td>
                                <td class="text-center">
                                    <button type="button" class="btn btn-sm btn-outline-info sa-test-btn" data-tone="newIncident" title="Test sound"><i class="bi bi-play-fill"></i></button>
                                </td>
                            </tr>
                            <tr>
                                <td><i class="bi bi-exclamation-octagon text-danger me-1"></i> High Severity</td>
                                <td class="text-body-secondary small">Rapid alternating alarm</td>
                                <td class="text-center">
                                    <div class="form-check form-switch d-inline-block mb-0">
                                        <input class="form-check-input" type="checkbox" id="saHighSeverity" value="1">
                                        <label class="form-check-label visually-hidden" for="saHighSeverity">Toggle</label>
                                    </div>
                                </td>
                                <td class="text-center">
                                    <button type="button" class="btn btn-sm btn-outline-info sa-test-btn" data-tone="highSeverity" title="Test sound"><i class="bi bi-play-fill"></i></button>
                                </td>
                            </tr>
                            <tr>
                                <td><i class="bi bi-person-check text-success me-1"></i> Unit Assigned</td>
                                <td class="text-body-secondary small">Single short beep</td>
                                <td class="text-center">
                                    <div class="form-check form-switch d-inline-block mb-0">
                                        <input class="form-check-input" type="checkbox" id="saUnitAssigned" value="1">
                                        <label class="form-check-label visually-hidden" for="saUnitAssigned">Toggle</label>
                                    </div>
                                </td>
                                <td class="text-center">
                                    <button type="button" class="btn btn-sm btn-outline-info sa-test-btn" data-tone="unitAssigned" title="Test sound"><i class="bi bi-play-fill"></i></button>
                                </td>
                            </tr>
                            <tr>
                                <td><i class="bi bi-chat-dots text-primary me-1"></i> Chat Message</td>
                                <td class="text-body-secondary small">Soft double-beep</td>
                                <td class="text-center">
                                    <div class="form-check form-switch d-inline-block mb-0">
                                        <input class="form-check-input" type="checkbox" id="saChatMessage" value="1">
                                        <label class="form-check-label visually-hidden" for="saChatMessage">Toggle</label>
                                    </div>
                                </td>
                                <td class="text-center">
                                    <button type="button" class="btn btn-sm btn-outline-info sa-test-btn" data-tone="chatMessage" title="Test sound"><i class="bi bi-play-fill"></i></button>
                                </td>
                            </tr>
                            <tr>
                                <td><i class="bi bi-arrow-repeat text-info me-1"></i> Status Change</td>
                                <td class="text-body-secondary small">Low tone</td>
                                <td class="text-center">
                                    <div class="form-check form-switch d-inline-block mb-0">
                                        <input class="form-check-input" type="checkbox" id="saStatusChange" value="1">
                                        <label class="form-check-label visually-hidden" for="saStatusChange">Toggle</label>
                                    </div>
                                </td>
                                <td class="text-center">
                                    <button type="button" class="btn btn-sm btn-outline-info sa-test-btn" data-tone="statusChange" title="Test sound"><i class="bi bi-play-fill"></i></button>
                                </td>
                            </tr>
                            <!-- Phase 28B (2026-06-12) — PAR overdue alarm.
                                 Plays every 30s while ANY incident has its
                                 next PAR cycle past due. Disabling here
                                 mutes the global cross-page alarm. -->
                            <tr>
                                <td><i class="bi bi-shield-exclamation text-danger me-1"></i> PAR Overdue</td>
                                <td class="text-body-secondary small">Urgent two-tone siren</td>
                                <td class="text-center">
                                    <div class="form-check form-switch d-inline-block mb-0">
                                        <input class="form-check-input" type="checkbox" id="saParOverdue" value="1">
                                        <label class="form-check-label visually-hidden" for="saParOverdue">Toggle</label>
                                    </div>
                                </td>
                                <td class="text-center">
                                    <button type="button" class="btn btn-sm btn-outline-info sa-test-btn" data-tone="parOverdue" title="Test sound"><i class="bi bi-play-fill"></i></button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="d-flex gap-2 mt-3">
                    <button type="submit" class="btn btn-sm btn-success"><i class="bi bi-check-lg me-1"></i>Save Sound Settings</button>
                </div>
            </form>

            <!-- Phase 41 — Custom tone composer + per-event overrides. -->
            <div class="settings-group mt-4">
                <div class="settings-group-title d-flex justify-content-between align-items-center">
                    <span>Custom Tones (server-side, shared across users)</span>
                    <button type="button" class="btn btn-sm btn-outline-success" id="btnNewCustomTone">
                        <i class="bi bi-plus-lg me-1"></i>Compose New Tone
                    </button>
                </div>
                <p class="text-body-secondary small mb-2">
                    Compose your own multi-step Web&nbsp;Audio tones, then assign them to event slots
                    so the dashboard plays your custom siren instead of the built-in one. Tones live in
                    site settings, so every dispatcher hears the same alert without per-browser setup.
                </p>
                <div id="customTonesList"></div>
            </div>

            <div class="settings-group">
                <div class="settings-group-title">Per-Event Tone Override</div>
                <p class="text-body-secondary small mb-2">
                    Pick a tone (built-in or any custom one above) for each event. Clearing the override
                    falls back to the built-in tone.
                </p>
                <table class="table table-sm table-hover mb-0 align-middle">
                    <thead>
                        <tr>
                            <th style="width:40%">Event</th>
                            <th style="width:45%">Tone</th>
                            <th class="text-center" style="width:15%">Preview / Clear</th>
                        </tr>
                    </thead>
                    <tbody id="toneOverrideBody"><!-- populated by JS --></tbody>
                </table>
            </div>

            <!-- Composer modal -->
            <div class="modal fade" id="customToneModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-dialog-scrollable modal-lg">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title"><i class="bi bi-music-note-beamed me-1"></i>Custom Tone Composer</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="row g-2 mb-3">
                                <div class="col-md-5">
                                    <label class="form-label form-label-sm mb-0">Tone Name <span class="text-body-secondary">(a-z, 0-9, _, -)</span></label>
                                    <input type="text" class="form-control form-control-sm" id="ctName" maxlength="32" placeholder="e.g. mayday-siren">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label form-label-sm mb-0">Wave Type</label>
                                    <select class="form-select form-select-sm" id="ctType">
                                        <option value="sine">Sine (smooth)</option>
                                        <option value="square">Square (siren)</option>
                                        <option value="triangle">Triangle (soft)</option>
                                        <option value="sawtooth">Sawtooth (rasp)</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label form-label-sm mb-0">Gap Between Notes (ms)</label>
                                    <input type="number" class="form-control form-control-sm" id="ctGap" min="0" max="1000" value="40">
                                </div>
                            </div>
                            <table class="table table-sm align-middle">
                                <thead>
                                    <tr>
                                        <th style="width:5%">#</th>
                                        <th>Frequency (Hz)</th>
                                        <th>Duration (ms)</th>
                                        <th class="text-end">Action</th>
                                    </tr>
                                </thead>
                                <tbody id="ctNotesBody"></tbody>
                            </table>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="ctAddNote">
                                <i class="bi bi-plus-lg me-1"></i>Add Note
                            </button>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-sm btn-outline-info" id="ctPreview">
                                <i class="bi bi-play-fill me-1"></i>Preview
                            </button>
                            <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="button" class="btn btn-sm btn-success" id="ctSave">
                                <i class="bi bi-check-lg me-1"></i>Save Tone
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <!-- Sound / Alerts panel JS (inline, ES5, deferred until scripts load) -->
        <script>
        document.addEventListener('DOMContentLoaded', function () {
            'use strict';

            if (typeof AudioAlerts === 'undefined') return;

            var form     = document.getElementById('soundAlertsForm');
            var elEnable = document.getElementById('saEnabled');
            var elVolume = document.getElementById('saVolume');
            var elVolLbl = document.getElementById('saVolumeLabel');
            var elNew    = document.getElementById('saNewIncident');
            var elHigh   = document.getElementById('saHighSeverity');
            var elUnit   = document.getElementById('saUnitAssigned');
            var elChat   = document.getElementById('saChatMessage');
            var elStatus = document.getElementById('saStatusChange');
            var elParOverdue = document.getElementById('saParOverdue');  // Phase 28B (2026-06-12)
            var statusEl = document.getElementById('soundAlertsStatus');

            if (!form || !elEnable) return;

            // Load current prefs into form
            function loadForm() {
                var p = AudioAlerts.getPrefs();
                elEnable.checked = !!p.enabled;
                elVolume.value   = p.volume;
                elVolLbl.textContent = p.volume + '%';
                elNew.checked    = !!p.newIncident;
                elHigh.checked   = !!p.highSeverity;
                elUnit.checked   = !!p.unitAssigned;
                elChat.checked   = !!p.chatMessage;
                elStatus.checked = !!p.statusChange;
                // Phase 28B (2026-06-12) — default ON for safety; an
                // overdue PAR alarm is the kind of thing you opt OUT
                // of explicitly, not the kind you opt in to.
                if (elParOverdue) elParOverdue.checked = p.parOverdue !== false;
            }

            loadForm();

            // Volume slider live feedback
            elVolume.addEventListener('input', function () {
                elVolLbl.textContent = elVolume.value + '%';
            });

            // Test buttons
            var testBtns = document.querySelectorAll('.sa-test-btn');
            for (var i = 0; i < testBtns.length; i++) {
                testBtns[i].addEventListener('click', function (e) {
                    e.preventDefault();
                    var tone = this.getAttribute('data-tone');
                    // Apply the current volume slider value before testing
                    AudioAlerts.setVolume(parseInt(elVolume.value, 10));
                    AudioAlerts.playTone(tone);
                });
            }

            // Save
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                AudioAlerts.setPrefs({
                    enabled:      elEnable.checked,
                    volume:       parseInt(elVolume.value, 10),
                    newIncident:  elNew.checked,
                    highSeverity: elHigh.checked,
                    unitAssigned: elUnit.checked,
                    chatMessage:  elChat.checked,
                    statusChange: elStatus.checked,
                    parOverdue:   elParOverdue ? elParOverdue.checked : true   // Phase 28B
                });
                if (statusEl) {
                    statusEl.textContent = 'Sound settings saved.';
                    setTimeout(function () { statusEl.textContent = ''; }, 3000);
                }
            });
        });
        </script>

        <!-- ── Incident Numbers ───────────────────────────────────── -->
        <div class="config-panel" id="panel-incident-numbers">
            <div class="config-panel-title">
                <i class="bi bi-hash text-primary"></i> Incident Numbers
            </div>
            <p class="text-body-secondary small mb-3">
                Each new incident gets a number rendered from this template.
                Mix static text with dynamic tokens (see the cheat-sheet below).
                The preview updates live as you type.
            </p>
            <form id="incidentNumbersForm">

                <!-- Phase 99o (Eric beta 2026-06-29) — admin-configurable
                     label so the same name is used everywhere ("Incident"
                     vs "Case" vs "Call" etc.). Default: "Incident". -->
                <div class="settings-group">
                    <div class="settings-group-title">Display label</div>
                    <div class="row g-2 align-items-end">
                        <div class="col-md-4">
                            <label for="incNumLabel" class="form-label form-label-sm">
                                Label
                                <i class="bi bi-question-circle text-body-secondary" tabindex="0"
                                   data-bs-toggle="popover" data-bs-trigger="hover focus" data-bs-placement="top"
                                   data-bs-content="Single word used as the prefix for rendered numbers — e.g., 'Incident', 'Case', 'Call', 'Ticket', 'Run'. Shows on the dashboard widget column header, the incident detail page title, and other user-facing places that pair the label with the number. Default: Incident."
                                   title="Label help"></i>
                            </label>
                            <input type="text" class="form-control form-control-sm" id="incNumLabel"
                                   placeholder="Incident" maxlength="32" autocomplete="off">
                        </div>
                        <div class="col-md-8 text-body-secondary small">
                            Examples: <span class="font-monospace">Incident 26-0062</span>,
                            <span class="font-monospace">Case 26-0062</span>,
                            <span class="font-monospace">Call 26-0062</span>.
                        </div>
                    </div>
                </div>

                <!-- Template input -->
                <div class="settings-group">
                    <div class="settings-group-title">Template</div>
                    <div class="row g-2 align-items-end">
                        <div class="col-md-8">
                            <label for="incNumTemplate" class="form-label form-label-sm">
                                Template string
                            </label>
                            <input type="text" class="form-control font-monospace" id="incNumTemplate"
                                   placeholder="{YY}-{NNNN}"
                                   maxlength="64"
                                   spellcheck="false"
                                   autocomplete="off">
                            <div class="form-text" id="incNumValidationMsg"></div>
                        </div>
                        <div class="col-md-2">
                            <label for="incNumNext" class="form-label form-label-sm">
                                Next sequence
                            </label>
                            <input type="number" class="form-control form-control-sm"
                                   id="incNumNext" min="1" value="1">
                        </div>
                        <div class="col-md-2">
                            <label for="incNumResetMode" class="form-label form-label-sm">
                                Reset sequence
                                <i class="bi bi-question-circle text-body-secondary" tabindex="0"
                                   data-bs-toggle="popover" data-bs-trigger="hover focus" data-bs-placement="top"
                                   data-bs-content="When should the counter reset to 1? Yearly is the fire/EMS convention — the first incident of 2027 becomes #0001 rather than continuing from 2026."
                                   title="Reset mode help"></i>
                            </label>
                            <select class="form-select form-select-sm" id="incNumResetMode">
                                <option value="never">Never</option>
                                <option value="yearly" selected>Yearly</option>
                                <option value="monthly">Monthly</option>
                                <option value="daily">Daily</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-text small" id="incNumResetHint"></div>
                    <div class="alert alert-warning small mt-2 d-none" id="incNumCollisionWarning" role="alert">
                        <i class="bi bi-exclamation-triangle-fill me-1"></i>
                        <span id="incNumCollisionMsg"></span>
                    </div>
                </div>

                <!-- Live preview -->
                <div class="settings-group">
                    <div class="settings-group-title">Preview</div>
                    <div class="d-flex align-items-center gap-2 mb-1">
                        <span class="text-body-secondary small">Next incident:</span>
                        <span class="badge bg-primary fs-6 font-monospace" id="incNumPreview">&mdash;</span>
                    </div>
                    <div class="text-body-secondary small">
                        Rendered with today's date and the "Next sequence number" above.
                    </div>
                </div>

                <!-- Token cheat-sheet -->
                <div class="settings-group">
                    <div class="settings-group-title">Token cheat-sheet</div>
                    <div class="row g-2 small">
                        <div class="col-md-6">
                            <strong>Date tokens (current time)</strong>
                            <table class="table table-sm table-borderless mb-0">
                                <caption class="visually-hidden">Date tokens you can use in an incident-number template</caption>
                                <thead><tr><th scope="col">Token</th><th scope="col">Meaning</th></tr></thead>
                                <tbody>
                                    <tr><td class="font-monospace"><code>{YYYY}</code></td><td>4-digit year (2026)</td></tr>
                                    <tr><td class="font-monospace"><code>{YY}</code></td><td>2-digit year (26)</td></tr>
                                    <tr><td class="font-monospace"><code>{MM}</code></td><td>2-digit month (01–12)</td></tr>
                                    <tr><td class="font-monospace"><code>{DD}</code></td><td>2-digit day of month (01–31)</td></tr>
                                    <tr><td class="font-monospace"><code>{HH}</code></td><td>2-digit hour (00–23)</td></tr>
                                    <tr><td class="font-monospace"><code>{JJJ}</code></td><td>Day of year (001–366)</td></tr>
                                    <tr><td class="font-monospace"><code>{UU}</code></td><td>ISO week number (01–53)</td></tr>
                                </tbody>
                            </table>
                        </div>
                        <div class="col-md-6">
                            <strong>Sequence counter</strong>
                            <table class="table table-sm table-borderless mb-0">
                                <caption class="visually-hidden">Sequence-counter tokens with padding width</caption>
                                <thead><tr><th scope="col">Token</th><th scope="col">Format</th></tr></thead>
                                <tbody>
                                    <tr><td class="font-monospace"><code>{N}</code> or <code>{0}</code></td><td>no padding (1, 42, 105)</td></tr>
                                    <tr><td class="font-monospace"><code>{NNN}</code> or <code>{000}</code></td><td>3 digits (001, 042, 105)</td></tr>
                                    <tr><td class="font-monospace"><code>{NNNNN}</code> or <code>{00000}</code></td><td>5 digits (00042)</td></tr>
                                </tbody>
                            </table>
                            <div class="text-body-secondary mt-1">
                                If the counter exceeds the token's width, the full number prints (no truncation).
                            </div>
                            <strong class="d-block mt-2">Escaping</strong>
                            <div class="text-body-secondary">
                                Use <code>\{</code> and <code>\}</code> for literal braces, <code>\\</code> for a literal backslash.
                            </div>
                        </div>
                    </div>
                    <div class="mt-2 small">
                        <strong>Example templates:</strong>
                        <code class="ms-1">{YY}-{NNNN}</code> → <span class="text-body-secondary">26-0042</span>,
                        <code class="ms-1">CASE-{YYYY}-{MM}-{DD}-{0000}</code> → <span class="text-body-secondary">CASE-2026-06-02-0042</span>,
                        <code class="ms-1">INV-{YY}{MM}-{NNNNN}</code> → <span class="text-body-secondary">INV-2606-00042</span>
                    </div>
                </div>

                <div class="d-flex gap-2 mt-3">
                    <button type="submit" class="btn btn-sm btn-success">
                        <i class="bi bi-check-lg me-1"></i>Save Incident Number Settings
                    </button>
                </div>
            </form>
        </div>

        <!-- ── Incident Lifecycle — Phase 104d (a beta tester GH #11) ────── -->
        <div class="config-panel" id="panel-incident-lifecycle">
            <div class="config-panel-title">
                <i class="bi bi-arrow-clockwise text-primary"></i> Incident Lifecycle
            </div>
            <p class="text-body-secondary small mb-3">
                When the last active unit clears from an incident, TicketsCAD can
                close the incident automatically after a grace period. If a unit
                is re-dispatched inside the grace window, the pending close is
                cancelled. See
                <a href="help.php#topic-auto-close" target="_blank">help.php — Auto-close on all-clear</a>
                for the full behaviour.
            </p>
            <form id="autoCloseForm">
                <div class="settings-group">
                    <div class="settings-group-title">Auto-close on all-clear</div>
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" id="autoCloseEnabled">
                        <label class="form-check-label small" for="autoCloseEnabled">
                            Close the incident automatically when all units clear
                        </label>
                    </div>
                    <div class="row g-2 align-items-end">
                        <div class="col-md-3">
                            <label for="autoCloseGraceValue" class="form-label form-label-sm">Grace period</label>
                            <input type="number" class="form-control form-control-sm" id="autoCloseGraceValue"
                                   min="1" max="999" value="90">
                        </div>
                        <div class="col-md-3">
                            <label for="autoCloseGraceUnit" class="form-label form-label-sm">Unit</label>
                            <select class="form-select form-select-sm" id="autoCloseGraceUnit">
                                <option value="seconds">seconds</option>
                                <option value="minutes">minutes</option>
                                <option value="hours">hours</option>
                            </select>
                        </div>
                        <div class="col-md-6 text-body-secondary small">
                            Range 1&ndash;999 in each unit. Max 999 hours (~41 days).
                            Default: <span class="font-monospace">90 seconds</span>. A re-dispatch
                            inside the window cancels the pending close.
                        </div>
                    </div>
                </div>
                <div class="d-flex gap-2 mt-3">
                    <button type="submit" class="btn btn-sm btn-success">
                        <i class="bi bi-check-lg me-1"></i>Save Lifecycle Settings
                    </button>
                    <span id="autoCloseStatus" class="align-self-center small text-success"></span>
                </div>
            </form>
        </div>
        <script>
        (function () {
            'use strict';
            var form = document.getElementById('autoCloseForm');
            if (!form) return;
            function pickUnit(secs) {
                if (secs % 3600 === 0 && secs >= 3600) return { value: secs / 3600, unit: 'hours' };
                if (secs % 60 === 0 && secs >= 60)     return { value: secs / 60,   unit: 'minutes' };
                return { value: secs, unit: 'seconds' };
            }
            function toSeconds(value, unit) {
                var v = parseInt(value, 10) || 0;
                if (unit === 'hours')   return v * 3600;
                if (unit === 'minutes') return v * 60;
                return v;
            }
            fetch('api/config-admin.php?section=settings', { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    var s = (data && data.settings) || {};
                    document.getElementById('autoCloseEnabled').checked =
                        (s.auto_close_on_all_clear === undefined || s.auto_close_on_all_clear === null
                         || s.auto_close_on_all_clear === '') ? true : (s.auto_close_on_all_clear === '1');
                    var seconds = parseInt(s.auto_close_grace_seconds || '90', 10);
                    if (isNaN(seconds) || seconds < 1) seconds = 90;
                    var pick = pickUnit(seconds);
                    document.getElementById('autoCloseGraceValue').value = pick.value;
                    document.getElementById('autoCloseGraceUnit').value  = pick.unit;
                })
                .catch(function () { /* leave defaults */ });
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                var enabled = document.getElementById('autoCloseEnabled').checked ? '1' : '0';
                var val = document.getElementById('autoCloseGraceValue').value;
                var unit = document.getElementById('autoCloseGraceUnit').value;
                var secs = toSeconds(val, unit);
                if (secs < 1 || secs > 3596400) {
                    document.getElementById('autoCloseStatus').textContent =
                        'Grace must be 1 second to 999 hours.';
                    document.getElementById('autoCloseStatus').className = 'align-self-center small text-danger';
                    return;
                }
                var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
                fetch('api/config-admin.php?section=settings', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        csrf_token: csrf,
                        settings: {
                            auto_close_on_all_clear:   enabled,
                            auto_close_grace_seconds:  String(secs)
                        }
                    })
                })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    var status = document.getElementById('autoCloseStatus');
                    if (data && data.saved !== undefined) {
                        status.textContent = 'Saved. Applies to the next all-clear.';
                        status.className = 'align-self-center small text-success';
                    } else {
                        status.textContent = 'Save failed: ' + ((data && data.error) || 'unknown');
                        status.className = 'align-self-center small text-danger';
                    }
                    setTimeout(function () { status.textContent = ''; }, 4000);
                })
                .catch(function () {
                    document.getElementById('autoCloseStatus').textContent = 'Network error saving.';
                    document.getElementById('autoCloseStatus').className = 'align-self-center small text-danger';
                });
            });
        })();
        </script>

        <!-- ── PAR Checks (Phase 16a, 2026-06-11) ─────────────────── -->
        <div class="config-panel" id="panel-par-checks">
            <div class="config-panel-title">
                <i class="bi bi-shield-check text-primary"></i> Personnel Accountability Reports (PAR)
            </div>
            <p class="text-body-secondary small mb-3">
                PAR checks help dispatchers confirm every assigned unit is accounted for at
                regular intervals during a working incident. The radio call is still primary;
                this feature helps you manage cadence, track acks, and surface units that
                haven't responded. Disabled by default — opt in below.
            </p>

            <form id="parConfigForm">
                <div class="settings-group">
                    <div class="settings-group-title">Master switch</div>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="parEnabled">
                        <label class="form-check-label" for="parEnabled">
                            Enable PAR features
                        </label>
                    </div>
                    <div class="form-text">
                        When off, the scheduler is dormant, no UI prompts appear, no SSE
                        events fire. Existing PAR history is preserved either way.
                    </div>
                </div>

                <div class="settings-group">
                    <div class="settings-group-title">Agency default cadence</div>
                    <div class="row g-2">
                        <div class="col-md-3">
                            <label for="parCadence" class="form-label form-label-sm">
                                Cadence (minutes)
                            </label>
                            <input type="number" class="form-control form-control-sm"
                                   id="parCadence" min="0" value="20">
                            <div class="form-text small">0 = scheduled PAR off (manual only)</div>
                        </div>
                        <div class="col-md-3">
                            <label for="parFirstWindow" class="form-label form-label-sm">
                                First ack window (s)
                            </label>
                            <input type="number" class="form-control form-control-sm"
                                   id="parFirstWindow" min="10" value="60">
                        </div>
                        <div class="col-md-3">
                            <label for="parRetryWindow" class="form-label form-label-sm">
                                Retry ack window (s)
                            </label>
                            <input type="number" class="form-control form-control-sm"
                                   id="parRetryWindow" min="10" value="120">
                        </div>
                        <div class="col-md-3">
                            <label for="parMaxMisses" class="form-label form-label-sm">
                                Max misses before unaccounted
                            </label>
                            <input type="number" class="form-control form-control-sm"
                                   id="parMaxMisses" min="1" value="2">
                        </div>
                    </div>
                </div>

                <div class="settings-group">
                    <div class="settings-group-title">Standby units</div>
                    <div class="row g-2">
                        <div class="col-md-6">
                            <label for="parStandbyBehavior" class="form-label form-label-sm">
                                Include units in standby / staging / available status?
                            </label>
                            <select class="form-select form-select-sm" id="parStandbyBehavior">
                                <option value="recommended">Recommended (exclude when not engaged)</option>
                                <option value="include">Always include</option>
                                <option value="exclude">Never include</option>
                            </select>
                            <div class="form-text small">
                                Recommended (default) skips units whose current status name
                                contains "standby", "staging", "available", "offduty",
                                or "reserve". Agencies that want to PAR everyone on scene
                                should pick <em>Always include</em>.
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label for="parMaydayAuto" class="form-label form-label-sm">
                                Auto-trigger PAR on Mayday?
                            </label>
                            <select class="form-select form-select-sm" id="parMaydayAuto">
                                <option value="1">Enabled (default)</option>
                                <option value="0">Disabled</option>
                            </select>
                            <div class="form-text small">
                                When a dispatcher clicks the red "Mayday" button on an
                                incident, automatically fire an urgent-escalation PAR
                                cycle. Strongly recommended.
                            </div>
                        </div>
                    </div>
                </div>

                <div class="settings-group">
                    <div class="settings-group-title">Escalation</div>
                    <div class="row g-2">
                        <div class="col-md-6">
                            <label for="parChatChannel" class="form-label form-label-sm">
                                Chat channel for escalations
                            </label>
                            <input type="text" class="form-control form-control-sm"
                                   id="parChatChannel" maxlength="64"
                                   placeholder="e.g. dispatch-ops">
                            <div class="form-text small">
                                When a unit misses its PAR window, a chat post lands here.
                                Leave blank to skip chat escalation.
                            </div>
                        </div>
                    </div>
                </div>

                <button type="submit" class="btn btn-sm btn-success">
                    <i class="bi bi-check-lg me-1"></i>Save PAR Settings
                </button>
            </form>
        </div>

        <!-- ── Security Labels (Phase 18b, 2026-06-11) ────────────── -->
        <div class="config-panel" id="panel-security-labels">
            <div class="config-panel-title">
                <i class="bi bi-shield-lock-fill text-danger"></i> Security Labels
            </div>
            <p class="text-body-secondary small mb-3">
                Define security labels for incidents. Each label controls how
                incidents tagged with it appear on the EOC Display, how routed
                messages are gated/delayed, and how ICS form exports are
                watermarked. Labels you create here appear as choices on every
                incident type and on every incident-detail page.
                <strong>Whatever you name a label is what gets stamped on
                every surface</strong> — no re-mapping.
            </p>

            <!-- ── ICS Forms Sharing (GH #79, 2026-07-12) ──────────────
                 Self-contained round-trip (own inline IIFE) so it does not
                 depend on the System-Settings form handler, which does not
                 collect checkbox state. -->
            <fieldset class="border rounded p-3 mb-4">
                <legend class="float-none w-auto px-2 small fw-semibold">
                    <i class="bi bi-people text-primary me-1"></i>ICS Forms Sharing
                </legend>
                <form id="icsShareStandaloneForm" class="d-flex flex-wrap align-items-center gap-3">
                    <div class="form-check form-switch mb-0">
                        <input class="form-check-input" type="checkbox" role="switch"
                               id="icsShareStandalone">
                        <label class="form-check-label small" for="icsShareStandalone">
                            Standalone forms visible to the whole team
                        </label>
                    </div>
                    <button type="submit" class="btn btn-sm btn-success">
                        <i class="bi bi-check-lg me-1"></i>Save
                    </button>
                    <span id="icsShareStandaloneStatus" class="small"></span>
                </form>
                <p class="text-body-secondary small mb-0 mt-2">
                    ICS forms that are <em>not</em> attached to an incident are private to
                    the person who created them by default. Turn this on only if this
                    install serves a <strong>single organization</strong> — it lets any
                    signed-in user open and edit every standalone form. Leave it off on a
                    multi-organization install: standalone forms carry no organization tag,
                    so team-wide visibility would expose one organization's forms to another.
                    (Forms attached to an incident are already shared with everyone who can
                    see that incident, regardless of this setting.)
                </p>
            </fieldset>
            <script>
            (function () {
                var form = document.getElementById('icsShareStandaloneForm');
                if (!form) return;
                var box    = document.getElementById('icsShareStandalone');
                var status = document.getElementById('icsShareStandaloneStatus');
                function csrf() {
                    return (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
                }
                fetch('api/config-admin.php?section=settings', { credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        var s = (data && data.settings) || {};
                        box.checked = (s.ics_forms_share_standalone === '1');
                    })
                    .catch(function () { /* leave unchecked */ });
                form.addEventListener('submit', function (e) {
                    e.preventDefault();
                    var token = csrf();
                    fetch('api/config-admin.php?section=settings', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token },
                        credentials: 'same-origin',
                        body: JSON.stringify({
                            csrf_token: token,
                            settings: { ics_forms_share_standalone: box.checked ? '1' : '0' }
                        })
                    })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (data && data.saved !== undefined) {
                            status.textContent = box.checked
                                ? 'Saved — standalone forms are now team-visible.'
                                : 'Saved — standalone forms are private to their creator.';
                            status.className = 'small text-success';
                        } else {
                            status.textContent = 'Save failed: ' + ((data && data.error) || 'unknown');
                            status.className = 'small text-danger';
                        }
                        setTimeout(function () { status.textContent = ''; }, 4000);
                    })
                    .catch(function () {
                        status.textContent = 'Network error saving.';
                        status.className = 'small text-danger';
                    });
                });
            })();
            </script>

            <div class="d-flex justify-content-end mb-2">
                <button type="button" class="btn btn-sm btn-primary" id="btnNewSecLabel">
                    <i class="bi bi-plus-lg me-1"></i>New Label
                </button>
            </div>

            <div id="secLabelList">
                <div class="text-body-secondary small text-center py-3">Loading…</div>
            </div>

            <!-- Editor modal -->
            <div class="modal fade" id="secLabelModal" tabindex="-1">
                <div class="modal-dialog modal-lg modal-dialog-scrollable">
                    <div class="modal-content">
                        <div class="modal-header py-2">
                            <h6 class="modal-title" id="secLabelModalTitle">Edit Security Label</h6>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <form id="secLabelForm" autocomplete="off">
                                <input type="hidden" id="secLabelId" value="">
                                <div class="row g-2 mb-3">
                                    <div class="col-md-4">
                                        <label class="form-label form-label-sm">Code (machine ID)</label>
                                        <input type="text" id="secLabelCode" class="form-control form-control-sm" maxlength="32" placeholder="standard">
                                        <div class="form-text small">lowercase, letters/numbers/_ only</div>
                                    </div>
                                    <div class="col-md-5">
                                        <label class="form-label form-label-sm">Display name</label>
                                        <input type="text" id="secLabelName" class="form-control form-control-sm" maxlength="64" placeholder="Standard">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label form-label-sm">Sort order</label>
                                        <input type="number" id="secLabelSort" class="form-control form-control-sm" min="0" value="100">
                                    </div>
                                </div>
                                <div class="row g-2 mb-3">
                                    <div class="col-md-3">
                                        <label class="form-label form-label-sm">Badge background</label>
                                        <input type="color" id="secLabelBg" class="form-control form-control-color form-control-sm">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label form-label-sm">Badge text</label>
                                        <input type="color" id="secLabelFg" class="form-control form-control-color form-control-sm">
                                    </div>
                                    <div class="col-md-3 d-flex align-items-end">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" id="secLabelDefault">
                                            <label class="form-check-label small" for="secLabelDefault">System default</label>
                                        </div>
                                    </div>
                                    <div class="col-md-3 d-flex align-items-end">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" id="secLabelReqReason">
                                            <label class="form-check-label small" for="secLabelReqReason">Require reason</label>
                                        </div>
                                    </div>
                                </div>

                                <fieldset class="border rounded p-2 mb-3">
                                    <legend class="float-none w-auto px-2 small fw-semibold">EOC Display</legend>
                                    <div class="row g-2">
                                        <div class="col-md-4">
                                            <div class="form-check form-switch">
                                                <input class="form-check-input" type="checkbox" id="secLabelEocScope">
                                                <label class="form-check-label small" for="secLabelEocScope">Show scope</label>
                                            </div>
                                            <div class="form-check form-switch">
                                                <input class="form-check-input" type="checkbox" id="secLabelEocAddress">
                                                <label class="form-check-label small" for="secLabelEocAddress">Show address</label>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label form-label-sm">Map marker</label>
                                            <select id="secLabelEocMarker" class="form-select form-select-sm">
                                                <option value="full">Full pin</option>
                                                <option value="dim">Dim pin (no popup detail)</option>
                                                <option value="hide">Hide pin</option>
                                            </select>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label form-label-sm">Placeholder text</label>
                                            <input type="text" id="secLabelEocPlaceholder" class="form-control form-control-sm" maxlength="64" placeholder="*** Restricted ***">
                                        </div>
                                    </div>
                                </fieldset>

                                <fieldset class="border rounded p-2 mb-3">
                                    <legend class="float-none w-auto px-2 small fw-semibold">Message Routing</legend>
                                    <div class="row g-2">
                                        <div class="col-md-3">
                                            <div class="form-check form-switch">
                                                <input class="form-check-input" type="checkbox" id="secLabelRoutingBroadcast">
                                                <label class="form-check-label small" for="secLabelRoutingBroadcast">Allow broadcast</label>
                                            </div>
                                            <div class="form-check form-switch">
                                                <input class="form-check-input" type="checkbox" id="secLabelRoutingDirect">
                                                <label class="form-check-label small" for="secLabelRoutingDirect">Allow direct-to-unit</label>
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label form-label-sm">Send delay (s)</label>
                                            <input type="number" id="secLabelSendDelay" class="form-control form-control-sm" min="0" max="3600" value="0">
                                            <div class="form-text small">Queued. Can be killed before send.</div>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label form-label-sm">Recall window (s)</label>
                                            <input type="number" id="secLabelRecall" class="form-control form-control-sm" min="0" max="3600" value="0">
                                            <div class="form-text small">After send. Best-effort delete.</div>
                                        </div>
                                    </div>
                                </fieldset>

                                <fieldset class="border rounded p-2 mb-3">
                                    <legend class="float-none w-auto px-2 small fw-semibold">ICS Form Exports</legend>
                                    <div class="row g-2">
                                        <div class="col-md-4">
                                            <div class="form-check form-switch">
                                                <input class="form-check-input" type="checkbox" id="secLabelIcsFull">
                                                <label class="form-check-label small" for="secLabelIcsFull">Show full scope/address</label>
                                            </div>
                                        </div>
                                        <div class="col-md-8">
                                            <label class="form-label form-label-sm">Watermark text</label>
                                            <input type="text" id="secLabelIcsWatermark" class="form-control form-control-sm" maxlength="64" placeholder="Confidential">
                                            <div class="form-text small">
                                                Leave blank for no watermark. Use the label's own
                                                name for consistency.
                                            </div>
                                        </div>
                                    </div>
                                </fieldset>
                            </form>
                        </div>
                        <div class="modal-footer py-2">
                            <button type="button" class="btn btn-sm btn-outline-danger me-auto" id="btnDeleteSecLabel">
                                <i class="bi bi-trash me-1"></i>Delete
                            </button>
                            <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="button" class="btn btn-sm btn-success" id="btnSaveSecLabel">
                                <i class="bi bi-check-lg me-1"></i>Save
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Pending Routed Messages (Phase 18e, 2026-06-11) ────── -->
        <div class="config-panel" id="panel-pending-messages">
            <div class="config-panel-title">
                <i class="bi bi-hourglass-split text-warning"></i> Pending Routed Messages
            </div>
            <p class="text-body-secondary small mb-3">
                Routed messages tied to incidents whose security label has a
                <strong>send delay</strong> are parked here before delivery.
                Click <em>Kill</em> on any row before its countdown expires
                and the message never goes out. A killed message leaves a
                routing-log row marked as such for the audit trail.
            </p>

            <div class="d-flex align-items-center gap-2 mb-2">
                <div class="btn-group btn-group-sm" role="group" id="pmFilter">
                    <button type="button" class="btn btn-outline-warning active" data-status="pending">Pending</button>
                    <button type="button" class="btn btn-outline-success" data-status="sent">Sent</button>
                    <button type="button" class="btn btn-outline-secondary" data-status="killed">Killed</button>
                    <button type="button" class="btn btn-outline-danger" data-status="failed">Failed</button>
                </div>
                <span class="text-body-secondary small ms-auto" id="pmCount">—</span>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="pmRefresh"
                        title="Refresh now (auto-refreshes every 5s while pending)">
                    <i class="bi bi-arrow-clockwise"></i>
                </button>
            </div>

            <div id="pendingMsgsTable">
                <div class="text-body-secondary small text-center py-3">Loading…</div>
            </div>
        </div>

        <!-- ── Field Help Text (hints table) ──────────────────────── -->
        <div class="config-panel" id="panel-signals">
            <div class="config-panel-title">
                <i class="bi bi-question-circle text-primary"></i> Field Help Text
            </div>
            <p class="text-body-secondary small mb-3">
                These are the help tooltips displayed next to form fields on the incident screen.
                Each entry maps a field tag (e.g. <code>_loca</code>, <code>_phone</code>) to its help text.
                Edit the text to customize what users see when they hover over a field's help icon.
            </p>

            <div class="config-edit-panel" id="signalEditPanel">
                <form id="signalForm">
                    <input type="hidden" id="signalId" name="id" value="">
                    <div class="row g-2">
                        <div class="col-md-3">
                            <label for="signalTag" class="form-label form-label-sm">Field Tag <span class="text-danger">*</span></label>
                            <input type="text" class="form-control form-control-sm" id="signalTag" name="tag" required maxlength="8">
                            <div class="form-text">e.g. _loca, _phone, _synop</div>
                        </div>
                        <div class="col-md-9">
                            <label for="signalHint" class="form-label form-label-sm">Help Text <span class="text-danger">*</span></label>
                            <textarea class="form-control form-control-sm" id="signalHint" name="hint" required maxlength="500" rows="2"></textarea>
                        </div>
                    </div>
                    <div class="d-flex gap-2 mt-3">
                        <button type="submit" class="btn btn-sm btn-success"><i class="bi bi-check-lg me-1"></i>Save</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="btnCancelSignal"><i class="bi bi-x-lg me-1"></i>Cancel</button>
                        <button type="button" class="btn btn-sm btn-outline-danger ms-auto d-none" id="btnDeleteSignal"><i class="bi bi-trash me-1"></i>Delete</button>
                    </div>
                </form>
            </div>

            <div class="config-toolbar">
                <button class="btn btn-sm btn-success ms-auto" id="btnAddSignal"><i class="bi bi-plus-lg me-1"></i>Add Help Entry</button>
            </div>

            <div class="table-responsive">
                <table class="table table-sm table-hover config-table">
                    <thead>
                        <tr>
                            <th style="width:50px;">ID</th>
                            <th style="width:100px;">Field Tag</th>
                            <th>Help Text</th>
                        </tr>
                    </thead>
                    <tbody id="signalsTableBody"></tbody>
                </table>
            </div>
            <div class="config-status-bar" id="signalsStatus">Loading...</div>
        </div>

        <!-- ── Lookup Services ─────────────────────────────────────── -->
        <div class="config-panel" id="panel-lookup-services">
            <div class="config-panel-title">
                <i class="bi bi-broadcast text-info"></i> Lookup Services
            </div>
            <p class="text-body-secondary small mb-3">
                Configure how the roster looks up callsign and zip code data.
                Internet access is optional — local database lookups work offline.
            </p>

            <div class="settings-group">
                <div class="settings-group-title">Callsign Lookup Provider</div>
                <div class="row g-2">
                    <div class="col-md-6">
                        <label for="lookupProvider" class="form-label form-label-sm">Provider</label>
                        <select class="form-select form-select-sm" id="lookupProvider">
                            <option value="disabled">Disabled</option>
                            <option value="local">Local Database (MySQL)</option>
                            <option value="callook" selected>callook.info (Internet)</option>
                            <option value="fcc_uls_api">FCC-ULS-API (Self-hosted)</option>
                        </select>
                        <div class="form-text">
                            <strong>Local:</strong> Import FCC data with <code>php tools/import-fcc.php</code><br>
                            <strong>callook.info:</strong> Free public API, requires internet<br>
                            <strong>FCC-ULS-API:</strong> Self-hosted Flask service
                            (<a href="https://github.com/porcej/FCC-ULS-API" target="_blank">GitHub</a>)
                        </div>
                    </div>
                    <div class="col-md-6" id="fccUlsApiUrlGroup">
                        <label for="fccUlsApiUrl" class="form-label form-label-sm">FCC-ULS-API URL</label>
                        <input type="url" class="form-control form-control-sm" id="fccUlsApiUrl"
                               placeholder="http://localhost:5000" value="http://localhost:5000">
                        <div class="form-text">Base URL of the FCC-ULS-API Flask server</div>
                    </div>
                </div>
            </div>

            <div class="settings-group">
                <div class="settings-group-title">Database Status</div>
                <div class="row g-2">
                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-body py-2 text-center">
                                <div class="small text-body-secondary">Amateur Radio</div>
                                <div id="lookupAmCount" class="fw-bold">—</div>
                                <div class="small text-muted">records</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-body py-2 text-center">
                                <div class="small text-body-secondary">GMRS</div>
                                <div id="lookupGmrsCount" class="fw-bold">—</div>
                                <div class="small text-muted">records</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-body py-2 text-center">
                                <div class="small text-body-secondary">Zip Codes</div>
                                <div id="lookupZipCount" class="fw-bold">—</div>
                                <div class="small text-muted">records</div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="mt-2 small text-body-secondary">
                    <strong>Import commands:</strong><br>
                    <code>php tools/import-fcc.php amateur &lt;extracted-dir&gt;</code> — Amateur radio licenses<br>
                    <code>php tools/import-fcc.php gmrs &lt;extracted-dir&gt;</code> — GMRS licenses<br>
                    <code>php tools/import-zipcodes.php &lt;csv-file&gt;</code> — US zip codes
                </div>
            </div>

            <div class="mt-3">
                <button type="button" class="btn btn-sm btn-primary" id="btnSaveLookupConfig">
                    <i class="bi bi-check-lg me-1"></i>Save Lookup Settings
                </button>
                <span id="lookupSaveStatus" class="ms-2 small"></span>
            </div>
        </div>

        <!-- ── API Keys ────────────────────────────────────────────── -->
        <div class="config-panel" id="panel-api-keys">
            <div class="config-panel-title">
                <i class="bi bi-key text-warning"></i> API Keys
            </div>
            <form id="apiKeysForm">
                <div class="settings-group">
                    <div class="settings-group-title">Weather</div>
                    <div class="row g-2">
                        <div class="col-md-6">
                            <label for="setOwmKey" class="form-label form-label-sm">OpenWeatherMap API Key</label>
                            <div class="input-group input-group-sm">
                                <input type="password" class="form-control" id="setOwmKey" data-key="owm_api_key" maxlength="64" autocomplete="off">
                                <button class="btn btn-outline-secondary secret-reveal" type="button" data-target="setOwmKey" title="Reveal / hide"><i class="bi bi-eye"></i></button>
                                <button class="btn btn-outline-secondary secret-copy" type="button" data-target="setOwmKey" title="Copy to clipboard"><i class="bi bi-clipboard"></i></button>
                            </div>
                            <div class="form-text">Used for weather overlays on the map. <a href="https://openweathermap.org/api" target="_blank">Get a key</a></div>
                        </div>
                    </div>
                </div>
                <div class="settings-group">
                    <div class="settings-group-title">Geocoding</div>
                    <div class="row g-2">
                        <div class="col-md-6">
                            <label for="setGeoProvider" class="form-label form-label-sm">Geocoding Provider</label>
                            <select class="form-select form-select-sm" id="setGeoProvider" data-key="geocoding_provider">
                                <option value="nominatim">Nominatim / OSM (free, no key needed)</option>
                                <option value="locationiq">LocationIQ</option>
                                <option value="geoapify">Geoapify</option>
                                <option value="google">Google Maps</option>
                                <option value="here">HERE</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="setGeoKey" class="form-label form-label-sm">Geocoding API Key (if applicable)</label>
                            <div class="input-group input-group-sm">
                                <input type="password" class="form-control" id="setGeoKey" data-key="geocoding_api_key" maxlength="128" autocomplete="off">
                                <button class="btn btn-outline-secondary secret-reveal" type="button" data-target="setGeoKey" title="Reveal / hide"><i class="bi bi-eye"></i></button>
                                <button class="btn btn-outline-secondary secret-copy" type="button" data-target="setGeoKey" title="Copy to clipboard"><i class="bi bi-clipboard"></i></button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- PRE-RELEASE-FIXES #8 — External incident feed onboarding -->
                <div class="settings-group" id="feedKeyGroup">
                    <div class="settings-group-title">External Incident Feed</div>
                    <div class="alert alert-warning py-2 d-none" id="feedKeyMissingBanner" role="alert">
                        <div class="d-flex align-items-start gap-2">
                            <i class="bi bi-exclamation-triangle-fill mt-1"></i>
                            <div class="flex-grow-1">
                                <div class="fw-semibold mb-1">External feed is disabled</div>
                                <div class="small">
                                    No <code>feed_api_key</code> is configured, so <code>api/feed.php</code>
                                    will reject every anonymous request (fail-closed by design — see
                                    security audit F-002). Generate a key below to enable the
                                    JSON/RSS/Atom feed for partner systems.
                                </div>
                            </div>
                            <button type="button" class="btn btn-sm btn-warning" id="btnGenerateFeedKey">
                                <i class="bi bi-shuffle me-1"></i>Generate
                            </button>
                        </div>
                    </div>
                    <div class="row g-2">
                        <div class="col-md-9">
                            <label for="setFeedApiKey" class="form-label form-label-sm">Feed API Key</label>
                            <div class="input-group input-group-sm">
                                <input type="password" class="form-control font-monospace"
                                       id="setFeedApiKey" data-key="feed_api_key" maxlength="64" autocomplete="off"
                                       placeholder="(empty — feed disabled)">
                                <button class="btn btn-outline-secondary secret-reveal" type="button"
                                        data-target="setFeedApiKey" title="Reveal / hide">
                                    <i class="bi bi-eye"></i>
                                </button>
                                <button class="btn btn-outline-secondary secret-copy" type="button"
                                        data-target="setFeedApiKey" title="Copy to clipboard">
                                    <i class="bi bi-clipboard"></i>
                                </button>
                            </div>
                            <div class="form-text">Pass via <code>?key=</code> or <code>X-Feed-Key</code> header. Anyone with this key can read every open incident, including PII — keep it secret.</div>
                        </div>
                    </div>
                </div>

                <div class="d-flex gap-2 mt-3">
                    <button type="submit" class="btn btn-sm btn-success"><i class="bi bi-check-lg me-1"></i>Save API Keys</button>
                </div>
            </form>
        </div>

        <!-- ── Map Defaults ────────────────────────────────────────── -->
        <div class="config-panel" id="panel-map-defaults">
            <div class="config-panel-title">
                <i class="bi bi-pin-map text-info"></i> Map Settings
            </div>
            <p class="text-body-secondary small mb-2">
                Set the default map view for all pages. Pan and zoom the preview map to your desired
                view, then click <strong>Use This View</strong> to capture the coordinates.
            </p>

            <!-- Interactive map preview (16:9 aspect ratio to match situation screen) -->
            <div class="border rounded mb-2" style="position:relative;max-width:640px">
                <div id="mapDefaultsPreview" style="width:100%;aspect-ratio:16/9;border-radius:5px"></div>
            </div>
            <p class="text-body-secondary small mb-2">
                <i class="bi bi-arrows-move me-1"></i>Pan and zoom the map above &mdash; the fields below update automatically.
            </p>

            <form id="mapDefaultsForm">
                <div class="row g-2">
                    <div class="col-md-3">
                        <label for="setMapLat" class="form-label form-label-sm">Default Center Latitude</label>
                        <input type="text" class="form-control form-control-sm" id="setMapLat" data-key="default_lat" placeholder="44.9778">
                    </div>
                    <div class="col-md-3">
                        <label for="setMapLng" class="form-label form-label-sm">Default Center Longitude</label>
                        <input type="text" class="form-control form-control-sm" id="setMapLng" data-key="default_lng" placeholder="-93.2650">
                    </div>
                    <div class="col-md-2">
                        <label for="setMapZoom" class="form-label form-label-sm">Default Zoom</label>
                        <input type="number" class="form-control form-control-sm" id="setMapZoom" data-key="default_zoom" min="1" max="20" placeholder="12">
                    </div>
                    <div class="col-md-4">
                        <label for="setMapLayer" class="form-label form-label-sm">Default Layer</label>
                        <select class="form-select form-select-sm" id="setMapLayer" data-key="default_map_layer">
                            <option value="0">Street</option>
                            <option value="1">Satellite</option>
                            <option value="2">Terrain</option>
                        </select>
                    </div>
                </div>
                <!-- GH #58 — reset a locked Situation overview when a new incident lands off-screen -->
                <div class="row g-2 mt-1">
                    <div class="col-md-8">
                        <label for="setSitResetOffscreen" class="form-label form-label-sm">
                            Situation view: new off-screen incidents
                            <i class="bi bi-question-circle text-body-secondary" tabindex="0"
                               data-bs-toggle="popover" data-bs-trigger="hover focus" data-bs-placement="top"
                               data-bs-content="On the full-screen Situation overview, if an operator has locked the view (zoomed or panned) and a NEW incident is created outside the current map extents, automatically unlock and re-fit the map so the new incident is visible. Turn off to keep the operator's locked view; they can re-fit manually with the padlock control."
                               title="Off-screen incident behavior"></i>
                        </label>
                        <select class="form-select form-select-sm" id="setSitResetOffscreen" data-key="situation_reset_on_offscreen">
                            <option value="1">Reset the locked view to show the new incident (recommended)</option>
                            <option value="0">Keep the locked view (operator re-fits manually)</option>
                        </select>
                    </div>
                </div>
                <div class="d-flex gap-2 mt-3">
                    <button type="submit" class="btn btn-sm btn-success"><i class="bi bi-check-lg me-1"></i>Save Map Defaults</button>
                </div>
            </form>
        </div>

        <!-- ── Tile Providers ──────────────────────────────────────── -->
        <div class="config-panel" id="panel-tile-providers">
            <div class="config-panel-title">
                <i class="bi bi-grid-3x3-gap text-info"></i> Tile Providers
            </div>
            <form id="tileProviderForm">
                <div class="settings-group mb-3">
                    <div class="settings-group-title">Map Tile Source</div>
                    <div class="row g-2">
                        <div class="col-md-5">
                            <label for="setTileProvider" class="form-label form-label-sm">Provider</label>
                            <select class="form-select form-select-sm" id="setTileProvider" data-key="tile_provider">
                                <optgroup label="Free — no key required (recommended)">
                                    <option value="osm">OpenStreetMap — Standard</option>
                                    <option value="osm_hot">OpenStreetMap — Humanitarian (HOT)</option>
                                    <option value="usgs_topo">USGS — Topographic (US only)</option>
                                    <option value="usgs_imagery">USGS — Imagery (satellite, US only)</option>
                                    <option value="usgs_imagery_topo">USGS — Imagery + Topo (US only)</option>
                                    <option value="cartodb_positron">CartoDB — Positron (light)</option>
                                    <option value="cartodb_dark">CartoDB — Dark Matter</option>
                                    <option value="esri_street">Esri — Street</option>
                                    <option value="esri_sat">Esri — World Imagery (satellite)</option>
                                    <option value="esri_topo">Esri — Topographic</option>
                                </optgroup>
                                <optgroup label="Requires API key">
                                    <option value="mapbox">Mapbox</option>
                                    <option value="custom">Custom URL (Azure Maps, etc.)</option>
                                </optgroup>
                                <optgroup label="Backward compatibility — not recommended for new use">
                                    <option value="google_street">Google Maps — Streets (unofficial)</option>
                                    <option value="google_sat">Google Maps — Satellite (unofficial)</option>
                                    <option value="google_hybrid">Google Maps — Hybrid (unofficial)</option>
                                    <option value="bing_road">Bing — Road (retired by Microsoft)</option>
                                    <option value="bing_aerial">Bing — Aerial (retired by Microsoft)</option>
                                </optgroup>
                            </select>
                            <div class="form-text small">
                                Bing Maps for Enterprise was discontinued by Microsoft
                                (Basic accounts shut down 2025-06-30; Enterprise sunset
                                2028-06-30). See
                                <a href="help.php#tile-providers" target="_blank">help &raquo; Tile Providers</a>
                                for the full reference and the Azure Maps migration path.
                            </div>
                        </div>
                        <div class="col-md-7">
                            <label for="setTileUrl" class="form-label form-label-sm">
                                Tile URL Template
                            </label>
                            <input type="text" class="form-control form-control-sm" id="setTileUrl" data-key="tile_server_url"
                                   placeholder="https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png">
                        </div>
                    </div>

                    <!-- Provider-specific info panel -->
                    <div class="mt-2 small" id="tileProviderInfo"></div>

                    <!-- Live tile preview -->
                    <div class="mt-2 border rounded" style="max-width:640px">
                        <div id="tilePreviewMap" style="width:100%;aspect-ratio:16/9;border-radius:5px"></div>
                    </div>
                    <div class="mt-1 d-flex align-items-center gap-2">
                        <button type="button" class="btn btn-sm btn-outline-info" id="btnTestTiles">
                            <i class="bi bi-eye me-1"></i>Refresh Preview
                        </button>
                        <span class="small" id="tileTestStatus"></span>
                    </div>
                </div>

                <div class="settings-group mb-3">
                    <div class="settings-group-title">Delivery &amp; Caching</div>
                    <div class="row g-2">
                        <div class="col-md-4">
                            <label for="setTileMode" class="form-label form-label-sm">Tile Mode</label>
                            <select class="form-select form-select-sm" id="setTileMode" data-key="tile_mode">
                                <option value="proxy" selected>Proxy (route through server cache) — recommended</option>
                                <option value="direct">Direct (browser fetches from provider)</option>
                            </select>
                            <div class="form-text small">Proxy is the default — it caches tiles on the server (keeps maps working during brief Internet outages, hides your API key from the browser, lower latency on warm cache).</div>
                        </div>
                        <div class="col-md-3">
                            <label for="setTileCacheDays" class="form-label form-label-sm">Cache Duration (days)</label>
                            <input type="number" class="form-control form-control-sm" id="setTileCacheDays" data-key="tile_cache_days" min="0" max="365" placeholder="60">
                        </div>
                        <div class="col-md-5">
                            <label for="setTileApiKey" class="form-label form-label-sm">Map Tile API Key (if required)</label>
                            <input type="text" class="form-control form-control-sm" id="setTileApiKey" data-key="tile_api_key" maxlength="128" autocomplete="off"
                                   placeholder="Only needed for Google, Bing, Mapbox">
                        </div>
                    </div>
                </div>

                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-sm btn-success"><i class="bi bi-check-lg me-1"></i>Save Tile Settings</button>
                </div>
            </form>

            <!-- Provider setup reference -->
            <details class="small text-body-secondary mt-3">
                <summary class="fw-semibold mb-1"><i class="bi bi-book me-1"></i>Provider Setup Guide</summary>
                <div class="ps-3 mt-2">
                    <div class="mb-3">
                        <strong class="text-body">OpenStreetMap</strong> <span class="badge bg-success">Free</span><br>
                        No API key needed. Free for all use cases. Community-maintained data, updated regularly.
                        Best general-purpose option for most deployments.
                    </div>
                    <div class="mb-3">
                        <strong class="text-body">Google Maps</strong> <span class="badge bg-warning text-dark">API Key Required</span><br>
                        1. Go to <a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener">Google Cloud Console</a><br>
                        2. Create a project &rarr; Enable "Maps JavaScript API" and "Map Tiles API"<br>
                        3. Create an API key &rarr; restrict to your server's domain/IP<br>
                        4. Paste the key in "Map Tile API Key" above<br>
                        <em>Free tier: $200/month credit (~28,000 tile loads). Set budget alerts.</em>
                    </div>
                    <div class="mb-3">
                        <strong class="text-body">Bing Maps</strong> <span class="badge bg-warning text-dark">API Key Required</span><br>
                        1. Go to <a href="https://www.bingmapsportal.com/" target="_blank" rel="noopener">Bing Maps Portal</a><br>
                        2. Sign in with Microsoft account &rarr; Create key &rarr; Application type: "Dev/Test"<br>
                        3. Paste the key above<br>
                        <em>Free tier: 125,000 transactions/year for non-profit/education. Bing uses quadkey addressing ({q} placeholder).</em>
                    </div>
                    <div class="mb-3">
                        <strong class="text-body">Esri / ArcGIS</strong> <span class="badge bg-success">Free</span><br>
                        No API key needed for basic tile access. Esri provides Street, Satellite, and Topographic
                        basemaps free of charge. High-quality imagery, especially for US coverage.
                        <em>For premium layers or high volume, create an <a href="https://developers.arcgis.com/" target="_blank" rel="noopener">ArcGIS Developer account</a>.</em>
                    </div>
                    <div class="mb-3">
                        <strong class="text-body">Mapbox</strong> <span class="badge bg-warning text-dark">API Key Required</span><br>
                        1. Go to <a href="https://account.mapbox.com/" target="_blank" rel="noopener">Mapbox Account</a><br>
                        2. Create account &rarr; Copy default public token from dashboard<br>
                        3. Paste as API key above<br>
                        <em>Free tier: 200,000 tile requests/month. Beautiful custom-styled maps.</em>
                    </div>
                    <div class="mb-0">
                        <strong class="text-body">Custom URL</strong><br>
                        Enter any XYZ tile URL with <code>{s}</code> (subdomain), <code>{z}</code> (zoom),
                        <code>{x}</code> and <code>{y}</code> (tile coordinates) placeholders.
                        Use <code>{key}</code> for API key substitution.
                        Example: <code>https://tiles.example.com/{z}/{x}/{y}.png</code>
                    </div>
                </div>
            </details>
        </div>

        <!-- ── Facilities ──────────────────────────────────────────── -->
        <div class="config-panel" id="panel-facilities">
            <div class="config-panel-title">
                <i class="bi bi-building text-warning"></i> Facilities
            </div>
            <!-- Issue #44 (a beta tester 2026-07-03): the previous inline
                 mini-form in this panel had ~7 fields but the real
                 facility-edit page has ~20 (address, contact fields,
                 bed capacity, status, map picker, etc.). Instead of
                 duplicating that form here (which would drift out of
                 sync), route the "Add/Edit" actions to the full
                 editor at facility-edit.php. This matches how
                 Members / Personnel already works (settings shows
                 the overview, editing happens on the dedicated page). -->
            <p class="text-body-secondary small mb-3">
                Add or edit facilities using the full
                <a href="facility-edit.php" target="_blank">facility editor</a>
                (address, status, contact, bed capacity, map picker — all fields available).
                The table below is a quick-view of the current list.
            </p>

            <div class="config-toolbar mb-2">
                <input type="text" class="form-control form-control-sm" id="facilitySearch" placeholder="Search facilities..." style="max-width:280px;">
                <a class="btn btn-sm btn-success ms-auto" href="facility-edit.php" target="_blank" id="btnAddFacility">
                    <i class="bi bi-plus-lg me-1"></i>New Facility
                </a>
            </div>

            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th style="width:50px;">ID</th>
                            <th>Name</th>
                            <th>Type</th>
                            <th>Description</th>
                            <th class="text-center" style="width:70px;">Hidden</th>
                            <th class="text-center" style="width:80px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="facilitiesTableBody"></tbody>
                </table>
            </div>
            <div class="config-status-bar" id="facilitiesStatus">Loading...</div>
        </div>

        <!-- ── System Settings ─────────────────────────────────────── -->
        <div class="config-panel" id="panel-system-settings">
            <div class="config-panel-title">
                <i class="bi bi-gear text-secondary"></i> System Settings
            </div>
            <form id="settingsForm">
                <div class="settings-group">
                    <div class="settings-group-title">General</div>
                    <div class="row g-2">
                        <div class="col-md-6">
                            <label for="setAreaTitle" class="form-label form-label-sm">Area Title</label>
                            <input type="text" class="form-control form-control-sm" id="setAreaTitle" data-key="area_title" maxlength="200" placeholder="My County CAD">
                        </div>
                        <div class="col-md-3">
                            <label for="setTimezone" class="form-label form-label-sm">Timezone</label>
                            <input type="text" class="form-control form-control-sm" id="setTimezone" data-key="timezone"
                                   list="timezoneList" maxlength="50" placeholder="Start typing... e.g. America/">
                            <datalist id="timezoneList"></datalist>
                        </div>
                    </div>
                </div>

                <div class="settings-group">
                    <div class="settings-group-title">Date &amp; Time Format</div>
                    <div class="row g-2">
                        <div class="col-md-3">
                            <label for="setTimeFmt" class="form-label form-label-sm">Time Format</label>
                            <select class="form-select form-select-sm" id="setTimeFmt" data-key="time_format">
                                <option value="24">24-hour (14:30)</option>
                                <option value="12">12-hour (2:30 PM)</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="setDateFmt" class="form-label form-label-sm">
                                Date Format
                                <i class="bi bi-question-circle text-body-secondary" tabindex="0"
                                   data-bs-toggle="popover" data-bs-trigger="hover focus" data-bs-placement="top"
                                   data-bs-html="true"
                                   data-bs-content="PHP date format codes:<br><b>m</b> = month (01-12)<br><b>d</b> = day (01-31)<br><b>Y</b> = 4-digit year<br><b>y</b> = 2-digit year<br><b>H</b> = 24h hour<br><b>h</b> = 12h hour<br><b>i</b> = minutes<br><b>A</b> = AM/PM<br><br>Examples:<br><code>m/d/Y</code> = 03/16/2026<br><code>d-m-Y</code> = 16-03-2026<br><code>Y-m-d</code> = 2026-03-16"
                                   title="Date format help"></i>
                            </label>
                            <input type="text" class="form-control form-control-sm" id="setDateFmt" data-key="date_format" maxlength="20" placeholder="m/d/Y">
                        </div>
                        <div class="col-md-3">
                            <label for="setRecentMins" class="form-label form-label-sm">
                                Recent Close (min)
                                <i class="bi bi-question-circle text-body-secondary" tabindex="0"
                                   data-bs-toggle="popover" data-bs-trigger="hover focus" data-bs-placement="top"
                                   data-bs-content="How many minutes after closing before an incident disappears from the dashboard and call board recent list. Default: 30 minutes."
                                   title="Recent close help"></i>
                            </label>
                            <input type="number" class="form-control form-control-sm" id="setRecentMins" data-key="recent_close_mins" min="1" max="1440" placeholder="30">
                        </div>
                        <!-- Phase 95-plus (2026-06-28) — admin-tunable stale-location threshold.
                             Used by the incident-detail Available Responders panel + assignments
                             table + units.php list. Exposed to JS via inc/navbar.php as
                             window.STALE_LOCATION_MIN. -->
                        <div class="col-md-3">
                            <label for="setStaleLocMin" class="form-label form-label-sm">
                                Stale Location (min)
                                <i class="bi bi-question-circle text-body-secondary" tabindex="0"
                                   data-bs-toggle="popover" data-bs-trigger="hover focus" data-bs-placement="top"
                                   data-bs-html="true"
                                   data-bs-content="A unit's last-known GPS location is flagged as <b>stale</b> when older than this many minutes. A yellow ⚠ icon appears next to the unit's distance on the incident-detail Available Responders panel + Assignments table + units.php list.<br><br>Range: 5&ndash;1440 minutes. Default: <b>30 min</b>.<br><br>Lower = stricter alerting (high-tempo ops). Higher = fewer false-positive warnings (standby).<br><br>Set to a very high number (e.g. 99999) to disable the stale flag entirely."
                                   title="Stale location help"></i>
                            </label>
                            <input type="number" class="form-control form-control-sm"
                                   id="setStaleLocMin"
                                   data-key="stale_location_threshold_minutes"
                                   min="5" max="1440" placeholder="30">
                        </div>
                        <!-- 2026-06-28 — admin-configurable distance unit.
                             Backend always stores haversine in km; JS converts at
                             render-time using window.formatDistanceKm (defined in
                             inc/navbar.php) so changing the unit takes effect on the
                             next page load with no backend round-trip. -->
                        <div class="col-md-3">
                            <label for="setDistanceUnit" class="form-label form-label-sm">
                                Distance Unit
                                <i class="bi bi-question-circle text-body-secondary" tabindex="0"
                                   data-bs-toggle="popover" data-bs-trigger="hover focus" data-bs-placement="top"
                                   data-bs-html="true"
                                   data-bs-content="How to display distance from incident to responder on the dispatcher views (Available Responders, Assignments, search dropdown).<br><br><b>Statute miles</b> — US standard. The mile used on highway signs, speedometers, etc. Default.<br><br><b>Kilometers</b> — metric. Use outside the US.<br><br><b>Nautical miles</b> — 1.151 statute miles; the unit used for marine and aviation operations. Useful for Coast Guard auxiliaries, marine SAR, coastal ARES groups.<br><br>The internal haversine calculation is the same; only the display label and conversion factor change."
                                   title="Distance unit help"></i>
                            </label>
                            <select class="form-select form-select-sm"
                                    id="setDistanceUnit"
                                    data-key="distance_unit">
                                <option value="mi">Statute miles (mi)</option>
                                <option value="km">Kilometers (km)</option>
                                <option value="nmi">Nautical miles (nmi)</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="settings-group">
                    <div class="settings-group-title">Phone Number Formatting</div>
                    <div class="row g-2">
                        <div class="col-md-4">
                            <label for="setPhoneFormat" class="form-label form-label-sm">Auto-Format Phone Numbers</label>
                            <select class="form-select form-select-sm" id="setPhoneFormat" data-key="phone_format">
                                <option value="off">Off (store as entered)</option>
                                <option value="us">(555) 555-1234</option>
                                <option value="dash">555-555-1234</option>
                                <option value="dots">555.555.1234</option>
                            </select>
                            <div class="form-text">Formats 10-digit US phone numbers on save. Does not modify non-US or short numbers.</div>
                        </div>
                    </div>
                </div>

                <div class="d-flex gap-2 mt-3">
                    <button type="submit" class="btn btn-sm btn-success"><i class="bi bi-check-lg me-1"></i>Save Settings</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btnReloadSettings"><i class="bi bi-arrow-counterclockwise me-1"></i>Reload</button>
                </div>
            </form>
        </div>

        <!-- ── Database Info & Legacy Migration ────────────────────────── -->
        <div class="config-panel" id="panel-database-info">
            <div class="config-panel-title">
                <i class="bi bi-database text-info"></i> Database &amp; Legacy Migration
            </div>

            <div class="alert alert-info py-2 mb-3 small" id="dbTfaKeyWarning">
                <i class="bi bi-info-circle me-1"></i>
                <strong>Database password &amp; 2FA:</strong> Check
                <a href="#two-factor-auth" class="alert-link config-quick-link">Two-Factor Auth &gt; Encryption Key Management</a>
                to verify your TFA key source before changing the database password.
                If using a dedicated key file, changing the DB password has no effect on 2FA.
            </div>

            <!-- Current DB info -->
            <div id="dbInfoContent">
                <div class="text-center py-3"><div class="spinner-border spinner-border-sm"></div> Loading...</div>
            </div>

            <!-- Legacy Migration section -->
            <div id="legacyMigrationSection" class="mt-3" style="display:none">
                <hr>
                <h6 class="mb-2"><i class="bi bi-arrow-left-right me-1"></i>Legacy Import</h6>
                <div id="legacyStatusBadge" class="mb-2"></div>

                <div class="row g-2 mb-3" id="legacyMigrationButtons" style="display:none">
                    <div class="col-auto">
                        <button class="btn btn-sm btn-outline-primary" id="btnPreviewMigration">
                            <i class="bi bi-eye me-1"></i>Preview Migration
                        </button>
                    </div>
                    <div class="col-auto">
                        <button class="btn btn-sm btn-primary" id="btnRunMigration" disabled>
                            <i class="bi bi-play-fill me-1"></i>Run Settings Migration
                        </button>
                    </div>
                    <div class="col-auto">
                        <button class="btn btn-sm btn-outline-secondary" id="btnMigrateUsers" disabled>
                            <i class="bi bi-people me-1"></i>Import Users
                        </button>
                    </div>
                    <div class="col-auto">
                        <button class="btn btn-sm btn-outline-secondary" id="btnMigrateTypes" disabled>
                            <i class="bi bi-tags me-1"></i>Import Incident Types
                        </button>
                    </div>
                </div>

                <!-- Preview results -->
                <div id="migrationPreview" style="display:none"></div>

                <!-- Migration log -->
                <div id="migrationLog" style="display:none">
                    <h6 class="mt-3 mb-1"><i class="bi bi-journal-text me-1"></i>Migration Log</h6>
                    <div id="migrationLogContent" class="small" style="max-height:300px;overflow-y:auto"></div>
                </div>
            </div>
        </div>

        <!-- ── Backup / Maintenance ──────────────────────────────────── -->
        <div class="config-panel" id="panel-backup">
            <div class="config-panel-title">
                <i class="bi bi-archive text-warning"></i> Backup / Maintenance
            </div>

            <!-- Download Full Backup -->
            <div class="settings-group mb-3">
                <div class="settings-group-title">Download Full Backup</div>
                <p class="text-body-secondary small mb-2">
                    Generate a complete database backup (.zip) containing a SQL dump of all tables
                    and a JSON snapshot of system configuration. Download directly to your browser.
                </p>
                <div class="d-flex align-items-center gap-3">
                    <button class="btn btn-primary" id="btnBackupDownload">
                        <i class="bi bi-cloud-download me-1"></i>Download Full Backup
                    </button>
                    <span class="text-body-secondary small" id="backupDownloadStatus"></span>
                </div>
                <div class="alert alert-warning small mt-2 mb-0 py-2">
                    <i class="bi bi-exclamation-triangle-fill me-1"></i>
                    <strong>This backup does NOT include_once encryption key files.</strong> You must also back up these files separately to fully recover your system:
                    <ul class="mb-0 mt-1">
                        <li><code>../keys/tfa.key</code> &mdash; <strong>2FA encryption key.</strong> Without this file, all two-factor authentication enrollments
                            (TOTP secrets, backup codes) are permanently unrecoverable. Every user would need to re-enroll in 2FA.</li>
                        <li><code>../keys/private.pem</code> &mdash; <strong>RSA field encryption private key.</strong> Used to decrypt sensitive form submissions
                            (passwords, patient data) sent over HTTP. Can be regenerated if lost, but any in-flight encrypted submissions would fail.</li>
                        <li><code>../keys/public.pem</code> &mdash; RSA public key (regenerated automatically with the private key).</li>
                    </ul>
                    <div class="mt-1">
                        <strong>Recommendation:</strong> Copy the <code>../keys/</code> directory to a secure, offline location (encrypted USB drive, hardware safe)
                        whenever you take a database backup. Never store key files in version control or cloud sync folders.
                    </div>
                </div>
            </div>

            <!-- Save to Server -->
            <div class="settings-group mb-3">
                <div class="settings-group-title">Save to Server Filesystem</div>
                <p class="text-body-secondary small mb-2">
                    Save a backup directly to a directory on the server. Useful for automated backup workflows or scheduled tasks.
                </p>
                <div class="row g-2 align-items-end">
                    <div class="col-md-7">
                        <label class="form-label form-label-sm">Backup Directory</label>
                        <input type="text" class="form-control form-control-sm" id="backupPath"
                               value="<?php echo e(NEWUI_ROOT . '/backups'); ?>"
                               placeholder="Server path for backup storage">
                    </div>
                    <div class="col-md-3">
                        <button class="btn btn-sm btn-outline-primary" id="btnBackupFilesystem">
                            <i class="bi bi-hdd me-1"></i>Save to Server
                        </button>
                    </div>
                    <div class="col-md-2">
                        <span class="small" id="backupFsStatus"></span>
                    </div>
                </div>
            </div>

            <!-- Backup History -->
            <div class="settings-group mb-3">
                <div class="settings-group-title">
                    Backup History
                    <button class="btn btn-sm btn-outline-secondary ms-2 py-0 px-1" id="btnRefreshHistory" title="Refresh">
                        <i class="bi bi-arrow-clockwise"></i>
                    </button>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0" style="font-size:0.8rem">
                        <thead>
                            <tr>
                                <th>Filename</th>
                                <th>Size</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody id="backupHistoryBody">
                            <tr><td colspan="3" class="text-center text-body-secondary py-3">No backups found</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Restore Instructions -->
            <details class="small text-body-secondary">
                <summary class="fw-semibold mb-1 cursor-pointer"><i class="bi bi-question-circle me-1"></i>Restore Instructions</summary>
                <div class="ps-3 mt-1">
                    <p class="mb-1"><strong>1. Database Restore:</strong></p>
                    <code class="d-block bg-body-tertiary p-2 rounded mb-2 user-select-all">mysql -u newui -p newui &lt; backup.sql</code>
                    <p class="mb-1">On Windows XAMPP:</p>
                    <code class="d-block bg-body-tertiary p-2 rounded mb-2 user-select-all">C:\xampp\8.2.4\mysql\bin\mysql.exe -u newui -p newui &lt; backup.sql</code>
                    <p class="mb-1"><strong>2. Encryption Keys:</strong> Restore <code>../keys/tfa.key</code> and <code>../keys/private.pem</code> from your separate key backup.</p>
                    <p class="mb-0"><strong>3. Config Review:</strong> Open <code>config.json</code> to verify settings. Sensitive values are masked and must be re-entered.</p>
                </div>
            </details>

            <hr class="my-3">

            <!-- CSV Import/Export Link -->
            <div class="d-flex align-items-center gap-2">
                <a href="import-export.php" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-arrow-left-right me-1"></i>CSV Import / Export Tool
                </a>
                <span class="text-body-secondary small">Import or export individual tables as CSV files</span>
            </div>
        </div>

        <!-- ── Login Settings ────────────────────────────────────────── -->
        <div class="config-panel" id="panel-login-settings">
            <div class="config-panel-title">
                <i class="bi bi-box-arrow-in-right text-primary"></i> Login Settings
            </div>
            <form id="loginSettingsForm">
                <div class="settings-group">
                    <div class="settings-group-title">General</div>
                    <div class="row g-2">
                        <div class="col-md-4">
                            <label class="form-label form-label-sm">Default Session Timeout (minutes)</label>
                            <input type="number" class="form-control form-control-sm" data-key="session_timeout_minutes" value="480" min="5" max="14400">
                            <div class="form-text">
                                Used as the fallback when a role has <em>no</em> per-role timeout set. Default: 480 min (8 hr). Per-role overrides live in the
                                <a href="#sessionTimeoutsSection" onclick="document.getElementById('roleTimeoutsGrid')?.scrollIntoView({behavior:'smooth'});return false;">Per-Role Session Timeouts</a>
                                section below.
                            </div>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label form-label-sm">Login Banner Text</label>
                            <input type="text" class="form-control form-control-sm" data-key="login_banner" placeholder="Displayed on the login page">
                        </div>
                    </div>
                    <div class="row g-2 mt-1">
                        <div class="col-md-6">
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" data-key="login_userlist" id="setShowUserList">
                                <label class="form-check-label" for="setShowUserList">Show user list on login page</label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" data-key="require_https" id="setRequireHttps">
                                <label class="form-check-label" for="setRequireHttps">Require HTTPS</label>
                            </div>
                        </div>
                    </div>
                    <div class="row g-2 mt-1">
                        <div class="col-12">
                            <div class="form-check form-switch">
                                <input type="checkbox" class="form-check-input" data-key="force_pw_change_for_new_users" id="setForcePwChangeNew" role="switch">
                                <label class="form-check-label" for="setForcePwChangeNew">
                                    <strong><?php echo e(t('login_settings.force_pw_default', 'All new users must choose their own password on first login')); ?></strong>
                                </label>
                                <div class="form-text">
                                    <?php echo e(t('login_settings.force_pw_default_hint', 'When you create a new user account, default the per-user "Require password reset at next login" toggle to ON. Admin can still override per-user.')); ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Phase 99i (Billy beta 2026-06-29) — CJIS click-
                     through notice. Required by CJIS-aligned deploys
                     so the user explicitly accepts system-use terms
                     before authentication. Notice text is admin-
                     editable; click-through is logged in audit_log
                     on every successful login with the notice on. -->
                <div class="settings-group">
                    <div class="settings-group-title">CJIS Login Notice</div>
                    <div class="row g-2">
                        <div class="col-12">
                            <div class="form-check form-switch">
                                <input type="checkbox" class="form-check-input" data-key="cjis_login_notice_enabled" id="setCjisNoticeEnabled" role="switch">
                                <label class="form-check-label" for="setCjisNoticeEnabled">
                                    <strong>Show system-use notice on login page (CJIS click-through)</strong>
                                </label>
                                <div class="form-text">
                                    When enabled, the notice text below is shown above the login form. Users must check "I have read and agree" before the Log In button is active. Acceptance is recorded in the audit log with each login.
                                </div>
                            </div>
                        </div>
                        <div class="col-12 mt-2">
                            <label class="form-label form-label-sm">Notice text</label>
                            <textarea class="form-control form-control-sm font-monospace" data-key="cjis_login_notice_text" rows="10"
                                      placeholder="Notice content shown above the login form. Plain text (line breaks preserved)."></textarea>
                            <div class="form-text">
                                Line breaks are preserved. Keep under ~2 KB so the banner doesn't dominate the login viewport. Default text on first install is a generic government-system warning; replace with your agency's required wording.
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Phase 37: Per-Role Session Timeouts driven by roles table -->
                <div class="settings-group">
                    <div class="settings-group-title">
                        Per-Role Session Timeouts
                        <i class="bi bi-question-circle text-body-secondary" tabindex="0"
                           data-bs-toggle="popover" data-bs-trigger="hover focus" data-bs-placement="top"
                           data-bs-content="Override the default session timeout for specific roles. Leave blank to use the default timeout above. When a user belongs to multiple roles, the SHORTEST role timeout wins."
                           title="Per-role timeout help"></i>
                    </div>
                    <p class="text-body-secondary small mb-2">
                        Per-role timeouts pulled live from the
                        <a href="#roles-levels" data-tab-link="roles-levels">Roles &amp; Permissions</a>
                        table. Leave blank to inherit the default above.
                        When a user holds multiple roles, the shortest timeout wins.
                    </p>
                    <div id="roleTimeoutsGrid" class="row g-2">
                        <div class="col-12 text-body-secondary small">Loading roles…</div>
                    </div>
                    <div class="mt-2">
                        <button type="button" class="btn btn-sm btn-outline-success" id="btnSaveRoleTimeouts">
                            <i class="bi bi-check-lg me-1"></i>Save Role Timeouts
                        </button>
                        <span class="ms-2 small text-body-secondary" id="roleTimeoutsStatus"></span>
                    </div>
                </div>

                <div class="settings-group">
                    <div class="settings-group-title">
                        <?php echo e(t('pw_policy.section_title', 'Password Policy')); ?>
                    </div>
                    <p class="text-body-secondary small mb-2">
                        <?php echo e(t('pw_policy.section_blurb', 'CJIS Security Policy v6.0 (aligned with NIST SP 800-63B) recommends: minimum 8 characters, history of ≥10, no forced periodic rotation. See docs/SECURITY-POLICY.md.')); ?>
                    </p>
                    <div class="row g-2">
                        <div class="col-md-3">
                            <label class="form-label form-label-sm"><?php echo e(t('pw_policy.label_min', 'Minimum length')); ?></label>
                            <input type="number" class="form-control form-control-sm pw-policy-input" id="setPwMinLength" data-key="password_min_length" data-cjis-min="8" min="4" max="256" value="8">
                            <div class="form-text">
                                <?php echo e(t('pw_policy.hint_min', 'CJIS recommended: 8 or more.')); ?>
                            </div>
                            <div class="text-warning small d-none cjis-warn" id="warnPwMinLength">
                                <i class="bi bi-exclamation-triangle me-1"></i><?php echo e(t('pw_policy.warn_min', 'Below CJIS recommended minimum (8). Does not meet CJIS standards.')); ?>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label form-label-sm"><?php echo e(t('pw_policy.label_history', 'History count')); ?></label>
                            <input type="number" class="form-control form-control-sm pw-policy-input" id="setPwHistory" data-key="password_history_count" data-cjis-min="10" min="0" max="100" value="10">
                            <div class="form-text">
                                <?php echo e(t('pw_policy.hint_history', 'Last N passwords retained. CJIS recommended: 10 or more.')); ?>
                            </div>
                            <div class="text-warning small d-none cjis-warn" id="warnPwHistory">
                                <i class="bi bi-exclamation-triangle me-1"></i><?php echo e(t('pw_policy.warn_history', 'Below CJIS recommended (10). Reuse may go undetected.')); ?>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label form-label-sm"><?php echo e(t('pw_policy.label_rotation_days', 'Rotation reminder (days)')); ?></label>
                            <input type="number" class="form-control form-control-sm" data-key="password_rotation_reminder_days" min="0" max="3650" value="180">
                            <div class="form-text">
                                <?php echo e(t('pw_policy.hint_rotation_days', 'Days before showing the "consider rotating" banner. 0 = disabled.')); ?>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label form-label-sm"><?php echo e(t('pw_policy.label_snooze_days', 'Reminder snooze (days)')); ?></label>
                            <input type="number" class="form-control form-control-sm" data-key="password_rotation_snooze_days" min="0" max="365" value="10">
                            <div class="form-text">
                                <?php echo e(t('pw_policy.hint_snooze_days', 'After "Remind Me Later", days before next reminder. 0 = re-prompt every login.')); ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="settings-group">
                    <div class="settings-group-title">
                        <?php echo e(t('trusted_proxies.section_title', 'Trusted Reverse Proxies')); ?>
                    </div>
                    <p class="text-body-secondary small mb-2">
                        <?php echo e(t('trusted_proxies.section_blurb', 'Comma-separated list of IPs / CIDR ranges trusted to forward X-Forwarded-For / X-Real-IP headers. When the direct connection matches one of these, the audit log will record the originating client IP from the proxy headers. Otherwise it records the direct connection. Leave at the safe default (localhost) for single-host deployments; expand if proxies sit on other hosts.')); ?>
                    </p>
                    <div class="row g-2">
                        <div class="col-12">
                            <label class="form-label form-label-sm"><?php echo e(t('trusted_proxies.label', 'Trusted proxy IPs / CIDR')); ?></label>
                            <input type="text" class="form-control form-control-sm"
                                   data-key="trusted_proxies"
                                   value="127.0.0.1,::1"
                                   placeholder="127.0.0.1,::1,10.0.0.0/24">
                            <div class="form-text">
                                <?php echo e(t('trusted_proxies.hint', 'Default: 127.0.0.1,::1 (covers single-host NPM / nginx). Add CIDR ranges (e.g., 10.0.0.0/8) for proxies on other hosts.')); ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="settings-group">
                    <div class="settings-group-title">Account Lockout</div>
                    <p class="text-body-secondary small mb-2">
                        Temporarily lock accounts after repeated failed login attempts to prevent brute-force attacks.
                    </p>
                    <div class="row g-2">
                        <div class="col-md-4">
                            <label class="form-label form-label-sm">Max Failed Attempts</label>
                            <input type="number" class="form-control form-control-sm" data-key="lockout_max_attempts" value="5" min="1" max="50">
                            <div class="form-text">Default: 5</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label form-label-sm">Time Window (minutes)</label>
                            <input type="number" class="form-control form-control-sm" data-key="lockout_window_minutes" value="15" min="1" max="1440">
                            <div class="form-text">Default: 15 min</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label form-label-sm">Lockout Duration (minutes)</label>
                            <input type="number" class="form-control form-control-sm" data-key="lockout_duration_minutes" value="30" min="1" max="1440">
                            <div class="form-text">Default: 30 min</div>
                        </div>
                    </div>
                </div>

                <div class="mt-2">
                    <button type="submit" class="btn btn-sm btn-success"><i class="bi bi-check-lg me-1"></i>Save</button>
                    <span id="loginSettingsStatus" class="ms-2 small text-success"></span>
                </div>
            </form>

            <!-- Phase 10 (2026-06-08): Admin Password Reset with required
                 reason field. Calls api/login-security.php action=reset_password.
                 The reason is logged verbatim in the audit log entry.
                 On success: target user's password is rewritten, history is
                 updated, must_change_password=1 is set (Phase 9), and all
                 their existing sessions are killed. -->
            <div class="settings-group mt-3">
                <div class="settings-group-title">
                    <i class="bi bi-key me-1"></i><?php echo e(t('admin_reset.section_title', 'Reset User Password')); ?>
                </div>
                <p class="text-body-secondary small mb-2">
                    <?php echo e(t('admin_reset.section_blurb', 'Reset a user\'s password. They will be required to change it on next login. All their active sessions will be terminated. A reason is required for the CJIS audit trail.')); ?>
                </p>
                <form id="adminResetPwForm" class="row g-2 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label form-label-sm"><?php echo e(t('admin_reset.label_user', 'User')); ?> <span class="text-danger">*</span></label>
                        <select class="form-select form-select-sm" id="adminResetUserId" required>
                            <option value="">— <?php echo e(t('admin_reset.select_user', 'Select a user')); ?> —</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label form-label-sm"><?php echo e(t('admin_reset.label_new_pw', 'New Password')); ?> <span class="text-danger">*</span></label>
                        <input type="password" class="form-control form-control-sm" id="adminResetNewPw" required autocomplete="new-password">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label form-label-sm"><?php echo e(t('admin_reset.label_reason', 'Reason for reset')); ?> <span class="text-danger">*</span></label>
                        <input type="text" class="form-control form-control-sm" id="adminResetReason"
                               required minlength="3" maxlength="2000"
                               placeholder="<?php echo e(t('admin_reset.placeholder_reason', 'E.g., User reported forgotten password during shift change')); ?>">
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-sm btn-warning w-100">
                            <i class="bi bi-key-fill me-1"></i><?php echo e(t('admin_reset.btn_submit', 'Reset')); ?>
                        </button>
                    </div>
                </form>
                <div class="form-text mt-1">
                    <?php echo e(t('admin_reset.cjis_note', 'CJIS compliance: every admin password reset is logged with a reason. The reset user is forced to change the password on next login.')); ?>
                </div>
            </div>

            <!-- Active Sessions -->
            <div class="settings-group mt-3">
                <div class="settings-group-title">
                    <i class="bi bi-person-badge me-1"></i>Active Sessions
                    <button class="btn btn-sm btn-outline-secondary ms-2" id="btnRefreshSessions" type="button">
                        <i class="bi bi-arrow-clockwise"></i>
                    </button>
                </div>
                <div id="activeSessionsContent">
                    <div class="text-center py-2"><div class="spinner-border spinner-border-sm"></div></div>
                </div>
            </div>

            <!-- Recent Login Attempts -->
            <div class="settings-group mt-3">
                <div class="settings-group-title">
                    <i class="bi bi-shield-exclamation me-1"></i>Recent Login Attempts
                    <button class="btn btn-sm btn-outline-secondary ms-2" id="btnRefreshAttempts" type="button">
                        <i class="bi bi-arrow-clockwise"></i>
                    </button>
                </div>
                <div id="loginAttemptsContent">
                    <div class="text-center py-2"><div class="spinner-border spinner-border-sm"></div></div>
                </div>
            </div>
        </div>

        <!-- ── Two-Factor Authentication ──────────────────────────── -->
        <div class="config-panel" id="panel-two-factor-auth">
            <div class="config-panel-title">
                <i class="bi bi-shield-lock text-warning"></i> Two-Factor Authentication (TOTP)
            </div>
            <p class="text-body-secondary small mb-3">
                Configure TOTP-based two-factor authentication for user accounts.
                Users can enroll via their profile or an admin can require it for specific roles.
            </p>

            <!-- Global Settings (admin) -->
            <div class="settings-group mb-3">
                <div class="fw-semibold small mb-2">Global Settings</div>
                <div class="row g-2">
                    <div class="col-md-4">
                        <div class="form-check form-switch">
                            <input type="checkbox" class="form-check-input" id="tfaGlobalEnabled">
                            <label class="form-check-label" for="tfaGlobalEnabled">Enable 2FA System-Wide</label>
                        </div>
                        <div class="form-text small">When off, 2FA is completely disabled even for enrolled users.</div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label form-label-sm">Remember Device (days)</label>
                        <input type="number" class="form-control form-control-sm" id="tfaRememberDays" min="1" max="365" value="30">
                    </div>
                </div>
            </div>

            <div class="settings-group mb-3">
                <div class="fw-semibold small mb-2">Required Roles</div>
                <p class="text-body-secondary small mb-2">
                    Users with these roles must enable 2FA. Leave all unchecked to make 2FA optional for everyone.
                </p>
                <div class="row g-2">
                    <div class="col-md-3">
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input tfa-role-check" value="0" id="tfaRole0">
                            <label class="form-check-label small" for="tfaRole0">Super Admin (0)</label>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input tfa-role-check" value="1" id="tfaRole1">
                            <label class="form-check-label small" for="tfaRole1">Administrator (1)</label>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input tfa-role-check" value="2" id="tfaRole2">
                            <label class="form-check-label small" for="tfaRole2">Operator (2)</label>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input tfa-role-check" value="3" id="tfaRole3">
                            <label class="form-check-label small" for="tfaRole3">Guest (3)</label>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input tfa-role-check" value="4" id="tfaRole4">
                            <label class="form-check-label small" for="tfaRole4">Member (4)</label>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input tfa-role-check" value="5" id="tfaRole5">
                            <label class="form-check-label small" for="tfaRole5">Unit (5)</label>
                        </div>
                    </div>
                </div>
            </div>

            <div class="settings-group mb-3">
                <div class="fw-semibold small mb-2">Trusted Networks (CIDR)</div>
                <p class="text-body-secondary small mb-2">
                    Only devices on these networks can use "Remember this device." One CIDR per line.
                    Default: private RFC 1918 ranges.
                </p>
                <textarea class="form-control form-control-sm" id="tfaTrustedCidrs" rows="4" placeholder="10.0.0.0/8
172.16.0.0/12
192.168.0.0/16"></textarea>
            </div>

            <div class="d-flex gap-2 mb-4">
                <button type="button" class="btn btn-sm btn-success" id="btnSaveTfaSettings">
                    <i class="bi bi-check-lg me-1"></i>Save 2FA Settings
                </button>
                <span class="text-success small align-self-center d-none" id="tfaSaveOk">Saved.</span>
            </div>

            <hr>

            <!-- Admin: Force-disable 2FA for a user -->
            <div class="settings-group">
                <div class="fw-semibold small mb-2">Admin: Force-Disable 2FA for a User</div>
                <p class="text-body-secondary small mb-2">
                    Super admins can force-disable 2FA for any user account (e.g., if they lost their device).
                </p>
                <div class="row g-2 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label form-label-sm">User ID</label>
                        <input type="number" class="form-control form-control-sm" id="tfaForceUserId" placeholder="Enter user ID" min="1">
                    </div>
                    <div class="col-md-4">
                        <button type="button" class="btn btn-sm btn-outline-danger" id="btnForceDisableTfa">
                            <i class="bi bi-shield-x me-1"></i>Force Disable
                        </button>
                    </div>
                    <div class="col-md-4">
                        <span class="text-body-secondary small" id="tfaForceStatus"></span>
                    </div>
                </div>
            </div>

            <hr>

            <!-- TFA Encryption Key Management -->
            <div class="settings-group">
                <div class="fw-semibold small mb-2"><i class="bi bi-key me-1"></i>Encryption Key Management</div>
                <p class="text-body-secondary small mb-2">
                    TOTP secrets are encrypted at rest in the database using AES-256-CBC.
                    The encryption key can be stored as a dedicated file (recommended) or
                    derived from the database password (legacy).
                </p>

                <div class="table-responsive mb-2">
                    <table class="table table-sm mb-0" style="font-size:0.8rem">
                        <caption class="visually-hidden">Two-factor authentication encryption-key status</caption>
                        <tbody>
                            <tr>
                                <th scope="row" class="text-body-secondary fw-normal" style="width:180px">Key Source</th>
                                <td><span class="badge" id="tfaKeySource">Checking...</span></td>
                            </tr>
                            <tr>
                                <th scope="row" class="text-body-secondary fw-normal">Key File</th>
                                <td class="font-monospace small" id="tfaKeyFile">--</td>
                            </tr>
                            <tr>
                                <th scope="row" class="text-body-secondary fw-normal">Enrollments</th>
                                <td id="tfaKeyEnrollments">--</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div id="tfaKeyActions" class="d-flex gap-2 flex-wrap">
                    <button type="button" class="btn btn-sm btn-outline-primary d-none" id="btnTfaMigrateKey">
                        <i class="bi bi-arrow-left-right me-1"></i>Migrate to Dedicated Key
                    </button>
                    <span class="text-body-secondary small align-self-center" id="tfaKeyStatus"></span>
                </div>

                <div id="tfaKeyWarningBox">
                    <!-- Populated dynamically by JS based on actual key source -->
                </div>
            </div>
        </div>

        <!-- ── Field Encryption ─────────────────────────────────────── -->
        <div class="config-panel" id="panel-field-encryption">
            <div class="config-panel-title">
                <i class="bi bi-shield-lock text-success"></i> Field Encryption (HTTP)
            </div>
            <p class="small text-body-secondary mb-3">
                When enabled, sensitive form fields (passwords, patient data) are encrypted
                with RSA in the browser before submission over HTTP. This protects data in transit
                when HTTPS is not available. If the site is served over HTTPS, encryption is
                bypassed automatically since TLS already protects the connection.
            </p>
            <form id="fieldEncryptForm">
                <div class="settings-group">
                    <div class="row g-2 align-items-center">
                        <div class="col-md-6">
                            <div class="form-check form-switch">
                                <input type="checkbox" class="form-check-input" id="setFieldEncrypt" data-key="field_encrypt_enabled">
                                <label class="form-check-label" for="setFieldEncrypt">Encrypt form fields over HTTP</label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="d-flex align-items-center gap-2">
                                <span class="small text-body-secondary">HTTPS Status:</span>
                                <span class="badge" id="feHttpsStatus">Checking...</span>
                            </div>
                        </div>
                    </div>
                    <div class="row g-2 mt-2">
                        <div class="col-md-6">
                            <div class="d-flex align-items-center gap-2">
                                <span class="small text-body-secondary">Key Status:</span>
                                <span class="badge" id="feKeyStatus">Checking...</span>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <span class="small text-body-secondary" id="feKeyCreated"></span>
                        </div>
                    </div>
                </div>
                <div class="mt-2 d-flex gap-2">
                    <button type="submit" class="btn btn-sm btn-success"><i class="bi bi-check-lg me-1"></i>Save</button>
                    <button type="button" class="btn btn-sm btn-outline-warning" id="btnRegenKeys">
                        <i class="bi bi-arrow-repeat me-1"></i>Regenerate Keys
                    </button>
                </div>
            </form>

            <div class="settings-group mt-3">
                <div class="settings-group-title"><i class="bi bi-info-circle me-1"></i>Key Lifecycle</div>
                <div class="small text-body-secondary">
                    <ul class="mb-1 ps-3">
                        <li><strong>RSA keys</strong> protect form data <em>in transit only</em> (not stored encrypted). Regenerating keys is safe for normal operations &mdash; old keys are archived automatically.</li>
                        <li><strong>2FA secrets</strong> are encrypted at rest using AES-256-CBC. The encryption key is managed separately &mdash; see
                            <a href="#two-factor-auth" class="config-quick-link">Two-Factor Auth &gt; Encryption Key Management</a> for details and backup guidance.</li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- ── Roles & Permissions ────────────────────────────────────── -->
        <div class="config-panel" id="panel-roles-levels">
            <div class="config-panel-title">
                <i class="bi bi-shield-lock text-danger"></i> Roles &amp; Permissions
            </div>

            <!-- 2026-06-11 — Permission audit banner. Surfaces permissions
                 that have been added to the system but aren't granted to
                 any administrable role yet. Each new feature ship should
                 take this list to zero by granting reasonable defaults. -->
            <div class="alert alert-warning d-none mb-3" id="permAuditBanner" role="alert">
                <div class="d-flex align-items-start">
                    <i class="bi bi-shield-exclamation me-2 fs-5"></i>
                    <div class="flex-grow-1">
                        <strong>Permission audit:</strong>
                        <span id="permAuditSummary">—</span>
                        <!-- 2026-06-29 (Phase 99u-2) — direct link to the
                             matrix where admins can grant/dismiss in one
                             place. Renders alongside the existing "Show
                             details" inline drill-down so admins can pick
                             the surface they prefer. -->
                        <a href="roles-matrix.php" class="btn btn-sm btn-warning ms-2">
                            <i class="bi bi-grid-3x3-gap me-1"></i>Open matrix to review
                        </a>
                        <button type="button" class="btn btn-sm btn-outline-warning ms-2" id="btnPermAuditToggle">
                            Show details
                        </button>
                    </div>
                </div>
                <div class="mt-2 small d-none" id="permAuditDetails"></div>
            </div>

            <div class="row g-3">
                <!-- Role list (left) -->
                <div class="col-md-4">
                    <div class="d-flex align-items-center mb-2">
                        <span class="fw-semibold">Roles</span>
                        <button class="btn btn-sm btn-outline-primary ms-auto" id="btnNewRole">
                            <i class="bi bi-plus-lg me-1"></i>New Role
                        </button>
                    </div>
                    <div id="rbacRoleList">
                        <div class="text-center py-3"><div class="spinner-border spinner-border-sm"></div></div>
                    </div>

                    <!-- Migration button.
                         Phase 11 (2026-06-11): wrapped in #migrateLegacyWrap
                         so it can be hidden once all users have RBAC grants.
                         JS calls /api/rbac.php?action=migration_status on
                         panel load and shows/hides accordingly. The "no
                         migration needed" message replaces the button. -->
                    <div class="border-top pt-2 mt-3" id="migrateLegacyWrap" style="display:none;">
                        <button class="btn btn-sm btn-outline-warning w-100" id="btnMigrateLevels">
                            <i class="bi bi-arrow-repeat me-1"></i><?php echo e(t('migrate_legacy.button', 'Migrate Legacy Accounts to Roles')); ?>
                        </button>
                        <div class="small text-body-secondary mt-1" id="migrateLegacyHint">
                            <?php echo e(t('migrate_legacy.hint', 'Assigns roles to any user accounts carried over from a legacy installation. Safe to run multiple times. Once every account has a role, this option disappears.')); ?>
                        </div>
                    </div>
                    <div class="border-top pt-2 mt-3" id="migrateLegacyDone" style="display:none;">
                        <div class="text-success small">
                            <i class="bi bi-check-circle-fill me-1"></i>
                            <?php echo e(t('migrate_legacy.done', 'All user accounts are on the Roles & Permissions system.')); ?>
                        </div>
                    </div>
                </div>

                <!-- Permission matrix (right) -->
                <div class="col-md-8">
                    <div id="rbacPermPanel">
                        <div class="text-center text-body-secondary py-4">
                            <i class="bi bi-shield-check display-6 d-block mb-2 opacity-25"></i>
                            <span class="small">Select a role to view and edit its permissions</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── RBAC v2 settings (rbac-redesign-2026-05) ─────────── -->
            <div class="border-top mt-4 pt-3">
                <div class="d-flex align-items-center mb-2">
                    <span class="fw-semibold">RBAC Settings</span>
                    <small class="text-body-secondary ms-2">Phase 2 features</small>
                </div>
                <div class="row g-2">
                    <div class="col-md-6">
                        <label class="form-label form-label-sm" for="rbacSepApprover">
                            Require separate approver
                            <i class="bi bi-info-circle small text-body-tertiary"
                               data-bs-toggle="tooltip"
                               title="When ON, no user can approve their own time entry / record / form regardless of permissions. OFF (default) suits volunteer ops."></i>
                        </label>
                        <select class="form-select form-select-sm" id="rbacSepApprover" data-rbac-setting="rbac.require_separate_approver">
                            <option value="0">Off — self-approval allowed</option>
                            <option value="1">On — separate approver required</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label form-label-sm" for="rbacDelegMax">
                            Delegation max depth
                            <i class="bi bi-info-circle small text-body-tertiary"
                               data-bs-toggle="tooltip"
                               title="Maximum hop count for role-delegation chains. 0 disables delegation; 1 allows direct hand-offs only; higher values support layered orgs but make audit harder."></i>
                        </label>
                        <select class="form-select form-select-sm" id="rbacDelegMax" data-rbac-setting="rbac.delegation_max_depth">
                            <option value="0">0 — disabled</option>
                            <option value="1">1 — direct hand-off (recommended)</option>
                            <option value="2">2 — two hops</option>
                            <option value="3">3 — three hops</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label form-label-sm" for="rbacAutoApprove">
                            Time-entry auto-approve
                            <i class="bi bi-info-circle small text-body-tertiary"
                               data-bs-toggle="tooltip"
                               title="Off: every entry stays self_reported until approved. On: every entry auto-approves at create time. By activity type: only entries whose activity type has auto_approve=1 skip the queue (configure per-type below)."></i>
                        </label>
                        <select class="form-select form-select-sm" id="rbacAutoApprove" data-rbac-setting="rbac.time_entry_auto_approve">
                            <option value="off">Off — admin must approve</option>
                            <option value="on">On — auto-approve everything</option>
                            <option value="by_activity_type">By activity type</option>
                        </select>
                    </div>
                </div>
                <div class="mt-2 d-flex justify-content-end">
                    <button type="button" class="btn btn-sm btn-primary" id="btnSaveRbacSettings">
                        <i class="bi bi-save me-1"></i>Save RBAC Settings
                    </button>
                </div>
            </div>

            <!-- ── User Grants — time-bound, scoped role assignments ─── -->
            <div class="border-top mt-4 pt-3">
                <div class="d-flex align-items-center mb-2">
                    <span class="fw-semibold">User Grants</span>
                    <small class="text-body-secondary ms-2">View &amp; manage role assignments with scope and expiry</small>
                    <button class="btn btn-sm btn-outline-primary ms-auto" id="btnNewGrant" data-bs-toggle="modal" data-bs-target="#grantRoleModal">
                        <i class="bi bi-plus-lg me-1"></i>Grant Role
                    </button>
                </div>
                <div class="row g-2 mb-2">
                    <div class="col-md-6">
                        <input type="text" class="form-control form-control-sm" id="grantSearchUser" placeholder="Filter by username...">
                    </div>
                    <div class="col-md-3">
                        <select class="form-select form-select-sm" id="grantFilterScope">
                            <option value="">All scopes</option>
                            <option value="global">Global</option>
                            <option value="org">Org</option>
                            <option value="team">Team</option>
                            <option value="self">Self</option>
                            <option value="delegate">Delegate</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <select class="form-select form-select-sm" id="grantFilterExpiry">
                            <option value="active">Active only</option>
                            <option value="all">Include expired</option>
                            <option value="expiring">Expiring within 7d</option>
                        </select>
                    </div>
                </div>
                <div id="rbacGrantList" class="small">
                    <div class="text-center py-3"><div class="spinner-border spinner-border-sm"></div></div>
                </div>
            </div>

            <!-- ── Audit Trail — RBAC grant / revoke / expiry history (specs/rbac-enforcement-2026-06) ─── -->
            <div class="border-top mt-4 pt-3">
                <div class="d-flex align-items-center mb-2">
                    <span class="fw-semibold">Audit Trail</span>
                    <small class="text-body-secondary ms-2">Role grants, revocations, and expiries from the audit log</small>
                    <button class="btn btn-sm btn-outline-secondary ms-auto" id="btnAuditExport">
                        <i class="bi bi-download me-1"></i>Export CSV
                    </button>
                </div>
                <div class="row g-2 mb-2">
                    <div class="col-md-4">
                        <input type="text" class="form-control form-control-sm" id="auditSearchUser" placeholder="Filter by username...">
                    </div>
                    <div class="col-md-4">
                        <select class="form-select form-select-sm" id="auditFilterActivity">
                            <option value="">All activities</option>
                            <option value="grant">Grant</option>
                            <option value="revoke">Revoke</option>
                            <option value="expire">Expire</option>
                        </select>
                    </div>
                    <div class="col-md-2"><input type="date" class="form-control form-control-sm" id="auditFromDate" title="From date"></div>
                    <div class="col-md-2"><input type="date" class="form-control form-control-sm" id="auditToDate" title="To date"></div>
                </div>
                <div id="auditLogList" class="small">
                    <div class="text-center py-3"><div class="spinner-border spinner-border-sm"></div></div>
                </div>
            </div>
        </div>

        <!-- ── Grant Role Modal ─────────────────────────────────────── -->
        <div class="modal fade" id="grantRoleModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="bi bi-shield-plus me-1"></i>Grant Role</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-2">
                            <label class="form-label form-label-sm">User <span class="text-danger">*</span></label>
                            <select class="form-select form-select-sm" id="grantUserId" required></select>
                        </div>
                        <div class="mb-2">
                            <label class="form-label form-label-sm">Role <span class="text-danger">*</span></label>
                            <select class="form-select form-select-sm" id="grantRoleId" required></select>
                        </div>
                        <div class="row g-2">
                            <div class="col-md-6">
                                <label class="form-label form-label-sm">Scope</label>
                                <select class="form-select form-select-sm" id="grantScopeKind">
                                    <option value="global">Global — applies everywhere</option>
                                    <option value="org">Org — only one organization</option>
                                    <option value="team">Team — only one team</option>
                                    <option value="self">Self — only on user's own resources</option>
                                    <option value="delegate">Delegate — temporary handoff</option>
                                </select>
                            </div>
                            <div class="col-md-6" id="grantScopeIdWrap" style="display:none;">
                                <label class="form-label form-label-sm" id="grantScopeIdLabel">Scope ID</label>
                                <input type="number" class="form-control form-control-sm" id="grantScopeId" min="1">
                            </div>
                        </div>
                        <div class="mb-2 mt-2">
                            <label class="form-label form-label-sm">
                                Expires
                                <small class="text-body-secondary">(leave blank for permanent)</small>
                            </label>
                            <input type="datetime-local" class="form-control form-control-sm" id="grantExpiresAt">
                        </div>
                        <div class="mb-2">
                            <label class="form-label form-label-sm">Reason</label>
                            <input type="text" class="form-control form-control-sm" id="grantReason" maxlength="255" placeholder="e.g. Pat on call 2026-06-01 to 2026-06-08">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-sm btn-primary" id="btnConfirmGrant">
                            <i class="bi bi-check-lg me-1"></i>Grant
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── User Accounts ───────────────────────────────────────── -->
        <div class="config-panel" id="panel-user-accounts">
            <div class="config-panel-title">
                <i class="bi bi-people text-success"></i> User Accounts
            </div>

            <div class="config-edit-panel" id="userEditPanel">
                <form id="userForm">
                    <input type="hidden" id="userId" name="id" value="">
                    <div class="row g-2">
                        <div class="col-md-4">
                            <label for="userName" class="form-label form-label-sm">Username <span class="text-danger">*</span></label>
                            <input type="text" class="form-control form-control-sm" id="userName" name="user" required maxlength="50">
                        </div>
                        <!-- Phase 11 (2026-06-11): "Role & Permissions set"
                             replaces the legacy Level dropdown. Populated
                             at form-open time from /api/config-admin.php
                             ?section=roles, so it reflects the live roles
                             list (including any custom roles the admin
                             created via Roles & Permissions). The hidden
                             #userLevel input is kept so older JS / form
                             collection helpers that read data.level still
                             see something coherent — populated from the
                             chosen role's legacy_level via JS. -->
                        <div class="col-md-3">
                            <label for="userRoleId" class="form-label form-label-sm">
                                <?php echo e(t('useracct.role_label', 'Role and permission group')); ?>
                                <span class="text-danger">*</span>
                            </label>
                            <select class="form-select form-select-sm" id="userRoleId" name="role_id" required>
                                <option value=""><?php echo e(t('useracct.role_placeholder', '— Select a role —')); ?></option>
                                <!-- Populated via JS in openUserForm() -->
                            </select>
                            <input type="hidden" id="userLevelDerived" name="level" value="">
                        </div>
                        <div class="col-md-3">
                            <label for="userPass" class="form-label form-label-sm">Password <small class="text-body-secondary">(blank = keep)</small></label>
                            <input type="password" class="form-control form-control-sm" id="userPass" name="password" autocomplete="new-password">
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="userCanLogin" name="can_login" checked>
                                <label class="form-check-label small" for="userCanLogin">Can Login</label>
                            </div>
                        </div>
                    </div>
                    <!-- Phase 99j-2 (Billy beta 2026-06-29) — org scoping.
                         Two org selectors:
                           Home Org = where this person primarily belongs
                                      (user.home_org_id). Always shown.
                           Role Scope = limit this role's powers to a
                                      specific org. Blank/"All orgs" =
                                      global (visible to all). Populated
                                      via JS in openUserForm(). -->
                    <div class="row g-2 mt-1">
                        <div class="col-md-4">
                            <label for="userHomeOrg" class="form-label form-label-sm">
                                Home Organization
                                <i class="bi bi-question-circle text-body-secondary" tabindex="0"
                                   data-bs-toggle="popover" data-bs-trigger="hover focus" data-bs-placement="top"
                                   data-bs-content="The user's primary affiliation. Defaults visible data to this org plus any descendant orgs."></i>
                            </label>
                            <select class="form-select form-select-sm" id="userHomeOrg" name="home_org_id">
                                <option value="">— Select home org —</option>
                                <!-- Populated via JS -->
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label for="userRoleOrgId" class="form-label form-label-sm">
                                Role Scope (org)
                                <i class="bi bi-question-circle text-body-secondary" tabindex="0"
                                   data-bs-toggle="popover" data-bs-trigger="hover focus" data-bs-placement="top"
                                   data-bs-content="Limits the role's powers to this org (and its descendants). Leave blank for a global grant. Set this when granting Org Admin to scope them to their org only."></i>
                            </label>
                            <select class="form-select form-select-sm" id="userRoleOrgId" name="role_org_id">
                                <option value="">— All orgs (global grant) —</option>
                                <!-- Populated via JS -->
                            </select>
                        </div>
                    </div>
                    <!-- Phase 10c (2026-06-11): inline reason field that appears
                         when an admin types a non-empty password while editing
                         an EXISTING user. CJIS requires a paper trail for every
                         admin password reset. Hidden on user-create and when
                         editing one's own account. JS reveals it via the
                         #userPass input listener in config.js. -->
                    <div class="row g-2 mt-1 d-none" id="adminResetReasonRow">
                        <div class="col-12">
                            <label class="form-label form-label-sm">
                                <?php echo e(t('admin_reset.label_reason', 'Reason for reset')); ?>
                                <span class="text-danger">*</span>
                                <small class="text-body-secondary">
                                    (<?php echo e(t('admin_reset.cjis_required', 'required for CJIS audit trail')); ?>)
                                </small>
                            </label>
                            <input type="text" class="form-control form-control-sm" id="userResetReason" name="reason"
                                   minlength="3" maxlength="2000"
                                   placeholder="<?php echo e(t('admin_reset.placeholder_reason', 'E.g., User reported forgotten password during shift change')); ?>">
                            <div class="form-text text-warning small">
                                <i class="bi bi-shield-exclamation me-1"></i>
                                <?php echo e(t('admin_reset.inline_warning', 'Resetting another user\'s password will log them out of all sessions and require them to choose a new password on next login.')); ?>
                            </div>
                        </div>
                    </div>
                    <div class="row g-2 mt-1">
                        <div class="col-md-6">
                            <label for="userMemberDisplay" class="form-label form-label-sm">Link to Member <small class="text-body-secondary">(roster record)</small></label>
                            <!--
                              Searchable combobox (specs/searchable-member-dropdown-2026-05).
                              The visible input filters the popover list; the hidden input
                              carries name="member" so the form POST is unchanged. JS wiring
                              lives in config.js openUserForm().
                            -->
                            <div class="searchable-select-wrap position-relative">
                                <input type="text"
                                       class="form-control form-control-sm searchable-select-input"
                                       id="userMemberDisplay" autocomplete="off"
                                       placeholder="Type a name or callsign, or click to browse">
                                <input type="hidden" id="userMember" name="member" value="">
                                <ul class="searchable-select-list list-group d-none"></ul>
                            </div>
                        </div>
                        <div class="col-md-6 d-flex align-items-end">
                            <!-- Phase 9 (2026-06-08): per-user force-password-change toggle.
                                 Default on CREATE comes from the system setting
                                 force_pw_change_for_new_users (Login Settings). On EDIT,
                                 reflects the current user.must_change_password value.
                                 See assets/js/config.js openUserForm(). -->
                            <div class="form-check mb-1">
                                <input class="form-check-input" type="checkbox" id="userForcePw" name="must_change_password">
                                <label class="form-check-label small" for="userForcePw">
                                    <?php echo e(t('useracct.force_pw', 'Require this user to reset their password at next login')); ?>
                                </label>
                            </div>
                        </div>
                    </div>
                    <!-- Admin info (read-only) + account unlock. Populated by openUserForm(). -->
                    <div class="border-top mt-3 pt-2" id="userAdminInfo" style="display:none">
                        <div class="row g-2 small align-items-center">
                            <div class="col-md-5">
                                <span class="text-body-secondary">Last login:</span>
                                <span id="userLastLogin" class="fw-semibold">&mdash;</span>
                            </div>
                            <div class="col-md-4">
                                <span class="text-body-secondary">Two-factor:</span>
                                <span id="userTfaStatus" class="fw-semibold">&mdash;</span>
                            </div>
                            <div class="col-md-3 text-end">
                                <button type="button" class="btn btn-sm btn-outline-warning" id="btnUnlockUser"
                                        title="Clear the lockout and failed-attempt counter for this account">
                                    <i class="bi bi-unlock me-1"></i>Unlock
                                </button>
                            </div>
                        </div>
                    </div>
                    <!--
                      No proactive warning banner here — admins were skimming
                      past it (per Eric 2026-06-02). The access-chain check
                      now runs at SAVE time and presents a confirm dialog
                      that requires an explicit Continue / Cancel decision.
                      See userMemberAccessCheckOnSubmit() in config.js.
                    -->
                    <div class="d-flex gap-2 mt-3">
                        <button type="submit" class="btn btn-sm btn-success"><i class="bi bi-check-lg me-1"></i>Save</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="btnCancelUser"><i class="bi bi-x-lg me-1"></i>Cancel</button>
                        <button type="button" class="btn btn-sm btn-outline-danger ms-auto d-none" id="btnDeleteUser"><i class="bi bi-trash me-1"></i>Delete</button>
                    </div>
                </form>
            </div>

            <div class="config-toolbar">
                <!-- Eric beta 2026-06-30 — filter inactive accounts (can_login = 0).
                     Default off so existing behaviour (show all) is preserved. -->
                <div class="form-check form-switch me-3 mb-0">
                    <input class="form-check-input" type="checkbox" id="usersHideInactive">
                    <label class="form-check-label small" for="usersHideInactive">Hide inactive accounts</label>
                </div>
                <button class="btn btn-sm btn-success ms-auto" id="btnAddUser"><i class="bi bi-plus-lg me-1"></i>Add User</button>
            </div>

            <div class="table-responsive">
                <table class="table table-sm table-hover config-table" id="usersTable">
                    <thead>
                        <!-- Eric beta 2026-06-30 — sortable headers. Click toggles
                             asc/desc; the active column shows a chevron via JS. -->
                        <tr>
                            <th style="width:50px; cursor:pointer; user-select:none;" data-sort-key="id" data-sort-type="num">ID <span class="sort-ind text-body-secondary small"></span></th>
                            <th style="cursor:pointer; user-select:none;" data-sort-key="user" data-sort-type="str">Username <span class="sort-ind text-body-secondary small"></span></th>
                            <th style="cursor:pointer; user-select:none;" data-sort-key="role_name" data-sort-type="str"><?php echo e(t('useracct.role_col', 'Role')); ?> <span class="sort-ind text-body-secondary small"></span></th>
                            <th style="cursor:pointer; user-select:none;" data-sort-key="role_org_name" data-sort-type="str">Scope <span class="sort-ind text-body-secondary small"></span></th>
                            <th style="cursor:pointer; user-select:none;" data-sort-key="member_sort" data-sort-type="str">Linked Member <span class="sort-ind text-body-secondary small"></span></th>
                            <th style="width:60px; cursor:pointer; user-select:none;" data-sort-key="can_login" data-sort-type="num">Login <span class="sort-ind text-body-secondary small"></span></th>
                        </tr>
                    </thead>
                    <tbody id="usersTableBody"></tbody>
                </table>
            </div>
            <div class="config-status-bar" id="usersStatus">Loading...</div>
        </div>

        <!-- ── Warn Locations ─────────────────────────────────────── -->
        <!-- ── Alert Zones (combined Warn Locations + Geofencing) ──── -->
        <div class="config-panel" id="panel-alert-zones">
            <div class="config-panel-title">
                <i class="bi bi-shield-exclamation text-warning"></i> Alert Zones
            </div>
            <p class="text-body-secondary small mb-2">
                Define geographic areas that trigger alerts. <strong>Warn Locations</strong> alert dispatchers when
                creating incidents near hazardous sites. <strong>Geofences</strong> alert when tracked units enter or leave boundaries.
            </p>
            <ul class="nav nav-tabs nav-tabs-sm mb-3" role="tablist">
                <li class="nav-item">
                    <button class="nav-link active small py-1 px-3" data-bs-toggle="tab" data-bs-target="#azWarnTab" type="button" role="tab">
                        <i class="bi bi-exclamation-triangle me-1"></i>Warn Locations
                    </button>
                </li>
                <li class="nav-item">
                    <button class="nav-link small py-1 px-3" data-bs-toggle="tab" data-bs-target="#azGeofenceTab" type="button" role="tab">
                        <i class="bi bi-bounding-box me-1"></i>Geofences
                    </button>
                </li>
            </ul>
            <div class="tab-content">
                <!-- Warn Locations sub-tab -->
                <div class="tab-pane fade show active" id="azWarnTab" role="tabpanel">
                    <p class="text-body-secondary small mb-2">
                        Flag locations with active warnings. When a new incident is created near a warn location,
                        dispatchers see a prominent alert banner. Use for hazmat sites, known threats,
                        aggressive animals, structural hazards, etc.
                    </p>
                    <button class="btn btn-sm btn-outline-primary mb-2 az-editor-link" data-tab="warn-locations">
                        <i class="bi bi-pencil-square me-1"></i>Open Warn Locations Editor
                    </button>
                    <div id="azWarnSummary" class="small text-body-secondary">Loading warn locations...</div>
                </div>
                <!-- Geofencing sub-tab -->
                <div class="tab-pane fade" id="azGeofenceTab" role="tabpanel">
                    <p class="text-body-secondary small mb-2">
                        Define geographic boundaries that trigger alerts when tracked units enter or leave.
                    </p>
                    <button class="btn btn-sm btn-outline-primary mb-2 az-editor-link" data-tab="geofencing">
                        <i class="bi bi-pencil-square me-1"></i>Open Geofence Editor
                    </button>
                    <div id="azGeofenceSummary" class="small text-body-secondary">Loading geofences...</div>
                </div>
            </div>
        </div>

        <div class="config-panel" id="panel-warn-locations">
            <div class="config-panel-title">
                <i class="bi bi-exclamation-triangle text-warning"></i> Warn Locations
                <button class="btn btn-sm btn-outline-secondary ms-auto py-0 px-2 az-editor-link" data-tab="alert-zones" style="font-size:0.7rem">
                    <i class="bi bi-arrow-left me-1"></i>Back to Alert Zones
                </button>
            </div>
            <p class="text-body-secondary small mb-3">
                Flag locations with active warnings. When a new incident is created near a warn location,
                dispatchers see a prominent alert banner. Use this for hazmat sites, known threats,
                aggressive animals, structural hazards, and other location-based advisories.
            </p>

            <!-- ── Global default alert radius (maps-comprehensive-2026-06) ── -->
            <!-- Each warn location can set its own "Alert Radius" (meters) in the
                 editor below. This global default is the fallback used for any
                 warn location whose radius is left blank/zero. -->
            <div class="card card-body bg-body-tertiary mb-3 py-2">
                <div class="d-flex align-items-center flex-wrap gap-2">
                    <i class="bi bi-bullseye text-warning"></i>
                    <strong class="small">Global Default Alert Radius</strong>
                    <i class="bi bi-question-circle text-body-secondary" tabindex="0"
                       data-bs-toggle="popover" data-bs-trigger="hover focus" data-bs-placement="top"
                       data-bs-content="Fallback radius for warn locations that don't set their own Alert Radius. Stored as tenths of the chosen unit (legacy format): e.g. value 10 + Miles = 1.0 mile."
                       title="Global radius help"></i>
                    <div class="input-group input-group-sm" style="width:120px">
                        <input type="number" class="form-control form-control-sm" id="warnProximity"
                               data-warn-setting="warn_proximity" min="0" max="9999" step="1" value="10"
                               title="Default radius in tenths of the unit (10 = 1.0)">
                        <span class="input-group-text">×0.1</span>
                    </div>
                    <select class="form-select form-select-sm" id="warnProximityUnits"
                            data-warn-setting="warn_proximity_units" style="width:auto">
                        <option value="M">Miles</option>
                        <option value="K">Kilometers</option>
                        <option value="N">Nautical mi</option>
                    </select>
                    <button class="btn btn-sm btn-outline-primary" type="button" id="btnSaveWarnProximity">
                        <i class="bi bi-check-lg me-1"></i>Save Default
                    </button>
                    <span class="text-success small d-none" id="warnProximitySaveOk">
                        <i class="bi bi-check-circle"></i> Saved
                    </span>
                </div>
                <div class="small text-body-secondary mt-1">
                    Set to <code>0</code> to disable proximity alerts for locations that have no per-location radius.
                </div>
            </div>

            <div class="config-edit-panel" id="warnLocEditPanel">
                <form id="warnLocForm">
                    <input type="hidden" id="warnLocId" name="id" value="">

                    <!-- Row 1: Title + Type -->
                    <div class="row g-2">
                        <div class="col-md-6">
                            <label for="warnLocTitle" class="form-label form-label-sm">Title <span class="text-danger">*</span></label>
                            <input type="text" class="form-control form-control-sm" id="warnLocTitle" name="title" required maxlength="200"
                                   placeholder="e.g. Active meth lab — 123 Oak St">
                        </div>
                        <div class="col-md-3">
                            <label for="warnLocType" class="form-label form-label-sm">
                                Alert Type
                                <i class="bi bi-question-circle text-body-secondary" tabindex="0"
                                   data-bs-toggle="popover" data-bs-trigger="hover focus" data-bs-placement="top"
                                   data-bs-content="Category determines the alert icon and color shown to dispatchers."
                                   title="Alert type help"></i>
                            </label>
                            <select class="form-select form-select-sm" id="warnLocType" name="loc_type">
                                <option value="0">⚠️ General Warning</option>
                                <option value="1">☣️ Hazmat / Chemical</option>
                                <option value="2">🔫 Threat / Weapons</option>
                                <option value="3">🐕 Aggressive Animal</option>
                                <option value="4">🏚️ Structural Hazard</option>
                                <option value="5">⚡ Utility Hazard</option>
                                <option value="6">🔒 Access Restriction</option>
                                <option value="7">🏥 Medical Advisory</option>
                                <option value="8">📋 Information Only</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="warnLocState" class="form-label form-label-sm"><?php echo e(t('form.state', 'State')); ?></label>
                            <select class="form-select form-select-sm" id="warnLocState" name="state"></select>
                        </div>
                    </div>

                    <!-- Row 2: Street + City + Lookup -->
                    <div class="row g-2 mt-1">
                        <div class="col-md-5">
                            <label for="warnLocStreet" class="form-label form-label-sm">Street Address</label>
                            <input type="text" class="form-control form-control-sm" id="warnLocStreet" name="street" maxlength="96"
                                   placeholder="123 Main St">
                        </div>
                        <div class="col-md-4">
                            <label for="warnLocCity" class="form-label form-label-sm">City</label>
                            <input type="text" class="form-control form-control-sm" id="warnLocCity" name="city" maxlength="32">
                        </div>
                        <div class="col-md-3 d-flex align-items-end">
                            <button type="button" class="btn btn-sm btn-outline-primary w-100" id="btnWarnLocLookup">
                                <i class="bi bi-search me-1"></i>Lookup Address
                            </button>
                        </div>
                    </div>

                    <!-- Row 3: Lat / Lng / Radius -->
                    <div class="row g-2 mt-1">
                        <div class="col-md-3">
                            <label for="warnLocLat" class="form-label form-label-sm">Latitude</label>
                            <input type="text" class="form-control form-control-sm" id="warnLocLat" name="lat" placeholder="0.000000">
                        </div>
                        <div class="col-md-3">
                            <label for="warnLocLng" class="form-label form-label-sm">Longitude</label>
                            <input type="text" class="form-control form-control-sm" id="warnLocLng" name="lng" placeholder="0.000000">
                        </div>
                        <div class="col-md-3">
                            <label for="warnLocRadius" class="form-label form-label-sm">
                                Alert Radius
                                <i class="bi bi-question-circle text-body-secondary" tabindex="0"
                                   data-bs-toggle="popover" data-bs-trigger="hover focus" data-bs-placement="top"
                                   data-bs-content="Distance in meters from the pin. When a new incident's coordinates fall within this radius, the warning alert fires."
                                   title="Radius help"></i>
                            </label>
                            <div class="input-group input-group-sm">
                                <input type="number" class="form-control form-control-sm" id="warnLocRadius" name="radius"
                                       min="50" max="50000" value="500" step="50">
                                <span class="input-group-text">m</span>
                            </div>
                        </div>
                        <div class="col-md-3 d-flex align-items-end">
                            <small class="text-body-secondary">
                                <i class="bi bi-info-circle me-1"></i>
                                Click map to place pin
                            </small>
                        </div>
                    </div>

                    <!-- Row 4: Map -->
                    <div class="row g-2 mt-1">
                        <div class="col-12">
                            <div id="warnLocMap"></div>
                        </div>
                    </div>

                    <!-- Row 5: Description -->
                    <div class="row g-2 mt-1">
                        <div class="col-12">
                            <label for="warnLocDesc" class="form-label form-label-sm">
                                Description / Alert Message <span class="text-danger">*</span>
                            </label>
                            <textarea class="form-control form-control-sm" id="warnLocDesc" name="description" rows="3"
                                      placeholder="Detailed warning shown to dispatchers when an incident is near this location.&#10;Include specific hazards, approach instructions, officer safety notes, etc."
                                      required></textarea>
                        </div>
                    </div>

                    <!-- Buttons -->
                    <div class="d-flex gap-2 mt-3">
                        <button type="submit" class="btn btn-sm btn-success"><i class="bi bi-check-lg me-1"></i>Save</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="btnCancelWarnLoc"><i class="bi bi-x-lg me-1"></i>Cancel</button>
                        <button type="button" class="btn btn-sm btn-outline-danger ms-auto d-none" id="btnDeleteWarnLoc"><i class="bi bi-trash me-1"></i>Delete</button>
                    </div>
                </form>
            </div>

            <div class="config-toolbar">
                <input type="text" class="form-control form-control-sm" id="warnLocSearch" placeholder="Search warn locations...">
                <button class="btn btn-sm btn-success ms-auto" id="btnAddWarnLoc"><i class="bi bi-plus-lg me-1"></i>Add Warn Location</button>
            </div>

            <div class="table-responsive">
                <table class="table table-sm table-hover config-table">
                    <thead>
                        <tr>
                            <th style="width:50px;">ID</th>
                            <th style="width:40px;">Type</th>
                            <th>Title</th>
                            <th>Street</th>
                            <th>City</th>
                            <th style="width:80px;">Lat</th>
                            <th style="width:80px;">Lng</th>
                            <th style="width:120px;">Created</th>
                        </tr>
                    </thead>
                    <tbody id="warnLocTableBody"></tbody>
                </table>
            </div>
            <div class="config-status-bar" id="warnLocStatus">Loading...</div>
        </div>

        <!-- ── Zello Network Radio Configuration ─────────────────── -->
        <div class="config-panel" id="panel-zello-radio">
            <div class="config-panel-title">
                <i class="bi bi-broadcast-pin text-warning"></i> Zello Network Radio Settings
            </div>
            <p class="text-body-secondary small mb-3">
                Configure Zello network radio integration. This enables voice, text, and media communication
                with field units directly from the dispatch console. Supports both Zello Consumer (free, single channel)
                and Zello Work (paid, multi-channel with transcription).
            </p>

            <!-- ── Setup Wizard ──────────────────────────────── -->
            <div id="zelloSetupWizard" class="card border-warning mb-3" style="display:none;">
                <div class="card-header bg-warning-subtle d-flex justify-content-between align-items-center py-2">
                    <span><i class="bi bi-magic me-1"></i><strong>Setup Wizard</strong> — Get connected in 4 steps</span>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btnDismissWizard">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>
                <div class="card-body py-2 small">
                    <div class="zello-wizard-steps">
                        <div class="wizard-step" data-step="1">
                            <div class="d-flex align-items-start gap-2 mb-2">
                                <span class="badge rounded-pill text-bg-warning wizard-step-num">1</span>
                                <div class="flex-fill">
                                    <strong>Create a Zello Developer Account</strong>
                                    <p class="mb-1 text-body-secondary">
                                        Go to <a href="https://developers.zello.com/" target="_blank">developers.zello.com</a>
                                        and sign in with your Zello account. If you don't have a Zello account yet,
                                        <a href="https://zello.com/personal/" target="_blank">create one for free</a> first.
                                    </p>
                                    <div class="wizard-check text-success" style="display:none;">
                                        <i class="bi bi-check-circle-fill me-1"></i>Account ready
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="wizard-step" data-step="2">
                            <div class="d-flex align-items-start gap-2 mb-2">
                                <span class="badge rounded-pill text-bg-warning wizard-step-num">2</span>
                                <div class="flex-fill">
                                    <strong>Create an App &amp; Copy the Credentials</strong>
                                    <p class="mb-1 text-body-secondary">
                                        In the developer console, click <strong>Create App</strong> (or open an existing app).
                                        The app page shows its credentials inline &mdash; Issuer, Developer Token, Private Key,
                                        and Public Key. You need two of them:
                                    </p>
                                    <ul class="mb-1 text-body-secondary ps-3">
                                        <li><strong>Issuer</strong> — a short string like <code>WkM6...</code> — copy it into the <em>Issuer</em> field below</li>
                                        <li><strong>Private Key</strong> — copy the key text shown in the console (the whole block, including the
                                            <code>-----BEGIN/END-----</code> lines if present) into the <em>Private Key</em> field below.
                                            Newer Zello consoles display the key inline to copy &mdash; there is no longer a
                                            "Generate" button or downloadable <code>.pem</code> file.</li>
                                    </ul>
                                    <div class="wizard-check text-success" style="display:none;">
                                        <i class="bi bi-check-circle-fill me-1"></i><span class="wizard-check-text">Issuer &amp; key configured</span>
                                    </div>
                                    <div class="wizard-missing text-danger" style="display:none;">
                                        <i class="bi bi-exclamation-circle me-1"></i><span class="wizard-missing-text">Missing</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="wizard-step" data-step="3">
                            <div class="d-flex align-items-start gap-2 mb-2">
                                <span class="badge rounded-pill text-bg-warning wizard-step-num">3</span>
                                <div class="flex-fill">
                                    <strong>Enter Account &amp; Channel</strong>
                                    <p class="mb-1 text-body-secondary">
                                        Fill in your Zello <em>Username</em>, <em>Password</em>, and
                                        <em>Dispatch Channel</em> name below. The channel must be one
                                        you've already joined in the Zello app.
                                    </p>
                                    <div class="wizard-check text-success" style="display:none;">
                                        <i class="bi bi-check-circle-fill me-1"></i><span class="wizard-check-text">Account &amp; channel set</span>
                                    </div>
                                    <div class="wizard-missing text-danger" style="display:none;">
                                        <i class="bi bi-exclamation-circle me-1"></i><span class="wizard-missing-text">Missing</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="wizard-step" data-step="4">
                            <div class="d-flex align-items-start gap-2 mb-2">
                                <span class="badge rounded-pill text-bg-warning wizard-step-num">4</span>
                                <div class="flex-fill">
                                    <strong>Save &amp; Test</strong>
                                    <p class="mb-1 text-body-secondary">
                                        Click <strong>Save Zello Settings</strong>, then restart the proxy
                                        (<code>proxy\start-proxy.bat</code>)
                                        and click <strong>Test Connection</strong> to verify everything works.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <form id="zelloConfigForm">
                <div class="settings-group">
                    <div class="settings-group-title">
                        Connection
                        <!-- Eric beta 2026-06-30 — clear current-mode badge so admins
                             see at a glance which auth path they're on. JS sets the
                             text + color via applyZelloServiceMode(). -->
                        <span id="zelloServiceModeBadge" class="badge bg-secondary ms-2">—</span>
                    </div>
                    <!-- Eric beta 2026-06-30 — service-disabled notice. Shown only
                         when Service Type is "". Hides all the irrelevant auth fields
                         below so the form isn't a wall of blanks. -->
                    <div id="zelloServiceDisabledNotice" class="alert alert-secondary py-2 mb-2 small d-none">
                        <i class="bi bi-power me-2"></i>
                        Zello integration is currently disabled. Pick a Service Type below to enable it; the form will adapt to show only the fields that apply.
                    </div>
                    <div class="row g-2">
                        <div class="col-md-4">
                            <label for="zelloService" class="form-label form-label-sm">Service Type</label>
                            <select class="form-select form-select-sm" id="zelloService" data-key="zello_service">
                                <option value="">— Disabled —</option>
                                <option value="consumer">Zello Consumer (free, 1 channel)</option>
                                <option value="work">Zello Work (paid, multi-channel)</option>
                            </select>
                            <div class="form-text">Consumer connects to public Zello channels. Work connects to your Zello Work network.</div>
                        </div>
                        <div class="col-md-4" id="zelloWsUrlWrap">
                            <label for="zelloWsUrl" class="form-label form-label-sm">WebSocket URL <span class="text-body-secondary fw-normal">(optional)</span></label>
                            <input type="text" class="form-control form-control-sm" id="zelloWsUrl" data-key="zello_ws_url"
                                   placeholder="wss://zello.io/ws">
                            <div class="form-text" id="zelloWsUrlHelp">Consumer: <code>wss://zello.io/ws</code> | Work: <code>wss://zellowork.io/ws/NETWORK</code></div>
                        </div>
                        <!-- Eric beta 2026-06-30 — Network Name shown ONLY in Work
                             mode. Consumer doesn't have networks; hiding the field
                             stops admins from typing into it and wondering why
                             nothing happens. -->
                        <div class="col-md-4 d-none" id="zelloNetworkWrap">
                            <label for="zelloNetwork" class="form-label form-label-sm">Network Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control form-control-sm" id="zelloNetwork" data-key="zello_network"
                                   placeholder="your-company-slug">
                            <div class="form-text">The slug from your Zello Work URL. Example: if your admin panel is at <code>https://acmecorp.zellowork.com/</code> the slug is <code>acmecorp</code>. Paste the full URL — we'll extract the slug on save.</div>
                        </div>
                    </div>
                    <div class="row g-2 mt-1">
                        <div class="col-md-4">
                            <label for="zelloProxyPort" class="form-label form-label-sm">Proxy Port</label>
                            <input type="number" class="form-control form-control-sm" id="zelloProxyPort" data-key="zello_proxy_port"
                                   placeholder="8090" min="1024" max="65535">
                            <div class="form-text">WebSocket proxy port for browser ↔ Zello relay. Default: <code>8090</code></div>
                        </div>
                        <!-- Eric beta 2026-06-30 — Connection Mode is only meaningful
                             in Consumer (direct browser mode needs a dev token). In
                             Work mode it's always the proxy. Hide to remove confusion. -->
                        <div class="col-md-4" id="zelloProxyModeWrap">
                            <label for="zelloProxyMode" class="form-label form-label-sm">Connection Mode</label>
                            <select class="form-select form-select-sm" id="zelloProxyMode" data-key="zello_proxy_mode">
                                <option value="proxy">Server Proxy (recommended)</option>
                                <option value="direct">Direct Browser (dev token only)</option>
                            </select>
                            <div class="form-text">Proxy handles JWT auth server-side. Direct mode requires a dev auth token.</div>
                        </div>
                        <div class="col-md-4">
                            <label for="zelloRetention" class="form-label form-label-sm">Message Retention (days)</label>
                            <input type="number" class="form-control form-control-sm" id="zelloRetention" data-key="zello_retention_days"
                                   placeholder="90" min="1" max="365">
                            <div class="form-text">Auto-purge messages and audio older than this. Default: <code>90</code> days.</div>
                        </div>
                    </div>
                </div>

                <div class="settings-group">
                    <div class="settings-group-title">Authentication</div>
                    <div class="row g-2">
                        <div class="col-md-4">
                            <label for="zelloUsername" class="form-label form-label-sm">Username</label>
                            <input type="text" class="form-control form-control-sm" id="zelloUsername" data-key="zello_username"
                                   placeholder="dispatch-console">
                            <div class="form-text">The Zello account this console connects as.</div>
                        </div>
                        <div class="col-md-4">
                            <label for="zelloPassword" class="form-label form-label-sm">Password</label>
                            <input type="password" class="form-control form-control-sm" id="zelloPassword" data-key="zello_password"
                                   data-secret="1" autocomplete="new-password"
                                   placeholder="•••• stored — leave blank to keep, type to replace">
                        </div>
                        <!-- Eric beta 2026-06-30 — Auth Token is a Consumer-mode
                             quick-dev shortcut. Hide in Work mode (username+password
                             handle auth). -->
                        <div class="col-md-4" id="zelloAuthTokenWrap">
                            <label for="zelloAuthToken" class="form-label form-label-sm">
                                Auth Token <span class="text-body-secondary fw-normal">(optional, Consumer dev only)</span>
                            </label>
                            <input type="password" class="form-control form-control-sm" id="zelloAuthToken" data-key="zello_auth_token"
                                   data-secret="1" autocomplete="off"
                                   placeholder="•••• stored — leave blank to keep, type to replace">
                            <div class="form-text">Leave blank if using Issuer + Private Key below. Only needed for quick dev testing without JWT.</div>
                        </div>
                    </div>
                </div>

                <!-- Phase 98 (2026-06-28): info banner shown ONLY in Work mode.
                     JS in config.js toggles this with the API Credentials section
                     below based on Service Type selection. -->
                <div class="settings-group" id="zelloWorkAuthInfo" style="display:none;">
                    <div class="settings-group-title">Authentication (Zello Work)</div>
                    <div class="alert alert-info py-2 mb-0 small">
                        <i class="bi bi-info-circle me-2"></i>
                        Zello Work authenticates via your Username + Password
                        (above) against the user database of the configured
                        Network. No JWT issuer / private key is needed in this
                        mode &mdash; you can leave the API Credentials fields
                        blank.
                    </div>
                </div>
                <div class="settings-group" id="zelloApiCredentialsSection">
                    <div class="settings-group-title">API Credentials <span class="text-body-secondary fw-normal">(Consumer mode only)</span></div>
                    <div class="row g-2">
                        <div class="col-md-4">
                            <label for="zelloIssuer" class="form-label form-label-sm">Issuer</label>
                            <input type="text" class="form-control form-control-sm" id="zelloIssuer" data-key="zello_issuer"
                                   placeholder="From Zello developer console">
                            <div class="form-text">From <a href="https://developers.zello.com/" target="_blank">developers.zello.com</a> &rarr; your app.</div>
                        </div>
                        <div class="col-md-8">
                            <label for="zelloPrivateKey" class="form-label form-label-sm">Private Key (PEM)</label>
                            <textarea class="form-control form-control-sm" id="zelloPrivateKey" data-key="zello_private_key"
                                      data-secret="1" rows="3" placeholder="•••• stored — leave blank to keep, paste a new key to replace"></textarea>
                            <div class="form-text">Never share this key. Used server-side to generate JWT auth tokens automatically.</div>
                        </div>
                    </div>
                </div>

                <div class="settings-group">
                    <div class="settings-group-title">Channels</div>
                    <div class="row g-2">
                        <div class="col-md-4">
                            <label for="zelloDispatchChannel" class="form-label form-label-sm">Dispatch Channel</label>
                            <input type="text" class="form-control form-control-sm" id="zelloDispatchChannel" data-key="zello_dispatch_channel"
                                   placeholder="dispatch">
                            <div class="form-text">Primary dispatch channel for voice communication.</div>
                        </div>
                        <div class="col-md-4">
                            <label for="zelloAlertChannel" class="form-label form-label-sm">Alert Channel (optional)</label>
                            <input type="text" class="form-control form-control-sm" id="zelloAlertChannel" data-key="zello_alert_channel"
                                   placeholder="alerts">
                            <div class="form-text">Channel for automated text alerts and status updates.</div>
                        </div>
                        <div class="col-md-4">
                            <label for="zelloExtraChannels" class="form-label form-label-sm">Additional Channels</label>
                            <input type="text" class="form-control form-control-sm" id="zelloExtraChannels" data-key="zello_extra_channels"
                                   placeholder="tac-1, tac-2, command">
                            <div class="form-text">Comma-separated. Zello Work supports up to 100 channels.</div>
                        </div>
                    </div>
                </div>

                <div class="settings-group">
                    <div class="settings-group-title">Audio Settings</div>
                    <div class="row g-2">
                        <div class="col-md-3">
                            <label for="zelloCodec" class="form-label form-label-sm">Codec</label>
                            <select class="form-select form-select-sm" id="zelloCodec" data-key="zello_codec">
                                <option value="opus">Opus (recommended)</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="zelloSampleRate" class="form-label form-label-sm">Sample Rate</label>
                            <select class="form-select form-select-sm" id="zelloSampleRate" data-key="zello_sample_rate">
                                <option value="16000">16 kHz (default)</option>
                                <option value="8000">8 kHz (low bandwidth)</option>
                                <option value="24000">24 kHz (high quality)</option>
                                <option value="48000">48 kHz (studio)</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="zelloFrameDuration" class="form-label form-label-sm">Frame Duration (ms)</label>
                            <select class="form-select form-select-sm" id="zelloFrameDuration" data-key="zello_frame_duration">
                                <option value="20">20ms (default)</option>
                                <option value="40">40ms</option>
                                <option value="60">60ms</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="zelloListenOnly" class="form-label form-label-sm">Mode</label>
                            <select class="form-select form-select-sm" id="zelloListenOnly" data-key="zello_listen_only">
                                <option value="0">Full (talk + listen)</option>
                                <option value="1">Listen only</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="settings-group">
                    <div class="settings-group-title">Behavior</div>
                    <div class="row g-2">
                        <div class="col-md-4">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="zelloAutoConnect" data-key="zello_auto_connect">
                                <label class="form-check-label small" for="zelloAutoConnect">Auto-connect on page load</label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="zelloTextAlerts" data-key="zello_text_alerts">
                                <label class="form-check-label small" for="zelloTextAlerts">Send dispatch alerts as text messages</label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="zelloTranscribe" data-key="zello_transcribe">
                                <label class="form-check-label small" for="zelloTranscribe">Enable voice transcription (Work only)</label>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="alert alert-info small py-2 mt-2">
                    <i class="bi bi-info-circle me-1"></i>
                    <strong>HTTPS Required:</strong> Browser microphone access requires HTTPS.
                    Voice transmission will not work on plain HTTP connections.
                    Listening and text messaging work on HTTP.
                    <br>
                    <i class="bi bi-link-45deg me-1 mt-1"></i>
                    <strong>Resources:</strong>
                    <a href="https://developers.zello.com/" target="_blank">Developer Console</a> |
                    <a href="https://github.com/zelloptt/zello-channel-api" target="_blank">Channel API SDK</a> |
                    <a href="https://zello.com/pricing/" target="_blank">Zello Work Pricing</a>
                </div>

                <div class="d-flex gap-2 mt-3">
                    <button type="submit" class="btn btn-sm btn-success"><i class="bi bi-check-lg me-1"></i>Save Zello Settings</button>
                    <button type="button" class="btn btn-sm btn-outline-info" id="btnTestZello"><i class="bi bi-broadcast me-1"></i>Test Connection</button>
                </div>
            </form>

            <!-- Eric beta 2026-06-30 — visible troubleshooting on the setup
                 page. Collapsible (default open) so admins find it during
                 first-time setup but can hide it once they're past initial
                 verification. Click-to-copy on each command for quick paste
                 into an SSH terminal. -->
            <div class="card border-secondary mt-3" id="zelloTroubleshootCard">
                <div class="card-header py-2 d-flex align-items-center" style="cursor:pointer;" data-bs-toggle="collapse" data-bs-target="#zelloTroubleshoot">
                    <i class="bi bi-wrench-adjustable me-2 text-warning"></i>
                    <span class="fw-semibold">Troubleshooting commands</span>
                    <span class="text-body-secondary small ms-2">— SSH to the server hosting the Zello proxy and run these to diagnose connection issues.</span>
                    <i class="bi bi-chevron-down ms-auto"></i>
                </div>
                <div class="collapse show" id="zelloTroubleshoot">
                    <div class="card-body py-2 small">
                        <p class="mb-2 text-body-secondary">
                            Every command below has a <i class="bi bi-clipboard"></i> button to copy. Run them on the host where the <code>newui-zello-proxy</code> systemd service is running (usually the same box as the web server).
                        </p>

                        <div class="mb-3">
                            <div class="fw-semibold mb-1">1. Watch the proxy log in real time</div>
                            <div class="text-body-secondary mb-1">Best command to run BEFORE you click Save — you see the reconnect + auth attempt as it happens.</div>
                            <div class="d-flex align-items-center bg-body-tertiary rounded p-2">
                                <code class="flex-grow-1 font-monospace ts-copy-target">sudo tail -f /var/log/newui/zello-proxy.log</code>
                                <button type="button" class="btn btn-sm btn-outline-secondary ts-copy-btn" title="Copy to clipboard"><i class="bi bi-clipboard"></i></button>
                            </div>
                            <div class="text-body-secondary small mt-1">
                                Look for <code>[Upstream] Authenticated successfully</code> then <code>Channel status: NAME - online</code>. If you see <code>Authentication failed</code> the username/password is wrong; if you see <code>online</code> the channel is live.
                            </div>
                        </div>

                        <div class="mb-3">
                            <div class="fw-semibold mb-1">2. Check the proxy service is running</div>
                            <div class="d-flex align-items-center bg-body-tertiary rounded p-2">
                                <code class="flex-grow-1 font-monospace ts-copy-target">sudo systemctl status newui-zello-proxy.service --no-pager</code>
                                <button type="button" class="btn btn-sm btn-outline-secondary ts-copy-btn"><i class="bi bi-clipboard"></i></button>
                            </div>
                            <div class="text-body-secondary small mt-1">
                                Expect <code>Active: active (running)</code>. If it's <code>failed</code> or <code>inactive</code>, restart it with the next command.
                            </div>
                        </div>

                        <div class="mb-3">
                            <div class="fw-semibold mb-1">3. Restart the proxy after a config change</div>
                            <div class="text-body-secondary mb-1">The proxy normally reloads on Save, but a force-restart clears any stuck state.</div>
                            <div class="d-flex align-items-center bg-body-tertiary rounded p-2">
                                <code class="flex-grow-1 font-monospace ts-copy-target">sudo systemctl restart newui-zello-proxy.service &amp;&amp; sudo journalctl -u newui-zello-proxy.service -n 30 --no-pager</code>
                                <button type="button" class="btn btn-sm btn-outline-secondary ts-copy-btn"><i class="bi bi-clipboard"></i></button>
                            </div>
                        </div>

                        <div class="mb-3">
                            <div class="fw-semibold mb-1">4. Verify the saved Network Name (slug)</div>
                            <div class="text-body-secondary mb-1">If Work mode connects but the channel never reaches <code>online</code>, your network slug is probably wrong. This shows exactly what got saved.</div>
                            <div class="d-flex align-items-center bg-body-tertiary rounded p-2">
                                <code class="flex-grow-1 font-monospace ts-copy-target">cd /var/www/newui &amp;&amp; /usr/bin/php -r 'require_once "config.php"; require_once "inc/db.php"; echo "network=" . db_fetch_value("SELECT value FROM settings WHERE name=\"zello_network\"") . "\nws_url=" . db_fetch_value("SELECT value FROM settings WHERE name=\"zello_ws_url\"") . "\nservice=" . db_fetch_value("SELECT value FROM settings WHERE name=\"zello_service\"") . "\nchannel=" . db_fetch_value("SELECT value FROM settings WHERE name=\"zello_dispatch_channel\"") . "\n";'</code>
                                <button type="button" class="btn btn-sm btn-outline-secondary ts-copy-btn"><i class="bi bi-clipboard"></i></button>
                            </div>
                            <div class="text-body-secondary small mt-1">
                                <code>network</code> should be a bare slug (no <code>https://</code>, no <code>.zellowork.com</code>, no slashes). If it has any of those, re-save the Network Name and the form will strip them automatically.
                            </div>
                        </div>

                        <div class="mb-2">
                            <div class="fw-semibold mb-1">Common failure modes</div>
                            <ul class="mb-0 ps-3">
                                <li><strong>Log shows <code>Authentication failed</code>:</strong> username or password wrong, or the account doesn't exist on the Network. Log into your Zello Work admin panel and verify the user.</li>
                                <li><strong>Log shows <code>Authenticated successfully</code> but channel stays <code>offline</code>:</strong> channel name typo (case-sensitive), or the console account isn't a member of that channel on the Zello side.</li>
                                <li><strong>Connection rejected / no log activity at all:</strong> the proxy can't reach Zello. Check the host's outbound firewall — Zello needs WSS to <code>zello.io</code> (Consumer) or <code>zellowork.io</code> (Work) on port 443.</li>
                                <li><strong>Chat widget shows grey dot even after success:</strong> browser SSE stream is stale. Hard-refresh the page (Ctrl+Shift+R).</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Constituents Management ───────────────────────────────── -->
        <div class="config-panel" id="panel-constituents">
            <div class="config-panel-title">
                <i class="bi bi-person-lines-fill text-info"></i> Constituents (Contact Database)
            </div>
            <p class="text-body-secondary small mb-3">
                Community contacts used for phone-based caller lookup during incident creation.
                When a dispatcher enters a phone number, the system searches constituents to auto-fill
                caller information and display important notes or warnings.
            </p>
            <div class="d-flex gap-2">
                <a href="constituents.php" class="btn btn-sm btn-primary">
                    <i class="bi bi-box-arrow-up-right me-1"></i>Open Constituents Manager
                </a>
            </div>
        </div>

        <!-- ═══ ROAD CONDITIONS ═══ -->
        <div class="config-panel" id="panel-road-conditions">
            <div class="config-panel-title">
                <i class="bi bi-cone-striped text-warning"></i> Road Conditions
            </div>

            <!-- ── Condition Types CRUD ────────────────────────── -->
            <h6 class="mb-2 mt-2"><i class="bi bi-tags me-1"></i>Condition Types</h6>
            <p class="text-body-secondary small mb-2">
                Define condition types (e.g. slippery, flooded, closed) that can be assigned to road condition reports.
            </p>

            <div class="config-edit-panel" id="condTypeEditPanel">
                <form id="condTypeForm">
                    <input type="hidden" id="condTypeId" name="id" value="">
                    <div class="row g-2">
                        <div class="col-md-3">
                            <label for="condTypeTitle" class="form-label form-label-sm">Title <span class="text-danger">*</span></label>
                            <input type="text" class="form-control form-control-sm" id="condTypeTitle" name="title" required maxlength="64">
                        </div>
                        <div class="col-md-5">
                            <label for="condTypeDesc" class="form-label form-label-sm">Description</label>
                            <input type="text" class="form-control form-control-sm" id="condTypeDesc" name="description" maxlength="255">
                        </div>
                        <div class="col-md-4">
                            <label for="condTypeIcon" class="form-label form-label-sm">
                                Icon
                                <i class="bi bi-question-circle text-body-secondary" tabindex="0"
                                   data-bs-toggle="popover" data-bs-trigger="hover focus" data-bs-placement="top"
                                   data-bs-content="Bootstrap Icons class name without the 'bi-' prefix, e.g. 'exclamation-triangle-fill'. Leave blank for default."
                                   title="Icon help"></i>
                            </label>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text" id="condTypeIconPreview"><i class="bi bi-tag"></i></span>
                                <input type="text" class="form-control form-control-sm" id="condTypeIcon" name="icon"
                                       placeholder="e.g. exclamation-triangle-fill" maxlength="64">
                            </div>
                        </div>
                    </div>
                    <div class="d-flex gap-2 mt-3">
                        <button type="submit" class="btn btn-sm btn-success"><i class="bi bi-check-lg me-1"></i>Save Type</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="btnCancelCondType"><i class="bi bi-x-lg me-1"></i>Cancel</button>
                        <button type="button" class="btn btn-sm btn-outline-danger ms-auto d-none" id="btnDeleteCondType"><i class="bi bi-trash me-1"></i>Delete Type</button>
                    </div>
                </form>
            </div>

            <div class="config-toolbar">
                <button class="btn btn-sm btn-success ms-auto" id="btnAddCondType"><i class="bi bi-plus-lg me-1"></i>Add Condition Type</button>
            </div>

            <div class="table-responsive">
                <table class="table table-sm table-hover config-table">
                    <thead>
                        <tr>
                            <th style="width:50px;">ID</th>
                            <th style="width:40px;">Icon</th>
                            <th>Title</th>
                            <th>Description</th>
                        </tr>
                    </thead>
                    <tbody id="condTypesTableBody"></tbody>
                </table>
            </div>
            <div class="config-status-bar" id="condTypesStatus">Loading...</div>

            <!-- ── Road Condition Reports CRUD ─────────────────── -->
            <hr class="my-3">
            <h6 class="mb-2"><i class="bi bi-signpost-2 me-1"></i>Road Condition Reports</h6>
            <p class="text-body-secondary small mb-2">
                Active road condition reports. These can be displayed on the map and used for dispatch awareness.
            </p>

            <div class="config-edit-panel" id="roadCondEditPanel">
                <form id="roadCondForm">
                    <input type="hidden" id="roadCondId" name="id" value="">
                    <div class="row g-2">
                        <div class="col-md-4">
                            <label for="roadCondTitle" class="form-label form-label-sm">Title <span class="text-danger">*</span></label>
                            <input type="text" class="form-control form-control-sm" id="roadCondTitle" name="title" required maxlength="128">
                        </div>
                        <div class="col-md-4">
                            <label for="roadCondAddress" class="form-label form-label-sm">Address / Location</label>
                            <input type="text" class="form-control form-control-sm" id="roadCondAddress" name="address" maxlength="255">
                        </div>
                        <div class="col-md-4">
                            <label for="roadCondType" class="form-label form-label-sm">Condition Type</label>
                            <select class="form-select form-select-sm" id="roadCondType" name="condition_id">
                                <option value="0">-- None --</option>
                            </select>
                        </div>
                    </div>
                    <div class="row g-2 mt-1">
                        <div class="col-md-8">
                            <label for="roadCondDesc" class="form-label form-label-sm">Description</label>
                            <textarea class="form-control form-control-sm" id="roadCondDesc" name="description" rows="2" maxlength="1024"></textarea>
                        </div>
                        <div class="col-md-2">
                            <label for="roadCondLat" class="form-label form-label-sm">Latitude</label>
                            <input type="number" class="form-control form-control-sm" id="roadCondLat" name="lat" step="any" value="0">
                        </div>
                        <div class="col-md-2">
                            <label for="roadCondLng" class="form-label form-label-sm">Longitude</label>
                            <input type="number" class="form-control form-control-sm" id="roadCondLng" name="lng" step="any" value="0">
                        </div>
                    </div>
                    <div class="d-flex gap-2 mt-3">
                        <button type="submit" class="btn btn-sm btn-success"><i class="bi bi-check-lg me-1"></i>Save</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="btnCancelRoadCond"><i class="bi bi-x-lg me-1"></i>Cancel</button>
                        <button type="button" class="btn btn-sm btn-outline-danger ms-auto d-none" id="btnDeleteRoadCond"><i class="bi bi-trash me-1"></i>Delete</button>
                    </div>
                </form>
            </div>

            <div class="config-toolbar">
                <button class="btn btn-sm btn-success ms-auto" id="btnAddRoadCond"><i class="bi bi-plus-lg me-1"></i>Add Road Condition</button>
            </div>

            <div class="table-responsive">
                <table class="table table-sm table-hover config-table">
                    <thead>
                        <tr>
                            <th style="width:50px;">ID</th>
                            <th>Title</th>
                            <th>Address</th>
                            <th>Condition</th>
                            <th style="width:100px;">Reported</th>
                            <th style="width:80px;">By</th>
                        </tr>
                    </thead>
                    <tbody id="roadCondTableBody"></tbody>
                </table>
            </div>
            <div class="config-status-bar" id="roadCondStatus">Loading...</div>
        </div>

        <!-- ═══ ICS POSITIONS ═══ -->
        <div class="config-panel" id="panel-ics-positions">
            <div class="config-panel-title">
                <i class="bi bi-shield-check text-info"></i> ICS Positions (NIMS)
            </div>
            <p class="text-body-secondary small mb-3">
                NIMS/ICS position codes used for qualification tracking. Assign positions to team members
                and track Position Task Book (PTB) progress. Pre-populated with common ICS positions.
            </p>

            <!-- Add Position Form -->
            <div class="card border-0 bg-body-tertiary mb-3">
                <div class="card-body py-2">
                    <div class="row g-2 align-items-end">
                        <div class="col-md-2">
                            <label class="form-label form-label-sm mb-0">Code</label>
                            <input type="text" class="form-control form-control-sm" id="icsCode" placeholder="e.g. COML" style="text-transform: uppercase;">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label form-label-sm mb-0">Title</label>
                            <input type="text" class="form-control form-control-sm" id="icsTitle" placeholder="e.g. Communications Unit Leader">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label form-label-sm mb-0">Category</label>
                            <select class="form-select form-select-sm" id="icsCategory">
                                <option value="">-- Select --</option>
                                <option value="Command">Command</option>
                                <option value="Operations">Operations</option>
                                <option value="Planning">Planning</option>
                                <option value="Logistics">Logistics</option>
                                <option value="Finance">Finance</option>
                            </select>
                        </div>
                        <div class="col-md-1">
                            <label class="form-label form-label-sm mb-0">Level</label>
                            <select class="form-select form-select-sm" id="icsNimsLevel">
                                <option value="">--</option>
                                <option value="1">1</option>
                                <option value="2">2</option>
                                <option value="3">3</option>
                                <option value="4">4</option>
                            </select>
                        </div>
                        <div class="col-md-1">
                            <label class="form-label form-label-sm mb-0">Order</label>
                            <input type="number" class="form-control form-control-sm" id="icsSortOrder" value="0" min="0">
                        </div>
                        <div class="col-md-auto">
                            <button type="button" class="btn btn-sm btn-primary" id="btnAddIcsPosition">
                                <i class="bi bi-plus-lg me-1"></i>Add
                            </button>
                        </div>
                    </div>
                    <div class="row g-2 mt-1">
                        <div class="col-12">
                            <label class="form-label form-label-sm mb-0">Description</label>
                            <input type="text" class="form-control form-control-sm" id="icsDescription" placeholder="Position description">
                        </div>
                    </div>
                    <input type="hidden" id="icsEditId" value="0">
                </div>
            </div>

            <!-- Positions Table -->
            <div class="table-responsive" style="max-height: 500px; overflow-y: auto;">
                <table class="table table-hover table-sm mb-0 align-middle">
                    <thead class="sticky-top" style="background: var(--bs-body-bg);">
                        <tr>
                            <th class="ps-3" style="width: 80px;">Code</th>
                            <th>Title</th>
                            <th>Category</th>
                            <th class="text-center" style="width: 50px;">Level</th>
                            <th class="text-center" style="width: 70px;">Qualified</th>
                            <th class="text-center" style="width: 50px;">Order</th>
                            <th class="text-center" style="width: 80px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="icsPositionsBody">
                        <tr><td colspan="7" class="text-center text-body-secondary py-3">Loading...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ═══ TRAINING ═══ -->
        <div class="config-panel" id="panel-training">
            <div class="config-panel-title">
                <i class="bi bi-mortarboard text-warning"></i> Training Configuration
            </div>
            <p class="text-body-secondary small mb-3">
                Manage the training / certification catalog that powers the roster's
                training and certification autocomplete. Individual training records
                are added per-member on the <a href="roster.php">Roster</a> page.
            </p>

            <!-- Training Catalog CRUD (mirrors ICS Positions layout) -->
            <div class="card border-0 bg-body-tertiary mb-3">
                <div class="card-body py-2">
                    <div class="row g-2 align-items-end">
                        <div class="col-md-4">
                            <label class="form-label form-label-sm mb-0">Course / Training Name</label>
                            <input type="text" class="form-control form-control-sm" id="trainCatName" placeholder="e.g. IS-100.c Introduction to the Incident Command System">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label form-label-sm mb-0">Category</label>
                            <select class="form-select form-select-sm" id="trainCatCategory">
                                <option value="">-- Select --</option>
                                <option value="FEMA IS">FEMA IS</option>
                                <option value="CPR/Medical">CPR/Medical</option>
                                <option value="Radio">Radio</option>
                                <option value="HAZMAT">HAZMAT</option>
                                <option value="Weather">Weather</option>
                                <option value="Emergency Mgmt">Emergency Mgmt</option>
                                <option value="Driving">Driving</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label form-label-sm mb-0">FEMA Code</label>
                            <input type="text" class="form-control form-control-sm" id="trainCatFemaCode" placeholder="e.g. IS-100.c">
                        </div>
                        <div class="col-md-1">
                            <label class="form-label form-label-sm mb-0">Refresh</label>
                            <input type="number" class="form-control form-control-sm" id="trainCatRefreshMonths" placeholder="mo" min="0">
                        </div>
                        <div class="col-md-1">
                            <div class="form-check form-check-sm mt-3">
                                <input class="form-check-input" type="checkbox" id="trainCatRequired">
                                <label class="form-check-label small" for="trainCatRequired">Req'd</label>
                            </div>
                        </div>
                        <div class="col-md-auto">
                            <button type="button" class="btn btn-sm btn-primary" id="btnAddTrainCat">
                                <i class="bi bi-plus-lg me-1"></i>Add
                            </button>
                        </div>
                    </div>
                    <div class="row g-2 mt-1">
                        <div class="col-md-8">
                            <label class="form-label form-label-sm mb-0">Description</label>
                            <input type="text" class="form-control form-control-sm" id="trainCatDescription" placeholder="Short description (optional)">
                        </div>
                    </div>
                    <input type="hidden" id="trainCatEditId" value="0">
                </div>
            </div>

            <!-- Training Catalog Table -->
            <div class="table-responsive mb-3" style="max-height: 500px; overflow-y: auto;">
                <table class="table table-hover table-sm mb-0 align-middle">
                    <thead class="sticky-top" style="background: var(--bs-body-bg);">
                        <tr>
                            <th class="ps-3">Name</th>
                            <th style="width: 110px;">FEMA Code</th>
                            <th style="width: 130px;">Category</th>
                            <th class="text-center" style="width: 60px;">Refresh</th>
                            <th class="text-center" style="width: 60px;">Req</th>
                            <th class="text-center" style="width: 70px;">Members</th>
                            <th class="text-center" style="width: 80px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="trainCatBody">
                        <tr><td colspan="7" class="text-center text-body-secondary py-3">Loading...</td></tr>
                    </tbody>
                </table>
            </div>

            <!-- Training Summary Stats -->
            <div class="row g-3 mb-3" id="trainingStatsRow">
                <div class="col-md-3">
                    <div class="card border-0 bg-body-tertiary">
                        <div class="card-body py-2 text-center">
                            <div class="fs-4 fw-bold" id="trainStatTotal">—</div>
                            <div class="small text-body-secondary">Total Records</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card border-0 bg-body-tertiary">
                        <div class="card-body py-2 text-center">
                            <div class="fs-4 fw-bold" id="trainStatHours">—</div>
                            <div class="small text-body-secondary">Total Hours</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card border-0 bg-body-tertiary">
                        <div class="card-body py-2 text-center">
                            <div class="fs-4 fw-bold" id="trainStatTypes">—</div>
                            <div class="small text-body-secondary">Training Types</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card border-0 bg-body-tertiary">
                        <div class="card-body py-2 text-center">
                            <div class="fs-4 fw-bold" id="trainStatCompleted">—</div>
                            <div class="small text-body-secondary">Completed</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- By Type Breakdown -->
            <div class="card border-0 bg-body-tertiary mb-3">
                <div class="card-header py-1 bg-transparent border-bottom">
                    <span class="fw-semibold small">Training by Type</span>
                </div>
                <div class="card-body py-2">
                    <div class="table-responsive">
                        <table class="table table-sm table-borderless mb-0">
                            <thead><tr><th>Type</th><th class="text-end">Count</th><th class="text-end">Hours</th></tr></thead>
                            <tbody id="trainByTypeBody">
                                <tr><td colspan="3" class="text-center text-body-secondary py-3">Loading...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Recent Activity -->
            <div class="card border-0 bg-body-tertiary mb-3">
                <div class="card-header py-1 bg-transparent border-bottom">
                    <span class="fw-semibold small">Recent Training Activity</span>
                </div>
                <div class="card-body py-2">
                    <div class="table-responsive">
                        <table class="table table-sm table-borderless mb-0">
                            <thead><tr><th>Member</th><th>Training</th><th>Date</th><th>Result</th></tr></thead>
                            <tbody id="trainRecentBody">
                                <tr><td colspan="4" class="text-center text-body-secondary py-3">Loading...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- FEMA IS Courses Reference -->
            <div class="card border-0 bg-body-tertiary">
                <div class="card-header py-1 bg-transparent border-bottom">
                    <span class="fw-semibold small"><i class="bi bi-building-check me-1"></i>FEMA IS Courses in System</span>
                </div>
                <div class="card-body py-2">
                    <div class="table-responsive" style="max-height: 300px; overflow-y: auto;">
                        <table class="table table-sm table-borderless mb-0">
                            <thead class="sticky-top" style="background: var(--bs-body-bg);">
                                <tr><th>Course Code</th><th>Name</th><th>Required</th><th>Refresh</th></tr>
                            </thead>
                            <tbody id="femaCourseBody">
                                <tr><td colspan="4" class="text-center text-body-secondary py-3">Loading...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- ═══ VEHICLE TYPES ═══ -->
        <div class="config-panel" id="panel-vehicles">
            <div class="config-panel-title">
                <i class="bi bi-truck text-primary"></i> Vehicle Types
            </div>
            <p class="text-body-secondary small mb-3">
                Manage vehicle type categories used for fleet classification.
                Individual vehicles are managed on the <a href="vehicles.php">Vehicles</a> page.
            </p>

            <!-- Add Vehicle Type Form -->
            <div class="card border-0 bg-body-tertiary mb-3">
                <div class="card-body py-2">
                    <div class="row g-2 align-items-end">
                        <div class="col-md-3">
                            <label class="form-label form-label-sm mb-0">Type Name</label>
                            <input type="text" class="form-control form-control-sm" id="vehTypeName" placeholder="e.g. Emergency Vehicle">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label form-label-sm mb-0">Description</label>
                            <input type="text" class="form-control form-control-sm" id="vehTypeDesc" placeholder="Short description">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label form-label-sm mb-0">Icon</label>
                            <input type="text" class="form-control form-control-sm" id="vehTypeIcon" value="bi-truck" placeholder="bi-truck">
                        </div>
                        <div class="col-md-1">
                            <label class="form-label form-label-sm mb-0">Order</label>
                            <input type="number" class="form-control form-control-sm" id="vehTypeSortOrder" value="0" min="0">
                        </div>
                        <div class="col-md-auto">
                            <button type="button" class="btn btn-sm btn-primary" id="btnAddVehType">
                                <i class="bi bi-plus-lg me-1"></i>Add
                            </button>
                        </div>
                    </div>
                    <input type="hidden" id="vehTypeEditId" value="0">
                </div>
            </div>

            <!-- Vehicle Types Table -->
            <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                <table class="table table-hover table-sm mb-0 align-middle">
                    <thead class="sticky-top" style="background: var(--bs-body-bg);">
                        <tr>
                            <th class="ps-3">Icon</th>
                            <th>Name</th>
                            <th>Description</th>
                            <th class="text-center" style="width: 50px;">Order</th>
                            <th class="text-center" style="width: 80px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="vehTypesBody">
                        <tr><td colspan="5" class="text-center text-body-secondary py-3">Loading...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ═══ EQUIPMENT TYPES ═══ -->
        <div class="config-panel" id="panel-equipment">
            <div class="config-panel-title">
                <i class="bi bi-box-seam text-primary"></i> Equipment Types
            </div>
            <p class="text-body-secondary small mb-3">
                Manage equipment type categories. Equipment supports both <strong>organization-owned</strong>
                items (tracked with asset tags and checkout/checkin) and <strong>personal</strong> items
                (volunteer-owned, listed for availability and lost+found).
                Individual items are managed on the <a href="equipment.php">Equipment</a> page.
            </p>

            <!-- Add Equipment Type Form -->
            <div class="card border-0 bg-body-tertiary mb-3">
                <div class="card-body py-2">
                    <div class="row g-2 align-items-end">
                        <div class="col-md-3">
                            <label class="form-label form-label-sm mb-0">Type Name</label>
                            <input type="text" class="form-control form-control-sm" id="eqTypeName" placeholder="e.g. Radio">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label form-label-sm mb-0">Description</label>
                            <input type="text" class="form-control form-control-sm" id="eqTypeDesc" placeholder="Short description">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label form-label-sm mb-0">Icon</label>
                            <input type="text" class="form-control form-control-sm" id="eqTypeIcon" value="bi-box" placeholder="bi-box">
                        </div>
                        <div class="col-md-1">
                            <label class="form-label form-label-sm mb-0">Order</label>
                            <input type="number" class="form-control form-control-sm" id="eqTypeSortOrder" value="0" min="0">
                        </div>
                        <div class="col-md-1">
                            <div class="form-check form-check-sm mt-3">
                                <input class="form-check-input" type="checkbox" id="eqTypeCheckout" checked>
                                <label class="form-check-label small" for="eqTypeCheckout">Track</label>
                            </div>
                        </div>
                        <div class="col-md-auto">
                            <button type="button" class="btn btn-sm btn-primary" id="btnAddEqType">
                                <i class="bi bi-plus-lg me-1"></i>Add
                            </button>
                        </div>
                    </div>
                    <input type="hidden" id="eqTypeEditId" value="0">
                </div>
            </div>

            <!-- Equipment Types Table -->
            <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                <table class="table table-hover table-sm mb-0 align-middle">
                    <thead class="sticky-top" style="background: var(--bs-body-bg);">
                        <tr>
                            <th class="ps-3">Icon</th>
                            <th>Name</th>
                            <th>Description</th>
                            <th class="text-center" style="width: 50px;">Order</th>
                            <th class="text-center" style="width: 60px;">Track</th>
                            <th class="text-center" style="width: 80px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="eqTypesBody">
                        <tr><td colspan="6" class="text-center text-body-secondary py-3">Loading...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ═══ MEMBERS / PERSONNEL OVERVIEW ═══ -->
        <div class="config-panel" id="panel-members">
            <div class="config-panel-title">
                <i class="bi bi-person-badge text-info"></i> Members / Personnel Overview
            </div>
            <p class="text-body-secondary small mb-3">
                Summary of your organization's personnel roster. Individual members are managed on the
                <a href="roster.php">Roster</a> page.
            </p>

            <!-- Summary Stats -->
            <div class="row g-3 mb-3" id="memberStatsRow">
                <div class="col-md-2">
                    <div class="card border-0 bg-body-tertiary">
                        <div class="card-body py-2 text-center">
                            <div class="fs-4 fw-bold" id="memStatTotal">—</div>
                            <div class="small text-body-secondary">Total Members</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="card border-0 bg-body-tertiary">
                        <div class="card-body py-2 text-center">
                            <div class="fs-4 fw-bold text-success" id="memStatAvail">—</div>
                            <div class="small text-body-secondary">Available</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="card border-0 bg-body-tertiary">
                        <div class="card-body py-2 text-center">
                            <div class="fs-4 fw-bold text-primary" id="memStatTeams">—</div>
                            <div class="small text-body-secondary">Teams</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="card border-0 bg-body-tertiary">
                        <div class="card-body py-2 text-center">
                            <div class="fs-4 fw-bold text-info" id="memStatCerts">—</div>
                            <div class="small text-body-secondary">Certifications</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- By Type -->
            <div class="row g-3">
                <div class="col-md-6">
                    <div class="card border-0 bg-body-tertiary">
                        <div class="card-header py-1 bg-transparent border-bottom">
                            <span class="fw-semibold small">Members by Type</span>
                        </div>
                        <div class="card-body py-2">
                            <table class="table table-sm table-borderless mb-0">
                                <thead><tr><th>Type</th><th class="text-end">Count</th></tr></thead>
                                <tbody id="memByTypeBody">
                                    <tr><td colspan="2" class="text-center text-body-secondary py-2">Loading...</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card border-0 bg-body-tertiary">
                        <div class="card-header py-1 bg-transparent border-bottom">
                            <span class="fw-semibold small">Members by Status</span>
                        </div>
                        <div class="card-body py-2">
                            <table class="table table-sm table-borderless mb-0">
                                <thead><tr><th>Status</th><th class="text-end">Count</th></tr></thead>
                                <tbody id="memByStatusBody">
                                    <tr><td colspan="2" class="text-center text-body-secondary py-2">Loading...</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="mt-3 d-flex gap-2">
                <a href="roster.php" class="btn btn-sm btn-primary">
                    <i class="bi bi-box-arrow-up-right me-1"></i>Open Roster
                </a>
                <a href="import-export.php" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-arrow-left-right me-1"></i>Import / Export
                </a>
            </div>
        </div>

        <!-- ═══ CERTIFICATIONS ═══ -->
        <div class="config-panel" id="panel-certifications">
            <div class="config-panel-title">
                <i class="bi bi-patch-check text-success"></i> Certifications
            </div>
            <p class="text-body-secondary small mb-3">
                Manage certification types that can be assigned to members. Includes FEMA IS courses,
                CPR/Medical, Radio, and HAZMAT categories. Individual member certifications are managed
                on the <a href="roster.php">Roster</a> page.
            </p>

            <!-- Add/Edit Form -->
            <div class="card border-0 bg-body-tertiary mb-3">
                <div class="card-body py-2">
                    <div class="row g-2 align-items-end">
                        <div class="col-md-3">
                            <label class="form-label form-label-sm mb-0">Name</label>
                            <input type="text" class="form-control form-control-sm" id="certName" placeholder="e.g. IS-100.c">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label form-label-sm mb-0">Category</label>
                            <select class="form-select form-select-sm" id="certCategory">
                                <option value="">-- Select --</option>
                                <option value="FEMA IS">FEMA IS</option>
                                <option value="CPR/Medical">CPR/Medical</option>
                                <option value="Radio">Radio</option>
                                <option value="HAZMAT">HAZMAT</option>
                                <option value="Weather">Weather</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label form-label-sm mb-0">FEMA Code</label>
                            <input type="text" class="form-control form-control-sm" id="certFemaCode" placeholder="e.g. IS-100">
                        </div>
                        <div class="col-md-1">
                            <label class="form-label form-label-sm mb-0">Refresh</label>
                            <input type="number" class="form-control form-control-sm" id="certRefreshMonths" placeholder="mo" min="0">
                        </div>
                        <div class="col-md-1">
                            <div class="form-check form-check-sm mt-3">
                                <input class="form-check-input" type="checkbox" id="certRequired">
                                <label class="form-check-label small" for="certRequired">Req'd</label>
                            </div>
                        </div>
                        <div class="col-md-auto">
                            <button type="button" class="btn btn-sm btn-primary" id="btnAddCert">
                                <i class="bi bi-plus-lg me-1"></i>Add
                            </button>
                        </div>
                    </div>
                    <div class="row g-2 mt-1">
                        <div class="col-md-4">
                            <label class="form-label form-label-sm mb-0">Description</label>
                            <input type="text" class="form-control form-control-sm" id="certDescription" placeholder="Short description (optional)">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label form-label-sm mb-0">NIMS Credential Type</label>
                            <input type="text" class="form-control form-control-sm" id="certNimsType" placeholder="e.g. Type III">
                        </div>
                    </div>
                    <input type="hidden" id="certEditId" value="0">
                </div>
            </div>

            <!-- Certifications Table -->
            <div class="table-responsive" style="max-height: 500px; overflow-y: auto;">
                <table class="table table-hover table-sm mb-0 align-middle">
                    <thead class="sticky-top" style="background: var(--bs-body-bg);">
                        <tr>
                            <th class="ps-3">Name</th>
                            <th>Category</th>
                            <th>FEMA Code</th>
                            <th class="text-center">Refresh</th>
                            <th class="text-center">Req'd</th>
                            <th class="text-center">Holders</th>
                            <th class="text-center" style="width: 80px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="certTableBody">
                        <tr><td colspan="7" class="text-center text-body-secondary py-3">Loading...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ═══ TEAMS CONFIG ═══ -->
        <div class="config-panel" id="panel-teams">
            <div class="config-panel-title">
                <i class="bi bi-people text-primary"></i> Teams
            </div>
            <p class="text-body-secondary small mb-3">
                View and manage organizational teams. Full team management (member assignment, roles, ICS positions)
                is available on the <a href="teams.php">Teams</a> page.
            </p>

            <!-- Teams Table -->
            <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                <table class="table table-hover table-sm mb-0 align-middle">
                    <thead class="sticky-top" style="background: var(--bs-body-bg);">
                        <tr>
                            <th class="ps-3">Team Name</th>
                            <th>Description</th>
                            <th class="text-center">Members</th>
                            <th>NIMS Type</th>
                        </tr>
                    </thead>
                    <tbody id="teamsConfigBody">
                        <tr><td colspan="4" class="text-center text-body-secondary py-3">Loading...</td></tr>
                    </tbody>
                </table>
            </div>

            <div class="mt-3">
                <a href="teams.php" class="btn btn-sm btn-primary">
                    <i class="bi bi-box-arrow-up-right me-1"></i>Open Teams Manager
                </a>
            </div>
        </div>

        <!-- ═══ MEMBER STATUSES ═══ -->
        <div class="config-panel" id="panel-member-statuses">
            <div class="config-panel-title">
                <i class="bi bi-toggle-on text-warning"></i> Member Statuses
            </div>
            <p class="text-body-secondary small mb-3">
                Define availability statuses for personnel (e.g., Available, Unavailable, On Duty, On Leave).
                These are used on the Roster to track member availability.
            </p>

            <!-- Add/Edit Form -->
            <div class="card border-0 bg-body-tertiary mb-3">
                <div class="card-body py-2">
                    <div class="row g-2 align-items-end">
                        <div class="col-md-3">
                            <label class="form-label form-label-sm mb-0">Status Name</label>
                            <input type="text" class="form-control form-control-sm" id="msName" placeholder="e.g. On Duty">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label form-label-sm mb-0">Description</label>
                            <input type="text" class="form-control form-control-sm" id="msDescription" placeholder="Short description">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label form-label-sm mb-0">Text Color</label>
                            <input type="text" class="form-control form-control-sm" id="msColor" value="Black" placeholder="e.g. Green">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label form-label-sm mb-0">Background</label>
                            <input type="text" class="form-control form-control-sm" id="msBackground" value="White" placeholder="e.g. White">
                        </div>
                        <div class="col-md-auto">
                            <button type="button" class="btn btn-sm btn-primary" id="btnAddMemberStatus">
                                <i class="bi bi-plus-lg me-1"></i>Add
                            </button>
                        </div>
                    </div>
                    <input type="hidden" id="msEditId" value="0">
                </div>
            </div>

            <!-- Statuses Table -->
            <div class="table-responsive">
                <table class="table table-hover table-sm mb-0 align-middle">
                    <thead class="sticky-top" style="background: var(--bs-body-bg);">
                        <tr>
                            <th class="ps-3">Preview</th>
                            <th>Status Name</th>
                            <th>Description</th>
                            <th class="text-center">Members</th>
                            <th class="text-center" style="width: 80px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="memberStatusBody">
                        <tr><td colspan="5" class="text-center text-body-secondary py-3">Loading...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ═══ MEMBER TYPES ═══ -->
        <div class="config-panel" id="panel-member-types">
            <div class="config-panel-title">
                <i class="bi bi-person-gear text-primary"></i> Member Types
            </div>
            <p class="text-body-secondary small mb-3">
                Define personnel classification types (e.g., Observer, Responder, Command Staff).
                These categorize members by their role and qualification level.
            </p>

            <!-- Add/Edit Form -->
            <div class="card border-0 bg-body-tertiary mb-3">
                <div class="card-body py-2">
                    <div class="row g-2 align-items-end">
                        <div class="col-md-2">
                            <label class="form-label form-label-sm mb-0">Type Name</label>
                            <input type="text" class="form-control form-control-sm" id="mtName" placeholder="e.g. Responder">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label form-label-sm mb-0">Description</label>
                            <input type="text" class="form-control form-control-sm" id="mtDescription" placeholder="e.g. Level 2 - Responder">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label form-label-sm mb-0">Text Color</label>
                            <input type="text" class="form-control form-control-sm" id="mtColor" value="Black" placeholder="e.g. Blue">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label form-label-sm mb-0">Background</label>
                            <input type="text" class="form-control form-control-sm" id="mtBackground" value="White" placeholder="e.g. White">
                        </div>
                        <div class="col-md-auto">
                            <button type="button" class="btn btn-sm btn-primary" id="btnAddMemberType">
                                <i class="bi bi-plus-lg me-1"></i>Add
                            </button>
                        </div>
                    </div>
                    <input type="hidden" id="mtEditId" value="0">
                </div>
            </div>

            <!-- Types Table -->
            <div class="table-responsive">
                <table class="table table-hover table-sm mb-0 align-middle">
                    <thead class="sticky-top" style="background: var(--bs-body-bg);">
                        <tr>
                            <th class="ps-3">Preview</th>
                            <th>Type Name</th>
                            <th>Description</th>
                            <th class="text-center">Members</th>
                            <th class="text-center" style="width: 80px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="memberTypeBody">
                        <tr><td colspan="5" class="text-center text-body-secondary py-3">Loading...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ── Scheduling Permissions ────────────────────────────── -->
        <div class="config-panel" id="panel-scheduling-permissions">
            <div class="config-panel-title">
                <i class="bi bi-shield-lock text-primary"></i> Scheduling Permissions
            </div>
            <p class="text-body-secondary small mb-2">
                Control what personnel can do with scheduling: view schedules, sign up for shifts, mark unavailable, swap with others, or manage assignments.
                Assign permission profiles globally or per-template/event/role/team/member.
            </p>

            <div class="settings-group">
                <div class="settings-group-title">Permission Profiles</div>
                <p class="text-body-secondary small mb-2">
                    Built-in profiles cover common scenarios. You can also create custom profiles.
                </p>
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0" style="font-size:0.8rem" id="schedPermProfilesTable">
                        <thead>
                            <tr>
                                <th>Profile</th>
                                <th class="text-center">View</th>
                                <th class="text-center">Self-Assign</th>
                                <th class="text-center">Unavailable</th>
                                <th class="text-center">Swap</th>
                                <th class="text-center">Assign Others</th>
                                <th class="text-center">Manage Slots</th>
                            </tr>
                        </thead>
                        <tbody id="schedPermProfilesBody">
                            <tr><td colspan="7" class="text-center text-body-secondary py-3"><div class="spinner-border spinner-border-sm me-1"></div>Loading...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="settings-group mt-3">
                <div class="settings-group-title">Permission Assignments</div>
                <p class="text-body-secondary small mb-2">
                    Assign profiles to control who gets what level of access. More specific assignments (per-member, per-template) override broader ones (global, all).
                </p>
                <div id="schedPermAssignmentsList" class="mb-2">
                    <div class="text-body-secondary small">Loading assignments...</div>
                </div>
                <button class="btn btn-sm btn-outline-primary" id="btnAddSchedPermAssignment">
                    <i class="bi bi-plus-lg me-1"></i>Add Permission Assignment
                </button>
            </div>
        </div>

        <!-- ── Unit Assignment Roles ──────────────────────────────── -->
        <div class="config-panel" id="panel-unit-assignment-roles">
            <div class="config-panel-title">
                <i class="bi bi-people text-info"></i> Unit Assignment Roles
            </div>
            <p class="text-body-secondary small mb-2">
                Define roles for personnel assigned to units (e.g., Commander, Operator, Driver, Medic).
                These appear in the dropdown when assigning personnel to a unit.
            </p>

            <div class="card border-0 bg-body-tertiary mb-3">
                <div class="card-body py-2">
                    <div class="row g-2 align-items-end">
                        <div class="col-md-2">
                            <label class="form-label form-label-sm mb-0">Code</label>
                            <input type="text" class="form-control form-control-sm" id="uarCode" placeholder="e.g. medic">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label form-label-sm mb-0">Name</label>
                            <input type="text" class="form-control form-control-sm" id="uarName" placeholder="e.g. Medic/EMT">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label form-label-sm mb-0">Description</label>
                            <input type="text" class="form-control form-control-sm" id="uarDescription" placeholder="e.g. Medical personnel">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label form-label-sm mb-0">Sort Order</label>
                            <input type="number" class="form-control form-control-sm" id="uarSortOrder" value="50" min="1">
                        </div>
                        <div class="col-md-auto">
                            <button type="button" class="btn btn-sm btn-primary" id="btnAddUnitRole">
                                <i class="bi bi-plus-lg me-1"></i>Add
                            </button>
                        </div>
                    </div>
                    <input type="hidden" id="uarEditId" value="0">
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-hover table-sm mb-0 align-middle" id="unitRolesTable">
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Name</th>
                            <th>Description</th>
                            <th class="text-center">Sort</th>
                            <th class="text-center" style="width:80px">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="unitRolesBody">
                        <tr><td colspan="5" class="text-center text-body-secondary py-3"><div class="spinner-border spinner-border-sm me-1"></div>Loading...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Organizations Panel -->
        <div class="config-panel" id="panel-organizations">
            <h5 class="mb-1"><i class="bi bi-building me-2"></i>Organizations</h5>
            <p class="text-body-secondary small mb-3">Manage agencies, departments, and groups. Members can belong to multiple organizations with different roles in each.</p>

            <!-- Add/Edit Form -->
            <div class="card mb-3" id="orgForm" style="display:none;">
                <div class="card-body py-2">
                    <input type="hidden" id="orgEditId" value="0">
                    <div class="row g-2 mb-2">
                        <div class="col-md-4">
                            <label class="form-label form-label-sm mb-0">Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control form-control-sm" id="orgName" placeholder="e.g. Bloomington AUXCOMM">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label form-label-sm mb-0">Short Name</label>
                            <input type="text" class="form-control form-control-sm" id="orgShortName" placeholder="AUXCOMM" maxlength="32">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label form-label-sm mb-0">Type</label>
                            <select class="form-select form-select-sm" id="orgType">
                                <option value="">-- Select --</option>
                                <option>RACES</option>
                                <option>ARES</option>
                                <option>CERT</option>
                                <option>Fire</option>
                                <option>EMS</option>
                                <option>Campus PD</option>
                                <option>Radio Club</option>
                                <option>SAR</option>
                                <option>General</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label form-label-sm mb-0">
                                Parent
                                <i class="bi bi-question-circle text-body-secondary" tabindex="0"
                                   data-bs-toggle="popover" data-bs-trigger="hover focus" data-bs-placement="top"
                                   data-bs-content="Optional. Build a hierarchy like Statewide -> County -> District. Users assigned to a parent org see all descendant orgs' data."></i>
                            </label>
                            <select class="form-select form-select-sm" id="orgParentId">
                                <option value="">— None (top-level) —</option>
                                <!-- Phase 99j-3 (Billy beta 2026-06-29): populated by loadOrganizations() -->
                            </select>
                        </div>
                        <div class="col-md-1">
                            <label class="form-label form-label-sm mb-0">Order</label>
                            <input type="number" class="form-control form-control-sm" id="orgSortOrder" value="0" min="0">
                        </div>
                        <div class="col-md-1 d-flex align-items-end gap-1">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="orgActive" checked>
                                <label class="form-check-label small" for="orgActive">Active</label>
                            </div>
                        </div>
                    </div>
                    <div class="row g-2 mb-2">
                        <div class="col-md-4">
                            <label class="form-label form-label-sm mb-0">Contact Name</label>
                            <input type="text" class="form-control form-control-sm" id="orgContactName">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label form-label-sm mb-0">Contact Email</label>
                            <input type="email" class="form-control form-control-sm" id="orgContactEmail">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label form-label-sm mb-0">Contact Phone</label>
                            <input type="text" class="form-control form-control-sm" id="orgContactPhone">
                        </div>
                    </div>
                    <div class="row g-2 mb-2">
                        <div class="col-12">
                            <label class="form-label form-label-sm mb-0">Description</label>
                            <textarea class="form-control form-control-sm" id="orgDescription" rows="2"></textarea>
                        </div>
                    </div>
                    <div class="d-flex gap-2">
                        <button class="btn btn-sm btn-primary" id="orgSaveBtn"><i class="bi bi-check-lg me-1"></i>Save</button>
                        <button class="btn btn-sm btn-secondary" id="orgCancelBtn">Cancel</button>
                    </div>
                </div>
            </div>

            <button class="btn btn-sm btn-success mb-2" id="orgAddBtn"><i class="bi bi-plus-lg me-1"></i>Add Organization</button>

            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead>
                        <tr>
                            <th class="ps-3">Name</th>
                            <th>Short</th>
                            <th>Type</th>
                            <th class="text-center">Members</th>
                            <th class="text-center">Active</th>
                            <th class="text-center">Order</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="orgTableBody">
                        <tr><td colspan="7" class="text-center text-body-secondary py-3">Loading...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Comm / Location Modes Panel -->
        <div class="config-panel" id="panel-comm-modes">
            <h5 class="mb-1"><i class="bi bi-broadcast me-2"></i>Communication / Location Modes</h5>
            <p class="text-body-secondary small mb-3">Define the communication and location systems your organization uses. Each mode specifies what fields to collect per member (e.g., APRS callsign-SSID, DMR Radio ID, Meshtastic node).</p>

            <!-- Add/Edit Form -->
            <div class="card mb-3" id="commModeForm" style="display:none;">
                <div class="card-body py-2">
                    <input type="hidden" id="commModeEditId" value="0">
                    <div class="row g-2 mb-2">
                        <div class="col-md-3">
                            <label class="form-label form-label-sm mb-0">Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control form-control-sm" id="commModeName" placeholder="e.g. APRS">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label form-label-sm mb-0">Code <span class="text-danger">*</span></label>
                            <input type="text" class="form-control form-control-sm" id="commModeCode" placeholder="aprs" maxlength="32">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label form-label-sm mb-0">Icon (bi-*)</label>
                            <input type="text" class="form-control form-control-sm" id="commModeIcon" placeholder="broadcast">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label form-label-sm mb-0">Color</label>
                            <input type="color" class="form-control form-control-sm form-control-color" id="commModeColor" value="#6c757d">
                        </div>
                        <div class="col-md-1">
                            <label class="form-label form-label-sm mb-0">Order</label>
                            <input type="number" class="form-control form-control-sm" id="commModeSortOrder" value="0" min="0">
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="commModeEnabled" checked>
                                <label class="form-check-label small" for="commModeEnabled">Enabled</label>
                            </div>
                        </div>
                    </div>
                    <div class="row g-2 mb-2">
                        <div class="col-md-6">
                            <label class="form-label form-label-sm mb-0">Lookup URL (optional)</label>
                            <input type="text" class="form-control form-control-sm" id="commModeLookupUrl" placeholder="https://api.example.com/lookup?q={callsign}">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label form-label-sm mb-0">Notes</label>
                            <input type="text" class="form-control form-control-sm" id="commModeNotes">
                        </div>
                    </div>

                    <!-- Dynamic Field Builder -->
                    <label class="form-label form-label-sm mb-1 fw-semibold">Field Definitions</label>
                    <div class="table-responsive mb-2">
                        <table class="table table-sm table-bordered mb-0" id="commModeFieldsTable">
                            <thead>
                                <tr class="table-light">
                                    <th style="width:18%">Key</th>
                                    <th style="width:22%">Label</th>
                                    <th style="width:12%">Type</th>
                                    <th style="width:18%">Placeholder</th>
                                    <th style="width:8%">Max</th>
                                    <th style="width:7%">Req</th>
                                    <th style="width:5%"></th>
                                </tr>
                            </thead>
                            <tbody id="commModeFieldsBody">
                            </tbody>
                        </table>
                    </div>
                    <button class="btn btn-sm btn-outline-secondary mb-2" id="commModeAddFieldBtn"><i class="bi bi-plus me-1"></i>Add Field</button>

                    <div class="d-flex gap-2">
                        <button class="btn btn-sm btn-primary" id="commModeSaveBtn"><i class="bi bi-check-lg me-1"></i>Save</button>
                        <button class="btn btn-sm btn-secondary" id="commModeCancelBtn">Cancel</button>
                    </div>
                </div>
            </div>

            <button class="btn btn-sm btn-success mb-2" id="commModeAddBtn"><i class="bi bi-plus-lg me-1"></i>Add Comm Mode</button>

            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead>
                        <tr>
                            <th class="ps-3">Name</th>
                            <th>Code</th>
                            <th class="text-center">Icon</th>
                            <th class="text-center">Fields</th>
                            <th class="text-center">In Use</th>
                            <th class="text-center">Enabled</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="commModeTableBody">
                        <tr><td colspan="7" class="text-center text-body-secondary py-3">Loading...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ── Regions ────────────────────────────────────────────── -->
        <div class="config-panel" id="panel-regions">
            <div class="config-panel-title">
                <i class="bi bi-map text-primary"></i> Regions
            </div>
            <p class="text-body-secondary small mb-2">Define geographic regions for your organization. Each region can have its own default area code, city, state, and map boundary.</p>
            <div id="regionsContent">
                <div class="text-center py-3"><div class="spinner-border spinner-border-sm"></div> Loading...</div>
            </div>
        </div>

        <!-- ── Places ────────────────────────────────────────────── -->
        <div class="config-panel" id="panel-places">
            <div class="config-panel-title">
                <i class="bi bi-geo text-success"></i> Places
            </div>
            <p class="text-body-secondary small mb-2">
                Named locations for quick address entry. When a dispatcher types <code>The Stadium</code>, <code>Main &amp; 5th</code>, or any saved place name into the address field on the New Incident form, the address and lat/lng auto-fill.
            </p>
            <div class="d-flex gap-2 mb-2 align-items-center">
                <button type="button" class="btn btn-sm btn-success" id="btnNewPlace"><i class="bi bi-plus-lg me-1"></i>New Place</button>
                <!-- GH #36 follow-up (2026-07-08) — bulk export/import moved to
                     the unified Import/Export page with every other table,
                     per a beta tester's consistency note + Eric's decision. -->
                <a class="btn btn-sm btn-outline-primary" href="import-export.php" title="Bulk import/export places with the unified tool">
                    <i class="bi bi-arrow-left-right me-1"></i>Import / Export…
                </a>
                <input type="text" class="form-control form-control-sm ms-auto" placeholder="Filter places…" id="placesFilter" style="max-width:240px;">
            </div>
            <div id="placesBody">
                <div class="text-body-secondary p-3 small">Loading places…</div>
            </div>
        </div>

        <!-- Phase 108 — Places import modal -->
        <div class="modal fade" id="importPlacesModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header py-2">
                        <h6 class="modal-title"><i class="bi bi-upload me-1"></i>Import Places</h6>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body py-2">
                        <p class="small text-body-secondary mb-2">
                            Upload a CSV or JSON file matching the export format. Matching is by <code>name</code> (case-insensitive) — existing places update, novel names insert. Run a dry run first to see what would happen before writing.
                        </p>
                        <div class="mb-2">
                            <label class="form-label form-label-sm mb-0">File</label>
                            <input type="file" class="form-control form-control-sm" id="importPlacesFile" accept=".csv,.json,application/json,text/csv">
                        </div>
                        <div class="row g-2 mb-2">
                            <div class="col-6">
                                <label class="form-label form-label-sm mb-0">Format</label>
                                <select class="form-select form-select-sm" id="importPlacesFormat">
                                    <option value="csv" selected>CSV</option>
                                    <option value="json">JSON</option>
                                </select>
                            </div>
                            <div class="col-6 d-flex align-items-end">
                                <div class="form-check form-check-sm mb-1">
                                    <input class="form-check-input" type="checkbox" id="importPlacesDryRun" checked>
                                    <label class="form-check-label small" for="importPlacesDryRun">Dry run (don't write)</label>
                                </div>
                            </div>
                        </div>
                        <div id="importPlacesResult" class="small"></div>
                    </div>
                    <div class="modal-footer py-1">
                        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="button" class="btn btn-sm btn-primary" id="btnImportPlacesSubmit">
                            <i class="bi bi-check-lg me-1"></i>Run
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Phase 41 — Map Overlay Categories -->
        <div class="config-panel" id="panel-map-overlay-categories">
            <div class="config-panel-title">
                <i class="bi bi-layers-fill text-info"></i> Map Overlays
            </div>
            <p class="text-body-secondary small mb-2">
                Draw shapes (polygons, circles, lines, markers) directly on the map and group them by category. Dispatchers can toggle each category on or off from the live map. Use this for event zones, parade routes, precinct boundaries — anything operators need to see for context without generating alerts.
            </p>

            <!-- ── Categories list ───────────────────────────────── -->
            <div class="settings-group mb-3">
                <div class="settings-group-title">Categories</div>
                <div class="d-flex gap-2 mb-2 align-items-center">
                    <button type="button" class="btn btn-sm btn-success" id="btnNewMapCat"><i class="bi bi-plus-lg me-1"></i>New Category</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btnMapCatsRefresh"><i class="bi bi-arrow-clockwise"></i></button>
                </div>
                <div id="mapCatsBody">
                    <div class="text-body-secondary p-3 small">Loading categories…</div>
                </div>
            </div>

            <!-- ── Inline drawing editor ─────────────────────────── -->
            <div class="settings-group mb-3">
                <div class="settings-group-title d-flex justify-content-between align-items-center">
                    <span>Draw Shapes</span>
                    <div class="d-flex gap-2 align-items-center">
                        <span class="small text-body-secondary d-none d-md-inline">Resize: drag bottom-right corner</span>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="moMapFullscreen" title="Toggle fullscreen map">
                            <i class="bi bi-arrows-fullscreen"></i>
                        </button>
                    </div>
                </div>

                <div class="row g-2 mb-2 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label form-label-sm mb-1">Active Category</label>
                        <select class="form-select form-select-sm" id="moEditorCategory">
                            <option value="">— pick a category to draw into —</option>
                        </select>
                    </div>
                    <div class="col-md-8">
                        <div class="btn-group btn-group-sm" id="moDrawToolbar">
                            <button type="button" class="btn btn-outline-primary" id="moDrawPolygon" disabled title="Draw a polygon (zone)"><i class="bi bi-pentagon me-1"></i>Polygon</button>
                            <button type="button" class="btn btn-outline-primary" id="moDrawCircle"  disabled title="Draw a circle (radius)"><i class="bi bi-circle me-1"></i>Circle</button>
                            <button type="button" class="btn btn-outline-primary" id="moDrawLine"    disabled title="Draw a line (route)"><i class="bi bi-slash-lg me-1"></i>Line</button>
                            <button type="button" class="btn btn-outline-primary" id="moDrawMarker"  disabled title="Drop a marker (point)"><i class="bi bi-geo-alt me-1"></i>Marker</button>
                        </div>
                        <button type="button" class="btn btn-sm btn-success d-none ms-1" id="moDrawFinish"><i class="bi bi-check-lg me-1"></i>Finish</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary d-none ms-1" id="moDrawCancel"><i class="bi bi-x-lg me-1"></i>Cancel</button>
                        <span class="small text-body-secondary ms-2" id="moDrawStatus"></span>
                    </div>
                </div>

                <!-- Resizable map container -->
                <div class="border rounded position-relative" id="moMapWrap"
                     style="resize: both; overflow: hidden; width: 100%; height: 450px; min-height: 240px; min-width: 320px; max-width: 100%;">
                    <div id="moEditorMap" style="width:100%; height:100%; border-radius:5px;"></div>
                </div>
                <div class="small text-body-secondary mt-1">
                    <i class="bi bi-info-circle me-1"></i>Pick a category, click a tool, then click on the map. Polygon &amp; line collect clicks — press <strong>Finish</strong> to close the shape and name it. Shapes inherit the category color and the saved label always shows on the dispatcher map.
                </div>
            </div>

            <!-- ── Shapes already drawn ──────────────────────────── -->
            <div class="settings-group">
                <div class="settings-group-title d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <span>Shapes</span>
                    <div class="d-flex gap-2 flex-wrap">
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="moShapesRefresh"><i class="bi bi-arrow-clockwise me-1"></i>Refresh</button>

                        <!-- Phase 43c: export current category (or all if none selected) -->
                        <div class="btn-group btn-group-sm">
                            <button type="button" class="btn btn-outline-success dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="bi bi-download me-1"></i>Export
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li><h6 class="dropdown-header">Export <span id="moExportScope">all shapes</span> as:</h6></li>
                                <li><a class="dropdown-item" href="#" id="moExportGeoJson"><i class="bi bi-filetype-json me-1"></i>GeoJSON <span class="text-body-secondary small">— web / GIS</span></a></li>
                                <li><a class="dropdown-item" href="#" id="moExportKml"><i class="bi bi-globe2 me-1"></i>KML <span class="text-body-secondary small">— Google Earth, ATAK, Caltopo</span></a></li>
                                <li><a class="dropdown-item" href="#" id="moExportGpx"><i class="bi bi-geo me-1"></i>GPX <span class="text-body-secondary small">— Garmin / consumer GPS</span></a></li>
                            </ul>
                        </div>

                        <!-- Phase 43c + maps-comprehensive-2026-06: import GeoJSON / KML / KMZ -->
                        <button type="button" class="btn btn-sm btn-outline-primary" id="moImportBtn"
                                title="Import shapes from GeoJSON, KML, or KMZ (zipped KML)"><i class="bi bi-upload me-1"></i>Import</button>
                        <input type="file" id="moImportFile" accept=".geojson,.json,.kml,.xml,.kmz" style="display:none">
                    </div>
                </div>
                <div id="moShapesBody"><div class="text-body-secondary small p-3">Pick a category above to see its shapes.</div></div>
            </div>
        </div>

        <!-- ── Notifications ─────────────────────────────────────── -->
        <div class="config-panel" id="panel-notifications">
            <div class="config-panel-title">
                <i class="bi bi-bell text-warning"></i> Notification Rules
            </div>
            <p class="text-body-secondary small mb-2">Configure which events trigger notifications, who receives them, and through which channels (email, SMS, push).</p>
            <div class="alert alert-info small">
                <i class="bi bi-info-circle me-1"></i>Notification rules will be configurable per incident severity, type, and region. Each rule can target email groups, individual users, or SMS recipients.
            </div>
        </div>

        <!-- ── Web Push Notifications (Phase 96 admin, 2026-06-28) ───── -->
        <div class="config-panel" id="panel-push-notifications">
            <div class="config-panel-title">
                <i class="bi bi-bell-fill text-warning"></i> Web Push Notifications
            </div>
            <p class="text-body-secondary small mb-2">
                Browser-delivered push notifications for incidents, messages, and
                status changes. Works on desktop (Chrome, Firefox, Edge) and
                mobile (Android Chrome, iOS 16.4+ in installed PWA). Requires
                HTTPS in production.
            </p>

            <?php
            // GH #8 (2026-07-13, found via the Diagnostics page): the silent
            // failure. `minishlink/web-push` comes from composer; `vendor/` is
            // gitignored, so an install that never ran `composer install` has
            // push_enabled honored by browsers (they subscribe) but the server
            // can't actually deliver — no error the admin ever sees. Surface it
            // loudly, server-rendered, right where push is configured.
            $__webpush_lib = class_exists('Minishlink\\WebPush\\WebPush')
                || is_dir(__DIR__ . '/vendor/minishlink/web-push');
            if (!$__webpush_lib):
                $__push_on = false;
                try {
                    $__push_on = (string) db_fetch_value(
                        "SELECT value FROM `" . ($GLOBALS['db_prefix'] ?? '')
                        . "settings` WHERE name = 'push_enabled' LIMIT 1") === '1';
                } catch (Throwable $e) { /* pre-settings install */ }
            ?>
            <div class="alert <?php echo $__push_on ? 'alert-danger' : 'alert-warning'; ?> small d-flex gap-2">
                <i class="bi bi-exclamation-octagon-fill fs-5"></i>
                <div>
                    <strong>The Web Push PHP library isn't installed on this server.</strong>
                    <?php if ($__push_on): ?>
                    Push is turned <strong>on</strong>, so browsers can subscribe &mdash; but
                    <strong>notifications are not being delivered</strong> and won't be until this is fixed.
                    <?php else: ?>
                    Push won't be able to deliver notifications until this is installed.
                    <?php endif; ?>
                    Run this once in the install directory (over SSH), then reload:
                    <pre class="mb-1 mt-1 p-2 bg-body-tertiary rounded"><code>composer install --no-dev --optimize-autoloader</code></pre>
                    On shared hosting without SSH/Composer, see
                    <code>docs/INSTALL.md</code> &rarr; &ldquo;Notifications aren't delivered&rdquo;.
                    The <a href="diagnostics.php">Diagnostics</a> page confirms once it's resolved.
                </div>
            </div>
            <?php endif; ?>

            <form id="pushAdminForm">
                <div class="settings-group">
                    <div class="settings-group-title">VAPID Keypair</div>
                    <p class="small text-body-secondary mb-2">
                        Identifies this TicketsCAD install to push services
                        (FCM, Mozilla, Apple). Generated once per install; do
                        NOT share the private key. Rotating the keypair leaves
                        existing browser subscriptions valid but new
                        registrations will use the new public key.
                    </p>
                    <div class="row g-2 align-items-end">
                        <div class="col-md-8">
                            <label class="form-label form-label-sm">Public Key</label>
                            <input type="text" class="form-control form-control-sm font-monospace"
                                   id="pushVapidPublicKey" readonly
                                   placeholder="Generate a keypair to populate this">
                            <div class="form-text">Sent to browsers as the <code>applicationServerKey</code>.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label form-label-sm">Private Key</label>
                            <input type="text" class="form-control form-control-sm"
                                   id="pushVapidPrivateState" readonly value="(not set)"
                                   placeholder="(not set)">
                            <div class="form-text">Stored server-side; never displayed.</div>
                        </div>
                    </div>
                    <div class="d-flex gap-2 mt-2">
                        <button type="button" class="btn btn-sm btn-outline-warning" id="btnRegenerateVapid">
                            <i class="bi bi-arrow-clockwise me-1"></i>Generate New Keypair
                        </button>
                        <span class="small text-body-secondary align-self-center" id="pushKeyStatus"></span>
                    </div>
                </div>

                <div class="settings-group">
                    <div class="settings-group-title">Contact + Activation</div>
                    <div class="row g-2">
                        <div class="col-md-6">
                            <label for="pushVapidSubject" class="form-label form-label-sm">
                                VAPID Subject
                                <i class="bi bi-question-circle text-body-secondary" tabindex="0"
                                   data-bs-toggle="popover" data-bs-trigger="hover focus" data-bs-placement="top"
                                   data-bs-html="true"
                                   data-bs-content="Required by <a href='https://datatracker.ietf.org/doc/html/rfc8292' target='_blank'>RFC 8292</a>. Push services use this to contact you if your TicketsCAD install is misbehaving (excessive failed deliveries, abuse reports, etc).<br><br>Format: <code>mailto:admin@example.com</code> or <code>https://your.org/contact</code>.<br><br>If left blank, push delivery may degrade or be refused entirely by some providers."
                                   title="VAPID subject help"></i>
                            </label>
                            <input type="text" class="form-control form-control-sm"
                                   id="pushVapidSubject" placeholder="mailto:admin@yourorg.com">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label form-label-sm">Enabled</label>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="pushEnabled">
                                <label class="form-check-label small" for="pushEnabled">Allow push delivery</label>
                            </div>
                            <div class="form-text">Browsers can't subscribe when disabled.</div>
                        </div>
                    </div>
                </div>

                <div class="settings-group">
                    <div class="settings-group-title">Subscribers</div>
                    <p class="small mb-0">
                        <span class="badge bg-info" id="pushSubCount">0</span>
                        active subscriptions from
                        <span class="badge bg-info" id="pushSubUserCount">0</span>
                        users.
                    </p>
                </div>

                <div class="d-flex gap-2 mt-3">
                    <button type="submit" class="btn btn-sm btn-primary">
                        <i class="bi bi-check-lg me-1"></i>Save
                    </button>
                    <!-- Phase 96 follow-on (2026-06-28) — admin-side smoke test.
                         Sends a test notification to ALL of the calling user's
                         own browser subscriptions. Useful right after VAPID
                         setup to confirm the full stack (keypair + SW + perms)
                         actually delivers. -->
                    <button type="button" class="btn btn-sm btn-outline-primary" id="btnTestPush">
                        <i class="bi bi-bell me-1"></i>Send Test Push to My Devices
                    </button>
                </div>
            </form>
        </div>

        <!-- ── Message Routing ──────────────────────────────────── -->
        <div class="config-panel" id="panel-message-routing">
            <div class="config-panel-title">
                <i class="bi bi-diagram-3 text-warning"></i> Message Routing
            </div>
            <p class="text-body-secondary small mb-2">Bridge messages between protocols (Meshtastic, Zello, DMR, Chat, SMS, Email). Routes evaluate all matching rules and forward messages to destination channels.</p>

            <!-- Enabled delivery channels -->
            <div class="card mb-3" id="enabledChannelsCard">
                <div class="card-header py-2 d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-broadcast me-1"></i>Enabled delivery channels</span>
                    <button type="button" class="btn btn-sm btn-primary" id="btnSaveEnabledChannels">
                        <i class="bi bi-save me-1"></i>Save
                    </button>
                </div>
                <div class="card-body py-2">
                    <p class="text-body-secondary small mb-2">A routing rule only delivers to channels enabled here; others log as skipped.</p>
                    <div class="row g-2" id="enabledChannelsList">
                        <div class="col-12 text-body-secondary small">Loading channels...</div>
                    </div>
                    <div class="small mt-2" id="enabledChannelsStatus"></div>
                </div>
            </div>

            <!-- Stats cards -->
            <div class="row g-2 mb-3" id="routingStats">
                <div class="col-auto"><span class="badge bg-success" id="routingActiveCount">0</span> Active Routes</div>
                <div class="col-auto"><span class="badge bg-primary" id="routingFwd24h">0</span> Forwarded (24h)</div>
                <div class="col-auto"><span class="badge bg-danger" id="routingFail24h">0</span> Failed (24h)</div>
                <div class="col-auto"><span class="badge bg-warning text-dark" id="routingBlocked24h">0</span> Loop Blocked (24h)</div>
            </div>

            <!-- Toolbar -->
            <div class="d-flex gap-2 mb-2">
                <button type="button" class="btn btn-sm btn-success" id="btnCreateRoute"><i class="bi bi-plus-lg me-1"></i>New Route</button>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="btnTestRoute"><i class="bi bi-play-circle me-1"></i>Test Message</button>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="btnViewRoutingLog"><i class="bi bi-clock-history me-1"></i>View Log</button>
            </div>

            <!-- Routes table -->
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle" id="routesTable">
                    <thead>
                        <tr>
                            <th style="width:40px">Pri</th>
                            <th>Name</th>
                            <th>Source</th>
                            <th style="width:30px"></th>
                            <th>Destination</th>
                            <th>Dir</th>
                            <th>Filters</th>
                            <th style="width:60px">Enabled</th>
                            <th style="width:80px">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="routesTableBody">
                        <tr><td colspan="9" class="text-center text-body-secondary small">Loading...</td></tr>
                    </tbody>
                </table>
            </div>

            <!-- Routing Log (hidden by default) -->
            <div id="routingLogSection" style="display:none" class="mt-3">
                <h6 class="mb-2"><i class="bi bi-clock-history me-1"></i>Routing Log</h6>
                <div class="table-responsive">
                    <table class="table table-sm table-hover" id="routingLogTable">
                        <thead>
                            <tr><th>Time</th><th>Route</th><th>Source</th><th></th><th>Dest</th><th>Status</th><th>Summary</th></tr>
                        </thead>
                        <tbody id="routingLogBody"></tbody>
                    </table>
                </div>
                <nav><ul class="pagination pagination-sm" id="routingLogPager"></ul></nav>
            </div>
        </div>

        <!-- Route Create/Edit Modal -->
        <div class="modal fade" id="routeModal" tabindex="-1">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="routeModalTitle">New Route</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <form id="routeForm">
                        <div class="modal-body">
                            <input type="hidden" id="routeId" value="">
                            <div class="row g-2 mb-2">
                                <div class="col-md-6">
                                    <label class="form-label form-label-sm">Route Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control form-control-sm" id="routeName" required placeholder="e.g. Meshtastic to Dispatch Chat">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label form-label-sm">Priority</label>
                                    <input type="number" class="form-control form-control-sm" id="routePriority" value="100" min="1" max="9999">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label form-label-sm">Enabled</label>
                                    <div class="form-check form-switch mt-1">
                                        <input class="form-check-input" type="checkbox" id="routeEnabled" checked>
                                    </div>
                                </div>
                            </div>
                            <div class="mb-2">
                                <label class="form-label form-label-sm">Description</label>
                                <input type="text" class="form-control form-control-sm" id="routeDescription" placeholder="Optional description">
                            </div>
                            <div class="row g-2 mb-2">
                                <div class="col-md-4">
                                    <label class="form-label form-label-sm">Source Channel <span class="text-danger">*</span></label>
                                    <select class="form-select form-select-sm" id="routeSource">
                                        <option value="*">Any Channel (*)</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label form-label-sm">Destination Channel <span class="text-danger">*</span></label>
                                    <select class="form-select form-select-sm" id="routeDest"></select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label form-label-sm">Direction</label>
                                    <select class="form-select form-select-sm" id="routeDirection">
                                        <option value="both">Both</option>
                                        <option value="inbound">Inbound Only</option>
                                        <option value="outbound">Outbound Only</option>
                                    </select>
                                </div>
                            </div>

                            <!-- Phase D: mesh destination sub-address (shown only for mesh:* dests) -->
                            <div class="card card-body p-2 mb-2" id="routeMeshSub" style="display:none;">
                                <div class="small text-body-secondary mb-1"><i class="bi bi-broadcast me-1"></i>Mesh destination — channel broadcast or a direct unit/person.</div>
                                <div class="row g-2">
                                    <div class="col-md-4">
                                        <label class="form-label form-label-sm">Target</label>
                                        <select class="form-select form-select-sm" id="routeMeshTargetKind">
                                            <option value="channel">Channel (broadcast)</option>
                                            <option value="unit">Unit / Person (direct)</option>
                                            <option value="node">Raw node address (direct)</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4" id="routeMeshSlotWrap">
                                        <label class="form-label form-label-sm">Channel Slot</label>
                                        <select class="form-select form-select-sm" id="routeMeshSlot">
                                            <option value="0">0 — Primary / Public</option>
                                            <option value="1">1</option>
                                            <option value="2">2</option>
                                            <option value="3">3</option>
                                            <option value="4">4</option>
                                            <option value="5">5</option>
                                            <option value="6">6</option>
                                            <option value="7">7</option>
                                        </select>
                                    </div>
                                    <div class="col-md-8" id="routeMeshUnitWrap" style="display:none;">
                                        <label class="form-label form-label-sm">Unit / Person</label>
                                        <select class="form-select form-select-sm" id="routeMeshUnit"><option value="">— select —</option></select>
                                        <div class="form-text small" id="routeMeshUnitHint"></div>
                                    </div>
                                    <div class="col-md-8" id="routeMeshNodeWrap" style="display:none;">
                                        <label class="form-label form-label-sm">Node Address</label>
                                        <input type="text" class="form-control form-control-sm" id="routeMeshNode" placeholder="!a2a79f57 or MeshCore pubkey prefix">
                                    </div>
                                </div>
                            </div>

                            <!-- Phase D: Zello destination sub-address (shown only for zello dest) -->
                            <div class="card card-body p-2 mb-2" id="routeZelloSub" style="display:none;">
                                <div class="small text-body-secondary mb-1"><i class="bi bi-mic me-1"></i>Zello destination — a channel or a user.</div>
                                <div class="row g-2">
                                    <div class="col-md-4">
                                        <label class="form-label form-label-sm">Target</label>
                                        <select class="form-select form-select-sm" id="routeZelloTargetKind">
                                            <option value="channel">Channel</option>
                                            <option value="user">User (DM)</option>
                                        </select>
                                    </div>
                                    <div class="col-md-8">
                                        <label class="form-label form-label-sm" id="routeZelloValueLabel">Channel name</label>
                                        <input type="text" class="form-control form-control-sm" id="routeZelloValue" placeholder="Leave blank for default dispatch channel">
                                    </div>
                                </div>
                            </div>

                            <!-- Filters (collapsible) -->
                            <div class="mb-2">
                                <button class="btn btn-sm btn-outline-secondary w-100 text-start" type="button" data-bs-toggle="collapse" data-bs-target="#routeFiltersCollapse">
                                    <i class="bi bi-funnel me-1"></i>Filters <span class="text-body-secondary small">(optional — leave blank to match all messages)</span>
                                </button>
                                <div class="collapse mt-2" id="routeFiltersCollapse">
                                    <div class="card card-body p-2">
                                        <div class="row g-2">
                                            <div class="col-md-4">
                                                <label class="form-label form-label-sm">Min Severity</label>
                                                <select class="form-select form-select-sm" id="filterSeverityMin">
                                                    <option value="">Any</option>
                                                    <option value="1">1 (Low)</option>
                                                    <option value="2">2 (Medium)</option>
                                                    <option value="3">3 (High)</option>
                                                </select>
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label form-label-sm">Priority</label>
                                                <div>
                                                    <div class="form-check form-check-inline form-check-sm">
                                                        <input class="form-check-input" type="checkbox" id="filterPriNormal" value="normal">
                                                        <label class="form-check-label small" for="filterPriNormal">Normal</label>
                                                    </div>
                                                    <div class="form-check form-check-inline form-check-sm">
                                                        <input class="form-check-input" type="checkbox" id="filterPriHigh" value="high">
                                                        <label class="form-check-label small" for="filterPriHigh">High</label>
                                                    </div>
                                                    <div class="form-check form-check-inline form-check-sm">
                                                        <input class="form-check-input" type="checkbox" id="filterPriUrgent" value="urgent">
                                                        <label class="form-check-label small" for="filterPriUrgent">Urgent</label>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label form-label-sm">Sender Roles</label>
                                                <select class="form-select form-select-sm" id="filterSenderRoles" multiple size="3">
                                                    <option value="0">Super Admin</option>
                                                    <option value="1">Administrator</option>
                                                    <option value="2">Dispatcher</option>
                                                    <option value="3">Operator</option>
                                                    <option value="4">Read-Only</option>
                                                    <option value="5">Field Unit</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="row g-2 mt-1">
                                            <div class="col-md-6">
                                                <label class="form-label form-label-sm">Keywords <span class="text-body-secondary">(comma-separated, any match)</span></label>
                                                <input type="text" class="form-control form-control-sm" id="filterKeywords" placeholder="fire, mutual aid, hazmat">
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label form-label-sm">Exclude Keywords <span class="text-body-secondary">(comma-separated)</span></label>
                                                <input type="text" class="form-control form-control-sm" id="filterExclude" placeholder="test, drill">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Phase 99v-4 (a beta tester/Eric beta 2026-06-30) — Recipients (collapsible) -->
                            <div class="mb-2">
                                <button class="btn btn-sm btn-outline-secondary w-100 text-start" type="button" data-bs-toggle="collapse" data-bs-target="#routeRecipientsCollapse">
                                    <i class="bi bi-people me-1"></i>Recipients <span class="text-body-secondary small">(optional — leave on "Channel broadcast" for default behaviour)</span>
                                </button>
                                <div class="collapse mt-2" id="routeRecipientsCollapse">
                                    <div class="card card-body p-2">
                                        <div class="form-check form-check-inline">
                                            <input class="form-check-input" type="radio" name="recipientMode" id="recipMode_broadcast" value="broadcast" checked>
                                            <label class="form-check-label small" for="recipMode_broadcast">Channel broadcast (everyone on the destination channel)</label>
                                        </div>
                                        <div class="form-check form-check-inline">
                                            <input class="form-check-input" type="radio" name="recipientMode" id="recipMode_predicate" value="predicate">
                                            <label class="form-check-label small" for="recipMode_predicate">Specific users (predicate)</label>
                                        </div>

                                        <div id="recipBuilder" class="mt-2" style="display:none;">
                                            <div class="row g-2">
                                                <div class="col-md-4">
                                                    <label class="form-label form-label-sm">Predicate</label>
                                                    <select class="form-select form-select-sm" id="recipPredicate">
                                                        <option value="">— pick a predicate —</option>
                                                        <option value="assigned_to_incident">Users assigned to incident</option>
                                                        <option value="responder_status_in">Users with responder in status</option>
                                                        <option value="member_of_team">Members of team</option>
                                                        <option value="user_id_in">Specific users (literal list)</option>
                                                        <option value="org_member">Members of organization</option>
                                                        <option value="rbac_can">Users with permission</option>
                                                    </select>
                                                </div>
                                                <div class="col-md-8">
                                                    <label class="form-label form-label-sm" id="recipParamLabel">Parameter</label>
                                                    <input type="text" class="form-control form-control-sm" id="recipParam" placeholder="(select a predicate first)">
                                                    <div class="form-text small" id="recipParamHint"></div>
                                                </div>
                                            </div>
                                            <!-- Phase 99v-4 follow-on (Eric beta 2026-06-30) — real
                                                 ticket selector for Preview + Send-test. Sample payload
                                                 used to be hardcoded {ticket_id:1, severity:1} which made
                                                 assigned_to_incident preview misleading. -->
                                            <div class="row g-2 mt-2">
                                                <div class="col-md-9">
                                                    <label class="form-label form-label-sm">Test against incident</label>
                                                    <select class="form-select form-select-sm" id="recipSampleTicket">
                                                        <option value="">— synthetic payload (no real ticket) —</option>
                                                    </select>
                                                    <div class="form-text small">For predicates that read <code>$payload.ticket_id</code> (e.g. <em>assigned_to_incident</em>), pick a real incident to preview the actual audience.</div>
                                                </div>
                                            </div>
                                            <div class="d-flex align-items-center gap-2 mt-2 flex-wrap">
                                                <button type="button" class="btn btn-sm btn-outline-info" id="recipPreviewBtn">
                                                    <i class="bi bi-eye me-1"></i>Preview recipients
                                                </button>
                                                <button type="button" class="btn btn-sm btn-warning" id="recipTestSendBtn"
                                                        title="Fire a real test message through this route — recipients will receive it (clearly marked [TEST])">
                                                    <i class="bi bi-send-check me-1"></i>Send test
                                                </button>
                                                <button type="button" class="btn btn-sm btn-link p-0 ms-auto" id="recipAdvancedToggle">
                                                    Advanced: edit JSON directly
                                                </button>
                                            </div>
                                            <div id="recipPreviewResult" class="small mt-2" style="display:none;"></div>

                                            <div id="recipAdvancedPane" class="mt-2" style="display:none;">
                                                <label class="form-label form-label-sm">Predicate JSON</label>
                                                <textarea class="form-control form-control-sm font-monospace" id="recipJsonRaw" rows="6" placeholder='{"predicate": "rbac_can", "params": {"permission_code": "screen.situation"}}'></textarea>
                                                <div class="form-text small">Use nested <code>{"type":"any_of","conditions":[...]}</code> for compositions. See docs/MESSAGE-ROUTING-GUIDE.md for shapes.</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Transforms (collapsible) -->
                            <div class="mb-2">
                                <button class="btn btn-sm btn-outline-secondary w-100 text-start" type="button" data-bs-toggle="collapse" data-bs-target="#routeTransformCollapse">
                                    <i class="bi bi-arrow-repeat me-1"></i>Transform <span class="text-body-secondary small">(optional — modify message before forwarding)</span>
                                </button>
                                <div class="collapse mt-2" id="routeTransformCollapse">
                                    <div class="card card-body p-2">
                                        <div class="row g-2">
                                            <div class="col-md-8">
                                                <label class="form-label form-label-sm">Prefix <span class="text-body-secondary">({source} = source channel name)</span></label>
                                                <input type="text" class="form-control form-control-sm" id="transformPrefix" placeholder="[From {source}] ">
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label form-label-sm">Override Priority</label>
                                                <select class="form-select form-select-sm" id="transformPriority">
                                                    <option value="">No Change</option>
                                                    <option value="normal">Normal</option>
                                                    <option value="high">High</option>
                                                    <option value="urgent">Urgent</option>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-sm btn-success"><i class="bi bi-check-lg me-1"></i>Save Route</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Test Message Modal -->
        <div class="modal fade" id="testRouteModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="bi bi-play-circle me-1"></i>Test Message Routing</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p class="small text-body-secondary">Send a simulated message to see which routes would match. No actual messages are sent.</p>
                        <div class="row g-2 mb-2">
                            <div class="col-md-6">
                                <label class="form-label form-label-sm">Source Channel</label>
                                <select class="form-select form-select-sm" id="testChannel"></select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label form-label-sm">Direction</label>
                                <select class="form-select form-select-sm" id="testDirection">
                                    <option value="outbound">Outbound</option>
                                    <option value="inbound">Inbound</option>
                                </select>
                            </div>
                        </div>
                        <div class="mb-2">
                            <label class="form-label form-label-sm">Message Body</label>
                            <textarea class="form-control form-control-sm" id="testBody" rows="2" placeholder="Type a test message..."></textarea>
                        </div>
                        <div class="row g-2 mb-2">
                            <div class="col-md-6">
                                <label class="form-label form-label-sm">Priority</label>
                                <select class="form-select form-select-sm" id="testPriority">
                                    <option value="normal">Normal</option>
                                    <option value="high">High</option>
                                    <option value="urgent">Urgent</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label form-label-sm">Severity</label>
                                <select class="form-select form-select-sm" id="testSeverity">
                                    <option value="0">0 (None)</option>
                                    <option value="1">1 (Low)</option>
                                    <option value="2">2 (Medium)</option>
                                    <option value="3">3 (High)</option>
                                </select>
                            </div>
                        </div>
                        <button type="button" class="btn btn-sm btn-primary" id="btnRunTest"><i class="bi bi-play-fill me-1"></i>Run Test</button>
                        <div id="testResults" class="mt-2" style="display:none">
                            <h6 class="small fw-bold">Results</h6>
                            <div id="testResultsBody"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Email Configuration ───────────────────────────────── -->
        <div class="config-panel" id="panel-email-config">
            <div class="config-panel-title">
                <i class="bi bi-envelope text-primary"></i> Email Configuration
            </div>
            <form id="emailConfigForm">
                <div class="settings-group">
                    <div class="settings-group-title">Mail Transport</div>
                    <div class="row g-2">
                        <div class="col-md-4">
                            <label class="form-label form-label-sm">Mode</label>
                            <select class="form-select form-select-sm" id="setEmailMode">
                                <option value="">Disabled</option>
                                <option value="sendmail">Local Sendmail</option>
                                <option value="smtp">SMTP Relay</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label form-label-sm">From Address</label>
                            <input type="email" class="form-control form-control-sm" id="setSmtpFrom" placeholder="dispatch@example.com">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label form-label-sm">From Name</label>
                            <input type="text" class="form-control form-control-sm" id="setSmtpFromName" placeholder="Tickets CAD">
                        </div>
                    </div>
                </div>
                <div class="settings-group" id="smtpRelayGroup" style="display:none">
                    <div class="settings-group-title">SMTP Server</div>
                    <div class="row g-2">
                        <div class="col-md-5">
                            <label class="form-label form-label-sm">SMTP Host</label>
                            <input type="text" class="form-control form-control-sm" id="setSmtpHost" placeholder="smtp.gmail.com">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label form-label-sm">Port</label>
                            <input type="number" class="form-control form-control-sm" id="setSmtpPort" placeholder="587">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label form-label-sm">Encryption</label>
                            <select class="form-select form-select-sm" id="setSmtpEncrypt">
                                <option value="tls">STARTTLS (587)</option>
                                <option value="ssl">SSL/TLS (465)</option>
                                <option value="none">None (25)</option>
                            </select>
                        </div>
                    </div>
                    <div class="row g-2 mt-1">
                        <div class="col-md-6">
                            <label class="form-label form-label-sm">Username</label>
                            <input type="text" class="form-control form-control-sm" id="setSmtpUser" placeholder="your-email@gmail.com">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label form-label-sm">Password</label>
                            <input type="password" class="form-control form-control-sm" id="setSmtpPass" data-secret="1" autocomplete="new-password" placeholder="•••• stored — leave blank to keep, type to replace">
                        </div>
                    </div>
                </div>
                <div class="mt-2">
                    <button type="submit" class="btn btn-sm btn-success"><i class="bi bi-check-lg me-1"></i>Save</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btnTestEmail"><i class="bi bi-send me-1"></i>Send Test Email</button>
                </div>
            </form>
        </div>

        <!-- ── Email Lists ───────────────────────────────────────── -->
        <div class="config-panel" id="panel-email-lists">
            <div class="config-panel-title">
                <i class="bi bi-people text-info"></i> Email Distribution Lists
            </div>
            <p class="text-body-secondary small mb-2">Create named groups of email recipients for notifications. Lists can include_once members, external contacts, or a mix.</p>

            <div class="alert alert-info py-2 small">
                <i class="bi bi-info-circle me-1"></i>
                <strong>How lists are used:</strong> on the Incident form and PAR-overdue events, dispatchers can target "to:&lt;list name&gt;" instead of typing addresses. The Notification Rules panel can also route specific events to a list — e.g. "send every new SHELTER incident to <em>red-cross-ops</em>".
                <details class="mt-2">
                    <summary class="fw-semibold">Recipient types supported</summary>
                    <ul class="mb-0 mt-2">
                        <li><strong>Member</strong> — references a row in <code>member</code> by id. Email is read from <code>member.email</code> at send time, so changing a member's email auto-updates every list they're on.</li>
                        <li><strong>Constituent</strong> — references the <code>constituents</code> address book.</li>
                        <li><strong>Inline address</strong> — a plain RFC 5322 string for external recipients not in your roster.</li>
                        <li><strong>Sub-list</strong> — another distribution list (one level of nesting; cycles are detected and rejected).</li>
                    </ul>
                </details>
            </div>

            <div class="d-flex gap-2 mb-2 align-items-center">
                <button type="button" class="btn btn-sm btn-success" id="btnNewEmailList"><i class="bi bi-plus-lg me-1"></i>New List</button>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="btnImportEmailList"><i class="bi bi-upload me-1"></i>Import CSV</button>
                <input type="text" class="form-control form-control-sm ms-auto" placeholder="Filter lists…" id="emailListFilter" style="max-width:240px;">
            </div>
            <div id="emailListsBody">
                <div class="text-body-secondary p-3 small">Loading lists…</div>
            </div>
        </div>

        <!-- ── SMS Configuration ─────────────────────────────────── -->
        <div class="config-panel" id="panel-sms-config">
            <div class="config-panel-title">
                <i class="bi bi-phone text-success"></i> SMS Configuration
            </div>
            <form id="smsConfigForm">
                <div class="settings-group">
                    <div class="settings-group-title">SMS Provider</div>
                    <div class="row g-2">
                        <div class="col-md-4">
                            <label class="form-label form-label-sm">Provider</label>
                            <select class="form-select form-select-sm" id="setSmsProvider">
                                <option value="">Disabled</option>
                                <option value="generic">Generic REST Endpoint</option>
                                <option value="twilio">Twilio</option>
                                <option value="bulkvs">BulkVS</option>
                                <option value="pushbullet">Pushbullet (via phone)</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label form-label-sm">From Number</label>
                            <input type="text" class="form-control form-control-sm" id="setSmsFrom" placeholder="+15551234567">
                        </div>
                    </div>
                </div>
                <!-- Twilio fields -->
                <div class="settings-group" id="smsTwilioGroup" style="display:none">
                    <div class="settings-group-title">Twilio</div>
                    <div class="row g-2">
                        <div class="col-md-6">
                            <label class="form-label form-label-sm">Account SID</label>
                            <input type="text" class="form-control form-control-sm" id="setSmsTwilioSid">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label form-label-sm">Auth Token</label>
                            <input type="password" class="form-control form-control-sm" id="setSmsTwilioToken" data-secret="1" autocomplete="new-password" placeholder="•••• stored — leave blank to keep, type to replace">
                        </div>
                    </div>
                </div>
                <!-- BulkVS fields -->
                <div class="settings-group" id="smsBulkvsGroup" style="display:none">
                    <div class="settings-group-title">BulkVS</div>
                    <div class="row g-2">
                        <div class="col-md-6">
                            <label class="form-label form-label-sm">API Key</label>
                            <input type="text" class="form-control form-control-sm" id="setSmsBulkvsKey" data-secret="1" placeholder="•••• stored — leave blank to keep, type to replace">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label form-label-sm">API Secret</label>
                            <input type="password" class="form-control form-control-sm" id="setSmsBulkvsSecret" data-secret="1" autocomplete="new-password" placeholder="•••• stored — leave blank to keep, type to replace">
                        </div>
                    </div>
                </div>
                <!-- Pushbullet fields -->
                <div class="settings-group" id="smsPushbulletGroup" style="display:none">
                    <div class="settings-group-title">Pushbullet</div>
                    <div class="row g-2">
                        <div class="col-md-8">
                            <label class="form-label form-label-sm">Access Token</label>
                            <input type="text" class="form-control form-control-sm" id="setSmsPushbulletToken" data-secret="1" placeholder="•••• stored — leave blank to keep, type to replace">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label form-label-sm">Device ID</label>
                            <input type="text" class="form-control form-control-sm" id="setSmsPushbulletDevice" placeholder="Auto-detect">
                        </div>
                    </div>
                </div>
                <!-- Generic REST fields -->
                <div class="settings-group" id="smsGenericGroup" style="display:none">
                    <div class="settings-group-title">Generic REST Endpoint</div>
                    <div class="row g-2">
                        <div class="col-md-3">
                            <label class="form-label form-label-sm">Method</label>
                            <select class="form-select form-select-sm" id="setSmsGenericMethod">
                                <option value="POST">POST</option>
                                <option value="GET">GET</option>
                            </select>
                        </div>
                        <div class="col-md-9">
                            <label class="form-label form-label-sm">URL</label>
                            <input type="text" class="form-control form-control-sm" id="setSmsGenericUrl" placeholder="https://api.example.com/sms/send">
                        </div>
                    </div>
                    <div class="row g-2 mt-1">
                        <div class="col-md-3">
                            <label class="form-label form-label-sm">Content Type</label>
                            <select class="form-select form-select-sm" id="setSmsGenericContentType">
                                <option value="form">Form (x-www-form-urlencoded)</option>
                                <option value="json">JSON</option>
                            </select>
                        </div>
                        <div class="col-md-9">
                            <label class="form-label form-label-sm">Auth Header</label>
                            <input type="text" class="form-control form-control-sm" id="setSmsGenericAuth" placeholder="Authorization: Bearer {api_key}">
                        </div>
                    </div>
                    <div class="row g-2 mt-1">
                        <div class="col-md-6">
                            <label class="form-label form-label-sm">API Key</label>
                            <input type="text" class="form-control form-control-sm" id="setSmsGenericApiKey" data-secret="1" placeholder="•••• stored — leave blank to keep, type to replace">
                        </div>
                    </div>
                    <div class="mt-1">
                        <label class="form-label form-label-sm">Body Template</label>
                        <textarea class="form-control form-control-sm" id="setSmsGenericTemplate" rows="3" placeholder='{"to": "{to}", "message": "{body}", "from": "{from}"}'></textarea>
                        <div class="small text-body-secondary mt-1">Variables: <code>{to}</code> <code>{body}</code> <code>{from}</code> <code>{subject}</code> <code>{api_key}</code></div>
                    </div>
                </div>
                <div class="mt-2">
                    <button type="submit" class="btn btn-sm btn-success"><i class="bi bi-check-lg me-1"></i>Save</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btnTestSms"><i class="bi bi-send me-1"></i>Send Test SMS</button>
                </div>
            </form>
        </div>

        <!-- ── Telegram ──────────────────────────────────────────── -->
        <div class="config-panel" id="panel-telegram">
            <div class="config-panel-title">
                <i class="bi bi-telegram text-primary"></i> Telegram Bot
            </div>

            <!-- Phase 41: setup help -->
            <div class="alert alert-info py-2 small mb-3">
                <strong><i class="bi bi-info-circle me-1"></i>How this works:</strong>
                TicketsCAD posts incident / PAR / system alerts to a Telegram chat as a bot. Set up the bot once with BotFather, add it to your team's group, then paste its token + the group's chat ID below.
                <details class="mt-2">
                    <summary class="fw-semibold">Step-by-step setup</summary>
                    <ol class="mb-1 mt-2">
                        <li>Open Telegram → search for <a href="https://t.me/BotFather" target="_blank">@BotFather</a> → send <code>/newbot</code>. Pick a name and a username ending in "bot" (e.g. <code>YourOrgCADbot</code>). BotFather replies with a token like <code>123456:ABC-DEF…</code> — paste it below.</li>
                        <li>Create a Telegram group (or use an existing one). Add your bot as a member — for posting to channel-style groups you'll need to give it Admin rights so it can post.</li>
                        <li>To find the <strong>chat ID</strong>, send any message in the group, then visit <code>https://api.telegram.org/bot&lt;TOKEN&gt;/getUpdates</code> in your browser (replacing <code>&lt;TOKEN&gt;</code>). Look for <code>"chat":{"id":-100…</code> — copy that number including the minus sign.</li>
                        <li>Click <strong>Save</strong>, then <strong>Send Test</strong> — you should see a confirmation message land in your group.</li>
                    </ol>
                </details>
                <div class="mt-2 small">
                    Docs: <a href="https://core.telegram.org/bots/tutorial" target="_blank">core.telegram.org/bots/tutorial</a> ·
                    <a href="https://core.telegram.org/bots/api" target="_blank">Bot API reference</a>
                </div>
            </div>

            <form id="telegramConfigForm">
                <div class="settings-group">
                    <div class="row g-2">
                        <div class="col-md-8">
                            <label class="form-label form-label-sm">Bot Token</label>
                            <div class="input-group input-group-sm">
                                <input type="password" class="form-control font-monospace" id="setTelegramToken" placeholder="123456:ABC-DEF1234ghIkl-zyx57W2v1u123ew11">
                                <button class="btn btn-outline-secondary secret-reveal" type="button" data-target="setTelegramToken" title="Reveal / hide"><i class="bi bi-eye"></i></button>
                                <button class="btn btn-outline-secondary secret-copy" type="button" data-target="setTelegramToken" title="Copy"><i class="bi bi-clipboard"></i></button>
                            </div>
                            <div class="form-text small">From @BotFather. Treat like a password — anyone with the token can post as your bot.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label form-label-sm">Chat ID</label>
                            <input type="text" class="form-control form-control-sm" id="setTelegramChat" placeholder="-100123456789">
                            <div class="form-text small">Group IDs start with <code>-100</code>. Personal/DM IDs are positive.</div>
                        </div>
                    </div>
                </div>
                <div class="mt-2">
                    <button type="submit" class="btn btn-sm btn-success"><i class="bi bi-check-lg me-1"></i>Save</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btnTestTelegram"><i class="bi bi-send me-1"></i>Send Test</button>
                </div>
            </form>
        </div>

        <!-- ── Slack ────────────────────────────────────────────── -->
        <div class="config-panel" id="panel-slack">
            <div class="config-panel-title">
                <i class="bi bi-slack text-info"></i> Slack Integration
            </div>
            <p class="text-body-secondary small mb-2">Connect to a Slack workspace for sending/receiving messages. Useful for NWS weather coordination and mutual aid communication.</p>
            <form id="slackConfigForm">
                <div class="settings-group">
                    <div class="settings-group-title">Connection Mode</div>
                    <div class="row g-2">
                        <div class="col-md-4">
                            <label class="form-label form-label-sm">Mode</label>
                            <select class="form-select form-select-sm" id="setSlackMode">
                                <option value="">Disabled</option>
                                <option value="webhook">Incoming Webhook (send only)</option>
                                <option value="api">Bot API (send + receive)</option>
                            </select>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label form-label-sm">Default Channel</label>
                            <input type="text" class="form-control form-control-sm" id="setSlackChannel" placeholder="#dispatch or C01234ABCDE">
                        </div>
                    </div>
                </div>
                <div class="settings-group" id="slackWebhookGroup" style="display:none">
                    <div class="settings-group-title">Webhook</div>
                    <div class="row g-2">
                        <div class="col-12">
                            <label class="form-label form-label-sm">Webhook URL</label>
                            <input type="url" class="form-control form-control-sm" id="setSlackWebhook" data-secret="1" autocomplete="new-password" placeholder="https://hooks.slack.com/services/T.../B.../xxxx">
                        </div>
                    </div>
                </div>
                <div class="settings-group" id="slackApiGroup" style="display:none">
                    <div class="settings-group-title">Bot API</div>
                    <div class="row g-2">
                        <div class="col-12">
                            <label class="form-label form-label-sm">Bot OAuth Token</label>
                            <input type="password" class="form-control form-control-sm" id="setSlackToken" data-secret="1" autocomplete="new-password" placeholder="xoxb-1234-5678-abcdef">
                        </div>
                    </div>
                </div>
                <div class="mt-2">
                    <button type="submit" class="btn btn-sm btn-success"><i class="bi bi-check-lg me-1"></i>Save</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btnTestSlack"><i class="bi bi-send me-1"></i>Send Test Message</button>
                </div>
            </form>
        </div>

        <!-- ── Radio Messaging ───────────────────────────────────── -->
        <div class="config-panel" id="panel-radio-messaging">
            <div class="config-panel-title">
                <i class="bi bi-broadcast-pin text-danger"></i> Radio Messaging (MotoTRBO / DMR)
            </div>
            <p class="text-body-secondary small mb-2">Configure DMR radio text messaging. Supports MotoTRBO MNIS and generic DMR gateways.</p>
            <form id="radioMsgConfigForm">
                <div class="settings-group">
                    <div class="settings-group-title">Gateway Connection</div>
                    <div class="row g-2">
                        <div class="col-md-4">
                            <label class="form-label form-label-sm">Protocol</label>
                            <select class="form-select form-select-sm" id="setRadioProtocol">
                                <option value="">Disabled</option>
                                <option value="mnis">MotoTRBO MNIS</option>
                                <option value="dmr_gw">DMR Gateway (UDP)</option>
                            </select>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label form-label-sm">Gateway Host</label>
                            <input type="text" class="form-control form-control-sm" id="setRadioHost" placeholder="192.168.1.50">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label form-label-sm">Port</label>
                            <input type="number" class="form-control form-control-sm" id="setRadioPort" value="4001">
                        </div>
                    </div>
                </div>
                <div class="settings-group">
                    <div class="settings-group-title">Capabilities</div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="checkbox" id="setRadioText" checked>
                        <label class="form-check-label small" for="setRadioText">Text Messages (2-way)</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="checkbox" id="setRadioLocation">
                        <label class="form-check-label small" for="setRadioLocation">GPS Location</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="checkbox" id="setRadioVoice">
                        <label class="form-check-label small" for="setRadioVoice">Voice (2-way)</label>
                    </div>
                </div>
                <div class="mt-2">
                    <button type="submit" class="btn btn-sm btn-success"><i class="bi bi-check-lg me-1"></i>Save</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btnTestRadio"><i class="bi bi-broadcast-pin me-1"></i>Test Connection</button>
                </div>
            </form>
        </div>

        <!-- Mesh (Meshtastic) legacy panel removed in Phase 36D (2026-06-13).
             Superseded by the Mesh Bridges Console at mesh-console.php.
             See specs/phase-36-settings-sidebar/final-layout.md. -->

        <!-- ── Webhooks / Events ─────────────────────────────────── -->
        <div class="config-panel" id="panel-webhooks">
            <div class="config-panel-title">
                <i class="bi bi-link-45deg text-info"></i> Webhooks / Event Subscriptions
            </div>
            <p class="text-body-secondary small mb-2">Configure outbound webhooks that fire when events occur in the system (new incident, unit status change, etc.).</p>

            <!-- Toolbar -->
            <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="text-body-secondary small" id="webhooksStatus">Loading...</span>
                <button type="button" class="btn btn-sm btn-primary" id="btnAddWebhook"><i class="bi bi-plus-lg me-1"></i>Add Webhook</button>
            </div>

            <!-- Webhook list table -->
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>URL</th>
                            <th>Events</th>
                            <th class="text-center">Active</th>
                            <th class="text-center">Last Delivery</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="webhooksTableBody">
                        <tr><td colspan="6" class="text-center text-body-secondary py-3">Loading...</td></tr>
                    </tbody>
                </table>
            </div>

            <!-- Edit/Add form (hidden by default) -->
            <div class="collapse mt-3" id="webhookEditPanel">
                <div class="card card-body">
                    <h6 class="mb-3" id="webhookFormTitle">Add Webhook</h6>
                    <form id="webhookForm" autocomplete="off">
                        <input type="hidden" id="webhookId" value="">
                        <div class="row g-2 mb-2">
                            <div class="col-md-6">
                                <label class="form-label form-label-sm">Name</label>
                                <input type="text" class="form-control form-control-sm" id="webhookName" required placeholder="My Integration">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label form-label-sm">URL</label>
                                <input type="url" class="form-control form-control-sm" id="webhookUrl" required placeholder="https://example.com/webhook">
                            </div>
                        </div>
                        <div class="row g-2 mb-2">
                            <div class="col-md-6">
                                <label class="form-label form-label-sm">Secret (HMAC-SHA256)</label>
                                <div class="input-group input-group-sm">
                                    <input type="text" class="form-control form-control-sm font-monospace" id="webhookSecret" placeholder="Auto-generated on create">
                                    <button type="button" class="btn btn-outline-secondary btn-sm" id="btnGenSecret" title="Generate random secret"><i class="bi bi-arrow-repeat"></i></button>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label form-label-sm">Max Retries</label>
                                <input type="number" class="form-control form-control-sm" id="webhookRetryMax" min="1" max="10" value="3">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label form-label-sm">Active</label>
                                <div class="form-check form-switch mt-1">
                                    <input class="form-check-input" type="checkbox" id="webhookActive" checked>
                                    <label class="form-check-label small" for="webhookActive">Enabled</label>
                                </div>
                            </div>
                        </div>
                        <div class="mb-2">
                            <label class="form-label form-label-sm">Event Types</label>
                            <div class="row g-1" id="webhookEventsGrid">
                                <div class="col-6 col-md-4"><div class="form-check form-check-sm"><input class="form-check-input wh-evt" type="checkbox" value="*" id="whEvtAll"><label class="form-check-label small" for="whEvtAll">All Events (*)</label></div></div>
                                <div class="col-6 col-md-4"><div class="form-check form-check-sm"><input class="form-check-input wh-evt" type="checkbox" value="incident:new" id="whEvt1"><label class="form-check-label small" for="whEvt1">incident:new</label></div></div>
                                <div class="col-6 col-md-4"><div class="form-check form-check-sm"><input class="form-check-input wh-evt" type="checkbox" value="incident:update" id="whEvt2"><label class="form-check-label small" for="whEvt2">incident:update</label></div></div>
                                <div class="col-6 col-md-4"><div class="form-check form-check-sm"><input class="form-check-input wh-evt" type="checkbox" value="incident:close" id="whEvt3"><label class="form-check-label small" for="whEvt3">incident:close</label></div></div>
                                <div class="col-6 col-md-4"><div class="form-check form-check-sm"><input class="form-check-input wh-evt" type="checkbox" value="responder:status" id="whEvt4"><label class="form-check-label small" for="whEvt4">responder:status</label></div></div>
                                <div class="col-6 col-md-4"><div class="form-check form-check-sm"><input class="form-check-input wh-evt" type="checkbox" value="responder:assign" id="whEvt5"><label class="form-check-label small" for="whEvt5">responder:assign</label></div></div>
                                <div class="col-6 col-md-4"><div class="form-check form-check-sm"><input class="form-check-input wh-evt" type="checkbox" value="responder:unassign" id="whEvt6"><label class="form-check-label small" for="whEvt6">responder:unassign</label></div></div>
                                <div class="col-6 col-md-4"><div class="form-check form-check-sm"><input class="form-check-input wh-evt" type="checkbox" value="action:note" id="whEvt7"><label class="form-check-label small" for="whEvt7">action:note</label></div></div>
                                <div class="col-6 col-md-4"><div class="form-check form-check-sm"><input class="form-check-input wh-evt" type="checkbox" value="system:refresh" id="whEvt8"><label class="form-check-label small" for="whEvt8">system:refresh</label></div></div>
                            </div>
                        </div>
                        <div class="d-flex gap-2 mt-3">
                            <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-check-lg me-1"></i>Save</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="btnCancelWebhook">Cancel</button>
                            <button type="button" class="btn btn-sm btn-outline-info ms-auto" id="btnTestWebhook"><i class="bi bi-send me-1"></i>Test</button>
                            <button type="button" class="btn btn-sm btn-outline-danger d-none" id="btnDeleteWebhook"><i class="bi bi-trash me-1"></i>Delete</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Recent deliveries for selected webhook -->
            <div class="collapse mt-3" id="webhookDeliveriesPanel">
                <h6 class="mb-2"><i class="bi bi-clock-history me-1"></i>Recent Deliveries</h6>
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Time</th>
                                <th>Event</th>
                                <th class="text-center">Status</th>
                                <th class="text-center">HTTP</th>
                                <th class="text-end">Duration</th>
                                <th>Error</th>
                            </tr>
                        </thead>
                        <tbody id="webhookDeliveriesBody">
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Phase 94 Stage 5.4: Recent Deliveries widget. Reads from
                 webhook_subscriptions (the new table) — gives admins a
                 health view that survives the legacy webhooks table being
                 dropped in Stage 6. Auto-refreshes every 30s. -->
            <hr class="my-3">
            <h6 class="text-body-secondary"><i class="bi bi-broadcast me-1"></i>Recent Deliveries (live)</h6>
            <div id="webhookDeliveriesWidget" class="mt-2">
                <div class="text-body-secondary small">Loading delivery activity...</div>
            </div>
            <script src="assets/js/widgets/webhook-deliveries-widget.js?v=<?php echo defined('NEWUI_VERSION') ? e(NEWUI_VERSION) : '4'; ?>"></script>
            <script>
                // Mount when the Webhooks tab is activated. config.js's
                // onPanelActivated dispatcher emits a 'panel:activated'
                // CustomEvent we can hook, OR we can just mount on first
                // visibility (cheap defensive fallback).
                (function () {
                    var mounted = false;
                    function maybeMount() {
                        if (mounted) return;
                        var panel = document.getElementById('panel-webhooks');
                        if (!panel || panel.offsetParent === null) return; // not visible yet
                        if (typeof WebhookDeliveriesWidget !== 'undefined') {
                            WebhookDeliveriesWidget.mount('webhookDeliveriesWidget');
                            mounted = true;
                        }
                    }
                    // Try on DOM ready (in case the user lands directly on this panel)
                    if (document.readyState !== 'loading') {
                        setTimeout(maybeMount, 100);
                    } else {
                        document.addEventListener('DOMContentLoaded', function () {
                            setTimeout(maybeMount, 100);
                        });
                    }
                    // Also try on visibility change (when the user clicks the tab)
                    document.addEventListener('click', function (ev) {
                        var t = ev.target;
                        // Sidebar link to this panel
                        while (t && t !== document) {
                            if (t.getAttribute && t.getAttribute('data-tab') === 'webhooks') {
                                setTimeout(maybeMount, 200);
                                return;
                            }
                            t = t.parentNode;
                        }
                    });
                })();
            </script>
        </div>

        <!-- ── External API Tokens (Phase 94 Stage 6) ────────────── -->
        <div class="config-panel" id="panel-external-api-tokens">
            <div class="config-panel-title">
                <i class="bi bi-key text-warning"></i> External API Tokens
            </div>
            <p class="text-body-secondary small mb-2">
                Bearer tokens that authorize third-party systems (CAD platforms, custom dashboards,
                mobile apps, IoT sensors) to call the External API at <code>/api/external/v1/*</code>.
                Each token is bound to a real user — the user's RBAC role determines what the token can
                actually do; the token's <em>scope</em> further LIMITS that surface (it never grants
                additional capability beyond what the user already has).
                See <a href="documentation/?doc=EXTERNAL-API.md">EXTERNAL-API.md</a> for the full
                integrator guide.
            </p>

            <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="text-body-secondary small" id="extApiTokensStatus">Loading...</span>
                <button type="button" class="btn btn-sm btn-primary" id="btnAddExtApiToken"><i class="bi bi-plus-lg me-1"></i>Mint New Token</button>
            </div>

            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Bound User</th>
                            <th>Scopes</th>
                            <th class="text-center">Last Used</th>
                            <th class="text-center">Status</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="extApiTokensTableBody">
                        <tr><td colspan="6" class="text-center text-body-secondary py-3">Loading...</td></tr>
                    </tbody>
                </table>
            </div>

            <!-- Mint form (hidden by default; revealed on Add) -->
            <div class="collapse mt-3" id="extApiTokenMintPanel">
                <div class="card card-body">
                    <h6 class="mb-3">Mint New External API Token</h6>
                    <form id="extApiTokenMintForm" autocomplete="off">
                        <div class="row g-2 mb-2">
                            <div class="col-md-6">
                                <label class="form-label form-label-sm">Name</label>
                                <input type="text" class="form-control form-control-sm" id="extApiTokenName" required
                                       placeholder="e.g. Acme Agency iOS v1.4">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label form-label-sm">Bound user</label>
                                <select class="form-select form-select-sm" id="extApiTokenUserId" required>
                                    <option value="">— Select a user —</option>
                                </select>
                                <div class="form-text small">RBAC checks against this user. Token can do whatever the user's role permits; scopes LIMIT but don't grant.</div>
                            </div>
                        </div>
                        <div class="row g-2 mb-2">
                            <div class="col-md-12">
                                <label class="form-label form-label-sm">Scopes (check all that apply)</label>
                                <div class="row g-1 small" id="extApiTokenScopesCheckboxes">
                                    <!-- populated by JS — these are the canonical scope codes -->
                                </div>
                            </div>
                        </div>
                        <div class="row g-2 mb-2">
                            <div class="col-md-6">
                                <label class="form-label form-label-sm">Description (optional)</label>
                                <input type="text" class="form-control form-control-sm" id="extApiTokenDescription"
                                       placeholder="Operator notes">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label form-label-sm">Rate limit / hour</label>
                                <input type="number" class="form-control form-control-sm" id="extApiTokenRateLimit"
                                       placeholder="1000" min="1" max="100000">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label form-label-sm">Expires at (optional)</label>
                                <input type="datetime-local" class="form-control form-control-sm" id="extApiTokenExpiresAt">
                            </div>
                        </div>
                        <div class="row g-2 mb-2">
                            <div class="col-md-12">
                                <label class="form-label form-label-sm">IP allowlist (optional, comma-separated CIDR)</label>
                                <input type="text" class="form-control form-control-sm" id="extApiTokenIpAllowlist"
                                       placeholder="e.g. 10.0.0.0/8, 192.168.1.0/24">
                            </div>
                        </div>
                        <div class="d-flex justify-content-end gap-2 mt-2">
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="btnCancelExtApiTokenMint">Cancel</button>
                            <button type="submit" class="btn btn-sm btn-success"><i class="bi bi-check-lg me-1"></i>Mint</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Raw-token reveal modal (CRITICAL: shown ONCE, never again) -->
            <div class="modal fade" id="extApiTokenRevealModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
                <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                        <div class="modal-header bg-warning bg-opacity-25">
                            <h5 class="modal-title"><i class="bi bi-exclamation-triangle me-1"></i>Token Minted — Copy Now</h5>
                        </div>
                        <div class="modal-body">
                            <p>
                                <strong>This raw token will never be shown again.</strong> Copy it now and
                                store it securely (a password manager or your integrator's secrets store).
                                After you close this dialog, only the <em>prefix</em> remains visible in
                                the admin UI for identification.
                            </p>
                            <div class="mb-2">
                                <label class="form-label form-label-sm fw-semibold">Raw token</label>
                                <div class="input-group input-group-sm">
                                    <input type="text" class="form-control font-monospace" id="extApiTokenRawValue" readonly>
                                    <button type="button" class="btn btn-outline-secondary" id="btnCopyExtApiToken"><i class="bi bi-clipboard"></i> Copy</button>
                                </div>
                            </div>
                            <details class="mt-2">
                                <summary class="small text-body-secondary">Quick-test curl command</summary>
                                <pre class="small p-2 bg-body-tertiary border rounded mt-1" id="extApiTokenCurlExample"></pre>
                            </details>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-warning" data-bs-dismiss="modal">I've copied it — close</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Signal Codes (issue #31) ──────────────────────────── -->
        <!-- Populates the Signal dropdown on the new-incident form.
             Distinct from the "Field Help Text" panel above (which
             edits the `hints` table for form tooltips) and from the
             "Standard Messages" panel below (which edits the
             `std_msgs` table for boilerplate broadcasts). This one
             writes to the `signals` table. -->
        <div class="config-panel" id="panel-signal-codes">
            <div class="config-panel-title">
                <i class="bi bi-hash text-primary"></i> Signal Codes
            </div>
            <p class="text-body-secondary small mb-3">
                Short codes shown in the Signal dropdown on the new-incident form (e.g. <code>10-4</code>, <code>Priority 1</code>, <code>MVA</code>). Populate this list with the codes your org actually uses.
            </p>
            <div class="card border-0 bg-body-tertiary mb-3">
                <div class="card-body py-2">
                    <div class="row g-2 align-items-end">
                        <div class="col-md-2">
                            <label class="form-label form-label-sm mb-0">Code</label>
                            <input type="text" class="form-control form-control-sm" id="sigCode" maxlength="16" placeholder="e.g. 10-4">
                        </div>
                        <div class="col-md-5">
                            <label class="form-label form-label-sm mb-0">Description</label>
                            <input type="text" class="form-control form-control-sm" id="sigDescription" maxlength="255" placeholder="What the code means">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label form-label-sm mb-0">Sort</label>
                            <input type="number" class="form-control form-control-sm" id="sigSort" value="0" min="0">
                        </div>
                        <div class="col-md-1">
                            <div class="form-check form-check-sm mt-3">
                                <input class="form-check-input" type="checkbox" id="sigHide">
                                <label class="form-check-label small" for="sigHide">Hide</label>
                            </div>
                        </div>
                        <div class="col-md-auto">
                            <button type="button" class="btn btn-sm btn-primary" id="btnAddSigCode">
                                <i class="bi bi-plus-lg me-1"></i>Add
                            </button>
                        </div>
                    </div>
                    <input type="hidden" id="sigEditId" value="0">
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th class="ps-3" style="width:120px;">Code</th>
                            <th>Description</th>
                            <th class="text-center" style="width:60px;">Sort</th>
                            <th class="text-center" style="width:60px;">Hidden</th>
                            <th class="text-center" style="width:90px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="sigCodesBody">
                        <tr><td colspan="5" class="text-center text-body-secondary py-3">Loading...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ── Standard Messages ─────────────────────────────────── -->
        <div class="config-panel" id="panel-std-messages">
            <div class="config-panel-title">
                <i class="bi bi-chat-square-text text-primary"></i> Standard Messages / Signals
            </div>
            <p class="text-body-secondary small mb-2">Define canned messages (signals/codes) that dispatchers can send with one click.</p>
            <div id="stdMessagesContent">
                <div class="text-center py-3"><div class="spinner-border spinner-border-sm"></div> Loading...</div>
            </div>
        </div>

        <!-- ── Chat Settings ─────────────────────────────────────── -->
        <div class="config-panel" id="panel-chat-settings">
            <div class="config-panel-title">
                <i class="bi bi-chat-dots text-success"></i> Chat Settings
            </div>
            <p class="text-body-secondary small mb-2">Real-time text chat between logged-in TicketsCAD users. Backed by Server-Sent Events (no external dependencies; works on the same TLS port as the rest of the app).</p>

            <form id="chatSettingsForm">
                <div class="settings-group">
                    <div class="settings-group-title">Retention &amp; Persistence</div>
                    <div class="row g-2">
                        <div class="col-md-3">
                            <label class="form-label form-label-sm">Message retention (days)</label>
                            <input type="number" class="form-control form-control-sm" data-key="chat_retention_days" min="1" max="3650" placeholder="365">
                            <div class="form-text small">Messages older than this are purged by the nightly cleanup job. 0 disables retention (forever).</div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label form-label-sm">Max message length</label>
                            <input type="number" class="form-control form-control-sm" data-key="chat_max_chars" min="32" max="8000" placeholder="2000">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label form-label-sm">Idle DM clear-on-logout</label>
                            <select class="form-select form-select-sm" data-key="chat_dm_clear_logout">
                                <option value="off">Off — keep history</option>
                                <option value="user">User chooses at logout</option>
                                <option value="force">Force-clear on logout</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="settings-group">
                    <div class="settings-group-title">Channel Structure</div>
                    <div class="row g-2">
                        <div class="col-md-4">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="setChatAllRoom" data-key="chat_all_room_enabled">
                                <label class="form-check-label small" for="setChatAllRoom">Global "All" room (everyone)</label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="setChatRoleRooms" data-key="chat_role_rooms_enabled">
                                <label class="form-check-label small" for="setChatRoleRooms">Per-role rooms (Dispatcher, Field Unit, etc.)</label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="setChatIncRooms" data-key="chat_incident_rooms_enabled">
                                <label class="form-check-label small" for="setChatIncRooms">Auto-create room for each open incident</label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="setChatDM" data-key="chat_dm_enabled">
                                <label class="form-check-label small" for="setChatDM">Allow direct messages between users</label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="setChatTyping" data-key="chat_typing_indicators">
                                <label class="form-check-label small" for="setChatTyping">Show typing indicators</label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="setChatReadReceipts" data-key="chat_read_receipts">
                                <label class="form-check-label small" for="setChatReadReceipts">Read receipts</label>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="settings-group">
                    <div class="settings-group-title">Cross-platform Bridges</div>
                    <p class="form-text small mb-2">Chat messages can fan out to external networks. Configure each in its own panel; this section only toggles the bridge on/off.</p>
                    <div class="row g-2">
                        <div class="col-md-3">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" data-key="chat_bridge_telegram">
                                <label class="form-check-label small">Bridge → Telegram</label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" data-key="chat_bridge_slack">
                                <label class="form-check-label small">Bridge → Slack</label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" data-key="chat_bridge_email">
                                <label class="form-check-label small">Bridge → Email (digest)</label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" data-key="chat_bridge_mesh">
                                <label class="form-check-label small">Bridge → Mesh radio</label>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mt-2">
                    <button type="submit" class="btn btn-sm btn-success"><i class="bi bi-check-lg me-1"></i>Save Chat Settings</button>
                </div>
            </form>
        </div>

        <!-- ── Location Providers ────────────────────────────────── -->
        <div class="config-panel" id="panel-tracking-providers">
            <div class="config-panel-title">
                <i class="bi bi-geo-alt text-danger"></i> Location Providers
            </div>
            <p class="text-body-secondary small mb-2">
                Configure which location tracking systems your organization uses. Lower priority number = higher priority.
                When multiple providers report positions for the same unit, the system uses the <strong>highest-priority fresh</strong> report.
                If the highest-priority data is stale (older than the Max Age threshold), it falls through to the next provider.
            </p>

            <!-- 2026-06-11 UX fix: an explicit hint about the per-row
                 Save buttons. Each row is independently saved — there is
                 no auto-save and no global Save button. Admin must click
                 the Save button on each row whose values they changed. -->
            <div class="alert alert-secondary small py-2 px-3 mb-2 d-none" id="locationProvidersHint">
                <i class="bi bi-info-circle me-1"></i>
                <strong>Each row saves independently.</strong>
                After changing the On / Priority / Max Age values on a row, click that row's
                <span class="badge bg-success"><i class="bi bi-check-lg me-1"></i>Save</span>
                button to persist the change.
            </div>

            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0" id="locationProvidersTable">
                    <thead>
                        <tr>
                            <th style="width:40px">On</th>
                            <th>Provider</th>
                            <th style="width:80px">Priority</th>
                            <th style="width:120px">Max Age (stale after)</th>
                            <th style="width:100px" class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody id="locationProvidersBody">
                        <tr><td colspan="5" class="text-center text-body-secondary py-3"><div class="spinner-border spinner-border-sm me-1"></div>Loading...</td></tr>
                    </tbody>
                </table>
            </div>

            <div class="alert alert-info small mt-2 mb-0">
                <i class="bi bi-info-circle me-1"></i>
                <strong>Staleness resolution:</strong> Each provider has a "Max Age" threshold. If the latest position report from a provider is older than this threshold, it's considered stale.
                The system then falls through to the next highest-priority provider that has fresh data. If NO fresh data exists, the most recent stale report is used.
            </div>
        </div>

        <!-- ── Provider Settings ─────────────────────────────────── -->
        <div class="config-panel" id="panel-provider-settings">
            <div class="config-panel-title">
                <i class="bi bi-sliders text-secondary"></i> Provider Settings
            </div>
            <p class="text-body-secondary small mb-2">Per-provider connection configuration. Select a provider to view and edit its settings.</p>

            <div class="mb-2">
                <select class="form-select form-select-sm" id="providerSettingsSelect" style="max-width:300px">
                    <option value="">Select a provider...</option>
                </select>
            </div>
            <div id="providerSettingsForm" style="display:none">
                <div class="border rounded p-2 mb-2">
                    <h6 class="mb-2" id="providerSettingsName">--</h6>
                    <div id="providerSettingsFields"></div>
                    <button class="btn btn-sm btn-success mt-2" id="btnSaveProviderSettings">
                        <i class="bi bi-check-lg me-1"></i>Save Settings
                    </button>
                </div>
            </div>

            <div class="alert alert-info small mt-2 mb-0">
                <i class="bi bi-info-circle me-1"></i>Each location provider has its own connection settings.
                APRS requires an aprs.fi API key (see the APRS panel).
                Meshtastic requires a gateway node IP.
                OwnTracks uses HTTP callbacks to this server.
                Internal GPS uses the browser's Geolocation API.
            </div>
        </div>

        <!-- ── DVSwitch DMR proxy (Phase 73k) ─────────────────────── -->
        <div class="config-panel" id="panel-dvswitch-dmr">
            <div class="config-panel-title">
                <i class="bi bi-mic-fill text-danger"></i> DMR (DVSwitch)
            </div>
            <p class="text-body-secondary small mb-2">
                Bridge BrandMeister or other DMR talkgroups into the message broker.
                Each row links one talkgroup to a TicketsCAD chat channel via the
                DVSwitch USRP socket on a dedicated bridge VM. See
                <code>specs/dvswitch-proxy-2026-06/spec.md</code> for the architecture.
                <br>
                <strong>License note:</strong> DVSwitch + md380-emu are amateur-radio-only.
                Suitable for CERT / volunteer fire / ARES use; not for commercial dispatch.
            </p>

            <div class="d-flex gap-2 mb-2">
                <button type="button" class="btn btn-sm btn-success" id="dvsBtnNew">
                    <i class="bi bi-plus-lg me-1"></i>New Channel
                </button>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="dvsBtnReload">
                    <i class="bi bi-arrow-clockwise me-1"></i>Reload
                </button>
            </div>

            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle" id="dvsTable">
                    <thead>
                        <tr>
                            <th>Label</th>
                            <th>TG</th>
                            <th>Network</th>
                            <th>Mode</th>
                            <th>Chat</th>
                            <th>Bridge</th>
                            <th>USRP</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="dvsTableBody">
                        <tr><td colspan="9" class="text-center text-body-secondary small">Loading...</td></tr>
                    </tbody>
                </table>
            </div>

            <details class="small text-body-secondary mt-2">
                <summary>Bring up a new bridge host</summary>
                <ol class="mt-2 mb-0">
                    <li>Provision a Debian 13 VM per the playbook
                        (current: <code>dvswitch-01</code> at 10.0.0.10).</li>
                    <li>Install DVSwitch packages from the
                        <code>hamradio</code> component:
<pre class="mt-1 mb-1 small bg-body-tertiary p-2"
>echo "deb [signed-by=/etc/apt/keyrings/dvswitch-keyring.gpg] http://dvswitch.org/DVSwitch_Repository bookworm hamradio" \
  | sudo tee /etc/apt/sources.list.d/dvswitch.list
sudo apt-get update && sudo apt-get install -y analog-bridge mmdvm-bridge md380-emu</pre></li>
                    <li>Copy <code>services/dvswitch/bridge.py</code> to
                        <code>/opt/ticketscad/services/dvswitch/</code> on the host.</li>
                    <li>Create the channel below — the create response
                        gives you the bearer token (shown ONCE). Paste it into
                        <code>/etc/ticketscad/dvswitch-&lt;instance&gt;.env</code>.</li>
                    <li>Symlink <code>services/dvswitch/ticketscad-dvswitch@.service</code>
                        into <code>/etc/systemd/system/</code>, then
                        <code>systemctl enable --now ticketscad-dvswitch@&lt;instance&gt;</code>.</li>
                    <li>Click Test Health on the row.</li>
                </ol>
            </details>
        </div>

        <style>
            /* Phase 99e v4 (2026-06-28) — drag-and-drop reorder + inline sort edit */
            #panel-talkgroups .tg-drag-handle {
                cursor: grab; color: var(--bs-body-secondary); text-align: center;
                user-select: none;
            }
            #panel-talkgroups .tg-drag-handle:active { cursor: grabbing; }
            #panel-talkgroups tr.tg-dragging { opacity: 0.4; }
            #panel-talkgroups tr.tg-drop-target { box-shadow: inset 0 2px 0 0 var(--bs-primary); }
            #panel-talkgroups .tg-sort-cell {
                cursor: pointer; font-variant-numeric: tabular-nums;
            }
            #panel-talkgroups .tg-sort-cell:hover { background: var(--bs-tertiary-bg); }
            #panel-talkgroups .tg-sort-input {
                width: 60px; padding: 0 4px; margin: -2px 0;
                border: 1px solid var(--bs-primary); border-radius: 3px;
                font-variant-numeric: tabular-nums;
            }
            /* Disable drag-handle visual when not sorting by sort_order
               (drag would be confusing if the list is sorted by name etc) */
            #panel-talkgroups tbody:not(.tg-can-drag) .tg-drag-handle {
                cursor: not-allowed; opacity: 0.3;
            }
        </style>

        <!-- ── DMR Talkgroups (Phase 99e v2, 2026-06-28) ──────────────
             Per-install registry of DMR talkgroups. Feeds:
               - Compose form's DMR channel 'Send to → Talkgroup' picker (future)
               - DMR radio widget's talkgroup selector (future)
             Pre-seeded with US nationwide EmComm + TAC + all statewide +
             regional + FEMA Region V. Each row can be enabled/disabled +
             tagged as group-call (broadcast) or private-call (point-to-
             point, only meaningful for calls to a specific DMR ID). -->
        <div class="config-panel" id="panel-talkgroups">
            <div class="config-panel-title">
                <i class="bi bi-broadcast-pin text-info"></i> DMR Talkgroups
            </div>
            <p class="text-body-secondary small mb-2">
                Per-install registry of DMR talkgroups. Pre-seeded with
                ~79 entries (all US statewide + nationwide EmComm + FEMA Region V +
                TAC channels + regional bridges). Toggle <strong>Enabled</strong>
                to control which talkgroups appear in the Compose form's
                send-to picker + the DMR radio widget. <strong>Call type</strong>
                defaults to group-call (broadcast); private-call is for
                point-to-point sends to a specific DMR ID.
            </p>

            <div class="d-flex gap-2 mb-3 flex-wrap">
                <button type="button" class="btn btn-sm btn-success" id="btnAddTalkgroup">
                    <i class="bi bi-plus-lg me-1"></i>Add talkgroup
                </button>
                <a class="btn btn-sm btn-outline-secondary" href="api/talkgroups.php?format=csv"
                   download="talkgroups.csv" title="Export current rows as CSV">
                    <i class="bi bi-download me-1"></i>Export CSV
                </a>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="btnImportTalkgroupsCSV"
                        data-bs-toggle="modal" data-bs-target="#tgImportModal">
                    <i class="bi bi-upload me-1"></i>Import CSV
                </button>
                <input type="search" class="form-control form-control-sm ms-auto" id="tgFilter"
                       style="max-width:240px;" placeholder="Filter…">
            </div>

            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead>
                        <tr>
                            <th style="width:24px;" title="Drag to reorder (only when sorting by Sort)"></th>
                            <th style="width:100px;cursor:pointer;user-select:none;" class="tg-sortable" data-sort-key="dmr_id">DMR ID <i class="bi bi-arrow-down-up text-body-tertiary small"></i></th>
                            <th style="cursor:pointer;user-select:none;" class="tg-sortable" data-sort-key="name">Name <i class="bi bi-arrow-down-up text-body-tertiary small"></i></th>
                            <th style="cursor:pointer;user-select:none;" class="tg-sortable" data-sort-key="description">Description <i class="bi bi-arrow-down-up text-body-tertiary small"></i></th>
                            <th style="width:90px;cursor:pointer;user-select:none;" class="tg-sortable" data-sort-key="call_type">Call type <i class="bi bi-arrow-down-up text-body-tertiary small"></i></th>
                            <th style="width:80px;cursor:pointer;user-select:none;" class="tg-sortable" data-sort-key="sort_order">Sort <i class="bi bi-arrow-down-up text-body-tertiary small"></i></th>
                            <th style="width:60px;text-align:center;cursor:pointer;user-select:none;" class="tg-sortable" data-sort-key="enabled">Enabled <i class="bi bi-arrow-down-up text-body-tertiary small"></i></th>
                            <th style="width:60px;"></th>
                        </tr>
                    </thead>
                    <tbody id="tgTableBody">
                        <tr><td colspan="8" class="text-center text-body-secondary py-3">Loading…</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Talkgroup add/edit modal -->
        <div class="modal fade" id="tgEditModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h6 class="modal-title" id="tgEditModalTitle">Add talkgroup</h6>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <form id="tgEditForm">
                        <input type="hidden" id="tgEditId" value="">
                        <div class="modal-body">
                            <div class="row g-2">
                                <div class="col-md-4">
                                    <label class="form-label form-label-sm">DMR ID <span class="text-danger">*</span></label>
                                    <input type="number" class="form-control form-control-sm" id="tgEditDmrId" min="1" max="16777215" required>
                                    <div class="form-text">1–16777215 (24-bit)</div>
                                </div>
                                <div class="col-md-8">
                                    <label class="form-label form-label-sm">Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control form-control-sm" id="tgEditName" maxlength="64" required>
                                </div>
                                <div class="col-12">
                                    <label class="form-label form-label-sm">Description</label>
                                    <input type="text" class="form-control form-control-sm" id="tgEditDesc" maxlength="255">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label form-label-sm">Call type</label>
                                    <select class="form-select form-select-sm" id="tgEditCallType">
                                        <option value="group">Group (broadcast)</option>
                                        <option value="private">Private (point-to-point)</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label form-label-sm">Sort order</label>
                                    <input type="number" class="form-control form-control-sm" id="tgEditSort" value="100">
                                </div>
                                <div class="col-md-4 d-flex align-items-end">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="tgEditEnabled" checked>
                                        <label class="form-check-label small" for="tgEditEnabled">Enabled</label>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-sm btn-outline-danger me-auto d-none" id="tgEditDelete">
                                <i class="bi bi-trash me-1"></i>Delete
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-sm btn-primary">
                                <i class="bi bi-check-lg me-1"></i>Save
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Import CSV modal -->
        <div class="modal fade" id="tgImportModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h6 class="modal-title">Import talkgroups from CSV</h6>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body small">
                        <p>
                            CSV format — header row required, with at minimum
                            <code>dmr_id</code> and <code>name</code> columns. Optional:
                            <code>description</code>, <code>call_type</code>
                            (<code>group</code>/<code>private</code>), <code>sort_order</code>,
                            <code>enabled</code> (1/0).
                        </p>
                        <textarea class="form-control form-control-sm font-monospace" id="tgImportCsv"
                                  rows="10" placeholder="dmr_id,name,description,call_type,sort_order,enabled&#10;3127,MN State,Minnesota Statewide,group,100,1"></textarea>
                        <div class="form-check mt-2">
                            <input class="form-check-input" type="checkbox" id="tgImportReplace">
                            <label class="form-check-label" for="tgImportReplace">
                                Replace existing rows when DMR ID matches (otherwise skip)
                            </label>
                        </div>
                        <div id="tgImportResult" class="mt-2 small"></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-sm btn-primary" id="btnTgImportRun">
                            <i class="bi bi-upload me-1"></i>Import
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Create / edit modal -->
        <div class="modal fade" id="dvsChannelModal" tabindex="-1">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="dvsModalTitle">New DMR Channel</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <form id="dvsForm" autocomplete="off">
                        <div class="modal-body">
                            <input type="hidden" id="dvsId" value="">
                            <div class="row g-2">
                                <div class="col-md-4">
                                    <label class="form-label form-label-sm">Label *</label>
                                    <input type="text" class="form-control form-control-sm"
                                           id="dvsLabel" required pattern="[A-Za-z0-9_\-]+"
                                           placeholder="e.g. tg91-worldwide">
                                    <small class="text-body-secondary">Used as the systemd instance name. A-Z, 0-9, _-</small>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label form-label-sm">Talkgroup *</label>
                                    <input type="text" class="form-control form-control-sm"
                                           id="dvsTg" required placeholder="e.g. 9990 (parrot)">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label form-label-sm">Network</label>
                                    <select class="form-select form-select-sm" id="dvsNetwork">
                                        <option>BrandMeister</option>
                                        <option>TGIF</option>
                                        <option>Phoenix</option>
                                        <option>HBLink</option>
                                        <option>FreeDMR</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label form-label-sm">Link Mode</label>
                                    <select class="form-select form-select-sm" id="dvsLinkMode">
                                        <option value="rx_only">RX only</option>
                                        <option value="tx_only">TX only</option>
                                        <option value="bidirectional">Bidirectional</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label form-label-sm">Bridge host *</label>
                                    <input type="text" class="form-control form-control-sm"
                                           id="dvsBridgeHost" required
                                           placeholder="10.0.0.10"
                                           value="10.0.0.10">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label form-label-sm">Bridge HTTP port</label>
                                    <input type="number" class="form-control form-control-sm"
                                           id="dvsBridgePort" value="18091" min="1024" max="65535">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label form-label-sm">Chat channel</label>
                                    <input type="text" class="form-control form-control-sm"
                                           id="dvsChatChannel" value="dispatch">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label form-label-sm">USRP listen port</label>
                                    <input type="number" class="form-control form-control-sm"
                                           id="dvsUsrpListenPort" placeholder="auto" min="1024" max="65535">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label form-label-sm">USRP send port</label>
                                    <input type="number" class="form-control form-control-sm"
                                           id="dvsUsrpSendPort" placeholder="auto" min="1024" max="65535">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label form-label-sm">STT engine</label>
                                    <select class="form-select form-select-sm" id="dvsSttEngine">
                                        <option value="">(none)</option>
                                        <option value="vosk">Vosk (streaming)</option>
                                        <option value="whisper">faster-whisper</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label form-label-sm">TTS engine</label>
                                    <select class="form-select form-select-sm" id="dvsTtsEngine">
                                        <option value="">(none)</option>
                                        <option value="piper">Piper</option>
                                        <option value="espeak">eSpeak-NG (fallback)</option>
                                    </select>
                                </div>
                                <div class="col-md-12">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="dvsRouteToBroker" checked>
                                        <label class="form-check-label" for="dvsRouteToBroker">
                                            Route received DMR messages into the broker (so cross-protocol
                                            routing rules can forward them to chat / mesh / Slack / SMS).
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <!-- Token reveal section (shown once after create or rotate) -->
                            <div class="alert alert-warning small mt-3 d-none" id="dvsTokenReveal">
                                <strong>Save this bearer token now — it will not be shown again.</strong>
                                <pre class="mb-2 mt-1" id="dvsTokenValue"></pre>
                                Paste into <code>/etc/ticketscad/dvswitch-&lt;instance&gt;.env</code> as
                                <code>DMR_BEARER_TOKEN</code>, then
                                <code>systemctl restart ticketscad-dvswitch@&lt;instance&gt;</code>.
                                <button type="button" class="btn btn-sm btn-outline-secondary mt-2"
                                        id="dvsCopyToken">
                                    <i class="bi bi-clipboard me-1"></i>Copy to clipboard
                                </button>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-sm btn-outline-secondary"
                                    data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-sm btn-primary" id="dvsSaveBtn">
                                <i class="bi bi-check-lg me-1"></i>Save
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Test (health + tx) modal -->
        <div class="modal fade" id="dvsTestModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Test channel</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" id="dvsTestId" value="">
                        <p class="small text-body-secondary mb-2">
                            Paste the bearer token you saved when you minted this channel.
                            We don't store the plaintext, so each test requires the token.
                        </p>
                        <div class="mb-2">
                            <label class="form-label form-label-sm">Bridge bearer token</label>
                            <input type="password" class="form-control form-control-sm"
                                   id="dvsTestToken" placeholder="paste 64-char hex">
                        </div>
                        <div class="d-flex gap-2 mb-3 flex-wrap">
                            <button type="button" class="btn btn-sm btn-outline-primary" id="dvsTestHealthBtn">
                                <i class="bi bi-heart-pulse me-1"></i>Test /health
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-warning" id="dvsTestTxBtn">
                                <i class="bi bi-broadcast me-1"></i>TX 0.5s 1 kHz tone
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="dvsLoadMessagesBtn">
                                <i class="bi bi-list-ul me-1"></i>Recent transcripts
                            </button>
                        </div>
                        <div class="mb-3 border rounded p-2 bg-body-tertiary">
                            <label class="form-label form-label-sm mb-1" for="dvsTxTextBody">
                                <i class="bi bi-mic-fill text-warning me-1"></i>Speak text on the talkgroup
                            </label>
                            <textarea id="dvsTxTextBody" class="form-control form-control-sm"
                                      rows="2" maxlength="280"
                                      placeholder="e.g. All units stand by for a tone test."></textarea>
                            <div class="d-flex justify-content-between align-items-center mt-1">
                                <small class="text-body-secondary">
                                    Piper synthesises, the bridge keys the radio.
                                    Audit-logged.
                                </small>
                                <button type="button" class="btn btn-sm btn-warning" id="dvsTxTextBtn">
                                    <i class="bi bi-send me-1"></i>Send text
                                </button>
                            </div>
                        </div>
                        <pre class="mb-0 small bg-body-tertiary p-2" id="dvsTestResult"
                             style="max-height: 240px; overflow: auto;">(no response yet)</pre>
                        <div class="mt-3 d-none" id="dvsMessagesBox">
                            <div class="small fw-semibold text-body-secondary mb-1">
                                Recent transcripts (from <code>dmr_messages</code>)
                            </div>
                            <div class="table-responsive" style="max-height: 240px; overflow: auto;">
                                <table class="table table-sm table-hover small mb-0" id="dvsMessagesTable">
                                    <thead>
                                        <tr>
                                            <th>Time</th>
                                            <th>Dir</th>
                                            <th>TG</th>
                                            <th>From</th>
                                            <th>Transcript</th>
                                            <th>Play</th>
                                        </tr>
                                    </thead>
                                    <tbody></tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── OwnTracks Defaults (Phase 51) ──────────────────────── -->
        <div class="config-panel" id="panel-owntracks-defaults">
            <div class="config-panel-title">
                <i class="bi bi-geo-alt text-primary"></i> OwnTracks Defaults
            </div>

            <!-- ── Authentication (Phase 91 followup — surfaces what was SQL-only) ── -->
            <div class="settings-group mb-3">
                <div class="settings-group-title">Authentication</div>
                <p class="text-body-secondary small mb-2">
                    Three auth paths can satisfy an OwnTracks POST, in priority order:
                    <strong>(1)</strong> a per-member token from
                    <button class="btn btn-link btn-sm p-0 align-baseline" data-tab="provider-settings">Provider Settings</button>'s rotation flow
                    (sent as Basic-Auth password with the member's login as username), or
                    <strong>(2)</strong> a per-device token from
                    <button class="btn btn-link btn-sm p-0 align-baseline" data-tab="location-ingest">Location Ingest</button>'s Mint Token modal
                    (paste into OwnTracks <em>Password</em> field; any username works), or
                    <strong>(3)</strong> the legacy shared secret below. When the
                    <strong>"Require token"</strong> toggle is on, only (1) and (2) are accepted.
                </p>

                <form id="otAuthForm">
                    <div class="row g-2">
                        <div class="col-md-12">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="setOtRequireToken"
                                       data-key="owntracks_require_token">
                                <label class="form-check-label" for="setOtRequireToken">
                                    Require token (reject shared-secret and anonymous paths)
                                </label>
                            </div>
                            <div class="form-text">
                                CJIS-grade lockdown. Either Phase 41 per-member tokens OR Phase 89 per-device
                                tokens (from the Mint Token modal) satisfy this — both are accepted.
                            </div>
                        </div>
                        <div class="col-md-12">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="setOtAllowAnonymous"
                                       data-key="owntracks_allow_anonymous">
                                <label class="form-check-label" for="setOtAllowAnonymous">
                                    Allow anonymous (no auth required at all)
                                </label>
                            </div>
                            <div class="form-text">
                                Off by default — fail-closed. Enable only for evaluation on a private LAN or
                                behind an IP allowlist. Has NO effect when "Require token" above is on.
                            </div>
                        </div>
                        <div class="col-md-12">
                            <label for="setOtSecret" class="form-label form-label-sm">
                                Legacy shared secret (optional)
                            </label>
                            <div class="input-group input-group-sm">
                                <input type="text" class="form-control" id="setOtSecret"
                                       data-key="owntracks_secret"
                                       placeholder="(blank = no shared-secret path)">
                                <button class="btn btn-outline-secondary" type="button" id="btnGenOtSecret"
                                        title="Generate a strong random secret">
                                    <i class="bi bi-shuffle"></i> Generate
                                </button>
                            </div>
                            <div class="form-text">
                                Single secret all devices use, sent as <code>X-Limit-U</code> header
                                or <code>?secret=&lt;value&gt;</code>. Per-device tokens are preferred —
                                this exists for backwards compatibility with older OwnTracks deployments.
                            </div>
                        </div>
                    </div>
                    <div class="d-flex gap-2 mt-3">
                        <button type="submit" class="btn btn-sm btn-success">
                            <i class="bi bi-check-lg me-1"></i>Save Auth Settings
                        </button>
                    </div>
                </form>
            </div>

            <div class="settings-group-title">Reporting defaults (battery + post rate)</div>
            <p class="text-body-secondary small mb-2">
                Battery + reporting-rate knobs pushed to every OwnTracks device the next time it posts a position.
                Blank fields inherit from the built-in fallback (battery-friendly: monitoring=Significant, 60s floor, 50m displacement, BalancedPower).
                Individual members can override these in their roster record's OwnTracks Overrides card.
            </p>

            <div id="otDefaultsForm">
                <div class="text-center text-body-secondary py-3" id="otDefaultsLoading">
                    <span class="spinner-border spinner-border-sm me-2"></span>Loading defaults...
                </div>
                <div id="otDefaultsFields" class="d-none"></div>
                <div class="border-top pt-2 mt-2 d-none" id="otDefaultsActions">
                    <button class="btn btn-sm btn-success" id="btnSaveOtDefaults">
                        <i class="bi bi-check-lg me-1"></i>Save &amp; Push to All Active Phones
                    </button>
                    <button class="btn btn-sm btn-outline-secondary ms-1" id="btnResetOtDefaults" title="Clear every field so all members inherit the hardcoded fallback">
                        <i class="bi bi-arrow-counterclockwise me-1"></i>Clear All (Use Hardcoded)
                    </button>
                    <small class="text-body-secondary ms-2" id="otDefaultsStatus"></small>
                </div>
            </div>

            <div class="alert alert-warning small mt-3 mb-0">
                <strong><i class="bi bi-info-circle me-1"></i>OwnTracks Android quirk —</strong>
                changes to <code>locatorInterval</code> and <code>locatorDisplacement</code> don't take effect until the phone restarts its location service.
                Tell users to open OwnTracks → Preferences → Reporting → toggle Monitoring off and back to Significant (or force-stop the app) once you've pushed new defaults.
            </div>

            <div class="border-top pt-2 mt-3">
                <a href="owntracks-diagnostics.php" class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-clipboard-data me-1"></i>View live diagnostics →
                </a>
                <small class="text-body-secondary ms-2">Per-member effective config + post rate + outbox log. Useful for verifying a push actually landed.</small>
            </div>
        </div>

        <!-- ── APRS Configuration ─────────────────────────────────── -->
        <div class="config-panel" id="panel-aprs-config">
            <div class="config-panel-title">
                <i class="bi bi-broadcast text-success"></i> APRS Configuration
            </div>

            <!-- Quick-jump nav. Three concerns share this panel and
                 they were getting confused with each other. Anchor
                 links so admins land on the right section directly. -->
            <ul class="nav nav-pills mb-3" role="tablist">
                <li class="nav-item" role="presentation"><a class="nav-link active" id="aprs-tab-radio"
                    data-bs-toggle="tab" data-bs-target="#aprs-section-radio" role="tab"
                    aria-controls="aprs-section-radio" aria-selected="true" href="#">
                    <i class="bi bi-broadcast-pin me-1"></i>Station Radio (send + receive)
                </a></li>
                <li class="nav-item" role="presentation"><a class="nav-link" id="aprs-tab-listener"
                    data-bs-toggle="tab" data-bs-target="#aprs-section-listener" role="tab"
                    aria-controls="aprs-section-listener" aria-selected="false" href="#">
                    <i class="bi bi-activity me-1"></i>Listener Status
                </a></li>
                <li class="nav-item" role="presentation"><a class="nav-link" id="aprs-tab-map"
                    data-bs-toggle="tab" data-bs-target="#aprs-section-map" role="tab"
                    aria-controls="aprs-section-map" aria-selected="false" href="#">
                    <i class="bi bi-map me-1"></i>APRS Map
                </a></li>
                <li class="nav-item" role="presentation"><a class="nav-link" id="aprs-tab-polling"
                    data-bs-toggle="tab" data-bs-target="#aprs-section-polling" role="tab"
                    aria-controls="aprs-section-polling" aria-selected="false" href="#">
                    <i class="bi bi-arrow-repeat me-1"></i>aprs.fi Polling (fallback)
                </a></li>
            </ul>

            <!-- ─── SECTION 1: Station Radio (sending + receiving) ──────
                 The primary path. License gate + callsign/passcode +
                 server/port. Drives both inc/channels/aprs.php (send)
                 AND services/aprs/aprs_listener.py (receive) — they
                 share aprs_send_callsign / aprs_send_passcode /
                 aprs_is_server / aprs_is_port. -->
            <!-- ONE form wraps Radio + Polling so the existing
                 bindAprsConfigPanel() JS keeps working unchanged
                 (it reads all data-key inputs from this form). The
                 visual section boundaries are just <div> headers. -->
            <form id="aprsConfigForm">

            <div class="tab-content">

            <div id="aprs-section-radio" class="tab-pane fade show active settings-group mb-4"
                 role="tabpanel" aria-labelledby="aprs-tab-radio">
                <h6 class="border-bottom pb-2 mb-3">
                    <i class="bi bi-broadcast-pin me-1"></i>Station Radio (send + receive)
                    <span class="badge bg-success ms-1" style="font-size:0.65rem;">PRIMARY</span>
                </h6>
                <p class="small text-body-secondary mb-2">
                    Configures the station identity this install uses on APRS-IS.
                    Drives <strong>both</strong> outbound messages (Compose → APRS channel)
                    <strong>and</strong> the realtime receive listener that populates the
                    APRS Map page + Messages Inbox. The passcode is computed from the
                    base callsign per the public APRS-IS algorithm; no recoverable
                    password.
                </p>

                    <!-- License attestation gate. When unaccepted: warning + checkbox.
                         When accepted: small confirmation line + the configuration
                         fields are unlocked. JS reveals the fields on accept. -->
                    <div id="aprsLicenseGate" class="alert alert-warning small mb-3" style="display:none;">
                        <div class="d-flex">
                            <div class="me-2 fs-5"><i class="bi bi-exclamation-triangle-fill text-warning"></i></div>
                            <div>
                                <strong>FCC Amateur Radio license required.</strong>
                                APRS-IS transmits on the US Amateur Radio service. Under
                                <a href="https://www.ecfr.gov/current/title-47/chapter-I/subchapter-D/part-97" target="_blank">FCC Part 97</a>,
                                only licensed amateur radio operators may transmit. The passcode field below is computed from a callsign — entering one and transmitting under it without a valid license is a federal violation.
                                <div class="form-check mt-2">
                                    <input class="form-check-input" type="checkbox" id="aprsLicenseAccept">
                                    <label class="form-check-label" for="aprsLicenseAccept">
                                        I attest that I hold a current FCC Amateur Radio license (or equivalent in my jurisdiction) and understand that I am the responsible licensee for any transmissions made by this station.
                                    </label>
                                </div>
                                <button type="button" class="btn btn-sm btn-warning mt-2" id="btnAprsAcceptLicense" disabled>
                                    <i class="bi bi-check-lg me-1"></i>Accept and unlock APRS sending
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Acceptance confirmation (hidden until accepted) -->
                    <div id="aprsLicenseAccepted" class="alert alert-success small py-2 mb-3" style="display:none;">
                        <i class="bi bi-shield-check me-1"></i>
                        FCC Amateur Radio license attestation accepted
                        <span id="aprsLicenseAcceptedDetail" class="text-body-secondary"></span>.
                    </div>

                    <!-- Configuration fields — disabled until license attestation accepted. -->
                    <fieldset id="aprsSendFieldset" disabled>
                        <div class="row g-2 align-items-end">
                            <div class="col-md-4">
                                <label for="setAprsSendCallsign" class="form-label form-label-sm">
                                    Callsign
                                    <i class="bi bi-question-circle text-body-secondary" tabindex="0"
                                       data-bs-toggle="popover" data-bs-trigger="hover focus" data-bs-placement="top"
                                       data-bs-html="true"
                                       data-bs-content="Full callsign with SSID, e.g. <code>N0NE-10</code> (placeholder format only — replace with YOUR licensed callsign). The SSID is a number from -0 to -15 distinguishing multiple stations under one license. -10 is the conventional APRS &quot;internet gateway&quot; SSID."
                                       title="Callsign help"></i>
                                </label>
                                <input type="text" class="form-control form-control-sm" id="setAprsSendCallsign"
                                       data-key="aprs_send_callsign" placeholder="e.g. N0NE-10" maxlength="16">
                            </div>
                            <div class="col-md-3">
                                <label for="setAprsSendPasscode" class="form-label form-label-sm">
                                    Passcode
                                    <i class="bi bi-question-circle text-body-secondary" tabindex="0"
                                       data-bs-toggle="popover" data-bs-trigger="hover focus" data-bs-placement="top"
                                       data-bs-content="Auto-computed from the base callsign. The APRS-IS algorithm is public — every passcode calculator uses it. Forget your passcode? Just retype the callsign here and click Compute."
                                       title="Passcode help"></i>
                                </label>
                                <input type="number" class="form-control form-control-sm" id="setAprsSendPasscode"
                                       data-key="aprs_send_passcode" placeholder="auto" readonly>
                            </div>
                            <div class="col-md-3">
                                <button type="button" class="btn btn-sm btn-outline-primary w-100" id="btnAprsComputePass">
                                    <i class="bi bi-key me-1"></i>Compute passcode
                                </button>
                            </div>
                        </div>
                        <div class="row g-2 mt-1">
                            <div class="col-md-4">
                                <label for="setAprsIsServer" class="form-label form-label-sm">APRS-IS Server</label>
                                <input type="text" class="form-control form-control-sm" id="setAprsIsServer"
                                       data-key="aprs_is_server" placeholder="(prefilled on license accept)">
                                <div class="form-text">Default <code>rotate.aprs2.net</code> (auto-selects nearest tier-2).</div>
                            </div>
                            <div class="col-md-2">
                                <label for="setAprsIsPort" class="form-label form-label-sm">Port</label>
                                <input type="number" class="form-control form-control-sm" id="setAprsIsPort"
                                       data-key="aprs_is_port" placeholder="(prefilled on accept)" min="1" max="65535">
                            </div>
                            <div class="col-md-6">
                                <label for="setAprsRecvFilter" class="form-label form-label-sm">
                                    Receive Filter (listener)
                                    <i class="bi bi-question-circle text-body-secondary" tabindex="0"
                                       data-bs-toggle="popover" data-bs-trigger="hover focus" data-bs-placement="top"
                                       data-bs-html="true"
                                       data-bs-content="APRS-IS server-side filter spec. <code>r/LAT/LNG/KM</code> = radius around a point. <code>p/CALLSIGN</code> = follow a specific call. Combine with spaces. See <a href='http://www.aprs-is.net/javAPRSFilter.aspx' target='_blank'>filter syntax</a>."
                                       title="Filter help"></i>
                                </label>
                                <div class="input-group input-group-sm">
                                    <input type="text" class="form-control form-control-sm" id="setAprsRecvFilter"
                                           data-key="aprs_recv_filter" placeholder="r/45.0/-93.0/200">
                                    <button type="button" class="btn btn-outline-secondary" id="btnAprsFilterMap"
                                            data-bs-toggle="modal" data-bs-target="#aprsFilterMapModal"
                                            title="Pick a center + radius on a map">
                                        <i class="bi bi-geo-alt"></i> Map
                                    </button>
                                </div>
                                <div class="form-text">Default 200km around Twin Cities. Restart listener after change.</div>
                            </div>
                        </div>
                    </fieldset>

            </div>

            <!-- ─── SECTION 2: Listener Status ────────────────────────
                 Live view of the receive daemon. JS calls
                 /api/aprs-positions.php which already returns
                 listener_status + last_seen_ago + station count. -->
            <div id="aprs-section-listener" class="tab-pane fade settings-group mb-4"
                 role="tabpanel" aria-labelledby="aprs-tab-listener">
                <h6 class="border-bottom pb-2 mb-3">
                    <i class="bi bi-activity me-1"></i>Listener Status
                </h6>
                <div id="aprsListenerStatusCard" class="border rounded p-3">
                    <div class="text-center text-body-secondary small py-2">
                        <span class="spinner-border spinner-border-sm me-1"></span>
                        Loading status…
                    </div>
                </div>
                <p class="small text-body-secondary mt-2 mb-0">
                    The listener runs as systemd service <code>ticketscad-aprs-listener.service</code>
                    (see <code>services/aprs/aprs_listener.py</code>). Watch live with
                    <code>sudo journalctl -fu ticketscad-aprs-listener.service</code> on the server.
                    The map view is at <a href="aprs-map.php" target="_blank">APRS Map</a>.
                </p>
            </div>

            <!-- ─── SECTION 3: aprs.fi Polling (fallback) ─────────────
                 The legacy/optional path. Cron-driven, 5min latency.
                 Useful as a backup when the listener is down, or for
                 installs that don't want the realtime daemon. -->
            <div id="aprs-section-polling" class="tab-pane fade settings-group"
                 role="tabpanel" aria-labelledby="aprs-tab-polling">
                <h6 class="border-bottom pb-2 mb-3">
                    <i class="bi bi-arrow-repeat me-1"></i>aprs.fi Polling
                    <span class="badge bg-secondary ms-1" style="font-size:0.65rem;">FALLBACK / OPTIONAL</span>
                </h6>
                <p class="small text-body-secondary mb-2">
                    Optional cron-driven poller against the <a href="https://aprs.fi/page/api" target="_blank">aprs.fi REST API</a>
                    (5-minute cycles, not realtime). Useful as a backup if the listener
                    is restarting, or for installs that don't want to run the realtime
                    daemon. Most installs leave this disabled and rely on the listener.
                </p>

                    <div class="row g-2">
                        <div class="col-md-6">
                            <label for="setAprsApiKey" class="form-label form-label-sm">
                                API Key
                                <i class="bi bi-question-circle text-body-secondary" tabindex="0"
                                   data-bs-toggle="popover" data-bs-trigger="hover focus" data-bs-placement="top"
                                   data-bs-content="Get a free API key from <a href='https://aprs.fi/page/api' target='_blank'>aprs.fi/page/api</a>. Required for APRS position polling."
                                   data-bs-html="true"
                                   title="APRS.fi API key"></i>
                            </label>
                            <input type="text" class="form-control form-control-sm" id="setAprsApiKey"
                                   data-key="aprs_fi_api_key" placeholder="Enter aprs.fi API key" autocomplete="off">
                        </div>
                        <div class="col-md-3">
                            <label for="setAprsPollInterval" class="form-label form-label-sm">Poll Interval (min)</label>
                            <input type="number" class="form-control form-control-sm" id="setAprsPollInterval"
                                   data-key="aprs_poll_interval" min="1" max="60" placeholder="5">
                            <div class="form-text">How often the cron job polls aprs.fi (default: 5 min).</div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label form-label-sm">Enable Polling</label>
                            <div class="form-check form-switch mt-1">
                                <input class="form-check-input" type="checkbox" id="setAprsEnabled" data-key="aprs_enabled">
                                <label class="form-check-label small" for="setAprsEnabled">aprs.fi polling active</label>
                            </div>
                        </div>
                    </div>

                    <div class="row g-2 align-items-end mt-2">
                        <div class="col-md-4">
                            <label for="aprsTestCallsign" class="form-label form-label-sm">Test Callsign</label>
                            <input type="text" class="form-control form-control-sm" id="aprsTestCallsign"
                                   placeholder="e.g. W1AW-9" maxlength="16">
                        </div>
                        <div class="col-md-3">
                            <button type="button" class="btn btn-sm btn-outline-primary w-100" id="btnAprsTest">
                                <i class="bi bi-broadcast me-1"></i>Test API
                            </button>
                        </div>
                    </div>
                    <div id="aprsTestResult" class="mt-2" style="display:none;"></div>

                    <div class="alert alert-info small mt-2 mb-0">
                        <i class="bi bi-info-circle me-1"></i>
                        <strong>Cron setup:</strong> Add this to your server crontab to enable automatic polling:<br>
                        <code class="user-select-all">*/5 * * * * php <?php echo NEWUI_ROOT; ?>/tools/aprs-poller.php >> /var/log/aprs-poller.log 2>&amp;1</code>
                    </div>

            </div>

            <!-- ─── TAB: APRS Map (live view of received positions) ──
                 Inline Leaflet view modeled after aprs-map.php so an
                 admin can confirm the listener is working without
                 leaving the Settings panel. Reuses /api/aprs-positions.php
                 (same backend as the dedicated map page). Lazy-init
                 on first tab show; auto-refreshes while tab is active. -->
            <div id="aprs-section-map" class="tab-pane fade settings-group"
                 role="tabpanel" aria-labelledby="aprs-tab-map">
                <h6 class="border-bottom pb-2 mb-3 d-flex align-items-center gap-2">
                    <i class="bi bi-map"></i>APRS Map
                    <span class="badge bg-info" style="font-size:0.65rem;">SETTINGS PREVIEW</span>
                    <!-- Prominent link to the full-featured standalone page.
                         Eric beta 2026-06-29: this tab is a status preview;
                         the rich features (List view, symbol icons, motion
                         projection, watchlist, basemap selector) live on
                         /aprs-map.php and dispatchers need to find that page
                         from here. -->
                    <a href="aprs-map.php" target="_blank" class="btn btn-sm btn-primary ms-auto">
                        <i class="bi bi-arrow-up-right-square me-1"></i>Open full APRS Map page
                    </a>
                </h6>
                <p class="small text-body-secondary mb-2">
                    Stations heard by the listener in the configured filter window.
                    Refreshes every 60 seconds while this tab is open. The
                    standalone <a href="aprs-map.php" target="_blank">APRS Map page</a>
                    adds: sortable List view of every received data field,
                    APRS symbol icons (🚗 🌤️ 🚲 …), motion projection for
                    moving stations, watchlist, basemap selector
                    (satellite / topo / etc.).
                </p>

                <div class="d-flex align-items-center gap-2 mb-2 small">
                    <span id="aprsTabMapStatus" class="badge bg-secondary">—</span>
                    <span id="aprsTabMapCount" class="text-body-secondary"></span>
                    <span class="ms-auto">Window:
                        <select class="form-select form-select-sm d-inline-block w-auto" id="aprsTabMapSince">
                            <option value="15">Last 15 min</option>
                            <option value="60" selected>Last hour</option>
                            <option value="240">Last 4 h</option>
                            <option value="1440">Last 24 h</option>
                        </select>
                    </span>
                </div>
                <!-- Resizable wrapper (Eric beta 2026-06-29): CSS
                     resize: vertical lets the user drag the bottom-right
                     corner to grow/shrink the map. A ResizeObserver in
                     JS catches the size change and calls Leaflet's
                     invalidateSize so tiles re-render to fit. Height
                     persists in localStorage so it survives reloads. -->
                <div id="aprsTabMapWrap" style="resize: vertical; overflow: hidden;
                     height: 420px; min-height: 200px; max-height: 90vh;
                     border: 1px solid var(--bs-border-color); border-radius: 0.5rem;
                     position: relative;">
                    <div id="aprsTabMap" style="height: 100%;"></div>
                </div>
                <div class="form-text mt-1 d-flex align-items-center gap-2">
                    <span>
                        Green = heard &lt;5 min, yellow = 5–30 min, grey = older.
                        Click a marker for callsign + position detail.
                    </span>
                    <span class="ms-auto text-body-tertiary" style="font-size:0.75rem;">
                        <i class="bi bi-arrows-vertical"></i> Drag bottom-right corner to resize ·
                        <a href="#" id="aprsTabMapResetSize" class="text-body-secondary">reset</a> ·
                        <a href="#" id="aprsTabMapFitVH" class="text-body-secondary">fit viewport</a>
                    </span>
                </div>
            </div>

            </div><!-- /tab-content -->

            <!-- Single Save button (Radio + Polling tabs share one
                 form — pressing Save persists every data-key input
                 across all tabs in one POST). The Listener Status
                 and APRS Map tabs have no inputs and are unaffected. -->
            <div class="d-flex gap-2 mt-3">
                <button type="submit" class="btn btn-sm btn-success">
                    <i class="bi bi-check-lg me-1"></i>Save APRS Settings
                </button>
            </div>

            </form>

        </div>

        <!-- APRS Receive Filter — map picker modal.
             Eric beta 2026-06-29: "popup widget that makes it really
             easy to create the Receive Filter — map we click and we
             choose the radius and a circle appears on our map". Reuses
             the existing Leaflet vendor + OSM tiles. Coordinates
             rounded to 2 decimals (~1.1km precision, more than enough
             for a radius filter that itself works in whole km). -->
        <div class="modal fade" id="aprsFilterMapModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h6 class="modal-title">
                            <i class="bi bi-geo-alt me-1"></i>APRS Receive Filter — Pick Area
                        </h6>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p class="small text-body-secondary mb-2">
                            Click the map to choose a center point, then adjust the radius.
                            The blue circle shows what the listener will receive. The
                            current filter (if any) pre-populates on open.
                        </p>
                        <div id="aprsFilterMap" style="height: 400px; border-radius: 0.5rem; border: 1px solid var(--bs-border-color);"></div>
                        <div class="row g-2 align-items-end mt-3">
                            <div class="col-md-4">
                                <label for="aprsFilterRadius" class="form-label form-label-sm mb-0">Radius (km)</label>
                                <input type="number" class="form-control form-control-sm" id="aprsFilterRadius"
                                       value="200" min="1" max="5000" step="1">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label form-label-sm mb-0">Center</label>
                                <div class="form-control form-control-sm bg-body-tertiary text-body-secondary"
                                     id="aprsFilterCenter" style="font-family: var(--bs-font-monospace);">
                                    click map to set
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label form-label-sm mb-0">Filter string</label>
                                <div class="form-control form-control-sm font-monospace small"
                                     id="aprsFilterPreview" style="overflow-x: auto;">
                                    —
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-sm btn-primary" id="btnAprsFilterApply" disabled>
                            <i class="bi bi-check-lg me-1"></i>Use this filter
                        </button>
                    </div>
                </div>
            </div>
        </div>


        <!-- ── ATAK / TAK (Phase 91) ───────────────────────────────── -->
        <!-- Channels (per-channel push policy + sensitive_flag),
             recent CoT events, unbound-uid review. Tokens for ATAK
             ingest are minted via the Phase 89 Location Ingest panel
             with provider scoped to ATAK — no duplicate UI here. -->
        <div class="config-panel" id="panel-atak-tak">
            <div class="config-panel-title">
                <i class="bi bi-geo-alt-fill text-primary"></i> ATAK / TAK (CoT bridge)
            </div>
            <p class="text-body-secondary small mb-3">
                Bidirectional <strong>Cursor-on-Target</strong> bridge between TicketsCAD and
                ATAK / iTAK / WinTAK clients. ATAK rides the existing Meshtastic mesh bridges —
                see <a href="mesh-console.php">Mesh Console</a> for the bridges themselves and
                the channels they're assigned to. This panel only controls the <em>ATAK policy</em>
                layered onto those channels (sensitive flag, push toggles, marker action).
                Operator guide: <a href="docs/ATAK-SETUP.md" target="_blank" rel="noopener">docs/ATAK-SETUP.md</a>.
            </p>

            <!-- ── Channels list (mesh_channels with ATAK policy) ───── -->
            <div class="settings-group mb-3">
                <div class="settings-group-title d-flex align-items-center">
                    Channels
                    <button class="btn btn-sm btn-outline-secondary ms-auto" type="button" id="btnRefreshAtakChannels">
                        <i class="bi bi-arrow-clockwise"></i>
                    </button>
                </div>
                <p class="text-body-secondary small mb-2">
                    Every mesh channel from <a href="mesh-console.php">Mesh Console</a>. Toggle
                    <strong>Enabled</strong> on any channel where you want CoT routing to happen.
                    The <strong>sensitive</strong> flag (default ON per the project's CJIS posture) strips PII
                    fields from outbound CoT. Channel rows themselves (name, PSK, assigned bridges)
                    are managed under Mesh Console — this panel only edits the ATAK policy.
                </p>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Channel</th>
                                <th>Bridges</th>
                                <th>ATAK</th>
                                <th>Sensitive</th>
                                <th>Push</th>
                                <th>Marker default</th>
                                <th>Rate limit</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="atakChannelsBody">
                            <tr><td colspan="8" class="text-body-secondary small text-center py-3">Loading…</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ── Unbound uids ─────────────────────────────────── -->
            <div class="settings-group mb-3">
                <div class="settings-group-title d-flex align-items-center">
                    Unbound ATAK devices
                    <button class="btn btn-sm btn-outline-secondary ms-auto" type="button" id="btnRefreshAtakUnbound">
                        <i class="bi bi-arrow-clockwise"></i>
                    </button>
                </div>
                <p class="text-body-secondary small mb-2">
                    ATAK uids that have been calling in but aren't yet bound to a personnel record.
                    Their position data IS being captured — bind here to attribute it to a real
                    person on the dispatch map.
                </p>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th>ATAK UID</th>
                                <th>Callsign seen</th>
                                <th>Transport</th>
                                <th>Channel</th>
                                <th>Reports</th>
                                <th>Last seen</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="atakUnboundBody">
                            <tr><td colspan="7" class="text-body-secondary small text-center py-3">Loading…</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ── Recent CoT events (mesh_packet_log filtered to ATAK) ── -->
            <div class="settings-group">
                <div class="settings-group-title d-flex align-items-center">
                    Recent CoT events
                    <button class="btn btn-sm btn-outline-secondary ms-auto" type="button" id="btnRefreshAtakRecent">
                        <i class="bi bi-arrow-clockwise"></i>
                    </button>
                </div>
                <p class="text-body-secondary small mb-2">
                    Last 50 ATAK-bearing packets from the live mesh. Sourced from
                    <code>mesh_packet_log</code> filtered to <code>ATAK_PLUGIN</code> port and
                    text messages starting with <code>&lt;event</code>. See <a href="mesh-console.php">Mesh Console</a> for the full packet feed.
                </p>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Bridge</th>
                                <th>Node</th>
                                <th>Port</th>
                                <th>Position</th>
                                <th>SNR / RSSI</th>
                                <th>Payload preview</th>
                                <th>When</th>
                            </tr>
                        </thead>
                        <tbody id="atakRecentBody">
                            <tr><td colspan="7" class="text-body-secondary small text-center py-3">Loading…</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ── ATAK policy edit modal (edits mesh_channels atak_* columns ONLY) ── -->
        <div class="modal fade" id="atakChannelModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="bi bi-geo-alt-fill text-primary me-1"></i> ATAK Policy</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p class="text-body-secondary small mb-3">
                            Editing the ATAK policy on <code id="atakChanName">…</code>. The
                            channel's name, PSK, and bridge assignments are managed under
                            <a href="mesh-console.php">Mesh Console</a>; this dialog only edits
                            the ATAK-specific routing.
                        </p>
                        <form id="atakChannelForm">
                            <input type="hidden" name="id" value="">
                            <div class="row g-3">
                                <div class="col-md-12">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" name="atak_enabled" id="atakChanEnabled" checked>
                                        <label class="form-check-label fw-semibold" for="atakChanEnabled">
                                            Route ATAK CoT for this channel
                                        </label>
                                    </div>
                                    <div class="form-text">When off, packets from this channel still land in <code>mesh_packet_log</code> but no CoT routing happens — no incidents created, no positions logged.</div>
                                </div>

                                <div class="col-md-12">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" name="atak_sensitive_flag" id="atakChanSensitive" checked>
                                        <label class="form-check-label" for="atakChanSensitive">
                                            Sensitive channel — strip PII fields from outbound CoT (recommended)
                                        </label>
                                    </div>
                                </div>

                                <div class="col-md-12">
                                    <label class="form-label form-label-sm">Push entity classes (outbound to ATAK)</label>
                                    <div class="d-flex gap-3 flex-wrap">
                                        <div class="form-check form-check-inline">
                                            <input class="form-check-input" type="checkbox" name="atak_push_incidents" id="atakChanIncidents" checked>
                                            <label class="form-check-label" for="atakChanIncidents">Incidents</label>
                                        </div>
                                        <div class="form-check form-check-inline">
                                            <input class="form-check-input" type="checkbox" name="atak_push_units" id="atakChanUnits" checked>
                                            <label class="form-check-label" for="atakChanUnits">Units / responders</label>
                                        </div>
                                        <div class="form-check form-check-inline">
                                            <input class="form-check-input" type="checkbox" name="atak_push_facilities" id="atakChanFacilities">
                                            <label class="form-check-label" for="atakChanFacilities">Facilities</label>
                                        </div>
                                        <div class="form-check form-check-inline">
                                            <input class="form-check-input" type="checkbox" name="atak_push_chat" id="atakChanChat" checked>
                                            <label class="form-check-label" for="atakChanChat">Chat</label>
                                        </div>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label form-label-sm">Inbound marker default</label>
                                    <select class="form-select form-select-sm" name="atak_marker_action">
                                        <option value="new_incident">Create new incident</option>
                                        <option value="note_nearest">Append note to nearest open incident</option>
                                    </select>
                                    <div class="form-text">Circle markers (<code>u-d-c-c</code>) always create a new geofenced incident regardless.</div>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label form-label-sm">Min sec/position</label>
                                    <input type="number" class="form-control form-control-sm" name="atak_position_min_secs"
                                           min="5" max="3600" value="60">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label form-label-sm">Min meters/position</label>
                                    <input type="number" class="form-control form-control-sm" name="atak_position_min_m"
                                           min="0" max="10000" value="25">
                                </div>
                            </div>
                        </form>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-sm btn-primary" id="atakChannelSubmit">
                            <i class="bi bi-check-lg me-1"></i>Save
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── ATAK unbound-uid bind modal ────────────────────────── -->
        <div class="modal fade" id="atakBindModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="bi bi-person-plus me-1"></i> Bind ATAK UID to Personnel</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <form id="atakBindForm">
                            <input type="hidden" name="atak_uid" value="">
                            <div class="mb-2">
                                <label class="form-label form-label-sm">ATAK UID</label>
                                <input type="text" class="form-control form-control-sm" name="uid_display" disabled>
                            </div>
                            <div class="mb-2">
                                <label class="form-label form-label-sm">Personnel</label>
                                <select class="form-select form-select-sm" name="member_id" id="atakBindMember" required>
                                    <option value="">-- pick personnel --</option>
                                </select>
                                <div class="form-text">Writes a comm-identifier row so future positions attribute to this person.</div>
                            </div>
                        </form>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-sm btn-primary" id="atakBindSubmit">
                            <i class="bi bi-link-45deg me-1"></i>Bind
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Location Data Retention ───────────────────────────────── -->
        <div class="config-panel" id="panel-location-retention">
            <div class="config-panel-title">
                <i class="bi bi-clock-history text-info"></i> Location Data Retention
            </div>
            <p class="text-body-secondary small mb-2">Configure how long location history is kept. Older records are automatically purged during each poller run.</p>

            <form id="locationRetentionForm">
                <div class="row g-2">
                    <div class="col-md-4">
                        <label for="setLocationRetention" class="form-label form-label-sm">
                            Retention Period (days)
                            <i class="bi bi-question-circle text-body-secondary" tabindex="0"
                               data-bs-toggle="popover" data-bs-trigger="hover focus" data-bs-placement="top"
                               data-bs-content="Location reports older than this many days are automatically deleted. Set to 0 to keep records forever. Default: 90 days."
                               title="Retention period help"></i>
                        </label>
                        <input type="number" class="form-control form-control-sm" id="setLocationRetention"
                               data-key="location_retention_days" min="0" max="3650" placeholder="90">
                        <div class="form-text">Set to 0 to disable automatic cleanup. Default: 90 days.</div>
                    </div>
                </div>
                <div class="d-flex gap-2 mt-3">
                    <button type="submit" class="btn btn-sm btn-success"><i class="bi bi-check-lg me-1"></i>Save Retention Settings</button>
                </div>
            </form>
        </div>

        <!-- ── Location Ingest (Phase 89) ────────────────────────── -->
        <!-- Auth + per-device tokens + recent reports for the native
             Traccar / OpenGTS receiver. The OwnTracks endpoint has its
             own panel ("OwnTracks Defaults"); this one covers the
             non-OwnTracks side of /api/location.php. -->
        <div class="config-panel" id="panel-location-ingest">
            <div class="config-panel-title">
                <i class="bi bi-cloud-arrow-up text-info"></i> Location Ingest (Traccar / OpenGTS)
            </div>
            <p class="text-body-secondary small mb-3">
                Authentication and monitoring for the native Traccar / OpenGTS
                HTTP receiver. OwnTracks has its own panel under
                <button class="btn btn-link btn-sm p-0 align-baseline" data-tab="owntracks-defaults">OwnTracks Defaults</button>.
                Operator guide: <a href="docs/TRACCAR-SETUP.md" target="_blank" rel="noopener">docs/TRACCAR-SETUP.md</a>.
            </p>

            <!-- ── Auth & guard flags ───────────────────────────── -->
            <div class="settings-group mb-3">
                <div class="settings-group-title">Authentication</div>
                <form id="locationIngestSettingsForm">
                    <div class="row g-2">
                        <div class="col-md-6">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="setLocIngestRequireToken"
                                       data-key="location_ingest_require_token">
                                <label class="form-check-label" for="setLocIngestRequireToken">
                                    Require token on every report
                                </label>
                            </div>
                            <div class="form-text">
                                When off, anonymous ingest is accepted (fine on a private LAN
                                or behind an IP allowlist; not recommended for the open
                                Internet). When on, every report must carry either a
                                per-device token (recommended) or the legacy shared
                                secret below.
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="setLocIngestNullIsland"
                                       data-key="location_ingest_allow_null_island">
                                <label class="form-check-label" for="setLocIngestNullIsland">
                                    Accept reports at (0, 0) "Null Island"
                                </label>
                            </div>
                            <div class="form-text">
                                Leave OFF unless you legitimately operate at the equator and
                                prime meridian. The default drops (0,0) reports because they
                                almost always mean the device has no GPS fix and is reporting
                                a default value.
                            </div>
                        </div>

                        <div class="col-md-8">
                            <label for="setLocIngestSecret" class="form-label form-label-sm">
                                Legacy shared secret
                                <i class="bi bi-question-circle text-body-secondary" tabindex="0"
                                   data-bs-toggle="popover" data-bs-trigger="hover focus" data-bs-placement="top"
                                   data-bs-content="A single secret all unmanaged devices use. Per-device tokens (table below) are preferred — one leaked token revokes one device, not the entire fleet. Leave blank to disable the legacy path entirely."
                                   title="Shared secret help"></i>
                            </label>
                            <div class="input-group input-group-sm">
                                <input type="text" class="form-control" id="setLocIngestSecret"
                                       data-key="location_ingest_secret"
                                       placeholder="openssl rand -hex 32">
                                <button class="btn btn-outline-secondary" type="button" id="btnGenIngestSecret"
                                        title="Generate a strong random secret">
                                    <i class="bi bi-shuffle"></i> Generate
                                </button>
                            </div>
                            <div class="form-text">
                                Devices send this as <code>?token=&lt;secret&gt;</code> or
                                <code>Authorization: Bearer &lt;secret&gt;</code>. Per-device
                                tokens (below) take precedence.
                            </div>
                        </div>

                        <div class="col-md-4">
                            <label for="setLocIngestRateLimit" class="form-label form-label-sm">
                                Rate limit (per IP, per minute)
                            </label>
                            <input type="number" class="form-control form-control-sm" id="setLocIngestRateLimit"
                                   data-key="location_ingest_rate_limit_per_min"
                                   min="60" max="6000" placeholder="600">
                            <div class="form-text">Default 600. A misconfigured device flooding the endpoint hits this cap.</div>
                        </div>
                    </div>
                    <div class="d-flex gap-2 mt-3">
                        <button type="submit" class="btn btn-sm btn-success">
                            <i class="bi bi-check-lg me-1"></i>Save Auth Settings
                        </button>
                    </div>
                </form>
            </div>

            <!-- ── Per-device tokens ─────────────────────────────── -->
            <div class="settings-group mb-3">
                <div class="settings-group-title d-flex align-items-center">
                    Per-device tokens
                    <button class="btn btn-sm btn-primary ms-auto" type="button" id="btnMintIngestToken">
                        <i class="bi bi-plus-lg me-1"></i>Mint Token
                    </button>
                </div>
                <p class="text-body-secondary small mb-2">
                    One token per device. Revoking a token affects only that device.
                    The raw token is shown ONCE at mint time — copy it immediately into
                    the device's configuration.
                </p>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0" id="ingestTokensTable">
                        <thead>
                            <tr>
                                <th>Label</th>
                                <th>Provider</th>
                                <th>Bound device ID</th>
                                <th>Reports</th>
                                <th>Last used</th>
                                <th>Status</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="ingestTokensBody">
                            <tr><td colspan="7" class="text-body-secondary small text-center py-3">Loading…</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ── Recent reports ────────────────────────────────── -->
            <div class="settings-group">
                <div class="settings-group-title d-flex align-items-center">
                    Recent reports
                    <div class="ms-auto d-flex align-items-center gap-2">
                        <select id="ingestReportsFilter" class="form-select form-select-sm" style="width:auto">
                            <option value="">All providers</option>
                            <option value="traccar">Traccar</option>
                            <option value="opengts">OpenGTS</option>
                            <option value="owntracks">OwnTracks</option>
                        </select>
                        <button class="btn btn-sm btn-outline-secondary" type="button" id="btnRefreshIngestReports">
                            <i class="bi bi-arrow-clockwise"></i>
                        </button>
                    </div>
                </div>
                <p class="text-body-secondary small mb-2">
                    Last 50 reports across all providers. Use this to verify a newly-onboarded
                    device is reaching TicketsCAD without needing a shell.
                </p>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Provider</th>
                                <th>Device / TID</th>
                                <th>Lat, Lng</th>
                                <th>Speed</th>
                                <th>Token</th>
                                <th>When</th>
                            </tr>
                        </thead>
                        <tbody id="ingestReportsBody">
                            <tr><td colspan="6" class="text-body-secondary small text-center py-3">Loading…</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ── Mint Token modal ───────────────────────────────────── -->
        <div class="modal fade" id="ingestMintModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="bi bi-key text-warning me-1"></i> Mint Ingest Token</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <form id="ingestMintForm">
                            <div class="mb-2">
                                <label class="form-label form-label-sm">Label <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-sm" name="label"
                                       placeholder="Truck-7 Teltonika" required maxlength="120">
                                <div class="form-text">Operator-friendly name. Shown in the token list and audit log.</div>
                            </div>
                            <div class="mb-2">
                                <label class="form-label form-label-sm">Provider (optional)</label>
                                <select class="form-select form-select-sm" name="provider_code">
                                    <option value="">Any provider</option>
                                    <option value="owntracks">OwnTracks</option>
                                    <option value="traccar">Traccar</option>
                                    <option value="opengts">OpenGTS</option>
                                </select>
                                <div class="form-text">
                                    When set, the token is rejected on other providers.
                                    For OwnTracks devices, paste the token into the app's
                                    <em>Password</em> field (any username works);
                                    for Traccar/OpenGTS, use <code>?token=&lt;value&gt;</code>
                                    or <code>Authorization: Bearer &lt;value&gt;</code>.
                                </div>
                            </div>
                            <div class="mb-2">
                                <label class="form-label form-label-sm" id="ingestMintBoundIdLabel">Bound device unique ID (optional)</label>
                                <input type="text" class="form-control form-control-sm" name="device_unique_id"
                                       id="ingestMintBoundIdInput"
                                       placeholder="863719010012345" maxlength="120">
                                <div class="form-text" id="ingestMintBoundIdHint">When set, the token is rejected unless the report's device id matches exactly.</div>
                            </div>
                            <div class="mb-2">
                                <label class="form-label form-label-sm">Notes (optional)</label>
                                <input type="text" class="form-control form-control-sm" name="notes"
                                       placeholder="Issued to Fleet Mgr 2026-06-24" maxlength="255">
                            </div>
                        </form>
                        <div id="ingestMintResult" class="mt-3 d-none">
                            <div class="alert alert-warning small mb-2">
                                <i class="bi bi-exclamation-triangle me-1"></i>
                                <strong>Copy this token now.</strong> It will never be shown again.
                            </div>
                            <div class="input-group input-group-sm">
                                <input type="text" class="form-control font-monospace" id="ingestMintResultToken" readonly>
                                <button class="btn btn-outline-primary" type="button" id="ingestMintResultCopy">
                                    <i class="bi bi-clipboard me-1"></i>Copy
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="button" class="btn btn-sm btn-primary" id="ingestMintSubmit">
                            <i class="bi bi-key me-1"></i>Mint
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Geofencing ────────────────────────────────────────── -->
        <div class="config-panel" id="panel-geofencing">
            <div class="config-panel-title">
                <i class="bi bi-bounding-box text-warning"></i> Geofencing
                <button class="btn btn-sm btn-outline-secondary ms-auto py-0 px-2 az-editor-link" data-tab="alert-zones" style="font-size:0.7rem">
                    <i class="bi bi-arrow-left me-1"></i>Back to Alert Zones
                </button>
            </div>

            <!-- Step-by-step guide for new users -->
            <div class="card border-info mb-3">
                <div class="card-header py-2 bg-info bg-opacity-10 small fw-semibold">
                    <i class="bi bi-signpost-2 me-1"></i>How Geofencing Works
                </div>
                <div class="card-body py-2 small">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <div class="d-flex gap-2">
                                <span class="badge bg-primary rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width:24px;height:24px">1</span>
                                <div>
                                    <strong>Draw a boundary</strong><br>
                                    Use the drawing tools below to draw a polygon or circle on the map.
                                    This creates a "map markup" &mdash; a saved shape that defines the boundary.
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="d-flex gap-2">
                                <span class="badge bg-primary rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width:24px;height:24px">2</span>
                                <div>
                                    <strong>Create a geofence</strong><br>
                                    Select your markup from the dropdown, give it a name, choose
                                    enter/exit alerts and notification channels, then click Create.
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="d-flex gap-2">
                                <span class="badge bg-primary rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width:24px;height:24px">3</span>
                                <div>
                                    <strong>Alerts fire automatically</strong><br>
                                    When a tracked unit (via GPS, APRS, OwnTracks, etc.) crosses the boundary,
                                    an alert is sent to dispatchers via your chosen channels.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Step 1: Draw a boundary (inline map) -->
            <div class="settings-group mb-3">
                <div class="settings-group-title">
                    <span class="badge bg-primary rounded-circle me-1" style="width:20px;height:20px;font-size:0.7rem;display:inline-flex;align-items:center;justify-content:center">1</span>
                    Draw a Boundary
                </div>
                <p class="text-body-secondary small mb-2">
                    Click a drawing tool, then click on the map to draw your shape. Double-click to finish a polygon.
                </p>

                <!-- Drawing toolbar -->
                <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
                    <div class="btn-group btn-group-sm" id="gfDrawToolbar">
                        <button class="btn btn-outline-primary" id="gfDrawPolygon" title="Draw polygon boundary">
                            <i class="bi bi-pentagon me-1"></i>Polygon
                        </button>
                        <button class="btn btn-outline-primary" id="gfDrawCircle" title="Draw circle boundary">
                            <i class="bi bi-circle me-1"></i>Circle
                        </button>
                    </div>
                    <button class="btn btn-sm btn-success d-none" id="gfDrawFinish" title="Finish polygon">
                        <i class="bi bi-check-lg me-1"></i>Finish Polygon
                    </button>
                    <button class="btn btn-sm btn-outline-secondary d-none" id="gfDrawCancel" title="Cancel drawing">
                        <i class="bi bi-x-lg me-1"></i>Cancel
                    </button>
                    <span class="small text-body-secondary" id="gfDrawStatus"></span>
                </div>

                <!-- Inline map for drawing — resizable; drag bottom-right corner. -->
                <div class="border rounded position-relative" id="gfDrawMapWrap"
                     style="resize: both; overflow: hidden; width: 100%; max-width: 100%; height: 400px; min-height: 240px; min-width: 320px;">
                    <div id="gfDrawMap" style="width:100%; height:100%; border-radius:5px;"></div>
                </div>
                <div class="small text-body-secondary mt-1"><i class="bi bi-info-circle me-1"></i>Drag the bottom-right corner of the map to resize.</div>

                <!-- Name the markup after drawing -->
                <div class="mt-2 d-none" id="gfMarkupNameRow">
                    <div class="row g-2 align-items-end">
                        <div class="col-md-5">
                            <label class="form-label form-label-sm">Name this boundary</label>
                            <input type="text" class="form-control form-control-sm" id="gfMarkupName" placeholder="e.g. Downtown Zone, MSP Airport Perimeter">
                        </div>
                        <div class="col-md-3">
                            <button class="btn btn-sm btn-success" id="btnSaveMarkup">
                                <i class="bi bi-check-lg me-1"></i>Save Boundary
                            </button>
                        </div>
                        <div class="col-md-4">
                            <span class="small" id="gfSaveStatus"></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Step 2: Create geofence from markup -->
            <div class="settings-group mb-3">
                <div class="settings-group-title">
                    <span class="badge bg-primary rounded-circle me-1" style="width:20px;height:20px;font-size:0.7rem;display:inline-flex;align-items:center;justify-content:center">2</span>
                    Create Geofence
                </div>
                <div class="row g-2">
                    <div class="col-md-5">
                        <label class="form-label form-label-sm">Saved Boundary (Map Markup)</label>
                        <select class="form-select form-select-sm" id="gfMarkupSelect">
                            <option value="">-- select a markup --</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label form-label-sm">Geofence Name</label>
                        <input type="text" class="form-control form-control-sm" id="gfName" placeholder="Defaults to markup name">
                    </div>
                    <div class="col-md-3 d-flex align-items-end">
                        <button class="btn btn-sm btn-primary w-100" id="btnCreateGeofence">
                            <i class="bi bi-plus-lg me-1"></i>Create Geofence
                        </button>
                    </div>
                </div>
                <div class="row g-2 mt-1">
                    <div class="col-md-3">
                        <div class="form-check form-check-sm">
                            <input class="form-check-input" type="checkbox" id="gfAlertEnter" checked>
                            <label class="form-check-label small" for="gfAlertEnter">Alert on enter</label>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-check form-check-sm">
                            <input class="form-check-input" type="checkbox" id="gfAlertExit" checked>
                            <label class="form-check-label small" for="gfAlertExit">Alert on exit</label>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label form-label-sm">Alert Channels</label>
                        <select class="form-select form-select-sm" id="gfChannels" multiple size="4">
                            <option value="local_chat" selected>Local Chat</option>
                            <option value="smtp">Email (SMTP)</option>
                            <option value="sms">SMS</option>
                            <option value="slack">Slack</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Step 3: Active geofences list -->
            <div class="settings-group mb-3">
                <div class="settings-group-title">
                    <span class="badge bg-primary rounded-circle me-1" style="width:20px;height:20px;font-size:0.7rem;display:inline-flex;align-items:center;justify-content:center">3</span>
                    Active Geofences
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0" id="geofenceTable">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Markup</th>
                                <th>Type</th>
                                <th class="text-center">Enter</th>
                                <th class="text-center">Exit</th>
                                <th class="text-center">Units Inside</th>
                                <th class="text-center">Active</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="geofenceBody">
                            <tr><td colspan="8" class="text-body-secondary small text-center">No geofences defined. Draw a boundary and create one above.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ────────────────────────────────────────────────────────────── -->
        <!-- Languages registry (Phase 8b) -->
        <!-- ────────────────────────────────────────────────────────────── -->
        <div class="config-panel" id="panel-languages">
            <div class="config-panel-title">
                <i class="bi bi-translate text-primary"></i> <?php echo e(t('sidebar.tab.languages', 'Languages')); ?>
            </div>

            <div class="alert alert-info py-2 small mb-3" role="alert">
                <i class="bi bi-info-circle me-1"></i>
                Manage which languages are <strong>enabled</strong> on this install, set the <strong>install default</strong>
                (used for new sessions whose browser doesn't request a language we have), and see how much of each language
                is translated. Disabled languages don't appear in the user-facing switcher.
                Use the <strong><?php echo e(t('sidebar.tab.translations', 'Translations')); ?></strong> tab to fill in the actual string values.
            </div>

            <!-- Add language form -->
            <form id="langAddForm" class="row g-2 align-items-end mb-3 p-2 border rounded bg-body-tertiary">
                <div class="col-md-2">
                    <label class="form-label form-label-sm mb-0">Code</label>
                    <input type="text" class="form-control form-control-sm" id="langAddCode" placeholder="e.g. fr, pt-br" maxlength="8" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label form-label-sm mb-0">Display name</label>
                    <input type="text" class="form-control form-control-sm" id="langAddDisplay" placeholder="e.g. French" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label form-label-sm mb-0">Native name</label>
                    <input type="text" class="form-control form-control-sm" id="langAddNative" placeholder="e.g. Français">
                </div>
                <div class="col-md-2">
                    <label class="form-label form-label-sm mb-0">Sort order</label>
                    <input type="number" class="form-control form-control-sm" id="langAddSort" value="100" min="0" max="999">
                </div>
                <div class="col-md-2 text-end">
                    <button type="submit" class="btn btn-sm btn-success w-100">
                        <i class="bi bi-plus-lg me-1"></i>Add
                    </button>
                </div>
            </form>

            <!-- Languages table -->
            <div class="table-responsive border rounded">
                <table class="table table-sm table-hover table-striped mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Code</th>
                            <th>Display Name</th>
                            <th>Native Name</th>
                            <th class="text-center">Enabled</th>
                            <th class="text-center">Install Default</th>
                            <th>Completeness</th>
                            <th class="text-center">Sort</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="langTableBody">
                        <tr><td colspan="99" class="text-center text-body-secondary py-3">
                            <i class="bi bi-hourglass-split me-1"></i>Loading…
                        </td></tr>
                    </tbody>
                </table>
            </div>

            <div class="mt-3 small text-body-secondary">
                <i class="bi bi-lightbulb me-1"></i>
                English (<code>en</code>) cannot be deleted — it's the engine's hard fallback when a translation is missing.
                You can rename or hide it from the switcher, but never remove it.
            </div>
        </div>

        <!-- ────────────────────────────────────────────────────────────── -->
        <!-- Translations / i18n admin (Phase 8) -->
        <!-- ────────────────────────────────────────────────────────────── -->
        <div class="config-panel" id="panel-translations">
            <div class="config-panel-title">
                <i class="bi bi-translate text-primary"></i> <?php echo e(t('sidebar.tab.translations', 'Translations')); ?>
                <div class="ms-auto d-flex gap-2">
                    <button class="btn btn-sm btn-outline-primary config-tab-link" data-tab="languages" title="Manage installed languages">
                        <i class="bi bi-translate me-1"></i><?php echo e(t('sidebar.tab.languages', 'Languages')); ?>
                    </button>
                    <button class="btn btn-sm btn-outline-secondary" id="btnTrExport" title="Download all captions as JSON">
                        <i class="bi bi-download me-1"></i>Export
                    </button>
                    <label class="btn btn-sm btn-outline-secondary mb-0" for="trImportFile" title="Upload captions JSON">
                        <i class="bi bi-upload me-1"></i>Import
                    </label>
                    <input type="file" id="trImportFile" accept=".json,application/json" hidden>
                </div>
            </div>

            <div class="alert alert-info py-2 small mb-3" role="alert">
                <i class="bi bi-info-circle me-1"></i>
                Edit caption translations here. Click any value cell to edit; press <kbd>Enter</kbd> or click away to save,
                <kbd>Esc</kbd> to cancel. Use <strong>Add Language</strong> to enable a new target language for translation;
                use <strong>Export</strong>/<strong>Import</strong> to share JSON files with translators.
            </div>

            <!-- Filters / search bar -->
            <div class="row g-2 align-items-end mb-2">
                <div class="col-md-4">
                    <label class="form-label form-label-sm mb-0">Search</label>
                    <input type="text" class="form-control form-control-sm" id="trSearch" placeholder="Key or value…">
                </div>
                <div class="col-md-3">
                    <label class="form-label form-label-sm mb-0">Category</label>
                    <select class="form-select form-select-sm" id="trCategoryFilter">
                        <option value="">All</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label form-label-sm mb-0">Show</label>
                    <select class="form-select form-select-sm" id="trShowFilter">
                        <option value="all">All captions</option>
                        <option value="untranslated">Untranslated (any lang)</option>
                    </select>
                </div>
                <div class="col-md-2 text-end">
                    <small class="text-body-secondary" id="trCount">— captions</small>
                </div>
            </div>

            <!-- Captions table. trTableHead is populated by translations-admin.js
                 with one <th> per active language. The placeholder <th> below
                 keeps the table semantically valid before JS runs. -->
            <div class="table-responsive border rounded" style="max-height:60vh;overflow-y:auto">
                <table class="table table-sm table-hover table-striped mb-0 align-middle" id="trTable">
                    <caption class="visually-hidden">Translatable captions with one column per active language</caption>
                    <thead class="table-light sticky-top">
                        <tr id="trTableHead">
                            <th scope="col">Caption key</th>
                            <!-- columns populated by translations-admin.js -->
                        </tr>
                    </thead>
                    <tbody id="trTableBody">
                        <tr><td colspan="99" class="text-center text-body-secondary py-3">
                            <i class="bi bi-hourglass-split me-1"></i>Loading…
                        </td></tr>
                    </tbody>
                </table>
            </div>

            <div class="mt-3">
                <button class="btn btn-sm btn-success" id="btnTrAddCaption">
                    <i class="bi bi-plus-lg me-1"></i>Add Caption
                </button>
                <small class="text-body-secondary ms-2">
                    See <a href="docs/I18N-GUIDE.md" target="_blank">I18N-GUIDE.md</a> for retrofit conventions and the full key namespace.
                </small>
            </div>
        </div>

    </main>

</div>

<!-- CSRF token for JS -->
<input type="hidden" id="csrfToken" value="<?php echo e($csrf); ?>">
<!-- User level for JS permission checks -->
<input type="hidden" id="userLevel" value="<?php echo $userLevel; ?>">
<!-- Phase 10c (2026-06-11): current admin's user_id, exposed so the
     User Accounts edit form can detect "editing self" vs. "editing
     another user" and conditionally require the reason field. -->
<script>window.__currentUserId = <?php echo (int) ($_SESSION['user_id'] ?? 0); ?>;</script>

<!-- Vendor JS -->
<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="assets/js/toolbar.js?v=<?php echo asset_v('assets/js/toolbar.js'); ?>"></script>
<script src="assets/vendor/leaflet/leaflet.js"></script>
<script src="assets/js/leaflet-mobile-fit.js?v=<?php echo function_exists("asset_v")?asset_v("assets/js/leaflet-mobile-fit.js"):NEWUI_VERSION; ?>"></script>
<script src="assets/js/leaflet-quadkey.js"></script>
<script src="assets/js/map-prefs.js"></script>

<!-- App JS -->
<script src="assets/js/event-bus.js?v=<?php echo asset_v('assets/js/event-bus.js'); ?>"></script>
<script src="assets/js/theme-manager.js?v=<?php echo asset_v('assets/js/theme-manager.js'); ?>"></script>
<script src="assets/js/audio-alerts.js?v=<?php echo asset_v('assets/js/audio-alerts.js'); ?>"></script>
<script src="assets/js/searchable-select.js?v=<?php echo asset_v('assets/js/searchable-select.js'); ?>"></script>
<script src="assets/js/states-select.js?v=<?php echo asset_v('assets/js/states-select.js'); ?>"></script>
<script src="assets/js/type-icons.js?v=<?php echo asset_v('assets/js/type-icons.js'); ?>"></script>
<script src="assets/js/config.js?v=<?php echo asset_v('assets/js/config.js'); ?>"></script>
<script src="assets/js/roles-audit.js?v=<?php echo asset_v('assets/js/roles-audit.js'); ?>"></script>
<script src="assets/js/translations-admin.js?v=<?php echo asset_v('assets/js/translations-admin.js'); ?>"></script>
<script src="assets/js/languages-admin.js?v=<?php echo asset_v('assets/js/languages-admin.js'); ?>"></script>
<!-- Phase 73k — DVSwitch DMR admin panel -->
<script src="assets/js/dvswitch-admin.js?v=<?php echo asset_v('assets/js/dvswitch-admin.js'); ?>"></script>

<!-- 2FA Settings Panel JS -->
<script>
(function () {
    'use strict';

    var csrf = document.getElementById('csrfToken') ? document.getElementById('csrfToken').value : '';

    function loadTfaSettings() {
        fetch('api/tfa.php?action=settings', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.error) return;

                var el = document.getElementById('tfaGlobalEnabled');
                if (el) el.checked = !!data.tfa_enabled;

                var days = document.getElementById('tfaRememberDays');
                if (days) days.value = data.tfa_remember_days || 30;

                var roles = data.tfa_required_roles || [];
                var checks = document.querySelectorAll('.tfa-role-check');
                for (var i = 0; i < checks.length; i++) {
                    checks[i].checked = roles.indexOf(parseInt(checks[i].value, 10)) !== -1;
                }

                var cidrs = document.getElementById('tfaTrustedCidrs');
                if (cidrs && Array.isArray(data.tfa_trusted_cidrs)) {
                    cidrs.value = data.tfa_trusted_cidrs.join('\n');
                }
            })
            .catch(function () {});
    }

    var saveBtn = document.getElementById('btnSaveTfaSettings');
    if (saveBtn) {
        saveBtn.addEventListener('click', function () {
            var roles = [];
            var checks = document.querySelectorAll('.tfa-role-check:checked');
            for (var i = 0; i < checks.length; i++) {
                roles.push(parseInt(checks[i].value, 10));
            }

            var cidrsRaw = (document.getElementById('tfaTrustedCidrs').value || '').split('\n');
            var cidrs = [];
            for (var j = 0; j < cidrsRaw.length; j++) {
                var c = cidrsRaw[j].trim();
                if (c !== '') cidrs.push(c);
            }

            var body = {
                csrf_token: csrf,
                action: 'save_settings',
                tfa_enabled: document.getElementById('tfaGlobalEnabled').checked ? 1 : 0,
                tfa_remember_days: parseInt(document.getElementById('tfaRememberDays').value, 10) || 30,
                tfa_required_roles: roles,
                tfa_trusted_cidrs: cidrs
            };

            fetch('api/tfa.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body)
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var ok = document.getElementById('tfaSaveOk');
                if (data.success && ok) {
                    ok.classList.remove('d-none');
                    setTimeout(function () { ok.classList.add('d-none'); }, 2000);
                } else if (data.error) {
                    alert('Error: ' + data.error);
                }
            })
            .catch(function (err) {
                alert('Save failed: ' + err.message);
            });
        });
    }

    var forceBtn = document.getElementById('btnForceDisableTfa');
    if (forceBtn) {
        forceBtn.addEventListener('click', function () {
            var userId = parseInt(document.getElementById('tfaForceUserId').value, 10);
            if (!userId || userId <= 0) {
                alert('Please enter a valid user ID.');
                return;
            }
            if (!confirm('Disable 2FA for user ID ' + userId + '? This cannot be undone.')) {
                return;
            }

            fetch('api/tfa.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    csrf_token: csrf,
                    action: 'admin_disable',
                    user_id: userId
                })
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var status = document.getElementById('tfaForceStatus');
                if (data.success) {
                    if (status) status.textContent = '2FA disabled for user ' + userId;
                    if (status) status.className = 'text-success small';
                } else {
                    if (status) status.textContent = data.error || 'Failed';
                    if (status) status.className = 'text-danger small';
                }
            })
            .catch(function (err) {
                var status = document.getElementById('tfaForceStatus');
                if (status) status.textContent = err.message;
                if (status) status.className = 'text-danger small';
            });
        });
    }

    var panel = document.getElementById('panel-two-factor-auth');
    if (panel) {
        var observer = new MutationObserver(function (mutations) {
            for (var i = 0; i < mutations.length; i++) {
                if (panel.classList.contains('active')) {
                    loadTfaSettings();
                    return;
                }
            }
        });
        observer.observe(panel, { attributes: true, attributeFilter: ['class'] });
    }
    // ── TFA Key Management ──
    function loadTfaKeyStatus() {
        fetch('api/tfa-key.php', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.error) return;

                var srcEl = document.getElementById('tfaKeySource');
                var fileEl = document.getElementById('tfaKeyFile');
                var enrollEl = document.getElementById('tfaKeyEnrollments');
                var migrateBtn = document.getElementById('btnTfaMigrateKey');
                var statusEl = document.getElementById('tfaKeyStatus');

                if (srcEl) {
                    if (data.dedicated_exists) {
                        srcEl.className = 'badge bg-success';
                        srcEl.textContent = 'Dedicated File (Recommended)';
                    } else {
                        srcEl.className = 'badge bg-warning text-dark';
                        srcEl.textContent = 'DB Password Derived (Legacy)';
                    }
                }

                if (fileEl) fileEl.textContent = data.key_file || '--';
                if (enrollEl) enrollEl.textContent = data.enrollments + ' active enrollment(s)';

                // Warning box + migrate button + status text
                var warnBox = document.getElementById('tfaKeyWarningBox');

                if (migrateBtn) {
                    if (data.dedicated_exists) {
                        migrateBtn.classList.add('d-none');
                        if (statusEl) {
                            statusEl.textContent = 'TFA key is independent of the database password.';
                            statusEl.className = 'text-success small align-self-center';
                        }
                        if (warnBox) {
                            warnBox.innerHTML =
                                '<div class="alert alert-success py-2 mt-2 mb-0 small">' +
                                '<i class="bi bi-check-circle-fill me-1"></i>' +
                                '<strong>Dedicated key active.</strong> ' +
                                'TFA secrets are encrypted with an independent key file. ' +
                                'Changing the database password will <strong>not</strong> affect 2FA enrollments. ' +
                                'Back up <code>../keys/tfa.key</code> securely &mdash; losing it means all enrollments must be reset.' +
                                '</div>';
                        }
                    } else {
                        migrateBtn.classList.remove('d-none');
                        if (statusEl) {
                            statusEl.textContent = 'Changing the DB password will break all 2FA enrollments.';
                            statusEl.className = 'text-warning small align-self-center';
                        }
                        if (warnBox) {
                            warnBox.innerHTML =
                                '<div class="alert alert-warning py-2 mt-2 mb-0 small">' +
                                '<i class="bi bi-exclamation-triangle-fill me-1"></i>' +
                                '<strong>Legacy key source.</strong> ' +
                                'TFA secrets are encrypted with a key derived from the database password. ' +
                                'Changing the DB password in config.php will invalidate all 2FA enrollments. ' +
                                'Click <strong>Migrate to Dedicated Key</strong> above to prevent this.' +
                                '</div>';
                        }
                    }
                }
            })
            .catch(function () {});
    }

    // Migrate button handler
    var migrateBtn = document.getElementById('btnTfaMigrateKey');
    if (migrateBtn) {
        migrateBtn.addEventListener('click', function () {
            if (!confirm('Migrate TFA encryption to a dedicated key file?\n\nThis will:\n1. Generate a new random encryption key\n2. Re-encrypt all existing TOTP secrets\n3. Store the key in ../keys/tfa.key\n\nAfter migration, changing the DB password will NOT affect 2FA.')) {
                return;
            }
            var csrf2 = document.querySelector('meta[name="csrf-token"]');
            csrf2 = csrf2 ? csrf2.content : '<?php echo e($csrf); ?>';

            migrateBtn.disabled = true;
            migrateBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Migrating...';

            fetch('api/tfa-key.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({ action: 'migrate', csrf_token: csrf2 })
            }).then(function (r) { return r.json(); })
              .then(function (data) {
                  migrateBtn.disabled = false;
                  if (data.error) {
                      migrateBtn.innerHTML = '<i class="bi bi-arrow-left-right me-1"></i>Migrate to Dedicated Key';
                      alert('Migration failed: ' + data.error);
                      return;
                  }
                  migrateBtn.innerHTML = '<i class="bi bi-check-lg me-1"></i>Migrated!';
                  var statusEl2 = document.getElementById('tfaKeyStatus');
                  if (statusEl2) {
                      statusEl2.textContent = data.message || 'Migration complete.';
                      statusEl2.className = 'text-success small align-self-center';
                  }
                  loadTfaKeyStatus();
              })
              .catch(function (err) {
                  migrateBtn.disabled = false;
                  migrateBtn.innerHTML = '<i class="bi bi-arrow-left-right me-1"></i>Migrate to Dedicated Key';
                  alert('Migration error: ' + err.message);
              });
        });
    }

    // Load key status when 2FA panel becomes active
    var origLoadTfa = loadTfaSettings;
    loadTfaSettings = function () {
        origLoadTfa();
        loadTfaKeyStatus();
    };
})();
</script>

<!-- ═══════════ Wastebasket Panel JS ═══════════ -->
<script>
(function () {
    'use strict';

    var csrf = document.querySelector('meta[name="csrf-token"]');
    csrf = csrf ? csrf.content : '<?php echo e($csrf); ?>';

    var wbTableBody = document.getElementById('wbTableBody');
    var wbTypeFilter = document.getElementById('wbTypeFilter');
    var wbRefreshBtn = document.getElementById('wbRefreshBtn');
    var wbEmptyBtn = document.getElementById('wbEmptyBtn');
    var wbPurgeDays = document.getElementById('wbPurgeDays');
    var wbStatus = document.getElementById('wbStatus');
    var wbTotalBadge = document.getElementById('wbTotalBadge');
    var wbSidebarBadge = document.getElementById('wbSidebarBadge');

    function escHtml(s) {
        if (!s) return '';
        var d = document.createElement('div');
        d.appendChild(document.createTextNode(s));
        return d.innerHTML;
    }

    function formatDate(dt) {
        if (!dt) return '--';
        var d = new Date(dt.replace(' ', 'T'));
        if (isNaN(d.getTime())) return dt;
        return d.toLocaleDateString() + ' ' + d.toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'});
    }

    function loadWastebasket() {
        var typeVal = wbTypeFilter ? wbTypeFilter.value : '';
        var url = 'api/wastebasket.php' + (typeVal ? '?type=' + encodeURIComponent(typeVal) : '');
        if (wbStatus) wbStatus.textContent = 'Loading...';

        fetch(url, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.error) {
                    if (wbStatus) wbStatus.textContent = 'Error: ' + data.error;
                    return;
                }

                var items = data.items || [];
                var count = data.count || 0;

                if (wbTotalBadge) wbTotalBadge.textContent = count;
                if (wbSidebarBadge) wbSidebarBadge.textContent = count > 0 ? count : '';

                if (!items.length) {
                    wbTableBody.innerHTML = '<tr><td colspan="6" class="text-center text-body-secondary py-3">'
                        + '<i class="bi bi-check-circle me-1"></i>Wastebasket is empty</td></tr>';
                    if (wbStatus) wbStatus.textContent = 'No deleted items';
                    return;
                }

                var html = '';
                for (var i = 0; i < items.length; i++) {
                    var item = items[i];
                    html += '<tr data-type="' + escHtml(item.type) + '" data-id="' + item.id + '">'
                        + '<td><i class="bi ' + escHtml(item.type_icon) + '"></i></td>'
                        + '<td>' + escHtml(item.type_label) + '</td>'
                        + '<td>' + escHtml(item.label) + '</td>'
                        + '<td class="small">' + escHtml(item.deleted_by) + '</td>'
                        + '<td class="small">' + formatDate(item.deleted_at) + '</td>'
                        + '<td>'
                        + '<button class="btn btn-sm btn-outline-success me-1 wb-restore-btn" title="Restore">'
                        + '<i class="bi bi-arrow-counterclockwise"></i></button>'
                        + '<button class="btn btn-sm btn-outline-danger wb-purge-btn" title="Permanently Delete">'
                        + '<i class="bi bi-x-lg"></i></button>'
                        + '</td></tr>';
                }
                wbTableBody.innerHTML = html;

                // Bind restore buttons
                var restoreBtns = wbTableBody.querySelectorAll('.wb-restore-btn');
                for (var j = 0; j < restoreBtns.length; j++) {
                    restoreBtns[j].addEventListener('click', function () {
                        var row = this.closest('tr');
                        wbAction('restore', row.getAttribute('data-type'), row.getAttribute('data-id'));
                    });
                }

                // Bind purge buttons
                var purgeBtns = wbTableBody.querySelectorAll('.wb-purge-btn');
                for (var k = 0; k < purgeBtns.length; k++) {
                    purgeBtns[k].addEventListener('click', function () {
                        var row = this.closest('tr');
                        if (!confirm('Permanently delete this record? This cannot be undone.')) return;
                        wbAction('purge', row.getAttribute('data-type'), row.getAttribute('data-id'));
                    });
                }

                if (wbStatus) wbStatus.textContent = count + ' deleted item' + (count !== 1 ? 's' : '');
            })
            .catch(function (err) {
                if (wbStatus) wbStatus.textContent = 'Error: ' + err.message;
            });
    }

    function wbAction(action, type, id) {
        if (wbStatus) wbStatus.textContent = action === 'restore' ? 'Restoring...' : 'Deleting...';

        fetch('api/wastebasket.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: action,
                type: type,
                id: parseInt(id, 10),
                csrf_token: csrf
            })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.error) {
                if (wbStatus) wbStatus.textContent = 'Error: ' + data.error;
            } else {
                if (wbStatus) wbStatus.textContent = data.message || 'Done';
                loadWastebasket();
            }
        })
        .catch(function (err) {
            if (wbStatus) wbStatus.textContent = 'Error: ' + err.message;
        });
    }

    // Empty wastebasket
    if (wbEmptyBtn) {
        wbEmptyBtn.addEventListener('click', function () {
            var days = parseInt(wbPurgeDays.value, 10) || 30;
            if (!confirm('Permanently delete ALL records older than ' + days + ' days? This cannot be undone.')) return;
            if (wbStatus) wbStatus.textContent = 'Emptying...';

            fetch('api/wastebasket.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'empty',
                    days: days,
                    csrf_token: csrf
                })
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.error) {
                    if (wbStatus) wbStatus.textContent = 'Error: ' + data.error;
                } else {
                    if (wbStatus) wbStatus.textContent = data.message || 'Done';
                    loadWastebasket();
                }
            })
            .catch(function (err) {
                if (wbStatus) wbStatus.textContent = 'Error: ' + err.message;
            });
        });
    }

    // Filter change
    if (wbTypeFilter) {
        wbTypeFilter.addEventListener('change', function () {
            loadWastebasket();
        });
    }

    // Refresh button
    if (wbRefreshBtn) {
        wbRefreshBtn.addEventListener('click', function () {
            loadWastebasket();
        });
    }

    // Load on panel activation
    var wbPanel = document.getElementById('panel-wastebasket');
    if (wbPanel) {
        var wbObserver = new MutationObserver(function (mutations) {
            for (var i = 0; i < mutations.length; i++) {
                if (wbPanel.classList.contains('active')) {
                    loadWastebasket();
                    return;
                }
            }
        });
        wbObserver.observe(wbPanel, { attributes: true, attributeFilter: ['class'] });
    }

    // Load badge count on page load
    fetch('api/wastebasket.php?count=1', { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            var count = data.count || 0;
            if (wbSidebarBadge) wbSidebarBadge.textContent = count > 0 ? count : '';
        })
        .catch(function () {});
})();
</script>

</body>
</html>
