<?php
// Streams a CSV of the Case Queue rows the user ticked (index.php's Export
// column) - lock_adv.php can echo HTML before its own redirect check runs,
// which would corrupt the CSV stream, so buffer and discard it here first
// (same trick as aap_update.php's file-download branch).
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

$ids = isset($_POST['case_ids']) && is_array($_POST['case_ids']) ? array_map('intval', $_POST['case_ids']) : [];
$ids = array_values(array_filter($ids, function ($v) { return $v > 0; }));
if (empty($ids)) {
    die("No cases selected.");
}

// Re-scoped against the same visibility rule as the Case Queue itself
// (aapScopeWhere) - a user can only ever export cases they could already see
// in the list, regardless of which ids were posted (a tampered id list from
// outside the visible set is silently dropped, not an error, since the
// normal export flow can never produce one).
$scope_where = aapScopeWhere($id_user, $aap_dept_ids, $aap_is_admin);
$id_list = implode(',', $ids);
$sql = aapCaseSelectSql("$scope_where AND c.id IN ($id_list)", 'c.timestamp DESC');
$res = $conn->query($sql);

ob_end_clean();
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="aap_cases_' . date('Ymd_His') . '.csv"');

$out = fopen('php://output', 'w');
fputcsv($out, aapCaseExportCsvHeader());
while ($res && $row = $res->fetch_assoc()) {
    fputcsv($out, aapCaseExportCsvRow($conn, $row));
}
fclose($out);
exit;
