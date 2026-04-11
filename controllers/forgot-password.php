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
require_once "../helpers/smtp-mail.php";

use Config\Database;

try {
    $body = json_decode(file_get_contents("php://input"), true) ?? [];
    $email = trim($body['email'] ?? '');

    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(["success" => false, "error" => "Enter a valid email address."]);
        exit;
    }

    $db = (new Database())->connect();

    $db->exec("
        CREATE TABLE IF NOT EXISTS password_reset_tokens (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            token_hash CHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_password_reset_user (user_id),
            INDEX idx_password_reset_hash (token_hash)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $stmt = $db->prepare("SELECT id, full_name, email, status FROM users WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    $genericResponse = [
        "success" => true,
        "message" => "If that email exists in the system, a reset link has been sent."
    ];

    if (!$user || ($user['status'] ?? '') !== 'ACTIVE') {
        echo json_encode($genericResponse);
        exit;
    }

    $db->prepare("UPDATE password_reset_tokens SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL")
        ->execute([(int) $user['id']]);

    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));

    $insert = $db->prepare("
        INSERT INTO password_reset_tokens (user_id, token_hash, expires_at)
        VALUES (?, ?, ?)
    ");
    $insert->execute([(int) $user['id'], $tokenHash, $expiresAt]);

    $frontendUrl = rtrim(getenv('FRONTEND_URL') ?: 'http://localhost:3000', '/');
    $resetUrl = $frontendUrl . '/reset-password?token=' . urlencode($token);
    $name = $user['full_name'] ?: 'User';

    $subject = 'Reset your ETMS password';
    $textBody = "Hello {$name},\n\n"
        . "We received a request to reset your ETMS password.\n"
        . "Use the link below to set a new password:\n{$resetUrl}\n\n"
        . "This link expires in 1 hour. If you did not request this, you can ignore this message.\n\n"
        . "Royal Mabati Factory";

    $htmlBody = '<p>Hello ' . htmlspecialchars($name) . ',</p>'
        . '<p>We received a request to reset your ETMS password.</p>'
        . '<p><a href="' . htmlspecialchars($resetUrl) . '">Click here to reset your password</a></p>'
        . '<p>This link expires in <strong>1 hour</strong>. If you did not request this, you can ignore this message.</p>'
        . '<p>Royal Mabati Factory</p>';

    smtpSendMail($user['email'], $name, $subject, $htmlBody, $textBody);

    echo json_encode($genericResponse);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "error" => $e->getMessage()
    ]);
}
