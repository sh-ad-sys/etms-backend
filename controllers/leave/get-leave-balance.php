<?php ob_start();

/* ================= CORS ================= */

// Get allowed origin from environment or use default
$allowedOrigin = getenv('CORS_ORIGIN') ?: 'http://localhost:3000';
header("Access-Control-Allow-Origin: " . $allowedOrigin);
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, X-Requested-With, Authorization");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { ob_end_clean(); http_response_code(200); exit(); }

ini_set('session.cookie_samesite', 'Lax');
ini_set('session.cookie_secure', 0);
session_set_cookie_params(['lifetime'=>0,'path'=>'/','domain'=>'localhost','secure'=>false,'httponly'=>true,'samesite'=>'Lax']);
session_start();

if (!isset($_SESSION['user_id'])) {
    ob_end_clean();
    http_response_code(401);
    echo json_encode(["success" => false, "error" => "Not authenticated"]);
    exit;
}

require_once "../../config/db.php";
use Config\Database;

try {

    $db     = (new Database())->connect();
    $userId = (int) $_SESSION['user_id'];

    /* Fixed balances — your table has no leave_balances table,
       so we return static defaults keyed to match your enum values */
    $balances = [
        'Annual Leave'    => 18,
        'Sick Leave'      => 10,
        'Emergency Leave' => 5,
    ];

    /* Subtract already approved days per type */
    $stmt = $db->prepare("
        SELECT leave_type, SUM(DATEDIFF(end_date, start_date) + 1) AS used
        FROM   leave_requests
        WHERE  user_id      = ?
        AND    final_status = 'APPROVED'
        GROUP  BY leave_type
    ");
    $stmt->execute([$userId]);

    $typeMap = ['ANNUAL' => 'Annual Leave', 'SICK' => 'Sick Leave', 'EMERGENCY' => 'Emergency Leave'];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $label = $typeMap[$row['leave_type']] ?? null;
        if ($label && isset($balances[$label])) {
            $balances[$label] = max(0, $balances[$label] - (int) $row['used']);
        }
    }

    ob_end_clean();
    echo json_encode(["success" => true, "balances" => $balances]);

} catch (Exception $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}