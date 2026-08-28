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

function aapIsOperations($dept_ids) {
    return in_array(AAP_DEPT_OPERATION, $dept_ids, true);
}

function aapIsCustomerSupport($dept_ids) {
    return in_array(AAP_DEPT_CUSTOMER_SUPPORT, $dept_ids, true);
}

// $is_superadmin is the union of staff.aap/okr/atem (see
// aapFetchIsSuperAdmin) - a SuperAdmin flagged in any one of the three
// modules gets full admin access in all of them, mirroring how OKR and
// ATEM already union each other's flag.
function aapIsAdmin($grade, $dept_ids, $is_superadmin = false) {
    return ((int)$grade >= 4) || in_array(AAP_DEPT_DIGITAL_INNOVATION, $dept_ids, true) || $is_superadmin;
}

// Re-queried independently at every entry point rather than cached in
// session, same convention as OKR's $_is_superadmin / ATEM's
// $db_is_superadmin - staff.aap is this module's own SuperAdmin flag,
// unioned with staff.okr and staff.atem so admin access in either sibling
// module (both outside this repo) carries over here too.
function aapFetchIsSuperAdmin($conn, $staff_id) {
    if (empty($staff_id)) return false;
    $stmt = $conn->prepare("SELECT aap, okr, atem FROM staff WHERE id = ?");
    $stmt->bind_param("i", $staff_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return false;
    return (int)$row['aap'] === 1 || (int)$row['okr'] === 1 || (int)$row['atem'] === 1;
}

// Execution (CLS-equivalent) — Operations accounts only, regardless of value.
function aapCanExecute($grade, $dept_ids, $is_admin) {
    if ($is_admin) return true;
    return aapIsOperations($dept_ids) && (int)$grade >= 1;
}

// Per-staff approval ceiling (aap_staff_thresholds), managed inline from the
// "Staff in Pool" panels on aap_admin.php's Case Type form — replaced the
// old grade-based Executive/Manager tier and CS Level 1/2 system. No row means
// this staff member hasn't been given any approval authority; a row with
// threshold_amount = NULL means an explicit "unlimited" ceiling (can
// approve any value). Returns: null = unlimited, false = no ceiling
// assigned at all (no approval rights), or the numeric ceiling.
function aapGetStaffThreshold($conn, $staff_id) {
    $stmt = $conn->prepare("SELECT threshold_amount FROM aap_staff_thresholds WHERE staff_id = ?");
    $stmt->bind_param("i", $staff_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return false;
    return $row['threshold_amount'] === null ? null : (float)$row['threshold_amount'];
}

// Unified approval gate for all three approver_mode values. Being in the
// right pool (Operations OR Customer Support for cs_tier - Operations is
// included alongside CS since Operations can also act on CS-tier cases -
// Operations only for operations_tier, the requester's own department for
// bu_signoff) only makes a staff member eligible - they also need a
// personal ceiling (set by an admin) that covers the case's
// calculated_value, or to be admin.
//
// $ops_tier_required (the case's own copy of aap_case_types.ops_tier_required,
// only meaningful for cs_tier) is an extra grade-based filter applied ONLY to
// an Operations approver who is not also Customer Support - 'executive'
// needs grade >= 1, 'manager' needs grade >= 3, null/'' applies no extra
// filter. Customer Support staff are never subject to this - they're always
// ceiling-gated only, regardless of this setting.
function aapCanApprove($conn, $staff_id, $dept_ids, $approver_mode, $case_requester_dept_id, $value, $is_admin, $grade = 0, $ops_tier_required = null) {
    if ($is_admin) return true;

    if ($approver_mode === 'bu_signoff') {
        if (empty($case_requester_dept_id) || !in_array((int)$case_requester_dept_id, $dept_ids, true)) return false;
    } elseif ($approver_mode === 'cs_tier') {
        $is_cs = aapIsCustomerSupport($dept_ids);
        $is_ops = aapIsOperations($dept_ids);
        if (!$is_cs && !$is_ops) return false;
        if (!$is_cs && $is_ops && $ops_tier_required) {
            $min_grade = ($ops_tier_required === 'manager') ? 3 : 1;
            if ((int)$grade < $min_grade) return false;
        }
    } else {
        if (!aapIsOperations($dept_ids)) return false;
    }

    $ceiling = aapGetStaffThreshold($conn, $staff_id);
    if ($ceiling === false) return false;
    return $ceiling === null || (float)$value <= $ceiling;
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
    return 'AAP-' . str_pad((string)$id, 6, '0', STR_PAD_LEFT);
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

// Every staff member currently eligible to approve a case at this
// mode/value - mirrors aapCanApprove()'s pool + ceiling + ops-tier logic,
// but returns the full matching set instead of checking one staff member,
// so all of them can be notified once the case reaches the Approval Gate
// (see the open_case/tag_physical handlers in aap_update.php). Admins are
// excluded on purpose - there's no fixed "admin pool" to enumerate, and
// admins already see every case regardless of notifications.
function aapFetchEligibleApproverIds($conn, $approver_mode, $requester_dept_id, $value, $ops_tier_required) {
    $ids = [];
    $value = (float)$value;

    if ($approver_mode === 'bu_signoff') {
        if (empty($requester_dept_id)) return [];
        $res = $conn->query("
            SELECT s.id FROM staff s
            JOIN aap_staff_thresholds t ON t.staff_id = s.id
            WHERE s.recycle != 1 AND FIND_IN_SET(" . (int)$requester_dept_id . ", s.department)
              AND (t.threshold_amount IS NULL OR t.threshold_amount >= $value)
        ");
        while ($res && $row = $res->fetch_assoc()) $ids[] = (int)$row['id'];
        return $ids;
    }

    // Customer Support pool (cs_tier only) - ceiling gate, no grade filter.
    if ($approver_mode === 'cs_tier') {
        $res = $conn->query("
            SELECT s.id FROM staff s
            JOIN aap_staff_thresholds t ON t.staff_id = s.id
            WHERE s.recycle != 1 AND FIND_IN_SET(" . AAP_DEPT_CUSTOMER_SUPPORT . ", s.department)
              AND (t.threshold_amount IS NULL OR t.threshold_amount >= $value)
        ");
        while ($res && $row = $res->fetch_assoc()) $ids[] = (int)$row['id'];
    }

    // Operations pool (operations_tier, and also cs_tier since Operations
    // can act on CS-tier cases too) - ceiling gate, plus a grade floor when
    // ops_tier_required is set (cs_tier only).
    $min_grade = ($approver_mode === 'cs_tier' && $ops_tier_required)
        ? (($ops_tier_required === 'manager') ? 3 : 1)
        : 0;
    $res = $conn->query("
        SELECT s.id FROM staff s
        JOIN aap_staff_thresholds t ON t.staff_id = s.id
        WHERE s.recycle != 1 AND FIND_IN_SET(" . AAP_DEPT_OPERATION . ", s.department)
          AND s.grade >= $min_grade
          AND (t.threshold_amount IS NULL OR t.threshold_amount >= $value)
    ");
    while ($res && $row = $res->fetch_assoc()) $ids[] = (int)$row['id'];

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
               ct.name AS case_type_name, ct.code AS case_type_code,
               ct.approver_mode AS case_type_approver_mode,
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

function aapFetchCaseTypes($conn, $include_recycled = false, $department_id = null) {
    $where = $include_recycled ? '1=1' : 'ct.recycle = 0';
    if ($department_id !== null) $where .= ' AND ct.department_id = ' . (int)$department_id;
    $res = $conn->query("
        SELECT ct.*
        FROM aap_case_types ct
        WHERE $where
        ORDER BY ct.sort_order ASC, ct.id ASC
    ");
    $rows = [];
    while ($res && $row = $res->fetch_assoc()) $rows[] = $row;
    return $rows;
}


function aapApproverModeLabel($mode) {
    $map = [
        'operations_tier' => 'Operations Tier',
        'bu_signoff'      => 'BU Sign-off',
        'cs_tier'         => 'Customer Support Tier',
    ];
    return isset($map[$mode]) ? $map[$mode] : ucfirst($mode);
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

// AAP-LINK: Fixit tickets that should surface in AAP automatically, without
// anyone needing to click a handoff link back in Fixit: lodged under a
// category ticked "AAP - Payout Approval" (fixit_category.aap_link) and not
// yet converted into an aap_cases row. AAP can't auto-create the case itself
// — case type, calculated value, etc. aren't captured at Fixit intake, and
// there's no category -> AAP Family mapping yet either — so this just
// surfaces the queue; a human still picks the Family/Case Type and raises it
// via aap_add.php (pre-filled with this ticket's id/ref only, no family_id).
function aapIncomingFixitWhereSql() {
    return "fc.aap_link = 1
          AND NOT EXISTS (SELECT 1 FROM aap_cases c WHERE c.fixit_record_id = fr.id)";
}

function aapFetchIncomingFixitTickets($conn, $limit = 20, $offset = 0) {
    $limit = (int)$limit;
    $offset = max(0, (int)$offset);
    $where = aapIncomingFixitWhereSql();
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

function aapCountIncomingFixitTickets($conn) {
    $where = aapIncomingFixitWhereSql();
    $res = $conn->query("
        SELECT COUNT(*) c
        FROM fixit_record fr
        INNER JOIN fixit_category fc ON fc.id = fr.category_id
        WHERE $where
    ");
    return $res ? (int)$res->fetch_assoc()['c'] : 0;
}

function aapFetchCaseType($conn, $id) {
    $stmt = $conn->prepare("SELECT ct.* FROM aap_case_types ct WHERE ct.id = ?");
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

// Single unified display status — collapses case_status plus the
// physical/approval/execution sub-statuses into one label + CSS slug, so
// every list/badge/pill in the module shows the same 7-state vocabulary
// instead of raw case_status. 'open' itself is never shown directly; it's
// always resolved further into Confirmation in Progress / Pending Approval /
// Execute in Progress depending on where the case actually sits.
function aapCaseDisplayStatus($case) {
    $cs = $case['case_status'];
    if ($cs === 'draft')    return ['label' => 'Investigation in Progress', 'slug' => 'investigating'];
    if ($cs === 'voided')   return ['label' => 'Voided', 'slug' => 'voided'];
    if ($cs === 'rejected') return ['label' => 'Rejected', 'slug' => 'rejected'];
    if ($cs === 'closed')   return ['label' => 'Closed', 'slug' => 'closed'];
    // 'executed' is the legacy pre-merge intermediate state (see aap_update.php's
    // 'execute' action) — functionally the same as "approved, not yet closed".
    if ($cs === 'executed') return ['label' => 'Execute in Progress', 'slug' => 'executing'];

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
