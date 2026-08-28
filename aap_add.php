<?php
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
$my_dept_id = !empty($aap_dept_ids) ? $aap_dept_ids[0] : null;
if ((int)$grade < 1 && !$aap_is_admin) {
    die("You do not have access to this module.");
}

$msg = "";
$msg_type = "";

//Handoff from Fixit (fixit/add_report.php): carries the originating ticket
//id/ref through to the insert. No Family pre-filter - there's no mapping
//yet from a Fixit category to an AAP Family, so the requester just picks
//the Case Type directly from the full list.
$prefill_fixit_id  = isset($_GET['fixit_id']) && $_GET['fixit_id'] !== '' ? (int)$_GET['fixit_id'] : null;
$prefill_fixit_ref = isset($_GET['fixit_ref']) ? trim($_GET['fixit_ref']) : '';

// Cases can no longer be self-raised - every case must originate from a
// Fixit ticket (fixit/add_report.php's "Continue to Raise Approval Case"
// handoff, or the Incoming from Fixit queue on index.php), both of which
// pass fixit_id through. No fixit_id means someone reached this page
// directly, so bounce them back to the queue instead of allowing a raise.
if (!$prefill_fixit_id) {
    header("Location: index.php");
    exit;
}

$fixit_record = aapFetchFixitRecord($conn, $prefill_fixit_id);
$fixit_attachments = aapFetchFixitAttachments($conn, $prefill_fixit_id);

// Evidence Note stays blank by default — the original Fixit report is shown
// in its own card above the form instead of being duplicated in here.
$default_evidence_note = isset($_POST['evidence_note']) ? $_POST['evidence_note'] : '';

// Every active Case Type, regardless of department - the Department picker
// below filters this list client-side (data-department-id on each option),
// since each department now has its own Case Type list.
$case_types = aapFetchCaseTypes($conn);
if (empty($case_types)) {
    $msg = "No active Case Types are configured yet. Ask an admin to set up the Case Type Registry first.";
    $msg_type = "alpro-warn";
}

$departments = aapFetchDepartmentsWithCaseTypes($conn);

if (isset($_POST['raise_case'])) {
    $case_type_id = (int)$_POST['case_type_id'];
    $ct = aapFetchCaseType($conn, $case_type_id);
    $fixit_record_id = isset($_POST['fixit_record_id']) && $_POST['fixit_record_id'] !== '' ? (int)$_POST['fixit_record_id'] : null;

    if (!$ct || (int)$ct['recycle'] === 1) {
        $msg = "Invalid or retired Case Type selected.";
        $msg_type = "alpro-danger";
    } else {
        // Requester Type / Requesting Channel are no longer collected on this
        // form - Requester Type falls back to the Case Type's own default;
        // Requesting Channel has no equivalent default, so it's left blank.
        $requester_type      = in_array($_POST['requester_type'] ?? null, ['customer','outlet','bu'], true) ? $_POST['requester_type'] : $ct['default_requester_type'];
        $requesting_channel  = trim($_POST['requesting_channel'] ?? '');
        $customer_membership_id = trim($_POST['customer_membership_id']);
        $transaction_ref     = trim($_POST['transaction_ref']);
        $evidence_note       = trim($_POST['evidence_note']);
        $calculated_value    = ($_POST['calculated_value'] !== '') ? (float)$_POST['calculated_value'] : null;
        $value_type           = in_array($_POST['value_type'], ['cash','points'], true) ? $_POST['value_type'] : 'cash';
        $recommended_outcome = trim($_POST['recommended_outcome']);
        $now = date('Y-m-d H:i:s');

        $physical_required = (int)$ct['physical_confirm_required'];
        $physical_status = $physical_required ? 'pending' : 'not_required';

        $stmt = $conn->prepare("
            INSERT INTO aap_cases
                (case_ref, case_type_id, requester_type, requesting_channel, requester_staff_id, requester_department_id,
                 customer_membership_id, transaction_ref, evidence_note, calculated_value, value_type, recommended_outcome,
                 physical_confirm_required, physical_confirm_status, approver_mode, ops_tier_required, approval_status, execution_status, case_status,
                 created_by, timestamp, updated_at, fixit_record_id)
            VALUES
                ('', ?, ?, ?, ?, ?,
                 ?, ?, ?, ?, ?, ?,
                 ?, ?, ?, ?, 'pending', 'pending', 'draft',
                 ?, ?, ?, ?)
        ");
        $approver_mode_val = $ct['approver_mode'];
        $ops_tier_required_val = $ct['ops_tier_required'];
        $stmt->bind_param(
            "issiisssdssisssissi",
            $case_type_id, $requester_type, $requesting_channel, $id_user, $my_dept_id,
            $customer_membership_id, $transaction_ref, $evidence_note, $calculated_value, $value_type, $recommended_outcome,
            $physical_required, $physical_status, $approver_mode_val, $ops_tier_required_val,
            $id_user, $now, $now, $fixit_record_id
        );

        if ($stmt->execute()) {
            $new_id = $stmt->insert_id;
            $stmt->close();

            $case_ref = aapGenerateCaseRef($new_id);
            $conn->query("UPDATE aap_cases SET case_ref = '" . $conn->real_escape_string($case_ref) . "' WHERE id = " . $new_id);

            // Evidence uploads - stored on the corporate NAS, see aapUploadEvidenceFiles()
            aapUploadEvidenceFiles($conn, $new_id, $_FILES['evidence'] ?? null, $id_user, $now);

            $audit_note = "Case raised as $case_ref (" . $ct['name'] . ", " . aapRequesterTypeLabel($requester_type) . ")";
            if ($fixit_record_id) $audit_note .= ", linked from Fixit ticket F$fixit_record_id";
            aapLogAudit($conn, $new_id, 'case_raised', $id_user, $audit_note);

            header("Location: aap_update.php?id=$new_id&created=1");
            exit;
        } else {
            $msg = "Error raising case: " . $stmt->error;
            $msg_type = "alpro-danger";
        }
    }
}
?>

<?php include('aap_modern_head.php'); ?>

<div class="header">
  <b class="rtop"><b class="r1"></b><b class="r2"></b><b class="r3"></b><b class="r4"></b></b>
  <h1 class="headerH1"><img src="img/logo.svg"> Raise New Case</h1>
  <b class="rbottom"><b class="r4"></b><b class="r3"></b><b class="r2"></b><b class="r1"></b></b>
</div>

<?php include('aap_sidebar.php'); ?>

<div class="aap-modern">

    <div class="aap-page-title-row">
        <h2 class="aap-page-title"><i class="bi bi-plus-circle"></i> Raise New Case</h2>
        <div class="aap-page-actions">
            <a href="index.php" class="alpro-btn alpro-btn-grey" style="text-decoration:none;"><i class="bi bi-arrow-left"></i> Back to Case Queue</a>
        </div>
    </div>

    <?php if (!empty($msg)): ?>
        <div class="alpro-alert <?php echo $msg_type; ?>"><?php echo htmlspecialchars($msg); ?></div>
    <?php endif; ?>

    <?php if ($prefill_fixit_id): ?>
    <div class="aap-card aap-fixit-card" style="margin-bottom: 1rem;">
        <div class="aap-card-title-row">
            <h6 class="aap-card-title"><i class="bi bi-life-preserver"></i> Original Fixit Report — <?php echo htmlspecialchars($prefill_fixit_ref ?: ('F' . $prefill_fixit_id)); ?></h6>
            <a href="<?php echo htmlspecialchars(aapFixitTicketUrl($prefill_fixit_id)); ?>" target="_blank" style="font-size:12px;">View in Fixit <i class="bi bi-box-arrow-up-right"></i></a>
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
            <div class="alpro-field aap-fact-block"><label>Remark</label><div><?php echo nl2br(htmlspecialchars($fixit_record['remark'])); ?></div></div>
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
            <p class="aap-card-hint" style="margin-bottom:0;">Ticket details could not be loaded.</p>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($case_types)): ?>
    <form method="post" action="" enctype="multipart/form-data">
        <?php if ($prefill_fixit_id): ?>
            <input type="hidden" name="fixit_record_id" value="<?php echo (int)$prefill_fixit_id; ?>">
        <?php endif; ?>

        <div class="aap-bento">
            <div class="aap-bento-item aap-span-12">
                <div class="aap-card">
                    <h6 class="aap-card-title"><i class="bi bi-signpost-split"></i> Case Type</h6>
                    <div class="alpro-grid" style="flex-wrap:nowrap;">
                        <div class="alpro-field" style="flex:1; min-width:0;">
                            <label>Department <span class="aap-req">*</span></label>
                            <select class="alpro-input" id="department_id_filter" required style="height:38px;">
                                <option value="">Select Department</option>
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?php echo $dept['id']; ?>" <?php echo ($my_dept_id == $dept['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($dept['depart_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="alpro-field" style="flex:2; min-width:0;">
                            <label>Case Type <span class="aap-req">*</span></label>
                            <select class="alpro-input" name="case_type_id" id="case_type_id" required style="height:38px;">
                                <option value="">Select Case Type</option>
                                <?php foreach ($case_types as $ct): ?>
                                    <option value="<?php echo $ct['id']; ?>"
                                        data-department-id="<?php echo (int)$ct['department_id']; ?>"
                                        data-requester-type="<?php echo htmlspecialchars($ct['default_requester_type']); ?>"
                                        data-physical="<?php echo (int)$ct['physical_confirm_required']; ?>"
                                        data-approver-mode="<?php echo htmlspecialchars($ct['approver_mode']); ?>"
                                        data-turnaround="<?php echo htmlspecialchars($ct['turnaround_days'] ?? ''); ?>"
                                        data-systems="<?php echo htmlspecialchars($ct['systems_note'] ?? ''); ?>"
                                        data-desc="<?php echo htmlspecialchars($ct['description'] ?? ''); ?>">
                                        <?php echo htmlspecialchars($ct['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div id="ct_info" class="aap-card-hint" style="display:none; background:#f7f9fb; border:1px solid #e5e9ec; border-radius:6px; padding:10px; margin-top:10px;"></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="aap-bento-item aap-span-12">
                <div class="aap-card">
                    <h6 class="aap-card-title"><i class="bi bi-file-earmark-text"></i> Case Details</h6>

                    <div class="alpro-grid">
                        <div class="alpro-field" style="position:relative;">
                            <label>Customer / Membership ID</label>
                            <input class="alpro-input" type="text" name="customer_membership_id" id="customer-lookup-input" placeholder="Type name, IC, or membership ID to search..." autocomplete="off">
                            <ul id="customer-lookup-results" class="aap-attach-list" style="display:none; position:absolute; z-index:20; left:0; right:0; margin-top:4px; max-height:260px; overflow-y:auto; box-shadow:0 8px 20px rgba(0,0,0,.1);"></ul>
                        </div>

                        <div class="alpro-field">
                            <label>Transaction / SO Number</label>
                            <input class="alpro-input" type="text" name="transaction_ref">
                        </div>
                    </div>

                    <div class="alpro-grid alpro-mt-10">
                        <div class="alpro-field">
                            <label>Calculated Value (Requestor)</label>
                            <input class="alpro-input" type="number" step="0.01" min="0" name="calculated_value">
                        </div>

                        <div class="alpro-field">
                            <label>Value Type</label>
                            <select class="alpro-input" name="value_type" id="value_type">
                                <option value="cash">Cash (RM)</option>
                                <option value="points">Points</option>
                            </select>
                        </div>
                    </div>

                    <div class="alpro-grid alpro-mt-10">
                        <div class="alpro-field" style="grid-column: 1 / -1;">
                            <label>Recommended Refund / Payout Outcome</label>
                            <textarea class="alpro-input" name="recommended_outcome" placeholder="e.g. Points, Cash refund, Exchange first" style="height:38px; max-height:150px; resize:vertical; overflow-y:auto;"></textarea>
                        </div>
                    </div>
                </div>
            </div>

            <div class="aap-bento-item aap-span-12">
                <div class="aap-card">
                    <h6 class="aap-card-title"><i class="bi bi-paperclip"></i> Evidence</h6>
                    <div class="alpro-grid">
                        <div class="alpro-field">
                            <label>Evidence Note</label>
                            <textarea class="alpro-input" name="evidence_note" style="height:100px;" placeholder="Receipt/SO match, photo/video, CCTV reference, ID proof, etc."><?php echo htmlspecialchars($default_evidence_note); ?></textarea>
                        </div>
                        <div class="alpro-field">
                            <label>Evidence Upload</label>
                            <input class="alpro-input" type="file" name="evidence[]" id="evidence-file-input" multiple>
                            <ul class="aap-attach-list" id="evidence-file-preview" style="margin-top:8px;"></ul>
                        </div>
                    </div>
                </div>
            </div>

            <div class="aap-bento-item aap-span-12">
                <div class="alpro-actions" style="padding-top: 4px; justify-content:flex-end;">
                    <button class="alpro-btn alpro-btn-grey" type="button" onclick="window.location.reload();" style="flex:0 0 auto;"><i class="bi bi-arrow-clockwise"></i> Refresh</button>
                    <button class="alpro-btn" type="submit" name="raise_case" style="flex:0 0 auto; background:#198754; color:#fff;"><i class="bi bi-check-lg"></i> Raise Case</button>
                </div>
            </div>
        </div>
    </form>
    <?php endif; ?>
</div>

<?php $page_js = 'js/aap_add.js'; include('aap_footer.php'); ?>
