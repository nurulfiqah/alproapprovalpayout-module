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

// This page grants/revokes staff.aap (AAP admin access) itself, so only an
// existing AAP admin can open it - a grade>=4/Digital Innovation admin who
// doesn't hold staff.aap can still manage Case Types elsewhere in the module
// (general aapIsAdmin()), but can't hand out AAP admin access from here.
$aap_identity = aapResolveIdentity($conn, $id_user, $grade, $department);
$grade = $aap_identity['grade'];
$department = $aap_identity['department'];
$aap_dept_ids = $aap_identity['dept_ids'];
$aap_is_admin = $aap_identity['is_admin'];
$aap_is_superadmin = $aap_identity['is_superadmin'];
if (!$aap_is_superadmin) {
    die("Admin access only. This page manages AAP Admin Access.");
}

// PHP's default session handler locks the session file for the whole
// request - this page is hit repeatedly via AJAX below (staff search, Bank
// Master CRUD) and never writes to $_SESSION itself, so releasing the lock
// here lets those requests (and everything else sharing this browser's
// session) run concurrently instead of queuing up behind whichever one
// happens to be mid-request.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
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
    // staff.aap is just a flag now: 0 = no access, 1 = full AAP admin access
    // (see aapFetchAapLevel()/aapFetchIsSuperAdmin() in aap_lib.php - the old
    // two-tier "Admin 1"/"Admin 2 (SuperAdmin)" split was removed since
    // there was never a real reason to hold anything back from Admin 1).
    // Anything else posted collapses to 0 rather than left as whatever
    // garbage came in.
    $aap_val = (int)($_POST['aap'] ?? 0);
    if (!in_array($aap_val, [0, 1], true)) $aap_val = 0;

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

// ---- Bank Master panel - AJAX endpoints. Lets a SuperAdmin add/rename/
// retire banks straight from the frontend instead of needing a DB script
// each time (see aap_bank_master table, aapFetchBankMasterOptions() in
// aap_lib.php - the Bank Detail dropdown on aap_add.php/aap_update.php reads
// from that same table). Soft-delete via `recycle`, same convention as
// aap_case_types, so a retired bank stays intact on historical cases that
// already reference its id.
if (isset($_GET['action']) && $_GET['action'] === 'list_banks' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    ob_end_clean();
    header('Content-Type: application/json');
    $rows = [];
    $res = $conn->query("SELECT id, bank_name, sort_order, recycle FROM aap_bank_master ORDER BY sort_order, bank_name");
    while ($res && $row = $res->fetch_assoc()) {
        $rows[] = [
            'id' => (int)$row['id'],
            'bank_name' => $row['bank_name'],
            'sort_order' => (int)$row['sort_order'],
            'recycle' => (int)$row['recycle'],
        ];
    }
    echo json_encode(['success' => true, 'data' => $rows]);
    exit;
}

if (isset($_POST['action']) && $_POST['action'] === 'add_bank' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_end_clean();
    header('Content-Type: application/json');
    $bank_name = trim($_POST['bank_name'] ?? '');
    if ($bank_name === '') {
        echo json_encode(['success' => false, 'message' => 'Bank name is required.']);
        exit;
    }
    $dup = $conn->query("SELECT id FROM aap_bank_master WHERE bank_name = '" . $conn->real_escape_string($bank_name) . "' AND recycle = 0");
    if ($dup && $dup->num_rows > 0) {
        echo json_encode(['success' => false, 'message' => 'That bank already exists.']);
        exit;
    }
    $max_res = $conn->query("SELECT COALESCE(MAX(sort_order), 0) m FROM aap_bank_master");
    $next_sort = ((int)$max_res->fetch_assoc()['m']) + 1;
    $stmt = $conn->prepare("INSERT INTO aap_bank_master (bank_name, sort_order, timestamp) VALUES (?, ?, ?)");
    $stmt->bind_param("sis", $bank_name, $next_sort, $now);
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Bank added.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
    }
    $stmt->close();
    exit;
}

if (isset($_POST['action']) && $_POST['action'] === 'rename_bank' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_end_clean();
    header('Content-Type: application/json');
    $bank_id = (int)($_POST['bank_id'] ?? 0);
    $bank_name = trim($_POST['bank_name'] ?? '');
    if ($bank_id <= 0 || $bank_name === '') {
        echo json_encode(['success' => false, 'message' => 'Invalid bank or name.']);
        exit;
    }
    $stmt = $conn->prepare("UPDATE aap_bank_master SET bank_name = ? WHERE id = ?");
    $stmt->bind_param("si", $bank_name, $bank_id);
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Bank updated.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
    }
    $stmt->close();
    exit;
}

if (isset($_POST['action']) && $_POST['action'] === 'toggle_bank_recycle' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_end_clean();
    header('Content-Type: application/json');
    $bank_id = (int)($_POST['bank_id'] ?? 0);
    $recycle = (int)($_POST['recycle'] ?? 0) === 1 ? 1 : 0;
    if ($bank_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid bank.']);
        exit;
    }
    $stmt = $conn->prepare("UPDATE aap_bank_master SET recycle = ? WHERE id = ?");
    $stmt->bind_param("ii", $recycle, $bank_id);
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => $recycle ? 'Bank retired.' : 'Bank restored.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
    }
    $stmt->close();
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

<!-- =====================  ===================== -->
<div class="alpro-box alpro-mt-20" style="text-align:left;">
    <h3 style="margin-top:0; text-align:left;">Manage AAP Admin Access</h3>

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
            <tr><th>Staff Name</th><th>Department</th><th>AAP Access</th><th></th></tr>
            <tbody id="sa-staff-tbody">
                <tr><td colspan="4" align="center" style="padding:15px;">Loading...</td></tr>
            </tbody>
        </table>
        <div class="sa-pager" id="sa-staff-pager"></div>
    </div>
    </div>

    <div id="sa-edit" class="aap-modern" style="display:none;">
    <div class="aap-card alpro-mt-20" style="max-width:420px;">
        <h6 class="aap-card-title" style="margin-bottom:14px;">Update Admin Access</h6>
        <p style="margin:0 0 4px;"><strong>Name:</strong> <span id="sa-info-name"></span></p>
        <p style="margin:0 0 14px;"><strong>Department:</strong> <span id="sa-info-dept"></span></p>
        <div class="alpro-field" style="margin-bottom:14px;">
            <label style="font-size:10px; font-weight:600; color:#6c757d; text-transform:uppercase;">AAP Access</label>
            <select class="alpro-input" id="sa-aap-level">
                <option value="0">No Access</option>
                <option value="1">Admin (full access)</option>
            </select>
        </div>
        <div id="sa-alert" class="alpro-alert" style="display:none; margin-bottom:10px;"></div>
        <div class="alpro-actions" style="justify-content:flex-end;">
            <button type="button" class="alpro-btn alpro-btn-grey" id="sa-cancel-btn">Cancel</button>
            <button type="button" class="alpro-btn alpro-btn-blue" id="sa-update-btn">Update</button>
        </div>
    </div>
    </div>
</div>

<!-- =====================  ===================== -->
<div class="alpro-box alpro-mt-20" style="text-align:left;">
    <h3 style="margin-top:0; text-align:left;">Bank Master</h3>
    <p class="alpro-muted" style="margin-top:-8px;">Banks listed here populate the Bank Name dropdown on Raise Case / Edit Case. Retiring a bank removes it from that dropdown but keeps it on any case that already used it.</p>

    <div class="aap-modern">
    <div class="aap-card">
        <form onsubmit="return false;" style="display:flex; gap:8px; align-items:flex-end; margin-bottom:14px;">
            <div class="alpro-field" style="flex:1; max-width:320px;">
                <label style="font-size:10px; font-weight:600; color:#6c757d; text-transform:uppercase;">New Bank Name</label>
                <input class="alpro-input" type="text" id="bank-new-name" placeholder="e.g. Bank Islam Malaysia Berhad">
            </div>
            <input class="alpro-btn alpro-btn-blue" type="button" id="bank-add-btn" value="Add Bank">
        </form>
        <div id="bank-alert" class="alpro-alert" style="display:none; margin-bottom:10px;"></div>

        <table class="alpro-table" width="100%">
            <tr><th>Bank Name</th><th style="width:120px;">Status</th><th style="width:160px;"></th></tr>
            <tbody id="bank-tbody">
                <tr><td colspan="3" align="center" style="padding:15px;">Loading...</td></tr>
            </tbody>
        </table>
    </div>
    </div>
</div>

<?php $page_js = '../js/aap_settings.js'; include('../aap_footer.php'); ?>
