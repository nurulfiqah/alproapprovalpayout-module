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

// GRANTING/REVOKING access itself is AAP-admin-only - same as
// aap_settings.php (grants staff.aap) - a Department Manager must not be
// able to hand themselves or anyone else that same access, or extend it to
// another department (every mutation endpoint below re-checks
// $aap_is_superadmin independently). VIEWING the page is relaxed further
// down: a Department Manager can open it to see the grants list for their
// own department(s), just with the "Grant Access" form and Remove buttons
// hidden - see $aap_manager_dept_ids/$can_view_page below.
$aap_identity = aapResolveIdentity($conn, $id_user, $grade, $department);
$grade = $aap_identity['grade'];
$department = $aap_identity['department'];
$aap_dept_ids = $aap_identity['dept_ids'];
$aap_is_admin = $aap_identity['is_admin'];
$aap_is_superadmin = $aap_identity['is_superadmin'];
$aap_manager_dept_ids = aapFetchDeptManagerDepartmentIds($conn, $id_user);
$can_view_page = $aap_is_superadmin || !empty($aap_manager_dept_ids);
if (!$can_view_page) {
    die("You don't have access to this page. Ask an admin to grant you AAP admin access, or Department Manager access for a specific department.");
}

// PHP's default session handler locks the session file for the whole
// request - this page is hit repeatedly via AJAX below and never writes to
// $_SESSION itself, so releasing the lock here lets those requests (and
// everything else sharing this browser's session) run concurrently instead
// of queuing up behind whichever one happens to be mid-request.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

// ---- AJAX: every active staff member belonging to one department (via
// staff.department, comma-separated) - populates the Grant Access Staff
// dropdown below with that department's actual roster. Admin-only, same as
// the mutation endpoints below - a Department Manager can view the page now
// but the Grant Access form itself (and everything that feeds it) stays
// off-limits to them. ----
if (isset($_GET['action']) && $_GET['action'] === 'get_dept_staff' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    ob_end_clean();
    header('Content-Type: application/json');
    if (!$aap_is_superadmin) {
        echo json_encode(['success' => false, 'message' => 'Admin access only.']);
        exit;
    }
    $dept_id = (int)($_GET['department_id'] ?? 0);
    if ($dept_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid department.']);
        exit;
    }
    $stmt = $conn->prepare("
        SELECT s.id, s.nama_staff
        FROM staff s
        WHERE s.recycle != 1 AND FIND_IN_SET(?, s.department)
        ORDER BY s.nama_staff ASC
    ");
    $stmt->bind_param("i", $dept_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $staff = array_map(function ($r) {
        return ['id' => (int)$r['id'], 'nama_staff' => $r['nama_staff']];
    }, $rows);
    echo json_encode(['success' => true, 'staff' => $staff]);
    exit;
}

// ---- AJAX: grant one staff member Department Manager access for one
// department. ----
if (isset($_POST['action']) && $_POST['action'] === 'add_manager' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_end_clean();
    header('Content-Type: application/json');
    if (!$aap_is_superadmin) {
        echo json_encode(['success' => false, 'message' => 'Admin access only.']);
        exit;
    }
    $dept_id = (int)($_POST['department_id'] ?? 0);
    $staff_id = (int)($_POST['staff_id'] ?? 0);
    if ($dept_id <= 0 || $staff_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid department or staff.']);
        exit;
    }
    $staff_check = $conn->query("SELECT id FROM staff WHERE id = $staff_id");
    if (!$staff_check || $staff_check->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'Staff not found.']);
        exit;
    }
    $now = date('Y-m-d H:i:s');
    $stmt = $conn->prepare("INSERT IGNORE INTO aap_department_managers (department_id, staff_id, created_by, timestamp) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("iiis", $dept_id, $staff_id, $id_user, $now);
    $ok = $stmt->execute();
    $stmt->close();
    echo json_encode(['success' => $ok, 'message' => $ok ? 'Added.' : $conn->error]);
    exit;
}

// ---- AJAX: revoke one Department Manager grant. ----
if (isset($_POST['action']) && $_POST['action'] === 'remove_manager' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_end_clean();
    header('Content-Type: application/json');
    if (!$aap_is_superadmin) {
        echo json_encode(['success' => false, 'message' => 'Admin access only.']);
        exit;
    }
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid entry.']);
        exit;
    }
    $conn->query("DELETE FROM aap_department_managers WHERE id = " . $id);
    echo json_encode(['success' => true, 'message' => 'Removed.']);
    exit;
}

$departments = aapFetchDepartments($conn);
// A Department Manager only ever sees the grants for their own granted
// department(s) here - not the full company-wide roster a real admin gets.
$managers_scope_sql = $aap_is_superadmin ? '' : (
    empty($aap_manager_dept_ids) ? ' AND 1=0' : ' AND m.department_id IN (' . implode(',', array_map('intval', $aap_manager_dept_ids)) . ')'
);
$managers = $conn->query("
    SELECT m.id, m.department_id, m.staff_id, m.timestamp, sd.depart_name, s.nama_staff, s.recycle AS staff_resigned, s.email, s.hp
    FROM aap_department_managers m
    LEFT JOIN staff_department sd ON sd.id = m.department_id
    LEFT JOIN staff s ON s.id = m.staff_id
    WHERE 1=1 $managers_scope_sql
    ORDER BY sd.depart_name ASC, s.nama_staff ASC
")->fetch_all(MYSQLI_ASSOC);
$aap_base = '../';
?>

<?php include('../aap_modern_head.php'); ?>

<div class="header">
  <b class="rtop"><b class="r1"></b><b class="r2"></b><b class="r3"></b><b class="r4"></b></b>
  <h1 class="headerH1"><img src="../img/logo.svg"> Department Managers (Admin)</h1>
  <b class="rbottom"><b class="r4"></b><b class="r3"></b><b class="r2"></b><b class="r1"></b></b>
</div>

<?php include('../aap_sidebar.php'); ?>

<div class="aap-modern">

<div class="aap-page-title-row">
    <h2 class="aap-page-title"><i class="bi bi-person-badge"></i> Department Managers</h2>
</div>

<div class="aap-access-note" style="background:#e6f6ed; color:#146c37; border-color:#b7e4c7;">
    Grants a specific staff member access to manage one department's Case Types, Approval Unit Groups, and Staff Assignments lookups in AAP - independent of their grade or <code>staff.department</code>. Additive only: it never removes access someone already has through the normal grade/department-based rules, and it never touches the shared Universal tier list (Approval Unit Master), only that department's own Groups.
</div>

<div class="aap-bento">
    <?php if ($aap_is_superadmin): ?>
    <div class="aap-bento-item aap-span-12">
        <div class="aap-card">
            <h6 class="aap-card-title"><i class="bi bi-plus-lg"></i> Grant Access</h6>
            <div class="alpro-grid">
                <div class="alpro-field">
                    <label>Department</label>
                    <select class="alpro-input" id="dm-department">
                        <option value="">Select Department</option>
                        <?php foreach ($departments as $d): ?>
                            <option value="<?php echo (int)$d['id']; ?>"><?php echo htmlspecialchars($d['depart_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="alpro-field">
                    <label>Staff</label>
                    <select class="alpro-input" id="dm-staff-select" disabled>
                        <option value="">Select Department first</option>
                    </select>
                </div>
            </div>
            <div class="alpro-actions alpro-mt-10" style="justify-content:flex-end;">
                <span id="dm-add-msg" style="font-size:12px; margin-right:8px;"></span>
                <button class="alpro-btn alpro-btn-blue" type="button" id="dm-add-btn" style="flex:0 0 auto; padding:8px 20px; font-size:14px; border-radius:8px;">Grant Access</button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="aap-bento-item aap-span-12">
        <div class="aap-card">
            <h6 class="aap-card-title"><i class="bi bi-list-check"></i> Current Grants</h6>
            <table class="alpro-table aap-ct-list-table" width="100%">
                <thead><tr><th>Department</th><th>Staff</th><th>Email</th><th>Phone</th><th>Granted</th><?php if ($aap_is_superadmin): ?><th>Action</th><?php endif; ?></tr></thead>
                <tbody id="dm-list-tbody">
                    <?php if (empty($managers)): ?>
                    <tr><td colspan="<?php echo $aap_is_superadmin ? 6 : 5; ?>" class="alpro-muted" style="text-align:center;">No Department Managers granted yet.</td></tr>
                    <?php else: ?>
                    <?php
                    // $managers is already ordered by depart_name then
                    // nama_staff, so same-department rows are already
                    // adjacent - group them here so the Department cell only
                    // needs to appear once per department (rowspan) instead
                    // of repeating on every staff row.
                    $managers_by_dept = [];
                    foreach ($managers as $m) {
                        $managers_by_dept[$m['department_id']]['name'] = $m['depart_name'];
                        $managers_by_dept[$m['department_id']]['rows'][] = $m;
                    }
                    ?>
                    <?php foreach ($managers_by_dept as $group): ?>
                        <?php foreach ($group['rows'] as $i => $m): ?>
                        <tr data-id="<?php echo (int)$m['id']; ?>">
                            <?php if ($i === 0): ?>
                            <td rowspan="<?php echo count($group['rows']); ?>" style="vertical-align:top;"><?php echo htmlspecialchars($group['name'] ?: '—'); ?></td>
                            <?php endif; ?>
                            <td><?php echo htmlspecialchars($m['nama_staff'] ?: '—'); ?><?php if (!empty($m['staff_resigned'])): ?> <span class="alpro-badge alpro-badge-voided">Resigned</span><?php endif; ?></td>
                            <td><?php echo htmlspecialchars($m['email'] ?: '—'); ?></td>
                            <td><?php echo htmlspecialchars($m['hp'] ?: '—'); ?></td>
                            <td><?php echo htmlspecialchars(date('d-m-Y H:i', strtotime($m['timestamp']))); ?></td>
                            <?php if ($aap_is_superadmin): ?>
                            <td><button type="button" class="alpro-btn alpro-btn-grey dm-remove-btn" style="padding:2px 10px; font-size:12px;">Remove</button></td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
</div>

<?php $page_js = '../js/aap_department_managers.js'; include('../aap_footer.php'); ?>
