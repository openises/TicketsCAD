/**
 * NewUI v4.0 - Theme Manager
 *
 * Handles Day/Night toggle, applies Bootstrap theme and custom CSS vars.
 */
var ThemeManager = (function () {
    var currentTheme = document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'Night' : 'Day';

    function setTheme(theme) {
        currentTheme = theme;
        var bsTheme = (theme === 'Night') ? 'dark' : 'light';
        document.documentElement.setAttribute('data-bs-theme', bsTheme);

        // Update toggle buttons
        var btns = document.querySelectorAll('#themeToggle button');
        btns.forEach(function (btn) {
            var t = btn.getAttribute('data-theme');
            btn.className = 'btn ' + (t === theme
                ? (t === 'Day' ? 'btn-warning' : 'btn-primary')
                : 'btn-outline-secondary');
        });

        // Persist to localStorage for login page
        try { localStorage.setItem('ticketsTheme', theme); } catch (e) {}

        // Save to server
        var csrfMeta = document.querySelector('meta[name="csrf-token"]');
        var csrf = csrfMeta ? csrfMeta.getAttribute('content') : '';
        fetch('api/theme.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrf
            },
            body: JSON.stringify({ theme: theme, csrf_token: csrf })
        }).then(function (res) {
            return res.json();
        }).then(function (data) {
            if (data.colors) {
                applyColors(data.colors);
            }
            EventBus.emit('theme:changed', { theme: theme, colors: data.colors });
        }).catch(function (err) {
            console.error('Theme save error:', err);
        });
    }

    function applyColors(colors) {
        var root = document.documentElement;
        Object.keys(colors).forEach(function (key) {
            root.style.setProperty('--tickets-' + key, colors[key]);
        });
    }

    function getTheme() {
        return currentTheme;
    }

    function init() {
        // Bind toggle buttons
        var btns = document.querySelectorAll('#themeToggle button');
        btns.forEach(function (btn) {
            btn.addEventListener('click', function () {
                setTheme(btn.getAttribute('data-theme'));
            });
        });

        // Load initial colors
        DataService.fetchJSON('api/theme.php').then(function (data) {
            if (data.colors) {
                applyColors(data.colors);
            }
        }).catch(function () {});
    }

    return {
        init: init,
        setTheme: setTheme,
        getTheme: getTheme,
        applyColors: applyColors
    };
})();
