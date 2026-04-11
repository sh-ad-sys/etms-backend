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

$userId = (int) $_SESSION['user_id'];
session_write_close();

require_once "../config/db.php";
use Config\Database;

try {

    $db     = (new Database())->connect();

    $status  = $_GET['status'] ?? 'all';
    $allowed = ['pending', 'in_progress', 'completed', 'all'];
    if (!in_array($status, $allowed)) $status = 'all';

    $where = $status === 'all' ? '' : 'AND t.completed = ' . ($status === 'completed' ? '1' : '0');

    $stmt = $db->prepare("
        SELECT
            t.id,
            t.title,
            t.description,
            t.due_date,
            t.category,
            t.priority,
            t.completed,
            t.completed_at,
            t.created_at,
            u.full_name AS supervisor_name
        FROM  tasks t
        LEFT  JOIN users u ON u.id = t.assigned_by
        WHERE t.assigned_to = ?
        {$where}
        ORDER BY
            t.completed ASC,
            t.due_date  ASC,
            t.created_at DESC
    ");

    $stmt->execute([$userId]);

    $tasks = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {

        $taskStatus = 'pending';
        if ((int)$row['completed'] === 1) {
            $taskStatus = 'completed';
        } elseif ($row['due_date'] !== null && strtotime($row['due_date']) < time()) {
            $taskStatus = 'in_progress';
        }

        $tasks[] = [
            "id"             => (string) $row['id'],
            "title"          => $row['title'],
            "description"    => $row['description']   ?? '',
            "dueDate"        => $row['due_date'],
            "category"       => $row['category']       ?? 'General',
            "priority"       => $row['priority']       ?? 'medium',
            "completed"      => (bool) $row['completed'],
            "status"         => $taskStatus,
            "completedAt"    => $row['completed_at'],
            "assignedOn"     => date('M d, Y', strtotime($row['created_at'])),
            "supervisorName" => $row['supervisor_name'] ?? 'Supervisor',
        ];
    }

    ob_end_clean();
    echo json_encode(["success" => true, "tasks" => $tasks]);

} catch (Throwable $e) {
    ob_end_clean(); http_response_code(500);
    echo json_encode(["success" => false, "error" => $e->getMessage(), "file" => $e->getFile(), "line" => $e->getLine()]);
}
