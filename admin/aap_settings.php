<?php
// Buffers everything so the AJAX action branches below (list_aap_superadmin /
// update_aap_superadmin) can discard the HTML lock_adv.php echoes before its
// redirect check runs and emit clean JSON instead - same trick used in
// aap_notifications.php.
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
    die("Admin access only. This page manages AAP SuperAdmin Access.");
}

$msg = "";
$msg_type = "";
$now = date('Y-m-d H:i:s');

// ---- AAP SuperAdmin Access panel - AJAX endpoints (JSON, mirrors ATEM's
// admin/backend.php getSuperAdminStaff/updateSuperAdmin), so the panel below
// can search/paginate/update live without a full page reload, same as the
// ATEM & OKR SuperAdmin panel it's modelled on. Only staff.aap is written
// here - staff.atem/okr belong to their own modules' admin panels
// (atem/admin/index.php), not this one.
if (isset($_GET['action']) && $_GET['action'] === 'list_aap_superadmin' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    ob_end_clean();
    header('Content-Type: application/json');
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per_page = max(5, min(100, (int)($_GET['per_page'] ?? 30)));
    $offset = ($page - 1) * $per_page;
    $name_filter = trim($_GET['name_filter'] ?? '');
    $name_sql = $name_filter !== '' ? " AND nama_staff LIKE '" . $conn->real_escape_string($name_filter) . "%'" : '';
    // With no name search, only show current AAP admins (a roster, not the
    // full staff directory) - a name search reaches every staff member so a
    // brand-new admin can still be found and promoted.
    $admin_only_sql = $name_filter === '' ? ' AND aap != 0' : '';

    $count_res = $conn->query("SELECT COUNT(*) c FROM staff WHERE recycle != 1 $name_sql $admin_only_sql");
    $total = $count_res ? (int)$count_res->fetch_assoc()['c'] : 0;

    $res = $conn->query("
        SELECT s.id, s.nama_staff, s.aap, sd.depart_name
        FROM staff s
        LEFT JOIN staff_department sd ON sd.id = s.department
        WHERE s.recycle != 1 $name_sql $admin_only_sql
        ORDER BY s.nama_staff ASC
        LIMIT $per_page OFFSET $offset
    ");
    $staff = [];
    while ($res && $row = $res->fetch_assoc()) {
        $staff[] = [
            'id' => (int)$row['id'],
            'nama_staff' => $row['nama_staff'],
            'aap' => (int)$row['aap'],
            'department_name' => $row['depart_name'] ?: '—',
        ];
    }
    echo json_encode([
        'success' => true,
        'data' => $staff,
        'total' => $total,
        'page' => $page,
        'per_page' => $per_page,
        'total_pages' => max(1, (int)ceil($total / $per_page)),
    ]);
    exit;
}

if (isset($_POST['action']) && $_POST['action'] === 'update_aap_superadmin' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_end_clean();
    header('Content-Type: application/json');
    $target_id = (int)($_POST['staff_id'] ?? 0);
    $aap_val = (isset($_POST['aap']) && (int)$_POST['aap'] === 1) ? 1 : 0;

    if ($target_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid staff.']);
        exit;
    }
    $check = $conn->query("SELECT id FROM staff WHERE id = $target_id AND recycle != 1");
    if (!$check || $check->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'Staff not found.']);
        exit;
    }
    if ($conn->query("UPDATE staff SET aap = $aap_val WHERE id = $target_id AND recycle != 1")) {
        echo json_encode(['success' => true, 'message' => 'Updated successfully.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
    }
    exit;
}
$aap_base = '../';
?>

<?php include('../aap_modern_head.php'); ?>

<div class="header">
  <b class="rtop"><b class="r1"></b><b class="r2"></b><b class="r3"></b><b class="r4"></b></b>
  <h1 class="headerH1"><img src="../img/logo.svg"> Settings (Admin)</h1>
  <b class="rbottom"><b class="r4"></b><b class="r3"></b><b class="r2"></b><b class="r1"></b></b>
</div>

<?php include('../aap_sidebar.php'); ?>

<?php if (!empty($msg)): ?>
    <div class="alpro-alert <?php echo $msg_type; ?> alpro-mt-20"><?php echo htmlspecialchars($msg); ?></div>
<?php endif; ?>

<!-- ===================== MANAGE AAP SUPERADMIN ACCESS ===================== -->
<div class="alpro-box alpro-mt-20" style="text-align:left;">
    <h3 style="margin-top:0; text-align:left;">Manage AAP SuperAdmin Access</h3>

    <div class="aap-modern">
    <form onsubmit="return false;" class="sa-filter-form" style="display:flex; gap:8px; align-items:flex-end; margin-bottom:14px;">
        <div class="alpro-field" style="flex:1; max-width:220px;">
            <label style="font-size:10px; font-weight:600; color:#6c757d; text-transform:uppercase;">Staff Name</label>
            <input class="alpro-input" type="text" id="sa-filter-name" placeholder="Search name...">
        </div>
        <input class="alpro-btn alpro-btn-blue" type="button" id="sa-apply-filter" value="Apply">
        <input class="alpro-btn alpro-btn-grey" type="button" id="sa-reset-filter" value="Reset">
    </form>

    <div class="aap-card">
        <table class="alpro-table aap-sa-list-table" width="100%">
            <tr><th>Staff Name</th><th>Department</th><th>AAP Admin</th><th></th></tr>
            <tbody id="sa-staff-tbody">
                <tr><td colspan="4" align="center" style="padding:15px;">Loading...</td></tr>
            </tbody>
        </table>
        <div class="sa-pager" id="sa-staff-pager"></div>
    </div>
    </div>

    <div id="sa-edit" class="aap-modern" style="display:none;">
    <div class="aap-card alpro-mt-20" style="max-width:420px;">
        <h6 class="aap-card-title" style="margin-bottom:14px;">Update SuperAdmin Access</h6>
        <p style="margin:0 0 4px;"><strong>Name:</strong> <span id="sa-info-name"></span></p>
        <p style="margin:0 0 14px;"><strong>Department:</strong> <span id="sa-info-dept"></span></p>
        <label style="display:flex; align-items:center; gap:8px; font-weight:normal; margin-bottom:14px;">
            <input type="checkbox" id="sa-aap-toggle">
            AAP Admin
        </label>
        <div id="sa-alert" class="alpro-alert" style="display:none; margin-bottom:10px;"></div>
        <div class="alpro-actions" style="justify-content:flex-end;">
            <button type="button" class="alpro-btn alpro-btn-grey" id="sa-cancel-btn">Cancel</button>
            <button type="button" class="alpro-btn alpro-btn-blue" id="sa-update-btn">Update</button>
        </div>
    </div>
    </div>
</div>

<?php $page_js = '../js/aap_settings.js'; include('../aap_footer.php'); ?>
