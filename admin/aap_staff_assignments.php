<?php
// Buffers everything so the AJAX action branches below can discard the HTML
// lock_adv.php echoes before its redirect check runs and emit clean JSON
// instead - same trick used in aap_settings.php.
ob_start();
require_once('../../lock_adv.php');
$connect = 1;
date_default_timezone_set('Asia/Kuala_Lumpur');
include('../../common/index_adv.php');
if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection not available.");
}
require_once('../aap_lib.php');

// An AAP admin (staff.aap) can search/reassign ANY staff member's Case Type
// Staff Tier assignments across every department - more than the
// department-scoped access a general aapIsAdmin() gets everywhere else in
// this module. A Department Manager (aap_department_managers, see
// aapFetchDeptManagerDepartmentIds() in aap_lib.php) can also get in here
// now, but every endpoint below scopes what they see/touch down to just
// their own granted department(s)' assignment rows.
$aap_identity = aapResolveIdentity($conn, $id_user, $grade, $department);
$grade = $aap_identity['grade'];
$department = $aap_identity['department'];
$aap_dept_ids = $aap_identity['dept_ids'];
$aap_is_admin = $aap_identity['is_admin'];
$aap_is_superadmin = $aap_identity['is_superadmin'];
$aap_level = $aap_identity['aap_level'];
$aap_can_manage_all_depts = aapCanManageAllDepartments($grade, $aap_dept_ids, $aap_is_superadmin);

// Merged in AFTER $aap_can_manage_all_depts is decided, same reasoning as
// admin/aap_admin.php/aap_grouping_master.php - a grant here must never
// accidentally read as "manage every department".
$aap_manager_dept_ids = aapFetchDeptManagerDepartmentIds($conn, $id_user);
if (!empty($aap_manager_dept_ids)) {
    $aap_dept_ids = array_values(array_unique(array_merge($aap_dept_ids, $aap_manager_dept_ids)));
}

if (!$aap_is_superadmin && empty($aap_manager_dept_ids)) {
    die("You don't have access to this page. Ask an admin to grant you SuperAdmin access, or Department Manager access for a specific department.");
}

// PHP's default session handler locks the session file for the whole
// request - this page is hit repeatedly via AJAX below and never writes to
// $_SESSION itself, so releasing the lock here lets those requests (and
// everything else sharing this browser's session) run concurrently instead
// of queuing up behind whichever one happens to be mid-request.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

// ---- Search staff by name - AJAX endpoint (JSON). Deliberately does NOT
// filter out resigned staff (recycle = 1) - the whole point of this page is
// finding a resigned staff member's leftover assignments, so they need to be
// searchable here even though they're hidden from the picker elsewhere
// (admin/aap_admin.php's Staff Tier picker only shows active staff). ----
if (isset($_GET['action']) && $_GET['action'] === 'search_staff' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    ob_end_clean();
    header('Content-Type: application/json');
    $q = trim($_GET['q'] ?? '');
    if ($q === '') {
        echo json_encode(['success' => true, 'staff' => []]);
        exit;
    }
    $stmt = $conn->prepare("
        SELECT s.id, s.nama_staff, s.recycle, sd.depart_name
        FROM staff s
        LEFT JOIN staff_department sd ON sd.id = s.department
        WHERE s.nama_staff LIKE CONCAT('%', ?, '%')
        ORDER BY s.recycle ASC, s.nama_staff ASC
        LIMIT 20
    ");
    $stmt->bind_param("s", $q);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $staff = array_map(function ($r) {
        return [
            'id' => (int)$r['id'],
            'nama_staff' => $r['nama_staff'],
            'department_name' => $r['depart_name'] ?: '—',
            'resigned' => (int)$r['recycle'] === 1,
        ];
    }, $rows);
    echo json_encode(['success' => true, 'staff' => $staff]);
    exit;
}

// ---- Every Case Type Staff Tier row a staff member holds (Approval and
// Exclusion, across every Case Type/department) - AJAX endpoint (JSON). ----
if (isset($_GET['action']) && $_GET['action'] === 'get_assignments' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    ob_end_clean();
    header('Content-Type: application/json');
    $staff_id = (int)($_GET['staff_id'] ?? 0);
    if ($staff_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid staff.']);
        exit;
    }
    $staff_stmt = $conn->prepare("
        SELECT s.id, s.nama_staff, s.recycle, sd.depart_name
        FROM staff s
        LEFT JOIN staff_department sd ON sd.id = s.department
        WHERE s.id = ?
    ");
    $staff_stmt->bind_param("i", $staff_id);
    $staff_stmt->execute();
    $staff_row = $staff_stmt->get_result()->fetch_assoc();
    $staff_stmt->close();
    if (!$staff_row) {
        echo json_encode(['success' => false, 'message' => 'Staff not found.']);
        exit;
    }

    // A Department Manager only ever sees this staff member's assignment
    // rows whose OWN department_id (which department the tier row's Group
    // belongs to) is one they manage - not every department the staff
    // member happens to hold a slot in.
    $dept_scope_sql = '';
    if (!$aap_can_manage_all_depts) {
        $dept_scope_sql = empty($aap_dept_ids)
            ? ' AND 1=0'
            : ' AND t.department_id IN (' . implode(',', array_map('intval', $aap_dept_ids)) . ')';
    }
    $stmt = $conn->prepare("
        SELECT t.id, t.section, t.tier, t.department_id,
               ct.case_type_name, ct.recycle AS case_type_recycle,
               sd.depart_name AS assignment_department_name
        FROM aap_case_type_staff_tiers t
        INNER JOIN aap_case_types ct ON ct.id = t.case_type_id
        LEFT JOIN staff_department sd ON sd.id = t.department_id
        WHERE t.staff_id = ? $dept_scope_sql
        ORDER BY ct.case_type_name ASC, t.section ASC
    ");
    $stmt->bind_param("i", $staff_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $assignments = array_map(function ($r) {
        return [
            'id' => (int)$r['id'],
            'section' => $r['section'],
            'tier' => $r['tier'],
            'case_type_name' => $r['case_type_name'],
            'case_type_retired' => (int)$r['case_type_recycle'] === 1,
            'department_name' => $r['assignment_department_name'] ?: '—',
        ];
    }, $rows);

    echo json_encode([
        'success' => true,
        'staff' => [
            'id' => (int)$staff_row['id'],
            'nama_staff' => $staff_row['nama_staff'],
            'department_name' => $staff_row['depart_name'] ?: '—',
            'resigned' => (int)$staff_row['recycle'] === 1,
        ],
        'assignments' => $assignments,
    ]);
    exit;
}

// ---- Remove one assignment row - AJAX endpoint (JSON). ----
if (isset($_POST['action']) && $_POST['action'] === 'remove_assignment' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_end_clean();
    header('Content-Type: application/json');
    $row_id = (int)($_POST['id'] ?? 0);
    if ($row_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid assignment.']);
        exit;
    }
    $row = $conn->query("SELECT staff_id, case_type_id, section, department_id FROM aap_case_type_staff_tiers WHERE id = " . $row_id)->fetch_assoc();
    if (!$row) {
        echo json_encode(['success' => false, 'message' => 'Assignment not found.']);
        exit;
    }
    if (!aapDeptInScope($row['department_id'], $aap_dept_ids, $aap_can_manage_all_depts)) {
        echo json_encode(['success' => false, 'message' => 'You do not manage this department.']);
        exit;
    }
    $ct_name = $conn->query("SELECT case_type_name FROM aap_case_types WHERE id = " . (int)$row['case_type_id'])->fetch_assoc()['case_type_name'] ?? 'Case Type #' . $row['case_type_id'];
    $conn->query("DELETE FROM aap_case_type_staff_tiers WHERE id = " . $row_id);
    aapLogAdminAudit($conn, 'staff_assignments', 'assignment_removed', $id_user, (int)$row['staff_id'], "Removed {$row['section']} assignment on \"$ct_name\"");
    echo json_encode(['success' => true, 'message' => 'Removed.']);
    exit;
}

// ---- Reassign one assignment row to a different staff member - AJAX
// endpoint (JSON). Keeps the row's department_id/tier as-is (the slot's
// scope doesn't change, just who fills it); only the staff_id moves. ----
if (isset($_POST['action']) && $_POST['action'] === 'change_assignment' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_end_clean();
    header('Content-Type: application/json');
    $row_id = (int)($_POST['id'] ?? 0);
    $new_staff_id = (int)($_POST['new_staff_id'] ?? 0);
    if ($row_id <= 0 || $new_staff_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid request.']);
        exit;
    }
    $check = $conn->query("SELECT id FROM staff WHERE id = $new_staff_id");
    if (!$check || $check->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'Staff not found.']);
        exit;
    }
    $row = $conn->query("SELECT staff_id, case_type_id, section, department_id FROM aap_case_type_staff_tiers WHERE id = $row_id");
    $row = $row ? $row->fetch_assoc() : null;
    if (!$row) {
        echo json_encode(['success' => false, 'message' => 'Assignment not found.']);
        exit;
    }
    if (!aapDeptInScope($row['department_id'], $aap_dept_ids, $aap_can_manage_all_depts)) {
        echo json_encode(['success' => false, 'message' => 'You do not manage this department.']);
        exit;
    }
    // uq_case_type_section_staff (case_type_id, section, staff_id) - the new
    // staff member can't already be on this same Case Type's same section.
    $dup = $conn->query("SELECT id FROM aap_case_type_staff_tiers WHERE case_type_id = " . (int)$row['case_type_id'] . " AND section = '" . $conn->real_escape_string($row['section']) . "' AND staff_id = $new_staff_id AND id != $row_id");
    if ($dup && $dup->num_rows > 0) {
        echo json_encode(['success' => false, 'message' => 'That staff member is already on this Case Type\'s ' . $row['section'] . ' list.']);
        exit;
    }
    $stmt = $conn->prepare("UPDATE aap_case_type_staff_tiers SET staff_id = ? WHERE id = ?");
    $stmt->bind_param("ii", $new_staff_id, $row_id);
    $stmt->execute();
    $stmt->close();
    $ct_name = $conn->query("SELECT case_type_name FROM aap_case_types WHERE id = " . (int)$row['case_type_id'])->fetch_assoc()['case_type_name'] ?? 'Case Type #' . $row['case_type_id'];
    $old_name = $conn->query("SELECT nama_staff FROM staff WHERE id = " . (int)$row['staff_id'])->fetch_assoc()['nama_staff'] ?? 'staff #' . $row['staff_id'];
    $new_name = $conn->query("SELECT nama_staff FROM staff WHERE id = $new_staff_id")->fetch_assoc()['nama_staff'] ?? 'staff #' . $new_staff_id;
    aapLogAdminAudit($conn, 'staff_assignments', 'assignment_reassigned', $id_user, (int)$row['staff_id'], "Reassigned {$row['section']} on \"$ct_name\" from $old_name to $new_name");
    echo json_encode(['success' => true, 'message' => 'Reassigned.']);
    exit;
}

// ---- Reassign every assignment a staff member holds to a different staff
// member in one go - AJAX endpoint (JSON), for offboarding a resigned staff
// member onto their replacement without doing it row by row. Where the new
// staff member already holds the exact same Case Type + section slot
// (uq_case_type_section_staff), the old holder's row is just dropped
// instead of erroring - the slot is already covered. ----
if (isset($_POST['action']) && $_POST['action'] === 'reassign_all_assignments' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_end_clean();
    header('Content-Type: application/json');
    $old_staff_id = (int)($_POST['staff_id'] ?? 0);
    $new_staff_id = (int)($_POST['new_staff_id'] ?? 0);
    if ($old_staff_id <= 0 || $new_staff_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid request.']);
        exit;
    }
    if ($old_staff_id === $new_staff_id) {
        echo json_encode(['success' => false, 'message' => 'Pick a different staff member to reassign to.']);
        exit;
    }
    $check = $conn->query("SELECT id FROM staff WHERE id = $new_staff_id");
    if (!$check || $check->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'Staff not found.']);
        exit;
    }

    // A Department Manager only reassigns the portion of this staff
    // member's assignments that fall in their own department(s) - rows in
    // other departments are left untouched rather than erroring the whole
    // batch, since "Reassign All" is meant to work even when the acting
    // user only manages part of what this staff member holds.
    $dept_scope_sql = '';
    if (!$aap_can_manage_all_depts) {
        $dept_scope_sql = empty($aap_dept_ids)
            ? ' AND 1=0'
            : ' AND department_id IN (' . implode(',', array_map('intval', $aap_dept_ids)) . ')';
    }

    $moved = 0;
    $skipped = 0;
    $rows_res = $conn->query("SELECT id, case_type_id, section FROM aap_case_type_staff_tiers WHERE staff_id = " . $old_staff_id . $dept_scope_sql);
    $rows = $rows_res ? $rows_res->fetch_all(MYSQLI_ASSOC) : [];
    foreach ($rows as $row) {
        $dup = $conn->query("SELECT id FROM aap_case_type_staff_tiers WHERE case_type_id = " . (int)$row['case_type_id'] . " AND section = '" . $conn->real_escape_string($row['section']) . "' AND staff_id = $new_staff_id");
        if ($dup && $dup->num_rows > 0) {
            $conn->query("DELETE FROM aap_case_type_staff_tiers WHERE id = " . (int)$row['id']);
            $skipped++;
        } else {
            $conn->query("UPDATE aap_case_type_staff_tiers SET staff_id = $new_staff_id WHERE id = " . (int)$row['id']);
            $moved++;
        }
    }
    $old_name = $conn->query("SELECT nama_staff FROM staff WHERE id = " . $old_staff_id)->fetch_assoc()['nama_staff'] ?? 'staff #' . $old_staff_id;
    $new_name = $conn->query("SELECT nama_staff FROM staff WHERE id = $new_staff_id")->fetch_assoc()['nama_staff'] ?? 'staff #' . $new_staff_id;
    aapLogAdminAudit($conn, 'staff_assignments', 'reassigned_all', $id_user, $old_staff_id, "Reassigned all of $old_name's assignments to $new_name ($moved moved, $skipped already covered)");
    echo json_encode(['success' => true, 'moved' => $moved, 'skipped' => $skipped]);
    exit;
}

// ---- Remove every assignment a staff member holds in one go - AJAX
// endpoint (JSON), for offboarding a resigned staff member. ----
if (isset($_POST['action']) && $_POST['action'] === 'remove_all_assignments' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_end_clean();
    header('Content-Type: application/json');
    $staff_id = (int)($_POST['staff_id'] ?? 0);
    if ($staff_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid staff.']);
        exit;
    }
    // Same partial-scope reasoning as reassign_all_assignments above - a
    // Department Manager only removes the portion within their own
    // department(s), leaving the rest of this staff member's assignments
    // alone.
    $dept_scope_sql = '';
    if (!$aap_can_manage_all_depts) {
        $dept_scope_sql = empty($aap_dept_ids)
            ? ' AND 1=0'
            : ' AND department_id IN (' . implode(',', array_map('intval', $aap_dept_ids)) . ')';
    }
    $conn->query("DELETE FROM aap_case_type_staff_tiers WHERE staff_id = " . $staff_id . $dept_scope_sql);
    $removed_count = $conn->affected_rows;
    $staff_name = $conn->query("SELECT nama_staff FROM staff WHERE id = " . $staff_id)->fetch_assoc()['nama_staff'] ?? 'staff #' . $staff_id;
    aapLogAdminAudit($conn, 'staff_assignments', 'removed_all', $id_user, $staff_id, "Removed all $removed_count assignment(s) for $staff_name");
    echo json_encode(['success' => true, 'message' => 'All assignments removed.']);
    exit;
}

// Audit Trail section (below) is only shown to an admin granted via
// staff.aap specifically - not to someone who only reaches this page via
// grade>=4/Digital Innovation/an OKR-or-ATEM SuperAdmin flag.
$aap_show_audit_trail = ($aap_level === 1);
$aap_admin_audit_logs = $aap_show_audit_trail ? aapFetchAdminAuditLogs($conn, 'staff_assignments') : [];

$aap_base = '../';
?>

<?php include('../aap_modern_head.php'); ?>

<div class="header">
  <b class="rtop"><b class="r1"></b><b class="r2"></b><b class="r3"></b><b class="r4"></b></b>
  <h1 class="headerH1"><img src="../img/logo.svg"> Staff Assignments (Admin)</h1>
  <b class="rbottom"><b class="r4"></b><b class="r3"></b><b class="r2"></b><b class="r1"></b></b>
</div>

<?php include('../aap_sidebar.php'); ?>

<div class="aap-modern">
<div class="aap-card alpro-mt-20">
    <h3 class="aap-card-title">Staff Assignments</h3>
    <p class="alpro-muted" style="font-size:12px; margin-top:-6px;">
        Search a staff member to see every Case Type Staff Tier they're on (Approval and Exclusion, across every Case Type) in one place - useful before offboarding a resigned staff member.
    </p>

    <div class="aap-filter-bar" style="max-width:400px; position:relative;">
        <input class="alpro-input aap-filter-input" type="text" id="sa2-search" placeholder="Search staff name...">
        <div id="sa2-search-results" style="display:none; position:absolute; top:100%; left:0; right:0; background:#fff; border:1px solid #dee2e6; border-radius:6px; margin-top:4px; max-height:280px; overflow-y:auto; z-index:20; box-shadow:0 4px 12px rgba(0,0,0,0.08);"></div>
    </div>

    <div id="sa2-empty" class="aap-card-hint" style="margin-top:16px;">Search for a staff member above to see their assignments.</div>

    <div id="sa2-result" style="display:none; margin-top:16px;">
        <div id="sa2-staff-card" style="display:flex; align-items:center; justify-content:space-between; padding:12px 16px; border:1px solid #e5e9ec; border-radius:8px; margin-bottom:14px;">
            <div>
                <div style="font-weight:700; font-size:15px;" id="sa2-staff-name"></div>
                <div class="alpro-muted" style="font-size:12px;" id="sa2-staff-dept"></div>
            </div>
            <div style="display:flex; align-items:center; gap:10px; position:relative;">
                <span id="sa2-staff-badge"></span>
                <button type="button" class="alpro-btn alpro-btn-grey" id="sa2-reassign-all" style="padding:6px 14px; font-size:12px; display:none;">Reassign All To...</button>
                <button type="button" class="alpro-btn" id="sa2-remove-all" style="background:#dc3545; color:#fff; border:none; padding:6px 14px; font-size:12px; display:none;">Remove All Assignments</button>
                <div id="sa2-reassign-all-picker" style="display:none; position:absolute; top:100%; right:0; margin-top:6px; background:#fff; border:1px solid #e5e9ec; border-radius:8px; box-shadow:0 4px 12px rgba(0,0,0,0.08); padding:10px; width:280px; z-index:30;">
                    <input type="text" class="alpro-input" id="sa2-reassign-all-input" placeholder="Search staff to reassign to..." style="padding:4px 8px; font-size:12px; width:100%;">
                    <div id="sa2-reassign-all-results" style="max-height:200px; overflow-y:auto; margin-top:6px;"></div>
                </div>
            </div>
        </div>

        <table class="alpro-table aap-ct-list-table" id="sa2-table" width="100%">
            <thead><tr><th>Case Type</th><th>Department</th><th>Section</th><th>Tier</th><th></th></tr></thead>
            <tbody id="sa2-tbody"></tbody>
        </table>
    </div>
</div>

<?php if ($aap_show_audit_trail): ?>
<div class="aap-card alpro-mt-20">
    <h3 class="aap-card-title">Audit Trail</h3>
    <p class="alpro-muted" style="font-size:12px; margin-top:-6px;">Every Remove/Reassign action made on this page, most recent first.</p>
    <table class="alpro-table aap-ct-list-table" width="100%">
        <thead><tr><th>When</th><th>Actor</th><th>Affected Staff</th><th>Action</th></tr></thead>
        <tbody>
            <?php if (empty($aap_admin_audit_logs)): ?>
                <tr><td colspan="4" align="center" style="padding:15px;">No actions logged yet.</td></tr>
            <?php else: ?>
                <?php foreach ($aap_admin_audit_logs as $log): ?>
                    <tr>
                        <td class="alpro-mono"><?php echo date('d-m-Y H:i', strtotime($log['timestamp'])); ?></td>
                        <td><?php echo htmlspecialchars($log['actor_name'] ?: 'Unknown'); ?></td>
                        <td><?php echo htmlspecialchars($log['target_name'] ?: '—'); ?></td>
                        <td><?php echo htmlspecialchars($log['summary']); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
</div>

<?php $page_js = '../js/aap_staff_assignments.js'; include('../aap_footer.php'); ?>
