/**
 * NewUI v4.0 - Command Bar
 *
 * A slim command palette that appears when "/" is typed on any page
 * (unless an input/textarea is focused). Provides quick navigation +
 * widget-focus commands.
 *
 * Phase 86b — prefix-match autocomplete:
 *   - Typing a unique prefix (e.g. "/in") is enough to identify a
 *     command and Enter executes it.
 *   - Ambiguous prefixes (e.g. "/r" matches reports/responders/roster/
 *     roles/road) show a dropdown of completions; ArrowUp/ArrowDown +
 *     Enter, or click, selects one.
 *   - Exact matches still execute on Enter, even when other commands
 *     share the prefix.
 *
 * Each command has:
 *   name        canonical command (e.g. "incidents")
 *   aliases     short forms / synonyms (e.g. ["inc"])
 *   description one-liner shown in the dropdown
 *   handler     function invoked on execute
 *
 * Depends on: KeyboardNav (keyboard-nav.js), EventBus, DashboardActions
 *             (some handlers degrade gracefully if these are absent —
 *             e.g. widget-focus commands no-op on non-dashboard pages).
 */
(function () {
    'use strict';

    var barEl = null;
    var inputEl = null;
    var suggestEl = null;
    var visible = false;
    var suggestions = [];     // current matches (array of command objects)
    var selectedIdx = -1;     // highlighted row in the dropdown

    // ── Command Registry ──
    // Each entry: { name, aliases, description, handler }
    // The "name" is what shows in the dropdown; aliases are alternate
    // strings the matcher accepts.
    var COMMANDS = [
        // Eric 2026-07-04 (clarified): the DASHBOARD (index.php) is his
        // primary situational-awareness screen — /si /sit /situ
        // /situatio /situation should ALL open it. The full-screen
        // situation.php page is only opened once a night to set up the
        // large monitor for big events, so it gets its own distinct,
        // non-colliding command ('bigscreen' + wall/fullscreen/eoc) that
        // does NOT start with 'si'. Result: /si is a unique prefix for
        // the dashboard, /sit /situ are exact dashboard aliases, and
        // /s stays the status command (see 'status' below).
        { name: 'dashboard',   aliases: ['dash', 'home', 'sit', 'situ', 'situation'], description: 'Open the dashboard (situational view)',   handler: function () { go('index.php'); } },
        { name: 'bigscreen',   aliases: ['wall', 'fullscreen', 'eoc'],                description: 'Open the full-screen situation display (large monitor)', handler: function () { go('situation.php'); } },

        // Dispatch widgets (dashboard-only — degrade gracefully)
        { name: 'incidents',   aliases: ['inc'],                  description: 'Focus the Active Incidents widget',             handler: doFocusIncidents },
        { name: 'responders',  aliases: ['res', 'resp'],          description: 'Focus the Responders widget',                   handler: doFocusResponders },
        { name: 'units',       aliases: ['uni'],                  description: 'Focus the Responders widget (units view)',      handler: doFocusResponders },
        { name: 'facilities',  aliases: ['fac'],                  description: 'Focus the Facilities widget',                   handler: doFocusFacilities },
        { name: 'log',         aliases: ['logs'],                 description: 'Focus the Activity Log widget',                 handler: doFocusLog },
        { name: 'detail',      aliases: [],                       description: 'Open detail page for the selected incident',    handler: doOpenDetail },
        { name: 'zello',       aliases: ['zel'],                  description: 'Toggle the Zello radio panel',                  handler: doToggleZello },

        // Workflow actions
        { name: 'new',         aliases: [],                       description: 'Create a new incident',                         handler: function () { go('new-incident.php'); } },

        // Page-level navigation aliases (Phase 86b additions)
        { name: 'search',      aliases: [],                       description: 'Open the search page',                          handler: function () { go('search.php'); } },
        { name: 'reports',     aliases: [],                       description: 'Open the reports page',                         handler: function () { go('reports.php'); } },
        { name: 'settings',    aliases: [],                       description: 'Open the settings / configuration page',        handler: function () { go('settings.php'); } },
        { name: 'sop',         aliases: [],                       description: 'Open the SOP viewer',                           handler: function () { go('sop.php'); } },
        { name: 'help',        aliases: [],                       description: 'Open the help page',                            handler: function () { go('help.php'); } },
        { name: 'roster',      aliases: [],                       description: 'Open the personnel roster',                     handler: function () { go('roster.php'); } },
        { name: 'teams',       aliases: ['team'],                 description: 'Open the teams page',                           handler: function () { go('teams.php'); } },
        { name: 'schedule',    aliases: [],                       description: 'Open the scheduling page',                      handler: function () { go('scheduling.php'); } },
        { name: 'vehicles',    aliases: [],                       description: 'Open the vehicles page',                        handler: function () { go('vehicles.php'); } },
        { name: 'equipment',   aliases: [],                       description: 'Open the equipment page',                       handler: function () { go('equipment.php'); } },
        { name: 'roles',       aliases: [],                       description: 'Open the roles & permissions admin page',       handler: function () { go('roles.php'); } },
        { name: 'profile',     aliases: [],                       description: 'Open your user profile',                        handler: function () { go('profile.php'); } },
        { name: 'contacts',    aliases: ['constituents'],         description: 'Open the contacts / constituents page',         handler: function () { go('constituents.php'); } },
        { name: 'messages',    aliases: ['messaging'],            description: 'Open internal messaging',                       handler: function () { go('messaging.php'); } },
        { name: 'links',       aliases: [],                       description: 'Open the external links page',                  handler: function () { go('links.php'); } },
        { name: 'ics',         aliases: ['forms'],                description: 'Open the ICS forms page',                       handler: function () { go('ics-forms.php'); } },

        // Phase 99r (a beta tester beta 2026-06-29) — unit status changes
        // from the command bar. Syntax:
        //   /s <handle> <status>     → set unit to status
        //   /status M21 av           → set M21 Available
        //   /st E2 disp              → set Engine 2 Dispatched
        // takesArgs=true tells executeCurrent to route the whole input
        // (not just the command word) to the handler. See doStatusCommand.
        { name: 'status',      aliases: ['s', 'st'],              description: 'Change unit status — /s &lt;handle&gt; &lt;status&gt;',  handler: doStatusCommand, takesArgs: true },

        // Phase 109 — Event Net-Control zone move. Syntax:
        //   /z <team> <zone>   → put a unit in an event zone
        //   /z alpha 3         → Alpha to Zone with code/name "3"
        //   /z a park          → unit "a" to the Parking zone
        //   /z echo clear      → clear Echo's zone
        // Requires an active event selected on the Net Control board
        // (persisted in localStorage as nc_selected_event). Prefix-
        // friendly like /s. See doZoneCommand.
        { name: 'zone',        aliases: ['z'],                    description: 'Set a unit\'s event zone — /z &lt;team&gt; &lt;zone&gt;', handler: doZoneCommand, takesArgs: true }
    ];

    // Status alias map: short codes + spelled-out variants → canonical
    // status_val from un_status table. Order doesn't matter; the
    // matcher walks every entry case-insensitively. Multi-word names
    // (e.g. "on scene") are handled by the two-token fallback in
    // parseStatusFromTail. Statuses requiring extra_data (transporting
    // → facility, out-of-service → note) are accepted but the handler
    // will refuse them in v1 and direct the dispatcher to the modal.
    var STATUS_ALIASES = {
        'av':           'Available',
        'avail':        'Available',
        'available':    'Available',
        'busy':         'Busy',
        'unav':         'Unavailable',
        'unavail':      'Unavailable',
        'unavailable':  'Unavailable',
        'disp':         'Dispatched',
        'dispatched':   'Dispatched',
        'dp':           'Dispatched',
        // Issue #18 re-reopen (a beta tester 2026-07-04) — 'en' was absent
        // entirely, and 'enroute' too. His catalog uses EN/Enroute.
        // Tier-1 exact on the canonical name beats the loose tier-3
        // substring ('en' is a substring of "On Sc-en-e" — without
        // this alias the fallback could grab the wrong status).
        'en':           'Enroute',
        'enr':          'Enroute',
        'enroute':      'Enroute',
        'resp':         'Responding',
        'responding':   'Responding',
        'os':           'On Scene',
        'onscene':      'On Scene',
        'on-scene':     'On Scene',
        'on_scene':     'On Scene',
        'tx':           'Transporting',
        'transp':       'Transporting',
        'transport':    'Transporting',
        'transporting': 'Transporting',
        'af':           'At Facility',
        'atfacility':   'At Facility',
        'at-facility':  'At Facility',
        'iq':           'In Quarters',
        'inquarters':   'In Quarters',
        'in-quarters':  'In Quarters',
        'oos':          'Out of Service'
    };

    // ── Matcher ──

    /**
     * matchCommands(input) → array of command objects whose name OR
     * any alias starts with the given input (case-insensitive).
     *
     * Behaviour:
     *   - empty input → returns []
     *   - exact-match of name or alias → returns ONLY that command
     *     (so /inc executes /incidents even though /incidents shares
     *     the prefix)
     *   - prefix match → returns ALL commands sharing the prefix,
     *     de-duplicated, in registry order.
     */
    function matchCommands(input) {
        var q = (input || '').replace(/^\//, '').trim().toLowerCase();
        if (!q) return [];

        // Pass 1 — exact match on canonical name or alias.
        var i, j, cmd;
        for (i = 0; i < COMMANDS.length; i++) {
            cmd = COMMANDS[i];
            if (cmd.name === q) return [cmd];
            for (j = 0; j < cmd.aliases.length; j++) {
                if (cmd.aliases[j] === q) return [cmd];
            }
        }

        // Pass 2 — prefix match (any token: name or alias starts with q).
        var matches = [];
        var seen = {};
        for (i = 0; i < COMMANDS.length; i++) {
            cmd = COMMANDS[i];
            var hit = (cmd.name.indexOf(q) === 0);
            if (!hit) {
                for (j = 0; j < cmd.aliases.length; j++) {
                    if (cmd.aliases[j].indexOf(q) === 0) { hit = true; break; }
                }
            }
            if (hit && !seen[cmd.name]) {
                matches.push(cmd);
                seen[cmd.name] = true;
            }
        }
        return matches;
    }

    // ── Init ──

    function init() {
        barEl = document.getElementById('commandBar');
        inputEl = document.getElementById('commandInput');
        if (!barEl || !inputEl) return;

        // Build the suggestions list element (one-shot).
        suggestEl = document.createElement('ul');
        suggestEl.className = 'command-bar-suggest list-group';
        suggestEl.setAttribute('role', 'listbox');
        suggestEl.style.display = 'none';
        // Append to the inner wrapper so it sits visually under the input.
        var inner = barEl.querySelector('.command-bar-inner');
        if (inner && inner.parentNode) {
            inner.parentNode.insertBefore(suggestEl, inner.nextSibling);
        } else {
            barEl.appendChild(suggestEl);
        }

        // Global "/" listener to open command bar.
        document.addEventListener('keydown', function (e) {
            var tag = e.target.tagName;
            if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || e.target.isContentEditable) {
                return;
            }
            if (e.key === '/' && !e.ctrlKey && !e.metaKey && !e.altKey) {
                e.preventDefault();
                show();
            }
        });

        // Input handlers — keydown handles control keys (Esc, Enter,
        // ArrowUp/Down, Tab); the 'input' event refreshes the matches.
        inputEl.addEventListener('keydown', function (e) {
            e.stopPropagation(); // Prevent keyboard-nav from capturing.
            if (e.key === 'Escape') {
                e.preventDefault();
                hide();
                return;
            }
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                moveSelection(1);
                return;
            }
            if (e.key === 'ArrowUp') {
                e.preventDefault();
                moveSelection(-1);
                return;
            }
            if (e.key === 'Tab') {
                // Tab completes to the highlighted (or first) suggestion.
                if (suggestions.length > 0) {
                    e.preventDefault();
                    var idx = selectedIdx >= 0 ? selectedIdx : 0;
                    inputEl.value = '/' + suggestions[idx].name;
                    refresh();
                }
                return;
            }
            if (e.key === 'Enter') {
                e.preventDefault();
                executeCurrent();
                return;
            }
        });

        inputEl.addEventListener('input', refresh);

        // Click handlers on the suggestions list.
        suggestEl.addEventListener('mousedown', function (e) {
            // mousedown fires before the input loses focus.
            var li = e.target;
            while (li && li !== suggestEl && li.tagName !== 'LI') li = li.parentNode;
            if (!li || li === suggestEl) return;
            var idx = parseInt(li.getAttribute('data-idx'), 10);
            if (isNaN(idx) || !suggestions[idx]) return;
            e.preventDefault();
            hide();
            suggestions[idx].handler();
        });

        // Hide when clicking outside the inner area.
        barEl.addEventListener('click', function (e) {
            if (e.target === barEl) {
                hide();
            }
        });
    }

    // ── Show / hide / refresh ──

    function show() {
        if (!barEl) return;
        visible = true;
        barEl.classList.remove('d-none');
        inputEl.value = '/';
        inputEl.focus();
        inputEl.setSelectionRange(1, 1);
        suggestions = [];
        selectedIdx = -1;
        renderSuggestions();
    }

    function hide() {
        if (!barEl) return;
        visible = false;
        barEl.classList.add('d-none');
        inputEl.value = '';
        inputEl.blur();
        suggestions = [];
        selectedIdx = -1;
        renderSuggestions();
    }

    /** Re-run the matcher and re-render the dropdown. */
    function refresh() {
        suggestions = matchCommands(inputEl.value);
        // Reset highlight to the first row when the set changes.
        selectedIdx = suggestions.length > 0 ? 0 : -1;
        renderSuggestions();
    }

    function renderSuggestions() {
        if (!suggestEl) return;
        // Don't show the dropdown for a single exact-or-only match —
        // the user can just hit Enter. Showing it would be noise.
        var q = (inputEl.value || '').replace(/^\//, '').trim().toLowerCase();
        var hideDropdown = false;
        if (suggestions.length === 0) hideDropdown = true;
        if (suggestions.length === 1) {
            var only = suggestions[0];
            if (only.name === q) hideDropdown = true;
            for (var a = 0; a < only.aliases.length; a++) {
                if (only.aliases[a] === q) { hideDropdown = true; break; }
            }
        }
        if (hideDropdown) {
            suggestEl.style.display = 'none';
            suggestEl.innerHTML = '';
            return;
        }

        var html = '';
        for (var i = 0; i < suggestions.length; i++) {
            var cmd = suggestions[i];
            var aliasHtml = '';
            if (cmd.aliases.length > 0) {
                aliasHtml = ' <span class="text-body-tertiary small ms-1">(' + escapeHtml(cmd.aliases.join(', ')) + ')</span>';
            }
            var active = (i === selectedIdx) ? ' active' : '';
            html += '<li class="list-group-item list-group-item-action command-bar-suggest-item' + active + '"' +
                    ' data-idx="' + i + '" role="option">' +
                    '<code class="me-2">/' + escapeHtml(cmd.name) + '</code>' +
                    aliasHtml +
                    '<span class="ms-2 text-body-secondary">' + escapeHtml(cmd.description) + '</span>' +
                    '</li>';
        }
        suggestEl.innerHTML = html;
        suggestEl.style.display = 'block';
    }

    function moveSelection(delta) {
        if (suggestions.length === 0) return;
        selectedIdx += delta;
        if (selectedIdx < 0) selectedIdx = suggestions.length - 1;
        if (selectedIdx >= suggestions.length) selectedIdx = 0;
        renderSuggestions();
    }

    /**
     * Execute the current input. Behaviour:
     *   - command with args (e.g. "/s M21 av") and the first token
     *     matches a takesArgs command → run handler(argString)
     *   - 1 match (exact or unique prefix) → run it
     *   - >1 matches with a highlighted row → run highlighted
     *   - >1 matches with no highlight → flash invalid
     *   - 0 matches → flash invalid
     */
    function executeCurrent() {
        // Phase 99r — args-bearing command path. When the input has
        // whitespace after the first token (e.g. "/s M21 av"),
        // route the entire arg string to the matching takesArgs
        // command, skipping the prefix-match flow that would
        // otherwise see "s m21 av" as a no-match.
        var raw = (inputEl.value || '').replace(/^\//, '').trim();
        var firstSpace = raw.indexOf(' ');
        if (firstSpace > 0) {
            var cmdToken = raw.substring(0, firstSpace).toLowerCase();
            var argString = raw.substring(firstSpace + 1).trim();
            for (var k = 0; k < COMMANDS.length; k++) {
                var c = COMMANDS[k];
                if (!c.takesArgs) continue;
                if (c.name === cmdToken || (c.aliases || []).indexOf(cmdToken) >= 0) {
                    hide();
                    try { c.handler(argString); } catch (e) {
                        console.error('[command-bar] handler error:', e);
                    }
                    return;
                }
            }
            // No takesArgs match — fall through to the no-args matcher
            // which will likely flash invalid for an input with spaces.
        }

        var matches = matchCommands(inputEl.value);
        var pick = null;
        if (matches.length === 1) {
            pick = matches[0];
        } else if (matches.length > 1 && selectedIdx >= 0 && suggestions[selectedIdx]) {
            pick = suggestions[selectedIdx];
        }
        if (pick) {
            hide();
            pick.handler();
            return;
        }
        // Unknown / ambiguous-without-pick — flash red briefly.
        inputEl.classList.add('is-invalid');
        setTimeout(function () {
            inputEl.classList.remove('is-invalid');
        }, 600);
    }

    // ── Command Handlers ──

    function go(url) {
        window.location.href = url;
    }

    function doFocusIncidents() {
        if (window.KeyboardNav) {
            window.KeyboardNav.focusIncidents();
        }
    }

    function doFocusResponders() {
        var widget = document.querySelector('.widget-responders');
        if (widget) {
            widget.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        }
        if (window.KeyboardNav) {
            window.KeyboardNav.focusResponders();
        }
    }

    function doFocusLog() {
        var widget = document.querySelector('.widget-log');
        if (widget) {
            widget.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        }
    }

    function doFocusFacilities() {
        var widget = document.querySelector('.widget-facilities');
        if (widget) {
            widget.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        }
        if (window.KeyboardNav) {
            window.KeyboardNav.focusFacilities();
        }
    }

    function doToggleZello() {
        if (typeof EventBus !== 'undefined') {
            EventBus.emit('zello:toggle');
        }
    }

    function doOpenDetail() {
        if (window.DashboardActions) {
            var id = window.DashboardActions.getSelectedId();
            if (id && window.DashboardActions.getSelectedType() === 'incident') {
                window.location.href = 'incident-detail.php?id=' + id;
            }
        }
    }

    /**
     * Phase 99r (a beta tester beta 2026-06-29) — /status command.
     *
     * argString is the part after "/s " or "/status ". Shape:
     *   "<handle> <status>"   e.g. "M21 av"
     *   "<multi word handle> <status>"   e.g. "Engine 2 dispatched"
     *
     * Strategy: try to match the LAST whitespace-separated token as
     * a status alias. If it matches, the handle is everything before.
     * If not, try the LAST TWO tokens (covers "on scene", "at
     * facility", "in quarters"). If still no match, refuse with a
     * helpful error.
     *
     * Unit lookup is exact-match (case-insensitive) on handle,
     * callsign, or name. Multiple matches → refuse with "ambiguous".
     *
     * V1 limitations:
     *   - statuses requiring extra_data (transporting, out-of-service
     *     when configured as such) are refused with a "use the modal"
     *     hint. Modal flow handles facility autocomplete, location
     *     picker, etc.; replicating that in the command bar adds
     *     complexity that's deferred to v2.
     */
    function doStatusCommand(argString) {
        if (!window.DashboardActions
            || !window.DashboardActions.getRespondersSnapshot
            || !window.DashboardActions.postUnitStatus
            || !window.DashboardActions.loadUnStatusOptions) {
            return statusError('Status changes are only available on the dashboard');
        }
        var args = (argString || '').trim();
        if (!args) {
            return statusError('Usage: /s <handle> <status>  (e.g. /s M21 av)');
        }

        // Parse: split, try last 1 token then last 2 tokens as the status.
        // Issue #18 reopen (a beta tester, 2026-07-02): keep the RAW status
        // token the user typed alongside the alias canonicalization,
        // so the un_status lookup can try both. Installations often
        // configure short-form status_val entries like "TX" (instead of
        // "Transporting" or "TX Transporting"), and 'transporting' does
        // NOT contain 'tx' as a substring — so aliasing-then-lookup on
        // canon alone misses those sites.
        var tokens = args.split(/\s+/);
        var canonStatus = null;
        var rawStatus = null;     // exactly what the operator typed
        var handleStr = null;
        // Issue #18 re-reopen (a beta tester, 2026-07-04): the previous parse
        // only ever set rawStatus when the token was IN the alias map,
        // so an unrecognized token like "en" died right here with
        // "Unknown status" — the four-tier catalog matcher below never
        // even ran, even though the install's un_status literally
        // contained a row it could have matched. New order:
        //   1. Multi-word forms first (3-token "out of service", then
        //      the 2-token map) so "/s test1 on scene" doesn't get
        //      parsed as handle="test1 on" status="scene".
        //   2. The single-token fallback ALWAYS claims the last token
        //      as rawStatus, alias or not. The alias map upgrades it
        //      to a canonical name when known; otherwise the raw token
        //      itself goes to the catalog matcher, which tries exact
        //      and substring matches against the install's actual
        //      status_val values ("EN", "Enroute", whatever they are).
        if (tokens.length >= 3) {
            var threeTokens = (tokens[tokens.length - 3] + ' '
                             + tokens[tokens.length - 2] + ' '
                             + tokens[tokens.length - 1]).toLowerCase();
            if (threeTokens === 'out of service') {
                canonStatus = 'Out of Service';
                rawStatus   = threeTokens;
                handleStr = tokens.slice(0, tokens.length - 3).join(' ').trim();
            }
        }
        if (!rawStatus && tokens.length >= 2) {
            var twoTokens = (tokens[tokens.length - 2] + ' ' + tokens[tokens.length - 1]).toLowerCase();
            // Manual two-token names not in the alias map.
            var twoTokenMap = {
                'on scene':       'On Scene',
                'at facility':    'At Facility',
                'in quarters':    'In Quarters'
            };
            if (twoTokenMap[twoTokens]) {
                canonStatus = twoTokenMap[twoTokens];
                rawStatus   = twoTokens;
                handleStr = tokens.slice(0, tokens.length - 2).join(' ').trim();
            }
        }
        if (!rawStatus && tokens.length >= 1) {
            var oneToken = tokens[tokens.length - 1].toLowerCase();
            rawStatus = oneToken;
            handleStr = tokens.slice(0, tokens.length - 1).join(' ').trim();
            if (STATUS_ALIASES[oneToken]) {
                canonStatus = STATUS_ALIASES[oneToken];
            }
        }
        // No alias hit — the raw token doubles as the canonical
        // candidate so the tier-1 exact match still gets a shot.
        if (!canonStatus) {
            canonStatus = rawStatus;
        }
        if (!handleStr) {
            return statusError('Usage: /s <handle> ' + tokens[tokens.length - 1]);
        }

        // Resolve the unit. Match exact (case-insensitive) on handle,
        // callsign, or name. Most operators type the handle ("M21",
        // "E2") so handle wins ties.
        var responders = window.DashboardActions.getRespondersSnapshot();
        var needle = handleStr.toLowerCase();
        var byHandle = responders.filter(function (r) {
            return (r.handle || '').toLowerCase() === needle;
        });
        var byCallsign = responders.filter(function (r) {
            return (r.callsign || '').toLowerCase() === needle;
        });
        var byName = responders.filter(function (r) {
            return (r.name || '').toLowerCase() === needle;
        });
        var matches = byHandle.length ? byHandle
                    : byCallsign.length ? byCallsign
                    : byName;
        if (matches.length === 0) {
            return statusError('No unit matches "' + handleStr + '"');
        }
        if (matches.length > 1) {
            return statusError('"' + handleStr + '" matches ' + matches.length + ' units — be more specific');
        }
        var resp = matches[0];

        // Look up the un_status row matching canonStatus.
        window.DashboardActions.loadUnStatusOptions().then(function (statuses) {
            // Phase 103b + Issue #18 reopen (a beta tester, 2026-07-02) —
            // Four-tier match against status_val:
            //   1. Exact (case-insensitive) — 'Available' == 'available'
            //   2. Trimmed exact — trailing space / hyphenation
            //   3. Raw-alias exact — the user typed 'tx' and the site
            //      configured its status_val as literally 'TX' (or
            //      'Transporting (TX)', etc.). This covers the case
            //      that broke #18 the first time: 'transporting' does
            //      NOT contain 'tx' as a substring, so we couldn't
            //      substring-fallback out of it. Trying the raw alias
            //      as a substring picks up short-form configurations.
            //   4. Substring — the alias-canonical form contained in
            //      status_val OR vice-versa (covers 'On Scene' vs
            //      'On-Scene', 'Available' vs 'Available Unit',
            //      'Transporting' vs 'TX Transporting')
            var canonLower = canonStatus.toLowerCase();
            var rawLower   = (rawStatus || '').toLowerCase();
            var statusOpt = statuses.find(function (s) {
                return (s.status_val || '').toLowerCase() === canonLower;
            });
            if (!statusOpt) {
                statusOpt = statuses.find(function (s) {
                    return (s.status_val || '').trim().toLowerCase() === canonLower;
                });
            }
            if (!statusOpt && rawLower) {
                // Try the operator's exact typed alias as-is against
                // status_val (exact then substring).
                statusOpt = statuses.find(function (s) {
                    return (s.status_val || '').trim().toLowerCase() === rawLower;
                });
                if (!statusOpt) {
                    statusOpt = statuses.find(function (s) {
                        var v = (s.status_val || '').toLowerCase();
                        return v.indexOf(rawLower) !== -1;
                    });
                }
            }
            if (!statusOpt) {
                statusOpt = statuses.find(function (s) {
                    var v = (s.status_val || '').toLowerCase();
                    return v.indexOf(canonLower) !== -1 || canonLower.indexOf(v) !== -1;
                });
            }
            if (!statusOpt) {
                if (!statuses || statuses.length === 0) {
                    return statusError(
                        'No unit statuses are configured on this install — '
                        + 'check Config > App Preferences > Unit Statuses.'
                    );
                }
                var available = statuses.map(function (s) { return s.status_val; }).join(', ');
                return statusError(
                    'Status "' + canonStatus + '" not configured on this install. '
                    + 'Available: ' + available
                );
            }
            // V1 — refuse statuses that need extra_data. They have to
            // go through the modal which has typeaheads/pickers.
            // (Phase 99r-v2 will add inline collection here.)
            if (statusOpt.extra_data_required
                && statusOpt.extra_data_type
                && statusOpt.extra_data_type !== 'none') {
                return statusError(
                    canonStatus + ' needs ' + statusOpt.extra_data_type
                    + ' info — open the unit and use the Status modal instead'
                );
            }
            // Fire and forget — postUnitStatus shows its own toast on
            // success and alert() on error.
            window.DashboardActions.postUnitStatus(resp, statusOpt, null);
        });
    }

    function statusError(msg) {
        // Best-effort surface — alert() because the command bar
        // itself is now hidden (we already called hide()). Keeps
        // the dispatcher from wondering why nothing happened.
        alert(msg);
    }

    // ── /z <team> <zone> — Event Net-Control zone move (Phase 109) ──
    function _cmdCsrf() {
        var m = document.querySelector('meta[name="csrf-token"]');
        return m ? m.content : (window.CSRF_TOKEN || '');
    }
    function doZoneCommand(argString) {
        var tokens = String(argString || '').trim().split(/\s+/);
        if (tokens.length < 2) {
            return statusError('Usage: /z <team> <zone>   e.g. /z alpha 3');
        }
        var zoneTok = tokens[tokens.length - 1].toLowerCase();
        var teamStr = tokens.slice(0, tokens.length - 1).join(' ').trim().toLowerCase();

        var eventId = 0;
        try { eventId = parseInt(localStorage.getItem('nc_selected_event'), 10) || 0; } catch (e) {}
        if (!eventId) {
            return statusError('No active event selected. Open Net Control and pick the event first.');
        }

        // Pull the current board so we can resolve team -> assign_id and
        // zone -> zone_id without the command bar caching its own state.
        fetch('api/net-control.php?ticket_id=' + eventId, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.error) { return statusError(data.error); }
                var units = data.units || [];
                var zones = data.zones || [];

                // Zone: exact code, then exact name, then name prefix.
                // clear/none/off/roam-to-nothing → 0 (clears the zone).
                var zoneId = null;
                if (zoneTok === 'clear' || zoneTok === 'none' || zoneTok === 'off') {
                    zoneId = 0;
                } else {
                    var z = zones.find(function (zz) { return String(zz.code || '').toLowerCase() === zoneTok; })
                         || zones.find(function (zz) { return String(zz.name || '').toLowerCase() === zoneTok; })
                         || zones.find(function (zz) { return String(zz.name || '').toLowerCase().indexOf(zoneTok) === 0; });
                    if (!z) {
                        var zlist = zones.map(function (zz) { return zz.code || zz.name; }).join(', ');
                        return statusError('Zone "' + zoneTok + '" not found. Zones: ' + (zlist || '(none configured)'));
                    }
                    zoneId = parseInt(z.id, 10);
                }

                // Team: exact match on callsign/name, then a roster handle,
                // then prefix on name — mirrors the /s resolution ethos.
                function matchUnit(u) {
                    var name = String(u.name || '').toLowerCase();
                    var cs = String(u.callsign || '').toLowerCase();
                    if (cs === teamStr || name === teamStr) return true;
                    if ((u.roster || []).some(function (m) { return String(m.handle || '').toLowerCase() === teamStr; })) return true;
                    return false;
                }
                var matches = units.filter(matchUnit);
                if (!matches.length) {
                    matches = units.filter(function (u) { return String(u.name || '').toLowerCase().indexOf(teamStr) === 0; });
                }
                if (!matches.length) {
                    return statusError('No assigned unit matches "' + teamStr + '" on this event.');
                }
                if (matches.length > 1) {
                    return statusError('"' + teamStr + '" matches ' + matches.length + ' units — be more specific.');
                }
                var unit = matches[0];

                fetch('api/event-zone-update.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        ticket_id: eventId,
                        assign_id: unit.assign_id,
                        zone_id: zoneId,
                        csrf_token: _cmdCsrf()
                    })
                })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (res.error) { return statusError(res.error); }
                    var zn = (res.zone && res.zone.name) ? res.zone.name : (zoneId ? 'zone' : 'cleared');
                    if (typeof window.showBriefToast === 'function') {
                        window.showBriefToast((unit.name || 'Unit') + ' -> ' + zn);
                    }
                    if (typeof window.EventBus !== 'undefined' && window.EventBus.emit) {
                        window.EventBus.emit('widget:refresh', { widget: 'responders' });
                    }
                })
                .catch(function (err) { statusError('Zone update failed: ' + (err.message || String(err))); });
            })
            .catch(function (err) { statusError('Could not load the event board: ' + (err.message || String(err))); });
    }

    // ── Utilities ──

    function escapeHtml(s) {
        if (s == null) return '';
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    // Expose the matcher for tests / future tooling.
    window.CommandBar = {
        match: matchCommands,
        commands: COMMANDS
    };

    // Init on DOM ready.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
