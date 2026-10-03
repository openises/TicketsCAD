/**
 * SearchableSelect — small reusable combobox / type-ahead picker.
 *
 * Spec: specs/searchable-member-dropdown-2026-05/plan.md (component contract).
 *
 * Usage:
 *
 *   <div class="searchable-select-wrap position-relative">
 *     <input type="text" class="form-control form-control-sm searchable-select-input"
 *            id="myFieldDisplay" autocomplete="off"
 *            placeholder="Type to search, or click to browse">
 *     <input type="hidden" id="myField" name="myfield" value="">
 *     <ul class="searchable-select-list list-group d-none"></ul>
 *   </div>
 *
 *   var picker = SearchableSelect.attach(
 *       document.getElementById('myFieldDisplay'),
 *       document.getElementById('myField'),
 *       arrayOfItems,
 *       {
 *           emptyLabel: '— None —',
 *           getLabel:      function (it) { return it.name; },
 *           getValue:      function (it) { return String(it.id); },
 *           getSearchText: function (it) { return it.name.toLowerCase(); }
 *       }
 *   );
 *
 *   picker.setValue('42');      // select item with id 42 programmatically
 *   picker.setItems(newArray);  // re-populate without re-attaching
 *   picker.getValue();          // → '42'
 *
 * Keyboard contract:
 *   Down/Up      navigate the visible list (skipping disabled rows)
 *   Enter        commit highlighted item; close list
 *   Esc          clear text if any, else close list. When it does either it
 *                STOPS the keystroke there: a picker inside a Bootstrap modal must not
 *                also close the modal (Phase 155, GH#145).
 *   Tab          commit highlighted item if any, else commit single text match,
 *                else clear; default Tab behaviour proceeds (move focus)
 *   typing       filter the list via opts.getSearchText
 *   click input  open the list (full, if input is empty)
 *   click item   commit it
 *   click out    close list
 *
 * Optional options (Phase 155, GH#145). All default to "off", and with none of
 * them given the component behaves exactly as it did before:
 *
 *   onQuery(query, setItems)   SERVER-SIDE search. Called (debounced by
 *                              opts.queryDelay, default 200 ms) on typing and when
 *                              the list opens, instead of filtering the items in the
 *                              browser; call setItems(array) with the answer. Stale
 *                              answers (a slower earlier request) are ignored. The
 *                              component does not filter what setItems gives it.
 *   isDisabled(item)           true => the row shows but cannot be committed
 *   getDisabledReason(item)    text shown under a disabled row (plain text)
 *   getSubLabel(item)          secondary line under every row (plain text)
 *   onCommit(item)             called after an item is committed (not on clear)
 *   noMatchLabel / searchingLabel   texts for the empty and waiting states
 *   hideEmptyOption            true => no "— None —" row at the top (a picker that adds,
 *                              rather than sets a value, has nothing to "unset")
 *
 *   picker.getSelectedItem()   the item object last committed, or null
 *   picker.clear()             empty the input and hidden value
 *   picker.focus()             focus the input
 *
 * Every label, sub-label and reason is escaped here; a caller passes plain text.
 *
 * Accessibility: input role=combobox with aria-expanded, aria-controls,
 * aria-autocomplete and aria-activedescendant; list role=listbox; rows
 * role=option (aria-disabled when disabled).
 */
(function () {
    'use strict';

    var uid = 0;

    // ── Helpers ──────────────────────────────────────────────────────
    function escHtml(s) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(s == null ? '' : String(s)));
        return div.innerHTML;
    }

    function defaultGetValue(item) {
        return String(item && item.id != null ? item.id : '');
    }

    // ── Picker instance ──────────────────────────────────────────────
    function attach(inputEl, hiddenEl, items, opts) {
        if (!inputEl || !hiddenEl) {
            throw new Error('SearchableSelect.attach: inputEl and hiddenEl are required');
        }
        opts = opts || {};

        var emptyLabel    = opts.emptyLabel != null ? opts.emptyLabel : '— None —';
        var maxVisible    = opts.maxVisible || 200;
        var getLabel      = opts.getLabel      || function (it) { return String(it); };
        var getValue      = opts.getValue      || defaultGetValue;
        var getSearchText = opts.getSearchText || function (it) { return getLabel(it).toLowerCase(); };
        var remote        = typeof opts.onQuery === 'function';
        var queryDelay    = opts.queryDelay != null ? opts.queryDelay : 200;
        var isDisabled    = typeof opts.isDisabled === 'function' ? opts.isDisabled : null;
        var disabledWhy   = typeof opts.getDisabledReason === 'function' ? opts.getDisabledReason : null;
        var getSubLabel   = typeof opts.getSubLabel === 'function' ? opts.getSubLabel : null;
        var onCommit      = typeof opts.onCommit === 'function' ? opts.onCommit : null;
        var noMatchLabel  = opts.noMatchLabel != null ? opts.noMatchLabel : 'No matches';
        var searchingLabel = opts.searchingLabel != null ? opts.searchingLabel : 'Searching…';

        // Locate (or create) the <ul> that holds the popover list.
        var wrap = inputEl.parentElement;
        var listEl = wrap ? wrap.querySelector('.searchable-select-list') : null;
        if (!listEl) {
            listEl = document.createElement('ul');
            listEl.className = 'searchable-select-list list-group d-none';
            wrap.appendChild(listEl);
        }

        // ARIA wiring (additive: harmless for an existing consumer).
        var instanceId = 'ss' + (++uid);
        if (!listEl.id) listEl.id = instanceId + '-list';
        listEl.setAttribute('role', 'listbox');
        inputEl.setAttribute('role', 'combobox');
        inputEl.setAttribute('aria-autocomplete', 'list');
        inputEl.setAttribute('aria-expanded', 'false');
        inputEl.setAttribute('aria-controls', listEl.id);

        // Local state
        var currentItems = (items || []).slice();
        var filtered     = currentItems.slice();
        var highlighted  = -1;       // index into the filtered list; -1 = nothing highlighted
        var isOpen       = false;
        var selectedItem = null;
        var pending      = false;    // a remote query is in flight
        var querySeq     = 0;
        var queryTimer   = null;

        function disabledAt(i) {
            return !!(isDisabled && filtered[i] != null && isDisabled(filtered[i]));
        }

        function firstEnabled() {
            for (var i = 0; i < filtered.length; i++) if (!disabledAt(i)) return i;
            return -1;
        }

        // ── Rendering ────────────────────────────────────────────────
        function renderList() {
            var html = '';
            var query = (inputEl.value || '').trim();

            // The "empty" / unlink option appears at the top when the user
            // isn't actively typing. Hides during a search so it doesn't
            // clutter the filtered view.
            if (query === '' && !opts.hideEmptyOption) {
                html += '<li class="list-group-item list-group-item-action searchable-select-item empty-option" role="option" id="' + instanceId + '-o-1" data-idx="-1">'
                     + escHtml(emptyLabel)
                     + '</li>';
            }

            if (pending && filtered.length === 0) {
                html += '<li class="list-group-item searchable-select-item no-match" role="option" aria-disabled="true">' + escHtml(searchingLabel) + '</li>';
            } else if (filtered.length === 0) {
                html += '<li class="list-group-item searchable-select-item no-match" role="option" aria-disabled="true">' + escHtml(noMatchLabel) + '</li>';
            } else {
                var max = Math.min(filtered.length, maxVisible);
                for (var i = 0; i < max; i++) {
                    var label = getLabel(filtered[i]);
                    var off = disabledAt(i);
                    var sub = getSubLabel ? getSubLabel(filtered[i]) : '';
                    var why = (off && disabledWhy) ? disabledWhy(filtered[i]) : '';
                    html += '<li class="list-group-item searchable-select-item' + (off ? ' disabled text-body-secondary' : ' list-group-item-action') + '"'
                         + ' role="option" id="' + instanceId + '-o' + i + '" data-idx="' + i + '"'
                         + (off ? ' aria-disabled="true"' : '') + '>'
                         + escHtml(label)
                         + (sub ? '<div class="small text-body-secondary">' + escHtml(sub) + '</div>' : '')
                         + (why ? '<div class="small text-danger">' + escHtml(why) + '</div>' : '')
                         + '</li>';
                }
                if (filtered.length > max) {
                    html += '<li class="list-group-item searchable-select-item text-body-secondary small">'
                         + '+ ' + (filtered.length - max) + ' more — keep typing to narrow</li>';
                }
            }
            listEl.innerHTML = html;
            applyHighlight();
        }

        function applyHighlight() {
            var nodes = listEl.querySelectorAll('.searchable-select-item');
            var activeId = '';
            for (var i = 0; i < nodes.length; i++) {
                if (i === highlighted) {
                    nodes[i].classList.add('active');
                    nodes[i].setAttribute('aria-selected', 'true');
                    activeId = nodes[i].id || '';
                } else {
                    nodes[i].classList.remove('active');
                    nodes[i].removeAttribute('aria-selected');
                }
            }
            if (activeId) inputEl.setAttribute('aria-activedescendant', activeId);
            else inputEl.removeAttribute('aria-activedescendant');
            // Scroll highlighted into view
            if (highlighted >= 0) {
                var active = listEl.querySelector('.searchable-select-item.active');
                if (active && typeof active.scrollIntoView === 'function') {
                    active.scrollIntoView({ block: 'nearest' });
                }
            }
        }

        function openList() {
            if (isOpen) return;
            listEl.classList.remove('d-none');
            isOpen = true;
            inputEl.setAttribute('aria-expanded', 'true');
            renderList();
        }

        function closeList() {
            if (!isOpen) return;
            listEl.classList.add('d-none');
            isOpen = false;
            highlighted = -1;
            inputEl.setAttribute('aria-expanded', 'false');
            inputEl.removeAttribute('aria-activedescendant');
        }

        // ── Filtering ────────────────────────────────────────────────
        function applyFilter() {
            if (remote) return;     // the server already filtered
            var query = (inputEl.value || '').toLowerCase().trim();
            if (query === '') {
                filtered = currentItems.slice();
            } else {
                filtered = [];
                for (var i = 0; i < currentItems.length; i++) {
                    if (getSearchText(currentItems[i]).indexOf(query) !== -1) {
                        filtered.push(currentItems[i]);
                    }
                }
            }
            // Auto-highlight the first match so Enter / Tab commit it.
            highlighted = filtered.length > 0 ? firstEnabled() : -1;
        }

        /** Ask the server (debounced; immediate when immediate === true). */
        function runQuery(immediate) {
            if (!remote) return;
            if (queryTimer) { clearTimeout(queryTimer); queryTimer = null; }
            var go = function () {
                var seq = ++querySeq;
                pending = true;
                if (isOpen) renderList();
                opts.onQuery((inputEl.value || '').trim(), function (answer) {
                    if (seq !== querySeq) return;       // a newer query superseded this one
                    pending = false;
                    currentItems = (answer || []).slice();
                    filtered = currentItems.slice();
                    highlighted = filtered.length > 0 ? firstEnabled() : -1;
                    if (isOpen) renderList();
                });
            };
            if (immediate || queryDelay <= 0) go();
            else queryTimer = setTimeout(go, queryDelay);
        }

        // ── Commit (select an item) ──────────────────────────────────
        function commitItem(item) {
            if (item == null) {
                inputEl.value  = '';
                hiddenEl.value = '';
                selectedItem = null;
            } else {
                inputEl.value  = getLabel(item);
                hiddenEl.value = getValue(item);
                selectedItem = item;
            }
            closeList();
            // Fire a 'change' on the hidden input so consumers that observe
            // the form via change events (rare here, but cheap) see the
            // update. Don't fire 'input' to avoid re-filtering loops.
            try { hiddenEl.dispatchEvent(new Event('change', { bubbles: true })); }
            catch (e) { /* IE fallback not needed for NewUI's target browsers */ }
            if (item != null && onCommit) onCommit(item);
        }

        function commitEmpty() {
            inputEl.value  = '';
            hiddenEl.value = '';
            selectedItem = null;
            closeList();
            try { hiddenEl.dispatchEvent(new Event('change', { bubbles: true })); }
            catch (e) {}
        }

        function commitHighlightedOrTyped() {
            if (highlighted >= 0 && filtered[highlighted] && !disabledAt(highlighted)) {
                commitItem(filtered[highlighted]);
                return;
            }
            // If typed text uniquely matches one item exactly, commit it.
            var q = (inputEl.value || '').toLowerCase().trim();
            if (q === '') { commitEmpty(); return; }
            if (filtered.length === 1 && !disabledAt(0)) { commitItem(filtered[0]); return; }
            // No unique match — clear so the form doesn't submit a bad value.
            commitEmpty();
        }

        /** Move the highlight by one, jumping over disabled rows. */
        function move(step) {
            var i = highlighted + step;
            while (i >= 0 && i < filtered.length && disabledAt(i)) i += step;
            if (i >= 0 && i < filtered.length) highlighted = i;
            applyHighlight();
        }

        // ── Event handlers ───────────────────────────────────────────
        function onFocus() {
            applyFilter();
            openList();
            if (remote) runQuery(true);
        }

        function onInput() {
            applyFilter();
            openList();
            renderList();
            if (remote) runQuery(false);
        }

        function onKeydown(e) {
            if (!isOpen && e.key !== 'ArrowDown') return;

            if (e.key === 'ArrowDown') {
                e.preventDefault();
                if (!isOpen) { openList(); if (remote) runQuery(true); return; }
                move(1);
                return;
            }
            if (e.key === 'ArrowUp') {
                e.preventDefault();
                move(-1);
                return;
            }
            if (e.key === 'Enter') {
                e.preventDefault();
                commitHighlightedOrTyped();
                return;
            }
            if (e.key === 'Escape') {
                // Consumed here: do not let the same keystroke reach an enclosing modal.
                e.stopPropagation();
                if ((inputEl.value || '') !== '') {
                    e.preventDefault();
                    inputEl.value = '';
                    applyFilter();
                    renderList();
                    if (remote) runQuery(true);
                } else {
                    e.preventDefault();
                    closeList();
                }
                return;
            }
            if (e.key === 'Tab') {
                // Don't preventDefault — let Tab move focus naturally.
                // But commit whatever's in flight first.
                commitHighlightedOrTyped();
                return;
            }
        }

        function onListClick(e) {
            var li = e.target.closest('.searchable-select-item');
            if (!li) return;
            var idx = parseInt(li.getAttribute('data-idx'), 10);
            if (isNaN(idx) || idx === -1) {
                commitEmpty();
                return;
            }
            if (disabledAt(idx)) return;      // a disabled row says why; it cannot be chosen
            if (filtered[idx]) commitItem(filtered[idx]);
        }

        function onDocClick(e) {
            if (!wrap.contains(e.target)) closeList();
        }

        inputEl.addEventListener('focus',   onFocus);
        inputEl.addEventListener('click',   onFocus);
        inputEl.addEventListener('input',   onInput);
        inputEl.addEventListener('keydown', onKeydown);
        listEl.addEventListener('click',    onListClick);
        document.addEventListener('click',  onDocClick);

        // ── Public API ───────────────────────────────────────────────
        return {
            setItems: function (newItems) {
                currentItems = (newItems || []).slice();
                if (remote) {
                    filtered = currentItems.slice();
                    highlighted = filtered.length > 0 ? firstEnabled() : -1;
                } else {
                    applyFilter();
                }
                if (isOpen) renderList();
            },
            setValue: function (val) {
                if (val == null || val === '') {
                    inputEl.value  = '';
                    hiddenEl.value = '';
                    selectedItem = null;
                    return;
                }
                var str = String(val);
                for (var i = 0; i < currentItems.length; i++) {
                    if (getValue(currentItems[i]) === str) {
                        inputEl.value  = getLabel(currentItems[i]);
                        hiddenEl.value = str;
                        selectedItem = currentItems[i];
                        return;
                    }
                }
                // Value not in current items — clear so the form doesn't
                // submit an orphan id.
                inputEl.value  = '';
                hiddenEl.value = '';
                selectedItem = null;
            },
            getValue: function () {
                return hiddenEl.value || '';
            },
            getSelectedItem: function () {
                return hiddenEl.value ? selectedItem : null;
            },
            clear: function () {
                inputEl.value  = '';
                hiddenEl.value = '';
                selectedItem = null;
                closeList();
            },
            focus: function () {
                inputEl.focus();
            },
            destroy: function () {
                if (queryTimer) { clearTimeout(queryTimer); queryTimer = null; }
                querySeq++;
                inputEl.removeEventListener('focus',   onFocus);
                inputEl.removeEventListener('click',   onFocus);
                inputEl.removeEventListener('input',   onInput);
                inputEl.removeEventListener('keydown', onKeydown);
                listEl.removeEventListener('click',    onListClick);
                document.removeEventListener('click',  onDocClick);
                listEl.innerHTML = '';
                listEl.classList.add('d-none');
            }
        };
    }

    window.SearchableSelect = { attach: attach };
})();
