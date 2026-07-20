(function () {
    'use strict';

    var csrf = document.getElementById('csrfToken').value;
    var currentUserId = parseInt(document.getElementById('currentUserId').value, 10);
    // QA #2 — the schedule grid's member ids are in a different keyspace than
    // user ids; self-signup detection must use the logged-in user's MEMBER id.
    var _cmEl = document.getElementById('currentMemberId');
    var currentMemberId = _cmEl ? parseInt(_cmEl.value, 10) : 0;
    var currentLevel = parseInt(document.getElementById('currentLevel').value, 10);
    var isAdmin = currentLevel <= 1;

    var DAY_NAMES = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    var DAY_FULL  = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    var EVENT_TYPE_ICONS = {
        drill:      { icon: 'bi-megaphone-fill',      cls: 'type-drill' },
        exercise:   { icon: 'bi-lightning-charge-fill', cls: 'type-exercise' },
        deployment: { icon: 'bi-geo-alt-fill',          cls: 'type-deployment' },
        meeting:    { icon: 'bi-people-fill',            cls: 'type-meeting' },
        training:   { icon: 'bi-mortarboard-fill',       cls: 'type-training' },
        other:      { icon: 'bi-calendar-event',         cls: 'type-other' }
    };

    // ═══════════════════════════════════════════════════════════
    //  SHIFTS TAB
    // ═══════════════════════════════════════════════════════════

    var selectedTemplate = null;
    var weekStart = getMonday(new Date());
    var scheduleData = null;
    var allMembers = [];

    function getMonday(d) {
        var dt = new Date(d);
        var day = dt.getDay();
        var diff = dt.getDate() - day + (day === 0 ? -6 : 1);
        dt.setDate(diff);
        dt.setHours(0, 0, 0, 0);
        return dt;
    }

    function formatDate(d) {
        var mm = String(d.getMonth() + 1).padStart(2, '0');
        var dd = String(d.getDate()).padStart(2, '0');
        return d.getFullYear() + '-' + mm + '-' + dd;
    }

    function formatShortDate(d) {
        return (d.getMonth() + 1) + '/' + d.getDate();
    }

    // ── Load templates ──
    function loadTemplates() {
        fetch('api/shifts.php', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                renderTemplateList(data.templates || []);
            })
            .catch(function () {
                document.getElementById('templateList').innerHTML =
                    '<div class="text-center text-danger py-2 small">Failed to load</div>';
            });
    }

    function renderTemplateList(templates) {
        var el = document.getElementById('templateList');
        if (templates.length === 0) {
            el.innerHTML = '<div class="text-center text-body-secondary py-3 small">No templates yet</div>';
            return;
        }
        var html = '';
        for (var i = 0; i < templates.length; i++) {
            var t = templates[i];
            var isActive = selectedTemplate && selectedTemplate.id === parseInt(t.id, 10);
            html += '<div class="template-item' + (isActive ? ' active' : '') + '" data-id="' + t.id + '">';
            html += '<i class="bi bi-calendar3"></i>';
            html += '<div class="flex-grow-1">';
            html += '<div class="fw-semibold">' + esc(t.name) + '</div>';
            html += '<div class="text-body-secondary" style="font-size:0.7rem">';
            html += t.rotation_weeks + 'w cycle · ' + t.role_count + ' roles · ' + t.slot_count + ' slots';
            html += '</div></div>';
            if (parseInt(t.active, 10) === 1) {
                html += '<span class="badge bg-success">Active</span>';
            } else {
                html += '<span class="badge bg-secondary">Inactive</span>';
            }
            html += '</div>';
        }
        el.innerHTML = html;

        // Bind click
        var items = el.querySelectorAll('.template-item');
        for (var j = 0; j < items.length; j++) {
            items[j].addEventListener('click', function () {
                var id = parseInt(this.getAttribute('data-id'), 10);
                selectTemplate(id);
            });
        }
    }

    function selectTemplate(id) {
        fetch('api/shifts.php?id=' + id, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                selectedTemplate = data.template;
                selectedTemplate.id = parseInt(selectedTemplate.id, 10);
                selectedTemplate._roles = data.roles;
                selectedTemplate._slots = data.slots;
                renderTemplateDetail();
                loadScheduleGrid();
                document.getElementById('weekNavCard').style.display = '';
                document.getElementById('templateDetailCard').style.display = '';
                loadTemplates(); // Re-render list to show active highlight
            });
    }

    function renderTemplateDetail() {
        var t = selectedTemplate;
        document.getElementById('tmplName').value = t.name || '';
        document.getElementById('tmplDesc').value = t.description || '';
        document.getElementById('tmplWeeks').value = t.rotation_weeks || 1;
        document.getElementById('tmplTimezone').value = t.timezone || 'America/Chicago';
        document.getElementById('tmplActive').checked = parseInt(t.active, 10) === 1;

        // Roles
        var roles = selectedTemplate._roles || [];
        var html = '';
        for (var i = 0; i < roles.length; i++) {
            var r = roles[i];
            html += '<div class="role-item">';
            html += '<span class="flex-grow-1">' + esc(r.role_name) + '</span>';
            html += '<span class="text-body-secondary" style="font-size:0.68rem">';
            html += r.min_slots + '-' + r.max_slots + ' slots';
            html += '</span>';
            html += '<button class="btn btn-sm btn-outline-danger py-0 px-1 btn-delete-role" data-id="' + r.id + '" title="Delete"><i class="bi bi-x"></i></button>';
            html += '</div>';
        }
        if (roles.length === 0) {
            html = '<div class="text-body-secondary text-center py-1" style="font-size:0.72rem">No roles defined</div>';
        }
        document.getElementById('rolesList').innerHTML = html;

        // Bind delete role buttons
        var delBtns = document.querySelectorAll('.btn-delete-role');
        for (var j = 0; j < delBtns.length; j++) {
            delBtns[j].addEventListener('click', function (e) {
                e.stopPropagation();
                var roleId = parseInt(this.getAttribute('data-id'), 10);
                if (confirm('Delete this role?')) {
                    apiPost('api/shifts.php', { action: 'delete_role', id: roleId }, function () {
                        selectTemplate(selectedTemplate.id);
                    });
                }
            });
        }

        // ── Slots ──
        var slots = selectedTemplate._slots || [];
        var slotsHtml = '';
        for (var s = 0; s < slots.length; s++) {
            var sl = slots[s];
            var dayName = DAY_FULL[parseInt(sl.day_of_week, 10)] || '?';
            var startFmt = formatTimeShort(sl.start_time);
            var endFmt = formatTimeShort(sl.end_time);
            slotsHtml += '<div class="role-item">';
            slotsHtml += '<span class="flex-grow-1">';
            if (sl.label) slotsHtml += '<strong>' + esc(sl.label) + '</strong> — ';
            slotsHtml += dayName + ' ' + startFmt + '–' + endFmt;
            if (parseInt(t.rotation_weeks, 10) > 1) slotsHtml += ' <small class="text-body-secondary">Wk ' + (sl.week_number || 1) + '</small>';
            slotsHtml += '</span>';
            slotsHtml += '<button class="btn btn-sm btn-outline-danger py-0 px-1 btn-delete-slot" data-id="' + sl.id + '" title="Delete"><i class="bi bi-x"></i></button>';
            slotsHtml += '</div>';
        }
        if (slots.length === 0) {
            slotsHtml = '<div class="text-body-secondary text-center py-1" style="font-size:0.72rem">No slots defined — add time blocks below</div>';
        }
        document.getElementById('slotsList').innerHTML = slotsHtml;

        // Bind delete slot buttons
        var slotDelBtns = document.querySelectorAll('.btn-delete-slot');
        for (var k = 0; k < slotDelBtns.length; k++) {
            slotDelBtns[k].addEventListener('click', function (e) {
                e.stopPropagation();
                var slotId = parseInt(this.getAttribute('data-id'), 10);
                if (confirm('Delete this time slot?')) {
                    apiPost('api/shifts.php', { action: 'delete_slot', id: slotId }, function () {
                        selectTemplate(selectedTemplate.id);
                    });
                }
            });
        }

        // Set week number max based on rotation weeks
        var weekInput = document.getElementById('slotWeek');
        if (weekInput) weekInput.max = t.rotation_weeks || 1;
    }

    function formatTimeShort(timeStr) {
        if (!timeStr) return '';
        var parts = timeStr.split(':');
        var h = parseInt(parts[0], 10);
        var m = parts[1] || '00';
        var ampm = h >= 12 ? 'p' : 'a';
        var h12 = h === 0 ? 12 : (h > 12 ? h - 12 : h);
        return h12 + ':' + m + ampm;
    }

    // ── Schedule grid ──
    function loadScheduleGrid() {
        if (!selectedTemplate) return;

        var end = new Date(weekStart);
        end.setDate(end.getDate() + 6);

        var url = 'api/shifts.php?schedule=1&template_id=' + selectedTemplate.id +
                  '&start=' + formatDate(weekStart) + '&end=' + formatDate(end);

        fetch(url, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                scheduleData = data;
                allMembers = data.members || [];
                renderWeekGrid(data);
                updateWeekLabel();
            })
            .catch(function () {
                document.getElementById('shiftGrid').innerHTML =
                    '<div class="text-center text-danger py-3">Failed to load schedule</div>';
            });
    }

    function updateWeekLabel() {
        var end = new Date(weekStart);
        end.setDate(end.getDate() + 6);
        document.getElementById('weekLabel').textContent =
            formatShortDate(weekStart) + ' — ' + formatShortDate(end);
    }

    function renderWeekGrid(data) {
        var slots = data.slots || [];
        var roles = data.roles || [];
        var assignments = data.assignments || [];

        // Build assignment lookup: key = "slotId-roleId-date"
        var assignMap = {};
        for (var a = 0; a < assignments.length; a++) {
            var asgn = assignments[a];
            var key = asgn.slot_id + '-' + asgn.role_id + '-' + asgn.assignment_date;
            if (!assignMap[key]) assignMap[key] = [];
            assignMap[key].push(asgn);
        }

        // Group slots by time label (Morning/Afternoon/Night)
        var slotGroups = {};
        for (var s = 0; s < slots.length; s++) {
            var sl = slots[s];
            var label = sl.label || (sl.start_time + '-' + sl.end_time);
            if (!slotGroups[label]) {
                slotGroups[label] = { label: label, start: sl.start_time, end: sl.end_time, slotsByDay: {} };
            }
            slotGroups[label].slotsByDay[parseInt(sl.day_of_week, 10)] = sl;
        }

        var today = formatDate(new Date());
        var html = '<table class="shift-grid-table">';

        // Header row: Time | Mon | Tue | ... | Sun
        html += '<thead><tr><th style="width:80px">Shift</th>';
        for (var d = 0; d < 7; d++) {
            var dayDate = new Date(weekStart);
            dayDate.setDate(dayDate.getDate() + d);
            var dayStr = formatDate(dayDate);
            var isToday = dayStr === today;
            // weekStart is Monday (day 1), so map d -> dow
            var dow = dayDate.getDay(); // 0=Sun
            html += '<th class="' + (isToday ? 'today' : '') + '">';
            html += DAY_NAMES[dow] + ' ' + formatShortDate(dayDate);
            html += '</th>';
        }
        html += '</tr></thead>';

        // Body rows: one per slot group
        html += '<tbody>';
        var groupKeys = Object.keys(slotGroups);
        if (groupKeys.length === 0) {
            html += '<tr><td colspan="8" class="text-center text-body-secondary py-3 small">No slots defined for this template</td></tr>';
        }
        for (var g = 0; g < groupKeys.length; g++) {
            var grp = slotGroups[groupKeys[g]];
            html += '<tr>';
            html += '<td class="shift-time-label text-center">';
            html += '<div>' + esc(grp.label) + '</div>';
            html += '<div style="font-size:0.66rem;opacity:0.6">' + formatTime(grp.start) + '-' + formatTime(grp.end) + '</div>';
            html += '</td>';

            for (var dd = 0; dd < 7; dd++) {
                var cellDate = new Date(weekStart);
                cellDate.setDate(cellDate.getDate() + dd);
                var cellDow = cellDate.getDay();
                var cellDateStr = formatDate(cellDate);
                var cellSlot = grp.slotsByDay[cellDow];
                var isTodayCell = cellDateStr === today;

                html += '<td class="' + (isTodayCell ? 'today' : '') + '">';
                if (cellSlot) {
                    // Show assignments for each role
                    for (var ri = 0; ri < roles.length; ri++) {
                        var role = roles[ri];
                        var aKey = cellSlot.id + '-' + role.id + '-' + cellDateStr;
                        var cellAssigns = assignMap[aKey] || [];

                        for (var ai = 0; ai < cellAssigns.length; ai++) {
                            var ca = cellAssigns[ai];
                            var roleCls = getRoleClass(role.role_name);
                            var statusIcon = getAssignStatusIcon(ca.status);
                            html += '<div class="assignment-badge ' + roleCls + '" data-assign-id="' + ca.id + '" title="' + esc(role.role_name) + ': ' + esc(ca.member_name || '') + ' [' + ca.status + ']">';
                            html += statusIcon + ' ';
                            html += esc(ca.member_callsign || ca.member_name || 'Member #' + ca.member_id);
                            html += '</div>';
                        }

                        // Add button if under max
                        var currentFill = cellAssigns.filter(function (x) { return x.status !== 'cancelled' && x.status !== 'swapped'; }).length;
                        if (currentFill < parseInt(role.max_slots, 10)) {
                            html += '<button class="btn btn-outline-secondary btn-add-assignment" data-slot-id="' + cellSlot.id + '" data-role-id="' + role.id + '" data-date="' + cellDateStr + '" data-role-name="' + esc(role.role_name) + '" title="Add ' + esc(role.role_name) + '">';
                            html += '<i class="bi bi-plus"></i> ' + esc(role.role_name);
                            html += '</button>';
                        }
                    }
                } else {
                    html += '<span class="text-body-secondary" style="font-size:0.66rem">—</span>';
                }
                html += '</td>';
            }
            html += '</tr>';
        }
        html += '</tbody></table>';

        document.getElementById('shiftGrid').innerHTML = html;

        // Bind add assignment buttons
        var addBtns = document.querySelectorAll('.btn-add-assignment');
        for (var bi = 0; bi < addBtns.length; bi++) {
            addBtns[bi].addEventListener('click', function () {
                showAssignModal(this);
            });
        }

        // Bind assignment badge clicks
        var badges = document.querySelectorAll('.assignment-badge');
        for (var bj = 0; bj < badges.length; bj++) {
            badges[bj].addEventListener('click', function () {
                showAssignDetail(this.getAttribute('data-assign-id'));
            });
        }
    }

    function getRoleClass(roleName) {
        var lower = (roleName || '').toLowerCase();
        if (lower.indexOf('manager') !== -1 || lower.indexOf('lead') !== -1) return 'role-manager';
        if (lower.indexOf('support') !== -1 || lower.indexOf('backup') !== -1) return 'role-support';
        if (lower.indexOf('observer') !== -1 || lower.indexOf('spotter') !== -1) return 'role-observer';
        return 'role-default';
    }

    function getAssignStatusIcon(status) {
        var map = {
            assigned:  '<i class="bi bi-circle" style="font-size:0.5rem"></i>',
            confirmed: '<i class="bi bi-check-circle-fill text-success" style="font-size:0.5rem"></i>',
            completed: '<i class="bi bi-check-circle-fill text-success" style="font-size:0.5rem"></i>',
            'no-show': '<i class="bi bi-x-circle-fill text-danger" style="font-size:0.5rem"></i>',
            cancelled: '<i class="bi bi-dash-circle text-secondary" style="font-size:0.5rem"></i>',
            swapped:   '<i class="bi bi-arrow-repeat text-info" style="font-size:0.5rem"></i>'
        };
        return map[status] || '';
    }

    function formatTime(timeStr) {
        if (!timeStr) return '';
        var parts = timeStr.split(':');
        var h = parseInt(parts[0], 10);
        var m = parts[1] || '00';
        var ampm = h >= 12 ? 'p' : 'a';
        h = h % 12 || 12;
        return h + ':' + m + ampm;
    }

    // ── Assignment modal (typeahead search) ──
    var _assignPending = null; // { slotId, roleId, date }

    function showAssignModal(btn) {
        var slotId = btn.getAttribute('data-slot-id');
        var roleId = btn.getAttribute('data-role-id');
        var date = btn.getAttribute('data-date');
        var roleName = btn.getAttribute('data-role-name');

        _assignPending = { slotId: slotId, roleId: roleId, date: date };

        var titleEl = document.getElementById('assignModalTitle');
        if (titleEl) titleEl.textContent = 'Assign ' + roleName + ' for ' + date;

        var searchInput = document.getElementById('assignSearchInput');
        var resultsDiv = document.getElementById('assignSearchResults');
        if (searchInput) searchInput.value = '';
        if (resultsDiv) resultsDiv.innerHTML = '';

        // Render all members initially
        renderAssignResults('');

        // Show modal
        var modalEl = document.getElementById('assignMemberModal');
        var modal = new bootstrap.Modal(modalEl);
        modal.show();

        // Focus search input after modal opens
        modalEl.addEventListener('shown.bs.modal', function handler() {
            if (searchInput) searchInput.focus();
            modalEl.removeEventListener('shown.bs.modal', handler);
        });

        // Live search filter
        if (searchInput && !searchInput._bound) {
            searchInput._bound = true;
            searchInput.addEventListener('input', function () {
                renderAssignResults(searchInput.value.trim().toLowerCase());
            });
        }
    }

    function renderAssignResults(term) {
        var resultsDiv = document.getElementById('assignSearchResults');
        if (!resultsDiv) return;
        var html = '';
        var count = 0;
        for (var i = 0; i < allMembers.length; i++) {
            var m = allMembers[i];
            var display = (m.last_name || '') + ', ' + (m.first_name || '');
            var callsign = m.callsign || '';
            var searchable = (display + ' ' + callsign).toLowerCase();
            if (term && searchable.indexOf(term) === -1) continue;

            html += '<div class="assign-result-item p-2 border-bottom" style="cursor:pointer" data-member-id="' + m.id + '">';
            html += '<strong>' + esc(display) + '</strong>';
            if (callsign) html += ' <span class="text-body-secondary small">(' + esc(callsign) + ')</span>';
            html += '</div>';
            count++;
        }
        if (count === 0) {
            html = '<div class="p-2 text-body-secondary text-center"><em>No members match</em></div>';
        }
        resultsDiv.innerHTML = html;

        // Bind click on each result
        var items = resultsDiv.querySelectorAll('.assign-result-item');
        for (var j = 0; j < items.length; j++) {
            items[j].addEventListener('click', function () {
                var memberId = parseInt(this.getAttribute('data-member-id'), 10);
                selectAssignMember(memberId);
            });
        }
    }

    function selectAssignMember(memberId) {
        // Close modal
        var modalEl = document.getElementById('assignMemberModal');
        var modal = bootstrap.Modal.getInstance(modalEl);
        if (modal) modal.hide();

        if (!_assignPending) return;
        var slotId = _assignPending.slotId;
        var roleId = _assignPending.roleId;
        var date = _assignPending.date;
        _assignPending = null;

        // QA #2 — compare the clicked MEMBER id to the user's member id (was
        // wrongly compared to currentUserId, so every volunteer whose member.id
        // != user.id got routed to the admin 'assign' path and 403'd on their
        // own self-signup). Fall back to the user id only if member id is
        // unavailable (unlinked account).
        var selfKey = currentMemberId || currentUserId;
        var isSelf = parseInt(memberId, 10) === selfKey;
        var action = isSelf ? 'signup' : 'assign';

        apiPost('api/shift-assignments.php', {
            action: action,
            slot_id: parseInt(slotId, 10),
            role_id: parseInt(roleId, 10),
            member_id: parseInt(memberId, 10),
            assignment_date: date
        }, function (resp) {
            if (resp.prereq_failed) {
                var msg = 'Prerequisites not met:\n';
                for (var e = 0; e < (resp.errors || []).length; e++) {
                    msg += '- ' + resp.errors[e] + '\n';
                }
                if (isAdmin) {
                    msg += '\nOverride as admin?';
                    if (confirm(msg)) {
                        apiPost('api/shift-assignments.php', {
                            action: action,
                            slot_id: parseInt(slotId, 10),
                            role_id: parseInt(roleId, 10),
                            member_id: parseInt(memberId, 10),
                            assignment_date: date,
                            force: true
                        }, function () {
                            loadScheduleGrid();
                        });
                    }
                } else {
                    alert(msg);
                }
                return;
            }
            loadScheduleGrid();
        });
    }

    function showAssignDetail(assignId) {
        if (!scheduleData) return;
        var asgn = null;
        for (var i = 0; i < (scheduleData.assignments || []).length; i++) {
            if (String(scheduleData.assignments[i].id) === String(assignId)) {
                asgn = scheduleData.assignments[i];
                break;
            }
        }
        if (!asgn) return;

        // Populate modal fields
        document.getElementById('assignDetailRole').textContent = asgn.role_name || 'Unknown Role';
        document.getElementById('assignDetailMember').textContent = asgn.member_name || 'Unassigned';
        document.getElementById('assignDetailDate').textContent = asgn.assignment_date || '';

        // Status badge with color
        var statusEl = document.getElementById('assignDetailStatus');
        var statusColors = {
            'assigned':  'bg-info',
            'confirmed': 'bg-primary',
            'completed': 'bg-success',
            'no-show':   'bg-danger',
            'cancelled': 'bg-secondary'
        };
        var statusLabel = (asgn.status || 'assigned').charAt(0).toUpperCase() + (asgn.status || 'assigned').slice(1);
        statusEl.className = 'badge ' + (statusColors[asgn.status] || 'bg-secondary');
        statusEl.textContent = statusLabel;

        // Build action buttons
        var actionsEl = document.getElementById('assignDetailActions');
        actionsEl.innerHTML = '';

        if (isAdmin) {
            var actions = [
                { status: 'confirmed', label: 'Confirm',  icon: 'bi-check-circle', cls: 'btn-outline-primary' },
                { status: 'completed', label: 'Complete', icon: 'bi-check2-all',   cls: 'btn-outline-success' },
                { status: 'no-show',   label: 'No-Show',  icon: 'bi-person-x',     cls: 'btn-outline-warning' },
                { status: 'cancelled', label: 'Cancel',   icon: 'bi-x-circle',     cls: 'btn-outline-danger'  }
            ];

            for (var i = 0; i < actions.length; i++) {
                // Skip the button for the current status
                if (actions[i].status === asgn.status) continue;

                var btn = document.createElement('button');
                btn.className = 'btn btn-sm ' + actions[i].cls;
                btn.innerHTML = '<i class="bi ' + actions[i].icon + ' me-1"></i>' + actions[i].label;
                btn.setAttribute('data-new-status', actions[i].status);
                btn.setAttribute('data-assign-id', assignId);
                btn.addEventListener('click', function () {
                    var newStatus = this.getAttribute('data-new-status');
                    var id = parseInt(this.getAttribute('data-assign-id'), 10);
                    apiPost('api/shift-assignments.php', {
                        action: 'update_status',
                        id: id,
                        status: newStatus
                    }, function () {
                        bootstrap.Modal.getInstance(document.getElementById('assignDetailModal')).hide();
                        loadScheduleGrid();
                    });
                });
                actionsEl.appendChild(btn);
            }

            // Unassign button (remove assignment entirely)
            var removeBtn = document.createElement('button');
            removeBtn.className = 'btn btn-sm btn-outline-secondary mt-1';
            removeBtn.innerHTML = '<i class="bi bi-person-dash me-1"></i>Remove Assignment';
            removeBtn.addEventListener('click', function () {
                if (!confirm('Remove this assignment?')) return;
                apiPost('api/shift-assignments.php', {
                    action: 'delete',
                    id: parseInt(assignId, 10)
                }, function () {
                    bootstrap.Modal.getInstance(document.getElementById('assignDetailModal')).hide();
                    loadScheduleGrid();
                });
            });
            actionsEl.appendChild(removeBtn);

            // Swap button — opens inline search
            var swapBtn = document.createElement('button');
            swapBtn.className = 'btn btn-sm btn-outline-info mt-1';
            swapBtn.innerHTML = '<i class="bi bi-arrow-left-right me-1"></i>Swap With...';
            swapBtn.addEventListener('click', function () {
                var swapSection = document.getElementById('assignSwapSection');
                swapSection.classList.toggle('d-none');
                if (!swapSection.classList.contains('d-none')) {
                    var inp = document.getElementById('swapSearchInput');
                    inp.value = '';
                    inp.focus();
                    document.getElementById('swapSearchResults').innerHTML =
                        '<div class="text-body-secondary text-center small py-1">Type to search...</div>';
                }
            });
            actionsEl.appendChild(swapBtn);
        }

        // Reset swap section
        var swapSection = document.getElementById('assignSwapSection');
        if (swapSection) swapSection.classList.add('d-none');

        // Wire up swap search
        var swapInput = document.getElementById('swapSearchInput');
        var swapTimer = null;
        if (swapInput) {
            // Remove old listener by cloning
            var newInput = swapInput.cloneNode(true);
            swapInput.parentNode.replaceChild(newInput, swapInput);

            newInput.addEventListener('input', function () {
                clearTimeout(swapTimer);
                var q = this.value.trim();
                if (q.length < 2) {
                    document.getElementById('swapSearchResults').innerHTML =
                        '<div class="text-body-secondary text-center small py-1">Type to search...</div>';
                    return;
                }
                swapTimer = setTimeout(function () {
                    fetch('api/members.php?search=' + encodeURIComponent(q) + '&limit=10')
                        .then(function (r) { return r.json(); })
                        .then(function (data) {
                            var container = document.getElementById('swapSearchResults');
                            var members = data.members || data.data || [];
                            if (!members.length) {
                                container.innerHTML = '<div class="text-body-secondary text-center small py-1">No results</div>';
                                return;
                            }
                            var html = '';
                            for (var i = 0; i < members.length; i++) {
                                var m = members[i];
                                // Skip the currently assigned member
                                if (String(m.id) === String(asgn.member_id)) continue;
                                var name = (m.first_name || '') + ' ' + (m.last_name || '');
                                html += '<div class="d-flex align-items-center py-1 px-2 border-bottom swap-result" ' +
                                        'style="cursor:pointer" data-member-id="' + m.id + '">';
                                html += '<div class="small"><strong>' + esc(name.trim()) + '</strong>';
                                if (m.callsign) html += ' <span class="text-body-secondary">(' + esc(m.callsign) + ')</span>';
                                html += '</div></div>';
                            }
                            if (!html) html = '<div class="text-body-secondary text-center small py-1">No other members found</div>';
                            container.innerHTML = html;

                            // Bind click
                            var items = container.querySelectorAll('.swap-result');
                            for (var j = 0; j < items.length; j++) {
                                items[j].addEventListener('click', function () {
                                    var newMemberId = parseInt(this.getAttribute('data-member-id'), 10);
                                    if (!confirm('Swap this assignment to ' + this.querySelector('strong').textContent + '?')) return;
                                    apiPost('api/shift-assignments.php', {
                                        action: 'swap',
                                        assignment_id: parseInt(assignId, 10),
                                        new_member_id: newMemberId
                                    }, function () {
                                        bootstrap.Modal.getInstance(document.getElementById('assignDetailModal')).hide();
                                        loadScheduleGrid();
                                    });
                                });
                            }
                        });
                }, 300);
            });
        }

        // Show the modal
        var modal = new bootstrap.Modal(document.getElementById('assignDetailModal'));
        modal.show();
    }

    // ── Week navigation ──
    document.getElementById('btnPrevWeek').addEventListener('click', function () {
        weekStart.setDate(weekStart.getDate() - 7);
        loadScheduleGrid();
    });
    document.getElementById('btnNextWeek').addEventListener('click', function () {
        weekStart.setDate(weekStart.getDate() + 7);
        loadScheduleGrid();
    });
    document.getElementById('btnToday').addEventListener('click', function () {
        weekStart = getMonday(new Date());
        loadScheduleGrid();
    });

    // ── Template CRUD ──
    document.getElementById('btnNewTemplate').addEventListener('click', function () {
        var name = prompt('New template name:');
        if (!name) return;
        apiPost('api/shifts.php', {
            action: 'save_template',
            name: name,
            rotation_weeks: 1,
            active: true
        }, function (resp) {
            selectTemplate(resp.id);
            loadTemplates();
        });
    });

    document.getElementById('btnSaveTemplate').addEventListener('click', function () {
        if (!selectedTemplate) return;
        apiPost('api/shifts.php', {
            action: 'save_template',
            id: selectedTemplate.id,
            name: document.getElementById('tmplName').value,
            description: document.getElementById('tmplDesc').value,
            rotation_weeks: parseInt(document.getElementById('tmplWeeks').value, 10),
            timezone: document.getElementById('tmplTimezone').value,
            active: document.getElementById('tmplActive').checked
        }, function () {
            loadTemplates();
        });
    });

    document.getElementById('btnDeleteTemplate').addEventListener('click', function () {
        if (!selectedTemplate) return;
        if (!confirm('Delete template "' + selectedTemplate.name + '" and all its roles/slots/assignments?')) return;
        apiPost('api/shifts.php', {
            action: 'delete_template',
            id: selectedTemplate.id
        }, function () {
            selectedTemplate = null;
            document.getElementById('weekNavCard').style.display = 'none';
            document.getElementById('templateDetailCard').style.display = 'none';
            document.getElementById('shiftGrid').innerHTML =
                '<div class="text-center text-body-secondary py-5"><i class="bi bi-calendar3 display-6 d-block mb-2 opacity-25"></i><span class="small">Select a shift template</span></div>';
            loadTemplates();
        });
    });

    document.getElementById('btnAddRole').addEventListener('click', function () {
        if (!selectedTemplate) return;
        var name = prompt('Role name (e.g., Net Manager, Support, Observer):');
        if (!name) return;
        var maxSlots = prompt('Max slots for this role:', '1');
        apiPost('api/shifts.php', {
            action: 'save_role',
            template_id: selectedTemplate.id,
            role_name: name,
            min_slots: 0,
            max_slots: parseInt(maxSlots, 10) || 1,
            sort_order: (selectedTemplate._roles || []).length
        }, function () {
            selectTemplate(selectedTemplate.id);
        });
    });

    // ── Add Slot ──
    document.getElementById('btnAddSlot').addEventListener('click', function () {
        if (!selectedTemplate) { alert('Select a template first.'); return; }
        var day = parseInt(document.getElementById('slotDay').value, 10);
        var startTime = document.getElementById('slotStart').value;
        var endTime = document.getElementById('slotEnd').value;
        var label = document.getElementById('slotLabel').value.trim();
        var weekNum = parseInt(document.getElementById('slotWeek').value, 10) || 1;

        if (!startTime || !endTime) { alert('Start and end times are required.'); return; }

        apiPost('api/shifts.php', {
            action: 'save_slot',
            template_id: selectedTemplate.id,
            day_of_week: day,
            start_time: startTime + ':00',
            end_time: endTime + ':00',
            week_number: weekNum,
            label: label || null
        }, function () {
            // Clear label field for next entry
            document.getElementById('slotLabel').value = '';
            selectTemplate(selectedTemplate.id);
        });
    });


    // ═══════════════════════════════════════════════════════════
    //  EVENTS TAB
    // ═══════════════════════════════════════════════════════════

    var allEvents = [];
    var selectedEvent = null;

    function loadEvents() {
        fetch('api/events.php', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                allEvents = data.events || [];
                renderEventList();
                loadUpcomingCount();
            })
            .catch(function () {
                document.getElementById('eventList').innerHTML =
                    '<div class="text-center text-danger py-2 small">Failed to load events</div>';
            });
    }

    function loadUpcomingCount() {
        fetch('api/events.php?upcoming=1', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var count = (data.events || []).length;
                var badge = document.getElementById('upcomingBadge');
                if (count > 0) {
                    badge.textContent = count;
                    badge.style.display = '';
                } else {
                    badge.style.display = 'none';
                }
            });
    }

    function renderEventList() {
        var searchTerm = (document.getElementById('eventSearch').value || '').toLowerCase();
        var typeFilter = document.getElementById('eventTypeFilter').value;

        var filtered = allEvents.filter(function (ev) {
            if (typeFilter && ev.event_type !== typeFilter) return false;
            if (searchTerm) {
                var hay = ((ev.name || '') + ' ' + (ev.location || '') + ' ' + (ev.description || '')).toLowerCase();
                if (hay.indexOf(searchTerm) === -1) return false;
            }
            return true;
        });

        var el = document.getElementById('eventList');
        if (filtered.length === 0) {
            el.innerHTML = '<div class="text-center text-body-secondary py-3 small">No events found</div>';
            return;
        }

        var html = '';
        for (var i = 0; i < filtered.length; i++) {
            var ev = filtered[i];
            var meta = EVENT_TYPE_ICONS[ev.event_type] || EVENT_TYPE_ICONS.other;
            var isActive = selectedEvent && String(selectedEvent.id) === String(ev.id);
            var evDate = ev.start_date ? ev.start_date.substring(0, 10) : '';

            html += '<div class="event-item' + (isActive ? ' active' : '') + '" data-id="' + ev.id + '">';
            html += '<div class="event-type-icon ' + meta.cls + '"><i class="bi ' + meta.icon + '"></i></div>';
            html += '<div class="flex-grow-1">';
            html += '<div class="event-item-title">' + esc(ev.name) + '</div>';
            html += '<div class="event-item-meta">';
            html += '<i class="bi bi-calendar me-1"></i>' + evDate;
            if (ev.location) html += ' · <i class="bi bi-geo-alt me-1"></i>' + esc(ev.location);
            html += '</div>';
            html += '</div>';
            html += '<div class="text-end">';
            html += '<span class="badge bg-' + getEventStatusColor(ev.status) + '">' + esc(ev.status) + '</span>';
            if (ev.participant_count) {
                html += '<div style="font-size:0.68rem" class="text-body-secondary mt-1">';
                html += '<i class="bi bi-people me-1"></i>' + ev.participant_count;
                if (ev.max_participants) html += '/' + ev.max_participants;
                html += '</div>';
            }
            html += '</div></div>';
        }

        el.innerHTML = html;

        // Bind clicks
        var items = el.querySelectorAll('.event-item');
        for (var j = 0; j < items.length; j++) {
            items[j].addEventListener('click', function () {
                selectEvent(parseInt(this.getAttribute('data-id'), 10));
            });
        }
    }

    function getEventStatusColor(status) {
        var map = { planned: 'primary', active: 'success', completed: 'secondary', cancelled: 'danger' };
        return map[status] || 'secondary';
    }

    function selectEvent(id) {
        fetch('api/events.php?id=' + id, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                selectedEvent = data.event;
                renderEventDetail(data);
                renderEventList(); // Update active highlight
            });
    }

    function renderEventDetail(data) {
        var ev = data.event;
        var participants = data.participants || [];
        var members = data.members || [];
        var meta = EVENT_TYPE_ICONS[ev.event_type] || EVENT_TYPE_ICONS.other;

        var html = '';

        // Header
        html += '<div class="event-detail-header">';
        html += '<div class="d-flex align-items-center gap-2 mb-2">';
        html += '<div class="event-type-icon ' + meta.cls + '"><i class="bi ' + meta.icon + '"></i></div>';
        html += '<div class="flex-grow-1">';
        html += '<h6 class="mb-0">' + esc(ev.name) + '</h6>';
        html += '<span class="badge bg-' + getEventStatusColor(ev.status) + ' me-1">' + esc(ev.status) + '</span>';
        html += '<span class="text-body-secondary small">' + esc(ev.event_type) + '</span>';
        html += '</div>';
        if (isAdmin) {
            html += '<button class="btn btn-sm btn-outline-primary me-1" id="btnEditEvent"><i class="bi bi-pencil"></i></button>';
            html += '<button class="btn btn-sm btn-outline-danger" id="btnDeleteEvent"><i class="bi bi-trash"></i></button>';
        }
        html += '</div>';

        // Details grid
        html += '<div class="row g-2 small">';
        html += '<div class="col-6"><span class="text-body-secondary">Start:</span> ' + esc(ev.start_date || '') + '</div>';
        html += '<div class="col-6"><span class="text-body-secondary">End:</span> ' + esc(ev.end_date || '—') + '</div>';
        html += '<div class="col-6"><span class="text-body-secondary">Location:</span> ' + esc(ev.location || '—') + '</div>';
        html += '<div class="col-6"><span class="text-body-secondary">Capacity:</span> ';
        if (ev.max_participants) {
            html += participants.filter(function (p) { return p.status !== 'cancelled'; }).length + '/' + ev.max_participants;
        } else {
            html += 'Unlimited';
        }
        html += '</div>';
        html += '</div>';

        if (ev.description) {
            html += '<div class="mt-2 small text-body-secondary">' + esc(ev.description) + '</div>';
        }
        html += '</div>';

        // Body: Participants
        html += '<div class="event-detail-body">';
        html += '<div class="d-flex align-items-center mb-2">';
        html += '<span class="fw-semibold small">Participants (' + participants.length + ')</span>';
        html += '<button class="btn btn-sm btn-outline-primary ms-auto py-0 px-2" id="btnRegisterParticipant"><i class="bi bi-person-plus me-1"></i>Add</button>';
        html += '</div>';

        if (participants.length === 0) {
            html += '<div class="text-center text-body-secondary py-2 small">No participants yet</div>';
        } else {
            for (var i = 0; i < participants.length; i++) {
                var p = participants[i];
                html += '<div class="participant-row">';
                html += '<span class="fw-semibold">' + esc(p.member_name || '') + '</span>';
                if (p.member_callsign) html += '<span class="text-body-secondary">(' + esc(p.member_callsign) + ')</span>';
                if (p.role) html += '<span class="badge bg-info bg-opacity-50 ms-1" style="font-size:0.64rem">' + esc(p.role) + '</span>';
                html += '<span class="status-pill st-' + (p.status || '').replace(/\s/g, '-') + ' ms-auto">' + esc(p.status) + '</span>';
                if (p.hours_worked) html += '<span class="text-body-secondary ms-1" style="font-size:0.7rem">' + p.hours_worked + 'h</span>';
                if (isAdmin) {
                    if (p.status === 'registered') {
                        html += '<button class="btn btn-sm btn-outline-success py-0 px-1 ms-1 btn-checkin-participant" data-id="' + p.id + '" title="Check In"><i class="bi bi-box-arrow-in-right"></i></button>';
                    } else if (p.status === 'attended' && !p.check_out_time) {
                        html += '<button class="btn btn-sm btn-outline-warning py-0 px-1 ms-1 btn-checkout-participant" data-id="' + p.id + '" title="Check Out"><i class="bi bi-box-arrow-right"></i></button>';
                    }
                    html += '<button class="btn btn-sm btn-outline-danger py-0 px-1 ms-1 btn-cancel-participant" data-event-id="' + ev.id + '" data-member-id="' + p.member_id + '" title="Remove"><i class="bi bi-x"></i></button>';
                }
                html += '</div>';
            }
        }
        html += '</div>';

        document.getElementById('eventDetail').innerHTML = html;

        // Bind buttons
        var editBtn = document.getElementById('btnEditEvent');
        if (editBtn) {
            editBtn.addEventListener('click', function () {
                editEvent(ev);
            });
        }

        var deleteBtn = document.getElementById('btnDeleteEvent');
        if (deleteBtn) {
            deleteBtn.addEventListener('click', function () {
                if (!confirm('Delete event "' + ev.name + '"?')) return;
                apiPost('api/events.php', { action: 'delete', id: parseInt(ev.id, 10) }, function () {
                    selectedEvent = null;
                    document.getElementById('eventDetail').innerHTML =
                        '<div class="text-center text-body-secondary py-5"><i class="bi bi-calendar-event display-6 d-block mb-2 opacity-25"></i><span class="small">Select an event</span></div>';
                    loadEvents();
                });
            });
        }

        var regBtn = document.getElementById('btnRegisterParticipant');
        if (regBtn) {
            regBtn.addEventListener('click', function () {
                registerParticipant(ev.id, members);
            });
        }

        // Check-in buttons
        var checkinBtns = document.querySelectorAll('.btn-checkin-participant');
        for (var ci = 0; ci < checkinBtns.length; ci++) {
            checkinBtns[ci].addEventListener('click', function () {
                apiPost('api/events.php', { action: 'checkin', id: parseInt(this.getAttribute('data-id'), 10) }, function () {
                    selectEvent(parseInt(ev.id, 10));
                });
            });
        }

        // Check-out buttons
        var checkoutBtns = document.querySelectorAll('.btn-checkout-participant');
        for (var co = 0; co < checkoutBtns.length; co++) {
            checkoutBtns[co].addEventListener('click', function () {
                apiPost('api/events.php', { action: 'checkout', id: parseInt(this.getAttribute('data-id'), 10) }, function () {
                    selectEvent(parseInt(ev.id, 10));
                });
            });
        }

        // Cancel participant buttons
        var cancelBtns = document.querySelectorAll('.btn-cancel-participant');
        for (var cp = 0; cp < cancelBtns.length; cp++) {
            cancelBtns[cp].addEventListener('click', function () {
                if (!confirm('Remove this participant?')) return;
                apiPost('api/events.php', {
                    action: 'unregister',
                    event_id: parseInt(this.getAttribute('data-event-id'), 10),
                    member_id: parseInt(this.getAttribute('data-member-id'), 10)
                }, function () {
                    selectEvent(parseInt(ev.id, 10));
                });
            });
        }
    }

    var _registerPendingEventId = null;

    function registerParticipant(eventId, members) {
        _registerPendingEventId = eventId;

        var titleEl = document.getElementById('registerParticipantTitle');
        if (titleEl) titleEl.textContent = 'Register Participant';

        var searchInput = document.getElementById('participantSearchInput');
        var resultsDiv = document.getElementById('participantSearchResults');
        if (searchInput) searchInput.value = '';
        if (resultsDiv) resultsDiv.innerHTML = '';

        // Render all members initially
        renderParticipantResults('', members);

        // Show modal
        var modalEl = document.getElementById('registerParticipantModal');
        var modal = new bootstrap.Modal(modalEl);
        modal.show();

        // Focus search input after modal opens
        modalEl.addEventListener('shown.bs.modal', function handler() {
            if (searchInput) searchInput.focus();
            modalEl.removeEventListener('shown.bs.modal', handler);
        });

        // Live search filter
        if (searchInput && !searchInput._boundParticipant) {
            searchInput._boundParticipant = true;
            searchInput.addEventListener('input', function () {
                renderParticipantResults(searchInput.value.trim().toLowerCase(), members);
            });
        }
    }

    function renderParticipantResults(term, members) {
        var resultsDiv = document.getElementById('participantSearchResults');
        if (!resultsDiv) return;
        var html = '';
        var count = 0;
        for (var i = 0; i < members.length; i++) {
            var m = members[i];
            var display = (m.last_name || '') + ', ' + (m.first_name || '');
            var callsign = m.callsign || '';
            var searchable = (display + ' ' + callsign).toLowerCase();
            if (term && searchable.indexOf(term) === -1) continue;

            html += '<div class="participant-result-item p-2 border-bottom" style="cursor:pointer" data-member-id="' + m.id + '">';
            html += '<strong>' + esc(display) + '</strong>';
            if (callsign) html += ' <span class="text-body-secondary small">(' + esc(callsign) + ')</span>';
            html += '</div>';
            count++;
        }
        if (count === 0) {
            html = '<div class="p-2 text-body-secondary text-center"><em>No members match</em></div>';
        }
        resultsDiv.innerHTML = html;

        // Bind click on each result
        var items = resultsDiv.querySelectorAll('.participant-result-item');
        for (var j = 0; j < items.length; j++) {
            items[j].addEventListener('click', function () {
                var memberId = parseInt(this.getAttribute('data-member-id'), 10);
                selectParticipant(memberId);
            });
        }
    }

    function selectParticipant(memberId) {
        // Close modal
        var modalEl = document.getElementById('registerParticipantModal');
        var modal = bootstrap.Modal.getInstance(modalEl);
        if (modal) modal.hide();

        var eventId = _registerPendingEventId;
        _registerPendingEventId = null;
        if (!eventId) return;

        apiPost('api/events.php', {
            action: 'register',
            event_id: parseInt(eventId, 10),
            member_id: parseInt(memberId, 10)
        }, function () {
            selectEvent(parseInt(eventId, 10));
            loadEvents();
        });
    }

    function editEvent(ev) {
        var name = prompt('Event name:', ev.name);
        if (name === null) return;
        var location = prompt('Location:', ev.location || '');
        var startDate = prompt('Start date (YYYY-MM-DD HH:MM:SS):', ev.start_date || '');
        var endDate = prompt('End date (YYYY-MM-DD HH:MM:SS):', ev.end_date || '');

        apiPost('api/events.php', {
            action: 'save',
            id: parseInt(ev.id, 10),
            name: name,
            event_type: ev.event_type,
            location: location,
            start_date: startDate,
            end_date: endDate,
            status: ev.status,
            description: ev.description || ''
        }, function () {
            selectEvent(parseInt(ev.id, 10));
            loadEvents();
        });
    }

    // New event
    document.getElementById('btnNewEvent').addEventListener('click', function () {
        var name = prompt('Event name:');
        if (!name) return;

        var type = prompt('Type (drill, exercise, deployment, meeting, training, other):', 'drill');
        var startDate = prompt('Start date (YYYY-MM-DD HH:MM:SS):', new Date().toISOString().substring(0, 10) + ' 09:00:00');
        var location = prompt('Location:', '');

        apiPost('api/events.php', {
            action: 'save',
            name: name,
            event_type: type || 'other',
            start_date: startDate || new Date().toISOString().substring(0, 19),
            location: location || null,
            status: 'planned'
        }, function (resp) {
            loadEvents();
            selectEvent(resp.id);
        });
    });

    // Event filters
    document.getElementById('eventSearch').addEventListener('input', renderEventList);
    document.getElementById('eventTypeFilter').addEventListener('change', renderEventList);


    // ═══════════════════════════════════════════════════════════
    //  HELPERS
    // ═══════════════════════════════════════════════════════════

    function apiPost(url, body, onSuccess) {
        // RBAC/CSRF enforcement (specs/rbac-enforcement-2026-06): shifts.php now
        // requires the CSRF token in the JSON body (csrf_verify reads
        // $input['csrf_token'], not the header). Inject it here so all writes
        // carry it; harmless on endpoints that don't check the body token.
        body = body || {};
        if (!body.csrf_token) {
            body.csrf_token = csrf || window.CSRF_TOKEN || '';
        }
        fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrf
            },
            body: JSON.stringify(body)
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.error) {
                alert('Error: ' + data.error);
                return;
            }
            if (onSuccess) onSuccess(data);
        })
        .catch(function (err) {
            alert('Request failed: ' + err.message);
        });
    }

    function esc(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str || ''));
        return div.innerHTML;
    }

    // ── Init ──
    loadTemplates();
    loadEvents();

})();
