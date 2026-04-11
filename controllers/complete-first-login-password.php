<?php

header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
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

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(["success" => false, "error" => "Unauthorized"]);
    exit;
}

require_once "../config/db.php";
require_once "../helpers/user-password-policy.php";

use Config\Database;

try {
    $body = json_decode(file_get_contents("php://input"), true) ?? [];
    $password = (string) ($body['password'] ?? '');

    if (!isStrongPassword($password)) {
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "error" => "Password must be at least 8 characters and include uppercase, lowercase, number, and symbol."
        ]);
        exit;
    }

    $db = (new Database())->connect();
    ensureMustChangePasswordColumn($db);

    $userId = (int) $_SESSION['user_id'];
    $check = $db->prepare("SELECT must_change_password FROM users WHERE id = ? LIMIT 1");
    $check->execute([$userId]);

    if ((int) $check->fetchColumn() !== 1) {
        echo json_encode(["success" => true, "message" => "Password already updated."]);
        exit;
    }

    $stmt = $db->prepare("
        UPDATE users
        SET password = ?, must_change_password = 0, updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([password_hash($password, PASSWORD_DEFAULT), $userId]);

    $_SESSION['user']['must_change_password'] = 0;
    session_write_close();

    echo json_encode([
        "success" => true,
        "message" => "Password updated successfully."
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "error" => $e->getMessage()
    ]);
}
