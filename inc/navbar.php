<?php
/**
 * NewUI v4.0 - Shared Navigation Bar
 *
 * Include from any page after setting:
 *   $active_page  - which menu item to highlight (e.g. 'situation', 'units', 'personnel')
 *   $bs_theme     - 'dark' or 'light'
 *   $theme        - 'Day' or 'Night'
 *   $user         - display name
 *   $level        - level text
 *
 * Usage:
 *   $active_page = 'situation';
 *   include NEWUI_ROOT . '/inc/navbar.php';
 *
 * i18n: every visible label runs through t('key', 'English default') so
 * users with a non-English $_SESSION['lang'] see translated text. See
 * specs/phase-08-i18n-2026-06/ and docs/I18N-GUIDE.md for details.
 */

require_once __DIR__ . '/i18n.php';

// Defensive defaults: pages are supposed to set $user/$level before including
// (see the header comment). Default them from the session so a page that
// forgets (e.g. time-approvals.php) doesn't emit an "undefined variable"
// warning — which prints before the doctype and breaks the navbar styling.
// $user is echoed raw below (pre-escape it); $level is echoed via e() (keep raw).
if (!isset($user))  { $user  = isset($_SESSION['user']) ? e($_SESSION['user']) : ''; }
if (!isset($level)) { $level = function_exists('current_role_name') ? current_role_name() : ''; }

// Map of page → active key (for pages that don't set $active_page themselves)
if (!isset($active_page)) {
    $script = basename($_SERVER['SCRIPT_NAME'], '.php');
    $page_map = [
        'index'           => 'situation',
        'incident-detail' => 'situation',
        'new-incident'    => 'new',
        'units'           => 'units',
        'unit-detail'     => 'units',
        'unit-edit'       => 'units',
        'facilities'      => 'facilities',
        'facility-detail' => 'facilities',
        'facility-edit'   => 'facilities',
        'roster'          => 'personnel',
        'teams'           => 'personnel',
        'scheduling'      => 'personnel',
        'time-entries'    => 'personnel',
        'time-approvals'  => 'personnel',
        'vehicles'        => 'personnel',
        'equipment'       => 'personnel',
        'roles'           => 'personnel',
        'search'          => 'search',
        'situation'       => 'situation',
        'callboard'       => 'callboard',
        'major-incidents' => 'major-incidents',
        'net-control'     => 'net-control',
        'zone-coverage'   => 'zone-coverage',
        'facility-board'  => 'facility-board',
        'links'           => 'links',
        'incident-list'   => 'situation',
        'reports'         => 'reports',
        'settings'        => 'config',
        'status'          => 'config',
        'sop'             => 'sop',
        'constituents'    => 'contacts',
        'import-export'   => 'config',
        'ics-forms'       => 'ics-forms',
        'messaging'       => 'messaging',
        'dmr-archive'    => 'dmr-archive',
        'radio-ai'        => 'radio-ai',
        'profile'         => 'profile',
        'help'            => 'help',
        'quick-start'     => 'help',
        'diagnostics'     => 'help',
        'about'           => 'about',
    ];
    $active_page = $page_map[$script] ?? '';
}

/**
 * Render a nav menu button.
 */
if (!function_exists('nav_btn')) {
function nav_btn($href, $icon, $label, $key, $active_page, $attrs = '') {
    $cls = 'nav-menu-btn' . ($key === $active_page ? ' active' : '');
    echo '<a href="' . $href . '" class="' . $cls . '" title="' . htmlspecialchars($label) . '"' . ($attrs ? ' ' . $attrs : '') . '>';
    echo '<i class="bi bi-' . $icon . '"></i><span>' . $label . '</span>';
    echo '</a>';
}
}
?>
<!-- Phase 44 (a11y): skip-to-main-content link. Visible only when focused,
     lets keyboard / screen-reader users bypass the entire navbar with one Tab.
     Pages that wrap their primary content in <main id="main-content"> get the
     full benefit; pages without it still let the link jump to "#main-content"
     which gracefully no-ops. -->
<a href="#main-content" class="visually-hidden-focusable position-absolute top-0 start-0 m-2 p-2 bg-primary text-white text-decoration-none rounded" style="z-index:9999;">
    Skip to main content
</a>
<!-- Top Navigation Bar -->
<header class="sticky-top" id="appHeader">
    <!-- Row 1: Brand + Main Menu + User Controls -->
    <nav class="navbar navbar-expand-xl border-bottom nav-main" data-bs-theme="<?php echo $bs_theme; ?>" role="navigation" aria-label="Main navigation">
        <div class="container-fluid">
            <span class="navbar-brand d-flex align-items-center gap-2">
                <img src="assets/logo-light.png" alt="Tickets" height="36" class="d-block">
                <span class="fw-semibold">Tickets</span>
                <small class="text-body-secondary d-none d-sm-inline">v<?php echo NEWUI_VERSION; ?></small>
            </span>

            <!-- 2026-06-14 (Phase 47): hamburger toggler for <xl viewports.
                 NewUI's navbar has 13+ menu items plus 10 right-side
                 controls — without an expand-* + collapse-target pair,
                 every item rendered horizontally and overflowed the
                 viewport on phones (Eric flagged it while provisioning
                 OwnTracks on his Android). navbar-expand-xl picks 1200px
                 as the breakpoint because the desktop layout already
                 wraps awkwardly below that. -->
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse"
                    data-bs-target="#mainNavCollapse" aria-controls="mainNavCollapse"
                    aria-expanded="false" aria-label="<?php echo e(t('nav.toggle.menu', 'Toggle navigation menu')); ?>">
                <span class="navbar-toggler-icon"></span>
            </button>

            <div class="collapse navbar-collapse" id="mainNavCollapse">

            <!-- Main Menu -->
            <div class="d-flex align-items-center gap-1 me-auto ms-xl-3 flex-wrap nav-main-items" id="mainMenu">
                <?php nav_btn('index.php',         'display',      t('nav.menu.situation', 'Situation'), 'situation',  $active_page); ?>
                <?php nav_btn('new-incident.php',  'plus-circle',  t('nav.menu.new',       'New'),       'new',        $active_page); ?>
                <?php nav_btn('units.php',         'people',       t('nav.menu.units',     'Units'),     'units',      $active_page); ?>
                <?php nav_btn('facilities.php',    'hospital',     t('nav.menu.facs',      "Fac's"),     'facilities', $active_page); ?>
                <?php /* Eric 2026-07-07 (#67 icon overload): the Major button
                       moved into the Incidents widget headers on the dashboard
                       and situation screens — it is incident context, not a
                       top-level destination. */ ?>
                <?php // Phase 109 — Event Net-Control board (RBAC-gated so non-authorized users don't see a 403 button).
                      if (is_admin() || (function_exists('rbac_can') && rbac_can('screen.net_control'))) {
                          nav_btn('net-control.php', 'broadcast-pin', t('nav.menu.net_control', 'Net Control'), 'net-control', $active_page);
                      } ?>
                <?php // Phase 115 (#64) — Zone Coverage board. Granted to ALL roles incl. Field Unit,
                      // so it's the one zone screen a roaming volunteer can open.
                      if (is_admin() || (function_exists('rbac_can') && rbac_can('screen.zone_coverage'))) {
                          nav_btn('zone-coverage.php', 'diagram-3', t('nav.menu.zone_coverage', 'Zone Coverage'), 'zone-coverage', $active_page);
                      } ?>
                <?php nav_btn('search.php',        'search',       t('nav.menu.search',    'Search'),    'search',     $active_page); ?>

                <!-- Personnel Dropdown -->
                <div class="dropdown">
                    <a href="#" class="nav-menu-btn dropdown-toggle <?php echo $active_page === 'personnel' ? 'active' : ''; ?>"
                       data-bs-toggle="dropdown" aria-expanded="false" title="<?php echo e(t('nav.menu.personnel', 'Personnel')); ?>">
                        <i class="bi bi-person-badge"></i><span><?php echo e(t('nav.menu.personnel', 'Personnel')); ?></span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-dark">
                        <li><a class="dropdown-item" href="roster.php"><i class="bi bi-person-lines-fill me-2"></i><?php echo e(t('nav.menu.roster',     'Roster')); ?></a></li>
                        <li><a class="dropdown-item" href="teams.php"><i class="bi bi-people-fill me-2"></i><?php           echo e(t('nav.menu.teams',      'Teams')); ?></a></li>
                        <li><a class="dropdown-item" href="scheduling.php"><i class="bi bi-calendar-week me-2"></i><?php    echo e(t('nav.menu.scheduling', 'Scheduling')); ?></a></li>
                        <li><a class="dropdown-item" href="time-entries.php"><i class="bi bi-clock me-2"></i><?php          echo e(t('nav.menu.my_time',    'My Time')); ?></a></li>
                        <?php
                        if (function_exists('rbac_can') && rbac_can('time_entry.approve')):
                        ?>
                        <li><a class="dropdown-item" href="time-approvals.php"><i class="bi bi-clock-history me-2"></i><?php echo e(t('nav.menu.time_approvals', 'Pending Time Approvals')); ?></a></li>
                        <?php endif; ?>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="vehicles.php"><i class="bi bi-truck me-2"></i><?php   echo e(t('nav.menu.vehicles',  'Vehicles')); ?></a></li>
                        <li><a class="dropdown-item" href="equipment.php"><i class="bi bi-box-seam me-2"></i><?php echo e(t('nav.menu.equipment', 'Equipment')); ?></a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="settings.php#certifications"><i class="bi bi-patch-check me-2"></i><?php  echo e(t('nav.menu.certifications',   'Certifications')); ?></a></li>
                        <li><a class="dropdown-item" href="settings.php#ics-positions"><i class="bi bi-shield-check me-2"></i><?php  echo e(t('nav.menu.ics_positions',    'ICS Positions')); ?></a></li>
                        <li><a class="dropdown-item" href="settings.php#training"><i class="bi bi-mortarboard me-2"></i><?php        echo e(t('nav.menu.training',         'Training')); ?></a></li>
                        <li><a class="dropdown-item" href="settings.php#member-types"><i class="bi bi-tags me-2"></i><?php           echo e(t('nav.menu.member_types',     'Member Types')); ?></a></li>
                        <li><a class="dropdown-item" href="settings.php#member-statuses"><i class="bi bi-toggle-on me-2"></i><?php   echo e(t('nav.menu.member_statuses',  'Member Statuses')); ?></a></li>
                        <?php
                        if (function_exists('rbac_can') && rbac_can('action.manage_roles')):
                        ?>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="roles.php"><i class="bi bi-shield-lock me-2"></i><?php echo e(t('nav.menu.roles_permissions', 'Roles & Permissions')); ?></a></li>
                        <?php endif; ?>
                    </ul>
                </div>

                <?php nav_btn('reports.php',      'bar-chart-line',     t('nav.menu.reports',  'Reports'),  'reports',  $active_page); ?>
                <?php nav_btn('settings.php',     'gear',               t('nav.menu.config',   'Config'),   'config',   $active_page); ?>
                <?php nav_btn('sop.php',          'journal-text',       t('nav.menu.sop',      'SOP'),      'sop',      $active_page); ?>
                <?php nav_btn('ics-forms.php',    'file-earmark-text',  t('nav.menu.ics',      'ICS'),      'ics-forms',$active_page); ?>
                <?php nav_btn('constituents.php', 'person-lines-fill',  t('nav.menu.contacts', 'Contacts'), 'contacts', $active_page); ?>
                <?php nav_btn('messaging.php',    'envelope',           t('nav.menu.messages', 'Messages'), 'messaging',$active_page); ?>

                <!-- Communications Dropdown (Eric 2026-07-07, #67 icon
                     overload: Console / Radio Archive / Radio AI / APRS Map
                     stack under one icon) -->
                <div class="dropdown">
                    <a href="#" class="nav-menu-btn dropdown-toggle <?php echo in_array($active_page, ['console', 'dmr-archive', 'radio-ai', 'aprs-map'], true) ? 'active' : ''; ?>"
                       data-bs-toggle="dropdown" aria-expanded="false" title="<?php echo e(t('nav.menu.comms', 'Communications')); ?>">
                        <i class="bi bi-broadcast-pin"></i><span><?php echo e(t('nav.menu.comms', 'Comms')); ?></span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-dark">
                        <?php if (is_admin() || (function_exists('rbac_can') && rbac_can('screen.console'))): ?>
                        <li><a class="dropdown-item" href="console.php"><i class="bi bi-broadcast-pin me-2"></i><?php echo e(t('nav.menu.console', 'Communications Console')); ?></a></li>
                        <li><hr class="dropdown-divider"></li>
                        <?php endif; ?>
                        <li><a class="dropdown-item" href="dmr-archive.php"><i class="bi bi-broadcast me-2"></i><?php echo e(t('nav.menu.dmr_archive', 'Radio Archive')); ?></a></li>
                        <li><a class="dropdown-item" href="radio-ai.php"><i class="bi bi-robot me-2"></i><?php echo e(t('nav.menu.radio_ai', 'Radio AI')); ?></a></li>
                        <li><a class="dropdown-item" href="aprs-map.php"><i class="bi bi-geo-alt me-2"></i><?php echo e(t('nav.menu.aprs_map', 'APRS Map')); ?></a></li>
                    </ul>
                </div>
                <!-- Links Dropdown (top 5 bookmarks + View All) -->
                <div class="dropdown">
                    <a href="links.php" class="nav-menu-btn dropdown-toggle <?php echo $active_page === 'links' ? 'active' : ''; ?>"
                       data-bs-toggle="dropdown" aria-expanded="false" title="<?php echo e(t('nav.menu.links', 'Links')); ?>">
                        <i class="bi bi-link-45deg"></i><span><?php echo e(t('nav.menu.links', 'Links')); ?></span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-dark" id="navLinksDropdown">
                        <li class="text-center py-2"><small class="text-body-secondary">Loading...</small></li>
                    </ul>
                </div>

                <!-- Display boards (in main menu, after primary items, open in new windows) -->
                <span class="border-start ms-1 me-1" style="height:20px;opacity:0.3"></span>
                <?php /* 2026-06-11 — Renamed from "Full Screen" to
                       "EOC Display" so the purpose is obvious. Opens
                       situation.php in a chromeless popup window
                       intended for wall-mount monitors / public
                       displays. The label is i18n-keyed; the EN
                       caption defaults to "EOC Display". */ ?>
                <!-- Dashboards Dropdown (Eric 2026-07-07, #67 icon overload:
                     the three wall-display popups stack under one icon with
                     clearer names) -->
                <div class="dropdown">
                    <a href="#" class="nav-menu-btn dropdown-toggle"
                       data-bs-toggle="dropdown" aria-expanded="false" title="<?php echo e(t('nav.menu.dashboards', 'Dashboards')); ?>">
                        <i class="bi bi-tv"></i><span><?php echo e(t('nav.menu.dashboards', 'Dashboards')); ?></span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-dark">
                        <li><a class="dropdown-item" href="#"
                               onclick="window.open('situation.php','TicketsCAD_Situation','width='+screen.width+',height='+screen.height+',menubar=no,toolbar=no,location=no');return false;">
                            <i class="bi bi-tv me-2"></i><?php echo e(t('nav.menu.eoc_display', 'Situation Overview (EOC)')); ?></a></li>
                        <li><a class="dropdown-item" href="#"
                               onclick="window.open('callboard.php','TicketsCAD_Board','width='+screen.width+',height='+screen.height+',menubar=no,toolbar=no,location=no');return false;">
                            <i class="bi bi-grid-3x3-gap me-2"></i><?php echo e(t('nav.menu.board', 'Dispatched Call Board')); ?></a></li>
                        <li><a class="dropdown-item" href="#"
                               onclick="window.open('facility-board.php','TicketsCAD_FacBoard','width='+screen.width+',height='+screen.height+',menubar=no,toolbar=no,location=no');return false;">
                            <i class="bi bi-hospital me-2"></i><?php echo e(t('nav.menu.fac_board', 'Facilities Status Board')); ?></a></li>
                    </ul>
                </div>
            </div>

            <!-- Right side controls -->
            <div class="d-flex align-items-center gap-3 flex-wrap nav-right-controls">
                <!-- SSE Connection Indicator -->
                <span id="sseIndicator" class="text-body-secondary" title="Real-time updates: connecting..." style="font-size:0.65rem;cursor:help">
                    <i class="bi bi-circle-fill" style="font-size:0.5rem;color:var(--bs-secondary)"></i>
                </span>

                <!-- 24-Hour Clock -->
                <span class="toolbar-clock font-monospace" id="toolbarClock">00:00:00</span>

                <!-- Help -->
                <a href="help.php" class="btn btn-sm btn-outline-secondary" title="Help" aria-label="Help" id="navHelpBtn">
                    <i class="bi bi-question-circle"></i>
                </a>

                <!-- Messages (unread badge) -->
                <a href="messaging.php" class="btn btn-sm btn-outline-secondary position-relative" title="Messages" aria-label="Messages" id="navMsgBtn">
                    <i class="bi bi-envelope"></i>
                    <span class="badge bg-danger rounded-pill nav-msg-badge d-none position-absolute top-0 start-100 translate-middle" id="navMsgBadge">0</span>
                </a>

                <!-- HAS Broadcast (Dispatcher+ only) -->
<?php if ((int)($_SESSION['level'] ?? 99) <= 2): ?>
                <button type="button" class="btn btn-sm has-broadcast-btn" id="navHasBtn"
                        title="HAS Broadcast — All Stations" aria-label="HAS Broadcast"
                        data-bs-toggle="modal" data-bs-target="#hasBroadcastModal">
                    <i class="bi bi-megaphone-fill"></i>
                </button>
<?php endif; ?>

                <!-- Audio Mute Toggle -->
                <button type="button" class="btn btn-sm btn-outline-secondary" id="audioMuteBtn" title="Mute alerts" aria-label="Mute alerts">
                    <i class="bi bi-volume-up"></i>
                </button>

                <!-- Radio Widget Toggle — moved out of the dashboard's
                     Communications panel so the widget is reachable from
                     every page (Eric's 2026-06-16 request). The widget's
                     own JS binds the data-action delegator. -->
                <?php if (is_admin() || (function_exists('rbac_can') && rbac_can('action.dmr_receive'))): ?>
                <button type="button" class="btn btn-sm btn-outline-secondary" data-action="radio" title="Radio (DMR)" aria-label="Open radio widget">
                    <i class="bi bi-broadcast"></i>
                </button>
                <?php endif; ?>

                <!-- Notification Tray -->
                <div class="dropdown" id="notificationTray">
                    <button type="button" class="btn btn-sm btn-outline-secondary position-relative" id="navNotifyBtn"
                            data-bs-toggle="dropdown" data-bs-auto-close="outside"
                            title="Recent notifications" aria-label="Notifications">
                        <i class="bi bi-bell"></i>
                        <span class="badge bg-danger rounded-pill nav-notify-badge d-none position-absolute top-0 start-100 translate-middle" id="navNotifyBadge">0</span>
                    </button>
                    <div class="dropdown-menu dropdown-menu-end shadow" id="navNotifyDropdown"
                         style="width:360px;max-height:420px;overflow-y:auto;padding:0">
                        <div class="d-flex align-items-center justify-content-between px-3 py-2 border-bottom bg-body-tertiary">
                            <span class="fw-semibold small"><?php echo e(t('nav.title.notifications', 'Notifications')); ?></span>
                            <button class="btn btn-sm btn-link text-body-secondary p-0" id="btnClearNotifications" title="<?php echo e(t('btn.close', 'Clear')); ?>">
                                <i class="bi bi-trash3 me-1"></i><span class="small"><?php echo e(t('btn.close', 'Clear')); ?></span>
                            </button>
                        </div>
                        <div id="notifyList">
                            <div class="text-center text-body-secondary py-4 small">
                                <i class="bi bi-bell-slash d-block mb-1" style="font-size:1.5rem;opacity:0.3"></i>
                                <?php echo e(t('nav.title.no_notifications', 'No recent notifications')); ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Theme Toggle -->
                <div class="btn-group btn-group-sm" role="group" id="themeToggle">
                    <button type="button" class="btn <?php echo $theme === 'Day' ? 'btn-warning' : 'btn-outline-secondary'; ?>"
                            data-theme="Day" title="Day Mode" aria-label="Switch to day mode">
                        <i class="bi bi-sun-fill"></i>
                    </button>
                    <button type="button" class="btn <?php echo $theme === 'Night' ? 'btn-primary' : 'btn-outline-secondary'; ?>"
                            data-theme="Night" title="Night Mode" aria-label="Switch to night mode">
                        <i class="bi bi-moon-fill"></i>
                    </button>
                </div>

                <!-- Org Switcher (only shown when user belongs to 2+ orgs) -->
<?php if (!empty($_SESSION['user_orgs']) && count($_SESSION['user_orgs']) > 1): ?>
                <div class="dropdown" id="orgSwitcherWrap">
                    <button class="btn btn-sm btn-outline-info dropdown-toggle py-0 px-2" type="button"
                            data-bs-toggle="dropdown" aria-expanded="false" id="orgSwitcherBtn"
                            title="Switch Organization">
                        <i class="bi bi-building me-1"></i><?php
                        $activeShort = 'Org';
                        foreach ($_SESSION['user_orgs'] as $uorg) {
                            if ((int)$uorg['org_id'] === (int)($_SESSION['active_org_id'] ?? 0)) {
                                $activeShort = $uorg['short_name'] ?: $uorg['org_name'];
                                break;
                            }
                        }
                        echo e($activeShort);
                    ?></button>
                    <ul class="dropdown-menu dropdown-menu-end dropdown-menu-dark">
<?php foreach ($_SESSION['user_orgs'] as $uorg): ?>
                        <li><a class="dropdown-item org-switch-link<?php echo ((int)$uorg['org_id'] === (int)($_SESSION['active_org_id'] ?? 0)) ? ' active' : ''; ?>"
                               href="#" data-org-id="<?php echo (int)$uorg['org_id']; ?>">
                            <i class="bi bi-building me-2"></i><?php echo e($uorg['org_name']); ?>
                        </a></li>
<?php endforeach; ?>
                    </ul>
                </div>
<?php endif; ?>

                <!-- Language Switcher (only shown when ≥2 languages are configured in captions_i18n) -->
<?php
$_navbar_langs = i18n_available_langs();
$_navbar_curr  = i18n_lang();
if (count($_navbar_langs) >= 2):
?>
                <div class="dropdown" id="languageSwitcher" data-current-lang="<?php echo e($_navbar_curr); ?>">
                    <button class="btn btn-sm btn-outline-secondary dropdown-toggle py-0 px-2" type="button"
                            data-bs-toggle="dropdown" aria-expanded="false" id="languageSwitcherBtn"
                            title="<?php echo e(t('nav.title.language', 'Language')); ?>"
                            aria-label="<?php echo e(t('nav.title.language', 'Language')); ?>">
                        <i class="bi bi-translate me-1"></i><span id="languageSwitcherCurrent"><?php echo e(strtoupper($_navbar_curr)); ?></span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end dropdown-menu-dark" id="languageSwitcherMenu">
                        <!-- populated by language-switcher.js -->
                    </ul>
                </div>
<?php endif; ?>

                <!-- User Menu Dropdown -->
                <div class="dropdown">
                    <a href="#" class="d-flex align-items-center gap-1 text-body-secondary text-decoration-none dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false" role="button">
                        <i class="bi bi-person-circle"></i>
                        <span id="currentUser"><?php echo $user; ?></span>
                        <small class="text-body-tertiary">(<?php echo e($level); ?>)</small>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <!-- Phase 56 — personal-resource clock-in toggle. Lazy-loads
                             status the first time the dropdown opens (delegated load
                             in navbar JS at bottom of file). Visible to any member,
                             one click to flip. -->
                        <li id="navPuItem" class="d-none">
                            <button type="button" class="dropdown-item d-flex align-items-center" id="navPuToggle">
                                <i class="bi bi-person-fill-check me-2" id="navPuIcon"></i>
                                <span id="navPuLabel">Clock in as resource</span>
                                <span class="badge bg-secondary ms-auto" id="navPuBadge">…</span>
                            </button>
                        </li>
                        <li id="navPuDivider" class="d-none"><hr class="dropdown-divider"></li>

                        <li><a class="dropdown-item" href="profile.php"><i class="bi bi-person me-2"></i><?php           echo e(t('nav.user.profile',         'My Profile')); ?></a></li>
                        <li><a class="dropdown-item" href="profile.php#password"><i class="bi bi-key me-2"></i><?php     echo e(t('nav.user.change_password', 'Change Password')); ?></a></li>
                        <li><a class="dropdown-item" href="profile.php#security"><i class="bi bi-shield-lock me-2"></i><?php echo e(t('nav.user.tfa',         'Two-Factor Auth')); ?></a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="mobile.php"><i class="bi bi-phone me-2"></i><?php             echo e(t('nav.user.mobile',          'Mobile Unit View')); ?></a></li>
                        <li><a class="dropdown-item" href="quick-start.php"><i class="bi bi-rocket-takeoff me-2"></i><?php echo e(t('nav.user.quick_start',   'Quick Start')); ?></a></li>
                        <li><a class="dropdown-item" href="help.php"><i class="bi bi-question-circle me-2"></i><?php     echo e(t('nav.user.help',            'Help')); ?></a></li>
                        <li><a class="dropdown-item" href="diagnostics.php"><i class="bi bi-heart-pulse me-2"></i><?php echo e(t('nav.user.diagnostics',     'Diagnostics')); ?></a></li>
                        <li><a class="dropdown-item" href="about.php"><i class="bi bi-info-circle me-2"></i><?php        echo e(t('nav.user.about',           'About')); ?></a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger" href="login.php?logout=1"><i class="bi bi-box-arrow-right me-2"></i><?php echo e(t('nav.user.logout', 'Log Out')); ?></a></li>
                    </ul>
                </div>
            </div>

            </div><!-- /#mainNavCollapse (Phase 47) -->
        </div>
    </nav>

    <!-- Phase 69 (2026-06-14) — Mobile-view link. Replaces the easily
         -dismissed floating pill (Phase 68) with a full-width, NON-
         dismissable bar that sticks to the top of every non-mobile
         page on viewports ≤768px. Reasoning: a responder fighting a
         desktop UI on a 5" screen shouldn't be able to bury the only
         escape hatch. Hidden via CSS on viewports >768px so it never
         distracts on a normal monitor. -->
    <?php $_curScript = basename($_SERVER['SCRIPT_NAME'] ?? '', '.php'); ?>
    <?php if ($_curScript !== 'mobile' && $_curScript !== 'login'): ?>
    <style>
        #mobileViewBar { display: none; }
        @media (max-width: 768px) {
            #mobileViewBar {
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 6px;
                width: 100%;
                /* Phase 70 — slimmer bar so supervisors using the full
                   UI on a phone aren't fighting it for vertical space.
                   Still tall enough to be obvious + tap-safe. */
                padding: 4px 12px;
                background: var(--bs-primary);
                color: #fff;
                text-decoration: none;
                font-weight: 600;
                font-size: 0.8rem;
                line-height: 1.3;
                box-shadow: 0 1px 3px rgba(0,0,0,0.2);
                position: sticky;
                top: 0;
                z-index: 1091;
            }
            #mobileViewBar:hover, #mobileViewBar:active { color: #fff; opacity: 0.92; }
        }
    </style>
    <a id="mobileViewBar" href="mobile.php"
       title="<?php echo e(t('mobile.cta.title', 'Switch to the mobile-optimised view')); ?>">
        <i class="bi bi-phone" aria-hidden="true"></i>
        <span><?php echo e(t('mobile.cta.label', 'Switch to Mobile View')); ?></span>
        <i class="bi bi-arrow-right" aria-hidden="true"></i>
    </a>
    <?php endif; ?>
</header><!-- /#appHeader. Phase 102 (a beta tester GH #4) — header was opened
     at line 98 and never closed, so HTML5 parsing left every page's body
     content nested inside a position:sticky z-index:1030 stacking-context
     root. Bootstrap modals inside that context had their z-index:1055
     scoped to header (1030), while the .modal-backdrop appended to body
     root sat at z-index:1050 — winning elementFromPoint at every point
     over the modal ("underwater"): visible, unclickable, no way to
     close short of a hard refresh. Closing the header here puts the
     rest of the page (and any modal, dropdown, offcanvas, etc.) back at
     the body-root stacking context where their z-indices behave. -->

    <!-- EventBus (SSE real-time) + Audio Alerts — loaded on every page via navbar -->
    <script>
    (function(){
        function loadGlobal(src, id) {
            if (document.getElementById(id)) return; // already loaded by the page
            var existing = document.querySelector('script[src*="' + src.split('/').pop().split('?')[0] + '"]');
            if (existing) return; // page already included it
            var s = document.createElement('script');
            s.src = src;
            s.id = id;
            s.defer = true;
            document.body.appendChild(s);
        }
        function updateSseIndicator(state) {
            var ind = document.getElementById('sseIndicator');
            if (!ind) return;
            var colors = { connected: 'var(--bs-success)', disconnected: 'var(--bs-warning)', offline: 'var(--bs-secondary)' };
            var titles = { connected: 'Real-time updates: connected', disconnected: 'Real-time updates: reconnecting...', offline: 'Real-time updates: polling (SSE unavailable)' };
            ind.title = titles[state] || '';
            ind.innerHTML = '<i class="bi bi-circle-fill" style="font-size:0.5rem;color:' + (colors[state] || colors.offline) + '"></i>';
        }

        function boot() {
            // Phase 44 — a11y shim: makes every Bootstrap-collapse trigger
            // (data-bs-toggle) keyboard-focusable and Enter/Space-actionable
            // sitewide. One script, fixes ~62 Sonar findings at once.
            loadGlobal('assets/js/a11y.js?v=<?php echo NEWUI_VERSION; ?>', '_navbar_a11y');
            loadGlobal('assets/js/event-bus.js?v=<?php echo NEWUI_VERSION; ?>', '_navbar_eventbus');
            // GH #82/#76 — type-icon map markers + name labels. type-icons.js
            // defines window.TypeIcons.markerDivIcon (used by every map's marker
            // code); the CSS styles the badge + label. loadGlobal's dedupe means
            // settings.php's own type-icons.js include is not loaded twice.
            loadGlobal('assets/js/type-icons.js?v=<?php echo NEWUI_VERSION; ?>', '_navbar_typeicons');
            if (!document.querySelector('link[href*="type-markers.css"]')) {
                var _tmcss = document.createElement('link');
                _tmcss.rel = 'stylesheet';
                _tmcss.href = 'assets/css/type-markers.css?v=<?php echo NEWUI_VERSION; ?>';
                document.head.appendChild(_tmcss);
            }
            // Audio alerts needs EventBus, so load after a brief delay
            setTimeout(function() {
                loadGlobal('assets/js/audio-alerts.js?v=<?php echo NEWUI_VERSION; ?>', '_navbar_audio');
            }, 300);
            // Phase 29B (2026-06-12) — PAR-overdue check fires through
            // the existing internal-messaging broadcast pattern (see
            // par_broadcast_overdue() in inc/par.php). Hitting the
            // overdue endpoint triggers the dedup'd broadcast which
            // reaches dispatchers via the notification tray + bell
            // badge + audio + SSE that already exist on every page.
            // We poke the endpoint once on page load + every 60s so
            // broadcasts fire even if the scheduler cron isn't running.
            // The previous Phase 28A custom-banner approach was retired
            // — Eric's call: "don't we already have a pattern for this,
            // relating to messaging?"
            setTimeout(function() {
                function pokeOverdue() {
                    fetch('api/par.php?action=overdue', { credentials: 'same-origin' })
                        .catch(function () { /* swallow */ });
                }
                pokeOverdue();
                setInterval(pokeOverdue, 60000);
            }, 1000);

            // Wire up SSE indicator once EventBus is available
            var checkBus = setInterval(function() {
                if (typeof EventBus !== 'undefined') {
                    clearInterval(checkBus);
                    EventBus.on('sse:connected', function() { updateSseIndicator('connected'); });
                    EventBus.on('sse:disconnected', function() { updateSseIndicator('disconnected'); });
                    EventBus.on('sse:offline', function() { updateSseIndicator('offline'); });
                    // If already connected, update now
                    if (EventBus.isSSEConnected && EventBus.isSSEConnected()) {
                        updateSseIndicator('connected');
                    }
                }
            }, 200);
        }
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', boot);
        } else {
            setTimeout(boot, 50);
        }
    })();
    </script>

    <!-- Phase 56 — personal-resource clock-in toggle in the user dropdown -->
    <script>
    (function () {
        'use strict';
        var item = document.getElementById('navPuItem');
        var divider = document.getElementById('navPuDivider');
        var btn = document.getElementById('navPuToggle');
        var icon = document.getElementById('navPuIcon');
        var label = document.getElementById('navPuLabel');
        var badge = document.getElementById('navPuBadge');
        if (!btn || !badge) return;
        var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
        var loaded = false;
        var pending = false;

        function paint(status) {
            if (!status) return;
            if (status.error) {
                badge.textContent = 'err'; badge.className = 'badge bg-danger ms-auto';
                return;
            }
            // Phase 57 — RBAC gate. If the user's role doesn't include
            // action.self_clock_in, hide the toggle entirely so they
            // don't see a button they'll just get a 403 from.
            if (status.can_self_clock === false) {
                item.classList.add('d-none');
                divider.classList.add('d-none');
                return;
            }
            item.classList.remove('d-none');
            divider.classList.remove('d-none');
            if (status.clocked_in) {
                badge.textContent = 'CLOCKED IN';
                badge.className = 'badge bg-success ms-auto';
                icon.className = 'bi bi-box-arrow-right me-2';
                label.textContent = 'Clock out';
            } else {
                badge.textContent = status.exists ? 'off' : 'new';
                badge.className = 'badge bg-secondary ms-auto';
                icon.className = 'bi bi-box-arrow-in-right me-2';
                label.textContent = 'Clock in as resource';
            }
        }

        function loadStatus() {
            if (loaded) return;
            loaded = true;
            fetch('api/personal-unit.php?action=status', { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    // Hide silently if the user isn't linked to a member (404-ish).
                    if (d.error && /No member linked/i.test(d.error)) return;
                    paint(d);
                })
                .catch(function () { /* silent */ });
        }

        // Load lazily — wait for the dropdown to open so anonymous-pre-auth
        // pages and dashboards with no clock-in interest pay nothing.
        var userDropdown = btn.closest('.dropdown');
        if (userDropdown) {
            userDropdown.addEventListener('shown.bs.dropdown', loadStatus);
        }

        btn.addEventListener('click', function (ev) {
            ev.preventDefault();
            if (pending) return;
            pending = true;
            var current = badge.textContent === 'CLOCKED IN';
            var action = current ? 'clock_out' : 'clock_in';
            badge.textContent = '…';
            fetch('api/personal-unit.php?action=' + action, {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ csrf_token: csrf })
            })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                pending = false;
                if (d.error) { paint({ error: d.error }); return; }
                paint(d.status || d);
            })
            .catch(function () { pending = false; paint({ error: 'failed' }); });
        });
    })();
    </script>
    <!-- Messaging badge updater — deferred to load after page scripts -->
    <script>
    (function(){
        function loadMsgBadge() {
            if (document.getElementById('navMsgBadge')) {
                var s = document.createElement('script');
                s.src = 'assets/js/messaging-badge.js?v=<?php echo NEWUI_VERSION; ?>';
                s.defer = true;
                document.body.appendChild(s);
            }
        }
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', loadMsgBadge);
        } else {
            setTimeout(loadMsgBadge, 100);
        }
    })();
    </script>
    <!-- Notification tray — captures SSE events for visual history -->
    <script>
    (function(){
        function loadNotifyTray() {
            if (document.getElementById('notifyList')) {
                var s = document.createElement('script');
                s.src = 'assets/js/notification-tray.js?v=<?php echo NEWUI_VERSION; ?>';
                s.defer = true;
                document.body.appendChild(s);
            }
        }
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', loadNotifyTray);
        } else {
            setTimeout(loadNotifyTray, 200);
        }
    })();
    </script>
    <!-- Compact navbar: individual item peek with configurable fade delay -->
    <script>
    (function() {
        'use strict';
        var PREFS_KEY = 'ticketsNavbarPrefs';
        var stored = {};
        try { stored = JSON.parse(localStorage.getItem(PREFS_KEY)) || {}; } catch (e) { stored = {}; }
        var fadeMs = (stored.fadeDelay !== undefined) ? parseInt(stored.fadeDelay, 10) : 10000;
        window.NavbarPrefs = {
            getFadeDelay: function() { return fadeMs; },
            setFadeDelay: function(ms) {
                fadeMs = parseInt(ms, 10) || 10000;
                stored.fadeDelay = fadeMs;
                try { localStorage.setItem(PREFS_KEY, JSON.stringify(stored)); } catch (e) { /* ignore */ }
            }
        };
        var allBtns = document.querySelectorAll('#mainMenu .nav-menu-btn');
        var idx;
        for (idx = 0; idx < allBtns.length; idx++) {
            (function(btn) {
                var timer = null;
                btn.addEventListener('mouseenter', function() {
                    clearTimeout(timer);
                    btn.classList.add('peek');
                });
                btn.addEventListener('mouseleave', function() {
                    clearTimeout(timer);
                    var currentDelay = window.NavbarPrefs ? window.NavbarPrefs.getFadeDelay() : 10000;
                    if (currentDelay === 0) { return; }
                    timer = setTimeout(function() { btn.classList.remove('peek'); }, currentDelay);
                });
            })(allBtns[idx]);
        }
    })();
    </script>

    <!-- Toolbar clock (runs on every page via navbar) -->
    <script>
    (function() {
        'use strict';
        var clockEl = document.getElementById('toolbarClock');
        if (clockEl) {
            function tick() {
                var now = new Date();
                var h = now.getHours(), m = now.getMinutes(), s = now.getSeconds();
                clockEl.textContent = (h<10?'0':'') + h + ':' + (m<10?'0':'') + m + ':' + (s<10?'0':'') + s;
            }
            tick();
            setInterval(tick, 1000);
        }
    })();
    </script>

<!-- Links dropdown loader -->
<script>
(function() {
    'use strict';
    var dd = document.getElementById('navLinksDropdown');
    if (!dd) return;
    var loaded = false;

    // Load when dropdown is first shown
    dd.closest('.dropdown').addEventListener('show.bs.dropdown', function() {
        if (loaded) return;
        loaded = true;
        fetch('api/links.php', { credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                var links = data.links || [];
                var html = '';
                var shown = 0;
                for (var i = 0; i < links.length && shown < 5; i++) {
                    if (parseInt(links[i].active, 10) !== 1) continue;
                    var icon = links[i].icon || 'bi-link-45deg';
                    var title = links[i].title || '';
                    var url = links[i].url || '#';
                    html += '<li><a class="dropdown-item" href="' + url.replace(/"/g, '&quot;') + '" target="_blank" rel="noopener">';
                    html += '<i class="bi ' + icon.replace(/"/g, '') + ' me-2"></i>';
                    html += title.replace(/</g, '&lt;').replace(/>/g, '&gt;');
                    html += '</a></li>';
                    shown++;
                }
                if (shown === 0) {
                    html = '<li class="text-center py-2"><small class="text-body-secondary">No links configured</small></li>';
                }
                html += '<li><hr class="dropdown-divider"></li>';
                html += '<li><a class="dropdown-item text-primary" href="links.php"><i class="bi bi-box-arrow-up-right me-2"></i>View All &rarr;</a></li>';
                dd.innerHTML = html;
            })
            .catch(function() {
                dd.innerHTML = '<li class="text-center py-2"><small class="text-danger">Failed to load links</small></li>'
                    + '<li><hr class="dropdown-divider"></li>'
                    + '<li><a class="dropdown-item text-primary" href="links.php"><i class="bi bi-box-arrow-up-right me-2"></i>View All &rarr;</a></li>';
            });
    });
})();
</script>

<?php
// Phase 10 (2026-06-08): rotation-reminder banner.
// Renders if $_SESSION['rotation_reminder_age'] is set (login.php seeds
// it via pw_needs_rotation()). The banner spans the full viewport width,
// sits directly below the navbar, and offers two actions:
//   • "Change Now" → /profile.php#password (the password tab)
//   • "Remind Me Later" → POST /api/profile.php snooze_password_reminder
// The session flag is cleared on snooze response so the banner disappears
// without a full page reload.
if (!empty($_SESSION['rotation_reminder_age'])):
    $rotAge = (int) $_SESSION['rotation_reminder_age'];
?>
<div class="alert alert-warning d-flex align-items-center justify-content-between mb-0 rounded-0 py-2 px-3" role="alert" id="pwRotationBanner">
    <div class="small">
        <i class="bi bi-shield-exclamation me-2"></i>
        <strong><?php echo e(t('pw_rotation.banner_title', 'Password rotation suggested.')); ?></strong>
        <?php echo e(sprintf(
            t('pw_rotation.banner_body', "You haven't changed your password in %d days."),
            $rotAge
        )); ?>
    </div>
    <div class="d-flex align-items-center gap-2">
        <a href="profile.php#password" class="btn btn-sm btn-warning">
            <i class="bi bi-key me-1"></i><?php echo e(t('pw_rotation.btn_change_now', 'Change Now')); ?>
        </a>
        <button type="button" class="btn btn-sm btn-outline-secondary" id="btnSnoozePwReminder" data-csrf="<?php echo e($_SESSION['csrf_token'] ?? ''); ?>">
            <i class="bi bi-clock-history me-1"></i><?php echo e(t('pw_rotation.btn_snooze', 'Remind Me Later')); ?>
        </button>
    </div>
</div>
<script>
(function () {
    'use strict';
    var btn = document.getElementById('btnSnoozePwReminder');
    if (!btn) return;
    btn.addEventListener('click', function () {
        var token = btn.getAttribute('data-csrf') || '';
        btn.disabled = true;
        fetch('api/profile.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token },
            body: JSON.stringify({ action: 'snooze_password_reminder', csrf_token: token })
        })
        .then(function (r) { return r.json().then(function (j) { return { status: r.status, body: j }; }); })
        .then(function (res) {
            if (res.status === 200 && res.body && res.body.success) {
                // Hide the banner; no full reload needed.
                var b = document.getElementById('pwRotationBanner');
                if (b) b.classList.add('d-none');
            } else {
                btn.disabled = false;
                if (typeof window.alert === 'function') {
                    window.alert((res.body && res.body.error) || 'Snooze failed');
                }
            }
        })
        .catch(function () {
            btn.disabled = false;
        });
    });
})();
</script>
<?php endif; ?>

<?php
// Phase 13 (2026-06-11): pending-database-migrations banner.
//   For admins only. Fetches /api/migrations-check.php once per page
//   load; if pending > 0 OR tracking_table is missing, shows a yellow
//   banner pointing the admin at sql/run_migrations.php. The banner is
//   dismissable per session (sessionStorage flag) to avoid nagging
//   while the admin is mid-fix.
//
//   This prevents the "Location Providers panel is empty" surprise
//   that hit Eric on 2026-06-11 — code was deployed but the seed
//   migration was never invoked. The banner makes the gap visible.
?>
<div class="alert alert-warning d-flex align-items-center justify-content-between mb-0 rounded-0 py-2 px-3 d-none" role="alert" id="migrationsPendingBanner">
    <div class="small">
        <i class="bi bi-database-exclamation me-2"></i>
        <strong>Database migrations pending.</strong>
        <span id="migrationsPendingCount"></span>
        Apply via SSH: <code class="text-body-secondary">php sql/run_migrations.php</code>
    </div>
    <div class="d-flex align-items-center gap-2">
        <a href="migrations.php" class="btn btn-sm btn-outline-warning">
            <i class="bi bi-list-check me-1"></i>Status &amp; preview
        </a>
        <a href="docs/INSTALL.md" target="_blank" class="btn btn-sm btn-outline-warning">
            <i class="bi bi-book me-1"></i>Docs
        </a>
        <button type="button" class="btn btn-sm btn-outline-secondary" id="btnDismissMigrationsBanner" title="Hide for this session">
            <i class="bi bi-x"></i>
        </button>
    </div>
</div>
<script>
(function () {
    'use strict';
    // Only admins see this — the API returns 403 to non-admins, in which
    // case we silently no-op. Skip entirely if the admin has dismissed
    // it in this browser session.
    if (sessionStorage.getItem('migrationsBannerDismissed') === '1') return;
    fetch('api/migrations-check.php', { credentials: 'same-origin' })
        .then(function (r) { return r.status === 200 ? r.json() : null; })
        .then(function (data) {
            if (!data) return;
            var showBanner = (data.pending > 0) || !data.tracking_table || data.failed > 0;
            if (!showBanner) return;
            var b = document.getElementById('migrationsPendingBanner');
            if (!b) return;
            var msg = '';
            if (!data.tracking_table) {
                msg = 'No migrations have been recorded — this looks like a fresh install.';
            } else if (data.failed > 0) {
                msg = data.failed + ' previous migration(s) FAILED. Re-run after fixing.';
            } else {
                msg = data.pending + ' migration script(s) on disk are not yet applied.';
            }
            var span = document.getElementById('migrationsPendingCount');
            if (span) span.textContent = ' ' + msg + ' ';
            b.classList.remove('d-none');
        })
        .catch(function () { /* silent */ });

    var dismiss = document.getElementById('btnDismissMigrationsBanner');
    if (dismiss) dismiss.addEventListener('click', function () {
        sessionStorage.setItem('migrationsBannerDismissed', '1');
        var b = document.getElementById('migrationsPendingBanner');
        if (b) b.classList.add('d-none');
    });
})();
</script>
<?php
// GH #41 (2026-07-04): installation-health banner (admin-only, detect &
// warn). Sits directly below the migrations banner — same self-contained
// pattern: hidden div + one fetch/page load + sessionStorage dismiss.
// Shows only when api/health-check.php reports critical file-permission
// or stale-opcache problems; non-admins get a 403 and it no-ops silently.
include_once __DIR__ . '/health-banner.php';
?>

<?php
// GH #55 (Eric 2026-07-04) — shared PTT button color for the Zello + DMR
// Radio consoles. Config color picker writes the `ptt_button_color`
// setting; both widgets read `--ptt-color`. Sanitize to a hex color so a
// bad/hostile value can never break out of the <style> — anything that
// isn't #rgb / #rrggbb / #rrggbbaa falls back to the default red.
$__pttColor = function_exists('get_setting') ? (string) get_setting('ptt_button_color', '#dc3545') : '#dc3545';
if (!preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $__pttColor)) {
    $__pttColor = '#dc3545';
}
?>
<style>:root { --ptt-color: <?php echo $__pttColor; ?>; }</style>

<!-- Radio Widget — moved from index.php so it's reachable from every
     page (Eric's 2026-06-16 request: widget should survive page
     navigation). The JS handles permission-gating on toggle. -->
<link rel="stylesheet" href="assets/css/radio-widget.css?v=<?php echo file_exists(__DIR__ . '/../assets/css/radio-widget.css') ? filemtime(__DIR__ . '/../assets/css/radio-widget.css') : NEWUI_VERSION; ?>">
<template id="tpl-radio-widget">
    <div class="radio-widget radio-hidden">
        <div class="radio-header">
            <span class="radio-status-badge status-disconnected" title="Stream status"></span>
            <i class="bi bi-broadcast radio-header-icon"></i>
            <span class="radio-header-title">DMR Radio</span>
            <span class="radio-header-channel">TG 3127</span>
            <div class="radio-header-actions">
                <!-- Phase 101 (Eric beta 2026-07-01) — header toolbar in
                     the Responders-widget style. Archive + Audio-Mute
                     alongside Minimize + Close. Audio-mute is separate
                     from the existing pause/rewind (that gates the
                     ring buffer for DVR); this gates final playback. -->
                <a href="dmr-archive.php" target="_blank" rel="noopener"
                   class="btn btn-sm btn-outline-secondary" id="radioArchive"
                   title="Open archive in a new tab" aria-label="Open DMR archive">
                    <i class="bi bi-clock-history"></i>
                </a>
                <!-- GH #55 (Eric 2026-07-04) — Live monitor, mirroring the
                     Zello widget. ON = channel audio plays even while the
                     widget is minimized / you're on another page. Mute (next)
                     silences everything and overrides it. -->
                <button class="btn btn-sm btn-outline-secondary" id="radioLiveBtn"
                        title="Live monitor" aria-label="Live monitor" aria-pressed="false">
                    <i class="bi bi-broadcast"></i>
                </button>
                <button class="btn btn-sm btn-outline-secondary" id="radioAudioMuteBtn"
                        title="Mute incoming audio" aria-label="Mute incoming audio"
                        aria-pressed="false">
                    <i class="bi bi-volume-up-fill"></i>
                </button>
                <button class="btn btn-sm btn-outline-secondary" id="radioMinimize" title="Minimize" aria-label="Minimize Radio">
                    <i class="bi bi-dash"></i>
                </button>
                <button class="btn btn-sm btn-outline-secondary" id="radioClose" title="Close" aria-label="Close Radio">
                    <i class="bi bi-x"></i>
                </button>
            </div>
        </div>
        <div class="radio-feed" id="radioFeed">
            <div class="radio-feed-empty">
                <span><i class="bi bi-broadcast d-block mb-2" style="font-size:1.5rem"></i>Listening for TG 3127…<br>Activity will appear here.</span>
            </div>
        </div>
        <!-- GH #55 (Eric 2026-07-04) — transport (instant-recall / play-pause /
             LIVE / scrubber) moved from the top of the widget to just above
             the PTT, so the controls sit with the newest messages (the feed
             now flows newest-at-bottom). Matches the Zello widget layout. -->
        <div class="radio-transport">
            <button class="btn btn-sm btn-outline-secondary radio-transport-btn" id="radioRewind" title="Rewind (right-click to change)" aria-label="Rewind">
                <i class="bi bi-skip-backward-fill"></i>
                <span class="radio-transport-label">-5s</span>
            </button>
            <button class="btn btn-sm btn-outline-secondary radio-transport-btn" id="radioPlayPause" title="Pause / Resume" aria-label="Pause or resume playback">
                <i class="bi bi-pause-fill"></i>
            </button>
            <button class="btn btn-sm btn-outline-secondary radio-transport-btn" id="radioLive" title="Jump to live" aria-label="Jump to live">
                <i class="bi bi-broadcast-pin"></i>
                <span class="radio-transport-label">LIVE</span>
            </button>
            <button class="btn btn-sm btn-outline-secondary radio-transport-btn" id="radioSettings" title="Widget settings" aria-label="Widget settings">
                <i class="bi bi-gear"></i>
            </button>
            <div class="radio-position">
                <input type="range" id="radioScrubber" min="0" max="30" step="0.1" value="30"
                       title="Scrub up to 30 seconds back" aria-label="Playback position">
                <span class="radio-position-label" id="radioPosLabel">live</span>
            </div>
        </div>
        <div class="radio-ptt-bar">
            <button class="radio-ptt-btn" id="radioPttBtn" aria-label="Push to talk">
                <i class="bi bi-mic-fill me-1"></i> Push to Talk
            </button>
            <div class="radio-vu" id="radioVu" aria-hidden="true">
                <div class="radio-vu-bar"></div>
            </div>
            <div class="radio-ptt-hint">Hold Space or click to talk</div>
        </div>
        <div class="radio-resize-handle"></div>
    </div>
</template>
<script src="assets/js/radio-widget.js?v=<?php echo file_exists(__DIR__ . '/../assets/js/radio-widget.js') ? filemtime(__DIR__ . '/../assets/js/radio-widget.js') : NEWUI_VERSION; ?>"></script>
<!-- Shared map-defaults loader (one canonical source for all Leaflet map initializers) -->
<script src="assets/js/map-defaults.js?v=<?php echo file_exists(__DIR__ . '/../assets/js/map-defaults.js') ? filemtime(__DIR__ . '/../assets/js/map-defaults.js') : NEWUI_VERSION; ?>"></script>

<!-- Command Bar (hidden, shown on "/" keypress) — available on all pages -->
<div class="command-bar d-none" id="commandBar">
    <div class="command-bar-inner">
        <i class="bi bi-terminal me-2 text-body-secondary"></i>
        <label for="commandInput" class="visually-hidden">Command bar</label>
        <input type="text" class="command-bar-input" id="commandInput" placeholder="Type a command... /inc, /resp, /new, /log" autocomplete="off" spellcheck="false">
        <kbd class="ms-2 text-body-tertiary">Esc</kbd>
    </div>
</div>
<script src="assets/js/command-bar.js?v=<?php echo NEWUI_VERSION; ?>"></script>

<!-- Language Switcher bootstrap data + module (Phase 8 i18n) -->
<?php if (count($_navbar_langs) >= 2): ?>
<script>
window.AVAILABLE_LANGS    = <?php echo json_encode($_navbar_langs); ?>;
window.CURRENT_LANG       = <?php echo json_encode($_navbar_curr); ?>;
window.CSRF_TOKEN         = window.CSRF_TOKEN || <?php echo json_encode($_SESSION['csrf_token'] ?? ''); ?>;
// Phase 8b: full registry so the switcher can use admin-customized
// display + native names instead of the JS LANG_NAMES fallback map.
window.LANGUAGE_REGISTRY  = <?php echo json_encode(i18n_language_registry(), JSON_UNESCAPED_UNICODE); ?>;
</script>
<script src="assets/js/language-switcher.js?v=<?php echo file_exists(__DIR__ . '/../assets/js/language-switcher.js') ? filemtime(__DIR__ . '/../assets/js/language-switcher.js') : NEWUI_VERSION; ?>"></script>
<?php endif; ?>

<?php
// Phase 95-plus (2026-06-28) — expose the stale-location threshold to
// all client JS via window.STALE_LOCATION_MIN. Read from the settings
// table; default to 30 minutes if absent. Used by:
//   - assets/js/incident-detail.js — flags responders with location
//     older than this on the incident-detail Assignments panel
//   - assets/js/units.js — flags units with stale GPS on units.php
// Admin overrides via Settings → System Settings → key
// `stale_location_threshold_minutes`. Reasonable range: 5-120.
$_stale_loc_min = 30;
if (function_exists('get_variable')) {
    $v = get_variable('stale_location_threshold_minutes');
    if ($v !== '' && $v !== null && (int) $v > 0) {
        $_stale_loc_min = (int) $v;
    }
}

// 2026-06-28 — admin-configurable distance unit. Used by every
// distance-rendering surface (incident-detail Assignments table,
// Available Responders panel, search dropdown). Server-side
// haversine still computes in km; JS converts at render time so
// flipping the unit doesn't require a backend round-trip.
//
// Valid values: 'mi' (statute miles, US default), 'km' (metric),
// 'nmi' (nautical miles — useful for marine SAR / Coast Guard
// auxiliaries / ARES groups doing coastal ops).
$_dist_unit = 'mi';
if (function_exists('get_variable')) {
    $du = get_variable('distance_unit');
    if (in_array($du, ['mi', 'km', 'nmi'], true)) {
        $_dist_unit = $du;
    }
}

// Phase 99a (2026-06-28) — expose available messaging channels to the
// Compose form so the channel selector can disable channels that
// aren't configured. The actual handlers are always loaded; this
// flag only affects whether the channel option is enabled in the UI.
$_msg_channels = ['inbox' => true];
if (function_exists('get_variable')) {
    $smtpHost = get_variable('smtp_host');
    $smtpUser = get_variable('smtp_user');
    $_msg_channels['smtp'] = (!empty($smtpHost) && !empty($smtpUser));

    $smsProv = get_variable('sms_provider');
    $smsConfigured = false;
    if (!empty($smsProv)) {
        if ($smsProv === 'twilio') {
            $smsConfigured = (get_variable('sms_twilio_sid') !== ''
                           && get_variable('sms_twilio_token') !== ''
                           && get_variable('sms_twilio_from') !== '');
        } elseif ($smsProv === 'bulkvs') {
            $smsConfigured = (get_variable('sms_bulkvs_apikey') !== '');
        } elseif ($smsProv === 'pushbullet') {
            $smsConfigured = (get_variable('sms_pushbullet_token') !== '');
        } elseif ($smsProv === 'generic') {
            $smsConfigured = (get_variable('sms_generic_url') !== '');
        }
    }
    $_msg_channels['sms'] = $smsConfigured;

    // Phase 99a #11 (2026-06-28) — Meshtastic / MeshCore. Bridges
    // are protocol-agnostic on the server side (the same bridge
    // can carry both protocols; bridge_channels declares which
    // protocol each channel uses). Mark mesh channels available
    // when ANY non-revoked bridge exists. Sends queue regardless
    // of online state — bridge drains on next reconnect.
    try {
        $bridgeCount = (int) db_fetch_value(
            "SELECT COUNT(*) FROM `" . ($GLOBALS['db_prefix'] ?? '') . "mesh_bridges`
              WHERE `revoked_at` IS NULL"
        );
        $hasMesh = ($bridgeCount > 0);
        $_msg_channels['meshtastic'] = $hasMesh;
        $_msg_channels['meshcore']   = $hasMesh;
    } catch (Throwable $e) {
        $_msg_channels['meshtastic'] = false;
        $_msg_channels['meshcore']   = false;
    }

    // Phase 99a #14 (2026-06-28) — APRS-IS text. Configured when
    // the install has a sending callsign + computed passcode.
    $aprsCall = trim((string) (get_variable('aprs_send_callsign') ?? ''));
    $aprsPass = (int) (get_variable('aprs_send_passcode') ?? -1);
    $_msg_channels['aprs'] = ($aprsCall !== '' && $aprsPass > 0);

    // Phase 99e #18 (2026-06-28) — DMR text channel. Available when
    // there's at least one enabled talkgroup. Text-send broker
    // handler is stubbed — picker exists for UX validation; actual
    // text protocol awaits Phase 99e build-out.
    try {
        $tgEnabled = (int) db_fetch_value(
            "SELECT COUNT(*) FROM `" . ($GLOBALS['db_prefix'] ?? '') . "talkgroups`
              WHERE `enabled` = 1"
        );
        $_msg_channels['dmr'] = ($tgEnabled > 0);
    } catch (Throwable $e) {
        $_msg_channels['dmr'] = false;
    }
}

// 2026-06-28 — per-user "Send As" personal-identity targets. The
// Compose form's Send-As dropdown reads this to know which channels
// the logged-in user has a personal identity on. SMTP first (cleanest
// case — user.email). Mesh / DMR / APRS / Zello added when those
// channels gain Send-As support.
$_send_as_personal = ['smtp' => null];
if (!empty($_SESSION['user_id']) && function_exists('db_fetch_one')) {
    try {
        $_p = db_fetch_one(
            "SELECT `email`, `user` FROM `" . ($GLOBALS['db_prefix'] ?? '') . "user` WHERE `id` = ? LIMIT 1",
            [(int) $_SESSION['user_id']]
        );
        if ($_p && !empty($_p['email']) && filter_var($_p['email'], FILTER_VALIDATE_EMAIL)) {
            $_send_as_personal['smtp'] = [
                'address' => $_p['email'],
                'label'   => ($_p['user'] ?? '') . ' <' . $_p['email'] . '>',
            ];
        }
    } catch (Throwable $e) { /* non-fatal */ }
}

// Phase 118 — expose the configured list page size to client JS via
// window.LIST_PAGE_SIZE. Read from settings.page_size; any positive integer;
// default 50 when absent/invalid. Consumed by assets/js/units.js (client-side
// pagination on the Units screen); other list screens will adopt it.
$_list_page_size = 50;
if (function_exists('get_variable')) {
    $_lps = (int) get_variable('page_size');
    if ($_lps > 0) $_list_page_size = $_lps;
}
?>
<script>
window.STALE_LOCATION_MIN  = <?php echo (int) $_stale_loc_min; ?>;
window.LIST_PAGE_SIZE      = <?php echo (int) $_list_page_size; ?>;
window.DISTANCE_UNIT       = <?php echo json_encode($_dist_unit); ?>;
window.MESSAGING_CHANNELS  = <?php echo json_encode($_msg_channels); ?>;
window.SEND_AS_PERSONAL    = <?php echo json_encode($_send_as_personal); ?>;

// Format a kilometer value into the install's configured display
// unit. Returns 'N.N mi' / 'N.N km' / 'N.N nmi'. The JS-side
// conversion lets us change the unit without a server round-trip.
//   1 km = 0.621371 statute miles
//   1 km = 0.539957 nautical miles
window.formatDistanceKm = function (km) {
    if (typeof km !== 'number' || isNaN(km)) return '';
    var u = window.DISTANCE_UNIT || 'mi';
    if (u === 'km')  return km.toFixed(1) + ' km';
    if (u === 'nmi') return (km * 0.539957).toFixed(1) + ' nmi';
    return (km * 0.621371).toFixed(1) + ' mi';
};
</script>

<!-- Phase 96 (2026-06-28) — Web Push client loaded globally so any
     page can call TCADPush.enable() to subscribe the current browser.
     A floating "Enable notifications" pill appears bottom-right when:
       (a) browser supports Push, AND
       (b) push_enabled=1 + VAPID configured on the server, AND
       (c) Notification.permission === 'default' (never asked yet)
     Once granted (or denied), the pill goes away. -->
<script src="assets/js/push-client.js?v=<?php echo file_exists(__DIR__ . '/../assets/js/push-client.js') ? filemtime(__DIR__ . '/../assets/js/push-client.js') : NEWUI_VERSION; ?>"></script>
<script>
(function () {
    if (!window.TCADPush || !TCADPush.isSupported()) return;
    if (TCADPush.getPermission() !== 'default') return;
    fetch('/api/push-vapid-public-key.php')
        .then(function (r) { if (!r.ok) throw 0; return r.json(); })
        .then(function (d) {
            if (!d.ok) return;
            var pill = document.createElement('button');
            pill.id = 'tcadEnablePushPill';
            pill.className = 'btn btn-primary btn-sm shadow';
            pill.style.cssText = 'position:fixed;bottom:1rem;right:1rem;z-index:1080;border-radius:2rem;padding:.5rem 1rem;';
            pill.innerHTML = '<i class="bi bi-bell-fill me-1"></i>Enable notifications';
            pill.addEventListener('click', function () {
                pill.disabled = true;
                pill.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Subscribing&hellip;';
                // Phase 99t (a beta tester beta 2026-06-29) — mobile.php
                // subscribers get the assigned-only filter by default
                // so field responders don't get the dispatcher firehose.
                // Detect via URL pathname so we don't need a per-page
                // window.* shim. Desktop pages stay filter-less.
                var pushSource = (location.pathname.indexOf('/mobile.php') >= 0)
                    ? 'mobile' : 'desktop';
                TCADPush.enable({ source: pushSource }).then(function (r) {
                    if (r.ok) {
                        pill.className = 'btn btn-success btn-sm shadow';
                        pill.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i>Notifications on';
                        setTimeout(function () { pill.remove(); }, 2500);
                    } else {
                        pill.className = 'btn btn-warning btn-sm shadow';
                        pill.innerHTML = '<i class="bi bi-exclamation-circle-fill me-1"></i>' + (r.error || 'Failed');
                        setTimeout(function () { pill.remove(); }, 4000);
                    }
                });
            });
            document.body.appendChild(pill);
        })
        .catch(function () { /* push disabled or unavailable — silent */ });
})();
</script>
