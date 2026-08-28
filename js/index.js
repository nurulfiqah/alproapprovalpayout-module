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
});
