/**
 * Unit Tracking — Real-Time Map Overlay
 *
 * Displays live unit positions on a Leaflet map with 10-second auto-refresh.
 * Each unit marker shows provider icon, freshness indicator, speed/heading,
 * and popup with assigned personnel.
 *
 * Usage:
 *   var tracker = UnitTracking.init(map, {
 *       refreshInterval: 10000,   // 10 seconds
 *       showLabels: true,         // show unit name labels
 *       showStale: true,          // show stale markers (dimmed)
 *       onClick: function(unit) { ... }
 *   });
 *   tracker.start();       // begin polling
 *   tracker.stop();        // stop polling
 *   tracker.refresh();     // force immediate refresh
 *   tracker.destroy();     // cleanup
 */

var UnitTracking = (function () {
    'use strict';

    var _map = null;
    var _markers = {};       // keyed by responder_id
    var _options = {};
    var _timer = null;
    var _layerGroup = null;
    var _running = false;

    var DEFAULTS = {
        refreshInterval: 10000,
        showLabels: true,
        showStale: true,
        showTrails: false,
        trailLength: 5,
        onClick: null,
        onUpdate: null
    };

    function init(map, opts) {
        _map = map;
        _options = {};
        var key;
        for (key in DEFAULTS) {
            _options[key] = DEFAULTS[key];
        }
        if (opts) {
            for (key in opts) {
                _options[key] = opts[key];
            }
        }
        _layerGroup = L.layerGroup().addTo(_map);
        return {
            start: start,
            stop: stop,
            refresh: refresh,
            destroy: destroy,
            getMarkers: function () { return _markers; },
            getLayerGroup: function () { return _layerGroup; }
        };
    }

    function start() {
        if (_running) return;
        _running = true;
        refresh();
        _timer = setInterval(refresh, _options.refreshInterval);
    }

    function stop() {
        _running = false;
        if (_timer) {
            clearInterval(_timer);
            _timer = null;
        }
    }

    function destroy() {
        stop();
        if (_layerGroup) {
            _layerGroup.clearLayers();
            _map.removeLayer(_layerGroup);
        }
        _markers = {};
        _layerGroup = null;
        _map = null;
    }

    function refresh() {
        fetch('api/location.php?all_units=1')
            .then(function (r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.json();
            })
            .then(function (data) {
                var units = data.units || [];
                updateMarkers(units);
                if (_options.onUpdate) {
                    _options.onUpdate(units);
                }
            })
            .catch(function (err) {
                // Silently handle errors — don't spam alerts on a 10s loop
                if (window.console && console.warn) {
                    console.warn('UnitTracking refresh failed:', err.message);
                }
            });
    }

    function updateMarkers(units) {
        var seenIds = {};

        for (var i = 0; i < units.length; i++) {
            var u = units[i];
            var rid = parseInt(u.responder_id, 10);
            if (!rid) continue;
            seenIds[rid] = true;

            var lat = parseFloat(u.lat);
            var lng = parseFloat(u.lng);
            if (isNaN(lat) || isNaN(lng)) continue;

            var isFresh = parseInt(u.is_fresh, 10) === 1;

            // Skip stale if configured
            if (!isFresh && !_options.showStale) continue;

            var existing = _markers[rid];
            if (existing) {
                // Update position
                existing.marker.setLatLng([lat, lng]);
                existing.marker.setPopupContent(buildPopup(u));
                existing.marker.setIcon(buildIcon(u, isFresh));
                // Phase 26B — refresh hover tooltip age label
                if (existing.marker.getTooltip()) {
                    existing.marker.setTooltipContent(buildAgeTooltip(u));
                } else {
                    existing.marker.bindTooltip(buildAgeTooltip(u), { sticky: true });
                }

                // Update label if exists
                if (existing.label) {
                    existing.label.setLatLng([lat, lng]);
                }

                // Trail
                if (_options.showTrails && existing.trail) {
                    existing.trail.push([lat, lng]);
                    if (existing.trail.length > _options.trailLength) {
                        existing.trail.shift();
                    }
                    if (existing.trailLine) {
                        existing.trailLine.setLatLngs(existing.trail);
                    }
                }
            } else {
                // Create new marker
                var icon = buildIcon(u, isFresh);
                var marker = L.marker([lat, lng], { icon: icon })
                    .bindPopup(buildPopup(u));
                // Phase 26B (2026-06-11) — sticky tooltip with last-fix age
                marker.bindTooltip(buildAgeTooltip(u), { sticky: true });

                if (_options.onClick) {
                    (function (unit) {
                        marker.on('click', function () {
                            _options.onClick(unit);
                        });
                    })(u);
                }

                _layerGroup.addLayer(marker);

                var entry = { marker: marker, data: u, label: null, trail: null, trailLine: null };

                // Label
                if (_options.showLabels) {
                    var labelIcon = L.divIcon({
                        className: 'unit-label',
                        html: '<span class="unit-label-text' + (isFresh ? '' : ' unit-label-stale') + '">' + esc(u.unit_name || u.unit_handle || u.unit_callsign || '') + '</span>',
                        iconSize: [100, 16],
                        iconAnchor: [50, -12]
                    });
                    entry.label = L.marker([lat, lng], { icon: labelIcon, interactive: false });
                    _layerGroup.addLayer(entry.label);
                }

                // Trail
                if (_options.showTrails) {
                    entry.trail = [[lat, lng]];
                    entry.trailLine = L.polyline(entry.trail, {
                        color: u.color || '#3366FF',
                        weight: 2,
                        opacity: 0.5,
                        dashArray: '4 6'
                    });
                    _layerGroup.addLayer(entry.trailLine);
                }

                _markers[rid] = entry;
            }

            // Update stored data
            _markers[rid].data = u;
        }

        // Remove markers for units no longer reporting
        for (var id in _markers) {
            if (!seenIds[id]) {
                var m = _markers[id];
                _layerGroup.removeLayer(m.marker);
                if (m.label) _layerGroup.removeLayer(m.label);
                if (m.trailLine) _layerGroup.removeLayer(m.trailLine);
                delete _markers[id];
            }
        }
    }

    function buildIcon(unit, isFresh) {
        var color = unit.color || '#3366FF';
        // Phase 26B (2026-06-11) — graded opacity instead of binary 0.4/1.0.
        // Reads age_seconds (set by api/unit-tracking.php) and fades over
        // 60 minutes so a 10-minute-stale unit is still clearly visible.
        var ageMin = (parseInt(unit.age_seconds, 10) || 0) / 60;
        var opacity;
        if (ageMin < 5)       opacity = 1.0;
        else if (ageMin < 15) opacity = 0.85;
        else if (ageMin < 30) opacity = 0.65;
        else if (ageMin < 60) opacity = 0.45;
        else                  opacity = 0.3;
        if (isFresh && opacity < 0.85) opacity = 0.85;   // server says fresh? trust it.
        var speed = parseFloat(unit.speed) || 0;
        var heading = parseFloat(unit.heading) || 0;

        // Use a CSS-styled div icon with provider color
        var iconClass = unit.icon || 'bi-geo-alt-fill';
        var html = '<div class="unit-map-marker' + (isFresh ? '' : ' unit-marker-stale') + '" style="color:' + esc(color) + ';opacity:' + opacity + '">';
        html += '<i class="bi ' + esc(iconClass) + '" style="font-size:20px"></i>';

        // Speed indicator (small arrow showing direction if moving)
        if (speed > 2 && heading >= 0) {
            html += '<div class="unit-heading-arrow" style="transform:rotate(' + heading + 'deg)">&#x25B2;</div>';
        }

        html += '</div>';

        return L.divIcon({
            className: 'unit-icon-wrapper',
            html: html,
            iconSize: [28, 28],
            iconAnchor: [14, 14],
            popupAnchor: [0, -16]
        });
    }

    function buildPopup(unit) {
        var html = '<div class="unit-popup" style="min-width:180px;font-size:0.85rem">';

        // Unit name
        html += '<div class="fw-bold mb-1">' + esc(unit.unit_name || 'Unit') + '</div>';

        // Handle/callsign
        if (unit.unit_handle || unit.unit_callsign) {
            html += '<div class="text-body-secondary small">';
            if (unit.unit_handle) html += esc(unit.unit_handle);
            if (unit.unit_callsign) html += ' (' + esc(unit.unit_callsign) + ')';
            html += '</div>';
        }

        // Provider info
        html += '<div class="small mt-1">';
        html += '<i class="bi ' + esc(unit.icon || 'bi-geo-alt') + ' me-1" style="color:' + esc(unit.color || '') + '"></i>';
        html += esc(unit.provider_name || 'Unknown');

        var isFresh = parseInt(unit.is_fresh, 10) === 1;
        html += ' <span class="badge ' + (isFresh ? 'bg-success' : 'bg-warning text-dark') + '" style="font-size:0.65rem">';
        html += isFresh ? 'Fresh' : 'Stale';
        html += '</span>';
        html += '</div>';

        // Age
        if (unit.age_seconds !== undefined && unit.age_seconds !== null) {
            html += '<div class="small text-body-secondary">';
            html += 'Updated ' + formatAge(parseInt(unit.age_seconds, 10)) + ' ago';
            html += '</div>';
        }

        // Speed/heading
        var speed = parseFloat(unit.speed) || 0;
        if (speed > 0) {
            html += '<div class="small">';
            html += '<i class="bi bi-speedometer2 me-1"></i>' + speed.toFixed(1) + ' km/h';
            if (unit.heading) html += ' @ ' + parseFloat(unit.heading).toFixed(0) + '&deg;';
            html += '</div>';
        }

        // Altitude
        if (unit.altitude) {
            html += '<div class="small">';
            html += '<i class="bi bi-arrow-up me-1"></i>' + parseFloat(unit.altitude).toFixed(0) + 'm alt';
            html += '</div>';
        }

        // Battery
        if (unit.battery !== null && unit.battery !== undefined) {
            var batt = parseInt(unit.battery, 10);
            var battClass = batt > 50 ? 'text-success' : (batt > 20 ? 'text-warning' : 'text-danger');
            html += '<div class="small ' + battClass + '">';
            html += '<i class="bi bi-battery-half me-1"></i>' + batt + '%';
            html += '</div>';
        }

        // Personnel
        if (unit.personnel && unit.personnel.length) {
            html += '<hr class="my-1">';
            html += '<div class="small fw-semibold mb-1">Crew (' + unit.personnel.length + ')</div>';
            for (var i = 0; i < unit.personnel.length; i++) {
                var p = unit.personnel[i];
                html += '<div class="small">';
                html += '<i class="bi bi-person me-1"></i>' + esc(p.name || '');
                html += ' <span class="badge bg-secondary" style="font-size:0.6rem">' + esc(p.role) + '</span>';
                html += '</div>';
            }
        }

        // Coordinates
        html += '<div class="small text-body-secondary mt-1">';
        html += parseFloat(unit.lat).toFixed(5) + ', ' + parseFloat(unit.lng).toFixed(5);
        html += '</div>';

        html += '</div>';
        return html;
    }

    // Phase 26B (2026-06-11) — sticky-tooltip text for hover-over.
    // Compact "Unit Name — Last fix: 12m ago" line so dispatchers can
    // scan freshness across many markers without clicking.
    function buildAgeTooltip(u) {
        var label = u.unit_name || u.unit_handle || u.unit_callsign || 'Unit';
        var age   = (u.age_seconds !== undefined && u.age_seconds !== null)
            ? formatAge(parseInt(u.age_seconds, 10)) + ' ago'
            : 'unknown';
        return esc(label) + ' — Last fix: ' + age;
    }

    function formatAge(seconds) {
        if (isNaN(seconds) || seconds < 0) return '--';
        if (seconds < 60) return seconds + 's';
        if (seconds < 3600) return Math.floor(seconds / 60) + 'm ' + (seconds % 60) + 's';
        return Math.floor(seconds / 3600) + 'h ' + Math.floor((seconds % 3600) / 60) + 'm';
    }

    function esc(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.textContent = String(str);
        return div.innerHTML;
    }

    return {
        init: init
    };
})();
