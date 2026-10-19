<?php
// Returns JSON {unread: N, items: [...]} for the logged-in user. Powers the header notifications dropdown.
require_once __DIR__ . '/config/db.php';
header('Content-Type: application/json');
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['unread' => 0, 'items' => []]);
    exit;
}
require_once __DIR__ . '/config/notifications.php';
$userId = (int)$_SESSION['user_id'];
$items = [];
$stmt = $conn->prepare("SELECT notification_id, title, message, type, is_read, created_at FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 8");
if ($stmt) {
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $items[] = [
            'id' => (int)$row['notification_id'],
            'title' => (string)($row['title'] ?? ''),
            'message' => (string)($row['message'] ?? ''),
            'type' => (string)($row['type'] ?? ''),
            'is_read' => (int)$row['is_read'],
            'when' => date("M d, Y, h:i A", strtotime($row['created_at'] ?? 'now')),
        ];
    }
    $stmt->close();
}
echo json_encode(['unread' => get_unread_notification_count($conn, $userId), 'items' => $items]);
