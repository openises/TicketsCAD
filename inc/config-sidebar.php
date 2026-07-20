<?php
/**
 * Config Sidebar — Shared navigation for settings, status, and admin pages.
 *
 * Phase 36 layout (locked 2026-06-13). See specs/phase-36-settings-sidebar/
 * final-layout.md for the canonical structure + rationale.
 *
 * Usage:
 *   $configActivePage = 'system-health';
 *   include __DIR__ . '/inc/config-sidebar.php';
 *
 * The sidebar entries come in two flavors:
 *   - <a href="X.php"> — navigates to a standalone admin page
 *   - <button data-tab="X"> — switches the active tab inside settings.php
 *
 * Section order is fixed (Operations first); items inside each section
 * are alphabetized. Sub-headers (always expanded) group large sections.
 *
 * The filter box at the top hides any item whose label doesn't contain
 * the typed string (case-insensitive). Sub-headers and section headers
 * also hide when all their items are filtered out.
 *
 * i18n: every visible label runs through t() so non-English session
 * languages see translated text.
 */

require_once __DIR__ . '/i18n.php';

$configActivePage = $configActivePage ?? '';

if (!function_exists('_cfg_active')) {
function _cfg_active($tab, $active) {
    return $tab === $active ? ' active' : '';
}
}
if (!function_exists('_cfg_tab')) {
function _cfg_tab($id, $label, $keywords = '') {
    $active = _cfg_active($id, $GLOBALS['configActivePage'] ?? '');
    $kw = $keywords !== '' ? ' data-kw="' . e($keywords) . '"' : '';
    echo '<li><button class="config-tab-link' . $active . '" data-tab="' . e($id) . '"' . $kw . '>' . e($label) . '</button></li>';
}
}
if (!function_exists('_cfg_link')) {
function _cfg_link($key, $href, $label, $keywords = '') {
    $active = _cfg_active($key, $GLOBALS['configActivePage'] ?? '');
    $kw = $keywords !== '' ? ' data-kw="' . e($keywords) . '"' : '';
    echo '<li><a class="config-tab-link' . $active . '" href="' . e($href) . '"' . $kw . ' style="text-decoration:none;color:inherit;">' . e($label) . '</a></li>';
}
}
if (!function_exists('_cfg_sub')) {
function _cfg_sub($label) {
    echo '<li class="config-sub-header">' . e($label) . '</li>';
}
}
$canMesh = (!function_exists('rbac_can') || rbac_can('action.manage_mesh_bridges'));
$canRoles = (!function_exists('rbac_can') || rbac_can('action.manage_roles'));
$canCfg   = (!function_exists('rbac_can') || rbac_can('action.manage_config'));
?>
<nav class="config-sidebar" id="configSidebar">

    <div class="config-sidebar-filter">
        <input type="text" id="cfgSidebarFilter"
               placeholder="<?php echo e(t('sidebar.filter.placeholder', 'Filter settings…')); ?>"
               autocomplete="off">
    </div>

    <!-- 1. Operations -->
    <button class="config-section-header" data-section="operations">
        <i class="bi bi-speedometer2 section-icon text-danger"></i>
        <?php echo e(t('sidebar.section.operations', 'Operations')); ?>
        <i class="bi bi-chevron-down chevron"></i>
    </button>
    <ul class="config-tab-list" data-section="operations">
        <?php _cfg_tab('welcome',          t('sidebar.tab.welcome',         'Welcome')); ?>
        <?php _cfg_link('system-health', 'status.php',               t('sidebar.tab.system_health',  'System Health'), 'diagnostics status health monitoring uptime services'); ?>
        <?php _cfg_tab('audit-log',        t('sidebar.tab.audit_log',       'Audit Log'), 'audit trail history who changed activity log'); ?>
        <?php _cfg_link('time-check',    'status-time.php',          t('sidebar.tab.time_check',     'Time Check'), 'ntp time sync clock'); ?>
        <?php _cfg_tab('database-info',    t('sidebar.tab.database_info',   'Database Info'), 'db schema tables mysql mariadb database'); ?>
        <?php _cfg_tab('backup',           t('sidebar.tab.backup',          'Backup / Maintenance'), 'backup restore dump database maintenance'); ?>
        <?php _cfg_link('migrations',    'migrations.php',           t('sidebar.tab.migrations',     'Database Migrations'), 'migration schema upgrade sql patch'); ?>
        <?php _cfg_link('import-export', 'import-export.php',        t('sidebar.tab.import_export',  'Import / Export'), 'csv import export data spreadsheet places'); ?>
        <?php
        $wbExtra = ' <span class="badge bg-secondary ms-1" id="wbSidebarBadge" style="font-size:0.6rem;"></span>';
        $active  = _cfg_active('wastebasket', $configActivePage);
        echo '<li><button class="config-tab-link' . $active . '" data-tab="wastebasket" data-kw="trash deleted recycle restore soft delete">' . e(t('sidebar.tab.wastebasket', 'Wastebasket')) . $wbExtra . '</button></li>';
        ?>
    </ul>

    <!-- 2. Identity & Security -->
    <button class="config-section-header" data-section="identity-security">
        <i class="bi bi-shield-lock section-icon text-success"></i>
        <?php echo e(t('sidebar.section.identity_security', 'Identity & Security')); ?>
        <i class="bi bi-chevron-down chevron"></i>
    </button>
    <ul class="config-tab-list" data-section="identity-security">
        <?php _cfg_link('compliance-dashboard', 'compliance-dashboard.php', t('sidebar.tab.security_compliance', 'Security Compliance'), 'compliance cjis posture audit security'); ?>
        <?php _cfg_tab('user-accounts',     t('sidebar.tab.user_accounts',      'User Accounts'), 'users login account password reset disable'); ?>
        <?php _cfg_tab('roles-levels',      t('sidebar.tab.roles_permissions',  'Roles & Permissions'), 'rbac roles permissions access control privileges'); ?>
        <?php _cfg_tab('login-settings',    t('sidebar.tab.login_settings',     'Login Settings'), 'login lockout session password policy headers'); ?>
        <?php _cfg_tab('two-factor-auth',   t('sidebar.tab.two_factor_auth',    'Two-Factor Auth'), '2fa totp mfa otp authenticator'); ?>
        <?php _cfg_tab('field-encryption',  t('sidebar.tab.field_encryption',   'Field Encryption'), 'rsa encrypt encryption crypto'); ?>
        <?php _cfg_tab('security-labels',   t('sidebar.tab.security_labels',    'Security Labels'), 'classification labels sensitivity fouo'); ?>
    </ul>

    <!-- 3. Initial Setup -->
    <button class="config-section-header" data-section="initial-setup">
        <i class="bi bi-hdd-rack section-icon text-secondary"></i>
        <?php echo e(t('sidebar.section.initial_setup', 'Initial Setup')); ?>
        <i class="bi bi-chevron-down chevron"></i>
    </button>
    <ul class="config-tab-list" data-section="initial-setup">
        <?php _cfg_sub(t('sidebar.sub.connectivity', 'Connectivity')); ?>
        <?php _cfg_tab('system-settings',   t('sidebar.tab.system_settings',  'System Settings'), 'general org name timezone site settings'); ?>
        <?php _cfg_tab('api-keys',          t('sidebar.tab.api_keys',         'API Keys'), 'token bearer external api integration'); ?>
        <?php _cfg_tab('lookup-services',   t('sidebar.tab.lookup_services',  'Lookup Services'), 'fcc callsign geocoding nominatim lookup'); ?>
        <?php if ($canCfg): ?>
        <?php _cfg_sub(t('sidebar.sub.localization', 'Localization')); ?>
        <?php _cfg_tab('languages',         t('sidebar.tab.languages',        'Languages'), 'i18n locale language internationalization'); ?>
        <?php _cfg_tab('translations',      t('sidebar.tab.translations',     'Translations'), 'captions i18n translate strings labels wording rename'); ?>
        <?php endif; ?>
    </ul>

    <!-- 4. Application — Dispatch -->
    <button class="config-section-header" data-section="app-dispatch">
        <i class="bi bi-broadcast-pin section-icon text-primary"></i>
        <?php echo e(t('sidebar.section.app_dispatch', 'Application — Dispatch')); ?>
        <i class="bi bi-chevron-down chevron"></i>
    </button>
    <ul class="config-tab-list" data-section="app-dispatch">
        <?php _cfg_tab('constituents',          t('sidebar.tab.constituents',         'Constituents'), 'contacts address book people constituents'); ?>
        <?php _cfg_tab('signals',               t('sidebar.tab.field_help',           'Field Help Text'), 'field help hint tooltip signals'); ?>
        <?php _cfg_tab('incident-lifecycle',    t('sidebar.tab.incident_lifecycle',   'Incident Lifecycle'), 'auto close lifecycle status timeout'); ?>
        <?php _cfg_tab('incident-numbers',      t('sidebar.tab.incident_numbers',     'Incident Numbers'), 'case number format template numbering'); ?>
        <?php _cfg_tab('incident-types',        t('sidebar.tab.incident_types',       'Incident Types'), 'call types nature protocol incident'); ?>
        <?php _cfg_tab('par-checks',            t('sidebar.tab.par_checks',           'PAR Checks'), 'par accountability roll call personnel'); ?>
        <?php _cfg_tab('pending-messages',      t('sidebar.tab.pending_messages',     'Pending Messages'), 'queue pending messages outbox'); ?>
        <?php _cfg_tab('severity-levels',       t('sidebar.tab.severity_levels',      'Severity Levels'), 'priority severity levels'); ?>
        <?php _cfg_tab('signal-codes',          t('sidebar.tab.signal_codes',         'Signal Codes'), 'signal codes ten-codes 10-codes q-codes'); ?>
        <?php _cfg_tab('unit-assignment-roles', t('sidebar.tab.unit_assignment_roles','Unit Assignment Roles'), 'assignment roles unit crew position'); ?>
        <?php _cfg_tab('unit-statuses',         t('sidebar.tab.unit_statuses',        'Unit Statuses'), 'unit status enroute onscene available codes'); ?>
        <?php _cfg_tab('unit-types',            t('sidebar.tab.unit_types',           'Unit Types'), 'unit types apparatus resource'); ?>
        <?php // Phase 105 (GH #16) — visual status-workflow designer.
              if (!function_exists('rbac_can') || rbac_can('action.manage_status_workflow')) {
                  _cfg_link('status-workflow', 'workflow-designer.php', t('sidebar.tab.status_workflow', 'Status Workflow'), 'workflow status transitions designer'); } ?>
    </ul>

    <!-- 5. Application — Presentation -->
    <button class="config-section-header" data-section="app-presentation">
        <i class="bi bi-sliders section-icon text-primary"></i>
        <?php echo e(t('sidebar.section.app_presentation', 'Application — Presentation')); ?>
        <i class="bi bi-chevron-down chevron"></i>
    </button>
    <ul class="config-tab-list" data-section="app-presentation">
        <?php _cfg_tab('display-settings',  t('sidebar.tab.display_settings', 'Display Settings'), 'theme dark light appearance ui display'); ?>
        <?php _cfg_tab('map-defaults',      t('sidebar.tab.map_defaults',     'Map Settings'), 'leaflet center zoom basemap map default'); ?>
        <?php _cfg_tab('sound-alerts',      t('sidebar.tab.sound_alerts',     'Sound / Alerts'), 'audio sound alert tone beep chime'); ?>
        <?php _cfg_tab('tile-providers',    t('sidebar.tab.tile_providers',   'Tile Providers'), 'openstreetmap osm mapbox basemap map tiles source'); ?>
    </ul>

    <!-- 6. Application — Geographic Data -->
    <button class="config-section-header" data-section="app-geo">
        <i class="bi bi-map section-icon text-info"></i>
        <?php echo e(t('sidebar.section.app_geo_data', 'Application — Geographic Data')); ?>
        <i class="bi bi-chevron-down chevron"></i>
    </button>
    <ul class="config-tab-list" data-section="app-geo">
        <?php _cfg_tab('alert-zones',       t('sidebar.tab.alert_zones',      'Alert Zones'), 'geofence warn zones alert areas polygon'); ?>
        <?php _cfg_tab('map-overlay-categories', t('sidebar.tab.map_overlays', 'Map Overlays'), 'overlays markup categories map'); ?>
        <?php // Phase 110 (GH #43) — event map image overlays (standalone page).
              if (!function_exists('rbac_can') || rbac_can('action.manage_map_overlays')) {
                  _cfg_link('event-map-overlays', 'map-overlays.php', t('sidebar.tab.event_map_overlays', 'Event Map Overlays'), 'image overlay map event floorplan'); } ?>
        <?php _cfg_tab('places',            t('sidebar.tab.places',           'Places'), 'places gazetteer landmarks named locations addresses'); ?>
        <?php _cfg_tab('regions',           t('sidebar.tab.regions',          'Regions'), 'regions boundaries areas districts'); ?>
        <?php _cfg_tab('road-conditions',   t('sidebar.tab.road_conditions',  'Road Conditions'), '511 traffic road conditions'); ?>
    </ul>

    <!-- 7. People -->
    <button class="config-section-header" data-section="people">
        <i class="bi bi-person-badge section-icon text-info"></i>
        <?php echo e(t('sidebar.section.people', 'People')); ?>
        <i class="bi bi-chevron-down chevron"></i>
    </button>
    <ul class="config-tab-list" data-section="people">
        <?php _cfg_sub(t('sidebar.sub.people_teams', 'People & Teams')); ?>
        <?php _cfg_tab('members',                  t('sidebar.tab.members',         'Members / Personnel'), 'personnel members roster people responders'); ?>
        <?php _cfg_tab('organizations',            t('sidebar.tab.organizations',   'Organizations'), 'org organizations agencies groups departments'); ?>
        <?php _cfg_tab('teams',                    t('sidebar.tab.teams',           'Teams'), 'teams crews groups strike-teams'); ?>
        <?php _cfg_sub(t('sidebar.sub.roles_quals', 'Roles & Qualifications')); ?>
        <?php _cfg_tab('certifications',           t('sidebar.tab.certifications',  'Certifications'), 'certs certifications qualifications credentials'); ?>
        <?php _cfg_tab('ics-positions',            t('sidebar.tab.ics_positions',   'ICS Positions'), 'ics positions nims incident command'); ?>
        <?php _cfg_tab('member-statuses',          t('sidebar.tab.member_statuses', 'Member Statuses'), 'member status active inactive availability'); ?>
        <?php _cfg_tab('member-types',             t('sidebar.tab.member_types',    'Member Types'), 'member types categories roles'); ?>
        <?php _cfg_tab('training',                 t('sidebar.tab.training',        'Training'), 'training courses records classes'); ?>
        <?php _cfg_sub(t('sidebar.sub.workflow_config', 'Workflow Config')); ?>
        <?php _cfg_tab('scheduling-permissions',   t('sidebar.tab.scheduling_perms','Scheduling Permissions'), 'scheduling shifts self-signup permissions calendar'); ?>
    </ul>

    <!-- 8. Resources -->
    <button class="config-section-header" data-section="resources">
        <i class="bi bi-box-seam section-icon text-warning"></i>
        <?php echo e(t('sidebar.section.resources', 'Resources')); ?>
        <i class="bi bi-chevron-down chevron"></i>
    </button>
    <ul class="config-tab-list" data-section="resources">
        <?php _cfg_tab('equipment',         t('sidebar.tab.equipment',      'Equipment Types'), 'equipment gear inventory kit'); ?>
        <?php _cfg_tab('facilities',        t('sidebar.tab.facilities',     'Facilities'), 'facilities hospitals shelters stations beds'); ?>
        <?php _cfg_tab('facility-statuses', t('sidebar.tab.facility_statuses','Facility Statuses'), 'facility status open closed diversion'); ?>
        <?php _cfg_tab('facility-types',    t('sidebar.tab.facility_types', 'Facility Types'), 'facility types hospital shelter station'); ?>
        <?php _cfg_tab('vehicles',          t('sidebar.tab.vehicles',       'Vehicle Types'), 'vehicles apparatus fleet trucks'); ?>
        <?php _cfg_sub(t('sidebar.sub.address_book', 'Address Book')); ?>
        <?php _cfg_tab('constituents',      t('sidebar.tab.constituents',   'Constituents'), 'contacts address book people constituents'); ?>
    </ul>

    <!-- 9. Communications & Integrations -->
    <button class="config-section-header" data-section="comms-integrations">
        <i class="bi bi-chat-dots section-icon text-warning"></i>
        <?php echo e(t('sidebar.section.comms_integrations', 'Communications & Integrations')); ?>
        <i class="bi bi-chevron-down chevron"></i>
    </button>
    <ul class="config-tab-list" data-section="comms-integrations">
        <?php _cfg_sub(t('sidebar.sub.routing_policy', 'Routing & Policy')); ?>
        <?php _cfg_tab('message-routing',   t('sidebar.tab.message_routing',   'Message Routing'), 'routing rules bridge forward message'); ?>
        <?php _cfg_tab('notifications',     t('sidebar.tab.notification_rules','Notification Rules'), 'notification rules alerts triggers'); ?>
        <?php _cfg_tab('std-messages',      t('sidebar.tab.std_messages',      'Standard Messages'), 'standard messages canned templates quick'); ?>
        <?php // Phase 112 — NWS weather alerts (standalone admin page).
              if (!function_exists('rbac_can') || rbac_can('action.manage_weather_alerts')) {
                  _cfg_link('weather-alerts', 'weather-alerts.php', t('sidebar.tab.weather_alerts', 'Weather Alerts'), 'nws noaa weather warnings'); } ?>
        <?php // Phase 111 Slice B — dispatcher message tray (standalone page).
              if (!function_exists('rbac_can') || rbac_can('screen.message_tray')) {
                  _cfg_link('message-tray', 'message-tray.php', t('sidebar.tab.message_tray', 'Message Tray'), 'message tray dispatcher ics-214 log'); } ?>

        <?php _cfg_sub(t('sidebar.sub.voice', 'Voice')); ?>
        <?php // Phase 113 — pluggable text-to-speech engines (standalone page).
              if (!function_exists('rbac_can') || rbac_can('action.manage_tts')) {
                  _cfg_link('voice-speech', 'voice-speech.php', t('sidebar.tab.voice_speech', 'Voice & Speech'), 'tts piper deepgram openai text to speech'); } ?>
        <?php _cfg_tab('radio-messaging',   t('sidebar.tab.radio_messaging',   'Radio Messaging'), 'radio messaging meshtastic dmr text aprs'); ?>
        <?php _cfg_tab('zello-radio',       t('sidebar.tab.zello_radio',       'Zello Network Radio'), 'zello poc push to talk ptt'); ?>
        <?php _cfg_tab('dvswitch-dmr',      t('sidebar.tab.dvswitch_dmr',      'DMR (DVSwitch)'), 'dmr dvswitch brandmeister digital radio'); ?>
        <?php _cfg_tab('talkgroups',        t('sidebar.tab.talkgroups',        'DMR Talkgroups'), 'talkgroup brandmeister dmr'); ?>

        <?php _cfg_sub(t('sidebar.sub.text', 'Text')); ?>
        <?php _cfg_tab('chat-settings',     t('sidebar.tab.chat_settings',     'Chat Settings'), 'chat local messaging instant'); ?>
        <?php _cfg_tab('push-notifications', t('sidebar.tab.push_notifications','Web Push Notifications'), 'webpush vapid browser push notification'); ?>
        <?php _cfg_tab('email-config',      t('sidebar.tab.email_config',      'Email Configuration'), 'smtp mail outgoing email sendmail'); ?>
        <?php _cfg_tab('email-lists',       t('sidebar.tab.email_lists',       'Email Lists'), 'email lists distribution mailing groups'); ?>
        <?php _cfg_tab('slack',             t('sidebar.tab.slack',             'Slack'), 'slack webhook chatops'); ?>
        <?php _cfg_tab('sms-config',        t('sidebar.tab.sms_config',        'SMS Configuration'), 'twilio bulkvs pushbullet text message texting phone sms'); ?>
        <?php _cfg_tab('telegram',          t('sidebar.tab.telegram',          'Telegram'), 'telegram bot chat'); ?>

        <?php _cfg_sub(t('sidebar.sub.location', 'Location')); ?>
        <?php _cfg_tab('aprs-config',         t('sidebar.tab.aprs',              'APRS'), 'aprs.fi ax25 packet radio position'); ?>
        <?php _cfg_tab('tracking-providers',  t('sidebar.tab.location_providers','Location Providers'), 'owntracks traccar opengts gps meshtastic aprs location tracking'); ?>
        <?php _cfg_tab('provider-settings',   t('sidebar.tab.provider_settings', 'Provider Settings'), 'provider connection config location aprs meshtastic owntracks traccar opengts zello dmr google latitude'); ?>
        <?php _cfg_tab('owntracks-defaults',  t('sidebar.tab.owntracks_defaults', 'OwnTracks Defaults'), 'owntracks defaults location tracking mqtt'); ?>
        <?php _cfg_link('owntracks-diag', 'owntracks-diagnostics.php', t('sidebar.tab.owntracks_diag', 'OwnTracks Diagnostics'), 'owntracks diagnostics debug location troubleshoot'); ?>
        <?php _cfg_tab('location-ingest',     t('sidebar.tab.location_ingest',   'Location Ingest (Traccar/OpenGTS)'), 'traccar opengts ingest gps endpoint location token'); ?>
        <?php _cfg_tab('atak-tak',            t('sidebar.tab.atak_tak',          'ATAK / TAK (CoT bridge)'), 'atak tak cot wintak cursor on target'); ?>
        <?php _cfg_tab('location-retention',  t('sidebar.tab.location_retention','Location History Retention'), 'retention history purge cleanup location'); ?>

        <?php if ($canMesh): ?>
        <?php _cfg_sub(t('sidebar.sub.multi_protocol', 'Multi-protocol')); ?>
        <?php _cfg_link('mesh-console', 'mesh-console.php', t('sidebar.tab.mesh_console', 'Mesh Bridges (LoRa)'), 'meshtastic lora mesh bridge radio'); ?>
        <?php endif; ?>

        <?php _cfg_sub(t('sidebar.sub.integrations', 'Integrations')); ?>
        <?php _cfg_tab('comm-modes',        t('sidebar.tab.comm_modes',     'Comm / Location Modes'), 'comm modes location channels adapters'); ?>
        <?php _cfg_tab('webhooks',          t('sidebar.tab.webhooks',       'Webhooks / Events'), 'webhooks events http callback integration'); ?>
        <?php _cfg_tab('external-api-tokens', t('sidebar.tab.external_api_tokens', 'External API Tokens'), 'api token bearer external rest integration key'); ?>
    </ul>

</nav>

<script>
// Phase 36 follow-up: make the sidebar work on every page that includes it,
// not just settings.php (which had the JS buried in config.js).
//
//  1. Section header click → toggle that section's list collapsed/expanded.
//  2. data-tab button click → navigate to settings.php#<tab-id> (the tab
//     switching lives on settings.php itself).
//  3. Filter box — was already here, kept below.
(function () {
    // (1) Section collapse/expand
    document.querySelectorAll('.config-sidebar .config-section-header').forEach(function (h) {
        h.addEventListener('click', function () {
            var section = this.getAttribute('data-section');
            var list = document.querySelector('.config-sidebar .config-tab-list[data-section="' + section + '"]');
            if (!list) return;
            var collapsed = list.classList.toggle('collapsed');
            this.classList.toggle('collapsed', collapsed);
            list.style.maxHeight = collapsed ? '0' : list.scrollHeight + 'px';
        });
    });
    // Open all sections by default — set explicit max-height so the
    // CSS transition has a starting point on collapse.
    document.querySelectorAll('.config-sidebar .config-tab-list').forEach(function (l) {
        l.style.maxHeight = l.scrollHeight + 'px';
    });

    // (2) Tab buttons — if we're on settings.php already, the page's own
    //     JS handles the click. Anywhere else, jump to settings.php#tab.
    if (!/\/settings\.php(?:[?#]|$)/.test(window.location.pathname + window.location.search)) {
        document.querySelectorAll('.config-sidebar .config-tab-link[data-tab]').forEach(function (b) {
            b.addEventListener('click', function (e) {
                e.preventDefault();
                var tab = this.getAttribute('data-tab');
                window.location.href = 'settings.php#' + tab;
            });
        });
    }

    // (3) Sidebar filter
    var input = document.getElementById('cfgSidebarFilter');
    if (!input) return;
    function applyFilter() {
        var q = (input.value || '').toLowerCase().trim();
        document.querySelectorAll('.config-sidebar [data-section]').forEach(function (block) {
            if (block.classList.contains('config-section-header')) return; // header itself handled below
            // It's a <ul class="config-tab-list">
            var anyVisible = false;
            var subBuckets = [];
            var current = { sub: null, items: [] };
            block.querySelectorAll(':scope > li').forEach(function (li) {
                if (li.classList.contains('config-sub-header')) {
                    if (current.sub || current.items.length) subBuckets.push(current);
                    current = { sub: li, items: [] };
                } else {
                    current.items.push(li);
                }
            });
            if (current.sub || current.items.length) subBuckets.push(current);

            subBuckets.forEach(function (bucket) {
                var anyInBucket = false;
                bucket.items.forEach(function (li) {
                    // Match the visible label AND the item's keyword aliases
                    // (data-kw), so e.g. "twilio" finds the SMS panel.
                    var kwEl = li.querySelector('.config-tab-link');
                    var kw = kwEl ? (kwEl.getAttribute('data-kw') || '') : '';
                    var text = ((li.textContent || '') + ' ' + kw).toLowerCase();
                    var match = q === '' || text.indexOf(q) !== -1;
                    li.classList.toggle('config-filter-hidden', !match);
                    if (match) { anyInBucket = true; anyVisible = true; }
                });
                if (bucket.sub) {
                    bucket.sub.classList.toggle('config-filter-hidden', !anyInBucket);
                }
            });

            var sectionName = block.getAttribute('data-section');
            var header = document.querySelector('.config-section-header[data-section="' + sectionName + '"]');
            if (header) header.classList.toggle('config-filter-hidden', !anyVisible);
            block.classList.toggle('config-filter-hidden', !anyVisible && q !== '');
        });
    }
    input.addEventListener('input', applyFilter);
    input.addEventListener('keydown', function (e) { if (e.key === 'Escape') { input.value = ''; applyFilter(); } });
})();
</script>
