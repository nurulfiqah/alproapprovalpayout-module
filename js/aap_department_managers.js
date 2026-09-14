document.addEventListener('DOMContentLoaded', function () {
    function esc(s) {
        var d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML;
    }

    var deptSelect = document.getElementById('dm-department');
    var staffSelect = document.getElementById('dm-staff-select');
    var addBtn = document.getElementById('dm-add-btn');
    var addMsg = document.getElementById('dm-add-msg');
    var listTbody = document.getElementById('dm-list-tbody');

    // Staff dropdown is populated from the picked department's actual
    // roster (staff.department membership) - disabled with no department
    // picked yet, since there's nothing to list.
    function loadStaffOptions(deptId) {
        if (!deptId) {
            staffSelect.innerHTML = '<option value="">Select Department first</option>';
            staffSelect.disabled = true;
            return;
        }
        staffSelect.disabled = true;
        staffSelect.innerHTML = '<option value="">Loading...</option>';
        fetch('?action=get_dept_staff&department_id=' + encodeURIComponent(deptId))
            .then(function (r) { return r.json(); })
            .then(function (res) {
                var staff = res.success ? res.staff : [];
                if (!staff.length) {
                    staffSelect.innerHTML = '<option value="">No staff found in this department</option>';
                    return;
                }
                staffSelect.innerHTML = '<option value="">Select Staff</option>' + staff.map(function (s) {
                    return '<option value="' + s.id + '">' + esc(s.nama_staff) + '</option>';
                }).join('');
                staffSelect.disabled = false;
            })
            .catch(function () {
                staffSelect.innerHTML = '<option value="">Failed to load</option>';
            });
    }

    deptSelect.addEventListener('change', function () { loadStaffOptions(deptSelect.value); });

    addBtn.addEventListener('click', function () {
        var deptId = deptSelect.value;
        var staffId = staffSelect.value;
        if (!deptId || !staffId) {
            addMsg.textContent = 'Pick a department and a staff member.';
            addMsg.style.color = '#dc3545';
            return;
        }
        var body = new URLSearchParams({ action: 'add_manager', department_id: deptId, staff_id: staffId });
        fetch('', { method: 'POST', body: body }).then(function (r) { return r.json(); }).then(function (res) {
            addMsg.textContent = res.message || (res.success ? 'Added.' : 'Failed.');
            addMsg.style.color = res.success ? '#28a745' : '#dc3545';
            if (res.success) location.reload();
        });
    });

    listTbody.querySelectorAll('.dm-remove-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!confirm('Revoke this Department Manager grant?')) return;
            var tr = btn.closest('tr');
            var id = tr.getAttribute('data-id');
            var body = new URLSearchParams({ action: 'remove_manager', id: id });
            fetch('', { method: 'POST', body: body }).then(function (r) { return r.json(); }).then(function (res) {
                // Reload rather than just removing the <tr> - the Department
                // cell uses rowspan to group same-department rows together,
                // so removing one row client-side (especially the one
                // actually carrying that rowspan cell) would desync the
                // grouping until the next full reload anyway.
                if (res.success) location.reload();
            });
        });
    });
});
