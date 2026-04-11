<?php
/* ================= CORS ================= */
$allowedOrigin = getenv('CORS_ORIGIN') ?: 'http://localhost:3000';
header("Access-Control-Allow-Origin: " . $allowedOrigin);
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS");
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../middleware/JWTAuth.php';

use Middleware\JWTAuth;

function getAdminAuth(): void {
    $jwtToken = JWTAuth::validateToken();
    if ($jwtToken) {
        $jwtRole = strtolower($jwtToken['role'] ?? '');
        if ($jwtRole !== 'admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Unauthorized access']);
            exit;
        }
        return;
    }

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => 'localhost',
        'secure' => false,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $sessionRole = strtolower($_SESSION['user']['role'] ?? '');
    if (!isset($_SESSION['user_id']) || $sessionRole !== 'admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Unauthorized access']);
        exit;
    }
}

$database = new \Config\Database();
$conn = $database->connect();
getAdminAuth();

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    try {
        $query = "
            SELECT 
                u.id,
                u.full_name,
                u.email,
                u.department,
                mr.manager_type,
                CASE 
                    WHEN mr.manager_type = 'operations' THEN 'Manager A (Operations Manager - Technical)'
                    WHEN mr.manager_type = 'commercial' THEN 'Manager B (Commercial/General Manager - Administrative)'
                    ELSE 'Not assigned'
                END as manager_title,
                COUNT(DISTINCT d.id) as dept_count
            FROM users u
            LEFT JOIN manager_roles mr ON u.id = mr.user_id
            LEFT JOIN departments d ON d.manager_id = u.id
            WHERE u.role_id = (SELECT id FROM roles WHERE role_name = 'Manager')
            GROUP BY u.id
            ORDER BY u.full_name ASC
        ";

        $stmt = $conn->prepare($query);
        $stmt->execute();
        $managers = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'managers' => $managers
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
} elseif ($method === 'POST') {
    try {
        $data = json_decode(file_get_contents('php://input'), true);

        if (empty($data['user_id']) || empty($data['manager_type'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'User ID and manager type are required']);
            exit;
        }

        if (!in_array($data['manager_type'], ['operations', 'commercial'], true)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid manager type']);
            exit;
        }

        $deleteQuery = "DELETE FROM manager_roles WHERE manager_type = ? OR user_id = ?";
        $deleteStmt = $conn->prepare($deleteQuery);
        $deleteStmt->execute([$data['manager_type'], $data['user_id']]);

        $query = "INSERT INTO manager_roles (user_id, manager_type) VALUES (?, ?)";
        $stmt = $conn->prepare($query);
        $stmt->execute([$data['user_id'], $data['manager_type']]);

        $managerTitle = $data['manager_type'] === 'operations'
            ? 'Manager A (Operations Manager - Technical)'
            : 'Manager B (Commercial/General Manager - Administrative)';

        echo json_encode([
            'success' => true,
            'message' => 'Manager role assigned successfully',
            'manager_type' => $data['manager_type'],
            'manager_title' => $managerTitle
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
} elseif ($method === 'DELETE') {
    try {
        $data = json_decode(file_get_contents('php://input'), true);

        if (empty($data['user_id'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'User ID is required']);
            exit;
        }

        $query = "DELETE FROM manager_roles WHERE user_id = ?";
        $stmt = $conn->prepare($query);
        $stmt->execute([$data['user_id']]);

        echo json_encode([
            'success' => true,
            'message' => 'Manager role removed successfully'
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
}
?>
