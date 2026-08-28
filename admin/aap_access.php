<?php
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
    die("Admin access only. This page documents the module's access model.");
}
$aap_base = '../';
?>

<?php include('../aap_modern_head.php'); ?>

<div class="header">
  <b class="rtop"><b class="r1"></b><b class="r2"></b><b class="r3"></b><b class="r4"></b></b>
  <h1 class="headerH1"><img src="../img/logo.svg"> Access &amp; Authorization (Admin)</h1>
  <b class="rbottom"><b class="r4"></b><b class="r3"></b><b class="r2"></b><b class="r1"></b></b>
</div>

<?php include('../aap_sidebar.php'); ?>

<div class="aap-modern">

<div class="aap-page-title-row">
    <h2 class="aap-page-title"><i class="bi bi-shield-lock"></i> Access &amp; Authorization</h2>
    <div class="aap-page-actions">
        <a href="aap_admin.php" class="alpro-btn alpro-btn-grey" style="text-decoration:none;"><i class="bi bi-arrow-left"></i> Back to Admin</a>
    </div>
</div>

<div class="aap-access-note" style="background:#e6f6ed; color:#146c37; border-color:#b7e4c7;">
    <strong>SuperAdmin union:</strong> <code>staff.aap</code> is AAP's own SuperAdmin flag, unioned with <code>staff.okr</code> and <code>staff.atem</code> (via <code>aapFetchIsSuperAdmin()</code>) — a SuperAdmin flagged in any one of the three modules gets full admin access in all three, mirroring how OKR and ATEM already union each other's flag. Re-queried independently at every entry point, not session-cached.
</div>

<div class="aap-bento">

    <div class="aap-bento-item aap-span-12">
        <div class="aap-card aap-access-section">
            <h6 class="aap-card-title"><i class="bi bi-diagram-3"></i> The two inputs every check is built from</h6>
            <p class="aap-card-hint" style="margin-top:10px;">Every access decision in this module combines exactly two pieces of session state, both read from the outer <code>odb</code> app's shared <code>staff</code> table:</p>
            <ul style="margin: 0; padding-left: 20px; font-size: 14px; line-height: 1.9;">
                <li><strong><code>staff.grade</code></strong> (0–5) — determines Executive vs Manager tier eligibility on the Operations approval gate.</li>
                <li><strong><code>staff.department</code></strong> (comma-separated department ids) — determines which functional role applies: Operations, Customer Support, or Digital Innovation.</li>
            </ul>
        </div>
    </div>

    <div class="aap-bento-item aap-span-12">
        <div class="aap-card aap-access-section">
            <h6 class="aap-card-title"><i class="bi bi-people"></i> Department-keyed roles</h6>
            <table class="alpro-table aap-access-table" width="100%">
                <tr><th>Constant</th><th>Dept ID</th><th>Meaning</th></tr>
                <tr>
                    <td><code>AAP_DEPT_OPERATION</code></td>
                    <td>13</td>
                    <td>Sole execution authority — tags/confirms physical returns, executes cases, closes cases.</td>
                </tr>
                <tr>
                    <td><code>AAP_DEPT_CUSTOMER_SUPPORT</code></td>
                    <td>27</td>
                    <td>Approval pool for <code>cs_tier</code>-mode Case Types, alongside Operations (see <code>aapCanApprove()</code> below) — a staff member still needs a personal RM ceiling to actually approve.</td>
                </tr>
                <tr>
                    <td><code>AAP_DEPT_DIGITAL_INNOVATION</code></td>
                    <td>16</td>
                    <td>Currently one of two paths to module admin (the other being grade ≥ 4).</td>
                </tr>
            </table>
        </div>
    </div>

    <div class="aap-bento-item aap-span-12">
        <div class="aap-card aap-access-section">
            <h6 class="aap-card-title"><i class="bi bi-key"></i> Admin — <code>aapIsAdmin()</code></h6>
            <p style="margin:10px 0 0;"><code>grade &gt;= 4</code> <strong>OR</strong> in Digital Innovation (dept 16) <strong>OR</strong> SuperAdmin (<code>staff.aap</code>/<code>okr</code>/<code>atem</code> = 1). Bypasses every other gate below.</p>
        </div>
    </div>

    <div class="aap-bento-item aap-span-12">
        <div class="aap-card aap-access-section">
            <h6 class="aap-card-title"><i class="bi bi-code-square"></i> Shared checks (<code>aap_lib.php</code>)</h6>
            <table class="alpro-table aap-access-table" width="100%">
                <tr><th>Function</th><th>Rule</th></tr>
                <tr><td><code>aapIsOperations()</code></td><td>Member of Operations (dept 13).</td></tr>
                <tr><td><code>aapIsCustomerSupport()</code></td><td>Member of Customer Support (dept 27).</td></tr>
                <tr><td><code>aapIsAdmin()</code></td><td>Grade ≥ 4, Digital Innovation (dept 16), or SuperAdmin union (<code>staff.aap</code>/<code>okr</code>/<code>atem</code>).</td></tr>
                <tr><td><code>aapFetchIsSuperAdmin()</code></td><td>Returns true if <code>staff.aap</code>, <code>staff.okr</code>, or <code>staff.atem</code> is 1 for the current user.</td></tr>
                <tr><td><code>aapCanExecute()</code></td><td>Admin, or Operations with grade ≥ 1 — regardless of the case's value.</td></tr>
                <tr><td><code>aapGetStaffThreshold()</code></td><td>Reads the current user's personal RM approval ceiling from <code>aap_staff_thresholds</code>. Returns <code>null</code> for unlimited, <code>false</code> for no row (no approval rights at all), or the numeric ceiling.</td></tr>
                <tr><td><code>aapCanApprove()</code></td><td>Admin, or (a) in the right pool for the case's Approver Mode — Operations for Operations Tier, Operations <strong>or</strong> Customer Support for CS Tier, same department as the requester for BU Sign-off — <strong>and</strong> (b) a personal RM ceiling (<code>aapGetStaffThreshold()</code>) that covers the case's calculated value. Replaced the old grade-based Operations tier, CS Level 1/2, and BU sign-off grade rule with one unified per-staff ceiling, set inline from the "Staff in Pool" panels on <code>aap_admin.php</code>'s Case Type form.</td></tr>
                <tr><td><code>aapScopeWhere()</code></td><td>Visibility filter for case lists — Admin/Operations see every case; everyone else sees only cases they raised or that were raised by their own department(s).</td></tr>
            </table>
        </div>
    </div>

    <div class="aap-bento-item aap-span-12">
        <div class="aap-card aap-access-section">
            <h6 class="aap-card-title"><i class="bi bi-file-earmark-code"></i> Page-level gates (<code>aap_update.php</code> / <code>aap_delete.php</code>)</h6>
            <p class="aap-card-hint" style="margin-top:10px;">Each combines the shared checks above with the case's current state (draft/open/pending/etc.) and issuer identity — these aren't reusable functions, they're computed per-page from the current case row.</p>
            <table class="alpro-table aap-access-table" width="100%">
                <tr><th>Flag</th><th>Gates</th></tr>
                <tr><td><code>$can_view</code></td><td>Who can open the case detail page at all.</td></tr>
                <tr><td><code>$can_edit_case</code></td><td>Case Details form + Add Evidence — issuer or admin, and case not locked/further along (draft or open, approval still pending, physical confirm not past "pending").</td></tr>
                <tr><td><code>$can_open_case</code></td><td>Moving a Draft into the active approval workflow — issuer or admin, all required fields filled.</td></tr>
                <tr><td><code>$can_tag_physical</code></td><td>Tag Item Returned step — issuer, Operations, or admin.</td></tr>
                <tr><td><code>$can_confirm_physical</code></td><td>Confirm Receipt at ACMM — Operations or admin only.</td></tr>
                <tr><td><code>$can_execute</code></td><td>Execute &amp; Close Case — same as <code>aapCanExecute()</code>.</td></tr>
                <tr><td><code>$can_close</code></td><td>Notify Requester &amp; Close Case — admin, Operations, or the original issuer.</td></tr>
                <tr><td><code>$can_void</code></td><td>Void Case (<code>aap_delete.php</code>) — case still draft or open with approval pending, issuer or admin.</td></tr>
            </table>
        </div>
    </div>

</div>
</div>

<?php include('../aap_footer.php'); ?>
