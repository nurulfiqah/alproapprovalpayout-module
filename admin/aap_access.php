<?php
require_once('../../lock_adv.php');
$connect = 1;
date_default_timezone_set('Asia/Kuala_Lumpur');
include('../../common/index_adv.php');
if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection not available.");
}
require_once('../aap_lib.php');

// Resolves the SuperAdmin/admin-level union used across every AAP page.
$aap_identity = aapResolveIdentity($conn, $id_user, $grade, $department);
$grade = $aap_identity['grade'];
$department = $aap_identity['department'];
$aap_dept_ids = $aap_identity['dept_ids'];
$aap_is_admin = $aap_identity['is_admin'];
$aap_is_superadmin = $aap_identity['is_superadmin'];
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
                    <td>Self-handle exception: a Customer Support staff member can always act on a case they raised themselves, at any value, without needing to be on that Case Type's Approval Staff Tier list — see <code>aapCanApprove()</code> below.</td>
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
            <p style="margin:10px 0 0;"><code>grade &gt;= 4</code> <strong>OR</strong> in Digital Innovation (dept 16) <strong>OR</strong> Admin (<code>staff.aap</code> = 1, or <code>okr</code>/<code>atem</code> = 1). Bypasses every other gate below.</p>
        </div>
    </div>

    <div class="aap-bento-item aap-span-12">
        <div class="aap-card aap-access-section">
            <h6 class="aap-card-title"><i class="bi bi-shield-lock"></i> <code>staff.aap</code> is a flag, not a level</h6>
            <p style="margin:10px 0 0;"><code>staff.aap</code> is 0 (no access) or 1 (full AAP admin access — every admin page/feature, nothing held back). An earlier design split this into two tiers ("Admin 1" general vs "Admin 2" SuperAdmin, with SuperAdmin-only pages) — removed since there was never a real reason to hold anything back from Admin 1, so any <code>staff.aap</code> = 1 now counts toward the admin union (<code>aapFetchIsSuperAdmin()</code>) the same as an <code>okr</code>/<code>atem</code> SuperAdmin does.</p>
            <p style="margin:8px 0 0;"><code>admin/aap_settings.php</code> (grants/revokes <code>staff.aap</code> itself) and <code>admin/aap_department_managers.php</code> (grants the <code>aap_department_managers</code> access described below) both require this same admin union, same as every other admin page in the module — nothing here is gated more strictly than general <code>aapIsAdmin()</code> access anymore.</p>
            <p style="margin:8px 0 0;"><code>admin/aap_grouping_master.php</code> (Approval Unit Master) and <code>admin/aap_staff_assignments.php</code> also accept a Department Manager (see below), scoped down to just their own granted department(s); the shared Universal tier list on Approval Unit Master stays admin-only regardless.</p>
        </div>
    </div>

    <div class="aap-bento-item aap-span-12">
        <div class="aap-card aap-access-section">
            <h6 class="aap-card-title"><i class="bi bi-person-badge"></i> Department Managers (<code>aap_department_managers</code>)</h6>
            <p style="margin:10px 0 0;">A per-department allowlist, granted from <code>admin/aap_department_managers.php</code> (admin-only to manage) - lets a specific staff member manage one department's Case Types, Approval Unit Groups, and Staff Assignments lookups, independent of their grade or <code>staff.department</code>. Same idea as Fixit's <code>fixit_department.p_incharge</code>, but a real <code>staff_id</code> link rather than free text.</p>
            <p style="margin:8px 0 0;">Always <strong>additive</strong>: merged into a staff member's own department scope only after <code>aapCanManageAllDepartments()</code> is decided from their real grade/admin status, so a grant for one department (e.g. Customer Support) can never be mistaken for company-wide access. It never touches the shared Universal tier list on Approval Unit Master, only that department's own Group(s). Within a Case Type, reassigning WHICH department holds Level 2/Level 3 is reserved for whoever owns Level 1 (or a full-scope admin) - a Department Manager only curates the roster of a level once it's been handed to their own department (see <code>aapCaseTypeEditRights()</code>).</p>
        </div>
    </div>

    <div class="aap-bento-item aap-span-12">
        <div class="aap-card aap-access-section">
            <h6 class="aap-card-title"><i class="bi bi-code-square"></i> Shared checks (<code>aap_lib.php</code>)</h6>
            <table class="alpro-table aap-access-table" width="100%">
                <tr><th>Function</th><th>Rule</th></tr>
                <tr><td><code>aapIsOperations()</code></td><td>Member of Operations (dept 13).</td></tr>
                <tr><td><code>aapIsCustomerSupport()</code></td><td>Member of Customer Support (dept 27).</td></tr>
                <tr><td><code>aapIsAdmin()</code></td><td>Grade ≥ 4, Digital Innovation (dept 16), SuperAdmin union, or <code>staff.aap</code> &gt;= 1.</td></tr>
                <tr><td><code>aapFetchIsSuperAdmin()</code></td><td>Returns true if <code>staff.aap</code> &gt;= 1, or <code>staff.okr</code>/<code>atem</code> = 1, for the current user - the module's full-access admin union.</td></tr>
                <tr><td><code>aapFetchAapLevel()</code></td><td>Returns the current user's raw <code>staff.aap</code> value (0 or 1).</td></tr>
                <tr><td><code>aapCanExecute()</code></td><td>Admin, or Operations with grade ≥ 1 — regardless of the case's value.</td></tr>
                <tr><td><code>aapCanManageAllDepartments()</code></td><td>Grade ≥ 4 or admin only — decides who sees/manages EVERY department's Case Types, Approval Unit Groups, and Staff Assignments (the three admin/ pages) rather than just their own. Customer Support used to also qualify automatically here (they route/handle cases across every department) but that let any CS staff touch every other department's setup even when uninvolved — removed. A staff member (CS included) who's scoped to their own department can still be granted another specific department via <code>aap_department_managers</code> (<code>admin/aap_department_managers.php</code>, admin-only to grant) - see <code>aapFetchDeptManagerDepartmentIds()</code>.</td></tr>
                <tr><td><code>aapGetStaffThreshold()</code></td><td>Reads the current user's personal RM approval ceiling from <code>aap_staff_thresholds</code>. Returns <code>null</code> for unlimited, <code>false</code> for no row (no approval rights at all), or the numeric ceiling. Superseded by the per-Case-Type Staff Tier model below for approval decisions, but still used for the "Staff in Department" ceiling panel.</td></tr>
                <tr><td><code>aapCanApprove()</code></td><td>Admin, or (a) explicitly on the Case Type's Approval Staff Tier list (Level 2, <code>aap_admin.php</code>'s Case Type form) with a tier (Unlimited / Tier 1 &gt; RM5,000 / Tier 2 &le; RM5,000) covering the case's value, and (b) not on that Case Type's Exclusion list (Level 3) — exclusion always wins. Replaced the old grade-based Operations tier / CS Level 1/2 / BU sign-off department rule entirely. Customer Support self-handling their own case is checked separately in <code>aap_update.php</code>, not inside this function.</td></tr>
                <tr><td><code>aapScopeWhere()</code></td><td>Visibility filter for case lists — Admin/Operations see every case; everyone else sees cases they personally raised, or whose Case Type belongs to their own department(s) - i.e. what the case is actually about, not who happened to submit it (so a CS/Operations/admin staff member raising a case on behalf of another department's Fixit ticket doesn't hide it from that department).</td></tr>
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
                <tr><td><code>$can_edit_case</code></td><td>Case Details form + Add Evidence — issuer or admin, and case not locked/further along (draft or open, approval still pending, verification required not past "pending").</td></tr>
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
