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

    $db           = (new Database())->connect();
    $supervisorId = (int) $_SESSION['user_id'];

    /* Optional filters */
    $priority  = $_GET['priority']  ?? 'all';
    $completed = $_GET['completed'] ?? 'all';   // all | 0 | 1
    $search    = trim($_GET['search'] ?? '');

    $where   = ["t.assigned_by = :sup"];
    $params  = [':sup' => $supervisorId];

    if (in_array($priority, ['low','medium','high'])) {
        $where[]          = "t.priority = :priority";
        $params[':priority'] = $priority;
    }
    if ($completed === '0') { $where[] = "t.completed = 0"; }
    if ($completed === '1') { $where[] = "t.completed = 1"; }
    if ($search) {
        $where[]         = "(t.title LIKE :search OR u.full_name LIKE :search)";
        $params[':search'] = "%{$search}%";
    }

    $whereSQL = implode(' AND ', $where);

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
            u.id        AS worker_id,
            u.full_name AS worker_name,
            u.employee_code
        FROM  tasks t
        JOIN  users u ON u.id = t.assigned_to
        WHERE {$whereSQL}
        ORDER BY
            t.completed ASC,
            FIELD(t.priority,'high','medium','low'),
            t.due_date  ASC
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    /* Summary counts */
    $total     = count($rows);
    $done      = count(array_filter($rows, fn($r) => (int)$r['completed'] === 1));
    $pending   = $total - $done;
    $overdue   = count(array_filter($rows, fn($r) =>
        (int)$r['completed'] === 0 &&
        $r['due_date'] &&
        $r['due_date'] < date('Y-m-d')
    ));

    $tasks = array_map(fn($r) => [
        'id'           => (string) $r['id'],
        'title'        => $r['title'],
        'description'  => $r['description'] ?? '',
        'dueDate'      => $r['due_date'],
        'category'     => $r['category'],
        'priority'     => $r['priority'],
        'completed'    => (bool) $r['completed'],
        'completedAt'  => $r['completed_at'],
        'createdAt'    => $r['created_at'],
        'workerId'     => (string) $r['worker_id'],
        'workerName'   => $r['worker_name'],
        'employeeCode' => $r['employee_code'] ?? '',
        'isOverdue'    => (
            !(bool)$r['completed'] &&
            $r['due_date'] &&
            $r['due_date'] < date('Y-m-d')
        ),
    ], $rows);

    ob_end_clean();
    echo json_encode([
        "success" => true,
        "tasks"   => $tasks,
        "summary" => [
            "total"   => $total,
            "done"    => $done,
            "pending" => $pending,
            "overdue" => $overdue,
        ],
    ]);

} catch (Throwable $e) {
    ob_end_clean(); http_response_code(500);
    echo json_encode(["success" => false, "error" => $e->getMessage(), "file" => basename($e->getFile()), "line" => $e->getLine()]);
}