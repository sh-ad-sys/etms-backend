<?php ob_start();

header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, X-Requested-With");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    ob_end_clean();
    http_response_code(200);
    exit();
}

ini_set('session.cookie_samesite', 'Lax');
ini_set('session.cookie_secure', 0);
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => 'localhost',
    'secure' => false,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_end_clean();
    http_response_code(405);
    echo json_encode(["success" => false, "error" => "Method not allowed"]);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    ob_end_clean();
    http_response_code(401);
    echo json_encode(["success" => false, "error" => "Not authenticated"]);
    exit;
}

require_once "../config/db.php";

use Config\Database;

try {
    $payload = json_decode(file_get_contents("php://input"), true) ?? [];
    $id = (int) ($payload['id'] ?? 0);

    if ($id <= 0) {
        ob_end_clean();
        http_response_code(400);
        echo json_encode(["success" => false, "error" => "Invalid notification ID"]);
        exit;
    }

    $db = (new Database())->connect();
    $stmt = $db->prepare("
        UPDATE notifications
        SET is_read = 1
        WHERE id = ?
        AND (user_id = ? OR user_id IS NULL)
    ");
    $stmt->execute([$id, (int) $_SESSION['user_id']]);

    ob_end_clean();
    echo json_encode(["success" => true]);
} catch (Throwable $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}
