<?php
// Buffers everything so the AJAX action branches below can discard the HTML
// lock_adv.php echoes before its redirect check runs and emit clean JSON
// instead - same trick used in admin/aap_admin.php.
ob_start();
require_once('../../lock_adv.php');
$connect = 1;
date_default_timezone_set('Asia/Kuala_Lumpur');
include('../../common/index_adv.php');
if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection not available.");
}
require_once('../aap_lib.php');

// SuperAdmin-only ("Admin 2", staff.aap = 2) - same tier as
// aap_settings.php (grants staff.aap itself) and aap_staff_assignments.php.
// A separate aap_approval_unit column used to let a general grade>=4/
// Digital Innovation/staff.aap=1 admin ("Admin 1") in without full
// SuperAdmin - dropped in favour of just checking staff.aap's own level
// (see aapFetchIsSuperAdmin()/aapFetchAapLevel() in aap_lib.php), since two
// separate access columns for one module was more confusing than useful.
// $aap_is_admin is still computed (aap_sidebar.php below needs it to
// decide whether to show the Settings/Admin/Staff Assignments nav links at
// all).
$aap_dept_ids = aapDeptIdsFromCsv($department);
$aap_is_superadmin = aapFetchIsSuperAdmin($conn, $id_user);
$aap_is_admin = aapIsAdmin($grade, $aap_dept_ids, $aap_is_superadmin, aapFetchAapLevel($conn, $id_user));
if (!$aap_is_superadmin) {
    die("SuperAdmin access only. This page manages the Approval Unit Master.");
}

// Named tier list for either the shared Universal list (department_id null,
// group_id ignored) or one Group's own copy within a department - same
// fixed 6-row (one per staff_grade) shape both times. A group's own row
// only overrides the RM Value for that same grade/tier pairing - the grade
// itself always comes from the Universal list's own grouping
// (aap_approval_unit_tier_grades), resolved by matching tier_name since a
// group's row has its own id, not the Universal row's - see
// aapFetchTierGrades()/add_group below.
function aapFetchApprovalUnitTiers($conn, $department_id, $group_id = null) {
    if ($department_id === null) {
        $stmt = $conn->prepare("SELECT id, tier_name, tier_value, reason FROM aap_approval_unit_tiers WHERE department_id IS NULL ORDER BY sort_order ASC, id ASC");
    } else {
        $stmt = $conn->prepare("SELECT id, tier_name, tier_value, reason FROM aap_approval_unit_tiers WHERE group_id = ? ORDER BY sort_order ASC, id ASC");
        $stmt->bind_param("i", $group_id);
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id'];
        $r['tier_value'] = $r['tier_value'] !== null ? (float)$r['tier_value'] : null;
        $r['grades'] = $department_id === null
            ? aapFetchTierGrades($conn, $r['id'])
            : aapFetchTierGradesByName($conn, $r['tier_name']);
        $r['staff'] = ($department_id !== null) ? aapFetchDepartmentTierStaff($conn, $department_id, $group_id, $r['id'], $r['tier_name']) : [];
    }
    return $rows;
}

// Every Group a department has set up, each with its own independent copy
// of the fixed 6-tier set (see aapFetchApprovalUnitTiers above) - a
// department can have any number of Groups (e.g. separate teams within the
// same department), each named/described and staffed independently.
function aapFetchDepartmentGroups($conn, $department_id) {
    $stmt = $conn->prepare("SELECT id, group_name, description FROM aap_approval_unit_tier_groups WHERE department_id = ? ORDER BY sort_order ASC, id ASC");
    $stmt->bind_param("i", $department_id);
    $stmt->execute();
    $groups = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    foreach ($groups as &$g) {
        $g['id'] = (int)$g['id'];
        $g['tiers'] = aapFetchApprovalUnitTiers($conn, $department_id, $g['id']);
    }
    return $groups;
}

// Every staff member in this department, for the "Assign Staff" picker
// (id, name, current grade) - same shape as aapFetchPoolStaff() in
// admin/aap_admin.php.
function aapFetchDeptStaffPool($conn, $dept_id) {
    $out = [];
    $res = $conn->query("
        SELECT s.id, s.nama_staff, s.grade
        FROM staff s
        WHERE s.recycle != 1 AND FIND_IN_SET(" . (int)$dept_id . ", s.department)
        ORDER BY s.nama_staff ASC
    ");
    while ($res && $row = $res->fetch_assoc()) {
        $out[] = ['id' => (int)$row['id'], 'name' => $row['nama_staff'], 'grade' => (int)$row['grade']];
    }
    return $out;
}

// Same as aapFetchTierGrades() but resolved by tier NAME against the
// Universal list, for a department's own row (whose id isn't the Universal
// tier's id).
function aapFetchTierGradesByName($conn, $tier_name) {
    $stmt = $conn->prepare("
        SELECT g.id, g.grade_name AS name
        FROM aap_approval_unit_tiers t
        JOIN aap_approval_unit_tier_grades tg ON tg.tier_id = t.id
        JOIN staff_grade g ON g.id = tg.grade_id
        WHERE t.department_id IS NULL AND t.tier_name = ?
        ORDER BY g.id ASC
    ");
    $stmt->bind_param("s", $tier_name);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    foreach ($rows as &$r) $r['id'] = (int)$r['id'];
    return $rows;
}

// Staff who occupy this Group's tier row - either automatically (their
// grade falls under this tier NAME, via the Universal list's fixed
// grade<->tier pairing) or because admin manually assigned them here
// (aap_approval_unit_tier_staff), which overrides their grade default -
// see assign_tier_staff/unassign_tier_staff below. A staff member manually
// assigned to ANY tier within this SAME Group is excluded from their
// grade-default tier there, so they only ever show up once per Group - but
// the same person can independently appear in a different Group too (a
// staff member can belong to more than one Group within a department).
function aapFetchDepartmentTierStaff($conn, $department_id, $group_id, $tier_row_id, $tier_name) {
    $stmt = $conn->prepare("
        SELECT s.id, s.nama_staff AS name, 0 AS is_override
        FROM staff s
        WHERE s.recycle != 1
          AND FIND_IN_SET(?, s.department)
          AND s.grade IN (
              SELECT tg.grade_id
              FROM aap_approval_unit_tier_grades tg
              JOIN aap_approval_unit_tiers t ON t.id = tg.tier_id
              WHERE t.department_id IS NULL AND t.tier_name = ?
          )
          AND s.id NOT IN (
              SELECT ts.staff_id
              FROM aap_approval_unit_tier_staff ts
              JOIN aap_approval_unit_tiers t2 ON t2.id = ts.tier_id
              WHERE t2.group_id = ?
          )
        UNION
        SELECT s2.id, s2.nama_staff AS name, 1 AS is_override
        FROM aap_approval_unit_tier_staff ts2
        JOIN staff s2 ON s2.id = ts2.staff_id
        WHERE ts2.tier_id = ?
        ORDER BY name ASC
    ");
    $stmt->bind_param("isii", $department_id, $tier_name, $group_id, $tier_row_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    foreach ($rows as &$r) { $r['id'] = (int)$r['id']; $r['is_override'] = (int)$r['is_override'] === 1; }
    return $rows;
}

// staff_grade values combined into one Default tier (Default Tiers only).
function aapFetchTierGrades($conn, $tier_id) {
    $stmt = $conn->prepare("
        SELECT g.id, g.grade_name AS name
        FROM aap_approval_unit_tier_grades tg
        JOIN staff_grade g ON g.id = tg.grade_id
        WHERE tg.tier_id = ?
        ORDER BY g.id ASC
    ");
    $stmt->bind_param("i", $tier_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    foreach ($rows as &$r) $r['id'] = (int)$r['id'];
    return $rows;
}

// ---- AJAX: fetch every Group a department has set up (each with its own
// tier list) + whether it has any Groups at all (Custom) or is still empty
// (follows the Universal list above). ----
if (isset($_GET['action']) && $_GET['action'] === 'get_department_groups' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    ob_end_clean();
    header('Content-Type: application/json');
    $dept_id = (int)($_GET['department_id'] ?? 0);
    if ($dept_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid department.']);
        exit;
    }
    $groups = aapFetchDepartmentGroups($conn, $dept_id);
    echo json_encode(['success' => true, 'is_custom' => count($groups) > 0, 'groups' => $groups]);
    exit;
}

// ---- AJAX: create a new Group for a department - seeds its tier list with
// a fresh copy of the current Universal tiers (same fixed 6 rows), which
// this Group's own Edit/Assign Staff actions then work on independently. ----
if (isset($_POST['action']) && $_POST['action'] === 'add_group' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_end_clean();
    header('Content-Type: application/json');
    $dept_id = (int)($_POST['department_id'] ?? 0);
    $group_name = trim($_POST['group_name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    if ($dept_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid department.']);
        exit;
    }
    if ($group_name === '') {
        $count_res = $conn->query("SELECT COUNT(*) c FROM aap_approval_unit_tier_groups WHERE department_id = " . $dept_id);
        $group_name = 'Group ' . ((int)$count_res->fetch_assoc()['c'] + 1);
    }
    $now = date('Y-m-d H:i:s');
    $sort_res = $conn->query("SELECT COALESCE(MAX(sort_order), 0) + 1 AS n FROM aap_approval_unit_tier_groups WHERE department_id = " . $dept_id);
    $sort_order = (int)$sort_res->fetch_assoc()['n'];

    $stmt = $conn->prepare("INSERT INTO aap_approval_unit_tier_groups (department_id, group_name, description, sort_order, updated_by, timestamp) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("issiis", $dept_id, $group_name, $description, $sort_order, $id_user, $now);
    $stmt->execute();
    $group_id = $stmt->insert_id;
    $stmt->close();

    $defaults = aapFetchApprovalUnitTiers($conn, null);
    $stmt = $conn->prepare("INSERT INTO aap_approval_unit_tiers (department_id, group_id, tier_name, tier_value, sort_order, updated_by, timestamp) VALUES (?, ?, ?, ?, ?, ?, ?)");
    foreach ($defaults as $i => $t) {
        $t_sort = $i + 1;
        $stmt->bind_param("iisdiis", $dept_id, $group_id, $t['tier_name'], $t['tier_value'], $t_sort, $id_user, $now);
        $stmt->execute();
    }
    $stmt->close();

    echo json_encode(['success' => true, 'groups' => aapFetchDepartmentGroups($conn, $dept_id)]);
    exit;
}

// ---- AJAX: rename/re-describe a Group. ----
if (isset($_POST['action']) && $_POST['action'] === 'update_group' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_end_clean();
    header('Content-Type: application/json');
    $group_id = (int)($_POST['group_id'] ?? 0);
    $group_name = trim($_POST['group_name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    if ($group_id <= 0 || $group_name === '') {
        echo json_encode(['success' => false, 'message' => 'Group name is required.']);
        exit;
    }
    $group_dept_row = $conn->query("SELECT department_id FROM aap_approval_unit_tier_groups WHERE id = " . $group_id)->fetch_assoc();
    if (!$group_dept_row) {
        echo json_encode(['success' => false, 'message' => 'Invalid group.']);
        exit;
    }
    $now = date('Y-m-d H:i:s');
    $stmt = $conn->prepare("UPDATE aap_approval_unit_tier_groups SET group_name = ?, description = ?, updated_by = ?, timestamp = ? WHERE id = ?");
    $stmt->bind_param("ssisi", $group_name, $description, $id_user, $now, $group_id);
    $ok = $stmt->execute();
    $stmt->close();
    echo json_encode(['success' => $ok]);
    exit;
}

// ---- AJAX: delete one Group - removes its tier rows first (which cascades
// any staff overrides on them, via aap_approval_unit_tier_staff's ON DELETE
// CASCADE on tier_id), then the Group row itself. ----
if (isset($_POST['action']) && $_POST['action'] === 'delete_group' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_end_clean();
    header('Content-Type: application/json');
    $group_id = (int)($_POST['group_id'] ?? 0);
    if ($group_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid group.']);
        exit;
    }
    $dept_row = $conn->query("SELECT department_id FROM aap_approval_unit_tier_groups WHERE id = " . $group_id)->fetch_assoc();
    if (!$dept_row) {
        echo json_encode(['success' => false, 'message' => 'Invalid group.']);
        exit;
    }
    $conn->query("DELETE FROM aap_approval_unit_tiers WHERE group_id = " . $group_id);
    $conn->query("DELETE FROM aap_approval_unit_tier_groups WHERE id = " . $group_id);
    echo json_encode(['success' => true, 'groups' => $dept_row ? aapFetchDepartmentGroups($conn, (int)$dept_row['department_id']) : []]);
    exit;
}

// ---- AJAX: staff pool for the "Assign Staff" picker in one department. ----
if (isset($_GET['action']) && $_GET['action'] === 'get_dept_staff_pool' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    ob_end_clean();
    header('Content-Type: application/json');
    $dept_id = (int)($_GET['department_id'] ?? 0);
    if ($dept_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid department.']);
        exit;
    }
    echo json_encode(['success' => true, 'staff' => aapFetchDeptStaffPool($conn, $dept_id)]);
    exit;
}

// ---- AJAX: manually assign one staff member to a specific Group's tier
// row - overrides their grade-default tier within that Group. Removes any
// prior override for them in this same Group first (one tier per staff per
// Group, same convention as assign_tier_grade) - they can still separately
// hold a different assignment in another Group. ----
if (isset($_POST['action']) && $_POST['action'] === 'assign_tier_staff' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_end_clean();
    header('Content-Type: application/json');
    $tier_id = (int)($_POST['tier_id'] ?? 0);
    $staff_id = (int)($_POST['staff_id'] ?? 0);
    if ($tier_id <= 0 || $staff_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid tier or staff.']);
        exit;
    }
    $tier_row = $conn->query("SELECT department_id, group_id FROM aap_approval_unit_tiers WHERE id = " . $tier_id)->fetch_assoc();
    if (!$tier_row || $tier_row['department_id'] === null || $tier_row['group_id'] === null) {
        echo json_encode(['success' => false, 'message' => 'Invalid group tier.']);
        exit;
    }
    $conn->query("
        DELETE ts FROM aap_approval_unit_tier_staff ts
        JOIN aap_approval_unit_tiers t ON t.id = ts.tier_id
        WHERE t.group_id = " . (int)$tier_row['group_id'] . " AND ts.staff_id = " . $staff_id
    );
    $now = date('Y-m-d H:i:s');
    $stmt = $conn->prepare("INSERT INTO aap_approval_unit_tier_staff (tier_id, staff_id, created_by, timestamp) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("iiis", $tier_id, $staff_id, $id_user, $now);
    $ok = $stmt->execute();
    $stmt->close();
    echo json_encode(['success' => $ok]);
    exit;
}

// ---- AJAX: remove a manual staff assignment - staff member reverts to
// their grade-default tier in this department. ----
if (isset($_POST['action']) && $_POST['action'] === 'unassign_tier_staff' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_end_clean();
    header('Content-Type: application/json');
    $tier_id = (int)($_POST['tier_id'] ?? 0);
    $staff_id = (int)($_POST['staff_id'] ?? -1);
    $tier_row = $conn->query("SELECT department_id FROM aap_approval_unit_tiers WHERE id = " . $tier_id)->fetch_assoc();
    if (!$tier_row) {
        echo json_encode(['success' => false, 'message' => 'Invalid tier.']);
        exit;
    }
    $conn->query("DELETE FROM aap_approval_unit_tier_staff WHERE tier_id = $tier_id AND staff_id = $staff_id");
    echo json_encode(['success' => true]);
    exit;
}

// ---- AJAX: edit an existing tier row's RM value in place (Universal or a
// department's own copy - both are now a fixed 6-row-per-grade set, so this
// only ever changes tier_value, never tier_name/adds/removes a row). ----
if (isset($_POST['action']) && $_POST['action'] === 'update_tier' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_end_clean();
    header('Content-Type: application/json');
    $tier_id = (int)($_POST['id'] ?? 0);
    $tier_name = trim($_POST['tier_name'] ?? '');
    $unlimited = ($_POST['unlimited'] ?? '') === '1';
    $tier_value = $unlimited ? null : (float)($_POST['tier_value'] ?? 0);

    if ($tier_id <= 0 || $tier_name === '') {
        echo json_encode(['success' => false, 'message' => 'Tier name is required.']);
        exit;
    }
    $stmt = $conn->prepare("UPDATE aap_approval_unit_tiers SET tier_value = ?, updated_by = ?, timestamp = ? WHERE id = ? AND tier_name = ?");
    $now = date('Y-m-d H:i:s');
    $stmt->bind_param("disis", $tier_value, $id_user, $now, $tier_id, $tier_name);
    $ok = $stmt->execute();
    $stmt->close();
    echo json_encode(['success' => $ok]);
    exit;
}

// How many existing Case Type Staff Tier assignments (aap_case_type_staff_tiers)
// currently rely on a given tier NAME resolving through a given list
// (department_id null = Default, otherwise one department's own list) -
// feeds the impact-warning confirm dialog in js/aap_grouping_master.js
// before a tier's RM value is changed or the tier is removed, since both
// take effect immediately and silently for everyone holding that tier name
// (aapTierNameCoversValue() resolves live, nothing is snapshotted onto the
// assignment row). For a Default-list tier, "affected" means every
// assignment in a department that has NOT overridden that same tier name
// itself (a department with its own row of the same name is shielded from
// the Default change).
function aapTierImpact($conn, $department_id, $tier_name) {
    if ($department_id === null) {
        $stmt = $conn->prepare("
            SELECT COUNT(*) AS n, COUNT(DISTINCT case_type_id) AS ct
            FROM aap_case_type_staff_tiers
            WHERE tier = ?
              AND department_id NOT IN (
                  SELECT department_id FROM aap_approval_unit_tiers
                  WHERE tier_name = ? AND department_id IS NOT NULL
              )
        ");
        $stmt->bind_param("ss", $tier_name, $tier_name);
    } else {
        $stmt = $conn->prepare("
            SELECT COUNT(*) AS n, COUNT(DISTINCT case_type_id) AS ct
            FROM aap_case_type_staff_tiers
            WHERE tier = ? AND department_id = ?
        ");
        $stmt->bind_param("si", $tier_name, $department_id);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return ['staff_rows' => (int)$row['n'], 'case_types' => (int)$row['ct']];
}

// ---- AJAX: impact of changing/removing one tier NAME, before the edit is
// committed - see aapTierImpact() above. ----
if (isset($_GET['action']) && $_GET['action'] === 'get_tier_impact' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    ob_end_clean();
    header('Content-Type: application/json');
    $dept_id = ($_GET['department_id'] ?? '') !== '' ? (int)$_GET['department_id'] : null;
    $tier_name = trim($_GET['tier_name'] ?? '');
    if ($tier_name === '') {
        echo json_encode(['success' => false, 'message' => 'Invalid tier.']);
        exit;
    }
    echo json_encode(array_merge(['success' => true], aapTierImpact($conn, $dept_id, $tier_name)));
    exit;
}

// ---- AJAX: revert a department back to following the shared Universal
// list - deletes every Group it has (and their tiers/staff overrides with
// them). ----
if (isset($_POST['action']) && $_POST['action'] === 'revert_department' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_end_clean();
    header('Content-Type: application/json');
    $dept_id = (int)($_POST['department_id'] ?? 0);
    if ($dept_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid department.']);
        exit;
    }
    $conn->query("DELETE FROM aap_approval_unit_tiers WHERE department_id = " . $dept_id);
    $conn->query("DELETE FROM aap_approval_unit_tier_groups WHERE department_id = " . $dept_id);
    echo json_encode(['success' => true]);
    exit;
}

$departments = aapFetchDepartments($conn);
$default_tiers = aapFetchApprovalUnitTiers($conn, null);
$aap_base = '../';
?>

<?php include('../aap_modern_head.php'); ?>

<div class="header">
  <b class="rtop"><b class="r1"></b><b class="r2"></b><b class="r3"></b><b class="r4"></b></b>
  <h1 class="headerH1"><img src="../img/logo.svg"> Approval Unit Master (Admin)</h1>
  <b class="rbottom"><b class="r4"></b><b class="r3"></b><b class="r2"></b><b class="r1"></b></b>
</div>

<?php include('../aap_sidebar.php'); ?>

<div class="aap-modern">
<div class="aap-card alpro-mt-20">
    <h3 class="aap-card-title">Universal</h3>
    <p class="alpro-muted" style="font-size:12px; margin-top:-6px;">
        One fixed tier per staff grade - CEO/Board is always Unlimited. Edit a row's RM value to adjust that grade's approval ceiling.
    </p>
    <table class="alpro-table aap-ct-list-table" id="default_tier_table" width="100%" style="max-width:760px;">
        <thead><tr><th>Grade</th><th>Tier</th><th>Value</th><th>Unlimited</th><th>Action</th></tr></thead>
        <tbody id="default_tier_tbody"></tbody>
    </table>
</div>

<div class="aap-card alpro-mt-20">
    <h3 class="aap-card-title">Department Tiers</h3>
    <p class="alpro-muted" style="font-size:12px; margin-top:-6px;">
        Every department, collapsed by default - open one to see whether it follows the Universal tiers above, or has its own Group(s), each with independent RM values for the same 6 fixed tiers.
    </p>

    <div class="aap-filter-bar">
        <input class="alpro-input aap-filter-input" type="text" id="dept_search" placeholder="Search department..." style="max-width:400px;">
    </div>

    <div id="dept_list_container">
    <?php foreach ($departments as $dept): $did = (int)$dept['id']; ?>
    <details class="aap-dept-tier-block" data-dept-id="<?php echo $did; ?>" style="border:1px solid #e5e9ec; border-radius:6px; margin-bottom:8px;">
        <summary style="cursor:pointer; padding:10px 14px; font-weight:600;"><?php echo htmlspecialchars($dept['depart_name']); ?></summary>
        <div style="padding:2px 14px 14px;">
            <p class="dept-status" style="margin:6px 0 10px; font-size:13px;">Loading...</p>
            <button type="button" class="alpro-btn alpro-btn-blue dept-customize-btn" style="display:none; margin-bottom:12px;">Customize for this Department</button>
            <button type="button" class="alpro-btn alpro-btn-grey dept-revert-btn" style="display:none; margin-bottom:12px;">Revert to Universal</button>

            <div class="dept-edit" style="display:none;">
                <div class="dept-groups-container"></div>
                <button type="button" class="alpro-btn alpro-btn-grey dept-add-group-btn" style="margin-top:6px;">+ Add Group</button>
            </div>
        </div>
    </details>
    <?php endforeach; ?>
    </div>

    <div id="dept_pagination" class="aap-pagination-bar">
        <div class="aap-page-size-group">
            Show
            <select class="alpro-input aap-page-size-select" id="dept_page_size">
                <option value="10">10</option>
                <option value="25">25</option>
                <option value="30">30</option>
                <option value="50">50</option>
            </select>
            entries
        </div>
        <div class="aap-page-info">
            <span id="dept_page_showing" class="aap-page-showing"></span>
            <div id="dept_page_numbers" class="aap-page-numbers"></div>
        </div>
    </div>
</div>
</div>

<script>
var AAP_GROUPING = {
    defaultTiers: <?php echo json_encode($default_tiers); ?>
};
</script>
<?php $page_js = '../js/aap_grouping_master.js'; include('../aap_footer.php'); ?>