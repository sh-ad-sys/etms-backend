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

    $status   = $_GET['status'] ?? 'All';
    $dateFrom = $_GET['from']   ?? date('Y-m-d', strtotime('-30 days'));
    $dateTo   = $_GET['to']     ?? date('Y-m-d');
    $search   = trim($_GET['search'] ?? '');

    $where  = ["mc.date BETWEEN :from AND :to"];
    $params = [':from' => $dateFrom, ':to' => $dateTo];

    if (in_array($status, ['Unresolved', 'Resolved'])) {
        $where[]           = 'mc.status = :status';
        $params[':status'] = $status;
    }
    if ($search) {
        $where[]           = '(u.full_name LIKE :search OR u.department LIKE :search OR d.name LIKE :search)';
        $params[':search'] = "%{$search}%";
    }

    $whereSQL = implode(' AND ', $where);

    $stmt = $db->prepare("
        SELECT
            mc.id,
            mc.date,
            mc.expected_time,
            mc.status,
            mc.note,
            mc.resolved_at,
            u.full_name,
            u.employee_code,
            u.department,
            d.name       AS department_name,
            r.full_name  AS resolved_by_name
        FROM  missing_checkins mc
        JOIN  users u  ON u.id  = mc.user_id
        LEFT  JOIN departments d ON d.id = u.department_id
        LEFT  JOIN users r ON r.id = mc.resolved_by
        WHERE {$whereSQL}
        ORDER BY
            FIELD(mc.status,'Unresolved','Resolved'),
            mc.date DESC
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $records = array_map(fn($row) => [
        'id'           => (string) $row['id'],
        'name'         => $row['full_name'],
        'employeeCode' => $row['employee_code'] ?? '',
        'department'   => $row['department_name'] ?? $row['department'] ?? 'General',
        'date'         => $row['date'],
        'expectedTime' => date('h:i A', strtotime($row['expected_time'])),
        'status'       => $row['status'],
        'note'         => $row['note'] ?? '',
        'resolvedAt'   => $row['resolved_at']
            ? date('M d, Y h:i A', strtotime($row['resolved_at']))
            : null,
        'resolvedBy'   => $row['resolved_by_name'] ?? null,
    ], $rows);

    $counts = $db->prepare("
        SELECT
            COUNT(*)                   AS total,
            SUM(status = 'Unresolved') AS unresolved,
            SUM(status = 'Resolved')   AS resolved
        FROM missing_checkins
        WHERE date BETWEEN ? AND ?
    ");
    $counts->execute([$dateFrom, $dateTo]);
    $summary = $counts->fetch(PDO::FETCH_ASSOC);

    ob_end_clean();
    echo json_encode([
        "success" => true,
        "records" => $records,
        "summary" => [
            "total"      => (int) $summary['total'],
            "unresolved" => (int) $summary['unresolved'],
            "resolved"   => (int) $summary['resolved'],
        ],
    ]);

} catch (Throwable $e) {
    ob_end_clean(); http_response_code(500);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}