<?php

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

/* ================= CORS ================= */

header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Content-Type: application/json");

/* ================= PRE-FLIGHT ================= */

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

/* ================= SESSION CONFIG ================= */

session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'domain'   => 'localhost',
    'secure'   => false,
    'httponly' => true,
    'samesite' => 'Lax'
]);

session_start();

/* ================= DATABASE ================= */

require_once "../config/db.php";
require_once "../models/User.php";

use Config\Database;
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

/* ================= SET SESSION ================= */

$_SESSION['user_id']    = $user['id'];
$_SESSION['user_name']  = $user['full_name'];
$_SESSION['user_email'] = $user['email'];
$_SESSION['user_role']  = $user['role_name'];

session_regenerate_id(true);

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
require_once "../helpers/create-alert.php";

// New device login (check if device was just inserted vs updated)
if ($stmt->rowCount() === 1) {   // rowCount=1 means fresh INSERT
    createAlert($db, $user['id'],
        'New Device Login',
        "A new device ({$deviceName}) logged in from IP {$ip}.",
        'medium'
    );
}

/* ================= SUCCESS RESPONSE ================= */

echo json_encode([
    "success" => true,
    "message" => "Login successful",
    "user"    => [
        "id"    => $user['id'],
        "name"  => $user['full_name'],
        "email" => $user['email'],
        "role"  => $user['role_name']
    ]
]);

/* ================= HELPERS ================= */

function detectDeviceName(string $ua): string
{
    $ua = strtolower($ua);

    if (strpos($ua, 'iphone')    !== false) return 'iPhone';
    if (strpos($ua, 'ipad')      !== false) return 'iPad';
    if (strpos($ua, 'android')   !== false) {
        return strpos($ua, 'mobile') !== false ? 'Android Phone' : 'Android Tablet';
    }
    if (strpos($ua, 'windows')   !== false) return 'Windows PC';
    if (strpos($ua, 'macintosh') !== false) return 'Mac';
    if (strpos($ua, 'linux')     !== false) return 'Linux PC';

    return 'Unknown Device';
}