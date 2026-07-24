/**
 * NewUI v4.0 - Config/Settings Module
 *
 * Handles sidebar navigation, section/tab switching, and CRUD operations
 * for Incident Types, Unit Statuses, Signals, Facilities, System Settings,
 * API Keys, Map Defaults, Tile Providers, and Users.
 *
 * ES5 IIFE — no arrow functions, no let/const, no modules.
 */
(function () {
    'use strict';

    var API = 'api/config-admin.php';
    var csrfToken = '';
    var userLevel = 99;

    // Tile provider URL templates — must stay in sync with
    // inc/tile-config.php :: tile_provider_templates(). See help.php
    // Tile Providers section for what each one is, key requirements,
    // attribution, and the Bing/Azure migration note.
    var TILE_URLS = {
        // Free, no key — recommend these first
        osm:               'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
        osm_hot:           'https://{s}.tile.openstreetmap.fr/hot/{z}/{x}/{y}.png',
        usgs_topo:         'https://basemap.nationalmap.gov/arcgis/rest/services/USGSTopo/MapServer/tile/{z}/{y}/{x}',
        usgs_imagery:      'https://basemap.nationalmap.gov/arcgis/rest/services/USGSImageryOnly/MapServer/tile/{z}/{y}/{x}',
        usgs_imagery_topo: 'https://basemap.nationalmap.gov/arcgis/rest/services/USGSImageryTopo/MapServer/tile/{z}/{y}/{x}',
        cartodb_positron:  'https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}.png',
        cartodb_dark:      'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}.png',
        esri_street:       'https://server.arcgisonline.com/ArcGIS/rest/services/World_Street_Map/MapServer/tile/{z}/{y}/{x}',
        esri_sat:          'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
        esri_topo:         'https://server.arcgisonline.com/ArcGIS/rest/services/World_Topo_Map/MapServer/tile/{z}/{y}/{x}',
        // Unofficial scrapes — backward compat only, not ToS-compliant
        google_street:     'https://mt{s}.google.com/vt/lyrs=m&x={x}&y={y}&z={z}',
        google_sat:        'https://mt{s}.google.com/vt/lyrs=s&x={x}&y={y}&z={z}',
        google_hybrid:     'https://mt{s}.google.com/vt/lyrs=y&x={x}&y={y}&z={z}',
        // Retired by provider — Microsoft discontinued Bing Maps for
        // Enterprise (Basic shut down 2025-06-30; Enterprise sunset
        // 2028-06-30). Kept for legacy compatibility. Use Azure Maps
        // as a custom provider for new deployments. See help.php.
        bing_road:         'https://ecn.t{s}.tiles.virtualearth.net/tiles/r{q}?g=1&mkt=en-US',
        bing_aerial:       'https://ecn.t{s}.tiles.virtualearth.net/tiles/a{q}?g=1',
        // API key required
        mapbox:            'https://api.mapbox.com/styles/v1/mapbox/streets-v12/tiles/{z}/{x}/{y}?access_token={key}',
        custom:            ''
    };

    // Cache data after load
    var cache = {
        types: [],
        statuses: [],
        signals: [],
        facTypes: [],
        facilities: [],
        users: [],
        warnLocations: []
    };

    // SearchableSelect instance for the Link-to-Member picker on the
    // User Accounts panel. Created lazily on first openUserForm() and
    // re-used on subsequent opens via setItems / setValue so we don't
    // leak event listeners. (specs/searchable-member-dropdown-2026-05)
    var userMemberPicker = null;

    // ═══════════════════════════════════════════════════════════════
    //  INIT
    // ═══════════════════════════════════════════════════════════════
    function init() {
        var el = document.getElementById('csrfToken');
        csrfToken = el ? el.value : '';
        var lvlEl = document.getElementById('userLevel');
        userLevel = lvlEl ? parseInt(lvlEl.value, 10) : 99;

        bindSidebar();
        bindTypesPanel();
        bindStatusesPanel();
        bindSignalsPanel();
        bindSeverityPanel();
        bindDisplaySettingsPanel();
        bindFacilityTypesPanel();
        bindUnitTypesPanel();
        bindSoundAlertsPanel();
        bindFacilitiesPanel();
        bindSettingsPanel();
        bindApiKeysPanel();
        bindMapDefaultsPanel();
        bindTileProviderPanel();
        bindUsersPanel();
        bindWarnLocationsPanel();
        bindZelloPanel();
        bindPushAdminPanel();
        bindTalkgroupsPanel();
        bindOrganizationsPanel();
        bindCommModesPanel();
        bindAuditLogPanel();
        bindRoadConditionsPanel();
        bindIncidentNumbersPanel();
        bindSecurityLabelsPanel();
        bindPendingMessagesPanel();
        bindPARConfigPanel();
        bindWebhooksPanel();
        bindChatSettingsPanel();
        bindExternalApiTokensPanel();
        bindGeofencingPanel();
        bindAprsConfigPanel();
        bindLocationRetentionPanel();
        bindLocationIngestPanel();
        bindOwntracksAuthPanel();
        bindAtakTakPanel();
        bindBackupPanel();
        bindMessageRoutingPanel();
        populateTimezoneList();
        initPopovers();
        loadWelcomeDashboard();

        // Quick links in the welcome panel
        var quickLinks = document.querySelectorAll('.config-quick-link');
        for (var q = 0; q < quickLinks.length; q++) {
            quickLinks[q].addEventListener('click', function (e) {
                e.preventDefault();
                var hash = this.getAttribute('href').replace('#', '');
                if (hash) activateTab(hash);
            });
        }

        // Open section from URL hash
        var hash = window.location.hash.replace('#', '');
        if (hash) {
            activateTab(hash);
        }

        // Listen for hash changes (e.g. clicking navbar links while on settings page)
        window.addEventListener('hashchange', function () {
            var newHash = window.location.hash.replace('#', '');
            if (newHash) {
                activateTab(newHash);
            }
        });
    }

    // Populate timezone datalist for autocomplete
    function populateTimezoneList() {
        var list = document.getElementById('timezoneList');
        if (!list) return;
        var zones = [
            'America/New_York', 'America/Chicago', 'America/Denver', 'America/Los_Angeles',
            'America/Anchorage', 'America/Phoenix', 'America/Adak', 'Pacific/Honolulu',
            'America/Boise', 'America/Indiana/Indianapolis', 'America/Detroit',
            'America/Kentucky/Louisville', 'America/Menominee', 'America/Nome',
            'America/Juneau', 'America/Sitka', 'America/Yakutat', 'America/Metlakatla',
            'America/Toronto', 'America/Vancouver', 'America/Edmonton', 'America/Winnipeg',
            'America/Halifax', 'America/St_Johns', 'America/Regina',
            'America/Mexico_City', 'America/Cancun', 'America/Tijuana',
            'Europe/London', 'Europe/Paris', 'Europe/Berlin', 'Europe/Madrid', 'Europe/Rome',
            'Europe/Amsterdam', 'Europe/Brussels', 'Europe/Zurich', 'Europe/Vienna',
            'Europe/Stockholm', 'Europe/Oslo', 'Europe/Helsinki', 'Europe/Warsaw',
            'Europe/Prague', 'Europe/Budapest', 'Europe/Bucharest', 'Europe/Athens',
            'Europe/Istanbul', 'Europe/Moscow', 'Europe/Kiev',
            'Asia/Tokyo', 'Asia/Shanghai', 'Asia/Hong_Kong', 'Asia/Singapore',
            'Asia/Seoul', 'Asia/Kolkata', 'Asia/Dubai', 'Asia/Riyadh',
            'Asia/Bangkok', 'Asia/Jakarta', 'Asia/Manila', 'Asia/Taipei',
            'Australia/Sydney', 'Australia/Melbourne', 'Australia/Brisbane',
            'Australia/Perth', 'Australia/Adelaide', 'Australia/Darwin',
            'Pacific/Auckland', 'Pacific/Fiji', 'Pacific/Guam',
            'Africa/Cairo', 'Africa/Lagos', 'Africa/Johannesburg', 'Africa/Nairobi',
            'Atlantic/Reykjavik', 'UTC'
        ];
        zones.sort();
        var html = '';
        for (var i = 0; i < zones.length; i++) {
            html += '<option value="' + zones[i] + '">';
        }
        list.innerHTML = html;
    }

    // Initialize Bootstrap popovers for help icons
    function initPopovers() {
        var popoverTriggerList = document.querySelectorAll('[data-bs-toggle="popover"]');
        for (var i = 0; i < popoverTriggerList.length; i++) {
            new bootstrap.Popover(popoverTriggerList[i]);
        }
    }

    // ═══════════════════════════════════════════════════════════════
    //  SIDEBAR NAVIGATION
    // ═══════════════════════════════════════════════════════════════
    function bindSidebar() {
        // Section expand/collapse
        var headers = document.querySelectorAll('.config-section-header');
        for (var i = 0; i < headers.length; i++) {
            headers[i].addEventListener('click', function () {
                var section = this.getAttribute('data-section');
                var list = document.querySelector('.config-tab-list[data-section="' + section + '"]');
                if (!list) return;
                var isCollapsed = list.classList.contains('collapsed');
                if (isCollapsed) {
                    list.classList.remove('collapsed');
                    list.style.maxHeight = list.scrollHeight + 'px';
                    this.classList.remove('collapsed');
                } else {
                    list.classList.add('collapsed');
                    list.style.maxHeight = '0';
                    this.classList.add('collapsed');
                }
            });
        }

        // Initialize all sections as expanded
        var lists = document.querySelectorAll('.config-tab-list');
        for (var j = 0; j < lists.length; j++) {
            lists[j].style.maxHeight = lists[j].scrollHeight + 'px';
        }

        // Tab links
        var tabs = document.querySelectorAll('.config-tab-link');
        for (var k = 0; k < tabs.length; k++) {
            tabs[k].addEventListener('click', function () {
                var tab = this.getAttribute('data-tab');
                activateTab(tab);
            });
        }
    }

    function activateTab(tab) {
        // Deactivate all tab links
        var allTabs = document.querySelectorAll('.config-tab-link');
        for (var i = 0; i < allTabs.length; i++) {
            allTabs[i].classList.remove('active');
        }
        // Activate clicked tab
        var active = document.querySelector('.config-tab-link[data-tab="' + tab + '"]');
        if (active) active.classList.add('active');

        // Hide all panels
        var panels = document.querySelectorAll('.config-panel');
        for (var j = 0; j < panels.length; j++) {
            panels[j].classList.remove('active');
        }

        // Show target panel (or placeholder)
        var panel = document.getElementById('panel-' + tab);
        if (panel) {
            panel.classList.add('active');
            // Load data on first visit
            onPanelActivated(tab);
        } else {
            showPlaceholder(tab);
        }

        // Update URL hash
        window.location.hash = tab;
    }

    function showPlaceholder(tab) {
        // Reuse or create a generic placeholder
        var placeholder = document.getElementById('panel-placeholder');
        if (!placeholder) {
            placeholder = document.createElement('div');
            placeholder.id = 'panel-placeholder';
            placeholder.className = 'config-panel';
            placeholder.innerHTML =
                '<div class="config-placeholder">' +
                '<i class="bi bi-tools"></i>' +
                '<h5 id="placeholderTitle">Coming Soon</h5>' +
                '<p>This section is under development.</p>' +
                '</div>';
            document.getElementById('configContent').appendChild(placeholder);
        }
        var title = document.getElementById('placeholderTitle');
        if (title) title.textContent = formatTabName(tab);
        placeholder.classList.add('active');
    }

    // Phase 41: global event delegation for .secret-reveal / .secret-copy
    // buttons. Any input that's adjacent to a button with these classes
    // gets eyeball + clipboard behavior for free.
    document.addEventListener('click', function (e) {
        var rev = e.target.closest('.secret-reveal');
        if (rev) {
            var inp = document.getElementById(rev.getAttribute('data-target'));
            if (!inp) return;
            var icon = rev.querySelector('i');
            if (inp.type === 'password') {
                inp.type = 'text';
                if (icon) { icon.classList.remove('bi-eye'); icon.classList.add('bi-eye-slash'); }
            } else {
                inp.type = 'password';
                if (icon) { icon.classList.remove('bi-eye-slash'); icon.classList.add('bi-eye'); }
            }
            return;
        }
        var cp = e.target.closest('.secret-copy');
        if (cp) {
            var inp2 = document.getElementById(cp.getAttribute('data-target'));
            if (!inp2 || !inp2.value) return;
            var origType = inp2.type;
            inp2.type = 'text';
            inp2.select();
            try { document.execCommand('copy'); } catch (err) {}
            inp2.type = origType;
            window.getSelection().removeAllRanges();
            // brief flash on the icon
            var icon2 = cp.querySelector('i');
            if (icon2) {
                icon2.classList.remove('bi-clipboard');
                icon2.classList.add('bi-clipboard-check');
                setTimeout(function () {
                    icon2.classList.remove('bi-clipboard-check');
                    icon2.classList.add('bi-clipboard');
                }, 1200);
            }
        }
    });

    function formatTabName(tab) {
        return tab.replace(/-/g, ' ').replace(/\b\w/g, function (c) { return c.toUpperCase(); });
    }

    // Called when a panel becomes active — load its data if needed
    var loadedPanels = {};
    function onPanelActivated(tab) {
        if (loadedPanels[tab]) return;
        loadedPanels[tab] = true;

        if (tab === 'incident-types')       loadTypes();
        else if (tab === 'unit-statuses')     loadStatuses();
        else if (tab === 'signals')           loadSignals();
        else if (tab === 'signal-codes')      loadSignalCodes();
        else if (tab === 'severity-levels')   loadSeverity();
        else if (tab === 'display-settings')  loadDisplaySettings();
        else if (tab === 'facility-types')    loadFacilityTypes();
        else if (tab === 'unit-types')        loadUnitTypes();
        else if (tab === 'facility-statuses') loadFacilityStatuses();
        else if (tab === 'sound-alerts')      { loadSoundAlerts(); loadCustomTonesPanel(); }
        else if (tab === 'facilities')        loadFacilities();
        else if (tab === 'system-settings')   loadSettings();
        else if (tab === 'api-keys')          loadApiKeys();
        else if (tab === 'map-defaults')      loadMapDefaults();
        else if (tab === 'tile-providers')    loadTileProvider();
        else if (tab === 'user-accounts')     loadUsers();
        else if (tab === 'warn-locations')   loadWarnLocations();
        else if (tab === 'alert-zones')      loadAlertZonesSummary();
        else if (tab === 'zello-radio')      loadZelloSettings();
        else if (tab === 'push-notifications') loadPushAdminConfig();
        else if (tab === 'talkgroups')       loadTalkgroups();
        else if (tab === 'ics-positions')    loadIcsPositions();
        else if (tab === 'training')         loadTrainingConfig();
        else if (tab === 'vehicles')         loadVehicleTypes();
        else if (tab === 'equipment')        loadEquipmentTypes();
        else if (tab === 'certifications')   loadCertifications();
        else if (tab === 'member-types')     loadMemberTypes();
        else if (tab === 'member-statuses')  loadMemberStatuses();
        else if (tab === 'teams')            loadTeamsConfig();
        else if (tab === 'members')          loadMembersSummary();
        else if (tab === 'audit-log')        loadAuditLog();
        else if (tab === 'lookup-services')  loadLookupConfig();
        else if (tab === 'database-info')    loadDatabaseInfo();
        else if (tab === 'std-messages')     loadStdMessages();
        else if (tab === 'regions')          loadRegions();
        else if (tab === 'login-settings')   loadLoginSettings();
        else if (tab === 'field-encryption') loadFieldEncryption();
        else if (tab === 'email-config')     loadEmailConfig();
        else if (tab === 'email-lists')      loadEmailLists();
        else if (tab === 'places')           loadPlaces();
        else if (tab === 'map-overlay-categories') loadMapOverlayCategories();
        // (sound-alerts is handled above — combined with loadSoundAlerts.
        //  Left here as a no-op marker so a search still finds the pair.)
        else if (tab === 'sms-config')       loadSmsConfig();
        else if (tab === 'telegram')         loadTelegramConfig();
        else if (tab === 'incident-numbers') loadIncidentNumbers();
        else if (tab === 'par-checks')       loadPARConfig();
        else if (tab === 'security-labels')  loadSecurityLabels();
        else if (tab === 'pending-messages') loadPendingMessages();
        else if (tab === 'road-conditions')  loadRoadConditions();
        else if (tab === 'roles-levels')     loadRbac();
        else if (tab === 'slack')            loadSlackConfig();
        else if (tab === 'radio-messaging')  loadRadioMsgConfig();
        else if (tab === 'webhooks')         loadWebhooks();
        else if (tab === 'chat-settings')    loadChatSettings();
        else if (tab === 'external-api-tokens') loadExternalApiTokens();
    }

    function loadDatabaseInfo() {
        var el = document.getElementById('dbInfoContent');
        if (!el) return;

        // Load current DB info from health endpoint
        fetch('api/health.php', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var html = '<div class="row g-3">';
                // Connection info
                html += '<div class="col-md-6"><div class="card"><div class="card-body py-2">';
                html += '<h6 class="mb-2"><i class="bi bi-plug me-1"></i>Connection</h6>';
                html += '<table class="table table-sm table-borderless mb-0" style="font-size:0.8rem">';
                html += '<tr><td class="text-body-secondary">Database</td><td class="fw-semibold">' + escHtml(data.database || 'N/A') + '</td></tr>';
                html += '<tr><td class="text-body-secondary">Server</td><td>' + escHtml(data.server_version || 'N/A') + '</td></tr>';
                html += '<tr><td class="text-body-secondary">Host</td><td>' + escHtml(data.host || 'localhost') + '</td></tr>';
                html += '<tr><td class="text-body-secondary">Charset</td><td>' + escHtml(data.charset || 'N/A') + '</td></tr>';
                html += '</table></div></div></div>';
                // Table summary
                html += '<div class="col-md-6"><div class="card"><div class="card-body py-2">';
                html += '<h6 class="mb-2"><i class="bi bi-table me-1"></i>Tables</h6>';
                html += '<table class="table table-sm table-borderless mb-0" style="font-size:0.8rem">';
                html += '<tr><td class="text-body-secondary">Total Tables</td><td class="fw-semibold">' + (data.table_count || 'N/A') + '</td></tr>';
                html += '<tr><td class="text-body-secondary">Total Size</td><td>' + (data.total_size || 'N/A') + '</td></tr>';
                html += '<tr><td class="text-body-secondary">PHP Version</td><td>' + escHtml(data.php_version || 'N/A') + '</td></tr>';
                html += '<tr><td class="text-body-secondary">App Version</td><td>' + escHtml(data.app_version || 'N/A') + '</td></tr>';
                html += '</table></div></div></div>';
                html += '</div>';
                // Table list
                if (data.tables && data.tables.length) {
                    html += '<div class="mt-3"><h6><i class="bi bi-list-ul me-1"></i>Table Details</h6>';
                    html += '<div class="table-responsive" style="max-height:400px;overflow-y:auto">';
                    html += '<table class="table table-sm table-hover" style="font-size:0.75rem"><thead class="sticky-top">';
                    html += '<tr><th>Table</th><th>Engine</th><th>Rows</th><th>Size</th><th>Collation</th></tr></thead><tbody>';
                    for (var i = 0; i < data.tables.length; i++) {
                        var t = data.tables[i];
                        html += '<tr><td>' + escHtml(t.name) + '</td><td>' + escHtml(t.engine || '') + '</td>';
                        html += '<td class="text-end">' + (t.rows || 0) + '</td>';
                        html += '<td class="text-end">' + (t.size || '') + '</td>';
                        html += '<td>' + escHtml(t.collation || '') + '</td></tr>';
                    }
                    html += '</tbody></table></div></div>';
                }
                el.innerHTML = html;
            })
            .catch(function (err) {
                el.innerHTML = '<div class="alert alert-danger small">Failed to load database info: ' + escHtml(err.message) + '</div>';
            });

        // Load legacy migration status
        loadLegacyMigrationStatus();
    }

    // ── Legacy Migration ─────────────────────────────────────────
    var LEGACY_API = 'api/legacy-import.php';
    var migrationLogEntries = [];

    function loadLegacyMigrationStatus() {
        var section = document.getElementById('legacyMigrationSection');
        var badge   = document.getElementById('legacyStatusBadge');
        var buttons = document.getElementById('legacyMigrationButtons');
        if (!section) return;

        section.style.display = 'block';

        fetch(LEGACY_API, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var ls = data.legacy_status || {};

                if (!ls.config_found) {
                    badge.innerHTML = '<span class="badge bg-secondary"><i class="bi bi-x-circle me-1"></i>Legacy config not found</span>' +
                        '<div class="text-body-secondary small mt-1">Place the legacy tickets installation at <code>tickets/</code> alongside <code>newui-dev/</code> to enable migration.</div>';
                    return;
                }

                if (!ls.connected) {
                    badge.innerHTML = '<span class="badge bg-warning text-dark"><i class="bi bi-exclamation-triangle me-1"></i>Legacy DB connection failed</span>' +
                        (ls.error ? '<div class="text-danger small mt-1">' + escHtml(ls.error) + '</div>' : '');
                    return;
                }

                badge.innerHTML = '<span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Legacy database connected</span>';

                // Show counts if available
                var counts = data.legacy_counts || {};
                if (counts.users || counts.types) {
                    badge.innerHTML += '<span class="ms-2 small text-body-secondary">' +
                        (counts.users ? counts.users + ' users' : '') +
                        (counts.users && counts.types ? ', ' : '') +
                        (counts.types ? counts.types + ' incident types' : '') +
                        ' available to import</span>';
                }

                buttons.style.display = 'flex';

                // Show DB info from the legacy-import endpoint
                if (data.db_info) {
                    var di = data.db_info;
                    var infoHtml = '';
                    if (di.key_tables && di.key_tables.length) {
                        infoHtml += '<div class="mt-2"><small class="text-body-secondary">Key tables: ';
                        for (var i = 0; i < di.key_tables.length; i++) {
                            if (i > 0) infoHtml += ', ';
                            infoHtml += escHtml(di.key_tables[i].name) + ' (' + di.key_tables[i].rows + ')';
                        }
                        infoHtml += '</small></div>';
                    }
                    badge.innerHTML += infoHtml;
                }

                // If preview data came with the initial load, show it
                if (data.preview) {
                    renderMigrationPreview(data.preview);
                }
            })
            .catch(function (err) {
                badge.innerHTML = '<span class="badge bg-danger"><i class="bi bi-x-circle me-1"></i>Error</span>' +
                    '<div class="text-danger small mt-1">' + escHtml(err.message) + '</div>';
            });

        // Wire up buttons
        var btnPreview = document.getElementById('btnPreviewMigration');
        var btnRun     = document.getElementById('btnRunMigration');
        var btnUsers   = document.getElementById('btnMigrateUsers');
        var btnTypes   = document.getElementById('btnMigrateTypes');

        if (btnPreview) {
            btnPreview.addEventListener('click', function () {
                btnPreview.disabled = true;
                btnPreview.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Loading...';
                fetch(LEGACY_API, { credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (data.preview) {
                            renderMigrationPreview(data.preview);
                            btnRun.disabled = false;
                            btnUsers.disabled = false;
                            btnTypes.disabled = false;
                        }
                    })
                    .catch(function (err) {
                        showAlert('Preview failed: ' + err.message, 'danger');
                    })
                    .then(function () {
                        btnPreview.disabled = false;
                        btnPreview.innerHTML = '<i class="bi bi-eye me-1"></i>Preview Migration';
                    });
            });
        }

        if (btnRun) {
            btnRun.addEventListener('click', function () {
                if (!confirm('Migrate legacy settings into NewUI? Existing settings will NOT be overwritten.')) return;
                runLegacyAction('migrate', 'Settings migration');
            });
        }

        if (btnUsers) {
            btnUsers.addEventListener('click', function () {
                if (!confirm('Import legacy user accounts into NewUI? Existing users will NOT be overwritten. Legacy password hashes are imported as-is.')) return;
                runLegacyAction('migrate_users', 'User import');
            });
        }

        if (btnTypes) {
            btnTypes.addEventListener('click', function () {
                if (!confirm('Import legacy incident types into NewUI? Existing types (by name) will NOT be overwritten.')) return;
                runLegacyAction('migrate_types', 'Incident type import');
            });
        }
    }

    function renderMigrationPreview(preview) {
        var container = document.getElementById('migrationPreview');
        if (!container) return;
        container.style.display = 'block';

        var mapped  = preview.mapped  || [];
        var skipped = preview.skipped || [];

        var html = '';

        // Mapped settings table
        if (mapped.length) {
            html += '<h6 class="mt-2 mb-1"><i class="bi bi-arrow-right-circle me-1 text-primary"></i>Settings to Migrate (' + mapped.length + ')</h6>';
            html += '<div class="table-responsive" style="max-height:300px;overflow-y:auto">';
            html += '<table class="table table-sm table-hover" style="font-size:0.75rem"><thead class="sticky-top">';
            html += '<tr><th>Legacy Key</th><th>NewUI Key</th><th>Value</th><th>Status</th></tr></thead><tbody>';
            for (var i = 0; i < mapped.length; i++) {
                var m = mapped[i];
                var rowClass = m.conflict ? 'table-warning' : '';
                html += '<tr class="' + rowClass + '">';
                html += '<td><code>' + escHtml(m.legacy_key) + '</code></td>';
                html += '<td><code>' + escHtml(m.newui_key) + '</code></td>';
                html += '<td class="text-truncate" style="max-width:200px">' + escHtml(m.value) + '</td>';
                if (m.conflict) {
                    html += '<td><span class="badge bg-warning text-dark">Exists</span></td>';
                } else {
                    html += '<td><span class="badge bg-success">Ready</span></td>';
                }
                html += '</tr>';
            }
            html += '</tbody></table></div>';
        } else {
            html += '<div class="text-body-secondary small">No legacy settings found with known mappings.</div>';
        }

        // Skipped settings
        if (skipped.length) {
            html += '<details class="mt-2"><summary class="small text-body-secondary" style="cursor:pointer">' +
                '<i class="bi bi-skip-forward me-1"></i>Unmapped settings (' + skipped.length + ')</summary>';
            html += '<div class="table-responsive" style="max-height:200px;overflow-y:auto">';
            html += '<table class="table table-sm" style="font-size:0.7rem"><thead><tr><th>Key</th><th>Value</th></tr></thead><tbody>';
            for (var j = 0; j < skipped.length; j++) {
                var s = skipped[j];
                html += '<tr><td><code>' + escHtml(s.legacy_key) + '</code></td>';
                html += '<td class="text-truncate" style="max-width:200px">' + escHtml(s.value) + '</td></tr>';
            }
            html += '</tbody></table></div></details>';
        }

        container.innerHTML = html;
    }

    function runLegacyAction(action, label) {
        var body = { action: action, csrf_token: csrfToken };

        fetch(LEGACY_API, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.error) {
                appendMigrationLog(label + ' failed: ' + data.error, 'danger');
                showAlert(label + ' failed: ' + data.error, 'danger');
                return;
            }

            var msg = label + ' complete: ';
            if (typeof data.inserted !== 'undefined') {
                msg += data.inserted + ' inserted';
                if (data.skipped_conflict) msg += ', ' + data.skipped_conflict + ' skipped (existing)';
                if (data.skipped_unmapped) msg += ', ' + data.skipped_unmapped + ' unmapped';
            } else if (typeof data.imported !== 'undefined') {
                msg += data.imported + ' imported';
                if (data.skipped) msg += ', ' + data.skipped + ' skipped (existing)';
                msg += ' (of ' + data.total + ' total)';
            }

            if (data.errors && data.errors.length) {
                msg += ' — ' + data.errors.length + ' error(s)';
            }

            appendMigrationLog(msg, data.errors && data.errors.length ? 'warning' : 'success');
            showAlert(msg, 'success');

            // Log individual errors
            if (data.errors && data.errors.length) {
                for (var i = 0; i < data.errors.length; i++) {
                    appendMigrationLog('  Error: ' + data.errors[i], 'danger');
                }
            }
        })
        .catch(function (err) {
            appendMigrationLog(label + ' failed: ' + err.message, 'danger');
            showAlert(label + ' failed: ' + err.message, 'danger');
        });
    }

    function appendMigrationLog(message, type) {
        var logSection = document.getElementById('migrationLog');
        var logContent = document.getElementById('migrationLogContent');
        if (!logSection || !logContent) return;

        logSection.style.display = 'block';

        var now = new Date();
        var ts = now.toLocaleTimeString();
        var colorClass = type === 'danger' ? 'text-danger' : (type === 'warning' ? 'text-warning' : 'text-success');

        var entry = document.createElement('div');
        entry.className = 'mb-1';
        entry.innerHTML = '<span class="text-body-secondary">[' + ts + ']</span> <span class="' + colorClass + '">' + escHtml(message) + '</span>';
        logContent.appendChild(entry);
        logContent.scrollTop = logContent.scrollHeight;
    }

    function loadStdMessages() {
        var el = document.getElementById('stdMessagesContent');
        if (!el) return;
        fetch('api/config-admin.php?section=codes', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var codes = data.codes || [];
                if (codes.length === 0) {
                    el.innerHTML = '<div class="text-body-secondary small text-center py-2">No standard messages defined.</div>' +
                        '<button class="btn btn-sm btn-outline-primary" id="btnAddStdMsg"><i class="bi bi-plus-lg me-1"></i>Add Message</button>';
                    return;
                }
                var html = '<table class="table table-sm table-hover"><thead><tr><th>Code</th><th>Message</th><th>Actions</th></tr></thead><tbody>';
                for (var i = 0; i < codes.length; i++) {
                    var c = codes[i];
                    html += '<tr><td><code>' + escHtml(c.code) + '</code></td>';
                    html += '<td>' + escHtml(c.text) + '</td>';
                    html += '<td><button class="btn btn-xs btn-outline-danger" title="Delete"><i class="bi bi-x-lg"></i></button></td></tr>';
                }
                html += '</tbody></table>';
                html += '<button class="btn btn-sm btn-outline-primary mt-1" id="btnAddStdMsg"><i class="bi bi-plus-lg me-1"></i>Add Message</button>';
                el.innerHTML = html;
            })
            .catch(function (err) {
                el.innerHTML = '<div class="alert alert-danger small">Failed to load: ' + escHtml(err.message) + '</div>';
            });
    }

    function loadRegions() {
        var el = document.getElementById('regionsContent');
        if (!el) return;
        fetch('api/config-admin.php?section=regions', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var regions = data.regions || [];
                var categories = data.categories || [];

                // Category <select> options — reused for both the Add
                // form and inline edit swaps.
                var catOptions = '<option value="">— none —</option>';
                for (var c = 0; c < categories.length; c++) {
                    catOptions += '<option value="' + categories[c].id + '">' +
                                  escHtml(categories[c].name) + '</option>';
                }

                // Add/edit form (mirrors ICS Positions layout).
                var html = '<div class="card border-0 bg-body-tertiary mb-3"><div class="card-body py-2">' +
                    '<div class="row g-2 align-items-end">' +
                    '<div class="col-md-3"><label class="form-label form-label-sm mb-0">Name</label>' +
                    '<input type="text" class="form-control form-control-sm" id="regionName" placeholder="e.g. North District"></div>' +
                    '<div class="col-md-2"><label class="form-label form-label-sm mb-0">Category</label>' +
                    '<select class="form-select form-select-sm" id="regionCategory">' + catOptions + '</select></div>' +
                    '<div class="col-md-1"><label class="form-label form-label-sm mb-0">Area code</label>' +
                    '<input type="text" class="form-control form-control-sm" id="regionAreaCode" maxlength="4" placeholder="e.g. 651"></div>' +
                    '<div class="col-md-2"><label class="form-label form-label-sm mb-0">Default city</label>' +
                    '<input type="text" class="form-control form-control-sm" id="regionCity"></div>' +
                    '<div class="col-md-1"><label class="form-label form-label-sm mb-0">State</label>' +
                    '<input type="text" class="form-control form-control-sm" id="regionState" maxlength="20"></div>' +
                    '<div class="col-md-auto"><button type="button" class="btn btn-sm btn-primary" id="btnAddRegion">' +
                    '<i class="bi bi-plus-lg me-1"></i>Add</button></div>' +
                    '</div>' +
                    '<div class="row g-2 mt-1">' +
                    '<div class="col-md-3"><label class="form-label form-label-sm mb-0">Description</label>' +
                    '<input type="text" class="form-control form-control-sm" id="regionDescription"></div>' +
                    '<div class="col-md-2"><label class="form-label form-label-sm mb-0">Default lat</label>' +
                    '<input type="number" step="any" class="form-control form-control-sm" id="regionLat"></div>' +
                    '<div class="col-md-2"><label class="form-label form-label-sm mb-0">Default lng</label>' +
                    '<input type="number" step="any" class="form-control form-control-sm" id="regionLng"></div>' +
                    '<div class="col-md-1"><label class="form-label form-label-sm mb-0">Zoom</label>' +
                    '<input type="number" class="form-control form-control-sm" id="regionZoom" min="1" max="20" value="10"></div>' +
                    '</div>' +
                    '<input type="hidden" id="regionEditId" value="0">' +
                    '</div></div>';

                // M1 in code review 2026-07-03: store rows in a
                // closure-scoped id→row map so the edit button only
                // needs data-id=42, not the entire JSON-stringified
                // row embedded in the DOM. That was fragile against
                // apostrophes/quotes, bloated the payload, and made
                // any subsequent field-shape change require a JS
                // deploy in lock-step with the API.
                var regionsById = {};
                // Table
                if (regions.length === 0) {
                    html += '<div class="text-body-secondary small text-center py-2">No regions defined. Add one above.</div>';
                } else {
                    html += '<div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">' +
                        '<thead><tr><th>Name</th><th>Category</th><th>City</th><th>State</th><th>Area code</th>' +
                        '<th class="text-center" style="width:90px;">Actions</th></tr></thead><tbody>';
                    for (var i = 0; i < regions.length; i++) {
                        var r = regions[i];
                        regionsById[r.id] = r;
                        html += '<tr>' +
                            '<td class="fw-semibold">' + escHtml(r.group_name || '') +
                            (r.description ? '<br><small class="text-body-secondary">' + escHtml(r.description) + '</small>' : '') + '</td>' +
                            '<td>' + escHtml(r.category_name || '') + '</td>' +
                            '<td>' + escHtml(r.def_city || '') + '</td>' +
                            '<td>' + escHtml(r.def_st || '') + '</td>' +
                            '<td>' + escHtml(r.def_area_code || '') + '</td>' +
                            '<td class="text-center">' +
                                '<button class="btn btn-sm btn-link p-0 me-1 region-edit-btn" data-id="' + r.id + '" title="Edit"><i class="bi bi-pencil text-primary"></i></button>' +
                                '<button class="btn btn-sm btn-link p-0 region-del-btn" data-id="' + r.id + '" data-name="' + escHtml(r.group_name || '') + '" title="Delete"><i class="bi bi-trash text-danger"></i></button>' +
                            '</td></tr>';
                    }
                    html += '</tbody></table></div>';
                }

                el.innerHTML = html;

                // Wire Add/Update
                var btnAdd = document.getElementById('btnAddRegion');
                if (btnAdd) {
                    btnAdd.addEventListener('click', function () {
                        var name = (document.getElementById('regionName').value || '').trim();
                        if (!name) { showAlert('Region name is required.', 'warning'); return; }
                        var payload = {
                            action:         'save',
                            id:             parseInt(document.getElementById('regionEditId').value, 10) || 0,
                            group_name:     name,
                            category:       document.getElementById('regionCategory').value || '',
                            description:    (document.getElementById('regionDescription').value || '').trim(),
                            def_area_code:  (document.getElementById('regionAreaCode').value || '').trim(),
                            def_city:       (document.getElementById('regionCity').value || '').trim(),
                            def_st:         (document.getElementById('regionState').value || '').trim(),
                            def_lat:        document.getElementById('regionLat').value,
                            def_lng:        document.getElementById('regionLng').value,
                            def_zoom:       document.getElementById('regionZoom').value
                        };
                        fetchJSON('api/config-admin.php?section=regions', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify(payload)
                        }).then(function () {
                            loadRegions();
                        }).catch(function (err) {
                            showAlert('Save failed: ' + err.message, 'danger');
                        });
                    });
                }
                // Edit — look row up in the closure map by id.
                var editBtns = el.querySelectorAll('.region-edit-btn');
                for (var e2 = 0; e2 < editBtns.length; e2++) {
                    (function (btn) {
                        btn.addEventListener('click', function () {
                            var row = regionsById[btn.getAttribute('data-id')];
                            if (!row) return;
                            document.getElementById('regionEditId').value    = row.id;
                            document.getElementById('regionName').value      = row.group_name || '';
                            document.getElementById('regionCategory').value  = row.category || '';
                            document.getElementById('regionDescription').value = row.description || '';
                            document.getElementById('regionAreaCode').value  = row.def_area_code || '';
                            document.getElementById('regionCity').value      = row.def_city || '';
                            document.getElementById('regionState').value     = row.def_st || '';
                            document.getElementById('regionLat').value       = row.def_lat || '';
                            document.getElementById('regionLng').value       = row.def_lng || '';
                            document.getElementById('regionZoom').value      = row.def_zoom || 10;
                            document.getElementById('btnAddRegion').innerHTML = '<i class="bi bi-check-lg me-1"></i>Update';
                        });
                    })(editBtns[e2]);
                }
                // Delete
                var delBtns = el.querySelectorAll('.region-del-btn');
                for (var d2 = 0; d2 < delBtns.length; d2++) {
                    (function (btn) {
                        btn.addEventListener('click', function () {
                            if (!confirm('Delete region "' + btn.getAttribute('data-name') + '"?')) return;
                            fetchJSON('api/config-admin.php?section=regions', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({ action: 'delete', id: parseInt(btn.getAttribute('data-id'), 10) })
                            }).then(function () {
                                loadRegions();
                            }).catch(function (err) {
                                showAlert('Delete failed: ' + err.message, 'danger');
                            });
                        });
                    })(delBtns[d2]);
                }
            })
            .catch(function (err) {
                el.innerHTML = '<div class="alert alert-danger small">Failed to load: ' + escHtml(err.message) + '</div>';
            });
    }

    // ═══════════════════════════════════════════════════════════════
    //  INCIDENT NUMBERS
    // ═══════════════════════════════════════════════════════════════
    // Phase 15 (2026-06-11) \u2014 template-driven incident numbers.
    // JS-side replicas of inc/incident-number.php for live-preview ONLY;
    // the server is authoritative at incident-create time. Keep these
    // two implementations behaviorally identical.
    var INCNUM_DATE_TOKENS_JS = {
        'YYYY': function (d) { return String(d.getFullYear()); },
        'YY':   function (d) { return ('0' + (d.getFullYear() % 100)).slice(-2); },
        'MM':   function (d) { return ('0' + (d.getMonth() + 1)).slice(-2); },
        'DD':   function (d) { return ('0' + d.getDate()).slice(-2); },
        'HH':   function (d) { return ('0' + d.getHours()).slice(-2); },
        'JJJ':  function (d) {
            var start = new Date(d.getFullYear(), 0, 0);
            var diff = (d - start) + ((start.getTimezoneOffset() - d.getTimezoneOffset()) * 60 * 1000);
            var doy = Math.floor(diff / 86400000);
            return ('00' + doy).slice(-3);
        },
        'UU': function (d) {
            var target = new Date(d.valueOf());
            var dayNr = (d.getDay() + 6) % 7;
            target.setDate(target.getDate() - dayNr + 3);
            var firstThursday = target.valueOf();
            target.setMonth(0, 1);
            if (target.getDay() !== 4) {
                target.setMonth(0, 1 + ((4 - target.getDay()) + 7) % 7);
            }
            var wk = 1 + Math.ceil((firstThursday - target) / 604800000);
            return ('0' + wk).slice(-2);
        }
    };

    function incnumRenderJs(template, sequence, dateObj) {
        if (!dateObj) dateObj = new Date();
        var SENT_OPEN  = '\x01ESC_OPEN\x01';
        var SENT_CLOSE = '\x01ESC_CLOSE\x01';
        var SENT_BACK  = '\x01ESC_BACK\x01';
        var work = template.split('\\\\').join(SENT_BACK)
                           .split('\\{').join(SENT_OPEN)
                           .split('\\}').join(SENT_CLOSE);
        work = work.replace(/\{([^{}]*)\}/g, function (m, body) {
            if (INCNUM_DATE_TOKENS_JS.hasOwnProperty(body)) {
                return INCNUM_DATE_TOKENS_JS[body](dateObj);
            }
            if (/^N+$/.test(body) || /^0+$/.test(body)) {
                var width = body.length;
                var s = String(sequence);
                while (s.length < width) s = '0' + s;
                return s;
            }
            return m; // unknown token left literal
        });
        return work.split(SENT_OPEN).join('{')
                   .split(SENT_CLOSE).join('}')
                   .split(SENT_BACK).join('\\');
    }

    function incnumValidateJs(template) {
        var hasSeq = false;
        var warnings = [];
        var work = template.split('\\{').join('').split('\\}').join('').split('\\\\').join('');
        var matches = work.match(/\{([^{}]*)\}/g) || [];
        for (var i = 0; i < matches.length; i++) {
            var body = matches[i].slice(1, -1);
            if (INCNUM_DATE_TOKENS_JS.hasOwnProperty(body)) continue;
            if (/^N+$/.test(body) || /^0+$/.test(body)) { hasSeq = true; continue; }
            warnings.push('Unknown token: {' + body + '} \u2014 will render as literal text.');
        }
        if (!hasSeq) {
            return { valid: false, error: 'Template must contain at least one sequence token like {NNNN} or {0000}.' };
        }
        return { valid: true, warnings: warnings };
    }

    // Mirror inc/incident-number.php's incnum_suggest_reset_mode().
    function incnumSuggestResetModeJs(template) {
        if (template.indexOf('{DD}') !== -1 || template.indexOf('{JJJ}') !== -1) return 'daily';
        if (template.indexOf('{MM}') !== -1) return 'monthly';
        if (template.indexOf('{YY}') !== -1 || template.indexOf('{YYYY}') !== -1) return 'yearly';
        return 'never';
    }

    function bindIncidentNumbersPanel() {
        var form = document.getElementById('incidentNumbersForm');
        if (!form) return;
        var tplInput   = document.getElementById('incNumTemplate');
        var nextInput  = document.getElementById('incNumNext');
        var resetSel   = document.getElementById('incNumResetMode');
        var resetHint  = document.getElementById('incNumResetHint');
        var previewEl  = document.getElementById('incNumPreview');
        var msgEl      = document.getElementById('incNumValidationMsg');
        if (!tplInput || !nextInput || !previewEl) return;

        // Track whether the admin has manually overridden the reset
        // mode. If yes, we stop auto-suggesting. If no, the dropdown
        // follows the template's date-token shape.
        // Stored on the form element so loadIncidentNumbers can flip
        // it to "true" after loading saved settings (the saved value
        // IS the admin's chosen state — never auto-overwrite it).
        form._incNumResetUserOverride = false;
        if (resetSel) {
            resetSel.addEventListener('change', function () {
                form._incNumResetUserOverride = true;
                updateResetHint();
            });
        }

        function updateResetHint() {
            if (!resetSel || !resetHint) return;
            var mode = resetSel.value;
            var nextPeriodReset = '';
            var d = new Date();
            switch (mode) {
                case 'yearly':
                    nextPeriodReset = 'Counter resets to 1 on January 1, ' + (d.getFullYear() + 1) + '.';
                    break;
                case 'monthly':
                    nextPeriodReset = 'Counter resets to 1 on the first day of each month.';
                    break;
                case 'daily':
                    nextPeriodReset = 'Counter resets to 1 at midnight every day.';
                    break;
                case 'never':
                    nextPeriodReset = 'Counter increments forever, never resets.';
                    break;
            }
            resetHint.textContent = nextPeriodReset;
        }

        function updatePreview() {
            var template = tplInput.value || '{YY}-{NNNN}';
            var next     = parseInt(nextInput.value, 10) || 1;
            previewEl.textContent = incnumRenderJs(template, next);

            var v = incnumValidateJs(template);
            if (!v.valid) {
                msgEl.className = 'form-text text-danger';
                msgEl.textContent = v.error;
                tplInput.classList.add('is-invalid');
            } else if (v.warnings && v.warnings.length) {
                msgEl.className = 'form-text text-warning';
                msgEl.textContent = v.warnings.join(' ');
                tplInput.classList.remove('is-invalid');
            } else {
                msgEl.className = 'form-text text-success';
                msgEl.textContent = 'Template is valid.';
                tplInput.classList.remove('is-invalid');
            }

            // Smart-suggest reset mode only until the admin overrides.
            if (resetSel && !form._incNumResetUserOverride) {
                var suggested = incnumSuggestResetModeJs(template);
                if (resetSel.value !== suggested) {
                    resetSel.value = suggested;
                    updateResetHint();
                }
            }
        }

        // Phase 15c (2026-06-11) — debounced collision check.
        // When the admin types a template or sequence value, we ask
        // the server "would this number collide with any existing
        // ticket?" and surface a warning if so. Debounced so we
        // don't flood the API as they type.
        var collisionTimer = null;
        var warnBox = document.getElementById('incNumCollisionWarning');
        var warnMsg = document.getElementById('incNumCollisionMsg');
        function hideCollisionWarning() {
            if (warnBox) warnBox.classList.add('d-none');
        }
        function checkCollisionLater() {
            if (collisionTimer) clearTimeout(collisionTimer);
            hideCollisionWarning();
            collisionTimer = setTimeout(function () {
                var template = tplInput.value || '{YY}-{NNNN}';
                var seq = parseInt(nextInput.value, 10) || 1;
                var v = incnumValidateJs(template);
                if (!v.valid) return;  // don't query for known-bad templates
                var qs = '?section=incident_numbers&check=1' +
                    '&template=' + encodeURIComponent(template) +
                    '&next_number=' + encodeURIComponent(seq);
                fetch('api/config-admin.php' + qs, { credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        var c = data.check || {};
                        if (!c.collision || !warnBox || !warnMsg) {
                            hideCollisionWarning();
                            return;
                        }
                        var msg;
                        if (c.next_safe_seq && c.next_safe_seq !== seq) {
                            msg = 'The number "' + c.rendered + '" is already in use by incident #' +
                                (c.existing_ticket || '?') + '. On save, the next allocation will ' +
                                'automatically advance to sequence ' + c.next_safe_seq + ' to find a free slot.';
                        } else {
                            msg = 'The number "' + c.rendered + '" is already in use by incident #' +
                                (c.existing_ticket || '?') + ', and the template has no path to a free slot. ' +
                                'Change the template OR change "Next sequence" before saving.';
                        }
                        warnMsg.textContent = msg;
                        warnBox.classList.remove('d-none');
                    })
                    .catch(function () { hideCollisionWarning(); });
            }, 500);
        }

        tplInput.addEventListener('input', function () { updatePreview(); checkCollisionLater(); });
        nextInput.addEventListener('input', function () { updatePreview(); checkCollisionLater(); });
        if (resetSel) resetSel.addEventListener('change', checkCollisionLater);

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var template = tplInput.value || '{YY}-{NNNN}';
            var v = incnumValidateJs(template);
            if (!v.valid) { showAlert(v.error, 'danger'); return; }
            // Phase 99o (Eric beta 2026-06-29): include the
            // configurable label in the save payload.
            var labelInput = document.getElementById('incNumLabel');
            apiPost('incident_numbers', {
                template:    template,
                next_number: parseInt(nextInput.value, 10) || 1,
                reset_mode:  resetSel ? resetSel.value : 'yearly',
                label:       labelInput ? (labelInput.value || '').trim() : ''
            }).then(function () {
                showAlert('Incident number settings saved');
            }).catch(function (err) {
                showAlert(err.message, 'danger');
            });
        });
    }

    function loadIncidentNumbers() {
        apiGet('incident_numbers').then(function (data) {
            var cfg = data.config || {};
            var tplInput  = document.getElementById('incNumTemplate');
            var nextInput = document.getElementById('incNumNext');
            var resetSel  = document.getElementById('incNumResetMode');
            var labelInput = document.getElementById('incNumLabel');
            if (tplInput)  tplInput.value  = cfg.template    || '{YY}-{NNNN}';
            if (nextInput) nextInput.value = cfg.next_number || 1;
            if (labelInput) labelInput.value = cfg.label || 'Incident';
            // The reset_mode loaded from the server is the admin's
            // saved choice — flip the override flag BEFORE dispatching
            // the synthetic input event so the suggester doesn't
            // overwrite it on first render.
            var form = document.getElementById('incidentNumbersForm');
            if (form) form._incNumResetUserOverride = true;
            if (resetSel && cfg.reset_mode) resetSel.value = cfg.reset_mode;
            var ev = document.createEvent('Event');
            ev.initEvent('input', true, true);
            if (tplInput) tplInput.dispatchEvent(ev);
        }).catch(function (err) {
            showAlert('Failed to load incident number settings: ' + err.message, 'danger');
        });
    }

    // ── Login Settings ──
    function loadLoginSettings() {
        var form = document.getElementById('loginSettingsForm');
        if (!form) return;
        apiGet('settings').then(function (data) {
            var s = data.settings || {};
            applySettingsToForm(form, s);
            // Handle checkboxes
            var ul = form.querySelector('[data-key="login_userlist"]');
            if (ul) ul.checked = s.login_userlist === '1';
            var rh = form.querySelector('[data-key="require_https"]');
            if (rh) rh.checked = s.require_https === '1';
            // Phase 9: force-pw-change system toggle
            var fpw = form.querySelector('[data-key="force_pw_change_for_new_users"]');
            if (fpw) fpw.checked = s.force_pw_change_for_new_users === '1';
            // Phase 99i (Billy beta 2026-06-29) — CJIS notice toggle
            var cjis = form.querySelector('[data-key="cjis_login_notice_enabled"]');
            if (cjis) cjis.checked = s.cjis_login_notice_enabled === '1';
            // (notice TEXT is a textarea — applySettingsToForm covers it
            //  already via the data-key dispatcher above.)

            // Phase 10: cache the live settings for cross-form use
            // (e.g. the User Accounts form reads force_pw_change_for_new_users).
            cache.loginSettings = s;

            // Phase 10: trigger CJIS warning visibility on initial load.
            updateCjisWarnings();
        });
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var pairs = collectSettingsFromForm(form);
            // Checkboxes
            var ul = form.querySelector('[data-key="login_userlist"]');
            if (ul) pairs.login_userlist = ul.checked ? '1' : '0';
            var rh = form.querySelector('[data-key="require_https"]');
            if (rh) pairs.require_https = rh.checked ? '1' : '0';
            var fpw = form.querySelector('[data-key="force_pw_change_for_new_users"]');
            if (fpw) pairs.force_pw_change_for_new_users = fpw.checked ? '1' : '0';
            var cjis = form.querySelector('[data-key="cjis_login_notice_enabled"]');
            if (cjis) pairs.cjis_login_notice_enabled = cjis.checked ? '1' : '0';
            apiPost('settings', { settings: pairs }).then(function (data) {
                showAlert('Login settings saved (' + data.saved + ' updated).');
                // Refresh local cache so subsequent reads see new values.
                cache.loginSettings = Object.assign(cache.loginSettings || {}, pairs);
            }).catch(function (err) { showAlert(err.message, 'danger'); });
        });

        // Phase 10: live CJIS warning on the policy inputs.
        // Each input with class pw-policy-input has data-cjis-min; if the
        // current value is below that minimum, show the matching
        // .cjis-warn element. Toggles on every input event so the admin
        // sees the warning the moment they type 6.
        var policyInputs = form.querySelectorAll('.pw-policy-input');
        for (var i = 0; i < policyInputs.length; i++) {
            policyInputs[i].addEventListener('input', updateCjisWarnings);
        }

        // Phase 10: wire the admin password-reset form.
        wireAdminResetPwForm();

        // Phase 37: load per-role session timeouts grid + wire its save button.
        loadRoleTimeouts();

        // Load active sessions
        loadActiveSessions();
        var btnRefreshSessions = document.getElementById('btnRefreshSessions');
        if (btnRefreshSessions) {
            btnRefreshSessions.addEventListener('click', loadActiveSessions);
        }

        // Load login attempts
        loadLoginAttempts();
        var btnRefreshAttempts = document.getElementById('btnRefreshAttempts');
        if (btnRefreshAttempts) {
            btnRefreshAttempts.addEventListener('click', loadLoginAttempts);
        }
    }

    /*
     * Phase 10 helper — toggle the .cjis-warn elements based on the
     * current pw-policy-input values. Each input declares data-cjis-min
     * (the recommended minimum); if the entered value is below it, show
     * the matching warning element next to it. Ids must match the
     * convention: input #setPwMinLength → warning #warnPwMinLength.
     */
    function updateCjisWarnings() {
        var inputs = document.querySelectorAll('.pw-policy-input');
        for (var i = 0; i < inputs.length; i++) {
            var input = inputs[i];
            var warnId = input.id.replace(/^set/, 'warn');
            var warnEl = document.getElementById(warnId);
            if (!warnEl) continue;
            var min = parseInt(input.getAttribute('data-cjis-min') || '0', 10);
            var val = parseInt(input.value, 10);
            // Show the warning if the value is a number AND below the
            // CJIS recommendation. Empty / NaN input → hide (don't nag
            // while admin is mid-type).
            if (!isNaN(val) && val < min) {
                warnEl.classList.remove('d-none');
            } else {
                warnEl.classList.add('d-none');
            }
        }
    }

    /*
     * Phase 10 — wire the Admin Password Reset form in Login Settings.
     * Populates the user dropdown from /api/config-admin.php?section=users
     * and POSTs to /api/login-security.php?action=reset_password with the
     * required reason field. Refuses to submit if reason is empty.
     */
    function wireAdminResetPwForm() {
        var form = document.getElementById('adminResetPwForm');
        if (!form) return;

        // Populate user dropdown.
        fetch('api/config-admin.php?section=users', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var sel = document.getElementById('adminResetUserId');
                if (!sel) return;
                var rows = data.rows || [];
                rows.sort(function (a, b) {
                    var au = (a.user || '').toLowerCase();
                    var bu = (b.user || '').toLowerCase();
                    return au < bu ? -1 : (au > bu ? 1 : 0);
                });
                var html = sel.querySelector('option[value=""]').outerHTML;
                for (var i = 0; i < rows.length; i++) {
                    html += '<option value="' + rows[i].id + '">' +
                        escapeHtml(rows[i].user) + '</option>';
                }
                sel.innerHTML = html;
            })
            .catch(function () { /* leave empty — admin can refresh */ });

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var userId = parseInt(document.getElementById('adminResetUserId').value, 10);
            var newPw = document.getElementById('adminResetNewPw').value || '';
            var reason = (document.getElementById('adminResetReason').value || '').trim();
            if (!userId) { showAlert('Pick a user', 'warning'); return; }
            if (!newPw) { showAlert('New password required', 'warning'); return; }
            if (reason.length < 3) {
                showAlert('A reason is required (minimum 3 characters) for CJIS audit trail.', 'warning');
                return;
            }
            // Confirm — this is a destructive action.
            var userLabel = document.getElementById('adminResetUserId').selectedOptions[0].text;
            if (!confirm('Reset password for "' + userLabel + '"?\n\n' +
                         'They will be required to change it on next login. ' +
                         'All their existing sessions will be terminated.')) {
                return;
            }

            var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
            fetch('api/login-security.php?action=reset_password', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                body: JSON.stringify({
                    user_id: userId,
                    new_password: newPw,
                    reason: reason,
                    csrf_token: csrf
                })
            })
            .then(function (r) { return r.json().then(function (j) { return { status: r.status, body: j }; }); })
            .then(function (res) {
                if (res.status === 200 && res.body.success) {
                    showAlert(res.body.message || 'Password reset.');
                    form.reset();
                } else {
                    showAlert((res.body && res.body.error) || 'Reset failed', 'danger');
                }
            })
            .catch(function (err) {
                showAlert('Reset failed: ' + (err && err.message ? err.message : 'network error'), 'danger');
            });
        });
    }

    function escapeHtml(s) {
        if (s === null || s === undefined) return '';
        return String(s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    // Phase 37: Per-Role Session Timeouts grid — driven by /api/role-timeouts.php.
    function loadRoleTimeouts() {
        var grid = document.getElementById('roleTimeoutsGrid');
        var btn  = document.getElementById('btnSaveRoleTimeouts');
        var status = document.getElementById('roleTimeoutsStatus');
        if (!grid || !btn) return;

        fetch('api/role-timeouts.php?action=list', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || data.error) {
                    grid.innerHTML = '<div class="col-12 text-danger small">' + escHtml(data && data.error || 'load failed') + '</div>';
                    return;
                }
                var html = '';
                (data.roles || []).forEach(function (r) {
                    var v = r.session_timeout_minutes == null ? '' : r.session_timeout_minutes;
                    html +=
                        '<div class="col-md-3 col-sm-4 col-6">' +
                        '  <label class="form-label form-label-sm" title="' + escHtml(r.description || '') + '">' + escHtml(r.name) + '</label>' +
                        '  <div class="input-group input-group-sm">' +
                        '    <input type="number" class="form-control role-timeout" min="0" max="14400" placeholder="inherit" data-role-id="' + r.id + '" value="' + escHtml(v) + '">' +
                        '    <span class="input-group-text">min</span>' +
                        '  </div>' +
                        '</div>';
                });
                grid.innerHTML = html || '<div class="col-12 text-body-secondary small">No roles defined.</div>';
            })
            .catch(function (e) {
                grid.innerHTML = '<div class="col-12 text-danger small">' + escHtml(e.message) + '</div>';
            });

        // Idempotent — don't double-wire on tab re-open
        if (btn._phase37Wired) return;
        btn._phase37Wired = true;
        btn.addEventListener('click', function () {
            var items = [];
            grid.querySelectorAll('input.role-timeout').forEach(function (inp) {
                items.push({
                    role_id: parseInt(inp.getAttribute('data-role-id'), 10),
                    minutes: inp.value === '' ? null : parseInt(inp.value, 10)
                });
            });
            var token = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
            fetch('api/role-timeouts.php?action=save', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ csrf_token: token, items: items })
            }).then(function (r) { return r.json(); })
              .then(function (data) {
                if (data && data.error) { status.textContent = data.error; status.className = 'ms-2 small text-danger'; return; }
                status.textContent = 'Saved (' + (data.updated || 0) + ' roles).';
                status.className = 'ms-2 small text-success';
                setTimeout(function () { status.textContent = ''; }, 4000);
              })
              .catch(function (e) {
                status.textContent = e.message;
                status.className = 'ms-2 small text-danger';
              });
        });
    }

    function loadActiveSessions() {
        var el = document.getElementById('activeSessionsContent');
        if (!el) return;
        el.innerHTML = '<div class="text-center py-2"><div class="spinner-border spinner-border-sm"></div></div>';

        fetch('api/login-security.php?action=sessions', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var sessions = data.sessions || [];
                if (sessions.length === 0) {
                    el.innerHTML = '<div class="text-body-secondary small text-center py-2">No active sessions.</div>';
                    return;
                }
                var html = '<table class="table table-sm table-hover mb-0" style="font-size:0.8rem">';
                html += '<thead><tr><th>User</th><th>IP Address</th><th>Last Active</th><th>Logged In</th><th>Expires</th><th></th></tr></thead><tbody>';
                for (var i = 0; i < sessions.length; i++) {
                    var s = sessions[i];
                    var uaShort = (s.user_agent || '').substring(0, 40);
                    html += '<tr>';
                    html += '<td class="fw-semibold">' + escHtml(s.username || 'User #' + s.user_id) + '</td>';
                    html += '<td><code>' + escHtml(s.ip_address || '-') + '</code></td>';
                    html += '<td>' + escHtml(s.last_active || '-') + '</td>';
                    html += '<td>' + escHtml(s.created_at || '-') + '</td>';
                    html += '<td>' + escHtml(s.expires_at || '-') + '</td>';
                    html += '<td><button class="btn btn-sm btn-outline-danger py-0 px-1" data-session="' + escHtml(s.session_id) + '" title="Force Logout">';
                    html += '<i class="bi bi-box-arrow-right"></i></button></td>';
                    html += '</tr>';
                }
                html += '</tbody></table>';
                el.innerHTML = html;

                // Attach force-logout handlers
                el.querySelectorAll('[data-session]').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        var sid = this.getAttribute('data-session');
                        if (!confirm('Force logout this session?')) return;
                        fetch('api/login-security.php?action=force_logout', {
                            method: 'POST',
                            credentials: 'same-origin',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ session_id: sid, csrf_token: csrfToken })
                        })
                        .then(function (r) { return r.json(); })
                        .then(function (resp) {
                            if (resp.error) {
                                showAlert(resp.error, 'danger');
                            } else {
                                showAlert('Session terminated.');
                                loadActiveSessions();
                            }
                        })
                        .catch(function (err) { showAlert(err.message, 'danger'); });
                    });
                });
            })
            .catch(function (err) {
                el.innerHTML = '<div class="text-body-secondary small">Could not load sessions: ' + escHtml(err.message) + '</div>';
            });
    }

    function loadLoginAttempts() {
        var el = document.getElementById('loginAttemptsContent');
        if (!el) return;
        el.innerHTML = '<div class="text-center py-2"><div class="spinner-border spinner-border-sm"></div></div>';

        fetch('api/login-security.php?action=attempts&limit=50', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var attempts = data.attempts || [];
                if (attempts.length === 0) {
                    el.innerHTML = '<div class="text-body-secondary small text-center py-2">No recent login attempts.</div>';
                    return;
                }
                var html = '<table class="table table-sm table-hover mb-0" style="font-size:0.8rem">';
                html += '<thead><tr><th>Username</th><th>IP</th><th>Result</th><th>Reason</th><th>Time</th></tr></thead><tbody>';
                for (var i = 0; i < attempts.length; i++) {
                    var a = attempts[i];
                    var resultBadge = a.success === 1 || a.success === '1'
                        ? '<span class="badge bg-success">OK</span>'
                        : '<span class="badge bg-danger">FAIL</span>';
                    html += '<tr>';
                    html += '<td class="fw-semibold">' + escHtml(a.username || '-') + '</td>';
                    html += '<td><code>' + escHtml(a.ip_address || '-') + '</code></td>';
                    html += '<td>' + resultBadge + '</td>';
                    html += '<td>' + escHtml(a.failure_reason || '-') + '</td>';
                    html += '<td>' + escHtml(a.created_at || '-') + '</td>';
                    html += '</tr>';
                }
                html += '</tbody></table>';
                el.innerHTML = html;
            })
            .catch(function (err) {
                el.innerHTML = '<div class="text-body-secondary small">Could not load attempts: ' + escHtml(err.message) + '</div>';
            });
    }

    // ── Field Encryption ──
    function loadFieldEncryption() {
        var form = document.getElementById('fieldEncryptForm');
        if (!form) return;

        var toggleEl = document.getElementById('setFieldEncrypt');
        var httpsStatus = document.getElementById('feHttpsStatus');
        var keyStatus = document.getElementById('feKeyStatus');
        var keyCreated = document.getElementById('feKeyCreated');
        var regenBtn = document.getElementById('btnRegenKeys');

        // Load current status
        apiGet('field-encryption').then(function (data) {
            // Toggle
            if (toggleEl) toggleEl.checked = data.enabled;

            // HTTPS badge
            if (httpsStatus) {
                if (data.https) {
                    httpsStatus.className = 'badge bg-success';
                    httpsStatus.textContent = 'Active';
                } else {
                    httpsStatus.className = 'badge bg-warning text-dark';
                    httpsStatus.textContent = 'Not detected';
                }
            }

            // Key status badge
            if (keyStatus) {
                if (data.keys && data.keys.exists && data.keys.valid) {
                    keyStatus.className = 'badge bg-success';
                    keyStatus.textContent = 'Valid';
                } else if (data.keys && data.keys.exists) {
                    keyStatus.className = 'badge bg-danger';
                    keyStatus.textContent = 'Invalid';
                } else {
                    keyStatus.className = 'badge bg-secondary';
                    keyStatus.textContent = 'Not generated';
                }
            }

            // Key created date
            if (keyCreated && data.keys && data.keys.created) {
                keyCreated.textContent = 'Generated: ' + data.keys.created;
            }
        }).catch(function (err) {
            showAlert('Could not load encryption status: ' + err.message, 'danger');
        });

        // Save toggle
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var body = {
                action: 'save',
                enabled: toggleEl ? toggleEl.checked : false,
                csrf_token: csrfToken
            };
            fetchJSON(API + '?section=field-encryption', {
                method: 'POST',
                body: JSON.stringify(body)
            }).then(function (data) {
                showAlert('Field encryption settings saved.');
            }).catch(function (err) {
                showAlert(err.message, 'danger');
            });
        });

        // Regenerate keys
        if (regenBtn) {
            regenBtn.addEventListener('click', function () {
                if (!confirm('Regenerate RSA keys? Any data encrypted with the old keys will not be decryptable.')) {
                    return;
                }
                var body = {
                    action: 'regenerate',
                    csrf_token: csrfToken
                };
                fetchJSON(API + '?section=field-encryption', {
                    method: 'POST',
                    body: JSON.stringify(body)
                }).then(function (data) {
                    showAlert('RSA keys regenerated successfully.');
                    // Reload status
                    loadFieldEncryption();
                }).catch(function (err) {
                    showAlert('Key regeneration failed: ' + err.message, 'danger');
                });
            });
        }
    }

    // ── Email Config ──
    // ════════════════════════════════════════════════════════════════
    //  PHASE 41 — EMAIL DISTRIBUTION LISTS
    // ════════════════════════════════════════════════════════════════
    var emailListsCache = [];
    function loadEmailLists() {
        var body = document.getElementById('emailListsBody');
        if (!body) return;
        body.innerHTML = '<div class="text-body-secondary p-3 small">Loading…</div>';
        fetch('api/email-lists.php?action=list', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || data.error) {
                    body.innerHTML = '<div class="text-danger p-3 small">' + escapeHtml(data && data.error || 'load failed') + '</div>';
                    return;
                }
                emailListsCache = data.lists || [];
                renderEmailLists();
            });
        // Idempotent wire-up of header buttons
        var btnNew = document.getElementById('btnNewEmailList');
        if (btnNew && !btnNew._phase41) {
            btnNew._phase41 = true;
            btnNew.addEventListener('click', openNewEmailListPrompt);
        }
        var btnImport = document.getElementById('btnImportEmailList');
        if (btnImport && !btnImport._phase41) {
            btnImport._phase41 = true;
            btnImport.addEventListener('click', openEmailListImportPrompt);
        }
        var filter = document.getElementById('emailListFilter');
        if (filter && !filter._phase41) {
            filter._phase41 = true;
            filter.addEventListener('input', renderEmailLists);
        }
    }

    function renderEmailLists() {
        var body = document.getElementById('emailListsBody');
        if (!body) return;
        var q = ((document.getElementById('emailListFilter') || {}).value || '').toLowerCase();
        var lists = q ? emailListsCache.filter(function (l) { return (l.name + ' ' + (l.slug||'') + ' ' + (l.description||'')).toLowerCase().indexOf(q) !== -1; }) : emailListsCache;
        if (!lists.length) {
            body.innerHTML = '<div class="text-body-secondary p-3 small">No lists yet. Click <strong>New List</strong> above to create one.</div>';
            return;
        }
        var html = '<div class="table-responsive"><table class="table table-sm table-hover mb-0">' +
            '<thead><tr><th>Name</th><th>Slug</th><th>Description</th><th class="text-end">Members</th><th class="text-end">Actions</th></tr></thead><tbody>';
        lists.forEach(function (l) {
            html += '<tr>' +
                '<td><span class="fw-semibold">' + escapeHtml(l.name) + '</span></td>' +
                '<td class="font-monospace small text-body-secondary">' + escapeHtml(l.slug) + '</td>' +
                '<td class="small">' + escapeHtml((l.description || '').substr(0, 80)) + '</td>' +
                '<td class="text-end">' + (l.member_count || 0) + '</td>' +
                '<td class="text-end">' +
                '  <button class="btn btn-sm btn-outline-primary" onclick="window.__el_open(' + l.id + ')"><i class="bi bi-pencil me-1"></i>Manage</button> ' +
                '  <button class="btn btn-sm btn-outline-danger" onclick="window.__el_archive(' + l.id + ')" title="Archive"><i class="bi bi-archive"></i></button>' +
                '</td>' +
                '</tr>';
        });
        html += '</tbody></table></div>';
        body.innerHTML = html;
    }

    function openNewEmailListPrompt() {
        var name = prompt('List name (e.g. "EOC Ops"):');
        if (!name || !name.trim()) return;
        var desc = prompt('Optional description:') || '';
        fetch('api/email-lists.php?action=create', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ csrf_token: getCsrfToken(), name: name.trim(), description: desc })
        }).then(function (r) { return r.json(); })
          .then(function (data) {
            if (data && data.error) return showAlert(data.error, 'danger');
            showAlert('List "' + data.name + '" created.', 'success');
            loadEmailLists();
            window.__el_open(data.id);
          });
    }

    function openEmailListImportPrompt() {
        if (!emailListsCache.length) return showAlert('Create a list first.', 'warning');
        var listId = parseInt(prompt('Target list id (' + emailListsCache.map(function(l){return l.id + '=' + l.name;}).join(', ') + '):'), 10);
        if (!listId) return;
        var csv = prompt('Paste CSV (one email per line; optional second column = name):');
        if (!csv) return;
        fetch('api/email-lists.php?action=import_csv', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ csrf_token: getCsrfToken(), list_id: listId, csv_text: csv })
        }).then(function (r) { return r.json(); })
          .then(function (data) {
            if (data && data.error) return showAlert(data.error, 'danger');
            showAlert('Imported ' + data.added + ' addresses. Skipped: ' + data.skipped + '.', data.skipped ? 'warning' : 'success');
            loadEmailLists();
          });
    }

    window.__el_archive = function (id) {
        if (!confirm('Archive this list? Senders will no longer see it as a target.')) return;
        fetch('api/email-lists.php?action=archive', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ csrf_token: getCsrfToken(), id: id })
        }).then(function (r) { return r.json(); })
          .then(function (data) {
            if (data && data.error) return showAlert(data.error, 'danger');
            showAlert('Archived.', 'info');
            loadEmailLists();
          });
    };

    window.__el_open = function (id) {
        fetch('api/email-lists.php?action=detail&id=' + id, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || data.error) return showAlert(data && data.error || 'load failed', 'danger');
                showEmailListDetail(data);
            });
    };

    function showEmailListDetail(data) {
        var list = data.list, members = data.members || [];
        var modal = document.getElementById('emailListDetailModal');
        if (!modal) {
            modal = document.createElement('div');
            modal.id = 'emailListDetailModal';
            modal.className = 'modal fade';
            modal.tabIndex = -1;
            modal.innerHTML = '<div class="modal-dialog modal-lg"><div class="modal-content">' +
                '<div class="modal-header py-2"><h6 class="modal-title" id="elDetailTitle"></h6>' +
                '<button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>' +
                '<div class="modal-body" id="elDetailBody"></div>' +
                '<div class="modal-footer py-2">' +
                '<button class="btn btn-sm btn-outline-success" id="btnAddInlineEmail">Add inline address</button>' +
                '<button class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Close</button>' +
                '</div></div></div>';
            document.body.appendChild(modal);
        }
        document.getElementById('elDetailTitle').textContent = 'Manage list: ' + list.name + ' (' + list.slug + ')';
        var rows = members.length ? members.map(function (m) {
            var label = m.member_type === 'member' ? (m.display_name || m.member_username) + ' &lt;' + (m.member_email || '?') + '&gt;'
                      : m.member_type === 'constituent' ? (m.display_name || m.constituent_name) + ' &lt;' + (m.constituent_email || '?') + '&gt;'
                      : m.member_type === 'inline' ? (m.display_name ? m.display_name + ' &lt;' + m.inline_email + '&gt;' : m.inline_email)
                      : m.member_type === 'list' ? 'sub-list: ' + (m.sub_list_name || '#' + m.ref_id)
                      : '?';
            return '<tr><td><span class="badge bg-secondary">' + m.member_type + '</span></td><td>' + label + '</td>' +
                   '<td class="text-end"><button class="btn btn-sm btn-outline-danger" onclick="window.__el_rm(' + m.id + ',' + list.id + ')"><i class="bi bi-x-lg"></i></button></td></tr>';
        }).join('') : '<tr><td colspan="3" class="text-body-secondary text-center small">No members yet.</td></tr>';
        document.getElementById('elDetailBody').innerHTML =
            '<table class="table table-sm mb-0"><thead><tr><th>Type</th><th>Recipient</th><th class="text-end">Remove</th></tr></thead><tbody>' +
            rows + '</tbody></table>';
        var btn = document.getElementById('btnAddInlineEmail');
        btn.onclick = function () {
            var email = prompt('Email address:');
            if (!email) return;
            var name = prompt('Display name (optional):') || '';
            fetch('api/email-lists.php?action=add_member', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ csrf_token: getCsrfToken(), list_id: list.id, member_type: 'inline', inline_email: email, display_name: name })
            }).then(function (r) { return r.json(); })
              .then(function (d) {
                if (d && d.error) return showAlert(d.error, 'danger');
                window.__el_open(list.id);
                loadEmailLists();
              });
        };
        new bootstrap.Modal(modal).show();
    }

    window.__el_rm = function (memberId, listId) {
        if (!confirm('Remove this recipient?')) return;
        fetch('api/email-lists.php?action=remove_member', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ csrf_token: getCsrfToken(), id: memberId })
        }).then(function (r) { return r.json(); })
          .then(function (data) {
            if (data && data.error) return showAlert(data.error, 'danger');
            window.__el_open(listId);
            loadEmailLists();
          });
    };

    function getCsrfToken() { return (document.getElementById('csrfToken') || {}).value || ''; }

    // ════════════════════════════════════════════════════════════════
    //  PHASE 41 — PLACES (named locations)
    // ════════════════════════════════════════════════════════════════
    var placesCache = [];
    function loadPlaces() {
        var body = document.getElementById('placesBody');
        if (!body) return;
        body.innerHTML = '<div class="text-body-secondary p-3 small">Loading…</div>';
        fetch('api/places.php?action=list', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data && data.error) { body.innerHTML = '<div class="text-danger p-3 small">' + escapeHtml(data.error) + '</div>'; return; }
                placesCache = data.places || [];
                renderPlaces();
            });
        var btn = document.getElementById('btnNewPlace');
        if (btn && !btn._phase41) { btn._phase41 = true; btn.addEventListener('click', openNewPlacePrompt); }
        var filt = document.getElementById('placesFilter');
        if (filt && !filt._phase41) { filt._phase41 = true; filt.addEventListener('input', renderPlaces); }
        // Phase 108 (issue #36) — export + import buttons.
        var expCsv = document.getElementById('btnExportPlacesCsv');
        if (expCsv && !expCsv._phase108) {
            expCsv._phase108 = true;
            expCsv.addEventListener('click', function () {
                window.location.href = 'api/places.php?action=export&format=csv';
            });
        }
        var expJson = document.getElementById('btnExportPlacesJson');
        if (expJson && !expJson._phase108) {
            expJson._phase108 = true;
            expJson.addEventListener('click', function () {
                window.location.href = 'api/places.php?action=export&format=json';
            });
        }
        var impSubmit = document.getElementById('btnImportPlacesSubmit');
        if (impSubmit && !impSubmit._phase108) {
            impSubmit._phase108 = true;
            impSubmit.addEventListener('click', function () {
                var fileInput = document.getElementById('importPlacesFile');
                var resultEl  = document.getElementById('importPlacesResult');
                if (!fileInput.files || !fileInput.files.length) {
                    resultEl.innerHTML = '<div class="alert alert-warning py-1 mb-0">Pick a file first.</div>';
                    return;
                }
                var fd = new FormData();
                fd.append('csrf_token', csrfToken);
                fd.append('format',     document.getElementById('importPlacesFormat').value);
                fd.append('dry_run',    document.getElementById('importPlacesDryRun').checked ? '1' : '0');
                fd.append('file',       fileInput.files[0]);
                resultEl.innerHTML = '<div class="text-body-secondary py-1">Processing…</div>';
                fetch('api/places.php?action=import', {
                    method: 'POST',
                    body: fd,
                    credentials: 'same-origin'
                })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data && data.error) {
                        resultEl.innerHTML = '<div class="alert alert-danger py-1 mb-0">' + escapeHtml(data.error) + '</div>';
                        return;
                    }
                    var dry = data.dry_run ? ' (dry run — nothing written)' : '';
                    var html = '<div class="alert alert-info py-1 mb-1">' +
                        '<strong>Processed ' + (data.total_processed || 0) + ' row(s)</strong>' + dry + '<br>' +
                        'Inserted: ' + (data.inserted || 0) + ' &middot; ' +
                        'Updated: ' + (data.updated || 0) + ' &middot; ' +
                        'Skipped: ' + (data.skipped || 0) +
                        '</div>';
                    if (data.errors && data.errors.length) {
                        html += '<div class="border rounded p-2 small" style="max-height:200px;overflow-y:auto;">' +
                                '<div class="fw-semibold text-danger mb-1">' + data.errors.length + ' row error(s):</div>';
                        for (var i = 0; i < data.errors.length; i++) {
                            var err = data.errors[i];
                            html += '<div>row ' + err.row + ': ' + escapeHtml(err.message) + '</div>';
                        }
                        html += '</div>';
                    }
                    resultEl.innerHTML = html;
                    // Refresh the places list if we actually wrote.
                    if (!data.dry_run) { placesCache = null; loadPlaces(); }
                })
                .catch(function (err) {
                    resultEl.innerHTML = '<div class="alert alert-danger py-1 mb-0">' + escapeHtml(err.message || String(err)) + '</div>';
                });
            });
        }
    }
    function renderPlaces() {
        var body = document.getElementById('placesBody');
        if (!body) return;
        var q = ((document.getElementById('placesFilter') || {}).value || '').toLowerCase();
        var list = q ? placesCache.filter(function (p) { return (p.name + ' ' + (p.street||'') + ' ' + (p.city||'')).toLowerCase().indexOf(q) !== -1; }) : placesCache;
        if (!list.length) { body.innerHTML = '<div class="text-body-secondary p-3 small">No places yet. Click <strong>New Place</strong> to add one.</div>'; return; }
        var html = '<div class="table-responsive"><table class="table table-sm table-hover mb-0">' +
            '<thead><tr><th>Name</th><th>Address</th><th>Position</th><th class="text-end">Actions</th></tr></thead><tbody>';
        list.forEach(function (p) {
            var addr = [p.street, p.city, p.state].filter(Boolean).join(', ');
            var pos = (p.lat && p.lon) ? Number(p.lat).toFixed(4) + ', ' + Number(p.lon).toFixed(4) : '—';
            html += '<tr><td class="fw-semibold">' + escapeHtml(p.name) + ' <small class="text-body-secondary">[' + escapeHtml(p.apply_to) + ']</small></td>' +
                '<td class="small">' + escapeHtml(addr) + '</td>' +
                '<td class="font-monospace small">' + escapeHtml(pos) + '</td>' +
                '<td class="text-end">' +
                '  <button class="btn btn-sm btn-outline-primary" onclick="window.__pl_edit(' + p.id + ')"><i class="bi bi-pencil"></i></button> ' +
                '  <button class="btn btn-sm btn-outline-danger" onclick="window.__pl_del(' + p.id + ')"><i class="bi bi-trash"></i></button>' +
                '</td></tr>';
        });
        html += '</tbody></table></div>';
        body.innerHTML = html;
    }
    function openNewPlacePrompt() {
        var name = prompt('Place name (e.g. "The Stadium"):'); if (!name) return;
        var street = prompt('Street address (optional):') || '';
        var city = prompt('City (optional):') || '';
        var state = prompt('State (e.g. MN):') || '';
        var latStr = prompt('Latitude (optional, decimal):') || '';
        var lonStr = prompt('Longitude (optional, decimal):') || '';
        fetch('api/places.php?action=create', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                csrf_token: getCsrfToken(),
                name: name.trim(), street: street.trim(), city: city.trim(), state: state.trim().toUpperCase().substr(0, 4),
                lat: latStr ? parseFloat(latStr) : null,
                lon: lonStr ? parseFloat(lonStr) : null,
                apply_to: 'bldg'
            })
        }).then(function (r) { return r.json(); })
          .then(function (data) {
            if (data && data.error) return showAlert(data.error, 'danger');
            showAlert('Created "' + data.name + '"', 'success');
            loadPlaces();
          });
    }
    window.__pl_edit = function (id) {
        var p = placesCache.find(function (x) { return x.id === id; });
        if (!p) return;
        var name = prompt('Name:', p.name);
        if (name === null) return;
        var street = prompt('Street:', p.street || '');
        var city   = prompt('City:', p.city || '');
        fetch('api/places.php?action=update', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ csrf_token: getCsrfToken(), id: id, name: name, street: street, city: city })
        }).then(function (r) { return r.json(); })
          .then(function (data) {
            if (data && data.error) return showAlert(data.error, 'danger');
            loadPlaces();
          });
    };
    window.__pl_del = function (id) {
        if (!confirm('Delete this place?')) return;
        fetch('api/places.php?action=delete', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ csrf_token: getCsrfToken(), id: id })
        }).then(function (r) { return r.json(); })
          .then(function () { loadPlaces(); });
    };

    // ════════════════════════════════════════════════════════════════
    //  PHASE 41 — MAP OVERLAY CATEGORIES
    // ════════════════════════════════════════════════════════════════
    function loadMapOverlayCategories() {
        var body = document.getElementById('mapCatsBody');
        if (!body) return;
        body.innerHTML = '<div class="text-body-secondary p-3 small">Loading…</div>';
        fetch('api/map-overlay-categories.php?action=list', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data && data.error) { body.innerHTML = '<div class="text-danger p-3 small">' + escapeHtml(data.error) + '</div>'; return; }
                renderMapCats(data.categories || []);
            });
        var btnNew = document.getElementById('btnNewMapCat');
        if (btnNew && !btnNew._phase41) { btnNew._phase41 = true; btnNew.addEventListener('click', openNewMapCatPrompt); }
        var btnRef = document.getElementById('btnMapCatsRefresh');
        if (btnRef && !btnRef._phase41) { btnRef._phase41 = true; btnRef.addEventListener('click', loadMapOverlayCategories); }
    }
    function renderMapCats(cats) {
        var body = document.getElementById('mapCatsBody');
        if (!cats.length) { body.innerHTML = '<div class="text-body-secondary p-3 small">No categories. Click <strong>New Category</strong>.</div>'; return; }
        var html = '<div class="table-responsive"><table class="table table-sm table-hover mb-0">' +
            '<thead><tr><th>Name</th><th>Color</th><th>Icon</th><th>Sort</th><th>Default</th><th class="text-end">Markups</th><th class="text-end">Actions</th></tr></thead><tbody>';
        cats.forEach(function (c) {
            html += '<tr>' +
                '<td><span class="fw-semibold">' + escapeHtml(c.name) + '</span>' +
                  (c.description ? '<br><small class="text-body-secondary">' + escapeHtml(c.description) + '</small>' : '') +
                '</td>' +
                '<td><span class="d-inline-block" style="width:20px;height:20px;border-radius:3px;background:' + escapeHtml(c.color || '#888') + '" title="' + escapeHtml(c.color) + '"></span></td>' +
                '<td><i class="bi bi-' + escapeHtml(c.icon || 'circle') + '"></i> <small>' + escapeHtml(c.icon || '') + '</small></td>' +
                '<td>' + (c.sort_order || 0) + '</td>' +
                '<td>' + (Number(c.default_visible) ? '<span class="badge bg-success">ON</span>' : '<span class="badge bg-secondary">off</span>') + '</td>' +
                '<td class="text-end">' + (c.markup_count || 0) + '</td>' +
                '<td class="text-end">' +
                '  <button class="btn btn-sm btn-outline-primary" onclick="window.__mc_edit(' + c.id + ')"><i class="bi bi-pencil"></i></button> ' +
                '  <button class="btn btn-sm btn-outline-danger" onclick="window.__mc_archive(' + c.id + ')"><i class="bi bi-archive"></i></button>' +
                '</td></tr>';
        });
        html += '</tbody></table></div>';
        body.innerHTML = html;
        window.__mc_cache = cats;
    }
    function openNewMapCatPrompt() {
        var name = prompt('Category name (e.g. "Patrol Zones"):'); if (!name) return;
        var color = prompt('Color (hex, e.g. #1976d2):', '#1976d2') || '#1976d2';
        var icon = prompt('Bootstrap icon name (e.g. shield, flag, map):', 'circle') || 'circle';
        var sort = parseInt(prompt('Sort order (lower = first):', '50'), 10) || 50;
        var defVis = confirm('Show by default on the map?');
        fetch('api/map-overlay-categories.php?action=create', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ csrf_token: getCsrfToken(), name: name.trim(), color: color, icon: icon, sort_order: sort, default_visible: defVis ? 1 : 0 })
        }).then(function (r) { return r.json(); })
          .then(function (data) {
            if (data && data.error) return showAlert(data.error, 'danger');
            showAlert('Created "' + data.name + '"', 'success');
            loadMapOverlayCategories();
          });
    }
    window.__mc_edit = function (id) {
        var c = (window.__mc_cache || []).find(function (x) { return x.id === id; });
        if (!c) return;
        var name = prompt('Name:', c.name); if (name === null) return;
        var color = prompt('Color:', c.color || '#1976d2');
        var icon = prompt('Icon:', c.icon || 'circle');
        var sort = parseInt(prompt('Sort order:', c.sort_order), 10);
        fetch('api/map-overlay-categories.php?action=update', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ csrf_token: getCsrfToken(), id: id, name: name, color: color, icon: icon, sort_order: sort })
        }).then(function (r) { return r.json(); })
          .then(function () { loadMapOverlayCategories(); });
    };
    window.__mc_archive = function (id) {
        if (!confirm('Archive this category? Markups in it will become uncategorised.')) return;
        fetch('api/map-overlay-categories.php?action=archive', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ csrf_token: getCsrfToken(), id: id })
        }).then(function (r) { return r.json(); })
          .then(function () { loadMapOverlayCategories(); });
    };

    // ── Phase 43: inline map-overlay drawing editor ──────────────────
    //
    // Lives in the Map Overlays panel directly under the categories list.
    // Pick a category, click a draw tool, click on the map, Finish, name it,
    // saved with category_id = that category. Shapes inherit category color
    // and render with permanent center labels on the dispatcher map.
    var moEditor = {
        map:        null,        // L.Map
        catLayer:   null,        // L.LayerGroup of existing shapes in current category
        previewLayer: null,      // ephemeral L.Polyline/Polygon/Circle/Marker during drawing
        drawMode:   null,        // 'polygon'|'circle'|'line'|'marker'|null
        drawPoints: [],          // latlng list for polygon/line
        circleCenter: null,      // first click for circle
        activeCatId: 0,
        cats:       [],          // mirror of customTonesState pattern
        shapes:     []           // shapes last loaded for the active category
    };

    function _moStatus(txt) {
        var el = document.getElementById('moDrawStatus');
        if (el) el.textContent = txt || '';
    }

    function _moActiveCat() {
        for (var i = 0; i < moEditor.cats.length; i++) {
            if (moEditor.cats[i].id === moEditor.activeCatId) return moEditor.cats[i];
        }
        return null;
    }

    function _moShapeById(id) {
        var list = moEditor.shapes || [];
        for (var i = 0; i < list.length; i++) {
            if (parseInt(list[i].id, 10) === parseInt(id, 10)) return list[i];
        }
        return null;
    }

    function _moInitMap() {
        if (moEditor.map) {
            // Map already exists — just invalidate size in case the container
            // was hidden when first created. Bootstrap collapse / tab show
            // can leave Leaflet thinking the canvas is 0x0.
            setTimeout(function () { try { moEditor.map.invalidateSize(); } catch (e) {} }, 80);
            return;
        }
        var el = document.getElementById('moEditorMap');
        if (!el || typeof L === 'undefined') return;

        // Pull default center from saved settings. The Map Settings panel
        // (Settings → Maps & Places → Map Defaults) writes default_lat,
        // default_lng, and default_zoom — same keys read by the Map
        // Settings preview at initMapDefaultsPreview() and by the rest
        // of config.js's map initializers. The earlier version of this
        // function read from a global window.__mapDefaults that nothing
        // ever set, so the map always fell back to the Minneapolis
        // defaults — beta tester a beta tester 2026-06-26 reported
        // exactly that. Reading apiGet('settings') here is cheap (one
        // HTTP request, settings endpoint is fast) and the Map Overlays
        // editor is opened rarely enough that the latency doesn't matter.
        var hardcoded = [44.9778, -93.2650], hardcodedZoom = 12;
        apiGet('settings').then(function (data) {
            var s = (data && data.settings) || {};
            var lat = parseFloat(s.default_lat);
            var lng = parseFloat(s.default_lng);
            var z   = parseInt(s.default_zoom, 10);
            var center = (isFinite(lat) && isFinite(lng)) ? [lat, lng] : hardcoded;
            var zoom = isFinite(z) ? z : hardcodedZoom;
            _initMoEditorMapInner(el, center, zoom);
        }).catch(function () {
            // settings load failed — fall back to hardcoded so the operator
            // still gets a working map; they can recenter manually.
            _initMoEditorMapInner(el, hardcoded, hardcodedZoom);
        });
    }

    // Inner helper extracted so both the success and failure paths of
    // the settings load can share the layer/control setup below.
    function _initMoEditorMapInner(el, center, zoom) {
        moEditor.map = L.map(el, { zoomControl: true }).setView(center, zoom);

        // Phase 43b — basemap switcher so admins can match drawings against
        // satellite imagery, topo, dark, etc. Eric's use case: lining up
        // operational zones against a printed satellite-view festival map.
        var osm = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap', maxZoom: 19,
        });
        var esriSat = L.tileLayer(
            'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
            { attribution: 'Tiles &copy; Esri &mdash; Source: Esri, Maxar, Earthstar Geographics, USDA, USGS',
              maxZoom: 19 }
        );
        var esriSatLabels = L.tileLayer(
            'https://server.arcgisonline.com/ArcGIS/rest/services/Reference/World_Boundaries_and_Places/MapServer/tile/{z}/{y}/{x}',
            { attribution: '', maxZoom: 19, opacity: 0.9 }
        );
        var satWithLabels = L.layerGroup([esriSat, esriSatLabels]);
        var usgsTopo = L.tileLayer(
            'https://basemap.nationalmap.gov/arcgis/rest/services/USGSImageryTopo/MapServer/tile/{z}/{y}/{x}',
            { attribution: 'USGS', maxZoom: 20 }
        );
        var dark = L.tileLayer(
            'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png',
            { attribution: '&copy; CartoDB', maxZoom: 19 }
        );
        // Default to OSM unless the admin previously picked something else.
        var savedBase = null;
        try { savedBase = localStorage.getItem('newui_overlay_editor_base'); } catch (e) {}
        var baseMap = {
            'Streets (OpenStreetMap)': osm,
            'Satellite (Esri)':        esriSat,
            'Satellite + Labels':      satWithLabels,
            'USGS Imagery Topo':       usgsTopo,
            'Dark':                    dark,
        };
        (baseMap[savedBase] || osm).addTo(moEditor.map);
        L.control.layers(baseMap, null, { collapsed: false, position: 'topright' }).addTo(moEditor.map);
        moEditor.map.on('baselayerchange', function (e) {
            try { localStorage.setItem('newui_overlay_editor_base', e.name); } catch (_) {}
        });

        moEditor.catLayer = L.layerGroup().addTo(moEditor.map);

        // Re-invalidate on container resize (the wrap div has CSS `resize:both`).
        try {
            var wrap = document.getElementById('moMapWrap');
            if (wrap && typeof ResizeObserver !== 'undefined') {
                var ro = new ResizeObserver(function () {
                    if (moEditor.map) try { moEditor.map.invalidateSize(); } catch (e) {}
                });
                ro.observe(wrap);
            }
        } catch (e) {}
        // First invalidate after layout settles.
        setTimeout(function () { try { moEditor.map.invalidateSize(); } catch (e) {} }, 200);
    }

    function _moPopulateCategoryDropdown() {
        var sel = document.getElementById('moEditorCategory');
        if (!sel) return;
        var prev = sel.value;
        sel.innerHTML = '<option value="">— pick a category to draw into —</option>';
        moEditor.cats.forEach(function (c) {
            var opt = document.createElement('option');
            opt.value = c.id;
            opt.textContent = c.name + (c.markup_count ? ' (' + c.markup_count + ' shapes)' : '');
            opt.style.color = c.color || '';
            sel.appendChild(opt);
        });
        if (prev) sel.value = prev;
    }

    function _moEnableDrawTools(enabled) {
        ['moDrawPolygon','moDrawCircle','moDrawLine','moDrawMarker'].forEach(function (id) {
            var b = document.getElementById(id);
            if (b) b.disabled = !enabled;
        });
    }

    function _moRenderShapesList(shapes) {
        var body = document.getElementById('moShapesBody');
        if (!body) return;
        if (!moEditor.activeCatId) {
            body.innerHTML = '<div class="text-body-secondary small p-3">Pick a category above to see its shapes.</div>';
            return;
        }
        if (!shapes.length) {
            body.innerHTML = '<div class="text-body-secondary small fst-italic p-2">No shapes drawn in this category yet. Pick a tool and click on the map above.</div>';
            return;
        }
        var html = '<table class="table table-sm table-hover mb-0 align-middle"><thead><tr>'
                 + '<th style="width:8%">Type</th><th>Name</th><th style="width:20%">Color</th><th class="text-end" style="width:20%">Actions</th></tr></thead><tbody>';
        var typeLabels = { P: 'Polygon', L: 'Line', C: 'Circle', M: 'Marker' };
        shapes.forEach(function (s) {
            var typ = typeLabels[(s.line_type || 'P').toUpperCase()] || s.line_type;
            html += '<tr>'
                  + '<td class="text-body-secondary small">' + esc(typ) + '</td>'
                  + '<td><strong>' + esc(s.line_name || ('Shape ' + s.id)) + '</strong></td>'
                  + '<td><input type="color" class="form-control form-control-sm form-control-color"'
                  +      ' value="' + esc(s.line_color || '#1976d2') + '"'
                  +      ' title="Shape colour — applies as soon as you pick one"'
                  +      ' onchange="__mo_setcolor(' + s.id + ', this.value)"></td>'
                  + '<td class="text-end">'
                  + ' <button type="button" class="btn btn-xs btn-outline-secondary" onclick="__mo_rename(' + s.id + ')" title="Rename"><i class="bi bi-pencil"></i></button>'
                  + ' <button type="button" class="btn btn-xs btn-outline-danger"    onclick="__mo_delete(' + s.id + ')" title="Delete"><i class="bi bi-trash"></i></button>'
                  + '</td></tr>';
        });
        html += '</tbody></table>';
        body.innerHTML = html;
    }

    function _moLoadShapesForActiveCat() {
        if (!moEditor.catLayer) return;
        moEditor.catLayer.clearLayers();
        if (!moEditor.activeCatId) {
            _moRenderShapesList([]);
            return;
        }
        fetch('api/map-markups.php?category_id=' + moEditor.activeCatId, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                var shapes = (d.markups || []).filter(function (s) {
                    return parseInt(s.category_id || s.line_cat_id || 0, 10) === moEditor.activeCatId;
                });
                moEditor.shapes = shapes;
                _moRenderShapesList(shapes);
                shapes.forEach(_moRenderShapeOnMap);
                // Fit bounds to category if any shapes have coords.
                try {
                    var allLatLngs = [];
                    shapes.forEach(function (s) {
                        if (!s.line_data) return;
                        try {
                            var coords = JSON.parse(s.line_data);
                            (coords || []).forEach(function (c) { allLatLngs.push(c); });
                        } catch (e) {}
                    });
                    if (allLatLngs.length) {
                        moEditor.map.fitBounds(L.latLngBounds(allLatLngs), { padding: [40,40], maxZoom: 15 });
                    }
                } catch (e) {}
            });
    }

    function _moRenderShapeOnMap(s) {
        var cat = _moActiveCat();
        var color = s.line_color || (cat && cat.color) || '#1976d2';
        var name = s.line_name || ('Shape ' + s.id);
        try {
            var coords = JSON.parse(s.line_data || '[]');
            if (!coords.length) return;
            var t = (s.line_type || 'P').toUpperCase();
            var shape = null;
            if (t === 'P' && coords.length >= 3) {
                shape = L.polygon(coords, { color: color, weight: 2, fillColor: color, fillOpacity: 0.2 });
            } else if (t === 'L' && coords.length >= 2) {
                shape = L.polyline(coords, { color: color, weight: 3 });
            } else if (t === 'C' && coords.length >= 1) {
                var r = parseFloat(s.line_ident) || 250;
                shape = L.circle(coords[0], { radius: r, color: color, fillColor: color, fillOpacity: 0.2 });
            } else if (t === 'M' && coords.length >= 1) {
                shape = L.marker(coords[0]);
            }
            if (shape) {
                shape.bindTooltip(name, { permanent: true, direction: 'center', className: 'mo-shape-label' });
                moEditor.catLayer.addLayer(shape);
            }
        } catch (e) {}
    }

    function _moClearPreview() {
        if (moEditor.previewLayer && moEditor.map) {
            try { moEditor.map.removeLayer(moEditor.previewLayer); } catch (e) {}
        }
        moEditor.previewLayer = null;
        moEditor.drawPoints = [];
        moEditor.circleCenter = null;
        var finish = document.getElementById('moDrawFinish');
        var cancel = document.getElementById('moDrawCancel');
        if (finish) finish.classList.add('d-none');
        if (cancel) cancel.classList.add('d-none');
        if (moEditor.map) {
            moEditor.map.getContainer().style.cursor = '';
            moEditor.map.off('click', _moOnMapClick);
            moEditor.map.off('mousemove', _moOnMapMove);
        }
    }

    function _moStartDraw(mode) {
        if (!moEditor.activeCatId || !moEditor.map) return;
        _moClearPreview();
        moEditor.drawMode = mode;
        moEditor.map.getContainer().style.cursor = 'crosshair';
        var finish = document.getElementById('moDrawFinish');
        var cancel = document.getElementById('moDrawCancel');
        if (cancel) cancel.classList.remove('d-none');
        if (finish && (mode === 'polygon' || mode === 'line')) finish.classList.remove('d-none');
        moEditor.map.on('click', _moOnMapClick);
        moEditor.map.on('mousemove', _moOnMapMove);
        var help = {
            polygon: 'Click 3+ points; press Finish to close.',
            line:    'Click 2+ points; press Finish.',
            circle:  'Click center, then click a point on the edge.',
            marker:  'Click once to drop the marker.'
        };
        _moStatus(help[mode] || '');
    }

    function _moOnMapClick(e) {
        var cat = _moActiveCat();
        var color = cat && cat.color || '#1976d2';
        if (moEditor.drawMode === 'marker') {
            _moPromptNameAndSave({
                type: 'M',
                coordinates: JSON.stringify([[e.latlng.lat, e.latlng.lng]]),
                color: color, fill_color: color, filled: 0, width: 2, opacity: 1, fill_opacity: 0.2
            });
            _moClearPreview();
            moEditor.drawMode = null;
            _moStatus('');
            return;
        }
        if (moEditor.drawMode === 'circle') {
            if (!moEditor.circleCenter) {
                moEditor.circleCenter = e.latlng;
                _moStatus('Now click a point on the edge of the circle.');
            } else {
                var radiusMeters = moEditor.circleCenter.distanceTo(e.latlng);
                _moPromptNameAndSave({
                    type: 'C',
                    coordinates: JSON.stringify([[moEditor.circleCenter.lat, moEditor.circleCenter.lng]]),
                    ident: String(Math.round(radiusMeters)),
                    color: color, fill_color: color, filled: 1, width: 2, opacity: 0.8, fill_opacity: 0.2
                });
                _moClearPreview();
                moEditor.drawMode = null;
                _moStatus('');
            }
            return;
        }
        // polygon or line — accumulate
        moEditor.drawPoints.push([e.latlng.lat, e.latlng.lng]);
        if (!moEditor.previewLayer) {
            var opts = { color: color, weight: 3, opacity: 0.8 };
            if (moEditor.drawMode === 'polygon') {
                opts.fillColor = color; opts.fillOpacity = 0.2;
                moEditor.previewLayer = L.polygon(moEditor.drawPoints, opts).addTo(moEditor.map);
            } else {
                moEditor.previewLayer = L.polyline(moEditor.drawPoints, opts).addTo(moEditor.map);
            }
        } else {
            moEditor.previewLayer.setLatLngs(moEditor.drawPoints);
        }
        _moStatus(moEditor.drawPoints.length + ' point(s).');
    }

    function _moOnMapMove(e) {
        if (moEditor.drawMode === 'circle' && moEditor.circleCenter) {
            var r = moEditor.circleCenter.distanceTo(e.latlng);
            var cat = _moActiveCat();
            var color = cat && cat.color || '#1976d2';
            if (moEditor.previewLayer) try { moEditor.map.removeLayer(moEditor.previewLayer); } catch(_){}
            moEditor.previewLayer = L.circle(moEditor.circleCenter,
                { radius: r, color: color, fillColor: color, fillOpacity: 0.15 }).addTo(moEditor.map);
            _moStatus('Radius: ' + Math.round(r) + ' m');
            return;
        }
        if ((moEditor.drawMode === 'polygon' || moEditor.drawMode === 'line') && moEditor.drawPoints.length) {
            var pts = moEditor.drawPoints.concat([[e.latlng.lat, e.latlng.lng]]);
            if (moEditor.previewLayer && moEditor.previewLayer.setLatLngs) moEditor.previewLayer.setLatLngs(pts);
        }
    }

    function _moFinishDraw() {
        if (moEditor.drawMode === 'polygon' && moEditor.drawPoints.length >= 3) {
            var cat = _moActiveCat();
            var color = cat && cat.color || '#1976d2';
            _moPromptNameAndSave({
                type: 'P',
                coordinates: JSON.stringify(moEditor.drawPoints),
                color: color, fill_color: color, filled: 1, width: 2, opacity: 0.8, fill_opacity: 0.25
            });
        } else if (moEditor.drawMode === 'line' && moEditor.drawPoints.length >= 2) {
            var cat2 = _moActiveCat();
            var color2 = cat2 && cat2.color || '#1976d2';
            _moPromptNameAndSave({
                type: 'L',
                coordinates: JSON.stringify(moEditor.drawPoints),
                color: color2, filled: 0, width: 3, opacity: 0.8
            });
        }
        _moClearPreview();
        moEditor.drawMode = null;
        _moStatus('');
    }

    function _moPromptNameAndSave(shape) {
        var defaultName = '';
        var cat = _moActiveCat();
        if (cat) {
            // Suggest "Zone 1" etc by counting existing shapes in this cat.
            var n = (cat.markup_count || 0) + 1;
            defaultName = cat.name.replace(/s$/i,'') + ' ' + n;
        }
        var name = prompt('Name this shape:', defaultName);
        if (!name) return;
        var payload = Object.assign({
            action: 'save',
            csrf_token: getCsrfToken(),
            name: name,
            visible: 1,
            category_id: moEditor.activeCatId
        }, shape);
        fetch('api/map-markups.php', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (d && (d.success || d.id)) {
                _moLoadShapesForActiveCat();
                loadMapOverlayCategories(); // refresh markup_count badges
            } else {
                alert('Save failed: ' + (d && d.error ? d.error : 'unknown'));
            }
        });
    }

    window.__mo_rename = function (id) {
        var s = _moShapeById(id);
        var name = prompt('New name for this shape:', s ? (s.line_name || '') : '');
        if (name === null) return;              // Cancel — distinct from an empty box
        name = name.replace(/^\s+|\s+$/g, '');
        if (!name) return;
        // Send ONLY id + name. The endpoint updates just the keys it receives,
        // so the geometry, type, radius and colour are left untouched (GH #3).
        fetch('api/map-markups.php', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'save', csrf_token: getCsrfToken(), id: id, name: name })
        }).then(function (r) { return r.json(); })
          .then(function (d) {
              if (d && d.error) { alert('Rename failed: ' + d.error); return; }
              _moLoadShapesForActiveCat();
          })
          .catch(function (e) { alert('Rename failed: ' + e.message); });
    };

    window.__mo_setcolor = function (id, color) {
        var s = _moShapeById(id);
        if (!s) return;
        // name is required by the endpoint — resend the current one unchanged.
        fetch('api/map-markups.php', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'save', csrf_token: getCsrfToken(), id: id,
                name: s.line_name || ('Shape ' + id),
                color: color, fill_color: color
            })
        }).then(function (r) { return r.json(); })
          .then(function (d) {
              if (d && d.error) { alert('Colour change failed: ' + d.error); return; }
              _moLoadShapesForActiveCat();
          })
          .catch(function (e) { alert('Colour change failed: ' + e.message); });
    };

    window.__mo_delete = function (id) {
        if (!confirm('Delete this shape permanently?')) return;
        fetch('api/map-markups.php', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'delete', csrf_token: getCsrfToken(), id: id })
        }).then(function (r) { return r.json(); })
          .then(function () { _moLoadShapesForActiveCat(); loadMapOverlayCategories(); });
    };

    function _moWireEditor() {
        // Idempotent — guard with `_phase43` flag so repeated tab visits don't stack handlers.
        var sel = document.getElementById('moEditorCategory');
        if (sel && !sel._phase43) {
            sel._phase43 = true;
            sel.addEventListener('change', function () {
                moEditor.activeCatId = parseInt(sel.value, 10) || 0;
                _moEnableDrawTools(!!moEditor.activeCatId);
                _moLoadShapesForActiveCat();
            });
        }
        [['moDrawPolygon','polygon'], ['moDrawCircle','circle'], ['moDrawLine','line'], ['moDrawMarker','marker']].forEach(function (pair) {
            var btn = document.getElementById(pair[0]);
            if (btn && !btn._phase43) {
                btn._phase43 = true;
                btn.addEventListener('click', function () { _moStartDraw(pair[1]); });
            }
        });
        var finish = document.getElementById('moDrawFinish');
        if (finish && !finish._phase43) { finish._phase43 = true; finish.addEventListener('click', _moFinishDraw); }
        var cancel = document.getElementById('moDrawCancel');
        if (cancel && !cancel._phase43) {
            cancel._phase43 = true;
            cancel.addEventListener('click', function () { _moClearPreview(); moEditor.drawMode = null; _moStatus('Cancelled.'); });
        }
        var fs = document.getElementById('moMapFullscreen');
        if (fs && !fs._phase43) {
            fs._phase43 = true;
            fs.addEventListener('click', function () {
                var wrap = document.getElementById('moMapWrap');
                if (!wrap) return;
                if (!document.fullscreenElement) {
                    (wrap.requestFullscreen || wrap.webkitRequestFullscreen || function(){}).call(wrap);
                } else {
                    (document.exitFullscreen || document.webkitExitFullscreen || function(){}).call(document);
                }
                setTimeout(function () { if (moEditor.map) try { moEditor.map.invalidateSize(); } catch(e) {} }, 250);
            });
        }
        var refresh = document.getElementById('moShapesRefresh');
        if (refresh && !refresh._phase43) {
            refresh._phase43 = true;
            refresh.addEventListener('click', _moLoadShapesForActiveCat);
        }

        // Phase 43c — export / import wiring.
        function _moExportScopeText() {
            var cat = _moActiveCat();
            var label = document.getElementById('moExportScope');
            if (label) label.textContent = cat ? ('"' + cat.name + '" shapes') : 'all shapes';
        }
        _moExportScopeText();
        // Re-derive scope label whenever the active category changes.
        var selRef = document.getElementById('moEditorCategory');
        if (selRef && !selRef._phase43c) {
            selRef._phase43c = true;
            selRef.addEventListener('change', _moExportScopeText);
        }

        function _moExportHref(fmt) {
            var q = 'action=export&format=' + encodeURIComponent(fmt);
            if (moEditor.activeCatId) q += '&category_id=' + moEditor.activeCatId;
            return 'api/map-markups.php?' + q;
        }
        [['moExportGeoJson','geojson'], ['moExportKml','kml'], ['moExportGpx','gpx']].forEach(function (pair) {
            var a = document.getElementById(pair[0]);
            if (a && !a._phase43c) {
                a._phase43c = true;
                a.addEventListener('click', function (e) {
                    e.preventDefault();
                    // Use a hidden anchor with download so the browser uses the
                    // Content-Disposition filename rather than navigating away.
                    var url = _moExportHref(pair[1]);
                    var link = document.createElement('a');
                    link.href = url;
                    document.body.appendChild(link);
                    link.click();
                    setTimeout(function () { document.body.removeChild(link); }, 100);
                });
            }
        });

        var importBtn  = document.getElementById('moImportBtn');
        var importFile = document.getElementById('moImportFile');
        if (importBtn && !importBtn._phase43c) {
            importBtn._phase43c = true;
            importBtn.addEventListener('click', function () { importFile.click(); });
        }
        if (importFile && !importFile._phase43c) {
            importFile._phase43c = true;
            importFile.addEventListener('change', function () {
                var f = importFile.files && importFile.files[0];
                if (!f) return;
                if (!moEditor.activeCatId) {
                    var go = confirm('No active category picked. Imported shapes will land uncategorised. Continue?');
                    if (!go) { importFile.value = ''; return; }
                }
                var lower = f.name.toLowerCase();
                // KMZ is a binary zip → read as base64; KML/GeoJSON stay text.
                var isKmz = lower.endsWith('.kmz');
                var fmt = isKmz ? 'kmz'
                        : (lower.endsWith('.kml') || lower.endsWith('.xml')) ? 'kml'
                        : 'geojson';
                var reader = new FileReader();
                reader.onload = function () {
                    var body = {
                        action: 'import',
                        csrf_token: getCsrfToken(),
                        format: fmt,
                        category_id: moEditor.activeCatId || null
                    };
                    if (isKmz) {
                        // reader.result is a data URL: "data:...;base64,XXXX".
                        // Strip the prefix and flag the encoding for the server.
                        var res = String(reader.result);
                        var comma = res.indexOf(',');
                        body.content = comma >= 0 ? res.substring(comma + 1) : res;
                        body.content_encoding = 'base64';
                    } else {
                        body.content = reader.result;
                    }
                    fetch('api/map-markups.php', {
                        method: 'POST', credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(body)
                    })
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        if (d && d.success) {
                            alert('Imported ' + d.imported + ' shape(s).' +
                                  (d.errors && d.errors.length ? '\n\nErrors:\n - ' + d.errors.join('\n - ') : ''));
                            _moLoadShapesForActiveCat();
                            loadMapOverlayCategories();
                        } else {
                            alert('Import failed: ' + (d && d.error ? d.error : 'unknown'));
                        }
                    })
                    .catch(function (e) { alert('Import failed: ' + e.message); })
                    .finally(function () { importFile.value = ''; });
                };
                if (isKmz) {
                    reader.readAsDataURL(f);
                } else {
                    reader.readAsText(f);
                }
            });
        }
    }

    // Hook the editor init into the existing loader so it fires every time the
    // user activates the Map Overlays tab.
    var _origLoadMapCats = loadMapOverlayCategories;
    loadMapOverlayCategories = function () {
        _origLoadMapCats.apply(this, arguments);
        // Wait for categories to settle, then refresh editor state.
        fetch('api/map-overlay-categories.php?action=list', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                moEditor.cats = d.categories || [];
                _moInitMap();
                _moPopulateCategoryDropdown();
                _moWireEditor();
                // If a category is already selected (e.g. user returned to tab), refresh its shapes.
                var sel = document.getElementById('moEditorCategory');
                if (sel && sel.value) {
                    moEditor.activeCatId = parseInt(sel.value, 10) || 0;
                    _moEnableDrawTools(!!moEditor.activeCatId);
                    _moLoadShapesForActiveCat();
                }
            });
    };

    // ── Phase 41: Custom tone composer + per-event overrides ─────────
    var customTonesState = { customs: {}, overrides: {}, events: [], waveTypes: [] };

    function loadCustomTonesPanel() {
        var listEl = document.getElementById('customTonesList');
        var overEl = document.getElementById('toneOverrideBody');
        if (!listEl || !overEl) return;
        listEl.innerHTML = '<div class="text-body-secondary small">Loading...</div>';
        fetch('api/audio-alerts.php?action=list', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                customTonesState.customs = d.custom_tones || {};
                customTonesState.overrides = d.event_overrides || {};
                customTonesState.events = d.known_events || [];
                customTonesState.waveTypes = d.known_wave_types || ['sine','square','triangle','sawtooth'];
                renderCustomTonesList();
                renderToneOverrideTable();
            })
            .catch(function () { listEl.innerHTML = '<div class="text-danger small">Failed to load custom tones.</div>'; });
        var btnNew = document.getElementById('btnNewCustomTone');
        if (btnNew && !btnNew._phase41) {
            btnNew._phase41 = true;
            btnNew.addEventListener('click', function () { openCustomToneComposer(); });
        }
    }

    function renderCustomTonesList() {
        var el = document.getElementById('customTonesList');
        if (!el) return;
        var names = Object.keys(customTonesState.customs);
        if (!names.length) {
            el.innerHTML = '<div class="text-body-secondary small fst-italic">No custom tones yet. Click <em>Compose New Tone</em> to make one.</div>';
            return;
        }
        names.sort();
        var html = '<table class="table table-sm align-middle mb-0"><thead><tr>'
                 + '<th>Name</th><th>Notes</th><th>Gap (ms)</th><th>Wave</th>'
                 + '<th class="text-end">Actions</th></tr></thead><tbody>';
        names.forEach(function (n) {
            var t = customTonesState.customs[n];
            html += '<tr>'
                  + '<td><code>' + esc(n) + '</code></td>'
                  + '<td class="text-body-secondary small">' + (t.notes ? t.notes.length : 0) + ' note(s)</td>'
                  + '<td>' + parseInt(t.gap || 0, 10) + '</td>'
                  + '<td><span class="badge bg-secondary">' + esc(t.type || 'sine') + '</span></td>'
                  + '<td class="text-end">'
                  + ' <button type="button" class="btn btn-sm btn-outline-info" onclick="__ct_preview(\'' + jsesc(n) + '\')" title="Preview"><i class="bi bi-play-fill"></i></button>'
                  + ' <button type="button" class="btn btn-sm btn-outline-secondary" onclick="__ct_edit(\'' + jsesc(n) + '\')" title="Edit"><i class="bi bi-pencil"></i></button>'
                  + ' <button type="button" class="btn btn-sm btn-outline-danger" onclick="__ct_delete(\'' + jsesc(n) + '\')" title="Delete"><i class="bi bi-trash"></i></button>'
                  + '</td></tr>';
        });
        html += '</tbody></table>';
        el.innerHTML = html;
    }

    function renderToneOverrideTable() {
        var body = document.getElementById('toneOverrideBody');
        if (!body) return;
        var events = customTonesState.events;
        var customs = customTonesState.customs;
        var overrides = customTonesState.overrides;
        var html = '';
        events.forEach(function (ev) {
            var current = overrides[ev] || '';
            var opts = '<option value="">(built-in)</option>';
            // Built-in tones are 1:1 with event keys.
            events.forEach(function (b) {
                opts += '<option value="' + esc(b) + '"' + (current === b ? ' selected' : '') + '>built-in: ' + esc(b) + '</option>';
            });
            Object.keys(customs).sort().forEach(function (cn) {
                opts += '<option value="' + esc(cn) + '"' + (current === cn ? ' selected' : '') + '>custom: ' + esc(cn) + '</option>';
            });
            html += '<tr>'
                  + '<td>' + esc(ev) + '</td>'
                  + '<td><select class="form-select form-select-sm" data-event="' + esc(ev) + '" onchange="__ct_assign(this)">' + opts + '</select></td>'
                  + '<td class="text-center">'
                  + ' <button type="button" class="btn btn-sm btn-outline-info" onclick="AudioAlerts.playTone(\'' + jsesc(current || ev) + '\')" title="Preview"><i class="bi bi-play-fill"></i></button>'
                  + ' <button type="button" class="btn btn-sm btn-outline-secondary" onclick="__ct_clear_assignment(\'' + jsesc(ev) + '\')" title="Revert to built-in"><i class="bi bi-arrow-counterclockwise"></i></button>'
                  + '</td></tr>';
        });
        body.innerHTML = html;
    }

    var ctEditing = null;  // name being edited, or null for new

    function openCustomToneComposer(name) {
        ctEditing = name || null;
        var ntName = document.getElementById('ctName');
        var ntType = document.getElementById('ctType');
        var ntGap  = document.getElementById('ctGap');
        var nb     = document.getElementById('ctNotesBody');
        if (!ntName || !ntType || !ntGap || !nb) return;
        ntName.value = name || '';
        ntName.disabled = !!name;
        var existing = name ? customTonesState.customs[name] : null;
        ntType.value = (existing && existing.type) || 'sine';
        ntGap.value  = (existing && existing.gap)  || 40;
        nb.innerHTML = '';
        var notes = (existing && existing.notes) || [{ hz: 440, dur: 150 }, { hz: 660, dur: 150 }];
        notes.forEach(function (n, i) { appendComposerNoteRow(i, n.hz, n.dur); });
        wireComposerHandlers();
        var modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('customToneModal'));
        modal.show();
    }

    function appendComposerNoteRow(idx, hz, ms) {
        var nb = document.getElementById('ctNotesBody');
        var tr = document.createElement('tr');
        tr.innerHTML = '<td class="text-body-secondary">' + (idx + 1) + '</td>'
                    + '<td><input type="number" class="form-control form-control-sm ct-hz" min="20" max="8000" step="1" value="' + hz + '"></td>'
                    + '<td><input type="number" class="form-control form-control-sm ct-dur" min="20" max="2000" step="5" value="' + ms + '"></td>'
                    + '<td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger ct-row-del" title="Remove"><i class="bi bi-x-lg"></i></button></td>';
        nb.appendChild(tr);
        tr.querySelector('.ct-row-del').addEventListener('click', function () {
            tr.parentNode.removeChild(tr);
            renumberComposerRows();
        });
    }

    function renumberComposerRows() {
        var nb = document.getElementById('ctNotesBody');
        if (!nb) return;
        var rows = nb.querySelectorAll('tr');
        for (var i = 0; i < rows.length; i++) {
            rows[i].firstElementChild.textContent = (i + 1);
        }
    }

    function readComposerNotes() {
        var nb = document.getElementById('ctNotesBody');
        if (!nb) return [];
        var out = [];
        var rows = nb.querySelectorAll('tr');
        for (var i = 0; i < rows.length; i++) {
            var hz  = parseFloat(rows[i].querySelector('.ct-hz').value);
            var dur = parseInt(rows[i].querySelector('.ct-dur').value, 10);
            if (hz > 0 && dur > 0) out.push({ hz: hz, dur: dur });
        }
        return out;
    }

    function wireComposerHandlers() {
        var add = document.getElementById('ctAddNote');
        var prev = document.getElementById('ctPreview');
        var save = document.getElementById('ctSave');
        if (add && !add._phase41) {
            add._phase41 = true;
            add.addEventListener('click', function () {
                var nb = document.getElementById('ctNotesBody');
                appendComposerNoteRow(nb.querySelectorAll('tr').length, 440, 150);
            });
        }
        if (prev && !prev._phase41) {
            prev._phase41 = true;
            prev.addEventListener('click', function () {
                var notes = readComposerNotes();
                var gap   = parseInt(document.getElementById('ctGap').value, 10) || 40;
                var type  = document.getElementById('ctType').value || 'sine';
                if (typeof AudioAlerts !== 'undefined') AudioAlerts.previewNotes(notes, gap, type);
            });
        }
        if (save && !save._phase41) {
            save._phase41 = true;
            save.addEventListener('click', function () {
                var name = (document.getElementById('ctName').value || '').replace(/[^A-Za-z0-9_-]/g, '');
                if (!name) { alert('Tone name required (a-z, 0-9, _, -; up to 32 chars).'); return; }
                var notes = readComposerNotes();
                if (!notes.length) { alert('Add at least one note.'); return; }
                var gap   = parseInt(document.getElementById('ctGap').value, 10) || 40;
                var type  = document.getElementById('ctType').value || 'sine';
                fetch('api/audio-alerts.php?action=save_tone', {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ csrf_token: getCsrfToken(), name: name, notes: notes, gap: gap, type: type })
                }).then(function (r) { return r.json(); })
                  .then(function (d) {
                      if (d && d.ok) {
                          bootstrap.Modal.getInstance(document.getElementById('customToneModal')).hide();
                          if (typeof AudioAlerts !== 'undefined') AudioAlerts.reloadCustomTones();
                          loadCustomTonesPanel();
                      } else {
                          alert('Save failed: ' + (d && d.error ? d.error : 'unknown'));
                      }
                  });
            });
        }
    }

    window.__ct_preview = function (name) {
        if (typeof AudioAlerts === 'undefined') return;
        var t = customTonesState.customs[name];
        if (!t) return;
        AudioAlerts.previewNotes(t.notes || [], t.gap || 40, t.type || 'sine');
    };
    window.__ct_edit = function (name) { openCustomToneComposer(name); };
    window.__ct_delete = function (name) {
        if (!confirm('Delete custom tone "' + name + '"? Any event overrides pointing at it will revert to the built-in tone.')) return;
        fetch('api/audio-alerts.php?action=delete_tone', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ csrf_token: getCsrfToken(), name: name })
        }).then(function (r) { return r.json(); })
          .then(function () {
              if (typeof AudioAlerts !== 'undefined') AudioAlerts.reloadCustomTones();
              loadCustomTonesPanel();
          });
    };
    window.__ct_assign = function (sel) {
        var ev   = sel.getAttribute('data-event');
        var tone = sel.value;
        if (!ev) return;
        if (!tone) { window.__ct_clear_assignment(ev); return; }
        fetch('api/audio-alerts.php?action=assign', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ csrf_token: getCsrfToken(), event_key: ev, tone: tone })
        }).then(function (r) { return r.json(); })
          .then(function () { if (typeof AudioAlerts !== 'undefined') AudioAlerts.reloadCustomTones(); });
    };
    window.__ct_clear_assignment = function (ev) {
        fetch('api/audio-alerts.php?action=clear_assignment', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ csrf_token: getCsrfToken(), event_key: ev })
        }).then(function (r) { return r.json(); })
          .then(function () {
              if (typeof AudioAlerts !== 'undefined') AudioAlerts.reloadCustomTones();
              loadCustomTonesPanel();
          });
    };

    function jsesc(s) { return String(s).replace(/'/g, "\\'"); }

    function loadEmailConfig() {
        var form = document.getElementById('emailConfigForm');
        if (!form) return;

        var modeEl = document.getElementById('setEmailMode');
        var smtpGroup = document.getElementById('smtpRelayGroup');

        function toggleSmtpFields() {
            if (smtpGroup) smtpGroup.style.display = (modeEl.value === 'smtp') ? '' : 'none';
        }
        modeEl.addEventListener('change', toggleSmtpFields);

        var emailMap = {
            setEmailMode: 'email_mode', setSmtpHost: 'smtp_host', setSmtpPort: 'smtp_port',
            setSmtpEncrypt: 'smtp_encryption', setSmtpUser: 'smtp_user', setSmtpPass: 'smtp_pass',
            setSmtpFrom: 'email_from', setSmtpFromName: 'email_from_name'
        };

        apiGet('settings').then(function (data) {
            var s = data.settings || {};
            for (var id in emailMap) {
                var el = document.getElementById(id);
                if (!el) continue;
                var key = emailMap[id];
                if (el.getAttribute('data-secret') === '1') {
                    // Secret value never sent — reflect stored state in placeholder.
                    el.value = '';
                    el.placeholder = s[key + '_set']
                        ? '•••• stored — leave blank to keep, type to replace'
                        : 'Not set — enter to configure';
                } else if (s[key] !== undefined) {
                    el.value = s[key];
                }
            }
            toggleSmtpFields();
        });

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var pairs = {};
            for (var id in emailMap) {
                var el = document.getElementById(id);
                if (!el) continue;
                // BUG 1 (2026-06-25): blank secret = keep stored value.
                if (el.getAttribute('data-secret') === '1' && (el.value || '') === '') continue;
                pairs[emailMap[id]] = el.value;
            }
            apiPost('settings', { settings: pairs }).then(function (data) {
                showAlert('Email settings saved.', 'success');
            }).catch(function (err) { showAlert(err.message, 'danger'); });
        });

        // Test email
        var testBtn = document.getElementById('btnTestEmail');
        if (testBtn && !testBtn._bound) {
            testBtn._bound = true;
            testBtn.addEventListener('click', function () {
                var to = prompt('Send test email to:');
                if (!to) return;
                testBtn.disabled = true;
                fetch('api/chat.php', {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                    body: JSON.stringify({ action: 'test_channel', channel: 'smtp', to: to, body: 'Test email from TicketsCAD', subject: 'TicketsCAD Test Email', csrf_token: csrfToken })
                })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    testBtn.disabled = false;
                    // test_channel sends via broker_send() directly, so the result
                    // reflects the SMTP provider — independent of routing-enable.
                    if (data.error) { showAlert('Email test error: ' + data.error, 'danger'); return; }
                    var result = data.result || null;
                    if (result && result.success) {
                        showAlert('Test email sent to ' + to, 'success');
                    } else {
                        showAlert('Email failed: ' + ((result && result.error) || 'no result') + ' — check your SMTP settings.', 'danger');
                    }
                })
                .catch(function (err) { testBtn.disabled = false; showAlert(err.message, 'danger'); });
            });
        }
    }

    // ── SMS Config ──
    function loadSmsConfig() {
        var form = document.getElementById('smsConfigForm');
        if (!form) return;

        var providerEl = document.getElementById('setSmsProvider');
        var groups = {
            twilio: document.getElementById('smsTwilioGroup'),
            bulkvs: document.getElementById('smsBulkvsGroup'),
            pushbullet: document.getElementById('smsPushbulletGroup'),
            generic: document.getElementById('smsGenericGroup')
        };

        function toggleSmsGroups() {
            var val = providerEl.value;
            for (var k in groups) {
                if (groups[k]) groups[k].style.display = (val === k) ? '' : 'none';
            }
        }
        providerEl.addEventListener('change', toggleSmsGroups);

        var smsMap = {
            setSmsProvider: 'sms_provider', setSmsFrom: 'sms_from',
            setSmsTwilioSid: 'sms_twilio_sid', setSmsTwilioToken: 'sms_twilio_token',
            setSmsBulkvsKey: 'sms_bulkvs_api_key', setSmsBulkvsSecret: 'sms_bulkvs_secret',
            setSmsPushbulletToken: 'sms_pushbullet_token', setSmsPushbulletDevice: 'sms_pushbullet_device',
            setSmsGenericMethod: 'sms_generic_method', setSmsGenericUrl: 'sms_generic_url',
            setSmsGenericContentType: 'sms_generic_content_type', setSmsGenericAuth: 'sms_generic_auth_header',
            setSmsGenericApiKey: 'sms_generic_api_key', setSmsGenericTemplate: 'sms_generic_template'
        };

        apiGet('settings').then(function (data) {
            var s = data.settings || {};
            for (var id in smsMap) {
                var el = document.getElementById(id);
                if (!el) continue;
                var key = smsMap[id];
                if (el.getAttribute('data-secret') === '1') {
                    // Secret values are never sent to the browser — the server
                    // returns a <key>_set flag instead. Reflect that in the
                    // placeholder so an empty field doesn't imply a stored value.
                    el.value = '';
                    el.placeholder = s[key + '_set']
                        ? '•••• stored — leave blank to keep, type to replace'
                        : 'Not set — enter to configure';
                } else if (s[key] !== undefined) {
                    el.value = s[key];
                }
            }
            toggleSmsGroups();
        });

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var pairs = {};
            for (var id in smsMap) {
                var el = document.getElementById(id);
                if (!el) continue;
                // BUG 1 (2026-06-25): blank secret = keep stored value.
                if (el.getAttribute('data-secret') === '1' && (el.value || '') === '') continue;
                pairs[smsMap[id]] = el.value;
            }
            apiPost('settings', { settings: pairs }).then(function (data) {
                showAlert('SMS settings saved.', 'success');
            }).catch(function (err) { showAlert(err.message, 'danger'); });
        });

        // Test SMS
        var testBtn = document.getElementById('btnTestSms');
        if (testBtn && !testBtn._bound) {
            testBtn._bound = true;
            testBtn.addEventListener('click', function () {
                var to = prompt('Send test SMS to (phone number):');
                if (!to) return;
                testBtn.disabled = true;
                fetch('api/chat.php', {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                    body: JSON.stringify({ action: 'test_channel', channel: 'sms', to: to, body: 'Test SMS from TicketsCAD', csrf_token: csrfToken })
                })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    testBtn.disabled = false;
                    // test_channel sends via broker_send() directly, so the result
                    // reflects the SMS provider — independent of routing-enable.
                    if (data.error) { showAlert('SMS test error: ' + data.error, 'danger'); return; }
                    var result = data.result || null;
                    if (result && result.success) {
                        showAlert('Test SMS sent to ' + to, 'success');
                    } else {
                        showAlert('SMS failed: ' + ((result && result.error) || 'no result') + ' — check your SMS provider credentials.', 'danger');
                    }
                })
                .catch(function (err) { testBtn.disabled = false; showAlert(err.message, 'danger'); });
            });
        }
    }

    // ── Telegram Config ──
    function loadTelegramConfig() {
        var form = document.getElementById('telegramConfigForm');
        if (!form) return;
        apiGet('settings').then(function (data) {
            var s = data.settings || {};
            var bt = document.getElementById('setTelegramToken');
            var ci = document.getElementById('setTelegramChat');
            if (bt && s.telegram_bot_token) bt.value = s.telegram_bot_token;
            if (ci && s.telegram_chat_id) ci.value = s.telegram_chat_id;
        });
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var pairs = {
                telegram_bot_token: document.getElementById('setTelegramToken').value,
                telegram_chat_id: document.getElementById('setTelegramChat').value
            };
            apiPost('settings', { settings: pairs }).then(function (data) {
                showAlert('Telegram settings saved.');
            }).catch(function (err) { showAlert(err.message, 'danger'); });
        });
    }

    // ── Slack Config ──
    function loadSlackConfig() {
        var form = document.getElementById('slackConfigForm');
        if (!form) return;

        var modeEl = document.getElementById('setSlackMode');
        var webhookGroup = document.getElementById('slackWebhookGroup');
        var apiGroup = document.getElementById('slackApiGroup');

        function toggleSlackGroups() {
            var v = modeEl.value;
            if (webhookGroup) webhookGroup.style.display = (v === 'webhook') ? '' : 'none';
            if (apiGroup) apiGroup.style.display = (v === 'api') ? '' : 'none';
        }
        modeEl.addEventListener('change', toggleSlackGroups);

        var slackMap = {
            setSlackMode: 'slack_mode', setSlackChannel: 'slack_channel',
            setSlackWebhook: 'slack_webhook', setSlackToken: 'slack_token'
        };

        apiGet('settings').then(function (data) {
            var s = data.settings || {};
            for (var id in slackMap) {
                var el = document.getElementById(id);
                if (!el) continue;
                var key = slackMap[id];
                if (el.getAttribute('data-secret') === '1') {
                    // Secret value never sent — reflect stored state in placeholder.
                    el.value = '';
                    el.placeholder = s[key + '_set']
                        ? '•••• stored — leave blank to keep, type to replace'
                        : 'Not set — enter to configure';
                } else if (s[key] !== undefined) {
                    el.value = s[key];
                }
            }
            toggleSlackGroups();
        });

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var pairs = {};
            for (var id in slackMap) {
                var el = document.getElementById(id);
                if (!el) continue;
                // Blank secret = keep stored value (never overwrite with empty).
                if (el.getAttribute('data-secret') === '1' && (el.value || '') === '') continue;
                pairs[slackMap[id]] = el.value;
            }
            apiPost('settings', { settings: pairs }).then(function (data) {
                showAlert('Slack settings saved.', 'success');
            }).catch(function (err) { showAlert(err.message, 'danger'); });
        });

        var testBtn = document.getElementById('btnTestSlack');
        if (testBtn && !testBtn._bound) {
            testBtn._bound = true;
            testBtn.addEventListener('click', function () {
                testBtn.disabled = true;
                fetch('api/chat.php', {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                    body: JSON.stringify({ action: 'test_channel', channel: 'slack', body: 'Test message from TicketsCAD Slack integration', csrf_token: csrfToken })
                })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    testBtn.disabled = false;
                    if (data.error) { showAlert('Slack test error: ' + data.error, 'danger'); return; }
                    var result = data.result || null;
                    if (result && result.success) {
                        showAlert('Test message sent to Slack.', 'success');
                    } else {
                        showAlert('Slack failed: ' + ((result && result.error) || 'not configured'), 'danger');
                    }
                })
                .catch(function (err) { testBtn.disabled = false; showAlert(err.message, 'danger'); });
            });
        }
    }

    // ── Radio Messaging Config ──
    function loadRadioMsgConfig() {
        var form = document.getElementById('radioMsgConfigForm');
        if (!form) return;

        var radioMap = {
            setRadioProtocol: 'radio_protocol', setRadioHost: 'radio_host', setRadioPort: 'radio_port',
            setRadioText: 'radio_text_enabled', setRadioLocation: 'radio_location_enabled', setRadioVoice: 'radio_voice_enabled'
        };

        apiGet('settings').then(function (data) {
            var s = data.settings || {};
            for (var id in radioMap) {
                var el = document.getElementById(id);
                if (!el) continue;
                if (el.type === 'checkbox') {
                    el.checked = s[radioMap[id]] === '1' || s[radioMap[id]] === 'true';
                } else if (s[radioMap[id]] !== undefined) {
                    el.value = s[radioMap[id]];
                }
            }
        });

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var pairs = {};
            for (var id in radioMap) {
                var el = document.getElementById(id);
                if (!el) continue;
                pairs[radioMap[id]] = el.type === 'checkbox' ? (el.checked ? '1' : '0') : el.value;
            }
            apiPost('settings', { settings: pairs }).then(function (data) {
                showAlert('Radio messaging settings saved.', 'success');
            }).catch(function (err) { showAlert(err.message, 'danger'); });
        });
    }

    // ═══════════════════════════════════════════════════════════════
    //  RBAC — Role & Permission Management
    // ═══════════════════════════════════════════════════════════════
    var _rbacSelectedRoleId = null;

    function loadPermissionAudit() {
        var banner = document.getElementById('permAuditBanner');
        if (!banner) return;
        fetch('api/rbac.php?action=permission_audit', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data && data.error) return;
                // Phase 99u-1 — banner now counts UN-REVIEWED permissions
                // (= ungranted to any non-system role AND not yet dismissed
                // by an admin). Once an admin dismisses everything they
                // legitimately want admin-only, the banner disappears.
                var unreviewed = (data.permissions || []).filter(function (p) {
                    return p.unreviewed && !p.deprecated_alias_of;
                });
                if (unreviewed.length === 0) {
                    banner.classList.add('d-none');
                    return;
                }
                banner.classList.remove('d-none');
                var summary = document.getElementById('permAuditSummary');
                if (summary) {
                    summary.textContent = unreviewed.length + ' permission' +
                        (unreviewed.length === 1 ? '' : 's') +
                        ' un-reviewed. Each needs to either be granted to a non-system role or dismissed as intentionally admin-only.';
                }
                var detail = document.getElementById('permAuditDetails');
                if (detail) {
                    var byCat = {};
                    for (var i = 0; i < unreviewed.length; i++) {
                        var p = unreviewed[i];
                        if (!byCat[p.category]) byCat[p.category] = [];
                        byCat[p.category].push(p);
                    }
                    var html = '';
                    Object.keys(byCat).sort().forEach(function (cat) {
                        html += '<div class="mt-2"><strong>' + escHtml(cat) + '</strong><ul class="mb-0">';
                        byCat[cat].forEach(function (p) {
                            html += '<li><code>' + escHtml(p.code) + '</code>' +
                                ' — ' + escHtml(p.name || '') +
                                (p.description ? ' <span class="text-body-secondary">(' + escHtml(p.description) + ')</span>' : '') +
                                '</li>';
                        });
                        html += '</ul></div>';
                    });
                    detail.innerHTML = html;
                }
                var toggle = document.getElementById('btnPermAuditToggle');
                if (toggle) {
                    toggle.onclick = function () {
                        detail.classList.toggle('d-none');
                        toggle.textContent = detail.classList.contains('d-none')
                            ? 'Show details' : 'Hide details';
                    };
                }
            }).catch(function () {});
    }

    function loadRbac() {
        var listEl = document.getElementById('rbacRoleList');
        if (!listEl) return;

        // 2026-06-11 — permission audit. Surface permissions that
        // exist in the DB but no human-administrable role grants
        // them. Quietly hides the banner when audit is clean.
        loadPermissionAudit();

        fetch('api/rbac.php', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var roles = data.roles || [];
                if (roles.length === 0) {
                    listEl.innerHTML = '<div class="text-body-secondary small text-center py-2">No roles defined. Run migration or create roles.</div>';
                    return;
                }
                var html = '';
                for (var i = 0; i < roles.length; i++) {
                    var r = roles[i];
                    var active = _rbacSelectedRoleId === parseInt(r.id, 10) ? ' active' : '';
                    html += '<div class="list-group-item list-group-item-action d-flex align-items-center py-1 rbac-role-item' + active + '" data-role-id="' + r.id + '" style="cursor:pointer">';
                    html += '<div class="flex-grow-1">';
                    html += '<div class="fw-semibold small">' + escHtml(r.name) + '</div>';
                    html += '<div style="font-size:0.65rem" class="text-body-secondary">' + escHtml(r.description || '') + '</div>';
                    html += '</div>';
                    html += '<span class="badge bg-secondary ms-1" title="Permissions">' + (r.perm_count || 0) + '</span>';
                    html += '<span class="badge bg-primary ms-1" title="Users">' + (r.user_count || 0) + '</span>';
                    html += '</div>';
                }
                listEl.innerHTML = '<div class="list-group list-group-flush">' + html + '</div>';

                // Bind click
                var items = listEl.querySelectorAll('.rbac-role-item');
                for (var j = 0; j < items.length; j++) {
                    items[j].addEventListener('click', function () {
                        _rbacSelectedRoleId = parseInt(this.getAttribute('data-role-id'), 10);
                        loadRbacRole(_rbacSelectedRoleId);
                        // Update active state
                        var all = listEl.querySelectorAll('.rbac-role-item');
                        for (var k = 0; k < all.length; k++) all[k].classList.remove('active');
                        this.classList.add('active');
                    });
                }
            })
            .catch(function (err) {
                listEl.innerHTML = '<div class="alert alert-danger small">Failed to load roles: ' + escHtml(err.message) + '</div>';
            });

        // New role button
        var newBtn = document.getElementById('btnNewRole');
        if (newBtn && !newBtn._bound) {
            newBtn._bound = true;
            newBtn.addEventListener('click', function () {
                // Phase 11c (2026-06-11): replace the bare prompt() with an
                // inline form that captures Name AND Description. The form
                // renders into #rbacPermPanel (right column) so the admin
                // sees it next to the existing role list. Save fires
                // save_role with both fields and reloads the list.
                var panel = document.getElementById('rbacPermPanel');
                if (!panel) return;
                panel.innerHTML =
                    '<form id="rbacNewRoleForm">' +
                    '  <h6 class="mb-3"><i class="bi bi-plus-circle me-1"></i>New Role</h6>' +
                    '  <div class="mb-2">' +
                    '    <label class="form-label form-label-sm">Name <span class="text-danger">*</span></label>' +
                    '    <input type="text" class="form-control form-control-sm" id="newRoleName" maxlength="64" required autofocus>' +
                    '  </div>' +
                    '  <div class="mb-3">' +
                    '    <label class="form-label form-label-sm">Description</label>' +
                    '    <textarea class="form-control form-control-sm" id="newRoleDesc" rows="3" maxlength="255" ' +
                    '              placeholder="What does this role do? E.g., \'Watch Commander — same access as Dispatcher with additional shift-management responsibilities.\'"></textarea>' +
                    '  </div>' +
                    '  <div class="d-flex gap-2">' +
                    '    <button type="submit" class="btn btn-sm btn-success"><i class="bi bi-check-lg me-1"></i>Create Role</button>' +
                    '    <button type="button" class="btn btn-sm btn-outline-secondary" id="newRoleCancel">Cancel</button>' +
                    '  </div>' +
                    '</form>';
                var form = document.getElementById('rbacNewRoleForm');
                form.addEventListener('submit', function (e) {
                    e.preventDefault();
                    var name = (document.getElementById('newRoleName').value || '').trim();
                    var desc = (document.getElementById('newRoleDesc').value || '').trim();
                    if (!name) { showAlert('Name is required', 'warning'); return; }
                    fetch('api/rbac.php', {
                        method: 'POST', credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'save_role', name: name, description: desc, sort_order: 10 })
                    })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (data.error) { showAlert(data.error, 'danger'); return; }
                        showAlert('Role "' + name + '" created. Now set its permissions below.', 'success');
                        _rbacSelectedRoleId = data.id;
                        // Invalidate caches that include the roles list
                        // (User Accounts dropdown + Roles panel itself).
                        cache.roles = null;
                        loadedPanels['roles-levels'] = false;
                        loadRbac();
                        loadRbacRole(data.id);
                    })
                    .catch(function (err) { showAlert(err.message, 'danger'); });
                });
                var cancelBtn = document.getElementById('newRoleCancel');
                if (cancelBtn) cancelBtn.addEventListener('click', function () {
                    panel.innerHTML =
                        '<div class="text-center text-body-secondary py-4">' +
                        '<i class="bi bi-shield-check display-6 d-block mb-2 opacity-25"></i>' +
                        '<span class="small">Select a role to view and edit its permissions</span></div>';
                });
            });
        }

        // Phase 11 (2026-06-11): gate the legacy-migration button on
        // /api/rbac.php?action=migration_status. Show the button only
        // when there are still user accounts to migrate; otherwise show
        // the green "all accounts on RBAC" confirmation. Migration from
        // a v3.x install is a ONE-TIME event — after it's done, no
        // user-facing surface needs to mention the legacy model again.
        var wrap = document.getElementById('migrateLegacyWrap');
        var done = document.getElementById('migrateLegacyDone');
        if (wrap && done) {
            fetch('api/rbac.php?action=migration_status', { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.needs_migration) {
                        wrap.style.display = '';
                        done.style.display = 'none';
                        var hint = document.getElementById('migrateLegacyHint');
                        if (hint && typeof data.legacy_only_users === 'number' && data.legacy_only_users > 0) {
                            hint.textContent = data.legacy_only_users +
                                ' user account' + (data.legacy_only_users !== 1 ? 's' : '') +
                                ' from the legacy installation still need a role. Click to assign.';
                        }
                    } else {
                        wrap.style.display = 'none';
                        done.style.display = '';
                    }
                })
                .catch(function () {
                    // Endpoint missing — fall through to showing the button
                    // (pre-Phase-11 behavior).
                    wrap.style.display = '';
                    done.style.display = 'none';
                });
        }

        // Migrate button
        var migBtn = document.getElementById('btnMigrateLevels');
        if (migBtn && !migBtn._bound) {
            migBtn._bound = true;
            var migBtnDefaultHtml = '<i class="bi bi-arrow-repeat me-1"></i>Migrate Legacy Accounts to Roles';
            migBtn.addEventListener('click', function () {
                if (!confirm('Assign roles to user accounts carried over from a legacy installation? Existing roles will not be changed.')) return;
                migBtn.disabled = true;
                migBtn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Working...';
                fetch('api/rbac.php', {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'migrate_levels' })
                })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    migBtn.disabled = false;
                    migBtn.innerHTML = migBtnDefaultHtml;
                    if (data.error) { showAlert(data.error, 'danger'); return; }
                    showAlert('Assigned roles to ' + data.migrated + ' of ' + data.total_users + ' user account(s).', 'success');
                    loadedPanels['roles-levels'] = false;
                    loadRbac();
                })
                .catch(function (err) {
                    migBtn.disabled = false;
                    migBtn.innerHTML = migBtnDefaultHtml;
                    showAlert(err.message, 'danger');
                });
            });
        }
    }

    function loadRbacRole(roleId) {
        var panel = document.getElementById('rbacPermPanel');
        if (!panel) return;
        panel.innerHTML = '<div class="text-center py-3"><div class="spinner-border spinner-border-sm"></div></div>';

        fetch('api/rbac.php?role_id=' + roleId, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.error) { panel.innerHTML = '<div class="alert alert-danger small">' + escHtml(data.error) + '</div>'; return; }

                var role = data.role;
                var perms = data.permissions || [];

                // Group permissions by category
                var cats = {};
                var catOrder = ['screen', 'widget', 'action', 'field'];
                var catLabels = { screen: 'Screens', widget: 'Widgets', action: 'Actions', field: 'Data Visibility' };
                for (var i = 0; i < perms.length; i++) {
                    var p = perms[i];
                    var cat = p.category;
                    if (!cats[cat]) cats[cat] = [];
                    cats[cat].push(p);
                }

                // Phase 11c (2026-06-11): make the name + description
                // editable for ALL roles (including the 6 installation-
                // included ones). save_role accepts both fields and has
                // no is_system guard on UPDATE — admin can rename
                // "Dispatcher" to "Watch Commander" or whatever fits.
                // The header collapses into edit mode when the admin
                // clicks the pencil icon. Save fires a save_role POST
                // and reloads the role list + panel.
                var html = '<div class="d-flex align-items-center mb-2">';
                html += '<h6 class="mb-0" id="rbacRoleHeaderName"><i class="bi bi-shield-check me-1"></i>' + escHtml(role.name) + '</h6>';
                html += '<span class="badge bg-secondary ms-2">' + data.user_count + ' user(s)</span>';
                html += '<button type="button" class="btn btn-sm btn-link p-0 ms-2" id="rbacRoleEditToggle" title="Rename or edit description">' +
                        '<i class="bi bi-pencil"></i></button>';
                html += '</div>';
                html += '<p class="text-body-secondary small mb-2" id="rbacRoleHeaderDesc">' +
                        escHtml(role.description || 'No description — click the pencil to add one') + '</p>';

                // Hidden edit form (toggled by the pencil button).
                var mobileFirstChecked = parseInt(role.mobile_first || 0, 10) === 1 ? ' checked' : '';
                html += '<form id="rbacRoleEditForm" class="border rounded p-2 mb-3 d-none">';
                html += '  <div class="mb-2">';
                html += '    <label class="form-label form-label-sm mb-1">Name <span class="text-danger">*</span></label>';
                html += '    <input type="text" class="form-control form-control-sm" id="rbacRoleEditName" value="' + escHtml(role.name) + '" maxlength="64" required>';
                html += '  </div>';
                html += '  <div class="mb-2">';
                html += '    <label class="form-label form-label-sm mb-1">Description</label>';
                html += '    <textarea class="form-control form-control-sm" id="rbacRoleEditDesc" rows="3" maxlength="255">' + escHtml(role.description || '') + '</textarea>';
                html += '  </div>';
                html += '  <div class="form-check form-check-sm mb-2">';
                html += '    <input class="form-check-input" type="checkbox" id="rbacRoleEditMobileFirst"' + mobileFirstChecked + '>';
                html += '    <label class="form-check-label small" for="rbacRoleEditMobileFirst">';
                html += '      Send users with this role to the mobile interface on login';
                html += '    </label>';
                html += '  </div>';
                html += '  <div class="d-flex gap-2">';
                html += '    <button type="submit" class="btn btn-sm btn-success"><i class="bi bi-check-lg me-1"></i>Save</button>';
                html += '    <button type="button" class="btn btn-sm btn-outline-secondary" id="rbacRoleEditCancel">Cancel</button>';
                html += '  </div>';
                html += '</form>';

                // Permission checkboxes grouped by category
                html += '<form id="rbacPermForm">';
                for (var ci = 0; ci < catOrder.length; ci++) {
                    var cat = catOrder[ci];
                    var catPerms = cats[cat];
                    if (!catPerms) continue;

                    html += '<div class="mb-2">';
                    html += '<div class="fw-semibold small text-uppercase text-body-secondary mb-1">' + (catLabels[cat] || cat) + '</div>';
                    html += '<div class="row g-0">';
                    for (var pi = 0; pi < catPerms.length; pi++) {
                        var perm = catPerms[pi];
                        var checked = parseInt(perm.granted, 10) === 1 ? ' checked' : '';
                        html += '<div class="col-md-6 col-lg-4">';
                        html += '<div class="form-check form-check-sm py-0">';
                        html += '<input class="form-check-input rbac-perm-cb" type="checkbox" value="' + perm.id + '" id="perm_' + perm.id + '"' + checked + '>';
                        html += '<label class="form-check-label small" for="perm_' + perm.id + '" title="' + escHtml(perm.description || '') + '">';
                        html += escHtml(perm.name);
                        html += '</label></div></div>';
                    }
                    html += '</div></div>';
                }

                html += '<div class="d-flex gap-2 mt-2">';
                html += '<button type="submit" class="btn btn-sm btn-success"><i class="bi bi-check-lg me-1"></i>Save Permissions</button>';
                html += '<button type="button" class="btn btn-sm btn-outline-secondary" id="rbacSelectAll">Select All</button>';
                html += '<button type="button" class="btn btn-sm btn-outline-secondary" id="rbacDeselectAll">Deselect All</button>';
                // Phase 11c (2026-06-11): every role is deletable. The
                // server-side lockout-safety check refuses if this would
                // leave nobody with super-admin access — that's the only
                // remaining guard. Per Eric: no installation-included vs.
                // custom distinction.
                html += '<button type="button" class="btn btn-sm btn-outline-danger ms-auto" id="rbacDeleteRole">Delete Role</button>';
                html += '</div></form>';

                panel.innerHTML = html;

                // Phase 11c (2026-06-11): wire the name/description edit
                // toggle. Clicking the pencil reveals the inline form;
                // Save fires save_role and reloads. Works for every role.
                var editToggle = document.getElementById('rbacRoleEditToggle');
                var editForm = document.getElementById('rbacRoleEditForm');
                if (editToggle && editForm) {
                    editToggle.addEventListener('click', function () {
                        editForm.classList.toggle('d-none');
                        if (!editForm.classList.contains('d-none')) {
                            var n = document.getElementById('rbacRoleEditName');
                            if (n) n.focus();
                        }
                    });
                    var editCancel = document.getElementById('rbacRoleEditCancel');
                    if (editCancel) editCancel.addEventListener('click', function () {
                        editForm.classList.add('d-none');
                    });
                    editForm.addEventListener('submit', function (e) {
                        e.preventDefault();
                        var newName = (document.getElementById('rbacRoleEditName').value || '').trim();
                        var newDesc = (document.getElementById('rbacRoleEditDesc').value || '').trim();
                        var mobileCb = document.getElementById('rbacRoleEditMobileFirst');
                        var newMobileFirst = mobileCb && mobileCb.checked ? 1 : 0;
                        if (!newName) { showAlert('Name is required', 'warning'); return; }
                        fetch('api/rbac.php', {
                            method: 'POST', credentials: 'same-origin',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({
                                action: 'save_role',
                                id: roleId,
                                name: newName,
                                description: newDesc,
                                mobile_first: newMobileFirst,
                                sort_order: parseInt(role.sort_order || 0, 10)
                            })
                        })
                        .then(function (r) { return r.json(); })
                        .then(function (data) {
                            if (data.error) { showAlert(data.error, 'danger'); return; }
                            showAlert('Role updated.', 'success');
                            // Invalidate role caches and reload everywhere
                            // the name is rendered (User Accounts dropdown,
                            // role list in this panel).
                            cache.roles = null;
                            loadedPanels['roles-levels'] = false;
                            loadRbac();
                            loadRbacRole(roleId);
                        })
                        .catch(function (err) { showAlert(err.message, 'danger'); });
                    });
                }

                // Bind save
                var form = document.getElementById('rbacPermForm');
                form.addEventListener('submit', function (e) {
                    e.preventDefault();
                    var cbs = form.querySelectorAll('.rbac-perm-cb:checked');
                    var ids = [];
                    for (var c = 0; c < cbs.length; c++) ids.push(parseInt(cbs[c].value, 10));
                    fetch('api/rbac.php', {
                        method: 'POST', credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'set_permissions', role_id: roleId, permission_ids: ids })
                    })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (data.error) { showAlert(data.error, 'danger'); return; }
                        showAlert('Saved ' + data.count + ' permissions for ' + role.name + '.', 'success');
                        loadedPanels['roles-levels'] = false;
                        loadRbac();
                    })
                    .catch(function (err) { showAlert(err.message, 'danger'); });
                });

                // Select/deselect all
                var selAll = document.getElementById('rbacSelectAll');
                if (selAll) selAll.addEventListener('click', function () {
                    var cbs = form.querySelectorAll('.rbac-perm-cb');
                    for (var c = 0; c < cbs.length; c++) cbs[c].checked = true;
                });
                var deselAll = document.getElementById('rbacDeselectAll');
                if (deselAll) deselAll.addEventListener('click', function () {
                    var cbs = form.querySelectorAll('.rbac-perm-cb');
                    for (var c = 0; c < cbs.length; c++) cbs[c].checked = false;
                });

                // Delete role
                var delBtn = document.getElementById('rbacDeleteRole');
                if (delBtn) delBtn.addEventListener('click', function () {
                    if (!confirm('Delete role "' + role.name + '"? Users assigned to this role will lose these permissions.')) return;
                    fetch('api/rbac.php', {
                        method: 'POST', credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'delete_role', id: roleId })
                    })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (data.error) { showAlert(data.error, 'danger'); return; }
                        showAlert('Role deleted.', 'success');
                        _rbacSelectedRoleId = null;
                        panel.innerHTML = '<div class="text-center text-body-secondary py-4"><span class="small">Select a role</span></div>';
                        loadedPanels['roles-levels'] = false;
                        loadRbac();
                    })
                    .catch(function (err) { showAlert(err.message, 'danger'); });
                });
            })
            .catch(function (err) {
                panel.innerHTML = '<div class="alert alert-danger small">' + escHtml(err.message) + '</div>';
            });
    }

    // ═══════════════════════════════════════════════════════════════
    //  RBAC v2 — Settings + User Grants (rbac-redesign-2026-05)
    // ═══════════════════════════════════════════════════════════════

    function loadRbacSettings() {
        var inputs = document.querySelectorAll('[data-rbac-setting]');
        if (!inputs.length) return;
        fetch('api/config-admin.php?section=settings', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var s = data.settings || {};
                for (var i = 0; i < inputs.length; i++) {
                    var key = inputs[i].getAttribute('data-rbac-setting');
                    if (s[key] !== undefined && s[key] !== null) {
                        inputs[i].value = String(s[key]);
                    }
                }
            })
            .catch(function () { /* settings reload best-effort */ });
    }

    function bindRbacSettingsSave() {
        var btn = document.getElementById('btnSaveRbacSettings');
        if (!btn) return;
        btn.addEventListener('click', function () {
            var payload = { csrf_token: getCsrfToken(), settings: {} };
            var inputs = document.querySelectorAll('[data-rbac-setting]');
            for (var i = 0; i < inputs.length; i++) {
                payload.settings[inputs[i].getAttribute('data-rbac-setting')] = inputs[i].value;
            }
            fetch('api/config-admin.php?section=settings', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (d.error) toast(d.error, 'danger');
                    else toast('RBAC settings saved', 'success');
                })
                .catch(function (e) { toast(e.message, 'danger'); });
        });
    }

    function loadRbacGrants() {
        var listEl = document.getElementById('rbacGrantList');
        if (!listEl) return;
        listEl.innerHTML = '<div class="text-center py-3"><div class="spinner-border spinner-border-sm"></div></div>';
        // Pull users + each user's grants. The canonical users endpoint
        // is /api/config-admin.php?section=users (returns { rows: [...] }).
        // The earlier reference to /api/users.php was a 404 → its HTML
        // error page broke JSON.parse on the Roles & Permissions panel.
        // Fixed 2026-06-11.
        fetch('api/config-admin.php?section=users', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (uData) {
                var users = uData.rows || uData.users || uData || [];
                var promises = [];
                for (var i = 0; i < users.length; i++) {
                    (function (u) {
                        var p = fetch('api/rbac.php?user_id=' + u.id + '&grants=1' +
                                      '&include_expired=' + (document.getElementById('grantFilterExpiry').value === 'all' ? '1' : '0'),
                                      { credentials: 'same-origin' })
                            .then(function (r) { return r.json(); })
                            .then(function (gd) { return { user: u, grants: gd.grants || [] }; })
                            .catch(function () { return { user: u, grants: [] }; });
                        promises.push(p);
                    })(users[i]);
                }
                return Promise.all(promises);
            })
            .then(function (results) { renderRbacGrantList(results); })
            .catch(function (e) {
                listEl.innerHTML = '<div class="alert alert-danger small">' + escHtml(e.message) + '</div>';
            });
    }

    function renderRbacGrantList(results) {
        var listEl    = document.getElementById('rbacGrantList');
        var searchVal = (document.getElementById('grantSearchUser').value || '').toLowerCase();
        var scopeVal  = document.getElementById('grantFilterScope').value;
        var expVal    = document.getElementById('grantFilterExpiry').value;
        var html      = '<div class="table-responsive"><table class="table table-sm align-middle mb-0">';
        html += '<thead><tr><th>User</th><th>Role</th><th>Scope</th><th>Expires</th><th class="text-end">Actions</th></tr></thead><tbody>';
        var rowCount  = 0;
        var now       = new Date();
        var soon      = new Date(now.getTime() + 7 * 86400000);
        for (var i = 0; i < results.length; i++) {
            var r = results[i];
            var u = r.user;
            if (searchVal && (u.user || u.username || '').toLowerCase().indexOf(searchVal) === -1) continue;
            for (var j = 0; j < r.grants.length; j++) {
                var g = r.grants[j];
                if (scopeVal && g.scope_kind !== scopeVal) continue;
                var expDate = g.expires_at ? new Date(g.expires_at) : null;
                if (expVal === 'expiring' && (!expDate || expDate < now || expDate > soon)) continue;
                var rowCls = '';
                if (expDate && expDate < now) rowCls = 'table-secondary';
                else if (expDate && expDate < soon) rowCls = 'table-warning';
                html += '<tr class="' + rowCls + '">';
                html += '<td>' + escHtml(u.user || u.username || ('#' + u.id)) + '</td>';
                html += '<td>' + escHtml(g.role_name || ('#' + g.role_id)) +
                        (parseInt(g.is_super, 10) ? ' <i class="bi bi-shield-fill-check text-warning" title="Super Admin"></i>' : '') +
                        '</td>';
                html += '<td><span class="badge bg-secondary">' + escHtml(g.scope_kind) + '</span>';
                if (g.scope_id) html += ' <small class="text-body-secondary">#' + parseInt(g.scope_id, 10) + '</small>';
                html += '</td>';
                html += '<td>' + (g.expires_at ? '<span class="small">' + escHtml(g.expires_at) + '</span>' : '<span class="text-body-tertiary small">never</span>') + '</td>';
                html += '<td class="text-end">';
                html += '<button class="btn btn-sm btn-outline-danger rbac-revoke-btn" data-grant-id="' + parseInt(g.grant_id, 10) + '">';
                html += '<i class="bi bi-trash"></i></button>';
                html += '</td></tr>';
                rowCount++;
            }
        }
        html += '</tbody></table></div>';
        if (rowCount === 0) {
            html = '<div class="text-body-secondary text-center py-3">No grants match the current filters.</div>';
        }
        listEl.innerHTML = html;
        var revokeBtns = listEl.querySelectorAll('.rbac-revoke-btn');
        for (var k = 0; k < revokeBtns.length; k++) {
            revokeBtns[k].addEventListener('click', onRbacRevokeClick);
        }
    }

    function onRbacRevokeClick(ev) {
        var gid = parseInt(ev.currentTarget.getAttribute('data-grant-id'), 10);
        if (!gid) return;
        if (!confirm('Revoke this grant?')) return;
        fetch('api/rbac.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                csrf_token: getCsrfToken(),
                action: 'revoke_grant',
                grant_id: gid,
                reason: 'Revoked from settings UI'
            })
        })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d.error) toast(d.error, 'danger');
                else { toast('Grant revoked', 'success'); loadRbacGrants(); }
            });
    }

    function bindRbacGrantModal() {
        var modal = document.getElementById('grantRoleModal');
        if (!modal) return;

        // When the modal opens, hydrate user + role selects.
        modal.addEventListener('show.bs.modal', function () {
            // Users — same 2026-06-11 fix as loadRbacGrants():
            // the canonical endpoint is config-admin.php?section=users,
            // not api/users.php (which 404s).
            fetch('api/config-admin.php?section=users', { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    var users = d.rows || d.users || d || [];
                    var sel = document.getElementById('grantUserId');
                    sel.innerHTML = '';
                    for (var i = 0; i < users.length; i++) {
                        var o = document.createElement('option');
                        o.value = users[i].id;
                        o.textContent = (users[i].user || users[i].username || ('#' + users[i].id));
                        sel.appendChild(o);
                    }
                });
            // Roles
            fetch('api/rbac.php', { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    var sel = document.getElementById('grantRoleId');
                    sel.innerHTML = '';
                    var roles = d.roles || [];
                    for (var i = 0; i < roles.length; i++) {
                        var o = document.createElement('option');
                        o.value = roles[i].id;
                        o.textContent = roles[i].name + (parseInt(roles[i].is_super, 10) ? ' (Super)' : '');
                        sel.appendChild(o);
                    }
                });
            // Reset other fields
            document.getElementById('grantScopeKind').value = 'global';
            document.getElementById('grantScopeIdWrap').style.display = 'none';
            document.getElementById('grantExpiresAt').value = '';
            document.getElementById('grantReason').value = '';
        });

        // Toggle scope_id input visibility based on scope kind.
        document.getElementById('grantScopeKind').addEventListener('change', function () {
            var kind = this.value;
            var wrap = document.getElementById('grantScopeIdWrap');
            var label = document.getElementById('grantScopeIdLabel');
            if (kind === 'global' || kind === 'self') {
                wrap.style.display = 'none';
            } else {
                wrap.style.display = '';
                label.textContent = ({
                    'org':      'Organization ID',
                    'team':     'Team ID',
                    'delegate': 'Delegating user ID'
                })[kind] || 'Scope ID';
            }
        });

        document.getElementById('btnConfirmGrant').addEventListener('click', function () {
            var payload = {
                csrf_token: getCsrfToken(),
                action: 'grant_role',
                user_id: parseInt(document.getElementById('grantUserId').value, 10),
                role_id: parseInt(document.getElementById('grantRoleId').value, 10),
                scope_kind: document.getElementById('grantScopeKind').value,
                scope_id: parseInt(document.getElementById('grantScopeId').value, 10) || null,
                expires_at: document.getElementById('grantExpiresAt').value || null,
                reason: document.getElementById('grantReason').value || null
            };
            // Delegate scope: the "Delegating user ID" (scope_id) is the user whose
            // authority is being handed off. The server requires it as delegated_by
            // too — without it, rbac_grant_role() throws "delegated_by is required
            // for delegate scope". (Delegation depth is computed server-side.)
            if (payload.scope_kind === 'delegate') {
                payload.delegated_by = payload.scope_id;
            }
            // Datetime-local inputs come as YYYY-MM-DDTHH:MM — server accepts.
            if (payload.expires_at) payload.expires_at = payload.expires_at.replace('T', ' ');
            fetch('api/rbac.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (d.error) { toast(d.error, 'danger'); return; }
                    toast('Role granted (id ' + d.grant_id + ')', 'success');
                    var inst = bootstrap.Modal.getInstance(document.getElementById('grantRoleModal'));
                    if (inst) inst.hide();
                    loadRbacGrants();
                })
                .catch(function (e) { toast(e.message, 'danger'); });
        });
    }

    function bindRbacGrantFilters() {
        var ids = ['grantSearchUser', 'grantFilterScope', 'grantFilterExpiry'];
        for (var i = 0; i < ids.length; i++) {
            var el = document.getElementById(ids[i]);
            if (!el) continue;
            el.addEventListener('input',  function () { loadRbacGrants(); });
            el.addEventListener('change', function () { loadRbacGrants(); });
        }
    }

    // Hook into the existing tab loader so the new sections populate
    // when the user opens the Roles & Permissions panel.
    var _origLoadRbac = loadRbac;
    loadRbac = function () {
        _origLoadRbac();
        loadRbacSettings();
        loadRbacGrants();
    };

    // Expose entry points for the dedicated roles.php page (which
    // doesn't go through the settings tab plumbing).
    window.loadRbac         = loadRbac;
    window.loadRbacGrants   = loadRbacGrants;
    window.loadRbacSettings = loadRbacSettings;

    document.addEventListener('DOMContentLoaded', function () {
        bindRbacSettingsSave();
        bindRbacGrantModal();
        bindRbacGrantFilters();
    });

    function getCsrfToken() {
        var el = document.getElementById('csrfToken');
        return el ? el.value : '';
    }

    function toast(msg, type) {
        // Minimal fallback — settings.js may have its own. If not, alert.
        if (typeof window.showToast === 'function') {
            window.showToast(msg, type);
            return;
        }
        var w = document.getElementById('toastWrap') || document.body;
        var d = document.createElement('div');
        d.className = 'alert alert-' + (type === 'danger' ? 'danger' : (type === 'success' ? 'success' : 'info')) +
                      ' position-fixed top-0 end-0 m-3 shadow';
        d.style.zIndex = 9999;
        d.textContent = msg;
        w.appendChild(d);
        setTimeout(function () { d.remove(); }, 3500);
    }

    // ═══════════════════════════════════════════════════════════════
    //  HELPERS
    // ═══════════════════════════════════════════════════════════════
    function esc(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str || ''));
        return div.innerHTML;
    }
    // Alias — some modules use escHtml instead of esc
    var escHtml = esc;

    function fetchJSON(url, options) {
        options = options || {};
        options.headers = options.headers || {};
        if (!options.headers['Content-Type'] && options.body) {
            options.headers['Content-Type'] = 'application/json';
        }
        return fetch(url, options).then(function (resp) {
            return resp.json().then(function (data) {
                if (!resp.ok) {
                    throw new Error(data.error || 'Request failed');
                }
                return data;
            });
        });
    }

    function apiGet(section) {
        return fetchJSON(API + '?section=' + section);
    }

    function apiPost(section, body) {
        body.csrf_token = csrfToken;
        return fetchJSON(API + '?section=' + section, {
            method: 'POST',
            body: JSON.stringify(body)
        });
    }

    function apiDelete(section, id) {
        return fetchJSON(API + '?section=' + section + '&id=' + id + '&csrf_token=' + encodeURIComponent(csrfToken), {
            method: 'DELETE'
        });
    }

    /**
     * POST to a standalone API endpoint (not settings.php).
     * @param {string} endpoint  e.g. 'location', 'unit-assignments', 'scheduling-permissions'
     * @param {object} body      JSON payload (csrf_token auto-added)
     * @return {Promise}
     */
    function apiPostDirect(endpoint, body) {
        body.csrf_token = csrfToken;
        return fetchJSON('api/' + endpoint + '.php', {
            method: 'POST',
            body: JSON.stringify(body)
        });
    }

    function showAlert(msg, type) {
        // Fixed-position toast visible regardless of scroll position
        var container = document.getElementById('configToastContainer');
        if (!container) {
            container = document.createElement('div');
            container.id = 'configToastContainer';
            container.style.cssText = 'position:fixed;bottom:1rem;right:1rem;z-index:1090;max-width:400px;';
            document.body.appendChild(container);
        }

        var div = document.createElement('div');
        div.className = 'alert alert-' + (type || 'success') + ' alert-dismissible fade show py-2 px-3 small shadow';
        div.setAttribute('role', 'alert');
        div.innerHTML = esc(msg) +
            '<button type="button" class="btn-close btn-close-sm" data-bs-dismiss="alert"></button>';
        container.appendChild(div);

        setTimeout(function () {
            if (div.parentNode) div.remove();
        }, 5000);
    }

    /**
     * Sync a color picker + text input pair.
     * Pass the picker element and text element.
     */
    function syncColorPair(picker, textInput) {
        picker.addEventListener('input', function () {
            textInput.value = picker.value;
        });
        textInput.addEventListener('input', function () {
            if (/^#[0-9a-fA-F]{6}$/.test(textInput.value)) {
                picker.value = textInput.value;
            }
        });
    }

    // ═══════════════════════════════════════════════════════════════
    //  INCIDENT TYPES
    // ═══════════════════════════════════════════════════════════════
    function bindTypesPanel() {
        var form = document.getElementById('typeForm');
        var panel = document.getElementById('typeEditPanel');
        var search = document.getElementById('typeSearch');
        var groupFilter = document.getElementById('typeGroupFilter');

        document.getElementById('btnAddType').addEventListener('click', function () {
            openTypeForm(null);
        });
        document.getElementById('btnCancelType').addEventListener('click', function () {
            panel.classList.remove('show');
        });
        document.getElementById('btnDeleteType').addEventListener('click', function () {
            var id = document.getElementById('typeId').value;
            if (!id) return;
            if (!confirm('Delete this incident type?')) return;
            apiDelete('types', id).then(function () {
                panel.classList.remove('show');
                showAlert('Type deleted');
                loadedPanels['incident-types'] = false;
                loadTypes();
            }).catch(function (err) {
                showAlert(err.message, 'danger');
            });
        });

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var data = formToObject(form);
            apiPost('types', data).then(function () {
                panel.classList.remove('show');
                showAlert('Incident type saved');
                loadedPanels['incident-types'] = false;
                loadTypes();
            }).catch(function (err) {
                showAlert(err.message, 'danger');
            });
        });

        // Color picker sync
        syncColorPair(
            document.getElementById('typeColor'),
            document.getElementById('typeColorText')
        );

        // Live regex pattern testing — updates as user types in either field
        var patternInput = document.getElementById('typeMatchPattern');
        var testInput = document.getElementById('patternTestInput');
        var testResult = document.getElementById('patternTestResult');

        function updatePatternTest() {
            var pattern = patternInput.value.trim();
            var text = testInput.value.trim();
            if (!pattern || !text) {
                testResult.innerHTML = '<i class="bi bi-dash text-body-secondary"></i>';
                return;
            }
            try {
                var re = new RegExp(pattern, 'i');
                if (re.test(text)) {
                    testResult.innerHTML = '<i class="bi bi-check-circle-fill text-success"></i>';
                } else {
                    testResult.innerHTML = '<i class="bi bi-x-circle text-warning"></i>';
                }
            } catch (ex) {
                testResult.innerHTML = '<i class="bi bi-exclamation-triangle-fill text-danger" title="' + esc(ex.message) + '"></i>';
            }
        }

        patternInput.addEventListener('input', updatePatternTest);
        testInput.addEventListener('input', updatePatternTest);

        // Search filter
        if (search) {
            search.addEventListener('input', function () { renderTypes(); });
        }
        if (groupFilter) {
            groupFilter.addEventListener('change', function () { renderTypes(); });
        }
    }

    function loadTypes() {
        apiGet('types').then(function (data) {
            cache.types = data.rows || [];
            populateGroupFilter();
            renderTypes();
        }).catch(function (err) {
            document.getElementById('typesStatus').textContent = 'Error: ' + err.message;
        });
    }

    function populateGroupFilter() {
        var sel = document.getElementById('typeGroupFilter');
        var groups = {};
        for (var i = 0; i < cache.types.length; i++) {
            var g = cache.types[i].group || '';
            if (g) groups[g] = true;
        }
        var html = '<option value="">All Groups</option>';
        var sorted = Object.keys(groups).sort();
        for (var j = 0; j < sorted.length; j++) {
            html += '<option value="' + esc(sorted[j]) + '">' + esc(sorted[j]) + '</option>';
        }
        sel.innerHTML = html;
    }

    function renderTypes() {
        var tbody = document.getElementById('typesTableBody');
        var search = (document.getElementById('typeSearch').value || '').toLowerCase();
        var group = document.getElementById('typeGroupFilter').value;
        var sevLabels = ['Normal', 'Elevated', 'Critical'];
        var html = '';
        var count = 0;

        for (var i = 0; i < cache.types.length; i++) {
            var t = cache.types[i];
            var text = ((t.type || '') + ' ' + (t.description || '') + ' ' + (t.group || '')).toLowerCase();
            if (search && text.indexOf(search) === -1) continue;
            if (group && (t.group || '') !== group) continue;
            count++;
            html += '<tr data-id="' + t.id + '">' +
                '<td>' + t.id + '</td>' +
                '<td>' + esc(t.type) + '</td>' +
                '<td>' + esc(t.group || '') + '</td>' +
                '<td>' + (sevLabels[t.set_severity] || t.set_severity) + '</td>' +
                '<td><span class="color-swatch" style="background:' + esc(t.color || '#ccc') + '"></span></td>' +
                '<td>' + (t.match_pattern ? '<i class="bi bi-regex text-info" title="' + esc(t.match_pattern) + '"></i>' : '') + '</td>' +
                '<td>' + (t.sort || 0) + '</td>' +
                '</tr>';
        }

        tbody.innerHTML = html || '<tr><td colspan="7" class="text-center text-body-secondary py-3">No incident types found</td></tr>';
        document.getElementById('typesStatus').textContent = count + ' incident type' + (count !== 1 ? 's' : '') + ' loaded';

        // Row click → edit
        var rows = tbody.querySelectorAll('tr[data-id]');
        for (var j = 0; j < rows.length; j++) {
            rows[j].addEventListener('click', function () {
                var id = parseInt(this.getAttribute('data-id'), 10);
                var item = findById(cache.types, id);
                if (item) openTypeForm(item);
            });
        }
    }

    function openTypeForm(item) {
        var panel = document.getElementById('typeEditPanel');
        var deleteBtn = document.getElementById('btnDeleteType');

        document.getElementById('typeId').value = item ? item.id : '';
        document.getElementById('typeName').value = item ? item.type : '';
        document.getElementById('typeGroup').value = item ? (item.group || '') : '';
        document.getElementById('typeSeverity').value = item ? item.set_severity : '0';
        document.getElementById('typeSort').value = item ? (item.sort || 0) : 0;
        document.getElementById('typeDesc').value = item ? (item.description || '') : '';

        var color = item ? (item.color || '#0d6efd') : '#0d6efd';
        document.getElementById('typeColor').value = color;
        document.getElementById('typeColorText').value = color;

        document.getElementById('typeRadius').value = item ? (item.radius || 0) : 0;
        // Phase 26A (2026-06-11), revised Phase 32 (2026-06-12) — tri-state PAR.
        // par_mode = 'default' | 'override' | 'disabled'.
        var parField = document.getElementById('typeParCadence');
        var modeDef  = document.getElementById('typeParModeDefault');
        var modeOvr  = document.getElementById('typeParModeOverride');
        var modeDis  = document.getElementById('typeParModeDisabled');
        if (parField && modeDef && modeOvr && modeDis) {
            var mode = (item && item.par_mode) ? item.par_mode : 'default';
            var cad  = item ? (parseInt(item.par_cadence_minutes, 10) || 0) : 0;
            modeDef.checked = (mode === 'default');
            modeOvr.checked = (mode === 'override');
            modeDis.checked = (mode === 'disabled');
            parField.value    = cad > 0 ? cad : '';
            parField.disabled = (mode !== 'override');
            // Wire radio change → enable / clear field
            function applyParMode() {
                if (modeOvr.checked) {
                    parField.disabled = false;
                    if (!parField.value) parField.value = 20;
                    parField.focus();
                } else {
                    parField.disabled = true;
                }
            }
            modeDef.onchange = applyParMode;
            modeOvr.onchange = applyParMode;
            modeDis.onchange = applyParMode;
        }
        document.getElementById('typeProtocol').value = item ? (item.protocol || '') : '';
        document.getElementById('typeMatchPattern').value = item ? (item.match_pattern || '') : '';
        document.getElementById('patternTestInput').value = '';
        document.getElementById('patternTestResult').innerHTML = '<i class="bi bi-dash text-body-secondary"></i>';

        if (item) {
            deleteBtn.classList.remove('d-none');
        } else {
            deleteBtn.classList.add('d-none');
        }

        panel.classList.add('show');
        document.getElementById('typeName').focus();
    }

    // ═══════════════════════════════════════════════════════════════
    //  UNIT STATUSES (expanded: colors, group, sort, dispatch level, flags)
    // ═══════════════════════════════════════════════════════════════
    var DISPATCH_LABELS = {
        0: 'Available',
        1: 'Inform Only',
        2: 'Unavailable'
    };
    var DISPATCH_CLASSES = {
        0: 'text-success',
        1: 'text-warning',
        2: 'text-danger'
    };

    function bindStatusesPanel() {
        var form = document.getElementById('statusForm');
        var panel = document.getElementById('statusEditPanel');
        var searchInput = document.getElementById('statusSearch');
        var groupFilter = document.getElementById('statusGroupFilter');

        document.getElementById('btnAddStatus').addEventListener('click', function () {
            openStatusForm(null);
        });
        document.getElementById('btnCancelStatus').addEventListener('click', function () {
            panel.classList.remove('show');
        });
        document.getElementById('btnDeleteStatus').addEventListener('click', function () {
            var id = document.getElementById('statusId').value;
            if (!id) return;
            if (parseInt(id, 10) === 1) {
                showAlert('Cannot delete the default Available status (id=1)', 'danger');
                return;
            }
            if (!confirm('Delete this status?')) return;
            apiDelete('statuses', id).then(function () {
                panel.classList.remove('show');
                showAlert('Status deleted');
                loadedPanels['unit-statuses'] = false;
                loadStatuses();
            }).catch(function (err) { showAlert(err.message, 'danger'); });
        });

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var data = formToObject(form);
            // Dispatch comes from the select dropdown
            data.dispatch = parseInt(document.getElementById('statusDispatchLevel').value, 10) || 0;
            // Checkboxes need explicit handling — unchecked = not in formData
            data.watch         = document.getElementById('statusWatch').checked ? 1 : 0;
            data.hide          = document.getElementById('statusHide').checked ? 'y' : 'n';
            data.excl_from_reset = document.getElementById('statusExclReset').checked ? 'y' : 'n';
            // Phase 31 (2026-06-12) — resets_par flag
            var resetsParEl = document.getElementById('statusResetsPar');
            if (resetsParEl) data.resets_par = resetsParEl.checked ? 1 : 0;
            // Phase 95 (2026-06-28) — extra_data_* fields. formToObject
            // picks up the type/target/label inputs by name; the checkbox
            // needs explicit handling.
            var extReqEl = document.getElementById('statusExtraDataRequired');
            if (extReqEl) data.extra_data_required = extReqEl.checked ? 1 : 0;
            // GH #20 round 2 — per-status facility-delivery flag
            var bedDelEl = document.getElementById('statusBedDelivery');
            if (bedDelEl) data.bed_delivery = bedDelEl.checked ? 1 : 0;
            // GH #66 — hide-from-boards flag
            var hideBoardEl = document.getElementById('statusHideFromBoard');
            if (hideBoardEl) data.hide_from_board = hideBoardEl.checked ? 1 : 0;
            // GH #68 round 2 — explicit units-filter bucket ('' = auto)
            var unitsFilterEl = document.getElementById('statusUnitsFilter');
            if (unitsFilterEl) data.units_filter = unitsFilterEl.value;
            apiPost('statuses', data).then(function () {
                panel.classList.remove('show');
                showAlert('Status saved');
                loadedPanels['unit-statuses'] = false;
                loadStatuses();
            }).catch(function (err) { showAlert(err.message, 'danger'); });
        });

        // Search filter
        if (searchInput) {
            searchInput.addEventListener('input', function () {
                renderStatuses();
            });
        }

        // Group filter
        if (groupFilter) {
            groupFilter.addEventListener('change', function () {
                renderStatuses();
            });
        }

        // Color picker sync + live preview
        var bgPicker  = document.getElementById('statusBgColorPicker');
        var bgText    = document.getElementById('statusBgColor');
        var txtPicker = document.getElementById('statusTextColorPicker');
        var txtText   = document.getElementById('statusTextColor');
        var preview   = document.getElementById('statusPreview');

        function updateStatusPreview() {
            var bg  = bgText.value || bgPicker.value || '#198754';
            var txt = txtText.value || txtPicker.value || '#ffffff';
            preview.style.backgroundColor = bg;
            preview.style.color = txt;
        }

        syncColorPair(bgPicker, bgText);
        syncColorPair(txtPicker, txtText);

        bgPicker.addEventListener('input', updateStatusPreview);
        bgText.addEventListener('input', updateStatusPreview);
        txtPicker.addEventListener('input', updateStatusPreview);
        txtText.addEventListener('input', updateStatusPreview);
    }

    function loadStatuses() {
        apiGet('statuses').then(function (data) {
            cache.statuses = data.rows || [];
            populateStatusGroupFilter();
            renderStatuses();
        }).catch(function (err) {
            document.getElementById('statusesStatus').textContent = 'Error: ' + err.message;
        });
    }

    function populateStatusGroupFilter() {
        var sel = document.getElementById('statusGroupFilter');
        if (!sel) return;
        var groups = {};
        for (var i = 0; i < cache.statuses.length; i++) {
            var g = cache.statuses[i].group || '';
            if (g && !groups[g]) groups[g] = true;
        }
        var current = sel.value;
        sel.innerHTML = '<option value="">All Groups</option>';
        var keys = Object.keys(groups).sort();
        for (var j = 0; j < keys.length; j++) {
            var opt = document.createElement('option');
            opt.value = keys[j];
            opt.textContent = keys[j];
            sel.appendChild(opt);
        }
        sel.value = current;
    }

    function renderStatuses() {
        var tbody = document.getElementById('statusesTableBody');
        var searchInput = document.getElementById('statusSearch');
        var groupFilter = document.getElementById('statusGroupFilter');
        var filter = searchInput ? searchInput.value.toLowerCase() : '';
        var groupVal = groupFilter ? groupFilter.value : '';
        var html = '';
        var count = 0;

        for (var i = 0; i < cache.statuses.length; i++) {
            var s = cache.statuses[i];

            // Apply search filter
            if (filter) {
                var searchable = (s.description || '') + ' ' + (s.status_val || '') + ' ' + (s.group || '');
                if (searchable.toLowerCase().indexOf(filter) === -1) continue;
            }

            // Apply group filter
            if (groupVal && (s.group || '') !== groupVal) continue;

            count++;
            var bgColor = s.bg_color || '';
            var txtColor = s.text_color || '';
            var colorBadge = '';
            if (bgColor) {
                colorBadge = '<span class="rounded px-2 py-0 small" style="background:' +
                    esc(bgColor) + ';color:' + esc(txtColor || '#fff') + ';">' +
                    esc(s.description) + '</span>';
            } else {
                colorBadge = esc(s.description);
            }

            var dispVal = parseInt(s.dispatch, 10) || 0;
            var dispLabel = DISPATCH_LABELS[dispVal] || 'Available';
            var dispClass = DISPATCH_CLASSES[dispVal] || '';

            html += '<tr data-id="' + s.id + '">' +
                '<td>' + s.id + '</td>' +
                '<td>' + colorBadge + '</td>' +
                '<td class="text-body-secondary small">' + esc(s.status_val || '') + '</td>' +
                '<td>' + esc(s.group || '') + '</td>' +
                '<td>' + (s.sort || 0) + '</td>' +
                '<td><span class="' + dispClass + '">' + esc(dispLabel) + '</span></td>' +
                '<td>' + (parseInt(s.watch, 10) ? '<i class="bi bi-eye text-info"></i>' : '') + '</td>' +
                '<td>' + (s.hide === 'y' || s.hide === 'Y' ? '<i class="bi bi-eye-slash text-body-secondary"></i>' : '') + '</td>' +
                '<td>' + (s.excl_from_reset === 'y' || s.excl_from_reset === 'Y' ? '<i class="bi bi-shield-check text-warning"></i>' : '') + '</td>' +
                '</tr>';
        }
        tbody.innerHTML = html || '<tr><td colspan="9" class="text-center text-body-secondary py-3">No statuses found</td></tr>';
        document.getElementById('statusesStatus').textContent = count + ' status(es) loaded';

        var rows = tbody.querySelectorAll('tr[data-id]');
        for (var j = 0; j < rows.length; j++) {
            rows[j].addEventListener('click', function () {
                var item = findById(cache.statuses, parseInt(this.getAttribute('data-id'), 10));
                if (item) openStatusForm(item);
            });
        }
    }

    function openStatusForm(item) {
        var panel = document.getElementById('statusEditPanel');
        var preview = document.getElementById('statusPreview');

        document.getElementById('statusId').value = item ? item.id : '';
        document.getElementById('statusDesc').value = item ? item.description : '';
        document.getElementById('statusVal').value = item ? (item.status_val || '') : '';
        document.getElementById('statusGroup').value = item ? (item.group || '') : '';
        document.getElementById('statusSort').value = item ? (item.sort || 0) : 0;

        // Dispatch level dropdown
        document.getElementById('statusDispatchLevel').value = item ? (parseInt(item.dispatch, 10) || 0) : 0;

        // Phase 25 (2026-06-11) — incident_action dropdown: maps this
        // status to the assigns timestamp column dispatchers set from
        // the incident-detail row.
        var iaSel = document.getElementById('statusIncidentAction');
        if (iaSel) iaSel.value = (item && item.incident_action) ? item.incident_action : '';

        // Colors
        var bg  = (item && item.bg_color) ? item.bg_color : '#198754';
        var txt = (item && item.text_color) ? item.text_color : '#ffffff';
        document.getElementById('statusBgColor').value = bg;
        document.getElementById('statusBgColorPicker').value = /^#[0-9a-fA-F]{6}$/.test(bg) ? bg : '#198754';
        document.getElementById('statusTextColor').value = txt;
        document.getElementById('statusTextColorPicker').value = /^#[0-9a-fA-F]{6}$/.test(txt) ? txt : '#ffffff';

        // Preview
        preview.style.backgroundColor = bg;
        preview.style.color = txt;

        // Flags
        document.getElementById('statusWatch').checked = item ? (parseInt(item.watch, 10) === 1) : false;
        document.getElementById('statusHide').checked = item ? (item.hide === 'y' || item.hide === 'Y') : false;
        document.getElementById('statusExclReset').checked = item ? (item.excl_from_reset === 'y' || item.excl_from_reset === 'Y') : false;
        // Phase 31 (2026-06-12) — resets_par. When unset on item
        // (legacy pre-Phase-31 row), default to checked only if the
        // incident_action is one of dispatched/responding/on_scene,
        // mirroring the migration's seed.
        var resetsParEl = document.getElementById('statusResetsPar');
        if (resetsParEl) {
            if (item && item.resets_par !== undefined && item.resets_par !== null) {
                resetsParEl.checked = parseInt(item.resets_par, 10) === 1;
            } else if (item && item.incident_action) {
                resetsParEl.checked = ['dispatched','responding','on_scene'].indexOf(item.incident_action) !== -1;
            } else {
                resetsParEl.checked = false;
            }
        }

        // Phase 95 (2026-06-28) — populate extra_data_* fields if
        // the row carries them (api/config-admin.php statuses GET
        // returns them since the schema landed). Old rows that
        // don't have the columns get safe defaults.
        var extType = document.getElementById('statusExtraDataType');
        var extTgt  = document.getElementById('statusExtraDataTarget');
        var extLbl  = document.getElementById('statusExtraDataLabel');
        var extReq  = document.getElementById('statusExtraDataRequired');
        if (extType) extType.value = (item && item.extra_data_type) ? item.extra_data_type : 'none';
        if (extTgt)  extTgt.value  = (item && item.extra_data_target) ? item.extra_data_target : 'action_log';
        if (extLbl)  extLbl.value  = (item && item.extra_data_label) ? item.extra_data_label : '';
        if (extReq)  extReq.checked = item ? (parseInt(item.extra_data_required, 10) === 1) : false;

        // GH #20 round 2 — per-status facility-delivery flag
        var bedDel = document.getElementById('statusBedDelivery');
        if (bedDel) bedDel.checked = item ? (parseInt(item.bed_delivery, 10) === 1) : false;

        // GH #66 — hide-from-boards flag
        var hideBoard = document.getElementById('statusHideFromBoard');
        if (hideBoard) hideBoard.checked = item ? (parseInt(item.hide_from_board, 10) === 1) : false;

        // GH #68 round 2 — explicit units-filter bucket
        var unitsFilter = document.getElementById('statusUnitsFilter');
        if (unitsFilter) unitsFilter.value = (item && item.units_filter) ? item.units_filter : '';

        // Protect id=1 from deletion
        var deleteBtn = document.getElementById('btnDeleteStatus');
        if (item && parseInt(item.id, 10) === 1) {
            deleteBtn.classList.add('d-none');
        } else {
            deleteBtn.classList.toggle('d-none', !item);
        }

        panel.classList.add('show');
        document.getElementById('statusDesc').focus();
    }

    // ═══════════════════════════════════════════════════════════════
    //  SIGNALS / CODES (hints table)
    // ═══════════════════════════════════════════════════════════════
    function bindSignalsPanel() {
        var form = document.getElementById('signalForm');
        var panel = document.getElementById('signalEditPanel');

        if (!form) return; // Panel not in DOM

        document.getElementById('btnAddSignal').addEventListener('click', function () {
            openSignalForm(null);
        });
        document.getElementById('btnCancelSignal').addEventListener('click', function () {
            panel.classList.remove('show');
        });
        document.getElementById('btnDeleteSignal').addEventListener('click', function () {
            var id = document.getElementById('signalId').value;
            if (!id || !confirm('Delete this signal code?')) return;
            apiDelete('signals', id).then(function () {
                panel.classList.remove('show');
                showAlert('Signal deleted');
                loadedPanels['signals'] = false;
                loadSignals();
            }).catch(function (err) { showAlert(err.message, 'danger'); });
        });

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            apiPost('signals', formToObject(form)).then(function () {
                panel.classList.remove('show');
                showAlert('Signal saved');
                loadedPanels['signals'] = false;
                loadSignals();
            }).catch(function (err) { showAlert(err.message, 'danger'); });
        });
    }

    function loadSignals() {
        apiGet('signals').then(function (data) {
            cache.signals = data.rows || [];
            renderSignals();
        }).catch(function (err) {
            var el = document.getElementById('signalsStatus');
            if (el) el.textContent = 'Error: ' + err.message;
        });
    }

    function renderSignals() {
        var tbody = document.getElementById('signalsTableBody');
        if (!tbody) return;
        var html = '';
        for (var i = 0; i < cache.signals.length; i++) {
            var s = cache.signals[i];
            html += '<tr data-id="' + s.id + '">' +
                '<td>' + s.id + '</td>' +
                '<td><strong>' + esc(s.tag) + '</strong></td>' +
                '<td>' + esc(s.hint) + '</td>' +
                '</tr>';
        }
        tbody.innerHTML = html || '<tr><td colspan="3" class="text-center text-body-secondary py-3">No signals found</td></tr>';
        document.getElementById('signalsStatus').textContent = cache.signals.length + ' signal' + (cache.signals.length !== 1 ? 's' : '') + ' loaded';

        var rows = tbody.querySelectorAll('tr[data-id]');
        for (var j = 0; j < rows.length; j++) {
            rows[j].addEventListener('click', function () {
                var item = findById(cache.signals, parseInt(this.getAttribute('data-id'), 10));
                if (item) openSignalForm(item);
            });
        }
    }

    function openSignalForm(item) {
        var panel = document.getElementById('signalEditPanel');
        document.getElementById('signalId').value = item ? item.id : '';
        document.getElementById('signalTag').value = item ? item.tag : '';
        document.getElementById('signalHint').value = item ? item.hint : '';
        document.getElementById('btnDeleteSignal').classList.toggle('d-none', !item);
        panel.classList.add('show');
        document.getElementById('signalTag').focus();
    }

    // ═══════════════════════════════════════════════════════════════
    //  SEVERITY LEVELS (uses settings API)
    // ═══════════════════════════════════════════════════════════════
    function bindSeverityPanel() {
        var form = document.getElementById('severityForm');
        if (!form) return;

        // Wire up color pickers + live preview for each severity level
        for (var i = 0; i < 3; i++) {
            (function (idx) {
                var picker = document.getElementById('sevColor' + idx + 'Picker');
                var textInput = document.getElementById('sevColor' + idx);
                var preview = document.getElementById('sevPreview' + idx);
                var labelInput = document.getElementById('sevLabel' + idx);

                if (picker && textInput) {
                    syncColorPair(picker, textInput);
                    picker.addEventListener('input', function () {
                        if (preview) preview.style.backgroundColor = picker.value;
                    });
                    textInput.addEventListener('input', function () {
                        if (/^#[0-9a-fA-F]{6}$/.test(textInput.value) && preview) {
                            preview.style.backgroundColor = textInput.value;
                        }
                    });
                }
                if (labelInput && preview) {
                    labelInput.addEventListener('input', function () {
                        preview.textContent = labelInput.value || ['Normal', 'Elevated', 'Critical'][idx];
                    });
                }
            })(i);
        }

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var pairs = collectSettingsFromForm(form);
            apiPost('settings', { settings: pairs }).then(function (data) {
                showAlert('Severity settings saved (' + data.saved + ' updated)');
            }).catch(function (err) {
                showAlert(err.message, 'danger');
            });
        });
    }

    function loadSeverity() {
        apiGet('settings').then(function (data) {
            var settings = data.settings || {};
            var form = document.getElementById('severityForm');
            applySettingsToForm(form, settings);

            // Sync color pickers and previews after loading
            for (var i = 0; i < 3; i++) {
                var textInput = document.getElementById('sevColor' + i);
                var picker = document.getElementById('sevColor' + i + 'Picker');
                var preview = document.getElementById('sevPreview' + i);
                var labelInput = document.getElementById('sevLabel' + i);

                if (textInput && picker && /^#[0-9a-fA-F]{6}$/.test(textInput.value)) {
                    picker.value = textInput.value;
                    if (preview) preview.style.backgroundColor = textInput.value;
                }
                if (labelInput && preview && labelInput.value) {
                    preview.textContent = labelInput.value;
                }
            }
        }).catch(function (err) {
            showAlert('Failed to load severity settings: ' + err.message, 'danger');
        });
    }

    // ═══════════════════════════════════════════════════════════════
    //  DISPLAY SETTINGS (uses settings API)
    // ═══════════════════════════════════════════════════════════════
    function bindDisplaySettingsPanel() {
        var form = document.getElementById('displaySettingsForm');
        if (!form) return;

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var pairs = collectSettingsFromForm(form);
            apiPost('settings', { settings: pairs }).then(function (data) {
                showAlert('Display settings saved (' + data.saved + ' updated)');
            }).catch(function (err) {
                showAlert(err.message, 'danger');
            });
        });
    }

    function loadDisplaySettings() {
        apiGet('settings').then(function (data) {
            var settings = data.settings || {};
            applySettingsToForm(document.getElementById('displaySettingsForm'), settings);
        }).catch(function (err) {
            showAlert('Failed to load display settings: ' + err.message, 'danger');
        });

        // ── Local display preferences (localStorage) ──
        loadLocalDisplayPrefs();
    }

    /**
     * Load and bind the per-browser display preferences (navbar fade, basemap).
     * These live in localStorage, not the database.
     */
    function loadLocalDisplayPrefs() {
        var fadeSelect = document.getElementById('navFadeDelay');
        var basemapLightSelect = document.getElementById('basemapLight');
        var basemapDarkSelect = document.getElementById('basemapDark');
        var saveBtn = document.getElementById('btnSaveDisplayPrefs');

        if (!fadeSelect || !saveBtn) return;

        // Read current navbar prefs
        var navPrefs = {};
        try { navPrefs = JSON.parse(localStorage.getItem('ticketsNavbarPrefs')) || {}; } catch (e) { navPrefs = {}; }
        var currentFade = (navPrefs.fadeDelay !== undefined) ? String(navPrefs.fadeDelay) : '10000';
        fadeSelect.value = currentFade;
        // If the value didn't match any option, fall back to default
        if (fadeSelect.value !== currentFade) fadeSelect.value = '10000';

        // Read current map prefs
        var mapPrefs = {};
        try { mapPrefs = JSON.parse(localStorage.getItem('ticketsMapPrefs')) || {}; } catch (e) { mapPrefs = {}; }
        if (basemapLightSelect) basemapLightSelect.value = mapPrefs.basemapLight || 'street';
        if (basemapDarkSelect) basemapDarkSelect.value = mapPrefs.basemapDark || 'dark';

        // Save handler
        saveBtn.addEventListener('click', function () {
            // Save navbar fade delay
            var fadeMs = parseInt(fadeSelect.value, 10);
            if (window.NavbarPrefs) {
                window.NavbarPrefs.setFadeDelay(fadeMs);
            } else {
                navPrefs.fadeDelay = fadeMs;
                try { localStorage.setItem('ticketsNavbarPrefs', JSON.stringify(navPrefs)); } catch (e) { /* ignore */ }
            }

            // Save basemap preferences
            if (window.MapPrefs) {
                if (basemapLightSelect) window.MapPrefs.setBasemap('light', basemapLightSelect.value);
                if (basemapDarkSelect) window.MapPrefs.setBasemap('dark', basemapDarkSelect.value);
            } else {
                var mp = {};
                try { mp = JSON.parse(localStorage.getItem('ticketsMapPrefs')) || {}; } catch (e) { mp = {}; }
                if (basemapLightSelect) mp.basemapLight = basemapLightSelect.value;
                if (basemapDarkSelect) mp.basemapDark = basemapDarkSelect.value;
                try { localStorage.setItem('ticketsMapPrefs', JSON.stringify(mp)); } catch (e) { /* ignore */ }
            }

            showAlert('Display preferences saved (stored in this browser)');
        });
    }

    // ═══════════════════════════════════════════════════════════════
    //  FACILITY TYPES
    // ═══════════════════════════════════════════════════════════════
    function bindFacilityTypesPanel() {
        var form = document.getElementById('facTypeForm');
        var panel = document.getElementById('facTypeEditPanel');

        if (!form) return;

        document.getElementById('btnAddFacType').addEventListener('click', function () {
            openFacTypeForm(null);
        });
        document.getElementById('btnCancelFacType').addEventListener('click', function () {
            panel.classList.remove('show');
        });
        document.getElementById('btnDeleteFacType').addEventListener('click', function () {
            var id = document.getElementById('facTypeId').value;
            if (!id || !confirm('Delete this facility type?')) return;
            apiDelete('facility_types', id).then(function () {
                panel.classList.remove('show');
                showAlert('Facility type deleted');
                loadedPanels['facility-types'] = false;
                loadFacilityTypes();
            }).catch(function (err) { showAlert(err.message, 'danger'); });
        });

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            apiPost('facility_types', formToObject(form)).then(function () {
                panel.classList.remove('show');
                showAlert('Facility type saved');
                loadedPanels['facility-types'] = false;
                loadFacilityTypes();
            }).catch(function (err) { showAlert(err.message, 'danger'); });
        });
    }

    function loadFacilityTypes() {
        apiGet('facility_types').then(function (data) {
            cache.facTypes = data.rows || [];
            renderFacilityTypes();
        }).catch(function (err) {
            var el = document.getElementById('facTypesStatus');
            if (el) el.textContent = 'Error: ' + err.message;
        });
    }

    function renderFacilityTypes() {
        var tbody = document.getElementById('facTypesTableBody');
        if (!tbody) return;
        var html = '';
        for (var i = 0; i < cache.facTypes.length; i++) {
            var ft = cache.facTypes[i];
            // GH #62 — show the actual glyph, not a bare number.
            var ftIcon = window.TypeIcons
                ? '<i class="bi ' + window.TypeIcons.classFor(ft.icon) + '"></i> <span class="text-body-secondary small">' + (parseInt(ft.icon, 10) || 0) + '</span>'
                : String(ft.icon || 0);
            html += '<tr data-id="' + ft.id + '">' +
                '<td>' + ft.id + '</td>' +
                '<td><strong>' + esc(ft.name) + '</strong></td>' +
                '<td>' + esc(ft.description || '') + '</td>' +
                '<td>' + ftIcon + '</td>' +
                '</tr>';
        }
        tbody.innerHTML = html || '<tr><td colspan="4" class="text-center text-body-secondary py-3">No facility types found</td></tr>';
        document.getElementById('facTypesStatus').textContent = cache.facTypes.length + ' facility type' + (cache.facTypes.length !== 1 ? 's' : '') + ' loaded';

        var rows = tbody.querySelectorAll('tr[data-id]');
        for (var j = 0; j < rows.length; j++) {
            rows[j].addEventListener('click', function () {
                var item = findById(cache.facTypes, parseInt(this.getAttribute('data-id'), 10));
                if (item) openFacTypeForm(item);
            });
        }
    }

    function openFacTypeForm(item) {
        var panel = document.getElementById('facTypeEditPanel');
        document.getElementById('facTypeId').value = item ? item.id : '';
        document.getElementById('facTypeName').value = item ? item.name : '';
        document.getElementById('facTypeDesc').value = item ? (item.description || '') : '';
        document.getElementById('facTypeIcon').value = item ? (item.icon || 0) : 0;
        // GH #62 — the icon field is now a picker; refresh its glyph preview.
        document.getElementById('facTypeIcon').dispatchEvent(new Event('change', { bubbles: true }));
        document.getElementById('btnDeleteFacType').classList.toggle('d-none', !item);
        panel.classList.add('show');
        document.getElementById('facTypeName').focus();
    }

    // ═══════════════════════════════════════════════════════════════
    //  UNIT TYPES (GH #61 — mirrors facility types)
    // ═══════════════════════════════════════════════════════════════
    function bindUnitTypesPanel() {
        var form = document.getElementById('unitTypeForm');
        var panel = document.getElementById('unitTypeEditPanel');
        if (!form) return;

        document.getElementById('btnAddUnitType').addEventListener('click', function () {
            openUnitTypeForm(null);
        });
        document.getElementById('btnCancelUnitType').addEventListener('click', function () {
            panel.classList.remove('show');
        });
        document.getElementById('btnDeleteUnitType').addEventListener('click', function () {
            var id = document.getElementById('unitTypeId').value;
            if (!id || !confirm('Delete this unit type?')) return;
            apiDelete('unit_types', id).then(function () {
                panel.classList.remove('show');
                showAlert('Unit type deleted');
                loadedPanels['unit-types'] = false;
                loadUnitTypes();
            }).catch(function (err) { showAlert(err.message, 'danger'); });
        });

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            apiPost('unit_types', formToObject(form)).then(function () {
                panel.classList.remove('show');
                showAlert('Unit type saved');
                loadedPanels['unit-types'] = false;
                loadUnitTypes();
            }).catch(function (err) { showAlert(err.message, 'danger'); });
        });
    }

    function loadUnitTypes() {
        apiGet('unit_types').then(function (data) {
            cache.unitTypes = data.rows || [];
            renderUnitTypes();
        }).catch(function (err) {
            var el = document.getElementById('unitTypesStatus');
            if (el) el.textContent = 'Error: ' + err.message;
        });
    }

    function renderUnitTypes() {
        var tbody = document.getElementById('unitTypesTableBody');
        if (!tbody) return;
        var html = '';
        for (var i = 0; i < cache.unitTypes.length; i++) {
            var ut = cache.unitTypes[i];
            // GH #62 — show the actual glyph, not a bare number.
            var utIcon = window.TypeIcons
                ? '<i class="bi ' + window.TypeIcons.classFor(ut.icon) + '"></i> <span class="text-body-secondary small">' + (parseInt(ut.icon, 10) || 0) + '</span>'
                : String(ut.icon || 0);
            html += '<tr data-id="' + ut.id + '">' +
                '<td>' + ut.id + '</td>' +
                '<td><strong>' + esc(ut.name) + '</strong></td>' +
                '<td>' + esc(ut.description || '') + '</td>' +
                '<td>' + utIcon + '</td>' +
                '</tr>';
        }
        tbody.innerHTML = html || '<tr><td colspan="4" class="text-center text-body-secondary py-3">No unit types found</td></tr>';
        document.getElementById('unitTypesStatus').textContent = cache.unitTypes.length + ' unit type' + (cache.unitTypes.length !== 1 ? 's' : '') + ' loaded';

        var rows = tbody.querySelectorAll('tr[data-id]');
        for (var j = 0; j < rows.length; j++) {
            rows[j].addEventListener('click', function () {
                var item = findById(cache.unitTypes, parseInt(this.getAttribute('data-id'), 10));
                if (item) openUnitTypeForm(item);
            });
        }
    }

    function openUnitTypeForm(item) {
        var panel = document.getElementById('unitTypeEditPanel');
        document.getElementById('unitTypeId').value = item ? item.id : '';
        document.getElementById('unitTypeName').value = item ? item.name : '';
        document.getElementById('unitTypeDesc').value = item ? (item.description || '') : '';
        document.getElementById('unitTypeIcon').value = item ? (item.icon || 0) : 0;
        // GH #62 — the icon field is now a picker; refresh its glyph preview.
        document.getElementById('unitTypeIcon').dispatchEvent(new Event('change', { bubbles: true }));
        document.getElementById('btnDeleteUnitType').classList.toggle('d-none', !item);
        panel.classList.add('show');
        document.getElementById('unitTypeName').focus();
    }

    // ═══════════════════════════════════════════════════════════════
    //  SOUND / ALERTS (uses settings API)
    // ═══════════════════════════════════════════════════════════════
    function bindSoundAlertsPanel() {
        var form = document.getElementById('soundAlertsForm');
        if (!form) return;

        // Volume slider labels
        var newVolSlider = document.getElementById('setSoundNewVolume');
        var newVolLabel = document.getElementById('soundNewVolumeLabel');
        var highVolSlider = document.getElementById('setSoundHighVolume');
        var highVolLabel = document.getElementById('soundHighVolumeLabel');

        if (newVolSlider && newVolLabel) {
            newVolSlider.addEventListener('input', function () {
                newVolLabel.textContent = newVolSlider.value + '%';
            });
        }
        if (highVolSlider && highVolLabel) {
            highVolSlider.addEventListener('input', function () {
                highVolLabel.textContent = highVolSlider.value + '%';
            });
        }

        // Test sound button
        var testBtn = document.getElementById('btnTestSound');
        if (testBtn) {
            testBtn.addEventListener('click', function () {
                // Play a simple beep using Web Audio API
                try {
                    var ctx = new (window.AudioContext || window.webkitAudioContext)();
                    var osc = ctx.createOscillator();
                    var gain = ctx.createGain();
                    osc.connect(gain);
                    gain.connect(ctx.destination);
                    osc.frequency.value = 880;
                    osc.type = 'sine';
                    var vol = parseInt(newVolSlider ? newVolSlider.value : 70, 10) / 100;
                    gain.gain.value = vol * 0.5;
                    osc.start();
                    osc.stop(ctx.currentTime + 0.3);
                    setTimeout(function () {
                        var osc2 = ctx.createOscillator();
                        var gain2 = ctx.createGain();
                        osc2.connect(gain2);
                        gain2.connect(ctx.destination);
                        osc2.frequency.value = 1100;
                        osc2.type = 'sine';
                        gain2.gain.value = vol * 0.5;
                        osc2.start();
                        osc2.stop(ctx.currentTime + 0.3);
                    }, 350);
                } catch (ex) {
                    showAlert('Could not play test sound: ' + ex.message, 'warning');
                }
            });
        }

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var pairs = collectSettingsFromForm(form);
            // Handle checkboxes explicitly
            var checkboxes = form.querySelectorAll('input[type="checkbox"][data-key]');
            for (var i = 0; i < checkboxes.length; i++) {
                var key = checkboxes[i].getAttribute('data-key');
                pairs[key] = checkboxes[i].checked ? '1' : '0';
            }
            apiPost('settings', { settings: pairs }).then(function (data) {
                showAlert('Sound settings saved (' + data.saved + ' updated)');
            }).catch(function (err) {
                showAlert(err.message, 'danger');
            });
        });
    }

    function loadSoundAlerts() {
        apiGet('settings').then(function (data) {
            var settings = data.settings || {};
            var form = document.getElementById('soundAlertsForm');
            applySettingsToForm(form, settings);

            // Sync volume labels
            var newVol = document.getElementById('setSoundNewVolume');
            var newLabel = document.getElementById('soundNewVolumeLabel');
            if (newVol && newLabel) newLabel.textContent = (newVol.value || 70) + '%';

            var highVol = document.getElementById('setSoundHighVolume');
            var highLabel = document.getElementById('soundHighVolumeLabel');
            if (highVol && highLabel) highLabel.textContent = (highVol.value || 90) + '%';

            // Sync checkboxes
            var checkboxes = form.querySelectorAll('input[type="checkbox"][data-key]');
            for (var i = 0; i < checkboxes.length; i++) {
                var key = checkboxes[i].getAttribute('data-key');
                checkboxes[i].checked = (settings[key] === '1');
            }
        }).catch(function (err) {
            showAlert('Failed to load sound settings: ' + err.message, 'danger');
        });
    }

    // ═══════════════════════════════════════════════════════════════
    //  WEB PUSH NOTIFICATIONS (Phase 96 admin, 2026-06-28)
    // ═══════════════════════════════════════════════════════════════
    function bindPushAdminPanel() {
        var form = document.getElementById('pushAdminForm');
        if (!form) return;

        var btnRegen = document.getElementById('btnRegenerateVapid');
        if (btnRegen) {
            btnRegen.addEventListener('click', function () {
                if (!confirm(
                    'Generate a brand-new VAPID keypair?\n\n' +
                    'Existing browser subscriptions remain valid (the keys ' +
                    'rotate independently of subscription IDs), but any future ' +
                    'browser re-subscriptions will be against the new public ' +
                    'key. Only do this if the old private key was leaked or you ' +
                    'are doing a clean reset.'
                )) return;

                btnRegen.disabled = true;
                btnRegen.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Generating...';
                fetch('api/push-admin.php?action=regenerate', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': getCsrf()
                    },
                    body: JSON.stringify({})
                })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.error) {
                        showAlert('Keypair generation failed: ' + data.error, 'danger');
                    } else {
                        showAlert('New VAPID keypair generated and saved.', 'success');
                        loadPushAdminConfig();
                    }
                })
                .catch(function (err) {
                    showAlert('Network error: ' + err.message, 'danger');
                })
                .then(function () {
                    btnRegen.disabled = false;
                    btnRegen.innerHTML = '<i class="bi bi-arrow-clockwise me-1"></i>Generate New Keypair';
                });
            });
        }

        var btnTest = document.getElementById('btnTestPush');
        if (btnTest) {
            btnTest.addEventListener('click', function () {
                btnTest.disabled = true;
                btnTest.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Sending...';
                fetch('api/push-admin.php?action=test', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': getCsrf()
                    },
                    body: JSON.stringify({})
                })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.error) {
                        showAlert('Test push failed: ' + data.error, 'danger');
                    } else if (data.sent > 0) {
                        showAlert('Sent test push to ' + data.sent + ' of ' + data.total +
                            ' subscriptions. Check your browser notifications.', 'success');
                    } else {
                        var errMsg = 'Test push: 0 sent, ' + (data.failed || 0) + ' failed.';
                        if (data.errors && data.errors.length) {
                            errMsg += ' Errors: ' + data.errors.slice(0, 3).join('; ');
                        }
                        showAlert(errMsg, 'warning');
                    }
                })
                .catch(function (err) {
                    showAlert('Network error: ' + err.message, 'danger');
                })
                .then(function () {
                    btnTest.disabled = false;
                    btnTest.innerHTML = '<i class="bi bi-bell me-1"></i>Send Test Push to My Devices';
                });
            });
        }

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var enabled = document.getElementById('pushEnabled').checked ? '1' : '0';
            var subject = document.getElementById('pushVapidSubject').value.trim();
            fetch('api/push-admin.php?action=save', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': getCsrf()
                },
                body: JSON.stringify({
                    push_enabled: enabled,
                    push_vapid_subject: subject
                })
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.error) {
                    showAlert('Save failed: ' + data.error, 'danger');
                } else {
                    showAlert('Push notification settings saved.', 'success');
                }
            })
            .catch(function (err) {
                showAlert('Network error: ' + err.message, 'danger');
            });
        });
    }

    function loadPushAdminConfig() {
        fetchJSON('api/push-admin.php').then(function (data) {
            if (data.error) return;
            var pk = document.getElementById('pushVapidPublicKey');
            var priv = document.getElementById('pushVapidPrivateState');
            var sub = document.getElementById('pushVapidSubject');
            var en = document.getElementById('pushEnabled');
            var n = document.getElementById('pushSubCount');
            var nu = document.getElementById('pushSubUserCount');
            var stat = document.getElementById('pushKeyStatus');
            if (pk)  pk.value = data.push_vapid_public_key || '';
            if (priv) priv.value = data.push_vapid_private_set ? '(set, hidden)' : '(not set)';
            if (sub) sub.value = data.push_vapid_subject || '';
            if (en)  en.checked = (data.push_enabled === '1');
            if (n)   n.textContent  = data.subscriptions || 0;
            if (nu)  nu.textContent = data.subscribed_users || 0;
            if (stat) {
                stat.textContent = data.keys_configured
                    ? 'Keypair configured.'
                    : 'No keypair yet — click "Generate New Keypair" to enable push.';
                stat.className = 'small align-self-center ' +
                    (data.keys_configured ? 'text-success' : 'text-warning');
            }
        }).catch(function () { /* admin-only endpoint; non-admins silently no-op */ });
    }

    // ═══════════════════════════════════════════════════════════════
    //  ZELLO NETWORK RADIO
    // ═══════════════════════════════════════════════════════════════
    function bindZelloPanel() {
        var form = document.getElementById('zelloConfigForm');
        if (!form) return;

        // Dismiss wizard button
        var dismissBtn = document.getElementById('btnDismissWizard');
        if (dismissBtn) {
            dismissBtn.addEventListener('click', function () {
                var wiz = document.getElementById('zelloSetupWizard');
                if (wiz) wiz.style.display = 'none';
                try { sessionStorage.setItem('zelloWizardDismissed', '1'); } catch (e) {}
            });
        }

        // Phase 98 (2026-06-28) — toggle Work-vs-Consumer UI sections.
        // Work mode: hide JWT credentials, show info banner, mark
        // Network Name required. Consumer mode: opposite.
        var serviceSel = document.getElementById('zelloService');
        if (serviceSel) {
            serviceSel.addEventListener('change', applyZelloServiceMode);
        }

        // Live-update wizard checkmarks as user types
        var wizardFields = ['zelloIssuer', 'zelloPrivateKey', 'zelloUsername', 'zelloPassword', 'zelloDispatchChannel'];
        for (var i = 0; i < wizardFields.length; i++) {
            var el = document.getElementById(wizardFields[i]);
            if (el) {
                el.addEventListener('input', updateWizardSteps);
            }
        }

        // Test connection button
        var testBtn = document.getElementById('btnTestZello');
        if (testBtn) {
            testBtn.addEventListener('click', function () {
                testBtn.disabled = true;
                testBtn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Testing...';
                fetchJSON('api/zello-token.php').then(function (data) {
                    if (data.error) {
                        showAlert('Token test failed: ' + data.error, 'danger');
                    } else {
                        showAlert('Auth token generated OK for "' + (data.user || '?') + '". Start the proxy to connect to Zello.', 'success');
                    }
                }).catch(function (err) {
                    showAlert('Test failed: ' + err.message, 'danger');
                }).then(function () {
                    testBtn.disabled = false;
                    testBtn.innerHTML = '<i class="bi bi-broadcast me-1"></i>Test Connection';
                });
            });
        }

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var pairs = collectSettingsFromForm(form);
            // Handle checkboxes explicitly
            var checkboxes = form.querySelectorAll('input[type="checkbox"][data-key]');
            for (var j = 0; j < checkboxes.length; j++) {
                var key = checkboxes[j].getAttribute('data-key');
                pairs[key] = checkboxes[j].checked ? '1' : '0';
            }
            apiPost('settings', { settings: pairs }).then(function (data) {
                showAlert('Zello settings saved.');
                updateWizardSteps();
            }).catch(function (err) {
                showAlert(err.message, 'danger');
            });
        });
    }

    /**
     * Update wizard step checkmarks based on current form values.
     * Shows the wizard if credentials are missing (unless user dismissed it).
     */
    function updateWizardSteps() {
        var wizard = document.getElementById('zelloSetupWizard');
        if (!wizard) return;

        var issuer  = (document.getElementById('zelloIssuer') || {}).value || '';
        var privKey = (document.getElementById('zelloPrivateKey') || {}).value || '';
        var user    = (document.getElementById('zelloUsername') || {}).value || '';
        var pass    = (document.getElementById('zelloPassword') || {}).value || '';
        var channel = (document.getElementById('zelloDispatchChannel') || {}).value || '';

        var hasJwt     = issuer.length > 0 && privKey.length > 0;
        var hasAccount = user.length > 0 && pass.length > 0 && channel.length > 0;
        var allGood    = hasJwt && hasAccount;

        // Step 2: credentials
        var step2 = wizard.querySelector('[data-step="2"]');
        if (step2) {
            var chk2 = step2.querySelector('.wizard-check');
            var mis2 = step2.querySelector('.wizard-missing');
            if (hasJwt) {
                if (chk2) chk2.style.display = '';
                if (mis2) mis2.style.display = 'none';
                step2.querySelector('.wizard-step-num').className = 'badge rounded-pill text-bg-success wizard-step-num';
            } else {
                if (chk2) chk2.style.display = 'none';
                if (mis2) {
                    mis2.style.display = '';
                    var parts = [];
                    if (!issuer) parts.push('Issuer');
                    if (!privKey) parts.push('Private Key');
                    var misText = mis2.querySelector('.wizard-missing-text');
                    if (misText) misText.textContent = 'Missing: ' + parts.join(', ');
                }
                step2.querySelector('.wizard-step-num').className = 'badge rounded-pill text-bg-warning wizard-step-num';
            }
        }

        // Step 3: account + channel
        var step3 = wizard.querySelector('[data-step="3"]');
        if (step3) {
            var chk3 = step3.querySelector('.wizard-check');
            var mis3 = step3.querySelector('.wizard-missing');
            if (hasAccount) {
                if (chk3) chk3.style.display = '';
                if (mis3) mis3.style.display = 'none';
                step3.querySelector('.wizard-step-num').className = 'badge rounded-pill text-bg-success wizard-step-num';
            } else {
                if (chk3) chk3.style.display = 'none';
                if (mis3) {
                    mis3.style.display = '';
                    var parts3 = [];
                    if (!user) parts3.push('Username');
                    if (!pass) parts3.push('Password');
                    if (!channel) parts3.push('Channel');
                    var misText3 = mis3.querySelector('.wizard-missing-text');
                    if (misText3) misText3.textContent = 'Missing: ' + parts3.join(', ');
                }
                step3.querySelector('.wizard-step-num').className = 'badge rounded-pill text-bg-warning wizard-step-num';
            }
        }

        // Show/hide wizard
        var dismissed = false;
        try { dismissed = sessionStorage.getItem('zelloWizardDismissed') === '1'; } catch (e) {}
        if (allGood) {
            wizard.style.display = 'none';
        } else if (!dismissed) {
            wizard.style.display = '';
        }
    }

    function loadZelloSettings() {
        apiGet('settings').then(function (data) {
            var settings = data.settings || {};
            var form = document.getElementById('zelloConfigForm');
            if (!form) return;
            applySettingsToForm(form, settings);

            // Sync checkboxes
            var checkboxes = form.querySelectorAll('input[type="checkbox"][data-key]');
            for (var i = 0; i < checkboxes.length; i++) {
                var key = checkboxes[i].getAttribute('data-key');
                checkboxes[i].checked = (settings[key] === '1');
            }

            // Phase 98 — apply Work/Consumer UI state after loading.
            applyZelloServiceMode();

            // Update wizard after settings load
            updateWizardSteps();
        }).catch(function (err) {
            showAlert('Failed to load Zello settings: ' + err.message, 'danger');
        });
    }

    /**
     * Phase 98 (2026-06-28) — show/hide Work-vs-Consumer sections of
     * the Zello settings panel based on the Service Type selector.
     *
     *   service=work     → show #zelloWorkAuthInfo, hide API Credentials,
     *                      mark Network Name required, update WS URL hint
     *   service=consumer → hide #zelloWorkAuthInfo, show API Credentials,
     *                      Network Name optional
     *   service=''       → same as consumer (the safer default for the
     *                      pre-configured "disabled" state)
     */
    function applyZelloServiceMode() {
        var sel = document.getElementById('zelloService');
        if (!sel) return;
        var service = (sel.value || '').toLowerCase();
        var isWork = (service === 'work');
        var isConsumer = (service === 'consumer');
        var isDisabled = !isWork && !isConsumer;

        // Eric beta 2026-06-30 — expanded the service-mode form change.
        // Each wrapper has a d-none toggle so the form shows only the
        // fields that apply to the chosen mode.
        function toggle(id, show) {
            var el = document.getElementById(id);
            if (!el) return;
            if (show) el.classList.remove('d-none');
            else      el.classList.add('d-none');
        }

        var workInfo = document.getElementById('zelloWorkAuthInfo');
        var apiCreds = document.getElementById('zelloApiCredentialsSection');
        var network  = document.getElementById('zelloNetwork');
        var wsUrl    = document.getElementById('zelloWsUrl');
        var wsHelp   = document.getElementById('zelloWsUrlHelp');
        var badge    = document.getElementById('zelloServiceModeBadge');
        var notice   = document.getElementById('zelloServiceDisabledNotice');

        // 1. Mode badge — at-a-glance current state.
        if (badge) {
            if (isWork) {
                badge.textContent = 'Zello Work mode';
                badge.className = 'badge bg-primary ms-2';
            } else if (isConsumer) {
                badge.textContent = 'Zello Consumer mode';
                badge.className = 'badge bg-info text-dark ms-2';
            } else {
                badge.textContent = 'Disabled';
                badge.className = 'badge bg-secondary ms-2';
            }
        }

        // 2. Disabled notice — only when nothing is selected.
        toggle('zelloServiceDisabledNotice', isDisabled);

        // 3. Field visibility per mode.
        //    Work     → Network Name, Username, Password, Work info banner
        //               (hide: Auth Token, Issuer/PrivKey, Connection Mode)
        //    Consumer → Username, Password, Auth Token (optional), Connection Mode,
        //               API Credentials (Issuer + Private Key)
        //               (hide: Network Name, Work banner)
        //    Disabled → hide everything auth-related; keep only Service Type +
        //               Proxy Port + Retention visible so admin can re-enable
        toggle('zelloNetworkWrap',    isWork);
        toggle('zelloAuthTokenWrap',  isConsumer);
        toggle('zelloProxyModeWrap',  isConsumer);
        if (workInfo) workInfo.style.display = isWork ? '' : 'none';
        if (apiCreds) apiCreds.style.display = isConsumer ? '' : 'none';

        // 4. Network Name required only in Work mode.
        if (network) {
            if (isWork) {
                network.setAttribute('required', 'required');
                network.classList.add('border-warning');
            } else {
                network.removeAttribute('required');
                network.classList.remove('border-warning');
            }
        }

        // 5. WS URL placeholder + help — adapt to the mode.
        if (wsUrl) {
            if (isWork) {
                wsUrl.placeholder = '(auto: wss://zellowork.io/ws/<your-network>)';
            } else if (isConsumer) {
                wsUrl.placeholder = 'wss://zello.io/ws';
            } else {
                wsUrl.placeholder = '(disabled)';
            }
        }
        if (wsHelp) {
            if (isWork) {
                wsHelp.innerHTML = 'Leave blank — auto-computed from Network Name. Only override for non-standard Zello Work deployments.';
            } else if (isConsumer) {
                wsHelp.innerHTML = 'Standard Zello Consumer URL: <code>wss://zello.io/ws</code>. Leave blank to use that default.';
            } else {
                wsHelp.innerHTML = '';
            }
        }
    }

    // Eric beta 2026-06-30 — copy-to-clipboard for the Troubleshooting
    // commands on the Zello panel. Bound once; finds buttons by class
    // so additional commands can be added in settings.php without
    // touching this code.
    (function bindZelloTroubleshootCopy() {
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('.ts-copy-btn');
            if (!btn) return;
            var box = btn.parentElement;
            var code = box && box.querySelector('.ts-copy-target');
            if (!code) return;
            var text = code.textContent.replace(/\s+/g, ' ').trim();
            // navigator.clipboard isn't available on http:// (browser security);
            // fall back to a hidden textarea + execCommand. Both code paths
            // briefly flash a check on the button so admin sees the copy worked.
            function flashOk() {
                var orig = btn.innerHTML;
                btn.innerHTML = '<i class="bi bi-check-lg text-success"></i>';
                setTimeout(function () { btn.innerHTML = orig; }, 1200);
            }
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(flashOk).catch(function () {
                    fallbackCopy(text); flashOk();
                });
            } else {
                fallbackCopy(text); flashOk();
            }
            function fallbackCopy(t) {
                var ta = document.createElement('textarea');
                ta.value = t;
                ta.style.position = 'fixed';
                ta.style.opacity = '0';
                document.body.appendChild(ta);
                ta.select();
                try { document.execCommand('copy'); } catch (e) { /* best-effort */ }
                document.body.removeChild(ta);
            }
        });
    })();

    // ═══════════════════════════════════════════════════════════════
    //  FACILITIES
    // ═══════════════════════════════════════════════════════════════
    function bindFacilitiesPanel() {
        // Issue #44 — the settings-panel inline facility form was
        // removed and the panel now routes to facility-edit.php for
        // Add/Edit. Guard every getElementById so this function is
        // safe to run against the new leaner DOM (missing IDs is
        // expected). The New/Edit hyperlinks in the new markup
        // handle navigation via plain <a target="_blank">.
        var form   = document.getElementById('facilityForm');
        var panel  = document.getElementById('facilityEditPanel');
        var search = document.getElementById('facilitySearch');
        if (search) {
            search.addEventListener('input', function () { renderFacilities(); });
        }
        // Legacy inline-form path — only wire if the elements still
        // exist (they don't in the current markup, but a stale
        // cached settings.php might still have them).
        if (form && panel) {
            var btnAdd = document.getElementById('btnAddFacility');
            var btnCancel = document.getElementById('btnCancelFacility');
            var btnDel = document.getElementById('btnDeleteFacility');
            if (btnAdd && btnAdd.tagName !== 'A') {
                btnAdd.addEventListener('click', function () { openFacilityForm(null); });
            }
            if (btnCancel) {
                btnCancel.addEventListener('click', function () { panel.classList.remove('show'); });
            }
            if (btnDel) {
                btnDel.addEventListener('click', function () {
                    var id = document.getElementById('facilityId').value;
                    if (!id || !confirm('Delete this facility?')) return;
                    apiDelete('facilities', id).then(function () {
                        panel.classList.remove('show');
                        showAlert('Facility deleted');
                        loadedPanels['facilities'] = false;
                        loadFacilities();
                    }).catch(function (err) { showAlert(err.message, 'danger'); });
                });
            }
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                apiPost('facilities', formToObject(form)).then(function () {
                    panel.classList.remove('show');
                    showAlert('Facility saved');
                    loadedPanels['facilities'] = false;
                    loadFacilities();
                }).catch(function (err) { showAlert(err.message, 'danger'); });
            });
        }
    }

    function loadFacilities() {
        apiGet('facilities').then(function (data) {
            cache.facilities = data.rows || [];
            renderFacilities();
        }).catch(function (err) {
            document.getElementById('facilitiesStatus').textContent = 'Error: ' + err.message;
        });
    }

    function renderFacilities() {
        var tbody = document.getElementById('facilitiesTableBody');
        var search = (document.getElementById('facilitySearch').value || '').toLowerCase();
        var html = '';
        var count = 0;

        for (var i = 0; i < cache.facilities.length; i++) {
            var f = cache.facilities[i];
            var text = ((f.name || '') + ' ' + (f.description || '')).toLowerCase();
            if (search && text.indexOf(search) === -1) continue;
            count++;
            // Issue #44: edit action opens the full facility-edit
            // page in a new tab (all ~20 fields available) rather
            // than a stripped-down inline modal. Matches the
            // Members/Personnel pattern in this panel set.
            html += '<tr>' +
                '<td>' + parseInt(f.id, 10) + '</td>' +
                '<td>' + esc(f.name) + '</td>' +
                '<td>' + esc(f.type_name || f.type || '') + '</td>' +
                '<td>' + esc(f.description || '') + '</td>' +
                '<td class="text-center">' + (parseInt(f.hide, 10) ? '<i class="bi bi-eye-slash text-warning"></i>' : '') + '</td>' +
                '<td class="text-center">' +
                    '<a class="btn btn-sm btn-link p-0" href="facility-edit.php?id=' + parseInt(f.id, 10) + '" target="_blank" title="Open full editor">' +
                        '<i class="bi bi-pencil text-primary"></i>' +
                    '</a>' +
                '</td>' +
                '</tr>';
        }

        tbody.innerHTML = html || '<tr><td colspan="6" class="text-center text-body-secondary py-3">No facilities found</td></tr>';
        document.getElementById('facilitiesStatus').textContent = count + ' facilit' + (count !== 1 ? 'ies' : 'y') + ' loaded';
    }

    // Wire search box (bound once per page load — safe re-entry via
    // the _bound flag). Reactive on every keystroke so operators can
    // filter a long list quickly.
    var _facilitySearchBound = false;
    function _bindFacilitySearch() {
        if (_facilitySearchBound) return;
        var el = document.getElementById('facilitySearch');
        if (!el) return;
        _facilitySearchBound = true;
        el.addEventListener('input', renderFacilities);
    }
    // Chain into loadFacilities so the search field wires up on tab
    // activation. Idempotent.
    var _origLoadFacilities = loadFacilities;
    loadFacilities = function () {
        _bindFacilitySearch();
        return _origLoadFacilities();
    };

    // ═══════════════════════════════════════════════════════════════
    //  SYSTEM SETTINGS
    // ═══════════════════════════════════════════════════════════════
    function bindSettingsPanel() {
        var form = document.getElementById('settingsForm');

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var pairs = collectSettingsFromForm(form);
            apiPost('settings', { settings: pairs }).then(function (data) {
                showAlert('Settings saved (' + data.saved + ' updated)');
            }).catch(function (err) {
                showAlert(err.message, 'danger');
            });
        });

        document.getElementById('btnReloadSettings').addEventListener('click', function () {
            loadedPanels['system-settings'] = false;
            loadSettings();
        });

        // Sync color pickers with text inputs
        var colorInputs = form.querySelectorAll('input[type="color"]');
        for (var i = 0; i < colorInputs.length; i++) {
            colorInputs[i].addEventListener('input', (function (picker) {
                return function () {
                    var key = picker.getAttribute('data-key');
                    var textInput = form.querySelector('input[type="text"][data-key="' + key + '"]');
                    if (textInput) textInput.value = picker.value;
                };
            })(colorInputs[i]));
        }
    }

    /** Collect data-key inputs from a form, skipping duplicate color pickers */
    function collectSettingsFromForm(form) {
        var inputs = form.querySelectorAll('[data-key]');
        var pairs = {};
        for (var i = 0; i < inputs.length; i++) {
            var key = inputs[i].getAttribute('data-key');
            if (inputs[i].type === 'color') continue; // text input has same key
            // BUG 1 (a beta tester, 2026-06-25): never overwrite a stored
            // secret with an empty string. A data-secret field left blank
            // means "keep the stored value" — omit the key entirely so the
            // upsert never runs for it. Typing a new value still saves.
            if (inputs[i].getAttribute('data-secret') === '1' &&
                (inputs[i].value || '') === '') {
                continue;
            }
            pairs[key] = inputs[i].value;
        }
        return pairs;
    }

    function loadSettings() {
        apiGet('settings').then(function (data) {
            var settings = data.settings || {};
            applySettingsToForm(document.getElementById('settingsForm'), settings);
        }).catch(function (err) {
            showAlert('Failed to load settings: ' + err.message, 'danger');
        });
    }

    /** Apply a settings map to all data-key inputs in a form, syncing color pickers */
    function applySettingsToForm(form, settings) {
        var inputs = form.querySelectorAll('[data-key]');
        for (var i = 0; i < inputs.length; i++) {
            var key = inputs[i].getAttribute('data-key');
            if (settings[key] !== undefined) {
                inputs[i].value = settings[key];
            }
        }
    }

    // ═══════════════════════════════════════════════════════════════
    //  EXTERNAL API TOKENS (Phase 94 Stage 6)
    //  Admin UI for minting / listing / revoking bearer tokens for
    //  the /api/external/v1/* surface. Hits api/external-api-tokens.php.
    //  Raw token shown ONCE in a modal on mint; never retrievable.
    // ═══════════════════════════════════════════════════════════════
    var EXT_API_SCOPES = [
        // group label → list of [code, description]
        {label: 'Incidents',      codes: [['incidents:read', 'List + read'], ['incidents:write', 'Create/update/delete + actions + assignments + attachments']]},
        {label: 'Members',        codes: [['members:read', 'List + read'],   ['members:write', 'Create/update/delete + status']]},
        {label: 'Responders',     codes: [['responders:read', 'List + read'], ['responders:write', 'Create/update/delete + status']]},
        {label: 'Facilities',     codes: [['facilities:read', 'List + read'], ['facilities:write', 'Create/update/delete']]},
        {label: 'Teams',          codes: [['teams:read', 'List + read'],     ['teams:write', 'Create/update/delete']]},
        {label: 'Incident Types', codes: [['incident_types:read', 'List + read'], ['incident_types:write', 'Create/update/delete']]},
        {label: 'Attachments',    codes: [['attachments:read', 'Read'],      ['attachments:write', 'Upload + delete']]},
        {label: 'Wildcards',      codes: [['*:read', 'Read everything'],     ['*', 'Superuser — bounded by user RBAC']]},
    ];

    function bindExternalApiTokensPanel() {
        var form = document.getElementById('extApiTokenMintForm');
        if (!form) return;

        // Wire the Mint button to toggle the form
        var addBtn = document.getElementById('btnAddExtApiToken');
        var cancelBtn = document.getElementById('btnCancelExtApiTokenMint');
        var mintPanel = document.getElementById('extApiTokenMintPanel');
        if (addBtn) {
            addBtn.addEventListener('click', function () {
                resetExtApiTokenForm();
                if (mintPanel) {
                    var bsCollapse = bootstrap.Collapse.getOrCreateInstance(mintPanel, {toggle: false});
                    bsCollapse.show();
                }
                populateExtApiScopesCheckboxes();
                loadExtApiUsers();
            });
        }
        if (cancelBtn) {
            cancelBtn.addEventListener('click', function () {
                if (mintPanel) {
                    var bsCollapse = bootstrap.Collapse.getOrCreateInstance(mintPanel, {toggle: false});
                    bsCollapse.hide();
                }
            });
        }

        // Mint-form submit
        form.addEventListener('submit', function (ev) {
            ev.preventDefault();
            mintExtApiToken();
        });

        // Copy-to-clipboard button on the reveal modal
        var copyBtn = document.getElementById('btnCopyExtApiToken');
        if (copyBtn) {
            copyBtn.addEventListener('click', function () {
                var input = document.getElementById('extApiTokenRawValue');
                if (!input) return;
                input.select();
                input.setSelectionRange(0, input.value.length);
                try {
                    navigator.clipboard.writeText(input.value);
                    copyBtn.innerHTML = '<i class="bi bi-check2"></i> Copied';
                    setTimeout(function () {
                        copyBtn.innerHTML = '<i class="bi bi-clipboard"></i> Copy';
                    }, 1500);
                } catch (e) {
                    document.execCommand('copy');
                }
            });
        }
    }

    function loadExternalApiTokens() {
        var status = document.getElementById('extApiTokensStatus');
        var tbody  = document.getElementById('extApiTokensTableBody');
        if (!tbody) return;
        if (status) status.textContent = 'Loading...';
        tbody.innerHTML = '<tr><td colspan="6" class="text-center text-body-secondary py-3"><div class="spinner-border spinner-border-sm me-1"></div>Loading...</td></tr>';

        fetch('api/external-api-tokens.php', {credentials: 'same-origin'})
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.error) {
                    tbody.innerHTML = '<tr><td colspan="6" class="text-danger small">' + escHtml(data.error) + '</td></tr>';
                    return;
                }
                var tokens = data.tokens || [];
                if (status) status.textContent = tokens.length + ' token(s)';
                if (tokens.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="6" class="text-center text-body-secondary py-3">No tokens yet. Click "Mint New Token" to create one.</td></tr>';
                    return;
                }
                var html = '';
                for (var i = 0; i < tokens.length; i++) {
                    var t = tokens[i];
                    var isRevoked = !!t.revoked_at;
                    var scopes = (t.scopes || []).join(', ') || '—';
                    var lastUsed = t.last_used_at ? escHtml(t.last_used_at) : '<span class="text-body-secondary">never</span>';
                    var lastIp   = t.last_used_ip ? '<br><span class="small text-body-secondary">' + escHtml(t.last_used_ip) + '</span>' : '';
                    var statusBadge = isRevoked
                        ? '<span class="badge bg-danger">Revoked</span>'
                        : (t.expires_at && new Date(t.expires_at) < new Date()
                            ? '<span class="badge bg-secondary">Expired</span>'
                            : '<span class="badge bg-success">Active</span>');
                    var actionsHtml = isRevoked
                        ? '<span class="text-body-secondary small">' + (t.revoked_reason ? escHtml(t.revoked_reason) : '—') + '</span>'
                        : '<button class="btn btn-sm btn-outline-danger py-0 px-2 ext-api-revoke-btn" data-id="' + t.id + '" data-name="' + escHtml(t.name) + '">Revoke</button>';
                    html += '<tr>' +
                        '<td><strong>' + escHtml(t.name) + '</strong>' +
                        '<br><span class="font-monospace small text-body-secondary">' + escHtml(t.token_prefix) + '...</span>' +
                        (t.description ? '<br><span class="small text-body-secondary">' + escHtml(t.description) + '</span>' : '') +
                        '</td>' +
                        '<td>' + escHtml(t.user_name || ('user#' + t.user_id)) + '</td>' +
                        '<td class="small font-monospace">' + escHtml(scopes) + '</td>' +
                        '<td class="small">' + lastUsed + lastIp + '</td>' +
                        '<td class="text-center">' + statusBadge + '</td>' +
                        '<td class="text-center">' + actionsHtml + '</td>' +
                        '</tr>';
                }
                tbody.innerHTML = html;

                // Wire revoke buttons
                var btns = tbody.querySelectorAll('.ext-api-revoke-btn');
                for (var j = 0; j < btns.length; j++) {
                    btns[j].addEventListener('click', function () {
                        var id   = this.getAttribute('data-id');
                        var name = this.getAttribute('data-name');
                        revokeExtApiToken(parseInt(id, 10), name);
                    });
                }
            })
            .catch(function (err) {
                tbody.innerHTML = '<tr><td colspan="6" class="text-danger small">Load failed: ' + escHtml(err.message) + '</td></tr>';
            });
    }

    function populateExtApiScopesCheckboxes() {
        var container = document.getElementById('extApiTokenScopesCheckboxes');
        if (!container) return;
        var html = '';
        for (var i = 0; i < EXT_API_SCOPES.length; i++) {
            var group = EXT_API_SCOPES[i];
            html += '<div class="col-md-6 mb-1"><div class="border rounded p-2"><strong class="small">' + escHtml(group.label) + '</strong>';
            for (var j = 0; j < group.codes.length; j++) {
                var code = group.codes[j][0];
                var desc = group.codes[j][1];
                var id = 'extScope_' + code.replace(/[^a-zA-Z0-9]/g, '_');
                html += '<div class="form-check form-check-sm">' +
                    '<input class="form-check-input ext-api-scope-cb" type="checkbox" id="' + id + '" value="' + escHtml(code) + '">' +
                    '<label class="form-check-label small" for="' + id + '">' +
                    '<code>' + escHtml(code) + '</code> <span class="text-body-secondary">— ' + escHtml(desc) + '</span>' +
                    '</label></div>';
            }
            html += '</div></div>';
        }
        container.innerHTML = html;
    }

    function loadExtApiUsers() {
        var sel = document.getElementById('extApiTokenUserId');
        if (!sel) return;
        sel.innerHTML = '<option value="">— Select a user —</option>';
        // 2026-06-28 — api/config-admin.php?section=users returns
        // {rows: [...], members: [...]} — NOT {users: [...]}. a beta tester
        // beta report flagged this as "dropdown is blank". Accept
        // either shape so we're resilient if the API ever adds a
        // users alias.
        fetch('api/config-admin.php?section=users', {credentials: 'same-origin'})
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data) return;
                var users = data.users || data.rows || [];
                for (var i = 0; i < users.length; i++) {
                    var u = users[i];
                    var opt = document.createElement('option');
                    opt.value = u.id;
                    opt.textContent = u.user + (u.role_name ? ' (' + u.role_name + ')' : '');
                    sel.appendChild(opt);
                }
            })
            .catch(function () { /* user lookup failure — admin can type the user_id manually if needed */ });
    }

    function resetExtApiTokenForm() {
        var fields = ['extApiTokenName', 'extApiTokenDescription', 'extApiTokenRateLimit', 'extApiTokenExpiresAt', 'extApiTokenIpAllowlist'];
        for (var i = 0; i < fields.length; i++) {
            var el = document.getElementById(fields[i]);
            if (el) el.value = '';
        }
        var sel = document.getElementById('extApiTokenUserId');
        if (sel) sel.value = '';
    }

    function mintExtApiToken() {
        var name = (document.getElementById('extApiTokenName').value || '').trim();
        var userId = parseInt(document.getElementById('extApiTokenUserId').value, 10);
        var description = (document.getElementById('extApiTokenDescription').value || '').trim();
        var rateLimit = parseInt(document.getElementById('extApiTokenRateLimit').value, 10);
        var expiresAt = (document.getElementById('extApiTokenExpiresAt').value || '').trim();
        var ipAllowlistRaw = (document.getElementById('extApiTokenIpAllowlist').value || '').trim();
        var ipAllowlist = ipAllowlistRaw ? ipAllowlistRaw.split(',').map(function (s) { return s.trim(); }).filter(function (s) { return s.length; }) : null;

        var scopes = [];
        var cbs = document.querySelectorAll('.ext-api-scope-cb:checked');
        for (var i = 0; i < cbs.length; i++) scopes.push(cbs[i].value);

        if (!name) { alert('Token name required'); return; }
        if (!userId) { alert('Bound user required'); return; }
        if (scopes.length === 0) { alert('At least one scope required'); return; }

        var payload = {
            name: name,
            user_id: userId,
            scopes: scopes,
            csrf_token: (document.getElementById('csrfToken') || {}).value || ''
        };
        if (description) payload.description = description;
        if (rateLimit > 0) payload.rate_limit_per_hour = rateLimit;
        if (expiresAt) payload.expires_at = expiresAt.replace('T', ' ') + ':00';
        if (ipAllowlist) payload.ip_allowlist = ipAllowlist;

        fetch('api/external-api-tokens.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            credentials: 'same-origin',
            body: JSON.stringify(payload)
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.error) {
                alert('Mint failed: ' + data.error);
                return;
            }
            // Show the raw-token modal — this is the ONLY time the raw value
            // is ever surfaced. After dismiss, only the prefix remains.
            var rawInput = document.getElementById('extApiTokenRawValue');
            if (rawInput) rawInput.value = data.raw_token;
            var curlPre = document.getElementById('extApiTokenCurlExample');
            if (curlPre) {
                curlPre.textContent = 'curl -H "Authorization: Bearer ' + data.raw_token + '" \\\n' +
                                      '     ' + window.location.origin + '/api/external/v1/incidents';
            }
            var modalEl = document.getElementById('extApiTokenRevealModal');
            if (modalEl) {
                var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
                modal.show();
                modalEl.addEventListener('hidden.bs.modal', function () {
                    // Clear the raw value from the DOM after the modal closes
                    if (rawInput) rawInput.value = '';
                    if (curlPre) curlPre.textContent = '';
                }, {once: true});
            }
            // Hide the mint panel + refresh the list
            var mintPanel = document.getElementById('extApiTokenMintPanel');
            if (mintPanel) {
                var bsCollapse = bootstrap.Collapse.getOrCreateInstance(mintPanel, {toggle: false});
                bsCollapse.hide();
            }
            resetExtApiTokenForm();
            loadExternalApiTokens();
        })
        .catch(function (err) {
            alert('Mint request failed: ' + err.message);
        });
    }

    function revokeExtApiToken(id, name) {
        var reason = prompt('Revoke token "' + name + '"?\n\nOptional reason (logged in audit):', '');
        if (reason === null) return; // cancel
        fetch('api/external-api-tokens.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            credentials: 'same-origin',
            body: JSON.stringify({
                action: 'revoke',
                id: id,
                reason: reason,
                csrf_token: (document.getElementById('csrfToken') || {}).value || ''
            })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.error) {
                alert('Revoke failed: ' + data.error);
                return;
            }
            loadExternalApiTokens();
        });
    }

    // ═══════════════════════════════════════════════════════════════
    //  CHAT SETTINGS (uses settings API)
    //  a beta tester 2026-06-26: panel was rendered but had no save
    //  wiring at all — toggles reverted on every page reload. Pattern
    //  copied from bindSoundAlertsPanel / bindApiKeysPanel: collect
    //  data-key inputs + checkbox state, POST as { settings: {...} },
    //  load via apiGet('settings') and apply.
    // ═══════════════════════════════════════════════════════════════
    function bindChatSettingsPanel() {
        var form = document.getElementById('chatSettingsForm');
        if (!form) return;

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var pairs = collectSettingsFromForm(form);
            // Unchecked checkboxes don't appear in collectSettingsFromForm's
            // output (their value attribute, not their checked state, is
            // what data-key would read). Add them explicitly as '0'/'1'.
            var checkboxes = form.querySelectorAll('input[type="checkbox"][data-key]');
            for (var i = 0; i < checkboxes.length; i++) {
                var key = checkboxes[i].getAttribute('data-key');
                pairs[key] = checkboxes[i].checked ? '1' : '0';
            }
            apiPost('settings', { settings: pairs }).then(function (data) {
                showAlert('Chat settings saved (' + data.saved + ' updated)');
            }).catch(function (err) {
                showAlert(err.message, 'danger');
            });
        });
    }

    function loadChatSettings() {
        var form = document.getElementById('chatSettingsForm');
        if (!form) return;
        apiGet('settings').then(function (data) {
            var settings = data.settings || {};
            applySettingsToForm(form, settings);
            // Sync checkbox state — applySettingsToForm sets .value not .checked
            var checkboxes = form.querySelectorAll('input[type="checkbox"][data-key]');
            for (var i = 0; i < checkboxes.length; i++) {
                var key = checkboxes[i].getAttribute('data-key');
                if (settings[key] !== undefined) {
                    checkboxes[i].checked = (settings[key] === '1');
                }
            }
        }).catch(function (err) {
            showAlert('Failed to load chat settings: ' + err.message, 'danger');
        });
    }

    // ═══════════════════════════════════════════════════════════════
    //  API KEYS (uses settings API)
    // ═══════════════════════════════════════════════════════════════
    function bindApiKeysPanel() {
        var form = document.getElementById('apiKeysForm');
        if (!form) return;

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var pairs = collectSettingsFromForm(form);
            apiPost('settings', { settings: pairs }).then(function (data) {
                showAlert('API keys saved (' + data.saved + ' updated)');
                updateFeedKeyBanner();
            }).catch(function (err) {
                showAlert(err.message, 'danger');
            });
        });

        // PRE-RELEASE-FIXES #8 — generate-random-feed-key button
        var genBtn = document.getElementById('btnGenerateFeedKey');
        if (genBtn) {
            genBtn.addEventListener('click', function () {
                var input = document.getElementById('setFeedApiKey');
                if (!input) return;
                input.value = randomHex(48);
                updateFeedKeyBanner();
                showAlert('New key generated — click Save API Keys to apply.', 'info');
            });
        }
        var feedInput = document.getElementById('setFeedApiKey');
        if (feedInput) {
            feedInput.addEventListener('input', updateFeedKeyBanner);
        }
    }

    // 48 hex chars = 24 random bytes — uses crypto when available
    function randomHex(len) {
        if (window.crypto && window.crypto.getRandomValues) {
            var bytes = new Uint8Array(Math.ceil(len / 2));
            window.crypto.getRandomValues(bytes);
            var s = '';
            for (var i = 0; i < bytes.length; i++) {
                s += ('0' + bytes[i].toString(16)).slice(-2);
            }
            return s.slice(0, len);
        }
        // Fallback (not cryptographically strong) — only hit on ancient browsers
        var alpha = '0123456789abcdef';
        var s = '';
        for (var i = 0; i < len; i++) {
            s += alpha[Math.floor(Math.random() * 16)];
        }
        return s;
    }

    function updateFeedKeyBanner() {
        var input = document.getElementById('setFeedApiKey');
        var banner = document.getElementById('feedKeyMissingBanner');
        if (!input || !banner) return;
        if (input.value.trim() === '') {
            banner.classList.remove('d-none');
        } else {
            banner.classList.add('d-none');
        }
    }

    function loadApiKeys() {
        apiGet('settings').then(function (data) {
            var settings = data.settings || {};
            applySettingsToForm(document.getElementById('apiKeysForm'), settings);
            updateFeedKeyBanner();
        }).catch(function (err) {
            showAlert('Failed to load API keys: ' + err.message, 'danger');
        });
    }

    // ═══════════════════════════════════════════════════════════════
    //  MAP DEFAULTS (uses settings API)
    // ═══════════════════════════════════════════════════════════════
    function bindMapDefaultsPanel() {
        var form = document.getElementById('mapDefaultsForm');
        if (!form) return;

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var pairs = collectSettingsFromForm(form);
            apiPost('settings', { settings: pairs }).then(function (data) {
                showAlert('Map defaults saved (' + data.saved + ' updated)');
            }).catch(function (err) {
                showAlert(err.message, 'danger');
            });
        });
    }

    var _mapDefaultsMap = null;
    var _mapDefaultsCrosshair = null;

    function loadMapDefaults() {
        apiGet('settings').then(function (data) {
            var settings = data.settings || {};
            applySettingsToForm(document.getElementById('mapDefaultsForm'), settings);
            initMapDefaultsPreview(settings);
        }).catch(function (err) {
            showAlert('Failed to load map defaults: ' + err.message, 'danger');
        });
    }

    function initMapDefaultsPreview(settings) {
        var container = document.getElementById('mapDefaultsPreview');
        if (!container || !window.L) return;

        var lat = parseFloat(settings.default_lat) || 44.9778;
        var lng = parseFloat(settings.default_lng) || -93.2650;
        var zoom = parseInt(settings.default_zoom, 10) || 12;

        // Destroy previous map if exists (panel might be re-opened)
        if (_mapDefaultsMap) {
            _mapDefaultsMap.remove();
            _mapDefaultsMap = null;
        }

        _mapDefaultsMap = L.map(container, { zoomControl: true }).setView([lat, lng], zoom);

        // Tile layers
        var osmLayer = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap',
            maxZoom: 19
        }).addTo(_mapDefaultsMap);

        var darkLayer = L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
            attribution: '&copy; CartoDB',
            maxZoom: 19
        });

        var topoLayer = L.tileLayer('https://basemap.nationalmap.gov/arcgis/rest/services/USGSImageryTopo/MapServer/tile/{z}/{y}/{x}', {
            attribution: 'USGS',
            maxZoom: 20
        });

        L.control.layers({
            'Street': osmLayer,
            'Dark': darkLayer,
            'Satellite/Topo': topoLayer
        }, null, { collapsed: true, position: 'topright' }).addTo(_mapDefaultsMap);

        // Crosshair marker at center
        _mapDefaultsCrosshair = L.marker([lat, lng], {
            icon: L.divIcon({
                className: 'map-defaults-crosshair',
                html: '<i class="bi bi-plus-lg" style="font-size:24px;color:var(--bs-primary);text-shadow:0 0 3px #fff,0 0 3px #fff"></i>',
                iconSize: [24, 24],
                iconAnchor: [12, 12]
            }),
            interactive: false
        }).addTo(_mapDefaultsMap);

        // Live update: sync map center/zoom to the form fields in real time
        var latInput = document.getElementById('setMapLat');
        var lngInput = document.getElementById('setMapLng');
        var zoomInput = document.getElementById('setMapZoom');

        function syncMapToFields() {
            var c = _mapDefaultsMap.getCenter();
            var z = _mapDefaultsMap.getZoom();
            if (latInput) latInput.value = c.lat.toFixed(5);
            if (lngInput) lngInput.value = c.lng.toFixed(5);
            if (zoomInput) zoomInput.value = z;
            // Keep crosshair at center
            if (_mapDefaultsCrosshair) {
                _mapDefaultsCrosshair.setLatLng(c);
            }
        }
        _mapDefaultsMap.on('moveend', syncMapToFields);
        _mapDefaultsMap.on('zoomend', syncMapToFields);

        // Reverse: typing in fields updates the map
        function syncFieldsToMap() {
            var la = parseFloat(latInput ? latInput.value : 0);
            var ln = parseFloat(lngInput ? lngInput.value : 0);
            var z = parseInt(zoomInput ? zoomInput.value : 12, 10);
            if (!isNaN(la) && !isNaN(ln) && la >= -90 && la <= 90 && ln >= -180 && ln <= 180) {
                _mapDefaultsMap.setView([la, ln], z, { animate: false });
            }
        }
        if (latInput) latInput.addEventListener('change', syncFieldsToMap);
        if (lngInput) lngInput.addEventListener('change', syncFieldsToMap);
        if (zoomInput) zoomInput.addEventListener('change', syncFieldsToMap);

        // Fix map rendering after panel becomes visible
        setTimeout(function () {
            if (_mapDefaultsMap) _mapDefaultsMap.invalidateSize();
        }, 300);
    }

    // ═══════════════════════════════════════════════════════════════
    //  TILE PROVIDERS (uses settings API + auto-populate URL)
    // ═══════════════════════════════════════════════════════════════
    var _tilePreviewMap = null;
    var _tilePreviewLayer = null;

    // Provider metadata: cost, key requirement, notes
    var TILE_INFO = {
        osm:           { cost: 'Free', key: false, note: 'Community-maintained. No API key. Best default choice.' },
        google_street: { cost: 'Paid (free tier)', key: true, note: 'Requires Google Cloud API key with Maps JavaScript API enabled. $200/month free credit.' },
        google_sat:    { cost: 'Paid (free tier)', key: true, note: 'Satellite imagery from Google. Same API key as Streets.' },
        google_hybrid: { cost: 'Paid (free tier)', key: true, note: 'Satellite + road labels overlay. Same API key.' },
        bing_road:     { cost: 'Free (limited)', key: true, note: 'Requires Bing Maps key from bingmapsportal.com. 125K transactions/year free.' },
        bing_aerial:   { cost: 'Free (limited)', key: true, note: 'Aerial/satellite from Bing. Same key as Road.' },
        esri_street:   { cost: 'Free', key: false, note: 'ArcGIS street map. No API key for basic access. High quality.' },
        esri_sat:      { cost: 'Free', key: false, note: 'ArcGIS satellite imagery. Excellent US coverage.' },
        esri_topo:     { cost: 'Free', key: false, note: 'ArcGIS topographic. Great for rural/wilderness areas.' },
        mapbox:        { cost: 'Free tier', key: true, note: 'Requires Mapbox access token. 200K tile requests/month free. Beautiful custom styles.' },
        custom:        { cost: 'Varies', key: false, note: 'Enter any XYZ tile URL. Use {s}, {z}, {x}, {y} placeholders. {key} for API key.' }
    };

    function bindTileProviderPanel() {
        var form = document.getElementById('tileProviderForm');
        if (!form) return;

        var providerSelect = document.getElementById('setTileProvider');
        var urlInput = document.getElementById('setTileUrl');
        var infoEl = document.getElementById('tileProviderInfo');

        // Auto-populate URL and show info when provider changes
        providerSelect.addEventListener('change', function () {
            var key = providerSelect.value;
            if (TILE_URLS[key] !== undefined) {
                urlInput.value = TILE_URLS[key];
            }
            updateTileProviderInfo(key);
            updateTilePreview();
        });

        // Test/refresh button
        var testBtn = document.getElementById('btnTestTiles');
        if (testBtn) {
            testBtn.addEventListener('click', updateTilePreview);
        }

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var pairs = collectSettingsFromForm(form);
            apiPost('settings', { settings: pairs }).then(function (data) {
                showAlert('Tile settings saved (' + data.saved + ' updated)');
            }).catch(function (err) {
                showAlert(err.message, 'danger');
            });
        });
    }

    function updateTileProviderInfo(providerKey) {
        var infoEl = document.getElementById('tileProviderInfo');
        if (!infoEl) return;

        var info = TILE_INFO[providerKey];
        if (!info) { infoEl.innerHTML = ''; return; }

        var keyBadge = info.key
            ? '<span class="badge bg-warning text-dark">API Key Required</span>'
            : '<span class="badge bg-success">No Key Needed</span>';

        infoEl.innerHTML = '<div class="d-flex align-items-center gap-2 mb-1">' +
            keyBadge +
            ' <span class="text-body-secondary">' + esc(info.cost) + '</span>' +
            '</div>' +
            '<div class="text-body-secondary">' + esc(info.note) + '</div>';
    }

    function updateTilePreview() {
        var container = document.getElementById('tilePreviewMap');
        if (!container || !window.L) return;

        var urlInput = document.getElementById('setTileUrl');
        var apiKeyInput = document.getElementById('setTileApiKey');
        var statusEl = document.getElementById('tileTestStatus');
        var url = urlInput ? urlInput.value : '';

        if (!url) {
            if (statusEl) { statusEl.textContent = 'No tile URL set'; statusEl.className = 'small text-warning'; }
            return;
        }

        // Substitute API key placeholder
        var apiKey = apiKeyInput ? apiKeyInput.value : '';
        if (apiKey) {
            url = url.replace('{key}', apiKey).replace('{access_token}', apiKey);
        }

        // Quadkey ({q}) providers (Bing-style) are now rendered for real via
        // the makeTileLayer factory from leaflet-quadkey.js — no OSM fallback.
        var isQuadkey = url.indexOf('{q}') !== -1;

        // Get current default center for preview
        var latInput = document.getElementById('setMapLat');
        var lngInput = document.getElementById('setMapLng');
        var zoomInput = document.getElementById('setMapZoom');
        var lat = parseFloat(latInput ? latInput.value : 0) || 44.9778;
        var lng = parseFloat(lngInput ? lngInput.value : 0) || -93.2650;
        var zoom = parseInt(zoomInput ? zoomInput.value : 0, 10) || 12;

        // Initialize or update the preview map
        if (!_tilePreviewMap) {
            _tilePreviewMap = L.map(container, { zoomControl: true }).setView([lat, lng], zoom);
        } else {
            _tilePreviewMap.setView([lat, lng], zoom);
        }

        // Remove previous tile layer
        if (_tilePreviewLayer) {
            _tilePreviewMap.removeLayer(_tilePreviewLayer);
        }

        // Subdomain set depends on the URL template. Bing t{s} runs 0..7;
        // Google mt{s} runs 0..3; everything else uses the OSM-style a/b/c.
        var subdomains = 'abc';
        if (url.indexOf('mt{s}') !== -1) subdomains = '0123';
        if (url.indexOf('t{s}') !== -1) subdomains = '01234567';

        // makeTileLayer (leaflet-quadkey.js) returns a quadkey layer when the
        // URL has {q} (Bing renders for real), else a plain L.tileLayer.
        // Defensive: if the factory script didn't load, fall back to a plain
        // tile layer so the preview never throws.
        var makeLayer = (typeof window.makeTileLayer === 'function')
            ? window.makeTileLayer
            : function (u, o) { return L.tileLayer(u, o); };

        _tilePreviewLayer = makeLayer(url, {
            subdomains: subdomains,
            maxZoom: 19,
            attribution: 'Preview'
        }).addTo(_tilePreviewMap);

        if (statusEl && isQuadkey) {
            statusEl.textContent = 'Bing quadkey provider — rendering live tiles (a valid Bing Maps key is required)';
            statusEl.className = 'small text-info';
        }

        // Check if tiles actually load (success/error status), regardless of
        // whether this is a quadkey or standard XYZ source.
        var loadCount = 0;
        var errorCount = 0;
        _tilePreviewLayer.on('tileload', function () {
            loadCount++;
            if (loadCount === 1 && statusEl) {
                statusEl.textContent = 'Tiles loading successfully';
                statusEl.className = 'small text-success';
            }
        });
        _tilePreviewLayer.on('tileerror', function () {
            errorCount++;
            if (errorCount >= 3 && statusEl) {
                statusEl.textContent = 'Tile load errors detected — check URL and API key';
                statusEl.className = 'small text-danger';
            }
        });

        _tilePreviewMap.invalidateSize();
    }

    function loadTileProvider() {
        apiGet('settings').then(function (data) {
            var settings = data.settings || {};
            applySettingsToForm(document.getElementById('tileProviderForm'), settings);
            // Show provider info for current selection
            var sel = document.getElementById('setTileProvider');
            if (sel) updateTileProviderInfo(sel.value);
            // Initialize preview after a short delay for panel rendering
            setTimeout(updateTilePreview, 300);
        }).catch(function (err) {
            showAlert('Failed to load tile settings: ' + err.message, 'danger');
        });
    }

    // ═══════════════════════════════════════════════════════════════
    //  USER ACCOUNTS
    // ═══════════════════════════════════════════════════════════════
    function bindUsersPanel() {
        var form = document.getElementById('userForm');
        var panel = document.getElementById('userEditPanel');

        document.getElementById('btnAddUser').addEventListener('click', function () {
            openUserForm(null);
        });

        // Phase 11 (2026-06-11): keep #userLevelDerived in sync whenever
        // the admin changes the role dropdown. The hidden input mirrors
        // the chosen role's legacy_level so older code paths still see a
        // coherent data.level value.
        var userRoleSel = document.getElementById('userRoleId');
        if (userRoleSel) {
            userRoleSel.addEventListener('change', syncDerivedLevelFromRole);
        }

        // Phase 10c (2026-06-11): reveal the reason row the moment the admin
        // starts typing a password on an EXISTING user's record. Skipped
        // when creating a new user (no existing user.id) and skipped when
        // editing one's own account (self-change doesn't need a reason).
        var userPassInput = document.getElementById('userPass');
        if (userPassInput) {
            userPassInput.addEventListener('input', function () {
                var reasonRow = document.getElementById('adminResetReasonRow');
                if (!reasonRow) return;
                var editingId = parseInt(document.getElementById('userId').value || '0', 10);
                // We need to know the admin's own user_id to detect self-edit.
                // It's emitted by settings.php into a hidden #userLevel input
                // for the CURRENT admin (not the edit form). Skip the reason
                // gate when admin is editing themselves — they're already
                // authenticated and their session won't be killed.
                var meId = parseInt((window.__currentUserId || 0), 10);
                var isAdminResetOfOther = editingId > 0 && editingId !== meId;
                if (isAdminResetOfOther && this.value.length > 0) {
                    reasonRow.classList.remove('d-none');
                } else {
                    reasonRow.classList.add('d-none');
                }
            });
        }
        document.getElementById('btnCancelUser').addEventListener('click', function () {
            panel.classList.remove('show');
        });
        document.getElementById('btnDeleteUser').addEventListener('click', function () {
            var id = document.getElementById('userId').value;
            if (!id || !confirm('Delete this user account?')) return;
            apiDelete('users', id).then(function () {
                panel.classList.remove('show');
                showAlert('User deleted');
                loadedPanels['user-accounts'] = false;
                loadUsers();
            }).catch(function (err) { showAlert(err.message, 'danger'); });
        });

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var data = formToObject(form);
            var canLoginBox = document.getElementById('userCanLogin');
            data.can_login = canLoginBox && canLoginBox.checked ? 1 : 0;

            // Phase 9 (2026-06-08): explicit boolean for force-pw-change flag.
            // The server's api/config-admin.php honours this value when present
            // and defaults from the system setting when absent.
            var forcePwBox = document.getElementById('userForcePw');
            if (forcePwBox) {
                data.must_change_password = forcePwBox.checked ? 1 : 0;
            }

            // Phase 10c (2026-06-11): if admin is changing another user's
            // password via this form, require the reason field. The server
            // enforces this too (returns HTTP 400), but the client-side
            // check gives an immediate clear error before the round-trip.
            var editingId = parseInt(data.id || '0', 10);
            var meId = parseInt((window.__currentUserId || 0), 10);
            var isResetOfOther = editingId > 0 && editingId !== meId;
            var newPw = String(data.password || '');
            if (isResetOfOther && newPw.length > 0) {
                var reason = String(data.reason || '').trim();
                if (reason.length < 3) {
                    showAlert(
                        'A reason is required when changing another user\'s password '
                        + '(3+ characters). This is recorded in the audit log for '
                        + 'CJIS compliance.',
                        'warning'
                    );
                    var rInput = document.getElementById('userResetReason');
                    if (rInput) rInput.focus();
                    return;
                }
                // Confirmation prompt — destructive: kills sessions + forces change.
                if (!confirm('Reset password for this user?\n\n'
                    + 'They will be required to choose a new password on next login. '
                    + 'All their existing sessions will be terminated.\n\n'
                    + 'Reason logged: "' + reason.substring(0, 200) + '"')) {
                    return;
                }
            }

            // Save-time access-chain check. Replaces the earlier passive
            // yellow banner that admins skimmed past (per Eric 2026-06-02).
            // The admin must explicitly Continue or Cancel — either choice
            // is recorded by which branch runs, but only Cancel stops the
            // save. See docs/ACCESS-CHAIN.md for the access chain itself.
            var memberId  = String(data.member || '').trim();
            var memberMap = {};
            for (var i = 0; i < (cache.userMembers || []).length; i++) {
                memberMap[String(cache.userMembers[i].id)] = cache.userMembers[i];
            }
            var confirmMsg = null;
            if (memberId === '') {
                confirmMsg = 'This user is not linked to a member record.\n\n'
                  + 'They will be authenticated by RBAC role and will see data across ALL '
                  + 'organizations (no org filter applied). This is correct for service / '
                  + 'integration accounts; usually wrong for a person who should be scoped '
                  + 'to one agency.\n\n'
                  + 'Click OK to save anyway, or Cancel to link the user to a member first.';
            } else {
                var m = memberMap[memberId];
                if (m && (parseInt(m.org_count, 10) || 0) === 0) {
                    var memberName = ((m.last_name || '') + ', ' + (m.first_name || '')).trim().replace(/^,\s*/, '');
                    confirmMsg = 'The selected member "' + memberName + '" is not assigned '
                      + 'to any organization.\n\n'
                      + 'The user will be authenticated by RBAC role and will see data '
                      + 'across ALL organizations (no org filter applied) until the member '
                      + 'is assigned to one. To scope this user to a single agency, go to '
                      + 'Personnel → Roster, open the member, and Add to Organization.\n\n'
                      + 'Click OK to save the user anyway, or Cancel to fix the member '
                      + 'assignment first.';
                }
            }
            if (confirmMsg !== null && !confirm(confirmMsg)) {
                return;   // admin chose Cancel — form stays open, no save
            }

            apiPost('users', data).then(function () {
                panel.classList.remove('show');
                showAlert('User saved');
                loadedPanels['user-accounts'] = false;
                loadUsers();
            }).catch(function (err) { showAlert(err.message, 'danger'); });
        });
    }

    function loadUsers() {
        apiGet('users').then(function (data) {
            cache.users = data.rows || [];
            cache.userMembers = data.members || [];
            // Eric beta 2026-06-30 — bind sort headers + hide-inactive
            // toggle once. Safe to call on every load (static-guarded).
            bindUsersTableControls();
            renderUsers();
        }).catch(function (err) {
            document.getElementById('usersStatus').textContent = 'Error: ' + err.message;
        });
    }

    // Eric beta 2026-06-30 — sort + filter state for the User Accounts
    // table. Lives in module scope so re-renders (toggling the filter,
    // clicking a column header) don't reset each other.
    var usersTableState = { sortKey: 'user', sortDir: 'asc', hideInactive: false };

    function renderUsers() {
        var tbody = document.getElementById('usersTableBody');
        var s = usersTableState;

        // 1) Build a working copy with derived sort-keys we don't store
        //    on the row itself (memberLabel for stable string sort, etc.).
        var rows = cache.users.map(function (u) {
            var memberSort = '';
            if (u.member_name && u.member_name.trim() !== '') {
                memberSort = (u.member_callsign ? u.member_callsign + ' ' : '') + u.member_name;
            }
            return Object.assign({}, u, {
                member_sort: memberSort.toLowerCase(),
                _can_login_num: (u.can_login === undefined || parseInt(u.can_login, 10) !== 0) ? 1 : 0
            });
        });

        // 2) Apply filter.
        if (s.hideInactive) {
            rows = rows.filter(function (u) { return u._can_login_num === 1; });
        }

        // 3) Sort. Numeric for id / can_login, case-insensitive string
        //    for everything else. Missing values sort last either way.
        var key = s.sortKey;
        var dir = s.sortDir === 'desc' ? -1 : 1;
        var keyType = (key === 'id' || key === 'can_login') ? 'num' : 'str';
        var sortKey = (key === 'can_login') ? '_can_login_num' : key;
        rows.sort(function (a, b) {
            var av = a[sortKey];
            var bv = b[sortKey];
            // empty / null / undefined → sort last in either direction
            var aEmpty = (av === null || av === undefined || av === '');
            var bEmpty = (bv === null || bv === undefined || bv === '');
            if (aEmpty && bEmpty) return 0;
            if (aEmpty) return 1;
            if (bEmpty) return -1;
            if (keyType === 'num') return (parseFloat(av) - parseFloat(bv)) * dir;
            return String(av).toLowerCase().localeCompare(String(bv).toLowerCase()) * dir;
        });

        // 4) Render.
        var html = '';
        for (var i = 0; i < rows.length; i++) {
            var u = rows[i];
            var canLogin = u._can_login_num === 1;
            var loginIcon = canLogin
                ? '<i class="bi bi-check-circle-fill text-success" title="Active"></i>'
                : '<i class="bi bi-x-circle-fill text-danger" title="Disabled"></i>';
            var memberLabel = '';
            if (u.member_name && u.member_name.trim() !== '') {
                memberLabel = esc(u.member_callsign ? u.member_callsign + ' - ' : '') + esc(u.member_name);
            } else {
                memberLabel = '<span class="text-body-tertiary">—</span>';
            }
            // Phase 11 (2026-06-11): the Role column shows the user's
            // active role (returned as role_name on each row). Shows
            // a yellow warning when the account has no active grant —
            // that state only exists transiently on a fresh install
            // or right after legacy-account migration, and the admin
            // can fix it by clicking Edit and choosing a role.
            var roleCell = u.role_name
                ? esc(u.role_name)
                : '<span class="text-warning" title="No role assigned — click Edit to assign one, or open Roles &amp; Permissions and use Migrate Legacy Accounts to Roles">' +
                  '— <i class="bi bi-exclamation-triangle"></i></span>';
            // Eric beta 2026-06-30 — Scope column. Org-scoped role grants
            // show the org name; global grants show "Global" in muted text.
            var scopeCell;
            if (u.role_org_name) {
                scopeCell = esc(u.role_org_name);
            } else if (u.role_name) {
                // Has a role but no org — global grant.
                scopeCell = '<span class="text-body-tertiary small">Global</span>';
            } else {
                scopeCell = '<span class="text-body-tertiary">—</span>';
            }
            html += '<tr data-id="' + u.id + '"' + (canLogin ? '' : ' class="text-body-tertiary"') + '>' +
                '<td>' + u.id + '</td>' +
                '<td>' + esc(u.user) + '</td>' +
                '<td>' + roleCell + '</td>' +
                '<td>' + scopeCell + '</td>' +
                '<td>' + memberLabel + '</td>' +
                '<td class="text-center">' + loginIcon + '</td>' +
                '</tr>';
        }
        tbody.innerHTML = html || '<tr><td colspan="6" class="text-center text-body-secondary py-3">No users found</td></tr>';

        var total = cache.users.length;
        var shown = rows.length;
        var status = shown + ' of ' + total + ' user' + (total !== 1 ? 's' : '');
        if (s.hideInactive && shown < total) status += ' (filtered)';
        document.getElementById('usersStatus').textContent = status;

        // 5) Update sort indicators on column headers.
        var table = document.getElementById('usersTable');
        if (table) {
            var heads = table.querySelectorAll('thead th[data-sort-key]');
            for (var h = 0; h < heads.length; h++) {
                var ind = heads[h].querySelector('.sort-ind');
                if (!ind) continue;
                if (heads[h].getAttribute('data-sort-key') === s.sortKey) {
                    ind.textContent = s.sortDir === 'desc' ? '▼' : '▲';
                } else {
                    ind.textContent = '';
                }
            }
        }

        // 6) Row click → open edit form.
        var trs = tbody.querySelectorAll('tr[data-id]');
        for (var j = 0; j < trs.length; j++) {
            trs[j].addEventListener('click', function () {
                var item = findById(cache.users, parseInt(this.getAttribute('data-id'), 10));
                if (item) openUserForm(item);
            });
        }
    }

    // Eric beta 2026-06-30 — wire the column headers + the hide-inactive
    // toggle to re-render in place. Bound once per panel load via the
    // loadUsers flow below; guarded by a static flag to dedupe.
    function bindUsersTableControls() {
        if (bindUsersTableControls._bound) return;
        bindUsersTableControls._bound = true;
        var table = document.getElementById('usersTable');
        if (table) {
            var heads = table.querySelectorAll('thead th[data-sort-key]');
            heads.forEach(function (th) {
                th.addEventListener('click', function () {
                    var key = th.getAttribute('data-sort-key');
                    if (usersTableState.sortKey === key) {
                        usersTableState.sortDir = usersTableState.sortDir === 'asc' ? 'desc' : 'asc';
                    } else {
                        usersTableState.sortKey = key;
                        usersTableState.sortDir = 'asc';
                    }
                    renderUsers();
                });
            });
        }
        var toggle = document.getElementById('usersHideInactive');
        if (toggle) {
            toggle.addEventListener('change', function () {
                usersTableState.hideInactive = toggle.checked;
                renderUsers();
            });
        }
    }

    /*
     * Phase 11 — fetch the live roles list once per panel-open session
     * and populate the User Accounts form's "Role & Permissions set"
     * dropdown. Cached in `cache.roles` so reopening the form is
     * instant. The callback runs after the dropdown is populated so
     * the caller can set the selected value.
     */
    function populateUserRoleDropdown(done) {
        var sel = document.getElementById('userRoleId');
        if (!sel) { if (done) done(); return; }

        function render(roles) {
            var html = '<option value="">— Select a role —</option>';
            for (var i = 0; i < roles.length; i++) {
                var r = roles[i];
                // Phase 11c (2026-06-11): no visible distinction between
                // installation-included roles and admin-created ones.
                // All roles render uniformly per Eric's preference.
                html += '<option value="' + r.id + '">' + escapeHtml(r.name || '') + '</option>';
            }
            sel.innerHTML = html;
            if (done) done();
        }

        if (cache.roles) { render(cache.roles); return; }

        fetch('api/config-admin.php?section=roles', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                cache.roles = data.roles || [];
                render(cache.roles);
            })
            .catch(function () {
                cache.roles = [];
                render([]);
            });
    }

    /*
     * Phase 99j-2 (Billy beta 2026-06-29): populate both Home Org and
     * Role Scope dropdowns from the organizations list. Two selectors
     * share one source. Result is cached in cache.organizations so
     * reopening the form doesn't refetch.
     */
    function populateUserOrgDropdowns(done) {
        var homeSel = document.getElementById('userHomeOrg');
        var roleOrgSel = document.getElementById('userRoleOrgId');
        if (!homeSel && !roleOrgSel) { if (done) done(); return; }

        function render(orgs) {
            var homeOpts = '<option value="">— Select home org —</option>';
            var scopeOpts = '<option value="">— All orgs (global grant) —</option>';
            for (var i = 0; i < orgs.length; i++) {
                var o = orgs[i];
                if (parseInt(o.active, 10) === 0) continue;
                var label = escapeHtml(o.name || '');
                if (o.short_name && o.short_name !== o.name) {
                    label += ' (' + escapeHtml(o.short_name) + ')';
                }
                homeOpts  += '<option value="' + o.id + '">' + label + '</option>';
                scopeOpts += '<option value="' + o.id + '">' + label + '</option>';
            }
            if (homeSel)    homeSel.innerHTML = homeOpts;
            if (roleOrgSel) roleOrgSel.innerHTML = scopeOpts;
            if (done) done();
        }

        if (cache.organizations) { render(cache.organizations); return; }

        fetch('api/organizations.php', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                cache.organizations = data.rows || data.organizations || [];
                render(cache.organizations);
            })
            .catch(function () {
                cache.organizations = [];
                render([]);
            });
    }

    /*
     * Phase 11 — keep the hidden #userLevelDerived input in sync with
     * the selected role's legacy_level. The Phase 11 backend prefers
     * role_id, but any older code path that still reads data.level
     * should see a coherent value (not empty / NaN).
     */
    function syncDerivedLevelFromRole() {
        var roleSel = document.getElementById('userRoleId');
        var derived = document.getElementById('userLevelDerived');
        if (!roleSel || !derived) return;
        var rid = parseInt(roleSel.value || '0', 10);
        var match = (cache.roles || []).filter(function (r) {
            return parseInt(r.id, 10) === rid;
        })[0];
        if (match && match.legacy_level !== null && match.legacy_level !== undefined) {
            derived.value = String(match.legacy_level);
        } else {
            // Phase 11d (2026-06-11): custom role with no legacy mapping
            // defaults to 3 (Read-Only equivalent) — matches the
            // server-side fallback in api/config-admin.php. The previous
            // fallback of 4 (Field Unit equivalent) incorrectly triggered
            // the legacy "send to mobile.php on login" redirect.
            derived.value = '3';
        }
    }

    // Clear a user's account lockout + failed-attempt counter. The lockout
    // feature has no other UI control; this wires the existing
    // login-security.php?action=unlock_account endpoint. Never touches the
    // password — just the attempt counter.
    function unlockUserAccount(username) {
        if (!username) return;
        fetch('api/login-security.php?action=unlock_account', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ username: username, csrf_token: getCsrfToken() })
        })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d.error) { toast(d.error, 'danger'); return; }
                toast(d.message || ('Account ' + username + ' unlocked'), 'success');
            })
            .catch(function (e) { toast(e.message, 'danger'); });
    }

    function openUserForm(item) {
        var panel = document.getElementById('userEditPanel');
        document.getElementById('userId').value = item ? item.id : '';
        document.getElementById('userName').value = item ? item.user : '';

        // Admin info (read-only) + Unlock control. last_login / tfa_enrolled
        // come from api/config-admin.php?section=users. Shown only when editing
        // an existing user (hidden on create).
        var adminInfo = document.getElementById('userAdminInfo');
        if (adminInfo) adminInfo.style.display = item ? '' : 'none';
        var llEl = document.getElementById('userLastLogin');
        if (llEl) llEl.textContent = (item && item.last_login) ? String(item.last_login) : 'never';
        var tfaEl = document.getElementById('userTfaStatus');
        if (tfaEl) tfaEl.textContent = (item && parseInt(item.tfa_enrolled || 0, 10) > 0) ? 'Enrolled' : 'Not enrolled';
        var unlockBtn = document.getElementById('btnUnlockUser');
        if (unlockBtn && item) { unlockBtn.onclick = function () { unlockUserAccount(item.user); }; }

        // Phase 11 (2026-06-11): populate the Role & Permissions dropdown
        // from the live /api/config-admin.php?section=roles endpoint.
        // Cache the roles list so reopening the form doesn't refetch.
        // Default to Read-Only (role id 5) on CREATE — safest least-
        // privilege start for a new account. On EDIT, reflect the user's
        // current primary role (item.role_id).
        populateUserRoleDropdown(function () {
            var roleSelect = document.getElementById('userRoleId');
            if (roleSelect) {
                if (item && item.role_id) {
                    roleSelect.value = String(item.role_id);
                } else {
                    // Find Read-Only by name; fall back to first non-system
                    // role; final fallback to first option.
                    var preferred = String((cache.roles || [])
                        .filter(function (r) { return r.name === 'Read-Only'; })
                        .map(function (r) { return r.id; })[0] || '');
                    roleSelect.value = preferred || (cache.roles && cache.roles[0] ? String(cache.roles[0].id) : '');
                }
                syncDerivedLevelFromRole();
            }
        });

        // Phase 99j-2 (Billy beta 2026-06-29): populate the two org
        // dropdowns from the organizations list. Home Org defaults to
        // the user's current home_org_id (or 1 for new users). Role
        // Scope defaults to whatever org_id is set on the user's
        // current global/org role grant (empty = global).
        populateUserOrgDropdowns(function () {
            var homeSel = document.getElementById('userHomeOrg');
            if (homeSel) {
                if (item && item.home_org_id) {
                    homeSel.value = String(item.home_org_id);
                } else {
                    homeSel.value = '1';   // System Owner default
                }
            }
            var roleOrgSel = document.getElementById('userRoleOrgId');
            if (roleOrgSel) {
                roleOrgSel.value = (item && item.role_org_id)
                    ? String(item.role_org_id) : '';
            }
        });

        // Maintain user.level via the hidden #userLevelDerived input so the
        // server gets a coherent legacy value for backward compat.
        // (Phase 11 backend prefers role_id, but if anything still reads
        // data.level it should be coherent.)
        document.getElementById('userPass').value = '';
        var canLoginBox = document.getElementById('userCanLogin');
        if (canLoginBox) {
            canLoginBox.checked = item ? (item.can_login === undefined || parseInt(item.can_login, 10) !== 0) : true;
        }

        // Phase 10c (2026-06-11): reset the reason row visibility + value
        // whenever the form is reopened. The input listener below reveals
        // it as soon as the admin starts typing a password — but on a
        // fresh open we always start hidden.
        var reasonRow = document.getElementById('adminResetReasonRow');
        var reasonInput = document.getElementById('userResetReason');
        if (reasonRow) reasonRow.classList.add('d-none');
        if (reasonInput) reasonInput.value = '';

        // Phase 9 (2026-06-08): default the force-password-change toggle.
        //   * On EDIT  → reflect user.must_change_password (0/1)
        //   * On CREATE → default from system setting force_pw_change_for_new_users.
        // The system-setting default is fetched from the loginSettings cache
        // (already loaded by openPanel('login-settings')) OR we infer it from
        // the existing form's checkbox if it's been opened. If neither is
        // available, default to ON (safer for security).
        var forcePwBox = document.getElementById('userForcePw');
        if (forcePwBox) {
            if (item) {
                // Edit existing user — use whatever the API returned.
                forcePwBox.checked = parseInt(item.must_change_password || 0, 10) === 1;
            } else {
                // New user — default from system setting. Read live from the
                // Login Settings form if it's been rendered (the value would
                // already be populated by loadLoginSettings); otherwise fall
                // through to "ON" as the secure default.
                var sysToggle = document.getElementById('setForcePwChangeNew');
                if (sysToggle) {
                    forcePwBox.checked = !!sysToggle.checked;
                } else if (cache.loginSettings &&
                           cache.loginSettings.force_pw_change_for_new_users !== undefined) {
                    forcePwBox.checked = String(cache.loginSettings.force_pw_change_for_new_users) === '1';
                } else {
                    forcePwBox.checked = true;
                }
            }
        }

        // Populate the Link-to-Member combobox via the SearchableSelect
        // component (specs/searchable-member-dropdown-2026-05). The
        // visible input is #userMemberDisplay; the hidden input
        // #userMember carries name="member" so form POST is unchanged.
        var memberInput  = document.getElementById('userMemberDisplay');
        var memberHidden = document.getElementById('userMember');
        if (memberInput && memberHidden && window.SearchableSelect) {
            // Defensive client-side sort in case an older install's API
            // returned rows out of order. Sort by last name, then first.
            var membersRaw = (cache.userMembers || []).slice();
            membersRaw.sort(function (a, b) {
                var la = (a.last_name || '').toLowerCase();
                var lb = (b.last_name || '').toLowerCase();
                if (la < lb) return -1;
                if (la > lb) return 1;
                var fa = (a.first_name || '').toLowerCase();
                var fb = (b.first_name || '').toLowerCase();
                if (fa < fb) return -1;
                if (fa > fb) return 1;
                return 0;
            });

            if (!userMemberPicker) {
                userMemberPicker = SearchableSelect.attach(memberInput, memberHidden, membersRaw, {
                    emptyLabel: '— Not linked —',
                    placeholder: 'Type a name or callsign, or click to browse',
                    // Label puts the name FIRST so the visible alphabetical
                    // order is obvious. Callsign in parens at the end.
                    getLabel: function (m) {
                        var lbl = (m.last_name || '') + ', ' + (m.first_name || '');
                        if (m.callsign) lbl += ' (' + m.callsign + ')';
                        return lbl;
                    },
                    getValue: function (m) { return String(m.id); },
                    getSearchText: function (m) {
                        return ((m.last_name || '') + ' '
                              + (m.first_name || '') + ' '
                              + (m.callsign || '')).toLowerCase();
                    }
                });
            } else {
                userMemberPicker.setItems(membersRaw);
            }

            userMemberPicker.setValue(item && item.member ? String(item.member) : '');
            // Access-chain check fires at SAVE time, not on form open —
            // see the userForm submit handler in bindUsersPanel() above.
            // The earlier passive banner here was easy to miss; an
            // explicit confirm() at save makes the admin acknowledge.
        }

        document.getElementById('btnDeleteUser').classList.toggle('d-none', !item);
        panel.classList.add('show');
        document.getElementById('userName').focus();
    }

    // ═══════════════════════════════════════════════════════════════
    //  WARN LOCATIONS
    // ═══════════════════════════════════════════════════════════════

    var WARN_TYPE_LABELS = {
        0: '⚠️ General',
        1: '☣️ Hazmat',
        2: '🔫 Threat',
        3: '🐕 Animal',
        4: '🏚️ Structural',
        5: '⚡ Utility',
        6: '🔒 Access',
        7: '🏥 Medical',
        8: '📋 Info'
    };

    // Map state — module-level vars
    var warnLocMap = null;
    var warnLocMarker = null;
    var warnLocCircle = null;
    var warnLocMapReady = false;

    function bindWarnLocationsPanel() {
        var panel = document.getElementById('warnLocEditPanel');
        var form  = document.getElementById('warnLocForm');
        var addBtn    = document.getElementById('btnAddWarnLoc');
        var cancelBtn = document.getElementById('btnCancelWarnLoc');
        var deleteBtn = document.getElementById('btnDeleteWarnLoc');
        var search    = document.getElementById('warnLocSearch');
        var radiusInput = document.getElementById('warnLocRadius');

        // Issue #42: warn-location State is a DB-backed <select>.
        if (window.TCADStates) { window.TCADStates.fill(document.getElementById('warnLocState')); }

        // Global default alert-radius control (maps-comprehensive-2026-06).
        // Bound here so it wires up whenever the Warn Locations panel loads.
        bindWarnProximityDefault();

        if (!form || !addBtn) return;

        addBtn.addEventListener('click', function () {
            openWarnLocForm(null);
        });

        cancelBtn.addEventListener('click', function () {
            panel.classList.remove('show');
        });

        deleteBtn.addEventListener('click', function () {
            var id = document.getElementById('warnLocId').value;
            if (!id) return;
            if (!confirm('Delete this warn location?')) return;
            apiDelete('warn_locations', id).then(function () {
                panel.classList.remove('show');
                showAlert('Warn location deleted');
                loadedPanels['warn-locations'] = false;
                loadWarnLocations();
            }).catch(function (err) { showAlert(err.message, 'danger'); });
        });

        form.addEventListener('submit', function (ev) {
            ev.preventDefault();
            apiPost('warn_locations', formToObject(form)).then(function () {
                panel.classList.remove('show');
                showAlert('Warn location saved');
                loadedPanels['warn-locations'] = false;
                loadWarnLocations();
            }).catch(function (err) { showAlert(err.message, 'danger'); });
        });

        // Live search filter
        if (search) {
            search.addEventListener('input', function () {
                renderWarnLocations(this.value.toLowerCase());
            });
        }

        // Radius input — live update circle on map
        if (radiusInput) {
            radiusInput.addEventListener('input', function () {
                if (warnLocCircle) {
                    warnLocCircle.setRadius(parseInt(this.value, 10) || 500);
                }
            });
        }

        // Lookup button — forward geocode street/city/state → place marker
        var lookupBtn = document.getElementById('btnWarnLocLookup');
        if (lookupBtn) {
            lookupBtn.addEventListener('click', function () {
                geocodeWarnLoc();
            });
        }

        // Also allow Enter key in street/city fields to trigger lookup
        var streetEl = document.getElementById('warnLocStreet');
        var cityEl   = document.getElementById('warnLocCity');
        if (streetEl) {
            streetEl.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') { e.preventDefault(); geocodeWarnLoc(); }
            });
        }
        if (cityEl) {
            cityEl.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') { e.preventDefault(); geocodeWarnLoc(); }
            });
        }
    }

    // Initialize Leaflet map inside the warn location edit form
    function initWarnLocMap() {
        if (warnLocMapReady) return;
        var container = document.getElementById('warnLocMap');
        if (!container || typeof L === 'undefined') return;

        fetch('api/map-config.php')
            .then(function (r) { return r.json(); })
            .then(function (cfg) {
                var lat  = cfg.def_lat  || 39.8283;
                var lng  = cfg.def_lng  || -98.5795;
                var zoom = cfg.def_zoom || 5;
                buildWarnLocMap(lat, lng, zoom);
            })
            .catch(function () {
                // Fallback defaults
                buildWarnLocMap(39.8283, -98.5795, 5);
            });
    }

    function buildWarnLocMap(defLat, defLng, defZoom) {
        warnLocMap = L.map('warnLocMap', { zoomControl: true }).setView([defLat, defLng], defZoom);

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OSM',
            maxZoom: 19
        }).addTo(warnLocMap);

        // Click to place / move marker
        warnLocMap.on('click', function (e) {
            placeWarnMarker(e.latlng.lat, e.latlng.lng);
            reverseGeocodeWarnLoc(e.latlng.lat, e.latlng.lng);
        });

        warnLocMapReady = true;

        // Fix sizing after DOM renders
        setTimeout(function () { warnLocMap.invalidateSize(); }, 200);
    }

    function placeWarnMarker(lat, lng) {
        // Update form fields
        document.getElementById('warnLocLat').value = lat.toFixed(6);
        document.getElementById('warnLocLng').value = lng.toFixed(6);

        var radius = parseInt(document.getElementById('warnLocRadius').value, 10) || 500;

        if (warnLocMarker) {
            warnLocMarker.setLatLng([lat, lng]);
        } else {
            warnLocMarker = L.marker([lat, lng], { draggable: true }).addTo(warnLocMap);
            warnLocMarker.on('dragend', function (e) {
                var pos = e.target.getLatLng();
                document.getElementById('warnLocLat').value = pos.lat.toFixed(6);
                document.getElementById('warnLocLng').value = pos.lng.toFixed(6);
                if (warnLocCircle) {
                    warnLocCircle.setLatLng(pos);
                }
                reverseGeocodeWarnLoc(pos.lat, pos.lng);
            });
        }

        if (warnLocCircle) {
            warnLocCircle.setLatLng([lat, lng]);
            warnLocCircle.setRadius(radius);
        } else {
            warnLocCircle = L.circle([lat, lng], {
                radius: radius,
                color: '#dc3545',
                fillColor: '#dc3545',
                fillOpacity: 0.12,
                weight: 2,
                dashArray: '6 4'
            }).addTo(warnLocMap);
        }

        warnLocMap.setView([lat, lng], Math.max(warnLocMap.getZoom(), 14));
    }

    function clearWarnMarker() {
        if (warnLocMarker) {
            warnLocMap.removeLayer(warnLocMarker);
            warnLocMarker = null;
        }
        if (warnLocCircle) {
            warnLocMap.removeLayer(warnLocCircle);
            warnLocCircle = null;
        }
    }

    function reverseGeocodeWarnLoc(lat, lng) {
        var url = 'https://nominatim.openstreetmap.org/reverse?format=json&lat=' + lat + '&lon=' + lng;

        fetch(url)
            .then(function (r) { return r.json(); })
            .then(function (result) {
                if (!result || !result.address) return;
                var addr = result.address;

                // Street
                var num  = addr.house_number || '';
                var road = addr.road || '';
                document.getElementById('warnLocStreet').value = (num + ' ' + road).trim();

                // City
                document.getElementById('warnLocCity').value = addr.city || addr.town || addr.village || '';

                // State — simple text input, use abbreviation if available
                var stateVal = addr.state || '';
                // Try ISO code first (e.g. "US-NY" → "NY")
                if (addr['ISO3166-2-lvl4']) {
                    var parts = addr['ISO3166-2-lvl4'].split('-');
                    if (parts.length === 2) stateVal = parts[1];
                }
                // Issue #42: warn-location State is a DB-backed <select>.
                if (window.TCADStates) {
                    window.TCADStates.setValue(document.getElementById('warnLocState'), stateVal);
                } else {
                    document.getElementById('warnLocState').value = stateVal;
                }
            })
            .catch(function () {
                // Reverse geocode is best-effort — silent fail
            });
    }

    // Forward geocode — type an address, click Lookup, place the pin
    function geocodeWarnLoc() {
        var street = document.getElementById('warnLocStreet').value.trim();
        var city   = document.getElementById('warnLocCity').value.trim();
        var state  = document.getElementById('warnLocState').value.trim();
        var btn    = document.getElementById('btnWarnLocLookup');

        if (!street && !city) {
            showAlert('Enter a street address or city to look up.', 'warning');
            return;
        }

        var query = [street, city, state].filter(Boolean).join(', ');
        var url = 'https://nominatim.openstreetmap.org/search?format=json&addressdetails=1&limit=1&q=' + encodeURIComponent(query);

        // Bias results toward the current map view
        if (warnLocMap) {
            var bounds = warnLocMap.getBounds();
            url += '&viewbox=' + bounds.getWest().toFixed(4) + ',' + bounds.getNorth().toFixed(4) +
                   ',' + bounds.getEast().toFixed(4) + ',' + bounds.getSouth().toFixed(4);
            url += '&bounded=0';
        }

        // Visual feedback — disable button while fetching
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Looking up...';
        }

        fetch(url)
            .then(function (r) { return r.json(); })
            .then(function (results) {
                if (results.length === 0) {
                    showAlert('Address not found. Try a different format or click the map directly.', 'warning');
                    return;
                }
                var r = results[0];
                var lat = parseFloat(r.lat);
                var lng = parseFloat(r.lon);

                // Place marker + circle on map
                placeWarnMarker(lat, lng);

                // Fill city/state from result
                if (r.address) {
                    var foundCity = r.address.city || r.address.town || r.address.village || '';
                    if (foundCity) document.getElementById('warnLocCity').value = foundCity;

                    var stateVal = r.address.state || '';
                    if (r.address['ISO3166-2-lvl4']) {
                        var parts = r.address['ISO3166-2-lvl4'].split('-');
                        if (parts.length === 2) stateVal = parts[1];
                    }
                    if (stateVal) {
                        if (window.TCADStates) {
                            window.TCADStates.setValue(document.getElementById('warnLocState'), stateVal);
                        } else {
                            document.getElementById('warnLocState').value = stateVal;
                        }
                    }
                }
            })
            .catch(function () {
                showAlert('Geocoding failed. Click the map to set location manually.', 'warning');
            })
            .then(function () {
                // Restore button
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bi bi-search me-1"></i>Lookup Address';
                }
            });
    }

    // ── Alert Zones combined panel: load summaries ──
    function loadAlertZonesSummary() {
        // Warn locations summary. Issue #35 — the earlier version
        // rendered r.loc_type as a raw integer (0-8) which looked
        // blank / meaningless, and had an "Active" column reading
        // from r.active — but the warnings table doesn't have that
        // column, so it always displayed "No". The full editor
        // (Warn Locations sub-panel) doesn't have an activate/
        // deactivate toggle either, so the column was a lie. Fix:
        // resolve loc_type to its label + drop the phantom Active
        // column entirely.
        var LOC_TYPE_LABELS = {
            0: '⚠️ General Warning',
            1: '☣️ Hazmat / Chemical',
            2: '🔫 Threat / Weapons',
            3: '🐕 Aggressive Animal',
            4: '🏚️ Structural Hazard',
            5: '⚡ Utility Hazard',
            6: '🔒 Access Restriction',
            7: '🏥 Medical Advisory',
            8: '📋 Information Only'
        };
        apiGet('warn_locations').then(function (data) {
            var rows = data.rows || [];
            var el = document.getElementById('azWarnSummary');
            if (el) {
                if (rows.length === 0) {
                    el.innerHTML = '<span class="text-body-tertiary">No warn locations defined.</span>';
                } else {
                    var html = '<table class="table table-sm table-hover mb-0" style="font-size:0.78rem"><thead><tr><th>Type</th><th>Title</th><th>Address</th></tr></thead><tbody>';
                    for (var i = 0; i < rows.length && i < 10; i++) {
                        var r = rows[i];
                        var typeKey = parseInt(r.loc_type, 10);
                        if (isNaN(typeKey)) typeKey = 0;
                        var typeLabel = LOC_TYPE_LABELS[typeKey] || ('type ' + typeKey);
                        html += '<tr><td>' + esc(typeLabel) + '</td><td>' + esc(r.title || '') + '</td>';
                        html += '<td class="small text-body-secondary">' + esc(r.street || '') + (r.city ? ', ' + esc(r.city) : '') + '</td>';
                        html += '</tr>';
                    }
                    if (rows.length > 10) html += '<tr><td colspan="3" class="text-body-secondary text-center">... and ' + (rows.length - 10) + ' more</td></tr>';
                    html += '</tbody></table>';
                    el.innerHTML = html;
                }
            }
        }).catch(function () {});

        // Geofences summary
        fetch('api/geofences.php', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var fences = data.geofences || [];
                var el = document.getElementById('azGeofenceSummary');
                if (el) {
                    if (fences.length === 0) {
                        el.innerHTML = '<span class="text-body-tertiary">No geofences defined.</span>';
                    } else {
                        var html = '<table class="table table-sm table-hover mb-0" style="font-size:0.78rem"><thead><tr><th>Name</th><th>Enter</th><th>Exit</th><th>Active</th></tr></thead><tbody>';
                        for (var i = 0; i < fences.length && i < 10; i++) {
                            var f = fences[i];
                            html += '<tr><td>' + esc(f.name || '') + '</td>';
                            html += '<td>' + (f.alert_on_enter ? '<i class="bi bi-check text-success"></i>' : '-') + '</td>';
                            html += '<td>' + (f.alert_on_exit ? '<i class="bi bi-check text-success"></i>' : '-') + '</td>';
                            html += '<td>' + (f.active ? '<span class="badge bg-success">Yes</span>' : '<span class="badge bg-secondary">No</span>') + '</td></tr>';
                        }
                        html += '</tbody></table>';
                        el.innerHTML = html;
                    }
                }
            }).catch(function () {});

    }

    // Event delegation for all .az-editor-link buttons (handles both alert-zones summary
    // and "Back to Alert Zones" buttons on sub-panels, regardless of when they're rendered)
    document.addEventListener('click', function (e) {
        var link = e.target.closest('.az-editor-link');
        if (link) {
            e.preventDefault();
            var tab = link.getAttribute('data-tab');
            if (tab) activateTab(tab);
        }
    });

    // ── Global default alert radius (warn_proximity / warn_proximity_units) ──
    // Reads + writes the two legacy settings keys through the generic settings
    // section of config-admin.php. Per-location radii override this default;
    // it only applies to warn locations that leave their radius blank.
    function bindWarnProximityDefault() {
        var saveBtn = document.getElementById('btnSaveWarnProximity');
        if (!saveBtn || saveBtn._warnBound) return;
        saveBtn._warnBound = true;

        var inputs = document.querySelectorAll('[data-warn-setting]');

        // Load current values
        fetch('api/config-admin.php?section=settings', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var s = data.settings || {};
                for (var i = 0; i < inputs.length; i++) {
                    var key = inputs[i].getAttribute('data-warn-setting');
                    if (s[key] !== undefined && s[key] !== null && s[key] !== '') {
                        inputs[i].value = String(s[key]);
                    }
                }
            })
            .catch(function () { /* best-effort */ });

        // Save on click
        saveBtn.addEventListener('click', function () {
            var payload = { csrf_token: getCsrfToken(), settings: {} };
            for (var i = 0; i < inputs.length; i++) {
                payload.settings[inputs[i].getAttribute('data-warn-setting')] = inputs[i].value;
            }
            fetch('api/config-admin.php?section=settings', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (d.error) {
                        showAlert('Error: ' + d.error, 'danger');
                    } else {
                        var ok = document.getElementById('warnProximitySaveOk');
                        if (ok) {
                            ok.classList.remove('d-none');
                            setTimeout(function () { ok.classList.add('d-none'); }, 2000);
                        }
                    }
                })
                .catch(function (e) { showAlert('Save failed: ' + e.message, 'danger'); });
        });
    }

    function loadWarnLocations() {
        apiGet('warn_locations').then(function (data) {
            cache.warnLocations = data.rows || [];
            renderWarnLocations();
            var status = document.getElementById('warnLocStatus');
            if (status) status.textContent = cache.warnLocations.length + ' warn location' + (cache.warnLocations.length !== 1 ? 's' : '') + ' loaded';
        }).catch(function (err) {
            var status = document.getElementById('warnLocStatus');
            if (status) status.textContent = 'Error: ' + err.message;
        });
    }

    function renderWarnLocations(filter) {
        var tbody = document.getElementById('warnLocTableBody');
        if (!tbody) return;
        var html = '';
        for (var i = 0; i < cache.warnLocations.length; i++) {
            var w = cache.warnLocations[i];
            // Filter
            if (filter) {
                var haystack = (w.title + ' ' + w.street + ' ' + w.city + ' ' + w.description).toLowerCase();
                if (haystack.indexOf(filter) === -1) continue;
            }
            var typeLabel = WARN_TYPE_LABELS[parseInt(w.loc_type, 10)] || WARN_TYPE_LABELS[0];
            var created = w._on ? w._on.substring(0, 10) : '';
            var lat = w.lat ? parseFloat(w.lat).toFixed(4) : '';
            var lng = w.lng ? parseFloat(w.lng).toFixed(4) : '';
            html += '<tr data-id="' + w.id + '">'
                + '<td>' + esc(w.id) + '</td>'
                + '<td title="' + esc(typeLabel) + '">' + typeLabel.substring(0, 2) + '</td>'
                + '<td>' + esc(w.title) + '</td>'
                + '<td>' + esc(w.street) + '</td>'
                + '<td>' + esc(w.city) + '</td>'
                + '<td>' + esc(lat) + '</td>'
                + '<td>' + esc(lng) + '</td>'
                + '<td>' + esc(created) + '</td>'
                + '</tr>';
        }
        tbody.innerHTML = html || '<tr><td colspan="8" class="text-center text-body-secondary">No warn locations defined</td></tr>';

        // Bind row clicks
        var rows = tbody.querySelectorAll('tr[data-id]');
        for (var j = 0; j < rows.length; j++) {
            rows[j].addEventListener('click', function () {
                var id = parseInt(this.getAttribute('data-id'), 10);
                var item = findById(cache.warnLocations, id);
                if (item) openWarnLocForm(item);
            });
        }
    }

    function openWarnLocForm(item) {
        var panel = document.getElementById('warnLocEditPanel');
        var deleteBtn = document.getElementById('btnDeleteWarnLoc');

        document.getElementById('warnLocId').value      = item ? item.id : '';
        document.getElementById('warnLocTitle').value    = item ? item.title : '';
        document.getElementById('warnLocType').value     = item ? item.loc_type : '0';
        document.getElementById('warnLocStreet').value   = item ? item.street : '';
        document.getElementById('warnLocCity').value     = item ? item.city : '';
        if (window.TCADStates) {
            window.TCADStates.setValue(document.getElementById('warnLocState'), item ? item.state : '');
        } else {
            document.getElementById('warnLocState').value    = item ? item.state : '';
        }
        document.getElementById('warnLocLat').value      = item ? item.lat : '';
        document.getElementById('warnLocLng').value      = item ? item.lng : '';
        document.getElementById('warnLocRadius').value   = item && item.radius ? item.radius : '500';
        document.getElementById('warnLocDesc').value     = item ? item.description : '';

        if (item) {
            deleteBtn.classList.remove('d-none');
        } else {
            deleteBtn.classList.add('d-none');
        }

        panel.classList.add('show');
        document.getElementById('warnLocTitle').focus();

        // Initialize or refresh the map
        clearWarnMarker();
        if (!warnLocMapReady) {
            initWarnLocMap();
            // Wait for map to be ready, then place marker if editing
            var checkReady = setInterval(function () {
                if (warnLocMapReady) {
                    clearInterval(checkReady);
                    warnLocMap.invalidateSize();
                    showWarnLocOnMap(item);
                }
            }, 100);
        } else {
            // Map exists — just refresh size and show marker
            setTimeout(function () {
                warnLocMap.invalidateSize();
                showWarnLocOnMap(item);
            }, 50);
        }
    }

    function showWarnLocOnMap(item) {
        if (!warnLocMap) return;
        if (item && parseFloat(item.lat) !== 0 && parseFloat(item.lng) !== 0) {
            var lat = parseFloat(item.lat);
            var lng = parseFloat(item.lng);
            placeWarnMarker(lat, lng);
        } else {
            // No coordinates — just show map at defaults (no marker)
            fetch('api/map-config.php')
                .then(function (r) { return r.json(); })
                .then(function (cfg) {
                    warnLocMap.setView([cfg.def_lat || 39.8283, cfg.def_lng || -98.5795], cfg.def_zoom || 5);
                })
                .catch(function () {});
        }
    }

    // ═══════════════════════════════════════════════════════════════
    //  UTILITIES
    // ═══════════════════════════════════════════════════════════════
    function findById(arr, id) {
        for (var i = 0; i < arr.length; i++) {
            if (parseInt(arr[i].id, 10) === id) return arr[i];
        }
        return null;
    }

    function formToObject(form) {
        // Phase 32 hotfix (2026-06-12) — handle radio buttons. Without
        // the `.checked` check, multiple radios with the same name
        // overwrite each other in DOM order and we end up with the
        // value of whichever radio appears LAST, not the one the user
        // picked. Discovered while building the tri-state PAR cadence
        // selector. Checkbox semantics intentionally left alone —
        // existing form handlers explicit-override with .checked.
        var data = {};
        var inputs = form.querySelectorAll('input, select, textarea');
        for (var i = 0; i < inputs.length; i++) {
            var el = inputs[i];
            var name = el.name;
            if (!name) continue;
            if ((el.type || '').toLowerCase() === 'radio') {
                if (el.checked) data[name] = el.value;
                else if (!(name in data)) data[name] = '';
            } else {
                data[name] = el.value;
            }
        }
        return data;
    }

    // ═══════════════════════════════════════════════════════════════
    // ICS POSITIONS
    // ═══════════════════════════════════════════════════════════════

    var icsPositionsData = [];

    function loadIcsPositions() {
        fetchJSON('api/ics-positions.php').then(function (data) {
            icsPositionsData = data.positions || [];
            renderIcsPositions();
            bindIcsEvents();
        }).catch(function (err) {
            showAlert('Failed to load ICS positions: ' + err.message, 'danger');
        });
    }

    function renderIcsPositions() {
        var tbody = document.getElementById('icsPositionsBody');
        if (!tbody) return;

        if (icsPositionsData.length === 0) {
            tbody.innerHTML = '<tr><td colspan="7" class="text-center text-body-secondary py-3">No ICS positions defined.</td></tr>';
            return;
        }

        var categoryColors = {
            Command: 'danger', Operations: 'warning', Planning: 'info',
            Logistics: 'success', Finance: 'secondary'
        };

        var html = '';
        icsPositionsData.forEach(function (p) {
            var catColor = categoryColors[p.category] || 'secondary';
            var qualBadge = parseInt(p.qualified_count) > 0
                ? '<a href="roster.php?ics_position_id=' + p.id + '" class="badge bg-success text-decoration-none" title="View qualified members">' + p.qualified_count + '</a>'
                : '<span class="badge bg-secondary">0</span>';

            html += '<tr>' +
                '<td class="ps-3 fw-semibold font-monospace">' + esc(p.code) + '</td>' +
                '<td>' + esc(p.title) +
                    (p.description ? '<br><small class="text-body-secondary">' + esc(p.description).substring(0, 80) + '</small>' : '') +
                '</td>' +
                '<td><span class="badge bg-' + catColor + ' bg-opacity-75">' + esc(p.category || '--') + '</span></td>' +
                '<td class="text-center">' + (p.nims_typing_level || '--') + '</td>' +
                '<td class="text-center">' + qualBadge + '</td>' +
                '<td class="text-center">' + (p.sort_order || 0) + '</td>' +
                '<td class="text-center">' +
                    '<button class="btn btn-sm btn-link p-0 me-1 ics-edit-btn" data-id="' + p.id + '" title="Edit">' +
                        '<i class="bi bi-pencil text-primary"></i></button>' +
                    '<button class="btn btn-sm btn-link p-0 ics-delete-btn" data-id="' + p.id + '" data-code="' + esc(p.code) + '" title="Delete">' +
                        '<i class="bi bi-trash text-danger"></i></button>' +
                '</td>' +
                '</tr>';
        });

        tbody.innerHTML = html;
    }

    var icsBound = false;
    function bindIcsEvents() {
        if (icsBound) return;
        icsBound = true;

        // Add / update position
        var btnAdd = document.getElementById('btnAddIcsPosition');
        if (btnAdd) {
            btnAdd.addEventListener('click', function () {
                var code = (document.getElementById('icsCode').value || '').trim().toUpperCase();
                var title = (document.getElementById('icsTitle').value || '').trim();
                if (!code || !title) { alert('Code and title are required.'); return; }

                var payload = {
                    id: parseInt(document.getElementById('icsEditId').value) || 0,
                    code: code,
                    title: title,
                    category: document.getElementById('icsCategory').value || null,
                    description: document.getElementById('icsDescription').value || null,
                    nims_typing_level: document.getElementById('icsNimsLevel').value || null,
                    sort_order: parseInt(document.getElementById('icsSortOrder').value) || 0
                };

                payload.csrf_token = csrfToken;
                fetchJSON('api/ics-positions.php', {
                    method: 'POST',
                    body: JSON.stringify(payload)
                }).then(function () {
                    // Reset form
                    document.getElementById('icsCode').value = '';
                    document.getElementById('icsTitle').value = '';
                    document.getElementById('icsCategory').value = '';
                    document.getElementById('icsDescription').value = '';
                    document.getElementById('icsNimsLevel').value = '';
                    document.getElementById('icsSortOrder').value = '0';
                    document.getElementById('icsEditId').value = '0';
                    btnAdd.innerHTML = '<i class="bi bi-plus-lg me-1"></i>Add';

                    loadedPanels['ics-positions'] = false;
                    loadIcsPositions();
                }).catch(function (err) {
                    showAlert('Failed to save: ' + err.message, 'danger');
                });
            });
        }

        // Delegate edit/delete clicks
        var tbody = document.getElementById('icsPositionsBody');
        if (tbody) {
            tbody.addEventListener('click', function (e) {
                var editBtn = e.target.closest('.ics-edit-btn');
                if (editBtn) {
                    var id = parseInt(editBtn.dataset.id);
                    var pos = icsPositionsData.find(function (p) { return p.id == id; });
                    if (pos) {
                        document.getElementById('icsEditId').value = pos.id;
                        document.getElementById('icsCode').value = pos.code;
                        document.getElementById('icsTitle').value = pos.title || '';
                        document.getElementById('icsCategory').value = pos.category || '';
                        document.getElementById('icsDescription').value = pos.description || '';
                        document.getElementById('icsNimsLevel').value = pos.nims_typing_level || '';
                        document.getElementById('icsSortOrder').value = pos.sort_order || 0;
                        document.getElementById('btnAddIcsPosition').innerHTML = '<i class="bi bi-check-lg me-1"></i>Update';
                        document.getElementById('icsCode').focus();
                    }
                    return;
                }

                var delBtn = e.target.closest('.ics-delete-btn');
                if (delBtn) {
                    var code = delBtn.dataset.code;
                    if (!confirm('Delete ICS position "' + code + '"?')) return;
                    fetchJSON('api/ics-positions.php', {
                        method: 'POST',
                        body: JSON.stringify({ action: 'delete', id: parseInt(delBtn.dataset.id), csrf_token: csrfToken })
                    }).then(function () {
                        loadedPanels['ics-positions'] = false;
                        loadIcsPositions();
                    }).catch(function (err) {
                        showAlert('Delete failed: ' + err.message, 'danger');
                    });
                }
            });
        }
    }

    // ═══════════════════════════════════════════════════════════════
    //  TRAINING CONFIG
    // ═══════════════════════════════════════════════════════════════
    function loadTrainingConfig() {
        // Training Catalog CRUD — reads/writes the same certifications
        // table as the Certifications panel; presenting it here gives
        // dispatchers a training-oriented view of the catalog and
        // matches the ICS Positions / Member Statuses CRUD pattern for
        // consistency across settings panels.
        loadTrainCatalog();

        // Load training summary stats
        fetchJSON('api/training.php?summary=1').then(function (data) {
            var statTotal = document.getElementById('trainStatTotal');
            var statHours = document.getElementById('trainStatHours');
            var statTypes = document.getElementById('trainStatTypes');
            var statCompleted = document.getElementById('trainStatCompleted');

            if (statTotal) statTotal.textContent = data.total_records || 0;

            // Calculate total hours from by_type
            var totalHours = 0;
            if (data.by_type) {
                for (var i = 0; i < data.by_type.length; i++) {
                    totalHours += parseFloat(data.by_type[i].total_hours || 0);
                }
            }
            if (statHours) statHours.textContent = totalHours.toFixed(1);
            if (statTypes) statTypes.textContent = data.by_type ? data.by_type.length : 0;

            // Count completed from by_result
            var completedCount = 0;
            if (data.by_result) {
                for (var j = 0; j < data.by_result.length; j++) {
                    if (data.by_result[j].result === 'Completed') {
                        completedCount = parseInt(data.by_result[j].cnt, 10);
                    }
                }
            }
            if (statCompleted) statCompleted.textContent = completedCount;

            // By Type table
            var typeBody = document.getElementById('trainByTypeBody');
            if (typeBody && data.by_type) {
                if (data.by_type.length === 0) {
                    typeBody.innerHTML = '<tr><td colspan="3" class="text-center text-body-secondary py-2">No training records yet.</td></tr>';
                } else {
                    var typeHtml = '';
                    for (var k = 0; k < data.by_type.length; k++) {
                        var t = data.by_type[k];
                        typeHtml += '<tr><td>' + esc(t.training_type) + '</td>' +
                                    '<td class="text-end">' + t.cnt + '</td>' +
                                    '<td class="text-end">' + (parseFloat(t.total_hours || 0).toFixed(1)) + '</td></tr>';
                    }
                    typeBody.innerHTML = typeHtml;
                }
            }

            // Recent Activity table
            var recentBody = document.getElementById('trainRecentBody');
            if (recentBody && data.recent) {
                if (data.recent.length === 0) {
                    recentBody.innerHTML = '<tr><td colspan="4" class="text-center text-body-secondary py-2">No recent activity.</td></tr>';
                } else {
                    var resultColors = { Completed: 'success', 'In Progress': 'info', Incomplete: 'warning', Failed: 'danger' };
                    var rHtml = '';
                    for (var m = 0; m < data.recent.length; m++) {
                        var rec = data.recent[m];
                        rHtml += '<tr><td>' + esc(rec.member_name || 'Unknown') + '</td>' +
                                 '<td>' + esc(rec.training_name) + '</td>' +
                                 '<td>' + (rec.training_date || '—') + '</td>' +
                                 '<td><span class="badge bg-' + (resultColors[rec.result] || 'secondary') + ' bg-opacity-75" style="font-size:0.65rem;">' +
                                     esc(rec.result || '') + '</span></td></tr>';
                    }
                    recentBody.innerHTML = rHtml;
                }
            }
        }).catch(function (err) {
            showAlert('Failed to load training stats: ' + err.message, 'danger');
        });

        // Load FEMA IS courses from certifications
        fetchJSON('api/members.php').then(function (data) {
            var certs = data.certifications || [];
            var femaBody = document.getElementById('femaCourseBody');
            if (!femaBody) return;

            // Filter to FEMA IS category
            var femaCerts = [];
            for (var i = 0; i < certs.length; i++) {
                if (certs[i].category === 'FEMA IS' || (certs[i].fema_course_code && certs[i].fema_course_code !== '')) {
                    femaCerts.push(certs[i]);
                }
            }

            if (femaCerts.length === 0) {
                femaBody.innerHTML = '<tr><td colspan="4" class="text-center text-body-secondary py-2">No FEMA IS courses in system. Run training_nims.sql to seed.</td></tr>';
                return;
            }

            var html = '';
            for (var j = 0; j < femaCerts.length; j++) {
                var fc = femaCerts[j];
                html += '<tr>' +
                    '<td><span class="badge bg-info bg-opacity-75">' + esc(fc.fema_course_code || '') + '</span></td>' +
                    '<td>' + esc(fc.name) + '</td>' +
                    '<td>' + (fc.required === '1' || fc.required === 1 ? '<i class="bi bi-check-circle-fill text-success"></i>' : '') + '</td>' +
                    '<td>' + (fc.refresh_months ? fc.refresh_months + ' mo' : 'Permanent') + '</td>' +
                    '</tr>';
            }
            femaBody.innerHTML = html;
        }).catch(function () {
            var fb = document.getElementById('femaCourseBody');
            if (fb) fb.innerHTML = '<tr><td colspan="4" class="text-center text-body-secondary py-2">Failed to load.</td></tr>';
        });
    }

    // ─── Training Catalog CRUD (shares the certifications table) ───
    // ─── Facility Statuses CRUD (issue #29 followup) ───
    function loadFacilityStatuses() {
        var body = document.getElementById('facStatusesBody');
        if (!body) return;
        apiGet('facility_statuses').then(function (data) {
            var rows = data.rows || [];
            if (!rows.length) {
                body.innerHTML = '<tr><td colspan="6" class="text-center text-body-secondary py-3">No facility statuses yet. Add one above.</td></tr>';
                bindFacStatusForm();
                return;
            }
            var html = '';
            for (var i = 0; i < rows.length; i++) {
                var r = rows[i];
                var bg = r.bg_color || '#6c757d';
                var txt = r.text_color || '#ffffff';
                var preview = '<span class="badge" style="background:' + esc(bg) + ';color:' + esc(txt) + ';">' + esc(r.status_val) + '</span>';
                html += '<tr>' +
                    '<td class="ps-3">' + preview + '</td>' +
                    '<td class="fw-semibold">' + esc(r.status_val) + '</td>' +
                    '<td>' + esc(r.description || '') + '</td>' +
                    '<td>' + esc(r.group || '') + '</td>' +
                    '<td class="text-center">' + parseInt(r.sort || 0, 10) + '</td>' +
                    '<td class="text-center">' +
                        '<button class="btn btn-sm btn-link p-0 me-1 fs-edit-btn" data-id="' + parseInt(r.id, 10) + '" title="Edit"><i class="bi bi-pencil text-primary"></i></button>' +
                        '<button class="btn btn-sm btn-link p-0 fs-del-btn"        data-id="' + parseInt(r.id, 10) + '" data-name="' + esc(r.status_val) + '" title="Delete"><i class="bi bi-trash text-danger"></i></button>' +
                    '</td></tr>';
            }
            body.innerHTML = html;
            var rowsById = {};
            for (var k = 0; k < rows.length; k++) rowsById[rows[k].id] = rows[k];
            var editBtns = body.querySelectorAll('.fs-edit-btn');
            for (var e = 0; e < editBtns.length; e++) {
                (function (btn) {
                    btn.addEventListener('click', function () {
                        var row = rowsById[parseInt(btn.getAttribute('data-id'), 10)];
                        if (!row) return;
                        document.getElementById('facStatusEditId').value = row.id;
                        document.getElementById('facStatusVal').value     = row.status_val || '';
                        document.getElementById('facStatusDesc').value    = row.description || '';
                        document.getElementById('facStatusGroup').value   = row.group || '';
                        document.getElementById('facStatusSort').value    = parseInt(row.sort || 0, 10);
                        document.getElementById('facStatusBg').value      = row.bg_color || '#198754';
                        document.getElementById('facStatusText').value    = row.text_color || '#ffffff';
                        document.getElementById('facStatusAvail').checked   = !!parseInt(row.status_available, 10);
                        document.getElementById('facStatusUnavail').checked = !!parseInt(row.status_unavailable, 10);
                        document.getElementById('btnAddFacStatus').innerHTML = '<i class="bi bi-check-lg me-1"></i>Update';
                    });
                })(editBtns[e]);
            }
            var delBtns = body.querySelectorAll('.fs-del-btn');
            for (var d = 0; d < delBtns.length; d++) {
                (function (btn) {
                    btn.addEventListener('click', function () {
                        if (!confirm('Delete facility status "' + btn.getAttribute('data-name') + '"?')) return;
                        apiDelete('facility_statuses', parseInt(btn.getAttribute('data-id'), 10)).then(function () {
                            loadFacilityStatuses();
                        }).catch(function (err) { showAlert('Delete failed: ' + err.message, 'danger'); });
                    });
                })(delBtns[d]);
            }
            bindFacStatusForm();
        }).catch(function (err) {
            body.innerHTML = '<tr><td colspan="6" class="text-danger py-3">Failed to load: ' + esc(err.message) + '</td></tr>';
        });
    }
    function bindFacStatusForm() {
        var addBtn = document.getElementById('btnAddFacStatus');
        if (!addBtn || addBtn.hasAttribute('data-bound')) return;
        addBtn.setAttribute('data-bound', '1');
        addBtn.addEventListener('click', function () {
            var val = (document.getElementById('facStatusVal').value || '').trim();
            if (!val) { showAlert('Status is required.', 'warning'); return; }
            var payload = {
                id:                  parseInt(document.getElementById('facStatusEditId').value, 10) || 0,
                status_val:          val,
                description:         (document.getElementById('facStatusDesc').value || '').trim(),
                group:               (document.getElementById('facStatusGroup').value || '').trim(),
                status_available:    document.getElementById('facStatusAvail').checked ? 1 : 0,
                status_unavailable:  document.getElementById('facStatusUnavail').checked ? 1 : 0,
                sort:                parseInt(document.getElementById('facStatusSort').value, 10) || 0,
                bg_color:            document.getElementById('facStatusBg').value || '',
                text_color:          document.getElementById('facStatusText').value || ''
            };
            apiPost('facility_statuses', payload).then(function () {
                document.getElementById('facStatusEditId').value = '0';
                document.getElementById('facStatusVal').value    = '';
                document.getElementById('facStatusDesc').value   = '';
                document.getElementById('facStatusGroup').value  = '';
                document.getElementById('facStatusSort').value   = '0';
                document.getElementById('facStatusAvail').checked   = true;
                document.getElementById('facStatusUnavail').checked = false;
                addBtn.innerHTML = '<i class="bi bi-plus-lg me-1"></i>Add';
                loadFacilityStatuses();
            }).catch(function (err) {
                showAlert('Save failed: ' + err.message, 'danger');
            });
        });
    }

    // ─── Signal Codes CRUD (issue #31) ───
    function loadSignalCodes() {
        var body = document.getElementById('sigCodesBody');
        if (!body) return;
        apiGet('signal_codes').then(function (data) {
            var rows = data.rows || [];
            if (!rows.length) {
                body.innerHTML = '<tr><td colspan="5" class="text-center text-body-secondary py-3">No signal codes yet. Add one above.</td></tr>';
                bindSignalCodesForm();
                return;
            }
            var html = '';
            for (var i = 0; i < rows.length; i++) {
                var r = rows[i];
                html += '<tr>' +
                    '<td class="ps-3 font-monospace">' + esc(r.code) + '</td>' +
                    '<td>' + esc(r.description || '') + '</td>' +
                    '<td class="text-center">' + parseInt(r.sort_order || 0, 10) + '</td>' +
                    '<td class="text-center">' + (r.hide === 'y' ? '<i class="bi bi-eye-slash text-body-secondary"></i>' : '') + '</td>' +
                    '<td class="text-center">' +
                        '<button class="btn btn-sm btn-link p-0 me-1 sig-edit-btn" data-id="' + parseInt(r.id, 10) + '" title="Edit"><i class="bi bi-pencil text-primary"></i></button>' +
                        '<button class="btn btn-sm btn-link p-0 sig-del-btn"        data-id="' + parseInt(r.id, 10) + '" data-code="' + esc(r.code) + '" title="Delete"><i class="bi bi-trash text-danger"></i></button>' +
                    '</td></tr>';
            }
            body.innerHTML = html;

            // Rowsmap so edit doesn't need to round-trip a JSON blob
            // through an HTML attribute (M1 in code review 2026-07-03).
            var rowsById = {};
            for (var k = 0; k < rows.length; k++) rowsById[rows[k].id] = rows[k];

            var editBtns = body.querySelectorAll('.sig-edit-btn');
            for (var e = 0; e < editBtns.length; e++) {
                (function (btn) {
                    btn.addEventListener('click', function () {
                        var row = rowsById[parseInt(btn.getAttribute('data-id'), 10)];
                        if (!row) return;
                        document.getElementById('sigEditId').value      = row.id;
                        document.getElementById('sigCode').value        = row.code || '';
                        document.getElementById('sigDescription').value = row.description || '';
                        document.getElementById('sigSort').value        = parseInt(row.sort_order || 0, 10);
                        document.getElementById('sigHide').checked      = (row.hide === 'y');
                        document.getElementById('btnAddSigCode').innerHTML = '<i class="bi bi-check-lg me-1"></i>Update';
                    });
                })(editBtns[e]);
            }

            var delBtns = body.querySelectorAll('.sig-del-btn');
            for (var d = 0; d < delBtns.length; d++) {
                (function (btn) {
                    btn.addEventListener('click', function () {
                        if (!confirm('Delete signal code "' + btn.getAttribute('data-code') + '"?')) return;
                        apiDelete('signal_codes', parseInt(btn.getAttribute('data-id'), 10)).then(function () {
                            loadSignalCodes();
                        }).catch(function (err) {
                            showAlert('Delete failed: ' + err.message, 'danger');
                        });
                    });
                })(delBtns[d]);
            }
            bindSignalCodesForm();
        }).catch(function (err) {
            body.innerHTML = '<tr><td colspan="5" class="text-danger py-3">Failed to load: ' + esc(err.message) + '</td></tr>';
        });
    }
    function bindSignalCodesForm() {
        var addBtn = document.getElementById('btnAddSigCode');
        if (!addBtn || addBtn.hasAttribute('data-bound')) return;
        addBtn.setAttribute('data-bound', '1');
        addBtn.addEventListener('click', function () {
            var code = (document.getElementById('sigCode').value || '').trim();
            if (!code) { showAlert('Code is required.', 'warning'); return; }
            var payload = {
                id:          parseInt(document.getElementById('sigEditId').value, 10) || 0,
                code:        code,
                description: (document.getElementById('sigDescription').value || '').trim(),
                sort_order:  parseInt(document.getElementById('sigSort').value, 10) || 0,
                hide:        document.getElementById('sigHide').checked ? 'y' : 'n'
            };
            apiPost('signal_codes', payload).then(function () {
                document.getElementById('sigEditId').value      = '0';
                document.getElementById('sigCode').value        = '';
                document.getElementById('sigDescription').value = '';
                document.getElementById('sigSort').value        = '0';
                document.getElementById('sigHide').checked      = false;
                addBtn.innerHTML = '<i class="bi bi-plus-lg me-1"></i>Add';
                loadSignalCodes();
            }).catch(function (err) {
                showAlert('Save failed: ' + err.message, 'danger');
            });
        });
    }

    function loadTrainCatalog() {
        var tbody = document.getElementById('trainCatBody');
        if (!tbody) return;
        fetchJSON('api/personnel-config.php?table=certifications').then(function (data) {
            var certs = data.certifications || [];
            if (!certs.length) {
                tbody.innerHTML = '<tr><td colspan="7" class="text-center text-body-secondary py-3">No training entries defined. Add one above.</td></tr>';
                bindTrainCatForm();
                return;
            }
            var catColors = { 'FEMA IS': 'info', 'CPR/Medical': 'danger', 'Radio': 'primary',
                              'HAZMAT': 'warning', 'Weather': 'success', 'Emergency Mgmt': 'primary',
                              'Driving': 'secondary', 'Other': 'secondary' };
            // M1 sweep — id→row map for lookup by edit button data-id.
            var trainCatById = {};
            var html = '';
            for (var i = 0; i < certs.length; i++) {
                var c = certs[i];
                trainCatById[c.id] = c;
                var catBadge = c.category
                    ? '<span class="badge bg-' + (catColors[c.category] || 'secondary') + ' bg-opacity-75" style="font-size:0.65rem">' + esc(c.category) + '</span>'
                    : '<span class="text-body-secondary">—</span>';
                html += '<tr>' +
                    '<td class="ps-3 fw-semibold">' + esc(c.name) +
                        (c.description ? '<br><small class="text-body-secondary">' + esc(c.description) + '</small>' : '') + '</td>' +
                    '<td>' + (c.fema_course_code ? '<code>' + esc(c.fema_course_code) + '</code>' : '<span class="text-body-secondary">—</span>') + '</td>' +
                    '<td>' + catBadge + '</td>' +
                    '<td class="text-center small">' + (c.refresh_months ? c.refresh_months + ' mo' : 'Perm') + '</td>' +
                    '<td class="text-center">' + (parseInt(c.required, 10) ? '<i class="bi bi-check-circle-fill text-success"></i>' : '') + '</td>' +
                    '<td class="text-center"><span class="badge bg-secondary">' + (c.holder_count || 0) + '</span></td>' +
                    '<td class="text-center">' +
                        '<button class="btn btn-sm btn-link p-0 me-1 tc-edit-btn" data-id="' + c.id + '" title="Edit"><i class="bi bi-pencil text-primary"></i></button>' +
                        '<button class="btn btn-sm btn-link p-0 tc-del-btn" data-id="' + c.id + '" data-name="' + esc(c.name) + '" title="Delete"><i class="bi bi-trash text-danger"></i></button>' +
                    '</td></tr>';
            }
            tbody.innerHTML = html;

            var editBtns = tbody.querySelectorAll('.tc-edit-btn');
            for (var e = 0; e < editBtns.length; e++) {
                (function (btn) {
                    btn.addEventListener('click', function () {
                        var row = trainCatById[btn.getAttribute('data-id')];
                        if (!row) return;
                        document.getElementById('trainCatEditId').value = row.id;
                        document.getElementById('trainCatName').value = row.name || '';
                        document.getElementById('trainCatDescription').value = row.description || '';
                        document.getElementById('trainCatCategory').value = row.category || '';
                        document.getElementById('trainCatFemaCode').value = row.fema_course_code || '';
                        document.getElementById('trainCatRefreshMonths').value = row.refresh_months || '';
                        document.getElementById('trainCatRequired').checked = !!parseInt(row.required, 10);
                        document.getElementById('btnAddTrainCat').innerHTML = '<i class="bi bi-check-lg me-1"></i>Update';
                    });
                })(editBtns[e]);
            }

            var delBtns = tbody.querySelectorAll('.tc-del-btn');
            for (var d = 0; d < delBtns.length; d++) {
                (function (btn) {
                    btn.addEventListener('click', function () {
                        if (!confirm('Delete training entry "' + btn.getAttribute('data-name') + '"?')) return;
                        fetchJSON('api/personnel-config.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ action: 'delete_certification', id: parseInt(btn.getAttribute('data-id'), 10) })
                        }).then(function () {
                            loadedPanels['certifications'] = false;
                            loadTrainCatalog();
                        }).catch(function (err) {
                            showAlert('Delete failed: ' + err.message, 'danger');
                        });
                    });
                })(delBtns[d]);
            }

            bindTrainCatForm();
        }).catch(function (err) {
            tbody.innerHTML = '<tr><td colspan="7" class="text-danger py-3">Failed to load: ' + esc(err.message) + '</td></tr>';
        });
    }

    function bindTrainCatForm() {
        var addBtn = document.getElementById('btnAddTrainCat');
        if (!addBtn || addBtn.hasAttribute('data-bound')) return;
        addBtn.setAttribute('data-bound', '1');

        addBtn.addEventListener('click', function () {
            var name = (document.getElementById('trainCatName').value || '').trim();
            if (!name) { showAlert('Course / training name is required.', 'warning'); return; }

            var payload = {
                action: 'save_certification',
                id: parseInt(document.getElementById('trainCatEditId').value, 10) || 0,
                name: name,
                description: (document.getElementById('trainCatDescription').value || '').trim(),
                category: document.getElementById('trainCatCategory').value || '',
                fema_course_code: (document.getElementById('trainCatFemaCode').value || '').trim(),
                refresh_months: parseInt(document.getElementById('trainCatRefreshMonths').value, 10) || null,
                required: document.getElementById('trainCatRequired').checked ? 1 : 0
            };

            fetchJSON('api/personnel-config.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            }).then(function (data) {
                if (data.error) { showAlert(data.error, 'danger'); return; }
                document.getElementById('trainCatEditId').value = '0';
                document.getElementById('trainCatName').value = '';
                document.getElementById('trainCatDescription').value = '';
                document.getElementById('trainCatCategory').value = '';
                document.getElementById('trainCatFemaCode').value = '';
                document.getElementById('trainCatRefreshMonths').value = '';
                document.getElementById('trainCatRequired').checked = false;
                addBtn.innerHTML = '<i class="bi bi-plus-lg me-1"></i>Add';
                loadedPanels['certifications'] = false;
                loadTrainCatalog();
            }).catch(function (err) {
                showAlert('Save failed: ' + err.message, 'danger');
            });
        });
    }

    // ═══════════════════════════════════════════════════════════════
    //  VEHICLE TYPES CONFIG
    // ═══════════════════════════════════════════════════════════════
    function loadVehicleTypes() {
        fetchJSON('api/vehicles.php?types=1').then(function (data) {
            var types = data.types || [];
            var body = document.getElementById('vehTypesBody');
            if (!body) return;

            if (types.length === 0) {
                body.innerHTML = '<tr><td colspan="5" class="text-center text-body-secondary py-3">No vehicle types defined. Add one above.</td></tr>';
                return;
            }

            var html = '';
            for (var i = 0; i < types.length; i++) {
                var t = types[i];
                html += '<tr>' +
                    '<td class="ps-3"><i class="bi ' + esc(t.icon || 'bi-truck') + '"></i></td>' +
                    '<td class="fw-semibold">' + esc(t.name) + '</td>' +
                    '<td class="text-body-secondary">' + esc(t.description || '') + '</td>' +
                    '<td class="text-center">' + (t.sort_order || 0) + '</td>' +
                    '<td class="text-center">' +
                    '<button type="button" class="btn btn-xs btn-outline-warning me-1 edit-veh-type" ' +
                        'data-id="' + t.id + '" data-name="' + esc(t.name) + '" ' +
                        'data-desc="' + esc(t.description || '') + '" data-icon="' + esc(t.icon || '') + '" ' +
                        'data-order="' + (t.sort_order || 0) + '" title="Edit"><i class="bi bi-pencil"></i></button>' +
                    '<button type="button" class="btn btn-xs btn-outline-danger delete-veh-type" ' +
                        'data-id="' + t.id + '" data-name="' + esc(t.name) + '" title="Delete"><i class="bi bi-trash"></i></button>' +
                    '</td></tr>';
            }
            body.innerHTML = html;

            // Edit bindings
            var editBtns = body.querySelectorAll('.edit-veh-type');
            for (var e = 0; e < editBtns.length; e++) {
                (function (btn) {
                    btn.addEventListener('click', function () {
                        document.getElementById('vehTypeEditId').value = btn.getAttribute('data-id');
                        document.getElementById('vehTypeName').value = btn.getAttribute('data-name');
                        document.getElementById('vehTypeDesc').value = btn.getAttribute('data-desc');
                        document.getElementById('vehTypeIcon').value = btn.getAttribute('data-icon');
                        document.getElementById('vehTypeSortOrder').value = btn.getAttribute('data-order');
                        document.getElementById('btnAddVehType').innerHTML = '<i class="bi bi-check-lg me-1"></i>Update';
                    });
                })(editBtns[e]);
            }

            // Delete bindings
            var delBtns = body.querySelectorAll('.delete-veh-type');
            for (var d = 0; d < delBtns.length; d++) {
                (function (btn) {
                    btn.addEventListener('click', function () {
                        if (!confirm('Delete vehicle type "' + btn.getAttribute('data-name') + '"?')) return;
                        fetchJSON('api/vehicles.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ action: 'delete_type', id: parseInt(btn.getAttribute('data-id')) })
                        }).then(function () {
                            loadVehicleTypes();
                        }).catch(function (err) {
                            showAlert('Delete failed: ' + err.message, 'danger');
                        });
                    });
                })(delBtns[d]);
            }
        }).catch(function (err) {
            var body = document.getElementById('vehTypesBody');
            if (body) body.innerHTML = '<tr><td colspan="5" class="text-center text-danger py-3">Failed to load: ' + esc(err.message) + '</td></tr>';
        });

        // Add/Update button binding
        var addBtn = document.getElementById('btnAddVehType');
        if (addBtn && !addBtn._bound) {
            addBtn._bound = true;
            addBtn.addEventListener('click', function () {
                var name = document.getElementById('vehTypeName').value.trim();
                if (!name) { showAlert('Vehicle type name is required.', 'danger'); return; }

                var editId = document.getElementById('vehTypeEditId').value;
                var body = {
                    action: 'save_type',
                    id: editId ? parseInt(editId) : 0,
                    name: name,
                    description: document.getElementById('vehTypeDesc').value.trim(),
                    icon: document.getElementById('vehTypeIcon').value.trim() || 'bi-truck',
                    sort_order: parseInt(document.getElementById('vehTypeSortOrder').value) || 0
                };

                fetchJSON('api/vehicles.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(body)
                }).then(function () {
                    // Reset form
                    document.getElementById('vehTypeEditId').value = '0';
                    document.getElementById('vehTypeName').value = '';
                    document.getElementById('vehTypeDesc').value = '';
                    document.getElementById('vehTypeIcon').value = 'bi-truck';
                    document.getElementById('vehTypeSortOrder').value = '0';
                    addBtn.innerHTML = '<i class="bi bi-plus-lg me-1"></i>Add';
                    loadVehicleTypes();
                }).catch(function (err) {
                    showAlert('Save failed: ' + err.message, 'danger');
                });
            });
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // ═══ Equipment Types Tab (Config)
    // ═══════════════════════════════════════════════════════════════

    var eqTypesLoaded = false;

    function loadEquipmentTypes() {
        if (eqTypesLoaded) return;

        fetch('api/equipment.php?types=1', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                eqTypesLoaded = true;
                var types = data.types || [];
                var tbody = document.getElementById('eqTypesBody');
                if (!tbody) return;

                if (types.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="6" class="text-center text-body-secondary py-3">No equipment types defined.</td></tr>';
                    bindEqTypeForm();
                    return;
                }

                // M1 sweep — id→row map. Previously did bare
                // JSON.stringify(t) into data-row with NO apostrophe
                // escaping — a type description with an apostrophe
                // would truncate the attribute and break the row.
                var eqTypesById = {};
                var html = '';
                for (var i = 0; i < types.length; i++) {
                    var t = types[i];
                    eqTypesById[t.id] = t;
                    html += '<tr data-id="' + t.id + '">'
                        + '<td class="ps-3"><i class="bi ' + esc(t.icon || 'bi-box') + '"></i></td>'
                        + '<td class="fw-semibold">' + esc(t.name) + '</td>'
                        + '<td class="text-body-secondary">' + esc(t.description || '') + '</td>'
                        + '<td class="text-center">' + (t.sort_order || 0) + '</td>'
                        + '<td class="text-center">' + (parseInt(t.requires_checkout, 10) ? '<i class="bi bi-check-circle text-success"></i>' : '<i class="bi bi-dash text-body-secondary"></i>') + '</td>'
                        + '<td class="text-center">'
                        + '<button class="btn btn-sm btn-link text-warning p-0 me-2 eq-type-edit" data-id="' + t.id + '" title="Edit"><i class="bi bi-pencil"></i></button>'
                        + '<button class="btn btn-sm btn-link text-danger p-0 eq-type-del" data-id="' + t.id + '" data-name="' + esc(t.name) + '" title="Delete"><i class="bi bi-trash"></i></button>'
                        + '</td></tr>';
                }
                tbody.innerHTML = html;

                // Bind edit/delete
                tbody.querySelectorAll('.eq-type-edit').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        var row = eqTypesById[btn.getAttribute('data-id')];
                        if (!row) return;
                        document.getElementById('eqTypeEditId').value = row.id;
                        document.getElementById('eqTypeName').value = row.name;
                        document.getElementById('eqTypeDesc').value = row.description || '';
                        document.getElementById('eqTypeIcon').value = row.icon || 'bi-box';
                        document.getElementById('eqTypeSortOrder').value = row.sort_order || 0;
                        document.getElementById('eqTypeCheckout').checked = !!parseInt(row.requires_checkout, 10);
                        var addBtn = document.getElementById('btnAddEqType');
                        if (addBtn) addBtn.innerHTML = '<i class="bi bi-check-lg me-1"></i>Update';
                    });
                });

                tbody.querySelectorAll('.eq-type-del').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        var id = btn.getAttribute('data-id');
                        var name = btn.getAttribute('data-name');
                        if (!confirm('Delete equipment type "' + name + '"?')) return;
                        fetch('api/equipment.php', {
                            method: 'POST',
                            credentials: 'same-origin',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ action: 'delete_type', id: parseInt(id, 10), csrf_token: csrfToken })
                        }).then(function (r) { return r.json(); })
                        .then(function () { eqTypesLoaded = false; loadEquipmentTypes(); })
                        .catch(function (err) { showAlert('Delete failed: ' + err.message, 'danger'); });
                    });
                });

                bindEqTypeForm();
            })
            .catch(function (err) {
                var tbody = document.getElementById('eqTypesBody');
                if (tbody) tbody.innerHTML = '<tr><td colspan="6" class="text-danger py-3">Failed to load: ' + esc(err.message) + '</td></tr>';
            });
    }

    function bindEqTypeForm() {
        var addBtn = document.getElementById('btnAddEqType');
        if (!addBtn || addBtn.hasAttribute('data-bound')) return;
        addBtn.setAttribute('data-bound', '1');

        addBtn.addEventListener('click', function () {
            var name = (document.getElementById('eqTypeName').value || '').trim();
            if (!name) { showAlert('Type name is required.', 'warning'); return; }

            var payload = {
                action: 'save_type',
                id: parseInt(document.getElementById('eqTypeEditId').value, 10) || 0,
                name: name,
                description: (document.getElementById('eqTypeDesc').value || '').trim(),
                icon: (document.getElementById('eqTypeIcon').value || 'bi-box').trim(),
                sort_order: parseInt(document.getElementById('eqTypeSortOrder').value, 10) || 0,
                requires_checkout: document.getElementById('eqTypeCheckout').checked ? 1 : 0,
                csrf_token: csrfToken
            };

            fetch('api/equipment.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            }).then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.error) { showAlert(data.error, 'danger'); return; }
                eqTypesLoaded = false;
                document.getElementById('eqTypeEditId').value = '0';
                document.getElementById('eqTypeName').value = '';
                document.getElementById('eqTypeDesc').value = '';
                document.getElementById('eqTypeIcon').value = 'bi-box';
                document.getElementById('eqTypeSortOrder').value = '0';
                document.getElementById('eqTypeCheckout').checked = true;
                addBtn.innerHTML = '<i class="bi bi-plus-lg me-1"></i>Add';
                loadEquipmentTypes();
            }).catch(function (err) {
                showAlert('Save failed: ' + err.message, 'danger');
            });
        });
    }

    // ═══════════════════════════════════════════════════════════════
    //  CERTIFICATIONS CONFIG
    // ═══════════════════════════════════════════════════════════════

    function loadCertifications() {
        fetchJSON('api/personnel-config.php?table=certifications').then(function (data) {
            var certs = data.certifications || [];
            var tbody = document.getElementById('certTableBody');
            if (!tbody) return;

            if (certs.length === 0) {
                tbody.innerHTML = '<tr><td colspan="7" class="text-center text-body-secondary py-3">No certifications defined. Add one above.</td></tr>';
                bindCertForm();
                return;
            }

            var catColors = { 'FEMA IS': 'info', 'CPR/Medical': 'danger', 'Radio': 'primary', 'HAZMAT': 'warning', 'Weather': 'success', 'Other': 'secondary' };
            // M1 sweep — id→row map.
            var certsById = {};
            var html = '';
            for (var i = 0; i < certs.length; i++) {
                var c = certs[i];
                certsById[c.id] = c;
                var catBadge = c.category ? '<span class="badge bg-' + (catColors[c.category] || 'secondary') + ' bg-opacity-75" style="font-size:0.65rem">' + esc(c.category) + '</span>' : '—';
                html += '<tr>' +
                    '<td class="ps-3 fw-semibold">' + esc(c.name) +
                        (c.description ? '<br><small class="text-body-secondary">' + esc(c.description) + '</small>' : '') + '</td>' +
                    '<td>' + catBadge + '</td>' +
                    '<td>' + (c.fema_course_code ? '<code>' + esc(c.fema_course_code) + '</code>' : '—') + '</td>' +
                    '<td class="text-center">' + (c.refresh_months ? c.refresh_months + ' mo' : 'Perm') + '</td>' +
                    '<td class="text-center">' + (parseInt(c.required, 10) ? '<i class="bi bi-check-circle-fill text-success"></i>' : '') + '</td>' +
                    '<td class="text-center"><span class="badge bg-secondary">' + (c.holder_count || 0) + '</span></td>' +
                    '<td class="text-center">' +
                        '<button class="btn btn-sm btn-link p-0 me-1 cert-edit-btn" data-id="' + c.id + '" title="Edit"><i class="bi bi-pencil text-primary"></i></button>' +
                        '<button class="btn btn-sm btn-link p-0 cert-del-btn" data-id="' + c.id + '" data-name="' + esc(c.name) + '" title="Delete"><i class="bi bi-trash text-danger"></i></button>' +
                    '</td></tr>';
            }
            tbody.innerHTML = html;

            // Bind edit buttons
            var editBtns = tbody.querySelectorAll('.cert-edit-btn');
            for (var e = 0; e < editBtns.length; e++) {
                (function (btn) {
                    btn.addEventListener('click', function () {
                        var row = certsById[btn.getAttribute('data-id')];
                        if (!row) return;
                        document.getElementById('certEditId').value = row.id;
                        document.getElementById('certName').value = row.name || '';
                        document.getElementById('certDescription').value = row.description || '';
                        document.getElementById('certCategory').value = row.category || '';
                        document.getElementById('certFemaCode').value = row.fema_course_code || '';
                        document.getElementById('certRefreshMonths').value = row.refresh_months || '';
                        document.getElementById('certRequired').checked = !!parseInt(row.required, 10);
                        document.getElementById('certNimsType').value = row.nims_credential_type || '';
                        document.getElementById('btnAddCert').innerHTML = '<i class="bi bi-check-lg me-1"></i>Update';
                    });
                })(editBtns[e]);
            }

            // Bind delete buttons
            var delBtns = tbody.querySelectorAll('.cert-del-btn');
            for (var d = 0; d < delBtns.length; d++) {
                (function (btn) {
                    btn.addEventListener('click', function () {
                        if (!confirm('Delete certification "' + btn.getAttribute('data-name') + '"?')) return;
                        fetchJSON('api/personnel-config.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ action: 'delete_certification', id: parseInt(btn.getAttribute('data-id'), 10) })
                        }).then(function () {
                            loadedPanels['certifications'] = false;
                            loadCertifications();
                        }).catch(function (err) {
                            showAlert('Delete failed: ' + err.message, 'danger');
                        });
                    });
                })(delBtns[d]);
            }

            bindCertForm();
        }).catch(function (err) {
            var tbody = document.getElementById('certTableBody');
            if (tbody) tbody.innerHTML = '<tr><td colspan="7" class="text-danger py-3">Failed to load: ' + esc(err.message) + '</td></tr>';
        });
    }

    function bindCertForm() {
        var addBtn = document.getElementById('btnAddCert');
        if (!addBtn || addBtn.hasAttribute('data-bound')) return;
        addBtn.setAttribute('data-bound', '1');

        addBtn.addEventListener('click', function () {
            var name = (document.getElementById('certName').value || '').trim();
            if (!name) { showAlert('Certification name is required.', 'warning'); return; }

            var payload = {
                action: 'save_certification',
                id: parseInt(document.getElementById('certEditId').value, 10) || 0,
                name: name,
                description: (document.getElementById('certDescription').value || '').trim(),
                category: document.getElementById('certCategory').value || '',
                fema_course_code: (document.getElementById('certFemaCode').value || '').trim(),
                refresh_months: parseInt(document.getElementById('certRefreshMonths').value, 10) || null,
                required: document.getElementById('certRequired').checked ? 1 : 0,
                nims_credential_type: (document.getElementById('certNimsType').value || '').trim()
            };

            fetchJSON('api/personnel-config.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            }).then(function (data) {
                if (data.error) { showAlert(data.error, 'danger'); return; }
                // Reset form
                document.getElementById('certEditId').value = '0';
                document.getElementById('certName').value = '';
                document.getElementById('certDescription').value = '';
                document.getElementById('certCategory').value = '';
                document.getElementById('certFemaCode').value = '';
                document.getElementById('certRefreshMonths').value = '';
                document.getElementById('certRequired').checked = false;
                document.getElementById('certNimsType').value = '';
                addBtn.innerHTML = '<i class="bi bi-plus-lg me-1"></i>Add';
                loadedPanels['certifications'] = false;
                loadCertifications();
            }).catch(function (err) {
                showAlert('Save failed: ' + err.message, 'danger');
            });
        });
    }

    // ═══════════════════════════════════════════════════════════════
    //  MEMBER TYPES CONFIG
    // ═══════════════════════════════════════════════════════════════

    function loadMemberTypes() {
        fetchJSON('api/personnel-config.php?table=member_types').then(function (data) {
            var types = data.types || [];
            var tbody = document.getElementById('memberTypeBody');
            if (!tbody) return;

            if (types.length === 0) {
                tbody.innerHTML = '<tr><td colspan="5" class="text-center text-body-secondary py-3">No member types defined. Add one above.</td></tr>';
                bindMemberTypeForm();
                return;
            }

            // M1 sweep — id→row map.
            var memberTypesById = {};
            var html = '';
            for (var i = 0; i < types.length; i++) {
                var t = types[i];
                memberTypesById[t.id] = t;
                html += '<tr>' +
                    '<td class="ps-3"><span class="badge" style="color:' + esc(t.color || 'Black') + ';background:' + esc(t.background || 'White') + ';border:1px solid var(--bs-border-color)">' + esc(t.name) + '</span></td>' +
                    '<td class="fw-semibold">' + esc(t.name) + '</td>' +
                    '<td class="text-body-secondary">' + esc(t.description || '') + '</td>' +
                    '<td class="text-center"><span class="badge bg-secondary">' + (t.member_count || 0) + '</span></td>' +
                    '<td class="text-center">' +
                        '<button class="btn btn-sm btn-link p-0 me-1 mt-edit-btn" data-id="' + t.id + '" title="Edit"><i class="bi bi-pencil text-primary"></i></button>' +
                        '<button class="btn btn-sm btn-link p-0 mt-del-btn" data-id="' + t.id + '" data-name="' + esc(t.name) + '" title="Delete"><i class="bi bi-trash text-danger"></i></button>' +
                    '</td></tr>';
            }
            tbody.innerHTML = html;

            // Bind edit
            var editBtns = tbody.querySelectorAll('.mt-edit-btn');
            for (var e = 0; e < editBtns.length; e++) {
                (function (btn) {
                    btn.addEventListener('click', function () {
                        var row = memberTypesById[btn.getAttribute('data-id')];
                        if (!row) return;
                        document.getElementById('mtEditId').value = row.id;
                        document.getElementById('mtName').value = row.name || '';
                        document.getElementById('mtDescription').value = row.description || '';
                        document.getElementById('mtColor').value = row.color || 'Black';
                        document.getElementById('mtBackground').value = row.background || 'White';
                        document.getElementById('btnAddMemberType').innerHTML = '<i class="bi bi-check-lg me-1"></i>Update';
                    });
                })(editBtns[e]);
            }

            // Bind delete
            var delBtns = tbody.querySelectorAll('.mt-del-btn');
            for (var d = 0; d < delBtns.length; d++) {
                (function (btn) {
                    btn.addEventListener('click', function () {
                        if (!confirm('Delete member type "' + btn.getAttribute('data-name') + '"?')) return;
                        fetchJSON('api/personnel-config.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ action: 'delete_member_type', id: parseInt(btn.getAttribute('data-id'), 10) })
                        }).then(function () {
                            loadedPanels['member-types'] = false;
                            loadMemberTypes();
                        }).catch(function (err) {
                            showAlert('Delete failed: ' + err.message, 'danger');
                        });
                    });
                })(delBtns[d]);
            }

            bindMemberTypeForm();
        }).catch(function (err) {
            var tbody = document.getElementById('memberTypeBody');
            if (tbody) tbody.innerHTML = '<tr><td colspan="5" class="text-danger py-3">Failed to load: ' + esc(err.message) + '</td></tr>';
        });
    }

    function bindMemberTypeForm() {
        var addBtn = document.getElementById('btnAddMemberType');
        if (!addBtn || addBtn.hasAttribute('data-bound')) return;
        addBtn.setAttribute('data-bound', '1');

        addBtn.addEventListener('click', function () {
            var name = (document.getElementById('mtName').value || '').trim();
            if (!name) { showAlert('Type name is required.', 'warning'); return; }

            var payload = {
                action: 'save_member_type',
                id: parseInt(document.getElementById('mtEditId').value, 10) || 0,
                name: name,
                description: (document.getElementById('mtDescription').value || '').trim(),
                color: (document.getElementById('mtColor').value || 'Black').trim(),
                background: (document.getElementById('mtBackground').value || 'White').trim()
            };

            fetchJSON('api/personnel-config.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            }).then(function (data) {
                if (data.error) { showAlert(data.error, 'danger'); return; }
                document.getElementById('mtEditId').value = '0';
                document.getElementById('mtName').value = '';
                document.getElementById('mtDescription').value = '';
                document.getElementById('mtColor').value = 'Black';
                document.getElementById('mtBackground').value = 'White';
                addBtn.innerHTML = '<i class="bi bi-plus-lg me-1"></i>Add';
                loadedPanels['member-types'] = false;
                loadMemberTypes();
            }).catch(function (err) {
                showAlert('Save failed: ' + err.message, 'danger');
            });
        });
    }

    // ═══════════════════════════════════════════════════════════════
    //  MEMBER STATUSES CONFIG
    // ═══════════════════════════════════════════════════════════════

    function loadMemberStatuses() {
        fetchJSON('api/personnel-config.php?table=member_statuses').then(function (data) {
            var statuses = data.statuses || [];
            var tbody = document.getElementById('memberStatusBody');
            if (!tbody) return;

            if (statuses.length === 0) {
                tbody.innerHTML = '<tr><td colspan="5" class="text-center text-body-secondary py-3">No member statuses defined. Add one above.</td></tr>';
                bindMemberStatusForm();
                return;
            }

            // M1 sweep — id→row map.
            var memberStatusesById = {};
            var html = '';
            for (var i = 0; i < statuses.length; i++) {
                var s = statuses[i];
                memberStatusesById[s.id] = s;
                html += '<tr>' +
                    '<td class="ps-3"><span class="badge" style="color:' + esc(s.color || 'Black') + ';background:' + esc(s.background || 'White') + ';border:1px solid var(--bs-border-color)">' + esc(s.status_val) + '</span></td>' +
                    '<td class="fw-semibold">' + esc(s.status_val) + '</td>' +
                    '<td class="text-body-secondary">' + esc(s.description || '') + '</td>' +
                    '<td class="text-center"><span class="badge bg-secondary">' + (s.member_count || 0) + '</span></td>' +
                    '<td class="text-center">' +
                        '<button class="btn btn-sm btn-link p-0 me-1 ms-edit-btn" data-id="' + s.id + '" title="Edit"><i class="bi bi-pencil text-primary"></i></button>' +
                        '<button class="btn btn-sm btn-link p-0 ms-del-btn" data-id="' + s.id + '" data-name="' + esc(s.status_val) + '" title="Delete"><i class="bi bi-trash text-danger"></i></button>' +
                    '</td></tr>';
            }
            tbody.innerHTML = html;

            // Bind edit
            var editBtns = tbody.querySelectorAll('.ms-edit-btn');
            for (var e = 0; e < editBtns.length; e++) {
                (function (btn) {
                    btn.addEventListener('click', function () {
                        var row = memberStatusesById[btn.getAttribute('data-id')];
                        if (!row) return;
                        document.getElementById('msEditId').value = row.id;
                        document.getElementById('msName').value = row.status_val || '';
                        document.getElementById('msDescription').value = row.description || '';
                        document.getElementById('msColor').value = row.color || 'Black';
                        document.getElementById('msBackground').value = row.background || 'White';
                        document.getElementById('btnAddMemberStatus').innerHTML = '<i class="bi bi-check-lg me-1"></i>Update';
                    });
                })(editBtns[e]);
            }

            // Bind delete
            var delBtns = tbody.querySelectorAll('.ms-del-btn');
            for (var d = 0; d < delBtns.length; d++) {
                (function (btn) {
                    btn.addEventListener('click', function () {
                        if (!confirm('Delete member status "' + btn.getAttribute('data-name') + '"?')) return;
                        fetchJSON('api/personnel-config.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ action: 'delete_member_status', id: parseInt(btn.getAttribute('data-id'), 10) })
                        }).then(function () {
                            loadedPanels['member-statuses'] = false;
                            loadMemberStatuses();
                        }).catch(function (err) {
                            showAlert('Delete failed: ' + err.message, 'danger');
                        });
                    });
                })(delBtns[d]);
            }

            bindMemberStatusForm();
        }).catch(function (err) {
            var tbody = document.getElementById('memberStatusBody');
            if (tbody) tbody.innerHTML = '<tr><td colspan="5" class="text-danger py-3">Failed to load: ' + esc(err.message) + '</td></tr>';
        });
    }

    function bindMemberStatusForm() {
        var addBtn = document.getElementById('btnAddMemberStatus');
        if (!addBtn || addBtn.hasAttribute('data-bound')) return;
        addBtn.setAttribute('data-bound', '1');

        addBtn.addEventListener('click', function () {
            var name = (document.getElementById('msName').value || '').trim();
            if (!name) { showAlert('Status name is required.', 'warning'); return; }

            var payload = {
                action: 'save_member_status',
                id: parseInt(document.getElementById('msEditId').value, 10) || 0,
                status_val: name,
                description: (document.getElementById('msDescription').value || '').trim(),
                color: (document.getElementById('msColor').value || 'Black').trim(),
                background: (document.getElementById('msBackground').value || 'White').trim()
            };

            fetchJSON('api/personnel-config.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            }).then(function (data) {
                if (data.error) { showAlert(data.error, 'danger'); return; }
                document.getElementById('msEditId').value = '0';
                document.getElementById('msName').value = '';
                document.getElementById('msDescription').value = '';
                document.getElementById('msColor').value = 'Black';
                document.getElementById('msBackground').value = 'White';
                addBtn.innerHTML = '<i class="bi bi-plus-lg me-1"></i>Add';
                loadedPanels['member-statuses'] = false;
                loadMemberStatuses();
            }).catch(function (err) {
                showAlert('Save failed: ' + err.message, 'danger');
            });
        });
    }

    // ═══════════════════════════════════════════════════════════════
    //  TEAMS CONFIG (read-only summary)
    // ═══════════════════════════════════════════════════════════════

    function loadTeamsConfig() {
        fetchJSON('api/personnel-config.php?table=teams_summary').then(function (data) {
            var teams = data.teams || [];
            var tbody = document.getElementById('teamsConfigBody');
            if (!tbody) return;

            if (teams.length === 0) {
                tbody.innerHTML = '<tr><td colspan="4" class="text-center text-body-secondary py-3">No teams defined. Create teams on the Teams page.</td></tr>';
                return;
            }

            var html = '';
            for (var i = 0; i < teams.length; i++) {
                var t = teams[i];
                html += '<tr>' +
                    '<td class="ps-3 fw-semibold">' + esc(t.name || '') + '</td>' +
                    '<td class="text-body-secondary">' + esc(t.description || '') + '</td>' +
                    '<td class="text-center"><span class="badge bg-primary">' + (t.member_count || 0) + '</span></td>' +
                    '<td>' + (t.nims_resource_type ? esc(t.nims_resource_type) + (t.nims_typing_level ? ' (Type ' + t.nims_typing_level + ')' : '') : '—') + '</td>' +
                    '</tr>';
            }
            tbody.innerHTML = html;
        }).catch(function (err) {
            var tbody = document.getElementById('teamsConfigBody');
            if (tbody) tbody.innerHTML = '<tr><td colspan="4" class="text-danger py-3">Failed to load: ' + esc(err.message) + '</td></tr>';
        });
    }

    // ═══════════════════════════════════════════════════════════════
    //  MEMBERS SUMMARY (overview panel)
    // ═══════════════════════════════════════════════════════════════

    function loadMembersSummary() {
        fetchJSON('api/personnel-config.php?table=members_summary').then(function (data) {
            var totalEl = document.getElementById('memStatTotal');
            var availEl = document.getElementById('memStatAvail');
            var teamsEl = document.getElementById('memStatTeams');
            var certsEl = document.getElementById('memStatCerts');

            if (totalEl) totalEl.textContent = data.total_members || 0;
            if (availEl) availEl.textContent = data.available || 0;
            if (teamsEl) teamsEl.textContent = data.team_count || 0;
            if (certsEl) certsEl.textContent = data.cert_count || 0;

            // By Type table
            var typeBody = document.getElementById('memByTypeBody');
            if (typeBody) {
                var byType = data.by_type || [];
                if (byType.length === 0) {
                    typeBody.innerHTML = '<tr><td colspan="2" class="text-center text-body-secondary py-2">No data</td></tr>';
                } else {
                    var html = '';
                    for (var i = 0; i < byType.length; i++) {
                        var t = byType[i];
                        html += '<tr><td>' + esc(t.name || 'Unassigned') + '</td><td class="text-end">' + (t.cnt || 0) + '</td></tr>';
                    }
                    typeBody.innerHTML = html;
                }
            }

            // By Status table
            var statusBody = document.getElementById('memByStatusBody');
            if (statusBody) {
                var byStatus = data.by_status || [];
                if (byStatus.length === 0) {
                    statusBody.innerHTML = '<tr><td colspan="2" class="text-center text-body-secondary py-2">No data</td></tr>';
                } else {
                    var sHtml = '';
                    for (var j = 0; j < byStatus.length; j++) {
                        var s = byStatus[j];
                        sHtml += '<tr><td>' + esc(s.name || 'Unassigned') + '</td><td class="text-end">' + (s.cnt || 0) + '</td></tr>';
                    }
                    statusBody.innerHTML = sHtml;
                }
            }
        }).catch(function (err) {
            showAlert('Failed to load members summary: ' + err.message, 'danger');
        });
    }

    // ── Organizations Panel ─────────────────────────────────────────

    var orgsData = [];

    /*
     * Phase 99j-3 (Billy beta 2026-06-29) — populate the Parent
     * dropdown from the cached orgsData. excludeId, when provided,
     * is removed from the list (the org being edited can't be its
     * own parent — quick cycle guard; deep cycle prevention is on
     * the server). Result is sorted by name for usability.
     */
    function populateOrgParentDropdown(excludeId) {
        var sel = document.getElementById('orgParentId');
        if (!sel) return;
        var html = '<option value="">— None (top-level) —</option>';
        var sorted = (orgsData || []).slice().sort(function (a, b) {
            return (a.name || '').localeCompare(b.name || '');
        });
        for (var i = 0; i < sorted.length; i++) {
            var o = sorted[i];
            if (excludeId && parseInt(o.id, 10) === parseInt(excludeId, 10)) continue;
            html += '<option value="' + o.id + '">' + escapeHtml(o.name || '') + '</option>';
        }
        sel.innerHTML = html;
    }

    function bindOrganizationsPanel() {
        var section = document.getElementById('panel-organizations');
        if (!section) return;

        loadOrganizations();

        var addBtn = document.getElementById('orgAddBtn');
        var form   = document.getElementById('orgForm');
        var saveBtn = document.getElementById('orgSaveBtn');
        var cancelBtn = document.getElementById('orgCancelBtn');

        if (addBtn) {
            addBtn.addEventListener('click', function () {
                document.getElementById('orgEditId').value = '0';
                document.getElementById('orgName').value = '';
                document.getElementById('orgShortName').value = '';
                document.getElementById('orgType').value = '';
                document.getElementById('orgDescription').value = '';
                document.getElementById('orgContactName').value = '';
                document.getElementById('orgContactEmail').value = '';
                document.getElementById('orgContactPhone').value = '';
                document.getElementById('orgSortOrder').value = '0';
                document.getElementById('orgActive').checked = true;
                // Phase 99j-3 (Billy beta 2026-06-29): clear parent on add
                var parentSel = document.getElementById('orgParentId');
                if (parentSel) { populateOrgParentDropdown(0); parentSel.value = ''; }
                form.style.display = '';
                document.getElementById('orgName').focus();
            });
        }

        if (cancelBtn) {
            cancelBtn.addEventListener('click', function () {
                form.style.display = 'none';
            });
        }

        if (saveBtn) {
            saveBtn.addEventListener('click', function () {
                var name = (document.getElementById('orgName').value || '').trim();
                if (!name) { showAlert('Organization name is required.', 'warning'); return; }

                var parentSel = document.getElementById('orgParentId');
                var parentVal = parentSel && parentSel.value ? parseInt(parentSel.value, 10) : null;
                var editId = parseInt(document.getElementById('orgEditId').value) || 0;
                // Phase 99j-3 client-side guard: can't be your own parent
                if (parentVal && parentVal === editId) {
                    showAlert('An organization cannot be its own parent.', 'warning');
                    return;
                }
                var payload = {
                    action: 'save_org',
                    id: editId,
                    name: name,
                    short_name: (document.getElementById('orgShortName').value || '').trim(),
                    org_type: document.getElementById('orgType').value,
                    description: (document.getElementById('orgDescription').value || '').trim(),
                    contact_name: (document.getElementById('orgContactName').value || '').trim(),
                    contact_email: (document.getElementById('orgContactEmail').value || '').trim(),
                    contact_phone: (document.getElementById('orgContactPhone').value || '').trim(),
                    sort_order: parseInt(document.getElementById('orgSortOrder').value) || 0,
                    active: document.getElementById('orgActive').checked ? 1 : 0,
                    parent_org_id: parentVal   // null = top-level
                };

                fetch('api/organizations.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.error) { showAlert(data.error, 'danger'); return; }
                    form.style.display = 'none';
                    showAlert('Organization saved.', 'success');
                    loadOrganizations();
                })
                .catch(function (err) { showAlert('Save failed: ' + err.message, 'danger'); });
            });
        }

        // Delegate edit/delete clicks
        var tbody = document.getElementById('orgTableBody');
        if (tbody) {
            tbody.addEventListener('click', function (e) {
                var editBtn = e.target.closest('.org-edit-btn');
                var delBtn  = e.target.closest('.org-delete-btn');

                if (editBtn) {
                    var id = parseInt(editBtn.getAttribute('data-id'));
                    var org = null;
                    for (var i = 0; i < orgsData.length; i++) {
                        if (parseInt(orgsData[i].id) === id) { org = orgsData[i]; break; }
                    }
                    if (!org) return;

                    document.getElementById('orgEditId').value = org.id;
                    document.getElementById('orgName').value = org.name || '';
                    document.getElementById('orgShortName').value = org.short_name || '';
                    document.getElementById('orgType').value = org.org_type || '';
                    document.getElementById('orgDescription').value = org.description || '';
                    document.getElementById('orgContactName').value = org.contact_name || '';
                    document.getElementById('orgContactEmail').value = org.contact_email || '';
                    document.getElementById('orgContactPhone').value = org.contact_phone || '';
                    document.getElementById('orgSortOrder').value = org.sort_order || 0;
                    document.getElementById('orgActive').checked = parseInt(org.active) === 1;
                    // Phase 99j-3 (Billy beta 2026-06-29): repopulate the
                    // parent dropdown excluding this org (cycle prevention)
                    // and select the current parent_org_id if any.
                    populateOrgParentDropdown(org.id);
                    var pSel = document.getElementById('orgParentId');
                    if (pSel) pSel.value = org.parent_org_id ? String(org.parent_org_id) : '';
                    form.style.display = '';
                    document.getElementById('orgName').focus();
                }

                if (delBtn) {
                    var dId = delBtn.getAttribute('data-id');
                    var dName = delBtn.getAttribute('data-name');
                    if (!confirm('Delete organization "' + dName + '"?')) return;

                    fetch('api/organizations.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'delete_org', id: parseInt(dId) })
                    })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (data.error) { showAlert(data.error, 'danger'); return; }
                        showAlert('Organization deleted.', 'success');
                        loadOrganizations();
                    })
                    .catch(function (err) { showAlert('Delete failed: ' + err.message, 'danger'); });
                }
            });
        }
    }

    function loadOrganizations() {
        fetch('api/organizations.php?action=list')
            .then(function (r) { return r.json(); })
            .then(function (data) {
                orgsData = data.organizations || [];
                renderOrganizations();
            })
            .catch(function (err) {
                var tbody = document.getElementById('orgTableBody');
                if (tbody) tbody.innerHTML = '<tr><td colspan="7" class="text-danger py-3">Failed to load: ' + esc(err.message) + '</td></tr>';
            });
    }

    function renderOrganizations() {
        var tbody = document.getElementById('orgTableBody');
        if (!tbody) return;

        if (orgsData.length === 0) {
            tbody.innerHTML = '<tr><td colspan="7" class="text-center text-body-secondary py-3">No organizations defined.</td></tr>';
            return;
        }

        var typeColors = {
            RACES: 'primary', ARES: 'info', CERT: 'warning', Fire: 'danger',
            EMS: 'success', 'Campus PD': 'secondary', 'Radio Club': 'info', SAR: 'warning', General: 'secondary'
        };

        var html = '';
        orgsData.forEach(function (o) {
            var tColor = typeColors[o.org_type] || 'secondary';
            var activeIcon = parseInt(o.active) === 1
                ? '<i class="bi bi-check-circle-fill text-success"></i>'
                : '<i class="bi bi-x-circle-fill text-danger"></i>';
            var mc = parseInt(o.member_count, 10) || 0;
            var memberBadge = '<span class="badge rounded-pill ' + (mc > 0 ? 'bg-success' : 'bg-secondary') + '" style="min-width:2.2em;">' + mc + '</span>';

            html += '<tr>' +
                '<td class="ps-3 fw-semibold">' + esc(o.name) +
                    (o.description ? '<br><small class="text-body-secondary">' + esc((o.description || '').substring(0, 80)) + '</small>' : '') +
                '</td>' +
                '<td><code>' + esc(o.short_name || '--') + '</code></td>' +
                '<td><span class="badge bg-' + tColor + ' bg-opacity-75">' + esc(o.org_type || '--') + '</span></td>' +
                '<td class="text-center">' + memberBadge + '</td>' +
                '<td class="text-center">' + activeIcon + '</td>' +
                '<td class="text-center">' + (o.sort_order || 0) + '</td>' +
                '<td class="text-center">' +
                    '<button class="btn btn-sm btn-link p-0 me-1 org-edit-btn" data-id="' + o.id + '" title="Edit">' +
                        '<i class="bi bi-pencil text-primary"></i></button>' +
                    (parseInt(o.id) !== 1 ?
                    '<button class="btn btn-sm btn-link p-0 org-delete-btn" data-id="' + o.id + '" data-name="' + esc(o.name) + '" title="Delete">' +
                        '<i class="bi bi-trash text-danger"></i></button>' : '') +
                '</td>' +
                '</tr>';
        });

        tbody.innerHTML = html;
    }

    // ── Comm / Location Modes Panel ─────────────────────────────────

    var commModesData = [];

    function bindCommModesPanel() {
        var section = document.getElementById('panel-comm-modes');
        if (!section) return;

        loadCommModes();

        var addBtn    = document.getElementById('commModeAddBtn');
        var form      = document.getElementById('commModeForm');
        var saveBtn   = document.getElementById('commModeSaveBtn');
        var cancelBtn = document.getElementById('commModeCancelBtn');
        var addFieldBtn = document.getElementById('commModeAddFieldBtn');

        if (addBtn) {
            addBtn.addEventListener('click', function () {
                document.getElementById('commModeEditId').value = '0';
                document.getElementById('commModeName').value = '';
                document.getElementById('commModeCode').value = '';
                document.getElementById('commModeIcon').value = '';
                document.getElementById('commModeColor').value = '#6c757d';
                document.getElementById('commModeSortOrder').value = '0';
                document.getElementById('commModeEnabled').checked = true;
                document.getElementById('commModeLookupUrl').value = '';
                document.getElementById('commModeNotes').value = '';
                document.getElementById('commModeFieldsBody').innerHTML = '';
                addFieldRow(); // Start with one empty field
                form.style.display = '';
                document.getElementById('commModeName').focus();
            });
        }

        if (cancelBtn) {
            cancelBtn.addEventListener('click', function () {
                form.style.display = 'none';
            });
        }

        if (addFieldBtn) {
            addFieldBtn.addEventListener('click', function () {
                addFieldRow();
            });
        }

        if (saveBtn) {
            saveBtn.addEventListener('click', function () {
                var name = (document.getElementById('commModeName').value || '').trim();
                var code = (document.getElementById('commModeCode').value || '').trim();
                if (!name || !code) { showAlert('Name and code are required.', 'warning'); return; }

                // Collect field definitions from the table
                var fieldRows = document.getElementById('commModeFieldsBody').querySelectorAll('tr');
                var fields = [];
                for (var i = 0; i < fieldRows.length; i++) {
                    var inputs = fieldRows[i].querySelectorAll('input, select');
                    var key = (inputs[0].value || '').trim();
                    var label = (inputs[1].value || '').trim();
                    if (!key || !label) continue;
                    fields.push({
                        key: key.toLowerCase().replace(/[^a-z0-9_]/g, '_'),
                        label: label,
                        type: inputs[2].value || 'text',
                        placeholder: (inputs[3].value || '').trim(),
                        maxlength: parseInt(inputs[4].value) || 0,
                        required: inputs[5].checked
                    });
                }

                var payload = {
                    action: 'save_mode',
                    id: parseInt(document.getElementById('commModeEditId').value) || 0,
                    name: name,
                    code: code,
                    icon: (document.getElementById('commModeIcon').value || '').trim(),
                    color: document.getElementById('commModeColor').value || '#6c757d',
                    fields_json: fields,
                    lookup_url: (document.getElementById('commModeLookupUrl').value || '').trim(),
                    sort_order: parseInt(document.getElementById('commModeSortOrder').value) || 0,
                    enabled: document.getElementById('commModeEnabled').checked ? 1 : 0,
                    notes: (document.getElementById('commModeNotes').value || '').trim()
                };

                fetch('api/comm-identifiers.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.error) { showAlert(data.error, 'danger'); return; }
                    form.style.display = 'none';
                    showAlert('Comm mode saved.', 'success');
                    loadCommModes();
                })
                .catch(function (err) { showAlert('Save failed: ' + err.message, 'danger'); });
            });
        }

        // Delegate edit/delete
        var tbody = document.getElementById('commModeTableBody');
        if (tbody) {
            tbody.addEventListener('click', function (e) {
                var editBtn = e.target.closest('.cm-edit-btn');
                var delBtn  = e.target.closest('.cm-delete-btn');

                if (editBtn) {
                    var id = parseInt(editBtn.getAttribute('data-id'));
                    var mode = null;
                    for (var i = 0; i < commModesData.length; i++) {
                        if (parseInt(commModesData[i].id) === id) { mode = commModesData[i]; break; }
                    }
                    if (!mode) return;

                    document.getElementById('commModeEditId').value = mode.id;
                    document.getElementById('commModeName').value = mode.name || '';
                    document.getElementById('commModeCode').value = mode.code || '';
                    document.getElementById('commModeIcon').value = mode.icon || '';
                    document.getElementById('commModeColor').value = mode.color || '#6c757d';
                    document.getElementById('commModeSortOrder').value = mode.sort_order || 0;
                    document.getElementById('commModeEnabled').checked = parseInt(mode.enabled) === 1;
                    document.getElementById('commModeLookupUrl').value = mode.lookup_url || '';
                    document.getElementById('commModeNotes').value = mode.notes || '';

                    // Populate field rows
                    var fb = document.getElementById('commModeFieldsBody');
                    fb.innerHTML = '';
                    var fields = [];
                    try { fields = JSON.parse(mode.fields_json); } catch (ex) {}
                    if (fields.length === 0) { addFieldRow(); }
                    else {
                        for (var f = 0; f < fields.length; f++) {
                            addFieldRow(fields[f]);
                        }
                    }
                    form.style.display = '';
                    document.getElementById('commModeName').focus();
                }

                if (delBtn) {
                    var dId = delBtn.getAttribute('data-id');
                    var dName = delBtn.getAttribute('data-name');
                    if (!confirm('Delete comm mode "' + dName + '"?')) return;

                    fetch('api/comm-identifiers.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'delete_mode', id: parseInt(dId) })
                    })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (data.error) { showAlert(data.error, 'danger'); return; }
                        showAlert('Comm mode deleted.', 'success');
                        loadCommModes();
                    })
                    .catch(function (err) { showAlert('Delete failed: ' + err.message, 'danger'); });
                }
            });
        }
    }

    function addFieldRow(fieldDef) {
        var fb = document.getElementById('commModeFieldsBody');
        if (!fb) return;
        var def = fieldDef || {};
        var tr = document.createElement('tr');
        tr.innerHTML =
            '<td><input type="text" class="form-control form-control-sm" value="' + esc(def.key || '') + '" placeholder="field_key"></td>' +
            '<td><input type="text" class="form-control form-control-sm" value="' + esc(def.label || '') + '" placeholder="Display Label"></td>' +
            '<td><select class="form-select form-select-sm">' +
                '<option value="text"' + (def.type === 'text' ? ' selected' : '') + '>Text</option>' +
                '<option value="number"' + (def.type === 'number' ? ' selected' : '') + '>Number</option>' +
                '<option value="select"' + (def.type === 'select' ? ' selected' : '') + '>Select</option>' +
            '</select></td>' +
            '<td><input type="text" class="form-control form-control-sm" value="' + esc(def.placeholder || '') + '" placeholder=""></td>' +
            '<td><input type="number" class="form-control form-control-sm" value="' + (def.maxlength || '') + '" min="0" style="width:60px"></td>' +
            '<td class="text-center"><input type="checkbox" class="form-check-input"' + (def.required ? ' checked' : '') + '></td>' +
            '<td class="text-center"><button class="btn btn-sm btn-link p-0 text-danger cm-field-remove" title="Remove"><i class="bi bi-x-lg"></i></button></td>';
        fb.appendChild(tr);

        tr.querySelector('.cm-field-remove').addEventListener('click', function () {
            tr.remove();
        });
    }

    function loadCommModes() {
        fetch('api/comm-identifiers.php?action=modes')
            .then(function (r) { return r.json(); })
            .then(function (data) {
                commModesData = data.modes || [];
                renderCommModes();
            })
            .catch(function (err) {
                var tbody = document.getElementById('commModeTableBody');
                if (tbody) tbody.innerHTML = '<tr><td colspan="7" class="text-danger py-3">Failed to load: ' + esc(err.message) + '</td></tr>';
            });
    }

    function renderCommModes() {
        var tbody = document.getElementById('commModeTableBody');
        if (!tbody) return;

        if (commModesData.length === 0) {
            tbody.innerHTML = '<tr><td colspan="7" class="text-center text-body-secondary py-3">No comm modes defined.</td></tr>';
            return;
        }

        var html = '';
        commModesData.forEach(function (m) {
            var fields = [];
            try { fields = JSON.parse(m.fields_json); } catch (ex) {}
            var enabledIcon = parseInt(m.enabled) === 1
                ? '<i class="bi bi-check-circle-fill text-success"></i>'
                : '<i class="bi bi-x-circle-fill text-danger"></i>';
            var useBadge = parseInt(m.identifier_count) > 0
                ? '<span class="badge bg-success">' + m.identifier_count + '</span>'
                : '<span class="badge bg-secondary">0</span>';
            var iconPreview = m.icon ? '<i class="bi bi-' + esc(m.icon) + '" style="color:' + esc(m.color || '#6c757d') + ';"></i>' : '--';

            html += '<tr>' +
                '<td class="ps-3 fw-semibold">' + esc(m.name) +
                    (m.notes ? '<br><small class="text-body-secondary">' + esc((m.notes || '').substring(0, 60)) + '</small>' : '') +
                '</td>' +
                '<td><code>' + esc(m.code) + '</code></td>' +
                '<td class="text-center">' + iconPreview + '</td>' +
                '<td class="text-center">' + fields.length + '</td>' +
                '<td class="text-center">' + useBadge + '</td>' +
                '<td class="text-center">' + enabledIcon + '</td>' +
                '<td class="text-center">' +
                    '<button class="btn btn-sm btn-link p-0 me-1 cm-edit-btn" data-id="' + m.id + '" title="Edit">' +
                        '<i class="bi bi-pencil text-primary"></i></button>' +
                    '<button class="btn btn-sm btn-link p-0 cm-delete-btn" data-id="' + m.id + '" data-name="' + esc(m.name) + '" title="Delete">' +
                        '<i class="bi bi-trash text-danger"></i></button>' +
                '</td>' +
                '</tr>';
        });

        tbody.innerHTML = html;
    }

    // ═══════════════════════════════════════════════════════════════
    //  AUDIT LOG PANEL
    // ═══════════════════════════════════════════════════════════════
    var auditSort = 'event_time';
    var auditOrder = 'desc';
    var auditOffset = 0;
    var auditLimit = 50;
    var auditFiltersPopulated = false;

    var SEV_LABELS = ['Unknown', 'Info', 'Low', 'Medium', 'High', 'Critical'];
    var SEV_COLORS = ['secondary', 'info', 'success', 'warning', 'danger', 'danger'];

    function bindAuditLogPanel() {
        var btnSearch = document.getElementById('btnAuditSearch');
        var btnClear = document.getElementById('btnAuditClear');
        if (!btnSearch) return;

        btnSearch.addEventListener('click', function () {
            auditOffset = 0;
            loadedPanels['audit-log'] = false;
            loadAuditLog();
        });

        btnClear.addEventListener('click', function () {
            document.getElementById('auditCategory').value = '';
            document.getElementById('auditActivity').value = '';
            document.getElementById('auditSeverity').value = '';
            document.getElementById('auditUser').value = '';
            document.getElementById('auditSearch').value = '';
            document.getElementById('auditDateFrom').value = '';
            document.getElementById('auditDateTo').value = '';
            auditOffset = 0;
            loadedPanels['audit-log'] = false;
            loadAuditLog();
        });

        // Enter key on search field
        document.getElementById('auditSearch').addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                auditOffset = 0;
                loadedPanels['audit-log'] = false;
                loadAuditLog();
            }
        });

        // Sort headers
        var headers = document.querySelectorAll('#auditTable .sortable');
        for (var i = 0; i < headers.length; i++) {
            headers[i].addEventListener('click', function () {
                var field = this.getAttribute('data-sort');
                if (auditSort === field) {
                    auditOrder = auditOrder === 'asc' ? 'desc' : 'asc';
                } else {
                    auditSort = field;
                    auditOrder = (field === 'event_time' || field === 'severity') ? 'desc' : 'asc';
                }
                auditOffset = 0;
                loadedPanels['audit-log'] = false;
                loadAuditLog();
            });
        }

        // Pagination clicks
        document.getElementById('auditPagination').addEventListener('click', function (e) {
            var link = e.target.closest('[data-offset]');
            if (!link) return;
            e.preventDefault();
            auditOffset = parseInt(link.getAttribute('data-offset'), 10);
            loadedPanels['audit-log'] = false;
            loadAuditLog();
        });
    }

    function loadAuditLog() {
        var params = [];
        var cat = document.getElementById('auditCategory').value;
        var act = document.getElementById('auditActivity').value;
        var sev = document.getElementById('auditSeverity').value;
        var usr = document.getElementById('auditUser').value;
        var q   = document.getElementById('auditSearch').value;
        var df  = document.getElementById('auditDateFrom').value;
        var dt  = document.getElementById('auditDateTo').value;

        if (cat) params.push('category=' + encodeURIComponent(cat));
        if (act) params.push('activity=' + encodeURIComponent(act));
        if (sev) params.push('severity=' + sev);
        if (usr) params.push('user=' + encodeURIComponent(usr));
        if (q)   params.push('q=' + encodeURIComponent(q));
        if (df)  params.push('date_from=' + df);
        if (dt)  params.push('date_to=' + dt);
        params.push('sort=' + auditSort);
        params.push('order=' + auditOrder);
        params.push('limit=' + auditLimit);
        params.push('offset=' + auditOffset);

        var status = document.getElementById('auditStatus');
        status.textContent = 'Loading...';

        fetch('api/audit-log.php?' + params.join('&'))
            .then(function (r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.json();
            })
            .then(function (data) {
                if (data.error) {
                    status.textContent = 'Error: ' + data.error;
                    return;
                }

                renderAuditEntries(data.entries || []);
                renderAuditPagination(data.total, data.limit, data.offset);
                updateAuditSortIndicators();
                populateAuditFilters(data.categories || [], data.activities || []);

                var summary = document.getElementById('auditSummary');
                if (data.total === 0) {
                    summary.textContent = 'No entries found.';
                } else {
                    var from = data.offset + 1;
                    var to = Math.min(data.offset + data.limit, data.total);
                    summary.textContent = from + '-' + to + ' of ' + data.total;
                }
                status.textContent = data.total + ' entries';
            })
            .catch(function (err) {
                status.textContent = 'Error: ' + err.message;
                document.getElementById('auditTableBody').innerHTML =
                    '<tr><td colspan="7" class="text-center text-danger py-3">Failed to load: ' + esc(err.message) + '</td></tr>';
            });
    }

    function renderAuditEntries(entries) {
        var body = document.getElementById('auditTableBody');
        if (entries.length === 0) {
            body.innerHTML = '<tr><td colspan="7" class="text-center text-body-secondary py-4">' +
                '<i class="bi bi-journal me-1"></i>No audit entries match your filters.</td></tr>';
            return;
        }

        var html = '';
        for (var i = 0; i < entries.length; i++) {
            var e = entries[i];
            var sev = e.severity || 0;
            var sevLabel = SEV_LABELS[sev] || 'Unknown';
            var sevColor = SEV_COLORS[sev] || 'secondary';

            var time = e.event_time || '';
            if (time.length > 16) time = time.substring(5, 16); // MM-DD HH:MM

            var target = '';
            if (e.target_type) {
                target = esc(e.target_type);
                if (e.target_id) target += ' #' + esc(e.target_id);
                // GH #86 — for ticket targets, show the case number alongside the
                // raw DB id ("ticket #123 (2026-0045)") so the audit trail is
                // readable AND precise for troubleshooting.
                if (e.target_incident_number) target += ' (' + esc(e.target_incident_number) + ')';
            }

            html += '<tr>' +
                '<td class="text-nowrap text-body-secondary" style="font-size:0.75rem;">' + esc(time) + '</td>' +
                '<td><span class="badge bg-' + sevColor + '" style="font-size:0.6rem;">' + sevLabel + '</span></td>' +
                '<td><span class="badge bg-secondary bg-opacity-25 text-body" style="font-size:0.7rem;">' + esc(e.category || '') + '</span></td>' +
                '<td style="font-size:0.8rem;">' + esc(e.activity || '') + '</td>' +
                '<td style="font-size:0.8rem;">' + esc(e.user_name || '--') + '</td>' +
                '<td class="text-body-secondary" style="font-size:0.75rem;">' + target + '</td>' +
                '<td style="font-size:0.8rem;">' + esc(e.summary || '') + '</td>' +
                '</tr>';
        }
        body.innerHTML = html;
    }

    function renderAuditPagination(total, limit, offset) {
        var list = document.getElementById('auditPagination');
        var info = document.getElementById('auditPageInfo');
        if (!list) return;

        if (total <= limit) {
            list.innerHTML = '';
            if (info) info.textContent = '';
            return;
        }

        var totalPages = Math.ceil(total / limit);
        var currentPage = Math.floor(offset / limit) + 1;
        var html = '';

        // Previous
        if (currentPage > 1) {
            html += '<li class="page-item"><a class="page-link" href="#" data-offset="' + ((currentPage - 2) * limit) + '">&laquo;</a></li>';
        } else {
            html += '<li class="page-item disabled"><span class="page-link">&laquo;</span></li>';
        }

        var startPage = Math.max(1, currentPage - 3);
        var endPage = Math.min(totalPages, startPage + 6);
        if (endPage - startPage < 6) startPage = Math.max(1, endPage - 6);

        for (var p = startPage; p <= endPage; p++) {
            if (p === currentPage) {
                html += '<li class="page-item active"><span class="page-link">' + p + '</span></li>';
            } else {
                html += '<li class="page-item"><a class="page-link" href="#" data-offset="' + ((p - 1) * limit) + '">' + p + '</a></li>';
            }
        }

        // Next
        if (currentPage < totalPages) {
            html += '<li class="page-item"><a class="page-link" href="#" data-offset="' + (currentPage * limit) + '">&raquo;</a></li>';
        } else {
            html += '<li class="page-item disabled"><span class="page-link">&raquo;</span></li>';
        }

        list.innerHTML = html;
        if (info) info.textContent = 'Page ' + currentPage + ' of ' + totalPages;
    }

    function updateAuditSortIndicators() {
        var headers = document.querySelectorAll('#auditTable .sortable');
        for (var i = 0; i < headers.length; i++) {
            var field = headers[i].getAttribute('data-sort');
            var existing = headers[i].querySelector('.sort-indicator');
            if (existing) existing.remove();

            if (field === auditSort) {
                var indicator = document.createElement('i');
                indicator.className = 'bi bi-caret-' + (auditOrder === 'asc' ? 'up' : 'down') + '-fill ms-1 sort-indicator';
                indicator.style.fontSize = '0.6rem';
                headers[i].appendChild(indicator);
            }
        }
    }

    function populateAuditFilters(categories, activities) {
        if (auditFiltersPopulated) return;
        auditFiltersPopulated = true;

        var catSelect = document.getElementById('auditCategory');
        for (var i = 0; i < categories.length; i++) {
            var opt = document.createElement('option');
            opt.value = categories[i];
            opt.textContent = categories[i];
            catSelect.appendChild(opt);
        }

        var actSelect = document.getElementById('auditActivity');
        for (var j = 0; j < activities.length; j++) {
            var opt2 = document.createElement('option');
            opt2.value = activities[j];
            opt2.textContent = activities[j];
            actSelect.appendChild(opt2);
        }
    }

    // ═══════════════════════════════════════════════════════════════
    //  LOOKUP SERVICES
    // ═══════════════════════════════════════════════════════════════

    function loadLookupConfig() {
        fetch('api/callsign-lookup.php?action=config', { credentials: 'same-origin' })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            if (data.error) return;

            var config = data.config || {};

            // Set provider dropdown
            var providerSel = document.getElementById('lookupProvider');
            if (providerSel && config.callsign_provider) {
                providerSel.value = config.callsign_provider;
            }

            // Set FCC-ULS-API URL
            var urlInput = document.getElementById('fccUlsApiUrl');
            if (urlInput && config.fcc_uls_api_url) {
                urlInput.value = config.fcc_uls_api_url;
            }

            // Set User-Agent detail level
            var uaSel = document.getElementById('lookupUaDetail');
            if (uaSel && config.ua_detail) {
                uaSel.value = config.ua_detail;
            }

            // Toggle URL field visibility
            toggleFccUrlField();

            // Show database counts
            var amEl = document.getElementById('lookupAmCount');
            if (amEl) {
                amEl.textContent = data.local_amateur ? formatNum(data.local_amateur_count) : 'Not imported';
                amEl.className = data.local_amateur ? 'fw-bold text-success' : 'fw-bold text-muted';
            }

            var gmrsEl = document.getElementById('lookupGmrsCount');
            if (gmrsEl) {
                gmrsEl.textContent = data.local_gmrs ? 'Available' : 'Not imported';
                gmrsEl.className = data.local_gmrs ? 'fw-bold text-success' : 'fw-bold text-muted';
            }

            var zipEl = document.getElementById('lookupZipCount');
            if (zipEl) {
                zipEl.textContent = data.local_zipcodes ? 'Available' : 'Not imported';
                zipEl.className = data.local_zipcodes ? 'fw-bold text-success' : 'fw-bold text-muted';
            }
        })
        .catch(function () {});

        // Bind provider change to toggle URL field
        var providerSel = document.getElementById('lookupProvider');
        if (providerSel) {
            providerSel.addEventListener('change', toggleFccUrlField);
        }

        // Bind save button
        var saveBtn = document.getElementById('btnSaveLookupConfig');
        if (saveBtn) {
            saveBtn.addEventListener('click', saveLookupConfig);
        }
    }

    function toggleFccUrlField() {
        var provider = document.getElementById('lookupProvider').value;
        var urlGroup = document.getElementById('fccUlsApiUrlGroup');
        if (urlGroup) {
            urlGroup.style.display = (provider === 'fcc_uls_api') ? '' : 'none';
        }
    }

    function saveLookupConfig() {
        var uaSel = document.getElementById('lookupUaDetail');
        var data = {
            callsign_provider: document.getElementById('lookupProvider').value,
            fcc_uls_api_url: document.getElementById('fccUlsApiUrl').value,
            ua_detail: uaSel ? uaSel.value : 'full'
        };

        var statusEl = document.getElementById('lookupSaveStatus');
        statusEl.innerHTML = '<i class="bi bi-hourglass-split"></i> Saving...';

        fetch('api/callsign-lookup.php?action=config', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        })
        .then(function (res) { return res.json(); })
        .then(function (result) {
            if (result.error) {
                statusEl.innerHTML = '<span class="text-danger">' + esc(result.error) + '</span>';
            } else {
                statusEl.innerHTML = '<span class="text-success"><i class="bi bi-check-circle"></i> Saved!</span>';
                setTimeout(function () { statusEl.innerHTML = ''; }, 3000);
            }
        })
        .catch(function (err) {
            statusEl.innerHTML = '<span class="text-danger">Save failed</span>';
        });
    }

    // ═══════════════════════════════════════════════════════════════
    //  ROAD CONDITIONS
    // ═══════════════════════════════════════════════════════════════

    var RC_API = 'api/road-conditions.php';
    var rcConditionTypes = [];
    var rcRoadConditions = [];

    function bindRoadConditionsPanel() {
        // ── Condition Types form ──
        var ctForm = document.getElementById('condTypeForm');
        var ctPanel = document.getElementById('condTypeEditPanel');
        if (!ctForm) return;

        document.getElementById('btnAddCondType').addEventListener('click', function () {
            openCondTypeForm(null);
        });
        document.getElementById('btnCancelCondType').addEventListener('click', function () {
            ctPanel.classList.remove('show');
        });
        document.getElementById('btnDeleteCondType').addEventListener('click', function () {
            var id = document.getElementById('condTypeId').value;
            if (!id || !confirm('Delete this condition type?')) return;
            rcApiPost({ action: 'delete_type', id: id }).then(function () {
                ctPanel.classList.remove('show');
                showAlert('Condition type deleted');
                loadedPanels['road-conditions'] = false;
                loadRoadConditions();
            }).catch(function (err) { showAlert(err.message, 'danger'); });
        });

        ctForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var data = formToObject(ctForm);
            data.action = 'save_type';
            rcApiPost(data).then(function () {
                ctPanel.classList.remove('show');
                showAlert('Condition type saved');
                loadedPanels['road-conditions'] = false;
                loadRoadConditions();
            }).catch(function (err) { showAlert(err.message, 'danger'); });
        });

        // Live icon preview
        var iconInput = document.getElementById('condTypeIcon');
        var iconPreview = document.getElementById('condTypeIconPreview');
        if (iconInput && iconPreview) {
            iconInput.addEventListener('input', function () {
                var val = iconInput.value.trim();
                var cls = val ? 'bi bi-' + val : 'bi bi-tag';
                iconPreview.innerHTML = '<i class="' + cls + '"></i>';
            });
        }

        // ── Road Conditions form ──
        var rcForm = document.getElementById('roadCondForm');
        var rcPanel = document.getElementById('roadCondEditPanel');
        if (!rcForm) return;

        document.getElementById('btnAddRoadCond').addEventListener('click', function () {
            openRoadCondForm(null);
        });
        document.getElementById('btnCancelRoadCond').addEventListener('click', function () {
            rcPanel.classList.remove('show');
        });
        document.getElementById('btnDeleteRoadCond').addEventListener('click', function () {
            var id = document.getElementById('roadCondId').value;
            if (!id || !confirm('Delete this road condition report?')) return;
            rcApiPost({ action: 'delete', id: id }).then(function () {
                rcPanel.classList.remove('show');
                showAlert('Road condition deleted');
                loadedPanels['road-conditions'] = false;
                loadRoadConditions();
            }).catch(function (err) { showAlert(err.message, 'danger'); });
        });

        rcForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var data = formToObject(rcForm);
            data.action = 'save';
            rcApiPost(data).then(function () {
                rcPanel.classList.remove('show');
                showAlert('Road condition saved');
                loadedPanels['road-conditions'] = false;
                loadRoadConditions();
            }).catch(function (err) { showAlert(err.message, 'danger'); });
        });
    }

    function rcApiPost(body) {
        body.csrf_token = csrfToken;
        return fetchJSON(RC_API, {
            method: 'POST',
            body: JSON.stringify(body)
        });
    }

    function loadRoadConditions() {
        // Load condition types first, then road conditions
        fetchJSON(RC_API + '?types=1').then(function (data) {
            rcConditionTypes = data.rows || [];
            renderConditionTypes();
            populateCondTypeDropdown();
            return fetchJSON(RC_API);
        }).then(function (data) {
            rcRoadConditions = data.road_conditions || [];
            renderRoadConditions();
        }).catch(function (err) {
            var el = document.getElementById('condTypesStatus');
            if (el) el.textContent = 'Error: ' + err.message;
        });
    }

    function renderConditionTypes() {
        var tbody = document.getElementById('condTypesTableBody');
        if (!tbody) return;
        var html = '';
        for (var i = 0; i < rcConditionTypes.length; i++) {
            var ct = rcConditionTypes[i];
            var iconCls = ct.icon ? 'bi bi-' + esc(ct.icon) : 'bi bi-tag';
            html += '<tr data-id="' + ct.id + '">' +
                '<td>' + ct.id + '</td>' +
                '<td><i class="' + iconCls + '"></i></td>' +
                '<td><strong>' + esc(ct.title) + '</strong></td>' +
                '<td>' + esc(ct.description || '') + '</td>' +
                '</tr>';
        }
        tbody.innerHTML = html || '<tr><td colspan="4" class="text-center text-body-secondary py-3">No condition types found</td></tr>';
        document.getElementById('condTypesStatus').textContent = rcConditionTypes.length + ' condition type' + (rcConditionTypes.length !== 1 ? 's' : '') + ' loaded';

        var rows = tbody.querySelectorAll('tr[data-id]');
        for (var j = 0; j < rows.length; j++) {
            rows[j].addEventListener('click', function () {
                var item = findById(rcConditionTypes, parseInt(this.getAttribute('data-id'), 10));
                if (item) openCondTypeForm(item);
            });
        }
    }

    function openCondTypeForm(item) {
        var panel = document.getElementById('condTypeEditPanel');
        document.getElementById('condTypeId').value = item ? item.id : '';
        document.getElementById('condTypeTitle').value = item ? item.title : '';
        document.getElementById('condTypeDesc').value = item ? (item.description || '') : '';
        document.getElementById('condTypeIcon').value = item ? (item.icon || '') : '';
        document.getElementById('btnDeleteCondType').classList.toggle('d-none', !item);

        // Update icon preview
        var iconPreview = document.getElementById('condTypeIconPreview');
        if (iconPreview) {
            var cls = (item && item.icon) ? 'bi bi-' + item.icon : 'bi bi-tag';
            iconPreview.innerHTML = '<i class="' + cls + '"></i>';
        }

        panel.classList.add('show');
        document.getElementById('condTypeTitle').focus();
    }

    function populateCondTypeDropdown() {
        var sel = document.getElementById('roadCondType');
        if (!sel) return;
        // Keep the "None" option, remove the rest
        while (sel.options.length > 1) {
            sel.remove(1);
        }
        for (var i = 0; i < rcConditionTypes.length; i++) {
            var opt = document.createElement('option');
            opt.value = rcConditionTypes[i].id;
            opt.textContent = rcConditionTypes[i].title;
            sel.appendChild(opt);
        }
    }

    function renderRoadConditions() {
        var tbody = document.getElementById('roadCondTableBody');
        if (!tbody) return;
        var html = '';
        for (var i = 0; i < rcRoadConditions.length; i++) {
            var rc = rcRoadConditions[i];
            var dateStr = rc._on ? rc._on.substring(0, 10) : '';
            html += '<tr data-id="' + rc.id + '">' +
                '<td>' + rc.id + '</td>' +
                '<td><strong>' + esc(rc.title) + '</strong></td>' +
                '<td>' + esc(rc.address || '') + '</td>' +
                '<td>' + esc(rc.condition_title || '\u2014') + '</td>' +
                '<td>' + esc(dateStr) + '</td>' +
                '<td>' + esc(rc._by || '') + '</td>' +
                '</tr>';
        }
        tbody.innerHTML = html || '<tr><td colspan="6" class="text-center text-body-secondary py-3">No road conditions reported</td></tr>';
        document.getElementById('roadCondStatus').textContent = rcRoadConditions.length + ' road condition' + (rcRoadConditions.length !== 1 ? 's' : '') + ' loaded';

        var rows = tbody.querySelectorAll('tr[data-id]');
        for (var j = 0; j < rows.length; j++) {
            rows[j].addEventListener('click', function () {
                var item = findById(rcRoadConditions, parseInt(this.getAttribute('data-id'), 10));
                if (item) openRoadCondForm(item);
            });
        }
    }

    function openRoadCondForm(item) {
        var panel = document.getElementById('roadCondEditPanel');
        document.getElementById('roadCondId').value = item ? item.id : '';
        document.getElementById('roadCondTitle').value = item ? item.title : '';
        document.getElementById('roadCondDesc').value = item ? (item.description || '') : '';
        document.getElementById('roadCondAddress').value = item ? (item.address || '') : '';
        document.getElementById('roadCondType').value = item ? (item.condition_id || '0') : '0';
        document.getElementById('roadCondLat').value = item ? (item.lat || 0) : 0;
        document.getElementById('roadCondLng').value = item ? (item.lng || 0) : 0;
        document.getElementById('btnDeleteRoadCond').classList.toggle('d-none', !item);
        panel.classList.add('show');
        document.getElementById('roadCondTitle').focus();
    }

    function formatNum(n) {
        if (!n && n !== 0) return '\u2014';
        return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }

    // ═══════════════════════════════════════════════════════════════
    //  GEOFENCING PANEL
    // ═══════════════════════════════════════════════════════════════
    var gfGeofences = [];
    var gfMarkups = [];
    var gfRefreshTimer = null;

    var _gfDrawMap = null;
    var _gfDrawMode = null;     // 'polygon' or 'circle'
    var _gfDrawPoints = [];     // polygon vertices
    var _gfDrawLayer = null;    // current preview shape
    var _gfDrawLayers = [];     // all drawn preview layers
    var _gfDrawnCoords = null;  // final coordinates to save
    var _gfDrawnType = null;    // 'P' or 'C'
    var _gfMarkupsLayer = null; // layer showing existing markups

    function bindGeofencingPanel() {
        var btn = document.getElementById('btnCreateGeofence');
        if (btn) {
            btn.addEventListener('click', gfCreateGeofence);
        }

        // Drawing tools
        var polyBtn = document.getElementById('gfDrawPolygon');
        var circleBtn = document.getElementById('gfDrawCircle');
        var cancelBtn = document.getElementById('gfDrawCancel');
        var finishBtn = document.getElementById('gfDrawFinish');

        if (polyBtn) {
            polyBtn.addEventListener('click', function () { startGfDraw('polygon'); });
        }
        if (circleBtn) {
            circleBtn.addEventListener('click', function () { startGfDraw('circle'); });
        }
        if (cancelBtn) {
            cancelBtn.addEventListener('click', function () { cancelGfDraw(); });
        }
        if (finishBtn) {
            finishBtn.addEventListener('click', function () { gfDrawPolygonFinish(); });
        }

        // Save markup button
        var saveBtn = document.getElementById('btnSaveMarkup');
        if (saveBtn) {
            saveBtn.addEventListener('click', saveGfMarkup);
        }

        // When a saved boundary is selected, zoom the map to show it
        var markupSel = document.getElementById('gfMarkupSelect');
        if (markupSel) {
            markupSel.addEventListener('change', function () {
                gfShowSelectedMarkup(parseInt(this.value, 10));
            });
        }

        loadGeofenceMarkups();
        loadGeofences();

        // Initialize drawing map immediately if panel is already visible,
        // or use MutationObserver to detect when it becomes visible
        var panel = document.getElementById('panel-geofencing');
        if (panel) {
            if (panel.classList.contains('active')) {
                setTimeout(initGfDrawMap, 300);
            }
            var observer = new MutationObserver(function () {
                if (panel.classList.contains('active')) {
                    setTimeout(function () {
                        initGfDrawMap();
                        if (_gfDrawMap) _gfDrawMap.invalidateSize();
                    }, 200);
                }
            });
            observer.observe(panel, { attributes: true, attributeFilter: ['class'] });
        }
    }

    function initGfDrawMap() {
        var container = document.getElementById('gfDrawMap');
        if (!container || !window.L || _gfDrawMap) return;

        // Get default coordinates
        var latInput = document.getElementById('setMapLat');
        var lngInput = document.getElementById('setMapLng');
        var zoomInput = document.getElementById('setMapZoom');
        var lat = parseFloat(latInput ? latInput.value : 0) || 44.9778;
        var lng = parseFloat(lngInput ? lngInput.value : 0) || -93.2650;
        var zoom = parseInt(zoomInput ? zoomInput.value : 0, 10) || 12;

        _gfDrawMap = L.map(container, { zoomControl: true }).setView([lat, lng], zoom);

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap',
            maxZoom: 19
        }).addTo(_gfDrawMap);

        _gfMarkupsLayer = L.layerGroup().addTo(_gfDrawMap);

        // Load and display existing markups as reference
        fetch('api/map-markups.php', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var markups = data.markups || [];
                for (var i = 0; i < markups.length; i++) {
                    var m = markups[i];
                    try {
                        var coords = JSON.parse(m.line_data || '[]');
                        if (m.line_type === 'P' && coords.length >= 3) {
                            L.polygon(coords, { color: '#6c757d', weight: 1, fillOpacity: 0.1, interactive: false })
                                .bindTooltip(m.line_name || m.name || 'Markup', { permanent: false })
                                .addTo(_gfMarkupsLayer);
                        } else if (m.line_type === 'C' && coords.length >= 1) {
                            var radius = parseFloat(m.line_ident) || 500;
                            L.circle(coords[0], { radius: radius, color: '#6c757d', weight: 1, fillOpacity: 0.1, interactive: false })
                                .bindTooltip(m.line_name || m.name || 'Markup', { permanent: false })
                                .addTo(_gfMarkupsLayer);
                        }
                    } catch (e) {}
                }
            }).catch(function () {});

        setTimeout(function () {
            if (_gfDrawMap) _gfDrawMap.invalidateSize();
        }, 300);
    }

    function startGfDraw(mode) {
        if (!_gfDrawMap) initGfDrawMap();
        setTimeout(function () { if (_gfDrawMap) _gfDrawMap.invalidateSize(); }, 100);

        _gfDrawMode = mode;
        _gfDrawPoints = [];
        _gfDrawnCoords = null;
        _gfDrawnType = null;

        // Clear previous drawing
        if (_gfDrawLayer) { _gfDrawMap.removeLayer(_gfDrawLayer); _gfDrawLayer = null; }
        for (var i = 0; i < _gfDrawLayers.length; i++) { _gfDrawMap.removeLayer(_gfDrawLayers[i]); }
        _gfDrawLayers = [];

        var statusEl = document.getElementById('gfDrawStatus');
        var cancelBtn = document.getElementById('gfDrawCancel');
        var finishBtn = document.getElementById('gfDrawFinish');
        var nameRow = document.getElementById('gfMarkupNameRow');

        if (cancelBtn) cancelBtn.classList.remove('d-none');
        if (nameRow) nameRow.classList.add('d-none');

        // Disable map double-click zoom during drawing
        _gfDrawMap.doubleClickZoom.disable();

        if (mode === 'polygon') {
            if (finishBtn) finishBtn.classList.remove('d-none');
            if (statusEl) statusEl.textContent = 'Click on the map to place vertices. Click "Finish Polygon" when done.';
            _gfDrawMap.getContainer().style.cursor = 'crosshair';
            _gfDrawMap.on('click', gfDrawPolygonClick);
        } else if (mode === 'circle') {
            if (finishBtn) finishBtn.classList.add('d-none');
            if (statusEl) statusEl.textContent = 'Click the center of the circle, then click again to set the radius.';
            _gfDrawMap.getContainer().style.cursor = 'crosshair';
            _gfDrawMap.on('click', gfDrawCircleClick);
        }
    }

    function cancelGfDraw() {
        _gfDrawMode = null;
        _gfDrawPoints = [];
        if (_gfDrawLayer) { _gfDrawMap.removeLayer(_gfDrawLayer); _gfDrawLayer = null; }
        for (var i = 0; i < _gfDrawLayers.length; i++) { _gfDrawMap.removeLayer(_gfDrawLayers[i]); }
        _gfDrawLayers = [];
        // Clean up edit markers
        for (var j = 0; j < (_gfEditMarkers || []).length; j++) { _gfDrawMap.removeLayer(_gfEditMarkers[j]); }
        for (var k = 0; k < (_gfMidMarkers || []).length; k++) { _gfDrawMap.removeLayer(_gfMidMarkers[k]); }
        _gfEditMarkers = [];
        _gfMidMarkers = [];

        _gfDrawMap.off('click', gfDrawPolygonClick);
        _gfDrawMap.off('click', gfDrawCircleClick);
        _gfDrawMap.off('mousemove');
        _gfDrawMap.getContainer().style.cursor = '';
        _gfDrawMap.doubleClickZoom.enable();

        var statusEl = document.getElementById('gfDrawStatus');
        var cancelBtn = document.getElementById('gfDrawCancel');
        var finishBtn = document.getElementById('gfDrawFinish');
        var nameRow = document.getElementById('gfMarkupNameRow');
        if (statusEl) statusEl.textContent = '';
        if (cancelBtn) cancelBtn.classList.add('d-none');
        if (finishBtn) finishBtn.classList.add('d-none');
        if (nameRow) nameRow.classList.add('d-none');
    }

    // ── Polygon drawing ──
    function gfDrawPolygonClick(e) {
        _gfDrawPoints.push([e.latlng.lat, e.latlng.lng]);

        // Add vertex marker
        var marker = L.circleMarker(e.latlng, { radius: 4, color: '#0d6efd', fillOpacity: 1 }).addTo(_gfDrawMap);
        _gfDrawLayers.push(marker);

        // Update preview polygon
        if (_gfDrawLayer) _gfDrawMap.removeLayer(_gfDrawLayer);
        if (_gfDrawPoints.length >= 2) {
            _gfDrawLayer = L.polygon(_gfDrawPoints, {
                color: '#0d6efd', weight: 2, fillColor: '#0d6efd', fillOpacity: 0.15, dashArray: '6 4'
            }).addTo(_gfDrawMap);
        }

        var statusEl = document.getElementById('gfDrawStatus');
        if (statusEl) statusEl.textContent = _gfDrawPoints.length + ' vertices placed. Click "Finish Polygon" when done.';
    }

    var _gfEditMarkers = [];   // vertex drag markers
    var _gfMidMarkers = [];    // midpoint insert markers

    function gfDrawPolygonFinish() {
        if (_gfDrawPoints.length < 3) {
            var statusEl = document.getElementById('gfDrawStatus');
            if (statusEl) statusEl.textContent = 'Need at least 3 vertices. Keep clicking to add more points.';
            return;
        }

        // Stop listening for new clicks
        _gfDrawMap.off('click', gfDrawPolygonClick);
        _gfDrawMap.getContainer().style.cursor = '';
        _gfDrawMap.doubleClickZoom.enable();

        // Remove the drawing preview markers (blue dots from click phase)
        for (var i = 0; i < _gfDrawLayers.length; i++) { _gfDrawMap.removeLayer(_gfDrawLayers[i]); }
        _gfDrawLayers = [];

        // Create the final editable polygon with draggable vertices
        gfRenderEditablePolygon();

        _gfDrawnType = 'P';

        // Hide drawing buttons, show name row
        var cancelBtn = document.getElementById('gfDrawCancel');
        var finishBtn = document.getElementById('gfDrawFinish');
        if (cancelBtn) cancelBtn.classList.add('d-none');
        if (finishBtn) finishBtn.classList.add('d-none');

        var nameRow = document.getElementById('gfMarkupNameRow');
        if (nameRow) nameRow.classList.remove('d-none');
        var nameInput = document.getElementById('gfMarkupName');
        if (nameInput) { nameInput.value = ''; nameInput.focus(); }
    }

    /**
     * Render an editable polygon with:
     * - Draggable blue vertex markers (move existing points)
     * - Draggable gray midpoint markers between vertices (drag to insert new point)
     * - Right-click a vertex to delete it
     */
    function gfRenderEditablePolygon() {
        // Clear existing edit markers
        for (var i = 0; i < _gfEditMarkers.length; i++) { _gfDrawMap.removeLayer(_gfEditMarkers[i]); }
        for (var j = 0; j < _gfMidMarkers.length; j++) { _gfDrawMap.removeLayer(_gfMidMarkers[j]); }
        _gfEditMarkers = [];
        _gfMidMarkers = [];

        // Update or create the polygon layer
        if (_gfDrawLayer) _gfDrawMap.removeLayer(_gfDrawLayer);
        _gfDrawLayer = L.polygon(_gfDrawPoints, {
            color: '#0d6efd', weight: 2, fillColor: '#0d6efd', fillOpacity: 0.15
        }).addTo(_gfDrawMap);

        // Update saved coordinates
        _gfDrawnCoords = JSON.stringify(_gfDrawPoints);

        var statusEl = document.getElementById('gfDrawStatus');
        if (statusEl) {
            statusEl.textContent = _gfDrawPoints.length + ' vertices. Drag to move, drag midpoints to add, right-click to delete.';
        }

        // Create draggable vertex markers
        for (var v = 0; v < _gfDrawPoints.length; v++) {
            (function (idx) {
                var marker = L.marker(_gfDrawPoints[idx], {
                    draggable: true,
                    icon: L.divIcon({
                        className: 'gf-vertex-marker',
                        html: '<div style="width:12px;height:12px;border-radius:50%;background:#0d6efd;border:2px solid #fff;box-shadow:0 1px 3px rgba(0,0,0,0.4);cursor:grab"></div>',
                        iconSize: [12, 12],
                        iconAnchor: [6, 6]
                    })
                }).addTo(_gfDrawMap);

                marker.on('drag', function (e) {
                    _gfDrawPoints[idx] = [e.latlng.lat, e.latlng.lng];
                    _gfDrawLayer.setLatLngs(_gfDrawPoints);
                    _gfDrawnCoords = JSON.stringify(_gfDrawPoints);
                });

                marker.on('dragend', function () {
                    // Rebuild midpoints after drag
                    gfRenderEditablePolygon();
                });

                // Right-click to delete vertex (minimum 3)
                marker.on('contextmenu', function (e) {
                    L.DomEvent.preventDefault(e);
                    if (_gfDrawPoints.length <= 3) return; // can't go below triangle
                    _gfDrawPoints.splice(idx, 1);
                    gfRenderEditablePolygon();
                });

                _gfEditMarkers.push(marker);
            })(v);
        }

        // Create midpoint markers between each pair of vertices
        for (var m = 0; m < _gfDrawPoints.length; m++) {
            (function (idx) {
                var nextIdx = (idx + 1) % _gfDrawPoints.length;
                var midLat = (_gfDrawPoints[idx][0] + _gfDrawPoints[nextIdx][0]) / 2;
                var midLng = (_gfDrawPoints[idx][1] + _gfDrawPoints[nextIdx][1]) / 2;

                var midMarker = L.marker([midLat, midLng], {
                    draggable: true,
                    icon: L.divIcon({
                        className: 'gf-mid-marker',
                        html: '<div style="width:8px;height:8px;border-radius:50%;background:#6c757d;border:1px solid #fff;opacity:0.6;cursor:grab"></div>',
                        iconSize: [8, 8],
                        iconAnchor: [4, 4]
                    })
                }).addTo(_gfDrawMap);

                midMarker.on('dragend', function (e) {
                    // Insert new vertex at this position
                    var newPoint = [e.target.getLatLng().lat, e.target.getLatLng().lng];
                    _gfDrawPoints.splice(nextIdx, 0, newPoint);
                    gfRenderEditablePolygon();
                });

                _gfMidMarkers.push(midMarker);
            })(m);
        }
    }

    // ── Circle drawing ──
    var _gfCircleCenter = null;

    function gfDrawCircleClick(e) {
        if (!_gfCircleCenter) {
            // First click: set center
            _gfCircleCenter = e.latlng;
            var centerMarker = L.circleMarker(e.latlng, { radius: 5, color: '#0d6efd', fillOpacity: 1 }).addTo(_gfDrawMap);
            _gfDrawLayers.push(centerMarker);

            var statusEl = document.getElementById('gfDrawStatus');
            if (statusEl) statusEl.textContent = 'Center placed. Move mouse to set radius, click to confirm.';

            // Show radius preview on mousemove
            _gfDrawMap.on('mousemove', function (ev) {
                var radius = _gfCircleCenter.distanceTo(ev.latlng);
                if (_gfDrawLayer) _gfDrawMap.removeLayer(_gfDrawLayer);
                _gfDrawLayer = L.circle(_gfCircleCenter, {
                    radius: radius, color: '#0d6efd', weight: 2,
                    fillColor: '#0d6efd', fillOpacity: 0.15, dashArray: '6 4'
                }).addTo(_gfDrawMap);
                if (statusEl) statusEl.textContent = 'Radius: ' + Math.round(radius) + 'm. Click to confirm.';
            });
        } else {
            // Second click: set radius
            var radius = _gfCircleCenter.distanceTo(e.latlng);

            _gfDrawMap.off('click', gfDrawCircleClick);
            _gfDrawMap.off('mousemove');
            _gfDrawMap.getContainer().style.cursor = '';

            // Finalize circle
            if (_gfDrawLayer) _gfDrawMap.removeLayer(_gfDrawLayer);
            _gfDrawLayer = L.circle(_gfCircleCenter, {
                radius: radius, color: '#0d6efd', weight: 2,
                fillColor: '#0d6efd', fillOpacity: 0.2
            }).addTo(_gfDrawMap);

            _gfDrawnCoords = JSON.stringify([[_gfCircleCenter.lat, _gfCircleCenter.lng]]);
            _gfDrawnType = 'C';
            // Store radius in a way the API expects
            _gfDrawPoints = { center: [_gfCircleCenter.lat, _gfCircleCenter.lng], radius: Math.round(radius) };

            var statusEl = document.getElementById('gfDrawStatus');
            if (statusEl) statusEl.textContent = 'Circle complete (radius: ' + Math.round(radius) + 'm). Name it and save below.';

            var cancelBtn = document.getElementById('gfDrawCancel');
            if (cancelBtn) cancelBtn.style.display = 'none';

            var nameRow = document.getElementById('gfMarkupNameRow');
            if (nameRow) nameRow.classList.remove('d-none');
            var nameInput = document.getElementById('gfMarkupName');
            if (nameInput) { nameInput.value = ''; nameInput.focus(); }

            _gfCircleCenter = null;
        }
    }

    // ── Save markup to API ──
    function saveGfMarkup() {
        var name = (document.getElementById('gfMarkupName').value || '').trim();
        if (!name) { showAlert('Please enter a name for the boundary', 'warning'); return; }
        if (!_gfDrawnCoords && !_gfDrawPoints) { showAlert('No shape drawn', 'warning'); return; }

        var saveStatusEl = document.getElementById('gfSaveStatus');
        var payload = {
            action: 'save',
            name: name,
            type: _gfDrawnType,
            coordinates: _gfDrawnCoords,
            color: '#0d6efd',
            opacity: '0.8',
            width: '2',
            fill_color: '#0d6efd',
            fill_opacity: '0.2',
            filled: '1',
            visible: '1',
            csrf_token: csrfToken
        };

        // For circles, store radius in ident field
        if (_gfDrawnType === 'C' && _gfDrawPoints && _gfDrawPoints.radius) {
            payload.ident = String(_gfDrawPoints.radius);
        }

        fetch('api/map-markups.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify(payload)
        }).then(function (r) { return r.json(); })
          .then(function (data) {
              if (data.error) {
                  if (saveStatusEl) { saveStatusEl.textContent = data.error; saveStatusEl.className = 'small text-danger'; }
                  return;
              }
              if (saveStatusEl) { saveStatusEl.textContent = 'Saved! Select it below to create a geofence.'; saveStatusEl.className = 'small text-success'; }

              // Refresh markup dropdown
              loadGeofenceMarkups();

              // Reset drawing state
              var nameRow = document.getElementById('gfMarkupNameRow');
              if (nameRow) nameRow.classList.add('d-none');
              _gfDrawnCoords = null;
              _gfDrawnType = null;

              // Auto-select the new markup in the dropdown (after a short delay for the list to refresh)
              setTimeout(function () {
                  var sel = document.getElementById('gfMarkupSelect');
                  if (sel && data.id) {
                      sel.value = data.id;
                  }
                  var gfNameInput = document.getElementById('gfName');
                  if (gfNameInput) gfNameInput.value = name;
              }, 500);
          })
          .catch(function (err) {
              if (saveStatusEl) { saveStatusEl.textContent = err.message; saveStatusEl.className = 'small text-danger'; }
          });
    }

    function loadGeofenceMarkups() {
        fetch('api/map-markups.php', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var markups = data.markups || [];
                gfMarkups = markups;
                var sel = document.getElementById('gfMarkupSelect');
                if (!sel) return;
                // Keep first placeholder option
                sel.innerHTML = '<option value="">-- select a markup --</option>';
                for (var i = 0; i < markups.length; i++) {
                    var m = markups[i];
                    var markupName = m.line_name || m.name || '(unnamed)';
                    var typeName = m.line_type || m.markup_type || '?';
                    var opt = document.createElement('option');
                    opt.value = m.id;
                    opt.textContent = markupName + ' (' + typeName + ')';
                    sel.appendChild(opt);
                }
            })
            .catch(function () {});
    }

    var _gfHighlightLayer = null; // highlighted selected markup

    function gfShowSelectedMarkup(markupId) {
        if (!_gfDrawMap) initGfDrawMap();
        if (!_gfDrawMap) return;

        // Remove previous highlight
        if (_gfHighlightLayer) {
            _gfDrawMap.removeLayer(_gfHighlightLayer);
            _gfHighlightLayer = null;
        }

        if (!markupId) return;

        // Find the markup in the cached list
        var markup = null;
        for (var i = 0; i < gfMarkups.length; i++) {
            if (parseInt(gfMarkups[i].id, 10) === markupId) {
                markup = gfMarkups[i];
                break;
            }
        }
        if (!markup) return;

        try {
            var coords = JSON.parse(markup.line_data || '[]');
            if (!coords || !coords.length) return;

            var type = (markup.line_type || 'P').toUpperCase();

            if (type === 'P' && coords.length >= 3) {
                // Polygon — highlight and fit bounds
                _gfHighlightLayer = L.polygon(coords, {
                    color: '#ff6600', weight: 3, fillColor: '#ff6600', fillOpacity: 0.2, dashArray: '6 4'
                }).addTo(_gfDrawMap);
                _gfDrawMap.fitBounds(_gfHighlightLayer.getBounds(), { padding: [30, 30], maxZoom: 16 });

            } else if (type === 'C' && coords.length >= 1) {
                // Circle — highlight and fit bounds
                var radius = parseFloat(markup.line_ident) || 500;
                _gfHighlightLayer = L.circle(coords[0], {
                    radius: radius, color: '#ff6600', weight: 3, fillColor: '#ff6600', fillOpacity: 0.2, dashArray: '6 4'
                }).addTo(_gfDrawMap);
                _gfDrawMap.fitBounds(_gfHighlightLayer.getBounds(), { padding: [30, 30], maxZoom: 16 });
            }
        } catch (e) {}
    }

    function loadGeofences() {
        fetch('api/geofences.php', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                gfGeofences = data.geofences || [];
                renderGeofences();
            })
            .catch(function () {
                var tbody = document.getElementById('geofenceBody');
                if (tbody) {
                    tbody.innerHTML = '<tr><td colspan="8" class="text-danger small text-center">Failed to load geofences</td></tr>';
                }
            });
    }

    function renderGeofences() {
        var tbody = document.getElementById('geofenceBody');
        if (!tbody) return;

        if (gfGeofences.length === 0) {
            tbody.innerHTML = '<tr><td colspan="8" class="text-body-secondary small text-center">No geofences defined. Create one above.</td></tr>';
            return;
        }

        var html = '';
        for (var i = 0; i < gfGeofences.length; i++) {
            var g = gfGeofences[i];
            var activeChecked = parseInt(g.active) ? ' checked' : '';
            var enterIcon = parseInt(g.alert_on_enter) ? '<i class="bi bi-check-circle-fill text-success"></i>' : '<i class="bi bi-dash-circle text-body-secondary"></i>';
            var exitIcon = parseInt(g.alert_on_exit) ? '<i class="bi bi-check-circle-fill text-success"></i>' : '<i class="bi bi-dash-circle text-body-secondary"></i>';
            var unitCount = parseInt(g.units_inside_count) || 0;
            var unitBadge = unitCount > 0
                ? '<span class="badge bg-warning text-dark">' + unitCount + '</span>'
                : '<span class="badge bg-secondary">0</span>';

            html += '<tr data-gf-id="' + g.id + '">' +
                '<td class="small">' + esc(g.name) + '</td>' +
                '<td class="small">' + esc(g.markup_name || '') + '</td>' +
                '<td class="small">' + esc(g.markup_type || '') + '</td>' +
                '<td class="text-center">' + enterIcon + '</td>' +
                '<td class="text-center">' + exitIcon + '</td>' +
                '<td class="text-center">' + unitBadge + '</td>' +
                '<td class="text-center">' +
                    '<div class="form-check form-switch d-inline-block">' +
                        '<input class="form-check-input gf-active-toggle" type="checkbox" data-id="' + g.id + '"' + activeChecked + '>' +
                    '</div>' +
                '</td>' +
                '<td class="text-end">' +
                    '<button class="btn btn-sm btn-outline-primary gf-edit-btn me-1 py-0 px-1" data-id="' + g.id + '" data-markup-id="' + (g.markup_id || '') + '" title="Edit">' +
                        '<i class="bi bi-pencil"></i>' +
                    '</button>' +
                    '<button class="btn btn-sm btn-outline-danger gf-delete-btn py-0 px-1" data-id="' + g.id + '" data-name="' + esc(g.name) + '" title="Delete">' +
                        '<i class="bi bi-trash"></i>' +
                    '</button>' +
                '</td>' +
                '</tr>' +
                // Hidden edit row
                '<tr class="gf-edit-row d-none" data-edit-id="' + g.id + '">' +
                '<td colspan="8" class="py-2 px-3 bg-body-tertiary">' +
                    '<div class="row g-2 align-items-end">' +
                        '<div class="col-md-3">' +
                            '<label class="form-label form-label-sm mb-0">Name</label>' +
                            '<input type="text" class="form-control form-control-sm gf-edit-name" value="' + esc(g.name) + '">' +
                        '</div>' +
                        '<div class="col-md-2">' +
                            '<div class="form-check form-check-sm"><input type="checkbox" class="form-check-input gf-edit-enter"' + (parseInt(g.alert_on_enter) ? ' checked' : '') + '><label class="form-check-label small">Enter</label></div>' +
                            '<div class="form-check form-check-sm"><input type="checkbox" class="form-check-input gf-edit-exit"' + (parseInt(g.alert_on_exit) ? ' checked' : '') + '><label class="form-check-label small">Exit</label></div>' +
                        '</div>' +
                        '<div class="col-md-4">' +
                            '<label class="form-label form-label-sm mb-0">Alert Channels</label>' +
                            '<select class="form-select form-select-sm gf-edit-channels" multiple size="3">' +
                                '<option value="local_chat"' + ((g.alert_channels || []).indexOf('local_chat') >= 0 ? ' selected' : '') + '>Local Chat</option>' +
                                '<option value="smtp"' + ((g.alert_channels || []).indexOf('smtp') >= 0 ? ' selected' : '') + '>Email</option>' +
                                '<option value="sms"' + ((g.alert_channels || []).indexOf('sms') >= 0 ? ' selected' : '') + '>SMS</option>' +
                                '<option value="slack"' + ((g.alert_channels || []).indexOf('slack') >= 0 ? ' selected' : '') + '>Slack</option>' +
                            '</select>' +
                        '</div>' +
                        '<div class="col-md-3 d-flex gap-1">' +
                            '<button class="btn btn-sm btn-success gf-edit-save" data-id="' + g.id + '"><i class="bi bi-check-lg me-1"></i>Save</button>' +
                            '<button class="btn btn-sm btn-outline-secondary gf-edit-cancel" data-id="' + g.id + '">Cancel</button>' +
                        '</div>' +
                    '</div>' +
                '</td>' +
                '</tr>';
        }
        tbody.innerHTML = html;

        // Bind active toggles
        var toggles = tbody.querySelectorAll('.gf-active-toggle');
        for (var t = 0; t < toggles.length; t++) {
            toggles[t].addEventListener('change', function () {
                var id = parseInt(this.getAttribute('data-id'));
                var active = this.checked ? 1 : 0;
                gfUpdateGeofence(id, { active: active });
            });
        }

        // Bind delete buttons
        var delBtns = tbody.querySelectorAll('.gf-delete-btn');
        for (var d = 0; d < delBtns.length; d++) {
            delBtns[d].addEventListener('click', function () {
                var id = parseInt(this.getAttribute('data-id'));
                var name = this.getAttribute('data-name');
                if (confirm('Delete geofence "' + name + '"?')) {
                    gfDeleteGeofence(id);
                }
            });
        }

        // Bind edit buttons — toggle the edit row and zoom map to boundary
        var editBtns = tbody.querySelectorAll('.gf-edit-btn');
        for (var e = 0; e < editBtns.length; e++) {
            editBtns[e].addEventListener('click', function () {
                var id = this.getAttribute('data-id');
                var markupId = parseInt(this.getAttribute('data-markup-id'), 10);
                var editRow = tbody.querySelector('.gf-edit-row[data-edit-id="' + id + '"]');
                if (editRow) {
                    // Close any other open edit rows
                    var allEdits = tbody.querySelectorAll('.gf-edit-row');
                    for (var x = 0; x < allEdits.length; x++) {
                        if (allEdits[x] !== editRow) allEdits[x].classList.add('d-none');
                    }
                    var isOpening = editRow.classList.contains('d-none');
                    editRow.classList.toggle('d-none');

                    // Zoom map to the boundary when opening the edit row
                    if (isOpening && markupId) {
                        gfShowSelectedMarkup(markupId);
                    }
                }
            });
        }

        // Bind edit save buttons
        var saveBtns = tbody.querySelectorAll('.gf-edit-save');
        for (var s = 0; s < saveBtns.length; s++) {
            saveBtns[s].addEventListener('click', function () {
                var id = parseInt(this.getAttribute('data-id'));
                var editRow = tbody.querySelector('.gf-edit-row[data-edit-id="' + id + '"]');
                if (!editRow) return;

                var newName = editRow.querySelector('.gf-edit-name').value.trim();
                var alertEnter = editRow.querySelector('.gf-edit-enter').checked ? 1 : 0;
                var alertExit = editRow.querySelector('.gf-edit-exit').checked ? 1 : 0;

                var chanSel = editRow.querySelector('.gf-edit-channels');
                var channels = [];
                for (var c = 0; c < chanSel.options.length; c++) {
                    if (chanSel.options[c].selected) channels.push(chanSel.options[c].value);
                }

                gfUpdateGeofence(id, {
                    name: newName,
                    alert_on_enter: alertEnter,
                    alert_on_exit: alertExit,
                    alert_channels: channels
                });

                // Reload after a brief delay
                setTimeout(loadGeofences, 500);
            });
        }

        // Bind edit cancel buttons
        var cancelBtns = tbody.querySelectorAll('.gf-edit-cancel');
        for (var c2 = 0; c2 < cancelBtns.length; c2++) {
            cancelBtns[c2].addEventListener('click', function () {
                var id = this.getAttribute('data-id');
                var editRow = tbody.querySelector('.gf-edit-row[data-edit-id="' + id + '"]');
                if (editRow) editRow.classList.add('d-none');
            });
        }
    }

    function gfCreateGeofence() {
        var sel = document.getElementById('gfMarkupSelect');
        var markupId = sel ? parseInt(sel.value) : 0;
        if (!markupId) {
            alert('Please select a map markup.');
            return;
        }
        var nameEl = document.getElementById('gfName');
        var name = nameEl ? nameEl.value.trim() : '';
        var enterEl = document.getElementById('gfAlertEnter');
        var exitEl = document.getElementById('gfAlertExit');
        var chanEl = document.getElementById('gfChannels');

        var channels = [];
        if (chanEl) {
            for (var c = 0; c < chanEl.options.length; c++) {
                if (chanEl.options[c].selected) {
                    channels.push(chanEl.options[c].value);
                }
            }
        }

        var body = {
            action: 'create',
            csrf_token: csrfToken,
            markup_id: markupId,
            name: name,
            alert_on_enter: enterEl && enterEl.checked ? 1 : 0,
            alert_on_exit: exitEl && exitEl.checked ? 1 : 0,
            alert_channels: channels
        };

        fetch('api/geofences.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.error) {
                alert('Error: ' + data.error);
                return;
            }
            // Reset form
            if (sel) sel.value = '';
            if (nameEl) nameEl.value = '';
            loadGeofences();
        })
        .catch(function (err) {
            alert('Failed to create geofence: ' + err.message);
        });
    }

    function gfUpdateGeofence(id, fields) {
        var body = { action: 'update', csrf_token: csrfToken, id: id };
        for (var k in fields) {
            if (fields.hasOwnProperty(k)) body[k] = fields[k];
        }

        fetch('api/geofences.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.error) alert('Error: ' + data.error);
        })
        .catch(function () {});
    }

    function gfDeleteGeofence(id) {
        fetch('api/geofences.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'delete', csrf_token: csrfToken, id: id })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.error) {
                alert('Error: ' + data.error);
                return;
            }
            loadGeofences();
        })
        .catch(function (err) {
            alert('Delete failed: ' + err.message);
        });
    }

    // ═══════════════════════════════════════════════════════════════
    //  WEBHOOKS
    // ═══════════════════════════════════════════════════════════════
    var WEBHOOK_API = 'api/webhooks.php';
    var webhooksCache = [];

    function bindWebhooksPanel() {
        var form = document.getElementById('webhookForm');
        var panel = document.getElementById('webhookEditPanel');
        if (!form) return;

        document.getElementById('btnAddWebhook').addEventListener('click', function () {
            openWebhookForm(null);
        });
        document.getElementById('btnCancelWebhook').addEventListener('click', function () {
            panel.classList.remove('show');
            document.getElementById('webhookDeliveriesPanel').classList.remove('show');
        });
        document.getElementById('btnDeleteWebhook').addEventListener('click', function () {
            var id = document.getElementById('webhookId').value;
            if (!id || !confirm('Delete this webhook and all its delivery history?')) return;
            fetchJSON(WEBHOOK_API, {
                method: 'POST',
                body: JSON.stringify({ action: 'delete', id: id, csrf_token: csrfToken })
            }).then(function () {
                panel.classList.remove('show');
                document.getElementById('webhookDeliveriesPanel').classList.remove('show');
                showAlert('Webhook deleted');
                loadedPanels['webhooks'] = false;
                loadWebhooks();
            }).catch(function (err) { showAlert(err.message, 'danger'); });
        });
        document.getElementById('btnTestWebhook').addEventListener('click', function () {
            var url = document.getElementById('webhookUrl').value.trim();
            var secret = document.getElementById('webhookSecret').value.trim();
            if (!url) { showAlert('Enter a URL first', 'warning'); return; }
            var btn = this;
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Testing...';
            fetchJSON(WEBHOOK_API, {
                method: 'POST',
                body: JSON.stringify({ action: 'test', url: url, secret: secret, csrf_token: csrfToken })
            }).then(function (data) {
                var t = data.test || {};
                if (t.success) {
                    showAlert('Test successful! HTTP ' + t.http_status + ' in ' + t.duration_ms + 'ms');
                } else {
                    showAlert('Test failed: HTTP ' + t.http_status + ' — ' + (t.response || 'No response'), 'danger');
                }
            }).catch(function (err) {
                showAlert('Test error: ' + err.message, 'danger');
            }).then(function () {
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-send me-1"></i>Test';
            });
        });
        document.getElementById('btnGenSecret').addEventListener('click', function () {
            var arr = new Uint8Array(32);
            window.crypto.getRandomValues(arr);
            var hex = '';
            for (var i = 0; i < arr.length; i++) {
                hex += ('0' + arr[i].toString(16)).slice(-2);
            }
            document.getElementById('webhookSecret').value = hex;
        });

        // "All Events" checkbox toggles others
        document.getElementById('whEvtAll').addEventListener('change', function () {
            var boxes = document.querySelectorAll('.wh-evt');
            for (var i = 0; i < boxes.length; i++) {
                if (boxes[i].value !== '*') boxes[i].checked = false;
            }
        });
        var evtBoxes = document.querySelectorAll('.wh-evt');
        for (var i = 0; i < evtBoxes.length; i++) {
            if (evtBoxes[i].value !== '*') {
                evtBoxes[i].addEventListener('change', function () {
                    if (this.checked) document.getElementById('whEvtAll').checked = false;
                });
            }
        }

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var id = document.getElementById('webhookId').value;
            var events = [];
            var boxes = document.querySelectorAll('.wh-evt:checked');
            for (var j = 0; j < boxes.length; j++) {
                events.push(boxes[j].value);
            }
            if (events.length === 0) {
                showAlert('Select at least one event type', 'warning');
                return;
            }
            var payload = {
                action: 'save',
                id: id || null,
                name: document.getElementById('webhookName').value.trim(),
                url: document.getElementById('webhookUrl').value.trim(),
                secret: document.getElementById('webhookSecret').value.trim(),
                retry_max: parseInt(document.getElementById('webhookRetryMax').value, 10) || 3,
                active: document.getElementById('webhookActive').checked ? 1 : 0,
                events: events,
                csrf_token: csrfToken
            };
            fetchJSON(WEBHOOK_API, {
                method: 'POST',
                body: JSON.stringify(payload)
            }).then(function (data) {
                panel.classList.remove('show');
                showAlert('Webhook saved');
                // If secret was auto-generated, show it
                if (data.secret && !id) {
                    showAlert('Secret: ' + data.secret + ' (save this!)', 'info');
                }
                loadedPanels['webhooks'] = false;
                loadWebhooks();
            }).catch(function (err) { showAlert(err.message, 'danger'); });
        });
    }

    function loadWebhooks() {
        fetchJSON(WEBHOOK_API).then(function (data) {
            webhooksCache = data.rows || [];
            renderWebhooks();
        }).catch(function (err) {
            var el = document.getElementById('webhooksStatus');
            if (el) el.textContent = 'Error: ' + err.message;
        });
    }

    function renderWebhooks() {
        var tbody = document.getElementById('webhooksTableBody');
        if (!tbody) return;
        var html = '';
        for (var i = 0; i < webhooksCache.length; i++) {
            var w = webhooksCache[i];
            var evts = w.events || [];
            var evtLabel = evts.length > 2 ? evts.length + ' events' : evts.join(', ');
            var statusBadge = '';
            if (w.last_status === 'success') {
                statusBadge = '<span class="badge bg-success">OK</span>';
            } else if (w.last_status === 'failed') {
                statusBadge = '<span class="badge bg-danger">Failed</span>';
            } else if (w.last_status) {
                statusBadge = '<span class="badge bg-secondary">' + esc(w.last_status) + '</span>';
            } else {
                statusBadge = '<span class="text-body-secondary small">Never</span>';
            }

            html += '<tr data-id="' + w.id + '">' +
                '<td><strong>' + esc(w.name) + '</strong></td>' +
                '<td class="text-truncate" style="max-width:200px" title="' + esc(w.url) + '">' + esc(w.url) + '</td>' +
                '<td><span class="small">' + esc(evtLabel) + '</span></td>' +
                '<td class="text-center">' + (parseInt(w.active) ? '<i class="bi bi-check-circle-fill text-success"></i>' : '<i class="bi bi-x-circle text-secondary"></i>') + '</td>' +
                '<td class="text-center">' + statusBadge + '</td>' +
                '<td class="text-center"><button class="btn btn-sm btn-outline-secondary btn-wh-detail" data-id="' + w.id + '" title="Details"><i class="bi bi-eye"></i></button></td>' +
                '</tr>';
        }
        tbody.innerHTML = html || '<tr><td colspan="6" class="text-center text-body-secondary py-3">No webhooks configured</td></tr>';
        document.getElementById('webhooksStatus').textContent = webhooksCache.length + ' webhook' + (webhooksCache.length !== 1 ? 's' : '');

        // Click row to edit
        var rows = tbody.querySelectorAll('tr[data-id]');
        for (var j = 0; j < rows.length; j++) {
            rows[j].addEventListener('click', function (e) {
                // Don't trigger if the detail button was clicked
                if (e.target.closest('.btn-wh-detail')) return;
                var item = findById(webhooksCache, parseInt(this.getAttribute('data-id'), 10));
                if (item) openWebhookForm(item);
            });
        }

        // Detail buttons — load deliveries
        var detailBtns = tbody.querySelectorAll('.btn-wh-detail');
        for (var k = 0; k < detailBtns.length; k++) {
            detailBtns[k].addEventListener('click', function (e) {
                e.stopPropagation();
                var whId = this.getAttribute('data-id');
                loadWebhookDeliveries(whId);
            });
        }
    }

    function openWebhookForm(item) {
        var panel = document.getElementById('webhookEditPanel');
        document.getElementById('webhookFormTitle').textContent = item ? 'Edit Webhook' : 'Add Webhook';
        document.getElementById('webhookId').value = item ? item.id : '';
        document.getElementById('webhookName').value = item ? item.name : '';
        document.getElementById('webhookUrl').value = item ? item.url : '';
        // 2026-06-28 security audit fix #6: secret is masked in the
        // detail GET response (server returns null + secret_prefix).
        // Leaving the field blank means "keep current"; setting a new
        // value rotates it. Hint shown in the placeholder.
        var secretField = document.getElementById('webhookSecret');
        secretField.value = '';
        if (item && item.secret_prefix) {
            secretField.placeholder = 'leave blank to keep (current starts: ' + item.secret_prefix + '…)';
        } else {
            secretField.placeholder = 'shared HMAC secret (auto-generated if blank)';
        }
        document.getElementById('webhookRetryMax').value = item ? (item.retry_max || 3) : 3;
        document.getElementById('webhookActive').checked = item ? (parseInt(item.active) === 1) : true;
        document.getElementById('btnDeleteWebhook').classList.toggle('d-none', !item);

        // Set event checkboxes
        var events = item ? (item.events || []) : [];
        var boxes = document.querySelectorAll('.wh-evt');
        for (var i = 0; i < boxes.length; i++) {
            boxes[i].checked = (events.indexOf(boxes[i].value) !== -1);
        }

        panel.classList.add('show');
        document.getElementById('webhookName').focus();

        // Also load deliveries if editing
        if (item) {
            loadWebhookDeliveries(item.id);
        } else {
            document.getElementById('webhookDeliveriesPanel').classList.remove('show');
        }
    }

    function loadWebhookDeliveries(webhookId) {
        var delPanel = document.getElementById('webhookDeliveriesPanel');
        var tbody = document.getElementById('webhookDeliveriesBody');
        tbody.innerHTML = '<tr><td colspan="6" class="text-center py-2"><span class="spinner-border spinner-border-sm"></span></td></tr>';
        delPanel.classList.add('show');

        fetchJSON(WEBHOOK_API + '?id=' + webhookId).then(function (data) {
            var dels = data.deliveries || [];
            var html = '';
            for (var i = 0; i < dels.length; i++) {
                var d = dels[i];
                var sBadge = '';
                if (d.status === 'success') sBadge = '<span class="badge bg-success">OK</span>';
                else if (d.status === 'failed') sBadge = '<span class="badge bg-danger">Failed</span>';
                else sBadge = '<span class="badge bg-secondary">' + esc(d.status) + '</span>';

                html += '<tr>' +
                    '<td class="small">' + esc(d.created_at || '') + '</td>' +
                    '<td class="small">' + esc(d.event_type) + '</td>' +
                    '<td class="text-center">' + sBadge + '</td>' +
                    '<td class="text-center small">' + (d.http_status || '-') + '</td>' +
                    '<td class="text-end small">' + (d.duration_ms ? d.duration_ms + 'ms' : '-') + '</td>' +
                    '<td class="small text-danger">' + esc(d.error || '') + '</td>' +
                    '</tr>';
            }
            tbody.innerHTML = html || '<tr><td colspan="6" class="text-center text-body-secondary py-2">No deliveries yet</td></tr>';
        }).catch(function (err) {
            tbody.innerHTML = '<tr><td colspan="6" class="text-center text-danger py-2">' + esc(err.message) + '</td></tr>';
        });
    }

    // ═══════════════════════════════════════════════════════════════
    //  APRS CONFIGURATION PANEL
    // ═══════════════════════════════════════════════════════════════
    function bindAprsConfigPanel() {
        var form = document.getElementById('aprsConfigForm');
        if (!form) return;

        // Load current APRS settings
        apiGet('settings').then(function (data) {
            var settings = data.settings || {};
            var keyInput = form.querySelector('[data-key="aprs_fi_api_key"]');
            var intervalInput = form.querySelector('[data-key="aprs_poll_interval"]');
            var enabledCheck = form.querySelector('[data-key="aprs_enabled"]');

            if (keyInput && settings.aprs_fi_api_key) keyInput.value = settings.aprs_fi_api_key;
            if (intervalInput && settings.aprs_poll_interval) intervalInput.value = settings.aprs_poll_interval;
            if (enabledCheck) enabledCheck.checked = settings.aprs_enabled === '1';

            // Phase 99a #14 (2026-06-28) — APRS-IS send settings.
            var sendCallInput = form.querySelector('[data-key="aprs_send_callsign"]');
            var sendPassInput = form.querySelector('[data-key="aprs_send_passcode"]');
            var serverInput = form.querySelector('[data-key="aprs_is_server"]');
            var portInput = form.querySelector('[data-key="aprs_is_port"]');
            if (sendCallInput && settings.aprs_send_callsign) sendCallInput.value = settings.aprs_send_callsign;
            if (sendPassInput && settings.aprs_send_passcode) sendPassInput.value = settings.aprs_send_passcode;
            if (serverInput && settings.aprs_is_server) serverInput.value = settings.aprs_is_server;
            if (portInput && settings.aprs_is_port) portInput.value = settings.aprs_is_port;

            // Phase 99a #14 follow-on (2026-06-28) — FCC license attestation gate.
            // Show gate vs accepted-confirmation based on prior acceptance.
            _aprsApplyLicenseState(
                settings.aprs_license_attestation_accepted_at || '',
                settings.aprs_license_attestation_accepted_by || ''
            );
        }).catch(function () {
            // Settings not available yet
        });

        // Save handler
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var keyInput = form.querySelector('[data-key="aprs_fi_api_key"]');
            var intervalInput = form.querySelector('[data-key="aprs_poll_interval"]');
            var enabledCheck = form.querySelector('[data-key="aprs_enabled"]');
            // Phase 99a #14 — send settings
            var sendCallInput = form.querySelector('[data-key="aprs_send_callsign"]');
            var sendPassInput = form.querySelector('[data-key="aprs_send_passcode"]');
            var serverInput = form.querySelector('[data-key="aprs_is_server"]');
            var portInput = form.querySelector('[data-key="aprs_is_port"]');

            var pairs = {};
            if (keyInput) pairs.aprs_fi_api_key = keyInput.value;
            if (intervalInput) pairs.aprs_poll_interval = intervalInput.value || '5';
            if (enabledCheck) pairs.aprs_enabled = enabledCheck.checked ? '1' : '0';
            if (sendCallInput) pairs.aprs_send_callsign = sendCallInput.value.trim().toUpperCase();
            if (sendPassInput) pairs.aprs_send_passcode = sendPassInput.value;
            if (serverInput)   pairs.aprs_is_server     = serverInput.value || 'rotate.aprs2.net';
            if (portInput)     pairs.aprs_is_port       = portInput.value || '14580';

            apiPost('settings', { settings: pairs }).then(function (data) {
                showAlert('APRS settings saved (' + data.saved + ' updated)');
            }).catch(function (err) {
                showAlert(err.message, 'danger');
            });
        });

        // Helper — apply the accepted/unaccepted state to the form UI.
        // accepted_at: empty string = not yet accepted; otherwise a datetime
        // accepted_by: a user name (or empty)
        function _aprsApplyLicenseState(acceptedAt, acceptedBy) {
            var gate     = document.getElementById('aprsLicenseGate');
            var accepted = document.getElementById('aprsLicenseAccepted');
            var detail   = document.getElementById('aprsLicenseAcceptedDetail');
            var fieldset = document.getElementById('aprsSendFieldset');
            if (acceptedAt) {
                if (gate)     gate.style.display = 'none';
                if (accepted) accepted.style.display = '';
                if (detail)   detail.textContent = ' (' + acceptedAt + (acceptedBy ? ' by ' + acceptedBy : '') + ')';
                if (fieldset) fieldset.disabled = false;
            } else {
                if (gate)     gate.style.display = '';
                if (accepted) accepted.style.display = 'none';
                if (fieldset) fieldset.disabled = true;
            }
        }

        // Phase 99a #14 follow-on (2026-06-28) — FCC license attestation.
        // Checkbox enables the Accept button; clicking Accept POSTs the
        // attestation timestamp + user (server logs to audit_log + writes
        // settings). On success, swap gate → accepted-confirmation +
        // unlock the fieldset + prefill server/port defaults.
        var licenseCheck   = document.getElementById('aprsLicenseAccept');
        var licenseBtn     = document.getElementById('btnAprsAcceptLicense');
        if (licenseCheck && licenseBtn) {
            licenseCheck.addEventListener('change', function () {
                licenseBtn.disabled = !licenseCheck.checked;
            });
            licenseBtn.addEventListener('click', function () {
                if (!licenseCheck.checked) return;
                licenseBtn.disabled = true;
                licenseBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Recording acceptance...';
                fetch('api/aprs-license-accept.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': getCsrf()
                    },
                    body: JSON.stringify({})
                })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.error) {
                        showAlert('Acceptance failed: ' + data.error, 'danger');
                        licenseBtn.disabled = false;
                        licenseBtn.innerHTML = '<i class="bi bi-check-lg me-1"></i>Accept and unlock APRS sending';
                        return;
                    }
                    // Server returns accepted_at + accepted_by. Apply
                    // local UI immediately so admin can start typing.
                    _aprsApplyLicenseState(data.accepted_at, data.accepted_by);
                    // Prefill server + port defaults (only if blank).
                    var srv  = form.querySelector('[data-key="aprs_is_server"]');
                    var port = form.querySelector('[data-key="aprs_is_port"]');
                    if (srv  && !srv.value)  srv.value  = 'rotate.aprs2.net';
                    if (port && !port.value) port.value = '14580';
                    showAlert('License attestation recorded. APRS sending unlocked.', 'success');
                })
                .catch(function (err) {
                    showAlert('Network error: ' + err.message, 'danger');
                    licenseBtn.disabled = false;
                    licenseBtn.innerHTML = '<i class="bi bi-check-lg me-1"></i>Accept and unlock APRS sending';
                });
            });
        }

        // Phase 99a #13 (2026-06-28) — Compute Passcode button.
        // Calls /api/aprs-passcode.php with the typed callsign and
        // fills the passcode field. Auto-fires on callsign blur too
        // so the admin doesn't have to remember to click the button.
        var btnPass = document.getElementById('btnAprsComputePass');
        var callsignInput = document.getElementById('setAprsSendCallsign');
        var passcodeInput = document.getElementById('setAprsSendPasscode');
        function computeAprsPasscode() {
            if (!callsignInput || !passcodeInput) return;
            var cs = callsignInput.value.trim().toUpperCase();
            callsignInput.value = cs;  // normalize input
            if (!cs) {
                passcodeInput.value = '';
                return;
            }
            fetch('api/aprs-passcode.php?callsign=' + encodeURIComponent(cs), {
                credentials: 'same-origin'
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.error) {
                    showAlert('Passcode: ' + data.error, 'warning');
                    passcodeInput.value = '';
                    return;
                }
                passcodeInput.value = data.passcode;
            })
            .catch(function (err) {
                showAlert('Passcode lookup failed: ' + err.message, 'danger');
            });
        }
        if (btnPass) btnPass.addEventListener('click', computeAprsPasscode);
        if (callsignInput) callsignInput.addEventListener('blur', computeAprsPasscode);

        // Test button
        var testBtn = document.getElementById('btnAprsTest');
        var testResult = document.getElementById('aprsTestResult');
        if (testBtn) {
            testBtn.addEventListener('click', function () {
                var callsign = document.getElementById('aprsTestCallsign').value.trim();
                var apiKeyInput = form.querySelector('[data-key="aprs_fi_api_key"]');
                var apiKey = apiKeyInput ? apiKeyInput.value.trim() : '';

                if (!callsign) {
                    showAlert('Enter a callsign to test', 'warning');
                    return;
                }
                if (!apiKey) {
                    showAlert('Enter an API key first', 'warning');
                    return;
                }

                testBtn.disabled = true;
                testBtn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Testing...';

                if (testResult) {
                    testResult.style.display = 'block';
                    testResult.innerHTML = '<div class="alert alert-info small py-1"><i class="bi bi-hourglass-split me-1"></i>Querying aprs.fi...</div>';
                }

                // Direct call to location API for APRS test
                fetchJSON('api/location.php', {
                    method: 'POST',
                    body: JSON.stringify({
                        csrf_token: csrfToken,
                        action: 'test_aprs',
                        callsign: callsign,
                        api_key: apiKey
                    })
                }).then(function (data) {
                    testBtn.disabled = false;
                    testBtn.innerHTML = '<i class="bi bi-broadcast me-1"></i>Test';

                    if (data.position) {
                        var p = data.position;
                        var html = '<div class="alert alert-success small py-1 mb-0">';
                        html += '<strong>' + esc(p.name || callsign) + '</strong><br>';
                        html += 'Lat: ' + p.lat + ', Lng: ' + p.lng + '<br>';
                        if (p.speed !== undefined) html += 'Speed: ' + p.speed + ' km/h<br>';
                        if (p.altitude !== undefined) html += 'Alt: ' + p.altitude + ' m<br>';
                        if (p.lasttime) html += 'Last seen: ' + new Date(p.lasttime * 1000).toLocaleString();
                        html += '</div>';
                        if (testResult) testResult.innerHTML = html;
                    } else {
                        if (testResult) testResult.innerHTML = '<div class="alert alert-warning small py-1 mb-0">No position data found for ' + esc(callsign) + '</div>';
                    }
                }).catch(function (err) {
                    testBtn.disabled = false;
                    testBtn.innerHTML = '<i class="bi bi-broadcast me-1"></i>Test';
                    if (testResult) testResult.innerHTML = '<div class="alert alert-danger small py-1 mb-0">' + esc(err.message) + '</div>';
                });
            });
        }

        // 2026-06-29 — Listener Status section. Hits the existing
        // api/aprs-positions.php which already returns listener_status
        // + last_seen_ago_sec + station count. Refreshes every 15s
        // while the panel is open.
        _aprsLoadListenerStatus();
        if (window._aprsStatusTimer) clearInterval(window._aprsStatusTimer);
        window._aprsStatusTimer = setInterval(_aprsLoadListenerStatus, 15000);

        // 2026-06-29 — Receive Filter map picker. Eric beta UX ask.
        _aprsBindFilterMapPicker();

        // 2026-06-29 — APRS Map tab. Lazy-init Leaflet on first show
        // (Leaflet needs the container to have a real size, which
        // tab-content doesn't give it until the tab is shown).
        _aprsBindMapTab();
    }

    // ────────────────────────────────────────────────────────────────
    // APRS Map tab — inline live view of received stations.
    //   Modeled after aprs-map.php but embedded in the Settings panel
    //   tab so admins can confirm the listener is healthy without
    //   navigating away. Same /api/aprs-positions.php backend.
    // ────────────────────────────────────────────────────────────────
    function _aprsBindMapTab() {
        var tabBtn = document.getElementById('aprs-tab-map');
        var mapDiv = document.getElementById('aprsTabMap');
        if (!tabBtn || !mapDiv) return;
        if (tabBtn.dataset.bound === '1') return;
        tabBtn.dataset.bound = '1';

        var map = null;
        var markers = {};        // callsign → L.circleMarker
        var refreshTimer = null;

        function init() {
            // 2026-06-29 (Eric beta) — restore persisted height
            // BEFORE Leaflet initializes so the map sees the right
            // container size from the start.
            var wrap = document.getElementById('aprsTabMapWrap');
            var savedH = parseInt(localStorage.getItem('aprsTabMapHeight'), 10);
            if (wrap && savedH && savedH >= 200) {
                wrap.style.height = savedH + 'px';
            }

            map = L.map('aprsTabMap', { zoomControl: true }).setView([45.0, -93.0], 7);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; OpenStreetMap', maxZoom: 18
            }).addTo(map);

            var sinceSel = document.getElementById('aprsTabMapSince');
            if (sinceSel) sinceSel.addEventListener('change', loadStations);

            // 2026-06-29 (Eric beta) — resizable map. CSS `resize:
            // vertical` on the wrapper provides the drag handle in
            // the bottom-right corner. ResizeObserver catches the
            // size change, debounces, and tells Leaflet to redraw
            // tiles. Persist the new height to localStorage.
            if (wrap && typeof ResizeObserver !== 'undefined') {
                var resizeTimer = null;
                var ro = new ResizeObserver(function () {
                    if (resizeTimer) clearTimeout(resizeTimer);
                    resizeTimer = setTimeout(function () {
                        if (map) map.invalidateSize();
                        var h = wrap.offsetHeight;
                        if (h >= 200) localStorage.setItem('aprsTabMapHeight', String(h));
                    }, 120);
                });
                ro.observe(wrap);
            }

            // Reset link — back to default 420px
            var resetLink = document.getElementById('aprsTabMapResetSize');
            if (resetLink) {
                resetLink.addEventListener('click', function (e) {
                    e.preventDefault();
                    if (wrap) wrap.style.height = '420px';
                    localStorage.removeItem('aprsTabMapHeight');
                    setTimeout(function () { if (map) map.invalidateSize(); }, 100);
                });
            }
            // Fit viewport link — grow to ~90vh (the max-height cap)
            var fitLink = document.getElementById('aprsTabMapFitVH');
            if (fitLink) {
                fitLink.addEventListener('click', function (e) {
                    e.preventDefault();
                    var h = Math.floor(window.innerHeight * 0.9);
                    if (wrap) wrap.style.height = h + 'px';
                    localStorage.setItem('aprsTabMapHeight', String(h));
                    setTimeout(function () { if (map) map.invalidateSize(); }, 100);
                });
            }
        }

        function ageColor(sec) {
            if (sec == null) return '#6c757d';
            if (sec < 300)   return '#198754';   // green <5min
            if (sec < 1800)  return '#ffc107';   // yellow <30min
            return '#6c757d';                    // grey older
        }
        function ageText(sec) {
            if (sec == null) return 'unknown';
            if (sec < 60)    return sec + 's';
            if (sec < 3600)  return Math.floor(sec / 60) + 'm';
            if (sec < 86400) return Math.floor(sec / 3600) + 'h';
            return Math.floor(sec / 86400) + 'd';
        }
        function escH(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }

        function loadStations() {
            if (!map) return;
            var since = (document.getElementById('aprsTabMapSince') || {}).value || '60';
            fetch('api/aprs-positions.php?since_min=' + encodeURIComponent(since) + '&limit=500',
                  { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    var statusEl = document.getElementById('aprsTabMapStatus');
                    var countEl  = document.getElementById('aprsTabMapCount');
                    if (data.error) {
                        if (statusEl) { statusEl.className = 'badge bg-danger'; statusEl.textContent = 'Error'; }
                        return;
                    }
                    var stations = data.stations || [];
                    var status = data.listener_status || 'unknown';

                    if (statusEl) {
                        if (status === 'running') {
                            statusEl.className = 'badge bg-success';
                            statusEl.innerHTML = '<i class="bi bi-circle-fill me-1" style="font-size:0.5rem;vertical-align:middle;"></i>Listener active';
                        } else if (status === 'stopped') {
                            statusEl.className = 'badge bg-warning text-dark';
                            statusEl.textContent = 'Listener stale';
                        } else {
                            statusEl.className = 'badge bg-secondary';
                            statusEl.textContent = 'Listener not configured';
                        }
                    }
                    if (countEl) {
                        countEl.textContent = stations.length + ' station' +
                            (stations.length === 1 ? '' : 's') + ' in window';
                    }

                    // Clear old + redraw
                    Object.keys(markers).forEach(function (k) { map.removeLayer(markers[k]); });
                    markers = {};

                    stations.forEach(function (s) {
                        var color = ageColor(s.age_sec);
                        var m = L.circleMarker([s.lat, s.lng], {
                            radius: 7, fillColor: color, color: '#fff',
                            weight: 1.5, fillOpacity: 0.85
                        }).addTo(map);
                        m.bindPopup(
                            '<div><strong>' + escH(s.callsign) + '</strong></div>' +
                            '<div class="small text-body-secondary">' +
                                s.lat.toFixed(5) + ', ' + s.lng.toFixed(5) + '</div>' +
                            (s.speed > 0 ? '<div class="small">' + s.speed.toFixed(1) + ' kph · ' + s.heading + '°</div>' : '') +
                            '<div class="small">' + ageText(s.age_sec) + ' ago</div>'
                        );
                        markers[s.callsign] = m;
                    });

                    // Fit bounds on first load with data
                    if (stations.length > 0 && !map._aprsTabBoundsSet) {
                        map.fitBounds(L.latLngBounds(stations.map(function (s) { return [s.lat, s.lng]; })),
                                      { padding: [20, 20], maxZoom: 11 });
                        map._aprsTabBoundsSet = true;
                    }
                })
                .catch(function () { /* network blip — silent, retry on next interval */ });
        }

        // Bootstrap fires shown.bs.tab AFTER the tab is fully visible.
        // First-show: init + load. Subsequent shows: just re-load and
        // start the auto-refresh timer.
        tabBtn.addEventListener('shown.bs.tab', function () {
            if (!map) init();
            setTimeout(function () {
                if (map) map.invalidateSize();
                loadStations();
            }, 100);
            if (refreshTimer) clearInterval(refreshTimer);
            refreshTimer = setInterval(loadStations, 60000);
        });

        // Stop the interval when the tab is hidden so we're not
        // pulling /api/aprs-positions.php while admin is on a
        // different tab.
        tabBtn.addEventListener('hidden.bs.tab', function () {
            if (refreshTimer) { clearInterval(refreshTimer); refreshTimer = null; }
        });
    }

    // ────────────────────────────────────────────────────────────────
    // APRS Receive Filter — map picker modal
    //   Click map → marker drops + blue circle shows radius coverage.
    //   Radius input updates the circle live.
    //   Pre-populates from the current input value on open (parses
    //   r/LAT/LNG/KM if present; falls back to Twin Cities default).
    //   Save formats as "r/LAT/LNG/KM" with lat/lng rounded to 2
    //   decimals (~1.1km — plenty for a whole-km radius filter).
    //   Preserves any non-radius operators in the input (e.g.
    //   t/poimqs added after the radius is left intact).
    // ────────────────────────────────────────────────────────────────
    function _aprsBindFilterMapPicker() {
        var modalEl = document.getElementById('aprsFilterMapModal');
        var input   = document.getElementById('setAprsRecvFilter');
        if (!modalEl || !input) return;
        if (modalEl.dataset.bound === '1') return;   // bind once
        modalEl.dataset.bound = '1';

        var map = null;          // Leaflet map
        var marker = null;       // center marker
        var circle = null;       // radius circle
        var center = null;       // L.latLng
        var radius = 200;        // km

        var radiusInput  = document.getElementById('aprsFilterRadius');
        var centerLabel  = document.getElementById('aprsFilterCenter');
        var previewLabel = document.getElementById('aprsFilterPreview');
        var applyBtn     = document.getElementById('btnAprsFilterApply');

        function fmt(n) { return (Math.round(n * 100) / 100).toFixed(2); }

        function buildFilterString(c, r) {
            if (!c) return '';
            return 'r/' + fmt(c.lat) + '/' + fmt(c.lng) + '/' + Math.round(r);
        }

        function render() {
            if (!map) return;
            if (center) {
                if (!marker) {
                    marker = L.marker(center, { draggable: true }).addTo(map);
                    marker.on('drag', function (e) {
                        center = e.latlng;
                        if (circle) circle.setLatLng(center);
                        update();
                    });
                } else {
                    marker.setLatLng(center);
                }
                if (!circle) {
                    circle = L.circle(center, {
                        radius: radius * 1000,
                        color: '#0d6efd', weight: 2,
                        fillColor: '#0d6efd', fillOpacity: 0.1
                    }).addTo(map);
                } else {
                    circle.setLatLng(center);
                    circle.setRadius(radius * 1000);
                }
            }
            update();
        }

        function update() {
            if (center) {
                centerLabel.textContent = fmt(center.lat) + ', ' + fmt(center.lng);
                previewLabel.textContent = buildFilterString(center, radius);
                applyBtn.disabled = false;
            } else {
                centerLabel.textContent = 'click map to set';
                previewLabel.textContent = '—';
                applyBtn.disabled = true;
            }
        }

        // Build map on first show. Bootstrap's 'shown.bs.modal' fires
        // after the slide-in animation so the container has its final
        // size — that's when Leaflet's invalidateSize is happy.
        modalEl.addEventListener('shown.bs.modal', function () {
            if (!map) {
                map = L.map('aprsFilterMap', { zoomControl: true }).setView([45.0, -93.0], 6);
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    attribution: '&copy; OpenStreetMap', maxZoom: 18
                }).addTo(map);
                map.on('click', function (e) {
                    center = e.latlng;
                    render();
                });
            }

            // Parse current input value if it contains r/LAT/LNG/KM —
            // pre-populate so an existing filter is editable on open.
            // Match the actual r/ operator anywhere in the filter
            // string (it might be combined with other operators like
            // "r/45/-93/200 b/N0NKI-10").
            var current = (input.value || '').trim();
            var m = current.match(/r\/(-?\d+\.?\d*)\/(-?\d+\.?\d*)\/(\d+)/);
            if (m) {
                center = L.latLng(parseFloat(m[1]), parseFloat(m[2]));
                radius = parseInt(m[3], 10);
                radiusInput.value = radius;
                map.setView(center, 7);
            } else {
                center = null;
                radius = parseInt(radiusInput.value, 10) || 200;
            }
            // Remove any stale layers from a previous open (Leaflet
            // doesn't auto-clear when modal closes).
            if (marker) { map.removeLayer(marker); marker = null; }
            if (circle) { map.removeLayer(circle); circle = null; }

            // Bootstrap modal animation can leave Leaflet with a stale
            // pane size — force a tile refresh after the transition.
            setTimeout(function () { map.invalidateSize(); render(); }, 100);
        });

        // Radius input — live update the circle as user types.
        if (radiusInput) {
            radiusInput.addEventListener('input', function () {
                var v = parseInt(this.value, 10);
                if (isNaN(v) || v < 1) return;
                radius = v;
                render();
            });
        }

        // Apply — write the new filter to the panel input, preserving
        // any non-radius operators that were already there. Then close.
        if (applyBtn) {
            applyBtn.addEventListener('click', function () {
                if (!center) return;
                var newRadius = buildFilterString(center, radius);
                var existing = (input.value || '').trim();
                // Replace existing r/... in place if present, else
                // prepend. Keeps other operators (p/, b/, t/, etc.)
                // intact.
                var combined;
                if (/r\/(-?\d+\.?\d*)\/(-?\d+\.?\d*)\/(\d+)/.test(existing)) {
                    combined = existing.replace(/r\/(-?\d+\.?\d*)\/(-?\d+\.?\d*)\/(\d+)/, newRadius);
                } else if (existing === '') {
                    combined = newRadius;
                } else {
                    combined = newRadius + ' ' + existing;
                }
                input.value = combined;
                // Fire input + change so any save-on-change listeners
                // pick it up.
                input.dispatchEvent(new Event('input', { bubbles: true }));
                input.dispatchEvent(new Event('change', { bubbles: true }));
                bootstrap.Modal.getInstance(modalEl).hide();
                if (typeof showAlert === 'function') {
                    showAlert('Filter updated. Click "Save APRS Settings" to persist.', 'success');
                }
            });
        }
    }

    function _aprsLoadListenerStatus() {
        var card = document.getElementById('aprsListenerStatusCard');
        if (!card) return;
        fetch('api/aprs-positions.php?since_min=60&limit=1', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.error) {
                    card.innerHTML = '<div class="text-danger small">' + esc(data.error) + '</div>';
                    return;
                }
                var status = data.listener_status || 'unknown';
                var lastSeenAgo = (data.provider && data.provider.last_seen_ago_sec != null)
                    ? data.provider.last_seen_ago_sec : null;
                // 2026-06-29 fix: use unique_stations_in_window (true
                // distinct count in window) instead of count (which is
                // capped by LIMIT — Listener Status calls with limit=1).
                var count = (data.unique_stations_in_window != null)
                    ? data.unique_stations_in_window
                    : (data.count || 0);

                var badge, msg;
                if (status === 'running') {
                    badge = '<span class="badge bg-success"><i class="bi bi-circle-fill me-1" style="font-size:0.5rem;vertical-align:middle;"></i>Running</span>';
                    msg = 'Receiving positions. Last packet ' + _aprsFormatAge(lastSeenAgo) + ' ago. ' +
                          count + ' unique station' + (count === 1 ? '' : 's') + ' heard in the last hour.';
                } else if (status === 'stopped') {
                    badge = '<span class="badge bg-warning text-dark">Stale</span>';
                    msg = 'Listener was last receiving ' + _aprsFormatAge(lastSeenAgo) + ' ago. ' +
                          'May have been restarted or APRS-IS may be quiet. Restart with: ' +
                          '<code>sudo systemctl restart ticketscad-aprs-listener.service</code>';
                } else if (status === 'not_configured') {
                    badge = '<span class="badge bg-secondary">Not configured</span>';
                    msg = 'No APRS-IS position rows ever received. Set the callsign + passcode above and save — ' +
                          'the listener picks up credentials within 60 seconds.';
                } else {
                    badge = '<span class="badge bg-secondary">Unknown</span>';
                    msg = '';
                }

                card.innerHTML =
                    '<div class="d-flex align-items-center mb-2">' + badge +
                    '<span class="ms-2 small text-body-secondary">ticketscad-aprs-listener.service</span></div>' +
                    '<div class="small">' + msg + '</div>';
            })
            .catch(function (err) {
                card.innerHTML = '<div class="text-danger small">Status fetch failed: ' + esc(err.message) + '</div>';
            });
    }

    function _aprsFormatAge(sec) {
        if (sec == null) return 'an unknown time';
        if (sec < 60) return sec + 's';
        if (sec < 3600) return Math.floor(sec / 60) + 'm';
        if (sec < 86400) return Math.floor(sec / 3600) + 'h';
        return Math.floor(sec / 86400) + 'd';
    }

    // ═══════════════════════════════════════════════════════════════
    //  LOCATION DATA RETENTION PANEL
    // ═══════════════════════════════════════════════════════════════
    function bindLocationRetentionPanel() {
        var form = document.getElementById('locationRetentionForm');
        if (!form) return;

        // Load current retention settings
        apiGet('settings').then(function (data) {
            var settings = data.settings || {};
            var retInput = form.querySelector('[data-key="location_retention_days"]');
            if (retInput && settings.location_retention_days) {
                retInput.value = settings.location_retention_days;
            }
        }).catch(function () {
            // Settings not available yet
        });

        // Save handler
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var retInput = form.querySelector('[data-key="location_retention_days"]');
            var pairs = {};
            if (retInput) pairs.location_retention_days = retInput.value || '90';

            apiPost('settings', { settings: pairs }).then(function (data) {
                showAlert('Retention settings saved (' + data.saved + ' updated)');
            }).catch(function (err) {
                showAlert(err.message, 'danger');
            });
        });
    }

    // ═══════════════════════════════════════════════════════════════
    //  LOCATION INGEST (Phase 89)
    //  - Auth settings for native Traccar / OpenGTS receiver
    //  - Per-device token CRUD
    //  - Recent reports table for verification
    // ═══════════════════════════════════════════════════════════════
    function bindLocationIngestPanel() {
        var form = document.getElementById('locationIngestSettingsForm');
        if (!form) return;

        var INGEST_API = 'api/location-ingest.php';

        function fmtAge(seconds) {
            if (seconds === null || seconds === undefined) return '';
            seconds = parseInt(seconds, 10);
            if (seconds < 60) return seconds + 's ago';
            if (seconds < 3600) return Math.floor(seconds / 60) + 'm ago';
            if (seconds < 86400) return Math.floor(seconds / 3600) + 'h ago';
            return Math.floor(seconds / 86400) + 'd ago';
        }

        // ── Load settings ───────────────────────────────────────
        function loadIngestSettings() {
            apiGet('settings').then(function (data) {
                var s = data.settings || {};
                var requireT = form.querySelector('#setLocIngestRequireToken');
                var nullI    = form.querySelector('#setLocIngestNullIsland');
                var secret   = form.querySelector('#setLocIngestSecret');
                var rate     = form.querySelector('#setLocIngestRateLimit');
                if (requireT) requireT.checked = (s.location_ingest_require_token === '1');
                if (nullI)    nullI.checked    = (s.location_ingest_allow_null_island === '1');
                if (secret)   secret.value     = s.location_ingest_secret || '';
                if (rate)     rate.value       = s.location_ingest_rate_limit_per_min || '600';
            }).catch(function () {});
        }
        loadIngestSettings();

        // ── Save settings ───────────────────────────────────────
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var pairs = {
                location_ingest_require_token:      form.querySelector('#setLocIngestRequireToken').checked ? '1' : '0',
                location_ingest_allow_null_island:  form.querySelector('#setLocIngestNullIsland').checked ? '1' : '0',
                location_ingest_secret:             form.querySelector('#setLocIngestSecret').value || '',
                location_ingest_rate_limit_per_min: form.querySelector('#setLocIngestRateLimit').value || '600'
            };
            apiPost('settings', { settings: pairs }).then(function (data) {
                showAlert('Ingest auth settings saved (' + data.saved + ' updated)');
            }).catch(function (err) {
                showAlert(err.message, 'danger');
            });
        });

        // ── Generate secret button ──────────────────────────────
        var genBtn = document.getElementById('btnGenIngestSecret');
        if (genBtn) {
            genBtn.addEventListener('click', function () {
                // 32 bytes from crypto.getRandomValues, base64url encoded.
                var bytes = new Uint8Array(32);
                (window.crypto || window.msCrypto).getRandomValues(bytes);
                var b64 = btoa(String.fromCharCode.apply(null, bytes))
                    .replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
                document.getElementById('setLocIngestSecret').value = b64;
            });
        }

        // ── Token list ──────────────────────────────────────────
        function loadTokens() {
            var body = document.getElementById('ingestTokensBody');
            if (!body) return;
            fetchJSON(INGEST_API + '?action=tokens').then(function (data) {
                var rows = data.tokens || [];
                if (!rows.length) {
                    body.innerHTML = '<tr><td colspan="7" class="text-body-secondary small text-center py-3">No per-device tokens yet — click Mint Token to create one.</td></tr>';
                    return;
                }
                var html = '';
                for (var i = 0; i < rows.length; i++) {
                    var t = rows[i];
                    var statusBadge = t.revoked_at
                        ? '<span class="badge bg-danger">Revoked</span>'
                        : '<span class="badge bg-success">Active</span>';
                    var actions = t.revoked_at
                        ? ''
                        : '<button class="btn btn-sm btn-outline-danger ingest-revoke-btn" data-id="' + t.id + '" data-label="' + esc(t.label) + '"><i class="bi bi-x-circle me-1"></i>Revoke</button>';
                    var providerLabel = t.provider_id
                        ? (t.provider_name || t.provider_code || '#' + t.provider_id)
                        : '<span class="text-body-secondary">any</span>';
                    html += '<tr>'
                          + '<td>' + esc(t.label) + (t.notes ? '<div class="small text-body-secondary">' + esc(t.notes) + '</div>' : '') + '</td>'
                          + '<td>' + providerLabel + '</td>'
                          + '<td><code>' + esc(t.device_unique_id || '—') + '</code></td>'
                          + '<td>' + (t.report_count || 0) + '</td>'
                          + '<td>' + (t.last_used_at ? esc(t.last_used_at) + (t.last_used_ip ? '<div class="small text-body-secondary">' + esc(t.last_used_ip) + '</div>' : '') : '<span class="text-body-secondary">never</span>') + '</td>'
                          + '<td>' + statusBadge + (t.revoked_at ? '<div class="small text-body-secondary">' + esc(t.revoked_at) + '</div>' : '') + '</td>'
                          + '<td class="text-end">' + actions + '</td>'
                          + '</tr>';
                }
                body.innerHTML = html;

                // Wire revoke buttons.
                var btns = body.querySelectorAll('.ingest-revoke-btn');
                for (var j = 0; j < btns.length; j++) {
                    btns[j].addEventListener('click', function () {
                        var id = this.getAttribute('data-id');
                        var label = this.getAttribute('data-label');
                        if (!confirm('Revoke token "' + label + '"?\n\nThe device using this token will start getting 401 errors immediately.')) return;
                        fetchJSON(INGEST_API + '?action=revoke_token', {
                            method: 'POST',
                            body: JSON.stringify({ id: parseInt(id, 10), csrf_token: csrfToken })
                        }).then(function () {
                            showAlert('Token revoked');
                            loadTokens();
                        }).catch(function (err) {
                            showAlert(err.message, 'danger');
                        });
                    });
                }
            }).catch(function () {
                body.innerHTML = '<tr><td colspan="7" class="text-danger small text-center py-3">Failed to load tokens. The location_ingest_tokens table may be missing — run sql/run_location_ingest_tokens.php.</td></tr>';
            });
        }
        loadTokens();

        // ── Mint token modal ────────────────────────────────────
        var mintBtn = document.getElementById('btnMintIngestToken');
        var mintModal = document.getElementById('ingestMintModal');
        var mintForm = document.getElementById('ingestMintForm');
        var mintResult = document.getElementById('ingestMintResult');
        var mintResultToken = document.getElementById('ingestMintResultToken');
        var mintSubmit = document.getElementById('ingestMintSubmit');

        function resetMintModal() {
            mintForm.reset();
            mintResult.classList.add('d-none');
            mintSubmit.disabled = false;
            mintSubmit.classList.remove('d-none');
        }

        if (mintBtn && mintModal) {
            var bsMintModal = new bootstrap.Modal(mintModal);
            mintBtn.addEventListener('click', function () {
                resetMintModal();
                bsMintModal.show();
            });
            mintModal.addEventListener('hidden.bs.modal', resetMintModal);

            // Phase 91 followup: provider-aware "Bound device unique ID"
            // field. The label/placeholder/hint should reflect what kind
            // of identifier the operator actually pastes — TID for
            // OwnTracks (2-char), IMEI-ish for Traccar/OpenGTS. Without
            // this, an operator coming from the OwnTracks docs sees an
            // IMEI placeholder and assumes the field is for something
            // they don't have.
            var providerSel = mintForm.querySelector('[name="provider_code"]');
            var boundLabel  = document.getElementById('ingestMintBoundIdLabel');
            var boundInput  = document.getElementById('ingestMintBoundIdInput');
            var boundHint   = document.getElementById('ingestMintBoundIdHint');
            function updateBoundIdHint() {
                if (!providerSel || !boundLabel) return;
                switch (providerSel.value) {
                    case 'owntracks':
                        boundLabel.textContent = 'Bound TID (optional)';
                        boundInput.placeholder = 'EJ';
                        boundInput.setAttribute('maxlength', '2');
                        boundHint.innerHTML =
                            'OwnTracks Tracker ID (2 chars, e.g. <code>EJ</code>). ' +
                            'When set, the token only authenticates this TID. ' +
                            'Leave blank to share one token across multiple OwnTracks devices.';
                        break;
                    case 'traccar':
                    case 'opengts':
                        boundLabel.textContent = 'Bound device unique ID (optional)';
                        boundInput.placeholder = '863719010012345';
                        boundInput.setAttribute('maxlength', '120');
                        boundHint.innerHTML =
                            'Traccar uniqueId or hardware IMEI (typically 15 digits). ' +
                            'When set, the token is rejected unless the report\'s device id matches exactly.';
                        break;
                    default:
                        boundLabel.textContent = 'Bound device unique ID (optional)';
                        boundInput.placeholder = 'IMEI, TID, or device-specific id';
                        boundInput.setAttribute('maxlength', '120');
                        boundHint.innerHTML =
                            'When set, the token is rejected unless the report\'s device id matches exactly. ' +
                            'Shape depends on the provider you chose above.';
                }
            }
            if (providerSel) providerSel.addEventListener('change', updateBoundIdHint);
            // Run once when modal opens too
            mintBtn.addEventListener('click', updateBoundIdHint);

            mintSubmit.addEventListener('click', function () {
                var data = new FormData(mintForm);
                var body = {
                    label:            (data.get('label') || '').trim(),
                    provider_code:    (data.get('provider_code') || '').trim(),
                    device_unique_id: (data.get('device_unique_id') || '').trim(),
                    notes:            (data.get('notes') || '').trim(),
                    csrf_token:       csrfToken
                };
                if (!body.label) { showAlert('Label is required', 'warning'); return; }
                mintSubmit.disabled = true;
                fetchJSON(INGEST_API + '?action=mint_token', {
                    method: 'POST',
                    body: JSON.stringify(body)
                }).then(function (resp) {
                    mintResultToken.value = resp.token || '';
                    mintResult.classList.remove('d-none');
                    mintSubmit.classList.add('d-none'); // hide Mint button after success
                    loadTokens();
                }).catch(function (err) {
                    mintSubmit.disabled = false;
                    showAlert(err.message, 'danger');
                });
            });

            var copyBtn = document.getElementById('ingestMintResultCopy');
            if (copyBtn) {
                copyBtn.addEventListener('click', function () {
                    mintResultToken.select();
                    try {
                        document.execCommand('copy');
                        showAlert('Token copied to clipboard');
                    } catch (e) {
                        showAlert('Copy failed — select the text manually', 'warning');
                    }
                });
            }
        }

        // ── Recent reports ──────────────────────────────────────
        function loadRecentReports() {
            var body = document.getElementById('ingestReportsBody');
            if (!body) return;
            var filter = document.getElementById('ingestReportsFilter');
            var providerArg = filter && filter.value ? '&provider=' + encodeURIComponent(filter.value) : '';
            fetchJSON(INGEST_API + '?action=recent&limit=50' + providerArg).then(function (data) {
                var rows = data.reports || [];
                if (!rows.length) {
                    body.innerHTML = '<tr><td colspan="6" class="text-body-secondary small text-center py-3">No reports yet. After you point a device at <code>?provider=traccar</code> or <code>?provider=opengts</code>, the most recent reports appear here.</td></tr>';
                    return;
                }
                var html = '';
                for (var i = 0; i < rows.length; i++) {
                    var r = rows[i];
                    var color = r.provider_color || '#666';
                    var pBadge = '<span class="badge" style="background:' + esc(color) + ';">' + esc(r.provider_name || r.provider_code) + '</span>';
                    var coord = parseFloat(r.lat).toFixed(5) + ', ' + parseFloat(r.lng).toFixed(5);
                    var speed = (r.speed !== null && r.speed !== undefined) ? parseFloat(r.speed).toFixed(1) : '—';
                    var tokenCell = r.token_label
                        ? '<span class="badge bg-info text-dark" title="Per-device token">' + esc(r.token_label) + '</span>'
                        : (r.auth_token_id ? '<span class="text-body-secondary small">#' + r.auth_token_id + '</span>' : '<span class="text-body-secondary small">shared/none</span>');
                    html += '<tr>'
                          + '<td>' + pBadge + '</td>'
                          + '<td><code>' + esc(r.unit_identifier) + '</code></td>'
                          + '<td class="font-monospace small">' + coord + '</td>'
                          + '<td>' + speed + '</td>'
                          + '<td>' + tokenCell + '</td>'
                          + '<td><span title="' + esc(r.received_at) + '">' + fmtAge(r.age_seconds) + '</span></td>'
                          + '</tr>';
                }
                body.innerHTML = html;
            }).catch(function () {
                body.innerHTML = '<tr><td colspan="6" class="text-danger small text-center py-3">Failed to load reports.</td></tr>';
            });
        }

        var refreshBtn = document.getElementById('btnRefreshIngestReports');
        if (refreshBtn) refreshBtn.addEventListener('click', loadRecentReports);
        var filterSel = document.getElementById('ingestReportsFilter');
        if (filterSel) filterSel.addEventListener('change', loadRecentReports);
        loadRecentReports();
    }

    // ═══════════════════════════════════════════════════════════════
    //  OwnTracks Authentication (Phase 91 followup)
    //  Surfaces what used to be SQL-only:
    //    owntracks_require_token, owntracks_allow_anonymous, owntracks_secret
    //  Loads + saves via the same apiGet('settings') / apiPost('settings')
    //  pattern bindLocationIngestPanel uses.
    // ═══════════════════════════════════════════════════════════════
    function bindOwntracksAuthPanel() {
        var form = document.getElementById('otAuthForm');
        if (!form) return;

        // Initial load — populate from current settings
        apiGet('settings').then(function (data) {
            var s = data.settings || {};
            var req = form.querySelector('#setOtRequireToken');
            var anon = form.querySelector('#setOtAllowAnonymous');
            var sec = form.querySelector('#setOtSecret');
            if (req)  req.checked  = (s.owntracks_require_token === '1');
            if (anon) anon.checked = (s.owntracks_allow_anonymous === '1');
            if (sec)  sec.value    = s.owntracks_secret || '';
        }).catch(function () {});

        // Generate-secret button — same UX as the Phase 89 Location Ingest panel
        var genBtn = document.getElementById('btnGenOtSecret');
        if (genBtn) {
            genBtn.addEventListener('click', function () {
                var bytes = new Uint8Array(32);
                (window.crypto || window.msCrypto).getRandomValues(bytes);
                var b64 = btoa(String.fromCharCode.apply(null, bytes))
                    .replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
                document.getElementById('setOtSecret').value = b64;
            });
        }

        // Save
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var pairs = {
                owntracks_require_token:    form.querySelector('#setOtRequireToken').checked ? '1' : '0',
                owntracks_allow_anonymous:  form.querySelector('#setOtAllowAnonymous').checked ? '1' : '0',
                owntracks_secret:           form.querySelector('#setOtSecret').value || ''
            };
            apiPost('settings', { settings: pairs }).then(function (data) {
                showAlert('OwnTracks auth settings saved (' + data.saved + ' updated)');
            }).catch(function (err) {
                showAlert(err.message, 'danger');
            });
        });
    }

    // ═══════════════════════════════════════════════════════════════
    //  ATAK / TAK (Phase 91 — post-consolidation)
    //  - Channels = mesh_channels rows (Phase 35); we only edit
    //    the atak_* policy columns. Channel CRUD itself lives on
    //    mesh-console.php.
    //  - Recent events = mesh_packet_log filtered to ATAK ports.
    //  - Unbound uids = atak_unbound_uids (operator review queue).
    // ═══════════════════════════════════════════════════════════════
    function bindAtakTakPanel() {
        var panel = document.getElementById('panel-atak-tak');
        if (!panel) return;
        var ATAK_API = 'api/atak.php';

        function fmtAge(s) {
            if (s === null || s === undefined) return '';
            s = parseInt(s, 10);
            if (s < 60) return s + 's';
            if (s < 3600) return Math.floor(s/60) + 'm';
            if (s < 86400) return Math.floor(s/3600) + 'h';
            return Math.floor(s/86400) + 'd';
        }

        // ── Channels (mesh_channels rows + their ATAK policy) ──
        function loadChannels() {
            var body = document.getElementById('atakChannelsBody');
            if (!body) return;
            fetchJSON(ATAK_API + '?action=channels').then(function (d) {
                var rows = d.channels || [];
                if (!rows.length) {
                    body.innerHTML = '<tr><td colspan="8" class="text-body-secondary small text-center py-3">No mesh channels found. Create a channel under <a href="mesh-console.php">Mesh Console</a> first.</td></tr>';
                    return;
                }
                var html = '';
                for (var i = 0; i < rows.length; i++) {
                    var c = rows[i];
                    var atakOn = !!parseInt(c.atak_enabled, 10);
                    var pushes = [];
                    if (parseInt(c.atak_push_incidents,10))  pushes.push('inc');
                    if (parseInt(c.atak_push_units,10))      pushes.push('units');
                    if (parseInt(c.atak_push_facilities,10)) pushes.push('fac');
                    if (parseInt(c.atak_push_chat,10))       pushes.push('chat');
                    var atakBadge = atakOn
                        ? '<span class="badge bg-success">Enabled</span>'
                        : '<span class="badge bg-secondary">Off</span>';
                    var sensBadge = atakOn
                        ? (parseInt(c.atak_sensitive_flag,10)
                            ? '<span class="badge bg-warning text-dark">PII strip</span>'
                            : '<span class="badge bg-info text-dark">Full data</span>')
                        : '<span class="text-body-secondary small">—</span>';
                    html += '<tr>'
                          + '<td>' + esc(c.name) + (parseInt(c.is_primary,10) ? ' <span class="badge bg-primary">primary</span>' : '') + '</td>'
                          + '<td>' + (parseInt(c.bridge_count,10) || 0) + '</td>'
                          + '<td>' + atakBadge + '</td>'
                          + '<td>' + sensBadge + '</td>'
                          + '<td><span class="small">' + (atakOn ? (pushes.join(', ') || '(none)') : '—') + '</span></td>'
                          + '<td>' + (atakOn ? '<code class="small">' + esc(c.atak_marker_action || '') + '</code>' : '—') + '</td>'
                          + '<td class="small">' + (atakOn ? c.atak_position_min_secs + 's / ' + c.atak_position_min_m + 'm' : '—') + '</td>'
                          + '<td class="text-end"><button class="btn btn-sm btn-outline-primary atak-chan-edit" data-id="' + c.id + '">Edit</button></td>'
                          + '</tr>';
                }
                body.innerHTML = html;
                var btns = body.querySelectorAll('.atak-chan-edit');
                for (var j = 0; j < btns.length; j++) {
                    btns[j].addEventListener('click', function () {
                        var id = this.getAttribute('data-id');
                        var row = null;
                        for (var k = 0; k < rows.length; k++) if (rows[k].id == id) row = rows[k];
                        openChannelModal(row);
                    });
                }
            }).catch(function () {
                body.innerHTML = '<tr><td colspan="8" class="text-danger small text-center py-3">Failed to load. mesh_channels may not have ATAK columns — run sql/run_atak_consolidation.php.</td></tr>';
            });
        }

        function openChannelModal(row) {
            if (!row) return;
            var modal = document.getElementById('atakChannelModal');
            if (!modal) return;
            var form = document.getElementById('atakChannelForm');
            form.reset();
            form.elements['id'].value = row.id;
            document.getElementById('atakChanName').textContent = row.name;
            form.elements['atak_enabled'].checked         = !!parseInt(row.atak_enabled || 0, 10);
            form.elements['atak_sensitive_flag'].checked  = !!parseInt(row.atak_sensitive_flag || 1, 10);
            form.elements['atak_push_incidents'].checked  = !!parseInt(row.atak_push_incidents || 1, 10);
            form.elements['atak_push_units'].checked      = !!parseInt(row.atak_push_units || 1, 10);
            form.elements['atak_push_facilities'].checked = !!parseInt(row.atak_push_facilities || 0, 10);
            form.elements['atak_push_chat'].checked       = !!parseInt(row.atak_push_chat || 1, 10);
            form.elements['atak_marker_action'].value     = row.atak_marker_action || 'new_incident';
            form.elements['atak_position_min_secs'].value = row.atak_position_min_secs || 60;
            form.elements['atak_position_min_m'].value    = row.atak_position_min_m || 25;
            new bootstrap.Modal(modal).show();
        }

        var refreshBtn = document.getElementById('btnRefreshAtakChannels');
        if (refreshBtn) refreshBtn.addEventListener('click', loadChannels);

        var saveBtn = document.getElementById('atakChannelSubmit');
        if (saveBtn) saveBtn.addEventListener('click', function () {
            var form = document.getElementById('atakChannelForm');
            var body = {
                id:                     parseInt(form.elements['id'].value || '0', 10),
                atak_enabled:           form.elements['atak_enabled'].checked ? 1 : 0,
                atak_sensitive_flag:    form.elements['atak_sensitive_flag'].checked ? 1 : 0,
                atak_push_incidents:    form.elements['atak_push_incidents'].checked ? 1 : 0,
                atak_push_units:        form.elements['atak_push_units'].checked ? 1 : 0,
                atak_push_facilities:   form.elements['atak_push_facilities'].checked ? 1 : 0,
                atak_push_chat:         form.elements['atak_push_chat'].checked ? 1 : 0,
                atak_marker_action:     form.elements['atak_marker_action'].value,
                atak_position_min_secs: parseInt(form.elements['atak_position_min_secs'].value || '60', 10),
                atak_position_min_m:    parseInt(form.elements['atak_position_min_m'].value || '25', 10),
                csrf_token:             csrfToken
            };
            if (!body.id) { showAlert('Channel id missing', 'warning'); return; }
            fetchJSON(ATAK_API + '?action=save_channel_atak', {
                method: 'POST',
                body: JSON.stringify(body)
            }).then(function () {
                showAlert('ATAK policy saved');
                bootstrap.Modal.getInstance(document.getElementById('atakChannelModal')).hide();
                loadChannels();
            }).catch(function (err) { showAlert(err.message, 'danger'); });
        });

        // ── Unbound uids ───────────────────────────────────────
        function loadUnbound() {
            var body = document.getElementById('atakUnboundBody');
            if (!body) return;
            fetchJSON(ATAK_API + '?action=unbound').then(function (d) {
                var rows = d.unbound || [];
                if (!rows.length) {
                    body.innerHTML = '<tr><td colspan="7" class="text-body-secondary small text-center py-3">No unbound ATAK devices. Anything calling in is already attributed.</td></tr>';
                    return;
                }
                var html = '';
                for (var i = 0; i < rows.length; i++) {
                    var u = rows[i];
                    html += '<tr>'
                          + '<td><code class="small">' + esc(u.atak_uid) + '</code></td>'
                          + '<td>' + esc(u.callsign_seen || '—') + '</td>'
                          + '<td><code>' + esc(u.transport) + '</code></td>'
                          + '<td>' + esc(u.channel_ref) + '</td>'
                          + '<td>' + u.position_count + '</td>'
                          + '<td><span class="small">' + esc(u.last_seen) + '</span></td>'
                          + '<td class="text-end"><button class="btn btn-sm btn-primary atak-bind-btn" data-uid="' + esc(u.atak_uid) + '"><i class="bi bi-link-45deg me-1"></i>Bind</button></td>'
                          + '</tr>';
                }
                body.innerHTML = html;
                var btns = body.querySelectorAll('.atak-bind-btn');
                for (var j = 0; j < btns.length; j++) {
                    btns[j].addEventListener('click', function () { openBindModal(this.getAttribute('data-uid')); });
                }
            }).catch(function () {
                body.innerHTML = '<tr><td colspan="7" class="text-body-secondary small text-center py-3">Failed to load — schema may not be applied.</td></tr>';
            });
        }

        function openBindModal(uid) {
            var modal = document.getElementById('atakBindModal');
            if (!modal) return;
            var form = document.getElementById('atakBindForm');
            form.elements['atak_uid'].value = uid;
            form.elements['uid_display'].value = uid;
            // Populate personnel dropdown
            var sel = document.getElementById('atakBindMember');
            sel.innerHTML = '<option value="">Loading…</option>';
            fetchJSON('api/members.php?short=1').then(function (d) {
                var members = d.members || d.rows || d || [];
                sel.innerHTML = '<option value="">-- pick personnel --</option>';
                for (var i = 0; i < members.length; i++) {
                    var m = members[i];
                    var label = (m.first_name || '') + ' ' + (m.last_name || '') + (m.callsign ? ' (' + m.callsign + ')' : '');
                    sel.innerHTML += '<option value="' + m.id + '">' + esc(label.trim() || ('#' + m.id)) + '</option>';
                }
            }).catch(function () {
                sel.innerHTML = '<option value="">(could not load personnel)</option>';
            });
            new bootstrap.Modal(modal).show();
        }

        var bindBtn = document.getElementById('atakBindSubmit');
        if (bindBtn) bindBtn.addEventListener('click', function () {
            var form = document.getElementById('atakBindForm');
            var uid = form.elements['atak_uid'].value;
            var mid = parseInt(form.elements['member_id'].value, 10);
            if (!mid) { showAlert('Pick a personnel record', 'warning'); return; }
            fetchJSON(ATAK_API + '?action=bind_unbound', {
                method: 'POST',
                body: JSON.stringify({ atak_uid: uid, member_id: mid, csrf_token: csrfToken })
            }).then(function () {
                showAlert('Bound');
                bootstrap.Modal.getInstance(document.getElementById('atakBindModal')).hide();
                loadUnbound();
            }).catch(function (err) { showAlert(err.message, 'danger'); });
        });

        var unboundRefresh = document.getElementById('btnRefreshAtakUnbound');
        if (unboundRefresh) unboundRefresh.addEventListener('click', loadUnbound);

        // ── Recent CoT events (rendered from mesh_packet_log) ──
        function loadRecent() {
            var body = document.getElementById('atakRecentBody');
            if (!body) return;
            fetchJSON(ATAK_API + '?action=recent&limit=50').then(function (d) {
                var rows = d.events || [];
                if (!rows.length) {
                    body.innerHTML = '<tr><td colspan="7" class="text-body-secondary small text-center py-3">No ATAK events yet. Once a device on an ATAK-enabled channel sends a position or marker, rows appear here.</td></tr>';
                    return;
                }
                var html = '';
                for (var i = 0; i < rows.length; i++) {
                    var e = rows[i];
                    var pos = (e.lat !== null && e.lng !== null)
                        ? '<span class="font-monospace small">' + parseFloat(e.lat).toFixed(5) + ', ' + parseFloat(e.lng).toFixed(5) + '</span>'
                        : '<span class="text-body-secondary small">—</span>';
                    var snrRssi = (e.snr || e.rssi)
                        ? '<span class="small">' + (e.snr !== null ? e.snr + ' dB' : '?') + ' / ' + (e.rssi !== null ? e.rssi + ' dBm' : '?') + '</span>'
                        : '<span class="text-body-secondary small">—</span>';
                    var preview = e.payload_text
                        ? '<span class="small font-monospace">' + esc(String(e.payload_text).substring(0, 80)) + '</span>'
                        : '<span class="text-body-secondary small">(binary)</span>';
                    html += '<tr>'
                          + '<td>' + esc(e.bridge_label || ('#' + e.bridge_id)) + '</td>'
                          + '<td><code class="small">' + esc(e.display_name || e.src_node || '?') + '</code></td>'
                          + '<td><code class="small">' + esc(e.port_kind || '?') + '</code></td>'
                          + '<td>' + pos + '</td>'
                          + '<td>' + snrRssi + '</td>'
                          + '<td>' + preview + '</td>'
                          + '<td><span class="small" title="' + esc(e.received_at || '') + '">' + fmtAge(e.age_seconds) + ' ago</span></td>'
                          + '</tr>';
                }
                body.innerHTML = html;
            }).catch(function () {
                body.innerHTML = '<tr><td colspan="7" class="text-danger small text-center py-3">Failed to load events.</td></tr>';
            });
        }
        var recentRefresh = document.getElementById('btnRefreshAtakRecent');
        if (recentRefresh) recentRefresh.addEventListener('click', loadRecent);

        loadChannels();
        loadUnbound();
        loadRecent();
    }

    // ═══════════════════════════════════════════════════════════════
    //  BACKUP / MAINTENANCE
    // ═══════════════════════════════════════════════════════════════

    function bindBackupPanel() {
        // Download button
        var dlBtn = document.getElementById('btnBackupDownload');
        if (dlBtn) {
            dlBtn.addEventListener('click', function () {
                var statusEl = document.getElementById('backupDownloadStatus');
                dlBtn.disabled = true;
                dlBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Generating backup...';
                if (statusEl) statusEl.textContent = 'This may take several minutes for large databases.';

                // Navigate to download endpoint (browser handles the file save dialog)
                window.location.href = 'api/backup.php?action=download&csrf_token=' + encodeURIComponent(csrfToken);

                // Re-enable button after a delay (we can't detect download completion reliably)
                setTimeout(function () {
                    dlBtn.disabled = false;
                    dlBtn.innerHTML = '<i class="bi bi-cloud-download me-1"></i>Download Full Backup';
                    if (statusEl) statusEl.textContent = '';
                }, 10000);
            });
        }

        // Filesystem save button
        var fsBtn = document.getElementById('btnBackupFilesystem');
        if (fsBtn) {
            fsBtn.addEventListener('click', function () {
                var path = document.getElementById('backupPath').value.trim();
                var statusEl = document.getElementById('backupFsStatus');
                if (!path) { showAlert('Enter a backup path', 'warning'); return; }

                fsBtn.disabled = true;
                fsBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Saving...';
                if (statusEl) statusEl.textContent = '';

                fetch('api/backup.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'same-origin',
                    body: JSON.stringify({ action: 'filesystem', path: path, csrf_token: csrfToken })
                }).then(function (r) { return r.json(); })
                  .then(function (data) {
                      fsBtn.disabled = false;
                      fsBtn.innerHTML = '<i class="bi bi-hdd me-1"></i>Save to Server';
                      if (data.error) {
                          if (statusEl) { statusEl.textContent = data.error; statusEl.className = 'small text-danger'; }
                          return;
                      }
                      if (statusEl) {
                          statusEl.textContent = 'Saved: ' + data.filename + ' (' + data.size + ')';
                          statusEl.className = 'small text-success';
                      }
                      loadBackupHistory();
                  })
                  .catch(function (err) {
                      fsBtn.disabled = false;
                      fsBtn.innerHTML = '<i class="bi bi-hdd me-1"></i>Save to Server';
                      if (statusEl) { statusEl.textContent = err.message; statusEl.className = 'small text-danger'; }
                  });
            });
        }

        // Refresh history button
        var refreshBtn = document.getElementById('btnRefreshHistory');
        if (refreshBtn) {
            refreshBtn.addEventListener('click', loadBackupHistory);
        }
    }

    function loadBackupHistory() {
        var body = document.getElementById('backupHistoryBody');
        if (!body) return;

        var path = document.getElementById('backupPath');
        var dir = path ? path.value.trim() : '';
        var url = 'api/backup.php?action=history';
        if (dir) url += '&path=' + encodeURIComponent(dir);

        fetch(url, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var backups = data.backups || [];
                if (!backups.length) {
                    body.innerHTML = '<tr><td colspan="3" class="text-center text-body-secondary py-3">No backups found</td></tr>';
                    return;
                }
                var dir = data.directory || '';
                var html = '';
                for (var i = 0; i < backups.length; i++) {
                    var b = backups[i];
                    var dlUrl = 'api/backup.php?action=download_file&file=' + encodeURIComponent(b.filename);
                    if (dir) dlUrl += '&path=' + encodeURIComponent(dir);
                    html += '<tr>';
                    html += '<td class="font-monospace"><a href="' + dlUrl + '" class="text-decoration-none" title="Download">';
                    html += '<i class="bi bi-download me-1"></i>' + esc(b.filename) + '</a></td>';
                    html += '<td>' + esc(b.size_formatted) + '</td>';
                    html += '<td>' + esc(b.date) + '</td>';
                    html += '</tr>';
                }
                body.innerHTML = html;
            })
            .catch(function () {
                body.innerHTML = '<tr><td colspan="3" class="text-center text-danger py-3">Failed to load history</td></tr>';
            });
    }

    // ═══════════════════════════════════════════════════════════════
    //  WELCOME DASHBOARD
    // ═══════════════════════════════════════════════════════════════

    function loadWelcomeDashboard() {
        fetch('api/config-summary.php', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.error) return;

                var s = data.summary || {};

                // Stats cards
                var map = {
                    wsUsers: s.users ? s.users.count : 0,
                    wsMembers: s.members ? s.members.count : 0,
                    wsUnits: s.responders ? s.responders.count : 0,
                    wsTypes: s.incidents ? s.incidents.count : 0,
                    wsFacilities: s.facilities ? s.facilities.count : 0,
                    wsTeams: s.teams ? s.teams.count : 0
                };
                for (var id in map) {
                    var el = document.getElementById(id);
                    if (el) el.textContent = map[id];
                }

                // Security status
                var sec = data.security || {};

                var httpsEl = document.getElementById('wsHttps');
                if (httpsEl) {
                    httpsEl.className = 'badge ' + (sec.https ? 'bg-success' : 'bg-warning text-dark');
                    httpsEl.textContent = sec.https ? 'Active' : 'Not detected';
                }

                var tfaEl = document.getElementById('wsTfa');
                if (tfaEl) {
                    tfaEl.className = 'badge ' + (sec.tfa_enabled ? 'bg-success' : 'bg-secondary');
                    tfaEl.textContent = sec.tfa_enabled ? 'Enabled' : 'Disabled';
                }

                var covEl = document.getElementById('wsTfaCoverage');
                if (covEl) {
                    var cov = sec.tfa_coverage || 0;
                    covEl.textContent = sec.tfa_enrolled + ' / ' + (map.wsUsers || 0) + ' users (' + cov + '%)';
                }

                var failEl = document.getElementById('wsFailedLogins');
                if (failEl) {
                    var fails = sec.failed_logins_24h || 0;
                    failEl.textContent = fails;
                    if (fails > 0) failEl.className = 'text-warning fw-bold';
                    if (fails >= 10) failEl.className = 'text-danger fw-bold';
                }

                var sessEl = document.getElementById('wsSessions');
                if (sessEl) sessEl.textContent = sec.active_sessions || 0;

                // Database info
                var db = data.database || {};
                var dbSizeEl = document.getElementById('wsDbSize');
                if (dbSizeEl) dbSizeEl.textContent = (db.size_mb || 0) + ' MB';

                var dbTablesEl = document.getElementById('wsDbTables');
                if (dbTablesEl) dbTablesEl.textContent = db.table_count || 0;

                // Location providers
                var loc = data.location || {};
                var locEl = document.getElementById('wsLocProviders');
                if (locEl) locEl.textContent = (loc.enabled || 0) + ' enabled / ' + (loc.total || 0) + ' total';

                // Phase 38: Onboarding hints
                var hintsEl = document.getElementById('welcomeHints');
                var hints = data.hints || [];
                if (hintsEl) {
                    if (!hints.length) {
                        hintsEl.classList.add('d-none');
                        hintsEl.innerHTML = '';
                    } else {
                        var sevToClass = { warning: 'warning', info: 'info', danger: 'danger' };
                        var html = '<div class="card border-0 bg-body-tertiary">' +
                                   '<div class="card-header py-2 bg-transparent border-bottom-0">' +
                                   '<i class="bi bi-lightbulb text-warning me-1"></i>' +
                                   '<span class="fw-semibold small">Setup hints (' + hints.length + ')</span>' +
                                   '</div><div class="card-body pt-1"><div class="row g-2">';
                        hints.forEach(function (h) {
                            var cls = 'alert-' + (sevToClass[h.severity] || 'info');
                            var icon = h.icon || 'info-circle';
                            var href = '#';
                            var tab = h.tab || '';
                            var linkClass = 'config-quick-link';
                            if (tab.indexOf('__link:') === 0) {
                                href = tab.substring(7);
                                linkClass = '';
                            } else if (tab) {
                                href = '#' + tab;
                            }
                            html +=
                                '<div class="col-md-6">' +
                                '<a href="' + href + '" class="' + linkClass + ' text-decoration-none">' +
                                '<div class="alert ' + cls + ' py-2 mb-0 d-flex gap-2 align-items-start">' +
                                '<i class="bi bi-' + icon + ' fs-5"></i>' +
                                '<div><div class="fw-semibold small">' + escapeHtml(h.title) + '</div>' +
                                '<div class="small">' + escapeHtml(h.body) + '</div></div>' +
                                '</div></a></div>';
                        });
                        html += '</div></div></div>';
                        hintsEl.innerHTML = html;
                        hintsEl.classList.remove('d-none');
                    }
                }
            })
            .catch(function () {
                // Non-fatal — dashboard is a convenience
            });
    }

    // ═══════════════════════════════════════════════════════════════
    //  LOCATION PROVIDERS TABLE
    // ═══════════════════════════════════════════════════════════════

    function initLocationProviders() {
        var body = document.getElementById('locationProvidersBody');
        if (!body) return;

        fetch('api/location.php')
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var providers = data.providers || [];
                if (!providers.length) {
                    body.innerHTML = '<tr><td colspan="5" class="text-center text-body-secondary py-3">No providers configured</td></tr>';
                    return;
                }

                var html = '';
                for (var i = 0; i < providers.length; i++) {
                    var p = providers[i];
                    html += '<tr data-provider-id="' + p.id + '">';
                    html += '<td class="text-center">';
                    html += '<div class="form-check form-switch d-inline-block">';
                    html += '<input class="form-check-input provider-enabled" type="checkbox"' + (parseInt(p.enabled, 10) ? ' checked' : '') + ' data-id="' + p.id + '">';
                    html += '</div></td>';
                    html += '<td>';
                    if (p.icon) html += '<i class="bi ' + esc(p.icon) + ' me-1" style="color:' + esc(p.color || '') + '"></i>';
                    html += esc(p.name) + '</td>';
                    html += '<td><input type="number" class="form-control form-control-sm provider-priority" style="width:60px" value="' + (p.priority || 50) + '" min="1" max="100" data-id="' + p.id + '"></td>';
                    html += '<td>';
                    html += '<div class="input-group input-group-sm" style="width:140px">';
                    html += '<input type="number" class="form-control form-control-sm provider-max-age" value="' + (p.max_age_seconds || 300) + '" min="10" max="86400" data-id="' + p.id + '">';
                    html += '<span class="input-group-text">s</span>';
                    html += '</div></td>';
                    html += '<td class="text-end"><button class="btn btn-sm btn-success btn-save-provider" data-id="' + p.id + '"><i class="bi bi-check-lg me-1"></i>Save</button></td>';
                    html += '</tr>';
                }
                body.innerHTML = html;
                // 2026-06-11 UX fix: explicit hint above the per-row Save
                // buttons so admins don't leave the page thinking their
                // edits are auto-saved. The Save button text + icon makes
                // the affordance obvious; the hint reinforces it.
                var hint = document.getElementById('locationProvidersHint');
                if (hint) {
                    hint.classList.remove('d-none');
                }

                // Bind save buttons
                var btns = body.querySelectorAll('.btn-save-provider');
                for (var j = 0; j < btns.length; j++) {
                    btns[j].addEventListener('click', function () {
                        var pid = parseInt(this.getAttribute('data-id'), 10);
                        var row = body.querySelector('tr[data-provider-id="' + pid + '"]');
                        if (!row) return;

                        var enabled = row.querySelector('.provider-enabled').checked ? 1 : 0;
                        var priority = parseInt(row.querySelector('.provider-priority').value, 10);
                        var maxAge = parseInt(row.querySelector('.provider-max-age').value, 10);

                        apiPostDirect('location', {
                            action: 'save_provider',
                            id: pid,
                            enabled: enabled,
                            priority: priority,
                            max_age_seconds: maxAge
                        }).then(function () {
                            showAlert('Provider settings saved');
                        }).catch(function (err) {
                            showAlert(err.message, 'danger');
                        });
                    });
                }

                // Also populate the provider settings dropdown
                var sel = document.getElementById('providerSettingsSelect');
                if (sel) {
                    // Cache the providers array on the select element so
                    // the change handler can look up the chosen row
                    // (including its current config_json) without an
                    // extra fetch.
                    sel._providers = providers;
                    // Clear existing options except the placeholder
                    while (sel.options.length > 1) sel.remove(1);
                    for (var k = 0; k < providers.length; k++) {
                        var opt = document.createElement('option');
                        opt.value = providers[k].id;
                        opt.textContent = providers[k].name;
                        sel.appendChild(opt);
                    }
                    // 2026-06-11 fix: wire the change handler that renders
                    // per-provider config fields. Pre-fix the select changed
                    // but nothing happened — admin saw an empty form.
                    sel.addEventListener('change', function () {
                        renderProviderSettingsForm(this);
                    });
                }
            })
            .catch(function () {
                body.innerHTML = '<tr><td colspan="5" class="text-center text-danger py-3">Failed to load providers</td></tr>';
            });
    }

    /*
     * Render the per-provider settings form when an admin picks a
     * provider from the Provider Settings dropdown.
     *
     * Pre-2026-06-11 the dropdown changed but nothing happened — Eric
     * caught this on OwnTracks (the most common config admins want to
     * touch). This function:
     *   1. Reads the provider from the cached list (sel._providers)
     *   2. Parses its config_json (may be null for never-configured)
     *   3. Renders provider-specific input fields (each provider has
     *      a different shape — OwnTracks needs a secret, Meshtastic
     *      needs a gateway, OpenGTS needs a server URL, Internal GPS
     *      needs nothing, etc.)
     *   4. Wires the Save button to POST config_json back via
     *      api/location.php save_provider.
     */
    function renderProviderSettingsForm(sel) {
        var formWrap   = document.getElementById('providerSettingsForm');
        var nameEl     = document.getElementById('providerSettingsName');
        var fieldsEl   = document.getElementById('providerSettingsFields');
        var saveBtn    = document.getElementById('btnSaveProviderSettings');
        if (!formWrap || !nameEl || !fieldsEl || !saveBtn) return;

        var pid = parseInt(sel.value, 10);
        if (!pid) {
            formWrap.style.display = 'none';
            return;
        }

        var providers = sel._providers || [];
        var provider = null;
        for (var i = 0; i < providers.length; i++) {
            if (parseInt(providers[i].id, 10) === pid) {
                provider = providers[i];
                break;
            }
        }
        if (!provider) {
            formWrap.style.display = 'none';
            return;
        }

        // Parse existing config_json (may be a string already-parsed obj or null)
        var cfg = {};
        if (provider.config && typeof provider.config === 'object') {
            cfg = provider.config;
        } else if (provider.config_json) {
            try { cfg = JSON.parse(provider.config_json) || {}; } catch (e) { cfg = {}; }
        }

        nameEl.textContent = provider.name;

        // Per-provider field schema. Code matches the `code` column in
        // the location_providers table (run_location_providers.php seed).
        var schema = providerFieldSchema(provider.code);

        if (schema.fields.length === 0) {
            // Phase 41: schema.note may carry intentional HTML (links, <strong>),
            // and schema.redirect may supply a button. Keep both rich-rendered.
            var noteHtml = schema.note
                ? schema.note
                : 'This provider has no extra connection settings. Use the On/Priority/Max Age controls in the Location Providers table above.';
            fieldsEl.innerHTML = '<div class="alert alert-info small mb-0">' +
                '<i class="bi bi-info-circle me-1"></i>' + noteHtml + '</div>';
            if (schema.redirect && schema.redirect.href) {
                fieldsEl.innerHTML += '<div class="mt-2">' +
                    '<a href="' + escHtml(schema.redirect.href) + '" class="btn btn-sm btn-primary">' +
                    '<i class="bi bi-arrow-right-circle me-1"></i>' + escHtml(schema.redirect.label || 'Open') +
                    '</a></div>';
            }
            saveBtn.style.display = 'none';
        } else {
            var html = '';
            if (schema.note) {
                html += '<p class="text-body-secondary small mb-2">' + escHtml(schema.note) + '</p>';
            }
            for (var f = 0; f < schema.fields.length; f++) {
                var field = schema.fields[f];
                var val = cfg[field.key] !== undefined ? cfg[field.key] : (field.default || '');
                html += '<div class="mb-2">';
                html += '  <label class="form-label form-label-sm mb-1">' + escHtml(field.label);
                if (field.required) html += ' <span class="text-danger">*</span>';
                html += '</label>';
                if (field.type === 'textarea') {
                    html += '<textarea class="form-control form-control-sm provider-cfg-input" data-key="' + escHtml(field.key) + '" rows="3"' +
                            (field.placeholder ? ' placeholder="' + escHtml(field.placeholder) + '"' : '') +
                            '>' + escHtml(String(val)) + '</textarea>';
                } else {
                    html += '<input type="' + (field.type || 'text') + '" class="form-control form-control-sm provider-cfg-input"' +
                            ' data-key="' + escHtml(field.key) + '"' +
                            ' value="' + escHtml(String(val)) + '"' +
                            (field.placeholder ? ' placeholder="' + escHtml(field.placeholder) + '"' : '') +
                            (field.min !== undefined ? ' min="' + field.min + '"' : '') +
                            (field.max !== undefined ? ' max="' + field.max + '"' : '') +
                            '>';
                }
                if (field.hint) {
                    html += '<div class="form-text small">' + field.hint + '</div>';
                }
                html += '</div>';
            }
            fieldsEl.innerHTML = html;
            saveBtn.style.display = '';
        }

        formWrap.style.display = '';

        // Wire the save button (rebind on every render so the latest
        // provider id is captured).
        var newSaveBtn = saveBtn.cloneNode(true);
        saveBtn.parentNode.replaceChild(newSaveBtn, saveBtn);
        newSaveBtn.addEventListener('click', function () {
            var inputs = fieldsEl.querySelectorAll('.provider-cfg-input');
            var newCfg = {};
            for (var x = 0; x < inputs.length; x++) {
                var k = inputs[x].getAttribute('data-key');
                var v = inputs[x].value;
                if (v !== '') newCfg[k] = v;
            }
            apiPostDirect('location', {
                action: 'save_provider',
                id: pid,
                config_json: JSON.stringify(newCfg)
            }).then(function () {
                showAlert(provider.name + ' settings saved.', 'success');
                // Refresh the providers cache so a subsequent edit
                // sees the new values.
                provider.config = newCfg;
                provider.config_json = JSON.stringify(newCfg);
            }).catch(function (err) {
                showAlert(err.message, 'danger');
            });
        });
    }

    /*
     * Per-provider input schemas. Indexed by the `code` column.
     * Each returns { fields: [...], note: "..." }.
     * Fields: { key, label, type, required, default, placeholder, hint, min, max }
     */
    function providerFieldSchema(code) {
        switch (code) {
            case 'owntracks':
                return {
                    note: 'OwnTracks phones POST positions to /api/location.php?provider=owntracks. The shared secret prevents anyone who knows the URL from posting fake position reports. Phones send it as the X-Limit-U HTTP header OR ?secret=... query parameter.',
                    fields: [
                        {
                            key: 'secret',
                            label: 'Shared secret',
                            type: 'text',
                            placeholder: 'e.g., tcad-ot-' + Math.random().toString(36).slice(2, 18),
                            hint: 'Any random string. Leave blank to skip authentication (NOT recommended — anyone who guesses the URL can post fake locations).'
                        }
                    ]
                };
            case 'meshtastic':
                return {
                    note: 'Meshtastic ingest is handled by the <strong>Mesh Bridges Console</strong> (Phase 35) — distributed bridges authenticate to TicketsCAD via Bearer tokens over HTTPS and post positions, texts, and node info. There are no per-provider connection settings here; configure bridges + channels in the Mesh Console.',
                    redirect: { href: 'mesh-console.php', label: 'Open Mesh Bridges Console' },
                    fields: []
                };
            case 'aprs':
                return {
                    note: 'APRS-IS uses the aprs.fi REST API. The dedicated APRS Configuration panel below also covers the poller cron and call-sign list.',
                    fields: [
                        { key: 'api_key', label: 'aprs.fi API key', type: 'text', placeholder: '###############', hint: 'Get a free key at <a href="https://aprs.fi/page/api" target="_blank">aprs.fi/page/api</a>.' }
                    ]
                };
            case 'opengts':
                return {
                    note: 'OpenGTS/Traccar devices POST to a custom URL. Configure your device firmware to send to /api/location.php?provider=opengts with these parameters.',
                    fields: [
                        { key: 'device_id_pattern', label: 'Device ID pattern', type: 'text', placeholder: 'TCAD-{tid}', hint: 'How device IDs map to bound TIDs. {tid} is replaced with the binding.' },
                        { key: 'secret',            label: 'Shared secret',     type: 'text', placeholder: '(optional)',  hint: 'Optional shared secret; same role as the OwnTracks secret.' }
                    ]
                };
            case 'dmr':
                return {
                    note: 'DMR Radio GPS integration is a stub — the protocol adapter is not yet implemented. The panel will report "not_implemented" if you POST positions here.',
                    fields: []
                };
            case 'internal':
                return {
                    note: 'Internal GPS uses the browser\'s Geolocation API on the Mobile interface — no server-side connection settings are needed.',
                    fields: []
                };
            case 'google_lat':
                return {
                    note: 'Google Latitude has been deprecated by Google since 2013. This provider is kept for legacy compatibility only.',
                    fields: [
                        { key: 'api_key', label: 'API key', type: 'text', placeholder: '(legacy — leave blank)' }
                    ]
                };
            default:
                return {
                    note: 'Custom provider — define your own fields in the provider source.',
                    fields: [
                        { key: 'config', label: 'Custom config (JSON)', type: 'textarea', placeholder: '{}' }
                    ]
                };
        }
    }

    // ═══════════════════════════════════════════════════════════════
    //  UNIT ASSIGNMENT ROLES TABLE
    // ═══════════════════════════════════════════════════════════════

    function initUnitAssignmentRoles() {
        var body = document.getElementById('unitRolesBody');
        if (!body) return;

        fetch('api/unit-assignments.php?roles=1')
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var roles = data.roles || [];
                if (!roles.length) {
                    body.innerHTML = '<tr><td colspan="5" class="text-center text-body-secondary py-3">No roles defined</td></tr>';
                    return;
                }

                var html = '';
                for (var i = 0; i < roles.length; i++) {
                    var r = roles[i];
                    html += '<tr>';
                    html += '<td class="font-monospace small">' + esc(r.code) + '</td>';
                    html += '<td>' + esc(r.name) + '</td>';
                    html += '<td class="small text-body-secondary">' + esc(r.description || '') + '</td>';
                    html += '<td class="text-center">' + r.sort_order + '</td>';
                    html += '<td class="text-center">';
                    html += '<button class="btn btn-sm btn-outline-primary py-0 px-1 me-1 btn-edit-unit-role" data-id="' + r.id + '" data-code="' + esc(r.code) + '" data-name="' + esc(r.name) + '" data-desc="' + esc(r.description || '') + '" data-sort="' + r.sort_order + '" title="Edit"><i class="bi bi-pencil"></i></button>';
                    html += '<button class="btn btn-sm btn-outline-danger py-0 px-1 btn-delete-unit-role" data-id="' + r.id + '" title="Delete"><i class="bi bi-trash"></i></button>';
                    html += '</td></tr>';
                }
                body.innerHTML = html;

                // Edit buttons
                var editBtns = body.querySelectorAll('.btn-edit-unit-role');
                for (var j = 0; j < editBtns.length; j++) {
                    editBtns[j].addEventListener('click', function () {
                        document.getElementById('uarEditId').value = this.getAttribute('data-id');
                        document.getElementById('uarCode').value = this.getAttribute('data-code');
                        document.getElementById('uarName').value = this.getAttribute('data-name');
                        document.getElementById('uarDescription').value = this.getAttribute('data-desc');
                        document.getElementById('uarSortOrder').value = this.getAttribute('data-sort');
                        document.getElementById('btnAddUnitRole').innerHTML = '<i class="bi bi-check-lg me-1"></i>Update';
                    });
                }

                // Delete buttons
                var delBtns = body.querySelectorAll('.btn-delete-unit-role');
                for (var k = 0; k < delBtns.length; k++) {
                    delBtns[k].addEventListener('click', function () {
                        var roleId = parseInt(this.getAttribute('data-id'), 10);
                        if (!confirm('Delete this role?')) return;
                        apiPostDirect('unit-assignments', {
                            action: 'delete_role',
                            id: roleId
                        }).then(function () {
                            showAlert('Role deleted');
                            initUnitAssignmentRoles();
                        }).catch(function (err) {
                            showAlert(err.message, 'danger');
                        });
                    });
                }
            })
            .catch(function () {
                body.innerHTML = '<tr><td colspan="5" class="text-center text-danger py-3">Failed to load roles</td></tr>';
            });

        // Add/Update role button
        var addBtn = document.getElementById('btnAddUnitRole');
        if (addBtn && !addBtn._bound) {
            addBtn._bound = true;
            addBtn.addEventListener('click', function () {
                var editId = parseInt(document.getElementById('uarEditId').value, 10) || 0;
                var code = document.getElementById('uarCode').value.trim();
                var name = document.getElementById('uarName').value.trim();
                var desc = document.getElementById('uarDescription').value.trim();
                var sort = parseInt(document.getElementById('uarSortOrder').value, 10) || 50;

                if (!name) { showAlert('Role name is required', 'warning'); return; }

                var payload = { action: 'save_role', code: code, name: name, description: desc, sort_order: sort };
                if (editId) payload.id = editId;

                // Issue #33 (a beta tester 2026-07-03): was calling apiPost() which
                // routes to config-admin.php?section=unit-assignments — no
                // such section exists there, endpoint returned an error and
                // the JSON-parse threw. Delete used apiPostDirect() and
                // worked; add/edit needs the same routing.
                apiPostDirect('unit-assignments', payload).then(function () {
                    showAlert(editId ? 'Role updated' : 'Role added');
                    document.getElementById('uarEditId').value = '0';
                    document.getElementById('uarCode').value = '';
                    document.getElementById('uarName').value = '';
                    document.getElementById('uarDescription').value = '';
                    document.getElementById('uarSortOrder').value = '50';
                    addBtn.innerHTML = '<i class="bi bi-plus-lg me-1"></i>Add';
                    initUnitAssignmentRoles();
                }).catch(function (err) {
                    showAlert(err.message, 'danger');
                });
            });
        }
    }

    // ═══════════════════════════════════════════════════════════════
    //  SCHEDULING PERMISSIONS TABLE
    // ═══════════════════════════════════════════════════════════════

    function initSchedulingPermissions() {
        var body = document.getElementById('schedPermProfilesBody');
        if (!body) return;

        fetch('api/scheduling-permissions.php')
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var profiles = data.profiles || [];
                if (!profiles.length) {
                    body.innerHTML = '<tr><td colspan="7" class="text-center text-body-secondary py-3">No profiles</td></tr>';
                    return;
                }

                var html = '';
                for (var i = 0; i < profiles.length; i++) {
                    var p = profiles[i];
                    var check = '<i class="bi bi-check-circle-fill text-success"></i>';
                    var x = '<i class="bi bi-x-circle text-body-tertiary"></i>';

                    html += '<tr>';
                    html += '<td><strong>' + esc(p.name) + '</strong><br><span class="text-body-secondary" style="font-size:0.7rem">' + esc(p.code) + '</span></td>';
                    html += '<td class="text-center">' + (parseInt(p.can_view_schedule, 10) ? check : x) + '</td>';
                    html += '<td class="text-center">' + (parseInt(p.can_self_assign, 10) ? check : x) + '</td>';
                    html += '<td class="text-center">' + (parseInt(p.can_mark_unavailable, 10) ? check : x) + '</td>';
                    html += '<td class="text-center">' + (parseInt(p.can_swap, 10) ? check : x) + '</td>';
                    html += '<td class="text-center">' + (parseInt(p.can_assign_others, 10) ? check : x) + '</td>';
                    html += '<td class="text-center">' + (parseInt(p.can_manage_slots, 10) ? check : x) + '</td>';
                    html += '</tr>';
                }
                body.innerHTML = html;
            })
            .catch(function () {
                body.innerHTML = '<tr><td colspan="7" class="text-center text-danger py-3">Failed to load profiles</td></tr>';
            });

        // Load assignments
        var assignList = document.getElementById('schedPermAssignmentsList');
        if (assignList) {
            fetch('api/scheduling-permissions.php?assignments=1')
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    var assignments = data.assignments || [];
                    if (!assignments.length) {
                        assignList.innerHTML = '<div class="text-body-secondary small">No custom assignments. Global default is in effect.</div>';
                        return;
                    }

                    var html = '<table class="table table-sm mb-0" style="font-size:0.8rem"><thead><tr><th>Scope</th><th>Target</th><th>Profile</th><th></th></tr></thead><tbody>';
                    for (var i = 0; i < assignments.length; i++) {
                        var a = assignments[i];
                        html += '<tr>';
                        html += '<td>' + esc(a.scope_type) + (a.scope_id ? ' #' + a.scope_id : '') + '</td>';
                        html += '<td>' + esc(a.target_type) + (a.target_id ? ' #' + a.target_id : '') + '</td>';
                        html += '<td><span class="badge bg-primary">' + esc(a.profile_name) + '</span></td>';
                        html += '<td class="text-end"><button class="btn btn-sm btn-outline-danger py-0 px-1 btn-delete-sched-assign" data-id="' + a.id + '"><i class="bi bi-x-lg"></i></button></td>';
                        html += '</tr>';
                    }
                    html += '</tbody></table>';
                    assignList.innerHTML = html;

                    // Bind delete
                    var btns = assignList.querySelectorAll('.btn-delete-sched-assign');
                    for (var j = 0; j < btns.length; j++) {
                        btns[j].addEventListener('click', function () {
                            var aid = parseInt(this.getAttribute('data-id'), 10);
                            if (!confirm('Remove this permission assignment?')) return;
                            apiPostDirect('scheduling-permissions', {
                                action: 'delete_assignment',
                                id: aid
                            }).then(function () {
                                showAlert('Assignment removed');
                                initSchedulingPermissions();
                            }).catch(function (err) {
                                showAlert(err.message, 'danger');
                            });
                        });
                    }
                })
                .catch(function () {
                    assignList.innerHTML = '<div class="text-danger small">Failed to load assignments</div>';
                });
        }
    }

    // ── Wire up lazy loading for new panels ─────────────────────
    var _locationProvidersLoaded = false;
    var _unitRolesLoaded = false;
    var _schedPermsLoaded = false;
    var _backupHistoryLoaded = false;

    function onPanelShow(panelId) {
        // Phase 44b — fix intermittent "no available providers" on a direct
        // #provider-settings landing. initLocationProviders() is what
        // populates BOTH the tracking-providers table AND the
        // providerSettingsSelect dropdown as a side-effect. If the user
        // lands on #provider-settings without ever opening tracking-providers,
        // the dropdown stays empty. The _locationProvidersLoaded flag
        // makes the second call a no-op, so this is idempotent.
        if ((panelId === 'tracking-providers' || panelId === 'provider-settings')
                && !_locationProvidersLoaded) {
            _locationProvidersLoaded = true;
            initLocationProviders();
        }
        if (panelId === 'unit-assignment-roles' && !_unitRolesLoaded) {
            _unitRolesLoaded = true;
            initUnitAssignmentRoles();
        }
        if (panelId === 'scheduling-permissions' && !_schedPermsLoaded) {
            _schedPermsLoaded = true;
            initSchedulingPermissions();
        }
        if (panelId === 'backup' && !_backupHistoryLoaded) {
            _backupHistoryLoaded = true;
            loadBackupHistory();
        }
        if (panelId === 'geofencing') {
            setTimeout(function () {
                initGfDrawMap();
                if (_gfDrawMap) _gfDrawMap.invalidateSize();
            }, 200);
        }
        if (panelId === 'owntracks-defaults' && !_otDefaultsLoaded) {
            _otDefaultsLoaded = true;
            initOtDefaults();
        }
    }

    // ─────────────────────────────────────────────────────────────
    // Phase 51 (2026-06-14) — OwnTracks Defaults panel
    //
    // Renders one row per tunable knob (defined in PHP at
    // _ot_tunable_keys()). Each row is label + (input or select)
    // + hint. Blank value = inherit from hardcoded fallback. Save
    // POSTs to api/owntracks-config.php?action=save_defaults which
    // also queues a setConfiguration push to every active OwnTracks
    // device so changes converge within seconds of next post.
    // ─────────────────────────────────────────────────────────────
    var _otDefaultsLoaded = false;

    function initOtDefaults() {
        var loading = document.getElementById('otDefaultsLoading');
        var fields  = document.getElementById('otDefaultsFields');
        var actions = document.getElementById('otDefaultsActions');
        if (!fields) return;
        fetch('api/owntracks-config.php?action=get_defaults', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.error) {
                    loading.innerHTML = '<div class="text-danger">' + (data.error) + '</div>';
                    return;
                }
                _renderOtDefaults(data.defaults || []);
                loading.classList.add('d-none');
                fields.classList.remove('d-none');
                actions.classList.remove('d-none');
            })
            .catch(function () {
                loading.innerHTML = '<div class="text-danger">Failed to load defaults.</div>';
            });
    }

    function _renderOtDefaults(defaults) {
        var html = '<div class="row g-2">';
        for (var i = 0; i < defaults.length; i++) {
            var d = defaults[i];
            var inputHtml;
            if (d.type === 'select' && d.options) {
                inputHtml = '<select class="form-select form-select-sm ot-default-input" data-key="' + d.settings_key + '">';
                inputHtml += '<option value="">— inherit fallback —</option>';
                for (var k in d.options) {
                    var sel = (String(d.value) === String(k)) ? ' selected' : '';
                    inputHtml += '<option value="' + k + '"' + sel + '>' + d.options[k] + '</option>';
                }
                inputHtml += '</select>';
            } else {
                var v = (d.value === null || d.value === undefined) ? '' : d.value;
                inputHtml = '<input type="number" class="form-control form-control-sm ot-default-input" '
                    + 'data-key="' + d.settings_key + '" value="' + v + '" placeholder="inherit fallback">';
            }
            html += '<div class="col-md-6">'
                +    '<label class="form-label form-label-sm mb-0">' + d.label
                +      ' <code class="small text-body-secondary">' + d.config_key + '</code></label>'
                +    inputHtml
                +    '<div class="form-text small">' + d.hint + '</div>'
                +  '</div>';
        }
        html += '</div>';
        document.getElementById('otDefaultsFields').innerHTML = html;
    }

    function _collectOtDefaults() {
        var inputs = document.querySelectorAll('#otDefaultsFields .ot-default-input');
        var out = {};
        for (var i = 0; i < inputs.length; i++) {
            out[inputs[i].getAttribute('data-key')] = inputs[i].value.trim();
        }
        return out;
    }

    document.addEventListener('click', function (e) {
        var saveBtn = e.target.closest('#btnSaveOtDefaults');
        if (saveBtn) {
            var statusEl = document.getElementById('otDefaultsStatus');
            statusEl.textContent = 'Saving + pushing…';
            saveBtn.disabled = true;
            fetch('api/owntracks-config.php?action=save_defaults', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    csrf_token: document.querySelector('meta[name="csrf-token"]').content,
                    settings: _collectOtDefaults()
                })
            })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                saveBtn.disabled = false;
                if (d.error) { statusEl.innerHTML = '<span class="text-danger">' + d.error + '</span>'; return; }
                statusEl.innerHTML = '<span class="text-success">Saved · pushed to ' + d.pushed_to + ' device(s)</span>';
                setTimeout(function () { statusEl.textContent = ''; }, 6000);
            })
            .catch(function () { saveBtn.disabled = false; statusEl.innerHTML = '<span class="text-danger">Save failed.</span>'; });
        }
        var resetBtn = e.target.closest('#btnResetOtDefaults');
        if (resetBtn) {
            if (!confirm('Clear every OwnTracks default and revert all members to the hardcoded fallback? Per-member overrides are kept.')) return;
            var inputs = document.querySelectorAll('#otDefaultsFields .ot-default-input');
            for (var i = 0; i < inputs.length; i++) {
                if (inputs[i].tagName === 'SELECT') inputs[i].value = '';
                else inputs[i].value = '';
            }
            document.getElementById('btnSaveOtDefaults').click();
        }
    });

    // Hook into existing tab switching
    var _origTabHandler = null;
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.config-tab-link');
        if (btn) {
            var tab = btn.getAttribute('data-tab');
            if (tab) setTimeout(function () { onPanelShow(tab); }, 100);
        }
    });

    // Also check on init if the hash points to one of our panels
    var _initHash = (window.location.hash || '').replace('#', '');
    if (_initHash) {
        setTimeout(function () { onPanelShow(_initHash); }, 300);
    }

    // ── Message Routing Panel ─────────────────────────────────────
    function bindMessageRoutingPanel() {
        var routesBody = document.getElementById('routesTableBody');
        var logBody = document.getElementById('routingLogBody');
        var logSection = document.getElementById('routingLogSection');
        var logPager = document.getElementById('routingLogPager');
        var channels = [];
        var logPage = 1;

        if (!routesBody) return;

        // Load channel list for dropdowns
        function loadChannels(cb) {
            fetch('api/routing.php?channels=1').then(function (r) { return r.json(); }).then(function (data) {
                channels = data.channels || [];
                if (cb) cb();
            }).catch(function () { channels = []; if (cb) cb(); });
        }

        function populateChannelSelect(sel, includeWildcard) {
            if (!sel) return;
            var val = sel.value;
            sel.innerHTML = '';
            if (includeWildcard) {
                var opt = document.createElement('option');
                opt.value = '*'; opt.textContent = 'Any Channel (*)';
                sel.appendChild(opt);
            }
            for (var i = 0; i < channels.length; i++) {
                var ch = channels[i];
                // Phase D: the unified transport destinations (mesh:*, zello)
                // are valid only as a route DESTINATION, not a source. The
                // source select passes includeWildcard=true — skip them there.
                if (includeWildcard && ch.status === 'transport') continue;
                var o = document.createElement('option');
                o.value = ch.code;
                o.textContent = ch.name + ' (' + ch.code + ')';
                if (ch.status === 'not_configured') o.textContent += ' [not configured]';
                sel.appendChild(o);
            }
            if (val) sel.value = val;
        }

        // ── Phase D: route destination sub-address (mesh + Zello) ──────
        // The dest dropdown now includes mesh:meshtastic, mesh:meshcore and
        // zello. When one is picked, reveal the matching sub-address card so
        // the admin chooses a channel/unit (mesh) or channel/user (Zello).
        var _routeMeshTargets = null;
        function loadRouteMeshTargets() {
            if (_routeMeshTargets) { fillRouteMeshUnits(); return; }
            fetch('api/mesh.php?action=send_targets', { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data || data.error) { _routeMeshTargets = { members: [], units: [] }; }
                    else { _routeMeshTargets = { members: data.members || [], units: data.units || [] }; }
                    fillRouteMeshUnits();
                }).catch(function () { _routeMeshTargets = { members: [], units: [] }; fillRouteMeshUnits(); });
        }
        function fillRouteMeshUnits() {
            var sel = document.getElementById('routeMeshUnit');
            var hint = document.getElementById('routeMeshUnitHint');
            if (!sel) return;
            var proto = (document.getElementById('routeDest').value === 'mesh:meshcore') ? 'meshcore' : 'meshtastic';
            var t = _routeMeshTargets || { members: [], units: [] };
            var html = '<option value="">— select —</option>';
            var n = 0;
            if (t.units.length) {
                var uOpts = '';
                t.units.forEach(function (u) {
                    if (!u[proto]) return;
                    uOpts += '<option value="unit:' + u.unit_id + '">' + _esc(u.name) + '</option>'; n++;
                });
                if (uOpts) html += '<optgroup label="Units">' + uOpts + '</optgroup>';
            }
            if (t.members.length) {
                var mOpts = '';
                t.members.forEach(function (m) {
                    if (!m[proto]) return;
                    mOpts += '<option value="member:' + m.member_id + '">' + _esc(m.name) + '</option>'; n++;
                });
                if (mOpts) html += '<optgroup label="People">' + mOpts + '</optgroup>';
            }
            sel.innerHTML = html;
            if (hint) hint.textContent = n ? (n + ' target(s) with a ' + proto + ' address.') : ('No units/people have a ' + proto + ' identifier on file.');
        }
        function updateRouteSubVisibility() {
            var dest = document.getElementById('routeDest').value;
            var meshCard = document.getElementById('routeMeshSub');
            var zelloCard = document.getElementById('routeZelloSub');
            var isMesh = (dest === 'mesh:meshtastic' || dest === 'mesh:meshcore');
            var isZello = (dest === 'zello');
            if (meshCard) meshCard.style.display = isMesh ? '' : 'none';
            if (zelloCard) zelloCard.style.display = isZello ? '' : 'none';
            if (isMesh) { loadRouteMeshTargets(); updateRouteMeshKind(); }
            if (isZello) updateRouteZelloKind();
        }
        function updateRouteMeshKind() {
            var kind = document.getElementById('routeMeshTargetKind').value;
            document.getElementById('routeMeshSlotWrap').style.display = (kind === 'channel') ? '' : 'none';
            document.getElementById('routeMeshUnitWrap').style.display = (kind === 'unit') ? '' : 'none';
            document.getElementById('routeMeshNodeWrap').style.display = (kind === 'node') ? '' : 'none';
        }
        function updateRouteZelloKind() {
            var kind = document.getElementById('routeZelloTargetKind').value;
            var label = document.getElementById('routeZelloValueLabel');
            var input = document.getElementById('routeZelloValue');
            if (kind === 'user') {
                if (label) label.textContent = 'Zello username';
                if (input) input.placeholder = 'unit12';
            } else {
                if (label) label.textContent = 'Channel name';
                if (input) input.placeholder = 'Leave blank for default dispatch channel';
            }
        }
        // Bind sub-address controls once.
        (function bindRouteSubControls() {
            var dest = document.getElementById('routeDest');
            if (dest) dest.addEventListener('change', updateRouteSubVisibility);
            var mk = document.getElementById('routeMeshTargetKind');
            if (mk) mk.addEventListener('change', updateRouteMeshKind);
            var zk = document.getElementById('routeZelloTargetKind');
            if (zk) zk.addEventListener('change', updateRouteZelloKind);
        })();
        // Reset the sub-address controls to defaults (used on New Route).
        function resetRouteSubAddress() {
            var mk = document.getElementById('routeMeshTargetKind'); if (mk) mk.value = 'channel';
            var ms = document.getElementById('routeMeshSlot'); if (ms) ms.value = '0';
            var mu = document.getElementById('routeMeshUnit'); if (mu) mu.value = '';
            var mn = document.getElementById('routeMeshNode'); if (mn) mn.value = '';
            var zk = document.getElementById('routeZelloTargetKind'); if (zk) zk.value = 'channel';
            var zv = document.getElementById('routeZelloValue'); if (zv) zv.value = '';
        }
        // Apply a loaded route's dest_subaddress to the controls.
        function applyRouteSubAddress(dest, sub) {
            resetRouteSubAddress();
            sub = sub || {};
            if (dest === 'mesh:meshtastic' || dest === 'mesh:meshcore') {
                if (sub.to_node) {
                    document.getElementById('routeMeshTargetKind').value = 'node';
                    document.getElementById('routeMeshNode').value = sub.to_node;
                } else if (sub.unit_id) {
                    document.getElementById('routeMeshTargetKind').value = 'unit';
                    // value applied after units load
                    _routeMeshPendingUnit = 'unit:' + sub.unit_id;
                } else if (sub.member_id) {
                    document.getElementById('routeMeshTargetKind').value = 'unit';
                    _routeMeshPendingUnit = 'member:' + sub.member_id;
                } else {
                    document.getElementById('routeMeshTargetKind').value = 'channel';
                    if (typeof sub.channel_slot !== 'undefined') document.getElementById('routeMeshSlot').value = String(sub.channel_slot);
                }
            } else if (dest === 'zello') {
                if (sub.user) {
                    document.getElementById('routeZelloTargetKind').value = 'user';
                    document.getElementById('routeZelloValue').value = sub.user;
                } else if (sub.channel) {
                    document.getElementById('routeZelloTargetKind').value = 'channel';
                    document.getElementById('routeZelloValue').value = sub.channel;
                }
            }
            updateRouteSubVisibility();
        }
        var _routeMeshPendingUnit = null;
        // After units fill, apply any pending selection.
        var _origFillRouteMeshUnits = fillRouteMeshUnits;
        fillRouteMeshUnits = function () {
            _origFillRouteMeshUnits();
            if (_routeMeshPendingUnit) {
                var mu = document.getElementById('routeMeshUnit');
                if (mu) mu.value = _routeMeshPendingUnit;
                _routeMeshPendingUnit = null;
            }
        };
        // Collect dest_subaddress from the visible controls. Returns null for
        // a flat-channel destination (no sub-address).
        function collectRouteSubAddress() {
            var dest = document.getElementById('routeDest').value;
            if (dest === 'mesh:meshtastic' || dest === 'mesh:meshcore') {
                var kind = document.getElementById('routeMeshTargetKind').value;
                if (kind === 'node') {
                    var node = document.getElementById('routeMeshNode').value.trim();
                    return node ? { to_node: node } : null;
                }
                if (kind === 'unit') {
                    var v = document.getElementById('routeMeshUnit').value;
                    if (!v) return null;
                    var parts = v.split(':');
                    if (parts[0] === 'unit') return { unit_id: parseInt(parts[1], 10) };
                    if (parts[0] === 'member') return { member_id: parseInt(parts[1], 10) };
                    return null;
                }
                // channel
                return { channel_slot: parseInt(document.getElementById('routeMeshSlot').value, 10) || 0 };
            }
            if (dest === 'zello') {
                var zkind = document.getElementById('routeZelloTargetKind').value;
                var zval = document.getElementById('routeZelloValue').value.trim();
                if (zkind === 'user') return zval ? { user: zval } : null;
                return zval ? { channel: zval } : null;
            }
            return null;
        }

        // Load stats
        function loadStats() {
            fetch('api/routing.php?stats=1').then(function (r) { return r.json(); }).then(function (d) {
                var el;
                el = document.getElementById('routingActiveCount'); if (el) el.textContent = d.active_routes || 0;
                el = document.getElementById('routingFwd24h'); if (el) el.textContent = d.forwarded_24h || 0;
                el = document.getElementById('routingFail24h'); if (el) el.textContent = d.failed_24h || 0;
                el = document.getElementById('routingBlocked24h'); if (el) el.textContent = d.loop_blocked_24h || 0;
            }).catch(function () {});
        }

        // ── Enabled delivery channels ──────────────────────────────
        // The broker only forwards a route to a destination channel if
        // that channel is listed in broker_enabled_channels. This card
        // manages that list.
        function loadEnabledChannels() {
            var list = document.getElementById('enabledChannelsList');
            if (!list) return;
            fetch('api/routing.php?enabled_channels=1').then(function (r) { return r.json(); }).then(function (data) {
                var chans = data.channels || [];
                if (chans.length === 0) {
                    list.innerHTML = '<div class="col-12 text-body-secondary small">No channels registered.</div>';
                    return;
                }
                var html = '';
                for (var i = 0; i < chans.length; i++) {
                    var c = chans[i];
                    var id = 'enabledCh_' + c.code;
                    var disabled = c.always_on ? ' disabled' : '';
                    var checked = (c.enabled || c.always_on) ? ' checked' : '';
                    var note = '';
                    if (c.always_on) {
                        note = ' <span class="text-success small">(always on)</span>';
                    } else if (!c.implemented) {
                        note = ' <span class="text-body-secondary small">(send not yet implemented)</span>';
                    }
                    html += '<div class="col-md-4 col-sm-6">'
                        + '<div class="form-check">'
                        + '<input class="form-check-input enabled-channel-cb" type="checkbox" value="' + _esc(c.code) + '" id="' + id + '"' + checked + disabled + '>'
                        + '<label class="form-check-label small" for="' + id + '">' + _esc(c.name) + note + '</label>'
                        + '</div></div>';
                }
                list.innerHTML = html;
            }).catch(function () {
                list.innerHTML = '<div class="col-12 text-danger small">Error loading channels.</div>';
            });
        }

        function saveEnabledChannels() {
            var status = document.getElementById('enabledChannelsStatus');
            var cbs = document.querySelectorAll('.enabled-channel-cb');
            var selected = [];
            for (var i = 0; i < cbs.length; i++) {
                // disabled local_chat is still checked; the server forces it on regardless.
                if (cbs[i].checked) selected.push(cbs[i].value);
            }
            fetch('api/routing.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'save_enabled_channels', channels: selected, csrf_token: csrfToken })
            }).then(function (r) { return r.json(); }).then(function (resp) {
                if (status) {
                    if (resp && resp.ok) {
                        status.innerHTML = '<span class="text-success"><i class="bi bi-check-circle me-1"></i>Saved.</span>';
                    } else {
                        status.innerHTML = '<span class="text-danger">' + _esc((resp && resp.error) || 'Save failed') + '</span>';
                    }
                }
                loadEnabledChannels();
                setTimeout(function () { if (status) status.innerHTML = ''; }, 3000);
            }).catch(function (err) {
                if (status) status.innerHTML = '<span class="text-danger">Error saving: ' + _esc(err.message) + '</span>';
            });
        }

        var btnSaveEnabled = document.getElementById('btnSaveEnabledChannels');
        if (btnSaveEnabled) {
            btnSaveEnabled.addEventListener('click', saveEnabledChannels);
        }

        // Load routes table
        function loadRoutes() {
            fetch('api/routing.php').then(function (r) { return r.json(); }).then(function (data) {
                var routes = data.routes || [];
                if (routes.length === 0) {
                    routesBody.innerHTML = '<tr><td colspan="9" class="text-center text-body-secondary small">No routes configured. Click "New Route" to create one.</td></tr>';
                    return;
                }
                var html = '';
                for (var i = 0; i < routes.length; i++) {
                    var r = routes[i];
                    var filterSummary = _routeFilterSummary(r.filters);
                    html += '<tr data-id="' + r.id + '">'
                        + '<td class="text-center small text-body-secondary">' + r.priority + '</td>'
                        + '<td><strong>' + _esc(r.name) + '</strong>'
                        + (r.description ? '<br><small class="text-body-secondary">' + _esc(r.description) + '</small>' : '')
                        + '</td>'
                        + '<td><span class="badge bg-secondary">' + _esc(r.source_channel) + '</span></td>'
                        + '<td class="text-center"><i class="bi bi-arrow-right text-body-secondary"></i></td>'
                        + '<td><span class="badge bg-primary">' + _esc(r.dest_channel) + '</span></td>'
                        + '<td class="small">' + (r.direction === 'both' ? '<i class="bi bi-arrow-left-right"></i>' : r.direction === 'inbound' ? '<i class="bi bi-arrow-down"></i> in' : '<i class="bi bi-arrow-up"></i> out') + '</td>'
                        + '<td class="small">' + filterSummary + '</td>'
                        + '<td class="text-center"><div class="form-check form-switch d-inline-block"><input class="form-check-input route-toggle" type="checkbox" data-id="' + r.id + '"' + (parseInt(r.enabled) ? ' checked' : '') + '></div></td>'
                        + '<td><button class="btn btn-sm btn-outline-primary route-edit me-1" data-id="' + r.id + '" title="Edit"><i class="bi bi-pencil"></i></button>'
                        + '<button class="btn btn-sm btn-outline-danger route-delete" data-id="' + r.id + '" title="Delete"><i class="bi bi-trash"></i></button></td>'
                        + '</tr>';
                }
                routesBody.innerHTML = html;
                _bindRouteActions();
            }).catch(function (err) {
                routesBody.innerHTML = '<tr><td colspan="9" class="text-danger small">Error loading routes</td></tr>';
            });
        }

        function _routeFilterSummary(filters) {
            if (!filters) return '<span class="text-body-secondary">All</span>';
            var parts = [];
            if (filters.severity_min) parts.push('sev>=' + filters.severity_min);
            if (filters.priority_in && filters.priority_in.length) parts.push('pri:' + filters.priority_in.join('/'));
            if (filters.keywords && filters.keywords.length) parts.push('kw:' + filters.keywords.slice(0, 2).join(',') + (filters.keywords.length > 2 ? '...' : ''));
            if (filters.exclude_keywords && filters.exclude_keywords.length) parts.push('-kw:' + filters.exclude_keywords.slice(0, 2).join(','));
            if (filters.sender_roles && filters.sender_roles.length) parts.push('roles:' + filters.sender_roles.length);
            if (filters.incident_type_ids && filters.incident_type_ids.length) parts.push('types:' + filters.incident_type_ids.length);
            if (filters.incident_id) parts.push('inc#' + filters.incident_id);
            return parts.length ? parts.join(', ') : '<span class="text-body-secondary">All</span>';
        }

        function _esc(s) {
            if (!s) return '';
            var d = document.createElement('div');
            d.appendChild(document.createTextNode(s));
            return d.innerHTML;
        }

        function _bindRouteActions() {
            // Toggle switches
            var toggles = routesBody.querySelectorAll('.route-toggle');
            for (var t = 0; t < toggles.length; t++) {
                toggles[t].addEventListener('change', function () {
                    var id = parseInt(this.getAttribute('data-id'));
                    var enabled = this.checked ? 1 : 0;
                    fetch('api/routing.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'toggle', id: id, enabled: enabled, csrf_token: csrfToken })
                    }).then(function () { loadStats(); });
                });
            }

            // Edit buttons
            var edits = routesBody.querySelectorAll('.route-edit');
            for (var e = 0; e < edits.length; e++) {
                edits[e].addEventListener('click', function () {
                    var id = parseInt(this.getAttribute('data-id'));
                    openRouteModal(id);
                });
            }

            // Delete buttons
            var dels = routesBody.querySelectorAll('.route-delete');
            for (var d = 0; d < dels.length; d++) {
                dels[d].addEventListener('click', function () {
                    var id = parseInt(this.getAttribute('data-id'));
                    if (!confirm('Delete this route?')) return;
                    fetch('api/routing.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'delete', id: id, csrf_token: csrfToken })
                    }).then(function (r) { return r.json(); }).then(function () {
                        loadRoutes();
                        loadStats();
                    });
                });
            }
        }

        // Open route modal (create or edit)
        function openRouteModal(routeId) {
            var titleEl = document.getElementById('routeModalTitle');
            var idField = document.getElementById('routeId');
            var form = document.getElementById('routeForm');

            populateChannelSelect(document.getElementById('routeSource'), true);
            populateChannelSelect(document.getElementById('routeDest'), false);

            if (routeId) {
                titleEl.textContent = 'Edit Route';
                fetch('api/routing.php?id=' + routeId).then(function (r) { return r.json(); }).then(function (data) {
                    var r = data.route;
                    if (!r) return;
                    idField.value = r.id;
                    document.getElementById('routeName').value = r.name || '';
                    document.getElementById('routeDescription').value = r.description || '';
                    document.getElementById('routePriority').value = r.priority || 100;
                    document.getElementById('routeEnabled').checked = !!parseInt(r.enabled);
                    document.getElementById('routeSource').value = r.source_channel || '*';
                    document.getElementById('routeDest').value = r.dest_channel || '';
                    document.getElementById('routeDirection').value = r.direction || 'both';

                    // Filters
                    var f = r.filters || {};
                    document.getElementById('filterSeverityMin').value = f.severity_min || '';
                    document.getElementById('filterPriNormal').checked = f.priority_in ? f.priority_in.indexOf('normal') !== -1 : false;
                    document.getElementById('filterPriHigh').checked = f.priority_in ? f.priority_in.indexOf('high') !== -1 : false;
                    document.getElementById('filterPriUrgent').checked = f.priority_in ? f.priority_in.indexOf('urgent') !== -1 : false;
                    document.getElementById('filterKeywords').value = f.keywords ? f.keywords.join(', ') : '';
                    document.getElementById('filterExclude').value = f.exclude_keywords ? f.exclude_keywords.join(', ') : '';
                    var rolesSelect = document.getElementById('filterSenderRoles');
                    for (var i = 0; i < rolesSelect.options.length; i++) {
                        rolesSelect.options[i].selected = f.sender_roles ? f.sender_roles.indexOf(parseInt(rolesSelect.options[i].value)) !== -1 : false;
                    }

                    // Transforms
                    var t = r.transform || {};
                    document.getElementById('transformPrefix').value = t.prefix || '';
                    document.getElementById('transformPriority').value = t.override_priority || '';

                    // Phase D: apply dest sub-address (mesh/zello target).
                    applyRouteSubAddress(r.dest_channel || '', r.dest_subaddress || null);

                    // Phase 99v-4 — restore recipient predicate (or leave on
                    // channel-broadcast for legacy/null cases).
                    applyRecipientPredicate(r.recipient_predicate || null);

                    // Expand filter/transform sections if they have values
                    if (Object.keys(f).length) {
                        var fc = document.getElementById('routeFiltersCollapse');
                        if (fc && !fc.classList.contains('show')) {
                            new bootstrap.Collapse(fc, { toggle: true });
                        }
                    }
                    if (Object.keys(t).length) {
                        var tc = document.getElementById('routeTransformCollapse');
                        if (tc && !tc.classList.contains('show')) {
                            new bootstrap.Collapse(tc, { toggle: true });
                        }
                    }

                    var modal = new bootstrap.Modal(document.getElementById('routeModal'));
                    modal.show();
                });
            } else {
                titleEl.textContent = 'New Route';
                idField.value = '';
                form.reset();
                document.getElementById('routeEnabled').checked = true;
                document.getElementById('routePriority').value = 100;
                resetRouteSubAddress();
                updateRouteSubVisibility();
                // Phase 99v-4 — start in channel-broadcast mode for fresh routes.
                applyRecipientPredicate(null);
                var modal = new bootstrap.Modal(document.getElementById('routeModal'));
                modal.show();
            }
            // Phase 99v-4 follow-on — populate the sample-ticket dropdown
            // for Preview / Send-test (lazy, runs once per page load).
            if (typeof window._recipLoadSampleTickets === 'function') {
                window._recipLoadSampleTickets();
            }
        }

        // Collect form data
        function collectFormData() {
            var filters = {};
            var sev = document.getElementById('filterSeverityMin').value;
            if (sev) filters.severity_min = parseInt(sev);

            var priIn = [];
            if (document.getElementById('filterPriNormal').checked) priIn.push('normal');
            if (document.getElementById('filterPriHigh').checked) priIn.push('high');
            if (document.getElementById('filterPriUrgent').checked) priIn.push('urgent');
            if (priIn.length) filters.priority_in = priIn;

            var kw = document.getElementById('filterKeywords').value.trim();
            if (kw) filters.keywords = kw.split(',').map(function (s) { return s.trim(); }).filter(function (s) { return s; });

            var exKw = document.getElementById('filterExclude').value.trim();
            if (exKw) filters.exclude_keywords = exKw.split(',').map(function (s) { return s.trim(); }).filter(function (s) { return s; });

            var rolesSelect = document.getElementById('filterSenderRoles');
            var roles = [];
            for (var i = 0; i < rolesSelect.options.length; i++) {
                if (rolesSelect.options[i].selected) roles.push(parseInt(rolesSelect.options[i].value));
            }
            if (roles.length) filters.sender_roles = roles;

            var transform = {};
            var pfx = document.getElementById('transformPrefix').value;
            if (pfx) transform.prefix = pfx;
            var tPri = document.getElementById('transformPriority').value;
            if (tPri) transform.override_priority = tPri;

            return {
                action: document.getElementById('routeId').value ? 'update' : 'create',
                id: parseInt(document.getElementById('routeId').value) || undefined,
                name: document.getElementById('routeName').value.trim(),
                description: document.getElementById('routeDescription').value.trim(),
                priority: parseInt(document.getElementById('routePriority').value) || 100,
                enabled: document.getElementById('routeEnabled').checked ? 1 : 0,
                source_channel: document.getElementById('routeSource').value,
                dest_channel: document.getElementById('routeDest').value,
                direction: document.getElementById('routeDirection').value,
                filters: Object.keys(filters).length ? filters : null,
                transform: Object.keys(transform).length ? transform : null,
                dest_subaddress: collectRouteSubAddress(),
                recipient_predicate: collectRecipientPredicate(),
                csrf_token: csrfToken
            };
        }

        // ── Phase 99v-4 (a beta tester/Eric beta 2026-06-30) — recipient predicate builder ──
        //
        // Two paths:
        //   - "Channel broadcast" radio selected → return null (legacy behaviour)
        //   - "Specific users (predicate)" selected:
        //     - Advanced pane open → parse the JSON textarea
        //     - Otherwise → build {predicate, params} from dropdown + param field
        //
        // The 6 predicates each have a specific param shape; the param field
        // is freeform text and we coerce it based on the selected predicate.

        var RECIP_PARAM_SHAPES = {
            'assigned_to_incident': {
                key: 'ticket_id',
                kind: 'string-or-int',
                label: 'Ticket ID (literal int or $payload.ticket_id)',
                hint: 'Use the literal "$payload.ticket_id" placeholder to read it from the routed message at fire-time.',
                placeholder: '$payload.ticket_id'
            },
            'responder_status_in': {
                key: 'status_names',
                kind: 'csv-strings',
                label: 'Status names (comma-separated)',
                hint: 'Comma-separated list. Matches un_status.status_val case-insensitively. Example: Available, On Scene',
                placeholder: 'Available, On Scene'
            },
            'member_of_team': {
                key: 'team_ids',
                kind: 'csv-ints',
                label: 'Team IDs (comma-separated)',
                hint: 'Comma-separated team IDs from the Teams page.',
                placeholder: '3, 7'
            },
            'user_id_in': {
                key: 'user_ids',
                kind: 'csv-ints',
                label: 'User IDs (comma-separated)',
                hint: 'Literal list of user IDs. Useful for direct targeting or testing.',
                placeholder: '29, 30'
            },
            'org_member': {
                key: 'org_ids',
                kind: 'csv-ints',
                label: 'Organization IDs (comma-separated)',
                hint: 'Comma-separated org IDs from the Organizations admin page.',
                placeholder: '1, 2'
            },
            'rbac_can': {
                key: 'permission_code',
                kind: 'string',
                label: 'Permission code',
                hint: 'e.g. screen.situation, widget.incidents, action.view_major. See /roles-matrix.php for the full list.',
                placeholder: 'screen.situation'
            }
        };

        function collectRecipientPredicate() {
            var modeEl = document.querySelector('input[name="recipientMode"]:checked');
            var mode = modeEl ? modeEl.value : 'broadcast';
            if (mode === 'broadcast') return null;

            // Predicate mode — prefer advanced JSON if pane is open + populated.
            var advPane = document.getElementById('recipAdvancedPane');
            if (advPane && advPane.style.display !== 'none') {
                var raw = (document.getElementById('recipJsonRaw').value || '').trim();
                if (!raw) return null;
                try {
                    var parsed = JSON.parse(raw);
                    return parsed && Object.keys(parsed).length ? parsed : null;
                } catch (e) {
                    alert('Recipient JSON is invalid: ' + e.message);
                    throw e;
                }
            }
            var predicateName = document.getElementById('recipPredicate').value;
            if (!predicateName) return null;
            var shape = RECIP_PARAM_SHAPES[predicateName];
            var raw2 = (document.getElementById('recipParam').value || '').trim();
            if (!raw2) return null;
            var paramValue;
            if (shape.kind === 'csv-strings') {
                paramValue = raw2.split(',').map(function (s) { return s.trim(); }).filter(function (s) { return s; });
            } else if (shape.kind === 'csv-ints') {
                paramValue = raw2.split(',').map(function (s) { return parseInt(s.trim(), 10); }).filter(function (n) { return !isNaN(n); });
            } else if (shape.kind === 'string-or-int') {
                var asInt = parseInt(raw2, 10);
                paramValue = (isFinite(asInt) && String(asInt) === raw2) ? asInt : raw2;
            } else {
                paramValue = raw2;
            }
            var params = {};
            params[shape.key] = paramValue;
            return { predicate: predicateName, params: params };
        }

        function applyRecipientPredicate(predicate) {
            // Reset visibility
            document.getElementById('recipMode_broadcast').checked = true;
            document.getElementById('recipBuilder').style.display = 'none';
            document.getElementById('recipPredicate').value = '';
            document.getElementById('recipParam').value = '';
            document.getElementById('recipJsonRaw').value = '';
            document.getElementById('recipAdvancedPane').style.display = 'none';
            document.getElementById('recipPreviewResult').style.display = 'none';

            if (!predicate || (typeof predicate !== 'object')) return;
            // Predicate is present — switch to predicate mode.
            document.getElementById('recipMode_predicate').checked = true;
            document.getElementById('recipBuilder').style.display = '';

            // Expand the parent collapse so admin sees the setting.
            var col = document.getElementById('routeRecipientsCollapse');
            if (col && !col.classList.contains('show')) new bootstrap.Collapse(col, { toggle: true });

            // Determine whether it's a simple single-predicate (form-friendly)
            // or a composition (advanced JSON).
            if (predicate.type && predicate.conditions) {
                // composition → advanced mode
                document.getElementById('recipAdvancedPane').style.display = '';
                document.getElementById('recipJsonRaw').value = JSON.stringify(predicate, null, 2);
                return;
            }
            if (predicate.predicate && RECIP_PARAM_SHAPES[predicate.predicate]) {
                var shape = RECIP_PARAM_SHAPES[predicate.predicate];
                document.getElementById('recipPredicate').value = predicate.predicate;
                updateRecipParamHint(predicate.predicate);
                var v = predicate.params ? predicate.params[shape.key] : null;
                if (Array.isArray(v)) {
                    document.getElementById('recipParam').value = v.join(', ');
                } else if (v !== undefined && v !== null) {
                    document.getElementById('recipParam').value = String(v);
                }
                return;
            }
            // Unknown shape — drop to advanced.
            document.getElementById('recipAdvancedPane').style.display = '';
            document.getElementById('recipJsonRaw').value = JSON.stringify(predicate, null, 2);
        }

        function updateRecipParamHint(predicateName) {
            var shape = RECIP_PARAM_SHAPES[predicateName];
            var labelEl = document.getElementById('recipParamLabel');
            var paramEl = document.getElementById('recipParam');
            var hintEl  = document.getElementById('recipParamHint');
            if (!shape) {
                labelEl.textContent = 'Parameter';
                paramEl.placeholder = '(select a predicate first)';
                hintEl.textContent = '';
                return;
            }
            labelEl.textContent = shape.label;
            paramEl.placeholder = shape.placeholder;
            hintEl.textContent = shape.hint;
        }

        // Bind recipient builder events once. The form lives in a modal so
        // we attach to the form element (which persists), not the input
        // (rebuilt by Bootstrap on modal show).
        (function bindRecipBuilder() {
            var pickEl = document.getElementById('recipPredicate');
            if (pickEl) {
                pickEl.addEventListener('change', function () { updateRecipParamHint(pickEl.value); });
            }
            // Mode radios — show/hide the builder
            document.querySelectorAll('input[name="recipientMode"]').forEach(function (r) {
                r.addEventListener('change', function () {
                    var on = document.querySelector('input[name="recipientMode"]:checked').value === 'predicate';
                    document.getElementById('recipBuilder').style.display = on ? '' : 'none';
                });
            });
            // Advanced toggle
            var advBtn = document.getElementById('recipAdvancedToggle');
            if (advBtn) {
                advBtn.addEventListener('click', function () {
                    var pane = document.getElementById('recipAdvancedPane');
                    if (pane.style.display === 'none') {
                        // Switching to advanced — populate JSON from current form state
                        var current = null;
                        var pickName = document.getElementById('recipPredicate').value;
                        if (pickName) {
                            try {
                                // Build the JSON via collectRecipientPredicate logic
                                var temp = collectRecipientPredicate();
                                if (temp) current = temp;
                            } catch (e) { /* user cancelled */ }
                        }
                        if (current) {
                            document.getElementById('recipJsonRaw').value = JSON.stringify(current, null, 2);
                        }
                        pane.style.display = '';
                        advBtn.textContent = 'Simple: use the form';
                    } else {
                        pane.style.display = 'none';
                        advBtn.textContent = 'Advanced: edit JSON directly';
                    }
                });
            }
            // Helper: build the sample_payload for preview / test-send.
            // If admin picked a real ticket from the dropdown, the option's
            // dataset carries its severity + handle so we can stamp them.
            // Falls back to a synthetic payload that won't accidentally
            // match a real assigned_to_incident query.
            function _recipSamplePayload() {
                var sel = document.getElementById('recipSampleTicket');
                if (sel && sel.value) {
                    var opt = sel.options[sel.selectedIndex];
                    return {
                        ticket_id: parseInt(sel.value, 10),
                        severity:  parseInt(opt.getAttribute('data-severity') || 0, 10),
                        summary:   opt.textContent || ''
                    };
                }
                // Synthetic payload — ticket_id 0 means no real ticket. The
                // assigned_to_incident predicate will resolve to [] here,
                // which is the honest answer for "no ticket selected."
                return { ticket_id: 0, severity: 1 };
            }

            // Preview button — now uses the real (or honest empty)
            // sample payload from the ticket dropdown.
            var prevBtn = document.getElementById('recipPreviewBtn');
            if (prevBtn) {
                prevBtn.addEventListener('click', function () {
                    var pred;
                    try { pred = collectRecipientPredicate(); }
                    catch (e) { return; }
                    var resEl = document.getElementById('recipPreviewResult');
                    if (!pred) {
                        resEl.style.display = '';
                        resEl.className = 'small mt-2 text-body-secondary';
                        resEl.textContent = 'No predicate set yet — pick one above.';
                        return;
                    }
                    resEl.style.display = '';
                    resEl.className = 'small mt-2 text-body-secondary';
                    resEl.textContent = 'Resolving…';
                    fetch('api/router-recipients-preview.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            predicate: pred,
                            sample_payload: _recipSamplePayload()
                        })
                    }).then(function (r) { return r.json(); }).then(function (data) {
                        if (data.error) {
                            resEl.className = 'small mt-2 text-danger';
                            resEl.textContent = 'Preview failed: ' + data.error;
                            return;
                        }
                        var n = data.count || 0;
                        var names = (data.users || []).map(function (u) { return u.user; }).join(', ');
                        resEl.className = 'small mt-2 ' + (n === 0 ? 'text-warning' : 'text-success');
                        var html = '<strong>' + n + '</strong> user' + (n === 1 ? '' : 's') + ' would be notified';
                        if (n > 0) {
                            html += ': ' + (names ? '<code>' + names.replace(/</g, '&lt;') + '</code>' : '');
                            if (data.truncated) html += ' <span class="text-body-secondary">(showing first 25)</span>';
                        } else {
                            html += '. Tip: check that the param values exist on this install (team IDs, status names, permission codes). For <em>assigned_to_incident</em>, pick a real incident above so the preview resolves against actual assignments.';
                        }
                        resEl.innerHTML = html;
                    }).catch(function (err) {
                        resEl.className = 'small mt-2 text-danger';
                        resEl.textContent = 'Preview request failed: ' + err.message;
                    });
                });
            }

            // Send-test button — fires a real [TEST] message end-to-end
            // through router_forward. Admin gets the actual push / Slack /
            // chat / whatever, marked [TEST] so recipients aren't confused.
            var testBtn = document.getElementById('recipTestSendBtn');
            if (testBtn) {
                testBtn.addEventListener('click', function () {
                    var dest = document.getElementById('routeDest').value;
                    if (!dest) {
                        alert('Pick a destination channel first.');
                        return;
                    }
                    var routeName = (document.getElementById('routeName').value || 'Untitled route').trim();
                    var routeIdEl = document.getElementById('routeId');
                    var routeId   = parseInt((routeIdEl && routeIdEl.value) || 0, 10);
                    var pred;
                    try { pred = collectRecipientPredicate(); }
                    catch (e) { return; }

                    // Confirm — this WILL send a real notification to whoever
                    // the predicate resolves to. Don't surprise the user.
                    var msg = 'Send a test message right now via "' + dest + '"?\n\n' +
                              (pred ? 'Recipients: whoever your predicate resolves to (Preview them first if unsure).'
                                    : 'Recipients: whoever is on the destination channel (channel broadcast).') +
                              '\n\nThe message body will be clearly marked [TEST].';
                    if (!window.confirm(msg)) return;

                    var resEl = document.getElementById('recipPreviewResult');
                    resEl.style.display = '';
                    resEl.className = 'small mt-2 text-body-secondary';
                    resEl.textContent = 'Sending test…';

                    fetch('api/router-test-send.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            predicate:     pred,
                            dest_channel:  dest,
                            sample_payload: _recipSamplePayload(),
                            route_name:    routeName,
                            route_id:      routeId || null
                        })
                    }).then(function (r) { return r.json(); }).then(function (data) {
                        if (data.error || data.ok === false) {
                            resEl.className = 'small mt-2 text-danger';
                            resEl.textContent = data.note || data.error || 'Test send failed';
                            return;
                        }
                        resEl.className = 'small mt-2 text-success';
                        var html = '<i class="bi bi-check-circle me-1"></i>' + (data.note || 'Test sent.');
                        if (data.recipients_resolved === 0) {
                            html += ' <span class="text-warning">No recipients matched — nothing actually went out. Check your predicate.</span>';
                        }
                        resEl.innerHTML = html;
                    }).catch(function (err) {
                        resEl.className = 'small mt-2 text-danger';
                        resEl.textContent = 'Test-send request failed: ' + err.message;
                    });
                });
            }

            // Populate the sample-ticket dropdown with the 10 most recent
            // open incidents. Lazy-loaded on first modal open via the
            // existing openRouteModal flow — see further below.
            window._recipLoadSampleTickets = function () {
                var sel = document.getElementById('recipSampleTicket');
                if (!sel || sel.options.length > 1) return; // already loaded
                fetch('api/incidents.php?status=open&limit=10', { credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        var list = (data && data.incidents) || (data && data.rows) || [];
                        list.slice(0, 10).forEach(function (inc) {
                            var opt = document.createElement('option');
                            opt.value = inc.id;
                            opt.setAttribute('data-severity', inc.severity || 0);
                            // api/incidents.php returns incident_type (string)
                            // — fall through to alternate keys for forward-compat.
                            var typeLabel = inc.incident_type || inc.type_name || inc.type || 'incident';
                            var label = '#' + (inc.incident_number || inc.id) + ' — ' +
                                        typeLabel +
                                        (inc.scope ? ' (' + (inc.scope.length > 40 ? inc.scope.substr(0, 37) + '…' : inc.scope) + ')' : '');
                            opt.textContent = label;
                            sel.appendChild(opt);
                        });
                    }).catch(function () { /* non-fatal — fallback to synthetic payload */ });
            };
        })();

        // Form submit
        var routeForm = document.getElementById('routeForm');
        if (routeForm) {
            routeForm.addEventListener('submit', function (e) {
                e.preventDefault();
                var data = collectFormData();
                if (!data.name) { alert('Route name is required'); return; }
                if (!data.dest_channel) { alert('Destination channel is required'); return; }

                fetch('api/routing.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(data)
                }).then(function (r) { return r.json(); }).then(function (resp) {
                    if (resp.ok || resp.id) {
                        var modal = bootstrap.Modal.getInstance(document.getElementById('routeModal'));
                        if (modal) modal.hide();
                        loadRoutes();
                        loadStats();
                    } else {
                        alert(resp.error || 'Failed to save route');
                    }
                }).catch(function (err) {
                    alert('Error saving route: ' + err.message);
                });
            });
        }

        // Create button
        var btnCreate = document.getElementById('btnCreateRoute');
        if (btnCreate) {
            btnCreate.addEventListener('click', function () { openRouteModal(null); });
        }

        // View Log button
        var btnLog = document.getElementById('btnViewRoutingLog');
        if (btnLog) {
            btnLog.addEventListener('click', function () {
                if (logSection.style.display === 'none') {
                    logSection.style.display = '';
                    loadRoutingLog(1);
                } else {
                    logSection.style.display = 'none';
                }
            });
        }

        // Load routing log
        function loadRoutingLog(page) {
            logPage = page || 1;
            fetch('api/routing.php?log=1&limit=20&page=' + logPage).then(function (r) { return r.json(); }).then(function (data) {
                var log = data.log || [];
                if (log.length === 0) {
                    logBody.innerHTML = '<tr><td colspan="7" class="text-center text-body-secondary small">No routing activity yet.</td></tr>';
                    logPager.innerHTML = '';
                    return;
                }
                var html = '';
                for (var i = 0; i < log.length; i++) {
                    var l = log[i];
                    var statusBadge = l.status === 'forwarded' ? 'bg-success'
                        : l.status === 'failed' ? 'bg-danger'
                        : l.status === 'loop_blocked' ? 'bg-warning text-dark'
                        : 'bg-secondary';
                    html += '<tr>'
                        + '<td class="small">' + (l.routed_at || '') + '</td>'
                        + '<td class="small">' + _esc(l.route_name || '#' + l.route_id) + '</td>'
                        + '<td><span class="badge bg-secondary">' + _esc(l.source_channel) + '</span></td>'
                        + '<td class="text-center"><i class="bi bi-arrow-right text-body-secondary"></i></td>'
                        + '<td><span class="badge bg-primary">' + _esc(l.dest_channel) + '</span></td>'
                        + '<td><span class="badge ' + statusBadge + '">' + l.status + '</span></td>'
                        + '<td class="small text-truncate" style="max-width:200px" title="' + _esc(l.payload_summary) + '">' + _esc(l.payload_summary ? l.payload_summary.substring(0, 60) : '') + '</td>'
                        + '</tr>';
                }
                logBody.innerHTML = html;

                // Pager
                var pages = data.pages || 1;
                var phtml = '';
                for (var p = 1; p <= Math.min(pages, 10); p++) {
                    phtml += '<li class="page-item' + (p === logPage ? ' active' : '') + '"><button class="page-link log-page" data-page="' + p + '">' + p + '</button></li>';
                }
                logPager.innerHTML = phtml;
                var pageLinks = logPager.querySelectorAll('.log-page');
                for (var pl = 0; pl < pageLinks.length; pl++) {
                    pageLinks[pl].addEventListener('click', function () {
                        loadRoutingLog(parseInt(this.getAttribute('data-page')));
                    });
                }
            });
        }

        // Test Message button
        var btnTest = document.getElementById('btnTestRoute');
        if (btnTest) {
            btnTest.addEventListener('click', function () {
                populateChannelSelect(document.getElementById('testChannel'), false);
                var modal = new bootstrap.Modal(document.getElementById('testRouteModal'));
                modal.show();
            });
        }

        // Run Test
        var btnRunTest = document.getElementById('btnRunTest');
        if (btnRunTest) {
            btnRunTest.addEventListener('click', function () {
                var data = {
                    action: 'test',
                    channel: document.getElementById('testChannel').value,
                    direction: document.getElementById('testDirection').value,
                    body: document.getElementById('testBody').value || 'Test message',
                    priority: document.getElementById('testPriority').value,
                    severity: parseInt(document.getElementById('testSeverity').value) || 0,
                    csrf_token: csrfToken
                };
                fetch('api/routing.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(data)
                }).then(function (r) { return r.json(); }).then(function (resp) {
                    var resultsDiv = document.getElementById('testResults');
                    var resultsBody = document.getElementById('testResultsBody');
                    resultsDiv.style.display = '';

                    if (!resp.matches || resp.matches.length === 0) {
                        resultsBody.innerHTML = '<div class="alert alert-warning small mb-0">No routes matched this message.</div>';
                        return;
                    }

                    var html = '<div class="alert alert-success small mb-1">' + resp.match_count + ' route(s) matched</div>';
                    html += '<table class="table table-sm table-bordered mb-0"><thead><tr><th>Route</th><th>Dest</th><th>Transformed Body</th><th>Priority</th></tr></thead><tbody>';
                    for (var i = 0; i < resp.matches.length; i++) {
                        var m = resp.matches[i];
                        html += '<tr><td class="small">' + _esc(m.route_name) + '</td>'
                            + '<td><span class="badge bg-primary">' + _esc(m.dest) + '</span></td>'
                            + '<td class="small">' + _esc(m.transformed.body ? m.transformed.body.substring(0, 80) : '') + '</td>'
                            + '<td class="small">' + _esc(m.transformed.priority) + '</td></tr>';
                    }
                    html += '</tbody></table>';
                    resultsBody.innerHTML = html;
                });
            });
        }

        // Initial load when panel becomes visible
        var observer = new MutationObserver(function () {
            var panel = document.getElementById('panel-message-routing');
            if (panel && panel.style.display !== 'none' && panel.offsetParent !== null) {
                loadChannels(function () {
                    loadRoutes();
                    loadStats();
                    loadEnabledChannels();
                });
                observer.disconnect();
            }
        });
        observer.observe(document.body, { childList: true, subtree: true, attributes: true });

        // Also load if panel is already visible (direct URL hash)
        var panel = document.getElementById('panel-message-routing');
        if (panel && panel.style.display !== 'none' && panel.offsetParent !== null) {
            loadChannels(function () {
                loadRoutes();
                loadStats();
                loadEnabledChannels();
            });
        }
    }

    // ═══════════════════════════════════════════════════════════════
    //  PAR CHECKS (Phase 16a, 2026-06-11)
    // ═══════════════════════════════════════════════════════════════
    function bindPARConfigPanel() {
        var form = document.getElementById('parConfigForm');
        if (!form) return;

        // 2026-06-11 fix — settings.php exposes CSRF via the
        // #csrfToken hidden input, not the meta tag. The previous
        // build dereferenced a null meta and threw silently, which
        // is why save reported nothing.
        function getCsrf() {
            var el = document.getElementById('csrfToken');
            if (el && el.value) return el.value;
            var meta = document.querySelector('meta[name="csrf-token"]');
            return meta ? meta.getAttribute('content') : '';
        }

        // Master switch — separate POST so admin doesn't have to fill
        // out the whole form to flip enabled on/off.
        var enabledSwitch = document.getElementById('parEnabled');
        if (enabledSwitch) {
            enabledSwitch.addEventListener('change', function () {
                fetch('api/par.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'set_enabled',
                        enabled: enabledSwitch.checked,
                        csrf_token: getCsrf()
                    })
                }).then(function (r) { return r.json(); }).then(function (data) {
                    if (data && data.error) {
                        showAlert('PAR toggle failed: ' + data.error, 'danger');
                        // Revert the toggle UI to the actual saved state
                        enabledSwitch.checked = !enabledSwitch.checked;
                    } else {
                        showAlert(enabledSwitch.checked ? 'PAR enabled' : 'PAR disabled', 'success');
                    }
                }).catch(function (err) {
                    showAlert('PAR toggle network error: ' + err.message, 'danger');
                    enabledSwitch.checked = !enabledSwitch.checked;
                });
            });
        }

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var payload = {
                action:         'save_config',
                cadence_min:    parseInt(document.getElementById('parCadence').value, 10),
                first_window_s: parseInt(document.getElementById('parFirstWindow').value, 10),
                retry_window_s: parseInt(document.getElementById('parRetryWindow').value, 10),
                max_misses:     parseInt(document.getElementById('parMaxMisses').value, 10),
                chat_channel:   document.getElementById('parChatChannel').value,
                standby_behavior: (document.getElementById('parStandbyBehavior') || {}).value || 'recommended',
                mayday_auto:    (document.getElementById('parMaydayAuto') || {}).value || '1',
                csrf_token: getCsrf()
            };
            fetch('api/par.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            }).then(function (r) { return r.json(); }).then(function (data) {
                if (data && data.error) showAlert('PAR save failed: ' + data.error, 'danger');
                else showAlert('PAR settings saved', 'success');
            }).catch(function (err) {
                showAlert('PAR save network error: ' + err.message, 'danger');
            });
        });
    }

    function loadPARConfig() {
        fetch('api/par.php?action=config', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); }).then(function (data) {
                if (data.error) { showAlert(data.error, 'danger'); return; }
                var c = data.config || {};
                var el = document.getElementById('parEnabled');
                if (el) el.checked = !!c.enabled;
                if (document.getElementById('parCadence'))     document.getElementById('parCadence').value     = c.par_default_cadence_min || 20;
                if (document.getElementById('parFirstWindow')) document.getElementById('parFirstWindow').value = c.par_first_window_s || 60;
                if (document.getElementById('parRetryWindow')) document.getElementById('parRetryWindow').value = c.par_retry_window_s || 120;
                if (document.getElementById('parMaxMisses'))   document.getElementById('parMaxMisses').value   = c.par_max_misses || 2;
                if (document.getElementById('parChatChannel')) document.getElementById('parChatChannel').value = c.par_escalation_chat_channel || '';
                if (document.getElementById('parStandbyBehavior')) document.getElementById('parStandbyBehavior').value = c.par_standby_unit_behavior || 'recommended';
                if (document.getElementById('parMaydayAuto'))      document.getElementById('parMaydayAuto').value      = (c.par_mayday_auto_trigger || '1');
            });
    }

    // ═══════════════════════════════════════════════════════════════
    //  SECURITY LABELS (Phase 18b, 2026-06-11)
    // ═══════════════════════════════════════════════════════════════
    function getCsrf() {
        var el = document.getElementById('csrfToken');
        if (el && el.value) return el.value;
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }
    function escH(s) {
        if (s === null || s === undefined) return '';
        var d = document.createElement('div');
        d.textContent = String(s);
        return d.innerHTML;
    }

    function loadSecurityLabels() {
        var list = document.getElementById('secLabelList');
        if (!list) return;
        fetch('api/security-labels.php', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.error) { list.innerHTML = '<div class="alert alert-danger small">' + escH(data.error) + '</div>'; return; }
                var labels = data.labels || [];
                if (labels.length === 0) {
                    list.innerHTML = '<div class="alert alert-warning small">No security labels defined. Click "New Label" to add the first one.</div>';
                    return;
                }
                var html = '<table class="table table-sm table-hover"><thead><tr>' +
                    '<th></th><th>Code</th><th>Name</th><th>EOC</th><th>Routing</th><th>ICS</th><th>Default</th><th></th></tr></thead><tbody>';
                for (var i = 0; i < labels.length; i++) {
                    var l = labels[i];
                    var badge = '<span class="badge" style="background:' + escH(l.badge_bg_color || '#6c757d') + ';color:' + escH(l.badge_text_color || '#fff') + '">' + escH(l.name) + '</span>';
                    var eoc = (l.eoc_show_scope ? 'scope ' : '') + (l.eoc_show_address ? 'addr ' : '') + 'marker=' + escH(l.eoc_show_map_marker);
                    var rt = (l.routing_allow_broadcast ? 'bc ' : 'no-bc ') + (l.routing_send_delay_secs > 0 ? '+' + l.routing_send_delay_secs + 's' : '');
                    var ics = l.ics_watermark_text ? 'WM "' + escH(l.ics_watermark_text) + '"' : (l.ics_export_show_full ? 'full' : 'redacted');
                    html += '<tr data-id="' + l.id + '" style="cursor:pointer">' +
                        '<td>' + badge + '</td>' +
                        '<td><code>' + escH(l.code) + '</code></td>' +
                        '<td>' + escH(l.name) + '</td>' +
                        '<td class="small text-body-secondary">' + eoc + '</td>' +
                        '<td class="small text-body-secondary">' + rt + '</td>' +
                        '<td class="small text-body-secondary">' + ics + '</td>' +
                        '<td>' + (l.is_default == 1 ? '<i class="bi bi-check-circle-fill text-success"></i>' : '') + '</td>' +
                        '<td><button class="btn btn-xs btn-outline-secondary edit-sec-label">Edit</button></td>' +
                        '</tr>';
                }
                html += '</tbody></table>';
                list.innerHTML = html;
                list.querySelectorAll('tr[data-id]').forEach(function (tr) {
                    tr.addEventListener('click', function () {
                        openSecLabelModal(parseInt(tr.getAttribute('data-id'), 10));
                    });
                });
            });
    }

    function openSecLabelModal(id) {
        var modalEl = document.getElementById('secLabelModal');
        if (!modalEl) return;
        var fillFromLabel = function (l) {
            document.getElementById('secLabelId').value = l.id || '';
            document.getElementById('secLabelCode').value = l.code || '';
            document.getElementById('secLabelName').value = l.name || '';
            document.getElementById('secLabelSort').value = l.sort_order || 100;
            document.getElementById('secLabelBg').value = l.badge_bg_color || '#6c757d';
            document.getElementById('secLabelFg').value = l.badge_text_color || '#ffffff';
            document.getElementById('secLabelDefault').checked = (l.is_default == 1);
            document.getElementById('secLabelReqReason').checked = (l.audit_required_reason == 1);
            document.getElementById('secLabelEocScope').checked = (l.eoc_show_scope == 1);
            document.getElementById('secLabelEocAddress').checked = (l.eoc_show_address == 1);
            document.getElementById('secLabelEocMarker').value = l.eoc_show_map_marker || 'full';
            document.getElementById('secLabelEocPlaceholder').value = l.eoc_placeholder_text || '';
            document.getElementById('secLabelRoutingBroadcast').checked = (l.routing_allow_broadcast == 1);
            document.getElementById('secLabelRoutingDirect').checked = (l.routing_allow_direct == 1);
            document.getElementById('secLabelSendDelay').value = l.routing_send_delay_secs || 0;
            document.getElementById('secLabelRecall').value = l.routing_recall_window_s || 0;
            document.getElementById('secLabelIcsFull').checked = (l.ics_export_show_full == 1);
            document.getElementById('secLabelIcsWatermark').value = l.ics_watermark_text || '';
            document.getElementById('btnDeleteSecLabel').style.display = (l.id ? '' : 'none');
            document.getElementById('secLabelModalTitle').textContent = l.id ? ('Edit Label: ' + (l.name || '')) : 'New Security Label';
        };
        if (id) {
            fetch('api/security-labels.php?id=' + id, { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (data) { fillFromLabel(data.label || {}); new bootstrap.Modal(modalEl).show(); });
        } else {
            fillFromLabel({
                eoc_show_scope: 1, eoc_show_address: 1, routing_allow_broadcast: 1, routing_allow_direct: 1, ics_export_show_full: 1,
                badge_bg_color: '#6c757d', badge_text_color: '#ffffff', sort_order: 100
            });
            new bootstrap.Modal(modalEl).show();
        }
    }

    function collectSecLabelFields() {
        return {
            code:                    document.getElementById('secLabelCode').value,
            name:                    document.getElementById('secLabelName').value,
            sort_order:              parseInt(document.getElementById('secLabelSort').value, 10) || 100,
            is_default:              document.getElementById('secLabelDefault').checked ? 1 : 0,
            badge_bg_color:          document.getElementById('secLabelBg').value,
            badge_text_color:        document.getElementById('secLabelFg').value,
            eoc_show_scope:          document.getElementById('secLabelEocScope').checked ? 1 : 0,
            eoc_show_address:        document.getElementById('secLabelEocAddress').checked ? 1 : 0,
            eoc_show_map_marker:     document.getElementById('secLabelEocMarker').value,
            eoc_placeholder_text:    document.getElementById('secLabelEocPlaceholder').value,
            routing_allow_broadcast: document.getElementById('secLabelRoutingBroadcast').checked ? 1 : 0,
            routing_allow_direct:    document.getElementById('secLabelRoutingDirect').checked ? 1 : 0,
            routing_send_delay_secs: parseInt(document.getElementById('secLabelSendDelay').value, 10) || 0,
            routing_recall_window_s: parseInt(document.getElementById('secLabelRecall').value, 10) || 0,
            ics_export_show_full:    document.getElementById('secLabelIcsFull').checked ? 1 : 0,
            ics_watermark_text:      document.getElementById('secLabelIcsWatermark').value,
            audit_required_reason:   document.getElementById('secLabelReqReason').checked ? 1 : 0
        };
    }

    function bindSecurityLabelsPanel() {
        var btnNew = document.getElementById('btnNewSecLabel');
        if (btnNew) btnNew.addEventListener('click', function () { openSecLabelModal(0); });

        var btnSave = document.getElementById('btnSaveSecLabel');
        if (btnSave) btnSave.addEventListener('click', function () {
            var id = parseInt(document.getElementById('secLabelId').value, 10);
            var fields = collectSecLabelFields();
            var payload = id > 0
                ? Object.assign({ action: 'update', id: id, csrf_token: getCsrf() }, fields)
                : Object.assign({ action: 'create',         csrf_token: getCsrf() }, fields);
            fetch('api/security-labels.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            }).then(function (r) { return r.json(); }).then(function (data) {
                if (data && data.error) { showAlert(data.error, 'danger'); return; }
                showAlert('Security label saved.', 'success');
                bootstrap.Modal.getInstance(document.getElementById('secLabelModal')).hide();
                loadSecurityLabels();
            });
        });

        var btnDel = document.getElementById('btnDeleteSecLabel');
        if (btnDel) btnDel.addEventListener('click', function () {
            var id = parseInt(document.getElementById('secLabelId').value, 10);
            if (!id) return;
            if (!confirm('Delete this security label?')) return;
            fetch('api/security-labels.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'delete', id: id, csrf_token: getCsrf() })
            }).then(function (r) { return r.json(); }).then(function (data) {
                if (data && data.error) { showAlert(data.error, 'danger'); return; }
                showAlert('Label deleted.', 'success');
                bootstrap.Modal.getInstance(document.getElementById('secLabelModal')).hide();
                loadSecurityLabels();
            });
        });
    }

    // ═══════════════════════════════════════════════════════════════
    //  PENDING ROUTED MESSAGES (Phase 18e admin panel, 2026-06-11)
    //
    //  Shows the queue of routed messages parked behind a security
    //  label's send-delay. Auto-refreshes every 5s while the
    //  "Pending" filter is active so countdowns stay live.
    // ═══════════════════════════════════════════════════════════════
    var pmStatus = 'pending';
    var pmTimer = null;
    var pmCountdownTimer = null;

    function pmStopTimers() {
        if (pmTimer) { clearInterval(pmTimer); pmTimer = null; }
        if (pmCountdownTimer) { clearInterval(pmCountdownTimer); pmCountdownTimer = null; }
    }

    function pmFriendlyDelta(target) {
        var now = Math.floor(Date.now() / 1000);
        var t = Math.floor(new Date(target.replace(' ', 'T')).getTime() / 1000);
        var diff = t - now;
        if (diff <= 0) return 'sending…';
        var min = Math.floor(diff / 60);
        var sec = diff % 60;
        if (min > 0) return min + 'm ' + sec + 's';
        return sec + 's';
    }

    function loadPendingMessages() {
        pmStopTimers();
        var tableEl = document.getElementById('pendingMsgsTable');
        if (!tableEl) return;
        function refresh() {
            fetch('api/pending-messages.php?status=' + encodeURIComponent(pmStatus) + '&limit=200',
                  { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.error) { tableEl.innerHTML = '<div class="alert alert-danger small">' + escHtml(data.error) + '</div>'; return; }
                    var rows = data.rows || [];
                    document.getElementById('pmCount').textContent =
                        rows.length + ' ' + pmStatus + ' message' + (rows.length === 1 ? '' : 's');
                    if (rows.length === 0) {
                        tableEl.innerHTML = '<div class="text-body-secondary small text-center py-3">No ' + escHtml(pmStatus) + ' messages.</div>';
                        return;
                    }
                    var html = '<div class="table-responsive"><table class="table table-sm table-hover mb-0">' +
                        '<thead><tr>' +
                        '<th>Channel</th><th>Target</th><th>Subject / body</th><th>Incident</th>' +
                        '<th>' + (pmStatus === 'pending' ? 'Sends in' : 'When') + '</th>' +
                        (pmStatus === 'pending' ? '<th class="text-end"></th>' : '<th></th>') +
                        '</tr></thead><tbody>';
                    for (var i = 0; i < rows.length; i++) {
                        var r = rows[i];
                        var whenCell = '';
                        if (pmStatus === 'pending') {
                            whenCell = '<span class="font-monospace small" data-pm-target="' + escHtml(r.scheduled_send_at) + '">' +
                                       pmFriendlyDelta(r.scheduled_send_at) + '</span>';
                        } else if (pmStatus === 'sent') {
                            whenCell = '<span class="small text-body-secondary">' + escHtml(r.sent_at || '') + '</span>';
                        } else if (pmStatus === 'killed') {
                            whenCell = '<span class="small text-body-secondary">' + escHtml(r.killed_at || '') + '</span>' +
                                       (r.killed_reason ? '<br><span class="small text-body-secondary fst-italic">"' + escHtml(r.killed_reason) + '"</span>' : '');
                        } else if (pmStatus === 'failed') {
                            whenCell = '<span class="small text-danger">' + escHtml(r.send_error || 'unknown error') + '</span>';
                        }
                        var actionCell = '';
                        if (pmStatus === 'pending') {
                            actionCell = '<td class="text-end"><button class="btn btn-sm btn-outline-danger pm-kill" data-id="' + r.id + '">' +
                                         '<i class="bi bi-x-octagon me-1"></i>Kill</button></td>';
                        } else {
                            actionCell = '<td></td>';
                        }
                        var snippet = (r.subject ? escHtml(r.subject) + ' — ' : '') + escHtml((r.body || '').slice(0, 80));
                        html += '<tr>' +
                            '<td><code class="small">' + escHtml(r.channel) + '</code></td>' +
                            '<td class="small">' + escHtml(r.target) + '</td>' +
                            '<td class="small">' + snippet + '</td>' +
                            '<td class="small">' + (r.ticket_id ? '<a href="incident-detail.php?id=' + r.ticket_id + '">#' + r.ticket_id + '</a>' : '—') + '</td>' +
                            '<td>' + whenCell + '</td>' +
                            actionCell +
                            '</tr>';
                    }
                    html += '</tbody></table></div>';
                    tableEl.innerHTML = html;

                    // Wire kill buttons
                    tableEl.querySelectorAll('.pm-kill').forEach(function (btn) {
                        btn.addEventListener('click', function () {
                            var id = parseInt(btn.getAttribute('data-id'), 10);
                            var reason = prompt('Kill this pending message?\n\nOptional reason (will be audit-logged):');
                            if (reason === null) return;
                            fetch('api/pending-messages.php', {
                                method: 'POST',
                                credentials: 'same-origin',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({
                                    action: 'kill', id: id, reason: reason,
                                    csrf_token: getCsrf()
                                })
                            }).then(function (r) { return r.json(); }).then(function (data) {
                                if (data && data.error) { showAlert(data.error, 'danger'); return; }
                                showAlert('Message killed.', 'success');
                                refresh();
                            });
                        });
                    });
                });
        }
        refresh();
        // Polling: 5s for pending tab; longer for historical tabs (the data is static).
        if (pmStatus === 'pending') {
            pmTimer = setInterval(refresh, 5000);
            // Tick countdown cells every second without re-fetching.
            pmCountdownTimer = setInterval(function () {
                document.querySelectorAll('[data-pm-target]').forEach(function (el) {
                    el.textContent = pmFriendlyDelta(el.getAttribute('data-pm-target'));
                });
            }, 1000);
        }
    }

    function bindPendingMessagesPanel() {
        var filt = document.getElementById('pmFilter');
        if (filt) {
            filt.querySelectorAll('button').forEach(function (b) {
                b.addEventListener('click', function () {
                    filt.querySelectorAll('button').forEach(function (x) { x.classList.remove('active'); });
                    b.classList.add('active');
                    pmStatus = b.getAttribute('data-status') || 'pending';
                    loadPendingMessages();
                });
            });
        }
        var refresh = document.getElementById('pmRefresh');
        if (refresh) refresh.addEventListener('click', loadPendingMessages);
    }

    // ═══════════════════════════════════════════════════════════════
    //  DMR TALKGROUPS (Phase 99e v2 — 2026-06-28)
    // ═══════════════════════════════════════════════════════════════
    var _tgCache = [];
    // Phase 99e v3 (2026-06-28) — header-click sortable state.
    // Default: by sort_order ASC (matches the server-side order).
    var _tgSort = { key: 'sort_order', dir: 'asc' };

    function bindTalkgroupsPanel() {
        var addBtn = document.getElementById('btnAddTalkgroup');
        if (!addBtn) return;  // panel not in DOM

        addBtn.addEventListener('click', function () {
            _tgOpenEditModal(null);
        });

        var filter = document.getElementById('tgFilter');
        if (filter) filter.addEventListener('input', _tgRenderTable);

        // Bind column-header click → sort. Single delegate on the panel.
        var panel = document.getElementById('panel-talkgroups');
        if (panel) {
            panel.addEventListener('click', function (e) {
                var th = e.target.closest('.tg-sortable');
                if (!th) return;
                var key = th.getAttribute('data-sort-key');
                if (!key) return;
                if (_tgSort.key === key) {
                    _tgSort.dir = (_tgSort.dir === 'asc') ? 'desc' : 'asc';
                } else {
                    _tgSort.key = key;
                    _tgSort.dir = 'asc';
                }
                _tgRenderTable();
            });
        }

        var form = document.getElementById('tgEditForm');
        if (form) {
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                _tgSave();
            });
        }
        var delBtn = document.getElementById('tgEditDelete');
        if (delBtn) delBtn.addEventListener('click', _tgDelete);

        var importBtn = document.getElementById('btnTgImportRun');
        if (importBtn) importBtn.addEventListener('click', _tgRunImport);
    }

    function loadTalkgroups() {
        // api/talkgroups.php is a STANDALONE endpoint (not part of the
        // settings.php section dispatcher), so use fetchJSON directly
        // rather than apiGet (which prepends ?section=).
        fetchJSON('api/talkgroups.php').then(function (data) {
            _tgCache = data.talkgroups || [];
            _tgRenderTable();
        }).catch(function (err) {
            var tbody = document.getElementById('tgTableBody');
            if (tbody) tbody.innerHTML = '<tr><td colspan="7" class="text-center text-danger py-3">Load failed: ' + (err.message || err) + '</td></tr>';
        });
    }

    function _tgRenderTable() {
        var tbody = document.getElementById('tgTableBody');
        if (!tbody) return;
        var filter = (document.getElementById('tgFilter') || {}).value || '';
        filter = filter.trim().toLowerCase();

        var rows = _tgCache.slice().filter(function (r) {
            if (!filter) return true;
            var hay = (r.name + ' ' + (r.description || '') + ' ' + r.dmr_id).toLowerCase();
            return hay.indexOf(filter) !== -1;
        });

        // Phase 99e v3 — apply current sort (header click toggles asc/desc).
        var key = _tgSort.key, dir = _tgSort.dir;
        var sign = (dir === 'desc') ? -1 : 1;
        rows.sort(function (a, b) {
            var av = a[key], bv = b[key];
            // Numerics (dmr_id, sort_order, enabled) sort numerically.
            if (key === 'dmr_id' || key === 'sort_order' || key === 'enabled') {
                return sign * ((parseInt(av, 10) || 0) - (parseInt(bv, 10) || 0));
            }
            // Strings — case-insensitive natural sort.
            av = (av == null ? '' : String(av)).toLowerCase();
            bv = (bv == null ? '' : String(bv)).toLowerCase();
            if (av < bv) return -sign;
            if (av > bv) return sign;
            return 0;
        });

        // Update header chevrons to show the active column.
        var panel = document.getElementById('panel-talkgroups');
        if (panel) {
            panel.querySelectorAll('.tg-sortable').forEach(function (th) {
                var k = th.getAttribute('data-sort-key');
                var icon = th.querySelector('i');
                if (!icon) return;
                if (k === key) {
                    icon.className = 'bi ' + (dir === 'asc' ? 'bi-arrow-up' : 'bi-arrow-down') + ' text-primary small';
                } else {
                    icon.className = 'bi bi-arrow-down-up text-body-tertiary small';
                }
            });
        }

        if (rows.length === 0) {
            tbody.innerHTML = '<tr><td colspan="7" class="text-center text-body-secondary py-3">' +
                (_tgCache.length === 0 ? 'No talkgroups configured.' : 'No matches for filter.') + '</td></tr>';
            return;
        }

        // Drag-and-drop only makes sense when sorted by sort_order ASC —
        // dragging when sorted by Name would scatter rows in a way
        // that doesn't match the visible order. tbody class drives the
        // dragstart guard + handle cursor styling.
        var canDrag = (key === 'sort_order' && dir === 'asc' && !filter);
        tbody.classList.toggle('tg-can-drag', canDrag);

        var html = '';
        rows.forEach(function (r) {
            var ct = r.call_type || 'group';
            var ctBadge = ct === 'private'
                ? '<span class="badge bg-warning text-dark" style="font-size:0.65rem;">private</span>'
                : '<span class="badge bg-info" style="font-size:0.65rem;">group</span>';
            var enabled = parseInt(r.enabled, 10) === 1;
            html += '<tr data-tg-id="' + r.id + '"' + (canDrag ? ' draggable="true"' : '') + '>' +
                '<td class="tg-drag-handle" title="' + (canDrag ? 'Drag to reorder' : 'Sort by Sort column ASC + clear filter to drag') + '">' +
                    '<i class="bi bi-grip-vertical"></i>' +
                '</td>' +
                '<td class="font-monospace">' + esc(String(r.dmr_id)) + '</td>' +
                '<td class="fw-semibold">' + esc(r.name) + '</td>' +
                '<td class="small text-body-secondary">' + esc(r.description || '') + '</td>' +
                '<td>' + ctBadge + '</td>' +
                '<td class="tg-sort-cell text-body-secondary small" data-id="' + r.id + '" title="Click to edit — type a number and this row lands above the row currently at that number">' +
                    (r.sort_order || 0) +
                '</td>' +
                '<td class="text-center">' +
                    '<input type="checkbox" class="form-check-input tg-enabled-toggle" data-id="' + r.id + '" ' +
                    (enabled ? 'checked' : '') + '>' +
                '</td>' +
                '<td class="text-end">' +
                    '<button type="button" class="btn btn-sm btn-outline-secondary tg-edit-btn" data-id="' + r.id + '">' +
                        '<i class="bi bi-pencil"></i>' +
                    '</button>' +
                '</td>' +
                '</tr>';
        });
        tbody.innerHTML = html;

        // Wire drag-and-drop (only meaningful when sortable order matches DOM).
        if (canDrag) _tgWireDragAndDrop(tbody);

        // Wire click-to-edit on the sort cell.
        tbody.querySelectorAll('.tg-sort-cell').forEach(function (cell) {
            cell.addEventListener('click', function () { _tgEditSortInline(this); });
        });

        // Wire row events.
        tbody.querySelectorAll('.tg-edit-btn').forEach(function (b) {
            b.addEventListener('click', function () {
                var id = parseInt(this.getAttribute('data-id'), 10);
                var row = _tgCache.find(function (x) { return parseInt(x.id, 10) === id; });
                if (row) _tgOpenEditModal(row);
            });
        });
        // Quick-toggle enabled checkbox without opening the modal.
        // Phase 99e v3 followup (2026-06-28) — uses the dedicated
        // action='set_enabled' endpoint that touches ONLY the enabled
        // column. The earlier full-row POST was clobbering sort_order
        // with stale cache values when Eric edited sort first then
        // toggled enabled before the GET-reload finished.
        tbody.querySelectorAll('.tg-enabled-toggle').forEach(function (cb) {
            cb.addEventListener('change', function () {
                var id = parseInt(this.getAttribute('data-id'), 10);
                var row = _tgCache.find(function (x) { return parseInt(x.id, 10) === id; });
                if (!row) return;
                var newEnabled = this.checked ? 1 : 0;
                _tgPostSave({
                    action: 'set_enabled',
                    id: row.id,
                    enabled: newEnabled
                }).then(function () {
                    row.enabled = newEnabled;
                });
            });
        });
    }

    // ── Drag-and-drop reorder (Phase 99e v4, 2026-06-28) ───────────
    // Native HTML5 DnD — no external library. Only active when the
    // current sort = sort_order ASC (so DOM order matches the
    // underlying sort_order column and a drop produces a meaningful
    // renumber).
    var _tgDraggedId = null;

    function _tgWireDragAndDrop(tbody) {
        tbody.querySelectorAll('tr[draggable="true"]').forEach(function (row) {
            row.addEventListener('dragstart', function (e) {
                _tgDraggedId = this.getAttribute('data-tg-id');
                this.classList.add('tg-dragging');
                e.dataTransfer.effectAllowed = 'move';
                e.dataTransfer.setData('text/plain', _tgDraggedId);
            });
            row.addEventListener('dragend', function () {
                this.classList.remove('tg-dragging');
                tbody.querySelectorAll('.tg-drop-target').forEach(function (r) {
                    r.classList.remove('tg-drop-target');
                });
                _tgDraggedId = null;
            });
            row.addEventListener('dragover', function (e) {
                if (!_tgDraggedId) return;
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';
                tbody.querySelectorAll('.tg-drop-target').forEach(function (r) {
                    r.classList.remove('tg-drop-target');
                });
                this.classList.add('tg-drop-target');
            });
            row.addEventListener('drop', function (e) {
                e.preventDefault();
                if (!_tgDraggedId || _tgDraggedId === this.getAttribute('data-tg-id')) return;
                _tgReorderRows(_tgDraggedId, this.getAttribute('data-tg-id'));
            });
        });
    }

    // Move dragged_id to land right before target_id in the cache,
    // then push the new order to the server.
    function _tgReorderRows(draggedId, targetId) {
        // Build a fresh ordered ID array based on the current sort_order
        // of the cached rows (not the DOM, which may have a filter applied).
        var ordered = _tgCache.slice().sort(function (a, b) {
            return (parseInt(a.sort_order, 10) || 0) - (parseInt(b.sort_order, 10) || 0);
        }).map(function (r) { return String(r.id); });

        var draggedIdx = ordered.indexOf(String(draggedId));
        var targetIdx  = ordered.indexOf(String(targetId));
        if (draggedIdx < 0 || targetIdx < 0) return;

        ordered.splice(draggedIdx, 1);
        // After splice, if dragged was BEFORE target, the targetIdx
        // shifted left by 1. Recompute insertion point.
        var insertAt = ordered.indexOf(String(targetId));
        ordered.splice(insertAt, 0, String(draggedId));

        _tgPostReorder(ordered);
    }

    function _tgPostReorder(orderedIds) {
        _tgPostSave({ action: 'reorder', order: orderedIds }).then(function (data) {
            if (!data || data.error) return;
            loadTalkgroups();
        });
    }

    // ── Inline sort-cell editing (Phase 99e v4) ─────────────────────
    // Click cell → input. Blur / Enter saves with smart-insert
    // semantic: "type N to land above the row currently at sort=N".
    // Esc cancels.
    function _tgEditSortInline(cell) {
        if (cell.querySelector('input')) return;  // already editing
        var id = cell.getAttribute('data-id');
        var current = parseInt(cell.textContent, 10) || 0;

        var input = document.createElement('input');
        input.type = 'number';
        input.className = 'tg-sort-input';
        input.value = current;
        cell.textContent = '';
        cell.appendChild(input);
        input.focus();
        input.select();

        var done = false;
        function commit() {
            if (done) return; done = true;
            var newVal = parseInt(input.value, 10);
            if (isNaN(newVal) || newVal === current) {
                cell.textContent = current;
                return;
            }
            _tgSmartInsertSort(id, newVal);
        }
        function cancel() {
            if (done) return; done = true;
            cell.textContent = current;
        }
        input.addEventListener('blur', commit);
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); commit(); }
            else if (e.key === 'Escape') { e.preventDefault(); cancel(); }
        });
    }

    // "Smart insert" — user typed `desiredSort` in a row's sort cell.
    // The row becomes exactly sort=desiredSort. The row that WAS at
    // desiredSort gets pushed to desiredSort+1, and so on cascade.
    // Equivalent to: pull target out, insert at index (desiredSort-1)
    // in the ordered list, then renumber 1, 2, 3, ...
    //
    // Bounds:
    //   - desiredSort <= 1: target lands at the top (sort=1)
    //   - desiredSort >= total rows: target lands at the bottom
    function _tgSmartInsertSort(targetId, desiredSort) {
        var ordered = _tgCache.slice().sort(function (a, b) {
            return (parseInt(a.sort_order, 10) || 0) - (parseInt(b.sort_order, 10) || 0);
        }).map(function (r) { return String(r.id); });

        var withoutTarget = ordered.filter(function (x) { return x !== String(targetId); });

        // Clamp + convert to 0-indexed insertion point.
        var insertAt = Math.max(0, Math.min(withoutTarget.length, desiredSort - 1));
        withoutTarget.splice(insertAt, 0, String(targetId));

        _tgPostReorder(withoutTarget);
    }

    function _tgOpenEditModal(row) {
        var modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('tgEditModal'));
        var isEdit = !!row;
        document.getElementById('tgEditModalTitle').textContent = isEdit ? 'Edit talkgroup' : 'Add talkgroup';
        document.getElementById('tgEditId').value       = isEdit ? row.id : '';
        document.getElementById('tgEditDmrId').value    = isEdit ? row.dmr_id : '';
        document.getElementById('tgEditName').value     = isEdit ? row.name : '';
        document.getElementById('tgEditDesc').value     = isEdit ? (row.description || '') : '';
        document.getElementById('tgEditCallType').value = isEdit ? (row.call_type || 'group') : 'group';
        document.getElementById('tgEditSort').value     = isEdit ? (row.sort_order || 0) : 100;
        document.getElementById('tgEditEnabled').checked = isEdit ? (parseInt(row.enabled, 10) === 1) : true;
        document.getElementById('tgEditDelete').classList.toggle('d-none', !isEdit);
        modal.show();
    }

    function _tgSave() {
        var payload = {
            id:          document.getElementById('tgEditId').value || null,
            dmr_id:      parseInt(document.getElementById('tgEditDmrId').value, 10),
            name:        document.getElementById('tgEditName').value.trim(),
            description: document.getElementById('tgEditDesc').value.trim(),
            call_type:   document.getElementById('tgEditCallType').value,
            sort_order:  parseInt(document.getElementById('tgEditSort').value, 10) || 0,
            enabled:     document.getElementById('tgEditEnabled').checked ? 1 : 0,
        };
        _tgPostSave(payload).then(function (data) {
            if (!data || data.error) return;
            bootstrap.Modal.getInstance(document.getElementById('tgEditModal')).hide();
            loadTalkgroups();
            showAlert('Talkgroup saved.', 'success');
        });
    }

    function _tgPostSave(payload) {
        return fetch('api/talkgroups.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': getCsrf() },
            body: JSON.stringify(payload)
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data && data.error) showAlert(data.error, 'danger');
            return data;
        })
        .catch(function (err) {
            showAlert('Save failed: ' + err.message, 'danger');
            return null;
        });
    }

    function _tgDelete() {
        var id = parseInt(document.getElementById('tgEditId').value, 10);
        if (!id) return;
        if (!confirm('Delete this talkgroup? This is irreversible.')) return;
        fetch('api/talkgroups.php?id=' + id, {
            method: 'DELETE',
            credentials: 'same-origin',
            headers: { 'X-CSRF-Token': getCsrf() }
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.error) {
                showAlert('Delete failed: ' + data.error, 'danger');
                return;
            }
            bootstrap.Modal.getInstance(document.getElementById('tgEditModal')).hide();
            loadTalkgroups();
            showAlert('Talkgroup deleted.', 'success');
        });
    }

    function _tgRunImport() {
        var csv     = document.getElementById('tgImportCsv').value;
        var replace = document.getElementById('tgImportReplace').checked;
        var resultEl = document.getElementById('tgImportResult');
        if (!csv.trim()) {
            if (resultEl) resultEl.innerHTML = '<span class="text-warning">Paste a CSV first.</span>';
            return;
        }
        var btn = document.getElementById('btnTgImportRun');
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Importing...';
        fetch('api/talkgroups.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': getCsrf() },
            body: JSON.stringify({ action: 'import_csv', csv: csv, replace_existing: replace })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-upload me-1"></i>Import';
            if (data.error) {
                if (resultEl) resultEl.innerHTML = '<span class="text-danger">' + esc(data.error) + '</span>';
                return;
            }
            var s = data.stats || {};
            var html = '<span class="text-success">' +
                'Imported: ' + (s.inserted || 0) + ', skipped: ' + (s.skipped || 0) +
                ' (' + (s.rows_seen || 0) + ' rows seen).' +
                '</span>';
            if (s.errors && s.errors.length) {
                html += '<ul class="mt-1 mb-0 small text-danger">';
                s.errors.slice(0, 5).forEach(function (e) {
                    html += '<li>' + esc(e) + '</li>';
                });
                if (s.errors.length > 5) html += '<li>+ ' + (s.errors.length - 5) + ' more</li>';
                html += '</ul>';
            }
            if (resultEl) resultEl.innerHTML = html;
            loadTalkgroups();
        })
        .catch(function (err) {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-upload me-1"></i>Import';
            if (resultEl) resultEl.innerHTML = '<span class="text-danger">Network error: ' + esc(err.message) + '</span>';
        });
    }

    // ── Boot ────────────────────────────────────────────────────────
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
