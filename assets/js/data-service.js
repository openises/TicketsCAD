/**
 * NewUI v4.0 - Data Service
 *
 * Handles all API communication. Fetches widget data in parallel
 * using Promise.all() for maximum performance.
 */
var DataService = (function () {
    var POLL_INTERVAL = 15000; // 15 seconds
    var pollTimer = null;
    var cache = {};

    function fetchJSON(url) {
        return fetch(url, { credentials: 'same-origin' })
            .then(function (res) {
                if (res.status === 401) {
                    window.location.href = 'login.php';
                    throw new Error('Not authenticated');
                }
                if (!res.ok) throw new Error('HTTP ' + res.status);
                return res.json();
            });
    }

    function fetchAll(widgets) {
        // 2026-06-11 — Honor the per-user "Keep closed N min" pref on
        // the dashboard Incidents widget. window.DashboardPrefs is
        // set by app.js after a one-time screen-prefs load.
        var recentMins = (window.DashboardPrefs && window.DashboardPrefs.recent_close_mins) || 30;
        var endpoints = {
            incidents:  'api/incidents.php?func=0&recent_close_mins=' + encodeURIComponent(recentMins),
            responders: 'api/responders.php',
            facilities: 'api/facilities.php',
            log:        'api/log.php?days=7',
            statistics: 'api/statistics.php',
            scheduled:  'api/scheduled.php'
        };

        var fetches = [];
        var keys = [];

        widgets.forEach(function (w) {
            if (endpoints[w]) {
                keys.push(w);
                fetches.push(fetchJSON(endpoints[w]));
            }
        });

        // Use allSettled so one failing endpoint doesn't kill the rest
        return Promise.allSettled(fetches).then(function (results) {
            var data = {};
            keys.forEach(function (key, i) {
                if (results[i].status === 'fulfilled') {
                    data[key] = results[i].value;
                    cache[key] = results[i].value;
                } else {
                    console.warn('Failed to fetch ' + key + ':', results[i].reason);
                    // Use cached data if available
                    if (cache[key]) {
                        data[key] = cache[key];
                    }
                }
            });
            return data;
        });
    }

    function startPolling(widgets, callback) {
        stopPolling();

        function poll() {
            fetchAll(widgets)
                .then(function (data) {
                    callback(data);
                    EventBus.emit('data:updated', data);
                })
                .catch(function (err) {
                    console.error('Poll error:', err);
                });
        }

        // Initial fetch
        poll();

        // Repeated polling
        pollTimer = setInterval(poll, POLL_INTERVAL);
    }

    function stopPolling() {
        if (pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
    }

    function getCache(key) {
        return cache[key] || null;
    }

    function setPollInterval(ms) {
        POLL_INTERVAL = Math.max(5000, ms);
    }

    return {
        fetchJSON: fetchJSON,
        fetchAll: fetchAll,
        startPolling: startPolling,
        stopPolling: stopPolling,
        getCache: getCache,
        setPollInterval: setPollInterval
    };
})();
