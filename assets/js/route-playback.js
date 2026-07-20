/**
 * Route Playback — Historical Location Slider
 *
 * Displays a unit's historical route on a Leaflet map with a time slider
 * for playback. Shows speed, heading, battery, and provider at each point.
 *
 * Usage:
 *   var player = RoutePlayback.init(map, {
 *       responderId: 123,
 *       hours: 24,        // or start/end dates
 *       onPointChange: function(point, index) { ... }
 *   });
 *   player.load();        // fetch data and render
 *   player.play();        // animate playback
 *   player.pause();
 *   player.seekTo(index);
 *   player.setSpeed(2);   // 2x playback speed
 *   player.destroy();
 */

var RoutePlayback = (function () {
    'use strict';

    function init(map, opts) {
        var state = {
            map: map,
            opts: opts || {},
            points: [],
            routeLine: null,
            marker: null,
            trailLine: null,
            playing: false,
            currentIndex: 0,
            speed: 1,
            timer: null,
            controlEl: null
        };

        return {
            load: function () { return load(state); },
            play: function () { play(state); },
            pause: function () { pause(state); },
            seekTo: function (idx) { seekTo(state, idx); },
            setSpeed: function (s) { state.speed = s; },
            destroy: function () { destroy(state); },
            getPoints: function () { return state.points; },
            isPlaying: function () { return state.playing; }
        };
    }

    function load(state) {
        var opts = state.opts;
        var url = 'api/location-history.php?responder_id=' + opts.responderId;

        if (opts.hours) {
            url += '&hours=' + opts.hours;
        } else if (opts.start && opts.end) {
            url += '&start=' + encodeURIComponent(opts.start);
            url += '&end=' + encodeURIComponent(opts.end);
        }

        return fetch(url)
            .then(function (r) { return r.json(); })
            .then(function (data) {
                state.points = data.points || [];
                if (state.points.length === 0) {
                    if (opts.onEmpty) opts.onEmpty();
                    return;
                }
                renderRoute(state);
                createControls(state);
                seekTo(state, 0);
                if (opts.onLoad) opts.onLoad(state.points);
            });
    }

    function renderRoute(state) {
        var coords = [];
        for (var i = 0; i < state.points.length; i++) {
            coords.push([parseFloat(state.points[i].lat), parseFloat(state.points[i].lng)]);
        }

        // Full route line (semi-transparent)
        state.routeLine = L.polyline(coords, {
            color: '#3366FF',
            weight: 3,
            opacity: 0.3,
            dashArray: '6 4'
        }).addTo(state.map);

        // Trail line (solid, shows traversed portion)
        state.trailLine = L.polyline([], {
            color: '#FF6600',
            weight: 4,
            opacity: 0.8
        }).addTo(state.map);

        // Fit map to route
        if (coords.length > 1) {
            state.map.fitBounds(L.latLngBounds(coords), { padding: [30, 30] });
        }
    }

    function createControls(state) {
        // Create a control panel overlay on the map
        var container = document.createElement('div');
        container.className = 'route-playback-controls';
        container.innerHTML = '' +
            '<div class="d-flex align-items-center gap-2 mb-1">' +
                '<button class="btn btn-sm btn-primary rp-play-btn" title="Play/Pause">' +
                    '<i class="bi bi-play-fill"></i>' +
                '</button>' +
                '<input type="range" class="form-range rp-slider" min="0" max="' + (state.points.length - 1) + '" value="0" style="flex:1">' +
                '<select class="form-select form-select-sm rp-speed" style="width:70px">' +
                    '<option value="0.5">0.5x</option>' +
                    '<option value="1" selected>1x</option>' +
                    '<option value="2">2x</option>' +
                    '<option value="5">5x</option>' +
                    '<option value="10">10x</option>' +
                '</select>' +
            '</div>' +
            '<div class="d-flex justify-content-between small text-body-secondary">' +
                '<span class="rp-time">--</span>' +
                '<span class="rp-info">--</span>' +
                '<span class="rp-count">' + state.points.length + ' points</span>' +
            '</div>';

        state.controlEl = container;

        // Position below the map (or in a custom container if provided)
        var target = state.opts.controlContainer || state.map.getContainer().parentNode;
        target.appendChild(container);

        // Bind events
        var playBtn = container.querySelector('.rp-play-btn');
        var slider = container.querySelector('.rp-slider');
        var speedSel = container.querySelector('.rp-speed');

        playBtn.addEventListener('click', function () {
            if (state.playing) {
                pause(state);
                playBtn.innerHTML = '<i class="bi bi-play-fill"></i>';
            } else {
                play(state);
                playBtn.innerHTML = '<i class="bi bi-pause-fill"></i>';
            }
        });

        slider.addEventListener('input', function () {
            seekTo(state, parseInt(this.value, 10));
        });

        speedSel.addEventListener('change', function () {
            state.speed = parseFloat(this.value);
        });
    }

    function seekTo(state, index) {
        if (index < 0 || index >= state.points.length) return;
        state.currentIndex = index;

        var point = state.points[index];
        var lat = parseFloat(point.lat);
        var lng = parseFloat(point.lng);

        // Update marker
        if (!state.marker) {
            state.marker = L.circleMarker([lat, lng], {
                radius: 8,
                fillColor: '#FF6600',
                color: '#fff',
                weight: 2,
                fillOpacity: 1
            }).addTo(state.map);

            state.marker.bindPopup('');
        }

        state.marker.setLatLng([lat, lng]);
        state.marker.setPopupContent(buildPointPopup(point));

        // Update trail line (traversed portion)
        var trailCoords = [];
        for (var i = 0; i <= index; i++) {
            trailCoords.push([parseFloat(state.points[i].lat), parseFloat(state.points[i].lng)]);
        }
        if (state.trailLine) {
            state.trailLine.setLatLngs(trailCoords);
        }

        // Update slider
        if (state.controlEl) {
            var slider = state.controlEl.querySelector('.rp-slider');
            if (slider) slider.value = index;

            var timeEl = state.controlEl.querySelector('.rp-time');
            if (timeEl) timeEl.textContent = point.reported_at || '--';

            var infoEl = state.controlEl.querySelector('.rp-info');
            if (infoEl) {
                var info = '';
                var speed = parseFloat(point.speed) || 0;
                if (speed > 0) info += speed.toFixed(1) + ' km/h';
                if (point.provider_name) info += (info ? ' | ' : '') + point.provider_name;
                infoEl.textContent = info || '--';
            }
        }

        // Pan map to keep marker visible
        if (!state.map.getBounds().contains([lat, lng])) {
            state.map.panTo([lat, lng]);
        }

        if (state.opts.onPointChange) {
            state.opts.onPointChange(point, index);
        }
    }

    function play(state) {
        if (state.playing) return;
        state.playing = true;

        // If at the end, restart from beginning
        if (state.currentIndex >= state.points.length - 1) {
            state.currentIndex = 0;
        }

        state.timer = setInterval(function () {
            if (state.currentIndex >= state.points.length - 1) {
                pause(state);
                if (state.controlEl) {
                    var btn = state.controlEl.querySelector('.rp-play-btn');
                    if (btn) btn.innerHTML = '<i class="bi bi-play-fill"></i>';
                }
                return;
            }
            seekTo(state, state.currentIndex + 1);
        }, 500 / state.speed);
    }

    function pause(state) {
        state.playing = false;
        if (state.timer) {
            clearInterval(state.timer);
            state.timer = null;
        }
    }

    function destroy(state) {
        pause(state);
        if (state.routeLine) state.map.removeLayer(state.routeLine);
        if (state.trailLine) state.map.removeLayer(state.trailLine);
        if (state.marker) state.map.removeLayer(state.marker);
        if (state.controlEl && state.controlEl.parentNode) {
            state.controlEl.parentNode.removeChild(state.controlEl);
        }
        state.points = [];
    }

    function buildPointPopup(point) {
        var html = '<div style="min-width:150px;font-size:0.85rem">';
        html += '<div class="fw-bold mb-1">' + (point.reported_at || '--') + '</div>';

        if (point.provider_name) {
            html += '<div class="small">';
            if (point.icon) html += '<i class="bi ' + esc(point.icon) + ' me-1" style="color:' + esc(point.color || '') + '"></i>';
            html += esc(point.provider_name);
            html += '</div>';
        }

        var speed = parseFloat(point.speed) || 0;
        if (speed > 0) {
            html += '<div class="small"><i class="bi bi-speedometer2 me-1"></i>' + speed.toFixed(1) + ' km/h';
            if (point.heading) html += ' @ ' + parseFloat(point.heading).toFixed(0) + '&deg;';
            html += '</div>';
        }

        if (point.altitude) {
            html += '<div class="small"><i class="bi bi-arrow-up me-1"></i>' + parseFloat(point.altitude).toFixed(0) + 'm</div>';
        }

        if (point.battery !== null && point.battery !== undefined) {
            var batt = parseInt(point.battery, 10);
            html += '<div class="small"><i class="bi bi-battery-half me-1"></i>' + batt + '%</div>';
        }

        html += '<div class="small text-body-secondary mt-1">';
        html += parseFloat(point.lat).toFixed(5) + ', ' + parseFloat(point.lng).toFixed(5);
        html += '</div>';

        html += '</div>';
        return html;
    }

    function esc(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.textContent = String(str);
        return div.innerHTML;
    }

    return { init: init };
})();
