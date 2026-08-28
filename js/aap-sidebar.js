(function () {
    'use strict';
    var BASE = (typeof AAP_BASE !== 'undefined') ? AAP_BASE : '';
    var API_URL = BASE + 'aap_notifications.php';
    var VIEW_URL = BASE + 'aap_update.php';
    var POLL_MS = 8000;

    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function formatDateTime(v) {
        if (!v) { return ''; }
        var d = new Date(String(v).replace(' ', 'T'));
        if (isNaN(d.getTime())) { return v; }
        var months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        var hh = String(d.getHours()).padStart(2, '0');
        var mm = String(d.getMinutes()).padStart(2, '0');
        return d.getDate() + ' ' + months[d.getMonth()] + ' ' + d.getFullYear() + ', ' + hh + ':' + mm;
    }

    function setBadge(count) {
        var badge = document.getElementById('aap-notif-badge');
        if (!badge) { return; }
        if (count > 0) {
            badge.textContent = count > 99 ? '99+' : String(count);
            badge.style.display = 'inline-block';
        } else {
            badge.style.display = 'none';
        }
    }

    function renderList(items) {
        var list = document.getElementById('aap-notif-list');
        if (!list) { return; }
        if (!items.length) {
            list.innerHTML = '<div style="padding:14px 12px; color:#888; font-size:12px;">No notifications.</div>';
            return;
        }
        var html = '';
        for (var i = 0; i < items.length; i++) {
            var n = items[i];
            var ref = escapeHtml(n.case_ref);
            var snippets = {
                case_approved: 'Case ' + ref + ' has been approved',
                case_rejected: 'Case ' + ref + ' has been rejected',
                case_closed: 'Case ' + ref + ' has been closed',
                pending_approval: 'Case ' + ref + ' is awaiting your approval'
            };
            var snippet = snippets[n.type] || ('Case ' + ref + ' has an update');
            html += '<div class="aap-notif-item" data-id="' + n.id + '" data-case-id="' + n.case_id + '" '
                + 'style="padding:10px 12px; border-bottom:1px solid #f2f2f2; cursor:pointer; font-size:12px;' + (!n.read_at ? ' background:#f0f7ff;' : '') + '">'
                + '<div>' + snippet + '</div>'
                + '<div style="color:#999; font-size:11px; margin-top:2px;">' + escapeHtml(formatDateTime(n.created_at)) + '</div></div>';
        }
        list.innerHTML = html;
    }

    function refresh() {
        fetch(API_URL + '?action=listNotifications')
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res && res.success) {
                    setBadge(res.unread_count || 0);
                    renderList(res.data || []);
                }
            })
            .catch(function () {});
    }

    document.addEventListener('click', function (e) {
        var bell = e.target.closest('#aap-notif-bell');
        if (bell) {
            e.preventDefault();
            var menu = document.getElementById('aap-notif-menu');
            menu.style.display = (menu.style.display === 'none') ? 'block' : 'none';
            return;
        }

        var item = e.target.closest('.aap-notif-item');
        if (item) {
            var id = item.getAttribute('data-id');
            var caseId = item.getAttribute('data-case-id');
            var body = new URLSearchParams();
            body.set('action', 'markNotificationRead');
            body.set('id', id);
            fetch(API_URL, { method: 'POST', body: body }).then(function () {
                window.location.href = VIEW_URL + '?id=' + caseId;
            });
            return;
        }

        if (e.target.closest('#aap-notif-markall')) {
            e.preventDefault();
            var body2 = new URLSearchParams();
            body2.set('action', 'markAllNotificationsRead');
            fetch(API_URL, { method: 'POST', body: body2 }).then(refresh);
            return;
        }

        if (!e.target.closest('#aap-notif-menu')) {
            var menu2 = document.getElementById('aap-notif-menu');
            if (menu2) menu2.style.display = 'none';
        }
    });

    refresh();
    setInterval(refresh, POLL_MS);
})();
