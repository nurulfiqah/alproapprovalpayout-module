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

// $is_superadmin is the union of staff.aap/okr/atem (see
// aapFetchIsSuperAdmin) - an admin flagged in any one of the three modules
// gets full admin access in all of them, mirroring how OKR and ATEM already
// union each other's flag. $aap_level is the raw staff.aap value
// (aapFetchAapLevel()) - passed separately since $is_superadmin alone can't
// distinguish "has staff.aap = 1" from "is admin via grade/department/OKR/
// ATEM instead", which matters wherever that distinction is checked
// directly (e.g. aap_staff_assignments.php's Audit Trail visibility).
function aapIsAdmin($grade, $dept_ids, $is_superadmin = false, $aap_level = 0) {
    return ((int)$grade >= 4) || in_array(AAP_DEPT_DIGITAL_INNOVATION, $dept_ids, true) || $is_superadmin || (int)$aap_level >= 1;
}

// Whether this staff member manages Case Types / Approval Unit settings for
// EVERY department, versus being scoped to only their own - grade >= 4 or
// SuperAdmin only. Customer Support (dept 27) used to also get this
// automatically (they route/handle cases across every department), but that
// meant any CS staff could see/touch every OTHER department's Case
// Types/Approval Unit Groups/Staff Assignments even when not actually
// involved - removed so CS is scoped like anyone else: their own
// department, plus whatever they're explicitly granted via
// aap_department_managers (aapFetchDeptManagerDepartmentIds()). Deliberately
// NOT the same set as aapIsAdmin(): Digital Innovation staff (one of
// aapIsAdmin's paths, since they administer this module's settings pages
// generally) are still scoped to their own department here unless they
// separately qualify via grade/SuperAdmin - the two functions answer
// different questions (who can open an admin page vs. whose department data
// they see once inside it).
function aapCanManageAllDepartments($grade, $dept_ids, $is_superadmin = false) {
    return ((int)$grade >= 4) || $is_superadmin;
}

// Whether $department_id is within a staff member's own scope - either they
// manage every department (aapCanManageAllDepartments()), or it's one of
// their own (staff.department, via aapDeptIdsFromCsv()). Shared by
// admin/aap_admin.php's Case Type Level 1 Department picker/save/edit/list
// and admin/aap_grouping_master.php's Department Tiers list/AJAX actions.
function aapDeptInScope($department_id, $dept_ids, $can_manage_all) {
    return $can_manage_all || in_array((int)$department_id, $dept_ids, true);
}

// aap_department_managers - a per-department allowlist granting specific
// staff access to manage that department's Case Types (admin/aap_admin.php),
// Approval Unit Groups (admin/aap_grouping_master.php - their own
// department's Groups only, never the shared Universal list), and Staff
// Assignments lookups (admin/aap_staff_assignments.php). Deliberately
// ungated by grade or staff.department - being on this list is enough by
// itself, the same way fixit_department's Person Incharge names someone for
// a department without checking their grade. Always ADDITIVE: callers merge
// this into their own $dept_ids after computing $aap_can_manage_all_depts
// from the real grade/department signals, never before - a grant here must
// never be mistaken for company-wide "manage all departments" access just
// because one of the granted departments happens to be the one
// aapCanManageAllDepartments() otherwise keys off (Customer Support).
function aapFetchDeptManagerDepartmentIds($conn, $staff_id) {
    if (empty($staff_id)) return [];
    $stmt = $conn->prepare("SELECT department_id FROM aap_department_managers WHERE staff_id = ?");
    $stmt->bind_param("i", $staff_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return array_map(function ($r) { return (int)$r['department_id']; }, $rows);
}

// Re-queried independently at every entry point rather than cached in
// session, same convention as OKR's $_is_superadmin / ATEM's
// $db_is_superadmin.
// staff.aap only has one real admin level now (1 = full access to every AAP
// admin page/feature - Case Types, Approval Units, Staff Assignments,
// Department Managers, granting staff.aap itself, nothing hidden or
// blocked). The old two-tier "Admin 1" (general)/"Admin 2" (SuperAdmin)
// split was removed since there was never an actual reason to hold anything
// back from Admin 1 - any staff.aap value >= 1 now counts as full
// SuperAdmin-equivalent access for AAP's own purposes. staff.okr/staff.atem
// stay in this same union so a SuperAdmin from either sibling module still
// gets full AAP access too, same as before.
function aapFetchIsSuperAdmin($conn, $staff_id) {
    if (empty($staff_id)) return false;
    $stmt = $conn->prepare("SELECT aap, okr, atem FROM staff WHERE id = ?");
    $stmt->bind_param("i", $staff_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return false;
    return (int)$row['aap'] >= 1 || (int)$row['okr'] === 1 || (int)$row['atem'] === 1;
}

// The raw staff.aap level (0 or 1 - see aapFetchIsSuperAdmin() above for why
// there's no level 2 anymore) - needed wherever "has AAP admin access at
// all" (level 1) must be distinguished from "no access" (level 0), which a
// plain is-SuperAdmin boolean can't express on its own (a grade>=4/Digital
// Innovation/OKR-or-ATEM-SuperAdmin account is_superadmin=true without ever
// having a staff.aap row value). Passed into aapIsAdmin() below as its own
// OR path, same weight as grade>=4/Digital Innovation/SuperAdmin.
function aapFetchAapLevel($conn, $staff_id) {
    if (empty($staff_id)) return 0;
    $stmt = $conn->prepare("SELECT aap FROM staff WHERE id = ?");
    $stmt->bind_param("i", $staff_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? (int)$row['aap'] : 0;
}

// Resolves a staff member's grade/department/admin-level identity for the
// AAP module - every page calls this once right after lock_adv.php/
// common/index_adv.php resolve the real $grade/$department, in place of the
// old aapDeptIdsFromCsv()+aapIsAdmin() two-liner.
function aapResolveIdentity($conn, $id_user, $real_grade, $real_department) {
    $is_superadmin = aapFetchIsSuperAdmin($conn, $id_user);
    $aap_level = aapFetchAapLevel($conn, $id_user);

    $grade = $real_grade;
    $department = $real_department;
    $dept_ids = aapDeptIdsFromCsv($department);
    $is_admin = aapIsAdmin($grade, $dept_ids, $is_superadmin, $aap_level);

    return [
        'grade' => $grade,
        'department' => $department,
        'dept_ids' => $dept_ids,
        'is_admin' => $is_admin,
        'is_superadmin' => $is_superadmin,
        'aap_level' => $aap_level,
    ];
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
    $stmt = $conn->prepare("SELECT id, group_name, description FROM aap_approval_unit_tier_groups WHERE department_id = ? ORDER BY sort_order ASC, id ASC");
    $stmt->bind_param("i", $department_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    foreach ($rows as &$r) $r['id'] = (int)$r['id'];
    // Default (Universal) is always offered alongside any custom Group(s) -
    // a Case Type can still be assigned to a department's grade-based
    // default roster even after that department has since set up its own
    // Group(s), not just as a fallback when it has none at all.
    array_unshift($rows, ['id' => 0, 'group_name' => 'Default (Universal)', 'description' => '']);
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

// The department currently assigned to a Case Type's Level 2 ('approval') or
// Level 3 ('exclusion') section, or null if nothing's been assigned yet. All
// rows in one section always share the same department_id - the Staff Tier
// picker only ever adds one Group (one department) per level - so the first
// row's value stands in for the whole section.
function aapCaseTypeSectionDepartment($conn, $case_type_id, $section) {
    if (!$case_type_id) return null;
    $stmt = $conn->prepare("SELECT department_id FROM aap_case_type_staff_tiers WHERE case_type_id = ? AND section = ? LIMIT 1");
    $stmt->bind_param("is", $case_type_id, $section);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? (int)$row['department_id'] : null;
}

// Per-Case-Type edit rights, split by section - each is independently
// authorized, both for rendering admin/aap_admin.php's edit form and for the
// save handler's own server-side check (never trust the client's disabled/
// hidden fields alone).
//
// Level 1 (name/department/description/physical-confirm) belongs to
// whoever manages the Case Type's own department, same as before this
// feature existed.
//
// Level 2/3 are each a single delegated department's Group+staff roster.
// Deciding WHICH department holds a level (assigning it for the first time,
// or reassigning it to a different department later) is Level 1's call (or
// a full-scope admin's) - the receiving department doesn't get to hand
// itself a level, only curate its own roster once it's been handed one.
// Curating that roster (picking a specific Group, changing tiers) belongs to
// whichever department currently holds the level; if nothing's been
// assigned yet, that falls to whoever can do the assigning, since they're
// the one initializing it.
function aapCaseTypeEditRights($conn, $case_type_id, $ct_department_id, $dept_ids, $can_manage_all) {
    $can_edit_level1 = $can_manage_all || aapDeptInScope($ct_department_id, $dept_ids, $can_manage_all);
    $level2_dept = aapCaseTypeSectionDepartment($conn, $case_type_id, 'approval');
    $level3_dept = aapCaseTypeSectionDepartment($conn, $case_type_id, 'exclusion');

    $can_reassign_level2 = $can_edit_level1;
    $can_reassign_level3 = $can_edit_level1;
    $can_edit_level2_group = $can_manage_all || ($level2_dept !== null ? aapDeptInScope($level2_dept, $dept_ids, $can_manage_all) : $can_reassign_level2);
    $can_edit_level3_group = $can_manage_all || ($level3_dept !== null ? aapDeptInScope($level3_dept, $dept_ids, $can_manage_all) : $can_reassign_level3);

    return [
        'can_edit_level1' => $can_edit_level1,
        'can_reassign_level2' => $can_reassign_level2,
        'can_reassign_level3' => $can_reassign_level3,
        'can_edit_level2_group' => $can_edit_level2_group,
        'can_edit_level3_group' => $can_edit_level3_group,
        'level2_dept' => $level2_dept,
        'level3_dept' => $level3_dept,
    ];
}

// Whether $acting_dept_ids may hand-pick a specific Group when assigning a
// Case Type level to $target_department_id - only when it's their own
// department, or they're a full-scope admin. Handing a level to a
// department they don't belong to must fall back to that department's
// Default (Universal) roster (see aapDefaultUniversalTierRows()) instead of
// trusting whatever specific Group/staff the assigner posted - only the
// receiving department should ever curate a specific Group for itself.
function aapCaseTypeAllowedGroupForAssignment($target_department_id, $dept_ids, $can_manage_all) {
    return $can_manage_all || in_array((int)$target_department_id, $dept_ids, true);
}

// Builds Staff Tier rows (same shape as the client's serialized JSON) for a
// department's Default (Universal) roster - used to force that roster
// server-side instead of trusting a submitted Group/staff pick, whenever
// aapCaseTypeAllowedGroupForAssignment() says the assigner isn't entitled to
// hand-pick a specific Group for the department they're assigning a level to.
function aapDefaultUniversalTierRows($conn, $department_id) {
    $staff = aapFetchGroupStaffTiers($conn, $department_id, 0);
    $rows = [];
    foreach ($staff as $s) {
        $rows[] = ['staff_id' => $s['staff_id'], 'department_id' => $department_id, 'group_id' => null, 'tier' => $s['tier']];
    }
    return $rows;
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

// approval gate — replaced the old approver_mode/department-pool/grade
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
// Visibility: a staff member sees a case if they personally raised it, OR
// the case's Case Type belongs to their own department - i.e. what the
// case is actually ABOUT, not who happened to submit the form. Deliberately
// keyed off aap_case_types.department_id (via a subquery, so this stays
// usable in a plain `aap_cases c` query with no join needed) rather than
// aap_cases.requester_department_id, which is just the raiser's own
// department at raise time (still shown separately as "Requester
// Department" - see aapCaseSelectSql()'s requester_department_name) - a
// Customer Support/Operations/admin staff member raising a case on behalf
// of another department's Fixit ticket (see aap_add.php's aapDeptInScope()
// gate) must not silently hide that case from the department it's actually
// for.
function aapScopeWhere($staff_id, $dept_ids, $is_admin) {
    if ($is_admin || aapIsOperations($dept_ids)) return '1=1';
    $conds = ["c.created_by = " . (int)$staff_id];
    if (!empty($dept_ids)) {
        $ids = implode(',', array_map('intval', $dept_ids));
        $conds[] = "c.case_type_id IN (SELECT id FROM aap_case_types WHERE department_id IN ($ids))";
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

function aapFetchCaseNote($conn, $note_id, $case_id) {
    $stmt = $conn->prepare("SELECT * FROM aap_case_notes WHERE id = ? AND case_id = ?");
    $stmt->bind_param("ii", $note_id, $case_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row;
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
// of them can be notified once the case reaches the approval gate (see the
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

// Unread only - the bell's dropdown is a to-do list, not a permanent history
// (aap_audit_logs on the case itself is where the full history lives). Once
// a notification is marked read - either by clicking it directly, "Mark all
// read", or simply opening the case it's about (see
// aapMarkCaseNotificationsRead()) - it drops out of this list entirely
// rather than lingering with just a different background color.
function aapFetchNotifications($conn, $staff_id, $limit = 20) {
    $stmt = $conn->prepare("
        SELECT n.id, n.case_id, n.type, n.read_at, n.created_at, c.case_ref
        FROM aap_notifications n
        JOIN aap_cases c ON c.id = n.case_id
        WHERE n.staff_id = ? AND n.read_at IS NULL
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

// Called from aap_update.php on every page load - opening a case (by any
// route: clicking its notification, the Case Queue, a direct link) clears
// every one of this viewer's own unread notifications about it, not just
// the one they happened to click through. Scoped to $staff_id so viewing a
// case never marks some OTHER staff member's notification about the same
// case as read.
function aapMarkCaseNotificationsRead($conn, $case_id, $staff_id) {
    $stmt = $conn->prepare("UPDATE aap_notifications SET read_at = NOW() WHERE case_id = ? AND staff_id = ? AND read_at IS NULL");
    $stmt->bind_param("ii", $case_id, $staff_id);
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

// General-purpose audit log for admin pages whose actions aren't scoped to
// one case (aap_audit_logs.case_id is NOT NULL, so it can't be reused here)
// - e.g. admin/aap_staff_assignments.php's Remove/Reassign/Reassign All/
// Remove All actions, which act on a staff member's rows across many
// departments/Case Types at once, not a single case.
function aapLogAdminAudit($conn, $page, $event, $actor_id, $target_staff_id, $summary) {
    $now = date('Y-m-d H:i:s');
    $stmt = $conn->prepare("INSERT INTO aap_admin_audit_logs (page, event, actor_staff_id, target_staff_id, summary, timestamp) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ssiiss", $page, $event, $actor_id, $target_staff_id, $summary, $now);
    $stmt->execute();
    $stmt->close();
}

function aapFetchAdminAuditLogs($conn, $page, $limit = 50) {
    $stmt = $conn->prepare("
        SELECT al.*, actor.nama_staff AS actor_name, target.nama_staff AS target_name
        FROM aap_admin_audit_logs al
        LEFT JOIN staff actor ON actor.id = al.actor_staff_id
        LEFT JOIN staff target ON target.id = al.target_staff_id
        WHERE al.page = ?
        ORDER BY al.timestamp DESC, al.id DESC
        LIMIT ?
    ");
    $stmt->bind_param("si", $page, $limit);
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
               ct.department_id AS case_type_department_id,
               ctdept.depart_name AS case_type_department_name,
               fixit_o.code AS fixit_outlet_code,
               dept.depart_name AS requester_department_name,
               req.nama_staff AS requester_name,
               app.nama_staff AS approver_name,
               exe.nama_staff AS executor_name,
               tag.nama_staff AS physical_tagged_by_name,
               cfm.nama_staff AS physical_confirmed_by_name,
               susp.nama_staff AS suspended_by_name,
               bm.bank_name AS bank_name
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
        LEFT JOIN staff susp ON susp.id = c.suspended_by
        LEFT JOIN aap_bank_master bm ON bm.id = c.bank_id
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
    // AAP-LINK: efficiency freezes at the moment the AAP case was raised, not
    // whenever it eventually closes/rejects/voids - the approval/execution
    // workflow afterward can involve many people over a much longer stretch
    // of time than a normal Fixit resolution, none of which is the
    // outlet/raising staff's own turnaround, and this feeds their
    // incentive/performance metric. Falls back to now() if the case row
    // can't be found for some reason, matching the old always-live-clock
    // behavior rather than failing the whole update.
    $case_stmt = $conn->prepare("SELECT timestamp FROM aap_cases WHERE fixit_record_id = ? ORDER BY id DESC LIMIT 1");
    $case_stmt->bind_param("i", $fixit_record_id);
    $case_stmt->execute();
    $case_row = $case_stmt->get_result()->fetch_assoc();
    $case_stmt->close();
    $frozen_at = $case_row ? strtotime($case_row['timestamp']) : time();

    $efficiency = $frozen_at - strtotime($row['lodge']);
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
// filter bar on index.php. department_ids (int[] or null) scopes the
// queue to only tickets lodged against one of those departments - null
// means no scoping (full-list roles see every department). Every value is
// validated/escaped here rather than trusted from the caller.
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
    if (isset($filters['department_ids']) && is_array($filters['department_ids'])) {
        $ids = array_map('intval', $filters['department_ids']);
        // An empty scope (staff.department blank) must match nothing, not
        // "no filter at all" - a bare "IN ()" is invalid SQL, so fail closed
        // with an always-false condition instead.
        $where .= $ids ? " AND fr.department_id IN (" . implode(',', $ids) . ")" : " AND 1=0";
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
// $department_ids (int[] or null) scopes the option list to only what a
// department-scoped user would actually see in the queue itself (null =
// every department, for full-list roles).
function aapFetchIncomingFixitCategoryOptions($conn, $department_ids = null) {
    $dept_sql = '';
    if (is_array($department_ids)) {
        $ids = array_map('intval', $department_ids);
        $dept_sql = $ids ? " AND fr.department_id IN (" . implode(',', $ids) . ")" : " AND 1=0";
    }
    $rows = [];
    $res = $conn->query("
        SELECT DISTINCT fc.id, fc.category
        FROM fixit_category fc
        JOIN fixit_record fr ON fr.category_id = fc.id
        WHERE fc.aap_link = 1 AND NOT EXISTS (SELECT 1 FROM aap_cases c WHERE c.fixit_record_id = fr.id) $dept_sql
        ORDER BY fc.category ASC
    ");
    while ($res && $row = $res->fetch_assoc()) $rows[] = ['id' => (int)$row['id'], 'category' => $row['category']];
    return $rows;
}

function aapFetchIncomingFixitOutletOptions($conn, $department_ids = null) {
    $dept_sql = '';
    if (is_array($department_ids)) {
        $ids = array_map('intval', $department_ids);
        $dept_sql = $ids ? " AND fr.department_id IN (" . implode(',', $ids) . ")" : " AND 1=0";
    }
    $rows = [];
    $res = $conn->query("
        SELECT DISTINCT o.id, o.code
        FROM outlet o
        JOIN fixit_record fr ON fr.outlet = o.id
        JOIN fixit_category fc ON fc.id = fr.category_id
        WHERE fc.aap_link = 1 AND NOT EXISTS (SELECT 1 FROM aap_cases c WHERE c.fixit_record_id = fr.id) $dept_sql
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
    if (trim((string)$case['recommended_outcome']) === '') $missing[] = 'Report Description';
    if (empty($case['bank_id'])) $missing[] = 'Bank Name';
    if (trim((string)($case['bank_account_number'] ?? '')) === '') $missing[] = 'Account Number';
    if (trim((string)($case['bank_account_holder'] ?? '')) === '') $missing[] = 'Account Holder Name';
    return $missing;
}

// Shared by aap_update.php's 'edit_case' and 'open_case' action handlers -
// Open Case now submits the same Case Summary form as Save Changes (so the
// requester isn't forced to save first just to unlock the Open Case button),
// so both actions need to persist the same fields. Returns the saved field
// values (for the caller to re-check open-readiness against) or null if the
// submitted Case Type is invalid/retired.
function aapSaveCaseEditFields($conn, $id, $case, $post, $now) {
    $case_type_id = (int)($post['case_type_id'] ?? 0);
    $new_ct = aapFetchCaseType($conn, $case_type_id);
    if (!$new_ct || (int)$new_ct['recycle'] === 1) {
        return null;
    }

    $requester_type         = in_array($post['requester_type'] ?? null, ['customer', 'outlet', 'bu'], true) ? $post['requester_type'] : 'customer';
    $requesting_channel     = $case['requesting_channel'];
    $customer_membership_id = trim($post['customer_membership_id'] ?? '');
    $customer_name          = trim($post['customer_name'] ?? '');
    $bank_id                = !empty($post['bank_id']) ? (int)$post['bank_id'] : null;
    $bank_account_number    = trim($post['bank_account_number'] ?? '');
    $bank_account_holder    = trim($post['bank_account_holder'] ?? '');
    $transaction_ref        = trim($post['transaction_ref'] ?? '');
    $calculated_value       = (($post['calculated_value'] ?? '') !== '') ? (float)$post['calculated_value'] : null;
    $value_type             = in_array($post['value_type'] ?? null, ['cash', 'points'], true) ? $post['value_type'] : $case['value_type'];
    $recommended_outcome    = trim($post['recommended_outcome'] ?? '');

    $new_physical_required = (int)$new_ct['physical_confirm_required'];
    $new_physical_status   = $new_physical_required ? 'pending' : 'not_required';

    $stmt = $conn->prepare("
        UPDATE aap_cases SET
            case_type_id=?, requester_type=?, requesting_channel=?, customer_membership_id=?, customer_name=?,
            bank_id=?, bank_account_number=?, bank_account_holder=?, transaction_ref=?,
            calculated_value=?, value_type=?, recommended_outcome=?,
            physical_confirm_required=?, physical_confirm_status=?,
            updated_at=?
        WHERE id=?
    ");
    $stmt->bind_param(
        "issssisssdssissi",
        $case_type_id, $requester_type, $requesting_channel, $customer_membership_id, $customer_name,
        $bank_id, $bank_account_number, $bank_account_holder, $transaction_ref,
        $calculated_value, $value_type, $recommended_outcome,
        $new_physical_required, $new_physical_status,
        $now, $id
    );
    $stmt->execute(); $stmt->close();

    return [
        'case_type_id' => $case_type_id,
        'case_type_name' => $new_ct['case_type_name'],
        'calculated_value' => $calculated_value,
        'value_type' => $value_type,
        'recommended_outcome' => $recommended_outcome,
        'bank_id' => $bank_id,
        'bank_account_number' => $bank_account_number,
        'bank_account_holder' => $bank_account_holder,
        'physical_confirm_required' => $new_physical_required,
    ];
}

// Master list of banks for the Bank Detail dropdown (aap_add.php / aap_update.php).
function aapFetchBankMasterOptions($conn) {
    $rows = [];
    $res = $conn->query("SELECT id, bank_name FROM aap_bank_master WHERE recycle = 0 ORDER BY sort_order, bank_name");
    while ($row = $res->fetch_assoc()) $rows[] = $row;
    return $rows;
}

function aapFormatValue($value, $type) {
    if ($value === null || $value === '') return '—';
    if ($type === 'points') return number_format((float)$value, 0) . ' pts';
    return 'RM ' . number_format((float)$value, 2);
}

// The moment a case actually entered the Approval stage - used to tell
// evidence added during Approval (still editable/removable by whoever added
// it) apart from evidence carried over from Draft/Verification (frozen once
// Approval starts, see aapCaseEvidenceItemEditable()). There's no dedicated
// column for this, so it's read off whichever event marks that transition:
// physical_confirmed_at directly on the case when a physical return was
// required, or the audit log's 'case_opened' event when it wasn't (Open Case
// goes straight to Approval in that case).
function aapCaseApprovalStageStartedAt($conn, $case) {
    if ((int)$case['physical_confirm_required'] === 1) {
        return $case['physical_confirmed_at'];
    }
    return aapCaseOpenedAt($conn, $case['id']);
}

// When Open Case actually ran (the 'case_opened' audit event) - the start of
// the Verification stage (or, when no physical return is required, of the
// Approval stage directly - see aapCaseApprovalStageStartedAt above).
function aapCaseOpenedAt($conn, $case_id) {
    $stmt = $conn->prepare("SELECT `timestamp` FROM aap_audit_logs WHERE case_id = ? AND event = 'case_opened' ORDER BY id DESC LIMIT 1");
    $stmt->bind_param("i", $case_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? $row['timestamp'] : null;
}

// Start timestamp of whichever phase the case is CURRENTLY sitting in -
// mirrors aapCaseDisplayStatus()'s own phase classification (Investigation /
// Verification / Approval / Execution), one boundary earlier: the freeze
// below needs to know not just what phase this is, but exactly when it
// began, so that only evidence added since then counts as "added during the
// current phase". Null for a terminal case (closed/rejected/voided) - there
// is no "current phase" left to add anything into.
function aapCaseCurrentPhaseStartedAt($conn, $case) {
    $cs = $case['case_status'];
    if ($cs === 'draft') return $case['timestamp'];
    if (in_array($cs, ['closed', 'rejected', 'voided'], true)) return null;

    if ($cs === 'executed') {
        $phase_started_at = $case['executed_at'] ?: $case['approved_at'];
    } elseif ((int)$case['physical_confirm_required'] === 1 && in_array($case['physical_confirm_status'], ['pending', 'tagged'], true)) {
        // $cs === 'open' from here.
        $phase_started_at = aapCaseOpenedAt($conn, $case['id']);
    } elseif (in_array($case['approval_status'], ['approved', 'corrected'], true) && $case['execution_status'] === 'pending') {
        $phase_started_at = $case['approved_at'];
    } else {
        $phase_started_at = aapCaseApprovalStageStartedAt($conn, $case);
    }

    // Suspend (Execution sending a case back to Verification/Approval, see
    // aap_update.php's 'suspend_case' action) never re-triggers 'case_opened'
    // - case_status stays 'open' throughout, it never goes back to 'draft' -
    // so the phase-boundary lookups above would otherwise still point at the
    // ORIGINAL case_opened/approved_at from before the suspend, letting
    // evidence from that earlier run look like it's still "this phase".
    // Clamping to suspended_at floors it at the suspend itself instead,
    // which is always safe (a fresh cycle's own boundaries land after it).
    if (!empty($case['suspended_at']) && ($phase_started_at === null || strtotime($case['suspended_at']) > strtotime($phase_started_at))) {
        $phase_started_at = $case['suspended_at'];
    }
    return $phase_started_at;
}

// Per-item edit/delete gate for one note or attachment. $can_add_evidence is
// the outer active-case-window check (case not yet closed/rejected/voided) -
// once the case is terminal, nothing is editable regardless of who's asking,
// admin included. Inside that window, only the item's own author can touch
// it, and only if they added it during the CURRENT phase (see
// aapCaseCurrentPhaseStartedAt) - evidence carried over from an earlier
// phase (e.g. added during Approval) is frozen the moment the case moves
// into the next one (e.g. Execution), even for its own author, since
// whoever acted on the previous phase already reviewed it as-is. This freeze
// applies to everyone with no admin override - same person, same
// SuperAdmin/admin level, every phase: once the phase that added an item has
// ended, it's locked for good. $is_admin is accepted only so callers don't
// need special-casing, but it's intentionally unused here.
function aapCaseEvidenceItemEditable($item_created_by, $item_timestamp, $can_add_evidence, $id_user, $is_admin, $phase_started_at) {
    if (!$can_add_evidence) return false;
    if ($phase_started_at === null) return false;
    return (int)$item_created_by === (int)$id_user && strtotime($item_timestamp) >= strtotime($phase_started_at);
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
// always resolved further into Verification / Approval / Execute depending
// on where the case actually sits.
function aapCaseDisplayStatus($case) {
    $cs = $case['case_status'];
    if ($cs === 'draft')    return ['label' => 'Investigation', 'slug' => 'investigating'];
    // Voided and Rejected both mean the same thing to anyone looking at the
    // case from outside the approval workflow (it didn't proceed) - shown as
    // one "Rejected" status everywhere, even though case_status itself keeps
    // them distinct internally (different actor/trigger, see $can_void).
    if ($cs === 'voided' || $cs === 'rejected') return ['label' => 'Rejected', 'slug' => 'rejected'];
    // A case that was ever suspended (see aap_update.php's 'suspend_case'
    // action) keeps that flagged permanently once it finally closes, even
    // though suspend_count itself doesn't affect anything else about the
    // closed case - it's just a heads-up that this one didn't go straight
    // through on its first pass.
    if ($cs === 'closed') {
        if ((int)($case['suspend_count'] ?? 0) > 0) return ['label' => 'Closed (Suspended)', 'slug' => 'closed_suspended'];
        return ['label' => 'Closed', 'slug' => 'closed'];
    }
    // Execute and Close are separate steps/actions - 'executed' means the
    // case has been executed but is still waiting on the separate "Notify
    // Requester & Close Case" step (aap_update.php's 'close' action), not
    // finished yet, so it gets its own status rather than folding into
    // 'Closed'.
    if ($cs === 'executed') return ['label' => 'Closing', 'slug' => 'closing'];

    // $cs === 'open' from here on.
    if ((int)$case['physical_confirm_required'] === 1 && in_array($case['physical_confirm_status'], ['pending', 'tagged'], true)) {
        return ['label' => 'Verification', 'slug' => 'confirming'];
    }
    if (in_array($case['approval_status'], ['approved', 'corrected'], true) && $case['execution_status'] === 'pending') {
        // Flags a case that was ever suspended, same idea as the Closed
        // (Suspended) status - just a heads-up for whoever's about to
        // execute that this one already went through a Suspend/redo cycle.
        if ((int)($case['suspend_count'] ?? 0) > 0) return ['label' => 'Execute (Suspended)', 'slug' => 'executing_suspended'];
        return ['label' => 'Execute', 'slug' => 'executing'];
    }
    return ['label' => 'Approval', 'slug' => 'pending_approval'];
}

function aapRequesterTypeLabel($type) {
    $map = ['customer' => 'Customer-Type', 'outlet' => 'Outlet-Type', 'bu' => 'BU-Type'];
    return isset($map[$type]) ? $map[$type] : ucfirst($type);
}

function aapPhysicalStatusLabel($status) {
    $map = [
        'not_required' => 'Not Required',
        'pending'      => 'Pending Verification',
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
// Returns ['attempted' => n, 'uploaded' => n] so the caller can tell a real
// success from a NAS/network failure that silently dropped the file - a
// selected-but-failed file used to look identical to "nothing selected" to
// the user (both just produced no attachment row), with the audit log and
// success banner still claiming "Evidence attachment(s) added" either way.
function aapUploadEvidenceFiles($conn, $case_id, $files, $uploaded_by, $now) {
    $result = ['attempted' => 0, 'uploaded' => 0];
    if (empty($files) || !is_array($files['name'])) {
        return $result;
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
        $result['attempted']++;
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
        $result['uploaded']++;
    }
    return $result;
}

// Execution-only attachments (aap_case_execution_attachments) - kept as a
// separate table from aap_case_attachments (rather than a visibility flag on
// the same one) so a query that forgets to filter by visibility can never
// accidentally leak these to everyone else. Only shown/uploadable/
// downloadable to whoever can execute the case ($can_execute in
// aap_update.php) - the requester, approver, and everyone else viewing the
// case never sees this list exists.
// Returns ['attempted' => n, 'uploaded' => n] - same reasoning as
// aapUploadEvidenceFiles() above: lets the caller tell a real success from a
// NAS/network failure that silently dropped the file.
function aapUploadExecutionAttachments($conn, $case_id, $files, $uploaded_by, $now) {
    $result = ['attempted' => 0, 'uploaded' => 0];
    if (empty($files) || !is_array($files['name'])) {
        return $result;
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
        $result['attempted']++;
        $safe_name  = preg_replace('/[^A-Za-z0-9._-]/', '_', $fname);
        $tmp_name   = $case_id . '_exec_' . time() . '_' . $i . '_' . $safe_name;
        $tmp_path   = $tmp_dir . $tmp_name;
        if (!move_uploaded_file($files['tmp_name'][$i], $tmp_path)) {
            continue;
        }

        $nas_path = $nas->upload($tmp_path, CORP_NAS_FOLDER, $tmp_name);
        unlink($tmp_path);
        if ($nas_path === false) {
            continue;
        }

        $stmt = $conn->prepare("INSERT INTO aap_case_execution_attachments (case_id, file_name, stored_name, uploaded_by, timestamp) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("issis", $case_id, $fname, $nas_path, $uploaded_by, $now);
        $stmt->execute();
        $stmt->close();
        $result['uploaded']++;
    }
    return $result;
}

// Builds a one-line warning to append to the flash message when one or more
// selected files failed to actually reach the NAS (see
// aapUploadEvidenceFiles()/aapUploadExecutionAttachments() above) - without
// this, a NAS/network failure looked identical to "nothing selected" from
// the user's side: no error shown, the audit log and success banner both
// still said the attachment was added, and the file was just silently gone.
function aapUploadFailureNote($upload_result) {
    if (!empty($upload_result['attempted']) && $upload_result['uploaded'] < $upload_result['attempted']) {
        $failed = $upload_result['attempted'] - $upload_result['uploaded'];
        return " Warning: $failed file(s) failed to upload — check your connection and try again.";
    }
    return '';
}

// Shared by aap_export_cases.php and aap_export_close_cases.php - the CSV
// header and column order for a Case Queue export must stay identical
// between the two so a "closed" export just adds one extra trailing column
// rather than reordering everything.
function aapCaseExportCsvHeader() {
    return [
        'Case Ref', 'Fixit Ref', 'Case Type', 'Department/Outlet', 'Verification Required', 'Approval', 'Status', 'Raised', 'Closed',
        'Fixit Report', 'Fixit Report Description', 'Fixit Category', 'Fixit Outlet', 'Fixit Lodged By',
        'Calculated Value (Requestor)', 'Approved Value', 'Value Type',
        'Bank Name', 'Bank Account Number', 'Bank Account Holder Name',
        'Notes', 'Attachments',
    ];
}

// One CSV row for one case (a row from aapCaseSelectSql's result). Notes and
// attachments are flattened into single cells - a CSV row can't hold a
// nested repeating group. Execution-only attachments (aap_case_execution_
// attachments) are deliberately excluded - a CSV export has no equivalent of
// aap_update.php's $can_execute gate, so including them here would leak them
// to anyone who can export the case.
// Neutralizes CSV/formula injection (OWASP CSV Injection) - a free-text
// value starting with =, +, -, or @ is interpreted as a formula by Excel/
// Sheets when the exported file is opened, which could run arbitrary
// formulas (including ones that reach out to a URL) using nothing more than
// a case note or Fixit report someone typed. Prefixing with a tab keeps the
// visible text intact while stopping it from being read as a formula.
function aapCsvSafe($value) {
    if ($value === null || $value === '') return $value;
    $value = (string)$value;
    if (preg_match('/^[=+\-@]/', $value)) {
        return "\t" . $value;
    }
    return $value;
}

function aapCaseExportCsvRow($conn, $row) {
    $display_status = aapCaseDisplayStatus($row);
    $approval_display = aapApprovalStatusDisplay($row['approval_status']);
    $fixit = !empty($row['fixit_record_id']) ? aapFetchFixitRecord($conn, $row['fixit_record_id']) : null;

    $note_parts = [];
    if (!empty($row['evidence_note'])) {
        $note_parts[] = '[' . date('d-m-Y', strtotime($row['timestamp'])) . '] ' . $row['evidence_note'];
    }
    foreach (aapFetchCaseNotes($conn, $row['id']) as $note) {
        $note_parts[] = '[' . date('d-m-Y', strtotime($note['timestamp'])) . ' - ' . ($note['created_by_name'] ?: 'Unknown') . '] ' . $note['note'];
    }

    $attach_parts = [];
    $att_stmt = $conn->prepare("SELECT file_name FROM aap_case_attachments WHERE case_id = ? ORDER BY timestamp ASC");
    $att_stmt->bind_param("i", $row['id']);
    $att_stmt->execute();
    $att_res = $att_stmt->get_result();
    while ($att_row = $att_res->fetch_assoc()) {
        $attach_parts[] = $att_row['file_name'];
    }
    $att_stmt->close();

    return [
        $row['case_ref'],
        !empty($row['fixit_record_id']) ? 'F' . $row['fixit_record_id'] : '',
        $row['case_type_name'],
        $row['fixit_outlet_code'] ?: $row['case_type_department_name'],
        aapPhysicalStatusLabel($row['physical_confirm_status']),
        $approval_display['label'],
        $display_status['label'],
        date('d-m-Y', strtotime($row['timestamp'])),
        $row['closed_at'] ? date('d-m-Y', strtotime($row['closed_at'])) : '',
        aapCsvSafe($fixit['report'] ?? ''),
        aapCsvSafe($fixit['remark'] ?? ''),
        $fixit['category_name'] ?? '',
        $fixit['outlet_code'] ?? '',
        $fixit['lodged_by_name'] ?? '',
        $row['calculated_value'] !== null ? $row['calculated_value'] : '',
        $row['approved_value'] !== null ? $row['approved_value'] : '',
        $row['value_type'] ? ucfirst($row['value_type']) : '',
        $row['bank_name'] ?: '',
        $row['bank_account_number'] ?: '',
        aapCsvSafe($row['bank_account_holder'] ?: ''),
        aapCsvSafe(implode(' | ', $note_parts)),
        aapCsvSafe(implode(' | ', $attach_parts)),
    ];
}