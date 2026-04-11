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
    $supervisorId = (int) $_SESSION['user_id'];

    // Get all workers under this supervisor with their shift assignments
    $stmt = $db->prepare("
        SELECT 
            u.id,
            u.full_name,
            u.employee_code,
            u.department,
            COALESCE(sa.assignments, '{}') as assignments
        FROM users u
        LEFT JOIN (
            SELECT user_id, JSON_OBJECT(
                'Monday', COALESCE(monday_shift, 'Morning Shift'),
                'Tuesday', COALESCE(tuesday_shift, 'Morning Shift'),
                'Wednesday', COALESCE(wednesday_shift, 'Morning Shift'),
                'Thursday', COALESCE(thursday_shift, 'Morning Shift'),
                'Friday', COALESCE(friday_shift, 'Morning Shift'),
                'Saturday', COALESCE(saturday_shift, 'Morning Shift'),
                'Sunday', COALESCE(sunday_shift, 'Morning Shift')
            ) as assignments
            FROM shift_assignments
        ) sa ON u.id = sa.user_id
        WHERE u.supervisor_id = ? AND u.status = 'ACTIVE'
        ORDER BY u.full_name ASC
    ");
    $stmt->execute([$supervisorId]);

    $assignments = array_map(fn($r) => [
        'id' => (int) $r['id'],
        'full_name' => $r['full_name'],
        'employee_code' => $r['employee_code'] ?? '',
        'department' => $r['department'] ?? 'General',
        'assignments' => json_decode($r['assignments'] ?? '{}', true)
    ], $stmt->fetchAll(PDO::FETCH_ASSOC));

    ob_end_clean();
    echo json_encode([
        "success" => true,
        "assignments" => $assignments
    ]);

} catch (Exception $e) {
    ob_end_clean(); http_response_code(500);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}
?>