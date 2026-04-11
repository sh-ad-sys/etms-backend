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

// Validate JWT token - requires admin or manager role
// Managers can get supervisors for their departments
$token = JWTAuth::requireAuth();

// Initialize database connection
$database = new \Config\Database();
$conn = $database->connect();

error_log("get-staff.php: User authenticated, user_id: " . ($token['user_id'] ?? 'unknown'));

try {
    $query = "
        SELECT 
            u.id,
            u.full_name,
            u.email,
            u.department,
            r.name as role
        FROM users u
        JOIN roles r ON u.role_id = r.id
        WHERE r.name IN ('supervisor', 'manager')
        ORDER BY r.name ASC, u.full_name ASC
    ";
    
    $stmt = $conn->prepare($query);
    $result = $stmt->execute();
    error_log("get-staff.php: Query executed, result: " . ($result ? "true" : "false"));
    
    $staff = $stmt->fetchAll(PDO::FETCH_ASSOC);
    error_log("get-staff.php: Found " . count($staff) . " staff members");
    
    echo json_encode([
        'success' => true,
        'staff' => $staff
    ]);
} catch (Exception $e) {
    error_log("get-staff.php: Exception - " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
