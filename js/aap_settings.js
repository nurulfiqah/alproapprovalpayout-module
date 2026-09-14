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

    // staff.aap is just a flag now (0/1) - see aapFetchAapLevel()/
    // aapFetchIsSuperAdmin() in aap_lib.php.
    function levelLabel(level) {
        return level === 1 ? 'Admin' : 'No Access';
    }
    function badge(level) {
        var cls = level === 1 ? 'approved' : 'voided';
        return '<span class="alpro-badge alpro-badge-' + cls + '">' + levelLabel(level) + '</span>';
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
                + '<td>' + badge(s.aap) + '</td>'
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
            document.getElementById('sa-aap-level').value = btn.getAttribute('data-aap') || '0';
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
            body.set('aap', document.getElementById('sa-aap-level').value);

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

    // ---- Bank Master panel ----
    (function () {
        var tbody = document.getElementById('bank-tbody');
        var addBtn = document.getElementById('bank-add-btn');
        var newNameEl = document.getElementById('bank-new-name');
        var alertEl = document.getElementById('bank-alert');
        if (!tbody || !addBtn || !newNameEl || !alertEl) return;

        function showAlert(success, message) {
            alertEl.style.display = 'block';
            alertEl.className = 'alpro-alert ' + (success ? 'alpro-success' : 'alpro-danger');
            alertEl.textContent = message;
        }

        function loadBanks() {
            tbody.innerHTML = '<tr><td colspan="3" align="center" style="padding:15px;">Loading...</td></tr>';
            fetch('?action=list_banks').then(function (r) { return r.json(); }).then(function (res) {
                if (!res.success) { tbody.innerHTML = '<tr><td colspan="3" align="center" style="padding:15px;">Failed to load.</td></tr>'; return; }
                renderBanks(res.data);
            }).catch(function () {
                tbody.innerHTML = '<tr><td colspan="3" align="center" style="padding:15px;">Error loading data.</td></tr>';
            });
        }

        function renderBanks(data) {
            if (!data || !data.length) {
                tbody.innerHTML = '<tr><td colspan="3" align="center" style="padding:15px;">No banks yet.</td></tr>';
                return;
            }
            var html = '';
            data.forEach(function (b) {
                var retired = b.recycle === 1;
                html += '<tr data-id="' + b.id + '">'
                    + '<td><span class="bank-name-text">' + esc(b.bank_name) + '</span>'
                    + '<input class="alpro-input bank-name-input" type="text" value="' + esc(b.bank_name) + '" style="display:none; max-width:280px;"></td>'
                    + '<td>' + (retired ? '<span class="alpro-badge alpro-badge-voided">Retired</span>' : '<span class="alpro-badge alpro-badge-approved">Active</span>') + '</td>'
                    + '<td>'
                    + '<button type="button" class="alpro-btn alpro-btn-grey bank-rename-btn" style="padding:4px 10px; font-size:12px;">Rename</button> '
                    + '<button type="button" class="alpro-btn ' + (retired ? 'alpro-btn-blue bank-restore-btn' : 'alpro-btn-grey bank-retire-btn') + '" style="padding:4px 10px; font-size:12px;">' + (retired ? 'Restore' : 'Retire') + '</button>'
                    + '</td></tr>';
            });
            tbody.innerHTML = html;
        }

        addBtn.addEventListener('click', function () {
            var name = newNameEl.value.trim();
            if (name === '') { showAlert(false, 'Bank name is required.'); return; }
            addBtn.disabled = true;
            var body = new URLSearchParams();
            body.set('action', 'add_bank');
            body.set('bank_name', name);
            fetch('', { method: 'POST', body: body }).then(function (r) { return r.json(); }).then(function (res) {
                showAlert(res.success, res.message || (res.success ? 'Added.' : 'Failed.'));
                if (res.success) { newNameEl.value = ''; loadBanks(); }
            }).catch(function () {
                showAlert(false, 'Request failed. Please try again.');
            }).finally(function () {
                addBtn.disabled = false;
            });
        });
        newNameEl.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') addBtn.click();
        });

        tbody.addEventListener('click', function (e) {
            var row = e.target.closest('tr[data-id]');
            if (!row) return;
            var bankId = row.getAttribute('data-id');

            var renameBtn = e.target.closest('.bank-rename-btn');
            if (renameBtn) {
                var nameSpan = row.querySelector('.bank-name-text');
                var nameInput = row.querySelector('.bank-name-input');
                if (renameBtn.textContent === 'Rename') {
                    nameSpan.style.display = 'none';
                    nameInput.style.display = 'inline-block';
                    nameInput.focus();
                    renameBtn.textContent = 'Save';
                } else {
                    var newName = nameInput.value.trim();
                    if (newName === '') { showAlert(false, 'Bank name is required.'); return; }
                    var body = new URLSearchParams();
                    body.set('action', 'rename_bank');
                    body.set('bank_id', bankId);
                    body.set('bank_name', newName);
                    fetch('', { method: 'POST', body: body }).then(function (r) { return r.json(); }).then(function (res) {
                        showAlert(res.success, res.message || (res.success ? 'Updated.' : 'Failed.'));
                        if (res.success) loadBanks();
                    }).catch(function () {
                        showAlert(false, 'Request failed. Please try again.');
                    });
                }
                return;
            }

            var retireBtn = e.target.closest('.bank-retire-btn');
            var restoreBtn = e.target.closest('.bank-restore-btn');
            if (retireBtn || restoreBtn) {
                var recycle = retireBtn ? 1 : 0;
                var body2 = new URLSearchParams();
                body2.set('action', 'toggle_bank_recycle');
                body2.set('bank_id', bankId);
                body2.set('recycle', recycle);
                fetch('', { method: 'POST', body: body2 }).then(function (r) { return r.json(); }).then(function (res) {
                    showAlert(res.success, res.message || (res.success ? 'Updated.' : 'Failed.'));
                    if (res.success) loadBanks();
                }).catch(function () {
                    showAlert(false, 'Request failed. Please try again.');
                });
            }
        });

        loadBanks();
    })();
})();
