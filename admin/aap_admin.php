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
// Resolves the SuperAdmin/admin-level union used across every AAP page.
$aap_identity = aapResolveIdentity($conn, $id_user, $grade, $department);
$grade = $aap_identity['grade'];
$department = $aap_identity['department'];
$aap_dept_ids = $aap_identity['dept_ids'];
$aap_is_admin = $aap_identity['is_admin'];
$aap_is_superadmin = $aap_identity['is_superadmin'];
$aap_can_manage_all_depts = aapCanManageAllDepartments($grade, $aap_dept_ids, $aap_is_superadmin);

// aap_department_managers - an additional, grade-independent allowlist (see
// aapFetchDeptManagerDepartmentIds() in aap_lib.php) letting specific staff
// manage specific departments' Case Types here without needing
// staff.department membership or a qualifying grade. Merged in AFTER
// $aap_can_manage_all_depts is decided above (that's based on the real
// grade/department signals only) so a grant here - say, for Customer
// Support (dept 27) - never accidentally trips aapCanManageAllDepartments()'s
// own Customer-Support-triggers-manage-all path.
$aap_manager_dept_ids = aapFetchDeptManagerDepartmentIds($conn, $id_user);
if (!empty($aap_manager_dept_ids)) {
    $aap_dept_ids = array_values(array_unique(array_merge($aap_dept_ids, $aap_manager_dept_ids)));
}

// PHP's default session handler locks the session file for the whole
// request - this page is hit repeatedly via AJAX below and never writes to
// $_SESSION itself, so releasing the lock here lets those requests (and
// everything else sharing this browser's session) run concurrently instead
// of queuing up behind whichever one happens to be mid-request.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

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
// that department's Groups (admin/aap_grouping_master.php). Deliberately
// restricted server-side (not just hidden in the form) - a full-scope admin
// gets every Group for any department, but a department-scoped user only
// gets the real list for their OWN department(s); for any other department
// (e.g. a Level 1 owner assigning Level 2/3 to a department they don't
// belong to) they get back only the Default (Universal) entry, same as if
// that department had no custom Groups at all - see
// aapCaseTypeAllowedGroupForAssignment() in aap_lib.php, which the
// save_case_type handler enforces again independently either way. ----
if (isset($_GET['action']) && $_GET['action'] === 'get_dept_groups' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    ob_end_clean();
    header('Content-Type: application/json');
    $dept_id = (int)($_GET['department_id'] ?? 0);
    if ($dept_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid department.']);
        exit;
    }
    $groups = aapCaseTypeAllowedGroupForAssignment($dept_id, $aap_dept_ids, $aap_can_manage_all_depts)
        ? aapFetchDepartmentGroupNames($conn, $dept_id)
        : [['id' => 0, 'group_name' => 'Default (Universal)', 'description' => '']];
    echo json_encode(['success' => true, 'groups' => $groups]);
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
    // Same restriction as get_dept_groups above, re-checked independently
    // here since this is a separate request a crafted call could hit
    // directly with an arbitrary group_id - a department-scoped user
    // requesting a specific Group they're not entitled to always falls back
    // to that department's Default (Universal) roster instead.
    if (!aapCaseTypeAllowedGroupForAssignment($dept_id, $aap_dept_ids, $aap_can_manage_all_depts)) {
        $group_id = 0;
    }
    echo json_encode(['success' => true, 'staff' => aapFetchGroupStaffTiers($conn, $dept_id, $group_id)]);
    exit;
}

// "Group 1: description" label for a Group id, or "Default (Universal)" for
// null/0 (no custom Group assigned) - used by the Case Type Registry list
// below to show which Group a section actually resolves to, not just how
// many staff rows it has.
function aapGroupLabel($conn, $group_id) {
    if (empty($group_id)) return 'Default (Universal)';
    $stmt = $conn->prepare("SELECT group_name, description FROM aap_approval_unit_tier_groups WHERE id = ?");
    $stmt->bind_param("i", $group_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return 'Default (Universal)';
    return $row['description'] !== '' ? ($row['group_name'] . ': ' . $row['description']) : $row['group_name'];
}

// "Department (Group label)" for a section's first row - all rows in one
// section always share the same department_id/group_id (only one Group per
// level), so the first row stands in for the whole section. '—' when the
// section has nobody assigned yet.
function aapCaseTypeSectionLabel($conn, $tiers, $dept_names) {
    if (empty($tiers)) return '—';
    $dept_name = $dept_names[$tiers[0]['department_id']] ?? '—';
    return $dept_name . ' (' . aapGroupLabel($conn, $tiers[0]['group_id']) . ')';
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

// Authorizes + saves one Case Type's Level 2 ('approval') or Level 3
// ('exclusion') section against aapCaseTypeEditRights()'s rules - never
// trusts the client's disabled/hidden form fields alone, since a submission
// can be forged regardless of what the rendered form allowed.
//
// $existing_dept/$can_reassign/$can_edit_group come straight from
// aapCaseTypeEditRights() (aap_lib.php). Reassigning the section to a
// different department (or assigning it for the first time) requires
// $can_reassign; updating the roster within whichever department already
// holds it requires $can_edit_group instead. Not entitled at all -> the
// section is silently left untouched (correct for e.g. a Level-2-only
// editor whose own submission always carries Level 3's existing, locked-in-
// the-UI rows unchanged).
//
// Returns an error message to show the user, or null on success (including
// "not entitled, nothing to do").
function aapSaveCaseTypeStaffTierSection($conn, $case_type_id, $section, $json, $existing_dept, $can_reassign, $can_edit_group, $dept_ids, $can_manage_all, $actor_id, $now, $level_label) {
    $submitted_rows = json_decode($json ?? '', true);
    $submitted_rows = is_array($submitted_rows) ? $submitted_rows : [];
    $submitted_dept = !empty($submitted_rows) ? (int)($submitted_rows[0]['department_id'] ?? 0) : null;

    $is_reassignment_or_initial = ($existing_dept === null) || ($submitted_dept !== null && $submitted_dept !== $existing_dept);
    $allowed = $is_reassignment_or_initial ? $can_reassign : $can_edit_group;
    if (!$allowed) return null;

    if (empty($submitted_rows)) {
        return "At least one Group must be assigned under $level_label.";
    }

    if ($is_reassignment_or_initial && !aapCaseTypeAllowedGroupForAssignment($submitted_dept, $dept_ids, $can_manage_all)) {
        // Handing this level to a department the assigner doesn't belong to
        // - force that department's Default (Universal) roster instead of
        // trusting whatever specific Group/staff was posted.
        $forced_json = json_encode(aapDefaultUniversalTierRows($conn, $submitted_dept));
        aapSaveCaseTypeStaffTiers($conn, $case_type_id, $section, $forced_json, $actor_id, $now);
    } else {
        aapSaveCaseTypeStaffTiers($conn, $case_type_id, $section, $json, $actor_id, $now);
    }
    return null;
}

// ---- Case Type save (add or update) ----
if (isset($_POST['save_case_type'])) {
    $ctid = (int)$_POST['case_type_id'];
    $department_id = (int)$_POST['department_id_ct'];
    $name = trim($_POST['ct_name']);
    $physical_confirm_required = isset($_POST['physical_confirm_required']) ? 1 : 0;
    $description = trim($_POST['ct_description']);

    $existing_ct = $ctid > 0 ? aapFetchCaseType($conn, $ctid) : null;
    if ($ctid > 0 && !$existing_ct) {
        $msg = "Case Type not found."; $msg_type = "alpro-danger";
    } else {
        // Rights are computed against the Case Type's EXISTING department on
        // an update (aapCaseTypeEditRights() below) - can_edit_level1 must
        // not hinge on whatever department the submitter tried to move it
        // to. For a brand new Case Type there's no existing department yet,
        // so the newly-picked one is what's checked instead.
        $rights_ct_department = $existing_ct ? $existing_ct['department_id'] : $department_id;
        $rights = aapCaseTypeEditRights($conn, $ctid, $rights_ct_department, $aap_dept_ids, $aap_can_manage_all_depts);
        $any_right = $rights['can_edit_level1'] || $rights['can_reassign_level2'] || $rights['can_edit_level2_group']
                   || $rights['can_reassign_level3'] || $rights['can_edit_level3_group'];

        if (!$any_right) {
            $msg = "You don't have permission to manage this Case Type."; $msg_type = "alpro-danger";
        } elseif ($rights['can_edit_level1'] && ($name === '' || $department_id <= 0)) {
            $msg = "Case Type name and department are required."; $msg_type = "alpro-danger";
        } elseif ($rights['can_edit_level1'] && $description === '') {
            $msg = "Description is required."; $msg_type = "alpro-danger";
        } else {
            // Level 1 core fields - only touched if actually entitled; a
            // Level 2/3-only editor's submission leaves these columns
            // exactly as they already were.
            if ($ctid > 0) {
                if ($rights['can_edit_level1']) {
                    $stmt = $conn->prepare("UPDATE aap_case_types SET case_type_name=?, department_id=?, physical_confirm_required=?, description=? WHERE id=?");
                    $stmt->bind_param("siisi", $name, $department_id, $physical_confirm_required, $description, $ctid);
                    $stmt->execute();
                    $stmt->close();
                }
                $target_ctid = $ctid;
            } else {
                // A brand new Case Type always requires Level 1 rights
                // (enforced by the required-field checks above, which only
                // fire when can_edit_level1 is true) - aapCaseTypeEditRights()
                // ties every right to can_edit_level1 when nothing exists
                // yet, so this branch is unreachable without it.
                $stmt = $conn->prepare("INSERT INTO aap_case_types (case_type_name, department_id, physical_confirm_required, description, recycle, timestamp) VALUES (?, ?, ?, ?, 0, ?)");
                $stmt->bind_param("siiss", $name, $department_id, $physical_confirm_required, $description, $now);
                $stmt->execute();
                $target_ctid = $stmt->insert_id;
                $stmt->close();
            }

            $err = aapSaveCaseTypeStaffTierSection(
                $conn, $target_ctid, 'approval', $_POST['approval_tiers_json'] ?? '',
                $rights['level2_dept'], $rights['can_reassign_level2'], $rights['can_edit_level2_group'],
                $aap_dept_ids, $aap_can_manage_all_depts, $id_user, $now, 'Level 2 - Approval Mode Assign'
            );
            if ($err === null) {
                $err = aapSaveCaseTypeStaffTierSection(
                    $conn, $target_ctid, 'exclusion', $_POST['exclusion_tiers_json'] ?? '',
                    $rights['level3_dept'], $rights['can_reassign_level3'], $rights['can_edit_level3_group'],
                    $aap_dept_ids, $aap_can_manage_all_depts, $id_user, $now, 'Level 3 - Approval Exclusion Assign'
                );
            }

            if ($err !== null) {
                $msg = $err; $msg_type = "alpro-danger";
            } else {
                $msg = ($ctid > 0) ? "Case Type updated." : "Case Type created."; $msg_type = "alpro-success";
            }
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

// $edit_rights covers every section independently (aapCaseTypeEditRights() -
// aap_lib.php) - opening the edit form no longer requires owning Level 1;
// holding Level 2 or Level 3 alone is enough to get in, just with whichever
// sections aren't yours locked/read-only in the form below.
$edit_case_type = null;
$edit_rights = null;
if (isset($_GET['edit_case_type'])) {
    $edit_case_type = aapFetchCaseType($conn, (int)$_GET['edit_case_type']);
    if ($edit_case_type) {
        $edit_rights = aapCaseTypeEditRights($conn, $edit_case_type['id'], $edit_case_type['department_id'], $aap_dept_ids, $aap_can_manage_all_depts);
        $any_right = $edit_rights['can_edit_level1'] || $edit_rights['can_reassign_level2'] || $edit_rights['can_edit_level2_group']
                   || $edit_rights['can_reassign_level3'] || $edit_rights['can_edit_level3_group'];
        if (!$any_right) {
            $edit_case_type = null;
            $edit_rights = null;
            $msg = "You don't have permission to manage this Case Type."; $msg_type = "alpro-danger";
        }
    }
}
// No $edit_case_type (either no ?edit_case_type param, or access was denied
// above) means the form below renders in "create new" mode - full rights
// over everything, since whichever department the creator picks for Level 1
// is by definition their own (aapDeptInScope() at save time enforces this
// for real - this default is only ever used to decide what the form SHOWS).
if ($edit_rights === null) {
    $edit_rights = [
        'can_edit_level1' => true,
        'can_reassign_level2' => true,
        'can_reassign_level3' => true,
        'can_edit_level2_group' => true,
        'can_edit_level3_group' => true,
        'level2_dept' => null,
        'level3_dept' => null,
    ];
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

// A department-scoped user who actually OWNS Level 1 here (creating a new
// Case Type, or editing one of their own) and has only ONE department they
// could ever pick has nothing to actually choose - show it as a plain fixed
// value instead of a "Select Department" + one real option dropdown.
// Someone managing more than one department still gets the dropdown, since
// there's a real choice to make there. Deliberately excludes an editor who
// DOESN'T own Level 1 (Level 2/3-only access) - their case is handled
// separately below, showing the Case Type's actual department (which may be
// outside this editor's own scope entirely, e.g. Academy while they only
// manage Finance) rather than substituting their own.
$level1_dept_locked = $edit_rights['can_edit_level1'] && !$aap_can_manage_all_depts && count($level1_departments) === 1;
$dept_names_all = array_column($departments, 'depart_name', 'id');

// The Case Type Registry table below lists every Case Type the current user
// is actually involved in - not just the ones they own at Level 1, but also
// any where their own department currently holds Level 2 (Approval) or
// Level 3 (Exclusion), since that department's own staff need to see (and,
// per aapCaseTypeEditRights() below, edit their own roster within) it too.
if (!$aap_can_manage_all_depts) {
    $case_types = array_values(array_filter($case_types, function ($ct) use ($conn, $aap_dept_ids) {
        if (in_array((int)$ct['department_id'], $aap_dept_ids, true)) return true;
        $level2_dept = aapCaseTypeSectionDepartment($conn, $ct['id'], 'approval');
        if ($level2_dept !== null && in_array($level2_dept, $aap_dept_ids, true)) return true;
        $level3_dept = aapCaseTypeSectionDepartment($conn, $ct['id'], 'exclusion');
        if ($level3_dept !== null && in_array($level3_dept, $aap_dept_ids, true)) return true;
        return false;
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

        <!-- Level 1 - locked read-only (with a hidden field carrying the
        existing value through submission) unless $edit_rights['can_edit_level1']
        - a Level 2/3-only editor (holds a level, but doesn't own Level 1)
        can open this form but must not be able to touch these fields; the
        save handler re-checks this server-side regardless. -->
        <p class="aap-ct-level-title">Level 1 - Case Department Assign<?php if (!$edit_rights['can_edit_level1']): ?> <span class="aap-admin-info-icon">i<span class="aap-admin-tooltip">Only the department that owns this Case Type can change these fields.</span></span><?php endif; ?></p>
        <div class="aap-ct-level aap-ct-level-1">
            <div class="aap-ct-field">
                <label>Case Type Name <span style="color:red;">*</span></label>
                <input class="alpro-input" type="text" name="ct_name" id="ct_name" value="<?php echo htmlspecialchars($edit_case_type['case_type_name'] ?? ''); ?>" required <?php echo $edit_rights['can_edit_level1'] ? '' : 'disabled'; ?>>
                <?php if (!$edit_rights['can_edit_level1']): ?><input type="hidden" name="ct_name" value="<?php echo htmlspecialchars($edit_case_type['case_type_name'] ?? ''); ?>"><?php endif; ?>
            </div>
            <div class="aap-ct-field">
                <label>Department <span style="color:red;">*</span></label>
                <?php if ($level1_dept_locked): ?>
                    <!-- Actor owns Level 1 and has only one department to ever
                    pick anyway (new Case Type, or editing their own) - no
                    real choice to make, so just show it. -->
                    <div class="alpro-input" style="background:#f1f3f5; display:flex; align-items:center;"><?php echo htmlspecialchars($level1_departments[0]['depart_name']); ?></div>
                    <input type="hidden" name="department_id_ct" value="<?php echo (int)$level1_departments[0]['id']; ?>">
                <?php elseif (!$edit_rights['can_edit_level1']): ?>
                    <!-- Editing via Level 2/3-only access - show the Case
                    Type's ACTUAL department (looked up against the full,
                    unfiltered department list, since it may be outside this
                    editor's own scope entirely), not a disabled dropdown
                    whose options are filtered to the editor's own
                    department(s) and so would never actually contain it. -->
                    <div class="alpro-input" style="background:#f1f3f5; display:flex; align-items:center;"><?php echo htmlspecialchars($dept_names_all[$edit_case_type['department_id']] ?? '—'); ?></div>
                    <input type="hidden" name="department_id_ct" value="<?php echo (int)($edit_case_type['department_id'] ?? 0); ?>">
                <?php else: ?>
                    <select class="alpro-input" name="department_id_ct" required>
                        <option value="">Select Department</option>
                        <?php foreach ($level1_departments as $dept): ?>
                            <option value="<?php echo $dept['id']; ?>" <?php echo (isset($edit_case_type['department_id']) && $edit_case_type['department_id'] == $dept['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($dept['depart_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
            </div>
            <div class="aap-ct-field">
                <label>Verification Required
                    <span class="aap-admin-info-icon">i<span class="aap-admin-tooltip">When checked, a case raised under this Case Type must have the returned item tagged and confirmed at ACMM before it can reach the approval gate. Leave unchecked for cases with no physical item to return (e.g. points/credit adjustments).</span></span>
                </label>
                <div class="aap-ct-field-inline">
                    <input type="checkbox" name="physical_confirm_required" value="1" <?php echo !empty($edit_case_type['physical_confirm_required']) ? 'checked' : ''; ?> <?php echo $edit_rights['can_edit_level1'] ? '' : 'disabled'; ?>>
                    <?php if (!$edit_rights['can_edit_level1'] && !empty($edit_case_type['physical_confirm_required'])): ?><input type="hidden" name="physical_confirm_required" value="1"><?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Level 2 - the Department dropdown is locked to whichever
        department currently holds this level unless
        $edit_rights['can_reassign_level2'] (Level 1 owner/admin only, see
        aapCaseTypeEditRights() in aap_lib.php); the Group dropdown itself
        stays open whenever $edit_rights['can_edit_level2_group'] - the
        get_dept_groups AJAX endpoint below only ever offers a full Group
        list for a department the requester actually belongs to, silently
        falling back to Default (Universal) for any other, so a Level 1
        owner handing this level to a department they're not part of can
        never hand-pick that department's specific Group. -->
        <p class="aap-ct-level-title">Level 2 - Approval Mode Assign<?php if (!$edit_rights['can_reassign_level2'] && !$edit_rights['can_edit_level2_group']): ?> <span class="aap-admin-info-icon">i<span class="aap-admin-tooltip">Only the department holding Level 2 can change this.</span></span><?php endif; ?></p>
        <div class="aap-ct-level aap-ct-level-2">
            <div class="aap-ct-field" style="grid-column: 1 / -1;">
                <label>Staff Tier <span style="color:red;">*</span></label>
                <div style="display:flex; gap:8px; align-items:center; margin-bottom:8px;">
                    <select class="alpro-input" name="department_id_ct_lvl2" id="ct_pool_department" style="flex:1;" <?php echo $edit_rights['can_reassign_level2'] ? '' : 'disabled'; ?>>
                        <option value="">Select Department</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?php echo $dept['id']; ?>" <?php echo ($edit_rights['level2_dept'] !== null && $edit_rights['level2_dept'] == $dept['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($dept['depart_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select class="alpro-input" id="ct_pool_group" style="flex:1;" <?php echo $edit_rights['can_edit_level2_group'] ? '' : 'disabled'; ?>>
                        <option value="">Select Group</option>
                    </select>
                </div>
                <p class="alpro-muted" style="font-size:12px; margin:0 0 8px;">Picking a Group fills the table below with every staff member in it, each at the tier they hold there (Approval Unit Master) - read-only here. Only one Group per level - picking a different one replaces the table. To change who's in a Group or their tier, update it in the Approval Unit Master, then re-pick.</p>
                <div class="aap-modern">
                <table class="alpro-table aap-ct-list-table" id="ct_staff_tier_table" width="100%">
                    <thead><tr><th>Staff</th><th>Tier</th></tr></thead>
                    <tbody id="ct_staff_tier_tbody"></tbody>
                </table>
                </div>
            </div>
        </div>

        <!-- Level 3 - despite living in aap_case_type_staff_tiers as
        section='exclusion', this has nothing to do with who can execute a
        case (aapCanExecute() never reads it) - it's an approval-exclusion
        override: anyone on this list is blocked from approving this Case
        Type regardless of their Level 2 tier (see aapCanApprove() in
        aap_lib.php). Labelled accordingly, not "Executed Mode". -->
        <p class="aap-ct-level-title">Level 3 - Approval Exclusion Assign<?php if (!$edit_rights['can_reassign_level3'] && !$edit_rights['can_edit_level3_group']): ?> <span class="aap-admin-info-icon">i<span class="aap-admin-tooltip">Only the department holding Level 3 can change this.</span></span><?php endif; ?></p>
        <div class="aap-ct-level aap-ct-level-3">
            <div class="aap-ct-field" style="grid-column: 1 / -1;">
                <label>Staff Tier <span style="color:red;">*</span></label>
                <div style="display:flex; gap:8px; align-items:center; margin-bottom:8px;">
                    <select class="alpro-input" id="ct_pool_department_lvl3" style="flex:1;" <?php echo $edit_rights['can_reassign_level3'] ? '' : 'disabled'; ?>>
                        <option value="">Select Department</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?php echo $dept['id']; ?>" <?php echo ($edit_rights['level3_dept'] !== null && $edit_rights['level3_dept'] == $dept['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($dept['depart_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select class="alpro-input" id="ct_pool_group_lvl3" style="flex:1;" <?php echo $edit_rights['can_edit_level3_group'] ? '' : 'disabled'; ?>>
                        <option value="">Select Group</option>
                    </select>
                </div>
                <p class="alpro-muted" style="font-size:12px; margin:0 0 8px;">Picking a Group fills the table below with every staff member in it, each at the tier they hold there (Approval Unit Master) - read-only here. Only one Group per level - picking a different one replaces the table. To change who's in a Group or their tier, update it in the Approval Unit Master, then re-pick.</p>
                <div class="aap-modern">
                <table class="alpro-table aap-ct-list-table" id="ct_staff_tier_table_lvl3" width="100%">
                    <thead><tr><th>Staff</th><th>Tier</th></tr></thead>
                    <tbody id="ct_staff_tier_tbody_lvl3"></tbody>
                </table>
                </div>
            </div>
            <div class="aap-ct-field" style="grid-column: 1 / -1;">
                <label>Description <span style="color:red;">*</span></label>
                <input class="alpro-input" type="text" name="ct_description" value="<?php echo htmlspecialchars($edit_case_type['description'] ?? ''); ?>" required <?php echo $edit_rights['can_edit_level1'] ? '' : 'disabled'; ?>>
                <?php if (!$edit_rights['can_edit_level1']): ?><input type="hidden" name="ct_description" value="<?php echo htmlspecialchars($edit_case_type['description'] ?? ''); ?>"><?php endif; ?>
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
        <!-- Shown for every role now, not just full-list admins - a
             department-scoped user can still be in scope for more than one
             department ($aap_dept_ids), so this isn't always a single-value
             no-op the way the old gate assumed. -->
        <select class="alpro-input aap-filter-input" id="ct_filter_dept">
            <option value="">All Departments</option>
            <?php foreach ($level1_departments as $dept): ?>
            <option value="<?php echo htmlspecialchars($dept['depart_name']); ?>"><?php echo htmlspecialchars($dept['depart_name']); ?></option>
            <?php endforeach; ?>
        </select>
        <input class="alpro-input aap-filter-input" type="text" id="ct_filter_approval" placeholder="Approval staff...">
        <input class="alpro-input aap-filter-input" type="text" id="ct_filter_exclusion" placeholder="Excluded staff...">
        <select class="alpro-input aap-filter-input" id="ct_filter_physical">
            <option value="">Physical: All</option>
            <option value="1">Required</option>
            <option value="0">Not Required</option>
        </select>
        <select class="alpro-input aap-filter-input" id="ct_filter_status">
            <option value="">Status: All</option>
            <option value="active">Active</option>
            <option value="retired">Inactive</option>
        </select>
    </div>

    <table class="alpro-table aap-ct-list-table" id="ct_list_table" width="100%">
        <thead><tr><th>ID</th><th>Case Type Name</th><th>Department</th><th>Approval Staff</th><th>Excluded Staff</th><th>Physical</th><th>Status</th><th>Action</th></tr></thead>
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
            <td><?php echo htmlspecialchars(aapCaseTypeSectionLabel($conn, $approval_tiers, $dept_names)); ?></td>
            <td><?php echo htmlspecialchars(aapCaseTypeSectionLabel($conn, $exclusion_tiers, $dept_names)); ?></td>
            <td><?php echo $ct['physical_confirm_required'] ? 'Required' : '—'; ?></td>
            <td><?php echo $ct['recycle'] ? '<span class="alpro-badge alpro-badge-voided">Inactive</span>' : '<span class="alpro-badge alpro-badge-approved">Active</span>'; ?></td>
            <td>
                <a href="?edit_case_type=<?php echo $ct['id']; ?>" class="alpro-btn alpro-btn-blue" style="text-decoration:none; padding:4px 10px; font-size:12px;">Edit</a>
                <?php if (aapDeptInScope($ct['department_id'], $aap_dept_ids, $aap_can_manage_all_depts)): ?>
                <form method="post" style="display:inline;">
                    <input type="hidden" name="case_type_id" value="<?php echo $ct['id']; ?>">
                    <input class="alpro-btn alpro-btn-grey" style="padding:4px 10px; font-size:12px;" type="submit" name="toggle_case_type_recycle" value="<?php echo $ct['recycle'] ? 'Restore' : 'Inactive'; ?>">
                </form>
                <?php endif; ?>
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