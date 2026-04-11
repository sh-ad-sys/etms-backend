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

    if (!isset($body['workerIds']) || !is_array($body['workerIds']) || empty($body['workerIds'])) {
        ob_end_clean(); http_response_code(400);
        echo json_encode(["success" => false, "error" => "Missing worker IDs"]);
        exit;
    }

    if (!isset($body['shiftAssignments']) || !is_array($body['shiftAssignments'])) {
        ob_end_clean(); http_response_code(400);
        echo json_encode(["success" => false, "error" => "Missing shift assignments"]);
        exit;
    }

    // Verify all workers belong to this supervisor
    $placeholders = implode(',', array_fill(0, count($body['workerIds']), '?'));
    $verifyStmt = $db->prepare("
        SELECT COUNT(*) as count FROM users 
        WHERE id IN ($placeholders) 
        AND supervisor_id = ? 
        AND status = 'ACTIVE'
    ");
    $params = array_merge($body['workerIds'], [$supervisorId]);
    $verifyStmt->execute($params);
    $result = $verifyStmt->fetch(PDO::FETCH_ASSOC);

    if ((int)$result['count'] !== count($body['workerIds'])) {
        ob_end_clean(); http_response_code(403);
        echo json_encode(["success" => false, "error" => "Unauthorized"]);
        exit;
    }

    // Convert shift assignments to database format
    $shiftAssignments = $body['shiftAssignments'];
    $assignments = [
        'monday_shift' => $shiftAssignments['Monday'] ?? 'Morning Shift',
        'tuesday_shift' => $shiftAssignments['Tuesday'] ?? 'Morning Shift',
        'wednesday_shift' => $shiftAssignments['Wednesday'] ?? 'Morning Shift',
        'thursday_shift' => $shiftAssignments['Thursday'] ?? 'Morning Shift',
        'friday_shift' => $shiftAssignments['Friday'] ?? 'Morning Shift',
        'saturday_shift' => $shiftAssignments['Saturday'] ?? 'Morning Shift',
        'sunday_shift' => $shiftAssignments['Sunday'] ?? 'Morning Shift',
    ];

    // Bulk update or insert assignments
    $upsertStmt = $db->prepare("
        INSERT INTO shift_assignments (user_id, monday_shift, tuesday_shift, wednesday_shift, thursday_shift, friday_shift, saturday_shift, sunday_shift)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            monday_shift = VALUES(monday_shift),
            tuesday_shift = VALUES(tuesday_shift),
            wednesday_shift = VALUES(wednesday_shift),
            thursday_shift = VALUES(thursday_shift),
            friday_shift = VALUES(friday_shift),
            saturday_shift = VALUES(saturday_shift),
            sunday_shift = VALUES(sunday_shift)
    ");

    foreach ($body['workerIds'] as $workerId) {
        $upsertStmt->execute([
            $workerId,
            $assignments['monday_shift'],
            $assignments['tuesday_shift'],
            $assignments['wednesday_shift'],
            $assignments['thursday_shift'],
            $assignments['friday_shift'],
            $assignments['saturday_shift'],
            $assignments['sunday_shift'],
        ]);
    }

    // Log audit
    $auditStmt = $db->prepare("
        INSERT INTO audit_logs (user_id, action, entity, entity_id, details)
        VALUES (?, 'bulk_assign_shifts', 'worker_shifts', ?, ?)
    ");
    $auditStmt->execute([
        $supervisorId,
        implode(',', $body['workerIds']),
        json_encode($shiftAssignments)
    ]);

    ob_end_clean();
    echo json_encode([
        "success" => true,
        "message" => "Shifts assigned to " . count($body['workerIds']) . " workers",
        "workersCount" => count($body['workerIds'])
    ]);

} catch (Exception $e) {
    ob_end_clean(); http_response_code(500);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}
?>