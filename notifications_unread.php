<?php
// Returns JSON {unread: N} for the logged-in user. Used for real-time badge polling.
require_once __DIR__ . '/config/db.php';
header('Content-Type: application/json');
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['unread' => 0]);
    exit;
}
require_once __DIR__ . '/config/notifications.php';
$userId = (int)$_SESSION['user_id'];
echo json_encode(['unread' => get_unread_notification_count($conn, $userId)]);
