<?php
/**
 * AAP shared helpers — query builders, scope/permission logic, formatters.
 * Required by every page in this module (never routed to directly).
 */
require_once __DIR__ . '/nas_config.php';

// Department IDs (staff_department) this module keys off:
define('AAP_DEPT_OPERATION', 13);          // "Operation" — sole execution authority
define('AAP_DEPT_DIGITAL_INNOVATION', 16);  // Digital Innovation — module admin (BI/dev team)
define('AAP_DEPT_CUSTOMER_SUPPORT', 27);    // "Customer Support" — CS-tier approval gate

function aapDeptIdsFromCsv($csv) {
    if (empty($csv)) return [];
    $parts = explode(',', $csv);
    $ids = [];
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p !== '' && is_numeric($p)) $ids[] = (int)$p;
    }
    return $ids;
}

// Every department Case Types can be scoped to (staff_department, the outer
// app's shared table - no separate aap_department table). Includes
// departments with no Case Type yet, since aap_admin.php uses this to let an
// admin assign a first Case Type to a department - see
// aapFetchDepartmentsWithCaseTypes() for the requester-facing subset.
function aapFetchDepartments($conn) {
    $rows = [];
    $res = $conn->query("SELECT id, depart_name FROM staff_department ORDER BY depart_name");
    while ($res && $row = $res->fetch_assoc()) $rows[] = $row;
    return $rows;
}

// Departments that actually have at least one active Case Type - used by the
// Department picker on aap_add.php so requesters never see a department with
// nothing behind it.
function aapFetchDepartmentsWithCaseTypes($conn) {
    $rows = [];
    $res = $conn->query("
        SELECT DISTINCT sd.id, sd.depart_name
        FROM staff_department sd
        INNER JOIN aap_case_types ct ON ct.department_id = sd.id AND ct.recycle = 0
        ORDER BY sd.depart_name
    ");
    while ($res && $row = $res->fetch_assoc()) $rows[] = $row;
    return $rows;
}

// staff_grade (outer app's shared table, id 0-5) - used by
// admin/aap_grouping_master.php's Approval Unit grid so a grade renamed/added
// there (via ATEM's masterlist.php "+ Add Grade") shows up here with no code
// change.
function aapFetchGrades($conn) {
    $rows = [];
    $res = $conn->query("SELECT id, grade_name FROM staff_grade WHERE is_active = 1 ORDER BY id ASC");
    while ($res && $row = $res->fetch_assoc()) $rows[] = $row;
    return $rows;
}

function aapIsOperations($dept_ids) {
    return in_array(AAP_DEPT_OPERATION, $dept_ids, true);
}

function aapIsCustomerSupport($dept_ids) {
    return in_array(AAP_DEPT_CUSTOMER_SUPPORT, $dept_ids, true);
}

// $is_superadmin is the union of staff.aap=2/okr/atem (see
// aapFetchIsSuperAdmin) - a SuperAdmin flagged in any one of the three
// modules gets full admin access in all of them, mirroring how OKR and
// ATEM already union each other's flag. $aap_level is the raw staff.aap
// value (aapFetchAapLevel()) - level 1 ("Admin 1") also counts as general
// admin here even though it's below the level-2 SuperAdmin threshold that
// $is_superadmin already covers.
function aapIsAdmin($grade, $dept_ids, $is_superadmin = false, $aap_level = 0) {
    return ((int)$grade >= 4) || in_array(AAP_DEPT_DIGITAL_INNOVATION, $dept_ids, true) || $is_superadmin || (int)$aap_level >= 1;
}

// Whether this staff member manages Case Types / Approval Unit settings for
// EVERY department, versus being scoped to only their own - grade >= 4,
// SuperAdmin, or Customer Support (dept 27, since CS routes/handles cases
// across every department). Deliberately NOT the same set as aapIsAdmin():
// Digital Innovation staff (one of aapIsAdmin's paths, since they administer
// this module's settings pages generally) are still scoped to their own
// department here unless they separately qualify via grade/SuperAdmin - the
// two functions answer different questions (who can open an admin page vs.
// whose department data they see once inside it).
function aapCanManageAllDepartments($grade, $dept_ids, $is_superadmin = false) {
    return ((int)$grade >= 4) || $is_superadmin || aapIsCustomerSupport($dept_ids);
}

// Whether $department_id is within a staff member's own scope - either they
// manage every department (aapCanManageAllDepartments()), or it's one of
// their own (staff.department, via aapDeptIdsFromCsv()). Shared by
// admin/aap_admin.php's Case Type Level 1 Department picker/save/edit/list
// and admin/aap_grouping_master.php's Department Tiers list/AJAX actions.
function aapDeptInScope($department_id, $dept_ids, $can_manage_all) {
    return $can_manage_all || in_array((int)$department_id, $dept_ids, true);
}

// Re-queried independently at every entry point rather than cached in
// session, same convention as OKR's $_is_superadmin / ATEM's
// $db_is_superadmin. staff.aap is a LEVEL (0 = no access, 1 = "Admin 1"
// general admin, 2 = "Admin 2" full SuperAdmin) rather than a plain flag -
// same 0/1(/2) leveled single-column pattern already used elsewhere on
// staff (see e.g. atem's own "0 = normal user, 1 = superadmin" convention).
// Only level 2 counts as SuperAdmin here, unioned with staff.okr/atem
// (still plain 0/1 flags on their own modules) so admin access in either
// sibling module carries over. Level 1 only grants aapIsAdmin()-equivalent
// general admin access - see aapFetchAapLevel() below.
function aapFetchIsSuperAdmin($conn, $staff_id) {
    if (empty($staff_id)) return false;
    $stmt = $conn->prepare("SELECT aap, okr, atem FROM staff WHERE id = ?");
    $stmt->bind_param("i", $staff_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return false;
    return (int)$row['aap'] === 2 || (int)$row['okr'] === 1 || (int)$row['atem'] === 1;
}

// The raw staff.aap level (0/1/2) - needed wherever "Admin 1" (level 1)
// must be distinguished from "no access at all" (level 0), which a plain
// is-SuperAdmin boolean can't express. Passed into aapIsAdmin() below as
// its own OR path, same weight as grade>=4/Digital Innovation/SuperAdmin.
function aapFetchAapLevel($conn, $staff_id) {
    if (empty($staff_id)) return 0;
    $stmt = $conn->prepare("SELECT aap FROM staff WHERE id = ?");
    $stmt->bind_param("i", $staff_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? (int)$row['aap'] : 0;
}

// Execution (CLS-equivalent) — Operations accounts only, regardless of value.
function aapCanExecute($grade, $dept_ids, $is_admin) {
    if ($is_admin) return true;
    return aapIsOperations($dept_ids) && (int)$grade >= 1;
}

// aapGetStaffThreshold() / aap_staff_thresholds removed 2026-09 - a
// per-staff flat RM ceiling from an earlier design, superseded by the
// per-Case-Type Staff Tier model below. Its own save UI ("Staff in Pool"
// panel) had already been removed from admin/aap_admin.php and
// aapCanApprove() never consulted it, so it was a second, non-functional
// "ceiling" concept sitting next to the real one - removed rather than
// left to confuse anyone reading the admin pages. If a flat per-staff RM
// ceiling (independent of Case Type/department) is wanted again later,
// reintroduce it deliberately with a real UI and wire it into
// aapCanApprove(), rather than resurrecting the orphaned table.

// The Case Type Staff Tier picker (admin/aap_admin.php) used to work off a
// fixed Unlimited/Tier 1/Tier 2 vocabulary with a hardcoded $5,000 split -
// dropped in favour of directly mirroring whatever named tiers actually
// exist in the Approval Unit Master (admin/aap_grouping_master.php), so a
// department's real tier names/values (including any it added beyond the
// standard 3) show up correctly instead of being silently bucketed into the
// wrong-looking name. aap_case_type_staff_tiers.tier now stores that tier's
// real name (VARCHAR, no longer an ENUM) rather than a bucket label.

// Tier list to populate the picker's Tier dropdown for one department -
// that department's own tiers if it has customized the Approval Unit
// Master, otherwise the shared Default list. Same fallback rule as
// aapFetchApprovalUnitTiers() in admin/aap_grouping_master.php.
function aapFetchTierOptionsForDepartment($conn, $department_id) {
    $stmt = $conn->prepare("SELECT tier_name, tier_value FROM aap_approval_unit_tiers WHERE department_id = ? ORDER BY sort_order ASC, id ASC");
    $stmt->bind_param("i", $department_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    if (empty($rows)) {
        $res = $conn->query("SELECT tier_name, tier_value FROM aap_approval_unit_tiers WHERE department_id IS NULL ORDER BY sort_order ASC, id ASC");
        $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    }
    foreach ($rows as &$r) $r['tier_value'] = $r['tier_value'] !== null ? (float)$r['tier_value'] : null;
    return $rows;
}

// The RM value a given tier NAME actually grants - resolved against one
// specific Group when $group_id is given (exact match, no ambiguity even if
// the department has multiple Groups sharing the same tier names), or the
// old department/Universal fallback chain when it's null (legacy
// aap_case_type_staff_tiers rows assigned before group_id existed, or ones
// added via the "Default (Universal)" picker entry). Returns null if no
// tier by that name exists anywhere (treated as "covers nothing" by
// aapTierNameCoversValue() below, not as Unlimited).
function aapTierValueByName($conn, $department_id, $tier_name, $group_id = null) {
    if ($group_id) {
        $stmt = $conn->prepare("SELECT tier_value FROM aap_approval_unit_tiers WHERE group_id = ? AND tier_name = ?");
        $stmt->bind_param("is", $group_id, $tier_name);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) return ['found' => true, 'value' => $row['tier_value'] !== null ? (float)$row['tier_value'] : null];
        return ['found' => false, 'value' => null];
    }

    $stmt = $conn->prepare("SELECT tier_value FROM aap_approval_unit_tiers WHERE department_id = ? AND tier_name = ?");
    $stmt->bind_param("is", $department_id, $tier_name);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) return ['found' => true, 'value' => $row['tier_value'] !== null ? (float)$row['tier_value'] : null];

    $stmt = $conn->prepare("SELECT tier_value FROM aap_approval_unit_tiers WHERE department_id IS NULL AND tier_name = ?");
    $stmt->bind_param("s", $tier_name);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) return ['found' => true, 'value' => $row['tier_value'] !== null ? (float)$row['tier_value'] : null];

    return ['found' => false, 'value' => null];
}

// A tier's RM ceiling covers a case value if the tier is Unlimited (value
// null) or the case value doesn't exceed it. A tier name that no longer
// exists anywhere (e.g. removed from Approval Unit Master after being
// assigned) covers nothing - fails closed rather than silently granting
// approval.
function aapTierNameCoversValue($conn, $department_id, $tier_name, $value, $group_id = null) {
    $tier = aapTierValueByName($conn, $department_id, $tier_name, $group_id);
    if (!$tier['found']) return false;
    return $tier['value'] === null || (float)$value <= $tier['value'];
}

// Groups belonging to a department (id + name only) - feeds the "Select
// Group" dropdown on the Level 2/3 Staff Tier picker in admin/aap_admin.php.
// Full Group detail (tiers/staff) lives in admin/aap_grouping_master.php's
// aapFetchDepartmentGroups() - this is the lightweight picker-only version.
// A department that hasn't set up any Group yet (Approval Unit Master's
// Department Tiers not customized) gets a synthetic "Default (Universal)"
// entry (id 0) instead of an empty list, so the picker still works - see
// aapFetchGroupStaffTiers()'s id-0 branch for what it resolves to.
function aapFetchDepartmentGroupNames($conn, $department_id) {
    $stmt = $conn->prepare("SELECT id, group_name FROM aap_approval_unit_tier_groups WHERE department_id = ? ORDER BY sort_order ASC, id ASC");
    $stmt->bind_param("i", $department_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    foreach ($rows as &$r) $r['id'] = (int)$r['id'];
    if (empty($rows)) return [['id' => 0, 'group_name' => 'Default (Universal)']];
    return $rows;
}

// Every staff member belonging to one Group, each with the tier NAME they
// occupy there (grade-default or manually overridden - same resolution as
// admin/aap_grouping_master.php's aapFetchDepartmentTierStaff(), flattened
// across all of the Group's tier rows instead of one row at a time). Feeds
// the "Add" button on the Level 2/3 Staff Tier picker in admin/aap_admin.php,
// which bulk-adds every row returned here.
//
// group_id 0 is the synthetic "Default (Universal)" entry
// (aapFetchDepartmentGroupNames() above) for a department with no Groups set
// up at all - every staff member in the department, bucketed straight off
// the Universal list by grade, with no per-Group override to apply (there is
// no Group).
function aapFetchGroupStaffTiers($conn, $department_id, $group_id) {
    if ($group_id === 0) {
        $stmt = $conn->prepare("
            SELECT s.id, s.nama_staff AS name, t.tier_name
            FROM staff s
            JOIN aap_approval_unit_tier_grades tg ON tg.grade_id = s.grade
            JOIN aap_approval_unit_tiers t ON t.id = tg.tier_id AND t.department_id IS NULL
            WHERE s.recycle != 1 AND FIND_IN_SET(?, s.department)
            ORDER BY s.nama_staff ASC
        ");
        $stmt->bind_param("i", $department_id);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        $out = [];
        foreach ($rows as $r) {
            $out[] = ['staff_id' => (int)$r['id'], 'staff_name' => $r['name'], 'tier' => $r['tier_name'], 'group_id' => null];
        }
        return $out;
    }

    $stmt = $conn->prepare("SELECT id, tier_name FROM aap_approval_unit_tiers WHERE group_id = ? ORDER BY sort_order ASC, id ASC");
    $stmt->bind_param("i", $group_id);
    $stmt->execute();
    $tiers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $out = [];
    foreach ($tiers as $t) {
        $tier_row_id = (int)$t['id'];
        $stmt = $conn->prepare("
            SELECT s.id, s.nama_staff AS name
            FROM staff s
            WHERE s.recycle != 1
              AND FIND_IN_SET(?, s.department)
              AND s.grade IN (
                  SELECT tg.grade_id
                  FROM aap_approval_unit_tier_grades tg
                  JOIN aap_approval_unit_tiers ut ON ut.id = tg.tier_id
                  WHERE ut.department_id IS NULL AND ut.tier_name = ?
              )
              AND s.id NOT IN (
                  SELECT ts.staff_id
                  FROM aap_approval_unit_tier_staff ts
                  JOIN aap_approval_unit_tiers t2 ON t2.id = ts.tier_id
                  WHERE t2.group_id = ?
              )
            UNION
            SELECT s2.id, s2.nama_staff AS name
            FROM aap_approval_unit_tier_staff ts2
            JOIN staff s2 ON s2.id = ts2.staff_id
            WHERE ts2.tier_id = ?
        ");
        $stmt->bind_param("isii", $department_id, $t['tier_name'], $group_id, $tier_row_id);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        foreach ($rows as $r) {
            $out[] = ['staff_id' => (int)$r['id'], 'staff_name' => $r['name'], 'tier' => $t['tier_name'], 'group_id' => $group_id];
        }
    }
    return $out;
}

// Every staff+tier row assigned to a Case Type for one section ('approval'
// or 'exclusion') - see admin/aap_admin.php's Level 2/3 Staff Tier tables.
function aapFetchCaseTypeStaffTiers($conn, $case_type_id, $section) {
    $stmt = $conn->prepare("
        SELECT t.id, t.department_id, t.group_id, t.staff_id, t.tier, s.nama_staff AS staff_name
        FROM aap_case_type_staff_tiers t
        LEFT JOIN staff s ON s.id = t.staff_id
        WHERE t.case_type_id = ? AND t.section = ?
        ORDER BY t.id ASC
    ");
    $stmt->bind_param("is", $case_type_id, $section);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

// Approval gate — replaced the old approver_mode/department-pool/grade
// system entirely. A staff member can approve a case only if they're
// explicitly on that Case Type's Approval Staff Tier list (Level 2 in
// admin/aap_admin.php) with a tier that covers the case's value, AND they
// are not on the Exclusion list (Level 3) - exclusion always wins regardless
// of tier. Admins bypass both lists.
//
// Customer Support is a deliberate exception (not handled in here): CS staff
// raise and self-handle their own cases at any value, so callers should
// check that case first (requester is CS and is the acting staff member)
// before ever calling this function - see aap_update.php.
function aapCanApprove($conn, $staff_id, $case_type_id, $value, $is_admin) {
    if ($is_admin) return true;

    $stmt = $conn->prepare("SELECT 1 FROM aap_case_type_staff_tiers WHERE case_type_id = ? AND section = 'exclusion' AND staff_id = ?");
    $stmt->bind_param("ii", $case_type_id, $staff_id);
    $stmt->execute();
    $excluded = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    if ($excluded) return false;

    $stmt = $conn->prepare("SELECT tier, department_id, group_id FROM aap_case_type_staff_tiers WHERE case_type_id = ? AND section = 'approval' AND staff_id = ?");
    $stmt->bind_param("ii", $case_type_id, $staff_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return false;

    return aapTierNameCoversValue($conn, (int)$row['department_id'], $row['tier'], $value, $row['group_id'] !== null ? (int)$row['group_id'] : null);
}

// Visibility scope: Operations/admin see everything; everyone else sees cases
// they raised themselves or that were raised by their own department.
function aapScopeWhere($staff_id, $dept_ids, $is_admin) {
    if ($is_admin || aapIsOperations($dept_ids)) return '1=1';
    $conds = ["c.created_by = " . (int)$staff_id];
    foreach ($dept_ids as $did) {
        $conds[] = "c.requester_department_id = " . (int)$did;
    }
    return '(' . implode(' OR ', $conds) . ')';
}

function aapGenerateCaseRef($id) {
    return 'AAP' . (int)$id;
}

function aapLogAudit($conn, $case_id, $event, $actor_id, $summary, $changes = null) {
    $stmt = $conn->prepare("INSERT INTO aap_audit_logs (case_id, event, actor_staff_id, summary, changes, timestamp) VALUES (?, ?, ?, ?, ?, ?)");
    $changes_json = $changes !== null ? json_encode($changes) : null;
    $now = date('Y-m-d H:i:s');
    $stmt->bind_param("isisss", $case_id, $event, $actor_id, $summary, $changes_json, $now);
    $stmt->execute();
    $stmt->close();
}

// Additional notes added after case creation - separate from the original
// aap_cases.evidence_note captured at raise time (see aap_case_notes).
function aapFetchCaseNotes($conn, $case_id) {
    $stmt = $conn->prepare("
        SELECT n.*, s.nama_staff AS created_by_name
        FROM aap_case_notes n
        LEFT JOIN staff s ON s.id = n.created_by
        WHERE n.case_id = ?
        ORDER BY n.timestamp ASC
    ");
    $stmt->bind_param("i", $case_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function aapAddCaseNote($conn, $case_id, $note, $created_by) {
    $now = date('Y-m-d H:i:s');
    $stmt = $conn->prepare("INSERT INTO aap_case_notes (case_id, note, created_by, timestamp) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("isis", $case_id, $note, $created_by, $now);
    $stmt->execute();
    $stmt->close();
}

function aapUpdateCaseNote($conn, $note_id, $case_id, $note) {
    $stmt = $conn->prepare("UPDATE aap_case_notes SET note = ? WHERE id = ? AND case_id = ?");
    $stmt->bind_param("sii", $note, $note_id, $case_id);
    $stmt->execute();
    $stmt->close();
}

function aapDeleteCaseNote($conn, $note_id, $case_id) {
    $stmt = $conn->prepare("DELETE FROM aap_case_notes WHERE id = ? AND case_id = ?");
    $stmt->bind_param("ii", $note_id, $case_id);
    $stmt->execute();
    $stmt->close();
}

// In-app notifications (mirrors okr_notifications) - one row per event a
// staff member should be told about: the issuer on approved/rejected/closed,
// or an eligible approver on pending_approval (see
// aapFetchEligibleApproverIds() below). $type is a free-form string
// (aap_notifications.type has no ENUM constraint) rendered by
// js/aap-sidebar.js's snippet map.
function aapNotifyStaff($conn, $case_id, $recipient_staff_id, $type) {
    $stmt = $conn->prepare("INSERT INTO aap_notifications (staff_id, case_id, type) VALUES (?, ?, ?)");
    $stmt->bind_param("iis", $recipient_staff_id, $case_id, $type);
    $stmt->execute();
    $stmt->close();
}

// Every staff member currently eligible to approve a case at this Case
// Type/value - mirrors aapCanApprove()'s Staff Tier + exclusion logic, but
// returns the full matching set instead of checking one staff member, so all
// of them can be notified once the case reaches the Approval Gate (see the
// open_case/tag_physical handlers in aap_update.php). Admins are excluded on
// purpose - there's no fixed "admin pool" to enumerate, and admins already
// see every case regardless of notifications.
function aapFetchEligibleApproverIds($conn, $case_type_id, $value) {
    $value = (float)$value;
    $approval = aapFetchCaseTypeStaffTiers($conn, $case_type_id, 'approval');
    $excluded_ids = array_column(aapFetchCaseTypeStaffTiers($conn, $case_type_id, 'exclusion'), 'staff_id');

    $ids = [];
    foreach ($approval as $row) {
        if (in_array((int)$row['staff_id'], $excluded_ids, true)) continue;
        $group_id = $row['group_id'] !== null ? (int)$row['group_id'] : null;
        if (aapTierNameCoversValue($conn, (int)$row['department_id'], $row['tier'], $value, $group_id)) $ids[] = (int)$row['staff_id'];
    }
    return array_unique($ids);
}

function aapFetchNotifications($conn, $staff_id, $limit = 20) {
    $stmt = $conn->prepare("
        SELECT n.id, n.case_id, n.type, n.read_at, n.created_at, c.case_ref
        FROM aap_notifications n
        JOIN aap_cases c ON c.id = n.case_id
        WHERE n.staff_id = ?
        ORDER BY n.created_at DESC
        LIMIT ?
    ");
    $stmt->bind_param("ii", $staff_id, $limit);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function aapUnreadNotificationCount($conn, $staff_id) {
    $stmt = $conn->prepare("SELECT COUNT(*) AS n FROM aap_notifications WHERE staff_id = ? AND read_at IS NULL");
    $stmt->bind_param("i", $staff_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? (int)$row['n'] : 0;
}

function aapMarkNotificationRead($conn, $notification_id, $staff_id) {
    $stmt = $conn->prepare("UPDATE aap_notifications SET read_at = NOW() WHERE id = ? AND staff_id = ? AND read_at IS NULL");
    $stmt->bind_param("ii", $notification_id, $staff_id);
    $stmt->execute();
    $stmt->close();
}

function aapMarkAllNotificationsRead($conn, $staff_id) {
    $stmt = $conn->prepare("UPDATE aap_notifications SET read_at = NOW() WHERE staff_id = ? AND read_at IS NULL");
    $stmt->bind_param("i", $staff_id);
    $stmt->execute();
    $stmt->close();
}

function aapFetchAuditLogs($conn, $case_id) {
    $stmt = $conn->prepare("
        SELECT al.*, s.nama_staff AS actor_name
        FROM aap_audit_logs al
        LEFT JOIN staff s ON s.id = al.actor_staff_id
        WHERE al.case_id = ?
        ORDER BY al.timestamp ASC, al.id ASC
    ");
    $stmt->bind_param("i", $case_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($row = $res->fetch_assoc()) $rows[] = $row;
    $stmt->close();
    return $rows;
}

// Canonical case SELECT — every page that reads a case (or list of cases)
// goes through this so joined labels never drift.
function aapCaseSelectSql($where = '1=1', $order = 'c.timestamp DESC') {
    return "
        SELECT c.*,
               ct.case_type_name,
               ctdept.depart_name AS case_type_department_name,
               fixit_o.code AS fixit_outlet_code,
               dept.depart_name AS requester_department_name,
               req.nama_staff AS requester_name,
               app.nama_staff AS approver_name,
               exe.nama_staff AS executor_name,
               tag.nama_staff AS physical_tagged_by_name,
               cfm.nama_staff AS physical_confirmed_by_name
        FROM aap_cases c
        INNER JOIN aap_case_types ct ON ct.id = c.case_type_id
        LEFT JOIN staff_department ctdept ON ctdept.id = ct.department_id
        LEFT JOIN fixit_record fixit_r ON fixit_r.id = c.fixit_record_id
        LEFT JOIN outlet fixit_o ON fixit_o.id = fixit_r.outlet
        LEFT JOIN staff_department dept ON dept.id = c.requester_department_id
        LEFT JOIN staff req ON req.id = c.requester_staff_id
        LEFT JOIN staff app ON app.id = c.approver_staff_id
        LEFT JOIN staff exe ON exe.id = c.executor_staff_id
        LEFT JOIN staff tag ON tag.id = c.physical_tagged_by
        LEFT JOIN staff cfm ON cfm.id = c.physical_confirmed_by
        WHERE $where
        ORDER BY $order
    ";
}

function aapFetchCase($conn, $id) {
    $sql = aapCaseSelectSql('c.id = ' . (int)$id);
    $res = $conn->query($sql);
    return $res ? $res->fetch_assoc() : null;
}

// Distinct outlets among cases visible in this scope (via their linked
// Fixit ticket) - index.php's Outlet filter only lists outlets that
// actually have a case here, not the entire outlet master list.
function aapFetchCaseOutletOptions($conn, $scope_where) {
    $sql = "
        SELECT DISTINCT o.id, o.code
        FROM aap_cases c
        INNER JOIN fixit_record fr ON fr.id = c.fixit_record_id
        INNER JOIN outlet o ON o.id = fr.outlet
        WHERE $scope_where
        ORDER BY o.code ASC
    ";
    $res = $conn->query($sql);
    return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
}

function aapFetchCaseTypes($conn, $include_recycled = false, $department_id = null) {
    $where = $include_recycled ? '1=1' : 'ct.recycle = 0';
    if ($department_id !== null) $where .= ' AND ct.department_id = ' . (int)$department_id;
    $res = $conn->query("
        SELECT ct.*
        FROM aap_case_types ct
        WHERE $where
        ORDER BY ct.case_type_name ASC, ct.id ASC
    ");
    $rows = [];
    while ($res && $row = $res->fetch_assoc()) $rows[] = $row;
    return $rows;
}

// The originating Fixit report (fixit_record) for a case raised via the
// Fixit "Continue to Raise Approval Case" handoff (fixit/add_report.php).
// Surfaced read-only in AAP so approvers see what the requester actually
// reported in Fixit, not just the AAP-side fields re-entered on top of it.
function aapFetchFixitRecord($conn, $fixit_id) {
    if (empty($fixit_id)) return null;
    $stmt = $conn->prepare("
        SELECT fr.id, fr.report, fr.remark, fr.lodge, fr.lodge_op, fr.department_id, fr.category_id, fr.outlet AS outlet_id, fr.fix_status,
               fc.category AS category_name,
               sd.depart_name AS department_name,
               o.code AS outlet_code,
               s.nama_staff AS lodged_by_name
        FROM fixit_record fr
        LEFT JOIN fixit_category fc ON fc.id = fr.category_id
        LEFT JOIN staff_department sd ON sd.id = fr.department_id
        LEFT JOIN outlet o ON o.id = fr.outlet
        LEFT JOIN staff s ON s.id = fr.lodge_op
        WHERE fr.id = ?
    ");
    $stmt->bind_param("i", $fixit_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

// Files attached to the originating Fixit report (fixit_attachment) -
// surfaced alongside aapFetchFixitRecord() so the requester's original
// photos/evidence are visible here too, not just the AAP-side uploads.
// Streamed via Fixit's own downloaded.php (id-keyed, not path-based), so no
// duplicate download endpoint is needed on the AAP side.
function aapFetchFixitAttachments($conn, $fixit_id) {
    if (empty($fixit_id)) return [];
    $stmt = $conn->prepare("SELECT id, name, size, type FROM fixit_attachment WHERE fixit_record_id = ? ORDER BY id");
    $stmt->bind_param("i", $fixit_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function aapFixitAttachmentUrl($attachment_id) {
    return '../fixit/downloaded.php?id=' . (int)$attachment_id;
}

function aapFixitTicketUrl($fixit_id) {
    return '../fixit/index.php?key=F' . (int)$fixit_id;
}

// AAP-LINK: mirrors the status-update logic fixit/index_specific.php itself
// runs when a staff member manually marks a ticket Completed/Cancelled (see
// fixit/changedforAAP.md) - `complete`/`complete_op`/`efficiency` set the
// same way, `handled_by` forced to '1' (Staff In Charge) with `incharge_id`
// set to the ticket's own raiser (lodge_op), since there's no real Fixit
// contractor here - AAP resolved it. No-op if the ticket is missing or
// already resolved (fix_status != 0), so this never overwrites a manual
// completion/cancellation that already happened on the Fixit side.
function aapSetLinkedFixitStatus($conn, $fixit_record_id, $actor_staff_id, $fix_status) {
    $fixit_record_id = (int)$fixit_record_id;
    if ($fixit_record_id <= 0) return;
    $stmt = $conn->prepare("SELECT lodge, lodge_op, fix_status FROM fixit_record WHERE id = ?");
    $stmt->bind_param("i", $fixit_record_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row || (int)$row['fix_status'] !== 0) return;

    $complete = date('Y-m-d H:i:s');
    $efficiency = time() - strtotime($row['lodge']);
    $incharge_id = (int)$row['lodge_op'];
    $actor_staff_id = (int)$actor_staff_id;
    $fix_status = (int)$fix_status;

    $stmt = $conn->prepare("UPDATE fixit_record SET fix_status = ?, complete = ?, complete_op = ?, efficiency = ?, handled_by = 1, incharge_id = ? WHERE id = ?");
    $stmt->bind_param("isiiii", $fix_status, $complete, $actor_staff_id, $efficiency, $incharge_id, $fixit_record_id);
    $stmt->execute();
    $stmt->close();
}

function aapCompleteLinkedFixitTicket($conn, $fixit_record_id, $completed_by_staff_id) {
    aapSetLinkedFixitStatus($conn, $fixit_record_id, $completed_by_staff_id, 1);
}

function aapCancelLinkedFixitTicket($conn, $fixit_record_id, $cancelled_by_staff_id) {
    aapSetLinkedFixitStatus($conn, $fixit_record_id, $cancelled_by_staff_id, 2);
}

// AAP-LINK: Fixit tickets that should surface in AAP automatically, without
// anyone needing to click a handoff link back in Fixit: lodged under a
// category ticked "AAP - Payout Approval" (fixit_category.aap_link) and not
// yet converted into an aap_cases row. AAP can't auto-create the case itself
// — case type, calculated value, etc. aren't captured at Fixit intake, and
// there's no category -> AAP Family mapping yet either — so this just
// surfaces the queue; a human still picks the Family/Case Type and raises it
// via aap_add.php (pre-filled with this ticket's id/ref only, no family_id).
// $filters (all optional): category_id (int), outlet_id (int), lodged_by
// (string, matched against staff.nama_staff), date (string 'YYYY-MM-DD',
// matched against DATE(fr.lodge)) - feeds the Incoming from Fixit tab's
// filter bar on index.php. Every value is validated/escaped here rather
// than trusted from the caller.
function aapIncomingFixitWhereSql($conn, $filters = []) {
    $where = "fc.aap_link = 1
          AND NOT EXISTS (SELECT 1 FROM aap_cases c WHERE c.fixit_record_id = fr.id)";
    if (!empty($filters['category_id'])) {
        $where .= " AND fc.id = " . (int)$filters['category_id'];
    }
    if (!empty($filters['outlet_id'])) {
        $where .= " AND fr.outlet = " . (int)$filters['outlet_id'];
    }
    if (!empty($filters['lodged_by'])) {
        $where .= " AND s.nama_staff LIKE '%" . $conn->real_escape_string($filters['lodged_by']) . "%'";
    }
    if (!empty($filters['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['date'])) {
        $where .= " AND DATE(fr.lodge) = '" . $conn->real_escape_string($filters['date']) . "'";
    }
    return $where;
}

function aapFetchIncomingFixitTickets($conn, $limit = 20, $offset = 0, $filters = []) {
    $limit = (int)$limit;
    $offset = max(0, (int)$offset);
    $where = aapIncomingFixitWhereSql($conn, $filters);
    $res = $conn->query("
        SELECT fr.id, fr.report, fr.remark, fr.lodge, fr.lodge_op, fr.outlet AS outlet_id,
               fc.category AS category_name,
               o.code AS outlet_code,
               s.nama_staff AS lodged_by_name
        FROM fixit_record fr
        INNER JOIN fixit_category fc ON fc.id = fr.category_id
        LEFT JOIN outlet o ON o.id = fr.outlet
        LEFT JOIN staff s ON s.id = fr.lodge_op
        WHERE $where
        ORDER BY fr.lodge DESC
        LIMIT $limit OFFSET $offset
    ");
    $rows = [];
    while ($res && $row = $res->fetch_assoc()) $rows[] = $row;
    return $rows;
}

// Category/Outlet dropdown options for the Incoming from Fixit filter bar -
// only ones that actually appear among incoming tickets (aap_link=1, not
// yet cased), not every category/outlet in the system.
function aapFetchIncomingFixitCategoryOptions($conn) {
    $rows = [];
    $res = $conn->query("
        SELECT DISTINCT fc.id, fc.category
        FROM fixit_category fc
        JOIN fixit_record fr ON fr.category_id = fc.id
        WHERE fc.aap_link = 1 AND NOT EXISTS (SELECT 1 FROM aap_cases c WHERE c.fixit_record_id = fr.id)
        ORDER BY fc.category ASC
    ");
    while ($res && $row = $res->fetch_assoc()) $rows[] = ['id' => (int)$row['id'], 'category' => $row['category']];
    return $rows;
}

function aapFetchIncomingFixitOutletOptions($conn) {
    $rows = [];
    $res = $conn->query("
        SELECT DISTINCT o.id, o.code
        FROM outlet o
        JOIN fixit_record fr ON fr.outlet = o.id
        JOIN fixit_category fc ON fc.id = fr.category_id
        WHERE fc.aap_link = 1 AND NOT EXISTS (SELECT 1 FROM aap_cases c WHERE c.fixit_record_id = fr.id)
        ORDER BY o.code ASC
    ");
    while ($res && $row = $res->fetch_assoc()) $rows[] = ['id' => (int)$row['id'], 'code' => $row['code']];
    return $rows;
}

function aapCountIncomingFixitTickets($conn, $filters = []) {
    $where = aapIncomingFixitWhereSql($conn, $filters);
    $res = $conn->query("
        SELECT COUNT(*) c
        FROM fixit_record fr
        INNER JOIN fixit_category fc ON fc.id = fr.category_id
        LEFT JOIN outlet o ON o.id = fr.outlet
        LEFT JOIN staff s ON s.id = fr.lodge_op
        WHERE $where
    ");
    return $res ? (int)$res->fetch_assoc()['c'] : 0;
}

function aapFetchCaseType($conn, $id) {
    $stmt = $conn->prepare("
        SELECT ct.*
        FROM aap_case_types ct
        WHERE ct.id = ?
    ");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row;
}

// Fields left optional at raise time (aap_add.php) so evidence gathering can
// continue while a case is a Draft, but required before Open Case can move
// it into the approval workflow. Returns a list of missing field labels -
// empty means ready to open.
function aapCaseOpenReadyErrors($case) {
    $missing = [];
    if ($case['calculated_value'] === null || $case['calculated_value'] === '') $missing[] = 'Calculated Value (Requestor)';
    if (empty($case['value_type'])) $missing[] = 'Value Type';
    if (trim((string)$case['recommended_outcome']) === '') $missing[] = 'Recommended Outcome';
    return $missing;
}

function aapFormatValue($value, $type) {
    if ($value === null || $value === '') return '—';
    if ($type === 'points') return number_format((float)$value, 0) . ' pts';
    return 'RM ' . number_format((float)$value, 2);
}

// 'corrected' is just an approved case whose value was adjusted on the way -
// shown as one "Approved" status everywhere (label + badge/pill slug), even
// though approval_status itself keeps them distinct internally.
function aapApprovalStatusDisplay($approval_status) {
    if ($approval_status === 'corrected') return ['label' => 'Approved', 'slug' => 'approved'];
    return ['label' => ucfirst($approval_status), 'slug' => $approval_status];
}

// Single unified display status — collapses case_status plus the
// physical/approval/execution sub-statuses into one label + CSS slug, so
// every list/badge/pill in the module shows the same 7-state vocabulary
// instead of raw case_status. 'open' itself is never shown directly; it's
// always resolved further into Confirmation in Progress / Pending Approval /
// Execute in Progress depending on where the case actually sits.
function aapCaseDisplayStatus($case) {
    $cs = $case['case_status'];
    if ($cs === 'draft')    return ['label' => 'Investigation in Progress', 'slug' => 'investigating'];
    // Voided and Rejected both mean the same thing to anyone looking at the
    // case from outside the approval workflow (it didn't proceed) - shown as
    // one "Rejected" status everywhere, even though case_status itself keeps
    // them distinct internally (different actor/trigger, see $can_void).
    if ($cs === 'voided' || $cs === 'rejected') return ['label' => 'Rejected', 'slug' => 'rejected'];
    // 'executed' is a legacy raw case_status value (see aap_update.php's
    // 'execute' action, which now sets case_status straight to 'closed') -
    // functionally the same finished state as 'closed', shown as one status.
    if ($cs === 'closed' || $cs === 'executed') return ['label' => 'Closed', 'slug' => 'closed'];

    // $cs === 'open' from here on.
    if ((int)$case['physical_confirm_required'] === 1 && in_array($case['physical_confirm_status'], ['pending', 'tagged'], true)) {
        return ['label' => 'Confirmation in Progress', 'slug' => 'confirming'];
    }
    if (in_array($case['approval_status'], ['approved', 'corrected'], true) && $case['execution_status'] === 'pending') {
        return ['label' => 'Execute in Progress', 'slug' => 'executing'];
    }
    return ['label' => 'Pending Approval', 'slug' => 'pending_approval'];
}

function aapRequesterTypeLabel($type) {
    $map = ['customer' => 'Customer-Type', 'outlet' => 'Outlet-Type', 'bu' => 'BU-Type'];
    return isset($map[$type]) ? $map[$type] : ucfirst($type);
}

function aapPhysicalStatusLabel($status) {
    $map = [
        'not_required' => 'Not Required',
        'pending'      => 'Pending Tag',
        'tagged'       => 'Tagged — Awaiting Confirmation',
        'confirmed'    => 'Confirmed',
    ];
    return isset($map[$status]) ? $map[$status] : ucfirst($status);
}

// Bootstrap Icons class + color for a compact, symbol-only rendering of
// physical_confirm_status (list tables) - the full label from
// aapPhysicalStatusLabel() is still used as the title/tooltip.
function aapPhysicalStatusIcon($status) {
    $map = [
        'not_required' => ['bi-dash-circle', '#adb5bd'],
        'pending'      => ['bi-hourglass-split', '#fd7e14'],
        'tagged'       => ['bi-tag-fill', '#0d6efd'],
        'confirmed'    => ['bi-check-circle-fill', '#198754'],
    ];
    return $map[$status] ?? ['bi-question-circle', '#adb5bd'];
}

// Evidence attachments live on the corporate NAS, never permanently under
// uploads/ (see nas_config.php / lib/synologynas.php) - uploads/tmp/ is only
// a transient stop because CorporateNAS::upload() needs a real filesystem
// path (CURLFile). Handles a standard multi-file $_FILES['evidence'] entry;
// the case row must already exist ($case_id known) before calling this, so
// unlike OKR's create flow there's no session-staging step needed here.
function aapUploadEvidenceFiles($conn, $case_id, $files, $uploaded_by, $now) {
    if (empty($files) || !is_array($files['name'])) {
        return;
    }

    $tmp_dir = __DIR__ . '/uploads/tmp/';
    if (!is_dir($tmp_dir)) {
        mkdir($tmp_dir, 0755, true);
    }

    $nas = corpNasConnect();
    foreach ($files['name'] as $i => $fname) {
        if ($files['error'][$i] !== UPLOAD_ERR_OK || $fname === '') {
            continue;
        }
        $safe_name  = preg_replace('/[^A-Za-z0-9._-]/', '_', $fname);
        $tmp_name   = $case_id . '_' . time() . '_' . $i . '_' . $safe_name;
        $tmp_path   = $tmp_dir . $tmp_name;
        if (!move_uploaded_file($files['tmp_name'][$i], $tmp_path)) {
            continue;
        }

        $nas_path = $nas->upload($tmp_path, CORP_NAS_FOLDER, $tmp_name);
        unlink($tmp_path);
        if ($nas_path === false) {
            continue;
        }

        $stmt = $conn->prepare("INSERT INTO aap_case_attachments (case_id, file_name, stored_name, uploaded_by, timestamp) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("issis", $case_id, $fname, $nas_path, $uploaded_by, $now);
        $stmt->execute();
        $stmt->close();
    }
}