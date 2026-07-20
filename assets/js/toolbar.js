/**
 * NewUI v4.0 - Toolbar Utilities
 *
 * Shared toolbar functionality for all pages:
 *  - 24-hour clock with seconds
 *  - Theme toggle (basic, no DataService dependency)
 */
(function () {
    'use strict';

    // ── 24-Hour Clock ────────────────────────────────────────────
    var clockEl = document.getElementById('toolbarClock');

    function updateClock() {
        if (!clockEl) return;
        var now = new Date();
        var h = now.getHours();
        var m = now.getMinutes();
        var s = now.getSeconds();
        clockEl.textContent =
            (h < 10 ? '0' : '') + h + ':' +
            (m < 10 ? '0' : '') + m + ':' +
            (s < 10 ? '0' : '') + s;
    }

    if (clockEl) {
        updateClock();
        setInterval(updateClock, 1000);
    }

    // ── Theme Toggle (standalone, for non-dashboard pages) ──────
    // Dashboard pages have ThemeManager; this is a fallback for other pages.
    var toggleBtns = document.querySelectorAll('#themeToggle button');
    if (toggleBtns.length && typeof ThemeManager === 'undefined') {
        for (var i = 0; i < toggleBtns.length; i++) {
            (function (btn) {
                btn.addEventListener('click', function () {
                    var theme = btn.getAttribute('data-theme');
                    var bsTheme = (theme === 'Night') ? 'dark' : 'light';
                    document.documentElement.setAttribute('data-bs-theme', bsTheme);

                    for (var j = 0; j < toggleBtns.length; j++) {
                        var t = toggleBtns[j].getAttribute('data-theme');
                        toggleBtns[j].className = 'btn ' + (t === theme
                            ? (t === 'Day' ? 'btn-warning' : 'btn-primary')
                            : 'btn-outline-secondary');
                    }

                    var csrfMeta3 = document.querySelector('meta[name="csrf-token"]');
                    var csrf3 = csrfMeta3 ? csrfMeta3.getAttribute('content') : '';
                    fetch('api/theme.php', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-Token': csrf3
                        },
                        body: JSON.stringify({ theme: theme, csrf_token: csrf3 })
                    }).catch(function () {});
                });
            })(toggleBtns[i]);
        }
    }

    // ── Logo Hover Preview ──────────────────────────────────────────
    // Shows a 4× enlarged logo in a floating popup when hovering the navbar logo.
    var brandEl = document.querySelector('.nav-main .navbar-brand');
    var logoImg = brandEl ? brandEl.querySelector('img') : null;

    if (logoImg) {
        var preview = document.createElement('div');
        preview.className = 'logo-hover-preview';
        var bigImg = document.createElement('img');
        bigImg.src = logoImg.src;
        bigImg.alt = 'Logo preview';
        preview.appendChild(bigImg);
        brandEl.appendChild(preview);

        var showTimer = null;
        var hideTimer = null;

        logoImg.addEventListener('mouseenter', function () {
            clearTimeout(hideTimer);
            showTimer = setTimeout(function () {
                preview.style.display = 'block';
            }, 200);
        });

        logoImg.addEventListener('mouseleave', function () {
            clearTimeout(showTimer);
            hideTimer = setTimeout(function () {
                preview.style.display = 'none';
            }, 300);
        });

        preview.addEventListener('mouseenter', function () {
            clearTimeout(hideTimer);
        });

        preview.addEventListener('mouseleave', function () {
            hideTimer = setTimeout(function () {
                preview.style.display = 'none';
            }, 300);
        });
    }
})();
