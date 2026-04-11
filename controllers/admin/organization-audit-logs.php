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

// Validate JWT token - requires admin or hr role
$token = JWTAuth::requireAuth();

// Initialize database connection
$database = new \Config\Database();
$conn = $database->connect();

$userRole = ucfirst(strtolower($token['role'] ?? ''));

// Only Admin and HR can view audit logs
if (!in_array($userRole, ['Admin', 'Hr'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Only Admin and HR can view audit logs']);
    exit;
}

try {
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
    $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
    $filterType = isset($_GET['action_type']) ? $_GET['action_type'] : '';
    
    $query = "
        SELECT 
            id,
            action_type,
            altered_by,
            altered_by_role,
            COALESCE(
                (SELECT full_name FROM users WHERE id = organization_audit_logs.altered_by),
                'System'
            ) as altered_by_name,
            target_user_id,
            target_user_name,
            target_department_id,
            target_department_name,
            old_value,
            new_value,
            action_date,
            notes
        FROM organization_audit_logs
    ";
    
    $params = [];
    if ($filterType) {
        $query .= " WHERE action_type = ?";
        $params[] = $filterType;
    }
    
    $query .= " ORDER BY action_date DESC LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;
    
    $stmt = $conn->prepare($query);
    $stmt->execute($params);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get total count
    $countQuery = "SELECT COUNT(*) as total FROM organization_audit_logs";
    if ($filterType) {
        $countQuery .= " WHERE action_type = ?";
    }
    
    $countStmt = $conn->prepare($countQuery);
    if ($filterType) {
        $countStmt->execute([$filterType]);
    } else {
        $countStmt->execute();
    }
    
    $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    echo json_encode([
        'success' => true,
        'logs' => $logs,
        'total' => $total,
        'limit' => $limit,
        'offset' => $offset
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
