document.addEventListener('DOMContentLoaded', function () {
    function esc(s) {
        var d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML;
    }

    var searchInput = document.getElementById('sa2-search');
    var resultsBox = document.getElementById('sa2-search-results');
    var emptyEl = document.getElementById('sa2-empty');
    var resultEl = document.getElementById('sa2-result');
    var staffNameEl = document.getElementById('sa2-staff-name');
    var staffDeptEl = document.getElementById('sa2-staff-dept');
    var staffBadgeEl = document.getElementById('sa2-staff-badge');
    var removeAllBtn = document.getElementById('sa2-remove-all');
    var reassignAllBtn = document.getElementById('sa2-reassign-all');
    var reassignAllPicker = document.getElementById('sa2-reassign-all-picker');
    var reassignAllInput = document.getElementById('sa2-reassign-all-input');
    var reassignAllResults = document.getElementById('sa2-reassign-all-results');
    var tbody = document.getElementById('sa2-tbody');

    var currentStaffId = null;
    var searchTimer = null;

    function renderResults(staff) {
        if (!staff.length) {
            resultsBox.innerHTML = '<div style="padding:10px 12px; color:#6c757d; font-size:12px;">No staff matched.</div>';
            resultsBox.style.display = 'block';
            return;
        }
        resultsBox.innerHTML = staff.map(function (s) {
            var badge = s.resigned ? ' <span class="alpro-badge alpro-badge-voided" style="font-size:9px;">Resigned</span>' : '';
            return '<div class="sa2-result-row" data-id="' + s.id + '" style="padding:8px 12px; cursor:pointer; border-bottom:1px solid #f1f3f5;">' +
                '<strong>' + esc(s.nama_staff) + '</strong>' + badge +
                '<div style="font-size:11px; color:#6c757d;">' + esc(s.department_name) + '</div></div>';
        }).join('');
        resultsBox.style.display = 'block';
        resultsBox.querySelectorAll('.sa2-result-row').forEach(function (row) {
            row.addEventListener('mouseenter', function () { row.style.background = '#f5f9ff'; });
            row.addEventListener('mouseleave', function () { row.style.background = ''; });
            row.addEventListener('click', function () {
                resultsBox.style.display = 'none';
                loadAssignments(parseInt(row.dataset.id, 10));
            });
        });
    }

    searchInput.addEventListener('input', function () {
        var q = searchInput.value.trim();
        if (searchTimer) clearTimeout(searchTimer);
        if (q === '') { resultsBox.style.display = 'none'; return; }
        searchTimer = setTimeout(function () {
            fetch('?action=search_staff&q=' + encodeURIComponent(q))
                .then(function (r) { return r.json(); })
                .then(function (res) { renderResults(res.success ? res.staff : []); })
                .catch(function () { renderResults([]); });
        }, 300);
    });

    document.addEventListener('click', function (e) {
        if (!resultsBox.contains(e.target) && e.target !== searchInput) {
            resultsBox.style.display = 'none';
        }
    });

    function loadAssignments(staffId) {
        fetch('?action=get_assignments&staff_id=' + staffId)
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res.success) { alert(res.message || 'Could not load assignments.'); return; }
                currentStaffId = staffId;
                renderStaff(res.staff, res.assignments);
            })
            .catch(function () { alert('Could not load assignments.'); });
    }

    function renderStaff(staff, assignments) {
        emptyEl.style.display = 'none';
        resultEl.style.display = 'block';
        searchInput.value = staff.nama_staff;

        staffNameEl.textContent = staff.nama_staff;
        staffDeptEl.textContent = staff.department_name;
        staffBadgeEl.innerHTML = staff.resigned
            ? '<span class="alpro-badge alpro-badge-voided">Resigned</span>'
            : '<span class="alpro-badge alpro-badge-approved">Active</span>';
        removeAllBtn.style.display = assignments.length ? 'inline-block' : 'none';
        reassignAllBtn.style.display = assignments.length ? 'inline-block' : 'none';
        reassignAllPicker.style.display = 'none';
        reassignAllInput.value = '';
        reassignAllResults.innerHTML = '';

        if (!assignments.length) {
            tbody.innerHTML = '<tr><td colspan="5" align="center" style="padding:15px;">No Case Type assignments for this staff.</td></tr>';
            return;
        }
        tbody.innerHTML = assignments.map(function (a) {
            var retiredNote = a.case_type_retired ? ' <span class="alpro-muted" style="font-size:11px;">(retired)</span>' : '';
            var sectionLabel = a.section === 'approval' ? 'Approval' : 'Exclusion';
            return '<tr data-id="' + a.id + '">' +
                '<td>' + esc(a.case_type_name) + retiredNote + '</td>' +
                '<td>' + esc(a.department_name) + '</td>' +
                '<td>' + sectionLabel + '</td>' +
                '<td>' + esc(a.tier) + '</td>' +
                '<td style="white-space:nowrap;">' +
                '<button type="button" class="alpro-btn alpro-btn-grey sa2-change-row" style="padding:3px 8px; font-size:11px;">Change</button> ' +
                '<button type="button" class="alpro-btn alpro-btn-grey sa2-remove-row" style="padding:3px 8px; font-size:11px;">Remove</button>' +
                '</td>' +
                '</tr>' +
                '<tr class="sa2-change-picker-row" data-for="' + a.id + '" style="display:none;">' +
                '<td colspan="5" style="background:#f8f9fa; padding:10px 14px;">' +
                '<div style="display:flex; align-items:center; gap:8px; position:relative; max-width:360px;">' +
                '<input type="text" class="alpro-input sa2-change-input" placeholder="Search staff to reassign to..." style="padding:4px 8px; font-size:12px;">' +
                '<button type="button" class="alpro-btn alpro-btn-grey sa2-change-cancel" style="padding:3px 8px; font-size:11px;">Cancel</button>' +
                '<div class="sa2-change-results" style="display:none; position:absolute; top:100%; left:0; right:80px; background:#fff; border:1px solid #dee2e6; border-radius:6px; margin-top:4px; max-height:220px; overflow-y:auto; z-index:20; box-shadow:0 4px 12px rgba(0,0,0,0.08);"></div>' +
                '</div>' +
                '</td>' +
                '</tr>';
        }).join('');

        tbody.querySelectorAll('.sa2-remove-row').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var tr = btn.closest('tr');
                if (!confirm('Remove this assignment?')) return;
                fetch('', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({ action: 'remove_assignment', id: tr.dataset.id })
                })
                    .then(function (r) { return r.json(); })
                    .then(function () { loadAssignments(currentStaffId); })
                    .catch(function () { alert('Could not remove assignment.'); });
            });
        });

        tbody.querySelectorAll('.sa2-change-row').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var tr = btn.closest('tr');
                var pickerRow = tbody.querySelector('.sa2-change-picker-row[data-for="' + tr.dataset.id + '"]');
                // Close any other open picker first.
                tbody.querySelectorAll('.sa2-change-picker-row').forEach(function (r) { if (r !== pickerRow) r.style.display = 'none'; });
                pickerRow.style.display = pickerRow.style.display === 'none' ? 'table-row' : 'none';
                if (pickerRow.style.display === 'table-row') pickerRow.querySelector('.sa2-change-input').focus();
            });
        });

        tbody.querySelectorAll('.sa2-change-cancel').forEach(function (btn) {
            btn.addEventListener('click', function () { btn.closest('.sa2-change-picker-row').style.display = 'none'; });
        });

        tbody.querySelectorAll('.sa2-change-picker-row').forEach(function (pickerRow) {
            var assignmentId = pickerRow.dataset.for;
            var input = pickerRow.querySelector('.sa2-change-input');
            var resultsBox2 = pickerRow.querySelector('.sa2-change-results');
            var timer = null;

            input.addEventListener('input', function () {
                var q = input.value.trim();
                if (timer) clearTimeout(timer);
                if (q === '') { resultsBox2.style.display = 'none'; return; }
                timer = setTimeout(function () {
                    fetch('?action=search_staff&q=' + encodeURIComponent(q))
                        .then(function (r) { return r.json(); })
                        .then(function (res) {
                            var list = res.success ? res.staff : [];
                            if (!list.length) {
                                resultsBox2.innerHTML = '<div style="padding:8px 10px; color:#6c757d; font-size:12px;">No staff matched.</div>';
                                resultsBox2.style.display = 'block';
                                return;
                            }
                            resultsBox2.innerHTML = list.map(function (s) {
                                var badge = s.resigned ? ' <span class="alpro-badge alpro-badge-voided" style="font-size:9px;">Resigned</span>' : '';
                                return '<div class="sa2-change-option" data-id="' + s.id + '" style="padding:6px 10px; cursor:pointer; border-bottom:1px solid #f1f3f5; font-size:12px;">' +
                                    esc(s.nama_staff) + badge +
                                    '<div style="font-size:10px; color:#6c757d;">' + esc(s.department_name) + '</div></div>';
                            }).join('');
                            resultsBox2.style.display = 'block';
                            resultsBox2.querySelectorAll('.sa2-change-option').forEach(function (opt) {
                                opt.addEventListener('mouseenter', function () { opt.style.background = '#f5f9ff'; });
                                opt.addEventListener('mouseleave', function () { opt.style.background = ''; });
                                opt.addEventListener('click', function () {
                                    fetch('', {
                                        method: 'POST',
                                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                                        body: new URLSearchParams({ action: 'change_assignment', id: assignmentId, new_staff_id: opt.dataset.id })
                                    })
                                        .then(function (r) { return r.json(); })
                                        .then(function (res) {
                                            if (!res.success) { alert(res.message || 'Could not reassign.'); return; }
                                            loadAssignments(currentStaffId);
                                        })
                                        .catch(function () { alert('Could not reassign.'); });
                                });
                            });
                        })
                        .catch(function () { resultsBox2.style.display = 'none'; });
                }, 300);
            });
        });
    }

    removeAllBtn.addEventListener('click', function () {
        if (!currentStaffId) return;
        if (!confirm('Remove ALL Case Type Staff Tier assignments for ' + staffNameEl.textContent + '? This cannot be undone.')) return;
        fetch('', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ action: 'remove_all_assignments', staff_id: currentStaffId })
        })
            .then(function (r) { return r.json(); })
            .then(function () { loadAssignments(currentStaffId); })
            .catch(function () { alert('Could not remove assignments.'); });
    });

    reassignAllBtn.addEventListener('click', function () {
        reassignAllPicker.style.display = reassignAllPicker.style.display === 'none' ? 'block' : 'none';
        if (reassignAllPicker.style.display === 'block') reassignAllInput.focus();
    });

    document.addEventListener('click', function (e) {
        if (!reassignAllPicker.contains(e.target) && e.target !== reassignAllBtn) {
            reassignAllPicker.style.display = 'none';
        }
    });

    var reassignAllTimer = null;
    reassignAllInput.addEventListener('input', function () {
        var q = reassignAllInput.value.trim();
        if (reassignAllTimer) clearTimeout(reassignAllTimer);
        if (q === '') { reassignAllResults.innerHTML = ''; return; }
        reassignAllTimer = setTimeout(function () {
            fetch('?action=search_staff&q=' + encodeURIComponent(q))
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    var list = (res.success ? res.staff : []).filter(function (s) { return s.id !== currentStaffId; });
                    if (!list.length) {
                        reassignAllResults.innerHTML = '<div style="padding:6px 8px; color:#6c757d; font-size:12px;">No staff matched.</div>';
                        return;
                    }
                    reassignAllResults.innerHTML = list.map(function (s) {
                        var badge = s.resigned ? ' <span class="alpro-badge alpro-badge-voided" style="font-size:9px;">Resigned</span>' : '';
                        return '<div class="sa2-reassign-all-option" data-id="' + s.id + '" style="padding:6px 8px; cursor:pointer; border-bottom:1px solid #f1f3f5; font-size:12px;">' +
                            esc(s.nama_staff) + badge +
                            '<div style="font-size:10px; color:#6c757d;">' + esc(s.department_name) + '</div></div>';
                    }).join('');
                    reassignAllResults.querySelectorAll('.sa2-reassign-all-option').forEach(function (opt) {
                        opt.addEventListener('mouseenter', function () { opt.style.background = '#f5f9ff'; });
                        opt.addEventListener('mouseleave', function () { opt.style.background = ''; });
                        opt.addEventListener('click', function () {
                            var targetName = opt.textContent.trim();
                            if (!confirm('Reassign ALL Case Type Staff Tier assignments from ' + staffNameEl.textContent + ' to ' + targetName + '?')) return;
                            fetch('', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                                body: new URLSearchParams({ action: 'reassign_all_assignments', staff_id: currentStaffId, new_staff_id: opt.dataset.id })
                            })
                                .then(function (r) { return r.json(); })
                                .then(function (res) {
                                    if (!res.success) { alert(res.message || 'Could not reassign.'); return; }
                                    reassignAllPicker.style.display = 'none';
                                    var summary = res.moved + ' assignment(s) moved';
                                    if (res.skipped) summary += ', ' + res.skipped + ' already held by that staff member (dropped)';
                                    alert(summary + '.');
                                    loadAssignments(currentStaffId);
                                })
                                .catch(function () { alert('Could not reassign.'); });
                        });
                    });
                })
                .catch(function () { reassignAllResults.innerHTML = ''; });
        }, 300);
    });
});
