/**
 * NewUI v4.0 - Personnel Roster
 *
 * ES5 IIFE — no arrow functions, no let/const, no template literals.
 * Manages member list, detail view, edit form, and certifications.
 */
(function () {
    'use strict';

    // ── State ────────────────────────────────────────────────────
    var allMembers     = [];
    var lookupTypes    = [];
    var lookupStatuses = [];
    var lookupTeams    = [];
    var lookupCerts    = [];
    var selectedId     = null;
    // GH #55 follow-on (Billy/K9OH) — bulk roster removal state.
    var canBulkDeleteMembers = false;   // set at init from #canBulkDeleteMembers hidden input
    var bulkSelected      = {};      // { memberId: true } — survives re-renders
    var _rawData       = {};
    var sortField      = 'name';
    var sortDir        = 'asc';
    var filterStatus   = 'all';
    var filterTeam     = 'all';
    var filterType     = 'all';
    var searchTerm     = '';
    var searchTimer    = null;
    var filterIcsPositionId = null;
    var filterIcsLabel      = '';

    // ── DOM refs ─────────────────────────────────────────────────
    var $loading      = document.getElementById('loadingSpinner');
    var $main         = document.getElementById('mainContent');
    var $tbody        = document.getElementById('rosterBody');
    var $noResults    = document.getElementById('noResults');
    var $memberCount  = document.getElementById('memberCount');
    var $searchInput  = document.getElementById('searchInput');
    var $clearSearch  = document.getElementById('btnClearSearch');
    var $alertArea    = document.getElementById('alertArea');

    var $detailEmpty  = document.getElementById('detailEmpty');
    var $detailView   = document.getElementById('detailView');
    var $editView     = document.getElementById('editView');

    // ── Helpers ──────────────────────────────────────────────────
    function esc(str) {
        if (str === null || str === undefined) return '';
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(String(str)));
        return div.innerHTML;
    }

    function csrfToken() {
        var el = document.getElementById('csrfToken');
        return el ? el.value : '';
    }

    function showAlert(msg, type) {
        type = type || 'danger';
        $alertArea.innerHTML = '<div class="alert alert-' + type + ' alert-dismissible fade show py-1 small" role="alert">' +
            esc(msg) +
            '<button type="button" class="btn-close btn-close-sm" data-bs-dismiss="alert"></button></div>';
        if (type === 'success') {
            setTimeout(function () { $alertArea.innerHTML = ''; }, 3000);
        }
    }

    function formatDate(d) {
        if (!d) return '';
        var dt = new Date(d);
        if (isNaN(dt.getTime())) return d;
        return dt.toLocaleDateString();
    }

    function isExpired(dateStr) {
        if (!dateStr) return false;
        var dt = new Date(dateStr);
        return dt < new Date();
    }

    // ── API helpers ──────────────────────────────────────────────
    function apiGet(url, cb) {
        fetch(url, { credentials: 'same-origin' })
            .then(function (res) { return res.json(); })
            .then(function (data) { cb(null, data); })
            .catch(function (err) { cb(err); });
    }

    function apiPost(data, cb) {
        // Every POST to api/members.php is CSRF-protected. saveMember()
        // (further down) was caught manually setting csrf_token via
        // 6887f4f earlier, but this generic helper still wasn't adding
        // it on behalf of its other ~10 callers (add_cert, remove_cert,
        // set_primary_callsign, delete_callsign, save_callsign, action:
        // 'delete', etc.). Beta tester a beta tester 2026-06-26 reported
        // "Failed to add certification: Invalid CSRF token" — same root
        // cause. Adding csrf_token at the helper level fixes every
        // affected POST in one shot. No caller currently sets csrf_token
        // in its own payload, so no double-add risk.
        data.csrf_token = csrfToken();
        fetch('api/members.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        })
        .then(function (res) { return res.json(); })
        .then(function (result) {
            if (result.error) { cb(new Error(result.error)); }
            else { cb(null, result); }
        })
        .catch(function (err) { cb(err); });
    }

    // ── Load Members ─────────────────────────────────────────────
    function loadMembers() {
        var url = 'api/members.php';
        if (filterIcsPositionId) {
            url += '?ics_position_id=' + filterIcsPositionId;
        }
        apiGet(url, function (err, data) {
            if (err) {
                showAlert('Failed to load roster: ' + err.message);
                $loading.classList.add('d-none');
                $main.classList.remove('d-none');
                return;
            }
            allMembers     = data.members || [];
            lookupTypes    = data.types || [];
            lookupStatuses = data.statuses || [];
            lookupTeams    = data.teams || [];
            lookupCerts    = data.certifications || [];

            // If ICS position filter is active, show banner
            if (data.ics_filter) {
                filterIcsLabel = data.ics_filter.code + ' — ' + data.ics_filter.title;
                showIcsFilterBanner();
            }

            buildFilterButtons();
            populateFormDropdowns();
            renderTable();

            $loading.classList.add('d-none');
            $main.classList.remove('d-none');
        });
    }

    function showIcsFilterBanner() {
        var existing = document.getElementById('icsFilterBanner');
        if (existing) existing.remove();

        var banner = document.createElement('div');
        banner.id = 'icsFilterBanner';
        banner.className = 'alert alert-info alert-dismissible fade show d-flex align-items-center py-2 mb-2';
        banner.innerHTML = '<i class="bi bi-funnel-fill me-2"></i>' +
            '<span>Showing members qualified for <strong>' + esc(filterIcsLabel) + '</strong></span>' +
            '<a href="roster.php" class="btn btn-sm btn-outline-info ms-3">Show All Members</a>' +
            '<button type="button" class="btn-close" aria-label="Clear filter"></button>';

        banner.querySelector('.btn-close').addEventListener('click', function () {
            filterIcsPositionId = null;
            filterIcsLabel = '';
            banner.remove();
            // Update URL without reload
            if (window.history && window.history.replaceState) {
                window.history.replaceState({}, '', 'roster.php');
            }
            loadMembers();
        });

        // Insert before the main content area
        var filterRow = document.querySelector('.card-body .row');
        if (filterRow) {
            filterRow.parentNode.insertBefore(banner, filterRow);
        } else {
            $main.insertBefore(banner, $main.firstChild);
        }
    }

    // ── Filter Buttons ───────────────────────────────────────────
    function buildFilterButtons() {
        var html, i;

        // Status filters
        html = '';
        for (i = 0; i < lookupStatuses.length; i++) {
            var s = lookupStatuses[i];
            html += '<button type="button" class="btn btn-sm btn-outline-secondary filter-btn" ' +
                    'data-filter="status" data-value="' + esc(s.id) + '">' +
                    esc(s.name) + '</button>';
        }
        document.getElementById('statusFilters').innerHTML = html;

        // Team filters
        html = '';
        for (i = 0; i < lookupTeams.length; i++) {
            var t = lookupTeams[i];
            html += '<button type="button" class="btn btn-sm btn-outline-secondary filter-btn" ' +
                    'data-filter="team" data-value="' + esc(t.id) + '">' +
                    esc(t.name) + '</button>';
        }
        document.getElementById('teamFilters').innerHTML = html;

        // Type filters
        html = '';
        for (i = 0; i < lookupTypes.length; i++) {
            var tp = lookupTypes[i];
            html += '<button type="button" class="btn btn-sm btn-outline-secondary filter-btn" ' +
                    'data-filter="type" data-value="' + esc(tp.id) + '">' +
                    esc(tp.name) + '</button>';
        }
        document.getElementById('typeFilters').innerHTML = html;
    }

    // ── Populate Form Dropdowns ──────────────────────────────────
    function populateFormDropdowns() {
        var i, html;

        // Type
        html = '<option value="">-- None --</option>';
        for (i = 0; i < lookupTypes.length; i++) {
            html += '<option value="' + lookupTypes[i].id + '">' + esc(lookupTypes[i].name) + '</option>';
        }
        document.getElementById('editType').innerHTML = html;

        // Status
        html = '<option value="">-- None --</option>';
        for (i = 0; i < lookupStatuses.length; i++) {
            html += '<option value="' + lookupStatuses[i].id + '">' + esc(lookupStatuses[i].name) + '</option>';
        }
        document.getElementById('editStatus').innerHTML = html;

        // Team
        html = '<option value="">-- None --</option>';
        for (i = 0; i < lookupTeams.length; i++) {
            html += '<option value="' + lookupTeams[i].id + '">' + esc(lookupTeams[i].name) + '</option>';
        }
        document.getElementById('editTeam').innerHTML = html;
    }

    // ── Filter & Sort ────────────────────────────────────────────
    function getFilteredMembers() {
        var result = [];
        var term = searchTerm.toLowerCase();

        for (var i = 0; i < allMembers.length; i++) {
            var m = allMembers[i];

            // Status filter
            if (filterStatus !== 'all' && String(m.member_status_id) !== String(filterStatus)) continue;

            // Team filter — check both primary team_id and junction table team_ids
            if (filterTeam !== 'all') {
                var inTeam = String(m.team_id) === String(filterTeam);
                if (!inTeam && m.team_ids && m.team_ids.length > 0) {
                    for (var ti = 0; ti < m.team_ids.length; ti++) {
                        if (String(m.team_ids[ti]) === String(filterTeam)) { inTeam = true; break; }
                    }
                }
                if (!inTeam) continue;
            }

            // Type filter
            if (filterType !== 'all' && String(m.member_type_id) !== String(filterType)) continue;

            // Search filter
            if (term) {
                var haystack = (
                    (m.first_name || '') + ' ' +
                    (m.last_name || '') + ' ' +
                    (m.callsign || '') + ' ' +
                    (m.phone_cell || '') + ' ' +
                    (m.email || '')
                ).toLowerCase();
                if (haystack.indexOf(term) === -1) continue;
            }

            result.push(m);
        }

        // Sort
        result.sort(function (a, b) {
            var va, vb;
            switch (sortField) {
                case 'name':
                    va = ((a.last_name || '') + ' ' + (a.first_name || '')).toLowerCase();
                    vb = ((b.last_name || '') + ' ' + (b.first_name || '')).toLowerCase();
                    break;
                case 'callsign':
                    va = (a.callsign || '').toLowerCase();
                    vb = (b.callsign || '').toLowerCase();
                    break;
                case 'type':
                    va = (a.type_name || '').toLowerCase();
                    vb = (b.type_name || '').toLowerCase();
                    break;
                case 'status':
                    va = (a.status_name || '').toLowerCase();
                    vb = (b.status_name || '').toLowerCase();
                    break;
                case 'team':
                    va = (a.team_name || '').toLowerCase();
                    vb = (b.team_name || '').toLowerCase();
                    break;
                case 'available':
                    va = a.available || '';
                    vb = b.available || '';
                    break;
                default:
                    va = '';
                    vb = '';
            }
            if (va < vb) return sortDir === 'asc' ? -1 : 1;
            if (va > vb) return sortDir === 'asc' ? 1 : -1;
            return 0;
        });

        return result;
    }

    // ── Render Table ─────────────────────────────────────────────
    function renderTable() {
        var members = getFilteredMembers();
        $memberCount.textContent = members.length;

        // Bulk-delete safety (2026-07-05) — prune the selection to only the
        // members currently visible under the active search/filter. Filtering
        // is client-side, so without this a member selected under one filter
        // could stay silently selected (no visible checkbox) and still be
        // swept up by "Delete selected" — an invisible data-loss foot-gun.
        // Keeping selection == visible removes that risk.
        if (canBulkDeleteMembers) {
            var _visibleIds = {};
            for (var _vi = 0; _vi < members.length; _vi++) { _visibleIds[members[_vi].id] = true; }
            for (var _sk in bulkSelected) {
                if (bulkSelected.hasOwnProperty(_sk) && !_visibleIds[_sk]) { delete bulkSelected[_sk]; }
            }
        }

        // Dynamically add/remove ICS qualification columns in the header
        var thead = document.querySelector('#rosterTable thead tr');
        var existingQualTh = document.getElementById('thQualLevel');
        var existingPtbTh = document.getElementById('thPtbStatus');
        if (filterIcsPositionId) {
            if (!existingQualTh) {
                var qualTh = document.createElement('th');
                qualTh.id = 'thQualLevel';
                qualTh.textContent = 'Qual Level';
                var ptbTh = document.createElement('th');
                ptbTh.id = 'thPtbStatus';
                ptbTh.textContent = 'PTB Status';
                // Insert before the last column (Avail)
                var availTh = thead.lastElementChild;
                thead.insertBefore(qualTh, availTh);
                thead.insertBefore(ptbTh, availTh);
            }
        } else {
            if (existingQualTh) existingQualTh.remove();
            if (existingPtbTh) existingPtbTh.remove();
        }

        if (members.length === 0) {
            $tbody.innerHTML = '';
            $noResults.classList.remove('d-none');
            return;
        }
        $noResults.classList.add('d-none');

        var qualLevelColors = {
            'Trainee': 'warning', 'Qualified': 'success', 'Expert': 'primary'
        };
        var ptbColors = {
            'Not Started': 'secondary', 'In Progress': 'info', 'Completed': 'success'
        };

        var html = '';
        for (var i = 0; i < members.length; i++) {
            var m = members[i];
            var isSelected = (selectedId && String(m.id) === String(selectedId));
            var typeColor  = m.type_color || '#6c757d';
            var statusColor = m.status_color || '#6c757d';
            // PRE-RELEASE-FIXES #7 — use text_color when supplied so badges
            // remain readable on light/dark backgrounds.
            var typeTextColor   = m.type_text_color   || '#ffffff';
            var statusTextColor = m.status_text_color || '#ffffff';
            var availIcon  = (m.available === 'Yes')
                ? '<i class="bi bi-check-circle-fill text-success"></i>'
                : '<i class="bi bi-x-circle-fill text-danger"></i>';

            var icsCols = '';
            if (filterIcsPositionId) {
                var qlColor = qualLevelColors[m.qualification_level] || 'secondary';
                var ptbColor = ptbColors[m.ptb_status] || 'secondary';
                icsCols = '<td><span class="badge bg-' + qlColor + '">' + esc(m.qualification_level || 'N/A') + '</span></td>' +
                          '<td><span class="badge bg-' + ptbColor + '">' + esc(m.ptb_status || 'N/A') + '</span></td>';
            }

            // PRE-RELEASE-FIXES #19 — photo avatar (24×24) when present
            var avatarHtml = m.photo_file_id
                ? '<img src="api/upload.php?file_id=' + encodeURIComponent(m.photo_file_id)
                  + '" class="roster-avatar me-1" alt="" '
                  + 'style="width:24px;height:24px;border-radius:50%;object-fit:cover;">'
                : '';

            // Render every team the member belongs to (the list endpoint
            // populates m.team_names as an array via the team_members
            // junction; fall back to the legacy single team_name field).
            var teamHtml = '';
            if (m.team_names && m.team_names.length) {
                for (var ti = 0; ti < m.team_names.length; ti++) {
                    teamHtml += '<span class="badge bg-secondary me-1">' + esc(m.team_names[ti]) + '</span>';
                }
            } else if (m.team_name) {
                teamHtml = esc(m.team_name);
            }

            html += '<tr class="roster-row' + (isSelected ? ' selected' : '') + '" data-id="' + m.id + '">' +
                '<td class="fw-semibold">' + avatarHtml + esc(m.last_name) + ', ' + esc(m.first_name) + '</td>' +
                '<td>' + esc(m.callsign || '') + '</td>' +
                '<td><span class="badge" style="background-color:' + esc(typeColor) + ';color:' + esc(typeTextColor) + ';">' + esc(m.type_name || 'N/A') + '</span></td>' +
                '<td><span class="badge" style="background-color:' + esc(statusColor) + ';color:' + esc(statusTextColor) + ';">' + esc(m.status_name || 'N/A') + '</span></td>' +
                '<td>' + teamHtml + '</td>' +
                '<td>' + esc(m.phone_cell || '') + '</td>' +
                icsCols +
                '<td class="text-center">' + availIcon + '</td>' +
                (canBulkDeleteMembers
                    ? '<td class="roster-sel-col text-center"><input type="checkbox" class="form-check-input roster-sel-cb" data-id="' + m.id + '"' + (bulkSelected[m.id] ? ' checked' : '') + ' aria-label="Select member"></td>'
                    : '') +
                '</tr>';
        }
        $tbody.innerHTML = html;
        if (canBulkDeleteMembers) { updateBulkBar(); }

        // Update sort icons
        var headers = document.querySelectorAll('#rosterTable th.sortable');
        for (var j = 0; j < headers.length; j++) {
            var th = headers[j];
            var icon = th.querySelector('.sort-icon');
            if (th.getAttribute('data-sort') === sortField) {
                icon.className = 'bi sort-icon ' + (sortDir === 'asc' ? 'bi-arrow-up' : 'bi-arrow-down');
            } else {
                icon.className = 'bi bi-arrow-down-up sort-icon';
            }
        }
    }

    // ── Select Member ────────────────────────────────────────────
    function selectMember(id) {
        selectedId = id;
        renderTable(); // Update selection highlight

        apiGet('api/members.php?id=' + id, function (err, data) {
            if (err || !data.member) {
                showAlert('Failed to load member details.');
                return;
            }
            _rawData = data;
            renderDetail(data.member, data.certifications || [],
                data.ics_qualifications || [], data.all_ics_positions || [],
                data.team_memberships || [], data.training_records || [],
                data.training_hours || 0, data.organizations || [],
                data.all_organizations || [], data.comm_identifiers || [],
                data.all_comm_modes || [], data.callsigns || []);
            renderLinkedUser(data.linked_user || null);
        });
    }

    // ── Render Detail ────────────────────────────────────────────
    function renderDetail(member, certs, icsQuals, allIcsPositions, teamMemberships, trainingRecords, trainingHours, orgMemberships, allOrgs, commIdentifiers, allCommModes, callsigns) {
        $detailEmpty.classList.add('d-none');
        $editView.classList.add('d-none');
        $detailView.classList.remove('d-none');

        // Header
        var fullName = esc(member.first_name) + ' ' +
                       (member.middle_name ? esc(member.middle_name) + ' ' : '') +
                       esc(member.last_name);
        document.getElementById('detailName').textContent = fullName;
        document.getElementById('detailTitle').textContent = member.title || member.callsign || '';

        // Badges. Followup to b759ce3 / 1f559df (member_types and
        // member_status color-semantic alignment): after those fixes the
        // bg comes from the canonical `background` column. The detail
        // panel previously only set background-color and let text color
        // fall through to whatever the Bootstrap .badge default was
        // (white), which worked under the legacy interpretation when bg
        // was always a saturated color, but breaks under the new
        // convention where bg can legitimately be white (e.g. a beta tester
        // Gilbert 2026-06-26: 'Dispatcher' type with white bg / black
        // text rendered as invisible white-on-white in the detail
        // panel). Setting both bg and text from the API-provided
        // *_text_color fields makes the detail badge consistent with
        // the list-table badge at line 405.
        var badgeHtml = '';
        if (member.type_name) {
            badgeHtml += '<span class="badge" style="background-color:' + esc(member.type_color || '#6c757d') +
                         ';color:' + esc(member.type_text_color || '#ffffff') + ';">' +
                         esc(member.type_name) + '</span>';
        }
        if (member.status_name) {
            badgeHtml += '<span class="badge" style="background-color:' + esc(member.status_color || '#6c757d') +
                         ';color:' + esc(member.status_text_color || '#ffffff') + ';">' +
                         esc(member.status_name) + '</span>';
        }
        if (member.team_name) {
            badgeHtml += '<span class="badge bg-secondary">' + esc(member.team_name) + '</span>';
        }
        badgeHtml += '<span class="badge ' + (member.available === 'Yes' ? 'bg-success' : 'bg-danger') + '">' +
                     (member.available === 'Yes' ? 'Available' : 'Unavailable') + '</span>';
        document.getElementById('detailBadges').innerHTML = badgeHtml;

        // Contact
        document.getElementById('detailContact').innerHTML =
            '<div class="row g-2">' +
            '<div class="col-md-12"><div class="text-body-secondary">Email</div><div>' +
                (member.email ? '<a href="mailto:' + esc(member.email) + '">' + esc(member.email) + '</a>' : '—') + '</div></div>' +
            '<div class="col-md-4"><div class="text-body-secondary">Cell</div><div>' + esc(member.phone_cell || '—') + '</div></div>' +
            '<div class="col-md-4"><div class="text-body-secondary">Home</div><div>' + esc(member.phone_home || '—') + '</div></div>' +
            '<div class="col-md-4"><div class="text-body-secondary">Work</div><div>' + esc(member.phone_work || '—') + '</div></div>' +
            '</div>';

        // Address
        var addrParts = [];
        if (member.street) addrParts.push(esc(member.street));
        var cityLine = '';
        if (member.city) cityLine += esc(member.city);
        if (member.state) cityLine += (cityLine ? ', ' : '') + esc(member.state);
        if (member.zip) cityLine += ' ' + esc(member.zip);
        if (cityLine) addrParts.push(cityLine);
        document.getElementById('detailAddress').innerHTML =
            '<div>' + (addrParts.length ? addrParts.join('<br>') : '—') + '</div>';

        // Communications Licenses
        var licContainer = document.getElementById('detailLicenses');
        var licCount = document.getElementById('licenseCount');
        if (licContainer) {
            if (licCount) licCount.textContent = (callsigns || []).length;
            if (callsigns && callsigns.length > 0) {
                var licHtml = '<table class="table table-sm table-borderless mb-0"><thead>' +
                    '<tr><th></th><th>Callsign</th><th>Type</th><th>Class</th><th>Issued</th><th>Expires</th></tr></thead><tbody>';
                for (var ci = 0; ci < callsigns.length; ci++) {
                    var csd = callsigns[ci];
                    var cType = (csd.license_type || '').charAt(0).toUpperCase() + (csd.license_type || '').slice(1);
                    var isPri = (csd.is_primary === '1' || csd.is_primary === 1);
                    var starIcon = isPri ? '<i class="bi bi-star-fill text-warning"></i>' : '<i class="bi bi-star text-body-secondary"></i>';
                    // Expiry coloring
                    var expCls = '';
                    if (csd.expiry_date) {
                        var dLeft = Math.round((new Date(csd.expiry_date) - new Date()) / 86400000);
                        if (dLeft < 0) expCls = ' class="text-danger fw-bold"';
                        else if (dLeft < 90) expCls = ' class="text-warning"';
                    }
                    licHtml += '<tr><td>' + starIcon + '</td>' +
                        '<td><code class="fw-bold">' + esc(csd.callsign) + '</code></td>' +
                        '<td>' + esc(cType) + '</td>' +
                        '<td>' + esc(csd.oper_class || '—') + '</td>' +
                        '<td>' + (formatDate(csd.grant_date) || '—') + '</td>' +
                        '<td' + expCls + '>' + (formatDate(csd.expiry_date) || '—') + '</td>' +
                        '</tr>';
                }
                licHtml += '</tbody></table>';
                licContainer.innerHTML = licHtml;
            } else {
                licContainer.innerHTML = '<div class="text-body-secondary">No communications licenses on file.</div>';
            }
        }

        // Emergency Contact
        document.getElementById('detailEmergency').innerHTML =
            '<div class="row g-2">' +
            '<div class="col-md-5"><div class="text-body-secondary">Name</div><div>' + esc(member.emergency_contact || '—') + '</div></div>' +
            '<div class="col-md-4"><div class="text-body-secondary">Phone</div><div>' + esc(member.emergency_phone || '—') + '</div></div>' +
            '<div class="col-md-3"><div class="text-body-secondary">Relation</div><div>' + esc(member.emergency_relation || '—') + '</div></div>' +
            '</div>';

        // Medical & Notes
        document.getElementById('detailMedical').innerHTML =
            '<div class="mb-2"><div class="text-body-secondary">Medical Info</div>' +
            '<div style="white-space:pre-wrap;">' + esc(member.medical_info || '—') + '</div></div>' +
            '<div><div class="text-body-secondary">Notes</div>' +
            '<div style="white-space:pre-wrap;">' + esc(member.notes || '—') + '</div></div>';

        // Certifications
        renderCertifications(certs, member.id);

        // ICS Qualifications
        renderIcsQualifications(icsQuals || [], allIcsPositions || [], member.id);

        // Team Memberships
        renderTeamMemberships(teamMemberships || []);

        // Organization Memberships
        renderOrgMemberships(orgMemberships || [], allOrgs || [], member.id, _rawData.all_member_types || []);

        // Communication / Location Identifiers
        renderCommIdentifiers(commIdentifiers || [], allCommModes || [], member.id);

        // Phase 41 — OwnTracks tracking tokens for this member.
        // GH #84 — render via the shared OwnTracks provisioning module so the
        // roster and the unit-edit page use one implementation. Falls back to the
        // roster-local copy below if the shared module didn't load.
        if (window.OTProvision) {
            OTProvision.mount(document.getElementById('detailOtTokens'), member.id, document.getElementById('otTokenCount'));
        } else {
            loadOtTokens(member.id);
        }
        // Phase 51 — reset the per-member overrides card (lazy-loaded on
        // first expand to avoid an extra round-trip per member detail view)
        _otOverridesLoaded = false;
        var ovBody = document.getElementById('detailOtOverrides');
        if (ovBody) ovBody.innerHTML = '<div class="text-body-secondary fst-italic">Open to load this member\'s OwnTracks overrides.</div>';
        var ovBadge = document.getElementById('otOverridesBadge');
        if (ovBadge) { ovBadge.textContent = 'inherit'; ovBadge.className = 'badge bg-secondary ms-auto'; }
        _otCurrentMemberId = member.id;

        // Store callsigns data for edit form access
        _memberCallsigns = callsigns || [];

        // Training Records
        renderTrainingRecords(trainingRecords || [], trainingHours || 0, member.id);

        // Time Log (item #21) — fire & forget loader
        loadTimeLog(member.id);

        // Load and render vehicles for this member
        loadMemberVehicles(member.id);

        // Load and render equipment for this member
        loadMemberEquipment(member.id);

        // Store member on edit button
        document.getElementById('btnEditMember').setAttribute('data-member', JSON.stringify(member));
        document.getElementById('btnDeleteMember').setAttribute('data-id', member.id);
    }

    // ── Render Linked User Account Badge ────────────────────────
    function renderLinkedUser(linkedUser) {
        var container = document.getElementById('detailLinkedUser');
        if (!container) return;

        if (linkedUser && linkedUser.user) {
            var levelLabels = {
                '0': 'Super', '1': 'Admin', '2': 'Operator', '3': 'Guest',
                '4': 'Member', '5': 'Unit', '6': 'Statistics', '7': 'Service', '8': 'Facility'
            };
            var levelText = levelLabels[String(linkedUser.level)] || 'Level ' + linkedUser.level;
            container.innerHTML =
                '<div class="d-flex align-items-center gap-2 py-1">' +
                '<i class="bi bi-person-check-fill text-success"></i>' +
                '<span class="small fw-semibold">CAD Login</span>' +
                '<span class="badge bg-success">' + esc(linkedUser.user) + '</span>' +
                '<span class="badge bg-secondary" style="font-size:0.6rem;">' + esc(levelText) + '</span>' +
                '</div>';
            container.classList.remove('d-none');
        } else {
            container.innerHTML =
                '<div class="d-flex align-items-center gap-2 py-1 text-body-secondary">' +
                '<i class="bi bi-person-x"></i>' +
                '<span class="small">No CAD login linked</span>' +
                '</div>';
            container.classList.remove('d-none');
        }
    }

    // ── Render Certifications ────────────────────────────────────
    function renderCertifications(certs, memberId) {
        var html = '';
        document.getElementById('certCount').textContent = certs.length;

        if (certs.length > 0) {
            var catColors = { 'FEMA IS': 'info', 'CPR/Medical': 'danger', 'Radio': 'primary',
                              'HAZMAT': 'warning', 'Driving': 'secondary', 'Emergency Mgmt': 'success',
                              'Weather': 'primary' };
            html += '<table class="table table-sm table-borderless mb-2">' +
                    '<thead><tr><th>Certification</th><th>Category</th><th>Earned</th><th>Expires</th><th></th></tr></thead><tbody>';
            for (var i = 0; i < certs.length; i++) {
                var c = certs[i];
                var expired = isExpired(c.expiry_date);
                var catBadge = c.cert_category
                    ? '<span class="badge bg-' + (catColors[c.cert_category] || 'secondary') + ' bg-opacity-75" style="font-size:0.65rem;">' + esc(c.cert_category) + '</span>'
                    : '';
                var certLabel = esc(c.cert_name);
                if (c.fema_course_code) certLabel += ' <span class="text-body-secondary" style="font-size:0.7rem;">(' + esc(c.fema_course_code) + ')</span>';
                if (c.required === '1' || c.required === 1) certLabel += ' <span class="text-danger">*</span>';
                if (c.certificate_number) certLabel += '<br><span class="text-body-secondary" style="font-size:0.7rem;">#' + esc(c.certificate_number) + '</span>';
                html += '<tr' + (expired ? ' class="cert-expired"' : '') + '>' +
                    '<td>' + certLabel + '</td>' +
                    '<td>' + catBadge + '</td>' +
                    '<td>' + formatDate(c.earned_date) + '</td>' +
                    '<td>' + (c.expiry_date ? formatDate(c.expiry_date) + (expired ? ' <i class="bi bi-exclamation-triangle-fill text-danger"></i>' : '') : '—') + '</td>' +
                    '<td><button type="button" class="btn btn-xs btn-outline-danger remove-cert-btn" data-cert-row-id="' + c.id + '" title="Remove"><i class="bi bi-x-lg"></i></button></td>' +
                    '</tr>';
            }
            html += '</tbody></table>';
        } else {
            html += '<div class="text-body-secondary mb-2">No certifications on file.</div>';
        }

        // Add cert form — freeform typeahead against `certifications`
        // with live search that surfaces both "in use by other members"
        // and "available" matches, plus an "Add new: X" escape hatch
        // that auto-creates a certifications row on save.
        html += '<div class="border-top pt-2">' +
                '<div class="row g-1 align-items-end">' +
                '<div class="col-5 position-relative">' +
                '<label class="form-label form-label-sm mb-0">Training / Certification</label>' +
                '<input type="text" class="form-control form-control-sm" id="addCertName" ' +
                       'placeholder="Type to search or add new..." autocomplete="off">' +
                '<input type="hidden" id="addCertId" value="">' +
                '<div id="addCertSuggest" class="dropdown-menu w-100 shadow-sm" ' +
                     'style="max-height:280px;overflow-y:auto;display:none;"></div>' +
                '</div>' +
                '<div class="col-3">' +
                '<label class="form-label form-label-sm mb-0">Earned</label>' +
                '<input type="date" class="form-control form-control-sm" id="addCertEarned"></div>' +
                '<div class="col-3">' +
                '<label class="form-label form-label-sm mb-0">Expires</label>' +
                '<input type="date" class="form-control form-control-sm" id="addCertExpiry"></div>' +
                '<div class="col-1">' +
                '<button type="button" class="btn btn-sm btn-primary w-100" id="btnAddCert" title="Add">' +
                '<i class="bi bi-plus-lg"></i></button></div>' +
                '</div></div>';

        document.getElementById('detailCerts').innerHTML = html;

        // Wire the typeahead (shared with Training Records)
        _wireTrainingTypeahead({
            input:    document.getElementById('addCertName'),
            suggest:  document.getElementById('addCertSuggest'),
            idHidden: document.getElementById('addCertId'),
            allowNew: true
        });

        // Bind add cert button
        var addBtn = document.getElementById('btnAddCert');
        if (addBtn) {
            addBtn.addEventListener('click', function () {
                addCertification(memberId);
            });
        }

        // Bind remove cert buttons
        var removeBtns = document.querySelectorAll('.remove-cert-btn');
        for (var k = 0; k < removeBtns.length; k++) {
            (function (btn) {
                btn.addEventListener('click', function () {
                    removeCertification(parseInt(btn.getAttribute('data-cert-row-id'), 10));
                });
            })(removeBtns[k]);
        }
    }

    // ── Add Certification ────────────────────────────────────────
    // Sends certification_id if a suggestion was picked, or
    // certification_name if the user typed a freeform value — backend
    // auto-creates the certifications row on the fly in that case.
    function addCertification(memberId) {
        var nameInput   = document.getElementById('addCertName');
        var idHidden    = document.getElementById('addCertId');
        var earnedInput = document.getElementById('addCertEarned');
        var expiryInput = document.getElementById('addCertExpiry');

        var typed  = nameInput ? nameInput.value.trim() : '';
        var certId = idHidden && idHidden.value ? parseInt(idHidden.value, 10) : 0;

        if (!typed && !certId) {
            showAlert('Please type or select a certification.');
            return;
        }

        // If the user typed AFTER picking a suggestion, the hidden id
        // may be stale — clear it so we send the current name string.
        if (certId && nameInput && nameInput.dataset.chosenName &&
            nameInput.dataset.chosenName !== typed) {
            certId = 0;
        }

        var payload = {
            action:       'add_cert',
            member_id:    memberId,
            earned_date:  earnedInput ? earnedInput.value : null,
            expiry_date:  expiryInput ? expiryInput.value : null
        };
        if (certId)     payload.certification_id   = certId;
        else            payload.certification_name = typed;

        apiPost(payload, function (err) {
            if (err) {
                showAlert('Failed to add certification: ' + err.message);
                return;
            }
            showAlert('Certification added.', 'success');
            selectMember(memberId); // Reload detail
        });
    }

    // ── Training / certification typeahead ───────────────────────
    // Reusable across the Training Records add form (roster) and the
    // Certifications add form (roster). Debounces to ~180ms; supports
    // keyboard nav (↑ / ↓ / Enter / Escape); shows an "Add as new: X"
    // escape hatch when the query doesn't exactly match a suggestion.
    //
    // opts:
    //   input      — the visible text input element (required)
    //   suggest    — the floating <div> that hosts suggestions (required)
    //   idHidden   — a hidden <input> to store the picked catalog id
    //                (optional — used by callers who need FK-mode)
    //   allowNew   — false to hide "Add as new: X" (default true)
    function _wireTrainingTypeahead(opts) {
        var input   = opts.input;
        var suggest = opts.suggest;
        var idHid   = opts.idHidden || null;
        var allowNew = opts.allowNew !== false;
        if (!input || !suggest) return;

        var timer = null;
        var focusedIndex = -1;

        function hide() {
            suggest.style.display = 'none';
            suggest.innerHTML = '';
            focusedIndex = -1;
        }
        function show() { suggest.style.display = 'block'; }

        function itemsInDom() {
            return suggest.querySelectorAll('.tt-pick, .tt-new');
        }
        function focusItem(i) {
            var items = itemsInDom();
            if (!items.length) { focusedIndex = -1; return; }
            if (i < 0) i = items.length - 1;
            if (i >= items.length) i = 0;
            for (var k = 0; k < items.length; k++) items[k].classList.remove('active');
            items[i].classList.add('active');
            items[i].scrollIntoView({ block: 'nearest' });
            focusedIndex = i;
        }
        function selectCurrent() {
            var items = itemsInDom();
            if (focusedIndex < 0 || focusedIndex >= items.length) return false;
            items[focusedIndex].dispatchEvent(new Event('mousedown'));
            return true;
        }

        function renderSuggestions(data) {
            var q = (data.query || '').trim();
            var html = '';

            function catalogRow(item) {
                var subtitle = '';
                if (item.fema_course_code) subtitle += esc(item.fema_course_code);
                if (item.category) subtitle += (subtitle ? ' · ' : '') + esc(item.category);
                var count = parseInt(item.member_count || 0, 10);
                var countHtml = count > 0
                    ? '<span class="badge bg-secondary bg-opacity-75 ms-1" style="font-size:0.65rem;">' + count + '</span>'
                    : '';
                return '<button type="button" class="dropdown-item d-flex flex-column align-items-start py-1 tt-pick" ' +
                       'data-id="' + item.id + '" data-name="' + esc(item.name) + '">' +
                       '<span class="small">' + esc(item.name) + countHtml + '</span>' +
                       (subtitle ? '<span class="text-body-secondary" style="font-size:0.7rem;">' + subtitle + '</span>' : '') +
                       '</button>';
            }
            function loggedRow(item) {
                var count = parseInt(item.member_count || 0, 10);
                var countHtml = count > 0
                    ? '<span class="badge bg-info bg-opacity-75 ms-1" style="font-size:0.65rem;">' + count + '</span>'
                    : '';
                return '<button type="button" class="dropdown-item d-flex flex-column align-items-start py-1 tt-pick" ' +
                       'data-id="" data-name="' + esc(item.name) + '">' +
                       '<span class="small">' + esc(item.name) + countHtml + '</span>' +
                       '<span class="text-body-secondary" style="font-size:0.7rem;">logged by other members</span>' +
                       '</button>';
            }

            // ── Catalog: certifications table (has member_count) ────
            var catalog = data.catalog || data.in_use || [];   // back-compat
            if (data.available && !data.catalog) {
                // legacy shape from earlier deploy — merge in_use + available
                catalog = catalog.concat(data.available);
            }
            if (catalog.length) {
                html += '<h6 class="dropdown-header py-1" style="font-size:0.7rem;">From training catalog</h6>';
                for (var i = 0; i < catalog.length; i++) html += catalogRow(catalog[i]);
            }
            // ── Logged: distinct training_records.training_name ─────
            var logged = data.logged || [];
            if (logged.length) {
                if (html) html += '<div class="dropdown-divider my-1"></div>';
                html += '<h6 class="dropdown-header py-1" style="font-size:0.7rem;">Used by other members (not in catalog)</h6>';
                for (var j = 0; j < logged.length; j++) html += loggedRow(logged[j]);
            }

            // Exact-match test (case-insensitive) across both buckets.
            var exact = false;
            var qLow = q.toLowerCase();
            for (var e = 0; e < catalog.length; e++)
                if (catalog[e].name && catalog[e].name.toLowerCase() === qLow) { exact = true; break; }
            if (!exact) {
                for (var g = 0; g < logged.length; g++)
                    if (logged[g].name && logged[g].name.toLowerCase() === qLow) { exact = true; break; }
            }

            if (allowNew && q !== '' && !exact) {
                if (html) html += '<div class="dropdown-divider my-1"></div>';
                html += '<button type="button" class="dropdown-item py-1 tt-new" data-name="' + esc(q) + '">' +
                        '<i class="bi bi-plus-lg text-success me-1"></i>' +
                        '<span class="small">Add as new: <strong>' + esc(q) + '</strong></span>' +
                        '</button>';
            }
            if (!html) {
                html = '<div class="dropdown-item-text text-body-secondary small py-1">' +
                       (q === '' ? 'Type to search training and certifications.' :
                                   (allowNew ? 'No matches. Press Enter to add a new entry.' : 'No matches.')) +
                       '</div>';
            }
            suggest.innerHTML = html;
            focusedIndex = -1;

            // Pick handlers — mousedown fires before blur so the pick
            // registers even though blur will fire a hide().
            var picks = suggest.querySelectorAll('.tt-pick');
            for (var p = 0; p < picks.length; p++) {
                (function (btn) {
                    btn.addEventListener('mousedown', function (ev) {
                        ev.preventDefault();
                        input.value = btn.getAttribute('data-name');
                        input.dataset.chosenName = input.value;
                        if (idHid) idHid.value = btn.getAttribute('data-id') || '';
                        hide();
                    });
                })(picks[p]);
            }
            var news = suggest.querySelectorAll('.tt-new');
            for (var n = 0; n < news.length; n++) {
                (function (btn) {
                    btn.addEventListener('mousedown', function (ev) {
                        ev.preventDefault();
                        input.value = btn.getAttribute('data-name');
                        input.dataset.chosenName = '';   // freeform
                        if (idHid) idHid.value = '';
                        hide();
                    });
                })(news[n]);
            }
            show();
        }

        function search() {
            apiPost({ action: 'training_search', q: input.value.trim() }, function (err, data) {
                if (err) { hide(); return; }
                renderSuggestions(data || {});
            });
        }

        input.addEventListener('input', function () {
            if (idHid && idHid.value && input.dataset.chosenName !== input.value) {
                idHid.value = '';
                input.dataset.chosenName = '';
            }
            if (timer) clearTimeout(timer);
            timer = setTimeout(search, 180);
        });
        input.addEventListener('focus', function () { search(); });
        input.addEventListener('blur', function () { setTimeout(hide, 200); });
        input.addEventListener('keydown', function (ev) {
            if (suggest.style.display !== 'block') {
                if (ev.key === 'ArrowDown') { search(); ev.preventDefault(); }
                return;
            }
            if (ev.key === 'ArrowDown') { focusItem(focusedIndex + 1); ev.preventDefault(); }
            else if (ev.key === 'ArrowUp')   { focusItem(focusedIndex - 1); ev.preventDefault(); }
            else if (ev.key === 'Enter')     {
                if (selectCurrent()) ev.preventDefault();
                // else: fall through so the form's Add button owns Enter
            }
            else if (ev.key === 'Escape')    { hide(); }
        });
    }

    // Back-compat shim for the earlier _wireCertTypeahead entry point
    // (retained so callers that still reference the old name keep
    // working during rollout — safe to remove once nothing does).
    function _wireCertTypeahead() {
        _wireTrainingTypeahead({
            input:    document.getElementById('addCertName'),
            suggest:  document.getElementById('addCertSuggest'),
            idHidden: document.getElementById('addCertId'),
            allowNew: true
        });
    }

    // ── Remove Certification ─────────────────────────────────────
    function removeCertification(id) {
        if (!confirm('Remove this certification?')) return;

        apiPost({ action: 'remove_cert', id: id }, function (err) {
            if (err) {
                showAlert('Failed to remove certification: ' + err.message);
                return;
            }
            showAlert('Certification removed.', 'success');
            if (selectedId) selectMember(selectedId);
        });
    }

    // ── Render ICS Qualifications ──────────────────────────────
    function renderIcsQualifications(quals, allPositions, memberId) {
        var container = document.getElementById('detailIcsQuals');
        var countEl = document.getElementById('icsQualCount');
        if (!container) return;
        if (countEl) countEl.textContent = quals.length;

        var html = '';
        if (quals.length > 0) {
            html += '<table class="table table-sm table-borderless mb-2">' +
                '<thead><tr><th>Position</th><th>Level</th><th>PTB Status</th><th>Evaluator</th><th></th></tr></thead><tbody>';
            for (var i = 0; i < quals.length; i++) {
                var q = quals[i];
                var levelColors = { Trainee: 'warning', Qualified: 'success', Expert: 'primary' };
                var ptbColors = { 'Not Started': 'secondary', 'In Progress': 'info', 'Completed': 'success' };
                html += '<tr>' +
                    '<td><span class="fw-semibold font-monospace">' + esc(q.code) + '</span>' +
                        ' <small class="text-body-secondary">' + esc(q.position_title || '') + '</small></td>' +
                    '<td><span class="badge bg-' + (levelColors[q.qualification_level] || 'secondary') + ' bg-opacity-75">' +
                        esc(q.qualification_level) + '</span></td>' +
                    '<td><span class="badge bg-' + (ptbColors[q.ptb_status] || 'secondary') + ' bg-opacity-75">' +
                        esc(q.ptb_status) + '</span></td>' +
                    '<td>' + esc(q.evaluator || '—') + '</td>' +
                    '<td><button type="button" class="btn btn-xs btn-outline-danger remove-ics-btn" ' +
                        'data-ics-id="' + q.id + '" title="Remove"><i class="bi bi-x-lg"></i></button></td>' +
                    '</tr>';
            }
            html += '</tbody></table>';
        } else {
            html += '<div class="text-body-secondary mb-2">No ICS qualifications on file.</div>';
        }

        // Add qualification form
        html += '<div class="border-top pt-2">' +
            '<div class="row g-1 align-items-end">' +
            '<div class="col-4">' +
            '<label class="form-label form-label-sm mb-0">Add ICS Position</label>' +
            '<select class="form-select form-select-sm" id="addIcsSelect">';
        html += '<option value="">-- Select --</option>';
        var categories = {};
        for (var j = 0; j < allPositions.length; j++) {
            var p = allPositions[j];
            var cat = p.category || 'Other';
            if (!categories[cat]) categories[cat] = [];
            categories[cat].push(p);
        }
        var catKeys = Object.keys(categories).sort();
        for (var c = 0; c < catKeys.length; c++) {
            html += '<optgroup label="' + esc(catKeys[c]) + '">';
            for (var m = 0; m < categories[catKeys[c]].length; m++) {
                var pos = categories[catKeys[c]][m];
                html += '<option value="' + pos.id + '">' + esc(pos.code) + ' — ' + esc(pos.title) + '</option>';
            }
            html += '</optgroup>';
        }
        html += '</select></div>' +
            '<div class="col-3">' +
            '<label class="form-label form-label-sm mb-0">Level</label>' +
            '<select class="form-select form-select-sm" id="addIcsLevel">' +
            '<option value="Trainee">Trainee</option>' +
            '<option value="Qualified">Qualified</option>' +
            '<option value="Expert">Expert</option>' +
            '</select></div>' +
            '<div class="col-3">' +
            '<label class="form-label form-label-sm mb-0">PTB Status</label>' +
            '<select class="form-select form-select-sm" id="addIcsPtb">' +
            '<option value="Not Started">Not Started</option>' +
            '<option value="In Progress">In Progress</option>' +
            '<option value="Completed">Completed</option>' +
            '</select></div>' +
            '<div class="col-2">' +
            '<button type="button" class="btn btn-sm btn-info w-100" id="btnAddIcsQual" title="Add">' +
            '<i class="bi bi-plus-lg"></i></button></div>' +
            '</div></div>';

        container.innerHTML = html;

        // Bind add button
        var addBtn = document.getElementById('btnAddIcsQual');
        if (addBtn) {
            addBtn.addEventListener('click', function () {
                var posId = document.getElementById('addIcsSelect').value;
                if (!posId) { showAlert('Please select an ICS position.'); return; }
                apiPostUrl('api/ics-positions.php', {
                    action: 'add_qualification',
                    member_id: memberId,
                    ics_position_id: parseInt(posId),
                    qualification_level: document.getElementById('addIcsLevel').value,
                    ptb_status: document.getElementById('addIcsPtb').value
                }, function (err) {
                    if (err) { showAlert('Failed: ' + err.message); return; }
                    showAlert('ICS qualification added.', 'success');
                    selectMember(memberId);
                });
            });
        }

        // Bind remove buttons
        var removeBtns = container.querySelectorAll('.remove-ics-btn');
        for (var r = 0; r < removeBtns.length; r++) {
            (function (btn) {
                btn.addEventListener('click', function () {
                    if (!confirm('Remove this ICS qualification?')) return;
                    apiPostUrl('api/ics-positions.php', {
                        action: 'remove_qualification',
                        id: parseInt(btn.getAttribute('data-ics-id'))
                    }, function (err) {
                        if (err) { showAlert('Failed: ' + err.message); return; }
                        showAlert('ICS qualification removed.', 'success');
                        if (selectedId) selectMember(selectedId);
                    });
                });
            })(removeBtns[r]);
        }
    }

    // ── Render Team Memberships ─────────────────────────────────
    function renderTeamMemberships(memberships) {
        var container = document.getElementById('detailTeamMemberships');
        var countEl = document.getElementById('teamMembershipCount');
        if (!container) return;
        if (countEl) countEl.textContent = memberships.length;

        if (memberships.length === 0) {
            container.innerHTML = '<div class="text-body-secondary">Not assigned to any teams. ' +
                '<a href="teams.php">Manage teams</a></div>';
            return;
        }

        var roleColors = { Leader: 'primary', Deputy: 'warning', Member: 'secondary', Observer: 'info' };
        var html = '<table class="table table-sm table-borderless mb-0"><tbody>';
        for (var i = 0; i < memberships.length; i++) {
            var tm = memberships[i];
            html += '<tr>' +
                '<td><a href="teams.php" class="text-decoration-none">' + esc(tm.team_name || 'Unknown') + '</a></td>' +
                '<td><span class="badge bg-' + (roleColors[tm.role] || 'secondary') + ' bg-opacity-75">' +
                    esc(tm.role || 'Member') + '</span></td>' +
                '<td class="text-body-secondary">' + (tm.position_code || '—') + '</td>' +
                '</tr>';
        }
        html += '</tbody></table>';
        container.innerHTML = html;
    }

    // ── Render Callsigns (Google Contacts style) ──────────────
    var _memberCallsigns = [];

    function renderCallsigns(callsigns, memberId) {
        var container = document.getElementById('callsignsList');
        var countEl = document.getElementById('callsignCount');
        if (!container) return;
        _memberCallsigns = callsigns || [];
        if (countEl) countEl.textContent = callsigns.length;

        if (callsigns.length === 0) {
            container.innerHTML = '<div class="text-body-secondary small">No callsigns on file.</div>';
        } else {
            var html = '<div class="list-group list-group-flush">';
            for (var i = 0; i < callsigns.length; i++) {
                var cs = callsigns[i];
                var isPrimary = cs.is_primary === '1' || cs.is_primary === 1;
                var typeLabel = (cs.license_type || 'amateur').charAt(0).toUpperCase() + (cs.license_type || 'amateur').slice(1);
                var classText = cs.oper_class ? ' (' + esc(cs.oper_class) + ')' : '';

                // Expiry badge
                var expiryHtml = '';
                if (cs.expiry_date) {
                    var ed = new Date(cs.expiry_date);
                    var daysLeft = Math.round((ed - new Date()) / 86400000);
                    var badgeCls = 'bg-success';
                    if (daysLeft < 0) badgeCls = 'bg-danger';
                    else if (daysLeft < 90) badgeCls = 'bg-warning text-dark';
                    else if (daysLeft < 365) badgeCls = 'bg-info text-dark';
                    expiryHtml = ' <span class="badge ' + badgeCls + '" style="font-size:0.65rem;">' +
                        esc(cs.expiry_date) + '</span>';
                }

                html += '<div class="list-group-item px-0 py-1 d-flex align-items-center gap-2">' +
                    '<button type="button" class="btn btn-xs p-0 set-primary-cs-btn' +
                        (isPrimary ? ' text-warning' : ' text-body-secondary') + '" ' +
                        'data-cs-id="' + cs.id + '" title="' + (isPrimary ? 'Primary callsign' : 'Set as primary') + '">' +
                        '<i class="bi bi-star' + (isPrimary ? '-fill' : '') + '"></i></button>' +
                    '<code class="fw-bold">' + esc(cs.callsign) + '</code>' +
                    '<span class="badge bg-secondary bg-opacity-50">' + esc(typeLabel) + classText + '</span>' +
                    expiryHtml +
                    '<div class="ms-auto d-flex gap-1">' +
                        '<button type="button" class="btn btn-xs btn-outline-info lookup-cs-btn" ' +
                            'data-callsign="' + esc(cs.callsign) + '" title="FCC Lookup">' +
                            '<i class="bi bi-search"></i></button>' +
                        '<button type="button" class="btn btn-xs btn-outline-danger remove-cs-btn" ' +
                            'data-cs-id="' + cs.id + '" title="Remove">' +
                            '<i class="bi bi-x-lg"></i></button>' +
                    '</div></div>';
            }
            html += '</div>';
            container.innerHTML = html;
        }

        // Bind set-primary buttons
        var primaryBtns = container.querySelectorAll('.set-primary-cs-btn');
        for (var p = 0; p < primaryBtns.length; p++) {
            (function (btn) {
                btn.addEventListener('click', function () {
                    var csId = parseInt(btn.getAttribute('data-cs-id'), 10);
                    apiPost({ action: 'set_primary_callsign', id: csId }, function (err) {
                        if (err) { showAlert('Failed: ' + err.message); return; }
                        selectMember(memberId);
                    });
                });
            })(primaryBtns[p]);
        }

        // Bind remove buttons
        var removeBtns = container.querySelectorAll('.remove-cs-btn');
        for (var r = 0; r < removeBtns.length; r++) {
            (function (btn) {
                btn.addEventListener('click', function () {
                    if (!confirm('Remove this callsign?')) return;
                    var csId = parseInt(btn.getAttribute('data-cs-id'), 10);
                    apiPost({ action: 'delete_callsign', id: csId }, function (err) {
                        if (err) { showAlert('Failed: ' + err.message); return; }
                        selectMember(memberId);
                    });
                });
            })(removeBtns[r]);
        }

        // Bind lookup buttons
        var lookupBtns = container.querySelectorAll('.lookup-cs-btn');
        for (var l = 0; l < lookupBtns.length; l++) {
            (function (btn) {
                btn.addEventListener('click', function () {
                    var cs = btn.getAttribute('data-callsign');
                    var addInput = document.getElementById('addCallsignInput');
                    if (addInput) addInput.value = cs;
                    document.getElementById('callsignAddRow').classList.remove('d-none');
                    doCallsignLookup();
                });
            })(lookupBtns[l]);
        }

        // Bind add callsign row toggle
        var btnAddRow = document.getElementById('btnAddCallsignRow');
        if (btnAddRow) {
            btnAddRow.addEventListener('click', function () {
                var addRow = document.getElementById('callsignAddRow');
                addRow.classList.toggle('d-none');
                if (!addRow.classList.contains('d-none')) {
                    var inp = document.getElementById('addCallsignInput');
                    if (inp) { inp.value = ''; inp.focus(); }
                }
            });
        }

        // Bind cancel
        var btnCancel = document.getElementById('btnCancelCallsign');
        if (btnCancel) {
            btnCancel.addEventListener('click', function () {
                document.getElementById('callsignAddRow').classList.add('d-none');
                document.getElementById('callsignResult').classList.add('d-none');
                document.getElementById('fccLicensePanel').classList.add('d-none');
            });
        }

        // Bind save callsign (manual entry without lookup)
        var btnSave = document.getElementById('btnSaveCallsign');
        if (btnSave) {
            btnSave.addEventListener('click', function () {
                var csInput = document.getElementById('addCallsignInput');
                var typeSelect = document.getElementById('addCallsignType');
                var cs = (csInput.value || '').toUpperCase().replace(/[^A-Z0-9]/g, '');
                if (!cs) { showAlert('Enter a callsign.'); return; }
                apiPost({
                    action: 'save_callsign',
                    member_id: memberId,
                    callsign: cs,
                    license_type: typeSelect.value || 'amateur',
                    source: 'manual'
                }, function (err) {
                    if (err) { showAlert('Failed: ' + err.message); return; }
                    document.getElementById('callsignAddRow').classList.add('d-none');
                    selectMember(memberId);
                });
            });
        }

        // Bind lookup button
        var btnLookup = document.getElementById('btnCallsignLookup');
        if (btnLookup) {
            btnLookup.addEventListener('click', function () {
                doCallsignLookup();
            });
        }

        // Enter key on callsign input triggers lookup
        var csInput = document.getElementById('addCallsignInput');
        if (csInput) {
            csInput.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    doCallsignLookup();
                }
            });
        }
    }

    // ── Render Organization Memberships ──────────────────────
    var _allMemberTypes = []; // Stored for org edit modal

    function renderOrgMemberships(memberships, allOrgs, memberId, allMemberTypes) {
        var container = document.getElementById('detailOrganizations');
        var countEl = document.getElementById('orgCount');
        if (!container) return;
        if (countEl) countEl.textContent = memberships.length;
        if (allMemberTypes) _allMemberTypes = allMemberTypes;

        var html = '';

        if (memberships.length > 0) {
            html += '<table class="table table-sm table-borderless mb-2"><thead>' +
                '<tr><th>Organization</th><th>Type</th><th>Status</th><th>Role</th><th>Joined</th><th></th></tr></thead><tbody>';
            for (var i = 0; i < memberships.length; i++) {
                var mo = memberships[i];
                var typeBadge = mo.type_name
                    ? '<span class="badge" style="background-color:' + esc(mo.type_color || '#6c757d') +
                      ';color:' + esc(mo.type_text_color || '#ffffff') + ';">' + esc(mo.type_name) + '</span>'
                    : '<span class="text-body-secondary">—</span>';
                var statusCls = mo.status === 'active' ? 'success' : (mo.status === 'pending' ? 'warning' : 'secondary');
                var roleBadge = mo.role
                    ? '<span class="badge bg-info bg-opacity-75">' + esc(mo.role) + '</span>'
                    : '<span class="text-body-secondary">—</span>';
                html += '<tr>' +
                    '<td>' + esc(mo.org_name) + (mo.short_name ? ' <small class="text-body-secondary">(' + esc(mo.short_name) + ')</small>' : '') + '</td>' +
                    '<td>' + typeBadge + '</td>' +
                    '<td><span class="badge bg-' + statusCls + '">' + esc(mo.status || 'active') + '</span></td>' +
                    '<td>' + roleBadge + '</td>' +
                    '<td>' + formatDate(mo.join_date) + '</td>' +
                    '<td class="d-flex gap-1">' +
                        '<button type="button" class="btn btn-xs btn-outline-secondary edit-org-btn" ' +
                            'data-org-row="' + esc(JSON.stringify(mo)) + '" title="Edit membership">' +
                            '<i class="bi bi-pencil"></i></button>' +
                        '<button type="button" class="btn btn-xs btn-outline-danger remove-org-btn" ' +
                            'data-org-row-id="' + mo.id + '" title="Remove from org">' +
                            '<i class="bi bi-x-lg"></i></button>' +
                    '</td></tr>';
            }
            html += '</tbody></table>';
        } else {
            // Active-state warning (Eric 2026-06-02). Any user account
            // linked to a member with no orgs will see data across ALL
            // organizations — useful for some accounts, surprising for
            // most. The contextual placement here (right next to the
            // Add to Organization form below) doubles as the call to
            // action. See docs/ACCESS-CHAIN.md.
            html += '<div class="alert alert-warning small mb-2" role="status">' +
                '<i class="bi bi-exclamation-triangle-fill me-1"></i>' +
                'This member is not assigned to any organization. Any user account linked to this member will see data across ALL organizations (no org filter applied). If this member should be scoped to one agency, use the form below to add them to it. ' +
                '<a href="docs/ACCESS-CHAIN.md" target="_blank">Learn more</a>' +
                '</div>';
        }

        // Add org form
        var existingOrgIds = {};
        for (var e = 0; e < memberships.length; e++) {
            existingOrgIds[memberships[e].org_id] = true;
        }
        var availableOrgs = [];
        for (var a = 0; a < allOrgs.length; a++) {
            if (!existingOrgIds[allOrgs[a].id]) {
                availableOrgs.push(allOrgs[a]);
            }
        }
        if (availableOrgs.length > 0) {
            html += '<div class="border-top pt-2">' +
                '<div class="row g-1 align-items-end">' +
                '<div class="col-8">' +
                '<label class="form-label form-label-sm mb-0">Add to Organization</label>' +
                '<select class="form-select form-select-sm" id="addOrgSelect">' +
                '<option value="">-- Select --</option>';
            for (var j = 0; j < availableOrgs.length; j++) {
                html += '<option value="' + availableOrgs[j].id + '">' + esc(availableOrgs[j].name) + '</option>';
            }
            html += '</select></div>' +
                '<div class="col-4">' +
                '<button type="button" class="btn btn-sm btn-primary w-100" id="btnAddOrg">' +
                '<i class="bi bi-plus-lg me-1"></i>Add</button></div>' +
                '</div></div>';
        }

        container.innerHTML = html;

        // Bind add org
        var addOrgBtn = document.getElementById('btnAddOrg');
        if (addOrgBtn) {
            addOrgBtn.addEventListener('click', function () {
                var sel = document.getElementById('addOrgSelect');
                var orgId = sel ? sel.value : '';
                if (!orgId) { showAlert('Please select an organization.'); return; }
                apiPostUrl('api/organizations.php', {
                    action: 'assign_member',
                    member_id: memberId,
                    org_id: parseInt(orgId, 10)
                }, function (err) {
                    if (err) { showAlert('Failed to add organization: ' + err); return; }
                    selectMember(memberId);
                });
            });
        }

        // Bind remove org buttons
        var removeOrgBtns = container.querySelectorAll('.remove-org-btn');
        for (var k = 0; k < removeOrgBtns.length; k++) {
            (function (btn) {
                btn.addEventListener('click', function () {
                    var rowId = parseInt(btn.getAttribute('data-org-row-id'), 10);
                    if (!confirm('Remove this member from the organization?')) return;
                    apiPostUrl('api/organizations.php', {
                        action: 'unassign_member',
                        id: rowId
                    }, function (err) {
                        if (err) { showAlert('Failed to remove: ' + err); return; }
                        selectMember(memberId);
                    });
                });
            })(removeOrgBtns[k]);
        }

        // Bind edit org buttons
        var editOrgBtns = container.querySelectorAll('.edit-org-btn');
        for (var e = 0; e < editOrgBtns.length; e++) {
            (function (btn) {
                btn.addEventListener('click', function () {
                    var mo = {};
                    try { mo = JSON.parse(btn.getAttribute('data-org-row')); } catch (ex) {}
                    openOrgEditModal(mo, memberId);
                });
            })(editOrgBtns[e]);
        }
    }

    var _orgEditMemberId = 0;
    var _orgEditModal = null;

    function openOrgEditModal(mo, memberId) {
        _orgEditMemberId = memberId;
        document.getElementById('editOrgMemberId').value = mo.member_id || memberId;
        document.getElementById('editOrgOrgId').value = mo.org_id || '';
        document.getElementById('editOrgName').textContent = mo.org_name || 'Organization';
        document.getElementById('editOrgStatus').value = mo.status || 'active';
        document.getElementById('editOrgRole').value = mo.role || '';
        document.getElementById('editOrgJoinDate').value = mo.join_date || '';
        document.getElementById('editOrgNotes').value = mo.notes || '';

        // Populate type dropdown
        var typeSelect = document.getElementById('editOrgType');
        typeSelect.innerHTML = '<option value="">— None —</option>';
        for (var t = 0; t < _allMemberTypes.length; t++) {
            var mt = _allMemberTypes[t];
            var sel = (mo.member_type_id && String(mt.id) === String(mo.member_type_id)) ? ' selected' : '';
            typeSelect.innerHTML += '<option value="' + mt.id + '"' + sel + '>' + esc(mt.name) + '</option>';
        }

        // Show modal
        var modalEl = document.getElementById('editOrgModal');
        _orgEditModal = bootstrap.Modal.getOrCreateInstance(modalEl);
        _orgEditModal.show();
    }

    // One-time save handler bound at init
    function initOrgEditSave() {
        var saveBtn = document.getElementById('btnSaveOrgMembership');
        if (!saveBtn) return;
        saveBtn.addEventListener('click', function () {
            var memberId = parseInt(document.getElementById('editOrgMemberId').value, 10);
            var orgId = parseInt(document.getElementById('editOrgOrgId').value, 10);
            if (!memberId || !orgId) {
                showAlert('Missing member or org ID — cannot save.');
                return;
            }
            var payload = {
                action: 'update_member_org',
                member_id: memberId,
                org_id: orgId,
                member_type_id: document.getElementById('editOrgType').value || null,
                status: document.getElementById('editOrgStatus').value,
                role: document.getElementById('editOrgRole').value || null,
                join_date: document.getElementById('editOrgJoinDate').value || null,
                notes: document.getElementById('editOrgNotes').value.trim() || null
            };
            // Disable button during save
            saveBtn.disabled = true;
            saveBtn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Saving...';
            fetch('api/organizations.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
            .then(function (r) {
                if (!r.ok) throw new Error('Server returned ' + r.status);
                return r.json();
            })
            .then(function (data) {
                saveBtn.disabled = false;
                saveBtn.innerHTML = '<i class="bi bi-check-lg me-1"></i>Save';
                if (data.error) {
                    showAlert('Failed to update: ' + data.error);
                    return;
                }
                if (_orgEditModal) _orgEditModal.hide();
                showAlert('Membership updated.', 'success');
                selectMember(_orgEditMemberId);
            })
            .catch(function (err) {
                saveBtn.disabled = false;
                saveBtn.innerHTML = '<i class="bi bi-check-lg me-1"></i>Save';
                showAlert('Failed to update: ' + (err.message || err));
            });
        });
    }

    // ── Render Communication / Location Identifiers ──────────
    // 2026-06-14 (Phase 48): stash each ci row in a module-scope map
    // keyed by id so the Edit button can recover it without serialising
    // JSON through a data-* attribute. esc() doesn't escape `"`; that's
    // what broke the modal field rendering in Phase 46 and was breaking
    // the Edit button here too (open-blank-form symptom — JSON.parse
    // threw silently into a catch, leaving ci = {}).
    var _commIdRowsById = {};
    function renderCommIdentifiers(identifiers, allModes, memberId) {
        var container = document.getElementById('detailCommIds');
        var countEl = document.getElementById('commIdCount');
        if (!container) return;
        if (countEl) countEl.textContent = identifiers.length;

        _commIdRowsById = {};
        var html = '';

        if (identifiers.length > 0) {
            html += '<div class="list-group list-group-flush mb-2">';
            for (var i = 0; i < identifiers.length; i++) {
                var ci = identifiers[i];
                _commIdRowsById[String(ci.id)] = ci;
                var values = {};
                try { values = JSON.parse(ci.values_json || '{}'); } catch (e) { values = {}; }
                var fields = [];
                try { fields = JSON.parse(ci.fields_json || '[]'); } catch (e) { fields = []; }

                // Build value display from fields_json definition order
                var valParts = [];
                for (var f = 0; f < fields.length; f++) {
                    var fKey = fields[f].key;
                    var fLabel = fields[f].label || fKey;
                    if (values[fKey]) {
                        valParts.push('<span class="text-body-secondary">' + esc(fLabel) + ':</span> ' + esc(values[fKey]));
                    }
                }

                var iconCls = ci.mode_icon ? 'bi bi-' + esc(ci.mode_icon) : 'bi bi-broadcast';
                var primaryStar = ci.is_primary === '1' || ci.is_primary === 1
                    ? ' <i class="bi bi-star-fill text-warning" title="Primary"></i>' : '';
                var labelText = ci.label ? ' <small class="text-body-secondary">(' + esc(ci.label) + ')</small>' : '';

                // Capabilities badges
                var capHtml = '';
                if (ci.mode_capabilities) {
                    var caps = ci.mode_capabilities.split(',');
                    var capIcons = { 'V': 'bi-mic', 'L': 'bi-geo-alt', '1T': 'bi-chat-dots', '2T': 'bi-chat-text' };
                    var capTitles = { 'V': 'Voice', 'L': 'Location', '1T': '1-Way Text', '2T': '2-Way Text' };
                    for (var cc = 0; cc < caps.length; cc++) {
                        var c = caps[cc].trim();
                        capHtml += ' <span class="badge bg-secondary bg-opacity-50" title="' + esc(capTitles[c] || c) +
                            '" style="font-size:0.6rem;"><i class="bi ' + (capIcons[c] || 'bi-question') + '"></i> ' + esc(c) + '</span>';
                    }
                }

                // Reorder buttons disabled at the ends of the list so the
                // UI doesn't suggest impossible moves.
                var canMoveUp   = (i > 0);
                var canMoveDown = (i < identifiers.length - 1);
                html += '<div class="list-group-item px-0 py-1 d-flex align-items-start gap-2">' +
                    '<span class="badge mt-1" style="background-color:' + esc(ci.mode_color || '#6c757d') + ';">' +
                        '<i class="' + iconCls + '"></i></span>' +
                    '<div class="flex-grow-1">' +
                        '<div><strong>' + esc(ci.mode_name) + '</strong>' + capHtml + labelText + primaryStar + '</div>' +
                        '<div class="small">' + (valParts.length > 0 ? valParts.join(' &middot; ') : '<span class="text-body-secondary">No data</span>') + '</div>' +
                        (ci.notes ? '<div class="text-body-secondary small fst-italic">' + esc(ci.notes) + '</div>' : '') +
                    '</div>' +
                    '<div class="d-flex gap-1 flex-wrap justify-content-end" style="max-width:170px">' +
                        '<button type="button" class="btn btn-xs btn-outline-secondary move-comm-up-btn" data-comm-id="' + ci.id + '" title="Move up"' + (canMoveUp ? '' : ' disabled') + '><i class="bi bi-arrow-up"></i></button>' +
                        '<button type="button" class="btn btn-xs btn-outline-secondary move-comm-down-btn" data-comm-id="' + ci.id + '" title="Move down"' + (canMoveDown ? '' : ' disabled') + '><i class="bi bi-arrow-down"></i></button>' +
                        '<button type="button" class="btn btn-xs btn-outline-secondary set-primary-comm-btn" data-comm-id="' + ci.id + '" title="Set primary"><i class="bi bi-star"></i></button>' +
                        '<button type="button" class="btn btn-xs btn-outline-info edit-comm-btn" data-comm-id="' + ci.id + '" title="Edit"><i class="bi bi-pencil"></i></button>' +
                        '<button type="button" class="btn btn-xs btn-outline-danger remove-comm-btn" data-comm-id="' + ci.id + '" title="Remove"><i class="bi bi-x-lg"></i></button>' +
                    '</div>' +
                    '</div>';
            }
            html += '</div>';
        } else {
            html += '<div class="text-body-secondary mb-2">No communication identifiers on file.</div>';
        }

        // Add button (opens modal)
        if (allModes.length > 0) {
            html += '<div class="border-top pt-2 text-end">' +
                '<button type="button" class="btn btn-sm btn-outline-primary" id="btnAddCommModal">' +
                '<i class="bi bi-plus-lg me-1"></i>Add Identifier</button></div>';
        }

        container.innerHTML = html;

        // Bind add button → open modal
        var addCommModalBtn = document.getElementById('btnAddCommModal');
        if (addCommModalBtn) {
            addCommModalBtn.addEventListener('click', function () {
                openCommModal(null, allModes, memberId);
            });
        }

        // Bind edit buttons → open modal with existing data.
        // 2026-06-14 (Phase 48): look the row up in _commIdRowsById by id
        // instead of decoding JSON from a data-comm-row attribute. The old
        // approach used esc(JSON.stringify(ci)), and esc() doesn't escape
        // double quotes — the attribute terminated at the first inner ",
        // JSON.parse threw silently into the catch, the modal opened with
        // ci = {} (no id), and the user saw a blank Add Identifier form
        // instead of the edit they asked for.
        var editCommBtns = container.querySelectorAll('.edit-comm-btn');
        for (var ec = 0; ec < editCommBtns.length; ec++) {
            (function (btn) {
                btn.addEventListener('click', function () {
                    var id = btn.getAttribute('data-comm-id');
                    var ci = _commIdRowsById[String(id)];
                    if (!ci) {
                        console.error('Edit identifier: no cached row for id=' + id);
                        showAlert('Could not load that identifier for editing — try reloading the page.', 'warning');
                        return;
                    }
                    openCommModal(ci, allModes, memberId);
                });
            })(editCommBtns[ec]);
        }

        // Bind reorder buttons → POST a reorder action, then refresh.
        // 2026-06-14 (Phase 48): per-row up/down arrows so admins can
        // order multiple identifiers (Eric's three DMR Radio IDs being
        // the motivating case — Mobile HT first, Portable second,
        // Base Station third).
        function _bindReorder(btnClass, direction) {
            var btns = container.querySelectorAll(btnClass);
            for (var rb = 0; rb < btns.length; rb++) {
                (function (btn) {
                    btn.addEventListener('click', function () {
                        if (btn.disabled) return;
                        var id = btn.getAttribute('data-comm-id');
                        apiPostUrl('api/comm-identifiers.php', {
                            action: 'reorder_identifier',
                            id: parseInt(id, 10),
                            direction: direction
                        }, function (err) {
                            if (err) { showAlert('Reorder failed: ' + (err.message || err), 'danger'); return; }
                            selectMember(memberId);
                        });
                    });
                })(btns[rb]);
            }
        }
        _bindReorder('.move-comm-up-btn', 'up');
        _bindReorder('.move-comm-down-btn', 'down');

        // Bind set primary buttons
        var primaryBtns = container.querySelectorAll('.set-primary-comm-btn');
        for (var p = 0; p < primaryBtns.length; p++) {
            (function (btn) {
                btn.addEventListener('click', function () {
                    var commId = parseInt(btn.getAttribute('data-comm-id'), 10);
                    apiPostUrl('api/comm-identifiers.php', {
                        action: 'set_primary',
                        id: commId
                    }, function (err) {
                        if (err) { showAlert('Failed to set primary: ' + err); return; }
                        selectMember(memberId);
                    });
                });
            })(primaryBtns[p]);
        }

        // Bind remove comm buttons
        var removeCommBtns = container.querySelectorAll('.remove-comm-btn');
        for (var r = 0; r < removeCommBtns.length; r++) {
            (function (btn) {
                btn.addEventListener('click', function () {
                    var commId = parseInt(btn.getAttribute('data-comm-id'), 10);
                    if (!confirm('Remove this identifier?')) return;
                    apiPostUrl('api/comm-identifiers.php', {
                        action: 'delete_identifier',
                        id: commId
                    }, function (err) {
                        if (err) { showAlert('Failed to remove identifier: ' + err); return; }
                        selectMember(memberId);
                    });
                });
            })(removeCommBtns[r]);
        }
    }

    // ── RadioID.net Lookup ───────────────────────────────────
    /**
     * Open the comm identifier modal for add or edit
     * @param {Object|null} existing - existing identifier data (null for add)
     * @param {Array} allModes - all comm modes from API
     * @param {number} memberId - current member ID
     */
    function openCommModal(existing, allModes, memberId) {
        var isEdit = existing && existing.id;
        document.getElementById('commModalTitle').textContent = isEdit ? 'Edit Identifier' : 'Add Identifier';
        document.getElementById('commModalId').value = isEdit ? existing.id : '';
        document.getElementById('commModalMemberId').value = memberId;

        // Populate mode dropdown.
        //
        // 2026-06-14 (Phase 46): keep the mode metadata (fields_json + code)
        // in a JS-side map keyed by mode id instead of HTML data-* attributes.
        // `esc()` is innerHTML-based and escapes <, > and & but NOT ", so a
        // fields_json blob like [{"key":"radio_id",...}] used to break the
        // surrounding data-fields=" attribute at the first inner quote.
        // JSON.parse() then threw silently into a catch and dynamic fields
        // never rendered — symptom: every Add Identifier dialog looked
        // "incomplete" with only Mode/Label/Notes visible.
        var modeSelect = document.getElementById('commModalMode');
        modeSelect.innerHTML = '';
        var modeMeta = {};
        var placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = '-- Select Mode --';
        modeSelect.appendChild(placeholder);
        for (var m = 0; m < allModes.length; m++) {
            var am = allModes[m];
            var opt = document.createElement('option');
            opt.value = am.id;
            opt.textContent = am.name;
            if (isEdit && String(am.id) === String(existing.comm_mode_id)) {
                opt.selected = true;
            }
            modeSelect.appendChild(opt);
            modeMeta[String(am.id)] = {
                fields_json: am.fields_json || '[]',
                code: am.code || ''
            };
        }
        if (isEdit) modeSelect.disabled = true;
        else modeSelect.disabled = false;

        document.getElementById('commModalLabel').value = isEdit ? (existing.label || '') : '';
        document.getElementById('commModalNotes').value = isEdit ? (existing.notes || '') : '';

        // Build dynamic fields
        var fieldsContainer = document.getElementById('commModalFields');
        var lookupArea = document.getElementById('commModalLookupArea');
        fieldsContainer.innerHTML = '';
        lookupArea.innerHTML = '';

        function buildFields(fieldsDef, existingValues, modeCode) {
            var fHtml = '<div class="row g-2">';
            for (var fi = 0; fi < fieldsDef.length; fi++) {
                var fd = fieldsDef[fi];
                var val = existingValues[fd.key] || '';
                // Auto-populate callsign fields from member's primary callsign
                if (!val && fd.key.indexOf('callsign') !== -1 && _memberCallsigns.length > 0) {
                    for (var pc = 0; pc < _memberCallsigns.length; pc++) {
                        if (_memberCallsigns[pc].is_primary === '1' || _memberCallsigns[pc].is_primary === 1) {
                            val = _memberCallsigns[pc].callsign;
                            break;
                        }
                    }
                }
                // 2026-06-11 fix: render fd.hint as small help text below
                // each field so admins know what to enter. Without this,
                // Eric couldn't tell the OwnTracks "MQTT Topic" field is
                // only needed for MQTT mode (not for HTTP-Direct, which
                // is what the documentation recommends).
                fHtml += '<div class="col-12 mb-2">' +
                    '<label class="form-label form-label-sm mb-1">' + esc(fd.label || fd.key) +
                    (fd.required ? ' <span class="text-danger">*</span>' : '') + '</label>';
                if (fd.type === 'select' && fd.options) {
                    fHtml += '<select class="form-select form-select-sm comm-modal-field" data-field-key="' + esc(fd.key) + '">';
                    for (var si = 0; si < fd.options.length; si++) {
                        var optVal = fd.options[si];
                        var optSel = (optVal === val) ? ' selected' : '';
                        fHtml += '<option value="' + esc(optVal) + '"' + optSel + '>' + (optVal || '—') + '</option>';
                    }
                    fHtml += '</select>';
                } else {
                    fHtml += '<input type="' + (fd.type === 'number' ? 'number' : 'text') + '" ' +
                        'class="form-control form-control-sm comm-modal-field" ' +
                        'data-field-key="' + esc(fd.key) + '" ' +
                        'value="' + esc(val) + '" ' +
                        (fd.placeholder ? 'placeholder="' + esc(fd.placeholder) + '" ' : '') +
                        (fd.maxlength ? 'maxlength="' + parseInt(fd.maxlength) + '" ' : '') +
                        '>';
                }
                if (fd.hint) {
                    fHtml += '<div class="form-text small">' + esc(fd.hint) + '</div>';
                }
                fHtml += '</div>';
            }
            fHtml += '</div>';
            fieldsContainer.innerHTML = fHtml;

            // DMR RadioID lookup
            if (modeCode === 'dmr') {
                lookupArea.innerHTML = '<button type="button" class="btn btn-xs btn-outline-info" id="btnRadioIdModalLookup">' +
                    '<i class="bi bi-search me-1"></i>Lookup RadioID.net</button>' +
                    '<small class="text-body-secondary ms-2" id="radioIdModalStatus"></small>';
                var lookupBtn = document.getElementById('btnRadioIdModalLookup');
                if (lookupBtn) {
                    lookupBtn.addEventListener('click', function () {
                        doRadioIdLookup(memberId);
                    });
                }
            } else {
                lookupArea.innerHTML = '';
            }
        }

        // If editing, build fields immediately
        if (isEdit) {
            var existingValues = {};
            try { existingValues = JSON.parse(existing.values_json || '{}'); } catch (ex) {}
            var fieldsDef = [];
            try { fieldsDef = JSON.parse(existing.fields_json || '[]'); } catch (ex) {}
            buildFields(fieldsDef, existingValues, existing.mode_code || '');
        }

        // Mode change → rebuild fields. Look the per-mode metadata up in
        // modeMeta (populated above) rather than parsing data-* attributes.
        // 2026-06-14 (Phase 46c): if fields_json fails to parse or arrives
        // empty, surface it in the dialog AND console — silent fallback to
        // an empty array was the bug Eric hit, and "the dialog showed no
        // fields" had no visible diagnostic.
        modeSelect.addEventListener('change', function () {
            var modeId = modeSelect.value;
            if (!modeId) { fieldsContainer.innerHTML = ''; lookupArea.innerHTML = ''; return; }
            var meta = modeMeta[String(modeId)] || { fields_json: '[]', code: '' };
            var fd = [];
            try {
                fd = JSON.parse(meta.fields_json || '[]');
            } catch (ex) {
                console.error('Add Identifier: fields_json failed to parse for mode id=' + modeId, ex, meta.fields_json);
                fieldsContainer.innerHTML = '<div class="alert alert-warning small mb-0">' +
                    'Could not parse the field definitions for this mode. Check the browser console for details, ' +
                    'or visit Settings → Identifiers → ' + esc(meta.code || modeId) + ' to fix the JSON.' +
                    '</div>';
                lookupArea.innerHTML = '';
                return;
            }
            if (!fd.length) {
                console.warn('Add Identifier: mode id=' + modeId + ' (' + meta.code + ') has no fields defined.');
                fieldsContainer.innerHTML = '<div class="alert alert-info small mb-0">' +
                    'This mode has no field definitions yet. Add some in Settings → Identifiers, then come back.' +
                    '</div>';
                lookupArea.innerHTML = '';
                return;
            }
            buildFields(fd, {}, meta.code);
        });

        // 2026-06-14 (Phase 46): if a mode is pre-selected (edit path or
        // browser autofill), fire the same build pass immediately so the
        // user sees the correct fields without having to re-pick the mode.
        if (!isEdit && modeSelect.value) {
            var initMeta = modeMeta[String(modeSelect.value)];
            if (initMeta) {
                var initFd = [];
                try { initFd = JSON.parse(initMeta.fields_json || '[]'); } catch (ex2) {}
                buildFields(initFd, {}, initMeta.code);
            }
        }

        // Show modal
        var modal = new bootstrap.Modal(document.getElementById('editCommModal'));
        modal.show();

        // Bind save (replace button to remove old listeners)
        var saveBtn = document.getElementById('btnSaveCommModal');
        var newSave = saveBtn.cloneNode(true);
        saveBtn.parentNode.replaceChild(newSave, saveBtn);
        newSave.addEventListener('click', function () {
            var modeId = modeSelect.value;
            if (!modeId) { showAlert('Please select a mode.'); return; }

            var fieldInputs = document.querySelectorAll('.comm-modal-field');
            var valuesObj = {};
            for (var vi = 0; vi < fieldInputs.length; vi++) {
                var key = fieldInputs[vi].getAttribute('data-field-key');
                valuesObj[key] = fieldInputs[vi].value;
            }

            var payload = {
                action: 'save_identifier',
                member_id: memberId,
                comm_mode_id: parseInt(modeId, 10),
                label: document.getElementById('commModalLabel').value.trim(),
                values_json: valuesObj,
                notes: document.getElementById('commModalNotes').value.trim()
            };

            // If editing, include the id
            var existingId = document.getElementById('commModalId').value;
            if (existingId) payload.id = parseInt(existingId, 10);

            apiPostUrl('api/comm-identifiers.php', payload, function (err, data) {
                if (err) { showAlert('Failed to save: ' + err); return; }
                modal.hide();

                // 2026-06-11 polish: when the saved identifier is for a
                // location-capable mode (APRS / DMR / Meshtastic /
                // OwnTracks), the comm-identifier on its own doesn't
                // actually drive map tracking — the same identifier
                // also needs to be bound on the Unit Detail page's
                // Location Sources card. Show a follow-up hint with
                // direct links to the member's currently-assigned
                // unit(s) so admin doesn't have to navigate manually.
                showAlert('Identifier saved.', 'success');
                if (data && data.location_capable) {
                    showLocationBindingHint(data, payload);
                }
                selectMember(memberId);
            });
        });
    }

    function doRadioIdLookup(memberId) {
        // 2026-06-14 (Phase 46): rewritten selectors. The Add Identifier
        // modal renders fields with class `comm-modal-field` and a status
        // element id of `radioIdModalStatus` — the previous version was
        // looking for `comm-field-input` / `radioIdStatus`, neither of
        // which exist in the modal context. The result was a no-op every
        // time. Also prepopulate from the member's primary amateur
        // callsign so the lookup just works without a prompt.
        var statusEl = document.getElementById('radioIdModalStatus')
            || document.getElementById('radioIdStatus');
        var radioIdInput = document.querySelector(
            '.comm-modal-field[data-field-key="radio_id"], .comm-field-input[data-field-key="radio_id"]'
        );

        // 1) The modal-rendered DMR row may include a callsign_ssid field
        //    that the admin filled in. Strip any "-7" SSID suffix.
        var callsign = '';
        var callsignInput = document.querySelector(
            '.comm-modal-field[data-field-key="callsign_ssid"], .comm-field-input[data-field-key="callsign_ssid"]'
        );
        if (callsignInput && callsignInput.value) {
            callsign = callsignInput.value.replace(/-\d+$/, '').trim();
        }
        // 2) Otherwise pick the member's primary callsign (amateur, GMRS,
        //    or whichever is flagged primary). Falls back to the first
        //    callsign in the list if none is flagged primary.
        if (!callsign && typeof _memberCallsigns !== 'undefined' && _memberCallsigns && _memberCallsigns.length) {
            for (var pc = 0; pc < _memberCallsigns.length; pc++) {
                if (_memberCallsigns[pc].is_primary === '1' || _memberCallsigns[pc].is_primary === 1) {
                    callsign = (_memberCallsigns[pc].callsign || '').trim();
                    break;
                }
            }
            if (!callsign) callsign = (_memberCallsigns[0].callsign || '').trim();
        }
        // 3) Last resort — ask the admin.
        if (!callsign) {
            callsign = prompt('Enter callsign to look up on RadioID.net:');
        }
        if (!callsign) return;

        if (statusEl) statusEl.textContent = 'Looking up ' + callsign + '…';

        fetch('api/comm-identifiers.php?action=radioid_lookup&callsign=' + encodeURIComponent(callsign))
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.error) {
                    if (statusEl) statusEl.textContent = data.error;
                    return;
                }
                if (!data.results || data.results.length === 0) {
                    if (statusEl) statusEl.textContent = 'No DMR IDs found for ' + callsign;
                    return;
                }
                _renderRadioIdResults(data.results, callsign, memberId, radioIdInput, statusEl);
            })
            .catch(function () {
                if (statusEl) statusEl.textContent = 'Lookup failed — try again or enter manually.';
            });
    }

    /**
     * Render the RadioID.net lookup results below the Lookup button.
     *
     * 2026-06-14 (Phase 46d): Eric pointed out that his callsign has 3 Radio
     * IDs registered on RadioID.net, and most volunteers will have 2 (one
     * mobile, one portable). Auto-filling the first result silently throws
     * away the others. Render every result with a "Use this" button that
     * fills the current radio_id field, plus an "Add as new identifier"
     * button that saves it as a separate DMR row on the member (so an admin
     * can attach all three IDs in one sitting).
     */
    function _renderRadioIdResults(results, callsign, memberId, radioIdInput, statusEl) {
        var lookupArea = document.getElementById('commModalLookupArea');
        if (!lookupArea) {
            // Fallback: no lookup area, just auto-fill first result like before.
            if (radioIdInput && results[0] && results[0].id) radioIdInput.value = results[0].id;
            if (statusEl) statusEl.textContent = 'Found ' + results.length + ' result(s)';
            return;
        }
        // Keep the Lookup button in place (it was rendered into lookupArea
        // by buildFields). Append the results table below it.
        var existingBtn = document.getElementById('btnRadioIdModalLookup');
        var btnHtml = existingBtn ? existingBtn.outerHTML : '';
        var html = btnHtml
            + ' <small class="text-body-secondary ms-2" id="radioIdModalStatus">'
            + 'Found ' + results.length + ' Radio ID' + (results.length === 1 ? '' : 's') + ' for ' + esc(callsign)
            + '</small>'
            + '<div class="table-responsive mt-2"><table class="table table-sm table-hover table-striped small mb-0">'
            + '<thead class="table-light"><tr>'
            +   '<th scope="col">Radio ID</th>'
            +   '<th scope="col">Name</th>'
            +   '<th scope="col">Location</th>'
            +   '<th scope="col" style="min-width:130px">Label (optional)</th>'
            +   '<th scope="col" class="text-end">Action</th>'
            + '</tr></thead><tbody>';
        for (var i = 0; i < results.length; i++) {
            var r = results[i];
            var name = (((r.fname || '') + ' ' + (r.surname || '')) || '').trim();
            var loc = [];
            if (r.city)    loc.push(r.city);
            if (r.state)   loc.push(r.state);
            if (r.country) loc.push(r.country);
            // 2026-06-14 (Phase 48): per-row label input so the admin can
            // type "Mobile HT" / "Portable" / "Base Station" before
            // clicking Add — Eric pointed out that auto-labelling every
            // row with the same city/state doesn't help disambiguate
            // multiple Radio IDs on the same callsign.
            html += '<tr>'
                +    '<td class="font-monospace">' + esc(String(r.id || '')) + '</td>'
                +    '<td>' + esc(name) + '</td>'
                +    '<td class="text-body-secondary">' + esc(loc.join(', ')) + '</td>'
                +    '<td><input type="text" class="form-control form-control-sm radioid-label-input" '
                +      'data-radio-id="' + esc(String(r.id || '')) + '" '
                +      'placeholder="e.g. Mobile HT" maxlength="64"></td>'
                +    '<td class="text-end text-nowrap">'
                +      '<button type="button" class="btn btn-xs btn-primary radioid-use-btn" data-radio-id="' + esc(String(r.id || '')) + '" title="Fill the Radio ID + Label fields above with this value">'
                +        '<i class="bi bi-arrow-up-square me-1"></i>Use'
                +      '</button> '
                +      '<button type="button" class="btn btn-xs btn-outline-success radioid-add-btn" data-radio-id="' + esc(String(r.id || '')) + '" data-radio-loc="' + esc(loc.join(', ')) + '" title="Save this Radio ID as a new identifier on the member (keeps the form open so you can add the next one)">'
                +        '<i class="bi bi-plus-lg me-1"></i>Add'
                +      '</button>'
                +    '</td>'
                +  '</tr>';
        }
        html += '</tbody></table></div>'
             + '<div class="form-text small mt-1">'
             +   '<strong>Use</strong> fills the form above with this Radio ID + Label — then click Save at the bottom of the dialog. '
             +   '<strong>Add</strong> saves this Radio ID as a separate DMR identifier on the member (using the per-row Label if you typed one, otherwise the city/state). Dialog stays open so you can add the next one.'
             + '</div>';
        lookupArea.innerHTML = html;

        // Re-bind the original Lookup button (re-rendered above).
        var newLookupBtn = document.getElementById('btnRadioIdModalLookup');
        if (newLookupBtn) {
            newLookupBtn.addEventListener('click', function () { doRadioIdLookup(memberId); });
        }
        // Bind "Use" buttons — fill the radio_id field + (if filled) the
        // dialog's Label field with the per-row label input.
        var useBtns = lookupArea.querySelectorAll('.radioid-use-btn');
        for (var u = 0; u < useBtns.length; u++) {
            useBtns[u].addEventListener('click', function (ev) {
                var rid = ev.currentTarget.getAttribute('data-radio-id');
                var input = document.querySelector('.comm-modal-field[data-field-key="radio_id"]');
                if (input) {
                    input.value = rid;
                    input.classList.add('border-primary');
                    setTimeout(function () { input.classList.remove('border-primary'); }, 1500);
                }
                var rowLabel = lookupArea.querySelector('.radioid-label-input[data-radio-id="' + rid + '"]');
                var labelInput = document.getElementById('commModalLabel');
                if (rowLabel && rowLabel.value && labelInput) {
                    labelInput.value = rowLabel.value.trim();
                }
            });
        }
        // Bind "Add" buttons — POST a save_identifier directly so the admin
        // can attach all three Radio IDs in one pass without having to
        // re-open the dialog for each. Per-row Label input wins over the
        // city/state default if the admin typed one (Phase 48).
        var addBtns = lookupArea.querySelectorAll('.radioid-add-btn');
        for (var a = 0; a < addBtns.length; a++) {
            addBtns[a].addEventListener('click', function (ev) {
                var btn = ev.currentTarget;
                var rid = btn.getAttribute('data-radio-id');
                var loc = btn.getAttribute('data-radio-loc') || '';
                if (!rid) return;
                var modeSelect = document.getElementById('commModalMode');
                var modeId = modeSelect ? modeSelect.value : '';
                if (!modeId) { showAlert('Pick the DMR mode first.', 'warning'); return; }
                // Phase 48 — prefer the typed label, fall back to location,
                // then a generic "RadioID 3127061" so the saved row always
                // has *something* in the label column.
                var rowLabel = lookupArea.querySelector('.radioid-label-input[data-radio-id="' + rid + '"]');
                var labelTxt = (rowLabel && rowLabel.value.trim()) || loc || ('RadioID ' + rid);
                btn.disabled = true;
                btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Adding…';
                apiPostUrl('api/comm-identifiers.php', {
                    action: 'save_identifier',
                    member_id: memberId,
                    comm_mode_id: parseInt(modeId, 10),
                    label: labelTxt,
                    values_json: { radio_id: rid },
                    notes: 'Imported from RadioID.net lookup'
                }, function (err) {
                    if (err) {
                        btn.disabled = false;
                        btn.innerHTML = '<i class="bi bi-plus-lg me-1"></i>Add';
                        showAlert('Could not add ' + rid + ': ' + (err.message || err), 'danger');
                        return;
                    }
                    btn.classList.remove('btn-outline-success');
                    btn.classList.add('btn-success');
                    btn.innerHTML = '<i class="bi bi-check-lg me-1"></i>Added';
                    // Refresh the member detail so the new row appears in the
                    // identifier list below, but keep this modal open so the
                    // admin can add the next Radio ID without re-navigating.
                    if (typeof selectMember === 'function') selectMember(memberId);
                });
            });
        }
    }

    // ── Render Training Records ───────────────────────────────
    function renderTrainingRecords(records, totalHours, memberId) {
        var container = document.getElementById('detailTraining');
        var countEl = document.getElementById('trainingCount');
        if (!container) return;
        if (countEl) countEl.textContent = records.length;

        var html = '';

        // Summary line
        if (records.length > 0) {
            html += '<div class="d-flex gap-3 mb-2 text-body-secondary" style="font-size:0.75rem;">' +
                '<span><i class="bi bi-clock me-1"></i>' + totalHours + ' total hours</span>' +
                '<span><i class="bi bi-journal-check me-1"></i>' + records.length + ' records</span>' +
                '</div>';
        }

        if (records.length > 0) {
            var typeColors = { Course: 'primary', Drill: 'success', Exercise: 'warning',
                               Workshop: 'info', OJT: 'secondary', Webinar: 'primary', 'Self-Study': 'secondary' };
            var resultColors = { Completed: 'success', 'In Progress': 'info', Incomplete: 'warning', Failed: 'danger' };
            html += '<table class="table table-sm table-borderless mb-2">' +
                    '<thead><tr><th>Training</th><th>Type</th><th>Date</th><th>Result</th><th></th></tr></thead><tbody>';
            for (var i = 0; i < records.length; i++) {
                var r = records[i];
                var nameLabel = esc(r.training_name);
                if (r.fema_course_code) nameLabel += ' <span class="text-body-secondary" style="font-size:0.7rem;">(' + esc(r.fema_course_code) + ')</span>';
                if (r.hours) nameLabel += '<br><span class="text-body-secondary" style="font-size:0.7rem;">' + r.hours + ' hrs</span>';
                if (r.instructor) nameLabel += '<span class="text-body-secondary" style="font-size:0.7rem;"> — ' + esc(r.instructor) + '</span>';
                html += '<tr>' +
                    '<td>' + nameLabel + '</td>' +
                    '<td><span class="badge bg-' + (typeColors[r.training_type] || 'secondary') + ' bg-opacity-75" style="font-size:0.65rem;">' + esc(r.training_type || '') + '</span></td>' +
                    '<td>' + formatDate(r.training_date) + '</td>' +
                    '<td><span class="badge bg-' + (resultColors[r.result] || 'secondary') + ' bg-opacity-75" style="font-size:0.65rem;">' + esc(r.result || '') + '</span></td>' +
                    '<td><button type="button" class="btn btn-xs btn-outline-danger remove-training-btn" data-training-id="' + r.id + '" title="Remove"><i class="bi bi-x-lg"></i></button></td>' +
                    '</tr>';
            }
            html += '</tbody></table>';
        } else {
            html += '<div class="text-body-secondary mb-2">No training records on file.</div>';
        }

        // Add training form
        // Layout note (2026-06-26, a beta tester): Date was at col-2,
        // which truncated to "2025-12-0…" on his viewport because date
        // inputs need ~120px for the YYYY-MM-DD digits plus the picker
        // icon. Promoted Date to col-3 and trimmed Training Name from
        // col-4 to col-3 to keep the row total at 12 cols (3+2+3+1+2+1=12).
        html += '<div class="border-top pt-2">' +
                '<div class="row g-1 align-items-end">' +
                '<div class="col-3 position-relative">' +
                '<label class="form-label form-label-sm mb-0">Training Name</label>' +
                '<input type="text" class="form-control form-control-sm" id="addTrainingName" ' +
                       'placeholder="Type to search catalog + others..." autocomplete="off">' +
                '<div id="addTrainingSuggest" class="dropdown-menu w-100 shadow-sm" ' +
                     'style="max-height:280px;overflow-y:auto;display:none;"></div>' +
                '</div>' +
                '<div class="col-2">' +
                '<label class="form-label form-label-sm mb-0">Type</label>' +
                '<select class="form-select form-select-sm" id="addTrainingType">' +
                '<option value="Course">Course</option>' +
                '<option value="Drill">Drill</option>' +
                '<option value="Exercise">Exercise</option>' +
                '<option value="Workshop">Workshop</option>' +
                '<option value="OJT">OJT</option>' +
                '<option value="Webinar">Webinar</option>' +
                '<option value="Self-Study">Self-Study</option>' +
                '</select></div>' +
                '<div class="col-3">' +
                '<label class="form-label form-label-sm mb-0">Date</label>' +
                '<input type="date" class="form-control form-control-sm" id="addTrainingDate"></div>' +
                '<div class="col-1">' +
                '<label class="form-label form-label-sm mb-0">Hours</label>' +
                '<input type="number" class="form-control form-control-sm" id="addTrainingHours" step="0.5" min="0" placeholder="0"></div>' +
                '<div class="col-2">' +
                '<label class="form-label form-label-sm mb-0">Result</label>' +
                '<select class="form-select form-select-sm" id="addTrainingResult">' +
                '<option value="Completed">Completed</option>' +
                '<option value="In Progress">In Progress</option>' +
                '<option value="Incomplete">Incomplete</option>' +
                '<option value="Failed">Failed</option>' +
                '</select></div>' +
                '<div class="col-1">' +
                '<button type="button" class="btn btn-sm btn-primary w-100" id="btnAddTraining" title="Add">' +
                '<i class="bi bi-plus-lg"></i></button></div>' +
                '</div></div>';

        container.innerHTML = html;

        // Wire the training-name typeahead. No hidden id — training
        // records store a free-form string, not an FK; the typeahead
        // is purely a consistency aid.
        _wireTrainingTypeahead({
            input:   document.getElementById('addTrainingName'),
            suggest: document.getElementById('addTrainingSuggest'),
            allowNew: true
        });

        // Bind add training button
        var addBtn = document.getElementById('btnAddTraining');
        if (addBtn) {
            addBtn.addEventListener('click', function () {
                addTrainingRecord(memberId);
            });
        }

        // Bind remove training buttons
        var removeBtns = container.querySelectorAll('.remove-training-btn');
        for (var k = 0; k < removeBtns.length; k++) {
            (function (btn) {
                btn.addEventListener('click', function () {
                    removeTrainingRecord(parseInt(btn.getAttribute('data-training-id'), 10));
                });
            })(removeBtns[k]);
        }
    }

    // ── Add Training Record ───────────────────────────────────
    function addTrainingRecord(memberId) {
        var nameEl = document.getElementById('addTrainingName');
        var typeEl = document.getElementById('addTrainingType');
        var dateEl = document.getElementById('addTrainingDate');
        var hoursEl = document.getElementById('addTrainingHours');
        var resultEl = document.getElementById('addTrainingResult');

        var name = nameEl ? nameEl.value.trim() : '';
        if (!name) {
            showAlert('Please enter a training name.');
            return;
        }

        var body = {
            member_id: memberId,
            training_name: name,
            training_type: typeEl ? typeEl.value : 'Course',
            training_date: dateEl ? dateEl.value : null,
            hours: hoursEl && hoursEl.value ? parseFloat(hoursEl.value) : null,
            result: resultEl ? resultEl.value : 'Completed'
        };

        fetch('api/training.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            if (data.error) {
                showAlert('Failed to add training: ' + data.error);
                return;
            }
            showAlert('Training record added.', 'success');
            selectMember(memberId);
        })
        .catch(function (err) {
            showAlert('Failed to add training: ' + err.message);
        });
    }

    // ── Remove Training Record ────────────────────────────────
    function removeTrainingRecord(id) {
        if (!confirm('Remove this training record?')) return;

        fetch('api/training.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'delete', id: id })
        })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            if (data.error) {
                showAlert('Failed to remove: ' + data.error);
                return;
            }
            showAlert('Training record removed.', 'success');
            if (selectedId) selectMember(selectedId);
        })
        .catch(function (err) {
            showAlert('Failed to remove: ' + err.message);
        });
    }

    // ── Load & Render Member Vehicles ───────────────────────────
    function loadMemberVehicles(memberId) {
        var container = document.getElementById('detailVehicles');
        var countEl = document.getElementById('vehicleCountBadge');
        if (!container) return;

        fetch('api/vehicles.php?member_id=' + memberId, { credentials: 'same-origin' })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                var vehicles = data.vehicles || [];
                if (countEl) countEl.textContent = vehicles.length;

                if (vehicles.length === 0) {
                    container.innerHTML = '<div class="text-body-secondary">No vehicles on file. ' +
                        '<a href="vehicles.php">Manage vehicles</a></div>';
                    return;
                }

                var statusColors = { Active: 'success', 'Out of Service': 'warning', Disposed: 'secondary' };
                var html = '<table class="table table-sm table-borderless mb-0"><tbody>';
                for (var i = 0; i < vehicles.length; i++) {
                    var v = vehicles[i];
                    var desc = [v.year, v.make, v.model].filter(Boolean).join(' ') || '—';
                    var privacyIcon = v.redacted
                        ? '<i class="bi bi-lock-fill text-warning" title="Private - fields hidden"></i>'
                        : '';
                    html += '<tr>' +
                        '<td class="fw-semibold font-monospace">' + esc(v.callsign || '—') + '</td>' +
                        '<td>' + esc(desc) + (v.color ? ' <small class="text-body-secondary">(' + esc(v.color) + ')</small>' : '') + '</td>' +
                        '<td><span class="badge bg-' + (statusColors[v.status] || 'secondary') + ' bg-opacity-75" style="font-size:0.6rem;">' +
                            esc(v.status) + '</span></td>' +
                        '<td>' + privacyIcon + '</td>' +
                        '</tr>';
                }
                html += '</tbody></table>' +
                    '<div class="text-end"><a href="vehicles.php" class="small text-decoration-none">Manage vehicles <i class="bi bi-arrow-right"></i></a></div>';
                container.innerHTML = html;
            })
            .catch(function () {
                container.innerHTML = '<div class="text-body-secondary">Failed to load vehicles.</div>';
                if (countEl) countEl.textContent = '?';
            });
    }

    // ── Load & Render Member Equipment ───────────────────────────
    function loadMemberEquipment(memberId) {
        var container = document.getElementById('detailEquipment');
        var countEl = document.getElementById('equipmentCountBadge');
        if (!container) return;

        // Fetch both assigned equipment and personal equipment in parallel
        Promise.all([
            fetch('api/equipment.php?member_id=' + memberId, { credentials: 'same-origin' }).then(function (r) { return r.json(); }),
            fetch('api/equipment.php?owner_member_id=' + memberId, { credentials: 'same-origin' }).then(function (r) { return r.json(); })
        ])
        .then(function (results) {
            var assigned = results[0].equipment || [];
            var personal = results[1].equipment || [];

            // Deduplicate (personal items that are also assigned)
            var assignedIds = {};
            for (var a = 0; a < assigned.length; a++) assignedIds[assigned[a].id] = true;
            var personalOnly = [];
            for (var p = 0; p < personal.length; p++) {
                if (!assignedIds[personal[p].id]) personalOnly.push(personal[p]);
            }

            var total = assigned.length + personalOnly.length;
            if (countEl) countEl.textContent = total;

            if (total === 0) {
                container.innerHTML = '<div class="text-body-secondary">No equipment on file. ' +
                    '<a href="equipment.php">Manage equipment</a></div>';
                return;
            }

            var statusColors = { Available: 'success', 'Checked Out': 'warning', 'In Repair': 'info', Lost: 'danger', Disposed: 'secondary' };
            var html = '';

            // Assigned equipment section
            if (assigned.length > 0) {
                html += '<div class="fw-semibold text-body-secondary mb-1"><i class="bi bi-box-arrow-right me-1"></i>Checked Out (' + assigned.length + ')</div>';
                html += '<table class="table table-sm table-borderless mb-2"><tbody>';
                for (var i = 0; i < assigned.length; i++) {
                    var e = assigned[i];
                    html += '<tr>' +
                        '<td><i class="bi ' + esc(e.type_icon || 'bi-box') + ' me-1"></i>' + esc(e.name) + '</td>' +
                        '<td class="text-body-secondary">' + esc(e.type_name || '') + '</td>' +
                        '<td><span class="badge bg-' + (statusColors[e.status] || 'secondary') + '" style="font-size:0.6rem;">' +
                            esc(e.status) + '</span></td>' +
                        '</tr>';
                }
                html += '</tbody></table>';
            }

            // Personal equipment section
            if (personalOnly.length > 0) {
                html += '<div class="fw-semibold text-body-secondary mb-1"><i class="bi bi-person me-1"></i>Personal Equipment (' + personalOnly.length + ')</div>';
                html += '<table class="table table-sm table-borderless mb-2"><tbody>';
                for (var j = 0; j < personalOnly.length; j++) {
                    var pe = personalOnly[j];
                    var availIcon = parseInt(pe.available_for_events, 10)
                        ? '<i class="bi bi-calendar-check text-success" title="Available for events"></i>'
                        : '';
                    html += '<tr>' +
                        '<td><i class="bi ' + esc(pe.type_icon || 'bi-box') + ' me-1"></i>' + esc(pe.name) + '</td>' +
                        '<td class="text-body-secondary">' + esc(pe.type_name || '') + '</td>' +
                        '<td>' + availIcon + '</td>' +
                        '</tr>';
                }
                html += '</tbody></table>';
            }

            html += '<div class="text-end"><a href="equipment.php" class="small text-decoration-none">Manage equipment <i class="bi bi-arrow-right"></i></a></div>';
            container.innerHTML = html;
        })
        .catch(function () {
            container.innerHTML = '<div class="text-body-secondary">Failed to load equipment.</div>';
            if (countEl) countEl.textContent = '?';
        });
    }

    // ── API POST to specific URL (for ICS positions API) ──────
    /*
     * 2026-06-11 polish — surface the operational gap between a member's
     * comm identifier and the unit-side location binding.
     *
     * The comm_identifiers table captures "Allen Edward uses OwnTracks
     * with TID AE." But for OwnTracks position reports to actually
     * appear on the map as Allen Edward's unit moving around, the
     * SAME TID also needs to live in unit_location_bindings against
     * a specific unit (responder_id). That's done on the Unit Detail
     * page → Location Sources card.
     *
     * This hint:
     *   - Names the provider (OwnTracks, APRS, etc.)
     *   - Lists the member's currently-assigned units with direct
     *     links to each unit-edit page anchored to the Location
     *     Sources section.
     *   - If the member has no active unit assignment, says so and
     *     points to the Units list instead.
     *
     * Banner auto-dismisses after 60 seconds. Admin can click X early.
     */
    function showLocationBindingHint(data, payload) {
        var providerName = {
            owntracks:  'OwnTracks',
            aprs:       'APRS',
            meshtastic: 'Meshtastic',
            dmr:        'DMR'
        }[data.provider_code] || data.provider_code;

        // Pull the identifier value out of the payload so the admin
        // sees exactly what they need to bind on the unit side.
        var identValue = '';
        var v = payload.values_json || {};
        identValue = v.tracker_id || v.callsign || v.radioid || v.mqtt_topic || '';

        // Build the unit-link list.
        var unitLinks = '';
        if (data.assigned_units && data.assigned_units.length > 0) {
            for (var i = 0; i < data.assigned_units.length; i++) {
                var u = data.assigned_units[i];
                unitLinks += '<a href="unit-edit.php?id=' + u.id +
                    '#location-sources" class="btn btn-sm btn-outline-primary me-1 mb-1">' +
                    '<i class="bi bi-geo-alt me-1"></i>' + esc(u.name) +
                    ' — open Location Sources</a>';
            }
        }

        // Compose the banner HTML.
        var hint = '<div class="alert alert-info alert-dismissible fade show mt-2" role="alert" id="locBindingHint">' +
            '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>' +
            '<strong><i class="bi bi-info-circle me-1"></i>Identifier saved — one more step.</strong>' +
            '<p class="mb-2 mt-1 small">The ' + esc(providerName) + ' identifier' +
            (identValue ? ' <code>' + esc(identValue) + '</code>' : '') +
            ' is now on file. For position reports to appear on the map, ' +
            'the same identifier also needs to be bound to a Unit\'s ' +
            '<em>Location Sources</em> card.</p>';

        if (unitLinks) {
            hint += '<div class="small mb-1 text-body-secondary">' +
                'This member is currently assigned to:</div>' +
                '<div>' + unitLinks + '</div>';
        } else {
            hint += '<div class="small text-body-secondary">' +
                'This member isn\'t currently assigned to any active unit. ' +
                'Once assigned, open that <a href="units.php">unit</a>\'s ' +
                'Location Sources card to add the binding.</div>';
        }
        hint += '</div>';

        // Inject into the comm-identifiers panel area on the roster page.
        // Look for the comm-identifier list container; fall back to the
        // member detail card if missing.
        var container = document.getElementById('commIdentifiersList') ||
            document.getElementById('memberDetailPanel') ||
            document.body;

        // Remove any prior hint so we don't stack.
        var prior = document.getElementById('locBindingHint');
        if (prior) prior.parentNode.removeChild(prior);

        var wrap = document.createElement('div');
        wrap.innerHTML = hint;
        container.appendChild(wrap.firstChild);

        // Auto-dismiss after 60 seconds.
        setTimeout(function () {
            var el = document.getElementById('locBindingHint');
            if (el && el.parentNode) el.parentNode.removeChild(el);
        }, 60000);
    }

    function apiPostUrl(url, body, callback) {
        body.csrf_token = document.getElementById('csrfToken') ? document.getElementById('csrfToken').value : '';
        fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.error) callback(new Error(data.error));
            else callback(null, data);
        })
        .catch(function (err) { callback(err); });
    }

    // ── Show Edit Form ───────────────────────────────────────────
    function showEditForm(member) {
        $detailEmpty.classList.add('d-none');
        $detailView.classList.add('d-none');
        $editView.classList.remove('d-none');

        var isNew = !member;
        document.getElementById('editFormTitle').innerHTML =
            '<i class="bi bi-' + (isNew ? 'person-plus' : 'pencil-square') + ' me-1"></i>' +
            (isNew ? 'New Member' : 'Edit Member');

        document.getElementById('editMemberId').value = member ? member.id : '';
        document.getElementById('editFirstName').value = member ? (member.first_name || '') : '';
        document.getElementById('editLastName').value = member ? (member.last_name || '') : '';
        document.getElementById('editMiddleName').value = member ? (member.middle_name || '') : '';
        document.getElementById('editTitle').value = member ? (member.title || '') : '';
        // Render callsigns in edit mode
        renderCallsigns(_memberCallsigns || [], member ? member.id : 0);
        document.getElementById('editDOB').value = member ? (member.dob || '') : '';
        document.getElementById('editEmail').value = member ? (member.email || '') : '';
        document.getElementById('editPhoneHome').value = member ? (member.phone_home || '') : '';
        document.getElementById('editPhoneWork').value = member ? (member.phone_work || '') : '';
        document.getElementById('editPhoneCell').value = member ? (member.phone_cell || '') : '';
        document.getElementById('editStreet').value = member ? (member.street || '') : '';
        document.getElementById('editCity').value = member ? (member.city || '') : '';
        // Issue #42: State is a DB-backed <select>; inject legacy value if absent.
        if (window.TCADStates) {
            window.TCADStates.setValue(document.getElementById('editState'), member ? (member.state || '') : '');
        } else {
            document.getElementById('editState').value = member ? (member.state || '') : '';
        }
        document.getElementById('editZip').value = member ? (member.zip || '') : '';
        // Phase 99i (Billy beta 2026-06-29) — county field. Defensive
        // null-guard so a missing editCounty element doesn't throw.
        var cnt = document.getElementById('editCounty');
        if (cnt) cnt.value = member ? (member.county || '') : '';
        document.getElementById('editType').value = member ? (member.member_type_id || '') : '';
        document.getElementById('editStatus').value = member ? (member.member_status_id || '') : '';
        document.getElementById('editTeam').value = member ? (member.team_id || '') : '';
        document.getElementById('editAvailable').value = member ? (member.available || 'Yes') : 'Yes';
        document.getElementById('editJoinDate').value = member ? (member.join_date || '') : '';
        document.getElementById('editMembershipDue').value = member ? (member.membership_due || '') : '';
        document.getElementById('editEmergencyContact').value = member ? (member.emergency_contact || '') : '';
        document.getElementById('editEmergencyPhone').value = member ? (member.emergency_phone || '') : '';
        document.getElementById('editEmergencyRelation').value = member ? (member.emergency_relation || '') : '';
        document.getElementById('editMedicalInfo').value = member ? (member.medical_info || '') : '';
        document.getElementById('editNotes').value = member ? (member.notes || '') : '';

        // PRE-RELEASE-FIXES #19 — photo upload (only available after first save).
        var photoIdEl = document.getElementById('editPhotoFileId');
        var photoImgEl = document.getElementById('editPhotoPreview');
        var photoControlsEl = document.getElementById('editPhotoControls');
        if (photoIdEl && photoImgEl && photoControlsEl) {
            var fid = member ? (member.photo_file_id || '') : '';
            photoIdEl.value = fid || '';
            photoImgEl.src  = fid ? ('api/upload.php?file_id=' + encodeURIComponent(fid)) : '';
            photoImgEl.classList.toggle('d-none', !fid);
            // Photo upload only works against an existing member id
            photoControlsEl.classList.toggle('d-none', !(member && member.id));
        }

        // Focus first field
        setTimeout(function () {
            document.getElementById('editFirstName').focus();
        }, 100);
    }

    // ── Save Member ──────────────────────────────────────────────
    function saveMember() {
        var firstName = document.getElementById('editFirstName').value.trim();
        var lastName  = document.getElementById('editLastName').value.trim();

        if (!firstName || !lastName) {
            showAlert('First name and last name are required.');
            return;
        }

        // Note: PRE-RELEASE-FIXES #16 — never include `callsign: ''` here.
        // Callsigns are managed via the member_callsigns admin tool, and the
        // server now treats absent keys as "preserve existing". Sending an
        // empty callsign would clobber the value populated by the FCC tools.
        var photoEl = document.getElementById('editPhotoFileId');
        var data = {
            // CSRF token — required by api/members.php save handler.
            // Beta tester (a beta tester, 2026-06-25) hit
            // "Failed to save member: Invalid CSRF token" because this
            // field was missing from the payload.
            csrf_token:         csrfToken(),
            id:                 document.getElementById('editMemberId').value || 0,
            first_name:         firstName,
            last_name:          lastName,
            middle_name:        document.getElementById('editMiddleName').value.trim(),
            title:              document.getElementById('editTitle').value.trim(),
            dob:                document.getElementById('editDOB').value,
            email:              document.getElementById('editEmail').value.trim(),
            phone_home:         document.getElementById('editPhoneHome').value.trim(),
            phone_work:         document.getElementById('editPhoneWork').value.trim(),
            phone_cell:         document.getElementById('editPhoneCell').value.trim(),
            street:             document.getElementById('editStreet').value.trim(),
            city:               document.getElementById('editCity').value.trim(),
            county:             (document.getElementById('editCounty') || { value: '' }).value.trim(),
            state:              document.getElementById('editState').value.trim(),
            zip:                document.getElementById('editZip').value.trim(),
            member_type_id:     document.getElementById('editType').value || null,
            member_status_id:   document.getElementById('editStatus').value || null,
            team_id:            document.getElementById('editTeam').value || null,
            available:          document.getElementById('editAvailable').value,
            join_date:          document.getElementById('editJoinDate').value,
            membership_due:     document.getElementById('editMembershipDue').value,
            emergency_contact:  document.getElementById('editEmergencyContact').value.trim(),
            emergency_phone:    document.getElementById('editEmergencyPhone').value.trim(),
            emergency_relation: document.getElementById('editEmergencyRelation').value.trim(),
            medical_info:       document.getElementById('editMedicalInfo').value.trim(),
            notes:              document.getElementById('editNotes').value.trim(),
            photo_file_id:      photoEl ? (photoEl.value || null) : null
        };

        apiPost(data, function (err, result) {
            if (err) {
                showAlert('Failed to save member: ' + err.message);
                return;
            }
            selectedId = result.id || selectedId;

            // Phase 45 — drain the pending-callsigns buffer. When the
            // admin used FCC amateur lookup or GMRS lookup on a NEW
            // member (id=0), the callsign rows were queued instead of
            // dropped. Now that we have a real member id, persist them.
            _flushPendingCallsigns(selectedId, function (savedCount, errors) {
                var msg = 'Member saved.';
                if (savedCount > 0) msg += ' ' + savedCount + ' callsign(s) attached.';
                if (errors.length > 0) {
                    showAlert(msg + ' Some callsigns failed:\n - ' + errors.join('\n - '), 'warning');
                } else {
                    showAlert(msg, 'success');
                }
                // Reload list and detail
                loadMembers();
                setTimeout(function () { selectMember(selectedId); }, 300);
            });
        });
    }

    // ── Delete Member ────────────────────────────────────────────
    function deleteMember(id) {
        if (!confirm('Are you sure you want to delete this member? This cannot be undone.')) return;

        apiPost({ action: 'delete', id: id }, function (err) {
            if (err) {
                showAlert('Failed to delete member: ' + err.message);
                return;
            }
            showAlert('Member deleted.', 'success');
            selectedId = null;
            $detailView.classList.add('d-none');
            $editView.classList.add('d-none');
            $detailEmpty.classList.remove('d-none');
            loadMembers();
        });
    }

    // ── Bulk roster removal (GH #55 follow-on, Billy/K9OH 2026-07-04) ──
    function updateBulkBar() {
        var bar = document.getElementById('rosterBulkBar');
        if (!bar) return;
        var n = Object.keys(bulkSelected).length;
        var cnt = document.getElementById('rosterBulkCount');
        if (cnt) cnt.textContent = n + ' selected';
        if (n > 0) { bar.classList.remove('d-none'); bar.classList.add('d-flex'); }
        else { bar.classList.add('d-none'); bar.classList.remove('d-flex'); }
        // Sync the select-all header checkbox against the currently visible rows.
        var all = document.querySelectorAll('.roster-sel-cb');
        var checked = document.querySelectorAll('.roster-sel-cb:checked');
        var selAll = document.getElementById('rosterSelectAll');
        if (selAll) {
            selAll.checked = (all.length > 0 && checked.length === all.length);
            selAll.indeterminate = (checked.length > 0 && checked.length < all.length);
        }
    }

    function doBulkDelete() {
        var ids = Object.keys(bulkSelected)
            .map(function (x) { return parseInt(x, 10); })
            .filter(function (x) { return x > 0; });
        if (!ids.length) return;
        apiPost({ action: 'bulk_delete', ids: ids }, function (err, resp) {
            if (err) { showAlert('Bulk delete failed: ' + err.message); return; }
            var d = (resp && resp.deleted) || 0;
            var f = (resp && resp.failed && resp.failed.length) || 0;
            showAlert('Removed ' + d + ' member' + (d === 1 ? '' : 's')
                + (f ? ' (' + f + ' failed)' : '') + '.', f ? 'warning' : 'success');
            // If the open member was among those deleted, clear the detail panel.
            if (selectedId && ids.indexOf(parseInt(selectedId, 10)) !== -1) {
                selectedId = null;
                $detailView.classList.add('d-none');
                $editView.classList.add('d-none');
                $detailEmpty.classList.remove('d-none');
            }
            bulkSelected = {};
            loadMembers();
        });
    }

    function initBulkRoster() {
        var flag = document.getElementById('canBulkDeleteMembers');
        canBulkDeleteMembers = !!(flag && flag.value === '1');
        if (!canBulkDeleteMembers) return;

        // Row checkbox toggles (delegated) — must not also select the member.
        var tbody = document.getElementById('rosterBody');
        if (tbody) {
            tbody.addEventListener('click', function (e) {
                var cb = e.target;
                if (cb && cb.classList && cb.classList.contains('roster-sel-cb')) {
                    e.stopPropagation();
                    var id = cb.getAttribute('data-id');
                    if (cb.checked) { bulkSelected[id] = true; } else { delete bulkSelected[id]; }
                    updateBulkBar();
                }
            });
        }
        var selAll = document.getElementById('rosterSelectAll');
        if (selAll) selAll.addEventListener('click', function (e) {
            e.stopPropagation();
            var on = selAll.checked;
            var cbs = document.querySelectorAll('.roster-sel-cb');
            for (var i = 0; i < cbs.length; i++) {
                cbs[i].checked = on;
                var id = cbs[i].getAttribute('data-id');
                if (on) { bulkSelected[id] = true; } else { delete bulkSelected[id]; }
            }
            updateBulkBar();
        });
        var clearBtn = document.getElementById('btnRosterBulkClear');
        if (clearBtn) clearBtn.addEventListener('click', function () {
            bulkSelected = {};
            var cbs = document.querySelectorAll('.roster-sel-cb');
            for (var i = 0; i < cbs.length; i++) cbs[i].checked = false;
            updateBulkBar();
        });
        var modalEl = document.getElementById('rosterBulkDeleteModal');
        var modal = (modalEl && window.bootstrap) ? new bootstrap.Modal(modalEl) : null;
        var delBtn = document.getElementById('btnRosterBulkDelete');
        if (delBtn) delBtn.addEventListener('click', function () {
            var n = Object.keys(bulkSelected).length;
            if (!n) return;
            var c1 = document.getElementById('rosterBulkModalCount');
            var c2 = document.getElementById('rosterBulkModalCount2');
            if (c1) c1.textContent = n;
            if (c2) c2.textContent = n;
            if (modal) { modal.show(); }
            else if (confirm('Delete ' + n + ' selected member(s)?')) { doBulkDelete(); }
        });
        var confirmBtn = document.getElementById('btnRosterBulkDeleteConfirm');
        if (confirmBtn) confirmBtn.addEventListener('click', function () {
            if (modal) modal.hide();
            doBulkDelete();
        });
    }

    // ── Photo upload (PRE-RELEASE-FIXES #19) ─────────────────────
    function uploadMemberPhoto(file, memberId, callback) {
        if (!memberId) {
            showAlert('Save the member first, then add a photo.');
            return;
        }
        var validTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        if (validTypes.indexOf(file.type) === -1) {
            showAlert('Photo must be JPEG, PNG, WebP, or GIF.');
            return;
        }
        if (file.size > 2 * 1024 * 1024) {
            showAlert('Photo too large (max 2 MB).');
            return;
        }

        var csrfMeta = document.querySelector('meta[name="csrf-token"]');
        var csrf = csrfMeta ? csrfMeta.getAttribute('content') : '';

        var fd = new FormData();
        fd.append('csrf_token', csrf);
        fd.append('entity', 'member');
        fd.append('entity_id', memberId);
        fd.append('description', 'Member photo');
        fd.append('file', file);

        fetch('api/upload.php', {
            method: 'POST',
            credentials: 'same-origin',
            body: fd
        }).then(function (r) {
            return r.json().then(function (data) { return { ok: r.ok, data: data }; });
        }).then(function (res) {
            if (!res.ok || !res.data || !res.data.success) {
                var msg = (res.data && res.data.error) || ('HTTP ' + (res.status || '?'));
                showAlert('Photo upload failed: ' + msg);
                return;
            }
            callback(res.data.file_id);
        }).catch(function (err) {
            showAlert('Photo upload failed: ' + err.message);
        });
    }

    // ── Event Binding ────────────────────────────────────────────
    function bindEvents() {

        // Photo file input
        var photoInput = document.getElementById('editPhotoInput');
        if (photoInput) {
            photoInput.addEventListener('change', function () {
                var file = photoInput.files && photoInput.files[0];
                if (!file) return;
                var memberId = document.getElementById('editMemberId').value;
                uploadMemberPhoto(file, memberId, function (fileId) {
                    document.getElementById('editPhotoFileId').value = fileId;
                    var img = document.getElementById('editPhotoPreview');
                    img.src = 'api/upload.php?file_id=' + encodeURIComponent(fileId)
                        + '&t=' + Date.now(); // cache-bust
                    img.classList.remove('d-none');
                    showAlert('Photo uploaded — click Save to attach it to the member.', 'success');
                });
                photoInput.value = ''; // allow re-selecting the same file
            });
        }
        var photoRemoveBtn = document.getElementById('editPhotoRemove');
        if (photoRemoveBtn) {
            photoRemoveBtn.addEventListener('click', function () {
                document.getElementById('editPhotoFileId').value = '';
                var img = document.getElementById('editPhotoPreview');
                img.src = '';
                img.classList.add('d-none');
            });
        }

        // Table row click
        $tbody.addEventListener('click', function (e) {
            var row = e.target.closest('tr.roster-row');
            if (!row) return;
            var id = row.getAttribute('data-id');
            if (id) selectMember(parseInt(id, 10));
        });

        // Sort headers
        var headers = document.querySelectorAll('#rosterTable th.sortable');
        for (var i = 0; i < headers.length; i++) {
            (function (th) {
                th.addEventListener('click', function () {
                    var field = th.getAttribute('data-sort');
                    if (sortField === field) {
                        sortDir = sortDir === 'asc' ? 'desc' : 'asc';
                    } else {
                        sortField = field;
                        sortDir = 'asc';
                    }
                    renderTable();
                });
            })(headers[i]);
        }

        // Search with debounce
        $searchInput.addEventListener('input', function () {
            clearTimeout(searchTimer);
            var val = $searchInput.value;
            $clearSearch.classList.toggle('d-none', !val);
            searchTimer = setTimeout(function () {
                searchTerm = val;
                renderTable();
            }, 300);
        });

        // Clear search
        $clearSearch.addEventListener('click', function () {
            $searchInput.value = '';
            searchTerm = '';
            $clearSearch.classList.add('d-none');
            renderTable();
            $searchInput.focus();
        });

        // Filter buttons (delegated)
        document.querySelector('.card-body').addEventListener('click', function (e) {
            var btn = e.target.closest('.filter-btn');
            if (!btn) return;

            var filterGroup = btn.getAttribute('data-filter');
            var filterValue = btn.getAttribute('data-value');

            // Deactivate siblings of same group, activate this one
            var siblings = btn.closest('.d-flex').querySelectorAll('.filter-btn[data-filter="' + filterGroup + '"]');
            // Also include the "All" button for this group
            var allBtn = btn.closest('.d-flex').querySelector('.filter-btn[data-filter="' + filterGroup + '"][data-value="all"]');
            // Deactivate all in group
            for (var j = 0; j < siblings.length; j++) {
                siblings[j].classList.remove('active');
            }
            if (allBtn) allBtn.classList.remove('active');
            btn.classList.add('active');

            if (filterGroup === 'status') filterStatus = filterValue;
            else if (filterGroup === 'team') filterTeam = filterValue;
            else if (filterGroup === 'type') filterType = filterValue;

            renderTable();
        });

        // New member button
        document.getElementById('btnNewMember').addEventListener('click', function () {
            selectedId = null;
            renderTable();
            showEditForm(null);
        });

        // Edit member button
        document.getElementById('btnEditMember').addEventListener('click', function () {
            var memberJson = this.getAttribute('data-member');
            if (memberJson) {
                try {
                    showEditForm(JSON.parse(memberJson));
                } catch (ex) {
                    showAlert('Error loading member data for edit.');
                }
            }
        });

        // Delete member button
        document.getElementById('btnDeleteMember').addEventListener('click', function () {
            var id = this.getAttribute('data-id');
            if (id) deleteMember(parseInt(id, 10));
        });

        // Save button
        document.getElementById('btnSaveMember').addEventListener('click', function () {
            saveMember();
        });

        // ── Time Log (item #21) ────────────────────────────────────
        var btnLog = document.getElementById('btnLogTime');
        if (btnLog) {
            btnLog.addEventListener('click', function () { openLogTimeModal(null); });
        }
        var btnSaveTime = document.getElementById('btnSaveTimeEntry');
        if (btnSaveTime) {
            btnSaveTime.addEventListener('click', saveTimeEntry);
        }
        // Delegated handlers — entries render dynamically
        document.addEventListener('click', function (ev) {
            var editBtn = ev.target.closest && ev.target.closest('.edit-time-btn');
            if (editBtn) {
                var id = parseInt(editBtn.getAttribute('data-id'), 10);
                fetch('api/time-entries.php?id=' + id, { credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        if (d.entry) {
                            openLogTimeModal(d.entry);
                            var modal = new bootstrap.Modal(document.getElementById('logTimeModal'));
                            modal.show();
                        }
                    });
                return;
            }
            var delBtn = ev.target.closest && ev.target.closest('.delete-time-btn');
            if (delBtn) {
                deleteTimeEntry(delBtn.getAttribute('data-id'));
                return;
            }
            var apprBtn = ev.target.closest && ev.target.closest('.approve-time-btn');
            if (apprBtn) {
                approveTimeEntry(parseInt(apprBtn.getAttribute('data-id'), 10), 'approve');
                return;
            }
            var rejBtn = ev.target.closest && ev.target.closest('.reject-time-btn');
            if (rejBtn) {
                if (confirm('Reject this time entry?')) {
                    approveTimeEntry(parseInt(rejBtn.getAttribute('data-id'), 10), 'reject');
                }
            }
        });

        // Cancel edit button
        document.getElementById('btnCancelEdit').addEventListener('click', function () {
            $editView.classList.add('d-none');
            if (selectedId) {
                selectMember(selectedId);
            } else {
                $detailEmpty.classList.remove('d-none');
            }
        });

        // Theme toggle
        var themeButtons = document.querySelectorAll('#themeToggle button');
        for (var t = 0; t < themeButtons.length; t++) {
            (function (btn) {
                btn.addEventListener('click', function () {
                    var theme = btn.getAttribute('data-theme');
                    var bsTheme = (theme === 'Night') ? 'dark' : 'light';
                    document.documentElement.setAttribute('data-bs-theme', bsTheme);
                    document.querySelector('.navbar').setAttribute('data-bs-theme', bsTheme);

                    // Update button styles
                    for (var b = 0; b < themeButtons.length; b++) {
                        var bt = themeButtons[b];
                        var t2 = bt.getAttribute('data-theme');
                        bt.className = 'btn ' + (t2 === theme
                            ? (t2 === 'Day' ? 'btn-warning' : 'btn-primary')
                            : 'btn-outline-secondary');
                    }

                    // Save to server (CSRF per F-012)
                    var csrfMeta2 = document.querySelector('meta[name="csrf-token"]');
                    var csrf2 = csrfMeta2 ? csrfMeta2.getAttribute('content') : '';
                    fetch('api/theme.php', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-Token': csrf2
                        },
                        body: JSON.stringify({ theme: theme, csrf_token: csrf2 })
                    }).catch(function () {});
                });
            })(themeButtons[t]);
        }

        // Keyboard: Escape to cancel edit
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !$editView.classList.contains('d-none')) {
                $editView.classList.add('d-none');
                if (selectedId) {
                    selectMember(selectedId);
                } else {
                    $detailEmpty.classList.remove('d-none');
                }
            }
        });

        // Callsign lookup and add-row bindings are set up in renderCallsigns()

        // ── ZIP Code Lookup ─────────────────────────────────────
        var btnZipLookup = document.getElementById('btnZipLookup');
        if (btnZipLookup) {
            btnZipLookup.addEventListener('click', function () {
                doZipLookup();
            });
        }

        // Also trigger on blur/Tab out of ZIP field
        var editZip = document.getElementById('editZip');
        if (editZip) {
            editZip.addEventListener('blur', function () {
                var val = editZip.value.trim();
                // Auto-lookup if zip is 5 digits and city is empty
                if (/^\d{5}$/.test(val) && !document.getElementById('editCity').value.trim()) {
                    doZipLookup();
                }
            });
        }

        // ── FCC License Panel buttons ───────────────────────────
        var btnCloseFcc = document.getElementById('btnCloseFccPanel');
        if (btnCloseFcc) {
            btnCloseFcc.addEventListener('click', function () {
                document.getElementById('fccLicensePanel').classList.add('d-none');
            });
        }

        var btnApplyFcc = document.getElementById('btnApplyFccData');
        if (btnApplyFcc) {
            btnApplyFcc.addEventListener('click', function () {
                applyFccDataToForm();
            });
        }

        var btnGmrsLookup = document.getElementById('btnGmrsLookup');
        if (btnGmrsLookup) {
            btnGmrsLookup.addEventListener('click', function () {
                doGmrsLookup();
            });
        }

        var btnCloseGmrs = document.getElementById('btnCloseGmrsPanel');
        if (btnCloseGmrs) {
            btnCloseGmrs.addEventListener('click', function () {
                document.getElementById('gmrsResultsPanel').classList.add('d-none');
            });
        }
    }

    // ══════════════════════════════════════════════════════════════
    // PHONE NUMBER FORMATTING
    // ══════════════════════════════════════════════════════════════

    var _phoneFormat = 'off';

    function formatPhone(phone, fmt) {
        if (!phone || fmt === 'off' || !fmt) return phone;
        var digits = phone.replace(/[^0-9]/g, '');
        if (digits.length === 11 && digits.charAt(0) === '1') {
            digits = digits.substring(1);
        }
        if (digits.length !== 10) return phone;
        var area = digits.substring(0, 3);
        var pre  = digits.substring(3, 6);
        var line = digits.substring(6, 10);
        switch (fmt) {
            case 'us':   return '(' + area + ') ' + pre + '-' + line;
            case 'dash': return area + '-' + pre + '-' + line;
            case 'dots': return area + '.' + pre + '.' + line;
            default:     return phone;
        }
    }

    function bindPhoneFormatting() {
        var phoneIds = ['editPhoneHome', 'editPhoneWork', 'editPhoneCell', 'editEmergencyPhone'];
        for (var i = 0; i < phoneIds.length; i++) {
            (function (id) {
                var el = document.getElementById(id);
                if (el) {
                    el.addEventListener('blur', function () {
                        if (_phoneFormat !== 'off' && el.value.trim()) {
                            el.value = formatPhone(el.value.trim(), _phoneFormat);
                        }
                    });
                }
            })(phoneIds[i]);
        }

        // Load phone format setting from system settings
        fetch('api/config-admin.php?section=settings', { credentials: 'same-origin' })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            var settings = data && data.settings ? data.settings : {};
            if (settings.phone_format) {
                _phoneFormat = settings.phone_format;
            }
        })
        .catch(function () {});
    }

    // ══════════════════════════════════════════════════════════════
    // FCC / CALLSIGN LOOKUP
    // ══════════════════════════════════════════════════════════════

    var _lastFccData = null; // Cached for "Apply to Form"

    // Phase 45 — pending callsigns buffer. FCC amateur lookups and GMRS
    // selections used to silently drop the callsign on the floor when the
    // member hadn't been saved yet ("Save the member first..." toast that
    // most admins missed). Now we queue the save payload in this array,
    // then drain it inside saveMember()'s success callback so the callsigns
    // get persisted with the freshly-minted member id.
    var _pendingCallsigns = [];
    function _queueCallsign(payload) {
        // Replace any pending entry for the same callsign so re-clicking
        // Apply on the same lookup doesn't double-insert later.
        _pendingCallsigns = _pendingCallsigns.filter(function (p) {
            return (p.callsign || '').toUpperCase() !== (payload.callsign || '').toUpperCase();
        });
        _pendingCallsigns.push(payload);
    }
    function _flushPendingCallsigns(memberId, done) {
        if (!_pendingCallsigns.length) { if (done) done(0, []); return; }
        var queue = _pendingCallsigns.slice();
        _pendingCallsigns = [];
        var saved = 0;
        var errors = [];
        var remaining = queue.length;
        queue.forEach(function (p) {
            var payload = Object.assign({}, p, { member_id: memberId, action: 'save_callsign' });
            apiPost(payload, function (err) {
                if (err) errors.push((p.callsign || '?') + ': ' + (err.message || 'save failed'));
                else saved++;
                remaining--;
                if (remaining === 0 && done) done(saved, errors);
            });
        });
    }

    /**
     * Look up amateur radio callsign
     */
    function doCallsignLookup() {
        var input = document.getElementById('addCallsignInput');
        if (!input) return;
        var callsign = (input.value || '').toUpperCase().replace(/[^A-Z0-9]/g, '');
        if (!callsign || callsign.length < 3) {
            showCallsignStatus('Enter a valid callsign (3+ characters)', 'text-warning');
            return;
        }
        input.value = callsign;

        showCallsignStatus('<i class="bi bi-hourglass-split"></i> Looking up ' + esc(callsign) + '...', 'text-info');

        fetch('api/callsign-lookup.php?action=callsign&q=' + encodeURIComponent(callsign), {
            credentials: 'same-origin'
        })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            if (data.error) {
                showCallsignStatus('<i class="bi bi-exclamation-triangle"></i> ' + esc(data.error), 'text-danger');
                return;
            }

            if (data.provider === 'disabled') {
                showCallsignStatus('<i class="bi bi-slash-circle"></i> Callsign lookup is disabled', 'text-muted');
                return;
            }

            if (!data.found) {
                showCallsignStatus('<i class="bi bi-x-circle"></i> No record found for ' + esc(callsign), 'text-warning');
                return;
            }

            _lastFccData = data;
            // Auto-set type dropdown to amateur
            var typeSelect = document.getElementById('addCallsignType');
            if (typeSelect) typeSelect.value = 'amateur';

            showCallsignStatus('<i class="bi bi-check-circle text-success"></i> Found — ' +
                esc(data.first_name) + ' ' + esc(data.last_name) +
                (data.oper_class ? ' (Class ' + esc(data.oper_class) + ')' : ''),
                'text-success');

            showFccLicensePanel(data);
        })
        .catch(function (err) {
            showCallsignStatus('<i class="bi bi-wifi-off"></i> Lookup failed: ' + esc(err.message), 'text-danger');
        });
    }

    function showCallsignStatus(html, cssClass) {
        var el = document.getElementById('callsignResult');
        el.innerHTML = '<small class="' + (cssClass || '') + '">' + html + '</small>';
        el.classList.remove('d-none');
    }

    /**
     * Display FCC license info panel with all details including expiry
     */
    function showFccLicensePanel(data) {
        var panel = document.getElementById('fccLicensePanel');
        var body  = document.getElementById('fccLicenseBody');

        // Compute expiry status
        var expiryHtml = '';
        if (data.expiry_date) {
            var expDate = new Date(data.expiry_date);
            var now = new Date();
            var daysLeft = Math.round((expDate - now) / 86400000);
            var badgeClass = 'bg-success';
            var statusText = daysLeft + ' days remaining';

            if (daysLeft < 0) {
                badgeClass = 'bg-danger';
                statusText = 'EXPIRED ' + Math.abs(daysLeft) + ' days ago';
            } else if (daysLeft < 90) {
                badgeClass = 'bg-warning text-dark';
                statusText = 'Expires in ' + daysLeft + ' days';
            } else if (daysLeft < 365) {
                badgeClass = 'bg-info text-dark';
                statusText = daysLeft + ' days remaining';
            }

            expiryHtml = '<span class="badge ' + badgeClass + '">' + esc(statusText) + '</span>';
        }

        // Operator class label
        var classLabels = { T: 'Technician', G: 'General', E: 'Extra', A: 'Advanced', N: 'Novice' };
        var classLabel = classLabels[data.oper_class] || data.oper_class || 'Unknown';

        var html = '<div class="row g-1">';

        // Name
        html += '<div class="col-6"><strong>Name:</strong> ' +
            esc(data.first_name) + ' ' +
            (data.middle_initial ? esc(data.middle_initial) + '. ' : '') +
            esc(data.last_name) +
            (data.suffix ? ' ' + esc(data.suffix) : '') + '</div>';

        // Class
        html += '<div class="col-6"><strong>Class:</strong> ' + esc(classLabel) + '</div>';

        // Address
        if (data.street || data.city) {
            html += '<div class="col-12"><strong>Address:</strong> ' +
                (data.street ? esc(data.street) + ', ' : '') +
                esc(data.city) + ', ' + esc(data.state) + ' ' + esc(data.zip) + '</div>';
        }

        // Grid square
        if (data.grid_square) {
            html += '<div class="col-6"><strong>Grid:</strong> ' + esc(data.grid_square) + '</div>';
        }

        // FRN
        if (data.frn) {
            html += '<div class="col-6"><strong>FRN:</strong> ' + esc(data.frn) + '</div>';
        }

        // Grant / Expiry dates
        if (data.grant_date) {
            html += '<div class="col-6"><strong>Granted:</strong> ' + esc(data.grant_date) + '</div>';
        }
        if (data.expiry_date) {
            html += '<div class="col-6"><strong>Expires:</strong> ' + esc(data.expiry_date) +
                ' ' + expiryHtml + '</div>';
        }

        // Provider
        html += '<div class="col-12 mt-1"><small class="text-muted">Source: ' + esc(data.provider) + '</small></div>';

        html += '</div>';

        body.innerHTML = html;
        panel.classList.remove('d-none');
    }

    /**
     * Apply cached FCC data to the form fields
     */
    function applyFccDataToForm() {
        if (!_lastFccData) return;
        var d = _lastFccData;
        var memberId = parseInt(document.getElementById('editMemberId').value, 10) || 0;

        // Issue #25 (k9oh-ares 2026-07-02, refined by Eric 2026-07-03):
        // Categorize each FCC field into one of three buckets:
        //   * empty  — current is blank        → fill silently
        //   * match  — current equals FCC data → skip silently
        //   * diff   — both populated, differ  → prompt via modal
        //
        // Only when there is at least one diff do we open the
        // fccOverwriteModal. The user sees the current + proposed
        // values and picks which to overwrite via per-field checkbox.
        // Empty fields ALWAYS fill (both silently and after the diff
        // is applied) since there is nothing to overwrite.
        //
        // The prior "always overwrite" behaviour was too aggressive —
        // a typo in an FCC record could silently clobber a hand-corrected
        // address the operator had already fixed.
        var fields = [
            ['editFirstName',  d.first_name,   'First name'],
            ['editLastName',   d.last_name,    'Last name'],
            ['editMiddleName', d.middle_initial || '', 'Middle initial'],
            ['editStreet',     d.street,       'Street'],
            ['editCity',       d.city,         'City'],
            ['editState',      d.state,        'State'],
            ['editZip',        d.zip,          'ZIP']
        ];

        var emptyFills = [];   // will fill silently
        var diffs      = [];   // will prompt

        for (var i = 0; i < fields.length; i++) {
            var el  = document.getElementById(fields[i][0]);
            var val = fields[i][1];
            var lbl = fields[i][2];
            if (!el || val == null || val === '') continue;
            var cur = el.value.trim();
            if (cur === '')      { emptyFills.push({el: el, val: val, label: lbl}); continue; }
            if (cur === val)     continue;   // exact match — nothing to do
            diffs.push({el: el, val: val, label: lbl, current: cur});
        }

        // Apply the silent fills up front — no prompt needed.
        for (var e = 0; e < emptyFills.length; e++) {
            _fccApplyOne(emptyFills[e].el, emptyFills[e].val);
        }

        var filled = emptyFills.length;

        // If there are diffs, show the confirmation modal. The apply
        // step (save callsign, GMRS lookup, alert) is deferred until
        // the user confirms — see _fccContinueAfterOverwrite().
        if (diffs.length > 0) {
            _showFccOverwriteModal(diffs, emptyFills.length, memberId);
            return;
        }
        _fccContinueAfterOverwrite(filled, memberId);
    }

    /**
     * Apply a single FCC field value with the transient green border
     * highlight used by the empty-fill and confirmed-overwrite paths.
     */
    function _fccApplyOne(el, val) {
        // Issue #42: the State field is now a <select>; route through the
        // shared helper so an FCC state code that isn't in states_translator
        // is still injected rather than silently dropped.
        if (el.tagName === 'SELECT' && window.TCADStates) {
            window.TCADStates.setValue(el, val);
        } else {
            el.value = val;
        }
        el.classList.add('border-success');
        setTimeout(function () { el.classList.remove('border-success'); }, 1500);
    }

    /**
     * Build + show the FCC-overwrite confirmation modal. Each diff row
     * is rendered as a per-field checkbox (default checked) with the
     * current + proposed values side-by-side.
     */
    function _showFccOverwriteModal(diffs, emptyFilledCount, memberId) {
        var host = document.getElementById('fccOverwriteRows');
        if (!host) {
            // Fallback: no modal in DOM — apply all diffs so we don't
            // silently drop the operator's action.
            for (var i = 0; i < diffs.length; i++) _fccApplyOne(diffs[i].el, diffs[i].val);
            _fccContinueAfterOverwrite(emptyFilledCount + diffs.length, memberId);
            return;
        }

        var html = '';
        for (var i = 0; i < diffs.length; i++) {
            var d0 = diffs[i];
            html += '<div class="border rounded p-2 mb-2 d-flex align-items-start gap-2">' +
                    '<div class="form-check pt-1">' +
                      '<input class="form-check-input fcc-ow-cb" type="checkbox" ' +
                             'id="fccOw' + i + '" data-idx="' + i + '" checked>' +
                    '</div>' +
                    '<div class="flex-grow-1">' +
                      '<label for="fccOw' + i + '" class="fw-semibold small mb-1">' +
                        esc(d0.label) +
                      '</label>' +
                      '<div class="row g-1 small">' +
                        '<div class="col-6">' +
                          '<div class="text-body-secondary" style="font-size:11px;">Current</div>' +
                          '<code class="text-body">' + esc(d0.current || '(empty)') + '</code>' +
                        '</div>' +
                        '<div class="col-6">' +
                          '<div class="text-success" style="font-size:11px;">FCC lookup</div>' +
                          '<code class="text-success">' + esc(d0.val) + '</code>' +
                        '</div>' +
                      '</div>' +
                    '</div>' +
                  '</div>';
        }
        host.innerHTML = html;

        // Toggle the "empty fields will be filled" hint.
        var emptyNote = document.getElementById('fccOverwriteEmptyNote');
        if (emptyNote) {
            emptyNote.classList.toggle('d-none', emptyFilledCount === 0);
        }

        // Wire Apply / Check all / Uncheck all — replace listeners
        // rather than accumulate them across successive lookups.
        var applyBtn = document.getElementById('btnFccOverwriteApply');
        var allBtn   = document.getElementById('btnFccOverwriteAll');
        var noneBtn  = document.getElementById('btnFccOverwriteNone');

        function setAllCheckboxes(checked) {
            var boxes = host.querySelectorAll('.fcc-ow-cb');
            for (var b = 0; b < boxes.length; b++) boxes[b].checked = checked;
        }

        // Clone-then-replace pattern to drop any prior listeners.
        var newApply = applyBtn.cloneNode(true); applyBtn.parentNode.replaceChild(newApply, applyBtn);
        var newAll   = allBtn.cloneNode(true);   allBtn.parentNode.replaceChild(newAll, allBtn);
        var newNone  = noneBtn.cloneNode(true);  noneBtn.parentNode.replaceChild(newNone, noneBtn);

        newAll.addEventListener('click', function () { setAllCheckboxes(true); });
        newNone.addEventListener('click', function () { setAllCheckboxes(false); });

        var modalEl = document.getElementById('fccOverwriteModal');
        var modal   = bootstrap.Modal.getOrCreateInstance(modalEl);

        // Track whether the user clicked Apply so the dismiss handler
        // knows whether to run the empty-only continuation.
        var appliedFlag = false;

        newApply.addEventListener('click', function () {
            var boxes = host.querySelectorAll('.fcc-ow-cb');
            var applied = 0;
            for (var b = 0; b < boxes.length; b++) {
                if (!boxes[b].checked) continue;
                var idx = parseInt(boxes[b].getAttribute('data-idx'), 10);
                if (isNaN(idx) || !diffs[idx]) continue;
                _fccApplyOne(diffs[idx].el, diffs[idx].val);
                applied++;
            }
            appliedFlag = true;
            modal.hide();
            _fccContinueAfterOverwrite(emptyFilledCount + applied, memberId);
        });

        // Cancel/dismiss path: empties were already filled (silently),
        // and the callsign save/queue must still happen. Only trigger
        // the continuation when the user dismissed WITHOUT clicking Apply.
        modalEl.addEventListener('hidden.bs.modal', function onHide() {
            modalEl.removeEventListener('hidden.bs.modal', onHide);
            if (!appliedFlag) {
                _fccContinueAfterOverwrite(emptyFilledCount, memberId);
            }
        });

        modal.show();
    }

    /**
     * Once the fill+overwrite decisions are settled, run the
     * downstream flow (save callsign, trigger GMRS lookup, show alert)
     * that used to live at the tail of applyFccDataToForm().
     */
    function _fccContinueAfterOverwrite(filled, memberId) {
        var d = _lastFccData;
        if (!d) return;

        // Save callsign to member_callsigns if member is saved.
        // Phase 45: if member isn't saved yet, queue the callsign in the
        // pending buffer; saveMember()'s success callback will drain it.
        var callsignPayload = d.callsign ? {
            callsign: d.callsign,
            license_type: 'amateur',
            oper_class: d.oper_class || null,
            frn: d.frn || null,
            grant_date: d.grant_date || null,
            expiry_date: d.expiry_date || null,
            grid_square: d.grid_square || null,
            source: d.provider || 'fcc'
        } : null;

        if (memberId && callsignPayload) {
            apiPost(Object.assign({ action: 'save_callsign', member_id: memberId }, callsignPayload), function (err) {
                if (err) {
                    showAlert('Applied ' + filled + ' field(s) but failed to save callsign: ' + err.message, 'warning');
                } else {
                    showAlert('Applied ' + filled + ' field(s) and saved callsign ' + d.callsign + '.', 'success');
                    // Auto-trigger GMRS lookup
                    doGmrsLookup();
                    selectMember(memberId);
                }
            });
        } else if (callsignPayload) {
            _queueCallsign(callsignPayload);
            showAlert('Applied ' + filled + ' field(s) and queued callsign ' + d.callsign +
                ' — it will be saved when you click "Save Member."', 'info');
        } else {
            showAlert('Applied ' + filled + ' field(s) from FCC data.' +
                (memberId ? '' : ' Save the member first, then callsigns can be stored.'), 'success');
        }
    }

    // ══════════════════════════════════════════════════════════════
    // GMRS LOOKUP
    // ══════════════════════════════════════════════════════════════

    /**
     * Look up GMRS license by name + zip
     */
    function doGmrsLookup() {
        var lastName  = document.getElementById('editLastName').value.trim();
        var firstName = document.getElementById('editFirstName').value.trim();
        var zip       = document.getElementById('editZip').value.trim();

        if (!lastName) {
            showAlert('Enter a last name first, then try GMRS lookup.');
            return;
        }

        var url = 'api/callsign-lookup.php?action=gmrs&last_name=' + encodeURIComponent(lastName);
        if (firstName) url += '&first_name=' + encodeURIComponent(firstName);
        if (zip) url += '&zip=' + encodeURIComponent(zip);

        var body = document.getElementById('gmrsResultsBody');
        body.innerHTML = '<div class="text-center"><i class="bi bi-hourglass-split"></i> Searching...</div>';
        document.getElementById('gmrsResultsPanel').classList.remove('d-none');

        fetch(url, { credentials: 'same-origin' })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            if (data.error) {
                body.innerHTML = '<span class="text-danger">' + esc(data.error) + '</span>';
                return;
            }

            if (!data.results || data.results.length === 0) {
                body.innerHTML = '<span class="text-muted">No GMRS licenses found for ' +
                    esc(lastName) + (zip ? ' in ' + esc(zip) : '') + '</span>';
                return;
            }

            var html = '<table class="table table-sm table-hover mb-0" style="font-size:0.8rem;">';
            html += '<thead><tr>' +
                '<th>Callsign</th><th>Name</th><th>City, ST</th>' +
                '<th>Expires</th><th></th></tr></thead><tbody>';

            for (var i = 0; i < data.results.length; i++) {
                var r = data.results[i];
                var expClass = '';
                if (r.expiry_date) {
                    var ed = new Date(r.expiry_date);
                    if (ed < new Date()) expClass = ' class="text-danger"';
                }
                html += '<tr>' +
                    '<td><code>' + esc(r.callsign) + '</code></td>' +
                    '<td>' + esc(r.first_name) + ' ' + esc(r.last_name) + '</td>' +
                    '<td>' + esc(r.city) + ', ' + esc(r.state) + '</td>' +
                    '<td' + expClass + '>' + esc(r.expiry_date || '—') + '</td>' +
                    '<td><button type="button" class="btn btn-sm btn-outline-success py-0 px-1 gmrs-select" ' +
                    'data-callsign="' + esc(r.callsign) + '" ' +
                    'data-grant="' + esc(r.grant_date || '') + '" ' +
                    'data-expiry="' + esc(r.expiry_date || '') + '" ' +
                    'data-frn="' + esc(r.frn || '') + '" ' +
                    'title="Use this callsign">' +
                    '<i class="bi bi-check-lg"></i></button></td>' +
                    '</tr>';
            }
            html += '</tbody></table>';
            body.innerHTML = html;

            // Bind select buttons
            var selectBtns = body.querySelectorAll('.gmrs-select');
            for (var j = 0; j < selectBtns.length; j++) {
                (function (btn) {
                    btn.addEventListener('click', function () {
                        var cs = btn.getAttribute('data-callsign');
                        if (!cs) return;
                        var memberId = parseInt(document.getElementById('editMemberId').value, 10) || 0;
                        var grantDate = btn.getAttribute('data-grant') || null;
                        var expiryDate = btn.getAttribute('data-expiry') || null;
                        var frn = btn.getAttribute('data-frn') || null;
                        var callsignPayload = {
                            callsign: cs,
                            license_type: 'gmrs',
                            frn: frn,
                            grant_date: grantDate,
                            expiry_date: expiryDate,
                            source: 'fcc_gmrs'
                        };
                        document.getElementById('gmrsResultsPanel').classList.add('d-none');
                        if (memberId) {
                            apiPost(Object.assign({ action: 'save_callsign', member_id: memberId }, callsignPayload), function (err) {
                                if (err) {
                                    showAlert('Failed to save GMRS callsign: ' + err.message);
                                } else {
                                    showAlert('GMRS callsign ' + cs + ' saved.', 'success');
                                    selectMember(memberId);
                                }
                            });
                        } else {
                            // Phase 45: queue for save after new member is created.
                            _queueCallsign(callsignPayload);
                            showAlert('GMRS callsign ' + cs +
                                ' queued — it will be saved when you click "Save Member."', 'info');
                        }
                    });
                })(selectBtns[j]);
            }
        })
        .catch(function (err) {
            body.innerHTML = '<span class="text-danger">Lookup failed: ' + esc(err.message) + '</span>';
        });
    }

    // ══════════════════════════════════════════════════════════════
    // ZIP CODE LOOKUP
    // ══════════════════════════════════════════════════════════════

    /**
     * Look up city/state from ZIP code
     */
    function doZipLookup() {
        var zipInput = document.getElementById('editZip');
        var zip = (zipInput.value || '').replace(/[^0-9]/g, '');

        if (zip.length < 5) return; // Silently skip short zips

        fetch('api/zipcode-lookup.php?zip=' + encodeURIComponent(zip), {
            credentials: 'same-origin'
        })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            if (data.found) {
                var cityEl   = document.getElementById('editCity');
                var stateEl  = document.getElementById('editState');
                var countyEl = document.getElementById('editCounty');

                // Fill city, state, and county. Phase 99i fix
                // (Billy beta 2026-06-29): the zipcodes table has
                // had a county column all along (40k+ rows). Wire
                // the zip-lookup button to auto-fill it too.
                cityEl.value = data.city;
                // Issue #42: State is a DB-backed <select>.
                if (window.TCADStates) {
                    window.TCADStates.setValue(stateEl, data.state);
                } else {
                    stateEl.value = data.state;
                }
                if (countyEl && data.county) countyEl.value = data.county;

                // Flash fields
                cityEl.classList.add('border-success');
                stateEl.classList.add('border-success');
                if (countyEl && data.county) countyEl.classList.add('border-success');
                setTimeout(function () {
                    cityEl.classList.remove('border-success');
                    stateEl.classList.remove('border-success');
                    if (countyEl) countyEl.classList.remove('border-success');
                }, 1500);
            }
        })
        .catch(function () {
            // Silently fail — zip lookup is a convenience, not critical
        });
    }

    // ────────────────────────────────────────────────────────────
    // ── Time Log (item #21)                                    ──
    // ────────────────────────────────────────────────────────────

    var _timeActivityTypes = null;   // cached dropdown options

    function loadTimeLog(memberId) {
        if (!memberId) return;
        var container = document.getElementById('detailTimeLog');
        var totalEl   = document.getElementById('timeLogTotal');
        var summaryEl = document.getElementById('timeLogSummary');
        if (!container) return;

        // Pull recent entries + summary in parallel
        Promise.all([
            fetch('api/time-entries.php?member_id=' + memberId, { credentials: 'same-origin' }).then(function (r) { return r.json(); }),
            fetch('api/time-entries.php?summary=1&member_id=' + memberId, { credentials: 'same-origin' }).then(function (r) { return r.json(); })
        ]).then(function (results) {
            var list  = results[0] || {};
            var sum   = results[1] || {};
            renderTimeLogList(list.entries || [], memberId);
            if (totalEl) totalEl.textContent = (sum.total_hours || 0).toFixed(1) + ' h';
            if (summaryEl) {
                var parts = [];
                (sum.by_activity || []).forEach(function (a) {
                    parts.push(esc(a.activity_type) + ': ' + parseFloat(a.total_hours).toFixed(1) + 'h');
                });
                summaryEl.innerHTML = parts.length ? parts.join(' &nbsp;·&nbsp; ') : 'No entries logged yet.';
            }
        }).catch(function (err) {
            container.innerHTML = '<div class="text-danger small">Failed to load time log: ' + esc(err.message) + '</div>';
        });
    }

    function renderTimeLogList(entries, memberId) {
        var container = document.getElementById('detailTimeLog');
        if (!container) return;
        if (!entries.length) {
            container.innerHTML = '<div class="text-body-secondary small">No time entries yet. Click "Log Time" to add one.</div>';
            return;
        }
        var canApproveEl = document.getElementById('canApproveTime');
        var canApprove   = canApproveEl && canApproveEl.value === '1';
        var html = '<div class="table-responsive"><table class="table table-sm mb-0"><thead><tr>'
            + '<th>Date</th><th>Activity</th><th class="text-end">Hours</th>'
            + '<th>Status</th><th></th></tr></thead><tbody>';
        for (var i = 0; i < Math.min(entries.length, 10); i++) {
            var e = entries[i];
            var canEdit = (e.status === 'self_reported');
            var statusBadge = '<span class="badge bg-' + (
                e.status === 'approved' ? 'success' :
                e.status === 'rejected' ? 'danger'  : 'secondary'
            ) + '">' + esc(e.status) + '</span>';
            var actions = '';
            if (canEdit) {
                actions += '<button class="btn btn-xs btn-outline-secondary edit-time-btn" data-id="' + e.id + '" title="Edit"><i class="bi bi-pencil"></i></button>';
                actions += ' <button class="btn btn-xs btn-outline-danger delete-time-btn" data-id="' + e.id + '" title="Delete"><i class="bi bi-trash"></i></button>';
                if (canApprove) {
                    actions += ' <button class="btn btn-xs btn-outline-success approve-time-btn" data-id="' + e.id + '" title="Approve"><i class="bi bi-check-lg"></i></button>';
                    actions += ' <button class="btn btn-xs btn-outline-warning reject-time-btn" data-id="' + e.id + '" title="Reject"><i class="bi bi-x-lg"></i></button>';
                }
            }
            html += '<tr>'
                + '<td>' + esc((e.started_at || '').substring(0, 16)) + '</td>'
                + '<td>' + esc(e.activity_type) + '</td>'
                + '<td class="text-end">' + parseFloat(e.hours || 0).toFixed(1) + '</td>'
                + '<td>' + statusBadge + '</td>'
                + '<td class="text-end">' + actions + '</td>'
                + '</tr>';
        }
        if (entries.length > 10) {
            html += '<tr><td colspan="5" class="text-center text-body-secondary small">'
                + '+ ' + (entries.length - 10) + ' more entries (run a Time Summary report to see all)</td></tr>';
        }
        html += '</tbody></table></div>';
        container.innerHTML = html;
    }

    function approveTimeEntry(id, action) {
        var csrf = document.getElementById('csrfToken').value;
        fetch('api/time-entries.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ csrf_token: csrf, action: action, id: id })
        })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d.error) { alert('Failed: ' + d.error); return; }
                // Refresh the time log to show the new status.
                var memberIdInput = document.getElementById('logTimeMemberId');
                var mid = memberIdInput ? parseInt(memberIdInput.value, 10) : 0;
                if (mid) loadTimeLog(mid);
            })
            .catch(function (e) { alert('Failed: ' + e.message); });
    }

    function loadActivityTypes(callback) {
        if (_timeActivityTypes) { callback(_timeActivityTypes); return; }
        fetch('api/time-entries.php?activity_types=1', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                _timeActivityTypes = d.activity_types || [];
                callback(_timeActivityTypes);
            }).catch(function () { callback([]); });
    }

    function openLogTimeModal(entry) {
        var memberId = selectedId;
        if (!memberId) return;
        document.getElementById('logTimeMemberId').value = memberId;
        document.getElementById('logTimeEntryId').value  = entry ? entry.id : '';

        // Default times: now (rounded down) + 1 hour
        var now = new Date(); now.setSeconds(0, 0);
        var later = new Date(now.getTime() + 3600000);
        function fmt(d) {
            return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-'
                 + String(d.getDate()).padStart(2, '0') + 'T'
                 + String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');
        }
        document.getElementById('logTimeStart').value = entry ? (entry.started_at || '').substring(0, 16).replace(' ', 'T') : fmt(now);
        document.getElementById('logTimeEnd').value   = entry ? (entry.ended_at   || '').substring(0, 16).replace(' ', 'T') : fmt(later);
        document.getElementById('logTimeIncident').value = entry ? (entry.incident_id || '') : '';
        document.getElementById('logTimeNotes').value    = entry ? (entry.notes       || '') : '';

        loadActivityTypes(function (types) {
            var sel = document.getElementById('logTimeActivity');
            var opts = '<option value="">(choose an activity)</option>';
            for (var i = 0; i < types.length; i++) {
                opts += '<option value="' + esc(types[i].name) + '"' +
                    (entry && entry.activity_type === types[i].name ? ' selected' : '') +
                    '>' + esc(types[i].name) + '</option>';
            }
            sel.innerHTML = opts;
        });
    }

    function saveTimeEntry() {
        var entryId = document.getElementById('logTimeEntryId').value;
        var memberId = parseInt(document.getElementById('logTimeMemberId').value, 10);
        var csrf = document.querySelector('meta[name="csrf-token"]');
        csrf = csrf ? csrf.getAttribute('content') : '';

        var payload = {
            csrf_token:   csrf,
            member_id:    memberId,
            started_at:   document.getElementById('logTimeStart').value,
            ended_at:     document.getElementById('logTimeEnd').value,
            activity_type: document.getElementById('logTimeActivity').value,
            incident_id:  document.getElementById('logTimeIncident').value || null,
            notes:        document.getElementById('logTimeNotes').value
        };

        if (!payload.started_at || !payload.ended_at || !payload.activity_type) {
            showAlert('Start, End, and Activity are required.');
            return;
        }

        if (entryId) {
            payload.action = 'update';
            payload.id = parseInt(entryId, 10);
        } else {
            payload.action = 'create';
        }

        fetch('api/time-entries.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        }).then(function (r) {
            return r.json().then(function (d) { return { ok: r.ok, data: d }; });
        }).then(function (res) {
            if (!res.ok || !res.data || !res.data.success) {
                showAlert((res.data && res.data.error) || 'Save failed', 'danger');
                return;
            }
            showAlert(entryId ? 'Time entry updated.' : 'Time logged.', 'success');
            var modal = bootstrap.Modal.getInstance(document.getElementById('logTimeModal'));
            if (modal) modal.hide();
            loadTimeLog(memberId);
        }).catch(function (err) {
            showAlert('Save failed: ' + err.message, 'danger');
        });
    }

    function deleteTimeEntry(id) {
        if (!confirm('Delete this time entry? This cannot be undone.')) return;
        var csrf = document.querySelector('meta[name="csrf-token"]');
        csrf = csrf ? csrf.getAttribute('content') : '';
        fetch('api/time-entries.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'delete', id: parseInt(id, 10), csrf_token: csrf })
        }).then(function (r) { return r.json(); })
          .then(function (d) {
              if (!d.success) { showAlert(d.error || 'Delete failed', 'danger'); return; }
              showAlert('Time entry deleted.', 'success');
              if (selectedId) loadTimeLog(selectedId);
          });
    }

    // ── Init ─────────────────────────────────────────────────────
    function init() {
        // Parse URL params for ICS position filter (ES5 compatible)
        var icsMatch = window.location.search.match(/[?&]ics_position_id=(\d+)/);
        if (icsMatch && parseInt(icsMatch[1]) > 0) {
            filterIcsPositionId = parseInt(icsMatch[1]);
        }

        bindEvents();
        bindPhoneFormatting();
        initOrgEditSave();
        initBulkRoster();
        loadMembers();
        // Issue #42: member State is a DB-backed <select>.
        if (window.TCADStates) { window.TCADStates.fill(document.getElementById('editState')); }
    }

    // ─────────────────────────────────────────────────────────────
    // Phase 41 — OwnTracks tracking token table (per-member)
    // ─────────────────────────────────────────────────────────────
    function loadOtTokens(memberId) {
        var container = document.getElementById('detailOtTokens');
        var countEl   = document.getElementById('otTokenCount');
        if (!container) return;
        container.innerHTML = '<div class="text-body-secondary small">Loading tokens...</div>';
        fetch('api/owntracks-config.php?action=list_tokens&member_id=' + encodeURIComponent(memberId), { credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : { tokens: [] }; })
            .then(function (d) { renderOtTokens(d.tokens || [], memberId); if (countEl) countEl.textContent = (d.tokens || []).length; })
            .catch(function () { container.innerHTML = '<div class="text-danger small">Failed to load tokens.</div>'; });
    }

    function renderOtTokens(tokens, memberId) {
        var container = document.getElementById('detailOtTokens');
        if (!container) return;
        // 2026-06-14 (Phase 46b): "New: File" is the most reliable path on
        // Android — OwnTracks Android does NOT ship a QR scanner. Android
        // users download the .otrc file on their phone and open it from
        // the file manager / browser download — OwnTracks' intent filter
        // routes the file to the import flow. iOS keeps the QR path.
        var actionBar = '<div class="d-flex gap-1 mb-2 flex-wrap">'
                      + '<button type="button" class="btn btn-sm btn-success" onclick="__ot_provision(' + memberId + ', \'file\')" title="Android-friendly: downloads an .otrc file you load in OwnTracks"><i class="bi bi-file-earmark-arrow-down me-1"></i>New: File (Android)</button>'
                      + '<button type="button" class="btn btn-sm btn-success" onclick="__ot_provision(' + memberId + ', \'qr\')" title="iOS-friendly: scan from inside the OwnTracks iOS app"><i class="bi bi-qr-code me-1"></i>New: QR (iOS)</button>'
                      + '<button type="button" class="btn btn-sm btn-success" onclick="__ot_provision(' + memberId + ', \'url\')" title="Raw owntracks:///config?inline=… URL"><i class="bi bi-link-45deg me-1"></i>New: URL</button>'
                      + '<button type="button" class="btn btn-sm btn-success" onclick="__ot_provision(' + memberId + ', \'email\')"><i class="bi bi-envelope me-1"></i>New: Email</button>'
                      + '<button type="button" class="btn btn-sm btn-warning" onclick="__ot_rotate(' + memberId + ')"><i class="bi bi-arrow-repeat me-1"></i>Rotate</button>'
                      + '</div>';
        if (!tokens || !tokens.length) {
            container.innerHTML = actionBar + '<div class="text-body-secondary small fst-italic">No OwnTracks tokens have been provisioned for this member yet. Use one of the New buttons above to send a setup link.</div>';
            return;
        }
        var html = actionBar + '<table class="table table-sm table-hover mb-0"><thead><tr>'
                 + '<th>#</th><th>Label</th><th>Status</th><th>Created</th><th>Last Used</th><th>Expires</th><th class="text-end">Action</th></tr></thead><tbody>';
        for (var i = 0; i < tokens.length; i++) {
            var t = tokens[i];
            var status = (t.status || 'active');
            var badge = 'secondary';
            if (status === 'active')   badge = 'success';
            if (status === 'expiring') badge = 'warning';
            if (status === 'expired')  badge = 'secondary';
            if (status === 'revoked')  badge = 'danger';
            html += '<tr>'
                  + '<td class="text-body-secondary">' + parseInt(t.id, 10) + '</td>'
                  + '<td>' + esc(t.token_label || '') + '</td>'
                  + '<td><span class="badge bg-' + badge + '">' + esc(status) + '</span></td>'
                  + '<td class="text-body-secondary small">' + esc(t.created_at || '') + '</td>'
                  + '<td class="text-body-secondary small">' + esc(t.last_used_at || '—') + '</td>'
                  + '<td class="text-body-secondary small">' + esc(t.valid_until || '—') + '</td>'
                  + '<td class="text-end">';
            if (status !== 'revoked') {
                html += '<button type="button" class="btn btn-xs btn-outline-danger" onclick="__ot_revoke(' + parseInt(t.id, 10) + ', ' + memberId + ')" title="Revoke now"><i class="bi bi-x-octagon"></i></button>';
            }
            html += '</td></tr>';
        }
        html += '</tbody></table>';
        container.innerHTML = html;
    }

    function _csrf() { return document.getElementById('csrfToken') ? document.getElementById('csrfToken').value : ''; }

    window.__ot_provision = function (memberId, mode) {
        // 2026-06-14 (Phase 46b): mode=file is a binary download (the .otrc
        // attachment), not JSON. We can't fetch it via XHR + json() — point
        // the browser at the URL instead so the Save dialog appears. Then
        // refresh the tokens table after a short delay so the new token shows
        // up. Show the Android-specific instructions in a follow-up alert.
        if (mode === 'file') {
            var fileUrl = 'api/owntracks-config.php?action=link&mode=file&member_id=' + memberId;
            // Use a hidden iframe so the existing page state survives.
            var ifr = document.createElement('iframe');
            ifr.style.display = 'none';
            ifr.src = fileUrl;
            document.body.appendChild(ifr);
            setTimeout(function () {
                document.body.removeChild(ifr);
                loadOtTokens(memberId);
            }, 2500);

            var w2 = window.open('', '_blank');
            if (w2) {
                w2.document.write('<html><head><title>OwnTracks setup — Android</title>'
                    + '</head><body style="margin:24px;font-family:system-ui;max-width:620px;line-height:1.5">'
                    + '<h3>OwnTracks setup file (.otrc) — Android instructions</h3>'
                    + '<p>A file named <code>owntracks-&lt;username&gt;-&lt;date&gt;.otrc</code> is downloading now '
                    +   '(check your browser\'s Downloads folder). It contains this member\'s OwnTracks server URL, '
                    +   'username, and a freshly-minted token — treat it like a password.</p>'
                    + '<h4 style="margin-top:18px">Easiest path — download directly on the phone</h4>'
                    + '<ol>'
                    +   '<li>Open this admin page on the phone\'s browser (or message yourself the page URL).</li>'
                    +   '<li>Click <em>New: File (Android)</em>. The phone saves <code>.otrc</code> to <em>Downloads</em>.</li>'
                    +   '<li>Open the phone\'s <strong>Files</strong> app → <em>Downloads</em> → tap the <code>.otrc</code> file.</li>'
                    +   '<li>Android will offer to open it with <strong>OwnTracks</strong>. Tap that.</li>'
                    +   '<li>OwnTracks opens the import screen showing the new settings. Tap <em>Apply</em>.</li>'
                    + '</ol>'
                    + '<h4 style="margin-top:18px">If you downloaded on a desktop</h4>'
                    + '<ol>'
                    +   '<li>Send the <code>.otrc</code> file to the phone any way you like — Gmail/Outlook attachment, Google Drive, Signal, USB cable, etc.</li>'
                    +   '<li>Open the file on the phone → Android prompts to open with OwnTracks.</li>'
                    + '</ol>'
                    + '<h4 style="margin-top:18px">If the Open-With dialog doesn\'t offer OwnTracks</h4>'
                    + '<ol>'
                    +   '<li>Open the <strong>OwnTracks</strong> app on the phone.</li>'
                    +   '<li>☰ menu → <em>Preferences</em> → <em>Configuration management</em>.</li>'
                    +   '<li>Tap the <em>Import</em> button — a file picker opens.</li>'
                    +   '<li>Navigate to <em>Downloads</em> and pick the <code>.otrc</code> file.</li>'
                    + '</ol>'
                    + '<p style="background:#fff8e1;border:1px solid #f0c14b;border-radius:6px;padding:10px 12px;margin-top:16px">'
                    +   '<strong>Note —</strong> OwnTracks Android does NOT include a QR code scanner. '
                    +   'Use this file path (or the URL button) for Android. The QR button is for iOS only.'
                    + '</p>'
                    + '</body></html>');
                w2.document.close();
            }
            return;
        }

        var url = 'api/owntracks-config.php?action=link&member_id=' + memberId + '&mode=' + encodeURIComponent(mode);
        fetch(url, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d.error) { alert('Provision failed: ' + d.error); return; }
                if (mode === 'email') {
                    alert(d.sent ? ('Setup link emailed to ' + (d.address || 'member')) : 'Email send failed.');
                } else if (mode === 'qr') {
                    var w = window.open('', '_blank');
                    if (w) {
                        // 2026-06-14 (Phase 46b): rewritten with verified iOS
                        // instructions. The Android QR path doesn't exist —
                        // OwnTracks Android has no QR scanner (verified against
                        // owntracks/android LoadActivity.kt). Android users go
                        // through "New: File (Android)" instead.
                        w.document.write('<html><head><title>OwnTracks QR (iOS)</title>'
                            + '<script src="assets/vendor/qrcode/qrcode-generator.min.js"></script>'
                            + '</head><body style="margin:24px;font-family:system-ui;max-width:560px;line-height:1.4">'
                            + '<h3>OwnTracks setup QR — token id ' + d.token_id + '</h3>'
                            + '<div id="qr"></div>'
                            + '<div style="background:#e7f3ff;border:1px solid #5aa9e6;border-radius:6px;padding:10px 12px;margin-top:14px">'
                            +   '<strong>iOS users —</strong> open the OwnTracks app → ⓘ <em>Info</em> tab → ⚙ <em>Settings</em> '
                            +   '→ <em>Configuration</em> → ⋮ → <em>Scan</em>. Aim the in-app scanner at this QR.'
                            + '</div>'
                            + '<div style="background:#fff0f0;border:1px solid #d35a5a;border-radius:6px;padding:10px 12px;margin-top:10px">'
                            +   '<strong>Android users —</strong> close this window and click <em>New: File (Android)</em> instead. '
                            +   'OwnTracks Android does not include a QR scanner. The system Camera / Google Lens cannot route an '
                            +   '<code>owntracks://</code> URL and will show "The app was not found on your device".'
                            + '</div>'
                            + '<p style="color:#666;font-size:.85em;word-break:break-all;margin-top:14px">URL: ' + (d.qr_text || '') + '</p>'
                            + '<script>var t = qrcode(0, "L"); t.addData(' + JSON.stringify(d.qr_text || '') + '); t.make(); document.getElementById("qr").innerHTML = t.createSvgTag(6);</' + 'script>'
                            + '</body></html>');
                        w.document.close();
                    } else {
                        prompt('Pop-up blocked. Copy this URL and open it on the phone (iOS only — scan from inside the OwnTracks app):', d.qr_text);
                    }
                } else {
                    prompt('OwnTracks setup URL (token id ' + d.token_id + ') — tap on the phone:', d.url);
                }
                loadOtTokens(memberId);
            });
    };

    window.__ot_rotate = function (memberId) {
        if (!confirm('Rotate this member\'s OwnTracks token? The previous token will keep working for the configured dual-window before expiring.')) return;
        fetch('api/owntracks-config.php?action=rotate', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ csrf_token: _csrf(), member_id: memberId })
        }).then(function (r) { return r.json(); })
          .then(function (d) {
              if (d.error) { alert('Rotation failed: ' + d.error); return; }
              // Secret is shown ONCE.
              prompt('New secret (token id ' + d.token_id + '). Copy now — it can\'t be retrieved later.\nWindow: ' + (d.dual_window_days || '?') + ' days.', d.secret_raw || '');
              loadOtTokens(memberId);
          });
    };

    window.__ot_revoke = function (tokenId, memberId) {
        if (!confirm('Revoke token #' + tokenId + ' immediately? The phone will be locked out on its next post.')) return;
        fetch('api/owntracks-config.php?action=revoke', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ csrf_token: _csrf(), token_id: tokenId })
        }).then(function (r) { return r.json(); })
          .then(function () { loadOtTokens(memberId); });
    };

    // ─────────────────────────────────────────────────────────────
    // Phase 51 (2026-06-14) — per-member OwnTracks overrides card.
    //
    // The card lazy-loads on first expand to avoid an extra round-
    // trip on every member selection. Same field set as the global
    // defaults panel (Settings → OwnTracks Defaults); blank value
    // = inherit from defaults (which themselves may inherit from
    // the hardcoded fallback). Saving auto-pushes to this member's
    // phone via the outbox.
    // ─────────────────────────────────────────────────────────────
    var _otOverridesLoaded = false;
    var _otCurrentMemberId = null;
    var _otTunable = null; // cached metadata from get_defaults

    document.addEventListener('shown.bs.collapse', function (ev) {
        if (ev.target && ev.target.id === 'collapseDetailOtOverrides') {
            if (_otOverridesLoaded || !_otCurrentMemberId) return;
            _otOverridesLoaded = true;
            _loadOtOverrides(_otCurrentMemberId);
        }
    });

    function _loadOtOverrides(memberId) {
        var body = document.getElementById('detailOtOverrides');
        if (!body) return;
        body.innerHTML = '<div class="text-body-secondary small"><span class="spinner-border spinner-border-sm me-2"></span>Loading…</div>';
        // Need the field metadata (label/hint/options) — borrow from get_defaults.
        var pTunable = _otTunable
            ? Promise.resolve({ defaults: _otTunable })
            : fetch('api/owntracks-config.php?action=get_defaults', { credentials: 'same-origin' }).then(function (r) { return r.json(); });
        var pMember = fetch('api/owntracks-config.php?action=get_member_overrides&member_id=' + encodeURIComponent(memberId),
            { credentials: 'same-origin' }).then(function (r) { return r.json(); });
        Promise.all([pTunable, pMember]).then(function (results) {
            var tunable = results[0].defaults || [];
            _otTunable = tunable;
            var overrides = results[1].overrides || {};
            var effective = results[1].effective || {};
            _renderOtOverrides(memberId, tunable, overrides, effective);
        }).catch(function () {
            body.innerHTML = '<div class="text-danger small">Failed to load overrides.</div>';
        });
    }

    function _renderOtOverrides(memberId, tunable, overrides, effective) {
        var body = document.getElementById('detailOtOverrides');
        var hasAny = false;
        var html = '<div class="alert alert-info py-1 px-2 small mb-2">Blank = inherit. Filled = override the global default just for this member. Saving pushes to this phone immediately.</div>';
        html += '<div class="row g-2">';
        for (var i = 0; i < tunable.length; i++) {
            var d = tunable[i];
            var v = overrides[d.settings_key];
            if (v !== undefined && v !== '' && v !== null) hasAny = true;
            var inputHtml;
            if (d.type === 'select' && d.options) {
                inputHtml = '<select class="form-select form-select-sm ot-ov-input" data-key="' + d.settings_key + '">';
                inputHtml += '<option value="">— inherit —</option>';
                for (var k in d.options) {
                    var sel = (String(v) === String(k)) ? ' selected' : '';
                    inputHtml += '<option value="' + k + '"' + sel + '>' + d.options[k] + '</option>';
                }
                inputHtml += '</select>';
            } else {
                var val = (v === undefined || v === null) ? '' : v;
                inputHtml = '<input type="number" class="form-control form-control-sm ot-ov-input" data-key="' + d.settings_key + '" value="' + val + '" placeholder="inherit">';
            }
            var eff = effective[d.config_key];
            html += '<div class="col-md-6">'
                +    '<label class="form-label form-label-sm mb-0">' + d.label + '</label>'
                +    inputHtml
                +    '<div class="form-text small">Currently effective: <strong>' + (eff !== undefined ? eff : '—') + '</strong></div>'
                +  '</div>';
        }
        html += '</div>';
        html += '<div class="border-top pt-2 mt-2">'
             +    '<button type="button" class="btn btn-sm btn-success" id="btnSaveOtOverrides">'
             +      '<i class="bi bi-check-lg me-1"></i>Save &amp; Push to Phone</button> '
             +    '<button type="button" class="btn btn-sm btn-outline-secondary" id="btnClearOtOverrides" title="Clear all per-member overrides; member inherits global defaults">'
             +      '<i class="bi bi-arrow-counterclockwise me-1"></i>Clear (Inherit Defaults)</button>'
             +    ' <small class="text-body-secondary ms-2" id="otOvStatus"></small>'
             +  '</div>';
        body.innerHTML = html;

        var badge = document.getElementById('otOverridesBadge');
        if (badge) {
            badge.textContent = hasAny ? 'custom' : 'inherit';
            badge.className = 'badge ms-auto ' + (hasAny ? 'bg-warning text-dark' : 'bg-secondary');
        }

        document.getElementById('btnSaveOtOverrides').addEventListener('click', function () {
            _saveOtOverrides(memberId, false);
        });
        document.getElementById('btnClearOtOverrides').addEventListener('click', function () {
            if (!confirm('Clear this member\'s overrides and inherit from global defaults?')) return;
            _saveOtOverrides(memberId, true);
        });
    }

    function _saveOtOverrides(memberId, clearAll) {
        var inputs = document.querySelectorAll('#detailOtOverrides .ot-ov-input');
        var out = {};
        if (!clearAll) {
            for (var i = 0; i < inputs.length; i++) {
                var k = inputs[i].getAttribute('data-key');
                var v = inputs[i].value.trim();
                if (v !== '') out[k] = v;
            }
        }
        var statusEl = document.getElementById('otOvStatus');
        var saveBtn = document.getElementById('btnSaveOtOverrides');
        statusEl.textContent = 'Saving + pushing…';
        if (saveBtn) saveBtn.disabled = true;
        fetch('api/owntracks-config.php?action=save_member_overrides', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ csrf_token: _csrf(), member_id: memberId, overrides: out })
        })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (saveBtn) saveBtn.disabled = false;
            if (d.error) { statusEl.innerHTML = '<span class="text-danger">' + d.error + '</span>'; return; }
            statusEl.innerHTML = '<span class="text-success">Saved · pushed</span>';
            // Reload to show fresh "Currently effective" values + badge state.
            _otOverridesLoaded = false;
            _loadOtOverrides(memberId);
        })
        .catch(function () {
            if (saveBtn) saveBtn.disabled = false;
            statusEl.innerHTML = '<span class="text-danger">Save failed.</span>';
        });
    }

    // Run on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
