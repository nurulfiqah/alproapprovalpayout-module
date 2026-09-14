document.addEventListener('DOMContentLoaded', function () {
    var sel = document.getElementById('case_type_id');
    var info = document.getElementById('ct_info');
    var valType = document.getElementById('value_type');
    var deptSelect = document.getElementById('department_id_filter');

    // Customer search typeahead - attached directly to BOTH Customer Name
    // and Membership ID, so searching by name/IC/membership id (against the
    // shared `customer` table - mamabe uses the same table for its own
    // name/IC search) works no matter which of the two fields you start
    // typing in. Picking a result fills both fields, then locks them
    // (readonly) so a verified match can't be silently hand-edited into
    // something that no longer matches any real customer - the Refresh
    // button on the Case Details card header clears every field in this
    // section (Customer Name/Membership ID included, unlocking them too),
    // without touching anything else on the form.
    function escapeHtml(s) {
        var d = document.createElement('div');
        d.textContent = s == null ? '' : s;
        return d.innerHTML;
    }

    // Shared by Membership ID, Transaction No, and Bank Account Number -
    // all three only ever hold digits, so anything else is stripped as it's
    // typed (covers manual typing; a picked search result's c_id is already
    // numeric).
    function digitsOnlyFilter(el) {
        if (!el) return;
        el.addEventListener('input', function () {
            var digitsOnly = el.value.replace(/\D/g, '');
            if (digitsOnly !== el.value) el.value = digitsOnly;
        });
    }
    digitsOnlyFilter(document.getElementById('bank-account-number-input'));

    (function () {
        var nameInput = document.getElementById('customer-name-input');
        var membershipInput = document.getElementById('customer-membership-input');
        var refreshBtn = document.getElementById('case-details-refresh-btn');
        var transactionInput = document.getElementById('case-details-transaction-ref');
        var calculatedValueInput = document.getElementById('case-details-calculated-value');
        var recommendedOutcomeInput = document.getElementById('case-details-recommended-outcome');
        if (!nameInput || !membershipInput) return;

        function lockCustomerFields() {
            nameInput.readOnly = true;
            membershipInput.readOnly = true;
        }

        function unlockCustomerFields() {
            nameInput.readOnly = false;
            membershipInput.readOnly = false;
        }

        if (refreshBtn) {
            refreshBtn.addEventListener('click', function () {
                nameInput.value = '';
                membershipInput.value = '';
                unlockCustomerFields();
                if (transactionInput) transactionInput.value = '';
                if (calculatedValueInput) calculatedValueInput.value = '';
                if (valType) valType.value = 'cash';
                if (recommendedOutcomeInput) recommendedOutcomeInput.value = '';
                nameInput.focus();
            });
        }

        digitsOnlyFilter(membershipInput);
        digitsOnlyFilter(transactionInput);

        function initCustomerLookup(input, results) {
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
                    // Preventing the mousedown's default action stops the
                    // browser from blurring the input the instant the mouse
                    // goes down on a non-focusable element like <li>.
                    li.addEventListener('mousedown', function (e) { e.preventDefault(); });
                    li.addEventListener('click', function () {
                        nameInput.value = row.customer_name || '';
                        membershipInput.value = row.c_id || '';
                        hideResults();
                        lockCustomerFields();
                    });
                    results.appendChild(li);
                });
                results.style.display = 'block';
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
        }

        initCustomerLookup(nameInput, document.getElementById('customer-name-results'));
        initCustomerLookup(membershipInput, document.getElementById('customer-membership-results'));
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
        if (opt.dataset.physical === '1') lines.push('<strong>Verification required</strong> before this case can reach the approval gate.');
        lines.push('Approved by the staff assigned to this Case Type\'s approval gate.');

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
    // Each entry is { file, url } - the blob URL is created exactly once per
    // file (not on every re-render) and explicitly revoked when the file is
    // removed or the page unloads, so picking/removing files repeatedly
    // doesn't leak blob memory and eventually freeze the tab.
    var pendingFiles = [];

    function syncFileInput() {
        var dt = new DataTransfer();
        pendingFiles.forEach(function (p) { dt.items.add(p.file); });
        evidenceFileInput.files = dt.files;
    }

    function renderFilePreview() {
        evidenceFilePreview.innerHTML = '';
        pendingFiles.forEach(function (entry, index) {
            var li = document.createElement('li');

            var nameLink = document.createElement('a');
            nameLink.href = entry.url;
            nameLink.target = '_blank';
            nameLink.rel = 'noopener';
            nameLink.innerHTML = '<i class="bi bi-file-earmark-arrow-up"></i> ';
            nameLink.appendChild(document.createTextNode(entry.file.name));
            li.appendChild(nameLink);

            var removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.title = 'Remove from upload';
            removeBtn.style.cssText = 'background:none; border:none; color:#dc3545; cursor:pointer; padding:0; font-size:13px;';
            removeBtn.innerHTML = '<i class="bi bi-trash"></i>';
            removeBtn.addEventListener('click', function () {
                URL.revokeObjectURL(entry.url);
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
            pendingFiles.push({ file: f, url: URL.createObjectURL(f) });
        });
        syncFileInput();
        renderFilePreview();
    });

    // Release every still-live blob URL on the way out, in case the form is
    // abandoned (navigated away from) with files still pending.
    window.addEventListener('beforeunload', function () {
        pendingFiles.forEach(function (p) { URL.revokeObjectURL(p.url); });
    });
});