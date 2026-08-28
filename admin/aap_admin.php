<?php
// Buffers everything so the update_aap_ceiling AJAX endpoint below can
// discard the HTML lock_adv.php echoes before its redirect check runs and
// emit clean JSON instead - same trick used in aap_settings.php.
ob_start();
require_once('../../lock_adv.php');
$connect = 1;
date_default_timezone_set('Asia/Kuala_Lumpur');
include('../../common/index_adv.php');
if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection not available.");
}
require_once('../aap_lib.php');

$aap_dept_ids = aapDeptIdsFromCsv($department);
$aap_is_admin = aapIsAdmin($grade, $aap_dept_ids, aapFetchIsSuperAdmin($conn, $id_user));
if (!$aap_is_admin) {
    die("Admin access only. This page manages the Case Type Registry and Staff Approval Ceilings.");
}

// ---- Update Approval Ceiling - AJAX endpoint (JSON) used by the "Staff in
// Pool" panels within the Case Type form above. staff_id is the PK of
// aap_staff_thresholds; a missing row means "no approval rights", and a row
// with threshold_amount = NULL means "unlimited" - see aapGetStaffThreshold().
if (isset($_POST['action']) && $_POST['action'] === 'update_aap_ceiling' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_end_clean();
    header('Content-Type: application/json');
    $target_id = (int)($_POST['staff_id'] ?? 0);
    $mode = $_POST['mode'] ?? '';

    if ($target_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid staff.']);
        exit;
    }
    $check = $conn->query("SELECT id FROM staff WHERE id = $target_id AND recycle != 1");
    if (!$check || $check->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'Staff not found.']);
        exit;
    }
    $ts = date('Y-m-d H:i:s');

    if ($mode === 'remove') {
        $stmt = $conn->prepare("DELETE FROM aap_staff_thresholds WHERE staff_id = ?");
        $stmt->bind_param("i", $target_id);
        $stmt->execute(); $stmt->close();
        echo json_encode(['success' => true, 'message' => 'Approval rights removed.']);
        exit;
    }

    $threshold_amount = ($mode === 'unlimited') ? null : (float)($_POST['threshold_amount'] ?? 0);
    $stmt = $conn->prepare("
        INSERT INTO aap_staff_thresholds (staff_id, threshold_amount, updated_by, timestamp)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE threshold_amount = VALUES(threshold_amount), updated_by = VALUES(updated_by), timestamp = VALUES(timestamp)
    ");
    $stmt->bind_param("idis", $target_id, $threshold_amount, $id_user, $ts);
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Ceiling updated.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
    }
    $stmt->close();
    exit;
}

$msg = "";
$msg_type = "";
$now = date('Y-m-d H:i:s');

// ---- Case Type save (add or update) ----
if (isset($_POST['save_case_type'])) {
    $ctid = (int)$_POST['case_type_id'];
    $department_id = (int)$_POST['department_id_ct'];
    $name = trim($_POST['ct_name']);
    $code = strtoupper(preg_replace('/[^A-Za-z0-9_]/', '_', trim($_POST['code'])));
    $default_requester_type = in_array($_POST['default_requester_type'], ['customer', 'outlet', 'bu'], true) ? $_POST['default_requester_type'] : 'customer';
    $physical_confirm_required = isset($_POST['physical_confirm_required']) ? 1 : 0;
    $approver_mode = in_array($_POST['approver_mode'], ['operations_tier', 'cs_tier', 'bu_signoff'], true) ? $_POST['approver_mode'] : 'operations_tier';
    // Only meaningful for cs_tier - an extra grade floor applied to an
    // Operations (non-CS) approver on top of the CS+Operations pool, see
    // aapCanApprove() in aap_lib.php. Ignored/cleared for the other modes.
    $ops_tier_required = ($approver_mode === 'cs_tier' && in_array($_POST['ops_tier_required'] ?? '', ['executive', 'manager'], true)) ? $_POST['ops_tier_required'] : null;
    $turnaround_days = ($_POST['turnaround_days'] !== '') ? (int)$_POST['turnaround_days'] : null;
    $description = trim($_POST['ct_description']);

    if ($name === '' || $code === '' || $department_id <= 0) {
        $msg = "Case Type name, code, and department are required."; $msg_type = "alpro-danger";
    } elseif ($ctid > 0) {
        // sort_order/systems_note dropped from this form - left untouched on
        // update so any value set elsewhere (e.g. a future admin tool) survives.
        $stmt = $conn->prepare("UPDATE aap_case_types SET department_id=?, name=?, code=?, default_requester_type=?, physical_confirm_required=?, approver_mode=?, ops_tier_required=?, turnaround_days=?, description=? WHERE id=?");
        $stmt->bind_param("isssissisi", $department_id, $name, $code, $default_requester_type, $physical_confirm_required, $approver_mode, $ops_tier_required, $turnaround_days, $description, $ctid);
        if ($stmt->execute()) { $msg = "Case Type updated."; $msg_type = "alpro-success"; }
        else { $msg = "Error: " . $stmt->error; $msg_type = "alpro-danger"; }
        $stmt->close();
    } else {
        $stmt = $conn->prepare("INSERT INTO aap_case_types (department_id, name, code, default_requester_type, physical_confirm_required, approver_mode, ops_tier_required, turnaround_days, description, sort_order, recycle, timestamp) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, ?)");
        $stmt->bind_param("isssississ", $department_id, $name, $code, $default_requester_type, $physical_confirm_required, $approver_mode, $ops_tier_required, $turnaround_days, $description, $now);
        if ($stmt->execute()) { $msg = "Case Type created."; $msg_type = "alpro-success"; }
        else { $msg = "Error: " . $stmt->error . " (code must be unique)"; $msg_type = "alpro-danger"; }
        $stmt->close();
    }
}

// ---- Case Type recycle toggle ----
if (isset($_POST['toggle_case_type_recycle'])) {
    $ctid = (int)$_POST['case_type_id'];
    $conn->query("UPDATE aap_case_types SET recycle = 1 - recycle WHERE id = " . $ctid);
    $msg = "Case Type visibility updated."; $msg_type = "alpro-success";
}

$edit_case_type = null;
if (isset($_GET['edit_case_type'])) {
    $edit_case_type = aapFetchCaseType($conn, (int)$_GET['edit_case_type']);
}

$case_types = aapFetchCaseTypes($conn, true);
$departments = aapFetchDepartments($conn);

// Staff rosters shown under the Approver Mode field in the Case Type form,
// letting the admin see and edit each pool's RM ceilings right there
// instead of a separate staff-wide panel.
function aapFetchPoolStaffWithCeiling($conn, $dept_id) {
    $out = [];
    $res = $conn->query("
        SELECT s.id, s.nama_staff, s.grade, t.threshold_amount, t.staff_id AS has_ceiling
        FROM staff s
        LEFT JOIN aap_staff_thresholds t ON t.staff_id = s.id
        WHERE s.recycle != 1 AND FIND_IN_SET(" . (int)$dept_id . ", s.department)
        ORDER BY s.nama_staff ASC
    ");
    while ($res && $row = $res->fetch_assoc()) {
        $out[] = [
            'id' => (int)$row['id'],
            'name' => $row['nama_staff'],
            'grade' => (int)$row['grade'],
            'has_ceiling' => $row['has_ceiling'] !== null,
            'threshold_amount' => $row['threshold_amount'] !== null ? (float)$row['threshold_amount'] : null,
        ];
    }
    return $out;
}
// Kept as two separate department rosters (rather than one merged list) so
// the Case Type form can show CS Tier as two distinct sections - Customer
// Support and Operations - since Operations can also act on CS-tier cases
// (see aapCanApprove() in aap_lib.php), subject to the Operations Tier
// Required grade filter shown per Operations row.
$aap_pool_staff = [
    'operations' => aapFetchPoolStaffWithCeiling($conn, AAP_DEPT_OPERATION),
    'customer_support' => aapFetchPoolStaffWithCeiling($conn, AAP_DEPT_CUSTOMER_SUPPORT),
];
$aap_base = '../';
?>

<?php include('../aap_modern_head.php'); ?>

<div class="header">
  <b class="rtop"><b class="r1"></b><b class="r2"></b><b class="r3"></b><b class="r4"></b></b>
  <h1 class="headerH1"><img src="../img/logo.svg"> Case Type Registry (Admin)</h1>
  <b class="rbottom"><b class="r4"></b><b class="r3"></b><b class="r2"></b><b class="r1"></b></b>
</div>

<?php include('../aap_sidebar.php'); ?>

<?php if (!empty($msg)): ?>
    <div class="alpro-alert <?php echo $msg_type; ?> alpro-mt-20"><?php echo htmlspecialchars($msg); ?></div>
<?php endif; ?>

<!-- ===================== CASE TYPES ===================== -->
<div class="alpro-mt-20 aap-ct-card<?php echo $edit_case_type ? ' aap-ct-card-editing' : ''; ?>">
    <h3><?php echo $edit_case_type ? 'Edit Case Type' : 'Add Case Type'; ?></h3>
    <form method="post" action="">
        <input type="hidden" name="case_type_id" value="<?php echo $edit_case_type ? $edit_case_type['id'] : 0; ?>">
        <input type="hidden" name="default_requester_type" value="<?php echo htmlspecialchars($edit_case_type['default_requester_type'] ?? 'customer'); ?>">

        <!-- Level 1 -->
        <p class="aap-ct-level-title">Level 1</p>
        <div class="aap-ct-level aap-ct-level-1">
            <div class="aap-ct-field">
                <label>Case Type Name <span style="color:red;">*</span></label>
                <input class="alpro-input" type="text" name="ct_name" id="ct_name" value="<?php echo htmlspecialchars($edit_case_type['name'] ?? ''); ?>" required>
            </div>
            <div class="aap-ct-field">
                <label>Code <span class="alpro-muted" style="font-weight:normal;">(auto)</span></label>
                <input class="alpro-input alpro-mono" type="text" name="code" id="ct_code" value="<?php echo htmlspecialchars($edit_case_type['code'] ?? ''); ?>" placeholder="UNIQUE_CODE" readonly style="background:#f1f3f5;">
            </div>
            <div class="aap-ct-field">
                <label>Department <span style="color:red;">*</span></label>
                <select class="alpro-input" name="department_id_ct" required>
                    <option value="">Select Department</option>
                    <?php foreach ($departments as $dept): ?>
                        <option value="<?php echo $dept['id']; ?>" <?php echo (isset($edit_case_type['department_id']) && $edit_case_type['department_id'] == $dept['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($dept['depart_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="aap-ct-field">
                <label>Physical Return Confirmation Required
                    <span class="aap-admin-info-icon">i<span class="aap-admin-tooltip">When checked, a case raised under this Case Type must have the returned item tagged and confirmed at ACMM before it can reach the Approval Gate. Leave unchecked for cases with no physical item to return (e.g. points/credit adjustments).</span></span>
                </label>
                <div class="aap-ct-field-inline"><input type="checkbox" name="physical_confirm_required" value="1" <?php echo !empty($edit_case_type['physical_confirm_required']) ? 'checked' : ''; ?>></div>
            </div>
        </div>

        <!-- Level 2 -->
        <p class="aap-ct-level-title">Level 2</p>
        <div class="aap-ct-level aap-ct-level-2">
            <div class="aap-ct-field">
                <label>Approver Mode
                    <span class="aap-admin-info-icon">i<span class="aap-admin-tooltip">Which pool of staff can approve this Case Type — Operations only, Customer Support or Operations, or the requester's own department (BU Sign-off). Within that pool, a staff member can only act if their personal RM ceiling (set per staff below) covers the case's value.</span></span>
                </label>
                <select class="alpro-input" name="approver_mode" id="ct_approver_mode">
                    <option value="operations_tier" <?php echo (($edit_case_type['approver_mode'] ?? 'operations_tier') === 'operations_tier') ? 'selected' : ''; ?>>Operations Tier</option>
                    <option value="cs_tier" <?php echo (($edit_case_type['approver_mode'] ?? '') === 'cs_tier') ? 'selected' : ''; ?>>Customer Support Tier</option>
                    <option value="bu_signoff" <?php echo (($edit_case_type['approver_mode'] ?? '') === 'bu_signoff') ? 'selected' : ''; ?>>BU Sign-off</option>
                </select>
            </div>
            <div class="aap-ct-field" id="ct_ops_tier_field" style="display:none;">
                <label>Operations Tier Required
                    <span class="aap-admin-info-icon">i<span class="aap-admin-tooltip">Extra filter on top of the Customer Support + Operations pool above. If set, an Operations staff member (who isn't also Customer Support) additionally needs this grade to approve - Executive needs grade ≥ 1, Manager needs grade ≥ 3. Customer Support staff are never affected by this. Leave as "Any" to keep any Operations staff eligible regardless of grade.</span></span>
                </label>
                <select class="alpro-input" name="ops_tier_required" id="ct_ops_tier_required">
                    <option value="" <?php echo empty($edit_case_type['ops_tier_required']) ? 'selected' : ''; ?>>Any</option>
                    <option value="executive" <?php echo (($edit_case_type['ops_tier_required'] ?? '') === 'executive') ? 'selected' : ''; ?>>Executive (grade ≥ 1)</option>
                    <option value="manager" <?php echo (($edit_case_type['ops_tier_required'] ?? '') === 'manager') ? 'selected' : ''; ?>>Manager (grade ≥ 3)</option>
                </select>
            </div>
            <div class="aap-ct-field" id="ct_pool_staff_field" style="display:none;">
                <div id="ct_pool_section_cs" style="display:none;">
                    <label>Staff in Customer Support</label>
                    <div id="ct_pool_list_cs" style="border:1px solid #e5e9ec; border-radius:6px; padding:8px 10px; max-height:160px; max-width:500px; overflow-y:auto; font-size:12px;"></div>
                </div>
                <div id="ct_pool_section_ops" style="display:none;">
                    <label id="ct_pool_ops_label">Staff in Operations</label>
                    <div id="ct_pool_list_ops" style="border:1px solid #e5e9ec; border-radius:6px; padding:8px 10px; max-height:160px; max-width:500px; overflow-y:auto; font-size:12px;"></div>
                </div>
            </div>
        </div>

        <!-- Level 3 -->
        <p class="aap-ct-level-title">Level 3</p>
        <div class="aap-ct-level aap-ct-level-3">
            <div class="aap-ct-field">
                <label>Turnaround (Days)
                    <span class="aap-admin-info-icon">i<span class="aap-admin-tooltip">Target number of working days to resolve a case raised under this Case Type. Shown to the requester as a guide on the Raise Case form — it's informational only, not enforced by the system.</span></span>
                </label>
                <input class="alpro-input" type="number" min="0" name="turnaround_days" value="<?php echo htmlspecialchars($edit_case_type['turnaround_days'] ?? ''); ?>">
            </div>
            <div class="aap-ct-field" style="grid-column: 1 / -1;">
                <label>Description</label>
                <input class="alpro-input" type="text" name="ct_description" value="<?php echo htmlspecialchars($edit_case_type['description'] ?? ''); ?>">
            </div>
        </div>

        <div class="alpro-actions alpro-mt-10" style="justify-content:flex-end;">
            <input class="alpro-btn alpro-btn-blue" type="submit" name="save_case_type" value="<?php echo $edit_case_type ? 'Update Case Type' : 'Create Case Type'; ?>">
            <?php if ($edit_case_type): ?><a href="aap_admin.php" class="alpro-btn alpro-btn-grey" style="text-decoration:none;">Cancel</a><?php endif; ?>
        </div>
    </form>
</div>

<div class="aap-modern">
<div class="aap-card alpro-mt-20">
    <table class="alpro-table aap-ct-list-table" width="100%">
        <tr><th>Code</th><th>Name</th><th>Department</th><th>Approver Mode</th><th>Eligible Staff</th><th>Physical</th><th>Status</th><th>Action</th></tr>
        <?php $dept_names = array_column($departments, 'depart_name', 'id'); ?>
        <?php foreach ($case_types as $ct): ?>
        <tr>
            <td class="alpro-mono"><?php echo htmlspecialchars($ct['code']); ?></td>
            <td><?php echo htmlspecialchars($ct['name']); ?></td>
            <td><?php echo htmlspecialchars($dept_names[$ct['department_id']] ?? '—'); ?></td>
            <td>
                <?php echo aapApproverModeLabel($ct['approver_mode']); ?>
                <?php if ($ct['approver_mode'] === 'cs_tier' && !empty($ct['ops_tier_required'])): ?>
                    <br><span class="alpro-muted" style="font-size:11px;">Ops: <?php echo ucfirst($ct['ops_tier_required']); ?>+</span>
                <?php endif; ?>
            </td>
            <td>
                <?php if ($ct['approver_mode'] === 'bu_signoff'): ?>
                    <span class="alpro-muted">Varies</span>
                <?php elseif ($ct['approver_mode'] === 'operations_tier'): ?>
                    <?php echo count($aap_pool_staff['operations']); ?>
                <?php else: ?>
                    <?php
                        $eligible_ids = array_column($aap_pool_staff['customer_support'], 'id');
                        $min_grade = ($ct['ops_tier_required'] === 'manager') ? 3 : (($ct['ops_tier_required'] === 'executive') ? 1 : 0);
                        foreach ($aap_pool_staff['operations'] as $ops_staff) {
                            if ($ops_staff['grade'] >= $min_grade) $eligible_ids[] = $ops_staff['id'];
                        }
                        echo count(array_unique($eligible_ids));
                    ?>
                <?php endif; ?>
            </td>
            <td><?php echo $ct['physical_confirm_required'] ? 'Required' : '—'; ?></td>
            <td><?php echo $ct['recycle'] ? '<span class="alpro-badge alpro-badge-voided">Retired</span>' : '<span class="alpro-badge alpro-badge-approved">Active</span>'; ?></td>
            <td>
                <a href="?edit_case_type=<?php echo $ct['id']; ?>" class="alpro-btn alpro-btn-blue" style="text-decoration:none; padding:4px 10px; font-size:12px;">Edit</a>
                <form method="post" style="display:inline;">
                    <input type="hidden" name="case_type_id" value="<?php echo $ct['id']; ?>">
                    <input class="alpro-btn alpro-btn-grey" style="padding:4px 10px; font-size:12px;" type="submit" name="toggle_case_type_recycle" value="<?php echo $ct['recycle'] ? 'Restore' : 'Retire'; ?>">
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
</div>
</div>

<script>
var AAP_ADMIN = {
    isNew: <?php echo $edit_case_type ? 'false' : 'true'; ?>,
    poolStaff: <?php echo json_encode($aap_pool_staff); ?>
};
</script>
<?php $page_js = '../js/aap_admin.js'; include('../aap_footer.php'); ?>
