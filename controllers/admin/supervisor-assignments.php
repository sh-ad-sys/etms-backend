<?php
/* ================= CORS ================= */
$allowedOrigin = getenv('CORS_ORIGIN') ?: 'http://localhost:3000';
header("Access-Control-Allow-Origin: " . $allowedOrigin);
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS");
header('Content-Type: application/json');

/* ================= PRE-FLIGHT ================= */
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../middleware/JWTAuth.php';

use Middleware\JWTAuth;

// Validate JWT token
$token = JWTAuth::requireAuth();

// Initialize database connection
$database = new \Config\Database();
$conn = $database->connect();

$method = $_SERVER['REQUEST_METHOD'];
$userRole = ucfirst(strtolower($token['role'] ?? ''));
$userId = $token['user_id'];

// Helper function to log audit events
function logAuditEvent($conn, $actionType, $alteredById, $alteredByRole, $targetUserId, $targetUserName, $targetDeptId, $targetDeptName, $oldValue, $newValue, $notes = '') {
    try {
        $query = "
            INSERT INTO organization_audit_logs 
            (action_type, altered_by, altered_by_role, target_user_id, target_user_name, target_department_id, target_department_name, old_value, new_value, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ";
        
        $stmt = $conn->prepare($query);
        $stmt->execute([
            $actionType,
            $alteredById,
            $alteredByRole,
            $targetUserId,
            $targetUserName,
            $targetDeptId,
            $targetDeptName,
            $oldValue,
            $newValue,
            $notes
        ]);
    } catch (Exception $e) {
        error_log("Audit log error: " . $e->getMessage());
    }
}

// GET: Fetch departments for a manager
if ($method === 'GET') {
    try {
        if ($userRole === 'Admin') {
            // Admins see all departments
            $query = "
                SELECT 
                    d.id,
                    d.name,
                    d.description,
                    d.supervisor_id,
                    s.full_name as supervisor_name,
                    d.manager_id,
                    m.full_name as manager_name,
                    d.manager_type,
                    (SELECT COUNT(*) FROM users WHERE department = d.name) as staff_count
                FROM departments d
                LEFT JOIN users s ON d.supervisor_id = s.id
                LEFT JOIN users m ON d.manager_id = m.id
                ORDER BY d.name ASC
            ";
        } elseif ($userRole === 'Manager') {
            // Managers see only their assigned departments
            $query = "
                SELECT 
                    d.id,
                    d.name,
                    d.description,
                    d.supervisor_id,
                    s.full_name as supervisor_name,
                    d.manager_id,
                    m.full_name as manager_name,
                    d.manager_type,
                    (SELECT COUNT(*) FROM users WHERE department = d.name) as staff_count
                FROM departments d
                LEFT JOIN users s ON d.supervisor_id = s.id
                LEFT JOIN users m ON d.manager_id = m.id
                WHERE d.id IN (
                    SELECT department_id FROM manager_departments WHERE manager_id = ?
                )
                ORDER BY d.name ASC
            ";
        } elseif ($userRole === 'HR') {
            // HR sees all departments (read-only)
            $query = "
                SELECT 
                    d.id,
                    d.name,
                    d.description,
                    d.supervisor_id,
                    s.full_name as supervisor_name,
                    d.manager_id,
                    m.full_name as manager_name,
                    d.manager_type,
                    (SELECT COUNT(*) FROM users WHERE department = d.name) as staff_count
                FROM departments d
                LEFT JOIN users s ON d.supervisor_id = s.id
                LEFT JOIN users m ON d.manager_id = m.id
                ORDER BY d.name ASC
            ";
        } else {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Insufficient permissions']);
            exit;
        }
        
        $stmt = $conn->prepare($query);
        if ($userRole === 'Manager') {
            $stmt->execute([$userId]);
        } else {
            $stmt->execute();
        }
        
        $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'departments' => $departments,
            'user_role' => $userRole
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

// POST: Assign supervisor to department
elseif ($method === 'POST') {
    try {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (empty($data['department_id']) || empty($data['supervisor_id'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Department ID and supervisor ID are required']);
            exit;
        }
        
        // Check authorization
        if ($userRole === 'Admin') {
            // Admin can assign to any department
            $canAssign = true;
        } elseif ($userRole === 'Manager') {
            // Manager can only assign within their assigned departments
            $checkQuery = "
                SELECT d.id FROM departments d
                WHERE d.id = ? AND d.id IN (
                    SELECT department_id FROM manager_departments WHERE manager_id = ?
                )
            ";
            $checkStmt = $conn->prepare($checkQuery);
            $checkStmt->execute([$data['department_id'], $userId]);
            $canAssign = $checkStmt->rowCount() > 0;
        } else {
            $canAssign = false;
        }
        
        if (!$canAssign) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'You do not have permission to assign supervisors to this department']);
            exit;
        }
        
        // Check if supervisor is already assigned to another department
        $supervisorCheckQuery = "
            SELECT d.id, d.name FROM departments d 
            WHERE d.supervisor_id = ? AND d.id != ?
        ";
        $supervisorCheckStmt = $conn->prepare($supervisorCheckQuery);
        $supervisorCheckStmt->execute([$data['supervisor_id'], $data['department_id']]);
        
        if ($supervisorCheckStmt->rowCount() > 0) {
            $existingDept = $supervisorCheckStmt->fetch(PDO::FETCH_ASSOC);
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'This supervisor is already assigned to ' . $existingDept['name']]);
            exit;
        }
        
        // Get old supervisor name for audit log
        $oldQuery = "SELECT s.full_name FROM departments d LEFT JOIN users s ON d.supervisor_id = s.id WHERE d.id = ?";
        $oldStmt = $conn->prepare($oldQuery);
        $oldStmt->execute([$data['department_id']]);
        $oldSupervisor = $oldStmt->fetch(PDO::FETCH_ASSOC)['full_name'] ?? 'None';
        
        // Get new supervisor name
        $newQuery = "SELECT full_name FROM users WHERE id = ?";
        $newStmt = $conn->prepare($newQuery);
        $newStmt->execute([$data['supervisor_id']]);
        $newSupervisor = $newStmt->fetch(PDO::FETCH_ASSOC)['full_name'] ?? 'Unknown';
        
        // Get department name
        $deptQuery = "SELECT name FROM departments WHERE id = ?";
        $deptStmt = $conn->prepare($deptQuery);
        $deptStmt->execute([$data['department_id']]);
        $deptName = $deptStmt->fetch(PDO::FETCH_ASSOC)['name'] ?? 'Unknown';
        
        // Update supervisor assignment
        $updateQuery = "UPDATE departments SET supervisor_id = ? WHERE id = ?";
        $updateStmt = $conn->prepare($updateQuery);
        $updateStmt->execute([$data['supervisor_id'], $data['department_id']]);
        
        // Log the action
        logAuditEvent(
            $conn,
            'supervisor_assigned',
            $userId,
            $userRole,
            $data['supervisor_id'],
            $newSupervisor,
            $data['department_id'],
            $deptName,
            $oldSupervisor,
            $newSupervisor,
            'Supervisor assignment by ' . $userRole
        );
        
        echo json_encode([
            'success' => true,
            'message' => 'Supervisor assigned successfully'
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

// DELETE: Remove supervisor assignment
elseif ($method === 'DELETE') {
    try {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (empty($data['department_id'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Department ID is required']);
            exit;
        }
        
        // Check authorization
        if ($userRole === 'Admin') {
            $canRemove = true;
        } elseif ($userRole === 'Manager') {
            $checkQuery = "
                SELECT d.id FROM departments d
                WHERE d.id = ? AND d.id IN (
                    SELECT department_id FROM manager_departments WHERE manager_id = ?
                )
            ";
            $checkStmt = $conn->prepare($checkQuery);
            $checkStmt->execute([$data['department_id'], $userId]);
            $canRemove = $checkStmt->rowCount() > 0;
        } else {
            $canRemove = false;
        }
        
        if (!$canRemove) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'You do not have permission to remove supervisors from this department']);
            exit;
        }
        
        // Get current supervisor for audit log
        $oldQuery = "SELECT supervisor_id, (SELECT full_name FROM users WHERE id = departments.supervisor_id) as supervisor_name, name FROM departments WHERE id = ?";
        $oldStmt = $conn->prepare($oldQuery);
        $oldStmt->execute([$data['department_id']]);
        $dept = $oldStmt->fetch(PDO::FETCH_ASSOC);
        
        // Remove supervisor
        $updateQuery = "UPDATE departments SET supervisor_id = NULL WHERE id = ?";
        $updateStmt = $conn->prepare($updateQuery);
        $updateStmt->execute([$data['department_id']]);
        
        // Log the action
        logAuditEvent(
            $conn,
            'supervisor_removed',
            $userId,
            $userRole,
            $dept['supervisor_id'],
            $dept['supervisor_name'],
            $data['department_id'],
            $dept['name'],
            $dept['supervisor_name'],
            'None',
            'Supervisor removed by ' . $userRole
        );
        
        echo json_encode([
            'success' => true,
            'message' => 'Supervisor removed successfully'
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

else {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
}
?>
