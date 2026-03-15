<?php

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

/* ================= CORS ================= */

header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Content-Type: application/json");

/* ================= PRE-FLIGHT ================= */

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

/* ================= DATABASE ================= */

require_once "../config/db.php";
require_once "../config/jwt.php";
require_once "../models/User.php";
require_once "../helpers/device-detect.php";

use Config\Database;
use Config\JWT;
use Models\User;

/* ================= GET INPUT ================= */

$data = json_decode(file_get_contents("php://input"), true);

if (!isset($data['email'], $data['password'])) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "error"   => "Email and password required"
    ]);
    exit;
}

$db        = (new Database())->connect();
$userModel = new User($db);

/* ================= FIND USER ================= */

$user = $userModel->findByEmail($data['email']);

if (!$user) {
    http_response_code(401);
    echo json_encode([
        "success" => false,
        "error"   => "Invalid credentials"
    ]);
    exit;
}

/* ================= VERIFY PASSWORD ================= */

if (!password_verify($data['password'], $user['password'])) {
    http_response_code(401);
    echo json_encode([
        "success" => false,
        "error"   => "Invalid credentials"
    ]);
    exit;
}

/* ================= CHECK STATUS ================= */

if ($user['status'] !== "ACTIVE") {
    http_response_code(403);
    echo json_encode([
        "success" => false,
        "error"   => "Account not active"
    ]);
    exit;
}

/* ================= RECORD DEVICE ================= */

try {

    $ua         = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    $ip         = $_SERVER['REMOTE_ADDR']     ?? 'Unknown';
    $deviceName = detectDeviceName($ua);

    $stmt = $db->prepare("
        INSERT INTO devices (user_id, device_name, ip_address, user_agent, last_login)
        VALUES (:user_id, :device_name, :ip, :ua, NOW())
        ON DUPLICATE KEY UPDATE
            device_name = VALUES(device_name),
            last_login  = NOW()
    ");

    $stmt->execute([
        ':user_id'     => $user['id'],
        ':device_name' => $deviceName,
        ':ip'          => $ip,
        ':ua'          => $ua,
    ]);

} catch (Exception $e) {
    // Device recording failure must never block login
    error_log("Device record error: " . $e->getMessage());
}

/* ================= GENERATE JWT ================= */

// Create JWT payload with user data
$payload = [
    'user_id'   => $user['id'],
    'email'     => $user['email'],
    'name'      => $user['full_name'],
    'role'      => $user['role_name'],
];

// Generate JWT token
$token = JWT::encode($payload);

/* ================= SUCCESS RESPONSE ================= */

echo json_encode([
    "success" => true,
    "message" => "Login successful",
    "token"   => $token,
    "user"    => [
        "id"       => $user['id'],
        "email"    => $user['email'],
        "fullName" => $user['full_name'],
        "role"     => $user['role_name'],
    ]
]);
