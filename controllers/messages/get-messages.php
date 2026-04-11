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
require_once "../../helpers/communication-policy.php";
use Config\Database;

try {

    $db     = (new Database())->connect();
    $userId = (int) $_SESSION['user_id'];

    /* Fetch both sent and received messages for this user. */
    $stmt = $db->prepare("
        SELECT
            m.id,
            m.thread_id,
            m.sender_id,
            m.receiver_id,
            m.message,
            m.is_read,
            m.created_at,
            sender.full_name AS sender_name,
            sender_roles.name AS sender_role,
            receiver.full_name AS receiver_name,
            receiver_roles.name AS receiver_role
        FROM  messages m
        JOIN  users sender ON sender.id = m.sender_id
        LEFT  JOIN roles sender_roles ON sender_roles.id = sender.role_id
        JOIN  users receiver ON receiver.id = m.receiver_id
        LEFT  JOIN roles receiver_roles ON receiver_roles.id = receiver.role_id
        WHERE m.receiver_id = :user_id OR m.sender_id = :user_id
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
            'receiverId' => (string) $row['receiver_id'],
            'sender'     => $row['sender_name'],
            'senderRole' => ucfirst(normalizeCommunicationRole($row['sender_role'] ?? '')),
            'receiver'   => $row['receiver_name'],
            'receiverRole' => ucfirst(normalizeCommunicationRole($row['receiver_role'] ?? '')),
            'direction'  => ((int) $row['sender_id'] === $userId) ? 'sent' : 'received',
            'counterparty' => ((int) $row['sender_id'] === $userId) ? $row['receiver_name'] : $row['sender_name'],
            'counterpartyRole' => ((int) $row['sender_id'] === $userId)
                ? ucfirst(normalizeCommunicationRole($row['receiver_role'] ?? ''))
                : ucfirst(normalizeCommunicationRole($row['sender_role'] ?? '')),
            'message'    => $row['message'],
            'isRead'     => ((int) $row['receiver_id'] === $userId) ? (bool) $row['is_read'] : true,
            'time'       => formatTime($row['created_at']),
            'rawTime'    => $row['created_at'],
        ];
    }

    $unreadCount = count(array_filter(
        $messages,
        fn($m) => $m['direction'] === 'received' && !$m['isRead']
    ));

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
