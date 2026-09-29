document.addEventListener('DOMContentLoaded', function () {
    function esc(s) {
        var d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML;
    }

    var deptSelect = document.getElementById('dm-department');
    var staffSelect = document.getElementById('dm-staff-select');
    var addBtn = document.getElementById('dm-add-btn');
    var addMsg = document.getElementById('dm-add-msg');
    var listTbody = document.getElementById('dm-list-tbody');
    if (!deptSelect || !staffSelect || !addBtn) return;

    // Staff dropdown is populated from the picked department's actual
    // roster (staff.department membership) - just a filter to find the
    // right person, not a separate scope assignment (their Department
    // Manager scope always follows their own full staff.department field,
    // whatever departments that already lists).
    function loadStaffOptions(deptId) {
        if (!deptId) {
            staffSelect.innerHTML = '<option value="">Select Department first</option>';
            staffSelect.disabled = true;
            return;
        }
        staffSelect.disabled = true;
        staffSelect.innerHTML = '<option value="">Loading...</option>';
        fetch('?action=get_dept_staff&department_id=' + encodeURIComponent(deptId))
            .then(function (r) {
                // A non-JSON body (a leaked PHP warning/redirect/HTML error
                // page instead of the expected JSON) throws inside r.json()
                // with a useless generic message - read it as text first so
                // a real failure can say what actually came back, instead of
                // collapsing into an opaque "Failed to load".
                return r.text().then(function (text) {
                    try {
                        return JSON.parse(text);
                    } catch (e) {
                        throw new Error('Non-JSON response (HTTP ' + r.status + '): ' + text.slice(0, 200));
                    }
                });
            })
            .then(function (res) {
                if (!res.success) {
                    staffSelect.innerHTML = '<option value="">' + esc(res.message || 'Failed to load') + '</option>';
                    return;
                }
                var staff = res.staff || [];
                if (!staff.length) {
                    staffSelect.innerHTML = '<option value="">No staff found in this department</option>';
                    return;
                }
                staffSelect.innerHTML = '<option value="">Select Staff</option>' + staff.map(function (s) {
                    var label = s.nama_staff + (s.aap === 2 ? ' (already a Manager)' : (s.aap === 1 ? ' (Admin)' : ''));
                    return '<option value="' + s.id + '">' + esc(label) + '</option>';
                }).join('');
                staffSelect.disabled = false;
            })
            .catch(function (err) {
                console.error('get_dept_staff failed:', err);
                staffSelect.innerHTML = '<option value="">Failed to load - ' + esc(err.message || String(err)) + '</option>';
            });
    }

    deptSelect.addEventListener('change', function () { loadStaffOptions(deptSelect.value); });

    addBtn.addEventListener('click', function () {
        var staffId = staffSelect.value;
        if (!staffId) {
            addMsg.textContent = 'Pick a staff member.';
            addMsg.style.color = '#dc3545';
            return;
        }
        var body = new URLSearchParams({ action: 'set_department_manager', staff_id: staffId, make_manager: '1' });
        fetch('', { method: 'POST', body: body }).then(function (r) { return r.json(); }).then(function (res) {
            addMsg.textContent = res.message || (res.success ? 'Done.' : 'Failed.');
            addMsg.style.color = res.success ? '#28a745' : '#dc3545';
            if (res.success) location.reload();
        });
    });

    if (listTbody) {
        listTbody.querySelectorAll('.dm-remove-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                if (!confirm('Remove Department Manager access for this staff member?')) return;
                var tr = btn.closest('tr');
                var staffId = tr.getAttribute('data-id');
                var body = new URLSearchParams({ action: 'set_department_manager', staff_id: staffId, make_manager: '0' });
                fetch('', { method: 'POST', body: body }).then(function (r) { return r.json(); }).then(function (res) {
                    // Reload rather than just removing the <tr> - the Department
                    // cell uses rowspan to group same-department rows together,
                    // so removing one row client-side (especially the one
                    // actually carrying that rowspan cell) would desync the
                    // grouping until the next full reload anyway.
                    if (res.success) {
                        location.reload();
                    } else {
                        alert(res.message || 'Failed.');
                    }
                });
            });
        });
    }
});
