/**
 * Navigate-to-Scene launcher — Phase 71 (2026-06-14)
 *
 * Bottom-sheet chooser for opening turn-by-turn navigation to an
 * incident or destination. Offers platform-appropriate options:
 *   iOS:     Apple Maps, Google Maps (app deep link), Waze, browser
 *   Android: Google Maps (geo: intent — picks user's default), Waze, browser
 *   Desktop: Google Maps (browser)
 *
 * Remembers the user's last choice in localStorage so the second tap
 * launches directly into their preferred app. Long-press / shift-click
 * forces the chooser back open so the responder can switch apps if
 * they're in a different vehicle today.
 *
 * Usage:
 *   NavigateLauncher.attach(button, { lat: 44.97, lng: -93.26, address: '123 Main' });
 *   NavigateLauncher.launch({ lat: 44.97, lng: -93.26 }); // imperative
 */
var NavigateLauncher = (function () {
    'use strict';

    var PREF_KEY = 'navAppChoice';
    var INSTALLED_OVERLAY = false;

    function isIOS() {
        return /iPhone|iPad|iPod/i.test(navigator.userAgent || '');
    }
    function isAndroid() {
        return /Android/i.test(navigator.userAgent || '');
    }

    // Build the list of apps to offer for this device. Each entry has
    // an id (for preference storage), label, icon, and a function that
    // turns coords into the deep-link / URL to navigate to.
    function appsForPlatform() {
        var ios = isIOS();
        var android = isAndroid();
        var apps = [];

        if (ios) {
            apps.push({
                id: 'apple',
                label: 'Apple Maps',
                icon: 'bi-map-fill',
                build: function (lat, lng) {
                    return 'maps://?daddr=' + lat + ',' + lng;
                }
            });
            apps.push({
                id: 'gmaps-app',
                label: 'Google Maps',
                icon: 'bi-geo-alt-fill',
                build: function (lat, lng) {
                    return 'comgooglemaps://?daddr=' + lat + ',' + lng + '&directionsmode=driving';
                }
            });
            apps.push({
                id: 'waze',
                label: 'Waze',
                icon: 'bi-cone-striped',
                build: function (lat, lng) {
                    return 'waze://?ll=' + lat + ',' + lng + '&navigate=yes';
                }
            });
        } else if (android) {
            // geo: lets Android offer the user's default navigation app
            // (Maps, Waze, Magic Earth, etc.). Includes the lat/lng both
            // as the centre and as a query so the destination pin lands
            // correctly even on apps that ignore one or the other.
            apps.push({
                id: 'android-default',
                label: 'Default Nav App',
                icon: 'bi-geo-alt-fill',
                build: function (lat, lng, label) {
                    var q = label ? '(' + encodeURIComponent(label) + ')' : '';
                    return 'geo:' + lat + ',' + lng + '?q=' + lat + ',' + lng + q;
                }
            });
            apps.push({
                id: 'gmaps-app',
                label: 'Google Maps',
                icon: 'bi-map-fill',
                build: function (lat, lng) {
                    return 'google.navigation:q=' + lat + ',' + lng + '&mode=d';
                }
            });
            apps.push({
                id: 'waze',
                label: 'Waze',
                icon: 'bi-cone-striped',
                build: function (lat, lng) {
                    return 'waze://?ll=' + lat + ',' + lng + '&navigate=yes';
                }
            });
        }

        // Browser fallback — always available. Last in the list.
        apps.push({
            id: 'web',
            label: 'Open in Browser',
            icon: 'bi-globe',
            build: function (lat, lng) {
                return 'https://www.google.com/maps/dir/?api=1&destination=' + lat + ',' + lng;
            }
        });

        return apps;
    }

    function getStoredChoice() {
        try { return localStorage.getItem(PREF_KEY); } catch (e) { return null; }
    }
    function setStoredChoice(id) {
        try { localStorage.setItem(PREF_KEY, id); } catch (e) {}
    }
    function clearStoredChoice() {
        try { localStorage.removeItem(PREF_KEY); } catch (e) {}
    }

    function buildOverlay() {
        if (INSTALLED_OVERLAY) return;
        INSTALLED_OVERLAY = true;

        var css = ''
            + '.nav-launcher-backdrop {'
            + '  position: fixed; inset: 0; z-index: 2000;'
            + '  background: rgba(0,0,0,0.5);'
            + '  display: flex; align-items: flex-end; justify-content: center;'
            + '  opacity: 0; transition: opacity 0.15s ease-out;'
            + '}'
            + '.nav-launcher-backdrop.show { opacity: 1; }'
            + '.nav-launcher-sheet {'
            + '  width: 100%; max-width: 480px;'
            + '  background: var(--bs-body-bg, #fff);'
            + '  color: var(--bs-body-color, #212529);'
            + '  border-radius: 16px 16px 0 0;'
            + '  padding: 16px;'
            + '  box-shadow: 0 -4px 20px rgba(0,0,0,0.3);'
            + '  transform: translateY(100%); transition: transform 0.18s ease-out;'
            + '}'
            + '.nav-launcher-backdrop.show .nav-launcher-sheet { transform: translateY(0); }'
            + '.nav-launcher-title { font-weight: 600; margin-bottom: 4px; }'
            + '.nav-launcher-dest { font-size: 0.85rem; color: var(--bs-secondary-color, #6c757d); margin-bottom: 14px; word-break: break-word; }'
            + '.nav-launcher-btn {'
            + '  display: flex; align-items: center; gap: 12px;'
            + '  width: 100%; padding: 14px;'
            + '  background: var(--bs-secondary-bg, #f8f9fa);'
            + '  color: inherit; border: 1px solid var(--bs-border-color, #dee2e6);'
            + '  border-radius: 10px; margin-bottom: 8px;'
            + '  font-size: 1rem; font-weight: 500;'
            + '  text-decoration: none; cursor: pointer;'
            + '}'
            + '.nav-launcher-btn:hover, .nav-launcher-btn:active { background: var(--bs-tertiary-bg, #e9ecef); }'
            + '.nav-launcher-btn i { font-size: 1.4rem; flex-shrink: 0; width: 1.5em; text-align: center; }'
            + '.nav-launcher-cancel {'
            + '  display: block; width: 100%; padding: 12px;'
            + '  background: transparent; border: 0; margin-top: 6px;'
            + '  color: var(--bs-secondary-color, #6c757d); font-weight: 500;'
            + '  text-align: center; cursor: pointer;'
            + '}';
        var style = document.createElement('style');
        style.setAttribute('data-nav-launcher', '1');
        style.textContent = css;
        document.head.appendChild(style);
    }

    function openChooser(coords) {
        buildOverlay();
        var apps = appsForPlatform();

        var backdrop = document.createElement('div');
        backdrop.className = 'nav-launcher-backdrop';

        var sheet = document.createElement('div');
        sheet.className = 'nav-launcher-sheet';

        var dest = coords.address || (coords.lat.toFixed(5) + ', ' + coords.lng.toFixed(5));
        sheet.innerHTML = '<div class="nav-launcher-title">Navigate to scene</div>'
            + '<div class="nav-launcher-dest">' + escapeHtml(dest) + '</div>';

        apps.forEach(function (app) {
            var btn = document.createElement('a');
            btn.className = 'nav-launcher-btn';
            btn.href = app.build(coords.lat, coords.lng, coords.address);
            btn.target = '_blank';
            btn.rel = 'noopener';
            btn.innerHTML = '<i class="bi ' + app.icon + '"></i><span>' + escapeHtml(app.label) + '</span>';
            btn.addEventListener('click', function () {
                setStoredChoice(app.id);
                // Let the link navigate naturally; close the sheet after
                // a tick so the launch doesn't get cancelled.
                setTimeout(closeChooser, 150);
            });
            sheet.appendChild(btn);
        });

        var cancel = document.createElement('button');
        cancel.type = 'button';
        cancel.className = 'nav-launcher-cancel';
        cancel.textContent = 'Cancel';
        cancel.addEventListener('click', closeChooser);
        sheet.appendChild(cancel);

        backdrop.appendChild(sheet);
        backdrop.addEventListener('click', function (e) {
            if (e.target === backdrop) closeChooser();
        });

        document.body.appendChild(backdrop);
        // Animate in
        requestAnimationFrame(function () { backdrop.classList.add('show'); });

        function closeChooser() {
            backdrop.classList.remove('show');
            setTimeout(function () {
                if (backdrop.parentNode) backdrop.parentNode.removeChild(backdrop);
            }, 200);
        }
    }

    // Launch directly without chooser — used when the user has already
    // picked a preferred app this device.
    function launchDirect(coords, appId) {
        var apps = appsForPlatform();
        var app = null;
        for (var i = 0; i < apps.length; i++) {
            if (apps[i].id === appId) { app = apps[i]; break; }
        }
        if (!app) return false;
        var url = app.build(coords.lat, coords.lng, coords.address);
        // Anchor-click for both web URLs and custom-scheme URLs so iOS
        // honours the deep link (window.location.href can be blocked
        // by Safari for unknown schemes).
        var a = document.createElement('a');
        a.href = url;
        a.target = '_blank';
        a.rel = 'noopener';
        a.style.display = 'none';
        document.body.appendChild(a);
        a.click();
        setTimeout(function () {
            if (a.parentNode) a.parentNode.removeChild(a);
        }, 1000);
        return true;
    }

    // Public: launch navigation. If the user has a stored preference,
    // launch directly. Otherwise, show the chooser.
    function launch(coords, opts) {
        if (!coords || typeof coords.lat !== 'number' || typeof coords.lng !== 'number') {
            return;
        }
        opts = opts || {};
        var forceChooser = !!opts.forceChooser;
        var stored = getStoredChoice();
        if (!forceChooser && stored) {
            if (launchDirect(coords, stored)) return;
        }
        openChooser(coords);
    }

    // Public: attach launch behavior to a button. Tap launches; long-
    // press (or shift+click on desktop) re-opens the chooser to let
    // the user switch apps.
    function attach(el, coords) {
        if (!el) return;
        var pressTimer = null;
        var longPressed = false;

        el.addEventListener('click', function (e) {
            e.preventDefault();
            if (longPressed) { longPressed = false; return; }
            launch(coords, { forceChooser: e.shiftKey });
        });
        el.addEventListener('touchstart', function () {
            longPressed = false;
            clearTimeout(pressTimer);
            pressTimer = setTimeout(function () {
                longPressed = true;
                launch(coords, { forceChooser: true });
            }, 600);
        }, { passive: true });
        el.addEventListener('touchend', function () {
            clearTimeout(pressTimer);
        });
        el.addEventListener('touchmove', function () {
            clearTimeout(pressTimer);
        });
    }

    function escapeHtml(s) {
        if (s == null) return '';
        var d = document.createElement('div');
        d.appendChild(document.createTextNode(String(s)));
        return d.innerHTML;
    }

    return {
        launch: launch,
        attach: attach,
        clearPreference: clearStoredChoice,
        appsForPlatform: appsForPlatform
    };
})();
