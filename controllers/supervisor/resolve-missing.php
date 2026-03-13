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

    $body         = json_decode(file_get_contents("php://input"), true) ?? [];
    $id           = (int) ($body['id']   ?? 0);
    $note         = trim($body['note']   ?? '');
    $supervisorId = (int) $_SESSION['user_id'];

    if ($id <= 0) {
        ob_end_clean(); http_response_code(400);
        echo json_encode(["success" => false, "error" => "Invalid record ID"]); exit;
    }

    $db = (new Database())->connect();

    $check = $db->prepare("SELECT status FROM missing_checkins WHERE id = ?");
    $check->execute([$id]);
    $row = $check->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        ob_end_clean(); http_response_code(404);
        echo json_encode(["success" => false, "error" => "Record not found"]); exit;
    }
    if ($row['status'] === 'Resolved') {
        ob_end_clean(); http_response_code(409);
        echo json_encode(["success" => false, "error" => "Already resolved"]); exit;
    }

    $stmt = $db->prepare("
        UPDATE missing_checkins
        SET
            status      = 'Resolved',
            note        = ?,
            resolved_by = ?,
            resolved_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$note ?: null, $supervisorId, $id]);

    ob_end_clean();
    echo json_encode([
        "success"    => true,
        "message"    => "Marked as resolved.",
        "resolvedAt" => date('M d, Y h:i A'),
    ]);

} catch (Throwable $e) {
    ob_end_clean(); http_response_code(500);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}