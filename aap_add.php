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
$aap_is_superadmin = aapFetchIsSuperAdmin($conn, $id_user);
$aap_is_admin = aapIsAdmin($grade, $aap_dept_ids, $aap_is_superadmin, aapFetchAapLevel($conn, $id_user));
$aap_can_manage_all_depts = aapCanManageAllDepartments($grade, $aap_dept_ids, $aap_is_superadmin);
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

// Raising is scoped to the Fixit report's own department, same rule as
// admin/aap_admin.php's Case Type management - any staff member belonging
// to that department can raise the case (no value/tier check here at all;
// that's decided later, live, at the Approval Gate via each Case Type's
// Level 2/3 Staff Tier lists - see aapCanApprove() in aap_lib.php).
// Full-list here is grade>=4/SuperAdmin/Customer Support
// (aapCanManageAllDepartments()) PLUS Operations - Operations is not part
// of that function's set (it doesn't manage Case Types generally), but they
// already see and act on every department's ticket in the whole "Incoming
// from Fixit" queue on index.php ($aap_can_see_incoming there), so they need
// the same cross-department raise ability here to keep that workflow working.
$aap_can_raise_any_dept = $aap_can_manage_all_depts || aapIsOperations($aap_dept_ids);
if (!$fixit_record || !aapDeptInScope($fixit_record['department_id'], $aap_dept_ids, $aap_can_raise_any_dept)) {
    die("You can only raise a case for a Fixit report lodged against your own department.");
}

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

// The Department filter defaults to (and is locked to) the originating
// Fixit report's own department, not the requester's own - a case raised
// from a Fixit ticket lodged against Academy must pick from Academy's Case
// Types even if the staff member raising it personally belongs to a
// different department (e.g. Customer Support handling the handoff). Every
// visit here has a fixit record (aap_add.php redirects to index.php
// otherwise, see above), so this is always set.
$prefill_dept_id = $fixit_record['department_id'] ?? null;

if (isset($_POST['raise_case'])) {
    $case_type_id = (int)$_POST['case_type_id'];
    $ct = aapFetchCaseType($conn, $case_type_id);
    $fixit_record_id = isset($_POST['fixit_record_id']) && $_POST['fixit_record_id'] !== '' ? (int)$_POST['fixit_record_id'] : null;

    // The Department picker is locked client-side to the Fixit report's own
    // department (see $prefill_dept_id above) purely as a convenience/UI
    // guarantee - it does not itself stop a tampered POST from submitting a
    // case_type_id belonging to a different department, so that match is
    // re-verified here server-side.
    if (!$ct || (int)$ct['recycle'] === 1) {
        $msg = "Invalid or retired Case Type selected.";
        $msg_type = "alpro-danger";
    } elseif ((int)$ct['department_id'] !== (int)$fixit_record['department_id']) {
        $msg = "That Case Type does not belong to this Fixit report's department.";
        $msg_type = "alpro-danger";
    } else {
        // Requester Type / Requesting Channel are no longer collected on this
        // form - Requester Type falls back to 'customer' (Case Type no longer
        // carries its own default_requester_type - dropped from aap_case_types
        // when Case Types moved to the Staff Tier approval model); Requesting
        // Channel has no equivalent default, so it's left blank.
        $requester_type      = in_array($_POST['requester_type'] ?? null, ['customer','outlet','bu'], true) ? $_POST['requester_type'] : 'customer';
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
                 physical_confirm_required, physical_confirm_status, approval_status, execution_status, case_status,
                 created_by, timestamp, updated_at, fixit_record_id)
            VALUES
                ('', ?, ?, ?, ?, ?,
                 ?, ?, ?, ?, ?, ?,
                 ?, ?, 'pending', 'pending', 'draft',
                 ?, ?, ?, ?)
        ");
        $stmt->bind_param(
            "issiisssdssisissi",
            $case_type_id, $requester_type, $requesting_channel, $id_user, $my_dept_id,
            $customer_membership_id, $transaction_ref, $evidence_note, $calculated_value, $value_type, $recommended_outcome,
            $physical_required, $physical_status,
            $id_user, $now, $now, $fixit_record_id
        );

        if ($stmt->execute()) {
            $new_id = $stmt->insert_id;
            $stmt->close();

            $case_ref = aapGenerateCaseRef($new_id);
            $conn->query("UPDATE aap_cases SET case_ref = '" . $conn->real_escape_string($case_ref) . "' WHERE id = " . $new_id);

            // Evidence uploads - stored on the corporate NAS, see aapUploadEvidenceFiles()
            aapUploadEvidenceFiles($conn, $new_id, $_FILES['evidence'] ?? null, $id_user, $now);

            $audit_note = "Case raised as $case_ref (" . $ct['case_type_name'] . ", " . aapRequesterTypeLabel($requester_type) . ")";
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
                            <select class="alpro-input" id="department_id_filter" required disabled style="height:38px;">
                                <option value="">Select Department</option>
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?php echo $dept['id']; ?>" <?php echo ($prefill_dept_id == $dept['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($dept['depart_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="alpro-muted" style="font-size:12px; margin:6px 0 0;">Fixed to the originating Fixit report's department - pick the Case Type from that department's list.</p>
                        </div>

                        <div class="alpro-field" style="flex:2; min-width:0;">
                            <label>Case Type <span class="aap-req">*</span></label>
                            <select class="alpro-input" name="case_type_id" id="case_type_id" required style="height:38px;">
                                <option value="">Select Case Type</option>
                                <?php foreach ($case_types as $ct): ?>
                                    <option value="<?php echo $ct['id']; ?>"
                                        data-department-id="<?php echo (int)$ct['department_id']; ?>"
                                        data-physical="<?php echo (int)$ct['physical_confirm_required']; ?>"
                                        data-desc="<?php echo htmlspecialchars($ct['description'] ?? ''); ?>">
                                        <?php echo htmlspecialchars($ct['case_type_name']); ?>
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
                    <button class="alpro-btn alpro-btn-blue" type="submit" name="raise_case" style="flex:0 0 auto; padding:8px 20px; font-size:14px; border-radius:8px;">Raise Case</button>
                    <button class="alpro-btn alpro-btn-grey" type="button" onclick="window.location.reload();" style="flex:0 0 auto; padding:8px 20px; font-size:14px; border-radius:8px; border:1px solid #000;">Refresh</button>
                </div>
            </div>
        </div>
    </form>
    <?php endif; ?>
</div>

<?php $page_js = 'js/aap_add.js'; include('aap_footer.php'); ?>
