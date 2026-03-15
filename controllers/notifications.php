<?php ob_start();

header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { ob_end_clean(); http_response_code(200); exit(); }

/* ================= JWT VALIDATION ================= */

require_once "../config/jwt.php";
use Config\JWT;

$payload = JWT::validateRequest();

require_once "../config/db.php";
use Config\Database;

try {
    $db     = (new Database())->connect();
    $userId = (int) $payload['user_id'];

    /* Fetch from notifications table — role-based: user-specific OR broadcast (user_id IS NULL) */
    $stmt = $db->prepare("
        SELECT
            id,
            title,
            message,
            type,
            priority,
            is_read,
            created_at
        FROM notifications
        WHERE (user_id = ? OR user_id IS NULL)
        ORDER BY created_at DESC
        LIMIT 60
    ");
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $notifications = array_map(fn($r) => [
        'id'         => (int) $r['id'],
        'title'      => $r['title'],
        'message'    => $r['message'],
        'type'       => $r['type']     ?? 'Alert',
        'priority'   => $r['priority'] ?? 'Medium',
        'is_read'    => (int) ($r['is_read'] ?? 0),
        'created_at' => $r['created_at'],
    ], $rows);

    ob_end_clean();
    echo json_encode(["success" => true, "notifications" => $notifications]);

} catch (Throwable $e) {
    ob_end_clean(); http_response_code(500);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}