/*
 * NewUI v4.0 — Language Switcher (Phase 8 i18n)
 *
 * Renders the language picker in the navbar dropdown menu and POSTs
 * the chosen lang to /api/set-language.php on selection.
 *
 * Bootstrap data (set by inc/navbar.php just before this script):
 *   window.AVAILABLE_LANGS  array of ISO codes (['en','de'])
 *   window.CURRENT_LANG     current session lang (e.g. 'en')
 *   window.CSRF_TOKEN       session CSRF token (already set by other pages,
 *                           but defended against missing for safety)
 *
 * The switcher container (#languageSwitcher) is only emitted by navbar.php
 * when AVAILABLE_LANGS has >= 2 entries — so this script is a no-op for
 * single-language installs.
 *
 * Conventions: ES5 IIFE, no jQuery, no template literals. Matches
 * project-wide JS style documented in CLAUDE.md.
 */
(function () {
    'use strict';

    // Display-name lookup for common ISO 639-1 codes. Unknown codes fall
    // back to the uppercase code itself (e.g. 'sv' → 'SV'). Extend as
    // additional translation languages come online.
    var LANG_NAMES = {
        en: 'English',
        de: 'Deutsch',
        fr: 'Français',
        es: 'Español',
        it: 'Italiano',
        pt: 'Português',
        nl: 'Nederlands',
        sv: 'Svenska',
        no: 'Norsk',
        da: 'Dansk',
        fi: 'Suomi',
        pl: 'Polski',
        cs: 'Čeština',
        ja: '日本語',
        ko: '한국어',
        zh: '中文',
        ar: 'العربية',
        he: 'עברית',
        ru: 'Русский',
        uk: 'Українська'
    };

    function displayName(code) {
        if (!code) return '';
        // Phase 8b: prefer admin-customized registry entry over the
        // hardcoded LANG_NAMES map. Native name wins (it's the more
        // user-friendly display); fall back to display_name, then to
        // our built-in dictionary, then to the uppercased code.
        var registry = window.LANGUAGE_REGISTRY || [];
        for (var i = 0; i < registry.length; i++) {
            if (registry[i].code === code) {
                return registry[i].native_name ||
                       registry[i].display_name ||
                       LANG_NAMES[code] ||
                       code.toUpperCase();
            }
        }
        return LANG_NAMES[code] || code.toUpperCase();
    }

    function ready(fn) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', fn);
        } else {
            fn();
        }
    }

    function render() {
        var menu = document.getElementById('languageSwitcherMenu');
        if (!menu) return;

        var langs = window.AVAILABLE_LANGS || [];
        var current = window.CURRENT_LANG || 'en';

        var html = '';
        for (var i = 0; i < langs.length; i++) {
            var code = langs[i];
            var label = displayName(code);
            var isActive = (code === current);
            html += '<li>';
            html += '<a class="dropdown-item lang-switch-link' + (isActive ? ' active' : '') + '"';
            html += '   href="#" data-lang="' + code + '">';
            html += '<span class="badge bg-secondary me-2" style="font-family:monospace;font-size:0.7rem">' + code.toUpperCase() + '</span>';
            html += label;
            if (isActive) {
                html += ' <i class="bi bi-check2 ms-2 text-success"></i>';
            }
            html += '</a></li>';
        }
        menu.innerHTML = html;

        // Wire click handlers.
        var links = menu.querySelectorAll('.lang-switch-link');
        for (var j = 0; j < links.length; j++) {
            links[j].addEventListener('click', onSelect);
        }
    }

    function onSelect(ev) {
        ev.preventDefault();
        var lang = this.getAttribute('data-lang');
        if (!lang) return;
        if (lang === window.CURRENT_LANG) {
            // Same language already active. Side-effect-free reload kept
            // because the user clearly meant SOMETHING; rather than
            // silently doing nothing, we close the dropdown and move on.
            return;
        }
        switchTo(lang);
    }

    function switchTo(lang) {
        var btn = document.getElementById('languageSwitcherBtn');
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>';
        }

        fetch('api/set-language.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': window.CSRF_TOKEN || ''
            },
            body: JSON.stringify({
                lang: lang,
                csrf_token: window.CSRF_TOKEN || ''
            })
        })
            .then(function (r) {
                return r.json().then(function (j) {
                    return { status: r.status, body: j };
                });
            })
            .then(function (res) {
                if (res.status === 200 && res.body && res.body.success) {
                    // Reload so every t() call on every page sees the new lang.
                    window.location.reload();
                    return;
                }

                // Failed — restore the button and show what happened.
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bi bi-translate me-1"></i><span id="languageSwitcherCurrent">' +
                        (window.CURRENT_LANG || 'en').toUpperCase() + '</span>';
                }
                var msg = (res.body && res.body.error) || 'Language switch failed';
                if (res.body && res.body.available && res.body.available.length) {
                    msg += ' (available: ' + res.body.available.join(', ') + ')';
                }
                if (typeof window.alert === 'function') {
                    window.alert(msg);
                }
            })
            .catch(function (err) {
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bi bi-translate me-1"></i><span id="languageSwitcherCurrent">' +
                        (window.CURRENT_LANG || 'en').toUpperCase() + '</span>';
                }
                if (typeof window.alert === 'function') {
                    window.alert('Language switch failed: ' + (err && err.message ? err.message : 'network error'));
                }
            });
    }

    ready(render);
})();
