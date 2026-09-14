<?php
require_once('../lock_adv.php');
$connect = 1;
date_default_timezone_set('Asia/Kuala_Lumpur');
include('../common/index_adv.php');
if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection not available.");
}
require_once('aap_lib.php');

// Resolves the SuperAdmin/admin-level union used across every AAP page.
$aap_identity = aapResolveIdentity($conn, $id_user, $grade, $department);
$grade = $aap_identity['grade'];
$department = $aap_identity['department'];
$aap_dept_ids = $aap_identity['dept_ids'];
$aap_is_admin = $aap_identity['is_admin'];
$aap_is_superadmin = $aap_identity['is_superadmin'];
$aap_is_operations = aapIsOperations($aap_dept_ids);
// Export & Close is the same authority as the single-case Execute/Close
// flow (aap_update.php) - Level 3/execution only, not every admin/creator.
$aap_can_execute = aapCanExecute($grade, $aap_dept_ids, $aap_is_admin);
if ((int)$grade < 1 && !$aap_is_admin) {
    die("You do not have access to this module.");
}

// ---- Filters ----
$f_status      = isset($_GET['case_status']) ? trim($_GET['case_status']) : '';
$f_case_type   = isset($_GET['case_type_id']) && $_GET['case_type_id'] !== '' ? (int)$_GET['case_type_id'] : null;
$f_outlet      = isset($_GET['outlet_id']) && $_GET['outlet_id'] !== '' ? (int)$_GET['outlet_id'] : null;
$f_physical    = isset($_GET['physical_confirm_status']) ? trim($_GET['physical_confirm_status']) : '';
$f_approval    = isset($_GET['approval_status']) ? trim($_GET['approval_status']) : '';
$f_date        = isset($_GET['raised_date']) ? trim($_GET['raised_date']) : '';
$f_closed_date = isset($_GET['closed_date']) ? trim($_GET['closed_date']) : '';
$f_search      = isset($_GET['q']) ? trim($_GET['q']) : '';

// Drafts now live in the main queue (Status column shows them as
// "Investigation" via aapCaseDisplayStatus) instead of a
// separate tab - the Draft stat card above still gives a quick count.
//
// Split into a status-only clause and a "every other filter" clause so the
// stat strip below can share the latter (Case Type/Outlet/Verification
// Required/Approval/dates/search all narrow every tile) while deliberately
// leaving the former out (each tile already IS one specific status - if the
// Case Status filter also applied here, picking one status would zero out
// every other tile instead of showing where the filtered cases currently
// sit).
$status_where = '';
if ($f_status === 'rejected') {
    // Voided and Rejected are shown as one "Rejected" status (see
    // aapCaseDisplayStatus in aap_lib.php) - filtering by it must catch both.
    $status_where = " AND c.case_status IN ('rejected', 'voided')";
} elseif ($f_status === 'closed') {
    $status_where = " AND c.case_status = 'closed'";
} elseif ($f_status === 'closing') {
    // Matches aapCaseDisplayStatus's "Closing" branch - executed
    // but not yet notified/closed via the separate Close action.
    $status_where = " AND c.case_status = 'executed'";
} elseif ($f_status === 'confirming') {
    // Matches aapCaseDisplayStatus's "Verification" branch - one
    // of the three sub-statuses 'open' resolves into.
    $status_where = " AND c.case_status = 'open' AND c.physical_confirm_required = 1 AND c.physical_confirm_status IN ('pending', 'tagged')";
} elseif ($f_status === 'executing') {
    // Matches aapCaseDisplayStatus's "Execute" branch.
    $status_where = " AND c.case_status = 'open' AND c.approval_status IN ('approved', 'corrected') AND c.execution_status = 'pending'";
} elseif ($f_status === 'pending_approval') {
    // Matches aapCaseDisplayStatus's "Approval" branch - whatever
    // 'open' case is left once confirming/executing are excluded.
    $status_where = " AND c.case_status = 'open'
                AND NOT (c.physical_confirm_required = 1 AND c.physical_confirm_status IN ('pending', 'tagged'))
                AND NOT (c.approval_status IN ('approved', 'corrected') AND c.execution_status = 'pending')";
} elseif ($f_status !== '' && in_array($f_status, ['open', 'draft'], true)) {
    $status_where = " AND c.case_status = '" . $conn->real_escape_string($f_status) . "'";
}

$common_filters = '';
if ($f_case_type !== null) {
    $common_filters .= " AND c.case_type_id = " . $f_case_type;
}
if ($f_outlet !== null) {
    // No join in this $where's own query (the COUNT below runs against
    // aap_cases alone) - a subquery on the linked Fixit ticket works in
    // both places instead of relying on aapCaseSelectSql's joins.
    $common_filters .= " AND c.fixit_record_id IN (SELECT id FROM fixit_record WHERE outlet = " . $f_outlet . ")";
}
if ($f_physical === 'required') {
    $common_filters .= " AND c.physical_confirm_required = 1";
} elseif ($f_physical === 'not_required') {
    $common_filters .= " AND c.physical_confirm_required = 0";
}
if ($f_approval === 'approved') {
    // Corrected is shown as one "Approved" status (see aapApprovalStatusDisplay
    // in aap_lib.php) - filtering by it must catch both.
    $common_filters .= " AND c.approval_status IN ('approved', 'corrected')";
} elseif ($f_approval !== '' && in_array($f_approval, ['pending','rejected'], true)) {
    $common_filters .= " AND c.approval_status = '" . $conn->real_escape_string($f_approval) . "'";
}
if ($f_date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_date)) {
    $common_filters .= " AND DATE(c.timestamp) = '" . $conn->real_escape_string($f_date) . "'";
}
if ($f_closed_date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_closed_date)) {
    $common_filters .= " AND DATE(c.closed_at) = '" . $conn->real_escape_string($f_closed_date) . "'";
}
if ($f_search !== '') {
    $s = $conn->real_escape_string($f_search);
    $common_filters .= " AND (c.case_ref LIKE '%$s%' OR c.transaction_ref LIKE '%$s%' OR c.customer_membership_id LIKE '%$s%' OR c.customer_name LIKE '%$s%')";
}

$where = aapScopeWhere($id_user, $aap_dept_ids, $aap_is_admin) . $status_where . $common_filters;

$filter_case_types = aapFetchCaseTypes($conn, true);
$filter_outlets = aapFetchCaseOutletOptions($conn, aapScopeWhere($id_user, $aap_dept_ids, $aap_is_admin));

// ---- Queue-position stat strip - reflects every filter except Case Status
// itself (see comment above $status_where). ----
$stat_where = aapScopeWhere($id_user, $aap_dept_ids, $aap_is_admin) . $common_filters;
$stats = ['draft' => 0, 'open' => 0, 'pending_physical' => 0, 'pending_approval' => 0, 'pending_execution' => 0, 'pending_close' => 0, 'closed_today' => 0];
$res = $conn->query("SELECT case_status, COUNT(*) c FROM aap_cases c WHERE $stat_where GROUP BY case_status");
while ($res && $row = $res->fetch_assoc()) {
    if ($row['case_status'] === 'open') $stats['open'] = (int)$row['c'];
    if ($row['case_status'] === 'draft') $stats['draft'] = (int)$row['c'];
}
$res = $conn->query("SELECT COUNT(*) c FROM aap_cases c WHERE $stat_where AND physical_confirm_status IN ('pending','tagged')");
$stats['pending_physical'] = $res ? (int)$res->fetch_assoc()['c'] : 0;
$res = $conn->query("SELECT COUNT(*) c FROM aap_cases c WHERE $stat_where AND case_status = 'open' AND physical_confirm_status IN ('not_required','confirmed') AND approval_status = 'pending'");
$stats['pending_approval'] = $res ? (int)$res->fetch_assoc()['c'] : 0;
$res = $conn->query("SELECT COUNT(*) c FROM aap_cases c WHERE $stat_where AND approval_status IN ('approved','corrected') AND execution_status = 'pending'");
$stats['pending_execution'] = $res ? (int)$res->fetch_assoc()['c'] : 0;
$res = $conn->query("SELECT COUNT(*) c FROM aap_cases c WHERE $stat_where AND case_status = 'executed'");
$stats['pending_close'] = $res ? (int)$res->fetch_assoc()['c'] : 0;
$res = $conn->query("SELECT COUNT(*) c FROM aap_cases c WHERE $stat_where AND case_status = 'closed'");
$stats['closed_today'] = $res ? (int)$res->fetch_assoc()['c'] : 0;

// ---- List (paginated) ----
$limit = 20;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;

$count_res = $conn->query("SELECT COUNT(*) c FROM aap_cases c WHERE $where");
$total_rows = $count_res ? (int)$count_res->fetch_assoc()['c'] : 0;
$total_pages = max(1, (int)ceil($total_rows / $limit));
// Clamp to the last valid page - otherwise a stale ?page= from before a
// filter/page-size change (e.g. fewer total pages now) requests an OFFSET
// past the end of the result set and silently returns nothing.
$page = min($page, $total_pages);
$offset = ($page - 1) * $limit;

$f_sort = isset($_GET['sort']) && in_array($_GET['sort'], ['timestamp', 'closed_at'], true) ? $_GET['sort'] : 'timestamp';
$f_dir  = isset($_GET['dir']) && $_GET['dir'] === 'asc' ? 'asc' : 'desc';
$list_sql = aapCaseSelectSql($where, "c.$f_sort $f_dir") . " LIMIT $limit OFFSET $offset";
$list_res = $conn->query($list_sql);

// Sortable "Raised"/"Closed" column headers - clicking toggles asc/desc for
// that column (defaulting to desc first), keeping every other active filter.
function aapSortHeaderLink($column, $label, $f_sort, $f_dir) {
    $qs = $_GET; unset($qs['page']);
    $next_dir = ($f_sort === $column && $f_dir === 'desc') ? 'asc' : 'desc';
    $qs['sort'] = $column;
    $qs['dir'] = $next_dir;
    $arrow = ($f_sort === $column) ? ($f_dir === 'asc' ? ' ▲' : ' ▼') : '';
    return '<a href="?' . htmlspecialchars(http_build_query($qs)) . '" style="color:inherit; text-decoration:none;">' . htmlspecialchars($label) . $arrow . '</a>';
}

// Fixit tickets lodged under an AAP-linked category that haven't been
// converted into an AAP case yet — everyone gets this tab now (any staff
// belonging to a ticket's own department can raise a case for it, see
// aap_add.php's aapDeptInScope() gate), not just CS/Operations/admin -
// otherwise a department had no way to even discover its own incoming
// tickets. Full-list roles still see every department's queue; everyone
// else is scoped to their own department(s) only, via the department_ids
// filter below.
$aap_can_see_incoming = true;
$aap_incoming_full_list = $aap_is_admin || $aap_is_operations || aapIsCustomerSupport($aap_dept_ids);

// ---- Incoming from Fixit filters ----
$if_category = isset($_GET['if_category']) && $_GET['if_category'] !== '' ? (int)$_GET['if_category'] : null;
$if_outlet   = isset($_GET['if_outlet']) && $_GET['if_outlet'] !== '' ? (int)$_GET['if_outlet'] : null;
$if_lodged_by = isset($_GET['if_lodged_by']) ? trim($_GET['if_lodged_by']) : '';
$if_date      = isset($_GET['if_date']) ? trim($_GET['if_date']) : '';
$incoming_filters = [
    'category_id' => $if_category,
    'outlet_id' => $if_outlet,
    'lodged_by' => $if_lodged_by,
    'date' => $if_date,
    'department_ids' => $aap_incoming_full_list ? null : $aap_dept_ids,
];
$filter_incoming_categories = aapFetchIncomingFixitCategoryOptions($conn, $incoming_filters['department_ids']);
$filter_incoming_outlets = aapFetchIncomingFixitOutletOptions($conn, $incoming_filters['department_ids']);

$i_limit = 20;
$i_page = isset($_GET['ipage']) ? max(1, (int)$_GET['ipage']) : 1;
$incoming_total = $aap_can_see_incoming ? aapCountIncomingFixitTickets($conn, $incoming_filters) : 0;
$incoming_total_pages = max(1, (int)ceil($incoming_total / $i_limit));
$i_page = min($i_page, $incoming_total_pages);
$i_offset = ($i_page - 1) * $i_limit;
$incoming_fixit = $aap_can_see_incoming ? aapFetchIncomingFixitTickets($conn, $i_limit, $i_offset, $incoming_filters) : [];

$active_tab = (isset($_GET['tab']) && $_GET['tab'] === 'incoming' && $aap_can_see_incoming) ? 'incoming' : 'queue';
?>

<?php include('aap_modern_head.php'); ?>

<div class="header">
  <b class="rtop"><b class="r1"></b><b class="r2"></b><b class="r3"></b><b class="r4"></b></b>
  <h1 class="headerH1"><img src="img/logo.svg"> Case Queue</h1>
  <b class="rbottom"><b class="r4"></b><b class="r3"></b><b class="r2"></b><b class="r1"></b></b>
</div>

<?php include('aap_sidebar.php'); ?>

<div class="aap-modern">

<p class="aap-module-tag"><i class="bi bi-shield-check"></i> AAP — Alpro Approval Protocol</p>

<div class="aap-stats">
    <?php if ($aap_can_see_incoming): ?>
    <div class="aap-stat red">
        <h3>Incoming from Fixit</h3>
        <div class="value"><?php echo number_format(count($incoming_fixit)); ?></div>
    </div>
    <?php endif; ?>
    <div class="aap-stat" style="border-top-color:#b8860b;">
        <h3>Open Cases</h3>
        <div class="value"><?php echo number_format($stats['draft'] + $stats['open']); ?></div>
    </div>
    <div class="aap-stat" style="border-top-color:#f39c12;">
        <h3>Verification</h3>
        <div class="value"><?php echo number_format($stats['pending_physical']); ?></div>
    </div>
    <div class="aap-stat" style="border-top-color:#fd7e14;">
        <h3>Approval</h3>
        <div class="value"><?php echo number_format($stats['pending_approval']); ?></div>
    </div>
    <div class="aap-stat" style="border-top-color:#0dcaf0;">
        <h3>Execute</h3>
        <div class="value"><?php echo number_format($stats['pending_execution']); ?></div>
    </div>
    <div class="aap-stat" style="border-top-color:#20c997;">
        <h3>Closing</h3>
        <div class="value"><?php echo number_format($stats['pending_close']); ?></div>
    </div>
    <div class="aap-stat" style="border-top-color:#6c757d;">
        <h3>Closed</h3>
        <div class="value"><?php echo number_format($stats['closed_today']); ?></div>
    </div>
</div>

<div class="aap-card" style="padding: 0;">
    <div class="aap-tabs" style="padding: 6px 20px 0; border-bottom-color: #e9ecef;">
        <button type="button" class="aap-tab-btn <?php echo $active_tab === 'queue' ? 'active' : ''; ?>" data-tab="queue">Case Queue <span class="aap-tab-count"><?php echo number_format($total_rows); ?></span></button>
        <?php if ($aap_can_see_incoming): ?>
        <button type="button" class="aap-tab-btn <?php echo $active_tab === 'incoming' ? 'active' : ''; ?>" data-tab="incoming">Incoming from Fixit <span class="aap-tab-count red"><?php echo number_format($incoming_total); ?></span></button>
        <?php endif; ?>
    </div>

<div id="tab-queue" class="aap-tab-panel <?php echo $active_tab === 'queue' ? 'active' : ''; ?>">

    <div style="padding: 20px;">
        <form method="get" action="" class="aap-filter-compact">
            <div class="alpro-grid">
                <div class="alpro-field">
                    <label>Case Status</label>
                    <select class="alpro-input" name="case_status">
                        <option value="">All Statuses</option>
                        <?php foreach ([
                            'draft' => 'Investigation',
                            'confirming' => 'Verification',
                            'pending_approval' => 'Approval',
                            'executing' => 'Execute',
                            'closing' => 'Closing',
                            'rejected' => 'Rejected',
                            'closed' => 'Closed',
                        ] as $k => $v): ?>
                            <option value="<?php echo $k; ?>" <?php echo ($f_status === $k) ? 'selected' : ''; ?>><?php echo $v; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="alpro-field">
                    <label>Case Type</label>
                    <select class="alpro-input" name="case_type_id">
                        <option value="">All Case Types</option>
                        <?php foreach ($filter_case_types as $ct): ?>
                            <option value="<?php echo $ct['id']; ?>" <?php echo ($f_case_type === (int)$ct['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($ct['case_type_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="alpro-field">
                    <label>Outlet</label>
                    <select class="alpro-input" name="outlet_id">
                        <option value="">All Outlets</option>
                        <?php foreach ($filter_outlets as $ol): ?>
                            <option value="<?php echo $ol['id']; ?>" <?php echo ($f_outlet === (int)$ol['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($ol['code']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="alpro-field">
                    <label>Verification Required</label>
                    <select class="alpro-input" name="physical_confirm_status">
                        <option value="">All</option>
                        <option value="required" <?php echo ($f_physical === 'required') ? 'selected' : ''; ?>>Required</option>
                        <option value="not_required" <?php echo ($f_physical === 'not_required') ? 'selected' : ''; ?>>Not Required</option>
                    </select>
                </div>
                <div class="alpro-field">
                    <label>Approval</label>
                    <select class="alpro-input" name="approval_status">
                        <option value="">All</option>
                        <?php foreach (['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected'] as $k => $v): ?>
                            <option value="<?php echo $k; ?>" <?php echo ($f_approval === $k) ? 'selected' : ''; ?>><?php echo $v; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="alpro-field">
                    <label>Date Raised</label>
                    <input class="alpro-input" type="date" name="raised_date" value="<?php echo htmlspecialchars($f_date); ?>">
                </div>
                <div class="alpro-field">
                    <label>Date Closed</label>
                    <input class="alpro-input" type="date" name="closed_date" value="<?php echo htmlspecialchars($f_closed_date); ?>">
                </div>
                <div class="alpro-field" style="grid-column: 1 / -1;">
                    <label>Search (Ref / Transaction / Membership ID)</label>
                    <input class="alpro-input" type="text" name="q" value="<?php echo htmlspecialchars($f_search); ?>" placeholder="AAP-000123...">
                </div>
                <div class="alpro-actions" style="grid-column: 1 / -1; justify-content:flex-end;">
                    <input class="alpro-btn alpro-btn-blue" type="submit" value="Filter">
                    <a href="index.php" class="alpro-btn alpro-btn-grey" style="text-decoration:none;">Reset</a>
                </div>
            </div>
        </form>

        <div style="border-top: 1px solid #e9ecef; margin: 18px 0 0; padding-top: 18px;">
        <form method="post" action="aap_export_cases.php" target="_blank" id="export-form">
        <div style="display:flex; justify-content:flex-end; gap:8px; margin-bottom:10px;">
            <?php if ($aap_can_execute): ?>
            <button type="submit" formaction="aap_export_close_cases.php" class="alpro-btn alpro-btn-grey" id="export-close-btn" disabled onclick="return confirm('Close every selected case that has finished Execution, then export? Cases not yet executed are skipped and reported in the CSV, not closed. This cannot be undone.');"><i class="bi bi-check2-all"></i> Export &amp; Close</button>
            <?php endif; ?>
            <button type="submit" class="alpro-btn alpro-btn-grey" id="export-selected-btn" disabled><i class="bi bi-download"></i> Export Selected</button>
        </div>
        <table class="alpro-table" width="100%">
            <tr>
                <th style="width:32px;"><input type="checkbox" id="export-select-all"></th>
                <th>Case Ref</th>
                <th>Case Type</th>
                <th style="text-align:center;">Verification Required</th>
                <th>Approval</th>
                <th>Status</th>
                <th><?php echo aapSortHeaderLink('timestamp', 'Raised', $f_sort, $f_dir); ?></th>
                <th><?php echo aapSortHeaderLink('closed_at', 'Closed', $f_sort, $f_dir); ?></th>
                <th>Action</th>
            </tr>
            <?php if ($list_res && $list_res->num_rows > 0): ?>
                <?php while ($row = $list_res->fetch_assoc()): ?>
                <tr>
                    <td><input type="checkbox" class="export-row-checkbox" name="case_ids[]" value="<?php echo (int)$row['id']; ?>"></td>
                    <td class="alpro-mono">
                        <?php echo htmlspecialchars($row['case_ref']); ?>
                        <?php if (!empty($row['fixit_record_id'])): ?>
                            <br><a href="<?php echo htmlspecialchars(aapFixitTicketUrl($row['fixit_record_id'])); ?>" target="_blank" style="font-size:10px;">Fixit F<?php echo (int)$row['fixit_record_id']; ?></a>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php echo htmlspecialchars($row['case_type_name']); ?>
                        <?php $outlet_label = $row['fixit_outlet_code'] ?: $row['case_type_department_name']; ?>
                        <?php if (!empty($outlet_label)): ?>
                            <br><span class="alpro-muted" style="font-size:11px; color:#6c757d;"><?php echo htmlspecialchars($outlet_label); ?></span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:center;">
                        <?php list($pc_icon, $pc_color) = aapPhysicalStatusIcon($row['physical_confirm_status']); ?>
                        <i class="bi <?php echo $pc_icon; ?>" style="color:<?php echo $pc_color; ?>; font-size:16px;" title="<?php echo htmlspecialchars(aapPhysicalStatusLabel($row['physical_confirm_status'])); ?>"></i>
                    </td>
                    <td>
                        <?php $row_approval_display = aapApprovalStatusDisplay($row['approval_status']); ?>
                        <span class="alpro-badge alpro-badge-<?php echo $row_approval_display['slug']; ?>"><?php echo htmlspecialchars($row_approval_display['label']); ?></span>
                        <?php if ($row['approval_tier']): ?>
                            <span class="alpro-badge alpro-badge-<?php echo $row['approval_tier']; ?>"><?php echo ucfirst($row['approval_tier']); ?></span>
                        <?php endif; ?>
                    </td>
                    <?php $row_display_status = aapCaseDisplayStatus($row); ?>
                    <td><span class="alpro-badge alpro-badge-<?php echo $row_display_status['slug']; ?>"><?php echo htmlspecialchars($row_display_status['label']); ?></span></td>
                    <td><?php echo date('d-m-Y', strtotime($row['timestamp'])); ?></td>
                    <td><?php echo $row['closed_at'] ? date('d-m-Y', strtotime($row['closed_at'])) : '—'; ?></td>
                    <td><a href="aap_update.php?id=<?php echo $row['id']; ?>" class="alpro-btn alpro-btn-blue" style="text-decoration:none; padding:4px 10px; font-size:12px; display:inline-flex; align-items:center; justify-content:center;" title="Open"><i class="bi bi-box-arrow-up-right"></i></a></td>
                </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr>
                    <td colspan="9" align="center" style="padding: 15px;">No cases found for the current filter.</td>
                </tr>
            <?php endif; ?>
        </table>
        </form>
        </div>
    </div>

    <?php if ($total_pages > 1): ?>
    <div style="padding: 0 20px 20px;">
    <div style="display:flex; justify-content:center; gap:4px; margin-top:8px; flex-wrap:wrap;">
        <?php
        $qs = $_GET; unset($qs['page']);
        $base_qs = http_build_query($qs);
        for ($i = 1; $i <= $total_pages; $i++):
            $active = ($i == $page) ? 'background:#2980b9;color:white;border-color:#2980b9;' : 'background:white;color:#333;';
        ?>
            <a href="?<?php echo $base_qs; ?>&page=<?php echo $i; ?>" style="padding:3px 9px; border:1px solid #ddd; border-radius:4px; text-decoration:none; font-size:11px;<?php echo $active; ?>"><?php echo $i; ?></a>
        <?php endfor; ?>
    </div>
    <div style="text-align:center; font-size:11px; color:#7f8c8d; margin-top:4px;">
        Page <?php echo $page; ?> of <?php echo $total_pages; ?> &nbsp;|&nbsp; <?php echo $total_rows; ?> total cases
    </div>
    </div>
    <?php else: ?>
    <div style="padding-bottom: 4px;"></div>
    <?php endif; ?>

</div>

<?php if ($aap_can_see_incoming): ?>
<div id="tab-incoming" class="aap-tab-panel <?php echo $active_tab === 'incoming' ? 'active' : ''; ?>">

    <div style="padding: 20px;">
        <form method="get" action="" class="aap-filter-compact">
            <input type="hidden" name="tab" value="incoming">
            <div class="alpro-grid">
                <div class="alpro-field">
                    <label>Category</label>
                    <select class="alpro-input" name="if_category">
                        <option value="">All Categories</option>
                        <?php foreach ($filter_incoming_categories as $cat): ?>
                            <option value="<?php echo $cat['id']; ?>" <?php echo ($if_category === (int)$cat['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($cat['category']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="alpro-field">
                    <label>Outlet</label>
                    <select class="alpro-input" name="if_outlet">
                        <option value="">All Outlets</option>
                        <?php foreach ($filter_incoming_outlets as $ol): ?>
                            <option value="<?php echo $ol['id']; ?>" <?php echo ($if_outlet === (int)$ol['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($ol['code']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="alpro-field">
                    <label>Lodged By</label>
                    <input class="alpro-input" type="text" name="if_lodged_by" value="<?php echo htmlspecialchars($if_lodged_by); ?>" placeholder="Staff name...">
                </div>
                <div class="alpro-field">
                    <label>Date Lodged</label>
                    <input class="alpro-input" type="date" name="if_date" value="<?php echo htmlspecialchars($if_date); ?>">
                </div>
                <div class="alpro-actions" style="grid-column: 1 / -1; justify-content:flex-end;">
                    <input class="alpro-btn alpro-btn-blue" type="submit" value="Filter">
                    <a href="index.php?tab=incoming" class="alpro-btn alpro-btn-grey" style="text-decoration:none;">Reset</a>
                </div>
            </div>
        </form>

        <table class="alpro-table" width="100%" style="margin-top:16px;">
            <tr>
                <th>Fixit Ref</th>
                <th>Report</th>
                <th>Category</th>
                <th>Outlet</th>
                <th>Lodged By</th>
                <th>Lodged At</th>
                <th>Action</th>
            </tr>
            <?php if (!empty($incoming_fixit)): ?>
                <?php foreach ($incoming_fixit as $ft): ?>
                <tr>
                    <td class="alpro-mono"><a href="<?php echo htmlspecialchars(aapFixitTicketUrl($ft['id'])); ?>" target="_blank">F<?php echo (int)$ft['id']; ?></a></td>
                    <td><?php echo htmlspecialchars(mb_strimwidth($ft['report'], 0, 70, '…')); ?></td>
                    <td><?php echo htmlspecialchars($ft['category_name'] ?: '—'); ?></td>
                    <td><?php echo htmlspecialchars($ft['outlet_code'] ?: '—'); ?></td>
                    <td><?php echo htmlspecialchars($ft['lodged_by_name'] ?: '—'); ?></td>
                    <td><?php echo $ft['lodge'] ? date('d-m-Y H:i', strtotime($ft['lodge'])) : '—'; ?></td>
                    <td><a href="aap_add.php?fixit_id=<?php echo (int)$ft['id']; ?>&fixit_ref=F<?php echo (int)$ft['id']; ?>" class="alpro-btn alpro-btn-blue" style="text-decoration:none; padding:4px 10px; font-size:12px; display:inline-flex; align-items:center; justify-content:center;" title="Open Case"><i class="bi bi-box-arrow-up-right"></i></a></td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="7" align="center" style="padding: 15px;">Nothing waiting — every AAP-linked report from Fixit has been raised as an AAP case.</td>
                </tr>
            <?php endif; ?>
        </table>

        <?php if ($incoming_total_pages > 1): ?>
        <div style="display:flex; justify-content:center; gap:4px; margin-top:8px; flex-wrap:wrap;">
            <?php
            $iqs = $_GET; unset($iqs['ipage']); $iqs['tab'] = 'incoming';
            $i_base_qs = http_build_query($iqs);
            for ($i = 1; $i <= $incoming_total_pages; $i++):
                $i_active = ($i == $i_page) ? 'background:#2980b9;color:white;border-color:#2980b9;' : 'background:white;color:#333;';
            ?>
                <a href="?<?php echo $i_base_qs; ?>&ipage=<?php echo $i; ?>" style="padding:3px 9px; border:1px solid #ddd; border-radius:4px; text-decoration:none; font-size:11px;<?php echo $i_active; ?>"><?php echo $i; ?></a>
            <?php endfor; ?>
        </div>
        <div style="text-align:center; font-size:11px; color:#7f8c8d; margin-top:4px;">
            Page <?php echo $i_page; ?> of <?php echo $incoming_total_pages; ?> &nbsp;|&nbsp; <?php echo $incoming_total; ?> total incoming
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

</div>
</div>

<?php $page_js = 'js/index.js'; include('aap_footer.php'); ?>
