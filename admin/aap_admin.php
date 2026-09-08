<?php
ob_start();
require_once('../../lock_adv.php');
$connect = 1;
date_default_timezone_set('Asia/Kuala_Lumpur');
include('../../common/index_adv.php');
if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection not available.");
}
require_once('../aap_lib.php');

// Opened up to every staff member (not admin-only any more) - each is
// scoped to managing Case Types for their OWN department(s) only, via
// aapCanManageAllDepartments()/aapDeptInScope() below. Grade >= 4,
// SuperAdmin, and Customer Support still manage every department, same as
// before. $aap_is_admin is kept only because aap_sidebar.php still uses it
// to decide whether to show the Settings/Approval Units/Staff Assignments
// nav links - it no longer gates this page itself.
$aap_dept_ids = aapDeptIdsFromCsv($department);
$aap_is_superadmin = aapFetchIsSuperAdmin($conn, $id_user);
$aap_is_admin = aapIsAdmin($grade, $aap_dept_ids, $aap_is_superadmin, aapFetchAapLevel($conn, $id_user));
$aap_can_manage_all_depts = aapCanManageAllDepartments($grade, $aap_dept_ids, $aap_is_superadmin);

// ---- Tier options for a picked Department - AJAX endpoint (JSON) feeding
// the Staff Tier picker's Tier dropdown with that department's own tiers
// (Approval Unit Master), or the shared Default list if it hasn't
// customized any. ----
if (isset($_GET['action']) && $_GET['action'] === 'get_tier_options' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    ob_end_clean();
    header('Content-Type: application/json');
    $dept_id = (int)($_GET['department_id'] ?? 0);
    if ($dept_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid department.']);
        exit;
    }
    echo json_encode(['success' => true, 'tiers' => aapFetchTierOptionsForDepartment($conn, $dept_id)]);
    exit;
}

// ---- Groups for a picked Department - AJAX endpoint (JSON) feeding the
// "Select Group" dropdown on the Level 2/3 Staff Tier picker, populated from
// that department's Groups (admin/aap_grouping_master.php). ----
if (isset($_GET['action']) && $_GET['action'] === 'get_dept_groups' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    ob_end_clean();
    header('Content-Type: application/json');
    $dept_id = (int)($_GET['department_id'] ?? 0);
    if ($dept_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid department.']);
        exit;
    }
    echo json_encode(['success' => true, 'groups' => aapFetchDepartmentGroupNames($conn, $dept_id)]);
    exit;
}

// ---- Staff + resolved Tier for a picked Group - AJAX endpoint (JSON) that
// the "Add" button uses to bulk-add every staff member in the Group into the
// Staff Tier table, each pre-filled at the tier they hold there. ----
if (isset($_GET['action']) && $_GET['action'] === 'get_group_staff_tiers' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    ob_end_clean();
    header('Content-Type: application/json');
    $dept_id = (int)($_GET['department_id'] ?? 0);
    // group_id 0 is the valid "Default (Universal)" sentinel for a
    // department with no Groups set up - only a missing/non-numeric param is
    // invalid, not 0 itself (unlike department_id, which is never 0).
    if ($dept_id <= 0 || !isset($_GET['group_id']) || !is_numeric($_GET['group_id'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid department or group.']);
        exit;
    }
    $group_id = (int)$_GET['group_id'];
    echo json_encode(['success' => true, 'staff' => aapFetchGroupStaffTiers($conn, $dept_id, $group_id)]);
    exit;
}

$msg = "";
$msg_type = "";
$now = date('Y-m-d H:i:s');

// A Case Type's Approval/Exclusion Staff Tier tables are serialized to JSON
// by js/aap_admin.js just before submit (see the form's submit listener) -
// each entry is {staff_id, department_id, group_id, tier}. Decoded here and
// replaced wholesale into aap_case_type_staff_tiers for that section -
// simpler and safer than diffing against the previous set row by row.
// group_id (from the Select Group picker) is stamped onto the row so the
// live approval gate (aapTierValueByName()/aapCanApprove() in aap_lib.php)
// can resolve the tier NAME against that exact Group instead of an
// ambiguous department-wide match - see sql/aap_master.sql's note on
// aap_case_type_staff_tiers. null (the "Default (Universal)" picker entry,
// or a legacy row) falls back to that lookup's old department/Universal
// chain.
function aapSaveCaseTypeStaffTiers($conn, $case_type_id, $section, $json, $actor_id, $now) {
    $conn->query("DELETE FROM aap_case_type_staff_tiers WHERE case_type_id = " . (int)$case_type_id . " AND section = '" . $conn->real_escape_string($section) . "'");
    $rows = json_decode($json ?? '', true);
    if (!is_array($rows)) return;
    $stmt = $conn->prepare("INSERT INTO aap_case_type_staff_tiers (case_type_id, section, department_id, group_id, staff_id, tier, created_by, timestamp) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    foreach ($rows as $r) {
        $staff_id = (int)($r['staff_id'] ?? 0);
        $dept_id = (int)($r['department_id'] ?? 0);
        $group_id = isset($r['group_id']) && $r['group_id'] !== null && $r['group_id'] !== '' ? (int)$r['group_id'] : null;
        $tier_name = trim($r['tier'] ?? '');
        // Real tier names are Approval Unit Master names, not a fixed
        // Unlimited/Tier 1/Tier 2 enum - validate against whichever tier
        // names that exact Group offers (or the department/Universal chain
        // when there's no Group).
        $tier = ($dept_id > 0 && $tier_name !== '' && aapTierValueByName($conn, $dept_id, $tier_name, $group_id)['found']) ? $tier_name : null;
        if ($staff_id <= 0 || $dept_id <= 0 || $tier === null) continue;
        $stmt->bind_param("isiiisis", $case_type_id, $section, $dept_id, $group_id, $staff_id, $tier, $actor_id, $now);
        $stmt->execute();
    }
    $stmt->close();
}

// ---- Case Type save (add or update) ----
if (isset($_POST['save_case_type'])) {
    $ctid = (int)$_POST['case_type_id'];
    $department_id = (int)$_POST['department_id_ct'];
    $name = trim($_POST['ct_name']);
    $physical_confirm_required = isset($_POST['physical_confirm_required']) ? 1 : 0;
    $description = trim($_POST['ct_description']);

    // A department-scoped (non-full-list) user must own BOTH the target
    // department and, on an update, the Case Type's existing department -
    // the second check stops them reassigning a Case Type that belongs to a
    // department outside their scope, not just picking one on creation.
    $dept_in_scope = aapDeptInScope($department_id, $aap_dept_ids, $aap_can_manage_all_depts);
    $existing_in_scope = true;
    if ($ctid > 0 && !$aap_can_manage_all_depts) {
        $existing_ct = aapFetchCaseType($conn, $ctid);
        $existing_in_scope = $existing_ct && aapDeptInScope($existing_ct['department_id'], $aap_dept_ids, $aap_can_manage_all_depts);
    }

    if ($name === '' || $department_id <= 0) {
        $msg = "Case Type name and department are required."; $msg_type = "alpro-danger";
    } elseif (!$dept_in_scope || !$existing_in_scope) {
        $msg = "You can only manage Case Types for your own department."; $msg_type = "alpro-danger";
    } else {
        if ($ctid > 0) {
            $stmt = $conn->prepare("UPDATE aap_case_types SET case_type_name=?, department_id=?, physical_confirm_required=?, description=? WHERE id=?");
            $stmt->bind_param("siisi", $name, $department_id, $physical_confirm_required, $description, $ctid);
            $stmt->execute();
            $stmt->close();
            aapSaveCaseTypeStaffTiers($conn, $ctid, 'approval', $_POST['approval_tiers_json'] ?? '', $id_user, $now);
            aapSaveCaseTypeStaffTiers($conn, $ctid, 'exclusion', $_POST['exclusion_tiers_json'] ?? '', $id_user, $now);
            $msg = "Case Type updated."; $msg_type = "alpro-success";
        } else {
            $stmt = $conn->prepare("INSERT INTO aap_case_types (case_type_name, department_id, physical_confirm_required, description, recycle, timestamp) VALUES (?, ?, ?, ?, 0, ?)");
            $stmt->bind_param("siiss", $name, $department_id, $physical_confirm_required, $description, $now);
            $stmt->execute();
            $new_ctid = $stmt->insert_id;
            $stmt->close();
            aapSaveCaseTypeStaffTiers($conn, $new_ctid, 'approval', $_POST['approval_tiers_json'] ?? '', $id_user, $now);
            aapSaveCaseTypeStaffTiers($conn, $new_ctid, 'exclusion', $_POST['exclusion_tiers_json'] ?? '', $id_user, $now);
            $msg = "Case Type created."; $msg_type = "alpro-success";
        }
    }
}

// ---- Case Type recycle toggle ----
if (isset($_POST['toggle_case_type_recycle'])) {
    $ctid = (int)$_POST['case_type_id'];
    $toggle_ct = aapFetchCaseType($conn, $ctid);
    if ($toggle_ct && aapDeptInScope($toggle_ct['department_id'], $aap_dept_ids, $aap_can_manage_all_depts)) {
        $conn->query("UPDATE aap_case_types SET recycle = 1 - recycle WHERE id = " . $ctid);
        $msg = "Case Type visibility updated."; $msg_type = "alpro-success";
    } else {
        $msg = "You can only manage Case Types for your own department."; $msg_type = "alpro-danger";
    }
}

$edit_case_type = null;
if (isset($_GET['edit_case_type'])) {
    $edit_case_type = aapFetchCaseType($conn, (int)$_GET['edit_case_type']);
    if ($edit_case_type && !aapDeptInScope($edit_case_type['department_id'], $aap_dept_ids, $aap_can_manage_all_depts)) {
        $edit_case_type = null;
        $msg = "You can only edit Case Types for your own department."; $msg_type = "alpro-danger";
    }
}

$case_types = aapFetchCaseTypes($conn, true);
$departments = aapFetchDepartments($conn);

// Level 1's Department picker only offers departments a scoped user
// actually manages - full-list roles (grade>=4/SuperAdmin/Customer Support)
// still see every department; everyone else sees only their own. Level 2/3
// (Staff Tier pickers) and the $dept_names lookup below stay on the
// unfiltered $departments - only who's ALLOWED TO OWN a Case Type is
// scoped, not who can be picked as its approver.
$level1_departments = $aap_can_manage_all_depts
    ? $departments
    : array_values(array_filter($departments, function ($d) use ($aap_dept_ids) {
        return in_array((int)$d['id'], $aap_dept_ids, true);
    }));

// The Case Type Registry table below only lists Case Types the current user
// actually manages, same scoping as everything else on this page.
if (!$aap_can_manage_all_depts) {
    $case_types = array_values(array_filter($case_types, function ($ct) use ($aap_dept_ids) {
        return in_array((int)$ct['department_id'], $aap_dept_ids, true);
    }));
}

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
        <!-- Serialized by js/aap_admin.js just before submit from the Level 2/3
             Staff Tier tables below - see aapSaveCaseTypeStaffTiers() in the
             save_case_type handler above. -->
        <input type="hidden" name="approval_tiers_json" id="ct_approval_tiers_json">
        <input type="hidden" name="exclusion_tiers_json" id="ct_exclusion_tiers_json">

        <!-- Level 1 -->
        <p class="aap-ct-level-title">Level 1 - Case Department Assign</p>
        <div class="aap-ct-level aap-ct-level-1">
            <div class="aap-ct-field">
                <label>Case Type Name <span style="color:red;">*</span></label>
                <input class="alpro-input" type="text" name="ct_name" id="ct_name" value="<?php echo htmlspecialchars($edit_case_type['case_type_name'] ?? ''); ?>" required>
            </div>
            <div class="aap-ct-field">
                <label>Department <span style="color:red;">*</span></label>
                <select class="alpro-input" name="department_id_ct" required>
                    <option value="">Select Department</option>
                    <?php foreach ($level1_departments as $dept): ?>
                        <option value="<?php echo $dept['id']; ?>" <?php echo (isset($edit_case_type['department_id']) && $edit_case_type['department_id'] == $dept['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($dept['depart_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="aap-ct-field">
                <label>Confirmation Required
                    <span class="aap-admin-info-icon">i<span class="aap-admin-tooltip">When checked, a case raised under this Case Type must have the returned item tagged and confirmed at ACMM before it can reach the Approval Gate. Leave unchecked for cases with no physical item to return (e.g. points/credit adjustments).</span></span>
                </label>
                <div class="aap-ct-field-inline"><input type="checkbox" name="physical_confirm_required" value="1" <?php echo !empty($edit_case_type['physical_confirm_required']) ? 'checked' : ''; ?>></div>
            </div>
        </div>

        <!-- Level 2 -->
        <p class="aap-ct-level-title">Level 2 - Approval Mode Assign</p>
        <div class="aap-ct-level aap-ct-level-2">
            <div class="aap-ct-field" style="grid-column: 1 / -1;">
                <label>Staff Tier</label>
                <div style="display:flex; gap:8px; align-items:center; margin-bottom:8px;">
                    <select class="alpro-input" name="department_id_ct_lvl2" id="ct_pool_department" style="flex:1;">
                        <option value="">Select Department</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?php echo $dept['id']; ?>"><?php echo htmlspecialchars($dept['depart_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select class="alpro-input" id="ct_pool_group" style="flex:1;">
                        <option value="">Select Group</option>
                    </select>
                    <button type="button" class="alpro-btn alpro-btn-blue" id="ct_tier_add" style="white-space:nowrap;">Add</button>
                </div>
                <p class="alpro-muted" style="font-size:12px; margin:0 0 8px;">Adding a Group adds every staff member in it, each at the tier they hold there (Approval Unit Master) - read-only here. To change who's in a Group or their tier, update it in the Approval Unit Master, then re-Add.</p>
                <div class="aap-modern">
                <table class="alpro-table aap-ct-list-table" id="ct_staff_tier_table" width="100%">
                    <thead><tr><th>Staff</th><th>Tier</th></tr></thead>
                    <tbody id="ct_staff_tier_tbody"></tbody>
                </table>
                </div>
            </div>
        </div>

        <!-- Level 3 -->
        <p class="aap-ct-level-title">Level 3 - Executed Mode Assign</p>
        <div class="aap-ct-level aap-ct-level-3">
            <div class="aap-ct-field" style="grid-column: 1 / -1;">
                <label>Staff Tier</label>
                <div style="display:flex; gap:8px; align-items:center; margin-bottom:8px;">
                    <select class="alpro-input" id="ct_pool_department_lvl3" style="flex:1;">
                        <option value="">Select Department</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?php echo $dept['id']; ?>"><?php echo htmlspecialchars($dept['depart_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select class="alpro-input" id="ct_pool_group_lvl3" style="flex:1;">
                        <option value="">Select Group</option>
                    </select>
                    <button type="button" class="alpro-btn alpro-btn-blue" id="ct_tier_add_lvl3" style="white-space:nowrap;">Add</button>
                </div>
                <p class="alpro-muted" style="font-size:12px; margin:0 0 8px;">Adding a Group adds every staff member in it, each at the tier they hold there (Approval Unit Master) - read-only here. To change who's in a Group or their tier, update it in the Approval Unit Master, then re-Add.</p>
                <div class="aap-modern">
                <table class="alpro-table aap-ct-list-table" id="ct_staff_tier_table_lvl3" width="100%">
                    <thead><tr><th>Staff</th><th>Tier</th></tr></thead>
                    <tbody id="ct_staff_tier_tbody_lvl3"></tbody>
                </table>
                </div>
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
    <h3 class="aap-card-title">Case Type Registry</h3>

    <style>
    /* Scoped to #ct_list_table only - tighter row height for this list, does
       not affect the Level 2/3 Staff Tier tables above which share the same
       .aap-ct-list-table class. */
    #ct_list_table th, #ct_list_table td { padding: 4px 10px !important; }
    #ct_list_table .alpro-badge { padding: 0 6px !important; font-size: 10px !important; }
    #ct_list_table .alpro-btn { padding: 2px 8px !important; font-size: 11px !important; }
    </style>

    <div id="ct_filter_bar" class="aap-filter-bar" style="display:grid; grid-template-columns:repeat(6, 1fr); padding-bottom:14px; border-bottom:1px solid #e5e9ec;">
        <input class="alpro-input aap-filter-input" type="text" id="ct_filter_name" placeholder="Search case type name...">
        <select class="alpro-input aap-filter-input" id="ct_filter_dept">
            <option value="">All Departments</option>
            <?php foreach ($departments as $dept): ?>
            <option value="<?php echo htmlspecialchars($dept['depart_name']); ?>"><?php echo htmlspecialchars($dept['depart_name']); ?></option>
            <?php endforeach; ?>
        </select>
        <input class="alpro-input aap-filter-input" type="text" id="ct_filter_approval" placeholder="Approval staff...">
        <input class="alpro-input aap-filter-input" type="text" id="ct_filter_exclusion" placeholder="Executed staff...">
        <select class="alpro-input aap-filter-input" id="ct_filter_physical">
            <option value="">Physical: All</option>
            <option value="1">Required</option>
            <option value="0">Not Required</option>
        </select>
        <select class="alpro-input aap-filter-input" id="ct_filter_status">
            <option value="">Status: All</option>
            <option value="active">Active</option>
            <option value="retired">Retired</option>
        </select>
    </div>

    <table class="alpro-table aap-ct-list-table" id="ct_list_table" width="100%">
        <thead><tr><th>ID</th><th>Case Type Name</th><th>Department</th><th>Approval Staff</th><th>Executed Staff</th><th>Physical</th><th>Status</th><th>Action</th></tr></thead>
        <tbody id="ct_list_tbody">
        <?php $dept_names = array_column($departments, 'depart_name', 'id'); ?>
        <?php foreach ($case_types as $ct): ?>
        <?php
            $approval_tiers = aapFetchCaseTypeStaffTiers($conn, $ct['id'], 'approval');
            $exclusion_tiers = aapFetchCaseTypeStaffTiers($conn, $ct['id'], 'exclusion');
            $dept_name = $dept_names[$ct['department_id']] ?? '';
            $approval_names = implode(', ', array_column($approval_tiers, 'staff_name'));
            $exclusion_names = implode(', ', array_column($exclusion_tiers, 'staff_name'));
        ?>
        <tr class="ct-list-row"
            data-name="<?php echo htmlspecialchars(strtolower($ct['case_type_name'])); ?>"
            data-dept="<?php echo htmlspecialchars($dept_name); ?>"
            data-approval="<?php echo htmlspecialchars(strtolower($approval_names)); ?>"
            data-exclusion="<?php echo htmlspecialchars(strtolower($exclusion_names)); ?>"
            data-physical="<?php echo $ct['physical_confirm_required'] ? '1' : '0'; ?>"
            data-status="<?php echo $ct['recycle'] ? 'retired' : 'active'; ?>">
            <td class="alpro-mono"><?php echo (int)$ct['id']; ?></td>
            <td><?php echo htmlspecialchars($ct['case_type_name']); ?></td>
            <td><?php echo htmlspecialchars($dept_name ?: '—'); ?></td>
            <td><?php echo count($approval_tiers) ?: '—'; ?></td>
            <td><?php echo count($exclusion_tiers) ?: '—'; ?></td>
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
        </tbody>
    </table>

    <div id="ct_pagination" class="aap-pagination-bar">
        <div class="aap-page-size-group">
            Show
            <select class="alpro-input aap-page-size-select" id="ct_page_size">
                <option value="10">10</option>
                <option value="25">25</option>
                <option value="30">30</option>
                <option value="50">50</option>
            </select>
            entries
        </div>
        <div class="aap-page-info">
            <span id="ct_page_showing" class="aap-page-showing"></span>
            <div id="ct_page_numbers" class="aap-page-numbers"></div>
        </div>
    </div>
</div>
</div>

<script>
var AAP_ADMIN = {
    isNew: <?php echo $edit_case_type ? 'false' : 'true'; ?>,
    approvalTiers: <?php echo json_encode($edit_case_type ? aapFetchCaseTypeStaffTiers($conn, $edit_case_type['id'], 'approval') : []); ?>,
    exclusionTiers: <?php echo json_encode($edit_case_type ? aapFetchCaseTypeStaffTiers($conn, $edit_case_type['id'], 'exclusion') : []); ?>
};
</script>
<?php $page_js = '../js/aap_admin.js'; include('../aap_footer.php'); ?>