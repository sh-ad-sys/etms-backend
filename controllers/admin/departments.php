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
                d.id,
                d.name,
                d.description,
                d.supervisor_id,
                s.full_name as supervisor_name,
                d.manager_id,
                m.full_name as manager_name,
                (SELECT COUNT(*) FROM users WHERE department = d.name) as staff_count,
                d.created_at,
                d.updated_at
            FROM departments d
            LEFT JOIN users s ON d.supervisor_id = s.id
            LEFT JOIN users m ON d.manager_id = m.id
            ORDER BY d.name ASC
        ";

        $stmt = $conn->prepare($query);
        $stmt->execute();
        $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'departments' => $departments
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
} elseif ($method === 'POST') {
    try {
        $data = json_decode(file_get_contents('php://input'), true);

        if (empty($data['name'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Department name is required']);
            exit;
        }

        if (isset($data['id']) && !empty($data['id'])) {
            $query = "
                UPDATE departments 
                SET name = ?, description = ?, supervisor_id = ?, manager_id = ?
                WHERE id = ?
            ";

            $stmt = $conn->prepare($query);
            $stmt->execute([
                $data['name'],
                $data['description'] ?? null,
                $data['supervisor_id'] ?? null,
                $data['manager_id'] ?? null,
                $data['id']
            ]);

            echo json_encode([
                'success' => true,
                'message' => 'Department updated successfully',
                'id' => $data['id']
            ]);
        } else {
            $query = "
                INSERT INTO departments (name, description, supervisor_id, manager_id) 
                VALUES (?, ?, ?, ?)
            ";

            $stmt = $conn->prepare($query);
            $stmt->execute([
                $data['name'],
                $data['description'] ?? null,
                $data['supervisor_id'] ?? null,
                $data['manager_id'] ?? null
            ]);

            echo json_encode([
                'success' => true,
                'message' => 'Department created successfully',
                'id' => $conn->lastInsertId()
            ]);
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
} elseif ($method === 'DELETE') {
    try {
        $data = json_decode(file_get_contents('php://input'), true);

        if (empty($data['id'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Department ID is required']);
            exit;
        }

        $query = "DELETE FROM departments WHERE id = ?";
        $stmt = $conn->prepare($query);
        $stmt->execute([$data['id']]);

        echo json_encode([
            'success' => true,
            'message' => 'Department deleted successfully'
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
