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

// Resolves the SuperAdmin/admin-level union used across every AAP page.
$aap_identity = aapResolveIdentity($conn, $id_user, $grade, $department);
$grade = $aap_identity['grade'];
$department = $aap_identity['department'];
$aap_dept_ids = $aap_identity['dept_ids'];
$aap_is_admin = $aap_identity['is_admin'];
$aap_is_superadmin = $aap_identity['is_superadmin'];

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$case = $id ? aapFetchCase($conn, $id) : null;
if (!$case) {
    die("Case not found.");
}

// Visibility scope check (mirrors index.php's aapScopeWhere) - keyed off
// the Case Type's own department (what the case is actually about), not
// requester_department_id (just whoever happened to raise it - see
// aapScopeWhere() in aap_lib.php for why).
$can_view = $aap_is_admin
    || aapIsOperations($aap_dept_ids)
    || (int)$case['created_by'] === (int)$id_user
    || in_array((int)$case['case_type_department_id'], $aap_dept_ids, true);
if (!$can_view) {
    die("You do not have access to this case.");
}

// Opening this case (however the viewer got here - a notification click,
// the Case Queue, a direct link) clears every one of their own unread
// notifications about it, not just one they happened to click through - see
// aapMarkCaseNotificationsRead() in aap_lib.php.
aapMarkCaseNotificationsRead($conn, $id, $id_user);

// Needed ahead of the download branch below (execution attachments are
// gated on this, not on general case visibility) - the rest of the
// execution-eligibility logic near $can_close/$can_void stays where it is
// further down, this is just pulled forward for the download gate.
$can_execute = aapCanExecute($grade, $aap_dept_ids, $aap_is_admin);

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

// ---- File download (execution-only attachment) - Level 3/execution staff
// only, even if they can otherwise view the case; see aapCanExecute(). ----
if (isset($_GET['download_exec'])) {
    if (!$can_execute) {
        die("You do not have access to this attachment.");
    }
    $att_id = (int)$_GET['download_exec'];
    $stmt = $conn->prepare("SELECT * FROM aap_case_execution_attachments WHERE id = ? AND case_id = ?");
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

// Only while the case is still active (draft/open) - a rejected/voided/
// closed case is terminal and must not accept a physical tag/confirm after
// the fact, even though physical_confirm_status itself may still read
// 'pending' (voiding a case never touches that field).
$can_tag_physical = in_array($case['case_status'], ['draft', 'open'], true)
    && ($case['requester_type'] === 'outlet' || $case['requester_type'] === 'customer') && ((int)$case['created_by'] === (int)$id_user || aapIsOperations($aap_dept_ids) || $aap_is_admin);

// Approval eligibility is now per-Case-Type: a staff member must be on that
// Case Type's Approval Staff Tier list (Level 2, admin/aap_admin.php) with a
// tier covering the case's value, and not on its Exclusion list (Level 3) -
// see aapCanApprove(). Customer Support is the one deliberate exception -
// they raise and self-handle their own cases at any value, so the person who
// raised it can always act on it if they're Customer Support, without
// needing to be on the Case Type's Staff Tier list at all.
$can_act_approval = (aapIsCustomerSupport($aap_dept_ids) && (int)$case['created_by'] === (int)$id_user)
    || aapCanApprove($conn, $id_user, $case['case_type_id'], $case['calculated_value'], $aap_is_admin);
// $can_execute itself is computed earlier (before the download branches).
$can_close   = $aap_is_admin || aapIsOperations($aap_dept_ids) || (int)$case['created_by'] === (int)$id_user;
// Once a case has ever been suspended (Execution sending it back to
// Verification/Approval), it's already past the point Reject makes sense
// for - it previously reached a decision, so Reject is retired for it even
// while approval_status is back to 'pending' during the redo; Void from the
// approver's side of the workflow no longer applies.
$can_void    = (in_array($case['case_status'], ['draft', 'open'], true) && $case['approval_status'] === 'pending'
                   && (int)$case['suspend_count'] === 0)
               && ((int)$case['created_by'] === (int)$id_user || $aap_is_admin);

// Draft: a new case sits here first so the issuer/CS team has time to
// gather evidence before it's opened for the approval workflow. Only the
// issuer or admin can open it, same population as $can_edit_case below.
$can_open_case = $case['case_status'] === 'draft'
               && ((int)$case['created_by'] === (int)$id_user || $aap_is_admin);

// Case Summary, and existing evidence (notes/attachments), are only
// editable/removable before a decision is made and before the
// physical-return workflow has actually started (RFID tagged/confirmed) —
// changing the Case Type mid-flight after tagging would desync the physical
// confirmation gate from whatever the new Case Type expects, and once the
// case reaches the Approval stage the approver is reviewing exactly what's
// there, so it must stop shifting underneath them. Also editable while still
// a Draft, since that's exactly when evidence is being gathered.
$can_edit_case = (in_array($case['case_status'], ['draft', 'open'], true) && $case['approval_status'] === 'pending'
                   && in_array($case['physical_confirm_status'], ['not_required', 'pending'], true))
               && ((int)$case['created_by'] === (int)$id_user || $aap_is_admin);

// Adding *new* evidence (notes/attachments) stays open across every phase
// of the case's life - Investigation, Verification, Approval, Execution -
// right up until it's actually finished (closed/rejected/voided). Page
// access itself ($can_view, checked at the very top of this file) already
// limits who ever reaches this point, so no further population check is
// needed here. Editing/removing what's already there is a separate, much
// narrower question - see aapCaseEvidenceItemEditable()/
// $case_phase_started_at below.
$can_add_evidence = !in_array($case['case_status'], ['closed', 'rejected', 'voided'], true);

// Start of whichever phase the case is CURRENTLY in - an item is only
// editable/removable by its own author if it was added during this same
// phase (see aapCaseEvidenceItemEditable() in aap_lib.php); anything carried
// over from an earlier phase (e.g. added during Approval) freezes the
// moment the case moves into the next one (e.g. Execution), even for its
// own author. Null for a terminal case, which aapCaseEvidenceItemEditable()
// already short-circuits via $can_add_evidence above.
$case_phase_started_at = aapCaseCurrentPhaseStartedAt($conn, $case);

// The "original note" (aap_cases.evidence_note, captured at raise time) is
// its own item for freeze purposes, same as any other note/attachment - it
// was added during the Investigation phase, by the case's creator, so it
// follows the exact same current-phase-only rule instead of the broader
// $can_edit_case window (which covers the rest of Case Summary and stays
// open through all of Approval when there's no physical return step).
// Without this it stayed editable straight through Approval whenever
// physical_confirm_required was 0, contradicting the freeze everywhere else.
$original_note_editable = aapCaseEvidenceItemEditable((int)$case['created_by'], $case['timestamp'], $can_add_evidence, $id_user, $aap_is_admin, $case_phase_started_at);

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
    // Appended to $msg just before the flash-redirect below, by whichever
    // branch calls aapUploadEvidenceFiles()/aapUploadExecutionAttachments() -
    // see aapUploadFailureNote() in aap_lib.php.
    $aap_upload_warning = '';

    if ($action === 'open_case' && $can_open_case) {
        // Open Case now submits the Case Summary (case-edit) form itself, so
        // save those fields first - the requester no longer has to click
        // Save Changes in a separate step before this button unlocks.
        $updated = aapSaveCaseEditFields($conn, $id, $case, $_POST, $now);
        if ($updated === null) {
            $msg = "Invalid or retired Case Type selected."; $msg_type = "alpro-danger";
        } else {
            $aap_upload_warning .= aapUploadFailureNote(aapUploadEvidenceFiles($conn, $id, $_FILES['evidence'] ?? null, $id_user, $now));
            $new_note = trim($_POST['new_note'] ?? '');
            if ($new_note !== '') {
                aapAddCaseNote($conn, $id, $new_note, $id_user);
                aapLogAudit($conn, $id, 'note_added', $id_user, "Added a note");
            }
            aapLogAudit($conn, $id, 'case_edited', $id_user, "Case details updated (" . $updated['case_type_name'] . ")");

            $missing = aapCaseOpenReadyErrors($updated);
            if (!empty($missing)) {
                $msg = "Fill in all required fields before opening this case: " . implode(', ', $missing) . "."; $msg_type = "alpro-danger";
            } else {
                $stmt = $conn->prepare("UPDATE aap_cases SET case_status='open', updated_at=? WHERE id=?");
                $stmt->bind_param("si", $now, $id);
                $stmt->execute(); $stmt->close();
                aapLogAudit($conn, $id, 'case_opened', $id_user, "Case opened — evidence gathering complete, ready for the approval workflow.");
                // No physical confirmation to wait on - this case is immediately
                // at the approval gate, so tell whoever's eligible to act on it
                // now instead of waiting for the tag_physical step below.
                if (!$updated['physical_confirm_required']) {
                    $eligible = aapFetchEligibleApproverIds($conn, $updated['case_type_id'], $updated['calculated_value']);
                    foreach ($eligible as $approver_id) {
                        aapNotifyStaff($conn, $id, $approver_id, 'pending_approval');
                    }
                }
                $msg = "Case opened."; $msg_type = "alpro-success";
            }
        }
    } elseif ($action === 'tag_physical' && $can_tag_physical && $case['case_status'] !== 'draft' && $case['physical_confirm_status'] === 'pending') {
        // Tag + confirm in one step - the separate "Confirm Receipt at ACMM
        // (Reverse Team)" action was merged in here, so entering the RFID
        // reference takes the case straight to 'confirmed' and on to the
        // approval gate instead of sitting in an intermediate 'tagged' state.
        $ref = trim($_POST['physical_confirm_ref']);
        if ($ref === '') {
            $msg = "RFID / tag reference is required."; $msg_type = "alpro-danger";
        } else {
            $stmt = $conn->prepare("UPDATE aap_cases SET physical_confirm_status='confirmed', physical_confirm_ref=?, physical_tagged_by=?, physical_tagged_at=?, physical_confirmed_by=?, physical_confirmed_at=?, updated_at=? WHERE id=?");
            $stmt->bind_param("sisissi", $ref, $id_user, $now, $id_user, $now, $now, $id);
            $stmt->execute(); $stmt->close();
            aapLogAudit($conn, $id, 'physical_confirmed', $id_user, "Item tagged and confirmed (ref: $ref). Case ready for the approval gate.");
            // Physical confirmation was the last thing standing between this
            // case and the approval gate - tell whoever's eligible it's their
            // turn now.
            $eligible = aapFetchEligibleApproverIds($conn, $case['case_type_id'], $case['calculated_value']);
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
                // AAP-LINK: case ends negatively - auto-cancel the linked Fixit
                // ticket instead of leaving it stuck Pending forever.
                if (!empty($case['fixit_record_id'])) {
                    aapCancelLinkedFixitTicket($conn, $case['fixit_record_id'], $id_user);
                }
                $msg = "Case rejected. Requester notified."; $msg_type = "alpro-success";
            }
        }
    } elseif ($action === 'execute' && $can_execute && in_array($case['approval_status'], ['approved', 'corrected'], true) && $case['execution_status'] === 'pending') {
        // Execute and Close are separate steps/actions again - this only
        // marks the case executed (case_status='executed', a real waiting
        // state, not a legacy value collapsed into 'closed'). The requester
        // isn't notified and the linked Fixit ticket isn't completed until
        // the separate "Notify Requester & Close Case" step below actually
        // runs (the 'close' action) - executing alone doesn't finish the
        // case.
        $exec_ref = trim($_POST['execution_reference']);
        if ($exec_ref === '') {
            $msg = "Execution reference is required."; $msg_type = "alpro-danger";
        } else {
            $stmt = $conn->prepare("UPDATE aap_cases SET execution_status='executed', execution_reference=?, executor_staff_id=?, executed_at=?, case_status='executed', updated_at=? WHERE id=?");
            $stmt->bind_param("sissi", $exec_ref, $id_user, $now, $now, $id);
            $stmt->execute(); $stmt->close();
            aapLogAudit($conn, $id, 'case_executed', $id_user, "Executed (ref: $exec_ref). Ready to close.");
            $msg = "Case executed. Close the case below to notify the requester and finish."; $msg_type = "alpro-success";
        }
    } elseif ($action === 'suspend_case' && $can_execute && in_array($case['approval_status'], ['approved', 'corrected'], true) && $case['execution_status'] === 'pending' && (int)$case['suspend_count'] === 0) {
        // Execution can send a case back to Verification/Approval instead of
        // executing it - e.g. not enough evidence surfaced to actually carry
        // out the payout. Never goes back to Draft (case_status stays
        // 'open') - the requester picks up editing from Verification/
        // Approval, not from raw scratch.
        //
        // Only the three status enums reset to pending/not_required - who
        // tagged/confirmed/approved/executed last time, and their
        // remark/reference, are deliberately left in place rather than
        // nulled out, so that history stays visible (aap_update.php's
        // "Tagged & confirmed by.../Approved by..." meta lines) right up
        // until the case actually gets tagged/approved/executed again, at
        // which point the normal action handlers overwrite them with the
        // new attempt's values same as always. Evidence/notes/attachments
        // added before this moment stay frozen forever regardless
        // (aapCaseCurrentPhaseStartedAt() floors the edit window at
        // suspended_at, see aap_lib.php), even for their own author, even
        // once that same person is back in a phase they could normally edit.
        $suspend_reason = trim($_POST['suspend_reason'] ?? '');
        if ($suspend_reason === '') {
            $msg = "A reason is required to suspend this case."; $msg_type = "alpro-danger";
        } else {
            $new_physical_status = (int)$case['physical_confirm_required'] === 1 ? 'pending' : 'not_required';
            $stmt = $conn->prepare("
                UPDATE aap_cases SET
                    physical_confirm_status = ?,
                    approval_status = 'pending',
                    execution_status = 'pending',
                    suspended_at = ?, suspended_by = ?, suspend_reason = ?, suspend_count = suspend_count + 1, updated_at = ?
                WHERE id = ?
            ");
            $stmt->bind_param("ssissi", $new_physical_status, $now, $id_user, $suspend_reason, $now, $id);
            $stmt->execute(); $stmt->close();
            aapLogAudit($conn, $id, 'case_suspended', $id_user, "Suspended by Execution: $suspend_reason. Case sent back to Verification/Approval.");

            aapNotifyStaff($conn, $id, (int)$case['created_by'], 'case_suspended');
            $eligible = aapFetchEligibleApproverIds($conn, $case['case_type_id'], $case['calculated_value']);
            foreach ($eligible as $approver_id) {
                aapNotifyStaff($conn, $id, $approver_id, 'case_suspended');
            }

            $msg = "Case suspended and sent back to Verification/Approval. Requester and approvers notified."; $msg_type = "alpro-success";
        }
    } elseif ($action === 'add_execution_attachment' && $can_execute) {
        // Lets the executor attach more files after the fact (e.g. a later
        // confirmation screenshot) without needing to redo the Execute step -
        // same pattern as 'add_evidence' for the general Evidence Attachments
        // card.
        $exec_upload_result = aapUploadExecutionAttachments($conn, $id, $_FILES['execution_evidence'] ?? null, $id_user, $now);
        if ($exec_upload_result['uploaded'] > 0) {
            aapLogAudit($conn, $id, 'execution_attachment_added', $id_user, "Execution attachment(s) added");
            $msg = "Execution attachment added."; $msg_type = "alpro-success";
        } elseif ($exec_upload_result['attempted'] > 0) {
            $msg = "Upload failed — check your connection and try again."; $msg_type = "alpro-danger";
        } else {
            $msg = "Nothing to add — choose a file first."; $msg_type = "alpro-warn";
        }
    } elseif ($action === 'delete_execution_attachment' && $can_execute) {
        $att_id = (int)($_POST['attachment_id'] ?? 0);
        $stmt = $conn->prepare("SELECT * FROM aap_case_execution_attachments WHERE id = ? AND case_id = ?");
        $stmt->bind_param("ii", $att_id, $id);
        $stmt->execute();
        $att = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($att) {
            $dstmt = $conn->prepare("DELETE FROM aap_case_execution_attachments WHERE id = ?");
            $dstmt->bind_param("i", $att_id);
            $dstmt->execute(); $dstmt->close();
            aapLogAudit($conn, $id, 'execution_attachment_removed', $id_user, "Removed execution attachment: " . $att['file_name']);
            $msg = "Execution attachment removed."; $msg_type = "alpro-success";
        }
    } elseif ($action === 'edit_case' && $can_edit_case) {
        // Requester Type / Requesting Channel are no longer collected on this
        // form (removed to match aap_add.php) - Requester Type falls back to
        // 'customer' (Case Type no longer carries its own
        // default_requester_type); Requesting Channel keeps whatever the case
        // already had. See aapSaveCaseEditFields() (aap_lib.php), shared with
        // the 'open_case' action above.
        $updated = aapSaveCaseEditFields($conn, $id, $case, $_POST, $now);
        if ($updated === null) {
            $msg = "Invalid or retired Case Type selected."; $msg_type = "alpro-danger";
        } else {
            // Save Changes carries the Evidence Note/file fields too (moved
            // into this form by JS on submit, see the case-edit-actions
            // script below) - so evidence typed there isn't silently lost if
            // the requester clicks Save Changes instead of Add Evidence.
            $aap_upload_warning .= aapUploadFailureNote(aapUploadEvidenceFiles($conn, $id, $_FILES['evidence'] ?? null, $id_user, $now));
            $new_note = trim($_POST['new_note'] ?? '');
            if ($new_note !== '') {
                aapAddCaseNote($conn, $id, $new_note, $id_user);
                aapLogAudit($conn, $id, 'note_added', $id_user, "Added a note");
            }

            aapLogAudit($conn, $id, 'case_edited', $id_user, "Case details updated (" . $updated['case_type_name'] . ")");
            $msg = "Case details updated."; $msg_type = "alpro-success";
        }
    } elseif ($action === 'add_evidence' && $can_add_evidence) {
        // Lets evidence (files/note) be saved on its own, without also having
        // to fill in and resubmit the full Case Details form (edit_case above)
        // just to attach a file.
        $evidence_upload_result = aapUploadEvidenceFiles($conn, $id, $_FILES['evidence'] ?? null, $id_user, $now);

        $new_note = trim($_POST['new_note'] ?? '');
        if ($new_note !== '') {
            aapAddCaseNote($conn, $id, $new_note, $id_user);
            aapLogAudit($conn, $id, 'note_added', $id_user, "Added a note");
        }

        if ($evidence_upload_result['uploaded'] > 0 || $new_note !== '') {
            if ($evidence_upload_result['uploaded'] > 0) aapLogAudit($conn, $id, 'evidence_added', $id_user, "Evidence attachment(s) added");
            $msg = "Evidence added."; $msg_type = "alpro-success";
            $aap_upload_warning .= aapUploadFailureNote($evidence_upload_result);
        } elseif ($evidence_upload_result['attempted'] > 0) {
            $msg = "Upload failed — check your connection and try again."; $msg_type = "alpro-danger";
        } else {
            $msg = "Nothing to add — choose a file or write a note first."; $msg_type = "alpro-warn";
        }
    } elseif ($action === 'delete_attachment' && $can_add_evidence) {
        $att_id = (int)($_POST['attachment_id'] ?? 0);
        $stmt = $conn->prepare("SELECT * FROM aap_case_attachments WHERE id = ? AND case_id = ?");
        $stmt->bind_param("ii", $att_id, $id);
        $stmt->execute();
        $att = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$att) {
            $msg = "Attachment not found."; $msg_type = "alpro-danger";
        } elseif (!aapCaseEvidenceItemEditable($att['uploaded_by'], $att['timestamp'], $can_add_evidence, $id_user, $aap_is_admin, $case_phase_started_at)) {
            $msg = "This attachment can no longer be removed."; $msg_type = "alpro-danger";
        } else {
            corpNasConnect()->delete($att['stored_name']);
            $dstmt = $conn->prepare("DELETE FROM aap_case_attachments WHERE id = ?");
            $dstmt->bind_param("i", $att_id);
            $dstmt->execute(); $dstmt->close();
            aapLogAudit($conn, $id, 'attachment_removed', $id_user, "Removed attachment: " . $att['file_name']);
            $msg = "Attachment removed."; $msg_type = "alpro-success";
        }
    } elseif ($action === 'edit_case_note' && $can_add_evidence) {
        $note_id = (int)($_POST['note_id'] ?? 0);
        $note_text = trim($_POST['note'] ?? '');
        $note = aapFetchCaseNote($conn, $note_id, $id);
        if ($note_text === '') {
            $msg = "Note cannot be empty."; $msg_type = "alpro-danger";
        } elseif (!$note) {
            $msg = "Note not found."; $msg_type = "alpro-danger";
        } elseif (!aapCaseEvidenceItemEditable($note['created_by'], $note['timestamp'], $can_add_evidence, $id_user, $aap_is_admin, $case_phase_started_at)) {
            $msg = "This note can no longer be edited."; $msg_type = "alpro-danger";
        } else {
            aapUpdateCaseNote($conn, $note_id, $id, $note_text);
            aapLogAudit($conn, $id, 'note_edited', $id_user, "Edited a note");
            $msg = "Note updated."; $msg_type = "alpro-success";
        }
    } elseif ($action === 'delete_case_note' && $can_add_evidence) {
        $note_id = (int)($_POST['note_id'] ?? 0);
        $note = aapFetchCaseNote($conn, $note_id, $id);
        if (!$note) {
            $msg = "Note not found."; $msg_type = "alpro-danger";
        } elseif (!aapCaseEvidenceItemEditable($note['created_by'], $note['timestamp'], $can_add_evidence, $id_user, $aap_is_admin, $case_phase_started_at)) {
            $msg = "This note can no longer be removed."; $msg_type = "alpro-danger";
        } else {
            aapDeleteCaseNote($conn, $note_id, $id);
            aapLogAudit($conn, $id, 'note_removed', $id_user, "Removed a note");
            $msg = "Note removed."; $msg_type = "alpro-success";
        }
    } elseif ($action === 'edit_original_note' && $original_note_editable) {
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
    } elseif ($action === 'delete_original_note' && $original_note_editable) {
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
        // one closing it themselves.
        aapNotifyStaff($conn, $id, (int)$case['created_by'], 'case_closed');
        // AAP-LINK: auto-complete the linked Fixit ticket instead of relying
        // on the requester to go complete it by hand.
        if (!empty($case['fixit_record_id'])) {
            aapCompleteLinkedFixitTicket($conn, $case['fixit_record_id'], $id_user);
        }
        $msg = "Case closed."; $msg_type = "alpro-success";
    } elseif ($action === 'void_case' && $can_void) {
        $void_reason = trim($_POST['void_reason'] ?? '');
        $stmt = $conn->prepare("UPDATE aap_cases SET case_status='voided', closed_at=?, updated_at=? WHERE id=?");
        $stmt->bind_param("ssi", $now, $now, $id);
        $stmt->execute(); $stmt->close();
        aapLogAudit($conn, $id, 'case_voided', $id_user, "Case voided" . ($void_reason ? " — $void_reason" : ""));
        // AAP-LINK: case ends negatively - auto-cancel the linked Fixit
        // ticket instead of leaving it stuck Pending forever.
        if (!empty($case['fixit_record_id'])) {
            aapCancelLinkedFixitTicket($conn, $case['fixit_record_id'], $id_user);
        }
        $msg = "Case voided."; $msg_type = "alpro-success";
    } else {
        $msg = "This action is not available for the case's current state, or you don't have permission to perform it.";
        $msg_type = "alpro-danger";
    }

    // A failed file upload doesn't otherwise change $msg/$msg_type (the note/
    // case-save/etc. half of the same submit can still have genuinely
    // succeeded) - append it as a caveat instead of replacing the message,
    // downgrading a success banner to a warning so it doesn't read as
    // "everything worked" when a file silently didn't.
    if ($aap_upload_warning !== '') {
        $msg .= $aap_upload_warning;
        if ($msg_type === 'alpro-success') $msg_type = 'alpro-warn';
    }

    // Post/Redirect/Get: bounce to a plain GET on the same case so a page
    // refresh after submitting never resubmits the form or re-shows this
    // message - it's carried across the redirect as a one-time session flash.
    $_SESSION['aap_flash_msg'] = $msg;
    $_SESSION['aap_flash_msg_type'] = $msg_type;
    header('Location: aap_update.php?id=' . $id);
    exit;
}

// ---- SOP progress stepper — Raised -> [Verification Required] -> Approval -> Execution -> Closed ----
$aap_steps = [];
if ($case['case_status'] === 'draft') {
    $aap_steps[] = ['label' => 'Open', 'sub' => 'Gathering evidence', 'state' => 'current'];
} else {
    $aap_steps[] = ['label' => 'Open Case', 'sub' => '', 'state' => 'done'];
}

$physical_ready = !$case['physical_confirm_required'] || in_array($case['physical_confirm_status'], ['not_required', 'confirmed'], true);
if ($case['physical_confirm_required']) {
    $pstate = ($case['case_status'] === 'draft') ? 'upcoming' : (($case['physical_confirm_status'] === 'confirmed') ? 'done' : 'current');
    $aap_steps[] = ['label' => 'Verification Required', 'sub' => aapPhysicalStatusLabel($case['physical_confirm_status']), 'state' => $pstate];
}

if ($case['case_status'] === 'draft') {
    $astate = 'upcoming'; $asub = '';
} elseif (in_array($case['case_status'], ['rejected', 'voided'], true)) {
    $astate = 'failed'; $asub = 'Rejected';
} elseif (in_array($case['approval_status'], ['approved', 'corrected'], true)) {
    $astate = 'done'; $asub = 'Approved';
} elseif ($case['approval_status'] === 'pending') {
    $astate = $physical_ready ? 'current' : 'upcoming';
    $asub = $physical_ready ? aapFormatValue($case['calculated_value'], $case['value_type']) : '';
} else {
    $astate = 'upcoming'; $asub = '';
}
$aap_steps[] = ['label' => 'Approval', 'sub' => $asub, 'state' => $astate];

// Execution and Closed are separate steps again - executing a case
// (aap_update.php's 'execute' action) now only sets execution_status/
// case_status to 'executed' and stops there; a separate "Notify Requester &
// Close Case" step (the 'close' action) is what actually finishes the case.
if (in_array($case['case_status'], ['rejected', 'voided'], true)) {
    $exec_state = 'upcoming';
} elseif ($case['execution_status'] === 'executed') {
    $exec_state = 'done';
} elseif (in_array($case['approval_status'], ['approved', 'corrected'], true) && $case['execution_status'] === 'pending') {
    $exec_state = 'current';
} else {
    $exec_state = 'upcoming';
}
$aap_steps[] = ['label' => 'Execution', 'sub' => '', 'state' => $exec_state];

if (in_array($case['case_status'], ['rejected', 'voided'], true)) {
    $cstate = 'upcoming';
} elseif ($case['case_status'] === 'closed') {
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

// Execution-only attachments - only ever queried/rendered when the viewer
// can execute (see aap_lib.php's aapUploadExecutionAttachments comment) so
// the requester/approver/anyone else never even receives this list in the
// page's data, let alone sees it rendered.
$execution_attachments = [];
if ($can_execute) {
    $exec_att_res = $conn->query("SELECT a.*, s.nama_staff AS uploaded_by_name
                              FROM aap_case_execution_attachments a
                              LEFT JOIN staff s ON s.id = a.uploaded_by
                              WHERE a.case_id = " . (int)$id . "
                              ORDER BY a.timestamp ASC");
    while ($exec_att_res && $row = $exec_att_res->fetch_assoc()) $execution_attachments[] = $row;
}

$case_notes = aapFetchCaseNotes($conn, $id);

// Only needed to populate the Case Summary edit form's Case Type select -
// scoped to the case's own department (same rule as aap_add.php's Case Type
// picker, which only ever offers Case Types belonging to the originating
// Fixit report's department) so switching Case Type can't accidentally move
// a case into a different department's approval workflow.
$edit_case_types = $can_edit_case ? aapFetchCaseTypes($conn, false, (int)$case['case_type_department_id']) : [];

$audit_logs = aapFetchAuditLogs($conn, $id);

// Single unified display status (see aapCaseDisplayStatus in aap_lib.php)
// instead of raw case_status, for this page's OKR-style status chip.
$case_display_status = aapCaseDisplayStatus($case);
$case_pill = 'aap-pill-' . $case_display_status['slug'];
$approval_status_display = aapApprovalStatusDisplay($case['approval_status']);
$approval_pill = 'aap-pill-' . $approval_status_display['slug'];
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
    <?php if ((int)$case['suspend_count'] > 0): ?>
        <div class="alpro-alert" style="background:#fdf3e0; color:#7a5a10; border-color:#f3dfa8;">
            <i class="bi bi-pause-circle"></i> This case was suspended by Execution on <?php echo date('d-m-Y H:i', strtotime($case['suspended_at'])); ?> — see the Execution section below for the reason.
        </div>
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
                        <label>Report Description</label>
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
                        <div class="alpro-field"><label>Customer Name</label><div><?php echo htmlspecialchars($case['customer_name'] ?: '—'); ?></div></div>
                        <div class="alpro-field"><label>Membership ID</label><div><?php echo htmlspecialchars($case['customer_membership_id'] ?: '—'); ?></div></div>
                        <div class="alpro-field"><label>Transaction No</label><div><?php echo htmlspecialchars($case['transaction_ref'] ?: '—'); ?></div></div>
                    </div>

                    <div class="alpro-grid alpro-mt-10">
                        <div class="alpro-field"><label>Calculated Value (Requestor)</label><div><?php echo aapFormatValue($case['calculated_value'], $case['value_type']); ?></div></div>
                        <div class="alpro-field"><label>Raised By</label><div><?php echo htmlspecialchars($case['requester_name'] ?: '—'); ?> on <?php echo date('d-m-Y H:i', strtotime($case['timestamp'])); ?></div></div>
                    </div>

                    <div class="alpro-grid alpro-mt-10">
                        <div class="alpro-field" style="grid-column: 1 / -1;">
                            <label>Report Description</label>
                            <div style="height:80px; max-height:400px; overflow-y:auto; resize:vertical; border:1px solid #e5e9ec; border-radius:6px; padding:8px 10px; background:#fff;"><?php echo nl2br(htmlspecialchars($case['recommended_outcome'] ?: '—')); ?></div>
                        </div>
                    </div>
                </div>

                <?php if ($can_edit_case): ?>
                <form id="case-edit" method="post" action="" enctype="multipart/form-data" style="display:none;">
                    <input type="hidden" name="action" id="case-edit-action-field" value="edit_case">

                    <div class="alpro-grid" style="flex-wrap:nowrap;">
                        <div class="alpro-field" style="flex:1; min-width:0;">
                            <label>Department</label>
                            <div class="alpro-input" style="background:#f1f3f5; display:flex; align-items:center; height:38px;"><?php echo htmlspecialchars($case['case_type_department_name'] ?: '—'); ?></div>
                            <p class="alpro-muted" style="font-size:12px; margin:6px 0 0;">Fixed to this case's own department - pick the Case Type from that department's list.</p>
                        </div>

                        <div class="alpro-field" style="flex:2; min-width:0;">
                            <label>Case Type <span class="aap-req">*</span></label>
                            <select class="alpro-input" name="case_type_id" id="edit_case_type_id" required style="height:38px;">
                                <?php foreach ($edit_case_types as $ect): ?>
                                    <option value="<?php echo $ect['id']; ?>" <?php echo ($case['case_type_id'] == $ect['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($ect['case_type_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="alpro-grid alpro-mt-10">
                        <div class="alpro-field">
                            <label>Customer Name</label>
                            <input class="alpro-input" type="text" name="customer_name" value="<?php echo htmlspecialchars($case['customer_name'] ?? ''); ?>" readonly>
                        </div>
                        <div class="alpro-field">
                            <label>Membership ID</label>
                            <input class="alpro-input" type="text" name="customer_membership_id" id="edit-customer-membership-input" value="<?php echo htmlspecialchars($case['customer_membership_id'] ?? ''); ?>" inputmode="numeric" pattern="[0-9]*" readonly>
                        </div>
                        <div class="alpro-field">
                            <label>Transaction No</label>
                            <input class="alpro-input" type="text" name="transaction_ref" id="edit-transaction-ref-input" value="<?php echo htmlspecialchars($case['transaction_ref'] ?? ''); ?>" inputmode="numeric" pattern="[0-9]*">
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
                            <label>Report Description <span class="aap-req">*</span></label>
                            <input class="alpro-input" type="text" name="recommended_outcome" value="<?php echo htmlspecialchars($case['recommended_outcome'] ?? ''); ?>" required>
                        </div>
                    </div>

                </form>
                <?php endif; ?>
            </div>
        </div>

        <div class="aap-bento-item aap-span-4"<?php echo !empty($case['fixit_record_id']) ? ' style="grid-row: 1 / span 2; grid-column: 9 / span 4; align-self:start;"' : ''; ?>>
            <div class="aap-card">
                <h6 class="aap-card-title"><i class="bi bi-shield-check"></i> Approval gate</h6>
                <p style="margin: 0 0 4px; line-height:2;">
                    Value: <span class="aap-pill" style="background:#e7f1ff; color:#0d6efd;"><?php echo aapFormatValue($case['calculated_value'], $case['value_type']); ?></span>
                    <span class="aap-info-icon">i<span class="aap-tooltip">Approvable only by staff on this Case Type's Approval Staff Tier list, with a tier covering this value.</span></span>
                    <span style="margin-left:24px;">Status:</span> <span class="aap-pill <?php echo $approval_pill; ?>"><?php echo htmlspecialchars($approval_status_display['label']); ?></span>
                </p>
                <?php if ($case['approver_name']): ?>
                    <?php $value_was_adjusted = $case['approved_value'] !== null && abs((float)$case['approved_value'] - (float)$case['calculated_value']) > 0.001; ?>
                    <p class="aap-meta-line">
                        <?php echo htmlspecialchars($approval_status_display['label']); ?> by <?php echo htmlspecialchars($case['approver_name']); ?> on <?php echo date('d-m-Y H:i', strtotime($case['approved_at'])); ?>
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
                ?>
                    <div class="alpro-alert alpro-warn" style="margin-top:18px;"><i class="bi bi-hourglass-split"></i> Draft — gathering evidence. Open the case below when ready to start the approval workflow.</div>
                    <?php if ($can_open_case): ?>
                        <button class="alpro-btn alpro-btn-blue alpro-mt-10" type="submit" form="case-edit" id="open-case-btn" style="width:100%; padding:6px 10px; font-size:12px;"><i class="bi bi-unlock"></i> Open Case</button>
                    <?php endif; ?>
                <?php elseif ($case['approval_status'] === 'pending' && !$physical_ok): ?>
                    <div class="alpro-alert alpro-warn" style="margin-top:18px;"><i class="bi bi-exclamation-triangle"></i> Blocked — this case cannot be decided until the physical return is confirmed.</div>
                <?php elseif ($case['approval_status'] === 'pending' && $can_act_approval): ?>
                    <form method="post" action="" class="alpro-mt-10">
                        <div class="alpro-grid">
                            <div class="alpro-field" style="grid-column: 1 / -1;"><label>Approved Value <span class="aap-muted" style="font-weight:normal; font-size:11px; text-transform:uppercase;">(adjust if needed)</span></label><input class="alpro-input" type="number" step="0.01" min="0" name="approved_value" value="<?php echo htmlspecialchars($case['calculated_value']); ?>"></div>
                            <div class="alpro-field" style="grid-column: 1 / -1;">
                                <label>Report Description</label>
                                <textarea class="alpro-input" name="approval_remark" id="approval-remark-input" placeholder="Reason / notes" style="height:60px; max-height:150px; resize:vertical; overflow-y:auto;"><?php echo htmlspecialchars($case['approval_remark'] ?? ''); ?></textarea>
                                <div style="text-align:right;"><span id="approval-remark-status" style="font-size:11px; color:#6c757d;"></span></div>
                            </div>
                        </div>
                        <hr style="border:none; border-top:1px solid #e5e9ec; margin:10px 0;">
                        <div class="alpro-actions" style="flex-direction:row; flex-wrap:wrap; gap:4px;">
                            <button class="alpro-btn alpro-btn-blue" type="submit" name="action" value="approve" title="Approve" onclick="return confirm('Approve this case?');" style="flex:1; min-width:0; padding:4px 6px; font-size:11px;"><i class="bi bi-check-lg"></i> Approve</button>
                            <button class="alpro-btn" type="submit" name="action" value="reject" onclick="return confirm('Reject this case?');" style="flex:1; min-width:0; padding:4px 6px; font-size:11px; background:#dc3545; color:#fff; border:none;"><i class="bi bi-x-lg"></i> Reject</button>
                        </div>
                    </form>
                <?php elseif ($case['approval_status'] === 'pending'): ?>
                    <p class="aap-card-hint" style="margin-top:12px;">Awaiting decision by this Case Type's assigned Approval staff.</p>
                <?php endif; ?>
            </div>

            <?php if ((int)$case['physical_confirm_required'] === 1): ?>
            <div class="aap-card" style="margin-top:15px;">
                <h6 class="aap-card-title"><i class="bi bi-box-seam"></i> Verification Required</h6>
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
                            <div class="alpro-field" style="grid-column: 1 / -1;"><label>RFID / Tag Reference <span class="aap-req">*</span></label><input class="alpro-input" type="text" name="physical_confirm_ref" value="<?php echo htmlspecialchars($case['physical_confirm_ref'] ?? ''); ?>" required autofocus style="min-height:0; height:auto; padding:5px 8px;"></div>
                        </div>
                        <hr style="border:none; border-top:1px solid #e5e9ec; margin:10px 0;">
                        <button class="alpro-btn alpro-btn-blue" type="submit" onclick="return confirm('Confirm this item was tagged and received? This moves the case straight to the approval gate.');" style="width:100%; padding:6px 10px; font-size:12px;"><i class="bi bi-check2-circle"></i> Confirm Verification</button>
                    </form>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if ($can_void): ?>
            <div class="aap-card" style="margin-top:15px;">
                <h6 class="aap-card-title"><i class="bi bi-x-circle"></i> Reject Case</h6>
                <p class="aap-card-hint" style="margin:0 0 10px;">
                    Rejecting removes <?php echo htmlspecialchars($case['case_ref']); ?> (<?php echo htmlspecialchars($case['case_type_name']); ?>) from the active queue.
                    This is only possible before the case reaches a decision at the approval gate — it cannot be undone.
                </p>

                <button type="button" id="void-case-toggle" class="alpro-btn" style="width:100%; padding:6px 10px; font-size:12px; background:#fff; color:#212529; border:1px solid #000;"><i class="bi bi-x-circle"></i> Reject Case</button>

                <form method="post" action="" id="void-case-form" style="display:none;" onsubmit="return confirm('Reject this case? This cannot be undone.');">
                    <input type="hidden" name="action" value="void_case">
                    <div class="alpro-field">
                        <label>Reason</label>
                        <textarea class="alpro-input" name="void_reason" style="height:80px;" placeholder="Why is this case being rejected?"></textarea>
                    </div>
                    <div class="alpro-actions alpro-mt-10">
                        <button class="alpro-btn" type="submit" style="background:#dc3545; color:#fff; border:none; padding:8px 20px; font-size:14px; border-radius:8px;">Confirm Reject</button>
                        <button class="alpro-btn" type="button" id="void-case-cancel" style="background:#fff; color:#212529; border:1px solid #000; padding:8px 20px; font-size:14px; border-radius:8px;">Cancel</button>
                    </div>
                </form>
            </div>
            <?php endif; ?>

            <?php // Stays visible across a Suspend cycle (not just while
            // approval_status is currently approved/corrected) so the past
            // execution history below (ref/executor/when) doesn't disappear
            // the moment the case goes back to Verification/Approval - only
            // the actual Execute/Suspend buttons inside are gated on the
            // live eligibility window, further down. ?>
            <?php if (in_array($case['approval_status'], ['approved', 'corrected'], true) || $case['executor_name'] || (int)$case['suspend_count'] > 0): ?>
            <div class="aap-card" style="margin-top:15px;">
                <h6 class="aap-card-title"><i class="bi bi-gear"></i> Execution</h6>
                <p class="aap-card-hint" style="margin:0 0 10px;">Confirms the approved refund/adjustment was actually carried out in CLS, OMC, or Xilnex — enter that system's reference number and execute to record it here. Closing and notifying the requester is a separate step below once executed.</p>
                <p style="margin: 0 0 4px;">Status: <strong><?php echo ucfirst($case['execution_status']); ?></strong>
                    <?php if ($case['execution_reference']): ?> — Ref: <span class="alpro-mono"><?php echo htmlspecialchars($case['execution_reference']); ?></span><?php endif; ?>
                </p>
                <?php if ($case['executor_name']): ?>
                    <p class="aap-meta-line">Executed by <?php echo htmlspecialchars($case['executor_name']); ?> on <?php echo date('d-m-Y H:i', strtotime($case['executed_at'])); ?></p>
                <?php endif; ?>
                <?php if ($case['suspended_at']): ?>
                    <p class="aap-meta-line" style="color:#b8860b;">Suspended by <?php echo htmlspecialchars($case['suspended_by_name'] ?: 'Unknown'); ?> on <?php echo date('d-m-Y H:i', strtotime($case['suspended_at'])); ?><br>Reason: <?php echo htmlspecialchars($case['suspend_reason'] ?? ''); ?></p>
                <?php endif; ?>

                <?php // Also requires approval_status approved/corrected right
                // now, not just execution_status pending - after a Suspend
                // resets both back to pending together, execution isn't
                // actually reachable again until the case is re-approved,
                // even though the outer card above stays visible for
                // history the whole time. ?>
                <?php if ($case['execution_status'] === 'pending' && in_array($case['approval_status'], ['approved', 'corrected'], true) && $can_execute): ?>
                    <form method="post" action="" class="alpro-mt-10">
                        <input type="hidden" name="action" value="execute">
                        <div class="alpro-grid">
                            <div class="alpro-field" style="grid-column: 1 / -1;"><label>Execution Reference (CLS / OMC / Xilnex) <span class="aap-req">*</span></label><textarea class="alpro-input" name="execution_reference" required style="height:38px; max-height:150px; resize:vertical; overflow-y:auto;"><?php echo htmlspecialchars($case['execution_reference'] ?? ''); ?></textarea></div>
                        </div>
                        <hr style="border:none; border-top:1px solid #e5e9ec; margin:10px 0;">
                        <button class="alpro-btn alpro-btn-blue" type="submit" onclick="return confirm('Execute this adjustment? Closing the case is now a separate step afterward.');" style="width:100%; padding:6px 10px; font-size:12px;"><i class="bi bi-play-fill"></i> <?php echo ((int)$case['suspend_count'] > 0) ? 'Execute (Suspended)' : 'Execute'; ?></button>
                    </form>

                    <?php // A case can only ever be suspended once - not
                    // available again on a re-run after it's already been
                    // sent back this way one time. ?>
                    <?php if ((int)$case['suspend_count'] === 0): ?>
                    <button type="button" id="suspend-case-toggle" class="alpro-btn alpro-mt-10" style="width:100%; padding:6px 10px; font-size:12px; background:#fff; color:#b8860b; border:1px solid #b8860b;"><i class="bi bi-pause-circle"></i> Suspend Case</button>
                    <form method="post" action="" id="suspend-case-form" style="display:none;" class="alpro-mt-10" onsubmit="return confirm('Suspend this case and send it back to Verification/Approval? Evidence already on the case stays locked forever, even once it is your turn to edit again. This cannot be undone.');">
                        <input type="hidden" name="action" value="suspend_case">
                        <div class="alpro-field">
                            <label>Suspend Reason <span class="aap-req">*</span></label>
                            <textarea class="alpro-input" name="suspend_reason" required style="height:60px;" placeholder="e.g. not enough evidence submitted"></textarea>
                        </div>
                        <div class="alpro-actions alpro-mt-10">
                            <button class="alpro-btn" type="submit" style="background:#b8860b; color:#fff; border:none; padding:8px 20px; font-size:14px; border-radius:8px;">Confirm Suspend</button>
                            <button class="alpro-btn" type="button" id="suspend-case-cancel" style="background:#fff; color:#212529; border:1px solid #000; padding:8px 20px; font-size:14px; border-radius:8px;">Cancel</button>
                        </div>
                    </form>
                    <?php endif; ?>
                <?php endif; ?>

                <?php // Same eligibility as the Execute/Suspend buttons above -
                // hidden while the case is back at Verification/Approval
                // after a Suspend, not just whenever the outer card happens
                // to still be showing history. ?>
                <?php if ($can_execute && in_array($case['approval_status'], ['approved', 'corrected'], true)): ?>
                <div class="alpro-mt-10" style="border-top:1px solid #e5e9ec; padding-top:10px;">
                    <label style="font-size:11px; font-weight:600; color:#6c757d; text-transform:uppercase; display:block; margin-bottom:6px;"><i class="bi bi-eye-slash"></i> Execution Attachments (visible to Execution only)</label>
                    <?php if (!empty($execution_attachments)): ?>
                        <ul class="aap-attach-list">
                            <?php foreach ($execution_attachments as $eatt): ?>
                                <li>
                                    <a href="?id=<?php echo $id; ?>&download_exec=<?php echo $eatt['id']; ?>"><i class="bi bi-file-earmark-arrow-down"></i> <?php echo htmlspecialchars($eatt['file_name']); ?></a>
                                    <span style="display:flex; align-items:center; gap:8px;">
                                        <span style="font-size:11px; color:#6c757d;"><?php echo htmlspecialchars($eatt['uploaded_by_name'] ?: 'Unknown'); ?> &middot; <?php echo date('d-m-Y', strtotime($eatt['timestamp'])); ?></span>
                                        <form method="post" action="" style="display:inline;" onsubmit="return aapConfirmDeleteAttachment();">
                                            <input type="hidden" name="action" value="delete_execution_attachment">
                                            <input type="hidden" name="attachment_id" value="<?php echo (int)$eatt['id']; ?>">
                                            <button type="submit" title="Remove attachment" style="background:none; border:none; color:#dc3545; cursor:pointer; padding:0; font-size:13px;"><i class="bi bi-trash"></i></button>
                                        </form>
                                    </span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p class="aap-card-hint" style="margin:0 0 8px;">No execution-only files uploaded yet.</p>
                    <?php endif; ?>
                    <form method="post" action="" enctype="multipart/form-data" style="display:flex; gap:8px; align-items:center;">
                        <input type="hidden" name="action" value="add_execution_attachment">
                        <input class="alpro-input" type="file" name="execution_evidence[]" multiple style="flex:1;">
                        <button class="alpro-btn alpro-btn-grey" type="submit" style="padding:6px 12px; font-size:12px; flex-shrink:0;"><i class="bi bi-paperclip"></i> Add</button>
                    </form>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if ($case['case_status'] === 'executed' && $can_close): ?>
            <div class="aap-card" style="margin-top:15px;">
                <h6 class="aap-card-title"><i class="bi bi-bell"></i> Notification &amp; Close</h6>
                <form method="post" action="" class="alpro-mt-10">
                    <input type="hidden" name="action" value="close">
                    <button class="alpro-btn alpro-btn-blue" type="submit" onclick="return confirm('Close this case and notify the requester? This cannot be undone.');" style="width:100%; padding:6px 10px; font-size:12px;"><i class="bi bi-check2-all"></i> Notify Requester &amp; Close Case</button>
                </form>
            </div>
            <?php endif; ?>
        </div>

        <!-- Bank Detail - toggled together with Case Summary's own Edit/Save
        Changes (same $can_edit_case window, same case-edit form via
        form="case-edit" on each input below) rather than having its own
        separate Edit button, so there's one unified edit session for the
        case instead of two overlapping ones. -->
        <div class="aap-bento-item aap-span-12">
            <div class="aap-card">
                <h6 class="aap-card-title"><i class="bi bi-bank"></i> Bank Detail</h6>

                <div id="bank-view">
                    <div class="alpro-grid">
                        <div class="alpro-field"><label>Bank Name</label><div><?php echo htmlspecialchars($case['bank_name'] ?: '—'); ?></div></div>
                        <div class="alpro-field"><label>Account Number</label><div><?php echo htmlspecialchars($case['bank_account_number'] ?: '—'); ?></div></div>
                        <div class="alpro-field"><label>Account Holder Name</label><div><?php echo htmlspecialchars($case['bank_account_holder'] ?: '—'); ?></div></div>
                    </div>
                </div>

                <?php if ($can_edit_case): ?>
                <div id="bank-edit" style="display:none;">
                    <div class="alpro-grid">
                        <div class="alpro-field">
                            <label>Bank Name <span class="aap-req">*</span></label>
                            <select class="alpro-input" name="bank_id" form="case-edit" required>
                                <option value="">Select Bank</option>
                                <?php foreach (aapFetchBankMasterOptions($conn) as $bank): ?>
                                    <option value="<?php echo $bank['id']; ?>" <?php echo ((int)($case['bank_id'] ?? 0) === (int)$bank['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($bank['bank_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="alpro-field">
                            <label>Account Number <span class="aap-req">*</span></label>
                            <input class="alpro-input" type="text" name="bank_account_number" id="edit-bank-account-number-input" form="case-edit" value="<?php echo htmlspecialchars($case['bank_account_number'] ?? ''); ?>" inputmode="numeric" pattern="[0-9]*" required>
                        </div>
                        <div class="alpro-field">
                            <label>Account Holder Name <span class="aap-req">*</span></label>
                            <input class="alpro-input" type="text" name="bank_account_holder" form="case-edit" value="<?php echo htmlspecialchars($case['bank_account_holder'] ?? ''); ?>" required>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
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
                                                <?php if ($original_note_editable): ?>
                                                    <span class="note-actions" style="display:flex; gap:8px; flex-shrink:0;">
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
                                        <?php if ($original_note_editable): ?>
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
                                    <?php $note_editable = aapCaseEvidenceItemEditable($note['created_by'], $note['timestamp'], $can_add_evidence, $id_user, $aap_is_admin, $case_phase_started_at); ?>
                                    <li style="display:block;">
                                        <div class="note-view" id="note-view-<?php echo $note['id']; ?>">
                                            <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:8px;">
                                                <div class="note-text" style="height:80px; max-height:400px; overflow-y:auto; resize:vertical; border:1px solid #e9ecef; border-radius:6px; padding:6px 8px; background:#fff; width:100%; box-sizing:border-box;"><?php echo nl2br(htmlspecialchars($note['note'])); ?></div>
                                                <?php if ($note_editable): ?>
                                                    <span class="note-actions" style="display:flex; gap:8px; flex-shrink:0;">
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
                                        <?php if ($note_editable): ?>
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
                                <?php $att_editable = aapCaseEvidenceItemEditable($att['uploaded_by'], $att['timestamp'], $can_add_evidence, $id_user, $aap_is_admin, $case_phase_started_at); ?>
                                <li>
                                    <a href="?id=<?php echo $id; ?>&download=<?php echo $att['id']; ?>"><i class="bi bi-file-earmark-arrow-down"></i> <?php echo htmlspecialchars($att['file_name']); ?></a>
                                    <span style="display:flex; align-items:center; gap:8px;">
                                        <span style="font-size:11px; color:#6c757d;"><?php echo htmlspecialchars($att['uploaded_by_name'] ?: 'Unknown'); ?> &middot; <?php echo date('d-m-Y', strtotime($att['timestamp'])); ?></span>
                                        <?php if ($att_editable): ?>
                                            <form method="post" action="" class="attachment-delete-form" style="display:inline;" onsubmit="return aapConfirmDeleteAttachment();">
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

                <?php if ($can_add_evidence): ?>
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
                    <button class="alpro-btn alpro-btn-blue" type="submit" form="case-edit" id="case-edit-save" style="flex:0 0 auto; padding:8px 20px; font-size:14px; border-radius:8px;">Save Changes</button>
                    <button class="alpro-btn alpro-btn-grey" type="button" id="case-edit-cancel" style="flex:0 0 auto; padding:8px 20px; font-size:14px; border-radius:8px; border:1px solid #000;">Cancel</button>
                </div>
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
