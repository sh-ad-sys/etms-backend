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

    $stmt = $db->prepare("
        SELECT
            u.id,
            u.full_name,
            u.employee_code,
            u.department,
            d.name AS department_name
        FROM  users u
        LEFT  JOIN departments d ON d.id = u.department_id
        WHERE u.status  = 'ACTIVE'
        AND   u.role_id != (SELECT id FROM roles WHERE name = 'supervisor' LIMIT 1)
        ORDER BY u.full_name ASC
    ");
    $stmt->execute();

    $workers = array_map(fn($r) => [
        'id'           => (int)    $r['id'],
        'full_name'    => $r['full_name'],
        'employeeCode' => $r['employee_code'] ?? '',
        'department'   => $r['department_name'] ?? $r['department'] ?? 'General',
    ], $stmt->fetchAll(PDO::FETCH_ASSOC));

    ob_end_clean();
    echo json_encode(["success" => true, "workers" => $workers]);

} catch (Exception $e) {
    ob_end_clean(); http_response_code(500);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}