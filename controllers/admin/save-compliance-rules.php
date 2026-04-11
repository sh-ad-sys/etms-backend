<?php ob_start();

header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
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

function logAudit(PDO $db, int $actorId, string $action, string $details): void {
    $stmt = $db->prepare("
        INSERT INTO audit_logs (user_id, action, entity, entity_id, details)
        VALUES (?, ?, 'compliance_rules', NULL, ?)
    ");
    $stmt->execute([$actorId, $action, $details]);
}

try {
    $db      = (new Database())->connect();
    $actorId = (int) $_SESSION['user_id'];
    $method  = $_SERVER['REQUEST_METHOD'];

    /* ═══════════════════════════════════════════
       POST — Save compliance rules
    ═══════════════════════════════════════════ */
    if ($method === 'POST') {

        $body = json_decode(file_get_contents('php://input'), true) ?? [];

        // Validate required fields
        if (!isset($body['maxWeeklyHours']) || !isset($body['overtimeRate']) || !isset($body['graceMinutes'])) {
            ob_end_clean(); http_response_code(400);
            echo json_encode(["success" => false, "error" => "Missing required fields"]);
            exit;
        }

        // Ensure values are numeric and non-negative
        $maxWeeklyHours = max(0, intval($body['maxWeeklyHours']));
        $overtimeRate = max(0, intval($body['overtimeRate']));
        $graceMinutes = max(0, intval($body['graceMinutes']));

        $stmt = $db->prepare("
            INSERT INTO system_settings (setting_key, value, updated_by)
            VALUES (:key, :val, :uid)
            ON DUPLICATE KEY UPDATE value = :val2, updated_by = :uid2, updated_at = NOW()
        ");

        // Save max weekly hours
        $stmt->execute([
            ':key'  => 'max_weekly_hours',
            ':val'  => $maxWeeklyHours,
            ':uid'  => $actorId,
            ':val2' => $maxWeeklyHours,
            ':uid2' => $actorId,
        ]);

        // Save overtime rate
        $stmt->execute([
            ':key'  => 'overtime_rate',
            ':val'  => $overtimeRate,
            ':uid'  => $actorId,
            ':val2' => $overtimeRate,
            ':uid2' => $actorId,
        ]);

        // Save grace period
        $stmt->execute([
            ':key'  => 'late_arrival_grace_minutes',
            ':val'  => $graceMinutes,
            ':uid'  => $actorId,
            ':val2' => $graceMinutes,
            ':uid2' => $actorId,
        ]);

        logAudit($db, $actorId, 'update_compliance_rules',
            "Max Hours: $maxWeeklyHours, Overtime Rate: $overtimeRate%, Grace: $graceMinutes min");

        ob_end_clean();
        echo json_encode([
            "success" => true,
            "message" => "Compliance rules saved successfully.",
            "data" => [
                "maxWeeklyHours" => $maxWeeklyHours,
                "overtimeRate" => $overtimeRate,
                "graceMinutes" => $graceMinutes
            ]
        ]);

    } else {
        ob_end_clean(); http_response_code(405);
        echo json_encode(["success" => false, "error" => "Method not allowed"]);
    }

} catch (Exception $e) {
    ob_end_clean(); http_response_code(500);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}
?>