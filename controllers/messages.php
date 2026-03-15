<?php ob_start();

/* ================= CORS ================= */

// Get allowed origin from environment or use default
$allowedOrigin = getenv('CORS_ORIGIN') ?: 'http://localhost:3000';
header("Access-Control-Allow-Origin: " . $allowedOrigin);
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, X-Requested-With, Authorization");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { ob_end_clean(); http_response_code(200); exit(); }

ini_set('session.cookie_samesite', 'Lax');
ini_set('session.cookie_secure', 0);
session_set_cookie_params(['lifetime'=>0,'path'=>'/','domain'=>'localhost','secure'=>false,'httponly'=>true,'samesite'=>'Lax']);
session_start();

if (!isset($_SESSION['user_id'])) {
    ob_end_clean(); http_response_code(401);
    echo json_encode(["success" => false, "error" => "Not authenticated"]); exit;
}

require_once "../config/db.php";
use Config\Database;

try {
    $db     = (new Database())->connect();
    $userId = (int) $_SESSION['user_id'];

    /* Fetch messages received by this user, join sender's full_name */
    $stmt = $db->prepare("
        SELECT
            m.id,
            COALESCE(u.full_name, 'Unknown') AS sender,
            m.message,
            m.is_read,
            m.created_at
        FROM  messages m
        LEFT  JOIN users u ON u.id = m.sender_id
        WHERE m.receiver_id = ?
        ORDER BY m.created_at DESC
        LIMIT 60
    ");
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $messages = array_map(fn($r) => [
        'id'         => (int) $r['id'],
        'sender'     => $r['sender'],
        'message'    => $r['message'],
        'is_read'    => (int) ($r['is_read'] ?? 0),
        'created_at' => $r['created_at'],
    ], $rows);

    ob_end_clean();
    echo json_encode(["success" => true, "messages" => $messages]);

} catch (Throwable $e) {
    ob_end_clean(); http_response_code(500);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}