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

    $body        = json_decode(file_get_contents("php://input"), true) ?? [];
    $supervisorId = (int) $_SESSION['user_id'];

    $title       = trim($body['title']       ?? '');
    $description = trim($body['description'] ?? '');
    $dueDate     = trim($body['due_date']     ?? '');
    $category    = trim($body['category']     ?? 'General');
    $priority    = trim($body['priority']     ?? 'medium');
    $workerIds   = $body['worker_ids']        ?? [];   // array of ints
    $shiftNote   = trim($body['shift_note']   ?? '');  // optional shift context appended to description

    /* ── Validate ── */
    if (!$title) {
        ob_end_clean(); http_response_code(400);
        echo json_encode(["success" => false, "error" => "Task title is required"]); exit;
    }
    if (empty($workerIds) || !is_array($workerIds)) {
        ob_end_clean(); http_response_code(400);
        echo json_encode(["success" => false, "error" => "Select at least one worker"]); exit;
    }

    $validPriorities = ['low', 'medium', 'high'];
    if (!in_array($priority, $validPriorities)) $priority = 'medium';

    /* Append shift note to description if provided */
    $fullDescription = $description;
    if ($shiftNote) {
        $fullDescription .= ($fullDescription ? "\n\n" : '') . 'Shift: ' . $shiftNote;
    }

    $db = (new Database())->connect();

    /* Verify all worker IDs belong to active users */
    $placeholders = implode(',', array_fill(0, count($workerIds), '?'));
    $verify = $db->prepare("
        SELECT id FROM users
        WHERE id IN ({$placeholders}) AND status = 'ACTIVE'
    ");
    $verify->execute(array_map('intval', $workerIds));
    $validIds = array_column($verify->fetchAll(PDO::FETCH_ASSOC), 'id');

    if (empty($validIds)) {
        ob_end_clean(); http_response_code(400);
        echo json_encode(["success" => false, "error" => "No valid active workers selected"]); exit;
    }

    /* Insert one task row per worker */
    $stmt = $db->prepare("
        INSERT INTO tasks
            (title, description, due_date, category, priority, assigned_to, assigned_by, completed)
        VALUES
            (:title, :description, :due_date, :category, :priority, :assigned_to, :assigned_by, 0)
    ");

    $inserted = 0;
    $db->beginTransaction();

    foreach ($validIds as $workerId) {
        $stmt->execute([
            ':title'       => $title,
            ':description' => $fullDescription ?: null,
            ':due_date'    => $dueDate         ?: null,
            ':category'    => $category,
            ':priority'    => $priority,
            ':assigned_to' => (int) $workerId,
            ':assigned_by' => $supervisorId,
        ]);
        $inserted++;
    }

    $db->commit();

    ob_end_clean();
    echo json_encode([
        "success"  => true,
        "message"  => "Task assigned to {$inserted} worker" . ($inserted !== 1 ? "s" : "") . ".",
        "inserted" => $inserted,
    ]);

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    ob_end_clean(); http_response_code(500);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}