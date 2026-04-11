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

require_once "../config/db.php";

use Config\Database;

try {
    $body = json_decode(file_get_contents("php://input"), true) ?? [];
    $token = trim($body['token'] ?? '');
    $password = (string) ($body['password'] ?? '');

    if (!$token || strlen($token) < 20) {
        http_response_code(400);
        echo json_encode(["success" => false, "error" => "Invalid or missing reset token."]);
        exit;
    }

    if (strlen($password) < 8) {
        http_response_code(400);
        echo json_encode(["success" => false, "error" => "Password must be at least 8 characters."]);
        exit;
    }

    $db = (new Database())->connect();
    $tokenHash = hash('sha256', $token);

    $stmt = $db->prepare("
        SELECT prt.id, prt.user_id
        FROM password_reset_tokens prt
        JOIN users u ON u.id = prt.user_id
        WHERE prt.token_hash = ?
        AND prt.used_at IS NULL
        AND prt.expires_at > NOW()
        AND u.status = 'ACTIVE'
        LIMIT 1
    ");
    $stmt->execute([$tokenHash]);
    $resetRow = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$resetRow) {
        http_response_code(400);
        echo json_encode(["success" => false, "error" => "This reset link is invalid or has expired."]);
        exit;
    }

    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

    $db->beginTransaction();

    $updateUser = $db->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?");
    $updateUser->execute([$hashedPassword, (int) $resetRow['user_id']]);

    $consume = $db->prepare("UPDATE password_reset_tokens SET used_at = NOW() WHERE id = ?");
    $consume->execute([(int) $resetRow['id']]);

    $db->commit();

    echo json_encode([
        "success" => true,
        "message" => "Your password has been reset successfully."
    ]);
} catch (Throwable $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "error" => $e->getMessage()
    ]);
}
