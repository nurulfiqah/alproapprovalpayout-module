<?php
// AJAX endpoint for the Customer/Membership ID typeahead on aap_add.php -
// searches the same shared `customer` table (Xilnex-synced) mamabe's own
// customer search uses (mamabe_search_customer.php), just returning JSON
// instead of the old jquery.autocomplete text format since this module has
// no jQuery.
// lock_adv.php echoes HTML before its redirect check can run - buffer and
// discard it so the JSON response isn't corrupted (same trick used
// elsewhere in this module, e.g. aap_notifications.php).
ob_start();
require_once('../lock_adv.php');
$connect = 1;
include('../common/index_adv.php');
ob_end_clean();
header('Content-Type: application/json');

if (!isset($conn) || !($conn instanceof mysqli)) {
    echo json_encode([]);
    exit;
}

$q = trim($_GET['q'] ?? '');
if ($q === '' || strlen($q) < 2) {
    echo json_encode([]);
    exit;
}

$like = $conn->real_escape_string($q);
$res = $conn->query("
    SELECT c_id, ic, customer_name, phone
    FROM customer
    WHERE recycle = 0 AND (customer_name LIKE '%$like%' OR ic LIKE '%$like%' OR c_id LIKE '%$like%')
    ORDER BY customer_name ASC
    LIMIT 15
");

$rows = [];
while ($res && $row = $res->fetch_assoc()) {
    $rows[] = [
        'c_id' => $row['c_id'],
        'ic' => $row['ic'],
        'customer_name' => $row['customer_name'],
        'phone' => $row['phone'],
    ];
}
echo json_encode($rows);
