<?php

/* ================= SESSION ================= */

ini_set('session.cookie_samesite', 'Lax');
ini_set('session.cookie_secure', 0);

session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'domain'   => 'localhost',
    'secure'   => false,
    'httponly' => true,
    'samesite' => 'Lax'
]);

session_start();

/* ================= HEADERS ================= */

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

/* ================= AUTH ================= */

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(["success" => false, "error" => "Unauthorized"]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["success" => false, "error" => "Method not allowed"]);
    exit;
}

require_once "../../config/db.php";
use Config\Database;

try {

    $body = json_decode(file_get_contents("php://input"), true);
    $id   = isset($body['id']) ? (int) $body['id'] : 0;

    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(["success" => false, "error" => "Invalid alert ID"]);
        exit;
    }

    $db     = (new Database())->connect();
    $userId = (int) $_SESSION['user_id'];

    /* Only allow resolving alerts that belong to this user */
    $stmt = $db->prepare("
        UPDATE security_alerts
        SET    status      = 'resolved',
               resolved_at = NOW()
        WHERE  id      = ?
        AND    user_id = ?
        AND    status  = 'open'
    ");

    $stmt->execute([$id, $userId]);

    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(["success" => false, "error" => "Alert not found or already resolved"]);
        exit;
    }

    echo json_encode(["success" => true, "message" => "Alert marked as resolved"]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}