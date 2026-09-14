document.addEventListener('DOMContentLoaded', function () {
    var tabBtns = document.querySelectorAll('.aap-tab-btn');
    tabBtns.forEach(function (btn) {
        btn.addEventListener('click', function () {
            tabBtns.forEach(function (b) { b.classList.remove('active'); });
            document.querySelectorAll('.aap-tab-panel').forEach(function (p) { p.classList.remove('active'); });
            btn.classList.add('active');
            var panel = document.getElementById('tab-' + btn.dataset.tab);
            if (panel) panel.classList.add('active');
        });
    });

    // ---- Case Queue export selection ----
    var selectAll = document.getElementById('export-select-all');
    var exportBtn = document.getElementById('export-selected-btn');
    var exportCloseBtn = document.getElementById('export-close-btn');
    var rowCheckboxes = document.querySelectorAll('.export-row-checkbox');
    if (selectAll && exportBtn && rowCheckboxes.length) {
        function refreshExportBtn() {
            var anyChecked = Array.prototype.some.call(rowCheckboxes, function (cb) { return cb.checked; });
            exportBtn.disabled = !anyChecked;
            if (exportCloseBtn) exportCloseBtn.disabled = !anyChecked;
            selectAll.checked = anyChecked && Array.prototype.every.call(rowCheckboxes, function (cb) { return cb.checked; });
        }
        selectAll.addEventListener('change', function () {
            rowCheckboxes.forEach(function (cb) { cb.checked = selectAll.checked; });
            refreshExportBtn();
        });
        rowCheckboxes.forEach(function (cb) {
            cb.addEventListener('change', refreshExportBtn);
        });
    }
});
