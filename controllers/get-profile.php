<?php 
ob_start();

/* ================= CORS ================= */

// Get allowed origin from environment or use default
$allowedOrigin = getenv('CORS_ORIGIN') ?: 'http://localhost:3000';
header("Access-Control-Allow-Origin: " . $allowedOrigin);
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

/* ================= PRE-FLIGHT ================= */

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { 
    ob_end_clean(); 
    http_response_code(200); 
    exit(); 
}

/* ================= JWT VALIDATION ================= */

require_once "../config/jwt.php";

use Config\JWT;

// Validate JWT token (this will exit if invalid)
$payload = JWT::validateRequest();

/* ================= GET PROFILE ================= */

require_once "../config/db.php";
use Config\Database;

try {

    $db     = (new Database())->connect();
    $userId = (int) $payload['user_id'];

    $stmt = $db->prepare("
        SELECT
            id,
            employee_code,
            full_name,
            email,
            phone,
            department,
            avatar
        FROM  users
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        ob_end_clean(); 
        http_response_code(404);
        echo json_encode(["success" => false, "error" => "User not found"]); 
        exit;
    }

    ob_end_clean();
    echo json_encode([
        "success" => true,
        "user"    => [
            "id"           => (string) $user['id'],
            "employeeCode" => $user['employee_code'] ?? '',
            "full_name"    => $user['full_name'],
            "email"        => $user['email'],
            "phone"        => $user['phone']      ?? '',
            "department"   => $user['department']  ?? '',
            "avatar"       => $user['avatar']      ?? '',
            "role"         => $payload['role'] ?? 'Staff',
        ],
    ]);

} catch (Exception $e) {
    ob_end_clean(); 
    http_response_code(500);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}
