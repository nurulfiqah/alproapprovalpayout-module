document.addEventListener('DOMContentLoaded', function () {
    function esc(s) {
        var d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML;
    }

    function fmtValue(v) {
        return v === null ? 'Unlimited' : 'RM ' + Number(v).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    // Tier options are that department's own Approval Unit Master tiers (or
    // the shared Default list if it hasn't customized any) - never a fixed
    // Unlimited/Tier 1/Tier 2 list. Cached per department_id since the same
    // department is looked up repeatedly (row renders, re-renders on staff
    // pick, etc).
    var tierOptionsCache = {};
    function getTierOptions(deptId) {
        if (tierOptionsCache[deptId]) return tierOptionsCache[deptId];
        var p = fetch('?action=get_tier_options&department_id=' + encodeURIComponent(deptId))
            .then(function (r) { return r.json(); })
            .then(function (res) { return res.success ? res.tiers : []; })
            .catch(function () { return []; });
        tierOptionsCache[deptId] = p;
        return p;
    }

    // One Department -> Group table block. Level 2 (approval) and Level 3
    // (exclusion) each get their own independent instance (own dropdowns,
    // own table) via distinct element id suffixes - see aap_admin.php.
    // Returns { addRow, collectRows } so edit-mode prefill can push existing
    // rows in and the submit handler below can serialize the table.
    //
    // Only one Group per level - picking a Group replaces the whole table
    // with every staff member belonging to it (admin/aap_grouping_master.php),
    // each starting at the tier they hold there, no explicit Add step.
    // Picking a different Group (or clearing the Department) replaces/clears
    // the table rather than appending to it. Each row's Tier cell shows that
    // row's own department's real tier name/value, read-only - fix it at the
    // Group (Approval Unit Master) and re-pick if it's wrong.
    function initStaffTier(ids) {
        var deptSelect = document.getElementById(ids.dept);
        var groupSelect = document.getElementById(ids.group);
        var tierTbody = document.getElementById(ids.tbody);
        if (!deptSelect) return null;

        // Group Name alone ("Group 1") isn't enough to tell Groups apart -
        // Description is what an admin actually set it up for (e.g.
        // "Academy Moodle"), so show both: "Group 1: Academy Moodle".
        // Falls back to just the name if no Description has been set yet.
        function renderGroupOptions(groups) {
            if (!groupSelect) return;
            groupSelect.innerHTML = '<option value="">Select Group</option>' + groups.map(function (g) {
                var label = g.description ? (g.group_name + ': ' + g.description) : g.group_name;
                return '<option value="' + g.id + '">' + esc(label) + '</option>';
            }).join('');
        }

        // preselectGroupId is only ever passed on the very first load (the
        // Case Type's already-saved Group, if any) - once the picker's up and
        // running, a later "change" on the Department select means the user
        // is actively re-picking, so that always starts back at "Select
        // Group" rather than trying to guess a group in the new department.
        function loadDeptGroups(preselectGroupId) {
            if (!groupSelect) return;
            var deptId = deptSelect.value;
            if (!deptId) {
                renderGroupOptions([]);
                return;
            }
            fetch('?action=get_dept_groups&department_id=' + encodeURIComponent(deptId))
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    renderGroupOptions(res.success ? res.groups : []);
                    if (preselectGroupId !== undefined && preselectGroupId !== null) {
                        var opt = groupSelect.querySelector('option[value="' + preselectGroupId + '"]');
                        // The already-saved assignment was Default (Universal),
                        // but this department has since set up its own Group(s)
                        // - get_dept_groups only offers Default as a fallback
                        // when there are NONE, so it won't be in the list here.
                        // Add it back in just so what's actually saved still
                        // shows correctly, instead of a blank "Select Group".
                        if (!opt && preselectGroupId === '0') {
                            opt = document.createElement('option');
                            opt.value = '0';
                            opt.textContent = 'Default (Universal)';
                            groupSelect.insertBefore(opt, groupSelect.options[1] || null);
                        }
                        if (opt) groupSelect.value = preselectGroupId;
                    }
                })
                .catch(function () {
                    renderGroupOptions([]);
                });
        }

        deptSelect.addEventListener('change', function () { loadDeptGroups(); });
        loadDeptGroups(ids.currentGroupId);

        function fetchGroupStaffTiers(deptId, groupId) {
            return fetch('?action=get_group_staff_tiers&department_id=' + encodeURIComponent(deptId) + '&group_id=' + encodeURIComponent(groupId))
                .then(function (r) { return r.json(); })
                .then(function (res) { return res.success ? res.staff : []; })
                .catch(function () { return []; });
        }

        // A row's Tier is read-only text, not a picker - who's in a Group and
        // what tier they hold there is decided in admin/aap_grouping_master.php
        // (the Approval Unit Master), not per Case Type. There's no × either:
        // membership follows that Group's setup, so removing someone here
        // would just silently drift from it. To change who's assigned or at
        // what tier, fix it at the source (the Group) and re-Add.
        function tierDisplayHtml(tiers, tierName) {
            var t = tiers.find(function (x) { return x.tier_name === tierName; });
            return t ? (esc(t.tier_name) + ' (' + esc(fmtValue(t.tier_value)) + ')') : esc(tierName || '');
        }

        // Adding a staff member already in the table just updates their row
        // (department + Group + tier) instead of creating a duplicate.
        // groupId is stamped onto the row so the server can save it onto
        // aap_case_type_staff_tiers.group_id - lets the live approval gate
        // resolve this staff member's tier against that exact Group instead
        // of an ambiguous department-wide match (see sql/aap_master.sql).
        // null/0 (the "Default (Universal)" entry, or an edit-mode row saved
        // before group_id existed) is sent through as-is.
        function addRow(staffId, staffName, deptId, tier, groupId) {
            var existing = tierTbody.querySelector('tr[data-staff-id="' + staffId + '"]');
            var tr = existing || document.createElement('tr');
            tr.setAttribute('data-staff-id', staffId);
            tr.setAttribute('data-department-id', deptId);
            tr.setAttribute('data-tier', tier || '');
            if (groupId === null || groupId === undefined || groupId === '' || groupId === 0 || groupId === '0') {
                tr.removeAttribute('data-group-id');
            } else {
                tr.setAttribute('data-group-id', groupId);
            }
            tr.innerHTML = '<td>' + esc(staffName) + '</td><td class="ct-pool-tier-cell">Loading...</td>';
            if (!existing) tierTbody.appendChild(tr);

            getTierOptions(deptId).then(function (tiers) {
                tr.querySelector('.ct-pool-tier-cell').innerHTML = tierDisplayHtml(tiers, tier);
            });
        }

        // Picking a Group fully replaces the table - it's this level's only
        // Group, not one of several being accumulated.
        if (groupSelect) {
            groupSelect.addEventListener('change', function () {
                var groupId = groupSelect.value;
                var deptId = deptSelect.value;
                tierTbody.innerHTML = '';
                if (!groupId || !deptId) return;
                fetchGroupStaffTiers(deptId, groupId).then(function (staff) {
                    staff.forEach(function (st) {
                        addRow(st.staff_id, st.staff_name, deptId, st.tier, st.group_id);
                    });
                });
            });
        }
        // Clearing/changing the Department invalidates whatever Group was
        // picked under the previous one - clear the table so it can't
        // silently keep a stale department's staff.
        deptSelect.addEventListener('change', function () { tierTbody.innerHTML = ''; });

        function collectRows() {
            return Array.prototype.map.call(tierTbody.querySelectorAll('tr'), function (tr) {
                return {
                    staff_id: tr.getAttribute('data-staff-id'),
                    department_id: tr.getAttribute('data-department-id'),
                    group_id: tr.getAttribute('data-group-id'),
                    tier: tr.getAttribute('data-tier')
                };
            });
        }

        return { addRow: addRow, collectRows: collectRows, deptSelect: deptSelect };
    }

    // The Group dropdown itself doesn't carry a "current selection" from the
    // server the way the Department dropdown does - it's derived here from
    // whichever Group the already-saved rows actually belong to (they can
    // only ever belong to one, per Case Type/section). null group_id on a
    // saved row means the Default (Universal) entry, whose option value is
    // the "0" sentinel (see aapFetchDepartmentGroupNames() in aap_lib.php).
    function firstGroupId(tiers) {
        if (!tiers || !tiers.length) return null;
        var g = tiers[0].group_id;
        return (g === null || g === undefined) ? '0' : String(g);
    }

    var approvalTier = initStaffTier({ dept: 'ct_pool_department', group: 'ct_pool_group', tbody: 'ct_staff_tier_tbody', currentGroupId: firstGroupId(AAP_ADMIN.approvalTiers) });
    var exclusionTier = initStaffTier({ dept: 'ct_pool_department_lvl3', group: 'ct_pool_group_lvl3', tbody: 'ct_staff_tier_tbody_lvl3', currentGroupId: firstGroupId(AAP_ADMIN.exclusionTiers) });

    // Editing an existing Case Type - prefill both tables from what's already
    // saved (AAP_ADMIN.approvalTiers/exclusionTiers, from aap_admin.php).
    if (!AAP_ADMIN.isNew) {
        (AAP_ADMIN.approvalTiers || []).forEach(function (r) {
            if (approvalTier) approvalTier.addRow(r.staff_id, r.staff_name, r.department_id, r.tier, r.group_id);
        });
        (AAP_ADMIN.exclusionTiers || []).forEach(function (r) {
            if (exclusionTier) exclusionTier.addRow(r.staff_id, r.staff_name, r.department_id, r.tier, r.group_id);
        });
    }

    // Serialize both Staff Tier tables into the hidden JSON inputs the
    // save_case_type PHP handler reads (see aapSaveCaseTypeStaffTiers() in
    // admin/aap_admin.php).
    var form = document.querySelector('.aap-ct-card form');
    var approvalJsonInput = document.getElementById('ct_approval_tiers_json');
    var exclusionJsonInput = document.getElementById('ct_exclusion_tiers_json');
    if (form) {
        form.addEventListener('submit', function (e) {
            var approvalRows = approvalTier ? approvalTier.collectRows() : [];
            var exclusionRows = exclusionTier ? exclusionTier.collectRows() : [];
            if (approvalJsonInput) approvalJsonInput.value = JSON.stringify(approvalRows);
            if (exclusionJsonInput) exclusionJsonInput.value = JSON.stringify(exclusionRows);

            // Level 2/3 Staff Tier and Description are all required (server
            // re-checks this too - see aap_admin.php's save_case_type
            // handler) - a Case Type nobody can approve or execute under
            // isn't useful to save.
            if (!approvalRows.length) {
                e.preventDefault();
                alert('At least one Group must be added under Level 2 - Approval Mode Assign.');
                return;
            }
            if (!exclusionRows.length) {
                e.preventDefault();
                alert('At least one Group must be added under Level 3 - Approval Exclusion Assign.');
                return;
            }
        });
    }

    // ---- Filter + pagination for the Case Type Registry table below - all
    // rows are already in the DOM (rendered server-side), filtering and
    // paging both happen purely client-side. Same DataTables-style controls
    // as the Department Tiers list on admin/aap_grouping_master.php. ----
    (function () {
        var tbody = document.getElementById('ct_list_tbody');
        if (!tbody) return;
        var allRows = Array.prototype.slice.call(tbody.querySelectorAll('.ct-list-row'));
        var nameInput = document.getElementById('ct_filter_name');
        var deptSelect = document.getElementById('ct_filter_dept');
        var approvalInput = document.getElementById('ct_filter_approval');
        var exclusionInput = document.getElementById('ct_filter_exclusion');
        var physicalSelect = document.getElementById('ct_filter_physical');
        var statusSelect = document.getElementById('ct_filter_status');
        var pageSizeSelect = document.getElementById('ct_page_size');
        var showingEl = document.getElementById('ct_page_showing');
        var numbersEl = document.getElementById('ct_page_numbers');
        var currentPage = 1;

        function pageSize() { return parseInt(pageSizeSelect.value, 10) || 10; }

        function matching() {
            var name = nameInput.value.trim().toLowerCase();
            var dept = deptSelect ? deptSelect.value : '';
            var approval = approvalInput.value.trim().toLowerCase();
            var exclusion = exclusionInput.value.trim().toLowerCase();
            var physical = physicalSelect.value;
            var status = statusSelect.value;
            return allRows.filter(function (r) {
                if (name && r.getAttribute('data-name').indexOf(name) === -1) return false;
                if (dept && r.getAttribute('data-dept') !== dept) return false;
                if (approval && r.getAttribute('data-approval').indexOf(approval) === -1) return false;
                if (exclusion && r.getAttribute('data-exclusion').indexOf(exclusion) === -1) return false;
                if (physical && r.getAttribute('data-physical') !== physical) return false;
                if (status && r.getAttribute('data-status') !== status) return false;
                return true;
            });
        }

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
            var pageRows = matched.slice(start, start + size);

            allRows.forEach(function (r) { r.style.display = 'none'; });
            pageRows.forEach(function (r) { r.style.display = ''; });

            showingEl.textContent = matched.length
                ? ('Showing ' + (start + 1) + ' to ' + (start + pageRows.length) + ' of ' + matched.length + ' entries')
                : 'No case types match.';

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

        [nameInput, approvalInput, exclusionInput].forEach(function (el) {
            el.addEventListener('input', function () { currentPage = 1; renderPage(); });
        });
        [deptSelect, physicalSelect, statusSelect, pageSizeSelect].forEach(function (el) {
            if (!el) return;
            el.addEventListener('change', function () { currentPage = 1; renderPage(); });
        });

        renderPage();
    })();
});