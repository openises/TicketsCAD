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
 *   Down/Up      navigate the visible list
 *   Enter        commit highlighted item; close list
 *   Esc          clear text if any, else close list
 *   Tab          commit highlighted item if any, else commit single text match,
 *                else clear; default Tab behaviour proceeds (move focus)
 *   typing       filter the list via opts.getSearchText
 *   click input  open the list (full, if input is empty)
 *   click item   commit it
 *   click out    close list
 */
(function () {
    'use strict';

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

        // Locate (or create) the <ul> that holds the popover list.
        var wrap = inputEl.parentElement;
        var listEl = wrap ? wrap.querySelector('.searchable-select-list') : null;
        if (!listEl) {
            listEl = document.createElement('ul');
            listEl.className = 'searchable-select-list list-group d-none';
            wrap.appendChild(listEl);
        }

        // Local state
        var currentItems = (items || []).slice();
        var filtered     = currentItems.slice();
        var highlighted  = -1;       // index into `filtered`; -1 = nothing highlighted
        var isOpen       = false;

        // ── Rendering ────────────────────────────────────────────────
        function renderList() {
            var html = '';
            var query = (inputEl.value || '').trim();

            // The "empty" / unlink option appears at the top when the user
            // isn't actively typing. Hides during a search so it doesn't
            // clutter the filtered view.
            if (query === '') {
                html += '<li class="list-group-item list-group-item-action searchable-select-item empty-option" data-idx="-1">'
                     + escHtml(emptyLabel)
                     + '</li>';
            }

            if (filtered.length === 0) {
                html += '<li class="list-group-item searchable-select-item no-match">No matches</li>';
            } else {
                var max = Math.min(filtered.length, maxVisible);
                for (var i = 0; i < max; i++) {
                    var label = getLabel(filtered[i]);
                    html += '<li class="list-group-item list-group-item-action searchable-select-item" data-idx="' + i + '">'
                         + escHtml(label)
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
            for (var i = 0; i < nodes.length; i++) {
                if (i === highlighted) {
                    nodes[i].classList.add('active');
                    nodes[i].setAttribute('aria-selected', 'true');
                } else {
                    nodes[i].classList.remove('active');
                    nodes[i].removeAttribute('aria-selected');
                }
            }
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
            renderList();
        }

        function closeList() {
            if (!isOpen) return;
            listEl.classList.add('d-none');
            isOpen = false;
            highlighted = -1;
        }

        // ── Filtering ────────────────────────────────────────────────
        function applyFilter() {
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
            highlighted = filtered.length > 0 ? 0 : -1;
        }

        // ── Commit (select an item) ──────────────────────────────────
        function commitItem(item) {
            if (item == null) {
                inputEl.value  = '';
                hiddenEl.value = '';
            } else {
                inputEl.value  = getLabel(item);
                hiddenEl.value = getValue(item);
            }
            closeList();
            // Fire a 'change' on the hidden input so consumers that observe
            // the form via change events (rare here, but cheap) see the
            // update. Don't fire 'input' to avoid re-filtering loops.
            try { hiddenEl.dispatchEvent(new Event('change', { bubbles: true })); }
            catch (e) { /* IE fallback not needed for NewUI's target browsers */ }
        }

        function commitEmpty() {
            inputEl.value  = '';
            hiddenEl.value = '';
            closeList();
            try { hiddenEl.dispatchEvent(new Event('change', { bubbles: true })); }
            catch (e) {}
        }

        function commitHighlightedOrTyped() {
            if (highlighted >= 0 && filtered[highlighted]) {
                commitItem(filtered[highlighted]);
                return;
            }
            // If typed text uniquely matches one item exactly, commit it.
            var q = (inputEl.value || '').toLowerCase().trim();
            if (q === '') { commitEmpty(); return; }
            if (filtered.length === 1) { commitItem(filtered[0]); return; }
            // No unique match — clear so the form doesn't submit a bad value.
            commitEmpty();
        }

        // ── Event handlers ───────────────────────────────────────────
        function onFocus() {
            applyFilter();
            openList();
        }

        function onInput() {
            applyFilter();
            openList();
            renderList();
        }

        function onKeydown(e) {
            if (!isOpen && e.key !== 'ArrowDown') return;

            if (e.key === 'ArrowDown') {
                e.preventDefault();
                if (!isOpen) { openList(); return; }
                if (highlighted < filtered.length - 1) highlighted++;
                applyHighlight();
                return;
            }
            if (e.key === 'ArrowUp') {
                e.preventDefault();
                if (highlighted > 0) highlighted--;
                applyHighlight();
                return;
            }
            if (e.key === 'Enter') {
                e.preventDefault();
                commitHighlightedOrTyped();
                return;
            }
            if (e.key === 'Escape') {
                if ((inputEl.value || '') !== '') {
                    e.preventDefault();
                    inputEl.value = '';
                    applyFilter();
                    renderList();
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
                applyFilter();
                if (isOpen) renderList();
            },
            setValue: function (val) {
                if (val == null || val === '') {
                    inputEl.value  = '';
                    hiddenEl.value = '';
                    return;
                }
                var str = String(val);
                for (var i = 0; i < currentItems.length; i++) {
                    if (getValue(currentItems[i]) === str) {
                        inputEl.value  = getLabel(currentItems[i]);
                        hiddenEl.value = str;
                        return;
                    }
                }
                // Value not in current items — clear so the form doesn't
                // submit an orphan id.
                inputEl.value  = '';
                hiddenEl.value = '';
            },
            getValue: function () {
                return hiddenEl.value || '';
            },
            destroy: function () {
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
