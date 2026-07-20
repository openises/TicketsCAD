/**
 * NewUI v4.0 - Facilities List Page Logic
 *
 * Handles: fetch/render facility table, map with markers,
 * search/filter, type category buttons.
 */

(function () {
    'use strict';

    // ── State ──
    var allFacilities = [];
    var categories = [];
    var map = null;
    var markers = [];
    var markerGroup = null;
    var activeFilter = 'all';
    var searchTerm = '';
    var showHidden = false;

    // ── Initialise on DOM ready ──
    document.addEventListener('DOMContentLoaded', function () {
        initTheme();
        initMap();
        loadFacilities();
        bindEvents();
    });

    // ── Theme toggle ──
    function initTheme() {
        var btns = document.querySelectorAll('#themeToggle button');
        btns.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var theme = this.dataset.theme;
                document.documentElement.setAttribute('data-bs-theme', theme === 'Night' ? 'dark' : 'light');
                btns.forEach(function (b) {
                    b.className = 'btn ' + (b.dataset.theme === theme
                        ? (theme === 'Day' ? 'btn-warning' : 'btn-primary')
                        : 'btn-outline-secondary');
                });
            });
        });
    }

    // ── Map ──
    function initMap() {
        // Shared MapDefaults loader (assets/js/map-defaults.js) — one
        // canonical source for default coords / zoom. Falls back internally
        // on fetch failure so this caller doesn't need a .catch.
        var loader = (window.MapDefaults && window.MapDefaults.load)
            ? window.MapDefaults.load()
            : Promise.resolve({ lat: 44.9778, lng: -93.2650, zoom: 12 });
        loader.then(function (d) {
            map = L.map('facilitiesMap', { zoomControl: true }).setView([d.lat, d.lng], d.zoom);
            if (window.TypeIcons && TypeIcons.bindLabelZoom) { TypeIcons.bindLabelZoom(map); }  // GH #76
            addMapLayers(map);
            markerGroup = L.featureGroup().addTo(map);
            setTimeout(function () { map.invalidateSize(); }, 200);
        });
    }

    // ── Basemap + Weather Layer Controls ──
    function addMapLayers(mapInstance) {
        // Base layers
        var osmLight = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OSM', maxZoom: 19
        });
        var cartoDark = L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
            attribution: '&copy; CartoDB', maxZoom: 19
        });
        var topoMap = L.tileLayer('https://{s}.tile.opentopomap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenTopoMap', maxZoom: 17
        });

        // Add the preferred default basemap from user prefs
        var prefKey = window.MapPrefs ? window.MapPrefs.getBasemap() : (document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'dark' : 'street');
        var prefMap = { street: osmLight, dark: cartoDark, terrain: topoMap };
        (prefMap[prefKey] || osmLight).addTo(mapInstance);

        var baseMaps = {
            'Street Map': osmLight,
            'Dark': cartoDark,
            'Terrain': topoMap
        };

        // Weather overlays via caching proxy (fail gracefully if no API key)
        var weatherOpts = { opacity: 0.5, maxZoom: 19, errorTileUrl: '' };
        var weatherTemp = L.tileLayer('api/weather-proxy.php?type=tile&layer=temp&z={z}&x={x}&y={y}', weatherOpts);
        var weatherPrecip = L.tileLayer('api/weather-proxy.php?type=tile&layer=precipitation_cls&z={z}&x={x}&y={y}', weatherOpts);
        var weatherWind = L.tileLayer('api/weather-proxy.php?type=tile&layer=wind&z={z}&x={x}&y={y}', weatherOpts);
        var weatherClouds = L.tileLayer('api/weather-proxy.php?type=tile&layer=clouds_cls&z={z}&x={x}&y={y}', weatherOpts);

        var overlays = {
            'Temperature': weatherTemp,
            'Precipitation': weatherPrecip,
            'Wind': weatherWind,
            'Clouds': weatherClouds
        };

        var facLayersControl = L.control.layers(baseMaps, overlays, { collapsed: true, position: 'topright' }).addTo(mapInstance);

        // ── Configured tile provider (specs/configurable-tile-providers-2026-06) ──
        // Fold the admin-configured Tile Provider in as an additional base
        // layer once map-prefs.js has fetched it. Additive + async; the
        // built-in Street/Dark/Terrain options and default are unchanged.
        if (window.MapPrefs && typeof window.MapPrefs.init === 'function') {
            window.MapPrefs.init().then(function () {
                var label = window.MapPrefs.getCustomLabel();
                if (label && !facLayersControl._ticketsCustomAdded) {
                    facLayersControl.addBaseLayer(window.MapPrefs.makeLayer('custom'), label);
                    facLayersControl._ticketsCustomAdded = true;
                }
            });
        }
    }

    // ── Load facilities from API ──
    function loadFacilities() {
        fetch('api/facilities.php')
            .then(function (r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.json();
            })
            .then(function (data) {
                if (data.error) {
                    showAlert(escHtml(data.error), 'danger');
                    document.getElementById('loadingSpinner').classList.add('d-none');
                    return;
                }

                allFacilities = data.facilities || [];
                categories = data.categories || [];

                buildTypeFilters();
                renderTable();
                renderMapMarkers();

                document.getElementById('facilityCount').textContent = allFacilities.length;
                document.getElementById('loadingSpinner').classList.add('d-none');
                document.getElementById('mainContent').classList.remove('d-none');

                // Fix map size after display
                setTimeout(function () {
                    if (map) map.invalidateSize();
                }, 100);
            })
            .catch(function (err) {
                showAlert('Failed to load facilities: ' + escHtml(err.message), 'danger');
                document.getElementById('loadingSpinner').classList.add('d-none');
            });
    }

    // ── Build type filter buttons ──
    function buildTypeFilters() {
        var container = document.getElementById('typeFilters');
        if (!container || categories.length === 0) return;

        // Keep the "All" button, add category buttons
        categories.forEach(function (cat) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn btn-outline-secondary';
            btn.setAttribute('data-filter', cat);
            btn.textContent = cat;
            btn.addEventListener('click', function () {
                activeFilter = cat;
                updateFilterButtons();
                renderTable();
                renderMapMarkers();
            });
            container.appendChild(btn);
        });
    }

    function updateFilterButtons() {
        var btns = document.querySelectorAll('#typeFilters button');
        btns.forEach(function (btn) {
            var f = btn.getAttribute('data-filter');
            if (f === activeFilter) {
                btn.classList.add('active');
            } else {
                btn.classList.remove('active');
            }
        });
    }

    // ── Filter facilities based on current state ──
    function getFiltered() {
        var term = searchTerm.toLowerCase();
        return allFacilities.filter(function (f) {
            // Hide filter
            if (!showHidden && f.hide) return false;

            // Type filter
            if (activeFilter !== 'all' && f.type_name !== activeFilter) return false;

            // Search filter
            if (term) {
                var searchable = [f.name, f.handle, f.type_name, f.status_name, f.city, f.street, f.description]
                    .filter(Boolean).join(' ').toLowerCase();
                if (searchable.indexOf(term) === -1) return false;
            }

            return true;
        });
    }

    // ── Render table ──
    function renderTable() {
        var tbody = document.getElementById('facilitiesBody');
        var noResults = document.getElementById('noResults');
        if (!tbody) return;

        var filtered = getFiltered();

        if (filtered.length === 0) {
            tbody.innerHTML = '';
            noResults.classList.remove('d-none');
            return;
        }
        noResults.classList.add('d-none');

        var html = '';
        filtered.forEach(function (f) {
            // GH #69 — the API now sends beds_a/beds_o (null when the
            // facility has never had bed counts configured; != null
            // catches both null and undefined).
            var bedsHtml = '--';
            if (f.beds_a != null || f.beds_o != null) {
                bedsHtml = '<span class="text-success fw-bold">' + (f.beds_a || 0) + '</span>' +
                           ' / <span class="text-warning">' + (f.beds_o || 0) + '</span>';
            }

            // GH #49 — route through the shared helper so this page (the
            // reference the other surfaces were told to match) stays the
            // canonical rendering. Same output as before for a status;
            // '' when statusless.
            var statusBadge = window.FacilityStatus
                ? window.FacilityStatus.badge(f)
                : (f.status_name
                    ? '<span class="badge" style="background-color:' + escHtml(f.bg_color) +
                      ';color:' + escHtml(f.text_color) + ';">' + escHtml(f.status_name) + '</span>'
                    : '');

            var typeBadge = f.type_name
                ? '<span class="badge bg-info bg-opacity-75">' + escHtml(f.type_name) + '</span>'
                : '<span class="text-body-secondary">--</span>';

            var updated = f.updated ? formatDate(f.updated) : '--';

            var hasCoords = f.lat && f.lng;
            var cityContent = escHtml(f.city || '');
            if (hasCoords) {
                cityContent += ' <i class="bi bi-geo-alt-fill text-body-tertiary" style="font-size:0.7rem;"></i>';
            }

            html += '<tr class="facility-row' + (f.hide ? ' opacity-50' : '') + '" data-id="' + f.id + '"'
                + ' data-lat="' + (f.lat || '') + '" data-lng="' + (f.lng || '') + '">' +
                '<td class="ps-3 facility-name-cell" style="cursor:pointer;">' +
                    '<div class="fw-semibold">' + escHtml(f.name) + '</div>' +
                    (f.handle ? '<small class="text-body-secondary">' + escHtml(f.handle) + '</small>' : '') +
                '</td>' +
                '<td>' + typeBadge + '</td>' +
                '<td>' + statusBadge + '</td>' +
                '<td class="text-center">' + bedsHtml + '</td>' +
                '<td class="facility-loc-cell" style="cursor:' + (hasCoords ? 'pointer' : 'default') + ';">' + cityContent + '</td>' +
                '<td class="small text-body-secondary">' + updated + '</td>' +
                '</tr>';
        });

        tbody.innerHTML = html;

        // Update map marker count
        var mapCount = document.getElementById('mapMarkerCount');
        if (mapCount) {
            mapCount.textContent = filtered.length + ' markers';
        }
    }

    // ── Render map markers ──
    function renderMapMarkers() {
        if (!map || !markerGroup) return;

        markerGroup.clearLayers();
        markers = [];

        var filtered = getFiltered();
        var bounds = [];

        filtered.forEach(function (f) {
            if (!f.lat || !f.lng) return;

            // GH #82 — colour by STATUS (fac_status bg_color) so open/closed
            // reads at a glance; fall back to the type-name palette when no
            // status colour is configured.
            var typeColor = getMarkerColor(f.type_name);
            var statusColor = (f.bg_color && String(f.bg_color).toLowerCase() !== '#ffffff'
                                && String(f.bg_color).toLowerCase() !== '#fff')
                ? f.bg_color : typeColor;
            // GH #82/#76 — type glyph in a status-coloured square badge + name
            // label. Falls back to the plain dot if the shared builder isn't up.
            var icon = (window.TypeIcons && window.TypeIcons.markerDivIcon)
                ? window.TypeIcons.markerDivIcon(f.type_icon, statusColor,
                    { label: (f.name || ''), square: true })
                : L.divIcon({
                    className: 'facility-marker',
                    html: '<div class="facility-marker-dot" style="background-color:' + typeColor + ';"></div>',
                    iconSize: [14, 14],
                    iconAnchor: [7, 7]
                });

            var popupContent = '<div class="small">' +
                '<strong>' + escHtml(f.name) + '</strong><br>' +
                (f.type_name ? '<span class="text-body-secondary">' + escHtml(f.type_name) + '</span><br>' : '') +
                (f.city ? escHtml(f.city) + '<br>' : '') +
                (f.beds_a != null ? 'Beds: ' + f.beds_a + ' avail / ' + (f.beds_o || 0) + ' occ<br>' : '') +
                '<a href="facility-detail.php?id=' + f.id + '">View Details</a>' +
                '</div>';

            var marker = L.marker([f.lat, f.lng], { icon: icon })
                .bindPopup(popupContent);

            markerGroup.addLayer(marker);
            markers.push(marker);
            bounds.push([f.lat, f.lng]);
        });

        // Fit map to show all markers, or fall back to default view
        if (markerGroup.getLayers().length > 0) {
            // Delay fitBounds slightly to ensure map is fully rendered
            setTimeout(function () {
                try {
                    map.invalidateSize();
                    var b = markerGroup.getBounds();
                    if (b.isValid()) {
                        map.fitBounds(b.pad(0.15), { maxZoom: 15, animate: false });
                    }
                } catch (e) {
                    // Bounds calculation failed — ignore
                }
            }, 300);
        }
    }

    // ── Color assignment for facility types ──
    function getMarkerColor(typeName) {
        var colors = {
            'Hospital': '#dc3545',
            'Fire Station': '#fd7e14',
            'Police Station': '#0d6efd',
            'Shelter': '#198754',
            'Clinic': '#6f42c1',
            'EMS Station': '#ffc107',
            'Command Post': '#20c997'
        };
        return colors[typeName] || '#6c757d';
    }

    // ── Bind events ──
    function bindEvents() {
        // Search input
        var searchInput = document.getElementById('facilitySearch');
        if (searchInput) {
            var searchTimeout = null;
            searchInput.addEventListener('input', function () {
                clearTimeout(searchTimeout);
                var val = this.value;
                searchTimeout = setTimeout(function () {
                    searchTerm = val;
                    renderTable();
                    renderMapMarkers();
                }, 200);
            });
        }

        // "All" filter button
        var allBtn = document.querySelector('#typeFilters [data-filter="all"]');
        if (allBtn) {
            allBtn.addEventListener('click', function () {
                activeFilter = 'all';
                updateFilterButtons();
                renderTable();
                renderMapMarkers();
            });
        }

        // Show hidden toggle
        var chkHidden = document.getElementById('chkShowHidden');
        if (chkHidden) {
            chkHidden.addEventListener('change', function () {
                showHidden = this.checked;
                renderTable();
                renderMapMarkers();
            });
        }

        // Table cell clicks: name -> detail page, city/location -> zoom map
        document.addEventListener('click', function (e) {
            // Location cell click -> zoom map
            var locCell = e.target.closest('.facility-loc-cell');
            if (locCell) {
                var row = locCell.closest('.facility-row');
                if (row && map) {
                    var lat = parseFloat(row.getAttribute('data-lat'));
                    var lng = parseFloat(row.getAttribute('data-lng'));
                    if (lat && lng) {
                        map.setView([lat, lng], 16, { animate: true });
                    }
                }
                return;
            }

            // Name cell click -> detail page
            var nameCell = e.target.closest('.facility-name-cell');
            if (nameCell) {
                var row = nameCell.closest('.facility-row');
                if (row) {
                    var id = row.getAttribute('data-id');
                    window.location.href = 'facility-detail.php?id=' + id;
                }
                return;
            }

            // Click on other cells in facility row -> detail page (fallback)
            var row = e.target.closest('.facility-row');
            if (row) {
                var id = row.getAttribute('data-id');
                window.location.href = 'facility-detail.php?id=' + id;
            }
        });
    }

    // ── Utilities ──
    function escHtml(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    function formatDate(dateStr) {
        if (!dateStr) return '';
        var d = new Date(dateStr);
        if (isNaN(d.getTime())) return dateStr;
        var month = d.getMonth() + 1;
        var day = d.getDate();
        var hours = d.getHours();
        var mins = d.getMinutes();
        return month + '/' + day + ' ' +
               (hours < 10 ? '0' : '') + hours + ':' +
               (mins < 10 ? '0' : '') + mins;
    }

    function showAlert(message, type) {
        var area = document.getElementById('alertArea');
        if (!area) return;
        area.innerHTML = '<div class="alert alert-' + type + ' alert-dismissible fade show small py-2" role="alert">' +
            message +
            '<button type="button" class="btn-close btn-close-sm" data-bs-dismiss="alert"></button>' +
            '</div>';
    }

})();
