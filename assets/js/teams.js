/**
 * NewUI v4.0 - Teams Page Logic
 *
 * Handles: team list, detail view, edit form, member assignment,
 * ICS position tracking, NIMS resource typing.
 */

(function () {
    'use strict';

    // ── State ──
    var allTeams = [];
    var teamTypes = [];
    var icsPositions = [];
    var allMembers = [];
    var selectedTeamId = null;
    var currentTeamDetail = null;
    var sortCol = 'name';
    var sortAsc = true;
    var searchTerm = '';

    // ── Escape key → back to dashboard ──
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            // If a modal is open, let Bootstrap handle it
            var openModal = document.querySelector('.modal.show');
            if (openModal) return;

            // If editing, cancel
            if (document.getElementById('editView').style.display !== 'none') {
                cancelEdit();
                e.preventDefault();
                return;
            }

            // If viewing detail, go back to list
            if (selectedTeamId) {
                deselectTeam();
                e.preventDefault();
                return;
            }

            // Otherwise go to dashboard
            e.preventDefault();
            window.location.href = 'index.php';
        }
    }, true);

    // ── Init ──
    document.addEventListener('DOMContentLoaded', function () {
        initTheme();
        loadTeams();
        bindEvents();
    });

    // ── Theme toggle ──
    function initTheme() {
        var btns = document.querySelectorAll('#themeToggle button');
        btns.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var theme = this.dataset.theme;
                document.documentElement.setAttribute('data-bs-theme', theme === 'Night' ? 'dark' : 'light');
                btns.forEach(function (b) {
                    b.className = 'btn ' + (b.dataset.theme === theme
                        ? (theme === 'Day' ? 'btn-warning' : 'btn-primary')
                        : 'btn-outline-secondary');
                });
            });
        });
    }

    // ── Load all teams ──
    function loadTeams() {
        apiGet('api/teams.php')
            .then(function (data) {
                allTeams = data.teams || [];
                teamTypes = data.team_types || [];
                icsPositions = data.ics_positions || [];
                allMembers = data.all_members || [];

                document.getElementById('teamCount').textContent = allTeams.length;
                renderTeamList();
                populateTypeDropdown();
                populateMemberDropdowns();
                populateIcsDropdowns();

                populateNimsTypelist();

                document.getElementById('loadingSpinner').style.display = 'none';
                document.getElementById('mainContent').style.display = '';
            })
            .catch(function (err) {
                showAlert('Failed to load teams: ' + escHtml(err.message), 'danger');
                document.getElementById('loadingSpinner').style.display = 'none';
            });
    }

    // ── Render team list ──
    function renderTeamList() {
        var tbody = document.getElementById('teamsBody');
        var filtered = allTeams.filter(function (t) {
            if (!searchTerm) return true;
            var s = searchTerm.toLowerCase();
            return (t.name || '').toLowerCase().indexOf(s) >= 0 ||
                   (t.type_name || '').toLowerCase().indexOf(s) >= 0 ||
                   (t.description || '').toLowerCase().indexOf(s) >= 0;
        });

        // Sort
        filtered.sort(function (a, b) {
            var va, vb;
            if (sortCol === 'name') { va = (a.name || '').toLowerCase(); vb = (b.name || '').toLowerCase(); }
            else if (sortCol === 'type') { va = (a.type_name || '').toLowerCase(); vb = (b.type_name || '').toLowerCase(); }
            else if (sortCol === 'members') { va = parseInt(a.member_count) || 0; vb = parseInt(b.member_count) || 0; }
            else if (sortCol === 'nims') { va = (a.nims_resource_type || '').toLowerCase(); vb = (b.nims_resource_type || '').toLowerCase(); }
            else { va = ''; vb = ''; }

            if (va < vb) return sortAsc ? -1 : 1;
            if (va > vb) return sortAsc ? 1 : -1;
            return 0;
        });

        var html = '';
        filtered.forEach(function (t) {
            var active = t.id == selectedTeamId ? ' table-active' : '';
            var nimsLabel = '';
            if (t.nims_resource_type) {
                nimsLabel = '<span class="badge bg-info bg-opacity-25 text-info-emphasis">' +
                    escHtml(t.nims_resource_type) +
                    (t.nims_typing_level ? ' T' + t.nims_typing_level : '') +
                    '</span>';
            }

            html += '<tr class="team-row' + active + '" data-id="' + t.id + '" style="cursor:pointer;">' +
                '<td class="ps-3 fw-semibold">' + escHtml(t.name) + '</td>' +
                '<td class="small text-body-secondary">' + escHtml(t.type_name || '--') + '</td>' +
                '<td class="text-center"><span class="badge bg-secondary">' + (t.member_count || 0) + '</span></td>' +
                '<td>' + nimsLabel + '</td>' +
                '</tr>';
        });

        tbody.innerHTML = html || '<tr><td colspan="4" class="text-center text-body-secondary py-3">No teams found</td></tr>';

        // Bind row clicks
        var rows = tbody.querySelectorAll('.team-row');
        rows.forEach(function (row) {
            row.addEventListener('click', function () {
                selectTeam(parseInt(this.dataset.id));
            });
        });
    }

    // ── Select team → load detail ──
    function selectTeam(id) {
        selectedTeamId = id;
        renderTeamList(); // highlight

        document.getElementById('emptyState').style.display = 'none';
        document.getElementById('editView').style.display = 'none';
        document.getElementById('detailView').style.display = '';

        // Load full detail
        apiGet('api/teams.php?id=' + id)
            .then(function (data) {
                currentTeamDetail = data;
                renderDetail(data);
            })
            .catch(function (err) {
                showAlert('Failed to load team: ' + escHtml(err.message), 'danger');
            });
    }

    function deselectTeam() {
        selectedTeamId = null;
        currentTeamDetail = null;
        renderTeamList();
        document.getElementById('detailView').style.display = 'none';
        document.getElementById('editView').style.display = 'none';
        document.getElementById('emptyState').style.display = '';
    }

    // ── Render detail view ──
    function renderDetail(data) {
        var t = data.team;
        document.getElementById('detailName').textContent = t.name || '--';
        document.getElementById('detailDesc').textContent = t.description || '--';
        document.getElementById('detailType').textContent = t.type_name || '--';
        document.getElementById('detailFormed').textContent = t.formed ? formatDate(t.formed) : '--';

        // NIMS row
        var nimsRow = document.getElementById('nimsRow');
        if (t.nims_resource_type || t.nims_typing_level || t.rtlt_code) {
            nimsRow.style.display = '';
            document.getElementById('detailNimsType').textContent = t.nims_resource_type || '--';
            document.getElementById('detailNimsLevel').textContent = t.nims_typing_level ? 'Type ' + t.nims_typing_level : '--';
            document.getElementById('detailRtlt').textContent = t.rtlt_code || '--';
        } else {
            nimsRow.style.display = 'none';
        }

        // Members
        var members = data.members || [];
        document.getElementById('memberCount').textContent = members.length;

        var tbody = document.getElementById('membersBody');
        var noMembers = document.getElementById('noMembers');

        if (members.length === 0) {
            tbody.innerHTML = '';
            noMembers.classList.remove('d-none');
        } else {
            noMembers.classList.add('d-none');

            var roleColors = { Leader: 'primary', Deputy: 'warning', Member: 'secondary', Observer: 'info' };
            var html = '';
            members.forEach(function (m) {
                var name = escHtml((m.first_name || '') + ' ' + (m.last_name || ''));
                var roleBadge = '<span class="badge bg-' + (roleColors[m.role] || 'secondary') + ' bg-opacity-75">' +
                    escHtml(m.role) + '</span>';
                var posLabel = m.position_code
                    ? '<span class="badge bg-info bg-opacity-25 text-info-emphasis">' + escHtml(m.position_code) +
                      '</span> <small class="text-body-secondary">' + escHtml(m.position_title || '') + '</small>'
                    : '<span class="text-body-secondary">--</span>';

                html += '<tr>' +
                    '<td class="ps-3">' + name + '</td>' +
                    '<td class="small">' + escHtml(m.callsign || '--') + '</td>' +
                    '<td>' + roleBadge + '</td>' +
                    '<td>' + posLabel + '</td>' +
                    '<td class="small text-body-secondary">' + (m.assigned_date ? formatDate(m.assigned_date) : '--') + '</td>' +
                    '<td class="text-center">' +
                        '<button class="btn btn-sm btn-link p-0 me-1 btn-edit-role" data-id="' + m.assignment_id +
                            '" data-role="' + escHtml(m.role) + '" data-position="' + escHtml(m.position_code || '') +
                            '" data-notes="' + escHtml(m.notes || '') + '" title="Edit Role">' +
                            '<i class="bi bi-pencil text-primary"></i></button>' +
                        '<button class="btn btn-sm btn-link p-0 btn-remove-member" data-id="' + m.assignment_id +
                            '" data-name="' + escHtml(name) + '" title="Remove">' +
                            '<i class="bi bi-x-circle text-danger"></i></button>' +
                    '</td>' +
                    '</tr>';
            });
            tbody.innerHTML = html;

            // Bind edit/remove
            tbody.querySelectorAll('.btn-edit-role').forEach(function (btn) {
                btn.addEventListener('click', function (e) {
                    e.stopPropagation();
                    openEditRoleModal(this);
                });
            });
            tbody.querySelectorAll('.btn-remove-member').forEach(function (btn) {
                btn.addEventListener('click', function (e) {
                    e.stopPropagation();
                    removeMember(parseInt(this.dataset.id), this.dataset.name);
                });
            });
        }

        // Populate add-member modal with available members
        if (data.available_members) {
            var sel = document.getElementById('modalMember');
            sel.innerHTML = '<option value="">-- Select --</option>';
            data.available_members.forEach(function (m) {
                var opt = document.createElement('option');
                opt.value = m.id;
                opt.textContent = (m.first_name || '') + ' ' + (m.last_name || '') +
                    (m.callsign ? ' (' + m.callsign + ')' : '');
                sel.appendChild(opt);
            });
        }

        // ICS positions in modal
        if (data.ics_positions) {
            populateIcsSelect(document.getElementById('modalPosition'), data.ics_positions);
            populateIcsSelect(document.getElementById('editRolePosition'), data.ics_positions);
        }
    }

    // ── Populate dropdowns ──
    function populateTypeDropdown() {
        var sel = document.getElementById('editType');
        sel.innerHTML = '<option value="">-- Select --</option>';
        teamTypes.forEach(function (tt) {
            var opt = document.createElement('option');
            opt.value = tt.id;
            // contract audit 2026-07-07: `team_type` was a dead first
            // fallback (never emitted); `name` is the real key.
            opt.textContent = tt.name || 'Unknown';
            sel.appendChild(opt);
        });
    }

    function populateMemberDropdowns() {
        var selectors = ['editLeader', 'editDeputy'];
        selectors.forEach(function (selId) {
            var sel = document.getElementById(selId);
            sel.innerHTML = '<option value="">-- None --</option>';
            allMembers.forEach(function (m) {
                var opt = document.createElement('option');
                opt.value = m.id;
                opt.textContent = (m.first_name || '') + ' ' + (m.last_name || '') +
                    (m.callsign ? ' (' + m.callsign + ')' : '');
                sel.appendChild(opt);
            });
        });
    }

    function populateIcsDropdowns() {
        populateIcsSelect(document.getElementById('modalPosition'), icsPositions);
        populateIcsSelect(document.getElementById('editRolePosition'), icsPositions);
    }

    function populateIcsSelect(sel, positions) {
        if (!sel) return;
        sel.innerHTML = '<option value="">-- None --</option>';
        var categories = {};
        positions.forEach(function (p) {
            var cat = p.category || 'Other';
            if (!categories[cat]) categories[cat] = [];
            categories[cat].push(p);
        });
        Object.keys(categories).sort().forEach(function (cat) {
            var group = document.createElement('optgroup');
            group.label = cat;
            categories[cat].forEach(function (p) {
                var opt = document.createElement('option');
                opt.value = p.code;
                opt.textContent = p.code + ' — ' + p.title;
                group.appendChild(opt);
            });
            sel.appendChild(group);
        });
    }

    function populateNimsTypelist() {
        var datalist = document.getElementById('nimsTypeList');
        if (!datalist) return;
        // Add existing NIMS types from loaded teams (deduped)
        var existing = {};
        // Collect existing options
        for (var i = 0; i < datalist.options.length; i++) {
            existing[datalist.options[i].value.toLowerCase()] = true;
        }
        for (var t = 0; t < allTeams.length; t++) {
            var nrt = (allTeams[t].nims_resource_type || '').trim();
            if (nrt && !existing[nrt.toLowerCase()]) {
                existing[nrt.toLowerCase()] = true;
                var opt = document.createElement('option');
                opt.value = nrt;
                datalist.appendChild(opt);
            }
        }
    }

    // ── Bind events ──
    function bindEvents() {
        // Search
        var searchInput = document.getElementById('searchInput');
        var searchTimer = null;
        searchInput.addEventListener('input', function () {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(function () {
                searchTerm = searchInput.value.trim();
                renderTeamList();
            }, 200);
        });

        // Sort headers
        document.querySelectorAll('#teamsTable th[data-sort]').forEach(function (th) {
            th.addEventListener('click', function () {
                var col = this.dataset.sort;
                if (sortCol === col) { sortAsc = !sortAsc; }
                else { sortCol = col; sortAsc = true; }
                renderTeamList();
            });
        });

        // New team
        document.getElementById('btnNewTeam').addEventListener('click', function () {
            showEditForm(null);
        });

        // Edit team
        document.getElementById('btnEditTeam').addEventListener('click', function () {
            if (currentTeamDetail) showEditForm(currentTeamDetail.team);
        });

        // Delete team
        document.getElementById('btnDeleteTeam').addEventListener('click', function () {
            if (!currentTeamDetail) return;
            if (!confirm('Delete team "' + (currentTeamDetail.team.name || '') + '"?\nThis will also remove all member assignments.')) return;
            apiPost('api/teams.php', { action: 'delete', id: selectedTeamId })
                .then(function () {
                    showAlert('Team deleted.', 'success');
                    deselectTeam();
                    loadTeams();
                })
                .catch(function (err) { showAlert('Delete failed: ' + escHtml(err.message), 'danger'); });
        });

        // Save team
        document.getElementById('btnSaveTeam').addEventListener('click', saveTeam);

        // Cancel edit
        document.getElementById('btnCancelEdit').addEventListener('click', cancelEdit);

        // Add member button
        document.getElementById('btnAddMember').addEventListener('click', function () {
            if (!selectedTeamId) return;
            var modal = new bootstrap.Modal(document.getElementById('addMemberModal'));
            modal.show();
        });

        // Confirm add member
        document.getElementById('btnConfirmAdd').addEventListener('click', function () {
            var memberId = document.getElementById('modalMember').value;
            if (!memberId) { showAlert('Please select a member.', 'warning'); return; }

            apiPost('api/teams.php', {
                action: 'add_member',
                team_id: selectedTeamId,
                member_id: parseInt(memberId),
                role: document.getElementById('modalRole').value,
                position_code: document.getElementById('modalPosition').value || null
            })
            .then(function () {
                bootstrap.Modal.getInstance(document.getElementById('addMemberModal')).hide();
                selectTeam(selectedTeamId);
                loadTeams(); // refresh counts
            })
            .catch(function (err) { showAlert('Failed: ' + escHtml(err.message), 'danger'); });
        });

        // Confirm edit role
        document.getElementById('btnConfirmRoleEdit').addEventListener('click', function () {
            var assignmentId = document.getElementById('editRoleAssignmentId').value;
            apiPost('api/teams.php', {
                action: 'update_member_role',
                assignment_id: parseInt(assignmentId),
                role: document.getElementById('editRoleRole').value,
                position_code: document.getElementById('editRolePosition').value || null,
                notes: document.getElementById('editRoleNotes').value
            })
            .then(function () {
                bootstrap.Modal.getInstance(document.getElementById('editRoleModal')).hide();
                selectTeam(selectedTeamId);
            })
            .catch(function (err) { showAlert('Failed: ' + escHtml(err.message), 'danger'); });
        });
    }

    // ── Edit form ──
    function showEditForm(team) {
        document.getElementById('emptyState').style.display = 'none';
        document.getElementById('detailView').style.display = 'none';
        document.getElementById('editView').style.display = '';

        if (team) {
            document.getElementById('editTitle').textContent = 'Edit Team: ' + (team.name || '');
            document.getElementById('editId').value = team.id;
            document.getElementById('editName').value = team.name || '';
            document.getElementById('editType').value = team.team_type_id || '';
            document.getElementById('editDescription').value = team.description || '';
            document.getElementById('editLeader').value = team.leader_id || '';
            document.getElementById('editDeputy').value = team.deputy_id || '';
            document.getElementById('editNimsType').value = team.nims_resource_type || '';
            document.getElementById('editNimsLevel').value = team.nims_typing_level || '';
            document.getElementById('editRtlt').value = team.rtlt_code || '';
        } else {
            document.getElementById('editTitle').textContent = 'New Team';
            document.getElementById('editId').value = '0';
            document.getElementById('editName').value = '';
            document.getElementById('editType').value = '';
            document.getElementById('editDescription').value = '';
            document.getElementById('editLeader').value = '';
            document.getElementById('editDeputy').value = '';
            document.getElementById('editNimsType').value = '';
            document.getElementById('editNimsLevel').value = '';
            document.getElementById('editRtlt').value = '';
        }

        document.getElementById('editName').focus();
    }

    function cancelEdit() {
        document.getElementById('editView').style.display = 'none';
        if (selectedTeamId && currentTeamDetail) {
            document.getElementById('detailView').style.display = '';
        } else {
            document.getElementById('emptyState').style.display = '';
        }
    }

    function saveTeam() {
        var nameInput = document.getElementById('editName');
        var name = nameInput.value.trim();
        // Clear previous validation
        nameInput.classList.remove('is-invalid');
        if (!name) {
            nameInput.classList.add('is-invalid');
            nameInput.focus();
            showAlert('Team name is required.', 'warning');
            return;
        }

        var saveBtn = document.getElementById('btnSaveTeam');
        var payload = {
            id: parseInt(document.getElementById('editId').value) || 0,
            name: name,
            description: document.getElementById('editDescription').value.trim(),
            team_type_id: document.getElementById('editType').value || null,
            leader_id: document.getElementById('editLeader').value || null,
            deputy_id: document.getElementById('editDeputy').value || null,
            nims_resource_type: document.getElementById('editNimsType').value.trim() || null,
            nims_typing_level: document.getElementById('editNimsLevel').value || null,
            rtlt_code: document.getElementById('editRtlt').value.trim() || null
        };

        // Visual feedback
        saveBtn.disabled = true;
        saveBtn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Saving...';

        apiPost('api/teams.php', payload)
            .then(function (data) {
                saveBtn.disabled = false;
                saveBtn.innerHTML = '<i class="bi bi-check-lg me-1"></i>Save';
                showAlert('Team saved.', 'success');
                selectedTeamId = data.id;
                loadTeams();
                // Re-select after reload
                setTimeout(function () { selectTeam(data.id); }, 300);
            })
            .catch(function (err) {
                saveBtn.disabled = false;
                saveBtn.innerHTML = '<i class="bi bi-check-lg me-1"></i>Save';
                showAlert('Save failed: ' + escHtml(err.message), 'danger');
            });
    }

    // ── Edit role modal ──
    function openEditRoleModal(btn) {
        document.getElementById('editRoleAssignmentId').value = btn.dataset.id;
        document.getElementById('editRoleRole').value = btn.dataset.role || 'Member';
        document.getElementById('editRolePosition').value = btn.dataset.position || '';
        document.getElementById('editRoleNotes').value = btn.dataset.notes || '';
        var modal = new bootstrap.Modal(document.getElementById('editRoleModal'));
        modal.show();
    }

    // ── Remove member ──
    function removeMember(assignmentId, name) {
        if (!confirm('Remove ' + name + ' from this team?')) return;
        apiPost('api/teams.php', { action: 'remove_member', assignment_id: assignmentId })
            .then(function () {
                selectTeam(selectedTeamId);
                loadTeams();
            })
            .catch(function (err) { showAlert('Failed: ' + escHtml(err.message), 'danger'); });
    }

    // ── API helpers ──
    function apiGet(url) {
        return fetch(url, { credentials: 'same-origin' })
            .then(function (r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.json();
            })
            .then(function (data) {
                if (data.error) throw new Error(data.error);
                return data;
            });
    }

    function apiPost(url, body) {
        // RBAC/CSRF enforcement (specs/rbac-enforcement-2026-06): teams.php now
        // requires a CSRF token on every write. Inject the global token here so
        // all callers (save/delete/add_member/remove_member/update_member_role)
        // are covered in one place.
        body = body || {};
        if (!body.csrf_token) {
            body.csrf_token = window.CSRF_TOKEN || '';
        }
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        })
        .then(function (r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        })
        .then(function (data) {
            if (data.error) throw new Error(data.error);
            return data;
        });
    }

    // ── Utilities ──
    function escHtml(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    function formatDate(dateStr) {
        if (!dateStr) return '';
        var d = new Date(dateStr);
        if (isNaN(d.getTime())) return dateStr;
        var month = d.getMonth() + 1;
        var day = d.getDate();
        var year = d.getFullYear();
        return month + '/' + day + '/' + year;
    }

    function showAlert(message, type) {
        var area = document.getElementById('alertArea');
        if (!area) return;
        area.innerHTML = '<div class="alert alert-' + type + ' alert-dismissible fade show small py-2" role="alert">' +
            message +
            '<button type="button" class="btn-close btn-close-sm" data-bs-dismiss="alert"></button>' +
            '</div>';
        setTimeout(function () {
            var alert = area.querySelector('.alert');
            if (alert) alert.classList.remove('show');
        }, 4000);
    }

})();
