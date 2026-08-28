<?php
require_once('../lock_adv.php');
$connect = 1;
date_default_timezone_set('Asia/Kuala_Lumpur');
include('../common/index_adv.php');
if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection not available.");
}
require_once('aap_lib.php');

$aap_dept_ids = aapDeptIdsFromCsv($department);
$aap_is_admin = aapIsAdmin($grade, $aap_dept_ids, aapFetchIsSuperAdmin($conn, $id_user));
$aap_is_operations = aapIsOperations($aap_dept_ids);
if ((int)$grade < 1 && !$aap_is_admin) {
    die("You do not have access to this module.");
}

// ---- Filters ----
$f_status      = isset($_GET['case_status']) ? trim($_GET['case_status']) : '';
$f_case_type   = isset($_GET['case_type_id']) && $_GET['case_type_id'] !== '' ? (int)$_GET['case_type_id'] : null;
$f_physical    = isset($_GET['physical_confirm_status']) ? trim($_GET['physical_confirm_status']) : '';
$f_approval    = isset($_GET['approval_status']) ? trim($_GET['approval_status']) : '';
$f_execution   = isset($_GET['execution_status']) ? trim($_GET['execution_status']) : '';
$f_date        = isset($_GET['raised_date']) ? trim($_GET['raised_date']) : '';
$f_closed_date = isset($_GET['closed_date']) ? trim($_GET['closed_date']) : '';
$f_search      = isset($_GET['q']) ? trim($_GET['q']) : '';

// Drafts aren't part of the main queue - they get their own tab below so
// they don't clutter the active queue/stats while evidence is still being
// gathered.
$where = aapScopeWhere($id_user, $aap_dept_ids, $aap_is_admin) . " AND c.case_status != 'draft'";
if ($f_status !== '' && in_array($f_status, ['open','rejected','executed','closed','voided'], true)) {
    $where .= " AND c.case_status = '" . $conn->real_escape_string($f_status) . "'";
}
if ($f_case_type !== null) {
    $where .= " AND c.case_type_id = " . $f_case_type;
}
if ($f_physical === 'required') {
    $where .= " AND c.physical_confirm_required = 1";
} elseif ($f_physical === 'not_required') {
    $where .= " AND c.physical_confirm_required = 0";
}
if ($f_approval !== '' && in_array($f_approval, ['pending','approved','corrected','rejected'], true)) {
    $where .= " AND c.approval_status = '" . $conn->real_escape_string($f_approval) . "'";
}
if ($f_execution !== '' && in_array($f_execution, ['pending','executed'], true)) {
    $where .= " AND c.execution_status = '" . $conn->real_escape_string($f_execution) . "'";
}
if ($f_date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_date)) {
    $where .= " AND DATE(c.timestamp) = '" . $conn->real_escape_string($f_date) . "'";
}
if ($f_closed_date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_closed_date)) {
    $where .= " AND DATE(c.closed_at) = '" . $conn->real_escape_string($f_closed_date) . "'";
}
if ($f_search !== '') {
    $s = $conn->real_escape_string($f_search);
    $where .= " AND (c.case_ref LIKE '%$s%' OR c.transaction_ref LIKE '%$s%' OR c.customer_membership_id LIKE '%$s%')";
}

$filter_case_types = aapFetchCaseTypes($conn, true);

// ---- Queue-position stat strip ----
$stat_where = aapScopeWhere($id_user, $aap_dept_ids, $aap_is_admin);
$stats = ['draft' => 0, 'open' => 0, 'pending_physical' => 0, 'pending_approval' => 0, 'pending_execution' => 0, 'closed_today' => 0];
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

$list_sql = aapCaseSelectSql($where, 'c.timestamp DESC') . " LIMIT $limit OFFSET $offset";
$list_res = $conn->query($list_sql);

// Fixit tickets lodged under an AAP-linked category that haven't been
// converted into an AAP case yet — CS/Operations/admin's work queue, so
// nobody has to remember to click through from Fixit.
$aap_can_see_incoming = $aap_is_admin || $aap_is_operations || aapIsCustomerSupport($aap_dept_ids);

$i_limit = 20;
$i_page = isset($_GET['ipage']) ? max(1, (int)$_GET['ipage']) : 1;
$incoming_total = $aap_can_see_incoming ? aapCountIncomingFixitTickets($conn) : 0;
$incoming_total_pages = max(1, (int)ceil($incoming_total / $i_limit));
$i_page = min($i_page, $incoming_total_pages);
$i_offset = ($i_page - 1) * $i_limit;
$incoming_fixit = $aap_can_see_incoming ? aapFetchIncomingFixitTickets($conn, $i_limit, $i_offset) : [];

// ---- Draft cases (own scope) ----
// Not yet opened for the approval workflow - kept in their own tab so the
// issuer/CS team has a place to keep gathering evidence without the case
// cluttering the main queue or dashboard stats.
$d_limit = 20;
$d_page = isset($_GET['dpage']) ? max(1, (int)$_GET['dpage']) : 1;
$draft_where = aapScopeWhere($id_user, $aap_dept_ids, $aap_is_admin) . " AND c.case_status = 'draft'";
$draft_count_res = $conn->query("SELECT COUNT(*) c FROM aap_cases c WHERE $draft_where");
$draft_total = $draft_count_res ? (int)$draft_count_res->fetch_assoc()['c'] : 0;
$draft_total_pages = max(1, (int)ceil($draft_total / $d_limit));
$d_page = min($d_page, $draft_total_pages);
$d_offset = ($d_page - 1) * $d_limit;
$draft_list_sql = aapCaseSelectSql($draft_where, 'c.timestamp DESC') . " LIMIT $d_limit OFFSET $d_offset";
$draft_res = $conn->query($draft_list_sql);

$active_tab = (isset($_GET['tab']) && in_array($_GET['tab'], ['incoming', 'draft'], true) && ($_GET['tab'] !== 'incoming' || $aap_can_see_incoming)) ? $_GET['tab'] : 'queue';
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
        <h3>Draft</h3>
        <div class="value"><?php echo number_format($stats['draft']); ?></div>
    </div>
    <div class="aap-stat">
        <h3>Open Cases</h3>
        <div class="value"><?php echo number_format($stats['open']); ?></div>
    </div>
    <div class="aap-stat" style="border-top-color:#f39c12;">
        <h3>Physical Confirm Pending</h3>
        <div class="value"><?php echo number_format($stats['pending_physical']); ?></div>
    </div>
    <div class="aap-stat" style="border-top-color:#fd7e14;">
        <h3>Pending Approval</h3>
        <div class="value"><?php echo number_format($stats['pending_approval']); ?></div>
    </div>
    <div class="aap-stat" style="border-top-color:#0dcaf0;">
        <h3>Approved, Awaiting Execution</h3>
        <div class="value"><?php echo number_format($stats['pending_execution']); ?></div>
    </div>
    <div class="aap-stat" style="border-top-color:#6c757d;">
        <h3>Closed</h3>
        <div class="value"><?php echo number_format($stats['closed_today']); ?></div>
    </div>
</div>

<div class="aap-card" style="padding: 0;">
    <div class="aap-tabs" style="padding: 6px 20px 0; border-bottom-color: #e9ecef;">
        <button type="button" class="aap-tab-btn <?php echo $active_tab === 'queue' ? 'active' : ''; ?>" data-tab="queue">Case Queue <span class="aap-tab-count"><?php echo number_format($total_rows); ?></span></button>
        <button type="button" class="aap-tab-btn <?php echo $active_tab === 'draft' ? 'active' : ''; ?>" data-tab="draft">Draft <span class="aap-tab-count"><?php echo number_format($draft_total); ?></span></button>
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
                        <?php foreach (['open' => 'Open', 'rejected' => 'Rejected', 'executed' => 'Executed', 'closed' => 'Closed', 'voided' => 'Voided'] as $k => $v): ?>
                            <option value="<?php echo $k; ?>" <?php echo ($f_status === $k) ? 'selected' : ''; ?>><?php echo $v; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="alpro-field">
                    <label>Case Type</label>
                    <select class="alpro-input" name="case_type_id">
                        <option value="">All Case Types</option>
                        <?php foreach ($filter_case_types as $ct): ?>
                            <option value="<?php echo $ct['id']; ?>" <?php echo ($f_case_type === (int)$ct['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($ct['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="alpro-field">
                    <label>Physical Confirm</label>
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
                        <?php foreach (['pending' => 'Pending', 'approved' => 'Approved', 'corrected' => 'Corrected', 'rejected' => 'Rejected'] as $k => $v): ?>
                            <option value="<?php echo $k; ?>" <?php echo ($f_approval === $k) ? 'selected' : ''; ?>><?php echo $v; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="alpro-field">
                    <label>Execution</label>
                    <select class="alpro-input" name="execution_status">
                        <option value="">All</option>
                        <?php foreach (['pending' => 'Pending', 'executed' => 'Executed'] as $k => $v): ?>
                            <option value="<?php echo $k; ?>" <?php echo ($f_execution === $k) ? 'selected' : ''; ?>><?php echo $v; ?></option>
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
        <table class="alpro-table" width="100%">
            <tr>
                <th>Case Ref</th>
                <th>Case Type</th>
                <th>Physical Confirm</th>
                <th>Approval</th>
                <th>Execution</th>
                <th>Status</th>
                <th>Raised</th>
                <th>Closed</th>
                <th>Action</th>
            </tr>
            <?php if ($list_res && $list_res->num_rows > 0): ?>
                <?php while ($row = $list_res->fetch_assoc()): ?>
                <tr>
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
                        <span class="alpro-badge alpro-badge-<?php echo htmlspecialchars($row['approval_status']); ?>"><?php echo ucfirst($row['approval_status']); ?></span>
                        <?php if ($row['approval_tier']): ?>
                            <span class="alpro-badge alpro-badge-<?php echo $row['approval_tier']; ?>"><?php echo ucfirst($row['approval_tier']); ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo ucfirst($row['execution_status']); ?></td>
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

<div id="tab-draft" class="aap-tab-panel <?php echo $active_tab === 'draft' ? 'active' : ''; ?>">
    <div style="padding: 20px;">
        <table class="alpro-table" width="100%">
            <tr>
                <th>Case Ref</th>
                <th>Case Type</th>
                <th>Value</th>
                <th>Raised By</th>
                <th>Raised</th>
                <th>Action</th>
            </tr>
            <?php if ($draft_res && $draft_res->num_rows > 0): ?>
                <?php while ($row = $draft_res->fetch_assoc()): ?>
                <tr>
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
                    <td><?php echo aapFormatValue($row['calculated_value'], $row['value_type']); ?></td>
                    <td><?php echo htmlspecialchars($row['requester_name'] ?: '—'); ?></td>
                    <td><?php echo date('d-m-Y', strtotime($row['timestamp'])); ?></td>
                    <td><a href="aap_update.php?id=<?php echo $row['id']; ?>" class="alpro-btn alpro-btn-blue" style="text-decoration:none; padding:4px 10px; font-size:12px; display:inline-flex; align-items:center; justify-content:center;" title="Open"><i class="bi bi-box-arrow-up-right"></i></a></td>
                </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr>
                    <td colspan="6" align="center" style="padding: 15px;">No draft cases.</td>
                </tr>
            <?php endif; ?>
        </table>

        <?php if ($draft_total_pages > 1): ?>
        <div style="display:flex; justify-content:center; gap:4px; margin-top:8px; flex-wrap:wrap;">
            <?php
            $dqs = $_GET; unset($dqs['dpage']); $dqs['tab'] = 'draft';
            $d_base_qs = http_build_query($dqs);
            for ($i = 1; $i <= $draft_total_pages; $i++):
                $d_active = ($i == $d_page) ? 'background:#2980b9;color:white;border-color:#2980b9;' : 'background:white;color:#333;';
            ?>
                <a href="?<?php echo $d_base_qs; ?>&dpage=<?php echo $i; ?>" style="padding:3px 9px; border:1px solid #ddd; border-radius:4px; text-decoration:none; font-size:11px;<?php echo $d_active; ?>"><?php echo $i; ?></a>
            <?php endfor; ?>
        </div>
        <div style="text-align:center; font-size:11px; color:#7f8c8d; margin-top:4px;">
            Page <?php echo $d_page; ?> of <?php echo $draft_total_pages; ?> &nbsp;|&nbsp; <?php echo $draft_total; ?> total drafts
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($aap_can_see_incoming): ?>
<div id="tab-incoming" class="aap-tab-panel <?php echo $active_tab === 'incoming' ? 'active' : ''; ?>">

    <div style="padding: 20px;">
        <table class="alpro-table" width="100%">
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
                    <td><a href="aap_add.php?fixit_id=<?php echo (int)$ft['id']; ?>&fixit_ref=F<?php echo (int)$ft['id']; ?>" class="alpro-btn alpro-btn-blue" style="text-decoration:none; padding:4px 10px; font-size:12px;">Raise Case</a></td>
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
