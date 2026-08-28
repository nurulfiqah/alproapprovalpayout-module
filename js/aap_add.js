document.addEventListener('DOMContentLoaded', function () {
    var sel = document.getElementById('case_type_id');
    var info = document.getElementById('ct_info');
    var valType = document.getElementById('value_type');
    var deptSelect = document.getElementById('department_id_filter');

    // Customer / Membership ID typeahead - searches the shared `customer`
    // table (mamabe uses the same table for its own name/IC search) and
    // fills the field with the matched membership id (c_id) on selection.
    (function () {
        var input = document.getElementById('customer-lookup-input');
        var results = document.getElementById('customer-lookup-results');
        if (!input || !results) return;

        var debounceTimer = null;

        function hideResults() {
            results.style.display = 'none';
            results.innerHTML = '';
        }

        function search(q) {
            fetch('aap_search_customer.php?q=' + encodeURIComponent(q))
                .then(function (r) { return r.json(); })
                .then(function (rows) { renderResults(rows); })
                .catch(function () { hideResults(); });
        }

        function renderResults(rows) {
            if (!rows || !rows.length) { hideResults(); return; }
            results.innerHTML = '';
            rows.forEach(function (row) {
                var li = document.createElement('li');
                li.style.cursor = 'pointer';
                li.style.display = 'block';
                var line = (row.ic || '—') + ': ' + row.customer_name + ', ID: ' + (row.c_id || '—');
                li.innerHTML = escapeHtml(line);
                li.addEventListener('click', function () {
                    input.value = line;
                    hideResults();
                });
                results.appendChild(li);
            });
            results.style.display = 'block';
        }

        function escapeHtml(s) {
            var d = document.createElement('div');
            d.textContent = s == null ? '' : s;
            return d.innerHTML;
        }

        input.addEventListener('input', function () {
            var q = input.value.trim();
            clearTimeout(debounceTimer);
            if (q.length < 2) { hideResults(); return; }
            debounceTimer = setTimeout(function () { search(q); }, 200);
        });

        document.addEventListener('click', function (e) {
            if (e.target !== input && !results.contains(e.target)) hideResults();
        });
    })();

    function applyDepartmentFilter() {
        var did = deptSelect.value;
        Array.prototype.forEach.call(sel.options, function (opt) {
            if (!opt.value) return; // keep the placeholder option always visible
            opt.hidden = !(!did || opt.dataset.departmentId === did);
        });
        var currentOpt = sel.options[sel.selectedIndex];
        if (currentOpt && currentOpt.value && did && currentOpt.dataset.departmentId !== did) {
            sel.value = '';
            refresh();
        }
    }

    function refresh() {
        var opt = sel.options[sel.selectedIndex];
        if (!opt || !opt.value) { info.style.display = 'none'; return; }

        var lines = [];
        if (opt.dataset.desc) lines.push(opt.dataset.desc);
        if (opt.dataset.physical === '1') lines.push('<strong>Physical return confirmation required</strong> before this case can reach the Approval Gate.');

        var poolName = (opt.dataset.approverMode === 'cs_tier') ? 'Customer Support or Operations' : 'Operations';

        if (opt.dataset.approverMode === 'bu_signoff') {
            lines.push('<strong>Approved by the owning Business Unit</strong>, not ' + poolName + '.');
        } else {
            lines.push('Approved by ' + poolName + ' staff with a personal RM ceiling covering this case\'s value.');
        }
        if (opt.dataset.turnaround) lines.push('Target turnaround: up to ' + opt.dataset.turnaround + ' working day(s).');
        if (opt.dataset.systems) lines.push('Systems: ' + opt.dataset.systems);

        info.innerHTML = lines.join('<br>');
        info.style.display = 'block';
    }

    sel.addEventListener('change', refresh);
    deptSelect.addEventListener('change', applyDepartmentFilter);
    applyDepartmentFilter();
    refresh();

    // Live preview of chosen evidence files before submit, each clickable
    // (blob URL) with its own remove button - same behaviour as aap_update.php's
    // "Add More Evidence" preview. pendingFiles accumulates across multiple
    // "Choose files" picks since a native file input replaces its selection
    // each time; the input's FileList is rebuilt from it via DataTransfer.
    var evidenceFileInput = document.getElementById('evidence-file-input');
    var evidenceFilePreview = document.getElementById('evidence-file-preview');
    var pendingFiles = [];

    function syncFileInput() {
        var dt = new DataTransfer();
        pendingFiles.forEach(function (f) { dt.items.add(f); });
        evidenceFileInput.files = dt.files;
    }

    function renderFilePreview() {
        evidenceFilePreview.innerHTML = '';
        pendingFiles.forEach(function (file, index) {
            var li = document.createElement('li');

            var nameLink = document.createElement('a');
            nameLink.href = URL.createObjectURL(file);
            nameLink.target = '_blank';
            nameLink.rel = 'noopener';
            nameLink.innerHTML = '<i class="bi bi-file-earmark-arrow-up"></i> ';
            nameLink.appendChild(document.createTextNode(file.name));
            li.appendChild(nameLink);

            var removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.title = 'Remove from upload';
            removeBtn.style.cssText = 'background:none; border:none; color:#dc3545; cursor:pointer; padding:0; font-size:13px;';
            removeBtn.innerHTML = '<i class="bi bi-trash"></i>';
            removeBtn.addEventListener('click', function () {
                pendingFiles.splice(index, 1);
                syncFileInput();
                renderFilePreview();
            });
            li.appendChild(removeBtn);

            evidenceFilePreview.appendChild(li);
        });
    }

    evidenceFileInput.addEventListener('change', function () {
        Array.prototype.forEach.call(evidenceFileInput.files, function (f) {
            pendingFiles.push(f);
        });
        syncFileInput();
        renderFilePreview();
    });
});
