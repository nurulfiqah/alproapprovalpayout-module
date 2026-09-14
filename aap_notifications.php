<?php
/**
 * AJAX endpoint for the AAP notification bell (aap_sidebar.php).
 * Mirrors okr/backend.php's listNotifications/markNotificationRead/
 * markAllNotificationsRead actions.
 */
// lock_adv.php echoes HTML before its redirect check can run, which would
// corrupt the JSON response below - buffer and discard it (same trick used
// in aap_update.php's download/AJAX branches).
ob_start();
require_once('../lock_adv.php');
$connect = 1;
include('../common/index_adv.php');
if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection not available.");
}
require_once('aap_lib.php');
ob_end_clean();

// PHP's default session handler locks the session file for the entire
// duration of a request that has it open - lock_adv.php's session_start()
// already happened by this point and $id_user is read out of it, so nothing
// below needs the session anymore. This endpoint is polled every 8 seconds
// from EVERY open AAP tab (see js/aap-sidebar.js) - without releasing the
// lock here, each poll (and every other click from the same browser, since
// they share one session file) queues up behind whichever request happens
// to be holding it, which is exactly what made the whole app intermittently
// "freeze" until a page was refreshed.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

header('Content-Type: application/json');

$action = $_GET['action'] ?? ($_POST['action'] ?? '');

if ($action === 'listNotifications' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode([
        'success'      => true,
        'unread_count' => aapUnreadNotificationCount($conn, $id_user),
        'data'         => aapFetchNotifications($conn, $id_user),
    ]);
    exit;
}

if ($action === 'markNotificationRead' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    aapMarkNotificationRead($conn, (int)($_POST['id'] ?? 0), $id_user);
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'markAllNotificationsRead' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    aapMarkAllNotificationsRead($conn, $id_user);
    echo json_encode(['success' => true]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action.']);
