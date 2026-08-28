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

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$case = $id ? aapFetchCase($conn, $id) : null;
if (!$case) {
    die("Case not found.");
}

$can_void = (in_array($case['case_status'], ['draft', 'open'], true) && $case['approval_status'] === 'pending')
            && ((int)$case['created_by'] === (int)$id_user || $aap_is_admin);

if (!$can_void) {
    die("This case can no longer be voided — it has already progressed past the Approval Gate, or you don't have permission.");
}

$msg = "";
$msg_type = "";

if (isset($_POST['confirm_void'])) {
    $reason = trim($_POST['void_reason']);
    $now = date('Y-m-d H:i:s');
    $stmt = $conn->prepare("UPDATE aap_cases SET case_status='voided', closed_at=?, updated_at=? WHERE id=?");
    $stmt->bind_param("ssi", $now, $now, $id);
    $stmt->execute();
    $stmt->close();
    aapLogAudit($conn, $id, 'case_voided', $id_user, "Case voided" . ($reason ? " — $reason" : ""));
    header("Location: index.php?voided=1");
    exit;
}
?>

<div class="header">
  <b class="rtop"><b class="r1"></b><b class="r2"></b><b class="r3"></b><b class="r4"></b></b>
  <h1 class="headerH1"><img src="img/logo.svg"> Void Case <?php echo htmlspecialchars($case['case_ref']); ?></h1>
  <b class="rbottom"><b class="r4"></b><b class="r3"></b><b class="r2"></b><b class="r1"></b></b>
</div>

<?php include('aap_sidebar.php'); ?>

<div class="alpro-box alpro-mt-20" style="max-width:600px;">
    <div class="alpro-alert alpro-warn">
        Voiding removes <?php echo htmlspecialchars($case['case_ref']); ?> (<?php echo htmlspecialchars($case['case_type_name']); ?>) from the active queue.
        This is only possible before the case reaches a decision at the Approval Gate — it cannot be undone.
    </div>

    <form method="post" action="">
        <div class="alpro-field">
            <label>Reason</label>
            <textarea class="alpro-input" name="void_reason" style="height:80px;" placeholder="Why is this case being voided?"></textarea>
        </div>
        <div class="alpro-actions alpro-mt-20">
            <input class="alpro-btn alpro-btn-orange" type="submit" name="confirm_void" value="Confirm Void" onclick="return confirm('Void this case? This cannot be undone.');">
            <a href="aap_update.php?id=<?php echo $id; ?>" class="alpro-btn alpro-btn-grey" style="text-decoration:none;">Cancel</a>
        </div>
    </form>
</div>

<?php include('aap_footer.php'); ?>
