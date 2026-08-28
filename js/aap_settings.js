(function () {
    var selectedStaffId = null;
    var currentPage = 1;
    var perPage = 30;
    var nameFilter = '';

    // Surfaces any JS error directly in the table instead of leaving it
    // stuck on the static "Loading..." placeholder forever - lets this be
    // diagnosed without needing to open DevTools.
    function showFatalError(err) {
        var tbody = document.getElementById('sa-staff-tbody');
        if (tbody) {
            tbody.innerHTML = '<tr><td colspan="4" align="center" style="padding:15px; color:#dc3545;">Script error: ' + (err && err.message ? err.message : String(err)) + '</td></tr>';
        }
    }

    function badge(isYes) {
        return '<span class="alpro-badge alpro-badge-' + (isYes ? 'approved' : 'voided') + '">' + (isYes ? 'Yes' : 'No') + '</span>';
    }
    function esc(s) {
        var d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML;
    }

    function loadStaff(page) {
        currentPage = page || 1;
        var tbody = document.getElementById('sa-staff-tbody');
        tbody.innerHTML = '<tr><td colspan="4" align="center" style="padding:15px;">Loading...</td></tr>';

        var url = '?action=list_aap_superadmin&page=' + currentPage + '&per_page=' + perPage;
        if (nameFilter !== '') url += '&name_filter=' + encodeURIComponent(nameFilter);

        fetch(url).then(function (r) { return r.json(); }).then(function (res) {
            if (!res.success) { tbody.innerHTML = '<tr><td colspan="4" align="center" style="padding:15px;">Failed to load.</td></tr>'; return; }
            renderTable(res.data);
            renderPager(res.page, res.total_pages, res.total, res.per_page);
        }).catch(function () {
            tbody.innerHTML = '<tr><td colspan="4" align="center" style="padding:15px;">Error loading data.</td></tr>';
        });
    }

    function renderTable(data) {
        var tbody = document.getElementById('sa-staff-tbody');
        if (!data || !data.length) {
            tbody.innerHTML = '<tr><td colspan="4" align="center" style="padding:15px;">' + (nameFilter !== '' ? 'No staff matched that name.' : 'No current AAP SuperAdmins.') + '</td></tr>';
            return;
        }
        var html = '';
        data.forEach(function (s) {
            html += '<tr>'
                + '<td>' + esc(s.nama_staff) + '</td>'
                + '<td class="alpro-muted">' + esc(s.department_name) + '</td>'
                + '<td>' + badge(s.aap === 1) + '</td>'
                + '<td><button type="button" class="alpro-btn alpro-btn-grey sa-edit-btn" data-id="' + s.id + '" data-name="' + esc(s.nama_staff) + '" data-dept="' + esc(s.department_name) + '" data-aap="' + s.aap + '">Edit</button></td>'
                + '</tr>';
        });
        tbody.innerHTML = html;
    }

    function pageBtn(p, active) {
        return '<button type="button" class="sa-pager-btn' + (p === active ? ' active' : '') + '" data-page="' + p + '">' + p + '</button>';
    }

    function renderPager(page, totalPages, total, pp) {
        var pager = document.getElementById('sa-staff-pager');
        if (total === 0) { pager.innerHTML = ''; return; }
        var start = (page - 1) * pp + 1, end = Math.min(page * pp, total);
        var info = 'Showing ' + start + ' to ' + end + ' of ' + total + ' entries';

        if (totalPages <= 1) { pager.innerHTML = '<span>' + info + '</span>'; return; }

        var win = 2, from = Math.max(1, page - win), to = Math.min(totalPages, page + win);
        var btns = '<button type="button" class="sa-pager-btn" data-page="' + (page - 1) + '"' + (page === 1 ? ' disabled' : '') + '>Prev</button>';
        if (from > 1) btns += pageBtn(1, page) + (from > 2 ? '<span>…</span>' : '');
        for (var p = from; p <= to; p++) btns += pageBtn(p, page);
        if (to < totalPages) btns += (to < totalPages - 1 ? '<span>…</span>' : '') + pageBtn(totalPages, page);
        btns += '<button type="button" class="sa-pager-btn" data-page="' + (page + 1) + '"' + (page === totalPages ? ' disabled' : '') + '>Next</button>';

        pager.innerHTML = '<span>' + info + '</span><div class="sa-pager-bar">' + btns + '</div>';
    }

    try {
        var pagerEl = document.getElementById('sa-staff-pager');
        var applyBtn = document.getElementById('sa-apply-filter');
        var resetBtn = document.getElementById('sa-reset-filter');
        var filterNameEl = document.getElementById('sa-filter-name');
        var tbodyEl = document.getElementById('sa-staff-tbody');
        var cancelBtn = document.getElementById('sa-cancel-btn');
        var updateBtn = document.getElementById('sa-update-btn');
        var editEl = document.getElementById('sa-edit');
        var missing = [];
        if (!pagerEl) missing.push('sa-staff-pager');
        if (!applyBtn) missing.push('sa-apply-filter');
        if (!resetBtn) missing.push('sa-reset-filter');
        if (!filterNameEl) missing.push('sa-filter-name');
        if (!tbodyEl) missing.push('sa-staff-tbody');
        if (!cancelBtn) missing.push('sa-cancel-btn');
        if (!updateBtn) missing.push('sa-update-btn');
        if (!editEl) missing.push('sa-edit');
        if (missing.length) {
            throw new Error('Missing page element(s): ' + missing.join(', '));
        }

        pagerEl.addEventListener('click', function (e) {
            var btn = e.target.closest('.sa-pager-btn');
            if (btn && !btn.disabled) {
                var p = parseInt(btn.getAttribute('data-page'), 10);
                if (!isNaN(p) && p >= 1) loadStaff(p);
            }
        });

        applyBtn.addEventListener('click', function () {
            nameFilter = filterNameEl.value.trim();
            loadStaff(1);
        });
        resetBtn.addEventListener('click', function () {
            nameFilter = '';
            filterNameEl.value = '';
            loadStaff(1);
        });
        filterNameEl.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') applyBtn.click();
        });

        function resetForm() {
            selectedStaffId = null;
            editEl.style.display = 'none';
        }

        tbodyEl.addEventListener('click', function (e) {
            var btn = e.target.closest('.sa-edit-btn');
            if (!btn) return;
            selectedStaffId = btn.getAttribute('data-id');
            document.getElementById('sa-info-name').textContent = btn.getAttribute('data-name');
            document.getElementById('sa-info-dept').textContent = btn.getAttribute('data-dept');
            document.getElementById('sa-aap-toggle').checked = btn.getAttribute('data-aap') === '1';
            document.getElementById('sa-alert').style.display = 'none';
            editEl.style.display = 'block';
            editEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        });

        cancelBtn.addEventListener('click', resetForm);

        updateBtn.addEventListener('click', function () {
            if (!selectedStaffId) return;
            var btn = this;
            btn.disabled = true; btn.textContent = 'Saving...';

            var body = new URLSearchParams();
            body.set('action', 'update_aap_superadmin');
            body.set('staff_id', selectedStaffId);
            body.set('aap', document.getElementById('sa-aap-toggle').checked ? '1' : '0');

            fetch('', { method: 'POST', body: body }).then(function (r) { return r.json(); }).then(function (res) {
                var alertEl = document.getElementById('sa-alert');
                alertEl.style.display = 'block';
                alertEl.className = 'alpro-alert ' + (res.success ? 'alpro-success' : 'alpro-danger');
                alertEl.textContent = res.message || (res.success ? 'Updated.' : 'Update failed.');
                if (res.success) loadStaff(currentPage);
            }).catch(function () {
                var alertEl = document.getElementById('sa-alert');
                alertEl.style.display = 'block';
                alertEl.className = 'alpro-alert alpro-danger';
                alertEl.textContent = 'Request failed. Please try again.';
            }).finally(function () {
                btn.disabled = false; btn.textContent = 'Update';
            });
        });

        loadStaff(1);
    } catch (err) {
        showFatalError(err);
    }
})();
