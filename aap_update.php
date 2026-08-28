<?php
// lock_adv.php echoes HTML before the checks below can run, which would
// corrupt the binary file stream in the download branch further down - so
// buffer it here (same trick as okr/download.php) and discard it there;
// for normal page loads the buffer just flows through untouched at the end.
ob_start();
require_once('../lock_adv.php');
$connect = 1;
date_default_timezone_set('Asia/Kuala_Lumpur');
include('../common/index_adv.php');
if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection not available.");
}
require_once('aap_lib.php');

$aap_dept_ids = aapDeptIdsFromCsv($department);
$aap_is_admin = aapIsAdmin($grade, $aap_dept_ids, aapFetchIsSuperAdmin($conn, $id_user));

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$case = $id ? aapFetchCase($conn, $id) : null;
if (!$case) {
    die("Case not found.");
}

// Visibility scope check (mirrors index.php's aapScopeWhere)
$can_view = $aap_is_admin
    || aapIsOperations($aap_dept_ids)
    || (int)$case['created_by'] === (int)$id_user
    || in_array((int)$case['requester_department_id'], $aap_dept_ids, true);
if (!$can_view) {
    die("You do not have access to this case.");
}

// ---- File download (evidence attachment) ----
if (isset($_GET['download'])) {
    $att_id = (int)$_GET['download'];
    $stmt = $conn->prepare("SELECT * FROM aap_case_attachments WHERE id = ? AND case_id = ?");
    $stmt->bind_param("ii", $att_id, $id);
    $stmt->execute();
    $att = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($att) {
        $content = corpNasConnect()->download($att['stored_name']);
        if ($content !== false) {
            ob_end_clean();
            header('Content-Description: File Transfer');
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . basename($att['file_name']) . '"');
            header('Content-Length: ' . strlen($content));
            echo $content;
            exit;
        }
    }
    die("Attachment not found.");
}

$ct = aapFetchCaseType($conn, $case['case_type_id']);
$fixit_record = !empty($case['fixit_record_id']) ? aapFetchFixitRecord($conn, $case['fixit_record_id']) : null;
$fixit_attachments = !empty($case['fixit_record_id']) ? aapFetchFixitAttachments($conn, $case['fixit_record_id']) : [];

$can_tag_physical = ($case['requester_type'] === 'outlet' || $case['requester_type'] === 'customer') && ((int)$case['created_by'] === (int)$id_user || aapIsOperations($aap_dept_ids) || $aap_is_admin);

// Approval eligibility is now a single per-staff RM ceiling (aap_staff_thresholds,
// set from aap_admin.php) instead of grade-based Executive/Manager tiers or
// CS Level 1/2 - see aapCanApprove(). $case['ops_tier_required'] is the one
// remaining grade-based knob: an optional Executive/Manager floor applied
// only to an Operations (non-CS) approver on a cs_tier case.
$can_act_approval = aapCanApprove($conn, $id_user, $aap_dept_ids, $case['approver_mode'], $case['requester_department_id'], $case['calculated_value'], $aap_is_admin, $grade, $case['ops_tier_required']);
$can_execute = aapCanExecute($grade, $aap_dept_ids, $aap_is_admin);
$can_close   = $aap_is_admin || aapIsOperations($aap_dept_ids) || (int)$case['created_by'] === (int)$id_user;
$can_void    = (in_array($case['case_status'], ['draft', 'open'], true) && $case['approval_status'] === 'pending')
               && ((int)$case['created_by'] === (int)$id_user || $aap_is_admin);

// Draft: a new case sits here first so the issuer/CS team has time to
// gather evidence before it's opened for the approval workflow. Only the
// issuer or admin can open it, same population as $can_edit_case below.
$can_open_case = $case['case_status'] === 'draft'
               && ((int)$case['created_by'] === (int)$id_user || $aap_is_admin);

// Case Summary is only editable before a decision is made and before the
// physical-return workflow has actually started (RFID tagged/confirmed) —
// changing the Case Type mid-flight after tagging would desync the physical
// confirmation gate from whatever the new Case Type expects. Also editable
// while still a Draft, since that's exactly when evidence is being gathered.
$can_edit_case = (in_array($case['case_status'], ['draft', 'open'], true) && $case['approval_status'] === 'pending'
                   && in_array($case['physical_confirm_status'], ['not_required', 'pending'], true))
               && ((int)$case['created_by'] === (int)$id_user || $aap_is_admin);

$msg = "";
$msg_type = "";

// Flash message from the previous POST (see the redirect at the end of the
// action handler below) - shown once, then cleared, so a page refresh never
// re-shows it or resubmits the form.
if (isset($_SESSION['aap_flash_msg'])) {
    $msg = $_SESSION['aap_flash_msg'];
    $msg_type = $_SESSION['aap_flash_msg_type'];
    unset($_SESSION['aap_flash_msg'], $_SESSION['aap_flash_msg_type']);
}

// ---- Approval remark auto-save (AJAX) - separate from the main POST/redirect
// flow below since it must reply with JSON and not reload the page while the
// user is still typing. Free text only, doesn't itself approve/correct/reject
// - just keeps the draft remark saved so it isn't lost before a decision is made. ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ajax_save_remark' && $can_act_approval && $case['approval_status'] === 'pending') {
    ob_end_clean();
    header('Content-Type: application/json');
    $remark_text = trim($_POST['remark'] ?? '');
    $stmt = $conn->prepare("UPDATE aap_cases SET approval_remark = ? WHERE id = ?");
    $stmt->bind_param("si", $remark_text, $id);
    $stmt->execute(); $stmt->close();
    echo json_encode(['success' => true]);
    exit;
}

// ---- Action handler ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $now = date('Y-m-d H:i:s');

    if ($action === 'open_case' && $can_open_case) {
        $missing = aapCaseOpenReadyErrors($case);
        if (!empty($missing)) {
            $msg = "Fill in all required fields before opening this case: " . implode(', ', $missing) . "."; $msg_type = "alpro-danger";
        } else {
            $stmt = $conn->prepare("UPDATE aap_cases SET case_status='open', updated_at=? WHERE id=?");
            $stmt->bind_param("si", $now, $id);
            $stmt->execute(); $stmt->close();
            aapLogAudit($conn, $id, 'case_opened', $id_user, "Case opened — evidence gathering complete, ready for the approval workflow.");
            // No physical confirmation to wait on - this case is immediately
            // at the Approval Gate, so tell whoever's eligible to act on it
            // now instead of waiting for the tag_physical step below.
            if (!$case['physical_confirm_required']) {
                $eligible = aapFetchEligibleApproverIds($conn, $case['approver_mode'], $case['requester_department_id'], $case['calculated_value'], $case['ops_tier_required']);
                foreach ($eligible as $approver_id) {
                    aapNotifyStaff($conn, $id, $approver_id, 'pending_approval');
                }
            }
            $msg = "Case opened."; $msg_type = "alpro-success";
        }
    } elseif ($action === 'tag_physical' && $can_tag_physical && $case['case_status'] !== 'draft' && $case['physical_confirm_status'] === 'pending') {
        // Tag + confirm in one step - the separate "Confirm Receipt at ACMM
        // (Reverse Team)" action was merged in here, so entering the RFID
        // reference takes the case straight to 'confirmed' and on to the
        // Approval Gate instead of sitting in an intermediate 'tagged' state.
        $ref = trim($_POST['physical_confirm_ref']);
        if ($ref === '') {
            $msg = "RFID / tag reference is required."; $msg_type = "alpro-danger";
        } else {
            $stmt = $conn->prepare("UPDATE aap_cases SET physical_confirm_status='confirmed', physical_confirm_ref=?, physical_tagged_by=?, physical_tagged_at=?, physical_confirmed_by=?, physical_confirmed_at=?, updated_at=? WHERE id=?");
            $stmt->bind_param("sisissi", $ref, $id_user, $now, $id_user, $now, $now, $id);
            $stmt->execute(); $stmt->close();
            aapLogAudit($conn, $id, 'physical_confirmed', $id_user, "Item tagged and confirmed (ref: $ref). Case ready for the Approval Gate.");
            // Physical confirmation was the last thing standing between this
            // case and the Approval Gate - tell whoever's eligible it's their
            // turn now.
            $eligible = aapFetchEligibleApproverIds($conn, $case['approver_mode'], $case['requester_department_id'], $case['calculated_value'], $case['ops_tier_required']);
            foreach ($eligible as $approver_id) {
                aapNotifyStaff($conn, $id, $approver_id, 'pending_approval');
            }
            $msg = "Item tagged and confirmed. Case is now ready for approval."; $msg_type = "alpro-success";
        }
    } elseif (in_array($action, ['approve', 'reject'], true) && $can_act_approval && $case['case_status'] !== 'draft' && $case['approval_status'] === 'pending') {
        $physical_ok = in_array($case['physical_confirm_status'], ['not_required', 'confirmed'], true);
        if (!$physical_ok) {
            $msg = "This case cannot be decided until the physical return is confirmed."; $msg_type = "alpro-danger";
        } else {
            $remark = trim($_POST['approval_remark']);
            if ($action === 'approve') {
                // One Approve action - the Approved Value field is pre-filled with
                // calculated_value but editable, so an approver can adjust it
                // (what used to be the separate "Correct" action) without a second button.
                $approved_value = ($_POST['approved_value'] !== '') ? (float)$_POST['approved_value'] : (float)$case['calculated_value'];
                $was_adjusted = abs($approved_value - (float)$case['calculated_value']) > 0.001;
                $stmt = $conn->prepare("UPDATE aap_cases SET approval_status='approved', approved_value=?, approver_staff_id=?, approved_at=?, approval_remark=?, updated_at=? WHERE id=?");
                $stmt->bind_param("disssi", $approved_value, $id_user, $now, $remark, $now, $id);
                $stmt->execute(); $stmt->close();
                $audit_summary = "Approved" . ($was_adjusted ? " (value adjusted to " . aapFormatValue($approved_value, $case['value_type']) . ")" : "") . ($remark ? " — $remark" : "");
                aapLogAudit($conn, $id, 'case_approved', $id_user, $audit_summary, ['approved_value' => $approved_value]);
                aapNotifyStaff($conn, $id, (int)$case['created_by'], 'case_approved');
                $msg = "Case approved. Requester notified."; $msg_type = "alpro-success";
            } elseif ($action === 'reject') {
                $stmt = $conn->prepare("UPDATE aap_cases SET approval_status='rejected', approver_staff_id=?, approved_at=?, approval_remark=?, case_status='rejected', closed_at=?, updated_at=? WHERE id=?");
                $stmt->bind_param("issssi", $id_user, $now, $remark, $now, $now, $id);
                $stmt->execute(); $stmt->close();
                aapLogAudit($conn, $id, 'case_rejected', $id_user, "Rejected" . ($remark ? " — $remark" : ""));
                aapNotifyStaff($conn, $id, (int)$case['created_by'], 'case_rejected');
                $msg = "Case rejected. Requester notified."; $msg_type = "alpro-success";
            }
        }
    } elseif ($action === 'execute' && $can_execute && in_array($case['approval_status'], ['approved', 'corrected'], true) && $case['execution_status'] === 'pending') {
        $exec_ref = trim($_POST['execution_reference']);
        if ($exec_ref === '') {
            $msg = "Execution reference is required."; $msg_type = "alpro-danger";
        } else {
            $stmt = $conn->prepare("UPDATE aap_cases SET execution_status='executed', execution_reference=?, executor_staff_id=?, executed_at=?, case_status='closed', closed_at=?, updated_at=? WHERE id=?");
            $stmt->bind_param("sisssi", $exec_ref, $id_user, $now, $now, $now, $id);
            $stmt->execute(); $stmt->close();
            aapLogAudit($conn, $id, 'case_executed', $id_user, "Executed (ref: $exec_ref) and closed. Requester notified.");
            // Notify the requester who raised the case - even if they're also
            // the one executing it themselves - so they know to also
            // complete/close the matching status on their end in Fixit, if
            // this case was linked from there.
            aapNotifyStaff($conn, $id, (int)$case['created_by'], 'case_closed');
            $msg = "Case executed and closed. Requester notified."; $msg_type = "alpro-success";
        }
    } elseif ($action === 'edit_case' && $can_edit_case) {
        $case_type_id = (int)$_POST['case_type_id'];
        $new_ct = aapFetchCaseType($conn, $case_type_id);
        if (!$new_ct || (int)$new_ct['recycle'] === 1) {
            $msg = "Invalid or retired Case Type selected."; $msg_type = "alpro-danger";
        } else {
            // Requester Type / Requesting Channel are no longer collected on
            // this form (removed to match aap_add.php) - Requester Type falls
            // back to the Case Type's own default; Requesting Channel keeps
            // whatever the case already had.
            $requester_type         = in_array($_POST['requester_type'] ?? null, ['customer', 'outlet', 'bu'], true) ? $_POST['requester_type'] : $new_ct['default_requester_type'];
            $requesting_channel     = $case['requesting_channel'];
            $customer_membership_id = trim($_POST['customer_membership_id']);
            $transaction_ref        = trim($_POST['transaction_ref']);
            $calculated_value       = ($_POST['calculated_value'] !== '') ? (float)$_POST['calculated_value'] : null;
            $value_type             = in_array($_POST['value_type'], ['cash', 'points'], true) ? $_POST['value_type'] : $case['value_type'];
            $recommended_outcome    = trim($_POST['recommended_outcome']);

            $new_physical_required = (int)$new_ct['physical_confirm_required'];
            $new_physical_status   = $new_physical_required ? 'pending' : 'not_required';
            $new_approver_mode     = $new_ct['approver_mode'];
            $new_ops_tier_required = $new_ct['ops_tier_required'];

            // evidence_note itself is no longer edited here - it's the original
            // note captured at case creation. New notes go into aap_case_notes
            // instead (the "Evidence Note" field beside Add More Evidence is a
            // blank add-a-note box, submitted as new_note below).
            $stmt = $conn->prepare("
                UPDATE aap_cases SET
                    case_type_id=?, requester_type=?, requesting_channel=?, customer_membership_id=?, transaction_ref=?,
                    calculated_value=?, value_type=?, recommended_outcome=?,
                    physical_confirm_required=?, physical_confirm_status=?, approver_mode=?, ops_tier_required=?,
                    updated_at=?
                WHERE id=?
            ");
            $stmt->bind_param(
                "issssdssissssi",
                $case_type_id, $requester_type, $requesting_channel, $customer_membership_id, $transaction_ref,
                $calculated_value, $value_type, $recommended_outcome,
                $new_physical_required, $new_physical_status, $new_approver_mode, $new_ops_tier_required,
                $now, $id
            );
            $stmt->execute(); $stmt->close();

            // Save Changes carries the Evidence Note/file fields too (moved
            // into this form by JS on submit, see the case-edit-actions
            // script below) - so evidence typed there isn't silently lost if
            // the requester clicks Save Changes instead of Add Evidence.
            aapUploadEvidenceFiles($conn, $id, $_FILES['evidence'] ?? null, $id_user, $now);
            $new_note = trim($_POST['new_note'] ?? '');
            if ($new_note !== '') {
                aapAddCaseNote($conn, $id, $new_note, $id_user);
                aapLogAudit($conn, $id, 'note_added', $id_user, "Added a note");
            }

            aapLogAudit($conn, $id, 'case_edited', $id_user, "Case details updated (" . $new_ct['name'] . ")");
            $msg = "Case details updated."; $msg_type = "alpro-success";
        }
    } elseif ($action === 'add_evidence' && $can_edit_case) {
        // Lets evidence (files/note) be saved on its own, without also having
        // to fill in and resubmit the full Case Details form (edit_case above)
        // just to attach a file.
        aapUploadEvidenceFiles($conn, $id, $_FILES['evidence'] ?? null, $id_user, $now);

        $new_note = trim($_POST['new_note'] ?? '');
        if ($new_note !== '') {
            aapAddCaseNote($conn, $id, $new_note, $id_user);
            aapLogAudit($conn, $id, 'note_added', $id_user, "Added a note");
        }

        $has_files = !empty($_FILES['evidence']) && !empty(array_filter($_FILES['evidence']['name'] ?? []));
        if ($has_files || $new_note !== '') {
            if ($has_files) aapLogAudit($conn, $id, 'evidence_added', $id_user, "Evidence attachment(s) added");
            $msg = "Evidence added."; $msg_type = "alpro-success";
        } else {
            $msg = "Nothing to add — choose a file or write a note first."; $msg_type = "alpro-warn";
        }
    } elseif ($action === 'delete_attachment' && $can_edit_case) {
        $att_id = (int)($_POST['attachment_id'] ?? 0);
        $stmt = $conn->prepare("SELECT * FROM aap_case_attachments WHERE id = ? AND case_id = ?");
        $stmt->bind_param("ii", $att_id, $id);
        $stmt->execute();
        $att = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$att) {
            $msg = "Attachment not found."; $msg_type = "alpro-danger";
        } else {
            corpNasConnect()->delete($att['stored_name']);
            $dstmt = $conn->prepare("DELETE FROM aap_case_attachments WHERE id = ?");
            $dstmt->bind_param("i", $att_id);
            $dstmt->execute(); $dstmt->close();
            aapLogAudit($conn, $id, 'attachment_removed', $id_user, "Removed attachment: " . $att['file_name']);
            $msg = "Attachment removed."; $msg_type = "alpro-success";
        }
    } elseif ($action === 'edit_case_note' && $can_edit_case) {
        $note_id = (int)($_POST['note_id'] ?? 0);
        $note_text = trim($_POST['note'] ?? '');
        if ($note_text === '') {
            $msg = "Note cannot be empty."; $msg_type = "alpro-danger";
        } else {
            aapUpdateCaseNote($conn, $note_id, $id, $note_text);
            aapLogAudit($conn, $id, 'note_edited', $id_user, "Edited a note");
            $msg = "Note updated."; $msg_type = "alpro-success";
        }
    } elseif ($action === 'delete_case_note' && $can_edit_case) {
        $note_id = (int)($_POST['note_id'] ?? 0);
        aapDeleteCaseNote($conn, $note_id, $id);
        aapLogAudit($conn, $id, 'note_removed', $id_user, "Removed a note");
        $msg = "Note removed."; $msg_type = "alpro-success";
    } elseif ($action === 'edit_original_note' && $can_edit_case) {
        $note_text = trim($_POST['note'] ?? '');
        if ($note_text === '') {
            $msg = "Note cannot be empty."; $msg_type = "alpro-danger";
        } else {
            $stmt = $conn->prepare("UPDATE aap_cases SET evidence_note = ? WHERE id = ?");
            $stmt->bind_param("si", $note_text, $id);
            $stmt->execute(); $stmt->close();
            aapLogAudit($conn, $id, 'note_edited', $id_user, "Edited the original note");
            $msg = "Note updated."; $msg_type = "alpro-success";
        }
    } elseif ($action === 'delete_original_note' && $can_edit_case) {
        $stmt = $conn->prepare("UPDATE aap_cases SET evidence_note = '' WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute(); $stmt->close();
        aapLogAudit($conn, $id, 'note_removed', $id_user, "Removed the original note");
        $msg = "Note removed."; $msg_type = "alpro-success";
    } elseif ($action === 'close' && $can_close && $case['case_status'] === 'executed') {
        $stmt = $conn->prepare("UPDATE aap_cases SET case_status='closed', closed_at=?, updated_at=? WHERE id=?");
        $stmt->bind_param("ssi", $now, $now, $id);
        $stmt->execute(); $stmt->close();
        aapLogAudit($conn, $id, 'case_closed', $id_user, "Case closed and requester notified.");
        // Notify the requester who raised the case - even if they're also the
        // one closing it themselves - so they know to also complete/close the
        // matching status on their end in Fixit, if this case was linked from there.
        aapNotifyStaff($conn, $id, (int)$case['created_by'], 'case_closed');
        $msg = "Case closed."; $msg_type = "alpro-success";
    } elseif ($action === 'void_case' && $can_void) {
        $void_reason = trim($_POST['void_reason'] ?? '');
        $stmt = $conn->prepare("UPDATE aap_cases SET case_status='voided', closed_at=?, updated_at=? WHERE id=?");
        $stmt->bind_param("ssi", $now, $now, $id);
        $stmt->execute(); $stmt->close();
        aapLogAudit($conn, $id, 'case_voided', $id_user, "Case voided" . ($void_reason ? " — $void_reason" : ""));
        $msg = "Case voided."; $msg_type = "alpro-success";
    } else {
        $msg = "This action is not available for the case's current state, or you don't have permission to perform it.";
        $msg_type = "alpro-danger";
    }

    // Post/Redirect/Get: bounce to a plain GET on the same case so a page
    // refresh after submitting never resubmits the form or re-shows this
    // message - it's carried across the redirect as a one-time session flash.
    $_SESSION['aap_flash_msg'] = $msg;
    $_SESSION['aap_flash_msg_type'] = $msg_type;
    header('Location: aap_update.php?id=' . $id);
    exit;
}

// ---- SOP progress stepper — Raised -> [Physical Confirm] -> Approval -> Execution -> Closed ----
$aap_steps = [];
if ($case['case_status'] === 'draft') {
    $aap_steps[] = ['label' => 'Draft', 'sub' => 'Gathering evidence', 'state' => 'current'];
} else {
    $aap_steps[] = ['label' => 'Raised', 'sub' => '', 'state' => 'done'];
}

$physical_ready = !$case['physical_confirm_required'] || in_array($case['physical_confirm_status'], ['not_required', 'confirmed'], true);
if ($case['physical_confirm_required']) {
    $pstate = ($case['case_status'] === 'draft') ? 'upcoming' : (($case['physical_confirm_status'] === 'confirmed') ? 'done' : 'current');
    $aap_steps[] = ['label' => 'Physical Confirm', 'sub' => aapPhysicalStatusLabel($case['physical_confirm_status']), 'state' => $pstate];
}

if ($case['case_status'] === 'draft') {
    $astate = 'upcoming'; $asub = '';
} elseif ($case['case_status'] === 'rejected') {
    $astate = 'failed'; $asub = 'Rejected';
} elseif ($case['case_status'] === 'voided') {
    $astate = 'failed'; $asub = 'Voided';
} elseif (in_array($case['approval_status'], ['approved', 'corrected'], true)) {
    $astate = 'done'; $asub = ucfirst($case['approval_status']);
} elseif ($case['approval_status'] === 'pending') {
    $astate = $physical_ready ? 'current' : 'upcoming';
    $asub = $physical_ready ? aapFormatValue($case['calculated_value'], $case['value_type']) : '';
} else {
    $astate = 'upcoming'; $asub = '';
}
$aap_steps[] = ['label' => 'Approval', 'sub' => $asub, 'state' => $astate];

if (in_array($case['case_status'], ['rejected', 'voided'], true)) {
    $estate = 'upcoming';
} elseif ($case['execution_status'] === 'executed') {
    $estate = 'done';
} elseif (in_array($case['approval_status'], ['approved', 'corrected'], true) && $case['execution_status'] === 'pending') {
    $estate = 'current';
} else {
    $estate = 'upcoming';
}
$aap_steps[] = ['label' => 'Execution', 'sub' => '', 'state' => $estate];

if ($case['case_status'] === 'closed') {
    $cstate = 'done';
} elseif ($case['case_status'] === 'executed') {
    $cstate = 'current';
} else {
    $cstate = 'upcoming';
}
$aap_steps[] = ['label' => 'Closed', 'sub' => '', 'state' => $cstate];

$attachments = [];
$att_res = $conn->query("SELECT a.*, s.nama_staff AS uploaded_by_name
                          FROM aap_case_attachments a
                          LEFT JOIN staff s ON s.id = a.uploaded_by
                          WHERE a.case_id = " . (int)$id . "
                          ORDER BY a.timestamp ASC");
while ($att_res && $row = $att_res->fetch_assoc()) $attachments[] = $row;

$case_notes = aapFetchCaseNotes($conn, $id);

// Only needed to populate the Case Summary edit form's Case Type select.
$edit_case_types = $can_edit_case ? aapFetchCaseTypes($conn) : [];

$audit_logs = aapFetchAuditLogs($conn, $id);

// Single unified display status (see aapCaseDisplayStatus in aap_lib.php)
// instead of raw case_status, for this page's OKR-style status chip.
$case_display_status = aapCaseDisplayStatus($case);
$case_pill = 'aap-pill-' . $case_display_status['slug'];
$approval_pill = 'aap-pill-' . $case['approval_status'];
?>

<?php include('aap_modern_head.php'); ?>

<div class="header">
  <b class="rtop"><b class="r1"></b><b class="r2"></b><b class="r3"></b><b class="r4"></b></b>
  <h1 class="headerH1"><img src="img/logo.svg"> Case <?php echo htmlspecialchars($case['case_ref']); ?></h1>
  <b class="rbottom"><b class="r4"></b><b class="r3"></b><b class="r2"></b><b class="r1"></b></b>
</div>

<?php include('aap_sidebar.php'); ?>

<div class="aap-modern">

    <div class="aap-page-title-row">
        <h2 class="aap-page-title">
            <i class="bi bi-folder2-open"></i> Case <?php echo htmlspecialchars($case['case_ref']); ?>
            <span class="aap-pill <?php echo $case_pill; ?>"><?php echo htmlspecialchars($case_display_status['label']); ?></span>
        </h2>
        <div class="aap-page-actions">
            <a href="index.php" class="alpro-btn alpro-btn-grey" style="text-decoration:none;"><i class="bi bi-arrow-left"></i> Back to Case Queue</a>
        </div>
    </div>

    <div class="aap-card" style="margin-bottom: 1rem; padding: 0.75rem 1.25rem;">
        <div class="aap-stepper">
            <?php foreach ($aap_steps as $step): ?>
                <div class="aap-step <?php echo $step['state']; ?>">
                    <div class="aap-step-circle">
                        <?php if ($step['state'] === 'done'): ?>
                            <i class="bi bi-check-lg"></i>
                        <?php elseif ($step['state'] === 'failed'): ?>
                            <i class="bi bi-x-lg"></i>
                        <?php elseif ($step['state'] === 'current'): ?>
                            <i class="bi bi-record-fill" style="font-size:10px;"></i>
                        <?php else: ?>
                            &nbsp;
                        <?php endif; ?>
                    </div>
                    <div class="aap-step-label"><?php echo htmlspecialchars($step['label']); ?></div>
                    <?php if (!empty($step['sub'])): ?>
                        <div class="aap-step-sub"><?php echo htmlspecialchars($step['sub']); ?></div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if (isset($_GET['created'])): ?>
        <div class="alpro-alert alpro-success"><i class="bi bi-check-circle"></i> Case raised successfully as <?php echo htmlspecialchars($case['case_ref']); ?>.</div>
    <?php endif; ?>
    <?php if (!empty($msg)): ?>
        <div class="alpro-alert <?php echo $msg_type; ?>"><?php echo htmlspecialchars($msg); ?></div>
    <?php endif; ?>

    <div class="aap-bento">

        <?php if (!empty($case['fixit_record_id'])): ?>
        <div class="aap-bento-item aap-span-8">
            <div class="aap-card aap-fixit-card" style="height:100%;">
                <div class="aap-card-title-row">
                    <h6 class="aap-card-title"><i class="bi bi-life-preserver"></i> Original Fixit Report — F<?php echo (int)$case['fixit_record_id']; ?></h6>
                    <a href="<?php echo htmlspecialchars(aapFixitTicketUrl($case['fixit_record_id'])); ?>" target="_blank" style="font-size:12px;">View in Fixit <i class="bi bi-box-arrow-up-right"></i></a>
                </div>
                <?php if ($fixit_record): ?>
                    <div class="aap-fact-row">
                        <div class="alpro-field"><label>Category</label><div><?php echo htmlspecialchars($fixit_record['category_name'] ?: '—'); ?></div></div>
                        <div class="alpro-field"><label>Department</label><div><?php echo htmlspecialchars($fixit_record['department_name'] ?: '—'); ?></div></div>
                        <div class="alpro-field"><label>Outlet</label><div><?php echo htmlspecialchars($fixit_record['outlet_code'] ?: '—'); ?></div></div>
                        <div class="alpro-field"><label>Lodged By</label><div><?php echo htmlspecialchars($fixit_record['lodged_by_name'] ?: '—'); ?> on <?php echo $fixit_record['lodge'] ? date('d-m-Y H:i', strtotime($fixit_record['lodge'])) : '—'; ?></div></div>
                    </div>
                    <div class="alpro-field aap-fact-block"><label>Report</label><div><?php echo nl2br(htmlspecialchars($fixit_record['report'])); ?></div></div>
                    <?php if (!empty($fixit_record['remark'])): ?>
                    <div class="alpro-field aap-fact-block">
                        <label>Remark</label>
                        <div style="height:150px; max-height:400px; overflow-y:auto; resize:vertical; border:1px solid #e5e9ec; border-radius:6px; padding:8px 10px; background:#fff;"><?php echo nl2br(htmlspecialchars($fixit_record['remark'])); ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($fixit_attachments)): ?>
                    <div class="alpro-field aap-fact-block">
                        <label>Attachment(s)</label>
                        <ul class="aap-attach-list">
                            <?php foreach ($fixit_attachments as $fa): ?>
                                <li>
                                    <a href="<?php echo htmlspecialchars(aapFixitAttachmentUrl($fa['id'])); ?>" target="_blank"><i class="bi bi-file-earmark-arrow-down"></i> <?php echo htmlspecialchars($fa['name']); ?></a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="aap-card-hint" style="margin:0;">Ticket details could not be loaded.</div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="aap-bento-item aap-span-8">
            <div class="aap-card">
                <div class="aap-card-title-row">
                    <h6 class="aap-card-title"><i class="bi bi-file-earmark-text"></i> Case Summary</h6>
                    <?php if ($can_edit_case): ?>
                        <button type="button" id="case-edit-toggle" class="alpro-btn alpro-btn-grey" style="padding:4px 12px; font-size:12px;"><i class="bi bi-pencil"></i> Edit</button>
                    <?php endif; ?>
                </div>

                <div id="case-view">
                    <div class="alpro-grid">
                        <div class="alpro-field"><label>Department</label><div><?php echo htmlspecialchars($case['requester_department_name'] ?: '—'); ?></div></div>
                        <div class="alpro-field"><label>Case Type</label><div><?php echo htmlspecialchars($case['case_type_name']); ?></div></div>
                    </div>

                    <div class="alpro-grid alpro-mt-10">
                        <div class="alpro-field"><label>Customer / Membership ID</label><div><?php echo htmlspecialchars($case['customer_membership_id'] ?: '—'); ?></div></div>
                        <div class="alpro-field"><label>Transaction / SO Number</label><div><?php echo htmlspecialchars($case['transaction_ref'] ?: '—'); ?></div></div>
                    </div>

                    <div class="alpro-grid alpro-mt-10">
                        <div class="alpro-field"><label>Calculated Value (Requestor)</label><div><?php echo aapFormatValue($case['calculated_value'], $case['value_type']); ?></div></div>
                        <div class="alpro-field"><label>Raised By</label><div><?php echo htmlspecialchars($case['requester_name'] ?: '—'); ?> on <?php echo date('d-m-Y H:i', strtotime($case['timestamp'])); ?></div></div>
                    </div>

                    <div class="alpro-grid alpro-mt-10">
                        <div class="alpro-field" style="grid-column: 1 / -1;">
                            <label>Recommended Outcome</label>
                            <div style="height:80px; max-height:400px; overflow-y:auto; resize:vertical; border:1px solid #e5e9ec; border-radius:6px; padding:8px 10px; background:#fff;"><?php echo nl2br(htmlspecialchars($case['recommended_outcome'] ?: '—')); ?></div>
                        </div>
                    </div>
                </div>

                <?php if ($can_edit_case): ?>
                <form id="case-edit" method="post" action="" enctype="multipart/form-data" style="display:none;">
                    <input type="hidden" name="action" value="edit_case">

                    <div class="alpro-grid">
                        <div class="alpro-field" style="grid-column: 1 / -1;">
                            <label>Case Type <span class="aap-req">*</span></label>
                            <select class="alpro-input" name="case_type_id" id="edit_case_type_id" required>
                                <?php foreach ($edit_case_types as $ect): ?>
                                    <option value="<?php echo $ect['id']; ?>" <?php echo ($case['case_type_id'] == $ect['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($ect['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="alpro-grid alpro-mt-10">
                        <div class="alpro-field">
                            <label>Customer / Membership ID</label>
                            <input class="alpro-input" type="text" name="customer_membership_id" value="<?php echo htmlspecialchars($case['customer_membership_id'] ?? ''); ?>">
                        </div>
                        <div class="alpro-field">
                            <label>Transaction / SO Number</label>
                            <input class="alpro-input" type="text" name="transaction_ref" value="<?php echo htmlspecialchars($case['transaction_ref'] ?? ''); ?>">
                        </div>
                    </div>

                    <div class="alpro-grid alpro-mt-10">
                        <div class="alpro-field">
                            <label>Calculated Value (Requestor) <span class="aap-req">*</span></label>
                            <input class="alpro-input" type="number" step="0.01" min="0" name="calculated_value" value="<?php echo htmlspecialchars($case['calculated_value']); ?>" required>
                        </div>
                        <div class="alpro-field">
                            <label>Value Type <span class="aap-req">*</span></label>
                            <select class="alpro-input" name="value_type" required>
                                <option value="cash" <?php echo $case['value_type'] === 'cash' ? 'selected' : ''; ?>>Cash (RM)</option>
                                <option value="points" <?php echo $case['value_type'] === 'points' ? 'selected' : ''; ?>>Points</option>
                            </select>
                        </div>
                    </div>

                    <div class="alpro-grid alpro-mt-10">
                        <div class="alpro-field" style="grid-column: 1 / -1;">
                            <label>Recommended Refund / Payout Outcome <span class="aap-req">*</span></label>
                            <input class="alpro-input" type="text" name="recommended_outcome" value="<?php echo htmlspecialchars($case['recommended_outcome'] ?? ''); ?>" required>
                        </div>
                    </div>

                </form>
                <?php endif; ?>
            </div>
        </div>

        <div class="aap-bento-item aap-span-4"<?php echo !empty($case['fixit_record_id']) ? ' style="grid-row: 1 / span 2; grid-column: 9 / span 4; align-self:start;"' : ''; ?>>
            <div class="aap-card">
                <h6 class="aap-card-title"><i class="bi bi-shield-check"></i> Approval Gate</h6>
                <p style="margin: 0 0 4px; line-height:2;">
                    Mode: <strong><?php echo aapApproverModeLabel($case['approver_mode']); ?></strong>
                    <br>Value: <span class="aap-pill" style="background:#e7f1ff; color:#0d6efd;"><?php echo aapFormatValue($case['calculated_value'], $case['value_type']); ?></span>
                    <span class="aap-info-icon">i<span class="aap-tooltip">Approvable only by staff with a personal RM ceiling covering this value.</span></span>
                    <span style="margin-left:24px;">Status:</span> <span class="aap-pill <?php echo $approval_pill; ?>"><?php echo ucfirst($case['approval_status']); ?></span>
                </p>
                <?php if ($case['approver_name']): ?>
                    <?php $value_was_adjusted = $case['approved_value'] !== null && abs((float)$case['approved_value'] - (float)$case['calculated_value']) > 0.001; ?>
                    <p class="aap-meta-line">
                        <?php echo ucfirst($case['approval_status']); ?> by <?php echo htmlspecialchars($case['approver_name']); ?> on <?php echo date('d-m-Y H:i', strtotime($case['approved_at'])); ?>
                        <?php if ($case['approved_value'] !== null): ?>
                            — Approved value:
                            <?php if ($value_was_adjusted): ?>
                                <span class="aap-pill" style="background:#fff3cd; color:#856404;"><?php echo aapFormatValue($case['approved_value'], $case['value_type']); ?></span>
                                <span style="color:#6c757d;">(adjusted from <?php echo aapFormatValue($case['calculated_value'], $case['value_type']); ?>)</span>
                            <?php else: ?>
                                <?php echo aapFormatValue($case['approved_value'], $case['value_type']); ?>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php if ($case['approval_remark']): ?><br>Remark: <?php echo htmlspecialchars($case['approval_remark']); ?><?php endif; ?>
                    </p>
                <?php endif; ?>

                <?php
                $physical_ok = in_array($case['physical_confirm_status'], ['not_required', 'confirmed'], true);
                if ($case['case_status'] === 'draft'):
                    $open_ready_errors = aapCaseOpenReadyErrors($case);
                ?>
                    <div class="alpro-alert alpro-warn" style="margin-top:18px;"><i class="bi bi-hourglass-split"></i> Draft — gathering evidence. Open the case below when ready to start the approval workflow.</div>
                    <?php if ($can_open_case): ?>
                        <?php if (!empty($open_ready_errors)): ?>
                            <div class="alpro-alert alpro-danger alpro-mt-10"><i class="bi bi-exclamation-triangle"></i> Fill in before opening: <?php echo htmlspecialchars(implode(', ', $open_ready_errors)); ?></div>
                        <?php else: ?>
                            <form method="post" action="" class="alpro-mt-10" onsubmit="return confirm('Open this case? It will move into the approval workflow.');">
                                <input type="hidden" name="action" value="open_case">
                                <button class="alpro-btn" type="submit" style="width:100%; padding:6px 10px; font-size:12px; background:#198754; color:#fff;"><i class="bi bi-unlock"></i> Open Case</button>
                            </form>
                        <?php endif; ?>
                    <?php endif; ?>
                <?php elseif ($case['approval_status'] === 'pending' && !$physical_ok): ?>
                    <div class="alpro-alert alpro-warn" style="margin-top:18px;"><i class="bi bi-exclamation-triangle"></i> Blocked — this case cannot be decided until the physical return is confirmed.</div>
                <?php elseif ($case['approval_status'] === 'pending' && $can_act_approval): ?>
                    <form method="post" action="" class="alpro-mt-10">
                        <div class="alpro-grid">
                            <div class="alpro-field" style="grid-column: 1 / -1;">
                                <label>Remark</label>
                                <textarea class="alpro-input" name="approval_remark" id="approval-remark-input" placeholder="Reason / notes" style="height:60px; max-height:150px; resize:vertical; overflow-y:auto;"><?php echo htmlspecialchars($case['approval_remark'] ?? ''); ?></textarea>
                                <div style="text-align:right;"><span id="approval-remark-status" style="font-size:11px; color:#6c757d;"></span></div>
                            </div>
                            <div class="alpro-field" style="grid-column: 1 / -1;"><label>Approved Value <span class="aap-muted" style="font-weight:normal;">(adjust if needed)</span></label><input class="alpro-input" type="number" step="0.01" min="0" name="approved_value" value="<?php echo htmlspecialchars($case['calculated_value']); ?>"></div>
                        </div>
                        <hr style="border:none; border-top:1px solid #e5e9ec; margin:10px 0;">
                        <div class="alpro-actions" style="flex-direction:row; flex-wrap:wrap; gap:4px;">
                            <button class="alpro-btn alpro-btn-blue" type="submit" name="action" value="approve" title="Approve" onclick="return confirm('Approve this case?');" style="flex:1; min-width:0; padding:4px 6px; font-size:11px;"><i class="bi bi-check-lg"></i> Approve</button>
                            <button class="alpro-btn" type="submit" name="action" value="reject" onclick="return confirm('Reject this case?');" style="background:#dc3545; color:#fff; border:none; flex:1; min-width:0; padding:4px 6px; font-size:11px;"><i class="bi bi-x-lg"></i> Reject</button>
                        </div>
                    </form>
                <?php elseif ($case['approval_status'] === 'pending'):
                    if ($case['approver_mode'] === 'bu_signoff') {
                        $awaiting = 'the owning Business Unit';
                    } elseif ($case['approver_mode'] === 'cs_tier') {
                        $awaiting = 'Customer Support or Operations with sufficient approval ceiling';
                    } else {
                        $awaiting = 'Operations with sufficient approval ceiling';
                    }
                ?>
                    <p class="aap-card-hint" style="margin-top:12px;">Awaiting decision by <?php echo $awaiting; ?>.</p>
                <?php endif; ?>
            </div>

            <?php if ((int)$case['physical_confirm_required'] === 1): ?>
            <div class="aap-card" style="margin-top:15px;">
                <h6 class="aap-card-title"><i class="bi bi-box-seam"></i> Physical Return Confirmation</h6>
                <p style="margin: 0 0 4px;">Status: <strong><?php echo aapPhysicalStatusLabel($case['physical_confirm_status']); ?></strong></p>
                <?php if ($case['physical_confirm_ref']): ?>
                    <p class="aap-meta-line">Ref: <span class="alpro-mono"><?php echo htmlspecialchars($case['physical_confirm_ref']); ?></span></p>
                <?php endif; ?>
                <?php if ($case['physical_confirmed_by_name']): ?>
                    <p class="aap-meta-line">Tagged &amp; confirmed by <?php echo htmlspecialchars($case['physical_confirmed_by_name']); ?> on <?php echo date('d-m-Y H:i', strtotime($case['physical_confirmed_at'])); ?></p>
                <?php endif; ?>

                <?php if ($case['case_status'] === 'draft'): ?>
                    <p class="aap-card-hint" style="margin-top:12px;">Available once the case is opened.</p>
                <?php elseif ($case['physical_confirm_status'] === 'pending' && $can_tag_physical): ?>
                    <form method="post" action="" class="alpro-mt-10" id="tag-physical-form">
                        <input type="hidden" name="action" value="tag_physical">
                        <div class="alpro-grid">
                            <div class="alpro-field" style="grid-column: 1 / -1;"><label>RFID / Tag Reference <span class="aap-req">*</span></label><input class="alpro-input" type="text" name="physical_confirm_ref" required autofocus style="min-height:0; height:auto; padding:5px 8px;"></div>
                        </div>
                        <hr style="border:none; border-top:1px solid #e5e9ec; margin:10px 0;">
                        <button class="alpro-btn alpro-btn-blue" type="submit" onclick="return confirm('Confirm this item was tagged and received? This moves the case straight to the Approval Gate.');" style="width:100%; padding:6px 10px; font-size:12px;"><i class="bi bi-check2-circle"></i> Tag &amp; Confirm Item Returned</button>
                    </form>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if ($can_void): ?>
            <div class="aap-card" style="margin-top:15px;">
                <h6 class="aap-card-title"><i class="bi bi-x-circle"></i> Void Case</h6>
                <p class="aap-card-hint" style="margin:0 0 10px;">
                    Voiding removes <?php echo htmlspecialchars($case['case_ref']); ?> (<?php echo htmlspecialchars($case['case_type_name']); ?>) from the active queue.
                    This is only possible before the case reaches a decision at the Approval Gate — it cannot be undone.
                </p>

                <button type="button" id="void-case-toggle" class="alpro-btn" style="background:#dc3545; color:#fff; border:none; width:100%; padding:6px 10px; font-size:12px;"><i class="bi bi-x-circle"></i> Void Case</button>

                <form method="post" action="" id="void-case-form" style="display:none;" onsubmit="return confirm('Void this case? This cannot be undone.');">
                    <input type="hidden" name="action" value="void_case">
                    <div class="alpro-field">
                        <label>Reason</label>
                        <textarea class="alpro-input" name="void_reason" style="height:80px;" placeholder="Why is this case being voided?"></textarea>
                    </div>
                    <div class="alpro-actions alpro-mt-10">
                        <button class="alpro-btn" type="submit" style="background:#dc3545; color:#fff; border:none;"><i class="bi bi-check-lg"></i> Confirm Void</button>
                        <button class="alpro-btn alpro-btn-grey" type="button" id="void-case-cancel"><i class="bi bi-x-lg"></i> Cancel</button>
                    </div>
                </form>
            </div>
            <?php endif; ?>

            <?php if (in_array($case['approval_status'], ['approved', 'corrected'], true)): ?>
            <div class="aap-card" style="margin-top:15px;">
                <h6 class="aap-card-title"><i class="bi bi-gear"></i> Execution</h6>
                <p class="aap-card-hint" style="margin:0 0 10px;">Confirms the approved refund/adjustment was actually carried out in CLS, OMC, or Xilnex — enter that system's reference number and execute to record it here. This also closes the case and notifies the requester.</p>
                <p style="margin: 0 0 4px;">Status: <strong><?php echo ucfirst($case['execution_status']); ?></strong>
                    <?php if ($case['execution_reference']): ?> — Ref: <span class="alpro-mono"><?php echo htmlspecialchars($case['execution_reference']); ?></span><?php endif; ?>
                </p>
                <?php if ($case['executor_name']): ?>
                    <p class="aap-meta-line">Executed by <?php echo htmlspecialchars($case['executor_name']); ?> on <?php echo date('d-m-Y H:i', strtotime($case['executed_at'])); ?></p>
                <?php endif; ?>

                <?php if ($case['execution_status'] === 'pending' && $can_execute): ?>
                    <form method="post" action="" class="alpro-mt-10">
                        <input type="hidden" name="action" value="execute">
                        <div class="alpro-grid">
                            <div class="alpro-field" style="grid-column: 1 / -1;"><label>Execution Reference (CLS / OMC / Xilnex) <span class="aap-req">*</span></label><textarea class="alpro-input" name="execution_reference" required style="height:38px; max-height:150px; resize:vertical; overflow-y:auto;"></textarea></div>
                        </div>
                        <hr style="border:none; border-top:1px solid #e5e9ec; margin:10px 0;">
                        <button class="alpro-btn alpro-btn-blue" type="submit" onclick="return confirm('Execute this adjustment?') && confirm('Close this case now? This cannot be undone.');" style="width:100%; padding:6px 10px; font-size:12px;"><i class="bi bi-play-fill"></i> Execute &amp; Close Case</button>
                    </form>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <div class="aap-bento-item aap-span-12">
            <div class="aap-card">
                <h6 class="aap-card-title"><i class="bi bi-paperclip"></i> Evidence Attachments</h6>
                <div class="alpro-grid">
                    <div id="evidence-note-view" class="alpro-field">
                        <label>Evidence Note</label>
                        <?php if ($case['evidence_note'] || !empty($case_notes)): ?>
                            <ul class="aap-attach-list">
                                <?php if ($case['evidence_note']): ?>
                                    <li style="display:block;">
                                        <div class="note-view" id="note-view-original">
                                            <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:8px;">
                                                <div class="note-text" style="height:80px; max-height:400px; overflow-y:auto; resize:vertical; border:1px solid #e9ecef; border-radius:6px; padding:6px 8px; background:#fff; width:100%; box-sizing:border-box;"><?php echo nl2br(htmlspecialchars($case['evidence_note'])); ?></div>
                                                <?php if ($can_edit_case): ?>
                                                    <span class="note-actions" style="display:none; gap:8px; flex-shrink:0;">
                                                        <button type="button" class="note-edit-toggle" data-note-id="original" title="Edit note" style="background:none; border:none; color:#6c757d; cursor:pointer; padding:0; font-size:13px;"><i class="bi bi-pencil"></i></button>
                                                        <form method="post" action="" style="display:inline;" onsubmit="return aapConfirmDeleteNote();">
                                                            <input type="hidden" name="action" value="delete_original_note">
                                                            <button type="submit" title="Remove note" style="background:none; border:none; color:#dc3545; cursor:pointer; padding:0; font-size:13px;"><i class="bi bi-trash"></i></button>
                                                        </form>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            <span style="font-size:11px; color:#6c757d;"><?php echo htmlspecialchars($case['requester_name'] ?: 'Unknown'); ?> &middot; <?php echo date('d-m-Y H:i', strtotime($case['timestamp'])); ?></span>
                                        </div>
                                        <?php if ($can_edit_case): ?>
                                        <form method="post" action="" class="note-edit-form" id="note-edit-form-original" style="display:none;" onsubmit="aapRememberEditState();">
                                            <input type="hidden" name="action" value="edit_original_note">
                                            <textarea class="alpro-input" name="note" style="height:60px; max-height:150px; resize:vertical; overflow-y:auto;"><?php echo htmlspecialchars($case['evidence_note']); ?></textarea>
                                            <div class="alpro-actions alpro-mt-10">
                                                <button class="alpro-btn alpro-btn-blue" type="submit" style="padding:4px 10px; font-size:12px;"><i class="bi bi-check-lg"></i> Save</button>
                                                <button class="alpro-btn alpro-btn-grey note-edit-cancel" type="button" data-note-id="original" style="padding:4px 10px; font-size:12px;"><i class="bi bi-x-lg"></i> Cancel</button>
                                            </div>
                                        </form>
                                        <?php endif; ?>
                                    </li>
                                <?php endif; ?>
                                <?php foreach ($case_notes as $note): ?>
                                    <li style="display:block;">
                                        <div class="note-view" id="note-view-<?php echo $note['id']; ?>">
                                            <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:8px;">
                                                <div class="note-text" style="height:80px; max-height:400px; overflow-y:auto; resize:vertical; border:1px solid #e9ecef; border-radius:6px; padding:6px 8px; background:#fff; width:100%; box-sizing:border-box;"><?php echo nl2br(htmlspecialchars($note['note'])); ?></div>
                                                <?php if ($can_edit_case): ?>
                                                    <span class="note-actions" style="display:none; gap:8px; flex-shrink:0;">
                                                        <button type="button" class="note-edit-toggle" data-note-id="<?php echo $note['id']; ?>" title="Edit note" style="background:none; border:none; color:#6c757d; cursor:pointer; padding:0; font-size:13px;"><i class="bi bi-pencil"></i></button>
                                                        <form method="post" action="" style="display:inline;" onsubmit="return aapConfirmDeleteNote();">
                                                            <input type="hidden" name="action" value="delete_case_note">
                                                            <input type="hidden" name="note_id" value="<?php echo (int)$note['id']; ?>">
                                                            <button type="submit" title="Remove note" style="background:none; border:none; color:#dc3545; cursor:pointer; padding:0; font-size:13px;"><i class="bi bi-trash"></i></button>
                                                        </form>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            <span style="font-size:11px; color:#6c757d;"><?php echo htmlspecialchars($note['created_by_name'] ?: 'Unknown'); ?> &middot; <?php echo date('d-m-Y H:i', strtotime($note['timestamp'])); ?></span>
                                        </div>
                                        <?php if ($can_edit_case): ?>
                                        <form method="post" action="" class="note-edit-form" id="note-edit-form-<?php echo $note['id']; ?>" style="display:none;" onsubmit="aapRememberEditState();">
                                            <input type="hidden" name="action" value="edit_case_note">
                                            <input type="hidden" name="note_id" value="<?php echo (int)$note['id']; ?>">
                                            <textarea class="alpro-input" name="note" style="height:60px; max-height:150px; resize:vertical; overflow-y:auto;"><?php echo htmlspecialchars($note['note']); ?></textarea>
                                            <div class="alpro-actions alpro-mt-10">
                                                <button class="alpro-btn alpro-btn-blue" type="submit" style="padding:4px 10px; font-size:12px;"><i class="bi bi-check-lg"></i> Save</button>
                                                <button class="alpro-btn alpro-btn-grey note-edit-cancel" type="button" data-note-id="<?php echo $note['id']; ?>" style="padding:4px 10px; font-size:12px;"><i class="bi bi-x-lg"></i> Cancel</button>
                                            </div>
                                        </form>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <div>—</div>
                        <?php endif; ?>
                    </div>

                    <div>
                        <?php if (!empty($attachments)): ?>
                        <ul class="aap-attach-list">
                            <?php foreach ($attachments as $att): ?>
                                <li>
                                    <a href="?id=<?php echo $id; ?>&download=<?php echo $att['id']; ?>"><i class="bi bi-file-earmark-arrow-down"></i> <?php echo htmlspecialchars($att['file_name']); ?></a>
                                    <span style="display:flex; align-items:center; gap:8px;">
                                        <span style="font-size:11px; color:#6c757d;"><?php echo htmlspecialchars($att['uploaded_by_name'] ?: 'Unknown'); ?> &middot; <?php echo date('d-m-Y', strtotime($att['timestamp'])); ?></span>
                                        <?php if ($can_edit_case): ?>
                                            <form method="post" action="" class="attachment-delete-form" style="display:none;" onsubmit="return aapConfirmDeleteAttachment();">
                                                <input type="hidden" name="action" value="delete_attachment">
                                                <input type="hidden" name="attachment_id" value="<?php echo (int)$att['id']; ?>">
                                                <button type="submit" title="Remove attachment" style="background:none; border:none; color:#dc3545; cursor:pointer; padding:0; font-size:13px;"><i class="bi bi-trash"></i></button>
                                            </form>
                                        <?php endif; ?>
                                    </span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <?php else: ?>
                            <p class="aap-card-hint" style="margin:0;">No files uploaded for this case.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($can_edit_case): ?>
                <hr style="border:none; border-top:1px solid #e5e9ec; margin:14px 0;">
                <h6 class="aap-card-title" style="margin-bottom:10px; padding-bottom:0; border-bottom:none;">Add New Evidence</h6>
                <form method="post" action="" enctype="multipart/form-data" id="evidence-add-form">
                <input type="hidden" name="action" value="add_evidence">
                <div class="alpro-grid alpro-mt-10">
                    <div class="alpro-field">
                        <label>Evidence Note</label>
                        <textarea class="alpro-input" name="new_note" placeholder="Add a note..." style="height:80px; max-height:150px; resize:vertical; overflow-y:auto;"></textarea>
                    </div>
                    <div class="alpro-field">
                        <label>Add More Evidence</label>
                        <input class="alpro-input" type="file" name="evidence[]" id="evidence-file-input" multiple>
                        <ul class="aap-attach-list" id="evidence-file-preview" style="margin-top:8px;"></ul>
                    </div>
                </div>
                <div class="alpro-actions alpro-mt-10">
                    <button type="submit" style="background:#fff; color:#0d6efd; border:1px solid #0d6efd; border-radius:20px; padding:8px 18px; font-size:13px; font-weight:600; cursor:pointer; display:inline-flex; align-items:center; gap:6px;"><i class="bi bi-plus-lg"></i> Add Evidence</button>
                </div>
                </form>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($can_edit_case): ?>
        <div class="aap-bento-item aap-span-12" id="case-edit-actions" style="display:none;">
            <div class="aap-card">
                <div class="alpro-actions" style="justify-content:flex-end;">
                    <button class="alpro-btn alpro-btn-grey" type="button" id="case-edit-cancel" style="flex:0 0 auto;"><i class="bi bi-x-lg"></i> Cancel</button>
                    <button class="alpro-btn" type="submit" form="case-edit" id="case-edit-save" style="flex:0 0 auto; background:#198754; color:#fff;"><i class="bi bi-check-lg"></i> Save Changes</button>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($case['case_status'] === 'executed' && $can_close): ?>
        <div class="aap-bento-item aap-span-12">
            <div class="aap-card">
                <h6 class="aap-card-title"><i class="bi bi-bell"></i> Notification &amp; Close</h6>
                <form method="post" action="" class="alpro-mt-10">
                    <input type="hidden" name="action" value="close">
                    <button class="alpro-btn alpro-btn-blue" type="submit" onclick="return confirm('Close this case and notify the requester? This cannot be undone.');"><i class="bi bi-check2-all"></i> Notify Requester &amp; Close Case</button>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <div class="aap-bento-item aap-span-12">
            <div class="aap-card">
                <h6 class="aap-card-title"><i class="bi bi-clock-history"></i> Audit Trail</h6>
                <?php if (empty($audit_logs)): ?>
                    <div class="aap-empty-state">No activity recorded yet.</div>
                <?php else: ?>
                    <div class="aap-activity-list">
                        <?php foreach (array_reverse($audit_logs) as $log): ?>
                        <div class="aap-activity-row">
                            <div class="aap-activity-event"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $log['event']))); ?></div>
                            <div class="aap-activity-summary"><?php echo htmlspecialchars($log['summary']); ?></div>
                            <div class="aap-activity-meta"><?php echo htmlspecialchars($log['actor_name'] ?: 'Unknown'); ?> &middot; <?php echo date('d-m-Y H:i', strtotime($log['timestamp'])); ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </div>
</div>

<script>
var AAP_UPDATE = {
    openInEditMode: <?php echo ($can_edit_case && $case['case_status'] === 'draft') ? 'true' : 'false'; ?>
};
</script>
<?php $page_js = 'js/aap_update.js'; include('aap_footer.php'); ?>
