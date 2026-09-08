document.addEventListener('DOMContentLoaded', function () {
    function esc(s) {
        var d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML;
    }

    function fmtValue(v) {
        return v === null ? 'Unlimited' : 'RM ' + Number(v).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    // A tier's RM value/removal takes effect immediately for everyone
    // already holding that tier name (aapCanApprove() resolves it live, see
    // aap_lib.php) - so before committing an edit or a removal, check how
    // many existing Case Type assignments actually rely on it and make the
    // admin confirm if the number isn't zero. deptId is null for a Default
    // Tiers row, or that department's id for a Department Tiers row.
    function confirmTierImpact(deptId, tierName, action) {
        var qs = 'action=get_tier_impact&tier_name=' + encodeURIComponent(tierName) + (deptId ? '&department_id=' + encodeURIComponent(deptId) : '');
        return fetch('?' + qs).then(function (r) { return r.json(); }).then(function (res) {
            if (!res.success || !res.staff_rows) return true;
            return confirm(
                'The tier "' + tierName + '" is currently assigned to ' + res.staff_rows + ' staff approval assignment(s) across ' + res.case_types + ' Case Type(s). ' +
                (action === 'remove'
                    ? 'Removing it will immediately stop those staff from approving under it.'
                    : 'Changing its RM value will immediately change their approval ceiling.') +
                ' Continue?'
            );
        }).catch(function () { return true; });
    }

    // ---- Universal Tiers panel - one fixed row per staff grade, including
    // CEO/Board. Edit changes only that row's RM value (or Unlimited) - the
    // grade<->tier pairing itself is fixed by the migration that set this
    // table up, not something this UI can change. ----
    (function () {
        var tbody = document.getElementById('default_tier_tbody');
        if (!tbody) return;
        var tiers = AAP_GROUPING.defaultTiers || [];

        function renderRow(t) {
            var gradeName = (t.grades && t.grades[0]) ? t.grades[0].name : '—';
            var unlimitedChecked = t.tier_value === null;
            var actionCell = '<button type="button" class="alpro-btn alpro-btn-grey universal-tier-edit" style="padding:2px 8px; font-size:11px;">Edit</button>';
            return '<tr data-id="' + t.id + '" data-name="' + esc(t.tier_name) + '" data-value="' + (t.tier_value === null ? '' : t.tier_value) + '">'
                + '<td>' + esc(gradeName) + '</td>'
                + '<td>' + esc(t.tier_name) + '</td>'
                + '<td class="universal-tier-value-cell">' + esc(fmtValue(t.tier_value)) + '</td>'
                + '<td style="text-align:center;"><input type="checkbox" class="universal-tier-unlimited-toggle" ' + (unlimitedChecked ? 'checked' : '') + '></td>'
                + '<td>' + actionCell + '</td>'
                + '</tr>';
        }

        function render() {
            tbody.innerHTML = tiers.length ? tiers.map(renderRow).join('') : '<tr><td colspan="5" class="alpro-muted">Not set up yet.</td></tr>';
            tbody.querySelectorAll('.universal-tier-edit').forEach(function (btn) {
                btn.addEventListener('click', function () { startEdit(btn.closest('tr')); });
            });
            // Ticking Unlimited saves immediately (no value needed);
            // unticking it opens the row in the editor instead of guessing a
            // value to save with.
            tbody.querySelectorAll('.universal-tier-unlimited-toggle').forEach(function (cb) {
                cb.addEventListener('change', function () {
                    var tr = cb.closest('tr');
                    var id = tr.getAttribute('data-id');
                    var name = tr.getAttribute('data-name');
                    if (cb.checked) {
                        confirmTierImpact(null, name, 'edit').then(function (ok) {
                            if (!ok) { cb.checked = false; return; }
                            var body = new URLSearchParams();
                            body.set('action', 'update_tier');
                            body.set('id', id);
                            body.set('tier_name', name);
                            body.set('unlimited', '1');
                            fetch('', { method: 'POST', body: body }).then(function (r) { return r.json(); }).then(function (res) {
                                if (res.success) tr.setAttribute('data-value', '');
                                render();
                            });
                        });
                    } else {
                        startEdit(tr);
                    }
                });
            });
        }

        function startEdit(tr) {
            var id = tr.getAttribute('data-id');
            var name = tr.getAttribute('data-name');
            var valueCell = tr.querySelector('.universal-tier-value-cell');
            var actionCell = tr.children[4];
            valueCell.innerHTML = '<input type="number" min="0" step="0.01" class="alpro-input universal-tier-value-input" style="padding:3px 6px; font-size:12px; width:120px;" value="' + esc(tr.getAttribute('data-value')) + '">';
            actionCell.innerHTML = '<button type="button" class="alpro-btn alpro-btn-blue universal-tier-save" style="padding:2px 8px; font-size:11px;">Save</button> '
                + '<button type="button" class="alpro-btn alpro-btn-grey universal-tier-cancel" style="padding:2px 8px; font-size:11px;">Cancel</button>';
            valueCell.querySelector('.universal-tier-value-input').focus();

            actionCell.querySelector('.universal-tier-save').addEventListener('click', function () {
                var value = valueCell.querySelector('.universal-tier-value-input').value.trim();
                confirmTierImpact(null, name, 'edit').then(function (ok) {
                    if (!ok) { render(); return; }
                    var body = new URLSearchParams();
                    body.set('action', 'update_tier');
                    body.set('id', id);
                    body.set('tier_name', name);
                    body.set('unlimited', value === '' ? '1' : '0');
                    if (value !== '') body.set('tier_value', value);
                    fetch('', { method: 'POST', body: body }).then(function (r) { return r.json(); }).then(function (res) {
                        if (res.success) tr.setAttribute('data-value', value);
                        render();
                    });
                });
            });
            actionCell.querySelector('.universal-tier-cancel').addEventListener('click', render);
        }

        render();
    })();

    // ---- Department Tiers panel - one collapsible <details> block per
    // department (see admin/aap_grouping_master.php). A department with no
    // Groups follows the Universal tiers above untouched; "Customize"
    // creates its first Group. Each Group has its own independent copy of
    // the fixed Grade/Tier/Value/Staff Name/Unlimited/Action table (a
    // department can have any number of Groups - e.g. separate teams). Each
    // block loads its own data lazily on first expand rather than all
    // 47+ at once on page load. ----
    document.querySelectorAll('.aap-dept-tier-block').forEach(function (block) {
        var deptId = block.getAttribute('data-dept-id');
        var statusEl = block.querySelector('.dept-status');
        var customizeBtn = block.querySelector('.dept-customize-btn');
        var revertBtn = block.querySelector('.dept-revert-btn');
        var editSection = block.querySelector('.dept-edit');
        var groupsContainer = block.querySelector('.dept-groups-container');
        var addGroupBtn = block.querySelector('.dept-add-group-btn');
        var loaded = false;
        var staffPoolPromise = null;

        // Staff pool is the same for every Group in this department (it's
        // just "who's in this department"), so it's fetched once and shared.
        function loadStaffPool() {
            if (!staffPoolPromise) {
                staffPoolPromise = fetch('?action=get_dept_staff_pool&department_id=' + encodeURIComponent(deptId))
                    .then(function (r) { return r.json(); })
                    .then(function (res) { return res.success ? res.staff : []; })
                    .catch(function () { return []; });
            }
            return staffPoolPromise;
        }

        function renderGroups(groups) {
            groupsContainer.innerHTML = groups.map(renderGroup).join('');
            groups.forEach(wireGroup);
        }

        function renderGroup(g) {
            return '<div class="aap-group-block" data-group-id="' + g.id + '" style="margin-bottom:16px; border:1px solid #dee2e6; border-radius:6px; overflow:hidden;">'
                + '<div style="background:#0d6efd; display:flex; align-items:stretch;">'
                + '<input type="text" class="group-name-input" value="' + esc(g.group_name) + '" placeholder="Group Name" style="flex:1; background:transparent; border:none; border-right:1px solid rgba(255,255,255,0.35); color:#fff; font-weight:700; padding:8px 12px; outline:none;">'
                + '<input type="text" class="group-desc-input" value="' + esc(g.description || '') + '" placeholder="Group Description" style="flex:2; background:transparent; border:none; color:#fff; padding:8px 12px; outline:none;">'
                + '<button type="button" class="group-delete-btn" title="Delete this Group" style="background:transparent; border:none; color:#fff; padding:0 14px; cursor:pointer; font-size:16px; font-weight:bold;">&times;</button>'
                + '</div>'
                + '<div style="padding:12px;">'
                + '<div style="display:flex; gap:8px; align-items:center; margin-bottom:8px; max-width:700px;">'
                + '<select class="alpro-input aap-filter-input group-assign-staff" style="flex:1.5; padding:3px 6px !important; min-height:0 !important; height:auto !important; line-height:1.2 !important; font-size:12px !important;"><option value="">Select Staff</option></select>'
                + '<select class="alpro-input aap-filter-input group-assign-tier" style="flex:1; padding:3px 6px !important; min-height:0 !important; height:auto !important; line-height:1.2 !important; font-size:12px !important;">'
                + '<option value="">Select Tier</option>'
                + (g.tiers || []).map(function (t) { return '<option value="' + t.id + '">' + esc(t.tier_name) + '</option>'; }).join('')
                + '</select>'
                + '<button type="button" class="alpro-btn alpro-btn-blue group-assign-btn" style="white-space:nowrap; padding:3px 12px; font-size:12px;">Assign</button>'
                + '</div>'
                + '<p class="alpro-muted" style="font-size:12px; margin:0 0 10px;">Assigning a staff member to a tier here overrides their grade-default tier within this Group - they\'ll stop following their grade and stay on the tier picked, until unassigned.</p>'
                + '<table class="alpro-table aap-ct-list-table" width="100%">'
                + '<thead><tr><th>Tier</th><th>RM Value</th><th>Staff Name</th><th>Unlimited</th><th>Action</th></tr></thead>'
                + '<tbody class="group-tbody">' + (g.tiers && g.tiers.length ? g.tiers.map(renderTierRow).join('') : '<tr><td colspan="5" class="alpro-muted">Not set up yet.</td></tr>') + '</tbody>'
                + '</table>'
                + '</div>'
                + '</div>';
        }

        function renderTierRow(t) {
            var unlimitedChecked = t.tier_value === null;
            var staffHtml = (t.staff || []).map(function (s) {
                var unassign = s.is_override ? '<span class="group-staff-unassign" data-tier-id="' + t.id + '" data-staff-id="' + s.id + '" title="Unassign - revert to grade default">&times;</span>' : '';
                return '<span class="aap-tier-staff-pill">' + esc(s.name) + unassign + '</span>';
            }).join('') || '<span class="alpro-muted">None</span>';
            return '<tr data-id="' + t.id + '" data-name="' + esc(t.tier_name) + '" data-value="' + (t.tier_value === null ? '' : t.tier_value) + '">'
                + '<td>' + esc(t.tier_name) + '</td>'
                + '<td class="group-tier-value-cell">' + esc(fmtValue(t.tier_value)) + '</td>'
                + '<td>' + staffHtml + '</td>'
                + '<td style="text-align:center;"><input type="checkbox" class="group-tier-unlimited-toggle" ' + (unlimitedChecked ? 'checked' : '') + '></td>'
                + '<td><button type="button" class="alpro-btn alpro-btn-grey group-tier-edit" style="padding:2px 8px; font-size:11px;">Edit</button></td>'
                + '</tr>';
        }

        function wireGroup(g) {
            var groupEl = groupsContainer.querySelector('.aap-group-block[data-group-id="' + g.id + '"]');
            if (!groupEl) return;
            var tbody = groupEl.querySelector('.group-tbody');
            var assignStaffSelect = groupEl.querySelector('.group-assign-staff');
            var assignTierSelect = groupEl.querySelector('.group-assign-tier');
            var assignBtn = groupEl.querySelector('.group-assign-btn');
            var nameInput = groupEl.querySelector('.group-name-input');
            var descInput = groupEl.querySelector('.group-desc-input');
            var deleteBtn = groupEl.querySelector('.group-delete-btn');

            loadStaffPool().then(function (staff) {
                assignStaffSelect.innerHTML = '<option value="">Select Staff</option>' + staff.map(function (s) {
                    return '<option value="' + s.id + '">' + esc(s.name) + '</option>';
                }).join('');
            });

            function startEdit(tr) {
                var id = tr.getAttribute('data-id');
                var name = tr.getAttribute('data-name');
                var valueCell = tr.querySelector('.group-tier-value-cell');
                var actionCell = tr.children[4];
                valueCell.innerHTML = '<input type="number" min="0" step="0.01" class="alpro-input group-tier-value-input" style="padding:3px 6px; font-size:12px; width:120px;" value="' + esc(tr.getAttribute('data-value')) + '">';
                actionCell.innerHTML = '<button type="button" class="alpro-btn alpro-btn-blue group-tier-save" style="padding:2px 8px; font-size:11px;">Save</button> '
                    + '<button type="button" class="alpro-btn alpro-btn-grey group-tier-cancel" style="padding:2px 8px; font-size:11px;">Cancel</button>';
                valueCell.querySelector('.group-tier-value-input').focus();

                actionCell.querySelector('.group-tier-save').addEventListener('click', function () {
                    var value = valueCell.querySelector('.group-tier-value-input').value.trim();
                    confirmTierImpact(deptId, name, 'edit').then(function (ok) {
                        if (!ok) { load(); return; }
                        var body = new URLSearchParams();
                        body.set('action', 'update_tier');
                        body.set('id', id);
                        body.set('tier_name', name);
                        body.set('unlimited', value === '' ? '1' : '0');
                        if (value !== '') body.set('tier_value', value);
                        fetch('', { method: 'POST', body: body }).then(function () { load(); });
                    });
                });
                actionCell.querySelector('.group-tier-cancel').addEventListener('click', load);
            }

            tbody.querySelectorAll('.group-tier-edit').forEach(function (btn) {
                btn.addEventListener('click', function () { startEdit(btn.closest('tr')); });
            });

            tbody.addEventListener('change', function (e) {
                if (!e.target.classList.contains('group-tier-unlimited-toggle')) return;
                var cb = e.target;
                var tr = cb.closest('tr');
                var id = tr.getAttribute('data-id');
                var name = tr.getAttribute('data-name');
                if (cb.checked) {
                    confirmTierImpact(deptId, name, 'edit').then(function (ok) {
                        if (!ok) { cb.checked = false; return; }
                        var body = new URLSearchParams();
                        body.set('action', 'update_tier');
                        body.set('id', id);
                        body.set('tier_name', name);
                        body.set('unlimited', '1');
                        fetch('', { method: 'POST', body: body }).then(function () { load(); });
                    });
                } else {
                    startEdit(tr);
                }
            });

            // Unassigning a manually-assigned staff member - revert to their
            // grade-default tier within this Group.
            tbody.addEventListener('click', function (e) {
                if (!e.target.classList.contains('group-staff-unassign')) return;
                var el = e.target;
                if (!confirm('Unassign this staff member? They will revert to their grade-default tier.')) return;
                var body = new URLSearchParams();
                body.set('action', 'unassign_tier_staff');
                body.set('tier_id', el.getAttribute('data-tier-id'));
                body.set('staff_id', el.getAttribute('data-staff-id'));
                fetch('', { method: 'POST', body: body }).then(function () { load(); });
            });

            assignBtn.addEventListener('click', function () {
                var staffId = assignStaffSelect.value;
                var tierId = assignTierSelect.value;
                if (!staffId || !tierId) return;
                var body = new URLSearchParams();
                body.set('action', 'assign_tier_staff');
                body.set('tier_id', tierId);
                body.set('staff_id', staffId);
                fetch('', { method: 'POST', body: body }).then(function (r) { return r.json(); }).then(function (res) {
                    if (!res.success) { alert(res.message || 'Could not assign.'); return; }
                    load();
                });
            });

            // Group Name/Description save on blur, only if actually changed.
            function saveGroupMeta() {
                var name = nameInput.value.trim();
                if (!name) { name = 'Group'; nameInput.value = name; }
                var body = new URLSearchParams();
                body.set('action', 'update_group');
                body.set('group_id', g.id);
                body.set('group_name', name);
                body.set('description', descInput.value.trim());
                fetch('', { method: 'POST', body: body });
            }
            nameInput.addEventListener('blur', saveGroupMeta);
            descInput.addEventListener('blur', saveGroupMeta);
            nameInput.addEventListener('keydown', function (e) { if (e.key === 'Enter') nameInput.blur(); });
            descInput.addEventListener('keydown', function (e) { if (e.key === 'Enter') descInput.blur(); });

            deleteBtn.addEventListener('click', function () {
                if (!confirm('Delete this Group? Its tier values and staff assignments will be removed.')) return;
                var body = new URLSearchParams();
                body.set('action', 'delete_group');
                body.set('group_id', g.id);
                fetch('', { method: 'POST', body: body }).then(function (r) { return r.json(); }).then(function () { load(); });
            });
        }

        function load() {
            statusEl.textContent = 'Loading...';
            customizeBtn.style.display = 'none';
            revertBtn.style.display = 'none';
            editSection.style.display = 'none';

            fetch('?action=get_department_groups&department_id=' + encodeURIComponent(deptId))
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (!res.success) { statusEl.textContent = res.message || 'Failed to load.'; return; }
                    if (res.is_custom) {
                        statusEl.innerHTML = '<span class="alpro-badge alpro-badge-approved">Custom</span> This department has its own Group(s).';
                        revertBtn.style.display = 'inline-block';
                        editSection.style.display = 'block';
                        renderGroups(res.groups);
                    } else {
                        statusEl.innerHTML = '<span class="alpro-badge alpro-badge-voided">Universal</span> This department follows the Universal tiers above.';
                        customizeBtn.style.display = 'inline-block';
                    }
                });
        }

        customizeBtn.addEventListener('click', function () {
            var body = new URLSearchParams();
            body.set('action', 'add_group');
            body.set('department_id', deptId);
            fetch('', { method: 'POST', body: body }).then(function (r) { return r.json(); }).then(function () { load(); });
        });

        addGroupBtn.addEventListener('click', function () {
            var body = new URLSearchParams();
            body.set('action', 'add_group');
            body.set('department_id', deptId);
            fetch('', { method: 'POST', body: body }).then(function (r) { return r.json(); }).then(function () { load(); });
        });

        revertBtn.addEventListener('click', function () {
            if (!confirm('Revert this department back to the Universal tiers? All of its Groups will be deleted.')) return;
            var body = new URLSearchParams();
            body.set('action', 'revert_department');
            body.set('department_id', deptId);
            fetch('', { method: 'POST', body: body }).then(function (r) { return r.json(); }).then(function () { load(); });
        });

        block.addEventListener('toggle', function () {
            if (block.open && !loaded) { loaded = true; load(); }
        });
    });

    // ---- Search + pagination for the department list above - purely
    // client-side (all department blocks are already in the DOM; each one's
    // own data still only loads lazily on expand, per the forEach above).
    // Search and changing page size both reset back to page 1. DataTables-
    // style controls: "Show N entries" + "Showing X to Y of Z entries" +
    // numbered page buttons with an ellipsis when there are many pages. ----
    (function () {
        var allBlocks = Array.prototype.slice.call(document.querySelectorAll('.aap-dept-tier-block'));
        var searchInput = document.getElementById('dept_search');
        var pageSizeSelect = document.getElementById('dept_page_size');
        var showingEl = document.getElementById('dept_page_showing');
        var numbersEl = document.getElementById('dept_page_numbers');
        var currentPage = 1;

        function pageSize() { return parseInt(pageSizeSelect.value, 10) || 10; }

        function matching() {
            var term = searchInput.value.trim().toLowerCase();
            if (!term) return allBlocks;
            return allBlocks.filter(function (b) {
                return b.querySelector('summary').textContent.toLowerCase().indexOf(term) !== -1;
            });
        }

        // Page number list with an ellipsis, e.g. 1 2 3 ... 8 - always shows
        // the first, last, current, and current's immediate neighbours.
        function pageButtonList(current, total) {
            var pages = [];
            for (var p = 1; p <= total; p++) {
                if (p === 1 || p === total || Math.abs(p - current) <= 1) pages.push(p);
            }
            var out = [];
            var prev = null;
            pages.forEach(function (p) {
                if (prev !== null && p - prev > 1) out.push('...');
                out.push(p);
                prev = p;
            });
            return out;
        }

        function pageBtn(label, opts) {
            opts = opts || {};
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.textContent = label;
            btn.className = 'alpro-btn';
            btn.style.padding = '4px 10px';
            btn.style.fontSize = '12px';
            btn.style.minWidth = '32px';
            if (opts.active) {
                btn.style.background = '#0d6efd';
                btn.style.color = '#fff';
                btn.style.border = '1px solid #0d6efd';
            } else if (opts.disabled) {
                btn.style.background = '#f8f9fa';
                btn.style.color = '#adb5bd';
                btn.style.border = '1px solid #e5e9ec';
                btn.disabled = true;
            } else {
                btn.style.background = '#fff';
                btn.style.color = '#212529';
                btn.style.border = '1px solid #dee2e6';
            }
            if (opts.onClick) btn.addEventListener('click', opts.onClick);
            return btn;
        }

        function renderPage() {
            var matched = matching();
            var size = pageSize();
            var totalPages = Math.max(1, Math.ceil(matched.length / size));
            if (currentPage > totalPages) currentPage = totalPages;
            var start = (currentPage - 1) * size;
            var pageBlocks = matched.slice(start, start + size);

            allBlocks.forEach(function (b) { b.style.display = 'none'; });
            pageBlocks.forEach(function (b) { b.style.display = ''; });

            showingEl.textContent = matched.length
                ? ('Showing ' + (start + 1) + ' to ' + (start + pageBlocks.length) + ' of ' + matched.length + ' entries')
                : 'No departments match.';

            numbersEl.innerHTML = '';
            numbersEl.appendChild(pageBtn('Previous', {
                disabled: currentPage <= 1,
                onClick: function () { currentPage--; renderPage(); }
            }));
            pageButtonList(currentPage, totalPages).forEach(function (p) {
                if (p === '...') {
                    var span = document.createElement('span');
                    span.textContent = '...';
                    span.style.padding = '0 4px';
                    span.style.color = '#adb5bd';
                    numbersEl.appendChild(span);
                } else {
                    numbersEl.appendChild(pageBtn(String(p), {
                        active: p === currentPage,
                        onClick: function () { currentPage = p; renderPage(); }
                    }));
                }
            });
            numbersEl.appendChild(pageBtn('Next', {
                disabled: currentPage >= totalPages,
                onClick: function () { currentPage++; renderPage(); }
            }));
        }

        searchInput.addEventListener('input', function () { currentPage = 1; renderPage(); });
        pageSizeSelect.addEventListener('change', function () { currentPage = 1; renderPage(); });

        renderPage();
    })();
});