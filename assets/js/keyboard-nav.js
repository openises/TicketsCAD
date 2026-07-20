/**
 * NewUI v4.0 - Keyboard Navigation
 *
 * Provides keyboard-driven list navigation on the dashboard.
 * Arrow keys move between rows, Enter opens detail page.
 *
 * Focused widget determines context. Clicking a widget gives it focus.
 *
 * Key bindings (all widgets):
 *   Up/Down   - Move selection between rows (map pans to selected item)
 *   Enter     - Open detail page for selected item
 *   Escape    - Clear selection, blur widget
 *
 * Key bindings (incidents only):
 *   D         - Dispatch (navigate to assign tab)
 *   V         - View Details
 *   E         - Edit
 *   P         - Popup (open in new window)
 *   X         - Close incident (with confirm)
 *   U         - Units (navigate to assign tab)
 *
 * Key bindings (responders only — Phase 99n, Eric beta 2026-06-29):
 *   V         - View unit detail
 *   E         - Edit unit
 *   D         - Dispatch (open unit detail "assign" tab)
 *   N         - Quick note on the unit's currently-assigned incident
 *
 * Depends on: DashboardActions (exposed by app.js), EventBus
 */
(function () {
    'use strict';

    // Which widget type is currently focused: null or a key from WIDGET_CONFIG
    var focusedWidget = null;

    // ── Widget configuration table ──
    // Each entry defines how arrow/enter keys work for that widget type.
    // DashboardActions methods follow a naming convention:
    //   select{Type}ByOffset(n), selectFirst{Type}()
    var WIDGET_CONFIG = {
        incidents:   { type: 'incident',   detailUrl: 'incident-detail.php?id=' },
        responders:  { type: 'responder',  detailUrl: 'unit-detail.php?id=' },
        facilities:  { type: 'facility',   detailUrl: 'facility-detail.php?id=' }
    };

    // Action key map — single keypress triggers action (incidents only)
    var ACTION_KEYS = {
        'd': 'dispatch',
        'v': 'view',
        'e': 'edit',
        'p': 'popup',
        'x': 'close',
        'u': 'units'
    };
    // Phase 99n (Eric beta 2026-06-29): responder hotkeys. Same letter
    // overlap with incidents (V/E/D) is intentional — these only fire
    // when the responders widget is focused.
    var RESPONDER_ACTION_KEYS = {
        'v': 'view',
        'e': 'edit',
        'd': 'dispatch',
        's': 'status',  // Phase 99n-v2 — opens status modal
        'n': 'note'
    };
    // Phase 115 (Eric 2026-07-06): facility hotkeys. Letter overlap with
    // the other widgets is intentional — these only fire when the
    // facilities widget is focused.
    var FACILITY_ACTION_KEYS = {
        'v': 'view',
        'e': 'edit',
        'i': 'incident',
        's': 'status',
        'n': 'note',
        'b': 'beds'
    };

    function init() {
        document.addEventListener('keydown', handleKeyDown);

        // Track which widget gains focus via click
        document.addEventListener('click', function (e) {
            var matched = false;
            for (var widgetName in WIDGET_CONFIG) {
                if (e.target.closest('.widget-' + widgetName)) {
                    focusWidget(widgetName);
                    matched = true;
                    break;
                }
            }
            if (!matched && !e.target.closest('#commandBar')) {
                blurWidget();
            }
        });
    }

    function focusWidget(type) {
        blurWidget();
        focusedWidget = type;
        var w = document.querySelector('.widget-' + type);
        if (w) {
            var card = w.closest('.grid-stack-item-content');
            if (card) card.setAttribute('data-kb-focused', 'true');
        }
    }

    function blurWidget() {
        if (focusedWidget) {
            var w = document.querySelector('.widget-' + focusedWidget);
            if (w) {
                var card = w.closest('.grid-stack-item-content');
                if (card) card.removeAttribute('data-kb-focused');
            }
        }
        focusedWidget = null;
    }

    /**
     * Generic arrow navigation for the focused widget.
     * Calls DashboardActions.selectByOffset(widgetType, offset)
     * or DashboardActions.selectFirst(widgetType).
     */
    function navigateOffset(cfg, offset) {
        var da = window.DashboardActions;
        if (da.getSelectedType() === cfg.type) {
            da.selectByOffset(cfg.type, offset);
        } else {
            da.selectFirst(cfg.type);
        }
    }

    function handleKeyDown(e) {
        var tag = e.target.tagName;
        if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || e.target.isContentEditable) {
            return;
        }
        if (e.ctrlKey || e.metaKey || e.altKey) return;
        if (!focusedWidget) return;
        if (!window.DashboardActions) return;

        var key = e.key;
        var cfg = WIDGET_CONFIG[focusedWidget];
        if (!cfg) return;

        // Escape — clear selection and blur (all widgets)
        if (key === 'Escape') {
            e.preventDefault();
            window.DashboardActions.clearSelection();
            blurWidget();
            return;
        }

        // Arrow navigation (all widgets)
        if (key === 'ArrowDown') {
            e.preventDefault();
            navigateOffset(cfg, 1);
            return;
        }
        if (key === 'ArrowUp') {
            e.preventDefault();
            navigateOffset(cfg, -1);
            return;
        }

        // Enter — open detail page (all widgets)
        if (key === 'Enter') {
            e.preventDefault();
            var id = window.DashboardActions.getSelectedId();
            if (id && window.DashboardActions.getSelectedType() === cfg.type) {
                var url = cfg.detailUrl + id;
                // Shift+Enter on incidents goes to assign tab
                if (focusedWidget === 'incidents' && e.shiftKey) {
                    url += '&tab=assign';
                }
                window.location.href = url;
            }
            return;
        }

        // Action letter keys (incidents only)
        if (focusedWidget === 'incidents') {
            var lowerKey = key.toLowerCase();
            if (ACTION_KEYS[lowerKey]) {
                e.preventDefault();
                window.DashboardActions.executeAction(ACTION_KEYS[lowerKey]);
                return;
            }
        }
        // Phase 99n: responder action keys
        if (focusedWidget === 'responders') {
            var rKey = key.toLowerCase();
            if (RESPONDER_ACTION_KEYS[rKey] && window.DashboardActions.executeResponderAction) {
                e.preventDefault();
                window.DashboardActions.executeResponderAction(RESPONDER_ACTION_KEYS[rKey]);
                return;
            }
        }
        // Phase 115: facility action keys
        if (focusedWidget === 'facilities') {
            var fKey = key.toLowerCase();
            if (FACILITY_ACTION_KEYS[fKey] && window.DashboardActions.executeFacilityAction) {
                e.preventDefault();
                window.DashboardActions.executeFacilityAction(FACILITY_ACTION_KEYS[fKey]);
                return;
            }
        }
    }

    // Expose focus control for command-bar.js
    window.KeyboardNav = {
        focusIncidents: function () {
            focusWidget('incidents');
            if (window.DashboardActions) window.DashboardActions.selectFirst('incident');
        },
        focusResponders: function () {
            focusWidget('responders');
            if (window.DashboardActions) window.DashboardActions.selectFirst('responder');
        },
        focusFacilities: function () {
            focusWidget('facilities');
            if (window.DashboardActions) window.DashboardActions.selectFirst('facility');
        },
        blurAll: function () {
            blurWidget();
        },
        isFocused: function () {
            return !!focusedWidget;
        },
        getFocusedWidget: function () {
            return focusedWidget;
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
