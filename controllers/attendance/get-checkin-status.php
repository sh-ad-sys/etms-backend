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
    $db     = (new Database())->connect();
    $userId = (int) $_SESSION['user_id'];
    $today  = date('Y-m-d');

    /* Check v_attendance (covers both attendance table and qr_attendance_logs) */
    $stmt = $db->prepare("
        SELECT check_in, check_out, status
        FROM   v_attendance
        WHERE  user_id = ?
        AND    DATE(check_in) = ?
        ORDER  BY check_in DESC
        LIMIT  1
    ");
    $stmt->execute([$userId, $today]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        /* No record today — not checked in */
        ob_end_clean();
        echo json_encode([
            "success"       => true,
            "checkedIn"     => false,
            "checkedOut"    => false,
            "lastAction"    => null,
            "checkInTime"   => null,
            "checkOutTime"  => null,
        ]);
        exit;
    }

    $checkedIn  = !empty($row['check_in']);
    $checkedOut = !empty($row['check_out']);

    ob_end_clean();
    echo json_encode([
        "success"      => true,
        "checkedIn"    => $checkedIn,
        "checkedOut"   => $checkedOut,
        /* lastAction tells the frontend which button to show next */
        "lastAction"   => $checkedOut ? "check_out" : ($checkedIn ? "check_in" : null),
        "checkInTime"  => $row['check_in']  ? date('h:i A', strtotime($row['check_in']))  : null,
        "checkOutTime" => $row['check_out'] ? date('h:i A', strtotime($row['check_out'])) : null,
        "status"       => $row['status'],
    ]);

} catch (Throwable $e) {
    ob_end_clean(); http_response_code(500);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}