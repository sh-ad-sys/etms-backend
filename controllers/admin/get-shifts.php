<?php ob_start();

header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, OPTIONS");
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
    $stmt = $db->query("
        SELECT id, name, start_time, end_time, grace_period
        FROM shifts
        ORDER BY start_time ASC
    ");

    $shifts = array_map(static function (array $row): array {
        $startTime = (string) $row['start_time'];
        $endTime = (string) $row['end_time'];
        $grace = (int) ($row['grace_period'] ?? 0);
        $startTs = strtotime($startTime);
        $endTs = strtotime($endTime);

        return [
            "id" => (int) $row['id'],
            "name" => $row['name'],
            "start" => $startTs ? date('H:i', $startTs) : $startTime,
            "end" => $endTs ? date('H:i', $endTs) : $endTime,
            "startLabel" => $startTs ? date('h:i A', $startTs) : $startTime,
            "endLabel" => $endTs ? date('h:i A', $endTs) : $endTime,
            "break" => "00:30",
            "overtimeAfter" => $endTs ? date('H:i', $endTs) : $endTime,
            "overtimeAfterLabel" => $endTs ? date('h:i A', $endTs) : $endTime,
            "status" => "active",
            "grace" => $grace,
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));

    // Fetch compliance rules from system_settings
    $settingsStmt = $db->query("
        SELECT setting_key, value FROM system_settings
        WHERE setting_key IN ('max_weekly_hours', 'overtime_rate', 'late_arrival_grace_minutes')
    ");
    
    $complianceRules = [
        "maxWeeklyHours" => 48,
        "overtimeRate" => 150,
        "graceMinutes" => 15
    ];
    
    foreach ($settingsStmt->fetchAll(PDO::FETCH_ASSOC) as $setting) {
        $key = $setting['setting_key'];
        $val = (int) $setting['value'];
        if ($key === 'max_weekly_hours') $complianceRules['maxWeeklyHours'] = $val;
        elseif ($key === 'overtime_rate') $complianceRules['overtimeRate'] = $val;
        elseif ($key === 'late_arrival_grace_minutes') $complianceRules['graceMinutes'] = $val;
    }

    ob_end_clean();
    echo json_encode([
        "success" => true,
        "shifts" => $shifts,
        "complianceRules" => $complianceRules,
    ]);
} catch (Throwable $e) {
    ob_end_clean(); http_response_code(500);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}
