<?php ob_start();

header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, X-Requested-With");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { ob_end_clean(); http_response_code(200); exit(); }

ini_set('session.cookie_samesite', 'Lax');
ini_set('session.cookie_secure', 0);
session_set_cookie_params(['lifetime'=>0,'path'=>'/','domain'=>'localhost','secure'=>false,'httponly'=>true,'samesite'=>'Lax']);
session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_end_clean(); http_response_code(405);
    echo json_encode(["success" => false, "error" => "Method not allowed"]); exit;
}

if (!isset($_SESSION['user_id'], $_SESSION['user']['role'])) {
    ob_end_clean(); http_response_code(401);
    echo json_encode(["success" => false, "error" => "Not authenticated"]); exit;
}

require_once "../../config/db.php";
require_once "../../helpers/communication-policy.php";

use Config\Database;

try {
    $payload = json_decode(file_get_contents("php://input"), true) ?? [];

    $senderId = (int) $_SESSION['user_id'];
    $senderRole = normalizeCommunicationRole($_SESSION['user']['role'] ?? '');
    $receiverId = (int) ($payload['receiverId'] ?? 0);
    $message = trim((string) ($payload['message'] ?? ''));

    if ($receiverId <= 0) {
        ob_end_clean(); http_response_code(400);
        echo json_encode(["success" => false, "error" => "A recipient is required"]); exit;
    }

    if ($message === '') {
        ob_end_clean(); http_response_code(400);
        echo json_encode(["success" => false, "error" => "Message cannot be empty"]); exit;
    }

    if (mb_strlen($message) > 1200) {
        ob_end_clean(); http_response_code(400);
        echo json_encode(["success" => false, "error" => "Message is too long"]); exit;
    }

    $db = (new Database())->connect();
    ensureMessageThreadsTable($db);

    $receiverStmt = $db->prepare("
        SELECT u.id, u.full_name, r.name AS role_name
        FROM users u
        LEFT JOIN roles r ON r.id = u.role_id
        WHERE u.id = ?
        LIMIT 1
    ");
    $receiverStmt->execute([$receiverId]);
    $receiver = $receiverStmt->fetch(PDO::FETCH_ASSOC);

    if (!$receiver) {
        ob_end_clean(); http_response_code(404);
        echo json_encode(["success" => false, "error" => "Recipient not found"]); exit;
    }

    $receiverRole = normalizeCommunicationRole($receiver['role_name'] ?? '');

    if (!canDirectlyMessage($senderRole, $receiverRole)) {
        ob_end_clean(); http_response_code(403);
        echo json_encode([
            "success" => false,
            "error" => "This communication route is not allowed. Use the approved role chain instead.",
        ]);
        exit;
    }

    $threadId = ensureMessageThreadRecord($db);

    $insert = $db->prepare("
        INSERT INTO messages (thread_id, sender_id, receiver_id, message, is_read, created_at)
        VALUES (?, ?, ?, ?, 0, NOW())
    ");
    $insert->execute([$threadId, $senderId, $receiverId, $message]);

    ob_end_clean();
    echo json_encode([
        "success" => true,
        "message" => "Message sent successfully",
        "recipient" => $receiver['full_name'] ?? 'Recipient',
    ]);
} catch (Throwable $e) {
    ob_end_clean(); http_response_code(500);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}

function ensureMessageThreadsTable(PDO $db): void
{
    $db->exec("
        CREATE TABLE IF NOT EXISTS message_threads (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function ensureMessageThreadRecord(PDO $db): int
{
    $columns = getTableColumns($db, 'message_threads');

    if (!in_array('id', $columns, true)) {
        throw new RuntimeException("message_threads table is missing the id column");
    }

    if (count($columns) === 1 && $columns[0] === 'id') {
        $db->exec("INSERT INTO message_threads () VALUES ()");
        return (int) $db->lastInsertId();
    }

    if (count($columns) === 2 && in_array('created_at', $columns, true)) {
        $db->exec("INSERT INTO message_threads (created_at) VALUES (NOW())");
        return (int) $db->lastInsertId();
    }

    $fieldNames = [];
    $placeholders = [];
    $values = [];

    if (in_array('created_at', $columns, true)) {
        $fieldNames[] = 'created_at';
        $placeholders[] = 'NOW()';
    }

    if (in_array('updated_at', $columns, true)) {
        $fieldNames[] = 'updated_at';
        $placeholders[] = 'NOW()';
    }

    if ($fieldNames === []) {
        $db->exec("INSERT INTO message_threads () VALUES ()");
    } else {
        $sql = sprintf(
            'INSERT INTO message_threads (%s) VALUES (%s)',
            implode(', ', $fieldNames),
            implode(', ', $placeholders)
        );
        $stmt = $db->prepare($sql);
        $stmt->execute($values);
    }

    return (int) $db->lastInsertId();
}

function getTableColumns(PDO $db, string $table): array
{
    $stmt = $db->query("SHOW COLUMNS FROM {$table}");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return array_map(
        static fn(array $row): string => (string) ($row['Field'] ?? ''),
        $rows
    );
}
