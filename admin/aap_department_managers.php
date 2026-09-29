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

// PHP's default session handler locks the session file for the whole
// request - this page is hit repeatedly via AJAX below and never writes to
// $_SESSION itself, so releasing the lock here lets those requests (and
// everything else sharing this browser's session) run concurrently instead
// of queuing up behind whichever one happens to be mid-request.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

// ---- AJAX: every active staff member belonging to one department - feeds
// the Grant Access panel's Staff dropdown below. Admin-only (staff.aap = 1),
// same as the mutation endpoint further down - a Department Manager can view
// the page but never this panel or what feeds it. ----
if (isset($_GET['action']) && $_GET['action'] === 'get_dept_staff' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    ob_end_clean();
    header('Content-Type: application/json');
    // Wrapped so a stray warning/notice or a thrown mysqli exception (this
    // codebase runs with MYSQLI_REPORT_STRICT elsewhere) can never leak raw
    // PHP output/HTML into what the browser expects to be pure JSON - that
    // corrupts response.json() client-side into an opaque "Failed to load"
    // with no way to tell what actually went wrong. A real error now comes
    // back as a JSON message instead.
    try {
        $identity = aapResolveIdentity($conn, $id_user, $grade, $department);
        if (!$identity['is_superadmin']) {
            echo json_encode(['success' => false, 'message' => 'Admin access only.']);
            exit;
        }
        $dept_id = (int)($_GET['department_id'] ?? 0);
        if ($dept_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid department.']);
            exit;
        }
        $stmt = $conn->prepare("SELECT id, nama_staff, aap FROM staff WHERE recycle != 1 AND FIND_IN_SET(?, department) ORDER BY nama_staff ASC");
        $stmt->bind_param("i", $dept_id);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        $staff = array_map(function ($r) {
            return ['id' => (int)$r['id'], 'nama_staff' => $r['nama_staff'], 'aap' => (int)$r['aap']];
        }, $rows);
        echo json_encode(['success' => true, 'staff' => $staff]);
    } catch (\Throwable $e) {
        echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
    }
    exit;
}

// ---- AJAX: set/revoke a staff member's Department Manager access
// (staff.aap = 2). Never touches staff.aap = 1 (full admin) - a target
// already at level 1 is rejected rather than silently demoted, since that
// would be a much bigger, unintended change to make from this panel. ----
if (isset($_POST['action']) && $_POST['action'] === 'set_department_manager' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_end_clean();
    header('Content-Type: application/json');
    $identity = aapResolveIdentity($conn, $id_user, $grade, $department);
    if (!$identity['is_superadmin']) {
        echo json_encode(['success' => false, 'message' => 'Admin access only.']);
        exit;
    }
    $staff_id = (int)($_POST['staff_id'] ?? 0);
    $make_manager = !empty($_POST['make_manager']);
    if ($staff_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid staff.']);
        exit;
    }
    $check = $conn->query("SELECT aap, nama_staff FROM staff WHERE id = $staff_id AND recycle != 1");
    $row = $check ? $check->fetch_assoc() : null;
    if (!$row) {
        echo json_encode(['success' => false, 'message' => 'Staff not found.']);
        exit;
    }
    if ((int)$row['aap'] === 1) {
        echo json_encode(['success' => false, 'message' => $row['nama_staff'] . ' is already a full Admin - change that from AAP Access instead.']);
        exit;
    }
    $new_val = $make_manager ? 2 : 0;
    if ($conn->query("UPDATE staff SET aap = $new_val WHERE id = $staff_id AND recycle != 1")) {
        echo json_encode(['success' => true, 'message' => $make_manager ? 'Set as Department Manager.' : 'Removed.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Update failed.']);
    }
    exit;
}

// Who counts as a Department Manager is decided by staff.aap = 2 (see
// aapFetchDeptManagerDepartmentIds() in aap_lib.php) - settable from the
// Grant Access panel below (admin-only), or from admin/aap_settings.php's
// AAP Access panel (same underlying column, either page works). A full AAP
// admin or a Department Manager (for their own department(s)) can view this
// page; only a full admin sees the Grant Access panel itself.
$aap_identity = aapResolveIdentity($conn, $id_user, $grade, $department);
$grade = $aap_identity['grade'];
$department = $aap_identity['department'];
$aap_dept_ids = $aap_identity['dept_ids'];
$aap_is_admin = $aap_identity['is_admin'];
$aap_is_superadmin = $aap_identity['is_superadmin'];
$aap_manager_dept_ids = aapFetchDeptManagerDepartmentIds($conn, $id_user);
$can_view_page = $aap_is_superadmin || !empty($aap_manager_dept_ids);
if (!$can_view_page) {
    die("You don't have access to this page. This page is for AAP admins and Department Managers.");
}

// Department Managers are no longer granted here - they're simply every
// staff member with staff.aap = 2, each managing their own staff.department
// department(s) (see aapFetchDeptManagerDepartmentIds() in aap_lib.php).
// This page just lists them, one row per (department, staff). A full AAP
// admin sees every department; a Department Manager only sees the managers
// sharing their own department(s).
$dept_names = array_column(aapFetchDepartments($conn), 'depart_name', 'id');
$fixit_admins = $conn->query("
    SELECT id, nama_staff, department, email, hp
    FROM staff
    WHERE aap = 2 AND recycle != 1
    ORDER BY nama_staff ASC
")->fetch_all(MYSQLI_ASSOC);
$managers_by_dept = [];
foreach ($fixit_admins as $fa) {
    foreach (aapDeptIdsFromCsv($fa['department']) as $dept_id) {
        if (!$aap_is_superadmin && !in_array($dept_id, $aap_manager_dept_ids, true)) continue;
        $managers_by_dept[$dept_id]['name'] = $dept_names[$dept_id] ?? '';
        $managers_by_dept[$dept_id]['rows'][] = $fa;
    }
}
uasort($managers_by_dept, function ($x, $y) { return strcasecmp($x['name'], $y['name']); });
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


<div class="aap-bento">
    <?php if ($aap_is_superadmin): ?>
    <div class="aap-bento-item aap-span-12">
        <div class="aap-card">
            <h6 class="aap-card-title"><i class="bi bi-plus-lg"></i> Grant Access</h6>
            <p class="aap-card-hint" style="margin-top:6px;">Sets a staff member's AAP Access to Department Manager (staff.aap = 2) - same field as Admin → AAP Access. They'll manage every department already in their own staff record.</p>
            <div style="display:flex; align-items:flex-end; gap:12px; flex-wrap:wrap;">
                <div class="alpro-field" style="flex:0 0 220px;">
                    <label>Department</label>
                    <select class="alpro-input" id="dm-department">
                        <option value="">Select Department</option>
                        <?php foreach (aapFetchDepartments($conn) as $d): ?>
                            <option value="<?php echo (int)$d['id']; ?>"><?php echo htmlspecialchars($d['depart_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="alpro-field" style="flex:1; min-width:200px;">
                    <label>Staff</label>
                    <select class="alpro-input" id="dm-staff-select" disabled>
                        <option value="">Select Department first</option>
                    </select>
                </div>
                <button class="alpro-btn alpro-btn-blue" type="button" id="dm-add-btn" style="flex:0 0 auto; padding:8px 20px; font-size:14px; border-radius:8px; white-space:nowrap;">Set as Department Manager</button>
            </div>
            <div style="text-align:right; margin-top:6px;">
                <span id="dm-add-msg" style="font-size:12px;"></span>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="aap-bento-item aap-span-12">
        <div class="aap-card">
            <h6 class="aap-card-title"><i class="bi bi-list-check"></i> Current Department Managers</h6>
            <p class="aap-card-hint" style="margin-top:6px;">Every staff member with AAP Access set to Department Manager (staff.aap = 2) manages their own department's Case Types, Approval Units and Staff Assignments here.</p>
            <table class="alpro-table aap-ct-list-table" width="100%">
                <thead><tr><th>Department</th><th>Staff</th><th>Email</th><th>Phone</th><?php if ($aap_is_superadmin): ?><th>Action</th><?php endif; ?></tr></thead>
                <tbody id="dm-list-tbody">
                    <?php if (empty($managers_by_dept)): ?>
                    <tr><td colspan="<?php echo $aap_is_superadmin ? 5 : 4; ?>" class="alpro-muted" style="text-align:center;">No Department Managers found.</td></tr>
                    <?php else: ?>
                    <?php foreach ($managers_by_dept as $group): ?>
                        <?php foreach ($group['rows'] as $i => $m): ?>
                        <tr data-id="<?php echo (int)$m['id']; ?>">
                            <?php if ($i === 0): ?>
                            <td rowspan="<?php echo count($group['rows']); ?>" style="vertical-align:top;"><?php echo htmlspecialchars($group['name'] ?: '—'); ?></td>
                            <?php endif; ?>
                            <td><?php echo htmlspecialchars($m['nama_staff'] ?: '—'); ?></td>
                            <td><?php echo htmlspecialchars($m['email'] ?: '—'); ?></td>
                            <td><?php echo htmlspecialchars($m['hp'] ?: '—'); ?></td>
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

<?php $page_js = $aap_is_superadmin ? '../js/aap_department_managers.js' : ''; include('../aap_footer.php'); ?>
