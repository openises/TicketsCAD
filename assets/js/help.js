(function () {
    'use strict';

    // ── DOM references ──────────────────────────────────────────
    var searchInput   = document.getElementById('helpSearch');
    var searchResults = document.getElementById('helpSearchResults');
    var categories    = document.getElementById('helpCategories');
    var contentArea   = document.getElementById('helpContent');
    var welcomePanel  = document.getElementById('helpWelcome');

    var allHeaders    = document.querySelectorAll('.help-section-header');
    var allTopicLists = document.querySelectorAll('.help-topic-list');
    var allTopicLinks = document.querySelectorAll('.help-topic-link');
    var allPanels     = document.querySelectorAll('.help-topic-panel');

    var activeSlug = null;

    // ── Sidebar accordion ───────────────────────────────────────
    // Start with all sections collapsed except the first
    for (var i = 0; i < allHeaders.length; i++) {
        (function (header, idx) {
            var cat = header.getAttribute('data-category');
            var list = document.querySelector('.help-topic-list[data-category="' + cat + '"]');

            if (idx > 0) {
                header.classList.add('collapsed');
                list.style.display = 'none';
            }

            header.addEventListener('click', function () {
                var isCollapsed = header.classList.contains('collapsed');
                if (isCollapsed) {
                    header.classList.remove('collapsed');
                    list.style.display = '';
                } else {
                    header.classList.add('collapsed');
                    list.style.display = 'none';
                }
            });
        })(allHeaders[i], i);
    }

    // ── Topic link clicks ───────────────────────────────────────
    for (var j = 0; j < allTopicLinks.length; j++) {
        allTopicLinks[j].addEventListener('click', function () {
            var slug = this.getAttribute('data-slug');
            showTopic(slug);
        });
    }

    function showTopic(slug) {
        // Hide welcome
        if (welcomePanel) {
            welcomePanel.classList.add('d-none');
        }

        // Hide all panels
        for (var k = 0; k < allPanels.length; k++) {
            allPanels[k].classList.add('d-none');
        }

        // Show the selected panel
        var panel = document.getElementById('topic-' + slug);
        if (panel) {
            panel.classList.remove('d-none');
        }

        // Update active state in sidebar
        for (var m = 0; m < allTopicLinks.length; m++) {
            allTopicLinks[m].classList.remove('active');
            if (allTopicLinks[m].getAttribute('data-slug') === slug) {
                allTopicLinks[m].classList.add('active');

                // Expand the parent category if collapsed
                var cat = allTopicLinks[m].getAttribute('data-category');
                var header = document.querySelector('.help-section-header[data-category="' + cat + '"]');
                var list = document.querySelector('.help-topic-list[data-category="' + cat + '"]');
                if (header && header.classList.contains('collapsed')) {
                    header.classList.remove('collapsed');
                    list.style.display = '';
                }
            }
        }

        activeSlug = slug;

        // Scroll content to top
        if (contentArea) {
            contentArea.scrollTop = 0;
        }
    }

    // ── Search ──────────────────────────────────────────────────
    var searchTimer = null;

    searchInput.addEventListener('input', function () {
        clearTimeout(searchTimer);
        var query = searchInput.value.trim().toLowerCase();

        if (query.length < 2) {
            searchResults.classList.add('d-none');
            searchResults.innerHTML = '';
            categories.style.display = '';
            return;
        }

        searchTimer = setTimeout(function () {
            doSearch(query);
        }, 150);
    });

    function doSearch(query) {
        var results = [];
        var words = query.split(/\s+/);

        for (var i = 0; i < HELP_TOPICS.length; i++) {
            var topic = HELP_TOPICS[i];
            var searchable = (topic.title + ' ' + topic.text).toLowerCase();
            var matched = true;

            for (var w = 0; w < words.length; w++) {
                if (searchable.indexOf(words[w]) === -1) {
                    matched = false;
                    break;
                }
            }

            if (matched) {
                results.push(topic);
            }
        }

        // Show results
        categories.style.display = 'none';
        searchResults.classList.remove('d-none');
        searchResults.innerHTML = '';

        if (results.length === 0) {
            searchResults.innerHTML = '<p class="text-body-secondary small p-2">No topics found.</p>';
            return;
        }

        for (var r = 0; r < results.length; r++) {
            var btn = document.createElement('button');
            btn.className = 'help-search-result';
            btn.setAttribute('data-slug', results[r].slug);
            btn.innerHTML = results[r].title + '<span class="search-cat">' + getCategoryLabel(results[r].cat) + '</span>';
            btn.addEventListener('click', function () {
                var slug = this.getAttribute('data-slug');
                showTopic(slug);
                // Clear search
                searchInput.value = '';
                searchResults.classList.add('d-none');
                searchResults.innerHTML = '';
                categories.style.display = '';
            });
            searchResults.appendChild(btn);
        }
    }

    function getCategoryLabel(catKey) {
        var labels = {
            'getting-started': 'Getting Started',
            'dispatch': 'Dispatch Operations',
            'maps': 'Maps',
            'communications': 'Communications',
            'personnel': 'Personnel',
            'reports': 'Reports',
            'configuration': 'Configuration',
            'keyboard': 'Keyboard Shortcuts',
            'troubleshooting': 'Troubleshooting'
        };
        return labels[catKey] || catKey;
    }

    // ── Handle hash-based deep linking ──────────────────────────
    function checkHash() {
        var hash = window.location.hash.replace('#', '');
        if (hash) {
            showTopic(hash);
        }
    }

    checkHash();
    window.addEventListener('hashchange', checkHash);

})();
