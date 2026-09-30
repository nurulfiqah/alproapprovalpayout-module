<?php
// Streams one Execution attachment that's been explicitly flagged
// show_in_fixit=1 - linked only from fixit/index_specific.php's "AAP Case
// Progression" widget, for a Fixit viewer who isn't necessarily an AAP user
// at all (may not satisfy aap_update.php's own $can_view/$can_execute
// gates). Deliberately its own endpoint rather than relaxing
// aap_update.php's download_exec - that one gates on AAP's own case
// visibility, which a Fixit-side viewer legitimately doesn't have; this one
// re-derives FIXIT's own permission model instead (mirrors
// fixit/index_specific.php's $can_view_aap_progression check), independent
// of whatever link got them here.
ob_start();
require_once('../lock_adv.php');
$connect = 1;
include('../common/index_adv.php');
if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection not available.");
}
require_once('aap_lib.php');

$att_id = (int)($_GET['id'] ?? 0);
$stmt = $conn->prepare("
    SELECT a.*, c.fixit_record_id
    FROM aap_case_execution_attachments a
    JOIN aap_cases c ON c.id = a.case_id
    WHERE a.id = ? AND a.show_in_fixit = 1
");
$stmt->bind_param("i", $att_id);
$stmt->execute();
$att = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$att || empty($att['fixit_record_id'])) {
    die("Attachment not found or not shared.");
}

$fr_stmt = $conn->prepare("SELECT department_id FROM fixit_record WHERE id = ?");
$fr_stmt->bind_param("i", $att['fixit_record_id']);
$fr_stmt->execute();
$fr = $fr_stmt->get_result()->fetch_assoc();
$fr_stmt->close();

// Same rule as fixit/index_specific.php's $can_view_aap_progression -
// $fixit_autho comes from lock_adv.php (staff.fixit): '1' = full Fixit
// access, '2' = scoped to the ticket's own department only.
$can_view_shared = isset($fixit_autho) && ($fixit_autho == '1' || ($fixit_autho == '2' && $fr && (int)$fr['department_id'] === (int)$department));
if (!$can_view_shared) {
    die("You do not have access to this attachment.");
}

$content = corpNasConnect()->download($att['stored_name']);
if ($content === false) {
    die("Attachment not found.");
}
ob_end_clean();
header('Content-Description: File Transfer');
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . basename($att['file_name']) . '"');
header('Content-Length: ' . strlen($content));
echo $content;
exit;
