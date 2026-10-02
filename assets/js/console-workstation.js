/**
 * NewUI v4.0 — Console rebuild: workstation identity (Phase 152, spec.md
 * user story #12 / plan.md section 3.5).
 *
 * A persistent identity for a PHYSICAL desk, independent of who is logged
 * in or which login session is active -- the adjacent-transmit-mute
 * feature's own hard requirement (the acoustic-echo problem doesn't
 * change when a different person sits down at the same desk tomorrow).
 *
 * Generated once on first console load, stored in localStorage under
 * STORAGE_KEY. Survives logout and a different user logging in on the
 * same browser/machine. Does NOT survive a browser data wipe or a
 * different browser/device -- the honest, documented limit of this
 * approach (localStorage is per-origin, per-browser-profile storage;
 * there is no cross-device identity here, and none is claimed).
 *
 * ES5 IIFE — no arrow functions, no let/const, no template literals.
 */
(function () {
    'use strict';

    var STORAGE_KEY = 'ticketscad_console_workstation_token';

    function generateUuid() {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return window.crypto.randomUUID();
        }
        // Fallback for older browsers without crypto.randomUUID() -- RFC
        // 4122-shaped (not cryptographically strong, but this identity's
        // only job is "distinct enough to tell desks apart," never a
        // security token).
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
            var r = (Math.random() * 16) | 0;
            var v = c === 'x' ? r : (r & 0x3) | 0x8;
            return v.toString(16);
        });
    }

    function getToken() {
        var token = null;
        try {
            token = window.localStorage.getItem(STORAGE_KEY);
        } catch (e) {
            // localStorage can throw in a private window or when site data
            // is blocked -- degrade to "no persistent identity this visit"
            // rather than breaking the console.
        }
        if (!token) {
            token = generateUuid();
            try {
                window.localStorage.setItem(STORAGE_KEY, token);
            } catch (e) {
                // Same fallback as above -- the generated token still works
                // for THIS page load, it just won't persist to the next one.
            }
        }
        return token;
    }

    window.ConsoleWorkstation = {
        getToken: getToken,
    };
})();
