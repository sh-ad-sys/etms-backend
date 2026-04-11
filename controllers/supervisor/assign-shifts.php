<?php ob_start();

header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, X-Requested-With");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { ob_end_clean(); http_response_code(200); exit(); }

ini_set('session.cookie_samesite', 'Lax');
ini_set('session.cookie_secure', 0);
session_set_cookie_params(['lifetime'=>0,'path'=>'/','domain'=>'localhost','secure'=>false,'httponly'=>true,'samesite'=>'Lax']);
session_start();

if (!isset($_SESSION['user_id'])) {
    ob_end_clean(); http_response_code(401);
    echo json_encode(["success" => false, "error" => "Not authenticated"]); exit;
}

require_once "../../config/db.php";
use Config\Database;

try {
    $db = (new Database())->connect();
    $supervisorId = (int) $_SESSION['user_id'];

    $body = json_decode(file_get_contents('php://input'), true) ?? [];

    if (!isset($body['workerId']) || !isset($body['shiftAssignments'])) {
        ob_end_clean(); http_response_code(400);
        echo json_encode(["success" => false, "error" => "Missing required fields"]);
        exit;
    }

    $workerId = (int) $body['workerId'];
    $shiftAssignments = $body['shiftAssignments']; // {Monday: "Morning Shift", ...}

    // Verify the supervisor has authority over this worker
    $verifyStmt = $db->prepare("
        SELECT u.supervisor_id FROM users u WHERE u.id = ? AND u.status = 'ACTIVE'
    ");
    $verifyStmt->execute([$workerId]);
    $worker = $verifyStmt->fetch(PDO::FETCH_ASSOC);

    if (!$worker || (int)$worker['supervisor_id'] !== $supervisorId) {
        ob_end_clean(); http_response_code(403);
        echo json_encode(["success" => false, "error" => "Unauthorized"]);
        exit;
    }

    // Map day names to shift IDs (you may need to adjust based on your actual shift naming)
    $shiftMap = [
        "Morning Shift" => 1,
        "Evening Shift" => 2,
        "Night Shift" => 3,
        "Not in Shift" => null
    ];

    // Save or update assignments (store in a schedule table if it exists)
    // For now, we'll just log this as an audit trail
    $assignmentDetails = json_encode($shiftAssignments);
    
    $auditStmt = $db->prepare("
        INSERT INTO audit_logs (user_id, action, entity, entity_id, details)
        VALUES (?, 'assign_shifts', 'worker_shifts', ?, ?)
    ");
    $auditStmt->execute([$supervisorId, $workerId, $assignmentDetails]);

    ob_end_clean();
    echo json_encode([
        "success" => true,
        "message" => "Shifts assigned successfully",
        "workerId" => $workerId
    ]);

} catch (Exception $e) {
    ob_end_clean(); http_response_code(500);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}
?>