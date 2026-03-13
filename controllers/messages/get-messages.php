<?php ob_start();

header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, X-Requested-With");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { ob_end_clean(); http_response_code(200); exit(); }

ini_set('session.cookie_samesite', 'Lax');
ini_set('session.cookie_secure', 0);
session_set_cookie_params(['lifetime'=>0,'path'=>'/','domain'=>'localhost','secure'=>false,'httponly'=>true,'samesite'=>'Lax']);
session_start();

if (!isset($_SESSION['user_id'])) {
    ob_end_clean();
    http_response_code(401);
    echo json_encode(["success" => false, "error" => "Not authenticated"]);
    exit;
}

require_once "../../config/db.php";
use Config\Database;

try {

    $db     = (new Database())->connect();
    $userId = (int) $_SESSION['user_id'];

    /* Fetch all messages received by this worker,
       join sender's full_name from users table */
    $stmt = $db->prepare("
        SELECT
            m.id,
            m.thread_id,
            m.sender_id,
            m.message,
            m.is_read,
            m.created_at,
            u.full_name AS sender_name
        FROM  messages m
        JOIN  users u ON u.id = m.sender_id
        WHERE m.receiver_id = :user_id
        ORDER BY m.created_at DESC
    ");

    $stmt->execute([':user_id' => $userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $messages = [];

    foreach ($rows as $row) {
        $messages[] = [
            'id'         => (string) $row['id'],
            'threadId'   => (string) $row['thread_id'],
            'senderId'   => (string) $row['sender_id'],
            'sender'     => $row['sender_name'],
            'message'    => $row['message'],
            'isRead'     => (bool)   $row['is_read'],
            'time'       => formatTime($row['created_at']),
            'rawTime'    => $row['created_at'],
        ];
    }

    $unreadCount = count(array_filter($messages, fn($m) => !$m['isRead']));

    ob_end_clean();
    echo json_encode([
        "success"     => true,
        "messages"    => $messages,
        "unreadCount" => $unreadCount,
    ]);

} catch (Exception $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}

/* ── helpers ── */

function formatTime(string $datetime): string
{
    $ts   = strtotime($datetime);
    $now  = time();
    $diff = $now - $ts;

    if ($diff < 60)     return 'Just now';
    if ($diff < 3600)   return floor($diff / 60) . ' minutes ago';
    if ($diff < 86400)  return 'Today, '     . date('h:i A', $ts);
    if ($diff < 172800) return 'Yesterday, ' . date('h:i A', $ts);
    return date('M d, Y', $ts);
}