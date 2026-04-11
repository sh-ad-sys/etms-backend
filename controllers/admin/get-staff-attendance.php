<?php
/* ================= CORS ================= */
$allowedOrigin = getenv('CORS_ORIGIN') ?: 'http://localhost:3000';
header("Access-Control-Allow-Origin: " . $allowedOrigin);
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header('Content-Type: application/json');

/* ================= PRE-FLIGHT ================= */
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../middleware/JWTAuth.php';

use Middleware\JWTAuth;

// Validate JWT token and ensure user is supervisor
$token = JWTAuth::requireAuth();
$userRole = ucfirst(strtolower($token['role'] ?? ''));
$userId = $token['user_id'];

// Only supervisors can view staff attendance
if ($userRole !== 'Supervisor') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Only supervisors can view staff attendance']);
    exit;
}

try {
    $database = new \Config\Database();
    $conn = $database->connect();
    
    // Get all staff in the supervisor's department
    $query = "
        SELECT 
            u.id,
            u.full_name,
            u.email,
            u.department,
            a.check_in_time,
            a.check_out_time,
            CASE 
                WHEN a.check_in_time IS NULL THEN 'absent'
                WHEN a.check_out_time IS NOT NULL THEN 'checked_out'
                ELSE 'present'
            END as attendance_status
        FROM users u
        LEFT JOIN attendance a ON u.id = a.user_id AND DATE(a.check_in_time) = CURDATE()
        WHERE u.role = 'Staff' 
        AND u.department = (SELECT department FROM users WHERE id = ?)
        ORDER BY 
            CASE 
                WHEN a.check_in_time IS NULL THEN 1
                WHEN a.check_out_time IS NOT NULL THEN 2
                ELSE 0
            END ASC,
            u.full_name ASC
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->execute([$userId]);
    $staff = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'staff' => $staff,
        'count' => count($staff)
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
