document.addEventListener('DOMContentLoaded', function () {
    var nameInput = document.getElementById('ct_name');
    var codeInput = document.getElementById('ct_code');

    function slugify(name) {
        return name.toUpperCase().replace(/[^A-Z0-9]+/g, '_').replace(/^_+|_+$/g, '');
    }

    // Only auto-generate for a brand new Case Type - editing an existing
    // one keeps its original code so renaming Name never silently changes
    // an already-issued code.
    if (nameInput && codeInput && AAP_ADMIN.isNew) {
        nameInput.addEventListener('input', function () {
            codeInput.value = slugify(nameInput.value);
        });
    }

    var poolStaff = AAP_ADMIN.poolStaff;
    var modeSelect = document.getElementById('ct_approver_mode');
    var opsTierField = document.getElementById('ct_ops_tier_field');
    var opsTierSelect = document.getElementById('ct_ops_tier_required');
    var field = document.getElementById('ct_pool_staff_field');
    var sectionCs = document.getElementById('ct_pool_section_cs');
    var sectionOps = document.getElementById('ct_pool_section_ops');
    var opsLabel = document.getElementById('ct_pool_ops_label');
    var listCs = document.getElementById('ct_pool_list_cs');
    var listOps = document.getElementById('ct_pool_list_ops');
    if (!modeSelect) return;

    function renderOpsTierField() {
        if (!opsTierField) return;
        opsTierField.style.display = (modeSelect.value === 'cs_tier') ? 'block' : 'none';
    }

    function esc(s) {
        var d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML;
    }

    // minGrade: null = no grade filter applies to this list (Operations Tier
    // Case Types, or the Customer Support half of a CS Tier Case Type).
    // Otherwise a staff member below it is still editable (their ceiling can
    // still be set ahead of time) but flagged as not currently eligible.
    function row(st, minGrade) {
        var ineligible = minGrade !== null && st.grade < minGrade;
        return '<div class="ct-pool-row" data-id="' + st.id + '" style="display:flex; flex-wrap:wrap; align-items:center; gap:8px; padding:4px 0; border-bottom:1px solid #f1f3f5;' + (ineligible ? ' opacity:.55;' : '') + '">'
            + '<span style="min-width:0;">' + esc(st.name) + (ineligible ? ' <span style="color:#dc3545; font-size:11px;">(grade ' + st.grade + ', below required tier)</span>' : '') + '</span>'
            + '<label style="display:flex; align-items:center; gap:4px; font-weight:normal; white-space:nowrap;"><input type="checkbox" class="ct-pool-unlimited" ' + (st.has_ceiling && st.threshold_amount === null ? 'checked' : '') + '> Unlimited</label>'
            + '<input type="number" class="alpro-input ct-pool-amount" step="0.01" min="0" placeholder="RM value" style="width:110px; padding:3px 6px !important; min-height:0 !important; height:auto !important; font-size:12px;" value="' + (st.threshold_amount !== null ? st.threshold_amount : '') + '" ' + (st.has_ceiling && st.threshold_amount === null ? 'disabled' : '') + '>'
            + '<button type="button" class="alpro-btn alpro-btn-blue ct-pool-save" style="padding:3px 10px !important; font-size:11px !important;">Save</button>'
            + '<span class="ct-pool-status" style="font-size:11px; color:#6c757d; min-width:60px;">' + (st.has_ceiling ? '' : 'No rights') + '</span>'
            + '</div>';
    }

    function renderList(el, staff, minGrade) {
        el.innerHTML = staff.length
            ? staff.map(function (st) { return row(st, minGrade); }).join('')
            : '<span class="alpro-muted">No active staff in this department.</span>';
    }

    function render() {
        var mode = modeSelect.value;
        if (mode === 'operations_tier') {
            field.style.display = 'flex';
            sectionCs.style.display = 'none';
            sectionOps.style.display = 'block';
            opsLabel.textContent = 'Staff in Operations';
            renderList(listOps, poolStaff.operations, null);
        } else if (mode === 'cs_tier') {
            field.style.display = 'flex';
            sectionCs.style.display = 'block';
            sectionOps.style.display = 'block';
            var opsTier = opsTierSelect ? opsTierSelect.value : '';
            var minGrade = opsTier === 'manager' ? 3 : (opsTier === 'executive' ? 1 : 0);
            opsLabel.textContent = 'Staff in Operations' + (opsTier ? ' (also acting on this Case Type, subject to Operations Tier Required)' : ' (also acting on this Case Type)');
            renderList(listCs, poolStaff.customer_support, null);
            renderList(listOps, poolStaff.operations, minGrade);
        } else {
            field.style.display = 'none';
        }
    }

    field.addEventListener('change', function (e) {
        if (!e.target.classList.contains('ct-pool-unlimited')) return;
        var rowEl = e.target.closest('.ct-pool-row');
        var amountInput = rowEl.querySelector('.ct-pool-amount');
        amountInput.disabled = e.target.checked;
        if (e.target.checked) amountInput.value = '';
    });

    field.addEventListener('click', function (e) {
        var btn = e.target.closest('.ct-pool-save');
        if (!btn) return;
        var rowEl = btn.closest('.ct-pool-row');
        var staffId = rowEl.getAttribute('data-id');
        var unlimited = rowEl.querySelector('.ct-pool-unlimited').checked;
        var amount = rowEl.querySelector('.ct-pool-amount').value;
        var statusEl = rowEl.querySelector('.ct-pool-status');
        var mode = unlimited ? 'unlimited' : (amount === '' ? 'remove' : 'amount');

        btn.disabled = true; btn.textContent = 'Saving...';
        var body = new URLSearchParams();
        body.set('action', 'update_aap_ceiling');
        body.set('staff_id', staffId);
        body.set('mode', mode);
        if (mode === 'amount') body.set('threshold_amount', amount);

        fetch('', { method: 'POST', body: body }).then(function (r) { return r.json(); }).then(function (res) {
            statusEl.textContent = res.success ? 'Saved' : (res.message || 'Failed');
            statusEl.style.color = res.success ? '#198754' : '#dc3545';
        }).catch(function () {
            statusEl.textContent = 'Failed';
            statusEl.style.color = '#dc3545';
        }).finally(function () {
            btn.disabled = false; btn.textContent = 'Save';
        });
    });

    modeSelect.addEventListener('change', function () {
        render();
        renderOpsTierField();
    });
    if (opsTierSelect) opsTierSelect.addEventListener('change', render);
    render();
    renderOpsTierField();
});
