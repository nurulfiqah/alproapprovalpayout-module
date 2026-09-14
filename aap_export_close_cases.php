<?php
// Bulk "Export & Close" from the Case Queue (index.php) - same authority as
// the single-case Execute/Close flow on aap_update.php: Level 3/execution
// only (aapCanExecute()), not every admin/creator. Closes every selected
// case that's actually eligible (case_status must already be 'executed',
// i.e. Execution is done and it's just waiting on this step), THEN streams
// a CSV of every selected case with an extra "Close Action" column
// reporting what happened to each one - closed just now, already closed, or
// skipped (and why) - so a case that wasn't actually ready never silently
// vanishes from the export, it just shows why it wasn't closed.
ob_start();
require_once('../lock_adv.php');
$connect = 1;
date_default_timezone_set('Asia/Kuala_Lumpur');
include('../common/index_adv.php');
if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection not available.");
}
require_once('aap_lib.php');

$aap_identity = aapResolveIdentity($conn, $id_user, $grade, $department);
$grade = $aap_identity['grade'];
$department = $aap_identity['department'];
$aap_dept_ids = $aap_identity['dept_ids'];
$aap_is_admin = $aap_identity['is_admin'];
if ((int)$grade < 1 && !$aap_is_admin) {
    die("You do not have access to this module.");
}
$aap_can_execute = aapCanExecute($grade, $aap_dept_ids, $aap_is_admin);
if (!$aap_can_execute) {
    die("Execution access only. This bulk action is limited to whoever can execute cases.");
}

$ids = isset($_POST['case_ids']) && is_array($_POST['case_ids']) ? array_map('intval', $_POST['case_ids']) : [];
$ids = array_values(array_filter($ids, function ($v) { return $v > 0; }));
if (empty($ids)) {
    die("No cases selected.");
}

// Re-scoped against the same visibility rule as the Case Queue itself - a
// user can only ever act on/export cases they could already see in the
// list, regardless of which ids were posted.
$scope_where = aapScopeWhere($id_user, $aap_dept_ids, $aap_is_admin);
$id_list = implode(',', $ids);
$now = date('Y-m-d H:i:s');

$sql = aapCaseSelectSql("$scope_where AND c.id IN ($id_list)", 'c.timestamp DESC');
$res = $conn->query($sql);
$cases = [];
while ($res && $row = $res->fetch_assoc()) $cases[] = $row;

$close_action = []; // case id => what happened, for the CSV's extra column
foreach ($cases as $case) {
    $cid = (int)$case['id'];
    if ($case['case_status'] === 'closed') {
        $close_action[$cid] = 'Already closed';
        continue;
    }
    if ($case['case_status'] !== 'executed') {
        $close_action[$cid] = 'Skipped - execution not done yet';
        continue;
    }
    // Execution eligibility ($aap_can_execute) is a viewer-level permission,
    // already required for this whole endpoint (see the die() above) - no
    // per-case check needed here, unlike the department-scoped checks
    // elsewhere in the module.
    $stmt = $conn->prepare("UPDATE aap_cases SET case_status='closed', closed_at=?, updated_at=? WHERE id=?");
    $stmt->bind_param("ssi", $now, $now, $cid);
    $stmt->execute(); $stmt->close();
    aapLogAudit($conn, $cid, 'case_closed', $id_user, "Case closed and requester notified (bulk close from Case Queue export).");
    aapNotifyStaff($conn, $cid, (int)$case['created_by'], 'case_closed');
    if (!empty($case['fixit_record_id'])) {
        aapCompleteLinkedFixitTicket($conn, $case['fixit_record_id'], $id_user);
    }
    $close_action[$cid] = 'Closed now';
}

// Re-query so the CSV reflects the just-closed state (case_status/closed_at)
// rather than the pre-close snapshot taken above.
$res = $conn->query($sql);

ob_end_clean();
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="aap_cases_closed_' . date('Ymd_His') . '.csv"');

$out = fopen('php://output', 'w');
fputcsv($out, array_merge(aapCaseExportCsvHeader(), ['Close Action']));
while ($res && $row = $res->fetch_assoc()) {
    fputcsv($out, array_merge(aapCaseExportCsvRow($conn, $row), [$close_action[(int)$row['id']] ?? '']));
}
fclose($out);
exit;
