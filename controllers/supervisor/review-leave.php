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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_end_clean(); http_response_code(405);
    echo json_encode(["success" => false, "error" => "Method not allowed"]); exit;
}

if (!isset($_SESSION['user_id'])) {
    ob_end_clean(); http_response_code(401);
    echo json_encode(["success" => false, "error" => "Not authenticated"]); exit;
}

require_once "../../config/db.php";
use Config\Database;

try {

    $body    = json_decode(file_get_contents("php://input"), true) ?? [];
    $id      = (int)   ($body['id']      ?? 0);
    $action  = strtoupper(trim($body['action']  ?? ''));  // APPROVED | REJECTED
    $remarks = trim($body['remarks'] ?? '');

    if ($id <= 0) {
        ob_end_clean(); http_response_code(400);
        echo json_encode(["success" => false, "error" => "Invalid request ID"]); exit;
    }
    if (!in_array($action, ['APPROVED', 'REJECTED'])) {
        ob_end_clean(); http_response_code(400);
        echo json_encode(["success" => false, "error" => "Action must be APPROVED or REJECTED"]); exit;
    }

    $db = (new Database())->connect();

    /* Check request exists and is still pending */
    $check = $db->prepare("SELECT id, supervisor_approval FROM leave_requests WHERE id = ?");
    $check->execute([$id]);
    $row = $check->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        ob_end_clean(); http_response_code(404);
        echo json_encode(["success" => false, "error" => "Leave request not found"]); exit;
    }
    if ($row['supervisor_approval'] !== 'PENDING') {
        ob_end_clean(); http_response_code(409);
        echo json_encode(["success" => false, "error" => "This request has already been reviewed"]); exit;
    }

    /* Update supervisor_approval + final_status
       final_status = REJECTED immediately if supervisor rejects
       final_status = PENDING still (awaiting manager) if supervisor approves */
    $finalStatus = $action === 'REJECTED' ? 'REJECTED' : 'PENDING';

    $stmt = $db->prepare("
        UPDATE leave_requests
        SET
            supervisor_approval = ?,
            final_status        = ?
        WHERE id = ?
    ");
    $stmt->execute([$action, $finalStatus, $id]);

    ob_end_clean();
    echo json_encode([
        "success" => true,
        "message" => "Leave request " . strtolower($action) . " successfully.",
        "action"  => $action,
    ]);

} catch (Throwable $e) {
    ob_end_clean(); http_response_code(500);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}