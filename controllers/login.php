<?php
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

$log_file = dirname(__DIR__) . '/login_debug.log';
function login_log($message) {
    global $log_file;
    file_put_contents($log_file, '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n", FILE_APPEND);
}

login_log('Request started');

$allowedOrigin = getenv('CORS_ORIGIN') ?: 'http://localhost:3000';
<<<<<<< HEAD
header('Access-Control-Allow-Origin: ' . $allowedOrigin);
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Content-Type: application/json');
login_log('Headers sent');
=======
header("Access-Control-Allow-Origin: " . $allowedOrigin);
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Content-Type: application/json");

/* ================= SESSION ================= */

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

/* ================= PRE-FLIGHT ================= */
>>>>>>> 812dd5c7482ff03be52102d43bb633ef7a2305c8

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    echo json_encode(['success' => true]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Method not allowed. Use POST.'
    ]);
    exit;
}

<<<<<<< HEAD
require_once '../config/db.php';
require_once '../config/jwt.php';
require_once '../models/User.php';
require_once '../helpers/device-detect.php';
login_log('Dependencies loaded');
=======
require_once "../config/db.php";
require_once "../config/jwt.php";
require_once "../models/User.php";
require_once "../helpers/device-detect.php";
require_once "../helpers/user-password-policy.php";
>>>>>>> 812dd5c7482ff03be52102d43bb633ef7a2305c8

use Config\Database;
use Config\JWT;
use Models\User;

$data = json_decode(file_get_contents('php://input'), true);
login_log('Input decoded');

if (!is_array($data) || !isset($data['email'], $data['password'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Email and password required'
    ]);
    exit;
}

<<<<<<< HEAD
login_log('Connecting to database');
$db = (new Database())->connect();
login_log('Database connected');

=======
$db        = (new Database())->connect();
ensureMustChangePasswordColumn($db);
>>>>>>> 812dd5c7482ff03be52102d43bb633ef7a2305c8
$userModel = new User($db);
login_log('Looking up user');
$user = $userModel->findByEmail($data['email']);
login_log($user ? 'User found' : 'User not found');

if (!$user) {
    try {
<<<<<<< HEAD
        login_log('Writing failed-login audit for unknown email');
=======
>>>>>>> 812dd5c7482ff03be52102d43bb633ef7a2305c8
        $audit = $db->prepare("
            INSERT INTO audit_logs (user_id, action, entity, entity_id, details)
            VALUES (NULL, 'login_failed', 'auth', NULL, ?)
        ");
        $audit->execute(["Failed login attempt for unknown email {$data['email']}"]);
<<<<<<< HEAD
        login_log('Unknown-email audit written');
    } catch (Throwable $e) {
        error_log('Audit log error: ' . $e->getMessage());
        login_log('Unknown-email audit failed: ' . $e->getMessage());
    }

=======
    } catch (Throwable $e) {
        error_log("Audit log error: " . $e->getMessage());
    }
>>>>>>> 812dd5c7482ff03be52102d43bb633ef7a2305c8
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Invalid credentials'
    ]);
    exit;
}

login_log('Verifying password');
if (!password_verify($data['password'], $user['password'])) {
    try {
<<<<<<< HEAD
        login_log('Writing failed-login audit for known user');
=======
>>>>>>> 812dd5c7482ff03be52102d43bb633ef7a2305c8
        $audit = $db->prepare("
            INSERT INTO audit_logs (user_id, action, entity, entity_id, details)
            VALUES (?, 'login_failed', 'auth', ?, ?)
        ");
        $audit->execute([
            (int) $user['id'],
            (int) $user['id'],
            "Failed login attempt for {$user['email']}"
        ]);
<<<<<<< HEAD
        login_log('Known-user failed-login audit written');
    } catch (Throwable $e) {
        error_log('Audit log error: ' . $e->getMessage());
        login_log('Known-user failed-login audit failed: ' . $e->getMessage());
    }

=======
    } catch (Throwable $e) {
        error_log("Audit log error: " . $e->getMessage());
    }
>>>>>>> 812dd5c7482ff03be52102d43bb633ef7a2305c8
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Invalid credentials'
    ]);
    exit;
}

<<<<<<< HEAD
=======
try {
    $audit = $db->prepare("
        INSERT INTO audit_logs (user_id, action, entity, entity_id, details)
        VALUES (?, 'login_success', 'auth', ?, ?)
    ");
    $audit->execute([
        (int) $user['id'],
        (int) $user['id'],
        "Successful login for {$user['email']}"
    ]);
} catch (Throwable $e) {
    error_log("Audit log error: " . $e->getMessage());
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

>>>>>>> 812dd5c7482ff03be52102d43bb633ef7a2305c8
try {
    login_log('Writing success audit');
    $audit = $db->prepare("
        INSERT INTO audit_logs (user_id, action, entity, entity_id, details)
        VALUES (?, 'login_success', 'auth', ?, ?)
    ");
    $audit->execute([
        (int) $user['id'],
        (int) $user['id'],
        "Successful login for {$user['email']}"
    ]);
    login_log('Success audit written');
} catch (Throwable $e) {
    error_log('Audit log error: ' . $e->getMessage());
    login_log('Success audit failed: ' . $e->getMessage());
}

if ($user['status'] !== 'ACTIVE') {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error' => 'Account not active'
    ]);
    exit;
}

try {
    login_log('Recording device');
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
    $deviceName = detectDeviceName($ua);

    $stmt = $db->prepare("
        INSERT INTO devices (user_id, device_name, ip_address, user_agent, last_login)
        VALUES (:user_id, :device_name, :ip, :ua, NOW())
        ON DUPLICATE KEY UPDATE
            device_name = VALUES(device_name),
            last_login = NOW()
    ");

    $stmt->execute([
        ':user_id' => $user['id'],
        ':device_name' => $deviceName,
        ':ip' => $ip,
        ':ua' => $ua,
    ]);
    login_log('Device recorded');
} catch (Throwable $e) {
    error_log('Device record error: ' . $e->getMessage());
    login_log('Device record failed: ' . $e->getMessage());
}

$payload = [
    'user_id' => $user['id'],
    'email' => $user['email'],
    'name' => $user['full_name'],
    'role' => $user['role_name'],
];
$token = JWT::encode($payload);
login_log('JWT generated');

<<<<<<< HEAD
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
login_log('Session started');
=======
>>>>>>> 812dd5c7482ff03be52102d43bb633ef7a2305c8
session_regenerate_id(true);
$_SESSION['user_id'] = (int) $user['id'];
$_SESSION['user'] = [
    'id' => (int) $user['id'],
    'email' => $user['email'],
    'full_name' => $user['full_name'],
    'role' => $user['role_name'],
    'must_change_password' => (int) ($user['must_change_password'] ?? 0),
];
session_write_close();
<<<<<<< HEAD
login_log('Session written and closed');

echo json_encode([
    'success' => true,
    'message' => 'Login successful',
    'token' => $token,
    'user' => [
        'id' => $user['id'],
        'email' => $user['email'],
        'fullName' => $user['full_name'],
        'role' => $user['role_name'],
    ],
    'mustChangePassword' => (bool) ($user['must_change_password'] ?? 0),
=======

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
    ],
    "mustChangePassword" => (bool) ($user['must_change_password'] ?? 0),
>>>>>>> 812dd5c7482ff03be52102d43bb633ef7a2305c8
]);
login_log('Response sent');
?>
